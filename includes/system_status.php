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

function system_format_ms($ms)
{
    if ($ms === null || $ms === '') {
        return '—';
    }
    return number_format((float) $ms, 1) . ' ms';
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

    // Windows fallback
    if ($source !== 'host' && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && function_exists('shell_exec')) {
        $ps = @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_OperatingSystem | Select-Object TotalVisibleMemorySize,FreePhysicalMemory | ConvertTo-Json -Compress)"');
        if (is_string($ps) && preg_match('/TotalVisibleMemorySize["\']?\s*:\s*(\d+)/i', $ps, $tm)
            && preg_match('/FreePhysicalMemory["\']?\s*:\s*(\d+)/i', $ps, $fm)) {
            $total = (float) $tm[1] * 1024;
            $available = (float) $fm[1] * 1024;
            if ($total > 0) {
                $source = 'host';
            }
        }
    }

    $pct = 0;
    $used = 0;
    $label = format_bytes_short($phpUsed) . ' PHP';
    if ($source === 'host' && $total > 0) {
        $used = max(0, $total - $available);
        $pct = (int) round(($used / $total) * 100);
        // مستخدم / الإجمالي (مو المتاح)
        $label = format_bytes_short($used) . ' / ' . format_bytes_short($total);
    }

    return array(
        'ok' => true,
        'source' => $source,
        'php_used' => $phpUsed,
        'php_peak' => $phpPeak,
        'php_limit' => $phpLimit,
        'total' => $total,
        'available' => $available,
        'used' => $used,
        'pct_used' => $pct,
        'label' => $label,
    );
}

/**
 * ICMP ping with 1 decimal ms (preferred). Falls back to HTTP HEAD timing.
 */
function system_icmp_ping_ms($host, $timeoutSec = 3)
{
    $host = trim((string) $host);
    $host = preg_replace('#^https?://#i', '', $host);
    $host = preg_replace('#/.*$#', '', $host);
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === '' || !preg_match('/^[a-zA-Z0-9.\-:]+$/', $host)) {
        return array('ok' => false, 'ms' => null, 'error' => 'bad_host', 'method' => '');
    }
    if (!function_exists('shell_exec')) {
        return array('ok' => false, 'ms' => null, 'error' => 'no_shell', 'method' => '');
    }
    $timeoutSec = max(1, min(8, (int) $timeoutSec));
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        $cmd = 'ping -n 1 -w ' . ($timeoutSec * 1000) . ' ' . escapeshellarg($host) . ' 2>&1';
    } else {
        // -c 1 one packet; -W timeout seconds (Linux); busybox may use -w)
        $cmd = 'ping -c 1 -W ' . $timeoutSec . ' ' . escapeshellarg($host) . ' 2>&1';
    }
    $out = @shell_exec($cmd);
    if (!is_string($out) || $out === '') {
        return array('ok' => false, 'ms' => null, 'error' => 'empty', 'method' => 'icmp');
    }
    $ms = null;
    if (preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $out, $m)) {
        $ms = round((float) $m[1], 1);
    } elseif (preg_match('/Average\s*=\s*([\d.]+)\s*ms/i', $out, $m)) {
        $ms = round((float) $m[1], 1);
    } elseif (preg_match('/=\s*([\d.]+)\/([\d.]+)\/([\d.]+)/', $out, $m)) {
        // rtt min/avg/max
        $ms = round((float) $m[2], 1);
    }
    if ($ms === null) {
        return array('ok' => false, 'ms' => null, 'error' => 'parse', 'method' => 'icmp', 'raw' => substr($out, 0, 200));
    }
    return array('ok' => true, 'ms' => $ms, 'error' => '', 'method' => 'icmp');
}

function system_http_latency_ms($url, $timeout = 5)
{
    $ch = curl_init($url);
    if ($ch === false) {
        return array('ok' => false, 'ms' => null, 'error' => 'curl', 'method' => 'http');
    }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_NOBODY => true,
        CURLOPT_USERAGENT => 'WiFiNetSales-Health/1.0',
    ));
    curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $connect = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
    curl_close($ch);
    // زمن الاتصال أدق للـ latency من كامل الطلب مع التحويلات
    $sec = (is_numeric($connect) && (float) $connect > 0) ? (float) $connect : (float) $total;
    $ms = ($sec > 0) ? round($sec * 1000, 1) : null;
    $ok = ($err === '' && $code > 0 && $code < 500) || ($ms !== null && $ms > 0 && $err === '');
    return array(
        'ok' => $ok,
        'ms' => $ms,
        'http_code' => $code,
        'error' => $err,
        'method' => 'http',
    );
}

function system_latency_to_host($host, $httpUrl = '')
{
    $ping = system_icmp_ping_ms($host, 3);
    if (!empty($ping['ok']) && $ping['ms'] !== null) {
        return $ping;
    }
    if ($httpUrl === '') {
        $httpUrl = 'https://' . $host . '/';
    }
    $http = system_http_latency_ms($httpUrl, 5);
    if (!empty($http['ok']) || ($http['ms'] !== null && $http['ms'] > 0)) {
        return $http;
    }
    if (strpos($httpUrl, 'https://') === 0) {
        $http2 = system_http_latency_ms('http://' . $host . '/', 4);
        if (!empty($http2['ok']) || ($http2['ms'] !== null && (empty($http['ms']) || $http2['ms'] < $http['ms']))) {
            return $http2;
        }
    }
    return !empty($ping['ms']) ? $ping : $http;
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

function system_cpu_sample_linux()
{
    if (!is_readable('/proc/stat')) {
        return null;
    }
    $raw = @file_get_contents('/proc/stat');
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $line = strtok($raw, "\n");
    if (!$line || strpos($line, 'cpu ') !== 0) {
        return null;
    }
    $parts = preg_split('/\s+/', trim($line));
    if (!$parts || count($parts) < 5) {
        return null;
    }
    $user = isset($parts[1]) ? (float) $parts[1] : 0;
    $nice = isset($parts[2]) ? (float) $parts[2] : 0;
    $sys = isset($parts[3]) ? (float) $parts[3] : 0;
    $idle = isset($parts[4]) ? (float) $parts[4] : 0;
    $iowait = isset($parts[5]) ? (float) $parts[5] : 0;
    $irq = isset($parts[6]) ? (float) $parts[6] : 0;
    $soft = isset($parts[7]) ? (float) $parts[7] : 0;
    $steal = isset($parts[8]) ? (float) $parts[8] : 0;
    $idleAll = $idle + $iowait;
    $total = $user + $nice + $sys + $idleAll + $irq + $soft + $steal;
    return array('idle' => $idleAll, 'total' => $total);
}

function system_ncpu()
{
    if (is_readable('/proc/cpuinfo')) {
        $raw = @file_get_contents('/proc/cpuinfo');
        if (is_string($raw) && $raw !== '') {
            $n = preg_match_all('/^processor\s*:/m', $raw);
            if ($n > 0) {
                return (int) $n;
            }
        }
    }
    if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows') {
        $env = getenv('NUMBER_OF_PROCESSORS');
        if ($env !== false && (int) $env > 0) {
            return (int) $env;
        }
    }
    if (function_exists('shell_exec')) {
        $out = @shell_exec('nproc 2>/dev/null');
        if (is_string($out) && (int) trim($out) > 0) {
            return (int) trim($out);
        }
    }
    return 1;
}

function system_cpu_from_loadavg()
{
    $load1 = null;
    if (function_exists('sys_getloadavg')) {
        $la = @sys_getloadavg();
        if (is_array($la) && isset($la[0])) {
            $load1 = (float) $la[0];
        }
    }
    if ($load1 === null && is_readable('/proc/loadavg')) {
        $raw = @file_get_contents('/proc/loadavg');
        if (is_string($raw) && preg_match('/^([\d.]+)/', $raw, $m)) {
            $load1 = (float) $m[1];
        }
    }
    if ($load1 === null) {
        return null;
    }
    $n = max(1, system_ncpu());
    $pct = (int) round(($load1 / $n) * 100);
    if ($pct < 0) {
        $pct = 0;
    }
    if ($pct > 100) {
        $pct = 100;
    }
    return $pct;
}

function system_cpu_from_top()
{
    if (!function_exists('shell_exec')) {
        return null;
    }
    // idle% من top — أدق على بعض الـ VPS
    $out = @shell_exec('top -bn1 2>/dev/null | head -n 5');
    if (!is_string($out) || $out === '') {
        $out = @shell_exec('top -bn1 2>/dev/null');
    }
    if (!is_string($out) || $out === '') {
        return null;
    }
    // %Cpu(s): 12.5 us,  3.1 sy, ... 80.2 id
    if (preg_match('/(\d+[.,]\d+)\s*id/i', $out, $m)) {
        $idle = (float) str_replace(',', '.', $m[1]);
        $pct = (int) round(100 - $idle);
        if ($pct < 0) {
            $pct = 0;
        }
        if ($pct > 100) {
            $pct = 100;
        }
        return $pct;
    }
    if (preg_match('/Cpu\(s\):\s*([\d.]+)%us/i', $out, $m)) {
        // old format sometimes only us
        return max(0, min(100, (int) round((float) $m[1])));
    }
    return null;
}

function system_cpu_from_vmstat()
{
    if (!function_exists('shell_exec')) {
        return null;
    }
    $out = @shell_exec('vmstat 1 2 2>/dev/null');
    if (!is_string($out) || $out === '') {
        return null;
    }
    $lines = preg_split('/\r\n|\n|\r/', trim($out));
    $last = '';
    foreach ($lines as $ln) {
        if (preg_match('/^\s*\d+/', $ln)) {
            $last = $ln;
        }
    }
    if ($last === '') {
        return null;
    }
    $parts = preg_split('/\s+/', trim($last));
    // vmstat: ... wa free buff cache si so bi bo in cs us sy id wa st
    if (count($parts) < 15) {
        return null;
    }
    $id = (int) $parts[count($parts) - 3];
    $pct = 100 - $id;
    if ($pct < 0) {
        $pct = 0;
    }
    if ($pct > 100) {
        $pct = 100;
    }
    return $pct;
}

function system_cpu_info()
{
    $pct = null;
    $label = '—';
    $source = '';

    $a = system_cpu_sample_linux();
    if ($a) {
        // عيّنة ثانية بعد ~0.8 ثانية لقراءة حقيقية
        $tEnd = microtime(true) + 0.8;
        while (microtime(true) < $tEnd) {
            usleep(50000);
        }
        $b = system_cpu_sample_linux();
        if ($b && ($b['total'] - $a['total']) > 0) {
            $idle = $b['idle'] - $a['idle'];
            $total = $b['total'] - $a['total'];
            $pct = (int) round((1 - ($idle / $total)) * 100);
            $source = 'proc';
        }
    }

    if ($pct === null || $pct === 0) {
        $fromTop = system_cpu_from_top();
        if ($fromTop !== null && ($pct === null || $fromTop > 0)) {
            $pct = $fromTop;
            $source = 'top';
        }
    }

    if ($pct === null || $pct === 0) {
        $fromVm = system_cpu_from_vmstat();
        if ($fromVm !== null && ($pct === null || $fromVm > 0)) {
            $pct = $fromVm;
            $source = 'vmstat';
        }
    }

    if ($pct === null && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $out = @shell_exec('wmic cpu get loadpercentage /value');
        if (is_string($out) && preg_match('/LoadPercentage=(\d+)/i', $out, $m)) {
            $pct = (int) $m[1];
            $source = 'wmic';
        }
        if ($pct === null && function_exists('shell_exec')) {
            $ps = @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average"');
            if (is_string($ps) && preg_match('/([\d.]+)/', $ps, $m2)) {
                $pct = (int) round((float) $m2[1]);
                $source = 'ps';
            }
        }
    }

    if ($pct === null || $pct === 0) {
        $fromLoad = system_cpu_from_loadavg();
        if ($fromLoad !== null && ($pct === null || $fromLoad > 0)) {
            $pct = $fromLoad;
            $source = 'loadavg';
        }
    }

    if ($pct !== null) {
        if ($pct < 0) {
            $pct = 0;
        }
        if ($pct > 100) {
            $pct = 100;
        }
        $label = $pct . '%';
    }

    return array(
        'ok' => $pct !== null,
        'pct' => $pct,
        'label' => $label,
        'source' => $source,
    );
}

function system_sas_latency($config)
{
    $host = '';
    if (function_exists('sas_config')) {
        $s = sas_config($config);
        $host = isset($s['host']) ? trim((string) $s['host']) : '';
    }
    if ($host === '' && isset($config['sas']['host'])) {
        $host = preg_replace('#^https?://#i', '', rtrim(trim((string) $config['sas']['host']), '/'));
    }
    if ($host === '') {
        return array(
            'ok' => false,
            'ms' => null,
            'host' => '',
            'label' => '—',
            'error' => 'no_host',
        );
    }
    $res = system_latency_to_host($host);
    $ms = isset($res['ms']) ? $res['ms'] : null;
    $ok = !empty($res['ok']) || ($ms !== null && $ms > 0);
    return array(
        'ok' => $ok,
        'ms' => $ms,
        'host' => $host,
        'label' => system_format_ms($ms),
        'method' => isset($res['method']) ? $res['method'] : '',
        'http_code' => isset($res['http_code']) ? $res['http_code'] : 0,
        'error' => isset($res['error']) ? $res['error'] : '',
    );
}

/**
 * إعادة تشغيل / إطفاء السيرفر (يحتاج صلاحيات النظام).
 * @return array [ok(bool), message(string)]
 */
function system_power_action($action)
{
    $action = strtolower(trim((string) $action));
    if ($action !== 'reboot' && $action !== 'shutdown') {
        return array(false, 'إجراء غير معروف');
    }
    if (!function_exists('shell_exec') && !function_exists('exec')) {
        return array(false, 'shell_exec غير متاح على السيرفر');
    }
    $cmds = array();
    if ($action === 'reboot') {
        $cmds = array(
            'sudo -n /sbin/reboot',
            'sudo -n reboot',
            '/sbin/reboot',
            'reboot',
            'sudo -n shutdown -r now',
            'shutdown -r now',
        );
    } else {
        $cmds = array(
            'sudo -n /sbin/poweroff',
            'sudo -n poweroff',
            '/sbin/poweroff',
            'poweroff',
            'sudo -n shutdown -h now',
            'shutdown -h now',
            'sudo -n halt',
            'halt',
        );
    }
    $ran = false;
    foreach ($cmds as $cmd) {
        $full = $cmd . ' > /dev/null 2>&1 &';
        if (function_exists('shell_exec')) {
            @shell_exec($full);
            $ran = true;
            break;
        }
        if (function_exists('exec')) {
            @exec($full);
            $ran = true;
            break;
        }
    }
    if (!$ran) {
        return array(false, 'تعذر تنفيذ الأمر');
    }
    $msg = ($action === 'reboot')
        ? 'تم إرسال أمر إعادة التشغيل — السيرفر راح ينقطع لحظياً'
        : 'تم إرسال أمر الإطفاء — السيرفر راح ينطفي';
    return array(true, $msg);
}

function collect_system_status($config)
{
    $disk = system_disk_info();
    $ram = system_ram_info();
    $wa = system_whatsapp_status($config);
    // بينغ كوكل (8.8.8.8 أدق من HTTP)
    $google = system_latency_to_host('8.8.8.8', 'https://www.google.com/');
    if (empty($google['ok']) || $google['ms'] === null) {
        $google = system_latency_to_host('www.google.com', 'https://www.google.com/');
    }
    $cpu = system_cpu_info();
    $sasLat = system_sas_latency($config);

    return array(
        'version' => app_version(),
        'php' => PHP_VERSION,
        'server_time' => date('Y-m-d H:i:s'),
        'disk' => $disk,
        'ram' => $ram,
        'whatsapp' => $wa,
        'google' => $google,
        'cpu' => $cpu,
        'sas_latency' => $sasLat,
    );
}
