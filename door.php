<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db);
$doors = ['Stock room', 'Office', 'Back entrance'];
$door = in_array($_GET['door'] ?? '', $doors, true) ? $_GET['door'] : 'Stock room';

/**
 * Kiosk page for the stock room door. A USB RFID reader acts as a keyboard: it
 * types the card UID and presses Enter, which submits the always-focused form.
 */
if (is_post()) {
    try {
        verify_csrf();
        $_SESSION['door_result'] = rfid_scan($db, $user, (string) ($_POST['card_uid'] ?? ''), $door);
        $_SESSION['door_history'] = array_slice([$_SESSION['door_result'] + ['at' => date('g:i:s A')], ...($_SESSION['door_history'] ?? [])], 0, 8);
    } catch (HttpError $error) {
        flash('error', $error->getMessage());
    }
    redirect('door.php?door=' . urlencode($door));
}

$last = $_SESSION['door_result'] ?? null;
unset($_SESSION['door_result']);
$history = $_SESSION['door_history'] ?? [];

render_header($db, $user, 'Door terminal');
$options = '';
foreach ($doors as $name) {
    $options .= '<option' . selected($door, $name) . '>' . e($name) . '</option>';
}
page_header(
    'Door terminal',
    'Leave this page open on the PC beside the stock room with the USB RFID reader plugged in.',
    '<form method="get"><select class="input" name="door" aria-label="Door" onchange="this.form.submit()">' . $options . '</select></form>'
);
$granted = $last && $last['result'] === 'granted';
?>
<div class="door-layout">
  <div class="door-panel <?= $last ? ($granted ? 'granted' : 'denied') : '' ?>"<?= $last ? ' data-reset-after="5"' : '' ?>>
    <?php if (!$last): ?>
      <?= icon('scan', 56, 'door-scan') ?>
      <p class="door-title">Tap your card</p>
      <p class="door-sub"><?= e($door) ?> · waiting for RFID scan</p>
    <?php else: ?>
      <?= icon($granted ? 'check-circle' : 'x-circle', 64) ?>
      <p class="door-title"><?= $granted ? 'Access granted' : 'Access denied' ?></p>
      <p class="door-sub"><?= $last['holder'] ? e($last['holder']['name'] . ' · ' . ROLE_LABELS[$last['holder']['role']]) : 'Unregistered card' ?></p>
      <?php if (!$granted): ?><p class="door-reason"><?= e($last['reason']) ?> — security has been alerted.</p><?php endif; ?>
    <?php endif; ?>
    <form method="post" class="door-form">
      <?= csrf_field() ?>
      <input class="input" name="card_uid" autocomplete="off" autofocus placeholder="Card UID (reader types here)" aria-label="Card UID" data-keep-focus>
    </form>
  </div>

  <div class="card">
    <div class="card-head"><div><h3>Recent scans at this terminal</h3><p>The full history is in Access control.</p></div></div>
    <?php if (!$history): ?>
      <?= empty_state('card', 'No scans yet in this session') ?>
    <?php endif; ?>
    <div class="list">
      <?php foreach ($history as $scan): ?>
        <div class="list-row">
          <span class="dot <?= $scan['result'] === 'granted' ? 'dot-green' : 'dot-red' ?>"></span>
          <span class="list-main"><strong><?= e($scan['holder']['name'] ?? 'Unknown card') ?></strong><small class="mono"><?= e($scan['uid']) ?></small></span>
          <small class="muted"><?= e($scan['at']) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php
render_footer();
