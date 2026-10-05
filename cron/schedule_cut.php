<?php

$isCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/../includes/bootstrap.php';

if (!$isCli) {
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    if (!hash_equals((string) $config['cron_secret'], (string) $key)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$pdo->exec(
    "UPDATE subscriptions SET status = 'expired'
     WHERE status = 'active' AND end_date < CURDATE()"
);

$cut = array('enabled' => false, 'checked' => 0, 'cut' => 0, 'by_tenant' => array());
if (function_exists('schedule_each_tenant') && function_exists('run_schedule_debt_cuts')) {
    $parts = schedule_each_tenant($pdo, $config, function ($tid, $cfg, $sched) use ($pdo) {
        return run_schedule_debt_cuts($pdo, $cfg, 100);
    });
    $cut['by_tenant'] = $parts;
    foreach ($parts as $part) {
        if (!is_array($part)) {
            continue;
        }
        if (!empty($part['enabled'])) {
            $cut['enabled'] = true;
        }
        $cut['checked'] += isset($part['checked']) ? (int) $part['checked'] : 0;
        $cut['cut'] += isset($part['cut']) ? (int) $part['cut'] : 0;
    }
} elseif (function_exists('run_schedule_debt_cuts')) {
    $cut = run_schedule_debt_cuts($pdo, $config, 100);
}

$summary = array(
    'schedule_cut' => $cut,
    'time' => date('Y-m-d H:i:s'),
);

$json = json_encode($summary);
if ($isCli) {
    echo $json . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
}
