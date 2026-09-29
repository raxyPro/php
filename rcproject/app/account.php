<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$me = require_login();
page_top('My Account', 'account');
?>
<div class="page">
 <div class="card narrow"><h3>My Account</h3><div class="bd">
  <p><b><?= h($me['name']) ?></b><br><span class="muted"><?= h($me['email']) ?> · <?= h($me['role']) ?></span></p>
  <form method="post" action="api.php" class="stack">
   <?= csrf_field() ?><input type="hidden" name="action" value="account"><input type="hidden" name="back" value="account.php">
   <label>Current password<input type="password" name="current" required></label>
   <label>New password (min 8)<input type="password" name="new" minlength="8" required></label>
   <label>Confirm new password<input type="password" name="confirm" minlength="8" required></label>
   <button class="btn pri">Change password</button>
  </form>
 </div></div>
</div>
<?php page_bottom();
