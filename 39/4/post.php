<?php
/**************************************************
 * post.php
 * -----------------------------------------------
 * A single-file script that:
 *   1) Handles new post submissions (including file upload)
 *   2) Handles deletion of posts (with a hard-coded admin password)
 *   3) Stores posts in a JSON flatfile (board.json)
 *   4) Regenerates a static index.html
 *   (Now using PHPIB with external CSS/JS, three themes, an inline lightbox,
 *    and a headless deletion interface with the admin bar at the top)
 **************************************************/

/**
 * Configuration
 */
$jsonFile      = __DIR__ . '/board.json';  // JSON file for posts
$uploadsDir    = __DIR__ . '/uploads';       // Directory for full files
$thumbsDir     = __DIR__ . '/thumb';         // Directory for thumbs
$maxThumbSize  = 250;                        // Max dimension for thumbs
$allowedTypes  = ['image/jpeg','image/png','image/gif','image/webp','video/mp4']; // Allowed MIME types
$adminPassword = "secret123";                // Hard-coded admin password for deletion

/**
 * Helper: Load posts from the JSON file
 */
function getPosts() {
    global $jsonFile;
    if (!file_exists($jsonFile)) {
        return [];
    }
    $data = file_get_contents($jsonFile);
    $posts = json_decode($data, true);
    return is_array($posts) ? $posts : [];
}

/**
 * Helper: Save posts array to the JSON file
 */
function savePosts($posts) {
    global $jsonFile;
    file_put_contents($jsonFile, json_encode($posts, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Helper: Generate a thumbnail for images.
 * Returns path to generated thumbnail or empty string if not an image.
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
    if (!$srcImg) { return ''; }
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
 * 1) Process deletion if a delete request was submitted.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deletepost'])) {
    if (isset($_POST['password']) && $_POST['password'] === $adminPassword && isset($_POST['delete'])) {
        $deleteIds = $_POST['delete'];
        $posts = getPosts();
        $newPosts = [];
        foreach ($posts as $post) {
            if (in_array($post['id'], $deleteIds)) {
                // Delete associated files if they exist.
                if (!empty($post['filepath']) && file_exists(__DIR__ . '/' . $post['filepath'])) {
                    unlink(__DIR__ . '/' . $post['filepath']);
                }
                if (!empty($post['thumbpath']) && file_exists(__DIR__ . '/' . $post['thumbpath'])) {
                    unlink(__DIR__ . '/' . $post['thumbpath']);
                }
                // Skip this post (i.e. delete it)
            } else {
                $newPosts[] = $post;
            }
        }
        savePosts($newPosts);
        generateIndex($newPosts);
    }
    header("Location: index.html");
    exit;
}

/**
 * 2) Process a new post submission if no deletion is being made.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['deletepost'])) {
    $name    = trim($_POST['name'] ?? 'Anonymous');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $time    = time();
    $filename  = '';
    $filepath  = '';
    $thumbpath = '';
    $mimeType  = '';

    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $tmpName  = $_FILES['file']['tmp_name'];
        $origName = basename($_FILES['file']['name']);
        $mimeType = mime_content_type($tmpName);
        if (in_array($mimeType, $allowedTypes)) {
            if (!is_dir($uploadsDir)) { mkdir($uploadsDir, 0777, true); }
            if (!is_dir($thumbsDir)) { mkdir($thumbsDir, 0777, true); }
            $uniqueName = time() . '_' . mt_rand(1000,9999) . '_' . $origName;
            $destPath   = $uploadsDir . '/' . $uniqueName;
            move_uploaded_file($tmpName, $destPath);
            $filename = $origName;
            $filepath = 'uploads/' . $uniqueName;
            if (preg_match('/^image\//', $mimeType)) {
                $thumbName     = 'thumb_' . $uniqueName;
                $thumbFullPath = $thumbsDir . '/' . $thumbName;
                $t = createThumbnail($destPath, $thumbFullPath, $mimeType, $maxThumbSize);
                if ($t) {
                    $thumbpath = 'thumb/' . $thumbName;
                }
            } elseif ($mimeType === 'video/mp4') {
                $thumbpath = 'thumb/video_placeholder.jpg';
            }
        }
    }
    $posts = getPosts();
    $maxId = 0;
    foreach ($posts as $p) {
        if ($p['id'] > $maxId) { $maxId = $p['id']; }
    }
    $newId = $maxId + 1;
    $newPost = [
        'id'         => $newId,
        'name'       => $name,
        'subject'    => $subject,
        'message'    => $message,
        'filename'   => $filename,
        'filepath'   => $filepath,
        'thumbpath'  => $thumbpath,
        'mimetype'   => $mimeType,
        'created_at' => $time
    ];
    $posts[] = $newPost;
    savePosts($posts);
    generateIndex($posts);
    header("Location: index.html");
    exit;
}

/**
 * 3) Generate index.html from the posts stored in the JSON file.
 * The posts listing (with deletion checkboxes) is wrapped in its own form.
 */
function generateIndex($posts) {
    usort($posts, function($a, $b) { return $b['id'] - $a['id']; });
    ob_start();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>PHPIB Example</title>
  <link rel="shortcut icon" href="favicon.ico">
  <!-- Load the default stylesheet (Light theme) -->
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
    foreach ($posts as $row) {
        $id       = $row['id'];
        $name     = htmlspecialchars($row['name'] ?: 'Anonymous', ENT_QUOTES);
        $subject  = htmlspecialchars($row['subject'], ENT_QUOTES);
        $message  = nl2br(htmlspecialchars($row['message'], ENT_QUOTES));
        $filepath = $row['filepath'];
        $thumbpath= $row['thumbpath'];

        echo '<div id="post'.$id.'" class="op">';
        // Deletion checkbox at top left of the post
        echo '<input type="checkbox" name="delete[]" value="'.$id.'" class="delete-checkbox" style="float:left; margin-right:5px;">';
        // Post header: Subject and Name
        echo '<div class="post-header">';
        if ($subject) {
            echo '<span class="filetitle">'.$subject.'</span> ';
        }
        echo '<span class="postername">'.$name.'</span>';
        echo '</div>';
        // Display image if available
        if ($filepath && $thumbpath) {
            echo '<div class="thumb-container">';
            echo '<a href="'.$filepath.'" target="_blank">';
            echo '<img src="'.$thumbpath.'" alt="'.$id.'" class="thumb">';
            echo '</a>';
            echo '</div>';
        }
        // Post message
        echo '<div class="message">'.$message.'</div>';
        echo '</div>';
        echo '<hr>';
    }
?>
    </div>
    <!-- Delete Post Controls -->
    <div class="userdelete">
      Delete Post 
      <input type="password" name="password" size="8" placeholder="Password">
      <input name="deletepost" value="Delete" type="submit">
    </div>
  </form>
  <br>
  <div class="footer">
    - <a href="#" target="_blank">com</a> + 
      <a href="#" target="_blank">net</a> + 
      <a href="#" target="_blank">org</a> -
  </div>
  <!-- Load JavaScript files -->
  <script src="js/switchStylesheet.js"></script>
  <script src="js/toggleImage.js"></script>
</body>
</html>
<?php
    $html = ob_get_clean();
    file_put_contents(__DIR__ . '/index.html', $html);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $posts = getPosts();
    generateIndex($posts);
    header("Location: index.html");
    exit;
}
?>
