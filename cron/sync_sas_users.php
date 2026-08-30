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

$reset = true;
$ok = false;
$count = 0;
$mode = 'error';
$meta = array();
$guard = 0;
do {
    list($ok, $count, $mode, $meta) = sas_sync_users_from_api($pdo, $config, true, $reset);
    $reset = false;
    $guard++;
} while ($ok && $mode === 'progress' && $guard < 400);

$msg = ($ok ? 'OK' : 'FAIL') . ' mode=' . $mode . ' count=' . (int) $count;
if (!empty($meta['last_error'])) {
    $msg .= ' error=' . $meta['last_error'];
}
echo $msg . "\n";
exit($ok ? 0 : 1);
