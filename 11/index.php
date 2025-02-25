<?php
declare(strict_types=1);
session_start([
    'cookie_lifetime' => 86400,
    'cookie_httponly' => true,
    'cookie_secure' => true,
    'cookie_samesite' => 'Strict',
]);

// Regenerate session ID to prevent session fixation
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}

$db = new SQLite3('message_board.db');

// Create posts table if it doesn't exist
$db->exec('CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    media TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// Create replies table if it doesn't exist
$db->exec('CREATE TABLE IF NOT EXISTS replies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    message TEXT NOT NULL,
    FOREIGN KEY(post_id) REFERENCES posts(id)
)');

// Create trigger to update timestamp on post update
$db->exec('CREATE TRIGGER IF NOT EXISTS update_timestamp
           AFTER UPDATE ON posts
           FOR EACH ROW
           WHEN NEW.updated_at <= OLD.updated_at
           BEGIN
               UPDATE posts SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
           END');

$uploadsDir = 'uploads/';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

function getUniqueFilename(string $directory, string $filename): string {
    $path_info = pathinfo($filename);
    $basename = $path_info['filename'] ?? 'file';
    $extension = isset($path_info['extension']) ? '.' . $path_info['extension'] : '';
    $newFilename = $basename . $extension;
    $counter = 1;
    
    while (file_exists($directory . $newFilename)) {
        $newFilename = $basename . '-' . $counter . $extension;
        $counter++;
    }
    
    return $newFilename;
}

function getReplyCount(int $post_id): int {
    global $db;
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM replies WHERE post_id = :post_id");
    $stmt->bindValue(':post_id', $post_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return (int)$row['count'];
}

function renderPost(int $id, string $title, string $message, ?string $mediaPath = ''): string {
    $replyCount = getReplyCount($id);
    $mediaTag = '';
    if ($mediaPath) {
        $fileType = mime_content_type($mediaPath);
        if (strstr($fileType, 'video')) {
            $mediaTag = '<video class="post-media" controls width="200" height="200"><source src="' . htmlspecialchars($mediaPath, ENT_QUOTES) . '"></video>';
        } else {
            $mediaTag = '<img class="post-media" src="' . htmlspecialchars($mediaPath, ENT_QUOTES) . '" alt="media">';
        }
    }

    return '
        <div class="post">
            <hr class="green-hr">
            <div class="post-media-container">' . $mediaTag . '</div>
            <h2>' . htmlentities($title) . '</h2>
            <p style="word-wrap: break-word; overflow-wrap: break-word;">' . nl2br(htmlentities($message)) . '</p>
            <a class="reply-button" href="?post_id=' . $id . '">[reply-' . $replyCount . ']</a>
        </div>
    ';
}

function renderReply(string $message, int $index): string {
    return '<div class="reply"><p><strong>Reply ' . $index . ':</strong> ' . nl2br(htmlentities($message)) . '</p></div>';
}

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool {
    return isset($_SESSION['csrf_token']) && $token === $_SESSION['csrf_token'];
}

$csrf_token = generateCsrfToken();

// Check if we are in "single post" mode or "main board" mode
$post_id = filter_input(INPUT_GET, 'post_id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle both new posts and replies based on presence of post_id in POST
    $csrf_token_post = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING);

    if (!validateCsrfToken($csrf_token_post)) {
        die('Invalid CSRF token');
    }

    if (isset($_POST['post_id'])) {
        // This is a reply to an existing post
        $post_id_reply = filter_input(INPUT_POST, 'post_id', FILTER_VALIDATE_INT);
        $replyMessage = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (empty($replyMessage)) {
            die('Reply message is required.');
        }

        $stmt = $db->prepare('INSERT INTO replies (post_id, message) VALUES (:post_id, :message)');
        $stmt->bindValue(':post_id', $post_id_reply, SQLITE3_INTEGER);
        $stmt->bindValue(':message', $replyMessage, SQLITE3_TEXT);
        $stmt->execute();

        // Bump the original post
        $db->exec('UPDATE posts SET id = id WHERE id = ' . $post_id_reply);

        header('Location: ?post_id=' . $post_id_reply);
        exit;
    } else {
        // This is a new post
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (empty($title) || empty($message)) {
            die('Title and message are required.');
        }

        $media = $_FILES['media'] ?? null;
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/webm', 'video/mp4'];
        if ($media && $media['size'] > 0 && !in_array($media['type'], $allowed_types, true)) {
            die('Invalid file type');
        }

        if ($media && $media['size'] > 5 * 1024 * 1024) {
            die('File too large');
        }

        $mediaPath = '';
        if ($media && $media['size'] > 0) {
            $uniqueFilename = getUniqueFilename($uploadsDir, basename($media['name']));
            $mediaPath = $uploadsDir . $uniqueFilename;
            move_uploaded_file($media['tmp_name'], $mediaPath);
        }

        $stmt = $db->prepare('INSERT INTO posts (title, message, media) VALUES (:title, :message, :media)');
        $stmt->bindValue(':title', $title, SQLITE3_TEXT);
        $stmt->bindValue(':message', $message, SQLITE3_TEXT);
        $stmt->bindValue(':media', $mediaPath, SQLITE3_TEXT);
        $result = $stmt->execute();

        if ($result) {
            echo "<p>Post inserted successfully.</p>";
        } else {
            echo "<p>Failed to insert post.</p>";
        }

        echo renderPost($db->lastInsertRowID(), $title, $message, $mediaPath);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Message Board</title>
    <style>
        body {
            background-color: #B0C4DE;
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }
        .message-board {
            width: 90%;
            max-width: 1200px;
            margin: auto;
            padding: 20px;
            background: #F0F0F0;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .form-container {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .form-container form {
            width: 100%;
            max-width: 600px;
            display: none;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .post {
            margin-bottom: 20px;
            padding: 10px;
            background: #E0E0E0; 
            border-radius: 5px;
            position: relative;
        }
        .green-hr {
            border: 5px solid green;
        }
        .post-media {
            width: 200px;
            height: auto;
            cursor: pointer;
            object-fit: contain;
        }
        .post-media.expanded {
            width: 100%;
            max-width: 100%;
            height: auto;
        }
        .post-media video {
            width: 100%;
            height: auto;
        }
        form input[type="text"], form textarea, form input[type="file"], form button {
            width: 100%;
            margin-bottom: 10px;
        }
        form textarea {
            height: 100px;
        }
        form button {
            padding: 10px;
            background: green;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .toggle-buttons {
            text-align: center;
            margin-bottom: 20px;
        }
        .toggle-buttons button {
            padding: 10px;
            background: green;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .toggle-buttons .close-button {
            background: red;
            display: none; 
        }
        .reply-button {
            position: absolute;
            top: 10px;
            right: 10px;
            background: blue;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
        }
        .pagination {
            text-align: center;
            margin-top: 20px;
        }
        .pagination a {
            margin: 0 5px;
            padding: 10px 15px;
            background: #ddd;
            color: #000;
            text-decoration: none;
            border-radius: 5px;
        }
        .pagination a.active {
            background: #333;
            color: #fff;
        }
        .reply {
            margin-bottom: 10px;
            padding: 10px;
            background: #D0D0D0;
            border-radius: 5px;
        }
        .back-link {
            display: block;
            margin-bottom: 20px;
            color: blue;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="message-board">
        <?php if ($post_id): ?>
            <!-- Single Post View with Replies -->
            <a class="back-link" href="./">Back to Main Board</a>
            <?php
            $stmt = $db->prepare('SELECT * FROM posts WHERE id = :id');
            $stmt->bindValue(':id', $post_id, SQLITE3_INTEGER);
            $res = $stmt->execute();
            $post = $res->fetchArray(SQLITE3_ASSOC);
            if (!$post) {
                die('Post not found.');
            }
            echo renderPost((int)$post['id'], $post['title'], $post['message'], $post['media']);
            ?>
            <form method="post">
                <input type="hidden" name="post_id" value="<?= (int)$post_id; ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                <textarea name="message" placeholder="Reply message" required></textarea><br>
                <button type="submit">Post Reply</button>
            </form>
            <?php
            $stmtReplies = $db->prepare('SELECT * FROM replies WHERE post_id = :post_id ORDER BY id ASC');
            $stmtReplies->bindValue(':post_id', $post_id, SQLITE3_INTEGER);
            $rResult = $stmtReplies->execute();
            $index = 1;
            while ($reply = $rResult->fetchArray(SQLITE3_ASSOC)) {
                echo renderReply($reply['message'], $index++);
            }
            ?>
        <?php else: ?>
            <!-- Main Board View -->
            <div class="toggle-buttons">
                <button class="new-post-button">[NEW POST]</button>
                <button class="close-button">[X]</button>
            </div>
            <div class="form-container">
                <form id="postForm" enctype="multipart/form-data" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                    <input type="text" name="title" placeholder="Title" maxlength="20" required><br>
                    <textarea name="message" placeholder="Message" maxlength="100000" required></textarea><br>
                    <input type="file" name="media" accept="image/jpeg, image/png, image/gif, image/webp, video/webm, video/mp4"><br>
                    <button type="submit">Post</button>
                </form>
            </div>
            <div id="posts">
                <?php
                // Pagination
                $postsPerPage = 10;
                $totalPosts = $db->querySingle('SELECT COUNT(*) FROM posts');
                $totalPages = (int)ceil($totalPosts / $postsPerPage);
                $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
                if ($page < 1) $page = 1;
                $offset = ($page - 1) * $postsPerPage;

                $stmt = $db->prepare("SELECT * FROM posts ORDER BY updated_at DESC LIMIT :limit OFFSET :offset");
                $stmt->bindValue(':limit', $postsPerPage, SQLITE3_INTEGER);
                $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
                $result = $stmt->execute();

                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    echo renderPost((int)$row['id'], $row['title'], $row['message'], $row['media']);
                }
                ?>
            </div>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i; ?>" class="<?= ($i == $page) ? 'active' : ''; ?>"><?= $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.new-post-button').click(function() {
                $(this).hide();
                $('.close-button').show();
                $('.form-container form').slideDown();
            });

            $('.close-button').click(function() {
                $(this).hide();
                $('.new-post-button').show();
                $('.form-container form').slideUp();
            });

            $('#postForm').on('submit', function(e) {
                e.preventDefault();
                var formData = new FormData(this);
                $.ajax({
                    type: 'POST',
                    url: '',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function(response) {
                        $('#posts').prepend(response);
                        $('#postForm')[0].reset();
                        $('.close-button').hide();
                        $('.new-post-button').show();
                        $('.form-container form').slideUp();
                    }
                });
            });

            $(document).on('click', '.post-media', function() {
                $(this).toggleClass('expanded');
            });
        });
    </script>
</body>
</html>
