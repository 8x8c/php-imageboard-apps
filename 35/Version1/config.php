<?php
declare(strict_types=1);

use PDO;

error_reporting(E_ALL);
ini_set('display_errors', '0');  // Turn off display in production
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');

// Session cookie settings
session_set_cookie_params([
    'lifetime' => 0,                // session expires when the browser closes
    'path' => '/',                  // adjust as needed
    'domain' => '',                 // set your domain if needed
    'secure' => false,              // MUST be true if you serve over HTTPS
    'httponly' => true,             // helps mitigate XSS
    'samesite' => 'Strict',         // or 'Lax' if you have cross-site usage
]);

session_start([
    'use_strict_mode' => 1,
    'cookie_secure'   => false, // set to true if using HTTPS
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'read_and_close'  => false  // we might need to write to session
]);

// Database credentials for MariaDB/MySQL
$db_host = 'localhost';
$db_port = '3306';
$db_name = 'chess';
$db_user = 'chess';
$db_pass = 'WRjVMC99bG6jtUFMWhED';

// Directories for file uploads and thread pages
$upload_dir = __DIR__ . '/uploads/';
$threads_dir = __DIR__ . '/threads/';

// Pagination settings
$threads_per_page = 20;      // Number of threads per board index page
$replies_per_thread = 5;     // Number of replies to show under each thread on the main board page

$allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'];

// CSRF secret file
$csrf_file = __DIR__ . '/csrf_secret.txt';

// Admin password (PLAINTEXT for now; will hash later)
$admin_password = 'am4';
