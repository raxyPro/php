<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/util.php';

function registry_pdo(): PDO {
    $pdo = new PDO(MYSQL_DSN, MYSQL_USER, MYSQL_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS folders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ');
    return $pdo;
}

function registry_list_folders(): array {
    $pdo = registry_pdo();
    $stmt = $pdo->query('SELECT id, name, slug, created_at FROM folders ORDER BY id DESC;');
    return $stmt->fetchAll();
}

function registry_create_folder(string $name): array {
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('Folder name required');

    $slug = to_slug($name);

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

    $createdAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO folders(name, slug, created_at) VALUES(:n,:s,:c)');
    $stmt->execute([
        ':n' => $name,
        ':s' => $slug,
        ':c' => $createdAt
    ]);

    return [
        'id' => (int)$pdo->lastInsertId(),
        'name' => $name,
        'slug' => $slug,
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
    $stmt = $pdo->prepare('SELECT id, name, slug, created_at FROM folders WHERE id=:id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
