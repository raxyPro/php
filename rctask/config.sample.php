<?php
// Copy this file to config.php and fill in your values.
// config.php is ignored by git; keep your API key out of source control.

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'rctask',
        'user' => 'rax',
        'pass' => '512',
    ],

    // Claude API key from https://console.anthropic.com. Leave empty to use
    // the built-in keyword rules instead of Claude.
    'anthropic_api_key' => '',
    'anthropic_model'   => 'claude-haiku-4-5',

    'timezone' => 'Asia/Kolkata',

    // Set to false after creating your account(s) to close sign-ups.
    'allow_register' => true,
];
