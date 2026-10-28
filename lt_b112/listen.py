import asyncio
import configparser
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

# Hvor ofte vi scanner efter sensorer, som ikke er forbundet
SCAN_INTERVAL = 15

# Hvor længe hver scanning varer
SCAN_TIME = 10

# Timeout for selve forbindelsen inkl. GATT-gennemgang (sekunder).
# Var 20 s; første forbindelse på en ny installation kan tage længere.
CONNECT_TIMEOUT = 40.0

# Pause mellem scanning og forbindelse, så BlueZ har stoppet discovery
SCAN_SETTLE = 2

# Hvor ofte batteriniveauet læses (sekunder)
BATTERY_INTERVAL = 900


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
# Én Bluetooth-forbindelse
# ---------------------------------------------------------

async def sensor_connection(sensor, device, states):

    address = sensor["mac_address"].upper()
    start = time.monotonic()

    try:

        # Lad BlueZ afslutte discovery helt, før vi forbinder.
        # Pi'ens CYW43455 er upålidelig, hvis de to overlapper.
        await asyncio.sleep(SCAN_SETTLE)

        print(
            f"{sensor['name']}: "
            f"forbinder til {device.address}..."
        )

        async with BleakClient(
            device,
            timeout=CONNECT_TIMEOUT
        ) as client:

            states[address]["connected"] = True

            print(
                f"{sensor['name']}: FORBUNDET "
                f"efter {time.monotonic() - start:.1f} s"
            )

            def received(sender, data, sensor=sensor):

                asyncio.create_task(
                    process_measurement(
                        sensor,
                        bytes(data)
                    )
                )

            await client.start_notify(
                FFE8,
                received
            )

            print(
                f"{sensor['name']}: lytter..."
            )

            # Batteri læses straks efter forbindelse og derefter hvert BATTERY_INTERVAL
            next_battery = 0

            while client.is_connected:

                if time.monotonic() >= next_battery:

                    await read_battery(sensor, client)

                    next_battery = (
                        time.monotonic() + BATTERY_INTERVAL
                    )

                await asyncio.sleep(2)

            print(
                f"{sensor['name']}: "
                "forbindelsen blev afbrudt"
            )

    except asyncio.CancelledError:
        raise

    except Exception as e:

        print(
            f"{sensor['name']}: Bluetooth-fejl efter "
            f"{time.monotonic() - start:.1f} s:",
            fejltekst(e)
        )

        # Fuld traceback kun ved uventede fejl, ikke ved timeout
        if not isinstance(e, (TimeoutError, asyncio.TimeoutError)):
            traceback.print_exc()

    finally:

        states[address]["connected"] = False
        states[address]["task"] = None

        print(
            f"{sensor['name']}: "
            "markeret som ikke forbundet"
        )


# ---------------------------------------------------------
# Fælles scanner/reconnect-manager
# ---------------------------------------------------------

async def connection_manager(sensors):

    sensor_by_address = {
        sensor["mac_address"].upper(): sensor
        for sensor in sensors
    }

    states = {}

    for address in sensor_by_address:

        states[address] = {
            "connected": False,
            "task": None
        }

    while True:

        #
        # Find sensorer, som mangler forbindelse
        #

        missing = [
            address
            for address in sensor_by_address
            if not states[address]["connected"]
            and states[address]["task"] is None
        ]

        if not missing:

            # Alle er forbundet eller ved at forbinde
            await asyncio.sleep(5)
            continue

        #
        # Scan ikke, mens en anden sensor er midt i at forbinde.
        # Discovery og connect samtidig får forbindelsen til at hænge.
        #

        connecting = [
            address
            for address in sensor_by_address
            if states[address]["task"] is not None
            and not states[address]["connected"]
        ]

        if connecting:
            await asyncio.sleep(2)
            continue

        print()
        print(
            "Scanner efter ikke-forbundne sensorer:"
        )

        for address in missing:

            sensor = sensor_by_address[address]

            print(
                f"  {sensor['name']:<12} "
                f"{sensor['bluetooth_name']:<10} "
                f"{address}"
            )

        try:

            devices = await BleakScanner.discover(
                timeout=SCAN_TIME,
                return_adv=True
            )

        except Exception as e:

            print(
                "Fejl under BLE-scanning:",
                fejltekst(e)
            )

            await asyncio.sleep(
                SCAN_INTERVAL
            )

            continue

        #
        # Se om de manglende sensorer blev fundet
        #

        found_addresses = set()

        for address, result in devices.items():

            device, advertisement = result

            device_address = (
                device.address.upper()
            )

            if device_address not in missing:
                continue

            sensor = sensor_by_address[
                device_address
            ]

            found_addresses.add(
                device_address
            )

            print(
                f"FUNDET: "
                f"{sensor['name']:<12} "
                f"{device.name or '?':<10} "
                f"{device.address} "
                f"RSSI {advertisement.rssi}"
            )

            #
            # Start separat forbindelse.
            # Scanningen er afsluttet her, og der startes
            # ingen ny, før forbindelsen er etableret eller fejlet.
            #

            task = asyncio.create_task(
                sensor_connection(
                    sensor,
                    device,
                    states
                )
            )

            states[device_address]["task"] = task

        #
        # Vis hvilke vi ikke fandt
        #

        for address in missing:

            if address not in found_addresses:

                sensor = sensor_by_address[
                    address
                ]

                print(
                    f"{sensor['name']}: "
                    "ikke fundet"
                )

        await asyncio.sleep(
            SCAN_INTERVAL
        )


# ---------------------------------------------------------
# Main
# ---------------------------------------------------------

async def main():

    sensors = await asyncio.to_thread(
        get_sensors
    )

    if not sensors:

        print(
            "Ingen aktive Raspberry Pi-sensorer "
            "fundet i databasen."
        )

        return

    print()
    print(
        f"Starter LT-overvågning af "
        f"{len(sensors)} sensor(er)"
    )

    for sensor in sensors:

        print(
            f"  {sensor['name']:<12} "
            f"{sensor['bluetooth_name']:<10} "
            f"{sensor['mac_address']}"
        )

    print()

    await connection_manager(
        sensors
    )


# ---------------------------------------------------------
# Start
# ---------------------------------------------------------

try:

    asyncio.run(main())

except KeyboardInterrupt:

    print()
    print("Program stoppet.")
