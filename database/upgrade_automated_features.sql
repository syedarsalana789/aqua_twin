-- Safe upgrade for an existing AquaTwin installation. Does not drop existing application tables or data.
USE twin_db;

-- Verified external FFD source ingestion. Discharge is stored in cusecs; water level remains NULL unless explicitly supplied by source.
CREATE TABLE IF NOT EXISTS external_gauge_observations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source VARCHAR(80) NOT NULL,
 river_name VARCHAR(120) NOT NULL,
 station_name VARCHAR(180) NOT NULL,
 discharge_cusecs DECIMAL(16,2) NULL,
 water_level_m DECIMAL(10,3) NULL,
 flood_status VARCHAR(80) NULL,
 source_observed_at DATETIME NULL,
 source_updated_label VARCHAR(80) NULL,
 fetched_at DATETIME NOT NULL,
 raw_text TEXT NULL,
 UNIQUE KEY uq_external_gauge (source,station_name,source_updated_label),
 KEY idx_external_latest (source, fetched_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS data_sync_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 source VARCHAR(80) NOT NULL,
 status ENUM('success','partial','failed') NOT NULL,
 records_fetched INT NOT NULL DEFAULT 0,
 message TEXT NULL,
 started_at DATETIME NOT NULL,
 finished_at DATETIME NULL,
 KEY idx_sync_source (source, started_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS flood_forecasts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 location_id BIGINT UNSIGNED NULL,
 latitude DECIMAL(10,7) NOT NULL,
 longitude DECIMAL(10,7) NOT NULL,
 forecast_date DATE NOT NULL,
 discharge_m3s DECIMAL(16,3) NULL,
 baseline_p90_m3s DECIMAL(16,3) NULL,
 risk_score DECIMAL(7,3) NULL,
 risk_band ENUM('Unavailable','Low','Moderate','High','Very High') NOT NULL,
 model_name VARCHAR(120) NOT NULL,
 method_note TEXT NULL,
 generated_at DATETIME NOT NULL,
 FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE SET NULL,
 KEY idx_forecast_location_date (location_id,forecast_date)
) ENGINE=InnoDB;
