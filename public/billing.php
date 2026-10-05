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

$billName = $whoName;
if (!$noMembership && $row && !empty($row['name'])) {
    $billName = (string) $row['name'];
}
if ($billName === '') {
    $billName = $isEn ? 'This account' : 'هذا الحساب';
}
$statusRaw = $noMembership ? 'expired' : (isset($row['status']) ? (string) $row['status'] : 'active');
$statusMap = array(
    'active' => $isEn ? 'Active' : 'فعّال',
    'expired' => $isEn ? 'Expired' : 'منتهي',
    'pending' => $isEn ? 'Pending' : 'بانتظار الموافقة',
    'suspended' => $isEn ? 'Suspended' : 'معلّق',
);
$statusLabel = isset($statusMap[$statusRaw]) ? $statusMap[$statusRaw] : $statusRaw;
$subTs = (!$noMembership && $row && !empty($row['subscription_expires_at'])) ? strtotime($row['subscription_expires_at']) : 0;
$trialTs = (!$noMembership && $row && !empty($row['trial_ends_at'])) ? strtotime($row['trial_ends_at']) : 0;
$endTs = max((int) $subTs, (int) $trialTs);
$planDays = 30;
$planCode = (!$noMembership && $row && !empty($row['plan_code'])) ? (string) $row['plan_code'] : '';
if ($planCode !== '' && isset($saas['plans'][$planCode]['days'])) {
    $planDays = max(1, (int) $saas['plans'][$planCode]['days']);
}
if ($subTs > 0) {
    $startTs = $subTs - ($planDays * 86400);
} elseif ($trialTs > 0) {
    $trialLen = isset($saas['trial_days']) ? (int) $saas['trial_days'] : 7;
    if ($trialLen < 1) {
        $trialLen = 7;
    }
    $startTs = $trialTs - ($trialLen * 86400);
} elseif (!$noMembership && $row && !empty($row['created_at'])) {
    $startTs = strtotime($row['created_at']);
} else {
    $startTs = 0;
}
$nowTs = time();
$elapsedDays = ($startTs > 0) ? max(0, (int) floor(($nowTs - $startTs) / 86400)) : 0;
$remainDays = ($endTs > $nowTs) ? (int) ceil(($endTs - $nowTs) / 86400) : 0;
if ($endTs > 0 && $endTs <= $nowTs) {
    $statusLabel = $isEn ? 'Expired' : 'منتهي';
    $statusRaw = 'expired';
}
$spanDays = $elapsedDays + $remainDays;
$usedPct = ($spanDays > 0) ? (int) round(($elapsedDays / $spanDays) * 100) : ($endTs > 0 ? 100 : 0);
if ($usedPct < 0) {
    $usedPct = 0;
}
if ($usedPct > 100) {
    $usedPct = 100;
}
$brandLogo = (function_exists('brand_icon_url') && isset($settings)) ? brand_icon_url($settings) : '';
$brandTitle = isset($siteName) ? (string) $siteName : 'البوابة';
$zcReady = !$noMembership && function_exists('zaincash_is_configured') && zaincash_is_configured($settings);
$fmtDay = function ($ts) {
    if (!$ts) {
        return '—';
    }
    return date('d-m-Y', (int) $ts);
};

render_header($isEn ? 'Billing' : 'الاشتراك والفوترة', 'billing');
?>
<div class="bill-wrap">
<style>
.bill-wrap { max-width: 720px; margin: 0 auto; }
.bill-card {
  border-radius: 22px; overflow: hidden; color: #fff;
  background: linear-gradient(145deg, #0f172a 0%, #1e3a5f 55%, #0f766e 130%);
  box-shadow: 0 18px 40px rgba(15, 23, 42, .18);
}
.bill-head { display: flex; align-items: center; gap: 14px; padding: 22px 22px 8px; }
.bill-logo {
  width: 64px; height: 64px; border-radius: 18px; background: rgba(255,255,255,.96);
  display: flex; align-items: center; justify-content: center; flex: 0 0 auto; overflow: hidden;
}
.bill-logo img { width: 64px; height: 64px; object-fit: cover; }
.bill-logo span { color: #0f172a; font-weight: 800; font-size: 13px; text-align: center; line-height: 1.2; padding: 6px; }
.bill-head h2 { margin: 0 0 4px; font-size: 22px; font-weight: 800; }
.bill-head p { margin: 0; opacity: .88; font-weight: 700; font-size: 13px; }
.bill-pill {
  display: inline-block; margin-top: 8px; padding: 3px 10px; border-radius: 999px;
  font-size: 12px; font-weight: 800; background: rgba(255,255,255,.16);
}
.bill-pill.ok { background: rgba(34,197,94,.28); }
.bill-pill.bad { background: rgba(244,63,94,.28); }
.bill-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 16px 22px 8px; }
.bill-stat {
  background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.14);
  border-radius: 16px; padding: 14px 14px 12px;
}
.bill-stat .k { display: block; font-size: 12px; font-weight: 800; opacity: .8; margin-bottom: 4px; }
.bill-stat .v { display: block; font-size: 32px; font-weight: 800; line-height: 1; }
.bill-stat .u { font-size: 13px; font-weight: 700; opacity: .85; }
.bill-bar { margin: 8px 22px 0; height: 8px; border-radius: 999px; background: rgba(255,255,255,.16); overflow: hidden; }
.bill-bar > i { display: block; height: 100%; background: linear-gradient(90deg, #22d3ee, #4ade80); border-radius: inherit; }
.bill-meta { padding: 12px 22px 6px; font-size: 13px; font-weight: 700; opacity: .9; line-height: 1.7; }
.bill-actions { padding: 8px 22px 22px; }
.bill-renew {
  display: inline-flex; align-items: center; justify-content: center; width: 100%;
  border: 0; border-radius: 14px; padding: 13px 16px; font: inherit; font-weight: 800; font-size: 16px;
  background: #fff; color: #0f172a; cursor: pointer; text-decoration: none;
}
.bill-renew:hover { background: #ecfeff; }
.bill-note { margin: 12px 0 0; color: #475569; font-weight: 700; }
.bill-plans { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; }
.bill-plan {
  background: #fff; border: 1px solid rgba(15,23,42,.08); border-radius: 16px; padding: 14px;
  box-shadow: 0 8px 22px rgba(15,23,42,.04);
}
.bill-plan strong { display: block; margin-bottom: 4px; }
@media (max-width: 640px) {
  .bill-stats, .bill-plans { grid-template-columns: 1fr; }
  .bill-head { padding: 16px 16px 6px; }
  .bill-stats, .bill-meta, .bill-actions { padding-left: 16px; padding-right: 16px; }
}
</style>
<div class="bill-card">
    <div class="bill-head">
        <div class="bill-logo">
            <?php if ($brandLogo !== ''): ?>
                <img src="<?php echo e($brandLogo); ?>" alt="<?php echo e($brandTitle); ?>">
            <?php else: ?>
                <span><?php echo e($brandTitle); ?></span>
            <?php endif; ?>
        </div>
        <div>
            <h2><?php echo e($isEn ? 'Subscription' : 'الاشتراك'); ?></h2>
            <p><?php echo e($billName); ?></p>
            <span class="bill-pill <?php echo ($statusRaw === 'active') ? 'ok' : 'bad'; ?>"><?php echo e($statusLabel); ?></span>
        </div>
    </div>
    <div class="bill-stats">
        <div class="bill-stat">
            <span class="k"><?php echo e($isEn ? 'Days used' : 'الأيام المنقضية'); ?></span>
            <span class="v"><?php echo (int) $elapsedDays; ?> <span class="u"><?php echo e($isEn ? 'days' : 'يوم'); ?></span></span>
        </div>
        <div class="bill-stat">
            <span class="k"><?php echo e($isEn ? 'Days left' : 'الأيام المتبقية'); ?></span>
            <span class="v"><?php echo (int) $remainDays; ?> <span class="u"><?php echo e($isEn ? 'days' : 'يوم'); ?></span></span>
        </div>
    </div>
    <div class="bill-bar" aria-hidden="true"><i style="width:<?php echo (int) $usedPct; ?>%"></i></div>
    <div class="bill-meta">
        <?php echo e($isEn ? 'Trial ends' : 'انتهاء التجريبي'); ?>: <?php echo e($fmtDay($trialTs)); ?><br>
        <?php echo e($isEn ? 'Subscription until' : 'الاشتراك حتى'); ?>: <?php echo e($fmtDay($subTs)); ?>
    </div>
    <div class="bill-actions">
        <?php if ($noMembership): ?>
            <div class="alert alert-error" style="margin:0"><?php echo e($isEn
                ? 'This account has no membership, so the subscription is expired.'
                : 'هذا الحساب بدون عضوية، والاشتراك منتهي الصلاحية.'); ?></div>
        <?php elseif (!$okSub && $msgSub !== ''): ?>
            <div class="alert alert-error" style="margin:0 0 10px"><?php echo e($msgSub); ?></div>
        <?php endif; ?>
        <?php if (!$noMembership): ?>
            <a class="bill-renew" href="#billPlans"><?php echo e($isEn ? 'Renew now' : 'جدد اشتراكك الآن'); ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$noMembership): ?>
    <div id="billPlans">
        <?php if (!$zcReady): ?>
            <p class="bill-note"><?php echo e($isEn
                ? 'ZainCash is not configured yet — ask the system owner.'
                : 'ZainCash غير مضبوط بعد — تواصل مع صاحب النظام.'); ?></p>
        <?php else: ?>
            <div class="bill-plans">
                <?php foreach ($saas['plans'] as $code => $plan):
                    if (!is_array($plan)) {
                        continue;
                    }
                    $label = isset($plan['label']) ? $plan['label'] : $code;
                    $days = isset($plan['days']) ? (int) $plan['days'] : 30;
                    $amount = isset($plan['amount']) ? (float) $plan['amount'] : 0;
                    ?>
                    <form method="post" class="bill-plan">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="plan_code" value="<?php echo e($code); ?>">
                        <strong><?php echo e($label); ?></strong>
                        <p class="meta"><?php echo (int) $days; ?> <?php echo e($isEn ? 'days' : 'يوم'); ?> —
                            <?php echo e(function_exists('money_format_iqd') ? money_format_iqd($amount, $config['currency']) : ((int) $amount . ' IQD')); ?></p>
                        <button class="btn" type="submit"><?php echo e($isEn ? 'Renew now' : 'جدد اشتراكك الآن'); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
<?php render_footer(); ?>
