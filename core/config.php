<?php
// core/config.php

// 1. Define the Base Directory (The root of your project)
// dirname(__DIR__) goes up one level from the /core folder to the root.
define('BASE_DIR', dirname(__DIR__));

// 2. Define Folder Paths
define('DATA_DIR', BASE_DIR . '/data');
define('LOGS_DIR', BASE_DIR . '/logs');
define('CORE_DIR', BASE_DIR . '/core');
define('CACHE_DIR', DATA_DIR . '/cache');

// 3. Define Specific File Paths
define('TOKEN_FILE', DATA_DIR . '/tokens.json');
define('LOG_FILE', LOGS_DIR . '/requests.log');

/**
 * NOTE ON RAILWAY: 
 * Do NOT use mkdir() or file_put_contents() to create folders here.
 * Railway's filesystem is read-only in many areas during runtime.
 * * ACTION REQUIRED:
 * 1. Create the 'data', 'logs', and 'data/cache' folders on your computer.
 * 2. Create an empty 'tokens.json' file inside 'data' containing just: []
 * 3. Create an empty 'requests.log' file inside 'logs'.
 * 4. Push these folders/files to your GitHub repository.
 */

// If you MUST check for the file, do it silently without trying to create it
if (!file_exists(TOKEN_FILE)) {
    // This allows the script to fail gracefully instead of crashing the whole backend
    error_log("Critical Error: TOKEN_FILE missing at " . TOKEN_FILE);
}
?>
