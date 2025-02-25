<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Error logging configuration
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');

// Create directories if not exist
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Create threads directory
$threads_dir = __DIR__ . '/threads/';
if (!file_exists($threads_dir)) {
    mkdir($threads_dir, 0777, true);
}

$db = new SQLite3($db_file);

// Create table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER DEFAULT 0,
    name TEXT,
    subject TEXT,
    comment TEXT,
    image TEXT,
    datetime TEXT
)");

// Enable WAL mode
$db->exec("PRAGMA journal_mode = WAL;");

// Vacuum and analyze
$db->exec("VACUUM;");
$db->exec("ANALYZE;");

// Generate initial empty index.html with no posts
ob_start();
render_board_index($db, null);
$html = ob_get_clean();
file_put_contents(__DIR__ . '/index.html', $html);

echo "Installation completed successfully. You can now open chess.php or index.html in your browser.\n";
