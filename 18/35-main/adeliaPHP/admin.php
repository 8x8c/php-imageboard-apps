<?php
declare(strict_types=1);

session_start();

// *** ADMIN PANEL CONFIGURATION ***
// Use password_hash('YourStrongPassword', PASSWORD_DEFAULT) to generate a hash.
$ADMIN_PASSWORD_HASH = password_hash('ChangeThisPassword', PASSWORD_DEFAULT);

// Setup error logging
error_reporting(E_ALL);
ini_set('display_errors', '1');
touch(__DIR__ . '/error.txt');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.txt');

// Constants (should match main board)
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', 'uploads/');
define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 20 MB
define('ALLOWED_MIME_TYPES', [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'video/mp4'
]);

try {
    $db = new SQLite3(__DIR__ . '/board.db', SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
} catch (Throwable $e) {
    die("Database error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

function esc(string $input): string {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

function isLoggedIn(): bool {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Save file (copied logic from main code)
function saveFile(array $file): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ALLOWED_MIME_TYPES, true)) {
        return null;
    }

    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'video/mp4'  => 'mp4',
        default       => null
    };

    if (!$extension) {
        return null;
    }

    if (str_starts_with($mime, 'image/')) {
        $imageType = @exif_imagetype($file['tmp_name']);
        if ($imageType === false) {
            return null;
        }
        $expectedType = match ($extension) {
            'jpg' => IMAGETYPE_JPEG,
            'png' => IMAGETYPE_PNG,
            'gif' => IMAGETYPE_GIF,
            'webp'=> IMAGETYPE_WEBP,
            default => null
        };
        if ($expectedType === null || $imageType !== $expectedType) {
            return null;
        }
    }

    $filename = bin2hex(random_bytes(8)) . '.' . $extension;
    $filepath = UPLOAD_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return null;
    }

    return $filename;
}

// Handle login
if (isset($_POST['admin_password']) && !isLoggedIn()) {
    if (password_verify($_POST['admin_password'], $ADMIN_PASSWORD_HASH)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: ' . basename(__FILE__));
        exit;
    } else {
        echo "<p style='color:red;text-align:center;'>Invalid password.</p>";
    }
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout' && isLoggedIn()) {
    unset($_SESSION['admin_logged_in']);
    header('Location: ' . basename(__FILE__));
    exit;
}

if (!isLoggedIn()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Admin Login</title>
        <style>
            body {
                background: #222;
                color: #f1f1f1;
                font-family: Arial, sans-serif;
                text-align:center;
                margin-top:100px;
            }
            input {
                margin: 5px 0;
                padding: 8px;
                border-radius: 5px;
                border:1px solid #555;
                background: #333;
                color: #f1f1f1;
            }
            button {
                padding: 8px 12px;
                border:none;
                background:#007bff;
                color:#fff;
                border-radius:5px;
                cursor:pointer;
            }
            button:hover {
                background:#0056b3;
            }
        </style>
    </head>
    <body>
        <h1>Admin Panel Login</h1>
        <form method="post">
            <input type="password" name="admin_password" placeholder="Admin Password" required>
            <br>
            <button type="submit">Login</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// Logged in: handle actions
$action = $_GET['action'] ?? null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Delete post
if ($action === 'delete' && $id > 0) {
    try {
        $db->exec('BEGIN IMMEDIATE TRANSACTION;');
        $row = $db->querySingle("SELECT parent, image FROM posts WHERE id = $id", true);
        if (!$row) {
            throw new Exception("Post not found.");
        }
        $parent = (int)$row['parent'];
        
        // Delete any associated file
        if (!empty($row['image'])) {
            $filepath = UPLOAD_DIR . $row['image'];
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }

        if ($parent === 0) {
            // Delete thread and its replies (and their files)
            $replyRes = $db->query("SELECT image FROM posts WHERE parent = $id");
            while ($reply = $replyRes->fetchArray(SQLITE3_ASSOC)) {
                if (!empty($reply['image'])) {
                    $rpath = UPLOAD_DIR . $reply['image'];
                    if (file_exists($rpath)) unlink($rpath);
                }
            }
            $db->exec("DELETE FROM posts WHERE id = $id OR parent = $id");
        } else {
            // Just a reply
            $db->exec("DELETE FROM posts WHERE id = $id");
        }
        $db->exec('COMMIT;');
        echo "<p style='color:green;text-align:center;'>Post deleted successfully.</p>";
    } catch (Throwable $e) {
        $db->exec('ROLLBACK;');
        echo "<p style='color:red;text-align:center;'>Error deleting post: " . esc($e->getMessage()) . "</p>";
    }
}

// Edit post
if ($action === 'edit' && $id > 0) {
    $post = $db->querySingle("SELECT * FROM posts WHERE id = $id", true);
    if (!$post) {
        echo "<p style='color:red;text-align:center;'>Post not found.</p>";
    } else {
        $isThread = ((int)$post['parent'] === 0);

        // Handle update
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'], $_POST['message'])) {
            $name = trim($_POST['name']);
            $message = trim($_POST['message']);
            $title = $isThread ? trim($_POST['title'] ?? '') : $post['title'];
            
            if (strlen($name) < 1 || strlen($message) < 3) {
                echo "<p style='color:red;text-align:center;'>Name and message are required, message must be at least 3 chars.</p>";
            } elseif ($isThread && strlen($title) < 1) {
                echo "<p style='color:red;text-align:center;'>Title is required for threads.</p>";
            } else {
                $newImage = $post['image']; // default to old image
                // Handle file deletion
                if (isset($_POST['delete_file']) && $_POST['delete_file'] === '1') {
                    if ($post['image']) {
                        $oldPath = UPLOAD_DIR . $post['image'];
                        if (file_exists($oldPath)) {
                            unlink($oldPath);
                        }
                        $newImage = null;
                    }
                }
                
                // Handle file replacement
                if (isset($_FILES['new_image']) && $_FILES['new_image']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $filename = saveFile($_FILES['new_image']);
                    if ($filename !== null) {
                        // Delete old image if any
                        if ($post['image']) {
                            $oldPath = UPLOAD_DIR . $post['image'];
                            if (file_exists($oldPath)) unlink($oldPath);
                        }
                        $newImage = $filename;
                    } else {
                        echo "<p style='color:red;text-align:center;'>File upload failed. Check the file type and size.</p>";
                    }
                }

                try {
                    $db->exec('BEGIN IMMEDIATE TRANSACTION;');
                    $stmt = $db->prepare("UPDATE posts SET name = :name, title = :title, message = :message, image = :image WHERE id = :id");
                    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt->bindValue(':title', $title, SQLITE3_TEXT);
                    $stmt->bindValue(':message', $message, SQLITE3_TEXT);
                    $stmt->bindValue(':image', $newImage, SQLITE3_TEXT);
                    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                    $stmt->execute();
                    $db->exec('COMMIT;');
                    echo "<p style='color:green;text-align:center;'>Post edited successfully.</p>";
                    // Redirect back after edit
                    if ($isThread) {
                        echo "<p style='text-align:center;'><a href='".basename(__FILE__)."?'>Back to Thread List</a></p>";
                    } else {
                        $parent = (int)$post['parent'];
                        echo "<p style='text-align:center;'><a href='".basename(__FILE__)."?action=view&id=$parent'>Back to Thread</a></p>";
                    }
                    exit;
                } catch (Throwable $e) {
                    $db->exec('ROLLBACK;');
                    echo "<p style='color:red;text-align:center;'>Error editing post: " . esc($e->getMessage()) . "</p>";
                }
            }
        }

        // Show edit form
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Edit Post</title>
            <style>
                body { background:#222; color:#f1f1f1; font-family:Arial,sans-serif; margin:20px; }
                input[type="text"], textarea, input[type="file"] {
                    display:block; width:90%; max-width:500px; background:#555; color:#f1f1f1;
                    border:1px solid #666; border-radius:3px; padding:8px; margin-bottom:10px; 
                }
                button { background:#007bff; color:#fff; border:none; border-radius:5px; padding:8px 12px; cursor:pointer; }
                button:hover { background:#0056b3; }
                a { color:#0af; text-decoration:none; }
                a:hover { text-decoration:underline; }
                .thumbnail {
                    max-width:200px; 
                    max-height:150px; 
                    margin-top:10px;
                    border:1px solid #444;
                    display:block;
                }
                video.thumbnail { width:200px; height:auto; }
            </style>
        </head>
        <body>
        <h1>Edit Post #<?= (int)$post['id'] ?></h1>
        <form method="post" enctype="multipart/form-data">
            <input type="text" name="name" value="<?= esc($post['name']) ?>" placeholder="Name" required>
            <?php if ($isThread): ?>
                <input type="text" name="title" value="<?= esc($post['title']) ?>" placeholder="Title" required>
            <?php endif; ?>
            <textarea name="message" rows="4" placeholder="Message" required><?= esc($post['message']) ?></textarea><br>
            
            <?php if ($post['image']): ?>
                <p>Current file: <?= esc($post['image']) ?></p>
                <?php
                $ext = pathinfo($post['image'], PATHINFO_EXTENSION);
                if ($ext === 'mp4'): ?>
                    <video class="thumbnail" controls>
                        <source src="<?= esc(UPLOAD_URL . $post['image']) ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                <?php else: ?>
                    <img class="thumbnail" src="<?= esc(UPLOAD_URL . $post['image']) ?>" alt="Image">
                <?php endif; ?>
                <br>
                <label><input type="checkbox" name="delete_file" value="1"> Delete current file</label><br>
                <p>Or upload a new file to replace:</p>
            <?php else: ?>
                <p>No file currently attached. Upload a new file if desired:</p>
            <?php endif; ?>
            
            <input type="file" name="new_image" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4"><br>
            <button type="submit">Save Changes</button>
        </form>
        <?php if ($isThread): ?>
            <p><a href="<?= basename(__FILE__) ?>">Cancel and go back to Thread List</a></p>
        <?php else:
            $parent = (int)$post['parent']; ?>
            <p><a href="<?= basename(__FILE__) ?>?action=view&id=<?= $parent ?>">Cancel and go back to Thread</a></p>
        <?php endif; ?>
        </body>
        </html>
        <?php
        exit;
    }
}

// View thread and replies
if ($action === 'view' && $id > 0) {
    $thread = $db->querySingle("SELECT * FROM posts WHERE id = $id AND parent = 0", true);
    if (!$thread) {
        echo "<p style='color:red;text-align:center;'>Thread not found.</p>";
    } else {
        $replies = $db->query("SELECT * FROM posts WHERE parent = $id ORDER BY created_at ASC");
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>View Thread</title>
            <style>
                body { background:#222; color:#f1f1f1; font-family:Arial,sans-serif; margin:20px; }
                .post {
                    border:1px solid #444; border-radius:5px; padding:10px; margin-bottom:15px;
                    background:#333; word-wrap:break-word; overflow-wrap:break-word; white-space:pre-wrap;
                }
                .reply {
                    background:#444; margin:10px 20px; padding:8px; border-radius:3px;
                }
                a { color:#0af; text-decoration:none; }
                a:hover { text-decoration:underline; }
                button {
                    background:#007bff; color:#fff; border:none; border-radius:5px;
                    padding:8px 12px; cursor:pointer;
                }
                button:hover { background:#0056b3; }
                .top-bar { text-align:right; margin-bottom:20px; }
                .thumbnail {
                    max-width:200px; 
                    max-height:150px; 
                    margin-top:10px;
                    border:1px solid #444;
                    display:block;
                }
                video.thumbnail { width:200px; height:auto; }
            </style>
        </head>
        <body>
        <div class="top-bar">
            <a href="<?= basename(__FILE__) ?>?action=logout">Logout</a> | <a href="<?= basename(__FILE__) ?>">Thread List</a>
        </div>
        <h1>Viewing Thread #<?= (int)$thread['id'] ?></h1>
        <div class="post">
            <strong>Thread #<?= (int)$thread['id'] ?></strong> by <?= esc($thread['name']) ?><br>
            Title: <?= esc($thread['title']) ?><br>
            <a href="<?= basename(__FILE__) ?>?action=delete&id=<?= (int)$thread['id'] ?>" onclick="return confirm('Delete this thread and all replies?');">[Delete]</a>
            | <a href="<?= basename(__FILE__) ?>?action=edit&id=<?= (int)$thread['id'] ?>">[Edit]</a>
            <hr>
            <?= nl2br(esc($thread['message'])) ?>
            <?php if ($thread['image']):
                $ext = pathinfo($thread['image'], PATHINFO_EXTENSION);
                if ($ext === 'mp4'): ?>
                    <hr>
                    <video class="thumbnail" controls>
                        <source src="<?= esc(UPLOAD_URL . $thread['image']) ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                <?php else: ?>
                    <hr>
                    <img class="thumbnail" src="<?= esc(UPLOAD_URL . $thread['image']) ?>" alt="Image">
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php while($reply = $replies->fetchArray(SQLITE3_ASSOC)): ?>
            <div class="reply">
                <strong>Reply #<?= (int)$reply['id'] ?></strong> by <?= esc($reply['name']) ?><br>
                <a href="<?= basename(__FILE__) ?>?action=delete&id=<?= (int)$reply['id'] ?>" onclick="return confirm('Delete this reply?');">[Delete]</a>
                | <a href="<?= basename(__FILE__) ?>?action=edit&id=<?= (int)$reply['id'] ?>">[Edit]</a>
                <hr>
                <?= nl2br(esc($reply['message'])) ?>
                <?php if ($reply['image']):
                    $ext = pathinfo($reply['image'], PATHINFO_EXTENSION);
                    if ($ext === 'mp4'): ?>
                        <hr>
                        <video class="thumbnail" controls>
                            <source src="<?= esc(UPLOAD_URL . $reply['image']) ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    <?php else: ?>
                        <hr>
                        <img class="thumbnail" src="<?= esc(UPLOAD_URL . $reply['image']) ?>" alt="Image">
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
        </body>
        </html>
        <?php
    }
    exit;
}

// Default view - Thread list
try {
    $threads = $db->query("SELECT id, name, title FROM posts WHERE parent = 0 ORDER BY updated_at DESC");
} catch (Throwable $e) {
    die("Error fetching threads: " . esc($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Panel - Thread List</title>
<style>
    body {
        background: #222;
        color: #f1f1f1;
        font-family: Arial, sans-serif;
        margin: 20px;
    }
    a { color: #0af; text-decoration: none; }
    a:hover { text-decoration: underline; }
    table {
        width:100%;
        border-collapse:collapse;
        margin-bottom:20px;
    }
    th, td {
        padding:10px; border-bottom:1px solid #444;
    }
    th { background:#333; }
    tr:hover { background:#333; }
    .top-bar { text-align:right; margin-bottom:20px; }
</style>
</head>
<body>
<div class="top-bar">
    <a href="<?= basename(__FILE__) ?>?action=logout">Logout</a>
</div>
<h1>Admin Panel - Thread List</h1>
<p>Click "View" to see and manage that thread and its replies, or "Edit" to modify it.</p>
<table>
    <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Title</th>
        <th>Actions</th>
    </tr>
    <?php while ($thread = $threads->fetchArray(SQLITE3_ASSOC)): ?>
        <tr>
            <td><?= (int)$thread['id'] ?></td>
            <td><?= esc($thread['name']) ?></td>
            <td><?= esc($thread['title']) ?></td>
            <td>
                <a href="<?= basename(__FILE__) ?>?action=view&id=<?= (int)$thread['id'] ?>">[View]</a>
                <a href="<?= basename(__FILE__) ?>?action=edit&id=<?= (int)$thread['id'] ?>">[Edit]</a>
                <a href="<?= basename(__FILE__) ?>?action=delete&id=<?= (int)$thread['id'] ?>" onclick="return confirm('Delete this thread and all replies?');">[Delete]</a>
            </td>
        </tr>
    <?php endwhile; ?>
</table>
</body>
</html>
