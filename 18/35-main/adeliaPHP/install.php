<?php
declare(strict_types=1);

// Setup error logging just like in index.php
error_reporting(E_ALL);
ini_set('display_errors', '1');
touch(__DIR__ . '/error.txt');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.txt');

define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('DB_FILE', __DIR__ . '/board.db');

// Ensure the upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    if (!mkdir(UPLOAD_DIR, 0755, true)) {
        die("Failed to create uploads directory: " . UPLOAD_DIR);
    }
}

// Try opening/creating the database
try {
    $db = new SQLite3(DB_FILE, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
} catch (Throwable $e) {
    die("Database initialization error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// Create the posts table if it doesn’t exist
try {
    $db->exec("CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent INTEGER NOT NULL DEFAULT 0,
        name TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        image TEXT DEFAULT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL DEFAULT 0
    )");
} catch (Throwable $e) {
    die("Error creating table: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// Enable WAL mode
try {
    $result = $db->querySingle("PRAGMA journal_mode=WAL;");
    if (strtoupper($result) !== 'WAL') {
        die("Failed to enable WAL mode. Current journal_mode: " . htmlspecialchars($result, ENT_QUOTES, 'UTF-8'));
    }
} catch (Throwable $e) {
    die("Error enabling WAL mode: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// If we reach here, the database and table are ready and WAL is enabled.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Install Complete</title>
    <style>
        body {
            background: #f0f0f0; 
            color: #333; 
            font-family: Arial, sans-serif; 
            padding: 20px; 
            max-width:600px; 
            margin:auto;
        }
        h1 {
            text-align:center;
            color:#008000;
        }
    </style>
</head>
<body>
    <h1>Installation Complete</h1>
    <p>The database and uploads directory have been set up successfully, and WAL mode is enabled.</p>
    <p>You can now remove <code>install.php</code> for security reasons and start using the board by visiting <a href="index.php">index.php</a>.</p>
</body>
</html>
