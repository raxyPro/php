<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (current_user_id() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expired. Please try again.';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $st->execute([$email]);
        $u = $st->fetch();
        if ($u && password_verify($pass, $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            header('Location: index.php');
            exit;
        }
        $error = 'Email or password is not correct.';
    }
}
$hasUsers = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
$title = 'Sign in';
require __DIR__ . '/../src/auth_layout.php';
?>
<form class="login" method="post" autocomplete="on">
  <h1>rcfamily</h1>
  <p class="muted">Sign in to your tasks.</p>
  <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <label for="email">Email</label>
  <input id="email" name="email" type="email" required value="<?= h($email) ?>" autofocus>
  <label for="password">Password</label>
  <input id="password" name="password" type="password" required>
  <button class="btn primary" type="submit">Sign in</button>
  <?php if (!empty($CONFIG['allow_register'])): ?>
    <p class="muted small"><?= $hasUsers ? 'New here?' : 'No account yet.' ?> <a href="register.php">Create an account</a></p>
  <?php endif; ?>
</form>
</div></body></html>
