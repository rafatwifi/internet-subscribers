<?php
/**
 * تشخيص تقارير — احذف بعد الإصلاح
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';

echo "PHP " . PHP_VERSION . "\n";
echo "archives.php loaded: " . (function_exists('compute_month_stats') ? 'yes' : 'no') . "\n\n";

try {
    echo "1 ensure table…\n";
    ensure_monthly_archives_table($pdo);
    echo "OK\n";

    echo "2 archive_closed…\n";
    archive_closed_months($pdo);
    echo "OK\n";

    echo "3 compute stats…\n";
    $s = compute_month_stats($pdo, date('Y-m'));
    print_r($s);

    echo "4 list archives…\n";
    $a = list_monthly_archives($pdo);
    echo "count=" . count($a) . "\n";

    echo "5 columns invoices…\n";
    $cols = $pdo->query('SHOW COLUMNS FROM invoices')->fetchAll();
    foreach ($cols as $c) {
        echo $c['Field'] . "\n";
    }

    echo "\nALL GOOD\n";
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
} catch (Throwable $e) {
    echo "THROWABLE: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
}
