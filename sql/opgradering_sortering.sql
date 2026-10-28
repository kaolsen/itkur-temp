-- Sorteringskolonne på sensors: bestemmer rækkefølgen af kortene på itkur.dk/indeklima
-- (og i graf-vælgeren). Lavt tal først; samme tal sorteres på navn.
-- Kør én gang:  sudo mysql environment < /var/www/itkur-temp/sql/opgradering_sortering.sql

ALTER TABLE sensors
  ADD COLUMN sortering SMALLINT NOT NULL DEFAULT 100 AFTER location;

-- Startværdier: nuværende rækkefølge (alfabetisk) i spring af 10,
-- så der er plads til at indsætte målere imellem senere.
SET @n := 0;
UPDATE sensors SET sortering = (@n := @n + 10) ORDER BY name;

SELECT sortering, name, location FROM sensors ORDER BY sortering, name;
