<?php
// dashboard/dashboard-api.php

// 1. Enable Error Reporting (Crucial for debugging on Railway)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 2. Load Config
require_once __DIR__ . '/../core/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// 3. Receive the Clerk User ID
$input = file_get_contents('php://input');
$data = json_decode($input, true);
$clerk_id = $data['clerk_id'] ?? '';

if (empty($clerk_id)) {
    http_response_code(401);
    echo json_encode(["success" => false, "error" => "Unauthorized access."]);
    exit;
}

// 4. Load Database (TOKEN_FILE is defined in config.php)
if (!file_exists(TOKEN_FILE)) {
    // If file is missing, try to start with an empty array
    $tokens = [];
} else {
    $content = file_get_contents(TOKEN_FILE);
    $tokens = json_decode($content, true);
    if (!is_array($tokens)) $tokens = [];
}

$today = date('Y-m-d');
$user_token = null;
$updated = false;

// 5. Search for existing user
foreach ($tokens as &$t) {
    if (isset($t['clerk_id']) && $t['clerk_id'] === $clerk_id) {
        if (!isset($t['last_reset']) || $t['last_reset'] !== $today) {
            $t['used'] = 0;
            $t['last_reset'] = $today;
            $updated = true;
        }
        $user_token = $t;
        break;
    }
}

// 6. Auto-Provisioning (New User)
if (!$user_token) {
    $clean_hex = bin2hex(random_bytes(12));
    $new_token_string = 'krn_live_' . $clean_hex;
    
    $user_token = [
        "token" => $new_token_string,
        "limit" => 15,
        "used" => 0,
        "expiry" => date('Y-m-d', strtotime('+10 years')),
        "status" => "active",
        "clerk_id" => $clerk_id,
        "last_reset" => $today
    ];
    
    array_unshift($tokens, $user_token);
    $updated = true;
}

// 7. Save database (The "Railway Proof" way)
if ($updated) {
    $json_to_save = json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    // Check if writing is actually successful
    if (file_put_contents(TOKEN_FILE, $json_to_save) === false) {
        // If this fails, Railway hasn't given PHP write access to the folder
        echo json_encode([
            "success" => false, 
            "error" => "Server Error: Cannot write to database. Check folder permissions."
        ]);
        exit;
    }
}

// 8. Success Response
echo json_encode([
    "success" => true, 
    "data" => [
        "token" => $user_token['token'],
        "limit" => $user_token['limit'],
        "used" => $user_token['used'],
        "status" => $user_token['status']
    ]
]);
?>
