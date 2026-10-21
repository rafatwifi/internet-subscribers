<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';

$lockPath = dirname(__DIR__) . '/storage/cache/sas_pulse.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lock = @fopen($lockPath, 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

@set_time_limit(0);
$deadline = time() + 55;
$lastPing = 0;
$loopN = 0;
$slowStamp = dirname(__DIR__) . '/storage/cache/sas_pulse_slow.stamp';
if (!is_file($slowStamp) || (time() - (int) @filemtime($slowStamp)) >= 8) {
    @touch($slowStamp);
    $php = PHP_BINARY ? PHP_BINARY : 'php';
    $slow = escapeshellarg(__DIR__ . '/sas_pulse_slow.php');
    @exec($php . ' ' . $slow . ' >/dev/null 2>&1 &');
}

$tenants = array();
try {
    $rows = $pdo->query(
        'SELECT t.id, (SELECT COUNT(*) FROM sas_users_cache c WHERE c.tenant_id = t.id) AS n
         FROM tenants t ORDER BY n DESC, t.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if ($id <= 0) {
            continue;
        }
        $GLOBALS['portal_warm_tenant_id'] = $id;
        if (function_exists('sas_is_ready') && sas_is_ready($config)) {
            $tenants[] = $id;
        }
    }
} catch (Exception $e) {
    $tenants = array();
}

if (!$tenants) {
    exit(0);
}

while (time() < $deadline) {
    $loopN++;
    $loopStart = time();
    $primary = (int) $tenants[0];
    $due = array($primary);
    foreach ($tenants as $tid) {
        $tid = (int) $tid;
        if ($tid === $primary) {
            continue;
        }
        $file = dirname(__DIR__) . '/storage/cache/sas_dash_live_t' . $tid . '.json';
        $age = is_file($file) ? (time() - (int) @filemtime($file)) : 99;
        if ($age >= 8) {
            $due[] = $tid;
            break;
        }
    }
    foreach ($due as $tid) {
        $GLOBALS['portal_warm_tenant_id'] = (int) $tid;
        if (function_exists('sas_dash_live_refresh')) {
            try {
                sas_dash_live_refresh($pdo, $config, true, ($loopN % 5) === 0);
            } catch (Exception $e) {
            }
        }
    }
    $spent = time() - $loopStart;
    if ($spent < 2) {
        sleep(2 - $spent);
    }
}

exit(0);
