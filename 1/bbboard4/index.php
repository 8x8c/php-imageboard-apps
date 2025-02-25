<?php
session_start();

// Enable error logging to 'error.txt'
ini_set('log_errors', 'On');
ini_set('error_log', 'error.txt');
error_reporting(E_ALL);

// Ensure 'error.txt' exists
if (!file_exists('error.txt')) {
    file_put_contents('error.txt', '');
}

// Regenerate session ID to prevent session fixation
session_regenerate_id(true);

// Configurable Board Title
$board_title = 'Chess Discussion Board';

// Generate CSRF token if not present
if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

// Database connection with exception handling
try {
    $db = new SQLite3('imageboard.db', SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed.");
}

// Create table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER,
    name TEXT NOT NULL,
    message TEXT NOT NULL,
    file TEXT,
    file_type TEXT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(parent_id) REFERENCES posts(id) ON DELETE CASCADE
)");

// Handle new post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!hash_equals($_SESSION['token'], $_POST['token'] ?? '')) {
        error_log("Invalid CSRF token");
        die("Invalid CSRF token");
    }

    $name = 'Anonymous'; // Set name to 'Anonymous' by default
    $message = trim($_POST['message']) ?: '';
    $file = '';
    $file_type = '';
    $parent_id = isset($_POST['parent_id']) ? intval($_POST['parent_id']) : null;

    // Input validation
    if (empty($message) && empty($_FILES['file']['name'])) {
        $error = "Message or file is required.";
    } else {
        // Handle file upload
        if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploads_dir = 'uploads';
            if (!is_dir($uploads_dir)) {
                mkdir($uploads_dir, 0755, true);
            }

            $tmp_name = $_FILES['file']['tmp_name'];
            $original_name = basename($_FILES['file']['name']);
            $file_size = $_FILES['file']['size'];
            $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

            // Allowed file extensions and MIME types
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'];
            $allowed_image_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowed_video_types = ['video/mp4'];

            // Validate file extension
            if (!in_array($file_ext, $allowed_ext)) {
                $error = "Invalid file type.";
                error_log($error);
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime_type = $finfo->file($tmp_name);

                // Handle images
                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    if (!in_array($mime_type, $allowed_image_types)) {
                        $error = "Invalid image file type.";
                        error_log($error);
                    } else {
                        // Use ImageMagick to validate the image
                        $image_check = @exec("identify " . escapeshellarg($tmp_name) . " 2>&1", $output, $return_var);
                        if ($return_var !== 0) {
                            $error = "Corrupted or invalid image file.";
                            error_log($error . " Output: " . implode("\n", $output));
                        } else {
                            $file_type = 'image';
                        }
                    }
                }
                // Handle mp4 videos
                elseif ($file_ext === 'mp4') {
                    if (!in_array($mime_type, $allowed_video_types)) {
                        $error = "Invalid video file type.";
                        error_log($error);
                    } else {
                        // Use FFmpeg to validate the video
                        $video_check = @exec("ffmpeg -v error -i " . escapeshellarg($tmp_name) . " -f null - 2>&1", $output, $return_var);
                        if ($return_var !== 0) {
                            $error = "Corrupted or invalid video file.";
                            error_log($error . " Output: " . implode("\n", $output));
                        } else {
                            $file_type = 'video';
                        }
                    }
                }
            }

            // File Size Limit (20MB for both images and videos)
            $max_size = 20 * 1024 * 1024; // 20MB in bytes
            if ($file_size > $max_size) {
                $error = "File size exceeds the allowed limit of 20MB.";
                error_log($error);
            }

            if (empty($error)) {
                $unique_id = uniqid('', true);
                $target_file = $uploads_dir . '/' . $unique_id . '.' . $file_ext;
                if (!move_uploaded_file($tmp_name, $target_file)) {
                    $error = "Failed to upload file.";
                    error_log($error);
                } else {
                    $file = $target_file;
                }
            }
        }

        if (empty($error)) {
            // Insert post into database
            $stmt = $db->prepare("INSERT INTO posts (parent_id, name, message, file, file_type) VALUES (:parent_id, :name, :message, :file, :file_type)");
            $stmt->bindValue(':parent_id', $parent_id, SQLITE3_INTEGER);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT); // Name is 'Anonymous'
            $stmt->bindValue(':message', htmlspecialchars($message), SQLITE3_TEXT);
            $stmt->bindValue(':file', $file, SQLITE3_TEXT);
            $stmt->bindValue(':file_type', $file_type, SQLITE3_TEXT);
            $result = $stmt->execute();

            if (!$result) {
                $error = "Failed to insert post into database.";
                error_log($error . " Error Info: " . $db->lastErrorMsg());
            } else {
                // Redirect to prevent form resubmission
                if ($parent_id) {
                    header("Location: {$_SERVER['PHP_SELF']}?thread=$parent_id");
                } else {
                    header("Location: {$_SERVER['PHP_SELF']}");
                }
                exit();
            }
        }
    }
}

// Check if we are in thread view
if (isset($_GET['thread'])) {
    $thread_id = intval($_GET['thread']);

    // Fetch the thread
    $stmt = $db->prepare("SELECT * FROM posts WHERE id = :id AND parent_id IS NULL");
    $stmt->bindValue(':id', $thread_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if (!$result) {
        error_log("Failed to fetch thread. Error Info: " . $db->lastErrorMsg());
        die("Thread not found.");
    }
    $thread = $result->fetchArray(SQLITE3_ASSOC);

    if (!$thread) {
        error_log("Thread with ID $thread_id not found.");
        die("Thread not found.");
    }

    // Fetch replies to the thread
    $stmt_replies = $db->prepare("SELECT * FROM posts WHERE parent_id = :parent_id ORDER BY timestamp ASC");
    $stmt_replies->bindValue(':parent_id', $thread_id, SQLITE3_INTEGER);
    $replies = $stmt_replies->execute();

} else {
    // We are in main board view

    // Pagination setup
    $posts_per_page = 10;

    // Fetch parent posts (threads) for the current page
    $total_threads = $db->querySingle("SELECT COUNT(*) as count FROM posts WHERE parent_id IS NULL");
    $total_pages = ceil($total_threads / $posts_per_page);

    // Get current page from URL, default is 1
    $current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($current_page - 1) * $posts_per_page;

    // Fetch threads ordered by latest activity (thread creation or latest reply)
    $stmt = $db->prepare("
        SELECT p.*, (
            SELECT MAX(timestamp) FROM posts WHERE id = p.id OR parent_id = p.id
        ) AS last_activity
        FROM posts p
        WHERE p.parent_id IS NULL
        ORDER BY last_activity DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit', $posts_per_page, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $threads = $stmt->execute();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($board_title); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<?php if (isset($thread_id)): ?>
    <!-- Reply Page -->
    <h1><?php echo htmlspecialchars($board_title); ?> - Reply Mode</h1>
    <div class="navigation">
        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">[Return]</a>
        <a href="/index.html">[Home]</a>
    </div>

    <div class="form reply-form" id="reply-form">
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>">
            <input type="hidden" name="parent_id" value="<?php echo $thread['id']; ?>">
            <label>Message:</label><br>
            <textarea name="message" rows="10" cols="50"></textarea><br><br>
            <label>File: <input type="file" name="file" accept="image/*,video/mp4"></label><br><br>
            <button type="submit">Post Reply</button>
        </form>
    </div>

    <!-- Display the original post -->
    <div class="post">
        <div class="post-header">
            <div class="post-name">
                <strong><?php echo htmlspecialchars($thread['name']); ?></strong>
            </div>
        </div>
        <?php if ($thread['file']): ?>
            <div class="post-file">
                <?php if ($thread['file_type'] === 'image'): ?>
                    <img src="<?php echo htmlspecialchars($thread['file']); ?>" alt="Image">
                <?php elseif ($thread['file_type'] === 'video'): ?>
                    <video controls>
                        <source src="<?php echo htmlspecialchars($thread['file']); ?>" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="post-message">
            <p><?php echo nl2br(htmlspecialchars($thread['message'])); ?></p>
        </div>
    </div>

    <!-- Display replies -->
    <?php while ($reply = $replies->fetchArray(SQLITE3_ASSOC)): ?>
        <div class="reply">
            <div class="post-header">
                <div class="post-name">
                    <strong><?php echo htmlspecialchars($reply['name']); ?></strong>
                </div>
                <div class="post-time">
                    <em><?php echo $reply['timestamp']; ?></em>
                </div>
            </div>
            <?php if ($reply['file']): ?>
                <div class="post-file">
                    <?php if ($reply['file_type'] === 'image'): ?>
                        <img src="<?php echo htmlspecialchars($reply['file']); ?>" alt="Image">
                    <?php elseif ($reply['file_type'] === 'video'): ?>
                        <video controls>
                            <source src="<?php echo htmlspecialchars($reply['file']); ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="post-message">
                <p><?php echo nl2br(htmlspecialchars($reply['message'])); ?></p>
            </div>
        </div>
    <?php endwhile; ?>

<?php else: ?>
    <!-- Main Board View -->
    <h1><?php echo htmlspecialchars($board_title); ?></h1>
    <div class="navigation">
        <a href="/index.html">[Home]</a>
    </div>

    <div class="form">
        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>">
            <label>Message:</label><br>
            <textarea name="message" rows="10" cols="50"></textarea><br><br>
            <label>File: <input type="file" name="file" accept="image/*,video/mp4"></label><br><br>
            <button type="submit">Post New Thread</button>
        </form>
    </div>

    <?php while ($thread = $threads->fetchArray(SQLITE3_ASSOC)): ?>
        <div class="post">
            <div class="post-header">
                <div class="post-name">
                    <strong><?php echo htmlspecialchars($thread['name']); ?></strong>
                </div>
                <div class="post-reply">
                    <?php
                    // Count the number of replies for this thread
                    $stmt_count = $db->prepare("SELECT COUNT(*) as count FROM posts WHERE parent_id = :parent_id");
                    $stmt_count->bindValue(':parent_id', $thread['id'], SQLITE3_INTEGER);
                    $count_result = $stmt_count->execute();
                    $reply_count = $count_result->fetchArray(SQLITE3_ASSOC)['count'];
                    ?>
                    <a href="?thread=<?php echo $thread['id']; ?>" class="reply-button">Reply (<?php echo $reply_count; ?>)</a>
                </div>
            </div>
            <?php if ($thread['file']): ?>
                <div class="post-file">
                    <?php if ($thread['file_type'] === 'image'): ?>
                        <img src="<?php echo htmlspecialchars($thread['file']); ?>" alt="Image">
                    <?php elseif ($thread['file_type'] === 'video'): ?>
                        <video controls>
                            <source src="<?php echo htmlspecialchars($thread['file']); ?>" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="post-message">
                <p><?php echo nl2br(htmlspecialchars($thread['message'])); ?></p>
            </div>
        </div>
    <?php endwhile; ?>

    <!-- Pagination Links -->
    <div class="pagination">
        <?php if ($current_page > 1): ?>
            <a href="?page=<?php echo $current_page - 1; ?>">&laquo; Previous</a>
        <?php endif; ?>

        <span>Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>

        <?php if ($current_page < $total_pages): ?>
            <a href="?page=<?php echo $current_page + 1; ?>">Next &raquo;</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

</body>
</html>

