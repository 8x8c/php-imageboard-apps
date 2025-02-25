<?php
/**
 * post.php
 *
 * Logs all PHP errors to "error.txt" in the same directory.
 * Then handles imageboard post submission, generates index.html, etc.
 */

/**************************************************
 * 1) PHP Error Logging to error.txt
 **************************************************/
// Show no errors in the browser (optional)
ini_set('display_errors', 0);

// Log errors to a file instead
ini_set('log_errors', 1);

// Set error_log path to this directory
ini_set('error_log', __DIR__ . '/error.txt');

// Ensure we log all possible errors
error_reporting(E_ALL);

// Optional: Convert all PHP errors to exceptions, so you can catch them
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
set_exception_handler(function($ex) {
    error_log("Uncaught exception: " . $ex->getMessage());
    // You could optionally print a user-friendly message or handle differently here
});

/**************************************************
 * 2) Basic Configuration
 **************************************************/
$dbFile       = __DIR__ . '/board.db';    // SQLite file
$uploadsDir   = __DIR__ . '/uploads';     // Directory for full files
$thumbsDir    = __DIR__ . '/thumb';       // Directory for thumbs
$maxThumbSize = 250;                      // Max dimension for thumbs
$allowedTypes = ['image/jpeg','image/png','image/gif','image/webp','video/mp4']; // Allowed MIME types

/**
 * Create/open SQLite3 DB
 */
$db = new SQLite3($dbFile);
$db->exec("CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,
    subject TEXT,
    message TEXT,
    filename TEXT,
    filepath TEXT,
    thumbpath TEXT,
    mimetype TEXT,
    created_at INTEGER
)");

/**
 * Thumbnail Creation Helper
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
    imagecopyresampled($thumbImg, $srcImg, 0,0,0,0, $newW, $newH, $origW, $origH);

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

/**************************************************
 * 3) Handle New Post Submissions
 **************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? 'Anonymous');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $time    = time();

    $filename  = '';
    $filepath  = '';
    $thumbpath = '';
    $mimeType  = '';

    // File Upload
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $tmpName  = $_FILES['file']['tmp_name'];
        $origName = basename($_FILES['file']['name']);
        $mimeType = mime_content_type($tmpName);

        if (in_array($mimeType, $allowedTypes)) {
            $uniqueName = time() . '_' . mt_rand(1000, 9999) . '_' . $origName;
            $destPath   = $uploadsDir . '/' . $uniqueName;

            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0777, true);
            }
            if (!is_dir($thumbsDir)) {
                mkdir($thumbsDir, 0777, true);
            }

            move_uploaded_file($tmpName, $destPath);

            $filename = $origName;
            $filepath = 'uploads/' . $uniqueName;

            // Create thumbnail if image
            if (preg_match('/^image\//', $mimeType)) {
                $thumbName     = 'thumb_' . $uniqueName;
                $thumbFullPath = $thumbsDir . '/' . $thumbName;
                $t = createThumbnail($destPath, $thumbFullPath, $mimeType, $maxThumbSize);

                if ($t) {
                    $thumbpath = 'thumb/' . $thumbName;
                }
            } elseif ($mimeType === 'video/mp4') {
                // Placeholder or real thumbnail with ffmpeg
                $thumbpath = 'thumb/video_placeholder.jpg';
            }
        }
    }

    // Insert post into DB
    $stmt = $db->prepare("INSERT INTO posts
        (name, subject, message, filename, filepath, thumbpath, mimetype, created_at)
        VALUES (:name, :subject, :message, :filename, :filepath, :thumbpath, :mimetype, :created_at)");
    $stmt->bindValue(':name',      $name,    SQLITE3_TEXT);
    $stmt->bindValue(':subject',   $subject, SQLITE3_TEXT);
    $stmt->bindValue(':message',   $message, SQLITE3_TEXT);
    $stmt->bindValue(':filename',  $filename, SQLITE3_TEXT);
    $stmt->bindValue(':filepath',  $filepath, SQLITE3_TEXT);
    $stmt->bindValue(':thumbpath', $thumbpath, SQLITE3_TEXT);
    $stmt->bindValue(':mimetype',  $mimeType, SQLITE3_TEXT);
    $stmt->bindValue(':created_at',$time,    SQLITE3_INTEGER);
    $stmt->execute();

    // Regenerate index.html
    generateIndex($db);

    // Redirect back to index.html
    header("Location: index.html");
    exit;
}

/**************************************************
 * 4) Generate Static index.html
 **************************************************/
function generateIndex($db) {
    $result = $db->query("SELECT * FROM posts ORDER BY id DESC");

    ob_start();
    ?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>TinyIB Example</title>
  <link rel="shortcut icon" href="favicon.ico">
  <!-- Reference external CSS -->
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <div class="adminbar">
    [<a href="#" style="text-decoration: underline;">Manage</a>]
    <select id="switchStylesheet">
      <option value="">Style</option>
      <option value="futaba">Futaba</option>
      <option value="burichan">Burichan</option>
    </select>
  </div>
  <div class="logo">TinyIB Example</div>
  <hr width="90%">

  <div class="postarea">
    <form name="postform" id="postform" action="post.php" method="post" enctype="multipart/form-data">
      <input type="hidden" name="parent" value="0">
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
              <li>Supported file types: JPG, PNG, GIF, WEBP, MP4.</li>
              <li>Images larger than 250×250 are thumbnailed.</li>
            </ul>
          </td>
        </tr>
      </table>
    </form>
  </div>
  <hr>

  <div id="posts">
    <?php
      while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
          $id       = $row['id'];
          $name     = htmlspecialchars($row['name'] ?: 'Anonymous', ENT_QUOTES);
          $subject  = htmlspecialchars($row['subject'], ENT_QUOTES);
          $message  = nl2br(htmlspecialchars($row['message'], ENT_QUOTES));
          $filename = htmlspecialchars($row['filename'], ENT_QUOTES);
          $filepath = $row['filepath'];
          $thumbpath= $row['thumbpath'];
          $mime     = $row['mimetype'];

          echo '<div id="post'.$id.'" class="op">';
          if ($filepath) {
              // Show file info
              echo '<span class="filesize">File: ';
              echo '<a href="'.$filepath.'" target="_blank">'. $filename .'</a>';
              echo '</span><br>';

              // Show thumbnail if we have one
              if ($thumbpath) {
                  echo '<a href="'.$filepath.'" target="_blank">';
                  echo '<img src="'.$thumbpath.'" alt="'.$id.'" class="thumb">';
                  echo '</a>';
              }
          }
          echo '<br><label>';
          echo '<input type="checkbox" name="delete[]" value="'.$id.'"> ';
          if ($subject) {
              echo '<span class="filetitle">'.$subject.'</span> ';
          }
          echo '<span class="postername">'.$name.'</span>';
          echo '</label> ';
          echo '<span class="reflink">';
          echo '<a href="#'.$id.'">No.</a><a href="#q'.$id.'">'.$id.'</a>';
          echo '</span>';
          echo '<div class="message">'.$message.'</div>';
          echo '</div>';
          echo '<hr>';
      }
    ?>
  </div>

  <table class="userdelete">
    <tr>
      <td>
        Delete Post <input type="password" name="password" size="8" placeholder="Password">
        <input name="deletepost" value="Delete" type="submit">
      </td>
    </tr>
  </table>

  <br>
  <div class="footer">
    - <a href="https://4chess.com" target="_blank">com</a> +
      <a href="https://4chess.net" target="_blank">net</a> +
      <a href="https://4chess.com" target="_blank">org</a> -
  </div>
</body>
</html>
    <?php
    $html = ob_get_clean();
    file_put_contents(__DIR__ . '/index.html', $html);
}

/**************************************************
 * 5) GET Request
 **************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    generateIndex($db);
    header("Location: index.html");
    exit;
}
?>
