<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db);
$manage = can($user, 'manage_users');
$editId = query_int('edit');
$creating = isset($_GET['new']);

if (is_post()) {
    $back = empty($_POST['id']) ? 'users.php?new=1' : 'users.php?edit=' . (int) $_POST['id'];
    handle_post(function () use ($db, $user) {
        authorize($user, 'manage_users');
        save_user($db, $user, $_POST);
        return ['redirect' => 'users.php', 'message' => empty($_POST['id']) ? 'Account created.' : 'Account updated.'];
    }, $back . '#user-form');
}

const ROLE_DESCRIPTIONS = [
    ROLE_OWNER => 'Receives alerts, reviews events, plays and downloads recordings.',
    ROLE_ADMIN => 'Full control: users, cameras, settings, recordings and audit logs.',
    ROLE_GUARD => 'Receives alerts, reviews events and plays recordings. Cannot download or delete.',
    ROLE_STAFF => 'Views live cameras, uses the door terminal and panic alarm. No recordings access.',
];

$people = rows(
    $db,
    'SELECT u.*, (SELECT COUNT(*) FROM rfid_cards r WHERE r.user_id = u.id AND r.is_active = TRUE) AS active_cards
     FROM users u ORDER BY u.is_active DESC, u.full_name'
);
$editing = $editId ? row($db, 'SELECT * FROM users WHERE id = ?', [$editId]) : null;

render_header($db, $user, 'User management');
page_header(
    'User management',
    $manage ? 'Accounts are deactivated rather than deleted so the audit trail stays intact.' : 'Only administrators can change accounts.',
    $manage ? '<a class="btn btn-primary" href="users.php?new=1#user-form">' . icon('plus', 14) . ' Add account</a>' : ''
);
?>
<div class="card">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Name</th><th>Role</th><th>Mobile</th><th>RFID cards</th><th>Last sign-in</th><th>Status</th><?php if ($manage): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($people as $person): ?>
          <tr class="<?= $person['is_active'] ? '' : 'faded' ?>">
            <td><strong><?= e($person['full_name']) ?><?= (int) $person['id'] === $user['id'] ? ' <em class="you">(you)</em>' : '' ?></strong><small><?= e($person['email']) ?></small></td>
            <td><?= pill(ROLE_LABELS[$person['role']], ['administrator' => 'violet', 'owner' => 'teal'][$person['role']] ?? 'slate') ?></td>
            <td><?= e($person['phone'] ?? '—') ?></td>
            <td><?= (int) $person['active_cards'] ?></td>
            <td class="muted"><?= e(time_ago($person['last_login_at'])) ?></td>
            <td><?= $person['is_active'] ? pill('Active', 'green') : pill('Deactivated') ?></td>
            <?php if ($manage): ?>
              <td class="right"><a class="icon-btn" href="users.php?edit=<?= (int) $person['id'] ?>#user-form" aria-label="Edit <?= e($person['full_name']) ?>"><?= icon('pencil', 14) ?></a></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($manage && ($editing || $creating)):
    $isSelf = $editing && (int) $editing['id'] === $user['id'];
    $role = old('role', $editing['role'] ?? ROLE_STAFF); ?>
  <form method="post" class="card form-card" id="user-form">
    <?= csrf_field() ?>
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="card-head">
      <div><h3><?= $editing ? 'Edit ' . e($editing['full_name']) : 'Add account' ?></h3><p>Changes are recorded in the audit log.</p></div>
      <a class="link" href="users.php">Cancel</a>
    </div>
    <div class="form-grid">
      <label class="field">Full name
        <input class="input" name="name" required minlength="2" value="<?= e(old('name', $editing['full_name'] ?? '')) ?>">
      </label>
      <label class="field">Email address
        <input class="input" type="email" name="email" required value="<?= e(old('email', $editing['email'] ?? '')) ?>">
      </label>
      <label class="field">Mobile number
        <input class="input" name="phone" placeholder="09xx xxx xxxx" value="<?= e(old('phone', $editing['phone'] ?? '')) ?>">
        <small>For alert contact</small>
      </label>
      <label class="field">Role
        <?php if ($isSelf): ?>
          <input type="hidden" name="role" value="<?= e($editing['role']) ?>">
          <select class="input" disabled><option><?= e(ROLE_LABELS[$editing['role']]) ?></option></select>
          <small>You cannot change your own role.</small>
        <?php else: ?>
          <select class="input" name="role">
            <?php foreach (ROLE_LABELS as $value => $label): ?>
              <option value="<?= $value ?>"<?= selected($role, $value) ?>><?= $label ?> — <?= e(ROLE_DESCRIPTIONS[$value]) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </label>
      <label class="field"><?= $editing ? 'Reset password' : 'Password' ?>
        <input class="input" type="password" name="password" minlength="10" autocomplete="new-password"<?= $editing ? '' : ' required' ?>>
        <small><?= $editing ? 'Leave blank to keep the current password' : 'At least 10 characters' ?></small>
      </label>
      <?php if ($editing && !$isSelf): ?>
        <label class="check"><input type="checkbox" name="is_active" value="1"<?= checked($editing['is_active']) ?>> Account active <small>Deactivated accounts cannot sign in and their RFID cards are refused.</small></label>
      <?php elseif ($isSelf): ?>
        <input type="hidden" name="is_active" value="1">
      <?php endif; ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary"><?= icon('save', 14) ?> Save account</button></div>
  </form>
<?php endif;

render_footer();
