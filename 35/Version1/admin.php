<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

use PDO;

// Prepare DB
$db = init_db($db_host, $db_port, $db_name, $db_user, $db_pass);

// If admin not logged in, handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $pw = $_POST['admin_pw'] ?? '';
    if (!is_string($pw)) {
        exit('Invalid input.');
    }
    if ($pw === $admin_password) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $login_error = 'Incorrect password.';
    }
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    ?>
    <!doctype html>
    <html><head><meta charset="utf-8"><title>Admin Login</title></head><body>
    <h1>Admin Login</h1>
    <?php if (!empty($login_error)) : ?>
        <p style="color:red;"><?php echo htmlspecialchars($login_error, ENT_QUOTES); ?></p>
    <?php endif; ?>
    <form method="post">
        <input type="password" name="admin_pw" placeholder="Password" required>
        <input type="submit" name="admin_login" value="Login">
    </form>
    </body></html>
    <?php
    exit;
}

// Admin is logged in
$action = $_GET['action'] ?? '';

// Generate CSRF token
$csrf_token = htmlspecialchars(get_global_csrf_token($csrf_file), ENT_QUOTES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($csrf_file);

    if ($action === 'create_board') {
        $name = sanitize_input($_POST['name'] ?? '', 50);
        $desc = sanitize_input($_POST['description'] ?? '', 255);

        // Check board name format
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            exit('Invalid board name.');
        }

        // Insert new board
        $stmt = $db->prepare("INSERT INTO boards (name, description) VALUES (:name, :desc)");
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':desc', $desc, PDO::PARAM_STR);
        $stmt->execute();

        // Create directories
        $board_dir = __DIR__ . '/' . $name;
        if (!is_dir($board_dir)) {
            mkdir($board_dir, 0755, true);
        }
        if (!is_dir($board_dir . '/uploads')) {
            mkdir($board_dir . '/uploads', 0755, true);
        }
        if (!is_dir($board_dir . '/threads')) {
            mkdir($board_dir . '/threads', 0755, true);
        }

        // If templates exist, copy them
        if (file_exists(__DIR__ . '/chess_template.php')) {
            copy(__DIR__ . '/chess_template.php', $board_dir . '/chess.php');
        }
        if (file_exists(__DIR__ . '/reply_template.php')) {
            copy(__DIR__ . '/reply_template.php', $board_dir . '/reply.php');
        }

        // Generate empty index
        $new_board_id = (int)$db->lastInsertId();
        generate_all_index_pages($db, $new_board_id, $threads_per_page);

        header('Location: admin.php');
        exit;
    } elseif ($action === 'delete_boards') {
        $checked_boards = $_POST['delete_board'] ?? [];
        $admin_pw       = $_POST['admin_pw'] ?? '';

        if (!is_array($checked_boards)) {
            $checked_boards = [];
        }

        if ($admin_pw !== $admin_password) {
            exit('Incorrect admin password.');
        }

        if (!empty($checked_boards)) {
            foreach ($checked_boards as $bid) {
                $bid = (int)$bid;
                // Fetch board name
                $board_stmt = $db->prepare("SELECT name FROM boards WHERE id=:bid");
                $board_stmt->bindValue(':bid', $bid, PDO::PARAM_INT);
                $board_stmt->execute();
                $bname = $board_stmt->fetchColumn();

                if ($bname && preg_match('/^[a-zA-Z0-9_-]+$/', $bname)) {
                    // Delete from boards
                    $del_stmt = $db->prepare("DELETE FROM boards WHERE id=:bid");
                    $del_stmt->bindValue(':bid', $bid, PDO::PARAM_INT);
                    $del_stmt->execute();

                    // Remove directory
                    $board_dir = __DIR__ . '/' . $bname;
                    if (is_dir($board_dir)) {
                        delete_directory($board_dir);
                    }
                }
            }
        }
        header('Location: admin.php');
        exit;
    }
}

function delete_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            delete_directory($path);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$boards = get_boards($db);
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Admin Panel</title></head><body>
<h1>Admin Panel</h1>

<h2>Create New Board</h2>
<form method="post" action="?action=create_board">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    Board Name: <input type="text" name="name" required><br>
    Description: <input type="text" name="description"><br>
    <input type="submit" value="Create Board">
</form>

<h2>Existing Boards</h2>
<?php if (empty($boards)): ?>
    <p>No boards exist yet.</p>
<?php else: ?>
    <form method="post" action="?action=delete_boards">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <table border="1" cellpadding="5">
            <tr><th>Delete</th><th>ID</th><th>Name</th><th>Description</th></tr>
            <?php foreach ($boards as $b): ?>
                <tr>
                    <td><input type="checkbox" name="delete_board[]" value="<?php echo (int)$b['id']; ?>"></td>
                    <td><?php echo (int)$b['id']; ?></td>
                    <td><?php echo htmlspecialchars($b['name'] ?? '', ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars($b['description'] ?? '', ENT_QUOTES); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p>
            Admin Password: <input type="password" name="admin_pw" required>
            <input type="submit" value="Delete Selected Boards">
        </p>
    </form>
<?php endif; ?>
</body></html>
