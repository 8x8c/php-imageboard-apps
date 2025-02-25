<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// Error logging configuration
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');

$db = init_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $comment = trim($_POST['body'] ?? '');

    if ($name === '' || $subject === '' || $comment === '') {
        die("All fields (Name, Subject, Comment) are required.");
    }

    $datetime = gmdate('Y-m-d\TH:i:s\Z');

    $image_path = '';
    if (isset($_FILES['file']) && $_FILES['file']['size'] > 0 && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_exts, true)) {
            $filename = time() . '_' . random_int(1000,9999) . '.' . $ext;
            $target = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                $image_path = $filename;
            }
        }
    }

    $stmt = $db->prepare("INSERT INTO posts (parent_id, name, subject, comment, image, datetime) VALUES (0, ?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $name, SQLITE3_TEXT);
    $stmt->bindValue(2, $subject, SQLITE3_TEXT);
    $stmt->bindValue(3, $comment, SQLITE3_TEXT);
    $stmt->bindValue(4, $image_path, SQLITE3_TEXT);
    $stmt->bindValue(5, $datetime, SQLITE3_TEXT);
    $stmt->execute();

    // Regenerate static pages
    generate_static_index($db);
    $thread_id = (int)$db->lastInsertRowID();
    generate_static_thread($db, $thread_id);

    header("Location: index.html");
    exit;
}

if (!file_exists(__DIR__ . '/index.html')) {
    generate_static_index($db);
}

header("Location: index.html");
exit;
