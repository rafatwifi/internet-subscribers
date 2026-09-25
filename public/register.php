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
        $loginName = trim((string) post('username', ''));
        list($ok, $msg) = saas_register_agent(
            $pdo,
            $loginName,
            $loginName,
            post('display_name', ''),
            (string) post('password', ''),
            post('phone', ''),
            post('email', '')
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
        padding:24px 16px; background:
          radial-gradient(900px 420px at 10% -10%, rgba(56,189,248,.35), transparent 60%),
          radial-gradient(700px 380px at 110% 110%, rgba(45,212,191,.28), transparent 55%),
          linear-gradient(165deg, <?php echo e($loginBgColor); ?> 0%, #0b1220 100%);
        font-family: Tajawal, sans-serif; }
      .reg-card { width:min(560px, 96vw); background:rgba(255,255,255,.96); border:1px solid rgba(255,255,255,.7);
        border-radius:24px; padding:28px 26px 22px; box-shadow:0 24px 60px rgba(8,14,22,.28); }
      .reg-kicker { display:inline-block; margin:0 0 8px; padding:4px 10px; border-radius:999px; background:#e0f2fe; color:#0369a1; font-size:12px; font-weight:800; }
      .reg-card h1 { margin:0 0 6px; font-size:26px; font-weight:800; color:#0f172a; }
      .reg-card .meta { color:#64748b; font-size:14px; margin:0 0 18px; line-height:1.6; }
      .reg-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 14px; }
      .reg-grid .full { grid-column:1 / -1; }
      .reg-card label { display:block; margin:0 0 6px; font-weight:700; font-size:13px; color:#334155; }
      .reg-card input { width:100%; box-sizing:border-box; height:48px; padding:0 14px; border:1px solid #e2e8f0; border-radius:14px; background:#fff; font:inherit; font-size:15px; }
      .reg-card input:focus { outline:none; border-color:#38bdf8; box-shadow:0 0 0 4px rgba(56,189,248,.18); }
      .reg-card .btn { margin-top:16px; width:100%; height:48px; border:0; border-radius:14px; background:#0f766e; color:#fff; font:inherit; font-size:16px; font-weight:800; cursor:pointer; box-shadow:0 10px 22px rgba(15,118,110,.28); }
      .reg-back { display:inline-block; margin-top:14px; color:#0f766e; font-weight:800; text-decoration:none; }
      .alert { padding:12px 14px; border-radius:14px; margin-bottom:14px; font-weight:700; }
      .alert-error { background:#fef2f2; color:#b91c1c; }
      .alert-success { background:#ecfdf5; color:#047857; }
      @media (max-width:640px) { .reg-grid { grid-template-columns:1fr; } .reg-card { padding:22px 16px; } .reg-card h1 { font-size:22px; } }
    </style>
</head>
<body class="reg-page <?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<div class="reg-card">
    <div class="reg-kicker"><?php echo e($siteName); ?></div>
    <h1><?php echo e($isEn ? 'Create agent account' : 'إنشاء حساب وكيل'); ?></h1>
    <p class="meta"><?php echo e($isEn
        ? 'After register, wait for admin approval. Then bind your SAS and manage your subscribers.'
        : 'بعد التسجيل انتظر موافقة الإدارة، ثم اربط ساسك وأدر مشتركيك.'); ?></p>
    <?php if ($error !== ''): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($okMsg !== ''): ?><div class="alert alert-success"><?php echo e($okMsg); ?></div><?php endif; ?>
    <?php if (!empty($saas['registration_enabled']) && $okMsg === ''): ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <div class="reg-grid">
            <div>
                <label><?php echo e($isEn ? 'Your name' : 'اسمك'); ?></label>
                <input name="display_name" value="<?php echo e(post('display_name', '')); ?>" placeholder="<?php echo e($isEn ? 'Your name' : 'الاسم الظاهر'); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Username' : 'اسم الدخول'); ?></label>
                <input class="ltr" name="username" required pattern="[A-Za-z0-9._@\-]{2,40}" value="<?php echo e(post('username', '')); ?>" placeholder="wifi@name">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?></label>
                <input class="ltr" name="phone" value="<?php echo e(post('phone', '')); ?>" placeholder="07xxxxxxxxx">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?></label>
                <input class="ltr" type="password" name="password" required minlength="4" placeholder="••••">
            </div>
            <div class="full">
                <label><?php echo e($isEn ? 'Email' : 'الإيميل'); ?></label>
                <input class="ltr" type="email" name="email" value="<?php echo e(post('email', '')); ?>" placeholder="name@example.com">
            </div>
        </div>
        <button class="btn" type="submit"><?php echo e($isEn ? 'Create account' : 'إنشاء الحساب'); ?></button>
    </form>
    <?php endif; ?>
    <a class="reg-back" href="login.php"><?php echo e($isEn ? 'Back to login' : 'رجوع لتسجيل الدخول'); ?></a>
</div>
</body>
</html>
