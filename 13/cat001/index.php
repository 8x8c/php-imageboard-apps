<?php
// index.php - Displays threads and allows posting new threads

// Configuration
$db_file = __DIR__ . '/database.sqlite';
$upload_dir = __DIR__ . '/uploads/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Allowed file extensions
$allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'];

// Initialize DB
$db = new SQLite3($db_file);
$db->exec("CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER DEFAULT 0,
    name TEXT,
    subject TEXT,
    comment TEXT,
    image TEXT,
    datetime TEXT
)");

// Handle new thread submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? 'Anonymous');
    $subject = trim($_POST['subject'] ?? '');
    $comment = trim($_POST['body'] ?? '');
    $datetime = gmdate('Y-m-d\TH:i:s\Z');

    $image_path = '';
    if (isset($_FILES['file']) && $_FILES['file']['size'] > 0 && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed_exts)) {
            $filename = time() . '_' . mt_rand(1000,9999) . '.' . $ext;
            $target = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
                $image_path = $filename;
            }
        }
    }

    $stmt = $db->prepare("INSERT INTO posts (parent_id, name, subject, comment, image, datetime) VALUES (0, ?, ?, ?, ?, ?)");
    $stmt->bindValue(1, $name, SQLITE3_TEXT);
    $stmt->bindValue(2, $subject, SQLITE3_TEXT);
    $stmt->bindValue(3, $comment, SQLITE3_TEXT);
    $stmt->bindValue(4, $image_path, SQLITE3_TEXT);
    $stmt->bindValue(5, $datetime, SQLITE3_TEXT);
    $stmt->execute();

    // Redirect to avoid form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch threads (parent_id=0) ordered by datetime DESC
$results = $db->query("SELECT * FROM posts WHERE parent_id=0 ORDER BY datetime DESC");

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>/b/ - Random</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
    <link rel="stylesheet" title="default" href="css/style.css" type="text/css" media="screen">
    <link rel="stylesheet" title="style1" href="css/1.css" type="text/css" media="screen" disabled="disabled">
    <link rel="stylesheet" title="style2" href="css/2.css" type="text/css" media="screen" disabled="disabled">
    <link rel="stylesheet" title="style3" href="css/3.css" type="text/css" media="screen" disabled="disabled">
    <link rel="stylesheet" title="style4" href="css/4.css" type="text/css" media="screen" disabled="disabled">
    <link rel="stylesheet" title="style5" href="css/5.css" type="text/css" media="screen" disabled="disabled">
    <link rel="stylesheet" title="style6" href="css/6.css" type="text/css" media="screen" disabled="disabled">
    <link rel="stylesheet" title="style7" href="css/7.css" type="text/css" media="screen" disabled="disabled">
    <link rel="stylesheet" href="css/font-awesome/css/font-awesome.min.css">
   
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">

    <script type="text/javascript">
        var active_page = "index", board_name = "b";
        var configRoot="/";
        function setActiveStyleSheet(title) {
            var links = document.getElementsByTagName("link");
            for (var i = 0; i < links.length; i++) {
                var a = links[i];
                if(a.getAttribute("rel") && a.getAttribute("rel").indexOf("stylesheet") != -1 && a.getAttribute("title")) {
                    a.disabled = true;
                    if(a.getAttribute("title") == title) a.disabled = false;
                }
            }
            localStorage.setItem('selectedStyle', title);
        }

        window.addEventListener('load', function() {
            var savedStyle = localStorage.getItem('selectedStyle');
            if(savedStyle) {
                setActiveStyleSheet(savedStyle);
            }
        });
    </script>

    <!-- Load jQuery first -->
    <script type="text/javascript" src="js/jquery.min.js"></script>
    <!-- Then other JS files that depend on jQuery -->
    <script type="text/javascript" src="js/main.js"></script>
    <script type="text/javascript" src="js/inline-expanding.js"></script>
    <script type="text/javascript" src="js/hide-form.js"></script>
	
</head>
<body class="visitor is-not-moderator active-index" data-stylesheet="default">

<!-- Style Selector -->
<div id="style-selector" style="position:absolute; top:10px; left:10px; background:#eee; padding:5px; border:1px solid #ccc;">
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

<br>
<br>
<header><h1>/b/ - Random</h1><div class="subtitle"></div></header>

<!-- Post Form -->
<form name="post" onsubmit="return true;" enctype="multipart/form-data" action="" method="post">
    <table>
        <tr>
            <th>Name</th>
            <td><input type="text" name="name" size="25" maxlength="35" autocomplete="off"></td>
        </tr>
        <tr>
            <th>Subject</th>
            <td>
                <input style="float:left;" type="text" name="subject" size="25" maxlength="100" autocomplete="off">
                <input accesskey="s" style="margin-left:2px;" type="submit" name="post" value="New Topic" />
            </td>
        </tr>
        <tr>
            <th>Comment</th>
            <td><textarea name="body" id="body" rows="5" cols="35"></textarea></td>
        </tr>
        <tr id="upload">
            <th>File</th>
            <td><input type="file" name="file" id="upload_file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4"></td>
        </tr>
    </table>
    <input type="hidden" name="hash" value="dummyhash">
</form>
<hr />

<form name="postcontrols" action="" method="post">
<input type="hidden" name="board" value="b" />

<?php while ($post = $results->fetchArray(SQLITE3_ASSOC)): ?>
<?php
    $id = $post['id'];
    $name = htmlspecialchars($post['name'], ENT_QUOTES, 'UTF-8');
    $subject = htmlspecialchars($post['subject'], ENT_QUOTES, 'UTF-8');
    $comment = nl2br(htmlspecialchars($post['comment'], ENT_QUOTES, 'UTF-8'));
    $datetime = $post['datetime'];

    // Count replies for this thread
    $count_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM posts WHERE parent_id = ?");
    $count_stmt->bindValue(1, $id, SQLITE3_INTEGER);
    $count_res = $count_stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $reply_count = $count_res['cnt'] ?? 0;

    // Format datetime
    $ts = strtotime($datetime);
    $date_str = gmdate('m/d/y (D) H:i:s', $ts);

    $image_html = '';
    if ($post['image']) {
        $img_path = 'uploads/' . $post['image'];
        $image_ext = strtolower(pathinfo($img_path, PATHINFO_EXTENSION));

        if (in_array($image_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $image_html = '
            <div class="files">
                <div class="file">
                    <p class="fileinfo">File: <a href="'.$img_path.'">'.basename($post['image']).'</a></p>
                    <a href="'.$img_path.'" target="_blank"><img class="post-image" src="'.$img_path.'" style="width:255px;height:auto" alt="" /></a>
                </div>
            </div>';
        } elseif ($image_ext === 'mp4') {
            $image_html = '
            <div class="files">
                <div class="file">
                    <p class="fileinfo">File: <a href="'.$img_path.'">'.basename($post['image']).'</a></p>
                    <video width="255" controls>
                        <source src="'.$img_path.'" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                </div>
            </div>';
        }
    }

    $reply_link_text = $reply_count > 0 ? "Reply[".$reply_count."]" : "Reply";
?>
<div class="thread" id="thread_<?php echo $id; ?>" data-board="b">
    <?php echo $image_html; ?>
    <div class="post op" id="op_<?php echo $id; ?>">
        <p class="intro">
            <input type="checkbox" class="delete" name="delete_<?php echo $id; ?>" id="delete_<?php echo $id; ?>" />
            <label for="delete_<?php echo $id; ?>">
                <?php if (!empty($subject)): ?><span class="subject"><?php echo $subject; ?></span> <?php endif; ?>
                <span class="name"><?php echo $name; ?></span>
                <time datetime="<?php echo $datetime; ?>"><?php echo $date_str; ?></time>
            </label>&nbsp;
            <a class="post_no" id="post_no_<?php echo $id; ?>" href="#<?php echo $id; ?>">No.</a>
            <a class="post_no" href="#q<?php echo $id; ?>"><?php echo $id; ?></a>
            <a href="reply.php?thread_id=<?php echo $id; ?>"><?php echo $reply_link_text; ?></a>
        </p>
        <div class="body"><?php echo $comment; ?></div>
    </div>
    <br class="clear"/>
    <hr/>
</div>
<?php endwhile; ?>

<div id="post-moderation-fields">
    <div id="report-fields">
        <label for="reason">Reason</label> <input id="reason" type="text" name="reason" size="20" maxlength="30" /><input type="submit" name="report" value="Report" />
    </div>
</div>
</form>

<div class="pages">Previous [<a class="selected">1</a>] Next | </div>

<footer>
    <p class="unimportant" style="margin-top:20px;text-align:center;">
        All trademarks, copyrights, comments, and images on this page are 
        owned by and are the responsibility of their respective parties.
    </p>
</footer>

<script type="text/javascript">ready();</script>
</body>
</html>
