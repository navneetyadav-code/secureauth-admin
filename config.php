<?php
declare(strict_types=1);

const APP_ROOT = __DIR__;
const STORAGE_DIR = APP_ROOT . DIRECTORY_SEPARATOR . 'storage';
const LOG_DIR = STORAGE_DIR . DIRECTORY_SEPARATOR . 'logs';
const SESSION_DIR = STORAGE_DIR . DIRECTORY_SEPARATOR . 'sessions';
const APP_LOG_FILE = LOG_DIR . DIRECTORY_SEPARATOR . 'app.log';

function load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);

        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }

        $value = trim($value);
        $quote = $value[0] ?? '';
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);
        }

        if (!array_key_exists($key, $_ENV) && getenv($key) === false) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

function env_value(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }

    if (array_key_exists($key, $_SERVER)) {
        return (string) $_SERVER[$key];
    }

    $value = getenv($key);
    if ($value !== false) {
        return (string) $value;
    }

    return $default;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = env_value($key);
    if ($value === null || $value === '') {
        return $default;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function env_int(string $key, int $default, int $minimum = 0): int
{
    $value = env_value($key);
    if ($value === null || $value === '' || !is_numeric($value)) {
        return $default;
    }

    return max($minimum, (int) $value);
}

function ensure_log_directory(): void
{
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0750, true);
    }
}

function ensure_directory(string $path, int $mode = 0750): bool
{
    return is_dir($path) || @mkdir($path, $mode, true);
}

function scrub_log_context(array $context): array
{
    $scrubbed = [];

    foreach ($context as $key => $value) {
        $safeKey = (string) $key;
        if (preg_match('/pass|secret|token|otp|csrf|salt|key/i', $safeKey)) {
            $scrubbed[$safeKey] = '[redacted]';
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $scrubbed[$safeKey] = $value;
        } else {
            $scrubbed[$safeKey] = '[non-scalar]';
        }
    }

    return $scrubbed;
}

function safe_log(string $message, array $context = []): void
{
    ensure_log_directory();

    $entry = [
        'time' => date(DATE_ATOM),
        'message' => $message,
        'context' => scrub_log_context($context),
    ];

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = date(DATE_ATOM) . ' ' . $message;
    }

    if (@file_put_contents(APP_LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        error_log($line);
    }
}

function render_error_page(string $message): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Service Unavailable</title>';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#111827;color:#f9fafb;display:grid;min-height:100vh;place-items:center;margin:0}main{max-width:34rem;padding:2rem}p{color:#d1d5db}</style>';
    echo '</head><body><main><h1>Service unavailable</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></main></body></html>';
}

function app_fail(string $publicMessage, int $statusCode = 500): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $publicMessage . PHP_EOL);
        exit(1);
    }

    http_response_code($statusCode);
    render_error_page($publicMessage);
    exit;
}

load_env_file(APP_ROOT . DIRECTORY_SEPARATOR . '.env');
date_default_timezone_set(env_value('APP_TIMEZONE', 'Asia/Kolkata') ?: 'Asia/Kolkata');
ensure_log_directory();

$appEnv = env_value('APP_ENV', 'production') ?: 'production';
$appDebug = env_bool('APP_DEBUG', false);
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e): void {
    safe_log('Unhandled exception', [
        'class' => $e::class,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    app_fail('A server error occurred. Please try again later.', 500);
});

$requiredConfig = ['APP_KEY', 'APP_URL', 'DB_HOST', 'DB_NAME', 'DB_USER'];
$missingConfig = [];

foreach ($requiredConfig as $key) {
    $value = env_value($key);
    if ($value === null || trim($value) === '') {
        $missingConfig[] = $key;
    }
}

$appKey = env_value('APP_KEY', '') ?? '';
if (strlen($appKey) < 32) {
    $missingConfig[] = 'APP_KEY_MIN_32_CHARS';
}

if ($missingConfig !== []) {
    safe_log('Application configuration is incomplete', ['missing' => implode(',', array_unique($missingConfig))]);
    app_fail('Application configuration is incomplete. Please check the server configuration.', 503);
}

$GLOBALS['app_config'] = [
    'app_name' => env_value('APP_NAME', 'SecureAuth Admin') ?: 'SecureAuth Admin',
    'app_env' => $appEnv,
    'app_debug' => $appDebug,
    'app_key' => $appKey,
    'app_url' => rtrim(env_value('APP_URL', 'http://localhost/secure-login') ?: 'http://localhost/secure-login', '/'),
    'force_https' => env_bool('APP_FORCE_HTTPS', false),
    'session_name' => env_value('SESSION_NAME', 'secure_login_sid') ?: 'secure_login_sid',
    'session_cookie_path' => env_value('SESSION_COOKIE_PATH', '/') ?: '/',
    'session_save_path' => env_value('SESSION_SAVE_PATH', SESSION_DIR) ?: SESSION_DIR,
    'session_idle_seconds' => env_int('SESSION_IDLE_SECONDS', 900, 60),
    'session_absolute_seconds' => env_int('SESSION_ABSOLUTE_SECONDS', 28800, 300),
    'login_max_attempts' => env_int('LOGIN_MAX_ATTEMPTS', 5, 1),
    'login_lockout_seconds' => env_int('LOGIN_LOCKOUT_SECONDS', 900, 60),
    'otp_expiry_seconds' => env_int('OTP_EXPIRY_SECONDS', 300, 60),
    'otp_max_attempts' => env_int('OTP_MAX_ATTEMPTS', 5, 1),
];

function app_config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['app_config'][$key] ?? $default;
}

define('DB_HOST', env_value('DB_HOST', 'localhost') ?: 'localhost');
define('DB_PORT', env_int('DB_PORT', 3306, 1));
define('DB_NAME', env_value('DB_NAME', '') ?: '');
define('DB_USER', env_value('DB_USER', '') ?: '');
define('DB_PASS', env_value('DB_PASS', '') ?? '');
define('SESSION_IDLE_LIMIT', (int) app_config('session_idle_seconds'));
define('SESSION_ABSOLUTE_LIMIT', (int) app_config('session_absolute_seconds'));
define('MAX_LOGIN_ATTEMPTS', (int) app_config('login_max_attempts'));
define('LOCKOUT_TIME', (int) app_config('login_lockout_seconds'));
define('OTP_EXPIRY_SECONDS', (int) app_config('otp_expiry_seconds'));
define('MAX_OTP_ATTEMPTS', (int) app_config('otp_max_attempts'));

function is_https_request(): bool
{
    if ((bool) app_config('force_https', false)) {
        return true;
    }

    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }

    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function send_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }

    $csp = [
        "default-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "object-src 'none'",
        "img-src 'self' data:",
        "style-src 'self'",
        "script-src 'self'",
        "font-src 'self'",
        "connect-src 'self'",
    ];

    if (is_https_request()) {
        $csp[] = 'upgrade-insecure-requests';
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains', true);
    }

    header('X-Frame-Options: DENY', true);
    header('X-Content-Type-Options: nosniff', true);
    header('Referrer-Policy: strict-origin-when-cross-origin', true);
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()', true);
    header('Content-Security-Policy: ' . implode('; ', $csp), true);
    header('Cache-Control: no-store, max-age=0', true);
    header('Pragma: no-cache', true);
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');

    $sessionName = preg_replace('/[^A-Za-z0-9_,-]/', '', (string) app_config('session_name', 'secure_login_sid'));
    session_name($sessionName !== '' ? $sessionName : 'secure_login_sid');

    $sessionSavePath = (string) app_config('session_save_path', SESSION_DIR);
    if (!ensure_directory($sessionSavePath, 0750) || !is_writable($sessionSavePath)) {
        safe_log('Session storage is not writable', ['path' => $sessionSavePath]);
        app_fail('The authentication service is temporarily unavailable.', 503);
    }
    session_save_path($sessionSavePath);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (string) app_config('session_cookie_path', '/'),
        'domain' => '',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

function destroy_current_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }

    session_destroy();
}

send_security_headers();
start_secure_session();

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->prepare('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci')->execute();
} catch (Throwable $e) {
    safe_log('Database connection failed', ['message' => $e->getMessage()]);
    app_fail('The authentication service is temporarily unavailable.', 503);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function get_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }

    return '0.0.0.0';
}

function now_sql(): string
{
    return date('Y-m-d H:i:s');
}

function keyed_hash(string $value, int $length = 16): string
{
    return substr(hash_hmac('sha256', strtolower($value), (string) app_config('app_key')), 0, $length);
}

function audit_action(string $event, array $context = []): string
{
    $parts = [$event];

    foreach (scrub_log_context($context) as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $safeValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $parts[] = $key . '=' . preg_replace('/[^A-Za-z0-9_.:@-]/', '_', $safeValue);
    }

    return substr(implode(' ', $parts), 0, 255);
}

function admin_log(PDO $pdo, ?int $adminId, string $event, array $context = []): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO admin_logs (admin_id, action, ip, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $adminId !== null && $adminId > 0 ? $adminId : 0,
            audit_action($event, $context),
            get_client_ip(),
            now_sql(),
        ]);
    } catch (Throwable $e) {
        safe_log('Audit log write failed', ['event' => $event, 'message' => $e->getMessage()]);
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_validate(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf'])
        && is_string($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

function csrf_rotate(): string
{
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function session_fingerprint(): string
{
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 512);
    return hash_hmac('sha256', $userAgent, (string) app_config('app_key'));
}

function redirect_to(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function redirect_to_login(): never
{
    redirect_to('index.php');
}

function fetch_admin_by_id(PDO $pdo, int $adminId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();

    return is_array($admin) ? $admin : null;
}

function admin_is_active(array $admin): bool
{
    if (array_key_exists('is_active', $admin)) {
        return (int) $admin['is_active'] === 1;
    }

    if (array_key_exists('status', $admin)) {
        return strtolower((string) $admin['status']) === 'active';
    }

    return true;
}

function require_admin(PDO $pdo): array
{
    if (empty($_SESSION['admin_id'])) {
        redirect_to_login();
    }

    $adminId = (int) $_SESSION['admin_id'];
    $now = time();

    if (!empty($_SESSION['created_at']) && ($now - (int) $_SESSION['created_at']) > SESSION_ABSOLUTE_LIMIT) {
        admin_log($pdo, $adminId, 'session_expired', ['reason' => 'absolute_timeout']);
        destroy_current_session();
        redirect_to_login();
    }

    if (!empty($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > SESSION_IDLE_LIMIT) {
        admin_log($pdo, $adminId, 'session_expired', ['reason' => 'idle_timeout']);
        destroy_current_session();
        redirect_to_login();
    }

    if (empty($_SESSION['fingerprint']) || !hash_equals((string) $_SESSION['fingerprint'], session_fingerprint())) {
        admin_log($pdo, $adminId, 'session_rejected', ['reason' => 'fingerprint_mismatch']);
        destroy_current_session();
        redirect_to_login();
    }

    $_SESSION['last_activity'] = $now;
    csrf_token();

    $admin = fetch_admin_by_id($pdo, $adminId);
    if ($admin === null || !admin_is_active($admin)) {
        if ($admin !== null) {
            admin_log($pdo, $adminId, 'session_rejected', ['reason' => 'admin_inactive']);
        }

        destroy_current_session();
        redirect_to_login();
    }

    return $admin;
}

function complete_admin_login(PDO $pdo, array $admin, string $event): void
{
    session_regenerate_id(true);
    csrf_rotate();

    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_email'] = (string) $admin['email'];
    $_SESSION['created_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['fingerprint'] = session_fingerprint();

    try {
        $stmt = $pdo->prepare('UPDATE admins SET otp = NULL, otp_expiry = NULL, last_login_ip = ?, last_login_at = ? WHERE id = ?');
        $stmt->execute([get_client_ip(), now_sql(), (int) $admin['id']]);
    } catch (Throwable $e) {
        safe_log('Could not update admin login metadata', ['admin_id' => (int) $admin['id'], 'message' => $e->getMessage()]);
    }

    admin_log($pdo, (int) $admin['id'], $event);
}

function flash_message(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;
        return null;
    }

    $message = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);

    return is_string($message) ? $message : null;
}
