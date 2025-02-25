<?php
// Start the session
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

// Hashed password for moderator access
define('MODERATOR_PASSWORD_HASH', 'your_hashed_password_here');

// Check if the user is logged in as a moderator
if (isset($_POST['password'])) {
    if (password_verify($_POST['password'], MODERATOR_PASSWORD_HASH)) {
        $_SESSION['is_moderator'] = true;
    } else {
        echo '<p style="color: red;">Incorrect password.</p>';
    }
} elseif (isset($_GET['logout'])) {
    unset($_SESSION['is_moderator']);
}

// Redirect to login if not logged in
if (!isset($_SESSION['is_moderator'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Moderator Login</title>
    </head>
    <body>
        <form method="post" action="">
            <label for="password">Moderator Password:</label>
            <input type="password" name="password" id="password" required>
            <button type="submit">Login</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// Setup SQLite3 database
$db = new SQLite3('message_board.db');

// Handle post deletion
if (isset($_POST['delete_post_id'])) {
    $post_id = filter_input(INPUT_POST, 'delete_post_id', FILTER_SANITIZE_NUMBER_INT);
    $stmt = $db->prepare('UPDATE posts SET title = :title, message = :message WHERE id = :id');
    $stmt->bindValue(':title', 'Post Deleted By Moderator', SQLITE3_TEXT);
    $stmt->bindValue(':message', 'Post Deleted By Moderator', SQLITE3_TEXT);
    $stmt->bindValue(':id', $post_id, SQLITE3_INTEGER);
    $stmt->execute();
}

// Handle reply deletion
if (isset($_POST['delete_reply_id'])) {
    $reply_id = filter_input(INPUT_POST, 'delete_reply_id', FILTER_SANITIZE_NUMBER_INT);
    $stmt = $db->prepare('UPDATE replies SET message = :message WHERE id = :id');
    $stmt->bindValue(':message', 'Reply Deleted By Moderator', SQLITE3_TEXT);
    $stmt->bindValue(':id', $reply_id, SQLITE3_INTEGER);
    $stmt->execute();
}

// Fetch posts
$posts = $db->query('SELECT * FROM posts ORDER BY updated_at DESC');

// Fetch replies for a post
function fetchReplies($post_id, $db) {
    $stmt = $db->prepare('SELECT * FROM replies WHERE post_id = :post_id ORDER BY id ASC');
    $stmt->bindValue(':post_id', $post_id, SQLITE3_INTEGER);
    return $stmt->execute();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moderator Panel</title>
    <style>
        body {
            font-family: Arial, sans-serif;
        }
        .post, .reply {
            margin-bottom: 20px;
            padding: 10px;
            background: #E0E0E0;
            border-radius: 5px;
        }
        .delete-button {
            background: red;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <h1>Moderator Panel</h1>
    <a href="?logout=1">Logout</a>
    <div class="message-board">
        <?php
        while ($post = $posts->fetchArray(SQLITE3_ASSOC)) {
            echo '<div class="post">';
            echo '<h2>' . htmlentities($post['title']) . '</h2>';
            echo '<p>' . nl2br(htmlentities($post['message'])) . '</p>';
            echo '<form method="post" action="" onsubmit="return confirm(\'Are you sure you want to delete this post?\');">';
            echo '<input type="hidden" name="delete_post_id" value="' . $post['id'] . '">';
            echo '<button type="submit" class="delete-button">Delete Post</button>';
            echo '</form>';
            echo '</div>';

            $replies = fetchReplies($post['id'], $db);
            while ($reply = $replies->fetchArray(SQLITE3_ASSOC)) {
                echo '<div class="reply">';
                echo '<p><strong>Reply ' . $reply['id'] . ':</strong> ' . nl2br(htmlentities($reply['message'])) . '</p>';
                echo '<form method="post" action="" onsubmit="return confirm(\'Are you sure you want to delete this reply?\');">';
                echo '<input type="hidden" name="delete_reply_id" value="' . $reply['id'] . '">';
                echo '<button type="submit" class="delete-button">Delete Reply</button>';
                echo '</form>';
                echo '</div>';
            }
        }
        ?>
    </div>
</body>
</html>
