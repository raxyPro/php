<?php
declare(strict_types=1);

function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Failed to create directory: {$path}");
        }
    }
}

function safe_join(string $base, string ...$parts): string {
    $path = rtrim($base, DIRECTORY_SEPARATOR);
    foreach ($parts as $p) {
        $p = trim($p);
        $p = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
        $p = ltrim($p, DIRECTORY_SEPARATOR);
        $path .= DIRECTORY_SEPARATOR . $p;
    }
    return $path;
}

function to_slug(string $name): string {
    $name = mb_strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9]+/u', '-', $name) ?? '';
    $name = trim($name, '-');
    return $name !== '' ? $name : 'folder-' . bin2hex(random_bytes(3));
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'error' => 'POST required'], 405);
    }
}

function now_iso(): string {
    return (new DateTimeImmutable('now'))->format('c');
}

function normalize_folder_path(string $path): string {
    // Realpath only works if it exists
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $path = rtrim($path, DIRECTORY_SEPARATOR);
    $rp = realpath($path);
    return $rp !== false ? $rp : $path;
}

function is_within_base(string $path, string $base): bool {
    $path = normalize_folder_path($path);
    $base = normalize_folder_path($base);
    // Ensure base exists for reliable containment checks
    $baseRp = realpath($base);
    $pathRp = realpath($path);
    if ($baseRp === false || $pathRp === false) return false;
    $baseRp = rtrim($baseRp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $pathRp = rtrim($pathRp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return strncmp($pathRp, $baseRp, strlen($baseRp)) === 0;
}

function file_url_from_path(string $absolutePath): string {
    // Build file:// URL in an OS-neutral way
    $absolutePath = str_replace(DIRECTORY_SEPARATOR, '/', $absolutePath);
    // On Windows, absolute path might be like C:/...
    if (preg_match('/^[A-Za-z]:\//', $absolutePath)) {
        return 'file:///' . rawurlencode(substr($absolutePath, 0, 1)) . substr($absolutePath, 1);
    }
    return 'file://' . $absolutePath;
}

function human_filesize(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    $val = (float)$bytes;
    while ($val >= 1024 && $i < count($units)-1) {
        $val /= 1024;
        $i++;
    }
    return sprintf($i === 0 ? '%d %s' : '%.1f %s', $val, $units[$i]);
}
