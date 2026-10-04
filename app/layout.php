<?php
declare(strict_types=1);

/** Inline SVG icons (Lucide, ISC licence) so the app works offline with no icon font. */
function icon(string $name, int $size = 18, string $class = ''): string {
    static $paths = [
        'dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'video' => '<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
        'siren' => '<path d="M7 18v-6a5 5 0 1 1 10 0v6"/><path d="M5 21a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-1a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2z"/><path d="M21 12h1"/><path d="M18.5 4.5 18 5"/><path d="M2 12h1"/><path d="M12 2v1"/><path d="m4.929 4.929.707.707"/><path d="M12 12v6"/>',
        'hard-drive' => '<line x1="22" x2="2" y1="12" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" x2="6.01" y1="16" y2="16"/><line x1="10" x2="10.01" y1="16" y2="16"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'door' => '<path d="M18 20V6a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v14"/><path d="M2 20h20"/><path d="M14 12v.01"/>',
        'file-clock' => '<path d="M16 22h2a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v3"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><circle cx="8" cy="16" r="6"/><path d="M9.5 17.5 8 16.25V14"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'bell' => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
        'user' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'menu' => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'shield-alert' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'shield-off' => '<path d="m2 2 20 20"/><path d="M5 5a1 1 0 0 0-1 1v7c0 5 3.5 7.5 7.67 8.94a1 1 0 0 0 .67.01c2.35-.82 4.48-1.97 5.9-3.71"/><path d="M9.309 3.652A12.252 12.252 0 0 0 11.24 2.28a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1v7a9.784 9.784 0 0 1-.08 1.264"/>',
        'camera' => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
        'camera-off' => '<path d="M14.564 14.558a3 3 0 1 1-4.122-4.121"/><path d="m2 2 20 20"/><path d="M20 20H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h1.997a2 2 0 0 0 .819-.175"/><path d="M9.695 4.024A2 2 0 0 1 10.004 4h3.993a2 2 0 0 1 1.76 1.05l.486.9A2 2 0 0 0 18.003 7H20a2 2 0 0 1 2 2v7.344"/>',
        'activity' => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
        'play' => '<polygon points="6 3 20 12 6 21 6 3"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'x-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'eye' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'pencil' => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
        'save' => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
        'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'webcam' => '<circle cx="12" cy="10" r="8"/><circle cx="12" cy="10" r="3"/><path d="M7 22h10"/><path d="M12 22v-4"/>',
        'monitor' => '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
        'power' => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
        'maximize' => '<path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="M21 3l-7 7"/><path d="M3 21l7-7"/>',
        'record' => '<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"/>',
        'square' => '<rect width="14" height="14" x="5" y="5" rx="2"/>',
        'flame' => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
        'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
        'key' => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
        'card' => '<rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/>',
        'scan' => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/>',
        'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'volume-x' => '<path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><line x1="22" x2="16" y1="9" y2="15"/><line x1="16" x2="22" y1="9" y2="15"/>',
        'film' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18"/><path d="M3 7.5h4"/><path d="M3 12h18"/><path d="M3 16.5h4"/><path d="M17 3v18"/><path d="M17 7.5h4"/><path d="M17 16.5h4"/>',
        'unplug' => '<path d="m19 5 3-3"/><path d="m2 22 3-3"/><path d="M6.3 20.3a2.4 2.4 0 0 0 3.4 0L12 18l-6-6-2.3 2.3a2.4 2.4 0 0 0 0 3.4Z"/><path d="M7.5 13.5 10 11"/><path d="M10.5 16.5 13 14"/><path d="m12 6 6 6 2.3-2.3a2.4 2.4 0 0 0 0-3.4l-2.6-2.6a2.4 2.4 0 0 0-3.4 0Z"/>',
        'compass' => '<circle cx="12" cy="12" r="10"/><path d="m16.24 7.76-1.804 5.411a2 2 0 0 1-1.265 1.265L7.76 16.24l1.804-5.411a2 2 0 0 1 1.265-1.265z"/>',
        'grid' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/>',
        'columns' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/>',
    ];
    $inner = $paths[$name] ?? $paths['help'];
    return '<svg class="icon ' . e($class) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

const EVENT_ICONS = [
    'motion' => ['activity', 'tone-amber'],
    'fire' => ['flame', 'tone-red'],
    'panic' => ['siren', 'tone-solid-red'],
    'access' => ['lock', 'tone-blue'],
    'tamper' => ['eye-off', 'tone-violet'],
    'person' => ['user', 'tone-teal'],
    'other' => ['help', 'tone-slate'],
];

function event_icon(string $type, int $size = 16): string {
    [$name, $tone] = EVENT_ICONS[$type] ?? EVENT_ICONS['other'];
    return '<span class="icon-box ' . $tone . '">' . icon($name, $size) . '</span>';
}

function status_pill(string $status): string {
    $tones = ['new' => 'red', 'reviewing' => 'amber', 'resolved' => 'green', 'false_alarm' => 'slate'];
    return pill(EVENT_STATUS_LABELS[$status] ?? $status, $tones[$status] ?? 'slate');
}

function severity_pill(string $severity): string {
    return '<span class="severity severity-' . e($severity) . '">' . e($severity) . '</span>';
}

function pill(string $text, string $tone = 'slate'): string {
    return '<span class="pill pill-' . e($tone) . '">' . e($text) . '</span>';
}

function empty_state(string $iconName, string $title, string $description = '', string $actionHtml = ''): string {
    return '<div class="empty"><div class="empty-icon">' . icon($iconName, 22) . '</div><p class="empty-title">' . e($title) . '</p>'
        . ($description ? '<p class="empty-text">' . e($description) . '</p>' : '')
        . ($actionHtml ? '<div class="empty-action">' . $actionHtml . '</div>' : '') . '</div>';
}

function pagination(int $total, int $limit, int $page): string {
    if ($total <= $limit) return '';
    $pages = (int) ceil($total / $limit);
    $from = ($page - 1) * $limit + 1;
    $to = min($total, $page * $limit);
    $prev = $page > 1 ? '<a class="icon-btn" href="' . e(url_with(['page' => $page - 1])) . '" aria-label="Previous page">' . icon('chevron-left', 14) . '</a>' : '<span class="icon-btn disabled">' . icon('chevron-left', 14) . '</span>';
    $next = $page < $pages ? '<a class="icon-btn" href="' . e(url_with(['page' => $page + 1])) . '" aria-label="Next page">' . icon('chevron-right', 14) . '</a>' : '<span class="icon-btn disabled">' . icon('chevron-right', 14) . '</span>';
    return "<div class=\"pagination\"><span>{$from}–{$to} of {$total}</span><div class=\"pagination-controls\">{$prev}<span>{$page} / {$pages}</span>{$next}</div></div>";
}

function selected(mixed $a, mixed $b): string {
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(mixed $value): string {
    return $value ? ' checked' : '';
}

/** Common <head>; used by the app shell and the sign-in / error screens. */
function render_head(string $title): void {
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($title) ?> · CCTV Media Management</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<?php
}

function render_header(PDO $db, array $user, string $title): void {
    $page = basename($_SERVER['SCRIPT_NAME']);
    $settings = settings($db);
    $openEvents = can($user, 'review_events') ? open_event_count($db) : 0;
    $unread = (int) scalar($db, 'SELECT COUNT(*) FROM alerts WHERE recipient_user_id = ? AND read_at IS NULL', [$user['id']]);
    $siren = siren_active($db);
    render_head($title);
    ?>
<body data-can-review="<?= can($user, 'review_events') ? '1' : '0' ?>" data-role="<?= e($user['role']) ?>">
<div class="sidebar-backdrop" data-sidebar-close></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <a href="index.php" class="brand">
      <span class="brand-mark"><?= icon('shield', 19) ?></span>
      <span><strong>Sentinel</strong><small>CCTV CONTROL</small></span>
    </a>
    <button class="sidebar-close" data-sidebar-close aria-label="Close menu"><?= icon('x', 19) ?></button>
  </div>
  <nav class="nav">
    <?php foreach (['monitor' => 'MONITORING', 'manage' => 'ADMINISTRATION'] as $section => $heading):
        $items = array_filter(NAV, fn ($item) => $item['section'] === $section && in_array($user['role'], $item['roles'], true));
        if (!$items) continue; ?>
      <p class="nav-heading"><?= $heading ?></p>
      <?php foreach ($items as $file => $item): ?>
        <a href="<?= $file ?>" class="nav-link<?= $page === $file ? ' active' : '' ?>"<?= $page === $file ? ' aria-current="page"' : '' ?>>
          <?= icon($item['icon'], 17) ?>
          <span><?= e($item['label']) ?></span>
          <?php if ($file === 'events.php'): ?>
            <span class="nav-badge" data-open-events<?= $openEvents ? '' : ' hidden' ?>><?= $openEvents > 99 ? '99+' : $openEvents ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <a href="cameras.php" class="station-card" data-station-card>
    <span class="station-card-icon"><?= icon('monitor', 16) ?></span>
    <span><strong data-station-title>Viewer device</strong><small data-station-text>Station runs on the Live cameras page</small></span>
  </a>
</aside>

<main class="main">
  <header class="topbar">
    <div class="siren-banner" data-siren-banner<?= $siren ? '' : ' hidden' ?>>
      <span><?= icon('siren', 16) ?> Panic alarm active — siren is sounding at the monitoring station.</span>
      <?php if (can($user, 'reset_siren')): ?>
        <button type="button" class="siren-reset" data-siren-reset><?= icon('volume-x', 14) ?> Silence siren</button>
      <?php endif; ?>
    </div>
    <div class="topbar-row">
      <div class="topbar-title">
        <button class="menu-btn" data-sidebar-open aria-label="Open menu"><?= icon('menu', 19) ?></button>
        <div>
          <h1><?= e($title) ?></h1>
          <p><span class="dot dot-green"></span><?= e($settings['pharmacy_name']) ?></p>
        </div>
      </div>
      <div class="topbar-actions">
        <div class="dropdown" data-alerts>
          <button type="button" class="bell" data-dropdown-toggle aria-label="Alerts">
            <?= icon('bell', 17) ?>
            <span class="bell-count" data-unread<?= $unread ? '' : ' hidden' ?>><?= $unread > 99 ? '99+' : $unread ?></span>
          </button>
          <div class="dropdown-panel alerts-panel" hidden>
            <div class="dropdown-head"><strong>Alerts</strong><button type="button" class="link" data-mark-read>Mark all read</button></div>
            <button type="button" class="notify-enable" data-enable-notifications hidden>Enable desktop / phone notifications for new alerts</button>
            <div class="alerts-list" data-alerts-list><p class="muted center pad">Loading…</p></div>
          </div>
        </div>
        <span class="divider"></span>
        <div class="dropdown">
          <button type="button" class="user-btn" data-dropdown-toggle>
            <span class="avatar"><?= icon('user', 20) ?></span>
            <span class="user-meta"><strong><?= e($user['name']) ?></strong><small><?= e(ROLE_LABELS[$user['role']]) ?></small></span>
            <?= icon('chevron-down', 14, 'user-chevron') ?>
          </button>
          <div class="dropdown-panel menu-panel" hidden>
            <a href="profile.php" class="menu-item"><?= icon('user', 15) ?> My profile</a>
            <form method="post" action="logout.php">
              <?= csrf_field() ?>
              <button class="menu-item danger"><?= icon('log-out', 15) ?> Sign out</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </header>
  <div class="content">
    <?php foreach (take_flashes() as $flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= icon($flash['type'] === 'error' ? 'x-circle' : 'check-circle', 16) ?><span><?= e($flash['message']) ?></span></div>
    <?php endforeach;
}

function render_footer(array $scripts = []): void {
    ?>
  </div>
</main>
<div class="toasts" data-toasts aria-live="polite"></div>
<script src="assets/js/app.js"></script>
<?php foreach ($scripts as $script): ?>
<script src="<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

function page_header(string $title, string $description = '', string $actions = ''): void {
    echo '<section class="page-header"><div><h2>' . e($title) . '</h2>'
        . ($description !== '' ? '<p>' . $description . '</p>' : '')
        . '</div>' . ($actions !== '' ? '<div class="page-actions">' . $actions . '</div>' : '') . '</section>';
}

/** Two taps within 4 seconds: fast during a robbery, but a stray click does not sound the siren. */
function panic_button(string $location = 'Service counter'): string {
    return '<button type="button" class="btn btn-danger" data-panic data-location="' . e($location) . '">' . icon('siren', 16) . '<span>Panic alarm</span></button>';
}

/** Minimal full-screen layout for sign-in and error screens. */
function render_auth_start(string $title): void {
    render_head($title);
    ?>
<body class="auth-body">
<div class="auth-wrap">
  <div class="auth-brand">
    <span class="brand-mark"><?= icon('shield', 20) ?></span>
    <span><strong>Sentinel</strong><small>CCTV MEDIA MANAGEMENT</small></span>
  </div>
<?php
}

function render_auth_end(): void {
    echo "</div>\n</body>\n</html>\n";
}

function render_error_page(string $title, string $message, int $status = 500): never {
    http_response_code($status);
    render_auth_start($title);
    ?>
  <div class="auth-card">
    <span class="icon-box tone-red big"><?= icon('alert', 22) ?></span>
    <h2><?= e($title) ?></h2>
    <p class="muted"><?= e($message) ?></p>
    <a class="btn btn-primary btn-block" href="">Try again</a>
  </div>
<?php
    render_auth_end();
    exit;
}

/**
 * Camera tile. Viewers get frames relayed through the server (assets/js/app.js);
 * on the monitoring station assets/js/station.js swaps in the live webcam stream.
 */
function camera_tile(array $camera, bool $large = false): string {
    $assigned = $camera['device_id'] || $camera['device_label'];
    [$iconName, $title, $detail] = match (true) {
        !$camera['is_enabled'] => ['camera-off', 'Camera disabled', 'Enable it in System settings'],
        !$assigned => ['webcam', 'No webcam assigned', 'Assign a USB or laptop camera in System settings'],
        default => ['camera-off', 'Offline', 'The monitoring station is not streaming this camera'],
    };
    ob_start();
    ?>
<div class="cam-tile<?= $large ? ' cam-large' : '' ?>" data-camera-id="<?= (int) $camera['id'] ?>" data-relay-enabled="<?= $camera['is_enabled'] && $assigned ? '1' : '0' ?>">
  <div class="cam-media"></div>
  <div class="cam-placeholder">
    <span data-placeholder-icon><?= icon($iconName, 30) ?></span>
    <strong data-placeholder-title><?= e($title) ?></strong>
    <small data-placeholder-detail><?= e($detail) ?></small>
  </div>
  <div class="cam-shade"></div>
  <div class="cam-top">
    <span class="cam-badges">
      <span class="cam-status" data-status>OFFLINE</span>
      <span class="cam-rec" data-rec hidden>REC</span>
      <span class="cam-armed" data-armed title="Motion detection armed" hidden><?= icon('shield-check', 12) ?></span>
    </span>
    <span class="cam-clock" data-clock></span>
  </div>
  <div class="cam-bottom">
    <div class="cam-labels">
      <p class="cam-name"><?= e($camera['name']) ?></p>
      <p class="cam-location"><?= e($camera['location']) ?></p>
      <div class="cam-motion" data-motion hidden><span class="cam-motion-bar"><span data-motion-bar></span></span><em data-motion-value>0.0%</em></div>
    </div>
    <div class="cam-buttons">
      <button type="button" class="cam-btn" data-record hidden title="Record clip" aria-label="Record clip"><?= icon('record', 15) ?></button>
      <button type="button" class="cam-btn" data-fullscreen title="Full screen" aria-label="Full screen"><?= icon('maximize', 14) ?></button>
    </div>
  </div>
</div>
<?php
    return (string) ob_get_clean();
}
