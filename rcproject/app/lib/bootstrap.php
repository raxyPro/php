<?php
// Shared setup: config, session, database, auth and small helpers.
declare(strict_types=1);

$cfgFile = __DIR__ . '/../config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('ProjectDesk: config.php not found. Copy config.sample.php to config.php and set your MySQL details.');
}
$CFG = require $cfgFile;
date_default_timezone_set($CFG['timezone'] ?? 'Asia/Kolkata');

session_name('projectdesk');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

const STATUSES = ['Not Started', 'In Progress', 'On Hold', 'Completed'];

function db(): PDO
{
    static $pdo = null;
    global $CFG;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $CFG['db_host'], (int)$CFG['db_port'], $CFG['db_name']);
        $pdo = new PDO($dsn, $CFG['db_user'], $CFG['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

/* ---- CSRF ---- */
function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function csrf_check(): void
{
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Your session expired. Go back and reload the page.');
    }
}

/* ---- Auth ---- */
function me(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $row = q('SELECT id, name, email, role, active FROM pd_users WHERE id = ?', [$_SESSION['uid']])->fetch();
            $u = ($row && (int)$row['active'] === 1) ? $row : null;
        }
    }
    return $u;
}
function is_admin(): bool { $u = me(); return $u !== null && $u['role'] === 'Admin'; }
function require_login(): array
{
    $u = me();
    if (!$u) { header('Location: login.php'); exit; }
    return $u;
}
function require_admin(): void
{
    if (!is_admin()) { http_response_code(403); exit('Admins only.'); }
}

/* ---- misc ---- */
function redirect(string $url): void { header('Location: ' . $url); exit; }
function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
// Only allow redirects back to our own pages.
function safe_back($b): string
{
    $b = (string)$b;
    return preg_match('~^(index|users|account)\.php(\?[^\s<>"\'\\\\]*)?$~', $b) ? $b : 'index.php';
}
function current_url(): string
{
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    return basename($_SERVER['SCRIPT_NAME']) . ($qs !== '' ? '?' . $qs : '');
}
function fdate(?string $d): string { return $d ? date('d M y', strtotime($d)) : ''; }
function valid_date($d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', (string)$d);
    return $x && $x->format('Y-m-d') === $d;
}
function scls(string $s): string
{
    return ['Not Started' => 'ns', 'In Progress' => 'ip', 'On Hold' => 'oh', 'Completed' => 'c'][$s] ?? 'ns';
}
function today(): string { return date('Y-m-d'); }
function is_late(array $t): bool { return $t['status'] !== 'Completed' && $t['target_date'] < today(); }
function stats(array $list): array
{
    $done = 0; $late = 0;
    foreach ($list as $t) {
        if ($t['status'] === 'Completed') $done++;
        elseif ($t['target_date'] < today()) $late++;
    }
    $n = count($list);
    return ['total' => $n, 'done' => $done, 'due' => $n - $done, 'late' => $late,
            'pct' => $n ? (int)round($done * 100 / $n) : 0];
}
function js($v): string
{
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}
