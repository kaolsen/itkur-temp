# itkur-temp – indeklima

BLE-temperaturmålere (LT_) i huset: opsamling via Pi'ens Bluetooth og to ESP32-relæer (AtomS3), lagring i MariaDB (`environment`) og visning på itkur.dk/indeklima. Fuld dokumentation i `docs/Indeklima.md`.

| Mappe | Indhold | Kører som |
|---|---|---|
| `esphome/` | Relæ-konfiguration (ESPHome) | flashes fra Pi'en |
| `ble-mqtt/` | MQTT → MariaDB for relæernes målere | `ble-mqtt.service` |
| `lt_b112/` | Pi'ens egen BLE-aflæsning | `ble-pi.service` |
| `indeklima/` | Statusside (PHP) | Apache, via symlink fra `/var/www/html/indeklima` |
| `docs/` | Dokumentation | symlink fra `/var/www/docs/Indeklima.md` |
| `systemd/` | Service-filer | kopieres til `/etc/systemd/system/` |
| `tools/` | Testværktøjer (`lt_lyt.py`, `lt_gatt.py`) | manuelt |
| `config/` | Skabeloner til ini-filerne i `/etc/itkur/` | – |

Ligger på piprod i `/var/www/itkur-temp`. Deploy: `git pull`, og genstart den berørte service.

Hemmeligheder holdes uden for git: `/etc/itkur/db.ini`, `/etc/itkur/indeklima-web.ini` og `esphome/secrets.yaml`.
