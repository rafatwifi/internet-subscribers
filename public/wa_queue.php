<?php

ignore_user_abort(true);
@set_time_limit(150);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/portal_warm.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $key = isset($_GET['key']) ? (string) $_GET['key'] : '';
    $secret = portal_warm_secret();
    if ($secret === '' || !function_exists('hash_equals') || !hash_equals($secret, $key)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$lockPath = portal_warm_cache_dir() . '/wa_queue.lock';
$lockFh = @fopen($lockPath, 'c');
if (!$lockFh || !@flock($lockFh, LOCK_EX | LOCK_NB)) {
    echo "busy\n";
    exit;
}

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

$more = false;
try {
    if (function_exists('wa_resend_run')) {
        $more = wa_resend_run($pdo, $config, 2);
    }
} catch (Exception $e) {
    $more = false;
}
@flock($lockFh, LOCK_UN);
@fclose($lockFh);
if ($more && function_exists('wa_resend_kick')) {
    wa_resend_kick(1);
}
