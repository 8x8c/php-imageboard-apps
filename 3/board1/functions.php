<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function init_db(): SQLite3 {
    $db = new SQLite3($GLOBALS['db_file']);
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA foreign_keys = ON;");
    return $db;
}

function get_global_csrf_token(): string {
    global $csrf_file;
    if (!file_exists($csrf_file)) {
        // Create a persistent CSRF token
        $token = bin2hex(random_bytes(32));
        file_put_contents($csrf_file, $token, LOCK_EX);
        return $token;
    }
    $token = trim(file_get_contents($csrf_file));
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        file_put_contents($csrf_file, $token, LOCK_EX);
    }
    return $token;
}

function verify_csrf_token(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        $global_token = get_global_csrf_token();
        if (!hash_equals($global_token, $token)) {
            http_response_code(403);
            die("Invalid CSRF token.");
        }
    }
}

function sanitize_input(string $input): string {
    // Strip HTML tags and trim whitespace
    $input = strip_tags($input);
    return trim($input);
}

function generate_static_index(SQLite3 $db): void {
    global $posts_per_page;

    $stmt = $db->prepare("SELECT * FROM posts WHERE parent_id=0 ORDER BY datetime DESC LIMIT :limit");
    $stmt->bindValue(':limit', $posts_per_page, SQLITE3_INTEGER);
    $results = $stmt->execute();

    $posts = [];
    while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
        $posts[] = $row;
    }

    ob_start();
    if (count($posts) > 0) {
        render_board_index_with_array($posts);
    } else {
        render_board_index(null);
    }
    $html = ob_get_clean();
    file_put_contents(__DIR__ . '/index.html', $html, LOCK_EX);
}

function generate_static_thread(SQLite3 $db, int $thread_id): void {
    global $threads_dir;

    $op_stmt = $db->prepare("SELECT * FROM posts WHERE id = ? AND parent_id = 0");
    $op_stmt->bindValue(1, $thread_id, SQLITE3_INTEGER);
    $op = $op_stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$op) {
        return; // Thread not found
    }

    $replies_stmt = $db->prepare("SELECT * FROM posts WHERE parent_id = ? ORDER BY id ASC");
    $replies_stmt->bindValue(1, $thread_id, SQLITE3_INTEGER);
    $replies_q = $replies_stmt->execute();
    $replies = [];
    while ($row = $replies_q->fetchArray(SQLITE3_ASSOC)) {
        $replies[] = $row;
    }

    ob_start();
    render_thread_page($op, $replies);
    $html = ob_get_clean();
    file_put_contents($threads_dir . 'thread_' . $thread_id . '.html', $html, LOCK_EX);
}

function render_header(string $title): void {
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5); ?></title>
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
function setActiveStyleSheet(title) {
    var links = document.getElementsByTagName("link");
    for (var i = 0; i < links.length; i++) {
        var a = links[i];
        if(a.getAttribute("rel") && a.getAttribute("rel").indexOf("stylesheet") != -1 && a.getAttribute("title")) {
            a.disabled = true;
            if(a.getAttribute("title") === title) a.disabled = false;
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
<script type="text/javascript" src="/js/jquery.min.js"></script>
<script type="text/javascript" src="/js/main.js"></script>
<script type="text/javascript" src="/js/inline-expanding.js"></script>
<script type="text/javascript" src="/js/hide-form.js"></script>
</head>
<body class="visitor is-not-moderator active-index" data-stylesheet="default">
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
<header><h1>/<?php echo htmlspecialchars($GLOBALS['board_name'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5); ?>/ - Random</h1><div class="subtitle"></div></header>
    <?php
}

function render_footer(): void {
    ?>
<footer>
    <p class="unimportant" style="margin-top:20px;text-align:center;">
        All trademarks, copyrights,
        comments, and images on this page are owned by and are
        the responsibility of their respective parties.
    </p>
</footer>
<script type="text/javascript">ready();</script>
</body>
</html>
    <?php
}

function render_board_index($db, $results = null): void {
    $csrf_token = get_global_csrf_token();
    render_header('/' . $GLOBALS['board_name'] . '/ - Random');
    ?>
    <!-- Post Form -->
    <form name="post" onsubmit="return true;" enctype="multipart/form-data" action="chess.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5); ?>">
        <table>
            <tr>
                <th>Name</th>
                <td><input type="text" name="name" size="25" maxlength="35" autocomplete="off" required></td>
            </tr>
            <tr>
                <th>Subject</th>
                <td>
                    <input style="float:left;" type="text" name="subject" size="25" maxlength="100" autocomplete="off" required>
                    <input accesskey="s" style="margin-left:2px;" type="submit" name="post" value="New Topic" />
                </td>
            </tr>
            <tr>
                <th>Comment</th>
                <td><textarea name="body" id="body" rows="5" cols="35" required></textarea></td>
            </tr>
            <tr id="upload">
                <th>File</th>
                <td><input type="file" name="file" id="upload_file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4"></td>
            </tr>
        </table>
    </form>
    <hr />

    <form name="postcontrols" action="" method="post">
    <input type="hidden" name="board" value="<?php echo htmlspecialchars($GLOBALS['board_name'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5); ?>" />
    <?php
    if ($results instanceof SQLite3Result) {
        $dbx = init_db();
        while ($post = $results->fetchArray(SQLITE3_ASSOC)) {
            render_single_thread($dbx, $post);
        }
    } elseif (is_array($results)) {
        $dbx = init_db();
        foreach ($results as $post) {
            render_single_thread($dbx, $post);
        }
    } 
    // else no posts
    ?>
    <div id="post-moderation-fields">
        <div id="report-fields">
            <label for="reason">Reason</label> <input id="reason" type="text" name="reason" size="20" maxlength="30" />
            <input type="submit" name="report" value="Report" />
        </div>
    </div>
    </form>
    <?php
    render_footer();
}

function render_board_index_with_array(array $posts): void {
    $csrf_token = get_global_csrf_token();
    render_header('/' . $GLOBALS['board_name'] . '/ - Random');
    ?>
    <!-- Post Form -->
    <form name="post" onsubmit="return true;" enctype="multipart/form-data" action="chess.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5); ?>">
        <table>
            <tr>
                <th>Name</th>
                <td><input type="text" name="name" size="25" maxlength="35" autocomplete="off" required></td>
            </tr>
            <tr>
                <th>Subject</th>
                <td>
                    <input style="float:left;" type="text" name="subject" size="25" maxlength="100" autocomplete="off" required>
                    <input accesskey="s" style="margin-left:2px;" type="submit" name="post" value="New Topic" />
                </td>
            </tr>
            <tr>
                <th>Comment</th>
                <td><textarea name="body" id="body" rows="5" cols="35" required></textarea></td>
            </tr>
            <tr id="upload">
                <th>File</th>
                <td><input type="file" name="file" id="upload_file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4"></td>
            </tr>
        </table>
    </form>
    <hr />

    <form name="postcontrols" action="" method="post">
    <input type="hidden" name="board" value="<?php echo htmlspecialchars($GLOBALS['board_name'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5); ?>" />
    <?php
    $db = init_db();
    foreach ($posts as $post) {
        render_single_thread($db, $post);
    }
    ?>
    <div id="post-moderation-fields">
        <div id="report-fields">
            <label for="reason">Reason</label>
            <input id="reason" type="text" name="reason" size="20" maxlength="30" />
            <input type="submit" name="report" value="Report" />
        </div>
    </div>
    </form>
    <?php
    render_footer();
}

function render_single_thread(SQLite3 $db, array $post): void {
    global $threads_dir;
    $id = (int)$post['id'];
    $name = htmlspecialchars($post['name'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
    $subject = htmlspecialchars($post['subject'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
    $comment = nl2br(htmlspecialchars($post['comment'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5));

    $count_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM posts WHERE parent_id = ?");
    $count_stmt->bindValue(1, $id, SQLITE3_INTEGER);
    $count_res = $count_stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $reply_count = (int)($count_res['cnt'] ?? 0);

    $image_html = render_image_html($post['image']);
    $reply_link_text = $reply_count > 0 ? "Reply[".$reply_count."]" : "Reply";

    $thread_url = 'threads/thread_' . $id . '.html';

    echo '<div class="thread" id="thread_'.$id.'" data-board="'.htmlspecialchars($GLOBALS['board_name'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5).'">';
    echo $image_html;
    echo '<div class="post op" id="op_'.$id.'">
        <p class="intro">
            <input type="checkbox" class="delete" name="delete_'.$id.'" id="delete_'.$id.'" />
            <label for="delete_'.$id.'">';
    if (!empty($subject)) {
        echo '<span class="subject">'.$subject.'</span> ';
    }
    echo '<span class="name">'.$name.'</span>
            </label>&nbsp;
            <a href="'.$thread_url.'">'.$reply_link_text.'</a>
        </p>
        <div class="body">'.$comment.'</div>
    </div>
    <br class="clear"/>
    <hr/>
    </div>';
}

function render_thread_page(array $op, array $replies): void {
    global $board_name;
    $csrf_token = get_global_csrf_token();
    $thread_id = (int)$op['id'];
    render_header('/' . $board_name . '/ - Random');
    ?>
    <div class="banner">Posting mode: Reply <a class="unimportant" href="../index.html">[Return]</a> <a class="unimportant" href="#bottom">[Go to bottom]</a></div>

    <form name="post" onsubmit="return true;" action="../reply.php?thread_id=<?php echo $thread_id; ?>" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5); ?>">
        <input type="hidden" name="thread" value="<?php echo $thread_id; ?>">
        <input type="hidden" name="board" value="<?php echo htmlspecialchars($board_name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5); ?>">
        <table>
            <tr>
                <th>Name</th>
                <td><input type="text" name="name" size="25" maxlength="35" autocomplete="off" required></td>
            </tr>
            <tr>
                <th>Comment</th>
                <td>
                    <textarea name="body" id="body" rows="5" cols="35" required></textarea>
                    <input accesskey="s" style="margin-left:2px;" type="submit" name="post" value="New Reply" />
                </td>
            </tr>
        </table>
    </form>
    <hr />

    <form name="postcontrols" action="" method="post">
    <input type="hidden" name="board" value="<?php echo htmlspecialchars($board_name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5); ?>" />

    <div class="thread" id="thread_<?php echo $thread_id; ?>" data-board="<?php echo htmlspecialchars($board_name, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5); ?>">
        <?php
        $op_img_html = render_image_html($op['image']);
        $op_name = htmlspecialchars($op['name'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
        $op_subject = htmlspecialchars($op['subject'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
        $op_comment = nl2br(htmlspecialchars($op['comment'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5));

        echo '<a id="'. $thread_id .'" class="post_anchor"></a>';
        echo $op_img_html;
        echo '<div class="post op" id="op_'.$thread_id.'"><p class="intro">
        <input type="checkbox" class="delete" name="delete_'.$thread_id.'" id="delete_'.$thread_id.'" />
        <label for="delete_'.$thread_id.'">';
        if (!empty($op_subject)) {
            echo '<span class="subject">'.$op_subject.'</span> ';
        }
        echo '<span class="name">'.$op_name.'</span></label>&nbsp;</p>
        <div class="body">'.$op_comment.'</div></div>';

        $reply_num = 0;
        foreach ($replies as $r) {
            $reply_num++;
            $r_id = (int)$r['id'];
            $r_name = htmlspecialchars($r['name'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
            $r_comment = nl2br(htmlspecialchars($r['comment'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5));
            echo '<div class="post reply" id="reply_'.$r_id.'">
            <p class="intro">
            <a id="'.$r_id.'" class="post_anchor"></a>
            <input type="checkbox" class="delete" name="delete_'.$r_id.'" id="delete_'.$r_id.'" />
            <label for="delete_'.$r_id.'"><span class="name">'.$r_name.'</span></label>&nbsp;
            </p>
            <div class="files"></div>
            <div class="body" style="text-align:center;font-weight:bold;">Reply '.$reply_num.'</div>
            <div class="body">'.$r_comment.'</div></div><br/>';
        }
        ?>
        <br class="clear"/>
        <hr/>
    </div>

    <div id="thread-interactions">
        <span id="thread-links">
            <a id="thread-return" href="../index.html">[Return]</a>
            <a id="thread-top" href="#top">[Go to top]</a>
        </span>
        <span id="thread-quick-reply"><a id="link-quick-reply" href="#">[Post a Reply]</a></span>
        <div id="post-moderation-fields">
            <div id="report-fields">
                <label for="reason">Reason</label>
                <input id="reason" type="text" name="reason" size="20" maxlength="30" />
                <input type="submit" name="report" value="Report" />
            </div>
        </div>
    </div>
    <div class="clearfix"></div>
    </form>
    <a name="bottom"></a>
    <?php
    render_footer();
}

function render_image_html(?string $image): string {
    if (!$image) {
        return '';
    }
    global $allowed_exts;
    $image_ext = strtolower(pathinfo($image, PATHINFO_EXTENSION));
    if (!in_array($image_ext, $allowed_exts, true)) {
        return '';
    }

    $full_path = __DIR__ . '/uploads/' . $image;
    if (!file_exists($full_path)) {
        return '';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($full_path);

    $allowed_mimes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4'
    ];
    if (!isset($allowed_mimes[$image_ext]) || $allowed_mimes[$image_ext] !== $mime) {
        return ''; // MIME doesn't match expected type
    }

    $img_path = 'uploads/' . htmlspecialchars($image, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);

    if (in_array($image_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return '
        <div class="files">
            <div class="file">
                <p class="fileinfo">File: <a href="'.$img_path.'">'.htmlspecialchars(basename($image), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5).'</a></p>
                <a href="'.$img_path.'" target="_blank"><img class="post-image" src="'.$img_path.'" style="width:255px;height:auto" alt="" /></a>
            </div>
        </div>';
    } elseif ($image_ext === 'mp4') {
        return '
        <div class="files">
            <div class="file">
                <p class="fileinfo">File: <a href="'.$img_path.'">'.htmlspecialchars(basename($image), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5).'</a></p>
                <video width="255" controls>
                    <source src="'.$img_path.'" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
        </div>';
    }
    return '';
}
