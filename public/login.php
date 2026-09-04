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
$loginBgUrl = login_bg_url($settings);
$loginBgColor = login_bg_color($settings);
$bgMode = function_exists('app_bg_mode') ? app_bg_mode($settings) : 'color';
$useImage = ($bgMode === 'image' && $loginBgUrl !== '');
$loginBgCss = $useImage
    ? ('url("' . htmlspecialchars($loginBgUrl, ENT_QUOTES, 'UTF-8') . '")')
    : ('radial-gradient(1200px 700px at 12% 8%, rgba(255,255,255,0.16), transparent 55%), linear-gradient(165deg, ' . $loginBgColor . ' 0%, #0f1720 100%)');
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo e(t('login')); ?> | <?php echo e($siteName); ?></title>
    <link rel="icon" href="assets/favicon.svg?v=2" type="image/svg+xml">
    <link rel="icon" href="assets/favicon.png?v=2" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=login2">
    <style>
        html, body.login-page {
            min-height: 100%;
            min-height: 100dvh;
        }
        body.login-page {
            margin: 0;
            background-color: <?php echo e($loginBgColor); ?> !important;
            background-image: <?php echo $loginBgCss; ?> !important;
            background-size: cover !important;
            background-position: center center !important;
            background-repeat: no-repeat !important;
            background-attachment: scroll !important;
        }
        .login-wrap {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: max(20px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
            background: transparent;
            position: relative;
        }
        .login-wrap::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(10,16,24,0.28) 0%, rgba(10,16,24,0.55) 100%);
            pointer-events: none;
        }
        .login-card {
            position: relative;
            z-index: 1;
            width: min(420px, 100%);
            background: rgba(255,255,255,0.94);
            border: 1px solid rgba(255,255,255,0.7);
            border-radius: 22px;
            padding: 28px 24px 24px;
            box-shadow: 0 24px 60px rgba(8, 14, 22, 0.28);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
        .login-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 8px;
        }
        .login-brand img {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            flex: 0 0 42px;
            background: #1b2a38;
        }
        .login-card h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            color: #1c2430;
            line-height: 1.3;
        }
        .login-card .login-sub {
            margin: 0 0 18px;
            color: #6e7886;
            font-weight: 600;
            font-size: 14px;
        }
        .login-card label {
            color: #334155;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 6px;
        }
        .login-card input[type="text"],
        .login-card input[type="password"] {
            height: 48px;
            font-size: 16px;
            border-radius: 14px;
            background: #fff;
            margin-bottom: 14px;
        }
        .login-pass-wrap {
            position: relative;
        }
        .login-pass-wrap input {
            padding-inline-end: 72px;
            margin-bottom: 14px;
        }
        .login-pass-toggle {
            position: absolute;
            inset-inline-end: 8px;
            top: 8px;
            height: 32px;
            border: 0;
            background: #eef2f6;
            color: #334155;
            border-radius: 10px;
            font: inherit;
            font-size: 12px;
            font-weight: 800;
            padding: 0 10px;
            cursor: pointer;
        }
        .login-card .actions {
            margin-top: 6px;
        }
        .login-card .btn {
            width: 100%;
            height: 48px;
            border-radius: 14px;
            background: #2b6c9a;
            box-shadow: 0 10px 22px rgba(43, 108, 154, 0.28);
            font-size: 16px;
        }
        .login-lang {
            width: 100%;
            margin: 0 0 16px;
            background: #eef2f6;
            justify-content: center;
        }
        .login-lang a {
            flex: 1;
            text-align: center;
            color: #5b6b7a;
        }
        .login-lang a.on {
            background: #2b3640;
            color: #fff;
        }
        @media (max-width: 480px) {
            .login-card {
                padding: 22px 16px 18px;
                border-radius: 18px;
            }
            .login-card h1 { font-size: 20px; }
        }
        @media (min-width: 900px) {
            body.login-page {
                background-attachment: fixed !important;
            }
        }
    </style>
</head>
<body class="login-page <?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<div class="login-wrap">
    <form class="login-card" method="post" autocomplete="on">
        <div class="login-brand">
            <img src="assets/favicon.svg?v=2" alt="">
            <h1><?php echo e($siteName); ?></h1>
        </div>
        <p class="login-sub"><?php echo e(t('login_hint')); ?></p>
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
        <input type="text" name="username" required autofocus autocomplete="username" inputmode="text" placeholder="<?php echo e($isEn ? 'Username' : 'اسم المستخدم'); ?>">

        <label><?php echo e(t('password')); ?></label>
        <div class="login-pass-wrap">
            <input id="loginPassword" type="password" name="password" required autocomplete="current-password" placeholder="<?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?>">
            <button class="login-pass-toggle" type="button" id="loginPassToggle"><?php echo e($isEn ? 'Show' : 'إظهار'); ?></button>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('login')); ?></button>
        </div>
    </form>
</div>
<script>
(function () {
  var btn = document.getElementById('loginPassToggle');
  var inp = document.getElementById('loginPassword');
  if (!btn || !inp) return;
  var show = <?php echo json_encode($isEn ? 'Show' : 'إظهار'); ?>;
  var hide = <?php echo json_encode($isEn ? 'Hide' : 'إخفاء'); ?>;
  btn.addEventListener('click', function () {
    var on = inp.type === 'password';
    inp.type = on ? 'text' : 'password';
    btn.textContent = on ? hide : show;
  });
})();
</script>
</body>
</html>
