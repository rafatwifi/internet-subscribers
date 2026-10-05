<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$isEn = ($lang === 'en');
$me = current_admin();
$meId = $me ? (int) $me['id'] : 0;
$isAgent = function_exists('is_agent_user') && is_agent_user();
$isGm = function_exists('is_group_manager_user') && is_group_manager_user();
$isAccPrice = function_exists('is_accountant_user') && is_accountant_user()
    && function_exists('user_may_price_cards') && user_may_price_cards($pdo);
$isPriceAdmin = function_exists('is_admin_user') && is_admin_user()
    && !(function_exists('is_accountant_user') && is_accountant_user());
if (!$isPriceAdmin && !$isAgent && !$isGm && !$isAccPrice) {
    flash('error', $isEn ? 'Not allowed' : 'التسعير للأدمن، والتعديل على سعر المواطن للوكيل ومدير الوكلاء');
    redirect('index.php');
}

ensure_agent_card_prices_table($pdo);

$tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$agents = function_exists('list_agent_users') ? list_agent_users($pdo, false) : array();
$agents = array_values(array_filter($agents, function ($a) use ($tid) {
    $at = isset($a['tenant_id']) ? (int) $a['tenant_id'] : 1;
    return $at === $tid;
}));
try {
    $gmSt = $pdo->prepare(
        'SELECT id, username, display_name, role, is_active, tenant_id
         FROM admin_users WHERE role = "group_manager" AND tenant_id = :t'
    );
    $gmSt->execute(array(':t' => $tid));
    $seenP = array();
    foreach ($agents as $a0) {
        $seenP[(int) $a0['id']] = true;
    }
    foreach ($gmSt->fetchAll() as $gm0) {
        if (empty($seenP[(int) $gm0['id']])) {
            $agents[] = $gm0;
        }
    }
} catch (Exception $e) {
}
usort($agents, function ($a, $b) {
    $an = isset($a['username']) ? (string) $a['username'] : '';
    if ($an === '' && isset($a['display_name'])) {
        $an = (string) $a['display_name'];
    }
    $bn = isset($b['username']) ? (string) $b['username'] : '';
    if ($bn === '' && isset($b['display_name'])) {
        $bn = (string) $b['display_name'];
    }
    return strcasecmp($an, $bn);
});
if (!$isPriceAdmin && !$isAccPrice) {
    $allowIds = array($meId);
    if ($isGm && function_exists('group_manager_team_ids')) {
        $allowIds = group_manager_team_ids($pdo);
    }
    $agents = array_values(array_filter($agents, function ($a) use ($allowIds) {
        return in_array((int) $a['id'], $allowIds, true);
    }));
}
if ($isAccPrice && function_exists('accountant_tree_ids')) {
    $treePrice = accountant_tree_ids($pdo);
    $bossId = function_exists('accountant_linked_agent_id') ? accountant_linked_agent_id() : 0;
    $agents = array_values(array_filter($agents, function ($a) use ($treePrice, $bossId) {
        $id = (int) $a['id'];
        return $id !== $bossId && in_array($id, $treePrice, true);
    }));
    $only = isset($_GET['agent']) ? (int) $_GET['agent'] : 0;
    if ($only > 0) {
        $agents = array_values(array_filter($agents, function ($a) use ($only) {
            return (int) $a['id'] === $only;
        }));
    }
}
if (($isPriceAdmin || $isAccPrice) && function_exists('portal_agencies_under_current')) {
    $haveAg = array();
    foreach ($agents as $a0) {
        $haveAg[(int) $a0['id']] = true;
    }
    foreach (portal_agencies_under_current($pdo, '') as $childAg) {
        $cid = (int) $childAg['id'];
        if ($cid <= 0 || !empty($haveAg[$cid])) {
            continue;
        }
        $agents[] = $childAg;
        $haveAg[$cid] = true;
    }
    $namePrefixes = array();
    if ($me && !empty($me['username'])) {
        $namePrefixes[strtolower(trim((string) $me['username']))] = true;
    }
    try {
        $stSasName = $pdo->prepare('SELECT sas_username, name FROM tenants WHERE id = :t LIMIT 1');
        $stSasName->execute(array(':t' => $tid));
        $tn = $stSasName->fetch();
        if ($tn) {
            $su = isset($tn['sas_username']) ? strtolower(trim((string) $tn['sas_username'])) : '';
            $nm = isset($tn['name']) ? strtolower(trim((string) $tn['name'])) : '';
            if ($su !== '') {
                $namePrefixes[$su] = true;
            }
            if ($nm !== '') {
                $namePrefixes[$nm] = true;
            }
        }
    } catch (Exception $e) {
    }
    if ($namePrefixes) {
        try {
            $stChild = $pdo->prepare(
                'SELECT id, username, display_name, role, is_active, tenant_id
                 FROM admin_users
                 WHERE role = "admin" AND tenant_id > 1 AND tenant_id <> :my'
            );
            $stChild->execute(array(':my' => $tid));
            foreach ($stChild->fetchAll() as $childRow) {
                $cid = (int) $childRow['id'];
                if ($cid <= 0 || !empty($haveAg[$cid])) {
                    continue;
                }
                $uname = strtolower(trim((string) $childRow['username']));
                $dname = strtolower(trim((string) $childRow['display_name']));
                $hit = false;
                foreach ($namePrefixes as $pre => $yes) {
                    if ($pre === '' || strpos($pre, '@') !== false) {
                        continue;
                    }
                    if ($uname === $pre . '@office' || strpos($uname, $pre . '@') === 0 || ($dname !== '' && strpos($dname, $pre . '@') === 0)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                $agents[] = $childRow;
                $haveAg[$cid] = true;
            }
        } catch (Exception $e) {
        }
    }
    usort($agents, function ($a, $b) {
        $an = isset($a['username']) ? (string) $a['username'] : '';
        if ($an === '' && isset($a['display_name'])) {
            $an = (string) $a['display_name'];
        }
        $bn = isset($b['username']) ? (string) $b['username'] : '';
        if ($bn === '' && isset($b['display_name'])) {
            $bn = (string) $b['display_name'];
        }
        return strcasecmp($an, $bn);
    });
}

// الوكيل: أسعاره + الوكلاء تحت شركته (نفس الـ tenant) للتسعير عليهم
$myPrices = ($meId > 0 && function_exists('agent_card_prices_list'))
    ? agent_card_prices_list($pdo, $meId)
    : array();
$myFloor = array(); // profile_name lower => min price I already have
foreach ($myPrices as $mp) {
    $key = strtolower(trim((string) $mp['profile_name']));
    if ($key === '') {
        continue;
    }
    $ap = (float) $mp['wholesale_price'];
    if ($ap <= 0 && isset($mp['agent_price'])) {
        $ap = (float) $mp['agent_price'];
    }
    if ($ap <= 0) {
        continue;
    }
    if (!isset($myFloor[$key]) || $ap > $myFloor[$key]) {
        $myFloor[$key] = $ap;
    }
}

$profiles = array();
if (function_exists('sas_make_connector') && function_exists('sas_profiles_for_ui') && function_exists('sas_is_ready') && sas_is_ready($config)) {
    $apiP = sas_make_connector($config);
    if ($apiP) {
        $profiles = sas_profiles_for_ui($apiP);
    }
}
if (!$profiles) {
    try {
        $stP = $pdo->prepare(
            'SELECT profile_id, profile_name, MAX(wholesale_price) AS wholesale_price
             FROM agent_card_prices WHERE tenant_id = :t GROUP BY profile_id, profile_name'
        );
        $stP->execute(array(':t' => $tid));
        foreach ($stP->fetchAll() as $pr) {
            $profiles[] = array(
                'id' => (int) $pr['profile_id'],
                'name' => $pr['profile_name'],
                'price' => (float) $pr['wholesale_price'],
            );
        }
    } catch (Exception $e) {
    }
}
$planProfiles = array();
try {
    $planRows = $pdo->query(
        'SELECT name, cost_price, sas_profile_id, sort_order, is_active
         FROM service_plans ORDER BY sort_order ASC, id ASC'
    )->fetchAll();
    foreach ($planRows as $pl) {
        if (isset($pl['is_active']) && (int) $pl['is_active'] !== 1) {
            continue;
        }
        $pnm = trim((string) $pl['name']);
        if ($pnm === '') {
            continue;
        }
        $planProfiles[] = array(
            'id' => isset($pl['sas_profile_id']) ? (int) $pl['sas_profile_id'] : 0,
            'name' => $pnm,
            'price' => isset($pl['cost_price']) ? (float) $pl['cost_price'] : 0,
            'from_plan' => 1,
        );
    }
} catch (Exception $e) {
}
if ($planProfiles) {
    $profiles = $planProfiles;
}
$pkgFloor = $myFloor;
foreach ($profiles as $pf) {
    $pk = strtolower(trim(isset($pf['name']) ? (string) $pf['name'] : ''));
    $pp = isset($pf['price']) ? (float) $pf['price'] : 0;
    if ($pk === '' || $pp <= 0) {
        continue;
    }
    if (!isset($pkgFloor[$pk]) || $pkgFloor[$pk] < $pp) {
        $pkgFloor[$pk] = $pp;
    }
}

$agentId = isset($_GET['agent']) ? (int) $_GET['agent'] : 0;
$homeId = $meId;
if ($isAccPrice && function_exists('accountant_linked_agent_id')) {
    $bossPrice = accountant_linked_agent_id();
    if ($bossPrice > 0) {
        $homeId = $bossPrice;
    }
}
$homeRow = ($homeId > 0 && function_exists('get_admin_user')) ? get_admin_user($pdo, $homeId) : null;
if (!$homeRow && $me) {
    $homeRow = $me;
    $homeId = $meId;
}
$picker = array();
if ($homeRow) {
    $picker[] = $homeRow;
}
foreach ($agents as $a) {
    if ((int) $a['id'] === $homeId) {
        continue;
    }
    $picker[] = $a;
}
$allowedPick = array();
foreach ($picker as $a) {
    $allowedPick[(int) $a['id']] = true;
}
if ($agentId <= 0 || empty($allowedPick[$agentId])) {
    $agentId = $homeId;
}
$aboveFloor = array();
$aboveId = ($homeRow && isset($homeRow['reports_to_user_id'])) ? (int) $homeRow['reports_to_user_id'] : 0;
if ($aboveId <= 0 && $homeId > 0) {
    try {
        $stAbove = $pdo->prepare('SELECT reports_to_user_id FROM admin_users WHERE id = :id LIMIT 1');
        $stAbove->execute(array(':id' => $homeId));
        $aboveId = (int) $stAbove->fetchColumn();
    } catch (Exception $e) {
        $aboveId = 0;
    }
}
if ($aboveId > 0 && function_exists('agent_card_prices_list')) {
    foreach (agent_card_prices_list($pdo, $aboveId) as $up) {
        $uk = strtolower(trim((string) $up['profile_name']));
        if ($uk === '') {
            continue;
        }
        $uv = isset($up['agent_price']) ? (float) $up['agent_price'] : 0;
        if ($uv <= 0) {
            $uv = (float) $up['wholesale_price'];
        }
        if ($uv > 0) {
            $aboveFloor[$uk] = $uv;
        }
    }
}
$mySell = array();
$myCost = array();
if ($homeId > 0 && function_exists('agent_card_prices_list')) {
    foreach (agent_card_prices_list($pdo, $homeId) as $hp0) {
        $hk = strtolower(trim((string) $hp0['profile_name']));
        if ($hk === '') {
            continue;
        }
        $hv = isset($hp0['agent_price']) ? (float) $hp0['agent_price'] : 0;
        if ($hv <= 0) {
            $hv = (float) $hp0['wholesale_price'];
        }
        if ($hv > 0) {
            $mySell[$hk] = $hv;
        }
        $cost = isset($hp0['wholesale_price']) ? (float) $hp0['wholesale_price'] : 0;
        if ($cost <= 0) {
            $cost = $hv;
        }
        if ($cost > 0) {
            $myCost[$hk] = $cost;
        }
    }
}
$sasByName = array();
foreach ($profiles as $pf0) {
    $pk = strtolower(trim(isset($pf0['name']) ? (string) $pf0['name'] : ''));
    $pp = isset($pf0['price']) ? (float) $pf0['price'] : 0;
    if ($pk !== '' && $pp > 0) {
        $sasByName[$pk] = $pp;
    }
}
$plansAreSource = !empty($profiles[0]['from_plan']);
$baseByName = array();
$baseKeys = array_unique(array_merge(array_keys($sasByName), array_keys($myCost), array_keys($aboveFloor)));
foreach ($baseKeys as $bk) {
    $bv = 0;
    if ($plansAreSource && isset($sasByName[$bk]) && (float) $sasByName[$bk] > 0) {
        $bv = (float) $sasByName[$bk];
    } elseif (isset($aboveFloor[$bk]) && (float) $aboveFloor[$bk] > 0) {
        $bv = (float) $aboveFloor[$bk];
    } elseif (isset($myCost[$bk]) && (float) $myCost[$bk] > 0) {
        $bv = (float) $myCost[$bk];
    } elseif (isset($sasByName[$bk]) && (float) $sasByName[$bk] > 0) {
        $bv = (float) $sasByName[$bk];
    }
    if ($bv > 0) {
        $baseByName[$bk] = $bv;
    }
}
$pricesAdopted = count($baseByName) > 0;
if ($pricesAdopted) {
    $pickerAgents = array();
    foreach ($picker as $a) {
        if ((int) $a['id'] === $homeId) {
            continue;
        }
        $pickerAgents[] = $a;
    }
    if ($pickerAgents) {
        $picker = $pickerAgents;
        $allowedPick = array();
        foreach ($picker as $a) {
            $allowedPick[(int) $a['id']] = true;
        }
        if ($agentId <= 0 || empty($allowedPick[$agentId])) {
            $agentId = (int) $picker[0]['id'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('agent_prices.php');
    }
    $action = post('action');
    $agentId = (int) post('agent_id', '0');
    $allowedIds = array();
    foreach ($picker as $a) {
        $allowedIds[] = (int) $a['id'];
    }
    if ($agentId <= 0 || !in_array($agentId, $allowedIds, true)) {
        flash('error', $isEn ? 'Not allowed' : 'غير مسموح');
        $backId = isset($picker[0]['id']) ? (int) $picker[0]['id'] : (int) $homeId;
        redirect('agent_prices.php?agent=' . $backId);
    }
    $back = 'agent_prices.php?agent=' . (int) $agentId;
    if ($action === 'clear' && ($isPriceAdmin || $isAccPrice) && $agentId !== $homeId) {
        $homeSaved = function_exists('agent_card_prices_list') ? agent_card_prices_list($pdo, $homeId) : array();
        $n = 0;
        $srcLines = $profiles;
        if (!$srcLines && $homeSaved) {
            foreach ($homeSaved as $hp) {
                $srcLines[] = array(
                    'id' => (int) $hp['profile_id'],
                    'name' => $hp['profile_name'],
                    'price' => (float) $hp['wholesale_price'],
                );
            }
        }
        $fixPid = $pdo->prepare(
            'UPDATE agent_card_prices
             SET wholesale_price = :c, agent_price = :c2, retail_price = :c3, updated_at = NOW()
             WHERE agent_user_id = :a AND profile_id = :p AND profile_id > 0'
        );
        foreach ($srcLines as $line) {
            $nm = isset($line['name']) ? trim((string) $line['name']) : '';
            if ($nm === '') {
                continue;
            }
            $key = strtolower($nm);
            $sasPrice = isset($line['price']) ? (float) $line['price'] : 0;
            $cost = 0;
            if (!empty($line['from_plan']) && $sasPrice > 0) {
                $cost = $sasPrice;
            } elseif (isset($baseByName[$key]) && (float) $baseByName[$key] > 0) {
                $cost = (float) $baseByName[$key];
            } elseif (isset($aboveFloor[$key]) && (float) $aboveFloor[$key] > 0) {
                $cost = (float) $aboveFloor[$key];
            } elseif ($sasPrice > 0) {
                $cost = $sasPrice;
            }
            if ($cost <= 0) {
                continue;
            }
            $pid = isset($line['id']) ? (int) $line['id'] : 0;
            if (agent_card_price_save($pdo, $agentId, $pid, $nm, $cost, $cost, $cost)) {
                $n++;
            }
            if ($pid > 0) {
                $fixPid->execute(array(
                    ':c' => $cost,
                    ':c2' => $cost,
                    ':c3' => $cost,
                    ':a' => $agentId,
                    ':p' => $pid,
                ));
            }
        }
        if ($n > 0 && function_exists('activity_log')) {
            activity_log($pdo, 0, 'card_price', $agentId, 'card_price', 'تصفير أسعار الوكيل على سعر التكلفة', '');
        }
        flash('success', $isEn ? 'Prices reset to the cost on this page' : 'تم التصفير على سعر التكلفة');
        redirect($back);
    }
    if ($action === 'save_all') {
        $rowsIn = isset($_POST['rows']) && is_array($_POST['rows']) ? $_POST['rows'] : array();
        $homeBySave = array();
        if ($agentId !== $homeId && function_exists('agent_card_prices_list')) {
            foreach (agent_card_prices_list($pdo, $homeId) as $hp) {
                $homeBySave[strtolower(trim((string) $hp['profile_name']))] = $hp;
            }
        }
        $tooLow = array();
        foreach ($rowsIn as $row) {
            if (!is_array($row)) {
                continue;
            }
            $profileName = isset($row['profile_name']) ? trim((string) $row['profile_name']) : '';
            if ($profileName === '') {
                continue;
            }
            $floorKey = strtolower($profileName);
            $old = function_exists('agent_card_price_get') ? agent_card_price_get($pdo, $agentId, isset($row['profile_id']) ? (int) $row['profile_id'] : 0, $profileName) : null;
            $sasFloor = isset($pkgFloor[$floorKey]) ? (float) $pkgFloor[$floorKey] : 0;
            $postedW = isset($row['wholesale_price']) ? (float) $row['wholesale_price'] : 0;
            $postedA = isset($row['agent_price']) ? (float) $row['agent_price'] : 0;
            $postedR = isset($row['retail_price']) ? (float) $row['retail_price'] : 0;
            $above = isset($aboveFloor[$floorKey]) ? (float) $aboveFloor[$floorKey] : 0;
            $mine = isset($mySell[$floorKey]) ? (float) $mySell[$floorKey] : 0;
            if ($mine <= 0) {
                $mine = $sasFloor;
            }
            $knownW = 0;
            if ($agentId !== $homeId && isset($baseByName[$floorKey]) && (float) $baseByName[$floorKey] > 0) {
                $knownW = (float) $baseByName[$floorKey];
            } elseif ($agentId !== $homeId && isset($homeBySave[$floorKey]) && (float) $homeBySave[$floorKey]['wholesale_price'] > 0) {
                $knownW = (float) $homeBySave[$floorKey]['wholesale_price'];
            } elseif ($agentId !== $homeId && $mine > 0) {
                $knownW = $mine;
            }
            if ($agentId === $homeId) {
                $floorW = $above;
                if ($postedW > $floorW) {
                    $floorW = $postedW;
                }
                $floorA = $floorW;
                $floorR = $floorW;
            } else {
                $floorW = $knownW > 0 ? $knownW : $mine;
                $floorA = $floorW;
                $floorR = $floorW;
            }
            $check = array();
            if ($agentId !== $homeId && isset($row['retail_price'])) {
                $check[] = array($postedR, $floorR);
            } elseif ($isAccPrice) {
                $check[] = array($postedR, $floorR);
            } elseif ($agentId === $homeId && isset($row['retail_price'])) {
                $costFloor = $postedW;
                if (function_exists('account_viewer_package_price')) {
                    $viewFloor = (float) account_viewer_package_price(
                        $pdo,
                        $profileName,
                        isset($row['profile_id']) ? (int) $row['profile_id'] : 0,
                        $postedW > 0 ? $postedW : $sasFloor
                    );
                    if ($viewFloor > $costFloor) {
                        $costFloor = $viewFloor;
                    }
                }
                $check[] = array($postedR, $costFloor > 0 ? $costFloor : $floorR);
            }
            if ($agentId === $homeId && $knownW <= 0 && !isset($row['retail_price'])) {
                $check[] = array($postedW, $floorW);
            }
            if (!$isAccPrice && $agentId !== $homeId) {
                $check[] = array($postedA, $floorA);
            } elseif (!$isAccPrice && $agentId === $homeId && isset($row['agent_price'])) {
                $check[] = array($postedA, $floorA);
            }
            if ($agentId === $homeId && isset($row['subagent_price'])) {
                $postedSubCheck = (float) $row['subagent_price'];
                $check[] = array($postedSubCheck, $postedW > 0 ? $postedW : $floorA);
            }
            foreach ($check as $pair) {
                if ($pair[1] > 0 && $pair[0] < $pair[1]) {
                    $tooLow[] = $profileName;
                    break;
                }
            }
        }
        if ($tooLow) {
            flash('error', $isEn
                ? 'Price cannot be below the current price'
                : 'سعر البيع للوكيل ما ينزل عن سعرك، واللي تحتك ما ينزل عن السعر اللي مخليه اللي فوق');
            redirect('agent_prices.php?agent=' . (int) $agentId);
        }
        $n = 0;
        foreach ($rowsIn as $row) {
            if (!is_array($row)) {
                continue;
            }
            $profileName = isset($row['profile_name']) ? trim((string) $row['profile_name']) : '';
            if ($profileName === '') {
                continue;
            }
            $profileId = isset($row['profile_id']) ? (int) $row['profile_id'] : 0;
            $postedW = isset($row['wholesale_price']) ? (float) $row['wholesale_price'] : 0;
            $postedA = isset($row['agent_price']) ? (float) $row['agent_price'] : 0;
            $old = function_exists('agent_card_price_get') ? agent_card_price_get($pdo, $agentId, $profileId, $profileName) : null;
            $subP = null;
            if ($isPriceAdmin || $isAccPrice) {
                $w = $postedW;
                $ap = $isAccPrice ? $postedW : $postedA;
                $rp = null;
                $subP = null;
                if (isset($row['retail_price'])) {
                    $rp = (float) $row['retail_price'];
                }
                if (isset($row['subagent_price'])) {
                    $subP = (float) $row['subagent_price'];
                }
                if ($agentId === $homeId) {
                    $oldAp = $old ? (float) $old['agent_price'] : 0;
                    $oldW = $old ? (float) $old['wholesale_price'] : 0;
                    if (!isset($row['agent_price'])) {
                        $ap = $oldAp > 0 ? $oldAp : ($postedW > 0 ? $postedW : $oldW);
                    }
                    if ($w <= 0) {
                        if ($oldAp > 0) {
                            $w = $oldAp;
                        } elseif ($oldW > 0) {
                            $w = $oldW;
                        }
                    }
                } elseif ($agentId !== $homeId) {
                    $saveKey = strtolower($profileName);
                    $knownW = 0;
                    if (isset($baseByName[$saveKey]) && (float) $baseByName[$saveKey] > 0) {
                        $knownW = (float) $baseByName[$saveKey];
                    } elseif (isset($homeBySave[$saveKey]) && (float) $homeBySave[$saveKey]['wholesale_price'] > 0) {
                        $knownW = (float) $homeBySave[$saveKey]['wholesale_price'];
                    } elseif (isset($mySell[$saveKey]) && (float) $mySell[$saveKey] > 0) {
                        $knownW = (float) $mySell[$saveKey];
                    }
                    if ($knownW > 0) {
                        $w = $knownW;
                        if ($isAccPrice) {
                            $ap = $knownW;
                        }
                    }
                }
            } else {
                $teamOk = ($agentId === $meId);
                if ($isGm && function_exists('group_manager_team_ids')) {
                    $teamOk = in_array($agentId, group_manager_team_ids($pdo), true);
                }
                if (!$teamOk) {
                    continue;
                }
                $w = $old ? (float) $old['wholesale_price'] : $postedW;
                $ap = $old ? (float) $old['agent_price'] : $postedA;
                $rp = null;
                $subP = null;
                if ($agentId === $meId || $agentId === $homeId) {
                    if ($postedW > 0) {
                        $w = $postedW;
                    } elseif ($ap > 0) {
                        $w = $ap;
                    }
                    if (!isset($row['agent_price']) && $old) {
                        $ap = (float) $old['agent_price'];
                    }
                    if (isset($row['retail_price'])) {
                        $rp = (float) $row['retail_price'];
                    }
                    if (isset($row['subagent_price'])) {
                        $subP = (float) $row['subagent_price'];
                    }
                }
            }
            if (agent_card_price_save($pdo, $agentId, $profileId, $profileName, $w, $ap, $rp, $subP)) {
                $n++;
            }
        }
        if ($n > 0 && function_exists('activity_log')) {
            activity_log($pdo, 0, 'card_price', $agentId, 'card_price', 'تسعير خدمات وكيل', '');
        }
        flash('success', $isEn
            ? ('Saved prices for ' . $n . ' packages')
            : ('تم حفظ أسعار ' . $n . ' باقات'));
        redirect('agent_prices.php?agent=' . (int) $agentId);
    }
}

render_header($isEn ? 'Package prices' : 'تسعير الباقات', 'agent_prices');
require_once __DIR__ . '/../includes/settings_tabs.php';
render_settings_tabs('prices');
?>
<div class="panel agent-pick-panel" id="agentPickPanel">
    <h2><?php echo e($isEn ? 'Card pricing' : 'تسعير الكروت'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'The package price comes from SAS or from the one above you. You can keep it or set it higher. After it is adopted, My agency is hidden, and pricing an agent shows the package price and the agent sale.'
        : 'سعر التكلفة يجي من الساس أو من الصفحة اللي فوق. من تسعّر وكيل: التكلفة ثابتة، وتكتب سعر الوكيل وسعر المشترك النهائي، والربح فرق التكلفة عن سعر الوكيل.'); ?></p>
    <style>
    .agent-pick-panel.is-pick-open { position:relative; z-index:30; overflow:visible; }
    .agent-pick-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-top:12px; }
    .agent-pick { position:relative; flex:1 1 280px; max-width:420px; min-width:0; }
    .agent-pick label { display:block; margin-bottom:6px; font-weight:800; }
    .agent-pick-q {
      width:100%; box-sizing:border-box; direction:ltr; text-align:left;
      font-size:16px; font-weight:700; line-height:1.45; padding:10px 12px;
      border:1px solid #cbd5e1; border-radius:12px; background:#fff;
    }
    .agent-pick-list {
      display:none; position:absolute; z-index:80; left:0; right:0; width:100%;
      top:calc(100% + 6px); direction:ltr; text-align:left; box-sizing:border-box;
      max-height:260px; overflow-x:hidden; overflow-y:auto;
      -webkit-overflow-scrolling:touch; overscroll-behavior:contain; touch-action:pan-y;
      border:1px solid #e2e8f0; border-radius:12px; background:#fff;
      box-shadow:0 16px 40px rgba(15,23,42,.18);
    }
    .agent-pick.is-open .agent-pick-list { display:block; }
    .agent-pick-list button {
      display:block; width:100%; max-width:100%; box-sizing:border-box; margin:0;
      text-align:left; direction:ltr;
      padding:10px 12px; border:0; border-bottom:1px solid #f1f5f9;
      background:#fff; font-size:15px; font-weight:700; cursor:pointer;
    }
    .agent-pick-list button.is-on { background:#dbeafe; }
    .agent-pick-list button:hover, .agent-pick-list button:focus { background:#eff6ff; outline:none; }
    .agent-pick-empty { padding:12px; color:#64748b; font-weight:700; text-align:left; }
    </style>
    <form method="get" id="agentPickForm" class="agent-pick-row">
        <div class="agent-pick" id="agentPick">
            <label for="agentPickQ"><?php echo e($isEn ? 'Show' : 'استعراض'); ?></label>
            <?php
            $pickedLabel = '';
            foreach ($picker as $opt0) {
                if ((int) $opt0['id'] !== $agentId) {
                    continue;
                }
                $odn0 = isset($opt0['display_name']) ? trim((string) $opt0['display_name']) : '';
                $oun0 = isset($opt0['username']) ? trim((string) $opt0['username']) : '';
                $pickedLabel = $odn0 !== '' ? $odn0 : $oun0;
                break;
            }
            ?>
            <input type="hidden" name="agent" id="agentPickValue" value="<?php echo (int) $agentId; ?>">
            <input type="search" class="agent-pick-q" id="agentPickQ" autocomplete="off" enterkeyhint="search"
                   placeholder="<?php echo e($isEn ? 'Search agent' : 'ابحث عن وكيل'); ?>"
                   value="<?php echo e($pickedLabel); ?>">
            <div class="agent-pick-list" id="agentPickList" role="listbox">
                <?php foreach ($picker as $opt):
                    $oid = (int) $opt['id'];
                    $odn = isset($opt['display_name']) ? trim((string) $opt['display_name']) : '';
                    $oun = isset($opt['username']) ? trim((string) $opt['username']) : '';
                    $oname = $odn !== '' ? $odn : $oun;
                    if ($oid === $homeId) {
                        $oname = ($isEn ? 'My agency — ' : 'وكالتي — ') . $oname;
                    }
                    if ($oid !== $homeId && isset($opt['is_active']) && (int) $opt['is_active'] !== 1) {
                        $oname .= $isEn ? ' (stopped)' : ' (موقوف)';
                    }
                    ?>
                    <button type="button" role="option" data-id="<?php echo $oid; ?>" data-label="<?php echo e($oname); ?>" <?php echo $oid === $agentId ? 'class="is-on"' : ''; ?>><?php echo e($oname); ?></button>
                <?php endforeach; ?>
                <div class="agent-pick-empty" id="agentPickEmpty" hidden><?php echo e($isEn ? 'No match' : 'ماكو نتيجة'); ?></div>
            </div>
        </div>
        <button class="btn" type="submit"><?php echo e($isEn ? 'Open' : 'اختيار'); ?></button>
    </form>
    <script>
    (function () {
      var form = document.getElementById('agentPickForm');
      var panel = document.getElementById('agentPickPanel');
      var box = document.getElementById('agentPick');
      var q = document.getElementById('agentPickQ');
      var val = document.getElementById('agentPickValue');
      var list = document.getElementById('agentPickList');
      var empty = document.getElementById('agentPickEmpty');
      if (!form || !box || !q || !val || !list) return;
      var items = list.getElementsByTagName('button');
      var picked = '';
      function norm(s) { return String(s || '').toLowerCase().replace(/\s+/g, ''); }
      function openList() {
        box.className = 'agent-pick is-open';
        if (panel) panel.className = 'panel agent-pick-panel is-pick-open';
        var on = null;
        for (var i = 0; i < items.length; i++) {
          if (items[i].className.indexOf('is-on') !== -1) on = items[i];
        }
        if (on) list.scrollTop = on.offsetTop > 40 ? on.offsetTop - 8 : 0;
      }
      function closeList() {
        box.className = 'agent-pick';
        if (panel) panel.className = 'panel agent-pick-panel';
      }
      function apply() {
        var needle = norm(q.value);
        if (needle === picked) needle = '';
        var shown = 0;
        for (var i = 0; i < items.length; i++) {
          var label = norm(items[i].getAttribute('data-label') || items[i].textContent);
          var ok = needle === '' || label.indexOf(needle) !== -1;
          items[i].style.display = ok ? 'block' : 'none';
          if (ok) shown++;
        }
        if (empty) empty.style.display = shown ? 'none' : 'block';
      }
      picked = norm(q.value);
      q.addEventListener('focus', function () { openList(); q.select(); });
      q.addEventListener('input', function () { openList(); apply(); });
      q.addEventListener('keydown', function (e) {
        var key = e.key || e.keyCode;
        if (key === 'Escape' || key === 27) closeList();
      });
      document.addEventListener('click', function (e) {
        var n = e.target;
        while (n) {
          if (n === box) return;
          n = n.parentNode;
        }
        closeList();
      });
      list.addEventListener('click', function (e) {
        var btn = e.target;
        while (btn && btn.tagName !== 'BUTTON') btn = btn.parentNode;
        if (!btn || !btn.getAttribute) return;
        val.value = btn.getAttribute('data-id') || '';
        q.value = btn.getAttribute('data-label') || btn.textContent;
        closeList();
        form.submit();
      });
      apply();
    })();
    </script>
</div>
<?php
$ag = null;
foreach ($picker as $opt) {
    if ((int) $opt['id'] === $agentId) {
        $ag = $opt;
        break;
    }
}
$aid = $ag ? (int) $ag['id'] : 0;
$saved = ($aid > 0) ? agent_card_prices_list($pdo, $aid) : array();
$byName = array();
$bySoft = array();
$byPid = array();
foreach ($saved as $sp) {
    $nkSaved = strtolower(trim((string) $sp['profile_name']));
    $byName[$nkSaved] = $sp;
    if ($nkSaved !== '' && function_exists('card_price_soft_key')) {
        $skSaved = card_price_soft_key($sp['profile_name']);
        if ($skSaved !== '' && !isset($bySoft[$skSaved])) {
            $bySoft[$skSaved] = $sp;
        }
    }
    $spid = isset($sp['profile_id']) ? (int) $sp['profile_id'] : 0;
    if ($spid > 0 && !isset($byPid[$spid])) {
        $byPid[$spid] = $sp;
    }
}
$homeSavedView = ($homeId > 0) ? agent_card_prices_list($pdo, $homeId) : array();
$homeByView = array();
foreach ($homeSavedView as $hp) {
    $homeByView[strtolower(trim((string) $hp['profile_name']))] = $hp;
}
$lines = $profiles;
if (!$lines && $saved) {
    foreach ($saved as $sp) {
        $lines[] = array('id' => (int) $sp['profile_id'], 'name' => $sp['profile_name'], 'price' => (float) $sp['wholesale_price']);
    }
}
$isSelf = ($aid === $homeId);
$hasDownline = false;
foreach ($picker as $downOpt) {
    if ((int) $downOpt['id'] !== (int) $homeId) {
        $hasDownline = true;
        break;
    }
}
?>
<?php if ($ag): ?>
<div class="panel">
    <h3 style="margin:0 0 8px"><?php
        $agName = trim((string) $ag['display_name']) !== '' ? $ag['display_name'] : $ag['username'];
        if ($isSelf) {
            $agName = ($isEn ? 'My agency — ' : 'وكالتي — ') . $agName;
        } elseif (isset($ag['is_active']) && (int) $ag['is_active'] !== 1) {
            $agName .= $isEn ? ' (stopped)' : ' (موقوف)';
        }
        echo e($agName);
        ?>
        <?php if (!empty($ag['username']) && strcasecmp(trim((string) $ag['username']), trim((string) $agName)) !== 0): ?><small class="meta ltr"><?php echo e($ag['username']); ?></small><?php endif; ?>
    </h3>
    <?php if ($isSelf && !$isAccPrice): ?>
        <p class="meta"><?php echo e($isEn
            ? 'Cost is the price set above you, or the SAS price. The subscriber price stays at that cost or higher.'
            : 'سعر التكلفة هو تسعيرة الأب إذا مسعّرك، وإلا سعر الساس. سعر المشترك النهائي نفسه أو أعلى.'); ?>
            <?php if ($hasDownline): ?><?php echo e($isEn
                ? ' With agents under you, set their sale at the cost or higher.'
                : ' وإذا تحتك وكلاء، سعر البيع للوكيل الفرعي نفسه أو أعلى من التكلفة.'); ?><?php endif; ?></p>
    <?php elseif (!$isSelf && !$isAccPrice): ?>
        <p class="meta"><?php echo e($isEn
            ? 'The package price is shown. Set the agent sale at that price or higher.'
            : 'سعر التكلفة ثابت من الباقة. سعّر الوكيل والمشترك النهائي، نفسه أو أعلى. الربح = سعر الوكيل − التكلفة.'); ?></p>
    <?php elseif (!$isSelf): ?>
        <p class="meta"><?php echo e($isEn
            ? 'The package price is already set. Set the cost and the subscriber price at that price or higher.'
            : 'سعر الباقة معتمد. هنا تسعّر سعر التكلفة وسعر المشترك النهائي، نفسه أو أعلى.'); ?></p>
    <?php endif; ?>
    <?php if (!$lines): ?>
        <p class="meta"><?php echo e($isEn ? 'No packages from SAS yet. Sync SAS then reopen this page.' : 'ماكو فئات من الساس بعد. زامن الساس ثم ارجع لهنا.'); ?></p>
    <?php else: ?>
    <form method="post" id="priceSaveForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_all">
        <input type="hidden" name="agent_id" value="<?php echo $aid; ?>">
        <div class="table-wrap">
            <table class="table-compact">
                <thead>
                <tr>
                    <th><?php echo e($isEn ? 'Package' : 'الباقة'); ?></th>
                    <?php if ($isAccPrice): ?>
                    <th><?php echo e($isEn ? 'Cost' : 'سعر التكلفة'); ?></th>
                    <th><?php echo e($isEn ? 'Subscriber price' : 'سعر المشترك النهائي'); ?></th>
                    <?php elseif ($isSelf): ?>
                    <th><?php echo e($isEn ? 'Cost' : 'سعر التكلفة'); ?></th>
                    <th><?php echo e($isEn ? 'Subscriber price' : 'سعر المشترك النهائي'); ?></th>
                    <?php if ($hasDownline): ?>
                    <th><?php echo e($isEn ? 'Sub-agent sale' : 'سعر البيع للوكيل الفرعي'); ?></th>
                    <?php endif; ?>
                    <?php else: ?>
                    <th><?php echo e($isEn ? 'Cost' : 'سعر التكلفة'); ?></th>
                    <th><?php echo e($isEn ? 'Agent price' : 'سعر الوكيل'); ?></th>
                    <th><?php echo e($isEn ? 'Final subscriber' : 'سعر المشترك النهائي'); ?></th>
                    <th><?php echo e($isEn ? 'Profit' : 'الربح'); ?></th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $i => $line):
                    $nm = isset($line['name']) ? (string) $line['name'] : '';
                    $key = strtolower(trim($nm));
                    $have = isset($byName[$key]) ? $byName[$key] : null;
                    $linePid = isset($line['id']) ? (int) $line['id'] : 0;
                    if (!$have && $linePid > 0 && isset($byPid[$linePid])) {
                        $have = $byPid[$linePid];
                    }
                    if (!$have && $key !== '' && function_exists('card_price_soft_key')) {
                        $softKey = card_price_soft_key($nm);
                        if ($softKey !== '' && isset($bySoft[$softKey])) {
                            $have = $bySoft[$softKey];
                        }
                    }
                    $hp = isset($homeByView[$key]) ? $homeByView[$key] : null;
                    $sasPrice = isset($line['price']) ? (float) $line['price'] : 0;
                    $above = isset($aboveFloor[$key]) ? (float) $aboveFloor[$key] : 0;
                    $mine = isset($mySell[$key]) ? (float) $mySell[$key] : 0;
                    if ($mine <= 0) {
                        $mine = $sasPrice;
                    }
                    if ($isSelf) {
                        $floorW = $above;
                        $floorA = $above;
                        $floorR = $above;
                    } else {
                        $floorW = $mine;
                        $floorA = $mine;
                        $floorR = $mine;
                    }
                    $knownW = 0;
                    if (!$isSelf) {
                        if (!empty($line['from_plan']) && $sasPrice > 0) {
                            $knownW = $sasPrice;
                        } elseif (isset($baseByName[$key]) && (float) $baseByName[$key] > 0) {
                            $knownW = (float) $baseByName[$key];
                        } elseif ($above > 0) {
                            $knownW = $above;
                        } elseif ($hp && isset($hp['wholesale_price']) && (float) $hp['wholesale_price'] > 0) {
                            $knownW = (float) $hp['wholesale_price'];
                        } elseif ($mine > 0) {
                            $knownW = $mine;
                        }
                        if ($hp && isset($hp['subagent_price']) && (float) $hp['subagent_price'] > $knownW) {
                            $knownW = (float) $hp['subagent_price'];
                        }
                    }
                    $orig = ($have && isset($have['wholesale_price'])) ? (float) $have['wholesale_price'] : 0;
                    if (!$isSelf && $knownW > 0) {
                        $orig = $knownW;
                        $floorW = $knownW;
                        $floorA = $knownW;
                        $floorR = $knownW;
                    }
                    $sell = ($have && isset($have['agent_price'])) ? (float) $have['agent_price'] : 0;
                    $retail = ($have && isset($have['retail_price'])) ? (float) $have['retail_price'] : 0;
                    if (!$isSelf && $knownW > 0) {
                        if ($sell <= 0) {
                            $sell = $knownW;
                        }
                        if ($retail <= 0) {
                            $retail = $knownW;
                        }
                    }
                    $savedAp = ($have && isset($have['agent_price'])) ? (float) $have['agent_price'] : 0;
                    $savedW = ($have && isset($have['wholesale_price'])) ? (float) $have['wholesale_price'] : 0;
                    $selfCost = 0;
                    if ($isSelf && function_exists('account_viewer_package_price')) {
                        $viewCost = (float) account_viewer_package_price(
                            $pdo,
                            $nm,
                            isset($line['id']) ? (int) $line['id'] : 0,
                            $sasPrice > 0 ? $sasPrice : $savedW
                        );
                        if ($viewCost > 0) {
                            $selfCost = $viewCost;
                        }
                    }
                    if ($selfCost <= 0 && $aboveId > 0 && $savedAp > 0) {
                        $selfCost = $savedAp;
                    } elseif ($selfCost <= 0 && $sasPrice > 0) {
                        $selfCost = $sasPrice;
                    } elseif ($selfCost <= 0 && $savedW > 0) {
                        $selfCost = $savedW;
                    }
                    if ($isSelf) {
                        $orig = $selfCost;
                        $floorW = $selfCost;
                        $floorA = $selfCost;
                        $floorR = $selfCost;
                        if ($retail <= 0 && $selfCost > 0) {
                            $retail = $selfCost;
                        }
                    }
                    $subagent = ($have && isset($have['subagent_price'])) ? (float) $have['subagent_price'] : 0;
                    if ($isSelf && $hasDownline && $subagent <= 0 && $selfCost > 0) {
                        $subagent = $selfCost;
                    }
                    $profitBase = $knownW > 0 ? $knownW : ($isSelf ? $orig : $mine);
                    $profitNow = $sell - $profitBase;
                    if ($profitNow < 0) {
                        $profitNow = 0;
                    }
                    $lockAdmin = !$isPriceAdmin && !$isAccPrice;
                    $lockWholesale = $lockAdmin || (!$isSelf && $knownW > 0);
                    $canRetail = $isPriceAdmin || $isAccPrice || $aid === $meId;
                    if ($isGm && function_exists('group_manager_team_ids')) {
                        $canRetail = $canRetail || in_array($aid, group_manager_team_ids($pdo), true);
                    }
                    ?>
                    <tr>
                        <td>
                            <?php echo e($nm); ?>
                            <input type="hidden" name="rows[<?php echo (int) $i; ?>][profile_name]" value="<?php echo e($nm); ?>">
                            <input type="hidden" name="rows[<?php echo (int) $i; ?>][profile_id]" value="<?php echo (int) (isset($line['id']) ? $line['id'] : 0); ?>">
                            <?php if (!$isSelf && !$isAccPrice): ?>
                            <input type="hidden" data-col="w" name="rows[<?php echo (int) $i; ?>][wholesale_price]" value="<?php echo (int) $orig; ?>">
                            <?php endif; ?>
                        </td>
                        <?php if (!$isSelf && !$isAccPrice): ?>
                        <td><input class="ltr pkg-price" data-col="w" type="text" readonly tabindex="-1" value="<?php echo (int) $orig; ?>" style="max-width:140px"></td>
                        <td><input class="ltr js-price" data-col="a" type="number" min="0" step="1" data-floor="<?php echo (int) $floorA; ?>" name="rows[<?php echo (int) $i; ?>][agent_price]" value="<?php echo (int) $sell; ?>" style="max-width:140px"></td>
                        <td><input class="ltr js-price" data-col="r" type="number" min="0" step="1" data-floor="<?php echo (int) $floorR; ?>" name="rows[<?php echo (int) $i; ?>][retail_price]" value="<?php echo (int) $retail; ?>" style="max-width:140px"></td>
                        <td class="ltr js-profit" style="font-weight:800"><?php echo (int) $profitNow; ?></td>
                        <?php elseif ($isSelf): ?>
                        <td>
                            <input class="ltr pkg-price" data-col="w" type="text" readonly tabindex="-1" value="<?php echo (int) $orig; ?>" style="max-width:140px">
                            <input type="hidden" name="rows[<?php echo (int) $i; ?>][wholesale_price]" value="<?php echo (int) $savedW; ?>">
                        </td>
                        <td><input class="ltr js-price" data-col="r" data-bind="1" type="number" min="0" step="1" data-floor="<?php echo (int) $floorR; ?>" name="rows[<?php echo (int) $i; ?>][retail_price]" value="<?php echo (int) $retail; ?>" style="max-width:140px"></td>
                        <?php if ($hasDownline): ?>
                        <td><input class="ltr js-price" data-col="s" data-bind="1" type="number" min="0" step="1" data-floor="<?php echo (int) $floorA; ?>" name="rows[<?php echo (int) $i; ?>][subagent_price]" value="<?php echo (int) $subagent; ?>" style="max-width:140px"></td>
                        <?php endif; ?>
                        <?php else: ?>
                        <?php if ($isAccPrice): ?>
                        <td><input class="ltr js-price" data-col="w" type="number" min="0" step="1" data-floor="<?php echo (int) $floorW; ?>" name="rows[<?php echo (int) $i; ?>][wholesale_price]" value="<?php echo (int) $orig; ?>" style="max-width:140px" <?php echo $lockWholesale ? 'readonly' : ''; ?>></td>
                        <td><input class="ltr js-price" data-col="r" type="number" min="0" step="1" data-floor="<?php echo (int) $floorR; ?>" name="rows[<?php echo (int) $i; ?>][retail_price]" value="<?php echo (int) $retail; ?>" style="max-width:140px" <?php echo $canRetail ? '' : 'readonly'; ?>></td>
                        <?php endif; ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
    <?php if (($isPriceAdmin || $isAccPrice) && !$isSelf): ?>
    <form method="post" id="priceClearForm" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Reset every price on this page to the cost shown?' : 'تصفير كل الأسعار الظاهرة هنا على سعر التكلفة؟'); ?>);">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="clear">
        <input type="hidden" name="agent_id" value="<?php echo $aid; ?>">
    </form>
    <?php endif; ?>
    <div class="price-actions-row">
        <button class="btn" type="submit" form="priceSaveForm"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
        <?php if (($isPriceAdmin || $isAccPrice) && !$isSelf): ?>
        <button class="btn ghost" type="submit" form="priceClearForm"><?php echo e($isEn ? 'Reset to cost' : 'تصفير'); ?></button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<style>
.price-actions-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:14px; }
.price-actions-row .btn { min-width:120px; }
.js-price.is-over { border-color:#dc2626 !important; color:#b91c1c !important; background:#fef2f2 !important; outline:2px solid #dc2626; }
.js-price[readonly], .pkg-price[readonly] { background:#f3f4f6; color:#111827; }
</style>
<script>
(function () {
  function rowOf(input) {
    return input && input.closest ? input.closest('tr') : null;
  }
  function paint(input) {
    if (!input || input.readOnly) return true;
    var floor = parseFloat(input.getAttribute('data-floor') || '0') || 0;
    var tr = rowOf(input);
    if (input.getAttribute('data-bind') === '1' && tr) {
      var w = tr.querySelector('[data-col="w"]');
      var wv = w ? parseFloat(w.value) : 0;
      if (isNaN(wv)) wv = 0;
      if (wv > floor) floor = wv;
    }
    var val = parseFloat(input.value);
    if (isNaN(val)) val = 0;
    var over = floor > 0 && val < floor;
    if (over) input.classList.add('is-over');
    else input.classList.remove('is-over');
    if (tr && input.getAttribute('data-col') === 'a') {
      var cell = tr.querySelector('.js-profit');
      if (cell) {
        if (over) cell.textContent = '—';
        else cell.textContent = String(Math.round(val - floor));
      }
    }
    return !over;
  }
  var inputs = document.querySelectorAll('.js-price');
  for (var i = 0; i < inputs.length; i++) {
    (function (input) {
      input.addEventListener('input', function () {
        paint(input);
        if (input.getAttribute('data-col') === 'w') {
          var tr = rowOf(input);
          if (tr) {
            var bound = tr.querySelectorAll('[data-bind="1"], [data-col="a"], [data-col="r"]');
            for (var b = 0; b < bound.length; b++) paint(bound[b]);
          }
        }
      });
      paint(input);
    })(inputs[i]);
  }
  var forms = document.querySelectorAll('form');
  for (var f = 0; f < forms.length; f++) {
    forms[f].addEventListener('submit', function (e) {
      var bad = false;
      var fields = this.querySelectorAll('.js-price');
      for (var j = 0; j < fields.length; j++) {
        if (!paint(fields[j])) bad = true;
      }
      if (bad) e.preventDefault();
    });
  }
})();
</script>
<?php render_footer(); ?>
