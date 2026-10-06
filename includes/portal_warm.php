<?php

function portal_warm_cache_dir()
{
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function portal_warm_remember_base()
{
    $port = isset($_SERVER['SERVER_PORT']) ? (string) $_SERVER['SERVER_PORT'] : '';
    $fwd = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $fwd === 'https'
        || $port === '443'
        || $port === '40001';
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : '';
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/.');
    if ($host === '' || $dir === '' || $dir === '/') {
        $saved = portal_warm_saved_base();
        return $saved;
    }
    $base = $scheme . '://' . $host . $dir;
    @file_put_contents(portal_warm_cache_dir() . '/warm_base.txt', $base);
    return $base;
}

function portal_warm_saved_base()
{
    $path = portal_warm_cache_dir() . '/warm_base.txt';
    if (!is_file($path)) {
        return '';
    }
    return trim((string) @file_get_contents($path));
}

function portal_warm_secret()
{
    global $config;
    if (is_array($config) && !empty($config['cron_secret'])) {
        return (string) $config['cron_secret'];
    }
    return '';
}

function portal_warm_fsock($url)
{
    $parts = @parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'http';
    $host = $parts['host'];
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    $path = (isset($parts['path']) ? $parts['path'] : '/');
    if (!empty($parts['query'])) {
        $path .= '?' . $parts['query'];
    }
    $target = ($scheme === 'https' ? 'ssl://' : '') . $host;
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($target, $port, $errno, $errstr, 2);
    if (!$fp) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
    }
    if (!$fp) {
        return false;
    }
    $req = 'GET ' . $path . " HTTP/1.1\r\nHost: " . $host . "\r\nConnection: Close\r\n\r\n";
    @fwrite($fp, $req);
    @stream_set_timeout($fp, 2);
    @fread($fp, 64);
    @fclose($fp);
    return true;
}

function portal_warm_schedule($delaySec)
{
    $base = portal_warm_saved_base();
    if ($base === '') {
        $base = portal_warm_remember_base();
    }
    $secret = portal_warm_secret();
    if ($base === '' || $secret === '') {
        return false;
    }
    $url = $base . '/warm.php?key=' . rawurlencode($secret);
    $delaySec = max(0, (int) $delaySec);
    $inner = 'sleep ' . $delaySec . '; curl -fsS -k --max-time 8 ' . escapeshellarg($url) . ' >/dev/null 2>&1';
    $cmd = 'sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &';
    if (function_exists('popen') && function_exists('pclose')) {
        $h = @popen($cmd, 'r');
        if (is_resource($h)) {
            @pclose($h);
            return true;
        }
    }
    if ($delaySec === 0) {
        return portal_warm_fsock($url);
    }
    return false;
}

function portal_warm_sas_tenants($pdo, $config)
{
    $ids = array();
    try {
        $st = $pdo->query('SELECT id FROM tenants ORDER BY id ASC');
        if ($st) {
            foreach ($st->fetchAll() as $r) {
                $id = isset($r['id']) ? (int) $r['id'] : 0;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
    } catch (Exception $e) {
    }
    if (!$ids) {
        $ids[] = 1;
    }
    $out = array();
    foreach ($ids as $tid) {
        if (!function_exists('sas_config_for_tenant')) {
            continue;
        }
        $s = sas_config_for_tenant($pdo, $config, $tid);
        if (is_array($s) && !empty($s['enabled']) && !empty($s['host']) && !empty($s['username'])) {
            $out[] = $tid;
        }
    }
    return $out;
}

function portal_warm_cards($pdo, $config, $tenantId)
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0) {
        return;
    }
    $prev = isset($GLOBALS['portal_warm_tenant_id']) ? $GLOBALS['portal_warm_tenant_id'] : null;
    $GLOBALS['portal_warm_tenant_id'] = $tenantId;
    $path = function_exists('sas_cards_inventory_cache_path') ? sas_cards_inventory_cache_path() : '';
    $age = ($path !== '' && is_file($path)) ? (time() - (int) @filemtime($path)) : 999999;
    if ($age < 180) {
        if ($prev === null) {
            unset($GLOBALS['portal_warm_tenant_id']);
        } else {
            $GLOBALS['portal_warm_tenant_id'] = $prev;
        }
        return;
    }
    $api = function_exists('sas_make_connector') ? sas_make_connector($config) : null;
    if ($api && method_exists($api, 'setTimeout')) {
        $api->setTimeout(18);
    }
    if ($api && method_exists($api, 'login') && $api->login() && method_exists($api, 'listCardsInventory')) {
        $inv = $api->listCardsInventory(10);
        if (is_array($inv)) {
            if (function_exists('sas_cards_inventory_save_persisted')) {
                sas_cards_inventory_save_persisted($inv);
            }
            if (function_exists('sas_dash_groups_from_inventory') && function_exists('sas_store_dash_card_groups')) {
                sas_store_dash_card_groups(sas_dash_groups_from_inventory($inv), 'warm');
            }
        }
    }
    if ($prev === null) {
        unset($GLOBALS['portal_warm_tenant_id']);
    } else {
        $GLOBALS['portal_warm_tenant_id'] = $prev;
    }
}

function portal_warm_run($pdo, $config)
{
    @file_put_contents(portal_warm_cache_dir() . '/warm_beat.txt', (string) time());
    $tenants = portal_warm_sas_tenants($pdo, $config);
    $soon = false;
    foreach ($tenants as $tid) {
        $GLOBALS['portal_warm_tenant_id'] = $tid;
        $meta = function_exists('sas_sync_meta') ? sas_sync_meta($pdo, $tid) : array();
        $offset = isset($meta['sync_offset']) ? (int) $meta['sync_offset'] : 0;
        $last = !empty($meta['last_ok_at']) ? strtotime((string) $meta['last_ok_at']) : 0;
        $age = $last ? (time() - $last) : 999999;
        if ($offset > 0 || $age > 180) {
            $GLOBALS['sas_warm_pages'] = 2;
            $mode = 'error';
            try {
                list($ok, $n, $mode, $meta2) = sas_sync_users_from_api($pdo, $config, false, false);
            } catch (Exception $e) {
                $mode = 'error';
            }
            unset($GLOBALS['sas_warm_pages']);
            if ($mode === 'progress') {
                $soon = true;
            }
            break;
        }
    }
    foreach ($tenants as $tid) {
        $flag = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'isp_sas_online_flags_t' . (int) $tid . '.txt';
        $age = is_file($flag) ? (time() - (int) @filemtime($flag)) : 999999;
        if ($age < 40) {
            continue;
        }
        $GLOBALS['portal_warm_tenant_id'] = $tid;
        try {
            if (function_exists('sas_refresh_online_flags')) {
                sas_refresh_online_flags($pdo, $config);
            }
        } catch (Exception $e) {
        }
        break;
    }
    $cardTid = 0;
    $cardAge = -1;
    foreach ($tenants as $tid) {
        $GLOBALS['portal_warm_tenant_id'] = $tid;
        $path = function_exists('sas_cards_inventory_cache_path') ? sas_cards_inventory_cache_path() : '';
        $age = ($path !== '' && is_file($path)) ? (time() - (int) @filemtime($path)) : 999999;
        if ($age > $cardAge) {
            $cardAge = $age;
            $cardTid = $tid;
        }
    }
    if ($cardTid > 0 && $cardAge >= 600 && !$soon) {
        try {
            portal_warm_cards($pdo, $config, $cardTid);
        } catch (Exception $e) {
        }
    }
    unset($GLOBALS['portal_warm_tenant_id'], $GLOBALS['sas_warm_pages']);
    @file_put_contents(portal_warm_cache_dir() . '/warm_beat.txt', (string) time());
    return $soon;
}

function portal_warm_kick()
{
    $beat = portal_warm_cache_dir() . '/warm_beat.txt';
    $age = is_file($beat) ? (time() - (int) trim((string) @file_get_contents($beat))) : 999999;
    if ($age < 35) {
        return false;
    }
    portal_warm_remember_base();
    return portal_warm_schedule(0);
}
