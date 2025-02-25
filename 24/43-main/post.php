<?php
/**************************************************
 * post.php
 * -----------------------------------------------
 * A single-file script that:
 *   1) Handles new post submissions (including file upload)
 *   2) Stores posts in an SQLite3 database
 *   3) Regenerates a static index.html
 **************************************************/

/**
 * Configuration
 */
$dbFile       = __DIR__ . '/board.db';    // SQLite file
$uploadsDir   = __DIR__ . '/uploads';     // Directory for full files
$thumbsDir    = __DIR__ . '/thumb';       // Directory for thumbs
$maxThumbSize = 250;                      // Max dimension for thumbs
$allowedTypes = ['image/jpeg','image/png','image/gif','image/webp','video/mp4']; // Allowed MIME types

/**
 * 1) Initialize database
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
 * Helper: Generate a thumbnail for images
 * Returns path to generated thumbnail or empty if not an image
 */
function createThumbnail($srcPath, $dstPath, $mime, $maxSize) {
    // If it's not an image, skip
    if (!preg_match('/^image\//', $mime)) {
        return '';
    }
    
    // Create image resource from the original
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

    // Get original dimensions
    $origW = imagesx($srcImg);
    $origH = imagesy($srcImg);

    // Calculate new dimensions, preserving aspect ratio
    $ratio = min($maxSize / $origW, $maxSize / $origH, 1);
    $newW  = (int)($origW * $ratio);
    $newH  = (int)($origH * $ratio);

    // Create thumbnail image
    $thumbImg = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($thumbImg, $srcImg, 0,0,0,0, $newW, $newH, $origW, $origH);

    // Save thumbnail
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
 * 2) Handle a new post (if method is POST)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? 'Anonymous');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $time    = time();

    $filename  = '';
    $filepath  = '';
    $thumbpath = '';
    $mimeType  = '';

    // Handle file upload if any
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $tmpName  = $_FILES['file']['tmp_name'];
        $origName = basename($_FILES['file']['name']);
        $mimeType = mime_content_type($tmpName);

        // Check if allowed type
        if (in_array($mimeType, $allowedTypes)) {
            // Move file into uploads/ with a unique name
            $uniqueName = time() . '_' . mt_rand(1000,9999) . '_' . $origName;
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

            // Create thumbnail if image, or use a placeholder for video
            if (preg_match('/^image\//', $mimeType)) {
                // create actual image thumb
                $thumbName  = 'thumb_' . $uniqueName;
                $thumbFullPath = $thumbsDir . '/' . $thumbName;
                $t = createThumbnail($destPath, $thumbFullPath, $mimeType, $maxThumbSize);

                if ($t) {
                    $thumbpath = 'thumb/' . $thumbName;
                }
            } elseif ($mimeType === 'video/mp4') {
                // Use a placeholder or do real thumbnail with ffmpeg
                $thumbpath = 'thumb/video_placeholder.jpg'; 
                // Make sure you have an actual placeholder file if needed
            }
        }
    }

    // Insert into DB
    $stmt = $db->prepare("INSERT INTO posts
        (name, subject, message, filename, filepath, thumbpath, mimetype, created_at)
        VALUES (:name, :subject, :message, :filename, :filepath, :thumbpath, :mimetype, :created_at)");
    $stmt->bindValue(':name',      $name,    SQLITE3_TEXT);
    $stmt->bindValue(':subject',   $subject, SQLITE3_TEXT);
    $stmt->bindValue(':message',   $message, SQLITE3_TEXT);
    $stmt->bindValue(':filename',  $filename,SQLITE3_TEXT);
    $stmt->bindValue(':filepath',  $filepath,SQLITE3_TEXT);
    $stmt->bindValue(':thumbpath', $thumbpath,SQLITE3_TEXT);
    $stmt->bindValue(':mimetype',  $mimeType,SQLITE3_TEXT);
    $stmt->bindValue(':created_at',$time,    SQLITE3_INTEGER);
    $stmt->execute();

    // After saving, regenerate index.html
    generateIndex($db);

    // Redirect back to index.html (optional)
    header("Location: index.html");
    exit;
}

/**
 * 3) Generate index.html from DB
 */
function generateIndex($db) {
    // Pull out all posts (descending)
    $result = $db->query("SELECT * FROM posts ORDER BY id DESC");

    // Begin HTML
    ob_start();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>TinyIB Example</title>
  <link rel="shortcut icon" href="favicon.ico">
  <style>
    /* -- Global CSS (merged for demonstration) -- */
    body {
      padding: 8px;
      margin: 0 0 auto;
      background: #FFFFEE;
      color: #800000;
      font-family: sans-serif;
    }
    hr {
      clear: both;
      border: 0 none;
      height: 1px;
      color: #800000;
      background-color: #800000;
      background-image: linear-gradient(
        to right,
        rgba(255,255,238,1),
        rgba(128,0,0,0.75),
        rgba(255,255,238,1)
      );
    }
    .adminbar {
      text-align: right;
      clear: both;
      float: right;
    }
    .logo {
      clear: both;
      text-align: center;
      font-size: 2em;
      color: #800000;
      width: 100%;
    }
    .postarea {
      text-align: center;
    }
    .postarea table {
      margin: 0 auto;
      text-align: left;
    }
    .postblock {
      background: #EEAA88;
      color: #800000;
      font-weight: 800;
      vertical-align: top;
      padding: 3px;
    }
    .rules {
      padding-left: 5px;
      width: 468px;
      font-size: 10px;
      font-family: sans-serif;
    }
    .rules ul {
      margin: 0;
      padding-left: 0;
    }
    .footer {
      clear: both;
      text-align: center;
      font-size: 12px;
      font-family: serif;
    }
    .thumb {
      border: none;
      float: left;
      margin: 4px 20px;
      max-width: 250px;
      max-height: 250px;
    }
    .message {
      margin: 1em 25px;
    }
    .reflink a {
      color: inherit;
      text-decoration: none;
    }
    .reflink a:hover {
      color: #800000;
      font-weight: bold;
    }
    a {
      color: #0000EE;
      text-decoration: none;
    }
    a:hover {
      color: #DD0000;
    }
    .op {
      margin-bottom: 1em;
      padding: 5px;
      background: #F0E0D6;
      border: 1px solid #EEAA88;
    }
    .filesize {
      font-size: 0.9em;
      color: #800000;
      margin-left: 20px;
    }
    .filetitle {
      color: #CC1105;
      font-weight: 800;
      font-size: 1.2em;
    }
    .postername {
      color: #117743;
      font-weight: bold;
    }
    .adminbar select {
      margin-left: 5px;
    }
    .userdelete {
      float: right;
      text-align: center;
      white-space: nowrap;
    }
  </style>
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
              <li>Supported file types are JPG, PNG, GIF, WEBP, and MP4.</li>
              <li>Images greater than 250x250 will be thumbnailed.</li>
            </ul>
          </td>
        </tr>
      </table>
    </form>
  </div>
  <hr>

  <div id="posts">
<?php
    // Loop through each post
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
            echo '<a href="'.$filepath.'" target="_blank">'.$filename.'</a>';
            echo '</span><br>';

            // If we have a thumb, show it
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

// If a GET request hits post.php directly without posting, optionally generate index
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    generateIndex($db);
    // Then redirect to index.html
    header("Location: index.html");
    exit;
}

?>
