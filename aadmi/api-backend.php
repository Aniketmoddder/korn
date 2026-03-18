<?php
// aadmi/api-backend.php

require_once __DIR__ . '/../core/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE');

$action = $_GET['action'] ?? '';

function getTokens() {
    if (!file_exists(TOKEN_FILE)) return [];
    $data = file_get_contents(TOKEN_FILE);
    return json_decode($data, true) ?? [];
}

function saveTokens($tokens) {
    file_put_contents(TOKEN_FILE, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

switch ($action) {
    
    case 'get_stats':
        $tokens = getTokens();
        $total_tokens = count($tokens);
        $active_tokens = 0;
        foreach ($tokens as $t) {
            if ($t['status'] === 'active' && strtotime($t['expiry']) > time()) {
                $active_tokens++;
            }
        }
        $total_requests = 0;
        if (file_exists(LOG_FILE)) {
            $log_lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $total_requests = count($log_lines);
        }
        echo json_encode(["success" => true, "stats" => ["total_tokens" => $total_tokens, "active_tokens" => $active_tokens, "total_requests" => $total_requests]]);
        break;

    case 'get_tokens':
        echo json_encode(["success" => true, "tokens" => getTokens()]);
        break;

    case 'create_token':
        $data = json_decode(file_get_contents('php://input'), true);
        $limit = isset($data['limit']) ? (int)$data['limit'] : 100;
        $expiry = isset($data['expiry']) && !empty($data['expiry']) ? $data['expiry'] : date('Y-m-d', strtotime('+30 days'));
        $new_token_string = "tok_" . substr(md5(uniqid(rand(), true)), 0, 8);
        $new_token = ["token" => $new_token_string, "limit" => $limit, "used" => 0, "expiry" => $expiry, "status" => "active"];
        $tokens = getTokens();
        array_unshift($tokens, $new_token);
        saveTokens($tokens);
        echo json_encode(["success" => true, "message" => "Token created successfully", "token" => $new_token]);
        break;

    case 'delete_token':
        $data = json_decode(file_get_contents('php://input'), true);
        $token_to_delete = $data['token'] ?? '';
        if (empty($token_to_delete)) { echo json_encode(["success" => false, "error" => "No token provided"]); exit; }
        $tokens = getTokens();
        $filtered_tokens = array_values(array_filter($tokens, function($t) use ($token_to_delete) { return $t['token'] !== $token_to_delete; }));
        saveTokens($filtered_tokens);
        echo json_encode(["success" => true, "message" => "Token deleted"]);
        break;

    // --- NEW: FETCH SYSTEM LOGS ---
    case 'get_logs':
        $log_data = [];
        if (file_exists(LOG_FILE)) {
            $lines = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            // Grab the last 150 requests to keep it fast
            $lines = array_slice($lines, -150);
            
            foreach ($lines as $line) {
                // Parse the format: [2026-03-14 10:15:30] tok_admin123 | https://url... | success
                if (preg_match('/\[(.*?)\] (.*?) \| (.*?) \| (.*)/', $line, $matches)) {
                    $log_data[] = [
                        'time' => $matches[1],
                        'token' => trim($matches[2]),
                        'url' => trim($matches[3]),
                        'status' => trim($matches[4])
                    ];
                } else {
                    $log_data[] = ['raw' => $line]; // Fallback for weird lines
                }
            }
        }
        echo json_encode(["success" => true, "logs" => $log_data]);
        break;
    
    // --- NEW: CLEAR LOGS ---
    case 'clear_logs':
        file_put_contents(LOG_FILE, '');
        echo json_encode(["success" => true, "message" => "Logs cleared"]);
        break;

    default:
        echo json_encode(["success" => false, "error" => "Invalid API action"]);
        break;
}
?>
