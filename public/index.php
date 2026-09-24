<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$isEn = (isset($lang) && $lang === 'en');
// ويدجت محاسبة الكروت: للمحاسب فقط (مو للأدمن/الكل)
$showCardAccountingDash = function_exists('is_accountant_user') && is_accountant_user()
    && function_exists('user_can') && user_can('card_accounting');
$cardDashAgentId = 0;
if ($showCardAccountingDash) {
    $cardDashAgentId = accountant_linked_agent_id();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['card_payment']) && $showCardAccountingDash) {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('index.php');
    }
    $payAgentId = (int) post('agent_user_id', '0');
    $linked = accountant_linked_agent_id();
    if ($linked > 0) {
        $payAgentId = $linked;
    }
    $amount = (float) post('amount', '0');
    if (post('pay_all') === '1' && function_exists('card_agent_remaining_balance')) {
        $amount = card_agent_remaining_balance($pdo, $payAgentId);
    }
    $note = trim((string) post('payment_note', ''));
    $me = current_admin();
    $meId = $me ? (int) $me['id'] : 0;
    list($ok, $code) = record_card_payment($pdo, $payAgentId, $amount, $note, $meId);
    if ($ok) {
        if (function_exists('whatsapp_send') && $payAgentId > 0) {
            try {
                $agentRow = get_admin_user($pdo, $payAgentId);
                $phone = '';
                if ($agentRow && !empty($agentRow['phone'])) {
                    $phone = trim((string) $agentRow['phone']);
                }
                if ($phone !== '') {
                    $msg = $isEn
                        ? ('Payment recorded: ' . money_format_iqd($amount, $config['currency']))
                        : ('تم تسجيل دفعة: ' . money_format_iqd($amount, $config['currency']));
                    if ($note !== '') {
                        $msg .= ' — ' . $note;
                    }
                    whatsapp_send($config, $phone, $msg, 'card_payment');
                }
            } catch (Exception $e) {
            }
        }
        flash('success', $isEn ? 'Payment recorded' : 'تم تسجيل الدفعة');
    } else {
        flash('error', card_transfer_error_message($code, isset($lang) ? $lang : 'ar'));
    }
    redirect('index.php#card-accounting');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['card_remind']) && $showCardAccountingDash) {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('index.php');
    }
    $aid = accountant_linked_agent_id();
    if ($aid <= 0) {
        $aid = (int) post('agent_user_id', '0');
    }
    list($okR, $msgR) = card_agent_payment_remind($pdo, $config, $aid, isset($lang) ? $lang : 'ar');
    flash($okR ? 'success' : 'error', $msgR);
    redirect('index.php#card-accounting');
}

$canDisableAgentSas = $showCardAccountingDash
    || (function_exists('is_admin_user') && is_admin_user() && function_exists('user_can') && user_can('agents'));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_agent_sas']) && $canDisableAgentSas) {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('index.php');
    }
    if (post('confirm_disable') !== '1') {
        flash('error', $isEn ? 'Confirm disable required' : 'يلزم التأكيد قبل التعطيل');
        redirect('index.php#card-accounting');
    }
    $aid = (int) post('agent_user_id', '0');
    if ($showCardAccountingDash) {
        $linked = accountant_linked_agent_id();
        if ($linked > 0) {
            $aid = $linked;
        }
    }
    $me = current_admin();
    $meId = $me ? (int) $me['id'] : 0;
    list($okD, $msgD) = disable_agent_sas($pdo, $config, $aid, $meId);
    flash($okD ? 'success' : 'error', $msgD);
    redirect('index.php#card-accounting');
}

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
        $cachedLatMs = isset($_SESSION['sas_latency_ms']) ? $_SESSION['sas_latency_ms'] : null;
        // حرّر الجلسة قبل طلبات الساس/البنغ حتى لا يتوقف التنقّل
        if (function_exists('app_session_close')) {
            app_session_close();
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
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    @session_start();
                }
                $_SESSION['sas_latency_ms'] = (int) $lat['ms'];
                $_SESSION['sas_latency_host'] = isset($lat['host']) ? (string) $lat['host'] : '';
                if (function_exists('app_session_close')) {
                    app_session_close();
                }
            }
        } elseif ($cachedLatMs !== null && $cachedLatMs !== '') {
            $out['sas_ms'] = (int) $cachedLatMs;
            $out['balance'] = number_format((float) $cachedLatMs, 0) . ' ms';
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

$agentScope = function_exists('subscriber_agent_scope_sql') ? subscriber_agent_scope_sql('s') : '';
$totalSubscribers = (int) $pdo->query('SELECT COUNT(*) FROM subscribers s WHERE 1=1' . $agentScope)->fetchColumn();
$totalDebt = (float) $pdo->query(
    "SELECT COALESCE(SUM(i.amount),0) FROM invoices i
     JOIN subscribers s ON s.id = i.subscriber_id
     WHERE i.status = 'unpaid'" . $agentScope
)->fetchColumn();
$receivedMonth = (float) $pdo->query(
    "SELECT COALESCE(SUM(i.amount),0) FROM invoices i
     JOIN subscribers s ON s.id = i.subscriber_id
     WHERE i.status = 'paid' AND DATE_FORMAT(i.paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')" . $agentScope
)->fetchColumn();
$profitMonth = (float) $pdo->query(
    "SELECT COALESCE(SUM(i.profit),0) FROM invoices i
     JOIN subscribers s ON s.id = i.subscriber_id
     WHERE i.status = 'paid' AND DATE_FORMAT(i.paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')" . $agentScope
)->fetchColumn();
$salesMonth = (float) $pdo->query(
    "SELECT COALESCE(SUM(sub.monthly_price),0) FROM subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     WHERE DATE_FORMAT(sub.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')" . $agentScope
)->fetchColumn();
$activatedMonth = (int) $pdo->query(
    "SELECT COUNT(*) FROM subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     WHERE DATE_FORMAT(sub.created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')" . $agentScope
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
       AND TRIM(s.rental_device_id) <> ""' . $agentScope;
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
     )' . $agentScope
)->fetchColumn();
$expiredSubsCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM subscribers s
     WHERE NOT EXISTS (
         SELECT 1 FROM subscriptions sub
         WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
       )' . $agentScope
)->fetchColumn();
$expireTodayCount = (int) $pdo->query(
    'SELECT COUNT(DISTINCT sub.subscriber_id) FROM subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     WHERE sub.status = "active" AND sub.end_date = CURDATE()' . $agentScope
)->fetchColumn();

$sasPointsOk = false;
$sasPointsVal = null;
$sasPointsDisp = '—';
$expireSoonCount = (int) $pdo->query(
    'SELECT COUNT(DISTINCT sub.subscriber_id) FROM subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     WHERE sub.status = "active"
       AND sub.end_date > CURDATE()
       AND sub.end_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)' . $agentScope
)->fetchColumn();

$chartMonths = array();
$chartValues = array();
$chartYear = (int) date('Y');
for ($m = 1; $m <= 12; $m++) {
    $ym = sprintf('%04d-%02d', $chartYear, $m);
    $chartMonths[] = month_short_label($ym, true);
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(i.amount),0) FROM invoices i
         JOIN subscribers s ON s.id = i.subscriber_id
         WHERE i.status = 'paid' AND DATE_FORMAT(i.paid_at, '%Y-%m') = :ym" . $agentScope
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

$cardDash = null;
$cardDashPayments = array();
$cardDashLedger = array();
if ($showCardAccountingDash && function_exists('card_accounting_dashboard')) {
    ensure_card_accounting_tables($pdo);
    if ($cardDashAgentId > 0) {
        $cardDash = card_accounting_dashboard($pdo, $cardDashAgentId);
        $cardDashPayments = list_recent_card_payments($pdo, $cardDashAgentId, 8);
        if (function_exists('card_agent_transfers_ledger')) {
            $cardDashLedger = card_agent_transfers_ledger($pdo, $cardDashAgentId, 40);
        }
    }
}

render_header(t('dashboard'), 'dashboard', '');
$dashTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$dashSasReady = function_exists('sas_is_ready') && sas_is_ready($config);
if ($dashTid > 1 && !$dashSasReady):
?>
<div class="alert alert-error" style="margin:12px 14px;font-weight:700">
    <?php echo e($isEn
        ? 'No SAS reseller linked yet — add your account to see subscribers.'
        : 'ما مربوط حساب ريسيلر ساس بعد — أضف حسابك حتى تطلع المشتركين.'); ?>
    —
    <a href="settings.php?tab=sas"><?php echo e($isEn ? 'Add SAS account' : 'إضافة حساب ساس'); ?></a>
</div>
<?php
endif;
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
}
.sas-box-title { font-size: 13px; font-weight: 800; opacity: .95; z-index: 1; }
.sas-box-sub { font-size: 11px; font-weight: 600; opacity: .8; margin-top: 2px; z-index: 1; }
.sas-box-val { font-size: 28px; font-weight: 800; margin-top: 10px; z-index: 1; letter-spacing: -0.02em; }
.sas-box-ico { position: absolute; inset-inline-end: 14px; inset-block-start: 12px; font-size: 22px; opacity: .35; }
.sas-box.tone-blue { --c1: #38bdf8; --c2: #0369a1; }
.sas-box.tone-green { --c1: #4ade80; --c2: #15803d; }
.sas-box.tone-aqua { --c1: #22d3ee; --c2: #0e7490; }
.sas-box.tone-red { --c1: #fb7185; --c2: #be123c; }
.sas-box.tone-yellow { --c1: #fbbf24; --c2: #b45309; }
.sas-box.tone-teal { --c1: #2dd4bf; --c2: #0f766e; }
.sas-box.tone-purple { --c1: #fb923c; --c2: #c2410c; }
.sas-box.tone-lime { --c1: #a3e635; --c2: #4d7c0f; }
.sas-box.tone-navy { --c1: #64748b; --c2: #1e293b; }
.sas-box.tone-maroon { --c1: #f43f5e; --c2: #9f1239; }
.sas-dash h2.sas-sec { font-size: 15px; margin: 6px 0 10px; color: #444; font-family: inherit; }
.acct-pay-row {
  display: flex; flex-wrap: wrap; gap: 10px; align-items: end;
  margin: 0 0 16px; padding: 0;
}
.acct-pay-row label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; color: #475569; }
.acct-pay-row input {
  padding: 10px 12px; border: 1px solid #d8dee8; border-radius: 10px;
  font: inherit; min-width: 140px; background: #fff;
}
</style>
<?php if ($showCardAccountingDash): ?>
<div class="sas-dash">
    <h2 class="sas-sec"><?php echo e($isEn ? 'Card accounting' : 'محاسبة الكروت'); ?></h2>
    <?php if ($cardDash): ?>
    <div class="sas-boxes">
        <?php
        $stk = $cardDash['stock'];
        $xfer = $cardDash['transfers'];
        $catHint = '';
        if (!empty($stk['rows'])) {
            $bits = array();
            foreach ($stk['rows'] as $sr) {
                $bits[] = $sr['profile_name'] . ': ' . (int) $sr['qty'];
            }
            $catHint = implode(' · ', array_slice($bits, 0, 3));
        }
        dash_sas_box('cards.php', 'tone-navy', $isEn ? 'Remaining stock' : 'المخزون الشاغر', $catHint !== '' ? $catHint : ($isEn ? 'Cards left' : 'كروت متبقية'), (string) (int) $stk['total_qty'], '🃏');
        dash_sas_box('cards.php#card-transfer', 'tone-purple', $isEn ? 'Transfers' : 'التحويلات', $isEn ? 'Received cards' : 'كروت مستلمة', (string) (int) $xfer['qty'], '📦');
        dash_sas_box('cards.php', 'tone-green', $isEn ? 'Profit' : 'الربح', $isEn ? 'Wholesale vs agent' : 'جملة مقابل وكيل', money_format_iqd($cardDash['profit_total'], $config['currency']), '📈');
        dash_sas_box('index.php#acct-pay', 'tone-teal', $isEn ? 'Received' : 'المقبوض', $isEn ? 'Payments' : 'دفعات', money_format_iqd($cardDash['payments_total'], $config['currency']), '💵');
        dash_sas_box('index.php#acct-pay', 'tone-red', $isEn ? 'Remaining' : 'المتبقي', $isEn ? 'To collect' : 'باقي التحصيل', money_format_iqd($cardDash['remaining'], $config['currency']), '📄');
        ?>
    </div>
    <form method="post" class="acct-pay-row" id="acct-pay">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="card_payment" value="1">
        <input type="hidden" name="agent_user_id" value="<?php echo (int) $cardDashAgentId; ?>">
        <div>
            <label><?php echo e($isEn ? 'Payment amount' : 'مبلغ الدفعة'); ?></label>
            <input name="amount" type="number" min="0.01" step="0.01" max="<?php echo e(max(0.01, (float) $cardDash['remaining'])); ?>"
                   required placeholder="0"
                   value="">
            <p class="meta" style="margin:4px 0 0"><?php echo e($isEn ? 'Max = remaining' : 'الحد الأقصى = المتبقي'); ?>:
                <?php echo e(money_format_iqd($cardDash['remaining'], $config['currency'])); ?></p>
        </div>
        <div style="flex:1;min-width:200px">
            <label><?php echo e($isEn ? 'Note (optional)' : 'ملاحظة (اختياري)'); ?></label>
            <input name="payment_note" maxlength="255" style="width:100%" placeholder="<?php echo e($isEn ? 'Payment note…' : 'ملاحظة…'); ?>">
        </div>
        <button class="btn" type="submit"><?php echo e($isEn ? 'Partial pay' : 'تسديد جزئي'); ?></button>
        <button class="btn secondary" type="submit" name="pay_all" value="1"
                onclick="var a=this.form.amount; if(a){a.removeAttribute('required'); a.value='<?php echo e(number_format(max(0.01, (float) $cardDash['remaining']), 2, '.', '')); ?>';} return confirm(<?php echo json_encode($isEn ? 'Pay full remaining?' : 'تسديد كل المتبقي؟'); ?>);">
            <?php echo e($isEn ? 'Pay all remaining' : 'تسديد الكل'); ?>
        </button>
    </form>
    <div class="actions" style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px">
        <form method="post" style="display:inline" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Send WhatsApp payment reminder?' : 'إرسال تذكير واتساب بالتسديد؟'); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="card_remind" value="1">
            <input type="hidden" name="agent_user_id" value="<?php echo (int) $cardDashAgentId; ?>">
            <button class="btn ghost" type="submit"><?php echo e($isEn ? 'WA remind' : 'تذكير واتساب'); ?></button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Disable this agent SAS users? Debts/cache stay.' : 'تعطيل يوزرات ساس هذا الوكيل؟ الديون والكاش يبقون.'); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="disable_agent_sas" value="1">
            <input type="hidden" name="confirm_disable" value="1">
            <input type="hidden" name="agent_user_id" value="<?php echo (int) $cardDashAgentId; ?>">
            <button class="btn ghost" type="submit" style="color:#b91c1c"><?php echo e($isEn ? 'Disable agent SAS' : 'تعطيل ساس الوكيل'); ?></button>
        </form>
    </div>
    <?php if ($cardDashLedger): ?>
        <h3 style="margin:8px 0;font-size:14px"><?php echo e($isEn ? 'Transfer ledger' : 'سجل التحويلات'); ?></h3>
        <div class="table-wrap" style="margin:0 0 18px">
            <table class="table-compact" style="font-size:13px">
                <thead>
                <tr>
                    <th><?php echo e($isEn ? 'Date' : 'التاريخ'); ?></th>
                    <th><?php echo e($isEn ? 'Package' : 'الباقة'); ?></th>
                    <th><?php echo e($isEn ? 'Qty' : 'الكمية'); ?></th>
                    <th><?php echo e($isEn ? 'Total' : 'المبلغ'); ?></th>
                    <th><?php echo e($isEn ? 'From' : 'من'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cardDashLedger as $lg):
                    $lineTotal = (float) $lg['agent_price'] * (int) $lg['qty'];
                    ?>
                    <tr>
                        <td><?php echo e(isset($lg['created_at']) ? $lg['created_at'] : ''); ?></td>
                        <td><?php echo e(isset($lg['profile_name']) ? $lg['profile_name'] : ''); ?></td>
                        <td><?php echo (int) $lg['qty']; ?></td>
                        <td><?php echo e(money_format_iqd($lineTotal, $config['currency'])); ?></td>
                        <td><?php echo e(isset($lg['from_name']) ? $lg['from_name'] : '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($cardDashPayments): ?>
        <div class="table-wrap" style="margin:0 0 18px">
            <table class="table-compact" style="font-size:13px">
                <thead>
                <tr>
                    <th><?php echo e($isEn ? 'Date' : 'التاريخ'); ?></th>
                    <th><?php echo e($isEn ? 'Amount' : 'المبلغ'); ?></th>
                    <th><?php echo e($isEn ? 'Note' : 'ملاحظة'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cardDashPayments as $cp): ?>
                    <tr>
                        <td><?php echo e(isset($cp['created_at']) ? $cp['created_at'] : ''); ?></td>
                        <td><?php echo e(money_format_iqd($cp['amount'], $config['currency'])); ?></td>
                        <td><?php echo e(isset($cp['note']) ? $cp['note'] : ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php else: ?>
        <p class="meta" style="margin:0 0 18px"><?php echo e($isEn ? 'Link an agent to this accountant in Settings → Users.' : 'اربط وكيلاً بحساب المحاسب من الإعدادات → المستخدمين.'); ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if (!is_accountant_user()): ?>
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
<?php endif; ?>
<?php if ($sasReadyDash && !is_accountant_user()): ?>
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
  setInterval(function () { loadDash(false); }, 120000);
})();
</script>
<?php endif; ?>

<?php if (!is_accountant_user()): ?>
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
<?php endif; ?>
<?php render_footer(); ?>
