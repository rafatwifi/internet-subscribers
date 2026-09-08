<?php

function render_header($title, $active = '', $subtitle = '', $titleAfter = '', $topToolsHtml = '')
{
    global $siteName, $lang, $settings;
    $flash = get_flash();
    $name = isset($siteName) ? $siteName : 'WiFi-Net-SALES';
    $page = isset($_SERVER['PHP_SELF']) ? basename($_SERVER['PHP_SELF']) : 'index.php';
    $isEn = ($lang === 'en');
    $settingsActive = ($active === 'settings' || $active === 'whatsapp' || $active === 'backup' || $active === 'users' || $active === 'schedule');
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
    $bgMode = (function_exists('app_bg_mode') && is_array($settings)) ? app_bg_mode($settings) : 'color';
    $bgColor = (function_exists('login_bg_color') && is_array($settings)) ? login_bg_color($settings) : '#1b2a38';
    $bgUrl = (function_exists('login_bg_url') && is_array($settings)) ? login_bg_url($settings) : '';
    if ($bgMode === 'image' && $bgUrl === '') {
        $bgMode = 'color';
    }
    $brandIcon = (function_exists('brand_icon_url') && is_array($settings))
        ? brand_icon_url($settings)
        : '';
    ?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>" dir="<?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> | <?php echo e($name); ?></title>
    <?php if ($brandIcon !== ''): ?>
    <link rel="icon" href="<?php echo e($brandIcon); ?>">
    <?php else: ?>
    <link rel="icon" href="assets/favicon.svg?v=2" type="image/svg+xml">
    <link rel="icon" href="assets/favicon.png?v=2" type="image/png" sizes="32x32">
    <?php endif; ?>
    <link rel="apple-touch-icon" href="<?php echo e($brandIcon !== '' ? $brandIcon : 'assets/apple-touch-icon.png?v=2'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=ui5">
    <style>
        <?php if ($bgMode === 'image' && $bgUrl !== ''): ?>
        body.app-bg-image {
            background-color: <?php echo e($bgColor); ?> !important;
            background-image: url("<?php echo e($bgUrl); ?>") !important;
            background-size: cover !important;
            background-position: center center !important;
            background-repeat: no-repeat !important;
            background-attachment: fixed !important;
        }
        body.app-bg-image .bg-bubbles { display: none; }
        body.app-bg-image .app { background: transparent; }
        body.app-bg-image .main { background: transparent; }
        body.app-bg-image .panel,
        body.app-bg-image .sas-table-card,
        body.app-bg-image .glass-panel,
        body.app-bg-image .sched-table-wrap,
        body.app-bg-image .debts-table-wrap,
        body.app-bg-image .debts-toolbar,
        body.app-bg-image .debts-add,
        body.app-bg-image .chart-panel,
        body.app-bg-image .cat-block,
        body.app-bg-image .modal-card,
        body.app-bg-image .ops-modal-card {
            background: rgba(255,255,255,0.78) !important;
            backdrop-filter: blur(12px) saturate(1.15);
            -webkit-backdrop-filter: blur(12px) saturate(1.15);
            border-color: rgba(255,255,255,0.45) !important;
        }
        body.app-bg-image .sas-table-headbar,
        body.app-bg-image .main-top {
            background: rgba(255,255,255,0.72) !important;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        <?php elseif ($bgMode === 'color'): ?>
        body.app-bg-color {
            background: <?php echo e($bgColor); ?> !important;
        }
        body.app-bg-color .bg-bubbles { opacity: .25; }
        <?php endif; ?>
        /* Sidebar modern — text only, centered */
        .sidebar {
          background: #12181f !important;
          border-inline-end: 1px solid rgba(255,255,255,0.06);
        }
        .sidebar-head {
          padding: 18px 14px 12px;
          border-bottom: 1px solid rgba(255,255,255,0.06);
          justify-content: center;
        }
        .sidebar-head .brand {
          width: 100%;
          text-align: center;
          font-size: 15px;
          font-weight: 800;
          letter-spacing: 0.02em;
          color: #f8fafc;
        }
        .side-links {
          padding: 10px 10px 16px;
          gap: 4px;
        }
        .side-links a {
          display: flex;
          align-items: center;
          justify-content: center;
          text-align: center;
          min-height: 42px;
          padding: 10px 12px;
          border-radius: 10px;
          font-size: 13.5px;
          font-weight: 700;
          color: rgba(248,250,252,0.72);
          border: 1px solid transparent;
        }
        .side-links a .nav-ico { display: none !important; }
        .side-links a:hover {
          background: rgba(255,255,255,0.06);
          color: #fff;
        }
        .side-links a.active {
          background: rgba(56, 189, 248, 0.14);
          color: #e0f2fe;
          border-color: rgba(56, 189, 248, 0.22);
          box-shadow: none;
        }
        .side-links .nav-user { display: none !important; }
        .sidebar .lang-mini { display: none !important; }
        .main-top-end .top-user-cluster {
          display: inline-flex;
          align-items: center;
          gap: 8px;
          padding: 4px;
          border-radius: 999px;
          background: rgba(255,255,255,0.72);
          border: 1px solid rgba(15,23,42,0.08);
          box-shadow: 0 4px 14px rgba(15,23,42,0.06);
        }
        .top-lang-btn {
          width: 34px;
          height: 34px;
          border-radius: 999px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          font-weight: 800;
          font-size: 12px;
          color: #0f172a;
          text-decoration: none;
          background: #f1f5f9;
        }
        .top-profile-btn {
          display: inline-flex;
          align-items: center;
          gap: 8px;
          padding: 4px 10px 4px 4px;
          border-radius: 999px;
          text-decoration: none;
          color: #0f172a;
          font-weight: 700;
          font-size: 13px;
        }
        .top-profile-avatar {
          width: 30px;
          height: 30px;
          border-radius: 999px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          background: #e2e8f0;
          color: #334155;
        }
    </style>
</head>
<body class="<?php echo $isEn ? 'ltr' : 'rtl'; ?> ios-glass<?php
    if ($bgMode === 'image' && $bgUrl !== '') {
        echo ' app-bg-image';
    } elseif ($bgMode === 'color') {
        echo ' app-bg-color';
    }
?>">
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
            <a class="<?php echo $active === 'sas' ? 'active' : ''; ?>" href="sas.php"><?php echo e(t('sas')); ?></a>
            <?php endif; ?>
            <?php if ($can('subscribers')): ?>
            <a class="<?php echo $active === 'cards' ? 'active' : ''; ?>" href="cards.php"><?php echo e($isEn ? 'Cards' : 'الكارتات'); ?></a>
            <?php endif; ?>
            <?php if ($can('plans')): ?>
            <a class="<?php echo $active === 'plans' ? 'active' : ''; ?>" href="plans.php"><?php echo e(t('plans')); ?></a>
            <?php endif; ?>
            <?php if ($can('agents')): ?>
            <a class="<?php echo $active === 'agents' ? 'active' : ''; ?>" href="agents.php"><?php echo e($isEn ? 'Agents' : 'الوكلاء'); ?></a>
            <?php endif; ?>
            <?php if ($can('rentals')): ?>
            <a class="<?php echo $active === 'rentals' ? 'active' : ''; ?>" href="rentals.php"><?php echo e($isEn ? 'Rentals' : 'الإيجار'); ?></a>
            <?php endif; ?>
            <?php if ($can('debts')): ?>
            <a class="<?php echo $active === 'debts' ? 'active' : ''; ?>" href="debts.php"><?php echo e(t('debts')); ?></a>
            <?php endif; ?>
            <?php if ($can('settings')): ?>
            <a class="<?php echo $active === 'schedule' ? 'active' : ''; ?>" href="schedule.php"><?php echo e($isEn ? 'Periodic jobs' : 'الجدول الدوري'); ?></a>
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
            <a class="<?php echo $settingsActive && $active !== 'schedule' ? 'active' : ''; ?>" href="settings.php"><?php echo e(t('settings')); ?></a>
            <?php endif; ?>
            <a href="logout.php"><?php echo e(t('logout')); ?></a>
        </nav>
    </aside>

    <div class="main">
        <div class="main-top">
            <div class="main-top-start">
                <button class="sidebar-toggle sidebar-toggle-bar" type="button" id="sidebarToggleBar" title="<?php echo e(t('menu')); ?>" aria-label="<?php echo e(t('menu')); ?>">|||</button>
                <strong class="main-title name-cell">
                    <?php if ($brandIcon !== ''): ?>
                        <img class="dash-brand-ico" src="<?php echo e($brandIcon); ?>" alt="" width="28" height="28">
                    <?php endif; ?>
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
                <div class="top-user-cluster">
                    <a class="top-profile-btn" href="profile.php" title="<?php echo e($isEn ? 'My profile' : 'بروفايلي'); ?>">
                        <span class="top-profile-avatar" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12zm0 2.25c-3.6 0-6.75 1.8-6.75 4V20h13.5v-1.75c0-2.2-3.15-4-6.75-4z"/></svg>
                        </span>
                        <?php if ($userLabel !== ''): ?>
                            <span class="top-profile-name"><?php echo e($userLabel); ?></span>
                        <?php endif; ?>
                    </a>
                    <a class="top-lang-btn" href="<?php echo e($langHref); ?>" title="<?php echo e(t('language')); ?>">
                        <span class="top-lang-glyph" aria-hidden="true"><?php echo $isEn ? 'ع' : 'A'; ?></span>
                    </a>
                </div>
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
    global $siteName;
    $name = isset($siteName) ? $siteName : 'WiFi-Net-SALES';
    ?>
        </main>
        <footer class="footer"><?php echo e($name); ?> © <?php echo date('Y'); ?></footer>
    </div>
    <div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>
</div>
<button class="fab-menu" type="button" id="sidebarToggle" title="<?php echo e(t('menu')); ?>" aria-label="<?php echo e(t('menu')); ?>">
    <span class="fab-menu-bars" aria-hidden="true"><i></i><i></i><i></i></span>
</button>
<style>
body.nav-pending .main { opacity: .72; transition: opacity .12s ease; pointer-events: none; }
body.nav-pending .side-links a { opacity: .85; }
</style>
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

  // إحساس تنقّل فوري — بدون انتظار انتهاء طلبات ثقيلة في الصفحة الحالية
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
    if (a.target && a.target !== '_self') return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (href.indexOf('logout.php') >= 0) return;
    try {
      var u = new URL(href, window.location.href);
      if (u.origin !== window.location.origin) return;
      if (u.pathname === window.location.pathname && u.search === window.location.search) return;
    } catch (err) { return; }
    document.body.classList.add('nav-pending');
  }, true);
  window.addEventListener('pageshow', function () {
    document.body.classList.remove('nav-pending');
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
  var failStreak = 0;
  var lastKey = '';

  function showBar() {
    bar.hidden = false;
    bar.classList.remove('wa-conn-hidden');
  }
  function hideBar() {
    bar.hidden = true;
    bar.classList.add('wa-conn-hidden');
    lastKey = 'ok';
    failStreak = 0;
  }
  function setProblem(cls, msg) {
    var key = cls + '|' + msg;
    if (key === lastKey && !bar.hidden) return;
    lastKey = key;
    showBar();
    bar.className = 'wa-conn-bar ' + cls;
    if (text) text.textContent = msg;
  }

  function check() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'wa_proxy.php?action=status&_=' + Date.now(), true);
    xhr.timeout = 4000;
    xhr.onload = function () {
      var data = null;
      try { data = JSON.parse(xhr.responseText); } catch (e) {}
      if (!data || data.success === false) {
        failStreak += 1;
        if (failStreak >= 3) setProblem('wa-conn-off', msgs.unreachable);
        return;
      }
      failStreak = 0;
      if (data.ready === true) {
        hideBar();
        return;
      }
      if (data.has_qr || data.status === 'qr_ready' || data.status === 'connecting') {
        setProblem('wa-conn-warn', msgs.needQr);
        return;
      }
      setProblem('wa-conn-off', msgs.offline);
    };
    xhr.onerror = xhr.ontimeout = function () {
      failStreak += 1;
      if (failStreak >= 3) setProblem('wa-conn-off', msgs.unreachable);
    };
    xhr.send();
  }

  // لا تفحص واتساب فور فتح الصفحة — يقلل صفنة التنقل
  setTimeout(check, 2500);
  setInterval(check, 60000);
})();

// تذكير انتهاء الاشتراك بالخلفية — لا يوقف رسم الصفحة
setTimeout(function () {
  try {
    if (navigator.sendBeacon) navigator.sendBeacon('tick.php');
    else {
      var x = new XMLHttpRequest();
      x.open('GET', 'tick.php', true);
      x.timeout = 8000;
      x.send();
    }
  } catch (e) {}
}, 4000);
</script>
</body>
</html>
<?php
}
