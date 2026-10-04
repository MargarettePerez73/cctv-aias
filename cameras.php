<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db);
$settings = settings($db);
$cameras = cameras($db);
$assigned = count(array_filter($cameras, fn ($c) => $c['device_id'] || $c['device_label']));

$mode = match ($settings['motion_mode']) {
    'off' => 'Motion detection is switched off',
    'after_hours' => "Motion alerts armed outside business hours ({$settings['business_open']}–{$settings['business_close']})",
    default => 'Motion alerts armed around the clock',
};

render_header($db, $user, 'Live cameras');
page_header(
    'Live cameras',
    e($mode) . ' · USB &amp; laptop webcams',
    '<div class="segmented" data-layout-switch>'
        . '<button type="button" data-layout="1" title="Single column">' . icon('square', 15) . '</button>'
        . '<button type="button" data-layout="2" class="active" title="2 × 2 grid">' . icon('columns', 15) . '</button>'
        . '<button type="button" data-layout="3" title="3 columns">' . icon('grid', 15) . '</button>'
        . '</div>' . panic_button()
);
?>
<div class="card station-panel" data-station-panel>
  <div class="station-info">
    <span class="icon-box tone-slate big" data-station-icon><?= icon('monitor', 19) ?></span>
    <div>
      <strong data-station-heading>This device is a viewer</strong>
      <p data-station-description>Feeds are relayed from the monitoring station every few seconds. If the USB / laptop webcams are plugged into this computer, make it the station.</p>
      <p class="text-danger" data-station-error hidden></p>
    </div>
  </div>
  <button type="button" class="btn btn-primary" data-station-toggle><?= icon('power', 14) ?> <span>Use this computer as station</span></button>
  <?php if ($assigned === 0): ?>
    <p class="notice notice-amber">
      No webcams are assigned to camera slots yet.
      <?= can($user, 'edit_settings') ? '<a href="settings.php">Assign them in System settings</a>.' : 'Ask an administrator to assign them in System settings.' ?>
    </p>
  <?php endif; ?>
</div>

<?php if (!$cameras): ?>
  <div class="card"><?= empty_state('camera', 'No cameras configured', 'An administrator can add camera slots in System settings.') ?></div>
<?php else: ?>
  <div class="cam-grid cam-grid-2" data-cam-grid>
    <?php foreach ($cameras as $camera) echo camera_tile($camera, true); ?>
  </div>
<?php endif; ?>

<p class="hint" data-station-hint hidden><?= icon('shield-check', 13) ?> Keep this tab open: it runs detection and recording. Use the record button on a feed to save a manual clip.</p>
<?php
render_footer(['assets/js/station.js']);
