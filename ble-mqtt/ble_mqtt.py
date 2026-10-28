#!/usr/bin/env python3
"""Modtager LT-målinger og batteriniveau fra ESP32-relæer via MQTT og gemmer dem i MariaDB."""
import configparser
import json
import time

import paho.mqtt.client as mqtt
import pymysql

INTERVAL = 0          # sekunder mellem gemte målinger pr. måler
SENSOR_RELOAD = 300    # genindlæs sensors-tabellen hvert 5. minut


def db_config(profil="lt_b112"):
    """Læs adgangsoplysninger fra /etc/itkur/db.ini (holdes uden for git)."""
    cfg = configparser.ConfigParser()
    if not cfg.read("/etc/itkur/db.ini"):
        raise SystemExit("Kan ikke læse /etc/itkur/db.ini – findes den, og må denne bruger læse den?")
    return dict(host=cfg.get(profil, "host"), user=cfg.get(profil, "user"),
                password=cfg.get(profil, "password"), database="environment")


DB = dict(**db_config(), autocommit=True)

db = pymysql.connect(**DB)
sensors = {}
sensors_loaded = 0
last_saved = {}


def decode(raw_hex):
    """Samme decodning som process_measurement() i listen.py."""
    data = bytes.fromhex(raw_hex)
    if not (len(data) >= 13 and data[0] == 0xAA and data[1] == 0xAA
            and data[2] == 0xA2 and data[-1] == 0x55):
        return None
    temperature = ((data[5] << 8) | data[6]) / 10.0
    humidity = ((data[7] << 8) | data[8]) / 10.0
    return temperature, humidity, data.hex(" ").upper()


def load_sensors():
    global sensors, sensors_loaded
    db.ping(reconnect=True)
    with db.cursor() as c:
        c.execute("SELECT id, name, mac_address FROM sensors "
                  "WHERE active = 1 AND gateway LIKE 'ble-relae-%'")
        sensors = {mac.upper(): (sid, name) for sid, name, mac in c.fetchall()}
    sensors_loaded = time.time()
    print(f"Indlæst {len(sensors)} relæ-målere: "
          f"{', '.join(n for _, n in sensors.values())}", flush=True)


def save_battery(sid, name, level, gw):
    try:
        db.ping(reconnect=True)
        with db.cursor() as c:
            c.execute("UPDATE sensors SET battery = %s, battery_at = NOW() WHERE id = %s",
                      (level, sid))
        print(f"{gw:12} {name:<12} batteri {level:3d} %  -> gemt", flush=True)
    except Exception as e:
        print(f"{name}: databasefejl (batteri):", e, flush=True)


def save_measurement(sid, name, m):
    now = time.time()
    if now - last_saved.get(sid, 0) < INTERVAL:
        return

    result = decode(m["raw"])
    if result is None:
        return
    temperature, humidity, raw = result

    try:
        db.ping(reconnect=True)
        with db.cursor() as c:
            c.execute("INSERT INTO measurements "
                      "(sensor_id, measured_at, temperature, humidity, raw_data) "
                      "VALUES (%s, NOW(3), %s, %s, %s)",
                      (sid, temperature, humidity, raw[:100]))
        last_saved[sid] = now
        print(f"{m['gw']:12} {name:<12} {temperature:5.1f} °C  {humidity:5.1f} %  -> gemt",
              flush=True)
    except Exception as e:
        print(f"{name}: databasefejl:", e, flush=True)


def on_connect(client, userdata, flags, *args):
    client.subscribe([("ble/+/lt", 0), ("ble/+/batt", 0)])
    print("Forbundet til MQTT", flush=True)


def on_message(client, userdata, msg):
    if time.time() - sensors_loaded > SENSOR_RELOAD:
        load_sensors()

    try:
        m = json.loads(msg.payload)
    except ValueError:
        return

    sensor = sensors.get(m.get("mac", "").upper())
    if sensor is None:
        return
    sid, name = sensor

    if msg.topic.endswith("/batt"):
        save_battery(sid, name, int(m["battery"]), m["gw"])
    else:
        save_measurement(sid, name, m)


load_sensors()
try:
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
except AttributeError:
    client = mqtt.Client()
client.on_connect = on_connect
client.on_message = on_message
client.connect("localhost", 1883)
client.loop_forever()

