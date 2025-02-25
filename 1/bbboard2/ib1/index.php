<?php
// Initialize the database file
$dbFile = "database.db";
$db = new SQLite3($dbFile);

// Create posts table if it doesn't exist
$db->exec("CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    text TEXT NOT NULL,
    date TEXT NOT NULL,
    image TEXT
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

    // Validate the input
    $nameLength = strlen($postName);
    $textLength = strlen($postText);
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
            $stmt = $db->prepare("INSERT INTO posts (name, text, date, image) VALUES (:name, :text, :date, :image)");
            $stmt->bindValue(':name', $postName, SQLITE3_TEXT);
            $stmt->bindValue(':text', $postText, SQLITE3_TEXT);
            $stmt->bindValue(':date', date("Y/m/d g:i:s"), SQLITE3_TEXT);
            $stmt->bindValue(':image', $imagePath, SQLITE3_TEXT);
            $stmt->execute();

            // Redirect the user back to the homepage
            header("Location: index.php");
            exit();
        }
    }
}

// Load the existing posts from the database
$numPosts = isset($_GET["num_posts"]) ? $_GET["num_posts"] : 10;
$numPosts = min(max(intval($numPosts), 5), 500);
$result = $db->query("SELECT * FROM posts ORDER BY id DESC LIMIT $numPosts");
$posts = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $posts[] = $row;
}
?>

<!-- HTML code for the homepage -->
<html>
<head>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div id="showPosts">
    <span>Show:</span>
    <?php
    $displayOptions = array(5, 15, 25, 50, 100, 200, 500);
    foreach ($displayOptions as $option) {
        if ($option == $numPosts) {
            echo '<a href="?num_posts=' . $option . '" class="active">' . $option . '</a> ';
        } else {
            echo '<a href="?num_posts=' . $option . '">' . $option . '</a> ';
        }
    }
    ?>
</div>

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
        <span class="postDate"><?php echo htmlspecialchars($post["date"], ENT_QUOTES); ?></span>
        <p class="postText"><?php echo nl2br(htmlspecialchars($post["text"], ENT_QUOTES)); ?></p>
        <div style="clear: both;"></div>
    </div>
<?php } ?>

<br><br><br>

</body>
</html>
