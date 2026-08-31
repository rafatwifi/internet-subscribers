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
        $graceDays = (int) post('grace_days', '3');
        if ($graceDays < 0) {
            $graceDays = 0;
        }
        list($ok, $msg) = sas_save_user_local_details($pdo, $config, $username, $address, $notes);
        if ($ok && function_exists('sas_save_user_grace_days')) {
            list($gOk, $gMsg) = sas_save_user_grace_days($pdo, $config, $username, $graceDays);
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
$localGrace = function_exists('subscriber_grace_days')
    ? subscriber_grace_days($localSub ? $localSub : array(), $config)
    : 3;

render_header($pageTitle, 'sas', $isNew ? '' : $username);
?>
<style>
.sas-form-page { background: #fff; border: 1px solid #d2d6de; padding: 0 18px 24px; }
.sas-tabs { display: flex; gap: 18px; border-bottom: 1px solid #eee; margin: 0 -18px 18px; padding: 12px 18px 0; overflow-x: auto; }
.sas-tabs a { color: #555; text-decoration: none; padding: 8px 4px 10px; font-weight: 600; white-space: nowrap; }
.sas-tabs a.on { color: #3c8dbc; border-bottom: 2px solid #3c8dbc; }
.sas-sec { margin: 0 0 22px; }
.sas-sec h3 { font-size: 15px; margin: 0 0 12px; color: #444; }
.sas-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
@media (max-width: 800px) { .sas-grid { grid-template-columns: 1fr; } }
.sas-grid label { display: block; font-size: 13px; color: #333; font-weight: 600; }
.sas-grid input, .sas-grid select {
  width: 100%; margin-top: 4px; padding: 8px 10px; border: 1px solid #d2d6de; border-radius: 2px; background: #fbfbfb;
}
.req { color: #dd4b39; }
.sas-toggle { display: flex; align-items: center; gap: 10px; margin-top: 22px; }
.sas-switch { position: relative; width: 46px; height: 24px; display: inline-block; }
.sas-switch input { opacity: 0; width: 0; height: 0; }
.sas-switch span {
  position: absolute; inset: 0; background: #ccc; border-radius: 24px; cursor: pointer; transition: .2s;
}
.sas-switch span:before {
  content: ""; position: absolute; height: 18px; width: 18px; left: 3px; top: 3px;
  background: #fff; border-radius: 50%; transition: .2s;
}
.sas-switch input:checked + span { background: #3c8dbc; }
.sas-switch input:checked + span:before { transform: translateX(22px); }
.sas-form-actions { margin-top: 16px; display: flex; gap: 8px; }
.sas-more-btn { background: none; border: 0; color: #3c8dbc; font: inherit; font-weight: 700; cursor: pointer; padding: 0; margin: 0 0 10px; }
.sas-more-box.hidden { display: none; }
.sas-user-row { display: flex; align-items: end; gap: 16px; }
.sas-user-row > div:first-child { flex: 1; }
</style>

<p style="margin:0 0 10px"><a class="btn ghost sm" href="sas.php"><?php echo e($isEn ? 'Back' : 'رجوع'); ?></a></p>
<div class="sas-form-page">
    <div class="sas-tabs">
        <a class="on" href="#"><?php echo e($isNew ? ($isEn ? 'New' : 'جديد') : ($isEn ? 'Edit' : 'تعديل')); ?></a>
    </div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo e($isNew ? 'sas_create' : 'sas_update_info'); ?>">

        <div class="sas-sec">
            <h3><?php echo e($isEn ? 'Account' : 'الحساب'); ?></h3>
            <div class="sas-grid">
                <div>
                    <label><?php echo e($isEn ? 'Username' : 'اليوزرنيم'); ?> <span class="req">*</span></label>
                    <input name="username" value="<?php echo e($username); ?>" required>
                </div>
                <div class="sas-toggle">
                    <label><?php echo e($isEn ? 'Enabled' : 'مفعّل'); ?></label>
                    <label class="sas-switch">
                        <input type="checkbox" name="enabled" value="1"<?php echo $enabled ? ' checked' : ''; ?>>
                        <span></span>
                    </label>
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
                <div>
                    <label><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?></label>
                    <input name="phone" value="<?php echo e($phone); ?>">
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
</div>

<?php if (!$isNew): ?>
<div class="sas-form-page" id="rental" style="margin-top:16px">
    <h2 style="font-size:16px;margin:16px 0 10px"><?php echo e($isEn ? 'Rental device' : 'جهاز إيجار'); ?></h2>
    <p class="meta" style="margin:0 0 12px"><?php echo e($isEn ? 'Stored on this server — not shown in SAS.' : 'يُحفظ على السيرفر — ما يظهر بكومنت الساس.'); ?></p>
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
</div>

<div class="sas-form-page" id="local" style="margin-top:16px">
    <h2 style="font-size:16px;margin:16px 0 10px"><?php echo e($isEn ? 'Local details' : 'تفاصيل محلية'); ?></h2>
    <p class="meta" style="margin:0 0 12px"><?php echo e($isEn ? 'Address and notes stay on this server only.' : 'العنوان والملاحظات تبقى على هذا السيرفر فقط.'); ?></p>
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
                <input type="number" name="grace_days" min="0" max="30" step="1" value="<?php echo (int) $localGrace; ?>">
            </div>
        </div>
        <div class="sas-form-actions">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Save details' : 'حفظ التفاصيل'); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>
<script>
(function () {
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
<?php
render_footer();
