<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
    flash('error', ($lang === 'en') ? 'Super admin only' : 'للمدير العام فقط');
    redirect('index.php');
}

$migrateFile = __DIR__ . '/../includes/tenant_migrate.php';
if (is_file($migrateFile)) {
    require_once $migrateFile;
}

$isEn = ($lang === 'en');
ensure_tenants_schema($pdo, $config);
$counts = function_exists('tenant_migrate_count_owner_data')
    ? tenant_migrate_count_owner_data($pdo)
    : array();
$me = current_admin();
$meId = $me ? (int) $me['id'] : 0;

// هل تم الترحيل مسبقاً؟
$already = false;
try {
    $already = (bool) $pdo->query(
        "SELECT id FROM admin_users WHERE username IN ('wifi@office','wifi.office','wifioffice') LIMIT 1"
    )->fetchColumn();
} catch (Exception $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('migrate_owner_agency.php');
    }
    if ($already) {
        flash('error', $isEn ? 'Migration already done' : 'الترحيل منفّذ مسبقاً');
        redirect('migrate_owner_agency.php');
    }
    if (post('confirm') !== 'MIGRATE') {
        flash('error', $isEn ? 'Type MIGRATE to confirm' : 'اكتب MIGRATE للتأكيد');
        redirect('migrate_owner_agency.php');
    }
    $username = trim((string) post('username', 'wifi@office'));
    $display = trim((string) post('display_name', 'WiFi Office'));
    $agency = trim((string) post('agency_name', 'WiFi Office'));
    $pass = (string) post('password', '');
    list($ok, $msg, $tid, $uid) = tenant_migrate_owner_to_agency(
        $pdo,
        $config,
        $username,
        $display,
        $pass,
        $agency,
        array($meId)
    );
    flash($ok ? 'success' : 'error', $msg . ($ok ? (' — tenant #' . (int) $tid . ' / user #' . (int) $uid) : ''));
    redirect($ok ? 'saas_agents.php' : 'migrate_owner_agency.php');
}

render_header($isEn ? 'Move office to agency' : 'ترحيل مكتبك لوكالة', 'saas_agents');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Split platform vs your office' : 'فصل المنصة عن مكتبك التشغيلي'); ?></h2>
    <p class="meta">
        <?php echo e($isEn
            ? 'Creates agency wifi@office, moves your current subscribers/debts/SAS cache/agents there. You stay super-admin on company #1 to manage all agencies. System login ≠ SAS login.'
            : 'ينشئ وكالة wifi@office وينقل مشتركيك وديونك وكاش الساس والوكلاء إليها. تبقى سوبر أدمن على الشركة 1 لإدارة كل الوكالات. دخول النظام ≠ دخول الساس.'); ?>
    </p>

    <?php if ($already): ?>
        <div class="alert alert-info"><?php echo e($isEn ? 'Looks like wifi@office already exists.' : 'يبدو أن wifi@office موجود مسبقاً.'); ?></div>
    <?php endif; ?>

    <h3 style="font-size:15px"><?php echo e($isEn ? 'What will move from company #1' : 'ما راح ينتقل من الشركة 1'); ?></h3>
    <ul class="meta">
        <li><?php echo e($isEn ? 'Subscribers' : 'المشتركين'); ?>: <strong><?php echo (int) (isset($counts['subscribers']) ? $counts['subscribers'] : 0); ?></strong></li>
        <li><?php echo e($isEn ? 'SAS cache users' : 'كاش الساس'); ?>: <strong><?php echo (int) (isset($counts['sas_cache']) ? $counts['sas_cache'] : 0); ?></strong></li>
        <li><?php echo e($isEn ? 'Unpaid invoices (stay linked)' : 'فواتير غير مسددة (تبقى مربوطة)'); ?>: <strong><?php echo (int) (isset($counts['invoices_unpaid']) ? $counts['invoices_unpaid'] : 0); ?></strong></li>
        <li><?php echo e($isEn ? 'Agents' : 'وكلاء'); ?>: <strong><?php echo (int) (isset($counts['agents']) ? $counts['agents'] : 0); ?></strong></li>
        <li><?php echo e($isEn ? 'Staff / accountants' : 'موظفين / محاسبين'); ?>: <strong><?php echo (int) (isset($counts['staff']) ? $counts['staff'] : 0); ?></strong></li>
        <li><?php echo e($isEn ? 'Card stock rows' : 'صفوف مخزون كروت'); ?>: <strong><?php echo (int) (isset($counts['card_stock']) ? $counts['card_stock'] : 0); ?></strong></li>
    </ul>

    <form method="post" class="panel" style="padding:14px;margin-top:12px" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Move all office data now? This cannot be undone easily.' : 'نقل كل بيانات المكتب الآن؟ ما ينرجع بسهولة.'); ?>);">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($isEn ? 'Agency name' : 'اسم الوكالة'); ?></label>
                <input name="agency_name" value="WiFi Office" required>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Login username' : 'يوزر الدخول للنظام'); ?></label>
                <input class="ltr" name="username" value="wifi@office" required>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Display name' : 'الاسم الظاهر'); ?></label>
                <input name="display_name" value="WiFi Office">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?></label>
                <input class="ltr" type="password" name="password" required minlength="4" autocomplete="new-password">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Type MIGRATE to confirm' : 'اكتب MIGRATE للتأكيد'); ?></label>
                <input class="ltr" name="confirm" placeholder="MIGRATE" required>
            </div>
        </div>
        <div class="actions" style="margin-top:12px">
            <button class="btn" type="submit" <?php echo $already ? 'disabled' : ''; ?>><?php echo e($isEn ? 'Create wifi@office & move data' : 'إنشاء wifi@office ونقل البيانات'); ?></button>
            <a class="btn ghost" href="saas_agents.php"><?php echo e($isEn ? 'Back' : 'رجوع'); ?></a>
        </div>
    </form>
</div>
<?php render_footer(); ?>
