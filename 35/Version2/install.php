<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

use PDO;

try {
    $pdo = init_db($db_host, $db_port, $db_name, $db_user, $db_pass);

    // Drop tables
    $pdo->exec("DROP TABLE IF EXISTS posts");
    $pdo->exec("DROP TABLE IF EXISTS boards");

    // Recreate boards
    $pdo->exec("
        CREATE TABLE boards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            description TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Recreate posts
    $pdo->exec("
        CREATE TABLE posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            board_id INT NOT NULL,
            parent_id INT DEFAULT 0,
            name TEXT,
            subject TEXT,
            comment TEXT,
            image TEXT,
            datetime DATETIME,
            deleted BOOLEAN DEFAULT 0,
            CONSTRAINT fk_board
                FOREIGN KEY (board_id) REFERENCES boards (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    if (!file_exists($threads_dir)) {
        mkdir($threads_dir, 0755, true);
    }

    // Generate CSRF token file
    get_global_csrf_token($csrf_file);

    // (Optional) Fix perms
    fix_permissions(__DIR__);

    echo "Tables recreated successfully.<br>";
    echo "No boards yet. Log in to admin.php to create your first board.";
} catch (PDOException $e) {
    exit('Error during install: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES));
}
