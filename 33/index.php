<?php
// Start the session to handle messages (optional)
session_start();

// Define the path to the posts data file
define('POSTS_FILE', 'posts.json');

// Define the uploads directory
define('UPLOAD_DIR', 'uploads/');

// Maximum allowed file size (e.g., 5MB)
define('MAX_FILE_SIZE', 5 * 1024 * 1024);

// Allowed MIME types for images
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];

// Ensure the uploads directory exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = trim($_POST['message'] ?? '');

    // Initialize variables
    $imagePath = '';

    // Validate the message
    if (empty($message)) {
        $_SESSION['error'] = 'Message cannot be empty.';
    } else {
        // Check if an image was uploaded
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['image'];

            // Check for upload errors
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['error'] = 'Error uploading the image.';
            } elseif (!in_array(mime_content_type($file['tmp_name']), $allowedMimeTypes)) {
                $_SESSION['error'] = 'Unsupported image type. Allowed types: JPEG, PNG, GIF.';
            } elseif ($file['size'] > MAX_FILE_SIZE) {
                $_SESSION['error'] = 'Image size exceeds the 5MB limit.';
            } else {
                // Generate a unique filename
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = uniqid('img_', true) . '.' . $ext;
                $destination = UPLOAD_DIR . $filename;

                // Move the uploaded file
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $imagePath = $destination;
                } else {
                    $_SESSION['error'] = 'Failed to save the uploaded image.';
                }
            }
        }

        // If no errors, save the post
        if (!isset($_SESSION['error'])) {
            // Prepare the new post data
            $newPost = [
                'id' => uniqid(),
                'message' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
                'image' => $imagePath,
                'timestamp' => time()
            ];

            // Load existing posts
            $posts = [];
            if (file_exists(POSTS_FILE)) {
                $json = file_get_contents(POSTS_FILE);
                $posts = json_decode($json, true) ?? [];
            }

            // Prepend the new post to display latest first
            array_unshift($posts, $newPost);

            // Save the posts back to the file
            file_put_contents(POSTS_FILE, json_encode($posts, JSON_PRETTY_PRINT));

            // Redirect to avoid form resubmission
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

// Load existing posts
$posts = [];
if (file_exists(POSTS_FILE)) {
    $json = file_get_contents(POSTS_FILE);
    $posts = json_decode($json, true) ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Simple Imageboard Clone</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 90%;
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            text-align: center;
            color: #333;
        }
        form {
            margin-bottom: 30px;
        }
        textarea {
            width: 100%;
            height: 80px;
            padding: 10px;
            resize: vertical;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
        }
        input[type="file"] {
            margin-top: 10px;
        }
        button {
            padding: 10px 20px;
            background-color: #4285F4;
            border: none;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover {
            background-color: #357ae8;
        }
        .error {
            color: red;
            margin-bottom: 15px;
        }
        .post {
            border-bottom: 1px solid #ddd;
            padding: 15px 0;
        }
        .post:last-child {
            border-bottom: none;
        }
        .message {
            font-size: 16px;
            color: #333;
        }
        .image {
            margin-top: 10px;
        }
        .image img {
            max-width: 100%;
            height: auto;
            border: 1px solid #ccc;
        }
        .timestamp {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Simple Imageboard Clone</h1>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="error"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <form action="" method="POST" enctype="multipart/form-data">
        <label for="message">Message:</label><br>
        <textarea name="message" id="message" required></textarea><br>
        <label for="image">Optional Image (JPEG, PNG, GIF | Max 5MB):</label><br>
        <input type="file" name="image" id="image" accept="image/*"><br><br>
        <button type="submit">Post</button>
    </form>

    <hr>

    <?php if (empty($posts)): ?>
        <p>No posts yet. Be the first to post!</p>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <div class="post">
                <div class="message"><?= nl2br($post['message']) ?></div>
                <?php if (!empty($post['image'])): ?>
                    <div class="image">
                        <img src="<?= htmlspecialchars($post['image']) ?>" alt="Uploaded Image">
                    </div>
                <?php endif; ?>
                <div class="timestamp"><?= date('Y-m-d H:i:s', $post['timestamp']) ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>
