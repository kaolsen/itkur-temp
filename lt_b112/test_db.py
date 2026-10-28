import configparser
import pymysql


def db_config(profil="lt_b112"):
    """Læs adgangsoplysninger fra /etc/itkur/db.ini (holdes uden for git)."""
    cfg = configparser.ConfigParser()
    if not cfg.read("/etc/itkur/db.ini"):
        raise SystemExit("Kan ikke læse /etc/itkur/db.ini – findes den, og må denne bruger læse den?")
    return dict(host=cfg.get(profil, "host"), user=cfg.get(profil, "user"),
                password=cfg.get(profil, "password"), database="environment")


conn = pymysql.connect(**db_config())

print("Forbindelse til MariaDB OK")

with conn.cursor() as cursor:
    cursor.execute("SELECT id, name, mac_address FROM sensors")
    for row in cursor.fetchall():
        print(row)

conn.close()

