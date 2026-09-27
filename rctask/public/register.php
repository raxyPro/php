<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (empty($CONFIG['allow_register'])) {
    http_response_code(403);
    exit('Sign-ups are closed. Set allow_register to true in config.php to add an account.');
}

$error = '';
$email = '';
$name = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $error = 'Session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($pass) < 8) {
        $error = 'Use a password of at least 8 characters.';
    } else {
        try {
            $st = db()->prepare('INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)');
            $st->execute([$email, mb_substr($name, 0, 100), password_hash($pass, PASSWORD_DEFAULT)]);
            $uid = (int)db()->lastInsertId();
            Bandwidths::save($uid, Bandwidths::defaults());
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000' ? 'An account with this email already exists.' : 'Could not create the account.';
        }
    }
}
$title = 'Create account';
require __DIR__ . '/../src/auth_layout.php';
?>
<form class="login" method="post">
  <h1>rcfamily</h1>
  <p class="muted">Create your account.</p>
  <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <label for="name">Name</label>
  <input id="name" name="name" type="text" value="<?= h($name) ?>" autofocus>
  <label for="email">Email</label>
  <input id="email" name="email" type="email" required value="<?= h($email) ?>">
  <label for="password">Password (8+ characters)</label>
  <input id="password" name="password" type="password" required minlength="8">
  <button class="btn primary" type="submit">Create account</button>
  <p class="muted small">Already registered? <a href="login.php">Sign in</a></p>
</form>
</div></body></html>
