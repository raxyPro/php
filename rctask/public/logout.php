<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

// Clear cached tasks and the service worker on this device
header('Clear-Site-Data: "cache", "storage"');
$_SESSION = [];
session_destroy();
header('Location: login.php');
