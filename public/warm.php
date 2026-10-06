<?php

ignore_user_abort(true);
@set_time_limit(80);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/portal_warm.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $key = isset($_GET['key']) ? (string) $_GET['key'] : '';
    $secret = portal_warm_secret();
    if ($secret === '' || !hash_equals($secret, $key)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$lockPath = portal_warm_cache_dir() . '/warm.lock';
$lockFh = @fopen($lockPath, 'c');
if (!$lockFh || !@flock($lockFh, LOCK_EX | LOCK_NB)) {
    echo "busy\n";
    exit;
}

portal_warm_remember_base();
if (!headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
}
echo "ok\n";
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_flush();
    @flush();
}

$soon = false;
try {
    $soon = portal_warm_run($pdo, $config);
} catch (Exception $e) {
}
@flock($lockFh, LOCK_UN);
@fclose($lockFh);
portal_warm_schedule($soon ? 3 : 45);
