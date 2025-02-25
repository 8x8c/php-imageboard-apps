<?php
session_start();

// Create an image
$image = imagecreatetruecolor(150, 50);

// Allocate colors
$bg_color = imagecolorallocate($image, 255, 255, 255);
$text_color = imagecolorallocate($image, 0, 0, 0);
$line_color = imagecolorallocate($image, 64, 64, 64);

// Fill the background
imagefilledrectangle($image, 0, 0, 150, 50, $bg_color);

// Add some random lines
for ($i = 0; $i < 3; $i++) {
    imageline($image, 0, rand() % 50, 150, rand() % 50, $line_color);
}

// Generate a random string
$letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
$len = strlen($letters);
$captcha_text = '';
for ($i = 0; $i < 5; $i++) {
    $captcha_text .= $letters[rand(0, $len - 1)];
}

// Store the text in a session variable
$_SESSION['captcha_text'] = $captcha_text;

// Add the text to the image
imagestring($image, 5, 35, 15, $captcha_text, $text_color);

// Output the image as a PNG
header('Content-type: image/png');
imagepng($image);
imagedestroy($image);
?>
