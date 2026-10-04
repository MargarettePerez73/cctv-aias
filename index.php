<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db);
$settings = settings($db);
$monitor = can($user, 'review_events');
$cameras = cameras($db);
$storage = storage_stats($db, $config);
$devices = system_devices($db);

$stats = [
    'online' => count(array_filter($cameras, fn ($c) => $c['online'])),
    'total' => count(array_filter($cameras, fn ($c) => $c['is_enabled'])),
    'events_today' => (int) $db->query('SELECT COUNT(*) FROM security_events WHERE DATE(detected_at) = CURDATE()')->fetchColumn(),
    'events_open' => open_event_count($db),
    'granted' => (int) $db->query("SELECT COUNT(*) FROM access_logs WHERE result = 'granted' AND DATE(accessed_at) = CURDATE()")->fetchColumn(),
    'denied' => (int) $db->query("SELECT COUNT(*) FROM access_logs WHERE result = 'denied' AND DATE(accessed_at) = CURDATE()")->fetchColumn(),
];
$events = $monitor ? rows(
    $db,
    'SELECT e.id, e.event_type, e.title, e.status, e.detected_at, c.location
     FROM security_events e LEFT JOIN cameras c ON c.id = e.camera_id
     ORDER BY e.detected_at DESC, e.id DESC LIMIT 8'
) : [];

$tiles = [
    ['Cameras online', "{$stats['online']} / {$stats['total']}", $stats['online'] < $stats['total'] ? 'Some cameras are offline' : 'All cameras streaming', $stats['online'] >= $stats['total'], 'camera', 'tone-teal', 'cameras.php'],
    ['Events today', (string) $stats['events_today'], "{$stats['events_open']} need review", !$stats['events_open'], 'activity', 'tone-violet', $monitor ? 'events.php' : null],
    ['Stock room entries', (string) $stats['granted'], plural($stats['denied'], 'denied attempt'), !$stats['denied'], 'lock', 'tone-blue', $monitor ? 'access.php' : 'door.php'],
    ['Footage retained', plural($storage['retained_days'], 'day'), fmt_bytes($storage['used_bytes']) . " · {$settings['retention_days']}-day policy", true, 'hard-drive', 'tone-amber', $monitor ? 'recordings.php' : null],
];

$deviceLabels = [
    'vpu' => 'Video processing', 'nvr' => 'Local recorder', 'ups' => 'UPS battery', 'smoke_detector' => 'Smoke detector',
    'siren' => 'Panic siren', 'rfid_lock' => 'RFID door reader', 'other' => 'Device',
];
$warnings = array_filter($devices, fn ($d) => in_array($d['status'], ['offline', 'warning'], true));
$diskUsed = $storage['disk_total_bytes'] - $storage['disk_free_bytes'];

render_header($db, $user, 'Overview');
?>
<section class="page-header">
  <div>
    <p class="eyebrow"><?= date('l, F j') ?></p>
    <h2 class="hello"><?= greeting() ?>, <?= e(explode(' ', $user['name'])[0]) ?></h2>
    <p>Here&apos;s what&apos;s happening at <?= e($settings['pharmacy_name']) ?>.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="cameras.php"><?= icon('video', 16) ?> Live cameras</a>
    <?= panic_button() ?>
  </div>
</section>

<section class="stats">
  <?php foreach ($tiles as [$label, $value, $sub, $ok, $iconName, $tone, $href]): ?>
    <<?= $href ? 'a href="' . $href . '"' : 'article' ?> class="card stat<?= $href ? ' stat-link' : '' ?>">
      <div class="stat-row">
        <div><p class="stat-label"><?= e($label) ?></p><p class="stat-value"><?= e($value) ?></p></div>
        <span class="icon-box <?= $tone ?>"><?= icon($iconName, 17) ?></span>
      </div>
      <p class="stat-sub"><span class="dot <?= $ok ? 'dot-green' : 'dot-amber' ?>"></span><?= e($sub) ?></p>
    </<?= $href ? 'a' : 'article' ?>>
  <?php endforeach; ?>
</section>

<section class="section">
  <div class="section-head">
    <div><h3>Live surveillance</h3><p>Relayed from the monitoring station</p></div>
    <a class="link" href="cameras.php">View all <?= icon('chevron-right', 14) ?></a>
  </div>
  <?php if (!$cameras): ?>
    <div class="card"><?= empty_state('camera', 'No cameras configured', 'An administrator can add cameras in System settings.') ?></div>
  <?php else: ?>
    <div class="cam-grid cam-grid-4">
      <?php foreach (array_slice($cameras, 0, 4) as $camera) echo camera_tile($camera); ?>
    </div>
  <?php endif; ?>
</section>

<section class="overview-bottom<?= $monitor ? '' : ' single' ?>">
  <?php if ($monitor): ?>
    <div class="card">
      <div class="card-head">
        <div><h3>Recent events</h3><p>Detections, panic alarms and access attempts</p></div>
        <a class="link" href="events.php">Event log</a>
      </div>
      <?php if (!$events): ?>
        <?= empty_state('shield-check', 'No events recorded', 'Detections and alarms will appear here.') ?>
      <?php endif; ?>
      <div class="list">
        <?php foreach ($events as $event): ?>
          <a class="list-row" href="events.php?id=<?= (int) $event['id'] ?>">
            <?= event_icon($event['event_type']) ?>
            <span class="list-main"><strong><?= e($event['title']) ?></strong><small><?= e($event['location'] ?? 'System') ?> · <?= time_ago($event['detected_at']) ?></small></span>
            <?= status_pill($event['status']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="health">
    <div class="health-head">
      <div>
        <p class="eyebrow">System health</p>
        <h3><?= $warnings ? plural(count($warnings), 'device') . ' need attention' : 'All systems normal' ?></h3>
      </div>
      <span class="health-icon<?= $warnings ? ' warn' : '' ?>"><?= icon($warnings ? 'shield-alert' : 'shield-check', 20) ?></span>
    </div>
    <div class="health-list">
      <?php foreach ($devices as $device):
          $label = match (true) {
              $device['device_type'] === 'siren' && $device['status'] === 'warning' => 'Sounding',
              $device['status'] === 'maintenance' => 'Not installed',
              default => ucfirst($device['status']),
          }; ?>
        <div class="health-row">
          <span><span class="dot dot-<?= e($device['status']) ?>"></span><?= e($deviceLabels[$device['device_type']] ?? $device['name']) ?></span>
          <small><?= e($label) ?></small>
        </div>
      <?php endforeach; ?>
      <div class="health-disk">
        <div><span>Storage disk</span><span><?= fmt_bytes($diskUsed) ?> of <?= fmt_bytes($storage['disk_total_bytes']) ?></span></div>
        <div class="bar"><span style="width: <?= $storage['disk_total_bytes'] ? round($diskUsed / $storage['disk_total_bytes'] * 100) : 0 ?>%"></span></div>
      </div>
    </div>
  </div>
</section>
<?php
render_footer();
