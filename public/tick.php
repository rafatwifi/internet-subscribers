<?php
/**
 * خلفية تلقائية: تذكير قرب الانتهاء + القطع حسب إعدادات الجدول الدوري.
 * يُستدعى من الواجهة كل دقيقتين تقريباً — بدون كرون.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
if (function_exists('app_session_close')) {
    app_session_close();
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
echo json_encode(array('ok' => $ok));
