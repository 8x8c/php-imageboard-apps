
<?php
$msj = htmlentities($_GET["msj"], ENT_QUOTES | ENT_IGNORE, "UTF-8");
$post = dechex(rand());
$color = rand(0,6);

switch ($color) {
    case 0:
        $back = '#aaffff';
        break;
    case 1:
    case 2:
        $back = '#ffaaff';
        break;
    case 3:
        $back = '#ccccff';
        break;
    case 4:
        $back = '#ffcccc';
        break;
    case 5:
        $back = '#ffccff';
        break;
    default:
        $back = '#ffffaa';
        break;
}

if (!empty($_GET["msj"])) {
    // Quoting feature removed
    /*
    $msj = str_replace("[[","<a href=\"#", $msj);
    $msj = str_replace("]]","\">another post</a>", $msj);
    */
    
    $bbs = '<div style="background-color:'.$back.';"><a name="' . $post . '"><b>' . $post . '</b></a> - ' . $msj . '</div>';
    $bbs .= file_get_contents('bbs.htm');
    file_put_contents('bbs.htm', $bbs);
}

echo '<meta http-equiv="pragma" content="no-cache" />';
echo '<div style="background-color: #000; color: #ccc; text-align: center;">
        <br>
        <b>Submit a message directly in the URL bar via <code>index.php?msj=your-message</code></b>
        <br><br>
      </div><br>';

$file = fopen("bbs.htm", "r");
while(!feof($file)) {
    echo fgets($file). "<br />";
}
fclose($file);
?>
