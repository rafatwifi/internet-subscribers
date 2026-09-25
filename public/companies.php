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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('companies.php');
    }
    $action = post('action');
    if ($action === 'create' || $action === 'save') {
        $id = $action === 'save' ? (int) post('id', '0') : 0;
        list($ok, $msg, $newId) = platform_company_save(
            $pdo,
            $id,
            post('name', ''),
            post('sas_host', ''),
            post('is_active', '1') === '1' || $action === 'create'
        );
        flash($ok ? 'success' : 'error', $msg);
        redirect($ok && $newId > 0 ? ('companies.php?id=' . (int) $newId) : 'companies.php');
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

render_header($isEn ? 'Companies' : 'الشركات', 'companies');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Companies' : 'الشركات'); ?></h2>

    <div class="table-wrap" style="margin-bottom:16px">
        <table class="table-compact">
            <thead>
            <tr>
                <th>#</th>
                <th><?php echo e($isEn ? 'Company name' : 'اسم الشركة'); ?></th>
                <th><?php echo e($isEn ? 'Host' : 'الهوست'); ?></th>
                <th><?php echo e($isEn ? 'Ping' : 'البنك'); ?></th>
                <th><?php echo e($isEn ? 'Stability' : 'الاستقرار'); ?></th>
                <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$list): ?>
                <tr><td colspan="7" class="msg-empty"><?php echo e($isEn ? 'No companies yet' : 'ماكو شركات بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($list as $t): ?>
                <tr>
                    <td><?php echo (int) $t['id']; ?></td>
                    <td><strong><?php echo e($t['name']); ?></strong></td>
                    <td class="ltr"><?php echo e($t['sas_host']); ?></td>
                    <?php
                    $pingMs = null;
                    $pingOk = false;
                    $pingHost = preg_replace('#^https?://#i', '', rtrim(trim((string) $t['sas_host']), '/'));
                    if ($pingHost !== '' && function_exists('system_latency_to_host')) {
                        $pingRow = system_latency_to_host($pingHost);
                        if (is_array($pingRow)) {
                            $pingMs = isset($pingRow['ms']) ? $pingRow['ms'] : null;
                            $pingOk = !empty($pingRow['ok']) || ($pingMs !== null && $pingMs !== '');
                        }
                    }
                    $pingNum = ($pingMs === null || $pingMs === '') ? null : (float) $pingMs;
                    $pingTone = 'bad';
                    $stable = 0;
                    if ($pingOk && $pingNum !== null) {
                        if ($pingNum <= 100) {
                            $pingTone = 'ok';
                            $stable = 100;
                        } elseif ($pingNum <= 250) {
                            $pingTone = 'warn';
                            $stable = (int) max(40, round(10000 / $pingNum));
                        } else {
                            $pingTone = 'warn';
                            $stable = (int) max(10, round(10000 / $pingNum));
                        }
                    }
                    $pingText = $pingNum === null ? '—' : (number_format($pingNum, 0) . ' ms');
                    ?>
                    <td>
                        <span class="co-ping <?php echo e($pingTone); ?>" title="<?php echo e($isEn ? 'Standard: 100 ms = 100%' : 'المعيار: 100 ملي ثانية = 100٪'); ?>">
                            <span class="co-mark"><?php echo $pingTone === 'ok' ? '✓' : ($pingTone === 'warn' ? '!' : '×'); ?></span>
                            <span class="ltr"><?php echo e($pingText); ?></span>
                        </span>
                    </td>
                    <td><strong class="co-stable <?php echo e($pingTone); ?>"><?php echo (int) $stable; ?>%</strong></td>
                    <td><?php echo !empty($t['is_active']) ? e($isEn ? 'Active' : 'نشطة') : e($isEn ? 'Off' : 'موقوفة'); ?></td>
                    <td class="actions" style="gap:6px">
                        <a class="co-btn edit" href="companies.php?id=<?php echo (int) $t['id']; ?>"><?php echo e($isEn ? 'Edit' : 'تعديل'); ?></a>
                        <form method="post" class="inline-form" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Delete company?' : 'حذف الشركة؟'); ?>);">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>">
                            <button class="co-btn del" type="submit"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <button class="btn" type="button" id="coAddToggle"><?php echo e($edit ? ($isEn ? 'Edit company' : 'تعديل شركة') : ($isEn ? 'Add company' : 'إضافة شركة')); ?></button>
    <form method="post" id="coAddBox" class="panel" style="padding:14px;max-width:520px;margin-top:12px" <?php echo $edit ? '' : 'hidden'; ?>>
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo $edit ? 'save' : 'create'; ?>">
        <?php if ($edit): ?>
            <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">
        <?php endif; ?>
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($isEn ? 'Company name' : 'اسم الشركة'); ?></label>
                <input name="name" required value="<?php echo e($edit ? $edit['name'] : ''); ?>"
                       placeholder="<?php echo e($isEn ? 'e.g. Iraqi Net' : 'مثلاً: نت العراق'); ?>">
            </div>
            <div>
                <label><?php echo e($isEn ? 'SAS host' : 'هوست الساس'); ?></label>
                <input class="ltr" name="sas_host" required
                       value="<?php echo e($edit ? $edit['sas_host'] : ''); ?>"
                       placeholder="reseller.example.com">
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
.co-ping { display:inline-flex; align-items:center; gap:8px; font-weight:800; }
.co-mark { width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:13px; color:#fff; }
.co-ping.ok, .co-stable.ok { color:#15803d; }
.co-ping.ok .co-mark { background:#16a34a; }
.co-ping.warn, .co-stable.warn { color:#a16207; }
.co-ping.warn .co-mark { background:#eab308; }
.co-ping.bad, .co-stable.bad { color:#b91c1c; }
.co-ping.bad .co-mark { background:#dc2626; }
.co-btn { display:inline-flex; align-items:center; height:32px; padding:0 12px; border-radius:10px; font-weight:800; font-size:13px; text-decoration:none; border:0; cursor:pointer; }
.co-btn.edit { background:#dcfce7; color:#166534; }
.co-btn.del { background:#fee2e2; color:#991b1b; }
</style>
<script>
(function () {
  var btn = document.getElementById('coAddToggle');
  var box = document.getElementById('coAddBox');
  if (!btn || !box) return;
  btn.addEventListener('click', function () {
    box.hidden = !box.hidden;
    if (!box.hidden) {
      var first = box.querySelector('input[name="name"]');
      if (first) first.focus();
    }
  });
})();
</script>
<?php render_footer(); ?>
