<?php
session_start();

// CAPTCHA Configuration
$captcha_enabled = false; // Set to false to disable CAPTCHA for testing

// Generate a CAPTCHA value (random number) if not already set or after each load
if ($captcha_enabled && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['captcha'] = rand(10000, 99999);
}

$msj = isset($_POST["msj"]) ? htmlspecialchars($_POST["msj"], ENT_QUOTES, "UTF-8") : "";
$captcha_input = isset($_POST["captcha"]) ? intval($_POST["captcha"]) : 0;
$expected_captcha = isset($_SESSION['captcha']) ? $_SESSION['captcha'] : 0;
$post = dechex(rand());

// Define a set of colors to cycle through
$colors = [
    '#2b2d42', '#3a86ff', '#8338ec', '#ff006e', '#ff6700',
    '#003049', '#d62828', '#264653', '#1d3557', '#457b9d',
    '#2a9d8f', '#606c38', '#3d5a80', '#6a4c93', '#4a4e69'
];

// Load the last used color index from a file
$color_index_file = 'a.txt';

$last_color_index = -1; // Default value if file doesn't exist
if (file_exists($color_index_file)) {
    $file = fopen($color_index_file, 'r');
    if (flock($file, LOCK_SH)) { // Shared lock for reading
        $last_color_index = intval(fgets($file));
        flock($file, LOCK_UN); // Release the lock
    }
    fclose($file);
}

// Determine the next color index and ensure it is not the same as the last one
$color_index = ($last_color_index + 1) % count($colors);
$back = $colors[$color_index];

if (!empty($msj) && (!$captcha_enabled || $captcha_input === $expected_captcha)) {
    // Load current contents of bbs.htm
    $current_bbs_content = file_get_contents('bbs.htm');
    $new_message = '<div style="background-color:'.$back.'; color: #fff; padding: 10px; margin-bottom: 5px; border-radius: 5px;"><a name="' . $post . '"><b>' . $post . '</b></a> - ' . $msj . '</div>';

    // Add the new message to the board
    $bbs = $new_message . "\n" . $current_bbs_content;
    file_put_contents('bbs.htm', $bbs);

    // Save the current color index for the next post with file locking
    $file = fopen($color_index_file, 'w');
    if (flock($file, LOCK_EX)) { // Exclusive lock for writing
        fwrite($file, $color_index);
        flock($file, LOCK_UN); // Release the lock
    }
    fclose($file);
    
    // Redirect to avoid form resubmission issues
    header("Location: index.php");
    exit;
}

// Ensure the page loads correctly without redirect loops
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET)) {
    echo '<meta http-equiv="pragma" content="no-cache" />
<style>
    body {
        background-color: #111;
        color: #ccc;
    }
</style>';
    echo '<div style="background-color: #111; color: #ccc; text-align: center; padding: 20px;">
            <form method="post" action="index.php" style="margin-bottom: 20px;">
                <textarea name="msj" rows="3" style="width: 80%; max-width: 600px; padding: 10px; border-radius: 5px; background-color: #333; color: #ccc;" placeholder="Write your message here..." required></textarea><br><br>';

    if ($captcha_enabled) {
        echo '<label for="captcha">' . $_SESSION['captcha'] . '</label>
                <input type="text" id="captcha" name="captcha" required style="width: 80px; text-align: center; background-color: #333; color: #ccc; border: 1px solid #555;"><br><br>';
    }

    echo '<input type="submit" value="Send Message" style="padding: 10px 20px; border-radius: 5px; background-color: #4CAF50; color: white; border: none; cursor: pointer;">
            </form>
          </div><br>';

    if (file_exists("bbs.htm")) {
        echo '<div style="background-color: #111; padding: 20px;">';
        $file = fopen("bbs.htm", "r");
        while (!feof($file)) {
            echo fgets($file);
        }
        fclose($file);
        echo '</div>';
    }
} else if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}
?>
