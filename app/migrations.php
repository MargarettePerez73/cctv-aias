<?php
declare(strict_types=1);

/**
 * Idempotent schema upgrades applied on top of database/schema.sql.
 * They run automatically from the health check, so an existing XAMPP database
 * is upgraded in place without re-importing and without losing accounts.
 */
function migrations(): array {
    return [
        '2026_09_27_001_webcam_cameras' => [
            "ALTER TABLE cameras
               ADD COLUMN IF NOT EXISTS device_id VARCHAR(255) NULL AFTER stream_url,
               ADD COLUMN IF NOT EXISTS device_label VARCHAR(255) NULL AFTER device_id,
               ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT TRUE AFTER status,
               ADD COLUMN IF NOT EXISTS motion_detection BOOLEAN NOT NULL DEFAULT TRUE AFTER is_enabled,
               ADD COLUMN IF NOT EXISTS continuous_recording BOOLEAN NOT NULL DEFAULT FALSE AFTER motion_detection,
               ADD COLUMN IF NOT EXISTS latest_frame_path VARCHAR(500) NULL,
               ADD COLUMN IF NOT EXISTS latest_frame_at DATETIME NULL",
            "UPDATE cameras SET status = 'offline', last_seen_at = NULL WHERE device_id IS NULL",
        ],
        '2026_09_27_002_event_clips_and_alert_reads' => [
            "ALTER TABLE recordings
               ADD COLUMN IF NOT EXISTS event_id BIGINT UNSIGNED NULL AFTER is_event_clip,
               ADD COLUMN IF NOT EXISTS mime_type VARCHAR(40) NOT NULL DEFAULT 'video/webm' AFTER file_path,
               ADD COLUMN IF NOT EXISTS recorded_by BIGINT UNSIGNED NULL",
            "ALTER TABLE security_events
               ADD COLUMN IF NOT EXISTS clip_recording_id BIGINT UNSIGNED NULL AFTER clip_path,
               ADD COLUMN IF NOT EXISTS triggered_by BIGINT UNSIGNED NULL AFTER camera_id",
            "ALTER TABLE alerts ADD COLUMN IF NOT EXISTS read_at DATETIME NULL",
            "ALTER TABLE access_logs
               ADD COLUMN IF NOT EXISTS card_uid VARCHAR(100) NULL AFTER rfid_card_id,
               ADD COLUMN IF NOT EXISTS scanned_by BIGINT UNSIGNED NULL",
        ],
        '2026_09_27_003_settings' => [
            "CREATE TABLE IF NOT EXISTS settings (
               setting_key VARCHAR(60) PRIMARY KEY,
               setting_value VARCHAR(255) NOT NULL,
               updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
             ) ENGINE=InnoDB",
            "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
               ('pharmacy_name', 'Community Pharmacy'),
               ('business_open', '07:00'),
               ('business_close', '21:00'),
               ('retention_days', '30'),
               ('motion_mode', 'always'),
               ('motion_sensitivity', '5'),
               ('event_cooldown_seconds', '60'),
               ('event_clip_seconds', '10'),
               ('segment_minutes', '2'),
               ('fire_detection_enabled', '0')",
        ],
        // Phase 1 runs on USB / laptop webcams only; hardware not yet wired in is shown as "not installed".
        '2026_09_27_004_phase1_hardware' => [
            "UPDATE system_devices SET status = 'maintenance', last_heartbeat_at = NULL
             WHERE device_type IN ('ups', 'smoke_detector')",
            "UPDATE system_devices SET name = 'Monitoring station (browser)', location = 'Station PC'
             WHERE device_type = 'vpu'",
            "UPDATE system_devices SET name = 'Local recording storage', location = 'Station PC'
             WHERE device_type = 'nvr'",
            "UPDATE system_devices SET name = 'USB RFID reader (door terminal)'
             WHERE device_type = 'rfid_lock'",
        ],
        // Pretrained person detector in the station browser, and copies to a cloud-synced folder.
        '2026_10_04_001_person_detection_and_cloud_copy' => [
            "ALTER TABLE security_events
               MODIFY event_type ENUM('motion', 'fire', 'panic', 'access', 'tamper', 'person', 'other') NOT NULL",
            "ALTER TABLE recordings ADD COLUMN IF NOT EXISTS cloud_backup_path VARCHAR(500) NULL AFTER checksum_sha256",
            "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('person_detection_enabled', '1')",
        ],
    ];
}

function run_migrations(PDO $db): void {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
           id VARCHAR(120) PRIMARY KEY,
           applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
         ) ENGINE=InnoDB'
    );
    $applied = $db->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    foreach (migrations() as $id => $statements) {
        if (in_array($id, $applied, true)) continue;
        foreach ($statements as $sql) {
            $db->exec($sql);
        }
        $db->prepare('INSERT INTO schema_migrations (id) VALUES (?)')->execute([$id]);
    }
}
