<?php
declare(strict_types=1);

/**
 * Loaded by every page: config, session, database, helpers.
 */

const APP_ROOT = __DIR__ . '/..';

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing config.php. Copy config.sample.php to config.php and fill in your database details.');
}
/** @var array $CONFIG */
$CONFIG = require $configFile;
date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Kolkata');

session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 30,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_name('rcphp');
session_start();

require_once __DIR__ . '/Bandwidths.php';
require_once __DIR__ . '/Tasks.php';
require_once __DIR__ . '/Sanitizer.php';
require_once __DIR__ . '/Claude.php';

function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $c = $CONFIG['db'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $offset = (new DateTime())->format('P');
        $pdo->exec("SET time_zone = '$offset'");
    }
    return $pdo;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_user_id(): ?int
{
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
}

function require_login_page(): int
{
    $uid = current_user_id();
    if ($uid === null) {
        header('Location: login.php');
        exit;
    }
    return $uid;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(?string $token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Sign-up is open when config allows it, or when no account exists yet (first run). */
function registration_open(): bool
{
    global $CONFIG;
    if (!empty($CONFIG['allow_register'])) return true;
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
}

/* ---------- environment (production vs development) ---------- */
function app_env(): string
{
    global $CONFIG;
    return ($CONFIG['env'] ?? 'local') === 'production' ? 'production' : 'dev';
}

function is_production(): bool
{
    return app_env() === 'production';
}

/** "[DEV] " in development, "" in production. */
function env_title_prefix(): string
{
    return is_production() ? '' : '[DEV] ';
}

/** Browser/phone top-bar colour: blue on production, orange on development. */
function env_theme_color(): string
{
    return is_production() ? '#1F5F8B' : '#C2410C';
}

/** Orange warning strip shown only in development. */
function env_banner(): string
{
    if (is_production()) return '';
    global $CONFIG;
    $db = (string)($CONFIG['db']['name'] ?? '');
    $host = (string)(gethostname() ?: '');
    return '<div class="envbar" role="note"><b>DEVELOPMENT</b><span>Test copy · database <code>' . h($db) . '</code> on <code>' . h($host) . '</code> · changes here do not affect the live app</span></div>';
}

/** Small badge next to the app name. */
function env_badge(): string
{
    return is_production() ? '<span class="envtag live">LIVE</span>' : '<span class="envtag dev">DEV</span>';
}
