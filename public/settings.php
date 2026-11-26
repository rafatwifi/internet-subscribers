<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/settings_tabs.php';
require_login();

function settings_verify_wipe_password($pdo, $config, $pass)
{
    $pass = (string) $pass;
    $me = current_admin();
    if ($me && isset($me['id']) && (int) $me['id'] > 0) {
        if (verify_user_password($pdo, (int) $me['id'], $pass)) {
            return true;
        }
    }
    if (isset($config['admin_password']) && hash_equals((string) $config['admin_password'], $pass)) {
        return true;
    }
    return false;
}

function settings_unlink_sas_locals($pdo)
{
    try {
        $pdo->exec('UPDATE sas_users_cache SET local_subscriber_id = NULL');
    } catch (Exception $e) {
    }
}

function settings_wipe_offline_tables($pdo)
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('TRUNCATE TABLE message_logs');
    $pdo->exec('TRUNCATE TABLE invoices');
    $pdo->exec('TRUNCATE TABLE subscriptions');
    $pdo->exec('TRUNCATE TABLE subscribers');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    settings_unlink_sas_locals($pdo);
}

$tab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'general';
if ($tab === 'templates') {
    redirect('messages.php?mode=templates');
}
// POST لربط الساس من الوكالة: احسب التبويب من القسم حتى ما تنرفض الصلاحية
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'sas') {
    $tab = 'sas';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'ui_theme') {
    $tab = 'theme';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'user_prefs') {
    $tab = 'general';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'system_behavior') {
    $tab = 'behavior';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'invoice_design') {
    $tab = 'invoices';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'invoice_printer') {
    $tab = 'invoices';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'invoice_send') {
    $tab = 'invoices';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'invoice_seq') {
    $tab = 'invoices';
}
if (!in_array($tab, array('general', 'behavior', 'whatsapp', 'templates', 'rental', 'devices', 'users', 'plans', 'sas', 'saas', 'schedule', 'sensitive', 'update', 'maintenance', 'widgets', 'theme', 'invoices', 'printers'), true)) {
    $tab = 'general';
}
// تبويبات محذوفة من إعدادات أدمن البوابة
if ($tab === 'rental' || $tab === 'plans') {
    $tab = (function_exists('is_super_admin_user') && is_super_admin_user()) ? 'general' : 'theme';
}
if ($tab === 'sas' && function_exists('is_super_admin_user') && is_super_admin_user()
    && (!function_exists('current_tenant_id') || (int) current_tenant_id() <= 1)) {
    $tab = 'general';
}

$isAgentWaOnly = ($tab === 'whatsapp' && function_exists('is_agent_user') && is_agent_user());
$agencyTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$isPlatformAdmin = function_exists('is_super_admin_user') && is_super_admin_user();
// وكالة: تبويب ربط الساس مسموح بدون صلاحية settings كاملة
$isInvoiceTab = ($tab === 'invoices' || $tab === 'printers');
$isAgencySasOnly = ($tab === 'sas' && $agencyTid > 1);

if ($tab === 'users') {
    require_perm('users');
} elseif ($tab === 'plans') {
    redirect('plans.php');
} elseif ($tab === 'schedule') {
    redirect('messages.php?mode=schedule');
} elseif ($tab === 'sensitive' || $tab === 'update') {
    require_perm($tab === 'sensitive' ? 'clear_data' : 'settings');
    if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
        flash('error', $lang === 'en' ? 'Platform admin only' : 'للمدير العام فقط');
        redirect('settings.php?tab=sas');
    }
} elseif ($tab === 'saas' || $tab === 'maintenance') {
    require_perm('settings');
    if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
        flash('error', $lang === 'en' ? 'Super admin only' : 'للمدير العام فقط');
        redirect('settings.php');
    }
} elseif ($tab === 'widgets' || $tab === 'theme' || $tab === 'behavior' || $tab === 'general' || $tab === 'devices' || $isAgentWaOnly || $isAgencySasOnly || $isInvoiceTab) {
    // وجدز وثيم لكل حساب، وواتساب للوكيل، وربط ساس للوكالة، وأجهزة الشبكة
    if ($tab === 'devices' && !user_can('settings') && !user_can('devices')) {
        require_perm('devices');
    }
} else {
    // وكالة بدون صلاحية settings: وجّه مباشرة لربط الساس
    if ($agencyTid > 1 && !user_can('settings') && $tab === 'general') {
        redirect('settings.php?tab=sas');
    }
    require_perm('settings');
}

if (!$isPlatformAdmin && $tab === 'users') {
    redirect('settings.php?tab=general');
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'sys_status') {
    if (!$isPlatformAdmin) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false));
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    if (isset($_GET['refresh']) && $_GET['refresh'] === '1') {
        $_SESSION['sas_rp_at'] = 0;
    }
    $sys = collect_system_status($config);
    $points = '—';
    if (function_exists('sas_is_ready') && sas_is_ready($config) && function_exists('sas_manager_reward_points')) {
        list($ptsOk, $ptsVal) = sas_manager_reward_points($config, $pdo);
        if ($ptsOk && $ptsVal !== null) {
            $points = ((float) $ptsVal == (int) $ptsVal)
                ? number_format((int) $ptsVal)
                : number_format((float) $ptsVal, 2);
        }
    }
    $gMs = isset($sys['google']['ms']) ? $sys['google']['ms'] : null;
    $sasMs = isset($sys['sas_latency']['ms']) ? $sys['sas_latency']['ms'] : null;
    $sasOk = !empty($sys['sas_latency']['ok']);
    $sasHost = isset($sys['sas_latency']['host']) ? (string) $sys['sas_latency']['host'] : '';
    $fmtMs = function ($ms) {
        return function_exists('system_format_ms') ? system_format_ms($ms) : (($ms !== null) ? (number_format((float) $ms, 1) . ' ms') : '—');
    };
    $portalSubs = 0;
    if (function_exists('is_super_admin_user') && is_super_admin_user()
        && !(function_exists('is_impersonating') && is_impersonating())
        && function_exists('platform_portal_subscribers_total') && isset($pdo)
    ) {
        $portalSubs = (int) platform_portal_subscribers_total($pdo);
    }
    echo json_encode(array(
        'ok' => true,
        'cpu' => isset($sys['cpu']['label']) ? $sys['cpu']['label'] : '—',
        'cpu_pct' => isset($sys['cpu']['pct']) ? $sys['cpu']['pct'] : null,
        'google_ms' => $gMs,
        'google_label' => $fmtMs($gMs),
        'google_ok' => !empty($sys['google']['ok']),
        'sas_ms' => $sasMs,
        'sas_ok' => $sasOk,
        'sas_host' => $sasHost,
        'sas_name' => isset($sys['sas_latency']['name']) ? (string) $sys['sas_latency']['name'] : '',
        'bank' => $fmtMs($sasMs),
        'points' => $points,
        'ram' => $sys['ram']['label'],
        'ram_pct' => isset($sys['ram']['pct_used']) ? (int) $sys['ram']['pct_used'] : 0,
        'disk' => $sys['disk']['ok'] ? $sys['disk']['label'] : '—',
        'disk_pct' => isset($sys['disk']['pct_used']) ? (int) $sys['disk']['pct_used'] : 0,
        'whatsapp' => $sys['whatsapp']['label'],
        'server_time' => $sys['server_time'],
        'portal_subscribers' => $portalSubs,
    ));
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'sas_otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!verify_csrf(post('csrf'))) {
        echo json_encode(array('ok' => false, 'error' => 'bad'));
        exit;
    }
    $tidAjax = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $pending = (isset($_SESSION['sas_link_otp']) && is_array($_SESSION['sas_link_otp'])) ? $_SESSION['sas_link_otp'] : null;
    if (!$pending || empty($pending['fields']) || !is_array($pending['fields'])) {
        $reId = (int) post('account_id', '0');
        $reRow = ($reId > 0 && function_exists('tenant_sas_account_row'))
            ? tenant_sas_account_row($pdo, $reId, $tidAjax) : null;
        if (!$reRow) {
            echo json_encode(array('ok' => false, 'error' => 'bad'));
            exit;
        }
        $pending = array(
            'fields' => array(
                'label' => isset($reRow['label']) ? (string) $reRow['label'] : '',
                'company_id' => !empty($reRow['company_id']) ? (int) $reRow['company_id'] : null,
                'sas_enabled' => 1,
                'sas_host' => (string) $reRow['sas_host'],
                'sas_username' => (string) $reRow['sas_username'],
                'sas_password' => (string) $reRow['sas_password'],
                'sas_parent_id' => isset($reRow['sas_parent_id']) ? (int) $reRow['sas_parent_id'] : 1,
                'sas_default_password' => isset($reRow['sas_default_password']) ? (string) $reRow['sas_default_password'] : '1234',
                'sas_activate_units' => isset($reRow['sas_activate_units']) ? (int) $reRow['sas_activate_units'] : 1,
                'sas_extend_method' => isset($reRow['sas_extend_method']) ? (string) $reRow['sas_extend_method'] : 'reward_points',
                'sas_extend_profile_id' => isset($reRow['sas_extend_profile_id']) ? (int) $reRow['sas_extend_profile_id'] : 0,
                'sas_on_failure' => isset($reRow['sas_on_failure']) ? (string) $reRow['sas_on_failure'] : 'warn',
                'is_default' => !empty($reRow['is_default']),
            ),
            'edit_id' => $reId,
            'provider' => 'sas',
            'company_id' => !empty($reRow['company_id']) ? (int) $reRow['company_id'] : 0,
        );
    }
    $code = preg_replace('/\D+/', '', (string) post('sas_otp', ''));
    if (strlen($code) < 6) {
        echo json_encode(array('ok' => false, 'error' => 'short'));
        exit;
    }
    $fieldsAcc = $pending['fields'];
    list($okT, $msgT) = tenant_test_sas_connection(
        $fieldsAcc['sas_host'],
        $fieldsAcc['sas_username'],
        $fieldsAcc['sas_password'],
        $code
    );
    if (!$okT) {
        echo json_encode(array('ok' => false, 'error' => 'bad'));
        exit;
    }
    $editId = isset($pending['edit_id']) ? (int) $pending['edit_id'] : 0;
    unset($_SESSION['sas_link_otp'], $_SESSION['sas_otp_dismissed']);
    list($okS, $msgS, $newId) = tenant_sas_account_save($pdo, $tidAjax, $fieldsAcc, $editId);
    if (!$okS) {
        echo json_encode(array('ok' => false, 'error' => 'bad'));
        exit;
    }
    $accIdMark = (int) $newId > 0 ? (int) $newId : $editId;
    if ($accIdMark > 0) {
        try {
            $pdo->prepare(
                'UPDATE tenant_sas_accounts
                 SET last_ok_at = NOW(), last_error = NULL,
                     login_fail_count = 0, login_next_retry_at = NULL
                 WHERE id = :id AND tenant_id = :t'
            )->execute(array(':id' => $accIdMark, ':t' => $tidAjax));
        } catch (Exception $eOtp) {
        }
    }
    if (function_exists('sas_mark_connection')) {
        sas_mark_connection($pdo, $config, true, $msgT, $tidAjax);
    }
    echo json_encode(array('ok' => true));
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(90);
    }
    if (!function_exists('sas_sync_request_now')) {
        $warmFile = dirname(__DIR__) . '/includes/portal_warm.php';
        if (is_file($warmFile)) {
            require_once $warmFile;
        }
    }
    if (function_exists('sas_sync_request_now')) {
        sas_sync_request_now($tidAjax);
    }
    if (function_exists('sas_sync_users_from_api')) {
        $GLOBALS['sas_warm_pages'] = 12;
        try {
            sas_sync_users_from_api($pdo, $config, true, false);
        } catch (Exception $eSync) {
        }
        unset($GLOBALS['sas_warm_pages']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', t('saved') === 'Saved' ? 'Invalid request' : 'طلب غير صالح');
        redirect('settings.php?tab=' . $tab);
    }
    if ((string) post('section') === 'invoice_send') {
        require_once __DIR__ . '/../includes/invoice_studio.php';
        $invTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $slipSend = invoice_slip_agent_may($invTid, (string) post('slip_token', ''));
        if (!$slipSend) {
            flash('error', $lang === 'en' ? 'Invoice not found' : 'الفاتورة غير موجودة');
        } else {
            list($sentOk, $sentMsg) = invoice_slip_send_whatsapp($config, $slipSend);
            flash($sentOk ? 'success' : 'error', $sentMsg);
        }
        redirect('settings.php?tab=invoices');
    }
    if (in_array((string) post('section'), array('invoice_design', 'invoice_printer', 'invoice_seq'), true)) {
        require_once __DIR__ . '/../includes/invoice_studio.php';
        $invTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $invRes = invoice_apply_post($invTid);
        if ($invRes === 'added_off') {
            flash('error', $lang === 'en' ? 'Saved, printer did not answer' : 'انحفظت، الطابعة ما ردت');
        } else {
            flash('success', $lang === 'en' ? 'Saved' : 'تم الحفظ');
        }
        redirect('settings.php?tab=' . $tab);
    }

    if ((string) post('action') === 'save_dash_layout' && function_exists('dash_layout_save')) {
        $dashMe = function_exists('current_admin') ? current_admin() : null;
        $dashIds = isset($_POST['dash_id']) && is_array($_POST['dash_id']) ? $_POST['dash_id'] : array();
        $dashOns = isset($_POST['dash_on']) && is_array($_POST['dash_on']) ? $_POST['dash_on'] : array();
        $dashOnMap = array();
        foreach ($dashOns as $dashOnId) {
            $dashOnMap[(string) $dashOnId] = 1;
        }
        $dashTones = isset($_POST['dash_tone']) && is_array($_POST['dash_tone']) ? $_POST['dash_tone'] : array();
        $dashRows = array();
        foreach ($dashIds as $dashId) {
            $dashId = (string) $dashId;
            $dashRows[] = array(
                'id' => $dashId,
                'on' => isset($dashOnMap[$dashId]) ? 1 : 0,
                'tone' => isset($dashTones[$dashId]) ? (string) $dashTones[$dashId] : '',
            );
        }
        if ($dashMe && dash_layout_save($pdo, (int) $dashMe['id'], $dashRows)) {
            flash('success', $lang === 'en' ? 'Widget layout saved' : 'انحفظ ترتيب الوجدز');
        } else {
            flash('error', $lang === 'en' ? 'Could not save the widget layout' : 'ما انحفظ ترتيب الوجدز');
        }
        redirect('settings.php?tab=widgets');
    }

    $section = post('section', 'general');
    if (!$isPlatformAdmin && in_array($section, array('general', 'login_bg', 'whatsapp', 'add_user', 'save_user', 'delete_user'), true)) {
        flash('error', $lang === 'en' ? 'Platform admin only' : 'للمدير العام فقط');
        redirect('settings.php?tab=sas');
    }
    if ($section === 'sub_ping') {
        $pingMode = function_exists('sas_ping_mode_normalize')
            ? sas_ping_mode_normalize(post('sub_ping_mode', 'sas'))
            : 'off';
        $pingAccId = (int) post('account_id', 0);
        $pingTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
        $saved = array('ok' => false, 'mode' => 'off', 'message' => $lang === 'en' ? 'Add a reseller first' : 'أضف رسلر أولاً');
        if ($pingTid > 1 && $pingAccId > 0 && function_exists('sas_ping_mode_save_account')) {
            $saved = sas_ping_mode_save_account($pdo, $pingTid, $pingAccId, $pingMode);
        }
        if (!empty($saved['ok'])) {
            flash('success', $lang === 'en' ? 'Saved for this reseller' : 'انحفظ لهذا الرسلر');
        } else {
            flash('error', isset($saved['message']) ? $saved['message'] : ($lang === 'en' ? 'Could not save' : 'ما انحفظ'));
        }
        redirect('settings.php?tab=sas');
    }
    if ($section === 'user_prefs') {
        $periodMode = function_exists('subscription_period_normalize')
            ? subscription_period_normalize(post('subscription_period_mode', 'days_30'))
            : 'days_30';
        $prefLang = post('language') === 'en' ? 'en' : 'ar';
        $prefCur = trim((string) post('currency', 'د.ع'));
        if ($prefCur === '') {
            $prefCur = 'د.ع';
        }
        $prefGrace = max(0, (int) post('grace_days', '3'));
        if (function_exists('admin_ui_prefs_save')) {
            admin_ui_prefs_save($pdo, 'language', $prefLang);
            admin_ui_prefs_save($pdo, 'currency', $prefCur);
            admin_ui_prefs_save($pdo, 'grace_days', $prefGrace);
            admin_ui_prefs_save($pdo, 'subscription_period_mode', $periodMode);
        }
        if (function_exists('set_lang_preference')) {
            set_lang_preference($prefLang);
        }
        flash('success', $lang === 'en' ? 'Saved for your account only' : 'انحفظ لحسابك فقط');
        redirect('settings.php?tab=general');
    }
    if ($section === 'system_behavior') {
        $actAfter = post('activate_after') === 'stay' ? 'stay' : 'dashboard';
        if (function_exists('admin_ui_prefs_save')) {
            admin_ui_prefs_save($pdo, 'activate_after', $actAfter);
        }
        require_once __DIR__ . '/../includes/invoice_studio.php';
        $seqTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $seqMode = post('invoice_seq_mode') === 'month' ? 'month' : 'continuous';
        if (function_exists('invoice_slip_seq_set_mode')) {
            invoice_slip_seq_set_mode($seqTid, $seqMode);
        }
        flash('success', $lang === 'en' ? 'Saved' : 'تم الحفظ');
        redirect('settings.php?tab=behavior');
    }
    if ($section === 'ui_theme') {
        $themePick = function_exists('ui_theme_normalize')
            ? ui_theme_normalize(post('ui_theme', 'classic'))
            : ((post('ui_theme', 'classic') === 'glass') ? 'glass' : 'classic');
        if (function_exists('admin_ui_prefs_save')) {
            admin_ui_prefs_save($pdo, 'ui_theme', $themePick);
        }
        $meTheme = function_exists('current_admin') ? current_admin() : null;
        $meThemeId = $meTheme ? (int) $meTheme['id'] : 0;
        if (post('bg_clear') === '1' || post('glass_bg_remove') === '1') {
            if ($meThemeId > 0 && function_exists('ui_glass_bg_delete_files')) {
                ui_glass_bg_delete_files($meThemeId);
            }
            if (function_exists('admin_ui_prefs_save')) {
                admin_ui_prefs_save($pdo, 'glass_bg', '');
                admin_ui_prefs_save($pdo, 'app_bg_off', 1);
            }
            if (function_exists('user_can') && user_can('settings') && function_exists('settings_save')) {
                settings_save(array('bg_mode' => 'none', 'login_bg' => ''));
                if (function_exists('login_bg_delete_files')) {
                    login_bg_delete_files();
                }
            }
            flash('success', $lang === 'en' ? 'Background removed' : 'تم حذف الخلفية');
            redirect('settings.php?tab=theme');
        }
        if ($meThemeId > 0 && function_exists('ui_glass_bg_store_upload')) {
            if (!empty($_FILES['glass_bg_file']['tmp_name'])) {
                $bgFail = '';
                $savedBg = ui_glass_bg_store_upload($_FILES['glass_bg_file'], $meThemeId, $bgFail);
                if ($savedBg === false) {
                    $msg = $lang === 'en'
                        ? 'Background upload failed. Use JPG/PNG/GIF/WEBP up to 8MB.'
                        : 'فشل رفع الخلفية. استخدم JPG/PNG/GIF/WEBP بحد 8MB.';
                    if ($bgFail === 'writable' || $bgFail === 'mkdir') {
                        $msg = $lang === 'en'
                            ? 'Uploads folder is not writable (public/uploads).'
                            : 'مجلد الرفع غير قابل للكتابة (public/uploads).';
                    } elseif ($bgFail === 'size') {
                        $msg = $lang === 'en' ? 'Background image is too large (max 8MB).' : 'صورة الخلفية كبيرة (الحد 8MB).';
                    } elseif ($bgFail === 'type') {
                        $msg = $lang === 'en' ? 'Unsupported background image type.' : 'نوع صورة الخلفية غير مدعوم.';
                    }
                    flash('error', $msg);
                    redirect('settings.php?tab=theme');
                }
                if (function_exists('admin_ui_prefs_save')) {
                    admin_ui_prefs_save($pdo, 'glass_bg', $savedBg);
                    admin_ui_prefs_save($pdo, 'app_bg_off', 0);
                }
            }
        }
        flash('success', $lang === 'en' ? 'Theme saved for your account' : 'انحفظ الثيم لحسابك');
        redirect('settings.php?tab=theme');
    }
    $data = array();
    $skipSettingsSave = false;

    if ($section === 'wa_cover') {
        $skipSettingsSave = true;
        $tab = 'whatsapp';
        $meCover = function_exists('current_admin') ? current_admin() : null;
        $meCoverId = $meCover ? (int) $meCover['id'] : 0;
        if ($meCoverId <= 0) {
            flash('error', $lang === 'en' ? 'Not allowed' : 'غير مسموح');
            redirect('settings.php?tab=whatsapp');
        }
        if (function_exists('ensure_admin_users_table')) {
            ensure_admin_users_table($pdo);
        }
        $allowedCover = array();
        if (function_exists('whatsapp_cover_targets')) {
            foreach (whatsapp_cover_targets($pdo) as $tgt) {
                $allowedCover[(int) $tgt['id']] = true;
            }
        }
        $pickedCover = (isset($_POST['wa_cover']) && is_array($_POST['wa_cover'])) ? $_POST['wa_cover'] : array();
        $keepCover = array();
        foreach ($pickedCover as $pid) {
            $pid = (int) $pid;
            if ($pid > 0 && !empty($allowedCover[$pid])) {
                $keepCover[] = $pid;
            }
        }
        $aud = isset($_POST['wa_audience']) ? strtolower(trim((string) $_POST['wa_audience'])) : 'agents';
        if ($aud !== 'subscribers' && $aud !== 'agents' && $aud !== 'both') {
            $aud = 'agents';
        }
        try {
            $pdo->prepare('UPDATE admin_users SET wa_cover_ids = :c, wa_audience = :a WHERE id = :id')
                ->execute(array(
                    ':c' => implode(',', $keepCover),
                    ':a' => $aud,
                    ':id' => $meCoverId,
                ));
            flash('success', $lang === 'en' ? 'Saved' : 'تم الحفظ');
        } catch (Exception $e) {
            flash('error', $lang === 'en' ? 'Could not save' : 'ما انحفظ');
        }
        redirect('settings.php?tab=whatsapp');
    } elseif ($section === 'wa_notify') {
        $skipSettingsSave = true;
        $tab = 'whatsapp';
        if (function_exists('ensure_admin_users_table')) {
            ensure_admin_users_table($pdo);
        }
        $ownerNotify = function_exists('whatsapp_notify_owner_id') ? whatsapp_notify_owner_id($pdo) : 0;
        if ($ownerNotify <= 0) {
            flash('error', $lang === 'en' ? 'Not allowed' : 'غير مسموح');
            redirect('settings.php?tab=whatsapp');
        }
        $waOn = post('wa_notify') === '1' ? 1 : 0;
        try {
            $pdo->prepare('UPDATE admin_users SET wa_notify = :n WHERE id = :id')
                ->execute(array(':n' => $waOn, ':id' => $ownerNotify));
        } catch (Exception $e) {
            flash('error', $lang === 'en' ? 'Could not save' : 'ما انحفظ');
        }
        redirect('settings.php?tab=whatsapp');
    } elseif ($section === 'sas_test') {
        $skipSettingsSave = true;
        $tab = 'sas';
    } elseif ($section === 'system_power') {
        require_perm('settings');
        $skipSettingsSave = true;
        $tab = 'sensitive';
        $pass = (string) post('admin_password', '');
        $power = post('power_action', '');
        if (!settings_verify_wipe_password($pdo, $config, $pass)) {
            flash('error', $lang === 'en' ? 'Wrong password' : 'كلمة المرور غير صحيحة');
            redirect('settings.php?tab=sensitive');
        }
        if (!function_exists('system_power_action')) {
            flash('error', $lang === 'en' ? 'Power action unavailable' : 'أمر الطاقة غير متاح');
            redirect('settings.php?tab=sensitive');
        }
        list($ok, $msg) = system_power_action($power);
        flash($ok ? 'success' : 'error', $msg);
        redirect('settings.php?tab=sensitive');
    } elseif ($section === 'maintenance') {
        require_perm('settings');
        if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
            flash('error', $lang === 'en' ? 'Super admin only' : 'للمدير العام فقط');
            redirect('settings.php');
        }
        $data = array(
            'maint_block_activate' => post('maint_block_activate') === '1',
            'maint_block_give_test' => post('maint_block_give_test') === '1',
        );
        $tab = 'maintenance';
    } elseif ($section === 'app_update_upload') {
        require_perm('settings');
        $skipSettingsSave = true;
        $tab = 'update';
        list($ok, $msg) = app_update_store_upload(
            isset($_FILES['update_zip']) ? $_FILES['update_zip'] : array(),
            post('app_update_note', '')
        );
        flash($ok ? 'success' : 'error', $msg);
        redirect('settings.php?tab=update');
    } elseif ($section === 'app_update_apply') {
        require_perm('settings');
        $skipSettingsSave = true;
        $tab = 'update';
        $pending = app_update_pending(settings_load());
        if (!$pending) {
            flash('error', $lang === 'en' ? 'No pending update' : 'ماكو تحديث معلّق');
            redirect('settings.php?tab=update');
        }
        list($ok, $msg) = app_update_apply($pending['path']);
        if ($ok) {
            settings_save(array(
                'app_update_file' => '',
                'app_update_note' => '',
                'app_update_at' => '',
            ));
            @unlink($pending['path']);
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect('settings.php?tab=update');
    } elseif ($section === 'app_update_clear') {
        require_perm('settings');
        $skipSettingsSave = true;
        $tab = 'update';
        $pending = app_update_pending(settings_load());
        if ($pending && is_file($pending['path'])) {
            @unlink($pending['path']);
        }
        settings_save(array(
            'app_update_file' => '',
            'app_update_note' => '',
            'app_update_at' => '',
        ));
        flash('success', $lang === 'en' ? 'Pending update cleared' : 'تم إلغاء التحديث المعلّق');
        redirect('settings.php?tab=update');
    } elseif ($section === 'clear_data' || $section === 'clear_logs' || $section === 'clear_offline') {
        require_perm('clear_data');
        $pass = (string) post('admin_password', '');
        if (!settings_verify_wipe_password($pdo, $config, $pass)) {
            flash('error', $lang === 'en' ? 'Wrong password' : 'كلمة المرور غير صحيحة');
            redirect('settings.php?tab=sensitive');
        }
        try {
            if ($section === 'clear_logs') {
                $pdo->exec('TRUNCATE TABLE activity_logs');
                flash('success', $lang === 'en' ? 'Activity log cleared' : 'تم مسح اللوك');
            } elseif ($section === 'clear_offline') {
                settings_wipe_offline_tables($pdo);
                flash('success', $lang === 'en'
                    ? 'Local debts and subscriptions cleared. SAS users stay.'
                    : 'تم مسح الديون والاشتراكات المحلية. مشتركين SAS بقوا.');
            } else {
                settings_wipe_offline_tables($pdo);
                flash('success', $lang === 'en' ? 'All data cleared' : 'تم مسح كل البيانات');
            }
        } catch (Exception $e) {
            flash('error', 'Clear failed: ' . $e->getMessage());
        }
        redirect('settings.php?tab=sensitive');
    }

    if ($section === 'add_user') {
        require_perm('users');
        $addRole = normalize_admin_role(post('role', 'staff'));
        if ($addRole === 'agent' || $addRole === 'group_manager') {
            flash('error', $lang === 'en' ? 'SAS agents are added from Agents' : 'وكلاء الساس يضافون من صفحة الوكلاء');
            redirect('settings.php?tab=users');
        }
        if (!(function_exists('is_super_admin_user') && is_super_admin_user()) && $addRole === 'admin') {
            flash('error', $lang === 'en' ? 'Only the admin can create an admin' : 'إنشاء أدمن للمدير العام فقط');
            redirect('settings.php?tab=users');
        }
        $linkedAgent = ($addRole === 'accountant') ? (int) post('linked_agent_id', '0') : null;
        $res = create_admin_user(
            $pdo,
            post('username', ''),
            post('display_name', ''),
            post('password', ''),
            $addRole,
            $linkedAgent
        );
        if ($res === 'ok') {
            $holdId = (int) post('restore_hold_id', '0');
            if ($holdId > 0 && function_exists('user_hold_restore')) {
                $find = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u ORDER BY id DESC LIMIT 1');
                $find->execute(array(':u' => trim((string) post('username', ''))));
                $newId = (int) $find->fetchColumn();
                if ($newId > 0) {
                    user_hold_restore($pdo, $holdId, $newId);
                }
            }
            activity_log($pdo, null, 'system', null, 'user_add', 'إضافة مستخدم: ' . post('username'), post('role'));
            flash('success', $lang === 'en' ? 'User added' : 'تم إضافة المستخدم');
        } elseif ($res === 'taken') {
            flash('error', $lang === 'en' ? 'Username already used' : 'اسم الدخول مستخدم');
        } elseif ($res === 'username') {
            flash('error', $lang === 'en' ? 'Username: letters/numbers only' : 'اسم الدخول: حروف وأرقام فقط');
        } else {
            flash('error', $lang === 'en' ? 'Check the fields (password min 4)' : 'راجع الحقول (الرمز أقل شي 4)');
        }
        redirect('settings.php?tab=users');
    }

    if ($section === 'save_user') {
        require_perm('users');
        $uid = (int) post('user_id', '0');
        $display = trim((string) post('display_name', ''));
        $role = normalize_admin_role(post('role', 'staff'));
        $me = current_admin();
        if ($uid <= 0 || $display === '') {
            flash('error', $lang === 'en' ? 'Invalid data' : 'بيانات ناقصة');
            redirect('settings.php?tab=users');
        }
        $target = get_admin_user($pdo, $uid);
        if (!$target || !admin_user_in_manage_scope($pdo, $uid)) {
            flash('error', $lang === 'en' ? 'User not found' : 'المستخدم مو موجود');
            redirect('settings.php?tab=users');
        }
        if ($role === 'agent' || $role === 'group_manager') {
            flash('error', $lang === 'en' ? 'SAS agents are managed from Agents' : 'وكلاء الساس من صفحة الوكلاء');
            redirect('settings.php?tab=users');
        }
        if (!(function_exists('is_super_admin_user') && is_super_admin_user()) && $role === 'admin') {
            flash('error', $lang === 'en' ? 'Only the admin can assign the admin role' : 'صلاحية الأدمن يعدّلها الأدمن فقط');
            redirect('settings.php?tab=users');
        }
        $oldRole = normalize_admin_role(isset($target['role']) ? $target['role'] : 'staff');
        if ($oldRole === 'admin' && $role !== 'admin' && count_active_admins($pdo) <= 1) {
            flash('error', $lang === 'en' ? 'Keep at least one admin' : 'لازم يبقى مدير واحد على الأقل');
            redirect('settings.php?tab=users');
        }
        $linkedAgent = null;
        if ($role === 'accountant') {
            $linkedAgent = (int) post('linked_agent_id', '0');
        }
        update_admin_user_meta($pdo, $uid, $display, $role, $linkedAgent);
        if ($me && (int) $me['id'] === $uid) {
            $_SESSION['admin_display_name'] = $display;
            $_SESSION['admin_role'] = $role;
            if ($role === 'accountant') {
                $_SESSION['admin_linked_agent_id'] = $linkedAgent > 0 ? $linkedAgent : 0;
            } else {
                $_SESSION['admin_linked_agent_id'] = 0;
            }
        }
        $newPass = (string) post('new_password', '');
        if ($newPass !== '') {
            if (strlen($newPass) < 4) {
                flash('error', $lang === 'en' ? 'Password too short' : 'الرمز قصير');
                redirect('settings.php?tab=users');
            }
            change_user_password($pdo, $uid, $newPass);
        }
        activity_log($pdo, null, 'system', $uid, 'user_edit', 'تعديل مستخدم: ' . $target['username'], $role);
        flash('success', t('saved'));
        redirect('settings.php?tab=users');
    }

    if ($section === 'delete_user') {
        require_perm('users');
        $uid = (int) post('user_id', '0');
        $me = current_admin();
        if (!admin_user_in_manage_scope($pdo, $uid)) {
            flash('error', $lang === 'en' ? 'Not allowed' : 'ما عندك صلاحية على هذا المستخدم');
            redirect('settings.php?tab=users');
        }
        $targetDel = get_admin_user($pdo, $uid);
        $delRole = $targetDel ? normalize_admin_role(isset($targetDel['role']) ? $targetDel['role'] : '') : '';
        if ($delRole === 'agent' || $delRole === 'group_manager') {
            flash('error', $lang === 'en' ? 'Delete SAS agents from the Agents page' : 'حذف وكيل الساس من صفحة الوكلاء');
            redirect('settings.php?tab=users');
        }
        $subN = 0;
        try {
            $subSt = $pdo->prepare('SELECT COUNT(*) FROM subscribers WHERE agent_user_id = :a');
            $subSt->execute(array(':a' => $uid));
            $subN = (int) $subSt->fetchColumn();
        } catch (Exception $e) {
            $subN = 0;
        }
        if ($subN > 0 && function_exists('user_hold_apply_delete')) {
            list($okDel, $msgDel) = user_hold_apply_delete($pdo, $uid, $me ? $me['id'] : 0, 'owner');
            if ($okDel) {
                activity_log($pdo, null, 'system', $uid, 'user_delete', 'حذف مستخدم', 'owner');
            }
            flash($okDel ? 'success' : 'error', $msgDel);
        } else {
            $resDel = delete_admin_user($pdo, $uid, $me ? (int) $me['id'] : 0);
            if ($resDel === 'ok') {
                activity_log($pdo, null, 'system', $uid, 'user_delete', 'حذف مستخدم', '');
                flash('success', $lang === 'en' ? 'User deleted' : 'تم حذف المستخدم');
            } else {
                flash('error', $lang === 'en' ? 'Could not delete' : 'تعذر حذف المستخدم');
            }
        }
        redirect('settings.php?tab=users');
    }

    if ($section === 'general') {
        $data = array(
            'site_name' => (string) post('site_name', 'WiFi-Net-SALES'),
            'invoice_footer_name' => trim((string) post('invoice_footer_name', '')),
            'login_session_days' => max(1, min(30, (int) post('login_session_days', '3'))),
        );
        $tab = 'general';
    } elseif ($section === 'login_bg') {
        $tab = 'general';
        $color = trim((string) post('login_bg_color', '#1b2a38'));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#1b2a38';
        }
        $modePosted = (string) post('bg_mode', 'color');
        if ($modePosted !== 'image' && $modePosted !== 'none') {
            $modePosted = 'color';
        }
        if (post('bg_clear') === '1') {
            $modePosted = 'none';
        }
        $data = array(
            'login_bg_color' => $color,
            'bg_mode' => $modePosted,
        );
        if (function_exists('admin_ui_prefs_save')) {
            admin_ui_prefs_save($pdo, 'app_bg_off', $modePosted === 'none' ? 1 : 0);
        }
        if (post('login_bg_remove') === '1' || post('bg_clear') === '1') {
            login_bg_delete_files();
            $data['login_bg'] = '';
            $data['bg_mode'] = 'none';
        } elseif (!empty($_FILES['login_bg_file']['tmp_name'])) {
            $bgFail = '';
            $saved = login_bg_store_upload($_FILES['login_bg_file'], $bgFail);
            if ($saved === false) {
                $msg = $lang === 'en'
                    ? 'Background upload failed. Use JPG/PNG/GIF/WEBP up to 8MB.'
                    : 'فشل رفع الخلفية. استخدم JPG/PNG/GIF/WEBP بحد 8MB.';
                if ($bgFail === 'writable' || $bgFail === 'mkdir') {
                    $msg = $lang === 'en'
                        ? 'Uploads folder is not writable (public/uploads).'
                        : 'مجلد الرفع غير قابل للكتابة (public/uploads).';
                } elseif ($bgFail === 'size') {
                    $msg = $lang === 'en' ? 'Background image is too large (max 8MB).' : 'صورة الخلفية كبيرة (الحد 8MB).';
                } elseif ($bgFail === 'type') {
                    $msg = $lang === 'en' ? 'Unsupported background image type.' : 'نوع صورة الخلفية غير مدعوم.';
                }
                flash('error', $msg);
                redirect('settings.php?tab=general');
            }
            $data['login_bg'] = $saved;
            $data['bg_mode'] = 'image';
        }
        if (post('brand_icon_remove') === '1') {
            brand_icon_delete_files();
            $data['brand_icon'] = '';
        } elseif (!empty($_FILES['brand_icon_file']['tmp_name'])) {
            $icoFail = '';
            $savedIco = brand_icon_store_upload($_FILES['brand_icon_file'], $icoFail);
            if ($savedIco === false) {
                $msg = $lang === 'en'
                    ? 'Logo upload failed. Use JPG/PNG/GIF/WEBP up to 4MB.'
                    : 'فشل رفع الشعار. استخدم JPG/PNG/GIF/WEBP بحد 4MB.';
                if ($icoFail === 'writable' || $icoFail === 'mkdir') {
                    $msg = $lang === 'en'
                        ? 'Uploads folder is not writable (public/uploads).'
                        : 'مجلد الرفع غير قابل للكتابة (public/uploads).';
                } elseif ($icoFail === 'size') {
                    $msg = $lang === 'en' ? 'Logo is too large (max 4MB).' : 'الشعار كبير (الحد 4MB).';
                } elseif ($icoFail === 'type') {
                    $msg = $lang === 'en' ? 'Unsupported logo image type.' : 'نوع صورة الشعار غير مدعوم.';
                }
                flash('error', $msg);
                redirect('settings.php?tab=general');
            }
            $data['brand_icon'] = $savedIco;
        }
    } elseif ($section === 'rental_system') {
        $data = array(
            'rental_system_enabled' => post('rental_system_enabled') === '1',
        );
        $tab = 'rental';
    } elseif ($section === 'devices_system') {
        $data = array(
            'devices_system_enabled' => post('devices_system_enabled') === '1',
        );
        $tab = 'devices';
    } elseif ($section === 'rental_fee') {
        $fee = (float) post('rental_fee', '5000');
        if ($fee < 0) {
            $fee = 0;
        }
        $data = array('rental_fee' => $fee);
        $tab = 'rental';
    } elseif ($section === 'rental_device_save') {
        $cur = settings_load();
        $oldId = trim((string) post('device_old_id', ''));
        $name = trim((string) post('device_name', ''));
        $id = trim((string) post('device_id', ''));
        if ($id === '' && $name !== '') {
            $id = preg_replace('/\s+/', '-', strtolower($name));
            if ($id === '') {
                $id = 'dev' . time();
            }
        }
        $icon = trim((string) post('device_icon', ''));
        $color = trim((string) post('device_color', '#5e5ce6'));
        list($okDev, $devices) = rental_devices_replace_one($cur, $oldId, array(
            'id' => $id,
            'name' => $name,
            'icon' => $icon,
            'color' => $color,
        ));
        if (!$okDev) {
            flash('error', $lang === 'en' ? 'Name and code are required' : 'الاسم والمعرّف مطلوبان');
            redirect('settings.php?tab=rental&device=' . rawurlencode($oldId !== '' ? $oldId : 'new'));
        }
        $data = array('rental_devices' => $devices);
        $tab = 'rental';
        $_POST['_rental_device_redirect'] = $id;
    } elseif ($section === 'rental_device_delete') {
        $cur = settings_load();
        $delId = trim((string) post('device_id', ''));
        $data = array(
            'rental_devices' => rental_devices_remove_one($cur, $delId),
        );
        $tab = 'rental';
    } elseif ($section === 'whatsapp') {
        $url = trim((string) post('whatsapp_local_url', ''));
        $gwKey = trim((string) post('whatsapp_local_key', ''));
        if ($url === '' || $gwKey === '') {
            flash('error', $lang === 'en'
                ? 'Enter the gateway URL and key'
                : 'أدخل رابط البوابة ومفتاح البوابة');
            redirect('settings.php?tab=whatsapp');
        }
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $url = 'http://' . ltrim($url, '/');
        }
        if (post('whatsapp_enabled') !== '1' && post('whatsapp_enabled') !== '0') {
            flash('error', $lang === 'en' ? 'Choose WhatsApp on or off' : 'اختر تشغيل أو إيقاف واتساب');
            redirect('settings.php?tab=whatsapp');
        }
        $data = array(
            'whatsapp_enabled' => post('whatsapp_enabled') === '1',
            'whatsapp_provider' => 'local',
            'whatsapp_local_url' => $url,
            'whatsapp_local_key' => $gwKey,
            'whatsapp_sender_note' => '',
        );
        $tab = 'whatsapp';
    } elseif ($section === 'templates') {
        $data = array(
            'tpl_debt_remind' => (string) post('tpl_debt_remind', ''),
            'tpl_payment_ok' => (string) post('tpl_payment_ok', ''),
            'tpl_debt_created' => (string) post('tpl_debt_created', ''),
            'tpl_activation' => (string) post('tpl_activation', ''),
            'tpl_activation_credit' => (string) post('tpl_activation_credit', ''),
            'tpl_activation_debts' => (string) post('tpl_activation_debts', ''),
            'tpl_activation_credit_debts' => (string) post('tpl_activation_credit_debts', ''),
            'tpl_days_left' => (string) post('tpl_days_left', ''),
            'tpl_unpaid_overdue' => (string) post('tpl_unpaid_overdue', ''),
            'tpl_expiry_soon' => (string) post('tpl_expiry_soon', ''),
        );
        $tplAllowed = array(
            'activation', 'activation_credit', 'activation_debts', 'activation_credit_debts',
            'debt_created', 'payment_ok', 'debt_remind', 'days_left', 'unpaid_overdue', 'expiry_soon'
        );
        $caseKeys = array(
            'activation_cash', 'activation_credit', 'activation_debts', 'activation_credit_debts',
            'debt_created', 'payment_ok', 'debt_remind', 'reminder_auto', 'days_left', 'expiry_soon'
        );
        foreach ($caseKeys as $ck) {
            $v = trim((string) post('wa_case_' . $ck, ''));
            if (!in_array($v, $tplAllowed, true)) {
                if ($ck === 'activation_cash') {
                    $v = 'activation';
                } elseif ($ck === 'activation_credit') {
                    $v = 'activation_credit';
                } elseif ($ck === 'activation_debts') {
                    $v = 'activation_debts';
                } elseif ($ck === 'reminder_auto') {
                    $v = 'debt_remind';
                } else {
                    $v = $ck;
                }
                if (!in_array($v, $tplAllowed, true)) {
                    $v = 'activation';
                }
            }
            $data['wa_case_' . $ck] = $v;
        }
        if (settings_save($data)) {
            flash('success', t('saved'));
        } else {
            flash('error', 'Cannot write settings.json');
        }
        redirect('messages.php?mode=templates');
    } elseif ($section === 'sas') {
        $host = preg_replace('#^https?://#i', '', rtrim(trim((string) post('sas_host', '')), '/'));
        $pass = (string) post('sas_password', '');
        if ($pass === '') {
            $currSas = function_exists('sas_config') ? sas_config($config) : array();
            $pass = isset($currSas['password']) ? (string) $currSas['password'] : '';
        }
        $unitsRaw = trim((string) post('sas_activate_units', '1'));
        if ($unitsRaw === 'off' || $unitsRaw === 'disabled') {
            $units = 0;
        } else {
            $units = (int) $unitsRaw;
            if ($units < 0) {
                $units = 0;
            }
        }
        $parentId = (int) post('sas_parent_id', '0');
        $defPass = trim((string) post('sas_default_password', '1234'));
        if ($defPass === '') {
            $defPass = '1234';
        }
        // طريقة التست أصبحت عبر بروفايل التمديد فقط — نحتفظ بالقيمة الحالية إن وُجدت
        $currSettings = settings_load();
        $extendMethod = (isset($currSettings['sas_extend_method']) && $currSettings['sas_extend_method'] === 'credit')
            ? 'credit' : 'reward_points';
        $data = array(
            'sas_saved' => true,
            'sas_enabled' => post('sas_enabled') === '1',
            'sas_host' => $host,
            'sas_username' => trim((string) post('sas_username', '')),
            'sas_password' => $pass,
            'sas_parent_id' => $parentId > 0 ? $parentId : 1,
            'sas_default_password' => $defPass,
            'sas_activate_units' => $units,
            'sas_extend_method' => $extendMethod,
            'sas_extend_profile_id' => (int) post('sas_extend_profile_id', '0'),
            'sas_on_failure' => post('sas_on_failure') === 'rollback' ? 'rollback' : 'warn',
        );
        // محاولة جلب Parent ID من الساس إذا كان فارغاً أو 1 افتراضي والساس جاهز
        if (($parentId <= 0 || $parentId === 1) && !empty($data['sas_enabled']) && $host !== '' && $data['sas_username'] !== '' && $pass !== '') {
            $tmpCfg = $config;
            $tmpCfg['sas'] = array(
                'enabled' => true,
                'host' => $host,
                'username' => $data['sas_username'],
                'password' => $pass,
                'parent_id' => $parentId > 0 ? $parentId : 1,
                'default_password' => $defPass,
                'activate_units' => $units > 0 ? $units : 1,
                'extend_method' => $extendMethod,
                'extend_profile_id' => (int) $data['sas_extend_profile_id'],
                'on_failure' => $data['sas_on_failure'],
            );
            if (function_exists('sas_detect_parent_id')) {
                $detected = (int) sas_detect_parent_id($tmpCfg);
                if ($detected > 0) {
                    $data['sas_parent_id'] = $detected;
                }
            }
        }
        $tab = 'sas';
        // شركة غير 1: حسابات ريسيلر متعددة
        $tidSas = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        if ($tidSas > 1 && function_exists('tenant_sas_account_save')) {
            $accAction = post('action');
            if ($accAction === 'sas_otp_cancel') {
                $dismissId = 0;
                if (!empty($_SESSION['sas_link_otp']['edit_id'])) {
                    $dismissId = (int) $_SESSION['sas_link_otp']['edit_id'];
                }
                unset($_SESSION['sas_link_otp']);
                $_SESSION['sas_otp_dismissed'] = $dismissId;
                flash('info', $lang === 'en' ? 'Two-factor authentication is required' : 'يستوجب مصادقة ثنائية');
                redirect('settings.php?tab=sas');
            }
            if ($accAction === 'sas_otp_open') {
                $openId = (int) post('account_id', '0');
                $otpRow = ($openId > 0 && function_exists('tenant_sas_account_row'))
                    ? tenant_sas_account_row($pdo, $openId, $tidSas) : null;
                if ($otpRow) {
                    $_SESSION['sas_otp_dismissed'] = 0;
                    $_SESSION['sas_link_otp'] = array(
                        'fields' => array(
                            'label' => isset($otpRow['label']) ? (string) $otpRow['label'] : '',
                            'company_id' => !empty($otpRow['company_id']) ? (int) $otpRow['company_id'] : null,
                            'sas_enabled' => 1,
                            'sas_host' => (string) $otpRow['sas_host'],
                            'sas_username' => (string) $otpRow['sas_username'],
                            'sas_password' => (string) $otpRow['sas_password'],
                            'sas_parent_id' => isset($otpRow['sas_parent_id']) ? (int) $otpRow['sas_parent_id'] : 1,
                            'sas_default_password' => isset($otpRow['sas_default_password']) ? (string) $otpRow['sas_default_password'] : '1234',
                            'sas_activate_units' => isset($otpRow['sas_activate_units']) ? (int) $otpRow['sas_activate_units'] : 1,
                            'sas_extend_method' => isset($otpRow['sas_extend_method']) ? (string) $otpRow['sas_extend_method'] : 'reward_points',
                            'sas_extend_profile_id' => isset($otpRow['sas_extend_profile_id']) ? (int) $otpRow['sas_extend_profile_id'] : 0,
                            'sas_on_failure' => isset($otpRow['sas_on_failure']) ? (string) $otpRow['sas_on_failure'] : 'warn',
                            'is_default' => !empty($otpRow['is_default']),
                        ),
                        'edit_id' => $openId,
                        'provider' => 'sas',
                        'company_id' => !empty($otpRow['company_id']) ? (int) $otpRow['company_id'] : 0,
                        'error' => '',
                        'at' => time(),
                    );
                }
                redirect('settings.php?tab=sas&otp=1');
            }
            if ($accAction === 'sas_otp_confirm') {
                $pending = (isset($_SESSION['sas_link_otp']) && is_array($_SESSION['sas_link_otp']))
                    ? $_SESSION['sas_link_otp'] : null;
                $age = ($pending && !empty($pending['at'])) ? (time() - (int) $pending['at']) : 0;
                if ($pending && $age > 900) {
                    $pending['at'] = time();
                }
                if (!$pending || empty($pending['fields']) || !is_array($pending['fields'])) {
                    $reId = (int) post('account_id', '0');
                    $reRow = ($reId > 0 && function_exists('tenant_sas_account_row'))
                        ? tenant_sas_account_row($pdo, $reId, $tidSas) : null;
                    if (!$reRow) {
                        unset($_SESSION['sas_link_otp']);
                        flash('info', $lang === 'en' ? 'Two-factor authentication is required' : 'يستوجب مصادقة ثنائية');
                        redirect('settings.php?tab=sas');
                    }
                    $pending = array(
                        'fields' => array(
                            'sas_host' => (string) $reRow['sas_host'],
                            'sas_username' => (string) $reRow['sas_username'],
                            'sas_password' => (string) $reRow['sas_password'],
                            'label' => isset($reRow['label']) ? (string) $reRow['label'] : '',
                            'company_id' => !empty($reRow['company_id']) ? (int) $reRow['company_id'] : null,
                            'sas_enabled' => 1,
                            'sas_parent_id' => isset($reRow['sas_parent_id']) ? (int) $reRow['sas_parent_id'] : 1,
                            'sas_default_password' => isset($reRow['sas_default_password']) ? (string) $reRow['sas_default_password'] : '1234',
                            'sas_activate_units' => isset($reRow['sas_activate_units']) ? (int) $reRow['sas_activate_units'] : 1,
                            'sas_extend_method' => isset($reRow['sas_extend_method']) ? (string) $reRow['sas_extend_method'] : 'reward_points',
                            'sas_extend_profile_id' => isset($reRow['sas_extend_profile_id']) ? (int) $reRow['sas_extend_profile_id'] : 0,
                            'sas_on_failure' => isset($reRow['sas_on_failure']) ? (string) $reRow['sas_on_failure'] : 'warn',
                            'is_default' => !empty($reRow['is_default']),
                        ),
                        'edit_id' => $reId,
                        'provider' => 'sas',
                        'company_id' => !empty($reRow['company_id']) ? (int) $reRow['company_id'] : 0,
                    );
                }
                $code = preg_replace('/\D+/', '', (string) post('sas_otp', ''));
                if (strlen($code) < 4) {
                    $pending['error'] = 'short';
                    $_SESSION['sas_link_otp'] = $pending;
                    redirect('settings.php?tab=sas&otp=1');
                }
                $fieldsAcc = $pending['fields'];
                list($okT, $msgT) = tenant_test_sas_connection(
                    $fieldsAcc['sas_host'],
                    $fieldsAcc['sas_username'],
                    $fieldsAcc['sas_password'],
                    $code
                );
                if (!$okT) {
                    $pending['error'] = ($msgT === 'OTP_INVALID' || $msgT === 'OTP_REQUIRED') ? 'bad' : (string) $msgT;
                    $pending['at'] = time();
                    $_SESSION['sas_link_otp'] = $pending;
                    redirect('settings.php?tab=sas&otp=1');
                }
                $editId = isset($pending['edit_id']) ? (int) $pending['edit_id'] : 0;
                $coProvider = isset($pending['provider']) ? (string) $pending['provider'] : 'sas';
                $companyId = isset($pending['company_id']) ? (int) $pending['company_id'] : 0;
                unset($_SESSION['sas_link_otp']);
                list($okS, $msgS, $newId) = tenant_sas_account_save($pdo, $tidSas, $fieldsAcc, $editId);
                if ($okS) {
                    if ($coProvider !== 'earthlink' && function_exists('sas_mark_connection')) {
                        sas_mark_connection($pdo, $config, true, $msgT, $tidSas);
                    }
                    $accIdMark = (int) $newId > 0 ? (int) $newId : $editId;
                    if ($accIdMark > 0) {
                        try {
                            $pdo->prepare(
                                'UPDATE tenant_sas_accounts
                                 SET last_ok_at = NOW(), last_error = NULL,
                                     login_fail_count = 0, login_next_retry_at = NULL
                                 WHERE id = :id AND tenant_id = :t'
                            )->execute(array(':id' => $accIdMark, ':t' => $tidSas));
                        } catch (Exception $eMark) {
                        }
                    }
                    if ($coProvider !== 'earthlink' && function_exists('sas_sync_request_now')) {
                        sas_sync_request_now($tidSas);
                    }
                    flash('success', $lang === 'en'
                        ? 'Saved — connected. Subscribers are downloading'
                        : 'تم الحفظ — متصل. المشتركين يبدون ينزلون');
                } else {
                    flash('error', $msgS);
                }
                redirect('settings.php?tab=sas');
            }
            if ($accAction === 'delete_account') {
                $delId = (int) post('account_id', '0');
                list($okD, $msgD) = tenant_sas_account_delete($pdo, $tidSas, $delId);
                flash($okD ? 'success' : 'error', $msgD);
                redirect('settings.php?tab=sas');
            }
            if ($accAction === 'logout_account' && function_exists('tenant_sas_account_logout')) {
                $outId = (int) post('account_id', '0');
                list($okL, $msgL) = tenant_sas_account_logout($pdo, $tidSas, $outId);
                flash($okL ? 'success' : 'error', $msgL);
                redirect('settings.php?tab=sas');
            }
            if ($accAction === 'retry_login' && function_exists('tenant_sas_account_retry_login')) {
                $retryId = (int) post('account_id', '0');
                if (function_exists('set_time_limit')) {
                    @set_time_limit(45);
                }
                list($okR, $msgR, $metaR) = array_pad(
                    tenant_sas_account_retry_login($pdo, $tidSas, $retryId, $config),
                    3,
                    array()
                );
                if ($okR) {
                    flash('success', ($lang === 'en' ? 'Connected: ' : 'تم الدخول: ') . $msgR);
                } elseif ($msgR === 'OTP_REQUIRED' || $msgR === 'OTP_INVALID') {
                    $otpRow = function_exists('tenant_sas_account_row')
                        ? tenant_sas_account_row($pdo, $retryId, $tidSas) : null;
                    if ($otpRow) {
                        $_SESSION['sas_link_otp'] = array(
                            'fields' => array(
                                'label' => isset($otpRow['label']) ? (string) $otpRow['label'] : '',
                                'company_id' => !empty($otpRow['company_id']) ? (int) $otpRow['company_id'] : null,
                                'sas_enabled' => 1,
                                'sas_host' => (string) $otpRow['sas_host'],
                                'sas_username' => (string) $otpRow['sas_username'],
                                'sas_password' => (string) $otpRow['sas_password'],
                                'sas_parent_id' => isset($otpRow['sas_parent_id']) ? (int) $otpRow['sas_parent_id'] : 1,
                                'sas_default_password' => isset($otpRow['sas_default_password']) ? (string) $otpRow['sas_default_password'] : '1234',
                                'sas_activate_units' => isset($otpRow['sas_activate_units']) ? (int) $otpRow['sas_activate_units'] : 1,
                                'sas_extend_method' => isset($otpRow['sas_extend_method']) ? (string) $otpRow['sas_extend_method'] : 'reward_points',
                                'sas_extend_profile_id' => isset($otpRow['sas_extend_profile_id']) ? (int) $otpRow['sas_extend_profile_id'] : 0,
                                'sas_on_failure' => isset($otpRow['sas_on_failure']) ? (string) $otpRow['sas_on_failure'] : 'warn',
                                'is_default' => !empty($otpRow['is_default']),
                            ),
                            'edit_id' => $retryId,
                            'provider' => 'sas',
                            'company_id' => !empty($otpRow['company_id']) ? (int) $otpRow['company_id'] : 0,
                            'error' => '',
                            'at' => time(),
                        );
                    }
                    redirect('settings.php?tab=sas&otp=1');
                } else {
                    $extra = '';
                    if (is_array($metaR) && !empty($metaR['next_retry_sec'])) {
                        $mins = max(1, (int) ceil(((int) $metaR['next_retry_sec']) / 60));
                        $extra = $lang === 'en'
                            ? (' — auto-retry in ~' . $mins . ' min')
                            : (' — إعادة تلقائية بعد ~' . $mins . ' د');
                    }
                    flash('error', ($lang === 'en' ? 'Retry failed: ' : 'فشلت إعادة المحاولة: ') . $msgR . $extra);
                }
                redirect('settings.php?tab=sas');
            }
            if ($accAction === 'set_default') {
                $defId = (int) post('account_id', '0');
                flash(tenant_sas_account_set_default($pdo, $tidSas, $defId) ? 'success' : 'error',
                    $lang === 'en' ? 'Default updated' : 'تم تعيين الافتراضي');
                redirect('settings.php?tab=sas');
            }
            $companyId = (int) post('sas_company_id', '0');
            $postedHost = trim((string) post('sas_host', ''));
            if ($postedHost !== '') {
                $data['sas_host'] = preg_replace('#^https?://#i', '', rtrim($postedHost, '/'));
                $host = $data['sas_host'];
            } elseif ($companyId > 0 && function_exists('tenant_company_sas_host')) {
                $hostFromCo = tenant_company_sas_host($pdo, $companyId, $config);
                if ($hostFromCo === '') {
                    flash('error', $lang === 'en' ? 'Selected company has no SAS host' : 'الشركة المختارة ما عندها هوست ساس');
                    redirect('settings.php?tab=sas');
                }
                $data['sas_host'] = $hostFromCo;
                $host = $hostFromCo;
            } elseif ($host === '') {
                flash('error', $lang === 'en' ? 'Choose a company first' : 'اختار الشركة أولاً');
                redirect('settings.php?tab=sas');
            }
            $editId = (int) post('account_id', '0');
            $label = trim((string) post('account_label', ''));
            $fieldsAcc = array(
                'label' => $label,
                'company_id' => $companyId > 0 ? $companyId : null,
                'sas_enabled' => 1,
                'sas_host' => $data['sas_host'],
                'sas_username' => $data['sas_username'],
                'sas_password' => $pass,
                'sas_parent_id' => $data['sas_parent_id'],
                'sas_default_password' => $data['sas_default_password'],
                'sas_activate_units' => $data['sas_activate_units'] > 0 ? $data['sas_activate_units'] : 1,
                'sas_extend_method' => $data['sas_extend_method'],
                'sas_extend_profile_id' => $data['sas_extend_profile_id'],
                'sas_on_failure' => $data['sas_on_failure'],
                'is_default' => post('is_default') === '1',
                'service_kind' => post('service_kind') === 'ftth' ? 'ftth' : 'wireless',
            );
            // اكتشاف Parent
            $passKeep = $pass;
            if ($passKeep === '' && $editId > 0) {
                $prevA = tenant_sas_account_row($pdo, $editId, $tidSas);
                $passKeep = $prevA ? (string) $prevA['sas_password'] : '';
            }
            if ($data['sas_username'] === '' || ($passKeep === '' && $editId <= 0)) {
                flash('error', $lang === 'en' ? 'Reseller SAS username and password required' : 'يوزر وباسورد وكالة الساس مطلوبين');
                redirect('settings.php?tab=sas');
            }
            if (($fieldsAcc['sas_parent_id'] <= 0 || (int) $fieldsAcc['sas_parent_id'] === 1)
                && $passKeep !== '' && function_exists('sas_detect_parent_id')
            ) {
                $tmpCfg2 = $config;
                $tmpCfg2['sas'] = array(
                    'enabled' => true,
                    'host' => $fieldsAcc['sas_host'],
                    'username' => $fieldsAcc['sas_username'],
                    'password' => $passKeep,
                    'parent_id' => 1,
                    'default_password' => $fieldsAcc['sas_default_password'],
                    'activate_units' => max(1, (int) $fieldsAcc['sas_activate_units']),
                    'extend_method' => $fieldsAcc['sas_extend_method'],
                    'extend_profile_id' => (int) $fieldsAcc['sas_extend_profile_id'],
                    'on_failure' => $fieldsAcc['sas_on_failure'],
                );
                $detected2 = (int) sas_detect_parent_id($tmpCfg2);
                if ($detected2 > 0) {
                    $fieldsAcc['sas_parent_id'] = $detected2;
                }
            }
            $fieldsAcc['sas_password'] = $passKeep !== '' ? $passKeep : $pass;
            $coProvider = 'sas';
            if ($companyId > 0 && function_exists('platform_company_row')) {
                $coRowLink = platform_company_row($pdo, $companyId);
                if ($coRowLink && function_exists('platform_company_normalize_provider')) {
                    $coProvider = platform_company_normalize_provider(
                        isset($coRowLink['provider_type']) ? $coRowLink['provider_type'] : 'sas'
                    );
                }
            }
            if ($coProvider === 'earthlink' && function_exists('earthlink_test_connection')) {
                $elAlt = function_exists('earthlink_company_alt_host')
                    ? earthlink_company_alt_host($pdo, $companyId)
                    : '';
                $elTest = earthlink_test_connection(
                    $fieldsAcc['sas_host'],
                    $fieldsAcc['sas_username'],
                    $fieldsAcc['sas_password'],
                    $elAlt
                );
                $okT = !empty($elTest['ok']);
                $msgT = isset($elTest['message']) ? (string) $elTest['message'] : '';
            } else {
                list($okT, $msgT) = tenant_test_sas_connection(
                    $fieldsAcc['sas_host'],
                    $fieldsAcc['sas_username'],
                    $fieldsAcc['sas_password']
                );
            }
            if (!$okT) {
                if ($msgT === 'OTP_REQUIRED' || $msgT === 'OTP_INVALID') {
                    $_SESSION['sas_link_otp'] = array(
                        'fields' => $fieldsAcc,
                        'edit_id' => $editId,
                        'provider' => $coProvider,
                        'company_id' => $companyId,
                        'error' => '',
                        'at' => time(),
                    );
                    redirect('settings.php?tab=sas&otp=1');
                }
                flash('error', ($lang === 'en' ? 'Not added. Login failed: ' : 'ما انضاف. فشل الدخول: ') . $msgT);
                redirect('settings.php?tab=sas');
            }
            list($okS, $msgS, $newId) = tenant_sas_account_save($pdo, $tidSas, $fieldsAcc, $editId);
            if ($okS) {
                if ($coProvider === 'earthlink' && function_exists('earthlink_sync_to_cache')) {
                    $syncEl = earthlink_sync_to_cache(
                        $pdo,
                        $tidSas,
                        $fieldsAcc['sas_host'],
                        $fieldsAcc['sas_username'],
                        $fieldsAcc['sas_password'],
                        $companyId
                    );
                    if (!empty($syncEl['ok'])) {
                        $msgT .= ' — ' . (isset($syncEl['message']) ? $syncEl['message'] : '');
                    } else {
                        $msgT .= ' — ' . (isset($syncEl['message']) ? $syncEl['message'] : 'فشل جلب المشتركين');
                    }
                }
                // بانر/حالة «الساس» فقط لحسابات SAS — إيرث لنك ما يوقف الساس
                if ($coProvider !== 'earthlink' && function_exists('sas_mark_connection')) {
                    sas_mark_connection($pdo, $config, $okT, $msgT, $tidSas);
                }
                $accIdMark = (int) $newId > 0 ? (int) $newId : $editId;
                if ($accIdMark > 0) {
                    try {
                        if ($okT) {
                            $pdo->prepare(
                                'UPDATE tenant_sas_accounts
                                 SET last_ok_at = NOW(), last_error = NULL,
                                     login_fail_count = 0, login_next_retry_at = NULL
                                 WHERE id = :id AND tenant_id = :t'
                            )->execute(array(':id' => $accIdMark, ':t' => $tidSas));
                        } else {
                            $nextFail = 1;
                            $nextAt = function_exists('tenant_sas_login_retry_schedule_sql')
                                ? tenant_sas_login_retry_schedule_sql($nextFail)
                                : date('Y-m-d H:i:s', time() + 60);
                            $pdo->prepare(
                                'UPDATE tenant_sas_accounts
                                 SET last_ok_at = NULL, last_error = :e,
                                     login_fail_count = :fc, login_next_retry_at = :nr
                                 WHERE id = :id AND tenant_id = :t'
                            )->execute(array(
                                ':e' => function_exists('mb_substr') ? mb_substr((string) $msgT, 0, 250, 'UTF-8') : substr((string) $msgT, 0, 250),
                                ':fc' => $nextFail,
                                ':nr' => $nextAt,
                                ':id' => $accIdMark,
                                ':t' => $tidSas,
                            ));
                        }
                    } catch (Exception $eMark) {
                    }
                }
                if ($okT && $coProvider !== 'earthlink') {
                    if (!function_exists('sas_sync_request_now')) {
                        $warmFile = dirname(__DIR__) . '/includes/portal_warm.php';
                        if (is_file($warmFile)) {
                            require_once $warmFile;
                        }
                    }
                    if (function_exists('sas_sync_request_now')) {
                        sas_sync_request_now($tidSas);
                    }
                }
                if ($accAction === 'test' || post('sas_test') === '1') {
                    flash($okT ? 'success' : 'error', $okT
                        ? (($lang === 'en' ? 'Connected — subscribers are downloading' : 'متصل — المشتركين يبدون ينزلون') . ($msgT !== '' ? ': ' . $msgT : ''))
                        : $msgT);
                } else {
                    flash($okT ? 'success' : 'error', $okT
                        ? (($lang === 'en' ? 'Saved — connected. Subscribers are downloading' : 'تم الحفظ — متصل. المشتركين يبدون ينزلون') . ($msgT !== '' ? ': ' . $msgT : ''))
                        : (($lang === 'en' ? 'Saved but not connected: ' : 'تم الحفظ لكن غير متصل: ') . $msgT));
                }
            } else {
                flash('error', $msgS);
            }
            redirect('settings.php?tab=sas');
        }
        if ($tidSas > 1 && function_exists('tenant_save')) {
            // توافق قديم إن ملف الحسابات غير موجود
            $companyId = (int) post('sas_company_id', '0');
            if ($companyId > 0 && function_exists('tenant_company_sas_host')) {
                $hostFromCo = tenant_company_sas_host($pdo, $companyId, $config);
                if ($hostFromCo === '') {
                    flash('error', $lang === 'en' ? 'Selected company has no SAS host' : 'الشركة المختارة ما عندها هوست ساس');
                    redirect('settings.php?tab=sas');
                }
                $data['sas_host'] = $hostFromCo;
                $host = $hostFromCo;
            } elseif ($host === '') {
                flash('error', $lang === 'en' ? 'Choose a company first' : 'اختار الشركة أولاً');
                redirect('settings.php?tab=sas');
            }
            if ($data['sas_username'] !== '' && $pass !== '') {
                $data['sas_enabled'] = true;
            }
            $passKeep = $pass;
            if ($passKeep === '') {
                $rowKeep = function_exists('tenant_row') ? tenant_row($pdo, $tidSas) : null;
                $passKeep = $rowKeep && !empty($rowKeep['sas_password']) ? (string) $rowKeep['sas_password'] : '';
            }
            if ($data['sas_username'] === '' || $passKeep === '') {
                flash('error', $lang === 'en' ? 'Agency SAS username and password required' : 'يوزر وباسورد وكالة الساس مطلوبين');
                redirect('settings.php?tab=sas');
            }
            $tenantData = array(
                'sas_enabled' => !empty($data['sas_enabled']) ? 1 : 0,
                'sas_host' => $data['sas_host'],
                'sas_username' => $data['sas_username'],
                'sas_password' => $passKeep,
                'sas_parent_id' => $data['sas_parent_id'],
                'sas_default_password' => $data['sas_default_password'],
                'sas_activate_units' => $data['sas_activate_units'] > 0 ? $data['sas_activate_units'] : 1,
                'sas_extend_method' => $data['sas_extend_method'],
                'sas_extend_profile_id' => $data['sas_extend_profile_id'],
                'sas_on_failure' => $data['sas_on_failure'],
                'sas_company_id' => $companyId > 0 ? $companyId : null,
            );
            if (tenant_save($pdo, $tidSas, $tenantData)) {
                list($okT, $msgT) = tenant_test_sas_connection(
                    $tenantData['sas_host'],
                    $tenantData['sas_username'],
                    $passKeep
                );
                if (function_exists('sas_mark_connection')) {
                    sas_mark_connection($pdo, $config, $okT, $msgT, $tidSas);
                }
                if ($okT) {
                    if (!function_exists('sas_sync_request_now')) {
                        $warmFile = dirname(__DIR__) . '/includes/portal_warm.php';
                        if (is_file($warmFile)) {
                            require_once $warmFile;
                        }
                    }
                    if (function_exists('sas_sync_request_now')) {
                        sas_sync_request_now($tidSas);
                    }
                }
                flash($okT ? 'success' : 'error', $okT
                    ? (($lang === 'en' ? 'Saved — connected. Subscribers are downloading' : 'تم الحفظ — متصل. المشتركين يبدون ينزلون') . ($msgT !== '' ? ': ' . $msgT : ''))
                    : (($lang === 'en' ? 'Saved but not connected: ' : 'تم الحفظ لكن غير متصل: ') . $msgT));
            } else {
                flash('error', $lang === 'en' ? 'Save failed' : 'فشل الحفظ');
            }
            redirect('settings.php?tab=sas');
        }
    } elseif ($section === 'cpe') {
        $data = array(
            'cpe_http_user' => trim((string) post('cpe_http_user', 'ubnt')),
            'cpe_http_pass' => (string) post('cpe_http_pass', 'ubnt'),
            'cpe_use_https' => post('cpe_use_https') === '1',
        );
        $tab = 'rental';
    } elseif ($section === 'saas') {
        if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
            flash('error', $lang === 'en' ? 'Super admin only' : 'للمدير العام فقط');
            redirect('settings.php');
        }
        $planDefs = array(
            'monthly' => array('شهري', 30, 25000),
            'quarter' => array('3 أشهر', 90, 70000),
            'half' => array('6 أشهر', 180, 130000),
            'yearly' => array('سنوي', 365, 250000),
        );
        $plans = array();
        foreach ($planDefs as $code => $def) {
            $price = max(0, (float) post('plan_' . $code . '_amount', (string) $def[2]));
            $discount = max(0, (float) post('plan_' . $code . '_discount', '0'));
            if ($discount > $price) {
                $discount = $price;
            }
            $plans[$code] = array(
                'label' => trim((string) post('plan_' . $code . '_label', $def[0])),
                'days' => max(1, (int) post('plan_' . $code . '_days', (string) $def[1])),
                'amount' => $price,
                'discount' => $discount,
            );
        }
        $secret = (string) post('zaincash_secret', '');
        $data = array(
            'saas_registration_enabled' => post('saas_registration_enabled') === '1',
            'saas_trial_days' => max(0, (int) post('saas_trial_days', '7')),
            'saas_plans' => $plans,
            'zaincash_merchant_id' => trim((string) post('zaincash_merchant_id', '')),
            'zaincash_msisdn' => trim((string) post('zaincash_msisdn', '')),
            'zaincash_production' => post('zaincash_production') === '1',
            'zaincash_redirect_base' => rtrim(trim((string) post('zaincash_redirect_base', '')), '/'),
        );
        if ($secret !== '') {
            $data['zaincash_secret'] = $secret;
        }
        $tab = 'saas';
    } elseif ($section === 'schedule') {
        $data = array(
            'schedule_cut_enabled' => post('schedule_cut_enabled') === '1',
            'schedule_cut_send_wa' => post('schedule_cut_send_wa') === '1',
            'tpl_schedule_cut' => (string) post('tpl_schedule_cut', ''),
            'wa_case_schedule_cut' => 'schedule_cut',
        );
        if (function_exists('wa_patch_catalog_body')) {
            $patched = wa_patch_catalog_body(settings_load(), 'schedule_cut', $data['tpl_schedule_cut']);
            if (!empty($patched['wa_templates']) && is_array($patched['wa_templates'])) {
                $data['wa_templates'] = $patched['wa_templates'];
            }
        }
        $tab = 'schedule';
        if (function_exists('schedule_settings_save') && function_exists('current_tenant_id')) {
            schedule_settings_save($pdo, (int) current_tenant_id(), array(
                'schedule_cut_enabled' => !empty($data['schedule_cut_enabled']),
                'schedule_cut_send_wa' => !empty($data['schedule_cut_send_wa']),
                'wa_case_schedule_cut' => 'schedule_cut',
            ));
            unset($data['schedule_cut_enabled'], $data['schedule_cut_send_wa'], $data['wa_case_schedule_cut']);
        }
        if (post('schedule_run_now') === '1' && function_exists('run_schedule_debt_cuts')) {
            if (function_exists('schedule_settings_for_tenant') && function_exists('schedule_config_with_tenant')) {
                $config = schedule_config_with_tenant(
                    $config,
                    schedule_settings_for_tenant($pdo, function_exists('current_tenant_id') ? (int) current_tenant_id() : 1)
                );
            }
            if (!empty($config['schedule_cut_enabled'])) {
                $run = run_schedule_debt_cuts($pdo, $config, 100);
                $msg = 'تشغيل: فحص ' . (int) $run['checked']
                    . ' — قطع ' . (int) $run['cut']
                    . ' — واتساب ' . (int) $run['wa_sent'];
                flash('success', $msg);
            } else {
                flash('error', 'فعّل القطع التلقائي أولاً');
            }
            redirect('settings.php?tab=schedule');
        }
    } elseif (!$skipSettingsSave) {
        flash('error', 'Unknown section');
        redirect('settings.php');
    }

    if (!$skipSettingsSave) {
        if (settings_save($data)) {
            if ($section === 'sas' && function_exists('tenant_sync_from_settings') && isset($pdo)) {
                try {
                    tenant_sync_from_settings($pdo, $data, function_exists('current_tenant_id') ? current_tenant_id() : 1);
                } catch (Exception $e) {
                }
            }
            flash('success', t('saved'));
        } else {
            flash('error', 'Cannot write settings.json');
        }
        if ($section === 'rental_device_save' && !empty($_POST['_rental_device_redirect'])) {
            redirect('settings.php?tab=rental&device=' . rawurlencode((string) $_POST['_rental_device_redirect']));
        }
        if ($section === 'rental_device_delete') {
            redirect('settings.php?tab=rental');
        }
        redirect('settings.php?tab=' . $tab);
    }
}

$s = settings_load();
$activeNav = 'settings';
if ($tab === 'whatsapp') {
    $activeNav = 'whatsapp';
} elseif ($tab === 'users') {
    $activeNav = 'users';
} elseif ($tab === 'invoices' || $tab === 'printers') {
    $activeNav = 'invoices';
}

$sasCfgUi = function_exists('sas_config') ? sas_config($config) : array();
if (!is_array($sasCfgUi)) {
    $sasCfgUi = array();
}
$sasTenantIdUi = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
if ($sasTenantIdUi > 1 && function_exists('sas_config_for_tenant') && isset($pdo)) {
    $sasCfgUi = sas_config_for_tenant($pdo, $config, $sasTenantIdUi);
}
$sasConnStatus = null;
if ($tab === 'sas' && function_exists('sas_connection_status') && isset($pdo)) {
    $sasConnStatus = sas_connection_status($pdo, $config, $sasTenantIdUi);
}
$sasTestOk = null;
$sasTestMsg = '';
$sasProfiles = array();
$sasManagers = array();
$sasPlans = array();
$sasLoadError = '';
$sasRewardPoints = null;
if ($tab === 'sas') {
    try {
        if (function_exists('ensure_sas_columns')) {
            ensure_sas_columns($pdo);
        }
        $hasSasCol = false;
        try {
            $hasSasCol = (bool) $pdo->query("SHOW COLUMNS FROM service_plans LIKE 'sas_profile_id'")->fetch();
        } catch (Exception $eCol) {
            $hasSasCol = false;
        }
        if ($hasSasCol) {
            $sasPlans = $pdo->query('SELECT id, name, sas_profile_id FROM service_plans ORDER BY id ASC')->fetchAll();
        } else {
            $sasPlans = $pdo->query('SELECT id, name FROM service_plans ORDER BY id ASC')->fetchAll();
        }
        if (!is_array($sasPlans)) {
            $sasPlans = array();
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('section') === 'sas_test' && function_exists('sas_test_connection')) {
            $tres = sas_test_connection($config);
            $sasTestOk = !empty($tres['ok']);
            $sasTestMsg = isset($tres['message']) ? $tres['message'] : '';
            $sasProfiles = isset($tres['profiles']) && is_array($tres['profiles']) ? $tres['profiles'] : array();
            $sasManagers = isset($tres['managers']) && is_array($tres['managers']) ? $tres['managers'] : array();
            $sasRewardPoints = array_key_exists('reward_points', $tres) ? $tres['reward_points'] : null;
        }
    } catch (Exception $e) {
        $sasPlans = array();
        $sasLoadError = $e->getMessage();
    }
}
$rentalDevices = rental_devices_list($s);
$rentalFee = rental_fee_amount($s);
$rentalSystemOn = function_exists('rental_system_is_on') ? rental_system_is_on($s) : true;
$rentalDeviceEdit = isset($_GET['device']) ? trim((string) $_GET['device']) : '';
$rentalEditRow = null;
$rentalIsNew = ($rentalDeviceEdit === 'new');
if ($rentalDeviceEdit !== '' && !$rentalIsNew) {
    $rentalEditRow = rental_device_by_id($rentalDeviceEdit, $s);
    if (!$rentalEditRow) {
        $rentalDeviceEdit = '';
    }
}
$hostHint = parse_url(isset($s['whatsapp_local_url']) ? $s['whatsapp_local_url'] : '', PHP_URL_HOST);
if (!$hostHint) {
    $hostHint = '172.16.16.13';
}
$adminUsers = list_admin_users($pdo);
$settingsAgents = list_agent_users($pdo, false);
$me = current_admin();

if (($tab === 'invoices' || $tab === 'printers') && isset($_GET['tpl'])) {
    require_once __DIR__ . '/../includes/invoice_studio.php';
    $invTidPick = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    invoice_library_active($invTidPick, (string) $_GET['tpl']);
    redirect('settings.php?tab=invoices');
}
render_header(($tab === 'invoices' || $tab === 'printers') ? ($lang === 'en' ? 'Invoices' : 'الفواتير') : t('settings'), $activeNav);
if ($tab !== 'invoices' && $tab !== 'printers') {
    render_settings_tabs($tab);
}
if ($tab === 'invoices' || $tab === 'printers') {
    require_once __DIR__ . '/../includes/invoice_studio.php';
    $invTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    invoice_render_tab($tab, $invTid, isset($lang) && $lang === 'en');
}

$uiThemeNow = function_exists('ui_theme_current') ? ui_theme_current($pdo) : 'classic';
$uiThemeNow = ($uiThemeNow === 'glass') ? 'glass' : 'classic';
$showThemePanel = ($tab === 'theme');
$glassBgNow = function_exists('ui_glass_bg_url') ? ui_glass_bg_url($pdo, isset($settings) ? $settings : null) : '';
$glassBgPersonal = '';
$userWorkPrefs = function_exists('admin_ui_prefs_load') ? admin_ui_prefs_load($pdo) : array();
if (!is_array($userWorkPrefs)) {
    $userWorkPrefs = array();
}
if (function_exists('ui_glass_bg_filename')) {
    $glassBgPersonal = ui_glass_bg_filename($userWorkPrefs);
}
$prefLangUi = (isset($userWorkPrefs['language']) && $userWorkPrefs['language'] === 'en')
    ? 'en'
    : ((isset($userWorkPrefs['language']) && $userWorkPrefs['language'] === 'ar')
        ? 'ar'
        : ((isset($lang) && $lang === 'en') ? 'en' : 'ar'));
$prefCurUi = isset($userWorkPrefs['currency']) && trim((string) $userWorkPrefs['currency']) !== ''
    ? (string) $userWorkPrefs['currency']
    : (isset($s['currency']) ? (string) $s['currency'] : 'د.ع');
$prefGraceUi = array_key_exists('grace_days', $userWorkPrefs)
    ? max(0, (int) $userWorkPrefs['grace_days'])
    : (int) (isset($s['grace_days']) ? $s['grace_days'] : 3);
$prefPeriodRaw = isset($userWorkPrefs['subscription_period_mode'])
    ? $userWorkPrefs['subscription_period_mode']
    : (isset($s['subscription_period_mode']) ? $s['subscription_period_mode'] : 'days_30');
$prefPeriodUi = function_exists('subscription_period_normalize')
    ? subscription_period_normalize($prefPeriodRaw)
    : 'days_30';
$prefActAfter = (isset($userWorkPrefs['activate_after']) && $userWorkPrefs['activate_after'] === 'stay')
    ? 'stay'
    : 'dashboard';
?>

<?php if ($tab === 'behavior'): ?>
<div class="panel" id="systemBehaviorPanel">
    <h2><?php echo e($lang === 'en' ? 'System behavior' : 'سلوك النظام'); ?></h2>
    <form method="post" action="settings.php?tab=behavior">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="system_behavior">
        <div class="form-grid">
            <div>
                <?php
                $invSeqModeNow = 'continuous';
                if (!function_exists('invoice_slip_seq_mode')) {
                    require_once __DIR__ . '/../includes/invoice_studio.php';
                }
                if (function_exists('invoice_slip_seq_mode')) {
                    $invSeqModeNow = invoice_slip_seq_mode(function_exists('current_tenant_id') ? (int) current_tenant_id() : 1);
                }
                ?>
                <label><?php echo e($lang === 'en' ? 'Invoice counter' : 'عداد الفواتير'); ?></label>
                <select name="invoice_seq_mode">
                    <option value="continuous" <?php echo $invSeqModeNow !== 'month' ? 'selected' : ''; ?>>
                        <?php echo e($lang === 'en' ? 'Continuous' : 'مستمر'); ?>
                    </option>
                    <option value="month" <?php echo $invSeqModeNow === 'month' ? 'selected' : ''; ?>>
                        <?php echo e($lang === 'en' ? 'Reset every month' : 'يصفر كل شهر'); ?>
                    </option>
                </select>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'After activating a subscriber' : 'عند تفعيل مشترك'); ?></label>
                <select name="activate_after">
                    <option value="stay" <?php echo $prefActAfter === 'stay' ? 'selected' : ''; ?>>
                        <?php echo e($lang === 'en' ? 'Stay on the same page' : 'البقاء بنفس الصفحة'); ?>
                    </option>
                    <option value="dashboard" <?php echo $prefActAfter === 'dashboard' ? 'selected' : ''; ?>>
                        <?php echo e($lang === 'en' ? 'Return to the dashboard' : 'رجوع للداشبورد'); ?>
                    </option>
                </select>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($showThemePanel): ?>
<div class="panel theme-pick-panel" id="themePickPanel">
    <h2><?php echo e($lang === 'en' ? 'Interface theme' : 'ثيم الواجهة'); ?></h2>
    <p class="meta" style="margin-top:-6px">
        <?php echo e($lang === 'en'
            ? 'Saved for your login only. Classic is the previous look, Glass is the transparent dashboard.'
            : 'ينحفظ لحسابك فقط. الكلاسيك هو الشكل السابق، والزجاجي هو الداشبورد الشفاف.'); ?>
    </p>
    <form method="post" class="theme-pick-form" id="themePickForm" enctype="multipart/form-data" action="settings.php?tab=theme">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="ui_theme">
        <input type="hidden" name="return_tab" value="theme">
        <div class="theme-pick-grid" role="radiogroup" aria-label="<?php echo e($lang === 'en' ? 'Theme' : 'الثيم'); ?>">
            <label class="theme-card<?php echo $uiThemeNow === 'classic' ? ' is-on' : ''; ?>">
                <input type="radio" name="ui_theme" value="classic" <?php echo $uiThemeNow === 'classic' ? 'checked' : ''; ?>>
                <span class="theme-card-preview theme-prev-classic" aria-hidden="true">
                    <span class="tp-side"></span>
                    <span class="tp-main">
                        <span class="tp-bar"></span>
                        <span class="tp-boxes"><i></i><i></i><i></i></span>
                    </span>
                </span>
                <span class="theme-card-name"><?php echo e($lang === 'en' ? 'Classic' : 'كلاسيك'); ?></span>
                <span class="theme-card-sub"><?php echo e($lang === 'en' ? 'Previous solid look' : 'الشكل السابق الصلب'); ?></span>
            </label>
            <label class="theme-card<?php echo $uiThemeNow === 'glass' ? ' is-on' : ''; ?>">
                <input type="radio" name="ui_theme" value="glass" <?php echo $uiThemeNow === 'glass' ? 'checked' : ''; ?>>
                <span class="theme-card-preview theme-prev-glass" aria-hidden="true"<?php
                    echo $glassBgNow !== '' ? (' style="background-image:url(' . e($glassBgNow) . ')"') : '';
                ?>>
                    <span class="tp-side"></span>
                    <span class="tp-main">
                        <span class="tp-bar"></span>
                        <span class="tp-boxes"><i></i><i></i><i></i></span>
                    </span>
                </span>
                <span class="theme-card-name"><?php echo e($lang === 'en' ? 'Glass' : 'زجاجي شفاف'); ?></span>
                <span class="theme-card-sub"><?php echo e($lang === 'en' ? 'Frosted glass dashboard' : 'داشبورد زجاجي شفاف'); ?></span>
            </label>
        </div>
        <div class="theme-glass-bg" style="margin-top:14px">
            <label><?php echo e($lang === 'en' ? 'Glass background image (optional)' : 'صورة خلفية الزجاج (اختياري)'); ?></label>
            <input type="file" name="glass_bg_file" accept="image/jpeg,image/png,image/gif,image/webp">
            <small class="meta" style="display:block;margin-top:4px">
                <?php echo e($lang === 'en'
                    ? 'For your account only. JPG/PNG/GIF/WEBP up to 8MB. Makes transparency look better.'
                    : 'لحسابك فقط. JPG/PNG/GIF/WEBP حتى 8MB. تخلي الشفافية أوضح.'); ?>
            </small>
            <?php if ($glassBgNow !== ''): ?>
                <div class="login-bg-preview" style="margin-top:10px;height:88px;border-radius:12px;background-image:url(<?php echo e($glassBgNow); ?>);background-size:cover;background-position:center"></div>
            <?php endif; ?>
        </div>
        <div class="actions" style="margin-top:12px">
            <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Save theme' : 'حفظ الثيم'); ?></button>
            <button class="btn ghost" type="submit" name="bg_clear" value="1"><?php echo e($lang === 'en' ? 'Remove background' : 'حذف الخلفية'); ?></button>
        </div>
    </form>
</div>
<style>
.theme-pick-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-top: 10px;
}
@media (max-width: 640px) { .theme-pick-grid { grid-template-columns: 1fr; } }
.theme-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin: 0;
  padding: 12px;
  border-radius: 14px;
  border: 1px solid rgba(15,23,42,.12);
  background: rgba(255,255,255,.55);
  cursor: pointer;
  transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
}
.theme-card input { position: absolute; opacity: 0; pointer-events: none; }
.theme-card.is-on {
  border-color: #0ea5e9;
  box-shadow: 0 0 0 2px rgba(14,165,233,.25);
}
.theme-card:hover { transform: translateY(-2px); }
.theme-card-name { font-weight: 800; font-size: 14px; }
.theme-card-sub { font-size: 12px; font-weight: 600; opacity: .7; }
.theme-card-preview {
  display: flex;
  height: 76px;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid rgba(15,23,42,.08);
}
.theme-card-preview .tp-side { width: 22%; }
.theme-card-preview .tp-main { flex: 1; padding: 8px; display: flex; flex-direction: column; gap: 7px; }
.theme-card-preview .tp-bar { height: 10px; border-radius: 999px; }
.theme-card-preview .tp-boxes { display: flex; gap: 6px; flex: 1; }
.theme-card-preview .tp-boxes i { flex: 1; border-radius: 8px; display: block; }
.theme-prev-classic .tp-side { background: #12181f; }
.theme-prev-classic .tp-main { background: #e8edf3; }
.theme-prev-classic .tp-bar { background: #fff; }
.theme-prev-classic .tp-boxes i:nth-child(1) { background: linear-gradient(145deg,#38bdf8,#0369a1); }
.theme-prev-classic .tp-boxes i:nth-child(2) { background: linear-gradient(145deg,#4ade80,#15803d); }
.theme-prev-classic .tp-boxes i:nth-child(3) { background: linear-gradient(145deg,#fb7185,#be123c); }
.theme-prev-glass {
  background: url('assets/glass-bg.jpg?v=1') center/cover;
}
.theme-prev-glass .tp-side { background: rgba(255,255,255,.12); backdrop-filter: blur(6px); }
.theme-prev-glass .tp-main { background: transparent; }
.theme-prev-glass .tp-bar,
.theme-prev-glass .tp-boxes i {
  background: rgba(255,255,255,.16);
  border: 1px solid rgba(255,255,255,.28);
  backdrop-filter: blur(6px);
}
</style>
<script>
(function () {
  var form = document.getElementById('themePickForm');
  if (!form) return;
  form.querySelectorAll('input[name="ui_theme"]').forEach(function (r) {
    r.addEventListener('change', function () {
      form.querySelectorAll('.theme-card').forEach(function (c) { c.classList.remove('is-on'); });
      var lab = r.closest('.theme-card');
      if (lab) lab.classList.add('is-on');
    });
  });
})();
</script>
<?php endif; ?>

<?php if ($tab === 'widgets' && function_exists('dash_layout_edit_rows')):
    list($dashCat, $dashEdit) = dash_layout_edit_rows($pdo);
    $dashToneOpts = function_exists('dash_layout_tones') ? dash_layout_tones() : array();
    $isEnSet = ($lang === 'en');
?>
<div class="panel panel-compact wid-panel">
    <h2><?php echo e($isEnSet ? 'Arrange widgets' : 'ترتيب الوجدز'); ?></h2>
    <p class="meta"><?php echo e($isEnSet
        ? 'Drag the tiles like the dashboard. Tap the eye to hide, and the dots to change color.'
        : 'اسحب البطاقات مثل لوحة الوجدز. العين تخفي، والنقاط تغيّر اللون.'); ?></p>
    <?php if (!$dashEdit): ?>
        <p class="meta"><?php echo e($isEnSet ? 'No widgets are available for this account.' : 'ماكو وجدز متاحة لهذا الحساب.'); ?></p>
    <?php else: ?>
    <form method="post" action="settings.php?tab=widgets" id="dashLayoutForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_dash_layout">
        <div class="wid-sort" id="widSort">
            <?php foreach ($dashEdit as $dashRow):
                $dashId = (string) $dashRow['id'];
                if (!isset($dashCat[$dashId])) {
                    continue;
                }
                $dashOn = !isset($dashRow['on']) || !empty($dashRow['on']);
                $dashTone = isset($dashRow['tone']) ? (string) $dashRow['tone'] : $dashCat[$dashId]['tone'];
                if (!isset($dashToneOpts[$dashTone])) {
                    $dashTone = $dashCat[$dashId]['tone'];
                }
                ?>
            <div class="wid-tile sas-box <?php echo e($dashTone); ?><?php echo $dashOn ? '' : ' is-off'; ?>" data-row data-tone="<?php echo e($dashTone); ?>">
                <input type="hidden" name="dash_id[]" value="<?php echo e($dashId); ?>">
                <input type="hidden" class="wid-tone-val" name="dash_tone[<?php echo e($dashId); ?>]" value="<?php echo e($dashTone); ?>">
                <label class="wid-eye" title="<?php echo e($isEnSet ? 'Show / hide' : 'إظهار / إخفاء'); ?>">
                    <input type="checkbox" name="dash_on[]" value="<?php echo e($dashId); ?>"<?php echo $dashOn ? ' checked' : ''; ?>>
                    <span aria-hidden="true">👁</span>
                </label>
                <div class="sas-box-title"><?php echo e($dashCat[$dashId]['label']); ?></div>
                <div class="sas-box-sub"><?php echo e($isEnSet ? 'Preview' : 'معاينة'); ?></div>
                <div class="sas-box-val">—</div>
                <div class="wid-tones" data-no-drag>
                    <?php foreach ($dashToneOpts as $toneId => $toneName): ?>
                    <button type="button" class="wid-dot <?php echo e($toneId); ?><?php echo $dashTone === $toneId ? ' on' : ''; ?>" data-tone="<?php echo e($toneId); ?>" title="<?php echo e($toneName); ?>" aria-label="<?php echo e($toneName); ?>"></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="wid-save">
            <button class="btn" type="submit"><?php echo e($isEnSet ? 'Save' : 'حفظ'); ?></button>
        </div>
    </form>
    <?php endif; ?>
</div>
<style>
.wid-panel { max-width: none; }
.wid-panel h2 { margin: 0 0 6px; }
.wid-sort {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-top: 14px;
  width: 100%;
}
@media (max-width: 1100px) { .wid-sort { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 760px) { .wid-sort { grid-template-columns: 1fr; gap: 11px; } }
.wid-tile.sas-box {
  --c1: #0f766e; --c2: #115e59; --ink: #ffffff;
  position: relative; overflow: hidden;
  display: flex; flex-direction: column; justify-content: flex-start;
  color: var(--ink) !important; text-decoration: none !important;
  border-radius: 2px 20px 2px 16px; min-height: 118px;
  padding: 14px 16px 42px 18px;
  background: linear-gradient(145deg, var(--c1) 0%, var(--c2) 100%);
  border: 0; box-shadow: 6px 7px 0 rgba(15, 23, 42, 0.12);
  cursor: grab; touch-action: none; user-select: none;
  transition: transform .16s ease, box-shadow .16s ease, opacity .16s ease, filter .16s ease;
}
.wid-tile.sas-box::before {
  content: ''; position: absolute; inset-block: 12px 12px; inset-inline-start: 0;
  width: 4px; border-radius: 0 6px 6px 0; background: rgba(255,255,255,0.55);
}
.wid-tile.sas-box::after {
  content: ''; position: absolute; width: 110px; height: 110px; border-radius: 50%;
  inset-inline-end: -34px; inset-block-end: -42px; background: rgba(255,255,255,0.12); pointer-events: none;
}
.wid-tile .sas-box-title { font-size: 13px; font-weight: 800; opacity: .95; z-index: 1; line-height: 1.25; padding-inline-end: 36px; }
.wid-tile .sas-box-sub { font-size: 11px; font-weight: 600; opacity: .8; margin-top: 2px; z-index: 1; }
.wid-tile .sas-box-val { font-size: 26px; font-weight: 800; margin-top: 6px; z-index: 1; letter-spacing: -0.02em; }
.wid-tile.is-drag { opacity: .78; transform: scale(1.03); box-shadow: 10px 12px 0 rgba(15, 23, 42, 0.18); z-index: 3; }
.wid-tile.is-off { filter: grayscale(.85) brightness(.88); opacity: .55; }
.wid-eye {
  position: absolute; inset-inline-end: 10px; inset-block-start: 10px; z-index: 2;
  width: 34px; height: 34px; margin: 0; border-radius: 10px;
  background: rgba(15, 23, 42, .22); display: grid; place-items: center; cursor: pointer;
}
.wid-eye input { position: absolute; opacity: 0; pointer-events: none; }
.wid-eye span { font-size: 15px; line-height: 1; }
.wid-tile.is-off .wid-eye { background: rgba(15, 23, 42, .45); }
.wid-tones {
  position: absolute; inset-inline-start: 12px; inset-block-end: 10px; z-index: 2;
  display: flex; flex-wrap: wrap; gap: 5px; max-width: calc(100% - 24px);
}
.wid-dot {
  width: 16px; height: 16px; border-radius: 50%; border: 2px solid rgba(255,255,255,.55);
  padding: 0; cursor: pointer; box-shadow: inset 0 0 0 1px rgba(15,23,42,.12);
  background: linear-gradient(145deg, var(--c1), var(--c2));
}
.wid-dot.on { border-color: #fff; box-shadow: 0 0 0 2px rgba(15,23,42,.28); }
.wid-dot.tone-blue { --c1: #38bdf8; --c2: #0369a1; }
.wid-dot.tone-green { --c1: #4ade80; --c2: #15803d; }
.wid-dot.tone-aqua { --c1: #22d3ee; --c2: #0e7490; }
.wid-dot.tone-red { --c1: #fb7185; --c2: #be123c; }
.wid-dot.tone-yellow { --c1: #fbbf24; --c2: #b45309; }
.wid-dot.tone-teal { --c1: #2dd4bf; --c2: #0f766e; }
.wid-dot.tone-purple { --c1: #fb923c; --c2: #c2410c; }
.wid-dot.tone-lime { --c1: #a3e635; --c2: #4d7c0f; }
.wid-dot.tone-navy { --c1: #64748b; --c2: #1e293b; }
.wid-tile.tone-blue { --c1: #38bdf8; --c2: #0369a1; }
.wid-tile.tone-green { --c1: #4ade80; --c2: #15803d; }
.wid-tile.tone-aqua { --c1: #22d3ee; --c2: #0e7490; }
.wid-tile.tone-red { --c1: #fb7185; --c2: #be123c; }
.wid-tile.tone-yellow { --c1: #fbbf24; --c2: #b45309; }
.wid-tile.tone-teal { --c1: #2dd4bf; --c2: #0f766e; }
.wid-tile.tone-purple { --c1: #fb923c; --c2: #c2410c; }
.wid-tile.tone-lime { --c1: #a3e635; --c2: #4d7c0f; }
.wid-tile.tone-navy { --c1: #64748b; --c2: #1e293b; }
.wid-save {
  position: sticky; bottom: 12px; margin-top: 16px; z-index: 5;
  display: flex; justify-content: flex-start;
}
.wid-save .btn { min-width: 160px; height: 46px; box-shadow: 0 8px 20px rgba(15,23,42,.18); }
@media (max-width: 640px) { .wid-save .btn { width: 100%; } }
</style>
<script>
(function () {
  var list = document.getElementById('widSort');
  if (!list) return;
  var drag = null;
  var tones = ['tone-blue','tone-green','tone-red','tone-yellow','tone-teal','tone-navy','tone-lime','tone-purple','tone-aqua'];
  function rowOf(el) {
    return el && el.closest ? el.closest('[data-row]') : null;
  }
  function place(x, y) {
    var cards = list.querySelectorAll('[data-row]');
    var best = null, bestD = 1e15, i, r, cx, cy, d;
    for (i = 0; i < cards.length; i++) {
      if (cards[i] === drag) continue;
      r = cards[i].getBoundingClientRect();
      cx = r.left + r.width / 2;
      cy = r.top + r.height / 2;
      d = (x - cx) * (x - cx) + (y - cy) * (y - cy);
      if (d < bestD) { bestD = d; best = cards[i]; }
    }
    if (!best) { list.appendChild(drag); return; }
    r = best.getBoundingClientRect();
    var before;
    if (Math.abs(y - (r.top + r.height / 2)) > r.height * 0.35) {
      before = y < r.top + r.height / 2;
    } else {
      var rtl = (getComputedStyle(list).direction === 'rtl');
      before = rtl ? (x > r.left + r.width / 2) : (x < r.left + r.width / 2);
    }
    if (before) list.insertBefore(drag, best);
    else if (best.nextSibling) list.insertBefore(drag, best.nextSibling);
    else list.appendChild(drag);
  }
  list.addEventListener('pointerdown', function (e) {
    if (e.target.closest && (e.target.closest('[data-no-drag]') || e.target.closest('.wid-eye'))) return;
    var row = rowOf(e.target);
    if (!row) return;
    e.preventDefault();
    drag = row;
    row.classList.add('is-drag');
    try { row.setPointerCapture(e.pointerId); } catch (err) {}
  });
  list.addEventListener('pointermove', function (e) {
    if (!drag) return;
    e.preventDefault();
    if (e.clientY < 72) window.scrollBy(0, -16);
    else if (e.clientY > window.innerHeight - 88) window.scrollBy(0, 16);
    place(e.clientX, e.clientY);
  });
  function stop() {
    if (!drag) return;
    drag.classList.remove('is-drag');
    drag = null;
  }
  list.addEventListener('pointerup', stop);
  list.addEventListener('pointercancel', stop);
  list.addEventListener('change', function (e) {
    var eye = e.target.closest ? e.target.closest('.wid-eye input') : null;
    if (!eye) return;
    var row = rowOf(eye);
    if (row) row.classList.toggle('is-off', !eye.checked);
  });
  list.addEventListener('click', function (e) {
    var dot = e.target.closest ? e.target.closest('.wid-dot') : null;
    if (!dot) return;
    e.preventDefault();
    var row = rowOf(dot);
    if (!row) return;
    var tone = dot.getAttribute('data-tone') || '';
    var i;
    for (i = 0; i < tones.length; i++) row.classList.remove(tones[i]);
    if (tone) row.classList.add(tone);
    row.setAttribute('data-tone', tone);
    var hid = row.querySelector('.wid-tone-val');
    if (hid) hid.value = tone;
    var dots = row.querySelectorAll('.wid-dot');
    for (i = 0; i < dots.length; i++) dots[i].classList.toggle('on', dots[i] === dot);
  });
})();
</script>
<?php endif; ?>


<?php if ($tab === 'maintenance' && function_exists('is_super_admin_user') && is_super_admin_user()): ?>
<div class="panel panel-compact">
    <h2><?php echo e($lang === 'en' ? 'System maintenance' : 'صيانة النظام'); ?></h2>
    <p class="meta" style="margin-top:-4px">
        <?php echo e($lang === 'en'
            ? 'Temporarily block Activate and Give-1-day for all agents while you maintain the system.'
            : 'أوقف مؤقتاً زر التفعيل وإعطاء يوم واحد لكل الوكلاء أثناء صيانة النظام.'); ?>
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="maintenance">
        <label class="toggle" style="display:flex;align-items:center;gap:10px;margin:10px 0">
            <input type="checkbox" name="maint_block_activate" value="1" <?php echo !empty($s['maint_block_activate']) ? 'checked' : ''; ?>>
            <span><?php echo e($lang === 'en' ? 'Block Activate' : 'إيقاف زر التفعيل'); ?></span>
        </label>
        <label class="toggle" style="display:flex;align-items:center;gap:10px;margin:10px 0">
            <input type="checkbox" name="maint_block_give_test" value="1" <?php echo !empty($s['maint_block_give_test']) ? 'checked' : ''; ?>>
            <span><?php echo e($lang === 'en' ? 'Block Give 1 day' : 'إيقاف إعطاء يوم واحد'); ?></span>
        </label>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($tab === 'users'):
    $roleQ = trim((string) (isset($_GET['q']) ? $_GET['q'] : ''));
    $roleUsers = array();
    $rolesPlatform = function_exists('is_super_admin_user') && is_super_admin_user();
    foreach ($adminUsers as $u) {
        $urole = normalize_admin_role(isset($u['role']) ? $u['role'] : 'staff');
        $uTid = isset($u['tenant_id']) ? (int) $u['tenant_id'] : 1;
        if ($urole === 'agent' || $urole === 'group_manager') {
            continue;
        }
        if ($rolesPlatform && $uTid > 1) {
            continue;
        }
        if (!$rolesPlatform && $urole === 'admin' && $uTid > 1) {
            continue;
        }
        if ($roleQ !== '') {
            $bits = array(
                isset($u['username']) ? $u['username'] : '',
                isset($u['display_name']) ? $u['display_name'] : '',
                $urole,
                admin_role_label($urole, $lang),
            );
            $hay = function_exists('mb_strtolower') ? mb_strtolower(implode(' ', $bits), 'UTF-8') : strtolower(implode(' ', $bits));
            $needle = function_exists('mb_strtolower') ? mb_strtolower($roleQ, 'UTF-8') : strtolower($roleQ);
            $hit = function_exists('mb_strpos') ? (mb_strpos($hay, $needle, 0, 'UTF-8') !== false) : (strpos($hay, $needle) !== false);
            if (!$hit) {
                continue;
            }
        }
        $roleUsers[] = $u;
    }
?>
<div class="panel panel-compact">
    <div class="roles-head">
        <h2><?php echo e($lang === 'en' ? 'Roles' : 'الأدوار'); ?></h2>
        <button class="btn" type="button" id="roleAddToggle"><?php echo e($lang === 'en' ? 'Add user' : 'إضافة مستخدم'); ?></button>
    </div>
    <form method="get" class="roles-search">
        <input type="hidden" name="tab" value="users">
        <input type="search" name="q" value="<?php echo e($roleQ); ?>" placeholder="<?php echo e($lang === 'en' ? 'Search username, name, or role…' : 'بحث بالاسم أو الدخول أو الصلاحية…'); ?>" autocomplete="off">
        <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Search' : 'بحث'); ?></button>
    </form>
    <form method="post" id="roleAddBox" hidden>
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="add_user">
        <div class="form-grid cols-4">
            <div>
                <label><?php echo e($lang === 'en' ? 'Username' : 'اسم الدخول'); ?></label>
                <input name="username" required pattern="[A-Za-z0-9._@-]{2,40}" placeholder="yousif">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Display name' : 'الاسم الظاهر'); ?></label>
                <input name="display_name" required placeholder="<?php echo e($lang === 'en' ? 'Yousif' : 'يوسف'); ?>">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Password' : 'الرمز'); ?></label>
                <input type="password" name="password" required minlength="4">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Role' : 'الصلاحية'); ?></label>
                <select name="role" id="addUserRole">
                    <?php foreach (admin_roles() as $rOpt): ?>
                        <?php if ($rOpt === 'agent' || $rOpt === 'group_manager') { continue; } ?>
                        <?php if ($rOpt === 'admin' && !(function_exists('is_super_admin_user') && is_super_admin_user())) { continue; } ?>
                        <option value="<?php echo e($rOpt); ?>"><?php echo e(admin_role_label($rOpt, $lang)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="addLinkedAgentWrap" style="display:none">
                <label><?php echo e($lang === 'en' ? 'Linked agent' : 'الوكيل المرتبط'); ?></label>
                <select name="linked_agent_id">
                    <option value="0"><?php echo e($lang === 'en' ? '— select agent —' : '— اختر وكيل —'); ?></option>
                    <?php foreach ($settingsAgents as $sag): ?>
                        <option value="<?php echo (int) $sag['id']; ?>"><?php echo e($sag['display_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Add' : 'إضافة'); ?></button>
        </div>
    </form>
    <div class="table-wrap">
        <table class="table-compact roles-table">
            <thead>
            <tr>
                <th>#</th>
                <th><?php echo e($lang === 'en' ? 'Username' : 'اسم الدخول'); ?></th>
                <th><?php echo e($lang === 'en' ? 'Name' : 'الاسم'); ?></th>
                <th><?php echo e($lang === 'en' ? 'Role' : 'الصلاحية'); ?></th>
                <th><?php echo e($lang === 'en' ? 'New password' : 'رمز جديد'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$roleUsers): ?>
                <tr><td colspan="6" class="msg-empty"><?php echo e($roleQ !== '' ? ($lang === 'en' ? 'No matches' : 'ماكو نتيجة') : ($lang === 'en' ? 'No users' : 'ماكو أشخاص')); ?></td></tr>
            <?php endif; ?>
            <?php $roleNo = 0; foreach ($roleUsers as $u):
                $roleNo++;
                $urole = normalize_admin_role(isset($u['role']) ? $u['role'] : 'staff');
                $ulinked = isset($u['linked_agent_id']) ? (int) $u['linked_agent_id'] : 0;
                ?>
                <tr>
                    <td><?php echo (int) $roleNo; ?></td>
                    <td>
                        <strong class="ltr"><?php echo e($u['username']); ?></strong>
                        <?php if ($me && (int) $me['id'] === (int) $u['id']): ?>
                            <span class="badge active"><?php echo e($lang === 'en' ? 'You' : 'أنت'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input form="roleForm<?php echo (int) $u['id']; ?>" name="display_name" value="<?php echo e($u['display_name']); ?>" required placeholder="<?php echo e($lang === 'en' ? 'Name' : 'الاسم'); ?>">
                    </td>
                    <td>
                        <select form="roleForm<?php echo (int) $u['id']; ?>" name="role" class="user-role-select">
                            <?php foreach (admin_roles() as $rOpt): ?>
                                <?php if ($rOpt === 'agent' || $rOpt === 'group_manager') { continue; } ?>
                                <?php if ($rOpt === 'admin' && $urole !== 'admin' && !(function_exists('is_super_admin_user') && is_super_admin_user())) { continue; } ?>
                                <option value="<?php echo e($rOpt); ?>" <?php echo $urole === $rOpt ? 'selected' : ''; ?>><?php echo e(admin_role_label($rOpt, $lang)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select form="roleForm<?php echo (int) $u['id']; ?>" name="linked_agent_id" class="user-linked-agent" style="<?php echo $urole === 'accountant' ? '' : 'display:none'; ?>">
                            <option value="0"><?php echo e($lang === 'en' ? 'Agent…' : 'وكيل…'); ?></option>
                            <?php foreach ($settingsAgents as $sag):
                                $sid = (int) $sag['id'];
                                ?>
                                <option value="<?php echo $sid; ?>"<?php echo $ulinked === $sid ? ' selected' : ''; ?>><?php echo e($sag['display_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input form="roleForm<?php echo (int) $u['id']; ?>" type="password" name="new_password" placeholder="<?php echo e($lang === 'en' ? 'Optional' : 'اختياري'); ?>" minlength="4">
                    </td>
                    <td class="roles-acts">
                        <form method="post" id="roleForm<?php echo (int) $u['id']; ?>" class="user-edit-form inline-form">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="section" value="save_user">
                            <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                            <button class="btn secondary sm" type="submit"><?php echo e(t('save')); ?></button>
                        </form>
                        <?php if (!$me || (int) $me['id'] !== (int) $u['id']): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm(<?php echo json_encode($lang === 'en' ? 'Delete this user?' : 'حذف هذا الشخص؟'); ?>);">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="section" value="delete_user">
                            <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                            <button class="btn danger sm" type="submit"><?php echo e($lang === 'en' ? 'Delete' : 'حذف'); ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<style>
.roles-head { display:flex; align-items:center; justify-content:flex-end; direction:ltr; gap:12px; margin:0 0 12px; flex-wrap:wrap; }
.roles-head h2 { margin:0; }
.roles-search { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 14px; }
.roles-search input[type="search"] { flex:1; min-width:220px; max-width:420px; }
#roleAddBox { margin:0 0 16px; }
.roles-table td { vertical-align:middle; }
.roles-table input, .roles-table select { width:100%; max-width:180px; }
.roles-acts { display:flex; gap:6px; align-items:center; white-space:nowrap; }
</style>
<script>
(function () {
  var btn = document.getElementById('roleAddToggle');
  var box = document.getElementById('roleAddBox');
  if (btn && box) {
    btn.addEventListener('click', function () {
      box.hidden = !box.hidden;
      if (!box.hidden) {
        var first = box.querySelector('input[name="username"]');
        if (first) first.focus();
      }
    });
  }
  var roleSel = document.getElementById('addUserRole');
  var wrap = document.getElementById('addLinkedAgentWrap');
  if (roleSel && wrap) {
    function syncAdd() { wrap.style.display = roleSel.value === 'accountant' ? '' : 'none'; }
    roleSel.addEventListener('change', syncAdd);
    syncAdd();
  }
  document.querySelectorAll('.user-role-select').forEach(function (sel) {
    var formId = sel.getAttribute('form') || '';
    var linked = formId ? document.querySelector('select.user-linked-agent[form="' + formId + '"]') : null;
    if (!linked) return;
    function sync() { linked.style.display = sel.value === 'accountant' ? '' : 'none'; }
    sel.addEventListener('change', sync);
  });
})();
</script>
<?php endif; ?>

<?php if ($tab === 'general'): ?>
<div class="panel" id="userWorkPrefsPanel">
    <h2><?php echo e($lang === 'en' ? 'General' : 'عام'); ?></h2>
    <p class="meta" style="margin-top:-6px">
        <?php echo e($lang === 'en'
            ? 'Saved for your login only. Other users keep their own values.'
            : 'ينحفظ لحسابك فقط. كل مستخدم يخصص لنفسه بدون تأثير على غيره.'); ?>
    </p>
    <form method="post" action="settings.php?tab=general">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="user_prefs">
        <div class="form-grid">
            <div>
                <label><?php echo e(t('language')); ?></label>
                <select name="language">
                    <option value="ar" <?php echo $prefLangUi === 'ar' ? 'selected' : ''; ?>>العربية</option>
                    <option value="en" <?php echo $prefLangUi === 'en' ? 'selected' : ''; ?>>English</option>
                </select>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Currency' : 'العملة'); ?></label>
                <input name="currency" value="<?php echo e($prefCurUi); ?>">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Default grace days' : 'أيام السماح الافتراضية'); ?></label>
                <input type="number" min="0" name="grace_days" value="<?php echo (int) $prefGraceUi; ?>">
                <small style="color:#6b7a88;font-weight:600"><?php echo e($lang === 'en' ? 'Default after activation. Can still change per subscriber.' : 'الافتراضي بعد التفعيل. يبقى قابل للتعديل لكل مشترك.'); ?></small>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Subscription period' : 'مدة الاشتراك'); ?></label>
                <select name="subscription_period_mode">
                    <option value="days_30" <?php echo $prefPeriodUi === 'days_30' ? 'selected' : ''; ?>>
                        <?php echo e($lang === 'en' ? '30 days (30 Jul → 29 Aug)' : '30 يوم (30-7 → 29-8)'); ?>
                    </option>
                    <option value="calendar_month" <?php echo $prefPeriodUi === 'calendar_month' ? 'selected' : ''; ?>>
                        <?php echo e($lang === 'en' ? 'Calendar month (30 Jul → 30 Aug)' : 'شهر ميلادي (30-7 → 30-8)'); ?>
                    </option>
                    <option value="sas" <?php echo $prefPeriodUi === 'sas' ? 'selected' : ''; ?>>
                        <?php echo e($lang === 'en' ? 'Follow SAS expiry' : 'حسب الساس'); ?>
                    </option>
                </select>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>
<?php if ($isPlatformAdmin): ?>
<?php
$sys = collect_system_status($config);
$waState = $sys['whatsapp']['state'];
$waTone = ($waState === 'online') ? 'ok' : (($waState === 'qr') ? 'warn' : 'bad');
$gOk = !empty($sys['google']['ok']);
$gMs = isset($sys['google']['ms']) ? $sys['google']['ms'] : null;
$diskPct = (int) $sys['disk']['pct_used'];
$diskTone = ($diskPct >= 90) ? 'bad' : (($diskPct >= 75) ? 'warn' : 'ok');
$ramPct = (int) $sys['ram']['pct_used'];
$ramTone = ($sys['ram']['source'] === 'host')
    ? (($ramPct >= 90) ? 'bad' : (($ramPct >= 75) ? 'warn' : 'ok'))
    : 'ok';
$cpuPct = isset($sys['cpu']['pct']) ? (int) $sys['cpu']['pct'] : null;
$cpuTone = ($cpuPct === null) ? 'ok' : (($cpuPct >= 90) ? 'bad' : (($cpuPct >= 75) ? 'warn' : 'ok'));
$sasLat = isset($sys['sas_latency']) && is_array($sys['sas_latency']) ? $sys['sas_latency'] : array();
$sasLatOk = !empty($sasLat['ok']);
$sasLatMs = isset($sasLat['ms']) ? $sasLat['ms'] : null;
$sasLatHost = isset($sasLat['host']) ? (string) $sasLat['host'] : '';
$sasLatTone = ($sasLatMs === null) ? 'bad' : (($sasLatMs >= 200) ? 'bad' : (($sasLatMs >= 100) ? 'warn' : 'ok'));
$sasLatDisp = function_exists('system_format_ms') ? system_format_ms($sasLatMs) : (($sasLatMs !== null) ? (number_format((float) $sasLatMs, 1) . ' ms') : '—');
$gLatDisp = function_exists('system_format_ms') ? system_format_ms($gMs) : (($gMs !== null) ? (number_format((float) $gMs, 1) . ' ms') : '—');
$gLatTone = ($gMs === null || !$gOk) ? 'bad' : (($gMs >= 200) ? 'bad' : (($gMs >= 100) ? 'warn' : 'ok'));
?>
<div class="panel">
    <div class="sys-status-head">
        <h2 style="margin:0"><?php echo e($lang === 'en' ? 'System status' : 'حالة النظام'); ?></h2>
        <button type="button" class="btn ghost sm" id="sysStatusRefresh"><?php echo e($lang === 'en' ? 'Refresh' : 'تحديث'); ?></button>
    </div>
    <div class="sys-status-grid">
        <div class="sys-card tone-ok">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'Version' : 'الإصدار'); ?></div>
            <div class="sys-card-v">v<?php echo e($sys['version']); ?></div>
            <div class="sys-card-s">PHP <?php echo e($sys['php']); ?></div>
        </div>
        <div class="sys-card tone-<?php echo e($diskTone); ?>" id="sysDiskCard">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'Disk free' : 'المساحة'); ?></div>
            <div class="sys-card-v" id="sysDiskVal"><?php echo e($sys['disk']['ok'] ? $sys['disk']['label'] : '—'); ?></div>
            <div class="sys-card-s" id="sysDiskSub"><?php echo e($lang === 'en' ? 'Used' : 'مستخدم'); ?> <?php echo (int) $diskPct; ?>%</div>
        </div>
        <div class="sys-card tone-<?php echo e($ramTone); ?>" id="sysRamCard">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'RAM' : 'الرام'); ?></div>
            <div class="sys-card-v" id="sysRamVal"><?php echo e($sys['ram']['label']); ?></div>
            <div class="sys-card-s" id="sysRamSub">
                <?php if ($sys['ram']['source'] === 'host'): ?>
                    <?php echo e($lang === 'en' ? 'Used' : 'مستخدم'); ?> <?php echo (int) $ramPct; ?>%
                <?php else: ?>
                    peak <?php echo e(format_bytes_short($sys['ram']['php_peak'])); ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="sys-card tone-<?php echo e($cpuTone); ?>" id="sysCpuCard">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'CPU' : 'المعالج'); ?></div>
            <div class="sys-card-v" id="sysCpuVal"><?php echo e(isset($sys['cpu']['label']) ? $sys['cpu']['label'] : '—'); ?></div>
            <div class="sys-card-s"><?php echo e($lang === 'en' ? 'Usage' : 'الاستخدام'); ?></div>
        </div>
        <?php
        $portalSubsSys = function_exists('platform_portal_subscribers_total')
            ? (int) platform_portal_subscribers_total($pdo)
            : 0;
        ?>
        <div class="sys-card tone-ok" id="sysPortalSubsCard">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'Portal subscribers' : 'مشتركين البوابة'); ?></div>
            <div class="sys-card-v" id="sysPortalSubsVal"><?php echo (int) $portalSubsSys; ?></div>
            <div class="sys-card-s"><?php echo e($lang === 'en' ? 'All agencies & SAS (unique)' : 'كل الوكالات والساسات (بدون تكرار)'); ?></div>
        </div>
        <div class="sys-card tone-<?php echo e($waTone); ?>">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'WhatsApp' : 'واتساب'); ?></div>
            <div class="sys-card-v"><?php echo e($sys['whatsapp']['label']); ?></div>
            <div class="sys-card-s">
                <?php
                if ($waState === 'online') {
                    echo e($lang === 'en' ? 'Connected' : 'متصل');
                } elseif ($waState === 'qr') {
                    echo e($lang === 'en' ? 'Needs QR' : 'يحتاج QR');
                } elseif ($waState === 'disabled') {
                    echo e($lang === 'en' ? 'Disabled' : 'موقوف');
                } else {
                    echo e($lang === 'en' ? 'Not connected' : 'غير متصل');
                }
                ?>
            </div>
        </div>
        <div class="sys-card tone-<?php echo e($gLatTone); ?>" id="sysGoogleCard">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'Google latency' : 'اتصال كوكل (Latency)'); ?></div>
            <div class="sys-card-v" id="sysGoogleVal"><?php echo e($gLatDisp); ?></div>
            <div class="sys-card-s" id="sysGoogleSub"><?php echo $gOk ? e($lang === 'en' ? 'Reachable' : 'متاح') : e($lang === 'en' ? 'Failed' : 'فشل'); ?></div>
        </div>
        <div class="sys-card tone-<?php echo e($sasLatTone); ?>" id="sysBankCard">
            <div class="sys-card-k" id="sysBankTitle"><?php
                $sasLatName = isset($sasLat['name']) ? trim((string) $sasLat['name']) : '';
                if ($sasLatName === '') {
                    $sasLatName = $lang === 'en' ? 'Reseller' : 'الريسلر';
                }
                echo e($lang === 'en' ? ($sasLatName . ' ping') : ('بنك ' . $sasLatName));
            ?></div>
            <div class="sys-card-v" id="sysBankVal"><?php echo e($sasLatDisp); ?></div>
            <div class="sys-card-s" id="sysBankSub"><?php
                if ($sasLatHost !== '') {
                    echo e(($lang === 'en' ? 'Ping → ' : 'بنق → ') . $sasLatHost);
                } else {
                    echo e($lang === 'en' ? 'Reseller host ping' : 'بنق هوست الريسلر');
                }
            ?></div>
        </div>
    </div>
    <p class="meta" style="margin:10px 0 0"><?php echo e($lang === 'en' ? 'Server time' : 'وقت السيرفر'); ?>: <span id="sysServerTime"><?php echo e($sys['server_time']); ?></span></p>
</div>
<script>
(function () {
  var btn = document.getElementById('sysStatusRefresh');
  function applySys(d) {
    if (!d || !d.ok) return;
    var cpu = document.getElementById('sysCpuVal');
    if (cpu) cpu.textContent = d.cpu || '—';
    var bank = document.getElementById('sysBankVal');
    if (bank) bank.textContent = d.bank || '—';
    var bt = document.getElementById('sysBankTitle');
    if (bt && d.sas_name) {
      bt.textContent = <?php echo json_encode($lang === 'en' ? '' : 'بنك '); ?> + d.sas_name + <?php echo json_encode($lang === 'en' ? ' ping' : ''); ?>;
    }
    var bs = document.getElementById('sysBankSub');
    if (bs) {
      bs.textContent = d.sas_host
        ? (<?php echo json_encode($lang === 'en' ? 'Ping → ' : 'بنق → '); ?> + d.sas_host)
        : <?php echo json_encode($lang === 'en' ? 'Reseller host ping' : 'بنق هوست الريسلر'); ?>;
    }
    var g = document.getElementById('sysGoogleVal');
    if (g) g.textContent = d.google_label || '—';
    var gs = document.getElementById('sysGoogleSub');
    if (gs) gs.textContent = d.google_ok ? <?php echo json_encode($lang === 'en' ? 'Reachable' : 'متاح'); ?> : <?php echo json_encode($lang === 'en' ? 'Failed' : 'فشل'); ?>;
    var t = document.getElementById('sysServerTime');
    if (t && d.server_time) t.textContent = d.server_time;
    var disk = document.getElementById('sysDiskVal');
    if (disk && d.disk) disk.textContent = d.disk;
    var ram = document.getElementById('sysRamVal');
    if (ram && d.ram) ram.textContent = d.ram;
    var rs = document.getElementById('sysRamSub');
    if (rs && d.ram_pct !== undefined && d.ram_pct !== null) {
      rs.textContent = <?php echo json_encode($lang === 'en' ? 'Used ' : 'مستخدم '); ?> + d.ram_pct + '%';
    }
    var ps = document.getElementById('sysPortalSubsVal');
    if (ps && d.portal_subscribers !== undefined && d.portal_subscribers !== null) {
      ps.textContent = String(d.portal_subscribers);
    }
  }
  function loadSys(force) {
    fetch('settings.php?tab=general&ajax=sys_status' + (force ? '&refresh=1' : ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(applySys)
      .catch(function () {});
  }
  if (btn) btn.addEventListener('click', function () { loadSys(true); });
  loadSys(true);
  setInterval(function () { loadSys(true); }, 20000);
})();
</script>

<div class="panel">
    <h2><?php echo e(t('settings_general')); ?></h2>
    <p class="meta" style="margin-top:-6px">
        <?php echo e($lang === 'en'
            ? 'Language, currency, grace days and subscription period are set per user under Theme.'
            : 'اللغة والعملة وأيام السماح ومدة الاشتراك تُضبط لكل مستخدم من تبويب الثيم.'); ?>
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="general">
        <div class="form-grid">
            <div>
                <label><?php echo e(t('site_name')); ?></label>
                <input name="site_name" value="<?php echo e($s['site_name']); ?>" required>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Invoice footer' : 'فوتر الفاتورة'); ?></label>
                <input name="invoice_footer_name" value="<?php echo e(isset($s['invoice_footer_name']) ? $s['invoice_footer_name'] : ''); ?>" placeholder="<?php echo e($s['site_name']); ?>">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Keep me logged in (days)' : 'مدة حفظ الدخول (أيام)'); ?></label>
                <input type="number" min="1" max="30" name="login_session_days" value="<?php echo (int) (isset($s['login_session_days']) ? $s['login_session_days'] : 3); ?>">
                <small style="color:#6b7a88;font-weight:600"><?php echo e($lang === 'en' ? 'Session cookie duration (1–30). Default 3 days.' : 'مدة كوكي الجلسة (1–30). الافتراضي 3 أيام.'); ?></small>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>

<?php
$loginBgUrl = login_bg_url($s);
$loginBgColor = login_bg_color($s);
$bgMode = function_exists('app_bg_mode') ? app_bg_mode($s) : 'color';
$brandIconUrl = function_exists('brand_icon_url') ? brand_icon_url($s) : '';
?>
<div class="panel appearance-panel" id="appearancePanel">
    <h2><?php echo e($lang === 'en' ? 'Appearance' : 'المظهر والخلفية'); ?></h2>
    <p class="meta" style="margin-top:-6px">
        <?php echo e($lang === 'en'
            ? 'Toggle color or image. Image covers login and the app with a soft blur on panels. Logo appears on login and dashboard.'
            : 'بدّل بين لون أو صورة. الصورة تظهر بصفحة الدخول وبالنظام بضبابية على اللوحات. الشعار يظهر بصفحة الدخول ويم الرئيسية.'); ?>
    </p>
    <div class="login-bg-preview" id="appearancePreview" style="background-color:<?php echo e($loginBgColor); ?>;<?php echo ($bgMode === 'image' && $loginBgUrl !== '') ? ('background-image:url(' . e($loginBgUrl) . ');') : ''; ?>"></div>
    <form method="post" enctype="multipart/form-data" id="appearanceForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="login_bg">
        <div class="bg-mode-toggle" role="group">
            <label>
                <input type="radio" name="bg_mode" value="color" <?php echo $bgMode === 'color' ? 'checked' : ''; ?>>
                <span><?php echo e($lang === 'en' ? 'Color' : 'لون'); ?></span>
            </label>
            <label>
                <input type="radio" name="bg_mode" value="image" <?php echo $bgMode === 'image' ? 'checked' : ''; ?>>
                <span><?php echo e($lang === 'en' ? 'Image' : 'صورة'); ?></span>
            </label>
            <label>
                <input type="radio" name="bg_mode" value="none" <?php echo $bgMode === 'none' ? 'checked' : ''; ?>>
                <span><?php echo e($lang === 'en' ? 'None' : 'بدون'); ?></span>
            </label>
        </div>
        <div class="form-grid cols-2">
            <div class="bg-opt-image" data-bg-opt="image">
                <label><?php echo e($lang === 'en' ? 'Background image' : 'صورة الخلفية'); ?></label>
                <input type="file" name="login_bg_file" accept="image/jpeg,image/png,image/gif,image/webp">
                <small style="color:#64748b;font-weight:600"><?php echo e($lang === 'en' ? 'JPG/PNG/GIF/WEBP up to 8MB' : 'JPG/PNG/GIF/WEBP حتى 8MB'); ?></small>
            </div>
            <div class="bg-opt-color" data-bg-opt="color">
                <label><?php echo e($lang === 'en' ? 'Background color' : 'لون الخلفية'); ?></label>
                <input type="color" name="login_bg_color" value="<?php echo e($loginBgColor); ?>">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'System logo / icon' : 'شعار / أيقونة النظام'); ?></label>
                <input type="file" name="brand_icon_file" accept="image/jpeg,image/png,image/gif,image/webp">
                <small style="color:#64748b;font-weight:600"><?php echo e($lang === 'en' ? 'Any image up to 4MB' : 'أي صورة حتى 4MB'); ?></small>
                <?php if ($brandIconUrl !== ''): ?>
                    <div style="margin-top:8px;display:flex;align-items:center;gap:8px">
                        <img src="<?php echo e($brandIconUrl); ?>" alt="" width="36" height="36" style="border-radius:8px;object-fit:contain;background:#fff;border:1px solid #e2e8f0">
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Save appearance' : 'حفظ المظهر'); ?></button>
            <button class="btn ghost" type="submit" name="bg_clear" value="1"><?php echo e($lang === 'en' ? 'Remove background' : 'حذف الخلفية'); ?></button>
            <?php if ($brandIconUrl !== ''): ?>
                <button class="btn ghost" type="submit" name="brand_icon_remove" value="1"><?php echo e($lang === 'en' ? 'Remove icon' : 'حذف الأيقونة'); ?></button>
            <?php endif; ?>
        </div>
    </form>
</div>
<style>
.appearance-panel .bg-mode-toggle {
  display: inline-flex; gap: 0; margin: 0 0 14px; border: 1px solid #d2d6de; border-radius: 10px; overflow: hidden;
}
.appearance-panel .bg-mode-toggle label {
  margin: 0; cursor: pointer;
}
.appearance-panel .bg-mode-toggle input { position: absolute; opacity: 0; pointer-events: none; }
.appearance-panel .bg-mode-toggle span {
  display: inline-block; padding: 8px 16px; font-weight: 800; font-size: 13px; background: #fff; color: #475569;
}
.appearance-panel .bg-mode-toggle input:checked + span { background: #1e293b; color: #fff; }
.appearance-panel [data-bg-opt].is-dim {
  opacity: .38; filter: grayscale(.35); pointer-events: none;
}
.appearance-panel [data-bg-opt].is-dim input { pointer-events: none; }
</style>
<script>
(function () {
  var form = document.getElementById('appearanceForm');
  if (!form) return;
  function syncBgMode() {
    var modeEl = form.querySelector('input[name="bg_mode"]:checked');
    var mode = modeEl ? modeEl.value : 'color';
    form.querySelectorAll('[data-bg-opt]').forEach(function (el) {
      var want = el.getAttribute('data-bg-opt');
      el.classList.toggle('is-dim', want !== mode);
    });
  }
  form.querySelectorAll('input[name="bg_mode"]').forEach(function (r) {
    r.addEventListener('change', syncBgMode);
  });
  syncBgMode();
})();
</script>
<?php endif; ?>

<?php endif; ?>

<?php if ($tab === 'sensitive'): ?>
<style>
.sens-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
}
@media (max-width: 980px) { .sens-grid { grid-template-columns: 1fr; } }
.sens-card {
  border: 1px solid #fecaca;
  background: #fff7f7;
  border-radius: 12px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 100%;
}
.sens-card h3 { margin: 0; font-size: 15px; color: #991b1b; }
.sens-card p { margin: 0; color: #6b7a88; font-size: 13px; line-height: 1.45; flex: 1; }
.sens-card .actions { margin-top: 6px; }
</style>
<div class="panel">
    <h2 style="margin:0 0 8px"><?php echo e($lang === 'en' ? 'Sensitive data' : 'بيانات حساسة'); ?></h2>
    <p style="color:#6b7a88;font-weight:600;margin:0 0 14px">
        <?php echo e($lang === 'en'
            ? 'Dangerous wipe actions. Debts stay in this system (not SAS). SAS cache is a snapshot only.'
            : 'عمليات مسح خطرة. الديون تُحفظ هنا بالنظام — مو بالساس. كاش SAS لقطة حماية فقط.'); ?>
    </p>
    <div class="sens-grid">
        <form class="sens-card" method="post" onsubmit="return confirm(<?php echo json_encode(t('confirm_clear_logs')); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="section" value="clear_logs">
            <h3><?php echo e(t('clear_logs')); ?></h3>
            <p><?php echo e($lang === 'en' ? 'Deletes activity log only.' : 'يحذف حركات اللوك فقط.'); ?></p>
            <label><?php echo e(t('password')); ?>
                <input type="password" name="admin_password" required placeholder="<?php echo e($lang === 'en' ? 'Your password' : 'رمزك'); ?>">
            </label>
            <div class="actions">
                <button class="btn danger" type="submit"><?php echo e(t('clear_logs')); ?></button>
            </div>
        </form>
        <form class="sens-card" method="post" onsubmit="return confirm(<?php echo json_encode(t('confirm_clear_offline')); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="section" value="clear_offline">
            <h3><?php echo e(t('clear_offline')); ?></h3>
            <p><?php echo e($lang === 'en'
                ? 'Deletes local debts, subscriptions and WhatsApp logs. SAS user list stays.'
                : 'يحذف الديون والاشتراكات المحلية وسجل الرسائل. قائمة مشتركين SAS تبقى.'); ?></p>
            <label><?php echo e(t('password')); ?>
                <input type="password" name="admin_password" required placeholder="<?php echo e($lang === 'en' ? 'Your password' : 'رمزك'); ?>">
            </label>
            <div class="actions">
                <button class="btn danger" type="submit"><?php echo e(t('clear_offline')); ?></button>
            </div>
        </form>
        <form class="sens-card" method="post" onsubmit="return confirm('<?php echo e(t('confirm_clear')); ?>');">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="section" value="clear_data">
            <h3><?php echo e(t('clear_data')); ?></h3>
            <p><?php echo e($lang === 'en'
                ? 'Deletes all subscribers, movements, invoices and message logs. Packages stay.'
                : 'يحذف كل المشتركين والحركات والفواتير وسجل الرسائل. الباقات تبقى.'); ?></p>
            <label><?php echo e(t('password')); ?>
                <input type="password" name="admin_password" required placeholder="<?php echo e($lang === 'en' ? 'Your password' : 'رمزك'); ?>">
            </label>
            <div class="actions">
                <button class="btn danger" type="submit"><?php echo e(t('clear_data')); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="panel" style="margin-top:16px">
    <h2><?php echo e($lang === 'en' ? 'Server power' : 'طاقة السيرفر'); ?></h2>
    <p class="meta" style="margin:0 0 12px"><?php echo e($lang === 'en'
        ? 'Requires OS permissions (sudo/reboot). Confirm with your admin password.'
        : 'يحتاج صلاحيات النظام على السيرفر. أكّد بكلمة مرور الأدمن.'); ?></p>
    <div class="form-grid cols-2">
        <form method="post" onsubmit="return confirm(<?php echo json_encode($lang === 'en' ? 'Reboot the server now?' : 'إعادة تشغيل السيرفر الآن؟'); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="section" value="system_power">
            <input type="hidden" name="power_action" value="reboot">
            <label><?php echo e($lang === 'en' ? 'Admin password' : 'كلمة مرور الأدمن'); ?></label>
            <input type="password" name="admin_password" required autocomplete="current-password">
            <div class="actions" style="margin-top:10px">
                <button class="btn" type="submit" style="background:#b45309"><?php echo e($lang === 'en' ? 'Reboot system' : 'إعادة تشغيل النظام'); ?></button>
            </div>
        </form>
        <form method="post" onsubmit="return confirm(<?php echo json_encode($lang === 'en' ? 'SHUT DOWN the server now? This will take it offline.' : 'إطفاء السيرفر الآن؟ راح ينقطع بالكامل.'); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="section" value="system_power">
            <input type="hidden" name="power_action" value="shutdown">
            <label><?php echo e($lang === 'en' ? 'Admin password' : 'كلمة مرور الأدمن'); ?></label>
            <input type="password" name="admin_password" required autocomplete="current-password">
            <div class="actions" style="margin-top:10px">
                <button class="btn" type="submit" style="background:#b91c1c"><?php echo e($lang === 'en' ? 'Shutdown system' : 'إطفاء النظام'); ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'devices'):
    $devicesSystemOn = function_exists('devices_system_is_on') ? devices_system_is_on($s) : true;
?>
<div class="panel glass-panel">
    <form method="post" class="rent-toggle-card" id="devicesSystemForm" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:16px 18px;border-radius:16px;border:1px solid #e2e8f0;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.04);margin-bottom:14px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="devices_system">
        <div>
            <h2 style="margin:0"><?php echo e($lang === 'en' ? 'Network devices' : 'أجهزة الشبكة'); ?></h2>
            <p style="margin:4px 0 0;color:#64748b;font-weight:650;font-size:13px"><?php echo e($lang === 'en'
                ? ($devicesSystemOn ? 'On — agents can inventory and monitor radios.' : 'Off — devices menu is hidden.')
                : ($devicesSystemOn ? 'تشغيل — قائمة الأجهزة والمراقبة متاحة.' : 'إيقاف — قائمة الأجهزة مخفية.')); ?></p>
        </div>
        <label class="toggle" style="display:flex;align-items:center;gap:10px;margin:0">
            <input type="checkbox" name="devices_system_enabled" value="1" <?php echo $devicesSystemOn ? 'checked' : ''; ?> onchange="this.form.submit()">
            <span class="toggle-ui"></span>
            <span style="font-weight:800"><?php echo e($devicesSystemOn
                ? ($lang === 'en' ? 'Enabled' : 'تشغيل')
                : ($lang === 'en' ? 'Disabled' : 'إيقاف')); ?></span>
        </label>
    </form>
    <?php if ($devicesSystemOn): ?>
    <p class="meta" style="font-weight:700">
        <a href="devices.php"><?php echo e($lang === 'en' ? 'Open devices page' : 'افتح صفحة الأجهزة'); ?></a>
        —
        <?php echo e($lang === 'en'
            ? 'PowerBeam, LiteBeam, NanoStation, Mimosa, MikroTik — switches, APs and links.'
            : 'بور بيم، لايت بيم، نانو، ميموسا، مايكروتك — سويتشات ومرسلات ولنكات.'); ?>
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'rental'): ?>
<style>
.rent-toggle-card {
  display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;
  padding:16px 18px; border-radius:16px; border:1px solid #e2e8f0; background:#fff;
  box-shadow:0 10px 24px rgba(15,23,42,.04); margin-bottom:14px;
}
.rent-toggle-card h2 { margin:0; font-size:1.1rem; }
.rent-toggle-card p { margin:4px 0 0; color:#64748b; font-weight:650; font-size:13px; }
.rent-dev-list { display:flex; flex-direction:column; gap:10px; margin-top:12px; }
.rent-dev-row {
  display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
  padding:12px 14px; border:1px solid #e2e8f0; border-radius:14px; background:#fafbfc;
}
.rent-dev-meta { display:flex; align-items:center; gap:10px; min-width:0; }
.rent-dev-badge {
  width:36px; height:36px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center;
  color:#0f172a; font-weight:800; font-size:12px; flex:0 0 auto;
}
.rent-dev-badge .rent-glyph { width:18px; height:18px; display:block; }
.rent-dev-name { font-weight:800; color:#0f172a; }
.rent-dev-id { color:#64748b; font-size:12px; font-weight:700; direction:ltr; unicode-bidi:isolate; }
.rent-dev-actions { display:flex; gap:8px; flex-wrap:wrap; }
</style>
<?php if ($rentalIsNew || $rentalEditRow):
    $ed = $rentalEditRow ? $rentalEditRow : array('id' => '', 'name' => '', 'icon' => 'DV', 'color' => '#5e5ce6');
?>
<div class="panel glass-panel">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px">
        <h2 style="margin:0"><?php echo e($rentalIsNew
            ? ($lang === 'en' ? 'New device type' : 'نوع جهاز جديد')
            : (($lang === 'en' ? 'Edit device type' : 'تعديل نوع الجهاز') . ' — ' . $ed['name'])); ?></h2>
        <a class="btn secondary sm" href="settings.php?tab=rental"><?php echo e($lang === 'en' ? 'Back' : 'رجوع'); ?></a>
    </div>
    <p style="color:var(--muted);margin-top:0;font-weight:600">
        <?php echo e($lang === 'en'
            ? 'Saving or deleting this type does not change the other types.'
            : 'حفظ أو مسح هذا النوع ما يغيّر بقية الأنواع.'); ?>
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="rental_device_save">
        <input type="hidden" name="device_old_id" value="<?php echo e($rentalIsNew ? '' : $ed['id']); ?>">
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($lang === 'en' ? 'Name' : 'الاسم'); ?></label>
                <input name="device_name" value="<?php echo e($ed['name']); ?>" required>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Code / ID' : 'المعرّف'); ?></label>
                <input class="ltr" name="device_id" value="<?php echo e($ed['id']); ?>" required>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Badge' : 'الرمز'); ?></label>
                <input name="device_icon" value="<?php echo e($ed['icon']); ?>" maxlength="4">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Color' : 'اللون'); ?></label>
                <input type="color" name="device_color" value="<?php echo e($ed['color']); ?>">
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
    <?php if (!$rentalIsNew): ?>
    <form method="post" style="margin-top:14px" onsubmit="return confirm(<?php echo json_encode($lang === 'en' ? 'Delete this device type only?' : 'مسح نوع الجهاز هذا فقط؟'); ?>);">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="rental_device_delete">
        <input type="hidden" name="device_id" value="<?php echo e($ed['id']); ?>">
        <button class="btn secondary" type="submit"><?php echo e($lang === 'en' ? 'Delete this type' : 'مسح هذا النوع'); ?></button>
    </form>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="panel glass-panel">
    <form method="post" class="rent-toggle-card" id="rentalSystemForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="rental_system">
        <div>
            <h2><?php echo e($lang === 'en' ? 'Rental system' : 'نظام الإيجار'); ?></h2>
            <p><?php echo e($lang === 'en'
                ? ($rentalSystemOn ? 'On — rentals menu and subscriber rental tools are available.' : 'Off — rental features are hidden.')
                : ($rentalSystemOn ? 'تشغيل — قائمة الإيجار وأدوات إيجار المشترك متاحة.' : 'إيقاف — ميزات الإيجار مخفية.')); ?></p>
        </div>
        <label class="toggle" style="display:flex;align-items:center;gap:10px;margin:0">
            <input type="checkbox" name="rental_system_enabled" value="1" <?php echo $rentalSystemOn ? 'checked' : ''; ?> onchange="this.form.submit()">
            <span class="toggle-ui"></span>
            <span style="font-weight:800"><?php echo e($rentalSystemOn
                ? ($lang === 'en' ? 'Enabled' : 'تشغيل')
                : ($lang === 'en' ? 'Disabled' : 'إيقاف')); ?></span>
        </label>
    </form>

    <?php if ($rentalSystemOn): ?>
    <h2><?php echo e($lang === 'en' ? 'Rental devices' : 'أجهزة الإيجار'); ?></h2>
    <p style="color:var(--muted);margin-top:-6px;font-weight:600">
        <?php echo e($lang === 'en'
            ? 'Monthly rent is added to subscription when activating a subscriber with a rental device.'
            : 'مبلغ الإيجار الشهري يُضاف للاشتراك عند تفعيل مشترك عليه جهاز إيجار.'); ?>
    </p>
    <form method="post" style="margin-bottom:18px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="rental_fee">
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($lang === 'en' ? 'Monthly rental fee' : 'مبلغ الإيجار الشهري'); ?></label>
                <input type="number" name="rental_fee" min="0" step="1" value="<?php echo e((float) $rentalFee); ?>" required>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Save fee' : 'حفظ المبلغ'); ?></button>
        </div>
    </form>

    <h3 style="margin:8px 0 0;font-size:15px"><?php echo e($lang === 'en' ? 'Device types' : 'أنواع الأجهزة'); ?></h3>
    <p style="color:var(--muted);margin:6px 0 0;font-weight:600;font-size:13px">
        <?php echo e($lang === 'en'
            ? 'Each type opens on its own page. Edit or delete one without touching the others.'
            : 'كل نوع بصفحته. تعديل أو مسح واحد ما يمس الباقي.'); ?>
    </p>
    <div class="rent-dev-list">
        <?php foreach ($rentalDevices as $d): ?>
        <div class="rent-dev-row">
            <div class="rent-dev-meta">
                <span class="rent-dev-badge" style="background:<?php echo e($d['color']); ?>;color:#0f172a"><?php
                    $g = function_exists('rental_device_glyph_html') ? rental_device_glyph_html($d['id']) : '';
                    echo $g !== '' ? $g : e($d['icon']);
                ?></span>
                <div>
                    <div class="rent-dev-name"><?php echo e($d['name']); ?></div>
                    <div class="rent-dev-id"><?php echo e($d['id']); ?></div>
                </div>
            </div>
            <div class="rent-dev-actions">
                <a class="btn secondary sm" href="settings.php?tab=rental&device=<?php echo e(rawurlencode($d['id'])); ?>"><?php echo e($lang === 'en' ? 'Edit' : 'تعديل'); ?></a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$rentalDevices): ?>
        <p style="color:#64748b;font-weight:700"><?php echo e($lang === 'en' ? 'No device types yet.' : 'ماكو أنواع أجهزة بعد.'); ?></p>
        <?php endif; ?>
    </div>
    <div class="actions" style="margin-top:12px">
        <a class="btn" href="settings.php?tab=rental&device=new">+ <?php echo e($lang === 'en' ? 'Device type' : 'نوع جهاز'); ?></a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel glass-panel" style="margin-top:16px">
    <h2><?php echo e($lang === 'en' ? 'Subscriber device login (IP click)' : 'دخول جهاز المشترك (ضغط IP)'); ?></h2>
    <p style="color:var(--muted);margin-top:-6px;font-weight:600">
        <?php echo e($lang === 'en'
            ? 'Used when clicking a subscriber IP to open NanoStation / CPE login (ticket.cgi / login.cgi).'
            : 'يُستخدم عند ضغط IP المشترك لفتح دخول الجهاز (نانو / CPE) تلقائياً.'); ?>
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="cpe">
        <div class="form-grid cols-3">
            <div>
                <label><?php echo e($lang === 'en' ? 'Device username' : 'يوزر الجهاز'); ?></label>
                <input class="ltr" name="cpe_http_user"
                       value="<?php echo e(isset($s['cpe_http_user']) && $s['cpe_http_user'] !== '' ? $s['cpe_http_user'] : 'ubnt'); ?>"
                       placeholder="ubnt">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Device password' : 'باسورد الجهاز'); ?></label>
                <input class="ltr" name="cpe_http_pass"
                       value="<?php echo e(isset($s['cpe_http_pass']) ? $s['cpe_http_pass'] : 'ubnt'); ?>"
                       placeholder="ubnt">
            </div>
            <div>
                <label class="toggle" style="display:flex;align-items:center;gap:10px;margin-top:22px">
                    <input type="checkbox" name="cpe_use_https" value="1" <?php echo !isset($s['cpe_use_https']) || !empty($s['cpe_use_https']) ? 'checked' : ''; ?>>
                    <span class="toggle-ui"></span>
                    <span><?php echo e($lang === 'en' ? 'Prefer HTTPS' : 'تفضيل HTTPS'); ?></span>
                </label>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($tab === 'whatsapp'): ?>
<style>
.wa-page { display:flex; flex-direction:column; gap:16px; }
.wa-hero { display:grid; grid-template-columns: minmax(0,1.15fr) minmax(260px,380px); gap:18px; align-items:stretch; padding:22px; border-radius:20px; background:#fff; border:1px solid #e2e8f0; box-shadow:0 12px 32px rgba(15,23,42,.06); }
.wa-hero.is-linked { grid-template-columns: 1fr; }
.wa-hero.is-linked #wa-guide,
.wa-hero.is-linked #wa-qr { display: none; }
@media (max-width: 960px) { .wa-hero { grid-template-columns: 1fr; } }
.wa-kicker { margin:0 0 6px; color:#0f766e; font-size:12px; font-weight:800; letter-spacing:.02em; }
.wa-hero h2 { margin:0 0 8px; font-size:1.35rem; }
.wa-lead { margin:0; color:#64748b; font-weight:650; line-height:1.65; font-size:13.5px; }
.wa-steps { list-style:none; margin:16px 0 0; padding:0; display:flex; flex-direction:column; gap:10px; }
.wa-steps li { display:flex; gap:10px; align-items:flex-start; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:10px 12px; color:#334155; font-weight:700; font-size:13.5px; line-height:1.55; }
.wa-steps b { width:26px; height:26px; border-radius:999px; background:#0f172a; color:#fff; display:grid; place-items:center; flex:0 0 auto; font-size:12px; }
.wa-card { border:1px solid #e2e8f0; border-radius:16px; background:#fff; padding:18px; box-shadow:0 8px 24px rgba(15,23,42,.04); }
.wa-card h2 { margin:0 0 6px; font-size:1.05rem; }
.wa-card .wa-lead { margin:0 0 14px; }
.wa-tips { margin:14px 0 0; padding:12px 14px; border-radius:12px; background:#f0f9ff; border:1px solid #dbeafe; color:#334155; font-size:13px; font-weight:650; line-height:1.65; }
.wa-status-pill { display:flex; align-items:flex-start; gap:10px; padding:12px 14px; border-radius:12px; font-weight:800; margin-top:14px; border:1px solid transparent; line-height:1.5; }
.wa-status-pill.ok { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
.wa-status-pill.warn { background:#fffbeb; color:#b45309; border-color:#fde68a; }
.wa-status-pill.err { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
.wa-status-dot { width:10px; height:10px; border-radius:50%; background:currentColor; flex:0 0 auto; margin-top:6px; box-shadow:0 0 0 4px rgba(0,0,0,.05); }
.wa-qr-stage { border-radius:18px; border:1px dashed #cbd5e1; background:linear-gradient(180deg,#f8fafc,#eef2ff); min-height:340px; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:18px; text-align:center; }
.wa-qr-stage.is-ok { border-style:solid; border-color:#a7f3d0; background:linear-gradient(180deg,#f0fdf4,#ecfdf5); }
.wa-qr-stage.is-down { border-style:solid; border-color:#fecaca; background:linear-gradient(180deg,#fff,#fef2f2); }
.wa-qr-stage.is-scan { border-style:solid; border-color:#fde68a; background:#fffbeb; }
.wa-qr-stage img { width:min(260px,100%); height:auto; border-radius:12px; background:#fff; padding:10px; box-shadow:0 10px 30px rgba(15,23,42,.12); }
.wa-qr-stage .wa-qr-hint, #wa-qr-placeholder { margin-top:12px; color:#475569; font-weight:750; font-size:13.5px; line-height:1.6; max-width:32ch; }
.wa-qr-count { margin-top:10px; display:none; align-items:baseline; justify-content:center; gap:6px; color:#0f172a; font-weight:800; }
.wa-qr-count b { font-size:32px; line-height:1; font-variant-numeric:tabular-nums; min-width:2ch; }
.wa-qr-count span { font-size:13px; color:#475569; }
.wa-qr-count.is-low b { color:#b91c1c; }
.wa-qr-stage.is-ok #wa-qr-placeholder { color:#047857; }
.wa-qr-stage.is-down #wa-qr-placeholder { color:#b91c1c; }
.wa-actions-row { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
.wa-notify { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; }
.wa-notify .btn { margin-bottom:1px; }
.wa-cover-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:8px; }
.wa-cover-item { display:flex; gap:8px; align-items:center; padding:10px 12px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; font-weight:750; }
.wa-cover-item.stopped { color:#64748b; }
.wa-cover-item.has-wa { border-color:#86efac; background:#f0fdf4; }
.wa-cover-item.has-wa input { accent-color:#16a34a; }
.wa-cover-item.no-wa { border-color:#fca5a5; background:#fef2f2; }
.wa-cover-item.no-wa input { accent-color:#dc2626; }
.wa-cover-item .wa-mark { margin-inline-start:auto; font-size:11px; font-weight:700; }
.wa-cover-item.has-wa .wa-mark { color:#166534; }
.wa-cover-item.no-wa .wa-mark { color:#b91c1c; }
.wa-cover-head { margin:14px 0 8px; font-size:12px; font-weight:800; color:#64748b; }
.wa-aud { display:flex; flex-wrap:wrap; gap:8px; margin:10px 0 4px; }
.wa-aud label { display:flex; align-items:center; gap:8px; padding:10px 14px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; font-weight:800; cursor:pointer; }
.wa-aud input { accent-color:#0f766e; width:16px; height:16px; }
.wa-cover-tools { display:flex; flex-wrap:wrap; gap:8px; margin:12px 0 4px; }
.wa-cover-tools button { border:1px solid #cbd5e1; background:#fff; color:#0f172a; border-radius:10px; padding:8px 12px; font-weight:800; cursor:pointer; }
.wa-switch-row { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:12px 14px; border:1px solid #e2e8f0; border-radius:14px; background:#fff; }
.wa-switch-row p { margin:0; color:#334155; font-weight:750; font-size:13.5px; line-height:1.55; }
.wa-switch-side { display:flex; align-items:center; gap:10px; flex:0 0 auto; }
.wa-switch-word { font-size:15px; font-weight:800; color:#15803d; line-height:1; }
.wa-switch-word.is-off { color:#64748b; }
.wa-switch { position:relative; width:52px; height:30px; flex:0 0 auto; direction:ltr; }
.wa-switch input { position:absolute; opacity:0; width:0; height:0; }
.wa-switch i { position:absolute; inset:0; border-radius:999px; background:#cbd5e1; transition:.15s ease; cursor:pointer; }
.wa-switch i:before { content:""; position:absolute; width:24px; height:24px; top:3px; left:3px; border-radius:50%; background:#fff; transition:.15s ease; box-shadow:0 1px 3px rgba(15,23,42,.25); }
.wa-switch input:checked + i { background:#16a34a; }
.wa-switch input:checked + i:before { transform:translateX(22px); }
</style>

<?php
$waNotifyOn = true;
if (function_exists('whatsapp_notifications_enabled') && isset($pdo)) {
    $waNotifyOn = whatsapp_notifications_enabled($pdo);
}
?>
<div class="wa-page">
<form method="post" class="wa-switch-row">
  <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
  <input type="hidden" name="section" value="wa_notify">
  <input type="hidden" name="wa_notify" value="0">
  <p><?php echo e($lang === 'en'
      ? 'Use this to message your subscribers on WhatsApp: activation, expiry, and debt reminders.'
      : 'استخدم هذه الخاصية لإرسال رسائل لمشتركيك عبر واتساب، منها رسائل التفعيل والانتهاء وتذكير الديون.'); ?></p>
  <div class="wa-switch-side">
    <span class="wa-switch-word<?php echo $waNotifyOn ? '' : ' is-off'; ?>"><?php echo e($waNotifyOn ? ($lang === 'en' ? 'On' : 'شغال') : ($lang === 'en' ? 'Off' : 'متوقف')); ?></span>
    <label class="wa-switch" title="<?php echo e($waNotifyOn ? ($lang === 'en' ? 'On' : 'شغال') : ($lang === 'en' ? 'Off' : 'متوقف')); ?>">
      <input type="checkbox" name="wa_notify" value="1" <?php echo $waNotifyOn ? 'checked' : ''; ?> onchange="this.form.submit()">
      <i></i>
    </label>
  </div>
</form>
<?php if ($waNotifyOn): ?>
<section class="wa-hero">
  <div>
    <h2 id="wa-title"><?php echo e($lang === 'en' ? 'Link WhatsApp' : 'ربط واتساب'); ?></h2>
    <div id="wa-status" class="wa-status-pill warn"><span class="wa-status-dot"></span><span id="wa-status-text"><?php echo e($lang === 'en' ? 'Please wait until the QR code appears' : 'يرجى الانتظار لحين ظهور رمز QR'); ?></span></div>
    <div class="wa-actions-row" id="waActions">
      <button class="btn" type="button" id="waLogoutBtn"><?php echo e($lang === 'en' ? 'Reload' : 'إعادة تحميل'); ?></button>
    </div>
  </div>
  <div id="wa-qr" class="wa-qr-stage">
    <img id="wa-qr-img" alt="QR" style="display:none">
    <div id="wa-qr-count" class="wa-qr-count" aria-live="polite"><b id="wa-qr-sec">20</b><span><?php echo e($lang === 'en' ? 'seconds left' : 'ثانية وتنتهي صلاحية الرمز'); ?></span></div>
    <div id="wa-qr-placeholder"><?php echo e($lang === 'en' ? 'Please wait until the QR code appears' : 'يرجى الانتظار لحين ظهور رمز QR'); ?></div>
    <div class="wa-qr-hint" id="wa-qr-title" style="display:none"></div>
  </div>
</section>
<?php endif; ?>

<?php if ($isPlatformAdmin): ?>
<div class="wa-card">
  <h2><?php echo e(t('settings_whatsapp')); ?></h2>
  <form method="post" id="waForm">
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="section" value="whatsapp">
    <div class="form-grid">
      <div>
        <label><?php echo e($lang === 'en' ? 'Gateway URL' : 'رابط البوابة'); ?></label>
        <input class="ltr" id="gwUrl" name="whatsapp_local_url" value="<?php echo e($s['whatsapp_local_url']); ?>" required>
      </div>
      <div>
        <label><?php echo e($lang === 'en' ? 'Gateway key' : 'مفتاح البوابة'); ?></label>
        <input class="ltr" name="whatsapp_local_key" value="<?php echo e($s['whatsapp_local_key']); ?>" required>
      </div>
      <div>
        <label><?php echo e($lang === 'en' ? 'Enable WhatsApp' : 'تفعيل واتساب'); ?></label>
        <select name="whatsapp_enabled" required>
          <option value="1" <?php echo !empty($s['whatsapp_enabled']) ? 'selected' : ''; ?>><?php echo e($lang === 'en' ? 'ON' : 'تشغيل'); ?></option>
          <option value="0" <?php echo empty($s['whatsapp_enabled']) ? 'selected' : ''; ?>><?php echo e($lang === 'en' ? 'OFF' : 'إيقاف'); ?></option>
        </select>
      </div>
    </div>
    <div class="actions" style="margin-top:12px">
      <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
    </div>
  </form>
</div>
<?php endif; ?>
<?php
$waCoverTargets = ($waNotifyOn && function_exists('whatsapp_cover_targets')) ? whatsapp_cover_targets($pdo) : array();
$waMe = function_exists('current_admin') ? current_admin() : null;
$waMeId = $waMe ? (int) $waMe['id'] : 0;
$waCoverOn = ($waMeId > 0 && function_exists('whatsapp_cover_selected')) ? whatsapp_cover_selected($pdo, $waMeId) : array();
$waAud = ($waMeId > 0 && function_exists('whatsapp_audience_mode')) ? whatsapp_audience_mode($pdo, $waMeId) : 'agents';
if ($waNotifyOn):
    usort($waCoverTargets, function ($a, $b) {
        $an = isset($a['display_name']) && trim((string) $a['display_name']) !== '' ? (string) $a['display_name'] : (string) $a['username'];
        $bn = isset($b['display_name']) && trim((string) $b['display_name']) !== '' ? (string) $b['display_name'] : (string) $b['username'];
        return strcasecmp($an, $bn);
    });
?>
<?php
$waCoverLive = array();
$waCoverStopped = array();
foreach ($waCoverTargets as $tgt) {
    if (isset($tgt['is_active']) && (int) $tgt['is_active'] !== 1) {
        $waCoverStopped[] = $tgt;
    } else {
        $waCoverLive[] = $tgt;
    }
}
$waCoverGroups = array(
    array('title' => ($lang === 'en' ? 'Active agents' : 'وكلاء شغالين'), 'rows' => $waCoverLive, 'stopped' => false),
    array('title' => ($lang === 'en' ? 'Stopped agents' : 'وكلاء موقوفين'), 'rows' => $waCoverStopped, 'stopped' => true),
);
?>
<div class="wa-card">
    <h2><?php echo e($lang === 'en' ? 'Who this number covers' : 'من يشملهم هذا الرقم'); ?></h2>
    <p class="wa-lead"><?php echo e($lang === 'en'
        ? 'Pick who this linked number notifies. Agent boxes are for a card-transfer message and a short statement. Subscriber notices go out a few at a time.'
        : 'اختر من يوصلهم هذا الرقم بعد الربط. مربعات الوكلاء لإشعار تحويل الكروت وكشف حساب قصير. إشعار المشتركين ينرسل على دفعات صغيرة حتى ما يثقل الصفحة.'); ?></p>
    <form method="post" id="waCoverForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="wa_cover">
        <div class="wa-aud">
            <label><input type="radio" name="wa_audience" value="subscribers" <?php echo $waAud === 'subscribers' ? 'checked' : ''; ?>><?php echo e($lang === 'en' ? 'Subscriber notices' : 'إشعار المشتركين'); ?></label>
            <label><input type="radio" name="wa_audience" value="agents" <?php echo $waAud === 'agents' ? 'checked' : ''; ?>><?php echo e($lang === 'en' ? 'Agent notices' : 'إشعار الوكلاء'); ?></label>
            <label><input type="radio" name="wa_audience" value="both" <?php echo $waAud === 'both' ? 'checked' : ''; ?>><?php echo e($lang === 'en' ? 'Both' : 'كلاهما'); ?></label>
        </div>
        <?php if ($waCoverTargets): ?>
        <div class="wa-cover-tools">
            <button type="button" id="waCoverAll"><?php echo e($lang === 'en' ? 'Select all' : 'تحديد الكل'); ?></button>
            <button type="button" id="waCoverNone"><?php echo e($lang === 'en' ? 'Clear all' : 'إلغاء تحديد الكل'); ?></button>
        </div>
        <?php endif; ?>
        <?php foreach ($waCoverGroups as $grp):
            if (!$grp['rows']) {
                continue;
            }
            ?>
            <div class="wa-cover-head"><?php echo e($grp['title']); ?></div>
            <div class="wa-cover-grid">
            <?php foreach ($grp['rows'] as $tgt):
                $tidOpt = (int) $tgt['id'];
                $tlabel = (isset($tgt['display_name']) && trim((string) $tgt['display_name']) !== '')
                    ? (string) $tgt['display_name'] : (string) $tgt['username'];
                $hasWa = isset($tgt['phone']) && trim((string) $tgt['phone']) !== '';
                $coverCls = 'wa-cover-item' . ($grp['stopped'] ? ' stopped' : '') . ($hasWa ? ' has-wa' : ' no-wa');
                ?>
                <label class="<?php echo e($coverCls); ?>">
                    <input type="checkbox" name="wa_cover[]" value="<?php echo $tidOpt; ?>" <?php echo !empty($waCoverOn[$tidOpt]) ? 'checked' : ''; ?>>
                    <span><?php echo e($tlabel); ?><?php echo $grp['stopped'] ? e($lang === 'en' ? ' · stopped' : ' · موقوف') : ''; ?></span>
                    <span class="wa-mark"><?php echo e($hasWa ? ($lang === 'en' ? 'WhatsApp' : 'واتساب') : ($lang === 'en' ? 'No WhatsApp' : 'بدون واتساب')); ?></span>
                </label>
            <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="actions" style="margin-top:12px">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>
</div>

<?php if ($waNotifyOn): ?>
<script>
(function () {
  var urlInput = document.getElementById('gwUrl');
  var hostEl = document.getElementById('gwHost');
  function syncHost() {
    if (!hostEl || !urlInput) return;
    try {
      var u = new URL(urlInput.value);
      hostEl.textContent = u.hostname || urlInput.value;
    } catch (e) {
      hostEl.textContent = urlInput.value;
    }
  }
  if (urlInput) urlInput.addEventListener('input', syncHost);
  var coverAll = document.getElementById('waCoverAll');
  var coverNone = document.getElementById('waCoverNone');
  function setCover(on) {
    var boxes = document.querySelectorAll('#waCoverForm input[name="wa_cover[]"]');
    for (var i = 0; i < boxes.length; i++) boxes[i].checked = on;
  }
  if (coverAll) coverAll.addEventListener('click', function () { setCover(true); });
  if (coverNone) coverNone.addEventListener('click', function () { setCover(false); });
})();

var waBusy = false;
var qrWaitTimer = null;
var waAutoResetDone = false;
var waLinked = false;
var waExpect = <?php echo json_encode(function_exists('whatsapp_session_id') ? (string) whatsapp_session_id() : ''); ?>;
function waMine(data) {
  return !!(data && waExpect && data.session && String(data.session) === String(waExpect));
}
var L = {
  titleOn: <?php echo json_encode($lang === 'en' ? 'WhatsApp connected' : 'واتساب متصل'); ?>,
  titleOff: <?php echo json_encode($lang === 'en' ? 'Link WhatsApp' : 'ربط واتساب'); ?>,
  connected: <?php echo json_encode($lang === 'en' ? 'Connected' : 'متصل'); ?>,
  connectedBox: <?php echo json_encode($lang === 'en' ? 'Linked.' : 'الربط محفوظ.'); ?>,
  scanBelow: <?php echo json_encode($lang === 'en' ? 'WhatsApp → Linked devices → Link a device.' : 'واتساب ← الأجهزة المرتبطة ← ربط جهاز.'); ?>,
  waitQr: <?php echo json_encode($lang === 'en' ? 'Please wait until the QR code appears' : 'يرجى الانتظار لحين ظهور رمز QR'); ?>,
  gatewayDown: <?php echo json_encode($lang === 'en' ? 'WhatsApp is not connected.' : 'واتساب غير متصل.'); ?>,
  lost: <?php echo json_encode($lang === 'en' ? 'The phone unlinked this device.' : 'الموبايل فك الربط.'); ?>,
  needOnce: <?php echo json_encode($lang === 'en' ? 'This account is not linked yet.' : 'هذا الحساب بعد ما مربوط.'); ?>,
  waiting: <?php echo json_encode($lang === 'en' ? 'Please wait until the QR code appears' : 'يرجى الانتظار لحين ظهور رمز QR'); ?>,
  scanNew: <?php echo json_encode($lang === 'en' ? 'New picture ready' : 'صورة جديدة جاهزة'); ?>,
  btnReload: <?php echo json_encode($lang === 'en' ? 'Reload' : 'إعادة تحميل'); ?>,
  btnDisconnect: <?php echo json_encode($lang === 'en' ? 'Disconnect' : 'قطع الاتصال'); ?>,
  confirmLogout: <?php echo json_encode($lang === 'en' ? 'Disconnect this WhatsApp link?' : 'تقطع ربط واتساب؟'); ?>,
  loggingOut: <?php echo json_encode($lang === 'en' ? 'Please wait until the QR code appears' : 'يرجى الانتظار لحين ظهور رمز QR'); ?>,
  certExpired: <?php echo json_encode($lang === 'en'
    ? 'Certificate problem on the PC. The gateway retries by itself. Wait, do not refresh many times.'
    : 'مشكلة شهادة على الحاسبة. البوابة تعيد المحاولة وحدها. انتظر ولا تحدّث الصفحة كثير.'); ?>,
  tlsRetry: <?php echo json_encode($lang === 'en' ? 'Please wait until the QR code appears' : 'يرجى الانتظار لحين ظهور رمز QR'); ?>
};

function setStatus(cls, text) {
  var box = document.getElementById('wa-status');
  var tx = document.getElementById('wa-status-text');
  box.className = 'wa-status-pill ' + cls;
  if (tx) tx.textContent = text; else box.textContent = text;
}
var lastQrUrl = '';
var qrDeadline = 0;
var qrTicker = null;
var QR_TTL_MS = 20000;
function qrLeftOf(data) {
  if (!data) return 0;
  if (typeof data.qr_left_ms === 'number') return data.qr_left_ms;
  var ttl = (typeof data.qr_ttl_ms === 'number' && data.qr_ttl_ms > 0) ? data.qr_ttl_ms : QR_TTL_MS;
  var age = (typeof data.qr_age_ms === 'number') ? data.qr_age_ms : 0;
  return Math.max(0, ttl - age);
}
function clearQrCount() {
  if (qrTicker) { clearInterval(qrTicker); qrTicker = null; }
  qrDeadline = 0;
  var box = document.getElementById('wa-qr-count');
  if (box) box.style.display = 'none';
}
function paintQrCount() {
  var box = document.getElementById('wa-qr-count');
  var num = document.getElementById('wa-qr-sec');
  if (!box || !qrDeadline) return;
  var sec = Math.max(0, Math.ceil((qrDeadline - Date.now()) / 1000));
  box.style.display = 'flex';
  box.className = 'wa-qr-count' + (sec <= 5 ? ' is-low' : '');
  if (num) num.textContent = String(sec);
  if (sec <= 0) {
    clearQrCount();
    lastQrUrl = '';
    showWaitingBox(L.waitQr, '');
    setStatus('warn', L.waitQr);
    checkWhatsApp();
  }
}
function armQrCount(leftMs, force) {
  var left = parseInt(leftMs, 10);
  if (!(left > 0)) left = QR_TTL_MS;
  var next = Date.now() + left;
  if (force || !qrDeadline || next < qrDeadline - 400) qrDeadline = next;
  if (!qrTicker) qrTicker = setInterval(paintQrCount, 200);
  paintQrCount();
}
function syncWaBtn(linked) {
  var btn = document.getElementById('waLogoutBtn');
  var row = document.getElementById('waActions');
  if (row) row.style.display = '';
  if (!btn) return;
  waLinked = !!linked;
  if (linked) {
    btn.textContent = L.btnDisconnect;
    btn.className = 'btn danger';
  } else {
    btn.textContent = L.btnReload;
    btn.className = 'btn';
  }
}
function paintConnected(on) {
  syncWaBtn(on);
  var hero = document.querySelector('.wa-hero');
  if (hero) hero.classList.toggle('is-linked', !!on);
  var title = document.getElementById('wa-title');
  if (title) title.textContent = on ? L.titleOn : L.titleOff;
}
function setHint(text) {
  var hint = document.getElementById('wa-qr-title');
  if (!hint) return;
  if (!text) {
    hint.style.display = 'none';
    hint.textContent = '';
    return;
  }
  hint.style.display = '';
  hint.textContent = text;
}
function stageMode(mode) {
  var stage = document.getElementById('wa-qr');
  if (stage) stage.className = 'wa-qr-stage' + (mode ? (' is-' + mode) : '');
}
function showQr(dataUrl, leftMs) {
  var img = document.getElementById('wa-qr-img');
  var ph = document.getElementById('wa-qr-placeholder');
  if (!dataUrl || !img) return false;
  var left = parseInt(leftMs, 10);
  if (!(left > 0)) left = QR_TTL_MS;
  stageMode('scan');
  var changed = dataUrl !== lastQrUrl;
  if (changed) {
    lastQrUrl = dataUrl;
    img.src = dataUrl;
    setHint(L.scanBelow);
    qrDeadline = 0;
  }
  img.style.display = 'inline-block';
  if (ph) ph.style.display = 'none';
  armQrCount(left, changed);
  return true;
}
function showWaitingBox(text, mode) {
  var img = document.getElementById('wa-qr-img');
  var ph = document.getElementById('wa-qr-placeholder');
  lastQrUrl = '';
  clearQrCount();
  stageMode(mode || '');
  if (img) {
    img.style.display = 'none';
    img.removeAttribute('src');
  }
  setHint('');
  if (ph) {
    ph.style.display = 'block';
    ph.textContent = text || L.waiting;
  }
}
function paintState(data) {
  if (!data || data.success === false || data.status === 'gateway_down') {
    paintConnected(false);
    var down = (data && data.error) ? data.error : L.gatewayDown;
    setStatus('err', down);
    showWaitingBox(down, 'down');
    return 'stop';
  }
  if ((waMine(data) || !waExpect) && data.ready) {
    waAutoResetDone = false;
    paintConnected(true);
    setStatus('ok', L.connected + (data.phone ? (' — ' + data.phone) : ''));
    showWaitingBox(L.connectedBox, 'ok');
    return 'stop';
  }
  paintConnected(false);
  if (data.qr_data_url && showQr(data.qr_data_url, qrLeftOf(data))) {
    setStatus('warn', L.scanBelow);
    return 'stop';
  }
  if (data.registered || data.status === 'reconnecting') {
    setStatus('warn', L.waitQr);
    if (!lastQrUrl) showWaitingBox(L.waitQr, '');
    /* جلسة محفوظة بدون اتصال = امسحها فوراً واطلب QR (مثل باقي الحسابات) */
    if (!waAutoResetDone && !waBusy) {
      waAutoResetDone = true;
      reloadWaQr(false);
    }
    return 'stop';
  }
  if (data.status === 'qr_wait' || data.status === 'logout_cooldown' || data.status === 'need_link'
      || data.status === 'connecting' || data.status === 'starting' || data.status === 'tls_retry') {
    setStatus('warn', L.waitQr);
    if (!lastQrUrl) showWaitingBox(L.waitQr, '');
    return 'qr';
  }
  if (data.status === 'cert_expired') {
    setStatus('warn', L.certExpired);
    showWaitingBox(L.certExpired, '');
    return 'stop';
  }
  if (data.status === 'closed_401') {
    setStatus('warn', L.lost);
    showWaitingBox(L.lost, '');
    return 'qr';
  }
  setStatus('warn', L.waitQr);
  if (!lastQrUrl) showWaitingBox(L.waitQr, '');
  return 'qr';
}
var waChecking = false;
function checkWhatsApp() {
  if (waBusy || waChecking) return;
  waChecking = true;
  fetch('wa_proxy.php?action=status&_=' + Date.now())
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (paintState(data) !== 'qr') return null;
      return fetch('wa_proxy.php?action=qr&_=' + Date.now()).then(function (r) { return r.json(); });
    })
    .then(function (qr) {
      if (!qr) return;
      paintState(qr);
    })
    .catch(function () {
      setStatus('err', L.gatewayDown);
      if (!lastQrUrl) showWaitingBox(L.gatewayDown, 'down');
    })
    .then(function () { waChecking = false; }, function () { waChecking = false; });
}
function waitForQr(tries) {
  if (tries <= 0) {
    waBusy = false;
    setStatus('warn', L.waiting);
    showWaitingBox(L.waiting, '');
    return;
  }
  fetch('wa_proxy.php?action=qr&_=' + Date.now())
    .then(function (r) { return r.json(); })
    .then(function (qr) {
      if (qr && qr.ready) {
        paintState(qr);
        waBusy = false;
        return;
      }
      if (qr && qr.qr_data_url && showQr(qr.qr_data_url, qrLeftOf(qr))) {
        paintConnected(false);
        setStatus('warn', L.scanNew);
        waBusy = false;
        return;
      }
      if (qr && (qr.success === false || qr.status === 'gateway_down')) {
        setStatus('err', (qr && qr.error) ? qr.error : L.gatewayDown);
        showWaitingBox((qr && qr.error) ? qr.error : L.gatewayDown, 'down');
        qrWaitTimer = setTimeout(function () { waitForQr(tries - 1); }, 4000);
        return;
      }
      /* registered بعد القطع = البوابة بعد تمسح — نكمل الانتظار */
      setStatus('warn', L.waitQr);
      if (!lastQrUrl) showWaitingBox(L.waitQr, '');
      qrWaitTimer = setTimeout(function () { waitForQr(tries - 1); }, 2000);
    })
    .catch(function () {
      setStatus('err', L.gatewayDown);
      showWaitingBox(L.gatewayDown, 'down');
      qrWaitTimer = setTimeout(function () { waitForQr(tries - 1); }, 4000);
    });
}
function reloadWaQr(askConfirm) {
  if (askConfirm && !confirm(L.confirmLogout)) return;
  if (qrWaitTimer) clearTimeout(qrWaitTimer);
  waBusy = true;
  waAutoResetDone = true;
  lastQrUrl = '';
  setStatus('warn', L.waitQr);
  showWaitingBox(L.waitQr, '');
  fetch('wa_proxy.php?action=logout&_=' + Date.now())
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.error && data.success === false) {
        setStatus('err', data.error);
        showWaitingBox(data.error, 'down');
        waBusy = false;
        return;
      }
      setTimeout(function () { waitForQr(30); }, 2000);
    })
    .catch(function () {
      setStatus('err', L.gatewayDown);
      showWaitingBox(L.gatewayDown, 'down');
      waBusy = false;
    });
}
function logoutWhatsApp() {
  reloadWaQr(true);
}
(function () {
  var btn = document.getElementById('waLogoutBtn');
  if (btn) {
    btn.addEventListener('click', function () {
      if (waLinked) reloadWaQr(true);
      else reloadWaQr(false);
    });
  }
})();
paintConnected(false);
checkWhatsApp();
setInterval(function () {
  if (!waBusy) checkWhatsApp();
}, 2000);
</script>
<?php endif; ?>
<?php endif; ?>

<?php if ($tab === 'sas'): ?>
<?php
$isEn = ($lang === 'en');
$parentVal = (int) (isset($sasCfgUi['parent_id']) ? $sasCfgUi['parent_id'] : 0);
$unitsVal = (int) (isset($sasCfgUi['activate_units']) ? $sasCfgUi['activate_units'] : 1);
$defPassVal = isset($sasCfgUi['default_password']) && (string) $sasCfgUi['default_password'] !== ''
    ? (string) $sasCfgUi['default_password']
    : '1234';
$sasTitle = ($sasTenantIdUi > 1)
    ? ($isEn ? 'Reseller link' : 'ربط رسلر')
    : t('settings_sas');
$stOk = $sasConnStatus && !empty($sasConnStatus['ok']);
$stReady = $sasConnStatus && !empty($sasConnStatus['ready']);
$stLabel = $sasConnStatus && isset($sasConnStatus['label']) ? $sasConnStatus['label'] : '';
$stDetail = $sasConnStatus && isset($sasConnStatus['detail']) ? $sasConnStatus['detail'] : '';
$sasCompanyCatalog = array();
$sasCompanyIdUi = 0;
if ($sasTenantIdUi > 1) {
    $sasCompanyCatalog = function_exists('tenants_sas_company_catalog')
        ? tenants_sas_company_catalog($pdo, $config)
        : array();
    $rowUi = function_exists('tenant_row') ? tenant_row($pdo, $sasTenantIdUi) : null;
    if ($rowUi && !empty($rowUi['sas_company_id'])) {
        $sasCompanyIdUi = (int) $rowUi['sas_company_id'];
    } elseif (!empty($sasCfgUi['host']) && $sasCompanyCatalog) {
        foreach ($sasCompanyCatalog as $co) {
            $coHost = preg_replace('#^https?://#i', '', rtrim(trim((string) $co['sas_host']), '/'));
            if ($coHost !== '' && strcasecmp($coHost, (string) $sasCfgUi['host']) === 0) {
                $sasCompanyIdUi = (int) $co['id'];
                break;
            }
        }
    }
}
?>
<div class="panel">
    <h2><?php echo e($sasTitle); ?></h2>
    <?php
    if (function_exists('sas_ping_mode_style')) {
        echo sas_ping_mode_style();
    }
    ?>
    <?php if ($sasTenantIdUi <= 1): ?>
    <p style="color:#6b7a88;font-weight:600;margin-top:0">
        <?php echo e($isEn
            ? 'Enter your SAS reseller login. Activation here creates/activates the subscriber on SAS.'
            : 'أدخل بيانات دخول لوحة الساس. التفعيل من هذا النظام ينشئ ويفعّل المشترك على الساس.'); ?>
    </p>
    <?php endif; ?>
    <?php if ($sasTenantIdUi > 1): ?>
        </form>
        <?php
        if (function_exists('ensure_tenant_sas_accounts_schema')) {
            ensure_tenant_sas_accounts_schema($pdo);
        }
        $sasAccountsList = function_exists('tenant_sas_accounts_list')
            ? tenant_sas_accounts_list($pdo, $sasTenantIdUi)
            : array();
        ?>
        <style>
        .sas-acc-list { display:flex; flex-direction:column; gap:10px; margin:8px 0 16px; }
        .sas-acc-card {
          display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
          border:1px solid #e6ebf2; border-radius:14px; padding:12px 14px; background:#fff;
        }
        .sas-acc-name { font-weight:800; }
        .sas-acc-meta { color:#64748b; font-size:13px; margin-top:2px; }
        .sas-acc-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .sas-logged {
          background:#dcfce7; color:#166534; font-weight:800; border-radius:999px; padding:4px 10px; font-size:13px;
        }
        .sas-logged-off { background:#f1f5f9; color:#64748b; font-weight:700; border-radius:999px; padding:4px 10px; font-size:13px; }
        .sas-logged-bad { background:#fee2e2; color:#991b1b; font-weight:800; border-radius:999px; padding:4px 10px; font-size:13px; }
        .sas-retry-btn {
          background:#0f766e; color:#fff; border:0; border-radius:999px; font-weight:800;
          padding:6px 14px; box-shadow:0 6px 16px rgba(15,118,110,.22);
        }
        .sas-retry-btn:hover { background:#0d9488; color:#fff; }
        .sas-retry-btn:disabled { opacity:.65; cursor:wait; }
        .sas-acc-err {
          margin-top:8px; padding:8px 10px; border-radius:10px;
          background:#fef2f2; color:#991b1b; font-size:12.5px; font-weight:700;
          line-height:1.45; word-break:break-word; max-width:42rem;
        }
        .sas-acc-retry-meta {
          margin-top:4px; color:#9a3412; font-size:11.5px; font-weight:600;
        }
        </style>
        <?php if (!$sasAccountsList): ?>
        <p class="meta"><?php echo e($isEn ? 'No reseller yet. Add one below.' : 'ماكو ريسيلر بعد. أضف واحد من الزر.'); ?></p>
        <div class="sas-acc-ping" style="margin:8px 0 14px;opacity:.7">
            <div class="sas-acc-meta" style="margin-bottom:4px"><?php echo e($isEn ? 'Subscriber ping (locked)' : 'بينغ المشتركين (مقفل)'); ?></div>
            <?php
            if (function_exists('sas_ping_mode_buttons')) {
                echo sas_ping_mode_buttons($isEn, 'sas', 'subPingModeLocked', 0, true);
            }
            ?>
        </div>
        <?php else: ?>
        <div class="sas-acc-list">
            <?php
            $seenSasAcc = array();
            $liveSas = function_exists('sas_connection_status')
                ? sas_connection_status($pdo, $config, $sasTenantIdUi, false)
                : array();
            $liveKey = '';
            if (function_exists('sas_config_for_tenant') && function_exists('tenant_sas_account_key')) {
                $liveCfg = sas_config_for_tenant($pdo, $config, $sasTenantIdUi);
                $liveKey = tenant_sas_account_key(
                    isset($liveCfg['host']) ? $liveCfg['host'] : '',
                    isset($liveCfg['username']) ? $liveCfg['username'] : ''
                );
            }
            foreach ($sasAccountsList as $acc):
                $accKey = function_exists('tenant_sas_account_key')
                    ? tenant_sas_account_key($acc['sas_host'], $acc['sas_username'])
                    : '';
                if ($accKey !== '') {
                    if (isset($seenSasAcc[$accKey])) {
                        continue;
                    }
                    $seenSasAcc[$accKey] = 1;
                }
                $isElAcc = function_exists('tenant_sas_account_is_earthlink')
                    && tenant_sas_account_is_earthlink($pdo, $acc);
                $logged = !empty($acc['last_ok_at']) && trim((string) (isset($acc['last_error']) ? $acc['last_error'] : '')) === '';
                $loginFail = trim((string) (isset($acc['last_error']) ? $acc['last_error'] : '')) !== '';
                $liveDetail = (is_array($liveSas) && isset($liveSas['detail'])) ? (string) $liveSas['detail'] : '';
                // حالة الساس الحية تخص حسابات SAS فقط — لا تلصّق فشل الساس على بطاقة إيرث
                $liveDown = (!$isElAcc && $accKey !== '' && $accKey === $liveKey
                    && is_array($liveSas) && !empty($liveSas['at']) && empty($liveSas['ok']));
                if ($liveDown) {
                    $logged = false;
                    $loginFail = true;
                    if ($liveDetail !== '') {
                        $acc['last_error'] = $liveDetail;
                    }
                }
                $accName = trim((string) $acc['label']) !== '' ? $acc['label'] : $acc['sas_username'];
                ?>
            <div class="sas-acc-card">
                <div>
                    <div class="sas-acc-name"><?php echo e($accName); ?></div>
                    <div class="sas-acc-meta ltr"><?php echo e($acc['sas_username']); ?> · <?php echo e($acc['sas_host']); ?></div>
                    <?php
                    $accKind = (isset($acc['service_kind']) && $acc['service_kind'] === 'ftth') ? 'FTTH' : 'Wireless';
                    ?>
                    <div class="sas-acc-meta"><?php echo e($isEn ? 'Service' : 'الخدمة'); ?>: <?php echo e($accKind); ?></div>
                    <?php if (isset($acc['act_via']) && $acc['act_via'] === 'user'): ?>
                    <div class="sas-acc-meta" style="color:#9a3412;font-weight:700;margin-top:4px">
                        <?php echo e($isEn
                            ? 'Activation was found disabled by reseller management; a second activation method will be used.'
                            : 'تم الكشف عن وجود تعطيل في خاصية التفعيل من قبل ادارة الرسلر , سيتم اعتماد طريقة تفعيل ثانية'); ?>
                    </div>
                    <?php endif; ?>
                    <?php
                    $accBank = '—';
                    $accHost = preg_replace('#^https?://#i', '', rtrim(trim((string) $acc['sas_host']), '/'));
                    if ($accHost !== '' && function_exists('system_latency_to_host')) {
                        $latAcc = system_latency_to_host($accHost);
                        $msAcc = isset($latAcc['ms']) ? $latAcc['ms'] : null;
                        if ($msAcc !== null && $msAcc !== '') {
                            $accBank = number_format((float) $msAcc, 0) . ' ms';
                        }
                    }
                    ?>
                    <div class="sas-acc-meta"><?php echo e($isEn ? 'Latency' : 'زمن الاستجابة'); ?>: <span class="ltr"><?php echo e($accBank); ?></span></div>
                    <div class="sas-acc-ping">
                        <div class="sas-acc-meta" style="margin-bottom:4px"><?php echo e($isEn ? 'Subscriber ping' : 'بينغ المشتركين'); ?></div>
                        <?php
                        $accPingMode = function_exists('sas_ping_mode_for_account')
                            ? sas_ping_mode_for_account($pdo, (int) $acc['id'])
                            : 'off';
                        if (function_exists('sas_ping_mode_buttons')) {
                            echo sas_ping_mode_buttons(
                                $isEn,
                                $accPingMode,
                                'subPingMode-' . (int) $acc['id'],
                                (int) $acc['id'],
                                false
                            );
                        }
                        ?>
                    </div>
                    <?php
                    $failBlobEarly = isset($acc['last_error']) ? trim((string) $acc['last_error']) : '';
                    $failCountUi = isset($acc['login_fail_count']) ? (int) $acc['login_fail_count'] : 0;
                    $nextRetryUi = isset($acc['login_next_retry_at']) ? trim((string) $acc['login_next_retry_at']) : '';
                    if ($loginFail && $failBlobEarly !== ''):
                    ?>
                    <div class="sas-acc-err"><?php
                        $failShow = $failBlobEarly;
                        if ($failShow === 'OTP_REQUIRED' || $failShow === 'OTP_INVALID' || strpos($failShow, 'OTP_REQUIRED') !== false) {
                            $failShow = $isEn ? 'Authenticator code required' : 'يلزم رمز المصادقة';
                        }
                        echo e($failShow);
                    ?></div>
                    <?php if ($failBlobEarly === 'OTP_REQUIRED' || $failBlobEarly === 'OTP_INVALID' || strpos($failBlobEarly, 'OTP_REQUIRED') !== false): ?>
                    <form method="post" style="margin-top:8px">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="section" value="sas">
                        <input type="hidden" name="action" value="sas_otp_open">
                        <input type="hidden" name="account_id" value="<?php echo (int) $acc['id']; ?>">
                        <button class="btn sm" type="submit"><?php echo e($isEn ? 'Enter code' : 'إدخال الرمز'); ?></button>
                    </form>
                    <?php endif; ?>
                    <?php
                    if ($nextRetryUi !== '') {
                        $nextTs = strtotime($nextRetryUi);
                        $waitSec = $nextTs ? max(0, $nextTs - time()) : 0;
                        if ($waitSec > 0) {
                            $waitLabel = ($waitSec >= 60)
                                ? (ceil($waitSec / 60) . ($isEn ? ' min' : ' د'))
                                : ($waitSec . ($isEn ? 's' : ' ث'));
                            echo '<div class="sas-acc-retry-meta">'
                                . e($isEn
                                    ? ('Auto-retry in ' . $waitLabel . ($failCountUi > 0 ? (' · try #' . $failCountUi) : ''))
                                    : ('إعادة تلقائية بعد ' . $waitLabel . ($failCountUi > 0 ? (' · محاولة #' . $failCountUi) : '')))
                                . '</div>';
                        } else {
                            echo '<div class="sas-acc-retry-meta">'
                                . e($isEn ? 'Auto-retry soon' : 'إعادة تلقائية قريباً')
                                . '</div>';
                        }
                    } else {
                        echo '<div class="sas-acc-retry-meta">'
                            . e($isEn ? 'Background retries are scheduled' : 'إعادة المحاولة التلقائية مفعّلة')
                            . '</div>';
                    }
                    ?>
                    <?php endif; ?>
                </div>
                <div class="sas-acc-actions">
                    <?php if ($logged): ?>
                    <span class="sas-logged"><?php echo e($isEn ? 'Connected' : 'متصل'); ?></span>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="section" value="sas">
                        <input type="hidden" name="action" value="logout_account">
                        <input type="hidden" name="account_id" value="<?php echo (int) $acc['id']; ?>">
                        <button class="btn ghost sm" type="submit"><?php echo e($isEn ? 'Disconnect' : 'قطع الربط'); ?></button>
                    </form>
                    <?php elseif ($loginFail): ?>
                    <?php
                    $failLabel = $isEn ? 'Login failed' : 'فشل الدخول';
                    $failBlob = $failBlobEarly;
                    if ($liveDown && (stripos($failBlob, '429') !== false || stripos($failBlob, 'too_many') !== false)) {
                        $failLabel = $isEn ? 'Too many tries' : 'محاولات كثيرة';
                    } elseif ($liveDown) {
                        $failLabel = $isEn ? 'Not connected' : 'غير متصل';
                    }
                    ?>
                    <span class="sas-logged-bad" title="<?php echo e($failBlob); ?>"><?php echo e($failLabel); ?></span>
                    <form method="post" class="inline-form sas-retry-form">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="section" value="sas">
                        <input type="hidden" name="action" value="retry_login">
                        <input type="hidden" name="account_id" value="<?php echo (int) $acc['id']; ?>">
                        <button class="btn sm sas-retry-btn" type="submit"><?php echo e($isEn ? 'Retry' : 'إعادة محاولة'); ?></button>
                    </form>
                    <?php else: ?>
                    <span class="sas-logged-off"><?php echo e($isEn ? 'Not connected' : 'غير متصل'); ?></span>
                    <form method="post" class="inline-form sas-retry-form">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="section" value="sas">
                        <input type="hidden" name="action" value="retry_login">
                        <input type="hidden" name="account_id" value="<?php echo (int) $acc['id']; ?>">
                        <button class="btn sm sas-retry-btn" type="submit"><?php echo e($isEn ? 'Retry' : 'إعادة محاولة'); ?></button>
                    </form>
                    <?php endif; ?>
                    <form method="post" class="inline-form" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Delete this reseller?' : 'حذف هذا الريسيلر؟'); ?>);">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="section" value="sas">
                        <input type="hidden" name="action" value="delete_account">
                        <input type="hidden" name="account_id" value="<?php echo (int) $acc['id']; ?>">
                        <button class="btn danger sm" type="submit"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <script>
        (function () {
          var csrf = <?php echo json_encode(csrf_token()); ?>;
          var boxes = document.querySelectorAll('.sas-acc-ping .ping-mode[data-account-id]');
          for (var i = 0; i < boxes.length; i++) {
            (function (box) {
              box.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('[data-ping-mode]') : null;
                if (!btn || !box.contains(btn) || btn.disabled) return;
                var mode = btn.getAttribute('data-ping-mode') || 'off';
                var accId = box.getAttribute('data-account-id') || '0';
                if (box.getAttribute('data-mode') === mode) return;
                var prev = box.getAttribute('data-mode') || 'off';
                var all = box.querySelectorAll('[data-ping-mode]');
                for (var j = 0; j < all.length; j++) all[j].classList.remove('is-on');
                btn.classList.add('is-on');
                box.setAttribute('data-mode', mode);
                var body = new FormData();
                body.append('csrf', csrf);
                body.append('section', 'sub_ping');
                body.append('sub_ping_mode', mode);
                body.append('account_id', accId);
                fetch('settings.php?tab=sas', { method: 'POST', credentials: 'same-origin', body: body, redirect: 'manual' })
                  .then(function () { window.location.href = 'settings.php?tab=sas'; })
                  .catch(function () {
                    box.setAttribute('data-mode', prev);
                    for (var k = 0; k < all.length; k++) all[k].classList.remove('is-on');
                    var pb = box.querySelector('[data-ping-mode="' + prev + '"]');
                    if (pb) pb.classList.add('is-on');
                  });
              });
            })(boxes[i]);
          }
          document.addEventListener('submit', function (e) {
            var f = e.target;
            if (!f || !f.classList || !f.classList.contains('sas-retry-form')) return;
            var b = f.querySelector('.sas-retry-btn');
            if (b) {
              b.disabled = true;
              b.textContent = <?php echo json_encode($isEn ? 'Checking…' : 'جاري الفحص…'); ?>;
            }
          });
        })();
        </script>
        <?php endif; ?>

        <?php if (!$sasCompanyCatalog): ?>
        <div class="alert alert-error" style="margin:10px 0">
            <?php echo e($isEn
                ? 'No companies with a SAS host yet. Ask the platform admin to add one.'
                : 'ماكو شركات عليها هوست ساس بعد. اطلب من أدمن المنصة يضيف شركة مع الهوست.'); ?>
        </div>
        <?php endif; ?>
        <div class="sys-head" style="display:flex;justify-content:flex-end;direction:ltr;margin:8px 0 12px">
            <button class="btn" type="button" id="sasAddToggle"><?php echo e($isEn ? 'Add host' : 'إضافة هوست'); ?></button>
        </div>
        <form method="post" id="sasAddBox" hidden style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="sas">
        <input type="hidden" name="account_label" id="sasAccLabel" value="">
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($isEn ? 'Company' : 'اسم الشركة'); ?></label>
                <select name="sas_company_id" id="sasCompanyPick" required <?php echo !$sasCompanyCatalog ? 'disabled' : ''; ?>>
                    <option value=""><?php echo e($isEn ? '— Select —' : '— اختار —'); ?></option>
                    <?php foreach ($sasCompanyCatalog as $co):
                        $cid = (int) $co['id'];
                        $cHost = preg_replace('#^https?://#i', '', rtrim(trim((string) $co['sas_host']), '/'));
                        $cProv = isset($co['provider_type']) ? (string) $co['provider_type'] : 'sas';
                        $cProv = function_exists('platform_company_normalize_provider')
                            ? platform_company_normalize_provider($cProv) : $cProv;
                        ?>
                        <option value="<?php echo $cid; ?>" data-host="<?php echo e($cHost); ?>" data-name="<?php echo e($co['name']); ?>" data-provider="<?php echo e($cProv); ?>">
                            <?php echo e($co['name'] . ($cProv === 'earthlink' ? ' · Earthlink' : ' · SAS')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Host' : 'الهوست'); ?></label>
                <input class="ltr" id="sasHostAuto" name="sas_host" value="" placeholder="s1.example.com">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Agency username' : 'اسم الوكالة'); ?></label>
                <input class="ltr" name="sas_username" required autocomplete="off">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Agency password' : 'باسورد الوكالة'); ?></label>
                <input class="ltr" type="password" name="sas_password" autocomplete="new-password" required>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Service' : 'خدماته'); ?></label>
                <select name="service_kind" id="sasServiceKind">
                    <option value="wireless">Wireless</option>
                    <option value="ftth">FTTH</option>
                </select>
            </div>
        </div>
        <input type="hidden" name="account_id" value="0">
        <input type="hidden" name="sas_enabled" value="1">
        <input type="hidden" name="sas_parent_id" value="1">
        <input type="hidden" name="sas_extend_profile_id" value="0">
        <input type="hidden" name="sas_activate_units" value="1">
        <input type="hidden" name="sas_on_failure" value="warn">
        <input type="hidden" name="sas_default_password" value="1234">
        <div class="actions" style="margin-top:12px">
            <button class="btn" type="submit" id="sasAddSubmit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
        </div>
        </form>
        <div id="sasWait" hidden>
            <div class="sas-wait-card">
                <div class="sas-spin"></div>
                <div style="margin-top:10px;font-weight:800"><?php echo e($isEn ? 'Signing in…' : 'جاري تسجيل الدخول…'); ?></div>
            </div>
        </div>
        <style>
        #sasWait[hidden] { display:none !important; }
        #sasWait { position:fixed; inset:0; background:rgba(15,23,42,.35); z-index:80; display:flex; align-items:center; justify-content:center; }
        .sas-wait-card { background:#fff; border-radius:18px; padding:22px 28px; text-align:center; box-shadow:0 12px 40px rgba(15,23,42,.18); }
        .sas-spin { width:42px; height:42px; margin:0 auto; border-radius:50%; border:4px solid #e2e8f0; border-top-color:#0f766e; animation:sasSpin .8s linear infinite; }
        @keyframes sasSpin { to { transform:rotate(360deg); } }
        </style>
        <script>
        (function () {
          var addBtn = document.getElementById('sasAddToggle');
          var addBox = document.getElementById('sasAddBox');
          if (addBtn && addBox) {
            addBtn.addEventListener('click', function () {
              addBox.hidden = !addBox.hidden;
            });
          }
          if (addBox) {
            addBox.setAttribute('data-no-wait', '1');
            addBox.addEventListener('submit', function () {
              var wait = document.getElementById('sasWait');
              var btn = document.getElementById('sasAddSubmit');
              if (wait) { wait.hidden = false; }
              if (btn) btn.disabled = true;
            });
          }
          var sel = document.getElementById('sasCompanyPick');
          var host = document.getElementById('sasHostAuto');
          var lab = document.getElementById('sasAccLabel');
          if (!sel || !host) return;
          sel.addEventListener('change', function () {
            var opt = sel.options[sel.selectedIndex];
            var h = opt && opt.getAttribute('data-host') ? opt.getAttribute('data-host') : '';
            if (h) host.value = h;
            if (lab && opt) lab.value = opt.getAttribute('data-name') || '';
            var kind = document.getElementById('sasServiceKind');
            var blob = ((opt && opt.getAttribute('data-name')) || '') + ' ' + h;
            if (kind) kind.value = /ftth/i.test(blob) ? 'ftth' : 'wireless';
          });
        })();
        </script>
        <?php
        if (empty($_SESSION['sas_link_otp']) && !empty($sasAccountsList) && empty($_SESSION['sas_otp_dismissed'])) {
            foreach ($sasAccountsList as $otpAcc) {
                $otpErr = isset($otpAcc['last_error']) ? (string) $otpAcc['last_error'] : '';
                if (strpos($otpErr, 'OTP_REQUIRED') === false && strpos($otpErr, 'OTP_INVALID') === false) {
                    continue;
                }
                $_SESSION['sas_link_otp'] = array(
                    'fields' => array(
                        'label' => isset($otpAcc['label']) ? (string) $otpAcc['label'] : '',
                        'company_id' => !empty($otpAcc['company_id']) ? (int) $otpAcc['company_id'] : null,
                        'sas_enabled' => 1,
                        'sas_host' => (string) $otpAcc['sas_host'],
                        'sas_username' => (string) $otpAcc['sas_username'],
                        'sas_password' => (string) $otpAcc['sas_password'],
                        'sas_parent_id' => isset($otpAcc['sas_parent_id']) ? (int) $otpAcc['sas_parent_id'] : 1,
                        'sas_default_password' => isset($otpAcc['sas_default_password']) ? (string) $otpAcc['sas_default_password'] : '1234',
                        'sas_activate_units' => isset($otpAcc['sas_activate_units']) ? (int) $otpAcc['sas_activate_units'] : 1,
                        'sas_extend_method' => isset($otpAcc['sas_extend_method']) ? (string) $otpAcc['sas_extend_method'] : 'reward_points',
                        'sas_extend_profile_id' => isset($otpAcc['sas_extend_profile_id']) ? (int) $otpAcc['sas_extend_profile_id'] : 0,
                        'sas_on_failure' => isset($otpAcc['sas_on_failure']) ? (string) $otpAcc['sas_on_failure'] : 'warn',
                        'is_default' => !empty($otpAcc['is_default']),
                    ),
                    'edit_id' => (int) $otpAcc['id'],
                    'provider' => 'sas',
                    'company_id' => !empty($otpAcc['company_id']) ? (int) $otpAcc['company_id'] : 0,
                    'error' => '',
                    'at' => time(),
                );
                break;
            }
        }
        $sasOtpPending = (isset($_SESSION['sas_link_otp']) && is_array($_SESSION['sas_link_otp'])) ? $_SESSION['sas_link_otp'] : null;
        $sasOtpShow = $sasOtpPending && !empty($sasOtpPending['fields']) && (time() - (int) (isset($sasOtpPending['at']) ? $sasOtpPending['at'] : 0) < 900);
        if (!$sasOtpShow) {
            $sasOtpPending = null;
        }
        $sasOtpUser = ($sasOtpPending && !empty($sasOtpPending['fields']['sas_username'])) ? (string) $sasOtpPending['fields']['sas_username'] : '';
        $sasOtpErr = ($sasOtpPending && !empty($sasOtpPending['error'])) ? (string) $sasOtpPending['error'] : '';
        ?>
        <div class="sas-otp-back<?php echo $sasOtpShow ? '' : ' hidden'; ?>" id="sasOtpModal">
            <form method="post" class="sas-otp-card" id="sasOtpForm" data-no-wait="1">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="section" value="sas">
                <input type="hidden" name="action" value="sas_otp_confirm">
                <input type="hidden" name="account_id" value="<?php echo (int) (isset($sasOtpPending['edit_id']) ? $sasOtpPending['edit_id'] : 0); ?>">
                <div class="sas-otp-badge">2FA</div>
                <h3><?php echo e($isEn ? 'Second step' : 'مصادقة ثانية'); ?></h3>
                <p><?php echo e($isEn
                    ? 'Enter the authenticator code before it expires.'
                    : 'أدخل رمز المصادقة قبل ما ينتهي.'); ?></p>
                <?php if ($sasOtpUser !== ''): ?>
                <div class="sas-otp-user ltr"><?php echo e($sasOtpUser); ?></div>
                <?php endif; ?>
                <div class="sas-otp-clock" id="sasOtpClock" dir="ltr">30</div>
                <input class="sas-otp-input ltr" name="sas_otp" id="sasOtpInput" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••" autofocus>
                <div class="sas-otp-stage" id="sasOtpStage" aria-hidden="true">
                    <span class="sas-otp-spin"></span>
                    <span class="sas-otp-mark is-ok">✓</span>
                    <span class="sas-otp-mark is-bad">×</span>
                </div>
                <?php if ($sasOtpErr === 'bad' || $sasOtpErr === 'short'): ?>
                <div class="sas-otp-err"><?php echo e($sasOtpErr === 'short'
                    ? ($isEn ? 'Enter the code from the app.' : 'اكتب الرمز من التطبيق.')
                    : ($isEn ? 'That code was not accepted. Try the new one.' : 'الرمز ما انقبل. جرّب الرمز الجديد.')); ?></div>
                <?php elseif ($sasOtpErr !== ''): ?>
                <div class="sas-otp-err"><?php echo e($sasOtpErr); ?></div>
                <?php endif; ?>
                <div class="sas-otp-actions">
                    <button class="btn" type="submit"><?php echo e($isEn ? 'Continue' : 'متابعة'); ?></button>
                </div>
            </form>
            <form method="post" class="sas-otp-cancel" data-no-wait="1">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="section" value="sas">
                <input type="hidden" name="action" value="sas_otp_cancel">
                <button class="btn ghost" type="submit"><?php echo e($isEn ? 'Cancel' : 'إلغاء'); ?></button>
            </form>
        </div>
        <style>
        .sas-otp-back.hidden { display:none !important; }
        .sas-otp-back {
          position:fixed; inset:0; z-index:100080;
          display:flex; flex-direction:column; align-items:center; justify-content:center;
          padding:22px; background:rgba(8,15,28,.55);
          backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px);
        }
        .sas-otp-card {
          width:min(420px, 100%); background:#fff; border-radius:24px;
          padding:26px 24px 20px; text-align:center;
          box-shadow:0 24px 60px rgba(8,15,28,.28);
        }
        .sas-otp-cancel { margin-top:8px; width:min(420px, 100%); }
        .sas-otp-cancel .btn { width:100%; height:44px; border-radius:14px; font-weight:800; }
        .sas-otp-badge {
          display:inline-flex; align-items:center; justify-content:center;
          min-width:64px; height:28px; padding:0 12px; border-radius:999px;
          background:#ecfeff; color:#0f766e; font-weight:900; letter-spacing:.08em; font-size:12px;
        }
        .sas-otp-card h3 { margin:12px 0 6px; font-size:22px; }
        .sas-otp-card p { margin:0 0 12px; color:#64748b; font-weight:650; line-height:1.55; }
        .sas-otp-user {
          display:inline-block; margin-bottom:12px; padding:4px 10px; border-radius:999px;
          background:#f8fafc; color:#0f172a; font-weight:800; font-size:13px;
        }
        .sas-otp-input {
          display:block;
          width:100%; height:64px; border-radius:16px; border:1px solid #dbe4ee;
          direction:ltr; text-align:center; font-size:28px; font-weight:900;
          letter-spacing:14px; padding:0 0 0 14px; color:#0f172a;
        }
        .sas-otp-input::placeholder { text-align:center; letter-spacing:14px; }
        .sas-otp-input:focus { outline:none; border-color:#0f766e; box-shadow:0 0 0 4px rgba(15,118,110,.15); }
        .sas-otp-clock { font-weight:900; font-size:13px; color:#0f766e; margin-bottom:8px; }
        .sas-otp-stage { display:none; align-items:center; justify-content:center; height:72px; }
        .sas-otp-card.is-busy .sas-otp-input,
        .sas-otp-card.is-busy .sas-otp-actions,
        .sas-otp-card.is-busy .sas-otp-err,
        .sas-otp-card.is-busy .sas-otp-clock { display:none !important; }
        .sas-otp-card.is-busy .sas-otp-stage { display:flex; }
        .sas-otp-spin {
          width:46px; height:46px; border-radius:50%;
          border:4px solid #d1fae5; border-top-color:#16a34a;
          animation:sasOtpSpin .7s linear infinite;
        }
        .sas-otp-mark { display:none; width:52px; height:52px; border-radius:50%; align-items:center; justify-content:center; font-size:28px; font-weight:900; color:#fff; }
        .sas-otp-card.is-ok .sas-otp-spin { display:none; }
        .sas-otp-card.is-ok .sas-otp-mark.is-ok { display:flex; background:#16a34a; }
        .sas-otp-card.is-bad .sas-otp-spin { display:none; }
        .sas-otp-card.is-bad .sas-otp-mark.is-bad { display:flex; background:#dc2626; }
        @keyframes sasOtpSpin { to { transform:rotate(360deg); } }
        .sas-otp-err { margin-top:10px; color:#b91c1c; font-weight:800; font-size:13px; }
        .sas-otp-actions { display:flex; gap:8px; margin-top:16px; }
        .sas-otp-actions .btn { flex:1; height:46px; border-radius:14px; font-weight:800; }
        </style>
        <script>
        (function () {
          var box = document.getElementById('sasOtpModal');
          var card = document.getElementById('sasOtpForm');
          var inp = document.getElementById('sasOtpInput');
          var clock = document.getElementById('sasOtpClock');
          if (!box || box.classList.contains('hidden') || !inp || !card) return;
          var skew = <?php echo (int) time(); ?> - Math.floor(Date.now() / 1000);
          function remain() {
            var t = Math.floor(Date.now() / 1000) + skew;
            var left = 30 - (t % 30);
            return left === 0 ? 30 : left;
          }
          function paintClock() {
            if (!clock || card.classList.contains('is-busy')) return;
            var n = remain();
            clock.textContent = n + ' <?php echo $isEn ? 's' : 'ث'; ?>';
            clock.style.color = n <= 5 ? '#b91c1c' : '#0f766e';
          }
          paintClock();
          setInterval(paintClock, 250);
          setTimeout(function () { try { inp.focus(); } catch (e) {} }, 40);
          var sent = false;
          function verify() {
            if (sent) return;
            var code = String(inp.value || '').replace(/\D/g, '');
            if (code.length < 6) return;
            sent = true;
            card.classList.add('is-busy');
            var body = new FormData(card);
            fetch('settings.php?ajax=sas_otp', { method: 'POST', credentials: 'same-origin', body: body })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                card.classList.add(d && d.ok ? 'is-ok' : 'is-bad');
                setTimeout(function () {
                  if (d && d.ok) {
                    window.location.href = 'settings.php?tab=sas';
                    return;
                  }
                  sent = false;
                  card.classList.remove('is-busy', 'is-bad');
                  inp.value = '';
                  try { inp.focus(); } catch (e2) {}
                }, 900);
              })
              .catch(function () {
                card.classList.add('is-bad');
                setTimeout(function () {
                  sent = false;
                  card.classList.remove('is-busy', 'is-bad');
                  inp.value = '';
                }, 900);
              });
          }
          inp.addEventListener('input', function () {
            inp.value = String(inp.value || '').replace(/\D/g, '').slice(0, 6);
            if (inp.value.length === 6) verify();
          });
          card.addEventListener('submit', function (e) {
            e.preventDefault();
            verify();
          });
        })();
        </script>
        <?php else: ?>
        <div class="form-grid cols-4">
            <div>
                <label><?php echo e($isEn ? 'SAS connection' : 'ربط الساس'); ?></label>
                <label class="toggle" style="display:flex;align-items:center;gap:10px;margin-top:8px">
                    <input type="checkbox" name="sas_enabled" value="1" <?php echo !empty($sasCfgUi['enabled']) ? 'checked' : ''; ?>>
                    <span class="toggle-ui"></span>
                    <span><?php echo e($isEn ? 'ON / OFF' : 'تشغيل / إيقاف'); ?></span>
                </label>
            </div>
            <div>
                <label><?php echo e($isEn ? 'SAS URL / Host' : 'رابط الساس (Host)'); ?></label>
                <input class="ltr" name="sas_host" required
                       value="<?php echo e(!empty($sasCfgUi['host']) ? $sasCfgUi['host'] : ''); ?>"
                       placeholder="s1.example.com">
                <div class="hint" style="color:#6b7a88;font-size:12px;margin-top:4px">
                    <?php echo e($isEn ? 'Enter the SAS panel domain without https://' : 'أدخل نطاق لوحة الساس بدون https://'); ?>
                </div>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Username' : 'اسم المستخدم'); ?></label>
                <input class="ltr" name="sas_username" required
                       value="<?php echo e(isset($sasCfgUi['username']) ? $sasCfgUi['username'] : ''); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?></label>
                <input class="ltr" type="password" name="sas_password" autocomplete="new-password" value="">
            </div>
            <div>
                <label>Parent ID</label>
                <input type="number" name="sas_parent_id" min="0" step="1" value="<?php echo (int) $parentVal; ?>">
                <div class="hint" style="color:#6b7a88;font-size:12px;margin-top:4px">
                    <?php echo e($isEn
                        ? 'Auto-filled from SAS on save when possible'
                        : 'يُملأ من الساس عند الحفظ إن أمكن'); ?>
                </div>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Extend / 24h test profile' : 'بروفايل التمديد / تست 24 ساعة'); ?></label>
                <input type="number" name="sas_extend_profile_id" min="0" step="1"
                       value="<?php echo (int) (isset($sasCfgUi['extend_profile_id']) ? $sasCfgUi['extend_profile_id'] : 0); ?>"
                       placeholder="0">
                <div class="hint" style="color:#6b7a88;font-size:12px;margin-top:4px">
                    <?php echo e($isEn
                        ? 'Extension profile ID from SAS. 0 = auto-pick 24h test.'
                        : 'رقم بروفايل التمديد من الساس. صفر = اختيار تلقائي للتست 24 ساعة.'); ?>
                </div>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Activation units' : 'وحدات التفعيل'); ?></label>
                <select name="sas_activate_units">
                    <option value="0" <?php echo $unitsVal <= 0 ? 'selected' : ''; ?>>
                        <?php echo e($isEn ? 'Disabled (use SAS default)' : 'تعطيل (حسب الساس)'); ?>
                    </option>
                    <?php for ($u = 1; $u <= 12; $u++): ?>
                        <option value="<?php echo $u; ?>" <?php echo $unitsVal === $u ? 'selected' : ''; ?>><?php echo $u; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div>
                <label><?php echo e($isEn ? 'If SAS fails' : 'عند فشل SAS'); ?></label>
                <select name="sas_on_failure">
                    <option value="warn" <?php echo (isset($sasCfgUi['on_failure']) && $sasCfgUi['on_failure'] === 'rollback') ? '' : 'selected'; ?>>
                        <?php echo e($isEn ? 'Keep local activation + warn' : 'تفعيل محلي + تحذير'); ?>
                    </option>
                    <option value="rollback" <?php echo (isset($sasCfgUi['on_failure']) && $sasCfgUi['on_failure'] === 'rollback') ? 'selected' : ''; ?>>
                        <?php echo e($isEn ? 'Cancel local if SAS fails' : 'إلغاء التفعيل إذا فشل SAS'); ?>
                    </option>
                </select>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Default SAS user password' : 'باسورد مستخدم SAS الافتراضي'); ?></label>
                <input class="ltr" name="sas_default_password" value="<?php echo e($defPassVal); ?>">
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
            <button class="btn ghost" type="submit" name="action" value="test"><?php echo e($isEn ? 'Test connection' : 'فحص الاتصال'); ?></button>
            <a class="btn secondary" href="plans.php"><?php echo e($isEn ? 'Local packages' : 'الباقات المحلية'); ?></a>
        </div>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>
<?php if ($tab === 'schedule'): ?>
<?php
$isEn = ($lang === 'en');
$cronSecret = isset($config['cron_secret']) ? (string) $config['cron_secret'] : '';
$cronUrl = 'cron/schedule_cut.php?key=' . rawurlencode($cronSecret);
$sysGrace = (int) (isset($s['grace_days']) ? $s['grace_days'] : 3);
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Periodic jobs' : 'الجدول الدوري'); ?></h2>
    <p class="meta" style="margin:0 0 14px">
        <?php echo e($isEn
            ? 'When enabled, subscribers who exceed their grace days without paying unpaid debts are disabled on SAS automatically.'
            : 'عند التشغيل: من يتجاوز أيام السماح وعليه دين غير مسدد يُعطَّل يوزره بالساس تلقائياً وينقطع النت.'); ?>
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="schedule">
        <div class="form-grid cols-2" style="margin-bottom:14px">
            <label class="toggle" style="display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #e2e8f0;border-radius:10px">
                <input type="checkbox" name="schedule_cut_enabled" value="1" <?php echo !empty($s['schedule_cut_enabled']) ? 'checked' : ''; ?>>
                <span class="toggle-ui"></span>
                <span>
                    <strong><?php echo e($isEn ? 'Auto-disable after grace' : 'قطع تلقائي بعد أيام السماح'); ?></strong><br>
                    <span class="meta"><?php echo e($isEn
                        ? ('Uses each subscriber’s grace (or system default: ' . $sysGrace . ' days).')
                        : ('يعتمد أيام السماح لكل مشترك أو الافتراضي بالنظام: ' . $sysGrace . ' يوم.')); ?></span>
                </span>
            </label>
            <label class="toggle" style="display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #e2e8f0;border-radius:10px">
                <input type="checkbox" name="schedule_cut_send_wa" value="1" <?php echo !isset($s['schedule_cut_send_wa']) || !empty($s['schedule_cut_send_wa']) ? 'checked' : ''; ?>>
                <span class="toggle-ui"></span>
                <span>
                    <strong><?php echo e($isEn ? 'Send cut WhatsApp message' : 'إرسال رسالة القطع عبر واتساب'); ?></strong><br>
                    <span class="meta"><?php echo e($isEn ? 'Turn off to cut without messaging.' : 'اطفه إذا تريد القطع بدون رسالة.'); ?></span>
                </span>
            </label>
        </div>
        <div class="panel" style="margin:0 0 14px;padding:12px 14px">
            <h3 style="margin:0 0 8px;font-size:15px"><?php echo e($isEn ? 'Cut message template' : 'قالب رسالة القطع'); ?></h3>
            <p class="meta" style="margin:0 0 8px">{name} {debt} {amount} {days_passed} {grace} {package} {month}</p>
            <textarea name="tpl_schedule_cut" rows="5" style="width:100%"><?php echo e(isset($s['tpl_schedule_cut']) ? $s['tpl_schedule_cut'] : ''); ?></textarea>
        </div>
        <p class="meta" style="margin:0 0 10px">
            <?php echo e($isEn ? 'Cron URL (run hourly recommended):' : 'رابط الكرون (يفضّل كل ساعة):'); ?>
            <code class="ltr" style="display:block;margin-top:4px;word-break:break-all"><?php echo e($cronUrl); ?></code>
        </p>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
            <?php if (!empty($s['schedule_cut_enabled']) && function_exists('run_schedule_debt_cuts')): ?>
                <button class="btn ghost" type="submit" name="schedule_run_now" value="1"><?php echo e($isEn ? 'Run once now' : 'تشغيل مرة الآن'); ?></button>
            <?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($tab === 'saas'): ?>
<?php
$isEn = ($lang === 'en');
$plansUi = isset($s['saas_plans']) && is_array($s['saas_plans']) ? $s['saas_plans'] : array();
$planForm = array(
    'monthly' => array('label' => 'شهري', 'days' => 30, 'amount' => 25000, 'discount' => 0),
    'quarter' => array('label' => '3 أشهر', 'days' => 90, 'amount' => 70000, 'discount' => 0),
    'half' => array('label' => '6 أشهر', 'days' => 180, 'amount' => 130000, 'discount' => 0),
    'yearly' => array('label' => 'سنوي', 'days' => 365, 'amount' => 250000, 'discount' => 0),
);
foreach ($planForm as $code => $def) {
    if (isset($plansUi[$code]) && is_array($plansUi[$code])) {
        $planForm[$code] = array_merge($def, $plansUi[$code]);
    }
}
$zcConfigured = function_exists('zaincash_is_configured') && zaincash_is_configured($s);
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'SaaS hosting & ZainCash' : 'استضافة الوكلاء و ZainCash'); ?></h2>
    <p class="meta" style="margin-top:-4px">
        <?php echo e($isEn
            ? 'Agents register publicly, you approve them, then they get a trial and pay via ZainCash. Your company #1 data stays separate.'
            : 'الوكيل يسجّل علناً، توافق عليه، ثم يحصل على تجريبي ويدفع عبر زين كاش. بيانات شركتك رقم 1 تبقى معزولة.'); ?>
        —
        <a href="saas_agents.php"><?php echo e($isEn ? 'Manage agents' : 'إدارة الوكلاء'); ?></a>
    </p>
    <p class="meta"><?php echo e($zcConfigured
        ? ($isEn ? 'ZainCash: configured' : 'ZainCash: مضبوط')
        : ($isEn ? 'ZainCash: missing keys' : 'ZainCash: المفاتيح ناقصة')); ?></p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="saas">
        <label class="toggle" style="display:flex;align-items:center;gap:10px;margin:10px 0">
            <input type="checkbox" name="saas_registration_enabled" value="1" <?php echo !isset($s['saas_registration_enabled']) || !empty($s['saas_registration_enabled']) ? 'checked' : ''; ?>>
            <span><?php echo e($isEn ? 'Allow public agent registration' : 'السماح بتسجيل الوكلاء علناً'); ?></span>
        </label>
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($isEn ? 'Trial days after approval' : 'أيام التجريبي بعد الموافقة'); ?></label>
                <input class="ltr" type="number" min="0" name="saas_trial_days" value="<?php echo e(isset($s['saas_trial_days']) ? (int) $s['saas_trial_days'] : 7); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Public site base URL' : 'رابط الموقع العام'); ?></label>
                <input class="ltr" name="zaincash_redirect_base" placeholder="https://example.com/public" value="<?php echo e(isset($s['zaincash_redirect_base']) ? $s['zaincash_redirect_base'] : ''); ?>">
            </div>
        </div>
        <h3 style="font-size:15px;margin:18px 0 8px"><?php echo e($isEn ? 'Membership plans' : 'خطط عضوية البوابة'); ?></h3>
        <p class="meta" style="margin:0 0 8px"><?php echo e($isEn ? 'Price and discount are for portal membership only.' : 'السعر والخصم لعضوية البوابة فقط، مو لباقات الساس.'); ?></p>
        <?php foreach ($planForm as $code => $prow): ?>
        <div class="form-grid cols-4" style="margin-bottom:8px">
            <div>
                <label><?php echo e($isEn ? 'Name' : 'الاسم'); ?></label>
                <input name="plan_<?php echo e($code); ?>_label" value="<?php echo e($prow['label']); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Days' : 'الأيام'); ?></label>
                <input class="ltr" type="number" min="1" name="plan_<?php echo e($code); ?>_days" value="<?php echo (int) $prow['days']; ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Price' : 'السعر'); ?></label>
                <input class="ltr" type="number" min="0" step="1" name="plan_<?php echo e($code); ?>_amount" value="<?php echo (int) $prow['amount']; ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Discount' : 'الخصم'); ?></label>
                <input class="ltr" type="number" min="0" step="1" name="plan_<?php echo e($code); ?>_discount" value="<?php echo isset($prow['discount']) ? (int) $prow['discount'] : 0; ?>">
            </div>
        </div>
        <?php endforeach; ?>
        <h3 style="font-size:15px;margin:18px 0 8px"><?php echo e($isEn ? 'ZainCash keys' : 'مفاتيح زين كاش'); ?></h3>
        <div class="form-grid cols-2">
            <div>
                <label>Merchant ID</label>
                <input class="ltr" name="zaincash_merchant_id" value="<?php echo e(isset($s['zaincash_merchant_id']) ? $s['zaincash_merchant_id'] : ''); ?>">
            </div>
            <div>
                <label>MSISDN</label>
                <input class="ltr" name="zaincash_msisdn" value="<?php echo e(isset($s['zaincash_msisdn']) ? $s['zaincash_msisdn'] : ''); ?>">
            </div>
            <div>
                <label>Secret <?php echo !empty($s['zaincash_secret']) ? '(' . ($isEn ? 'leave blank to keep' : 'اتركه فارغ للإبقاء') . ')' : ''; ?></label>
                <input class="ltr" type="password" name="zaincash_secret" value="" autocomplete="new-password" placeholder="<?php echo !empty($s['zaincash_secret']) ? '••••••••' : ''; ?>">
            </div>
            <div>
                <label class="toggle" style="display:flex;align-items:center;gap:10px;margin-top:28px">
                    <input type="checkbox" name="zaincash_production" value="1" <?php echo !empty($s['zaincash_production']) ? 'checked' : ''; ?>>
                    <span><?php echo e($isEn ? 'Production mode' : 'وضع الإنتاج'); ?></span>
                </label>
            </div>
        </div>
        <div class="actions" style="margin-top:14px">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($tab === 'update'): ?>
<?php
$isEn = ($lang === 'en');
$pendingUpd = function_exists('app_update_pending') ? app_update_pending($s) : null;
?>
<div class="panel panel-compact">
    <h2><?php echo e($isEn ? 'Official system update' : 'تحديث النظام الرسمي'); ?></h2>
    <p class="meta" style="margin-top:-4px">
        <?php echo e($isEn
            ? 'Upload a ZIP of changed files (includes/, public/, cron/, whatsapp-gateway/). Live secrets in config/config.php are never overwritten.'
            : 'ارفع ZIP يضم الملفات المتغيرة (includes/ و public/ و cron/ و whatsapp-gateway/). ملف config/config.php ما ينستبدل أبداً.'); ?>
    </p>
    <?php if ($pendingUpd): ?>
        <div class="alert alert-info" style="margin:12px 0">
            <strong><?php echo e($isEn ? 'Update ready to apply' : 'تحديث جاهز للتطبيق'); ?></strong>
            <div class="meta"><?php echo e($pendingUpd['file']); ?>
                <?php if (!empty($pendingUpd['at'])): ?> · <?php echo e($pendingUpd['at']); ?><?php endif; ?>
            </div>
            <?php if (trim((string) $pendingUpd['note']) !== ''): ?>
                <div><?php echo e($pendingUpd['note']); ?></div>
            <?php endif; ?>
        </div>
        <form method="post" style="display:inline" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Apply this update now?' : 'تطبيق التحديث الآن؟'); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="section" value="app_update_apply">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Apply update' : 'تطبيق التحديث'); ?></button>
        </form>
        <form method="post" style="display:inline;margin-inline-start:8px" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Discard pending update?' : 'إلغاء التحديث المعلّق؟'); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="section" value="app_update_clear">
            <button class="btn ghost" type="submit"><?php echo e($isEn ? 'Discard' : 'إلغاء'); ?></button>
        </form>
    <?php else: ?>
        <p class="meta"><?php echo e($isEn ? 'No pending update.' : 'ماكو تحديث معلّق حالياً.'); ?></p>
    <?php endif; ?>
</div>
<div class="panel panel-compact">
    <h2><?php echo e($isEn ? 'Upload new package' : 'رفع حزمة جديدة'); ?></h2>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="app_update_upload">
        <label><?php echo e($isEn ? 'ZIP file' : 'ملف ZIP'); ?>
            <input type="file" name="update_zip" accept=".zip,application/zip" required>
        </label>
        <label style="margin-top:10px;display:block"><?php echo e($isEn ? 'Note (optional)' : 'ملاحظة (اختياري)'); ?>
            <input name="app_update_note" maxlength="200" placeholder="<?php echo e($isEn ? 'e.g. messages + agents fix' : 'مثال: إصلاح الرسائل والوكلاء'); ?>">
        </label>
        <div class="actions" style="margin-top:12px">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Upload & announce' : 'رفع وإعلان التحديث'); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php render_footer(); ?>
