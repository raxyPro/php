<?php
$apps = [
    ["file" => "gem.php", "description" => "Gemini Model Testing"],
    ["file" => "ict.php", "description" => "Testing ICICI Breeze API"],
    ["file" => "mail.php", "description" => "Testing mail functionality"],
    ["file" => "oc_live.php", "description" => "Option Chain Live"],
    ["file" => "oc_order.php", "description" => "Trade list and Definition"],
    ["file" => "phpi.php", "description" => "PHP Information file"],
    ["file" => "poc.php", "description" => "Proof of Concept for API integration"],
];

$appFiles = [];
foreach ($apps as $app) {
    $file = (string)($app["file"] ?? "");
    if ($file !== "") {
        $appFiles[$file] = true;
    }
}

$otherFiles = [];
foreach (scandir(__DIR__) ?: [] as $file) {
    if ($file === "." || $file === ".." || $file === "index.php") {
        continue;
    }
    $path = __DIR__ . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        continue;
    }
    if (!isset($appFiles[$file])) {
        $otherFiles[] = $file;
    }
}
sort($otherFiles, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>App Selector</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 14px; background: #f5f7fb; color: #1d2530; }
        .wrap { max-width: 980px; margin: 0 auto; }
        h2, h3 { margin: 0 0 10px; }
        .apps-inline {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 18px;
        }
        .app-link {
            display: inline-block;
            background: #ffffff;
            color: #18407d;
            text-decoration: none;
            border: 1px solid #c7d4ea;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 13px;
            line-height: 1.2;
        }
        .app-link:hover { background: #eef4ff; border-color: #9db7e0; }
        .files-list { display: flex; flex-wrap: wrap; gap: 8px 12px; }
        .file-link { color: #0b5ed7; text-decoration: none; font-size: 13px; }
        .file-link:hover { text-decoration: underline; }
        .muted { color: #687587; font-size: 13px; }
    </style>
</head>
<body>
    <div class="wrap">
        <h2>Applications</h2>
        <div class="apps-inline">
            <?php foreach ($apps as $app): ?>
                <?php
                    $file = (string)($app["file"] ?? "");
                    $description = trim((string)($app["description"] ?? ""));
                    if ($file === "" || !is_file(__DIR__ . DIRECTORY_SEPARATOR . $file)) {
                        continue;
                    }
                    $label = $description !== "" ? ($file . " - " . $description) : $file;
                ?>
                <a class="app-link" href="<?php echo htmlspecialchars($file); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo htmlspecialchars($label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <h3>Other Files</h3>
        <?php if (!empty($otherFiles)): ?>
            <div class="files-list">
                <?php foreach ($otherFiles as $file): ?>
                    <a class="file-link" href="<?php echo htmlspecialchars($file); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo htmlspecialchars($file); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="muted">No additional files found.</div>
        <?php endif; ?>
    </div>
</body>
</html>
