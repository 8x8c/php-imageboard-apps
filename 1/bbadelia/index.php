<?php
session_start();

// Configuration to enable/disable CAPTCHA
$enable_captcha = false;

// SQLite Database Configuration
$db_file = __DIR__ . '/board.db';
$images_dir = __DIR__ . '/uploads';

// Create SQLite DB if it doesn't exist
if (!file_exists($db_file)) {
    $db = new SQLite3($db_file);
    $db->exec("CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                post_id TEXT,
                message TEXT,
                image_path TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
              );");
} else {
    $db = new SQLite3($db_file);
}

// Create uploads directory if it doesn't exist
if (!is_dir($images_dir)) {
    mkdir($images_dir, 0755, true);
}

// Handling CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Generate CAPTCHA if enabled
if ($enable_captcha && empty($_SESSION['captcha'])) {
    $_SESSION['captcha'] = rand(10000, 99999);
}

// Match expression for routing
$action = $_GET['action'] ?? 'index';
match ($action) {
    'reply' => displayReplyForm($db),
    'reply_post' => handleReplyPost($db),
    'post' => handlePost($db),
    default => displayIndex($db),
};

// Function to handle new posts
function handlePost(SQLite3 $db): void
{
    global $images_dir, $enable_captcha; // Ensure $images_dir and $enable_captcha are accessible

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ./');
        exit;
    }

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('Invalid CSRF token.');
    }

    if ($enable_captcha) {
        $captcha_input = trim($_POST['captcha'] ?? '');
        if ($captcha_input !== ($_SESSION['captcha'] ?? '')) {
            echo 'Invalid CAPTCHA. Please try again.';
            exit;
        }
        // Reset CAPTCHA after each submission
        $_SESSION['captcha'] = rand(10000, 99999);
    }

    $message = htmlspecialchars(trim($_POST['msj'] ?? ''), ENT_QUOTES, 'UTF-8');
    $image_path = '';

    if (empty($message) && empty($_FILES['image']['name'])) {
        echo 'You must enter a message or upload an image.';
        exit;
    }

    // Handle Image Upload
    if (!empty($_FILES['image']['name'])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4'];
        $file_type = mime_content_type($_FILES['image']['tmp_name']);

        if (in_array($file_type, $allowed_types)) {
            $extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $target_file = $images_dir . '/' . uniqid() . '.' . $extension;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                // Create a thumbnail if the file is an image
                if (str_contains($file_type, 'image')) {
                    $thumbnail_path = $images_dir . '/thumb_' . basename($target_file);
                    createThumbnailGD($target_file, $thumbnail_path, thumb_width: 100, thumb_height: 100);
                    $image_path = './uploads/' . basename($target_file);
                } elseif ($file_type === 'video/mp4') {
                    // If it's a video, just use the uploaded video directly for HTML5 video player
                    $image_path = './uploads/' . basename($target_file);
                }
            } else {
                echo 'Error: Unable to upload the image.';
                exit;
            }
        } else {
            echo 'Error: Unsupported file type.';
            exit;
        }
    }

    // Insert post into the database
    $stmt = $db->prepare("INSERT INTO posts (message, image_path) VALUES (:message, :image_path)");
    $stmt->bindValue(':message', $message, SQLITE3_TEXT);
    $stmt->bindValue(':image_path', $image_path, SQLITE3_TEXT);
    $result = $stmt->execute();

    if ($result) {
        header('Location: index.php');
    } else {
        echo 'Error: Unable to save post.';
    }
    exit;
}

// Function to handle new reply posts
function handleReplyPost(SQLite3 $db): void
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

    if (empty($message)) {
        echo 'You must enter a message.';
        exit;
    }

    // Insert reply into the database
    $stmt = $db->prepare("INSERT INTO posts (parent_id, message) VALUES (:parent_id, :message)");
    $stmt->bindValue(':parent_id', $parent_id, SQLITE3_INTEGER);
    $stmt->bindValue(':message', $message, SQLITE3_TEXT);
    $result = $stmt->execute();

    if ($result) {
        // Update the updated_at timestamp for the parent post
        $update_stmt = $db->prepare("UPDATE posts SET updated_at = CURRENT_TIMESTAMP WHERE id = :parent_id");
        $update_stmt->bindValue(':parent_id', $parent_id, SQLITE3_INTEGER);
        $update_stmt->execute();

        header('Location: index.php?action=reply&id=' . $parent_id);
    } else {
        echo 'Error: Unable to save reply.';
    }
    exit;
}

// Function to display the index page with posts
function displayIndex(SQLite3 $db): void
{
    global $enable_captcha;
    // Pagination configuration
    $postsPerPage = 10;
    $currentPage = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $offset = ($currentPage - 1) * $postsPerPage;

    // Get total number of posts for pagination
    $totalPostsResult = $db->query("SELECT COUNT(*) as total FROM posts WHERE parent_id = 0");
    $totalPostsRow = $totalPostsResult->fetchArray(SQLITE3_ASSOC);
    $totalPosts = $totalPostsRow['total'];
    $totalPages = ceil($totalPosts / $postsPerPage);

    // Fetch posts for the current page, ordered by updated_at to ensure recently replied posts are at the top
    $results = $db->query("SELECT * FROM posts WHERE parent_id = 0 ORDER BY updated_at DESC LIMIT $postsPerPage OFFSET $offset");
    
    echo '<meta http-equiv="pragma" content="no-cache" />
<link rel="stylesheet" href="style.css?v=' . time() . '">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    $(document).ready(function() {
        $(".thumbnail-image").click(function() {
            let imageUrl = $(this).attr("data-fullsize");
            $("#lightbox img").attr("src", imageUrl);
            $("#lightbox").fadeIn();
        });

        $("#lightbox").click(function() {
            $("#lightbox").fadeOut();
        });
    });
</script>';

    echo '<div class="container">
            <form method="post" action="index.php?action=post" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">
                <textarea name="msj" rows="3" class="textarea" placeholder="Write your message here..." required></textarea><br><br>
                <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4"><br><br>';

    if ($enable_captcha) {
        echo '<div class="captcha">
                <label for="captcha">Enter the number: ' . $_SESSION['captcha'] . '</label><br>
                <input type="text" name="captcha" id="captcha" maxlength="5" size="5" class="captcha-input" required>
              </div><br>';
    }

    echo '<input type="submit" value="Send Message" class="submit-button">
            </form>
          </div><br>';

    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $post_media = '';
        if (!empty($row['image_path'])) {
            if (strpos($row['image_path'], '.mp4') !== false) {
                $post_media = '<video class="media" controls>
                                    <source src="' . $row['image_path'] . '" type="video/mp4">
                                    Your browser does not support the video tag.
                               </video>';
            } else {
                $post_media = '<img src="' . $row['image_path'] . '" class="media thumbnail-image" data-fullsize="' . str_replace('thumb_', '', $row['image_path']) . '">';
            }
        }

        $reply_count_stmt = $db->prepare("SELECT COUNT(*) as reply_count FROM posts WHERE parent_id = :parent_id");
        $reply_count_stmt->bindValue(':parent_id', $row['id'], SQLITE3_INTEGER);
        $reply_result = $reply_count_stmt->execute();
        $reply_count_row = $reply_result->fetchArray(SQLITE3_ASSOC);
        $reply_count = $reply_count_row['reply_count'];

        echo '<div class="post">
                ' . $post_media . '
                <div class="replylink"><a href="?action=reply&id=' . $row['id'] . '">Reply (' . $reply_count . ')</a></div>
                <div class="message" style="margin-top: 20px;">' . $row['message'] . '</div>
                <div style="clear:both; height:10px;"></div>
              </div><br>';
    }

    // Pagination links
    echo '<div class="pagination">';
    if ($currentPage > 1) {
        echo '<a href="?page=' . ($currentPage - 1) . '" class="pagination-link">Previous</a> ';
    }

    for ($page = 1; $page <= $totalPages; $page++) {
        if ($page == $currentPage) {
            echo '<span class="current-page">' . $page . '</span> ';
        } else {
            echo '<a href="?page=' . $page . '" class="pagination-link">' . $page . '</a> ';
        }
    }

    if ($currentPage < $totalPages) {
        echo '<a href="?page=' . ($currentPage + 1) . '" class="pagination-link">Next</a>';
    }
    echo '</div>';

    echo '<div id="lightbox">
            <img src="" alt="Enlarged Image">
          </div>';
}

// Function to display the reply form
function displayReplyForm(SQLite3 $db): void
{
    $parent_id = intval($_GET['id'] ?? 0);

    echo '<meta http-equiv="pragma" content="no-cache" />
<link rel="stylesheet" href="style.css?v=' . time() . '">
<div class="replymode">
        <h2>Reply Mode</h2>
        <a href="index.php">Return</a>
</div>';

    echo '<div class="container">
            <form method="post" action="index.php?action=reply_post" class="form">
                <input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">
                <input type="hidden" name="parent_id" value="' . $parent_id . '">
                <textarea name="message" rows="3" class="textarea" placeholder="Write your reply here..." required></textarea><br><br>
                <input type="submit" value="Post Reply" class="submit-button">
            </form>
          </div><br>';

    // Display original post and any replies
    $stmt = $db->prepare("SELECT * FROM posts WHERE id = :id OR parent_id = :parent_id ORDER BY created_at ASC");
    $stmt->bindValue(':id', $parent_id, SQLITE3_INTEGER);
    $stmt->bindValue(':parent_id', $parent_id, SQLITE3_INTEGER);
    $results = $stmt->execute();
    
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        echo '<div class="post replyhl">' . htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8') . '</div><br>';
    }
}

// Reintroducing the createThumbnailGD function from the previous version
function createThumbnailGD($src, $dest, int $thumb_width, int $thumb_height): void {
    $source_image = null;
    $image_info = getimagesize($src);

    switch ($image_info['mime']) {
        case 'image/jpeg':
            $source_image = imagecreatefromjpeg($src);
            break;
        case 'image/png':
            $source_image = imagecreatefrompng($src);
            break;
        case 'image/gif':
            $source_image = imagecreatefromgif($src);
            break;
        case 'image/webp':
            $source_image = imagecreatefromwebp($src);
            break;
    }

    if ($source_image === null) {
        echo "<p>GD Error: Unable to create source image from $src</p>";
        return;
    }

    $width = imagesx($source_image);
    $height = imagesy($source_image);

    $thumb_image = imagecreatetruecolor($thumb_width, $thumb_height);

    // Preserve transparency for PNG and GIF
    if ($image_info['mime'] == 'image/png' || $image_info['mime'] == 'image/gif') {
        imagecolortransparent($thumb_image, imagecolorallocatealpha($thumb_image, 0, 0, 0, 127));
        imagealphablending($thumb_image, false);
        imagesavealpha($thumb_image, true);
    }

    imagecopyresampled($thumb_image, $source_image, 0, 0, 0, 0, $thumb_width, $thumb_height, $width, $height);

    switch ($image_info['mime']) {
        case 'image/jpeg':
            imagejpeg($thumb_image, $dest);
            break;
        case 'image/png':
            imagepng($thumb_image, $dest);
            break;
        case 'image/gif':
            imagegif($thumb_image, $dest);
            break;
        case 'image/webp':
            imagewebp($thumb_image, $dest);
            break;
    }

    imagedestroy($source_image);
    imagedestroy($thumb_image);
}
