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
if (function_exists('saas_mark_expired_tenants')) {
    saas_mark_expired_tenants($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('saas_agents.php');
    }
    $action = post('action');
    $tid = (int) post('tenant_id', '0');
    if ($action === 'approve') {
        list($ok, $msg) = saas_approve_tenant($pdo, $tid, $settings);
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'reject') {
        flash(saas_reject_tenant($pdo, $tid) ? 'success' : 'error', $isEn ? 'Suspended' : 'تم التعليق');
    } elseif ($action === 'extend') {
        $days = max(1, (int) post('days', '30'));
        flash(saas_extend_subscription($pdo, $tid, $days, post('plan_code', 'manual'))
            ? 'success' : 'error', $isEn ? 'Extended' : 'تم التمديد');
    }
    redirect('saas_agents.php');
}

$rows = array();
try {
    $rows = $pdo->query(
        'SELECT t.*, u.username AS owner_username, u.display_name AS owner_name, u.is_active AS owner_active
         FROM tenants t
         LEFT JOIN admin_users u ON u.id = t.owner_user_id
         WHERE t.id > 1
         ORDER BY FIELD(t.status, "pending", "active", "expired", "suspended"), t.id DESC'
    )->fetchAll();
} catch (Exception $e) {
    $rows = tenants_list($pdo);
    $rows = array_values(array_filter($rows, function ($r) {
        return (int) $r['id'] > 1;
    }));
}

$plat = function_exists('platform_card_summary') ? platform_card_summary($pdo) : array();
$hasWifi = false;
try {
    $hasWifi = (bool) $pdo->query(
        "SELECT id FROM admin_users WHERE username IN ('wifi@office','wifi.office','wifioffice') LIMIT 1"
    )->fetchColumn();
} catch (Exception $e) {
}

render_header($isEn ? 'System users' : 'مستخدمي النظام', 'saas_agents');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Platform overview' : 'ملخص المنصة'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'System login is separate from SAS. Your office subscribers should live under wifi@office — not company #1.'
        : 'دخول النظام غير دخول الساس. مشتركو مكتبك المفروض تحت wifi@office — مو الشركة 1.'); ?></p>
    <div class="form-grid cols-2" style="margin:12px 0;gap:10px">
        <div class="panel" style="padding:10px;margin:0">
            <div class="meta"><?php echo e($isEn ? 'Agencies' : 'الوكالات'); ?></div>
            <strong style="font-size:20px"><?php echo (int) (isset($plat['agencies']) ? $plat['agencies'] : 0); ?></strong>
            <span class="meta"> (<?php echo e($isEn ? 'active' : 'نشط'); ?>: <?php echo (int) (isset($plat['agencies_active']) ? $plat['agencies_active'] : 0); ?>)</span>
        </div>
        <div class="panel" style="padding:10px;margin:0">
            <div class="meta"><?php echo e($isEn ? 'Stock available' : 'كروت متوفرة'); ?></div>
            <strong style="font-size:20px"><?php echo (int) (isset($plat['available_cards']) ? $plat['available_cards'] : 0); ?></strong>
        </div>
        <div class="panel" style="padding:10px;margin:0">
            <div class="meta"><?php echo e($isEn ? 'Transferred to agents' : 'محوّل للوكلاء'); ?></div>
            <strong style="font-size:20px"><?php echo (int) (isset($plat['transfer_qty']) ? $plat['transfer_qty'] : 0); ?></strong>
        </div>
        <div class="panel" style="padding:10px;margin:0">
            <div class="meta"><?php echo e($isEn ? 'Capital (wholesale)' : 'رأس المال'); ?></div>
            <strong style="font-size:20px"><?php echo e(number_format((float) (isset($plat['wholesale_amount']) ? $plat['wholesale_amount'] : 0), 0)); ?></strong>
        </div>
        <div class="panel" style="padding:10px;margin:0">
            <div class="meta"><?php echo e($isEn ? 'Sold (agent price)' : 'المباع'); ?></div>
            <strong style="font-size:20px"><?php echo e(number_format((float) (isset($plat['sold_amount']) ? $plat['sold_amount'] : 0), 0)); ?></strong>
        </div>
        <div class="panel" style="padding:10px;margin:0">
            <div class="meta"><?php echo e($isEn ? 'Received' : 'المستلم'); ?></div>
            <strong style="font-size:20px"><?php echo e(number_format((float) (isset($plat['received']) ? $plat['received'] : 0), 0)); ?></strong>
        </div>
        <div class="panel" style="padding:10px;margin:0">
            <div class="meta"><?php echo e($isEn ? 'Debt remaining' : 'الدين المتبقي'); ?></div>
            <strong style="font-size:20px"><?php echo e(number_format((float) (isset($plat['remaining']) ? $plat['remaining'] : 0), 0)); ?></strong>
        </div>
        <div class="panel" style="padding:10px;margin:0">
            <div class="meta"><?php echo e($isEn ? 'Profit' : 'الربح'); ?></div>
            <strong style="font-size:20px"><?php echo e(number_format((float) (isset($plat['profit']) ? $plat['profit'] : 0), 0)); ?></strong>
        </div>
    </div>
    <div class="actions" style="margin-bottom:16px;flex-wrap:wrap;gap:8px">
        <?php if (!$hasWifi): ?>
            <a class="btn" href="migrate_owner_agency.php"><?php echo e($isEn ? 'Create wifi@office & move my data' : 'إنشاء wifi@office ونقل بياناتي'); ?></a>
        <?php else: ?>
            <span class="meta"><?php echo e($isEn ? 'wifi@office exists — manage it like any agency below.' : 'wifi@office موجود — أدِره مثل باقي الوكالات تحت.'); ?></span>
        <?php endif; ?>
        <a class="btn ghost" href="settings.php?tab=saas"><?php echo e($isEn ? 'SaaS / ZainCash' : 'الاستضافة / ZainCash'); ?></a>
    </div>
</div>
<div class="panel">
    <h2><?php echo e($isEn ? 'System users (agencies)' : 'مستخدمي النظام (الوكالات)'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'Approve pending agents to start their trial. Each agency binds its own SAS (not system login).'
        : 'وافق على الطلبات لبدء التجريبي. كل وكالة تربط ساسها بنفسها (مو دخول النظام).'); ?></p>
    <div class="table-wrap">
        <table class="table-compact">
            <thead>
            <tr>
                <th>#</th>
                <th><?php echo e($isEn ? 'Agency' : 'الوكالة'); ?></th>
                <th><?php echo e($isEn ? 'Owner' : 'المالك'); ?></th>
                <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                <th><?php echo e($isEn ? 'Trial / Sub' : 'تجريبي / اشتراك'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="msg-empty"><?php echo e($isEn ? 'No registrations yet' : 'ماكو تسجيلات بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $tid = (int) $r['id'];
                $st = isset($r['status']) ? $r['status'] : 'active';
                ?>
                <tr>
                    <td><?php echo $tid; ?></td>
                    <td><?php echo e($r['name']); ?><?php if (!empty($r['contact_phone'])): ?><br><small class="meta ltr"><?php echo e($r['contact_phone']); ?></small><?php endif; ?></td>
                    <td><?php echo e(isset($r['owner_name']) ? $r['owner_name'] : '—'); ?>
                        <?php if (!empty($r['owner_username'])): ?><br><small class="meta ltr"><?php echo e($r['owner_username']); ?></small><?php endif; ?>
                    </td>
                    <td><strong><?php echo e($st); ?></strong></td>
                    <td class="meta" style="font-size:12px">
                        <?php echo e($isEn ? 'Trial' : 'تجريبي'); ?>: <?php echo e(!empty($r['trial_ends_at']) ? $r['trial_ends_at'] : '—'); ?><br>
                        <?php echo e($isEn ? 'Until' : 'حتى'); ?>: <?php echo e(!empty($r['subscription_expires_at']) ? $r['subscription_expires_at'] : '—'); ?>
                    </td>
                    <td class="actions" style="gap:6px;flex-wrap:wrap">
                        <?php if ($st === 'pending'): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="tenant_id" value="<?php echo $tid; ?>">
                                <button class="btn sm" type="submit"><?php echo e($isEn ? 'Approve' : 'موافقة'); ?></button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Reject/suspend?' : 'رفض/تعليق؟'); ?>);">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="tenant_id" value="<?php echo $tid; ?>">
                                <button class="btn ghost sm" type="submit"><?php echo e($isEn ? 'Reject' : 'رفض'); ?></button>
                            </form>
                        <?php else: ?>
                            <form method="post" class="inline-form" style="display:flex;gap:4px;align-items:center">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="extend">
                                <input type="hidden" name="tenant_id" value="<?php echo $tid; ?>">
                                <input type="number" name="days" value="30" min="1" style="width:70px">
                                <button class="btn sm" type="submit"><?php echo e($isEn ? 'Extend' : 'تمديد'); ?></button>
                            </form>
                            <?php if ($st !== 'suspended'): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="tenant_id" value="<?php echo $tid; ?>">
                                <button class="btn ghost sm" type="submit"><?php echo e($isEn ? 'Suspend' : 'تعليق'); ?></button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="meta"><a href="settings.php?tab=saas"><?php echo e($isEn ? 'SaaS / ZainCash settings' : 'إعدادات الاستضافة / ZainCash'); ?></a>
        · <a href="migrate_owner_agency.php"><?php echo e($isEn ? 'Office migration' : 'ترحيل المكتب'); ?></a></p>
</div>
<?php render_footer(); ?>
