<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

/** @var array $config */
if (is_post()) {
    try {
        verify_csrf();
        $db = connect($config);
        if ($user = load_user($db)) audit($db, $user['id'], 'LOGOUT', 'session');
        $_SESSION = [];
        session_destroy();
    } catch (HttpError) {
        // Stale form: fall through to the sign-in page either way.
    }
}
redirect('login.php');
