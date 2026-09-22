<?php

require_once __DIR__ . '/../includes/bootstrap.php';

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$isEn = (isset($lang) && $lang === 'en');

if ($token === '') {
    flash('error', $isEn ? 'Missing payment token' : 'رمز الدفع مفقود');
    redirect(!empty($_SESSION['admin_logged_in']) ? 'billing.php' : 'login.php');
}

list($ok, $msg, $tenantId) = saas_handle_zaincash_callback($pdo, $token, $settings);
flash($ok ? 'success' : 'error', $msg);

if (!empty($_SESSION['admin_logged_in'])) {
    unset($_SESSION['saas_force_billing']);
    redirect('billing.php');
}
redirect('login.php');
