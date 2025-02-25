<?php
session_start();

// Regenerate session ID to prevent session fixation
session_regenerate_id(true);

// Generate CSRF token if not present
if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

// Database connection with exception handling
try {
    $db = new SQLite3('imageboard.db', SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
} catch (Exception $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

// Create table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    message TEXT NOT NULL,
    image TEXT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Handle new post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token validation
    if (!hash_equals($_SESSION['token'], $_POST['token'] ?? '')) {
        die("Invalid CSRF token");
    }

    $name = trim($_POST['name']) ?: 'Anonymous';
    $message = trim($_POST['message']) ?: '';
    $image = '';

    // Input validation
    if (empty($message) && empty($_FILES['image']['name'])) {
        $error = "Message or image is required.";
    } else {
        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploads_dir = 'uploads';
            if (!is_dir($uploads_dir)) {
                mkdir($uploads_dir, 0755, true);
            }

            $tmp_name = $_FILES['image']['tmp_name'];
            $img_name = basename($_FILES['image']['name']);
            $file_size = $_FILES['image']['size'];
            $file_ext = strtolower(pathinfo($img_name, PATHINFO_EXTENSION));

            // Allowed file extensions and MIME types
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $finfo->file($tmp_name);

            // Validate file extension and MIME type
            if (!in_array($file_ext, $allowed_ext) || !in_array($mime_type, $allowed_types)) {
                $error = "Invalid image file type.";
            }

            // Limit file size (e.g., 5MB)
            if ($file_size > 5 * 1024 * 1024) {
                $error = "Image file size exceeds 5MB.";
            }

            if (empty($error)) {
                $target_file = $uploads_dir . '/' . uniqid('', true) . '.' . $file_ext;
                if (!move_uploaded_file($tmp_name, $target_file)) {
                    $error = "Failed to upload image.";
                } else {
                    $image = $target_file;
                }
            }
        }

        if (empty($error)) {
            // Insert post into database
            $stmt = $db->prepare("INSERT INTO posts (name, message, image) VALUES (:name, :message, :image)");
            $stmt->bindValue(':name', htmlspecialchars($name), SQLITE3_TEXT);
            $stmt->bindValue(':message', htmlspecialchars($message), SQLITE3_TEXT);
            $stmt->bindValue(':image', $image, SQLITE3_TEXT);
            $stmt->execute();

            // Redirect to prevent form resubmission
            header("Location: {$_SERVER['PHP_SELF']}");
            exit();
        }
    }
}

// Pagination setup
$posts_per_page = 10;
$total_posts = $db->querySingle("SELECT COUNT(*) as count FROM posts");
$total_pages = ceil($total_posts / $posts_per_page);

// Get current page from URL, default is 1
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $posts_per_page;

// Fetch posts for the current page
$stmt = $db->prepare("SELECT * FROM posts ORDER BY timestamp DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $posts_per_page, SQLITE3_INTEGER);
$stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
$result = $stmt->execute();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Simple PHP Imageboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<h1>Simple PHP Imageboard</h1>

<div class="form">
    <?php if (!empty($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form action="" method="post" enctype="multipart/form-data">
        <input type="hidden" name="token" value="<?php echo $_SESSION['token']; ?>">
        <label>Name: <input type="text" name="name" value="Anonymous"></label><br><br>
        <label>Message:</label><br>
        <textarea name="message" rows="5" cols="50"></textarea><br><br>
        <label>Image: <input type="file" name="image" accept="image/*"></label><br><br>
        <button type="submit">Post</button>
    </form>
</div>

<?php while ($row = $result->fetchArray(SQLITE3_ASSOC)): ?>
    <div class="post">
        <div class="post-header">
            <strong><?php echo htmlspecialchars($row['name']); ?></strong>
            <em><?php echo $row['timestamp']; ?></em>
        </div>
        <p><?php echo nl2br(htmlspecialchars($row['message'])); ?></p>
        <?php if ($row['image']): ?>
            <img src="<?php echo htmlspecialchars($row['image']); ?>" alt="Image">
        <?php endif; ?>
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

</body>
</html>
