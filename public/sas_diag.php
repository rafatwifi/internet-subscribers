<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "PHP " . PHP_VERSION . "\n";
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

echo "bootstrap OK\n";
echo "sas_ready=" . (function_exists('sas_is_ready') && sas_is_ready($config) ? 'yes' : 'NO') . "\n";
echo "listUsersPage=" . (class_exists('SASConnector') && method_exists('SASConnector', 'listUsersPage') ? 'yes' : 'NO') . "\n";

try {
    ensure_sas_users_cache_table($pdo);
    echo "cache_table OK count=" . (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache')->fetchColumn() . "\n";
} catch (Exception $e) {
    echo "cache_table FAIL " . $e->getMessage() . "\n";
}

$api = sas_make_connector($config);
if (!$api) {
    echo "connector NO\n";
    exit;
}
$api->setTimeout(15);
echo "login=" . ($api->login() ? 'OK' : ('FAIL ' . $api->getLastError())) . "\n";
$one = $api->getFirstUser();
echo "firstUser=" . (is_array($one) ? json_encode(array_keys($one)) : 'empty') . "\n";
if (is_array($one)) {
    echo "username=" . (isset($one['username']) ? $one['username'] : '') . "\n";
}
if (method_exists($api, 'listUsersPage')) {
    $pg = $api->listUsersPage(0, 1, '');
    echo "list1 ok=" . (!empty($pg['ok']) ? '1' : '0') . " rows=" . (isset($pg['rows']) ? count($pg['rows']) : 0) . "\n";
    echo "list1 via=" . (isset($pg['via']) ? $pg['via'] : '') . "\n";
    echo "list1 msg=" . (isset($pg['message']) ? $pg['message'] : '') . "\n";
}
echo "DONE\n";
