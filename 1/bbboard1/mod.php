<?php
// Enforce strict typing
declare(strict_types=1);

// Enable error reporting for debugging purposes
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Initialize the database file
$dbFile = "database.db";
$defaultPassword = "mod123";

// Start the session
session_start();

// Set the default password in the session if it's not set
if (!isset($_SESSION['mod_password'])) {
    $_SESSION['mod_password'] = $defaultPassword;
}

try {
    $db = new SQLite3($dbFile);
} catch (Exception $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Handle login
if (!isset($_SESSION['is_mod_logged_in'])) {
    $_SESSION['is_mod_logged_in'] = false;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'login') {
    $password = $_POST['password'] ?? '';
    if ($password === $_SESSION['mod_password']) {
        $_SESSION['is_mod_logged_in'] = true;
    } else {
        $errorMessage = "Invalid password.";
    }
}

// Handle password update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_password') {
    $newPassword = $_POST['new_password'] ?? '';
    if (!empty($newPassword)) {
        $_SESSION['mod_password'] = $newPassword;
        $successMessage = "Password has been successfully updated.";
    } else {
        $errorMessage = "New password cannot be empty.";
    }
}

// Protect the moderator panel
if (!$_SESSION['is_mod_logged_in']) {
    ?>
    <html>
    <head>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
    <div id="loginPanel">
        <h2>Moderator Login</h2>
        <?php if (isset($errorMessage) && !empty($errorMessage)) { ?>
            <div class="error">
                <?php echo htmlspecialchars($errorMessage, ENT_QUOTES | ENT_HTML5); ?>
            </div>
        <?php } ?>
        <form action="mod.php" method="post">
            <label for="password">Password:</label>
            <input type="password" name="password" id="password" required>
            <input type="submit" name="action" value="login">
        </form>
    </div>
    </body>
    </html>
    <?php
    exit();
}

// Handle post deletion, image removal, or update
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : null;
    $errorMessage = '';

    if ($action === 'delete' && $postId) {
        // Delete the post from the database
        try {
            $stmt = $db->prepare("DELETE FROM posts WHERE id = :post_id OR parent_id = :post_id");
            $stmt->bindValue(':post_id', $postId, SQLITE3_INTEGER);
            $stmt->execute();
            $successMessage = "Post and its replies have been successfully deleted.";
        } catch (Exception $e) {
            $errorMessage = 'Failed to delete post: ' . $e->getMessage();
        }
    } elseif ($action === 'edit' && $postId) {
        // Edit the post
        $updatedName = htmlspecialchars($_POST["name"], ENT_QUOTES | ENT_HTML5);
        $updatedText = htmlspecialchars($_POST["text"], ENT_QUOTES | ENT_HTML5);
        
        try {
            $stmt = $db->prepare("UPDATE posts SET name = :name, text = :text, updated_at = CURRENT_TIMESTAMP WHERE id = :post_id");
            $stmt->bindValue(':name', $updatedName, SQLITE3_TEXT);
            $stmt->bindValue(':text', $updatedText, SQLITE3_TEXT);
            $stmt->bindValue(':post_id', $postId, SQLITE3_INTEGER);
            $stmt->execute();
            $successMessage = "Post has been successfully updated.";
        } catch (Exception $e) {
            $errorMessage = 'Failed to update post: ' . $e->getMessage();
        }
    } elseif ($action === 'delete_image' && $postId) {
        // Delete the image associated with the post
        try {
            $stmt = $db->prepare("SELECT image FROM posts WHERE id = :post_id");
            $stmt->bindValue(':post_id', $postId, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            if ($row && !empty($row['image'])) {
                $imagePath = $row['image'];
                if (file_exists($imagePath)) {
                    unlink($imagePath);
                }
                $updateStmt = $db->prepare("UPDATE posts SET image = NULL WHERE id = :post_id");
                $updateStmt->bindValue(':post_id', $postId, SQLITE3_INTEGER);
                $updateStmt->execute();
                $successMessage = "Image has been successfully deleted.";
            }
        } catch (Exception $e) {
            $errorMessage = 'Failed to delete image: ' . $e->getMessage();
        }
    } else {
        $errorMessage = "Invalid action or missing post ID.";
    }
}

// Pagination setup
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$postsPerPage = 10;
$offset = ($page - 1) * $postsPerPage;

// Load posts with pagination
try {
    $totalPostsResult = $db->query("SELECT COUNT(*) as count FROM posts");
    $totalPostsRow = $totalPostsResult->fetchArray(SQLITE3_ASSOC);
    $totalPosts = $totalPostsRow['count'];
    $totalPages = (int) ceil($totalPosts / $postsPerPage);

    $postsResult = $db->prepare("SELECT id, name, text, image, parent_id, updated_at FROM posts ORDER BY datetime(updated_at) DESC LIMIT :limit OFFSET :offset");
    $postsResult->bindValue(':limit', $postsPerPage, SQLITE3_INTEGER);
    $postsResult->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $postsResult->execute();
    $posts = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $posts[] = $row;
    }
} catch (Exception $e) {
    die('Failed to load posts: ' . $e->getMessage());
}
?>

<!-- HTML code for the moderator page -->
<html>
<head>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div id="modPanel">
    <h2>Moderator Panel</h2>
    <a href="index.php" style="display: block; text-align: center; margin-bottom: 20px;">Return to main board</a>
    <form action="mod.php" method="post" style="margin-top: 20px;">
        <label for="new_password">New Password:</label>
        <input type="password" name="new_password" id="new_password" required>
        <input type="submit" name="action" value="update_password">
    </form>
</div>

<?php if (isset($errorMessage) && !empty($errorMessage)) { ?>
    <div class="error">
        <?php echo htmlspecialchars($errorMessage, ENT_QUOTES | ENT_HTML5); ?>
    </div>
<?php } elseif (isset($successMessage) && !empty($successMessage)) { ?>
    <div class="success">
        <?php echo htmlspecialchars($successMessage, ENT_QUOTES | ENT_HTML5); ?>
    </div>
<?php } ?>

<div id="postsList">
    <h3>All Posts:</h3>
    <?php foreach ($posts as $post) { ?>
        <div class="post">
            <?php if (!empty($post['image'])) { ?>
                <img src="<?php echo htmlspecialchars($post['image'], ENT_QUOTES | ENT_HTML5); ?>" alt="Post Image" width="100" height="100" style="float:left; margin-right:10px;">
            <?php } ?>
            <span class="postName">Name: <?php echo htmlspecialchars($post["name"], ENT_QUOTES | ENT_HTML5); ?></span>
            <p class="postText" style="word-wrap: break-word; overflow-wrap: break-word;">Message: <?php echo nl2br(htmlspecialchars($post["text"], ENT_QUOTES | ENT_HTML5)); ?></p>
            <div style="clear: both;"></div>
            <form action="mod.php" method="post" style="margin-top: 10px;">
                <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                <button type="button" onclick="toggleEditForm(<?php echo $post['id']; ?>)">Edit</button>
                <input type="submit" name="action" value="delete" onclick="return confirm('Are you sure you want to delete this post and all its replies?');">
                <?php if (!empty($post['image'])) { ?>
                    <input type="submit" name="action" value="delete_image" onclick="return confirm('Are you sure you want to delete this image?');">
                <?php } ?>
            </form>
            <div id="editForm<?php echo $post['id']; ?>" class="editForm" style="display: none; margin-top: 10px;">
                <form action="mod.php" method="post">
                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                    <input type="text" name="name" value="<?php echo htmlspecialchars($post['name'], ENT_QUOTES | ENT_HTML5); ?>" maxlength="14" required>
                    <textarea name="text" required maxlength="14000"><?php echo htmlspecialchars($post['text'], ENT_QUOTES | ENT_HTML5); ?></textarea>
                    <input type="submit" name="action" value="edit">
                </form>
            </div>
        </div>
        <hr>
    <?php } ?>
</div>

<div id="pagination" style="text-align: center; margin-top: 20px;">
    <?php if ($page > 1) { ?>
        <a href="?page=<?php echo $page - 1; ?>">&laquo; Previous</a>
    <?php } ?>

    <?php for ($i = 1; $i <= $totalPages; $i++) { ?>
        <a href="?page=<?php echo $i; ?>" <?php if ($i == $page) echo 'class="active"'; ?>><?php echo $i; ?></a>
    <?php } ?>

    <?php if ($page < $totalPages) { ?>
        <a href="?page=<?php echo $page + 1; ?>">Next &raquo;</a>
    <?php } ?>
</div>

<script>
    function toggleEditForm(postId) {
        var editForm = document.getElementById('editForm' + postId);
        if (editForm.style.display === 'none') {
            editForm.style.display = 'block';
        } else {
            editForm.style.display = 'none';
        }
    }
</script>

</body>
</html>

