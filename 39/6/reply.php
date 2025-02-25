
<?php
/**************************************************
 * reply.php
 * -----------------------------------------------
 * This script handles replies to a post.
 * It displays a link back to the main board,
 * a reply form (name and message only), the original post,
 * and then all replies in chronological order.
 *
 * When a reply is submitted, it increments the post's reply_count,
 * updates its bumped_at timestamp (to bump the post),
 * and regenerates the static paginated main board.
 *
 * Shared functions are included from common.php.
 **************************************************/

require_once 'common.php';

// Configuration
$dbFile = __DIR__ . '/board.db';

// Establish PDO connection
$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get post_id from GET parameter
if (!isset($_GET['post_id'])) {
    die("No post specified.");
}
$post_id = intval($_GET['post_id']);

// Fetch the original post
$stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
$stmt->execute([$post_id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) {
    die("Post not found.");
}

// Process new reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? 'Anonymous');
    $message = trim($_POST['message'] ?? '');
    $time    = time();
    if ($message !== '') {
        $replyNumber = $post['reply_count'] + 1;
        $stmtInsert = $pdo->prepare("INSERT INTO replies (post_id, name, message, created_at, reply_number) VALUES (?, ?, ?, ?, ?)");
        $stmtInsert->execute([$post_id, $name, $message, $time, $replyNumber]);
        $stmtUpdate = $pdo->prepare("UPDATE posts SET reply_count = reply_count + 1, bumped_at = ? WHERE id = ?");
        $stmtUpdate->execute([$time, $post_id]);
        $stmt->execute([$post_id]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        // Regenerate the main board pagination.
        generatePagination($pdo);
    }
}

// Fetch replies for this post in chronological order
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
  <!-- Link back to the main board -->
  <div style="margin:10px;">
    <a href="index.html">Return to Board</a>
  </div>
  
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
            <input type="submit" value="Submit Reply">
          </td>
        </tr>
      </table>
    </form>
  </div>
  <hr>
  
  <!-- Original Post -->
  <div class="op" style="background:#EEE; padding:10px; margin:10px;">
    <div class="post-header" style="overflow:hidden;">
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
  
  <!-- Replies -->
  <div style="margin:10px;">
    <h3>Replies:</h3>
    <?php foreach ($replies as $reply): ?>
      <div class="op" style="background:#F9F9F9; padding:10px; margin:10px;">
        <div class="post-header" style="overflow:hidden;">
          <div style="float:left;">
            <span class="postername"><?php echo htmlspecialchars($reply['name'] ?: 'Anonymous', ENT_QUOTES); ?></span>
          </div>
          <div style="float:right;">Reply <?php echo $reply['reply_number']; ?></div>
        </div>
        <div class="message"><?php echo nl2br(htmlspecialchars($reply['message'], ENT_QUOTES)); ?></div>
      </div>
      <hr>
    <?php endforeach; ?>
  </div>
  
  <script src="js/switchStylesheet.js"></script>
  <script src="js/toggleImage.js"></script>
</body>
</html>
