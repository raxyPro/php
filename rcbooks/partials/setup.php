<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>rcnotes - Setup</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:40px;color:#111}
    code,pre{background:#f6f8fa;padding:10px;border-radius:10px;display:block;overflow:auto}
    .card{max-width:900px;border:1px solid #e5e7eb;border-radius:16px;padding:22px}
    .muted{color:#6b7280}
  </style>
</head>
<body>
  <div class="card">
    <h2>rcnotes.php - setup required</h2>
    <p class="muted">Create <b>app.config</b> in the same folder as rcnotes.php and add:</p>
    <pre><code>DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=stockdata;charset=utf8mb4"
DB_USER=rax
DB_PASS=512</code></pre>
    <p class="muted">Reload after creating the file.</p>
  </div>
</body>
</html>
