<?php
declare(strict_types=1);

/**
 * post.php
 * -----------------------------------------------
 * A single-file script that:
 *   1) Stores top-level posts (name, subject, body, optional file)
 *   2) Uses a single "posts" table (no replies)
 *   3) Generates a static index.html in Futaba style
 *   4) Supports pagination
 *   5) No reply/shoutbox logic
 */

// --------------------------------------------------
// 0) ERROR LOGGING & DEBUG SETTINGS
// --------------------------------------------------
ini_set('display_errors', '1');         // Show errors in browser (remove in production)
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.txt');

// Convert PHP errors to exceptions (for easier debugging)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
set_exception_handler(function($ex) {
    error_log('Uncaught exception: ' . $ex->getMessage() . 
              " in " . $ex->getFile() . " on line " . $ex->getLine());
    echo "<pre style='color:red;'>Uncaught exception: " . 
         htmlentities($ex->getMessage()) . "</pre>";
    exit;
});

// --------------------------------------------------
// 1) CONFIG
// --------------------------------------------------
const DB_FILE         = __DIR__ . '/board.db'; // Single DB for posts
const UPLOADS_SUBDIR  = 'uploads';             // Folder name for user uploads
const ALLOWED_MIME    = ['image/jpeg','image/png','image/gif','image/webp','video/mp4'];
const MAX_UPLOAD_SIZE = 5_000_000;             // 5 MB limit
const POSTS_PER_PAGE  = 5;                     // how many posts per page

// --------------------------------------------------
// 2) INIT: DB + TABLE
// --------------------------------------------------
$pdo = new PDO('sqlite:' . DB_FILE, '', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

// Create "posts" table if not exist
$pdo->exec("
CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    file_path TEXT,
    mime_type TEXT,
    created_at INTEGER NOT NULL
)
");

if (!is_dir(__DIR__ . '/' . UPLOADS_SUBDIR)) {
    mkdir(__DIR__ . '/' . UPLOADS_SUBDIR, 0777, true);
}

// --------------------------------------------------
// 3) HANDLE POST (new top-level post only)
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handleNewPost($pdo);
    generateIndex($pdo);
    header("Location: index.html");
    exit;
}

// --------------------------------------------------
// 4) generateIndex($pdo)
//    Overwrites index.html in a Futaba-like style
//    Only shows top-level posts (no replies).
// --------------------------------------------------
function generateIndex(PDO $pdo, int $page = 1): void
{
    // Pagination logic
    $totalPosts = (int)$pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    $pageCount  = (int)ceil($totalPosts / POSTS_PER_PAGE);

    if ($page < 1) {
        $page = 1;
    } elseif ($page > $pageCount && $pageCount > 0) {
        $page = $pageCount;
    }
    $offset = ($page - 1) * POSTS_PER_PAGE;

    // Fetch top-level posts
    $stmt = $pdo->prepare("
      SELECT * FROM posts
      ORDER BY id DESC
      LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit',  POSTS_PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,        PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();

    // Build the static HTML
    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>/a/ - Random</title>
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
<link rel="stylesheet" title="default" href="/css/style.css" type="text/css" media="screen">
<link rel="stylesheet" title="style1" href="/css/1.css" type="text/css" media="screen" disabled="disabled">
<link rel="stylesheet" title="style2" href="/css/2.css" type="text/css" media="screen" disabled="disabled">
<link rel="stylesheet" title="style3" href="/css/3.css" type="text/css" media="screen" disabled="disabled">
<link rel="stylesheet" title="style4" href="/css/4.css" type="text/css" media="screen" disabled="disabled">
<link rel="stylesheet" title="style5" href="/css/5.css" type="text/css" media="screen" disabled="disabled">
<link rel="stylesheet" title="style6" href="/css/6.css" type="text/css" media="screen" disabled="disabled">
<link rel="stylesheet" title="style7" href="/css/7.css" type="text/css" media="screen" disabled="disabled">
<link rel="stylesheet" href="/css/font-awesome/css/font-awesome.min.css">

<script type="text/javascript">
    const active_page = "index";
    const board_name = "a";

    function setActiveStyleSheet(title) {
        const links = document.getElementsByTagName("link");
        for (let i = 0; i < links.length; i++) {
            const a = links[i];
            if(a.getAttribute("rel") 
               && a.getAttribute("rel").indexOf("stylesheet") !== -1 
               && a.getAttribute("title")) {
                a.disabled = true;
                if(a.getAttribute("title") === title) {
                    a.disabled = false;
                }
            }
        }
        localStorage.setItem('selectedStyle', title);
    }

    window.addEventListener('load', () => {
        const savedStyle = localStorage.getItem('selectedStyle');
        if(savedStyle) {
            setActiveStyleSheet(savedStyle);
        }
    });
</script>
<script type="text/javascript" src="/js/jquery.min.js"></script>
<script type="text/javascript" src="/js/main.js"></script>
<script type="text/javascript" src="/js/inline-expanding.js"></script>
<script type="text/javascript" src="/js/hide-form.js"></script>
</head>
<body class="visitor is-not-moderator active-index" data-stylesheet="default">
<header><h1>/a/ - Random</h1><div class="subtitle"></div></header>

<!-- New Post Form -->
<form name="post" enctype="multipart/form-data" action="post.php" method="post">
    <table>
        <tr><th>Name</th>
            <td><input type="text" name="name" size="25" maxlength="35" required></td>
        </tr>
        <tr><th>Subject</th>
            <td>
              <input type="text" name="subject" size="25" maxlength="100" required>
              <input type="submit" value="New Topic" style="margin-left:2px;">
            </td>
        </tr>
        <tr><th>Comment</th>
            <td><textarea name="body" rows="5" cols="35" required></textarea></td>
        </tr>
        <tr id="upload"><th>File</th>
            <td><input type="file" name="file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4"></td>
        </tr>
    </table>
</form>
<hr />

<!-- Display All Posts (No replies) -->
<?php foreach($posts as $post): ?>
  <div class="thread" id="post_<?=$post['id']?>" data-board="a">
    <?php if($post['file_path']): ?>
    <div class="files">
      <div class="file">
        <?php if(str_starts_with($post['mime_type'] ?? '', 'video')): ?>
          <video class="post-video" controls>
            <source src="<?=$post['file_path']?>" type="<?=$post['mime_type']?>">
            Your browser does not support the video tag.
          </video>
        <?php else: ?>
          <a href="<?=$post['file_path']?>" target="_blank">
            <img class="post-image" src="<?=$post['file_path']?>" alt="">
          </a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="post op" id="op_<?=$post['id']?>">
      <p class="intro">
        <span class="subject"><?=htmlspecialchars($post['subject'], ENT_QUOTES)?></span>
        <span class="name"><?=htmlspecialchars($post['name'], ENT_QUOTES)?></span>
      </p>
      <div class="body"><?=nl2br(htmlspecialchars($post['body'], ENT_QUOTES))?></div>
    </div>
    <br class="clear"/>
    <hr/>
  </div>
<?php endforeach; ?>

<!-- Pagination -->
<div class="pagination">
<?php
if ($pageCount <= 1) {
    echo "<strong>1</strong>";
} else {
    for($i=1; $i<=$pageCount; $i++) {
        if ($i === $page) {
            echo "<strong>$i</strong> ";
        } else {
            echo "<a href='post.php?page=$i'>$i</a> ";
        }
    }
}
?>
</div>

<footer>
    <div id="style-selector">
        <label for="style_select">Style:</label>
        <select id="style_select" onchange="setActiveStyleSheet(this.value)">
            <option value="default">default</option>
            <option value="style1">style1</option>
            <option value="style2">style2</option>
            <option value="style3">style3</option>
            <option value="style4">style4</option>
            <option value="style5">style5</option>
            <option value="style6">style6</option>
            <option value="style7">style7</option>
        </select>
    </div>

    <p class="unimportant">
        All trademarks, copyrights,
        comments, and images on this page are owned by and are
        the responsibility of their respective parties.
    </p>
</footer>
<div id="home-button">
    <a href="/">Home</a>
</div>
<script type="text/javascript">ready();</script>
</body>
</html>
    <?php
    // Save the HTML to index.html
    $html = ob_get_clean();
    file_put_contents(__DIR__ . '/index.html', $html);
}

// --------------------------------------------------
// 5) handleNewPost($pdo)
// --------------------------------------------------
function handleNewPost(PDO $pdo): void
{
    $name    = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body'] ?? '');
    $time    = time();

    if ($name === '' || $subject === '' || $body === '') {
        throw new RuntimeException("Name, Subject, and Comment are required.");
    }

    $filePath = null;
    $mimeType = null;

    // Optional file upload
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['file']['size'] > MAX_UPLOAD_SIZE) {
            throw new RuntimeException("Uploaded file exceeds 5 MB limit.");
        }
        $tmpName     = $_FILES['file']['tmp_name'];
        $detectedMime= mime_content_type($tmpName);
        if (!in_array($detectedMime, ALLOWED_MIME, true)) {
            throw new RuntimeException("File type not allowed: " . $detectedMime);
        }

        $ext       = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $uniqueName= time() . '_' . random_int(1000,9999) . '.' . $ext;
        $uploadDir = __DIR__ . '/' . UPLOADS_SUBDIR;

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $destPath = $uploadDir . '/' . $uniqueName;
        move_uploaded_file($tmpName, $destPath);

        // Example: "/kingsgambit/uploads/filename"
        // If your folder is /3/kingsgambit, adjust accordingly
        $filePath = '/' . basename(__DIR__) . '/' . UPLOADS_SUBDIR . '/' . $uniqueName;
        $mimeType = $detectedMime;
    }

    // Insert new post
    $stmt = $pdo->prepare("
        INSERT INTO posts (name, subject, body, file_path, mime_type, created_at)
        VALUES (:name, :subject, :body, :file_path, :mime_type, :created_at)
    ");
    $stmt->execute([
        ':name'       => $name,
        ':subject'    => $subject,
        ':body'       => $body,
        ':file_path'  => $filePath,
        ':mime_type'  => $mimeType,
        ':created_at' => $time
    ]);
}

// --------------------------------------------------
// 6) GET => generate index + redirect
// --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    generateIndex($pdo, $page);
    header("Location: index.html");
    exit;
}
