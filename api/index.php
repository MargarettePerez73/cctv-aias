<?php
declare(strict_types=1);

/**
 * JSON endpoints used by the browser scripts: the monitoring station (webcam
 * frames, detections, recordings, heartbeat), alert polling and the panic
 * button. Everything else is handled by the PHP pages.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

/** @var array $config */

function api_user(PDO $db, array $roles = ALL_ROLES): array {
    $user = load_user($db) ?? fail('Your session has ended. Please sign in again.', 401);
    if (!in_array($user['role'], $roles, true)) fail('Your role does not have permission for this action', 403);
    return $user;
}

$routes = [
    'station-config' => ['GET', function (PDO $db) {
        api_user($db);
        json_out(['cameras' => cameras($db), 'settings' => settings($db), 'upload_limit_bytes' => upload_limit_bytes()]);
    }],

    // Sent every ~30s by the browser acting as the monitoring station.
    'heartbeat' => ['POST', function (PDO $db, array $config) {
        api_user($db);
        $ids = array_values(array_filter(array_map('intval', (array) (body()['camera_ids'] ?? []))));
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE cameras SET last_seen_at = NOW(), status = 'online' WHERE id IN ({$placeholders})")->execute($ids);
        }
        $db->exec(
            "UPDATE cameras SET status = 'offline'
             WHERE status = 'online' AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL " . CAMERA_ONLINE_SECONDS . ' SECOND)'
        );
        $db->exec("UPDATE system_devices SET last_heartbeat_at = NOW(), status = 'online' WHERE device_type IN ('vpu', 'nvr')");
        $last = settings($db)['last_retention_run'];
        $deleted = (!$last || substr($last, 0, 10) !== date('Y-m-d')) ? run_retention($db, $config) : 0;
        json_out(['retention_deleted' => $deleted]);
    }],

    // Low-rate JPEG relay so phones and other PCs can watch feeds attached to the station.
    'frame-upload' => ['POST', function (PDO $db, array $config) {
        api_user($db);
        $cameraId = input_int('camera_id') ?? fail('camera_id required', 422);
        $file = uploaded_file('frame', ['image/jpeg' => 'jpg']);
        if ($file['size'] > 2 * 1024 * 1024) fail('Frame too large', 413);
        storage_path($config, 'frames');
        $relative = 'frames/camera-' . $cameraId . '.jpg';
        if (!move_uploaded_file($file['tmp'], storage_path($config) . '/' . $relative)) {
            throw new RuntimeException('Unable to store frame');
        }
        $db->prepare('UPDATE cameras SET latest_frame_path = ?, latest_frame_at = NOW() WHERE id = ?')->execute([$relative, $cameraId]);
        json_out([]);
    }],

    'frame' => ['GET', function (PDO $db, array $config) {
        api_user($db);
        // Frames older than a minute mean the station stopped streaming this camera.
        $relative = scalar(
            $db,
            'SELECT latest_frame_path FROM cameras WHERE id = ? AND is_enabled AND latest_frame_at > NOW() - INTERVAL 60 SECOND',
            [query_int('id', 0)]
        );
        $path = absolute_storage_file($config, $relative ?: null) ?? fail('No recent frame', 404);
        session_write_close();
        stream_file($path, 'image/jpeg');
    }],

    // Raised by the station's in-browser motion / flame / tamper analysis.
    'event-create' => ['POST', function (PDO $db, array $config) {
        api_user($db);
        $cameraId = input_int('camera_id') ?? fail('camera_id required', 422);
        $type = input_string('event_type', 20);
        $types = [
            'motion' => ['Motion detected', 'medium'],
            'fire' => ['Possible fire or smoke detected', 'critical'],
            'tamper' => ['Camera view blocked or feed lost', 'high'],
        ];
        if (!isset($types[$type])) fail('Unsupported detection type', 422);
        $camera = row($db, 'SELECT name, location FROM cameras WHERE id = ?', [$cameraId]) ?? fail('Camera not found', 404);

        // Server-side cooldown so a flapping detector cannot flood owners with alerts.
        $cooldown = settings($db)['event_cooldown_seconds'];
        $existing = scalar(
            $db,
            "SELECT id FROM security_events WHERE camera_id = ? AND event_type = ? AND detected_at > NOW() - INTERVAL {$cooldown} SECOND LIMIT 1",
            [$cameraId, $type]
        );
        if ($existing) json_out(['event_id' => (int) $existing, 'suppressed' => true]);

        $snapshot = isset($_FILES['snapshot']) ? store_upload($config, uploaded_file('snapshot', ['image/jpeg' => 'jpg']), 'snapshots') : null;
        [$title, $severity] = $types[$type];
        $score = input_string('score', 40);
        $eventId = create_event($db, [
            'camera_id' => $cameraId,
            'event_type' => $type,
            'severity' => $severity,
            'title' => $title,
            'description' => "{$camera['location']} ({$camera['name']})" . ($score !== '' ? " · {$score}" : ''),
            'snapshot_path' => $snapshot,
        ]);
        json_out(['event_id' => $eventId, 'suppressed' => false], 201);
    }],

    'event-snapshot' => ['GET', function (PDO $db, array $config) {
        api_user($db, MONITOR_ROLES);
        $path = absolute_storage_file($config, scalar($db, 'SELECT snapshot_path FROM security_events WHERE id = ?', [query_int('id', 0)]) ?: null)
            ?? fail('Snapshot not found', 404);
        session_write_close();
        stream_file($path, 'image/jpeg');
    }],

    'upload-recording' => ['POST', function (PDO $db, array $config) {
        $user = api_user($db);
        $cameraId = input_int('camera_id') ?? fail('A valid camera_id is required', 422);
        if (!scalar($db, 'SELECT id FROM cameras WHERE id = ?', [$cameraId])) fail('Camera not found', 404);
        $eventId = input_int('event_id');
        $file = uploaded_file('recording', RECORDING_MIMES);
        $relative = store_upload($config, $file, 'recordings');
        $absolute = storage_path($config) . '/' . $relative;
        $duration = max(1, min(3600, input_int('duration_seconds') ?? 1));
        $endedAt = new DateTimeImmutable();
        $retention = settings($db)['retention_days'];
        $db->prepare(
            "INSERT INTO recordings
             (camera_id, file_path, mime_type, started_at, ended_at, file_size_bytes, checksum_sha256,
              is_event_clip, event_id, recorded_by, retained_until)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL {$retention} DAY))"
        )->execute([
            $cameraId,
            $relative,
            $file['mime'],
            $endedAt->modify("-{$duration} seconds")->format('Y-m-d H:i:s'),
            $endedAt->format('Y-m-d H:i:s'),
            filesize($absolute),
            hash_file('sha256', $absolute),
            $eventId ? 1 : 0,
            $eventId,
            $user['id'],
        ]);
        $recordingId = (int) $db->lastInsertId();
        if ($eventId) {
            $db->prepare('UPDATE security_events SET clip_recording_id = ?, clip_path = ? WHERE id = ?')->execute([$recordingId, $relative, $eventId]);
        }
        audit($db, $user['id'], $eventId ? 'UPLOAD_EVENT_CLIP' : 'UPLOAD_RECORDING', 'recording', $recordingId, ['camera_id' => $cameraId, 'seconds' => $duration]);
        json_out(['recording_id' => $recordingId], 201);
    }],

    // Playback and download go through PHP so every viewing session lands in the audit trail.
    'recording-stream' => ['GET', function (PDO $db, array $config) {
        $user = api_user($db, MONITOR_ROLES);
        $id = query_int('id', 0);
        $download = ($_GET['download'] ?? '') === '1';
        if ($download && !can($user, 'download_recordings')) fail('Only the owner and administrators can download recordings', 403);
        $recording = row($db, 'SELECT file_path, mime_type, started_at FROM recordings WHERE id = ? AND deleted_at IS NULL', [$id])
            ?? fail('Recording not found', 404);
        $path = absolute_storage_file($config, $recording['file_path']) ?? fail('Recording file is missing from storage', 410);
        // Browsers issue several Range requests per playback; only log the one that starts it.
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($download || $range === '' || str_starts_with($range, 'bytes=0-')) {
            audit($db, $user['id'], $download ? 'DOWNLOAD_RECORDING' : 'PLAY_RECORDING', 'recording', $id);
        }
        session_write_close();
        $extension = RECORDING_MIMES[$recording['mime_type']] ?? 'webm';
        $name = $download ? 'recording-' . $id . '-' . date('Ymd-His', strtotime($recording['started_at'])) . '.' . $extension : null;
        stream_file($path, $recording['mime_type'], $name);
    }],

    'alerts' => ['GET', function (PDO $db) {
        $user = api_user($db);
        json_out([
            'data' => rows(
                $db,
                'SELECT a.id, a.event_id, a.read_at, a.created_at, e.event_type, e.severity, e.title, e.description, c.location
                 FROM alerts a
                 JOIN security_events e ON e.id = a.event_id
                 LEFT JOIN cameras c ON c.id = e.camera_id
                 WHERE a.recipient_user_id = ? ORDER BY a.id DESC LIMIT 20',
                [$user['id']]
            ),
            'unread' => (int) scalar($db, 'SELECT COUNT(*) FROM alerts WHERE recipient_user_id = ? AND read_at IS NULL', [$user['id']]),
            'siren_active' => siren_active($db),
            'open_events' => can($user, 'review_events') ? open_event_count($db) : 0,
        ]);
    }],

    'alerts-read' => ['POST', function (PDO $db) {
        $user = api_user($db);
        $db->prepare('UPDATE alerts SET read_at = NOW() WHERE recipient_user_id = ? AND read_at IS NULL')->execute([$user['id']]);
        json_out([]);
    }],

    'panic' => ['POST', function (PDO $db) {
        $user = api_user($db);
        json_out(['event_id' => trigger_panic($db, $user, input_string('location', 120) ?: 'Service counter')], 201);
    }],

    'siren-reset' => ['POST', function (PDO $db) {
        $user = api_user($db);
        authorize($user, 'reset_siren');
        $db->exec("UPDATE system_devices SET status = 'online' WHERE device_type = 'siren'");
        audit($db, $user['id'], 'RESET_SIREN', 'system_device');
        json_out([]);
    }],
];

$action = (string) ($_GET['action'] ?? '');

try {
    [$method, $handler] = $routes[$action] ?? fail('Unknown API action', 404);
    if ($_SERVER['REQUEST_METHOD'] !== $method) fail("Use {$method} for this action", 405);
    if ($method === 'POST') verify_csrf();
    $handler(connect($config, true), $config);
} catch (HttpError $error) {
    json_out(['message' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_out(['message' => 'Unexpected server error'], 500);
}
