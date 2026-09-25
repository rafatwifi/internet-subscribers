<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
    flash('error', 'هذه الصفحة لأدمن المنصة');
    redirect('index.php');
}

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
if ($code === '' || !function_exists('gdrive_exchange_code')) {
    flash('error', 'ما رجع رمز من كوكل');
    redirect('backup.php');
}

list($ok, $msg) = gdrive_exchange_code($code);
flash($ok ? 'success' : 'error', $ok ? 'تم ربط كوكل درايف' : $msg);
redirect('backup.php');
