<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db);
$tab = ($_GET['tab'] ?? '') === 'cards' && has_role($user, MANAGER_ROLES) ? 'cards' : 'log';

if (is_post()) {
    handle_post(function () use ($db, $user) {
        authorize($user, 'manage_cards');
        save_rfid_card($db, $user, $_POST);
        return empty($_POST['id']) ? 'Card issued.' : 'Card updated.';
    }, 'access.php?tab=cards');
}

render_header($db, $user, 'Access control');
page_header(
    'Access control',
    'Stock room RFID entries. Every scan — granted or denied — is permanently logged.',
    '<a class="btn" href="door.php">' . icon('door', 14) . ' Open door terminal</a>'
);

if (has_role($user, MANAGER_ROLES)): ?>
  <div class="tabs tabs-page">
    <a class="tab<?= $tab === 'log' ? ' active' : '' ?>" href="access.php">Entry log</a>
    <a class="tab<?= $tab === 'cards' ? ' active' : '' ?>" href="access.php?tab=cards">RFID cards</a>
  </div>
<?php endif;

if ($tab === 'log'):
    $result = in_array($_GET['result'] ?? '', ['granted', 'denied'], true) ? $_GET['result'] : '';
    $from = query_date('from');
    $to = query_date('to');
    [$limit, $offset, $page] = paging(30);
    $where = ['1 = 1'];
    $params = [];
    if ($result) {
        $where[] = 'a.result = ?';
        $params[] = $result;
    }
    if ($from) {
        $where[] = 'a.accessed_at >= ?';
        $params[] = "{$from} 00:00:00";
    }
    if ($to) {
        $where[] = 'a.accessed_at <= ?';
        $params[] = "{$to} 23:59:59";
    }
    $clause = implode(' AND ', $where);
    $total = (int) scalar($db, "SELECT COUNT(*) FROM access_logs a WHERE {$clause}", $params);
    $logs = rows(
        $db,
        "SELECT a.*, COALESCE(r.card_uid, a.card_uid) AS uid, u.full_name, u.role
         FROM access_logs a
         LEFT JOIN rfid_cards r ON r.id = a.rfid_card_id
         LEFT JOIN users u ON u.id = r.user_id
         WHERE {$clause} ORDER BY a.accessed_at DESC, a.id DESC LIMIT {$limit} OFFSET {$offset}",
        $params
    );
    ?>
  <div class="card filters">
    <form method="get" class="filter-form">
      <select class="input" name="result">
        <option value="">All attempts</option>
        <option value="granted"<?= selected($result, 'granted') ?>>Granted</option>
        <option value="denied"<?= selected($result, 'denied') ?>>Denied</option>
      </select>
      <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
      <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
      <button class="btn"><?= icon('search', 14) ?> Filter</button>
    </form>
  </div>
  <div class="card">
    <?php if (!$logs): ?>
      <?= empty_state('door', 'No entries recorded', 'Card scans from the door terminal appear here.', '<a class="link" href="door.php">Open door terminal</a>') ?>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Time</th><th>Cardholder</th><th>Card UID</th><th>Door</th><th>Result</th></tr></thead>
          <tbody>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td><?= e(fmt_datetime($log['accessed_at'])) ?></td>
                <td><strong><?= e($log['full_name'] ?? 'Unknown') ?></strong><?php if ($log['role']): ?><small><?= e(ROLE_LABELS[$log['role']]) ?></small><?php endif; ?></td>
                <td class="mono muted"><?= e($log['uid'] ?? '—') ?></td>
                <td><?= e($log['door_name']) ?></td>
                <td><?= $log['result'] === 'granted' ? pill('Granted', 'green') : pill('Denied · ' . $log['reason'], 'red') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?= pagination($total, $limit, $page) ?>
  </div>
<?php else:
    $manage = can($user, 'manage_cards');
    $cards = rows(
        $db,
        "SELECT r.*, u.full_name, u.role,
                (SELECT MAX(accessed_at) FROM access_logs a WHERE a.rfid_card_id = r.id AND a.result = 'granted') AS last_used_at
         FROM rfid_cards r JOIN users u ON u.id = r.user_id ORDER BY r.is_active DESC, u.full_name"
    );
    $people = rows($db, 'SELECT id, full_name, role FROM users WHERE is_active = TRUE ORDER BY full_name');
    $editId = query_int('edit');
    $editing = $editId ? row($db, 'SELECT * FROM rfid_cards WHERE id = ?', [$editId]) : null;
    ?>
  <div class="card">
    <div class="card-head">
      <div><h3>Authorized cards</h3><p><?= $manage ? 'Issue, suspend or expire stock room access.' : 'Only administrators can change cards.' ?></p></div>
    </div>
    <?php if (!$cards): ?>
      <?= empty_state('card', 'No cards issued', 'Issue a card to each staff member allowed into the stock room.') ?>
    <?php endif; ?>
    <div class="list">
      <?php foreach ($cards as $card): ?>
        <div class="list-row">
          <span class="icon-box <?= $card['is_active'] ? 'tone-blue' : 'tone-slate' ?>"><?= icon('key', 16) ?></span>
          <span class="list-main">
            <strong><?= e($card['full_name']) ?> <em>· <?= e(ROLE_LABELS[$card['role']]) ?></em></strong>
            <small class="mono"><?= e($card['card_uid']) ?> · last used <?= e(time_ago($card['last_used_at'])) ?><?= $card['expires_at'] ? ' · expires ' . e(fmt_date($card['expires_at'])) : '' ?></small>
          </span>
          <?= $card['is_active'] ? pill('Active', 'green') : pill('Suspended') ?>
          <?php if ($manage): ?>
            <a class="icon-btn" href="access.php?tab=cards&amp;edit=<?= (int) $card['id'] ?>#card-form" aria-label="Edit card for <?= e($card['full_name']) ?>"><?= icon('pencil', 14) ?></a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($manage): ?>
    <form method="post" class="card form-card" id="card-form">
      <?= csrf_field() ?>
      <div class="card-head"><div><h3><?= $editing ? 'Edit card' : 'Issue a card' ?></h3><p>Only active cards open the stock room door.</p></div>
        <?php if ($editing): ?><a class="link" href="access.php?tab=cards">Cancel edit</a><?php endif; ?></div>
      <div class="form-grid">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
        <label class="field">Cardholder
          <select class="input" name="user_id" required>
            <option value="">Choose a person…</option>
            <?php foreach ($people as $person): ?>
              <option value="<?= (int) $person['id'] ?>"<?= selected(old('user_id', $editing['user_id'] ?? ''), $person['id']) ?>><?= e($person['full_name'] . ' · ' . ROLE_LABELS[$person['role']]) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">Card UID
          <input class="input mono" name="card_uid" required placeholder="e.g. 04A1B2C3" value="<?= e(old('card_uid', $editing['card_uid'] ?? '')) ?>">
          <small>Click here and tap the card on the USB RFID reader, or type the UID printed on the card.</small>
        </label>
        <label class="field">Expires (optional)
          <input class="input" type="date" name="expires_at" value="<?= e(old('expires_at', substr((string) ($editing['expires_at'] ?? ''), 0, 10))) ?>">
        </label>
        <?php if ($editing): ?>
          <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked($editing['is_active']) ?>> Card active</label>
        <?php endif; ?>
      </div>
      <div class="form-actions"><button class="btn btn-primary"><?= icon($editing ? 'save' : 'plus', 14) ?> <?= $editing ? 'Save card' : 'Issue card' ?></button></div>
    </form>
  <?php endif; ?>
<?php endif;

render_footer();
