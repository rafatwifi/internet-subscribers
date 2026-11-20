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
        if (!empty($_SESSION['saas_force_billing'])) {
            redirect('billing.php');
        }
        redirect('index.php');
    } else {
        $reason = isset($GLOBALS['login_block_reason']) ? $GLOBALS['login_block_reason'] : '';
        if ($reason === 'pending') {
            redirect('register.php?wait=1');
        } elseif ($reason === 'suspended') {
            $error = ($lang === 'en') ? 'Account suspended' : 'الحساب معلّق';
        } else {
            $error = ($lang === 'en') ? 'Wrong username or password' : 'اسم المستخدم أو كلمة المرور غير صحيحة';
        }
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
$brandIconUrl = function_exists('brand_icon_url') ? brand_icon_url($settings) : '';
$brandSrc = ($brandIconUrl !== '') ? $brandIconUrl : 'assets/favicon.svg?v=2';
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
    <link rel="stylesheet" href="assets/style.css?v=login6">
    <link rel="stylesheet" href="assets/gate.css?v=gate5">

</head>
<body class="login-page <?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<canvas id="loginField" class="login-field" aria-hidden="true"></canvas>
<style>
        .login-photo { background-color: <?php echo e($loginBgColor); ?>; background-image: <?php echo $loginBgCss; ?>; opacity: <?php echo $useImage ? '0.22' : '0'; ?>; }
        .login-card .alert-error { background: rgba(127, 29, 29, 0.55); color: #fecaca; border: 1px solid rgba(252, 165, 165, 0.35); }
    </style>
<div class="login-photo" aria-hidden="true"></div>
<div class="login-wrap">
    <form class="login-card" id="loginCard" method="post" autocomplete="on">
        <span class="login-glow" id="loginGlow" aria-hidden="true"></span>
        <span class="login-bead" aria-hidden="true"></span>
        <div class="login-brand">
            <img src="<?php echo e($brandSrc); ?>" alt="">
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

        <label class="login-float">
            <input type="text" name="username" required autofocus autocomplete="username" inputmode="text" placeholder=" ">
            <span><?php echo e($isEn ? 'Username' : 'اسم المستخدم'); ?></span>
        </label>

        <label class="login-float login-pass-wrap">
            <input id="loginPassword" type="password" name="password" required autocomplete="current-password" placeholder=" ">
            <span><?php echo e(t('password')); ?></span>
            <button class="login-pass-toggle" type="button" id="loginPassToggle" aria-label="<?php echo e($isEn ? 'Show password' : 'إظهار كلمة المرور'); ?>"><?php echo e($isEn ? 'Show' : 'إظهار'); ?></button>
        </label>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('login')); ?></button>
        </div>
        <?php
        $regOpen = true;
        if (function_exists('saas_settings')) {
            $saasLogin = saas_settings(isset($settings) ? $settings : null);
            $regOpen = !empty($saasLogin['registration_enabled']);
        }
        if ($regOpen):
        ?>
        <p class="login-reg">
            <a href="register.php"><?php echo e($isEn ? 'Register as agent' : 'تسجيل وكيل جديد'); ?></a>
        </p>
        <?php endif; ?>
    </form>
</div>
<script>
(function () {
  var btn = document.getElementById('loginPassToggle');
  var inp = document.getElementById('loginPassword');
  if (btn && inp) {
    var show = <?php echo json_encode($isEn ? 'Show' : 'إظهار'); ?>;
    var hide = <?php echo json_encode($isEn ? 'Hide' : 'إخفاء'); ?>;
    btn.addEventListener('click', function () {
      var on = inp.type === 'password';
      inp.type = on ? 'text' : 'password';
      btn.textContent = on ? hide : show;
    });
  }

</script>
<script src="assets/gate-field.js?v=gate2"></script>
</body>
</html>
