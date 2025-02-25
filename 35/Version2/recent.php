<?php
declare(strict_types=1);
// recent.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

use PDO;

$db = init_db($db_host, $db_port, $db_name, $db_user, $db_pass);
$limit = 50; // Show last 50 posts

$stmt = $db->prepare("
    SELECT 
        p.id, 
        p.board_id, 
        p.name, 
        p.subject, 
        p.comment, 
        p.datetime, 
        b.name AS board_name,
        CASE WHEN p.parent_id = 0 THEN p.id ELSE p.parent_id END AS thread_id
    FROM posts p
    JOIN boards b ON p.board_id = b.id
    WHERE p.deleted = false
    ORDER BY p.datetime DESC
    LIMIT :limit
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Recent Posts</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/css/style.css" type="text/css" media="screen">
    <style>
        body { font-family: sans-serif; background: #f0f0f0; padding: 20px; }
        h1 { font-size: 1.5em; margin-bottom: 1em; }
        table.recent-posts { border-collapse: collapse; width: 100%; background: #fff; }
        table.recent-posts th, table.recent-posts td {
            border: 1px solid #ccc; padding: 10px; vertical-align: top;
        }
        table.recent-posts th { background: #eee; }
        .comment-snippet { color: #555; }
    </style>
</head>
<body>
<h1>Recent Posts (Last <?php echo (int)$limit; ?>)</h1>
<p>Below are the most recent posts from all boards. Click a link to visit the thread page.</p>

<table class="recent-posts">
    <tr>
        <th>Board</th>
        <th>Name</th>
        <th>Subject</th>
        <th>Comment Snippet</th>
        <th>Date/Time</th>
        <th>Link</th>
    </tr>
    <?php if (!$posts): ?>
        <tr><td colspan="6">No recent posts found.</td></tr>
    <?php else: ?>
        <?php foreach ($posts as $post): ?>
            <?php
            $name    = htmlspecialchars($post['name'] ?? '', ENT_QUOTES);
            $subject = htmlspecialchars($post['subject'] ?? '', ENT_QUOTES);
            $comment = htmlspecialchars($post['comment'] ?? '', ENT_QUOTES);
            $snippet = mb_substr($comment, 0, 50) . (mb_strlen($comment) > 50 ? '...' : '');
            $bname   = htmlspecialchars($post['board_name'] ?? '', ENT_QUOTES);
            $tid     = (int)($post['thread_id'] ?? 0);
            $pid     = (int)($post['id'] ?? 0);
            $dt      = htmlspecialchars($post['datetime'] ?? '', ENT_QUOTES);

            $thread_link = '/' . $bname . '/threads/thread_' . $tid . '.html#' . $pid;
            ?>
            <tr>
                <td>/<?php echo $bname; ?>/</td>
                <td><?php echo $name; ?></td>
                <td><?php echo $subject; ?></td>
                <td class="comment-snippet"><?php echo $snippet; ?></td>
                <td><?php echo $dt; ?></td>
                <td><a href="<?php echo $thread_link; ?>" target="_blank">View Thread</a></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</table>

<p style="margin-top:20px;">
    <a href="/">Return to Home</a>
</p>
</body>
</html>
