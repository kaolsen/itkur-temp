-- Indekser til kolonnen "Første måling" på batterioversigten.
-- MIN(tidspunkt) pr. måler slås op ved hver sidevisning; uden indeks skal hele
-- tabellen læses. IF NOT EXISTS gør filen sikker at køre igen.
-- Kør én gang:  sudo mysql < /var/www/itkur-temp/sql/opgradering_foerste_maaling.sql

CREATE INDEX IF NOT EXISTS idx_sensor_tid  ON environment.measurements (sensor_id, measured_at);
CREATE INDEX IF NOT EXISTS idx_kanal_enhed ON vejrdata.nexus (kanal, enhed_id, tidspunkt);
CREATE INDEX IF NOT EXISTS idx_enhed_tid   ON vejrdata.vevor (enhed_id, tidspunkt);
