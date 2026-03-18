<?php
// dashboard/dashboard-api.php

require_once __DIR__ . '/../core/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Receive the Clerk User ID from the frontend
$data = json_decode(file_get_contents('php://input'), true);
$clerk_id = $data['clerk_id'] ?? '';

if (empty($clerk_id)) {
    echo json_encode(["success" => false, "error" => "Unauthorized access."]);
    exit;
}

$tokens = file_exists(TOKEN_FILE) ? json_decode(file_get_contents(TOKEN_FILE), true) : [];
if (!is_array($tokens)) $tokens = [];

$today = date('Y-m-d');
$user_token = null;
$updated = false;

// 1. Search for existing user
foreach ($tokens as &$t) {
    if (isset($t['clerk_id']) && $t['clerk_id'] === $clerk_id) {
        
        // ✨ THE LAZY RESET ENGINE ✨
        // If their last reset wasn't today, reset their usage to 0!
        if (!isset($t['last_reset']) || $t['last_reset'] !== $today) {
            $t['used'] = 0;
            $t['last_reset'] = $today;
            $updated = true;
        }
        
        $user_token = $t;
        break;
    }
}

// 2. Auto-Provisioning (New User)
if (!$user_token) {
    // Generate an ultra-clean, Stripe-style API Key
    $clean_hex = bin2hex(random_bytes(12)); // 24 characters of pure secure hex
    $new_token_string = 'krn_live_' . $clean_hex;
    
    $user_token = [
        "token" => $new_token_string,
        "limit" => 15, // Free tier default
        "used" => 0,
        "expiry" => date('Y-m-d', strtotime('+10 years')), // Free tier never expires
        "status" => "active",
        "clerk_id" => $clerk_id,
        "last_reset" => $today // Set the reset clock
    ];
    
    array_unshift($tokens, $user_token); // Add to top of database
    $updated = true;
}

// Save database only if changes were made (saves server resources)
if ($updated) {
    file_put_contents(TOKEN_FILE, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Return the user's specific dashboard data
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
