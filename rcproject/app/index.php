<?php
// Dashboard: project cards on the left, tasks (Today / Next Week / All) on the right.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
$me = require_login();
$admin = is_admin();
$today = today();

// Next week = the coming Monday to Sunday
$nwStart = date('Y-m-d', strtotime('+' . (8 - (int)date('N')) . ' days'));
$nwEnd   = date('Y-m-d', strtotime($nwStart . ' +6 days'));

$view   = in_array($_GET['v'] ?? '', ['today', 'week', 'all'], true) ? $_GET['v'] : 'today';
$pf     = (int)($_GET['p'] ?? 0);
$uf     = $admin ? (int)($_GET['u'] ?? 0) : 0;
$search = trim((string)($_GET['q'] ?? ''));

/* ---- visible tasks: Admin = all (optionally by owner); User = only his own ---- */
$sql = 'SELECT t.*, u.name AS owner_name, p.name AS project_name
        FROM pd_tasks t JOIN pd_projects p ON p.id = t.project_id LEFT JOIN pd_users u ON u.id = t.owner_id';
$args = [];
if (!$admin)  { $sql .= ' WHERE t.owner_id = ?'; $args[] = $me['id']; }
elseif ($uf)  { $sql .= ' WHERE t.owner_id = ?'; $args[] = $uf; }
$sql .= ' ORDER BY t.target_date, t.id';
$tasks = q($sql, $args)->fetchAll();

/* ---- visible projects: Admin = all; User = projects where he has tasks ---- */
if ($admin && !$uf) {
    $projects = q('SELECT p.*, u.name AS owner_name FROM pd_projects p LEFT JOIN pd_users u ON u.id = p.owner_id ORDER BY p.target_date, p.id')->fetchAll();
} else {
    $ids = array_values(array_unique(array_map('intval', array_column($tasks, 'project_id'))));
    $projects = $ids ? q('SELECT p.*, u.name AS owner_name FROM pd_projects p LEFT JOIN pd_users u ON u.id = p.owner_id
                          WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY p.target_date, p.id', $ids)->fetchAll() : [];
}
if ($pf && !in_array($pf, array_map('intval', array_column($projects, 'id')), true)) $pf = 0;

$byProj = [];
foreach ($tasks as $t) $byProj[$t['project_id']][] = $t;

/* ---- task panel lists ---- */
$base = $pf ? ($byProj[$pf] ?? []) : $tasks;
if ($search !== '') {
    $base = array_values(array_filter($base, function ($t) use ($search) {
        return stripos($t['name'] . ' ' . $t['remark'] . ' ' . $t['project_name'], $search) !== false;
    }));
}
$open = array_filter($base, function ($t) { return $t['status'] !== 'Completed'; });
$lists = [
    'today' => array_values(array_filter($open, function ($t) use ($today) { return $t['target_date'] <= $today; })),
    'week'  => array_values(array_filter($open, function ($t) use ($nwStart, $nwEnd) { return $t['target_date'] >= $nwStart && $t['target_date'] <= $nwEnd; })),
    'all'   => $base,
];
$list = $lists[$view];

// grouping
$groups = [];
if ($view === 'today') {
    $groups['Overdue']   = array_filter($list, 'is_late');
    $groups['Due today'] = array_filter($list, function ($t) use ($today) { return $t['target_date'] === $today; });
} elseif ($view === 'week') {
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime("$nwStart +$i days"));
        $groups[date('l, d M', strtotime($d))] = array_filter($list, function ($t) use ($d) { return $t['target_date'] === $d; });
    }
} else {
    foreach ($list as $t) $groups[$t['project_name']][] = $t;
}

$all = stats($tasks);
$todayN = count(array_filter($tasks, function ($t) use ($today) { return $t['status'] !== 'Completed' && $t['target_date'] <= $today; }));

$qs = function (array $over) use ($view, $pf, $uf, $search) {
    $a = array_merge(['v' => $view, 'p' => $pf, 'u' => $uf, 'q' => $search], $over);
    return 'index.php?' . http_build_query(array_filter($a, function ($x) { return $x !== '' && $x !== 0 && $x !== null; }));
};

// hand the visible tasks to the dialog script
$GLOBALS['PD_TASKS'] = [];
foreach ($tasks as $t) {
    $GLOBALS['PD_TASKS'][$t['id']] = ['id' => (int)$t['id'], 'project_id' => (int)$t['project_id'], 'name' => $t['name'],
        'owner_id' => (int)$t['owner_id'], 'target_date' => $t['target_date'], 'effort' => (float)$t['effort'],
        'status' => $t['status'], 'remark' => (string)$t['remark']];
}
$GLOBALS['PD_PROJ'] = $pf;

page_top('Dashboard', 'dash', ['v' => $view, 'p' => $pf]);
$back = h(current_url());
?>
<div class="kpis">
  <div class="kpi"><div class="n"><?= count($projects) ?></div><div class="l">Projects</div></div>
  <div class="kpi"><div class="n"><?= $all['total'] ?></div><div class="l">Total tasks</div></div>
  <div class="kpi"><div class="n"><?= $all['done'] ?></div><div class="l">Completed</div></div>
  <div class="kpi blue"><div class="n"><?= $all['due'] ?></div><div class="l">Due (open)</div></div>
  <div class="kpi amb"><div class="n"><?= $todayN ?></div><div class="l">Today's work</div></div>
  <div class="kpi red"><div class="n"><?= $all['late'] ?></div><div class="l">Overdue</div></div>
  <div class="hello"><b><?= h($me['name']) ?></b><br><?= $admin ? ($uf ? 'Admin · viewing one owner' : 'Admin · all projects &amp; tasks') : 'Showing projects &amp; tasks assigned to you' ?></div>
</div>

<div class="split">
 <div class="left">
  <div class="ph"><h2>Projects</h2><span class="muted">(<?= count($projects) ?>)</span><span class="sp"></span>
   <?php if ($pf): ?><a class="chip" href="<?= h($qs(['p' => 0])) ?>">Filtered ✕</a><?php else: ?><span class="muted">Click a card to filter tasks</span><?php endif; ?></div>
  <div class="scroll"><div class="cards">
  <?php foreach ($projects as $p):
      $s = stats($byProj[$p['id']] ?? []);
      $left = (int)round((strtotime($p['target_date']) - strtotime($today)) / 86400);
      $risk = ($s['late'] >= 2 || ($left < 14 && $s['total'] && $s['pct'] < 60)) ? 'risk' : ($s['late'] ? 'watch' : '');
      $href = $qs(['p' => $pf == $p['id'] ? 0 : (int)$p['id']]); ?>
   <div class="pc <?= $risk ?> <?= $pf == $p['id'] ? 'on' : '' ?>" onclick="location.href='<?= h($href) ?>'">
    <div class="top"><div class="nm"><span class="rag <?= $risk ?: 'ok' ?>" title="<?= $risk ? ($risk === 'risk' ? 'At risk' : 'Watch') : 'On track' ?>"></span><?= h($p['name']) ?>
      <?php if ($admin): ?><button class="ed" title="Edit project" onclick="event.stopPropagation();openProject(PD.projects[<?= (int)$p['id'] ?>])">✎</button><?php endif; ?></div>
     <div class="meta">Start <?= fdate($p['start_date']) ?> · Target <?= fdate($p['target_date']) ?>
      <span class="<?= $left < 0 ? 'late' : '' ?>">(<?= $left >= 0 ? $left . 'd left' : -$left . 'd late' ?>)</span> · <?= h($p['owner_name'] ?? '—') ?></div>
     <?php if ($p['goal']): ?><div class="goal"><?= h($p['goal']) ?></div><?php endif; ?></div>
    <div class="stats"><div><b><?= $s['total'] ?></b><span>Total</span></div><div><b class="g"><?= $s['done'] ?></b><span>Completed</span></div>
     <div><b class="bl"><?= $s['due'] ?></b><span>Due</span></div><div><b class="<?= $s['late'] ? 'late' : '' ?>"><?= $s['late'] ?></b><span>Overdue</span></div></div>
    <div class="prog" title="<?= $s['pct'] ?>% complete"><i style="width:<?= $s['pct'] ?>%"></i></div>
   </div>
  <?php endforeach; ?>
  <?php if (!$projects): ?><div class="empty"><?= $admin ? 'No projects yet. Use <b>New Project</b> on the ribbon.' : 'No projects assigned to you yet.' ?></div><?php endif; ?>
  </div></div>
 </div>

 <div class="right">
  <form class="ph" method="get" action="index.php">
   <h2>Tasks</h2>
   <div class="vt">
    <a class="<?= $view === 'today' ? 'on' : '' ?>" href="<?= h($qs(['v' => 'today'])) ?>">Today<span class="c"><?= count($lists['today']) ?></span></a>
    <a class="<?= $view === 'week' ? 'on' : '' ?>" href="<?= h($qs(['v' => 'week'])) ?>">Next Week<span class="c"><?= count($lists['week']) ?></span></a>
    <a class="<?= $view === 'all' ? 'on' : '' ?>" href="<?= h($qs(['v' => 'all'])) ?>">All<span class="c"><?= count($lists['all']) ?></span></a>
   </div>
   <span class="muted"><?= $view === 'today' ? 'Due today + overdue' : ($view === 'week' ? h(fdate($nwStart) . ' – ' . fdate($nwEnd)) : 'All tasks') ?></span>
   <span class="sp"></span>
   <input type="hidden" name="v" value="<?= h($view) ?>"><?php if ($pf): ?><input type="hidden" name="p" value="<?= $pf ?>"><?php endif; ?>
   <?php if ($admin): ?><select name="u" onchange="this.form.submit()"><option value="">All owners</option>
    <?php foreach (q('SELECT id, name FROM pd_users ORDER BY name')->fetchAll() as $u) echo '<option value="' . $u['id'] . '"' . ($uf == $u['id'] ? ' selected' : '') . '>' . h($u['name']) . '</option>'; ?></select><?php endif; ?>
   <input name="q" placeholder="Search…" value="<?= h($search) ?>">
  </form>
  <div class="scroll np">
  <?php if (!$list): ?>
   <div class="empty m"><?= $view === 'today' ? 'Nothing due today.' : 'No tasks in this view.' ?></div>
  <?php else: ?>
   <table class="g"><thead><tr><th></th><th>Task</th><?php if ($admin): ?><th>Owner</th><?php endif; ?><th>Target Date</th><th>Effort (h)</th><th>Status</th><th>Remark</th></tr></thead><tbody>
   <?php $n = 0; foreach ($groups as $g => $rows): if (!$rows) continue; ?>
    <tr class="gh"><td colspan="<?= $admin ? 7 : 6 ?>"><?= h($g) ?> (<?= count($rows) ?>)</td></tr>
    <?php foreach ($rows as $t): $n++;
        $late = is_late($t); $cls = $late ? 'late' : ($t['target_date'] === $today && $t['status'] !== 'Completed' ? 'today-t' : ''); ?>
    <tr>
     <td class="id"><?= $n ?></td>
     <td><div class="tn" onclick="openTask(PD.tasks[<?= (int)$t['id'] ?>])"><?= h($t['name']) ?></div><?php if ($view !== 'all'): ?><div class="pj"><?= h($t['project_name']) ?></div><?php endif; ?></td>
     <?php if ($admin): ?><td><?= h($t['owner_name'] ?? '—') ?></td><?php endif; ?>
     <td class="<?= $cls ?>"><?= fdate($t['target_date']) ?><?php if ($late): ?> <span class="lt">(<?= (int)round((strtotime($today) - strtotime($t['target_date'])) / 86400) ?>d late)</span><?php endif; ?></td>
     <td><?= rtrim(rtrim(number_format((float)$t['effort'], 1), '0'), '.') ?></td>
     <td><form method="post" action="api.php"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="tid" value="<?= (int)$t['id'] ?>"><input type="hidden" name="back" value="<?= $back ?>">
       <select name="status" class="<?= scls($t['status']) ?>" onchange="this.form.submit()"><?php foreach (STATUSES as $s) echo '<option' . ($s === $t['status'] ? ' selected' : '') . '>' . $s . '</option>'; ?></select></form></td>
     <td class="rm" title="<?= h($t['remark']) ?>"><?= $t['remark'] !== null && $t['remark'] !== '' ? h($t['remark']) : '<span class="muted">—</span>' ?></td>
    </tr>
    <?php endforeach; endforeach; ?>
   </tbody></table>
  <?php endif; ?>
  </div>
 </div>
</div>
<?php
page_bottom('<span>Projects: ' . count($projects) . '</span><span>Tasks: ' . $all['total'] . '</span><span class="late">Overdue: ' . $all['late'] . '</span>');
