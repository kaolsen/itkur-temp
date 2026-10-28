import asyncio
from datetime import datetime
from bleak import BleakClient, BleakScanner

ADDRESS = "F0:8F:09:55:B1:12"
FFE8 = "0000ffe8-0000-1000-8000-00805f9b34fb"

LOGFILE = "lt_b112.log"


def received(sender, data):
    now = datetime.now()
    timestamp = now.strftime("%Y-%m-%d %H:%M:%S")
    hexdata = data.hex(" ").upper()

    if len(data) >= 13 and data[0] == 0xAA and data[1] == 0xAA:

        # Temperatur er et 16-bit tal i byte 6 og 7
        temp_raw = (data[5] << 8) | data[6]

        # Fugtighed er et 16-bit tal i byte 8 og 9
        humidity_raw = (data[7] << 8) | data[8]

        temperature = temp_raw / 10.0
        humidity = humidity_raw / 10.0

        print(
            f"{timestamp}  "
            f"Temperatur: {temperature:.1f} °C  "
            f"Fugtighed: {humidity:.1f} %"
        )

    # Gem stadig den originale Bluetooth-pakke
    with open(LOGFILE, "a") as f:
        f.write(f"{timestamp};{hexdata}\n")


async def main():

    print("Søger efter LT_B112...")

    device = await BleakScanner.find_device_by_address(
        ADDRESS,
        timeout=30.0
    )

    if device is None:
        print("LT_B112 blev ikke fundet.")
        return

    print("Fundet:", device.name, device.address)
    print("Forbinder...")

    async with BleakClient(device) as client:

        print("Forbundet:", client.is_connected)

        print("Starter FFE8 notifications...")
        await client.start_notify(FFE8, received)

        print("Lytter på LT_B112...")
        print("Tryk Ctrl+C for at stoppe.")
        print()

        while True:
            await asyncio.sleep(1)


try:
    asyncio.run(main())

except KeyboardInterrupt:
    print("\nProgram stoppet.")

