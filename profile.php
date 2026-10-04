<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$user = require_page($db, ALL_ROLES);

if (is_post()) {
    handle_post(function () use ($db, $user) {
        if (($_POST['form'] ?? '') === 'password') {
            $hash = (string) scalar($db, 'SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
            if (!password_verify((string) ($_POST['current_password'] ?? ''), $hash)) fail('Current password is incorrect', 422);
            $next = (string) ($_POST['new_password'] ?? '');
            if ($next !== ($_POST['confirm_password'] ?? '')) fail('The new passwords do not match', 422);
            validate_password($next);
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($next, PASSWORD_DEFAULT), $user['id']]);
            audit($db, $user['id'], 'CHANGE_PASSWORD', 'user', $user['id']);
            return 'Password changed.';
        }
        $name = input_string('name', 120);
        $phone = input_string('phone', 30);
        if (mb_strlen($name) < 2) fail('Enter your full name', 422);
        $db->prepare('UPDATE users SET full_name = ?, phone = ? WHERE id = ?')->execute([$name, $phone ?: null, $user['id']]);
        audit($db, $user['id'], 'UPDATE_PROFILE', 'user', $user['id']);
        return 'Profile updated.';
    }, 'profile.php');
}

render_header($db, $user, 'My profile');
page_header('My profile', e($user['email']) . ' ' . pill(ROLE_LABELS[$user['role']], 'teal'));
?>
<div class="two-cards">
  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="profile">
    <div class="card-head"><div><h3>Contact details</h3><p>Your mobile number is used for alert contact.</p></div></div>
    <div class="card-body stack">
      <label class="field">Full name<input class="input" name="name" required minlength="2" value="<?= e(old('name', $user['name'])) ?>"></label>
      <label class="field">Mobile number<input class="input" name="phone" placeholder="09xx xxx xxxx" value="<?= e(old('phone', $user['phone'] ?? '')) ?>"></label>
      <div><button class="btn btn-primary"><?= icon('save', 14) ?> Save profile</button></div>
    </div>
  </form>
  <form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="form" value="password">
    <div class="card-head"><div><h3>Change password</h3><p>At least 10 characters.</p></div></div>
    <div class="card-body stack">
      <label class="field">Current password<input class="input" type="password" name="current_password" required autocomplete="current-password"></label>
      <label class="field">New password<input class="input" type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>
      <label class="field">Confirm new password<input class="input" type="password" name="confirm_password" required minlength="10" autocomplete="new-password"></label>
      <div><button class="btn"><?= icon('key', 14) ?> Change password</button></div>
    </div>
  </form>
</div>
<?php
render_footer();
