<?php
declare(strict_types=1);

$phrase = 'invalid';
if (isset($_GET['key'])) {
    $phrase = substr(md5($_GET['key']), 0, 4);
}

$im = imagecreatetruecolor(37, 18);
imagesavealpha($im, true);
$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
imagefill($im, 0, 0, $transparent);
$textColor = imagecolorallocate($im, 255, 255, 255);
imagestring($im, 4, 4, 0, $phrase, $textColor);

header('Content-type: image/png');
imagepng($im);
imagedestroy($im);
