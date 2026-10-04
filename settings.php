<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db);
$editable = can($user, 'edit_settings');

if (is_post()) {
    handle_post(function () use ($db, $user) {
        authorize($user, 'edit_settings');
        switch ($_POST['form'] ?? '') {
            case 'camera':
                $id = save_camera($db, $user, $_POST);
                return trim((string) $_POST['name']) . ' saved.';
            case 'camera-delete':
                delete_camera($db, $user, input_int('id') ?? fail('Camera id required', 422));
                return 'Camera removed.';
            default:
                save_settings($db, $user, $_POST);
                return 'Settings saved and logged. The monitoring station picks them up within 20 seconds.';
        }
    }, 'settings.php');
}

$settings = settings($db);
$cameras = cameras($db);
$disabled = $editable ? '' : ' disabled';
$nextName = sprintf('CAM %02d', count($cameras) + 1);

/**
 * Attributes for a toggle that asks "are you sure?" before it is switched on (and/or off) and then saves its
 * form straight away, so a ticked box cannot be lost by leaving the page without clicking Save.
 */
function toggle_attrs(string $confirmOn = '', string $confirmOff = '', bool $autosave = true): string {
    return ($confirmOn !== '' ? ' data-confirm-on="' . e($confirmOn) . '"' : '')
        . ($confirmOff !== '' ? ' data-confirm-off="' . e($confirmOff) . '"' : '')
        . ($autosave ? ' data-autosave' : '');
}

function camera_form(array $camera, string $disabled, bool $isNew = false): string {
    $name = $camera['name'];
    ob_start();
    ?>
<form method="post" class="camera-row" data-camera-form>
  <?= csrf_field() ?>
  <input type="hidden" name="form" value="camera">
  <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= (int) $camera['id'] ?>"><?php endif; ?>
  <input type="hidden" name="device_id" value="<?= e($camera['device_id'] ?? '') ?>" data-device-id>
  <input type="hidden" name="device_label" value="<?= e($camera['device_label'] ?? '') ?>" data-device-label>
  <label class="field">Name<input class="input" name="name" required value="<?= e($camera['name']) ?>"<?= $disabled ?>></label>
  <label class="field">Location<input class="input" name="location" required value="<?= e($camera['location']) ?>" placeholder="e.g. Store floor"<?= $disabled ?>></label>
  <label class="field">Webcam
    <select class="input" data-device-select<?= $disabled ?>>
      <option value=""><?= $camera['device_label'] ? e($camera['device_label']) . ' (saved)' : 'Not assigned' ?></option>
    </select>
  </label>
  <div class="camera-actions">
    <?php if (!$disabled): ?>
      <button class="btn btn-primary"><?= icon('save', 14) ?> <?= $isNew ? 'Add' : 'Save' ?></button>
    <?php endif; ?>
  </div>
  <div class="camera-toggles">
    <label class="check"><input type="checkbox" name="is_enabled" value="1"<?= checked($camera['is_enabled']) . $disabled . toggle_attrs(
        '',
        "Disable {$name}? It will stop streaming, recording and detecting until you enable it again.",
        !$isNew
    ) ?>> Enabled</label>
    <label class="check"><input type="checkbox" name="motion_detection" value="1"<?= checked($camera['motion_detection']) . $disabled . toggle_attrs(
        "Turn on motion & person detection for {$name}? Movement or a person in view will save a snapshot and clip and alert the owner, guards and administrators.",
        "Turn off motion & person detection for {$name}? This camera will no longer raise alerts for movement or people.",
        !$isNew
    ) ?>> Motion &amp; person detection <small>snapshot, event clip and alert</small></label>
    <label class="check"><input type="checkbox" name="continuous_recording" value="1"<?= checked($camera['continuous_recording']) . $disabled . toggle_attrs(
        "Turn on continuous recording for {$name}? Everything it sees is saved, which uses more disk space (kept for the retention period).",
        '',
        !$isNew
    ) ?>> Continuous recording <small>saved in short segments</small></label>
  </div>
</form>
<?php
    return (string) ob_get_clean();
}

render_header($db, $user, 'System settings');
page_header('System settings', $editable ? 'Changes apply to the monitoring station within 20 seconds.' : 'Read-only. Only administrators can change settings.');
?>
<div class="card">
  <div class="card-head">
    <div><h3>Cameras</h3><p>Map each camera slot to a USB or laptop webcam plugged into the monitoring station.</p></div>
    <?php if ($editable): ?>
      <button type="button" class="btn" data-detect-webcams><?= icon('refresh', 14) ?> Detect webcams</button>
    <?php endif; ?>
  </div>
  <?php if ($editable): ?>
    <p class="notice" data-webcam-notice><?= icon('webcam', 14) ?> Click <strong>Detect webcams</strong> and allow camera access to list the webcams on this computer. Do this from the monitoring station PC, since webcams are identified per computer.</p>
  <?php endif; ?>
  <?php foreach ($cameras as $camera): ?>
    <div class="camera-block">
      <?= camera_form($camera, $disabled) ?>
      <?php if ($editable): ?>
        <form method="post" class="camera-delete" data-confirm="Remove <?= e($camera['name']) ?>? Cameras with recordings must be disabled instead.">
          <?= csrf_field() ?>
          <input type="hidden" name="form" value="camera-delete">
          <input type="hidden" name="id" value="<?= (int) $camera['id'] ?>">
          <button class="link text-danger"><?= icon('trash', 13) ?> Remove camera</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($editable): ?>
    <details class="camera-add">
      <summary><?= icon('plus', 14) ?> Add camera</summary>
      <?= camera_form(['name' => $nextName, 'location' => '', 'device_id' => '', 'device_label' => '', 'is_enabled' => 1, 'motion_detection' => 1, 'continuous_recording' => 0], '', true) ?>
    </details>
  <?php endif; ?>
</div>

<form method="post" class="settings-grid">
  <?= csrf_field() ?>
  <input type="hidden" name="form" value="settings">
  <div class="card">
    <div class="card-head"><div><h3>Pharmacy</h3></div></div>
    <div class="card-body stack">
      <label class="field">Business name<input class="input" name="pharmacy_name" value="<?= e($settings['pharmacy_name']) ?>"<?= $disabled ?>></label>
      <div class="two-col">
        <label class="field">Opens<input class="input" type="time" name="business_open" value="<?= e($settings['business_open']) ?>"<?= $disabled ?>></label>
        <label class="field">Closes<input class="input" type="time" name="business_close" value="<?= e($settings['business_close']) ?>"<?= $disabled ?>></label>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Threat detection</h3><p>Runs in the monitoring station browser</p></div></div>
    <div class="card-body stack">
      <label class="field">Motion &amp; person alerts
        <select class="input" name="motion_mode"<?= $disabled ?>>
          <option value="always"<?= selected($settings['motion_mode'], 'always') ?>>Always armed</option>
          <option value="after_hours"<?= selected($settings['motion_mode'], 'after_hours') ?>>Only outside business hours</option>
          <option value="off"<?= selected($settings['motion_mode'], 'off') ?>>Off</option>
        </select>
      </label>
      <label class="field">Sensitivity: <output data-range-output><?= (int) $settings['motion_sensitivity'] ?></output>
        <input type="range" name="motion_sensitivity" min="1" max="10" value="<?= (int) $settings['motion_sensitivity'] ?>" data-range<?= $disabled ?>>
        <small>1 = only large movements, 10 = very small changes</small>
      </label>
      <label class="field">Alert cooldown (seconds)
        <input class="input" type="number" name="event_cooldown_seconds" min="10" max="3600" value="<?= (int) $settings['event_cooldown_seconds'] ?>"<?= $disabled ?>>
        <small>Minimum gap between alerts from the same camera</small>
      </label>
      <label class="check"><input type="checkbox" name="person_detection_enabled" value="1"<?= checked($settings['person_detection_enabled']) . $disabled . toggle_attrs(
          'Turn on person detection? The monitoring station downloads the AI model (about 5 MB) and raises a high-priority alert whenever it sees a person while alerts are armed.',
          'Turn off person detection? People in view will no longer raise alerts, only general motion will.'
      ) ?>> Person detection
        <small>Pretrained AI model (COCO-SSD) recognises people in the frame. Downloads about 5 MB the first time, so the station needs internet once.</small></label>
      <label class="check"><input type="checkbox" name="fire_detection_enabled" value="1"<?= checked($settings['fire_detection_enabled']) . $disabled . toggle_attrs(
          "Turn on flame-colour detection?\n\nLarge bright orange areas will raise a CRITICAL fire alert to the owner, guards and administrators. Orange objects or strong sunlight can cause false alarms, and it does not replace a smoke detector.",
          'Turn off flame-colour detection? The cameras will no longer raise fire alerts.'
      ) ?>> Flame-colour detection (experimental)
        <small>Flags large bright orange areas. Expect false alarms; not a replacement for smoke detectors.</small></label>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Recording &amp; retention</h3></div></div>
    <div class="card-body stack">
      <label class="field">Keep footage for (days)
        <input class="input" type="number" name="retention_days" min="1" max="365" value="<?= (int) $settings['retention_days'] ?>"<?= $disabled ?>>
        <small>Applies to new recordings. Event clips are kept until deleted.</small>
      </label>
      <label class="field">Event clip length (seconds)
        <input class="input" type="number" name="event_clip_seconds" min="0" max="60" value="<?= (int) $settings['event_clip_seconds'] ?>"<?= $disabled ?>>
        <small>Recorded right after a detection. 0 = snapshot only.</small>
      </label>
      <label class="field">Continuous segment length (minutes)
        <input class="input" type="number" name="segment_minutes" min="1" max="10" value="<?= (int) $settings['segment_minutes'] ?>"<?= $disabled ?>>
        <small>Shorter segments upload sooner and fit PHP&apos;s upload limit (<?= fmt_bytes(upload_limit_bytes()) ?>).</small>
      </label>
      <p class="muted small">Cloud copy: <?= $config['cloud_sync_path'] !== ''
          ? 'every snapshot and recording is also copied to <code>' . e($config['cloud_sync_path']) . '</code>'
          : 'off. Set <code>cloud_sync_path</code> in app/config.php to a Google Drive or OneDrive folder.' ?></p>
      <p class="muted small">Last automatic cleanup: <?= e(time_ago($settings['last_retention_run'])) ?></p>
    </div>
  </div>

  <?php if ($editable): ?>
    <div class="settings-save"><button class="btn btn-primary"><?= icon('save', 14) ?> Save settings</button></div>
  <?php endif; ?>
</form>
<?php
render_footer();
