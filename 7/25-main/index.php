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

// We assume install.php already created the database and table, and enabled WAL.
// Ensure upload directory exists (it should be created by install.php, but let's be safe).
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Initialize Database with exception mode
try {
    $db = new SQLite3(__DIR__ . '/board.db', SQLITE3_OPEN_READWRITE);
    $db->enableExceptions(true);
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

// Handle new thread POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken($csrf)) {
        die("Invalid CSRF token.");
    }

    $parent = (int)($_POST['parent'] ?? 0);
    if ($parent !== 0) {
        die("Invalid request: no replies allowed here. Use reply.php.");
    }

    $name = sanitize($_POST['name'] ?? '');
    $title = sanitize($_POST['title'] ?? '');
    $message = sanitize($_POST['message'] ?? '');

    if (strlen($name) < 1 || strlen($title) < 1 || strlen($message) < 3) {
        die("Name, title, and message are required. Message must be at least 3 characters.");
    }

    $name = generateTripcode($name, $SECURE_SALT);

    $image = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $image = saveFile($_FILES['image']);
        if ($image === null) {
            die("File upload failed. Please check the file type and size.");
        }
    }

    $time = time();
    try {
        $stmt = $db->prepare("INSERT INTO posts (parent, name, title, message, image, created_at, updated_at) VALUES (:parent, :name, :title, :message, :image, :created_at, :updated_at)");
        $stmt->bindValue(':parent', 0, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->bindValue(':image', $image, SQLITE3_TEXT);
        $stmt->bindValue(':created_at', $time, SQLITE3_INTEGER);
        $stmt->bindValue(':updated_at', $time, SQLITE3_INTEGER);
        $stmt->execute();
    } catch (Throwable $e) {
        die("Database error while inserting thread: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    // Redirect to the main page after posting
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Generate CSRF token on GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['csrf_token'])) {
        generateCsrfToken();
    }
}
$csrfToken = $_SESSION['csrf_token'] ?? '';

// Pagination logic
$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}

try {
    // Count total threads
    $totalThreads = (int)$db->querySingle("SELECT COUNT(*) FROM posts WHERE parent = 0");
} catch (Throwable $e) {
    die("Database error counting threads: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$totalPages = max((int)ceil($totalThreads / $limit), 1);
$offset = ($page - 1) * $limit;

try {
    // Fetch threads with pagination
    $threads = $db->query("SELECT * FROM posts WHERE parent = 0 ORDER BY updated_at DESC LIMIT $limit OFFSET $offset");
} catch (Throwable $e) {
    die("Database error fetching threads: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Imageboard</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($styleSheet, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
    <!-- Style Selector at top-left -->
    <div style="position: absolute; top: 10px; left: 10px;">
        <form method="get" style="display:inline;">
            <label for="style-selector">Style:</label>
            <select name="style" id="style-selector" onchange="this.form.submit()">
                <?php for ($i = 1; $i <= 8; $i++): ?>
                    <option value="<?= $i ?>" <?= $i === $currentStyle ? 'selected' : '' ?>>Style <?= $i ?></option>
                <?php endfor; ?>
            </select>
        </form>
    </div>

    <h1>Simple Imageboard</h1>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="parent" value="0">
        <input type="text" name="name" placeholder="Name (tripcodes allowed)" required>
        <input type="text" name="title" placeholder="Title" required>
        <textarea name="message" rows="4" placeholder="Message" required></textarea>
        <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4">
        <button type="submit">Post Thread</button>
    </form>

    <hr>

    <?php
    $found_threads = false;
    while ($thread = $threads->fetchArray(SQLITE3_ASSOC)):
        $found_threads = true;
        $thread_id = (int)$thread['id'];
        ?>
        <div class="thread">
            <div class="post-info">
                <a href="reply.php?thread=<?= $thread_id ?>" style="font-weight: bold;">[Reply]</a>
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

            <?php
            try {
                // Count total replies
                $totalReplies = (int)$db->querySingle("SELECT COUNT(*) FROM posts WHERE parent = $thread_id");
            } catch (Throwable $e) {
                $totalReplies = 0; // If error, fallback
            }

            if ($totalReplies > 0) {
                try {
                    // Fetch only the last reply
                    $lastReplyRes = $db->query("SELECT * FROM posts WHERE parent = $thread_id ORDER BY created_at DESC LIMIT 1");
                    $lastReply = $lastReplyRes->fetchArray(SQLITE3_ASSOC);
                } catch (Throwable $e) {
                    $lastReply = null;
                }

                if ($lastReply):
                    $replyNumber = $totalReplies;
                    ?>
                    <div class="reply-snippet">
                        <div class="post-info">
                            <strong><?= sanitize($lastReply['name']) ?></strong> | Reply #<?= $replyNumber ?>
                        </div>
                        <p><?= nl2br(sanitize($lastReply['message'])) ?></p>
                    </div>
                <?php endif;
            } ?>
        </div>
    <?php endwhile; ?>

    <?php if (!$found_threads): ?>
        <div class="no-threads">No threads found.</div>
    <?php endif; ?>

    <!-- Pagination Controls -->
    <div class="pagination" style="text-align:center; margin-top: 20px;">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>">&laquo; Previous</a>
        <?php endif; ?>

        <span>Page <?= $page ?> of <?= $totalPages ?></span>

        <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>">Next &raquo;</a>
        <?php endif; ?>
    </div>
</body>
</html>
