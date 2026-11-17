<?php

/**
 * تفعيل تلقائي للمشتركين المنتهين إذا auto_activate_on_expire = 1
 */

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

if (function_exists('ensure_subscriber_auto_activate_column')) {
    ensure_subscriber_auto_activate_column($pdo);
}

$out = array(
    'checked' => 0,
    'activated' => 0,
    'failed' => 0,
    'skipped' => 0,
    'by_tenant' => array(),
    'time' => date('Y-m-d H:i:s'),
);

$runner = function ($tid, $cfg) use ($pdo, &$out) {
    $part = array('tenant_id' => (int) $tid, 'checked' => 0, 'activated' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => array());
    try {
        $st = $pdo->prepare(
            'SELECT s.id AS local_id, s.sas_username, c.username, c.profile_id, c.profile_name, c.expire_at, c.provider
             FROM subscribers s
             INNER JOIN sas_users_cache c
                 ON c.tenant_id = s.tenant_id AND c.username = s.sas_username
             WHERE s.tenant_id = :t
               AND s.auto_activate_on_expire = 1
               AND s.sas_username IS NOT NULL AND s.sas_username <> ""
               AND c.expire_at IS NOT NULL
               AND c.expire_at < NOW()
               AND (c.provider IS NULL OR c.provider = "" OR c.provider = "sas")
             ORDER BY c.expire_at ASC
             LIMIT 30'
        );
        $st->execute(array(':t' => (int) $tid));
        $rows = $st->fetchAll();
    } catch (Exception $e) {
        $part['errors'][] = $e->getMessage();
        return $part;
    }
    foreach ($rows as $row) {
        $part['checked']++;
        $out['checked']++;
        $username = isset($row['username']) ? trim((string) $row['username']) : '';
        if ($username === '' || !function_exists('sas_write_user')) {
            $part['skipped']++;
            $out['skipped']++;
            continue;
        }
        $fields = array(
            'profile_id' => isset($row['profile_id']) ? (string) (int) $row['profile_id'] : '0',
            'profile_name' => isset($row['profile_name']) ? (string) $row['profile_name'] : '',
            'units' => '1',
            'pay_mode' => 'credit',
            'send_whatsapp' => '1',
            'send_old_debts' => '0',
        );
        $write = sas_write_user($pdo, $cfg, 'sas_activate_credit', $username, $fields);
        $ok = is_array($write) && !empty($write[0]);
        $msg = (is_array($write) && isset($write[1])) ? (string) $write[1] : '';
        if ($ok) {
            $part['activated']++;
            $out['activated']++;
            if (function_exists('activity_log')) {
                activity_log(
                    $pdo,
                    (int) $row['local_id'],
                    'subscriber',
                    (int) $row['local_id'],
                    'auto_activate',
                    'تفعيل تلقائي عند الانتهاء',
                    'اليوزرنيم: ' . $username . "\n" . $msg
                );
            }
        } else {
            $part['failed']++;
            $out['failed']++;
            if (count($part['errors']) < 8) {
                $part['errors'][] = $username . ': ' . ($msg !== '' ? $msg : 'fail');
            }
        }
    }
    return $part;
};

if (function_exists('schedule_each_tenant')) {
    $parts = schedule_each_tenant($pdo, $config, function ($tid, $cfg, $sched) use ($runner) {
        return $runner($tid, $cfg);
    });
    $out['by_tenant'] = $parts;
} else {
    $out['by_tenant'][] = $runner(function_exists('current_tenant_id') ? (int) current_tenant_id() : 1, $config);
}

$json = json_encode($out);
if ($isCli) {
    echo $json . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
}
