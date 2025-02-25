<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

use PDO;

/**
 * This file is copied to a new board directory as `chess.php`.
 * It handles creating a new topic (OP post) in that specific board.
 */

// Derive board name from this file’s containing directory.
$board_name = basename(__DIR__);
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $board_name)) {
    exit('Invalid board name.');
}

// Initialize DB
$db = init_db($db_host, $db_port, $db_name, $db_user, $db_pass);
$board = get_board_by_name($db, $board_name);
if (!$board) {
    exit('Board not found.');
}
$board_id = (int)$board['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post'])) {
    verify_csrf_token($csrf_file);

    $name    = sanitize_input($_POST['name'] ?? '', 35);
    $subject = sanitize_input($_POST['subject'] ?? '', 100);
    $comment = sanitize_input($_POST['body'] ?? '', 2000);

    if ($name === '' || $subject === '' || $comment === '') {
        exit('All fields (Name, Subject, Comment) are required.');
    }

    $datetime   = gmdate('Y-m-d H:i:s');
    $image_path = '';

    // File upload
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK && $_FILES['file']['size'] > 0) {
        if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
            exit('File too large (max 5MB).');
        }

        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts, true)) {
            exit('Invalid file extension.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['file']['tmp_name']);
        $allowed_mimes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'mp4'  => 'video/mp4',
        ];
        if (!isset($allowed_mimes[$ext]) || $allowed_mimes[$ext] !== $mime) {
            exit('Invalid file type.');
        }

        $filename = time() . '_' . random_int(1000, 9999) . '.' . $ext;
        $target   = __DIR__ . '/uploads/' . $filename;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
            exit('Could not save uploaded file.');
        }
        $image_path = $filename;
    }

    // Insert new thread (OP)
    $table = get_table_name();
    $stmt  = $db->prepare("
        INSERT INTO {$table} 
        (board_id, parent_id, name, subject, comment, image, datetime, deleted) 
        VALUES 
        (:bid, 0, :name, :subject, :comment, :image, :datetime, false)
    ");
    $stmt->bindValue(':bid',      $board_id,  PDO::PARAM_INT);
    $stmt->bindValue(':name',     $name,      PDO::PARAM_STR);
    $stmt->bindValue(':subject',  $subject,   PDO::PARAM_STR);
    $stmt->bindValue(':comment',  $comment,   PDO::PARAM_STR);
    $stmt->bindValue(':image',    $image_path,PDO::PARAM_STR);
    $stmt->bindValue(':datetime', $datetime,  PDO::PARAM_STR);
    $stmt->execute();

    $thread_id = (int)$db->lastInsertId();

    generate_all_index_pages($db, $board_id, $threads_per_page);
    generate_static_thread($db, $thread_id);

    header('Location: index.html');
    exit;
}

// If no index.html, generate it
$index_file = __DIR__ . '/index.html';
if (!file_exists($index_file)) {
    generate_all_index_pages($db, $board_id, $threads_per_page);
}

// If page=? is set, redirect accordingly
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page === 1) {
    header('Location: index.html');
} else {
    header('Location: index_' . $page . '.html');
}
exit;
