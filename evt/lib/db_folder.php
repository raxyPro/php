<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db_registry.php';

function folder_db_path(string $folderPath): string {
    return safe_join($folderPath, FOLDER_APP_SUBDIR, FOLDER_DB_FILENAME);
}

function folder_files_dir(string $folderPath): string {
    return safe_join($folderPath, FOLDER_FILES_SUBDIR);
}

function folder_init_storage(string $folderPath): void {
    // Ensure folder is under base for safety
    if (!is_within_base($folderPath, BASE_FOLDERS_DIR)) {
        throw new RuntimeException('Folder path is outside BASE_FOLDERS_DIR (blocked for safety).');
    }
    ensure_dir($folderPath);
    ensure_dir(safe_join($folderPath, FOLDER_APP_SUBDIR));
    ensure_dir(folder_files_dir($folderPath));
}

function folder_pdo(int $folderId): PDO {
    $f = registry_get_folder($folderId);
    if (!$f) throw new RuntimeException('Folder not found');

    $folderPath = (string)$f['path'];
    folder_init_storage($folderPath);

    $dbPath = folder_db_path($folderPath);
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // Schema
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            name_norm TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_date TEXT NOT NULL,
            name TEXT NOT NULL,
            description TEXT,
            remark TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS event_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INTEGER NOT NULL,
            file_url TEXT NOT NULL,
            local_path TEXT,
            display_name TEXT,
            file_type TEXT,
            added_at TEXT NOT NULL,
            UNIQUE(event_id, file_url),
            FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS event_tags (
            event_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            PRIMARY KEY(event_id, tag_id),
            FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE,
            FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_events_date ON events(event_date);
        CREATE INDEX IF NOT EXISTS idx_files_event ON event_files(event_id);
        CREATE INDEX IF NOT EXISTS idx_tags_norm ON tags(name_norm);
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
    return $pdo->query('SELECT id, name FROM tags ORDER BY name_norm ASC')->fetchAll();
}

function folder_create_tag(int $folderId, string $name): array {
    $pdo = folder_pdo($folderId);
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('Tag name required');
    $norm = tag_norm($name);

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO tags(name, name_norm, created_at) VALUES(:n,:nn,:c)');
    $stmt->execute([':n'=>$name, ':nn'=>$norm, ':c'=>now_iso()]);

    // Fetch
    $stmt = $pdo->prepare('SELECT id, name FROM tags WHERE name_norm=:nn');
    $stmt->execute([':nn'=>$norm]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Failed to create/fetch tag');
    return $row;
}

function folder_list_events(int $folderId, ?string $q, ?int $tagId): array {
    $pdo = folder_pdo($folderId);

    $where = [];
    $params = [];

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
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id=:id');
    $stmt->execute([':id'=>$eventId]);
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
      SELECT id, file_url, local_path, display_name, file_type, added_at
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
        $eventDate = (new DateTimeImmutable('now'))->format('Y-m-d H:i');
    }

    $desc = (string)($data['description'] ?? '');
    $remark = (string)($data['remark'] ?? '');

    $now = now_iso();
    $stmt = $pdo->prepare('
      INSERT INTO events(event_date, name, description, remark, created_at, updated_at)
      VALUES(:d,:n,:ds,:r,:c,:u)
    ');
    $stmt->execute([
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
      WHERE id=:id
    ');
    $stmt->execute([
        ':d'=>$eventDate,
        ':n'=>$name,
        ':ds'=>$desc,
        ':r'=>$remark,
        ':u'=>now_iso(),
        ':id'=>$eventId
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

    $localPath = isset($file['local_path']) ? trim((string)$file['local_path']) : null;
    $display = isset($file['display_name']) ? trim((string)$file['display_name']) : null;
    $type = isset($file['file_type']) ? trim((string)$file['file_type']) : null;

    $stmt = $pdo->prepare('
      INSERT OR IGNORE INTO event_files(event_id, file_url, local_path, display_name, file_type, added_at)
      VALUES(:e,:u,:p,:d,:t,:a)
    ');
    $stmt->execute([
        ':e'=>$eventId,
        ':u'=>$fileUrl,
        ':p'=>$localPath !== '' ? $localPath : null,
        ':d'=>$display !== '' ? $display : null,
        ':t'=>$type !== '' ? $type : null,
        ':a'=>now_iso()
    ]);
}

function folder_remove_event_file(int $folderId, int $eventFileId): void {
    $pdo = folder_pdo($folderId);
    $stmt = $pdo->prepare('DELETE FROM event_files WHERE id=:id');
    $stmt->execute([':id'=>$eventFileId]);
}

function folder_list_inbox_files(int $folderId): array {
    $f = registry_get_folder($folderId);
    if (!$f) throw new RuntimeException('Folder not found');

    $folderPath = (string)$f['path'];
    folder_init_storage($folderPath);
    $dir = folder_files_dir($folderPath);

    $items = [];
    $dh = opendir($dir);
    if ($dh === false) return [];

    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') continue;
        $full = safe_join($dir, $file);
        if (!is_file($full)) continue;

        $items[] = [
            'display_name' => $file,
            'local_path' => normalize_folder_path($full),
            'file_url' => 'files/' . rawurlencode($file), // web-served URL via api.php?action=download
            'bytes' => filesize($full) ?: 0,
            'mtime' => filemtime($full) ?: 0,
        ];
    }
    closedir($dh);

    usort($items, fn($a,$b) => ($b['mtime'] <=> $a['mtime']));
    return $items;
}

function folder_save_uploads_to_inbox(int $folderId, array $files): array {
    $f = registry_get_folder($folderId);
    if (!$f) throw new RuntimeException('Folder not found');

    $folderPath = (string)$f['path'];
    folder_init_storage($folderPath);
    $dir = folder_files_dir($folderPath);

    $saved = [];
    // Handle multiple uploads under input name="files[]"
    $names = $files['name'] ?? [];
    $tmp = $files['tmp_name'] ?? [];
    $errors = $files['error'] ?? [];
    $sizes = $files['size'] ?? [];

    for ($i=0; $i<count($names); $i++) {
        $err = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) continue;

        $size = (int)($sizes[$i] ?? 0);
        if ($size > MAX_UPLOAD_BYTES) continue;

        $orig = (string)$names[$i];
        $orig = preg_replace('/[^\w.\- ]+/u', '_', $orig) ?? $orig;
        $orig = trim($orig);
        if ($orig === '') $orig = 'upload-' . bin2hex(random_bytes(4));

        // Ensure unique filename
        $target = safe_join($dir, $orig);
        $base = pathinfo($orig, PATHINFO_FILENAME);
        $ext  = pathinfo($orig, PATHINFO_EXTENSION);
        $k = 1;
        while (file_exists($target)) {
            $candidate = $base . '-' . $k . ($ext ? ('.' . $ext) : '');
            $target = safe_join($dir, $candidate);
            $k++;
        }

        if (!move_uploaded_file((string)$tmp[$i], $target)) continue;

        $saved[] = [
            'display_name' => basename($target),
            'local_path' => normalize_folder_path($target),
            'file_url' => 'files/' . rawurlencode(basename($target)),
            'bytes' => filesize($target) ?: 0,
            'mtime' => filemtime($target) ?: 0,
        ];
    }

    return $saved;
}
