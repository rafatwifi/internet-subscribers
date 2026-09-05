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
if (!in_array($tab, array('general', 'whatsapp', 'rental', 'users', 'plans', 'sas', 'schedule', 'sensitive'), true)) {
    $tab = 'general';
}

if ($tab === 'users') {
    require_perm('users');
} elseif ($tab === 'plans') {
    redirect('plans.php');
} elseif ($tab === 'sensitive') {
    require_perm('clear_data');
} else {
    require_perm('settings');
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'sys_status') {
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
        'bank' => $fmtMs($sasMs),
        'points' => $points,
        'ram' => $sys['ram']['label'],
        'ram_pct' => isset($sys['ram']['pct_used']) ? (int) $sys['ram']['pct_used'] : 0,
        'disk' => $sys['disk']['ok'] ? $sys['disk']['label'] : '—',
        'disk_pct' => isset($sys['disk']['pct_used']) ? (int) $sys['disk']['pct_used'] : 0,
        'whatsapp' => $sys['whatsapp']['label'],
        'server_time' => $sys['server_time'],
    ));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', t('saved') === 'Saved' ? 'Invalid request' : 'طلب غير صالح');
        redirect('settings.php?tab=' . $tab);
    }

    $section = post('section', 'general');
    $data = array();
    $skipSettingsSave = false;

    if ($section === 'sas_test') {
        $skipSettingsSave = true;
        $tab = 'sas';
    } elseif ($section === 'system_power') {
        require_perm('settings');
        $skipSettingsSave = true;
        $tab = 'general';
        $pass = (string) post('admin_password', '');
        $power = post('power_action', '');
        if (!settings_verify_wipe_password($pdo, $config, $pass)) {
            flash('error', $lang === 'en' ? 'Wrong password' : 'كلمة المرور غير صحيحة');
            redirect('settings.php?tab=general');
        }
        if (!function_exists('system_power_action')) {
            flash('error', $lang === 'en' ? 'Power action unavailable' : 'أمر الطاقة غير متاح');
            redirect('settings.php?tab=general');
        }
        list($ok, $msg) = system_power_action($power);
        flash($ok ? 'success' : 'error', $msg);
        redirect('settings.php?tab=general');
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
        $res = create_admin_user(
            $pdo,
            post('username', ''),
            post('display_name', ''),
            post('password', ''),
            post('role', 'staff')
        );
        if ($res === 'ok') {
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
        if (!$target) {
            flash('error', $lang === 'en' ? 'User not found' : 'المستخدم مو موجود');
            redirect('settings.php?tab=users');
        }
        $oldRole = normalize_admin_role(isset($target['role']) ? $target['role'] : 'staff');
        if ($oldRole === 'admin' && $role !== 'admin' && count_active_admins($pdo) <= 1) {
            flash('error', $lang === 'en' ? 'Keep at least one admin' : 'لازم يبقى مدير واحد على الأقل');
            redirect('settings.php?tab=users');
        }
        update_admin_user_meta($pdo, $uid, $display, $role);
        if ($me && (int) $me['id'] === $uid) {
            $_SESSION['admin_display_name'] = $display;
            $_SESSION['admin_role'] = $role;
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
        $res = delete_admin_user($pdo, $uid, $me ? $me['id'] : 0);
        if ($res === 'ok') {
            activity_log($pdo, null, 'system', $uid, 'user_delete', 'حذف مستخدم', '');
            flash('success', $lang === 'en' ? 'User deleted' : 'تم حذف المستخدم');
        } elseif ($res === 'self') {
            flash('error', $lang === 'en' ? 'Cannot delete yourself' : 'ما تكدر تحذف نفسك');
        } elseif ($res === 'last_admin') {
            flash('error', $lang === 'en' ? 'Cannot delete the last admin' : 'ما تكدر تحذف آخر مدير');
        } else {
            flash('error', $lang === 'en' ? 'Delete failed' : 'فشل الحذف');
        }
        redirect('settings.php?tab=users');
    }

    if ($section === 'general') {
        $periodMode = post('subscription_period_mode', 'days_30') === 'calendar_month'
            ? 'calendar_month'
            : 'days_30';
        $data = array(
            'site_name' => (string) post('site_name', 'WiFi-Net-SALES'),
            'language' => post('language') === 'en' ? 'en' : 'ar',
            'currency' => (string) post('currency', 'د.ع'),
            'grace_days' => (int) post('grace_days', '3'),
            'subscription_period_mode' => $periodMode,
        );
        set_lang_preference($data['language']);
        $tab = 'general';
    } elseif ($section === 'login_bg') {
        $tab = 'general';
        $color = trim((string) post('login_bg_color', '#1b2a38'));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#1b2a38';
        }
        $mode = post('bg_mode') === 'image' ? 'image' : 'color';
        $data = array(
            'login_bg_color' => $color,
            'bg_mode' => $mode,
        );
        if (post('login_bg_remove') === '1') {
            login_bg_delete_files();
            $data['login_bg'] = '';
            $data['bg_mode'] = 'color';
        } elseif (!empty($_FILES['login_bg_file']['tmp_name'])) {
            $saved = login_bg_store_upload($_FILES['login_bg_file']);
            if ($saved === false) {
                flash('error', $lang === 'en'
                    ? 'Use a JPG/PNG/GIF image up to 5MB.'
                    : 'ارفع صورة JPG أو PNG أو GIF بحجم 5MB كحد أقصى.');
                redirect('settings.php?tab=general');
            }
            $data['login_bg'] = $saved;
            $data['bg_mode'] = 'image';
        }
        if (post('brand_icon_remove') === '1') {
            brand_icon_delete_files();
            $data['brand_icon'] = '';
        } elseif (!empty($_FILES['brand_icon_file']['tmp_name'])) {
            $savedIco = brand_icon_store_upload($_FILES['brand_icon_file']);
            if ($savedIco === false) {
                flash('error', $lang === 'en'
                    ? 'Use a square JPG/PNG icon up to 2MB.'
                    : 'ارفع أيقونة مربعة JPG أو PNG بحجم 2MB كحد أقصى.');
                redirect('settings.php?tab=general');
            }
            $data['brand_icon'] = $savedIco;
        }
    } elseif ($section === 'rental') {
        $fee = (float) post('rental_fee', '5000');
        if ($fee < 0) {
            $fee = 0;
        }
        $names = isset($_POST['device_name']) && is_array($_POST['device_name']) ? $_POST['device_name'] : array();
        $icons = isset($_POST['device_icon']) && is_array($_POST['device_icon']) ? $_POST['device_icon'] : array();
        $colors = isset($_POST['device_color']) && is_array($_POST['device_color']) ? $_POST['device_color'] : array();
        $ids = isset($_POST['device_id']) && is_array($_POST['device_id']) ? $_POST['device_id'] : array();
        $devices = array();
        $n = max(count($names), count($ids));
        for ($i = 0; $i < $n; $i++) {
            $name = isset($names[$i]) ? trim((string) $names[$i]) : '';
            if ($name === '') {
                continue;
            }
            $id = isset($ids[$i]) ? trim((string) $ids[$i]) : '';
            if ($id === '') {
                $id = preg_replace('/\s+/', '-', strtolower($name));
                if ($id === '') {
                    $id = 'dev' . ($i + 1);
                }
            }
            $icon = isset($icons[$i]) ? trim((string) $icons[$i]) : '';
            if ($icon === '') {
                $icon = strtoupper(substr($id, 0, 2));
            }
            $color = isset($colors[$i]) ? trim((string) $colors[$i]) : '#5e5ce6';
            $devices[] = array(
                'id' => $id,
                'name' => $name,
                'icon' => $icon,
                'color' => $color,
            );
        }
        if (!$devices) {
            $devices = rental_default_devices();
        }
        $data = array(
            'rental_fee' => $fee,
            'rental_devices' => $devices,
        );
        $tab = 'rental';
    } elseif ($section === 'whatsapp') {
        $url = trim((string) post('whatsapp_local_url', 'http://172.16.16.13:3001'));
        if ($url !== '' && strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $url = 'http://' . ltrim($url, '/');
        }
        $expDays = (int) post('expiry_auto_remind_days', '1');
        if ($expDays < 0) {
            $expDays = 0;
        }
        if ($expDays > 60) {
            $expDays = 60;
        }
        $data = array(
            'whatsapp_enabled' => post('whatsapp_enabled') === '1',
            'whatsapp_provider' => 'local',
            'whatsapp_local_url' => $url,
            'whatsapp_local_key' => (string) post('whatsapp_local_key', 'local-secret-change-me'),
            'whatsapp_sender_note' => (string) post('whatsapp_sender_note', ''),
            'expiry_auto_remind_enabled' => post('expiry_auto_remind_enabled') === '1',
            'expiry_auto_remind_days' => $expDays,
        );
        $tab = 'whatsapp';
    } elseif ($section === 'templates') {
        $afterDays = (int) post('unpaid_remind_after_days', '7');
        if ($afterDays < 1) {
            $afterDays = 1;
        }
        if ($afterDays > 365) {
            $afterDays = 365;
        }
        $data = array(
            'tpl_debt_remind' => (string) post('tpl_debt_remind', ''),
            'tpl_payment_ok' => (string) post('tpl_payment_ok', ''),
            'tpl_debt_created' => (string) post('tpl_debt_created', ''),
            'tpl_activation' => (string) post('tpl_activation', ''),
            'tpl_activation_credit' => (string) post('tpl_activation_credit', ''),
            'tpl_activation_debts' => (string) post('tpl_activation_debts', ''),
            'tpl_days_left' => (string) post('tpl_days_left', ''),
            'tpl_unpaid_overdue' => (string) post('tpl_unpaid_overdue', ''),
            'tpl_expiry_soon' => (string) post('tpl_expiry_soon', ''),
            'unpaid_remind_after_days' => $afterDays,
        );
        $tplAllowed = array(
            'activation', 'activation_credit', 'activation_debts',
            'debt_created', 'payment_ok', 'debt_remind', 'days_left', 'unpaid_overdue', 'expiry_soon'
        );
        $caseKeys = array(
            'activation_cash', 'activation_credit', 'activation_debts', 'debt_created', 'payment_ok',
            'debt_remind', 'reminder_auto', 'days_left', 'unpaid_overdue', 'expiry_soon'
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
        $units = (int) post('sas_activate_units', '1');
        if ($units < 1) {
            $units = 1;
        }
        $data = array(
            'sas_saved' => true,
            'sas_enabled' => post('sas_enabled') === '1',
            'sas_host' => $host !== '' ? $host : 'reseller.nbtel.iq',
            'sas_username' => trim((string) post('sas_username', '')),
            'sas_password' => $pass,
            'sas_parent_id' => (int) post('sas_parent_id', '1'),
            'sas_default_password' => (string) post('sas_default_password', ''),
            'sas_activate_units' => $units,
            'sas_extend_method' => post('sas_extend_method') === 'credit' ? 'credit' : 'reward_points',
            'sas_extend_profile_id' => (int) post('sas_extend_profile_id', '0'),
            'sas_on_failure' => post('sas_on_failure') === 'rollback' ? 'rollback' : 'warn',
            'cpe_http_user' => trim((string) post('cpe_http_user', 'ubnt')),
            'cpe_http_pass' => (string) post('cpe_http_pass', 'ubnt'),
            'cpe_use_https' => post('cpe_use_https') === '1',
        );
        $tab = 'sas';
    } elseif ($section === 'schedule') {
        $data = array(
            'schedule_cut_enabled' => post('schedule_cut_enabled') === '1',
            'schedule_cut_send_wa' => post('schedule_cut_send_wa') === '1',
            'tpl_schedule_cut' => (string) post('tpl_schedule_cut', ''),
            'wa_case_schedule_cut' => 'schedule_cut',
        );
        $tab = 'schedule';
        if (post('schedule_run_now') === '1' && function_exists('run_schedule_debt_cuts')) {
            // احفظ أولاً ثم شغّل بالكود المحدّث
            if (settings_save($data)) {
                $settings = settings_load();
                $config = apply_settings_to_config($config, $settings);
                $run = run_schedule_debt_cuts($pdo, $config, 100);
                $msg = 'تشغيل: فحص ' . (int) $run['checked']
                    . ' — قطع ' . (int) $run['cut']
                    . ' — واتساب ' . (int) $run['wa_sent'];
                flash('success', $msg);
            } else {
                flash('error', 'Cannot write settings.json');
            }
            redirect('settings.php?tab=schedule');
        }
    } elseif (!$skipSettingsSave) {
        flash('error', 'Unknown section');
        redirect('settings.php');
    }

    if (!$skipSettingsSave) {
        if (settings_save($data)) {
            flash('success', t('saved'));
        } else {
            flash('error', 'Cannot write settings.json');
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
}

$sasCfgUi = function_exists('sas_config') ? sas_config($config) : array();
if (!is_array($sasCfgUi)) {
    $sasCfgUi = array();
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
$hostHint = parse_url(isset($s['whatsapp_local_url']) ? $s['whatsapp_local_url'] : '', PHP_URL_HOST);
if (!$hostHint) {
    $hostHint = '172.16.16.13';
}
$adminUsers = list_admin_users($pdo);
$me = current_admin();

render_header(t('settings'), $activeNav);
render_settings_tabs($tab);
?>

<?php if ($tab === 'users'): ?>
<div class="panel panel-compact">
    <h2><?php echo e($lang === 'en' ? 'Roles' : 'الصلاحيات'); ?></h2>
    <div class="role-help">
        <div><strong><?php echo e(admin_role_label('admin', $lang)); ?></strong> — <?php echo e(admin_role_hint('admin', $lang)); ?></div>
        <div><strong><?php echo e(admin_role_label('manager', $lang)); ?></strong> — <?php echo e(admin_role_hint('manager', $lang)); ?></div>
        <div><strong><?php echo e(admin_role_label('staff', $lang)); ?></strong> — <?php echo e(admin_role_hint('staff', $lang)); ?></div>
        <div><strong><?php echo e(admin_role_label('agent', $lang)); ?></strong> — <?php echo e(admin_role_hint('agent', $lang)); ?></div>
    </div>
</div>

<div class="panel panel-compact">
    <h2><?php echo e($lang === 'en' ? 'Add user' : 'إضافة مستخدم'); ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="add_user">
        <div class="form-grid cols-4">
            <div>
                <label><?php echo e($lang === 'en' ? 'Username' : 'اسم الدخول'); ?></label>
                <input name="username" required pattern="[A-Za-z0-9._-]{2,40}" placeholder="yousif">
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
                <select name="role">
                    <option value="staff"><?php echo e(admin_role_label('staff', $lang)); ?></option>
                    <option value="agent"><?php echo e(admin_role_label('agent', $lang)); ?></option>
                    <option value="manager"><?php echo e(admin_role_label('manager', $lang)); ?></option>
                    <option value="admin"><?php echo e(admin_role_label('admin', $lang)); ?></option>
                </select>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Add' : 'إضافة'); ?></button>
        </div>
    </form>
</div>

<div class="panel panel-compact">
    <h2><?php echo e($lang === 'en' ? 'Users' : 'المستخدمين'); ?></h2>
    <p class="meta" style="margin-top:-4px">
        <?php echo e($lang === 'en'
            ? 'Each change is logged with who did it. Password change for yourself is in Profile.'
            : 'كل تغيير يظهر باللوك. تغيير رمزك من صفحة بروفايلي.'); ?>
        —
        <a href="profile.php"><?php echo e($lang === 'en' ? 'Open my profile' : 'افتح بروفايلي'); ?></a>
    </p>
    <div class="table-wrap">
        <table class="table-compact users-table">
            <thead>
            <tr>
                <th><?php echo e($lang === 'en' ? 'Username' : 'اسم الدخول'); ?></th>
                <th><?php echo e($lang === 'en' ? 'Name / Role / Password' : 'الاسم / الصلاحية / الرمز'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$adminUsers): ?>
                <tr><td colspan="3"><?php echo e($lang === 'en' ? 'No users' : 'ماكو مستخدمين'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($adminUsers as $u):
                $urole = normalize_admin_role(isset($u['role']) ? $u['role'] : 'staff');
                ?>
                <tr>
                    <td>
                        <strong><?php echo e($u['username']); ?></strong>
                        <?php if ($me && (int) $me['id'] === (int) $u['id']): ?>
                            <span class="badge active"><?php echo e($lang === 'en' ? 'You' : 'أنت'); ?></span>
                        <?php endif; ?>
                        <div class="meta"><?php echo e(admin_role_label($urole, $lang)); ?></div>
                    </td>
                    <td>
                        <form method="post" class="user-edit-form">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="section" value="save_user">
                            <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                            <div class="inline-form-row">
                                <input name="display_name" value="<?php echo e($u['display_name']); ?>" required style="max-width:140px" placeholder="<?php echo e($lang === 'en' ? 'Name' : 'الاسم'); ?>">
                                <select name="role" style="max-width:120px">
                                    <?php foreach (admin_roles() as $rOpt): ?>
                                        <option value="<?php echo e($rOpt); ?>" <?php echo $urole === $rOpt ? 'selected' : ''; ?>><?php echo e(admin_role_label($rOpt, $lang)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="password" name="new_password" placeholder="<?php echo e($lang === 'en' ? 'New pass (optional)' : 'رمز جديد (اختياري)'); ?>" style="max-width:140px" minlength="4">
                                <button class="btn secondary sm" type="submit"><?php echo e(t('save')); ?></button>
                            </div>
                        </form>
                    </td>
                    <td>
                        <?php if (!$me || (int) $me['id'] !== (int) $u['id']): ?>
                            <form method="post" onsubmit="return confirm(<?php echo json_encode($lang === 'en' ? 'Delete this user?' : 'حذف هذا المستخدم؟'); ?>);">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="section" value="delete_user">
                                <input type="hidden" name="user_id" value="<?php echo (int) $u['id']; ?>">
                                <button class="btn danger sm" type="submit"><?php echo e($lang === 'en' ? 'Delete' : 'حذف'); ?></button>
                            </form>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'general'): ?>
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
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'SAS latency' : 'بنك الساس'); ?></div>
            <div class="sys-card-v" id="sysBankVal"><?php echo e($sasLatDisp); ?></div>
            <div class="sys-card-s" id="sysBankSub"><?php
                if ($sasLatHost !== '') {
                    echo e(($lang === 'en' ? 'Ping → ' : 'Latency → ') . $sasLatHost);
                } else {
                    echo e($lang === 'en' ? 'SAS domain ping' : 'بينغ دومين الساس');
                }
            ?></div>
        </div>
    </div>
    <p class="meta" style="margin:10px 0 0"><?php echo e($lang === 'en' ? 'Server time' : 'وقت السيرفر'); ?>: <span id="sysServerTime"><?php echo e($sys['server_time']); ?></span></p>
</div>

<div class="panel">
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
<script>
(function () {
  var btn = document.getElementById('sysStatusRefresh');
  function applySys(d) {
    if (!d || !d.ok) return;
    var cpu = document.getElementById('sysCpuVal');
    if (cpu) cpu.textContent = d.cpu || '—';
    var bank = document.getElementById('sysBankVal');
    if (bank) bank.textContent = d.bank || '—';
    var bs = document.getElementById('sysBankSub');
    if (bs) {
      bs.textContent = d.sas_host
        ? (<?php echo json_encode($lang === 'en' ? 'Ping → ' : 'Latency → '); ?> + d.sas_host)
        : <?php echo json_encode($lang === 'en' ? 'SAS domain ping' : 'بينغ دومين الساس'); ?>;
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
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="general">
        <div class="form-grid">
            <div>
                <label><?php echo e(t('site_name')); ?></label>
                <input name="site_name" value="<?php echo e($s['site_name']); ?>" required>
            </div>
            <div>
                <label><?php echo e(t('language')); ?></label>
                <select name="language">
                    <option value="ar" <?php echo $s['language'] === 'ar' ? 'selected' : ''; ?>>العربية</option>
                    <option value="en" <?php echo $s['language'] === 'en' ? 'selected' : ''; ?>>English</option>
                </select>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Currency' : 'العملة'); ?></label>
                <input name="currency" value="<?php echo e($s['currency']); ?>">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Default grace days' : 'أيام السماح الافتراضية'); ?></label>
                <input type="number" min="0" name="grace_days" value="<?php echo (int) $s['grace_days']; ?>">
                <small style="color:#6b7a88;font-weight:600"><?php echo e($lang === 'en' ? 'Default 3 days after activation. Can be changed per subscriber.' : 'الافتراضي 3 أيام بعد التفعيل. قابل للتعديل لكل مشترك.'); ?></small>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Subscription period' : 'مدة الاشتراك'); ?></label>
                <?php $periodMode = (isset($s['subscription_period_mode']) && $s['subscription_period_mode'] === 'calendar_month') ? 'calendar_month' : 'days_30'; ?>
                <select name="subscription_period_mode">
                    <option value="days_30" <?php echo $periodMode === 'days_30' ? 'selected' : ''; ?>>
                        <?php echo e($lang === 'en' ? '30 days (30 Jul → 29 Aug)' : '30 يوم (30-7 → 29-8)'); ?>
                    </option>
                    <option value="calendar_month" <?php echo $periodMode === 'calendar_month' ? 'selected' : ''; ?>>
                        <?php echo e($lang === 'en' ? 'Calendar month (30 Jul → 30 Aug)' : 'شهر ميلادي (30-7 → 30-8)'); ?>
                    </option>
                </select>
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
<div class="panel">
    <h2><?php echo e($lang === 'en' ? 'Appearance' : 'المظهر والخلفية'); ?></h2>
    <p class="meta" style="margin-top:-6px">
        <?php echo e($lang === 'en'
            ? 'Toggle color or image. Image covers login and the app. Color is used on the login page. Brand icon appears next to Dashboard.'
            : 'بدّل بين لون أو صورة. الصورة تظهر بصفحة الدخول وبالنظام. اللون يظهر بصفحة الدخول. أيقونة النظام تظهر يم الرئيسية بالداشبورد.'); ?>
    </p>
    <div class="login-bg-preview" style="background-color:<?php echo e($loginBgColor); ?>;<?php echo ($bgMode === 'image' && $loginBgUrl !== '') ? ('background-image:url(' . e($loginBgUrl) . ');') : ''; ?>"></div>
    <form method="post" enctype="multipart/form-data">
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
        </div>
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($lang === 'en' ? 'Background image' : 'صورة الخلفية'); ?></label>
                <input type="file" name="login_bg_file" accept="image/jpeg,image/png,image/gif,image/webp">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Background color' : 'لون الخلفية'); ?></label>
                <input type="color" name="login_bg_color" value="<?php echo e($loginBgColor); ?>">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'System icon (dashboard)' : 'أيقونة النظام (يم الرئيسية)'); ?></label>
                <input type="file" name="brand_icon_file" accept="image/jpeg,image/png,image/gif,image/webp">
                <?php if ($brandIconUrl !== ''): ?>
                    <div style="margin-top:8px;display:flex;align-items:center;gap:8px">
                        <img src="<?php echo e($brandIconUrl); ?>" alt="" width="36" height="36" style="border-radius:8px;object-fit:contain;background:#fff;border:1px solid #e2e8f0">
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Save appearance' : 'حفظ المظهر'); ?></button>
            <?php if ($loginBgUrl !== ''): ?>
                <button class="btn ghost" type="submit" name="login_bg_remove" value="1"><?php echo e($lang === 'en' ? 'Remove image' : 'حذف الصورة'); ?></button>
            <?php endif; ?>
            <?php if ($brandIconUrl !== ''): ?>
                <button class="btn ghost" type="submit" name="brand_icon_remove" value="1"><?php echo e($lang === 'en' ? 'Remove icon' : 'حذف الأيقونة'); ?></button>
            <?php endif; ?>
        </div>
    </form>
</div>

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
<?php endif; ?>

<?php if ($tab === 'rental'): ?>
<div class="panel glass-panel">
    <h2><?php echo e($lang === 'en' ? 'Rental devices' : 'أجهزة الإيجار'); ?></h2>
    <p style="color:var(--muted);margin-top:-6px;font-weight:600">
        <?php echo e($lang === 'en'
            ? 'Monthly rent is added to subscription when activating a subscriber with a rental device.'
            : 'مبلغ الإيجار الشهري يُضاف للاشتراك عند تفعيل مشترك عليه جهاز إيجار.'); ?>
    </p>
    <form method="post" id="rentalSettingsForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="rental">
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($lang === 'en' ? 'Monthly rental fee' : 'مبلغ الإيجار الشهري'); ?></label>
                <input type="number" name="rental_fee" min="0" step="1" value="<?php echo e((float) $rentalFee); ?>" required>
            </div>
        </div>
        <h3 style="margin:18px 0 10px;font-size:15px"><?php echo e($lang === 'en' ? 'Device types' : 'أنواع الأجهزة'); ?></h3>
        <div id="rentalDeviceRows">
            <?php foreach ($rentalDevices as $d): ?>
            <div class="form-grid rental-device-row" style="margin-bottom:10px">
                <div>
                    <label><?php echo e($lang === 'en' ? 'Name' : 'الاسم'); ?></label>
                    <input name="device_name[]" value="<?php echo e($d['name']); ?>" required>
                </div>
                <div>
                    <label><?php echo e($lang === 'en' ? 'Code / ID' : 'المعرّف'); ?></label>
                    <input class="ltr" name="device_id[]" value="<?php echo e($d['id']); ?>">
                </div>
                <div>
                    <label><?php echo e($lang === 'en' ? 'Badge' : 'الرمز'); ?></label>
                    <input name="device_icon[]" value="<?php echo e($d['icon']); ?>" maxlength="4">
                </div>
                <div>
                    <label><?php echo e($lang === 'en' ? 'Color' : 'اللون'); ?></label>
                    <input type="color" name="device_color[]" value="<?php echo e($d['color']); ?>">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="actions">
            <button type="button" class="btn ghost" id="addRentalDeviceBtn">+ <?php echo e($lang === 'en' ? 'Device type' : 'نوع جهاز'); ?></button>
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>
<script>
(function () {
  var btn = document.getElementById('addRentalDeviceBtn');
  var box = document.getElementById('rentalDeviceRows');
  if (!btn || !box) return;
  btn.addEventListener('click', function () {
    var row = document.createElement('div');
    row.className = 'form-grid rental-device-row';
    row.style.marginBottom = '10px';
    row.innerHTML = ''
      + '<div><label>الاسم</label><input name="device_name[]" required></div>'
      + '<div><label>المعرّف</label><input class="ltr" name="device_id[]"></div>'
      + '<div><label>الرمز</label><input name="device_icon[]" maxlength="4" value="DV"></div>'
      + '<div><label>اللون</label><input type="color" name="device_color[]" value="#5e5ce6"></div>';
    box.appendChild(row);
  });
})();
</script>
<?php endif; ?>

<?php if ($tab === 'whatsapp'): ?>
<div class="panel">
    <h2><?php echo e(t('settings_whatsapp')); ?></h2>
    <form method="post" id="waForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="whatsapp">
        <div class="form-grid">
            <div>
                <label><?php echo e(t('gateway_url')); ?></label>
                <input class="ltr" id="gwUrl" name="whatsapp_local_url" value="<?php echo e($s['whatsapp_local_url']); ?>" required>
            </div>
            <div>
                <label><?php echo e(t('gateway_key')); ?></label>
                <input class="ltr" name="whatsapp_local_key" value="<?php echo e($s['whatsapp_local_key']); ?>" required>
            </div>
            <div>
                <label><?php echo e(t('whatsapp_on')); ?></label>
                <select name="whatsapp_enabled">
                    <option value="1" <?php echo !empty($s['whatsapp_enabled']) ? 'selected' : ''; ?>><?php echo e($lang === 'en' ? 'ON' : 'تشغيل'); ?></option>
                    <option value="0" <?php echo empty($s['whatsapp_enabled']) ? 'selected' : ''; ?>><?php echo e($lang === 'en' ? 'OFF' : 'إيقاف'); ?></option>
                </select>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Fixed note' : 'ملاحظة ثابتة'); ?></label>
                <input name="whatsapp_sender_note" value="<?php echo e($s['whatsapp_sender_note']); ?>">
            </div>
        </div>

        <div class="expiry-auto-box settings-block">
            <label class="toggle" for="expiryAutoToggle">
                <input type="checkbox" id="expiryAutoToggle" name="expiry_auto_remind_enabled" value="1"
                    <?php echo !empty($s['expiry_auto_remind_enabled']) ? 'checked' : ''; ?>>
                <span class="toggle-ui" aria-hidden="true"></span>
                <span class="toggle-text"><?php echo e($lang === 'en'
                    ? 'Auto reminder before subscription ends'
                    : 'تذكير تلقائي قبل انتهاء الاشتراك'); ?></span>
            </label>
            <div id="expiryAutoFields" class="settings-stack" style="display:none;margin-top:14px">
                <div class="settings-field settings-field-sm">
                    <label><?php echo e($lang === 'en' ? 'Days before end' : 'قبل الانتهاء بـ (يوم)'); ?></label>
                    <input type="number" min="0" max="60" name="expiry_auto_remind_days"
                        value="<?php echo (int) (isset($s['expiry_auto_remind_days']) ? $s['expiry_auto_remind_days'] : 1); ?>">
                </div>
                <p class="meta" style="margin:0">
                    <?php echo e($lang === 'en' ? 'Message text:' : 'نص الرسالة:'); ?>
                    <a href="messages.php?mode=templates"><?php echo e(t('templates')); ?></a>
                </p>
            </div>
        </div>

        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>
<script>
(function () {
  var t = document.getElementById('expiryAutoToggle');
  var f = document.getElementById('expiryAutoFields');
  function sync() {
    if (!f) return;
    f.style.display = (t && t.checked) ? 'grid' : 'none';
  }
  if (t) t.addEventListener('change', sync);
  sync();
})();
</script>

<div class="panel">
    <h2><?php echo e($lang === 'en' ? 'Connect WhatsApp (QR)' : 'ربط واتساب (QR)'); ?></h2>
    <ol class="wa-steps">
        <?php if ($lang === 'en'): ?>
            <li>On Windows PC <strong class="ltr" id="gwHost"><?php echo e($hostHint); ?></strong> run <strong>start-gateway.bat</strong> and keep the window open.</li>
            <li>Click <strong>Disconnect & reconnect</strong> below.</li>
            <li>The <strong>QR code appears in the big box under this text</strong> — scan it from WhatsApp → Linked devices.</li>
        <?php else: ?>
            <li>على جهاز الويندوز <strong class="ltr" id="gwHost"><?php echo e($hostHint); ?></strong> شغّل <strong>start-gateway.bat</strong> وخلّ النافذة مفتوحة.</li>
            <li>اضغط زر <strong>قطع الاتصال وإعادة الربط</strong> تحت.</li>
            <li>رمز <strong>QR يطلع بالمربع الكبير تحت هالنص</strong> — صوّره من واتساب → الأجهزة المرتبطة.</li>
        <?php endif; ?>
    </ol>
    <div id="wa-status" class="wa-box">...</div>
    <div id="wa-qr" class="qr-wrap qr-wrap-visible">
        <p id="wa-qr-title" style="margin:0 0 10px;font-weight:700">
            <?php echo e($lang === 'en' ? 'QR appears here ↓' : 'رمز QR يظهر هنا ↓'); ?>
        </p>
        <div id="wa-qr-placeholder" class="qr-placeholder">
            <?php echo e($lang === 'en' ? 'Waiting…' : 'بانتظار الرمز…'); ?>
        </div>
        <img id="wa-qr-img" alt="QR" style="display:none">
    </div>
    <div class="actions">
        <button class="btn secondary" type="button" onclick="checkWhatsApp(true)"><?php echo e($lang === 'en' ? 'Show / Refresh QR' : 'تحديث / إظهار QR'); ?></button>
        <button class="btn danger" type="button" onclick="logoutWhatsApp()"><?php echo e(t('reconnect_wa')); ?></button>
    </div>
</div>

<script>
(function () {
  var urlInput = document.getElementById('gwUrl');
  var hostEl = document.getElementById('gwHost');
  function syncHost() {
    try {
      var u = new URL(urlInput.value);
      hostEl.textContent = u.hostname || urlInput.value;
    } catch (e) {
      hostEl.textContent = urlInput.value;
    }
  }
  if (urlInput) urlInput.addEventListener('input', syncHost);
})();

var waBusy = false;
var qrWaitTimer = null;
var L = {
  connected: <?php echo json_encode($lang === 'en' ? 'Connected ✓ Ready' : 'متصل ✓ واتساب جاهز للإرسال'); ?>,
  needDisconnect: <?php echo json_encode($lang === 'en' ? 'Already connected. Press Disconnect to show a new QR.' : 'متصل حالياً. اضغط قطع الاتصال لإظهار QR جديد.'); ?>,
  fetching: <?php echo json_encode($lang === 'en' ? 'Not connected — fetching QR...' : 'غير متصل — جاري جلب QR...'); ?>,
  scanBelow: <?php echo json_encode($lang === 'en' ? 'Scan the QR in the box below ↓' : 'امسح رمز QR بالمربع تحت ↓'); ?>,
  waiting: <?php echo json_encode($lang === 'en' ? 'Waiting for QR…' : 'بانتظار QR…'); ?>,
  gatewayDown: <?php echo json_encode($lang === 'en'
    ? 'Cannot reach Windows gateway. On PC ' . $hostHint . ' run start-gateway.bat and keep it open.'
    : 'ما وصلت لبوابة الويندوز. على جهاز ' . $hostHint . ' شغّل start-gateway.bat وخلّ النافذة مفتوحة.'); ?>,
  scanNew: <?php echo json_encode($lang === 'en' ? 'New QR ready — scan the box below' : 'طلع QR جديد — صوّر المربع تحت'); ?>,
  confirmLogout: <?php echo json_encode($lang === 'en' ? 'Disconnect and show new QR?' : 'تقطع الاتصال وتعيد مسح QR برقم ثاني؟'); ?>,
  loggingOut: <?php echo json_encode($lang === 'en' ? 'Disconnecting… QR will appear in the box below' : 'جاري قطع الاتصال… الرمز راح يطلع بالمربع تحت'); ?>,
  pressShow: <?php echo json_encode($lang === 'en' ? 'No QR yet. Make sure start-gateway.bat is running, then press Show QR.' : 'ما طلع QR. تأكد start-gateway.bat شغّال، بعدين اضغط تحديث / إظهار QR.'); ?>
};

function setStatus(cls, text) {
  var box = document.getElementById('wa-status');
  box.className = 'wa-box ' + cls;
  box.textContent = text;
}
function showQr(dataUrl) {
  var img = document.getElementById('wa-qr-img');
  var ph = document.getElementById('wa-qr-placeholder');
  if (!dataUrl) return false;
  img.src = dataUrl;
  img.style.display = 'inline-block';
  if (ph) ph.style.display = 'none';
  document.getElementById('wa-qr').style.display = 'block';
  return true;
}
function showWaitingBox(text) {
  var img = document.getElementById('wa-qr-img');
  var ph = document.getElementById('wa-qr-placeholder');
  img.style.display = 'none';
  img.removeAttribute('src');
  if (ph) {
    ph.style.display = 'flex';
    ph.textContent = text || L.waiting;
  }
  document.getElementById('wa-qr').style.display = 'block';
}
function checkWhatsApp(forceQr) {
  if (waBusy && !forceQr) return;
  fetch('wa_proxy.php?action=status&_=' + Date.now())
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.error && !data.ready) {
        setStatus('err', data.error);
        showWaitingBox(L.gatewayDown);
        return null;
      }
      if (data && data.ready) {
        var phone = data.phone ? (' — ' + data.phone) : '';
        setStatus('ok', L.connected + phone);
        if (!forceQr) {
          document.getElementById('wa-qr-img').style.display = 'none';
          showWaitingBox(<?php echo json_encode($lang === 'en' ? 'Connected — disconnect to change number' : 'متصل — اقطع الاتصال لتغيير الرقم'); ?>);
        } else {
          setStatus('warn', L.needDisconnect);
        }
        return null;
      }
      setStatus('warn', L.fetching);
      showWaitingBox(L.waiting);
      return fetch('wa_proxy.php?action=qr&_=' + Date.now()).then(function (r) { return r.json(); });
    })
    .then(function (qr) {
      if (!qr) return;
      if (qr.error) {
        setStatus('err', qr.error);
        showWaitingBox(qr.error);
        return;
      }
      if (qr.qr_data_url && showQr(qr.qr_data_url)) {
        setStatus('warn', L.scanBelow);
      } else {
        setStatus('warn', L.waiting);
        showWaitingBox(L.waiting);
      }
    })
    .catch(function () {
      setStatus('err', L.gatewayDown);
      showWaitingBox(L.gatewayDown);
    });
}
function waitForQr(tries) {
  if (tries <= 0) {
    waBusy = false;
    setStatus('warn', L.pressShow);
    showWaitingBox(L.pressShow);
    return;
  }
  fetch('wa_proxy.php?action=qr&_=' + Date.now())
    .then(function (r) { return r.json(); })
    .then(function (qr) {
      if (qr && qr.error) {
        setStatus('err', qr.error);
        showWaitingBox(qr.error.indexOf('gateway') >= 0 || qr.error.indexOf('بوابة') >= 0 || qr.error.indexOf('reach') >= 0 ? L.gatewayDown : qr.error);
        if (tries > 10) {
          qrWaitTimer = setTimeout(function () { waitForQr(tries - 1); }, 2500);
          return;
        }
        waBusy = false;
        return;
      }
      if (qr && qr.ready) {
        setStatus('ok', L.connected);
        waBusy = false;
        return;
      }
      if (qr && qr.qr_data_url && showQr(qr.qr_data_url)) {
        setStatus('warn', L.scanNew);
        waBusy = false;
        return;
      }
      setStatus('warn', L.waiting + ' (' + tries + ')');
      showWaitingBox(L.waiting + ' (' + tries + ')');
      qrWaitTimer = setTimeout(function () { waitForQr(tries - 1); }, 2000);
    })
    .catch(function () {
      setStatus('err', L.gatewayDown);
      showWaitingBox(L.gatewayDown);
      qrWaitTimer = setTimeout(function () { waitForQr(tries - 1); }, 3000);
    });
}
function logoutWhatsApp() {
  if (!confirm(L.confirmLogout)) return;
  if (qrWaitTimer) clearTimeout(qrWaitTimer);
  waBusy = true;
  setStatus('warn', L.loggingOut);
  showWaitingBox(L.loggingOut);
  fetch('wa_proxy.php?action=logout&_=' + Date.now())
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.error && data.success === false) {
        setStatus('err', data.error);
        showWaitingBox(data.error);
        waBusy = false;
        return;
      }
      waitForQr(20);
    })
    .catch(function () {
      setStatus('err', L.gatewayDown);
      showWaitingBox(L.gatewayDown);
      waBusy = false;
    });
}
checkWhatsApp(true);
setInterval(function () { if (!waBusy) checkWhatsApp(false); }, 10000);
</script>
<?php endif; ?>

<?php if ($tab === 'sas'): ?>
<?php
$isEn = ($lang === 'en');
$sasHasPass = !empty($sasCfgUi['password']);
?>
<div class="panel">
    <h2><?php echo e(t('settings_sas')); ?></h2>
    <p style="color:#6b7a88;font-weight:600;margin-top:0">
        <?php echo e($isEn
            ? 'Save NBTel/Snono login here. Activation in this system will create/activate the user on SAS.'
            : 'احفظ دخول NBTel / سنونو هنا. التفعيل من هذا النظام ينشئ ويفعّل المشترك على SAS.'); ?>
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="sas">
        <div class="form-grid cols-4">
            <div>
                <label><?php echo e($isEn ? 'SAS link' : 'ربط SAS'); ?></label>
                <select name="sas_enabled">
                    <option value="1" <?php echo !empty($sasCfgUi['enabled']) ? 'selected' : ''; ?>><?php echo e($isEn ? 'ON' : 'تشغيل'); ?></option>
                    <option value="0" <?php echo empty($sasCfgUi['enabled']) ? 'selected' : ''; ?>><?php echo e($isEn ? 'OFF' : 'إيقاف'); ?></option>
                </select>
            </div>
            <div>
                <label>Host</label>
                <input class="ltr" name="sas_host" required
                       value="<?php echo e(!empty($sasCfgUi['host']) ? $sasCfgUi['host'] : 'reseller.nbtel.iq'); ?>"
                       placeholder="reseller.nbtel.iq">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Username' : 'اسم المستخدم'); ?></label>
                <input class="ltr" name="sas_username" required
                       value="<?php echo e(isset($sasCfgUi['username']) ? $sasCfgUi['username'] : ''); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?></label>
                <input class="ltr" type="password" name="sas_password" autocomplete="new-password"
                       placeholder="<?php echo e($sasHasPass
                           ? ($isEn ? 'Leave blank to keep current' : 'فارغ = إبقاء الحالي')
                           : ''); ?>">
            </div>
            <div>
                <label>Parent ID</label>
                <input type="number" name="sas_parent_id" min="1" step="1"
                       value="<?php echo (int) (isset($sasCfgUi['parent_id']) ? $sasCfgUi['parent_id'] : 1); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? '24h test / extend method' : 'طريقة تست 24 ساعة'); ?></label>
                <select name="sas_extend_method">
                    <option value="reward_points" <?php echo (isset($sasCfgUi['extend_method']) && $sasCfgUi['extend_method'] === 'credit') ? '' : 'selected'; ?>>
                        <?php echo e($isEn ? 'Reward points (default)' : 'نقاط تشجيعية (افتراضي)'); ?>
                    </option>
                    <option value="credit" <?php echo (isset($sasCfgUi['extend_method']) && $sasCfgUi['extend_method'] === 'credit') ? 'selected' : ''; ?>>
                        <?php echo e($isEn ? 'Manager balance' : 'رصيد المدير'); ?>
                    </option>
                </select>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Extend profile ID (optional)' : 'بروفايل التمديد (اختياري)'); ?></label>
                <input type="number" name="sas_extend_profile_id" min="0" step="1"
                       value="<?php echo (int) (isset($sasCfgUi['extend_profile_id']) ? $sasCfgUi['extend_profile_id'] : 0); ?>"
                       placeholder="<?php echo e($isEn ? '0 = auto 24h extension' : '0 = اختيار تلقائي لبروفايل التمديد'); ?>">
                <div class="hint" style="color:#6b7a88;font-size:12px;margin-top:4px">
                    <?php echo e($isEn
                        ? 'Must be an Extension profile ID from SAS, not the monthly plan profile.'
                        : 'لازم رقم بروفايل Extension من SAS، مو بروفايل الباقة الشهرية. صفر = يختار تست 24 ساعة تلقائياً.'); ?>
                </div>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Activation units' : 'وحدات التفعيل'); ?></label>
                <input type="number" name="sas_activate_units" min="1" step="1"
                       value="<?php echo (int) (isset($sasCfgUi['activate_units']) ? $sasCfgUi['activate_units'] : 1); ?>">
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
                <input class="ltr" name="sas_default_password"
                       value="<?php echo e(isset($sasCfgUi['default_password']) ? $sasCfgUi['default_password'] : ''); ?>"
                       placeholder="<?php echo e($isEn ? 'Empty = last 6 digits of phone' : 'فارغ = آخر 6 أرقام من الهاتف'); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'CPE login user (IP click)' : 'يوزر جهاز المشترك (ضغط IP)'); ?></label>
                <input class="ltr" name="cpe_http_user"
                       value="<?php echo e(isset($s['cpe_http_user']) && $s['cpe_http_user'] !== '' ? $s['cpe_http_user'] : 'ubnt'); ?>"
                       placeholder="ubnt">
            </div>
            <div>
                <label><?php echo e($isEn ? 'CPE login password' : 'باسورد جهاز المشترك'); ?></label>
                <input class="ltr" name="cpe_http_pass"
                       value="<?php echo e(isset($s['cpe_http_pass']) ? $s['cpe_http_pass'] : 'ubnt'); ?>"
                       placeholder="ubnt">
            </div>
            <div>
                <label class="toggle" style="display:flex;align-items:center;gap:10px;margin-top:22px">
                    <input type="checkbox" name="cpe_use_https" value="1" <?php echo !isset($s['cpe_use_https']) || !empty($s['cpe_use_https']) ? 'checked' : ''; ?>>
                    <span class="toggle-ui"></span>
                    <span><?php echo e($isEn ? 'Prefer HTTPS (like UISP ticket link)' : 'تفضيل HTTPS (مثل رابط التكت في UISP)'); ?></span>
                </label>
            </div>
        </div>
        <p class="meta" style="margin:0 0 10px"><?php echo e($isEn
            ? 'Clicking a subscriber IP opens the device address in a new tab (http/https only — no auto login).'
            : 'ضغط IP يفتح عنوان الجهاز بتبويب جديد مباشرة (http/https فقط — بدون تسجيل دخول تلقائي).'); ?></p>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>

<div class="panel">
    <h2><?php echo e($isEn ? 'Connection & profiles' : 'الاتصال والبروفايلات'); ?></h2>
    <table class="meta-table" style="margin:0 0 14px">
        <tr><th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
            <td><?php echo !empty($sasCfgUi['enabled']) ? ($isEn ? 'Enabled' : 'مفعّل') : ($isEn ? 'Disabled' : 'متوقف'); ?></td></tr>
        <tr><th>Host</th><td class="ltr"><?php echo e(!empty($sasCfgUi['host']) ? $sasCfgUi['host'] : '—'); ?></td></tr>
        <tr><th><?php echo e($isEn ? 'User' : 'المستخدم'); ?></th>
            <td class="ltr"><?php echo e(!empty($sasCfgUi['username']) ? $sasCfgUi['username'] : '—'); ?></td></tr>
        <tr><th>Parent ID</th><td><?php echo (int) (isset($sasCfgUi['parent_id']) ? $sasCfgUi['parent_id'] : 0); ?></td></tr>
    </table>
    <form method="post" style="margin-bottom:14px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="sas_test">
        <button class="btn" type="submit"><?php echo e($isEn ? 'Test connection & load profiles' : 'اختبار الاتصال وجلب البروفايلات'); ?></button>
        <a class="btn secondary" href="plans.php"><?php echo e($isEn ? 'Map profiles to plans' : 'ربط البروفايلات بالباقات'); ?></a>
    </form>
    <?php if ($sasLoadError !== ''): ?>
        <div class="flash error" style="margin-bottom:12px"><?php echo e($sasLoadError); ?></div>
    <?php endif; ?>
    <?php if ($sasTestOk !== null): ?>
        <div class="flash <?php echo $sasTestOk ? 'success' : 'error'; ?>" style="margin-bottom:12px">
            <?php echo e($sasTestMsg); ?>
        </div>
        <?php if ($sasRewardPoints !== null): ?>
            <p style="font-weight:700;margin-top:0">
                Reward Points:
                <?php echo e(((float) $sasRewardPoints == (int) $sasRewardPoints)
                    ? number_format((int) $sasRewardPoints)
                    : number_format((float) $sasRewardPoints, 2)); ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($sasProfiles): ?>
        <h3><?php echo e($isEn ? 'SAS profiles' : 'بروفايلات SAS'); ?></h3>
        <p style="color:#6b7a88"><?php echo e($isEn
            ? 'Monthly profiles go on the plan. Extension profiles are for 24h test / extend.'
            : 'بروفايل الباقة الشهري للباقات. بروفايل Extension (تمديد) يُستخدم للتست 24 ساعة.'); ?></p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th><?php echo e($isEn ? 'Name' : 'الاسم'); ?></th><th><?php echo e($isEn ? 'Type' : 'النوع'); ?></th><th><?php echo e($isEn ? 'Price' : 'السعر'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($sasProfiles as $pr): ?>
                    <?php if (!is_array($pr)) { continue; } ?>
                    <?php
                    $ptype = '';
                    foreach (array('type', 'profile_type', 'service_type', 'profileType') as $tk) {
                        if (!empty($pr[$tk])) {
                            $ptype = (string) $pr[$tk];
                            break;
                        }
                    }
                    ?>
                    <tr>
                        <td><strong><?php echo e(function_exists('sas_row_id') ? sas_row_id($pr) : ''); ?></strong></td>
                        <td><?php echo e(function_exists('sas_row_name') ? sas_row_name($pr) : ''); ?></td>
                        <td><?php echo e($ptype !== '' ? $ptype : '—'); ?></td>
                        <td><?php echo e(isset($pr['price']) ? $pr['price'] : ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($sasManagers): ?>
        <h3 style="margin-top:20px"><?php echo e($isEn ? 'Managers (Parent ID)' : 'المدراء (Parent ID)'); ?></h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Username</th><th><?php echo e($isEn ? 'Name' : 'الاسم'); ?></th><th>Reward Points</th></tr></thead>
                <tbody>
                <?php foreach ($sasManagers as $m): ?>
                    <?php if (!is_array($m)) { continue; } ?>
                    <tr>
                        <td><?php echo e(isset($m['id']) ? $m['id'] : ''); ?></td>
                        <td><?php echo e(isset($m['username']) ? $m['username'] : ''); ?></td>
                        <td><?php echo e(isset($m['name']) ? $m['name'] : ''); ?></td>
                        <td><?php
                            $mp = function_exists('sas_find_reward_points') ? sas_find_reward_points($m) : null;
                            echo $mp === null ? '—' : e((string) $mp);
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <h2><?php echo e($isEn ? 'Local plans ↔ SAS' : 'الباقات المحلية ↔ SAS'); ?></h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th><?php echo e($isEn ? 'Plan' : 'الباقة'); ?></th>
                <th>SAS Profile ID</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$sasPlans): ?>
                <tr><td colspan="2"><?php echo e($isEn ? 'No plans yet' : 'لا توجد باقات'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($sasPlans as $pl): ?>
                <tr>
                    <td><strong><?php echo e($pl['name']); ?></strong></td>
                    <td><?php echo !empty($pl['sas_profile_id']) ? (int) $pl['sas_profile_id'] : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="actions">
        <a class="btn secondary" href="plans.php"><?php echo e($isEn ? 'Edit plans' : 'تعديل الباقات'); ?></a>
    </div>
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

<?php render_footer(); ?>
