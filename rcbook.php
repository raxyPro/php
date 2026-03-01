<?php
/**
 * rcnotes.php — Single-page “Book → Chapter → Topic” notes app (MySQL + rich editor + autosave)
 *
 * ✅ Model: Book -> Chapter (rich content) -> Topic (name + description only)
 * ✅ Rich editor: Quill (bold/italic/underline, headings, lists, code, links, images, etc.)
 * ✅ Autosave: every 60 seconds (and also on Ctrl+S)
 * ✅ Clean UI: left nav (books/chapters/topics), main editor (chapter), topics panel (list + add)
 *
 * ---------------------------
 * CONFIG
 * ---------------------------
 * Create a file in SAME folder named: app.config
 * Put your config exactly like you shared:
 *
 * DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=stockdata;charset=utf8mb4"
 * DB_USER=rax
 * DB_PASS=512
 *
 * (Other MAIL/IMAP/SMTP lines can remain, they will be ignored by this app.)
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();

/** --------- Helpers --------- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function json_out(array $data, int $code = 200): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Minimal .env parser supporting:
 * KEY=value
 * KEY="value"
 * comments (# ...)
 */
function load_env(string $path): array {
  if (!is_file($path)) return [];
  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  $env = [];
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;
    $k = trim(substr($line, 0, $pos));
    $v = trim(substr($line, $pos + 1));
    if ($v !== '' && $v[0] === '"' && str_ends_with($v, '"')) {
      $v = substr($v, 1, -1);
    }
    $env[$k] = $v;
  }
  return $env;
}

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['csrf'];
}

function csrf_check(): void {
  $tok = $_POST['csrf'] ?? '';
  if (!$tok || !hash_equals($_SESSION['csrf'] ?? '', $tok)) {
    json_out(['ok' => false, 'error' => 'CSRF check failed. Please refresh.'], 403);
  }
}

/** --------- DB --------- */
$env = load_env(__DIR__ . '/app.config');
$dsn = $env['DB_DSN'] ?? '';
$user = $env['DB_USER'] ?? '';
$pass = $env['DB_PASS'] ?? '';

if (!$dsn || !$user) {
  // Show a friendly config page (HTML) if not configured.
  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <title>rcnotes — Setup</title>
    <style>
      body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:40px;color:#111}
      code,pre{background:#f6f8fa;padding:10px;border-radius:10px;display:block;overflow:auto}
      .card{max-width:900px;border:1px solid #e5e7eb;border-radius:16px;padding:22px}
      .muted{color:#6b7280}
    </style>
  </head>
  <body>
    <div class="card">
      <h2>rcnotes.php — setup required</h2>
      <p class="muted">Create <b>app.config</b> in the same folder as rcnotes.php and add:</p>
      <pre><code>DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=stockdata;charset=utf8mb4"
DB_USER=rax
DB_PASS=512</code></pre>
      <p class="muted">Reload after creating the file.</p>
    </div>
  </body>
  </html>
  <?php
  exit;
}

try {
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  // Do not leak password details.
  ?>
  <!doctype html>
  <html><head><meta charset="utf-8"><title>rcnotes — DB error</title>
  <style>body{font-family:system-ui;margin:40px} .err{color:#b91c1c}</style></head>
  <body>
    <h2 class="err">Database connection failed</h2>
    <p>Please verify DB_DSN/DB_USER/DB_PASS in <b>app.config</b>.</p>
  </body></html>
  <?php
  exit;
}

/** --------- Schema (auto-create) --------- */
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
  chapter_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX(chapter_id),
  CONSTRAINT fk_rc_topics_chapter FOREIGN KEY (chapter_id) REFERENCES rc_chapters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/** --------- API actions (AJAX) --------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
  header('Cache-Control: no-store');
  $action = (string)$_POST['action'];
  csrf_check();

  try {
    if ($action === 'create_book') {
      $title = trim((string)($_POST['title'] ?? ''));
      $desc  = trim((string)($_POST['description'] ?? ''));
      if ($title === '') json_out(['ok'=>false,'error'=>'Book title required'], 400);

      $st = $pdo->prepare("INSERT INTO rc_books(title, description) VALUES(?, ?)");
      $st->execute([$title, $desc ?: null]);
      $id = (int)$pdo->lastInsertId();
      json_out(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'create_chapter') {
      $book_id = (int)($_POST['book_id'] ?? 0);
      $title = trim((string)($_POST['title'] ?? ''));
      if ($book_id <= 0) json_out(['ok'=>false,'error'=>'Invalid book'], 400);
      if ($title === '') json_out(['ok'=>false,'error'=>'Chapter title required'], 400);

      $st = $pdo->prepare("INSERT INTO rc_chapters(book_id, title, content_html, content_text, last_saved_at) VALUES(?, ?, '', '', NOW())");
      $st->execute([$book_id, $title]);
      $id = (int)$pdo->lastInsertId();
      json_out(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'save_chapter') {
      $chapter_id = (int)($_POST['chapter_id'] ?? 0);
      $html = (string)($_POST['content_html'] ?? '');
      $text = (string)($_POST['content_text'] ?? '');
      if ($chapter_id <= 0) json_out(['ok'=>false,'error'=>'Invalid chapter'], 400);

      $st = $pdo->prepare("UPDATE rc_chapters SET content_html=?, content_text=?, last_saved_at=NOW() WHERE id=?");
      $st->execute([$html, $text, $chapter_id]);
      json_out(['ok'=>true,'saved_at'=>date('Y-m-d H:i:s')]);
    }

    if ($action === 'create_topic') {
      $chapter_id = (int)($_POST['chapter_id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      $desc = trim((string)($_POST['description'] ?? ''));
      if ($chapter_id <= 0) json_out(['ok'=>false,'error'=>'Invalid chapter'], 400);
      if ($name === '') json_out(['ok'=>false,'error'=>'Topic name required'], 400);

      $st = $pdo->prepare("INSERT INTO rc_topics(chapter_id, name, description) VALUES(?, ?, ?)");
      $st->execute([$chapter_id, $name, $desc ?: null]);
      $id = (int)$pdo->lastInsertId();
      json_out(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'delete_topic') {
      $topic_id = (int)($_POST['topic_id'] ?? 0);
      if ($topic_id <= 0) json_out(['ok'=>false,'error'=>'Invalid topic'], 400);

      $st = $pdo->prepare("DELETE FROM rc_topics WHERE id=?");
      $st->execute([$topic_id]);
      json_out(['ok'=>true]);
    }

    json_out(['ok'=>false,'error'=>'Unknown action'], 400);

  } catch (Throwable $e) {
    json_out(['ok'=>false,'error'=>'Server error'], 500);
  }
}

/** --------- Page data --------- */
$selectedBookId = (int)($_GET['book'] ?? 0);
$selectedChapterId = (int)($_GET['chapter'] ?? 0);

$books = $pdo->query("SELECT id, title FROM rc_books ORDER BY updated_at DESC, id DESC")->fetchAll();

$chapters = [];
if ($selectedBookId > 0) {
  $st = $pdo->prepare("SELECT id, title, last_saved_at FROM rc_chapters WHERE book_id=? ORDER BY id ASC");
  $st->execute([$selectedBookId]);
  $chapters = $st->fetchAll();
}

$chapter = null;
$topics = [];
if ($selectedChapterId > 0) {
  $st = $pdo->prepare("
    SELECT c.id, c.book_id, c.title, c.content_html, c.last_saved_at
    FROM rc_chapters c
    WHERE c.id=?
    LIMIT 1
  ");
  $st->execute([$selectedChapterId]);
  $chapter = $st->fetch() ?: null;

  if ($chapter) {
    $selectedBookId = (int)$chapter['book_id'];
    $st2 = $pdo->prepare("SELECT id, name, description, created_at FROM rc_topics WHERE chapter_id=? ORDER BY id ASC");
    $st2->execute([$selectedChapterId]);
    $topics = $st2->fetchAll();

    // Make sure chapters list matches the chapter’s book
    $st3 = $pdo->prepare("SELECT id, title, last_saved_at FROM rc_chapters WHERE book_id=? ORDER BY id ASC");
    $st3->execute([$selectedBookId]);
    $chapters = $st3->fetchAll();
  }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>rcnotes</title>

  <!-- Quill -->
  <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">

  <style>
    :root{
      --bg:#0b1220;
      --card:#0f1a2e;
      --card2:#0d1729;
      --text:#e6edf7;
      --muted:#9fb0cc;
      --line:rgba(255,255,255,.08);
      --accent:#5dd6ff;
      --good:#22c55e;
      --warn:#f59e0b;
      --bad:#ef4444;
      --shadow:0 10px 30px rgba(0,0,0,.35);
      --radius:16px;
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;
      background:radial-gradient(1200px 700px at 20% -10%, rgba(93,214,255,.14), transparent 55%),
                 radial-gradient(900px 600px at 90% 10%, rgba(34,197,94,.10), transparent 55%),
                 var(--bg);
      color:var(--text);
    }
    a{color:inherit;text-decoration:none}
    .app{
      display:grid;
      grid-template-columns: 320px 1fr 360px;
      height:100vh;
      gap:14px;
      padding:14px;
    }
    .panel{
      background:linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
      border:1px solid var(--line);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      overflow:hidden;
      display:flex;
      flex-direction:column;
      min-height:0;
    }
    .hdr{
      padding:14px 14px 10px 14px;
      border-bottom:1px solid var(--line);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
    }
    .hdr h3{margin:0;font-size:14px;letter-spacing:.4px;text-transform:uppercase;color:var(--muted)}
    .body{padding:12px;overflow:auto;min-height:0}
    .btn{
      background:rgba(93,214,255,.12);
      color:var(--text);
      border:1px solid rgba(93,214,255,.30);
      padding:8px 10px;
      border-radius:12px;
      cursor:pointer;
      font-weight:600;
      font-size:13px;
    }
    .btn:hover{filter:brightness(1.1)}
    .btn2{
      background:rgba(255,255,255,.06);
      border:1px solid var(--line);
    }
    .btnDanger{
      background:rgba(239,68,68,.12);
      border:1px solid rgba(239,68,68,.35);
    }
    .row{display:flex;gap:10px;align-items:center}
    .field{
      width:100%;
      background:rgba(255,255,255,.06);
      border:1px solid var(--line);
      border-radius:12px;
      padding:10px 12px;
      color:var(--text);
      outline:none;
    }
    textarea.field{min-height:90px;resize:vertical}
    .list{display:flex;flex-direction:column;gap:8px}
    .item{
      padding:10px 12px;
      border-radius:14px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.04);
    }
    .item:hover{background:rgba(255,255,255,.06)}
    .item .t{font-weight:700}
    .item .s{color:var(--muted);font-size:12px;margin-top:4px}
    .pill{
      display:inline-flex;align-items:center;gap:8px;
      padding:6px 10px;border-radius:999px;
      border:1px solid var(--line);
      background:rgba(255,255,255,.05);
      color:var(--muted);
      font-size:12px;
    }
    .muted{color:var(--muted)}
    .split{display:flex;flex-direction:column;gap:12px}
    .editorWrap{
      background:rgba(255,255,255,.04);
      border:1px solid var(--line);
      border-radius:var(--radius);
      overflow:hidden;
      min-height:0;
      display:flex;
      flex-direction:column;
    }
    .topbar{
      padding:10px 12px;
      border-bottom:1px solid var(--line);
      display:flex;justify-content:space-between;align-items:center;gap:10px;
    }
    .titleBig{font-size:18px;font-weight:800;margin:0}
    .status{font-size:12px;color:var(--muted)}
    #editor{
      height: calc(100vh - 14px - 14px - 14px - 70px); /* approximate */
      background:#fff;
      color:#111;
    }
    /* Quill toolbar in dark wrapper */
    .ql-toolbar.ql-snow{
      border:0;
      border-bottom:1px solid rgba(0,0,0,.08);
      background:rgba(255,255,255,.96);
    }
    .ql-container.ql-snow{border:0}
    .emptyState{
      padding:18px;
      border:1px dashed var(--line);
      border-radius:var(--radius);
      color:var(--muted);
      background:rgba(255,255,255,.03);
    }
    /* Modal */
    .modalBg{
      position:fixed;inset:0;background:rgba(0,0,0,.55);
      display:none;align-items:center;justify-content:center;padding:18px;
    }
    .modal{
      width:min(720px, 100%);
      background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
      border:1px solid var(--line);
      border-radius:18px;
      box-shadow:var(--shadow);
      overflow:hidden;
    }
    .modal .hdr{border-bottom:1px solid var(--line)}
    .modal .body{padding:14px}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    @media (max-width: 1100px){
      .app{grid-template-columns: 1fr; height:auto}
      #editor{height:420px}
    }
  </style>
</head>

<body>
  <div class="app">

    <!-- LEFT: Books + Chapters -->
    <div class="panel">
      <div class="hdr">
        <h3>Books & Chapters</h3>
        <button class="btn" onclick="openModal('book')">+ Book</button>
      </div>
      <div class="body split">
        <div>
          <div class="row" style="justify-content:space-between;margin-bottom:8px">
            <div class="pill">Books</div>
            <?php if ($selectedBookId > 0): ?>
              <button class="btn btn2" onclick="openModal('chapter')">+ Chapter</button>
            <?php endif; ?>
          </div>

          <div class="list">
            <?php if (!$books): ?>
              <div class="emptyState">No books yet. Create one using <b>+ Book</b>.</div>
            <?php endif; ?>

            <?php foreach ($books as $b): ?>
              <a class="item" href="?book=<?= (int)$b['id'] ?>">
                <div class="t"><?= h($b['title']) ?></div>
                <div class="s"><?= ((int)$b['id'] === $selectedBookId) ? 'Selected' : 'Open' ?></div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div>
          <div class="row" style="justify-content:space-between;margin-bottom:8px">
            <div class="pill">Chapters</div>
            <?php if ($selectedBookId > 0): ?>
              <span class="muted">Book #<?= $selectedBookId ?></span>
            <?php endif; ?>
          </div>

          <div class="list">
            <?php if ($selectedBookId <= 0): ?>
              <div class="emptyState">Select a book to view chapters.</div>
            <?php elseif (!$chapters): ?>
              <div class="emptyState">No chapters yet. Create one using <b>+ Chapter</b>.</div>
            <?php else: ?>
              <?php foreach ($chapters as $c): ?>
                <a class="item" href="?book=<?= (int)$selectedBookId ?>&chapter=<?= (int)$c['id'] ?>">
                  <div class="t"><?= h($c['title']) ?></div>
                  <div class="s">
                    <?= ((int)$c['id'] === $selectedChapterId) ? 'Editing' : 'Open' ?>
                    <?php if (!empty($c['last_saved_at'])): ?>
                      · last saved <?= h((string)$c['last_saved_at']) ?>
                    <?php endif; ?>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>

    <!-- CENTER: Chapter editor -->
    <div class="panel">
      <div class="hdr">
        <h3>Chapter Editor</h3>
        <div class="row">
          <span class="status" id="saveStatus">Ready</span>
          <?php if ($chapter): ?>
            <button class="btn btn2" onclick="manualSave()">Save</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="body">
        <?php if (!$chapter): ?>
          <div class="emptyState">
            Select a chapter to start writing. Your chapter will autosave every 1 minute.
          </div>
        <?php else: ?>
          <div class="editorWrap">
            <div class="topbar">
              <div>
                <p class="titleBig"><?= h((string)$chapter['title']) ?></p>
                <div class="muted" style="font-size:12px">
                  Book #<?= (int)$chapter['book_id'] ?> · Chapter #<?= (int)$chapter['id'] ?>
                  <?php if (!empty($chapter['last_saved_at'])): ?>
                    · last saved <?= h((string)$chapter['last_saved_at']) ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="row">
                <span class="pill">Autosave: 60s</span>
              </div>
            </div>

            <div id="editor"></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- RIGHT: Topics (name + description only) -->
    <div class="panel">
      <div class="hdr">
        <h3>Topics</h3>
        <?php if ($chapter): ?>
          <button class="btn" onclick="openModal('topic')">+ Topic</button>
        <?php endif; ?>
      </div>
      <div class="body">
        <?php if (!$chapter): ?>
          <div class="emptyState">Select a chapter to add topics.</div>
        <?php else: ?>
          <div class="muted" style="margin-bottom:10px">
            Topics are just <b>name + description</b> under this chapter.
          </div>

          <div class="list" id="topicList">
            <?php if (!$topics): ?>
              <div class="emptyState">No topics yet. Add one using <b>+ Topic</b>.</div>
            <?php else: ?>
              <?php foreach ($topics as $t): ?>
                <div class="item" data-topic-id="<?= (int)$t['id'] ?>">
                  <div class="row" style="justify-content:space-between;gap:10px">
                    <div class="t"><?= h($t['name']) ?></div>
                    <button class="btn btnDanger" style="padding:6px 9px" onclick="deleteTopic(<?= (int)$t['id'] ?>)">Del</button>
                  </div>
                  <?php if (!empty($t['description'])): ?>
                    <div class="s"><?= nl2br(h($t['description'])) ?></div>
                  <?php else: ?>
                    <div class="s muted">No description</div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- MODALS -->
  <div class="modalBg" id="modalBg" onclick="if(event.target.id==='modalBg'){closeModal()}">
    <div class="modal" onclick="event.stopPropagation()">
      <div class="hdr">
        <h3 id="modalTitle">Modal</h3>
        <button class="btn btn2" onclick="closeModal()">Close</button>
      </div>
      <div class="body">
        <div id="modalBody"></div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
  <script>
    const CSRF = <?= json_encode($csrf) ?>;
    const SELECTED_BOOK_ID = <?= (int)$selectedBookId ?>;
    const SELECTED_CHAPTER_ID = <?= (int)$selectedChapterId ?>;

    let quill = null;
    let dirty = false;
    let saving = false;
    let autosaveTimer = null;

    function setStatus(msg, kind='muted'){
      const el = document.getElementById('saveStatus');
      if(!el) return;
      el.textContent = msg;
      el.style.color =
        kind==='good' ? '<?= "#22c55e" ?>' :
        kind==='warn' ? '<?= "#f59e0b" ?>' :
        kind==='bad'  ? '<?= "#ef4444" ?>' :
        '<?= "#9fb0cc" ?>';
    }

    async function postAction(action, payload){
      const form = new FormData();
      form.append('action', action);
      form.append('csrf', CSRF);
      for(const [k,v] of Object.entries(payload||{})){
        form.append(k, v);
      }
      const res = await fetch(location.pathname, { method:'POST', body: form });
      const data = await res.json().catch(()=>({ok:false,error:'Invalid JSON'}));
      if(!res.ok || !data.ok){
        throw new Error(data.error || 'Request failed');
      }
      return data;
    }

    function openModal(kind){
      const bg = document.getElementById('modalBg');
      const title = document.getElementById('modalTitle');
      const body = document.getElementById('modalBody');

      if(kind==='book'){
        title.textContent = 'Create Book';
        body.innerHTML = `
          <div class="grid2">
            <div>
              <div class="muted" style="font-size:12px;margin-bottom:6px">Title</div>
              <input id="bookTitle" class="field" placeholder="e.g., Agile Metrics Book">
            </div>
            <div>
              <div class="muted" style="font-size:12px;margin-bottom:6px">Description (optional)</div>
              <input id="bookDesc" class="field" placeholder="Short description">
            </div>
          </div>
          <div style="margin-top:12px" class="row">
            <button class="btn" onclick="createBook()">Create</button>
            <span class="muted" style="font-size:12px">After creating, select the book from left.</span>
          </div>
        `;
      }

      if(kind==='chapter'){
        title.textContent = 'Create Chapter';
        body.innerHTML = `
          <div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Chapter Title</div>
            <input id="chapterTitle" class="field" placeholder="e.g., Burn Down Chart">
          </div>
          <div style="margin-top:12px" class="row">
            <button class="btn" onclick="createChapter()">Create</button>
            <span class="muted" style="font-size:12px">Book #${SELECTED_BOOK_ID}</span>
          </div>
        `;
      }

      if(kind==='topic'){
        title.textContent = 'Add Topic (name + description only)';
        body.innerHTML = `
          <div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Topic Name</div>
            <input id="topicName" class="field" placeholder="e.g., Definition & Formula">
          </div>
          <div style="margin-top:10px">
            <div class="muted" style="font-size:12px;margin-bottom:6px">Topic Description</div>
            <textarea id="topicDesc" class="field" placeholder="Short explanation / key points"></textarea>
          </div>
          <div style="margin-top:12px" class="row">
            <button class="btn" onclick="createTopic()">Add</button>
            <span class="muted" style="font-size:12px">Chapter #${SELECTED_CHAPTER_ID}</span>
          </div>
        `;
      }

      bg.style.display = 'flex';
    }

    function closeModal(){
      document.getElementById('modalBg').style.display = 'none';
    }

    async function createBook(){
      const title = (document.getElementById('bookTitle').value || '').trim();
      const description = (document.getElementById('bookDesc').value || '').trim();
      if(!title){ alert('Book title required'); return; }
      try{
        await postAction('create_book', {title, description});
        location.href = location.pathname; // reload list
      }catch(e){ alert(e.message); }
    }

    async function createChapter(){
      const title = (document.getElementById('chapterTitle').value || '').trim();
      if(!title){ alert('Chapter title required'); return; }
      try{
        const out = await postAction('create_chapter', {book_id: String(SELECTED_BOOK_ID), title});
        location.href = `?book=${SELECTED_BOOK_ID}&chapter=${out.id}`;
      }catch(e){ alert(e.message); }
    }

    async function createTopic(){
      const name = (document.getElementById('topicName').value || '').trim();
      const description = (document.getElementById('topicDesc').value || '').trim();
      if(!name){ alert('Topic name required'); return; }
      try{
        await postAction('create_topic', {chapter_id: String(SELECTED_CHAPTER_ID), name, description});
        location.reload();
      }catch(e){ alert(e.message); }
    }

    async function deleteTopic(topicId){
      if(!confirm('Delete this topic?')) return;
      try{
        await postAction('delete_topic', {topic_id: String(topicId)});
        const el = document.querySelector(`[data-topic-id="${topicId}"]`);
        if(el) el.remove();
      }catch(e){ alert(e.message); }
    }

    function initEditor(){
      if(!SELECTED_CHAPTER_ID) return;

      const toolbar = [
        [{ 'header': [1, 2, 3, 4, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
        [{ 'align': [] }],
        ['blockquote', 'code-block'],
        ['link', 'image'],
        [{ 'color': [] }, { 'background': [] }],
        ['clean']
      ];

      quill = new Quill('#editor', {
        theme: 'snow',
        modules: { toolbar }
      });

      // Load initial chapter HTML from server
      const initialHtml = <?= json_encode($chapter ? (string)($chapter['content_html'] ?? '') : '') ?>;
      if(initialHtml){
        quill.clipboard.dangerouslyPasteHTML(initialHtml);
      }

      quill.on('text-change', () => {
        dirty = true;
        setStatus('Unsaved changes…', 'warn');
      });

      // Autosave every 60 seconds
      autosaveTimer = setInterval(async () => {
        if(!dirty) return;
        await saveChapter();
      }, 60000);

      // Ctrl+S
      document.addEventListener('keydown', (e) => {
        if((e.ctrlKey || e.metaKey) && e.key.toLowerCase()==='s'){
          e.preventDefault();
          manualSave();
        }
      });

      // Before unload warning if dirty
      window.addEventListener('beforeunload', (e) => {
        if(dirty){
          e.preventDefault();
          e.returnValue = '';
        }
      });

      setStatus('Ready', 'muted');
    }

    function plainTextFromQuill(){
      // Quill plain text includes trailing newline
      return (quill ? quill.getText() : '').trim();
    }

    async function saveChapter(){
      if(!quill || saving) return;
      saving = true;
      setStatus('Saving…', 'muted');

      try{
        const html = quill.root.innerHTML;
        const text = plainTextFromQuill();
        await postAction('save_chapter', {
          chapter_id: String(SELECTED_CHAPTER_ID),
          content_html: html,
          content_text: text
        });
        dirty = false;
        setStatus('Saved ✓', 'good');
      }catch(e){
        setStatus('Save failed', 'bad');
        // Keep dirty true so user won’t lose state
        console.error(e);
      }finally{
        saving = false;
      }
    }

    function manualSave(){
      saveChapter();
    }

    initEditor();
  </script>
</body>
</html>
