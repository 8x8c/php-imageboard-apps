<?php
declare(strict_types=1);

// config.php - Board-specific configuration
$board_name = basename(__DIR__); // e.g., "board1"
$db_file = __DIR__ . '/database.sqlite';
$upload_dir = __DIR__ . '/uploads/';

// Hard-coded posts per page
$posts_per_page = 20;

// Allowed file extensions
$allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4'];
