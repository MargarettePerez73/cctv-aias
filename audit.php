<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db);

$userId = query_int('user');
$actionName = query_string('action_name', 80);
$from = query_date('from');
$to = query_date('to');
[$limit, $offset, $page] = paging(40);

$where = ['1 = 1'];
$params = [];
if ($userId) {
    $where[] = 'a.user_id = ?';
    $params[] = $userId;
}
if ($actionName !== '') {
    $where[] = 'a.action = ?';
    $params[] = $actionName;
}
if ($from) {
    $where[] = 'a.created_at >= ?';
    $params[] = "{$from} 00:00:00";
}
if ($to) {
    $where[] = 'a.created_at <= ?';
    $params[] = "{$to} 23:59:59";
}
$clause = implode(' AND ', $where);
$total = (int) scalar($db, "SELECT COUNT(*) FROM audit_logs a WHERE {$clause}", $params);
$entries = rows(
    $db,
    "SELECT a.*, u.full_name, u.role FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
     WHERE {$clause} ORDER BY a.id DESC LIMIT {$limit} OFFSET {$offset}",
    $params
);
$people = rows($db, 'SELECT id, full_name FROM users ORDER BY full_name');
$actions = $db->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

function action_tone(string $action): string {
    return match (true) {
        (bool) preg_match('/FAILED|DENIED|DELETE|PANIC/', $action) => 'red',
        (bool) preg_match('/PLAY|DOWNLOAD/', $action) => 'violet',
        (bool) preg_match('/LOGIN|LOGOUT/', $action) => 'teal',
        (bool) preg_match('/UPDATE|CREATE|ISSUE|SETTINGS/', $action) => 'amber',
        default => 'slate',
    };
}

function describe_details(?string $details): string {
    if (!$details) return '';
    $decoded = json_decode($details, true);
    if (!is_array($decoded)) return $details;
    $parts = [];
    foreach ($decoded as $key => $value) {
        $parts[] = str_replace('_', ' ', (string) $key) . ': ' . (is_scalar($value) ? var_export($value, true) : json_encode($value));
    }
    return str_replace("'", '', implode(' · ', $parts));
}

render_header($db, $user, 'Audit logs');
page_header(
    'Audit logs',
    icon('lock', 12) . ' Permanent record of sign-ins, video playback, downloads, reviews and configuration changes. The database rejects edits and deletions.'
);
?>
<div class="card filters">
  <form method="get" class="filter-form">
    <select class="input" name="user">
      <option value="">All users</option>
      <?php foreach ($people as $person): ?>
        <option value="<?= (int) $person['id'] ?>"<?= selected($userId, $person['id']) ?>><?= e($person['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="input" name="action_name">
      <option value="">All actions</option>
      <?php foreach ($actions as $action): ?>
        <option value="<?= e($action) ?>"<?= selected($actionName, $action) ?>><?= e(strtolower(str_replace('_', ' ', $action))) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From date">
    <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
    <button class="btn"><?= icon('search', 14) ?> Filter</button>
  </form>
</div>
<div class="card">
  <?php if (!$entries): ?>
    <?= empty_state('file-clock', 'No audit entries match') ?>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Target</th><th>Details</th><th>IP address</th></tr></thead>
        <tbody>
          <?php foreach ($entries as $entry): ?>
            <tr>
              <td class="nowrap"><?= e(fmt_datetime($entry['created_at'])) ?></td>
              <td><strong><?= e($entry['full_name'] ?? '—') ?></strong><?php if ($entry['role']): ?><small><?= e(ROLE_LABELS[$entry['role']]) ?></small><?php endif; ?></td>
              <td><?= pill(strtolower(str_replace('_', ' ', $entry['action'])), action_tone($entry['action'])) ?></td>
              <td class="muted"><?= e(str_replace('_', ' ', $entry['entity_type'])) ?><?= $entry['entity_id'] ? ' #' . (int) $entry['entity_id'] : '' ?></td>
              <td class="details-cell muted"><?= e(describe_details($entry['details'])) ?></td>
              <td class="mono muted" title="<?= e($entry['user_agent'] ?? '') ?>"><?= e($entry['ip_address'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <?= pagination($total, $limit, $page) ?>
</div>
<?php
render_footer();
