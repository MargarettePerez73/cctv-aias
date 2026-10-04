<?php
declare(strict_types=1);

const EVENT_STATUSES = ['new', 'reviewing', 'resolved', 'false_alarm'];
const EVENT_TYPES = ['motion', 'fire', 'panic', 'access', 'tamper', 'other'];
const RECORDING_MIMES = ['video/webm' => 'webm', 'video/mp4' => 'mp4'];

const EVENT_STATUS_LABELS = [
    'new' => 'New',
    'reviewing' => 'Reviewing',
    'resolved' => 'Resolved',
    'false_alarm' => 'False alarm',
];

const EVENT_TYPE_LABELS = [
    'motion' => 'Motion',
    'fire' => 'Fire',
    'panic' => 'Panic',
    'access' => 'Access',
    'tamper' => 'Tamper',
    'other' => 'Other',
];

// ---------------------------------------------------------------------------
// Audit trail & settings
// ---------------------------------------------------------------------------

function audit(PDO $db, ?int $userId, string $action, string $entity, ?int $entityId = null, ?array $details = null): void {
    $db->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, details)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $userId,
        $action,
        $entity,
        $entityId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        $details === null ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
    ]);
}

function settings(PDO $db): array {
    $values = [];
    foreach ($db->query('SELECT setting_key, setting_value FROM settings') as $row) {
        $values[$row['setting_key']] = $row['setting_value'];
    }
    return [
        'pharmacy_name' => $values['pharmacy_name'] ?? 'Community Pharmacy',
        'business_open' => $values['business_open'] ?? '07:00',
        'business_close' => $values['business_close'] ?? '21:00',
        'retention_days' => (int) ($values['retention_days'] ?? 30),
        'motion_mode' => $values['motion_mode'] ?? 'always',
        'motion_sensitivity' => (int) ($values['motion_sensitivity'] ?? 5),
        'event_cooldown_seconds' => (int) ($values['event_cooldown_seconds'] ?? 60),
        'event_clip_seconds' => (int) ($values['event_clip_seconds'] ?? 10),
        'segment_minutes' => (int) ($values['segment_minutes'] ?? 2),
        'fire_detection_enabled' => ($values['fire_detection_enabled'] ?? '0') === '1',
        'last_retention_run' => $values['last_retention_run'] ?? null,
    ];
}

function save_setting(PDO $db, string $key, string $value): void {
    $db->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

function save_settings(PDO $db, array $user, array $input): void {
    $rules = [
        'pharmacy_name' => fn ($v) => mb_strlen(trim((string) $v)) >= 2 ? mb_substr(trim((string) $v), 0, 120) : null,
        'business_open' => fn ($v) => preg_match('/^\d{2}:\d{2}$/', (string) $v) ? $v : null,
        'business_close' => fn ($v) => preg_match('/^\d{2}:\d{2}$/', (string) $v) ? $v : null,
        'retention_days' => fn ($v) => (int) $v >= 1 && (int) $v <= 365 ? (string) (int) $v : null,
        'motion_mode' => fn ($v) => in_array($v, ['always', 'after_hours', 'off'], true) ? $v : null,
        'motion_sensitivity' => fn ($v) => (int) $v >= 1 && (int) $v <= 10 ? (string) (int) $v : null,
        'event_cooldown_seconds' => fn ($v) => (int) $v >= 10 && (int) $v <= 3600 ? (string) (int) $v : null,
        'event_clip_seconds' => fn ($v) => is_numeric($v) && (int) $v >= 0 && (int) $v <= 60 ? (string) (int) $v : null,
        'segment_minutes' => fn ($v) => (int) $v >= 1 && (int) $v <= 10 ? (string) (int) $v : null,
    ];
    $changed = [];
    foreach ($rules as $key => $validate) {
        if (!array_key_exists($key, $input)) continue;
        $value = $validate($input[$key]);
        if ($value === null) fail('Invalid value for ' . str_replace('_', ' ', $key), 422);
        save_setting($db, $key, (string) $value);
        $changed[$key] = $value;
    }
    // Unchecked checkboxes are not submitted, so the flag is read explicitly.
    $fire = !empty($input['fire_detection_enabled']) ? '1' : '0';
    save_setting($db, 'fire_detection_enabled', $fire);
    $changed['fire_detection_enabled'] = $fire;
    audit($db, $user['id'], 'UPDATE_SETTINGS', 'settings', null, $changed);
}

// ---------------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------------

function validate_password(string $password): void {
    if (strlen($password) < 10) fail('Password must be at least 10 characters', 422);
}

function save_user(PDO $db, array $admin, array $input): int {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 120);
    $email = strtolower(mb_substr(trim((string) ($input['email'] ?? '')), 0, 190));
    $role = (string) ($input['role'] ?? '');
    $phone = mb_substr(trim((string) ($input['phone'] ?? '')), 0, 30);
    $active = $id === null || !empty($input['is_active']);
    $password = (string) ($input['password'] ?? '');

    if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid name and email', 422);
    if (!in_array($role, ALL_ROLES, true)) fail('Choose a valid role', 422);
    if (scalar($db, 'SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id ?? 0])) {
        fail('Another account already uses this email', 409);
    }

    if ($id === null) {
        validate_password($password);
        $db->prepare('INSERT INTO users (full_name, email, password_hash, role, phone) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $phone ?: null]);
        $id = (int) $db->lastInsertId();
        audit($db, $admin['id'], 'CREATE_USER', 'user', $id, ['role' => $role]);
        return $id;
    }

    if ($id === $admin['id'] && ($role !== ROLE_ADMIN || !$active)) {
        fail('You cannot remove your own administrator access', 422);
    }
    $db->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, phone = ?, is_active = ? WHERE id = ?')
        ->execute([$name, $email, $role, $phone ?: null, $active ? 1 : 0, $id]);
    if ($password !== '') {
        validate_password($password);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }
    audit($db, $admin['id'], 'UPDATE_USER', 'user', $id, ['role' => $role, 'active' => $active, 'password_reset' => $password !== '']);
    return $id;
}

// ---------------------------------------------------------------------------
// Cameras, storage & dashboard
// ---------------------------------------------------------------------------

function cameras(PDO $db): array {
    $online = CAMERA_ONLINE_SECONDS;
    return $db->query(
        "SELECT id, name, location, device_id, device_label, is_enabled, motion_detection, continuous_recording,
                last_seen_at, latest_frame_at,
                (is_enabled AND last_seen_at > NOW() - INTERVAL {$online} SECOND) AS online,
                (latest_frame_at > NOW() - INTERVAL 60 SECOND) AS frame_fresh
         FROM cameras ORDER BY id"
    )->fetchAll();
}

function save_camera(PDO $db, array $user, array $input): int {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $name = mb_substr(trim((string) ($input['name'] ?? '')), 0, 80);
    $location = mb_substr(trim((string) ($input['location'] ?? '')), 0, 120);
    if ($name === '' || $location === '') fail('Camera name and location are required', 422);
    $deviceId = mb_substr(trim((string) ($input['device_id'] ?? '')), 0, 255);
    $deviceLabel = mb_substr(trim((string) ($input['device_label'] ?? '')), 0, 255);
    $values = [
        $name,
        $location,
        $deviceId ?: null,
        $deviceLabel ?: null,
        !empty($input['is_enabled']) ? 1 : 0,
        !empty($input['motion_detection']) ? 1 : 0,
        !empty($input['continuous_recording']) ? 1 : 0,
    ];
    if ($id === null) {
        $db->prepare(
            "INSERT INTO cameras (name, location, device_id, device_label, is_enabled, motion_detection, continuous_recording, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'offline')"
        )->execute($values);
        $id = (int) $db->lastInsertId();
        audit($db, $user['id'], 'CREATE_CAMERA', 'camera', $id, ['name' => $name]);
    } else {
        $db->prepare(
            'UPDATE cameras SET name = ?, location = ?, device_id = ?, device_label = ?, is_enabled = ?,
                    motion_detection = ?, continuous_recording = ? WHERE id = ?'
        )->execute([...$values, $id]);
        audit($db, $user['id'], 'UPDATE_CAMERA', 'camera', $id, ['name' => $name, 'device' => $deviceLabel, 'enabled' => (bool) $values[4]]);
    }
    return $id;
}

function delete_camera(PDO $db, array $user, int $id): void {
    if ((int) scalar($db, 'SELECT COUNT(*) FROM recordings WHERE camera_id = ?', [$id]) > 0) {
        fail('This camera has recordings. Disable it instead so its evidence stays linked.', 409);
    }
    $db->prepare('DELETE FROM cameras WHERE id = ?')->execute([$id]);
    audit($db, $user['id'], 'DELETE_CAMERA', 'camera', $id);
}

function storage_stats(PDO $db, array $config): array {
    $row = $db->query(
        'SELECT COUNT(*) AS files, COALESCE(SUM(file_size_bytes), 0) AS bytes, MIN(started_at) AS oldest
         FROM recordings WHERE deleted_at IS NULL'
    )->fetch();
    $root = storage_path($config);
    return [
        'recordings' => (int) $row['files'],
        'used_bytes' => (int) $row['bytes'],
        'disk_free_bytes' => (int) (@disk_free_space($root) ?: 0),
        'disk_total_bytes' => (int) (@disk_total_space($root) ?: 0),
        'retained_days' => $row['oldest'] ? (int) (new DateTime($row['oldest']))->diff(new DateTime())->days : 0,
    ];
}

/** Station-backed devices are only online while the monitoring station keeps sending heartbeats. */
function system_devices(PDO $db): array {
    $online = CAMERA_ONLINE_SECONDS;
    return $db->query(
        "SELECT id, name, device_type, location, last_heartbeat_at,
                CASE WHEN device_type IN ('vpu', 'nvr')
                     THEN IF(last_heartbeat_at > NOW() - INTERVAL {$online} SECOND, 'online', 'offline')
                     ELSE status END AS status
         FROM system_devices ORDER BY id"
    )->fetchAll();
}

function siren_active(PDO $db): bool {
    return (int) $db->query("SELECT COUNT(*) FROM system_devices WHERE device_type = 'siren' AND status = 'warning'")->fetchColumn() > 0;
}

function open_event_count(PDO $db): int {
    return (int) $db->query("SELECT COUNT(*) FROM security_events WHERE status IN ('new', 'reviewing')")->fetchColumn();
}

// ---------------------------------------------------------------------------
// Security events & alerts
// ---------------------------------------------------------------------------

/** In-app alerts for every active owner, guard and administrator; browsers poll these. */
function dispatch_alerts(PDO $db, int $eventId): void {
    $recipients = $db->query(
        "SELECT id FROM users WHERE is_active = TRUE AND role IN ('owner', 'guard', 'administrator')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $statement = $db->prepare(
        "INSERT INTO alerts (event_id, recipient_user_id, channel, delivery_status, sent_at)
         VALUES (?, ?, 'push', 'delivered', NOW())"
    );
    foreach ($recipients as $recipientId) {
        $statement->execute([$eventId, $recipientId]);
    }
}

function create_event(PDO $db, array $data): int {
    $db->prepare(
        "INSERT INTO security_events
         (camera_id, triggered_by, event_type, severity, title, description, snapshot_path, cloud_backup_status, status, detected_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'not_required', 'new', NOW())"
    )->execute([
        $data['camera_id'] ?? null,
        $data['triggered_by'] ?? null,
        $data['event_type'],
        $data['severity'],
        $data['title'],
        $data['description'] ?? null,
        $data['snapshot_path'] ?? null,
    ]);
    $eventId = (int) $db->lastInsertId();
    dispatch_alerts($db, $eventId);
    return $eventId;
}

function trigger_panic(PDO $db, array $user, string $location): int {
    $eventId = create_event($db, [
        'event_type' => 'panic',
        'severity' => 'critical',
        'title' => 'Panic alarm triggered',
        'description' => "Triggered by {$user['name']} at {$location}",
        'triggered_by' => $user['id'],
    ]);
    $db->exec("UPDATE system_devices SET status = 'warning', last_heartbeat_at = NOW() WHERE device_type = 'siren'");
    audit($db, $user['id'], 'TRIGGER_PANIC', 'security_event', $eventId);
    return $eventId;
}

function update_event(PDO $db, array $user, int $id, string $status, string $notes): void {
    if (!in_array($status, EVENT_STATUSES, true)) fail('Invalid status', 422);
    $statement = $db->prepare(
        "UPDATE security_events SET status = ?, reviewed_by = ?, reviewed_at = NOW(),
                resolution_notes = COALESCE(NULLIF(?, ''), resolution_notes)
         WHERE id = ?"
    );
    $statement->execute([$status, $user['id'], mb_substr($notes, 0, 2000), $id]);
    if ($statement->rowCount() === 0) fail('Event not found', 404);
    audit($db, $user['id'], 'UPDATE_EVENT', 'security_event', $id, ['status' => $status]);
}

// ---------------------------------------------------------------------------
// RFID access control
// ---------------------------------------------------------------------------

function normalize_uid(string $uid): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $uid));
}

/**
 * A USB RFID reader behaves like a keyboard: it types the card UID and presses
 * Enter, which submits the door terminal form to this function.
 */
function rfid_scan(PDO $db, array $user, string $rawUid, string $door): array {
    $uid = normalize_uid($rawUid);
    if ($uid === '') fail('Card UID required', 422);
    $card = row(
        $db,
        'SELECT r.id, r.is_active, r.expires_at, u.full_name, u.role, u.is_active AS user_active
         FROM rfid_cards r JOIN users u ON u.id = r.user_id WHERE r.card_uid = ?',
        [$uid]
    );
    $reason = match (true) {
        !$card => 'Unknown card',
        !$card['is_active'] => 'Card deactivated',
        !$card['user_active'] => 'Cardholder account inactive',
        $card['expires_at'] !== null && strtotime($card['expires_at']) < time() => 'Card expired',
        default => null,
    };
    $result = $reason === null ? 'granted' : 'denied';
    $db->prepare('INSERT INTO access_logs (rfid_card_id, card_uid, door_name, result, reason, scanned_by) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$card['id'] ?? null, $uid, $door, $result, $reason ?? 'Authorized card', $user['id']]);
    $logId = (int) $db->lastInsertId();
    audit($db, $user['id'], $result === 'granted' ? 'RFID_GRANTED' : 'RFID_DENIED', 'access_log', $logId, ['card_uid' => $uid, 'door' => $door]);
    if ($result === 'denied') {
        create_event($db, [
            'event_type' => 'access',
            'severity' => 'high',
            'title' => 'Unauthorized RFID attempt',
            'description' => "{$door}: {$reason} (card {$uid})",
        ]);
    }
    return [
        'result' => $result,
        'reason' => $reason,
        'uid' => $uid,
        'holder' => $card ? ['name' => $card['full_name'], 'role' => $card['role']] : null,
    ];
}

function save_rfid_card(PDO $db, array $admin, array $input): void {
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT) ?: fail('Choose the cardholder', 422);
    $uid = normalize_uid((string) ($input['card_uid'] ?? ''));
    if (strlen($uid) < 4) fail('Scan or enter the card UID', 422);
    $expires = (string) ($input['expires_at'] ?? '');
    $expires = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) ? $expires . ' 23:59:59' : null;
    $active = $id === null || !empty($input['is_active']);
    if (scalar($db, 'SELECT id FROM rfid_cards WHERE card_uid = ? AND id <> ?', [$uid, $id ?? 0])) {
        fail('This card is already registered', 409);
    }
    if ($id === null) {
        $db->prepare('INSERT INTO rfid_cards (user_id, card_uid, is_active, expires_at) VALUES (?, ?, TRUE, ?)')
            ->execute([$userId, $uid, $expires]);
        audit($db, $admin['id'], 'ISSUE_RFID_CARD', 'rfid_card', (int) $db->lastInsertId(), ['user_id' => $userId, 'card_uid' => $uid]);
    } else {
        $db->prepare('UPDATE rfid_cards SET user_id = ?, card_uid = ?, is_active = ?, expires_at = ? WHERE id = ?')
            ->execute([$userId, $uid, $active ? 1 : 0, $expires, $id]);
        audit($db, $admin['id'], 'UPDATE_RFID_CARD', 'rfid_card', $id, ['active' => $active]);
    }
}

// ---------------------------------------------------------------------------
// Recordings & retention
// ---------------------------------------------------------------------------

function delete_recording(PDO $db, array $config, array $user, int $id): void {
    $recording = row($db, 'SELECT file_path, is_event_clip FROM recordings WHERE id = ? AND deleted_at IS NULL', [$id])
        ?? fail('Recording not found', 404);
    if ($recording['is_event_clip'] && $user['role'] !== ROLE_ADMIN) {
        fail('Event clips are evidence; only an administrator can delete them', 403);
    }
    if ($path = absolute_storage_file($config, $recording['file_path'])) @unlink($path);
    $db->prepare('UPDATE recordings SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
    audit($db, $user['id'], 'DELETE_RECORDING', 'recording', $id, ['event_clip' => (bool) $recording['is_event_clip']]);
}

/** Deletes expired non-event recordings. Event clips are evidence and are kept. */
function run_retention(PDO $db, array $config): int {
    $expired = $db->query(
        'SELECT id, file_path FROM recordings
         WHERE retained_until < CURDATE() AND deleted_at IS NULL AND is_event_clip = FALSE'
    )->fetchAll();
    $mark = $db->prepare('UPDATE recordings SET deleted_at = NOW() WHERE id = ?');
    foreach ($expired as $recording) {
        if ($path = absolute_storage_file($config, $recording['file_path'])) @unlink($path);
        $mark->execute([$recording['id']]);
    }
    save_setting($db, 'last_retention_run', date('Y-m-d H:i:s'));
    return count($expired);
}
