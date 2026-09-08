<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

if (isset($_GET['ajax']) && $_GET['ajax'] === 'dash_sas') {
    header('Content-Type: application/json; charset=utf-8');
    $en = (isset($lang) && $lang === 'en');
    $force = (isset($_GET['refresh']) && $_GET['refresh'] === '1');
    $out = array(
        'ok' => true,
        'points' => '—',
        'balance' => '—',
        'sas_ms' => null,
        'cards' => array(),
        'card_total' => 0,
        'card_sub' => $en ? 'Unused' : 'شاغرة',
        'from_cache' => false,
    );

    // قيم الكروت المخزّنة على السيرفر تظهر فوراً (يفضّل جرد الكروت الأدق)
    if (function_exists('sas_dash_cards_preferred_persisted')) {
        $persisted = sas_dash_cards_preferred_persisted();
        if ($persisted) {
            $out['cards'] = isset($persisted['groups']) ? $persisted['groups'] : array();
            $out['card_total'] = isset($persisted['card_total']) ? (int) $persisted['card_total'] : 0;
            if (!empty($persisted['card_sub'])) {
                $out['card_sub'] = (string) $persisted['card_sub'];
            } elseif (!$out['card_sub']) {
                $out['card_sub'] = $en ? 'Unused' : 'شاغرة';
            }
            $out['from_cache'] = true;
            $out['source'] = isset($persisted['source']) ? $persisted['source'] : '';
        }
    } elseif (function_exists('sas_dash_cards_load_persisted')) {
        $persisted = sas_dash_cards_load_persisted();
        if ($persisted) {
            $out['cards'] = isset($persisted['groups']) ? $persisted['groups'] : array();
            $out['card_total'] = isset($persisted['card_total']) ? (int) $persisted['card_total'] : 0;
            if (!empty($persisted['card_sub'])) {
                $out['card_sub'] = (string) $persisted['card_sub'];
            }
            $out['from_cache'] = true;
        }
    }

    if (function_exists('sas_is_ready') && sas_is_ready($config)) {
        if ($force) {
            $_SESSION['sas_rp_at'] = 0;
            if (function_exists('sas_clear_unused_card_cache')) {
                sas_clear_unused_card_cache();
            }
        }
        if (function_exists('sas_manager_reward_points')) {
            list($ptsOk, $ptsVal) = sas_manager_reward_points($config, $pdo);
            if ($ptsOk && $ptsVal !== null) {
                $out['points'] = ((float) $ptsVal == (int) $ptsVal)
                    ? number_format((int) $ptsVal)
                    : number_format((float) $ptsVal, 2);
            }
        }
        if (function_exists('system_sas_latency')) {
            $lat = system_sas_latency($config);
            if (isset($lat['ms']) && $lat['ms'] !== null) {
                $out['sas_ms'] = (int) $lat['ms'];
                $out['balance'] = number_format((float) $lat['ms'], 0) . ' ms';
                $_SESSION['sas_latency_ms'] = (int) $lat['ms'];
                $_SESSION['sas_latency_host'] = isset($lat['host']) ? (string) $lat['host'] : '';
            }
        } elseif (isset($_SESSION['sas_latency_ms'])) {
            $out['sas_ms'] = (int) $_SESSION['sas_latency_ms'];
            $out['balance'] = number_format((float) $_SESSION['sas_latency_ms'], 0) . ' ms';
        }

        $needCardsRefresh = $force;
        if (!$needCardsRefresh) {
            $p2 = function_exists('sas_dash_cards_preferred_persisted')
                ? sas_dash_cards_preferred_persisted()
                : (function_exists('sas_dash_cards_load_persisted') ? sas_dash_cards_load_persisted() : null);
            $pat = ($p2 && isset($p2['updated_at'])) ? (int) $p2['updated_at'] : 0;
            $hasGroups = ($p2 && !empty($p2['groups']) && is_array($p2['groups']));
            $src = ($p2 && isset($p2['source'])) ? (string) $p2['source'] : '';
            // إذا المخزون من inventory ودقيق، لا تعِد الجلب كل 3 دقائق بدون داعٍ
            if ($hasGroups && $pat > 0 && (time() - $pat) < ($src === 'inventory' ? 300 : 180)) {
                $needCardsRefresh = false;
            } else {
                $needCardsRefresh = true;
            }
        }

        if ($needCardsRefresh && function_exists('sas_page_connector') && function_exists('sas_dash_card_groups')) {
            try {
                $apiDash = sas_page_connector($config);
                if ($apiDash && method_exists($apiDash, 'setTimeout')) {
                    $apiDash->setTimeout(28);
                }
                if ($apiDash) {
                    $groups = sas_dash_card_groups($apiDash, true);
                    if (function_exists('sas_store_dash_card_groups')) {
                        sas_store_dash_card_groups($groups);
                    }
                    $payload = function_exists('sas_dash_cards_build_payload')
                        ? sas_dash_cards_build_payload($groups)
                        : array('groups' => $groups, 'card_total' => 0, 'card_sub' => '');
                    $out['cards'] = $groups;
                    $out['card_total'] = isset($payload['card_total']) ? (int) $payload['card_total'] : 0;
                    $out['card_sub'] = !empty($payload['card_sub'])
                        ? (string) $payload['card_sub']
                        : ($en ? 'Unused' : 'شاغرة');
                    $out['from_cache'] = false;
                }
            } catch (Exception $e) {
            }
        }
    }
    echo json_encode($out);
    exit;
}

$pdo->exec("UPDATE subscriptions SET status = 'expired' WHERE status = 'active' AND end_date < CURDATE()");
if (empty($_SESSION['archive_months_at']) || (time() - (int) $_SESSION['archive_months_at']) > 3600) {
    archive_closed_months($pdo);
    $_SESSION['archive_months_at'] = time();
}

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
// رأس المال = الربح + الديون
$capitalMonth = $profitMonth + $totalDebt;
// نفس منطق صفحة الإيجار: اشتراك محلي نشط أو صلاحية SAS سارية
$rentalJoin = ' FROM subscribers s
     LEFT JOIN sas_users_cache c ON c.local_subscriber_id = s.id
     LEFT JOIN sas_users_cache cu ON CONVERT(cu.username USING utf8mb4) COLLATE utf8mb4_unicode_ci
        = CONVERT(s.sas_username USING utf8mb4) COLLATE utf8mb4_unicode_ci
     WHERE (s.rental_enabled = 1 OR s.rental_enabled = "1")
       AND s.rental_device_id IS NOT NULL
       AND TRIM(s.rental_device_id) <> ""';
$rentalActiveSql = '(EXISTS (
         SELECT 1 FROM subscriptions sub
         WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
       ) OR (COALESCE(c.enabled, cu.enabled) = 1
            AND COALESCE(c.expire_at, cu.expire_at) IS NOT NULL
            AND COALESCE(c.expire_at, cu.expire_at) >= NOW()))';
$rentalTotalCount = (int) $pdo->query('SELECT COUNT(*)' . $rentalJoin)->fetchColumn();
$rentalActiveCount = (int) $pdo->query(
    'SELECT COUNT(*)' . $rentalJoin . ' AND ' . $rentalActiveSql
)->fetchColumn();
$rentalInactiveCount = max(0, $rentalTotalCount - $rentalActiveCount);

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
    // أولاً: كاش السيرفر الأدق (جرد الكروت) — بدون انتظار SAS
    if (function_exists('sas_dash_cards_preferred_persisted')) {
        $persistedCards = sas_dash_cards_preferred_persisted();
        if ($persistedCards && !empty($persistedCards['groups']) && is_array($persistedCards['groups'])) {
            $sasCardGroups = $persistedCards['groups'];
        }
    } elseif (function_exists('sas_dash_cards_load_persisted')) {
        $persistedCards = sas_dash_cards_load_persisted();
        if ($persistedCards && !empty($persistedCards['groups']) && is_array($persistedCards['groups'])) {
            $sasCardGroups = $persistedCards['groups'];
        }
    }
    if (!$sasCardGroups && isset($_SESSION['sas_card_groups_v5']) && is_array($_SESSION['sas_card_groups_v5'])) {
        $sasCardGroups = $_SESSION['sas_card_groups_v5'];
    } elseif (!$sasCardGroups && isset($_SESSION['sas_card_groups_v2']) && is_array($_SESSION['sas_card_groups_v2'])) {
        $sasCardGroups = $_SESSION['sas_card_groups_v2'];
    }
    if (isset($_SESSION['sas_rp_val']) && $_SESSION['sas_rp_val'] !== null) {
        $sasPointsOk = true;
        $sasPointsVal = $_SESSION['sas_rp_val'];
        $sasPointsDisp = ((float) $sasPointsVal == (int) $sasPointsVal)
            ? number_format((int) $sasPointsVal)
            : number_format((float) $sasPointsVal, 2);
    }
    // لا نعمل ping للساس عند فتح الصفحة — فقط من الكاش/الأجاكس (يمنع صفنة التنقل)
    if (isset($_SESSION['sas_latency_ms']) && $_SESSION['sas_latency_ms'] !== null && $_SESSION['sas_latency_ms'] !== '') {
        $sasBalanceDisp = number_format((float) $_SESSION['sas_latency_ms'], 0) . ' ms';
    }
}

if (!function_exists('dash_sas_box')) {
    function dash_sas_box($href, $tone, $title, $sub, $value, $ico, $boxId = '')
    {
        echo '<a class="sas-box ' . e($tone) . '" href="' . e($href) . '"'
            . ($boxId !== '' ? (' id="' . e($boxId) . '"') : '') . '>';
        echo '<div class="sas-box-title">' . e($title) . '</div>';
        if ($sub !== '' || $boxId !== '') {
            echo '<div class="sas-box-sub"' . ($boxId !== '' ? (' id="' . e($boxId) . 'Sub"') : '') . '>' . e($sub) . '</div>';
        }
        echo '<div class="sas-box-val"' . ($boxId !== '' ? (' id="' . e($boxId) . 'Val"') : '') . '>' . e($value) . '</div>';
        echo '<span class="sas-box-ico" aria-hidden="true">' . $ico . '</span>';
        echo '</a>';
    }
}

render_header(t('dashboard'), 'dashboard', '');
?>
<style>
.sas-dash { font-family: inherit; width: 100%; box-sizing: border-box; }
.sas-dash .sas-boxes {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin: 0 0 18px;
  width: 100%;
}
@media (max-width: 1100px) { .sas-dash .sas-boxes { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) {
  .sas-dash .sas-boxes { grid-template-columns: 1fr; gap: 11px; }
}
body:has(.sas-dash) .container {
  width: auto;
  max-width: 100%;
  margin-inline: 12px;
  padding-inline: 0;
  box-sizing: border-box;
}
.sas-box {
  --c1: #0f766e;
  --c2: #115e59;
  --ink: #ffffff;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  color: var(--ink) !important;
  text-decoration: none !important;
  font-family: inherit;
  border-radius: 2px 20px 2px 16px;
  min-height: 112px;
  padding: 16px 16px 14px 18px;
  background: linear-gradient(145deg, var(--c1) 0%, var(--c2) 100%);
  border: 0;
  box-shadow: 6px 7px 0 rgba(15, 23, 42, 0.12);
  transition: transform .16s ease, box-shadow .16s ease, filter .16s ease;
}
.sas-box::before {
  content: '';
  position: absolute;
  inset-block: 12px 12px;
  inset-inline-start: 0;
  width: 4px;
  border-radius: 0 6px 6px 0;
  background: rgba(255,255,255,0.55);
}
.sas-box::after {
  content: '';
  position: absolute;
  width: 110px;
  height: 110px;
  border-radius: 50%;
  inset-inline-end: -34px;
  inset-block-end: -42px;
  background: rgba(255,255,255,0.12);
  pointer-events: none;
}
.sas-box:hover {
  color: var(--ink) !important;
  transform: translate(-2px, -3px);
  box-shadow: 9px 10px 0 rgba(15, 23, 42, 0.14);
  filter: brightness(1.04);
}
.sas-box-title {
  position: relative;
  z-index: 1;
  font-family: inherit;
  font-size: 15px;
  font-weight: 800;
  line-height: 1.35;
  color: rgba(255,255,255,0.94);
  letter-spacing: 0.01em;
}
.sas-box-sub {
  position: relative;
  z-index: 1;
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  color: rgba(255,255,255,0.78);
  margin-top: 3px;
  line-height: 1.35;
}
#dashCardsSub { font-size: 13px; line-height: 1.3; max-height: 2.7em; overflow: hidden; }
.sas-box-val {
  position: relative;
  z-index: 1;
  font-family: inherit;
  font-size: 32px;
  font-weight: 800;
  margin-top: 16px;
  line-height: 1;
  color: #ffffff;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.02em;
  text-shadow: 0 1px 0 rgba(0,0,0,0.12);
}
.sas-box-ico { display: none !important; }
.sas-box.tone-blue { --c1: #3b82f6; --c2: #1d4ed8; }
.sas-box.tone-green { --c1: #22c55e; --c2: #15803d; }
.sas-box.tone-aqua { --c1: #22d3ee; --c2: #0e7490; }
.sas-box.tone-red { --c1: #fb7185; --c2: #be123c; }
.sas-box.tone-yellow { --c1: #fbbf24; --c2: #b45309; }
.sas-box.tone-teal { --c1: #2dd4bf; --c2: #0f766e; }
.sas-box.tone-purple { --c1: #fb923c; --c2: #c2410c; }
.sas-box.tone-lime { --c1: #a3e635; --c2: #4d7c0f; }
.sas-box.tone-navy { --c1: #64748b; --c2: #1e293b; }
.sas-box.tone-maroon { --c1: #f43f5e; --c2: #9f1239; }
.sas-dash h2.sas-sec { font-size: 15px; margin: 6px 0 10px; color: #444; font-family: inherit; }
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
    dash_sas_box('sas.php', 'tone-lime', $en ? 'Reward points' : 'نقاط تشجيعية', '', (string) $sasPointsDisp, '🎁', 'dashPoints');
    dash_sas_box('sas.php', 'tone-navy', $en ? 'SAS latency' : 'بنك الساس', $en ? 'Domain ping' : 'Latency دومين الساس', (string) $sasBalanceDisp, '📡', 'dashBank');
}
?>
</div>

<div class="sas-boxes">
<?php
dash_sas_box('reports.php', 'tone-yellow', $en ? 'Collected' : 'المقبوض', '', money_format_iqd($receivedMonth, $config['currency']), '💵');
dash_sas_box('debts.php?status=unpaid', 'tone-red', $en ? 'Debts' : 'الديون', '', money_format_iqd($totalDebt, $config['currency']), '📄');
dash_sas_box('reports.php', 'tone-green', $en ? 'Profit' : 'الربح', '', money_format_iqd($profitMonth, $config['currency']), '📈');
dash_sas_box('reports.php', 'tone-teal', $en ? 'Capital' : 'رأس المال', $en ? 'Profit + debts' : 'الربح + الديون', money_format_iqd($capitalMonth, $config['currency']), '🏦');
dash_sas_box('subscriptions.php', 'tone-purple', $en ? 'Sales' : 'المبيعات', '', money_format_iqd($salesMonth, $config['currency']), '🧾');
dash_sas_box('subscriptions.php', 'tone-aqua', $en ? 'Activations' : 'تفعيلات الشهر', '', (string) (int) $activatedMonth, '⚡');
dash_sas_box('rentals.php', 'tone-navy', $en ? 'Rental towers' : 'أبراج الإيجار', $en ? 'Total \\ active' : 'الكل \\ النشط', ((int) $rentalTotalCount) . '\\' . (int) $rentalActiveCount, '📡');
if ($sasReadyDash) {
    $cardTotal = 0;
    $cardParts = array();
    if ($sasCardGroups) {
        foreach ($sasCardGroups as $g) {
            $n = isset($g['count']) ? (int) $g['count'] : 0;
            if ($n <= 0) {
                continue;
            }
            $cardTotal += $n;
            $nm = isset($g['name']) ? (string) $g['name'] : '';
            $cardParts[] = trim($nm . ' ' . $n);
        }
    }
    $cardSub = $cardParts ? implode(' · ', $cardParts) : ($en ? 'Unused' : 'شاغرة');
    dash_sas_box('cards.php', 'tone-navy', $en ? 'Cards' : 'الكروت', $cardSub, (string) (int) $cardTotal, '🃏', 'dashCards');
}
?>
</div>
</div>
<?php if ($sasReadyDash): ?>
<script>
(function () {
  function applyDash(d) {
    if (!d || !d.ok) return;
    var p = document.getElementById('dashPointsVal');
    if (p && d.points) p.textContent = d.points;
    var b = document.getElementById('dashBankVal');
    if (b && d.balance) b.textContent = d.balance;
    var c = document.getElementById('dashCardsVal');
    if (c && typeof d.card_total === 'number') {
      var cur = parseInt(c.textContent, 10) || 0;
      // لا تستبدل رقم صحيح بـ 0 أثناء تحديث فاشل/جزئي
      if (d.card_total > 0 || cur <= 0 || d.from_cache) {
        c.textContent = String(d.card_total);
      }
    }
    var cs = document.getElementById('dashCardsSub');
    if (cs && d.card_sub) cs.textContent = d.card_sub;
  }
  function loadDash(force) {
    var url = 'index.php?ajax=dash_sas' + (force ? '&refresh=1' : '');
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(applyDash)
      .catch(function () {});
  }
  loadDash(false);
  setTimeout(function () { loadDash(false); }, 1500);
  setInterval(function () { loadDash(false); }, 120000);
})();
</script>
<?php endif; ?>

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
