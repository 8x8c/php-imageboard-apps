<?php
declare(strict_types=1);

use PDO;

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');

// Force session cookie to be secure (HTTPS).
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,       // IMPORTANT: set to true because you're using HTTPS
    'httponly' => true,
    'samesite' => 'Strict',
]);

session_start([
    'use_strict_mode' => 1,
    'cookie_secure'   => true, // also ensure HTTPS
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'read_and_close'  => false
]);

// Database credentials
$db_host = 'localhost';
$db_port = '3306';
$db_name = 'changemeeeeeee';
$db_user = 'changemeeeeeee';
$db_pass = 'changemeeeeeee';

// Directories for file uploads and thread pages
$upload_dir  = __DIR__ . '/uploads/';
$threads_dir = __DIR__ . '/threads/';

// Pagination settings
$threads_per_page   = 20;
$replies_per_thread = 5;

// Allowed file extensions
$allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'];

// CSRF file
$csrf_file = __DIR__ . '/csrf_secret.txt';

// Bcrypt-hashed admin password
$admin_password_hash = '$2y$10$GaGhLMlWEB0vA5PhwjzoGumQ3wiuGfdyMQSYyAAwhlzw9nt5Ocjia';

// Pepper for tripcode
$tripcode_pepper = 'replace_this_with_random_pepper';
