<?php
$dbHost = getenv("DB_HOST") ?: "127.0.0.1";
$dbUser = getenv("DB_USER") ?: "rax";
$dbPass = getenv("DB_PASS") ?: "512";
$dbName = getenv("DB_NAME") ?: "stockdata";
$dbPort = getenv("DB_PORT") ?: "3306";
$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
$dbReady = true;
$dates = [];
$conts = [];
$symbols = [];
$contsBySym = [];
$rows = [];
$lastUpd = "";
$error = "";
$notice = "";
$tTypeOptions = [
    "None" => "None",
    "entry" => "entry",
    "exit" => "exit"
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_ttype"])) {
        $isAjax = (($_POST["ajax"] ?? "") === "1")
            || (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest")
            || (strpos($_SERVER["HTTP_ACCEPT"] ?? "", "application/json") !== false);
        $updId = $_POST["id"] ?? "";
        $updType = $_POST["ttype"] ?? "None";
        if ($updType !== "entry" && $updType !== "exit") {
            $updType = "None";
        }
        $updGroup = $_POST["tgroup"] ?? "";
        if ($updId !== "") {
            $stmt = $pdo->prepare("UPDATE ictradefo SET tType = :ttype, tGroup = :tgroup WHERE id = :id");
            $stmt->execute([
                ":ttype" => $updType,
                ":tgroup" => $updGroup,
                ":id" => $updId
            ]);
            $notice = "Saved tType for id " . htmlspecialchars((string)$updId);
            if ($isAjax) {
                header("Content-Type: application/json; charset=utf-8");
                echo json_encode([
                    "ok" => true,
                    "id" => $updId,
                    "tType" => $updType,
                    "tGroup" => $updGroup
                ]);
                exit;
            }
        } elseif ($isAjax) {
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode([
                "ok" => false,
                "error" => "Missing id"
            ]);
            exit;
        }
    }

    $dates = $pdo->query("SELECT DISTINCT tdate FROM ictradefo ORDER BY tdate DESC")
        ->fetchAll(PDO::FETCH_COLUMN);
    $conts = $pdo->query("SELECT DISTINCT cont FROM ictradefo ORDER BY cont")
        ->fetchAll(PDO::FETCH_COLUMN);

    $selDate = $_GET["tdate"] ?? "";
    $selSym = $_GET["sym"] ?? "";
    $selCont = $_GET["cont"] ?? "";
    $showChanges = ($_GET["show_changes"] ?? "") === "1";

    foreach ($conts as $cont) {
        $parts = explode("-", $cont);
        $sym = $parts[1] ?? $cont;
        if (!isset($contsBySym[$sym])) {
            $contsBySym[$sym] = [];
            $symbols[] = $sym;
        }
        $contsBySym[$sym][] = $cont;
    }
    sort($symbols);
    if ($selSym === "" && !empty($symbols)) {
        $selSym = $symbols[0];
    }

    $where = [];
    $params = [];
    if ($selDate !== "") {
        $where[] = "tdate = :tdate";
        $params[":tdate"] = $selDate;
    }
    if ($selCont !== "") {
        $where[] = "cont = :cont";
        $params[":cont"] = $selCont;
    } elseif ($selSym !== "" && $selSym !== "All") {
        $where[] = "cont LIKE :sym_like";
        $params[":sym_like"] = "%-" . $selSym . "-%";
    }
    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

    $stmt = $pdo->prepare(
        "SELECT
            id,
            tdate,
            cont,
            exg,
            act,
            qty,
            ltp,
            avg,
            val,
            oref,
            stt,
            trnch,
            sduty,
            sebitc,
            brk,
            stb,
            totch,
            created_at,
            tType,
            tGroup
         FROM ictradefo
         {$whereSql}
         ORDER BY id DESC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT MAX(created_at) FROM ictradefo");
    $lastUpd = (string)$stmt->fetchColumn();
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders</title>
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
        <h2>Orders</h2>
        <?php if ($error !== ""): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($notice !== ""): ?>
            <div class="meta"><?php echo $notice; ?></div>
        <?php endif; ?>

        <form method="get" class="row">
            <div>
                <label for="tdate">Date</label>
                <select id="tdate" name="tdate">
                    <option value="">All</option>
                    <?php foreach ($dates as $d): ?>
                        <option value="<?php echo htmlspecialchars($d); ?>"
                            <?php echo ($d === ($selDate ?? "")) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($d); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="sym">Symbol</label>
                <select id="sym" name="sym">
                    <option value="All" <?php echo ($selSym === "All") ? "selected" : ""; ?>>All</option>
                    <?php foreach ($symbols as $s): ?>
                        <option value="<?php echo htmlspecialchars($s); ?>"
                            <?php echo ($s === ($selSym ?? "")) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="cont">Contract</label>
                <select id="cont" name="cont">
                    <option value="">All</option>
                    <?php if ($selSym === "All"): ?>
                        <?php foreach ($conts as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>"
                                <?php echo ($c === ($selCont ?? "")) ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($c); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach (($contsBySym[$selSym] ?? []) as $c): ?>
                            <option value="<?php echo htmlspecialchars($c); ?>"
                                <?php echo ($c === ($selCont ?? "")) ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($c); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <button type="submit">Load</button>
            </div>
            <div class="row" style="gap: 8px;">
                <label>
                    <input type="checkbox" name="show_changes" value="1" <?php echo $showChanges ? "checked" : ""; ?>>
                    Show changes fields
                </label>
            </div>
        </form>

        <?php if (!empty($rows)): ?>
            <div class="meta">
                Rows: <?php echo count($rows); ?>
                <?php if ($lastUpd !== ""): ?>
                    | Last update: <?php echo htmlspecialchars($lastUpd); ?>
                <?php endif; ?>
            </div>
            <table id="orders-table">
                <thead>
                    <tr>
                        <th class="left">id</th>
                        <th class="left">tdate</th>
                        <th class="left">cont</th>
                        <th>exg</th>
                        <th>act</th>
                        <th>qty</th>
                        <th>ltp</th>
                        <th>avg</th>
                        <th>val</th>
                        <th class="left">oref</th>
                        <th class="left">tType</th>
                        <?php if ($showChanges): ?>
                            <th>stt</th>
                            <th>trnch</th>
                            <th>sduty</th>
                            <th>sebitc</th>
                            <th>brk</th>
                            <th>stb</th>
                            <th>totch</th>
                            <th class="left">created_at</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="left"><?php echo htmlspecialchars((string)($r["id"] ?? "")); ?></td>
                            <td class="left"><?php echo htmlspecialchars((string)($r["tdate"] ?? "")); ?></td>
                            <td class="left"><?php echo htmlspecialchars((string)($r["cont"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["exg"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["act"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["qty"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["ltp"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["avg"] ?? "")); ?></td>
                            <td><?php echo htmlspecialchars((string)($r["val"] ?? "")); ?></td>
                            <td class="left"><?php echo htmlspecialchars((string)($r["oref"] ?? "")); ?></td>
                            <td class="left">
                                <form method="post" class="row ttype-form" style="gap: 6px;">
                                    <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)($r["id"] ?? "")); ?>">
                                    <input type="hidden" name="tdate" value="<?php echo htmlspecialchars($selDate); ?>">
                                    <input type="hidden" name="sym" value="<?php echo htmlspecialchars($selSym); ?>">
                                    <input type="hidden" name="cont" value="<?php echo htmlspecialchars($selCont); ?>">
                                    <input type="hidden" name="show_changes" value="<?php echo $showChanges ? "1" : "0"; ?>">
                                    <input type="hidden" name="ajax" value="1">
                                    <select name="ttype">
                                        <?php
                                            $curType = (string)($r["tType"] ?? "None");
                                            if ($curType !== "entry" && $curType !== "exit") {
                                                $curType = "None";
                                            }
                                        ?>
                                        <?php foreach ($tTypeOptions as $key => $label): ?>
                                            <option value="<?php echo htmlspecialchars($key); ?>"
                                                <?php echo ($key === $curType) ? "selected" : ""; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="tgroup" value="<?php echo htmlspecialchars((string)($r["tGroup"] ?? "")); ?>" placeholder="tGroup">
                                    <button type="submit" name="save_ttype" value="1">Save</button>
                                    <span class="save-status" style="font-size:12px;color:#555;"></span>
                                </form>
                            </td>
                            <?php if ($showChanges): ?>
                                <td><?php echo htmlspecialchars((string)($r["stt"] ?? "")); ?></td>
                                <td><?php echo htmlspecialchars((string)($r["trnch"] ?? "")); ?></td>
                                <td><?php echo htmlspecialchars((string)($r["sduty"] ?? "")); ?></td>
                                <td><?php echo htmlspecialchars((string)($r["sebitc"] ?? "")); ?></td>
                                <td><?php echo htmlspecialchars((string)($r["brk"] ?? "")); ?></td>
                                <td><?php echo htmlspecialchars((string)($r["stb"] ?? "")); ?></td>
                                <td><?php echo htmlspecialchars((string)($r["totch"] ?? "")); ?></td>
                                <td class="left"><?php echo htmlspecialchars((string)($r["created_at"] ?? "")); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($dbReady && $error === ""): ?>
            <div class="meta">No data for selected filters.</div>
        <?php endif; ?>
    </div>
    <script>
        document.querySelectorAll(".ttype-form").forEach((form) => {
            form.addEventListener("submit", async (e) => {
                e.preventDefault();
                const status = form.querySelector(".save-status");
                if (status) status.textContent = "Saving...";
                try {
                    const res = await fetch(window.location.href, {
                        method: "POST",
                        headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" },
                        body: new FormData(form)
                    });
                    const text = await res.text();
                    if (!res.ok) throw new Error(text || "Save failed");
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch {
                        throw new Error(text || "Invalid JSON");
                    }
                    if (data && data.ok) {
                        if (status) status.textContent = "Saved";
                    } else {
                        if (status) status.textContent = data?.error ? "Error: " + data.error : "Error";
                    }
                } catch (err) {
                    if (status) status.textContent = "Error: " + (err?.message || "Save failed");
                }
            });
        });

        const table = document.getElementById("orders-table");
        if (table) {
            const getCellValue = (row, idx) => row.children[idx]?.innerText ?? "";
            const isNumeric = (value) => value !== "" && !isNaN(value.replace(/,/g, ""));

            table.querySelectorAll("th").forEach((th, idx) => {
                th.style.cursor = "pointer";
                th.addEventListener("click", () => {
                    const tbody = table.tBodies[0];
                    const rows = Array.from(tbody.rows);
                    const asc = th.dataset.sortDir !== "asc";
                    table.querySelectorAll("th").forEach(h => h.dataset.sortDir = "");
                    th.dataset.sortDir = asc ? "asc" : "desc";

                    rows.sort((a, b) => {
                        const va = getCellValue(a, idx).trim();
                        const vb = getCellValue(b, idx).trim();
                        if (isNumeric(va) && isNumeric(vb)) {
                            return asc ? (parseFloat(va) - parseFloat(vb)) : (parseFloat(vb) - parseFloat(va));
                        }
                        return asc ? va.localeCompare(vb) : vb.localeCompare(va);
                    });
                    rows.forEach(r => tbody.appendChild(r));
                });
            });
        }
    </script>
</body>
</html>
