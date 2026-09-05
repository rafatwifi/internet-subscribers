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

$graceDays = isset($config['grace_days']) ? (int) $config['grace_days'] : 2;

$pdo->exec(
    "UPDATE subscriptions SET status = 'expired'
     WHERE status = 'active' AND end_date < CURDATE()"
);

$sql = "SELECT i.*, s.name, s.phone
        FROM invoices i
        JOIN subscribers s ON s.id = i.subscriber_id
        WHERE i.status = 'unpaid'
          AND i.due_date <= DATE_SUB(CURDATE(), INTERVAL :grace DAY)
          AND (
                i.reminder_sent_at IS NULL
                OR i.reminder_sent_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
              )";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':grace', $graceDays, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$sent = 0;
$failed = 0;
$skipped = 0;

foreach ($rows as $row) {
    $row['_wa_case'] = 'reminder_auto';
    $msg = reminder_message($row, $config);
    $result = whatsapp_send($config, $row['phone'], $msg, 'reminder_auto');
    log_message($pdo, (int) $row['subscriber_id'], $result);

    if (!empty($result['success'])) {
        $pdo->prepare(
            'UPDATE invoices
             SET reminder_sent_at = NOW(), reminder_count = reminder_count + 1
             WHERE id = :id'
        )->execute(array(':id' => $row['id']));
        $sent++;
    } elseif (!empty($result['skipped'])) {
        $skipped++;
    } else {
        $failed++;
    }
}

$expiry = run_expiry_soon_reminders($pdo, $config, 200);

$summary = array(
    'debt' => array(
        'checked' => count($rows),
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'grace_days' => $graceDays,
    ),
    'expiry_soon' => $expiry,
    'expiry_auto_enabled' => !empty($config['expiry_auto_remind_enabled']),
    'expiry_auto_days' => isset($config['expiry_auto_remind_days']) ? (int) $config['expiry_auto_remind_days'] : 1,
    'time' => date('Y-m-d H:i:s'),
);

$json = json_encode($summary);
if ($isCli) {
    echo $json . PHP_EOL;
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
}
