<?php
/**
 * تشخيص مؤقت لخطأ 500 — احذفه بعد الإصلاح
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "PHP " . PHP_VERSION . "\n";
echo "password_hash: " . (function_exists('password_hash') ? 'yes' : 'NO') . "\n";
echo "pdo_mysql: " . (extension_loaded('pdo_mysql') ? 'yes' : 'NO') . "\n\n";

$steps = array();
try {
    $steps[] = 'load bootstrap…';
    require_once __DIR__ . '/../includes/bootstrap.php';
    $steps[] = 'bootstrap OK';
    $steps[] = 'admin_users ensure…';
    ensure_admin_users_table($pdo, $config);
    $steps[] = 'admin_users OK count=' . (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    $steps[] = 'activity ensure…';
    ensure_activity_logs_table($pdo);
    $steps[] = 'activity OK';
    if (function_exists('ensure_monthly_archives_table')) {
        ensure_monthly_archives_table($pdo);
        $steps[] = 'archives OK';
    } else {
        $steps[] = 'archives missing (stub)';
    }
    echo "ALL GOOD\n\n";
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n\n";
} catch (Throwable $e) {
    echo "THROWABLE: " . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n\n";
}

echo implode("\n", $steps) . "\n";
