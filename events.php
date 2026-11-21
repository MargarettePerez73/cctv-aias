<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db);
$eventId = query_int('id');

if (is_post() && $eventId) {
    handle_post(function () use ($db, $user, $eventId) {
        authorize($user, 'review_events');
        $status = input_string('status', 20);
        $reason = input_string('reason', 40);
        update_event($db, $user, $eventId, $status, input_string('notes', 2000), $reason);
        return match ($status) {
            'resolved' => 'Event resolved: ' . RESOLUTION_REASONS[$reason] . '. Saved to the audit log.',
            'reviewing' => 'Event reopened for review and logged.',
            default => 'Event marked as ' . strtolower(EVENT_STATUS_LABELS[$status]) . ' and logged.',
        };
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
      <?php if ($event['resolution_reason']): ?>
        <dt>Resolution</dt><dd><?= e(RESOLUTION_REASONS[$event['resolution_reason']] ?? $event['resolution_reason']) ?></dd>
      <?php endif; ?>
      <dt>Notes</dt><dd><?= nl2br(e($event['resolution_notes'] ?: '—')) ?></dd>
    </dl>
    <?php if (in_array($event['status'], ['resolved', 'false_alarm'], true)): ?>
      <div class="review-closed">
        <button type="button" class="btn btn-block btn-closed" disabled>
          <?= icon($event['status'] === 'resolved' ? 'check-circle' : 'x-circle', 16) ?>
          <span><?= e(EVENT_STATUS_LABELS[$event['status']]) ?></span>
        </button>
        <form method="post" data-confirm="Reopen this event for review? It will appear under Needs review again.">
          <?= csrf_field() ?>
          <button class="link" name="status" value="reviewing"><?= icon('refresh', 13) ?> Reopen event</button>
        </form>
      </div>
    <?php else:
        $resolving = old('status') === 'resolved'; ?>
      <form method="post" class="stack">
        <?= csrf_field() ?>
        <label class="field">Review notes
          <textarea class="input" name="notes" rows="3" placeholder="What did you verify? Were authorities contacted?"><?= $resolving ? '' : e(old('notes')) ?></textarea>
          <small>Saved with your name (<?= e(ROLE_LABELS[$user['role']]) ?>) in the audit log</small>
        </label>
        <div class="button-grid">
          <button class="btn" name="status" value="reviewing"<?= $event['status'] === 'reviewing' ? ' disabled' : '' ?>><?= icon('eye', 14) ?> <?= $event['status'] === 'reviewing' ? 'Under review' : 'Reviewing' ?></button>
          <button class="btn" name="status" value="false_alarm"><?= icon('x-circle', 14) ?> False alarm</button>
          <button type="button" class="btn btn-primary span-2" data-dialog-open="resolve-dialog"><?= icon('check-circle', 14) ?> Mark resolved</button>
        </div>
      </form>

      <dialog class="modal" id="resolve-dialog" aria-labelledby="resolve-title"<?= $resolving ? ' data-open-on-load' : '' ?>>
        <form method="post" class="modal-form" data-resolve-form>
          <?= csrf_field() ?>
          <input type="hidden" name="status" value="resolved">
          <div class="modal-head">
            <span class="icon-box tone-green big"><?= icon('shield-check', 22) ?></span>
            <div>
              <h3 id="resolve-title">Resolve this event</h3>
              <p>What happened? Your answer is saved with your name in the audit log.</p>
            </div>
            <button type="button" class="icon-btn" data-dialog-close aria-label="Close"><?= icon('x', 15) ?></button>
          </div>
          <fieldset class="reasons">
            <legend>Reason for resolution</legend>
            <?php foreach (RESOLUTION_REASONS as $value => $label): ?>
              <label class="reason">
                <input type="radio" name="reason" value="<?= e($value) ?>" required<?= checked(old('reason') === $value) ?>>
                <span><?= e($label) ?></span>
              </label>
            <?php endforeach; ?>
          </fieldset>
          <label class="field">Details <span class="muted" data-notes-hint>(optional)</span>
            <textarea class="input" name="notes" rows="3" maxlength="2000" placeholder="e.g. Guard checked the stock room, nothing missing" data-reason-notes><?= $resolving ? e(old('notes')) : '' ?></textarea>
          </label>
          <div class="modal-actions">
            <button type="button" class="btn" data-dialog-close>Cancel</button>
            <button class="btn btn-primary"><?= icon('check-circle', 14) ?> Confirm resolved</button>
          </div>
        </form>
      </dialog>
    <?php endif; ?>
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
