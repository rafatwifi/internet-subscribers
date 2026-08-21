<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/settings_tabs.php';
require_login();

$tab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'general';
if (!in_array($tab, array('general', 'whatsapp', 'templates', 'rental', 'users', 'plans', 'sas'), true)) {
    $tab = 'general';
}

if ($tab === 'users') {
    require_perm('users');
} elseif ($tab === 'plans') {
    redirect('plans.php');
} else {
    require_perm('settings');
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
    } elseif ($section === 'clear_data') {
        require_perm('clear_data');
        $pass = (string) post('admin_password', '');
        $me = current_admin();
        $okPass = false;
        if ($me && $me['id'] > 0) {
            $okPass = verify_user_password($pdo, $me['id'], $pass);
        }
        if (!$okPass && isset($config['admin_password']) && hash_equals((string) $config['admin_password'], $pass)) {
            $okPass = true;
        }
        if (!$okPass) {
            flash('error', $lang === 'en' ? 'Wrong password' : 'كلمة المرور غير صحيحة');
            redirect('settings.php?tab=general');
        }
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->exec('TRUNCATE TABLE message_logs');
            $pdo->exec('TRUNCATE TABLE invoices');
            $pdo->exec('TRUNCATE TABLE subscriptions');
            $pdo->exec('TRUNCATE TABLE subscribers');
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            activity_log($pdo, null, 'system', null, 'clear_data', 'مسح كل البيانات', '');
            flash('success', $lang === 'en' ? 'All data cleared' : 'تم مسح كل البيانات');
        } catch (Exception $e) {
            flash('error', 'Clear failed: ' . $e->getMessage());
        }
        redirect('settings.php?tab=general');
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
            'grace_days' => (int) post('grace_days', '2'),
            'subscription_period_mode' => $periodMode,
        );
        set_lang_preference($data['language']);
        $tab = 'general';
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
            'tpl_expiry_soon' => (string) post('tpl_expiry_soon', ''),
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
            'tpl_days_left' => (string) post('tpl_days_left', ''),
            'tpl_unpaid_overdue' => (string) post('tpl_unpaid_overdue', ''),
            'unpaid_remind_after_days' => $afterDays,
        );
        $tab = 'templates';
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
        );
        $tab = 'sas';
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
} elseif ($tab === 'templates') {
    $activeNav = 'templates';
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
$gMs = isset($sys['google']['ms']) ? (int) $sys['google']['ms'] : null;
$diskPct = (int) $sys['disk']['pct_used'];
$diskTone = ($diskPct >= 90) ? 'bad' : (($diskPct >= 75) ? 'warn' : 'ok');
$ramPct = (int) $sys['ram']['pct_used'];
$ramTone = ($sys['ram']['source'] === 'host')
    ? (($ramPct >= 90) ? 'bad' : (($ramPct >= 75) ? 'warn' : 'ok'))
    : 'ok';
?>
<div class="panel">
    <div class="sys-status-head">
        <h2 style="margin:0"><?php echo e($lang === 'en' ? 'System status' : 'حالة النظام'); ?></h2>
        <a class="btn ghost sm" href="settings.php?tab=general"><?php echo e($lang === 'en' ? 'Refresh' : 'تحديث'); ?></a>
    </div>
    <div class="sys-status-grid">
        <div class="sys-card tone-ok">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'Version' : 'الإصدار'); ?></div>
            <div class="sys-card-v">v<?php echo e($sys['version']); ?></div>
            <div class="sys-card-s">PHP <?php echo e($sys['php']); ?></div>
        </div>
        <div class="sys-card tone-<?php echo e($diskTone); ?>">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'Disk free' : 'المساحة'); ?></div>
            <div class="sys-card-v"><?php echo e($sys['disk']['ok'] ? $sys['disk']['label'] : '—'); ?></div>
            <div class="sys-card-s"><?php echo e($lang === 'en' ? 'Used' : 'مستخدم'); ?> <?php echo (int) $diskPct; ?>%</div>
        </div>
        <div class="sys-card tone-<?php echo e($ramTone); ?>">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'RAM' : 'الرام'); ?></div>
            <div class="sys-card-v"><?php echo e($sys['ram']['label']); ?></div>
            <div class="sys-card-s">
                <?php if ($sys['ram']['source'] === 'host'): ?>
                    <?php echo e($lang === 'en' ? 'Used' : 'مستخدم'); ?> <?php echo (int) $ramPct; ?>%
                <?php else: ?>
                    peak <?php echo e(format_bytes_short($sys['ram']['php_peak'])); ?>
                <?php endif; ?>
            </div>
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
        <div class="sys-card tone-<?php echo $gOk ? 'ok' : 'bad'; ?>">
            <div class="sys-card-k"><?php echo e($lang === 'en' ? 'Google latency' : 'اتصال كوكل (Latency)'); ?></div>
            <div class="sys-card-v"><?php echo $gMs !== null ? ((int) $gMs . ' ms') : '—'; ?></div>
            <div class="sys-card-s"><?php echo $gOk ? e($lang === 'en' ? 'Reachable' : 'متاح') : e($lang === 'en' ? 'Failed' : 'فشل'); ?></div>
        </div>
    </div>
    <p class="meta" style="margin:10px 0 0"><?php echo e($lang === 'en' ? 'Server time' : 'وقت السيرفر'); ?>: <?php echo e($sys['server_time']); ?></p>
</div>

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
                <label><?php echo e($lang === 'en' ? 'Grace days' : 'أيام السماح'); ?></label>
                <input type="number" min="0" name="grace_days" value="<?php echo (int) $s['grace_days']; ?>">
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

<?php if (user_can('clear_data')): ?>
<div class="panel">
    <h2><?php echo e(t('clear_data')); ?></h2>
    <p style="color:#6b7a88;font-weight:600">
        <?php echo e($lang === 'en'
            ? 'Deletes all subscribers, movements, invoices and message logs. Packages stay.'
            : 'يحذف كل المشتركين والحركات والفواتير وسجل الرسائل. الباقات تبقى.'); ?>
    </p>
    <form method="post" onsubmit="return confirm('<?php echo e(t('confirm_clear')); ?>');">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="clear_data">
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e(t('password')); ?></label>
                <input type="password" name="admin_password" required placeholder="<?php echo e($lang === 'en' ? 'Your password' : 'رمزك'); ?>">
            </div>
        </div>
        <div class="actions">
            <button class="btn danger" type="submit"><?php echo e(t('clear_data')); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>
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
                <div class="settings-field">
                    <label><?php echo e($lang === 'en' ? 'Reminder message' : 'نص رسالة التذكير'); ?></label>
                    <textarea name="tpl_expiry_soon" rows="4"><?php echo e(isset($s['tpl_expiry_soon']) ? $s['tpl_expiry_soon'] : ''); ?></textarea>
                </div>
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
        </div>
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

<?php if ($tab === 'templates'): ?>
<div class="panel">
    <h2><?php echo e(t('settings_templates')); ?></h2>
    <form method="post" class="settings-templates-form">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="section" value="templates">
        <div class="settings-stack">
            <div class="settings-field">
                <label><?php echo e(t('msg_debt_remind')); ?></label>
                <textarea name="tpl_debt_remind" rows="3"><?php echo e($s['tpl_debt_remind']); ?></textarea>
            </div>
            <div class="settings-field">
                <label><?php echo e($lang === 'en' ? 'Days-left reminder' : 'رسالة الأيام المتبقية'); ?></label>
                <textarea name="tpl_days_left" rows="3"><?php echo e(isset($s['tpl_days_left']) ? $s['tpl_days_left'] : ''); ?></textarea>
            </div>
            <div class="settings-field settings-field-sm">
                <label><?php echo e($lang === 'en' ? 'Unpaid after (days)' : 'تنبيه عدم التسديد بعد (يوم)'); ?></label>
                <input type="number" name="unpaid_remind_after_days" min="1" max="365"
                    value="<?php echo (int) (isset($s['unpaid_remind_after_days']) ? $s['unpaid_remind_after_days'] : 7); ?>">
            </div>
            <div class="settings-field">
                <label><?php echo e($lang === 'en' ? 'Unpaid / cut warning message' : 'رسالة المتأخرين (تفعيل بدون تسديد)'); ?></label>
                <textarea name="tpl_unpaid_overdue" rows="4"><?php echo e(isset($s['tpl_unpaid_overdue']) ? $s['tpl_unpaid_overdue'] : ''); ?></textarea>
            </div>
            <div class="settings-field">
                <label><?php echo e(t('msg_debt_created')); ?></label>
                <textarea name="tpl_debt_created" rows="4"><?php echo e(isset($s['tpl_debt_created']) ? $s['tpl_debt_created'] : ''); ?></textarea>
            </div>
            <div class="settings-field">
                <label><?php echo e(t('msg_payment_ok')); ?></label>
                <textarea name="tpl_payment_ok" rows="4"><?php echo e($s['tpl_payment_ok']); ?></textarea>
            </div>
            <div class="settings-field">
                <label><?php echo e(t('msg_activation')); ?></label>
                <textarea name="tpl_activation" rows="4"><?php echo e($s['tpl_activation']); ?></textarea>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>
<?php render_footer(); ?>
