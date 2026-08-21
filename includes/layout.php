<?php

function render_header($title, $active = '', $subtitle = '', $titleAfter = '', $topToolsHtml = '')
{
    global $siteName, $lang;
    $flash = get_flash();
    $name = isset($siteName) ? $siteName : 'WiFi-Net-SALES';
    $page = isset($_SERVER['PHP_SELF']) ? basename($_SERVER['PHP_SELF']) : 'index.php';
    $isEn = ($lang === 'en');
    $settingsActive = ($active === 'settings' || $active === 'whatsapp' || $active === 'templates' || $active === 'backup' || $active === 'users' || $active === 'plans');
    $adminNow = function_exists('current_admin') ? current_admin() : null;
    $can = function ($p) {
        return function_exists('user_can') ? user_can($p) : true;
    };
    $userLabel = '';
    if ($adminNow) {
        $userLabel = !empty($adminNow['display_name']) ? $adminNow['display_name'] : (isset($adminNow['username']) ? $adminNow['username'] : '');
    }
    $qs = $_GET;
    unset($qs['lang']);
    $baseQs = http_build_query($qs);
    $langToggle = ($isEn ? 'ar' : 'en');
    $langHref = $page . '?' . ($baseQs !== '' ? $baseQs . '&' : '') . 'lang=' . $langToggle;
    ?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> | <?php echo e($name); ?></title>
    <link rel="icon" href="assets/favicon.svg?v=2" type="image/svg+xml">
    <link rel="icon" href="assets/favicon.png?v=2" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/apple-touch-icon.png?v=2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@500;600;700;800&family=Tajawal:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=sas78">
</head>
<body class="<?php echo $isEn ? 'ltr' : 'rtl'; ?> ios-glass">
<div class="bg-bubbles" aria-hidden="true">
    <span></span><span></span><span></span><span></span><span></span>
</div>
<div class="app" id="app">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <div class="brand"><?php echo e($name); ?></div>
            <button class="sidebar-close" type="button" id="sidebarClose" aria-label="<?php echo e(t('menu')); ?>">×</button>
        </div>
        <nav class="side-links">
            <?php if ($can('dashboard')): ?>
            <a class="<?php echo $active === 'dashboard' ? 'active' : ''; ?>" href="index.php"><?php echo e(t('dashboard')); ?></a>
            <?php endif; ?>
            <?php if ($can('subscribers')): ?>
            <a class="<?php echo $active === 'subscribers' ? 'active' : ''; ?>" href="subscribers.php"><?php echo e(t('subscribers')); ?></a>
            <?php endif; ?>
            <?php if ($can('agents')): ?>
            <a class="<?php echo $active === 'agents' ? 'active' : ''; ?>" href="agents.php"><?php echo e($isEn ? 'Agents' : 'الوكلاء'); ?></a>
            <?php endif; ?>
            <?php if ($can('activate')): ?>
            <a class="<?php echo $active === 'activate' ? 'active' : ''; ?>" href="activate.php"><?php echo e(t('activate')); ?></a>
            <?php endif; ?>
            <?php if ($can('rentals')): ?>
            <a class="<?php echo $active === 'rentals' ? 'active' : ''; ?>" href="rentals.php"><?php echo e($isEn ? 'Rentals' : 'الإيجار'); ?></a>
            <?php endif; ?>
            <?php if ($can('debts')): ?>
            <a class="<?php echo $active === 'debts' ? 'active' : ''; ?>" href="debts.php"><?php echo e(t('debts')); ?></a>
            <?php endif; ?>
            <?php if ($can('subscribers')): ?>
            <a class="<?php echo $active === 'import_export' ? 'active' : ''; ?>" href="import_export.php"><?php echo e($isEn ? 'Import & Export' : 'استيراد وتصدير'); ?></a>
            <?php endif; ?>
            <?php if ($can('subscriptions')): ?>
            <a class="<?php echo $active === 'subscriptions' ? 'active' : ''; ?>" href="subscriptions.php"><?php echo e(t('movements')); ?></a>
            <?php endif; ?>
            <?php if ($can('messages')): ?>
            <a class="<?php echo $active === 'messages' ? 'active' : ''; ?>" href="messages.php"><?php echo e(t('messages')); ?></a>
            <?php endif; ?>
            <?php if ($can('reports')): ?>
            <a class="<?php echo $active === 'reports' ? 'active' : ''; ?>" href="reports.php"><?php echo e(t('reports')); ?></a>
            <?php endif; ?>
            <?php if ($can('logs')): ?>
            <a class="<?php echo $active === 'logs' ? 'active' : ''; ?>" href="logs.php"><?php echo e($isEn ? 'Log' : 'اللوك'); ?></a>
            <?php endif; ?>

            <?php if ($can('settings') || $can('users') || $can('plans') || $can('backup')): ?>
            <a class="<?php echo $settingsActive ? 'active' : ''; ?>" href="settings.php"><?php echo e(t('settings')); ?></a>
            <?php endif; ?>

            <a class="<?php echo $active === 'profile' ? 'active' : ''; ?>" href="profile.php"><?php echo e($isEn ? 'My profile' : 'بروفايلي'); ?></a>
            <?php if ($adminNow): ?>
                <div class="nav-user">
                    <?php echo e($adminNow['display_name']); ?>
                    <span class="nav-role"><?php echo e(function_exists('admin_role_label') ? admin_role_label(isset($adminNow['role']) ? $adminNow['role'] : 'staff', $lang) : ''); ?></span>
                </div>
            <?php endif; ?>
            <a href="logout.php"><?php echo e(t('logout')); ?></a>
        </nav>
        <div class="lang-mini">
            <a class="<?php echo !$isEn ? 'on' : ''; ?>" href="<?php echo e($page); ?>?lang=ar">ع</a>
            <a class="<?php echo $isEn ? 'on' : ''; ?>" href="<?php echo e($page); ?>?lang=en">EN</a>
        </div>
    </aside>

    <div class="main">
        <div class="main-top">
            <div class="main-top-start">
                <button class="sidebar-toggle sidebar-toggle-bar" type="button" id="sidebarToggleBar" title="<?php echo e(t('menu')); ?>" aria-label="<?php echo e(t('menu')); ?>">|||</button>
                <strong class="main-title name-cell">
                    <?php echo e($title); ?>
                </strong>
                <?php if ($titleAfter !== '' && $titleAfter !== null): ?>
                    <div class="main-top-after"><?php echo $titleAfter; ?></div>
                <?php endif; ?>
                <?php if ($topToolsHtml !== '' && $topToolsHtml !== null): ?>
                    <div class="main-top-tools"><?php echo $topToolsHtml; ?></div>
                <?php endif; ?>
            </div>
            <div class="main-top-end">
                <a class="top-lang-btn" href="<?php echo e($langHref); ?>" title="<?php echo e(t('language')); ?>">
                    <span class="top-lang-glyph" aria-hidden="true"><?php echo $isEn ? 'ع' : 'A'; ?></span>
                </a>
                <a class="top-profile-btn" href="profile.php" title="<?php echo e($isEn ? 'My profile' : 'بروفايلي'); ?>">
                    <?php if ($userLabel !== ''): ?>
                        <span class="top-profile-name"><?php echo e($userLabel); ?></span>
                    <?php endif; ?>
                    <span class="top-profile-avatar" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12zm0 2.25c-3.6 0-6.75 1.8-6.75 4V20h13.5v-1.75c0-2.2-3.15-4-6.75-4z"/></svg>
                    </span>
                </a>
            </div>
        </div>
        <div id="waConnBar" class="wa-conn-bar wa-conn-checking wa-conn-hidden" role="status" aria-live="polite" hidden>
            <span class="wa-conn-dot" aria-hidden="true"></span>
            <span class="wa-conn-text"><?php echo e($isEn ? 'Checking WhatsApp…' : 'جاري فحص واتساب…'); ?></span>
            <a class="wa-conn-link" href="settings.php?tab=whatsapp"><?php echo e($isEn ? 'Settings' : 'الإعدادات'); ?></a>
        </div>
        <main class="container">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
            <?php endif; ?>
<?php
}

function render_footer()
{
    global $siteName, $pdo, $config;
    $name = isset($siteName) ? $siteName : 'WiFi-Net-SALES';
    if (isset($pdo, $config) && function_exists('maybe_run_expiry_auto_reminders') && function_exists('current_admin') && current_admin()) {
        @maybe_run_expiry_auto_reminders($pdo, $config);
    }
    ?>
        </main>
        <footer class="footer"><?php echo e($name); ?> © <?php echo date('Y'); ?></footer>
    </div>
    <div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>
</div>
<button class="fab-menu" type="button" id="sidebarToggle" title="<?php echo e(t('menu')); ?>" aria-label="<?php echo e(t('menu')); ?>">
    <span class="fab-menu-bars" aria-hidden="true"><i></i><i></i><i></i></span>
</button>
<script>
(function () {
  var app = document.getElementById('app');
  var btnFab = document.getElementById('sidebarToggle');
  var btnBar = document.getElementById('sidebarToggleBar');
  var closeBtn = document.getElementById('sidebarClose');
  var backdrop = document.getElementById('sidebarBackdrop');
  var sidebar = document.getElementById('sidebar');
  var key = 'sidebar_collapsed';
  var mobile = function () { return window.matchMedia('(max-width: 900px)').matches; };

  function setCollapsed(on) {
    if (!app) return;
    if (on) app.classList.add('sidebar-collapsed');
    else app.classList.remove('sidebar-collapsed');
    try { localStorage.setItem(key, on ? '1' : '0'); } catch (e) {}
  }

  function setMobileOpen(on) {
    if (!app) return;
    if (on) {
      app.classList.add('sidebar-open');
      document.body.classList.add('menu-open');
      if (backdrop) backdrop.hidden = false;
      if (btnFab) btnFab.classList.add('is-open');
    } else {
      app.classList.remove('sidebar-open');
      document.body.classList.remove('menu-open');
      if (backdrop) backdrop.hidden = true;
      if (btnFab) btnFab.classList.remove('is-open');
    }
  }

  function toggleMenu(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    if (mobile()) setMobileOpen(!app.classList.contains('sidebar-open'));
    else setCollapsed(!app.classList.contains('sidebar-collapsed'));
  }

  try {
    if (!mobile() && localStorage.getItem(key) === '1') setCollapsed(true);
  } catch (e) {}

  if (mobile()) {
    setCollapsed(false);
    setMobileOpen(false);
  }

  if (btnFab) btnFab.addEventListener('click', toggleMenu);
  if (btnBar) btnBar.addEventListener('click', toggleMenu);
  if (closeBtn) {
    closeBtn.addEventListener('click', function () {
      if (mobile()) setMobileOpen(false);
      else setCollapsed(true);
    });
  }
  if (backdrop) {
    backdrop.addEventListener('click', function () { setMobileOpen(false); });
  }
  if (sidebar) {
    sidebar.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a') : null;
      if (a && mobile()) setMobileOpen(false);
    });
  }
  window.addEventListener('resize', function () {
    if (!mobile()) {
      setMobileOpen(false);
      document.body.classList.remove('menu-open');
    } else {
      setCollapsed(false);
    }
  });
})();

(function () {
  var bar = document.getElementById('waConnBar');
  if (!bar) return;
  var text = bar.querySelector('.wa-conn-text');
  var isEn = document.documentElement.lang === 'en';
  var msgs = {
    offline: isEn ? 'WhatsApp offline' : 'واتساب غير متصل',
    needQr: isEn ? 'WhatsApp needs QR scan' : 'واتساب يحتاج مسح QR',
    unreachable: isEn ? 'WhatsApp gateway unreachable' : 'بوابة واتساب غير متاحة'
  };

  function showBar() {
    bar.hidden = false;
    bar.classList.remove('wa-conn-hidden');
  }
  function hideBar() {
    bar.hidden = true;
    bar.classList.add('wa-conn-hidden');
  }

  function setProblem(cls, msg) {
    showBar();
    bar.className = 'wa-conn-bar ' + cls;
    if (text) text.textContent = msg;
  }

  function check() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'wa_proxy.php?action=status&_=' + Date.now(), true);
    xhr.timeout = 9000;
    xhr.onload = function () {
      var data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) {}
      if (!data || data.success === false) {
        setProblem('wa-conn-off', msgs.unreachable);
        return;
      }
      if (data.ready === true) {
        // متصل بنجاح — لا نظهر الشريط أبداً (حتى عند الرفرش)
        hideBar();
        return;
      }
      if (data.has_qr) {
        setProblem('wa-conn-warn', msgs.needQr);
        return;
      }
      setProblem('wa-conn-off', msgs.offline);
    };
    xhr.onerror = xhr.ontimeout = function () {
      setProblem('wa-conn-off', msgs.unreachable);
    };
    xhr.send();
  }

  check();
  setInterval(check, 15000);
})();
</script>
</body>
</html>
<?php
}
