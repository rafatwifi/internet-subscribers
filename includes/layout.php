<?php

function render_header($title, $active = '', $subtitle = '', $titleAfter = '', $topToolsHtml = '')
{
    global $siteName, $lang, $settings, $pdo, $config;
    $flash = get_flash();
    $name = isset($siteName) ? $siteName : 'WiFi-Net-SALES';
    $page = isset($_SERVER['PHP_SELF']) ? basename($_SERVER['PHP_SELF']) : 'index.php';
    $isEn = ($lang === 'en');
    $settingsActive = ($active === 'settings' || $active === 'whatsapp' || $active === 'backup' || $active === 'users' || $active === 'schedule' || $active === 'my_sas');
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
    <link rel="stylesheet" href="assets/style.css?v=ui14">
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
        body.app-bg-image .sched-toolbar,
        body.app-bg-image .sched-stat,
        body.app-bg-image .msg-table-wrap,
        body.app-bg-image .msg-toolbar,
        body.app-bg-image .msg-compose,
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
        .main-top-end .top-user-cluster,
        .top-admin-menu {
          overflow: visible;
        }
        .top-admin-menu { position: relative; }
        .top-admin-dropdown {
          position: absolute !important;
          top: calc(100% + 8px) !important;
          inset-inline-end: 0 !important;
          min-width: 280px;
          width: max-content;
          max-width: min(340px, 92vw);
          display: flex !important;
          flex-direction: column !important;
          align-items: stretch;
          z-index: 400;
          background: #fff;
          border: 1px solid #e2e8f0;
          border-radius: 12px;
          box-shadow: 0 12px 28px rgba(15, 23, 42, .16);
          padding: 6px;
        }
        .top-admin-dropdown[hidden] { display: none !important; }
        .top-admin-dropdown a,
        .top-admin-dropdown button {
          display: block !important;
          width: 100% !important;
          max-width: none !important;
          height: auto !important;
          min-height: 0 !important;
          white-space: normal !important;
          overflow: visible !important;
          text-overflow: clip !important;
          border: 0 !important;
          background: transparent;
          box-shadow: none;
          text-align: start;
          line-height: 1.45;
          padding: 10px 12px;
          border-radius: 8px;
          color: #0f172a;
          font-weight: 700;
          text-decoration: none;
          font-size: 14px;
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
            <?php
            $isPlatformAdmin = function_exists('is_super_admin_user') && is_super_admin_user();
            $navTidEarly = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
            ?>
            <?php if ($isPlatformAdmin): ?>
            <?php /* —— قائمة أدمن المنصة فقط —— */ ?>
            <a class="<?php echo $active === 'dashboard' ? 'active' : ''; ?>" href="index.php"><?php echo e(t('dashboard')); ?></a>
            <a class="<?php echo $active === 'companies' ? 'active' : ''; ?>" href="companies.php"><?php echo e($isEn ? 'Companies' : 'الشركات'); ?></a>
            <a class="<?php echo $active === 'saas_agents' ? 'active' : ''; ?>" href="saas_agents.php"><?php echo e($isEn ? 'Agents' : 'وكلاء'); ?></a>
            <?php if ($can('subscribers')): ?>
            <a class="<?php echo $active === 'import_export' ? 'active' : ''; ?>" href="import_export.php"><?php echo e($isEn ? 'Import & Export' : 'استيراد وتصدير'); ?></a>
            <?php endif; ?>
            <?php if ($can('subscriptions')): ?>
            <a class="<?php echo $active === 'system_activity' ? 'active' : ''; ?>" href="logs.php?scope=system"><?php echo e($isEn ? 'System activity' : 'حركات النظام'); ?></a>
            <?php endif; ?>
            <?php if ($can('logs')): ?>
            <a class="<?php echo $active === 'logs' ? 'active' : ''; ?>" href="logs.php"><?php echo e($isEn ? 'Log' : 'اللوك'); ?></a>
            <?php endif; ?>
            <?php if ($can('settings') || $can('users') || $can('plans') || $can('backup')): ?>
            <a class="<?php echo $settingsActive && $active !== 'schedule' ? 'active' : ''; ?>" href="settings.php"><?php echo e(t('settings')); ?></a>
            <?php endif; ?>
            <a class="<?php echo $active === 'company' ? 'active' : ''; ?>" href="company.php"><?php echo e($isEn ? 'About company' : 'عن الشركة'); ?></a>
            <a href="logout.php"><?php echo e(t('logout')); ?></a>
            <?php else: ?>
            <?php /* —— قائمة الوكالة / الوكيل (كل خصائص المشتركين تبقى هنا) —— */ ?>
            <?php if ($can('dashboard')): ?>
            <a class="<?php echo $active === 'dashboard' ? 'active' : ''; ?>" href="index.php"><?php echo e(t('dashboard')); ?></a>
            <?php endif; ?>
            <?php if ($can('subscribers')): ?>
            <a class="<?php echo $active === 'sas' ? 'active' : ''; ?>" href="sas.php"><?php echo e(t('sas')); ?></a>
            <?php endif; ?>
            <?php
            $hideLeafCards = isset($pdo) && function_exists('account_viewer_is_leaf_child') && account_viewer_is_leaf_child($pdo);
            if ($can('cards') && !$hideLeafCards): ?>
            <a class="<?php echo $active === 'cards' ? 'active' : ''; ?>" href="cards.php"><?php echo e($isEn ? 'Cards' : 'الكارتات'); ?></a>
            <?php endif; ?>
            <?php if ($can('plans')): ?>
            <a class="<?php echo $active === 'plans' ? 'active' : ''; ?>" href="plans.php"><?php echo e(t('plans')); ?></a>
            <?php endif; ?>
            <?php
            $navChildAgents = 0;
            if (isset($pdo) && function_exists('admin_user_child_count') && function_exists('current_admin')) {
                $navMe = current_admin();
                if ($navMe) {
                    $navChildAgents = admin_user_child_count($pdo, (int) $navMe['id'], $navTidEarly);
                }
            }
            $accSeesAgents = function_exists('is_accountant_user') && is_accountant_user()
                && function_exists('user_boss_has_downline') && user_boss_has_downline(isset($pdo) ? $pdo : null);
            if (($can('agents') && $navChildAgents > 0) || $accSeesAgents): ?>
            <a class="<?php echo $active === 'agents' ? 'active' : ''; ?>" href="agents.php"><?php echo e($isEn ? 'Agents' : 'الوكلاء'); ?></a>
            <?php endif; ?>
            <?php if ($can('agents') || (function_exists('is_agent_user') && is_agent_user()) || (function_exists('is_group_manager_user') && is_group_manager_user())): ?>
            <a class="<?php echo $active === 'accountants' ? 'active' : ''; ?>" href="agents.php?view=accountant"><?php echo e($isEn ? 'Accountant' : 'المحاسب'); ?></a>
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
            <a class="<?php echo $active === 'profit' ? 'active' : ''; ?>" href="profit_report.php"><?php echo e($isEn ? 'Profits' : 'الأرباح'); ?></a>
            <?php endif; ?>
            <?php if ($can('logs')): ?>
            <a class="<?php echo $active === 'logs' ? 'active' : ''; ?>" href="logs.php"><?php echo e($isEn ? 'Log' : 'اللوك'); ?></a>
            <?php endif; ?>
            <?php if ($can('settings') || $can('users') || $can('plans') || $can('backup')): ?>
            <a class="<?php echo $settingsActive && $active !== 'schedule' ? 'active' : ''; ?>" href="settings.php?tab=sas"><?php echo e(t('settings')); ?></a>
            <?php endif; ?>
            <a class="<?php echo $active === 'company' ? 'active' : ''; ?>" href="company.php"><?php echo e($isEn ? 'Company' : 'عن الشركة'); ?></a>
            <?php
            $navTid = $navTidEarly;
            if ($navTid > 1):
            ?>
            <a class="<?php echo $active === 'billing' ? 'active' : ''; ?>" href="billing.php"><?php echo e($isEn ? 'Billing' : 'الاشتراك'); ?></a>
            <?php endif; ?>
            <?php
            $hideSideLogout = (function_exists('is_agent_user') && is_agent_user()) || $navTid > 1;
            if (!$hideSideLogout):
            ?>
            <a href="logout.php"><?php echo e(t('logout')); ?></a>
            <?php endif; ?>
            <?php endif; ?>
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
                <?php
                $pendingUpd = function_exists('app_update_pending') ? app_update_pending(isset($settings) ? $settings : null) : null;
                $isImpersonating = function_exists('is_impersonating') && is_impersonating();
                $canLoginAs = function_exists('is_super_admin_user') && is_super_admin_user() && !$isImpersonating;
                $loginAsMode = (!$isImpersonating && isset($pdo) && function_exists('impersonate_actor_mode')) ? impersonate_actor_mode($pdo) : '';
                $canLoginAsChild = ($loginAsMode === 'agency' || $loginAsMode === 'parent');
                ?>
                <?php if ($pendingUpd && !$isImpersonating && function_exists('is_super_admin_user') && is_super_admin_user() && function_exists('user_can') && user_can('settings')): ?>
                    <a class="top-update-pill" href="settings.php?tab=update" title="<?php echo e($isEn ? 'System update available' : 'تحديث نظام متاح'); ?>">
                        <?php echo e($isEn ? 'Update' : 'تحديث'); ?>
                    </a>
                <?php endif; ?>
                <div class="top-user-cluster">
                    <div class="top-admin-menu" id="topAdminMenu">
                        <button type="button" class="top-profile-btn" id="topAdminBtn" aria-haspopup="true" aria-expanded="false">
                            <span class="top-profile-avatar" aria-hidden="true">
                                <?php
                                $meAv = function_exists('current_admin') ? current_admin() : null;
                                $avPath = '';
                                if ($meAv && !empty($meAv['id']) && isset($pdo)) {
                                    try {
                                        $avSt = $pdo->prepare('SELECT avatar_path, phone FROM admin_users WHERE id = :id LIMIT 1');
                                        $avSt->execute(array(':id' => (int) $meAv['id']));
                                        $avRow = $avSt->fetch();
                                        if ($avRow && !empty($avRow['avatar_path'])) {
                                            $avPath = (string) $avRow['avatar_path'];
                                        }
                                    } catch (Exception $e) {
                                    }
                                }
                                if ($avPath !== ''):
                                ?>
                                <img src="<?php echo e($avPath); ?>" alt="" width="28" height="28" style="border-radius:50%;object-fit:cover">
                                <?php else: ?>
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 12a4.5 4.5 0 1 0-4.5-4.5A4.5 4.5 0 0 0 12 12zm0 2.25c-3.6 0-6.75 1.8-6.75 4V20h13.5v-1.75c0-2.2-3.15-4-6.75-4z"/></svg>
                                <?php endif; ?>
                            </span>
                            <?php if ($userLabel !== ''): ?>
                                <span class="top-profile-name"><?php echo e($userLabel); ?></span>
                            <?php endif; ?>
                            <?php if ($isImpersonating): ?>
                                <?php
                                $asLabel = (function_exists('is_agent_user') && (is_agent_user() || (function_exists('is_group_manager_user') && is_group_manager_user())))
                                    ? ($isEn ? 'as agent' : 'وكيل')
                                    : ($isEn ? 'as user' : 'مستخدم نظام');
                                ?>
                                <span class="top-as-agent"><?php echo e($asLabel); ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="top-admin-dropdown" id="topAdminDropdown" hidden>
                            <?php if ($canLoginAsChild): ?>
                                <div class="top-login-as-box">
                                    <div class="top-login-as-label"><?php echo e($isEn ? 'Login as' : 'تسجيل الدخول بـ'); ?></div>
                                    <input type="search" id="loginAsQ" data-kind="child" autocomplete="off" placeholder="<?php echo e($isEn ? 'Agent name…' : 'اسم الوكيل…'); ?>">
                                    <div id="loginAsResults" class="login-as-results"></div>
                                    <form method="post" action="impersonate.php" id="loginAsForm" hidden>
                                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                        <input type="hidden" name="action" value="start">
                                        <input type="hidden" name="user_id" id="loginAsUserId" value="">
                                    </form>
                                </div>
                            <?php endif; ?>
                            <a href="profile.php"><?php echo e($isEn ? 'My profile' : 'البروفايل الشخصي'); ?></a>
                            <?php if ($canLoginAs): ?>
                                <button type="button" id="topLoginAsBtn"><?php echo e($isEn ? 'Login as system user…' : 'الدخول بصفة مستخدم نظام…'); ?></button>
                            <?php endif; ?>
                            <?php if ($isImpersonating): ?>
                                <form method="post" action="impersonate.php" class="top-admin-exit-form">
                                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="stop">
                                    <button type="submit"><?php echo e($isEn ? 'Back to my account' : 'رجوع لحسابي'); ?></button>
                                </form>
                            <?php endif; ?>
                            <a href="logout.php"><?php echo e(t('logout')); ?></a>
                        </div>
                    </div>
                    <a class="top-lang-btn" href="<?php echo e($langHref); ?>" title="<?php echo e(t('language')); ?>">
                        <span class="top-lang-glyph" aria-hidden="true"><?php echo $isEn ? 'E' : 'ع'; ?></span>
                    </a>
                </div>
            </div>
        </div>
        <?php if ($canLoginAs): ?>
        <div class="login-as-modal" id="loginAsModal" hidden>
            <div class="login-as-card" role="dialog" aria-modal="true" aria-labelledby="loginAsTitle">
                <h3 id="loginAsTitle"><?php echo e($isEn ? 'Login as system user' : 'الدخول بصفة مستخدم نظام'); ?></h3>
                <p class="meta"><?php echo e($isEn ? 'Type the username, then choose from matches.' : 'اكتب اسم المستخدم، ثم اختَر من النتائج المطابقة.'); ?></p>
                <input type="search" id="loginAsQ" autocomplete="off" placeholder="<?php echo e($isEn ? 'Username…' : 'اسم المستخدم…'); ?>">
                <div id="loginAsResults" class="login-as-results"></div>
                <div class="actions">
                    <button type="button" class="btn ghost sm" id="loginAsClose"><?php echo e($isEn ? 'Close' : 'إغلاق'); ?></button>
                </div>
                <form method="post" action="impersonate.php" id="loginAsForm" hidden>
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="user_id" id="loginAsUserId" value="">
                </form>
            </div>
        </div>
        <?php endif; ?>
        <?php
        // شريط واتساب: للأدمن فقط (مو للوكيل / الدخول بصفة وكيل)
        $showWaBar = !$isImpersonating && function_exists('is_agent_user') && !is_agent_user();
        if ($showWaBar && function_exists('whatsapp_notifications_enabled') && isset($pdo) && !whatsapp_notifications_enabled($pdo)) {
            $showWaBar = false;
        }
        ?>
        <div id="waConnBar" class="wa-conn-bar wa-conn-checking wa-conn-hidden" role="status" aria-live="polite" <?php echo $showWaBar ? '' : 'hidden'; ?> data-disabled="<?php echo $showWaBar ? '0' : '1'; ?>">
            <span class="wa-conn-dot" aria-hidden="true"></span>
            <span class="wa-conn-text"><?php echo e($isEn ? 'Checking WhatsApp…' : 'جاري فحص واتساب…'); ?></span>
            <a class="wa-conn-link" href="settings.php?tab=whatsapp"><?php echo e($isEn ? 'Settings' : 'الإعدادات'); ?></a>
        </div>
        <?php
        // بانر حالة الساس — القراءة المحلية تبقى؛ الكتابة للساس تُمنع عند الانقطاع
        $sasBanner = null;
        if (!empty($pdo) && is_array(isset($config) ? $config : null) && function_exists('sas_connection_status') && function_exists('sas_is_ready') && sas_is_ready($config)) {
            $lockTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
            $lockFile = dirname(__DIR__) . '/config/sas_status_t' . $lockTid . '.json';
            if (is_file($lockFile)) {
                $prev = @json_decode((string) @file_get_contents($lockFile), true);
                if (is_array($prev) && array_key_exists('ok', $prev) && empty($prev['ok'])) {
                    $sasBanner = $prev;
                }
            }
        }
        if ($sasBanner):
            $banDetail = isset($sasBanner['detail']) ? (string) $sasBanner['detail'] : '';
            ?>
        <div class="alert alert-error" style="margin:10px 14px 0;border-radius:10px" role="status">
            <?php echo e($isEn
                ? 'SAS offline — local data (subscribers, debts, cards) stays available. Writes to SAS are blocked until reconnect.'
                : 'الساس غير متصل — البيانات المحلية (مشتركين، ديون، كروت) تبقى. الكتابة للساس موقوفة حتى يعود الاتصال.'); ?>
            <?php if ($banDetail !== ''): ?>
                <span class="meta"> — <?php echo e($banDetail); ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <main class="container">
            <?php if ($flash): ?>
                <?php $flashStay = !empty($flash['sticky']); ?>
                <div class="sas-toast sas-toast-<?php echo e($flash['type']); ?><?php echo $flashStay ? ' sas-toast-stay' : ''; ?>" role="status">
                    <span class="sas-toast-text"><?php echo e($flash['message']); ?></span>
                    <?php if ($flashStay): ?>
                        <button type="button" class="sas-toast-x" aria-label="<?php echo e($isEn ? 'Close' : 'إغلاق'); ?>">×</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <style>
            #appVeil[hidden], #appRing[hidden] { display: none !important; }
            #appVeil {
                position: fixed; inset: 0; z-index: 99999;
                background: rgba(255,255,255,.22);
                backdrop-filter: blur(2.5px);
                -webkit-backdrop-filter: blur(2.5px);
                pointer-events: none;
            }
            #appRing {
                position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
                z-index: 100000; width: 72px; height: 72px; border-radius: 50%;
                background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .16);
                display: flex; align-items: center; justify-content: center;
                pointer-events: none;
            }
            #appRing svg { width: 46px; height: 46px; transform: rotate(-90deg); }
            #appRing .ring-track { fill: none; stroke: #e8eef5; stroke-width: 3.5; }
            #appRing .ring-fill {
                fill: none; stroke: #2563eb; stroke-width: 3.5; stroke-linecap: round;
                stroke-dasharray: 100; stroke-dashoffset: 100;
            }
            #appRing.is-on .ring-fill {
                stroke-dashoffset: 16;
                transition: stroke-dashoffset 7s cubic-bezier(.12, .7, .2, 1);
            }
            #appRing.is-done .ring-fill {
                stroke-dashoffset: 0;
                transition: stroke-dashoffset .22s ease;
            }
            .sas-toast {
                position: fixed; top: 16px; inset-inline-end: 16px; z-index: 100001;
                width: min(340px, calc(100vw - 24px));
                padding: 12px 14px; border-radius: 14px; font-weight: 700; text-align: start;
                box-shadow: 0 14px 40px rgba(15, 23, 42, .22);
                display: flex; align-items: flex-start; gap: 10px;
                opacity: 1; transform: translateY(0);
                transition: opacity .45s ease, transform .45s ease;
            }
            .sas-toast.is-out { opacity: 0; transform: translateY(-10px); }
            .sas-toast-text { flex: 1; line-height: 1.45; }
            .sas-toast-x {
                border: 0; background: transparent; color: inherit; font-size: 20px;
                line-height: 1; cursor: pointer; padding: 0 2px;
            }
            @media (max-width: 640px) {
                .sas-toast {
                    top: auto; bottom: 16px; inset-inline-start: 12px; inset-inline-end: 12px;
                    width: auto;
                }
                .sas-toast.is-out { transform: translateY(10px); }
            }
            .sas-toast-success { background: #146c43; color: #fff; }
            .sas-toast-error { background: #9b2331; color: #fff; }
            .sas-toast-info { background: #0b5e78; color: #fff; }
            </style>
            <!-- دائرة الانتظار: وسط الشاشة، مع ضباب خفيف، فقط إذا الطلب تأخر -->
            <div id="appVeil" hidden aria-hidden="true"></div>
            <div id="appRing" hidden aria-hidden="true">
                <svg viewBox="0 0 36 36">
                    <circle class="ring-track" cx="18" cy="18" r="15" pathLength="100"></circle>
                    <circle class="ring-fill" cx="18" cy="18" r="15" pathLength="100"></circle>
                </svg>
            </div>
<?php
    // بعد رسم الهيدر: حرّر قفل الجلسة لطلبات GET حتى لا يتوقف التنقّل على أجاكس خلفي
    // احفظ رمز الحماية قبل الإغلاق، وإلا تعديل الاسم يطلع «طلب غير صالح»
    if (function_exists('csrf_token')) {
        csrf_token();
    }
    if (
        (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'GET')
        && empty($GLOBALS['app_keep_session'])
        && function_exists('app_session_close')
    ) {
        app_session_close();
    }
}

function render_footer()
{
    global $siteName, $lang;
    $name = isset($siteName) ? $siteName : 'WiFi-Net-SALES';
    $isEn = (isset($lang) && $lang === 'en');
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
.nav-progress {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  z-index: 100000;
  pointer-events: none;
  display: none;
  background: linear-gradient(90deg, #38bdf8 0%, #2563eb 45%, #38bdf8 100%);
  background-size: 220% 100%;
  animation: navProgressSlide 0.9s linear infinite;
}
body.nav-pending .nav-progress { display: block; }
@keyframes navProgressSlide {
  0% { background-position: 100% 0; }
  100% { background-position: -100% 0; }
}
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

  // شريط تقدّم خفيف بدل تبهيت الصفحة (كان يسبب مظهر ضبابي عند التنقّل)
  if (!document.getElementById('navProgress')) {
    var barProg = document.createElement('div');
    barProg.id = 'navProgress';
    barProg.className = 'nav-progress';
    barProg.setAttribute('aria-hidden', 'true');
    document.body.appendChild(barProg);
  }

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

  window.addEventListener('pageshow', function () {
    document.body.classList.remove('nav-pending');
  });
})();

(function () {
  var bar = document.getElementById('waConnBar');
  if (!bar || bar.getAttribute('data-disabled') === '1') return;
  var text = bar.querySelector('.wa-conn-text');
  var isEn = document.documentElement.lang === 'en';
  var msgs = {
    offline: isEn ? 'WhatsApp is not linked. Open settings and scan once.' : 'واتساب غير مربوط. افتح الإعدادات وامسح الرمز مرة واحدة.',
    needQr: isEn ? 'Scan the WhatsApp picture once from settings.' : 'امسح صورة واتساب مرة واحدة من الإعدادات.',
    restoring: isEn ? 'Opening the saved WhatsApp link…' : 'جاري فتح ربط واتساب المحفوظ…',
    unreachable: isEn ? 'WhatsApp gateway is off. The saved link stays — start it on the Windows PC.' : 'بوابة واتساب متوقفة. الربط محفوظ — شغّلها على حاسبة الويندوز.'
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
      if (!data || data.success === false || data.status === 'gateway_down') {
        setProblem('wa-conn-off', msgs.unreachable);
        return;
      }
      failStreak = 0;
      if (data.ready === true) {
        hideBar();
        return;
      }
      if (data.has_qr || data.status === 'qr_ready' || data.status === 'closed_401' || data.status === 'need_link') {
        setProblem('wa-conn-warn', data.status === 'qr_ready' || data.has_qr ? msgs.needQr : msgs.offline);
        return;
      }
      if (data.status === 'connecting' || data.status === 'starting' || (data.status && String(data.status).indexOf('closed_') === 0)) {
        setProblem('wa-conn-warn', msgs.restoring);
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
  setTimeout(check, 12000);
  setInterval(check, 60000);
})();

// تشغيل تلقائي (تذكير انتهاء + قطع) بالخلفية أثناء استخدام اللوحة
(function () {
  function fireTick() {
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon('tick.php');
        return;
      }
      var x = new XMLHttpRequest();
      x.open('GET', 'tick.php', true);
      x.timeout = 20000;
      x.send();
    } catch (e) {}
  }
  setTimeout(fireTick, 6000);
  setInterval(fireTick, 40000);
})();

(function () {
  var wrap = document.getElementById('topAdminMenu');
  var btn = document.getElementById('topAdminBtn');
  var drop = document.getElementById('topAdminDropdown');
  if (btn && drop) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var open = drop.hidden;
      drop.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function () {
      drop.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
    });
    drop.addEventListener('click', function (e) { e.stopPropagation(); });
  }

  var modal = document.getElementById('loginAsModal');
  var openBtn = document.getElementById('topLoginAsBtn');
  var closeBtn = document.getElementById('loginAsClose');
  var qInput = document.getElementById('loginAsQ');
  var results = document.getElementById('loginAsResults');
  var form = document.getElementById('loginAsForm');
  var uid = document.getElementById('loginAsUserId');
  var timer = null;

  function showModal(on) {
    if (!modal) return;
    modal.hidden = !on;
    if (on && qInput) {
      qInput.value = '';
      if (results) results.innerHTML = '';
      setTimeout(function () { qInput.focus(); }, 50);
    }
  }
  if (openBtn) openBtn.addEventListener('click', function () {
    if (drop) drop.hidden = true;
    showModal(true);
  });
  if (closeBtn) closeBtn.addEventListener('click', function () { showModal(false); });
  if (modal) modal.addEventListener('click', function (e) {
    if (e.target === modal) showModal(false);
  });

  function renderAgents(list) {
    if (!results) return;
    results.innerHTML = '';
    if (!list || !list.length) {
      results.innerHTML = '<div class="meta">—</div>';
      return;
    }
    list.forEach(function (a) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'login-as-item';
      b.textContent = a.label || a.display_name || a.username;
      b.addEventListener('click', function () {
        var picks = results.querySelectorAll('.login-as-item');
        for (var i = 0; i < picks.length; i++) picks[i].classList.remove('is-picked');
        b.classList.add('is-picked');
        if (window.appBusySet) window.appBusySet({ force: true });
        var csrfEl = form ? form.querySelector('input[name="csrf"]') : null;
        var body = new FormData();
        body.append('csrf', csrfEl ? csrfEl.value : '');
        body.append('action', 'start');
        body.append('user_id', String(a.id || 0));
        body.append('sas_id', String(a.sas_id || 0));
        body.append('ajax', '1');
        fetch('impersonate.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d || !d.ok) {
              alert((d && d.message) ? d.message : 'ما صار الدخول');
              return;
            }
            window.location.href = (d && d.redirect) ? d.redirect : 'sas.php';
          })
          .catch(function () { alert('ما صار الدخول'); });
      });
      results.appendChild(b);
    });
  }

  var searchXhr = null;
  if (qInput) {
    qInput.addEventListener('input', function () {
      clearTimeout(timer);
      var q = (qInput.value || '').trim();
      timer = setTimeout(function () {
        if (searchXhr) { try { searchXhr.abort(); } catch (e) {} searchXhr = null; }
        if (q.length < 1) {
          renderAgents([]);
          if (window.appBusyDone) window.appBusyDone();
          return;
        }
        if (window.appBusySet) window.appBusySet({});
        var xhr = new XMLHttpRequest();
        searchXhr = xhr;
        var kind = qInput.getAttribute('data-kind') || 'system';
        xhr.open('GET', 'impersonate.php?action=search&ajax=1&kind=' + encodeURIComponent(kind) + '&q=' + encodeURIComponent(q), true);
        xhr.onload = function () {
          if (searchXhr !== xhr) return;
          if (window.appBusyDone) window.appBusyDone();
          var data = null;
          try { data = JSON.parse(xhr.responseText); } catch (e) {}
          renderAgents(data && data.agents ? data.agents : []);
        };
        xhr.onerror = function () { if (window.appBusyDone) window.appBusyDone(); };
        xhr.send();
      }, 280);
    });
  }
})();
</script>
<script>
(function () {
  var ring = document.getElementById('appRing');
  var veil = document.getElementById('appVeil');
  var arm = null;

  function showWait(on) {
    if (veil) veil.hidden = !on;
  }
  function showRing() {
    if (!ring) return;
    ring.style.top = '';
    ring.style.left = '';
    showWait(true);
    ring.hidden = false;
    ring.classList.remove('is-done');
    void ring.offsetWidth;
    ring.classList.add('is-on');
  }
  function hideRing() {
    if (arm) { clearTimeout(arm); arm = null; }
    showWait(false);
    if (!ring) return;
    ring.classList.remove('is-on', 'is-done');
    ring.hidden = true;
  }
  function finishRing() {
    if (arm) { clearTimeout(arm); arm = null; }
    if (!ring || ring.hidden) {
      showWait(false);
      return;
    }
    ring.classList.remove('is-on');
    ring.classList.add('is-done');
    setTimeout(function () {
      showWait(false);
      if (!ring) return;
      ring.hidden = true;
      ring.classList.remove('is-done');
    }, 280);
  }
  function armRing() {
    if (arm || (ring && !ring.hidden)) return;
    arm = setTimeout(function () {
      arm = null;
      showRing();
    }, 450);
  }
  window.appBusySet = function (opt) {
    opt = opt || {};
    if (opt.force) { showRing(); return; }
    if (opt.soft || opt.mode === 'sync' || opt.mode === 'nav') return;
    armRing();
  };
  window.appBusyDone = function () { finishRing(); };

  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
    if (a.target && a.target !== '_self') return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1) return;
    if (href.indexOf('logout.php') >= 0) return;
    try {
      var u = new URL(href, window.location.href);
      if (u.origin !== window.location.origin) return;
      if (u.pathname === window.location.pathname && u.search === window.location.search) return;
    } catch (err) { return; }
    armRing();
  }, true);
  document.addEventListener('submit', function (ev) {
    if (ev.defaultPrevented) return;
    var form = ev.target;
    if (!form || !form.getAttribute) return;
    if (form.getAttribute('data-no-wait') === '1') return;
    armRing();
  });
  window.addEventListener('pagehide', finishRing);
  window.addEventListener('pageshow', hideRing);

  var toast = document.querySelector('.sas-toast');
  if (toast) {
    var closeToast = function () {
      toast.classList.add('is-out');
      setTimeout(function () {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 480);
    };
    var xbtn = toast.querySelector('.sas-toast-x');
    if (xbtn) xbtn.addEventListener('click', closeToast);
    if (!toast.classList.contains('sas-toast-stay')) {
      setTimeout(closeToast, 3000);
    }
  }
})();
</script>
</body>
</html>
<?php
}
