<?php
declare(strict_types=1);
// reply_template.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

use PDO;

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

$table = get_table_name();
$thread_id = filter_input(INPUT_GET, 'thread_id', FILTER_VALIDATE_INT, [
    'options' => ['default' => 0, 'min_range' => 1]
]);
if ($thread_id <= 0) {
    exit('Invalid thread ID.');
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($csrf_file);

    // Admin deletion
    if (isset($_POST['delete_selected'])) {
        $admin_pw = $_POST['admin_pw'] ?? '';
        if (!password_verify($admin_pw, $admin_password_hash)) {
            header("Location: threads/thread_{$thread_id}.html");
            exit;
        }

        // Collect checked posts
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
            // If the OP is in the list => delete entire thread
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
                // Delete only selected replies
                $in_placeholders = implode(',', array_fill(0, count($checked_posts), '?'));
                $query = "UPDATE {$table} SET deleted = true WHERE board_id = ? AND id IN ($in_placeholders)";
                $del_stmt = $db->prepare($query);
                $del_stmt->bindValue(1, $board_id, PDO::PARAM_INT);
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
        // If none checked => just reload
        header("Location: threads/thread_{$thread_id}.html");
        exit;
    }
    // Normal reply
    elseif (isset($_POST['post'])) {
        // Parse tripcode
        $raw_name = sanitize_input($_POST['name'] ?? '', 35);
        $parsed   = parse_name_with_tripcode($raw_name, $tripcode_pepper);
        $name     = $parsed['display_name'];

        $comment = sanitize_input($_POST['body'] ?? '', 2000);

        if ($name === '' || $comment === '') {
            exit('Name and Comment fields are required.');
        }

        $datetime   = gmdate('Y-m-d H:i:s');
        $image_path = '';

        // Optional file upload
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
                exit('Invalid file type (MIME mismatch).');
            }

            if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
                if (!is_valid_image_gd($_FILES['file']['tmp_name'])) {
                    exit('Invalid image data.');
                }
            }
            if ($ext === 'mp4') {
                if (!is_valid_mp4($_FILES['file']['tmp_name'])) {
                    exit('Invalid MP4 data.');
                }
            }

            $filename = time() . '_' . random_int(1000, 9999) . '.' . $ext;
            $target   = __DIR__ . '/uploads/' . $filename;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                exit('Could not save uploaded file.');
            }
            $image_path = $filename;
        }

        // Insert the reply
        $stmt = $db->prepare("
            INSERT INTO {$table}
            (board_id, parent_id, name, subject, comment, image, datetime, deleted)
            VALUES
            (:bid, :tid, :name, '', :comm, :img, :dt, false)
        ");
        $stmt->bindValue(':bid',  $board_id,  PDO::PARAM_INT);
        $stmt->bindValue(':tid',  $thread_id, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':comm', $comment);
        $stmt->bindValue(':img',  $image_path);
        $stmt->bindValue(':dt',   $datetime);
        $stmt->execute();

        // Bump thread datetime
        $bump_stmt = $db->prepare("
            UPDATE {$table}
            SET datetime = :dt
            WHERE id = :tid
              AND parent_id = 0
              AND deleted = false
              AND board_id = :bid
        ");
        $bump_stmt->bindValue(':dt',   $datetime);
        $bump_stmt->bindValue(':tid',  $thread_id, PDO::PARAM_INT);
        $bump_stmt->bindValue(':bid',  $board_id,  PDO::PARAM_INT);
        $bump_stmt->execute();

        generate_all_index_pages($db, $board_id, $threads_per_page);
        generate_static_thread($db, $thread_id);

        header("Location: threads/thread_{$thread_id}.html");
        exit;
    }
}

// If thread file exists => just redirect
$thread_file = __DIR__ . "/threads/thread_{$thread_id}.html";
if (file_exists($thread_file)) {
    header("Location: threads/thread_{$thread_id}.html");
    exit;
}

// If OP is missing => go back
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

// Rebuild static thread if it's missing
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
