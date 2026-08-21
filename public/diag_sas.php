<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

function diag_list($label, $path)
{
    echo "=== $label ===\n";
    echo "path: $path\n";
    if (!is_dir($path)) {
        echo "NOT A DIRECTORY\n\n";
        return;
    }
    $items = @scandir($path);
    if ($items === false) {
        echo "CANNOT READ\n\n";
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $path . '/' . $item;
        echo (is_dir($full) ? '[DIR]  ' : '[FILE] ') . $item . "\n";
    }
    echo "\n";
}

echo "PHP " . PHP_VERSION . "\n";
echo "__DIR__ = " . __DIR__ . "\n\n";

diag_list('public (this folder)', __DIR__);
diag_list('parent of public', dirname(__DIR__));
diag_list('parent/includes', dirname(__DIR__) . '/includes');
diag_list('public/includes', __DIR__ . '/includes');

$candidates = array(
    dirname(__DIR__) . '/includes/bootstrap.php',
    __DIR__ . '/includes/bootstrap.php',
    dirname(__DIR__) . '/bootstrap.php',
    __DIR__ . '/bootstrap.php',
);
echo "=== bootstrap.php search ===\n";
$found = '';
foreach ($candidates as $c) {
    $ok = is_file($c);
    echo ($ok ? 'FOUND  ' : 'missing ') . $c . "\n";
    if ($ok && $found === '') {
        $found = $c;
    }
}

if ($found === '') {
    echo "\nRESULT: bootstrap.php not found. includes folder is in the wrong place.\n";
    echo "It must be:\n";
    echo dirname(__DIR__) . "/includes/bootstrap.php\n";
    exit;
}

echo "\nTrying load: $found\n";
require_once $found;
echo "bootstrap OK\n";
echo 'settings_load: ' . (function_exists('settings_load') ? 'yes' : 'NO') . "\n";
echo 'sas_config: ' . (function_exists('sas_config') ? 'yes' : 'NO') . "\n";
echo "ALL GOOD\n";
