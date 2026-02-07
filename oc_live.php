<?php
$dbHost = getenv("DB_HOST") ?: "127.0.0.1";
$dbUser = getenv("DB_USER") ?: "rax";
$dbPass = getenv("DB_PASS") ?: "512";
$dbName = getenv("DB_NAME") ?: "stockdata";
$dbPort = getenv("DB_PORT") ?: "3306";
$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
$dbReady = true;
$syms = [];
$expdts = [];
$rows = [];
$lastUpd = "";
$error = "";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $syms = $pdo->query("SELECT DISTINCT sym FROM oc_live ORDER BY sym")
        ->fetchAll(PDO::FETCH_COLUMN);
    $expdts = $pdo->query("SELECT DISTINCT expdt FROM oc_live ORDER BY expdt")
        ->fetchAll(PDO::FETCH_COLUMN);

    $selSym = $_GET["sym"] ?? ($syms[0] ?? "");
    $selExp = $_GET["expdt"] ?? ($expdts[0] ?? "");
    $fCltpNz = ($_GET["cltp_nz"] ?? "") === "1";
    $fPltpNz = ($_GET["pltp_nz"] ?? "") === "1";
    $fClttNb = ($_GET["cltt_nb"] ?? "") === "1";
    $fPlttNb = ($_GET["pltt_nb"] ?? "") === "1";

    if ($selSym !== "" && $selExp !== "") {
        $where = ["sym = :sym", "expdt = :expdt"];
        $params = [
            ":sym" => $selSym,
            ":expdt" => $selExp
        ];
        if ($fCltpNz) {
            $where[] = "cltp <> 0";
        }
        if ($fPltpNz) {
            $where[] = "pltp <> 0";
        }
        if ($fClttNb) {
            $where[] = "cltt IS NOT NULL AND cltt <> ''";
        }
        if ($fPlttNb) {
            $where[] = "pltt IS NOT NULL AND pltt <> ''";
        }
        $whereSql = implode(" AND ", $where);

        $stmt = $pdo->prepare(
            "SELECT
                o.dtoe,
                o.cltt,
                (75 * o.cltp) AS ppl_c,
                75 AS lot,
                o.cltp,
                o.stk AS strike,
                o.pltp,
                (75 * o.pltp) AS ppl_p,
                o.pltt
             FROM oc_live o
             WHERE {$whereSql}
             ORDER BY o.stk"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT MAX(lst_upd) FROM oc_live WHERE sym = :sym AND expdt = :expdt"
        );
        $stmt->execute([
            ":sym" => $selSym,
            ":expdt" => $selExp
        ]);
        $lastUpd = (string)$stmt->fetchColumn();
    } else {
        $selSym = "";
        $selExp = "";
        $fCltpNz = false;
        $fPltpNz = false;
        $fClttNb = false;
        $fPlttNb = false;
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
    <title>Option Chain Live</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 16px; background: #f7f7f7; color: #222; }
        .card { background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        label { font-weight: 600; }
        select, button { padding: 6px 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 14px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: right; }
        th { background: #f0f0f0; }
        td.left, th.left { text-align: left; }
        .meta { margin-top: 8px; font-size: 12px; color: #555; }
        .error { color: #b00020; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Option Chain (Live)</h2>
        <?php if ($error !== ""): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="get" class="row">
            <div>
                <label for="sym">Symbol</label>
                <select id="sym" name="sym">
                    <?php foreach ($syms as $s): ?>
                        <option value="<?php echo htmlspecialchars($s); ?>"
                            <?php echo ($s === ($selSym ?? "")) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="expdt">Expiry</label>
                <select id="expdt" name="expdt">
                    <?php foreach ($expdts as $e): ?>
                        <option value="<?php echo htmlspecialchars($e); ?>"
                            <?php echo ($e === ($selExp ?? "")) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($e); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit">Load</button>
            </div>
            <div class="row" style="gap: 8px;">
                <label><input type="checkbox" name="cltp_nz" value="1" <?php echo $fCltpNz ? "checked" : ""; ?>> cltp non-zero</label>
                <label><input type="checkbox" name="pltp_nz" value="1" <?php echo $fPltpNz ? "checked" : ""; ?>> pltp non-zero</label>
                <label><input type="checkbox" name="cltt_nb" value="1" <?php echo $fClttNb ? "checked" : ""; ?>> cltt non-blank</label>
                <label><input type="checkbox" name="pltt_nb" value="1" <?php echo $fPlttNb ? "checked" : ""; ?>> pltt non-blank</label>
            </div>
        </form>

        <?php if (!empty($rows)): ?>
            <div class="meta">
                Rows: <?php echo count($rows); ?>
                <?php if ($lastUpd !== ""): ?>
                    | Last update: <?php echo htmlspecialchars($lastUpd); ?>
                <?php endif; ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>dtoe</th>
                        <th>cltt</th>
                        <th>ppl_c</th>
                        <th>lot</th>
                        <th>cltp</th>
                        <th>strike</th>
                        <th>pltp</th>
                        <th>ppl_p</th>
                        <th>pltt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($r["dtoe"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["cltt"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["ppl_c"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["lot"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["cltp"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["strike"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["pltp"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["ppl_p"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["pltt"] ?? "")); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($dbReady && $error === ""): ?>
            <div class="meta">No data for selected symbol/expiry.</div>
        <?php endif; ?>
    </div>
</body>
</html>
