<?php
declare(strict_types=1);

const ROLE_OWNER = 'owner';
const ROLE_ADMIN = 'administrator';
const ROLE_GUARD = 'guard';
const ROLE_STAFF = 'staff';
const ALL_ROLES = [ROLE_OWNER, ROLE_ADMIN, ROLE_GUARD, ROLE_STAFF];
const MONITOR_ROLES = [ROLE_OWNER, ROLE_ADMIN, ROLE_GUARD];
const MANAGER_ROLES = [ROLE_OWNER, ROLE_ADMIN];

const ROLE_LABELS = [
    ROLE_OWNER => 'Owner',
    ROLE_ADMIN => 'Administrator',
    ROLE_GUARD => 'Guard',
    ROLE_STAFF => 'Staff',
];

/** A camera counts as online when the monitoring station reported it within this window. */
const CAMERA_ONLINE_SECONDS = 90;

final class HttpError extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 400) {
        parent::__construct($message);
    }
}

function fail(string $message, int $status = 400): never {
    throw new HttpError($message, $status);
}

// ---------------------------------------------------------------------------
// Request input
// ---------------------------------------------------------------------------

function is_post(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/** POST body: JSON for fetch() calls from the browser scripts, otherwise the form fields. */
function body(): array {
    static $input = null;
    if ($input !== null) return $input;
    if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return $input = is_array($decoded) ? $decoded : [];
    }
    return $input = $_POST;
}

function input_string(string $key, int $max = 255): string {
    return mb_substr(trim((string) (body()[$key] ?? '')), 0, $max);
}

function input_int(string $key): ?int {
    $value = filter_var(body()[$key] ?? null, FILTER_VALIDATE_INT);
    return $value === false ? null : $value;
}

function input_bool(string $key): bool {
    return filter_var(body()[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
}

function query_string(string $key, int $max = 100): string {
    return mb_substr(trim((string) ($_GET[$key] ?? '')), 0, $max);
}

function query_int(string $key, ?int $default = null): ?int {
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);
    return $value === false ? $default : $value;
}

function query_date(string $key): ?string {
    $value = $_GET[$key] ?? '';
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function paging(int $limit = 25): array {
    $page = max(1, query_int('page', 1));
    return [$limit, ($page - 1) * $limit, $page];
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

function database(array $config): PDO {
    $db = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]
    );
    $db->exec("SET time_zone = '" . date('P') . "'");
    return $db;
}

function scalar(PDO $db, string $sql, array $params = []): mixed {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

function rows(PDO $db, string $sql, array $params = []): array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function row(PDO $db, string $sql, array $params = []): ?array {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement->fetch() ?: null;
}

// ---------------------------------------------------------------------------
// Sessions, auth, CSRF, flash messages
// ---------------------------------------------------------------------------

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Re-reads the signed-in user on every request so a deactivated account or a
 * changed role takes effect immediately. Returns null when not signed in.
 */
function load_user(PDO $db): ?array {
    $session = current_user();
    if (!$session) return null;
    $row = row($db, 'SELECT id, full_name, email, role, phone, is_active FROM users WHERE id = ?', [$session['id']]);
    if (!$row || !$row['is_active']) {
        $_SESSION = [];
        return null;
    }
    return $_SESSION['user'] = [
        'id' => (int) $row['id'],
        'name' => $row['full_name'],
        'email' => $row['email'],
        'role' => $row['role'],
        'phone' => $row['phone'],
    ];
}

function sign_in(array $userRow): void {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int) $userRow['id'],
        'name' => $userRow['full_name'],
        'email' => $userRow['email'],
        'role' => $userRow['role'],
        'phone' => $userRow['phone'] ?? null,
    ];
}

function has_role(?array $user, array $roles): bool {
    return $user !== null && in_array($user['role'], $roles, true);
}

function csrf_token(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function verify_csrf(): void {
    $sent = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrf_token(), $sent)) fail('Your session expired. Please try again.', 419);
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array {
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Previous form input, restored after a validation error redirect. */
function old(string $key, mixed $default = ''): mixed {
    return $GLOBALS['__old_input'][$key] ?? $default;
}

function redirect(string $to): never {
    header('Location: ' . $to);
    exit;
}

/**
 * Standard form handling: CSRF check, run the action, flash the outcome and
 * redirect (post/redirect/get). On error the submitted fields are kept for old().
 */
function handle_post(callable $action, string $redirectTo): never {
    try {
        verify_csrf();
        $result = $action();
        if (is_string($result) && $result !== '') flash('success', $result);
        if (is_array($result) && isset($result['redirect'])) {
            if (!empty($result['message'])) flash('success', $result['message']);
            redirect($result['redirect']);
        }
    } catch (HttpError $error) {
        flash('error', $error->getMessage());
        $_SESSION['old_input'] = array_diff_key($_POST, array_flip(['_csrf', 'password', 'current_password', 'new_password', 'confirm_password']));
    }
    redirect($redirectTo);
}

// ---------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------

function e(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $status < 400] + $data, JSON_UNESCAPED_SLASHES);
    exit;
}

/** Builds a URL for the current page with some query parameters replaced. */
function url_with(array $changes, ?string $page = null): string {
    $query = array_merge($_GET, $changes);
    $query = array_filter($query, fn ($value) => $value !== null && $value !== '');
    $base = $page ?? basename($_SERVER['SCRIPT_NAME']);
    return $base . ($query ? '?' . http_build_query($query) : '');
}

function to_time(?string $value): ?int {
    if (!$value) return null;
    $time = strtotime($value);
    return $time === false ? null : $time;
}

function fmt_datetime(?string $value): string {
    $time = to_time($value);
    return $time ? date('M j, g:i A', $time) : '—';
}

function fmt_date(?string $value): string {
    $time = to_time($value);
    return $time ? date('M j, Y', $time) : '—';
}

function time_ago(?string $value): string {
    $time = to_time($value);
    if (!$time) return 'never';
    $seconds = time() - $time;
    if ($seconds < 45) return 'just now';
    if ($seconds < 3600) return round($seconds / 60) . ' min ago';
    if ($seconds < 86400) return round($seconds / 3600) . ' h ago';
    return fmt_datetime($value);
}

function fmt_bytes(int|float|null $bytes): string {
    if (!$bytes) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = min(count($units) - 1, (int) floor(log($bytes, 1024)));
    return number_format($bytes / 1024 ** $index, $index > 1 ? 1 : 0) . ' ' . $units[$index];
}

function fmt_duration(?string $start, ?string $end): string {
    $a = to_time($start);
    $b = to_time($end);
    if (!$a || !$b) return '—';
    $seconds = max(0, $b - $a);
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function greeting(): string {
    $hour = (int) date('G');
    return $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
}

function plural(int $count, string $word): string {
    return $count . ' ' . $word . ($count === 1 ? '' : 's');
}

// ---------------------------------------------------------------------------
// File storage
// ---------------------------------------------------------------------------

/** Recordings live in storage/, which is never served directly; a deny-all .htaccess is kept there. */
function storage_path(array $config, string $sub = ''): string {
    $root = rtrim($config['storage_path'], '/\\');
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to create storage directory');
    }
    if (!file_exists($root . '/.htaccess')) {
        file_put_contents($root . '/.htaccess', "Require all denied\n");
    }
    $path = $sub === '' ? $root : $root . '/' . $sub;
    if ($sub !== '' && !is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create storage directory');
    }
    return $path;
}

function upload_limit_bytes(): int {
    $parse = static function (string $value): int {
        $value = trim($value);
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    };
    return min($parse((string) ini_get('upload_max_filesize')), $parse((string) ini_get('post_max_size')));
}

function uploaded_file(string $field, array $allowedMimes): array {
    $file = $_FILES[$field] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $tooLarge = $file && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        fail($tooLarge ? 'File exceeds the server upload limit' : "A valid {$field} file is required", $tooLarge ? 413 : 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    // MediaRecorder output is sometimes sniffed as Matroska or raw bytes; accept it only with the EBML signature.
    if ($mime === 'video/x-matroska' || $mime === 'application/octet-stream') {
        if (file_get_contents($file['tmp_name'], false, null, 0, 4) === "\x1A\x45\xDF\xA3") $mime = 'video/webm';
    }
    if (!isset($allowedMimes[$mime])) fail('Unsupported file type', 415);
    return ['tmp' => $file['tmp_name'], 'mime' => $mime, 'ext' => $allowedMimes[$mime], 'size' => (int) $file['size']];
}

function store_upload(array $config, array $file, string $folder): string {
    $relative = $folder . '/' . date('Y/m');
    $directory = storage_path($config, $relative);
    $name = bin2hex(random_bytes(16)) . '.' . $file['ext'];
    if (!move_uploaded_file($file['tmp'], $directory . '/' . $name)) {
        throw new RuntimeException('Unable to store upload');
    }
    return $relative . '/' . $name;
}

function absolute_storage_file(array $config, ?string $relative): ?string {
    if (!$relative || str_contains($relative, '..')) return null;
    $path = rtrim($config['storage_path'], '/\\') . '/' . $relative;
    return is_file($path) ? $path : null;
}

/** Streams a stored file with HTTP Range support so browsers can seek inside video. */
function stream_file(string $path, string $mime, ?string $downloadName = null): never {
    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    if ($downloadName) header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $match)) {
        if ($match[1] === '' && $match[2] !== '') {
            $start = max(0, $size - (int) $match[2]);
        } else {
            $start = (int) $match[1];
            if ($match[2] !== '') $end = min($end, (int) $match[2]);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */{$size}");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $handle = fopen($path, 'rb');
    fseek($handle, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8192, $remaining));
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($handle);
    exit;
}
