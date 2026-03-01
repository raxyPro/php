<?php
// rcPro Notes - single-file PHP app (SQLite version)
// --------------------------------------------------
// Setup: Place this file on a PHP 8+ server with pdo_sqlite enabled.
// The DB file will be auto-created at DB_PATH below. Notes are saved as HTML.

// ==== CONFIG ====
// Adjust credentials and database

// Detect host
$host_name = php_uname('n');
$host_name = strtolower($host_name);
if ($host_name === 'i5hp-lp') {
  // Local development settings
  define('DB_HOST', 'localhost');
  define('DB_NAME', 'rcmain');
  define('DB_USER', 'rax');
  define('DB_PASS', '512');
  define('APP_TIMEZONE', 'Asia/Kolkata');
} elseif ($host_name === 'server270.web-hosting.com') {
  // Alternate server settings
  define('DB_HOST', 'localhost');
  define('DB_NAME', 'rcprhkdi_rcpro');
  define('DB_USER', 'rcprhkdi_rax'); // make sure uid() is a real function or replace with actual username
  define('DB_PASS', 'rcPro2025$');
  define('APP_TIMEZONE', 'Asia/Kolkata');
} else {
  // Unknown host
  die("Cannot proceed: unsupported host '$host_name'");
}



// ==== BOOTSTRAP ====
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set(APP_TIMEZONE);

function pdo(): PDO
{
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    ensureSchema($pdo);
  }
  return $pdo;
}

function ensureSchema(PDO $pdo): void
{
  $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    SQL);
}



function json_out($data, int $code = 200): void
{
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

// ==== ROUTING (AJAX API) ====
$action = $_GET['action'] ?? '';
if ($action) {
  try {
    switch ($action) {
      case 'list':
        // Order by updated_at DESC (ISO 8601 text works lexicographically)
        $stmt = pdo()->query('SELECT id, title, updated_at FROM notes ORDER BY updated_at DESC');
        json_out(['ok' => true, 'notes' => $stmt->fetchAll()]);
        break;

      case 'get':
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = pdo()->prepare('SELECT id, title, content, created_at, updated_at FROM notes WHERE id = ?');
        $stmt->execute([$id]);
        $note = $stmt->fetch();
        json_out(['ok' => true, 'note' => $note]);
        break;

      case 'save':
        // POST: {id?, title, content}
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        $title = trim($payload['title'] ?? '') ?: 'Untitled';
        $content = (string) ($payload['content'] ?? ''); // HTML
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;

        if ($id > 0) {
          $stmt = pdo()->prepare('UPDATE notes SET title = ?, content = ? WHERE id = ?');
          $stmt->execute([$title, $content, $id]);
        } else {
          $stmt = pdo()->prepare('INSERT INTO notes (title, content) VALUES (?, ?)');
          $stmt->execute([$title, $content]);
          $id = (int) pdo()->lastInsertId();
        }

        $stmt = pdo()->prepare('SELECT id, title, content, created_at, updated_at FROM notes WHERE id = ?');
        $stmt->execute([$id]);
        $note = $stmt->fetch();
        json_out(['ok' => true, 'note' => $note]);
        break;

      case 'delete':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id > 0) {
          $stmt = pdo()->prepare('DELETE FROM notes WHERE id = ?');
          $stmt->execute([$id]);
        }
        json_out(['ok' => true]);
        break;

      default:
        json_out(['ok' => false, 'error' => 'Unknown action'], 400);
    }
  } catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
  }
}

// ==== UI (SPA-style) ====
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>rcPro Notes</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    :root {
      --sidebar-w: 320px;
    }

    body {
      font-family: 'Inter', system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
    }

    .appbar {
      height: 48px;
    }

    .appbar .title {
      font-weight: 600;
    }

    .notes-wrap {
      height: calc(100vh - 48px);
    }

    .sidebar {
      width: var(--sidebar-w);
      border-right: 1px solid #e9ecef;
      overflow: auto;
    }

    .note-item {
      cursor: pointer;
    }

    .note-item.active {
      background: #f1f3f5;
    }

    .editor {
      overflow: hidden;
    }

    #editorArea {
      height: calc(100vh - 48px - 56px - 56px);
      overflow: auto;
      border: 1px solid #e9ecef;
      border-radius: .5rem;
      padding: 1rem;
    }

    [contenteditable="true"]:empty:before {
      content: attr(data-placeholder);
      color: #adb5bd;
    }

    .toolbar button {
      border: 1px solid #e9ecef;
    }

    .footer {
      font-size: .85rem;
      color: #6c757d;
    }

    .note-title {
      font-size: 1rem;
      font-weight: 600;
    }

    /* smaller than form-control-lg */
    .note-meta {
      font-size: .85rem;
      color: #6c757d;
    }

    #editorArea {
      height: calc(100vh - 48px - 56px - 76px);
    }

    /* room for the meta row */
    blockquote {
      border-left: 4px solid #dee2e6;
      margin: .5rem 0;
      padding: .25rem .75rem;
      color: #6c757d;
    }

    pre {
      background: #f8f9fa;
      padding: .5rem .75rem;
      border-radius: .375rem;
      overflow: auto;
    }
  </style>
</head>

<body>
  <!-- Top bar: title + time (one line simple bar) -->
  <div class="appbar d-flex align-items-center px-3 border-bottom bg-light">
    <div class="title flex-grow-1">rcPro Notes <span class="text-secondary" id="clock"></span></div>
    <div class="d-flex gap-2">
      <button id="newBtn" class="btn btn-outline-secondary btn-sm" title="New note">New</button>
      <button id="saveBtn" class="btn btn-primary btn-sm" title="Save (Ctrl/Cmd+S)">Save</button>
    </div>
  </div>

  <div class="notes-wrap d-flex">
    <!-- Left: notes list -->
    <aside class="sidebar p-2">
      <div class="input-group input-group-sm mb-2">
        <span class="input-group-text">🔎</span>
        <input id="search" type="text" class="form-control" placeholder="Search notes...">
      </div>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-semibold">Notes</div>
        <span class="text-secondary" id="countLabel"></span>
      </div>
      <div id="list" class="list-group list-group-flush"></div>
    </aside>

    <!-- Right: editor always ready -->
    <main class="editor flex-grow-1 p-3">
      <div class="mb-1 d-flex align-items-center gap-3">
        <input id="titleInput" class="form-control note-title" placeholder="Note title" />
        <div class="note-meta" id="noteMeta">ID: — • 0 words • 0 lines</div>
      </div>

      <div class="toolbar btn-group mb-2" role="group">
        <button class="btn btn-sm" data-cmd="bold" title="Bold"><strong>B</strong></button>
        <button class="btn btn-sm" data-cmd="italic" title="Italic"><em>I</em></button>
        <button class="btn btn-sm" data-cmd="underline" title="Underline"><u>U</u></button>
        <button class="btn btn-sm" data-cmd="insertUnorderedList" title="Bulleted list">• List</button>
        <button class="btn btn-sm" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
        <button class="btn btn-sm" data-cmd="formatBlock" data-value="h1" title="Heading 1">H1</button>
        <button class="btn btn-sm" data-cmd="formatBlock" data-value="h2" title="Heading 2">H2</button>
        <button class="btn btn-sm" data-cmd="formatBlock" data-value="h3" title="Heading 3">H3</button>
        <button class="btn btn-sm" data-cmd="formatBlock" data-value="blockquote" title="Blockquote">❝</button>
        <button class="btn btn-sm" id="codeBtn" title="Code block">{ }</button>
        <button class="btn btn-sm" id="linkBtn" title="Insert link">🔗</button>
        <button class="btn btn-sm" data-cmd="removeFormat" title="Clear">Clear</button>
      </div>

      <div id="editorArea" contenteditable="true" data-placeholder="Start typing... (rich text supported)"></div>
      <div class="d-flex justify-content-between align-items-center mt-2 footer">
        <div id="status">Ready</div>
        <div>
          <button id="deleteBtn" class="btn btn-outline-danger btn-sm" disabled>Delete</button>
        </div>
      </div>
    </main>
  </div>

  <script>

    function monthShort(n) { return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][n] }
    function formatListDate(mysqlDate) {
      const d = new Date(mysqlDate.replace(' ', 'T'));
      const dd = String(d.getDate()).padStart(2, '0');
      const MMM = monthShort(d.getMonth());
      const hh = String(d.getHours()).padStart(2, '0');
      const mm = String(d.getMinutes()).padStart(2, '0');
      const ss = String(d.getSeconds()).padStart(2, '0');
      return `${dd}-${MMM} ${hh}:${mm}:${ss}`;
    }

    const metaEl = document.getElementById('noteMeta');
    function updateStats() {
      const text = editorEl.textContent || '';
      const words = (text.trim().match(/\S+/g) || []).length;
      const lines = (text.replace(/\r/g, '').split(/\n/).length);
      metaEl.textContent = `ID: ${current.id || '—'} • ${words} words • ${lines} lines`;
    }

    editorEl.addEventListener('input', updateStats);
    titleEl.addEventListener('input', updateStats);


    const listEl = document.getElementById('list');
    const searchEl = document.getElementById('search');
    const titleEl = document.getElementById('titleInput');
    const editorEl = document.getElementById('editorArea');
    const saveBtn = document.getElementById('saveBtn');
    const newBtn = document.getElementById('newBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const countLabel = document.getElementById('countLabel');
    const statusEl = document.getElementById('status');

    let current = { id: 0, title: '', content: '' };
    let allNotes = [];

    function tickClock() {
      const now = new Date();
      const pad = n => String(n).padStart(2, '0');
      document.getElementById('clock').textContent = `(${pad(now.getHours())}:${pad(now.getMinutes())})`;
    }
    setInterval(tickClock, 1000); tickClock();

    async function api(path, options = {}) {
      const res = await fetch(path, Object.assign({ headers: { 'Content-Type': 'application/json' } }, options));
      let data = null;
      try { data = await res.json(); } catch { }
      if (!res.ok || (data && data.ok === false)) {
        const msg = (data && data.error) ? data.error : `HTTP ${res.status}`;
        console.error('API error:', msg, data);
        throw new Error(msg);
      }
      return data;
    }
    function renderList(filter = '') {
      const f = filter.trim().toLowerCase();
      listEl.innerHTML = '';
      const filtered = allNotes.filter(n => !f || n.title.toLowerCase().includes(f));
      countLabel.textContent = `${filtered.length}`;
      for (const n of filtered) {
        const a = document.createElement('a');
        a.className = 'list-group-item list-group-item-action note-item';
        a.dataset.id = n.id;
        a.innerHTML = `
      <div class="d-flex w-100 justify-content-between align-items-center">
        <div class="text-truncate">
          <span class="fw-semibold text-dark">${escapeHtml(n.title)}</span>
          <small class="text-secondary">&nbsp;#${n.id}</small>
        </div>
        <small class="text-secondary">${formatListDate(n.updated_at)}</small>
      </div>`;
        a.onclick = () => loadNote(n.id);
        if (n.id === current.id) a.classList.add('active');
        listEl.appendChild(a);
      }
    }


    function escapeHtml(str = '') {
      return str.replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[m]));
    }

    function formatTime(isoLike) {
      // SQLite datetime('now') returns "YYYY-MM-DD HH:MM:SS"
      return new Date(isoLike.replace(' ', 'T')).toLocaleString();
    }

    async function refreshList() {
      const data = await api('?action=list');
      allNotes = data.notes || [];
      renderList(searchEl.value);
    }

    function resetEditor() {
      current = { id: 0, title: '', content: '' };
      titleEl.value = '';
      editorEl.innerHTML = '';
      deleteBtn.disabled = true;
      status('Ready');
      renderList(searchEl.value);
      updateStats();
    }

    async function loadNote(id) {
      const data = await api(`?action=get&id=${id}`);
      if (data.note) {
        current = data.note;
        titleEl.value = current.title || '';
        editorEl.innerHTML = current.content || '';
        deleteBtn.disabled = false;
        status('Loaded');
        renderList(searchEl.value);
      }
      updateStats();

    }

    async function saveNote() {
      const payload = {
        id: current.id || undefined,
        title: titleEl.value.trim() || 'Untitled',
        content: editorEl.innerHTML,
      };
      status('Saving...');
      const data = await api('?action=save', { method: 'POST', body: JSON.stringify(payload) });
      current = data.note;
      deleteBtn.disabled = false;
      status('Saved');
      await refreshList();
      const active = [...document.querySelectorAll('.note-item')].find(e => +e.dataset.id === current.id);
      if (active) active.classList.add('active');
    }

    async function deleteNote() {
      if (!current.id) return;
      if (!confirm('Delete this note?')) return;
      await api(`?action=delete&id=${current.id}`);
      resetEditor();
      await refreshList();
    }

    document.querySelectorAll('.toolbar [data-cmd]').forEach(btn => {
      btn.addEventListener('click', () => {
        const cmd = btn.dataset.cmd;
        const value = btn.dataset.value || null;
        if (cmd === 'formatBlock') {
          document.execCommand(cmd, false, value);
        } else if (cmd === 'removeFormat') {
          document.execCommand('removeFormat');
        } else {
          document.execCommand(cmd, false, value);
        }
        editorEl.focus();
      });
    });

    saveBtn.onclick = saveNote;
    newBtn.onclick = resetEditor;
    deleteBtn.onclick = deleteNote;
    searchEl.oninput = () => renderList(searchEl.value);

    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        saveNote();
      }
    });

    // Extra toolbar actions
    document.getElementById('codeBtn').addEventListener('click', () => {
      document.execCommand('formatBlock', false, 'pre');   // code block
      editorEl.focus();
      updateStats();
    });
    document.getElementById('linkBtn').addEventListener('click', () => {
      const url = prompt('Enter URL');
      if (url) document.execCommand('createLink', false, url);
      editorEl.focus();
    });


    (async function init() {
      resetEditor();
      await refreshList();
    })();

    function status(msg) { statusEl.textContent = msg; }
  </script>
</body>

</html>