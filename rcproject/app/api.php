<?php
// All form posts land here: task / status / project / user / account.
require __DIR__ . '/lib/bootstrap.php';
$me = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
csrf_check();

$back  = safe_back($_POST['back'] ?? '');
$admin = is_admin();
$P = function ($k) { return trim((string)($_POST[$k] ?? '')); };
$fail = function ($msg) use ($back) { flash($msg, 'err'); redirect($back); };

function active_user_exists(int $id): bool { return (bool)q('SELECT 1 FROM pd_users WHERE id = ? AND active = 1', [$id])->fetchColumn(); }
function set_status(int $id, string $status, ?string $remark = null): void
{
    $sql = "UPDATE pd_tasks SET status = ?, completed_at = CASE WHEN ? = 'Completed' THEN COALESCE(completed_at, NOW()) ELSE NULL END"
         . ($remark !== null ? ', remark = ?' : '') . ' WHERE id = ?';
    $args = [$status, $status];
    if ($remark !== null) $args[] = $remark;
    $args[] = $id;
    q($sql, $args);
}

try {
    switch ($_POST['action'] ?? '') {

    /* ---------- quick status change from the task list ---------- */
    case 'status':
        $id = (int)$P('tid');
        $t = q('SELECT id, owner_id, name FROM pd_tasks WHERE id = ?', [$id])->fetch();
        if (!$t) $fail('Task not found.');
        if (!$admin && (int)$t['owner_id'] !== (int)$me['id']) $fail('You can only update your own tasks.');
        $st = $P('status');
        if (!in_array($st, STATUSES, true)) $fail('Invalid status.');
        set_status($id, $st);
        flash('"' . $t['name'] . '" → ' . $st);
        break;

    /* ---------- task create / edit / delete ---------- */
    case 'task':
        $id = (int)$P('tid');
        if (!empty($_POST['del'])) {
            require_admin();
            q('DELETE FROM pd_tasks WHERE id = ?', [$id]);
            flash('Task deleted.');
            break;
        }
        $status = $P('status');
        if (!in_array($status, STATUSES, true)) $fail('Invalid status.');
        if (!$admin) { // users: status + remark on their own tasks only
            $t = q('SELECT owner_id FROM pd_tasks WHERE id = ?', [$id])->fetch();
            if (!$t || (int)$t['owner_id'] !== (int)$me['id']) $fail('You can only update your own tasks.');
            set_status($id, $status, $P('remark'));
            flash('Task updated.');
            break;
        }
        $d = ['project_id' => (int)$P('project_id'), 'name' => $P('name'), 'owner_id' => (int)$P('owner_id'),
              'target_date' => $P('target_date'), 'effort' => $P('effort') === '' ? 0 : $P('effort'), 'remark' => $P('remark')];
        if ($d['name'] === '' || mb_strlen($d['name']) > 200) $fail('Task name is required (max 200 characters).');
        if (!q('SELECT 1 FROM pd_projects WHERE id = ?', [$d['project_id']])->fetchColumn()) $fail('Please choose a project.');
        if (!active_user_exists($d['owner_id'])) $fail('Please choose an active owner.');
        if (!valid_date($d['target_date'])) $fail('Please enter a valid target date.');
        if (!is_numeric($d['effort']) || $d['effort'] < 0 || $d['effort'] > 99999) $fail('Effort must be a number of hours.');
        if ($id) {
            q('UPDATE pd_tasks SET project_id=?, name=?, owner_id=?, target_date=?, effort=?, remark=? WHERE id=?',
              [$d['project_id'], $d['name'], $d['owner_id'], $d['target_date'], $d['effort'], $d['remark'], $id]);
            set_status($id, $status);
            flash('Task updated.');
        } else {
            q('INSERT INTO pd_tasks (project_id, name, owner_id, target_date, effort, status, remark, completed_at, created_by)
               VALUES (?,?,?,?,?,?,?,' . ($status === 'Completed' ? 'NOW()' : 'NULL') . ',?)',
              [$d['project_id'], $d['name'], $d['owner_id'], $d['target_date'], $d['effort'], $status, $d['remark'], $me['id']]);
            flash('Task "' . $d['name'] . '" created.');
        }
        break;

    /* ---------- project create / edit / delete (Admin) ---------- */
    case 'project':
        require_admin();
        $id = (int)$P('pid');
        if (!empty($_POST['del'])) {
            q('DELETE FROM pd_projects WHERE id = ?', [$id]);   // tasks cascade
            flash('Project and its tasks deleted.');
            $back = 'index.php';
            break;
        }
        $d = [$P('name'), $P('goal'), (int)$P('owner_id'), $P('start_date'), $P('target_date')];
        if ($d[0] === '' || mb_strlen($d[0]) > 150) $fail('Project name is required (max 150 characters).');
        if (!active_user_exists($d[2])) $fail('Please choose an active owner.');
        if (!valid_date($d[3]) || !valid_date($d[4])) $fail('Please enter valid start and target dates.');
        if ($d[4] < $d[3]) $fail('Target date cannot be before the start date.');
        if ($id) {
            q('UPDATE pd_projects SET name=?, goal=?, owner_id=?, start_date=?, target_date=? WHERE id=?', array_merge($d, [$id]));
            flash('Project updated.');
        } else {
            q('INSERT INTO pd_projects (name, goal, owner_id, start_date, target_date) VALUES (?,?,?,?,?)', $d);
            flash('Project "' . $d[0] . '" created. Add tasks with New Task.');
            $back = 'index.php?p=' . db()->lastInsertId();
        }
        break;

    /* ---------- user create / edit (Admin) ---------- */
    case 'user':
        require_admin();
        $id = (int)$P('uid');
        $name = $P('name'); $email = strtolower($P('email')); $role = $P('role') === 'Admin' ? 'Admin' : 'User';
        $active = $P('active') === '0' ? 0 : 1; $pw = (string)($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $fail('Name and a valid email are required.');
        if (q('SELECT 1 FROM pd_users WHERE email = ? AND id <> ?', [$email, $id])->fetchColumn()) $fail('That email is already used by another user.');
        if ($pw !== '' && strlen($pw) < 8) $fail('Password must be at least 8 characters.');
        if ($id) {
            if ($role !== 'Admin' || !$active) {
                $others = q("SELECT COUNT(*) FROM pd_users WHERE role='Admin' AND active=1 AND id <> ?", [$id])->fetchColumn();
                if (!$others) $fail('At least one active Admin is required.');
            }
            q('UPDATE pd_users SET name=?, email=?, role=?, active=? WHERE id=?', [$name, $email, $role, $active, $id]);
            if ($pw !== '') q('UPDATE pd_users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            flash('User updated.' . ($pw !== '' ? ' Password reset.' : ''));
        } else {
            if ($pw === '') $fail('Set an initial password for the new user.');
            q('INSERT INTO pd_users (name, email, password_hash, role, active) VALUES (?,?,?,?,?)',
              [$name, $email, password_hash($pw, PASSWORD_DEFAULT), $role, $active]);
            flash('User "' . $name . '" added. Share the email and password with them.');
        }
        break;

    /* ---------- change own password ---------- */
    case 'account':
        $row = q('SELECT password_hash FROM pd_users WHERE id = ?', [$me['id']])->fetch();
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), $row['password_hash'])) $fail('Current password is incorrect.');
        if (strlen($new) < 8) $fail('New password must be at least 8 characters.');
        if ($new !== (string)($_POST['confirm'] ?? '')) $fail('New passwords do not match.');
        q('UPDATE pd_users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        flash('Password changed.');
        break;

    default:
        $fail('Unknown action.');
    }
} catch (PDOException $e) {
    error_log('ProjectDesk: ' . $e->getMessage());
    $fail('Database error – the change was not saved.');
}
redirect($back);
