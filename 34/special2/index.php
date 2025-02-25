<?php
$msj = htmlentities($_GET["msj"], ENT_QUOTES | ENT_IGNORE, "UTF-8");
$post = dechex(rand());

// Define a set of 10 colors to cycle through
$colors = [
    '#aaffff', '#ffaaff', '#ccccff', '#ffcccc', '#ffccff',
    '#ffffaa', '#aaffaa', '#ffaaaa', '#aaaaff', '#ffd700'
];

// Load the last used color index from a file
$color_index_file = 'last_color_index.txt';

$last_color_index = -1; // Default value if file doesn't exist
if (file_exists($color_index_file)) {
    $file = fopen($color_index_file, 'r');
    if (flock($file, LOCK_SH)) { // Shared lock for reading
        $last_color_index = intval(fgets($file));
        flock($file, LOCK_UN); // Release the lock
    }
    fclose($file);
}

// Determine the next color index
$color_index = ($last_color_index + 1) % count($colors);
$back = $colors[$color_index];

if (!empty($_GET["msj"])) {
    // Load current contents of bbs.htm to determine the current color index
    $current_bbs_content = file_get_contents('bbs.htm');
    $existing_messages = explode("\n", $current_bbs_content);

    // Check if the message already exists at the top by matching both post identifier and message content
    $first_message = isset($existing_messages[0]) ? $existing_messages[0] : '';
    if (strpos($first_message, $msj) === false) {
        // Quoting feature removed
        /*
        $msj = str_replace("[[","<a href=\"#", $msj);
        $msj = str_replace("]]","\">another post</a>", $msj);
        */
        
        $bbs = '<div style="background-color:'.$back.';"><a name="' . $post . '"><b>' . $post . '</b></a> - ' . $msj . '</div>';
        $bbs .= $current_bbs_content;
        file_put_contents('bbs.htm', $bbs);
    }

    // Save the current color index for the next post with file locking
    $file = fopen($color_index_file, 'w');
    if (flock($file, LOCK_EX)) { // Exclusive lock for writing
        fwrite($file, $color_index);
        flock($file, LOCK_UN); // Release the lock
    }
    fclose($file);
    
    // Redirect to clear the message from the URL and keep the format for easy message submission
    header("Location: index.php?msj=");
    exit;
}

// Redirect to ensure the URL always contains index.php?msj=
if (!isset($_GET["msj"])) {
    header("Location: index.php?msj=");
    exit;
}

echo '<meta http-equiv="pragma" content="no-cache" />';
echo '<div style="background-color: #000; color: #ccc; text-align: center;">
        <br>
        <b>URL bar shows <code>index.php?msj=</code> write your message at the end of that after the <code>=</code></b>
        <br><br>
      </div><br>';

$file = fopen("bbs.htm", "r");
while(!feof($file)) {
    echo fgets($file);
}
fclose($file);
?>
