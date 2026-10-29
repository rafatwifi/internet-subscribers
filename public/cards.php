<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'GET' && function_exists('app_session_close')) {
    app_session_close();
}
if (function_exists('account_viewer_is_leaf_child') && account_viewer_is_leaf_child($pdo)) {
    redirect('index.php');
}
if (!user_can('cards') && !user_can('card_accounting')) {
    require_perm('cards');
}
ensure_card_accounting_tables($pdo);

$isEn = ($lang === 'en');
$me = current_admin();
$meId = $me ? (int) $me['id'] : 0;
$accLevel = (function_exists('is_accountant_user') && is_accountant_user() && function_exists('accountant_scope_level'))
    ? accountant_scope_level($pdo) : '';
$canTransfer = function_exists('user_may_transfer_cards') && user_may_transfer_cards($pdo);
$xferPerAllowed = array(10, 30, 50, 100);
$xferPer = isset($_REQUEST['xfer_n']) ? (int) $_REQUEST['xfer_n'] : 10;
if (!in_array($xferPer, $xferPerAllowed, true)) {
    $xferPer = 10;
}
$xferBack = 'cards.php?xfer_n=' . $xferPer . '#card-transfer';
$sasReady = function_exists('sas_is_ready') && sas_is_ready($config);
// وكيل البوابة أو الدخول بوكيل ساس بدون عضوية: مخزونه المحوّل فقط، مو مستودع الوكالة
$cardsOwnStockOnly = !empty($_SESSION['admin_sas_shadow'])
    || (function_exists('is_agent_user') && is_agent_user());
if ($cardsOwnStockOnly) {
    $sasReady = false;
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'inventory') {
    header('Content-Type: application/json; charset=utf-8');
    $force = (isset($_GET['refresh']) && $_GET['refresh'] === '1');
    $out = array('ok' => true, 'groups' => array(), 'from_cache' => false, 'error' => '');
    if (!$sasReady) {
        echo json_encode($out);
        exit;
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(90);
    }
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    try {
        list($groups, $fromCache) = cards_page_fetch_inventory($config, $force);
        $out['groups'] = cards_page_with_all_categories(
            $pdo,
            cards_filter_groups_scope($pdo, cards_enrich_used_by_links($pdo, $groups))
        );
        $out['from_cache'] = $fromCache;
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $isEn ? 'Failed to load cards' : 'تعذر جلب الكروت';
    }
    echo json_encode($out);
    exit;
}
$agents = list_agent_users($pdo, false);
$tidCards = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$agents = array_values(array_filter($agents, function ($a) use ($tidCards) {
    $at = isset($a['tenant_id']) ? (int) $a['tenant_id'] : 1;
    return $at === $tidCards;
}));
$childAgencyIds = array();
try {
    $gmCards = $pdo->prepare(
        'SELECT id, username, display_name, role, is_active, tenant_id
         FROM admin_users WHERE role = "group_manager" AND tenant_id = :t
         ORDER BY display_name ASC'
    );
    $gmCards->execute(array(':t' => $tidCards));
    $haveAg = array();
    foreach ($agents as $ag0) {
        $haveAg[(int) $ag0['id']] = true;
    }
    foreach ($gmCards->fetchAll() as $gm0) {
        if (empty($haveAg[(int) $gm0['id']])) {
            $agents[] = $gm0;
            $haveAg[(int) $gm0['id']] = true;
        }
    }
    if (function_exists('portal_agencies_under_current')) {
        foreach (portal_agencies_under_current($pdo, '') as $childAg) {
            $cid = (int) $childAg['id'];
            if ($cid <= 0 || !empty($haveAg[$cid])) {
                continue;
            }
            $agents[] = $childAg;
            $haveAg[$cid] = true;
            $childAgencyIds[$cid] = true;
        }
    }
} catch (Exception $e) {
}

$homeId = function_exists('user_card_source_id') ? (int) user_card_source_id($pdo) : $meId;
$homeRow = ($homeId > 0 && function_exists('get_admin_user')) ? get_admin_user($pdo, $homeId) : null;
if (!$homeRow && function_exists('accountant_boss_row')) {
    $homeRow = accountant_boss_row($pdo);
}
$homeLabel = $homeRow
    ? (trim((string) $homeRow['display_name']) !== '' ? $homeRow['display_name'] : (string) $homeRow['username'])
    : ($me && !empty($me['display_name']) ? (string) $me['display_name'] : '');
$treeIds = (function_exists('is_accountant_user') && is_accountant_user() && function_exists('accountant_tree_ids'))
    ? accountant_tree_ids($pdo) : array();
$gmTeam = (function_exists('is_group_manager_user') && is_group_manager_user() && function_exists('group_manager_team_ids'))
    ? group_manager_team_ids($pdo) : array();
$homeUserKey = '';
$homeNameKey = '';
if ($homeRow) {
    $homeUserKey = strtolower(trim((string) $homeRow['username']));
    $homeNameKey = strtolower(trim((string) (isset($homeRow['display_name']) ? $homeRow['display_name'] : '')));
}
if ($homeNameKey === '' && $homeLabel !== '') {
    $homeNameKey = strtolower(trim($homeLabel));
}
$xferTargets = array();
$xferSeen = array();
foreach ($agents as $ag) {
    $aid = (int) $ag['id'];
    if ($aid <= 0 || $aid === $homeId || $aid === $meId || !empty($xferSeen[$aid])) {
        continue;
    }
    $agUser = strtolower(trim((string) (isset($ag['username']) ? $ag['username'] : '')));
    $agName = strtolower(trim((string) (isset($ag['display_name']) ? $ag['display_name'] : '')));
    if ($homeUserKey !== '' && ($agUser === $homeUserKey || $agName === $homeUserKey)) {
        continue;
    }
    if ($homeNameKey !== '' && ($agUser === $homeNameKey || $agName === $homeNameKey)) {
        continue;
    }
    if ($treeIds && !in_array($aid, $treeIds, true)) {
        continue;
    }
    if ($gmTeam && !in_array($aid, $gmTeam, true)) {
        continue;
    }
    $roleAg = isset($ag['role']) ? normalize_admin_role($ag['role']) : 'agent';
    $isChildAgency = !empty($childAgencyIds[$aid]);
    if (!$isChildAgency && !in_array($roleAg, array('agent', 'group_manager'), true)) {
        continue;
    }
    $xferSeen[$aid] = true;
    $xferTargets[] = $ag;
}
usort($xferTargets, function ($a, $b) {
    $an = isset($a['display_name']) ? (string) $a['display_name'] : '';
    $bn = isset($b['display_name']) ? (string) $b['display_name'] : '';
    return strcasecmp($an, $bn);
});
$xferTargetIds = array();
foreach ($xferTargets as $ag) {
    $xferTargetIds[(int) $ag['id']] = true;
}
$pageParentId = 0;
$pageParentRow = null;
if (function_exists('account_user_boss_id')) {
    $pageParentId = (int) account_user_boss_id($pdo, $meId);
    if (($pageParentId <= 0 || $pageParentId === (int) $homeId) && (int) $homeId > 0 && (int) $homeId !== $meId) {
        $pageParentId = (int) account_user_boss_id($pdo, (int) $homeId);
    }
}
if ($pageParentId === $meId || $pageParentId === (int) $homeId) {
    $pageParentId = 0;
}
if ($pageParentId > 0 && function_exists('get_admin_user')) {
    $pageParentRow = get_admin_user($pdo, $pageParentId);
}
if (!$pageParentRow) {
    $pageParentId = 0;
}
$cardsHasDown = false;
if (function_exists('account_has_card_downline')) {
    $cardsHasDown = account_has_card_downline($pdo, $meId);
    if (!$cardsHasDown && (int) $homeId > 0 && (int) $homeId !== $meId) {
        $cardsHasDown = account_has_card_downline($pdo, (int) $homeId);
    }
}
$pageParentLabel = '';
if ($pageParentRow) {
    $pageParentLabel = trim((string) $pageParentRow['display_name']);
    if ($pageParentLabel === '') {
        $pageParentLabel = (string) $pageParentRow['username'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canTransfer) {
    $ajaxXfer = post('ajax_xfer') === '1';
    if (!verify_csrf(post('csrf'))) {
        if ($ajaxXfer) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('ok' => false, 'message' => $isEn ? 'Invalid request' : 'طلب غير صالح'));
            exit;
        }
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('cards.php');
    }
    $action = post('action');
        if ($action === 'return_transfer') {
            $tidRet = (int) post('transfer_id', '0');
            $begun = function_exists('card_transfer_begin_return')
                ? card_transfer_begin_return($pdo, $tidRet, $homeId, $meId)
                : array(false, 'not_returnable', 0);
            if (empty($begun[0]) || empty($begun[2])) {
                flash('error', card_transfer_error_message(isset($begun[1]) ? $begun[1] : '', $lang));
                redirect($xferBack);
            }
            redirect($xferBack);
        }
        if ($action === 'recover_missing_cards') {
            $tidRec = (int) post('transfer_id', '0');
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }
            $rec = function_exists('card_transfer_recover_prefix')
                ? card_transfer_recover_prefix($pdo, $tidRec, $homeId, $config)
                : array(false, 'sas_not_ready');
            if (!empty($rec[0])) {
                if (function_exists('activity_log')) {
                    activity_log($pdo, 0, 'card_return', $tidRec, 'card_transfer', 'إرجاع كروت ناقصة من سلسلة الوكيل إلى ' . $homeLabel, '');
                }
                flash('success', $isEn ? 'The missing cards were returned from the agent series' : 'رجعت الكروت الناقصة من سلسلة الوكيل إلى وكالتك');
            } else {
                if (function_exists('card_sas_stock_forget')) {
                    card_sas_stock_forget();
                }
                flash('error', card_transfer_error_message(isset($rec[1]) ? $rec[1] : '', $lang));
            }
            redirect('cards.php#card-transfer');
        }
        if ($action === 'clear_transfer_history') {
            $tidClear = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
            $byClear = (function_exists('is_accountant_user') && is_accountant_user()) ? $meId : null;
            if (function_exists('card_transfer_history_clear')) {
                card_transfer_history_clear($pdo, $tidClear, $byClear);
            }
            flash('success', $isEn ? 'Transfer history cleared. Cards in SAS were not moved.' : 'انمسح سجل التحويل. الكروت بالساس ما انمسّت.');
            redirect('cards.php#card-transfer');
        }
    if ($action === 'transfer') {
        $xferAbort = function ($msg) use ($ajaxXfer, $xferBack) {
            if ($ajaxXfer) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('ok' => false, 'message' => $msg));
                exit;
            }
            flash('error', $msg);
            redirect($xferBack);
        };
        $fromAgentId = (int) post('from_agent_id', '0');
        if ($fromAgentId <= 0) {
            $fromAgentId = $homeId;
        }
        $toAgentId = (int) post('to_agent_id', '0');
        $fromOk = ($fromAgentId === $homeId) || !empty($xferTargetIds[$fromAgentId]);
        if (!$fromOk) {
            $xferAbort($isEn ? 'Choose a valid source' : 'اختر مصدر تحويل صحيح');
        }
        if ($fromAgentId === $homeId) {
            $okTo = !empty($xferTargetIds[$toAgentId]) || ($pageParentId > 0 && $toAgentId === $pageParentId);
        } else {
            $upId = $homeId;
            foreach ($xferTargets as $agUp) {
                if ((int) $agUp['id'] !== $fromAgentId) {
                    continue;
                }
                $upId = isset($agUp['reports_to_user_id']) ? (int) $agUp['reports_to_user_id'] : 0;
                if ($upId <= 0) {
                    $upId = $homeId;
                }
                break;
            }
            $okTo = ($toAgentId === $homeId || $toAgentId === $upId || ($pageParentId > 0 && $toAgentId === $pageParentId));
        }
        $toRow = function_exists('get_admin_user') ? get_admin_user($pdo, $toAgentId) : null;
        if ($fromAgentId !== $homeId && $toAgentId === $homeId && !$toRow && $homeRow) {
            $toRow = $homeRow;
        }
        if (!$okTo || !$toRow || $toAgentId === $fromAgentId) {
            $xferAbort($isEn ? 'Select an agent under you' : 'اختر وكيلاً من الشجرة اللي تحتك');
        }
        $profileId = (int) post('profile_id', '0');
        $profileName = trim((string) post('profile_name', ''));
        $qty = (int) post('qty', '0');
        $note = trim((string) post('note', ''));
        if ($profileName === '' || $qty < 1) {
            $xferAbort($isEn ? 'Choose a category and a quantity' : 'اختر الفئة والعدد');
        }
        $availQty = cards_transfer_available_qty($pdo, $config, $homeId, $fromAgentId, $profileName, $xferTargets, false);
        if ($availQty === null) {
            $xferAbort($isEn
                ? 'The available count is not confirmed, so the transfer was not accepted'
                : 'ما تأكد عدد الكروت المتوفرة، ما انقبل التحويل');
        }
        if ($qty > $availQty) {
            $xferAbort($isEn
                ? 'Quantity is above the available cards, so the transfer was not accepted'
                : 'العدد أكبر من المتوفّر (' . (int) $availQty . ')، ما انقبل التحويل');
        }
        $wholesale = 0;
        $agentPrice = 0;
        $priceAgentId = ($fromAgentId === $homeId) ? $toAgentId : $fromAgentId;
        if (function_exists('agent_card_price_get') && $priceAgentId > 0 && $profileName !== '') {
            $pr = agent_card_price_get($pdo, $priceAgentId, $profileId, $profileName);
            if ($pr) {
                $wholesale = (float) $pr['wholesale_price'];
                $agentPrice = (float) $pr['agent_price'];
            }
            if ($wholesale <= 0 && $agentPrice <= 0 && function_exists('agent_card_prices_list')) {
                $wantName = strtolower($profileName);
                $wantPid = $profileId;
                $planNames = array();
                $planPids = array();
                if ($wantName !== '') {
                    $planNames[$wantName] = true;
                }
                if ($wantPid > 0) {
                    $planPids[$wantPid] = true;
                }
                try {
                    $stPl = $pdo->query('SELECT name, sas_profile_id FROM service_plans WHERE is_active = 1');
                    foreach ($stPl->fetchAll() as $pl) {
                        $pn = strtolower(trim((string) $pl['name']));
                        $pp = isset($pl['sas_profile_id']) ? (int) $pl['sas_profile_id'] : 0;
                        $hit = ($wantName !== '' && $pn === $wantName) || ($wantPid > 0 && $pp > 0 && $pp === $wantPid);
                        if (!$hit) {
                            continue;
                        }
                        if ($pn !== '') {
                            $planNames[$pn] = true;
                        }
                        if ($pp > 0) {
                            $planPids[$pp] = true;
                        }
                    }
                } catch (Exception $e) {
                }
                $best = null;
                $bestScore = 0;
                foreach (agent_card_prices_list($pdo, $priceAgentId) as $row) {
                    $rn = strtolower(trim((string) $row['profile_name']));
                    $rp = isset($row['profile_id']) ? (int) $row['profile_id'] : 0;
                    $score = 0;
                    if ($wantName !== '' && $rn === $wantName) {
                        $score = 3;
                    } elseif ($rn !== '' && !empty($planNames[$rn])) {
                        $score = 2;
                    } elseif ($rp > 0 && !empty($planPids[$rp])) {
                        $score = 1;
                    }
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $row;
                    }
                }
                if ($best) {
                    $wholesale = (float) $best['wholesale_price'];
                    $agentPrice = (float) $best['agent_price'];
                }
            }
        }
        if ($wholesale <= 0 && $agentPrice <= 0 && $fromAgentId !== $homeId && function_exists('card_agent_stock_summary')) {
            $fromStock = card_agent_stock_summary($pdo, $fromAgentId);
            if (!empty($fromStock['rows'])) {
                foreach ($fromStock['rows'] as $sr) {
                    if ((string) $sr['profile_name'] !== $profileName && !($profileId > 0 && (int) $sr['profile_id'] === $profileId)) {
                        continue;
                    }
                    $wholesale = isset($sr['wholesale_price']) ? (float) $sr['wholesale_price'] : 0;
                    $agentPrice = isset($sr['agent_price']) ? (float) $sr['agent_price'] : 0;
                    break;
                }
            }
        }
        $sendingUp = ($pageParentId > 0 && $toAgentId === $pageParentId);
        $setPrice = 0.0;
        if (function_exists('account_package_rates') && $priceAgentId > 0) {
            $rates = account_package_rates($pdo, $priceAgentId, $profileName, $profileId);
            if (!empty($rates['found'])) {
                $setPrice = (float) $rates['cost'];
            }
        }
        if ($setPrice > 0) {
            $agentPrice = $setPrice;
        } elseif ($fromAgentId === $homeId && !$sendingUp) {
            $agentPrice = 0;
        } elseif ($fromAgentId !== $homeId) {
            $agentPrice = 0;
        }
        if ($fromAgentId === $homeId && $agentPrice <= 0 && !$sendingUp) {
            $xferAbort($isEn ? 'Set this agent package price first' : 'لازم الأدمن يسعّر هالفئة لهذا الوكيل أولاً');
        }

        if (!function_exists('sas_is_ready') || !sas_is_ready($config)) {
            $xferAbort(card_transfer_error_message('sas_not_ready', $lang));
        }
        $pendingId = function_exists('card_transfer_begin')
            ? card_transfer_begin($pdo, $fromAgentId, $toAgentId, $profileId, $profileName, $qty, $wholesale, $agentPrice, $note, $meId, 0)
            : 0;
        if ($pendingId <= 0) {
            $xferAbort($isEn ? 'Could not save the transfer' : 'ما انسجل التحويل');
        }
        if (post('notify_parent') === '1') {
            $flagDir = dirname(__DIR__) . '/storage/cache';
            if (!is_dir($flagDir)) {
                @mkdir($flagDir, 0775, true);
            }
            $extraNote = trim((string) post('notice_extra'));
            if (function_exists('mb_substr')) {
                $extraNote = mb_substr($extraNote, 0, 400, 'UTF-8');
            } elseif (strlen($extraNote) > 400) {
                $extraNote = substr($extraNote, 0, 400);
            }
            @file_put_contents($flagDir . '/xfer_notify_' . (int) $pendingId . '.flag', $extraNote);
        }
        if ($ajaxXfer) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('ok' => true, 'id' => (int) $pendingId));
            exit;
        }
        redirect($xferBack);
    }
}

function cards_owner_move_connector($pdo, $fromAgentId, $fromMid)
{
    $fromAgentId = (int) $fromAgentId;
    $fromMid = (int) $fromMid;
    if ($fromAgentId <= 0 || $fromMid <= 0 || !function_exists('sas_make_connector_from_account') || !function_exists('get_admin_user')) {
        return null;
    }
    $user = get_admin_user($pdo, $fromAgentId);
    if (!$user) {
        return null;
    }
    $tenantId = isset($user['tenant_id']) ? (int) $user['tenant_id'] : 0;
    if ($tenantId <= 0 || !function_exists('tenant_sas_accounts_ready')) {
        return null;
    }
    $want = array();
    if (!empty($user['username'])) {
        $want[strtolower(trim((string) $user['username']))] = true;
    }
    if (!empty($user['display_name'])) {
        $want[strtolower(trim((string) $user['display_name']))] = true;
    }
    $accounts = tenant_sas_accounts_ready($pdo, $tenantId);
    if (!$accounts) {
        return null;
    }
    $ordered = array();
    $rest = array();
    foreach ($accounts as $acc) {
        $uname = strtolower(trim((string) (isset($acc['sas_username']) ? $acc['sas_username'] : '')));
        if ($uname !== '' && isset($want[$uname])) {
            $ordered[] = $acc;
        } else {
            $rest[] = $acc;
        }
    }
    $ordered = array_merge($ordered, $rest);
    $tried = 0;
    foreach ($ordered as $acc) {
        if ($tried >= 4) {
            break;
        }
        $tried++;
        $api = sas_make_connector_from_account($acc);
        if (!$api || !method_exists($api, 'loggedManagerId') || !method_exists($api, 'moveUnusedCardsToOwner')) {
            continue;
        }
        if (method_exists($api, 'setReclaimAccount')) {
            $api->setReclaimAccount('', '');
        }
        if (method_exists($api, 'setTimeout')) {
            $api->setTimeout(40);
        }
        if ((int) $api->loggedManagerId() === $fromMid) {
            return $api;
        }
    }
    return null;
}

function cards_execute_pending_transfer($pdo, $config, $pendingId, $homeId, $lang, $isEn, $pageParentId, $homeRow, $homeLabel)
{
    $pendingId = (int) $pendingId;
    $homeId = (int) $homeId;
    if ($pendingId <= 0 || $homeId <= 0) {
        return array('ok' => false, 'state' => 'failed');
    }
    $lockDir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0775, true);
    }
    $lockFh = @fopen($lockDir . '/xfer_run_' . $pendingId . '.lock', 'c');
    if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) {
        if ($lockFh) {
            fclose($lockFh);
        }
        return array('ok' => true, 'state' => 'pending', 'busy' => 1);
    }
    $st = $pdo->prepare('SELECT * FROM agent_card_transfers WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => $pendingId));
    $row = $st->fetch();
    if (!$row) {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
        return array('ok' => false, 'state' => 'failed');
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
    if ($tid > 0 && isset($row['tenant_id']) && (int) $row['tenant_id'] !== $tid) {
        $partyIds = array();
        if (!empty($row['from_agent_id'])) {
            $partyIds[] = (int) $row['from_agent_id'];
        }
        if (!empty($row['to_agent_id'])) {
            $partyIds[] = (int) $row['to_agent_id'];
        }
        $ownsParty = false;
        if ($partyIds) {
            $in = implode(',', $partyIds);
            $ownsParty = (int) $pdo->query(
                'SELECT COUNT(*) FROM admin_users WHERE tenant_id = ' . (int) $tid . ' AND id IN (' . $in . ')'
            )->fetchColumn() > 0;
        }
        if (!$ownsParty) {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
            return array('ok' => false, 'state' => 'failed');
        }
    }
    $state = isset($row['xfer_state']) ? (string) $row['xfer_state'] : 'done';
    if ($state !== 'pending') {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
        return array('ok' => true, 'state' => ($state === 'failed' ? 'failed' : 'done'));
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }
    if (!empty($row['return_of_id'])) {
        $ret = function_exists('card_transfer_finish_return')
            ? card_transfer_finish_return($pdo, $pendingId, $homeId, $config)
            : array(false, 'sas_not_ready');
        if (!empty($ret[0]) && function_exists('activity_log')) {
            activity_log(
                $pdo,
                0,
                'card_return',
                isset($ret[4]) ? (int) $ret[4] : 0,
                'card_transfer',
                'استرجاع ' . (int) (isset($ret[3]) ? $ret[3] : 0) . ' — ' . (isset($ret[2]) ? $ret[2] : '') . ' إلى ' . $homeLabel,
                ''
            );
        }
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
        return array('ok' => !empty($ret[0]), 'state' => !empty($ret[0]) ? 'done' : 'failed');
    }
    $fromAgentId = isset($row['from_agent_id']) ? (int) $row['from_agent_id'] : 0;
    $toAgentId = (int) $row['to_agent_id'];
    $profileId = isset($row['profile_id']) ? (int) $row['profile_id'] : 0;
    $profileName = isset($row['profile_name']) ? (string) $row['profile_name'] : '';
    $qty = (int) $row['qty'];
    $wholesale = isset($row['wholesale_price']) ? (float) $row['wholesale_price'] : 0;
    $agentPrice = isset($row['agent_price']) ? (float) $row['agent_price'] : 0;
    $note = isset($row['note']) ? (string) $row['note'] : '';
    $toRow = function_exists('get_admin_user') ? get_admin_user($pdo, $toAgentId) : null;
    $fromRowLog = ($fromAgentId === $homeId) ? $homeRow : (function_exists('get_admin_user') ? get_admin_user($pdo, $fromAgentId) : null);
    $toName = $toRow && !empty($toRow['display_name']) ? $toRow['display_name'] : ('#' . $toAgentId);
    $fromName = $fromRowLog && !empty($fromRowLog['display_name']) ? $fromRowLog['display_name'] : ('#' . $fromAgentId);
    $xferTitle = (int) $qty . ' — ' . $profileName . ' من ' . $fromName . ' إلى ' . $toName;
    $fail = function ($msg) use ($pdo, $pendingId, $toAgentId, $xferTitle, $lockFh) {
        if (function_exists('card_sas_stock_forget')) {
            card_sas_stock_forget();
        }
        if (function_exists('card_transfer_fail')) {
            card_transfer_fail($pdo, $pendingId, $msg);
        }
        if (function_exists('activity_log')) {
            activity_log($pdo, 0, 'card_transfer', $toAgentId, 'card_transfer_fail', 'فشل تحويل ' . $xferTitle, $msg);
        }
        $flag = dirname(__DIR__) . '/storage/cache/xfer_notify_' . (int) $pendingId . '.flag';
        if (is_file($flag)) {
            @unlink($flag);
        }
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
        return array('ok' => false, 'state' => 'failed');
    };
    if (!function_exists('sas_make_connector') || !function_exists('sas_is_ready') || !sas_is_ready($config)) {
        return $fail(function_exists('card_transfer_error_message') ? card_transfer_error_message('sas_not_ready', $lang) : 'الساس مو جاهز');
    }
    $apiMove = sas_make_connector($config);
    if (!$apiMove || !method_exists($apiMove, 'moveUnusedCardsToOwner')) {
        return $fail(function_exists('card_transfer_error_message') ? card_transfer_error_message('sas_not_ready', $lang) : 'الساس مو جاهز');
    }
    if (method_exists($apiMove, 'setTimeout')) {
        $apiMove->setTimeout(15);
    }
    $fromMid = card_user_sas_manager_id($pdo, $fromAgentId, $homeId, $apiMove);
    $toMid = card_user_sas_manager_id($pdo, $toAgentId, $homeId, $apiMove);
    if ($toAgentId === $homeId && $toMid <= 0) {
        return $fail(function_exists('card_transfer_error_message') ? card_transfer_error_message('sas_home_missing', $lang) : 'ما لقى وكالتك بالساس');
    }
    if (($fromAgentId !== $homeId && $fromMid <= 0) || ($toAgentId !== $homeId && $toMid <= 0)) {
        return $fail(function_exists('card_transfer_error_message') ? card_transfer_error_message('sas_manager_missing', $lang) : 'ما لقى الوكيل بالساس');
    }
    if ($fromMid > 0 && $toMid > 0 && $fromMid === $toMid) {
        return $fail(function_exists('card_transfer_error_message') ? card_transfer_error_message('sas_same_owner', $lang) : 'المصدر والوجهة نفس المالك');
    }
    $foreignOwners = array();
    if ($fromAgentId === $homeId) {
        $foreignOwners = card_child_sas_manager_ids($pdo, $homeId);
        if (!$foreignOwners) {
            $foreignOwners = array(-1);
        }
    }
    $pageMid = method_exists($apiMove, 'loggedManagerId') ? (int) $apiMove->loggedManagerId() : 0;
    $pullingHome = ($toMid > 0 && $pageMid > 0 && $toMid === $pageMid);
    if ($fromMid > 0 && $pageMid !== $fromMid && !$pullingHome) {
        $ownerApi = cards_owner_move_connector($pdo, $fromAgentId, $fromMid);
        if ($ownerApi) {
            $apiMove = $ownerApi;
        }
    }
    if (method_exists($apiMove, 'setReclaimAccount')) {
        $apiMove->setReclaimAccount('', '');
    }
    $move = $apiMove->moveUnusedCardsToOwner($profileId, $profileName, $qty, $toMid, $fromMid, $foreignOwners);
    if (empty($move['ok'])) {
        $failMsg = function_exists('card_sas_move_error') ? card_sas_move_error($move, $lang) : 'فشل التحويل';
        return $fail($failMsg);
    }
    list($ok, $code) = function_exists('card_transfer_finish')
        ? card_transfer_finish($pdo, $pendingId, isset($move['ranges']) ? $move['ranges'] : array())
        : array(false, 'stock_update_failed');
    if (!$ok && method_exists($apiMove, 'restoreCardRanges')) {
        $apiMove->restoreCardRanges(isset($move['ranges']) ? $move['ranges'] : array(), $fromMid);
    }
    if (!$ok) {
        $failMsg = function_exists('card_transfer_error_message') ? card_transfer_error_message($code, $lang) : (string) $code;
        return $fail($failMsg . "\nتمت محاولة إرجاع الكروت للمصدر.");
    }
    if (function_exists('card_sas_stock_forget')) {
        card_sas_stock_forget();
    }
    $flag = dirname(__DIR__) . '/storage/cache/xfer_notify_' . (int) $pendingId . '.flag';
    $notifyOn = is_file($flag);
    $extraNote = '';
    if ($notifyOn) {
        $extraNote = trim((string) @file_get_contents($flag));
        if ($extraNote === '1') {
            $extraNote = '';
        }
        @unlink($flag);
    }
    $waNote = '';
    if ($notifyOn) {
        $noticeAgent = 0;
        $noticeKind = '';
        if ((int) $fromAgentId === (int) $homeId && (int) $toAgentId > 0 && (int) $toAgentId !== (int) $homeId) {
            $noticeAgent = (int) $toAgentId;
            $noticeKind = 'transfer';
        } elseif ((int) $fromAgentId > 0 && (int) $fromAgentId !== (int) $homeId && (int) $toAgentId === (int) $homeId) {
            $noticeAgent = (int) $fromAgentId;
            $noticeKind = 'pull';
        }
        $unitNote = 0.0;
        if ($noticeAgent > 0 && function_exists('account_package_rates')) {
            $ratesNote = account_package_rates($pdo, $noticeAgent, $profileName, $profileId);
            if (!empty($ratesNote['found'])) {
                $unitNote = (float) $ratesNote['cost'];
            }
        }
        $noticeQty = (int) $qty;
        if (!empty($move['ranges']) && is_array($move['ranges'])) {
            $span = 0;
            foreach ($move['ranges'] as $rg) {
                if (!is_array($rg)) {
                    continue;
                }
                $a = isset($rg['from_id']) ? (int) $rg['from_id'] : 0;
                $b = isset($rg['to_id']) ? (int) $rg['to_id'] : 0;
                if ($a > 0 && $b >= $a) {
                    $span += ($b - $a + 1);
                }
            }
            if ($span > $noticeQty) {
                $noticeQty = $span;
            }
        }
        if ($noticeAgent > 0 && $unitNote > 0 && function_exists('whatsapp_notify_agent_card')) {
            $waCode = whatsapp_notify_agent_card(
                $pdo,
                $config,
                (int) $homeId,
                $noticeAgent,
                $profileName,
                $noticeQty,
                $unitNote,
                $noticeKind,
                $extraNote
            );
            if ($waCode !== 'sent') {
                $waNote = $isEn ? ' The transfer is saved. The WhatsApp notice was not sent.' : ' التحويل تم. إشعار الواتساب ما انرسل.';
            }
        } elseif ($noticeAgent > 0) {
            $waNote = $isEn ? ' The transfer is saved. The agent price is not set, so no notice was sent.' : ' التحويل تم. سعر الوكيل مو مسعّر، فما انرسل إشعار.';
        }
    }
    if (function_exists('activity_log')) {
        $doneDetails = (string) $note;
        if ($waNote !== '') {
            $doneDetails .= ($doneDetails !== '' ? "\n" : '') . trim($waNote);
        }
        activity_log($pdo, 0, 'card_transfer', $toAgentId, 'card_transfer', 'تم تحويل ' . $xferTitle, $doneDetails);
    }
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    return array('ok' => true, 'state' => 'done');
}

$scopeAgentId = null;
if (!empty($_SESSION['admin_sas_shadow'])) {
    $scopeAgentId = 0;
} elseif (function_exists('is_agent_user') && is_agent_user()) {
    $scopeAgentId = $meId;
} elseif (is_accountant_user()) {
    $linked = accountant_linked_agent_id();
    if ($linked > 0) {
        $scopeAgentId = $linked;
    }
}
$xferActor = (function_exists('is_accountant_user') && is_accountant_user()) ? $meId : null;
if (isset($_GET['ajax']) && $_GET['ajax'] === 'xfer_rows') {
    header('Content-Type: application/json; charset=utf-8');
    $want = array();
    $rawIds = isset($_GET['ids']) ? (string) $_GET['ids'] : '';
    foreach (explode(',', $rawIds) as $oneId) {
        $oneId = (int) $oneId;
        if ($oneId > 0) {
            $want[$oneId] = $oneId;
        }
        if (count($want) >= 120) {
            break;
        }
    }
    $items = array();
    if ($want && function_exists('list_card_transfers_by_ids')) {
        $got = list_card_transfers_by_ids($pdo, array_values($want), $xferActor);
        $idList = array();
        foreach ($got as $trGot) {
            $idList[] = (int) $trGot['id'];
        }
        list($doneMap, $pendMap) = function_exists('card_transfer_return_flags')
            ? card_transfer_return_flags($pdo, $idList)
            : array(array(), array());
        foreach ($got as $trGot) {
            $items[] = array(
                'id' => (int) $trGot['id'],
                'state' => cards_xfer_state($trGot),
                'when' => isset($trGot['created_at']) ? (string) $trGot['created_at'] : '',
                'note' => isset($trGot['note']) ? (string) $trGot['note'] : '',
                'action' => cards_xfer_action_html($trGot, $canTransfer, $homeId, $isEn, $doneMap, $pendMap, $xferPer),
            );
        }
    }
    echo json_encode(array('ok' => true, 'rows' => $items));
    exit;
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'xfer_run') {
    header('Content-Type: application/json; charset=utf-8');
    $runId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if (!$canTransfer || $runId <= 0) {
        echo json_encode(array('ok' => false, 'state' => 'failed'));
        exit;
    }
    if ($xferActor) {
        try {
            $stOwn = $pdo->prepare('SELECT created_by, tenant_id FROM agent_card_transfers WHERE id = :id LIMIT 1');
            $stOwn->execute(array(':id' => $runId));
            $own = $stOwn->fetch();
            $ownBy = $own ? (int) $own['created_by'] : 0;
            if ($ownBy !== (int) $xferActor) {
                echo json_encode(array('ok' => false, 'state' => 'failed'));
                exit;
            }
        } catch (Exception $eOwn) {
            echo json_encode(array('ok' => false, 'state' => 'failed'));
            exit;
        }
    }
    $out = cards_execute_pending_transfer($pdo, $config, $runId, $homeId, $lang, $isEn, $pageParentId, $homeRow, $homeLabel);
    echo json_encode($out);
    exit;
}
$xferPage = isset($_GET['xfer_p']) ? (int) $_GET['xfer_p'] : 1;
if ($xferPage < 1) {
    $xferPage = 1;
}
$xferTotal = function_exists('count_card_transfers') ? count_card_transfers($pdo, null, $xferActor) : 0;
$xferPages = $xferPer > 0 ? (int) ceil($xferTotal / $xferPer) : 1;
if ($xferPages < 1) {
    $xferPages = 1;
}
if ($xferPage > $xferPages) {
    $xferPage = $xferPages;
}
$recentTransfers = list_recent_card_transfers($pdo, $xferPer, null, $xferActor, ($xferPage - 1) * $xferPer);
$agentStockPanel = null;
if ($scopeAgentId > 0 && function_exists('card_agent_dashboard')) {
    $agentStockPanel = card_agent_dashboard($pdo, $scopeAgentId);
} elseif ($scopeAgentId > 0 && function_exists('card_agent_stock_summary')) {
    $agentStockPanel = array('stock' => card_agent_stock_summary($pdo, $scopeAgentId));
}

function cards_xfer_state($tr)
{
    $state = (is_array($tr) && isset($tr['xfer_state'])) ? trim((string) $tr['xfer_state']) : '';
    if ($state !== 'pending' && $state !== 'failed') {
        return 'done';
    }
    return $state;
}

function cards_xfer_spinner_html($isEn = false)
{
    $label = $isEn ? 'Transferring' : 'جاري التحويل';
    return '<span class="xfer-live" aria-hidden="true"></span><span class="xfer-wait">' . e($label) . '</span>';
}

function cards_xfer_action_html($tr, $canTransfer, $homeId, $isEn, $returnedIds, $pendingReturnIds, $xferPer)
{
    $state = cards_xfer_state($tr);
    if ($state === 'pending') {
        return cards_xfer_spinner_html($isEn);
    }
    $isReturn = !empty($tr['return_of_id']);
    $id = isset($tr['id']) ? (int) $tr['id'] : 0;
    if ($isReturn || !empty($returnedIds[$id])) {
        return '<span class="meta">' . e($isEn ? 'Returned' : 'مسترجع') . '</span>';
    }
    if ($state === 'done') {
        return '<span class="xfer-ok">' . e($isEn ? 'Done' : 'تم') . '</span>';
    }
    return '';
}

function cards_page_fetch_inventory($config, $force = false)
{
    $groups = array();
    if (!$force && function_exists('sas_cards_inventory_load_persisted')) {
        $cached = sas_cards_inventory_load_persisted(0);
        if ($cached && !empty($cached['groups'])) {
            return array($cached['groups'], true);
        }
    }
    if (!function_exists('sas_page_connector')) {
        return array($groups, false);
    }
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    $api = sas_page_connector($config);
    if (!$api) {
        return array($groups, false);
    }
    if (method_exists($api, 'setTimeout')) {
        $api->setTimeout(35);
    }
    if (method_exists($api, 'listCardsInventory')) {
        $groups = $api->listCardsInventory(14);
    } elseif (function_exists('sas_unused_cards_grouped')) {
        $raw = sas_unused_cards_grouped($api);
        foreach ($raw as $g) {
            $groups[] = array(
                'name' => isset($g['name']) ? $g['name'] : '',
                'profile_id' => isset($g['profile_id']) ? (int) $g['profile_id'] : 0,
                'total' => isset($g['count']) ? (int) $g['count'] : 0,
                'used' => 0,
                'unused' => isset($g['count']) ? (int) $g['count'] : 0,
                'cards' => array(),
            );
        }
    }
    if (!is_array($groups)) {
        $groups = array();
    }
    if ($api && function_exists('sas_inventory_ensure_free_pins')) {
        $groups = sas_inventory_ensure_free_pins($api, $groups);
    }
    if (function_exists('sas_cards_inventory_save_persisted')) {
        sas_cards_inventory_save_persisted($groups);
    }
    if (function_exists('sas_dash_groups_from_inventory') && function_exists('sas_store_dash_card_groups')) {
        sas_store_dash_card_groups(sas_dash_groups_from_inventory($groups), 'inventory');
    }
    // حدّث كاش نافذة التفعيل من نفس الجرد
    if (function_exists('sas_unused_pins_from_inventory_cache')) {
        $pins = sas_unused_pins_from_inventory_cache();
        if (is_array($pins)) {
            $_SESSION['sas_unused_ui_v6'] = $pins;
            $_SESSION['sas_unused_ui_v6_at'] = time();
            if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_touch')) {
                app_session_touch();
            }
        }
    }
    return array($groups, false);
}

/**
 * اربط used_by بصفحة المشترك المحلي (#activations) أو sas_user كاحتياط.
 */
function cards_enrich_used_by_links($pdo, $groups)
{
    if (!is_array($groups) || !$groups) {
        return $groups;
    }
    $names = array();
    foreach ($groups as $g) {
        if (empty($g['cards']) || !is_array($g['cards'])) {
            continue;
        }
        foreach ($g['cards'] as $c) {
            if (empty($c['used']) || empty($c['used_by'])) {
                continue;
            }
            $u = trim((string) $c['used_by']);
            if ($u !== '') {
                $names[strtolower($u)] = $u;
            }
        }
    }
    if (!$names) {
        return $groups;
    }
    $map = array(); // lower => local_id
    $list = array_values($names);
    // sas_users_cache.local_subscriber_id
    try {
        $ph = array();
        $bind = array();
        $i = 0;
        foreach ($list as $u) {
            $k = ':u' . $i;
            $ph[] = $k;
            $bind[$k] = $u;
            $i++;
        }
        $sql = 'SELECT username, local_subscriber_id FROM sas_users_cache
                WHERE username IN (' . implode(',', $ph) . ') AND tenant_id = ' . (int) (function_exists('current_tenant_id') ? current_tenant_id() : 1);
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        while ($row = $st->fetch()) {
            $lid = !empty($row['local_subscriber_id']) ? (int) $row['local_subscriber_id'] : 0;
            if ($lid > 0) {
                $map[strtolower((string) $row['username'])] = $lid;
            }
        }
    } catch (Exception $e) {
    }
    // subscribers.sas_username
    $missing = array();
    foreach ($list as $u) {
        if (!isset($map[strtolower($u)])) {
            $missing[] = $u;
        }
    }
    if ($missing) {
        try {
            $ph = array();
            $bind = array();
            $i = 0;
            foreach ($missing as $u) {
                $k = ':s' . $i;
                $ph[] = $k;
                $bind[$k] = $u;
                $i++;
            }
            $sql = 'SELECT id, sas_username FROM subscribers
                    WHERE sas_username IN (' . implode(',', $ph) . ')
                      AND tenant_id = ' . (int) (function_exists('current_tenant_id') ? current_tenant_id() : 1);
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            while ($row = $st->fetch()) {
                $map[strtolower((string) $row['sas_username'])] = (int) $row['id'];
            }
        } catch (Exception $e) {
        }
    }
    foreach ($groups as &$g) {
        if (empty($g['cards']) || !is_array($g['cards'])) {
            continue;
        }
        foreach ($g['cards'] as &$c) {
            if (empty($c['used']) || empty($c['used_by'])) {
                continue;
            }
            $u = trim((string) $c['used_by']);
            $key = strtolower($u);
            if (isset($map[$key]) && $map[$key] > 0) {
                $c['local_id'] = (int) $map[$key];
                $c['user_href'] = 'subscriber.php?id=' . (int) $map[$key] . '#activations';
            } elseif (function_exists('sas_user_url')) {
                $c['local_id'] = 0;
                $c['user_href'] = sas_user_url($u);
            } else {
                $c['local_id'] = 0;
                $c['user_href'] = 'sas.php?q=' . rawurlencode($u);
            }
        }
        unset($c);
    }
    unset($g);
    return $groups;
}

/**
 * صفّ الكروت المستخدمة: فقط يوزرات ضمن نطاق الشركة/الوكيل الحالي
 */
function cards_filter_groups_scope($pdo, $groups)
{
    if (!is_array($groups) || !$groups) {
        return $groups;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $allowed = array();
    try {
        $sql = 'SELECT username FROM sas_users_cache WHERE tenant_id = :t';
        $params = array(':t' => $tid);
        if (function_exists('is_agent_user') && is_agent_user()) {
            $mid = function_exists('current_admin_sas_manager_id') ? (int) current_admin_sas_manager_id() : 0;
            if ($mid <= 0) {
                foreach ($groups as &$g0) {
                    if (empty($g0['cards']) || !is_array($g0['cards'])) {
                        continue;
                    }
            $g0['cards'] = array_values(array_filter($g0['cards'], function ($c) {
                return empty($c['used']);
            }));
            cards_keep_series_count($g0);
        }
        unset($g0);
        return $groups;
    }
    $sql .= ' AND parent_id = :p';
            $params[':p'] = $mid;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch()) {
            $allowed[strtolower(trim((string) $r['username']))] = true;
        }
    } catch (Exception $e) {
        // فشل الفلترة = لا تُظهر كروت مستخدمة أجنبية
        foreach ($groups as &$g0) {
            if (empty($g0['cards']) || !is_array($g0['cards'])) {
                continue;
            }
            $g0['cards'] = array_values(array_filter($g0['cards'], function ($c) {
                return empty($c['used']);
            }));
            cards_keep_series_count($g0);
        }
        unset($g0);
        return $groups;
    }
    foreach ($groups as &$g) {
        if (empty($g['cards']) || !is_array($g['cards'])) {
            continue;
        }
        $keep = array();
        $usedN = 0;
        $unusedN = 0;
        foreach ($g['cards'] as $c) {
            if (empty($c['used'])) {
                $keep[] = $c;
                $unusedN++;
                continue;
            }
            $by = isset($c['used_by']) ? strtolower(trim((string) $c['used_by'])) : '';
            if ($by !== '' && isset($allowed[$by])) {
                $keep[] = $c;
                $usedN++;
            }
        }
        $g['cards'] = $keep;
        cards_keep_series_count($g, $unusedN, $usedN);
    }
    unset($g);
    return $groups;
}

/** عدد السلسلة يبقى كما هو. عيّنة الأرقام لا تستبدل الشاغر. */
function cards_keep_series_count(&$g, $sampleFree = null, $sampleUsed = null)
{
    $seriesFree = isset($g['unused']) ? (int) $g['unused'] : 0;
    $seriesUsed = isset($g['used']) ? (int) $g['used'] : 0;
    $cards = (isset($g['cards']) && is_array($g['cards'])) ? $g['cards'] : array();
    if ($sampleFree === null) {
        $sampleFree = count($cards);
    }
    $sampleFree = (int) $sampleFree;
    $sampleUsed = (int) $sampleUsed;
    if (!empty($g['counts_only']) || $seriesFree > $sampleFree) {
        $g['unused'] = $seriesFree;
        $g['used'] = $seriesUsed;
        $g['total'] = $seriesFree + $seriesUsed;
        return;
    }
    $g['unused'] = $sampleFree;
    $g['used'] = $sampleUsed;
    $g['total'] = $sampleFree + $sampleUsed;
}

function cards_page_xfer_stock($homeId, $groups, $xferTargets, $sasMap)
{
    $xferStock = array();
    $alignBag = function ($nameQty) use ($groups) {
        $bag = array();
        $keys = array();
        if (is_array($groups)) {
            foreach ($groups as $g0) {
                $gn = isset($g0['name']) ? (string) $g0['name'] : '';
                if ($gn === '') {
                    continue;
                }
                $bag[$gn] = 0;
                $soft = function_exists('card_price_soft_key') ? card_price_soft_key($gn) : '';
                if ($soft !== '') {
                    $keys[$soft] = $gn;
                }
            }
        }
        if (!is_array($nameQty)) {
            $nameQty = array();
        }
        foreach ($nameQty as $nm => $qtyBag) {
            $nm = (string) $nm;
            $k = function_exists('card_price_soft_key') ? card_price_soft_key($nm) : strtolower(trim($nm));
            if ($k !== '' && isset($keys[$k])) {
                $bag[$keys[$k]] += (int) $qtyBag;
            } elseif (trim($nm) !== '') {
                if (!isset($bag[$nm])) {
                    $bag[$nm] = 0;
                }
                $bag[$nm] += (int) $qtyBag;
            }
        }
        return $bag;
    };
    $homeNames = array();
    if (!empty($groups)) {
        foreach ($groups as $g0) {
            $gn = isset($g0['name']) ? (string) $g0['name'] : '';
            if ($gn === '') {
                continue;
            }
            $homeNames[$gn] = isset($g0['unused']) ? (int) $g0['unused'] : 0;
        }
    }
    $xferStock[(string) $homeId] = $homeNames;
    if (is_array($xferTargets) && is_array($sasMap)) {
        global $pdo;
        foreach ($xferTargets as $ag) {
            $aid = (int) $ag['id'];
            if ($aid <= 0) {
                continue;
            }
            $raw = (isset($sasMap[$aid]) && is_array($sasMap[$aid])) ? $sasMap[$aid] : array();
            if (function_exists('card_portal_admin_unused_bag')) {
                $ownBag = card_portal_admin_unused_bag($pdo, $aid, (int) $homeId);
                if (is_array($ownBag)) {
                    $raw = function_exists('card_bag_take_higher') ? card_bag_take_higher($raw, $ownBag) : $ownBag;
                }
            }
            if (!is_array($raw) || !$raw) {
                continue;
            }
            $xferStock[(string) $aid] = $alignBag($raw);
        }
    }
    return $xferStock;
}

function cards_stock_qty($bag, $profileName)
{
    if (!is_array($bag)) {
        return null;
    }
    $profileName = trim((string) $profileName);
    if ($profileName === '') {
        return null;
    }
    $exact = isset($bag[$profileName]) ? (int) $bag[$profileName] : null;
    if ($exact !== null && $exact > 0) {
        return $exact;
    }
    $want = function_exists('card_name_key') ? card_name_key($profileName) : strtolower($profileName);
    $wantSoft = function_exists('card_price_soft_key') ? card_price_soft_key($profileName) : '';
    $softHit = null;
    foreach ($bag as $nm => $q) {
        $q = (int) $q;
        $k = function_exists('card_name_key') ? card_name_key($nm) : strtolower(trim((string) $nm));
        if ($want !== '' && $k === $want && $q > 0) {
            return $q;
        }
        $soft = function_exists('card_price_soft_key') ? card_price_soft_key($nm) : '';
        if ($wantSoft !== '' && $soft === $wantSoft && $q > 0 && $softHit === null) {
            $softHit = $q;
        }
    }
    if ($softHit !== null) {
        return $softHit;
    }
    if ($exact !== null) {
        return $exact;
    }
    return 0;
}

function cards_transfer_available_qty($pdo, $config, $homeId, $fromAgentId, $profileName, $xferTargets = array(), $allowLive = true)
{
    $fromAgentId = (int) $fromAgentId;
    $homeId = (int) $homeId;
    $profileName = trim((string) $profileName);
    if ($fromAgentId <= 0 || $profileName === '') {
        return null;
    }
    if ($fromAgentId === $homeId) {
        $groups = array();
        if (!$allowLive && function_exists('sas_cards_inventory_load_persisted')) {
            $cached = sas_cards_inventory_load_persisted(0);
            if ($cached && !empty($cached['groups']) && is_array($cached['groups'])) {
                $groups = $cached['groups'];
            }
        } else {
            $inv = cards_page_fetch_inventory($config, false);
            $groups = (is_array($inv) && isset($inv[0]) && is_array($inv[0])) ? $inv[0] : array();
        }
        if (!is_array($groups) || !$groups) {
            return null;
        }
        $bag = array();
        foreach ($groups as $g0) {
            if (!is_array($g0)) {
                continue;
            }
            $gn = isset($g0['name']) ? (string) $g0['name'] : '';
            if ($gn === '') {
                continue;
            }
            $bag[$gn] = isset($g0['unused']) ? (int) $g0['unused'] : 0;
        }
        return cards_stock_qty($bag, $profileName);
    }
    $qtyNow = null;
    if (!$allowLive) {
        if (function_exists('card_sas_stock_read')) {
            $hit = card_sas_stock_read($homeId);
            if ($hit && !empty($hit['map']) && is_array($hit['map']) && isset($hit['map'][$fromAgentId]) && is_array($hit['map'][$fromAgentId])) {
                $qtyNow = cards_stock_qty($hit['map'][$fromAgentId], $profileName);
            }
        }
    } elseif (function_exists('card_sas_stock_map')) {
        $map = card_sas_stock_map($pdo, $config, $homeId, true, $xferTargets);
        if (is_array($map) && isset($map[$fromAgentId]) && is_array($map[$fromAgentId])) {
            $qtyNow = cards_stock_qty($map[$fromAgentId], $profileName);
        }
    }
    if (function_exists('card_portal_admin_unused_bag')) {
        $ownBag = card_portal_admin_unused_bag($pdo, $fromAgentId, $homeId);
        if (is_array($ownBag)) {
            $ownQty = cards_stock_qty($ownBag, $profileName);
            if ($ownQty !== null && ($qtyNow === null || (int) $ownQty > (int) $qtyNow)) {
                return (int) $ownQty;
            }
        }
    }
    return $qtyNow;
}

function cards_page_with_all_categories($pdo, $groups)
{
    if (!is_array($groups)) {
        $groups = array();
    }
    $byName = array();
    $byPid = array();
    foreach ($groups as $i => $g) {
        if (!is_array($g)) {
            continue;
        }
        $name = isset($g['name']) ? strtolower(trim((string) $g['name'])) : '';
        $pid = isset($g['profile_id']) ? (int) $g['profile_id'] : 0;
        if ($name !== '') {
            $byName[$name] = $i;
        }
        if ($pid > 0) {
            $byPid[$pid] = $i;
        }
    }
    $catalog = array();
    if (function_exists('sas_cards_inventory_load_persisted')) {
        $inv = sas_cards_inventory_load_persisted(0);
        if ($inv && !empty($inv['groups']) && is_array($inv['groups'])) {
            foreach ($inv['groups'] as $g) {
                if (is_array($g)) {
                    $catalog[] = $g;
                }
            }
        }
    }
    if (!empty($_SESSION['sas_profiles_ui']) && is_array($_SESSION['sas_profiles_ui'])) {
        foreach ($_SESSION['sas_profiles_ui'] as $pr) {
            if (!is_array($pr)) {
                continue;
            }
            $catalog[] = array(
                'name' => isset($pr['name']) ? (string) $pr['name'] : '',
                'profile_id' => isset($pr['id']) ? (int) $pr['id'] : 0,
                'unused' => 0,
            );
        }
    }
    if ($pdo) {
        try {
            $st = $pdo->query('SELECT name, sas_profile_id FROM service_plans ORDER BY sort_order ASC, id ASC');
            if ($st) {
                foreach ($st->fetchAll() as $p) {
                    $catalog[] = array(
                        'name' => isset($p['name']) ? (string) $p['name'] : '',
                        'profile_id' => isset($p['sas_profile_id']) ? (int) $p['sas_profile_id'] : 0,
                        'unused' => 0,
                    );
                }
            }
        } catch (Exception $e) {
        }
    }
    foreach ($catalog as $g) {
        $name = isset($g['name']) ? trim((string) $g['name']) : '';
        $key = strtolower($name);
        $pid = isset($g['profile_id']) ? (int) $g['profile_id'] : 0;
        if ($key === '' && $pid <= 0) {
            continue;
        }
        if (($key !== '' && isset($byName[$key])) || ($pid > 0 && isset($byPid[$pid]))) {
            continue;
        }
        $unused = isset($g['unused']) ? (int) $g['unused'] : 0;
        $row = array(
            'name' => $name !== '' ? $name : ('#' . $pid),
            'profile_id' => $pid,
            'total' => isset($g['total']) ? (int) $g['total'] : $unused,
            'used' => isset($g['used']) ? (int) $g['used'] : 0,
            'unused' => $unused,
            'cards' => array(),
        );
        $groups[] = $row;
        $idx = count($groups) - 1;
        $rowKey = strtolower($row['name']);
        if ($rowKey !== '') {
            $byName[$rowKey] = $idx;
        }
        if ($pid > 0) {
            $byPid[$pid] = $idx;
        }
    }
    return $groups;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'agent_price') {
    header('Content-Type: application/json; charset=utf-8');
    $aid = (int) (isset($_GET['agent']) ? $_GET['agent'] : 0);
    $pid = (int) (isset($_GET['profile_id']) ? $_GET['profile_id'] : 0);
    $pname = isset($_GET['profile_name']) ? trim((string) $_GET['profile_name']) : '';
    $out = array('ok' => false, 'wholesale_price' => 0, 'agent_price' => 0);
    if ($aid > 0 && function_exists('agent_card_price_get')) {
        $pr = agent_card_price_get($pdo, $aid, $pid, $pname);
        if ($pr) {
            $out['ok'] = true;
            $out['wholesale_price'] = (float) $pr['wholesale_price'];
            $out['agent_price'] = (float) $pr['agent_price'];
        }
    }
    echo json_encode($out);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'xfer_one' && $canTransfer && function_exists('card_sas_stock_owner_bag')) {
    header('Content-Type: application/json; charset=utf-8');
    $oneAgent = isset($_GET['agent']) ? (int) $_GET['agent'] : 0;
    $oneBag = card_sas_stock_owner_bag($pdo, $config, $homeId, $oneAgent);
    echo json_encode(array(
        'ok' => true,
        'known' => is_array($oneBag) ? 1 : 0,
        'agent' => $oneAgent,
        'bag' => is_array($oneBag) ? $oneBag : array(),
    ));
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'xfer_stock' && function_exists('card_sas_stock_read') && function_exists('cards_page_xfer_stock')) {
    $fileFast = card_sas_stock_read($homeId);
    $fileAge = ($fileFast && isset($fileFast['at'])) ? (time() - (int) $fileFast['at']) : 99999;
    $fileMissing = ($fileFast && function_exists('card_sas_stock_users_missing'))
        ? card_sas_stock_users_missing($fileFast['map'], $xferTargets, $homeId)
        : array(1);
    if ($fileFast && $fileAge < 180 && !$fileMissing) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'ok' => true,
            'stock' => cards_page_xfer_stock($homeId, array(), $xferTargets, $fileFast['map']),
        ));
        exit;
    }
}

$groups = array();
$cardsLazy = false;
$err = '';
$fromCache = false;
if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'GET' && function_exists('app_session_close')) {
    app_session_close();
}
if ($sasReady) {
    if (function_exists('sas_cards_inventory_load_persisted')) {
        $invOpen = sas_cards_inventory_load_persisted(0);
        if ($invOpen && !empty($invOpen['groups']) && is_array($invOpen['groups'])) {
            $groups = $invOpen['groups'];
            $fromCache = true;
        }
    }
    if (!$groups && function_exists('sas_dash_cards_load_persisted')) {
        $dashBig = sas_dash_cards_load_persisted();
        if ($dashBig && !empty($dashBig['groups'])) {
            foreach ($dashBig['groups'] as $g) {
                $n = isset($g['count']) ? (int) $g['count'] : (isset($g['unused']) ? (int) $g['unused'] : 0);
                $groups[] = array(
                    'name' => isset($g['name']) ? $g['name'] : '',
                    'profile_id' => isset($g['profile_id']) ? (int) $g['profile_id'] : 0,
                    'total' => $n,
                    'used' => 0,
                    'unused' => $n,
                    'cards' => array(),
                );
            }
            $fromCache = true;
            $cardsLazy = true;
        }
    }
}
$groups = cards_page_with_all_categories($pdo, $groups);

$cardsPinCount = 0;
foreach ($groups as $gPin) {
    if (!empty($gPin['cards']) && is_array($gPin['cards'])) {
        $cardsPinCount += count($gPin['cards']);
    }
}
$cardsLazy = $cardsLazy || ($cardsPinCount > 800);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'cat') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    $giCat = isset($_GET['i']) ? (int) $_GET['i'] : -1;
    $oneCat = ($giCat >= 0 && isset($groups[$giCat])) ? $groups[$giCat] : null;
    $catOut = array('ok' => false, 'group' => null);
    if ($oneCat && (empty($oneCat['cards']) || !is_array($oneCat['cards'])) && function_exists('sas_cards_inventory_load_persisted')) {
        $fullInv = sas_cards_inventory_load_persisted(0);
        $wantName = isset($oneCat['name']) ? (string) $oneCat['name'] : '';
        if ($fullInv && !empty($fullInv['groups']) && is_array($fullInv['groups'])) {
            foreach ($fullInv['groups'] as $fullG) {
                $fullName = isset($fullG['name']) ? (string) $fullG['name'] : '';
                if ($wantName !== '' && $fullName === $wantName) {
                    $oneCat = $fullG;
                    break;
                }
            }
        }
    }
    if ($oneCat) {
        $catPack = cards_filter_groups_scope($pdo, cards_enrich_used_by_links($pdo, array($oneCat)));
        $catOut['ok'] = true;
        $catOut['group'] = ($catPack && isset($catPack[0])) ? $catPack[0] : $oneCat;
    }
    echo json_encode($catOut);
    exit;
}

foreach ($groups as &$gCount) {
    if (!empty($gCount['counts_only'])) {
        continue;
    }
    if (empty($gCount['cards']) || !is_array($gCount['cards'])) {
        continue;
    }
    $freeN = 0;
    $usedN = 0;
    foreach ($gCount['cards'] as $cCount) {
        if (!empty($cCount['used'])) {
            $usedN++;
        } else {
            $freeN++;
        }
    }
    if ((int) $gCount['unused'] > $freeN) {
        continue;
    }
    $gCount['used'] = $usedN;
    $gCount['unused'] = $freeN;
    $gCount['total'] = $usedN + $freeN;
}
unset($gCount);

if ($cardsLazy) {
    $slimGroups = array();
    foreach ($groups as $gPin) {
        $gCopy = $gPin;
        $gCopy['cards'] = array();
        $slimGroups[] = $gCopy;
    }
    $groups = $slimGroups;
} else {
    $groups = cards_filter_groups_scope($pdo, cards_enrich_used_by_links($pdo, $groups));
}

$sumTotal = 0;
$sumUsed = 0;
$sumUnused = 0;
foreach ($groups as $g0) {
    $sumTotal += isset($g0['total']) ? (int) $g0['total'] : 0;
    $sumUsed += isset($g0['used']) ? (int) $g0['used'] : 0;
    $sumUnused += isset($g0['unused']) ? (int) $g0['unused'] : 0;
}

$sasMapCached = null;
$sasMapKey = 'card_sas_stock_map_v5';
$sasMapAge = 99999;
if (!empty($_SESSION[$sasMapKey]) && is_array($_SESSION[$sasMapKey]) && !empty($_SESSION[$sasMapKey . '_at'])
    && (time() - (int) $_SESSION[$sasMapKey . '_at']) < 180) {
    $sasMapCached = $_SESSION[$sasMapKey];
    $sasMapAge = time() - (int) $_SESSION[$sasMapKey . '_at'];
}
if ($sasMapCached === null && function_exists('card_sas_stock_read')) {
    $fileHit = card_sas_stock_read($homeId);
    if ($fileHit && !empty($fileHit['map']) && is_array($fileHit['map'])) {
        $sasMapCached = $fileHit['map'];
        $sasMapAge = time() - (int) $fileHit['at'];
    }
}
$xferStockPending = ($canTransfer && ($sasMapCached === null || $sasMapAge > 180));

if (isset($_GET['ajax']) && $_GET['ajax'] === 'xfer_stock') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('set_time_limit')) {
        @set_time_limit(90);
    }
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    $liveMap = ($canTransfer && function_exists('card_sas_stock_map'))
        ? card_sas_stock_map($pdo, $config, $homeId, true, $xferTargets)
        : array();
    echo json_encode(array(
        'ok' => true,
        'stock' => cards_page_xfer_stock($homeId, $groups, $xferTargets, is_array($liveMap) ? $liveMap : array()),
    ));
    exit;
}

$xferStock = cards_page_xfer_stock($homeId, $groups, $xferTargets, is_array($sasMapCached) ? $sasMapCached : array());

render_header($isEn ? 'Cards' : 'الكارتات', 'cards');
?>
<?php if ($agentStockPanel && !empty($agentStockPanel['stock']['rows'])): ?>
<div class="panel">
    <h2><?php echo e($isEn ? 'My card stock' : 'مخزون كروتي'); ?></h2>
    <div class="table-wrap">
        <table class="table-compact">
            <thead>
            <tr>
                <th><?php echo e($isEn ? 'Package' : 'الباقة'); ?></th>
                <th><?php echo e($isEn ? 'Qty' : 'الكمية'); ?></th>
                <th><?php echo e($isEn ? 'Wholesale' : 'الجملة'); ?></th>
                <th><?php echo e($isEn ? 'Agent price' : 'سعر الوكيل'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($agentStockPanel['stock']['rows'] as $sr): ?>
                <tr>
                    <td><?php echo e($sr['profile_name']); ?></td>
                    <td class="ltr"><?php echo (int) $sr['qty']; ?></td>
                    <td class="ltr"><?php echo e($sr['wholesale_price']); ?></td>
                    <td class="ltr"><?php echo e($sr['agent_price']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="meta"><?php echo e($isEn ? 'Total qty' : 'إجمالي الكمية'); ?>:
        <strong><?php echo (int) $agentStockPanel['stock']['total_qty']; ?></strong></p>
</div>
<?php elseif (function_exists('is_agent_user') && is_agent_user()): ?>
<div class="panel">
    <h2><?php echo e($isEn ? 'My card stock' : 'مخزون كروتي'); ?></h2>
    <p class="meta"><?php echo e($isEn ? 'No stock yet — ask admin to transfer cards.' : 'ماكو مخزون بعد — اطلب من الإدارة تحويل كروت.'); ?></p>
</div>
<?php endif; ?>
<style>
.cards-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
  max-width: 100%;
  min-width: 0;
}
.cards-page table { min-width: 0; }
.cards-page .xfer-panel { order: 2; }
.cards-page .cards-section-title {
  margin: 0 0 10px;
  font-size: 18px;
  font-weight: 800;
}
.cards-page .cards-wids {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin: 0 0 16px;
  width: 100%;
}
@media (max-width: 1100px) { .cards-page .cards-wids { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 560px) { .cards-page .cards-wids { grid-template-columns: 1fr; gap: 11px; } }
.cards-page .sas-box {
  --c1: #0f766e; --c2: #115e59; --ink: #ffffff;
  position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
  touch-action: pan-y;
  color: var(--ink); text-decoration: none; border: 0; border-radius: 2px 20px 2px 16px;
  min-height: 112px; padding: 16px 16px 14px 18px; width: 100%; text-align: inherit; font: inherit;
  cursor: pointer;
  background: linear-gradient(145deg, var(--c1) 0%, var(--c2) 100%);
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
  transition: transform .15s ease, box-shadow .15s ease;
}
.cards-page .sas-box:hover { transform: translateY(-1px); box-shadow: 0 10px 22px rgba(15, 23, 42, 0.16); }
.cards-page .sas-box-title { font-size: 13px; font-weight: 800; opacity: .95; }
.cards-page .sas-box-sub { font-size: 11px; font-weight: 600; opacity: .8; margin-top: 2px; }
.cards-page .sas-box-val { font-size: 28px; font-weight: 800; margin-top: 10px; }
.cards-page .sas-box.tone-blue { --c1: #38bdf8; --c2: #0369a1; }
.cards-page .sas-box.tone-green { --c1: #4ade80; --c2: #15803d; }
.cards-page .sas-box.tone-red { --c1: #fb7185; --c2: #be123c; }
.cards-page .sas-box.tone-yellow { --c1: #fbbf24; --c2: #b45309; }
.cards-page .sas-box.tone-teal { --c1: #2dd4bf; --c2: #0f766e; }
.cards-page .sas-box.tone-navy { --c1: #64748b; --c2: #1e293b; }
.cards-page .sas-box.tone-lime { --c1: #a3e635; --c2: #4d7c0f; }
.cards-page .sas-box.tone-purple { --c1: #fb923c; --c2: #c2410c; }
.cards-page .sas-box.tone-aqua { --c1: #22d3ee; --c2: #0e7490; }
.cards-page .sas-box.is-on { outline: 3px solid #0f172a; outline-offset: 2px; }
.cards-page .cards-summary {
  display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px;
}
.cards-page .sum-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 12px; border-radius: 999px; font-size: 12px; font-weight: 800;
  background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;
}
.cards-page .sum-pill.ok { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
.cards-page .sum-pill.bad { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
.cards-page .cards-sync {
  font-size: 12px; font-weight: 700; color: #64748b; margin: 0 0 10px;
}
.cards-page .cards-sync:empty { display: none; margin: 0; }
.cards-page .cards-sync.is-busy { color: #0f766e; }
.cards-page .cat-block {
  border: 1px solid #d8dee8;
  border-radius: 14px;
  background: #fff;
  margin-bottom: 10px;
  overflow: hidden;
}
.cards-page .cat-head {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
  gap: 8px; padding: 12px 14px; background: #f4f7fb; cursor: pointer; user-select: none;
  width: 100%; border: 0; text-align: inherit; font: inherit; color: inherit;
}
.cards-page .cat-head h3 { margin: 0; font-size: 15px; display: flex; align-items: center; gap: 8px; }
.cards-page .cat-chevron { display: inline-block; transition: transform .15s ease; font-weight: 900; }
.cards-page .cat-block.is-open .cat-chevron { transform: rotate(90deg); }
.cards-page .cat-meta { display: flex; flex-wrap: wrap; gap: 6px; }
.cards-page .pill {
  display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 999px;
  font-size: 11px; font-weight: 800; background: #e2e8f0; color: #334155;
}
.cards-page .pill.ok { background: #dcfce7; color: #166534; }
.cards-page .pill.bad { background: #fee2e2; color: #991b1b; }
.cards-page .cat-body { display: none; padding: 12px 14px 14px; border-top: 1px solid #e8edf4; }
.cards-page .cat-block.is-open .cat-body { display: block; }
.cards-page .chip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(148px, 1fr)); gap: 10px; }
.cards-page .chip-grid-used { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
.cards-page .chip {
  border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; background: #fff;
  min-width: 0; overflow: hidden; box-sizing: border-box;
  user-select: text; -webkit-user-select: text;
}
.cards-page .chip.free {
  display: flex; flex-direction: column; gap: 6px;
  background: #f8fffb; border-color: #bbf7d0;
}
.cards-page .by-line a, .cards-page a.by-link {
  color: #1d4ed8; text-decoration: none; font-weight: 800;
  word-break: break-all; overflow-wrap: anywhere;
}
.cards-page .by-line a:hover, .cards-page a.by-link:hover {
  text-decoration: underline; color: #1e40af;
}
.cards-page .chip-top {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;
  min-width: 0;
}
.cards-page .chip-main { min-width: 0; flex: 1; }
.cards-page .pin-row {
  display: flex; flex-direction: column; align-items: flex-start; gap: 4px;
  min-width: 0; width: 100%;
}
.cards-page .pin {
  font-weight: 800; font-family: ui-monospace, Consolas, monospace; letter-spacing: .02em;
  font-size: 13px; color: #0f172a;
  word-break: break-all; overflow-wrap: anywhere; max-width: 100%;
  user-select: text; -webkit-user-select: text;
}
.cards-page .by-line {
  display: block; font-size: 12px; color: #475569; font-weight: 700;
  word-break: break-all; overflow-wrap: anywhere; max-width: 100%;
  line-height: 1.35;
  user-select: text; -webkit-user-select: text;
}
.cards-page .st {
  display: block; margin-top: 2px; font-size: 11px; font-weight: 800; color: #166534;
  word-break: break-word;
}
.cards-page .chip.used .st { color: #b91c1c; margin-top: 0; }
.cards-page .chip-copy {
  flex: 0 0 auto; border: 1px solid #e2e8f0; background: #fff; color: #334155;
  border-radius: 8px; padding: 4px 8px; font: inherit; font-size: 11px; font-weight: 800;
  cursor: pointer; line-height: 1.2; white-space: nowrap;
}
.cards-page .chip-copy:hover { background: #f1f5f9; }
.cards-page .chip-copy.is-ok { background: #dcfce7; border-color: #86efac; color: #166534; }
.cards-page .empty-cat { color: #64748b; font-weight: 700; padding: 8px 0; }
.cards-page .cards-search {
  width: 100%; max-width: 420px; margin: 0 0 10px; padding: 10px 12px;
  border: 1px solid #d8dee8; border-radius: 10px; font: inherit;
}
.cards-page .filter-row { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 12px; }
.cards-page .filter-row button {
  border: 1px solid #d8dee8; background: #fff; border-radius: 999px;
  padding: 6px 10px; font: inherit; font-weight: 700; cursor: pointer;
}
.cards-page .filter-row button.is-on { background: #0f766e; color: #fff; border-color: #0f766e; }
.cards-page .used-fold { margin-top: 12px; border-top: 1px dashed #e2e8f0; padding-top: 10px; }
.cards-page .used-fold-btn {
  width: 100%; display: flex; justify-content: space-between; align-items: center;
  border: 0; background: transparent; font: inherit; font-weight: 800; cursor: pointer; color: #991b1b;
}
.cards-page .used-fold-body { display: none; margin-top: 10px; }
.cards-page .used-fold.is-open .used-fold-body { display: block; }
.cards-page .used-fold.is-open .used-fold-chevron { transform: rotate(90deg); display: inline-block; }
.cards-page .xfer-panel {
  border: 1px solid #e2e8f0; border-radius: 16px; background: #fff;
  padding: 16px 16px 8px; margin: 6px 0 0;
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
  max-width: 100%;
  min-width: 0;
}
.cards-page .xfer-panel h2 { margin: 0 0 6px; font-size: 18px; }
.cards-page .xfer-head {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  margin: 0 0 12px;
}
.cards-page .xfer-head h3 { margin: 0; font-size: 18px; }
.cards-page .xfer-scroll {
  border: 0; border-radius: 0;
  max-width: 100%;
  overflow: visible;
}
.cards-page .xfer-panel > h2,
.cards-page .xfer-panel > p.meta { display: none; }
.cards-page .xfer-table thead,
.cards-page .xfer-full { display: none; }
.cards-page .xfer-table,
.cards-page .xfer-table tbody,
.cards-page .xfer-table tr { display: block; width: 100%; }
.cards-page .xfer-table tr {
  border: 1px solid #e8edf3; border-radius: 14px; margin: 0 0 8px; padding: 12px 14px;
  background: #fff; box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
}
.cards-page .xfer-compact {
  display: grid; width: 100%; box-sizing: border-box; text-align: start;
  grid-template-columns: 148px minmax(0, 1.35fr) minmax(180px, 1fr) 72px;
  grid-template-areas: "when route pack act" "when by note act";
  align-items: center; column-gap: 16px; row-gap: 2px;
}
.cards-page .xfer-c-top { grid-area: when; direction: ltr; font-size: 12px; color: #64748b; white-space: nowrap; }
.cards-page .xfer-c-route,
.cards-page .xfer-c-pack { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cards-page .xfer-c-route {
  grid-area: route; font-size: 14px; font-weight: 800;
  direction: ltr; text-align: left; unicode-bidi: isolate;
}
.cards-page .xfer-c-pack {
  grid-area: pack; font-size: 13px; font-weight: 700; color: #334155;
  font-variant-numeric: tabular-nums;
}
.cards-page .xfer-c-note { grid-area: note; font-size: 12px; color: #64748b; }
.cards-page .xfer-c-note:empty { display: none; }
.cards-page .xfer-c-by { grid-area: by; font-size: 11px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cards-page .xfer-c-act { grid-area: act; justify-self: end; margin: 0; width: 72px; text-align: center; }
.cards-page .cards-swipe-hint {
  margin: 0 0 10px; color: #475569; font-size: 13px; font-weight: 700;
}
.cards-page .card-xfer-go {
  position: relative; z-index: 2; margin-top: 8px; align-self: flex-start;
  display: inline-flex; align-items: center; height: 26px; padding: 0 10px;
  border-radius: 999px; background: rgba(255,255,255,.22); font-size: 12px; font-weight: 800;
}
.cards-page .sas-box.is-dragging { transition: none; }
#cardXferForm:not(.is-sheet) .xfer-sheet-body { display: none; }
#cardXferForm.is-sheet {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  right: auto; bottom: auto; z-index: 100200; margin: 0; padding: 0; border: 0;
  background: rgba(15, 23, 42, .48);
  display: flex; align-items: flex-end; justify-content: center;
  overflow: hidden; box-sizing: border-box;
}
#cardXferForm.is-sheet .xfer-sheet-body {
  display: block; width: 100%; max-width: 560px; max-height: 100%;
  overflow: auto; -webkit-overflow-scrolling: touch; overscroll-behavior: contain;
  background: #fff; border-radius: 18px 18px 0 0;
  padding: 0 16px calc(18px + env(safe-area-inset-bottom));
  box-shadow: 0 -12px 40px rgba(15, 23, 42, .22); text-align: start; box-sizing: border-box;
}
#cardXferForm.is-sheet .xfer-sheet-head {
  position: sticky; top: 0; z-index: 2; background: #fff; padding-top: 14px; margin-bottom: 10px;
}
#cardXferForm.is-sheet .xfer-grid { grid-template-columns: 1fr; width: 100%; min-width: 0; }
#cardXferForm.is-sheet .xfer-grid > div { min-width: 0; overflow: visible; }
#cardXferForm.is-sheet .xfer-grid label {
  overflow: visible; line-height: 1.45; min-height: 0; text-align: start;
}
#cardXferForm.is-sheet input,
#cardXferForm.is-sheet select,
#cardXferForm.is-sheet .xfer-pick-btn {
  height: auto !important; min-height: 44px; font-size: 16px; line-height: 1.35;
  box-sizing: border-box; max-width: 100%;
}
#cardXferForm #xferQty::-webkit-outer-spin-button,
#cardXferForm #xferQty::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
#cardXferForm #xferQty { -moz-appearance: textfield; appearance: textfield; }
.cards-page .xfer-sheet-head {
  display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px;
}
.cards-page .xfer-sheet-head h3 { margin: 0; font-size: 18px; }
.cards-page .xfer-sheet-x {
  border: 0; background: #f1f5f9; width: 36px; height: 36px; border-radius: 10px;
  font-size: 20px; cursor: pointer; color: #0f172a;
}
#cardXferForm .xfer-extra[hidden],
#cardXferForm #xferExtra[hidden] { display: none !important; }
#cardXferForm.is-sheet #xferExtra {
  width: 100%; min-height: 88px; height: auto !important; margin-top: 8px;
  padding: 10px 12px; font-size: 16px; line-height: 1.45; resize: vertical; box-sizing: border-box;
}
#cardXferForm #xferSubmit {
  width: 100%;
  height: 56px;
  margin-top: 6px;
  border: 0;
  border-radius: 16px;
  font-size: 18px;
  font-weight: 800;
  color: #fff;
  background: linear-gradient(115deg, #166534 0%, #16a34a 32%, #4ade80 50%, #16a34a 68%, #166534 100%);
  background-size: 240% 100%;
  box-shadow: 0 10px 24px rgba(22, 163, 74, 0.34);
  animation: xferLiveShift 2.8s ease-in-out infinite, xferLiveGlow 2.2s ease-in-out infinite;
}
#cardXferForm #xferSubmit:disabled {
  background: #e2e8f0;
  color: #94a3b8;
  box-shadow: none;
  animation: none;
  cursor: not-allowed;
}
@keyframes xferLiveShift {
  0% { background-position: 100% 50%; }
  50% { background-position: 0% 50%; }
  100% { background-position: 100% 50%; }
}
@keyframes xferLiveGlow {
  0%, 100% { box-shadow: 0 8px 18px rgba(22, 163, 74, 0.28); }
  50% { box-shadow: 0 14px 30px rgba(34, 197, 94, 0.5); }
}
@media (min-width: 761px) {
  #cardXferForm.is-sheet { align-items: center; padding: 16px; }
  #cardXferForm.is-sheet .xfer-sheet-body { border-radius: 18px; }
}
.cards-page .xfer-pager {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  margin: 12px 2px 8px; flex-wrap: wrap;
}
.cards-page .xfer-nav {
  display: inline-flex; align-items: center; height: 34px; padding: 0 3px; gap: 2px;
  border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc;
}
.cards-page .xfer-arrow {
  display: inline-flex; align-items: center; justify-content: center;
  width: 28px; min-width: 28px; height: 28px; border-radius: 8px; border: 0;
  background: transparent; color: #0f172a; font-size: 18px; line-height: 1;
  font-weight: 700; text-decoration: none;
}
.cards-page .xfer-arrow:hover { background: #fff; }
.cards-page .xfer-arrow.is-off { opacity: .35; pointer-events: none; }
.cards-page .xfer-page-no {
  min-width: 46px; text-align: center; font-size: 12px; font-weight: 800; color: #334155;
}
.cards-page .xfer-size {
  display: inline-flex; align-items: center; height: 34px; margin: 0;
  padding: 0 10px 0 4px; gap: 0;
  border: 1px solid #e2e8f0; border-radius: 12px; background: #fff;
}
.cards-page .xfer-size label {
  display: inline-flex; align-items: center; gap: 8px; margin: 0;
  font-size: 12px; font-weight: 800; color: #475569;
}
.cards-page .xfer-size select {
  appearance: none; -webkit-appearance: none; -moz-appearance: none;
  border: 0; height: 26px; min-width: 58px; padding: 0 10px 0 22px; border-radius: 8px;
  background-color: #f1f5f9;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='none' stroke='%23475569' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round' d='M2.5 4.5 6 8l3.5-3.5'/%3E%3C/svg%3E");
  background-repeat: no-repeat; background-position: left 7px center;
  font: inherit; font-weight: 800; color: #0f172a; cursor: pointer;
}
.cards-page td[data-col="act"] { vertical-align: middle; text-align: center; white-space: normal; }
.cards-page td[data-col="act"] form,
.cards-page .xfer-c-act form { display: block; margin: 2px 0 0; }
.cards-page .xfer-live {
  display: inline-block; width: 16px; height: 16px; box-sizing: border-box;
  border-radius: 50%; border: 2px solid #dbe4ee; border-top-color: #0f766e;
  vertical-align: middle; animation: xferSpin .8s linear infinite;
}
.cards-page .xfer-wait {
  display: inline-block; margin-inline-start: 6px; font-weight: 800; color: #0f766e;
}
@keyframes xferSpin { to { transform: rotate(360deg); } }
.cards-page tr[data-state="pending"] td { color: #64748b; }
.cards-page tr[data-state="done"] { background: #f0fdf4; }
.cards-page .xfer-ok { color: #166534; font-weight: 700; margin-inline-end: 6px; }
.cards-page .xfer-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px;
  min-width: 0;
}
.cards-page .xfer-grid label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; color: #475569; }
.cards-page .xfer-grid input, .cards-page .xfer-grid select {
  width: 100%; padding: 8px 10px; border: 1px solid #d8dee8; border-radius: 8px; font: inherit;
}
.cards-page .xfer-table { width: 100%; min-width: 0; border-collapse: collapse; font-size: 13px; margin-top: 12px; table-layout: fixed; }
.cards-page .xfer-table th, .cards-page .xfer-table td {
  border-bottom: 1px solid #e8edf4; padding: 8px 6px; text-align: inherit;
  word-break: break-word; overflow-wrap: anywhere;
}
.cards-page #xferQty.is-over {
  border-color: #dc2626;
  color: #b91c1c;
  background: #fef2f2;
}
.cards-page #xferAvail.is-over { color: #b91c1c; font-weight: 800; }
.cards-page #xferAvail.is-wait::after {
  content: "";
  display: inline-block;
  width: 12px; height: 12px; margin-inline-start: 6px;
  border-radius: 50%; border: 2px solid #dbe4ee; border-top-color: #0f766e;
  vertical-align: -2px; animation: xferSpin .8s linear infinite;
}
@media (max-width: 760px) {
  .cards-page { align-items: stretch; }
  .cards-page .cards-section-title { text-align: start; }
  .cards-page .sas-box { text-align: start; align-items: flex-start; min-height: 86px; padding: 12px; }
  .cards-page .sas-box-val { margin-top: 4px; }
  .cards-page .cat-head {
    flex-direction: column; align-items: stretch; justify-content: flex-start; gap: 8px;
  }
  .cards-page .cat-head h3 { width: 100%; min-width: 0; }
  .cards-page .cat-meta { width: 100%; }
  .cards-page .xfer-panel { margin-inline: 0; text-align: start; padding: 14px 12px 8px; }
  .cards-page .xfer-grid { grid-template-columns: 1fr; }
  .cards-page .xfer-grid label,
  .cards-page .xfer-grid input,
  .cards-page .xfer-grid select,
  .cards-page .xfer-pick-btn { text-align: start; }
  .cards-page #cardXferForm .toggle {
    display: flex; width: 100%; justify-content: space-between; margin-top: 14px;
  }
  .cards-page #cardXferForm .actions { display: block; }
  .cards-page #cardXferForm .actions .btn { width: 100%; }
  .cards-page .xfer-head {
    justify-content: space-between; text-align: start; width: 100%; gap: 8px;
  }
  .cards-page .xfer-scroll { overflow: visible; border: 0; }
  .cards-page .xfer-table { table-layout: auto; margin-inline: 0; }
  .cards-page .xfer-table thead,
  .cards-page .xfer-full { display: none; }
  .cards-page .xfer-table,
  .cards-page .xfer-table tbody,
  .cards-page .xfer-table tr { display: block; width: 100%; }
  .cards-page .xfer-compact {
    display: grid; width: 100%; box-sizing: border-box;
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
      "when act"
      "route route"
      "pack pack"
      "note note"
      "by by";
    column-gap: 8px; row-gap: 2px; text-align: start;
  }
  .cards-page .xfer-table tr {
    border: 1px solid #e8edf3; border-radius: 12px; margin: 0 0 8px; padding: 10px 12px;
    background: #fff; text-align: start; max-width: none;
  }
  .cards-page .xfer-table tr:not(:has(.xfer-compact)) td {
    display: block; width: 100%; border: 0; padding: 2px 0; text-align: start;
    word-break: normal; overflow-wrap: break-word;
  }
  .cards-page .xfer-table tr:not(:has(.xfer-compact)) td:empty { display: none; }
  .cards-page .xfer-c-top { grid-area: when; font-size: 12px; color: #64748b; direction: ltr; text-align: end; }
  .cards-page .xfer-c-act { grid-area: act; margin-top: 0; justify-self: end; }
  .cards-page .xfer-c-route { grid-area: route; }
  .cards-page .xfer-c-pack { grid-area: pack; }
  .cards-page .xfer-c-note { grid-area: note; }
  .cards-page .xfer-c-by { grid-area: by; }
  .cards-page .xfer-c-mid {
    font-size: 13px; font-weight: 700; line-height: 1.45;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .cards-page .xfer-c-note {
    font-size: 12px; color: #64748b; line-height: 1.35; margin-top: 2px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
  }
  .cards-page .xfer-c-note:empty { display: none; }
  .cards-page .xfer-c-by { font-size: 11px; color: #94a3b8; }
}
.cards-page .xfer-native {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0, 0, 0, 0); border: 0;
}
.cards-page .xfer-pick { position: relative; }
.cards-page .xfer-pick-btn {
  width: 100%; min-height: 42px; padding: 8px 12px; text-align: start;
  border: 1px solid #d8dee8; border-radius: 8px; background: #fff;
  font: inherit; font-weight: 700; cursor: pointer; color: #0f172a;
}
.cards-page .xfer-pick-btn.is-empty { color: #64748b; font-weight: 600; }
.cards-page .xfer-pick-pop {
  position: absolute; z-index: 40; inset-inline-start: 0; top: calc(100% + 4px);
  width: min(340px, calc(100vw - 28px)); min-width: 100%;
  background: #fff; border: 1px solid #d8dee8; border-radius: 12px;
  box-shadow: 0 16px 36px rgba(15, 23, 42, 0.16); padding: 8px;
}
.cards-page .xfer-pick-q {
  width: 100%; box-sizing: border-box; min-height: 42px; font-size: 16px;
  padding: 8px 10px; border: 1px solid #d8dee8; border-radius: 8px; font-family: inherit;
}
.cards-page .xfer-pick-list {
  margin-top: 8px; max-height: min(280px, 46vh); overflow: auto;
  -webkit-overflow-scrolling: touch; overscroll-behavior: contain;
}
.cards-page .xfer-pick-item {
  display: block; width: 100%; text-align: start; border: 0; background: transparent;
  min-height: 44px; padding: 10px 10px; border-radius: 8px;
  font: inherit; font-weight: 700; cursor: pointer; color: #0f172a;
}
.cards-page .xfer-pick-item.is-on { background: #ecfdf5; color: #065f46; }
.cards-page .xfer-pick-item:hover { background: #f8fafc; }
.cards-page .xfer-pick-empty { padding: 12px 8px; color: #64748b; font-weight: 700; font-size: 13px; }
@media (max-width: 640px) {
  .cards-page .xfer-pick-pop { width: 100%; min-width: 100%; max-width: 100%; }
  .cards-page .xfer-pick-list { max-height: min(320px, 50vh); }
}
</style>

<div class="cards-page">
    <?php if ($canTransfer): ?>
    <div class="xfer-panel" id="card-transfer">
        <h2><?php echo e($cardsHasDown
            ? ($isEn ? 'Card transfer' : 'تحويل كروت')
            : ($isEn ? 'Return cards' : 'ارجاع الكروت')); ?></h2>
        <p class="meta" style="margin:0 0 10px"><?php echo e($cardsHasDown
            ? ($isEn ? 'Transfer a category to an agent under you.' : 'تحويل فئة لوكيل تحته.')
            : ($isEn ? 'Each category goes back on its own to the parent of this page.' : 'كل فئة ترجع لحالها لأب هذه الصفحة.')); ?></p>
        <?php
        $xferOpt = function ($ag) use ($isEn) {
            $opt = trim((string) $ag['display_name']) !== '' ? $ag['display_name'] : $ag['username'];
            if (isset($ag['is_active']) && (int) $ag['is_active'] !== 1) {
                $opt .= $isEn ? ' (stopped)' : ' (موقوف)';
            }
            return $opt;
        };
        ?>
        <form method="post" class="cards-page" id="cardXferForm" data-no-wait="1">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="transfer">
            <input type="hidden" name="xfer_n" value="<?php echo (int) $xferPer; ?>">
            <div class="xfer-sheet-body">
            <div class="xfer-sheet-head">
                <h3 id="xferSheetTitle"><?php echo e($isEn ? 'Transfer cards' : 'تحويل الكروت'); ?></h3>
                <button type="button" class="xfer-sheet-x" id="xferSheetClose" aria-label="<?php echo e($isEn ? 'Close' : 'إغلاق'); ?>">×</button>
            </div>
            <div class="xfer-grid">
                <div>
                    <label><?php echo e($isEn ? 'From' : 'من'); ?></label>
                    <select class="xfer-native" name="from_agent_id" id="xferFrom" required>
                        <option value="<?php echo (int) $homeId; ?>" data-up="<?php echo (int) $pageParentId; ?>"><?php echo e($homeLabel); ?></option>
                        <?php foreach ($xferTargets as $ag):
                            $upId = isset($ag['reports_to_user_id']) ? (int) $ag['reports_to_user_id'] : 0;
                            if ($upId <= 0) {
                                $upId = (int) $homeId;
                            }
                            ?>
                            <option value="<?php echo (int) $ag['id']; ?>" data-up="<?php echo (int) $upId; ?>"><?php echo e($xferOpt($ag)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="xfer-pick" id="xferFromPick">
                        <button type="button" class="xfer-pick-btn" id="xferFromBtn"><?php echo e($homeLabel); ?></button>
                        <div class="xfer-pick-pop" id="xferFromPop" hidden>
                            <input type="search" class="xfer-pick-q" id="xferFromQ" placeholder="<?php echo e($isEn ? 'Search agent…' : 'بحث عن الوكيل…'); ?>" autocomplete="off">
                            <div class="xfer-pick-list" id="xferFromList"></div>
                        </div>
                    </div>
                </div>
                <div>
                    <label><?php echo e($cardsHasDown ? ($isEn ? 'To' : 'إلى') : ($isEn ? 'To the parent' : 'إلى الأب')); ?></label>
                    <select class="xfer-native" name="to_agent_id" id="xferTo" required>
                        <option value=""><?php echo e($isEn ? '— select —' : '— اختر الوكيل —'); ?></option>
                        <?php if ($pageParentId > 0): ?>
                        <option value="<?php echo (int) $pageParentId; ?>" data-parent="1"><?php echo e($pageParentLabel); ?></option>
                        <?php endif; ?>
                        <option value="<?php echo (int) $homeId; ?>" data-home="1"><?php echo e($homeLabel); ?></option>
                        <?php foreach ($xferTargets as $ag): ?>
                            <option value="<?php echo (int) $ag['id']; ?>" data-down="1"><?php echo e($xferOpt($ag)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="xfer-pick" id="xferToPick">
                        <button type="button" class="xfer-pick-btn is-empty" id="xferToBtn"><?php echo e($isEn ? '— select —' : '— اختر الوكيل —'); ?></button>
                        <div class="xfer-pick-pop" id="xferToPop" hidden>
                            <input type="search" class="xfer-pick-q" id="xferToQ" placeholder="<?php echo e($isEn ? 'Search agent…' : 'بحث عن الوكيل…'); ?>" autocomplete="off">
                            <div class="xfer-pick-list" id="xferToList"></div>
                        </div>
                    </div>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Card type' : 'نوع الكارت'); ?></label>
                    <select name="profile_name" id="xferProfileName" required>
                        <option value=""><?php echo e($isEn ? '— select —' : '— اختر —'); ?></option>
                        <?php foreach ($groups as $g0):
                            $gn = isset($g0['name']) ? (string) $g0['name'] : '';
                            if ($gn === '') { continue; }
                            $gpid = isset($g0['profile_id']) ? (int) $g0['profile_id'] : 0;
                            ?>
                            <option value="<?php echo e($gn); ?>" data-pid="<?php echo $gpid; ?>" data-free="<?php echo isset($g0['unused']) ? (int) $g0['unused'] : 0; ?>"><?php echo e($gn); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="profile_id" id="xferProfileId" value="0">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Quantity' : 'العدد'); ?></label>
                    <input name="qty" id="xferQty" type="number" min="1" step="1" required value="1">
                    <div class="meta" id="xferAvail" style="margin-top:4px"><?php echo e($isEn ? 'Available: —' : 'المتوفر: —'); ?></div>
                </div>
                <div style="grid-column: 1 / -1">
                    <label><?php echo e($isEn ? 'Note' : 'ملاحظات'); ?></label>
                    <input name="note" maxlength="255" placeholder="<?php echo e($isEn ? 'Note…' : 'ملاحظات…'); ?>">
                </div>
            </div>
            <label class="toggle" style="display:flex;align-items:center;gap:8px;margin-top:12px">
                <input type="checkbox" name="notify_parent" value="1" id="xferNotify" checked>
                <span class="toggle-ui" aria-hidden="true"></span>
                <span><?php echo e($isEn ? 'Send a WhatsApp notice to this agent' : 'أرسل إشعار واتساب لهذا الوكيل'); ?></span>
            </label>
            <div class="xfer-extra" id="xferExtraWrap">
                <label class="toggle" style="display:flex;align-items:center;gap:8px;margin-top:10px">
                    <input type="checkbox" id="xferExtraOn">
                    <span class="toggle-ui" aria-hidden="true"></span>
                    <span><?php echo e($isEn ? 'Extra text' : 'نص إضافي'); ?></span>
                </label>
                <textarea name="notice_extra" id="xferExtra" maxlength="400" rows="3" hidden disabled placeholder="<?php echo e($isEn ? 'Write the extra text…' : 'اكتب النص اللي تريد يوصل ويا الإشعار…'); ?>"></textarea>
            </div>
            <div class="actions" style="margin-top:12px">
                <button class="btn" type="submit" id="xferSubmit" disabled><?php echo e($isEn ? 'Record transfer' : 'تسجيل التحويل'); ?></button>
            </div>
            </div>
        </form>
        <script>
        (function () {
          var form = document.getElementById('cardXferForm');
          var fromSel = document.getElementById('xferFrom');
          var toSel = document.getElementById('xferTo');
          var profile = document.getElementById('xferProfileName');
          var profileId = document.getElementById('xferProfileId');
          var qty = document.getElementById('xferQty');
          var availEl = document.getElementById('xferAvail');
          var homeId = <?php echo (int) $homeId; ?>;
          var pageParent = <?php echo (int) $pageParentId; ?>;
          var hasDown = <?php echo $cardsHasDown ? 'true' : 'false'; ?>;
          var stock = <?php
            $stockJson = json_encode($xferStock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            echo ($stockJson === false) ? '{}' : $stockJson;
          ?> || {};
          var stockPending = <?php echo $xferStockPending ? 'true' : 'false'; ?>;
          var availWord = <?php echo json_encode($isEn ? 'Available: ' : 'المتوفر: '); ?>;
          var noneWord = <?php echo json_encode($isEn ? 'No cards' : 'لا توجد كروت'); ?>;
          var availMiss = <?php echo json_encode($isEn ? 'Could not read the count' : 'ما انقرأ العدد'); ?>;
          var availAsked = '';
          var availFail = {};
          if (!form || !fromSel || !toSel || !qty) return;
          function nameKey(s) {
            return String(s || '').toLowerCase().replace(/[\s_\-–—]+/g, '').replace(/msl$/, '');
          }
          function tileUnused(name) {
            var want = nameKey(name);
            if (!want) return null;
            var boxes = document.querySelectorAll('#cardsWids .sas-box, #cardsRoot .sas-box');
            var i, title, raw, n;
            for (i = 0; i < boxes.length; i++) {
              title = boxes[i].querySelector('.sas-box-title');
              raw = title ? title.textContent : '';
              if (nameKey(raw) !== want) continue;
              n = parseInt(boxes[i].getAttribute('data-unused'), 10);
              if (isNaN(n)) {
                var val = boxes[i].querySelector('.sas-box-val');
                n = parseInt(val ? val.textContent : '0', 10) || 0;
              }
              return n;
            }
            return null;
          }
          function availNow() {
            var name = profile ? profile.value : '';
            if (!name) return null;
            if (String(fromSel.value) === String(homeId)) {
              var shown = tileUnused(name);
              if (shown !== null) return shown;
              var homeOpt = profile.options[profile.selectedIndex];
              if (homeOpt && homeOpt.getAttribute('data-free') !== null && homeOpt.getAttribute('data-free') !== '') {
                return parseInt(homeOpt.getAttribute('data-free'), 10) || 0;
              }
            }
            var fromKey = String(fromSel.value);
            if (!Object.prototype.hasOwnProperty.call(stock, fromKey)) return null;
            var bag = stock[fromKey] || {};
            var want = nameKey(name);
            var k;
            var soft = null;
            if (typeof bag[name] !== 'undefined' && (parseInt(bag[name], 10) || 0) > 0) {
              return parseInt(bag[name], 10) || 0;
            }
            for (k in bag) {
              if (!Object.prototype.hasOwnProperty.call(bag, k) || nameKey(k) !== want) continue;
              var qn = parseInt(bag[k], 10) || 0;
              if (qn > 0) return qn;
              if (soft === null) soft = qn;
            }
            if (typeof bag[name] !== 'undefined') return parseInt(bag[name], 10) || 0;
            return soft === null ? 0 : soft;
          }
          function setSubmitEnabled(on) {
            var btn = document.getElementById('xferSubmit');
            if (btn) btn.disabled = !on;
          }
          function paintEnds() {
            var pulling = String(fromSel.value) !== String(homeId);
            var fromOpt = fromSel.options[fromSel.selectedIndex];
            var upId = fromOpt ? (fromOpt.getAttribute('data-up') || '') : '';
            if (!hasDown && pageParent > 0) upId = String(pageParent);
            if (!upId) upId = String(homeId);
            var i;
            var hasUp = false;
            for (i = 0; i < toSel.options.length; i++) {
              if (String(toSel.options[i].value) === String(upId)) hasUp = true;
            }
            if (!hasUp) upId = hasDown ? String(homeId) : String(pageParent || homeId);
            for (i = 0; i < toSel.options.length; i++) {
              var opt = toSel.options[i];
              if (!opt.value) {
                opt.hidden = pulling || !hasDown;
                continue;
              }
              if (!hasDown) {
                opt.hidden = String(opt.value) !== String(pageParent);
              } else if (pulling) {
                opt.hidden = String(opt.value) !== String(upId);
              } else if (opt.getAttribute('data-parent') === '1') {
                opt.hidden = false;
              } else if (opt.getAttribute('data-home') === '1') {
                opt.hidden = true;
              } else if (opt.getAttribute('data-down') === '1') {
                opt.hidden = String(opt.value) === String(fromSel.value);
              }
            }
            if (!hasDown && pageParent > 0) {
              toSel.value = String(pageParent);
            } else if (pulling) {
              toSel.value = String(upId);
            } else if (toSel.value === String(homeId) || toSel.value === String(fromSel.value)) {
              toSel.value = '';
            }
            var notifyBox = form.querySelector('input[name="notify_parent"]');
            var notifyLabel = notifyBox ? notifyBox.closest('label') : null;
            if (notifyLabel) notifyLabel.style.display = '';
            var submitBtn = document.getElementById('xferSubmit');
            if (submitBtn) {
              submitBtn.textContent = (!hasDown || pulling)
                ? <?php echo json_encode($isEn ? 'Return this category' : 'ارجاع هذه الفئة'); ?>
                : <?php echo json_encode($isEn ? 'Record transfer' : 'تسجيل التحويل'); ?>;
            }
            if (window.xferFromSync) window.xferFromSync();
            if (window.xferToSync) window.xferToSync();
          }
          function bindAgentPick(sel, ids, emptyLabel) {
            var pick = document.getElementById(ids.pick);
            var btn = document.getElementById(ids.btn);
            var pop = document.getElementById(ids.pop);
            var q = document.getElementById(ids.q);
            var list = document.getElementById(ids.list);
            var noneLabel = <?php echo json_encode($isEn ? 'No match' : 'ماكو نتيجة'); ?>;
            if (!sel || !pick || !btn || !pop || !q || !list) return;
            function visibleOpts() {
              var out = [];
              var i;
              for (i = 0; i < sel.options.length; i++) {
                var opt = sel.options[i];
                if (!opt.value || opt.hidden) continue;
                out.push(opt);
              }
              return out;
            }
            function paintBtn() {
              var cur = sel.options[sel.selectedIndex];
              var text = (cur && cur.value) ? cur.text : emptyLabel;
              btn.textContent = text;
              btn.classList.toggle('is-empty', !cur || !cur.value);
            }
            function paintList() {
              var needle = (q.value || '').replace(/^\s+|\s+$/g, '').toLowerCase();
              var opts = visibleOpts();
              var html = '';
              var n = 0;
              opts.forEach(function (opt) {
                var label = opt.text || '';
                if (needle && label.toLowerCase().indexOf(needle) < 0) return;
                n++;
                var on = String(opt.value) === String(sel.value) ? ' is-on' : '';
                html += '<button type="button" class="xfer-pick-item' + on + '" data-val="' + String(opt.value).replace(/"/g, '') + '">' + label.replace(/[&<>]/g, function (c) {
                  return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c];
                }) + '</button>';
              });
              if (!n) html = '<div class="xfer-pick-empty">' + noneLabel + '</div>';
              list.innerHTML = html;
            }
            function openPop() {
              pop.hidden = false;
              q.value = '';
              paintList();
              setTimeout(function () { q.focus(); }, 30);
            }
            function closePop() { pop.hidden = true; }
            window[ids.sync] = function () { paintBtn(); if (!pop.hidden) paintList(); };
            btn.addEventListener('click', function () {
              if (pop.hidden) openPop();
              else closePop();
            });
            q.addEventListener('input', paintList);
            list.addEventListener('click', function (e) {
              var item = e.target && e.target.closest ? e.target.closest('[data-val]') : null;
              if (!item) return;
              sel.value = item.getAttribute('data-val') || '';
              paintBtn();
              closePop();
              if (document.createEvent) {
                var ev = document.createEvent('HTMLEvents');
                ev.initEvent('change', true, false);
                sel.dispatchEvent(ev);
              }
            });
            document.addEventListener('click', function (e) {
              if (!pick.contains(e.target)) closePop();
            });
            document.addEventListener('keydown', function (e) {
              if (e.key === 'Escape') closePop();
            });
            paintBtn();
          }
          function paintQty() {
            var nameNow = profile ? profile.value : '';
            var max = nameNow ? availNow() : null;
            var q = parseInt(qty.value, 10) || 0;
            if (!nameNow) {
              if (availEl) {
                availEl.textContent = availWord + '—';
                availEl.classList.remove('is-over');
                availEl.classList.remove('is-wait');
              }
              qty.classList.remove('is-over');
              setSubmitEnabled(false);
              return false;
            }
            if (max === null) {
              if (availFail[String(fromSel.value)]) {
                if (availEl) {
                  availEl.textContent = availMiss;
                  availEl.classList.remove('is-wait');
                  availEl.classList.remove('is-over');
                }
                qty.classList.remove('is-over');
                setSubmitEnabled(false);
                return false;
              }
              if (availEl) {
                availEl.textContent = availWord;
                availEl.classList.add('is-wait');
                availEl.classList.remove('is-over');
              }
              qty.classList.remove('is-over');
              setSubmitEnabled(false);
              askAvail();
              return false;
            }
            if (availEl) availEl.classList.remove('is-wait');
            if (max <= 0) {
              if (availEl) {
                availEl.textContent = noneWord;
                availEl.classList.add('is-over');
              }
              qty.classList.add('is-over');
              setSubmitEnabled(false);
              return false;
            }
            var over = q < 1 || q > max;
            if (availEl) {
              availEl.textContent = availWord + String(max);
              if (over) availEl.classList.add('is-over');
              else availEl.classList.remove('is-over');
            }
            if (over) qty.classList.add('is-over');
            else qty.classList.remove('is-over');
            setSubmitEnabled(!over);
            return !over;
          }
          fromSel.addEventListener('change', function () { paintEnds(); paintQty(); });
          toSel.addEventListener('change', paintQty);
          bindAgentPick(fromSel, {
            pick: 'xferFromPick', btn: 'xferFromBtn', pop: 'xferFromPop', q: 'xferFromQ', list: 'xferFromList', sync: 'xferFromSync'
          }, <?php echo json_encode($isEn ? '— select —' : '— اختر —'); ?>);
          bindAgentPick(toSel, {
            pick: 'xferToPick', btn: 'xferToBtn', pop: 'xferToPop', q: 'xferToQ', list: 'xferToList', sync: 'xferToSync'
          }, <?php echo json_encode($isEn ? '— select —' : '— اختر الوكيل —'); ?>);
          if (profile) {
            profile.addEventListener('change', function () {
              var opt = profile.options[profile.selectedIndex];
              if (profileId) profileId.value = opt ? (opt.getAttribute('data-pid') || '0') : '0';
              paintQty();
            });
          }
          qty.addEventListener('input', paintQty);
          function xferCell(text, label) {
            var td = document.createElement('td');
            td.textContent = text || '';
            if (label) td.setAttribute('data-label', label);
            return td;
          }
          function xferToast(message) {
            var old = document.querySelector('.sas-toast.xfer-toast');
            if (old && old.parentNode) old.parentNode.removeChild(old);
            var box = document.createElement('div');
            box.className = 'sas-toast sas-toast-error xfer-toast';
            box.setAttribute('role', 'status');
            var span = document.createElement('span');
            span.className = 'sas-toast-text';
            span.textContent = message;
            box.appendChild(span);
            document.body.appendChild(box);
            if (window.placeCornerToast) window.placeCornerToast(box);
            setTimeout(function () {
              box.style.opacity = '0';
              box.style.transform = 'translateY(-56px)';
            }, 3000);
            setTimeout(function () {
              if (box.parentNode) box.parentNode.removeChild(box);
            }, 3800);
          }
          function xferTrim(v) {
            return String(v || '').replace(/^\s+|\s+$/g, '');
          }
          function insertPendingRow() {
            var table = document.getElementById('xferTable');
            var body = table ? table.querySelector('tbody') : null;
            if (!body) return null;
            var fromBtn = document.getElementById('xferFromBtn');
            var toBtn = document.getElementById('xferToBtn');
            var profOpt = profile && profile.options[profile.selectedIndex] ? profile.options[profile.selectedIndex] : null;
            var fromName = xferTrim(fromBtn ? fromBtn.textContent : '');
            var toName = xferTrim(toBtn ? toBtn.textContent : '');
            var profName = xferTrim(profOpt ? profOpt.textContent : '');
            var qtyVal = qty ? qty.value : '1';
            var actorName = <?php
              $xferActorName = '';
              if (is_array($me)) {
                  $xferActorName = isset($me['display_name']) ? trim((string) $me['display_name']) : '';
                  if ($xferActorName === '' && isset($me['username'])) {
                      $xferActorName = (string) $me['username'];
                  }
              }
              echo json_encode($xferActorName);
            ?>;
            var waitHtml = '<span class="xfer-live" aria-hidden="true"></span><span class="xfer-wait">'
              + <?php echo json_encode($isEn ? 'Transferring' : 'جاري التحويل'); ?> + '</span>';
            var tr = document.createElement('tr');
            tr.setAttribute('data-state', 'pending');
            tr.setAttribute('data-from', fromSel.value);
            tr.setAttribute('data-profile', profile ? profile.value : '');
            tr.setAttribute('data-qty', qtyVal);
            var compact = document.createElement('td');
            compact.className = 'xfer-compact';
            var top = document.createElement('div');
            top.className = 'xfer-c-top';
            top.setAttribute('data-col', 'when');
            var route = document.createElement('div');
            route.className = 'xfer-c-mid xfer-c-route';
            route.setAttribute('dir', 'ltr');
            route.textContent = fromName + ' → ' + toName;
            var pack = document.createElement('div');
            pack.className = 'xfer-c-mid xfer-c-pack';
            pack.textContent = profName + ' · ' + qtyVal;
            var noteC = document.createElement('div');
            noteC.className = 'xfer-c-note';
            noteC.setAttribute('data-col', 'note');
            var by = document.createElement('div');
            by.className = 'xfer-c-by';
            by.textContent = actorName;
            var actC = document.createElement('div');
            actC.className = 'xfer-c-act';
            actC.setAttribute('data-col', 'act');
            actC.innerHTML = waitHtml;
            compact.appendChild(top);
            compact.appendChild(route);
            compact.appendChild(pack);
            compact.appendChild(noteC);
            compact.appendChild(by);
            compact.appendChild(actC);
            tr.appendChild(compact);
            function fullCell(text, label, col) {
              var td = xferCell(text, label);
              td.className = 'xfer-full';
              if (col) td.setAttribute('data-col', col);
              return td;
            }
            tr.appendChild(fullCell('', <?php echo json_encode($isEn ? 'Date' : 'التاريخ'); ?>, 'when'));
            tr.appendChild(fullCell(fromName, <?php echo json_encode($isEn ? 'From' : 'من'); ?>, ''));
            tr.appendChild(fullCell(toName, <?php echo json_encode($isEn ? 'To' : 'إلى'); ?>, ''));
            tr.appendChild(fullCell(profName, <?php echo json_encode($isEn ? 'Package' : 'الفئة'); ?>, ''));
            tr.appendChild(fullCell(qtyVal, <?php echo json_encode($isEn ? 'Qty' : 'كم'); ?>, ''));
            tr.appendChild(fullCell('', <?php echo json_encode($isEn ? 'Profit' : 'ربح'); ?>, ''));
            tr.appendChild(fullCell('', <?php echo json_encode($isEn ? 'Note' : 'ملاحظة'); ?>, 'note'));
            tr.appendChild(fullCell(actorName, <?php echo json_encode($isEn ? 'By' : 'بواسطة'); ?>, ''));
            var act = document.createElement('td');
            act.className = 'xfer-full';
            act.setAttribute('data-col', 'act');
            act.setAttribute('data-label', <?php echo json_encode($isEn ? 'Action' : 'إجراء'); ?>);
            act.innerHTML = waitHtml;
            tr.appendChild(act);
            if (body.firstChild) body.insertBefore(tr, body.firstChild);
            else body.appendChild(tr);
            var wrap = table.parentNode;
            if (wrap && wrap.style) wrap.style.display = '';
            return tr;
          }
          form.addEventListener('submit', function (e) {
            paintEnds();
            if (!paintQty()) {
              e.preventDefault();
              if (window.appBusyDone) window.appBusyDone();
              return;
            }
            e.preventDefault();
            closeXferSheet();
            if (form.getAttribute('data-busy') === '1') return;
            form.setAttribute('data-busy', '1');
            var row = insertPendingRow();
            var body = new FormData(form);
            body.append('ajax_xfer', '1');
            var x = new XMLHttpRequest();
            x.open('POST', 'cards.php', true);
            x.onload = function () {
              form.removeAttribute('data-busy');
              var data = null;
              try { data = JSON.parse(x.responseText); } catch (err) {}
              if (!data || !data.ok || !data.id) {
                if (row && row.parentNode) row.parentNode.removeChild(row);
                if (window.appBusyDone) window.appBusyDone();
                xferToast((data && data.message) ? data.message : <?php echo json_encode($isEn ? 'Could not save the transfer' : 'ما انسجل التحويل'); ?>);
                return;
              }
              if (row) {
                row.setAttribute('data-id', String(data.id));
                row.setAttribute('data-state', 'pending');
              }
              if (window.cardsXferKick) window.cardsXferKick();
            };
            x.onerror = function () {
              form.removeAttribute('data-busy');
              if (row && row.parentNode) row.parentNode.removeChild(row);
              if (window.appBusyDone) window.appBusyDone();
              xferToast(<?php echo json_encode($isEn ? 'Could not save the transfer' : 'ما انسجل التحويل'); ?>);
            };
            x.send(body);
          }, true);
          function askAvail() {
            var agent = String(fromSel.value || '');
            var name = profile ? profile.value : '';
            if (!agent || !name || agent === String(homeId)) return;
            if (Object.prototype.hasOwnProperty.call(stock, agent)) return;
            if (availAsked === agent) return;
            availAsked = agent;
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'cards.php?ajax=xfer_one&agent=' + encodeURIComponent(agent), true);
            xhr.timeout = 15000;
            xhr.onload = function () {
              var data = null;
              try { data = JSON.parse(xhr.responseText); } catch (err) {}
              if (String(fromSel.value) !== agent) return;
              if (!data || !data.known) {
                availAsked = '';
                availFail[agent] = 1;
                if (availEl) {
                  availEl.classList.remove('is-wait');
                  availEl.textContent = availMiss;
                }
                return;
              }
              stock[agent] = data.bag || {};
              paintQty();
            };
            xhr.onerror = xhr.ontimeout = function () {
              availAsked = '';
              availFail[agent] = 1;
              if (availEl && String(fromSel.value) === agent) {
                availEl.classList.remove('is-wait');
                availEl.textContent = availMiss;
              }
            };
            xhr.send();
          }
          paintEnds();
          paintQty();
          function watchTiles() {
            var root = document.getElementById('cardsRoot');
            if (!root || !window.MutationObserver) {
              paintQty();
              return;
            }
            var wait = null;
            var obs = new MutationObserver(function () {
              if (wait) return;
              wait = setTimeout(function () {
                wait = null;
                paintQty();
              }, 40);
            });
            obs.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-unused'] });
            paintQty();
          }
          if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', watchTiles);
          } else {
            watchTiles();
          }
          var stockXhrStarted = false;
          function ensureStock() {
            if (!stockPending || stockXhrStarted) return;
            if (String(fromSel.value) === String(homeId)) return;
            stockXhrStarted = true;
            var stockXhr = new XMLHttpRequest();
            stockXhr.open('GET', 'cards.php?ajax=xfer_stock', true);
            stockXhr.timeout = 20000;
            stockXhr.onload = function () {
              var data = null;
              try { data = JSON.parse(stockXhr.responseText); } catch (e) {}
              stockPending = false;
              if (data && data.stock) {
                var sk;
                for (sk in data.stock) {
                  if (!Object.prototype.hasOwnProperty.call(data.stock, sk)) continue;
                  if (String(sk) === String(homeId)) continue;
                  stock[sk] = data.stock[sk];
                }
              }
              paintQty();
            };
            stockXhr.onerror = stockXhr.ontimeout = function () {
              stockPending = false;
              paintQty();
            };
            stockXhr.send();
          }
          window.cardXferApplyGroups = function (groups) {
            var bag = {};
            var i, g, name, oi, opt, freeN;
            for (i = 0; i < (groups || []).length; i++) {
              g = groups[i];
              if (!g || !g.name) continue;
              name = String(g.name);
              bag[name] = parseInt(g.unused, 10) || 0;
            }
            if (profile) {
              for (oi = 0; oi < profile.options.length; oi++) {
                opt = profile.options[oi];
                if (!opt.value) continue;
                freeN = null;
                for (i = 0; i < (groups || []).length; i++) {
                  g = groups[i];
                  if (!g || !g.name) continue;
                  if (nameKey(g.name) !== nameKey(opt.value)) continue;
                  freeN = parseInt(g.unused, 10) || 0;
                  break;
                }
                if (freeN !== null) opt.setAttribute('data-free', String(freeN));
              }
            }
            stock[String(homeId)] = bag;
            window.cardXferHomeReady = true;
            paintQty();
          };
          window.cardXferNoteDone = function (fromId, profileName, qtyTaken) {
            var q = parseInt(qtyTaken, 10) || 0;
            var want = nameKey(profileName);
            if (q < 1 || !fromId || !want) {
              paintQty();
              return;
            }
            var key = String(fromId);
            var bag = stock[key] || {};
            var found = null;
            var k;
            for (k in bag) {
              if (!Object.prototype.hasOwnProperty.call(bag, k)) continue;
              if (k === profileName || nameKey(k) === want) {
                found = k;
                break;
              }
            }
            if (found === null) found = profileName;
            var cur = parseInt(bag[found], 10) || 0;
            bag[found] = cur > q ? cur - q : 0;
            stock[key] = bag;
            if (profile) {
              var oi, opt, freeN;
              for (oi = 0; oi < profile.options.length; oi++) {
                opt = profile.options[oi];
                if (nameKey(opt.value) !== want) continue;
                freeN = parseInt(opt.getAttribute('data-free'), 10) || 0;
                opt.setAttribute('data-free', String(freeN > q ? freeN - q : 0));
              }
            }
            paintQty();
          };
          var extraOn = document.getElementById('xferExtraOn');
          var extraBox = document.getElementById('xferExtra');
          var extraWrap = document.getElementById('xferExtraWrap');
          function syncExtra() {
            var notify = document.getElementById('xferNotify');
            var notice = !notify || notify.checked;
            if (extraWrap) extraWrap.hidden = !notice;
            var show = notice && extraOn && extraOn.checked;
            if (extraBox) {
              extraBox.hidden = !show;
              extraBox.disabled = !show;
            }
          }
          var notifyBox = document.getElementById('xferNotify');
          if (notifyBox) notifyBox.addEventListener('change', syncExtra);
          if (extraOn) extraOn.addEventListener('change', syncExtra);
          syncExtra();
          var formParent = form.parentNode;
          var formNext = form.nextSibling;
          var sheetLockY = 0;
          function placeXferSheet() {
            if (!form.classList.contains('is-sheet')) return;
            var vv = window.visualViewport;
            var top = vv ? vv.offsetTop : 0;
            var left = vv ? vv.offsetLeft : 0;
            var width = vv ? vv.width : window.innerWidth;
            var height = vv ? vv.height : window.innerHeight;
            form.style.top = top + 'px';
            form.style.left = left + 'px';
            form.style.width = width + 'px';
            form.style.height = height + 'px';
            form.style.right = 'auto';
            form.style.bottom = 'auto';
          }
          function closeXferSheet() {
            if (document.activeElement && document.activeElement.blur) {
              try { document.activeElement.blur(); } catch (err) {}
            }
            form.classList.remove('is-sheet');
            form.style.top = '';
            form.style.left = '';
            form.style.width = '';
            form.style.height = '';
            form.style.right = '';
            form.style.bottom = '';
            if (formParent && form.parentNode !== formParent) {
              if (formNext && formNext.parentNode === formParent) formParent.insertBefore(form, formNext);
              else formParent.appendChild(form);
            }
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.left = '';
            document.body.style.right = '';
            document.body.style.width = '';
            document.body.style.overflow = '';
            window.scrollTo(0, sheetLockY || 0);
          }
          window.openCardXfer = function (profileName) {
            var want = nameKey(profileName);
            var i, opt;
            if (profile && want) {
              for (i = 0; i < profile.options.length; i++) {
                opt = profile.options[i];
                if (nameKey(opt.value) === want || nameKey(opt.textContent) === want) {
                  profile.selectedIndex = i;
                  break;
                }
              }
              var picked = profile.options[profile.selectedIndex];
              if (profileId) profileId.value = picked ? (picked.getAttribute('data-pid') || '0') : '0';
              var title = document.getElementById('xferSheetTitle');
              if (title && picked && picked.value) title.textContent = xferTrim(picked.textContent);
            }
            if (form.parentNode !== document.body) document.body.appendChild(form);
            sheetLockY = window.pageYOffset || document.documentElement.scrollTop || 0;
            document.body.style.top = (-sheetLockY) + 'px';
            document.body.style.left = '0';
            document.body.style.right = '0';
            document.body.style.width = '100%';
            document.body.style.position = 'fixed';
            document.body.style.overflow = 'hidden';
            form.classList.add('is-sheet');
            placeXferSheet();
            paintQty();
          };
          if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', placeXferSheet);
            window.visualViewport.addEventListener('scroll', placeXferSheet);
          }
          var sheetX = document.getElementById('xferSheetClose');
          if (sheetX) sheetX.addEventListener('click', closeXferSheet);
          form.addEventListener('click', function (e) {
            if (e.target === form) closeXferSheet();
          });
          document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && form.classList.contains('is-sheet')) closeXferSheet();
          });
        })();
        </script>

        <div class="xfer-head">
            <h3><?php echo e($isEn ? 'Recent transfers' : 'آخر التحويلات'); ?></h3>
            <?php if ($recentTransfers): ?>
            <form method="post" style="margin:0" onsubmit="return confirm(<?php echo json_encode($isEn
                ? 'Clear this transfer history? Cards in SAS stay where they are.'
                : 'تمسح سجل التحويل من البوابة؟ الكروت بالساس تبقى بمكانها.'); ?>);">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="clear_transfer_history">
                <button class="btn ghost sm" type="submit"><?php echo e($isEn ? 'Clear history' : 'مسح السجل'); ?></button>
            </form>
            <?php endif; ?>
        </div>
        <div class="table-wrap xfer-scroll">
            <table class="xfer-table" id="xferTable">
                <thead>
                <tr>
                    <th><?php echo e($isEn ? 'Date' : 'التاريخ'); ?></th>
                    <th><?php echo e($isEn ? 'From' : 'من'); ?></th>
                    <th><?php echo e($isEn ? 'To' : 'إلى'); ?></th>
                    <th><?php echo e($isEn ? 'Package' : 'الفئة'); ?></th>
                    <th><?php echo e($isEn ? 'Qty' : 'كم'); ?></th>
                    <th><?php echo e($isEn ? 'Profit' : 'ربح'); ?></th>
                    <th><?php echo e($isEn ? 'Note' : 'ملاحظة'); ?></th>
                    <th><?php echo e($isEn ? 'By' : 'بواسطة'); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php
                $pageIds = array();
                foreach ($recentTransfers as $trIdRow) {
                    $pageIds[] = (int) $trIdRow['id'];
                }
                list($returnedIds, $pendingReturnIds) = function_exists('card_transfer_return_flags')
                    ? card_transfer_return_flags($pdo, $pageIds)
                    : array(array(), array());
                foreach ($recentTransfers as $tr):
                    $fromLbl = $tr['from_name'] ? $tr['from_name'] : ($isEn ? 'Warehouse' : 'مخزن');
                    $profit = card_transfer_profit($tr['wholesale_price'], $tr['agent_price'], $tr['qty']);
                    $isReturn = !empty($tr['return_of_id']);
                    $rowState = cards_xfer_state($tr);
                    ?>
                    <tr data-id="<?php echo (int) $tr['id']; ?>" data-state="<?php echo e($rowState); ?>" data-from="<?php echo (int) $tr['from_agent_id']; ?>" data-qty="<?php echo (int) $tr['qty']; ?>" data-profile="<?php echo e(isset($tr['profile_name']) ? $tr['profile_name'] : ''); ?>">
                        <td class="xfer-compact">
                            <div class="xfer-c-top" data-col="when"><?php echo e(isset($tr['created_at']) ? $tr['created_at'] : ''); ?></div>
                            <div class="xfer-c-mid xfer-c-route" dir="ltr"><?php echo e($fromLbl); ?> → <?php echo e(isset($tr['to_name']) ? $tr['to_name'] : ''); ?></div>
                            <div class="xfer-c-mid xfer-c-pack"><?php echo e(isset($tr['profile_name']) ? $tr['profile_name'] : ''); ?> · <?php echo (int) $tr['qty']; ?> · <?php echo e(money_format_iqd($isReturn ? (0 - $profit) : $profit, $config['currency'])); ?></div>
                            <?php if (!empty($tr['note'])): ?><div class="xfer-c-note" data-col="note"><?php echo e($tr['note']); ?></div><?php endif; ?>
                            <div class="xfer-c-by"><?php echo e(isset($tr['created_by_name']) ? $tr['created_by_name'] : ''); ?></div>
                            <div class="xfer-c-act" data-col="act"><?php echo cards_xfer_action_html($tr, $canTransfer, $homeId, $isEn, $returnedIds, $pendingReturnIds, $xferPer); ?></div>
                        </td>
                        <td class="xfer-full" data-col="when" data-label="<?php echo e($isEn ? 'Date' : 'التاريخ'); ?>"><?php echo e(isset($tr['created_at']) ? $tr['created_at'] : ''); ?></td>
                        <td class="xfer-full" data-label="<?php echo e($isEn ? 'From' : 'من'); ?>"><?php echo e($fromLbl); ?></td>
                        <td class="xfer-full" data-label="<?php echo e($isEn ? 'To' : 'إلى'); ?>"><?php echo e(isset($tr['to_name']) ? $tr['to_name'] : ''); ?></td>
                        <td class="xfer-full" data-label="<?php echo e($isEn ? 'Package' : 'الفئة'); ?>"><?php echo e(isset($tr['profile_name']) ? $tr['profile_name'] : ''); ?><?php if ($isReturn): ?> <span class="meta"><?php echo e($isEn ? 'return' : 'استرجاع'); ?></span><?php endif; ?></td>
                        <td class="xfer-full" data-label="<?php echo e($isEn ? 'Qty' : 'كم'); ?>"><?php echo (int) $tr['qty']; ?></td>
                        <td class="xfer-full" data-label="<?php echo e($isEn ? 'Profit' : 'ربح'); ?>"><?php echo e(money_format_iqd($isReturn ? (0 - $profit) : $profit, $config['currency'])); ?></td>
                        <td class="xfer-full" data-label="<?php echo e($isEn ? 'Note' : 'ملاحظة'); ?>" data-col="note"><?php echo e(isset($tr['note']) ? $tr['note'] : ''); ?></td>
                        <td class="xfer-full" data-label="<?php echo e($isEn ? 'By' : 'بواسطة'); ?>"><?php echo e(isset($tr['created_by_name']) ? $tr['created_by_name'] : ''); ?></td>
                        <td class="xfer-full" data-label="<?php echo e($isEn ? 'Action' : 'إجراء'); ?>" data-col="act"><?php echo cards_xfer_action_html($tr, $canTransfer, $homeId, $isEn, $returnedIds, $pendingReturnIds, $xferPer); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($xferTotal > 0): ?>
        <div class="xfer-pager" id="xferPager">
            <?php
            $prevHref = 'cards.php?xfer_n=' . (int) $xferPer . '&xfer_p=' . max(1, $xferPage - 1) . '#card-transfer';
            $nextHref = 'cards.php?xfer_n=' . (int) $xferPer . '&xfer_p=' . min($xferPages, $xferPage + 1) . '#card-transfer';
            ?>
            <form method="get" action="cards.php#card-transfer" class="xfer-size">
                <label>
                    <span><?php echo e($isEn ? 'Show' : 'عرض'); ?></span>
                    <select name="xfer_n" onchange="this.form.submit()">
                        <?php foreach ($xferPerAllowed as $nOpt): ?>
                        <option value="<?php echo (int) $nOpt; ?>"<?php echo $nOpt === $xferPer ? ' selected' : ''; ?>><?php echo (int) $nOpt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
            <div class="xfer-nav">
            <a class="xfer-arrow<?php echo $xferPage <= 1 ? ' is-off' : ''; ?>" href="<?php echo e($prevHref); ?>" aria-label="<?php echo e($isEn ? 'Previous' : 'السابق'); ?>">‹</a>
            <span class="xfer-page-no"><?php echo (int) $xferPage; ?> / <?php echo (int) $xferPages; ?></span>
            <a class="xfer-arrow<?php echo $xferPage >= $xferPages ? ' is-off' : ''; ?>" href="<?php echo e($nextHref); ?>" aria-label="<?php echo e($isEn ? 'Next' : 'التالي'); ?>">›</a>
            </div>
        </div>
        <?php endif; ?>
        <script>
        (function () {
          var table = document.getElementById('xferTable');
          if (!table) return;
          var per = <?php echo (int) $xferPer; ?>;
          var runId = '';
          function tick() {
            var pending = table.querySelectorAll('tr[data-state="pending"]');
            if (!pending.length) return;
            var all = table.querySelectorAll('tr[data-id]');
            var ids = [];
            for (var i = 0; i < all.length; i++) ids.push(all[i].getAttribute('data-id'));
            var x = new XMLHttpRequest();
            x.open('GET', 'cards.php?ajax=xfer_rows&xfer_n=' + per + '&ids=' + encodeURIComponent(ids.join(',')), true);
            x.onload = function () {
              var data = null;
              try { data = JSON.parse(x.responseText); } catch (e) {}
              if (!data || !data.rows) {
                setTimeout(tick, 2500);
                return;
              }
              for (var k = 0; k < data.rows.length; k++) {
                var r = data.rows[k];
                var tr = table.querySelector('tr[data-id="' + r.id + '"]');
                if (!tr) continue;
                var prev = tr.getAttribute('data-state') || '';
                tr.setAttribute('data-state', r.state || 'done');
                function paintNodes(col, value, asHtml) {
                  var nodes = tr.querySelectorAll('[data-col="' + col + '"]');
                  var i;
                  for (i = 0; i < nodes.length; i++) {
                    if (asHtml) nodes[i].innerHTML = value || '';
                    else if (nodes[i].textContent !== (value || '')) nodes[i].textContent = value || '';
                  }
                }
                if (r.when) paintNodes('when', r.when, false);
                paintNodes('note', r.note || '', false);
                if (r.state !== 'pending') paintNodes('act', r.action || '', true);
                if (prev === 'pending' && r.state && r.state !== 'pending') {
                  if (r.state === 'done' && window.cardXferNoteDone) {
                    window.cardXferNoteDone(tr.getAttribute('data-from'), tr.getAttribute('data-profile'), tr.getAttribute('data-qty'));
                  }
                  if (r.state === 'done' && window.cardsBumpWarehouse) window.cardsBumpWarehouse(tr);
                  if (window.cardsLiveSync) window.cardsLiveSync();
                }
              }
              if (table.querySelector('tr[data-state="pending"]')) {
                setTimeout(tick, 2000);
                kick();
              }
            };
            x.onerror = function () { setTimeout(tick, 3000); };
            x.send();
          }
          function kick() {
            if (runId) return;
            var row = table.querySelector('tr[data-state="pending"]');
            if (!row) return;
            var id = row.getAttribute('data-id') || '';
            if (!id) return;
            runId = id;
            var x = new XMLHttpRequest();
            x.open('GET', 'cards.php?ajax=xfer_run&id=' + encodeURIComponent(id), true);
            x.timeout = 90000;
            function done() {
              if (window.appBusyDone) window.appBusyDone();
              if (runId === id) runId = '';
              setTimeout(tick, 700);
            }
            x.onload = done;
            x.onerror = done;
            x.ontimeout = done;
            x.send();
          }
          kick();
          window.cardsXferKick = function () {
            if (!runId) kick();
          };
        })();
        </script>
    </div>
    <?php endif; ?>
    <?php if (!$cardsOwnStockOnly): ?>
    <div class="cards-block">
    <h2 class="cards-section-title"><?php echo e($isEn ? 'Cards' : 'الكروت الموجودة'); ?></h2>
    <p class="cards-sync<?php echo ($sasReady && $err) ? ' is-busy' : ''; ?>" id="cardsSync"><?php
        if ($sasReady && $err) {
            echo e($err);
        }
    ?></p>
    <?php if ($canTransfer): ?>
    <p class="cards-swipe-hint"><?php echo e($isEn ? 'Swipe a package or tap Transfer, then pick the quantity and the agent.' : 'اسحب الفئة أو اضغط تحويل، ثم اختر العدد والوكيل.'); ?></p>
    <?php endif; ?>
    <div id="cardsRoot">
    <?php if (!$sasReady && !$groups): ?>
        <p style="font-weight:700;color:#64748b" id="cardsEmpty"><?php echo e($isEn ? 'This account has no SAS or reseller, so cards are not synced.' : 'هذا الحساب ما مربوط بساس أو ريسلر، فماكو مزامنة كروت.'); ?></p>
    <?php elseif ($err && !$groups): ?>
        <p style="color:#dd4b39;font-weight:700"><?php echo e($err); ?></p>
    <?php elseif (!$groups): ?>
        <p style="font-weight:700;color:#64748b" id="cardsEmpty"><?php echo e($isEn ? 'Loading…' : 'جاري التحميل…'); ?></p>
    <?php else: ?>
        <div class="cards-wids" id="cardsWids">
            <?php
            $cardTones = array('tone-blue', 'tone-green', 'tone-red', 'tone-yellow', 'tone-teal', 'tone-navy', 'tone-lime', 'tone-purple', 'tone-aqua');
            foreach ($groups as $wi => $wg):
                $wName = isset($wg['name']) ? (string) $wg['name'] : '';
                $wUnused = isset($wg['unused']) ? (int) $wg['unused'] : 0;
                $wUsed = isset($wg['used']) ? (int) $wg['used'] : 0;
                $wTotal = isset($wg['total']) ? (int) $wg['total'] : 0;
                $wTone = $cardTones[$wi % count($cardTones)];
                ?>
            <button type="button" class="sas-box <?php echo e($wTone); ?>" data-wid="<?php echo (int) $wi; ?>" data-total="<?php echo $wTotal; ?>" data-used="<?php echo $wUsed; ?>" data-unused="<?php echo $wUnused; ?>">
                <span class="sas-box-title"><?php echo e($wName !== '' ? $wName : '—'); ?></span>
                <span class="sas-box-sub"><?php echo e($isEn ? 'Available' : 'متوفر'); ?></span>
                <span class="sas-box-val"><?php echo $wUnused; ?></span>
                <?php if ($canTransfer): ?><span class="card-xfer-go"><?php echo e($isEn ? 'Transfer' : 'تحويل'); ?></span><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="cards-summary" id="cardsSummary">
            <span class="sum-pill"><?php echo e($isEn ? 'Categories' : 'فئات'); ?>: <span data-sum="cats"><?php echo count($groups); ?></span></span>
            <span class="sum-pill"><?php echo e($isEn ? 'Total' : 'الكل'); ?>: <span data-sum="total"><?php echo (int) $sumTotal; ?></span></span>
            <span class="sum-pill ok"><?php echo e($isEn ? 'Unused' : 'شاغر'); ?>: <span data-sum="unused"><?php echo (int) $sumUnused; ?></span></span>
            <span class="sum-pill bad"><?php echo e($isEn ? 'Used' : 'مستخدم'); ?>: <span data-sum="used"><?php echo (int) $sumUsed; ?></span></span>
        </div>
        <input type="search" id="cardsSearch" class="cards-search" placeholder="<?php echo e($isEn ? 'Search pin / username / name…' : 'بحث برقم الكارت أو اليوزر أو الاسم…'); ?>">
        <div class="filter-row" id="cardsFilter">
            <button type="button" class="is-on" data-f="all"><?php echo e($isEn ? 'All packages' : 'كل الفئات'); ?></button>
            <button type="button" data-f="free"><?php echo e($isEn ? 'With free cards' : 'فيها شواغر'); ?></button>
            <button type="button" data-f="expand"><?php echo e($isEn ? 'Expand all' : 'فتح الكل'); ?></button>
            <button type="button" data-f="collapse"><?php echo e($isEn ? 'Collapse' : 'طي الكل'); ?></button>
            <button type="button" data-f="refresh"><?php echo e($isEn ? 'Refresh' : 'تحديث'); ?></button>
        </div>
        <div id="cardsList">
        <?php foreach ($groups as $gi => $g): ?>
            <?php
            $gName = isset($g['name']) ? (string) $g['name'] : '';
            $total = isset($g['total']) ? (int) $g['total'] : 0;
            $used = isset($g['used']) ? (int) $g['used'] : 0;
            $unused = isset($g['unused']) ? (int) $g['unused'] : 0;
            $cards = (isset($g['cards']) && is_array($g['cards'])) ? $g['cards'] : array();
            $freeCards = array();
            $usedCards = array();
            foreach ($cards as $c) {
                if (!empty($c['used'])) {
                    $usedCards[] = $c;
                } else {
                    $freeCards[] = $c;
                }
            }
            ?>
            <div class="cat-block" data-cat data-wid="<?php echo (int) $gi; ?>" data-gi="<?php echo (int) $gi; ?>" data-lazy="<?php echo $cardsLazy ? '1' : '0'; ?>" data-has-free="<?php echo $unused > 0 ? '1' : '0'; ?>">
                <button type="button" class="cat-head" data-toggle-cat>
                    <h3>
                        <span class="cat-chevron">›</span>
                        <?php echo e($gName !== '' ? $gName : '—'); ?>
                    </h3>
                    <div class="cat-meta">
                        <span class="pill"><?php echo (int) $total; ?></span>
                        <span class="pill ok"><?php echo e($isEn ? 'Free' : 'شاغر'); ?> <?php echo (int) $unused; ?></span>
                        <span class="pill bad"><?php echo e($isEn ? 'Used' : 'مستخدم'); ?> <?php echo (int) $used; ?></span>
                    </div>
                </button>
                <div class="cat-body">
                    <?php if ($cardsLazy): ?>
                        <div class="empty-cat" data-lazy-slot><?php echo e($isEn ? 'Open to load cards' : 'افتح الفئة لعرض الكروت'); ?></div>
                    <?php elseif (!$freeCards && !$usedCards): ?>
                        <div class="empty-cat"><?php echo e($isEn ? 'Details load on sync…' : 'التفاصيل تكتمل مع المزامنة…'); ?></div>
                    <?php else: ?>
                        <?php if ($freeCards): ?>
                            <div class="chip-grid" data-free-grid>
                                <?php foreach ($freeCards as $c):
                                    $pin = isset($c['pin']) ? (string) $c['pin'] : '';
                                    $hay = strtolower(trim($pin));
                                    ?>
                                    <div class="chip free" data-used="0" data-search="<?php echo e($hay); ?>" data-copy="<?php echo e($pin); ?>">
                                        <div class="chip-top">
                                            <div class="chip-main">
                                                <div class="pin-row"><span class="pin"><?php echo e($pin); ?></span></div>
                                            </div>
                                            <button type="button" class="chip-copy" data-copy-btn title="<?php echo e($isEn ? 'Copy' : 'نسخ'); ?>"><?php echo e($isEn ? 'Copy' : 'نسخ'); ?></button>
                                        </div>
                                        <span class="st free"><?php echo e($isEn ? 'Available' : 'شاغر'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-cat"><?php echo e($isEn ? 'No free cards' : 'ماكو كروت شاغرة'); ?></div>
                        <?php endif; ?>
                        <?php if ($usedCards): ?>
                            <div class="used-fold" data-used-fold>
                                <button type="button" class="used-fold-btn" data-toggle-used>
                                    <span><?php echo e($isEn ? 'Used cards' : 'الكروت المستخدمة'); ?> (<?php echo count($usedCards); ?>)</span>
                                    <span class="used-fold-chevron">›</span>
                                </button>
                                <div class="used-fold-body">
                                    <div class="chip-grid chip-grid-used">
                                        <?php foreach ($usedCards as $c):
                                            $pin = isset($c['pin']) ? (string) $c['pin'] : '';
                                            $usedBy = !empty($c['used_by']) ? (string) $c['used_by'] : '';
                                            $usedAt = !empty($c['used_at']) ? (string) $c['used_at'] : '';
                                            $hay = strtolower(trim($pin . ' ' . $usedBy . ' ' . $usedAt));
                                            $copyText = trim($pin . ($usedBy !== '' ? (' ' . $usedBy) : ''));
                                            $userHref = !empty($c['user_href']) ? (string) $c['user_href'] : '';
                                            ?>
                                            <div class="chip used" data-used="1" data-search="<?php echo e($hay); ?>" data-copy="<?php echo e($copyText); ?>">
                                                <div class="chip-top">
                                                    <div class="chip-main">
                                                        <div class="pin-row">
                                                            <span class="pin"><?php echo e($pin); ?></span>
                                                            <?php if ($usedBy !== '' && $userHref !== ''): ?>
                                                                <span class="by-line"><a class="by-link" href="<?php echo e($userHref); ?>" title="<?php echo e($isEn ? 'Open subscriber activations' : 'فتح تفعيلات المشترك'); ?>"><?php echo e($usedBy); ?></a></span>
                                                            <?php else: ?>
                                                                <span class="by-line"><?php echo e($usedBy !== '' ? $usedBy : '—'); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <button type="button" class="chip-copy" data-copy-btn title="<?php echo e($isEn ? 'Copy' : 'نسخ'); ?>"><?php echo e($isEn ? 'Copy' : 'نسخ'); ?></button>
                                                </div>
                                                <?php if ($usedAt !== ''): ?>
                                                    <div class="st"><?php echo e($usedAt); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
  var isEn = <?php echo $isEn ? 'true' : 'false'; ?>;
  var canTransfer = <?php echo !empty($canTransfer) ? 'true' : 'false'; ?>;
  var ownStockOnly = <?php echo $cardsOwnStockOnly ? 'true' : 'false'; ?>;
  var sasReady = <?php echo !empty($sasReady) ? 'true' : 'false'; ?>;
  var syncEl = document.getElementById('cardsSync');
  var root = document.getElementById('cardsRoot');

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function paintFastCounts(groups) {
    var boxes = document.querySelectorAll('#cardsWids .sas-box');
    if (!boxes.length) return false;
    var byName = {};
    var sumTotal = 0, sumUsed = 0, sumUnused = 0;
    groups.forEach(function (g) {
      if (!g || !g.name) return;
      byName[String(g.name).replace(/^\s+|\s+$/g, '').toLowerCase()] = g;
      sumTotal += g.total || 0;
      sumUsed += g.used || 0;
      sumUnused += g.unused || 0;
    });
    var i, title, key, g, val;
    for (i = 0; i < boxes.length; i++) {
      title = boxes[i].querySelector('.sas-box-title');
      key = title ? String(title.textContent || '').replace(/^\s+|\s+$/g, '').toLowerCase() : '';
      g = byName[key];
      if (!g) continue;
      boxes[i].setAttribute('data-unused', String(g.unused || 0));
      boxes[i].setAttribute('data-used', String(g.used || 0));
      boxes[i].setAttribute('data-total', String(g.total || 0));
      val = boxes[i].querySelector('.sas-box-val');
      if (val) val.textContent = String(g.unused || 0);
    }
    function setSum(name, n) {
      var el = document.querySelector('#cardsSummary [data-sum="' + name + '"]');
      if (el) el.textContent = String(n);
    }
    setSum('total', sumTotal);
    setSum('used', sumUsed);
    setSum('unused', sumUnused);
    return true;
  }

  function renderGroups(groups) {
    if (!root) return;
    groups = groups || [];
    var sumTotal = 0, sumUsed = 0, sumUnused = 0;
    groups.forEach(function (g) {
      sumTotal += g.total || 0;
      sumUsed += g.used || 0;
      sumUnused += g.unused || 0;
    });
    if (!groups.length) {
      root.innerHTML = '<p style="font-weight:700;color:#64748b">' + (isEn ? 'No cards found' : 'ماكو كروت') + '</p>';
      return;
    }
    var html = '';
    var tones = ['tone-blue','tone-green','tone-red','tone-yellow','tone-teal','tone-navy','tone-lime','tone-purple','tone-aqua'];
    html += '<div class="cards-wids" id="cardsWids">';
    groups.forEach(function (g, wi) {
      var tone = tones[wi % tones.length];
      html += '<button type="button" class="sas-box ' + tone + '" data-wid="' + wi + '" data-total="' + (g.total || 0) + '" data-used="' + (g.used || 0) + '" data-unused="' + (g.unused || 0) + '">';
      html += '<span class="sas-box-title">' + esc(g.name || '—') + '</span>';
      html += '<span class="sas-box-sub">' + (isEn ? 'Available' : 'متوفر') + '</span>';
      html += '<span class="sas-box-val">' + (g.unused || 0) + '</span>';
      if (canTransfer) html += '<span class="card-xfer-go">' + (isEn ? 'Transfer' : 'تحويل') + '</span>';
      html += '</button>';
    });
    html += '</div>';
    html += '<div class="cards-summary" id="cardsSummary">';
    html += '<span class="sum-pill">' + (isEn ? 'Categories' : 'فئات') + ': <span data-sum="cats">' + groups.length + '</span></span>';
    html += '<span class="sum-pill">' + (isEn ? 'Total' : 'الكل') + ': <span data-sum="total">' + sumTotal + '</span></span>';
    html += '<span class="sum-pill ok">' + (isEn ? 'Unused' : 'شاغر') + ': <span data-sum="unused">' + sumUnused + '</span></span>';
    html += '<span class="sum-pill bad">' + (isEn ? 'Used' : 'مستخدم') + ': <span data-sum="used">' + sumUsed + '</span></span>';
    html += '</div>';
    html += '<input type="search" id="cardsSearch" class="cards-search" placeholder="' + (isEn ? 'Search…' : 'بحث…') + '">';
    html += '<div class="filter-row" id="cardsFilter">';
    html += '<button type="button" class="is-on" data-f="all">' + (isEn ? 'All packages' : 'كل الفئات') + '</button>';
    html += '<button type="button" data-f="free">' + (isEn ? 'With free cards' : 'فيها شواغر') + '</button>';
    html += '<button type="button" data-f="expand">' + (isEn ? 'Expand all' : 'فتح الكل') + '</button>';
    html += '<button type="button" data-f="collapse">' + (isEn ? 'Collapse' : 'طي الكل') + '</button>';
    html += '<button type="button" data-f="refresh">' + (isEn ? 'Refresh' : 'تحديث') + '</button>';
    html += '</div><div id="cardsList">';
    groups.forEach(function (g, gi) {
      var free = [], used = [];
      (g.cards || []).forEach(function (c) { (c.used ? used : free).push(c); });
      html += '<div class="cat-block" data-cat data-wid="' + gi + '" data-has-free="' + ((g.unused || 0) > 0 ? '1' : '0') + '">';
      html += '<button type="button" class="cat-head" data-toggle-cat><h3><span class="cat-chevron">›</span> ' + esc(g.name || '—') + '</h3>';
      html += '<div class="cat-meta"><span class="pill">' + (g.total || 0) + '</span>';
      html += '<span class="pill ok">' + (isEn ? 'Free' : 'شاغر') + ' ' + (g.unused || 0) + '</span>';
      html += '<span class="pill bad">' + (isEn ? 'Used' : 'مستخدم') + ' ' + (g.used || 0) + '</span></div></button>';
      html += '<div class="cat-body">';
      if (!free.length && !used.length) {
        html += '<div class="empty-cat">' + (isEn ? 'No cards in this package' : 'لا توجد كروت لهذه الباقة') + '</div>';
      } else {
        if (free.length) {
          html += '<div class="chip-grid">';
          free.forEach(function (c) {
            var pin = c.pin || '';
            html += '<div class="chip free" data-used="0" data-search="' + esc(String(pin).toLowerCase()) + '" data-copy="' + esc(pin) + '">';
            html += '<div class="chip-top"><div class="chip-main"><div class="pin-row"><span class="pin">' + esc(pin) + '</span></div></div>';
            html += '<button type="button" class="chip-copy" data-copy-btn>' + (isEn ? 'Copy' : 'نسخ') + '</button></div>';
            html += '<span class="st free">' + (isEn ? 'Available' : 'شاغر') + '</span></div>';
          });
          html += '</div>';
        } else {
          html += '<div class="empty-cat">' + (isEn ? 'No free cards' : 'ماكو كروت شاغرة') + '</div>';
        }
        if (used.length) {
          html += '<div class="used-fold" data-used-fold><button type="button" class="used-fold-btn" data-toggle-used><span>' + (isEn ? 'Used cards' : 'الكروت المستخدمة') + ' (' + used.length + ')</span><span class="used-fold-chevron">›</span></button><div class="used-fold-body"><div class="chip-grid chip-grid-used">';
          used.forEach(function (c) {
            var pin = c.pin || '';
            var by = c.used_by || '';
            var href = c.user_href || '';
            var copyTxt = String(pin) + (by ? (' ' + by) : '');
            html += '<div class="chip used" data-used="1" data-search="' + esc((pin + ' ' + by + ' ' + (c.used_at || '')).toLowerCase()) + '" data-copy="' + esc(copyTxt) + '">';
            html += '<div class="chip-top"><div class="chip-main"><div class="pin-row"><span class="pin">' + esc(pin) + '</span>';
            if (by && href) {
              html += '<span class="by-line"><a class="by-link" href="' + esc(href) + '">' + esc(by) + '</a></span>';
            } else {
              html += '<span class="by-line">' + esc(by || '—') + '</span>';
            }
            html += '</div></div>';
            html += '<button type="button" class="chip-copy" data-copy-btn>' + (isEn ? 'Copy' : 'نسخ') + '</button></div>';
            if (c.used_at) html += '<div class="st">' + esc(c.used_at) + '</div>';
            html += '</div>';
          });
          html += '</div></div></div>';
        }
      }
      html += '</div></div>';
    });
    html += '</div>';
    root.innerHTML = html;
    bindUi();
  }

  function catBodyHtml(g) {
    g = g || {};
    var free = [], used = [];
    (g.cards || []).forEach(function (c) { (c.used ? used : free).push(c); });
    var html = '';
    if (!free.length && !used.length) {
      return '<div class="empty-cat">' + (isEn ? 'No cards in this package' : 'لا توجد كروت لهذه الباقة') + '</div>';
    }
    if (free.length) {
      html += '<div class="chip-grid" data-free-grid>';
      free.forEach(function (c) {
        var pin = c.pin || '';
        html += '<div class="chip free" data-used="0" data-search="' + esc(String(pin).toLowerCase()) + '" data-copy="' + esc(pin) + '">';
        html += '<div class="chip-top"><div class="chip-main"><div class="pin-row"><span class="pin">' + esc(pin) + '</span></div></div>';
        html += '<button type="button" class="chip-copy" data-copy-btn>' + (isEn ? 'Copy' : 'نسخ') + '</button></div>';
        html += '<span class="st free">' + (isEn ? 'Available' : 'شاغر') + '</span></div>';
      });
      html += '</div>';
    } else {
      html += '<div class="empty-cat">' + (isEn ? 'No free cards' : 'ماكو كروت شاغرة') + '</div>';
    }
    if (used.length) {
      html += '<div class="used-fold" data-used-fold><button type="button" class="used-fold-btn" data-toggle-used><span>' + (isEn ? 'Used cards' : 'الكروت المستخدمة') + ' (' + used.length + ')</span><span class="used-fold-chevron">›</span></button><div class="used-fold-body"><div class="chip-grid chip-grid-used">';
      used.forEach(function (c) {
        var pin = c.pin || '';
        var by = c.used_by || '';
        var href = c.user_href || '';
        var copyTxt = String(pin) + (by ? (' ' + by) : '');
        html += '<div class="chip used" data-used="1" data-search="' + esc((pin + ' ' + by + ' ' + (c.used_at || '')).toLowerCase()) + '" data-copy="' + esc(copyTxt) + '">';
        html += '<div class="chip-top"><div class="chip-main"><div class="pin-row"><span class="pin">' + esc(pin) + '</span>';
        if (by && href) {
          html += '<span class="by-line"><a class="by-link" href="' + esc(href) + '">' + esc(by) + '</a></span>';
        } else {
          html += '<span class="by-line">' + esc(by || '—') + '</span>';
        }
        html += '</div></div>';
        html += '<button type="button" class="chip-copy" data-copy-btn>' + (isEn ? 'Copy' : 'نسخ') + '</button></div>';
        if (c.used_at) html += '<div class="st">' + esc(c.used_at) + '</div>';
        html += '</div>';
      });
      html += '</div></div></div>';
    }
    return html;
  }

  function copyText(txt, btn) {
    txt = String(txt || '');
    if (!txt) return;
    var done = function () {
      if (!btn) return;
      var old = btn.textContent;
      btn.textContent = isEn ? 'Copied' : 'تم';
      btn.classList.add('is-ok');
      setTimeout(function () {
        btn.textContent = old;
        btn.classList.remove('is-ok');
      }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(txt).then(done).catch(function () {
        try {
          var ta = document.createElement('textarea');
          ta.value = txt;
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
          done();
        } catch (e) {}
      });
    } else {
      try {
        var ta2 = document.createElement('textarea');
        ta2.value = txt;
        document.body.appendChild(ta2);
        ta2.select();
        document.execCommand('copy');
        document.body.removeChild(ta2);
        done();
      } catch (e2) {}
    }
  }

  function bindCatBits(scope) {
    if (!scope) return;
    scope.querySelectorAll('[data-toggle-used]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var fold = btn.closest('[data-used-fold]');
        if (fold) fold.classList.toggle('is-open');
      });
    });
    scope.querySelectorAll('[data-copy-btn]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var chip = btn.closest('.chip');
        copyText(chip ? chip.getAttribute('data-copy') : '', btn);
      });
    });
    scope.querySelectorAll('.chip .pin').forEach(function (el) {
      el.addEventListener('click', function (e) {
        if (e.target && e.target.closest && e.target.closest('a')) return;
        e.stopPropagation();
        try {
          var range = document.createRange();
          range.selectNodeContents(el);
          var sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(range);
        } catch (err) {}
      });
    });
  }

  function ensureCat(block) {
    if (!block || block.getAttribute('data-lazy') !== '1' || block.getAttribute('data-loaded') === '1' || block.getAttribute('data-loading') === '1') {
      return;
    }
    block.setAttribute('data-loading', '1');
    var slot = block.querySelector('[data-lazy-slot]');
    if (slot) slot.textContent = isEn ? 'Loading…' : 'جاري التحميل…';
    var i = block.getAttribute('data-gi') || '0';
    fetch('cards.php?ajax=cat&i=' + encodeURIComponent(i), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        block.setAttribute('data-loaded', '1');
        block.removeAttribute('data-loading');
        var body = block.querySelector('.cat-body');
        if (!body) return;
        body.innerHTML = catBodyHtml((d && d.group) ? d.group : { cards: [] });
        bindCatBits(body);
      })
      .catch(function () {
        block.removeAttribute('data-loading');
        if (slot) slot.textContent = isEn ? 'Failed to load' : 'تعذر التحميل';
      });
  }

  function bindUi() {
    var list = document.getElementById('cardsList') || root;
    if (!list) return;
    list.querySelectorAll('[data-toggle-cat]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var block = btn.closest('[data-cat]');
        if (!block) return;
        block.classList.toggle('is-open');
        if (block.classList.contains('is-open')) ensureCat(block);
      });
    });
    bindCatBits(list);
    var wids = document.getElementById('cardsWids');
    if (wids && canTransfer && window.openCardXfer && wids.getAttribute('data-swipe') !== '1') {
      wids.setAttribute('data-swipe', '1');
      var drag = null;
      wids.addEventListener('pointerdown', function (e) {
        var box = e.target.closest ? e.target.closest('.sas-box') : null;
        if (!box || !wids.contains(box) || e.button > 0) return;
        if (e.target.closest && e.target.closest('.card-xfer-go')) return;
        drag = { el: box, x: e.clientX, y: e.clientY, id: e.pointerId, moved: false };
      });
      wids.addEventListener('pointermove', function (e) {
        if (!drag || e.pointerId !== drag.id) return;
        var dx = e.clientX - drag.x;
        var dy = e.clientY - drag.y;
        if (!drag.moved && Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 8) {
          drag.el.classList.remove('is-dragging');
          drag.el.style.transform = '';
          drag = null;
          return;
        }
        if (Math.abs(dx) < 10) return;
        drag.moved = true;
        drag.el.classList.add('is-dragging');
        var shift = dx > 84 ? 84 : (dx < -84 ? -84 : dx);
        drag.el.style.transform = 'translateX(' + shift + 'px)';
      });
      function endDrag(e) {
        if (!drag || !e || e.pointerId !== drag.id) return;
        var dx = e.clientX - drag.x;
        var el = drag.el;
        var title = el.querySelector('.sas-box-title');
        el.classList.remove('is-dragging');
        el.style.transform = '';
        if (Math.abs(dx) > 72) {
          el.setAttribute('data-swiped', '1');
          window.openCardXfer(title ? title.textContent : '');
        }
        drag = null;
      }
      wids.addEventListener('pointerup', endDrag);
      wids.addEventListener('pointercancel', function (e) {
        if (!drag || (e && e.pointerId !== drag.id)) return;
        drag.el.classList.remove('is-dragging');
        drag.el.style.transform = '';
        drag = null;
      });
    }
    if (wids) {
      wids.addEventListener('click', function (e) {
        var go = e.target.closest ? e.target.closest('.card-xfer-go') : null;
        var b = e.target.closest ? e.target.closest('[data-wid]') : null;
        if (!b || !wids.contains(b)) return;
        if (go && canTransfer && window.openCardXfer) {
          e.preventDefault();
          var goTitle = b.querySelector('.sas-box-title');
          window.openCardXfer(goTitle ? goTitle.textContent : '');
          return;
        }
        if (b.getAttribute('data-swiped') === '1') {
          b.removeAttribute('data-swiped');
          e.preventDefault();
          return;
        }
        var id = b.getAttribute('data-wid');
        var already = b.classList.contains('is-on');
        wids.querySelectorAll('[data-wid]').forEach(function (x) { x.classList.remove('is-on'); });
        var sum = document.getElementById('cardsSummary');
        function setSum(k, v) {
          if (!sum) return;
          var el = sum.querySelector('[data-sum="' + k + '"]');
          if (el) el.textContent = String(v);
        }
        function totalsFromWids() {
          var t = 0, u = 0, n = 0, c = 0;
          wids.querySelectorAll('[data-wid]').forEach(function (x) {
            c++;
            t += parseInt(x.getAttribute('data-total'), 10) || 0;
            u += parseInt(x.getAttribute('data-used'), 10) || 0;
            n += parseInt(x.getAttribute('data-unused'), 10) || 0;
          });
          setSum('cats', c);
          setSum('total', t);
          setSum('used', u);
          setSum('unused', n);
        }
        if (already) {
          list.querySelectorAll('[data-cat]').forEach(function (el) { el.style.display = ''; });
          totalsFromWids();
          return;
        }
        b.classList.add('is-on');
        list.querySelectorAll('[data-cat]').forEach(function (el) {
          var on = el.getAttribute('data-wid') === id;
          el.style.display = on ? '' : 'none';
          if (on) el.classList.add('is-open');
        });
        setSum('cats', '1');
        setSum('total', b.getAttribute('data-total') || '0');
        setSum('used', b.getAttribute('data-used') || '0');
        setSum('unused', b.getAttribute('data-unused') || '0');
        var target = list.querySelector('[data-cat][data-wid="' + id + '"]');
        if (target) {
          ensureCat(target);
          if (target.scrollIntoView) target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      });
    }
    var filter = document.getElementById('cardsFilter');
    if (filter) {
      filter.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-f]');
        if (!b) return;
        var f = b.getAttribute('data-f');
        if (f === 'refresh') { sync(true, false); return; }
        if (f === 'expand') {
          list.querySelectorAll('[data-cat]').forEach(function (el) {
            el.classList.add('is-open');
            ensureCat(el);
          });
          return;
        }
        if (f === 'collapse') {
          list.querySelectorAll('[data-cat]').forEach(function (el) { el.classList.remove('is-open'); });
          return;
        }
        filter.querySelectorAll('button[data-f="all"],button[data-f="free"]').forEach(function (x) { x.classList.remove('is-on'); });
        b.classList.add('is-on');
        var widsNow = document.getElementById('cardsWids');
        if (f === 'all' && widsNow) {
          widsNow.querySelectorAll('[data-wid]').forEach(function (x) { x.classList.remove('is-on'); });
          var sum = document.getElementById('cardsSummary');
          var t = 0, u = 0, n = 0, c = 0;
          widsNow.querySelectorAll('[data-wid]').forEach(function (x) {
            c++;
            t += parseInt(x.getAttribute('data-total'), 10) || 0;
            u += parseInt(x.getAttribute('data-used'), 10) || 0;
            n += parseInt(x.getAttribute('data-unused'), 10) || 0;
          });
          function put(k, v) {
            if (!sum) return;
            var el = sum.querySelector('[data-sum="' + k + '"]');
            if (el) el.textContent = String(v);
          }
          put('cats', c);
          put('total', t);
          put('used', u);
          put('unused', n);
        }
        list.querySelectorAll('[data-cat]').forEach(function (el) {
          if (f === 'free') el.style.display = el.getAttribute('data-has-free') === '1' ? '' : 'none';
          else el.style.display = '';
        });
      });
    }
    var search = document.getElementById('cardsSearch');
    if (search) {
      search.addEventListener('input', function () {
        var q = (search.value || '').toLowerCase().trim();
        list.querySelectorAll('.chip').forEach(function (chip) {
          var hay = chip.getAttribute('data-search') || '';
          chip.style.display = (!q || hay.indexOf(q) >= 0) ? '' : 'none';
        });
      });
    }
  }

  var syncBusy = false;
  var syncGen = 0;
  function paintCount(el, delta) {
    if (!el) return;
    var n = (parseInt(String(el.textContent).replace(/[^0-9-]/g, ''), 10) || 0) + delta;
    if (n < 0) n = 0;
    if (/[0-9]/.test(el.textContent || '')) el.textContent = String(el.textContent).replace(/[0-9]+/, String(n));
    else el.textContent = String(n);
  }
  window.cardsBumpWarehouse = function (tr) {
    if (!tr || !tr.cells || tr.cells.length < 5) return;
    var fromName = (tr.cells[1].textContent || '').replace(/^\s+|\s+$/g, '');
    var toName = (tr.cells[2].textContent || '').replace(/^\s+|\s+$/g, '');
    var profile = (tr.cells[3].textContent || '').replace(/استرجاع|return/ig, '').replace(/^\s+|\s+$/g, '');
    var qty = parseInt(tr.cells[4].textContent, 10) || 0;
    var homeBtn = document.getElementById('xferFromBtn');
    var homeName = homeBtn ? (homeBtn.textContent || '').replace(/^\s+|\s+$/g, '') : '';
    var delta = 0;
    if (!profile || qty <= 0 || !homeName) return;
    if (toName === homeName) delta = qty;
    else if (fromName === homeName) delta = -qty;
    if (!delta) return;
    var want = profile.toLowerCase();
    var boxes = document.querySelectorAll('#cardsRoot .sas-box');
    var i;
    for (i = 0; i < boxes.length; i++) {
      var title = boxes[i].querySelector('.sas-box-title');
      var val = boxes[i].querySelector('.sas-box-val');
      if (!title || !val) continue;
      if ((title.textContent || '').replace(/^\s+|\s+$/g, '').toLowerCase() !== want) continue;
      paintCount(val, delta);
      var unused = (parseInt(boxes[i].getAttribute('data-unused'), 10) || 0) + delta;
      var total = (parseInt(boxes[i].getAttribute('data-total'), 10) || 0) + delta;
      if (unused < 0) unused = 0;
      if (total < 0) total = 0;
      boxes[i].setAttribute('data-unused', String(unused));
      boxes[i].setAttribute('data-total', String(total));
    }
    paintCount(document.querySelector('#cardsRoot [data-sum="unused"]'), delta);
    paintCount(document.querySelector('#cardsRoot [data-sum="total"]'), delta);
    var heads = document.querySelectorAll('#cardsRoot .cat-head h3');
    for (i = 0; i < heads.length; i++) {
      var htxt = (heads[i].textContent || '').replace(/›/g, '').replace(/^\s+|\s+$/g, '').toLowerCase();
      if (htxt !== want) continue;
      var block = heads[i].closest ? heads[i].closest('[data-cat]') : null;
      if (!block) continue;
      var pills = block.querySelectorAll('.cat-meta .pill');
      if (pills[0]) paintCount(pills[0], delta);
      if (pills[1]) paintCount(pills[1], delta);
    }
  };
  window.cardsLiveSync = function () {
    if (syncBusy) {
      window.cardsLiveSyncSoon = true;
      return;
    }
    sync(true, true);
  };
  function sync(force, quiet) {
    if (!sasReady) {
      return;
    }
    if (syncBusy && quiet) {
      return;
    }
    var gen = ++syncGen;
    syncBusy = true;
    if (!quiet && syncEl) {
      syncEl.classList.add('is-busy');
      syncEl.textContent = isEn ? 'Syncing with SAS…' : 'جاري المزامنة مع الساس…';
    }
    if (!quiet && window.appBusySet) window.appBusySet({ force: true });
    var timer = setTimeout(function () {
      if (gen !== syncGen) return;
      syncBusy = false;
      if (window.cardsLiveSyncSoon) {
        window.cardsLiveSyncSoon = false;
        sync(true, true);
        return;
      }
      if (!quiet && window.appBusyDone) window.appBusyDone();
      if (!quiet && syncEl) {
        syncEl.classList.remove('is-busy');
        syncEl.textContent = isEn ? 'Sync failed' : 'فشلت المزامنة';
      }
    }, quiet ? 18000 : 40000);
    if (sync._ac && sync._ac.abort) {
      try { sync._ac.abort(); } catch (eAb) {}
    }
    var opts = { credentials: 'same-origin', cache: 'no-store' };
    if (window.AbortController) {
      sync._ac = new AbortController();
      opts.signal = sync._ac.signal;
    }
    fetch('cards.php?ajax=inventory' + (force ? '&refresh=1' : ''), opts)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (gen !== syncGen) return;
        clearTimeout(timer);
        syncBusy = false;
        if (window.cardsLiveSyncSoon) {
          window.cardsLiveSyncSoon = false;
          sync(true, true);
          return;
        }
        if (!quiet && window.appBusyDone) window.appBusyDone();
        if (!d || !d.ok) {
          if (!quiet && syncEl) {
            syncEl.classList.remove('is-busy');
            syncEl.textContent = (d && d.error) ? d.error : (isEn ? 'Sync failed' : 'فشلت المزامنة');
          }
          return;
        }
        var groups = d.groups || [];
        var hasPins = false;
        var gi;
        for (gi = 0; gi < groups.length; gi++) {
          if (groups[gi] && groups[gi].cards && groups[gi].cards.length) {
            hasPins = true;
            break;
          }
        }
        if (!hasPins && paintFastCounts(groups)) {
          if (typeof window.cardXferApplyGroups === 'function') window.cardXferApplyGroups(groups);
        } else {
          renderGroups(groups);
          if (typeof window.cardXferApplyGroups === 'function') window.cardXferApplyGroups(groups);
        }
        if (syncEl) {
          syncEl.classList.remove('is-busy');
          syncEl.textContent = quiet ? '' : (isEn ? 'Synced' : 'تمت المزامنة');
        }
      })
      .catch(function () {
        if (gen !== syncGen) return;
        clearTimeout(timer);
        syncBusy = false;
        if (window.cardsLiveSyncSoon) {
          window.cardsLiveSyncSoon = false;
          sync(true, true);
          return;
        }
        if (!quiet && window.appBusyDone) window.appBusyDone();
        if (!quiet && syncEl) {
          syncEl.classList.remove('is-busy');
          syncEl.textContent = isEn ? 'Sync failed' : 'فشلت المزامنة';
        }
      });
  }

  if (!ownStockOnly && sasReady) {
    bindUi();
    if (syncEl) {
      syncEl.textContent = '';
      syncEl.classList.remove('is-busy');
    }
    if (document.querySelector('#cardsList [data-has-free="1"] .empty-cat')) {
      sync(true, true);
    }
    setInterval(function () { sync(true, true); }, 25000);
  } else if (syncEl) {
    syncEl.textContent = '';
    syncEl.classList.remove('is-busy');
  }

  var profileInput = document.getElementById('xferProfileName');
  var profileIdInput = document.getElementById('xferProfileId');

  if (profileInput && profileIdInput) {
    profileInput.addEventListener('change', function () {
      var opt = profileInput.options[profileInput.selectedIndex];
      profileIdInput.value = opt ? (opt.getAttribute('data-pid') || '0') : '0';
    });
  }
})();
</script>
<?php render_footer(); ?>
