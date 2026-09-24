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
    <h2><?php echo e($isEn ? 'SAS companies (name + host)' : 'الشركات (اسم + هوست)'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'Agencies only pick the company by name; host fills automatically when they add a reseller.'
        : 'الوكيل يختار الشركة بالاسم فقط؛ الهوست يجي تلقائي لما يضيف ريسيلر.'); ?></p>

    <div class="table-wrap" style="margin-bottom:16px">
        <table class="table-compact">
            <thead>
            <tr>
                <th>#</th>
                <th><?php echo e($isEn ? 'Company name' : 'اسم الشركة'); ?></th>
                <th><?php echo e($isEn ? 'Host' : 'الهوست'); ?></th>
                <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$list): ?>
                <tr><td colspan="5" class="msg-empty"><?php echo e($isEn ? 'No companies yet' : 'ماكو شركات بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($list as $t): ?>
                <tr>
                    <td><?php echo (int) $t['id']; ?></td>
                    <td><strong><?php echo e($t['name']); ?></strong></td>
                    <td class="ltr"><?php echo e($t['sas_host']); ?></td>
                    <td><?php echo !empty($t['is_active']) ? e($isEn ? 'Active' : 'نشطة') : e($isEn ? 'Off' : 'موقوفة'); ?></td>
                    <td class="actions" style="gap:6px">
                        <a class="btn ghost sm" href="companies.php?id=<?php echo (int) $t['id']; ?>"><?php echo e($isEn ? 'Edit' : 'تعديل'); ?></a>
                        <form method="post" class="inline-form" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Delete company?' : 'حذف الشركة؟'); ?>);">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>">
                            <button class="btn ghost sm" type="submit"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form method="post" class="panel" style="padding:14px;max-width:520px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo $edit ? 'save' : 'create'; ?>">
        <?php if ($edit): ?>
            <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">
        <?php endif; ?>
        <h3 style="margin:0 0 10px;font-size:15px"><?php echo e($edit
            ? ($isEn ? 'Edit company' : 'تعديل شركة')
            : ($isEn ? 'Add company' : 'إضافة شركة')); ?></h3>
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
<?php render_footer(); ?>
