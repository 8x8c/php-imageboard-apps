<?php
// Initialize the database file
$dbFile = "database.db";
$db = new SQLite3($dbFile);

// Load the original post
$parentId = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
$parentPostStmt = $db->prepare("SELECT id, name, text, image FROM posts WHERE id = :parent_id AND parent_id IS NULL");
$parentPostStmt->bindValue(':parent_id', $parentId, SQLITE3_INTEGER);
$parentPostResult = $parentPostStmt->execute();
$parentPost = $parentPostResult->fetchArray(SQLITE3_ASSOC);

if (!$parentPost) {
    die("Post not found.");
}

// Load replies to the original post
$replyStmt = $db->prepare("SELECT id, name, text, image FROM posts WHERE parent_id = :parent_id ORDER BY id ASC");
$replyStmt->bindValue(':parent_id', $parentId, SQLITE3_INTEGER);
$replyResult = $replyStmt->execute();
$replies = [];
while ($replyRow = $replyResult->fetchArray(SQLITE3_ASSOC)) {
    $replies[] = $replyRow;
}

// Check if the reply form has been submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get the reply data from the form submission
    $replyName = htmlspecialchars($_POST["name"], ENT_QUOTES);
    $replyText = htmlspecialchars($_POST["post"], ENT_QUOTES);

    // Validate the input
    $nameLength = mb_strlen($replyName);
    $textLength = mb_strlen($replyText);
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
                $uploadDir = 'uploads/';
                $imagePath = $uploadDir . $uniqueName;
                move_uploaded_file($_FILES['image']['tmp_name'], $imagePath);
            } else {
                $errorMessage = "Only JPG, GIF, and PNG files are allowed.";
            }
        }

        if (empty($errorMessage)) {
            // Insert reply into the database
            $stmt = $db->prepare("INSERT INTO posts (name, text, image, parent_id) VALUES (:name, :text, :image, :parent_id)");
            $stmt->bindValue(':name', $replyName, SQLITE3_TEXT);
            $stmt->bindValue(':text', $replyText, SQLITE3_TEXT);
            $stmt->bindValue(':image', $imagePath, SQLITE3_TEXT);
            $stmt->bindValue(':parent_id', $parentId, SQLITE3_INTEGER);
            $stmt->execute();

            // Redirect back to the reply page
            header("Location: reply.php?post_id=" . $parentId);
            exit();
        }
    }
}
?>

<!-- HTML code for the reply page -->
<html>
<head>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div id="replyMode">
    <h2>Reply Mode</h2>
    <a href="index.php" style="display: block; text-align: center; margin-bottom: 20px;">Return to main board</a>
</div>

<div id="replyForm">
    <?php if (isset($errorMessage) && !empty($errorMessage)) { ?>
        <div class="error">
            <?php echo htmlspecialchars($errorMessage, ENT_QUOTES); ?> <a href="reply.php?post_id=<?php echo $parentId; ?>">Return to reply mode</a>
        </div>
    <?php } ?>
    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?post_id=' . $parentId, ENT_QUOTES); ?>" method="post" enctype="multipart/form-data">
        <table>
            <tr>
                <td>Name:</td>
                <td><input type="text" name="name" value="Anonymous" required maxlength="14"></td>
            </tr>
            <tr>
                <td>Reply:</td>
                <td><textarea name="post" required maxlength="14000"></textarea></td>
            </tr>
            <tr>
                <td>Image:</td>
                <td><input type="file" name="image" accept="image/*"></td>
            </tr>
        </table>
        <input type="submit" value="Reply">
    </form>
</div>

<!-- Display the original post in a consistent box style -->
<div class="reply">
    <h3>Original Post:</h3>
    <?php if (!empty($parentPost['image'])) { ?>
        <img src="<?php echo htmlspecialchars($parentPost['image'], ENT_QUOTES); ?>" alt="Post Image" width="200" height="200" style="float:left; margin-right:10px;">
    <?php } ?>
    <span class="postName"><?php echo htmlspecialchars($parentPost["name"], ENT_QUOTES); ?></span>
    <p class="postText" style="word-wrap: break-word; overflow-wrap: break-word;"><?php echo nl2br(htmlspecialchars($parentPost["text"], ENT_QUOTES)); ?></p>
    <div style="clear: both;"></div>
</div>

<!-- Display the replies -->
<div class="replies">
    <h3>Replies:</h3>
    <?php foreach ($replies as $reply) { ?>
        <div class="reply">
            <?php if (!empty($reply['image'])) { ?>
                <img src="<?php echo htmlspecialchars($reply['image'], ENT_QUOTES); ?>" alt="Reply Image" width="100" height="100" style="float:left; margin-right:10px;">
            <?php } ?>
            <span class="postName"><?php echo htmlspecialchars($reply["name"], ENT_QUOTES); ?></span>
            <p class="postText" style="word-wrap: break-word; overflow-wrap: break-word;"><?php echo nl2br(htmlspecialchars($reply["text"], ENT_QUOTES)); ?></p>
            <div style="clear: both;"></div>
        </div>
    <?php } ?>
</div>

<br><br><br>

</body>
</html>
