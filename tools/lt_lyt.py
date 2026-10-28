#!/usr/bin/env python3
# lt_lyt.py – lyt på LT_-måleres advertisements uden at forbinde
# Brug: python3 lt_lyt.py            (vis alle LT_-enheder)
#       python3 lt_lyt.py AA:BB:...  (kun én måler)
import asyncio, sys
from datetime import datetime
from bleak import BleakScanner

FILTER_MAC = sys.argv[1].upper() if len(sys.argv) > 1 else None
sidst = {}

def cb(dev, adv):
    navn = adv.local_name or dev.name or ""
    if FILTER_MAC:
        if dev.address.upper() != FILTER_MAC:
            return
    elif not navn.startswith("LT_"):
        return

    mfr = {f"0x{k:04X}": v.hex(" ") for k, v in adv.manufacturer_data.items()}
    svc = {k[4:8]: v.hex(" ") for k, v in adv.service_data.items()}  # kort UUID
    nøgle = (str(mfr), str(svc))
    if sidst.get(dev.address) == nøgle:
        return
    sidst[dev.address] = nøgle

    t = datetime.now().strftime("%H:%M:%S")
    print(f"{t} {dev.address} {navn:12} rssi={adv.rssi:4}")
    for k, v in mfr.items():
        print(f"      mfr {k}: {v}")
    for k, v in svc.items():
        print(f"      svc {k}:   {v}")
    if not mfr and not svc:
        print("      (ingen data i advertisement)")

async def main():
    async with BleakScanner(detection_callback=cb):
        print("Lytter … Ctrl-C for at stoppe")
        await asyncio.Event().wait()

asyncio.run(main())
