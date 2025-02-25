<?php
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

function renderPost($id, $title, $message, $mediaPath) {
    $mediaTag = '';
    if ($mediaPath) {
        $fileType = mime_content_type($mediaPath);
        if (strstr($fileType, 'video')) {
            $mediaTag = '<video class="post-media" controls width="200" height="200"><source src="' . $mediaPath . '"></video>';
        } else {
            $mediaTag = '<img class="post-media" src="' . $mediaPath . '" alt="media">';
        }
    }

    return '
        <div class="post">
            <hr class="green-hr">
            <div class="post-media-container">' . $mediaTag . '</div>
            <h2>' . htmlentities($title) . '</h2>
            <p style="word-wrap: break-word;">' . nl2br(htmlentities($message)) . '</p>
        </div>
    ';
}

function renderReply($message, $index) {
    return '<div class="reply"><p><strong>Reply ' . $index . ':</strong> ' . nl2br(htmlentities($message)) . '</p></div>';
}

function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return $token === $_SESSION['csrf_token'];
}

$csrf_token = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_id = filter_input(INPUT_POST, 'post_id', FILTER_SANITIZE_NUMBER_INT);
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING);
    $captcha = filter_input(INPUT_POST, 'captcha', FILTER_SANITIZE_STRING);
    $csrf_token_post = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING);

    if (!validateCsrfToken($csrf_token_post)) {
        die('Invalid CSRF token');
    }

    if (empty($message)) {
        die('Reply message is required.');
    }

    if (empty($captcha) || $captcha !== $_SESSION['captcha_text']) {
        die('Invalid CAPTCHA.');
    }

    $stmt = $db->prepare('INSERT INTO replies (post_id, message) VALUES (:post_id, :message)');
    $stmt->bindValue(':post_id', $post_id, SQLITE3_INTEGER);
    $stmt->bindValue(':message', $message, SQLITE3_TEXT);
    $stmt->execute();

    // Bump the original post to the top by updating its row
    $db->exec('UPDATE posts SET id = id WHERE id = ' . $post_id);

    header('Location: reply.php?post_id=' . $post_id);
    exit;
}

$post_id = filter_input(INPUT_GET, 'post_id', FILTER_SANITIZE_NUMBER_INT);
if (empty($post_id)) {
    die('Post ID is required.');
}

$result = $db->query('SELECT * FROM posts WHERE id = ' . $post_id);
$post = $result->fetchArray(SQLITE3_ASSOC);
if (!$post) {
    die('Post not found.');
}

$replies = $db->query('SELECT * FROM replies WHERE post_id = ' . $post_id . ' ORDER BY id ASC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reply to Post</title>
    <style>
        /* Add basic styling here */
        body {
            background-color: #B0C4DE; /* Darker blue shade */
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }
        .message-board {
            width: 90%;
            max-width: 800px;
            margin: auto;
            padding: 20px;
            background: #F0F0F0; /* Light grey background for posts */
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .post {
            margin-bottom: 20px;
            padding: 10px;
            background: #E0E0E0; /* Grey background for individual posts */
            border-radius: 5px;
        }
        .green-hr {
            border: 5px solid green;
        }
        .post-media {
            width: 100%;
            height: auto;
            object-fit: contain;
        }
        .post-media video {
            width: 100%;
            height: auto;
        }
        .reply {
            margin-bottom: 10px;
            padding: 10px;
            background: #D0D0D0; /* Slightly darker grey for replies */
            border-radius: 5px;
        }
        form textarea, form button, form input[type="text"] {
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
        <a class="back-link" href="./">Back to Main Board</a>
        <?php
        echo renderPost($post['id'], $post['title'], $post['message'], $post['media']);
        ?>
        <form method="post">
            <input type="hidden" name="post_id" value="<?php echo $post_id; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <textarea name="message" placeholder="Reply message" required></textarea><br>
            <img src="simple_captcha.php" alt="CAPTCHA Image"><br>
            <input type="text" name="captcha" placeholder="Enter CAPTCHA" required><br>
            <button type="submit">Post Reply</button>
        </form>
        <?php
        $index = 1;
        while ($reply = $replies->fetchArray(SQLITE3_ASSOC)) {
            echo renderReply($reply['message'], $index++);
        }
        ?>
    </div>
</body>
</html>
