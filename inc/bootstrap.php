<?php
/**
 * Core bootstrap: config, database, session, helpers.
 * Every entry point (index.php, api.php, login.php …) requires this file.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.4.1');

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    // Not installed yet → send to the installer
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: install.php');
        exit;
    }
    $CONFIG = null;
} else {
    $CONFIG = require $configFile;
}

$debug = (bool)($CONFIG['app']['debug'] ?? false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
date_default_timezone_set($CONFIG['app']['timezone'] ?? 'UTC');

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/* ---------- small helpers ---------- */

function cfg(string $path, $default = null)
{
    global $CONFIG;
    $v = $CONFIG;
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function valid_date($d): bool
{
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

function valid_month($m): bool
{
    return is_string($m) && (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m);
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (cfg('app.force_https') && !is_https() && PHP_SAPI !== 'cli') {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
    session_name('tasc_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ?? '';
}

function security_headers(): void
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

/** Write an entry to the activity log. */
function log_activity(string $action, string $entity = '', ?int $entityId = null, string $summary = ''): void
{
    try {
        db_insert('activity_log', [
            'user_id'   => current_user_id(),
            'action'    => $action,
            'entity'    => $entity,
            'entity_id' => $entityId,
            'summary'   => mb_substr($summary, 0, 255),
            'ip'        => client_ip(),
            'created_at'=> now(),
        ]);
    } catch (Throwable $e) {
        // logging must never break the request
    }
}

function setting(string $key, $default = null)
{
    $row = db_one('SELECT svalue FROM settings WHERE skey = ?', [$key]);
    return $row ? $row['svalue'] : $default;
}

function set_setting(string $key, $value): void
{
    if (db_one('SELECT skey FROM settings WHERE skey = ?', [$key])) {
        db_exec('UPDATE settings SET svalue = ? WHERE skey = ?', [(string)$value, $key]);
    } else {
        db_insert('settings', ['skey' => $key, 'svalue' => (string)$value]);
    }
}
