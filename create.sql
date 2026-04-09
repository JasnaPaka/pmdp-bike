-- Schema pro evidenci výpůjček PMDP Bike
-- Spusť: mysql -u root -p vypujcky < create.sql
--
-- Výpůjčka = kolo zmizelo ze stanice mezi dvěma snapshoty.
-- (GBFS v3 zakazuje perzistentní vehicle_id, takže vrácení nelze spolehlivě sledovat.)

CREATE TABLE IF NOT EXISTS stations (
  station_id    VARCHAR(64)  NOT NULL,
  name          VARCHAR(255) NOT NULL DEFAULT '',
  lat           DOUBLE,
  lon           DOUBLE,
  updated_at    DATETIME     NOT NULL,
  district      VARCHAR(64)  DEFAULT NULL,  -- městský obvod, např. "Plzeň 3"
  district_part VARCHAR(128) DEFAULT NULL,  -- část obce, např. "U zimního stadionu"
  PRIMARY KEY (station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS snapshots (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  collected_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_collected_at (collected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Která kola (vehicle_id) jsou v každém snapshotu na které stanici
-- Zmizení vehicle_id ze stanice = výpůjčka
CREATE TABLE IF NOT EXISTS station_vehicles (
  snapshot_id INT UNSIGNED NOT NULL,
  station_id  VARCHAR(64)  NOT NULL,
  vehicle_id  VARCHAR(64)  NOT NULL,
  PRIMARY KEY (snapshot_id, vehicle_id),
  KEY idx_station (snapshot_id, station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrace pro existující instalace (sloupce přidány v dubnu 2026):
-- ALTER TABLE stations ADD COLUMN district      VARCHAR(64)  DEFAULT NULL;
-- ALTER TABLE stations ADD COLUMN district_part VARCHAR(128) DEFAULT NULL;

-- Detekované výpůjčky (kolo zmizelo ze stanice)
CREATE TABLE IF NOT EXISTS departures (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  station_id  VARCHAR(64)  NOT NULL,
  occurred_at DATETIME     NOT NULL,
  count       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_station  (station_id),
  KEY idx_occurred (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
