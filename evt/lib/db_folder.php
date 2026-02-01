<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db_registry.php';

function folder_pdo(int $folderId): PDO {
    $f = registry_get_folder($folderId);
    if (!$f) throw new RuntimeException('Folder not found');

    $pdo = new PDO(MYSQL_DSN, MYSQL_USER, MYSQL_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Schema
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS tags (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            folder_id INT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            name_norm VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_tags_folder_norm (folder_id, name_norm),
            INDEX idx_tags_folder (folder_id),
            CONSTRAINT fk_tags_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            folder_id INT UNSIGNED NOT NULL,
            event_date DATETIME NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            remark TEXT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_events_folder_date (folder_id, event_date),
            CONSTRAINT fk_events_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS event_files (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            file_url TEXT NOT NULL,
            display_name VARCHAR(512),
            file_type VARCHAR(64),
            added_at DATETIME NOT NULL,
            UNIQUE KEY uq_event_file (event_id, file_url(255)),
            INDEX idx_files_event (event_id),
            CONSTRAINT fk_files_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS event_tags (
            event_id INT UNSIGNED NOT NULL,
            tag_id INT UNSIGNED NOT NULL,
            PRIMARY KEY(event_id, tag_id),
            CONSTRAINT fk_event_tags_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
            CONSTRAINT fk_event_tags_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ');

    return $pdo;
}

function tag_norm(string $name): string {
    $n = mb_strtolower(trim($name));
    $n = preg_replace('/\s+/u', ' ', $n) ?? $n;
    return $n;
}

function folder_list_tags(int $folderId): array {
    $pdo = folder_pdo($folderId);
    $stmt = $pdo->prepare('SELECT id, name FROM tags WHERE folder_id=:fid ORDER BY name_norm ASC');
    $stmt->execute([':fid'=>$folderId]);
    return $stmt->fetchAll();
}

function folder_create_tag(int $folderId, string $name): array {
    $pdo = folder_pdo($folderId);
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('Tag name required');
    $norm = tag_norm($name);

    $stmt = $pdo->prepare('
      INSERT INTO tags(folder_id, name, name_norm, created_at)
      VALUES(:fid,:n,:nn,:c)
      ON DUPLICATE KEY UPDATE name = VALUES(name)
    ');
    $stmt->execute([
        ':fid'=>$folderId,
        ':n'=>$name,
        ':nn'=>$norm,
        ':c'=>(new DateTimeImmutable('now'))->format('Y-m-d H:i:s')
    ]);

    // Fetch
    $stmt = $pdo->prepare('SELECT id, name FROM tags WHERE folder_id=:fid AND name_norm=:nn');
    $stmt->execute([':fid'=>$folderId, ':nn'=>$norm]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Failed to create/fetch tag');
    return $row;
}

function folder_list_events(int $folderId, ?string $q, ?int $tagId): array {
    $pdo = folder_pdo($folderId);

    $where = [];
    $params = [];

    $where[] = 'e.folder_id = :fid';
    $params[':fid'] = $folderId;

    if ($q !== null && trim($q) !== '') {
        $where[] = '(e.name LIKE :q OR e.description LIKE :q OR e.remark LIKE :q)';
        $params[':q'] = '%' . trim($q) . '%';
    }
    if ($tagId !== null) {
        $where[] = 'EXISTS(SELECT 1 FROM event_tags et WHERE et.event_id=e.id AND et.tag_id=:tid)';
        $params[':tid'] = $tagId;
    }

    $sql = '
      SELECT
        e.id, e.event_date, e.name, e.description, e.remark,
        (SELECT COUNT(*) FROM event_files ef WHERE ef.event_id=e.id) AS file_count
      FROM events e
    ';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY e.event_date DESC, e.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();

    // Attach tags list per event (small scale; simple approach)
    foreach ($events as &$ev) {
        $stmt2 = $pdo->prepare('
          SELECT t.id, t.name
          FROM event_tags et
          JOIN tags t ON t.id=et.tag_id
          WHERE et.event_id=:eid
          ORDER BY t.name_norm ASC
        ');
        $stmt2->execute([':eid' => $ev['id']]);
        $ev['tags'] = $stmt2->fetchAll();
    }
    return $events;
}

function folder_get_event(int $folderId, int $eventId): ?array {
    $pdo = folder_pdo($folderId);
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id=:id AND folder_id=:fid');
    $stmt->execute([':id'=>$eventId, ':fid'=>$folderId]);
    $ev = $stmt->fetch();
    if (!$ev) return null;

    $stmtT = $pdo->prepare('
      SELECT t.id, t.name
      FROM event_tags et
      JOIN tags t ON t.id=et.tag_id
      WHERE et.event_id=:eid
      ORDER BY t.name_norm ASC
    ');
    $stmtT->execute([':eid'=>$eventId]);
    $ev['tags'] = $stmtT->fetchAll();

    $stmtF = $pdo->prepare('
      SELECT id, file_url, display_name, file_type, added_at
      FROM event_files
      WHERE event_id=:eid
      ORDER BY id DESC
    ');
    $stmtF->execute([':eid'=>$eventId]);
    $ev['files'] = $stmtF->fetchAll();

    return $ev;
}

function folder_create_event(int $folderId, array $data): array {
    $pdo = folder_pdo($folderId);

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Event name required');

    $eventDate = (string)($data['event_date'] ?? '');
    if ($eventDate === '') {
        $eventDate = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    $desc = (string)($data['description'] ?? '');
    $remark = (string)($data['remark'] ?? '');

    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare('
      INSERT INTO events(folder_id, event_date, name, description, remark, created_at, updated_at)
      VALUES(:fid,:d,:n,:ds,:r,:c,:u)
    ');
    $stmt->execute([
        ':fid'=>$folderId,
        ':d'=>$eventDate,
        ':n'=>$name,
        ':ds'=>$desc,
        ':r'=>$remark,
        ':c'=>$now,
        ':u'=>$now
    ]);

    $id = (int)$pdo->lastInsertId();
    return folder_get_event($folderId, $id) ?? ['id'=>$id];
}

function folder_update_event(int $folderId, int $eventId, array $data): array {
    $pdo = folder_pdo($folderId);
    $ev = folder_get_event($folderId, $eventId);
    if (!$ev) throw new RuntimeException('Event not found');

    $name = trim((string)($data['name'] ?? $ev['name']));
    if ($name === '') throw new InvalidArgumentException('Event name required');

    $eventDate = (string)($data['event_date'] ?? $ev['event_date']);
    $desc = (string)($data['description'] ?? $ev['description']);
    $remark = (string)($data['remark'] ?? $ev['remark']);

    $stmt = $pdo->prepare('
      UPDATE events
      SET event_date=:d, name=:n, description=:ds, remark=:r, updated_at=:u
      WHERE id=:id AND folder_id=:fid
    ');
    $stmt->execute([
        ':d'=>$eventDate,
        ':n'=>$name,
        ':ds'=>$desc,
        ':r'=>$remark,
        ':u'=>(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
        ':id'=>$eventId,
        ':fid'=>$folderId
    ]);

    return folder_get_event($folderId, $eventId) ?? ['id'=>$eventId];
}

function folder_set_event_tags(int $folderId, int $eventId, array $tagNames): void {
    $pdo = folder_pdo($folderId);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM event_tags WHERE event_id=:eid')->execute([':eid'=>$eventId]);

        foreach ($tagNames as $tn) {
            $tn = trim((string)$tn);
            if ($tn === '') continue;
            $tag = folder_create_tag($folderId, $tn);
            $pdo->prepare('INSERT OR IGNORE INTO event_tags(event_id, tag_id) VALUES(:e,:t)')
                ->execute([':e'=>$eventId, ':t'=>(int)$tag['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function folder_link_file_to_event(int $folderId, int $eventId, array $file): void {
    $pdo = folder_pdo($folderId);
    $fileUrl = trim((string)($file['file_url'] ?? ''));
    if ($fileUrl === '') throw new InvalidArgumentException('file_url required');

    $display = isset($file['display_name']) ? trim((string)$file['display_name']) : null;
    $type = isset($file['file_type']) ? trim((string)$file['file_type']) : null;

    $stmt = $pdo->prepare('
      INSERT INTO event_files(event_id, file_url, display_name, file_type, added_at)
      VALUES(:e,:u,:d,:t,:a)
      ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), file_type = VALUES(file_type)
    ');
    $stmt->execute([
        ':e'=>$eventId,
        ':u'=>$fileUrl,
        ':d'=>$display !== '' ? $display : null,
        ':t'=>$type !== '' ? $type : null,
        ':a'=>(new DateTimeImmutable('now'))->format('Y-m-d H:i:s')
    ]);
}

function folder_remove_event_file(int $folderId, int $eventFileId): void {
    $pdo = folder_pdo($folderId);
    $stmt = $pdo->prepare('DELETE FROM event_files WHERE id=:id');
    $stmt->execute([':id'=>$eventFileId]);
}
