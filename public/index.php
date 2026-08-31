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
$sasPointsDisp = '—';
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

$sasReadyDash = function_exists('sas_is_ready') && sas_is_ready($config);
$sasCounts = array(
    'total' => $totalSubscribers,
    'active' => $activeOnlineCount,
    'online' => 0,
    'expired' => $expiredSubsCount,
    'soon' => $expireSoonCount,
    'today' => $expireTodayCount,
    'disabled' => 0,
);
$sasCardGroups = array();
$sasBalanceDisp = '—';
if ($sasReadyDash) {
    if (function_exists('sas_dash_user_counts')) {
        $sasCounts = sas_dash_user_counts($pdo);
    }
    $needCards = empty($_SESSION['sas_card_groups_v2']) || !isset($_SESSION['sas_card_groups_v2_at'])
        || ((time() - (int) $_SESSION['sas_card_groups_v2_at']) > 120);
    if (isset($_SESSION['sas_card_groups_v2']) && is_array($_SESSION['sas_card_groups_v2']) && !$_SESSION['sas_card_groups_v2']
        && isset($_SESSION['sas_card_groups_v2_at']) && ((time() - (int) $_SESSION['sas_card_groups_v2_at']) > 20)) {
        $needCards = true;
    }
    if ($needCards && function_exists('sas_page_connector') && function_exists('sas_dash_card_groups')) {
        try {
            $apiDash = sas_page_connector($config);
            if ($apiDash && method_exists($apiDash, 'setTimeout')) {
                $apiDash->setTimeout(35);
            }
            if ($apiDash) {
                $_SESSION['sas_card_groups_v2'] = sas_dash_card_groups($apiDash);
                $_SESSION['sas_card_groups_v2_at'] = time();
            }
        } catch (Exception $e) {
        }
    }
    if (isset($_SESSION['sas_card_groups_v2']) && is_array($_SESSION['sas_card_groups_v2'])) {
        $sasCardGroups = $_SESSION['sas_card_groups_v2'];
    }
    if (function_exists('sas_manager_reward_points')) {
        list($ptsOk, $ptsVal) = sas_manager_reward_points($config, $pdo);
        if ($ptsOk && $ptsVal !== null) {
            $sasPointsOk = true;
            $sasPointsVal = $ptsVal;
            $sasPointsDisp = ((float) $ptsVal == (int) $ptsVal)
                ? number_format((int) $ptsVal)
                : number_format((float) $ptsVal, 2);
        }
    }
    if (array_key_exists('sas_balance_disp', $_SESSION) && $_SESSION['sas_balance_disp'] !== '' && $_SESSION['sas_balance_disp'] !== null) {
        $sasBalanceDisp = (string) $_SESSION['sas_balance_disp'];
    }
}

if (!function_exists('dash_sas_box')) {
    function dash_sas_box($href, $tone, $title, $sub, $value, $ico)
    {
        echo '<a class="sas-box ' . e($tone) . '" href="' . e($href) . '">';
        echo '<div class="sas-box-title">' . e($title) . '</div>';
        if ($sub !== '') {
            echo '<div class="sas-box-sub">' . e($sub) . '</div>';
        }
        echo '<div class="sas-box-val">' . e($value) . '</div>';
        echo '<span class="sas-box-ico" aria-hidden="true">' . $ico . '</span>';
        echo '</a>';
    }
}

render_header(t('dashboard'), 'dashboard', '');
?>
<style>
.sas-dash { font-family: inherit; }
.sas-dash .sas-boxes {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin: 0 0 16px;
}
@media (max-width: 1100px) { .sas-dash .sas-boxes { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .sas-dash .sas-boxes { grid-template-columns: 1fr; } }
.sas-box {
  position: relative; overflow: hidden; display: block; color: #fff !important;
  text-decoration: none !important; border-radius: 4px; min-height: 90px;
  padding: 10px 10px 10px 10px; box-shadow: 0 1px 1px rgba(0,0,0,.1);
}
.sas-box:hover { color: #fff !important; filter: brightness(1.05); }
.sas-box-title { font-size: 15px; font-weight: 700; line-height: 1.3; }
.sas-box-sub { font-size: 13px; font-weight: 400; opacity: .92; margin-top: 2px; }
.sas-box-val { font-size: 26px; font-weight: 400; margin-top: 8px; line-height: 1; }
.sas-box-ico {
  position: absolute; inset-inline-end: 10px; top: 50%; transform: translateY(-50%);
  font-size: 46px; opacity: .22; pointer-events: none;
}
.sas-box.tone-blue { background: linear-gradient(180deg, #5c9fd6 0%, #3c8dbc 58%); }
.sas-box.tone-green { background: linear-gradient(180deg, #2ecc71 0%, #00a65a 58%); }
.sas-box.tone-aqua { background: linear-gradient(180deg, #4dd3f5 0%, #00c0ef 58%); }
.sas-box.tone-red { background: linear-gradient(180deg, #e74c3c 0%, #dd4b39 58%); }
.sas-box.tone-yellow { background: linear-gradient(180deg, #f6c15b 0%, #f39c12 58%); }
.sas-box.tone-teal { background: linear-gradient(180deg, #5dced4 0%, #39cccc 58%); }
.sas-box.tone-purple { background: linear-gradient(180deg, #8e7cc3 0%, #605ca8 58%); }
.sas-box.tone-lime { background: linear-gradient(180deg, #9ccc65 0%, #7cb342 58%); }
.sas-box.tone-navy { background: linear-gradient(180deg, #4a6785 0%, #3c4b64 58%); }
.sas-box.tone-maroon { background: linear-gradient(180deg, #e4728a 0%, #d81b60 58%); }
.sas-dash h2.sas-sec { font-size: 15px; margin: 6px 0 10px; color: #444; }
</style>
<div class="sas-dash">
<div class="sas-boxes">
<?php
$usersHome = 'sas.php';
$en = ($lang === 'en');
dash_sas_box($usersHome, 'tone-blue', $en ? 'Total users' : 'كل المشتركين', $en ? 'Registered users' : '', (string) (int) $sasCounts['total'], '👤');
dash_sas_box('sas.php?sub=active', 'tone-green', $en ? 'Active users' : 'فعال', '', (string) (int) $sasCounts['active'], '☺');
dash_sas_box('sas.php?sub=online', 'tone-aqua', $en ? 'Online users' : 'متصل حاليا', $en ? 'Connected' : '', (string) (int) $sasCounts['online'], '💡');
dash_sas_box('sas.php?sub=expired', 'tone-red', $en ? 'Expired users' : 'منتهي', '', (string) (int) $sasCounts['expired'], '☹');
dash_sas_box('sas.php?sub=soon', 'tone-yellow', $en ? 'About to expire' : 'على وشك الانتهاء', $en ? 'In 3 days' : '', (string) (int) $sasCounts['soon'], '📅');
dash_sas_box('sas.php?sub=today', 'tone-teal', $en ? 'Expiring today' : 'ينتهي اليوم', '', (string) (int) $sasCounts['today'], '📅');
if ($sasReadyDash) {
    dash_sas_box('sas.php', 'tone-lime', $en ? 'Reward points' : 'نقاط تشجيعية', '', (string) $sasPointsDisp, '🎁');
    dash_sas_box('sas.php', 'tone-navy', $en ? 'Balance' : 'الرصيد', '', (string) $sasBalanceDisp, '💵');
}
?>
</div>

<div class="sas-boxes">
<?php
dash_sas_box('reports.php', 'tone-yellow', $en ? 'Collected' : 'المقبوض', '', money_format_iqd($receivedMonth, $config['currency']), '💵');
dash_sas_box('debts.php?status=unpaid', 'tone-red', $en ? 'Debts' : 'الديون', '', money_format_iqd($totalDebt, $config['currency']), '📄');
dash_sas_box('reports.php', 'tone-green', $en ? 'Profit' : 'الربح', '', money_format_iqd($profitMonth, $config['currency']), '📈');
dash_sas_box('reports.php', 'tone-teal', $en ? 'Capital' : 'رأس المال', '', money_format_iqd($capitalMonth, $config['currency']), '🏦');
dash_sas_box('subscriptions.php', 'tone-purple', $en ? 'Sales' : 'المبيعات', '', money_format_iqd($salesMonth, $config['currency']), '🧾');
dash_sas_box('subscriptions.php', 'tone-aqua', $en ? 'Activations' : 'تفعيلات الشهر', '', (string) (int) $activatedMonth, '⚡');
dash_sas_box('rentals.php', 'tone-navy', $en ? 'Rental towers' : 'أبراج الإيجار', ((int) ($rentalActiveCount + $rentalInactiveCount)) . ' \\ ' . (int) $rentalActiveCount, (string) (int) $rentalActiveCount, '📡');
if ($sasReadyDash) {
    $cardTotal = 0;
    $cardSub = $en ? 'Unused' : 'شاغرة';
    if ($sasCardGroups) {
        foreach ($sasCardGroups as $g) {
            $cardTotal += isset($g['count']) ? (int) $g['count'] : 0;
        }
        if (count($sasCardGroups) === 1 && !empty($sasCardGroups[0]['name'])) {
            $cardSub = (string) $sasCardGroups[0]['name'];
        }
    }
    dash_sas_box('sas.php', 'tone-navy', $en ? 'Cards' : 'الكروت', $cardSub, (string) (int) $cardTotal, '🃏');
}
?>
</div>
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
