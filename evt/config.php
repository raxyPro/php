<?php
declare(strict_types=1);

/**
 * OS-neutral local-first config.
 * Base directory where all physical folders will be created.
 * Keep it INSIDE the project for simplicity & safety.
 */
define('APP_TITLE', 'Event Recording App (Local)');
define('APP_TIMEZONE', 'Asia/Kolkata');

// Physical base folder for all workspaces
define('BASE_FOLDERS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'folders');

// Registry SQLite DB (central index of folders)
define('REGISTRY_DB_PATH', __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'registry.db');

// For uploads (per folder): <folder_path>/files/
define('FOLDER_FILES_SUBDIR', 'files');

// Per-folder DB location: <folder_path>/.event_app/events.db
define('FOLDER_APP_SUBDIR', '.event_app');
define('FOLDER_DB_FILENAME', 'events.db');

// Max upload size per request (server/php.ini can also limit)
define('MAX_UPLOAD_BYTES', 50 * 1024 * 1024); // 50 MB

date_default_timezone_set(APP_TIMEZONE);
