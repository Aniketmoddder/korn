<?php
// core/helpers.php
require_once __DIR__ . '/config.php';

function log_request($token, $video_url, $status) {
    $date = date('Y-m-d H:i:s');
    // Format: [2026-03-14 10:15:30] tok_admin123 | https://... | success
    $log_entry = "[$date] $token | $video_url | $status" . PHP_EOL;
    file_put_contents(LOG_FILE, $log_entry, FILE_APPEND);
}

function send_json_response($success, $data_or_error, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    
    if ($success) {
        echo json_encode(["success" => true, "data" => $data_or_error]);
    } else {
        echo json_encode(["success" => false, "error" => $data_or_error]);
    }
    exit;
}
?>
