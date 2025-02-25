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

$thread_id = filter_input(INPUT_GET, 'thread_id', FILTER_VALIDATE_INT, [
    'options' => ['default' => 0, 'min_range' => 1]
]);

if ($thread_id <= 0) {
    die("Invalid thread ID.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $name = sanitize_input($_POST['name'] ?? '');
    $comment = sanitize_input($_POST['body'] ?? '');

    if ($name === '' || $comment === '') {
        die("Name and Comment fields are required.");
    }

    $datetime = gmdate('Y-m-d\TH:i:s\Z');

    $stmt = $db->prepare("INSERT INTO posts (parent_id, name, subject, comment, image, datetime) VALUES (?, ?, '', ?, '', ?)");
    $stmt->bindValue(1, $thread_id, SQLITE3_INTEGER);
    $stmt->bindValue(2, $name, SQLITE3_TEXT);
    $stmt->bindValue(3, $comment, SQLITE3_TEXT);
    $stmt->bindValue(4, $datetime, SQLITE3_TEXT);
    $stmt->execute();

    $bump_stmt = $db->prepare("UPDATE posts SET datetime = ? WHERE id = ? AND parent_id = 0");
    $bump_stmt->bindValue(1, $datetime, SQLITE3_TEXT);
    $bump_stmt->bindValue(2, $thread_id, SQLITE3_INTEGER);
    $bump_stmt->execute();

    generate_static_index($db);
    generate_static_thread($db, $thread_id);

    header("Location: threads/thread_{$thread_id}.html");
    exit;
}

if (file_exists(__DIR__ . "/threads/thread_{$thread_id}.html")) {
    header("Location: threads/thread_{$thread_id}.html");
    exit;
}

$op_stmt = $db->prepare("SELECT * FROM posts WHERE id = ? AND parent_id = 0");
$op_stmt->bindValue(1, $thread_id, SQLITE3_INTEGER);
$op = $op_stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$op) {
    die("Thread not found.");
}

$replies_stmt = $db->prepare("SELECT * FROM posts WHERE parent_id = ? ORDER BY id ASC");
$replies_stmt->bindValue(1, $thread_id, SQLITE3_INTEGER);
$replies_q = $replies_stmt->execute();
$replies = [];
while ($row = $replies_q->fetchArray(SQLITE3_ASSOC)) {
    $replies[] = $row;
}

ob_start();
render_thread_page($op, $replies);
$html = ob_get_clean();

file_put_contents(__DIR__ . '/threads/thread_' . $thread_id . '.html', $html, LOCK_EX);

header("Location: threads/thread_{$thread_id}.html");
exit;
