<?php
declare(strict_types=1);

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'app.config';
$appCfg = is_file($configPath) ? (parse_ini_file($configPath, false, INI_SCANNER_RAW) ?: []) : [];

$imapValidateRaw = strtolower((string)($appCfg['IMAP_VALIDATE_CERT'] ?? 'true'));
$imapValidate = !in_array($imapValidateRaw, ['0', 'false', 'no', 'off'], true);

$cfg = [
    'username' => (string)($appCfg['MAIL_USERNAME'] ?? ''),
    'password' => (string)($appCfg['MAIL_PASSWORD'] ?? ''),
    'from_name' => (string)($appCfg['MAIL_FROM_NAME'] ?? ''),
    'cache_dir' => (string)($appCfg['MAIL_CACHE_DIR'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mail_cache')),
    'list_days_default' => (int)($appCfg['MAIL_LIST_DAYS_DEFAULT'] ?? 30),
    'list_uid_ttl' => (int)($appCfg['MAIL_LIST_UID_TTL'] ?? 120),
    'imap' => [
        'host' => (string)($appCfg['IMAP_HOST'] ?? 'rcpro.in'),
        'port' => (int)($appCfg['IMAP_PORT'] ?? 993),
        'encryption' => (string)($appCfg['IMAP_ENCRYPTION'] ?? 'ssl'),
        'validate_cert' => $imapValidate,
        'default_folder' => (string)($appCfg['IMAP_DEFAULT_FOLDER'] ?? 'INBOX'),
    ],
    'smtp' => [
        'host' => (string)($appCfg['SMTP_HOST'] ?? 'rcpro.in'),
        'port' => (int)($appCfg['SMTP_PORT'] ?? 465),
        'encryption' => (string)($appCfg['SMTP_ENCRYPTION'] ?? 'ssl'),
    ],
];

$debug = ($_GET['debug'] ?? '') === '1';
$timings = [];
$pageStart = microtime(true);

function t_start(array &$timings, string $key): void {
    $timings[$key] = ['start' => microtime(true), 'ms' => 0.0];
}

function t_end(array &$timings, string $key): void {
    if (!isset($timings[$key]['start'])) {
        return;
    }
    $timings[$key]['ms'] = (microtime(true) - $timings[$key]['start']) * 1000.0;
}

function cfg_get(array $cfg, string $path, $default = null) {
    $parts = explode('.', $path);
    $cur = $cfg;
    foreach ($parts as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) {
            return $default;
        }
        $cur = $cur[$p];
    }
    return $cur;
}

function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function account_key(array $cfg): string {
    $user = strtolower((string) cfg_get($cfg, 'username', ''));
    $host = strtolower((string) cfg_get($cfg, 'imap.host', ''));
    $port = (string) cfg_get($cfg, 'imap.port', '');
    return sha1($user . '|' . $host . '|' . $port);
}

function cache_dir(array $cfg): string {
    $dir = (string) cfg_get($cfg, 'cache_dir', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mail_cache');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function cache_path(array $cfg, string $key): string {
    return rtrim(cache_dir($cfg), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $key . '.cache';
}

function cache_get_json(array $cfg, string $key, int $ttl): ?array {
    $path = cache_path($cfg, $key);
    if (!is_file($path)) {
        return null;
    }
    if (filemtime($path) < (time() - $ttl)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function cache_set_json(array $cfg, string $key, array $data): void {
    $path = cache_path($cfg, $key);
    @file_put_contents($path, json_encode($data));
}

function cache_get_text(array $cfg, string $key, int $ttl): ?string {
    $path = cache_path($cfg, $key);
    if (!is_file($path)) {
        return null;
    }
    if (filemtime($path) < (time() - $ttl)) {
        return null;
    }
    $raw = @file_get_contents($path);
    return $raw === false ? null : $raw;
}

function cache_set_text(array $cfg, string $key, string $data): void {
    $path = cache_path($cfg, $key);
    @file_put_contents($path, $data);
}

function imap_base_string(array $cfg): string {
    $host = cfg_get($cfg, 'imap.host');
    $port = (int) cfg_get($cfg, 'imap.port');
    $enc = strtolower((string) cfg_get($cfg, 'imap.encryption', 'ssl'));
    $flags = '/imap';
    if ($enc === 'ssl') {
        $flags .= '/ssl';
    } elseif ($enc === 'tls') {
        $flags .= '/tls';
    }
    $validate = (bool) cfg_get($cfg, 'imap.validate_cert', true);
    if (!$validate) {
        $flags .= '/novalidate-cert';
    }
    return sprintf('{%s:%d%s}', $host, $port, $flags);
}

function imap_mailbox_string(array $cfg, string $folder): string {
    return imap_base_string($cfg) . $folder;
}

function open_imap(array $cfg, string $folder, ?string &$err = null) {
    if (!function_exists('imap_open')) {
        $err = 'PHP IMAP extension is not enabled.';
        return false;
    }
    $mailbox = imap_mailbox_string($cfg, $folder);
    $user = (string) cfg_get($cfg, 'username');
    $pass = (string) cfg_get($cfg, 'password');
    $imap = @imap_open($mailbox, $user, $pass, 0, 1);
    if ($imap === false) {
        $err = imap_last_error() ?: 'Unable to connect to IMAP.';
        return false;
    }
    return $imap;
}

function list_folders(array $cfg, $imap, array &$timings): array {
    $acct = account_key($cfg);
    $cacheKey = 'folders_' . $acct;
    $cached = cache_get_json($cfg, $cacheKey, 600);
    if ($cached !== null) {
        return $cached;
    }
    if (!$imap) {
        return [];
    }
    t_start($timings, 'folders');
    $base = imap_base_string($cfg);
    $list = imap_list($imap, $base, '*') ?: [];
    $folders = [];
    foreach ($list as $f) {
        $folders[] = str_replace($base, '', $f);
    }
    sort($folders);
    t_end($timings, 'folders');
    cache_set_json($cfg, $cacheKey, $folders);
    return $folders;
}

function sanitize_header_value(string $value): string {
    $value = str_replace(["\r", "\n"], '', $value);
    return trim($value);
}

function parse_recipients(string $input): array {
    $parts = preg_split('/[;,]+/', $input);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $out[] = $p;
        }
    }
    return array_values(array_unique($out));
}

function smtp_read($fp): string {
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtp_expect($fp, array $codes): void {
    $resp = smtp_read($fp);
    $code = (int) substr($resp, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($resp));
    }
}

function smtp_cmd($fp, string $cmd, array $codes): void {
    fwrite($fp, $cmd . "\r\n");
    smtp_expect($fp, $codes);
}

function smtp_send(array $cfg, string $to, string $cc, string $bcc, string $subject, string $body): void {
    $host = (string) cfg_get($cfg, 'smtp.host');
    $port = (int) cfg_get($cfg, 'smtp.port');
    $enc = strtolower((string) cfg_get($cfg, 'smtp.encryption', 'ssl'));

    $remote = $host;
    if ($enc === 'ssl') {
        $remote = 'ssl://' . $host;
    }

    $fp = fsockopen($remote, $port, $errno, $errstr, 20);
    if (!$fp) {
        throw new RuntimeException('SMTP connection failed: ' . $errstr);
    }
    stream_set_timeout($fp, 20);

    smtp_expect($fp, [220]);
    smtp_cmd($fp, 'EHLO localhost', [250]);

    if ($enc === 'tls') {
        smtp_cmd($fp, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Failed to start TLS.');
        }
        smtp_cmd($fp, 'EHLO localhost', [250]);
    }

    $user = (string) cfg_get($cfg, 'username');
    $pass = (string) cfg_get($cfg, 'password');

    smtp_cmd($fp, 'AUTH LOGIN', [334]);
    smtp_cmd($fp, base64_encode($user), [334]);
    smtp_cmd($fp, base64_encode($pass), [235]);

    $from = $user;
    $toList = parse_recipients($to);
    $ccList = parse_recipients($cc);
    $bccList = parse_recipients($bcc);
    $allRcpt = array_merge($toList, $ccList, $bccList);
    if (!$allRcpt) {
        throw new RuntimeException('No valid recipients.');
    }

    smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250]);
    foreach ($allRcpt as $r) {
        smtp_cmd($fp, 'RCPT TO:<' . $r . '>', [250, 251]);
    }

    smtp_cmd($fp, 'DATA', [354]);

    $fromName = sanitize_header_value((string) cfg_get($cfg, 'from_name', ''));
    $fromHeader = $fromName !== '' ? sprintf('%s <%s>', $fromName, $from) : $from;

    $subject = sanitize_header_value($subject);
    if (function_exists('mb_encode_mimeheader')) {
        $subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
    }

    $headers = [];
    $headers[] = 'From: ' . $fromHeader;
    $headers[] = 'To: ' . implode(', ', $toList);
    if ($ccList) {
        $headers[] = 'Cc: ' . implode(', ', $ccList);
    }
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Message-ID: <' . uniqid('', true) . '@' . $host . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';

    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace("\n.", "\n..", $body);
    $data = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.";

    fwrite($fp, $data . "\r\n");
    smtp_expect($fp, [250]);

    smtp_cmd($fp, 'QUIT', [221]);
    fclose($fp);
}

function get_message_part($imap, int $uid, $structure, string $mime, string $partNo = ''): ?string {
    $primary = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other'];
    $type = $primary[$structure->type] ?? 'other';
    $subtype = strtolower((string) ($structure->subtype ?? ''));
    $currentMime = $type . '/' . $subtype;

    if ($structure->type === 0 && $currentMime === $mime) {
        $body = imap_fetchbody($imap, $uid, $partNo === '' ? '1' : $partNo, FT_UID);
        if ($structure->encoding === 3) {
            $body = base64_decode($body);
        } elseif ($structure->encoding === 4) {
            $body = quoted_printable_decode($body);
        }
        return $body;
    }

    if (!empty($structure->parts)) {
        $i = 1;
        foreach ($structure->parts as $part) {
            $pno = $partNo === '' ? (string) $i : $partNo . '.' . $i;
            $data = get_message_part($imap, $uid, $part, $mime, $pno);
            if ($data !== null) {
                return $data;
            }
            $i++;
        }
    }

    return null;
}

function get_message_body($imap, array $cfg, string $folder, int $uid, array &$timings): string {
    $acct = account_key($cfg);
    $cacheKey = 'body_' . $acct . '_' . sha1($folder . '|' . $uid);
    $cached = cache_get_text($cfg, $cacheKey, 300);
    if ($cached !== null) {
        return $cached;
    }
    t_start($timings, 'body');
    $structure = imap_fetchstructure($imap, $uid, FT_UID);
    if (!$structure) {
        t_end($timings, 'body');
        return '';
    }
    $plain = get_message_part($imap, $uid, $structure, 'text/plain');
    if ($plain !== null) {
        t_end($timings, 'body');
        cache_set_text($cfg, $cacheKey, $plain);
        return $plain;
    }
    $html = get_message_part($imap, $uid, $structure, 'text/html');
    if ($html !== null) {
        $stripped = strip_tags($html);
        t_end($timings, 'body');
        cache_set_text($cfg, $cacheKey, $stripped);
        return $stripped;
    }
    t_end($timings, 'body');
    return '';
}

function fetch_uid_list($imap, array $cfg, string $folder, bool $showAll, int $days, string $query, bool $unreadOnly, int $max, array &$timings): array {
    $acct = account_key($cfg);
    $criteria = 'ALL';
    $query = trim($query);
    if ($unreadOnly) {
        $criteria = 'UNSEEN';
    } elseif ($query !== '') {
        $q = str_replace(['\\', '"'], ['\\\\', '\"'], $query);
        $criteria = 'TEXT "' . $q . '"';
    } elseif (!$showAll) {
        $since = date('d-M-Y', time() - ($days * 86400));
        $criteria = 'SINCE "' . $since . '"';
    }

    $cacheKey = 'uids_' . $acct . '_' . sha1($folder . '|' . $criteria . '|' . (int) $max);
    $uidTtl = (int) cfg_get($cfg, 'list_uid_ttl', 120);
    $cached = cache_get_json($cfg, $cacheKey, $uidTtl);
    if ($cached !== null) {
        return $cached;
    }

    t_start($timings, 'uids');
    $uids = [];
    if (function_exists('imap_sort')) {
        $sorted = imap_sort($imap, SORTDATE, true, SE_UID, $criteria);
        if (is_array($sorted)) {
            $uids = $sorted;
        }
    }
    if (!$uids) {
        $uids = imap_search($imap, $criteria, SE_UID) ?: [];
        rsort($uids);
    }
    if ($max > 0 && count($uids) > $max) {
        $uids = array_slice($uids, 0, $max);
    }
    t_end($timings, 'uids');
    cache_set_json($cfg, $cacheKey, $uids);
    return $uids;
}

function fetch_overview_list($imap, array $uids, array &$timings): array {
    if (!$uids) {
        return [];
    }
    t_start($timings, 'overview');
    $overview = imap_fetch_overview($imap, implode(',', $uids), FT_UID) ?: [];
    $byUid = [];
    foreach ($overview as $ov) {
        if (isset($ov->uid)) {
            $byUid[(int) $ov->uid] = $ov;
        }
    }
    $ordered = [];
    foreach ($uids as $uid) {
        if (isset($byUid[(int) $uid])) {
            $ordered[] = $byUid[(int) $uid];
        }
    }
    t_end($timings, 'overview');
    return $ordered;
}

$action = $_GET['action'] ?? 'list';
$folder = $_GET['folder'] ?? (string) cfg_get($cfg, 'imap.default_folder', 'INBOX');
$showAll = ($_GET['all'] ?? '') === '1';
$unreadOnly = ($_GET['unread'] ?? '') === '1';
$query = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = max(5, min(50, (int) ($_GET['limit'] ?? 20)));
$listDays = max(1, (int) cfg_get($cfg, 'list_days_default', 30));
$unreadLimit = 10;

$flash = '';
$error = '';

$imap = null;
$imapErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'send') {
        try {
            smtp_send(
                $cfg,
                sanitize_header_value($_POST['to'] ?? ''),
                sanitize_header_value($_POST['cc'] ?? ''),
                sanitize_header_value($_POST['bcc'] ?? ''),
                (string) ($_POST['subject'] ?? ''),
                (string) ($_POST['body'] ?? '')
            );
            $flash = 'Message sent.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $action = 'compose';
    } elseif ($action === 'delete') {
        $uid = (int) ($_POST['uid'] ?? 0);
        t_start($timings, 'connect');
        $imap = open_imap($cfg, $folder, $imapErr);
        t_end($timings, 'connect');
        if ($imap) {
            $msgno = imap_msgno($imap, $uid);
            if ($msgno > 0) {
                imap_delete($imap, $msgno);
                imap_expunge($imap);
                $flash = 'Message deleted.';
            }
            imap_close($imap);
        } else {
            $error = $imapErr ?? 'Unable to delete message.';
        }
        $action = 'list';
    }
}

$imapOk = false;
if (in_array($action, ['list', 'view'], true)) {
    t_start($timings, 'connect');
    $imap = open_imap($cfg, $folder, $imapErr);
    t_end($timings, 'connect');
    $imapOk = $imap !== false;
}

$folders = list_folders($cfg, $imapOk ? $imap : null, $timings);
if (!$folders && $action === 'compose') {
    t_start($timings, 'connect');
    $imapTmp = open_imap($cfg, $folder, $imapErr);
    t_end($timings, 'connect');
    if ($imapTmp) {
        $folders = list_folders($cfg, $imapTmp, $timings);
        imap_close($imapTmp);
    }
}

$messages = [];
$total = 0;
$pageCount = 1;
$ov = null;
$body = '';

if ($action === 'view') {
    $uid = (int) ($_GET['uid'] ?? 0);
    if ($imapOk && $uid > 0) {
        $ovList = imap_fetch_overview($imap, (string) $uid, FT_UID);
        $ov = $ovList[0] ?? null;
        if ($ov) {
            $body = get_message_body($imap, $cfg, $folder, $uid, $timings);
        }
    }
    if ($imapOk) {
        imap_close($imap);
    }
} elseif ($action === 'list') {
    if ($imapOk) {
        $uids = fetch_uid_list($imap, $cfg, $folder, $showAll, $listDays, $query, $unreadOnly, $unreadOnly ? $unreadLimit : 0, $timings);
        $total = count($uids);
        $offset = ($page - 1) * $limit;
        $slice = $unreadOnly ? $uids : array_slice($uids, $offset, $limit);
        if ($slice) {
            $messages = fetch_overview_list($imap, $slice, $timings);
        }
        imap_close($imap);
    }
    $pageCount = $unreadOnly ? 1 : ($limit > 0 ? (int) ceil($total / $limit) : 1);
}

$totalMs = (microtime(true) - $pageStart) * 1000.0;

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Mail Client</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
    :root {
        --bg: #f4f6fb;
        --panel: #ffffff;
        --ink: #1a1d29;
        --muted: #5f6b7a;
        --accent: #1e66f5;
        --accent-2: #0b3aa4;
        --line: #e5e8f1;
        --shadow: 0 10px 30px rgba(17, 24, 39, 0.08);
        --radius: 14px;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: "Avenir Next", "Avenir", "Trebuchet MS", sans-serif;
        background: radial-gradient(1200px 400px at 20% -10%, #e9f0ff 0%, #f4f6fb 55%, #f6f7fb 100%);
        color: var(--ink);
    }
    .loader {
        height: 3px;
        background: linear-gradient(90deg, transparent, var(--accent), transparent);
        background-size: 200% 100%;
        animation: load 1.4s ease-in-out infinite;
    }
    @keyframes load {
        0% { background-position: 0% 0; }
        100% { background-position: 200% 0; }
    }
    .topbar {
        position: sticky;
        top: 0;
        z-index: 10;
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(8px);
        border-bottom: 1px solid var(--line);
        box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
    }
    .topbar-inner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        max-width: 1200px;
        margin: 0 auto;
        padding: 12px 18px;
    }
    .brand {
        font-weight: 700;
        letter-spacing: 0.4px;
        color: var(--accent-2);
    }
    .search {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #f1f4fb;
        border: 1px solid var(--line);
        border-radius: 999px;
        padding: 8px 14px;
        width: 100%;
        max-width: 520px;
        height: 40px;
    }
    .search input {
        border: 0;
        outline: none;
        background: transparent;
        width: 100%;
        font-size: 14px;
    }
    .search svg { width: 16px; height: 16px; }
    .actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        flex-wrap: wrap;
    }
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 14px;
        border-radius: 10px;
        border: 1px solid transparent;
        background: #fff;
        color: var(--ink);
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08);
    }
    .btn svg { width: 16px; height: 16px; }
    .btn.primary {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
    }
    .btn.secondary {
        background: #eef2ff;
        color: #1f3fbf;
        border-color: #dbe4ff;
    }
    .btn.danger {
        background: #fee2e2;
        color: #991b1b;
        border-color: #fecaca;
    }
    .layout {
        max-width: 1200px;
        margin: 18px auto;
        display: grid;
        grid-template-columns: 260px 1fr;
        gap: 18px;
        padding: 0 18px 28px;
    }
    .sidebar {
        background: var(--panel);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 16px;
        border: 1px solid var(--line);
        height: fit-content;
    }
    .sidebar h3 {
        font-size: 12px;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: var(--muted);
        margin: 12px 0 8px;
    }
    .folder {
        display: flex;
        justify-content: space-between;
        padding: 8px 10px;
        border-radius: 10px;
        color: var(--ink);
        text-decoration: none;
        font-size: 14px;
    }
    .folder.active {
        background: #edf2ff;
        color: #1c3aa9;
        font-weight: 600;
    }
    .content {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    .panel {
        background: var(--panel);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1px solid var(--line);
        padding: 18px;
    }
    .panel h2 {
        margin: 0 0 8px;
        font-size: 18px;
    }
    .msg {
        font-size: 13px;
        padding: 10px 12px;
        border-radius: 10px;
        margin-bottom: 12px;
    }
    .msg.ok { background: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; }
    .msg.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .list-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }
    .mail-list {
        display: grid;
        gap: 8px;
    }
    .mail-row {
        display: grid;
        grid-template-columns: 200px 1fr 140px;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 12px;
        border: 1px solid var(--line);
        text-decoration: none;
        color: inherit;
        background: #fff;
    }
    .mail-row:hover { border-color: #c7d2fe; box-shadow: 0 6px 18px rgba(30, 64, 175, 0.08); }
    .mail-row.unread { background: #f8faff; border-color: #dbe4ff; }
    .mail-from { font-weight: 600; }
    .mail-subject { font-weight: 600; }
    .mail-snippet { color: var(--muted); font-size: 13px; margin-top: 4px; }
    .mail-date { text-align: right; color: var(--muted); font-size: 12px; }
    .mail-meta { display: flex; flex-direction: column; }
    .message-card pre {
        white-space: pre-wrap;
        word-wrap: break-word;
        background: #f8fafc;
        border: 1px solid var(--line);
        padding: 14px;
        border-radius: 12px;
    }
    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; }
    .field input, .field textarea {
        width: 100%;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid var(--line);
        font-size: 14px;
        font-family: inherit;
    }
    .field textarea { height: 220px; }
    .pager { display: flex; gap: 8px; margin-top: 12px; }
    .timing {
        font-size: 12px;
        color: var(--muted);
        background: #f8fafc;
        border: 1px dashed #dbe4ff;
        padding: 10px;
        border-radius: 10px;
    }
    @media (max-width: 980px) {
        .topbar-inner { grid-template-columns: 1fr; }
        .layout { grid-template-columns: 1fr; }
        .mail-row { grid-template-columns: 1fr; }
        .mail-date { text-align: left; }
    }
</style>
</head>
<body>
<div class="loader" aria-hidden="true"></div>
<div class="topbar">
    <div class="topbar-inner">
        <div class="brand">Mail Client</div>
        <form class="search" method="get" action="">
            <input type="hidden" name="action" value="list" />
            <input type="hidden" name="folder" value="<?php echo h($folder); ?>" />
            <?php if ($showAll): ?>
                <input type="hidden" name="all" value="1" />
            <?php endif; ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="#52607a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input name="q" placeholder="Search mail" value="<?php echo h($query); ?>" />
        </form>
        <div class="actions">
            <a class="btn primary" href="?action=compose&folder=<?php echo urlencode($folder); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14"></path>
                    <path d="M5 12h14"></path>
                </svg>
                Compose
            </a>
            <a class="btn secondary" href="?action=list&folder=<?php echo urlencode($folder); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="#1f3fbf" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"></polyline>
                    <polyline points="1 20 1 14 7 14"></polyline>
                    <path d="M3.51 9a9 9 0 0 1 14.13-3.36L23 10"></path>
                    <path d="M1 14l5.36 4.36A9 9 0 0 0 20.49 15"></path>
                </svg>
                Refresh
            </a>
        </div>
    </div>
</div>

<?php if ($flash): ?>
    <div class="panel msg ok" style="max-width:1200px;margin:16px auto 0;"><?php echo h($flash); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="panel msg err" style="max-width:1200px;margin:16px auto 0;"><?php echo h($error); ?></div>
<?php endif; ?>

<div class="layout">
    <aside class="sidebar">
        <a class="btn primary" style="width:100%;justify-content:center;" href="?action=compose&folder=<?php echo urlencode($folder); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 5v14"></path>
                <path d="M5 12h14"></path>
            </svg>
            Compose
        </a>
        <h3>Folders</h3>
        <?php if ($folders): ?>
            <?php foreach ($folders as $f): ?>
                <a class="folder <?php echo $f === $folder ? 'active' : ''; ?>" href="?action=list&folder=<?php echo urlencode($f); ?>">
                    <?php echo h($f); ?>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="msg err" style="margin-top:10px;">Unable to load folders.</div>
        <?php endif; ?>
    </aside>

    <main class="content">
        <?php if ($action === 'compose'): ?>
            <div class="panel">
                <h2>Compose</h2>
                <form method="post" action="?action=send&folder=<?php echo urlencode($folder); ?>">
                    <div class="field"><label>To</label><input name="to" value="<?php echo h($_POST['to'] ?? ''); ?>" /></div>
                    <div class="field"><label>Cc</label><input name="cc" value="<?php echo h($_POST['cc'] ?? ''); ?>" /></div>
                    <div class="field"><label>Bcc</label><input name="bcc" value="<?php echo h($_POST['bcc'] ?? ''); ?>" /></div>
                    <div class="field"><label>Subject</label><input name="subject" value="<?php echo h($_POST['subject'] ?? ''); ?>" /></div>
                    <div class="field"><label>Body</label><textarea name="body"><?php echo h($_POST['body'] ?? ''); ?></textarea></div>
                    <div class="actions">
                        <button class="btn primary" type="submit">
                            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                            Send
                        </button>
                        <a class="btn secondary" href="?action=list&folder=<?php echo urlencode($folder); ?>">Back</a>
                    </div>
                </form>
            </div>
        <?php elseif ($action === 'view'): ?>
            <div class="panel message-card">
                <div class="actions" style="margin-bottom:12px;">
                    <a class="btn secondary" href="?action=list&folder=<?php echo urlencode($folder); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#1f3fbf" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 18l-6-6 6-6"></path>
                        </svg>
                        Back
                    </a>
                </div>
                <?php if (!$imapOk): ?>
                    <div class="msg err"><?php echo h($imapErr ?? 'Unable to load message.'); ?></div>
                <?php elseif (!$ov): ?>
                    <div class="msg err">Message not found.</div>
                <?php else: ?>
                    <h2><?php echo h($ov->subject ?? '(no subject)'); ?></h2>
                    <div class="field"><label>From</label><div><?php echo h($ov->from ?? ''); ?></div></div>
                    <div class="field"><label>To</label><div><?php echo h($ov->to ?? ''); ?></div></div>
                    <div class="field"><label>Date</label><div><?php echo h($ov->date ?? ''); ?></div></div>
                    <pre><?php echo h($body); ?></pre>
                    <form method="post" action="?action=delete&folder=<?php echo urlencode($folder); ?>">
                        <input type="hidden" name="uid" value="<?php echo (int) ($_GET['uid'] ?? 0); ?>" />
                        <div class="actions">
                            <button class="btn danger" type="submit" onclick="return confirm('Delete this message?');">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#991b1b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6l-1 14H6L5 6"></path>
                                    <path d="M10 11v6"></path>
                                    <path d="M14 11v6"></path>
                                    <path d="M9 6V4h6v2"></path>
                                </svg>
                                Delete
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="panel">
                <div class="list-header">
                    <div>
                        <h2><?php echo h($folder); ?></h2>
                        <div style="color:var(--muted);font-size:12px;">
                            <?php if ($query !== ''): ?>
                                Searching for "<?php echo h($query); ?>"
                            <?php elseif ($showAll): ?>
                                Showing all mail
                            <?php else: ?>
                                Last <?php echo (int) $listDays; ?> days
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="actions">
                        <?php if ($unreadOnly): ?>
                            <a class="btn secondary" href="?action=list&folder=<?php echo urlencode($folder); ?>&q=<?php echo urlencode($query); ?>">Last <?php echo (int) $listDays; ?> days</a>
                        <?php elseif ($showAll): ?>
                            <a class="btn secondary" href="?action=list&folder=<?php echo urlencode($folder); ?>&q=<?php echo urlencode($query); ?>">Last <?php echo (int) $listDays; ?> days</a>
                        <?php else: ?>
                            <a class="btn secondary" href="?action=list&folder=<?php echo urlencode($folder); ?>&all=1&q=<?php echo urlencode($query); ?>">Show all</a>
                        <?php endif; ?>
                        <?php if (!$unreadOnly): ?>
                            <a class="btn secondary" href="?action=list&folder=<?php echo urlencode($folder); ?>&unread=1">Last 10 unread</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$imapOk): ?>
                    <div class="msg err"><?php echo h($imapErr ?? 'Unable to connect.'); ?></div>
                <?php elseif (!$messages): ?>
                    <div style="color:var(--muted);">No messages found.</div>
                <?php else: ?>
                    <div class="mail-list">
                        <?php foreach ($messages as $m): ?>
                            <?php
                                $uid = (int) ($m->uid ?? 0);
                                $from = trim((string) ($m->from ?? ''));
                                $subject = trim((string) ($m->subject ?? '(no subject)'));
                                $date = trim((string) ($m->date ?? ''));
                                $unread = empty($m->seen);
                            ?>
                            <a class="mail-row <?php echo $unread ? 'unread' : ''; ?>" href="?action=view&folder=<?php echo urlencode($folder); ?>&uid=<?php echo $uid; ?>">
                                <div class="mail-from"><?php echo h($from); ?></div>
                                <div class="mail-meta">
                                    <div class="mail-subject"><?php echo h($subject); ?></div>
                                    <div class="mail-snippet"><?php echo h($m->to ?? ''); ?></div>
                                </div>
                                <div class="mail-date"><?php echo h($date); ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($pageCount > 1): ?>
                        <div class="pager">
                            <?php if ($page > 1): ?>
                                <a class="btn secondary" href="?action=list&folder=<?php echo urlencode($folder); ?>&page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&all=<?php echo $showAll ? '1' : '0'; ?>&unread=<?php echo $unreadOnly ? '1' : '0'; ?>&q=<?php echo urlencode($query); ?>">Prev</a>
                            <?php endif; ?>
                            <?php if ($page < $pageCount): ?>
                                <a class="btn secondary" href="?action=list&folder=<?php echo urlencode($folder); ?>&page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&all=<?php echo $showAll ? '1' : '0'; ?>&unread=<?php echo $unreadOnly ? '1' : '0'; ?>&q=<?php echo urlencode($query); ?>">Next</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($debug): ?>
            <div class="panel timing">
                <?php
                    $rows = [];
                    foreach ($timings as $k => $v) {
                        $rows[] = $k . ': ' . number_format($v['ms'] ?? 0.0, 1) . ' ms';
                    }
                    $rows[] = 'total: ' . number_format($totalMs, 1) . ' ms';
                ?>
                <?php echo h(implode(' | ', $rows)); ?>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
