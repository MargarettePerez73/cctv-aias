<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
$db = connect($config);
$needsSetup = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
$next = (string) ($_GET['next'] ?? 'index.php');
// Only allow redirects to local pages.
if (!preg_match('/^[a-z]+\.php(\?.*)?$/', $next)) $next = 'index.php';

if (load_user($db)) redirect($next);

if (is_post()) {
    handle_post(function () use ($db, $needsSetup, $next) {
        $email = strtolower(input_string('email', 190));
        $password = (string) ($_POST['password'] ?? '');

        if ($needsSetup) {
            $name = input_string('name', 120);
            if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid name and email address', 422);
            validate_password($password);
            $db->prepare("INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'administrator')")
                ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $id = (int) $db->lastInsertId();
            sign_in(['id' => $id, 'full_name' => $name, 'email' => $email, 'role' => ROLE_ADMIN]);
            audit($db, $id, 'INITIAL_SETUP', 'user', $id);
            return ['redirect' => 'index.php', 'message' => 'Administrator account created. Add the owner, guards and staff in User management.'];
        }

        $failures = (int) scalar(
            $db,
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'LOGIN_FAILED' AND ip_address = ? AND created_at > NOW() - INTERVAL 15 MINUTE",
            [$_SERVER['REMOTE_ADDR'] ?? null]
        );
        if ($failures >= 10) fail('Too many failed sign-in attempts. Try again in 15 minutes.', 429);

        $user = row($db, 'SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
            audit($db, $user ? (int) $user['id'] : null, 'LOGIN_FAILED', 'session', null, ['email' => $email]);
            fail('Invalid email or password', 401);
        }
        sign_in($user);
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        audit($db, (int) $user['id'], 'LOGIN', 'session');
        return ['redirect' => $next];
    }, 'login.php' . ($next !== 'index.php' ? '?next=' . urlencode($next) : ''));
}

render_auth_start($needsSetup ? 'Create administrator' : 'Sign in');
?>
  <form method="post" class="auth-card">
    <?= csrf_field() ?>
    <h2><?= $needsSetup ? 'Create the administrator' : 'Sign in' ?></h2>
    <p class="muted"><?= $needsSetup
        ? 'First-time setup. This account can then add the owner, guards and staff.'
        : 'Every sign-in is recorded in the audit log.' ?></p>
    <?php foreach (take_flashes() as $flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($needsSetup): ?>
      <label class="field">Full name
        <input class="input" name="name" required minlength="2" autocomplete="name" value="<?= e(old('name')) ?>">
      </label>
    <?php endif; ?>
    <label class="field">Email address
      <input class="input" type="email" name="email" required autocomplete="username" autofocus value="<?= e(old('email')) ?>">
    </label>
    <label class="field">Password
      <input class="input" type="password" name="password" required <?= $needsSetup ? 'minlength="10" autocomplete="new-password"' : 'autocomplete="current-password"' ?>>
      <?php if ($needsSetup): ?><small>At least 10 characters</small><?php endif; ?>
    </label>
    <button class="btn btn-primary btn-block btn-lg"><?= $needsSetup ? 'Create account' : 'Sign in' ?></button>
  </form>
<?php
render_auth_end();
