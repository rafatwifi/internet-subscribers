<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$isEn = (isset($lang) && $lang === 'en');
// مستودع كروت الساس مال الوكالة. الوكيل والدخول بوكيل بدون عضوية ما يشوفونه.
$viewerOwnsSasWarehouse = empty($_SESSION['admin_sas_shadow'])
    && !(function_exists('is_agent_user') && is_agent_user());
$viewerCardQty = 0;
if (!$viewerOwnsSasWarehouse && empty($_SESSION['admin_sas_shadow']) && function_exists('card_agent_stock_summary')) {
    $meCards = function_exists('current_admin') ? current_admin() : null;
    $meCardsId = $meCards ? (int) $meCards['id'] : 0;
    if ($meCardsId > 0) {
        $meStock = card_agent_stock_summary($pdo, $meCardsId);
        $viewerCardQty = isset($meStock['total_qty']) ? (int) $meStock['total_qty'] : 0;
    }
}
// ويدجت محاسبة الكروت: للمحاسب فقط (مو للأدمن/الكل)
$showCardAccountingDash = function_exists('is_accountant_user') && is_accountant_user()
    && function_exists('user_can') && user_can('card_accounting');
$cardDashAgentId = 0;
$acctTargets = array();
if ($showCardAccountingDash) {
    $cardDashAgentId = accountant_linked_agent_id();
    $accLv = function_exists('accountant_scope_level') ? accountant_scope_level($pdo) : '';
    $treeIds = function_exists('accountant_tree_ids') ? accountant_tree_ids($pdo) : array();
    foreach ($treeIds as $treeUid) {
        if ((int) $treeUid === (int) $cardDashAgentId) {
            continue;
        }
        $treeRow = function_exists('get_admin_user') ? get_admin_user($pdo, (int) $treeUid) : null;
        if (!$treeRow) {
            continue;
        }
        $treeRole = isset($treeRow['role']) ? $treeRow['role'] : '';
        if ($accLv === 'admin' && $treeRole !== 'group_manager') {
            continue;
        }
        if ($accLv === 'group_manager' && $treeRole !== 'agent') {
            continue;
        }
        $acctTargets[] = $treeRow;
    }
    $pickAcct = isset($_GET['acct']) ? (int) $_GET['acct'] : 0;
    $picked = false;
    if ($pickAcct > 0) {
        foreach ($acctTargets as $tRow) {
            if ((int) $tRow['id'] === $pickAcct) {
                $cardDashAgentId = $pickAcct;
                $picked = true;
                break;
            }
        }
    }
    if (!$picked && $acctTargets) {
        $cardDashAgentId = (int) $acctTargets[0]['id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['card_payment']) && $showCardAccountingDash) {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('index.php');
    }
    $payAgentId = (int) post('agent_user_id', '0');
    $allowedPay = array();
    foreach ($acctTargets as $tRow) {
        $allowedPay[] = (int) $tRow['id'];
    }
    if (!$allowedPay && $cardDashAgentId > 0) {
        $allowedPay[] = (int) $cardDashAgentId;
    }
    if (!in_array($payAgentId, $allowedPay, true)) {
        flash('error', $isEn ? 'This agent is outside your tree' : 'هذا الوكيل مو ضمن شجرتك');
        redirect('index.php#card-accounting');
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
                $payAllowed = function_exists('whatsapp_agent_notice_allowed')
                    && whatsapp_agent_notice_allowed($pdo, $meId, $payAgentId);
                if ($phone !== '' && $payAllowed) {
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
    $aid = (int) post('agent_user_id', '0');
    if ($aid <= 0) {
        $aid = (int) $cardDashAgentId;
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
    if ($showCardAccountingDash && $aid <= 0) {
        $aid = (int) $cardDashAgentId;
    }
    $me = current_admin();
    $meId = $me ? (int) $me['id'] : 0;
    list($okD, $msgD) = disable_agent_sas($pdo, $config, $aid, $meId);
    flash($okD ? 'success' : 'error', $msgD);
    redirect('index.php#card-accounting');
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'dash_ping') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    $pingOut = function_exists('dash_reseller_pings')
        ? dash_reseller_pings($config)
        : array('ok' => true, 'balance' => '-', 'sub' => '', 'count' => 0);
    echo json_encode($pingOut);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'dash_company_pings') {
    header('Content-Type: application/json; charset=utf-8');
    if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
        echo json_encode(array('ok' => false, 'rows' => array()));
        exit;
    }
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    $coOut = function_exists('dash_platform_company_pings')
        ? dash_platform_company_pings(true)
        : array('ok' => true, 'rows' => array());
    echo json_encode($coOut);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'dash_points') {
    header('Content-Type: application/json; charset=utf-8');
    $ptsOut = array('ok' => false, 'points' => '');
    if (function_exists('sas_manager_reward_points')) {
        list($ptsOk, $ptsVal) = sas_manager_reward_points($config, $pdo);
        if ($ptsOk && $ptsVal !== null) {
            $ptsOut['ok'] = true;
            $ptsOut['points'] = ((float) $ptsVal == (int) $ptsVal)
                ? number_format((int) $ptsVal)
                : number_format((float) $ptsVal, 2);
        }
    }
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    echo json_encode($ptsOut);
    exit;
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
        'card_sub' => $en ? 'No free cards' : 'ماكو كروت شاغرة',
        'from_cache' => false,
    );

    // قيم الكروت المخزّنة على السيرفر تظهر فوراً (يفضّل جرد الكروت الأدق)
    if (!$viewerOwnsSasWarehouse) {
        $out['cards'] = array();
        $out['card_total'] = (int) $viewerCardQty;
        $out['card_sub'] = $en ? 'My stock' : 'مخزوني';
        $out['from_cache'] = false;
    } elseif (function_exists('sas_dash_cards_preferred_persisted')) {
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

    if (!$force) {
        if (isset($_SESSION['sas_rp_val']) && $_SESSION['sas_rp_val'] !== null && $_SESSION['sas_rp_val'] !== '') {
            $cachedPts = $_SESSION['sas_rp_val'];
            $out['points'] = ((float) $cachedPts == (int) $cachedPts)
                ? number_format((int) $cachedPts)
                : number_format((float) $cachedPts, 2);
        }
        $cachedMs = isset($_SESSION['sas_latency_ms']) ? $_SESSION['sas_latency_ms'] : null;
        $cachedHost = isset($_SESSION['sas_latency_host']) ? trim((string) $_SESSION['sas_latency_host']) : '';
        $bankNow = function_exists('system_reseller_bank') ? system_reseller_bank($config) : array('host' => '');
        $bankHostNow = isset($bankNow['host']) ? trim((string) $bankNow['host']) : '';
        $hostMatch = ($bankHostNow === '' || strcasecmp($cachedHost, $bankHostNow) === 0);
        if ($hostMatch && $cachedMs !== null && $cachedMs !== '') {
            $out['sas_ms'] = (int) $cachedMs;
            $out['balance'] = number_format((float) $cachedMs, 0) . ' ms';
        }
        if (function_exists('app_session_close')) {
            app_session_close();
        }
        echo json_encode($out);
        exit;
    }

    if (function_exists('sas_is_ready') && sas_is_ready($config)) {
        if ($force) {
            if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_reopen')) {
                app_session_reopen();
            }
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
                if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_reopen')) {
                    app_session_reopen();
                } elseif (session_status() !== PHP_SESSION_ACTIVE) {
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

        $needCardsRefresh = ($viewerOwnsSasWarehouse && $force);

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
                        : ($en ? 'No free cards' : 'ماكو كروت شاغرة');
                    $out['from_cache'] = false;
                }
            } catch (Exception $e) {
            }
        }
    }
    if ($viewerOwnsSasWarehouse && (int) $out['card_total'] <= 0) {
        $out['card_total'] = 0;
        $out['cards'] = array();
        $out['card_sub'] = $en ? 'No free cards' : 'ماكو كروت شاغرة';
    }
    echo json_encode($out);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'dash_stats') {
    header('Content-Type: application/json; charset=utf-8');
    $enStats = (isset($lang) && $lang === 'en');
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    $stats = array(
        'ok' => true,
        'total' => 0,
        'active' => 0,
        'online' => 0,
        'expired' => 0,
        'soon' => 0,
        'today' => 0,
    );
    if (function_exists('sas_dash_live_refresh')) {
        try {
            sas_dash_live_refresh($pdo, $config);
        } catch (Exception $e) {
        }
    }
    if (function_exists('sas_dash_user_counts')) {
        $counts = sas_dash_user_counts($pdo);
        if (is_array($counts)) {
            foreach (array('total', 'active', 'online', 'expired', 'soon', 'today') as $sk) {
                if (isset($counts[$sk])) {
                    $stats[$sk] = (int) $counts[$sk];
                }
            }
        }
    }
    $scopeStats = function_exists('subscriber_agent_scope_sql') ? subscriber_agent_scope_sql('s') : '';
    $monthA = date('Y-m-01 00:00:00');
    $monthB = date('Y-m-01 00:00:00', strtotime(date('Y-m-01') . ' +1 month'));
    $debtN = 0.0;
    $gotN = 0.0;
    $profitN = 0.0;
    $salesN = 0.0;
    $actN = 0;
    try {
        $debtN = (float) $pdo->query(
            "SELECT COALESCE(SUM(i.amount),0) FROM invoices i
             JOIN subscribers s ON s.id = i.subscriber_id
             WHERE i.status = 'unpaid'" . $scopeStats
        )->fetchColumn();
        $gotN = (float) $pdo->query(
            "SELECT COALESCE(SUM(i.amount),0) FROM invoices i
             JOIN subscribers s ON s.id = i.subscriber_id
             WHERE i.status = 'paid' AND i.paid_at >= " . $pdo->quote($monthA) . " AND i.paid_at < " . $pdo->quote($monthB) . $scopeStats
        )->fetchColumn();
        $profitN = (float) $pdo->query(
            "SELECT COALESCE(SUM(i.profit),0) FROM invoices i
             JOIN subscribers s ON s.id = i.subscriber_id
             WHERE i.status = 'paid' AND i.paid_at >= " . $pdo->quote($monthA) . " AND i.paid_at < " . $pdo->quote($monthB) . $scopeStats
        )->fetchColumn();
        $salesN = (float) $pdo->query(
            "SELECT COALESCE(SUM(sub.monthly_price),0) FROM subscriptions sub
             JOIN subscribers s ON s.id = sub.subscriber_id
             WHERE sub.created_at >= " . $pdo->quote($monthA) . " AND sub.created_at < " . $pdo->quote($monthB) . $scopeStats
        )->fetchColumn();
        $actN = (int) $pdo->query(
            "SELECT COUNT(*) FROM subscriptions sub
             JOIN subscribers s ON s.id = sub.subscriber_id
             WHERE sub.created_at >= " . $pdo->quote($monthA) . " AND sub.created_at < " . $pdo->quote($monthB) . $scopeStats
        )->fetchColumn();
    } catch (Exception $e) {
    }
    $curcy = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $stats['collected'] = function_exists('money_format_iqd') ? money_format_iqd($gotN, $curcy) : (string) $gotN;
    $stats['debts'] = function_exists('money_format_iqd') ? money_format_iqd($debtN, $curcy) : (string) $debtN;
    $stats['profit'] = function_exists('money_format_iqd') ? money_format_iqd($profitN, $curcy) : (string) $profitN;
    $stats['capital'] = function_exists('money_format_iqd') ? money_format_iqd($profitN + $debtN, $curcy) : (string) ($profitN + $debtN);
    $stats['sales'] = function_exists('money_format_iqd') ? money_format_iqd($salesN, $curcy) : (string) $salesN;
    $stats['activations'] = (int) $actN;
    $cardBits = array();
    $cardSum = 0;
    if (!empty($viewerOwnsSasWarehouse) && function_exists('sas_dash_cards_preferred_persisted')) {
        $cardSaved = sas_dash_cards_preferred_persisted();
        if ($cardSaved && !empty($cardSaved['groups']) && is_array($cardSaved['groups'])) {
            foreach ($cardSaved['groups'] as $cg) {
                if (!is_array($cg)) {
                    continue;
                }
                $cn = isset($cg['count']) ? (int) $cg['count'] : 0;
                if ($cn <= 0) {
                    continue;
                }
                $cardSum += $cn;
                $nm = isset($cg['name']) ? trim((string) $cg['name']) : '';
                if ($nm !== '') {
                    $cardBits[] = $nm . ' ' . $cn;
                }
            }
        }
        $stats['card_total'] = $cardSum;
        $stats['card_sub'] = $cardBits
            ? implode(' · ', $cardBits)
            : ($enStats ? 'No free cards' : 'ماكو كروت شاغرة');
    }
    echo json_encode($stats);
    exit;
}


$expDir = dirname(__DIR__) . '/storage/cache';
if (!is_dir($expDir)) {
    @mkdir($expDir, 0775, true);
}
$expTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$expLock = $expDir . '/sub_expire_t' . $expTid . '.txt';
if (!is_file($expLock) || (time() - (int) @filemtime($expLock)) > 900) {
    $pdo->exec("UPDATE subscriptions SET status = 'expired' WHERE status = 'active' AND end_date < CURDATE()");
    @file_put_contents($expLock, (string) time());
}
if (empty($_SESSION['archive_months_at']) || (time() - (int) $_SESSION['archive_months_at']) > 3600) {
    archive_closed_months($pdo);
    $_SESSION['archive_months_at'] = time();
    if (function_exists('app_session_touch')) {
        app_session_touch();
    }
}
if (function_exists('app_session_close')) {
    app_session_close();
}

/* لا تنقل دفتر أو مشتركين وكالة ثانية عند فتح اللوحة */
$agentScope = function_exists('subscriber_agent_scope_sql') ? subscriber_agent_scope_sql('s') : '';
$sasReadyDash = function_exists('sas_is_ready') && sas_is_ready($config);
$keepScopedCounts = function_exists('is_accountant_user') && is_accountant_user();
$useSasCounts = $sasReadyDash && !$keepScopedCounts && function_exists('sas_dash_user_counts');
$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-01 00:00:00', strtotime(date('Y-m-01') . ' +1 month'));
$totalSubscribers = 0;
if (!$useSasCounts) {
    $totalSubscribers = (int) $pdo->query('SELECT COUNT(*) FROM subscribers s WHERE 1=1' . $agentScope)->fetchColumn();
}
$totalDebt = (float) $pdo->query(
    "SELECT COALESCE(SUM(i.amount),0) FROM invoices i
     JOIN subscribers s ON s.id = i.subscriber_id
     WHERE i.status = 'unpaid'" . $agentScope
)->fetchColumn();
$receivedMonth = (float) $pdo->query(
    "SELECT COALESCE(SUM(i.amount),0) FROM invoices i
     JOIN subscribers s ON s.id = i.subscriber_id
     WHERE i.status = 'paid' AND i.paid_at >= " . $pdo->quote($monthStart) . " AND i.paid_at < " . $pdo->quote($monthEnd) . $agentScope
)->fetchColumn();
$profitMonth = (float) $pdo->query(
    "SELECT COALESCE(SUM(i.profit),0) FROM invoices i
     JOIN subscribers s ON s.id = i.subscriber_id
     WHERE i.status = 'paid' AND i.paid_at >= " . $pdo->quote($monthStart) . " AND i.paid_at < " . $pdo->quote($monthEnd) . $agentScope
)->fetchColumn();
$salesMonth = (float) $pdo->query(
    "SELECT COALESCE(SUM(sub.monthly_price),0) FROM subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     WHERE sub.created_at >= " . $pdo->quote($monthStart) . " AND sub.created_at < " . $pdo->quote($monthEnd) . $agentScope
)->fetchColumn();
$activatedMonth = (int) $pdo->query(
    "SELECT COUNT(*) FROM subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     WHERE sub.created_at >= " . $pdo->quote($monthStart) . " AND sub.created_at < " . $pdo->quote($monthEnd) . $agentScope
)->fetchColumn();
$dashGrowth = '0%';
$dashGrowthSub = '';
try {
    $yNow = (int) date('Y');
    $newThis = (int) $pdo->query(
        'SELECT COUNT(*) FROM subscribers s WHERE YEAR(s.created_at) = ' . $yNow . $agentScope
    )->fetchColumn();
    $newLast = (int) $pdo->query(
        'SELECT COUNT(*) FROM subscribers s WHERE YEAR(s.created_at) = ' . ($yNow - 1) . $agentScope
    )->fetchColumn();
    if ($newLast > 0) {
        $pct = (int) round((($newThis - $newLast) / $newLast) * 100);
        $dashGrowth = ($pct > 0 ? '+' : '') . $pct . '%';
    } elseif ($newThis > 0) {
        $dashGrowth = '+' . (int) $newThis;
    }
    $dashGrowthSub = (string) $newThis . ' / ' . (string) $yNow;
} catch (Exception $e) {
}
$dashVersion = function_exists('app_version') ? (string) app_version() : '1.4.0';
// رأس المال = الربح + الديون
$capitalMonth = $profitMonth + $totalDebt;
// نفس مشتركي جدول الإيجار، بدون تكرار صفوف الساس
$tidRent = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$rentOwn = function_exists('subscriber_ledger_owner_sql') ? subscriber_ledger_owner_sql('s') : '';
$rentalFrom = ' FROM subscribers s
     WHERE (s.rental_enabled = 1 OR s.rental_enabled = "1")
       AND s.rental_device_id IS NOT NULL
       AND TRIM(s.rental_device_id) <> ""
       AND s.tenant_id = ' . $tidRent . $rentOwn . $agentScope;
$rentalTotalCount = 0;
$rentalActiveCount = 0;
$rentalProbe = 0;
try {
    $rentalProbe = (int) $pdo->query(
        'SELECT COUNT(*) FROM subscribers s WHERE (s.rental_enabled = 1 OR s.rental_enabled = "1") AND s.tenant_id = ' . $tidRent . $agentScope
    )->fetchColumn();
} catch (Exception $e) {
    $rentalProbe = 1;
}
if ($rentalProbe > 0) {
$rentalTotalCount = (int) $pdo->query('SELECT COUNT(DISTINCT s.id)' . $rentalFrom)->fetchColumn();
$rentalActiveCount = (int) $pdo->query(
    'SELECT COUNT(DISTINCT s.id)' . $rentalFrom . ' AND (
        EXISTS (
            SELECT 1 FROM subscriptions sub
            WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
        )
        OR EXISTS (
            SELECT 1 FROM sas_users_cache c
            WHERE c.tenant_id = ' . $tidRent . '
              AND c.enabled = 1
              AND c.expire_at IS NOT NULL
              AND c.expire_at >= NOW()
              AND (
                c.local_subscriber_id = s.id
                OR (
                    s.sas_username IS NOT NULL AND s.sas_username <> ""
                    AND CONVERT(c.username USING utf8mb4) COLLATE utf8mb4_unicode_ci
                        = CONVERT(s.sas_username USING utf8mb4) COLLATE utf8mb4_unicode_ci
                )
              )
        )
    )'
)->fetchColumn();
}

// حالة الاشتراكات (مشتركين) — تُستبدل بأعداد كاش الساس عندما الساس مربوط
$activeOnlineCount = 0;
$expiredSubsCount = 0;
$expireTodayCount = 0;
$expireSoonCount = 0;
if (!$useSasCounts) {
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

$expireSoonCount = (int) $pdo->query(
    'SELECT COUNT(DISTINCT sub.subscriber_id) FROM subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     WHERE sub.status = "active"
       AND sub.end_date > CURDATE()
       AND sub.end_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)' . $agentScope
)->fetchColumn();
}

$sasPointsOk = false;
$sasPointsVal = null;
$sasPointsDisp = '—';

$chartMonths = array();
$chartProfit = array();
$chartActs = array();
$chartProfitFmt = array();
$chartYear = (int) date('Y');
for ($m = 1; $m <= 12; $m++) {
    $ym = sprintf('%04d-%02d', $chartYear, $m);
    $chartMonths[] = month_short_label($ym, true);
    $chartProfit[] = 0.0;
    $chartActs[] = 0;
    $chartProfitFmt[] = money_format_iqd(0, $config['currency']);
}
$chartRangeA = sprintf('%04d-01-01 00:00:00', $chartYear);
$chartRangeB = sprintf('%04d-01-01 00:00:00', $chartYear + 1);
try {
    $chartStmt = $pdo->prepare(
        "SELECT MONTH(i.paid_at) AS m, COALESCE(SUM(i.profit),0) AS amt
         FROM invoices i
         JOIN subscribers s ON s.id = i.subscriber_id
         WHERE i.status = 'paid'
           AND i.paid_at >= :a AND i.paid_at < :b" . $agentScope . "
         GROUP BY MONTH(i.paid_at)"
    );
    $chartStmt->execute(array(':a' => $chartRangeA, ':b' => $chartRangeB));
    while ($chartRow = $chartStmt->fetch()) {
        $chartMi = (int) $chartRow['m'];
        if ($chartMi >= 1 && $chartMi <= 12) {
            $chartProfit[$chartMi - 1] = (float) $chartRow['amt'];
            $chartProfitFmt[$chartMi - 1] = money_format_iqd($chartProfit[$chartMi - 1], $config['currency']);
        }
    }
} catch (Exception $e) {
}
try {
    $chartActStmt = $pdo->prepare(
        "SELECT MONTH(sub.created_at) AS m, COUNT(*) AS n
         FROM subscriptions sub
         JOIN subscribers s ON s.id = sub.subscriber_id
         WHERE sub.created_at >= :a AND sub.created_at < :b" . $agentScope . "
         GROUP BY MONTH(sub.created_at)"
    );
    $chartActStmt->execute(array(':a' => $chartRangeA, ':b' => $chartRangeB));
    while ($chartRow = $chartActStmt->fetch()) {
        $chartMi = (int) $chartRow['m'];
        if ($chartMi >= 1 && $chartMi <= 12) {
            $chartActs[$chartMi - 1] = (int) $chartRow['n'];
        }
    }
} catch (Exception $e) {
}
$yearProfitTotal = array_sum($chartProfit);
$yearActsTotal = 0;
foreach ($chartActs as $chartActN) {
    $yearActsTotal += (int) $chartActN;
}
$twinChartJson = json_encode(array(
    'months' => $chartMonths,
    'profit' => $chartProfit,
    'profitFmt' => $chartProfitFmt,
    'acts' => $chartActs,
    'cur' => ((int) date('n')) - 1,
    'year' => $chartYear,
    'labels' => array(
        'profit' => ($lang === 'en' ? 'Profit' : 'الأرباح'),
        'acts' => ($lang === 'en' ? 'Activations' : 'التفعيلات'),
        'empty' => ($lang === 'en' ? 'No data' : 'ماكو بيانات'),
    ),
));
if ($twinChartJson === false) {
    $twinChartJson = '{}';
}

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
    if ($useSasCounts) {
        $sasCounts = sas_dash_user_counts($pdo);
    }
    // أولاً: كاش السيرفر الأدق (جرد الكروت) — بدون انتظار SAS
    if ($viewerOwnsSasWarehouse) {
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
    }
    if (isset($_SESSION['sas_rp_val']) && $_SESSION['sas_rp_val'] !== null) {
        $sasPointsOk = true;
        $sasPointsVal = $_SESSION['sas_rp_val'];
        $sasPointsDisp = ((float) $sasPointsVal == (int) $sasPointsVal)
            ? number_format((int) $sasPointsVal)
            : number_format((float) $sasPointsVal, 2);
    }
    $dashBankInfo = function_exists('system_reseller_bank')
        ? system_reseller_bank($config)
        : array('host' => '', 'name' => '');
    $dashBankHost = isset($dashBankInfo['host']) ? trim((string) $dashBankInfo['host']) : '';
    $dashBankName = isset($dashBankInfo['name']) ? trim((string) $dashBankInfo['name']) : '';
    $cachedLatHost = isset($_SESSION['sas_latency_host']) ? trim((string) $_SESSION['sas_latency_host']) : '';
    $latHostOk = ($dashBankHost === '' || strcasecmp($cachedLatHost, $dashBankHost) === 0);
    // لا نعمل ping عند فتح الصفحة — فقط من الكاش إذا كان لنفس الريسلر
    if ($latHostOk && isset($_SESSION['sas_latency_ms']) && $_SESSION['sas_latency_ms'] !== null && $_SESSION['sas_latency_ms'] !== '') {
        $sasBalanceDisp = number_format((float) $_SESSION['sas_latency_ms'], 0) . ' ms';
    }
} else {
    $offTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($offTid > 1) {
    try {
        $sasCounts['total'] = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . $offTid)->fetchColumn();
        $sasCounts['online'] = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . $offTid . ' AND is_online = 1')->fetchColumn();
        $sasCounts['active'] = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . $offTid . ' AND enabled = 1 AND expire_at IS NOT NULL AND expire_at >= NOW()')->fetchColumn();
        $sasCounts['expired'] = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . $offTid . ' AND enabled = 1 AND expire_at IS NOT NULL AND expire_at < NOW()')->fetchColumn();
        $sasCounts['soon'] = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . $offTid . ' AND enabled = 1 AND expire_at > NOW() AND expire_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)')->fetchColumn();
        $sasCounts['today'] = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . $offTid . ' AND enabled = 1 AND DATE(expire_at) = CURDATE()')->fetchColumn();
    } catch (Exception $e) {
    }
    }
}

if (!function_exists('dash_sas_box')) {
    function dash_sas_box($href, $tone, $title, $sub, $value, $ico, $boxId = '', $extra = '', $dashKey = '')
    {
        if ($dashKey === '' && !empty($GLOBALS['dashLoopKey'])) {
            $dashKey = (string) $GLOBALS['dashLoopKey'];
        }
        $attrs = (string) $extra;
        if (function_exists('dash_layout_apply') && !dash_layout_apply($tone, $attrs, $dashKey)) {
            return;
        }
        echo '<a class="sas-box ' . e($tone) . '" href="' . e($href) . '"'
            . ($boxId !== '' ? (' id="' . e($boxId) . '"') : '')
            . ($attrs !== '' ? (' ' . $attrs) : '') . '>';
        echo '<div class="sas-box-title">' . e($title) . '</div>';
        if ($sub !== '' || $boxId !== '') {
            echo '<div class="sas-box-sub"' . ($boxId !== '' ? (' id="' . e($boxId) . 'Sub"') : '') . '>' . e($sub) . '</div>';
        }
        echo '<div class="sas-box-val"' . ($boxId !== '' ? (' id="' . e($boxId) . 'Val"') : '') . '>' . e($value) . '</div>';
        $icoHtml = function_exists('dash_widget_icon')
            ? dash_widget_icon($ico !== '' ? $ico : $dashKey)
            : e((string) $ico);
        echo '<span class="sas-box-ico" aria-hidden="true">' . $icoHtml . '</span>';
        echo '</a>';
    }
}

if (!function_exists('dash_ping_box')) {
    function dash_ping_box($rows, $en, $seedMs = '')
    {
        $rows = is_array($rows) ? array_values($rows) : array();
        $n = count($rows);
        $showN = $n > 4 ? 4 : $n;
        $multi = $n > 1 ? ' is-multi' : '';
        $pingTone = 'tone-navy';
        $pingAttrs = '';
        if (function_exists('dash_layout_apply') && !dash_layout_apply($pingTone, $pingAttrs, 'latency')) {
            return;
        }
        echo '<a class="sas-box ' . e($pingTone) . ' dash-ping' . $multi . '" id="dashPing" href="settings.php?tab=sas"'
            . ($pingAttrs !== '' ? (' ' . $pingAttrs) : '')
            . ' data-has="' . ($n > 0 ? '1' : '0') . '" data-count="' . (int) $n . '"'
            . ' data-empty="' . e($en ? 'Reseller' : 'الريسلر') . '"'
            . ' data-many="' . e($en ? 'Resellers' : 'الريسلرات') . '">';
        echo '<span class="sas-box-ico" aria-hidden="true">' . (function_exists('dash_widget_icon') ? dash_widget_icon('signal') : 'signal') . '</span>';
        if ($n <= 0) {
            echo '<div class="sas-box-title">' . e($en ? 'Reseller' : 'الريسلر') . '</div>';
            echo '<div class="sas-box-val" id="dashPingVal">-</div>';
        } elseif ($n === 1) {
            $name = isset($rows[0]['name']) ? (string) $rows[0]['name'] : '';
            $ms = $seedMs !== '' ? $seedMs : '…';
            echo '<div class="sas-box-title ping-name">' . e($name) . '</div>';
            echo '<div class="sas-box-val ping-ms" id="dashPingVal">' . e($ms) . '</div>';
        } else {
            echo '<div class="sas-box-title">' . e($en ? 'Resellers' : 'الريسلرات') . '</div>';
            echo '<div class="ping-list" id="dashPingVal">';
            for ($i = 0; $i < $showN; $i++) {
                $name = isset($rows[$i]['name']) ? (string) $rows[$i]['name'] : '';
                echo '<div class="ping-line"><b class="ping-name">' . e($name) . '</b>'
                    . '<span class="ping-ms">…</span></div>';
            }
            if ($n > 4) {
                echo '<div class="ping-more">+' . (int) ($n - 4) . '</div>';
            }
            echo '</div>';
        }
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
$isPlatformDash = function_exists('is_super_admin_user') && is_super_admin_user();
if ($dashTid > 1 && !$dashSasReady):
?>
<a class="alert alert-error" href="settings.php?tab=sas" style="display:block;margin:12px 14px;font-weight:700;text-decoration:none">
    <?php echo e($isEn
        ? 'Offline data. SAS is not linked — tap here to connect it, then sync.'
        : 'البيانات أوفلاين. الساس مو مربوط — اضغط هنا لربط الحساب ثم المزامنة.'); ?>
</a>
<?php
endif;

if ($isPlatformDash && function_exists('platform_admin_dashboard_stats')):
    $pad = platform_admin_dashboard_stats($pdo, $config);
    $fmtMs = function ($ms) {
        if ($ms === null || $ms === '') {
            return '—';
        }
        return number_format((float) $ms, 0) . ' ms';
    };
    $GLOBALS['dashLayoutMap'] = function_exists('dash_layout_map') ? dash_layout_map($pdo) : null;
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
@media (max-width: 900px) { .sas-dash .sas-boxes { grid-template-columns: 1fr; gap: 11px; } }
.sas-box {
  --c1: #0f766e; --c2: #115e59; --ink: #ffffff;
  position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
  color: var(--ink) !important; text-decoration: none !important; border-radius: 2px 20px 2px 16px;
  min-height: 112px; padding: 16px 16px 14px 18px;
  background: linear-gradient(145deg, var(--c1) 0%, var(--c2) 100%);
  box-shadow: 6px 7px 0 rgba(15, 23, 42, 0.12);
}
.sas-box-title { font-size: 13px; font-weight: 800; opacity: .95; z-index: 1; }
.sas-box-sub { font-size: 11px; font-weight: 600; opacity: .8; margin-top: 2px; z-index: 1; }
.sas-box-val { font-size: 28px; font-weight: 800; margin-top: 10px; z-index: 1; }
.sas-box-ico {
  position: absolute; inset-inline-end: 12px; inset-block-start: 10px;
  display: inline-flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; border-radius: 12px;
  background: rgba(255,255,255,.18); color: #fff; opacity: .95; z-index: 1;
}
.sas-box-ico .sas-ico-svg { display: block; width: 22px; height: 22px; }
body.theme-glass .sas-box-ico {
  background: rgba(15, 23, 42, 0.1) !important;
  color: #0f172a !important;
  opacity: 1 !important;
}
.sas-box.tone-blue { --c1: #38bdf8; --c2: #0369a1; }
.sas-box.tone-green { --c1: #4ade80; --c2: #15803d; }
.sas-box.tone-red { --c1: #fb7185; --c2: #be123c; }
.sas-box.tone-yellow { --c1: #fbbf24; --c2: #b45309; }
.sas-box.tone-teal { --c1: #2dd4bf; --c2: #0f766e; }
.sas-box.tone-navy { --c1: #64748b; --c2: #1e293b; }
.sas-box.tone-lime { --c1: #a3e635; --c2: #4d7c0f; }
.sas-box.tone-purple { --c1: #fb923c; --c2: #c2410c; }
.sas-box.tone-aqua { --c1: #22d3ee; --c2: #0e7490; }
</style>
<div class="sas-dash" style="padding:12px 14px">
    <h2 style="font-size:16px;margin:0 0 12px"><?php echo e($isEn ? 'Platform overview' : 'لوحة المنصة'); ?></h2>
    <div class="sas-boxes">
    <?php
    $en = $isEn;
    // وجدز البوابة فقط — بنك الشركات يبقى في صفحة الشركات
    dash_sas_box('saas_agents.php', 'tone-blue', $en ? 'Users' : 'عدد المستخدمين', '', (string) (int) $pad['users_total'], 'users', '', '', 'plat_users');
    dash_sas_box('saas_agents.php?view=pending', 'tone-yellow', $en ? 'Registration requests' : 'طلبات التسجيل', '', (string) (int) $pad['users_pending'], 'inbox', '', '', 'plat_pending');
    dash_sas_box('saas_agents.php?view=active', 'tone-green', $en ? 'Active' : 'الفعالين', '', (string) (int) $pad['users_active'], 'active', '', '', 'plat_active');
    dash_sas_box('saas_agents.php?view=expired', 'tone-red', $en ? 'Expired' : 'المنتهين', '', (string) (int) $pad['users_expired'], 'expired', '', '', 'plat_expired');
    dash_sas_box('settings.php', 'tone-navy', $en ? 'System version' : 'إصدار النظام', '', 'v' . (string) $pad['version'], 'version', '', '', 'version');
    dash_sas_box('settings.php', 'tone-lime', $en ? 'Google bank' : 'البنك على كوكل', $pad['google_ok'] ? ($en ? 'OK' : 'شغال') : ($en ? 'Down' : 'توقف'), $fmtMs($pad['google_ms']), 'globe', '', '', 'plat_google');
    $showResellerBank = true;
    if (isset($GLOBALS['dashLayoutMap']) && is_array($GLOBALS['dashLayoutMap'])
        && isset($GLOBALS['dashLayoutMap']['plat_reseller_bank'])
        && empty($GLOBALS['dashLayoutMap']['plat_reseller_bank']['on'])) {
        $showResellerBank = false;
    }
    if ($showResellerBank) {
        $coPings = function_exists('dash_platform_company_pings') ? dash_platform_company_pings(false) : array('rows' => array());
        $coRows = (is_array($coPings) && !empty($coPings['rows']) && is_array($coPings['rows'])) ? $coPings['rows'] : array();
        $coTones = array('tone-teal', 'tone-aqua', 'tone-blue', 'tone-green', 'tone-navy');
        $coi = 0;
        foreach ($coRows as $coRow) {
            $coId = isset($coRow['id']) ? (int) $coRow['id'] : 0;
            $coName = isset($coRow['name']) ? (string) $coRow['name'] : '';
            if ($coId <= 0 || $coName === '') {
                continue;
            }
            $coMs = (isset($coRow['ms']) && $coRow['ms'] !== null && $coRow['ms'] !== '') ? ((int) $coRow['ms'] . ' ms') : '—';
            $coTone = $coTones[$coi % 5];
            if (!empty($GLOBALS['dashLayoutMap']['plat_reseller_bank']['tone'])) {
                $coTone = (string) $GLOBALS['dashLayoutMap']['plat_reseller_bank']['tone'];
            }
            dash_sas_box('companies.php', $coTone, $coName, $en ? 'Reseller bank' : 'بنك الرسلر', $coMs, 'signal', 'dashCo' . $coId, '', '');
            $coi++;
        }
    }
    dash_sas_box('index.php', 'tone-teal', $en ? 'Date & time' : 'الوقت والتاريخ', '', (string) $pad['datetime'], 'clock', '', '', 'plat_datetime');
    dash_sas_box('saas_agents.php', 'tone-purple', $en ? 'Sales' : 'المبيعات', $en ? 'Portal membership' : 'عضوية البوابة', money_format_iqd($pad['sales'], $config['currency']), 'sales', '', '', 'plat_sales');
    dash_sas_box('saas_agents.php', 'tone-aqua', $en ? 'Received' : 'المستلم', '', money_format_iqd($pad['received'], $config['currency']), 'collected', '', '', 'plat_received');
    dash_sas_box('saas_agents.php', 'tone-red', $en ? 'Debt' : 'الدين', '', money_format_iqd($pad['debt'], $config['currency']), 'debts', '', '', 'plat_debt');
    ?>
    </div>
</div>
<?php if ($showResellerBank): ?>
<script>
(function () {
  var x = new XMLHttpRequest();
  x.open('GET', 'index.php?ajax=dash_company_pings', true);
  x.timeout = 20000;
  x.onload = function () {
    if (x.status !== 200) return;
    var data = null;
    try { data = JSON.parse(x.responseText); } catch (e) { return; }
    if (!data || !data.rows) return;
    for (var i = 0; i < data.rows.length; i++) {
      var row = data.rows[i];
      var el = document.getElementById('dashCo' + row.id + 'Val');
      if (!el) continue;
      el.textContent = (row.ms !== null && row.ms !== '') ? (row.ms + ' ms') : '—';
    }
  };
  x.send();
})();
</script>
<?php endif; ?>
<?php
    render_footer();
    return;
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
@media (max-width: 900px) {
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
  justify-content: flex-start;
  color: var(--ink) !important;
  text-decoration: none !important;
  font-family: inherit;
  border-radius: 2px 20px 2px 16px;
  min-height: 92px;
  padding: 14px 16px 12px 18px;
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
.sas-box-title { font-size: 13px; font-weight: 800; opacity: .95; z-index: 1; line-height: 1.25; }
.sas-box-sub { font-size: 11px; font-weight: 600; opacity: .8; margin-top: 2px; z-index: 1; min-height: 14px; line-height: 1.3; }
.sas-box-sub:empty { display: none; min-height: 0; }
.sas-box-val { font-size: 26px; font-weight: 800; margin-top: 6px; z-index: 1; letter-spacing: -0.02em; line-height: 1.1; }
.dash-ping[data-count="1"] .ping-name { max-width: calc(100% - 36px); font-size: 13px; font-weight: 800; letter-spacing: 0; opacity: .92; }
.dash-ping[data-count="1"] .ping-ms { margin-top: 10px; font-size: 34px; font-weight: 800; letter-spacing: -0.04em; line-height: 1; }
.dash-ping .ping-list { position: relative; z-index: 1; display: flex; flex-direction: column; gap: 0; width: 100%; margin-top: 8px; }
.dash-ping.is-multi .ping-line { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,.16); }
.dash-ping.is-multi .ping-line:last-of-type { border-bottom: 0; padding-bottom: 0; }
.dash-ping.is-multi .ping-name { font-size: 13px; font-weight: 800; min-width: 0; }
.dash-ping.is-multi .ping-ms { font-size: 15px; font-weight: 800; }
.dash-ping .ping-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dash-ping .ping-ms { direction: ltr; unicode-bidi: isolate; font-variant-numeric: tabular-nums; white-space: nowrap; }
.dash-ping .ping-more { font-size: 12px; font-weight: 800; opacity: .75; margin-top: 4px; }
@media (max-width: 640px) {
  .dash-ping[data-count="1"] .ping-ms { font-size: 30px; }
  .dash-ping.is-multi .ping-name { font-size: 12px; }
  .dash-ping.is-multi .ping-ms { font-size: 14px; }
}
@media (max-width: 760px) {
  .sas-dash .sas-boxes { display: flex; flex-direction: column; }
}
.sas-box-ico {
  position: absolute; inset-inline-end: 12px; inset-block-start: 10px;
  display: inline-flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; border-radius: 12px;
  background: rgba(255,255,255,.18); color: #fff; opacity: .95; z-index: 1;
}
.sas-box-ico .sas-ico-svg { display: block; width: 22px; height: 22px; }
body.theme-glass .sas-box-ico {
  background: rgba(15, 23, 42, 0.1) !important;
  color: #0f172a !important;
  opacity: 1 !important;
}
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
<?php if ($showCardAccountingDash && $cardDash && ($cardDashLedger || $cardDashPayments)): ?>
<div class="sas-dash">
    <h2 class="sas-sec"><?php echo e($isEn ? 'Card accounting' : 'محاسبة الكروت'); ?></h2>
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
</div>
<?php endif; ?>
<div class="sas-dash">
<?php
$GLOBALS['dashLayoutMap'] = function_exists('dash_layout_map') ? dash_layout_map($pdo) : null;
$dashFtth = function_exists('tenant_is_ftth') && tenant_is_ftth($pdo);
?>
<?php
$accWidList = null;
if (function_exists('is_accountant_user') && is_accountant_user() && function_exists('accountant_widgets_saved')) {
    $accWidList = accountant_widgets_saved($pdo);
}
$accLocked = is_array($accWidList);
$showWid = function ($id) use ($accLocked, $accWidList) {
    if (!$accLocked) {
        return true;
    }
    return in_array($id, $accWidList, true);
};
$isAccDash = function_exists('is_accountant_user') && is_accountant_user();
$en = ($lang === 'en');
if (!isset($dashBankName)) {
    $dashBankName = '';
}
if (!isset($dashBankHost)) {
    $dashBankHost = '';
}
$dashBankTitle = $dashBankName !== ''
    ? ($en ? ($dashBankName . ' ping') : ('بنك ' . $dashBankName))
    : ($en ? 'Reseller ping' : 'بنك الريسلر');
$dashBankSub = $dashBankHost !== ''
    ? $dashBankHost
    : ($en ? 'Reseller host' : 'هوست الريسلر');
if (!isset($dashBankInfo) || !is_array($dashBankInfo)) {
    $dashBankInfo = function_exists('system_reseller_bank')
        ? system_reseller_bank($config)
        : array('host' => '', 'name' => '');
    $dashBankHost = isset($dashBankInfo['host']) ? trim((string) $dashBankInfo['host']) : '';
    $dashBankName = isset($dashBankInfo['name']) ? trim((string) $dashBankInfo['name']) : '';
}
$dashPingRows = function_exists('dash_linked_resellers') ? dash_linked_resellers($config) : array();
$dashPingCount = count($dashPingRows);
$dashPingHost = '';
$dashPingNames = array();
foreach ($dashPingRows as $dashPingRow) {
    if ($dashPingHost === '' && !empty($dashPingRow['host'])) {
        $dashPingHost = (string) $dashPingRow['host'];
    }
    if (!empty($dashPingRow['name'])) {
        $dashPingNames[] = (string) $dashPingRow['name'];
    }
}
$dashPingSeed = '';
if ($dashPingCount === 1 && $dashPingHost !== '') {
    $cachedLatHost = isset($_SESSION['sas_latency_host']) ? trim((string) $_SESSION['sas_latency_host']) : '';
    $cachedLatMs = isset($_SESSION['sas_latency_ms']) ? $_SESSION['sas_latency_ms'] : null;
    if ($cachedLatHost !== '' && strcasecmp($cachedLatHost, $dashPingHost) === 0 && $cachedLatMs !== null && $cachedLatMs !== '') {
        $dashPingSeed = (int) round((float) $cachedLatMs) . ' ms';
    }
}
if ($isAccDash) {
    $full = function_exists('accountant_widget_catalog_full') ? accountant_widget_catalog_full() : array();
    $order = function_exists('accountant_widgets_effective') ? accountant_widgets_effective($pdo) : null;
    if (!is_array($order)) {
        $order = array_keys($full);
    }
    $pkgCount = array();
    if (!empty($sasCardGroups)) {
        foreach ($sasCardGroups as $cg) {
            $nm = isset($cg['name']) ? trim((string) $cg['name']) : '';
            if ($nm === '') {
                continue;
            }
            $pkgCount[$nm] = isset($cg['count']) ? (int) $cg['count'] : 0;
        }
    }
    $stkQty = 0;
    $stkHint = $en ? 'Cards left' : 'كروت متبقية';
    $xferQty = 0;
    $cardProfit = 0;
    $cardPaid = 0;
    $cardDue = 0;
    if (is_array($cardDash)) {
        $stk = isset($cardDash['stock']) && is_array($cardDash['stock']) ? $cardDash['stock'] : array();
        $stkQty = isset($stk['total_qty']) ? (int) $stk['total_qty'] : 0;
        if (!empty($stk['rows']) && is_array($stk['rows'])) {
            $bits = array();
            foreach ($stk['rows'] as $sr) {
                $bits[] = (isset($sr['profile_name']) ? $sr['profile_name'] : '') . ': ' . (int) (isset($sr['qty']) ? $sr['qty'] : 0);
            }
            if ($bits) {
                $stkHint = implode(' · ', array_slice($bits, 0, 3));
            }
        }
        $xfer = isset($cardDash['transfers']) && is_array($cardDash['transfers']) ? $cardDash['transfers'] : array();
        $xferQty = isset($xfer['qty']) ? (int) $xfer['qty'] : 0;
        $cardProfit = isset($cardDash['profit_total']) ? $cardDash['profit_total'] : 0;
        $cardPaid = isset($cardDash['payments_total']) ? $cardDash['payments_total'] : 0;
        $cardDue = isset($cardDash['remaining']) ? $cardDash['remaining'] : 0;
    }
    $agentN = 0;
    if (function_exists('user_boss_has_downline') && user_boss_has_downline($pdo) && function_exists('accountant_tree_ids')) {
        $agentN = count(accountant_tree_ids($pdo));
    }
    $cardTotal = 0;
    $cardParts = array();
    foreach ($pkgCount as $pnm => $pn) {
        if ($pn <= 0) {
            continue;
        }
        $cardTotal += $pn;
        $cardParts[] = trim($pnm . ' ' . $pn);
    }
    $cardSub = $cardParts ? implode(' · ', $cardParts) : ($en ? 'No free cards' : 'ماكو كروت شاغرة');
    $usersHome = 'sas.php';
    echo '<div class="sas-boxes">';
    foreach ($order as $wid) {
        $GLOBALS['dashLoopKey'] = (string) $wid;
        $wid = (string) $wid;
        if (strpos($wid, 'pkg:') === 0) {
            $pnm = rawurldecode(substr($wid, 4));
            $cn = isset($pkgCount[$pnm]) ? (int) $pkgCount[$pnm] : 0;
            $tone = isset($full[$wid]['tone']) ? $full[$wid]['tone'] : 'tone-navy';
            dash_sas_box('cards.php', $tone, $pnm, $en ? 'Available' : 'متوفر', (string) $cn, 'cards');
            continue;
        }
        if ($wid === 'subscribers') {
            dash_sas_box($usersHome, 'tone-blue', $en ? 'Subscribers' : 'المشتركين', '', (string) (int) $sasCounts['total'], 'users', 'dashUsers');
        } elseif ($wid === 'active') {
            dash_sas_box('sas.php?sub=active', 'tone-green', $en ? 'Active users' : 'فعال', '', (string) (int) $sasCounts['active'], 'active', 'dashActive');
        } elseif ($wid === 'online') {
            dash_sas_box('sas.php?sub=online', 'tone-aqua', $en ? 'Online users' : 'متصل حاليا', $en ? 'Connected' : '', (string) (int) $sasCounts['online'], 'online', 'dashOnline');
        } elseif ($wid === 'expired') {
            dash_sas_box('sas.php?sub=expired', 'tone-red', $en ? 'Expired users' : 'منتهي', '', (string) (int) $sasCounts['expired'], 'expired', 'dashExpired');
        } elseif ($wid === 'soon') {
            dash_sas_box('sas.php?sub=soon', 'tone-yellow', $en ? 'About to expire' : 'على وشك الانتهاء', $en ? 'In 3 days' : '', (string) (int) $sasCounts['soon'], 'soon', 'dashSoon');
        } elseif ($wid === 'today') {
            dash_sas_box('sas.php?sub=today', 'tone-teal', $en ? 'Expiring today' : 'ينتهي اليوم', '', (string) (int) $sasCounts['today'], 'today', 'dashToday');
        } elseif ($wid === 'agents' && $agentN > 0) {
            dash_sas_box('agents.php', 'tone-purple', $en ? 'Agents' : 'الوكلاء', '', (string) (int) $agentN, 'agents');
        } elseif ($wid === 'cards' && $sasReadyDash) {
            dash_sas_box('cards.php', 'tone-navy', $en ? 'Cards' : 'الكروت', $cardSub, (string) (int) $cardTotal, 'cards', 'dashCards');
        } elseif ($wid === 'stock') {
            dash_sas_box('cards.php', 'tone-navy', $en ? 'Remaining stock' : 'المخزون الشاغر', $stkHint, (string) (int) $stkQty, 'stock');
        } elseif ($wid === 'xfers') {
            dash_sas_box('cards.php#card-transfer', 'tone-purple', $en ? 'Transfers' : 'التحويلات', $en ? 'Received cards' : 'كروت مستلمة', (string) (int) $xferQty, 'transfer');
        } elseif ($wid === 'card_profit') {
            dash_sas_box('cards.php', 'tone-green', $en ? 'Card profit' : 'ربح الكروت', $en ? 'Wholesale vs agent' : 'جملة مقابل وكيل', money_format_iqd($cardProfit, $config['currency']), 'profit');
        } elseif ($wid === 'card_paid') {
            dash_sas_box('cards.php', 'tone-teal', $en ? 'Card payments' : 'دفعات الكروت', $en ? 'Payments' : 'دفعات', money_format_iqd($cardPaid, $config['currency']), 'collected');
        } elseif ($wid === 'card_due') {
            dash_sas_box('cards.php', 'tone-red', $en ? 'Remaining' : 'المتبقي', $en ? 'To collect' : 'باقي التحصيل', money_format_iqd($cardDue, $config['currency']), 'debts');
        } elseif ($wid === 'collected') {
            dash_sas_box('reports.php', 'tone-yellow', $en ? 'Collected' : 'المقبوض', '', money_format_iqd($receivedMonth, $config['currency']), 'collected', 'dashCollected');
        } elseif ($wid === 'debts') {
            dash_sas_box('debts.php?status=unpaid', 'tone-red', $en ? 'Debts' : 'الديون', '', money_format_iqd($totalDebt, $config['currency']), 'debts', 'dashDebts');
        } elseif ($wid === 'profit') {
            dash_sas_box('reports.php', 'tone-green', $en ? 'Profit' : 'الربح', '', money_format_iqd($profitMonth, $config['currency']), 'profit', 'dashProfit');
        } elseif ($wid === 'capital') {
            dash_sas_box('reports.php', 'tone-teal', $en ? 'Capital' : 'رأس المال', $en ? 'Profit + debts' : 'الربح + الديون', money_format_iqd($capitalMonth, $config['currency']), 'capital', 'dashCapital');
        } elseif ($wid === 'sales') {
            dash_sas_box('subscriptions.php', 'tone-purple', $en ? 'Sales' : 'المبيعات', '', money_format_iqd($salesMonth, $config['currency']), 'sales', 'dashSales');
        } elseif ($wid === 'activations') {
            dash_sas_box('subscriptions.php', 'tone-aqua', $en ? 'Activations' : 'تفعيلات الشهر', '', (string) (int) $activatedMonth, 'activations', 'dashActs');
        } elseif ($wid === 'rentals') {
            dash_sas_box('rentals.php', 'tone-navy', $en ? 'Rental towers' : 'أبراج الإيجار', $en ? 'Active of total' : 'فعال من أصل الكل', ((int) $rentalActiveCount) . '\\' . (int) $rentalTotalCount, 'rentals');
        } elseif ($wid === 'points' && $sasReadyDash) {
            dash_sas_box('sas.php', 'tone-lime', $en ? 'Reward points' : 'نقاط تشجيعية', '', (string) $sasPointsDisp, 'points', 'dashPoints');
        } elseif ($wid === 'latency' && $sasReadyDash) {
            dash_ping_box($dashPingRows, $en, $dashPingSeed);
        }
    }
    $GLOBALS['dashLoopKey'] = '';
    echo '</div>';
} else {
?>
<div class="sas-boxes">
<?php
$usersHome = 'sas.php';
if (!$accLocked || $showWid('subscribers')) {
    if ($accLocked) {
        dash_sas_box($usersHome, 'tone-blue', $en ? 'Subscribers' : 'المشتركين', '', (string) (int) $sasCounts['total'], 'users', '', '', 'subscribers');
    } else {
        dash_sas_box($usersHome, 'tone-blue', $en ? 'Total users' : 'كل المشتركين', $en ? 'Registered users' : 'مسجّلين', (string) (int) $sasCounts['total'], 'users', 'dashUsers', '', 'subscribers');
        dash_sas_box('sas.php?sub=active', 'tone-green', $en ? 'Active users' : 'فعال', $en ? 'Running line' : 'خط شغال', (string) (int) $sasCounts['active'], 'active', 'dashActive', '', 'active');
        dash_sas_box('sas.php?sub=online', 'tone-aqua', $en ? 'Online users' : 'متصل حاليا', $en ? 'Connected' : 'على الخط', (string) (int) $sasCounts['online'], 'online', 'dashOnline', '', 'online');
        dash_sas_box('sas.php?sub=expired', 'tone-red', $en ? 'Expired users' : 'منتهي', $en ? 'Ended' : 'انتهى الخط', (string) (int) $sasCounts['expired'], 'expired', 'dashExpired', '', 'expired');
        dash_sas_box('sas.php?sub=soon', 'tone-yellow', $en ? 'About to expire' : 'على وشك الانتهاء', $en ? 'In 3 days' : 'خلال 3 أيام', (string) (int) $sasCounts['soon'], 'soon', 'dashSoon', '', 'soon');
        dash_sas_box('sas.php?sub=today', 'tone-teal', $en ? 'Expiring today' : 'ينتهي اليوم', $en ? 'Today' : 'اليوم', (string) (int) $sasCounts['today'], 'today', 'dashToday', '', 'today');
    }
}
if ($isAccDash && $showWid('agents') && $accLocked) {
    $agentN = function_exists('accountant_tree_ids') ? count(accountant_tree_ids($pdo)) : 0;
    dash_sas_box('agents.php', 'tone-purple', $en ? 'Agents' : 'الوكلاء', '', (string) (int) $agentN, 'agents', '', '', 'agents');
}
if ($sasReadyDash && !$accLocked) {
    dash_sas_box('sas.php', 'tone-lime', $en ? 'Reward points' : 'نقاط تشجيعية', $en ? 'Balance' : 'الرصيد', (string) $sasPointsDisp, 'points', 'dashPoints', '', 'points');
}
if (!$accLocked) {
    dash_ping_box($dashPingRows, $en, $dashPingSeed);
}
if ($showWid('collected')) {
    dash_sas_box('reports.php', 'tone-yellow', $en ? 'Collected' : 'المقبوض', $en ? 'This month' : 'هذا الشهر', money_format_iqd($receivedMonth, $config['currency']), 'collected', 'dashCollected', '', 'collected');
}
if ($showWid('debts')) {
    dash_sas_box('debts.php?status=unpaid', 'tone-red', $en ? 'Debts' : 'الديون', $en ? 'Unpaid' : 'غير مدفوع', money_format_iqd($totalDebt, $config['currency']), 'debts', 'dashDebts', '', 'debts');
}
if ($showWid('profit')) {
    dash_sas_box('reports.php', 'tone-green', $en ? 'Profit' : 'الربح', $en ? 'This month' : 'هذا الشهر', money_format_iqd($profitMonth, $config['currency']), 'profit', 'dashProfit', '', 'profit');
    dash_sas_box('reports.php', 'tone-teal', $en ? 'Capital' : 'رأس المال', $en ? 'Profit + debts' : 'الربح + الديون', money_format_iqd($capitalMonth, $config['currency']), 'capital', 'dashCapital', '', 'capital');
}
if ($showWid('activations')) {
    dash_sas_box('subscriptions.php', 'tone-purple', $en ? 'Sales' : 'المبيعات', $en ? 'This month' : 'هذا الشهر', money_format_iqd($salesMonth, $config['currency']), 'sales', 'dashSales', '', 'sales');
    dash_sas_box('subscriptions.php', 'tone-aqua', $en ? 'Activations' : 'تفعيلات الشهر', $en ? 'This month' : 'هذا الشهر', (string) (int) $activatedMonth, 'activations', 'dashActs', '', 'activations');
}
if ($showWid('rentals')) {
    dash_sas_box('rentals.php', 'tone-navy', $en ? 'Rental towers' : 'أبراج الإيجار', $en ? 'Active of total' : 'فعال من أصل الكل', ((int) $rentalActiveCount) . '\\' . (int) $rentalTotalCount, 'rentals', '', '', 'rentals');
}
if (!empty($dashFtth)) {
    $cabN = 0;
    try {
        if (function_exists('ensure_cabinets_schema')) {
            ensure_cabinets_schema($pdo);
        }
        $cabTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
        if ($cabTid > 0) {
            $cabN = (int) $pdo->query('SELECT COUNT(*) FROM cabinets WHERE tenant_id = ' . $cabTid)->fetchColumn();
        }
    } catch (Exception $e) {
        $cabN = 0;
    }
    dash_sas_box('cabinets.php', 'tone-teal', $en ? 'Cabinets' : 'الكابينات', $en ? 'Sites' : 'مواقع', (string) $cabN, 'cabinets', 'dashCabs', '', 'cabinets');
}
if ($sasReadyDash && $showWid('cards')) {
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
    $cardSub = $cardParts ? implode(' · ', $cardParts) : ($en ? 'No free cards' : 'ماكو كروت شاغرة');
    dash_sas_box('cards.php', 'tone-navy', $en ? 'Cards' : 'الكروت', $cardSub, (string) (int) $cardTotal, 'cards', 'dashCards', '', 'cards');
}
if (!$accLocked) {
    dash_sas_box('settings.php', 'tone-purple', $en ? 'System version' : 'إصدار النظام', $en ? 'Portal' : 'البوابة', 'v' . $dashVersion, 'version', '', '', 'version');
    dash_sas_box('subscribers.php', 'tone-lime', $en ? 'Subscriber growth' : 'نمو المشتركين', $dashGrowthSub !== '' ? $dashGrowthSub : ($en ? 'This year' : 'هذي السنة'), $dashGrowth, 'growth', '', '', 'growth');
}
?>
</div>
<?php } ?>
</div>
<?php if ($sasReadyDash): ?>
<style>
#dashCards.is-loading #dashCardsVal,
#dashBank.is-loading #dashBankVal,
#dashPing.is-loading #dashPingVal {
  position: relative;
}
#dashCards.is-loading #dashCardsVal::after,
#dashBank.is-loading #dashBankVal::after,
#dashPing.is-loading #dashPingVal::after {
  content: "";
  position: absolute;
  inset-inline-start: 0;
  top: 50%;
  width: 22px;
  height: 22px;
  margin-top: -11px;
  border-radius: 50%;
  border: 3px solid rgba(15, 23, 42, 0.16);
  border-top-color: #0f172a;
  animation: dashCardSpin .7s linear infinite;
}
@keyframes dashCardSpin { to { transform: rotate(360deg); } }
</style>
<script>
(function () {
  function applyDash(d) {
    if (!d || !d.ok) return;
    var p = document.getElementById('dashPointsVal');
    if (p && d.points !== undefined && d.points !== null && d.points !== '' && d.points !== '—') p.textContent = d.points;
    var b = document.getElementById('dashBankVal');
    if (b && d.balance && d.balance !== '—') b.textContent = d.balance;
    var c = document.getElementById('dashCardsVal');
    if (c && typeof d.card_total === 'number') c.textContent = String(d.card_total);
    var cs = document.getElementById('dashCardsSub');
    if (cs && d.card_sub) cs.textContent = d.card_sub;
  }
  function loadDash(force, quiet) {
    var box = document.getElementById('dashCards');
    if (force && !quiet && box) box.classList.add('is-loading');
    var url = 'index.php?ajax=dash_sas' + (force ? '&refresh=1' : '');
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(applyDash)
      .catch(function () {})
      .then(function () {
        if (box) box.classList.remove('is-loading');
      });
  }
  function pingText(ms) {
    if (ms === null || ms === undefined || ms === '' || ms === 0) return '—';
    return String(ms) + ' ms';
  }
  function pingEsc(s) {
    return String(s || '').replace(/[&<>"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
    });
  }
  var dashIcoSignal = <?php echo json_encode('<span class="sas-box-ico" aria-hidden="true">' . (function_exists('dash_widget_icon') ? dash_widget_icon('signal') : '') . '</span>'); ?>;
  function paintPing(d) {
    var ping = document.getElementById('dashPing');
    if (!ping || !d) return;
    var rows = Array.isArray(d.rows) ? d.rows : [];
    var n = rows.length;
    var emptyTitle = ping.getAttribute('data-empty') || 'الريسلر';
    ping.setAttribute('data-count', String(n));
    ping.setAttribute('data-has', n ? '1' : '0');
    ping.classList.toggle('is-multi', n > 1);
    if (!n) {
      ping.innerHTML = dashIcoSignal
        + '<div class="sas-box-title">' + pingEsc(emptyTitle) + '</div>'
        + '<div class="sas-box-val" id="dashPingVal">-</div>';
      return;
    }
    var show = rows.slice(0, 4);
    var html = dashIcoSignal;
    if (n === 1) {
      html += '<div class="sas-box-title ping-name">' + pingEsc(show[0].name) + '</div>'
        + '<div class="sas-box-val ping-ms" id="dashPingVal">' + pingEsc(pingText(show[0].ms)) + '</div>';
    } else {
      var many = ping.getAttribute('data-many') || emptyTitle;
      html += '<div class="sas-box-title">' + pingEsc(many) + '</div><div class="ping-list" id="dashPingVal">';
      for (var i = 0; i < show.length; i++) {
        html += '<div class="ping-line"><b class="ping-name">' + pingEsc(show[i].name) + '</b>'
          + '<span class="ping-ms">' + pingEsc(pingText(show[i].ms)) + '</span></div>';
      }
      if (n > 4) html += '<div class="ping-more">+' + (n - 4) + '</div>';
      html += '</div>';
    }
    ping.innerHTML = html;
  }
  function loadBank() {
    var ping = document.getElementById('dashPing');
    var had = ping && ping.querySelector && ping.querySelector('.ping-ms')
      && String(ping.querySelector('.ping-ms').textContent || '').indexOf('ms') >= 0;
    if (ping && ping.getAttribute('data-has') === '1' && !had) ping.classList.add('is-loading');
    return fetch('index.php?ajax=dash_ping', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(paintPing)
      .catch(function () {})
      .then(function () {
        if (ping) ping.classList.remove('is-loading');
      });
  }
  function loadPoints() {
    return fetch('index.php?ajax=dash_points', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var p = document.getElementById('dashPointsVal');
        if (p && d && d.ok && d.points !== undefined && d.points !== null && d.points !== '') p.textContent = d.points;
      })
      .catch(function () {});
  }
  function paintStats(d) {
    if (!d || !d.ok) return;
    function setVal(id, value) {
      var el = document.getElementById(id);
      if (!el || value === undefined || value === null || value === '') return;
      el.textContent = String(value);
    }
    setVal('dashUsersVal', d.total);
    setVal('dashActiveVal', d.active);
    setVal('dashOnlineVal', d.online);
    setVal('dashExpiredVal', d.expired);
    setVal('dashSoonVal', d.soon);
    setVal('dashTodayVal', d.today);
    setVal('dashCollectedVal', d.collected);
    setVal('dashDebtsVal', d.debts);
    setVal('dashProfitVal', d.profit);
    setVal('dashCapitalVal', d.capital);
    setVal('dashSalesVal', d.sales);
    setVal('dashActsVal', d.activations);
    if (typeof d.card_total === 'number') setVal('dashCardsVal', d.card_total);
    if (d.card_sub) setVal('dashCardsSub', d.card_sub);
  }
  function loadStats() {
    return fetch('index.php?ajax=dash_stats', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(paintStats)
      .catch(function () {});
  }
  loadDash(false, true);
  loadStats();
  loadPoints();
  loadBank();
  setInterval(loadPoints, 2000);
  setInterval(loadStats, 2000);
  setInterval(function () { loadDash(false, true); loadBank(); }, 2000);
})();
</script>
<?php endif; ?>

<div class="panel twin-panel glass-panel panel-compact" id="twinLoom" data-year="<?php echo (int) $chartYear; ?>">
    <div class="twin-head">
        <div class="twin-title">
            <h2><?php echo e($lang === 'en' ? 'Year pulse' : 'نبض السنة'); ?> <?php echo (int) $chartYear; ?></h2>
            <div class="chart-sub"><?php echo e($lang === 'en'
                ? 'Profit and activations woven on one timeline — hover a month'
                : 'الأرباح والتفعيلات على خط واحد — مرّر على الشهر'); ?></div>
        </div>
        <div class="twin-totals">
            <div class="twin-tot is-profit">
                <span class="twin-tot-lab"><?php echo e($lang === 'en' ? 'Year profit' : 'أرباح السنة'); ?></span>
                <strong><?php echo e(money_format_iqd($yearProfitTotal, $config['currency'])); ?></strong>
            </div>
            <div class="twin-tot is-acts">
                <span class="twin-tot-lab"><?php echo e($lang === 'en' ? 'Year activations' : 'تفعيلات السنة'); ?></span>
                <strong><?php echo e(number_format((int) $yearActsTotal)); ?></strong>
            </div>
        </div>
    </div>
    <div class="twin-legend" aria-hidden="true">
        <span class="twin-leg is-profit"><i></i><?php echo e($lang === 'en' ? 'Profit' : 'الأرباح'); ?></span>
        <span class="twin-leg is-acts"><i></i><?php echo e($lang === 'en' ? 'Activations' : 'التفعيلات'); ?></span>
    </div>
    <div class="twin-stage">
        <svg class="twin-svg" id="twinSvg" viewBox="0 0 1200 340" preserveAspectRatio="none" role="img" aria-label="<?php echo e($lang === 'en' ? 'Profit and activations chart' : 'مخطط الأرباح والتفعيلات'); ?>"></svg>
        <div class="twin-tip" id="twinTip" hidden>
            <b id="twinTipMonth"></b>
            <div class="twin-tip-row is-profit"><span><?php echo e($lang === 'en' ? 'Profit' : 'الأرباح'); ?></span><strong id="twinTipProfit"></strong></div>
            <div class="twin-tip-row is-acts"><span><?php echo e($lang === 'en' ? 'Activations' : 'التفعيلات'); ?></span><strong id="twinTipActs"></strong></div>
        </div>
    </div>
    <div class="twin-months" id="twinMonths"></div>
</div>
<script>
(function () {
  var raw = <?php echo $twinChartJson; ?>;
  if (!raw || !raw.months) return;
  var svg = document.getElementById('twinSvg');
  var tip = document.getElementById('twinTip');
  var tipM = document.getElementById('twinTipMonth');
  var tipP = document.getElementById('twinTipProfit');
  var tipA = document.getElementById('twinTipActs');
  var monthsBox = document.getElementById('twinMonths');
  var stage = svg && svg.parentNode;
  if (!svg || !stage || !monthsBox) return;

  var W = 1200, H = 340, padL = 28, padR = 28, padT = 28, padB = 36;
  var n = raw.months.length;
  var profit = raw.profit || [];
  var acts = raw.acts || [];
  var maxP = 0, maxA = 0, i;
  for (i = 0; i < n; i++) {
    if ((+profit[i] || 0) > maxP) maxP = +profit[i];
    if ((+acts[i] || 0) > maxA) maxA = +acts[i];
  }
  if (maxP <= 0) maxP = 1;
  if (maxA <= 0) maxA = 1;

  function xAt(i) {
    if (n <= 1) return W / 2;
    return padL + (i * (W - padL - padR) / (n - 1));
  }
  function yP(v) {
    return padT + (1 - (Math.max(0, +v || 0) / maxP)) * (H - padT - padB);
  }
  function yA(v) {
    return padT + (1 - (Math.max(0, +v || 0) / maxA)) * (H - padT - padB);
  }
  function smooth(pts) {
    if (!pts.length) return '';
    if (pts.length === 1) return 'M' + pts[0][0] + ',' + pts[0][1];
    var d = 'M' + pts[0][0] + ',' + pts[0][1];
    for (var j = 0; j < pts.length - 1; j++) {
      var p0 = pts[j === 0 ? j : j - 1];
      var p1 = pts[j];
      var p2 = pts[j + 1];
      var p3 = pts[j + 2] || p2;
      var cp1x = p1[0] + (p2[0] - p0[0]) / 6;
      var cp1y = p1[1] + (p2[1] - p0[1]) / 6;
      var cp2x = p2[0] - (p3[0] - p1[0]) / 6;
      var cp2y = p2[1] - (p3[1] - p1[1]) / 6;
      d += 'C' + cp1x + ',' + cp1y + ',' + cp2x + ',' + cp2y + ',' + p2[0] + ',' + p2[1];
    }
    return d;
  }

  var ptsP = [], ptsA = [];
  for (i = 0; i < n; i++) {
    ptsP.push([xAt(i), yP(profit[i])]);
    ptsA.push([xAt(i), yA(acts[i])]);
  }
  var baseY = H - padB;
  var lineP = smooth(ptsP);
  var lineA = smooth(ptsA);
  var areaP = lineP + 'L' + ptsP[ptsP.length - 1][0] + ',' + baseY + 'L' + ptsP[0][0] + ',' + baseY + 'Z';

  var ns = 'http://www.w3.org/2000/svg';
  function el(name, attrs) {
    var nEl = document.createElementNS(ns, name);
    for (var k in attrs) if (attrs.hasOwnProperty(k)) nEl.setAttribute(k, attrs[k]);
    return nEl;
  }
  while (svg.firstChild) svg.removeChild(svg.firstChild);

  var defs = el('defs', {});
  var gP = el('linearGradient', { id: 'twinGradP', x1: '0', y1: '0', x2: '0', y2: '1' });
  gP.appendChild(el('stop', { offset: '0%', 'stop-color': '#f59e0b', 'stop-opacity': '0.38' }));
  gP.appendChild(el('stop', { offset: '100%', 'stop-color': '#f59e0b', 'stop-opacity': '0.02' }));
  var gA = el('linearGradient', { id: 'twinGradA', x1: '0', y1: '0', x2: '1', y2: '0' });
  gA.appendChild(el('stop', { offset: '0%', 'stop-color': '#0d9488', 'stop-opacity': '0.95' }));
  gA.appendChild(el('stop', { offset: '100%', 'stop-color': '#38bdf8', 'stop-opacity': '0.95' }));
  defs.appendChild(gP);
  defs.appendChild(gA);
  svg.appendChild(defs);

  for (i = 0; i < 4; i++) {
    var gy = padT + i * ((H - padT - padB) / 3);
    svg.appendChild(el('line', { x1: padL, x2: W - padR, y1: gy, y2: gy, class: 'twin-grid' }));
  }
  if (raw.cur >= 0 && raw.cur < n) {
    var cx = xAt(raw.cur);
    svg.appendChild(el('rect', {
      x: cx - 28, y: padT - 8, width: 56, height: H - padT - padB + 16,
      class: 'twin-now'
    }));
  }

  svg.appendChild(el('path', { d: areaP, class: 'twin-area-p', fill: 'url(#twinGradP)' }));
  svg.appendChild(el('path', { d: lineP, class: 'twin-line-p twin-draw' }));
  svg.appendChild(el('path', { d: lineA, class: 'twin-line-a twin-draw', stroke: 'url(#twinGradA)' }));

  var hit = el('g', { class: 'twin-hits' });
  for (i = 0; i < n; i++) {
    hit.appendChild(el('circle', {
      cx: ptsP[i][0], cy: ptsP[i][1], r: 5.5, class: 'twin-dot-p' + (i === raw.cur ? ' is-cur' : '')
    }));
    var rA = 6 + Math.min(14, (maxA > 0 ? ((+acts[i] || 0) / maxA) * 14 : 0));
    hit.appendChild(el('circle', {
      cx: ptsA[i][0], cy: ptsA[i][1], r: rA, class: 'twin-bead-a' + (i === raw.cur ? ' is-cur' : '')
    }));
    hit.appendChild(el('circle', {
      cx: ptsA[i][0], cy: ptsA[i][1], r: Math.max(3, rA * 0.35), class: 'twin-bead-core'
    }));
    var band = el('rect', {
      x: xAt(i) - ((W - padL - padR) / Math.max(1, n - 1)) / 2,
      y: 0, width: (W - padL - padR) / Math.max(1, n - 1), height: H,
      class: 'twin-hit', 'data-i': String(i), fill: 'transparent'
    });
    hit.appendChild(band);
  }
  svg.appendChild(hit);

  monthsBox.innerHTML = '';
  for (i = 0; i < n; i++) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'twin-m' + (i === raw.cur ? ' is-cur' : '');
    b.setAttribute('data-i', String(i));
    b.textContent = raw.months[i];
    monthsBox.appendChild(b);
  }

  function show(i, clientX) {
    if (i < 0 || i >= n) return;
    tip.hidden = false;
    tipM.textContent = raw.months[i] + ' · ' + (raw.year || '');
    tipP.textContent = (raw.profitFmt && raw.profitFmt[i]) ? raw.profitFmt[i] : String(profit[i] || 0);
    tipA.textContent = String(acts[i] || 0);
    var rect = stage.getBoundingClientRect();
    var left = clientX - rect.left;
    if (left < 90) left = 90;
    if (left > rect.width - 90) left = rect.width - 90;
    tip.style.left = left + 'px';
    tip.style.top = '18px';
    var ms = monthsBox.querySelectorAll('.twin-m');
    for (var j = 0; j < ms.length; j++) ms[j].classList.toggle('is-on', j === i);
  }
  function hide() {
    tip.hidden = true;
    var ms = monthsBox.querySelectorAll('.twin-m');
    for (var j = 0; j < ms.length; j++) ms[j].classList.remove('is-on');
  }
  svg.addEventListener('pointermove', function (e) {
    var t = e.target;
    var idx = t && t.getAttribute ? t.getAttribute('data-i') : null;
    if (idx === null) return;
    show(parseInt(idx, 10), e.clientX);
  });
  svg.addEventListener('pointerleave', hide);
  monthsBox.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-i]') : null;
    if (!b) return;
    var idx = parseInt(b.getAttribute('data-i'), 10);
    var r = b.getBoundingClientRect();
    show(idx, r.left + r.width / 2);
  });
})();
</script>
<?php if (empty($sasReadyDash)): ?>
<script>
(function () {
  var ping = document.getElementById('dashPing');
  if (!ping || ping.getAttribute('data-has') !== '1') return;
  function pingText(ms) {
    if (ms === null || ms === undefined || ms === '' || ms === 0) return '—';
    return String(ms) + ' ms';
  }
  function pingEsc(s) {
    return String(s || '').replace(/[&<>"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
    });
  }
  var dashIcoSignal = <?php echo json_encode('<span class="sas-box-ico" aria-hidden="true">' . (function_exists('dash_widget_icon') ? dash_widget_icon('signal') : '') . '</span>'); ?>;
  function load() {
    var had = ping.querySelector('.ping-ms') && String(ping.querySelector('.ping-ms').textContent || '').indexOf('ms') >= 0;
    if (!had) ping.classList.add('is-loading');
    return fetch('index.php?ajax=dash_ping', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !Array.isArray(d.rows)) return;
        var rows = d.rows;
        var n = rows.length;
        ping.setAttribute('data-count', String(n));
        ping.setAttribute('data-has', n ? '1' : '0');
        ping.classList.toggle('is-multi', n > 1);
        var emptyTitle = ping.getAttribute('data-empty') || 'الريسلر';
        if (!n) {
          ping.innerHTML = dashIcoSignal
            + '<div class="sas-box-title">' + pingEsc(emptyTitle) + '</div><div class="sas-box-val" id="dashPingVal">-</div>';
          return;
        }
        var show = rows.slice(0, 4);
        var html = dashIcoSignal;
        if (n === 1) {
          html += '<div class="sas-box-title ping-name">' + pingEsc(show[0].name) + '</div>'
            + '<div class="sas-box-val ping-ms" id="dashPingVal">' + pingEsc(pingText(show[0].ms)) + '</div>';
        } else {
          var many = ping.getAttribute('data-many') || emptyTitle;
          html += '<div class="sas-box-title">' + pingEsc(many) + '</div><div class="ping-list" id="dashPingVal">';
          for (var i = 0; i < show.length; i++) {
            html += '<div class="ping-line"><b class="ping-name">' + pingEsc(show[i].name) + '</b>'
              + '<span class="ping-ms">' + pingEsc(pingText(show[i].ms)) + '</span></div>';
          }
          if (n > 4) html += '<div class="ping-more">+' + (n - 4) + '</div>';
          html += '</div>';
        }
        ping.innerHTML = html;
      })
      .catch(function () {})
      .then(function () { ping.classList.remove('is-loading'); });
  }
  load();
  setInterval(load, 180000);
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
