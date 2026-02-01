<?php
declare(strict_types=1);

// MySQL config (update these)
define('MYSQL_HOST', '127.0.0.1');
define('MYSQL_PORT', 3306);
define('MYSQL_DB', 'rcmain');
define('MYSQL_USER', 'rax');
define('MYSQL_PASS', '512');

define('MYSQL_DSN', 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT . ';dbname=' . MYSQL_DB . ';charset=utf8mb4');
