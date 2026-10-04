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
        'error' => 'Gateway URL invalid. Must start with http://',
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
        CURLOPT_HTTPHEADER => array('X-Api-Key: ' . $key, 'Accept: application/json'),
    );
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = '{}';
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($raw, $err, $code);
}

$waAccount = array('local_url' => $base, 'local_key' => $key);
$ownStatus = function_exists('whatsapp_gateway_status_for_account')
    ? whatsapp_gateway_status_for_account($waAccount, $sessionId)
    : array('ready' => false, 'session' => '', 'status' => 'need_link');
$echo = isset($ownStatus['session']) ? (string) $ownStatus['session'] : '';
$ownsSession = !empty($ownStatus['session_ok'])
    && $sessionId !== ''
    && $echo === (string) $sessionId;
if ($action === 'status' || !$ownsSession) {
    echo json_encode(array(
        'success' => true,
        'ready' => $ownsSession && !empty($ownStatus['ready']),
        'has_qr' => $ownsSession && !empty($ownStatus['has_qr']),
        'phone' => ($ownsSession && !empty($ownStatus['ready']) && isset($ownStatus['phone'])) ? (string) $ownStatus['phone'] : '',
        'session' => $ownsSession ? $sessionId : '',
        'status' => $ownsSession && !empty($ownStatus['ready']) ? 'connected' : 'need_link',
        'qr_data_url' => ($ownsSession && $action === 'qr' && !empty($ownStatus['qr_data_url'])) ? $ownStatus['qr_data_url'] : null,
    ));
    exit;
}

if ($action === 'logout') {
    $urlPost = $base . '/logout?key=' . rawurlencode($key) . $sessionQs;
    $urlGet = $urlPost;
    list($raw, $err, $code) = wa_proxy_request($urlPost, 'POST', $key, 12);
    if ($raw === false || $code >= 400 || $code === 0) {
        list($raw, $err, $code) = wa_proxy_request($urlGet, 'GET', $key, 12);
    }
    // حتى لو الرد فاضي/انقطع: اعتبره نجاح وخلّي الواجهة تنتظر QR
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        echo json_encode($decoded);
        exit;
    }
    echo json_encode(array(
        'success' => true,
        'message' => 'Logout requested. Waiting for QR...',
        'detail' => $err,
        'http_code' => $code,
    ));
    exit;
}

$path = '/' . $action;
$url = $base . $path . '?key=' . rawurlencode($key) . $sessionQs;
list($raw, $err, $code) = wa_proxy_request($url, 'GET', $key, 8);

if ($raw === false) {
    http_response_code(502);
    echo json_encode(array(
        'success' => false,
        'error' => 'Cannot reach Windows gateway. Run start-gateway.bat',
        'detail' => $err,
    ));
    exit;
}

$decoded = json_decode($raw, true);
if (is_array($decoded)) {
    $echoed = isset($decoded['session']) ? (string) $decoded['session'] : '';
    if ($echoed === '' || $echoed !== (string) $sessionId) {
        $decoded['ready'] = false;
        $decoded['phone'] = '';
        $decoded['session'] = '';
        $decoded['status'] = 'need_link';
        $decoded['qr_data_url'] = null;
        $decoded['has_qr'] = false;
    } elseif (empty($decoded['ready'])) {
        $decoded['phone'] = '';
    }
    http_response_code($code > 0 ? $code : 200);
    echo json_encode($decoded);
    exit;
}

http_response_code(502);
echo json_encode(array(
    'success' => false,
    'error' => 'bad gateway response',
    'raw' => substr((string) $raw, 0, 200),
));
