<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "PHP: " . PHP_VERSION . PHP_EOL;
echo "IMAP loaded: " . (extension_loaded('imap') ? "yes" : "no") . PHP_EOL;

if (!function_exists('imap_open')) {
    echo "imap_open() not available.\n";
    exit(1);
}

$configPath = __DIR__ . DIRECTORY_SEPARATOR . "app.config";
$appCfg = is_file($configPath) ? (parse_ini_file($configPath, false, INI_SCANNER_RAW) ?: []) : [];

$host = (string)($appCfg["IMAP_HOST"] ?? "imap.example.com");
$port = (int)($appCfg["IMAP_PORT"] ?? 993);
$user = (string)($appCfg["MAIL_USERNAME"] ?? "user@example.com");
$pass = (string)($appCfg["MAIL_PASSWORD"] ?? "password");
$imapEnc = strtolower((string)($appCfg["IMAP_ENCRYPTION"] ?? "ssl"));
$mailboxFlags = "/imap";
if ($imapEnc === "ssl") {
    $mailboxFlags .= "/ssl";
} elseif ($imapEnc === "tls") {
    $mailboxFlags .= "/tls";
}
$imapValidateRaw = strtolower((string)($appCfg["IMAP_VALIDATE_CERT"] ?? "true"));
if (in_array($imapValidateRaw, ["0", "false", "no", "off"], true)) {
    $mailboxFlags .= "/novalidate-cert";
}

$mailbox = "{" . $host . ":" . $port . $mailboxFlags . "}INBOX";

echo "Connecting to: $mailbox\n";
$imap = @imap_open($mailbox, $user, $pass);

if (!$imap) {
    echo "imap_open failed: " . imap_last_error() . PHP_EOL;
    exit(2);
}

echo "imap_open OK\n";
$info = imap_check($imap);
if ($info) {
    echo "Messages: " . $info->Nmsgs . PHP_EOL;
    echo "Recent: " . $info->Recent . PHP_EOL;
}
imap_close($imap);
echo "Done\n";
?>

<?php
phpinfo();

?>
