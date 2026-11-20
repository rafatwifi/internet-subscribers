<?php

require_once __DIR__ . '/../includes/bootstrap.php';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'wait') {
    header('Content-Type: application/json; charset=utf-8');
    $uid = isset($_SESSION['reg_wait_user']) ? (int) $_SESSION['reg_wait_user'] : 0;
    $tid = isset($_SESSION['reg_wait_tenant']) ? (int) $_SESSION['reg_wait_tenant'] : 1;
    $out = array('state' => 'wait');
    if ($uid <= 0 || $tid <= 1) {
        $out['state'] = 'lost';
        echo json_encode($out);
        exit;
    }
    $user = null;
    $tenant = null;
    try {
        $st = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id AND tenant_id = :t LIMIT 1');
        $st->execute(array(':id' => $uid, ':t' => $tid));
        $user = $st->fetch();
        $tst = $pdo->prepare('SELECT status, is_active FROM tenants WHERE id = :id LIMIT 1');
        $tst->execute(array(':id' => $tid));
        $tenant = $tst->fetch();
    } catch (Exception $e) {
        $user = null;
        $tenant = null;
    }
    $status = ($tenant && isset($tenant['status'])) ? (string) $tenant['status'] : '';
    if ($status === 'suspended') {
        $out['state'] = 'no';
    } elseif ($user && $status === 'active' && !empty($tenant['is_active']) && (int) $user['is_active'] === 1) {
        set_admin_session_from_row($user);
        unset($_SESSION['reg_wait_user'], $_SESSION['reg_wait_tenant'], $_SESSION['reg_wait_name']);
        if (function_exists('app_session_refresh_cookie')) {
            app_session_refresh_cookie();
        }
        if (function_exists('app_remember_set')) {
            app_remember_set((int) $user['id'], isset($user['username']) ? (string) $user['username'] : '');
        }
        $out['state'] = 'in';
        $out['go'] = 'index.php';
    }
    echo json_encode($out);
    exit;
}

if (!empty($_SESSION['admin_logged_in'])) {
    redirect('index.php');
}

$isEn = ($lang === 'en');
$saas = function_exists('saas_settings') ? saas_settings($settings) : array('registration_enabled' => false);
$error = '';
$waiting = false;
$waitName = isset($_SESSION['reg_wait_name']) ? trim((string) $_SESSION['reg_wait_name']) : '';
if (!empty($_SESSION['reg_wait_user']) && (int) $_SESSION['reg_wait_tenant'] > 1) {
    $waiting = true;
}

if (empty($saas['registration_enabled']) && !$waiting) {
    $error = $isEn ? 'Registration is closed' : 'التسجيل مغلق حالياً';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($saas['registration_enabled']) && !$waiting) {
    if (!verify_csrf(post('csrf'))) {
        $error = $isEn ? 'Invalid request' : 'طلب غير صالح';
    } else {
        $loginName = trim((string) post('username', ''));
        $shown = trim((string) post('display_name', ''));
        list($ok, $msg, $tenantId, $userId) = saas_register_agent(
            $pdo,
            $loginName,
            $loginName,
            $shown,
            (string) post('password', ''),
            post('phone', ''),
            post('email', '')
        );
        if ($ok && (int) $userId > 0 && (int) $tenantId > 1) {
            $_SESSION['reg_wait_user'] = (int) $userId;
            $_SESSION['reg_wait_tenant'] = (int) $tenantId;
            $_SESSION['reg_wait_name'] = $shown !== '' ? $shown : $loginName;
            redirect('register.php?wait=1');
        } else {
            $error = $msg;
        }
    }
}

$loginBgUrl = function_exists('login_bg_url') ? login_bg_url($settings) : '';
$loginBgColor = function_exists('login_bg_color') ? login_bg_color($settings) : '#1b2a38';
$bgMode = function_exists('app_bg_mode') ? app_bg_mode($settings) : 'color';
$useImage = ($bgMode === 'image' && $loginBgUrl !== '');
$loginBgCss = $useImage
    ? ('url("' . htmlspecialchars($loginBgUrl, ENT_QUOTES, 'UTF-8') . '")')
    : 'none';
$brandIconUrl = function_exists('brand_icon_url') ? brand_icon_url($settings) : '';
$brandSrc = ($brandIconUrl !== '') ? $brandIconUrl : 'assets/favicon.svg?v=2';
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo e($isEn ? 'Agent register' : 'تسجيل وكيل'); ?> | <?php echo e($siteName); ?></title>
    <link rel="icon" href="assets/favicon.svg?v=2" type="image/svg+xml">
    <link rel="stylesheet" href="assets/style.css?v=ui27">
    <link rel="stylesheet" href="assets/gate.css?v=gate5">
    <style>
        .login-photo { background-color: <?php echo e($loginBgColor); ?>; background-image: <?php echo $loginBgCss; ?>; opacity: <?php echo $useImage ? '0.28' : '0'; ?>; }
        .alert { padding: 12px 14px; border-radius: 14px; margin-bottom: 14px; font-weight: 700; }
        .alert-error { background: rgba(127, 29, 29, 0.45); color: #fecaca; border: 1px solid rgba(252, 165, 165, 0.35); }
    </style>
</head>
<body class="login-page <?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<canvas id="loginField" class="login-field" aria-hidden="true"></canvas>
<div class="login-photo" aria-hidden="true"></div>
<div class="login-wrap">
<div class="login-card" id="loginCard">
    <span class="login-glow" id="loginGlow" aria-hidden="true"></span>
    <span class="login-bead" aria-hidden="true"></span>
    <div class="login-brand">
        <img src="<?php echo e($brandSrc); ?>" alt="">
        <h1><?php echo e($siteName); ?></h1>
    </div>
    <?php if ($waiting): ?>
    <div class="wait-stage" id="waitStage">
        <div class="wait-orb" aria-hidden="true"><i></i><i></i><b></b></div>
        <h1 id="waitTitle"><?php echo e($isEn ? 'Waiting for approval' : 'بانتظار الموافقة'); ?></h1>
        <?php if ($waitName !== ''): ?><p class="wait-name"><?php echo e($waitName); ?></p><?php endif; ?>
        <p class="wait-note" id="waitNote"><?php echo e($isEn
            ? 'Your request is in. As soon as it is approved, this page opens your account by itself. Keep it open.'
            : 'طلبك وصل. أول ما توافق الإدارة، الصفحة تفتح الحساب لحاله. خلّها مفتوحة.'); ?></p>
        <div class="wait-dots" id="waitDots" aria-hidden="true"><span></span><span></span><span></span></div>
    </div>
    <script>
    (function () {
        var stage = document.getElementById('waitStage');
        var title = document.getElementById('waitTitle');
        var note = document.getElementById('waitNote');
        var dots = document.getElementById('waitDots');
        var stopped = false;
        function tick() {
            if (stopped) return;
            fetch('register.php?ajax=wait', { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || stopped) return;
                    if (d.state === 'in' && d.go) {
                        stopped = true;
                        stage.className = 'wait-stage is-in';
                        title.textContent = <?php echo json_encode($isEn ? 'Approved' : 'تمت الموافقة'); ?>;
                        note.textContent = <?php echo json_encode($isEn ? 'Opening your account…' : 'يفتح الحساب الآن…'); ?>;
                        if (dots) dots.style.display = 'none';
                        window.location.replace(d.go);
                        return;
                    }
                    if (d.state === 'no') {
                        stopped = true;
                        stage.className = 'wait-stage is-no';
                        title.textContent = <?php echo json_encode($isEn ? 'Request refused' : 'تم رفض الطلب'); ?>;
                        note.textContent = <?php echo json_encode($isEn ? 'The administration did not approve this account.' : 'الإدارة ما وافقت على هذا الحساب.'); ?>;
                        if (dots) dots.style.display = 'none';
                    }
                })
                .catch(function () {});
        }
        tick();
        setInterval(tick, 3000);
    })();
    </script>
    <?php else: ?>
    <h1><?php echo e($isEn ? 'Create agent account' : 'إنشاء حساب وكيل'); ?></h1>
    <p class="meta"><?php echo e($isEn
        ? 'After register, wait for admin approval. Then bind your SAS and manage your subscribers.'
        : 'بعد التسجيل انتظر موافقة الإدارة، ثم اربط ساسك وأدر مشتركيك.'); ?></p>
    <?php if ($error !== ''): ?><div class="alert alert-error"><?php echo e($error); ?></div><?php endif; ?>
    <?php if (!empty($saas['registration_enabled'])): ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <div class="reg-grid">
            <label class="login-float">
                <input type="text" name="display_name" value="<?php echo e(post('display_name', '')); ?>" placeholder=" ">
                <span><?php echo e($isEn ? 'Your name' : 'اسمك'); ?></span>
            </label>
            <label class="login-float">
                <input class="ltr" type="text" name="username" required pattern="[A-Za-z0-9._@\-]{2,40}" value="<?php echo e(post('username', '')); ?>" placeholder=" ">
                <span><?php echo e($isEn ? 'Username' : 'اسم الدخول'); ?></span>
            </label>
            <label class="login-float">
                <input class="ltr" type="tel" name="phone" inputmode="tel" value="<?php echo e(post('phone', '')); ?>" placeholder=" ">
                <span><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?></span>
            </label>
            <label class="login-float">
                <input class="ltr" type="password" name="password" required minlength="4" placeholder=" ">
                <span><?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?></span>
            </label>
            <label class="login-float full">
                <input class="ltr" type="email" name="email" value="<?php echo e(post('email', '')); ?>" placeholder=" ">
                <span><?php echo e($isEn ? 'Email' : 'الإيميل'); ?></span>
            </label>
        </div>
        <button class="btn" type="submit"><?php echo e($isEn ? 'Create account' : 'إنشاء الحساب'); ?></button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <a class="reg-back" href="login.php"><?php echo e($isEn ? 'Back to login' : 'رجوع لتسجيل الدخول'); ?></a>
</div>
</div>
<script src="assets/gate-field.js?v=gate2"></script>
</body>
</html>
