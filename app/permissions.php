<?php
declare(strict_types=1);

/** Single source of truth for the sidebar and page access. */
const NAV = [
    'index.php' => ['label' => 'Overview', 'icon' => 'dashboard', 'roles' => ALL_ROLES, 'section' => 'monitor'],
    'cameras.php' => ['label' => 'Live cameras', 'icon' => 'video', 'roles' => ALL_ROLES, 'section' => 'monitor'],
    'events.php' => ['label' => 'Security events', 'icon' => 'siren', 'roles' => MONITOR_ROLES, 'section' => 'monitor'],
    'recordings.php' => ['label' => 'Recordings', 'icon' => 'hard-drive', 'roles' => MONITOR_ROLES, 'section' => 'monitor'],
    'access.php' => ['label' => 'Access control', 'icon' => 'lock', 'roles' => MONITOR_ROLES, 'section' => 'monitor'],
    'door.php' => ['label' => 'Door terminal', 'icon' => 'door', 'roles' => ALL_ROLES, 'section' => 'monitor'],
    'audit.php' => ['label' => 'Audit logs', 'icon' => 'file-clock', 'roles' => MANAGER_ROLES, 'section' => 'manage'],
    'users.php' => ['label' => 'User management', 'icon' => 'users', 'roles' => MANAGER_ROLES, 'section' => 'manage'],
    'settings.php' => ['label' => 'System settings', 'icon' => 'settings', 'roles' => MANAGER_ROLES, 'section' => 'manage'],
];

/** Fine-grained permissions. The same checks guard every form handler and API route. */
const ABILITIES = [
    'review_events' => MONITOR_ROLES,
    'view_recordings' => MONITOR_ROLES,
    'download_recordings' => MANAGER_ROLES,
    'delete_recordings' => MANAGER_ROLES,
    'delete_event_clips' => [ROLE_ADMIN],
    'manage_users' => [ROLE_ADMIN],
    'manage_cards' => [ROLE_ADMIN],
    'edit_settings' => [ROLE_ADMIN],
    'reset_siren' => MONITOR_ROLES,
];

function can(?array $user, string $ability): bool {
    return has_role($user, ABILITIES[$ability]);
}

/** Ends the request with 403 when the user lacks an ability (used inside form handlers). */
function authorize(array $user, string $ability): void {
    if (!can($user, $ability)) fail('Your role does not have permission for this action', 403);
}

/**
 * Guards a page: sends visitors to the sign-in page and shows "no access" to
 * roles that are not allowed. Returns the signed-in user.
 */
function require_page(PDO $db, ?array $roles = null): array {
    $user = load_user($db);
    if (!$user) {
        $next = basename($_SERVER['SCRIPT_NAME']) . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
        redirect('login.php?next=' . urlencode($next));
    }
    $roles ??= NAV[basename($_SERVER['SCRIPT_NAME'])]['roles'] ?? ALL_ROLES;
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        render_header($db, $user, 'No access');
        echo empty_state('shield-off', "You don't have access to this page", "Your role doesn't include this area. Ask an administrator if you need access.", '<a class="link" href="index.php">Back to overview</a>');
        render_footer();
        exit;
    }
    return $user;
}
