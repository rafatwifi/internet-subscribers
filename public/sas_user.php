<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('subscribers');

$isNew = isset($_GET['new']) && (string) $_GET['new'] === '1';
$username = isset($_GET['u']) ? trim((string) $_GET['u']) : '';
$isEn = ($lang === 'en');

if (!$isNew && $username === '') {
    flash('error', 'مشترك SAS غير محدد');
    redirect('sas.php');
}

$cache = array();
if (!$isNew) {
    $cache = function_exists('sas_cache_get') ? sas_cache_get($pdo, $username) : null;
    if (!$cache) {
        flash('error', 'المشترك مو موجود بكاش SAS — حدّث القائمة');
        redirect('sas.php');
    }
    $sasUserId = !empty($cache['sas_user_id']) ? (int) $cache['sas_user_id'] : 0;
    if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'sessions') {
        header('Content-Type: application/json; charset=utf-8');
        $storeName = !empty($cache['username']) ? (string) $cache['username'] : $username;
        if (!function_exists('sas_sessions_pull')) {
            echo json_encode(array('ok' => false, 'fresh' => 0, 'rows' => array()));
            exit;
        }
        $pack = sas_sessions_pull($pdo, $config, $storeName, $sasUserId);
        echo json_encode($pack);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect($isNew ? 'sas_user.php?new=1' : sas_user_url($username));
    }
    $action = post('action');

    if (!$isNew && $action === 'update_rental') {
        $enabled = post('rental_enabled') === '1';
        $deviceId = trim((string) post('rental_device_id', ''));
        if (!function_exists('sas_save_user_rental')) {
            flash('error', 'ملف الإيجار غير مكتمل');
            redirect(sas_user_url($username) . '#rental');
        }
        list($ok, $msg) = sas_save_user_rental($pdo, $config, $username, $enabled, $deviceId);
        flash($ok ? 'success' : 'error', $msg);
        redirect(sas_user_url($username) . '#rental');
    }

    if (!$isNew && $action === 'update_local_details') {
        $address = (string) post('address', '');
        $notes = (string) post('notes', '');
        if (!function_exists('sas_save_user_local_details')) {
            flash('error', 'تعذر حفظ التفاصيل');
            redirect(sas_user_url($username) . '#local');
        }
        $graceMode = post('grace_mode', 'system');
        $graceRaw = post('grace_days', '');
        list($ok, $msg) = sas_save_user_local_details($pdo, $config, $username, $address, $notes);
        if ($ok && function_exists('sas_save_user_grace_days')) {
            $graceVal = ($graceMode === 'custom') ? $graceRaw : 'system';
            list($gOk, $gMsg) = sas_save_user_grace_days($pdo, $config, $username, $graceVal);
            if (!$gOk) {
                $ok = false;
                $msg = $gMsg;
            }
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect(sas_user_url($username) . '#local');
    }

    if (!$isNew && ($action === 'msg_rental_only' || $action === 'msg_rental_return')) {
        list($localId, $err) = sas_cache_ensure_local($pdo, $config, $cache);
        if ($localId <= 0) {
            flash('error', $err !== '' ? $err : 'تعذر ربط المشترك');
            redirect(sas_user_url($username) . '#rental');
        }
        $st = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $st->execute(array(':id' => $localId));
        $sub = $st->fetch();
        if (!$sub || !subscriber_has_rental($sub)) {
            flash('error', 'لا يوجد جهاز إيجار لهذا المشترك');
            redirect(sas_user_url($username) . '#rental');
        }
        if ($action === 'msg_rental_only') {
            $msg = rental_only_message($sub, $config);
            $result = whatsapp_send($config, $sub['phone'], $msg, 'rental_fee');
            log_message($pdo, $localId, $result);
            flash(!empty($result['success']) ? 'success' : 'error', !empty($result['success']) ? 'تم إرسال رسالة الإيجار' : whatsapp_fail_user_message($result));
            redirect(sas_user_url($username) . '#rental');
        }
        $msg = rental_return_message($sub, $config);
        $result = whatsapp_send($config, $sub['phone'], $msg, 'rental_return');
        log_message($pdo, $localId, $result);
        if (function_exists('activity_log')) {
            activity_log($pdo, $localId, 'subscriber', $localId, 'rental_return', 'طلب استرجاع جهاز', 'اليوزرنيم: ' . $username);
        }
        flash(!empty($result['success']) ? 'success' : 'error', !empty($result['success']) ? 'تم إرسال طلب استرجاع الجهاز' : whatsapp_fail_user_message($result));
        redirect(sas_user_url($username) . '#rental');
    }

    $fields = array(
        'username' => post('username', ''),
        'firstname' => post('firstname', ''),
        'lastname' => post('lastname', ''),
        'phone' => post('phone', ''),
        'city' => post('city', ''),
        'email' => post('email', ''),
        'company' => post('company', ''),
        'enabled' => post('enabled') === '1' ? '1' : '0',
        'profile_id' => post('profile_id', '0'),
        'parent_id' => post('parent_id', '0'),
        'password' => post('password', ''),
        'confirm_password' => post('confirm_password', ''),
    );
    $pid = (int) $fields['profile_id'];
    $par = (int) $fields['parent_id'];
    if (isset($_SESSION['sas_profiles_ui']) && is_array($_SESSION['sas_profiles_ui'])) {
        foreach ($_SESSION['sas_profiles_ui'] as $pr) {
            if ((int) $pr['id'] === $pid) {
                $fields['profile_name'] = $pr['name'];
                break;
            }
        }
    }
    if (isset($_SESSION['sas_managers_ui']) && is_array($_SESSION['sas_managers_ui'])) {
        foreach ($_SESSION['sas_managers_ui'] as $mn) {
            if ((int) $mn['id'] === $par) {
                $fields['parent_name'] = $mn['name'];
                break;
            }
        }
    }
    if (empty($fields['profile_name']) || empty($fields['parent_name'])) {
        $apiTmp = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
        if ($apiTmp) {
            if (empty($fields['profile_name'])) {
                foreach (sas_profiles_for_ui($apiTmp) as $pr) {
                    if ((int) $pr['id'] === $pid) {
                        $fields['profile_name'] = $pr['name'];
                        break;
                    }
                }
            }
            if (empty($fields['parent_name'])) {
                foreach (sas_managers_for_ui($apiTmp) as $mn) {
                    if ((int) $mn['id'] === $par) {
                        $fields['parent_name'] = $mn['name'];
                        break;
                    }
                }
            }
        }
    }
    if ($isNew || $action === 'sas_create') {
        list($ok, $msg, $extra) = sas_write_user($pdo, $config, 'sas_create', '', $fields);
        flash($ok ? 'success' : 'error', $msg);
        if ($ok && !empty($extra['username'])) {
            redirect(sas_user_url($extra['username']));
        }
        redirect('sas_user.php?new=1');
    }
    list($ok, $msg, $extra) = sas_write_user($pdo, $config, 'sas_update_info', $username, $fields);
    flash($ok ? 'success' : 'error', $msg);
    if (!$ok) {
        redirect(sas_user_url($username));
    }
    $go = (!empty($extra['username'])) ? $extra['username'] : $username;
    $anchor = 'sas-row-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $go);
    redirect('sas.php?q=' . rawurlencode($go) . '#' . $anchor);
}

$fn = isset($cache['firstname']) ? $cache['firstname'] : '';
$ln = isset($cache['lastname']) ? $cache['lastname'] : '';
$phone = isset($cache['phone']) ? $cache['phone'] : '';
$enabled = $isNew ? true : !empty($cache['enabled']);
$profileId = !empty($cache['profile_id']) ? (int) $cache['profile_id'] : 0;
$parentId = !empty($cache['parent_id']) ? (int) $cache['parent_id'] : 0;
if ($isNew && function_exists('sas_config')) {
    $sc = sas_config($config);
    if ($parentId <= 0 && !empty($sc['parent_id'])) {
        $parentId = (int) $sc['parent_id'];
    }
}

$profiles = array();
$managers = array();
if (!$isNew) {
    if ($profileId > 0) {
        $profiles[] = array(
            'id' => $profileId,
            'name' => !empty($cache['profile_name']) ? $cache['profile_name'] : ('#' . $profileId),
        );
    }
    if ($parentId > 0) {
        $managers[] = array(
            'id' => $parentId,
            'name' => !empty($cache['parent_name']) ? $cache['parent_name'] : ('#' . $parentId),
        );
    }
}
if (isset($_SESSION['sas_profiles_ui']) && is_array($_SESSION['sas_profiles_ui']) && $_SESSION['sas_profiles_ui']) {
    $profiles = $_SESSION['sas_profiles_ui'];
}
if (isset($_SESSION['sas_managers_ui']) && is_array($_SESSION['sas_managers_ui']) && $_SESSION['sas_managers_ui']) {
    $managers = $_SESSION['sas_managers_ui'];
}

$pageTitle = $isNew
    ? ($isEn ? 'New User' : 'مشترك جديد')
    : ($username !== '' ? $username : ($isEn ? 'Edit User' : 'تعديل مشترك'));

$localSub = null;
if (!$isNew && $cache) {
    if (!empty($cache['local_subscriber_id'])) {
        $stLocal = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $stLocal->execute(array(':id' => (int) $cache['local_subscriber_id']));
        $localSub = $stLocal->fetch();
    }
    if (!$localSub && function_exists('sas_cache_pick_local')) {
        $localSub = sas_cache_pick_local(
            $pdo,
            $username,
            !empty($cache['sas_user_id']) ? $cache['sas_user_id'] : 0,
            isset($cache['phone']) ? $cache['phone'] : ''
        );
    }
}
$settingsRental = function_exists('settings_load') ? settings_load() : array();
$rentalDevices = function_exists('rental_devices_list') ? rental_devices_list($settingsRental) : array();
$rentalFee = function_exists('rental_fee_amount') ? rental_fee_amount($settingsRental) : 0;
$hasRent = $localSub && function_exists('subscriber_has_rental') && subscriber_has_rental($localSub);
$currentRentDev = ($hasRent && function_exists('rental_device_by_id'))
    ? rental_device_by_id($localSub['rental_device_id'], $settingsRental)
    : null;
$localAddress = ($localSub && isset($localSub['address'])) ? $localSub['address'] : '';
$localNotes = ($localSub && isset($localSub['notes'])) ? $localSub['notes'] : '';
$graceIsCustom = $localSub && function_exists('subscriber_grace_is_custom') && subscriber_grace_is_custom($localSub);
$localGraceNum = $graceIsCustom ? (int) $localSub['grace_days'] : (function_exists('subscriber_default_grace_days') ? subscriber_default_grace_days($config) : 3);
$sysGraceNum = function_exists('subscriber_default_grace_days') ? subscriber_default_grace_days($config) : 3;
$localId = ($localSub && !empty($localSub['id'])) ? (int) $localSub['id'] : 0;
$debtTotal = 0.0;
$debtRows = array();
if (!$isNew && $localId > 0 && function_exists('subscriber_unpaid_total')) {
    $debtTotal = (float) subscriber_unpaid_total($pdo, $localId);
    try {
        $stDebt = $pdo->prepare(
            'SELECT id, month_label, amount, due_date, notes
             FROM invoices
             WHERE subscriber_id = :id AND status = "unpaid"
             ORDER BY due_date ASC, id ASC
             LIMIT 8'
        );
        $stDebt->execute(array(':id' => $localId));
        $debtRows = $stDebt->fetchAll();
    } catch (Exception $eDebt) {
        $debtRows = array();
    }
}
$dispName = trim($fn . ' ' . $ln);
if ($dispName === '' && !empty($cache['display_name'])) {
    $dispName = trim((string) $cache['display_name']);
}
if ($dispName === '') {
    $dispName = $username;
}
$profileLabel = !empty($cache['profile_name']) ? (string) $cache['profile_name'] : '';
$parentLabel = !empty($cache['parent_name']) ? (string) $cache['parent_name'] : '';
$expireRaw = isset($cache['expire_at']) ? (string) $cache['expire_at'] : '';
$expireShow = function_exists('sas_format_expire_display') ? sas_format_expire_display($expireRaw) : $expireRaw;
$daysLeft = function_exists('sas_remaining_days') ? sas_remaining_days($expireRaw) : '';
$isOnlineNow = !empty($cache['is_online']);
$ipShow = isset($cache['framed_ip']) ? trim((string) $cache['framed_ip']) : '';
$macShow = isset($cache['mac_address']) ? trim((string) $cache['mac_address']) : '';
$phoneShow = function_exists('format_phone_display') ? format_phone_display($phone) : $phone;

render_header($pageTitle, 'sas', $isNew ? '' : $username);
?>
<style>
.su-page { max-width: 1120px; margin: 0 auto 28px; }
.su-hero {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
  padding: 18px 20px; border-radius: 18px; color: #f8fafc;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 70%, #1d4ed8 140%);
  box-shadow: 0 14px 34px rgba(15, 23, 42, .16);
}
.su-hero-who { display: flex; align-items: center; gap: 14px; min-width: 0; }
.su-hero h1 { margin: 0 0 4px; font-size: 22px; font-weight: 800; letter-spacing: -.02em; }
.su-avatar {
  width: 64px; height: 64px; flex: 0 0 auto; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 26px; font-weight: 800; line-height: 1; color: #fff;
  background: radial-gradient(circle at 32% 28%, rgba(255,255,255,.5), rgba(255,255,255,.08) 46%, rgba(14,165,233,.45) 100%);
  border: 2px solid rgba(255,255,255,.75);
  box-shadow: 0 10px 22px rgba(2,6,23,.28), inset 0 0 0 4px rgba(255,255,255,.12);
}
.su-hero .su-user { margin: 0; font-size: 13px; font-weight: 700; opacity: .82; direction: ltr; text-align: right; }
.su-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.su-pill {
  display: inline-flex; align-items: center; height: 26px; padding: 0 10px;
  border-radius: 999px; font-size: 12px; font-weight: 800;
  background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.16);
}
.su-pill.is-on { background: #16a34a; border-color: #16a34a; }
.su-pill.is-off { background: rgba(255,255,255,.08); }
.su-pill.is-debt { background: #b45309; border-color: #b45309; }
.su-back {
  flex: 0 0 auto; height: 34px; padding: 0 14px; border-radius: 999px;
  background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.2);
  text-decoration: none; font-weight: 800; font-size: 13px; display: inline-flex; align-items: center;
}
.su-nav {
  display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0;
}
.su-nav a, .su-nav button {
  display: inline-flex; align-items: center; justify-content: center;
  height: 36px; padding: 0 16px; border-radius: 999px; border: 1px solid #e2e8f0;
  background: #fff; color: #334155; font: inherit; font-size: 13px; font-weight: 800;
  line-height: 1; text-align: center; text-decoration: none; cursor: pointer;
}
.su-nav a:hover, .su-nav button:hover { border-color: #93c5fd; color: #1d4ed8; }
.su-nav button.is-on { background: #fff; color: #334155; border-color: #e2e8f0; }
.su-split { display: grid; grid-template-columns: 1.15fr .85fr; gap: 12px; margin-bottom: 12px; }
.su-card {
  background: #fff; border: 1px solid #e6ebf2; border-radius: 18px;
  box-shadow: 0 8px 24px rgba(15, 23, 42, .04); padding: 16px 16px 14px;
  scroll-margin-top: 72px;
}
.su-page > .su-card + .su-card { margin-top: 12px; }
.su-card h2 { margin: 0; font-size: 16px; font-weight: 800; color: #0f172a; }
.su-card-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.su-card .meta { margin: 0; color: #64748b; font-size: 12px; font-weight: 700; }
.su-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.su-fact {
  padding: 10px 12px; border-radius: 12px; background: #f8fafc; border: 1px solid #eef2f7;
}
.su-fact span { display: block; font-size: 11px; font-weight: 800; color: #64748b; margin-bottom: 3px; }
.su-fact strong { font-size: 14px; color: #0f172a; font-weight: 800; word-break: break-word; }
.su-debt-sum {
  display: flex; align-items: baseline; justify-content: space-between; gap: 10px;
  padding: 12px 14px; border-radius: 14px; background: #fff7ed; border: 1px solid #fed7aa; margin-bottom: 10px;
}
.su-debt-sum.is-clear { background: #f0fdf4; border-color: #bbf7d0; }
.su-debt-sum b { font-size: 22px; font-weight: 800; color: #9a3412; letter-spacing: -.02em; }
.su-debt-sum.is-clear b { color: #166534; }
.su-debt-list { list-style: none; margin: 0; padding: 0; }
.su-debt-list li {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 700;
}
.su-debt-list li:last-child { border-bottom: 0; }
.su-sec { margin: 0 0 16px; }
.su-sec h3 { margin: 0 0 10px; font-size: 13px; font-weight: 800; color: #475569; }
.sas-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
.sas-grid label { display: block; font-size: 12px; color: #334155; font-weight: 800; }
.sas-grid input, .sas-grid select, .su-card input, .su-card select {
  width: 100%; margin-top: 4px; height: 40px; padding: 0 12px;
  border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; font: inherit;
}
.req { color: #dd4b39; }
.su-switch-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.sas-switch { position: relative; width: 46px; height: 24px; display: inline-block; }
.sas-switch input { opacity: 0; width: 0; height: 0; }
.sas-switch span { position: absolute; inset: 0; background: #cbd5e1; border-radius: 24px; cursor: pointer; transition: .2s; }
.sas-switch span:before {
  content: ""; position: absolute; height: 18px; width: 18px; left: 3px; top: 3px;
  background: #fff; border-radius: 50%; transition: .2s;
}
.sas-switch input:checked + span { background: #16a34a; }
.sas-switch input:checked + span:before { transform: translateX(22px); }
.sas-form-actions { margin-top: 14px; display: flex; gap: 8px; flex-wrap: wrap; }
.sas-more-btn { background: none; border: 0; color: #1d4ed8; font: inherit; font-weight: 800; cursor: pointer; padding: 0; margin: 0 0 10px; }
.sas-more-box.hidden { display: none; }
#sasSessionsNote { font-size: 13px; color: #475569; font-weight: 700; margin: 0 0 8px; }
#sasSessionsTable { width: 100%; border-collapse: collapse; }
#sasSessionsTable th, #sasSessionsTable td { padding: 8px 10px; border-bottom: 1px solid #eef2f7; text-align: start; font-size: 13px; white-space: nowrap; }
#sasSessionsTable th { background: #f8fafc; font-weight: 800; color: #334155; }
#sasSessionsPanel .table-wrap { overflow-x: auto; }
.su-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 860px) {
  .su-split, .su-pair, .sas-grid, .su-facts { grid-template-columns: 1fr; }
  .su-hero { flex-direction: column; }
}
</style>

<div class="su-page">
<div class="su-hero">
    <div class="su-hero-who">
        <?php if (!$isNew): ?>
            <?php
            $avatarLetter = '•';
            $avatarSrc = trim($dispName);
            if ($avatarSrc !== '' && function_exists('mb_substr')) {
                $avatarLetter = mb_substr($avatarSrc, 0, 1, 'UTF-8');
            } elseif ($avatarSrc !== '') {
                $avatarLetter = substr($avatarSrc, 0, 1);
            }
            ?>
            <span class="su-avatar" aria-hidden="true"><?php echo e($avatarLetter); ?></span>
        <?php endif; ?>
        <div>
        <h1><?php echo e($isNew ? ($isEn ? 'New subscriber' : 'مشترك جديد') : $dispName); ?></h1>
        <?php if (!$isNew && $username !== ''): ?>
            <p class="su-user"><?php echo e($username); ?></p>
        <?php endif; ?>
        <?php if (!$isNew): ?>
        <div class="su-pills">
            <span class="su-pill<?php echo $enabled ? ' is-on' : ' is-off'; ?>"><?php echo e($enabled ? ($isEn ? 'Enabled' : 'مفعّل') : ($isEn ? 'Disabled' : 'موقف')); ?></span>
            <span class="su-pill<?php echo $isOnlineNow ? ' is-on' : ' is-off'; ?>"><?php echo e($isOnlineNow ? ($isEn ? 'Online' : 'متصل') : ($isEn ? 'Offline' : 'منفصل')); ?></span>
            <?php if ($profileLabel !== ''): ?><span class="su-pill"><?php echo e($profileLabel); ?></span><?php endif; ?>
            <?php if ($debtTotal > 0): ?><span class="su-pill is-debt"><?php echo e(money_format_iqd($debtTotal, $config['currency'])); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
    </div>
    <a class="su-back" href="sas.php"><?php echo e($isEn ? 'Back' : 'رجوع'); ?></a>
</div>
<?php if (!$isNew): ?>
<nav class="su-nav">
    <a href="#su-info"><?php echo e($isEn ? 'Subscriber' : 'بيانات المشترك'); ?></a>
    <a href="#su-edit"><?php echo e($isEn ? 'Edit' : 'تعديل البيانات'); ?></a>
    <a href="#su-debt"><?php echo e($isEn ? 'Debts' : 'موجز الديون'); ?></a>
    <button type="button" id="sasSessionsBtn"><?php echo e($isEn ? 'Sessions' : 'الجلسات'); ?></button>
</nav>
<div class="su-split">
    <section class="su-card" id="su-info">
        <div class="su-card-h">
            <h2><?php echo e($isEn ? 'Subscriber' : 'بيانات المشترك'); ?></h2>
        </div>
        <div class="su-facts">
            <div class="su-fact"><span><?php echo e($isEn ? 'Name' : 'الاسم'); ?></span><strong><?php echo e($dispName !== '' ? $dispName : '—'); ?></strong></div>
            <div class="su-fact"><span><?php echo e($isEn ? 'Username' : 'اليوزرنيم'); ?></span><strong dir="ltr"><?php echo e($username !== '' ? $username : '—'); ?></strong></div>
            <div class="su-fact"><span><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?></span><strong dir="ltr"><?php echo e($phoneShow !== '' ? $phoneShow : '—'); ?></strong></div>
            <div class="su-fact"><span><?php echo e($isEn ? 'Profile' : 'الباقة'); ?></span><strong><?php echo e($profileLabel !== '' ? $profileLabel : '—'); ?></strong></div>
            <div class="su-fact"><span><?php echo e($isEn ? 'Belongs to' : 'تابع الى'); ?></span><strong><?php echo e($parentLabel !== '' ? $parentLabel : '—'); ?></strong></div>
            <div class="su-fact"><span><?php echo e($isEn ? 'Expires' : 'الانتهاء'); ?></span><strong dir="ltr"><?php echo e($expireShow !== '' ? $expireShow : '—'); ?><?php if ($daysLeft !== ''): ?> · <?php echo e($daysLeft . ($isEn ? ' d' : ' يوم')); ?><?php endif; ?></strong></div>
            <div class="su-fact"><span>IP</span><strong dir="ltr"><?php echo e($ipShow !== '' ? $ipShow : '—'); ?></strong></div>
            <div class="su-fact"><span>MAC</span><strong dir="ltr"><?php echo e($macShow !== '' ? $macShow : '—'); ?></strong></div>
            <?php if ($localAddress !== ''): ?>
            <div class="su-fact"><span><?php echo e($isEn ? 'Address' : 'العنوان'); ?></span><strong><?php echo e($localAddress); ?></strong></div>
            <?php endif; ?>
            <?php if ($localNotes !== ''): ?>
            <div class="su-fact"><span><?php echo e($isEn ? 'Notes' : 'ملاحظات'); ?></span><strong><?php echo e($localNotes); ?></strong></div>
            <?php endif; ?>
        </div>
    </section>
    <section class="su-card" id="su-debt">
        <div class="su-card-h">
            <h2><?php echo e($isEn ? 'Debts' : 'موجز الديون'); ?></h2>
            <?php if ($localId > 0 && $debtTotal > 0): ?>
                <a class="btn sm" href="debts.php?status=unpaid&subscriber_id=<?php echo (int) $localId; ?>"><?php echo e($isEn ? 'Pay' : 'تسديد'); ?></a>
            <?php endif; ?>
        </div>
        <div class="su-debt-sum<?php echo $debtTotal > 0 ? '' : ' is-clear'; ?>">
            <span><?php echo e($debtTotal > 0 ? ($isEn ? 'Unpaid' : 'غير مسدد') : ($isEn ? 'No debt' : 'ماكو ديون')); ?></span>
            <b><?php echo e(money_format_iqd($debtTotal, $config['currency'])); ?></b>
        </div>
        <?php if ($debtRows): ?>
        <ul class="su-debt-list">
            <?php foreach ($debtRows as $drow): ?>
                <li>
                    <span><?php echo e(function_exists('invoice_debt_label') ? invoice_debt_label($drow) : (isset($drow['month_label']) ? $drow['month_label'] : '')); ?></span>
                    <strong><?php echo e(money_format_iqd($drow['amount'], $config['currency'])); ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php elseif ($localId <= 0): ?>
            <p class="meta"><?php echo e($isEn ? 'This line is not linked to a local subscriber yet.' : 'هذا الخط مو مربوط بمشترك محلي بعد.'); ?></p>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
<section class="su-card" id="su-edit">
    <div class="su-card-h">
        <h2><?php echo e($isNew ? ($isEn ? 'New' : 'مشترك جديد') : ($isEn ? 'Edit' : 'تعديل البيانات')); ?></h2>
    </div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo e($isNew ? 'sas_create' : 'sas_update_info'); ?>">

        <div class="su-sec">
            <div class="su-switch-row">
                <h3 style="margin:0"><?php echo e($isEn ? 'Account' : 'الحساب'); ?></h3>
                <label class="sas-switch" title="<?php echo e($isEn ? 'Enabled' : 'مفعّل'); ?>">
                    <input type="checkbox" name="enabled" value="1"<?php echo $enabled ? ' checked' : ''; ?>>
                    <span></span>
                </label>
            </div>
            <div class="sas-grid">
                <div>
                    <label><?php echo e($isEn ? 'Username' : 'اليوزرنيم'); ?> <span class="req">*</span></label>
                    <input name="username" value="<?php echo e($username); ?>" required>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?></label>
                    <input name="phone" type="tel" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" value="<?php echo e($phoneShow); ?>">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Password' : 'كلمة السر'); ?><?php echo $isNew ? ' <span class="req">*</span>' : ''; ?></label>
                    <input type="password" name="password" id="sasPass" autocomplete="new-password"<?php echo $isNew ? ' required' : ''; ?>>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Confirm Password' : 'تأكيد كلمة السر'); ?><?php echo $isNew ? ' <span class="req">*</span>' : ''; ?></label>
                    <input type="password" name="confirm_password" id="sasPass2" autocomplete="new-password"<?php echo $isNew ? ' required' : ''; ?>>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Service Profile' : 'البروفايل'); ?> <span class="req">*</span></label>
                    <select name="profile_id" id="sasProfileSelect" required>
                        <option value=""><?php echo e($isEn ? 'Loading…' : 'جاري التحميل…'); ?></option>
                        <?php foreach ($profiles as $pr): ?>
                            <option value="<?php echo (int) $pr['id']; ?>"<?php echo ((int) $pr['id'] === $profileId) ? ' selected' : ''; ?>><?php echo e($pr['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Belongs to' : 'تابع الى'); ?> <span class="req">*</span></label>
                    <select name="parent_id" id="sasParentSelect" required>
                        <option value=""><?php echo e($isEn ? 'Loading…' : 'جاري التحميل…'); ?></option>
                        <?php foreach ($managers as $mn): ?>
                            <option value="<?php echo (int) $mn['id']; ?>"<?php echo ((int) $mn['id'] === $parentId) ? ' selected' : ''; ?>><?php echo e($mn['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'First Name' : 'الاسم الأول'); ?></label>
                    <input name="firstname" value="<?php echo e($fn); ?>">
                </div>
            </div>
        </div>

        <button type="button" class="sas-more-btn" id="sasMoreBtn"><?php echo e($isEn ? '+ More fields' : '+ حقول إضافية'); ?></button>
        <div class="sas-sec sas-more-box hidden" id="sasMoreBox">
            <h3><?php echo e($isEn ? 'More' : 'المزيد'); ?></h3>
            <div class="sas-grid">
                <div>
                    <label><?php echo e($isEn ? 'Last Name' : 'الاسم الأخير'); ?></label>
                    <input name="lastname" value="<?php echo e($ln); ?>">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'City' : 'المدينة'); ?></label>
                    <input name="city" value="<?php echo e(isset($cache['city']) ? $cache['city'] : ''); ?>">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Company' : 'الشركة'); ?></label>
                    <input name="company" value="<?php echo e(isset($cache['company']) ? $cache['company'] : ''); ?>">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Email' : 'الإيميل'); ?></label>
                    <input name="email" value="<?php echo e(isset($cache['email']) ? $cache['email'] : ''); ?>">
                </div>
            </div>
        </div>

        <div class="sas-form-actions">
            <button class="btn" type="submit"><?php echo e($isNew ? ($isEn ? 'Create' : 'إنشاء') : ($isEn ? 'Save' : 'حفظ')); ?></button>
            <a class="btn ghost" href="sas.php"><?php echo e($isEn ? 'Cancel' : 'إلغاء'); ?></a>
        </div>
    </form>
</section>
<?php if (!$isNew): ?>
<section class="su-card" id="su-sessions">
    <div class="su-card-h">
        <h2><?php echo e($isEn ? 'Sessions' : 'الجلسات'); ?></h2>
        <span class="meta" id="sasSessionsHint"><?php echo e($isEn ? 'Latest connections from SAS' : 'آخر الاتصالات من الساس'); ?></span>
    </div>
    <div id="sasSessionsPanel" hidden>
        <div id="sasSessionsNote"></div>
        <div class="table-wrap">
            <table class="data-table" id="sasSessionsTable">
                <thead>
                <tr>
                    <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                    <th>MAC</th>
                    <th>IP</th>
                    <th><?php echo e($isEn ? 'Start' : 'البداية'); ?></th>
                    <th><?php echo e($isEn ? 'End' : 'النهاية'); ?></th>
                    <th><?php echo e($isEn ? 'Duration' : 'المدة'); ?></th>
                    <th><?php echo e($isEn ? 'Upload' : 'الرفع'); ?></th>
                    <th><?php echo e($isEn ? 'Download' : 'التحميل'); ?></th>
                    <th><?php echo e($isEn ? 'Usage' : 'الاستهلاك'); ?></th>
                </tr>
                </thead>
                <tbody id="sasSessionsBody"></tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!$isNew): ?>
<div class="su-pair">
<section class="su-card" id="rental">
    <div class="su-card-h">
        <h2><?php echo e($isEn ? 'Rental device' : 'جهاز إيجار'); ?></h2>
        <span class="meta"><?php echo e($isEn ? 'On this server only' : 'على السيرفر فقط'); ?></span>
    </div>
    <form method="post" id="rentalForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="update_rental">
        <div class="actions toggle-row" style="margin-top:0">
            <label class="toggle">
                <input type="checkbox" name="rental_enabled" value="1" id="rentalEnabledChk" <?php echo $hasRent ? 'checked' : ''; ?>>
                <span class="toggle-ui"></span>
                <span class="toggle-text"><?php echo e($isEn ? 'Rental device' : 'جهاز إيجار'); ?></span>
            </label>
            <span class="meta" style="font-weight:700;color:var(--muted)">
                <?php echo e($isEn ? 'Fee' : 'الإيجار'); ?>:
                <?php echo e(money_format_iqd($rentalFee, $config['currency'])); ?> / <?php echo e($isEn ? 'month' : 'شهر'); ?>
            </span>
        </div>
        <div id="rentalDetails"<?php echo $hasRent ? '' : ' hidden'; ?> style="<?php echo $hasRent ? '' : 'display:none'; ?>">
            <div style="margin-top:12px;max-width:360px">
                <label><?php echo e($isEn ? 'Device type' : 'نوع الجهاز'); ?></label>
                <select name="rental_device_id" id="rentalDeviceSelect"<?php echo $hasRent ? '' : ' disabled'; ?>>
                    <option value=""><?php echo e($isEn ? 'Choose…' : 'اختر…'); ?></option>
                    <?php foreach ($rentalDevices as $d): ?>
                        <option value="<?php echo e($d['id']); ?>" <?php echo ($hasRent && $localSub && $localSub['rental_device_id'] === $d['id']) ? 'selected' : ''; ?>>
                            <?php echo e($d['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($currentRentDev): ?>
                    <div style="margin-top:10px">
                        <?php echo rental_badge_html($localSub, $settingsRental); ?>
                        <strong style="margin-right:8px"><?php echo e($currentRentDev['name']); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
            <div class="actions" style="margin-top:12px">
                <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
            </div>
        </div>
        <div id="rentalSaveOff" class="actions"<?php echo $hasRent ? ' hidden' : ''; ?> style="margin-top:12px;<?php echo $hasRent ? 'display:none' : ''; ?>">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
    <div id="rentalMsgActions" class="actions"<?php echo $hasRent ? '' : ' hidden'; ?> style="margin-top:10px;<?php echo $hasRent ? '' : 'display:none'; ?>">
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="msg_rental_only">
            <button class="btn secondary" type="submit"><?php echo e($isEn ? 'Send rent message' : 'رسالة الإيجار فقط'); ?></button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('<?php echo e($isEn ? 'Send device return request?' : 'إرسال طلب استرجاع الجهاز؟'); ?>');">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="msg_rental_return">
            <button class="btn danger" type="submit"><?php echo e($isEn ? 'Request device return' : 'طلب استرجاع الجهاز'); ?></button>
        </form>
    </div>
</section>

<section class="su-card" id="local">
    <div class="su-card-h">
        <h2><?php echo e($isEn ? 'Local details' : 'تفاصيل محلية'); ?></h2>
    </div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="update_local_details">
        <div class="sas-grid">
            <div>
                <label><?php echo e($isEn ? 'Address' : 'العنوان'); ?></label>
                <input name="address" value="<?php echo e($localAddress); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Notes' : 'ملاحظات'); ?></label>
                <input name="notes" value="<?php echo e($localNotes); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Grace days after activation' : 'أيام السماح بعد التفعيل'); ?></label>
                <select name="grace_mode" id="graceModeSel" style="width:100%;margin-top:4px">
                    <option value="system" <?php echo !$graceIsCustom ? 'selected' : ''; ?>>
                        <?php echo e($isEn ? ('Follow system (' . $sysGraceNum . ' days)') : ('حسب النظام (' . $sysGraceNum . ' يوم)')); ?>
                    </option>
                    <option value="custom" <?php echo $graceIsCustom ? 'selected' : ''; ?>>
                        <?php echo e($isEn ? 'Custom number only' : 'رقم مخصص (حصراً)'); ?>
                    </option>
                </select>
                <input type="number" name="grace_days" id="graceDaysInp" min="0" max="90" step="1"
                       value="<?php echo (int) $localGraceNum; ?>"
                       style="margin-top:6px;<?php echo $graceIsCustom ? '' : 'display:none'; ?>">
                <p class="meta" style="margin:6px 0 0"><?php echo e($isEn
                    ? 'System = settings default. Custom = this subscriber only.'
                    : 'حسب النظام = قيمة الإعدادات. الرقم المخصص يستثني المشترك من إعداد النظام.'); ?></p>
            </div>
        </div>
        <div class="sas-form-actions">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Save details' : 'حفظ التفاصيل'); ?></button>
        </div>
    </form>
</section>
</div>
<?php endif; ?>
</div>
<script>
(function () {
  var phoneInp = document.querySelector('input[name="phone"]');
  function localPhone11(raw) {
    var d = String(raw || '').replace(/\D/g, '');
    if (d.indexOf('964') === 0 && d.length >= 12) d = '0' + d.slice(3);
    return d.replace(/\D/g, '').slice(0, 11);
  }
  if (phoneInp) {
    phoneInp.addEventListener('input', function () {
      var next = localPhone11(phoneInp.value);
      if (phoneInp.value !== next) phoneInp.value = next;
    });
    if (phoneInp.form) {
      phoneInp.form.addEventListener('submit', function (ev) {
        if (String(phoneInp.value || '').replace(/\D/g, '') === '') return;
        var digits = localPhone11(phoneInp.value);
        phoneInp.value = digits;
        if (digits.length !== 11) {
          ev.preventDefault();
          alert(<?php echo json_encode($isEn ? 'Phone must be 11 digits' : 'رقم الهاتف لازم يكون 11 رقم'); ?>);
        }
      });
    }
  }
  var mode = document.getElementById('graceModeSel');
  var inp = document.getElementById('graceDaysInp');
  if (mode && inp) {
    function syncGrace() {
      inp.style.display = mode.value === 'custom' ? '' : 'none';
    }
    mode.addEventListener('change', syncGrace);
    syncGrace();
  }
  var moreBtn = document.getElementById('sasMoreBtn');
  var moreBox = document.getElementById('sasMoreBox');
  if (moreBtn && moreBox) {
    moreBtn.addEventListener('click', function () {
      moreBox.classList.toggle('hidden');
      moreBtn.textContent = moreBox.classList.contains('hidden')
        ? <?php echo json_encode($isEn ? '+ More fields' : '+ حقول إضافية'); ?>
        : <?php echo json_encode($isEn ? 'Hide extra fields' : 'إخفاء الحقول الإضافية'); ?>;
    });
  }
  var pass = document.getElementById('sasPass');
  var pass2 = document.getElementById('sasPass2');
  if (pass && pass2) {
    pass.addEventListener('input', function () {
      if (pass2.value === '' || pass2.dataset.auto === '1') {
        pass2.value = pass.value;
        pass2.dataset.auto = '1';
      }
    });
    pass2.addEventListener('input', function () { pass2.dataset.auto = '0'; });
  }
  function fillSelect(sel, items, selected) {
    if (!sel) return;
    var cur = selected || sel.value;
    sel.innerHTML = '';
    var blank = document.createElement('option');
    blank.value = '';
    blank.textContent = <?php echo json_encode($isEn ? 'Select…' : 'اختر…'); ?>;
    sel.appendChild(blank);
    (items || []).forEach(function (p) {
      var o = document.createElement('option');
      o.value = String(p.id);
      o.textContent = p.name || ('#' + p.id);
      if (String(p.id) === String(cur)) o.selected = true;
      sel.appendChild(o);
    });
  }
  var profSel = document.getElementById('sasProfileSelect');
  var parSel = document.getElementById('sasParentSelect');
  var profNow = <?php echo json_encode((string) $profileId); ?>;
  var parNow = <?php echo json_encode((string) $parentId); ?>;
  fetch('sas.php?ajax=profiles', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { fillSelect(profSel, (d && d.profiles) ? d.profiles : [], profNow); })
    .catch(function () {});
  fetch('sas.php?ajax=managers', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { fillSelect(parSel, (d && d.managers) ? d.managers : [], parNow); })
    .catch(function () {});

  var rentChk = document.getElementById('rentalEnabledChk');
  var rentDetails = document.getElementById('rentalDetails');
  var rentSaveOff = document.getElementById('rentalSaveOff');
  var rentMsgs = document.getElementById('rentalMsgActions');
  var rentSelect = document.getElementById('rentalDeviceSelect');
  function setShown(el, on) {
    if (!el) return;
    if (on) {
      el.removeAttribute('hidden');
      el.style.display = '';
      el.classList.remove('hidden');
    } else {
      el.setAttribute('hidden', 'hidden');
      el.style.display = 'none';
      el.classList.add('hidden');
    }
  }
  function syncRentalUi() {
    if (!rentChk) return;
    var on = !!rentChk.checked;
    setShown(rentDetails, on);
    setShown(rentSaveOff, !on);
    setShown(rentMsgs, on);
    if (rentSelect) rentSelect.disabled = !on;
  }
  if (rentChk) {
    rentChk.addEventListener('change', syncRentalUi);
    syncRentalUi();
  }
})();
</script>
<?php if (!$isNew): ?>
<script>
(function () {
  var btn = document.getElementById('sasSessionsBtn');
  var panel = document.getElementById('sasSessionsPanel');
  var body = document.getElementById('sasSessionsBody');
  var note = document.getElementById('sasSessionsNote');
  if (!btn || !panel || !body) return;
  var timer = null;
  var busy = false;
  var open = false;
  var url = 'sas_user.php?u=' + encodeURIComponent(<?php echo json_encode($username); ?>) + '&ajax=sessions';
  var txtOpen = <?php echo json_encode($isEn ? 'Online' : 'متصل'); ?>;
  var txtOff = <?php echo json_encode($isEn ? 'Offline' : 'منفصل'); ?>;
  var txtEmpty = <?php echo json_encode($isEn ? 'No sessions for this subscriber' : 'ماكو جلسات لهذا المشترك'); ?>;
  var txtStale = <?php echo json_encode($isEn ? 'Could not refresh from SAS. Showing the saved sessions.' : 'تعذر التحديث من الساس. هذه آخر جلسات محفوظة.'); ?>;
  var txtFail = <?php echo json_encode($isEn ? 'Could not load sessions from SAS' : 'تعذر جلب الجلسات من الساس'); ?>;
  var txtMonth = <?php echo json_encode($isEn ? 'This month' : 'استهلاك هذا الشهر'); ?>;
  var txtUp = <?php echo json_encode($isEn ? 'Upload' : 'رفع'); ?>;
  var txtDown = <?php echo json_encode($isEn ? 'Download' : 'تحميل'); ?>;
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
    });
  }
  function paint(d) {
    var rows = (d && d.rows) ? d.rows : [];
    if (!d || !d.ok) {
      if (note && !body.querySelector('tr td[dir="ltr"]')) note.textContent = txtFail;
      return;
    }
    if (!rows.length) {
      if (note) note.textContent = txtEmpty;
    } else if (note) {
      note.textContent = '';
    }
    if (note && d && d.month_used) {
      var monthLine = txtMonth + ': ' + d.month_used;
      if (d.month_up) monthLine += ' · ' + txtUp + ' ' + d.month_up;
      if (d.month_down) monthLine += ' · ' + txtDown + ' ' + d.month_down;
      note.textContent = (note.textContent ? (note.textContent + ' — ') : '') + monthLine;
    }
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="9">—</td></tr>';
      return;
    }
    var html = '';
    var i;
    for (i = 0; i < rows.length; i++) {
      var r = rows[i] || {};
      html += '<tr>'
        + '<td>' + esc(r.open ? txtOpen : txtOff) + '</td>'
        + '<td dir="ltr">' + esc(r.mac || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.ip || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.start || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.stop || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.duration || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.up || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.down || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.used || '—') + '</td>'
        + '</tr>';
    }
    body.innerHTML = html;
  }
  function load() {
    if (!open || busy) return;
    busy = true;
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false;
        if (open) paint(d);
      })
      .catch(function () {
        busy = false;
        if (open) paint(null);
      });
  }
  btn.addEventListener('click', function () {
    open = !open;
    panel.hidden = !open;
    if (timer) { clearInterval(timer); timer = null; }
    if (open) {
      var sec = document.getElementById('su-sessions');
      if (sec && sec.scrollIntoView) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
      load();
      timer = setInterval(load, 4000);
    }
  });
  open = true;
  panel.hidden = false;
  load();
  timer = setInterval(load, 4000);
})();
</script>
<?php endif; ?>
<?php
render_footer();
