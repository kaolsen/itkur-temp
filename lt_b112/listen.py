import asyncio
import configparser
import subprocess
import time
import traceback
from datetime import datetime

from bleak import BleakClient, BleakScanner
import pymysql


FFE8 = "0000ffe8-0000-1000-8000-00805f9b34fb"
BATTERY = "00002a19-0000-1000-8000-00805f9b34fb"

def db_config(profil="lt_b112"):
    """Læs adgangsoplysninger fra /etc/itkur/db.ini (holdes uden for git)."""
    cfg = configparser.ConfigParser()
    if not cfg.read("/etc/itkur/db.ini"):
        raise SystemExit("Kan ikke læse /etc/itkur/db.ini – findes den, og må denne bruger læse den?")
    return dict(host=cfg.get(profil, "host"), user=cfg.get(profil, "user"),
                password=cfg.get(profil, "password"), database="environment")


DB_CONFIG = db_config()

# Kort forbindelse: hver måler forbindes én gang pr. runde, batteriet læses,
# vi venter på én FFE8-frame og afbryder. Permanent forbindelse holdt målerens
# radio vågen døgnet rundt og drænede batteriet.
RUNDE_INTERVAL = 600      # sekunder mellem runder (10 min, som relæerne)
SCAN_TIME = 15            # hvor længe vi leder efter én måler
CONNECT_TIMEOUT = 40.0    # forbindelse inkl. GATT-gennemgang
FRAME_TIMEOUT = 45.0      # hvor længe vi venter på en FFE8-frame
FORSOEG = 3               # forbindelsesforsøg pr. måler pr. runde
SCAN_SETTLE = 2           # pause mellem scanning og forbindelse (BlueZ/CYW43455)


def fejltekst(e):
    """Fejltype + tekst. TimeoutError o.l. har tom str(e)."""
    tekst = str(e).strip()
    return f"{type(e).__name__}: {tekst}" if tekst else type(e).__name__


# ---------------------------------------------------------
# Database
# ---------------------------------------------------------

def get_sensors():

    conn = pymysql.connect(**DB_CONFIG)

    try:
        with conn.cursor(pymysql.cursors.DictCursor) as cursor:

            cursor.execute(
                """
                SELECT
                    id,
                    name,
                    bluetooth_name,
                    mac_address
                FROM sensors
                WHERE active = 1
                  AND gateway = 'raspberrypi'
                ORDER BY id
                """
            )

            return cursor.fetchall()

    finally:
        conn.close()


def save_measurement(sensor_id, temperature, humidity, raw_data):

    conn = pymysql.connect(**DB_CONFIG)

    try:
        with conn.cursor() as cursor:

            cursor.execute(
                """
                INSERT INTO measurements
                    (
                        sensor_id,
                        measured_at,
                        temperature,
                        humidity,
                        raw_data
                    )
                VALUES
                    (%s, NOW(3), %s, %s, %s)
                """,
                (
                    sensor_id,
                    temperature,
                    humidity,
                    raw_data
                )
            )

        conn.commit()

    finally:
        conn.close()


def save_battery(sensor_id, level):

    conn = pymysql.connect(**DB_CONFIG)

    try:
        with conn.cursor() as cursor:

            cursor.execute(
                """
                UPDATE sensors
                SET battery = %s,
                    battery_at = NOW()
                WHERE id = %s
                """,
                (
                    level,
                    sensor_id
                )
            )

        conn.commit()

    finally:
        conn.close()


# ---------------------------------------------------------
# Måling
# ---------------------------------------------------------

async def process_measurement(sensor, data):

    if not (
        len(data) >= 13
        and data[0] == 0xAA
        and data[1] == 0xAA
        and data[2] == 0xA2
        and data[-1] == 0x55
    ):
        return

    temp_raw = (data[5] << 8) | data[6]
    humidity_raw = (data[7] << 8) | data[8]

    temperature = temp_raw / 10.0
    humidity = humidity_raw / 10.0

    raw_data = data.hex(" ").upper()

    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    print(
        f"{timestamp}  "
        f"{sensor['name']:<12} "
        f"{temperature:5.1f} °C   "
        f"{humidity:5.1f} %"
    )

    try:

        await asyncio.to_thread(
            save_measurement,
            sensor["id"],
            temperature,
            humidity,
            raw_data
        )

        print(
            f"                     "
            f"{sensor['name']} -> gemt i MariaDB"
        )

    except Exception as e:

        print(
            f"{sensor['name']}: databasefejl:",
            fejltekst(e)
        )


async def read_battery(sensor, client):

    try:

        level = (await client.read_gatt_char(BATTERY))[0]

        print(
            f"{sensor['name']}: batteri {level} %"
        )

        await asyncio.to_thread(
            save_battery,
            sensor["id"],
            level
        )

    except Exception as e:

        print(
            f"{sensor['name']}: batterifejl:",
            fejltekst(e)
        )


# ---------------------------------------------------------
# Én kort forbindelse
# ---------------------------------------------------------

def rens_bluez(address):
    """Fjern måleren helt fra BlueZ før hver forbindelse.

    - Er den 'Trusted', auto-forbinder bluetoothd og holder forbindelsen.
    - Har BlueZ den i cachen fra en tidligere forbindelse (GATT-cache), giver
      næste forbindelse timeout. Kun første forbindelse efter 'remove' virker.
    'remove' rydder begge dele; scanningen bagefter finder den igen.
    """
    try:
        r = subprocess.run(["bluetoothctl", "remove", address],
                           capture_output=True, text=True, timeout=15)
        if "removed" in r.stdout:
            print(f"{address}: fjernet fra BlueZ-cache")
    except Exception as e:
        print(f"{address}: kunne ikke rydde BlueZ-cache: {fejltekst(e)}")


async def aflaes_en(sensor):
    """Forbind, læs batteri, vent på én frame, afbryd. Returnerer True ved kontakt."""

    address = sensor["mac_address"].upper()

    for forsoeg in range(1, FORSOEG + 1):

        start = time.monotonic()
        await asyncio.to_thread(rens_bluez, address)

        try:
            device = await BleakScanner.find_device_by_address(
                address, timeout=SCAN_TIME
            )
            if device is None:
                print(f"{sensor['name']}: ikke fundet (forsøg {forsoeg}/{FORSOEG})")
                continue

            await asyncio.sleep(SCAN_SETTLE)

            async with BleakClient(device, timeout=CONNECT_TIMEOUT) as client:

                print(f"{sensor['name']}: forbundet efter {time.monotonic() - start:.1f} s")

                frame = asyncio.get_running_loop().create_future()

                def received(sender, data):
                    if not frame.done():
                        frame.set_result(bytes(data))

                await client.start_notify(FFE8, received)
                await read_battery(sensor, client)

                try:
                    data = await asyncio.wait_for(frame, FRAME_TIMEOUT)
                    await process_measurement(sensor, data)
                except asyncio.TimeoutError:
                    print(f"{sensor['name']}: ingen ændring inden for {FRAME_TIMEOUT:.0f} s")

            print(f"{sensor['name']}: afbrudt efter {time.monotonic() - start:.1f} s")
            return True

        except asyncio.CancelledError:
            raise

        except Exception as e:
            print(
                f"{sensor['name']}: Bluetooth-fejl (forsøg {forsoeg}/{FORSOEG}) efter "
                f"{time.monotonic() - start:.1f} s: {fejltekst(e)}"
            )
            if not isinstance(e, (TimeoutError, asyncio.TimeoutError)):
                traceback.print_exc()
            await asyncio.sleep(3)

    return False


# ---------------------------------------------------------
# Main: én runde hvert RUNDE_INTERVAL
# ---------------------------------------------------------

async def main():

    while True:

        runde_start = time.monotonic()

        try:
            sensors = await asyncio.to_thread(get_sensors)   # genindlæs hver runde
        except Exception as e:
            print("Kan ikke hente sensorer:", fejltekst(e))
            sensors = []

        if not sensors:
            print("Ingen aktive Raspberry Pi-sensorer i databasen.")

        print()
        print(datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
              f"runde: {', '.join(s['name'] for s in sensors)}")

        for sensor in sensors:          # én ad gangen – Pi'ens Bluetooth kan ikke mere
            await aflaes_en(sensor)

        pause = RUNDE_INTERVAL - (time.monotonic() - runde_start)
        await asyncio.sleep(max(pause, 30))


# ---------------------------------------------------------
# Start
# ---------------------------------------------------------

try:

    asyncio.run(main())

except KeyboardInterrupt:

    print()
    print("Program stoppet.")
