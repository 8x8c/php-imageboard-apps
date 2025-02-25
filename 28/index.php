<?php
/****************************************************************************
 * Minimal Imageboard (No delete link, no date/time, no post number shown)
 * Sorts threads by last update. 
 * PHP 8+ version
 ****************************************************************************/

// ------------------ Configuration ------------------
define('CLAIRE_TEXTMODE', true);     // If true, no images needed or allowed
define('TINYIB_PAGETITLE', 'Claire Board');
define('TINYIB_THREADSPERPAGE', 8);
define('TINYIB_REPLIESTOSHOW', 3);
define('TINYIB_MAXTHREADS', 0);       // 0 = unlimited
define('TINYIB_MAXPOSTSIZE', 16000);
define('TINYIB_RATELIMIT', 7);        // seconds between same-IP posts
// We'll keep “bumped” to track last update, but remove “timestamp”.

// Thumbnails
define('TINYIB_THUMBWIDTH',  200);
define('TINYIB_THUMBHEIGHT', 300);
define('TINYIB_REPLYWIDTH',  200);
define('TINYIB_REPLYHEIGHT', 300);

define('TINYIB_DBPOSTS', 'posts');
define('TINYIB_DBPATH',  'database.db');

// Make sure we have a place to store images
if (!file_exists('db')) {
    mkdir('db', 0777, true);
}
error_reporting(E_ALL);

// --------------- HTML Layout ---------------
function pageHeader(): string {
    $page_title = TINYIB_PAGETITLE;
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{$page_title}</title>
    <link rel="stylesheet" href="style.css" type="text/css">
    <script>
    function insertTag(openTag, closeTag) {
        const msg = document.getElementsByName("message")[0];
        if (!msg) return;
        const start = msg.selectionStart;
        const end = msg.selectionEnd;
        const text = msg.value;
        const before = text.substring(0, start);
        const sel = text.substring(start, end);
        const after = text.substring(end);
        msg.value = before + openTag + sel + closeTag + after;
        msg.selectionStart = start + openTag.length;
        msg.selectionEnd = start + openTag.length + sel.length;
        msg.focus();
    }
    function quote(postid) {
        const msg = document.forms.postform.message;
        const text = ">>" + postid + "\\n";
        if (msg.setRangeText) {
            const s = msg.selectionStart;
            msg.setRangeText(text, s, s, "end");
            msg.selectionStart = s + text.length;
            msg.selectionEnd = s + text.length;
        } else {
            msg.value += text;
        }
        msg.focus();
    }
    </script>
</head>
<body>

HTML;
}

function pageFooter(): string {
    return <<<HTML
<div class="footer">
    <hr>
    Powered by a <em>minimal Claire script</em>
</div>
</body>
</html>
HTML;
}

/**
 * Builds a single post’s HTML, 
 *  - No “X” link
 *  - No date/time display
 *  - No “No.#” label
 */
function buildPost(array $post, bool $isrespage): string {
    // Basic markup transformations
    $message = $post['message'];
    // Bold: **text**
    $message = preg_replace("#\*\*(.*?)\*\*#", "<b>\\1</b>", $message);
    // Italic: *text*
    $message = preg_replace("#\*(.*?)\*#", "<i>\\1</i>", $message);
    // Spoiler: %%text%%
    $message = preg_replace(
        "#\%\%(.*?)\%\%#",
        "<span style=\"background-color:#666;color:#666\" onmouseover=\"this.style.color='#fff'\" onmouseout=\"this.style.color='#666'\">\\1</span>",
        $message
    );

    // Determine if OP or reply
    $isOP = ($post['parent'] == 0);

    // HTML output
    $r = '';
    // If OP on index => “View thread”
    if ($isOP && !$isrespage) {
        // Provide a link to its thread
        $r .= '<span class="replylink">[<a href="?do=thread&id='.$post['id'].'">View thread</a>]</span>';
    }

    if (!$isOP) {
        // Wrap replies in a table
        $r .= '<table><tbody><tr><td class="reply" id="reply'.$post['id'].'">';
    } else {
        // If OP has an image, show it first
        if ($post['file'] !== '') {
            $imgDesc = htmlspecialchars($post['file_original'])
                     .' ('.$post["image_width"].'x'.$post["image_height"]
                     .', '.$post["file_size_formatted"].')';
            $r .= '<a target="_blank" href="db/'.$post["file"].'">'
                . '<img src="db/'.$post["thumb"].'" alt="'.$post["id"].'" class="thumb" '
                . 'width="'.$post["thumb_width"].'" height="'.$post["thumb_height"].'" '
                . 'title="'.$imgDesc.'"></a>';
        }
    }

    // Subject
    if ($post["subject"] != "") {
        $r .= ' <span class="filetitle">'.$post["subject"].'</span> ';
    }
    // Nameblock (no date/time or post number)
    if ($post['nameblock'] != '') {
        // e.g. “Anonymous” or “Alice !TrIp”
        $r .= ' '.$post['nameblock'];
    }

    // If reply has an image
    if (!$isOP && $post["file"] !== "") {
        $imgDesc = htmlspecialchars($post['file_original'])
                 .' ('.$post["image_width"].'x'.$post["image_height"]
                 .', '.$post["file_size_formatted"].')';
        $r .= '<br><a target="_blank" href="db/'.$post["file"].'">'
            . '<img title="'.$imgDesc.'" src="db/'.$post["thumb"].'" alt="'.$post["id"].'" '
            . 'class="thumb" width="'.$post["thumb_width"].'" height="'.$post["thumb_height"].'"></a>';
    }

    // Post message
    $r .= '<blockquote>'.$message.'</blockquote>';

    // Omitted replies note (only for OP on index)
    if ($isOP && !$isrespage && !empty($post['omitted'])) {
        if ($post['omitted'] > 0) {
            $r .= '<span class="omittedposts">('
                .$post['omitted'].' post(s) omitted. '
                .'<a href="?do=thread&id='.$post["id"].'">Click here</a> to view.)</span>';
        }
    }

    if (!$isOP) {
        $r .= '</td></tr></tbody></table>';
    }
    return $r;
}

/**
 * Build the final HTML page: includes post form, thread listing, etc.
 */
function buildPage(string $htmlposts, int $parent, int $pages=0, int $thispage=0): string {
    $pagelinks = '';

    // If on index (parent=0), show pagination
    if ($parent === 0) {
        $pages = max(0, $pages);
        // “Previous”
        if ($thispage <= 0) {
            $pagelinks .= '[ Previous ] ';
        } else {
            $pagelinks .= '[ <a href="?do=page&p='.($thispage - 1).'">Previous</a> ] ';
        }
        // Page numbers
        for ($i=0; $i<=$pages; $i++) {
            if ($i === $thispage) {
                $pagelinks .= '[ '.$i.' ] ';
            } else {
                $pagelinks .= '[ <a href="?do=page&p='.$i.'">'.$i.'</a> ] ';
            }
        }
        // “Next”
        if ($thispage >= $pages) {
            $pagelinks .= '[ Next ]';
        } else {
            $pagelinks .= '[ <a href="?do=page&p='.($thispage + 1).'">Next</a> ]';
        }
    }

    // Board banner
    $body = '<div class="logo">'.TINYIB_PAGETITLE.'</div><hr>';

    // Post form
    $body .= buildPostBlock($parent);

    // Show compiled posts
    $body .= $htmlposts;

    if ($parent === 0) {
        // Index => show page links
        $body .= '<div class="pagelinks">'.$pagelinks.'</div>';
    } else {
        // Thread => “Return” link
        $body .= '<div><a href="?">Return</a></div>';
    }

    return pageHeader() . $body . pageFooter();
}

/**
 * The post form block
 *   - If $parent==0 => new thread (can have file upload)
 *   - If $parent!=0 => reply (no file upload if you prefer)
 */
function buildPostBlock(int $parent): string {
    $isReply = ($parent !== 0);

    ob_start(); ?>
    <div class="postbox">
      <form name="postform" id="postform" action="?do=post" method="post" enctype="multipart/form-data">
        <input type="hidden" name="parent" value="<?= htmlspecialchars($parent) ?>">

        <div>
          <input type="text" name="name" placeholder="Name" size="28" maxlength="75">
        </div>

        <?php if (!$isReply): // Only show Subject & file if new thread ?>
          <div>
            <input type="text" name="subject" placeholder="Subject" size="40" maxlength="75">
          </div>
        <?php endif; ?>

        <div>
          <textarea name="message" placeholder="Message" cols="48" rows="4"></textarea>
        </div>

        <?php if (!CLAIRE_TEXTMODE && !$isReply): // Only show file upload for OP ?>
          <div>
            <input type="file" name="file" placeholder="Image (GIF, JPG, PNG)">
          </div>
        <?php endif; ?>

        <div>
          <input type="submit" value="<?= $isReply ? 'Post Reply' : 'Create Thread' ?>">
        </div>
      </form>
    </div>
    <hr>
    <?php
    return ob_get_clean();
}

// ------------------ Database Setup ------------------
try {
    $db = new PDO('sqlite:' . TINYIB_DBPATH);
    validateDatabaseSchema();
} catch (PDOException $e) {
    fancyDie('Could not connect to database: ' . $e->getMessage());
}

/**
 * We’ll store:
 *  - id, parent
 *  - bumped (integer) -> last update time for thread sorting
 *  - ip, name, tripcode, nameblock
 *  - subject, message
 *  - file info if image was uploaded
 */
function validateDatabaseSchema(): void {
    global $db;
    $db->query('
        CREATE TABLE IF NOT EXISTS '.TINYIB_DBPOSTS.' (
            id INTEGER PRIMARY KEY,
            parent INTEGER NOT NULL,
            bumped INTEGER NOT NULL,      -- used for sorting by last update
            ip TEXT NOT NULL,
            name TEXT NOT NULL,
            tripcode TEXT NOT NULL,
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
    ');
}

// ------------------ DB Helpers ----------------------
function fetchAndExecute(string $sql, array $params = []): array {
    $stmt = $GLOBALS['db']->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function postByID(int $id): ?array {
    $res = fetchAndExecute(
        'SELECT * FROM '.TINYIB_DBPOSTS.' WHERE id=? LIMIT 1',
        [$id]
    );
    return $res ? $res[0] : null;
}

function insertPost(array $post): int {
    fetchAndExecute(
        'INSERT INTO '.TINYIB_DBPOSTS.' (
            parent, bumped, ip,
            name, tripcode, nameblock,
            subject, message,
            file, file_hex, file_original,
            file_size, file_size_formatted,
            image_width, image_height,
            thumb, thumb_width, thumb_height
        )
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $post['parent'],
            $post['bumped'],
            $_SERVER['REMOTE_ADDR'],
            $post['name'],
            $post['tripcode'],
            $post['nameblock'],
            $post['subject'],
            $post['message'],
            $post['file'],
            $post['file_hex'],
            $post['file_original'],
            $post['file_size'],
            $post['file_size_formatted'],
            $post['image_width'],
            $post['image_height'],
            $post['thumb'],
            $post['thumb_width'],
            $post['thumb_height']
        ]
    );
    return (int)$GLOBALS['db']->lastInsertId();
}

/** Delete entire thread if OP, or just that reply. */
function deletePostByID(int $id): void {
    $post = postByID($id);
    if (!$post) return;

    if ($post['parent'] == 0) {
        // entire thread
        $threadPosts = fetchAndExecute(
            'SELECT * FROM '.TINYIB_DBPOSTS.' WHERE id=? OR parent=?',
            [$id, $id]
        );
        foreach ($threadPosts as $p) {
            deletePostImages($p);
            fetchAndExecute('DELETE FROM '.TINYIB_DBPOSTS.' WHERE id=?', [$p['id']]);
        }
    } else {
        // single reply
        deletePostImages($post);
        fetchAndExecute('DELETE FROM '.TINYIB_DBPOSTS.' WHERE id=?', [$id]);
    }
}

/** Return all posts in a thread (OP + replies). */
function postsInThread(int $id): array {
    return fetchAndExecute(
        'SELECT * FROM '.TINYIB_DBPOSTS.' WHERE parent=? OR id=? ORDER BY id ASC',
        [$id, $id]
    );
}

/** Return latest few replies for a thread. */
function latestRepliesInThread(int $id): array {
    return fetchAndExecute(
        'SELECT * FROM '.TINYIB_DBPOSTS.' WHERE parent=? ORDER BY id DESC LIMIT '.TINYIB_REPLIESTOSHOW,
        [$id]
    );
}

/** Count how many threads (OP posts) exist. */
function countThreads(): int {
    $res = fetchAndExecute(
        'SELECT COUNT(id) AS c FROM '.TINYIB_DBPOSTS.' WHERE parent=0'
    );
    return (int)($res[0]['c'] ?? 0);
}

/** 
 * Get threads in descending order by “bumped”.
 * $count => how many threads
 * $offset => which offset
 */
function getThreadRange(int $count, int $offset): array {
    return fetchAndExecute(
        'SELECT * FROM '.TINYIB_DBPOSTS.' WHERE parent=0 ORDER BY bumped DESC LIMIT '.$offset.','.$count
    );
}

/** “Bump” a thread to the top by updating “bumped”. */
function bumpThreadByID(int $id): void {
    fetchAndExecute(
        'UPDATE '.TINYIB_DBPOSTS.' SET bumped=? WHERE id=?',
        [time(), $id]
    );
}

/** If TINYIB_MAXTHREADS is set, remove older threads beyond that count. */
function trimThreads(): void {
    if (TINYIB_MAXTHREADS > 0) {
        $res = fetchAndExecute(
            'SELECT id FROM '.TINYIB_DBPOSTS.' WHERE parent=0 ORDER BY bumped DESC LIMIT '.TINYIB_MAXTHREADS.',100'
        );
        foreach ($res as $row) {
            deletePostByID((int)$row['id']);
        }
    }
}

// --------------- Utilities ---------------
function fancyDie(string $message): void {
    die('
    <!DOCTYPE html>
    <meta charset="UTF-8">
    <body style="background-color:#333; color:#EEE; text-align:center; margin-top:20px;">
        <h3>Error</h3>
        <p>'.nl2br($message).'</p>
    </body>
    ');
}

function newPost(): array {
    return [
        'parent' => 0,
        'bumped' => 0,
        'ip' => '',
        'name' => '',
        'tripcode' => '',
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
        'thumb_height' => 0,
    ];
}

/** Convert file size to e.g. “123KB” */
function convertBytes(int $num): string {
    if ($num < 1024) return $num.'B';
    if ($num < 1048576) return round($num/1024, 2).'KB';
    if ($num < 1073741824) return round($num/1048576, 2).'MB';
    return round($num/1073741824, 2).'GB';
}

/** If “Name” has # or ! => tripcode. Just keep it minimal. */
function nameAndTripcode(string $name): array {
    if (preg_match("/(#|!)(.*)/", $name, $m)) {
        $cap = $m[2];
        // salt for crypt:
        $salt = substr($cap."H.", 1, 2);
        $salt = preg_replace("/[^\.-z]/", ".", $salt);
        $salt = strtr($salt, ":;<=>?@[\\]^_`", "ABCDEFGabcdef");
        $tripcode = substr(crypt($cap, $salt), -10);
        $cleanName = preg_replace("/(#|!).*/", "", $name);
        return [$cleanName, $tripcode];
    }
    return [$name, ''];
}

/** 
 * We are removing date/time display, so nameblock is just e.g. “Anonymous” or “Bob !XYZ”
 */
function nameBlock(string $name, string $trip): string {
    $out = ($name !== '' ? $name : 'Anonymous');
    if ($trip !== '') {
        $out .= ' !'.$trip;
    }
    return $out;
}

function deletePostImages(array $post): void {
    if (!empty($post['file']) && file_exists('db/'.$post['file'])) {
        @unlink('db/'.$post['file']);
    }
    if (!empty($post['thumb']) && file_exists('db/'.$post['thumb'])) {
        @unlink('db/'.$post['thumb']);
    }
}

function postsByHex(string $hex): array {
    return fetchAndExecute(
        'SELECT id,parent FROM '.TINYIB_DBPOSTS.' WHERE file_hex=?',
        [$hex]
    );
}

/** Create a thumbnail image. */
function createThumbnail(string $srcpath, string $thumbpath, int $new_w, int $new_h): bool {
    $info = getimagesize($srcpath);
    if (!$info) return false;
    [$old_w, $old_h] = $info;

    $ext = strtolower(pathinfo($srcpath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif'])) return false;

    switch ($ext) {
        case 'gif':  $src = imagecreatefromgif($srcpath);  break;
        case 'png':  $src = imagecreatefrompng($srcpath);  break;
        default:     $src = imagecreatefromjpeg($srcpath); break;
    }
    if (!$src) return false;

    $aspect = min($new_w / $old_w, $new_h / $old_h);
    $thumb_w = max(1, (int)($old_w * $aspect));
    $thumb_h = max(1, (int)($old_h * $aspect));

    $dst = imagecreatetruecolor($thumb_w, $thumb_h);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $thumb_w, $thumb_h, $old_w, $old_h);

    switch ($ext) {
        case 'gif':  imagegif($dst, $thumbpath);  break;
        case 'png':  imagepng($dst, $thumbpath, 6); break;
        default:     imagejpeg($dst, $thumbpath, 80); break;
    }
    imagedestroy($dst);
    imagedestroy($src);
    return true;
}

function thumbnailDimensions(int $width, int $height, bool $isReply): array {
    $max_w = $isReply ? TINYIB_REPLYWIDTH : TINYIB_THUMBWIDTH;
    $max_h = $isReply ? TINYIB_REPLYHEIGHT : TINYIB_THUMBHEIGHT;
    if ($width > $max_w || $height > $max_h) {
        return [$max_w, $max_h];
    }
    return [$width, $height];
}

/** Simple flood check: must wait RATELIMIT seconds. */
function lastPostByIP(): ?array {
    $res = fetchAndExecute(
        'SELECT * FROM '.TINYIB_DBPOSTS.' WHERE ip=? ORDER BY id DESC LIMIT 1',
        [$_SERVER['REMOTE_ADDR']]
    );
    return $res ? $res[0] : null;
}

function checkFlood(): void {
    $lp = lastPostByIP();
    if ($lp) {
        $diff = time() - $lp['bumped']; // we use bumped as last update
        if ($diff < TINYIB_RATELIMIT) {
            fancyDie('Please wait a few seconds before posting again.');
        }
    }
}

function checkMessageSize(string $msg): void {
    if (strlen($msg) > TINYIB_MAXPOSTSIZE) {
        fancyDie('Your message is too long. The max is '.TINYIB_MAXPOSTSIZE.' characters.');
    }
}

/** Simple redirect. */
function redirect(string $url): void {
    header('Location: '.$url);
    exit;
}

/** Validate file upload status. */
function validateFileUpload(array $fileArr): void {
    if ($fileArr['error'] !== UPLOAD_ERR_OK) {
        switch ($fileArr['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                fancyDie("File too large (2 MB limit?).");
            default:
                fancyDie("File upload error #".$fileArr['error']);
        }
    }
}

// ------------------ Controller ------------------
if (!isset($_GET['do'])) {
    redirect('?do=page&p=0');
}

switch ($_GET['do']) {
    case 'page':
        if (!isset($_GET['p'])) {
            redirect('?do=page&p=0');
        }
        die(viewPage((int)$_GET['p']));
    case 'thread':
        if (!isset($_GET['id'])) {
            redirect('?do=page&p=0');
        }
        die(viewThread((int)$_GET['id']));
    case 'post':
        handlePost();
        break;
    default:
        fancyDie('Invalid request.');
}

// --------------- Request Handlers ---------------
function viewPage(int $pagenum): string {
    $page = max(0, $pagenum);
    $threadCount = countThreads();
    $pageCount = max(0, ceil($threadCount / TINYIB_THREADSPERPAGE) - 1);
    if ($page > $pageCount) {
        fancyDie('Invalid page number.');
    }
    // Get threads
    $threads = getThreadRange(TINYIB_THREADSPERPAGE, $page * TINYIB_THREADSPERPAGE);

    $html = [];
    foreach ($threads as $th) {
        // Gather replies
        $replies = latestRepliesInThread((int)$th['id']);
        $htmlReplies = [];
        foreach ($replies as $rp) {
            $htmlReplies[] = buildPost($rp, false);
        }
        // Check for omitted replies
        $all = postsInThread((int)$th['id']);
        $total = count($all);
        $omittedCount = 0;
        if ($total > (TINYIB_REPLIESTOSHOW + 1)) {
            $omittedCount = $total - (TINYIB_REPLIESTOSHOW + 1);
        }
        $th['omitted'] = $omittedCount;
        $html[] = buildPost($th, false)
               . implode("", array_reverse($htmlReplies))
               . "<br clear=\"left\"><hr>";
    }
    return buildPage(implode('', $html), 0, $pageCount, $page);
}

function viewThread(int $threadID): string {
    // get all posts
    $posts = postsInThread($threadID);
    if (!$posts) {
        fancyDie('Thread does not exist.');
    }
    $html = [];
    foreach ($posts as $p) {
        $html[] = buildPost($p, true);
    }
    $html[] = "<br clear=\"left\"><hr>";
    return buildPage(implode('', $html), $threadID);
}

/** Creates a new post (thread or reply). */
function handlePost(): void {
    checkFlood(); // Wait TINYIB_RATELIMIT if IP posted recently

    $post = newPost();
    $parent = isset($_POST['parent']) ? (int)$_POST['parent'] : 0;

    // If replying, parent must be a valid OP
    if ($parent > 0) {
        $parentPost = postByID($parent);
        if (!$parentPost || $parentPost['parent'] != 0) {
            fancyDie('Invalid parent thread ID.');
        }
    }
    $post['parent'] = $parent;
    // We'll set bumped to “now.”
    $post['bumped'] = time();

    // Name, trip
    $rawName = substr($_POST['name'] ?? '', 0, 75);
    [$nm, $tr] = nameAndTripcode($rawName);
    $post['name'] = htmlspecialchars($nm, ENT_QUOTES);
    $post['tripcode'] = $tr;
    $post['nameblock'] = nameBlock($post['name'], $post['tripcode']);

    // Subject (only if new thread)
    $rawSubject = isset($_POST['subject']) ? substr($_POST['subject'], 0, 75) : '';
    $post['subject'] = htmlspecialchars($rawSubject, ENT_QUOTES);

    // Message
    $rawMsg = rtrim($_POST['message'] ?? '');
    checkMessageSize($rawMsg);
    // Convert newlines => <br>, escape HTML
    $rawMsg = nl2br(htmlspecialchars($rawMsg, ENT_QUOTES));
    $post['message'] = $rawMsg;

    // If OP and not TEXTMODE => handle file
    $isReply = ($parent !== 0);
    if (!CLAIRE_TEXTMODE && !$isReply && isset($_FILES['file']) && $_FILES['file']['name'] !== "") {
        validateFileUpload($_FILES['file']);
        $tmpPath = $_FILES['file']['tmp_name'];
        $fileSize = $_FILES['file']['size'];
        $fileHash = md5_file($tmpPath);

        // Duplicate check
        $dups = postsByHex($fileHash);
        if ($dups) {
            fancyDie('Duplicate image detected.');
        }
        $fileOriginalName = substr($_FILES['file']['name'], 0, 50);
        $fileOriginalName = htmlspecialchars($fileOriginalName, ENT_QUOTES);

        // Extension
        $extension = strtolower(pathinfo($fileOriginalName, PATHINFO_EXTENSION));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        $uniqueName   = time().mt_rand(1,999).'.'.$extension;
        $uniqueThumb  = 'thumb_'.time().mt_rand(1,999).'.'.$extension;
        $destPath  = 'db/'.$uniqueName;
        $thumbPath = 'db/'.$uniqueThumb;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            fancyDie('Could not store uploaded file.');
        }
        if (filesize($destPath) !== $fileSize) {
            fancyDie('File transfer error.');
        }
        $imgInfo = getimagesize($destPath);
        if (!$imgInfo) {
            fancyDie('Not a valid image.');
        }
        [$imgW, $imgH] = $imgInfo;

        [$maxW, $maxH] = thumbnailDimensions($imgW, $imgH, false);
        if (!createThumbnail($destPath, $thumbPath, $maxW, $maxH)) {
            fancyDie('Could not create thumbnail.');
        }
        $thumbInfo = getimagesize($thumbPath);

        $post['file'] = $uniqueName;
        $post['file_hex'] = $fileHash;
        $post['file_original'] = $fileOriginalName;
        $post['file_size'] = $fileSize;
        $post['file_size_formatted'] = convertBytes($fileSize);
        $post['image_width'] = $imgW;
        $post['image_height'] = $imgH;
        $post['thumb'] = $uniqueThumb;
        $post['thumb_width'] = $thumbInfo[0];
        $post['thumb_height'] = $thumbInfo[1];
    } else {
        // If new thread has no file & not textmode => error
        if (!$isReply && !CLAIRE_TEXTMODE) {
            fancyDie('An image is required to start a thread.');
        }
    }

    // Insert
    $newID = insertPost($post);

    // Bump the thread if replying
    if ($isReply) {
        bumpThreadByID($parent);
    }

    // Trim older threads
    trimThreads();

    // Redirect to the thread
    redirect('?do=thread&id=' . ($isReply ? $parent : $newID));
}

?>
