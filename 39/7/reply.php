<?php
declare(strict_types=1);
require_once 'common.php';

/**
 * reply.php
 *
 * Handles replies to a post.
 * Displays:
 *   - A link back to the main board.
 *   - A reply form (name and message only).
 *   - The original post and all replies, each with a small deletion checkbox at the top left.
 *   - A deletion control area at the bottom right (asking for a password and a Delete button).
 *
 * When a reply is submitted, the post’s reply_count is incremented and bumped.
 * When deletion is submitted, checked items (original post or replies) are deleted.
 */

// Configuration
$dbFile = __DIR__ . '/board.db';
$adminPassword = "secret123";

$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Process deletion if the deletion form was submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['moddelete'])) {
    if (isset($_POST['password']) && $_POST['password'] === $adminPassword && isset($_POST['delete'])) {
        $deleteItems = $_POST['delete'];
        $deletePost = false;
        $deletedReplies = [];
        foreach ($deleteItems as $item) {
            if (str_starts_with($item, 'p-')) {
                $deletePost = true;
            } elseif (str_starts_with($item, 'r-')) {
                $replyId = (int)substr($item, 2);
                $deletedReplies[] = $replyId;
            }
        }
        if ($deletePost) {
            // Delete original post and all its replies.
            $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
            $stmt->execute([$_GET['post_id']]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($post) {
                if (!empty($post['filepath']) && file_exists(__DIR__ . '/' . $post['filepath'])) {
                    unlink(__DIR__ . '/' . $post['filepath']);
                }
                if (!empty($post['thumbpath']) && file_exists(__DIR__ . '/' . $post['thumbpath'])) {
                    unlink(__DIR__ . '/' . $post['thumbpath']);
                }
            }
            $stmtDeletePost = $pdo->prepare("DELETE FROM posts WHERE id = ?");
            $stmtDeletePost->execute([$_GET['post_id']]);
            $stmtDeleteReplies = $pdo->prepare("DELETE FROM replies WHERE post_id = ?");
            $stmtDeleteReplies->execute([$_GET['post_id']]);
            generatePagination($pdo);
            header("Location: index.html");
            exit;
        } else {
            // Delete selected replies.
            foreach ($deletedReplies as $replyId) {
                $stmtDeleteReply = $pdo->prepare("DELETE FROM replies WHERE id = ?");
                $stmtDeleteReply->execute([$replyId]);
            }
            // Update reply_count of the post.
            $stmtUpdate = $pdo->prepare("UPDATE posts SET reply_count = reply_count - ? WHERE id = ?");
            $stmtUpdate->execute([count($deletedReplies), $_GET['post_id']]);
        }
    }
    generatePagination($pdo);
    header("Location: index.html");
    exit;
}

// Get post_id from GET parameter
$post_id = (int)filter_input(INPUT_GET, 'post_id', FILTER_VALIDATE_INT);
if ($post_id <= 0) {
    die("No valid post specified.");
}

// Fetch original post
$stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
$stmt->execute([$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) {
    die("Post not found.");
}

// Process new reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_submit'])) {
    $name    = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING) ?? 'Anonymous');
    $message = trim(filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING) ?? '');
    $time    = time();
    if ($message !== '') {
        $replyNumber = $post['reply_count'] + 1;
        $stmtInsert = $pdo->prepare("INSERT INTO replies (post_id, name, message, created_at, reply_number) VALUES (?, ?, ?, ?, ?)");
        $stmtInsert->execute([$post_id, $name, $message, $time, $replyNumber]);
        $stmtUpdate = $pdo->prepare("UPDATE posts SET reply_count = reply_count + 1, bumped_at = ? WHERE id = ?");
        $stmtUpdate->execute([$time, $post_id]);
        $stmt->execute([$post_id]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        generatePagination($pdo);
    }
}

// Fetch replies in ascending order
$stmtReplies = $pdo->prepare("SELECT * FROM replies WHERE post_id = ? ORDER BY id ASC");
$stmtReplies->execute([$post_id]);
$replies = $stmtReplies->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Reply to Post <?php echo $post_id; ?></title>
  <link rel="shortcut icon" href="favicon.ico">
  <link id="stylesheet" rel="stylesheet" href="css/light.css">
</head>
<body>
  <!-- Link back to main board -->
  <div style="margin:10px;">
    <a href="index.html">Return to Board</a>
  </div>
  
  <!-- Display deletion checkboxes above each post/reply -->
  <!-- Original Post with deletion checkbox -->
  <div class="op" style="background:#EEE; padding:10px; margin:10px; position:relative;">
    <div style="position:absolute; top:5px; left:5px;">
      <input type="checkbox" name="delete[]" value="p-<?php echo $post_id; ?>">
    </div>
    <div class="post-header" style="overflow:hidden; margin-left:25px;">
      <div style="float:left;">
        <?php if ($post['subject']): ?>
          <span class="filetitle"><?php echo htmlspecialchars($post['subject'], ENT_QUOTES); ?></span>
        <?php endif; ?>
        <span class="postername"><?php echo htmlspecialchars($post['name'] ?: 'Anonymous', ENT_QUOTES); ?></span>
      </div>
    </div>
    <div class="message"><?php echo nl2br(htmlspecialchars($post['message'], ENT_QUOTES)); ?></div>
  </div>
  <hr>
  
  <!-- Reply Form -->
  <div class="postarea">
    <h2>Reply to Post #<?php echo $post_id; ?></h2>
    <form action="reply.php?post_id=<?php echo $post_id; ?>" method="post">
      <table>
        <tr>
          <td class="postblock">Name</td>
          <td><input type="text" name="name" size="28" maxlength="75"></td>
        </tr>
        <tr>
          <td class="postblock">Message</td>
          <td>
            <textarea name="message" cols="48" rows="4" maxlength="8000"></textarea>
          </td>
        </tr>
        <tr>
          <td colspan="2">
            <input type="submit" name="reply_submit" value="Submit Reply">
          </td>
        </tr>
      </table>
    </form>
  </div>
  <hr>
  
  <!-- Replies -->
  <?php foreach ($replies as $reply): ?>
    <div class="op" style="background:#F9F9F9; padding:10px; margin:10px; position:relative;">
      <div style="position:absolute; top:5px; left:5px;">
        <input type="checkbox" name="delete[]" form="modForm" value="r-<?php echo $reply['id']; ?>">
      </div>
      <div class="post-header" style="overflow:hidden; margin-left:25px;">
        <div style="float:left;">
          <span class="postername"><?php echo htmlspecialchars($reply['name'] ?: 'Anonymous', ENT_QUOTES); ?></span>
        </div>
        <div style="float:right;">Reply <?php echo $reply['reply_number']; ?></div>
      </div>
      <div class="message"><?php echo nl2br(htmlspecialchars($reply['message'], ENT_QUOTES)); ?></div>
    </div>
    <hr>
  <?php endforeach; ?>
  
  <!-- Deletion Controls (mod area) at bottom right -->
  <form id="modForm" action="reply.php?post_id=<?php echo $post_id; ?>" method="post" style="position:fixed; bottom:10px; right:10px; background:#ddd; padding:10px; border:1px solid #aaa;">
    <input type="password" name="password" size="8" placeholder="Mod Password">
    <input type="submit" name="moddelete" value="Delete Selected">
  </form>
  
  <script src="js/switchStylesheet.js"></script>
  <script src="js/toggleImage.js"></script>
</body>
</html>

