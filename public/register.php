<?php

require_once __DIR__ . '/../includes/bootstrap.php';

if (!empty($_SESSION['admin_logged_in'])) {
    redirect('index.php');
}

$isEn = ($lang === 'en');
$saas = function_exists('saas_settings') ? saas_settings($settings) : array('registration_enabled' => false);
$error = '';
$okMsg = '';

if (empty($saas['registration_enabled'])) {
    $error = $isEn ? 'Registration is closed' : 'التسجيل مغلق حالياً';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($saas['registration_enabled'])) {
    if (!verify_csrf(post('csrf'))) {
        $error = $isEn ? 'Invalid request' : 'طلب غير صالح';
    } else {
        list($ok, $msg) = saas_register_agent(
            $pdo,
            post('agency_name', ''),
            post('username', ''),
            post('display_name', ''),
            (string) post('password', ''),
            post('phone', '')
        );
        if ($ok) {
            $okMsg = $msg;
        } else {
            $error = $msg;
        }
    }
}

$loginBgColor = function_exists('login_bg_color') ? login_bg_color($settings) : '#1b2a38';
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($isEn ? 'Agent register' : 'تسجيل وكيل'); ?> | <?php echo e($siteName); ?></title>
    <link rel="stylesheet" href="assets/app.css?v=2">
    <style>
      body.reg-page { min-height:100vh; margin:0; display:flex; align-items:center; justify-content:center;
        background: linear-gradient(165deg, <?php echo e($loginBgColor); ?> 0%, #0f1720 100%); font-family: Tajawal, sans-serif; }
      .reg-card { width:min(440px, 94vw); background:#fff; border-radius:16px; padding:22px 20px; box-shadow:0 16px 40px rgba(0,0,0,.25); }
      .reg-card h1 { margin:0 0 8px; font-size:1.25rem; }
      .reg-card label { display:block; margin:10px 0 4px; font-weight:600; font-size:13px; }
      .reg-card input { width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #e2e8f0; border-radius:10px; }
      .reg-card .btn { margin-top:14px; width:100%; }
      .reg-card .meta { color:#64748b; font-size:13px; }
      .alert { padding:10px 12px; border-radius:10px; margin-bottom:12px; font-weight:600; }
      .alert-error { background:#fef2f2; color:#b91c1c; }
      .alert-success { background:#ecfdf5; color:#047857; }
    </style>
</head>
<body class="reg-page <?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<div class="reg-card">
    <h1><?php echo e($isEn ? 'Create agent account' : 'إنشاء حساب وكيل'); ?></h1>
    <p class="meta"><?php echo e($isEn
        ? 'After register, wait for admin approval. Then bind your SAS and manage your subscribers.'
        : 'بعد التسجيل انتظر موافقة الإدارة، ثم اربط ساسك وأدر مشتركيك.'); ?></p>
    <?php if ($error !== ''): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($okMsg !== ''): ?><div class="alert alert-success"><?php echo e($okMsg); ?></div><?php endif; ?>
    <?php if (!empty($saas['registration_enabled']) && $okMsg === ''): ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <label><?php echo e($isEn ? 'Agency name' : 'اسم الوكالة'); ?></label>
        <input name="agency_name" required value="<?php echo e(post('agency_name', '')); ?>">
        <label><?php echo e($isEn ? 'Your name' : 'اسمك'); ?></label>
        <input name="display_name" value="<?php echo e(post('display_name', '')); ?>">
        <label><?php echo e($isEn ? 'Username' : 'اسم الدخول'); ?></label>
        <input class="ltr" name="username" required pattern="[A-Za-z0-9._\-]{2,40}" value="<?php echo e(post('username', '')); ?>">
        <label><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?></label>
        <input class="ltr" name="phone" value="<?php echo e(post('phone', '')); ?>">
        <label><?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?></label>
        <input class="ltr" type="password" name="password" required minlength="4">
        <button class="btn" type="submit"><?php echo e($isEn ? 'Register' : 'تسجيل'); ?></button>
    </form>
    <?php endif; ?>
    <p class="meta" style="margin-top:14px"><a href="login.php"><?php echo e($isEn ? 'Back to login' : 'رجوع لتسجيل الدخول'); ?></a></p>
</div>
</body>
</html>
