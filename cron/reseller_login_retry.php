<?php

/**
 * إعادة محاولة دخول حسابات الريسيلر الفاشلة بجدول متدرّج وخفيف على المعالج.
 * يُشغَّل كل دقيقة من cron؛ يعالج حساباً واحداً مستحقاً فقط.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';

$lockPath = dirname(__DIR__) . '/storage/cache/reseller_login_retry.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lock = @fopen($lockPath, 'c');
if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

@set_time_limit(55);
if (function_exists('tenant_sas_accounts_retry_due')) {
    try {
        $n = tenant_sas_accounts_retry_due($pdo, $config, 1);
        if ($n > 0) {
            echo 'retried=' . (int) $n . "\n";
        }
    } catch (Exception $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    } catch (Error $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

exit(0);
