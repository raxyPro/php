<?php
require __DIR__ . '/lib/bootstrap.php';
try {
    if (!(int)q('SELECT COUNT(*) FROM pd_users')->fetchColumn()) redirect('setup.php');
} catch (PDOException $e) { redirect('setup.php'); }
if (me()) redirect('index.php');

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = q('SELECT id, password_hash, active FROM pd_users WHERE email = ?', [strtolower(trim((string)($_POST['email'] ?? '')))])->fetch();
    if ($u && (int)$u['active'] === 1 && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            q('UPDATE pd_users SET password_hash = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $u['id']]);
        }
        redirect('index.php');
    }
    sleep(1); // slow down guessing
    $err = 'Wrong email or password, or the account is inactive.';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in – ProjectDesk</title><link rel="stylesheet" href="assets/style.css?v=1"></head>
<body class="auth"><form class="box" method="post">
<div class="bh">ProjectDesk</div><div class="bb">
<h1>Sign in</h1>
<?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
<?= csrf_field() ?>
<label>Email<input name="email" type="email" required autofocus value="<?= h($_POST['email'] ?? '') ?>"></label>
<label>Password<input name="password" type="password" required></label>
<button class="btn pri">Sign in</button>
<p class="muted">Forgot your password? Ask your Admin to reset it.</p>
</div></form></body></html>
