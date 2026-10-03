<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$isEn = ($lang === 'en');
$tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$saas = saas_settings($settings);

if ($tid <= 1 && function_exists('is_super_admin_user') && is_super_admin_user()) {
    flash('error', $isEn ? 'Your account (company #1) has no SaaS billing.' : 'حسابك (الشركة 1) بدون فوترة SaaS.');
    redirect('index.php');
}

$row = tenant_row($pdo, $tid);
list($okSub, $code, $msgSub) = tenant_subscription_status($pdo, $tid);
$noMembership = !empty($_SESSION['admin_sas_shadow'])
    || (function_exists('is_agent_user') && is_agent_user())
    || (function_exists('is_group_manager_user') && is_group_manager_user());
$who = function_exists('current_admin') ? current_admin() : null;
$whoName = '';
if ($who) {
    $whoName = trim((string) (isset($who['display_name']) ? $who['display_name'] : ''));
    if ($whoName === '') {
        $whoName = trim((string) (isset($who['username']) ? $who['username'] : ''));
    }
}

if ($noMembership && $_SERVER['REQUEST_METHOD'] === 'POST') {
    flash('error', $isEn ? 'This account has no membership' : 'هذا الحساب بدون عضوية');
    redirect('billing.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('billing.php');
    }
    $plan = post('plan_code', 'monthly');
    list($ok, $urlOrErr) = saas_start_plan_payment($pdo, $tid, $plan, $settings, $lang);
    if ($ok) {
        header('Location: ' . $urlOrErr);
        exit;
    }
    flash('error', $urlOrErr);
    redirect('billing.php');
}

render_header($isEn ? 'Billing' : 'الاشتراك والفوترة', 'billing');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Subscription' : 'الاشتراك'); ?></h2>
    <?php if ($noMembership): ?>
        <p><strong><?php echo e($whoName !== '' ? $whoName : ($isEn ? 'This account' : 'هذا الحساب')); ?></strong>
            — <?php echo e($isEn ? 'Status' : 'الحالة'); ?>: <strong><?php echo e($isEn ? 'Expired' : 'منتهي الصلاحية'); ?></strong></p>
        <p class="meta">
            <?php echo e($isEn ? 'Trial ends' : 'انتهاء التجريبي'); ?>: —<br>
            <?php echo e($isEn ? 'Subscription until' : 'الاشتراك حتى'); ?>: —
        </p>
        <div class="alert alert-error"><?php echo e($isEn
            ? 'This account has no membership, so the subscription is expired.'
            : 'هذا الحساب بدون عضوية، والاشتراك منتهي الصلاحية.'); ?></div>
    <?php elseif ($row): ?>
        <p><strong><?php echo e($row['name']); ?></strong>
            — <?php echo e($isEn ? 'Status' : 'الحالة'); ?>: <strong><?php echo e(isset($row['status']) ? $row['status'] : ''); ?></strong></p>
        <p class="meta">
            <?php echo e($isEn ? 'Trial ends' : 'انتهاء التجريبي'); ?>:
            <?php echo e(!empty($row['trial_ends_at']) ? $row['trial_ends_at'] : '—'); ?><br>
            <?php echo e($isEn ? 'Subscription until' : 'الاشتراك حتى'); ?>:
            <?php echo e(!empty($row['subscription_expires_at']) ? $row['subscription_expires_at'] : '—'); ?>
        </p>
        <?php if (!$okSub): ?>
            <div class="alert alert-error"><?php echo e($msgSub !== '' ? $msgSub : $code); ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$noMembership && !zaincash_is_configured($settings)): ?>
        <p class="meta"><?php echo e($isEn
            ? 'ZainCash is not configured yet — ask the system owner.'
            : 'ZainCash غير مضبوط بعد — تواصل مع صاحب النظام.'); ?></p>
    <?php elseif (!$noMembership): ?>
        <h3 style="font-size:15px"><?php echo e($isEn ? 'Pay with ZainCash' : 'ادفع عبر زين كاش'); ?></h3>
        <div class="form-grid cols-2">
            <?php foreach ($saas['plans'] as $code => $plan):
                if (!is_array($plan)) {
                    continue;
                }
                $label = isset($plan['label']) ? $plan['label'] : $code;
                $days = isset($plan['days']) ? (int) $plan['days'] : 30;
                $amount = isset($plan['amount']) ? (float) $plan['amount'] : 0;
                ?>
                <form method="post" class="panel" style="padding:12px">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="plan_code" value="<?php echo e($code); ?>">
                    <strong><?php echo e($label); ?></strong>
                    <p class="meta"><?php echo (int) $days; ?> <?php echo e($isEn ? 'days' : 'يوم'); ?> —
                        <?php echo e(function_exists('money_format_iqd') ? money_format_iqd($amount, $config['currency']) : ((int) $amount . ' IQD')); ?></p>
                    <button class="btn" type="submit"><?php echo e($isEn ? 'Pay' : 'ادفع'); ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
