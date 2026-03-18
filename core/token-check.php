<?php
// core/token-check.php

function validate_and_use_token($api_token, $video_url) {
    $tokens = file_exists(TOKEN_FILE) ? json_decode(file_get_contents(TOKEN_FILE), true) : [];
    if (!is_array($tokens)) return "SYS_ERR: Invalid database state.";

    $today = date('Y-m-d');
    $token_found = false;
    $updated = false;
    $response_msg = "Invalid API token provided.";

    foreach ($tokens as &$t) {
        if ($t['token'] === $api_token) {
            $token_found = true;

            // 1. ✨ The Lazy Reset Check (Run before checking limits)
            if (isset($t['last_reset'])) {
                if ($t['last_reset'] !== $today) {
                    $t['used'] = 0;
                    $t['last_reset'] = $today;
                    $updated = true;
                }
            } else {
                // Fix older tokens missing the date parameter
                $t['last_reset'] = $today;
                $updated = true;
            }

            // 2. Check Status
            if ($t['status'] !== 'active') {
                $response_msg = "Your API token is disabled or revoked.";
                break;
            }

            // 3. Check Expiration
            if (strtotime($t['expiry']) < time()) {
                $response_msg = "Your API token has expired.";
                break;
            }

            // 4. Check Daily Limit
            if ($t['used'] >= $t['limit']) {
                $response_msg = "Rate Limit Exceeded: You have used " . $t['used'] . "/" . $t['limit'] . " requests. Resets at midnight.";
                break;
            }

            // 5. Success - Charge the token
            $t['used']++;
            $updated = true;
            
            // 6. System Logging (For your Matrix terminal)
            if (!file_exists(LOGS_DIR)) mkdir(LOGS_DIR, 0777, true);
            $log_entry = "[" . date('Y-m-d H:i:s') . "] " . substr($api_token, 0, 14) . "... | " . $video_url . " | success\n";
            file_put_contents(LOG_FILE, $log_entry, FILE_APPEND);

            // Save and allow the scraper to run
            file_put_contents(TOKEN_FILE, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return true;
        }
    }

    // Log failures (Limit hit, fake token, etc)
    if ($token_found && !$updated) {
         $log_entry = "[" . date('Y-m-d H:i:s') . "] " . substr($api_token, 0, 14) . "... | " . $video_url . " | failed (limit/expired)\n";
         file_put_contents(LOG_FILE, $log_entry, FILE_APPEND);
    }

    return $response_msg;
}
?>
