<?php
// index.php - Generate __hdnea__ via ScraperAPI Proxy Mode (India IP)
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

function makeRequestViaProxy($url, $headers, $proxy, $proxyAuth) {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_PROXY => $proxy,
        CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
        CURLOPT_PROXYUSERPWD => $proxyAuth,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,   // force IPv4 to avoid DNS issues
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

// Test mode: check proxy connectivity
if (isset($_GET['test'])) {
    $proxy = "proxy.scraperapi.com:8001";
    $auth = "scraperapi.country_code=in:" . "aac93ab62142f5ea8c722425382fd586";
    $targetUrl = "http://httpbin.org/headers";
    $headers = ["User-Agent: Test", "Accept: */*"];
    list($code, $headerText, $error) = makeRequestViaProxy($targetUrl, $headers, $proxy, $auth);
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

$headers = [
    "Cookie: $cookie",
    "User-Agent: plaYtv/7.1.3 (Linux;Android 14) ExoPlayerLib/2.11.7",
    "Accept: */*",
    "Accept-Encoding: identity",
    "Connection: close",
];

// Optional credentials (if provided)
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
            "accesstoken" => $access_token,
            "appkey" => "NzNiMDhlYcQyNjJm",
            "crmid" => $crm,
            "deviceId" => $device_id,
            "devicetype" => "phone",
            "isott" => "true",
            "languageId" => "6",
            "lbcookie" => "1",
            "os" => "android",
            "osVersion" => "14",
            "srno" => "250918144000",
            "ssotoken" => $sso_token,
            "subscriberId" => $crm,
            "uniqueId" => $unique_id,
            "usergroup" => "tvYR7NSNn7rymo3F",
            "versionCode" => "452",
            "Origin" => "https://www.jiocinema.com",
            "Referer" => "https://www.jiocinema.com/",
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

$SCRAPER_API_KEY = "aac93ab62142f5ea8c722425382fd586";
$proxyHost = "proxy.scraperapi.com:8001";
$proxyAuth = "scraperapi.country_code=in:" . $SCRAPER_API_KEY;

$cookiesFound = [];
$debug = [];

for ($i = 0; $i < 2; $i++) {
    list($httpCode, $headerText, $error) = makeRequestViaProxy($url, $headers, $proxyHost, $proxyAuth);
    $debug[] = [
        'request_number' => $i + 1,
        'http_code' => $httpCode,
        'curl_error' => $error,
        'headers' => $headerText,
    ];
    if ($httpCode == 450 || $httpCode == 0) {
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
