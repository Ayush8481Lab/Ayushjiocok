<?php
// index.php - Generate __hdnea__ via concurrent proxy attempts (for Vercel)
error_reporting(0);

// Increase max execution time (Vercel supports up to 60s with Hobby)
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

function makeCurlHandle($url, $headers, $proxy) {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 5,          // short timeout per proxy
        CURLOPT_CONNECTTIMEOUT => 3,
    ];
    if ($proxy) {
        $options[CURLOPT_PROXY] = $proxy;
        $options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
    }
    curl_setopt_array($ch, $options);
    return $ch;
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

// Optional credentials from environment or query (if needed)
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

// Get proxy list (or use a dedicated proxy from env)
$dedicatedProxy = getenv('INDIAN_PROXY') ?: ($_REQUEST['proxy'] ?? '');
if ($dedicatedProxy) {
    $proxyList = [$dedicatedProxy];
} else {
    $listUrl = "https://raw.githubusercontent.com/sayanpal514-hue/Proxy-Fetcher/refs/heads/main/live.txt";
    $proxyData = @file_get_contents($listUrl);
    if ($proxyData === false) {
        http_response_code(500);
        exit("Could not fetch proxy list");
    }
    $proxyList = array_filter(array_map('trim', explode("\n", $proxyData)));
    if (empty($proxyList)) {
        http_response_code(500);
        exit("Proxy list is empty");
    }
}

// Test proxies in batches of 10 concurrently
$batchSize = 10;
$startTime = microtime(true);
$maxDuration = 50; // seconds (leave margin for Vercel timeout)
$success = false;
$resultHex = '';

for ($i = 0; $i < count($proxyList); $i += $batchSize) {
    if ((microtime(true) - $startTime) > $maxDuration) {
        break; // overall time limit reached
    }

    $batch = array_slice($proxyList, $i, $batchSize);
    $multi = curl_multi_init();
    $handles = [];

    foreach ($batch as $proxy) {
        $ch = makeCurlHandle($url, $headers, $proxy);
        curl_multi_add_handle($multi, $ch);
        $handles[$proxy] = $ch;
    }

    // Execute the batch
    do {
        $status = curl_multi_exec($multi, $active);
        if ($active) {
            curl_multi_select($multi, 1.0);
        }
    } while ($active && $status == CURLM_OK);

    // Check results
    foreach ($handles as $proxy => $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $response = curl_multi_getcontent($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerText = substr($response, 0, $headerSize);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);

        if ($httpCode != 450 && $httpCode != 0) {
            $cookies = extractCookiesFromHeader($headerText);
            if (isset($cookies['__hdnea__'])) {
                $hdnea = '__hdnea__=' . $cookies['__hdnea__'];
                $resultHex = bin2hex($hdnea);
                $success = true;
                break 2; // exit both loops
            }
        }
    }

    curl_multi_close($multi);
}

if ($success) {
    echo $resultHex;
} else {
    http_response_code(500);
    echo "Error: Could not obtain __hdnea__ using any available proxy.";
}
?>
