<?php
/**************************************************
 * common.php
 * -----------------------------------------------
 * This file contains functions shared by the board:
 *  - createThumbnail()
 *  - generatePagination()
 *
 * It is included by both post.php and reply.php.
 **************************************************/

/**
 * Create a thumbnail from an image.
 * Returns the destination path if successful, or an empty string otherwise.
 */
function createThumbnail($srcPath, $dstPath, $mime, $maxSize) {
    if (!preg_match('/^image\//', $mime)) { 
        return ''; 
    }
    switch ($mime) {
        case 'image/jpeg':
            $srcImg = imagecreatefromjpeg($srcPath);
            break;
        case 'image/png':
            $srcImg = imagecreatefrompng($srcPath);
            break;
        case 'image/gif':
            $srcImg = imagecreatefromgif($srcPath);
            break;
        case 'image/webp':
            $srcImg = imagecreatefromwebp($srcPath);
            break;
        default:
            return '';
    }
    if (!$srcImg) { 
        return ''; 
    }
    $origW = imagesx($srcImg);
    $origH = imagesy($srcImg);
    $ratio = min($maxSize / $origW, $maxSize / $origH, 1);
    $newW  = (int)($origW * $ratio);
    $newH  = (int)($origH * $ratio);
    $thumbImg = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($thumbImg, $dstPath, 85);
            break;
        case 'image/png':
            imagepng($thumbImg, $dstPath);
            break;
        case 'image/gif':
            imagegif($thumbImg, $dstPath);
            break;
        case 'image/webp':
            imagewebp($thumbImg, $dstPath, 85);
            break;
    }
    imagedestroy($srcImg);
    imagedestroy($thumbImg);
    return $dstPath;
}

/**
 * Regenerate static paginated HTML pages for the main board.
 *
 * $pdo: a valid PDO connection.
 * $postsPerPage: how many posts to show per page.
 */
function generatePagination($pdo, $postsPerPage = 20) {
    $stmt = $pdo->query("SELECT * FROM posts ORDER BY bumped_at DESC, id DESC");
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalPosts = count($posts);
    $totalPages = max(1, ceil($totalPosts / $postsPerPage));
    
    for ($page = 1; $page <= $totalPages; $page++) {
        $start = ($page - 1) * $postsPerPage;
        $pagePosts = array_slice($posts, $start, $postsPerPage);
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>PHPIB - Page <?php echo $page; ?></title>
  <link rel="shortcut icon" href="favicon.ico">
  <!-- Default stylesheet: Light theme -->
  <link id="stylesheet" rel="stylesheet" href="css/light.css">
</head>
<body>
  <!-- Admin Bar at the Top -->
  <div class="adminbar">
    [<a href="#" style="text-decoration: underline;">Manage</a>]
    <select id="switchStylesheet">
      <option value="css/light.css">Light</option>
      <option value="css/grey.css">Grey</option>
      <option value="css/dark.css">Dark</option>
    </select>
  </div>
  
  <!-- New Post Form -->
  <div class="postarea">
    <form name="postform" id="postform" action="post.php" method="post" enctype="multipart/form-data">
      <table>
        <tr>
          <td class="postblock">Name</td>
          <td><input type="text" name="name" size="28" maxlength="75"></td>
        </tr>
        <tr>
          <td class="postblock">Subject</td>
          <td>
            <input type="text" name="subject" size="40" maxlength="75">
            <input type="submit" value="Submit">
          </td>
        </tr>
        <tr>
          <td class="postblock">Message</td>
          <td>
            <textarea id="message" name="message" cols="48" rows="4" maxlength="8000"></textarea>
          </td>
        </tr>
        <tr>
          <td class="postblock">File</td>
          <td><input type="file" name="file" size="35"></td>
        </tr>
        <tr>
          <td colspan="2" class="rules">
            <ul>
              <li>Supported file types: JPG, PNG, GIF, WEBP, and MP4.</li>
              <li>Images greater than 250x250 will be thumbnailed.</li>
            </ul>
          </td>
        </tr>
      </table>
    </form>
  </div>
  <hr>
  <!-- Posts and Deletion Form -->
  <form id="deleteform" action="post.php" method="post">
    <div id="posts">
      <?php
      foreach ($pagePosts as $row) {
          $id       = $row['id'];
          $name     = htmlspecialchars($row['name'] ?: 'Anonymous', ENT_QUOTES);
          $subject  = htmlspecialchars($row['subject'], ENT_QUOTES);
          $message  = nl2br(htmlspecialchars($row['message'], ENT_QUOTES));
          $filepath = $row['filepath'];
          $thumbpath= $row['thumbpath'];
      
          echo '<div id="post'.$id.'" class="op">';
          echo '<input type="checkbox" name="delete[]" value="'.$id.'" class="delete-checkbox" style="float:left; margin-right:5px;">';
          echo '<div class="post-header" style="overflow:hidden;">';
          echo '<div style="float:left;">';
          if ($subject) { echo '<span class="filetitle">'.$subject.'</span> '; }
          echo '<span class="postername">'.$name.'</span>';
          echo '</div>';
          echo '<div style="float:right;"><a href="reply.php?post_id='.$id.'">[Reply-'.$row['reply_count'].']</a></div>';
          echo '</div>';
          if ($filepath && $thumbpath) {
              echo '<div class="thumb-container">';
              echo '<a href="'.$filepath.'" target="_blank">';
              echo '<img src="'.$thumbpath.'" alt="'.$id.'" class="thumb">';
              echo '</a>';
              echo '</div>';
          }
          echo '<div class="message">'.$message.'</div>';
          echo '</div>';
          echo '<hr>';
      }
      ?>
    </div>
    <div class="userdelete">
      Delete Post 
      <input type="password" name="password" size="8" placeholder="Password">
      <input name="deletepost" value="Delete" type="submit">
    </div>
  </form>
  <div class="pagination" style="text-align:center; margin:20px;">
    <?php if ($page > 1): ?>
      <a href="page<?php echo $page - 1; ?>.html">&laquo; Previous</a>
    <?php endif; ?>
    Page <?php echo $page; ?> of <?php echo $totalPages; ?>
    <?php if ($page < $totalPages): ?>
      <a href="page<?php echo $page + 1; ?>.html">Next &raquo;</a>
    <?php endif; ?>
  </div>
  <div class="footer">
    - <a href="#" target="_blank">com</a> + 
      <a href="#" target="_blank">net</a> + 
      <a href="#" target="_blank">org</a> -
  </div>
  <script src="js/switchStylesheet.js"></script>
  <script src="js/toggleImage.js"></script>
</body>
</html>
<?php
        $html = ob_get_clean();
        file_put_contents(__DIR__ . "/page{$page}.html", $html, LOCK_EX);
    }
    // Always copy the first page as index.html.
    copy(__DIR__ . "/page1.html", __DIR__ . "/index.html");
}
?>
