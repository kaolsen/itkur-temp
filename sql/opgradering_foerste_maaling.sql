-- Indekser til kolonnen "Første måling" på batterioversigten.
-- MIN(tidspunkt) pr. måler slås op ved hver sidevisning; uden indeks skal hele
-- tabellen læses. IF NOT EXISTS gør filen sikker at køre igen.
-- Kør én gang:  sudo mysql < /var/www/itkur-temp/sql/opgradering_foerste_maaling.sql

CREATE INDEX IF NOT EXISTS idx_sensor_tid  ON environment.measurements (sensor_id, measured_at);
-- (nexus: erstattet af idx_radio_tid fra radio_sensor_trin1.sql)
CREATE INDEX IF NOT EXISTS idx_enhed_tid   ON vejrdata.vevor (enhed_id, tidspunkt);
