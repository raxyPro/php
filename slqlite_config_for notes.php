// ==== CONFIG ====
const DB_PATH = __DIR__ . '/data/notes.sqlite'; // change if needed
const APP_TIMEZONE = 'Asia/Kolkata';            // change if needed

// ==== BOOTSTRAP ====
ini_set('display_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set(APP_TIMEZONE);

function pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // Ensure directory exists
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create DB directory: ' . $dir);
            }
        }

        $dsn = 'sqlite:' . DB_PATH;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, null, null, $options);

        // Pragmas: durability & constraints
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');

        ensureSchema($pdo);
    }
    return $pdo;
}

function ensureSchema(PDO $pdo): void {
    // Create table (SQLite flavor) + trigger to maintain updated_at
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
    SQL);

    // Trigger to auto-update updated_at on row updates
    $pdo->exec(<<<SQL
        CREATE TRIGGER IF NOT EXISTS notes_set_updated_at
        AFTER UPDATE ON notes
        FOR EACH ROW
        BEGIN
            UPDATE notes
            SET updated_at = datetime('now')
            WHERE id = NEW.id;
        END;
    SQL);
}
