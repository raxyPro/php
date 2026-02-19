<?php
// Keep DB config in this file only.
$DB_DSN_BASE = "mysql:host=127.0.0.1;port=3306;charset=utf8mb4";
$DB_USER = "rax";
$DB_PASS = "512";
$DEFAULT_DB = "stockdata";

function qi(string $name): string
{
    return "`" . str_replace("`", "``", $name) . "`";
}

$error = "";
$databases = [];
$tables = [];
$createStmt = "";
$indexes = [];
$selDb = "";
$selTable = "";

try {
    $pdo = new PDO($DB_DSN_BASE, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $databases = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    $selDb = (string)($_GET["db"] ?? $DEFAULT_DB);
    if (!in_array($selDb, $databases, true)) {
        $selDb = $databases[0] ?? "";
    }

    if ($selDb !== "") {
        $tables = $pdo->query("SHOW TABLES FROM " . qi($selDb))->fetchAll(PDO::FETCH_COLUMN);
    }

    $selTable = (string)($_GET["table"] ?? "");
    if ($selTable !== "" && in_array($selTable, $tables, true) && $selDb !== "") {
        $row = $pdo->query("SHOW CREATE TABLE " . qi($selDb) . "." . qi($selTable))->fetch(PDO::FETCH_NUM);
        $createStmt = (string)($row[1] ?? "");

        $idxStmt = $pdo->query("SHOW INDEX FROM " . qi($selDb) . "." . qi($selTable));
        $indexes = $idxStmt ? $idxStmt->fetchAll() : [];
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP DB Utility</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 14px; background: #f6f8fc; color: #1f2937; }
        .top { display: flex; gap: 10px; align-items: center; margin-bottom: 12px; }
        select, button { padding: 6px 8px; }
        .layout { display: grid; grid-template-columns: 280px 1fr; gap: 12px; min-height: 520px; }
        .panel { background: #fff; border: 1px solid #d5deec; border-radius: 8px; overflow: hidden; }
        .panel h3 { margin: 0; padding: 10px 12px; font-size: 14px; border-bottom: 1px solid #e6ecf6; background: #f9fbff; }
        .scroll { max-height: 640px; overflow: auto; }
        .tables a {
            display: block;
            padding: 8px 12px;
            text-decoration: none;
            color: #0b4db3;
            border-bottom: 1px solid #eef2f8;
            font-size: 13px;
        }
        .tables a:hover { background: #eef4ff; }
        .tables a.active { background: #dfeaff; font-weight: 700; color: #083d8f; }
        pre {
            margin: 0;
            padding: 12px;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 12px;
            line-height: 1.4;
            font-family: Consolas, "Courier New", monospace;
        }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #e5eaf3; padding: 6px 8px; text-align: left; }
        th { background: #f3f7ff; }
        .err { color: #b00020; font-weight: 600; margin-bottom: 10px; }
        .muted { color: #5f6b7b; padding: 12px; font-size: 13px; }
        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <h2>DB Utility</h2>
    <?php if ($error !== ""): ?>
        <div class="err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="get" class="top">
        <label for="db"><strong>Database</strong></label>
        <select id="db" name="db" onchange="this.form.submit()">
            <?php foreach ($databases as $db): ?>
                <option value="<?php echo htmlspecialchars((string)$db); ?>" <?php echo ((string)$db === $selDb) ? "selected" : ""; ?>>
                    <?php echo htmlspecialchars((string)$db); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($selTable !== ""): ?>
            <input type="hidden" name="table" value="<?php echo htmlspecialchars($selTable); ?>">
        <?php endif; ?>
        <button type="submit">Load</button>
    </form>

    <div class="layout">
        <div class="panel">
            <h3>Tables</h3>
            <div class="scroll tables">
                <?php if (!empty($tables)): ?>
                    <?php foreach ($tables as $t): ?>
                        <?php $isActive = ((string)$t === $selTable); ?>
                        <a
                            href="?db=<?php echo urlencode($selDb); ?>&table=<?php echo urlencode((string)$t); ?>"
                            class="<?php echo $isActive ? "active" : ""; ?>">
                            <?php echo htmlspecialchars((string)$t); ?>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="muted">No tables found.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <h3>Create Statement<?php echo $selTable !== "" ? " - " . htmlspecialchars($selTable) : ""; ?></h3>
            <div class="scroll">
                <?php if ($createStmt !== ""): ?>
                    <pre><?php echo htmlspecialchars($createStmt); ?></pre>
                    <h3 style="border-top: 1px solid #e6ecf6;">Indexes</h3>
                    <?php if (!empty($indexes)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Key_name</th>
                                    <th>Seq_in_index</th>
                                    <th>Column_name</th>
                                    <th>Non_unique</th>
                                    <th>Index_type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($indexes as $idx): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($idx["Key_name"] ?? "")); ?></td>
                                        <td><?php echo htmlspecialchars((string)($idx["Seq_in_index"] ?? "")); ?></td>
                                        <td><?php echo htmlspecialchars((string)($idx["Column_name"] ?? "")); ?></td>
                                        <td><?php echo htmlspecialchars((string)($idx["Non_unique"] ?? "")); ?></td>
                                        <td><?php echo htmlspecialchars((string)($idx["Index_type"] ?? "")); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="muted">No indexes found.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="muted">Select a table from the left to view its full CREATE statement.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
