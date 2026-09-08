<?php
/**
 * خلفية خفيفة: تذكير انتهاء الاشتراك بدون إبطاء صفحات الواجهة.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ok = true;
if (function_exists('maybe_run_expiry_auto_reminders')) {
    try {
        @maybe_run_expiry_auto_reminders($pdo, $config);
    } catch (Exception $e) {
        $ok = false;
    }
}
echo json_encode(array('ok' => $ok));
