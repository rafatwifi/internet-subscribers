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

$cut = function_exists('run_schedule_debt_cuts')
    ? run_schedule_debt_cuts($pdo, $config, 100)
    : array('enabled' => false, 'checked' => 0, 'cut' => 0);

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
