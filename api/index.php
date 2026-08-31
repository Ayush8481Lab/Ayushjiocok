<?php
// index.php - Generate __hdnea__ via ScraperAPI (with detailed errors)
error_reporting(0);
ini_set('max_execution_time', '60');

function hex2str($hex) {
    $s = hex2bin($hex);
    if ($s === false) { http_response_code(400); exit("Invalid hex string"); }
    return $s;
}

function extractCookiesFromHeader($headerText) {
    $cookies = [];
    foreach (explode("\r\n", $headerText) as $line) {
        if (preg_match('/^Set-Cookie:\s*([^;]*)/i', $line, $m)) {
            parse_str($m[1], $cookie);
            $cookies = array_merge($cookies, $cookie);
        }
    }
    return $cookies;
}

function makeRequest($url, $headers, $proxy, $proxyAuth) {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROXY => $proxy,
        CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
        CURLOPT_PROXYUSERPWD => $proxyAuth,
        // Uncomment to get more verbose output (not needed in production)
        // CURLOPT_VERBOSE => true,
    ];
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerText = $response !== false ? substr($response, 0, $headerSize) : '';
    $error = curl_error($ch);
    curl_close($ch);
    return [$httpCode, $headerText, $error];
}

// Check for test mode
if (isset($_GET['test'])) {
    // Simple test request to a site that returns headers
    $proxy = "proxy.scraperapi.com:8001";
    $auth = "scraperapi:" . "aac93ab62142f5ea8c722425382fd586";
    $url = "http://httpbin.org/headers";
    $headers = ["User-Agent: Test"];
    list($code, $headerText, $error) = makeRequest($url, $headers, $proxy, $auth);
    header('Content-Type: application/json');
    echo json_encode([
        'http_code' => $code,
        'curl_error' => $error,
        'response_headers' => $headerText,
    ], JSON_PRETTY_PRINT);
    exit;
}

$ck = $_REQUEST['ck'] ?? '';
$id = $_REQUEST['id'] ?? '';

if (empty($ck)) {
    http_response_code(400);
    exit("Missing ck parameter");
}

$cookie = hex2str($ck);

// Base headers
$headers = [
    "Cookie: $cookie",
    "User-Agent: plaYtv/7.1.3 (Linux;Android 14) ExoPlayerLib/2.11.7"
];

// Optional credentials (if needed)
$JIOCRED = getenv('JIOCRED');
$CREDKEY = getenv('CREDKEY');
$jiocred_hex = $_REQUEST['jiocred'] ?? $JIOCRED;
$credkey_hex = $_REQUEST['credkey'] ?? $CREDKEY;

if (!empty($jiocred_hex) && !empty($credkey_hex)) {
    // ... (same decryption and header addition as before, omitted for brevity)
    // Include the decryption code if you use it.
}

// Build CDN URL
if (!empty($id)) {
    $parts = explode('-', $id, 2);
    $channel = $parts[0];
    $url = "https://jiotvmblive.cdn.jio.com/bpk-tv/{$channel}/Fallback/{$id}";
} else {
    $url = "https://jiotvmblive.cdn.jio.com/";
}

// ScraperAPI settings
$SCRAPER_API_KEY = "aac93ab62142f5ea8c722425382fd586";  // hardcoded for testing
$proxyHost = "proxy.scraperapi.com:8001";
$proxyAuth = "scraperapi:" . $SCRAPER_API_KEY;

$cookiesFound = [];
$debug = [];

// Make two requests
for ($i = 0; $i < 2; $i++) {
    list($httpCode, $headerText, $error) = makeRequest($url, $headers, $proxyHost, $proxyAuth);
    $debug[] = [
        'request_number' => $i + 1,
        'http_code' => $httpCode,
        'curl_error' => $error,
        'headers' => $headerText,
    ];
    if ($httpCode == 450 || $httpCode == 0) {
        // Stop if blocked or connection failed
        break;
    }
    $cookies = extractCookiesFromHeader($headerText);
    $cookiesFound = array_merge($cookiesFound, $cookies);
}

if (isset($cookiesFound['__hdnea__'])) {
    $hdnea = '__hdnea__=' . $cookiesFound['__hdnea__'];
    echo bin2hex($hdnea);
} else {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error' => '__hdnea__ not found',
        'url' => $url,
        'requests' => $debug,
        'cookies_found' => array_keys($cookiesFound),
    ], JSON_PRETTY_PRINT);
}
?>
