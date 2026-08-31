<?php
// index.php - Generate __hdnea__ via ScraperAPI (India proxy)
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

function makeRequest($url, $headers, $proxy, $proxyAuth, $extraHeaders = []) {
    $ch = curl_init($url);
    $allHeaders = array_merge($headers, $extraHeaders);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $allHeaders,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROXY => $proxy,
        CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
        CURLOPT_PROXYUSERPWD => $proxyAuth,
    ];
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerText = substr($response, 0, $headerSize);
    curl_close($ch);
    return [$httpCode, $headerText];
}

$ck = $_REQUEST['ck'] ?? '';
$id = $_REQUEST['id'] ?? '';

if (empty($ck)) {
    http_response_code(400);
    exit("Missing ck parameter");
}

$cookie = hex2str($ck);

// Basic headers (same as before)
$headers = [
    "Cookie: $cookie",
    "User-Agent: plaYtv/7.1.3 (Linux;Android 14) ExoPlayerLib/2.11.7"
];

// Optional additional credentials from environment or query (if you need them later)
$JIOCRED = getenv('JIOCRED');
$CREDKEY = getenv('CREDKEY');
$jiocred_hex = $_REQUEST['jiocred'] ?? $JIOCRED;
$credkey_hex = $_REQUEST['credkey'] ?? $CREDKEY;

if (!empty($jiocred_hex) && !empty($credkey_hex)) {
    function decrypt_data($e_data, $key) {
        $key = (int) $key;
        $encrypted = base64_decode($e_data);
        $decrypted = array_map(fn($char) => chr(ord($char) - $key), str_split($encrypted));
        return implode('', $decrypted);
    }
    $jiocred = hex2str($jiocred_hex);
    $credkey = hex2str($credkey_hex);
    $cred = json_decode(decrypt_data($jiocred, $credkey), true);
    if ($cred) {
        $access_token = $cred['authToken'] ?? '';
        $crm = $cred['sessionAttributes']['user']['subscriberId'] ?? '';
        $device_id = $cred['deviceId'] ?? '';
        $unique_id = $cred['sessionAttributes']['user']['unique'] ?? '';
        $sso_token = $cred['sessionAttributes']['user']['ssoToken'] ?? '';
        $headers = array_merge($headers, [
            "accesstoken: $access_token",
            "appkey: NzNiMDhlYcQyNjJm",
            "crmid: $crm",
            "deviceId: $device_id",
            "devicetype: phone",
            "isott: true",
            "languageId: 6",
            "lbcookie: 1",
            "os: android",
            "osVersion: 14",
            "srno: 250918144000",
            "ssotoken: $sso_token",
            "subscriberId: $crm",
            "uniqueId: $unique_id",
            "usergroup: tvYR7NSNn7rymo3F",
            "versionCode: 452",
            "Origin: https://www.jiocinema.com",
            "Referer: https://www.jiocinema.com/",
        ]);
    }
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

// Optional: add country targeting header (if your ScraperAPI plan supports it)
$extraHeaders = ["X-Country: IN"];

$cookiesFound = [];
$debug = [];

// Make two requests (as before)
for ($i = 0; $i < 2; $i++) {
    list($httpCode, $headerText) = makeRequest($url, $headers, $proxyHost, $proxyAuth, $extraHeaders);
    $debug[] = [
        'request_number' => $i + 1,
        'http_code' => $httpCode,
        'headers' => $headerText,
    ];
    if ($httpCode == 450 || $httpCode == 0) {
        // Proxy blocked or failed; stop trying
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
