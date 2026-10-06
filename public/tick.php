<?php
/**
 * خلفية تلقائية: تذكير قرب الانتهاء + القطع حسب إعدادات الجدول الدوري.
 * يُستدعى من الواجهة كل دقيقتين تقريباً — بدون كرون.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/portal_warm.php';
require_login();
if (function_exists('app_session_close')) {
    app_session_close();
}
if (function_exists('portal_warm_kick')) {
    try {
        portal_warm_kick();
    } catch (Exception $e) {
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ok = true;
if (function_exists('maybe_run_auto_schedule_jobs')) {
    try {
        @maybe_run_auto_schedule_jobs($pdo, $config);
    } catch (Exception $e) {
        $ok = false;
    }
} elseif (function_exists('maybe_run_expiry_auto_reminders')) {
    try {
        @maybe_run_expiry_auto_reminders($pdo, $config);
    } catch (Exception $e) {
        $ok = false;
    }
}
if (function_exists('backup_auto_tick')) {
    try {
        @backup_auto_tick($pdo);
    } catch (Exception $e) {
    }
}
if (function_exists('wa_retry_due_batch')) {
    try {
        @wa_retry_due_batch($pdo, $config, 1);
    } catch (Exception $e) {
    }
}
echo json_encode(array('ok' => $ok));
