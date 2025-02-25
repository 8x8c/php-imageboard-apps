<?php
/**************************************************
 * post.php
 * -----------------------------------------------
 * This script handles:
 *  - New post submissions (with optional file upload)
 *  - Deletion of posts (via a hard-coded admin password)
 *  - Storing posts (and replies) in SQLite3 via PDO
 *  - Regenerating static paginated HTML pages for the main board
 *
 * It uses shared functions from common.php.
 **************************************************/

require_once 'common.php';

// Configuration
$dbFile       = __DIR__ . '/board.db';
$uploadsDir   = __DIR__ . '/uploads';
$thumbsDir    = __DIR__ . '/thumb';
$maxThumbSize = 250;
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4'];
$adminPassword = "secret123";  // Hard-coded password for deletion
$postsPerPage  = 20;

// Establish PDO connection
$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create tables if they don't exist. Note: bumped_at is used for bumping posts.
$pdo->exec("CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    subject TEXT,
    message TEXT,
    filename TEXT,
    filepath TEXT,
    thumbpath TEXT,
    mimetype TEXT,
    created_at INTEGER,
    bumped_at INTEGER,
    reply_count INTEGER DEFAULT 0
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS replies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER,
    name TEXT,
    message TEXT,
    created_at INTEGER,
    reply_number INTEGER,
    FOREIGN KEY(post_id) REFERENCES posts(id)
)");

/**************************************************
 * PROCESS DELETION
 **************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deletepost'])) {
    if (isset($_POST['password']) && $_POST['password'] === $adminPassword && isset($_POST['delete'])) {
        $deleteIds = $_POST['delete'];
        $stmtSelect = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
        $stmtDeletePost = $pdo->prepare("DELETE FROM posts WHERE id = ?");
        $stmtDeleteReplies = $pdo->prepare("DELETE FROM replies WHERE post_id = ?");
        foreach ($deleteIds as $id) {
            $stmtSelect->execute([$id]);
            $post = $stmtSelect->fetch(PDO::FETCH_ASSOC);
            if ($post) {
                if (!empty($post['filepath']) && file_exists(__DIR__ . '/' . $post['filepath'])) {
                    unlink(__DIR__ . '/' . $post['filepath']);
                }
                if (!empty($post['thumbpath']) && file_exists(__DIR__ . '/' . $post['thumbpath'])) {
                    unlink(__DIR__ . '/' . $post['thumbpath']);
                }
            }
            $stmtDeletePost->execute([$id]);
            $stmtDeleteReplies->execute([$id]);
        }
    }
    generatePagination($pdo, $postsPerPage);
    header("Location: index.html");
    exit;
}

/**************************************************
 * PROCESS NEW POST SUBMISSION
 **************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['deletepost'])) {
    $name    = trim($_POST['name'] ?? 'Anonymous');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $time    = time();
    $filename  = '';
    $filepath  = '';
    $thumbpath = '';
    $mimeType  = '';

    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $tmpName  = $_FILES['file']['tmp_name'];
        $origName = basename($_FILES['file']['name']);
        $mimeType = mime_content_type($tmpName);
        if (in_array($mimeType, $allowedTypes)) {
            if (!is_dir($uploadsDir)) { mkdir($uploadsDir, 0777, true); }
            if (!is_dir($thumbsDir)) { mkdir($thumbsDir, 0777, true); }
            $uniqueName = time() . '_' . mt_rand(1000,9999) . '_' . $origName;
            $destPath   = $uploadsDir . '/' . $uniqueName;
            move_uploaded_file($tmpName, $destPath);
            $filename = $origName;
            $filepath = 'uploads/' . $uniqueName;
            if (preg_match('/^image\//', $mimeType)) {
                $thumbName     = 'thumb_' . $uniqueName;
                $thumbFullPath = $thumbsDir . '/' . $thumbName;
                $t = createThumbnail($destPath, $thumbFullPath, $mimeType, $maxThumbSize);
                if ($t) { 
                    $thumbpath = 'thumb/' . $thumbName; 
                }
            } elseif ($mimeType === 'video/mp4') {
                $thumbpath = 'thumb/video_placeholder.jpg';
            }
        }
    }
    // Set bumped_at equal to created_at on a new post.
    $stmt = $pdo->prepare("INSERT INTO posts (name, subject, message, filename, filepath, thumbpath, mimetype, created_at, bumped_at, reply_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
    $stmt->execute([$name, $subject, $message, $filename, $filepath, $thumbpath, $mimeType, $time, $time]);
    generatePagination($pdo, $postsPerPage);
    header("Location: index.html");
    exit;
}

// For GET requests, regenerate pagination and redirect.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    generatePagination($pdo, $postsPerPage);
    header("Location: index.html");
    exit;
}
?>
