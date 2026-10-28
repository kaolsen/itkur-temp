import asyncio
import sys

from bleak import BleakClient, BleakScanner


async def main(mac):
    print(f"Scanner efter {mac} (op til 30 sek)...")
    device = await BleakScanner.find_device_by_address(mac, timeout=30)
    if device is None:
        print("Ikke fundet – er den inden for rækkevidde, og er ble-pi stoppet?")
        return

    print(f"Fundet: {device.name}  – forbinder...")
    async with BleakClient(device, timeout=20) as c:
        for s in c.services:
            print("Service", s.uuid)
            for ch in s.characteristics:
                print("   ", ch.uuid, ch.properties)

asyncio.run(main(sys.argv[1]))

