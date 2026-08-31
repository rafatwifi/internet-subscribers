<?php

require_once __DIR__ . '/../includes/bootstrap.php';

if (!empty($_SESSION['admin_logged_in'])) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chosen = post('language');
    if ($chosen === 'ar' || $chosen === 'en') {
        set_lang_preference($chosen);
        settings_save(array('language' => $chosen));
        $lang = $chosen;
        $GLOBALS['lang'] = $lang;
    }

    if (!verify_csrf(post('csrf'))) {
        $error = ($lang === 'en') ? 'Invalid request' : 'طلب غير صالح';
    } elseif (attempt_login($pdo, $config, (string) post('username', ''), (string) post('password', ''))) {
        activity_log($pdo, null, 'system', null, 'login', 'تسجيل دخول: ' . current_admin_label(), '');
        redirect('index.php');
    } else {
        $error = ($lang === 'en') ? 'Wrong username or password' : 'اسم المستخدم أو كلمة المرور غير صحيحة';
    }
}

$isEn = ($lang === 'en');
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(t('login')); ?> | <?php echo e($siteName); ?></title>
    <link rel="icon" href="assets/favicon.svg?v=2" type="image/svg+xml">
    <link rel="icon" href="assets/favicon.png?v=2" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=sas101">
</head>
<body class="<?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<div class="login-wrap">
    <form class="login-card" method="post">
        <h1><?php echo e($siteName); ?></h1>
        <p style="color:var(--muted);font-weight:600"><?php echo e(t('login_hint')); ?></p>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">

        <label><?php echo e(t('choose_lang')); ?></label>
        <div class="lang-toggle login-lang">
            <a class="<?php echo !$isEn ? 'on' : ''; ?>" href="login.php?lang=ar">العربية</a>
            <a class="<?php echo $isEn ? 'on' : ''; ?>" href="login.php?lang=en">English</a>
        </div>
        <input type="hidden" name="language" value="<?php echo e($lang); ?>">

        <label><?php echo e($isEn ? 'Username' : 'اسم المستخدم'); ?></label>
        <input type="text" name="username" required autofocus autocomplete="username" placeholder="admin / staff">

        <label><?php echo e(t('password')); ?></label>
        <input type="password" name="password" required autocomplete="current-password">
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('login')); ?></button>
        </div>
        <p style="color:var(--muted);font-size:12px;margin-top:10px;font-weight:600">
            <?php echo e($isEn ? 'Default users: admin, staff' : 'المستخدمون الافتراضيون: admin و staff'); ?>
        </p>
    </form>
</div>
</body>
</html>
