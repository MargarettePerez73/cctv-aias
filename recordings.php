<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db);

if (is_post()) {
    handle_post(function () use ($db, $config, $user) {
        if (($_POST['action'] ?? '') === 'retention') {
            authorize($user, 'edit_settings');
            $deleted = run_retention($db, $config);
            audit($db, $user['id'], 'RUN_RETENTION', 'recording', null, ['deleted' => $deleted]);
            return 'Retention cleanup removed ' . plural($deleted, 'expired recording') . '.';
        }
        authorize($user, 'delete_recordings');
        delete_recording($db, $config, $user, input_int('id') ?? fail('Recording id required', 422));
        return 'Recording deleted. The deletion is recorded in the audit log.';
    }, url_with([]));
}

$cameraId = query_int('camera');
$kind = query_string('kind', 20);
$from = query_date('from');
$to = query_date('to');
[$limit, $offset, $page] = paging(20);

$where = ['r.deleted_at IS NULL'];
$params = [];
if ($cameraId) {
    $where[] = 'r.camera_id = ?';
    $params[] = $cameraId;
}
if ($kind === 'event') $where[] = 'r.is_event_clip = TRUE';
if ($kind === 'continuous') $where[] = 'r.is_event_clip = FALSE';
if ($from) {
    $where[] = 'r.started_at >= ?';
    $params[] = "{$from} 00:00:00";
}
if ($to) {
    $where[] = 'r.started_at <= ?';
    $params[] = "{$to} 23:59:59";
}
$clause = implode(' AND ', $where);
$total = (int) scalar($db, "SELECT COUNT(*) FROM recordings r WHERE {$clause}", $params);
$recordings = rows(
    $db,
    "SELECT r.*, c.name AS camera_name, c.location, e.title AS event_title
     FROM recordings r
     JOIN cameras c ON c.id = r.camera_id
     LEFT JOIN security_events e ON e.id = r.event_id
     WHERE {$clause} ORDER BY r.started_at DESC, r.id DESC LIMIT {$limit} OFFSET {$offset}",
    $params
);
$storage = storage_stats($db, $config);
$settings = settings($db);
$cameras = cameras($db);

render_header($db, $user, 'Recordings');
page_header(
    'Recordings',
    e("{$storage['recordings']} recordings · " . fmt_bytes($storage['used_bytes']) . " stored · {$settings['retention_days']}-day retention, event clips are kept"),
    can($user, 'edit_settings')
        ? '<form method="post">' . csrf_field() . '<button class="btn" name="action" value="retention">' . icon('shield-alert', 14) . ' Run retention cleanup</button></form>'
        : ''
);
?>
<div class="card filters">
  <form method="get" class="filter-form">
    <select class="input" name="camera">
      <option value="">All cameras</option>
      <?php foreach ($cameras as $camera): ?>
        <option value="<?= (int) $camera['id'] ?>"<?= selected($cameraId, $camera['id']) ?>><?= e("{$camera['name']} · {$camera['location']}") ?></option>
      <?php endforeach; ?>
    </select>
    <select class="input" name="kind">
      <option value="">All recordings</option>
      <option value="event"<?= selected($kind, 'event') ?>>Event clips</option>
      <option value="continuous"<?= selected($kind, 'continuous') ?>>Continuous &amp; manual</option>
    </select>
    <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
    <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
    <button class="btn"><?= icon('search', 14) ?> Filter</button>
  </form>
</div>

<div class="card">
  <?php if (!$recordings): ?>
    <?= empty_state('film', 'No recordings yet', 'Turn on continuous recording for a camera in System settings, or record a clip from Live cameras on the monitoring station.') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Camera</th><th>Recorded</th><th>Length</th><th>Size</th><th>Type</th><th>Kept until</th><th class="right">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($recordings as $recording):
              $canDelete = $recording['is_event_clip'] ? can($user, 'delete_event_clips') : can($user, 'delete_recordings'); ?>
            <tr>
              <td><strong><?= e($recording['location']) ?></strong><small><?= e($recording['camera_name']) ?></small></td>
              <td><?= e(fmt_datetime($recording['started_at'])) ?></td>
              <td class="mono"><?= fmt_duration($recording['started_at'], $recording['ended_at']) ?></td>
              <td><?= fmt_bytes($recording['file_size_bytes']) ?></td>
              <td>
                <?php if ($recording['is_event_clip']): ?>
                  <a href="events.php?id=<?= (int) $recording['event_id'] ?>"><?= pill('Event · ' . ($recording['event_title'] ?? 'clip'), 'red') ?></a>
                <?php else: ?>
                  <?= pill('Footage') ?>
                <?php endif; ?>
              </td>
              <td><?= $recording['is_event_clip'] ? 'Evidence' : e(fmt_date($recording['retained_until'])) ?></td>
              <td>
                <div class="row-actions">
                  <button type="button" class="icon-btn tone-teal" title="Play" aria-label="Play recording"
                    data-play="api/index.php?action=recording-stream&amp;id=<?= (int) $recording['id'] ?>"
                    data-title="<?= e($recording['location'] . ' · ' . fmt_datetime($recording['started_at'])) ?>"
                    data-subtitle="<?= e($recording['camera_name'] . ' · SHA-256 ' . substr((string) $recording['checksum_sha256'], 0, 16) . '… · this playback is logged') ?>"><?= icon('play', 14) ?></button>
                  <?php if (can($user, 'download_recordings')): ?>
                    <a class="icon-btn" title="Download" aria-label="Download recording" href="api/index.php?action=recording-stream&amp;id=<?= (int) $recording['id'] ?>&amp;download=1"><?= icon('download', 14) ?></a>
                  <?php endif; ?>
                  <?php if ($canDelete): ?>
                    <form method="post" data-confirm="Delete this recording permanently?<?= $recording['is_event_clip'] ? ' It is an event clip and may be needed as evidence.' : '' ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $recording['id'] ?>">
                      <button class="icon-btn hover-danger" title="Delete" aria-label="Delete recording"><?= icon('trash', 14) ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?= pagination($total, $limit, $page) ?>
</div>

<dialog class="player" data-player>
  <div class="player-head">
    <div><strong data-player-title></strong><small data-player-subtitle></small></div>
    <button type="button" class="icon-btn dark" data-player-close aria-label="Close"><?= icon('x', 16) ?></button>
  </div>
  <video controls data-player-video></video>
</dialog>
<?php
render_footer();
