<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';

$lockPath = dirname(__DIR__) . '/storage/cache/sas_pulse_slow.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

@set_time_limit(40);
$tid = 0;
try {
    $tid = (int) $pdo->query(
        'SELECT t.id FROM tenants t
         ORDER BY (SELECT COUNT(*) FROM sas_users_cache c WHERE c.tenant_id = t.id) DESC, t.id ASC
         LIMIT 1'
    )->fetchColumn();
} catch (Exception $e) {
    $tid = 0;
}
if ($tid <= 0) {
    exit(0);
}
$GLOBALS['portal_warm_tenant_id'] = $tid;

if (function_exists('dash_reseller_pings')) {
    try {
        dash_reseller_pings($config, true);
    } catch (Exception $e) {
    }
}
if (function_exists('sas_refresh_online_flags')) {
    try {
        sas_refresh_online_flags($pdo, $config);
    } catch (Exception $e) {
    }
}
if (function_exists('sas_manager_reward_points')) {
    $GLOBALS['sas_points_force'] = 1;
    try {
        sas_manager_reward_points($config, $pdo);
    } catch (Exception $e) {
    }
    unset($GLOBALS['sas_points_force']);
}
if (function_exists('sas_make_connector') && function_exists('sas_dash_card_groups')) {
    try {
        $api = sas_make_connector($config);
        if ($api) {
            if (method_exists($api, 'setTimeout')) {
                $api->setTimeout(8);
            }
            sas_dash_card_groups($api, true);
        }
    } catch (Exception $e) {
    }
}

exit(0);
