<?php
declare(strict_types=1);

/**
 * OS-neutral config.
 */
define('APP_TITLE', 'Event Recording App (MySQL)');
define('APP_TIMEZONE', 'Asia/Kolkata');

require_once __DIR__ . '/mysql.config.php';

date_default_timezone_set(APP_TIMEZONE);
