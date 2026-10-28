#!/usr/bin/env python3
# lt_gatt.py – udforsk en LT_-målers GATT-services (én kort forbindelse)
# Brug: python3 lt_gatt.py E4:83:48:2F:B1:0F
import asyncio, sys
from datetime import datetime
from bleak import BleakClient, BleakScanner

MAC = sys.argv[1].upper()
FORSØG = 3

def notify(char):
    def cb(_, data: bytearray):
        t = datetime.now().strftime("%H:%M:%S")
        print(f"  {t} NOTIFY {char.uuid[4:8]}: {data.hex(' ')}")
    return cb

async def udforsk(dev):
    async with BleakClient(dev, timeout=30) as c:
        print(f"Forbundet til {MAC}\n")
        notif = []
        for s in c.services:
            print(f"Service {s.uuid}  ({s.description})")
            for ch in s.characteristics:
                props = ",".join(ch.properties)
                linje = f"  Char {ch.uuid}  [{props}]  ({ch.description})"
                if "read" in ch.properties:
                    try:
                        v = await c.read_gatt_char(ch)
                        linje += f"\n      = {v.hex(' ')}"
                    except Exception as e:
                        linje += f"\n      læsefejl: {e}"
                print(linje)
                if "notify" in ch.properties or "indicate" in ch.properties:
                    notif.append(ch)
        print(f"\nLytter på {len(notif)} notify-characteristics i 30 s – varm måleren op …")
        for ch in notif:
            try:
                await c.start_notify(ch, notify(ch))
            except Exception as e:
                print(f"  kan ikke abonnere på {ch.uuid}: {e}")
        await asyncio.sleep(30)
    print("\nAfbrudt.")

async def main():
    for n in range(1, FORSØG + 1):
        # Find enheden først, så BlueZ har den i sin cache lige nu
        print(f"[{n}/{FORSØG}] Scanner efter {MAC} …")
        dev = await BleakScanner.find_device_by_address(MAC, timeout=20)
        if dev is None:
            print("  ikke fundet i scanning")
            continue
        print(f"  fundet ({dev.name}), forbinder …")
        try:
            await udforsk(dev)
            return
        except (TimeoutError, asyncio.TimeoutError):
            print("  timeout ved forbindelse")
        except Exception as e:
            print(f"  fejl: {type(e).__name__}: {e}")
        await asyncio.sleep(3)
    print("Gav op efter", FORSØG, "forsøg.")

asyncio.run(main())
