-- Batteriskift: én række pr. skift, så siden kan vise seneste skift og hvor
-- længe det forrige batteri holdt. Dækker alle målere:
--   lt:<sensors.id>   LT_-målerne (BLE)
--   nexus:<kanal>     Nexus-termometrene på vejrstationen
--   vevor             Vevor-udedelen
-- Kør én gang:  sudo mysql environment < /var/www/itkur-temp/sql/opgradering_batteriskift.sql

CREATE TABLE IF NOT EXISTS batteriskift (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    maaler    VARCHAR(40)  NOT NULL,
    skiftet   DATE         NOT NULL,
    oprettet  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_maaler_skiftet (maaler, skiftet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kendt skift: Kontor (LT_B20E) fik nye batterier 28. okt 2026
INSERT INTO batteriskift (maaler, skiftet)
SELECT CONCAT('lt:', id), '2026-10-28' FROM sensors
WHERE mac_address = 'F0:B9:6A:D2:B2:0E'
  AND NOT EXISTS (SELECT 1 FROM batteriskift WHERE maaler = CONCAT('lt:', sensors.id));

-- Webbrugeren skal kunne læse, registrere og fortryde skift
GRANT SELECT, INSERT, DELETE ON environment.batteriskift TO 'lt_b112'@'localhost';
