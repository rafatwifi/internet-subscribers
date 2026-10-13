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
      .wait-stage { display:flex; flex-direction:column; align-items:center; text-align:center; padding:8px 0 4px; }
      .wait-orb { position:relative; width:112px; height:112px; margin:6px 0 18px; }
      .wait-orb i { position:absolute; inset:0; border-radius:50%; border:2px solid rgba(14,165,233,.28); }
      .wait-orb i:nth-child(1) { animation:waitSpin 2.8s linear infinite; border-top-color:#0284c7; }
      .wait-orb i:nth-child(2) { inset:14px; animation:waitSpin 2.1s linear infinite reverse; border-top-color:#0f766e; }
      .wait-orb b { position:absolute; inset:32px; border-radius:50%; background:linear-gradient(160deg,#e0f2fe,#ccfbf1); box-shadow:0 8px 20px rgba(14,165,233,.25); }
      .wait-name { margin:0 0 8px; font-size:18px; font-weight:800; color:#0f172a; }
      .wait-note { margin:0; color:#64748b; font-size:14px; line-height:1.7; }
      .wait-dots { display:flex; gap:8px; margin:16px 0 4px; }
      .wait-dots span { width:8px; height:8px; border-radius:50%; background:#0f766e; animation:waitDot 1.2s ease-in-out infinite; }
      .wait-dots span:nth-child(2) { animation-delay:.15s; }
      .wait-dots span:nth-child(3) { animation-delay:.3s; }
      .wait-stage.is-in .wait-orb i { animation:none; border-color:#34d399; }
      .wait-stage.is-in .wait-orb b { background:linear-gradient(160deg,#d1fae5,#a7f3d0); }
      .wait-stage.is-no .wait-note { color:#b91c1c; }
      @keyframes waitSpin { to { transform:rotate(360deg); } }
      @keyframes waitDot { 0%,80%,100% { opacity:.25; transform:translateY(0); } 40% { opacity:1; transform:translateY(-3px); } }
      @media (max-width:640px) { .reg-grid { grid-template-columns:1fr; } .reg-card { padding:22px 16px; } .reg-card h1 { font-size:22px; } }
    </style>
</head>
<body class="reg-page <?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<div class="reg-card">
    <div class="reg-kicker"><?php echo e($siteName); ?></div>
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
    <?php endif; ?>
    <a class="reg-back" href="login.php"><?php echo e($isEn ? 'Back to login' : 'رجوع لتسجيل الدخول'); ?></a>
</div>
</body>
</html>
