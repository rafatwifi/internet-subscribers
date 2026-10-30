<?php

/** أيقونة SVG حديثة لروابط القائمة الجانبية */
function nav_side_ico($key)
{
    $key = strtolower(trim((string) $key));
    $paths = array(
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'subscribers' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'cards' => '<rect x="2" y="5" width="20" height="14" rx="2.5"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="6" y1="15" x2="10" y2="15"/>',
        'plans' => '<path d="M12 2l9 4.5v6.5c0 4.5-3.6 8.4-9 9.5-5.4-1.1-9-5-9-9.5V6.5L12 2z"/><path d="M9 12l2 2 4-4"/>',
        'agents' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 11l2 2 4-4"/>',
        'accountants' => '<rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="6" x2="16" y2="6"/><line x1="8" y1="10" x2="16" y2="10"/><line x1="8" y1="14" x2="12" y2="14"/><circle cx="16" cy="17" r="1.2"/>',
        'cabinets' => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><circle cx="7" cy="6" r="1"/><circle cx="7" cy="12" r="1"/><circle cx="7" cy="18" r="1"/>',
        'rentals' => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1.2"/>',
        'debts' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>',
        'import_export' => '<path d="M12 3v12"/><polyline points="8 7 12 3 16 7"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
        'movements' => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'messages' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'reports' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'profit' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'logs' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'company' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        'billing' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><circle cx="16" cy="15" r="1.5"/>',
        'companies' => '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/><path d="M9 9h.01"/><path d="M15 9h.01"/><path d="M9 13h.01"/><path d="M15 13h.01"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    );
    $inner = isset($paths[$key]) ? $paths[$key] : $paths['dashboard'];
    return '<span class="nav-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round">'
        . $inner
        . '</svg></span>';
}

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
    $uiTheme = function_exists('ui_theme_current') ? ui_theme_current(isset($pdo) ? $pdo : null) : 'classic';
    $uiTheme = ($uiTheme === 'glass') ? 'glass' : 'classic';
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
    <link rel="stylesheet" href="assets/style.css?v=ui23">
    <?php
    // خلفية صورة حتى يشتغل blur — شخصي ثم نظام ثم الافتراضي
    $glassBgSrc = 'assets/glass-bg.jpg?v=1';
    $glassCustom = function_exists('ui_glass_bg_url')
        ? ui_glass_bg_url(isset($pdo) ? $pdo : null, is_array($settings) ? $settings : null)
        : '';
    if ($glassCustom !== '') {
        $glassBgSrc = $glassCustom;
    } elseif ($bgUrl !== '') {
        $glassBgSrc = $bgUrl;
    }
    ?>
    <style>
        /*
         * سبب التهنيك السابق: backdrop-filter + transform animation + background-attachment:fixed
         * على ويندوز يطلع مستطيلات بيض/سود (GPU compositing).
         * الحل: أنيميشن opacity فقط للثيم الزجاجي، وblur أقل طبقات، وخلفية scroll.
         */
        @keyframes uiEnterFade {
          from { opacity: 0; transform: translateY(14px); }
          to { opacity: 1; transform: none; }
        }
        @keyframes uiSideIn {
          from { opacity: 0; transform: translateX(var(--ui-side, -22px)); }
          to { opacity: 1; transform: none; }
        }
        @keyframes uiSoftPop {
          from { opacity: 0; transform: translateY(10px) scale(.98); }
          to { opacity: 1; transform: none; }
        }
        @keyframes uiGlassFade {
          from { opacity: 0; }
          to { opacity: 1; }
        }
        html[dir="rtl"] { --ui-side: 22px; }
        html[dir="ltr"] { --ui-side: -22px; }

        /* كلاسيك: حركة للمحتوى فقط — لا تلمس السايدبار (تكسر إخفاء الموبايل) */
        body.theme-classic .main { animation: uiEnterFade .58s .06s cubic-bezier(.22,.9,.28,1) both; }
        body.theme-classic .main-top { animation: uiSoftPop .5s .04s cubic-bezier(.22,.9,.28,1) both; }
        body.theme-classic .sas-boxes .sas-box,
        body.theme-classic .panel { animation: uiSoftPop .48s cubic-bezier(.22,.9,.28,1) both; }
        body.theme-classic .sas-boxes .sas-box:nth-child(1) { animation-delay: .05s; }
        body.theme-classic .sas-boxes .sas-box:nth-child(2) { animation-delay: .09s; }
        body.theme-classic .sas-boxes .sas-box:nth-child(3) { animation-delay: .13s; }
        body.theme-classic .sas-boxes .sas-box:nth-child(4) { animation-delay: .17s; }
        body.theme-classic .sas-boxes .sas-box:nth-child(5) { animation-delay: .21s; }
        body.theme-classic .sas-boxes .sas-box:nth-child(6) { animation-delay: .25s; }
        body.theme-classic .sas-boxes .sas-box:nth-child(7) { animation-delay: .29s; }
        body.theme-classic .sas-boxes .sas-box:nth-child(8) { animation-delay: .33s; }
        body.theme-classic .sas-boxes .sas-box:nth-child(n+9) { animation-delay: .37s; }

        /* زجاجي: opacity فقط — بدون أنيميشن على السايدبار */
        body.theme-glass .main,
        body.theme-glass .main-top,
        body.theme-glass .panel,
        body.theme-glass .sas-table-card,
        body.theme-glass .sas-boxes .sas-box {
          animation: uiGlassFade .45s ease both;
        }
        body.theme-glass .main { animation-delay: .05s; }
        body.theme-glass .sas-boxes .sas-box:nth-child(1) { animation-delay: .06s; }
        body.theme-glass .sas-boxes .sas-box:nth-child(2) { animation-delay: .1s; }
        body.theme-glass .sas-boxes .sas-box:nth-child(3) { animation-delay: .14s; }
        body.theme-glass .sas-boxes .sas-box:nth-child(4) { animation-delay: .18s; }
        body.theme-glass .sas-boxes .sas-box:nth-child(n+5) { animation-delay: .22s; }

        /* هاتف: القائمة مخفية حتى sidebar-open — لا تدع الأنيميشن يثبت transform */
        @media (max-width: 900px) {
          body.theme-classic .sidebar,
          body.theme-glass .sidebar {
            animation: none !important;
          }
          body.rtl .sidebar,
          body:not(.ltr) .sidebar {
            transform: translateX(110%) !important;
          }
          body.ltr .sidebar {
            transform: translateX(-110%) !important;
          }
          .app.sidebar-open .sidebar,
          body.ltr .app.sidebar-open .sidebar,
          body.rtl .app.sidebar-open .sidebar,
          body:not(.ltr) .app.sidebar-open .sidebar {
            transform: translateX(0) !important;
          }
        }

        @media (prefers-reduced-motion: reduce) {
          body.theme-classic .main,
          body.theme-classic .main-top,
          body.theme-classic .sas-boxes .sas-box,
          body.theme-classic .panel,
          body.theme-glass .main,
          body.theme-glass .main-top,
          body.theme-glass .panel,
          body.theme-glass .sas-table-card,
          body.theme-glass .sas-boxes .sas-box { animation: none !important; }
        }

        .glass-card {
            background: rgba(248, 250, 252, 0.48);
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(15, 23, 42, 0.12);
            color: #0f172a;
        }
        /* زجاج أشفّ — أقل بياض، نص غامق مقروء */
        body.theme-glass {
            --glass-bg: rgba(248, 250, 252, 0.52);
            --glass-bg-soft: rgba(248, 250, 252, 0.4);
            --glass-line: rgba(255, 255, 255, 0.28);
            --glass-ink: #0f172a;
            --glass-text: #0f172a;
            --glass-muted: #334155;
            --glass-side: rgba(12, 18, 28, 0.78);
            background-color: #1a1f2a !important;
            background-image: url("<?php echo e($glassBgSrc); ?>") !important;
            background-size: cover !important;
            background-position: center center !important;
            background-repeat: no-repeat !important;
            background-attachment: scroll !important;
            color: var(--glass-ink);
        }
        body.theme-glass .bg-bubbles { display: none !important; }
        body.theme-glass .app,
        body.theme-glass .main,
        body.theme-glass .container,
        body.theme-glass .table-wrap { background: transparent !important; color: inherit; }

        body.theme-glass .main-top,
        body.theme-glass .panel,
        body.theme-glass .card,
        body.theme-glass .sas-table-card,
        body.theme-glass .glass-panel,
        body.theme-glass .sched-table-wrap,
        body.theme-glass .msg-table-wrap,
        body.theme-glass .debts-table-wrap,
        body.theme-glass .chart-panel,
        body.theme-glass .cat-block,
        body.theme-glass .cards-page .cat-block,
        body.theme-glass .modal-card,
        body.theme-glass .ops-modal-card {
            background: var(--glass-bg) !important;
            backdrop-filter: blur(16px) saturate(1.2) !important;
            -webkit-backdrop-filter: blur(16px) saturate(1.2) !important;
            border: 1px solid var(--glass-line) !important;
            border-radius: 16px !important;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
            color: var(--glass-ink) !important;
        }
        /* نافذة الدخول بصفة: دائماً وسط الشاشة */
        .login-as-modal {
            position: fixed !important;
            inset: 0 !important;
            z-index: 200000 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 16px !important;
            margin: 0 !important;
            background: rgba(15, 23, 42, 0.5) !important;
            box-sizing: border-box !important;
        }
        .login-as-modal[hidden] {
            display: none !important;
        }
        .login-as-card {
            position: relative !important;
            margin: auto !important;
            width: min(420px, 100%) !important;
            max-height: min(88vh, 640px) !important;
            overflow: auto !important;
            flex: 0 0 auto !important;
            align-self: center !important;
        }
        body.theme-glass .login-as-card {
            background: rgba(255, 255, 255, 0.94) !important;
            backdrop-filter: blur(18px) saturate(1.2) !important;
            -webkit-backdrop-filter: blur(18px) saturate(1.2) !important;
            border: 1px solid rgba(15, 23, 42, 0.12) !important;
            border-radius: 16px !important;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.28) !important;
            color: #0f172a !important;
        }
        /* سايدبار غامق للقائمة فقط */
        body.theme-glass .sidebar {
            background: var(--glass-side) !important;
            backdrop-filter: blur(16px) saturate(1.15) !important;
            -webkit-backdrop-filter: blur(16px) saturate(1.15) !important;
            border: 0 !important;
            border-inline-end: 1px solid rgba(255,255,255,0.12) !important;
            border-radius: 0 !important;
            color: #f8fafc !important;
            box-shadow: none;
        }
        /* قوائم السياق فاتحة وواضحة */
        body.theme-glass .ops-dropdown,
        body.theme-glass .cols-dropdown,
        body.theme-glass .filter-dropdown,
        body.theme-glass .top-admin-dropdown,
        body.theme-glass .ops-panel {
            background: rgba(255, 255, 255, 0.88) !important;
            backdrop-filter: blur(18px) saturate(1.2) !important;
            -webkit-backdrop-filter: blur(18px) saturate(1.2) !important;
            border: 1px solid rgba(15, 23, 42, 0.12) !important;
            border-radius: 14px !important;
            box-shadow: 0 14px 36px rgba(15, 23, 42, 0.18) !important;
            color: #0f172a !important;
        }
        body.theme-glass .ops-dropdown .ops-item,
        body.theme-glass .ops-dropdown a.ops-item,
        body.theme-glass .ops-dropdown button.ops-item,
        body.theme-glass .ops-panel .ops-item,
        body.theme-glass .top-admin-dropdown a,
        body.theme-glass .top-admin-dropdown button,
        body.theme-glass .cols-dropdown,
        body.theme-glass .cols-dropdown label,
        body.theme-glass .filter-dropdown,
        body.theme-glass .filter-dropdown label {
            color: #0f172a !important;
            background: transparent !important;
        }
        body.theme-glass .ops-dropdown .ops-item:hover,
        body.theme-glass .ops-dropdown button.ops-item:hover,
        body.theme-glass .ops-dropdown a.ops-item:hover,
        body.theme-glass .top-admin-dropdown a:hover,
        body.theme-glass .top-admin-dropdown button:hover {
            background: #f1f5f9 !important;
            color: #0f172a !important;
        }
        body.theme-glass .ops-item.ops-warn { color: #b45309 !important; }
        body.theme-glass .ops-item.ops-danger { color: #dc2626 !important; }
        body.theme-glass #opsItemHint { color: #64748b !important; }

        body.theme-glass .panel,
        body.theme-glass .cat-block,
        body.theme-glass .cards-page .cat-block,
        body.theme-glass .sas-table-card,
        body.theme-glass .modal-card {
            overflow: hidden !important;
            border-radius: 16px !important;
        }
        body.theme-glass .cards-page .cat-head {
            background: rgba(15, 23, 42, 0.04) !important;
            color: var(--glass-ink) !important;
            border: 0 !important;
        }
        body.theme-glass .cards-page .cat-head h3,
        body.theme-glass .cards-page .cat-chevron { color: var(--glass-ink) !important; }
        body.theme-glass .cards-page .cat-body {
            border-top-color: rgba(15, 23, 42, 0.08) !important;
            background: transparent !important;
        }
        body.theme-glass .cards-page .chip,
        body.theme-glass .cards-page .chip.free {
            background: rgba(248, 250, 252, 0.42) !important;
            border-color: rgba(15, 23, 42, 0.1) !important;
            color: var(--glass-ink) !important;
            border-radius: 12px !important;
        }
        body.theme-glass .cards-page .pin,
        body.theme-glass .cards-page .by-line { color: #334155 !important; }
        body.theme-glass .cards-page .pill {
            background: #e2e8f0 !important;
            color: #334155 !important;
            border: 0;
        }
        body.theme-glass .cards-page .pill.ok {
            background: #dcfce7 !important;
            color: #166534 !important;
        }
        body.theme-glass .cards-page .pill.bad {
            background: #fee2e2 !important;
            color: #991b1b !important;
        }
        body.theme-glass .sched-toolbar,
        body.theme-glass .sched-stat,
        body.theme-glass .msg-toolbar,
        body.theme-glass .msg-compose,
        body.theme-glass .debts-toolbar,
        body.theme-glass .debts-add,
        body.theme-glass .ping-mode,
        body.theme-glass .theme-card,
        body.theme-glass .login-bg-preview {
            background: var(--glass-bg-soft) !important;
            border-color: rgba(15, 23, 42, 0.1) !important;
            color: var(--glass-ink) !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            border-radius: 16px !important;
        }
        body.theme-glass .msg-compose {
            overflow: hidden !important;
            padding: 14px !important;
        }
        body.theme-glass .msg-compose textarea,
        body.theme-glass .msg-compose input,
        body.theme-glass .msg-compose select {
            border-radius: 12px !important;
            background: rgba(248, 250, 252, 0.55) !important;
        }
        body.theme-glass .tabs a {
            background: var(--glass-bg-soft) !important;
            border-color: rgba(15, 23, 42, 0.1) !important;
            color: var(--glass-ink) !important;
            border-radius: 999px !important;
        }
        body.theme-glass .tabs {
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
            gap: 8px;
            padding: 0;
        }
        body.theme-glass .sas-table-card,
        body.theme-glass .msg-table-wrap,
        body.theme-glass .debts-table-wrap,
        body.theme-glass .sched-table-wrap {
            overflow: hidden !important;
            border-radius: 16px !important;
            padding: 0 !important;
        }
        body.theme-glass .sas-table-headbar {
            background: rgba(15, 23, 42, 0.05) !important;
            border: 0 !important;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08) !important;
            border-radius: 0 !important;
            color: var(--glass-ink) !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }
        body.theme-glass .sas-headbar-name,
        body.theme-glass .sas-headbar-title,
        body.theme-glass .sas-table-headbar .sas-found,
        body.theme-glass .sas-table-headbar,
        body.theme-glass .sas-table-headbar * {
            color: var(--glass-ink) !important;
        }
        body.theme-glass .sas-table-headbar .sas-found { opacity: 0.72 !important; }
        body.theme-glass .sas-table-card > .table-wrap,
        body.theme-glass .msg-table-wrap > .table-wrap,
        body.theme-glass .debts-table-wrap > .table-wrap {
            margin: 0 !important;
            border: 0 !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
        }
        body.theme-glass .sas-table-card table,
        body.theme-glass .sas-table-card #subsTable { border-radius: 0 !important; }
        body.theme-glass .sas-table-tools .tool-ico {
            color: #334155 !important;
            background: transparent !important;
        }
        body.theme-glass .sas-table-tools .tool-ico:hover,
        body.theme-glass .sas-table-tools .tool-ico.is-on {
            color: #0369a1 !important;
            background: rgba(14, 165, 233, 0.12) !important;
        }
        body.theme-glass .panel h2,
        body.theme-glass .card h2,
        body.theme-glass .meta,
        body.theme-glass label,
        body.theme-glass .sas-dash h2,
        body.theme-glass .sas-dash h2.sas-sec,
        body.theme-glass .theme-card-name,
        body.theme-glass .theme-card-sub,
        body.theme-glass small {
            color: var(--glass-muted) !important;
        }
        body.theme-glass .panel h2,
        body.theme-glass .theme-card-name { color: var(--glass-ink) !important; }

        body.theme-glass .main-top {
            margin: 12px 14px 0;
            border-radius: 18px !important;
            overflow: visible !important;
            color: var(--glass-ink);
        }
        body.theme-glass .main-top .page-title,
        body.theme-glass .main-top h1,
        body.theme-glass .main-top .title,
        body.theme-glass .menu-btn,
        body.theme-glass .side-menu-btn { color: var(--glass-ink) !important; }

        body.theme-glass .main-top-end,
        body.theme-glass .top-admin-menu {
            position: relative;
            z-index: 50;
            overflow: visible !important;
        }
        body.theme-glass .main-top-end .top-user-cluster,
        body.theme-glass .top-user-cluster {
            background: rgba(248, 250, 252, 0.55) !important;
            border: 1px solid rgba(255, 255, 255, 0.28) !important;
            border-radius: 999px !important;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
            overflow: visible !important;
            pointer-events: auto !important;
        }
        body.theme-glass .top-lang-btn,
        body.theme-glass .top-profile-btn {
            background: transparent !important;
            color: var(--glass-ink) !important;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            pointer-events: auto !important;
            cursor: pointer !important;
            position: relative;
            z-index: 2;
        }
        body.theme-glass .top-admin-dropdown {
            z-index: 100050 !important;
            overflow: visible !important;
            pointer-events: auto !important;
        }
        /* بدون مربع خلف حرف اللغة */
        body.theme-glass .top-lang-btn {
            border-inline-start: 1px solid rgba(15, 23, 42, 0.1) !important;
            background: transparent !important;
        }
        body.theme-glass .top-lang-btn:hover,
        body.theme-glass .top-profile-btn:hover {
            background: rgba(15, 23, 42, 0.05) !important;
        }
        body.theme-glass .top-profile-btn { font-weight: 800; }
        body.theme-glass .top-profile-name { color: var(--glass-ink) !important; }
        body.theme-glass .top-as-agent {
            background: rgba(14, 165, 233, 0.18) !important;
            color: #0369a1 !important;
            border: 0;
        }
        body.theme-glass .top-profile-avatar {
            background: rgba(15, 23, 42, 0.08) !important;
            color: #334155 !important;
            border: 1px solid rgba(15, 23, 42, 0.08);
        }

        /* وجدز: شفافة + ألوان زاهية من نقاط التلوين بالإعدادات */
        body.theme-glass .sas-box {
            --g1: rgba(45, 212, 191, 0.62);
            --g2: rgba(13, 148, 136, 0.38);
            --gline: rgba(20, 184, 166, 0.85);
            --ink: #0f172a;
            background:
                linear-gradient(145deg, var(--g1) 0%, var(--g2) 72%, rgba(255,255,255,0.12) 100%) !important;
            border: 1px solid var(--gline) !important;
            border-radius: 16px !important;
            box-shadow: 0 8px 26px rgba(15, 23, 42, 0.12) !important;
            color: var(--ink) !important;
            padding: 1.25rem 1.35rem !important;
            backdrop-filter: blur(14px) saturate(1.45) !important;
            -webkit-backdrop-filter: blur(14px) saturate(1.45) !important;
            transition: filter .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        body.theme-glass .sas-box.tone-blue {
            --g1: rgba(56, 189, 248, 0.72); --g2: rgba(2, 132, 199, 0.42); --gline: rgba(14, 165, 233, 0.9);
        }
        body.theme-glass .sas-box.tone-green {
            --g1: rgba(74, 222, 128, 0.7); --g2: rgba(22, 163, 74, 0.4); --gline: rgba(34, 197, 94, 0.9);
        }
        body.theme-glass .sas-box.tone-red {
            --g1: rgba(251, 113, 133, 0.7); --g2: rgba(225, 29, 72, 0.4); --gline: rgba(244, 63, 94, 0.9);
        }
        body.theme-glass .sas-box.tone-yellow {
            --g1: rgba(250, 204, 21, 0.72); --g2: rgba(217, 119, 6, 0.4); --gline: rgba(245, 158, 11, 0.92);
        }
        body.theme-glass .sas-box.tone-teal {
            --g1: rgba(45, 212, 191, 0.7); --g2: rgba(13, 148, 136, 0.4); --gline: rgba(20, 184, 166, 0.9);
        }
        body.theme-glass .sas-box.tone-navy {
            --g1: rgba(148, 163, 184, 0.62); --g2: rgba(51, 65, 85, 0.42); --gline: rgba(100, 116, 139, 0.85);
        }
        body.theme-glass .sas-box.tone-lime {
            --g1: rgba(190, 242, 100, 0.72); --g2: rgba(101, 163, 13, 0.4); --gline: rgba(132, 204, 22, 0.92);
        }
        body.theme-glass .sas-box.tone-purple {
            --g1: rgba(251, 146, 60, 0.72); --g2: rgba(234, 88, 12, 0.4); --gline: rgba(249, 115, 22, 0.9);
        }
        body.theme-glass .sas-box.tone-aqua {
            --g1: rgba(34, 211, 238, 0.72); --g2: rgba(8, 145, 178, 0.4); --gline: rgba(6, 182, 212, 0.92);
        }
        body.theme-glass .sas-box.tone-maroon {
            --g1: rgba(251, 113, 133, 0.7); --g2: rgba(190, 18, 60, 0.42); --gline: rgba(225, 29, 72, 0.9);
        }
        body.theme-glass .sas-box:hover {
            transform: none !important;
            filter: saturate(1.12) brightness(1.04);
            border-color: var(--gline) !important;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.16) !important;
            background:
                linear-gradient(145deg, var(--g1) 0%, var(--g2) 72%, rgba(255,255,255,0.16) 100%) !important;
        }
        body.theme-glass .sas-box::before {
            background: var(--gline) !important;
            box-shadow: 0 0 10px var(--gline);
            width: 4px;
            opacity: 1;
        }
        body.theme-glass .sas-box::after { display: none !important; }
        body.theme-glass .sas-box-title,
        body.theme-glass .sas-box-sub {
            color: rgba(15, 23, 42, 0.88) !important;
            font-weight: 800;
            text-shadow: 0 1px 0 rgba(255,255,255,0.35);
        }
        body.theme-glass .sas-box-val {
            color: #0f172a !important;
            font-weight: 900;
            text-shadow: 0 1px 0 rgba(255,255,255,0.35);
        }
        body.theme-glass .sas-box-ico {
            opacity: 1 !important;
            color: #0f172a !important;
            background: rgba(15, 23, 42, 0.1) !important;
            width: 36px;
            height: 36px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        body.theme-glass .sas-box-ico .sas-ico-svg { width: 22px; height: 22px; }

        /* كل الجداول: شفافة + نص غامق — بدون صفوف بيضاء صلبة */
        body.theme-glass table,
        body.theme-glass .data-table,
        body.theme-glass table.data-table,
        body.theme-glass table.table-compact,
        body.theme-glass .log-table,
        body.theme-glass #subsTable {
            background: transparent !important;
            color: var(--glass-ink) !important;
        }
        body.theme-glass table th,
        body.theme-glass table td,
        body.theme-glass .data-table th,
        body.theme-glass .data-table td,
        body.theme-glass table.table-compact th,
        body.theme-glass table.table-compact td,
        body.theme-glass .log-table th,
        body.theme-glass .log-table td,
        body.theme-glass #subsTable th,
        body.theme-glass #subsTable td,
        body.theme-glass .sas-radius-page #subsTable tbody tr:nth-child(even) td,
        body.theme-glass .sas-radius-page #subsTable tbody tr:hover td {
            border-color: rgba(15, 23, 42, 0.1) !important;
            color: var(--glass-ink) !important;
            background: transparent !important;
        }
        body.theme-glass table th,
        body.theme-glass .data-table th,
        body.theme-glass table.table-compact th,
        body.theme-glass .log-table th,
        body.theme-glass #subsTable th {
            background: rgba(15, 23, 42, 0.06) !important;
            color: #1e293b !important;
        }
        body.theme-glass table tbody tr:hover td,
        body.theme-glass .data-table tbody tr:hover td,
        body.theme-glass #subsTable tbody tr:hover td,
        body.theme-glass .sas-radius-page #subsTable tbody tr:hover td {
            background: rgba(14, 165, 233, 0.1) !important;
            color: var(--glass-ink) !important;
        }
        /* تحديد مشترك: تمييز خفيف بدون طمس البيانات */
        body.theme-glass #subsTable tbody tr.sas-row-ctx {
            outline: 2px solid rgba(37, 99, 235, 0.7) !important;
            outline-offset: -2px;
        }
        body.theme-glass #subsTable tbody tr.sas-row-ctx td,
        body.theme-glass .sas-radius-page #subsTable tbody tr.sas-row-ctx td,
        body.theme-glass .sas-radius-page #subsTable tbody tr.row-status-expired.sas-row-ctx td,
        body.theme-glass .sas-radius-page #subsTable tbody tr.row-status-left.sas-row-ctx td,
        body.theme-glass .sas-radius-page #subsTable tbody tr.row-status-active.sas-row-ctx td,
        body.theme-glass .sas-radius-page #subsTable tbody tr.row-status-expired.sas-row-ctx:hover td,
        body.theme-glass .sas-radius-page #subsTable tbody tr.row-status-left.sas-row-ctx:hover td {
            background: rgba(59, 130, 246, 0.18) !important;
            color: #0f172a !important;
        }
        body.theme-glass #subsTable tbody tr.sas-row-ctx td *,
        body.theme-glass #subsTable tbody tr.sas-row-ctx a {
            color: inherit !important;
        }
        body.theme-glass .sas-radius-page #subsTable tbody tr.row-status-expired td {
            background: rgba(250, 204, 21, 0.14) !important;
        }
        body.theme-glass .sas-radius-page #subsTable tbody tr.row-status-left td {
            background: rgba(248, 113, 113, 0.14) !important;
        }
        body.theme-glass .btn,
        body.theme-glass .ops-top-btn,
        body.theme-glass .tool-ico,
        body.theme-glass .ping-mode-btn,
        body.theme-glass .cards-page .btn,
        body.theme-glass .cards-page .chip-copy {
            background: rgba(248, 250, 252, 0.45) !important;
            border: 1px solid rgba(15, 23, 42, 0.12) !important;
            color: var(--glass-ink) !important;
            box-shadow: none !important;
            border-radius: 12px !important;
        }
        body.theme-glass .btn:hover,
        body.theme-glass .ops-top-btn:hover,
        body.theme-glass .tool-ico:hover {
            background: rgba(248, 250, 252, 0.65) !important;
        }
        body.theme-glass .btn.ghost,
        body.theme-glass .btn.outline {
            background: transparent !important;
            border-color: rgba(15, 23, 42, 0.18) !important;
            color: var(--glass-ink) !important;
        }
        body.theme-glass .tabs a.on,
        body.theme-glass .ping-mode-btn.is-on {
            background: rgba(14, 165, 233, 0.2) !important;
            border-color: rgba(14, 165, 233, 0.45) !important;
            color: #0c4a6e !important;
        }
        body.theme-glass input,
        body.theme-glass select,
        body.theme-glass textarea {
            background: rgba(248, 250, 252, 0.5) !important;
            border-color: rgba(15, 23, 42, 0.14) !important;
            color: var(--glass-ink) !important;
        }
        body.theme-glass input::placeholder,
        body.theme-glass textarea::placeholder { color: #64748b; }
        body.theme-glass .alert {
            background: rgba(248, 250, 252, 0.55) !important;
            border: 1px solid rgba(15, 23, 42, 0.12) !important;
            color: var(--glass-ink) !important;
        }
        body.theme-glass a.sas-link,
        body.theme-glass .sas-user-copywrap a { color: #0369a1 !important; }
        body.theme-glass .footer {
            background: transparent;
            color: rgba(248, 250, 252, 0.75);
            border: 0;
            text-shadow: 0 1px 2px rgba(0,0,0,0.35);
        }

        /* ثيم كلاسيك */
        body.theme-classic .sidebar {
          background: #12181f !important;
          backdrop-filter: none;
          -webkit-backdrop-filter: none;
          border-inline-end: 1px solid rgba(255,255,255,0.06);
        }
        body.theme-classic.app-bg-image {
          background-color: <?php echo e($bgColor); ?> !important;
          background-image: url("<?php echo e($bgUrl !== '' ? $bgUrl : $glassBgSrc); ?>") !important;
          background-size: cover !important;
          background-position: center center !important;
          background-repeat: no-repeat !important;
          background-attachment: scroll !important;
        }
        body.theme-classic.app-bg-image .bg-bubbles { display: none; }
        body.theme-classic.app-bg-image .app,
        body.theme-classic.app-bg-image .main { background: transparent; }
        body.theme-classic.app-bg-image .panel,
        body.theme-classic.app-bg-image .sas-table-card,
        body.theme-classic.app-bg-image .glass-panel,
        body.theme-classic.app-bg-image .main-top {
          background: rgba(255,255,255,0.78) !important;
          backdrop-filter: blur(12px) saturate(1.15);
          -webkit-backdrop-filter: blur(12px) saturate(1.15);
          border-color: rgba(255,255,255,0.45) !important;
          color: #1c2430;
        }
        body.theme-classic.app-bg-color {
          background: <?php echo e($bgColor); ?> !important;
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
          justify-content: flex-start;
          gap: 10px;
          text-align: start;
          min-height: 42px;
          padding: 10px 14px;
          border-radius: 10px;
          font-size: 13.5px;
          font-weight: 700;
          color: rgba(248,250,252,0.72);
          border: 1px solid transparent;
        }
        /* عربي: من اليمين | إنكليزي: من اليسار */
        body.rtl .side-links a,
        body:not(.ltr) .side-links a {
          justify-content: flex-start;
          text-align: right;
        }
        body.ltr .side-links a {
          justify-content: flex-start;
          text-align: left;
        }
        .side-links a .nav-ico {
          display: inline-flex !important;
          align-items: center;
          justify-content: center;
          flex: 0 0 auto;
          width: 22px;
          height: 22px;
          color: rgba(148, 163, 184, 0.95);
        }
        .side-links a .nav-ico svg {
          display: block;
          width: 18px;
          height: 18px;
        }
        .side-links a:hover {
          background: rgba(255,255,255,0.06);
          color: #fff;
        }
        .side-links a:hover .nav-ico { color: #e2e8f0; }
        .side-links a.active {
          background: rgba(56, 189, 248, 0.14);
          color: #e0f2fe;
          border-color: rgba(56, 189, 248, 0.22);
          box-shadow: none;
        }
        .side-links a.active .nav-ico { color: #7dd3fc; }
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
        .top-admin-dropdown a:hover,
        .top-admin-dropdown button:hover {
          background: #f1f5f9;
        }
        body.theme-glass .top-admin-dropdown a,
        body.theme-glass .top-admin-dropdown button {
          color: #f8fafc;
        }
        body.theme-glass .top-admin-dropdown a:hover,
        body.theme-glass .top-admin-dropdown button:hover {
          background: rgba(255,255,255,0.12);
        }
    </style>
</head>
<body class="<?php echo $isEn ? 'ltr' : 'rtl'; ?> theme-<?php echo e($uiTheme); ?><?php
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
            <a class="<?php echo $active === 'dashboard' ? 'active' : ''; ?>" href="index.php"><?php echo nav_side_ico('dashboard'); ?><?php echo e(t('dashboard')); ?></a>
            <a class="<?php echo $active === 'companies' ? 'active' : ''; ?>" href="companies.php"><?php echo nav_side_ico('companies'); ?><?php echo e($isEn ? 'Companies' : 'الشركات'); ?></a>
            <a class="<?php echo $active === 'saas_agents' ? 'active' : ''; ?>" href="saas_agents.php"><?php echo nav_side_ico('agents'); ?><?php echo e($isEn ? 'Agents' : 'وكلاء'); ?></a>
            <?php if ($can('subscribers')): ?>
            <a class="<?php echo $active === 'import_export' ? 'active' : ''; ?>" href="import_export.php"><?php echo nav_side_ico('import_export'); ?><?php echo e($isEn ? 'Import & Export' : 'استيراد وتصدير'); ?></a>
            <?php endif; ?>
            <?php if ($can('logs')): ?>
            <a class="<?php echo $active === 'system_activity' ? 'active' : ''; ?>" href="logs.php?scope=system" data-nav="system_activity"><?php echo nav_side_ico('activity'); ?><?php echo e($isEn ? 'System activity' : 'حركات النظام'); ?></a>
            <a class="<?php echo $active === 'logs' ? 'active' : ''; ?>" href="logs.php?scope=audit" data-nav="logs"><?php echo nav_side_ico('logs'); ?><?php echo e($isEn ? 'Log' : 'اللوك'); ?></a>
            <?php endif; ?>
            <?php if ($can('settings') || $can('users') || $can('plans') || $can('backup')): ?>
            <a class="<?php echo $settingsActive && $active !== 'schedule' ? 'active' : ''; ?>" href="settings.php"><?php echo nav_side_ico('settings'); ?><?php echo e(t('settings')); ?></a>
            <?php endif; ?>
            <a class="<?php echo $active === 'company' ? 'active' : ''; ?>" href="company.php"><?php echo nav_side_ico('company'); ?><?php echo e($isEn ? 'About company' : 'عن الشركة'); ?></a>
            <a href="logout.php"><?php echo nav_side_ico('logout'); ?><?php echo e(t('logout')); ?></a>
            <?php else: ?>
            <?php /* —— قائمة الوكالة / الوكيل (كل خصائص المشتركين تبقى هنا) —— */ ?>
            <?php if ($can('dashboard')): ?>
            <a class="<?php echo $active === 'dashboard' ? 'active' : ''; ?>" href="index.php"><?php echo nav_side_ico('dashboard'); ?><?php echo e(t('dashboard')); ?></a>
            <?php endif; ?>
            <?php if ($can('subscribers')): ?>
            <a class="<?php echo $active === 'sas' ? 'active' : ''; ?>" href="sas.php"><?php echo nav_side_ico('subscribers'); ?><?php echo e(t('sas')); ?></a>
            <?php endif; ?>
            <?php
            $hideLeafCards = isset($pdo) && function_exists('account_viewer_is_leaf_child') && account_viewer_is_leaf_child($pdo);
            if ($can('cards') && !$hideLeafCards): ?>
            <a class="<?php echo $active === 'cards' ? 'active' : ''; ?>" href="cards.php"><?php echo nav_side_ico('cards'); ?><?php echo e($isEn ? 'Cards' : 'الكارتات'); ?></a>
            <?php endif; ?>
            <?php if ($can('plans')): ?>
            <a class="<?php echo $active === 'plans' ? 'active' : ''; ?>" href="plans.php"><?php echo nav_side_ico('plans'); ?><?php echo e(t('plans')); ?></a>
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
            <a class="<?php echo $active === 'agents' ? 'active' : ''; ?>" href="agents.php"><?php echo nav_side_ico('agents'); ?><?php echo e($isEn ? 'Agents' : 'الوكلاء'); ?></a>
            <?php endif; ?>
            <?php if ($can('agents') || (function_exists('is_agent_user') && is_agent_user()) || (function_exists('is_group_manager_user') && is_group_manager_user())): ?>
            <a class="<?php echo $active === 'accountants' ? 'active' : ''; ?>" href="agents.php?view=accountant"><?php echo nav_side_ico('accountants'); ?><?php echo e($isEn ? 'Accountant' : 'المحاسب'); ?></a>
            <?php endif; ?>
            <?php
            $navFtth = isset($pdo) && function_exists('tenant_is_ftth') && tenant_is_ftth($pdo);
            if ($navFtth && ($can('subscribers') || $can('dashboard'))): ?>
            <a class="<?php echo $active === 'cabinets' ? 'active' : ''; ?>" href="cabinets.php"><?php echo nav_side_ico('cabinets'); ?><?php echo e($isEn ? 'Cabinets' : 'الكابينات'); ?></a>
            <?php endif; ?>
            <?php
            $navRentOn = !function_exists('rental_system_is_on') || rental_system_is_on();
            if ($can('rentals') && !$navFtth && $navRentOn): ?>
            <a class="<?php echo $active === 'rentals' ? 'active' : ''; ?>" href="rentals.php"><?php echo nav_side_ico('rentals'); ?><?php echo e($isEn ? 'Rentals' : 'الإيجار'); ?></a>
            <?php endif; ?>
            <?php if ($can('debts') && !$navFtth): ?>
            <a class="<?php echo $active === 'debts' ? 'active' : ''; ?>" href="debts.php"><?php echo nav_side_ico('debts'); ?><?php echo e(t('debts')); ?></a>
            <?php endif; ?>
            <?php if ($can('subscribers')): ?>
            <a class="<?php echo $active === 'import_export' ? 'active' : ''; ?>" href="import_export.php"><?php echo nav_side_ico('import_export'); ?><?php echo e($isEn ? 'Import & Export' : 'استيراد وتصدير'); ?></a>
            <?php endif; ?>
            <?php if ($can('subscriptions')): ?>
            <a class="<?php echo $active === 'subscriptions' ? 'active' : ''; ?>" href="subscriptions.php"><?php echo nav_side_ico('movements'); ?><?php echo e(t('movements')); ?></a>
            <?php endif; ?>
            <?php if ($can('messages')): ?>
            <a class="<?php echo $active === 'messages' ? 'active' : ''; ?>" href="messages.php"><?php echo nav_side_ico('messages'); ?><?php echo e(t('messages')); ?></a>
            <?php endif; ?>
            <?php if ($can('reports')): ?>
            <a class="<?php echo $active === 'reports' ? 'active' : ''; ?>" href="reports.php"><?php echo nav_side_ico('reports'); ?><?php echo e(t('reports')); ?></a>
            <a class="<?php echo $active === 'profit' ? 'active' : ''; ?>" href="profit_report.php"><?php echo nav_side_ico('profit'); ?><?php echo e($isEn ? 'Profits' : 'الأرباح'); ?></a>
            <?php endif; ?>
            <?php if ($can('logs')): ?>
            <a class="<?php echo $active === 'logs' ? 'active' : ''; ?>" href="logs.php"><?php echo nav_side_ico('logs'); ?><?php echo e($isEn ? 'Log' : 'اللوك'); ?></a>
            <?php endif; ?>
            <?php if ($can('settings') || $can('users') || $can('plans') || $can('backup')): ?>
            <a class="<?php echo $settingsActive && $active !== 'schedule' ? 'active' : ''; ?>" href="settings.php?tab=sas"><?php echo nav_side_ico('settings'); ?><?php echo e(t('settings')); ?></a>
            <?php endif; ?>
            <a class="<?php echo $active === 'company' ? 'active' : ''; ?>" href="company.php"><?php echo nav_side_ico('company'); ?><?php echo e($isEn ? 'Company' : 'عن الشركة'); ?></a>
            <?php
            $navTid = $navTidEarly;
            if ($navTid > 1):
            ?>
            <a class="<?php echo $active === 'billing' ? 'active' : ''; ?>" href="billing.php"><?php echo nav_side_ico('billing'); ?><?php echo e($isEn ? 'Billing' : 'الاشتراك'); ?></a>
            <?php endif; ?>
            <?php
            $hideSideLogout = (function_exists('is_agent_user') && is_agent_user()) || $navTid > 1;
            if (!$hideSideLogout):
            ?>
            <a href="logout.php"><?php echo nav_side_ico('logout'); ?><?php echo e(t('logout')); ?></a>
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
                <div class="sas-toast sas-toast-<?php echo e($flash['type']); ?><?php echo $flashStay ? ' sas-toast-stay' : ' sas-toast-mid'; ?>" role="status">
                    <span class="sas-toast-text"><?php echo e($flash['message']); ?></span>
                    <?php if ($flashStay): ?>
                        <button type="button" class="sas-toast-x" aria-label="<?php echo e($isEn ? 'Close' : 'إغلاق'); ?>">×</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <style>
            #appVeil[hidden], #appRing[hidden] { display: none !important; }
            #appVeil {
                position: fixed !important;
                inset: 0 !important;
                z-index: 199998 !important;
                background: rgba(255,255,255,.22);
                backdrop-filter: blur(2.5px);
                -webkit-backdrop-filter: blur(2.5px);
                pointer-events: none;
                margin: 0 !important;
            }
            /* وسط الشاشة فعلياً (RTL/LTR) — inset+margin بدون left% اللي ينكسر بالعربي */
            #appRing {
                position: fixed !important;
                top: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                left: 0 !important;
                inset: 0 !important;
                width: 72px !important;
                height: 72px !important;
                margin: auto !important;
                transform: none !important;
                z-index: 199999 !important;
                border-radius: 50%;
                background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .16);
                display: flex !important; align-items: center; justify-content: center;
                pointer-events: none;
            }
            #appRing[hidden] { display: none !important; }
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
            .sas-toast,
            .sas-toast.sas-toast-mid {
                position: fixed !important;
                top: 8px !important;
                bottom: auto !important;
                z-index: 100003 !important;
                margin: 0 !important;
                inset-inline-start: auto !important;
                inset-inline-end: auto !important;
                left: auto !important;
                right: calc(250px + 16px) !important;
                width: min(340px, 42vw);
                max-width: min(420px, calc(100vw - 24px));
                padding: 12px 14px; border-radius: 14px; font-weight: 700;
                text-align: start !important;
                justify-content: flex-start !important;
                box-shadow: 0 14px 40px rgba(15, 23, 42, .22);
                display: flex; align-items: flex-start; gap: 10px;
                opacity: 1;
                transform: translateY(0);
                transition: opacity .7s ease, transform .7s ease;
            }
            body.ltr .sas-toast,
            body.ltr .sas-toast.sas-toast-mid {
                left: 16px !important;
                right: auto !important;
            }
            .app.sidebar-collapsed .sas-toast,
            .app.sidebar-collapsed .sas-toast.sas-toast-mid {
                right: 16px !important;
            }
            body.ltr .app.sidebar-collapsed .sas-toast,
            body.ltr .app.sidebar-collapsed .sas-toast.sas-toast-mid {
                left: 16px !important;
                right: auto !important;
            }
            .sas-toast[hidden] { display: none !important; }
            .sas-toast.is-out,
            .sas-toast.sas-toast-mid.is-out {
                opacity: 0 !important;
                transform: translateY(-56px) !important;
            }
            .sas-toast-text { flex: 1; line-height: 1.45; }
            .sas-toast-x {
                border: 0; background: transparent; color: inherit; font-size: 20px;
                line-height: 1; cursor: pointer; padding: 0 2px;
            }
            @media (max-width: 640px) {
                .sas-toast,
                .sas-toast.sas-toast-mid,
                body.ltr .sas-toast,
                body.ltr .sas-toast.sas-toast-mid,
                .app.sidebar-collapsed .sas-toast,
                .app.sidebar-collapsed .sas-toast.sas-toast-mid {
                    top: 10px !important;
                    bottom: auto !important;
                    left: 12px !important;
                    right: 12px !important;
                    width: auto !important;
                    max-width: none !important;
                }
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
  }
  function stickyWa(msg) {
    var old = document.getElementById('waLinkToast');
    if (old) {
      var held = old.querySelector('.sas-toast-text');
      if (held && held.textContent === msg) return;
      if (old.parentNode) old.parentNode.removeChild(old);
    }
    hideBar();
    var el = document.createElement('div');
    el.id = 'waLinkToast';
    el.className = 'sas-toast sas-toast-error sas-toast-stay';
    el.setAttribute('role', 'status');
    var span = document.createElement('span');
    span.className = 'sas-toast-text';
    span.textContent = msg;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'sas-toast-x';
    btn.setAttribute('aria-label', isEn ? 'Close' : 'إغلاق');
    btn.textContent = '×';
    btn.addEventListener('click', function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    });
    el.appendChild(span);
    el.appendChild(btn);
    document.body.appendChild(el);
    if (window.placeCornerToast) window.placeCornerToast(el);
  }
  function clearWaToast() {
    var old = document.getElementById('waLinkToast');
    if (old && old.parentNode) old.parentNode.removeChild(old);
  }
  function setProblem(cls, msg) {
    var key = cls + '|' + msg;
    if (key === lastKey) return;
    lastKey = key;
    stickyWa(msg);
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
        clearWaToast();
        lastKey = 'ok';
        failStreak = 0;
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

  setTimeout(check, 1500);
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
  setTimeout(fireTick, 800);
  setInterval(fireTick, 25000);
})();

window.placeCornerToast = function (el) {
  if (!el || !el.style) return;
  if (el.parentNode && el.parentNode !== document.body) document.body.appendChild(el);
  function set(prop, val) { el.style.setProperty(prop, val, 'important'); }
  set('position', 'fixed');
  set('z-index', '100003');
  var fading = !el.classList.contains('sas-toast-stay');
  if (fading && el.classList.contains('is-out')) return;
  if (el.id === 'sasSyncOkBanner') {
    el.classList.remove('sas-toast-mid', 'is-in');
  } else if (fading) {
    el.classList.add('sas-toast-mid');
    el.classList.remove('is-in');
  }
  ['opacity', 'transform', 'inset-inline-start', 'inset-inline-end', 'bottom', 'margin'].forEach(function (prop) {
    el.style.removeProperty(prop);
  });
  var phone = window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
  set('bottom', 'auto');
  set('top', phone ? '10px' : '8px');
  if (phone) {
    set('left', '12px');
    set('right', '12px');
    set('width', 'auto');
    set('max-width', 'none');
    return;
  }
  var main = document.querySelector('.main');
  var r = main ? main.getBoundingClientRect() : null;
  set('width', 'min(340px, 42vw)');
  set('max-width', '340px');
  if (!r || r.width < 80) {
    set('left', '16px');
    set('right', 'auto');
    return;
  }
  if (document.body.classList.contains('rtl')) {
    set('right', Math.max(12, Math.round(window.innerWidth - r.right + 14)) + 'px');
    set('left', 'auto');
  } else {
    set('left', Math.max(12, Math.round(r.left + 14)) + 'px');
    set('right', 'auto');
  }
};
window.showCornerToast = function (msg, kind) {
  var text = String(msg || '').replace(/^\s+|\s+$/g, '');
  if (!text) return;
  var old = document.getElementById('appCornerToast');
  if (old && old.parentNode) old.parentNode.removeChild(old);
  var el = document.createElement('div');
  el.id = 'appCornerToast';
  el.className = 'sas-toast sas-toast-mid ' + (kind === 'error' ? 'sas-toast-error' : 'sas-toast-success');
  el.setAttribute('role', 'status');
  var span = document.createElement('span');
  span.className = 'sas-toast-text';
  span.textContent = text;
  el.appendChild(span);
  document.body.appendChild(el);
  if (window.placeCornerToast) window.placeCornerToast(el);
  setTimeout(function () {
    el.classList.add('is-out');
    setTimeout(function () {
      if (el.parentNode) el.parentNode.removeChild(el);
    }, 820);
  }, 3000);
};
(function () {
  var wait = <?php echo !empty($_SESSION['wa_resend_wait']) ? 'true' : 'false'; ?>;
  try {
    if (sessionStorage.getItem('waResendWait') === '1') wait = true;
    if (wait) sessionStorage.setItem('waResendWait', '1');
  } catch (e) {}
  if (!wait) return;
  var tries = 0;
  var idle = 0;
  var en = <?php echo !empty($isEn) ? 'true' : 'false'; ?>;
  function stopWait() {
    try { sessionStorage.removeItem('waResendWait'); } catch (e) {}
  }
  function poll() {
    var x = new XMLHttpRequest();
    x.open('GET', 'messages.php?ajax=wa_notice', true);
    x.timeout = 8000;
    x.onload = function () {
      var d = null;
      try { d = JSON.parse(x.responseText || ''); } catch (err) { d = null; }
      if (d && d.notice) {
        var ok = parseInt(d.notice.ok, 10) || 0;
        var fail = parseInt(d.notice.fail, 10) || 0;
        var msg = '';
        if (ok > 0 && fail < 1) msg = en ? ('Sent ' + ok) : ('تم إرسال ' + ok + ' رسالة');
        else if (ok > 0) msg = en ? ('Sent ' + ok + ', failed ' + fail) : ('تم إرسال ' + ok + ' وفشل ' + fail);
        else if (fail > 0) msg = en ? ('Failed to send ' + fail) : ('فشل إرسال ' + fail + ' رسالة');
        if (msg && window.showCornerToast) window.showCornerToast(msg, (fail > 0 && ok < 1) ? 'error' : 'success');
        stopWait();
        return;
      }
      var pending = d && d.pending ? parseInt(d.pending, 10) : 0;
      if (pending > 0) idle = 0;
      else idle += 1;
      tries += 1;
      if (idle >= 3 || tries > 200) {
        stopWait();
        return;
      }
      setTimeout(poll, 4000);
    };
    x.onerror = x.ontimeout = function () {
      tries += 1;
      if (tries > 200) { stopWait(); return; }
      setTimeout(poll, 4000);
    };
    x.send();
  }
  setTimeout(poll, 2500);
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
    if (on && modal.parentNode !== document.body) {
      document.body.appendChild(modal);
    }
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

  function pinToViewport(el) {
    if (el && el.parentNode !== document.body) {
      document.body.appendChild(el);
    }
  }
  function showWait(on) {
    if (!veil) return;
    if (on) pinToViewport(veil);
    veil.hidden = !on;
  }
  function showRing() {
    if (!ring) return;
    pinToViewport(veil);
    pinToViewport(ring);
    ring.style.top = '';
    ring.style.left = '';
    ring.style.right = '';
    ring.style.bottom = '';
    ring.style.inset = '';
    ring.style.margin = '';
    ring.style.transform = '';
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
    navHold = Date.now();
    armRing();
  }, true);
  var navHold = 0;
  document.addEventListener('submit', function (ev) {
    if (ev.defaultPrevented) return;
    var form = ev.target;
    if (!form || !form.getAttribute) return;
    if (form.getAttribute('data-no-wait') === '1') return;
    navHold = Date.now();
    if (form.id === 'cardXferForm') showRing();
    else armRing();
  });
  window.addEventListener('pagehide', function () {
    if (navHold && (Date.now() - navHold) < 20000) return;
    finishRing();
  });
  window.addEventListener('pageshow', hideRing);

  (function () {
    var top = document.querySelector('.main-top');
    if (!top) return;
    function markHeight() {
      var h = top.offsetHeight || 64;
      document.documentElement.style.setProperty('--main-top-h', h + 'px');
    }
    markHeight();
    var lastWin = window.pageYOffset || 0;
    var topLock = 0;
    function onMove(y, prev) {
      if (Date.now() < topLock) return;
      markHeight();
      var hidden = document.body.classList.contains('top-away');
      if (!hidden && y > prev + 12 && y > 36) {
        document.body.classList.add('top-away');
        topLock = Date.now() + 400;
      } else if (hidden && (y < prev - 12 || y < 8)) {
        document.body.classList.remove('top-away');
        topLock = Date.now() + 400;
      }
    }
    window.addEventListener('scroll', function () {
      var y = window.pageYOffset || document.documentElement.scrollTop || 0;
      var prev = lastWin;
      lastWin = y;
      onMove(y, prev);
    }, { passive: true });
    var wraps = document.querySelectorAll('.table-wrap');
    for (var i = 0; i < wraps.length; i++) {
      (function (el) {
        var prev = el.scrollTop || 0;
        el.addEventListener('scroll', function () {
          var y = el.scrollTop || 0;
          var p = prev;
          prev = y;
          onMove(y, p);
        }, { passive: true });
      })(wraps[i]);
    }
  })();

  function placeAllToasts() {
    var nodes = document.querySelectorAll('.sas-toast');
    for (var i = 0; i < nodes.length; i++) {
      if (window.placeCornerToast) window.placeCornerToast(nodes[i]);
    }
  }
  placeAllToasts();
  window.addEventListener('resize', placeAllToasts);
  window.addEventListener('scroll', placeAllToasts, { passive: true });
  var toastBoxes = document.querySelectorAll('.table-wrap');
  for (var ti = 0; ti < toastBoxes.length; ti++) {
    toastBoxes[ti].addEventListener('scroll', placeAllToasts, { passive: true });
  }
  var toast = document.querySelector('.sas-toast:not(#sasSyncOkBanner)');
  if (toast) {
    var closeToast = function () {
      toast.classList.add('is-out');
      setTimeout(function () {
        if (toast.parentNode) toast.parentNode.removeChild(toast);
      }, 820);
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
