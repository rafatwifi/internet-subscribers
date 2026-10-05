<?php

/**
 * Proxy WhatsApp gateway so browser never hits CORS / blocked LAN from mixed origins.
 * usage: wa_proxy.php?action=status|qr|logout
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if (function_exists('app_session_close')) {
    app_session_close();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'status';
$allowed = array('status', 'qr', 'logout');
if (!in_array($action, $allowed, true)) {
    http_response_code(400);
    echo json_encode(array('success' => false, 'error' => 'bad action'));
    exit;
}

$wa = isset($config['whatsapp']) ? $config['whatsapp'] : array();
$base = isset($wa['local_url']) ? rtrim((string) $wa['local_url'], '/') : '';
$key = isset($wa['local_key']) ? (string) $wa['local_key'] : '';
$sessionId = function_exists('whatsapp_session_id') ? whatsapp_session_id() : 'default';
$sessionQs = '&session=' . rawurlencode($sessionId);

if ($base === '' || strpos($base, 'http') !== 0) {
    http_response_code(502);
    echo json_encode(array(
        'success' => false,
        'ready' => false,
        'status' => 'gateway_down',
        'error' => 'عنوان بوابة واتساب غير مضبوط.',
    ));
    exit;
}

function wa_proxy_request($url, $method, $key, $timeout)
{
    $ch = curl_init($url);
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => array(
            'X-Api-Key: ' . $key,
            'Accept: application/json',
        ),
    );
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = '{}';
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($raw, $code);
}

function wa_proxy_emit($payload, $http)
{
    http_response_code($http);
    echo json_encode($payload);
    exit;
}

function wa_proxy_down()
{
    wa_proxy_emit(array(
        'success' => false,
        'ready' => false,
        'has_qr' => false,
        'phone' => '',
        'session' => '',
        'status' => 'gateway_down',
        'qr_data_url' => null,
        'error' => 'بوابة واتساب متوقفة على جهاز الويندوز. الربط السابق محفوظ على الجهاز — شغّل start-gateway.bat وما تحتاج تمسح الرمز من جديد.',
    ), 502);
}

function wa_proxy_status_name($raw)
{
    $raw = trim((string) $raw);
    $ok = array('starting', 'connecting', 'qr_ready', 'connected', 'logout_cooldown', 'cert_expired', 'tls_retry');
    if (in_array($raw, $ok, true)) {
        return $raw;
    }
    if (strpos($raw, 'closed_') === 0 && preg_match('/^closed_[0-9]+$/', $raw)) {
        return $raw;
    }
    return 'connecting';
}

list($raw, $code) = wa_proxy_request(
    $base . '/status?key=' . rawurlencode($key) . $sessionQs,
    'GET',
    $key,
    8
);
if ($raw === false || $code === 0) {
    wa_proxy_down();
}
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    wa_proxy_down();
}

$echoed = isset($decoded['session']) ? (string) $decoded['session'] : '';
if ($echoed === '' || $echoed !== (string) $sessionId) {
    wa_proxy_emit(array(
        'success' => true,
        'ready' => false,
        'has_qr' => false,
        'phone' => '',
        'session' => '',
        'status' => 'need_link',
        'qr_data_url' => null,
    ), 200);
}

if ($action === 'logout') {
    $urlPost = $base . '/logout?key=' . rawurlencode($key) . $sessionQs;
    list($lraw, $lcode) = wa_proxy_request($urlPost, 'POST', $key, 12);
    if ($lraw === false || $lcode >= 400 || $lcode === 0) {
        list($lraw, $lcode) = wa_proxy_request($urlPost, 'GET', $key, 12);
    }
    $logout = is_string($lraw) ? json_decode($lraw, true) : null;
    if (is_array($logout)) {
        if (!isset($logout['session']) || (string) $logout['session'] !== (string) $sessionId) {
            $logout['session'] = $sessionId;
        }
        wa_proxy_emit($logout, 200);
    }
    wa_proxy_emit(array(
        'success' => true,
        'session' => $sessionId,
        'message' => 'Logout requested. Waiting for QR...',
    ), 200);
}

if (!empty($decoded['ready'])) {
    wa_proxy_emit(array(
        'success' => true,
        'ready' => true,
        'has_qr' => false,
        'phone' => isset($decoded['phone']) ? (string) $decoded['phone'] : '',
        'session' => $sessionId,
        'status' => 'connected',
        'qr_data_url' => null,
    ), 200);
}

$gwStatus = wa_proxy_status_name(isset($decoded['status']) ? $decoded['status'] : '');
$qrImage = null;
$hasQr = !empty($decoded['has_qr']);

if ($action === 'qr') {
    list($qraw, $qcode) = wa_proxy_request(
        $base . '/qr?key=' . rawurlencode($key) . $sessionQs,
        'GET',
        $key,
        8
    );
    $qr = is_string($qraw) ? json_decode($qraw, true) : null;
    if (is_array($qr)) {
        $qEcho = isset($qr['session']) ? (string) $qr['session'] : '';
        if ($qEcho === (string) $sessionId) {
            if (!empty($qr['ready'])) {
                wa_proxy_emit(array(
                    'success' => true,
                    'ready' => true,
                    'has_qr' => false,
                    'phone' => isset($qr['phone']) ? (string) $qr['phone'] : '',
                    'session' => $sessionId,
                    'status' => 'connected',
                    'qr_data_url' => null,
                ), 200);
            }
            if (!empty($qr['qr_data_url'])) {
                $qrImage = $qr['qr_data_url'];
                $hasQr = true;
                $gwStatus = 'qr_ready';
            } elseif (isset($qr['status'])) {
                $gwStatus = wa_proxy_status_name($qr['status']);
            }
        }
    }
}

wa_proxy_emit(array(
    'success' => true,
    'ready' => false,
    'has_qr' => $hasQr,
    'phone' => '',
    'session' => $sessionId,
    'status' => $gwStatus,
    'qr_data_url' => $qrImage,
), 200);
