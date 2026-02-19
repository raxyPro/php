<?php
$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'app.config';
$appCfg = is_file($configPath) ? (parse_ini_file($configPath, false, INI_SCANNER_RAW) ?: []) : [];

$imapValidateRaw = strtolower((string)($appCfg['IMAP_VALIDATE_CERT'] ?? 'true'));
$imapValidate = !in_array($imapValidateRaw, ['0', 'false', 'no', 'off'], true);

return [
    'username' => (string)($appCfg['MAIL_USERNAME'] ?? ''),
    'password' => (string)($appCfg['MAIL_PASSWORD'] ?? ''),
    'from_name' => (string)($appCfg['MAIL_FROM_NAME'] ?? ''),
    'cache_dir' => (string)($appCfg['MAIL_CACHE_DIR'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mail_cache')),
    'list_days_default' => (int)($appCfg['MAIL_LIST_DAYS_DEFAULT'] ?? 30),
    'list_uid_ttl' => (int)($appCfg['MAIL_LIST_UID_TTL'] ?? 120),
    'imap' => [
        'host' => (string)($appCfg['IMAP_HOST'] ?? 'rcpro.in'),
        'port' => (int)($appCfg['IMAP_PORT'] ?? 993),
        // ssl, tls, or none
        'encryption' => (string)($appCfg['IMAP_ENCRYPTION'] ?? 'ssl'),
        // Set to false if your server uses a self-signed certificate.
        'validate_cert' => $imapValidate,
        'default_folder' => (string)($appCfg['IMAP_DEFAULT_FOLDER'] ?? 'INBOX'),
    ],
    'smtp' => [
        'host' => (string)($appCfg['SMTP_HOST'] ?? 'rcpro.in'),
        'port' => (int)($appCfg['SMTP_PORT'] ?? 465),
        // ssl, tls, or none
        'encryption' => (string)($appCfg['SMTP_ENCRYPTION'] ?? 'ssl'),
    ],
];
