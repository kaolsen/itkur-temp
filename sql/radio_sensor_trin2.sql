-- ============================================================================
--  radio_sensor_trin2.sql – fjern den gamle model, når trin 1 har kørt et stykke tid
--
--  Forudsætning: opsamleren og siderne bruger radio_id (deployet sammen med trin 1),
--  og kontrollen nedenfor giver 0. nexus_placering omdøbes (ikke slettes), så den
--  kan findes igen, hvis noget mangler.
--
--  Kør:  sudo mysql vejrdata < radio_sensor_trin2.sql
-- ============================================================================

-- Stop, hvis der findes målinger uden radio_id
SELECT COUNT(*) AS 'målinger uden radio_id (skal være 0)' FROM nexus WHERE radio_id IS NULL;

ALTER TABLE nexus
    MODIFY radio_id INT UNSIGNED NOT NULL,
    DROP INDEX idx_kanal,
    DROP INDEX idx_kanal_tid,
    DROP INDEX idx_kanal_enhed,
    DROP COLUMN enhed_id,
    DROP COLUMN kanal;

RENAME TABLE nexus_placering TO nexus_placering_gammel;

-- View'et genskabes, så det ikke peger på de fjernede kolonner
CREATE OR REPLACE VIEW nexus_maaling AS
SELECT n.id, n.tidspunkt, n.radio_id, r.enhed_id, r.kanal,
       r.sensor_id, s.navn, n.temperatur, n.luftfugtighed, n.batteri_ok
FROM nexus n
JOIN radio_id r          ON r.radio_id = n.radio_id
LEFT JOIN radio_sensor s ON s.sensor_id = r.sensor_id;
