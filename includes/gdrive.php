<?php

/**
 * رفع النسخ إلى كوكل درايف (OAuth refresh token)
 */

function gdrive_settings()
{
    $s = function_exists('settings_load') ? settings_load() : array();
    return array(
        'client_id' => isset($s['gdrive_client_id']) ? trim((string) $s['gdrive_client_id']) : '',
        'client_secret' => isset($s['gdrive_client_secret']) ? trim((string) $s['gdrive_client_secret']) : '',
        'refresh_token' => isset($s['gdrive_refresh_token']) ? trim((string) $s['gdrive_refresh_token']) : '',
        'folder_id' => isset($s['gdrive_folder_id']) ? trim((string) $s['gdrive_folder_id']) : '',
    );
}

function gdrive_is_ready()
{
    $g = gdrive_settings();
    return $g['client_id'] !== '' && $g['client_secret'] !== '' && $g['refresh_token'] !== '';
}

function gdrive_redirect_uri()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/public/backup.php';
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . $base . '/gdrive_callback.php';
}

function gdrive_auth_url()
{
    $g = gdrive_settings();
    $q = array(
        'client_id' => $g['client_id'],
        'redirect_uri' => gdrive_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'access_type' => 'offline',
        'prompt' => 'consent',
    );
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($q);
}

function gdrive_http($url, $post, $headers = array())
{
    if (!function_exists('curl_init')) {
        return array(false, 'cURL غير متوفر', '');
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return array(false, $err !== '' ? $err : 'HTTP fail', '');
    }
    return array($code >= 200 && $code < 300, $body, (string) $code);
}

function gdrive_access_token()
{
    $g = gdrive_settings();
    if (!gdrive_is_ready()) {
        return array(false, 'كوكول درايف غير مربوط');
    }
    list($ok, $body) = gdrive_http('https://oauth2.googleapis.com/token', array(
        'client_id' => $g['client_id'],
        'client_secret' => $g['client_secret'],
        'refresh_token' => $g['refresh_token'],
        'grant_type' => 'refresh_token',
    ));
    $json = json_decode($body, true);
    if (!$ok || !is_array($json) || empty($json['access_token'])) {
        $msg = is_array($json) && isset($json['error_description']) ? $json['error_description'] : 'تعذر التوكن';
        return array(false, $msg);
    }
    return array(true, $json['access_token']);
}

function gdrive_exchange_code($code)
{
    $g = gdrive_settings();
    list($ok, $body) = gdrive_http('https://oauth2.googleapis.com/token', array(
        'code' => $code,
        'client_id' => $g['client_id'],
        'client_secret' => $g['client_secret'],
        'redirect_uri' => gdrive_redirect_uri(),
        'grant_type' => 'authorization_code',
    ));
    $json = json_decode($body, true);
    if (!$ok || !is_array($json) || empty($json['refresh_token'])) {
        $msg = is_array($json) && isset($json['error_description']) ? $json['error_description'] : 'ما رجع refresh token';
        return array(false, $msg);
    }
    if (function_exists('settings_save')) {
        settings_save(array('gdrive_refresh_token' => $json['refresh_token']));
    }
    return array(true, 'تم الربط');
}

/**
 * @return array(bool ok, string msg)
 */
function gdrive_upload_file($path, $name)
{
    if (!is_file($path)) {
        return array(false, 'الملف غير موجود');
    }
    list($ok, $tokenOrMsg) = gdrive_access_token();
    if (!$ok) {
        return array(false, $tokenOrMsg);
    }
    $g = gdrive_settings();
    $meta = array('name' => $name);
    if ($g['folder_id'] !== '') {
        $meta['parents'] = array($g['folder_id']);
    }
    $boundary = 'bnd' . md5($name . microtime(true));
    $fileBody = file_get_contents($path);
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= json_encode($meta) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= $fileBody . "\r\n";
    $body .= "--{$boundary}--";
    list($upOk, $resp) = gdrive_http(
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
        $body,
        array(
            'Authorization: Bearer ' . $tokenOrMsg,
            'Content-Type: multipart/related; boundary=' . $boundary,
        )
    );
    if (!$upOk) {
        return array(false, 'فشل الرفع');
    }
    return array(true, 'ok');
}
