<?php
// Page chrome: title bar, ribbon, dialogs and status bar (MS Project style).

function page_top(string $title, string $page, array $ctx = []): void
{
    global $CFG;
    $me = me(); $admin = is_admin();
    $p = (int)($ctx['p'] ?? 0);
    $v = $ctx['v'] ?? 'today';
    $link = function ($view) use ($p) { return 'index.php?' . http_build_query(array_filter(['v' => $view, 'p' => $p ?: null])); };
    $btn = function ($icon, $label, $attr, $on = false, $dis = false) {
        if ($dis) return '<span class="bL dis" title="Admin only"><span class="i">' . $icon . '</span><span>' . $label . '</span></span>';
        return '<a class="bL' . ($on ? ' on' : '') . '" ' . $attr . '><span class="i">' . $icon . '</span><span>' . $label . '</span></a>';
    };
    $grp = function ($label, $html) { return '<div class="grp"><div class="btns">' . $html . '</div><div class="gl">' . $label . '</div></div>'; };
    $flash = flash();
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> – <?= h($CFG['app_name'] ?? 'ProjectDesk') ?></title>
<link rel="stylesheet" href="assets/style.css?v=1">
</head><body>
<div class="title"><span class="app"><?= h($CFG['app_name'] ?? 'ProjectDesk') ?></span>
  <span class="doc"><?= h($title) ?></span>
  <span class="who"><?= h($me['name']) ?> <span class="role"><?= h($me['role']) ?></span></span></div>
<div class="tabs"><div class="tab file">File</div><div class="tab on">Home</div></div>
<div class="ribbon">
<?php
    echo $grp('View', $btn('▦', 'Dashboard', 'href="index.php"', $page === 'dash')
        . $btn('☺', 'Users', 'href="users.php"', $page === 'users', !$admin));
    echo $grp('Project', $btn('＋', 'New Project', 'href="#" onclick="openProject();return false"', false, !$admin)
        . ($p && $admin ? $btn('✎', 'Edit Project', 'href="#" onclick="openProject(PD.projects[' . $p . ']);return false"') : $btn('✎', 'Edit Project', '', false, true)));
    echo $grp('Task', $btn('＋', 'New Task', 'href="#" onclick="openTask();return false"', false, !$admin));
    echo $grp('Show', $btn('◷', 'Today', 'href="' . h($link('today')) . '"', $page === 'dash' && $v === 'today')
        . $btn('▤', 'Next Week', 'href="' . h($link('week')) . '"', $page === 'dash' && $v === 'week')
        . $btn('☷', 'All Tasks', 'href="' . h($link('all')) . '"', $page === 'dash' && $v === 'all'));
    echo $grp('Export', $btn('⭳', 'Export CSV', 'href="export.php' . ($p ? '?p=' . $p : '') . '"'));
    echo $grp('Account', $btn('⚿', 'My Account', 'href="account.php"', $page === 'account')
        . $btn('⏻', 'Sign Out', 'href="logout.php"'));
?>
</div>
<?php if ($flash): ?><div class="flash <?= h($flash[0]) ?>"><?= h($flash[1]) ?><span onclick="this.parentNode.remove()">✕</span></div><?php endif; ?>
<div class="main"><div class="vstrip"><?= h($title) ?></div><div class="wrap">
<?php
}

function page_bottom(string $statusHtml = ''): void
{
    $me = me(); $admin = is_admin();
    // Data for the dialogs
    if ($admin) {
        $projects = q('SELECT id, name, goal, owner_id, start_date, target_date FROM pd_projects ORDER BY name')->fetchAll();
        $users = q('SELECT id, name FROM pd_users WHERE active = 1 ORDER BY name')->fetchAll();
    } else {
        $projects = q('SELECT DISTINCT p.id, p.name, p.goal, p.owner_id, p.start_date, p.target_date
                       FROM pd_projects p JOIN pd_tasks t ON t.project_id = p.id WHERE t.owner_id = ? ORDER BY p.name', [$me['id']])->fetchAll();
        $users = [['id' => $me['id'], 'name' => $me['name']]];
    }
    $pmap = [];
    foreach ($projects as $p) $pmap[$p['id']] = $p;
    $back = h(current_url());
    ?>
</div></div>
<div class="status"><span>Ready</span><span><?= $admin ? 'Admin – full view' : 'User – my projects &amp; tasks only' ?></span><?= $statusHtml ?><span class="r">Today: <?= h(fdate(today())) ?></span></div>

<dialog id="dTask"><form method="post" action="api.php">
  <?= csrf_field() ?><input type="hidden" name="action" value="task"><input type="hidden" name="tid"><input type="hidden" name="back" value="<?= $back ?>">
  <div class="dh"><span class="dt">Task</span><span class="x" onclick="this.closest('dialog').close()">✕</span></div>
  <div class="db">
    <?php if (!$admin): ?><div class="note">You can update Status and Remark. Other fields are set by the Admin.</div><?php endif; ?>
    <label>Project</label><select name="project_id" class="full a" required><?php foreach ($projects as $p) echo '<option value="' . $p['id'] . '">' . h($p['name']) . '</option>'; ?></select>
    <label>Task name</label><input name="name" class="full a" maxlength="200" required>
    <label>Owner</label><select name="owner_id" class="a"><?php foreach ($users as $u) echo '<option value="' . $u['id'] . '">' . h($u['name']) . '</option>'; ?></select>
    <label>Target date</label><input name="target_date" type="date" class="a" required>
    <label>Effort (h)</label><input name="effort" type="number" min="0" step="0.5" class="a">
    <label>Status</label><select name="status"><?php foreach (STATUSES as $s) echo '<option>' . $s . '</option>'; ?></select>
    <label>Remark</label><textarea name="remark" class="full"></textarea>
  </div>
  <div class="df"><button class="btn pri">OK</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Cancel</button>
    <?php if ($admin): ?><button class="btn del" name="del" value="1" formnovalidate onclick="return confirm('Delete this task?')">Delete</button><?php endif; ?></div>
</form></dialog>

<?php if ($admin): ?>
<dialog id="dProject"><form method="post" action="api.php">
  <?= csrf_field() ?><input type="hidden" name="action" value="project"><input type="hidden" name="pid"><input type="hidden" name="back" value="<?= $back ?>">
  <div class="dh"><span class="dt">Project</span><span class="x" onclick="this.closest('dialog').close()">✕</span></div>
  <div class="db">
    <label>Project name</label><input name="name" class="full" maxlength="150" required>
    <label>Goal</label><textarea name="goal" class="full" placeholder="What does success look like?"></textarea>
    <label>Owner</label><select name="owner_id" class="full"><?php foreach ($users as $u) echo '<option value="' . $u['id'] . '">' . h($u['name']) . '</option>'; ?></select>
    <label>Start date</label><input name="start_date" type="date" required>
    <label>Target date</label><input name="target_date" type="date" required>
  </div>
  <div class="df"><button class="btn pri">OK</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Cancel</button>
    <button class="btn del" name="del" value="1" formnovalidate onclick="return confirm('Delete this project and ALL its tasks?')">Delete</button></div>
</form></dialog>
<?php endif; ?>

<script>
const PD = <?= js([
    'me' => (int)$me['id'], 'admin' => $admin, 'today' => today(),
    'plus7' => date('Y-m-d', strtotime('+7 days')), 'plus60' => date('Y-m-d', strtotime('+60 days')),
    'projects' => (object)$pmap, 'tasks' => (object)($GLOBALS['PD_TASKS'] ?? []), 'proj' => (int)($GLOBALS['PD_PROJ'] ?? 0),
]) ?>;
</script>
<script src="assets/app.js?v=1"></script>
</body></html>
<?php
}
