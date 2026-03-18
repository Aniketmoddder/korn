<?php
// api/process.php

// 1. LOAD CORE FILES
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/token-check.php';

// 2. CAPTURE & VALIDATE PARAMETERS
$video_url = $_GET['url'] ?? '';
$api_token = $_GET['token'] ?? '';

if (empty($video_url) || empty($api_token)) {
    send_json_response(false, "Missing 'url' or 'token' parameter", 400);
}

// 3. VERIFY TOKEN 
$token_check = validate_and_use_token($api_token, $video_url);

if ($token_check !== true) {
    send_json_response(false, $token_check, 403);
}
// ==========================================
// 🚀 THE MICRO-CACHE ENGINE (CDN Token Fix)
// ==========================================
$cache_hash = md5($video_url); 
$cache_file = CACHE_DIR . '/' . $cache_hash . '.json';

// DROP TO 5 MINUTES (300 SECONDS). 
// This prevents server spam but ensures CDN links don't expire or hit their 3-click limits.
$cache_lifetime = 300; 

// If cache exists AND is less than 5 minutes old...
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_lifetime) {
    // Read the saved JSON and return it instantly
    $cached_data = json_decode(file_get_contents($cache_file), true);
    
    // Optional: Inject a little flag so you know it was served from cache
    $cached_data['cached'] = true; 
    
    send_json_response(true, $cached_data);
}

// ==========================================
// 4. EXECUTE SCRAPER (Only runs if cache is missed or expired)
// ==========================================

define('TARGET_API_KEY', '3c409435f781890e402cdf7312aa47f2a7e23594f5615ce524f8e711bc69acc5');
define('TARGET_BASE_URL', 'https://www.xoffline.com');
$cookie_file = DATA_DIR . '/cookie.txt';

// Step A: Get Cookies & CSRF
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => TARGET_BASE_URL . "/",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_USERAGENT => "Mozilla/5.0 (Linux; Android 15; CPH2467) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36",
    CURLOPT_ENCODING => "", 
    CURLOPT_TIMEOUT => 15
]);
$html_response = curl_exec($ch);
curl_close($ch);

$csrf = null;
if (file_exists($cookie_file)) {
    $cookies = file_get_contents($cookie_file);
    if (preg_match('/x-csrf-token\s+([^\s]+)/', $cookies, $matches)) {
        $csrf = $matches[1];
    }
}
if (!$csrf && preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html_response, $html_matches)) {
    $csrf = $html_matches[1];
}

if (!$csrf) {
    $error_msg = "CSRF Token missing.";
    if (empty($html_response)) {
        $error_msg .= " The target site returned an empty response (Your Serv00 server IP might be blocked).";
    }
    send_json_response(false, "Internal Server Error: " . $error_msg, 500);
}

// Step B: Call Target API
$ch = curl_init();
$payload = json_encode(["apiToken" => TARGET_API_KEY, "apiValue" => $video_url]);

curl_setopt_array($ch, [
    CURLOPT_URL => TARGET_BASE_URL . "/callDownloaderApi",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_ENCODING => "", 
    CURLOPT_HTTPHEADER => [
        "Accept: application/json, text/plain, */*",
        "Content-Type: application/json",
        "Origin: " . TARGET_BASE_URL,
        "Referer: " . TARGET_BASE_URL . "/",
        "Sec-Ch-Ua: \"Chromium\";v=\"137\", \"Not/A)Brand\";v=\"24\"",
        "Sec-Ch-Ua-Mobile: ?1",
        "Sec-Ch-Ua-Platform: \"Android\"",
        "X-CSRF-Token: " . $csrf,
        "User-Agent: Mozilla/5.0 (Linux; Android 15; CPH2467) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36"
    ],
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($ch);
curl_close($ch);

// Step C: Clean Up Output & Save to Cache
$json = json_decode($response, true);

if ($json && isset($json['data']) && is_array($json['data'])) {
    foreach ($json['data'] as &$item) {
        if (isset($item['title'])) {
            $item['title'] = explode('"/>', $item['title'])[0];
        }
        if (isset($item['thumbnail'])) {
            $item['thumbnail'] = explode('"/>', $item['thumbnail'])[0];
        }
    }
    
    // 💾 SAVE TO CACHE BEFORE RETURNING
    $json['cached'] = false; // Add a flag so you know it was fresh
    file_put_contents($cache_file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
    // Return Success Response!
    send_json_response(true, $json);
} else {
    send_json_response(false, ["message" => "Failed to parse target API response", "raw" => $response], 502);
}
?>
