CREATE DATABASE IF NOT EXISTS cctv_media
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE cctv_media;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner', 'administrator', 'guard', 'staff') NOT NULL,
  phone VARCHAR(30) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE cameras (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  location VARCHAR(120) NOT NULL,
  stream_url VARCHAR(500) NULL,
  ip_address VARCHAR(45) NULL,
  status ENUM('online', 'offline', 'maintenance') NOT NULL DEFAULT 'online',
  night_vision BOOLEAN NOT NULL DEFAULT TRUE,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE security_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  camera_id BIGINT UNSIGNED NULL,
  event_type ENUM('motion', 'fire', 'panic', 'access', 'tamper', 'other') NOT NULL,
  severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  snapshot_path VARCHAR(500) NULL,
  clip_path VARCHAR(500) NULL,
  cloud_backup_path VARCHAR(500) NULL,
  cloud_backup_status ENUM('pending', 'synced', 'failed', 'not_required') NOT NULL DEFAULT 'pending',
  status ENUM('new', 'reviewing', 'resolved', 'false_alarm') NOT NULL DEFAULT 'new',
  detected_at DATETIME NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  resolution_notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_events_detected (detected_at),
  INDEX idx_events_status (status),
  CONSTRAINT fk_events_camera FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE recordings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  camera_id BIGINT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NOT NULL,
  file_size_bytes BIGINT UNSIGNED NULL,
  checksum_sha256 CHAR(64) NULL,
  is_event_clip BOOLEAN NOT NULL DEFAULT FALSE,
  retained_until DATE NOT NULL,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_recordings_retention (retained_until, deleted_at),
  CONSTRAINT fk_recordings_camera FOREIGN KEY (camera_id) REFERENCES cameras(id)
) ENGINE=InnoDB;

CREATE TABLE rfid_cards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  card_uid VARCHAR(100) NOT NULL UNIQUE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  CONSTRAINT fk_cards_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE access_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rfid_card_id BIGINT UNSIGNED NULL,
  door_name VARCHAR(120) NOT NULL DEFAULT 'Stock room',
  result ENUM('granted', 'denied') NOT NULL,
  reason VARCHAR(255) NULL,
  accessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_access_time (accessed_at),
  CONSTRAINT fk_access_card FOREIGN KEY (rfid_card_id) REFERENCES rfid_cards(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE alerts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id BIGINT UNSIGNED NOT NULL,
  recipient_user_id BIGINT UNSIGNED NOT NULL,
  channel ENUM('sms', 'push', 'email') NOT NULL,
  delivery_status ENUM('queued', 'sent', 'delivered', 'failed') NOT NULL DEFAULT 'queued',
  provider_reference VARCHAR(190) NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_alert_event FOREIGN KEY (event_id) REFERENCES security_events(id),
  CONSTRAINT fk_alert_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE system_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  device_type ENUM('nvr', 'vpu', 'ups', 'smoke_detector', 'siren', 'rfid_lock', 'other') NOT NULL,
  location VARCHAR(120) NULL,
  status ENUM('online', 'offline', 'warning', 'maintenance') NOT NULL DEFAULT 'online',
  metadata JSON NULL,
  last_heartbeat_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_time (created_at),
  INDEX idx_audit_user (user_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

DELIMITER //
CREATE TRIGGER audit_logs_block_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs are immutable';
END//
CREATE TRIGGER audit_logs_block_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit logs cannot be deleted';
END//
DELIMITER ;

INSERT INTO cameras (name, location, status, night_vision, last_seen_at) VALUES
('CAM 01', 'Main entrance', 'online', TRUE, NOW()),
('CAM 02', 'Store floor', 'online', TRUE, NOW()),
('CAM 03', 'Service counter', 'online', TRUE, NOW()),
('CAM 04', 'Stock room', 'online', TRUE, NOW());

INSERT INTO system_devices (name, device_type, location, status, last_heartbeat_at) VALUES
('Primary video processor', 'vpu', 'Locked office cabinet', 'online', NOW()),
('2TB network recorder', 'nvr', 'Locked office cabinet', 'online', NOW()),
('Main backup UPS', 'ups', 'Locked office cabinet', 'online', NOW()),
('Store floor smoke detector', 'smoke_detector', 'Store floor ceiling', 'online', NOW()),
('Stock room smoke detector', 'smoke_detector', 'Stock room ceiling', 'online', NOW()),
('Stock room electronic lock', 'rfid_lock', 'Stock room door', 'online', NOW()),
('Counter panic siren', 'siren', 'Service counter', 'online', NOW());

-- Create the first administrator after import:
-- php -r "echo password_hash('Choose-A-Strong-Password', PASSWORD_DEFAULT), PHP_EOL;"
-- Then replace HASH_FROM_COMMAND below and execute the INSERT manually.
-- INSERT INTO users (full_name, email, password_hash, role)
-- VALUES ('System Administrator', 'admin@example.com', 'HASH_FROM_COMMAND', 'administrator');
