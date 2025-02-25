<?php
// Configuration
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('CSV_FILE', __DIR__ . '/posts.csv');

// Ensure necessary directories and files exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0777, true);
}
if (!file_exists(CSV_FILE)) {
    file_put_contents(CSV_FILE, "id,username,message,image,timestamp\n");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = htmlspecialchars($_POST['username'] ?? 'Anonymous');
    $message = htmlspecialchars($_POST['message'] ?? '');
    $timestamp = time();
    $imageName = '';

    // Handle file upload
    if (!empty($_FILES['image']['name'])) {
        $imageName = $timestamp . '_' . basename($_FILES['image']['name']);
        $uploadPath = UPLOAD_DIR . $imageName;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadPath)) {
            $imageName = '';
        }
    }

    // Append post to the CSV file
    $id = uniqid();
    $csvLine = implode(',', [$id, $username, $message, $imageName, $timestamp]) . "\n";
    file_put_contents(CSV_FILE, $csvLine, FILE_APPEND);

    // Redirect to clear form submission
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Read posts from the CSV file
$posts = array_reverse(array_map('str_getcsv', file(CSV_FILE)));
unset($posts[count($posts) - 1]); // Remove header line
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Imageboard</title>
    <style>
        body { font-family: Arial, sans-serif; }
        form { margin-bottom: 20px; }
        .post { border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; }
        .post img { max-width: 200px; height: auto; }
    </style>
</head>
<body>
    <h1>Simple Imageboard</h1>

    <form method="POST" enctype="multipart/form-data">
        <label for="username">Username:</label><br>
        <input type="text" id="username" name="username" placeholder="Anonymous"><br><br>

        <label for="message">Message:</label><br>
        <textarea id="message" name="message" rows="4" cols="50" placeholder="Write your message..."></textarea><br><br>

        <label for="image">Image:</label><br>
        <input type="file" id="image" name="image" accept="image/*"><br><br>

        <button type="submit">Post</button>
    </form>

    <h2>Posts</h2>
    <?php if (empty($posts)): ?>
        <p>No posts yet.</p>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <?php list($id, $username, $message, $image, $timestamp) = $post; ?>
            <div class="post">
                <strong><?= htmlspecialchars($username) ?></strong> <em>(<?= date('Y-m-d H:i:s', $timestamp) ?>)</em>
                <p><?= nl2br(htmlspecialchars($message)) ?></p>
                <?php if ($image): ?>
                    <img src="uploads/<?= htmlspecialchars($image) ?>" alt="User Image">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
