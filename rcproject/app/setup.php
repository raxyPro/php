<?php
// One-time installer: creates the pd_ tables and the first Admin.
// It locks itself once any user exists.
require __DIR__ . '/lib/bootstrap.php';

$msg = ''; $err = '';
try {
    db();
} catch (PDOException $e) {
    $err = 'Cannot connect to MySQL. Check config.php (host, port, database name, user, password). ' . $e->getMessage();
}
if (!$err) {
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $stmt) {
        if (preg_match('/^\s*(--.*\n\s*)*$/', $stmt)) continue;
        db()->exec($stmt);
    }
    if ((int)q('SELECT COUNT(*) FROM pd_users')->fetchColumn() > 0) {
        exit('<p style="font-family:sans-serif">ProjectDesk is already set up. <a href="login.php">Sign in</a>. (You can delete setup.php from the server.)</p>');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $name = trim((string)$_POST['name']); $email = strtolower(trim((string)$_POST['email'])); $pw = (string)$_POST['password'];
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter your name and a valid email.';
        elseif (strlen($pw) < 8) $err = 'Password must be at least 8 characters.';
        else {
            q("INSERT INTO pd_users (name, email, password_hash, role) VALUES (?,?,?,'Admin')", [$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
            $adminId = (int)db()->lastInsertId();
            if (!empty($_POST['demo'])) seed_demo($adminId);
            $_SESSION['uid'] = $adminId;
            session_regenerate_id(true);
            flash('Setup complete. Welcome to ProjectDesk!' . (!empty($_POST['demo']) ? ' Sample users can sign in with password demo1234.' : ''));
            redirect('index.php');
        }
    }
}

function seed_demo(int $admin): void
{
    $pw = password_hash('demo1234', PASSWORD_DEFAULT);
    $u = [$admin];
    foreach ([['Priya Sharma', 'priya@example.com'], ['Arjun Mehta', 'arjun@example.com'], ['Neha Kapoor', 'neha@example.com'], ['Vikram Rao', 'vikram@example.com']] as $x) {
        q("INSERT IGNORE INTO pd_users (name, email, password_hash, role) VALUES (?,?,?,'User')", [$x[0], $x[1], $pw]);
        $u[] = (int)q('SELECT id FROM pd_users WHERE email = ?', [$x[1]])->fetchColumn();
    }
    $d = function ($n) { return date('Y-m-d', strtotime("$n days")); };
    $nw = 8 - (int)date('N'); // days until next Monday
    $projects = [
        ['Client Portal Revamp', 'Self-service client portal; onboarding from 5 days to 1 day by Q4.', 0, -21, 40, [
            ['Stakeholder workshops', -14, 1, 16, 'Completed', 'Signed off'], ['UI wireframes', -5, 3, 24, 'Completed', ''],
            ['Login & KYC module', 0, 2, 40, 'In Progress', 'API from vendor pending'], ['Notifications service', -1, 4, 32, 'In Progress', 'Delayed – SMTP access'],
            ['Dashboard screens', $nw + 3, 3, 48, 'Not Started', ''], ['Production cut-over', 40, 0, 16, 'Not Started', '']]],
        ['Algo Trading Engine v2', 'Automated options execution with hard risk limits and daily P&L.', 2, -12, 55, [
            ['Port short-strangle logic', -2, 2, 30, 'Completed', ''], ['Iron-condor adjustments', 0, 4, 30, 'In Progress', ''],
            ['Backtest 2 years data', $nw + 1, 0, 20, 'Not Started', ''], ['Max-loss kill switch', $nw + 4, 4, 24, 'Not Started', ''],
            ['Broker API certification', -3, 0, 12, 'On Hold', 'Waiting for sandbox keys']]],
        ['Noida Office Setup', 'Office ready for 10 people with network and IT equipment.', 3, -30, 9, [
            ['Furniture order', -15, 3, 8, 'Completed', ''], ['Workstation installation', -2, 4, 24, 'In Progress', 'Vendor short-staffed'],
            ['Network & Wi-Fi', 0, 4, 20, 'In Progress', ''], ['Laptops & accessories', $nw + 2, 1, 12, 'Not Started', '']]],
    ];
    foreach ($projects as $p) {
        q('INSERT INTO pd_projects (name, goal, owner_id, start_date, target_date) VALUES (?,?,?,?,?)', [$p[0], $p[1], $u[$p[2]], $d($p[3]), $d($p[4])]);
        $pid = (int)db()->lastInsertId();
        foreach ($p[5] as $t) {
            q('INSERT INTO pd_tasks (project_id, name, target_date, owner_id, effort, status, remark, completed_at, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
              [$pid, $t[0], $d($t[1]), $u[$t[2]], $t[3], $t[4], $t[5], $t[4] === 'Completed' ? date('Y-m-d H:i:s') : null, $admin]);
        }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup – ProjectDesk</title><link rel="stylesheet" href="assets/style.css?v=1"></head>
<body class="auth"><form class="box" method="post">
<div class="bh">ProjectDesk · First-time setup</div><div class="bb">
<?php if ($err): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
<?php if (!$err || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<p class="muted">Tables created in database <b><?= h($CFG['db_name']) ?></b>. Create the first Admin account.</p>
<?= csrf_field() ?>
<label>Your name<input name="name" required value="<?= h($_POST['name'] ?? '') ?>"></label>
<label>Email<input name="email" type="email" required value="<?= h($_POST['email'] ?? '') ?>"></label>
<label>Password (min 8)<input name="password" type="password" minlength="8" required></label>
<label class="cb"><input type="checkbox" name="demo" value="1"> Load sample projects, tasks and 4 demo users</label>
<button class="btn pri">Create Admin &amp; start</button>
<?php endif; ?>
</div></form></body></html>
