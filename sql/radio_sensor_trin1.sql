-- ============================================================================
--  radio_sensor_trin1.sql – normaliseret sensormodel for radiomålerne (Nexus)
--
--  radio_sensor  stamtabel: én række pr. fysisk måler ("super-ID"), ændrer sig aldrig
--  radio_id      sub-ID'er: hvilket radio-ID måleren sendte med fra hvornår.
--                Nyt radio-ID = nyt batteri (eller en reset), så gyldig_fra er
--                batteriskiftet. sensor_id NULL = ukendt ID (støj, nabo, nyt batteri
--                der endnu ikke er tilknyttet).
--  nexus         målingerne får radio_id (FK) – sensoren findes via radio_id.
--  nexus_maaling view med målinger + sensor, som siderne læser fra.
--
--  Et radio-ID kan genbruges senere (fx 97 på kanal 2 både i juni og oktober),
--  derfor er (kanal, enhed_id) IKKE unik over tid. Målinger af samme ID med
--  mere end 48 timers mellemrum regnes som to forskellige perioder (to rækker).
--
--  Trin 1 lader de gamle kolonner nexus.enhed_id/kanal og nexus_placering stå
--  (gøres NULL-bare, så den nye opsamler kan undlade dem). Trin 2
--  (radio_sensor_trin2.sql) fjerner dem, når alt har kørt på den nye model.
--
--  Kør:  sudo mysql vejrdata < radio_sensor_trin1.sql
--  Stop opsamleren imens:  sudo systemctl stop vevor  (start igen efter deploy)
-- ============================================================================

-- ── Stamtabel ───────────────────────────────────────────────────────────────
CREATE TABLE radio_sensor (
    sensor_id  SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type       ENUM('nexus','vevor') NOT NULL,
    navn       VARCHAR(50)  NOT NULL,
    kanal      TINYINT UNSIGNED NULL,           -- Nexus: kanalvælgeren (1-3); Vevor: NULL
    aktiv      TINYINT(1)   NOT NULL DEFAULT 1,
    sortering  SMALLINT     NOT NULL DEFAULT 100,
    oprettet   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_type_kanal (type, kanal, aktiv)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Sub-ID'er ───────────────────────────────────────────────────────────────
CREATE TABLE radio_id (
    radio_id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('nexus','vevor') NOT NULL,
    kanal       TINYINT UNSIGNED NULL,
    enhed_id    SMALLINT UNSIGNED NOT NULL,     -- ID'et som måleren sender
    sensor_id   SMALLINT UNSIGNED NULL,         -- NULL = ukendt
    gyldig_fra  DATETIME NOT NULL,              -- første måling = batteriskift
    tilknyttet  DATETIME NULL,                  -- hvornår sensor_id blev sat
    note        VARCHAR(100) NULL,
    KEY idx_opslag (type, kanal, enhed_id, gyldig_fra),
    KEY idx_sensor (sensor_id, gyldig_fra),
    CONSTRAINT fk_radio_sensor FOREIGN KEY (sensor_id) REFERENCES radio_sensor (sensor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 1. Sensorer: én pr. kanal i nexus_placering ────────────────────────────
INSERT INTO radio_sensor (type, navn, kanal, sortering)
SELECT 'nexus',
       (SELECT p2.placering FROM nexus_placering p2
        WHERE p2.kanal = p.kanal ORDER BY p2.gyldig_fra DESC LIMIT 1),
       p.kanal, p.kanal * 10
FROM nexus_placering p
GROUP BY p.kanal
ORDER BY p.kanal;

-- ── 2. Del målingerne op i perioder pr. (kanal, enhed_id) ───────────────────
--  ny periode, når der er gået mere end 48 timer siden forrige måling af samme ID
CREATE TEMPORARY TABLE tmp_periode AS
SELECT id, kanal, enhed_id, tidspunkt,
       SUM(ny) OVER (PARTITION BY kanal, enhed_id ORDER BY tidspunkt, id) AS periode
FROM (
    SELECT id, kanal, enhed_id, tidspunkt,
           CASE WHEN TIMESTAMPDIFF(HOUR,
                       LAG(tidspunkt) OVER (PARTITION BY kanal, enhed_id ORDER BY tidspunkt, id),
                       tidspunkt) <= 48
                THEN 0 ELSE 1 END AS ny
    FROM nexus
) x;
ALTER TABLE tmp_periode ADD PRIMARY KEY (id), ADD KEY (kanal, enhed_id, periode);

-- ── 3. Én radio_id-række pr. periode ────────────────────────────────────────
--  Kendte ID'er (i nexus_placering) knyttes til kanalens sensor.
INSERT INTO radio_id (type, kanal, enhed_id, sensor_id, gyldig_fra, tilknyttet, note)
SELECT 'nexus', t.kanal, t.enhed_id,
       CASE WHEN p.enhed_id IS NOT NULL THEN s.sensor_id END,
       MIN(t.tidspunkt),
       CASE WHEN p.enhed_id IS NOT NULL THEN NOW() END,
       CASE WHEN p.enhed_id IS NOT NULL THEN 'migreret fra nexus_placering' END
FROM tmp_periode t
LEFT JOIN nexus_placering p ON p.kanal = t.kanal AND p.enhed_id = t.enhed_id
LEFT JOIN radio_sensor s    ON s.type = 'nexus' AND s.kanal = t.kanal
GROUP BY t.kanal, t.enhed_id, t.periode, p.enhed_id, s.sensor_id;

-- ── 4. Målingerne får radio_id ──────────────────────────────────────────────
ALTER TABLE nexus
    ADD COLUMN radio_id INT UNSIGNED NULL AFTER tidspunkt,
    ADD KEY idx_radio_tid (radio_id, tidspunkt);

CREATE TEMPORARY TABLE tmp_kobling AS
SELECT t.id, r.radio_id
FROM tmp_periode t
JOIN (SELECT kanal, enhed_id, periode, MIN(tidspunkt) AS fra
      FROM tmp_periode GROUP BY kanal, enhed_id, periode) pf
  ON pf.kanal = t.kanal AND pf.enhed_id = t.enhed_id AND pf.periode = t.periode
JOIN radio_id r
  ON r.type = 'nexus' AND r.kanal = pf.kanal AND r.enhed_id = pf.enhed_id AND r.gyldig_fra = pf.fra;
ALTER TABLE tmp_kobling ADD PRIMARY KEY (id);

UPDATE nexus n JOIN tmp_kobling k ON k.id = n.id SET n.radio_id = k.radio_id;

ALTER TABLE nexus
    ADD CONSTRAINT fk_nexus_radio FOREIGN KEY (radio_id) REFERENCES radio_id (radio_id);

-- Opsamleren skriver fremover kun radio_id; kanal og enhed_id ligger i radio_id.
-- De gamle kolonner må derfor være tomme for nye rækker (fjernes i trin 2).
ALTER TABLE nexus
    MODIFY enhed_id TINYINT UNSIGNED NULL,
    MODIFY kanal    TINYINT UNSIGNED NULL;

-- ── 5. View til siderne ─────────────────────────────────────────────────────
CREATE OR REPLACE VIEW nexus_maaling AS
SELECT n.id, n.tidspunkt, n.radio_id, r.enhed_id, r.kanal,
       r.sensor_id, s.navn, n.temperatur, n.luftfugtighed, n.batteri_ok
FROM nexus n
JOIN radio_id r          ON r.radio_id = n.radio_id
LEFT JOIN radio_sensor s ON s.sensor_id = r.sensor_id;

-- ── Kontrol ─────────────────────────────────────────────────────────────────
SELECT 'målinger uden radio_id (skal være 0)' AS kontrol, COUNT(*) AS antal FROM nexus WHERE radio_id IS NULL
UNION ALL
SELECT 'radio-ID-perioder i alt',               COUNT(*) FROM radio_id
UNION ALL
SELECT 'heraf knyttet til en sensor',           COUNT(*) FROM radio_id WHERE sensor_id IS NOT NULL;

SELECT s.sensor_id, s.navn, s.kanal,
       r.enhed_id AS nuvaerende_id, r.gyldig_fra AS batteri_fra
FROM radio_sensor s
LEFT JOIN radio_id r ON r.radio_id = (
    SELECT r2.radio_id FROM radio_id r2 WHERE r2.sensor_id = s.sensor_id
    ORDER BY r2.gyldig_fra DESC LIMIT 1)
ORDER BY s.sortering;
