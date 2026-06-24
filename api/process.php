<?php
// api/process.php

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/token-check.php';

$video_url = $_GET['url'] ?? '';
$api_token = $_GET['token'] ?? '';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

if (empty($video_url) || empty($api_token)) {
    send_json_response(false, "Missing 'url' or 'token' parameter", 400);
}

$token_check = validate_and_use_token($api_token, $video_url);
if ($token_check !== true) {
    send_json_response(false, $token_check, 403);
}

// ==========================================
// ✅ MICRO-CACHE (60 seconds)
// ==========================================
$cache_hash = md5($video_url);
$cache_file = CACHE_DIR . '/' . $cache_hash . '.json';
$cache_lifetime = 60; // 1 minute cache

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_lifetime) {
    $cached_data = json_decode(file_get_contents($cache_file), true);
    $cached_data['cached'] = true;
    send_json_response(true, $cached_data);
}

define('TARGET_API_KEY', '3c409435f781890e402cdf7312aa47f2a7e23594f5615ce524f8e711bc69acc5');
define('TARGET_BASE_URL', 'https://www.xoffline.com');
$cookie_file = DATA_DIR . '/cookie.txt';
$log_file = __DIR__ . '/../logs/request.log';

$proxy_pool = [
    "196.244.48.124:12345:naveed:Qwerty_123ABC",
    "136.179.19.164:3128:harishankarchoubey:HvCjWdoIrK6szj8v",
    "196.244.48.26:12345:naveed:Qwerty_123ABC",
    "136.179.19.164:3128:llewellynashleybowen:rNXaRJfNPN233zw",
    "ca-tor.pvdata.host:8080:g2rTXpNfPdcw2fzGtWKp62yH:nizar1elad2",
    "im-bal.pvdata.host:8080:g2rTXpNfPdcw2fzGtWKp62yH:nizar1elad2",
    "au-syd.pvdata.host:8080:g2rTXpNfPdcw2fzGtWKp62yH:nizar1elad2",
    "px460403.pointtoserver.com:10780:purevpn0s12153504:1LTpwxbCJbEdXo"
];

$selected_proxy = $proxy_pool[array_rand($proxy_pool)];
list($proxy_host, $proxy_port, $proxy_user, $proxy_pass) = explode(':', $selected_proxy);
$proxy_auth = $proxy_user . ":" . $proxy_pass;

function log_line($log_file, $message) {
    file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", FILE_APPEND);
}

function proxy_options($proxy_host, $proxy_port, $proxy_auth) {
    return [
        CURLOPT_PROXY => $proxy_host,
        CURLOPT_PROXYPORT => $proxy_port,
        CURLOPT_PROXYUSERPWD => $proxy_auth,
        CURLOPT_PROXYTYPE => CURLPROXY_HTTP
    ];
}

log_line($log_file, "Using proxy: {$selected_proxy}");

$debug_info = [];
if ($debug) {
    $ipCheck = curl_init();
    curl_setopt_array($ipCheck, [
        CURLOPT_URL => "https://api.ipify.org?format=json",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ] + proxy_options($proxy_host, $proxy_port, $proxy_auth));

    $ipResponse = curl_exec($ipCheck);
    $ipErrNo = curl_errno($ipCheck);
    $ipErrMsg = curl_error($ipCheck);
    curl_close($ipCheck);

    $debug_info['proxy'] = $selected_proxy;
    $debug_info['ip_check'] = $ipResponse ?: null;
    $debug_info['ip_check_error'] = $ipErrNo ? $ipErrMsg : null;
}

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
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
] + proxy_options($proxy_host, $proxy_port, $proxy_auth));

$html_response = curl_exec($ch);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
curl_close($ch);

if ($html_response === false || $curl_errno) {
    log_line($log_file, "Proxy failed (Cookie/CSRF): {$selected_proxy} | {$curl_errno} | {$curl_error}");
    send_json_response(false, "Proxy connection failed during cookie/CSRF request.", 502);
}

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
        $error_msg .= " The request failed or was blocked by Cloudflare.";
    } elseif (strpos($html_response, 'challenge-error-text') !== false) {
        $error_msg .= " Cloudflare blocked the server IP.";
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
] + proxy_options($proxy_host, $proxy_port, $proxy_auth));

$response = curl_exec($ch);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curl_errno) {
    log_line($log_file, "Proxy failed (Downloader API): {$selected_proxy} | {$curl_errno} | {$curl_error}");
    send_json_response(false, "Proxy connection failed during downloader request.", 502);
}

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

    $json['cached'] = false;

    if ($debug) {
        $json['debug'] = $debug_info;
        $json['debug']['http_status'] = $http_code;
    }

    file_put_contents($cache_file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    send_json_response(true, $json);
} else {
    $safe_raw_response = base64_encode($response);

    $error_details = [
        "message" => "Failed to parse target API response.",
        "http_status" => $http_code,
        "raw_base64" => $safe_raw_response,
        "hint" => "Decode the raw_base64 string to see the exact HTML error."
    ];

    if ($debug) {
        $error_details['debug'] = $debug_info;
    }

    send_json_response(false, $error_details, 502);
}
?>
