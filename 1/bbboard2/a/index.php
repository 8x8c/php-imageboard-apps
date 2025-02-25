<?php
// Initialize the database file
$dbFile = "database.db";
$db = new SQLite3($dbFile);

// Create posts table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    text TEXT NOT NULL,
    image TEXT,
    parent_id INTEGER
)");

// Directory for uploaded images
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Check if the form has been submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get the post data from the form submission
    $postName = htmlspecialchars($_POST["name"], ENT_QUOTES);
    $postText = htmlspecialchars($_POST["post"], ENT_QUOTES);
    $parentId = isset($_POST["parent_id"]) ? intval($_POST["parent_id"]) : null;

    // Validate the input
    $nameLength = mb_strlen($postName);
    $textLength = mb_strlen($postText);
    $errorMessage = '';
    if ($nameLength > 14 || $textLength > 14000) {
        $errorMessage = "Name must be 14 characters or less and message must be 14000 characters or less.";
    } else {
        // Handle image upload
        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
            $imageFileType = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowedTypes = ['jpg', 'jpeg', 'gif', 'png'];

            if (in_array($imageFileType, $allowedTypes)) {
                $uniqueName = uniqid() . '.' . $imageFileType;
                $imagePath = $uploadDir . $uniqueName;
                move_uploaded_file($_FILES['image']['tmp_name'], $imagePath);
            } else {
                $errorMessage = "Only JPG, GIF, and PNG files are allowed.";
            }
        }

        if (empty($errorMessage)) {
            // Insert post into the database
            $stmt = $db->prepare("INSERT INTO posts (name, text, image, parent_id) VALUES (:name, :text, :image, :parent_id)");
            $stmt->bindValue(':name', $postName, SQLITE3_TEXT);
            $stmt->bindValue(':text', $postText, SQLITE3_TEXT);
            $stmt->bindValue(':image', $imagePath, SQLITE3_TEXT);
            $stmt->bindValue(':parent_id', $parentId, SQLITE3_INTEGER);
            $stmt->execute();

            // Redirect the user back to the homepage or reply page
            if ($parentId) {
                header("Location: reply.php?post_id=" . $parentId);
            } else {
                header("Location: index.php");
            }
            exit();
        }
    }
}

// Load the existing posts from the database
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$postsPerPage = 10;
$offset = ($page - 1) * $postsPerPage;

// Get the total number of posts
$totalPostsResult = $db->query("SELECT COUNT(*) as count FROM posts WHERE parent_id IS NULL");
$totalPostsRow = $totalPostsResult->fetchArray(SQLITE3_ASSOC);
$totalPosts = $totalPostsRow['count'];
$totalPages = ceil($totalPosts / $postsPerPage);

$stmt = $db->prepare("SELECT id, name, text, image FROM posts WHERE parent_id IS NULL ORDER BY id DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $postsPerPage, SQLITE3_INTEGER);
$stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
$result = $stmt->execute();
$posts = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $posts[] = $row;
}

// Load replies count for each post
$replyCountStmt = $db->prepare("SELECT COUNT(*) as count FROM posts WHERE parent_id = :parent_id");
$replyCounts = [];
foreach ($posts as $post) {
    $replyCountStmt->bindValue(':parent_id', $post['id'], SQLITE3_INTEGER);
    $replyCountResult = $replyCountStmt->execute();
    $replyCountRow = $replyCountResult->fetchArray(SQLITE3_ASSOC);
    $replyCounts[$post['id']] = $replyCountRow['count'];
}
?>

<!-- HTML code for the homepage -->
<html>
<head>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div id="postBox">
    <?php if (isset($errorMessage) && !empty($errorMessage)) { ?>
        <div class="error">
            <?php echo htmlspecialchars($errorMessage, ENT_QUOTES); ?> <a href="index.php">Return to main page</a>
        </div>
    <?php } ?>
    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES); ?>" method="post" enctype="multipart/form-data">
        <table>
            <tr>
                <td>Name:</td>
                <td><input type="text" name="name" value="Anonymous" required maxlength="14"></td>
            </tr>
            <tr>
                <td>Post:</td>
                <td><textarea name="post" required maxlength="14000"></textarea></td>
            </tr>
            <tr>
                <td>Image:</td>
                <td><input type="file" name="image" accept="image/*"></td>
            </tr>
        </table>
        <input type="submit" value="Post">
    </form>
</div>

<?php foreach ($posts as $post) { ?>
    <!-- HTML code for a single post -->
    <div class="reply">
        <?php if (!empty($post['image'])) { ?>
            <img src="<?php echo htmlspecialchars($post['image'], ENT_QUOTES); ?>" alt="Post Image" width="200" height="200" style="float:left; margin-right:10px;">
        <?php } ?>
        <span class="postName"><?php echo htmlspecialchars($post["name"], ENT_QUOTES); ?></span>
        <span style="float:right;"><a href="reply.php?post_id=<?php echo $post['id']; ?>">[Reply-<?php echo $replyCounts[$post['id']]; ?>]</a></span>
        <p class="postText"><?php echo nl2br(htmlspecialchars($post["text"], ENT_QUOTES)); ?></p>
        <div style="clear: both;"></div>
    </div>
<?php } ?>

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

<br><br><br>

</body>
</html>
