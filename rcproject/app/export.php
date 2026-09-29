<?php
// CSV of the tasks the signed-in person can see (optionally one project).
require __DIR__ . '/lib/bootstrap.php';
$me = require_login();
$sql = 'SELECT p.name AS project, t.name, u.name AS owner, t.target_date, t.effort, t.status, t.remark, t.completed_at
        FROM pd_tasks t JOIN pd_projects p ON p.id = t.project_id LEFT JOIN pd_users u ON u.id = t.owner_id WHERE 1=1';
$a = [];
if (!is_admin()) { $sql .= ' AND t.owner_id = ?'; $a[] = $me['id']; }
if (!empty($_GET['p'])) { $sql .= ' AND t.project_id = ?'; $a[] = (int)$_GET['p']; }
$rows = q($sql . ' ORDER BY p.name, t.target_date', $a)->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="projectdesk_tasks_' . date('Ymd') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel opens UTF-8 correctly
fputcsv($out, ['Project', 'Task', 'Owner', 'Target Date', 'Effort (h)', 'Status', 'Overdue', 'Remark', 'Completed On']);
foreach ($rows as $r) {
    fputcsv($out, [$r['project'], $r['name'], $r['owner'], $r['target_date'], $r['effort'], $r['status'],
        ($r['status'] !== 'Completed' && $r['target_date'] < today()) ? 'Yes' : 'No', $r['remark'], $r['completed_at']]);
}
