<?php
// Updated index.php for PHP 8.4.1 with GD library, lightbox for images, and HTML5 video player

session_start();

// Configuration
define('BOARD_TITLE', 'My Imageboard');
define('DB_DIR', __DIR__ . '/db');
define('DB_FILE', DB_DIR . '/database.db');
define('THREADS_PER_PAGE', 10);
define('MAX_MESSAGE_LENGTH', 10000);
define('TIMEZONE', 'UTC');
define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 20 MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webm', 'mp4']);
define('THUMB_WIDTH', 200);
define('THUMB_HEIGHT', 200);

// Secure Session Settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_strict_mode', 1);
session_regenerate_id(true); // Prevent session fixation

// Set SameSite attribute for cookies to prevent CSRF attacks
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '', 
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);

// Set timezone
date_default_timezone_set(TIMEZONE);

// CSRF Token Management
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Error reporting settings
error_reporting(E_ALL);
ini_set('log_errors', 'On');
ini_set('error_log', __DIR__ . '/error.log');
ini_set('display_errors', 'Off');

// Log errors to error.log
if (!file_exists(__DIR__ . '/error.log')) {
    file_put_contents(__DIR__ . '/error.log', '');
    chmod(__DIR__ . '/error.log', 0666);
}

// Ensure the db directory exists
if (!file_exists(DB_DIR)) {
    if (!mkdir(DB_DIR, 0777, true) && !is_dir(DB_DIR)) {
        die('Failed to create database directory.');
    }
    chmod(DB_DIR, 0777); // Make sure directory is writable
}

// Database connection
try {
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    createTables($pdo);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    die('Database connection failed. Please check error log for details.');
}

// Add headers to prevent clickjacking
header('X-Frame-Options: DENY');

// Route requests
$action = $_GET['action'] ?? 'index';
switch ($action) {
    case 'post':
        handlePost($pdo);
        break;
    case 'thread':
        displayThread($pdo);
        break;
    case 'reply':
        displayReplyForm($pdo);
        break;
    default:
        displayIndex($pdo);
        break;
}

// Function to create tables
function createTables(PDO $pdo)
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER DEFAULT 0,
        timestamp INTEGER NOT NULL,
        bumped INTEGER NOT NULL,
        message TEXT,
        file TEXT,
        file_original TEXT,
        file_size INTEGER,
        image_width INTEGER,
        image_height INTEGER,
        thumb TEXT,
        thumb_width INTEGER,
        thumb_height INTEGER
    )');
}

// Function to handle new posts with CSRF protection
function handlePost(PDO $pdo)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ./');
        exit;
    }

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('Invalid CSRF token.');
    }

    $parent_id = intval($_POST['parent_id'] ?? 0);
    $message = htmlspecialchars(trim($_POST['message'] ?? ''), ENT_QUOTES, 'UTF-8');

    if (strlen($message) > MAX_MESSAGE_LENGTH) {
        die('Message is too long.');
    }

    if (empty($message) && empty($_FILES['file']['name'])) {
        die('You must enter a message or upload a file.');
    }

    $fileInfo = null;
    if (!empty($_FILES['file']['name'])) {
        $fileInfo = handleFileUpload();
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO posts (
            parent_id, timestamp, bumped, message, file,
            file_original, file_size, image_width, image_height, thumb, thumb_width, thumb_height
        ) VALUES (
            :parent_id, :timestamp, :bumped, :message, :file,
            :file_original, :file_size, :image_width, :image_height, :thumb, :thumb_width, :thumb_height
        )');

        $timestamp = time();
        $stmt->execute([
            ':parent_id' => $parent_id,
            ':timestamp' => $timestamp,
            ':bumped' => $timestamp,
            ':message' => nl2br($message),
            ':file' => $fileInfo['file'] ?? '',
            ':file_original' => $fileInfo['file_original'] ?? '',
            ':file_size' => $fileInfo['file_size'] ?? 0,
            ':image_width' => $fileInfo['image_width'] ?? 0,
            ':image_height' => $fileInfo['image_height'] ?? 0,
            ':thumb' => $fileInfo['thumb'] ?? '',
            ':thumb_width' => $fileInfo['thumb_width'] ?? 0,
            ':thumb_height' => $fileInfo['thumb_height'] ?? 0,
        ]);
    } catch (PDOException $e) {
        error_log('Failed to insert post: ' . $e->getMessage());
        die('Failed to insert post. Please check error log for details.');
    }

    $postId = $pdo->lastInsertId();

    if ($parent_id > 0) {
        try {
            $stmt = $pdo->prepare('UPDATE posts SET bumped = :bumped WHERE id = :id');
            $stmt->execute([':bumped' => $timestamp, ':id' => $parent_id]);
        } catch (PDOException $e) {
            error_log('Failed to update post: ' . $e->getMessage());
        }
    }

    if ($parent_id > 0) {
        header('Location: ?action=thread&id=' . $parent_id . '#' . $postId);
    } else {
        header('Location: ./');
    }
    exit;
}

// Function to handle file uploads with enhanced security
function handleFileUpload()
{
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        error_log('File upload error: ' . $_FILES['file']['error']);
        die('File upload error.');
    }

    if ($_FILES['file']['size'] > MAX_FILE_SIZE) {
        error_log('File is too large: ' . $_FILES['file']['size']);
        die('File is too large.');
    }

    // Validate the MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $_FILES['file']['tmp_name']);
    finfo_close($finfo);

    $validMimeTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'video/webm', 'video/mp4'
    ];
    if (!in_array($mimeType, $validMimeTypes)) {
        error_log('Invalid file type: ' . $mimeType);
        die('Invalid file type.');
    }

    $fileExt = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, ALLOWED_EXTENSIONS)) {
        error_log('Invalid file extension: ' . $fileExt);
        die('Invalid file extension.');
    }

    // Use a secure random name for the file
    $fileHash = bin2hex(random_bytes(16));
    $fileName = $fileHash . '.' . $fileExt;
    $filePath = DB_DIR . '/' . $fileName;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
        error_log('Failed to save uploaded file.');
        die('Failed to save uploaded file.');
    }

    // Set file permissions for security
    chmod($filePath, 0644);

    $thumbInfo = null;

    // Generate thumbnail if the file is an image
    if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif'])) {
        $thumbInfo = createImageThumbnail($filePath, $fileHash, $fileExt);
    }

    return [
        'file' => $fileName,
        'file_original' => htmlspecialchars($_FILES['file']['name'], ENT_QUOTES, 'UTF-8'),
        'file_size' => $_FILES['file']['size'],
        'image_width' => $thumbInfo['image_width'] ?? 0,
        'image_height' => $thumbInfo['image_height'] ?? 0,
        'thumb' => $thumbInfo['thumb'] ?? '',
        'thumb_width' => $thumbInfo['thumb_width'] ?? 0,
        'thumb_height' => $thumbInfo['thumb_height'] ?? 0,
    ];
}

// Function to create image thumbnails using GD Library
function createImageThumbnail($filePath, $fileHash, $fileExt)
{
    $thumbName = $fileHash . '_thumb.jpg';
    $thumbPath = DB_DIR . '/' . $thumbName;

    switch ($fileExt) {
        case 'jpeg':
        case 'jpg':
            $sourceImage = imagecreatefromjpeg($filePath);
            break;
        case 'png':
            $sourceImage = imagecreatefrompng($filePath);
            break;
        case 'gif':
            $sourceImage = imagecreatefromgif($filePath);
            break;
        default:
            error_log('Unsupported file type for thumbnail generation: ' . $fileExt);
            return null;
    }

    $thumbImage = imagecreatetruecolor(THUMB_WIDTH, THUMB_HEIGHT);
    list($width, $height) = getimagesize($filePath);
    imagecopyresampled($thumbImage, $sourceImage, 0, 0, 0, 0, THUMB_WIDTH, THUMB_HEIGHT, $width, $height);
    imagejpeg($thumbImage, $thumbPath);

    imagedestroy($sourceImage);
    imagedestroy($thumbImage);

    return [
        'thumb' => $thumbName,
        'thumb_width' => THUMB_WIDTH,
        'thumb_height' => THUMB_HEIGHT,
        'image_width' => $width,
        'image_height' => $height,
    ];
}

// Function to build a post's HTML
function buildPost(array $post, bool $showReplyLink)
{
    global $pdo;

    $html = '<div class="reply" id="post-' . $post['id'] . '">';

    if ($post['parent_id'] == 0 && $showReplyLink) {
        $html .= '<div class="replylink">';
        $stmtReplies = $pdo->prepare('SELECT COUNT(*) FROM posts WHERE parent_id = :parent_id');
        $stmtReplies->execute([':parent_id' => $post['id']]);
        $replyCount = (int) $stmtReplies->fetchColumn();

        $html .= '<a href="?action=thread&id=' . $post['id'] . '">Reply (' . $replyCount . ')</a>';
        $html .= '</div>';
    }

    $html .= '<div class="message">';
    
    // Handle attached file (image or video)
    if ($post['file']) {
        $fileExt = strtolower(pathinfo($post['file'], PATHINFO_EXTENSION));
        $filePath = 'db/' . htmlspecialchars($post['file']);
        
        // If the file is an image, show in lightbox
        if (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif'])) {
            $html .= '<div class="fileinfo">File: <a href="' . $filePath . '" target="_blank">' . htmlspecialchars($post['file_original']) . '</a> (' . round($post['file_size'] / 1024, 2) . ' KB, ' . $post['image_width'] . 'x' . $post['image_height'] . ')</div>';
            $html .= '<a href="#" onclick="openLightbox(\'' . $filePath . '\')"><img src="' . htmlspecialchars('db/' . $post['thumb']) . '" alt="" class="thumb"></a>';
        } 
        // If the file is a video, use HTML5 video tag
        elseif (in_array($fileExt, ['webm', 'mp4'])) {
            $html .= '<div class="fileinfo">File: <a href="' . $filePath . '" target="_blank">' . htmlspecialchars($post['file_original']) . '</a> (' . round($post['file_size'] / 1024, 2) . ' KB)</div>';
            $html .= '<video controls class="thumb">';
            $html .= '<source src="' . $filePath . '" type="video/' . $fileExt . '">';
            $html .= 'Your browser does not support the video tag.';
            $html .= '</video>';
        }
    }

    $html .= '<p>' . $post['message'] . '</p>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

// Function to display the index page
function displayIndex(PDO $pdo)
{
    $page = max(0, intval($_GET['page'] ?? 0));
    $offset = $page * THREADS_PER_PAGE;

    $stmt = $pdo->prepare('SELECT * FROM posts WHERE parent_id = 0 ORDER BY bumped DESC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', THREADS_PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $html = '<!DOCTYPE html><html><head>';
    $html .= '<meta charset="UTF-8">';
    $html .= '<title>' . BOARD_TITLE . '</title>';
    $html .= '<link rel="stylesheet" type="text/css" href="style.css">';
    $html .= '</head><body>';
    $html .= '<div class="logo"><h1>' . BOARD_TITLE . '</h1></div>';
    $html .= postForm();

    foreach ($threads as $thread) {
        $html .= buildPost($thread, true);
        $html .= '<hr>';
    }

    $html .= pagination($pdo, $page);
    $html .= '</body></html>';
    echo $html;
}

// Function to display a thread
function displayThread(PDO $pdo)
{
    $threadId = intval($_GET['id'] ?? 0);
    if ($threadId <= 0) {
        header('Location: ./');
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = :id AND parent_id = 0');
    $stmt->execute([':id' => $threadId]);
    $thread = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$thread) {
        die('Thread not found.');
    }

    $stmtReplies = $pdo->prepare('SELECT * FROM posts WHERE parent_id = :parent_id ORDER BY timestamp ASC');
    $stmtReplies->execute([':parent_id' => $threadId]);
    $replies = $stmtReplies->fetchAll(PDO::FETCH_ASSOC);

    $html = '<!DOCTYPE html><html><head>';
    $html .= '<meta charset="UTF-8">';
    $html .= '<title>' . BOARD_TITLE . ' - Thread #' . $threadId . '</title>';
    $html .= '<link rel="stylesheet" type="text/css" href="style.css">';
    $html .= '</head><body>';
    $html .= '<div class="logo"><h1>' . BOARD_TITLE . '</h1></div>';
    $html .= '<div class="replymode">';
    $html .= '<h2>Reply Mode</h2>';
    $html .= '<a href="./" class="return">Return to Board</a>';
    $html .= '</div>';

    $html .= buildPost($thread, false);
    $html .= postForm($threadId);

    foreach ($replies as $reply) {
        $html .= buildPost($reply, false);
    }

    $html .= '</body></html>';
    echo $html;
}

// Function to generate the post form
function postForm($parent_id = 0)
{
    $placeholder = $parent_id > 0 ? "Reply" : "Message";
    $html = '<form action="?action=post" method="post" enctype="multipart/form-data" class="post-form">';
    $html .= '<input type="hidden" name="parent_id" value="' . $parent_id . '">';
    $html .= '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
    $html .= '<textarea name="message" rows="5" cols="50" placeholder="' . $placeholder . '" required></textarea><br>';
    $html .= '<label>File: <input type="file" name="file"></label><br>';
    $html .= '<div class="form-submit-container"><input type="submit" value="Post" class="post-button"></div>';
    $html .= '</form><hr>';
    return $html;
}

// Function to generate pagination links
function pagination(PDO $pdo, $currentPage)
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM posts WHERE parent_id = 0');
    $totalThreads = (int) $stmt->fetchColumn();
    $totalPages = ceil($totalThreads / THREADS_PER_PAGE);

    if ($totalPages <= 1) {
        return '';
    }

    $html = '<div class="pagination">';
    for ($i = 0; $i < $totalPages; $i++) {
        if ($i == $currentPage) {
            $html .= '<strong>[' . $i . ']</strong> ';
        } else {
            $html .= '[<a href="?page=' . $i . '">' . $i . '</a>] ';
        }
    }
    $html .= '</div><hr>';
    return $html;
}

?>

<script>
function openLightbox(imageUrl) {
    // Create a simple lightbox overlay
    const lightbox = document.createElement('div');
    lightbox.id = 'lightbox';
    lightbox.style.position = 'fixed';
    lightbox.style.top = '0';
    lightbox.style.left = '0';
    lightbox.style.width = '100%';
    lightbox.style.height = '100%';
    lightbox.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
    lightbox.style.display = 'flex';
    lightbox.style.alignItems = 'center';
    lightbox.style.justifyContent = 'center';
    lightbox.innerHTML = `<img src="${imageUrl}" style="max-width: 90%; max-height: 90%;">`;

    // Add close button
    const closeButton = document.createElement('div');
    closeButton.style.position = 'absolute';
    closeButton.style.top = '20px';
    closeButton.style.right = '20px';
    closeButton.style.fontSize = '2em';
    closeButton.style.color = '#FFF';
    closeButton.style.cursor = 'pointer';
    closeButton.innerHTML = 'X';
    closeButton.onclick = function() {
        document.body.removeChild(lightbox);
    };
    lightbox.appendChild(closeButton);

    // Append lightbox to body
    document.body.appendChild(lightbox);
}
</script>
