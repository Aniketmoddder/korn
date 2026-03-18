<?php
// core/config.php

// Define absolute paths for reliability
define('BASE_DIR', realpath(__DIR__ . '/../'));
define('DATA_DIR', BASE_DIR . '/data');
define('LOGS_DIR', BASE_DIR . '/logs');
define('CORE_DIR', BASE_DIR . '/core');
define('CACHE_DIR', DATA_DIR . '/cache'); // NEW: Cache directory

// File paths
define('TOKEN_FILE', DATA_DIR . '/tokens.json');
define('LOG_FILE', LOGS_DIR . '/requests.log');

// Make sure directories exist (creates them if they don't)
if (!file_exists(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
if (!file_exists(LOGS_DIR)) mkdir(LOGS_DIR, 0777, true);
if (!file_exists(CACHE_DIR)) mkdir(CACHE_DIR, 0777, true); // NEW: Create cache folder

if (!file_exists(TOKEN_FILE)) file_put_contents(TOKEN_FILE, '[]');
if (!file_exists(LOG_FILE)) file_put_contents(LOG_FILE, '');
?>
