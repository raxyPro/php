<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/util.php';

function registry_pdo(): PDO {
    ensure_dir(dirname(REGISTRY_DB_PATH));
    $pdo = new PDO('sqlite:' . REGISTRY_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS folders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            path TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
    ');
    return $pdo;
}

function registry_list_folders(): array {
    $pdo = registry_pdo();
    $stmt = $pdo->query('SELECT id, name, slug, path, created_at FROM folders ORDER BY id DESC;');
    return $stmt->fetchAll();
}

function registry_create_folder(string $name): array {
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('Folder name required');

    ensure_dir(BASE_FOLDERS_DIR);

    $slug = to_slug($name);
    $folderPath = safe_join(BASE_FOLDERS_DIR, $slug);

    // Ensure unique slug if collision
    $pdo = registry_pdo();
    $try = $slug;
    $i = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM folders WHERE slug = :s');
        $stmt->execute([':s' => $try]);
        $c = (int)($stmt->fetch()['c'] ?? 0);
        if ($c === 0) { $slug = $try; break; }
        $i++;
        $try = $slug . '-' . $i;
    }

    $folderPath = safe_join(BASE_FOLDERS_DIR, $slug);
    ensure_dir($folderPath);

    // Create app subdir, db subdir, files subdir handled later by folder db init
    $createdAt = now_iso();
    $stmt = $pdo->prepare('INSERT INTO folders(name, slug, path, created_at) VALUES(:n,:s,:p,:c)');
    $stmt->execute([
        ':n' => $name,
        ':s' => $slug,
        ':p' => normalize_folder_path($folderPath),
        ':c' => $createdAt
    ]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'name' => $name,
        'slug' => $slug,
        'path' => normalize_folder_path($folderPath),
        'created_at' => $createdAt
    ];
}

function registry_remove_folder(int $id): void {
    $pdo = registry_pdo();
    $stmt = $pdo->prepare('DELETE FROM folders WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function registry_get_folder(int $id): ?array {
    $pdo = registry_pdo();
    $stmt = $pdo->prepare('SELECT id, name, slug, path, created_at FROM folders WHERE id=:id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
