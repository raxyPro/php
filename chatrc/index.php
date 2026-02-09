<?php
require_once 'config.php';

// Ensure POST responses stay valid JSON (no PHP warnings/notices leaking into output)
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');
if ($isPost) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ob_start();
    set_error_handler(function ($severity, $message, $file, $line) {
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            return true;
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'php_error',
            'message' => $message,
            'file' => basename($file),
            'line' => $line
        ]);
        exit;
    });
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err !== null) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'php_fatal',
                'message' => $err['message'],
                'file' => basename($err['file']),
                'line' => $err['line']
            ]);
        }
    });
}

function respond_json($data, $code = 200) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// --- BACKEND: Database & API Handling ---

// 1. Setup SQLite Database
$db = new SQLite3('linkedin_bot.db');
$db->exec("CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY, 
    sys_instruction TEXT, 
    prev_posts TEXT
)");

// Initialize row if empty
$check = $db->querySingle("SELECT count(*) FROM settings");
if ($check == 0) {
    $db->exec("INSERT INTO settings (id, sys_instruction, prev_posts) VALUES (1, '', '')");
}

// 2. Handle AJAX Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'save_settings') {
        $stmt = $db->prepare("UPDATE settings SET sys_instruction = :sys, prev_posts = :prev WHERE id = 1");
        $stmt->bindValue(':sys', $input['sys_instruction'], SQLITE3_TEXT);
        $stmt->bindValue(':prev', $input['prev_posts'], SQLITE3_TEXT);
        $stmt->execute();
        respond_json(['status' => 'success']);
    }

    if ($action === 'generate_post') {
        // Retrieve context from DB
        $row = $db->querySingle("SELECT * FROM settings WHERE id = 1", true);
        $sysInfo = $row['sys_instruction'];
        $styleExamples = $row['prev_posts'];
        
        $currentDraft = $input['current_draft'];
        $userPrompt = $input['user_prompt'];

        // Construct the Mega-Prompt
        $finalPrompt = "SYSTEM INSTRUCTION (My Persona):\n$sysInfo\n\n";
        $finalPrompt .= "STYLE EXAMPLES (How I write):\n$styleExamples\n\n";
        
        if (!empty($currentDraft)) {
            $finalPrompt .= "CURRENT DRAFT CONTENT:\n$currentDraft\n\n";
            $finalPrompt .= "USER REQUEST: The user wants to modify the draft above. Instruction: $userPrompt\n";
            $finalPrompt .= "TASK: Rewrite the draft based on the instruction. Keep HTML formatting if present.";
        } else {
            $finalPrompt .= "USER REQUEST: Create a new LinkedIn post about: $userPrompt\n";
        }

        // Call Gemini API
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent?key=" . GEMINI_API_KEY;
        
        $data = [
            "contents" => [
                [
                    "parts" => [
                        ["text" => $finalPrompt]
                    ]
                ]
            ]
        ];

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        if (defined('ALLOW_INSECURE_SSL') && ALLOW_INSECURE_SSL === true) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            respond_json(['error' => 'curl_failed', 'message' => $err], 502);
        }
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        
        respond_json(json_decode($response, true) ?? ['error' => 'invalid_upstream_json', 'raw' => $response], 200);
    }
}

// --- FRONTEND: Load Initial Data ---
$data = $db->querySingle("SELECT * FROM settings WHERE id = 1", true);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI LinkedIn Generator</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: sans-serif; height: 100vh; display: flex; flex-direction: column; background: #f0f2f5; }
        
        /* The Grid Layout */
        .container {
            display: grid;
            grid-template-columns: 30% 70%; /* Left 30%, Right 70% */
            height: 100vh;
            padding: 10px;
            gap: 10px;
        }

        .column {
            display: flex;
            flex-direction: column;
            gap: 10px;
            height: 100%;
        }

        .box {
            background: white;
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        /* Column ratios */
        .left-top { flex: 3 1 0; }
        .left-bottom { flex: 7 1 0; }
        .right-top { flex: 7 1 0; }
        .right-bottom { flex: 3 1 0; }

        h3 { margin: 0 0 10px 0; font-size: 16px; color: #333; display: flex; justify-content: space-between; align-items: center; }
        
        /* Buttons */
        .btn { padding: 5px 10px; cursor: pointer; background: #0073b1; color: white; border: none; border-radius: 4px; font-size: 12px; }
        .btn:hover { background: #005582; }
        .btn-green { background: #28a745; }
        .btn-green:hover { background: #218838; }

        /* Chat Area */
        .chat-input-area { flex-grow: 1; display: flex; flex-direction: column; gap: 10px; }
        textarea.chat-prompt { flex-grow: 1; width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; resize: none; font-family: inherit; }
        
        /* Loaders */
        .loader { display: none; color: #666; font-style: italic; font-size: 12px; }
    </style>
</head>
<body>

<div class="container">

    <div class="column left-col">
        <div class="box left-top" id="box-left-top">
            <h3>System Instruction <button class="btn" onclick="saveSettings()">Update DB</button></h3>
            <textarea id="sys_instruction"><?php echo htmlspecialchars($data['sys_instruction']); ?></textarea>
        </div>

        <div class="box left-bottom" id="box-left-bottom">
            <h3>Previous Posts / Style Context <button class="btn" onclick="saveSettings()">Update DB</button></h3>
            <textarea id="prev_posts"><?php echo htmlspecialchars($data['prev_posts']); ?></textarea>
        </div>
    </div>

    <div class="column right-col">
        <div class="box right-top" id="box-right-top">
            <h3>Generated Post Output <span id="loading-indicator" class="loader">Generating...</span></h3>
            <textarea id="post_output"></textarea>
        </div>

        <div class="box right-bottom" id="box-right-bottom">
            <h3>AI Chat Prompt</h3>
            <div class="chat-input-area">
                <textarea class="chat-prompt" id="user_prompt" placeholder="Type here (e.g., 'Write a post about Python' or 'Make the text above shorter')..."></textarea>
                <button class="btn btn-green" style="font-size: 16px; padding: 10px;" onclick="generatePost()">Submit / Refine</button>
            </div>
        </div>
    </div>

</div>

<script>
    // 1. Initialize Rich Text Editors
    tinymce.init({ selector: '#sys_instruction', height: '80%', menubar: false, plugins: 'lists', toolbar: 'bold italic bullist' });
    tinymce.init({ selector: '#prev_posts', height: '85%', menubar: false, plugins: 'lists', toolbar: 'bold italic bullist' });
    tinymce.init({ selector: '#post_output', height: '90%', menubar: false, plugins: 'lists', toolbar: 'undo redo | bold italic | bullist numlist' });

    // 2. Function to Save Left Side to DB
    async function saveSettings() {
        const sys = tinymce.get('sys_instruction').getContent();
        const prev = tinymce.get('prev_posts').getContent();

        const response = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save_settings', sys_instruction: sys, prev_posts: prev })
        });

        if (!response.ok) {
            const text = await response.text();
            alert(`Save failed (${response.status}). ${text}`);
            return;
        }
        const res = await response.json();
        if(res.status === 'success') alert('Settings Saved to Database!');
    }

    // 3. Function to Generate/Refine Post
    async function generatePost() {
        const prompt = document.getElementById('user_prompt').value;
        const currentDraft = tinymce.get('post_output').getContent();
        const indicator = document.getElementById('loading-indicator');
        
        if(!prompt) return alert("Please enter a prompt");

        indicator.style.display = 'inline';
        
        try {
            const response = await fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'generate_post', 
                    user_prompt: prompt,
                    current_draft: currentDraft 
                })
            });

            if (!response.ok) {
                const text = await response.text();
                alert(`Request failed (${response.status}). ${text}`);
                return;
            }
            const data = await response.json();
            
            // Extract text from Gemini response
            if (data.error) {
                alert(`API Error: ${data.error.message || JSON.stringify(data.error)}`);
                return;
            }
            if (data.candidates && data.candidates[0].content) {
                const newText = data.candidates[0].content.parts[0].text;
                
                // Convert Markdown to HTML (Basic conversion for the editor)
                const formattedText = newText.replace(/\*\*(.*?)\*\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
                
                tinymce.get('post_output').setContent(formattedText);
                document.getElementById('user_prompt').value = ''; // Clear prompt
            } else {
                alert("Error generating content. Response: " + JSON.stringify(data));
            }
        } catch (e) {
            console.error(e);
            alert("Network Error. Check browser console for details.");
        }
        
        indicator.style.display = 'none';
    }
</script>

</body>
</html>
