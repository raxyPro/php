<?php
$dataFile = __DIR__ . DIRECTORY_SEPARATOR . "prompt_manager_data.json";
$error = "";
$message = trim((string)($_GET["msg"] ?? ""));

function loadData(string $dataFile): array
{
    if (!is_file($dataFile)) {
        return ["apps" => []];
    }

    $raw = file_get_contents($dataFile);
    if ($raw === false || trim($raw) === "") {
        return ["apps" => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded["apps"]) || !is_array($decoded["apps"])) {
        return ["apps" => []];
    }

    return $decoded;
}

function saveData(string $dataFile, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return file_put_contents($dataFile, $json) !== false;
}

function normalizeName(string $value): string
{
    return trim(preg_replace('/\s+/', " ", $value) ?? "");
}

function buildPromptId(): string
{
    return "p_" . date("YmdHis") . "_" . bin2hex(random_bytes(3));
}

function redirectTo(string $appName, string $msg = "", string $edit = ""): void
{
    $query = [];
    if ($appName !== "") {
        $query["app"] = $appName;
    }
    if ($msg !== "") {
        $query["msg"] = $msg;
    }
    if ($edit !== "") {
        $query["edit"] = $edit;
    }
    $target = "prompt_manager.php";
    if (!empty($query)) {
        $target .= "?" . http_build_query($query);
    }
    header("Location: " . $target);
    exit;
}

function getPromptIndex(array $prompts, string $promptId): int
{
    foreach ($prompts as $idx => $item) {
        if ((string)($item["id"] ?? "") === $promptId) {
            return (int)$idx;
        }
    }
    return -1;
}

$data = loadData($dataFile);
if (!isset($data["apps"]) || !is_array($data["apps"])) {
    $data["apps"] = [];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string)($_POST["action"] ?? "");
    $currentApp = normalizeName((string)($_POST["selected_app"] ?? ""));

    try {
        if ($action === "create_app") {
            $newAppName = normalizeName((string)($_POST["new_app_name"] ?? ""));
            if ($newAppName === "") {
                throw new RuntimeException("Application name is required.");
            }
            if (isset($data["apps"][$newAppName])) {
                throw new RuntimeException("Application already exists.");
            }
            $data["apps"][$newAppName] = [];
            if (!saveData($dataFile, $data)) {
                throw new RuntimeException("Unable to save new application.");
            }
            redirectTo($newAppName, "Application created.");
        }

        if ($action === "save_prompt") {
            $promptTitle = normalizeName((string)($_POST["prompt_title"] ?? ""));
            $promptText = trim((string)($_POST["prompt_text"] ?? ""));
            $promptId = trim((string)($_POST["prompt_id"] ?? ""));

            if ($currentApp === "" || !isset($data["apps"][$currentApp])) {
                throw new RuntimeException("Select an application first.");
            }
            if ($promptText === "") {
                throw new RuntimeException("Prompt text is required.");
            }

            if ($promptId === "") {
                $data["apps"][$currentApp][] = [
                    "id" => buildPromptId(),
                    "title" => $promptTitle,
                    "text" => $promptText,
                    "created_at" => date("Y-m-d H:i:s"),
                    "updated_at" => date("Y-m-d H:i:s"),
                ];
                if (!saveData($dataFile, $data)) {
                    throw new RuntimeException("Failed to save prompt.");
                }
                redirectTo($currentApp, "Prompt added.");
            } else {
                $idx = getPromptIndex($data["apps"][$currentApp], $promptId);
                if ($idx < 0) {
                    throw new RuntimeException("Prompt not found for edit.");
                }
                $data["apps"][$currentApp][$idx]["title"] = $promptTitle;
                $data["apps"][$currentApp][$idx]["text"] = $promptText;
                $data["apps"][$currentApp][$idx]["updated_at"] = date("Y-m-d H:i:s");
                if (!saveData($dataFile, $data)) {
                    throw new RuntimeException("Failed to update prompt.");
                }
                redirectTo($currentApp, "Prompt updated.");
            }
        }

        if ($action === "delete_prompt") {
            $promptId = trim((string)($_POST["prompt_id"] ?? ""));
            if ($currentApp === "" || $promptId === "" || !isset($data["apps"][$currentApp])) {
                throw new RuntimeException("Invalid delete request.");
            }
            $idx = getPromptIndex($data["apps"][$currentApp], $promptId);
            if ($idx < 0) {
                throw new RuntimeException("Prompt not found.");
            }
            array_splice($data["apps"][$currentApp], $idx, 1);
            if (!saveData($dataFile, $data)) {
                throw new RuntimeException("Failed to delete prompt.");
            }
            redirectTo($currentApp, "Prompt deleted.");
        }
    } catch (Throwable $t) {
        $error = $t->getMessage();
    }
}

$apps = $data["apps"];
ksort($apps, SORT_NATURAL | SORT_FLAG_CASE);
$appNames = array_keys($apps);
$selectedApp = normalizeName((string)($_GET["app"] ?? ""));
if ($selectedApp === "" || !isset($apps[$selectedApp])) {
    $selectedApp = (string)($appNames[0] ?? "");
}
$selectedPrompts = ($selectedApp !== "" && isset($apps[$selectedApp])) ? $apps[$selectedApp] : [];
$editPromptId = trim((string)($_GET["edit"] ?? ""));
$editPrompt = null;
if ($selectedApp !== "" && $editPromptId !== "") {
    foreach ($selectedPrompts as $item) {
        if ((string)($item["id"] ?? "") === $editPromptId) {
            $editPrompt = $item;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prompt Manager</title>
    <style>
        :root {
            --bg1: #071e30;
            --bg2: #19558b;
            --panel: #ffffff;
            --ink: #11263a;
            --muted: #5e7387;
            --line: #d6e1ed;
            --btn: #0f6ec9;
            --btn-hover: #0c5ea9;
            --danger: #b83b4c;
            --danger-hover: #9d2f3f;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            padding: 18px;
            color: var(--ink);
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at 15% 10%, #1f5f98, transparent 36%),
                linear-gradient(125deg, var(--bg1), var(--bg2));
        }
        .shell {
            max-width: 1260px;
            margin: 0 auto;
            border-radius: 14px;
            overflow: hidden;
            background: var(--panel);
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.22);
        }
        .head {
            padding: 14px 16px;
            color: #fff;
            background: linear-gradient(90deg, #10314f, #235d93);
        }
        .title {
            margin: 0;
            font-size: 22px;
        }
        .sub {
            margin-top: 4px;
            font-size: 13px;
            opacity: 0.9;
        }
        .msg, .err {
            margin: 12px 14px 0;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
            font-weight: 600;
        }
        .msg {
            background: #ebf8ff;
            border: 1px solid #b7def5;
            color: #17517c;
        }
        .err {
            background: #ffeff2;
            border: 1px solid #efbfca;
            color: #912435;
        }
        .layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            min-height: 680px;
        }
        .left {
            border-right: 1px solid var(--line);
            background: #f8fbff;
            padding: 12px;
        }
        .right {
            padding: 14px;
            background: #fcfdff;
        }
        h2 {
            margin: 0 0 10px;
            font-size: 18px;
        }
        h3 {
            margin: 0 0 8px;
            font-size: 16px;
        }
        .new-app {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px;
            background: #fff;
            margin-bottom: 12px;
        }
        .app-list {
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }
        .app-link {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 9px 10px;
            text-decoration: none;
            color: #1d3550;
            border-bottom: 1px solid #e7eef6;
            font-size: 14px;
        }
        .app-link:last-child {
            border-bottom: 0;
        }
        .app-link:hover {
            background: #eef5ff;
        }
        .app-link.active {
            background: #e1efff;
            font-weight: 700;
        }
        .count {
            font-size: 12px;
            color: var(--muted);
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-size: 12px;
            color: #41576d;
        }
        input[type="text"], textarea {
            width: 100%;
            border: 1px solid #c7d5e5;
            border-radius: 8px;
            padding: 8px 10px;
            font: inherit;
            background: #fff;
        }
        textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.35;
            font-family: Consolas, monospace;
            font-size: 13px;
        }
        .row {
            margin-bottom: 10px;
        }
        .btn {
            border: 0;
            border-radius: 8px;
            padding: 8px 12px;
            color: #fff;
            cursor: pointer;
            background: var(--btn);
        }
        .btn:hover {
            background: var(--btn-hover);
        }
        .btn-danger {
            background: var(--danger);
        }
        .btn-danger:hover {
            background: var(--danger-hover);
        }
        .btn-ghost {
            display: inline-block;
            border: 1px solid #b2c4da;
            border-radius: 8px;
            padding: 7px 11px;
            text-decoration: none;
            color: #28425f;
            background: #fff;
        }
        .btn-ghost:hover {
            background: #f0f6ff;
        }
        .prompts {
            margin-bottom: 14px;
        }
        .prompt-item {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            padding: 10px;
            margin-bottom: 10px;
        }
        .prompt-title {
            font-weight: 700;
            margin-bottom: 4px;
        }
        .prompt-meta {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .prompt-text {
            white-space: pre-wrap;
            border: 1px dashed #c7d7e8;
            border-radius: 8px;
            background: #fbfdff;
            padding: 8px;
            max-height: 220px;
            overflow: auto;
            font-family: Consolas, monospace;
            font-size: 12px;
        }
        .actions {
            margin-top: 8px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .actions form {
            margin: 0;
        }
        .editor {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            padding: 12px;
        }
        .muted {
            color: var(--muted);
            font-size: 13px;
        }
        @media (max-width: 980px) {
            .layout {
                grid-template-columns: 1fr;
            }
            .left {
                border-right: 0;
                border-bottom: 1px solid var(--line);
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="head">
            <h1 class="title">App Prompt Manager</h1>
            <div class="sub">Left: applications. Right: prompts and add/edit form.</div>
        </div>

        <?php if ($message !== ""): ?>
            <div class="msg"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error !== ""): ?>
            <div class="err"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="layout">
            <aside class="left">
                <h2>Applications</h2>
                <div class="new-app">
                    <form method="post">
                        <input type="hidden" name="action" value="create_app">
                        <div class="row">
                            <label for="new_app_name">Create New Application</label>
                            <input id="new_app_name" name="new_app_name" type="text" placeholder="Example: Todo App" required>
                        </div>
                        <button class="btn" type="submit">Create App</button>
                    </form>
                </div>

                <?php if (empty($apps)): ?>
                    <div class="muted">No application yet.</div>
                <?php else: ?>
                    <div class="app-list">
                        <?php foreach ($apps as $appName => $prompts): ?>
                            <a
                                class="app-link <?php echo $appName === $selectedApp ? "active" : ""; ?>"
                                href="prompt_manager.php?<?php echo htmlspecialchars(http_build_query(["app" => $appName])); ?>">
                                <span><?php echo htmlspecialchars((string)$appName); ?></span>
                                <span class="count"><?php echo count($prompts); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </aside>

            <main class="right">
                <?php if ($selectedApp === ""): ?>
                    <h2>Prompts</h2>
                    <div class="muted">Create an application from the left to start saving prompts.</div>
                <?php else: ?>
                    <h2><?php echo htmlspecialchars($selectedApp); ?> - Prompts</h2>

                    <section class="prompts">
                        <?php if (empty($selectedPrompts)): ?>
                            <div class="muted">No prompt saved in this application.</div>
                        <?php else: ?>
                            <?php foreach (array_reverse($selectedPrompts) as $item): ?>
                                <?php
                                    $itemId = (string)($item["id"] ?? "");
                                    $itemTitle = trim((string)($item["title"] ?? ""));
                                    $itemText = (string)($item["text"] ?? "");
                                    $createdAt = (string)($item["created_at"] ?? "");
                                    $updatedAt = (string)($item["updated_at"] ?? "");
                                    $meta = "Created: " . $createdAt;
                                    if ($updatedAt !== "" && $updatedAt !== $createdAt) {
                                        $meta .= " | Updated: " . $updatedAt;
                                    }
                                ?>
                                <div class="prompt-item">
                                    <div class="prompt-title"><?php echo htmlspecialchars($itemTitle !== "" ? $itemTitle : "Untitled Prompt"); ?></div>
                                    <div class="prompt-meta"><?php echo htmlspecialchars($meta); ?></div>
                                    <div class="prompt-text" id="prompt_<?php echo htmlspecialchars($itemId); ?>"><?php echo htmlspecialchars($itemText); ?></div>
                                    <div class="actions">
                                        <button
                                            type="button"
                                            class="btn"
                                            onclick="copyPrompt('prompt_<?php echo htmlspecialchars($itemId); ?>', this)">
                                            Copy
                                        </button>
                                        <a
                                            class="btn-ghost"
                                            href="prompt_manager.php?<?php echo htmlspecialchars(http_build_query(["app" => $selectedApp, "edit" => $itemId])); ?>#promptForm">
                                            Edit
                                        </a>
                                        <form method="post" onsubmit="return confirm('Delete this prompt?');">
                                            <input type="hidden" name="action" value="delete_prompt">
                                            <input type="hidden" name="selected_app" value="<?php echo htmlspecialchars($selectedApp); ?>">
                                            <input type="hidden" name="prompt_id" value="<?php echo htmlspecialchars($itemId); ?>">
                                            <button type="submit" class="btn btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>

                    <section class="editor" id="promptForm">
                        <h3><?php echo $editPrompt !== null ? "Edit Prompt" : "Add New Prompt"; ?></h3>
                        <form method="post">
                            <input type="hidden" name="action" value="save_prompt">
                            <input type="hidden" name="selected_app" value="<?php echo htmlspecialchars($selectedApp); ?>">
                            <input type="hidden" name="prompt_id" value="<?php echo htmlspecialchars((string)($editPrompt["id"] ?? "")); ?>">
                            <div class="row">
                                <label for="prompt_title">Prompt Title (optional)</label>
                                <input
                                    id="prompt_title"
                                    name="prompt_title"
                                    type="text"
                                    value="<?php echo htmlspecialchars((string)($editPrompt["title"] ?? "")); ?>"
                                    placeholder="Example: Initial build prompt">
                            </div>
                            <div class="row">
                                <label for="prompt_text">Prompt</label>
                                <textarea id="prompt_text" name="prompt_text" required><?php echo htmlspecialchars((string)($editPrompt["text"] ?? "")); ?></textarea>
                            </div>
                            <div class="actions">
                                <button type="submit" class="btn"><?php echo $editPrompt !== null ? "Update Prompt" : "Add Prompt"; ?></button>
                                <?php if ($editPrompt !== null): ?>
                                    <a class="btn-ghost" href="prompt_manager.php?<?php echo htmlspecialchars(http_build_query(["app" => $selectedApp])); ?>#promptForm">Cancel Edit</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script>
        function copyPrompt(elementId, button) {
            const el = document.getElementById(elementId);
            if (!el) return;
            const text = el.textContent || "";

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    const original = button.textContent;
                    button.textContent = "Copied";
                    setTimeout(() => {
                        button.textContent = original;
                    }, 1000);
                });
                return;
            }

            const tmp = document.createElement("textarea");
            tmp.value = text;
            document.body.appendChild(tmp);
            tmp.select();
            document.execCommand("copy");
            document.body.removeChild(tmp);
            const original = button.textContent;
            button.textContent = "Copied";
            setTimeout(() => {
                button.textContent = original;
            }, 1000);
        }
    </script>
</body>
</html>
