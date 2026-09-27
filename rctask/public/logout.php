<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$_SESSION = [];
session_destroy();
header('Location: login.php');
