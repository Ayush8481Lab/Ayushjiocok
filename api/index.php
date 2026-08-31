<?php
// index.php - Get __hdnea__ via Indian proxy (for Vercel)
error_reporting(0);

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

function makeRequest($url, $headers, $proxy = null) {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10,
    ];
    if ($proxy) {
        $options[CURLOPT_PROXY] = $proxy;
        $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
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

// ---- PROXY HANDLING ----
$proxy = null;

// 1. Check for a dedicated proxy from environment or query param
$dedicatedProxy = getenv('INDIAN_PROXY') ?: ($_REQUEST['proxy'] ?? '');
if ($dedicatedProxy) {
    $proxy = $dedicatedProxy;
} else {
    // 2. Fallback: fetch a list of proxies (replace with an Indian-only list)
    $listUrl = "https://raw.githubusercontent.com/sayanpal514-hue/Proxy-Fetcher/refs/heads/main/live.txt";
    $proxies = @file_get_contents($listUrl);
    if ($proxies !== false) {
        $proxies = array_filter(array_map('trim', explode("\n", $proxies)));
        // We'll try each proxy below
    } else {
        http_response_code(500);
        exit("No proxy configured and proxy list unreachable");
    }
}

$cookies_found = [];
$success = false;

// If we have a single proxy, try it directly
if ($proxy) {
    for ($i = 0; $i < 2; $i++) {
        list($httpCode, $headerText) = makeRequest($url, $headers, $proxy);
        if ($httpCode == 450) break; // blocked, proxy not Indian
        $cookies = extractCookiesFromHeader($headerText);
        $cookies_found = array_merge($cookies_found, $cookies);
    }
    if (isset($cookies_found['__hdnea__'])) {
        $success = true;
    }
}
// Otherwise try the list
elseif (isset($proxies)) {
    foreach ($proxies as $proxy) {
        if (empty($proxy)) continue;
        $cookies_found = [];
        for ($i = 0; $i < 2; $i++) {
            list($httpCode, $headerText) = makeRequest($url, $headers, $proxy);
            if ($httpCode == 450) break;
            $cookies = extractCookiesFromHeader($headerText);
            $cookies_found = array_merge($cookies_found, $cookies);
        }
        if (isset($cookies_found['__hdnea__'])) {
            $success = true;
            break;
        }
    }
}

if ($success) {
    $hdnea = '__hdnea__=' . $cookies_found['__hdnea__'];
    echo bin2hex($hdnea);
} else {
    http_response_code(500);
    echo "Error: Could not obtain __hdnea__. Check proxy location (must be India) or credentials.";
}
?>
