<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
    flash('error', ($lang === 'en') ? 'Super admin only' : 'للمدير العام فقط');
    redirect('index.php');
}

$isEn = ($lang === 'en');
ensure_tenants_schema($pdo, $config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('companies.php');
    }
    $action = post('action');
    if ($action === 'create') {
        $name = trim((string) post('name', ''));
        $fields = array(
            'sas_enabled' => post('sas_enabled') === '1',
            'sas_host' => trim((string) post('sas_host', '')),
            'sas_username' => trim((string) post('sas_username', '')),
            'sas_password' => (string) post('sas_password', ''),
            'sas_parent_id' => (int) post('sas_parent_id', '1'),
        );
        list($id, $err) = tenant_create($pdo, $name, $fields);
        if ($id > 0) {
            flash('success', $isEn ? ('Company created #' . $id) : ('تم إنشاء الشركة #' . $id));
            redirect('companies.php?id=' . $id);
        }
        flash('error', $err !== '' ? $err : ($isEn ? 'Create failed' : 'فشل الإنشاء'));
        redirect('companies.php');
    }
    if ($action === 'save') {
        $id = (int) post('id', '0');
        $data = array(
            'name' => trim((string) post('name', '')),
            'is_active' => post('is_active') === '1' ? 1 : 0,
            'sas_enabled' => post('sas_enabled') === '1' ? 1 : 0,
            'sas_host' => preg_replace('#^https?://#i', '', rtrim(trim((string) post('sas_host', '')), '/')),
            'sas_username' => trim((string) post('sas_username', '')),
            'sas_parent_id' => (int) post('sas_parent_id', '1'),
            'sas_default_password' => (string) post('sas_default_password', ''),
            'sas_activate_units' => max(1, (int) post('sas_activate_units', '1')),
        );
        $pass = (string) post('sas_password', '');
        if ($pass !== '') {
            $data['sas_password'] = $pass;
        }
        if (tenant_save($pdo, $id, $data)) {
            flash('success', t('saved'));
        } else {
            flash('error', $isEn ? 'Save failed' : 'فشل الحفظ');
        }
        redirect('companies.php?id=' . $id);
    }
    if ($action === 'test') {
        $id = (int) post('id', '0');
        $row = tenant_row($pdo, $id);
        $host = $row ? $row['sas_host'] : '';
        $user = $row ? $row['sas_username'] : '';
        $pass = $row ? $row['sas_password'] : '';
        // اختبار بالقيم المرسلة إن وُجدت
        if (trim((string) post('sas_host', '')) !== '') {
            $host = trim((string) post('sas_host', ''));
            $user = trim((string) post('sas_username', ''));
            $pass = (string) post('sas_password', $pass);
        }
        list($ok, $msg) = tenant_test_sas_connection($host, $user, $pass);
        flash($ok ? 'success' : 'error', $msg);
        redirect('companies.php?id=' . max(1, $id));
    }
    if ($action === 'create_admin') {
        $tid = (int) post('id', '0');
        $u = trim((string) post('admin_username', ''));
        $d = trim((string) post('admin_display', ''));
        $p = (string) post('admin_password', '');
        if ($tid <= 0 || $u === '' || $p === '') {
            flash('error', $isEn ? 'Fill username and password' : 'أكمل اليوزر والباسورد');
            redirect('companies.php?id=' . $tid);
        }
        try {
            $hash = admin_password_hash($p);
            $pdo->prepare(
                'INSERT INTO admin_users (username, display_name, password_hash, role, is_active, tenant_id)
                 VALUES (:u, :d, :h, "admin", 1, :t)'
            )->execute(array(
                ':u' => $u,
                ':d' => $d !== '' ? $d : $u,
                ':h' => $hash,
                ':t' => $tid,
            ));
            flash('success', $isEn ? 'Company admin created' : 'تم إنشاء أدمن الشركة');
        } catch (Exception $e) {
            flash('error', $isEn ? 'Username may already exist' : 'اليوزر ربما موجود مسبقاً');
        }
        redirect('companies.php?id=' . $tid);
    }
}

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$list = tenants_list($pdo);
$edit = $editId > 0 ? tenant_row($pdo, $editId) : null;

render_header($isEn ? 'Companies' : 'الشركات', 'companies');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Companies (SAS accounts)' : 'الشركات (حسابات الساس)'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'Each company has its own SAS login. Current live data stays under company #1 — never wiped.'
        : 'كل شركة لها ساس خاص. بياناتك الحالية تبقى تحت الشركة رقم 1 — ما تنمسح.'); ?></p>

    <div class="form-grid cols-2" style="align-items:start">
        <div>
            <h3 style="font-size:15px"><?php echo e($isEn ? 'Companies' : 'قائمة الشركات'); ?></h3>
            <div class="table-wrap">
                <table class="table-compact">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th><?php echo e($isEn ? 'Name' : 'الاسم'); ?></th>
                        <th>SAS</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($list as $t): ?>
                        <tr>
                            <td><?php echo (int) $t['id']; ?></td>
                            <td><?php echo e($t['name']); ?></td>
                            <td><?php echo !empty($t['sas_enabled']) ? e($t['sas_username']) : '—'; ?></td>
                            <td><a href="companies.php?id=<?php echo (int) $t['id']; ?>"><?php echo e($isEn ? 'Edit' : 'تعديل'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" class="panel" style="margin-top:14px;padding:12px">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="create">
                <h3 style="margin:0 0 8px;font-size:15px"><?php echo e($isEn ? 'New company' : 'شركة جديدة'); ?></h3>
                <label><?php echo e($isEn ? 'Company name' : 'اسم الشركة'); ?>
                    <input name="name" required>
                </label>
                <label class="toggle" style="display:flex;gap:8px;align-items:center;margin:8px 0">
                    <input type="checkbox" name="sas_enabled" value="1" checked>
                    <span><?php echo e($isEn ? 'Enable SAS' : 'تفعيل الساس'); ?></span>
                </label>
                <label><?php echo e($isEn ? 'SAS host' : 'رابط/هوست الساس'); ?>
                    <input class="ltr" name="sas_host" placeholder="reseller.example.com">
                </label>
                <label><?php echo e($isEn ? 'SAS username' : 'يوزر الساس'); ?>
                    <input class="ltr" name="sas_username">
                </label>
                <label><?php echo e($isEn ? 'SAS password' : 'باسورد الساس'); ?>
                    <input class="ltr" type="password" name="sas_password">
                </label>
                <div class="actions" style="margin-top:10px">
                    <button class="btn" type="submit"><?php echo e($isEn ? 'Create' : 'إنشاء'); ?></button>
                </div>
            </form>
        </div>

        <div>
            <?php if ($edit): ?>
                <form method="post" class="panel" style="padding:12px">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">
                    <h3 style="margin:0 0 8px;font-size:15px"><?php echo e($isEn ? 'Edit company' : 'تعديل شركة'); ?> #<?php echo (int) $edit['id']; ?></h3>
                    <label><?php echo e($isEn ? 'Name' : 'الاسم'); ?>
                        <input name="name" value="<?php echo e($edit['name']); ?>" required>
                    </label>
                    <label class="toggle" style="display:flex;gap:8px;align-items:center;margin:8px 0">
                        <input type="checkbox" name="is_active" value="1"<?php echo !empty($edit['is_active']) ? ' checked' : ''; ?>>
                        <span><?php echo e($isEn ? 'Active' : 'نشطة'); ?></span>
                    </label>
                    <label class="toggle" style="display:flex;gap:8px;align-items:center;margin:8px 0">
                        <input type="checkbox" name="sas_enabled" value="1"<?php echo !empty($edit['sas_enabled']) ? ' checked' : ''; ?>>
                        <span><?php echo e($isEn ? 'SAS enabled' : 'الساس مفعّل'); ?></span>
                    </label>
                    <label><?php echo e($isEn ? 'SAS host' : 'هوست الساس'); ?>
                        <input class="ltr" name="sas_host" value="<?php echo e($edit['sas_host']); ?>">
                    </label>
                    <label><?php echo e($isEn ? 'SAS username' : 'يوزر الساس'); ?>
                        <input class="ltr" name="sas_username" value="<?php echo e($edit['sas_username']); ?>">
                    </label>
                    <label><?php echo e($isEn ? 'SAS password (leave blank to keep)' : 'باسورد الساس (فارغ = بدون تغيير)'); ?>
                        <input class="ltr" type="password" name="sas_password" value="" autocomplete="new-password">
                    </label>
                    <label><?php echo e($isEn ? 'Default parent id' : 'parent_id الافتراضي'); ?>
                        <input type="number" name="sas_parent_id" value="<?php echo (int) $edit['sas_parent_id']; ?>">
                    </label>
                    <div class="actions" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px">
                        <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
                        <button class="btn ghost" type="submit" name="action" value="test"><?php echo e($isEn ? 'Test SAS' : 'اختبار الساس'); ?></button>
                    </div>
                </form>

                <form method="post" class="panel" style="margin-top:12px;padding:12px">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="create_admin">
                    <input type="hidden" name="id" value="<?php echo (int) $edit['id']; ?>">
                    <h3 style="margin:0 0 8px;font-size:15px"><?php echo e($isEn ? 'Create company admin login' : 'إنشاء دخول أدمن للشركة'); ?></h3>
                    <label><?php echo e($isEn ? 'Username' : 'اليوزر'); ?>
                        <input class="ltr" name="admin_username" required>
                    </label>
                    <label><?php echo e($isEn ? 'Display name' : 'الاسم الظاهر'); ?>
                        <input name="admin_display">
                    </label>
                    <label><?php echo e($isEn ? 'Password' : 'الباسورد'); ?>
                        <input class="ltr" type="password" name="admin_password" required>
                    </label>
                    <div class="actions" style="margin-top:10px">
                        <button class="btn secondary" type="submit"><?php echo e($isEn ? 'Create admin' : 'إنشاء أدمن'); ?></button>
                    </div>
                </form>
            <?php else: ?>
                <p class="meta"><?php echo e($isEn ? 'Select a company to edit.' : 'اختر شركة للتعديل.'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php render_footer(); ?>
