<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/messages.php';
require_once __DIR__ . '/transport.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/extract.php';
require_once __DIR__ . '/mapping.php';
require_once __DIR__ . '/deployment.php';
require_once __DIR__ . '/bulk.php';
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$setup_error = '';
$config_path = dirname(__DIR__) . '/config.php';
$config = is_file($config_path) ? require $config_path : [];
foreach (['curl', 'dom', 'mbstring', 'json', 'session'] as $extension) if (!extension_loaded($extension)) $setup_error .= 'Missing PHP extension: ' . $extension . '. ';
if (PHP_VERSION_ID < 70400 || PHP_INT_SIZE < 8) $setup_error .= 'PHP 7.4 or later (64-bit) is required. ';
if (!$config || empty($config['admin_password_hash']) || strpos($config['admin_password_hash'], '$') !== 0) $setup_error .= 'Configuration missing. Run setup.php or configure config.example.php as described in the instructions. ';
if (!$setup_error) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!$secure && !($config['allow_http_localhost'] && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true))) $setup_error = 'The dashboard requires HTTPS. When using a reverse proxy, the web server must report HTTPS correctly to PHP.';
    if ($secure) header('Strict-Transport-Security: max-age=31536000');
    if (!is_dir($config['data_dir']) && !mkdir($config['data_dir'], 0700, true)) $setup_error = 'Could not create the data directory.';
    $data_path = realpath($config['data_dir']); $web_root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $data_normalized = normalized_path((string) $data_path);
    $root_normalized = rtrim(normalized_path((string) $web_root), '/');
    $config_normalized = normalized_path((string) realpath($config_path));
    if (!$data_path || !is_writable($data_path)) $setup_error = 'The data directory is not writable.';
    if ($web_root && ($data_normalized === $root_normalized || strpos($data_normalized . '/', $root_normalized . '/') === 0 || strpos($config_normalized, $root_normalized . '/') === 0)) $setup_error = 'config.php and the data directory must be outside the public document root. Only public/ may be publicly accessible.';
    if (!$setup_error) {
        if (!is_dir($config['data_dir'] . '/sessions')) mkdir($config['data_dir'] . '/sessions', 0700);
        session_save_path($config['data_dir'] . '/sessions');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '3600');
        session_name('standard_stuffer');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
        if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 3600) { $_SESSION = []; session_regenerate_id(true); }
        $_SESSION['last_seen'] = time();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
function check_csrf(): void {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) throw new RuntimeException('Security check failed. Reload the page.');
}
function login_admin(string $password): void {
    global $config;
    $lock = storage_lock('login');
    try {
        $path = $config['data_dir'] . '/login.json'; $attempts = read_json($path, []); $now = time();
        $attempts = array_filter($attempts, static function ($entry) use ($now) { return $entry['since'] > $now - 900; });
        $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $entry = $attempts[$key] ?? ['since' => $now, 'count' => 0];
        if ($entry['count'] >= 10) throw new RuntimeException('Too many login attempts. Wait up to 15 minutes.');
        if (!password_verify($password, $config['admin_password_hash'])) {
            $entry['count']++; $attempts[$key] = $entry; atomic_json($path, $attempts);
            throw new RuntimeException('Incorrect dashboard password.');
        }
        unset($attempts[$key]); atomic_json($path, $attempts);
        session_regenerate_id(true); $_SESSION['admin'] = true; $_SESSION['csrf'] = bin2hex(random_bytes(32));
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
