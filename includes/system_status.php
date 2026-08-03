<?php

/**
 * System health / status helpers (PHP 7.0+)
 */

function app_version()
{
    return '1.4.0';
}

function format_bytes_short($bytes)
{
    $bytes = (float) $bytes;
    if ($bytes < 0) {
        $bytes = 0;
    }
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $decimals = ($i === 0) ? 0 : 1;
    return number_format($bytes, $decimals) . ' ' . $units[$i];
}

function system_disk_info()
{
    $path = dirname(__DIR__);
    $free = @disk_free_space($path);
    $total = @disk_total_space($path);
    if ($free === false || $total === false || $total <= 0) {
        return array(
            'ok' => false,
            'free' => 0,
            'total' => 0,
            'used' => 0,
            'pct_used' => 0,
            'label' => '—',
        );
    }
    $used = $total - $free;
    $pct = (int) round(($used / $total) * 100);
    return array(
        'ok' => true,
        'free' => $free,
        'total' => $total,
        'used' => $used,
        'pct_used' => $pct,
        'label' => format_bytes_short($free) . ' / ' . format_bytes_short($total),
    );
}

function system_ram_info()
{
    $phpUsed = memory_get_usage(true);
    $phpPeak = memory_get_peak_usage(true);
    $phpLimit = ini_get('memory_limit');
    $total = 0;
    $available = 0;
    $source = 'php';

    if (is_readable('/proc/meminfo')) {
        $raw = @file_get_contents('/proc/meminfo');
        if (is_string($raw) && $raw !== '') {
            if (preg_match('/MemTotal:\s+(\d+)/i', $raw, $m)) {
                $total = (float) $m[1] * 1024;
            }
            if (preg_match('/MemAvailable:\s+(\d+)/i', $raw, $m)) {
                $available = (float) $m[1] * 1024;
            } elseif (preg_match('/MemFree:\s+(\d+)/i', $raw, $m)) {
                $available = (float) $m[1] * 1024;
            }
            if ($total > 0) {
                $source = 'host';
            }
        }
    }

    $pct = 0;
    $label = format_bytes_short($phpUsed) . ' PHP';
    if ($source === 'host' && $total > 0) {
        $used = $total - $available;
        $pct = (int) round(($used / $total) * 100);
        $label = format_bytes_short($available) . ' / ' . format_bytes_short($total);
    }

    return array(
        'ok' => true,
        'source' => $source,
        'php_used' => $phpUsed,
        'php_peak' => $phpPeak,
        'php_limit' => $phpLimit,
        'total' => $total,
        'available' => $available,
        'pct_used' => $pct,
        'label' => $label,
    );
}

function system_http_latency_ms($url, $timeout = 5)
{
    $ch = curl_init($url);
    if ($ch === false) {
        return array('ok' => false, 'ms' => null, 'error' => 'curl');
    }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_NOBODY => true,
        CURLOPT_USERAGENT => 'WiFiNetSales-Health/1.0',
    ));
    $t0 = microtime(true);
    curl_exec($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = ($err === '' && $code > 0 && $code < 500);
    return array(
        'ok' => $ok,
        'ms' => $ms,
        'http_code' => $code,
        'error' => $err,
    );
}

function system_whatsapp_status($config)
{
    $wa = isset($config['whatsapp']) ? $config['whatsapp'] : array();
    if (empty($wa['enabled'])) {
        return array(
            'ok' => false,
            'ready' => false,
            'state' => 'disabled',
            'phone' => '',
            'label' => 'OFF',
        );
    }
    $base = isset($wa['local_url']) ? rtrim((string) $wa['local_url'], '/') : '';
    $key = isset($wa['local_key']) ? (string) $wa['local_key'] : '';
    if ($base === '' || strpos($base, 'http') !== 0) {
        return array(
            'ok' => false,
            'ready' => false,
            'state' => 'bad_url',
            'phone' => '',
            'label' => 'URL?',
        );
    }
    $url = $base . '/status?key=' . rawurlencode($key);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => array('X-Api-Key: ' . $key, 'Accept: application/json'),
    ));
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code === 0) {
        return array(
            'ok' => false,
            'ready' => false,
            'state' => 'down',
            'phone' => '',
            'label' => 'Down',
            'detail' => $err,
        );
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return array(
            'ok' => false,
            'ready' => false,
            'state' => 'bad',
            'phone' => '',
            'label' => 'Error',
        );
    }
    $ready = !empty($data['ready']);
    $phone = isset($data['phone']) ? (string) $data['phone'] : '';
    if ($ready) {
        return array(
            'ok' => true,
            'ready' => true,
            'state' => 'online',
            'phone' => $phone,
            'label' => $phone !== '' ? $phone : 'Online',
        );
    }
    if (!empty($data['has_qr'])) {
        return array(
            'ok' => false,
            'ready' => false,
            'state' => 'qr',
            'phone' => '',
            'label' => 'QR',
        );
    }
    return array(
        'ok' => false,
        'ready' => false,
        'state' => 'offline',
        'phone' => '',
        'label' => 'Offline',
    );
}

function collect_system_status($config)
{
    $disk = system_disk_info();
    $ram = system_ram_info();
    $wa = system_whatsapp_status($config);
    $google = system_http_latency_ms('https://www.google.com/', 5);

    return array(
        'version' => app_version(),
        'php' => PHP_VERSION,
        'server_time' => date('Y-m-d H:i:s'),
        'disk' => $disk,
        'ram' => $ram,
        'whatsapp' => $wa,
        'google' => $google,
    );
}
