<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('subscribers');

$username = isset($_GET['u']) ? trim((string) $_GET['u']) : '';
if ($username === '') {
    flash('error', 'مشترك SAS غير محدد');
    redirect('sas.php');
}

$cache = function_exists('sas_cache_get') ? sas_cache_get($pdo, $username) : null;
if (!$cache) {
    flash('error', 'المشترك مو موجود بكاش SAS — حدّث القائمة');
    redirect('sas.php');
}

$sasUserId = !empty($cache['sas_user_id']) ? (int) $cache['sas_user_id'] : 0;
if (function_exists('sas_cache_refresh_one')) {
    @sas_cache_refresh_one($pdo, $config, $username, $sasUserId);
    $fresh = sas_cache_get($pdo, $username);
    if ($fresh) {
        $cache = $fresh;
        $sasUserId = !empty($cache['sas_user_id']) ? (int) $cache['sas_user_id'] : $sasUserId;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect(sas_user_url($username));
    }
    $action = post('action');
    $focus = '';
    $fields = array(
        'name' => post('name', ''),
        'phone' => post('phone', ''),
        'enabled' => post('enabled', ''),
        'profile_id' => post('profile_id', '0'),
        'profile_name' => post('profile_name', ''),
        'units' => post('units', '1'),
        'pin' => post('pin', ''),
        'card_id' => post('card_id', '0'),
    );
    if ($action === 'sas_update_info') {
        $fields['enabled'] = post('enabled') === '1' ? '1' : '0';
    }
    if ($action === 'sas_change_profile') {
        $focus = 'profile';
        $pid = (int) post('profile_id', '0');
        $fields['profile_name'] = '';
        $apiTmp = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
        if ($apiTmp) {
            foreach (sas_profiles_for_ui($apiTmp) as $pr) {
                if ((int) $pr['id'] === $pid) {
                    $fields['profile_name'] = $pr['name'];
                    break;
                }
            }
        }
    }
    if ($action === 'sas_activate_card' || $action === 'sas_activate_credit') {
        $focus = 'activate';
    }
    list($ok, $msg) = sas_write_user($pdo, $config, $action, $username, $fields);
    flash($ok ? 'success' : 'error', $msg);
    redirect(sas_user_url($username, $focus));
}

$name = !empty($cache['display_name']) ? $cache['display_name'] : $username;
$phone = !empty($cache['phone']) ? $cache['phone'] : '';
$phoneDisp = function_exists('format_phone_display') ? format_phone_display($phone) : $phone;
$enabled = !empty($cache['enabled']);
$profileId = !empty($cache['profile_id']) ? (int) $cache['profile_id'] : 0;
$profileName = !empty($cache['profile_name']) ? $cache['profile_name'] : '';
$expireAt = !empty($cache['expire_at']) ? $cache['expire_at'] : '';
$localId = !empty($cache['local_subscriber_id']) ? (int) $cache['local_subscriber_id'] : 0;
$daysLeft = null;
if ($expireAt !== '') {
    $daysLeft = (int) ceil((strtotime($expireAt) - strtotime(date('Y-m-d'))) / 86400);
}

$profiles = array();
$cards = array();
$api = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
if ($api) {
    $profiles = sas_profiles_for_ui($api);
    $cards = sas_cards_for_ui($api, $profileId);
}

$focus = isset($_GET['focus']) ? (string) $_GET['focus'] : '';
$isEn = ($lang === 'en');

render_header($name, 'sas', $username);
?>
<style>
.sas-user-grid { display: grid; gap: 16px; }
@media (min-width: 900px) {
  .sas-user-grid { grid-template-columns: 1fr 1fr; }
}
.sas-user-grid .span-2 { grid-column: 1 / -1; }
.sas-card-list { max-height: 260px; overflow: auto; margin: 8px 0 0; padding: 0; list-style: none; }
.sas-card-list li { margin: 0 0 6px; }
.sas-card-list label {
  display: flex; gap: 8px; align-items: center; padding: 8px 10px;
  border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer;
}
.sas-mode-row { display: flex; gap: 14px; flex-wrap: wrap; margin: 10px 0; }
.sas-mode-row label { font-weight: 700; display: flex; gap: 6px; align-items: center; }
</style>

<div class="actions sub-toolbar">
    <a class="btn ghost" href="sas.php"><?php echo e($isEn ? 'Back to SAS' : 'رجوع للساس'); ?></a>
</div>

<div class="cards">
    <div class="card-stat glass <?php echo $enabled ? 'g-green' : 'g-red'; ?>">
        <div class="label"><?php echo e($isEn ? 'SAS status' : 'حالة الساس'); ?></div>
        <div class="value" style="font-size:18px"><?php echo e($enabled ? ($isEn ? 'Enabled' : 'شغّال') : ($isEn ? 'Disabled' : 'موقوف')); ?></div>
    </div>
    <div class="card-stat glass g-blue">
        <div class="label"><?php echo e(t('days_left')); ?></div>
        <div class="value<?php echo ($daysLeft !== null && $daysLeft < 0) ? ' days-neg' : ''; ?>"><?php echo $daysLeft !== null ? (int) $daysLeft : '—'; ?></div>
        <div class="hint"><?php echo $expireAt !== '' ? e($expireAt) : ''; ?></div>
    </div>
    <div class="card-stat glass g-cyan">
        <div class="label"><?php echo e(t('package')); ?></div>
        <div class="value" style="font-size:16px"><?php echo e($profileName !== '' ? $profileName : '—'); ?></div>
        <div class="hint"><?php echo e($username); ?></div>
    </div>
</div>

<div class="sas-user-grid">
    <div class="panel glass-panel" id="info">
        <h2><?php echo e($isEn ? 'Edit on SAS' : 'تعديل بالساس'); ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="sas_update_info">
            <div class="form-grid">
                <div>
                    <label><?php echo e(t('name')); ?></label>
                    <input name="name" value="<?php echo e($name); ?>" required>
                </div>
                <div>
                    <label><?php echo e(t('phone')); ?></label>
                    <input name="phone" value="<?php echo e($phoneDisp); ?>">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Username' : 'اليوزرنيم'); ?></label>
                    <input value="<?php echo e($username); ?>" disabled>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Account' : 'الحساب'); ?></label>
                    <select name="enabled">
                        <option value="1"<?php echo $enabled ? ' selected' : ''; ?>><?php echo e($isEn ? 'Enable' : 'تشغيل'); ?></option>
                        <option value="0"<?php echo !$enabled ? ' selected' : ''; ?>><?php echo e($isEn ? 'Disable' : 'إيقاف'); ?></option>
                    </select>
                </div>
            </div>
            <div class="actions">
                <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
            </div>
        </form>
    </div>

    <div class="panel glass-panel" id="profile">
        <h2><?php echo e($isEn ? 'Change package' : 'تغيير نوع الاشتراك'); ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="sas_change_profile">
            <label><?php echo e($isEn ? 'SAS profile' : 'بروفايل الساس'); ?>
                <select name="profile_id" style="width:100%;margin-top:4px" required>
                    <option value=""><?php echo e($isEn ? 'Select…' : 'اختر…'); ?></option>
                    <?php foreach ($profiles as $pr): ?>
                        <option value="<?php echo (int) $pr['id']; ?>"<?php echo ((int) $pr['id'] === $profileId) ? ' selected' : ''; ?>>
                            <?php echo e($pr['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="actions" style="margin-top:12px">
                <button class="btn" type="submit"><?php echo e($isEn ? 'Save on SAS' : 'حفظ بالساس'); ?></button>
            </div>
        </form>
    </div>

    <div class="panel glass-panel span-2" id="activate">
        <h2><?php echo e($isEn ? 'Activate on SAS' : 'تفعيل بالساس'); ?></h2>
        <form method="post" id="sasUserActForm">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="sas_activate_card" id="sasUserActAction">
            <input type="hidden" name="card_id" id="sasUserCardId" value="0">
            <label><?php echo e($isEn ? 'Package' : 'نوع الاشتراك'); ?>
                <select name="profile_id" id="sasUserActProfile" style="width:100%;margin-top:4px">
                    <?php foreach ($profiles as $pr): ?>
                        <option value="<?php echo (int) $pr['id']; ?>"<?php echo ((int) $pr['id'] === $profileId) ? ' selected' : ''; ?>>
                            <?php echo e($pr['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="sas-mode-row">
                <label><input type="radio" name="sas_act_mode" value="card" checked> <?php echo e($isEn ? 'Unused cards (default)' : 'كروت غير مفعّلة (افتراضي)'); ?></label>
                <label><input type="radio" name="sas_act_mode" value="credit"> <?php echo e($isEn ? 'Manager credit' : 'رصيد المدير'); ?></label>
            </div>
            <div id="sasUserCardBox">
                <p class="meta"><?php echo e($isEn ? 'Unused cards for this profile' : 'الكروت غير المفعّلة حسب البروفايل'); ?></p>
                <ul class="sas-card-list" id="sasUserCards">
                    <?php if (!$cards): ?>
                        <li class="meta"><?php echo e($isEn ? 'No unused cards' : 'ماكو كروت غير مفعّلة'); ?></li>
                    <?php endif; ?>
                    <?php foreach ($cards as $i => $c): ?>
                        <li>
                            <label>
                                <input type="radio" name="pin" value="<?php echo e($c['pin']); ?>" data-card-id="<?php echo (int) $c['id']; ?>"<?php echo $i === 0 ? ' checked' : ''; ?>>
                                <span><?php echo e($c['label']); ?></span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div id="sasUserCreditBox" class="hidden">
                <label><?php echo e($isEn ? 'Units' : 'عدد الوحدات'); ?>
                    <input type="number" name="units" min="1" value="1" style="width:100%;margin-top:4px">
                </label>
            </div>
            <div class="actions" style="margin-top:12px">
                <button class="btn" type="submit"><?php echo e($isEn ? 'Activate' : 'تفعيل'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
  var form = document.getElementById('sasUserActForm');
  var action = document.getElementById('sasUserActAction');
  var boxC = document.getElementById('sasUserCardBox');
  var boxR = document.getElementById('sasUserCreditBox');
  var cardId = document.getElementById('sasUserCardId');
  function mode() {
    var on = document.querySelector('input[name="sas_act_mode"]:checked');
    return on ? on.value : 'card';
  }
  function syncMode() {
    var card = mode() === 'card';
    if (boxC) boxC.classList.toggle('hidden', !card);
    if (boxR) boxR.classList.toggle('hidden', card);
    if (action) action.value = card ? 'sas_activate_card' : 'sas_activate_credit';
  }
  document.querySelectorAll('input[name="sas_act_mode"]').forEach(function (r) {
    r.addEventListener('change', syncMode);
  });
  if (form) {
    form.addEventListener('submit', function () {
      var picked = document.querySelector('input[name="pin"]:checked');
      if (cardId) cardId.value = picked ? (picked.getAttribute('data-card-id') || '0') : '0';
    });
  }
  var prof = document.getElementById('sasUserActProfile');
  var list = document.getElementById('sasUserCards');
  if (prof && list) {
    prof.addEventListener('change', function () {
      fetch('sas.php?ajax=cards&username=<?php echo rawurlencode($username); ?>&profile_id=' + encodeURIComponent(prof.value), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var cards = (d && d.cards) ? d.cards : [];
          if (!cards.length) {
            list.innerHTML = '<li class="meta"><?php echo e($isEn ? 'No unused cards' : 'ماكو كروت غير مفعّلة'); ?></li>';
            return;
          }
          list.innerHTML = cards.map(function (c, i) {
            return '<li><label><input type="radio" name="pin" value="' + String(c.pin).replace(/"/g, '') + '" data-card-id="' + (c.id || 0) + '"' + (i === 0 ? ' checked' : '') + '> <span>' + (c.label || c.pin) + '</span></label></li>';
          }).join('');
        });
    });
  }
  <?php if ($focus === 'activate' || $focus === 'profile'): ?>
  var el = document.getElementById(<?php echo json_encode($focus); ?>);
  if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  <?php endif; ?>
})();
</script>
<?php
render_footer();
