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
if (!in_array($tab, array('general', 'whatsapp', 'rental', 'users', 'plans', 'sas', 'saas', 'schedule', 'sensitive', 'update'), true)) {
    $tab = 'general';
}

$isAgentWaOnly = ($tab === 'whatsapp' && function_exists('is_agent_user') && is_agent_user());

if ($tab === 'users') {
    require_perm('users');
} elseif ($tab === 'plans') {
    redirect('plans.php');
} elseif ($tab === 'schedule') {
    redirect('schedule.php');
} elseif ($tab === 'sensitive') {
    require_perm('clear_data');
} elseif ($tab === 'update') {
    require_perm('settings');
} elseif ($tab === 'saas') {
    require_perm('settings');
    if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
        flash('error', $lang === 'en' ? 'Super admin only' : 'للمدير العام فقط');
        redirect('settings.php');
    }
} elseif ($isAgentWaOnly) {
    // الوكيل: QR فقط — بدون صلاحية settings كاملة
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
        require_perm('users');
        $data = array(
            'maint_block_activate' => post('maint_block_activate') === '1',
            'maint_block_give_test' => post('maint_block_give_test') === '1',
        );
        $tab = 'users';
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
            'login_session_days' => max(1, min(30, (int) post('login_session_days', '3'))),
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
        $data = array(
            'whatsapp_enabled' => post('whatsapp_enabled') === '1',
            'whatsapp_provider' => 'local',
            'whatsapp_local_url' => $url,
            'whatsapp_local_key' => (string) post('whatsapp_local_key', 'local-secret-change-me'),
            'whatsapp_sender_note' => (string) post('whatsapp_sender_note', ''),
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
        // شركة غير 1: احفظ على صف الـ tenant فقط — لا تلمس settings العامة
        $tidSas = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        if ($tidSas > 1 && function_exists('tenant_save')) {
            $passKeep = $pass;
            if ($passKeep === '') {
                $rowKeep = function_exists('tenant_row') ? tenant_row($pdo, $tidSas) : null;
                $passKeep = $rowKeep && !empty($rowKeep['sas_password']) ? (string) $rowKeep['sas_password'] : '';
            }
            $tenantData = array(
                'sas_enabled' => !empty($data['sas_enabled']) ? 1 : 0,
                'sas_host' => $data['sas_host'],
                'sas_username' => $data['sas_username'],
                'sas_password' => $passKeep,
                'sas_parent_id' => $data['sas_parent_id'],
                'sas_default_password' => $data['sas_default_password'],
                'sas_activate_units' => $data['sas_activate_units'],
                'sas_extend_method' => $data['sas_extend_method'],
                'sas_extend_profile_id' => $data['sas_extend_profile_id'],
                'sas_on_failure' => $data['sas_on_failure'],
            );
            if (tenant_save($pdo, $tidSas, $tenantData)) {
                $testAction = post('action');
                if ($testAction === 'test' || post('sas_test') === '1') {
                    list($okT, $msgT) = tenant_test_sas_connection(
                        $tenantData['sas_host'],
                        $tenantData['sas_username'],
                        $passKeep
                    );
                    if (function_exists('sas_mark_connection')) {
                        sas_mark_connection($pdo, $config, $okT, $msgT, $tidSas);
                    }
                    flash($okT ? 'success' : 'error', $msgT);
                } else {
                    // فحص سريع بعد الحفظ
                    list($okT, $msgT) = tenant_test_sas_connection(
                        $tenantData['sas_host'],
                        $tenantData['sas_username'],
                        $passKeep
                    );
                    if (function_exists('sas_mark_connection')) {
                        sas_mark_connection($pdo, $config, $okT, $msgT, $tidSas);
                    }
                    flash($okT ? 'success' : 'error', $okT
                        ? (($lang === 'en' ? 'Saved — connected' : 'تم الحفظ — متصل بهذا المكان') . ($msgT !== '' ? ': ' . $msgT : ''))
                        : (($lang === 'en' ? 'Saved but not connected: ' : 'تم الحفظ لكن غير متصل: ') . $msgT));
                }
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
        $plans = array(
            'monthly' => array(
                'label' => trim((string) post('plan_monthly_label', 'شهري')),
                'days' => max(1, (int) post('plan_monthly_days', '30')),
                'amount' => max(0, (float) post('plan_monthly_amount', '25000')),
            ),
            'yearly' => array(
                'label' => trim((string) post('plan_yearly_label', 'سنوي')),
                'days' => max(1, (int) post('plan_yearly_days', '365')),
                'amount' => max(0, (float) post('plan_yearly_amount', '250000')),
            ),
        );
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
$hostHint = parse_url(isset($s['whatsapp_local_url']) ? $s['whatsapp_local_url'] : '', PHP_URL_HOST);
if (!$hostHint) {
    $hostHint = '172.16.16.13';
}
$adminUsers = list_admin_users($pdo);
$settingsAgents = list_agent_users($pdo, false);
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
                <select name="role" id="addUserRole">
                    <?php foreach (admin_roles() as $rOpt): ?>
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
    <script>
    (function () {
      var roleSel = document.getElementById('addUserRole');
      var wrap = document.getElementById('addLinkedAgentWrap');
      if (!roleSel || !wrap) return;
      function sync() { wrap.style.display = roleSel.value === 'accountant' ? '' : 'none'; }
      roleSel.addEventListener('change', sync);
      sync();
    })();
    </script>
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
                $ulinked = isset($u['linked_agent_id']) ? (int) $u['linked_agent_id'] : 0;
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
                                <select name="role" class="user-role-select" style="max-width:120px">
                                    <?php foreach (admin_roles() as $rOpt): ?>
                                        <option value="<?php echo e($rOpt); ?>" <?php echo $urole === $rOpt ? 'selected' : ''; ?>><?php echo e(admin_role_label($rOpt, $lang)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="linked_agent_id" class="user-linked-agent" style="max-width:130px;<?php echo $urole === 'accountant' ? '' : 'display:none'; ?>">
                                    <option value="0"><?php echo e($lang === 'en' ? 'Agent…' : 'وكيل…'); ?></option>
                                    <?php foreach ($settingsAgents as $sag):
                                        $sid = (int) $sag['id'];
                                        ?>
                                        <option value="<?php echo $sid; ?>"<?php echo $ulinked === $sid ? ' selected' : ''; ?>><?php echo e($sag['display_name']); ?></option>
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
    <script>
    (function () {
      document.querySelectorAll('.user-edit-form').forEach(function (form) {
        var roleSel = form.querySelector('.user-role-select');
        var linkedSel = form.querySelector('.user-linked-agent');
        if (!roleSel || !linkedSel) return;
        function sync() { linkedSel.style.display = roleSel.value === 'accountant' ? '' : 'none'; }
        roleSel.addEventListener('change', sync);
      });
    })();
    </script>
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
                <label><?php echo e($lang === 'en' ? 'Keep me logged in (days)' : 'مدة حفظ الدخول (أيام)'); ?></label>
                <input type="number" min="1" max="30" name="login_session_days" value="<?php echo (int) (isset($s['login_session_days']) ? $s['login_session_days'] : 3); ?>">
                <small style="color:#6b7a88;font-weight:600"><?php echo e($lang === 'en' ? 'Session cookie duration (1–30). Default 3 days.' : 'مدة كوكي الجلسة (1–30). الافتراضي 3 أيام.'); ?></small>
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
            <?php if ($loginBgUrl !== ''): ?>
                <button class="btn ghost bg-opt-image" type="submit" name="login_bg_remove" value="1" data-bg-opt="image"><?php echo e($lang === 'en' ? 'Remove image' : 'حذف الصورة'); ?></button>
            <?php endif; ?>
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
<style>
.wa-layout { display:grid; grid-template-columns: minmax(0,1fr) minmax(280px,420px); gap:16px; align-items:start; }
@media (max-width: 960px) { .wa-layout { grid-template-columns: 1fr; } }
.wa-card { border:1px solid var(--line,#e2e8f0); border-radius:16px; background:rgba(255,255,255,.92); padding:18px; box-shadow:0 8px 28px rgba(15,23,42,.06); }
.wa-card h2 { margin:0 0 6px; font-size:1.15rem; }
.wa-card .wa-lead { margin:0 0 14px; color:var(--muted,#64748b); font-weight:600; line-height:1.55; font-size:13px; }
.wa-tips { margin:0 0 14px; padding:12px 14px; border-radius:12px; background:linear-gradient(135deg,#f0f9ff,#f8fafc); border:1px solid #dbeafe; color:#334155; font-size:13px; font-weight:600; line-height:1.65; }
.wa-tips strong { color:#0f172a; }
.wa-status-pill { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:12px; font-weight:800; margin-bottom:14px; border:1px solid transparent; }
.wa-status-pill.ok { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
.wa-status-pill.warn { background:#fffbeb; color:#b45309; border-color:#fde68a; }
.wa-status-pill.err { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
.wa-status-dot { width:10px; height:10px; border-radius:50%; background:currentColor; flex:0 0 auto; box-shadow:0 0 0 4px rgba(0,0,0,.06); }
.wa-qr-stage { border-radius:16px; border:1px dashed #cbd5e1; background:radial-gradient(circle at 30% 20%,#f8fafc,#eef2ff 70%,#f1f5f9); min-height:300px; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:18px; text-align:center; }
.wa-qr-stage img { max-width:min(280px,100%); border-radius:12px; background:#fff; padding:10px; box-shadow:0 10px 30px rgba(15,23,42,.12); }
.wa-qr-stage .wa-qr-hint { margin-top:12px; color:#64748b; font-weight:700; font-size:13px; }
.wa-actions-row { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
.wa-actions-row .btn { flex:1 1 140px; justify-content:center; }
</style>

<div class="wa-layout">
  <?php if (!$isAgentWaOnly): ?>
  <div class="wa-card">
    <h2><?php echo e(t('settings_whatsapp')); ?></h2>
    <p class="wa-lead"><?php echo e($lang === 'en'
        ? 'Gateway must run on the Windows PC. After reboot it starts hidden if you installed auto-start.'
        : 'البوابة لازم تشتغل على جهاز الويندوز. بعد الريبوت تشتغل مخفية إذا ثبّت التشغيل التلقائي.'); ?></p>
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
        <div class="actions" style="margin-top:12px">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
    <div class="wa-tips" style="margin-top:16px">
      <?php if ($lang === 'en'): ?>
        On PC <strong class="ltr" id="gwHost"><?php echo e($hostHint); ?></strong>: run <strong>install-autostart.bat</strong> once so gateway starts after reboot.
        If phone says linking blocked, wait 15–30 minutes. Use Disconnect only when you want a new QR.
      <?php else: ?>
        على الجهاز <strong class="ltr" id="gwHost"><?php echo e($hostHint); ?></strong>: شغّل <strong>install-autostart.bat</strong> مرة واحدة حتى تشتغل البوابة بعد الريبوت تلقائياً.
        إذا الهاتف قال يتعذر الربط: انتظر 15–30 دقيقة. «قطع الاتصال» فقط لما تريد QR جديد.
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="wa-card">
    <h2><?php echo e($lang === 'en' ? 'Link WhatsApp' : 'ربط واتساب'); ?></h2>
    <p class="wa-lead"><?php echo e($isAgentWaOnly
        ? ($lang === 'en'
            ? 'Your messages use your own WhatsApp session on the shared gateway. Scan QR once here.'
            : 'رسائلك تُرسل من جلسة واتساب خاصة بك على البوابة المشتركة. امسح QR هنا مرة واحدة.')
        : ($lang === 'en'
            ? 'When disconnected, a QR appears here. Scan once — or disconnect to relink.'
            : 'عند تسجيل الخروج يظهر QR هنا. امسحه مرة واحدة — أو افصل لإعادة الربط.')); ?></p>
    <div id="wa-status" class="wa-status-pill warn"><span class="wa-status-dot"></span><span id="wa-status-text">...</span></div>
    <div id="wa-qr" class="wa-qr-stage">
        <img id="wa-qr-img" alt="QR" style="display:none">
        <div id="wa-qr-placeholder"><?php echo e($lang === 'en' ? 'Waiting for QR…' : 'بانتظار رمز QR…'); ?></div>
        <div class="wa-qr-hint" id="wa-qr-title"><?php echo e($lang === 'en' ? 'QR shows automatically when gateway is ready' : 'الرمز يظهر تلقائياً لما البوابة جاهزة'); ?></div>
    </div>
    <div class="wa-actions-row">
        <button class="btn" type="button" onclick="checkWhatsApp(true)"><?php echo e($lang === 'en' ? 'Show QR' : 'إظهار QR'); ?></button>
        <button class="btn danger" type="button" onclick="logoutWhatsApp()"><?php echo e($lang === 'en' ? 'Disconnect & relink' : 'قطع الاتصال وإعادة الربط'); ?></button>
    </div>
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
  connected: <?php echo json_encode($lang === 'en' ? 'Connected — ready to send' : 'متصل — جاهز للإرسال'); ?>,
  needDisconnect: <?php echo json_encode($lang === 'en' ? 'Already connected. Press Disconnect for a new QR.' : 'متصل حالياً. اضغط قطع الاتصال لـ QR جديد.'); ?>,
  fetching: <?php echo json_encode($lang === 'en' ? 'Fetching QR…' : 'جاري جلب QR…'); ?>,
  scanBelow: <?php echo json_encode($lang === 'en' ? 'Scan the QR below once' : 'امسح رمز QR تحت مرة واحدة'); ?>,
  waiting: <?php echo json_encode($lang === 'en' ? 'Waiting for QR…' : 'بانتظار QR…'); ?>,
  gatewayDown: <?php echo json_encode($lang === 'en'
    ? 'Cannot reach Windows gateway. On PC ' . $hostHint . ' run install-autostart.bat or start-gateway.bat.'
    : 'ما وصلت لبوابة الويندوز. على جهاز ' . $hostHint . ' شغّل install-autostart.bat أو start-gateway.bat.'); ?>,
  scanNew: <?php echo json_encode($lang === 'en' ? 'New QR ready — scan once' : 'QR جديد جاهز — امسحه مرة واحدة'); ?>,
  confirmLogout: <?php echo json_encode($lang === 'en' ? 'Disconnect and show a new QR?' : 'تقطع الاتصال وتعرض QR جديد؟'); ?>,
  loggingOut: <?php echo json_encode($lang === 'en' ? 'Disconnecting… QR in ~12 seconds' : 'جاري قطع الاتصال… QR خلال ~12 ثانية'); ?>,
  pressShow: <?php echo json_encode($lang === 'en' ? 'No QR yet. Wait a moment, then press Show QR once.' : 'ما طلع QR بعد. انتظر شوي، بعدين اضغط إظهار QR مرة واحدة.'); ?>,
  rateLimit: <?php echo json_encode($lang === 'en' ? 'WhatsApp blocked linking temporarily. Wait 15–30 minutes.' : 'واتساب حظر الربط مؤقتاً. انتظر 15–30 دقيقة.'); ?>,
  certExpired: <?php echo json_encode($lang === 'en'
    ? 'TLS/cert issue on the PC (often antivirus). Gateway retries with WA_TLS_INSECURE. Wait for QR — do not spam refresh.'
    : 'مشكلة شهادة/TLS على الحاسبة (غالباً أنتيفايروس). البوابة تعيد المحاولة تلقائياً. انتظر QR — لا تضغط تحديث مرّات.'); ?>,
  tlsRetry: <?php echo json_encode($lang === 'en' ? 'Retrying connection (TLS workaround)…' : 'إعادة اتصال (تجاوز TLS)…'); ?>
};

function setStatus(cls, text) {
  var box = document.getElementById('wa-status');
  var tx = document.getElementById('wa-status-text');
  box.className = 'wa-status-pill ' + cls;
  if (tx) tx.textContent = text; else box.textContent = text;
}
function showQr(dataUrl) {
  var img = document.getElementById('wa-qr-img');
  var ph = document.getElementById('wa-qr-placeholder');
  if (!dataUrl) return false;
  img.src = dataUrl;
  img.style.display = 'inline-block';
  if (ph) ph.style.display = 'none';
  return true;
}
function showWaitingBox(text) {
  var img = document.getElementById('wa-qr-img');
  var ph = document.getElementById('wa-qr-placeholder');
  img.style.display = 'none';
  img.removeAttribute('src');
  if (ph) {
    ph.style.display = 'block';
    ph.textContent = text || L.waiting;
  }
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
          showWaitingBox(<?php echo json_encode($lang === 'en' ? 'Connected — disconnect to change number' : 'متصل — اقطع الاتصال لتغيير الرقم'); ?>);
        } else {
          setStatus('warn', L.needDisconnect);
        }
        return null;
      }
      if (data && (data.status === 'cert_expired' || data.status === 'tls_retry')) {
        setStatus('warn', data.status === 'tls_retry' ? L.tlsRetry : L.certExpired);
        showWaitingBox(data.status === 'tls_retry' ? L.tlsRetry : L.certExpired);
        if (!forceQr && !(data && data.has_qr)) return null;
      }
      if (!forceQr && !(data && data.has_qr)) {
        var waitMsg = L.pressShow;
        if (data && data.status === 'logout_cooldown') waitMsg = L.loggingOut;
        setStatus('warn', waitMsg);
        showWaitingBox(waitMsg);
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
        qrWaitTimer = setTimeout(function () { waitForQr(tries - 1); }, 4000);
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
      qrWaitTimer = setTimeout(function () { waitForQr(tries - 1); }, 4000);
    })
    .catch(function () {
      setStatus('err', L.gatewayDown);
      showWaitingBox(L.gatewayDown);
      qrWaitTimer = setTimeout(function () { waitForQr(tries - 1); }, 5000);
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
      setTimeout(function () { waitForQr(20); }, 8000);
    })
    .catch(function () {
      setStatus('err', L.gatewayDown);
      showWaitingBox(L.gatewayDown);
      waBusy = false;
    });
}
checkWhatsApp(false);
setInterval(function () {
  if (!waBusy) checkWhatsApp(false);
}, 20000);
</script>
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
    ? ($isEn ? 'SAS login' : 'تسجيل الدخول عبر SAS')
    : t('settings_sas');
$stOk = $sasConnStatus && !empty($sasConnStatus['ok']);
$stReady = $sasConnStatus && !empty($sasConnStatus['ready']);
$stLabel = $sasConnStatus && isset($sasConnStatus['label']) ? $sasConnStatus['label'] : '';
$stDetail = $sasConnStatus && isset($sasConnStatus['detail']) ? $sasConnStatus['detail'] : '';
?>
<div class="panel">
    <h2><?php echo e($sasTitle); ?></h2>
    <p style="color:#6b7a88;font-weight:600;margin-top:0">
        <?php echo e($isEn
            ? 'Enter your SAS reseller login. Activation here creates/activates the subscriber on SAS.'
            : 'أدخل بيانات دخول لوحة الساس. التفعيل من هذا النظام ينشئ ويفعّل المشترك على الساس.'); ?>
    </p>
    <?php if ($stReady || $stLabel !== ''): ?>
    <div class="alert <?php echo $stOk ? 'alert-success' : 'alert-error'; ?>" style="margin:10px 0;font-weight:700">
        <?php
        if ($stOk) {
            echo e($isEn ? 'Connected to this place' : 'متصل بهذا المكان');
        } elseif (!$stReady) {
            echo e($stLabel !== '' ? $stLabel : ($isEn ? 'Not configured' : 'غير مضبوط'));
        } else {
            echo e($stDetail !== '' ? $stDetail : ($stLabel !== '' ? $stLabel : ($isEn ? 'Not connected' : 'غير متصل')));
        }
        ?>
    </div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="sas">
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
            <?php if ($sasTenantIdUi <= 1): ?>
            <a class="btn secondary" href="plans.php"><?php echo e($isEn ? 'Local packages' : 'الباقات المحلية'); ?></a>
            <?php endif; ?>
        </div>
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
$pm = isset($plansUi['monthly']) && is_array($plansUi['monthly']) ? $plansUi['monthly'] : array('label' => 'شهري', 'days' => 30, 'amount' => 25000);
$py = isset($plansUi['yearly']) && is_array($plansUi['yearly']) ? $plansUi['yearly'] : array('label' => 'سنوي', 'days' => 365, 'amount' => 250000);
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
        <h3 style="font-size:15px;margin:18px 0 8px"><?php echo e($isEn ? 'Plans (IQD)' : 'الباقات (دينار)'); ?></h3>
        <div class="form-grid cols-3">
            <div>
                <label><?php echo e($isEn ? 'Monthly label' : 'تسمية الشهري'); ?></label>
                <input name="plan_monthly_label" value="<?php echo e(isset($pm['label']) ? $pm['label'] : 'شهري'); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Days' : 'الأيام'); ?></label>
                <input class="ltr" type="number" min="1" name="plan_monthly_days" value="<?php echo e(isset($pm['days']) ? (int) $pm['days'] : 30); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Amount IQD' : 'المبلغ دينار'); ?></label>
                <input class="ltr" type="number" min="0" step="1" name="plan_monthly_amount" value="<?php echo e(isset($pm['amount']) ? (int) $pm['amount'] : 25000); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Yearly label' : 'تسمية السنوي'); ?></label>
                <input name="plan_yearly_label" value="<?php echo e(isset($py['label']) ? $py['label'] : 'سنوي'); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Days' : 'الأيام'); ?></label>
                <input class="ltr" type="number" min="1" name="plan_yearly_days" value="<?php echo e(isset($py['days']) ? (int) $py['days'] : 365); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Amount IQD' : 'المبلغ دينار'); ?></label>
                <input class="ltr" type="number" min="0" step="1" name="plan_yearly_amount" value="<?php echo e(isset($py['amount']) ? (int) $py['amount'] : 250000); ?>">
            </div>
        </div>
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
