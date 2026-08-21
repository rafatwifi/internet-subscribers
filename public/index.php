<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$pdo->exec("UPDATE subscriptions SET status = 'expired' WHERE status = 'active' AND end_date < CURDATE()");
archive_closed_months($pdo);

$totalSubscribers = (int) $pdo->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();
$totalDebt = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status = 'unpaid'")->fetchColumn();
$receivedMonth = (float) $pdo->query(
    "SELECT COALESCE(SUM(amount),0) FROM invoices
     WHERE status = 'paid' AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
)->fetchColumn();
$profitMonth = (float) $pdo->query(
    "SELECT COALESCE(SUM(profit),0) FROM invoices
     WHERE status = 'paid' AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
)->fetchColumn();
$salesMonth = (float) $pdo->query(
    "SELECT COALESCE(SUM(monthly_price),0) FROM subscriptions
     WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
)->fetchColumn();
$activatedMonth = (int) $pdo->query(
    "SELECT COUNT(*) FROM subscriptions
     WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
)->fetchColumn();
// رأس المال هذا الشهر = تكلفة الباقات المستلمة (ما يطلع كتكلفة من المبالغ المسددة)
$capitalMonth = (float) $pdo->query(
    "SELECT COALESCE(SUM(cost_price),0) FROM invoices
     WHERE status = 'paid' AND DATE_FORMAT(paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"
)->fetchColumn();
if ($capitalMonth <= 0 && $receivedMonth > 0) {
    $capitalMonth = max(0, $receivedMonth - $profitMonth);
}
$rentalInactiveCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM subscribers s
     WHERE s.rental_enabled = 1 AND s.rental_device_id IS NOT NULL AND s.rental_device_id <> ""
       AND NOT EXISTS (
         SELECT 1 FROM subscriptions sub
         WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
       )'
)->fetchColumn();
$rentalActiveCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM subscribers s
     WHERE s.rental_enabled = 1 AND s.rental_device_id IS NOT NULL AND s.rental_device_id <> ""
       AND EXISTS (
         SELECT 1 FROM subscriptions sub
         WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
       )'
)->fetchColumn();

// حالة الاشتراكات (مشتركين)
$activeOnlineCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM subscribers s
     WHERE EXISTS (
       SELECT 1 FROM subscriptions sub
       WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
     )'
)->fetchColumn();
$expiredSubsCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM subscribers s
     WHERE NOT EXISTS (
         SELECT 1 FROM subscriptions sub
         WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
       )'
)->fetchColumn();
$expireTodayCount = (int) $pdo->query(
    'SELECT COUNT(DISTINCT sub.subscriber_id) FROM subscriptions sub
     WHERE sub.status = "active" AND sub.end_date = CURDATE()'
)->fetchColumn();

$sasPointsOk = false;
$sasPointsVal = null;
if (function_exists('sas_is_ready') && sas_is_ready($config) && function_exists('sas_manager_reward_points')) {
    list($sasPointsOk, $sasPointsVal) = sas_manager_reward_points($config, $pdo);
}
$sasPointsDisp = '—';
if ($sasPointsOk && $sasPointsVal !== null) {
    $sasPointsDisp = ((float) $sasPointsVal == (int) $sasPointsVal)
        ? number_format((int) $sasPointsVal)
        : number_format((float) $sasPointsVal, 2);
}
$expireSoonCount = (int) $pdo->query(
    'SELECT COUNT(DISTINCT sub.subscriber_id) FROM subscriptions sub
     WHERE sub.status = "active"
       AND sub.end_date > CURDATE()
       AND sub.end_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)'
)->fetchColumn();

$chartMonths = array();
$chartValues = array();
$chartYear = (int) date('Y');
for ($m = 1; $m <= 12; $m++) {
    $ym = sprintf('%04d-%02d', $chartYear, $m);
    $chartMonths[] = month_short_label($ym, true);
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM invoices
         WHERE status = 'paid' AND DATE_FORMAT(paid_at, '%Y-%m') = :ym"
    );
    $stmt->execute(array(':ym' => $ym));
    $chartValues[] = (float) $stmt->fetchColumn();
}
$yearTotal = array_sum($chartValues);

render_header(t('dashboard'), 'dashboard', 'ملخص المشتركين والمبيعات والديون');
?>
<div class="cards cards-dash">
    <a class="card-stat glass g-blue" href="subscribers.php">
        <div class="label"><?php echo e(t('all_subscribers')); ?></div>
        <div class="value"><?php echo $totalSubscribers; ?></div>
    </a>
    <a class="card-stat glass g-orange" href="reports.php">
        <div class="label"><?php echo e(t('collected')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($receivedMonth, $config['currency'])); ?></div>
    </a>
    <a class="card-stat glass g-red" href="debts.php?status=unpaid">
        <div class="label"><?php echo e(t('debts_total')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($totalDebt, $config['currency'])); ?></div>
    </a>
    <a class="card-stat glass g-violet" href="subscriptions.php">
        <div class="label"><?php echo e(t('sales_short')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($salesMonth, $config['currency'])); ?></div>
    </a>
    <a class="card-stat glass g-green" href="reports.php">
        <div class="label"><?php echo e(t('profit')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($profitMonth, $config['currency'])); ?></div>
    </a>
    <a class="card-stat glass g-teal" href="reports.php" title="<?php echo e(t('capital_hint')); ?>">
        <div class="label"><?php echo e(t('capital')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($capitalMonth, $config['currency'])); ?></div>
        <div class="hint"><?php echo e(t('capital_hint')); ?></div>
    </a>
    <a class="card-stat glass g-orange" href="rentals.php" title="<?php echo e($lang === 'en' ? 'Total towers \\ still active' : 'كل الأبراج \\ المستمرين'); ?>">
        <div class="label"><?php echo e($lang === 'en' ? 'Rental towers' : 'أبراج الإيجار'); ?></div>
        <div class="value ratio-pair">
            <span><?php echo (int) ($rentalActiveCount + $rentalInactiveCount); ?></span>
            <span class="ratio-sep">\</span>
            <span><?php echo (int) $rentalActiveCount; ?></span>
        </div>
    </a>
    <a class="card-stat glass g-cyan" href="subscriptions.php">
        <div class="label"><?php echo e(t('activations_month')); ?></div>
        <div class="value"><?php echo $activatedMonth; ?></div>
    </a>
    <a class="card-stat glass g-lime" href="subscribers.php?sub=active" title="<?php echo e($lang === 'en' ? 'Active subscriptions' : 'اشتراكهم شغّال حالياً'); ?>">
        <div class="label"><?php echo e($lang === 'en' ? 'Active' : 'الفعالين'); ?></div>
        <div class="value"><?php echo (int) $activeOnlineCount; ?></div>
    </a>
    <a class="card-stat glass g-slate" href="subscribers.php?sub=expired" title="<?php echo e($lang === 'en' ? 'No active subscription' : 'ما عندهم اشتراك شغّال (منتهي أو ما انفعل)'); ?>">
        <div class="label"><?php echo e($lang === 'en' ? 'Expired' : 'المنتهية'); ?></div>
        <div class="value"><?php echo (int) $expiredSubsCount; ?></div>
    </a>
    <a class="card-stat glass g-amber" href="subscribers.php?sub=soon" title="<?php echo e($lang === 'en' ? 'Ends within next 3 days' : 'ينتهي خلال الأيام الثلاثة المقبلة'); ?>">
        <div class="label"><?php echo e($lang === 'en' ? 'Expiring soon' : 'على وشك الانتهاء'); ?></div>
        <div class="value"><?php echo (int) $expireSoonCount; ?></div>
        <div class="hint"><?php echo e($lang === 'en' ? 'Next 3 days' : 'خلال 3 أيام'); ?></div>
    </a>
    <a class="card-stat glass g-rose" href="subscribers.php?sub=today" title="<?php echo e($lang === 'en' ? 'Ends today' : 'ينتهي اليوم'); ?>">
        <div class="label"><?php echo e($lang === 'en' ? 'Ends today' : 'ينتهي اليوم'); ?></div>
        <div class="value"><?php echo (int) $expireTodayCount; ?></div>
    </a>
    <?php if (function_exists('sas_is_ready') && sas_is_ready($config)): ?>
    <a class="card-stat glass g-gold" href="settings.php?tab=sas" title="<?php echo e($lang === 'en' ? 'Available SAS reward points' : 'النقاط التشجيعية المتوفرة في SAS'); ?>">
        <div class="label"><?php echo e(t('sas_reward_points')); ?></div>
        <div class="value"><?php echo e($sasPointsDisp); ?></div>
    </a>
    <?php endif; ?>
</div>

<div class="panel chart-panel glass-panel panel-compact">
    <div class="chart-head chart-head-row">
        <div>
            <h2><?php echo e(t('collected_year')); ?> <?php echo (int) $chartYear; ?></h2>
            <div class="chart-sub"><?php echo e($lang === 'en' ? 'Collected per month (exact amounts)' : 'المقبوض شهرياً — أرقام حقيقية بدون نسب'); ?></div>
        </div>
        <div class="chart-year-total">
            <span class="chart-year-label"><?php echo e($lang === 'en' ? 'Year total' : 'مجموع السنة'); ?></span>
            <strong><?php echo e(money_format_iqd($yearTotal, $config['currency'])); ?></strong>
        </div>
    </div>
    <div class="month-grid">
        <?php for ($i = 0; $i < 12; $i++):
            $val = $chartValues[$i];
            $isCurrent = ((int) date('n') === ($i + 1));
            $hasVal = ($val > 0);
            $ymLink = sprintf('%04d-%02d', $chartYear, $i + 1);
            ?>
            <a class="month-cell<?php echo $isCurrent ? ' is-current' : ''; ?><?php echo $hasVal ? ' has-val' : ''; ?>"
               href="reports.php?month=<?php echo e(urlencode($ymLink)); ?>"
               title="<?php echo e($chartMonths[$i] . ': ' . money_format_iqd($val, $config['currency'])); ?>">
                <span class="month-name"><?php echo e($chartMonths[$i]); ?></span>
                <span class="month-amt"><?php echo $hasVal ? e(money_format_iqd($val, $config['currency'])) : '—'; ?></span>
            </a>
        <?php endfor; ?>
    </div>
</div>
<?php render_footer(); ?>
