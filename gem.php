<?php
declare(strict_types=1);

session_start();

$configPath = __DIR__ . DIRECTORY_SEPARATOR . "chatrc" . DIRECTORY_SEPARATOR . "config.php";
if (is_file($configPath)) {
    require_once $configPath;
}

$apiKey = defined("GEMINI_API_KEY") ? GEMINI_API_KEY : getenv("GEMINI_API_KEY");
$model = defined("GEMINI_MODEL") ? GEMINI_MODEL : "gemini-2.5-flash";
$allowInsecureSsl = defined("ALLOW_INSECURE_SSL") ? (bool) ALLOW_INSECURE_SSL : false;

if (!isset($_SESSION["gemini_history"]) || !is_array($_SESSION["gemini_history"])) {
    $_SESSION["gemini_history"] = [];
}

$error = null;
$responseText = null;

function call_gemini_api(array $contents, string $model, string $apiKey, bool $allowInsecureSsl): array
{
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model) . ":generateContent";

    $payload = json_encode([
        "contents" => $contents,
        "generationConfig" => [
            "temperature" => 0.7,
            "maxOutputTokens" => 512,
        ],
    ]);

    if ($payload === false) {
        return ["error" => "Failed to encode request payload."];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "x-goog-api-key: " . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if ($allowInsecureSsl) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $raw = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    if ($raw === false) {
        return ["error" => "Request failed: " . ($curlErr ?: "Unknown cURL error")];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ["error" => "Invalid JSON response from API."];
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $apiMessage = $data["error"]["message"] ?? "HTTP {$httpStatus}";
        return ["error" => "API error: " . $apiMessage];
    }

    $text = $data["candidates"][0]["content"]["parts"][0]["text"] ?? null;
    if (!is_string($text)) {
        return ["error" => "Unexpected response format."];
    }

    return ["text" => $text];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["reset"])) {
        $_SESSION["gemini_history"] = [];
    } else {
        $userMessage = trim((string) ($_POST["message"] ?? ""));
        if ($userMessage !== "") {
            $_SESSION["gemini_history"][] = [
                "role" => "user",
                "parts" => [["text" => $userMessage]],
            ];

            if (!$apiKey) {
                $error = "Missing API key. Set GEMINI_API_KEY in chatrc/config.php or an environment variable.";
            } else {
                $result = call_gemini_api($_SESSION["gemini_history"], $model, $apiKey, $allowInsecureSsl);
                if (isset($result["error"])) {
                    $error = $result["error"];
                } else {
                    $responseText = $result["text"];
                    $_SESSION["gemini_history"][] = [
                        "role" => "model",
                        "parts" => [["text" => $responseText]],
                    ];
                }
            }
        }
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Gemini Chat</title>
    <style>
      :root {
        color-scheme: light;
        --bg: #f7f4ef;
        --panel: #ffffff;
        --ink: #1b1b1b;
        --muted: #6b6b6b;
        --accent: #0b5fff;
        --user: #e7f0ff;
        --model: #f1efe8;
        --error: #b3261e;
      }
      * { box-sizing: border-box; }
      body {
        margin: 0;
        font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        background: var(--bg);
        color: var(--ink);
      }
      .wrap {
        max-width: 860px;
        margin: 40px auto;
        padding: 0 20px;
      }
      .card {
        background: var(--panel);
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.08);
      }
      h1 {
        margin: 0 0 16px 0;
        font-size: 22px;
      }
      .chat {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-bottom: 16px;
      }
      .msg {
        padding: 12px 14px;
        border-radius: 12px;
        max-width: 90%;
        white-space: pre-wrap;
      }
      .user { background: var(--user); align-self: flex-end; }
      .model { background: var(--model); align-self: flex-start; }
      .meta { color: var(--muted); font-size: 12px; margin-bottom: 6px; }
      form { display: flex; gap: 8px; }
      input[type="text"] {
        flex: 1;
        padding: 12px 14px;
        border: 1px solid #ddd;
        border-radius: 10px;
        font-size: 15px;
      }
      button {
        border: none;
        padding: 12px 16px;
        border-radius: 10px;
        background: var(--accent);
        color: #fff;
        font-size: 14px;
        cursor: pointer;
      }
      .reset {
        background: #444;
      }
      .error {
        color: var(--error);
        margin: 8px 0 0 0;
      }
      .footer {
        margin-top: 10px;
        color: var(--muted);
        font-size: 12px;
      }
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="card">
        <h1>Gemini Chat</h1>
        <div class="chat">
          <?php if (empty($_SESSION["gemini_history"])): ?>
            <div class="msg model">Ask me anything to get started.</div>
          <?php else: ?>
            <?php foreach ($_SESSION["gemini_history"] as $item): ?>
              <?php $role = $item["role"] ?? "model"; ?>
              <?php $text = $item["parts"][0]["text"] ?? ""; ?>
              <div class="msg <?php echo $role === "user" ? "user" : "model"; ?>">
                <?php echo h((string) $text); ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <form method="post">
          <input type="text" name="message" placeholder="Type your message..." autocomplete="off" />
          <button type="submit">Send</button>
          <button class="reset" type="submit" name="reset" value="1">Reset</button>
        </form>

        <?php if ($error): ?>
          <div class="error"><?php echo h($error); ?></div>
        <?php endif; ?>

        <div class="footer">Model: <?php echo h($model); ?></div>
      </div>
    </div>
  </body>
</html>
