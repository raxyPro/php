<?php
$configPath = __DIR__ . DIRECTORY_SEPARATOR . "app.config";
$appCfg = is_file($configPath) ? (parse_ini_file($configPath, false, INI_SCANNER_RAW) ?: []) : [];

$dsn = trim((string)($appCfg["DB_DSN"] ?? ""));
$dbUser = trim((string)($appCfg["DB_USER"] ?? ""));
$dbPass = (string)($appCfg["DB_PASS"] ?? "");

$error = "";
$symbols = [];
$expiries = [];
$dates = [];
$rows = [];
$selSym = "";
$selExp = "";
$selDate = "";
$spot = null;
$atmStrike = null;
$atmRowIndex = null;
$notice = "";
$totalRows = 0;
$nav = (string)($_GET["nav"] ?? "");
$dateIndex = -1;
$hasPrev = false;
$hasNext = false;
$isUserLoaded = isset($_GET["sym"]) || isset($_GET["expdt"]) || isset($_GET["dt"]) || isset($_GET["nav"]);
$canNavigate = false;

try {
    if ($dsn === "" || $dbUser === "") {
        throw new RuntimeException("Missing DB_DSN or DB_USER in app.config");
    }

    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $symbols = $pdo->query("SELECT DISTINCT sym FROM tblfodates WHERE sym IS NOT NULL AND TRIM(sym) <> '' ORDER BY sym")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (empty($symbols)) {
        $symbols = $pdo->query("SELECT DISTINCT sym FROM tblfo WHERE sym IS NOT NULL AND TRIM(sym) <> '' ORDER BY sym")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($symbols)) {
            $notice = "tblfodates has blank symbols; symbol list is loaded from tblfo.";
        }
    }
    if (!empty($symbols)) {
        usort($symbols, static function ($a, $b): int {
            $aa = strtoupper(trim((string)$a));
            $bb = strtoupper(trim((string)$b));
            $prio = ["NIFTY" => 0, "BANKNIFTY" => 1];
            $pa = $prio[$aa] ?? 100;
            $pb = $prio[$bb] ?? 100;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp($aa, $bb);
        });
    }
    $selSym = (string)($_GET["sym"] ?? ($symbols[0] ?? ""));
    if ($selSym !== "" && !in_array($selSym, $symbols, true)) {
        $selSym = (string)($symbols[0] ?? "");
    }

    if ($selSym !== "") {
        $stmt = $pdo->prepare("SELECT DISTINCT expdt FROM tblfodates WHERE sym = :sym ORDER BY expdt DESC");
        $stmt->execute([":sym" => $selSym]);
        $expiries = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($expiries)) {
            $expiries = $pdo->query("SELECT DISTINCT expdt FROM tblfodates ORDER BY expdt DESC")
                ->fetchAll(PDO::FETCH_COLUMN);
        }
        $selExp = (string)($_GET["expdt"] ?? ($expiries[0] ?? ""));
    }

    if ($selSym !== "" && $selExp !== "") {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT dt
             FROM tblfodates
             WHERE sym = :sym AND expdt = :expdt
             ORDER BY dt DESC"
        );
        $stmt->execute([
            ":sym" => $selSym,
            ":expdt" => $selExp,
        ]);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($dates)) {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT dt
                 FROM tblfodates
                 WHERE expdt = :expdt
                 ORDER BY dt DESC"
            );
            $stmt->execute([":expdt" => $selExp]);
            $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        $selDate = (string)($_GET["dt"] ?? ($dates[0] ?? ""));
        if ($selDate !== "" && !in_array($selDate, $dates, true)) {
            $selDate = (string)($dates[0] ?? "");
        }
        if (($nav === "prev" || $nav === "next") && !empty($dates)) {
            $idx = array_search($selDate, $dates, true);
            if ($idx === false) {
                $idx = 0;
            }
            if ($nav === "prev" && $idx < (count($dates) - 1)) {
                $idx++;
            } elseif ($nav === "next" && $idx > 0) {
                $idx--;
            }
            $selDate = (string)$dates[$idx];
        }
        $idx2 = array_search($selDate, $dates, true);
        if ($idx2 !== false) {
            $dateIndex = (int)$idx2;
            $hasPrev = $dateIndex < (count($dates) - 1);
            $hasNext = $dateIndex > 0;
            $canNavigate = $isUserLoaded && count($dates) > 1;
        }
    }

    if ($selSym !== "" && $selExp !== "" && $selDate !== "") {
        $stmt = $pdo->prepare(
            "SELECT AVG(cash) AS spot
             FROM tblfo
             WHERE sym = :sym AND expdt = :expdt AND dt = :dt AND cash IS NOT NULL"
        );
        $stmt->execute([
            ":sym" => $selSym,
            ":expdt" => $selExp,
            ":dt" => $selDate,
        ]);
        $spotRaw = $stmt->fetchColumn();
        $spot = $spotRaw !== false && $spotRaw !== null ? (float)$spotRaw : null;

        $stmt = $pdo->prepare(
            "SELECT
                stk,
                MAX(CASE WHEN UPPER(opt) IN ('CE','C','CALL') THEN lst END) AS ce_ltp,
                MAX(CASE WHEN UPPER(opt) IN ('CE','C','CALL') THEN opn END) AS ce_opn,
                MAX(CASE WHEN UPPER(opt) IN ('CE','C','CALL') THEN hgh END) AS ce_hgh,
                MAX(CASE WHEN UPPER(opt) IN ('CE','C','CALL') THEN low END) AS ce_low,
                MAX(CASE WHEN UPPER(opt) IN ('CE','C','CALL') THEN cls END) AS ce_cls,
                MAX(CASE WHEN UPPER(opt) IN ('CE','C','CALL') THEN oi END) AS ce_oi,
                MAX(CASE WHEN UPPER(opt) IN ('CE','C','CALL') THEN tvol END) AS ce_vol,
                MAX(CASE WHEN UPPER(opt) IN ('PE','P','PUT') THEN lst END) AS pe_ltp,
                MAX(CASE WHEN UPPER(opt) IN ('PE','P','PUT') THEN opn END) AS pe_opn,
                MAX(CASE WHEN UPPER(opt) IN ('PE','P','PUT') THEN hgh END) AS pe_hgh,
                MAX(CASE WHEN UPPER(opt) IN ('PE','P','PUT') THEN low END) AS pe_low,
                MAX(CASE WHEN UPPER(opt) IN ('PE','P','PUT') THEN cls END) AS pe_cls,
                MAX(CASE WHEN UPPER(opt) IN ('PE','P','PUT') THEN oi END) AS pe_oi,
                MAX(CASE WHEN UPPER(opt) IN ('PE','P','PUT') THEN tvol END) AS pe_vol
             FROM tblfo
             WHERE sym = :sym AND expdt = :expdt AND dt = :dt
             GROUP BY stk
             ORDER BY stk"
        );
        $stmt->execute([
            ":sym" => $selSym,
            ":expdt" => $selExp,
            ":dt" => $selDate,
        ]);
        $rows = $stmt->fetchAll();
        $totalRows = count($rows);

        if ($spot !== null && !empty($rows)) {
            $bestDiff = null;
            foreach ($rows as $idx => $r) {
                $strike = isset($r["stk"]) ? (float)$r["stk"] : null;
                if ($strike === null) {
                    continue;
                }
                $diff = abs($strike - $spot);
                if ($bestDiff === null || $diff < $bestDiff) {
                    $bestDiff = $diff;
                    $atmStrike = $strike;
                    $atmRowIndex = (int)$idx;
                }
            }
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

function numf($value, int $dec = 2): string
{
    if ($value === null || $value === "") {
        return "-";
    }
    if (!is_numeric((string)$value)) {
        return (string)$value;
    }
    return number_format((float)$value, $dec, ".", ",");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Option Chain</title>
    <style>
        :root {
            --bg-a: #091a2f;
            --bg-b: #122e4f;
            --panel: rgba(255, 255, 255, 0.95);
            --call: #eaf4ff;
            --put: #ffeef0;
            --strike: #f4f7fc;
            --atm: #fff9db;
            --ink: #122033;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            color: var(--ink);
            background: radial-gradient(circle at 15% 10%, #1f4a7a, transparent 38%),
                        radial-gradient(circle at 90% 20%, #284f86, transparent 40%),
                        linear-gradient(145deg, var(--bg-a), var(--bg-b));
            min-height: 100vh;
            padding: 20px;
        }
        .shell {
            max-width: 1220px;
            margin: 0 auto;
            background: var(--panel);
            border-radius: 16px;
            box-shadow: 0 18px 36px rgba(0, 0, 0, 0.22);
            overflow: hidden;
        }
        .head {
            padding: 16px 18px;
            background: linear-gradient(90deg, #1f3f66, #2d5f97);
            color: #fff;
        }
        .title { margin: 0; font-size: 22px; letter-spacing: 0.2px; font-weight: 700; }
        .sub { margin-top: 4px; opacity: 0.88; font-size: 13px; }
        .bar {
            display: flex;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
            padding: 14px 18px;
            border-bottom: 1px solid #dce6f5;
            background: #f8fbff;
        }
        .filter-bar {
            display: flex;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
            padding: 8px 18px 12px;
            border-bottom: 1px solid #dce6f5;
            background: #f4f8ff;
        }
        label { display: block; font-size: 12px; margin-bottom: 4px; color: #435572; }
        select, button {
            border: 1px solid #c4d4eb;
            background: #fff;
            border-radius: 8px;
            padding: 8px 10px;
            min-width: 170px;
        }
        .sym-wrap { display: flex; flex-direction: column; gap: 6px; }
        #symSearch {
            border: 1px solid #c4d4eb;
            border-radius: 8px;
            padding: 8px 10px;
            min-width: 170px;
        }
        button {
            background: #1d5ca8;
            color: #fff;
            border: none;
            min-width: 92px;
            cursor: pointer;
        }
        button:hover { background: #1b4f8f; }
        button:disabled {
            background: #9db3d1;
            cursor: not-allowed;
            opacity: 0.7;
        }
        .chips {
            padding: 10px 18px 0 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .chip {
            border: 1px solid #d5e2f6;
            background: #f6f9ff;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
        }
        .error {
            margin: 12px 18px;
            background: #ffeef0;
            border: 1px solid #f3bec4;
            color: #8f1a28;
            border-radius: 8px;
            padding: 10px 12px;
            font-weight: 600;
        }
        .notice {
            margin: 12px 18px;
            background: #ecf7ff;
            border: 1px solid #b8dbf2;
            color: #134d74;
            border-radius: 8px;
            padding: 10px 12px;
            font-weight: 600;
        }
        .table-wrap { padding: 10px 12px 14px; overflow: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 1180px;
            table-layout: fixed;
        }
        th, td {
            border: 1px solid #dfe8f3;
            padding: 5px 6px;
            text-align: right;
            white-space: nowrap;
        }
        thead th { position: sticky; top: 0; z-index: 1; }
        th.group-call { background: #dcecff; color: #15467c; text-align: center; font-size: 12px; }
        th.group-put { background: #ffdfe4; color: #7b2330; text-align: center; font-size: 12px; }
        th.group-strike { background: #eef2f9; color: #263a56; text-align: center; font-size: 12px; }
        th.col-call { background: var(--call); color: #1f4c7e; }
        th.col-put { background: var(--put); color: #8a2a3a; }
        th.col-strike { background: var(--strike); text-align: center; }
        td.call { background: #f4f9ff; }
        td.put { background: #fff4f5; }
        td.strike { background: #f7f9fd; font-weight: 700; text-align: center; }
        tr.atm td { background: var(--atm) !important; }
        .empty {
            text-align: center;
            color: #55657f;
            padding: 26px 12px;
            font-style: italic;
        }
        @media (max-width: 820px) {
            body { padding: 12px; }
            .title { font-size: 20px; }
            select { min-width: 130px; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="head">
            <h1 class="title">Option chain</h1>
            <div class="sub">Symbol -> Expiry -> Date from <code>tblfodates</code>, values from <code>tblfo</code>.</div>
        </div>

        <?php if ($error !== ""): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($notice !== ""): ?>
            <div class="notice"><?php echo htmlspecialchars($notice); ?></div>
        <?php endif; ?>

        <form method="get" class="bar">
            <div class="sym-wrap">
                <label for="sym">Symbol</label>
                <input id="symSearch" type="text" placeholder="Search symbol...">
                <select id="sym" name="sym" onchange="this.form.submit()">
                    <?php foreach ($symbols as $s): ?>
                        <option value="<?php echo htmlspecialchars((string)$s); ?>" <?php echo ((string)$s === $selSym) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars((string)$s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="expdt">Expiry</label>
                <select id="expdt" name="expdt" onchange="this.form.submit()">
                    <?php foreach ($expiries as $e): ?>
                        <option value="<?php echo htmlspecialchars((string)$e); ?>" <?php echo ((string)$e === $selExp) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars((string)$e); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="dt">Date</label>
                <select id="dt" name="dt">
                    <?php foreach ($dates as $d): ?>
                        <option value="<?php echo htmlspecialchars((string)$d); ?>" <?php echo ((string)$d === $selDate) ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars((string)$d); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" name="nav" value="load">Load</button>
            </div>
            <div>
                <button
                    type="submit"
                    name="nav"
                    value="prev"
                    <?php echo ($canNavigate && $hasPrev) ? "" : "disabled"; ?>>
                    Prev
                </button>
            </div>
            <div>
                <button
                    type="submit"
                    name="nav"
                    value="next"
                    <?php echo ($canNavigate && $hasNext) ? "" : "disabled"; ?>>
                    Next
                </button>
            </div>
        </form>
        <div class="filter-bar">
            <div>
                <label for="nFilter">N Strikes</label>
                <select id="nFilter">
                    <?php foreach ([5, 10, 15, 20, 30, 40, 50, 0] as $n): ?>
                        <option value="<?php echo $n; ?>" <?php echo ($n === 10) ? "selected" : ""; ?>>
                            <?php echo $n === 0 ? "All" : (string)$n; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="chips">
            <div class="chip">Rows: <span id="visibleRows"><?php echo count($rows); ?></span><?php if ($totalRows > 0): ?> / <?php echo $totalRows; ?><?php endif; ?></div>
            <div class="chip">Spot: <?php echo $spot === null ? "-" : numf($spot, 2); ?></div>
            <div class="chip">ATM Strike: <?php echo $atmStrike === null ? "-" : numf($atmStrike, 2); ?></div>
        </div>

        <div class="table-wrap">
            <?php if (!empty($rows)): ?>
                <table>
                    <thead>
                        <tr>
                            <th class="group-call" colspan="7">Call (CE)</th>
                            <th class="group-strike" colspan="1">Strike</th>
                            <th class="group-put" colspan="7">Put (PE)</th>
                        </tr>
                        <tr>
                            <th class="col-call">Ltp</th>
                            <th class="col-call">Open</th>
                            <th class="col-call">High</th>
                            <th class="col-call">Low</th>
                            <th class="col-call">Close</th>
                            <th class="col-call">Oi</th>
                            <th class="col-call">Vol</th>
                            <th class="col-strike">Strike</th>
                            <th class="col-put">Ltp</th>
                            <th class="col-put">Open</th>
                            <th class="col-put">High</th>
                            <th class="col-put">Low</th>
                            <th class="col-put">Close</th>
                            <th class="col-put">Oi</th>
                            <th class="col-put">Vol</th>
                        </tr>
                    </thead>
                    <tbody id="ocRows">
                        <?php foreach ($rows as $i => $r): ?>
                            <?php
                                $strike = isset($r["stk"]) ? (float)$r["stk"] : null;
                                $isAtm = ($atmStrike !== null && $strike !== null && abs($strike - $atmStrike) < 0.0001);
                            ?>
                            <tr class="<?php echo $isAtm ? "atm" : ""; ?>" data-row-index="<?php echo (int)$i; ?>">
                                <td class="call"><?php echo htmlspecialchars(numf($r["ce_ltp"] ?? null)); ?></td>
                                <td class="call"><?php echo htmlspecialchars(numf($r["ce_opn"] ?? null)); ?></td>
                                <td class="call"><?php echo htmlspecialchars(numf($r["ce_hgh"] ?? null)); ?></td>
                                <td class="call"><?php echo htmlspecialchars(numf($r["ce_low"] ?? null)); ?></td>
                                <td class="call"><?php echo htmlspecialchars(numf($r["ce_cls"] ?? null)); ?></td>
                                <td class="call"><?php echo htmlspecialchars(numf($r["ce_oi"] ?? null, 0)); ?></td>
                                <td class="call"><?php echo htmlspecialchars(numf($r["ce_vol"] ?? null, 0)); ?></td>
                                <td class="strike"><?php echo htmlspecialchars(numf($r["stk"] ?? null)); ?></td>
                                <td class="put"><?php echo htmlspecialchars(numf($r["pe_ltp"] ?? null)); ?></td>
                                <td class="put"><?php echo htmlspecialchars(numf($r["pe_opn"] ?? null)); ?></td>
                                <td class="put"><?php echo htmlspecialchars(numf($r["pe_hgh"] ?? null)); ?></td>
                                <td class="put"><?php echo htmlspecialchars(numf($r["pe_low"] ?? null)); ?></td>
                                <td class="put"><?php echo htmlspecialchars(numf($r["pe_cls"] ?? null)); ?></td>
                                <td class="put"><?php echo htmlspecialchars(numf($r["pe_oi"] ?? null, 0)); ?></td>
                                <td class="put"><?php echo htmlspecialchars(numf($r["pe_vol"] ?? null, 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty">No option chain data for selected symbol/expiry/date.</div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        const symSearch = document.getElementById("symSearch");
        const symSelect = document.getElementById("sym");
        const nFilter = document.getElementById("nFilter");
        const visibleRowsEl = document.getElementById("visibleRows");
        const bodyRows = Array.from(document.querySelectorAll("#ocRows tr[data-row-index]"));
        const atmIndex = <?php echo $atmRowIndex === null ? "null" : (string)((int)$atmRowIndex); ?>;

        function applyStrikeFilter() {
            if (!nFilter || bodyRows.length === 0) {
                return;
            }
            const n = parseInt(nFilter.value || "10", 10);
            let visible = 0;
            if (n === 0 || n >= bodyRows.length) {
                bodyRows.forEach((r) => {
                    r.style.display = "";
                    visible++;
                });
            } else {
                const center = (atmIndex === null) ? 0 : atmIndex;
                const half = Math.floor(n / 2);
                let start = Math.max(0, center - half);
                if (start + n > bodyRows.length) {
                    start = Math.max(0, bodyRows.length - n);
                }
                const end = start + n - 1;
                bodyRows.forEach((r, idx) => {
                    const show = idx >= start && idx <= end;
                    r.style.display = show ? "" : "none";
                    if (show) visible++;
                });
            }
            if (visibleRowsEl) {
                visibleRowsEl.textContent = String(visible);
            }
        }

        if (symSearch && symSelect) {
            symSearch.addEventListener("input", () => {
                const term = symSearch.value.trim().toUpperCase();
                for (const opt of symSelect.options) {
                    const txt = (opt.textContent || "").toUpperCase();
                    opt.hidden = term !== "" && !txt.includes(term);
                }
            });
            symSearch.addEventListener("keydown", (e) => {
                if (e.key !== "Enter") return;
                e.preventDefault();
                const term = symSearch.value.trim().toUpperCase();
                for (const opt of symSelect.options) {
                    const txt = (opt.textContent || "").toUpperCase();
                    if (term !== "" && txt.includes(term) && !opt.hidden) {
                        symSelect.value = opt.value;
                        symSelect.form.submit();
                        return;
                    }
                }
            });
        }
        if (nFilter) {
            nFilter.addEventListener("change", applyStrikeFilter);
            applyStrikeFilter();
        }
    </script>
</body>
</html>
