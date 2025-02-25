<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

use PDO;

/**
 * Copied into a new board folder as `reply.php`.
 * It handles replies (and optional admin deletions).
 */

$board_name = basename(__DIR__);
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $board_name)) {
    exit('Invalid board name.');
}

$db = init_db($db_host, $db_port, $db_name, $db_user, $db_pass);
$board = get_board_by_name($db, $board_name);
if (!$board) {
    exit('Invalid board.');
}
$board_id = (int)$board['id'];

$table     = get_table_name();
$thread_id = filter_input(INPUT_GET, 'thread_id', FILTER_VALIDATE_INT, [
    'options' => ['default' => 0, 'min_range' => 1]
]);
if ($thread_id <= 0) {
    exit('Invalid thread ID.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($csrf_file);

    // Admin deletion
    if (isset($_POST['delete_selected'])) {
        $admin_pw = $_POST['admin_pw'] ?? '';
        if ($admin_pw === $admin_password) {
            // Find selected posts to delete
            $checked_posts = [];
            foreach ($_POST as $key => $val) {
                if (str_starts_with($key, 'delete_') && $val === 'on') {
                    $post_id = (int)str_replace('delete_', '', $key);
                    if ($post_id > 0) {
                        $checked_posts[] = $post_id;
                    }
                }
            }

            if (!empty($checked_posts)) {
                // If the OP (thread_id) is in the checked list, delete the whole thread
                if (in_array($thread_id, $checked_posts, true)) {
                    $del_stmt = $db->prepare("
                        UPDATE {$table}
                        SET deleted = true
                        WHERE (id = :tid OR parent_id = :tid)
                          AND board_id = :bid
                    ");
                    $del_stmt->bindValue(':tid', $thread_id, PDO::PARAM_INT);
                    $del_stmt->bindValue(':bid', $board_id,  PDO::PARAM_INT);
                    $del_stmt->execute();

                    generate_all_index_pages($db, $board_id, $threads_per_page);
                    generate_static_thread($db, $thread_id);
                    header('Location: ../index.html');
                    exit;
                } else {
                    // Delete selected replies only
                    $in_placeholders = implode(',', array_fill(0, count($checked_posts), '?'));
                    $query           = "UPDATE {$table} SET deleted = true WHERE board_id = ? AND id IN ({$in_placeholders})";
                    $del_stmt        = $db->prepare($query);

                    // first param is board_id
                    $del_stmt->bindValue(1, $board_id, PDO::PARAM_INT);
                    // subsequent params are post IDs
                    foreach ($checked_posts as $i => $pid) {
                        $del_stmt->bindValue($i+2, $pid, PDO::PARAM_INT); 
                    }
                    $del_stmt->execute();

                    generate_all_index_pages($db, $board_id, $threads_per_page);
                    generate_static_thread($db, $thread_id);
                    header("Location: threads/thread_{$thread_id}.html");
                    exit;
                }
            }
            // No posts selected, just reload
            header("Location: threads/thread_{$thread_id}.html");
            exit;
        } else {
            // Wrong admin pass
            header("Location: threads/thread_{$thread_id}.html");
            exit;
        }
    }

    // Normal reply
    elseif (isset($_POST['post'])) {
        $name    = sanitize_input($_POST['name'] ?? '', 35);
        $comment = sanitize_input($_POST['body'] ?? '', 2000);

        if ($name === '' || $comment === '') {
            exit('Name and Comment fields are required.');
        }

        $datetime   = gmdate('Y-m-d H:i:s');
        $image_path = '';

        // Optional upload
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK && $_FILES['file']['size'] > 0) {
            if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
                exit('File too large.');
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
                'mp4'  => 'video/mp4'
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

        // Insert reply
        $stmt = $db->prepare("
            INSERT INTO {$table} 
            (board_id, parent_id, name, subject, comment, image, datetime, deleted)
            VALUES 
            (:bid, :tid, :name, '', :comment, :image, :datetime, false)
        ");
        $stmt->bindValue(':bid',      $board_id,  PDO::PARAM_INT);
        $stmt->bindValue(':tid',      $thread_id, PDO::PARAM_INT);
        $stmt->bindValue(':name',     $name,      PDO::PARAM_STR);
        $stmt->bindValue(':comment',  $comment,   PDO::PARAM_STR);
        $stmt->bindValue(':image',    $image_path,PDO::PARAM_STR);
        $stmt->bindValue(':datetime', $datetime,  PDO::PARAM_STR);
        $stmt->execute();

        // Bump thread (OP's datetime)
        $bump_stmt = $db->prepare("
            UPDATE {$table}
            SET datetime = :datetime
            WHERE id = :tid
              AND parent_id = 0
              AND deleted = false
              AND board_id = :bid
        ");
        $bump_stmt->bindValue(':datetime', $datetime,  PDO::PARAM_STR);
        $bump_stmt->bindValue(':tid',      $thread_id, PDO::PARAM_INT);
        $bump_stmt->bindValue(':bid',      $board_id,  PDO::PARAM_INT);
        $bump_stmt->execute();

        generate_all_index_pages($db, $board_id, $threads_per_page);
        generate_static_thread($db, $thread_id);

        header("Location: threads/thread_{$thread_id}.html");
        exit;
    }
}

// If the thread file exists, just go there
$thread_file = __DIR__ . "/threads/thread_{$thread_id}.html";
if (file_exists($thread_file)) {
    header("Location: threads/thread_{$thread_id}.html");
    exit;
}

// If OP is not found or is deleted, go back to board index
$op_stmt = $db->prepare("
    SELECT * 
    FROM {$table} 
    WHERE id = :tid 
      AND parent_id = 0 
      AND deleted = false 
      AND board_id = :bid
");
$op_stmt->bindValue(':tid', $thread_id, PDO::PARAM_INT);
$op_stmt->bindValue(':bid', $board_id,  PDO::PARAM_INT);
$op_stmt->execute();
$op = $op_stmt->fetch(PDO::FETCH_ASSOC);

if (!$op) {
    header('Location: ../index.html');
    exit;
}

// If we reach here, the static thread doesn’t exist or is old => create or regenerate it
$replies_stmt = $db->prepare("
    SELECT * 
    FROM {$table} 
    WHERE parent_id = :tid
      AND deleted = false
      AND board_id = :bid
    ORDER BY id ASC
");
$replies_stmt->bindValue(':tid', $thread_id, PDO::PARAM_INT);
$replies_stmt->bindValue(':bid', $board_id,  PDO::PARAM_INT);
$replies_stmt->execute();
$replies = $replies_stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
render_thread_page($db, $board_name, $op, $replies);
$html = ob_get_clean();

$threads_dir = __DIR__ . "/threads/";
if (!is_dir($threads_dir)) {
    mkdir($threads_dir, 0755, true);
}
file_put_contents($threads_dir . 'thread_' . $thread_id . '.html', $html, LOCK_EX);

header("Location: threads/thread_{$thread_id}.html");
exit;
