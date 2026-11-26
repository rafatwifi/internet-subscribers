<?php

$isCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/../includes/bootstrap.php';

if (!$isCli) {
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    $secret = isset($config['cron_secret']) ? (string) $config['cron_secret'] : '';
    if ($secret === '' || !hash_equals($secret, (string) $key)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

if (!function_exists('sas_sync_users_from_api')) {
    echo "sas_cache missing\n";
    exit(1);
}

@set_time_limit(0);
$deadline = time() + 50;
$out = array();

$tenants = array();
try {
    $rows = $pdo->query(
        'SELECT t.id,
                m.sync_offset,
                m.syncing_at,
                m.last_ok_at,
                (SELECT COUNT(*) FROM sas_users_cache c WHERE c.tenant_id = t.id) AS n
         FROM tenants t
         LEFT JOIN sas_sync_meta m ON m.tenant_id = t.id
         ORDER BY
           CASE WHEN IFNULL(m.sync_offset,0) > 0 OR m.syncing_at IS NOT NULL THEN 0 ELSE 1 END,
           CASE WHEN m.last_ok_at IS NULL THEN 0 ELSE 1 END,
           m.last_ok_at ASC,
           n DESC,
           t.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $tid = (int) $row['id'];
        if ($tid <= 0) {
            continue;
        }
        $tenants[] = $row;
    }
} catch (Exception $e) {
    echo "tenants fail\n";
    exit(1);
}

foreach ($tenants as $row) {
    if (time() >= $deadline) {
        break;
    }
    $tid = (int) $row['id'];
    $offset = isset($row['sync_offset']) ? (int) $row['sync_offset'] : 0;
    $syncingAt = !empty($row['syncing_at']) ? strtotime((string) $row['syncing_at']) : 0;
    $lastOk = !empty($row['last_ok_at']) ? strtotime((string) $row['last_ok_at']) : 0;

    if ($syncingAt > 0 && (time() - $syncingAt) > 1800 && $offset <= 0) {
        try {
            if (function_exists('sas_sync_meta_save')) {
                $GLOBALS['portal_warm_tenant_id'] = $tid;
                sas_sync_meta_save($pdo, array('syncing_at' => null));
            }
        } catch (Exception $eClr) {
        }
    }

    $inProgress = ($offset > 0) || ($syncingAt > 0 && (time() - $syncingAt) < 1800);
    $stale = (!$lastOk || (time() - $lastOk) >= 300);
    if (!$inProgress && !$stale) {
        continue;
    }

    $GLOBALS['portal_warm_tenant_id'] = $tid;
    if (function_exists('sas_is_ready') && !sas_is_ready($config)) {
        $out[] = 't' . $tid . '=skip_not_ready';
        continue;
    }

    $reset = (!$inProgress && $stale);
    $guard = 0;
    $ok = false;
    $count = 0;
    $mode = 'error';
    $meta = array();
    do {
        if (time() >= $deadline) {
            break;
        }
        list($ok, $count, $mode, $meta) = sas_sync_users_from_api($pdo, $config, true, $reset);
        $reset = false;
        $guard++;
    } while ($ok && $mode === 'progress' && $guard < 40 && time() < $deadline);

    $err = (!empty($meta['last_error'])) ? (' err=' . $meta['last_error']) : '';
    $out[] = 't' . $tid . '=' . ($ok ? 'OK' : 'FAIL') . ' mode=' . $mode . ' count=' . (int) $count . $err;
}

unset($GLOBALS['portal_warm_tenant_id']);
echo ($out ? implode(' | ', $out) : 'nothing_due') . "\n";
exit(0);
