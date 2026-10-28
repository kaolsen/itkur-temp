import asyncio
from bleak import BleakScanner


def callback(device, advertisement_data):

    name = device.name or advertisement_data.local_name or ""

    if name.startswith("LT_"):

        print()
        print("----------------------------------------")
        print("Navn:         ", name)
        print("Adresse:      ", device.address)
        print("RSSI:         ", advertisement_data.rssi)
        print("Service UUIDs:", advertisement_data.service_uuids)
        print("Manufacturer: ", advertisement_data.manufacturer_data)
        print("Service data: ", advertisement_data.service_data)


async def main():

    scanner = BleakScanner(
        detection_callback=callback
    )

    print("Scanner efter LT-enheder...")
    print("Ctrl+C stopper")
    print()

    await scanner.start()

    while True:
        await asyncio.sleep(1)


try:
    asyncio.run(main())

except KeyboardInterrupt:
    print("\nStoppet")

