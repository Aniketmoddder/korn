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

$cache_lifetime = 300; // 5 minutes

// If cache exists AND is less than 5 minutes old...
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_lifetime) {
    $cached_data = json_decode(file_get_contents($cache_file), true);
    $cached_data['cached'] = true; 
    send_json_response(true, $cached_data);
}

// ==========================================
// 4. EXECUTE SCRAPER (Via Webshare Proxy)
// ==========================================

define('TARGET_API_KEY', '3c409435f781890e402cdf7312aa47f2a7e23594f5615ce524f8e711bc69acc5');
define('TARGET_BASE_URL', 'https://www.xoffline.com');
$cookie_file = DATA_DIR . '/cookie.txt';

// 🛑 WEBSHARE PROXY CONFIGURATION 🛑
$proxy_ip_port = "http://31.59.20.176:6754"; 
$proxy_auth = "dnuijtuz:1rj9656dzjgk"; 

// Step A: Get Cookies & CSRF
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => TARGET_BASE_URL . "/",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie_file,
    CURLOPT_COOKIEFILE => $cookie_file,
    CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
    CURLOPT_ENCODING => "", 
    CURLOPT_TIMEOUT => 20, 
    // PROXY SETTINGS
    CURLOPT_PROXY => $proxy_ip_port,
    CURLOPT_PROXYUSERPWD => $proxy_auth,
    CURLOPT_HTTPPROXYTUNNEL => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
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
        $error_msg .= " The proxy failed or was blocked by Cloudflare.";
    } elseif (strpos($html_response, 'challenge-error-text') !== false) {
        $error_msg .= " Cloudflare blocked the Webshare Proxy IP.";
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
    // PROXY SETTINGS
    CURLOPT_PROXY => $proxy_ip_port,
    CURLOPT_PROXYUSERPWD => $proxy_auth,
    CURLOPT_HTTPPROXYTUNNEL => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => [
        "Accept: application/json, text/plain, */*",
        "Content-Type: application/json",
        "Origin: " . TARGET_BASE_URL,
        "Referer: " . TARGET_BASE_URL . "/",
        "Sec-Ch-Ua: \"Chromium\";v=\"122\", \"Google Chrome\";v=\"122\"",
        "Sec-Ch-Ua-Mobile: ?0",
        "Sec-Ch-Ua-Platform: \"Windows\"",
        "X-CSRF-Token: " . $csrf,
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36"
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
    $json['cached'] = false; 
    file_put_contents($cache_file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    
    send_json_response(true, $json);
} else {
    // If we get here, Cloudflare might have blocked the POST request.
    send_json_response(false, ["message" => "Failed to parse target API response via Proxy", "raw" => $response], 502);
}
?>
