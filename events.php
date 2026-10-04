<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db);
$eventId = query_int('id');

if (is_post() && $eventId) {
    handle_post(function () use ($db, $user, $eventId) {
        $status = input_string('status', 20);
        update_event($db, $user, $eventId, $status, input_string('notes', 2000));
        return 'Event marked as ' . strtolower(EVENT_STATUS_LABELS[$status]) . ' and logged.';
    }, "events.php?id={$eventId}");
}

// ---------------------------------------------------------------------------
// Single event: evidence and review
// ---------------------------------------------------------------------------
if ($eventId) {
    $event = row(
        $db,
        'SELECT e.*, c.name AS camera_name, c.location, r.full_name AS reviewed_by_name, t.full_name AS triggered_by_name
         FROM security_events e
         LEFT JOIN cameras c ON c.id = e.camera_id
         LEFT JOIN users r ON r.id = e.reviewed_by
         LEFT JOIN users t ON t.id = e.triggered_by
         WHERE e.id = ?',
        [$eventId]
    );
    render_header($db, $user, 'Security events');
    if (!$event) {
        echo '<div class="card">' . empty_state('compass', 'Event not found', '', '<a class="link" href="events.php">Back to events</a>') . '</div>';
        render_footer();
        exit;
    }
    // Viewing an event marks the alerts for it as read.
    $db->prepare('UPDATE alerts SET read_at = NOW() WHERE event_id = ? AND recipient_user_id = ? AND read_at IS NULL')->execute([$eventId, $user['id']]);
    $urgent = in_array($event['severity'], ['high', 'critical'], true) && $event['status'] !== 'resolved';
    page_header($event['title'], 'Event #' . (int) $event['id'] . ' · ' . e(fmt_datetime($event['detected_at'])), '<a class="btn" href="events.php">' . icon('chevron-left', 14) . ' All events</a>');
    ?>
<div class="event-detail">
  <div class="evidence">
    <?php if ($event['clip_recording_id']): ?>
      <video controls preload="metadata" src="api/index.php?action=recording-stream&amp;id=<?= (int) $event['clip_recording_id'] ?>"></video>
    <?php endif; ?>
    <?php if ($event['snapshot_path']): ?>
      <img src="api/index.php?action=event-snapshot&amp;id=<?= (int) $event['id'] ?>" alt="Snapshot captured when the event was detected">
    <?php endif; ?>
    <?php if (!$event['clip_recording_id'] && !$event['snapshot_path']): ?>
      <div class="evidence-empty">No camera footage is attached to this event.<br>Check Recordings from the same time.</div>
    <?php endif; ?>
  </div>
  <div class="card review">
    <div class="review-pills"><?= severity_pill($event['severity']) ?><?= status_pill($event['status']) ?></div>
    <dl class="details">
      <dt>Detected</dt><dd><?= e(fmt_datetime($event['detected_at'])) ?></dd>
      <dt>Location</dt><dd><?= $event['location'] ? e("{$event['location']} ({$event['camera_name']})") : '—' ?></dd>
      <dt>Details</dt><dd><?= e($event['description'] ?: '—') ?></dd>
      <dt>Cloud copy</dt><dd><?= match ($event['cloud_backup_status']) {
          'synced' => e($event['cloud_backup_path']),
          'failed' => '<span class="text-danger">Copy failed. The local copy is safe.</span>',
          'pending' => 'Pending',
          default => 'Local storage only',
      } ?></dd>
      <dt>Triggered by</dt><dd><?= e($event['triggered_by_name'] ?: 'Automatic detection') ?></dd>
      <dt>Reviewed</dt><dd><?= $event['reviewed_by_name'] ? e($event['reviewed_by_name'] . ' · ' . fmt_datetime($event['reviewed_at'])) : 'Not yet' ?></dd>
      <dt>Notes</dt><dd><?= nl2br(e($event['resolution_notes'] ?: '—')) ?></dd>
    </dl>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <label class="field">Review notes
        <textarea class="input" name="notes" rows="3" placeholder="What did you verify? Were authorities contacted?"><?= e(old('notes')) ?></textarea>
        <small>Saved with your name (<?= e(ROLE_LABELS[$user['role']]) ?>) in the audit log</small>
      </label>
      <div class="button-grid">
        <button class="btn" name="status" value="reviewing"<?= $event['status'] === 'reviewing' ? ' disabled' : '' ?>><?= icon('eye', 14) ?> Reviewing</button>
        <button class="btn" name="status" value="false_alarm"><?= icon('x-circle', 14) ?> False alarm</button>
        <button class="btn btn-primary span-2" name="status" value="resolved"><?= icon('check-circle', 14) ?> Mark resolved</button>
      </div>
    </form>
    <?php if ($urgent): ?>
      <a class="btn btn-outline-danger btn-block" href="tel:911"><?= icon('phone', 14) ?> Real emergency? Call 911</a>
    <?php endif; ?>
  </div>
</div>
<?php
    render_footer();
    exit;
}

// ---------------------------------------------------------------------------
// Event list with filters
// ---------------------------------------------------------------------------
$status = $_GET['status'] ?? 'open';
$type = query_string('type', 20);
$q = query_string('q');
$from = query_date('from');
$to = query_date('to');
[$limit, $offset, $page] = paging(20);

$where = ['1 = 1'];
$params = [];
if (in_array($status, EVENT_STATUSES, true)) {
    $where[] = 'e.status = ?';
    $params[] = $status;
} elseif ($status === 'open') {
    $where[] = "e.status IN ('new', 'reviewing')";
}
if (in_array($type, EVENT_TYPES, true)) {
    $where[] = 'e.event_type = ?';
    $params[] = $type;
}
if ($from) {
    $where[] = 'e.detected_at >= ?';
    $params[] = "{$from} 00:00:00";
}
if ($to) {
    $where[] = 'e.detected_at <= ?';
    $params[] = "{$to} 23:59:59";
}
if ($q !== '') {
    $where[] = '(e.title LIKE ? OR e.description LIKE ? OR c.location LIKE ?)';
    array_push($params, "%{$q}%", "%{$q}%", "%{$q}%");
}
$clause = implode(' AND ', $where);
$total = (int) scalar($db, "SELECT COUNT(*) FROM security_events e LEFT JOIN cameras c ON c.id = e.camera_id WHERE {$clause}", $params);
$events = rows(
    $db,
    "SELECT e.id, e.event_type, e.severity, e.title, e.description, e.status, e.detected_at, e.snapshot_path IS NOT NULL AS has_snapshot,
            c.location
     FROM security_events e LEFT JOIN cameras c ON c.id = e.camera_id
     WHERE {$clause} ORDER BY e.detected_at DESC, e.id DESC LIMIT {$limit} OFFSET {$offset}",
    $params
);

render_header($db, $user, 'Security events');
page_header('Security events', 'Motion and fire detections, panic alarms, tampering and denied RFID attempts. Every review is audited.');
?>
<div class="card filters">
  <div class="tabs">
    <?php foreach (['open' => 'Needs review', 'all' => 'All', 'resolved' => 'Resolved', 'false_alarm' => 'False alarms'] as $value => $label): ?>
      <a class="tab<?= $status === $value ? ' active' : '' ?>" href="<?= e(url_with(['status' => $value, 'page' => null])) ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="filter-form">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search events…">
    <select class="input" name="type">
      <option value="">All types</option>
      <?php foreach (EVENT_TYPE_LABELS as $value => $label): ?>
        <option value="<?= $value ?>"<?= selected($type, $value) ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
    <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
    <button class="btn"><?= icon('search', 14) ?> Filter</button>
  </form>
</div>

<div class="card">
  <?php if (!$events): ?>
    <?= empty_state('shield-check', $status === 'open' ? 'Nothing needs review' : 'No events match these filters', 'New detections and alarms appear here automatically.') ?>
  <?php endif; ?>
  <div class="list">
    <?php foreach ($events as $event): ?>
      <a class="list-row" href="events.php?id=<?= (int) $event['id'] ?>">
        <?php if ($event['has_snapshot']): ?>
          <img class="thumb" src="api/index.php?action=event-snapshot&amp;id=<?= (int) $event['id'] ?>" alt="" loading="lazy">
        <?php else: ?>
          <?= event_icon($event['event_type'], 17) ?>
        <?php endif; ?>
        <span class="list-main">
          <strong><?= e($event['title']) ?> <?= severity_pill($event['severity']) ?></strong>
          <small><?= e($event['location'] ?? $event['description'] ?? 'System') ?> · <?= time_ago($event['detected_at']) ?></small>
        </span>
        <span class="list-date"><?= e(fmt_datetime($event['detected_at'])) ?></span>
        <?= status_pill($event['status']) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?= pagination($total, $limit, $page) ?>
</div>
<?php
render_footer();
