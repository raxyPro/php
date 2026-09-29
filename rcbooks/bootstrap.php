<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load_env(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));

        if ($v !== '' && $v[0] === '"' && str_ends_with($v, '"')) {
            $v = substr($v, 1, -1);
        }

        $env[$k] = $v;
    }

    return $env;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $tok = $_POST['csrf'] ?? '';
    if (!$tok || !hash_equals($_SESSION['csrf'] ?? '', $tok)) {
        json_out(['ok' => false, 'error' => 'CSRF check failed. Please refresh.'], 403);
    }
}

function try_exec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // Keep bootstrap tolerant of existing schemas across MySQL versions.
    }
}

$env = load_env(__DIR__ . '/app.config');
$dsn = $env['DB_DSN'] ?? '';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? '';

if (!$dsn || !$user) {
    require __DIR__ . '/partials/setup.php';
    exit;
}

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    require __DIR__ . '/partials/db_error.php';
    exit;
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS rc_books (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rc_chapters (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  book_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  detail_html MEDIUMTEXT NULL,
  detail_text MEDIUMTEXT NULL,
  content_html MEDIUMTEXT NULL,
  content_text MEDIUMTEXT NULL,
  last_saved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX(book_id),
  CONSTRAINT fk_rc_chapters_book FOREIGN KEY (book_id) REFERENCES rc_books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rc_topics (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  book_id BIGINT UNSIGNED NULL,
  chapter_id BIGINT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  content_html MEDIUMTEXT NULL,
  content_text MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rc_chapter_topics (
  chapter_id BIGINT UNSIGNED NOT NULL,
  topic_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (chapter_id, topic_id),
  INDEX(topic_id),
  CONSTRAINT fk_rc_chapter_topics_chapter FOREIGN KEY (chapter_id) REFERENCES rc_chapters(id) ON DELETE CASCADE,
  CONSTRAINT fk_rc_chapter_topics_topic FOREIGN KEY (topic_id) REFERENCES rc_topics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

try_exec($pdo, "ALTER TABLE rc_chapters ADD COLUMN description TEXT NULL AFTER title");
try_exec($pdo, "ALTER TABLE rc_chapters ADD COLUMN detail_html MEDIUMTEXT NULL AFTER description");
try_exec($pdo, "ALTER TABLE rc_chapters ADD COLUMN detail_text MEDIUMTEXT NULL AFTER detail_html");
try_exec($pdo, "ALTER TABLE rc_topics ADD COLUMN book_id BIGINT UNSIGNED NULL AFTER id");
try_exec($pdo, "ALTER TABLE rc_topics ADD COLUMN content_html MEDIUMTEXT NULL AFTER description");
try_exec($pdo, "ALTER TABLE rc_topics ADD COLUMN content_text MEDIUMTEXT NULL AFTER content_html");

try_exec(
    $pdo,
    "UPDATE rc_chapters
     SET detail_html = CASE
         WHEN detail_html IS NULL OR detail_html = '' THEN content_html
         ELSE detail_html
     END,
     detail_text = CASE
         WHEN detail_text IS NULL OR detail_text = '' THEN content_text
         ELSE detail_text
     END"
);
try_exec(
    $pdo,
    "UPDATE rc_topics t
     INNER JOIN rc_chapters c ON c.id = t.chapter_id
     SET t.book_id = c.book_id
     WHERE t.book_id IS NULL OR t.book_id = 0"
);
try_exec(
    $pdo,
    "INSERT IGNORE INTO rc_chapter_topics (chapter_id, topic_id)
     SELECT chapter_id, id
     FROM rc_topics
     WHERE chapter_id IS NOT NULL AND chapter_id > 0"
);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    header('Cache-Control: no-store');
    $action = (string) $_POST['action'];
    csrf_check();

    try {
        if ($action === 'create_book') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            if ($title === '') {
                json_out(['ok' => false, 'error' => 'Book title required'], 400);
            }

            $st = $pdo->prepare("INSERT INTO rc_books(title, description) VALUES(?, ?)");
            $st->execute([$title, $desc ?: null]);
            $id = (int) $pdo->lastInsertId();
            json_out(['ok' => true, 'id' => $id]);
        }

        if ($action === 'create_chapter') {
            $book_id = (int) ($_POST['book_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if ($book_id <= 0) {
                json_out(['ok' => false, 'error' => 'Invalid book'], 400);
            }
            if ($title === '') {
                json_out(['ok' => false, 'error' => 'Chapter title required'], 400);
            }

            $st = $pdo->prepare("INSERT INTO rc_chapters(book_id, title, description, detail_html, detail_text, last_saved_at) VALUES(?, ?, ?, '', '', NOW())");
            $st->execute([$book_id, $title, $description ?: null]);
            $id = (int) $pdo->lastInsertId();
            json_out(['ok' => true, 'id' => $id]);
        }

        if ($action === 'save_chapter') {
            $chapter_id = (int) ($_POST['chapter_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $html = (string) ($_POST['detail_html'] ?? '');
            $text = (string) ($_POST['detail_text'] ?? '');
            if ($chapter_id <= 0) {
                json_out(['ok' => false, 'error' => 'Invalid chapter'], 400);
            }
            if ($title === '') {
                json_out(['ok' => false, 'error' => 'Chapter title required'], 400);
            }

            $st = $pdo->prepare("UPDATE rc_chapters SET title=?, description=?, detail_html=?, detail_text=?, last_saved_at=NOW() WHERE id=?");
            $st->execute([$title, $description ?: null, $html, $text, $chapter_id]);
            json_out(['ok' => true, 'saved_at' => date('Y-m-d H:i:s')]);
        }

        if ($action === 'create_topic') {
            $book_id = (int) ($_POST['book_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            if ($book_id <= 0) {
                json_out(['ok' => false, 'error' => 'Invalid book'], 400);
            }
            if ($name === '') {
                json_out(['ok' => false, 'error' => 'Topic name required'], 400);
            }

            $st = $pdo->prepare("INSERT INTO rc_topics(book_id, name, description, content_html, content_text) VALUES(?, ?, ?, '', '')");
            $st->execute([$book_id, $name, $desc ?: null]);
            $id = (int) $pdo->lastInsertId();
            json_out(['ok' => true, 'id' => $id]);
        }

        if ($action === 'save_topic') {
            $topic_id = (int) ($_POST['topic_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $html = (string) ($_POST['content_html'] ?? '');
            $text = (string) ($_POST['content_text'] ?? '');
            if ($topic_id <= 0) {
                json_out(['ok' => false, 'error' => 'Invalid topic'], 400);
            }
            if ($name === '') {
                json_out(['ok' => false, 'error' => 'Topic name required'], 400);
            }

            $st = $pdo->prepare("UPDATE rc_topics SET name=?, description=?, content_html=?, content_text=? WHERE id=?");
            $st->execute([$name, $description ?: null, $html, $text, $topic_id]);
            json_out(['ok' => true, 'saved_at' => date('Y-m-d H:i:s')]);
        }

        if ($action === 'link_topic') {
            $chapter_id = (int) ($_POST['chapter_id'] ?? 0);
            $topic_id = (int) ($_POST['topic_id'] ?? 0);
            if ($chapter_id <= 0 || $topic_id <= 0) {
                json_out(['ok' => false, 'error' => 'Invalid chapter or topic'], 400);
            }

            $st = $pdo->prepare("INSERT IGNORE INTO rc_chapter_topics(chapter_id, topic_id) VALUES(?, ?)");
            $st->execute([$chapter_id, $topic_id]);
            json_out(['ok' => true]);
        }

        if ($action === 'unlink_topic') {
            $chapter_id = (int) ($_POST['chapter_id'] ?? 0);
            $topic_id = (int) ($_POST['topic_id'] ?? 0);
            if ($chapter_id <= 0 || $topic_id <= 0) {
                json_out(['ok' => false, 'error' => 'Invalid chapter or topic'], 400);
            }

            $st = $pdo->prepare("DELETE FROM rc_chapter_topics WHERE chapter_id=? AND topic_id=?");
            $st->execute([$chapter_id, $topic_id]);
            json_out(['ok' => true]);
        }

        if ($action === 'delete_topic') {
            $topic_id = (int) ($_POST['topic_id'] ?? 0);
            if ($topic_id <= 0) {
                json_out(['ok' => false, 'error' => 'Invalid topic'], 400);
            }

            $st = $pdo->prepare("DELETE FROM rc_topics WHERE id=?");
            $st->execute([$topic_id]);
            json_out(['ok' => true]);
        }

        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => 'Server error'], 500);
    }
}

$selectedBookId = (int) ($_GET['book'] ?? 0);
$selectedChapterId = (int) ($_GET['chapter'] ?? 0);
$selectedTopicId = (int) ($_GET['topic'] ?? 0);

$books = $pdo->query("SELECT id, title FROM rc_books ORDER BY updated_at DESC, id DESC")->fetchAll();

$chapter = null;
if ($selectedChapterId > 0) {
    $st = $pdo->prepare("
        SELECT c.id, c.book_id, c.title, c.description, c.detail_html, c.last_saved_at
        FROM rc_chapters c
        WHERE c.id=?
        LIMIT 1
    ");
    $st->execute([$selectedChapterId]);
    $chapter = $st->fetch() ?: null;

    if ($chapter) {
        $selectedBookId = (int) $chapter['book_id'];
    }
}

$topic = null;
if ($selectedTopicId > 0) {
    $st = $pdo->prepare("
        SELECT t.id, t.book_id, t.name, t.description, t.content_html
        FROM rc_topics t
        WHERE t.id=?
        LIMIT 1
    ");
    $st->execute([$selectedTopicId]);
    $topic = $st->fetch() ?: null;

    if ($topic && $selectedBookId <= 0) {
        $selectedBookId = (int) $topic['book_id'];
    }
}

$chapters = [];
if ($selectedBookId > 0) {
    $st = $pdo->prepare("SELECT id, title, description, last_saved_at FROM rc_chapters WHERE book_id=? ORDER BY id ASC");
    $st->execute([$selectedBookId]);
    $chapters = $st->fetchAll();
}

$topics = [];
if ($selectedBookId > 0) {
    $st = $pdo->prepare("
        SELECT t.id, t.name, t.description, t.content_html,
               EXISTS(
                   SELECT 1
                   FROM rc_chapter_topics ct
                   WHERE ct.topic_id = t.id AND ct.chapter_id = ?
               ) AS linked_to_selected
        FROM rc_topics t
        WHERE t.book_id=?
        ORDER BY t.id ASC
    ");
    $st->execute([$selectedChapterId, $selectedBookId]);
    $topics = $st->fetchAll();
}

$linkedTopics = [];
if ($selectedChapterId > 0) {
    $st = $pdo->prepare("
        SELECT t.id, t.name
        FROM rc_topics t
        INNER JOIN rc_chapter_topics ct ON ct.topic_id = t.id
        WHERE ct.chapter_id=?
        ORDER BY t.name ASC, t.id ASC
    ");
    $st->execute([$selectedChapterId]);
    $linkedTopics = $st->fetchAll();
}

$csrf = csrf_token();
