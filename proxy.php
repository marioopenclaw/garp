<?php
/**
 * GARP Screener - FMP API Proxy
 * Place this file at filipas.com/garp/proxy.php
 * Handles all FMP API calls server-side to avoid CORS issues
 */

// Security: only allow requests from your domain
header('Access-Control-Allow-Origin: https://filipas.com');
header('Access-Control-Allow-Methods: GET');
header('Content-Type: application/json');

// Your FMP API key
define('FMP_KEY', 'SqXWkBu3Q8MhyBogRRKtFsLqCpqBOJWH');
define('FMP_BASE', 'https://financialmodelingprep.com/stable');

// Rate limiting - prevent abuse
session_start();
$now = time();
if (!isset($_SESSION['last_call'])) $_SESSION['last_call'] = 0;
if (!isset($_SESSION['call_count'])) $_SESSION['call_count'] = 0;

// Reset count every 60 seconds
if ($now - $_SESSION['last_call'] > 60) {
    $_SESSION['call_count'] = 0;
    $_SESSION['last_call'] = $now;
}

$_SESSION['call_count']++;

// Allow up to 500 calls per minute (generous for a full screen run)
if ($_SESSION['call_count'] > 500) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded. Please wait a moment.']);
    exit;
}

// Get endpoint and symbol from request
$endpoint = isset($_GET['endpoint']) ? preg_replace('/[^a-z\-]/', '', $_GET['endpoint']) : '';
$symbol   = isset($_GET['symbol'])   ? preg_replace('/[^A-Z0-9.\-]/', '', strtoupper($_GET['symbol'])) : '';
$limit    = isset($_GET['limit'])    ? intval($_GET['limit']) : 5;
$period   = isset($_GET['period'])   ? ($_GET['period'] === 'quarter' ? 'quarter' : 'annual') : 'annual';

if (empty($endpoint)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing endpoint']);
    exit;
}

// Build FMP URL
$allowed_endpoints = [
    'profile', 'ratios', 'key-metrics', 'income-statement',
    'balance-sheet-statement', 'cash-flow-statement', 'key-metrics-ttm'
];

if (!in_array($endpoint, $allowed_endpoints)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid endpoint']);
    exit;
}

$url = FMP_BASE . '/' . $endpoint . '?apikey=' . FMP_KEY;
if ($symbol) $url .= '&symbol=' . urlencode($symbol);
if ($limit)  $url .= '&limit=' . $limit;
if ($period && $endpoint === 'income-statement') $url .= '&period=' . $period;

// Fetch from FMP with caching
$cache_key = md5($url);
$cache_dir  = sys_get_temp_dir() . '/garp_cache/';
$cache_file = $cache_dir . $cache_key . '.json';
$cache_ttl  = 3600; // 1 hour cache

if (!is_dir($cache_dir)) mkdir($cache_dir, 0755, true);

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
    // Serve from cache
    echo file_get_contents($cache_file);
    exit;
}

// Make the actual FMP request
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'GARP-Screener/2.0',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(503);
    echo json_encode(['error' => 'Failed to reach data provider: ' . $error]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode(['error' => 'Data provider returned ' . $httpCode]);
    exit;
}

// Cache the response
file_put_contents($cache_file, $response);

echo $response;
?>