<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require_login();
require_admin();
$users = q("SELECT u.*,
   (SELECT COUNT(DISTINCT project_id) FROM pd_tasks WHERE owner_id = u.id) AS projects,
   (SELECT COUNT(*) FROM pd_tasks WHERE owner_id = u.id AND status <> 'Completed') AS open_tasks,
   (SELECT COUNT(*) FROM pd_tasks WHERE owner_id = u.id AND status <> 'Completed' AND target_date < CURDATE()) AS late_tasks
   FROM pd_users u ORDER BY u.active DESC, u.name")->fetchAll();
page_top('Users', 'users');
?>
<div class="page">
 <div class="bar"><h2>Users</h2><button class="btn pri" onclick="openUser()">＋ Add User</button></div>
 <table class="g"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Projects</th><th>Open tasks</th><th>Overdue</th><th></th></tr></thead><tbody>
 <?php foreach ($users as $u): ?>
  <tr class="<?= $u['active'] ? '' : 'inact' ?>"><td><b><?= h($u['name']) ?></b></td><td><?= h($u['email']) ?></td><td><?= h($u['role']) ?></td>
   <td><span class="pill <?= $u['active'] ? 'c' : 'ns' ?>"><?= $u['active'] ? 'Active' : 'Inactive' ?></span></td>
   <td><?= (int)$u['projects'] ?></td><td><?= (int)$u['open_tasks'] ?></td><td class="<?= $u['late_tasks'] ? 'late' : '' ?>"><?= (int)$u['late_tasks'] ?></td>
   <td><button class="btn" onclick='openUser(<?= js(['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'active' => (int)$u['active']]) ?>)'>Edit</button></td></tr>
 <?php endforeach; ?>
 </tbody></table>
 <p class="muted">Admin: sees and manages all projects, tasks and users. User: sees only projects where he has tasks, and only his own tasks (can update status &amp; remark).
 Users are deactivated rather than deleted so their task history stays.</p>
</div>

<dialog id="dUser"><form method="post" action="api.php">
  <?= csrf_field() ?><input type="hidden" name="action" value="user"><input type="hidden" name="uid"><input type="hidden" name="back" value="users.php">
  <div class="dh"><span class="dt">User</span><span class="x" onclick="this.closest('dialog').close()">✕</span></div>
  <div class="db">
    <label>Full name</label><input name="name" class="full" maxlength="100" required>
    <label>Email</label><input name="email" type="email" class="full" maxlength="150" required>
    <label>Role</label><select name="role"><option>User</option><option>Admin</option></select>
    <label>Status</label><select name="active"><option value="1">Active</option><option value="0">Inactive</option></select>
    <label>Password</label><input name="password" type="password" class="full" minlength="8" autocomplete="new-password">
    <div class="note pwnote"></div>
  </div>
  <div class="df"><button class="btn pri">OK</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Cancel</button></div>
</form></dialog>
<?php page_bottom('<span>Users: ' . count($users) . '</span>');
