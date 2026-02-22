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
$selDb = "";
$selTable = (string)($_GET["table"] ?? "");
$ajaxAction = (string)($_GET["ajax"] ?? "");

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

    if ($ajaxAction === "create") {
        header("Content-Type: application/json; charset=utf-8");
        if ($selDb === "" || $selTable === "") {
            throw new RuntimeException("Database or table missing.");
        }
        if (!in_array($selTable, $tables, true)) {
            throw new RuntimeException("Table not found in selected database.");
        }
        $row = $pdo->query("SHOW CREATE TABLE " . qi($selDb) . "." . qi($selTable))->fetch(PDO::FETCH_NUM);
        $createStmt = (string)($row[1] ?? "");
        echo json_encode([
            "ok" => true,
            "db" => $selDb,
            "table" => $selTable,
            "create" => $createStmt,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (Exception $e) {
    if ($ajaxAction === "create") {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "ok" => false,
            "error" => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
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
        .top { display: flex; gap: 10px; align-items: center; margin-bottom: 12px; flex-wrap: wrap; }
        select, button, input { padding: 6px 8px; font-size: 13px; }
        .layout { display: grid; grid-template-columns: 280px 1fr; gap: 12px; min-height: 520px; }
        .panel { background: #fff; border: 1px solid #d5deec; border-radius: 8px; overflow: hidden; }
        .panel h3 { margin: 0; padding: 10px 12px; font-size: 14px; border-bottom: 1px solid #e6ecf6; background: #f9fbff; }
        .pane-body { padding: 10px; }
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
        .err { color: #b00020; font-weight: 600; margin-bottom: 10px; }
        .muted { color: #5f6b7b; padding: 12px; font-size: 13px; }
        .actions { display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
        #tableSearch { width: calc(100% - 22px); margin: 10px; }
        #defBox {
            width: 100%;
            min-height: 560px;
            box-sizing: border-box;
            resize: vertical;
            font-size: 12px;
            line-height: 1.4;
            font-family: Consolas, "Courier New", monospace;
            padding: 10px;
            border: 1px solid #d5deec;
            border-radius: 6px;
            white-space: pre;
        }
        #status { font-size: 12px; color: #41506a; }
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
        <button type="submit">Load</button>
    </form>

    <div class="layout">
        <div class="panel">
            <h3>Tables</h3>
            <input id="tableSearch" type="text" placeholder="Search table...">
            <div class="scroll tables" id="tableList">
                <?php if (!empty($tables)): ?>
                    <?php foreach ($tables as $t): ?>
                        <a href="#" data-table="<?php echo htmlspecialchars((string)$t); ?>">
                            <?php echo htmlspecialchars((string)$t); ?>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="muted">No tables found.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <h3>Table Definitions</h3>
            <div class="pane-body">
                <div class="actions">
                    <button type="button" id="btnClear">Clear</button>
                    <button type="button" id="btnCopy">Copy</button>
                    <button type="button" id="btnDownload">Download</button>
                    <span id="status">Click a table to append definition.</span>
                </div>
                <textarea id="defBox" spellcheck="false" placeholder="Definitions will be appended here..."></textarea>
            </div>
        </div>
    </div>

    <script>
        const currentDb = <?php echo json_encode($selDb); ?>;
        const tableList = document.getElementById("tableList");
        const tableSearch = document.getElementById("tableSearch");
        const defBox = document.getElementById("defBox");
        const statusEl = document.getElementById("status");
        const btnClear = document.getElementById("btnClear");
        const btnCopy = document.getElementById("btnCopy");
        const btnDownload = document.getElementById("btnDownload");

        function setStatus(msg) {
            statusEl.textContent = msg;
        }

        async function loadDefinition(tableName) {
            const params = new URLSearchParams({
                ajax: "create",
                db: currentDb,
                table: tableName
            });
            setStatus("Loading " + tableName + "...");
            const res = await fetch("?" + params.toString(), {
                headers: { "Accept": "application/json" }
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error((data && data.error) ? data.error : "Load failed");
            }
            const block = "-- " + data.db + "." + data.table + "\n" + data.create + "\n\n";
            defBox.value += block;
            defBox.scrollTop = defBox.scrollHeight;
            setStatus("Appended: " + tableName);
        }

        tableList.addEventListener("click", async (e) => {
            const link = e.target.closest("a[data-table]");
            if (!link) {
                return;
            }
            e.preventDefault();
            tableList.querySelectorAll("a[data-table]").forEach((a) => a.classList.remove("active"));
            link.classList.add("active");
            const tableName = link.getAttribute("data-table") || "";
            if (!tableName) {
                return;
            }
            try {
                await loadDefinition(tableName);
            } catch (err) {
                setStatus("Error: " + (err && err.message ? err.message : "Load failed"));
            }
        });

        tableSearch.addEventListener("input", () => {
            const term = tableSearch.value.trim().toLowerCase();
            tableList.querySelectorAll("a[data-table]").forEach((a) => {
                const name = (a.getAttribute("data-table") || "").toLowerCase();
                a.style.display = (name.indexOf(term) !== -1) ? "block" : "none";
            });
        });

        btnClear.addEventListener("click", () => {
            defBox.value = "";
            setStatus("Cleared.");
        });

        btnCopy.addEventListener("click", async () => {
            try {
                await navigator.clipboard.writeText(defBox.value);
                setStatus("Copied to clipboard.");
            } catch {
                defBox.select();
                document.execCommand("copy");
                setStatus("Copied to clipboard.");
            }
        });

        btnDownload.addEventListener("click", () => {
            const blob = new Blob([defBox.value], { type: "text/plain;charset=utf-8" });
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = "phpdbdef.txt";
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            setStatus("Downloaded phpdbdef.txt.");
        });
    </script>
</body>
</html>
