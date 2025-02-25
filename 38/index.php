<?php
declare(strict_types=1);
session_start();
error_reporting(E_ALL);

// ----- Config Start -----
define('CLAIRE_TEXTMODE', false); // if true, disallow images.
define('TINYIB_PAGETITLE', 'Claire Imageboard'); // Change as needed.
define('TINYIB_THREADSPERPAGE', 8);
define('TINYIB_REPLIESTOSHOW', 3);
define('TINYIB_MAXTHREADS', 0); // 0 disables automatic trimming of old threads
define('TINYIB_MAXPOSTSIZE', 16000); // maximum characters allowed
define('TINYIB_RATELIMIT', 7);   // seconds between posts from same IP
define('TINYIB_TRIPSEED', "1231");
define('TINYIB_USECAPTCHA', true); // enable captcha verification
define('TINYIB_CAPTCHASALT', 'CAPTCHASALT');
define('TINYIB_THUMBWIDTH', 200);
define('TINYIB_THUMBHEIGHT', 300);
define('TINYIB_REPLYWIDTH', 200);
define('TINYIB_REPLYHEIGHT', 300);
define('TINYIB_TIMEZONE', ''); // leave empty to use server default
define('TINYIB_DATEFORMAT', 'D Y-m-d g:ia');
define('TINYIB_DBPOSTS', 'posts');
define('TINYIB_DBPATH', __DIR__ . '/database.db');
// ----- Config End -----

// Create upload directory if not exists.
if (!file_exists(__DIR__ . '/db')) {
    mkdir(__DIR__ . '/db', 0777, true);
}

// ----- HTML Helper Functions -----
function pageHeader(): string {
    $page_title = TINYIB_PAGETITLE;
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{$page_title}</title>
    <meta http-equiv="pragma" content="no-cache">
    <meta http-equiv="expires" content="-1">
    <link rel="stylesheet" type="text/css" href="style.css" />
    <script>
    // (Quote functionality removed.)
    function insertTag(before, after){
        var t = document.getElementsByName("message")[0];
        var start = t.selectionStart;
        var end = t.selectionEnd;
        var txt = t.value;
        t.value = txt.substring(0, start) + before + txt.substring(start, end) + after + txt.substring(end);
    }
    </script>
</head>
<body>
<div class="logo"><a href="/">{$page_title}</a></div>
HTML;
}

function pageFooter(): string {
    return <<<HTML
</body>
</html>
HTML;
}

// ----- Post Rendering Functions -----
function buildPost(array $post, bool $isResPage): string {
    // Format markup in message
    $message = $post['message'];
    $message = preg_replace("#\*\*(.*?)\*\*#", "<b>$1</b>", $message);
    $message = preg_replace("#\[s\](.*?)\[/s\]#", "<strike>$1</strike>", $message);
    $message = preg_replace("#\*(.*?)\*#", "<i>$1</i>", $message);
    $message = preg_replace("#\[u\](.*?)\[/u\]#", "<span style=\"border-bottom: 1px solid\">$1</span>", $message);
    $message = preg_replace("#\%\%(.*?)\%\%#", "<span class=\"spoiler\">$1</span>", $message);
    $message = preg_replace("#\'\'(.*?)\'\'#", "<pre style=\"font-family: Courier New, Courier, mono\">$1</pre>", $message);

    $threadId = ($post['parent'] == 0) ? $post['id'] : $post['parent'];
    $postLink = '?do=thread&id=' . $threadId . '#' . $post['id'];

    $html = "";

    // For replies, wrap in a table layout.
    if ($post['parent'] != 0) {
        $html .= "<table><tr><td class=\"doubledash\">&gt;&gt;</td><td class=\"reply\" id=\"reply{$post['id']}\">";
    } elseif ($post['file'] != "") {
        // For threads with an image, show the thumbnail.
        $image_desc = htmlspecialchars($post['file_original']) . ' (' .
            $post["image_width"] . 'x' . $post["image_height"] . ', ' .
            $post["file_size_formatted"] . ')';
        $html .= <<<HTML
<a target="_blank" href="db/{$post["file"]}">
    <img title="{$image_desc}" src="db/{$post["thumb"]}" alt="{$post["id"]}" class="thumb" width="{$post["thumb_width"]}" height="{$post["thumb_height"]}">
</a>
HTML;
    }

    $html .= "<a name=\"{$post['id']}\"></a>";
    // Output the name block (date removed)
    $html .= $post["nameblock"];
    // For threads, add the View Thread link floated to the right.
    if ($post['parent'] == 0 && !$isResPage) {
        $html .= "<span class=\"reflink\">[<a href=\"?do=thread&id={$post['id']}\">View thread</a>]</span>";
    }

    $html .= "<blockquote>{$message}</blockquote>";

    if ($post['parent'] != 0) {
        $html .= "</td></tr></table>";
    } elseif ($post['parent'] == 0 && !$isResPage && isset($post['omitted']) && $post['omitted'] > 0) {
        $html .= '<span class="omittedposts">' . $post['omitted'] . ' post(s) omitted. ';
        $html .= '<a href="?do=thread&id=' . $post["id"] . '">Click here</a> to view.</span>';
    }

    return $html;
}

function buildPostBlock(string $parent): string {
    $isThread = ($parent === "0");
    $formTitle = $isThread ? "Create Thread" : "Post Reply";
    
    // Build captcha HTML (mandatory)
    $captchaHTML = '';
    if (TINYIB_USECAPTCHA) {
        $captcha_key = md5((string)mt_rand());
        $captcha_expect = md5(TINYIB_CAPTCHASALT . substr(md5($captcha_key), 0, 4));
        $captchaHTML = <<<HTML
<div class="form-field">
    <input type="hidden" name="captcha_ex" value="{$captcha_expect}">
    <input type="text" name="captcha_out" placeholder="Enter captcha" required style="background: url('captcha_png.php?key={$captcha_key}') no-repeat right center; background-size: auto 100%; padding-right: 60px;">
</div>
HTML;
    }
    
    $imageField = '';
    if (!CLAIRE_TEXTMODE) {
        $imageField = <<<HTML
<div class="form-field">
    <input type="file" name="file" title="Images: GIF, JPG or PNG up to 2 MB.">
</div>
HTML;
    }
    
    // Build a simplified form with inline placeholders (subject removed)
    return <<<HTML
<div id="postarea">
    <form name="postform" id="postform" action="?do=post" method="post" enctype="multipart/form-data">
        <input type="hidden" name="parent" value="{$parent}">
        <div class="form-field">
            <input type="text" name="name" placeholder="Name (optional)" maxlength="75">
        </div>
        <div class="form-field">
            <textarea name="message" placeholder="Message" rows="4" required></textarea>
        </div>
        {$captchaHTML}
        {$imageField}
        <div class="form-field">
            <input type="submit" value="{$formTitle}">
        </div>
    </form>
</div>
<hr>
HTML;
}

function buildPage(string $htmlposts, string $parent, int $pages = 0, int $thispage = 0): string {
    $returnLink = $parent !== "0" ? '<span class="replylink">[<a href="?do=page&p=0">Return</a>]</span>' : '';
    $pagelinks = '';
    if ($parent === "0") {
        $pagelinks = ($thispage === 0) ? "[ Previous ]" : '[ <a href="?do=page&p=' . ($thispage - 1) . '">Previous</a> ]';
        for ($i = 0; $i <= $pages; $i++) {
            $pagelinks .= ($thispage === $i) ? "[ $i ]" : "[ <a href=\"?do=page&p=$i\">$i</a> ]";
        }
        $pagelinks .= ($pages <= $thispage) ? "[ Next ]" : '[ <a href="?do=page&p=' . ($thispage + 1) . '">Next</a> ]';
    }
    
    $body = '';
    if ($parent !== "0") {
        $body .= $returnLink . "\n" . $htmlposts;
    }
    $body .= buildPostBlock($parent);
    if ($parent === "0") {
        $body .= $htmlposts . "\n" . $pagelinks;
    }
    
    return pageHeader() . $body . pageFooter();
}

function viewPage($pagenum): string {
    $page = (int)$pagenum;
    $pagecount = max(0, ceil(countThreads() / TINYIB_THREADSPERPAGE) - 1);
    if (!is_numeric($pagenum) || $page < 0 || $page > $pagecount) {
        fancyDie('Invalid page number.');
    }
    $htmlposts = [];
    $threads = getThreadRange(TINYIB_THREADSPERPAGE, $page * TINYIB_THREADSPERPAGE);
    foreach ($threads as $thread) {
        $replies = latestRepliesInThreadByID((int)$thread['id']);
        $htmlreplies = [];
        foreach ($replies as $reply) {
            $htmlreplies[] = buildPost($reply, false);
        }
        // Calculate omitted replies if more exist.
        $threadPosts = postsInThreadByID((int)$thread['id']);
        $thread["omitted"] = count($threadPosts) > (TINYIB_REPLIESTOSHOW + 1)
            ? (count($threadPosts) - (TINYIB_REPLIESTOSHOW + 1)) : 0;
        $htmlposts[] = buildPost($thread, false)
                     . implode("", array_reverse($htmlreplies))
                     . "<br clear=\"left\">\n<hr>";
    }
    return buildPage(implode('', $htmlposts), "0", $pagecount, $page);
}

function viewThread($id): string {
    $htmlposts = [];
    $posts = postsInThreadByID((int)$id);
    foreach ($posts as $post) {
        $htmlposts[] = buildPost($post, true);
    }
    $htmlposts[] = "<br clear=\"left\">\n<hr>";
    return buildPage(implode('', $htmlposts), (string)$id);
}

// ----- Utility Functions -----
function fancyDie(string $message, int $depth = 1): void {
    die('<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Error</title>
    <link rel="stylesheet" type="text/css" href="style.css" />
</head>
<body>
<br>' . nl2br(htmlspecialchars($message)) . '
</body>
</html>');
}

function newPost(): array {
    return [
        'parent' => '0',
        'timestamp' => 0,
        'ip' => '',
        'name' => '',
        'tripcode' => '',
        'email' => '',
        'nameblock' => '',
        'subject' => '',
        'message' => '',
        'file' => '',
        'file_hex' => '',
        'file_original' => '',
        'file_size' => 0,
        'file_size_formatted' => '',
        'image_width' => 0,
        'image_height' => 0,
        'thumb' => '',
        'thumb_width' => 0,
        'thumb_height' => 0
    ];
}

function convertBytes(int $number): string {
    $len = strlen((string)$number);
    if ($len <= 3) return sprintf("%dB", $number);
    if ($len <= 6) return sprintf("%.2fKB", $number/1024);
    if ($len <= 9) return sprintf("%.2fMB", $number/1024/1024);
    return sprintf("%.2fGB", $number/1024/1024/1024);
}

function nameAndTripcode(string $name): array {
    if (preg_match("/(#|!)(.*)/", $name, $regs)) {
        $cap = $regs[2];
        $cap_delimiter = (strpos($name, '#') !== false) ? '#' : '!';
        if (preg_match("/(.*)(" . $cap_delimiter . ")(.*)/", $cap, $regs_secure)) {
            $cap = $regs_secure[1];
            $cap_secure = $regs_secure[3];
            $is_secure_trip = true;
        } else {
            $is_secure_trip = false;
        }
        $tripcode = "";
        if ($cap !== "") {
            $cap = strtr($cap, "&amp;", "&");
            $cap = strtr($cap, "&#44;", ", ");
            $salt = substr($cap . "H.", 1, 2);
            $salt = preg_replace("/[^\.-z]/", ".", $salt);
            $salt = strtr($salt, ":;<=>?@[\\]^_`", "ABCDEFGabcdef");
            $tripcode = substr(crypt($cap, $salt), -10);
        }
        if ($is_secure_trip && $cap !== "") {
            $tripcode .= "!" . substr(md5($cap_secure . TINYIB_TRIPSEED), 2, 10);
        }
        return [preg_replace("/(" . $cap_delimiter . ")(.*)/", "", $name), $tripcode];
    }
    return [$name, ""];
}

function nameBlock(string $name, string $tripcode, string $email, int $timestamp): string {
    // Date display removed.
    $output = '<span class="postername">' . (($name === "" && $tripcode === "") ? "Anonymous" : htmlspecialchars($name)) . '</span>';
    if ($tripcode !== "") {
        $output .= '<span class="postertrip">!' . htmlspecialchars($tripcode) . '</span>';
    }
    if ($email !== "") {
        $output = '<a href="mailto:' . htmlspecialchars($email) . '">' . $output . '</a>';
    }
    return $output;
}

function _postLink(array $matches): string {
    $post = postByID((int)$matches[1]);
    if ($post) {
        $parent = ($post['parent'] == 0) ? $post['id'] : $post['parent'];
        return '<a href="?do=thread&id=' . $parent . '#' . $matches[1] . '">' . $matches[0] . '</a>';
    }
    return $matches[0];
}

function postLink(string $message): string {
    return preg_replace_callback('/&gt;&gt;([0-9]+)/', '_postLink', $message);
}

function colorQuote(string $message): string {
    if (substr($message, -1) !== "\n") {
        $message .= "\n";
    }
    return preg_replace('/^(&gt;[^\>](.*))\n/m', '<span class="unkfunc">$1</span>' . "\n", $message);
}

function checkFlood(): void {
    $lastpost = lastPostByIP();
    if ($lastpost && (time() - $lastpost['timestamp']) < TINYIB_RATELIMIT) {
        fancyDie('Please wait a moment before posting again. You will be able to post in ' . (TINYIB_RATELIMIT - (time() - $lastpost['timestamp'])) . " second(s).");
    }
}

function checkMessageSize(): void {
    if (strlen($_POST["message"]) > TINYIB_MAXPOSTSIZE) {
        fancyDie('Your message is ' . strlen($_POST["message"]) . ' characters long, but the maximum allowed is ' . TINYIB_MAXPOSTSIZE . '. Please shorten your message.');
    }
}

function setParent(): string {
    return isset($_POST["parent"]) ? $_POST["parent"] : "0";
}

function validateFileUpload(): void {
    switch ($_FILES['file']['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_FORM_SIZE:
            fancyDie("That file is larger than 2 MB.");
            break;
        case UPLOAD_ERR_INI_SIZE:
            fancyDie("The uploaded file exceeds the upload_max_filesize directive in php.ini.");
            break;
        case UPLOAD_ERR_PARTIAL:
            fancyDie("The uploaded file was only partially uploaded.");
            break;
        case UPLOAD_ERR_NO_FILE:
            fancyDie("No file was uploaded.");
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            fancyDie("Missing a temporary folder.");
            break;
        case UPLOAD_ERR_CANT_WRITE:
            fancyDie("Failed to write file to disk.");
            break;
        default:
            fancyDie("Unable to save the uploaded file.");
    }
}

function checkDuplicateImage(string $hex): void {
    $hexmatches = postsByHex($hex);
    if (count($hexmatches) > 0) {
        foreach ($hexmatches as $hexmatch) {
            $location = ($hexmatch['parent'] == '0') ? $hexmatch['id'] : $hexmatch['parent'];
            fancyDie('That file has already been posted <a href="?do=thread&id=' . $location . '#' . $hexmatch['id'] . '">here</a>.');
        }
    }
}

function thumbnailDimensions(int $width, int $height, bool $isReply): array {
    if ($isReply) {
        $max_h = TINYIB_REPLYHEIGHT;
        $max_w = TINYIB_REPLYWIDTH;
    } else {
        $max_h = TINYIB_THUMBHEIGHT;
        $max_w = TINYIB_THUMBWIDTH;
    }
    return ($width > $max_w || $height > $max_h) ? [$max_w, $max_h] : [$width, $height];
}

function createThumbnail(string $srcPath, string $destPath, int $new_w, int $new_h): bool {
    $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg'])) {
        $src_img = imagecreatefromjpeg($srcPath);
    } elseif ($ext === 'png') {
        $src_img = imagecreatefrompng($srcPath);
    } elseif ($ext === 'gif') {
        $src_img = imagecreatefromgif($srcPath);
    } else {
        return false;
    }

    if (!$src_img) {
        fancyDie("Unable to read uploaded file during thumbnailing.");
    }
    $old_x = imagesx($src_img);
    $old_y = imagesy($src_img);
    $percent = ($old_x > $old_y) ? ($new_w / $old_x) : ($new_h / $old_y);
    $thumb_w = (int)round($old_x * $percent);
    $thumb_h = (int)round($old_y * $percent);

    $dst_img = imagecreatetruecolor($thumb_w, $thumb_h);
    if ($ext === 'png') {
        imagealphablending($dst_img, false);
        imagesavealpha($dst_img, true);
        $transparent = imagecolorallocatealpha($dst_img, 0, 0, 0, 127);
        imagefilledrectangle($dst_img, 0, 0, $thumb_w, $thumb_h, $transparent);
    }
    imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $thumb_w, $thumb_h, $old_x, $old_y);

    $result = false;
    if (in_array($ext, ['jpg', 'jpeg'])) {
        $result = imagejpeg($dst_img, $destPath, 70);
    } elseif ($ext === 'png') {
        $result = imagepng($dst_img, $destPath);
    } elseif ($ext === 'gif') {
        $result = imagegif($dst_img, $destPath);
    }

    imagedestroy($dst_img);
    imagedestroy($src_img);
    return $result;
}

function redirect(string $url = '?do=page&p=0'): void {
    header('Location: ' . $url);
    exit;
}

// ----- Database Connection & Schema -----
try {
    $db = new PDO('sqlite:' . TINYIB_DBPATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    validateDatabaseSchema();
} catch (PDOException $e) {
    fancyDie('Could not connect to database: ' . $e->getMessage());
}

function validateDatabaseSchema(): void {
    global $db;
    $db->exec("
        CREATE TABLE IF NOT EXISTS " . TINYIB_DBPOSTS . " (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent INTEGER NOT NULL,
            timestamp INTEGER NOT NULL,
            ip TEXT NOT NULL,
            name TEXT NOT NULL,
            tripcode TEXT NOT NULL,
            email TEXT NOT NULL,
            nameblock TEXT NOT NULL,
            subject TEXT NOT NULL,
            message TEXT NOT NULL,
            file TEXT NOT NULL,
            file_hex TEXT NOT NULL,
            file_original TEXT NOT NULL,
            file_size INTEGER NOT NULL DEFAULT 0,
            file_size_formatted TEXT NOT NULL,
            image_width INTEGER NOT NULL DEFAULT 0,
            image_height INTEGER NOT NULL DEFAULT 0,
            thumb TEXT NOT NULL,
            thumb_width INTEGER NOT NULL DEFAULT 0,
            thumb_height INTEGER NOT NULL DEFAULT 0
        )
    ");
}

// ----- SQLite PDO Helper Functions -----
function fetchAndExecute(string $sql, array $parameters = []): array {
    global $db;
    $stmt = $db->prepare($sql);
    $stmt->execute($parameters);
    return $stmt->fetchAll();
}

function postByID(int $id): ?array {
    $result = fetchAndExecute("SELECT * FROM " . TINYIB_DBPOSTS . " WHERE id=? LIMIT 1", [$id]);
    return $result ? $result[0] : null;
}

function insertPost(array $post): int {
    global $db;
    $stmt = $db->prepare("
        INSERT INTO " . TINYIB_DBPOSTS . " (
            parent, timestamp, ip, name, tripcode, email, nameblock,
            subject, message, file, file_hex, file_original,
            file_size, file_size_formatted, image_width, image_height,
            thumb, thumb_width, thumb_height
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $post['parent'], time(), $_SERVER['REMOTE_ADDR'],
        $post['name'], $post['tripcode'], $post['email'], $post['nameblock'],
        $post['subject'], $post['message'], $post['file'], $post['file_hex'], $post['file_original'],
        $post['file_size'], $post['file_size_formatted'],
        $post['image_width'], $post['image_height'], $post['thumb'],
        $post['thumb_width'], $post['thumb_height']
    ]);
    return (int)$db->lastInsertId();
}

function countPosts(): int {
    $result = fetchAndExecute("SELECT COUNT(*) c FROM " . TINYIB_DBPOSTS);
    return (int)$result[0]['c'];
}

function latestPosts(int $count): array {
    return fetchAndExecute("SELECT * FROM " . TINYIB_DBPOSTS . " ORDER BY id DESC LIMIT " . (int)$count);
}

function postsByHex(string $hex): array {    
    return fetchAndExecute("SELECT id,parent FROM " . TINYIB_DBPOSTS . " WHERE file_hex=? LIMIT 1", [$hex]);
}

function postsInThreadByID(int $id): array {
    return fetchAndExecute("SELECT * FROM " . TINYIB_DBPOSTS . " WHERE id=? OR parent=? ORDER BY id ASC", [$id, $id]);
}

function latestRepliesInThreadByID(int $id): array {
    return fetchAndExecute("SELECT * FROM " . TINYIB_DBPOSTS . " WHERE parent = ? ORDER BY id DESC LIMIT " . TINYIB_REPLIESTOSHOW, [$id]);
}

function lastPostByIP(): ?array {
    $result = fetchAndExecute("SELECT * FROM " . TINYIB_DBPOSTS . " WHERE ip=? ORDER BY id DESC LIMIT 1", [$_SERVER['REMOTE_ADDR']]);
    return $result ? $result[0] : null;
}

function threadExistsByID(int $id): bool {
    $result = fetchAndExecute("SELECT COUNT(id) c FROM " . TINYIB_DBPOSTS . " WHERE id=? AND parent=? LIMIT 1", [$id, 0]);
    return ((int)$result[0]['c']) > 0;
}

function bumpThreadByID(int $id): void {
    fetchAndExecute("UPDATE " . TINYIB_DBPOSTS . " SET timestamp = ? WHERE id = ?", [time(), $id]);
}

function countThreads(): int {
    $result = fetchAndExecute("SELECT COUNT(id) c FROM " . TINYIB_DBPOSTS . " WHERE parent = ?", [0]);
    return (int)$result[0]['c'];
}

function getThreadRange(int $count, int $offset): array {
    return fetchAndExecute("SELECT * FROM " . TINYIB_DBPOSTS . " WHERE parent = ? ORDER BY timestamp DESC LIMIT $offset, $count", [0]);
}

function trimThreads(): void {
    if (TINYIB_MAXTHREADS > 0) {
        $result = fetchAndExecute("SELECT id FROM " . TINYIB_DBPOSTS . " WHERE parent = ? ORDER BY timestamp DESC LIMIT " . TINYIB_MAXTHREADS . ",10", [0]);
        foreach ($result as $post) {
            // For simplicity, we are not supporting deletion by users.
        }
    }
}

// ----- Controller -----
if (!isset($_GET['do'])) {
    redirect('?do=page&p=0');
}

switch ($_GET['do']) {
    case 'page':
        if (!isset($_GET['p'])) {
            redirect('?do=page&p=0');
        }
        echo viewPage($_GET['p']);
        break;
    case 'thread':
        if (!isset($_GET['id'])) {
            redirect('?do=page&p=0');
        }
        echo viewThread($_GET['id']);
        break;
    case 'post':
        handlePost();
        // Redirect to the thread (or new thread) view
        $parent = ($_POST['parent'] === "0") ? (string)$_SESSION['last_post_id'] : $_POST['parent'];
        redirect('?do=thread&id=' . $parent . '#' . $_SESSION['last_post_id']);
        break;
    default:
        fancyDie('Invalid request.');
        break;
}

// ----- Posting Handler -----
function handlePost(): void {
    // Validate request: require a message (and/or a file)
    if (!isset($_POST["message"]) && !isset($_FILES["file"])) {
        fancyDie('Invalid request');
    }
    checkMessageSize();
    checkFlood();
    
    // Captcha validation
    if (TINYIB_USECAPTCHA) {
        if (!isset($_POST['captcha_ex'], $_POST['captcha_out']) ||
            $_POST['captcha_ex'] !== md5(TINYIB_CAPTCHASALT . $_POST['captcha_out'])
        ) {
            fancyDie('Captcha verification failed.');
        }
    }
    
    $post = newPost();
    $post['parent'] = setParent();
    $post['ip'] = $_SERVER['REMOTE_ADDR'];
    list($post['name'], $trip) = nameAndTripcode($_POST["name"] ?? '');
    $post['tripcode'] = $trip;
    $post['name'] = htmlspecialchars(substr($post['name'], 0, 75));
    $post['email'] = ''; // Deprecated
    // Subject field removed.
    $post['subject'] = '';
    
    // Process the message (convert newlines and add links)
    $cleanMessage = htmlspecialchars(rtrim($_POST["message"]));
    $post['message'] = str_replace("\n", "<br>", colorQuote(postLink($cleanMessage)));
    
    $post['nameblock'] = nameBlock($post['name'], $post['tripcode'], $post['email'], time());
    
    // Handle file upload if present.
    if (isset($_FILES['file']) && $_FILES['file']['name'] !== "") {
        validateFileUpload();
        if (!is_file($_FILES['file']['tmp_name']) || !is_readable($_FILES['file']['tmp_name'])) {
            fancyDie("File transfer failure. Please retry the submission.");
        }
        $post['file_original'] = substr(htmlentities($_FILES['file']['name'], ENT_QUOTES), 0, 50);
        $post['file_hex'] = md5_file($_FILES['file']['tmp_name']);
        $post['file_size'] = (int)$_FILES['file']['size'];
        $post['file_size_formatted'] = convertBytes($post['file_size']);
        
        $fileExt = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if ($fileExt === 'jpeg') {
            $fileExt = 'jpg';
        }
        $fileName = time() . mt_rand(1, 99);
        $post['file'] = $fileName . '.' . $fileExt;
        $post['thumb'] = "thumb_" . $fileName . '.' . $fileExt;
        $fileLocation = __DIR__ . "/db/" . $post['file'];
        $thumbLocation = __DIR__ . "/db/" . $post['thumb'];
        
        // Allow only specific file types.
        if (!in_array('.' . $fileExt, ['.jpg', '.gif', '.png'])) {
            fancyDie("Only GIF, JPG, and PNG files are allowed.");
        }
        if (!@getimagesize($_FILES['file']['tmp_name'])) {
            fancyDie("Failed to read the size of the uploaded file. Please retry.");
        }
        $fileInfo = getimagesize($_FILES['file']['tmp_name']);
        $mime = $fileInfo['mime'];
        if (!in_array($mime, ["image/jpeg", "image/gif", "image/png"])) {
            fancyDie("Only GIF, JPG, and PNG files are allowed.");
        }
        checkDuplicateImage($post['file_hex']);
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $fileLocation)) {
            fancyDie("Could not store uploaded file.");
        }
        if ($_FILES['file']['size'] !== filesize($fileLocation)) {
            fancyDie("File transfer failure. Please try again.");
        }
        $post['image_width'] = $fileInfo[0];
        $post['image_height'] = $fileInfo[1];
        
        list($max_w, $max_h) = thumbnailDimensions($post['image_width'], $post['image_height'], ($post['parent'] !== "0"));
        if (!createThumbnail($fileLocation, $thumbLocation, $max_w, $max_h)) {
            fancyDie("Could not create thumbnail.");
        }
        $thumbInfo = getimagesize($thumbLocation);
        $post['thumb_width'] = $thumbInfo[0];
        $post['thumb_height'] = $thumbInfo[1];
    }
    
    // Image is now optional.
    if (str_replace('<br>', '', $post['message']) === "") {
        fancyDie("Please enter a message.");
    }
    
    $postId = insertPost($post);
    $_SESSION['last_post_id'] = $postId;
    
    trimThreads();
}
