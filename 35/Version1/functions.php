<?php
declare(strict_types=1);

use PDO;

/**
 * Initialize a PDO connection to MySQL/MariaDB.
 */
function init_db(
    string $host,
    string $port,
    string $name,
    string $user,
    string $pass
): PDO {
    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
    return $pdo;
}

/**
 * Generate or retrieve a global CSRF token from a file.
 */
function get_global_csrf_token(string $csrf_file): string
{
    if (!file_exists($csrf_file)) {
        $token = bin2hex(random_bytes(32));
        file_put_contents($csrf_file, $token, LOCK_EX);
        return $token;
    }
    $token = trim((string)file_get_contents($csrf_file));
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        file_put_contents($csrf_file, $token, LOCK_EX);
    }
    return $token;
}

/**
 * Verify the CSRF token from a POST request against the global token.
 */
function verify_csrf_token(string $csrf_file): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token        = $_POST['csrf_token'] ?? '';
        $global_token = get_global_csrf_token($csrf_file);
        if (!hash_equals($global_token, $token)) {
            http_response_code(403);
            exit('Invalid CSRF token.');
        }
    }
}

/**
 * Sanitize input with a maximum length.
 */
function sanitize_input(string $input, int $maxLength = 1000): string
{
    $clean = strip_tags(trim($input));
    if (mb_strlen($clean) > $maxLength) {
        $clean = mb_substr($clean, 0, $maxLength);
    }
    return $clean;
}

/**
 * Fetch a single board by its name.
 */
function get_board_by_name(PDO $db, string $board_name): ?array
{
    $stmt = $db->prepare("SELECT * FROM boards WHERE name = :name LIMIT 1");
    $stmt->bindValue(':name', $board_name, PDO::PARAM_STR);
    $stmt->execute();
    $board = $stmt->fetch(PDO::FETCH_ASSOC);
    return $board ?: null;
}

/**
 * Fetch all boards.
 */
function get_boards(PDO $db): array
{
    $stmt = $db->query("SELECT * FROM boards ORDER BY id");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Table name for posts.
 */
function get_table_name(): string
{
    return 'posts';
}

/**
 * Fetch the board's name by its ID.
 */
function get_board_name_by_id(PDO $db, int $board_id): string
{
    $stmt = $db->prepare("SELECT name FROM boards WHERE id=:id");
    $stmt->bindValue(':id', $board_id, PDO::PARAM_INT);
    $stmt->execute();
    return (string)$stmt->fetchColumn();
}

/**
 * Given a thread ID, return which board ID it belongs to.
 */
function get_board_id_by_thread(PDO $db, int $thread_id): ?int
{
    $table = get_table_name();
    $stmt = $db->prepare("SELECT board_id FROM {$table} WHERE id = :id");
    $stmt->bindValue(':id', $thread_id, PDO::PARAM_INT);
    $stmt->execute();
    $board_id = $stmt->fetchColumn();
    return $board_id === false ? null : (int)$board_id;
}

/**
 * Generate all index pages for a given board.
 */
function generate_all_index_pages(PDO $db, int $board_id, int $threads_per_page): void
{
    $table = get_table_name();

    // Count how many threads exist
    $count_stmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM {$table} 
        WHERE board_id = :bid 
          AND parent_id = 0 
          AND deleted = false
    ");
    $count_stmt->bindValue(':bid', $board_id, PDO::PARAM_INT);
    $count_stmt->execute();
    $total_threads = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages   = $total_threads > 0 ? (int)ceil($total_threads / $threads_per_page) : 1;

    // Generate each page
    for ($p = 1; $p <= $total_pages; $p++) {
        generate_static_index($db, $board_id, $p, $threads_per_page);
    }
}

/**
 * Generate a single static index page for a board.
 */
function generate_static_index(PDO $db, int $board_id, int $page, int $threads_per_page): void
{
    $table = get_table_name();

    // Count total threads
    $count_stmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM {$table} 
        WHERE board_id = :bid 
          AND parent_id = 0 
          AND deleted = false
    ");
    $count_stmt->bindValue(':bid', $board_id, PDO::PARAM_INT);
    $count_stmt->execute();
    $total_threads = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages   = $total_threads > 0 ? (int)ceil($total_threads / $threads_per_page) : 1;

    if ($page > $total_pages) {
        $page = $total_pages;
    }

    $offset = ($page - 1) * $threads_per_page;

    // Fetch threads (OP posts) for this page
    $stmt = $db->prepare("
        SELECT * 
        FROM {$table} 
        WHERE board_id = :bid 
          AND parent_id = 0 
          AND deleted = false
        ORDER BY datetime DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':bid',    $board_id,         PDO::PARAM_INT);
    $stmt->bindValue(':limit',  $threads_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,           PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $board_name = get_board_name_by_id($db, $board_id);

    ob_start();
    if (count($posts) > 0) {
        render_board_index_with_array($db, $board_name, $posts, $page, $total_pages, $threads_per_page);
    } else {
        render_board_index($db, $board_name, null);
    }
    $html = ob_get_clean();

    $dir = __DIR__ . '/' . $board_name;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = $page === 1 ? 'index.html' : 'index_' . $page . '.html';
    file_put_contents($dir . '/' . $filename, $html, LOCK_EX);
}

/**
 * Generate or remove the static HTML file for a given thread.
 */
function generate_static_thread(PDO $db, int $thread_id): void
{
    $table = get_table_name();

    // Fetch the OP post
    $op_stmt = $db->prepare("
        SELECT * 
        FROM {$table} 
        WHERE id = :id 
          AND parent_id = 0 
          AND deleted = false
    ");
    $op_stmt->bindValue(':id', $thread_id, PDO::PARAM_INT);
    $op_stmt->execute();
    $op = $op_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$op) {
        // Thread not found or already deleted => remove any existing static thread file
        $board_id   = get_board_id_by_thread($db, $thread_id);
        if ($board_id !== null) {
            $board_name  = get_board_name_by_id($db, $board_id);
            $thread_file = __DIR__ . "/{$board_name}/threads/thread_{$thread_id}.html";
            if (file_exists($thread_file)) {
                unlink($thread_file);
            }
        }
        return;
    }

    $board_id   = (int)$op['board_id'];
    $board_name = get_board_name_by_id($db, $board_id);

    // Get all replies
    $replies_stmt = $db->prepare("
        SELECT * 
        FROM {$table} 
        WHERE parent_id = :pid 
          AND deleted = false 
        ORDER BY id ASC
    ");
    $replies_stmt->bindValue(':pid', $thread_id, PDO::PARAM_INT);
    $replies_stmt->execute();
    $replies = $replies_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Render
    ob_start();
    render_thread_page($db, $board_name, $op, $replies);
    $html = ob_get_clean();

    $threads_dir = __DIR__ . "/{$board_name}/threads/";
    if (!is_dir($threads_dir)) {
        mkdir($threads_dir, 0755, true);
    }
    file_put_contents($threads_dir . 'thread_' . $thread_id . '.html', $html, LOCK_EX);
}

/**
 * Render the HTML header for a page (index or thread).
 * Minimal CSP + nosniff + X-Frame-Options for example. 
 * Expand as needed.
 */
function render_header(string $board_name, string $title, string $page_type = 'index'): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    // Basic example CSP; adjust or remove script/style restrictions if needed
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';");

    $board_name_js = htmlspecialchars($board_name, ENT_QUOTES);

    echo '<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>', htmlspecialchars($title, ENT_QUOTES), '</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=yes">
    <link rel="stylesheet" href="/css/style.css" type="text/css" media="screen">
    <link rel="stylesheet" href="/css/font-awesome/css/font-awesome.min.css">
    <script>
        const active_page = "', $page_type, '";
        const board_name  = "', $board_name_js, '";
        function setActiveStyleSheet(title) { /* omitted for brevity */ }
        window.addEventListener("load", () => {
            /* Load last used style if stored in localStorage */
        });
    </script>
</head>
<body class="visitor is-not-moderator active-', htmlspecialchars($page_type, ENT_QUOTES), '">
<header>
    <h1>/', $board_name_js, '/ - Random</h1>
    <div class="subtitle"></div>
</header>';
}

/**
 * Render the HTML footer for a page (index or thread).
 */
function render_footer(): void
{
    echo '<footer>
        <p>All trademarks and copyrights on this page are owned 
           by their respective parties. This site is for demonstration only.</p>
    </footer>
    <div id="home-button">
        <a href="/">Home</a>
    </div>
</body>
</html>';
}

/**
 * Renders a board index page when no threads exist OR using a PDOStatement.
 */
function render_board_index(PDO $db, ?string $board_name, ?\PDOStatement $results = null): void
{
    global $csrf_file;

    if (!$board_name || !preg_match('/^[a-zA-Z0-9_-]+$/', $board_name)) {
        exit('Invalid board name.');
    }

    $csrf_token = htmlspecialchars(get_global_csrf_token($csrf_file), ENT_QUOTES);
    render_header($board_name, "/{$board_name}/ - Random", 'index');

    echo '<form name="post" enctype="multipart/form-data" action="chess.php" method="post">
        <input type="hidden" name="csrf_token" value="', $csrf_token, '">
        <table>
            <tr><th>Name</th><td><input type="text" name="name" required maxlength="35"></td></tr>
            <tr><th>Subject</th>
                <td>
                    <input type="text" name="subject" required maxlength="100">
                    <input type="submit" name="post" value="New Topic">
                </td>
            </tr>
            <tr><th>Comment</th><td><textarea name="body" rows="5" cols="35" required></textarea></td></tr>
            <tr><th>File</th><td><input type="file" name="file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4"></td></tr>
        </table>
    </form>
    <hr>';

    if ($results instanceof \PDOStatement) {
        while ($post = $results->fetch(PDO::FETCH_ASSOC)) {
            render_single_thread($db, $board_name, $post, 5); 
        }
    }

    render_footer();
}

/**
 * Renders a board index page from an array of posts, plus pagination.
 */
function render_board_index_with_array(
    PDO $db,
    string $board_name,
    array $posts,
    int $page,
    int $total_pages,
    int $threads_per_page
): void {
    global $csrf_file;

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $board_name)) {
        exit('Invalid board name.');
    }
    $csrf_token = htmlspecialchars(get_global_csrf_token($csrf_file), ENT_QUOTES);

    render_header($board_name, "/{$board_name}/ - Random", 'index');

    echo '<form name="post" enctype="multipart/form-data" action="chess.php" method="post">
        <input type="hidden" name="csrf_token" value="', $csrf_token, '">
        <table>
            <tr><th>Name</th><td><input type="text" name="name" required maxlength="35"></td></tr>
            <tr><th>Subject</th>
                <td>
                    <input type="text" name="subject" required maxlength="100">
                    <input type="submit" name="post" value="New Topic">
                </td>
            </tr>
            <tr><th>Comment</th><td><textarea name="body" rows="5" cols="35" required></textarea></td></tr>
            <tr><th>File</th><td><input type="file" name="file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4"></td></tr>
        </table>
    </form>
    <hr>';

    foreach ($posts as $post) {
        render_single_thread($db, $board_name, $post, 5);
    }

    // Simple pagination
    echo '<div class="pagination">';
    if ($page > 1) {
        $prev_page  = $page - 1;
        $prev_link  = ($prev_page === 1) ? 'index.html' : 'index_' . $prev_page . '.html';
        echo '<a href="', $prev_link, '">Previous</a> ';
    }

    for ($i = 1; $i <= $total_pages; $i++) {
        $page_link = ($i === 1) ? 'index.html' : 'index_' . $i . '.html';
        if ($i === $page) {
            echo '<strong>', $i, '</strong> ';
        } else {
            echo '<a href="', $page_link, '">', $i, '</a> ';
        }
    }

    if ($page < $total_pages) {
        $next_page = $page + 1;
        $next_link = 'index_' . $next_page . '.html';
        echo ' <a href="', $next_link, '">Next</a>';
    }
    echo '</div>';

    render_footer();
}

/**
 * Render a single thread (OP + snippet of recent replies) on the board index page.
 */
function render_single_thread(PDO $db, string $board_name, array $post, int $replies_to_show): void
{
    $table    = get_table_name();
    $id       = (int)($post['id'] ?? 0);
    $name     = htmlspecialchars($post['name'] ?? '', ENT_QUOTES);
    $subject  = htmlspecialchars($post['subject'] ?? '', ENT_QUOTES);
    $comment  = nl2br(htmlspecialchars($post['comment'] ?? '', ENT_QUOTES));
    if ($id === 0) {
        return;
    }

    // Fetch the latest $replies_to_show replies
    $replies_stmt = $db->prepare("
        SELECT *
        FROM {$table}
        WHERE parent_id = :pid
          AND deleted = false
        ORDER BY id DESC
        LIMIT :limit
    ");
    $replies_stmt->bindValue(':pid',   $id,             PDO::PARAM_INT);
    $replies_stmt->bindValue(':limit', $replies_to_show, PDO::PARAM_INT);
    $replies_stmt->execute();
    $recent_replies = array_reverse($replies_stmt->fetchAll(PDO::FETCH_ASSOC));

    // Count total replies
    $count_stmt = $db->prepare("
        SELECT COUNT(*) as cnt
        FROM {$table}
        WHERE parent_id = :pid
          AND deleted = false
    ");
    $count_stmt->bindValue(':pid', $id, PDO::PARAM_INT);
    $count_stmt->execute();
    $reply_count = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

    $image_html       = render_image_html($board_name, $post['image'] ?? '');
    $reply_link_text  = $reply_count > 0 ? "Reply [{$reply_count}]" : "Reply";
    $thread_url       = "threads/thread_{$id}.html";

    echo '<div class="thread" id="thread_', $id, '" data-board="', htmlspecialchars($board_name, ENT_QUOTES), '">';
    echo $image_html;
    echo '<div class="post op" id="op_', $id, '">
            <p class="intro">';

    // Subject
    if (!empty($subject)) {
        echo '<span class="subject">', $subject, '</span> ';
    }
    // Name
    echo '<span class="name">', $name, '</span>
          &nbsp;<a href="', $thread_url, '">', $reply_link_text, '</a>
          </p>
          <div class="body">', $comment, '</div>';

    // Show the $replies_to_show last replies
    $reply_num_start = ($reply_count - count($recent_replies)) + 1;

    foreach ($recent_replies as $index => $r) {
        $r_id       = (int)$r['id'];
        $r_name     = htmlspecialchars($r['name'] ?? '', ENT_QUOTES);
        $r_comment  = nl2br(htmlspecialchars($r['comment'] ?? '', ENT_QUOTES));
        $r_img_html = render_image_html($board_name, $r['image'] ?? '');

        echo '<div class="post reply" id="reply_', $r_id, '">
                <div class="body bold-center">Reply ', $reply_num_start + $index, '</div>
                <p class="intro">
                    <a id="', $r_id, '" class="post_anchor"></a>
                    <input type="checkbox" class="delete" name="delete_', $r_id, '" id="delete_', $r_id, '">
                    <label for="delete_', $r_id, '"><span class="name">', $r_name, '</span></label>
                </p>',
                $r_img_html,
                '<div class="body">', $r_comment, '</div>
            </div><br>';
    }

    // If there are more replies, say so
    $remaining = $reply_count - $replies_to_show;
    if ($remaining > 0) {
        echo '<div class="post reply"><em>... and ', $remaining, ' more replies.</em></div><br>';
    }

    echo '</div><br class="clear"><hr></div>';
}

/**
 * Renders a single thread page (OP + all replies).
 */
function render_thread_page(PDO $db, string $board_name, array $op, array $replies): void
{
    global $admin_password, $csrf_file;
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $board_name)) {
        exit('Invalid board name.');
    }

    $csrf_token = htmlspecialchars(get_global_csrf_token($csrf_file), ENT_QUOTES);
    $thread_id  = (int)$op['id'];
    if ($thread_id === 0) {
        return;
    }

    render_header($board_name, "/{$board_name}/ - Random", 'thread');

    echo '<div class="banner">
            Posting mode: Reply 
            <a class="unimportant" href="../index.html">[Return]</a> 
            <a class="unimportant" href="#bottom">[Go to bottom]</a>
        </div>

        <form name="post" action="../reply.php?thread_id=', $thread_id, '&board=', urlencode($board_name), '" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="', $csrf_token, '">
            <input type="hidden" name="thread" value="', $thread_id, '">
            <input type="hidden" name="board" value="', htmlspecialchars($board_name, ENT_QUOTES), '">
            <table>
                <tr>
                    <th>Name</th>
                    <td><input type="text" name="name" required maxlength="35" autocomplete="off"></td>
                </tr>
                <tr>
                    <th>Comment</th>
                    <td>
                        <textarea name="body" rows="5" cols="35" required></textarea>
                        <input style="margin-left:2px;" type="submit" name="post" value="New Reply">
                    </td>
                </tr>
                <tr><th>File</th><td><input type="file" name="file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4"></td></tr>
            </table>
        </form>
        <hr>

        <form name="postcontrols" action="../reply.php?thread_id=', $thread_id, '&board=', urlencode($board_name), '" method="post">
            <input type="hidden" name="csrf_token" value="', $csrf_token, '">
            <input type="hidden" name="board" value="', htmlspecialchars($board_name, ENT_QUOTES), '">

            <div class="thread" id="thread_', $thread_id, '" data-board="', htmlspecialchars($board_name, ENT_QUOTES), '">';

    $op_img_html = render_image_html($board_name, $op['image'] ?? '');
    $op_name     = htmlspecialchars($op['name'] ?? '', ENT_QUOTES);
    $op_subject  = htmlspecialchars($op['subject'] ?? '', ENT_QUOTES);
    $op_comment  = nl2br(htmlspecialchars($op['comment'] ?? '', ENT_QUOTES));

    echo '<a id="', $thread_id, '" class="post_anchor"></a>',
         $op_img_html,
         '<div class="post op" id="op_', $thread_id, '">
            <p class="intro">
                <input type="checkbox" class="delete" name="delete_', $thread_id, '" id="delete_', $thread_id, '">
                <label for="delete_', $thread_id, '">';

    if (!empty($op_subject)) {
        echo '<span class="subject">', $op_subject, '</span> ';
    }
    echo '<span class="name">', $op_name, '</span>
          </label>
          </p>
          <div class="body">', $op_comment, '</div>
        </div>';

    $reply_num = 0;
    foreach ($replies as $r) {
        $reply_num++;
        $r_id       = (int)$r['id'];
        $r_name     = htmlspecialchars($r['name'] ?? '', ENT_QUOTES);
        $r_comment  = nl2br(htmlspecialchars($r['comment'] ?? '', ENT_QUOTES));
        $r_img_html = render_image_html($board_name, $r['image'] ?? '');

        echo '<div class="post reply" id="reply_', $r_id, '">
                <div class="body bold-center">Reply ', $reply_num, '</div>
                <p class="intro">
                    <a id="', $r_id, '" class="post_anchor"></a>
                    <input type="checkbox" class="delete" name="delete_', $r_id, '" id="delete_', $r_id, '">
                    <label for="delete_', $r_id, '"><span class="name">', $r_name, '</span></label>
                </p>',
             $r_img_html,
             '<div class="body">', $r_comment, '</div>
              </div><br>';
    }

    echo '<br class="clear"><hr></div>
          <div class="admin-controls">
              <label for="admin_pw">Admin Password:</label>
              <input type="text" name="admin_pw" id="admin_pw" required>
              <input type="submit" name="delete_selected" value="Delete">
          </div>
          <div class="clearfix"></div>
        </form>
        <a name="bottom"></a>';

    render_footer();
}

/**
 * Render the <img> or <video> tag for an attached file, if present.
 */
function render_image_html(string $board_name, ?string $image): string
{
    global $allowed_exts;
    if (!$image) {
        return '';
    }

    $image_ext = strtolower(pathinfo($image, PATHINFO_EXTENSION));
    if (!in_array($image_ext, $allowed_exts, true)) {
        return '';
    }

    $full_path = __DIR__ . '/' . $board_name . '/uploads/' . $image;
    if (!file_exists($full_path)) {
        return '';
    }

    $finfo   = new \finfo(FILEINFO_MIME_TYPE);
    $mime    = $finfo->file($full_path);
    $allowed_mimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'mp4'  => 'video/mp4'
    ];

    if (!isset($allowed_mimes[$image_ext]) || $allowed_mimes[$image_ext] !== $mime) {
        return '';
    }

    // Build public URL
    $img_path = '/' . htmlspecialchars($board_name, ENT_QUOTES) . '/uploads/' . htmlspecialchars($image, ENT_QUOTES);

    // Return either <img> or <video>
    if (in_array($image_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return '<div class="files">
                    <div class="file">
                        <a href="' . $img_path . '" target="_blank">
                            <img class="post-image" src="' . $img_path . '" alt=""/>
                        </a>
                    </div>
                </div>';
    } elseif ($image_ext === 'mp4') {
        return '<div class="files">
                    <div class="file">
                        <video class="post-video" controls>
                            <source src="' . $img_path . '" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>
                    </div>
                </div>';
    }
    return '';
}
