<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "PHP: " . PHP_VERSION . PHP_EOL;
echo "IMAP loaded: " . (extension_loaded('imap') ? "yes" : "no") . PHP_EOL;

if (!function_exists('imap_open')) {
    echo "imap_open() not available.\n";
    exit(1);
}

// Edit these:
$host = "imap.example.com";
$port = 993;
$user = "user@example.com";
$pass = "password";

// SSL example:
$mailbox = "{" . $host . ":" . $port . "/imap/ssl}INBOX";

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