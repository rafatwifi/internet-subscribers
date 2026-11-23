<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
    flash('error', ($lang === 'en') ? 'Super admin only' : 'للمدير العام فقط');
    redirect('index.php');
}

$isEn = ($lang === 'en');
if (function_exists('ensure_platform_companies_schema')) {
    ensure_platform_companies_schema($pdo);
}
if (function_exists('ensure_tenant_sas_accounts_schema')) {
    ensure_tenant_sas_accounts_schema($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('companies.php');
    }
    $action = post('action');
    if ($action === 'create' || $action === 'save') {
        $id = $action === 'save' ? (int) post('id', '0') : 0;
        $provider = post('provider_type', 'sas');
        list($ok, $msg, $newId) = platform_company_save(
            $pdo,
            $id,
            post('name', ''),
            post('sas_host', ''),
            post('is_active', '1') === '1' || $action === 'create',
            $provider,
            null,
            post('api_alt_host', '')
        );
        $cid = $ok ? ($id > 0 ? $id : (int) $newId) : 0;
        if ($ok && $cid > 0 && !empty($_FILES['logo_file']['tmp_name']) && function_exists('platform_company_store_logo_upload')) {
            $fail = '';
            $saved = platform_company_store_logo_upload($_FILES['logo_file'], $cid, $fail);
            if ($saved) {
                platform_company_save(
                    $pdo,
                    $cid,
                    post('name', ''),
                    post('sas_host', ''),
                    post('is_active', '1') === '1' || $action === 'create',
                    $provider,
                    $saved,
                    post('api_alt_host', '')
                );
            } else {
                $msg .= $isEn ? ' (logo not saved)' : ' (اللوكو ما انحفظ)';
            }
        }
        flash($ok ? 'success' : 'error', $msg);
        if ($ok && $cid > 0) {
            $kind = post('service_kind') === 'ftth' ? 'ftth' : 'wireless';
            try {
                $pdo->prepare('UPDATE platform_companies SET service_kind = :k WHERE id = :id')
                    ->execute(array(':k' => $kind, ':id' => $cid));
            } catch (Exception $eKind) {
            }
        }
        redirect($ok && $cid > 0 ? ('companies.php?id=' . (int) $cid) : 'companies.php');
    }
    if ($action === 'delete') {
        $id = (int) post('id', '0');
        flash(platform_company_delete($pdo, $id) ? 'success' : 'error',
            $isEn ? 'Deleted' : 'تم الحذف');
        redirect('companies.php');
    }
}

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$list = platform_companies_list($pdo, false);
$edit = $editId > 0 ? platform_company_row($pdo, $editId) : null;
$editProvider = $edit && !empty($edit['provider_type'])
    ? platform_company_normalize_provider($edit['provider_type'])
    : 'sas';
$editKind = ($edit && isset($edit['service_kind']) && $edit['service_kind'] === 'ftth') ? 'ftth' : 'wireless';
$actNotes = array();
try {
    $noteRows = $pdo->query(
        "SELECT company_id, label, sas_username, act_note
         FROM tenant_sas_accounts
         WHERE act_via = 'user'"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($noteRows)) {
        foreach ($noteRows as $nr) {
            $ncid = (int) $nr['company_id'];
            if (!isset($actNotes[$ncid])) {
                $actNotes[$ncid] = array();
            }
            $actNotes[$ncid][] = $nr;
        }
    }
} catch (Exception $eNotes) {
}

$pingById = array();
if (function_exists('dash_platform_company_pings')) {
    $pingPack = dash_platform_company_pings(false);
    if (is_array($pingPack) && !empty($pingPack['rows']) && is_array($pingPack['rows'])) {
        foreach ($pingPack['rows'] as $pr) {
            if (is_array($pr) && isset($pr['id'])) {
                $pingById[(int) $pr['id']] = $pr;
            }
        }
    }
}

render_header($isEn ? 'Companies' : 'الشركات', 'companies');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Companies' : 'الشركات'); ?></h2>

    <div class="table-wrap" style="margin-bottom:16px">
        <table class="table-compact">
            <thead>
            <tr>
                <th>#</th>
                <th><?php echo e($isEn ? 'Logo' : 'اللوكو'); ?></th>
                <th><?php echo e($isEn ? 'Company name' : 'اسم الشركة'); ?></th>
                <th><?php echo e($isEn ? 'Type' : 'النوع'); ?></th>
                <th><?php echo e($isEn ? 'Host' : 'الهوست'); ?></th>
                <th><?php echo e($isEn ? 'Ping' : 'البنك'); ?></th>
                <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$list): ?>
                <tr><td colspan="8" class="msg-empty"><?php echo e($isEn ? 'No companies yet' : 'ماكو شركات بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($list as $t):
                $prov = platform_company_normalize_provider(isset($t['provider_type']) ? $t['provider_type'] : 'sas');
                $logoUrl = function_exists('platform_company_logo_url') ? platform_company_logo_url($t) : '';
                $cid = (int) $t['id'];
                $pingMs = null;
                if (isset($pingById[$cid]['ms']) && $pingById[$cid]['ms'] !== null && $pingById[$cid]['ms'] !== '') {
                    $pingMs = (float) $pingById[$cid]['ms'];
                }
                $pingTone = 'bad';
                if ($pingMs !== null) {
                    $pingTone = ($pingMs <= 100) ? 'ok' : 'warn';
                }
                $pingText = $pingMs === null ? '—' : (number_format($pingMs, 0) . ' ms');
                ?>
                <tr>
                    <td><?php echo $cid; ?></td>
                    <td>
                        <?php if ($logoUrl !== ''): ?>
                            <img class="co-logo" src="<?php echo e($logoUrl); ?>" alt="">
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo e($t['name']); ?></strong>
                        <div style="color:#64748b;font-size:12px;margin-top:2px"><?php echo (isset($t['service_kind']) && $t['service_kind'] === 'ftth') ? 'FTTH' : 'Wireless'; ?></div>
                        <?php if (!empty($actNotes[$cid])): ?>
                            <div style="color:#9a3412;font-size:12px;font-weight:700;margin-top:4px">
                                <?php echo e($isEn
                                    ? 'Activation was found disabled by reseller management; a second activation method will be used.'
                                    : 'تم الكشف عن وجود تعطيل في خاصية التفعيل من قبل ادارة الرسلر , سيتم اعتماد طريقة تفعيل ثانية'); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($prov === 'earthlink' ? 'Earthlink' : 'SAS'); ?></td>
                    <td class="ltr"><?php echo e($t['sas_host']); ?></td>
                    <td>
                        <span class="co-ping <?php echo e($pingTone); ?>" data-co-ping="<?php echo $cid; ?>">
                            <span class="ltr"><?php echo e($pingText); ?></span>
                        </span>
                    </td>
                    <td><?php echo !empty($t['is_active']) ? e($isEn ? 'Active' : 'نشطة') : e($isEn ? 'Off' : 'موقوفة'); ?></td>
                    <td class="actions" style="gap:6px">
                        <a class="co-btn edit" href="companies.php?id=<?php echo $cid; ?>"><?php echo e($isEn ? 'Edit' : 'تعديل'); ?></a>
                        <form method="post" class="inline-form" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Delete company?' : 'حذف الشركة؟'); ?>);">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $cid; ?>">
                            <button class="co-btn del" type="submit"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <button class="btn" type="button" id="coAddToggle"><?php echo e($edit ? ($isEn ? 'Edit company' : 'تعديل شركة') : ($isEn ? 'Add company' : 'إضافة شركة')); ?></button>
    <form method="post" id="coAddBox" class="panel" style="padding:14px;max-width:560px;margin-top:12px" enctype="multipart/form-data" <?php echo $edit ? '' : 'hidden'; ?>>
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo $edit ? 'save' : 'create'; ?>">
        <?php if ($edit): ?>
            <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">
        <?php endif; ?>
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($isEn ? 'Company name' : 'اسم الشركة'); ?></label>
                <input name="name" required value="<?php echo e($edit ? $edit['name'] : ''); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Type' : 'النوع'); ?></label>
                <select name="provider_type">
                    <option value="sas" <?php echo $editProvider === 'sas' ? 'selected' : ''; ?>>SAS</option>
                    <option value="earthlink" <?php echo $editProvider === 'earthlink' ? 'selected' : ''; ?>>Earthlink</option>
                </select>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Service' : 'خدماته'); ?></label>
                <select name="service_kind">
                    <option value="wireless" <?php echo $editKind !== 'ftth' ? 'selected' : ''; ?>>Wireless</option>
                    <option value="ftth" <?php echo $editKind === 'ftth' ? 'selected' : ''; ?>>FTTH</option>
                </select>
            </div>
            <div style="grid-column:1/-1">
                <label><?php echo e($isEn ? 'Primary API' : 'API الأساسي'); ?></label>
                <input class="ltr" name="sas_host" required
                       value="<?php echo e($edit ? $edit['sas_host'] : ''); ?>"
                       placeholder="net.reseller.com">
            </div>
            <div style="grid-column:1/-1" id="coAltHostWrap">
                <label><?php echo e($isEn ? 'Backup API (optional)' : 'API الاحتياطي (اختياري)'); ?></label>
                <input class="ltr" name="api_alt_host"
                       value="<?php echo e($edit && !empty($edit['api_alt_host']) ? $edit['api_alt_host'] : ''); ?>"
                       placeholder="net.reseller.com">
            </div>
            <div style="grid-column:1/-1">
                <label><?php echo e($isEn ? 'Company logo (optional)' : 'لوكو الشركة (اختياري)'); ?></label>
                <input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/webp">
                <?php
                $editLogo = $edit && function_exists('platform_company_logo_url') ? platform_company_logo_url($edit) : '';
                if ($editLogo !== ''): ?>
                    <div style="margin-top:8px"><img class="co-logo co-logo-lg" src="<?php echo e($editLogo); ?>" alt=""></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($edit): ?>
        <label class="toggle" style="display:flex;gap:8px;align-items:center;margin:10px 0">
            <input type="checkbox" name="is_active" value="1"<?php echo !empty($edit['is_active']) ? ' checked' : ''; ?>>
            <span><?php echo e($isEn ? 'Active' : 'نشطة'); ?></span>
        </label>
        <?php endif; ?>
        <div class="actions" style="margin-top:12px">
            <button class="btn" type="submit"><?php echo e($edit ? t('save') : ($isEn ? 'Add' : 'إضافة')); ?></button>
            <?php if ($edit): ?>
                <a class="btn ghost" href="companies.php"><?php echo e($isEn ? 'Cancel' : 'إلغاء'); ?></a>
            <?php endif; ?>
        </div>
    </form>
</div>
<style>
.co-logo { width:28px; height:28px; object-fit:contain; vertical-align:middle; background:transparent; }
.co-logo-lg { width:56px; height:56px; }
.co-ping { display:inline-flex; align-items:center; gap:8px; font-weight:800; }
.co-ping.ok { color:#15803d; }
.co-ping.warn { color:#a16207; }
.co-ping.bad { color:#b91c1c; }
.co-btn { display:inline-flex; align-items:center; height:32px; padding:0 12px; border-radius:10px; font-weight:800; font-size:13px; text-decoration:none; border:0; cursor:pointer; }
.co-btn.edit { background:#dcfce7; color:#166534; }
.co-btn.del { background:#fee2e2; color:#991b1b; }
</style>
<script>
(function () {
  var btn = document.getElementById('coAddToggle');
  var box = document.getElementById('coAddBox');
  if (btn && box) {
    btn.addEventListener('click', function () {
      box.hidden = !box.hidden;
      if (!box.hidden) {
        var first = box.querySelector('input[name="name"]');
        if (first) first.focus();
      }
    });
  }
  function paintPing(el, ms) {
    if (!el) return;
    el.classList.remove('ok', 'warn', 'bad');
    var span = el.querySelector('.ltr') || el;
    if (ms === null || ms === undefined || ms === '') {
      el.classList.add('bad');
      span.textContent = '—';
      return;
    }
    var n = Math.round(Number(ms));
    el.classList.add(n <= 100 ? 'ok' : 'warn');
    span.textContent = n + ' ms';
  }
  fetch('index.php?ajax=dash_company_pings', { credentials: 'same-origin', cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data || !data.rows) return;
      data.rows.forEach(function (row) {
        if (!row || !row.id) return;
        paintPing(document.querySelector('[data-co-ping="' + row.id + '"]'), row.ms);
      });
    })
    .catch(function () {});
})();
</script>
<?php render_footer(); ?>
