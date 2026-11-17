CREATE DATABASE IF NOT EXISTS twin_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE twin_db;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS flood_map_layers, alert_acknowledgements, audit_logs, incident_reports, weather_observations, operator_assignments, alerts, locations, users;
SET FOREIGN_KEY_CHECKS=1;
CREATE TABLE users(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,role ENUM('Admin','Area Operator') NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE locations(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,area_type ENUM('River','Canal','Catchment','Urban area') NOT NULL,
 latitude DECIMAL(10,7) NULL,longitude DECIMAL(10,7) NULL,description TEXT NULL,
 warning_level_m DECIMAL(8,3) NULL,critical_level_m DECIMAL(8,3) NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE operator_assignments(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,location_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_assignment(user_id,location_id),
 FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(location_id) REFERENCES locations(id)
) ENGINE=InnoDB;
CREATE TABLE weather_observations(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,location_id BIGINT UNSIGNED NOT NULL,observed_at DATETIME NOT NULL,
 rainfall_mm DECIMAL(8,2) NULL,water_level_m DECIMAL(8,3) NULL,source VARCHAR(120) NOT NULL DEFAULT 'Operator entry',
 notes TEXT NULL,entered_by BIGINT UNSIGNED NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(location_id) REFERENCES locations(id),FOREIGN KEY(entered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE alerts(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL,severity ENUM('Information','Watch','Warning','Critical') NOT NULL,
 message TEXT NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_by BIGINT UNSIGNED NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE alert_acknowledgements(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,alert_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,
 acknowledged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_ack(alert_id,user_id),
 FOREIGN KEY(alert_id) REFERENCES alerts(id),FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE incident_reports(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,location_id BIGINT UNSIGNED NOT NULL,title VARCHAR(180) NOT NULL,
 description TEXT NOT NULL,severity ENUM('Information','Watch','Warning','Critical') NOT NULL,status ENUM('Open','Reviewing','Resolved') NOT NULL DEFAULT 'Open',
 reported_by BIGINT UNSIGNED NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(location_id) REFERENCES locations(id),FOREIGN KEY(reported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE audit_logs(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,action VARCHAR(100) NOT NULL,details TEXT NULL,
 ip_address VARCHAR(45) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Demo login: admin@aquatwin.local / password
INSERT INTO users(name,email,password_hash,role) VALUES
('AquaTwin Administrator','admin@aquatwin.local','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Admin');
INSERT INTO locations(name,area_type,latitude,longitude,description) VALUES
('Indus River — Sukkur','River',27.7052,68.8574,'Demonstration monitoring location; verify coordinates and gauge data.'),
('Indus River — Guddu','River',28.4250,69.7130,'Demonstration monitoring location; verify coordinates and gauge data.'),
('Hub River','River',25.1500,66.8500,'Demonstration monitoring location; verify coordinates and gauge data.'),
('Karachi Urban Catchment','Catchment',24.8607,67.0011,'Urban demonstration location; not a flood boundary.');
INSERT INTO alerts(title,severity,message) VALUES
('Demo environment','Information','This is a demonstration notice. It is not an official weather or flood warning.');

-- Global river reference points for exploring the GloFAS discharge grid.
-- These are map reference points, NOT live gauge stations or flood declarations.
INSERT INTO locations(name,area_type,latitude,longitude,description) VALUES
('Ganges River — Patna, India','River',25.5941,85.1376,'Global model reference point; no local gauge feed is implied.'),
('Brahmaputra River — Guwahati, India','River',26.1445,91.7362,'Global model reference point; verify nearest river grid cell.'),
('Nile River — Cairo, Egypt','River',30.0444,31.2357,'Global model reference point; no local gauge feed is implied.'),
('Amazon River — Manaus, Brazil','River',-3.1190,-60.0217,'Global model reference point; no local gauge feed is implied.'),
('Mississippi River — New Orleans, USA','River',29.9511,-90.0715,'Global model reference point; no local gauge feed is implied.'),
('Thames River — London, UK','River',51.5072,-0.1276,'Global model reference point; no local gauge feed is implied.'),
('Rhine River — Cologne, Germany','River',50.9375,6.9603,'Global model reference point; no local gauge feed is implied.'),
('Yangtze River — Wuhan, China','River',30.5928,114.3055,'Global model reference point; no local gauge feed is implied.'),
('Mekong River — Phnom Penh, Cambodia','River',11.5564,104.9282,'Global model reference point; no local gauge feed is implied.'),
('Murray River — Mildura, Australia','River',-34.1850,142.1625,'Global model reference point; no local gauge feed is implied.');


-- Demo staff accounts (change both passwords after first login).
-- Both demo passwords are: password
INSERT INTO users(name,email,password_hash,role) VALUES
('Demo Area Operator','operator@aquatwin.local','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Area Operator');

-- Demo operator is assigned only to these two areas.
INSERT INTO operator_assignments(user_id,location_id)
SELECT u.id,l.id FROM users u JOIN locations l
WHERE u.email='operator@aquatwin.local'
AND l.name IN ('Indus River — Sukkur','Hub River');

CREATE TABLE flood_map_layers(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(180) NOT NULL, source VARCHAR(180) NOT NULL,
 geojson_file VARCHAR(255) NOT NULL, observed_at DATETIME NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1, uploaded_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;


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
