<?php
declare(strict_types=1);

session_start();

// Setup error logging
error_reporting(E_ALL);
ini_set('display_errors', '1');
touch(__DIR__ . '/error.txt'); // ensure file exists
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.txt');

// Constants and configuration
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

$SECURE_SALT = 'mysecretsaltchangeit';

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Initialize Database with exception mode
try {
    $db = new SQLite3(__DIR__ . '/board.db', SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
    $db->exec("CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent INTEGER NOT NULL DEFAULT 0,
        name TEXT NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        image TEXT DEFAULT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL DEFAULT 0
    )");
} catch (Throwable $e) {
    die("Database initialization error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateCsrfToken(): string {
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

function verifyCsrfToken(?string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generateTripcode(string $name, string $secure_salt): string {
    if (str_contains($name, '##')) {
        [$displayName, $tripPart] = explode('##', $name, 2);
        $displayName = trim($displayName);
        $tripPart = trim($tripPart);

        if ($tripPart !== '') {
            $tripcode = substr(crypt($tripPart, $secure_salt), -10);
            return $displayName . ' !!' . $tripcode;
        } else {
            return $displayName;
        }
    } elseif (str_contains($name, '#')) {
        [$displayName, $tripPart] = explode('#', $name, 2);
        $displayName = trim($displayName);
        $tripPart = trim($tripPart);

        if ($tripPart !== '') {
            $salt = substr($tripPart . 'H.', 1, 2);
            $salt = preg_replace('/[^\.-z]/', '.', $salt);
            $tripcode = substr(crypt($tripPart, $salt), -10);
            return $displayName . ' !' . $tripcode;
        } else {
            return $displayName;
        }
    } else {
        return trim($name);
    }
}

/**
 * Save uploaded file if valid.
 *
 * @param array $file The $_FILES['image'] array.
 * @return string|null The filename on success, or null on failure.
 */
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

    // Additional consistency checks for images
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
        // Gracefully handle failure
        return null;
    }

    return $filename;
}

// Handle style selection
if (isset($_GET['style'])) {
    $requestedStyle = (int)$_GET['style'];
    if ($requestedStyle >= 1 && $requestedStyle <= 8) {
        $_SESSION['current_style'] = $requestedStyle;
    }
}

// Default style if none chosen
if (!isset($_SESSION['current_style'])) {
    $_SESSION['current_style'] = 1;
}

$currentStyle = $_SESSION['current_style'];
$styleSheet = "css/style{$currentStyle}.css";

// Get thread ID
$thread_id = isset($_GET['thread']) ? (int)$_GET['thread'] : 0;
if ($thread_id < 1) {
    die("Invalid thread ID.");
}

// Check thread exists
try {
    $thread = $db->querySingle("SELECT * FROM posts WHERE id = $thread_id AND parent = 0", true);
} catch (Throwable $e) {
    die("Database error checking thread: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

if (!$thread) {
    die("Thread not found.");
}

// Handle POST (reply)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken($csrf)) {
        die("Invalid CSRF token.");
    }

    $name = sanitize($_POST['name'] ?? '');
    $message = sanitize($_POST['message'] ?? '');

    if (strlen($name) < 1 || strlen($message) < 3) {
        die("Name and message are required, and message must be at least 3 characters.");
    }

    $name = generateTripcode($name, $SECURE_SALT);

    $parent = $thread_id;
    $title = ''; // Replies don't have titles
    $image = null; 
    $time = time();

    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $image = saveFile($_FILES['image']);
        if ($image === null) {
            die("File upload failed. Please check the file type and size.");
        }
    }

    try {
        $stmt = $db->prepare("INSERT INTO posts (parent, name, title, message, image, created_at, updated_at) VALUES (:parent, :name, :title, :message, :image, :created_at, :updated_at)");
        $stmt->bindValue(':parent', $parent, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->bindValue(':image', $image, SQLITE3_TEXT);
        $stmt->bindValue(':created_at', $time, SQLITE3_INTEGER);
        $stmt->bindValue(':updated_at', $time, SQLITE3_INTEGER);
        $stmt->execute();

        // Update thread updated_at
        $update_stmt = $db->prepare("UPDATE posts SET updated_at = :updated_at WHERE id = :id");
        $update_stmt->bindValue(':updated_at', $time, SQLITE3_INTEGER);
        $update_stmt->bindValue(':id', $thread_id, SQLITE3_INTEGER);
        $update_stmt->execute();
    } catch (Throwable $e) {
        die("Database error while inserting reply: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    // Redirect back to the same thread after posting
    header('Location: reply.php?thread=' . $thread_id);
    exit;
}

// Generate CSRF token on GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['csrf_token'])) {
        generateCsrfToken();
    }
}
$csrfToken = $_SESSION['csrf_token'] ?? '';

// Fetch all replies, ordered by created_at so we can number them
try {
    $replies = $db->query("SELECT * FROM posts WHERE parent = $thread_id ORDER BY created_at ASC");
} catch (Throwable $e) {
    die("Database error fetching replies: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reply Mode</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($styleSheet, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
    <div style="position: absolute; top: 10px; left: 10px;">
        <form method="get" style="display:inline;">
            <input type="hidden" name="thread" value="<?= $thread_id ?>">
            <label for="style-selector">Style:</label>
            <select name="style" id="style-selector" onchange="this.form.submit()">
                <?php for ($i = 1; $i <= 8; $i++): ?>
                    <option value="<?= $i ?>" <?= $i === $currentStyle ? 'selected' : '' ?>>Style <?= $i ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <h1>Reply Mode</h1>
    <p class="back-link"><a href="index.php">Back to Board</a></p>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="text" name="name" placeholder="Name (tripcodes allowed)" required>
        <textarea name="message" rows="4" placeholder="Message" required></textarea>
        <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4">
        <button type="submit">Post Reply</button>
    </form>

    <hr>

    <!-- Show original post -->
    <div class="thread">
        <div class="post-info">
            <strong><?= sanitize($thread['name']) ?></strong> | <?= sanitize($thread['title']) ?>
        </div>
        <?php if ($thread['image']):
            $ext = pathinfo($thread['image'], PATHINFO_EXTENSION);
            if ($ext === 'mp4'): ?>
                <video controls>
                    <source src="<?= htmlspecialchars(UPLOAD_URL . $thread['image'], ENT_QUOTES, 'UTF-8') ?>" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            <?php else: ?>
                <img src="<?= htmlspecialchars(UPLOAD_URL . $thread['image'], ENT_QUOTES, 'UTF-8') ?>" alt="Image">
            <?php endif; ?>
        <?php endif; ?>
        <p><?= nl2br(sanitize($thread['message'])) ?></p>
    </div>

    <!-- Show replies below with sequential numbering -->
    <?php $replyCount = 0; ?>
    <?php while ($reply = $replies->fetchArray(SQLITE3_ASSOC)): ?>
        <?php $replyCount++; ?>
        <div class="reply-post">
            <div class="post-info">
                <strong><?= sanitize($reply['name']) ?></strong> | Reply #<?= $replyCount ?>
            </div>
            <?php if ($reply['image']):
                $ext = pathinfo($reply['image'], PATHINFO_EXTENSION);
                if ($ext === 'mp4'): ?>
                    <video controls>
                        <source src="<?= htmlspecialchars(UPLOAD_URL . $reply['image'], ENT_QUOTES, 'UTF-8') ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                <?php else: ?>
                    <img src="<?= htmlspecialchars(UPLOAD_URL . $reply['image'], ENT_QUOTES, 'UTF-8') ?>" alt="Image">
                <?php endif; ?>
            <?php endif; ?>
            <p><?= nl2br(sanitize($reply['message'])) ?></p>
        </div>
    <?php endwhile; ?>
</body>
</html>
