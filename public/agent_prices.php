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

if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'GET' && function_exists('app_session_close')) {
    app_session_close();
}
ensure_agent_card_prices_table($pdo);
$GLOBALS['portal_sas_tree_no_fetch'] = true;

function agent_price_people_under($pdo, $rootId, $pool)
{
    $rootId = (int) $rootId;
    if ($rootId <= 0 || !$pdo) {
        return array();
    }
    list($rows, $kids, $roots) = agent_price_tree_model($pdo, $pool, 0);
    $out = array();
    $stack = isset($kids[$rootId]) ? $kids[$rootId] : array();
    $guard = 0;
    while ($stack && $guard < 500) {
        $guard++;
        $cur = (int) array_pop($stack);
        if ($cur <= 0 || $cur === $rootId || isset($out[$cur])) {
            continue;
        }
        $out[$cur] = true;
        if (isset($kids[$cur])) {
            foreach ($kids[$cur] as $ch) {
                $stack[] = (int) $ch;
            }
        }
    }
    return array_keys($out);
}

function agent_price_sas_name_keys($name)
{
    $name = strtolower(trim((string) $name));
    $keys = array();
    if ($name === '') {
        return $keys;
    }
    $keys[] = $name;
    $flat = str_replace('@', '', $name);
    if ($flat !== '' && $flat !== $name) {
        $keys[] = $flat;
    }
    $at = strrpos($name, '@');
    if ($at !== false) {
        $tail = substr($name, $at + 1);
        if ($tail !== '') {
            $keys[] = $tail;
        }
    }
    return $keys;
}

function agent_price_one_letter_apart($a, $b)
{
    $a = strtolower(trim((string) $a));
    $b = strtolower(trim((string) $b));
    if ($a === '' || $b === '' || $a === $b) {
        return false;
    }
    if (strlen($a) > strlen($b)) {
        $swap = $a;
        $a = $b;
        $b = $swap;
    }
    if (strlen($a) < 8 || (strlen($b) - strlen($a)) !== 1) {
        return false;
    }
    $ia = 0;
    $ib = 0;
    $skip = 0;
    $la = strlen($a);
    $lb = strlen($b);
    while ($ia < $la && $ib < $lb) {
        if ($a[$ia] === $b[$ib]) {
            $ia++;
            $ib++;
        } else {
            $skip++;
            $ib++;
            if ($skip > 1) {
                return false;
            }
        }
    }
    return $ia === $la && ($skip + ($lb - $ib)) === 1;
}

function agent_price_sas_close_id($names, $byUser)
{
    $hits = array();
    if (!is_array($names) || !is_array($byUser)) {
        return 0;
    }
    foreach ($byUser as $sasName => $sid) {
        foreach ($names as $nm) {
            if (agent_price_one_letter_apart($nm, $sasName)) {
                $hits[(int) $sid] = true;
            }
        }
    }
    if (count($hits) !== 1) {
        return 0;
    }
    $ids = array_keys($hits);
    return (int) $ids[0];
}

function agent_price_copy_down($pdo, $userIds, $lines)
{
    $nUsers = 0;
    if (!$pdo || !is_array($userIds) || !is_array($lines) || !function_exists('agent_card_price_save')) {
        return 0;
    }
    foreach ($userIds as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0) {
            continue;
        }
        $wrote = false;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $price = isset($line['price']) ? (float) $line['price'] : 0;
            $name = isset($line['name']) ? trim((string) $line['name']) : '';
            if ($price <= 0 || $name === '') {
                continue;
            }
            $pid = isset($line['pid']) ? (int) $line['pid'] : 0;
            $retail = isset($line['retail']) ? (float) $line['retail'] : 0;
            if ($retail < $price) {
                $retail = $price;
            }
            if (agent_card_price_save($pdo, $uid, $pid, $name, $price, $price, $retail, $price)) {
                $wrote = true;
            }
        }
        if ($wrote) {
            $nUsers++;
        }
    }
    return $nUsers;
}

function agent_price_tree_model($pdo, $pool, $homeId)
{
    $homeId = (int) $homeId;
    $ids = array();
    if (is_array($pool)) {
        foreach ($pool as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($id > 0) {
                $ids[$id] = $row;
            }
        }
    }
    if (!$ids) {
        return array(array(), array(), array());
    }
    $rows = $ids;
    try {
        $st = $pdo->query(
            'SELECT id, username, display_name, role, is_active, sas_manager_id, reports_to_user_id
             FROM admin_users WHERE id IN (' . implode(',', array_map('intval', array_keys($ids))) . ')'
        );
        foreach ($st->fetchAll() as $r) {
            $rows[(int) $r['id']] = $r;
        }
    } catch (Exception $e) {
    }
    $sasParent = array();
    $byUser = array();
    if (function_exists('portal_sas_tree_maps')) {
        $maps = portal_sas_tree_maps($pdo);
        if (is_array($maps)) {
            $sasParent = isset($maps['parent_of']) && is_array($maps['parent_of']) ? $maps['parent_of'] : array();
            $byUser = isset($maps['by_user']) && is_array($maps['by_user']) ? $maps['by_user'] : array();
        }
    }
    $sasKnown = array();
    $sasNameOf = array();
    foreach ($byUser as $sasName => $sasId) {
        $sasKnown[(int) $sasId] = true;
        $sasNameOf[(int) $sasId] = (string) $sasName;
    }
    foreach ($sasParent as $sasId => $sasPid) {
        $sasKnown[(int) $sasId] = true;
    }
    $aliasOwner = array();
    $aliasBad = array();
    foreach ($byUser as $sasName => $sasId) {
        $alts = agent_price_sas_name_keys($sasName);
        foreach ($alts as $alias) {
            if ($alias === $sasName) {
                continue;
            }
            if (isset($aliasBad[$alias])) {
                continue;
            }
            if (isset($aliasOwner[$alias]) && (int) $aliasOwner[$alias] !== (int) $sasId) {
                unset($aliasOwner[$alias]);
                $aliasBad[$alias] = true;
                continue;
            }
            $aliasOwner[$alias] = (int) $sasId;
        }
    }
    $portalBySas = array();
    $sasOf = array();
    foreach ($rows as $id => $r) {
        $names = array(
            isset($r['username']) ? (string) $r['username'] : '',
            isset($r['display_name']) ? (string) $r['display_name'] : '',
        );
        $sid = !empty($r['sas_manager_id']) ? (int) $r['sas_manager_id'] : 0;
        if ($sid > 0 && !isset($sasKnown[$sid])) {
            $sid = 0;
        }
        $nameSid = 0;
        foreach ($names as $nm) {
            $key = strtolower(trim($nm));
            if ($key !== '' && isset($byUser[$key])) {
                $nameSid = (int) $byUser[$key];
                break;
            }
        }
        if ($nameSid <= 0) {
            foreach ($names as $nm) {
                $key = strtolower(trim($nm));
                if ($key !== '' && strpos($key, '@') === false && isset($byUser['wifi@' . $key])) {
                    $nameSid = (int) $byUser['wifi@' . $key];
                    break;
                }
            }
        }
        if ($nameSid <= 0) {
            foreach ($names as $nm) {
                $alts = agent_price_sas_name_keys($nm);
                foreach ($alts as $alias) {
                    if (isset($aliasOwner[$alias])) {
                        $nameSid = (int) $aliasOwner[$alias];
                        break 2;
                    }
                }
            }
        }
        if ($nameSid <= 0) {
            $nameSid = agent_price_sas_close_id($names, $byUser);
        }
        if ($nameSid > 0 && $nameSid !== $sid) {
            $storedName = ($sid > 0 && isset($sasNameOf[$sid])) ? $sasNameOf[$sid] : '';
            $same = false;
            if ($storedName !== '') {
                $storedKeys = agent_price_sas_name_keys($storedName);
                foreach ($names as $nm) {
                    $mine = agent_price_sas_name_keys($nm);
                    foreach ($mine as $a) {
                        foreach ($storedKeys as $b) {
                            if ($a !== '' && $a === $b) {
                                $same = true;
                            }
                        }
                    }
                    $plain = strtolower(trim($nm));
                    if ($plain !== '' && strpos($plain, '@') === false && strtolower($storedName) === ('wifi@' . $plain)) {
                        $same = true;
                    }
                }
            }
            if (!$same) {
                $sid = $nameSid;
            }
        }
        if ($sid <= 0) {
            $sid = $nameSid;
        }
        if ($sid > 0) {
            $rows[$id]['sas_manager_id'] = $sid;
        }
        $sasOf[$id] = $sid;
        if ($sid > 0 && !isset($portalBySas[$sid])) {
            $portalBySas[$sid] = $id;
        }
    }
    $systemId = -1;
    $parentOf = array();
    $needSystem = false;
    foreach ($rows as $id => $r) {
        $parent = 0;
        $sid = isset($sasOf[$id]) ? (int) $sasOf[$id] : 0;
        if ($sid <= 0) {
            $parent = $systemId;
            $needSystem = true;
        } else {
            $cur = $sid;
            for ($i = 0; $i < 12 && $cur > 0; $i++) {
                if (!isset($sasParent[$cur])) {
                    break;
                }
                $pSas = (int) $sasParent[$cur];
                if ($pSas <= 0) {
                    break;
                }
                if (isset($portalBySas[$pSas])) {
                    $pId = (int) $portalBySas[$pSas];
                    if ($pId > 0 && $pId !== $id && isset($rows[$pId])) {
                        $parent = $pId;
                        break;
                    }
                }
                $cur = $pSas;
            }
        }
        if ($parent === $id) {
            $parent = 0;
        }
        $parentOf[$id] = $parent;
    }
    if ($needSystem) {
        $rows[$systemId] = array(
            'id' => $systemId,
            'username' => 'System',
            'display_name' => 'System',
            'role' => '',
            'is_active' => 1,
            'sas_manager_id' => 0,
            'reports_to_user_id' => 0,
            'system_group' => 1,
        );
        $parentOf[$systemId] = 0;
    }
    foreach (array_keys($parentOf) as $id) {
        $seen = array($id => true);
        $cur = (int) $parentOf[$id];
        $guard = 0;
        while ($cur !== 0 && $guard < 12) {
            if (isset($seen[$cur])) {
                $parentOf[$id] = 0;
                break;
            }
            $seen[$cur] = true;
            $cur = isset($parentOf[$cur]) ? (int) $parentOf[$cur] : 0;
            $guard++;
        }
    }
    $kids = array();
    foreach ($parentOf as $id => $p) {
        $p = (int) $p;
        if (!isset($kids[$p])) {
            $kids[$p] = array();
        }
        $kids[$p][] = $id;
    }
    $sortRows = $rows;
    $sortKids = function ($a, $b) use ($sortRows) {
        $an = isset($sortRows[$a]['username']) ? (string) $sortRows[$a]['username'] : '';
        $bn = isset($sortRows[$b]['username']) ? (string) $sortRows[$b]['username'] : '';
        return strcasecmp($an, $bn);
    };
    foreach ($kids as $p => $list) {
        usort($list, $sortKids);
        $kids[$p] = $list;
    }
    $roots = isset($kids[0]) ? $kids[0] : array();
    $realRoots = array();
    $systemRoots = array();
    foreach ($roots as $rid) {
        if ((int) $rid === -1) {
            $systemRoots[] = $rid;
        } else {
            $realRoots[] = $rid;
        }
    }
    $roots = array_merge($realRoots, $systemRoots);
    return array($rows, $kids, $roots);
}

function agent_price_sas_label($pdo, $row)
{
    static $ready = false;
    static $byId = array();
    static $byFlat = array();
    static $byName = array();
    if (!$ready) {
        $ready = true;
        if ($pdo && function_exists('portal_sas_manager_tree')) {
            foreach (portal_sas_manager_tree($pdo) as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $sid = isset($node['id']) ? (int) $node['id'] : 0;
                $nm = isset($node['username']) ? trim((string) $node['username']) : '';
                if ($sid <= 0 || $nm === '') {
                    continue;
                }
                $byId[$sid] = $nm;
                $byName[strtolower($nm)] = $sid;
                $flat = strtolower(str_replace('@', '', $nm));
                if ($flat !== '' && !isset($byFlat[$flat])) {
                    $byFlat[$flat] = $nm;
                }
            }
        }
    }
    if (!is_array($row)) {
        return '';
    }
    $sid = !empty($row['sas_manager_id']) ? (int) $row['sas_manager_id'] : 0;
    if ($sid > 0 && isset($byId[$sid])) {
        return $byId[$sid];
    }
    $cands = array(
        isset($row['username']) ? trim((string) $row['username']) : '',
        isset($row['display_name']) ? trim((string) $row['display_name']) : '',
    );
    foreach ($cands as $cand) {
        if ($cand === '') {
            continue;
        }
        $flat = strtolower(str_replace('@', '', $cand));
        if ($flat !== '' && isset($byFlat[$flat])) {
            return $byFlat[$flat];
        }
    }
    $closeId = agent_price_sas_close_id($cands, $byName);
    if ($closeId > 0 && isset($byId[$closeId])) {
        return $byId[$closeId];
    }
    if (isset($cands[1]) && strpos($cands[1], '@') !== false) {
        return $cands[1];
    }
    if (isset($cands[0]) && strpos($cands[0], '@') !== false) {
        return $cands[0];
    }
    if ($cands[0] !== '') {
        return $cands[0];
    }
    return isset($cands[1]) ? $cands[1] : '';
}

function agent_price_tree_render($rows, $kids, $id, $agentId, $homeId, $isEn, $depth, $openSet)
{
    global $pdo;
    $id = (int) $id;
    if ($depth > 12 || !isset($rows[$id])) {
        return;
    }
    $r = $rows[$id];
    $isSystem = !empty($r['system_group']);
    $label = $isSystem ? 'System' : agent_price_sas_label($pdo, $r);
    if ($label === '') {
        $label = '#' . $id;
    }
    $childIds = isset($kids[$id]) ? $kids[$id] : array();
    $childIds = array_values(array_filter($childIds, function ($cid) use ($id) {
        return (int) $cid !== (int) $id;
    }));
    $stopped = ($id !== (int) $homeId && isset($r['is_active']) && (int) $r['is_active'] !== 1);
    $on = ((int) $agentId === $id);
    $kidsOpen = ($depth === 0) || (is_array($openSet) && !empty($openSet[$id]));
    $openAttr = $kidsOpen ? '1' : '0';
    echo '<div class="ag-branch" data-label="' . e(strtolower($label)) . '">';
    echo '<div class="ag-row' . ($on ? ' is-on' : '') . '">';
    if ($childIds) {
        echo '<button type="button" class="ag-twist" data-open="' . $openAttr . '" aria-label="toggle"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 7.5 L10 12.5 L15 7.5"/></svg></button>';
    } else {
        echo '<span class="ag-dot"></span>';
    }
    if ($isSystem) {
        echo '<span class="ag-hit ag-system">';
        echo '<span class="ag-label">System</span>';
        echo '</span></div>';
    } else {
        echo '<a class="ag-hit" href="agent_prices.php?agent=' . $id . '">';
        echo '<span class="ag-label">' . e($label) . '</span>';
        if ($stopped) {
            echo '<span class="ag-stop">' . e($isEn ? 'Stopped' : 'موقوف') . '</span>';
        }
        echo '</a></div>';
    }
    if ($childIds) {
        echo '<div class="ag-kids" data-open="' . $openAttr . '" data-base="' . $openAttr . '">';
        foreach ($childIds as $cid) {
            agent_price_tree_render($rows, $kids, (int) $cid, $agentId, $homeId, $isEn, $depth + 1, $openSet);
        }
        echo '</div>';
    }
    echo '</div>';
}

$wantSheet = (isset($_GET['part']) && (string) $_GET['part'] === 'sheet');
$sheetFast = false;
$sheetHome = 0;
$allowFile = dirname(__DIR__) . '/storage/cache/price_allow_' . $meId . '.json';
if ($wantSheet) {
    $tryAgent = isset($_GET['agent']) ? (int) $_GET['agent'] : 0;
    if ($tryAgent > 0 && is_file($allowFile) && (time() - (int) @filemtime($allowFile)) < 180) {
        $allowRaw = json_decode((string) @file_get_contents($allowFile), true);
        if (is_array($allowRaw) && isset($allowRaw['ids']) && is_array($allowRaw['ids'])) {
            foreach ($allowRaw['ids'] as $aid0) {
                if ((int) $aid0 !== $tryAgent) {
                    continue;
                }
                $sheetFast = true;
                $sheetHome = isset($allowRaw['home']) ? (int) $allowRaw['home'] : $meId;
                $tid = isset($allowRaw['tid']) ? (int) $allowRaw['tid'] : (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1);
                $agents = array();
                try {
                    $stOne = $pdo->prepare('SELECT id, username, display_name, role, is_active, tenant_id, sas_manager_id, reports_to_user_id FROM admin_users WHERE id = :id LIMIT 1');
                    $stOne->execute(array(':id' => $tryAgent));
                    $one = $stOne->fetch();
                    if ($one) {
                        $agents[] = $one;
                    }
                } catch (Exception $e) {
                }
                break;
            }
        }
    }
}
if (!$sheetFast) {
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
    $allowIds = array($meId => true);
    if ($isGm && function_exists('group_manager_team_ids')) {
        foreach (group_manager_team_ids($pdo) as $teamId) {
            $allowIds[(int) $teamId] = true;
        }
    }
    foreach (agent_price_people_under($pdo, $meId, $agents) as $underId) {
        $allowIds[(int) $underId] = true;
    }
    $agents = array_values(array_filter($agents, function ($a) use ($allowIds) {
        return !empty($allowIds[(int) $a['id']]);
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
$profiles = $planProfiles;
if (!$profiles) {
    if (function_exists('sas_make_connector') && function_exists('sas_profiles_for_ui') && function_exists('sas_is_ready') && sas_is_ready($config)) {
        $apiP = sas_make_connector($config);
        if ($apiP) {
            $profiles = sas_profiles_for_ui($apiP);
        }
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
$homeId = ($sheetFast && $sheetHome > 0) ? $sheetHome : $meId;
if (!$sheetFast && $isAccPrice && function_exists('accountant_linked_agent_id')) {
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
if ($agentId <= 0 || empty($allowedPick[$agentId])) {
    $agentId = $homeId;
}

$viewerIsTop = ($aboveId <= 0 && ($isPriceAdmin || $isAccPrice));
if (!$sheetFast) {
    $allowIdsOut = array();
    foreach ($allowedPick as $pidAllow => $yesAllow) {
        $allowIdsOut[] = (int) $pidAllow;
    }
    $allowDir = dirname($allowFile);
    if (!is_dir($allowDir)) {
        @mkdir($allowDir, 0755, true);
    }
    @file_put_contents($allowFile, json_encode(array(
        'at' => time(),
        'home' => (int) $homeId,
        'tid' => (int) $tid,
        'ids' => $allowIdsOut,
    )));
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
        $clearLines = array();
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
            $clearLines[] = array('pid' => $pid, 'name' => $nm, 'price' => $cost, 'retail' => $cost);
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
        $downCount = agent_price_copy_down($pdo, agent_price_people_under($pdo, $agentId, $agents), $clearLines);
        if ($n > 0 && function_exists('activity_log')) {
            activity_log($pdo, 0, 'card_price', $agentId, 'card_price', 'تصفير أسعار الوكيل على سعر التكلفة', '');
        }
        flash('success', $isEn
            ? ('Prices reset to the cost on this page' . ($downCount > 0 ? (' and ' . $downCount . ' under him') : ''))
            : ('تم التصفير على سعر التكلفة' . ($downCount > 0 ? (' وعلى ' . $downCount . ' تحته') : '')));
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
        $downAllowed = array();
        if (!$isPriceAdmin && !$isAccPrice && $agentId !== $homeId) {
            foreach (agent_price_people_under($pdo, $meId, $agents) as $underOk) {
                $downAllowed[(int) $underOk] = true;
            }
        }
        $downLines = array();
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
            if ($agentId !== $homeId && !$viewerIsTop && $mine > 0) {
                $knownW = $mine;
            } elseif ($agentId !== $homeId && isset($baseByName[$floorKey]) && (float) $baseByName[$floorKey] > 0) {
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
                    $mineSave = isset($mySell[$saveKey]) ? (float) $mySell[$saveKey] : 0;
                    if (!$viewerIsTop && $mineSave > 0) {
                        $knownW = $mineSave;
                    } elseif (isset($baseByName[$saveKey]) && (float) $baseByName[$saveKey] > 0) {
                        $knownW = (float) $baseByName[$saveKey];
                    } elseif (isset($homeBySave[$saveKey]) && (float) $homeBySave[$saveKey]['wholesale_price'] > 0) {
                        $knownW = (float) $homeBySave[$saveKey]['wholesale_price'];
                    } elseif ($mineSave > 0) {
                        $knownW = $mineSave;
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
                if (!$teamOk && !empty($downAllowed[$agentId])) {
                    $teamOk = true;
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
                } else {
                    $saveKey = strtolower($profileName);
                    $mineSave = isset($mySell[$saveKey]) ? (float) $mySell[$saveKey] : 0;
                    $knownW = $mineSave;
                    if ($knownW <= 0 && isset($baseByName[$saveKey]) && (float) $baseByName[$saveKey] > 0) {
                        $knownW = (float) $baseByName[$saveKey];
                    }
                    if ($knownW > 0) {
                        $w = $knownW;
                    }
                    if ($postedA > 0) {
                        $ap = $postedA;
                    }
                    if (isset($row['retail_price'])) {
                        $rp = (float) $row['retail_price'];
                    }
                }
            }
            if (agent_card_price_save($pdo, $agentId, $profileId, $profileName, $w, $ap, $rp, $subP)) {
                $n++;
            }
            $downPrice = 0;
            if (!$isAccPrice && $agentId === $homeId) {
                if ($subP !== null && (float) $subP > 0) {
                    $downPrice = (float) $subP;
                } elseif ($ap > 0) {
                    $downPrice = (float) $ap;
                } elseif ($w > 0) {
                    $downPrice = (float) $w;
                }
            } elseif (!$isAccPrice && $agentId !== $homeId && $ap > 0) {
                $downPrice = (float) $ap;
            }
            if ($downPrice > 0) {
                $retailDown = ($rp !== null && (float) $rp > 0) ? (float) $rp : $downPrice;
                $downLines[] = array(
                    'pid' => $profileId,
                    'name' => $profileName,
                    'price' => $downPrice,
                    'retail' => $retailDown,
                );
            }
        }
        $downCount = 0;
        if ($downLines) {
            $downCount = agent_price_copy_down($pdo, agent_price_people_under($pdo, $agentId, $agents), $downLines);
        }
        if ($n > 0 && function_exists('activity_log')) {
            activity_log($pdo, 0, 'card_price', $agentId, 'card_price', 'تسعير خدمات وكيل', '');
        }
        if ($downCount > 0 && $agentId === $homeId) {
            flash('success', $isEn
                ? ('Saved. The same price now applies to ' . $downCount . ' agents under you.')
                : ('تم الحفظ، ونفس السعر نزل على ' . $downCount . ' وكيل تحتك.'));
        } elseif ($downCount > 0) {
            flash('success', $isEn
                ? ('Saved. The price also applies to ' . $downCount . ' agents under him.')
                : ('تم حفظ السعر على هذا الوكيل وعلى ' . $downCount . ' تحته.'));
        } else {
            flash('success', $isEn
                ? ('Saved prices for ' . $n . ' packages')
                : ('تم حفظ أسعار ' . $n . ' باقات'));
        }
        redirect('agent_prices.php?agent=' . (int) $agentId);
    }
}

if (!$wantSheet) {
render_header($isEn ? 'Package prices' : 'تسعير الباقات', 'agent_prices');
require_once __DIR__ . '/../includes/settings_tabs.php';
render_settings_tabs('prices');
?>
<div class="price-layout">
<div class="panel agent-pick-panel" id="agentPickPanel">
    <style>
    .price-layout,
    .price-layout input,
    .price-layout button,
    .price-layout summary,
    .price-layout .btn {
      font-family: Tajawal, "Segoe UI", Tahoma, Arial, sans-serif;
    }
    .price-layout {
      display: grid;
      grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
      gap: 16px;
      align-items: start;
    }
    .price-layout > .panel {
      margin: 0;
      border: 1px solid #e7edf4;
      border-radius: 18px;
      box-shadow: 0 10px 28px rgba(15, 23, 42, .05);
      background: #fff;
    }
    #agentPickPanel { position: sticky; top: 12px; padding: 14px 12px 12px; }
    .ag-tree-wrap { margin: 0; }
    .ag-tree-fold { margin: 0; }
    .ag-tree-fold > summary {
      display: none;
      list-style: none;
      cursor: pointer;
      font-size: 16px;
      font-weight: 800;
      color: #0f172a;
      padding: 2px 2px 0;
    }
    .ag-tree-fold > summary::-webkit-details-marker { display: none; }
    .ag-tree-title { margin: 0 0 10px; font-size: 16px; font-weight: 800; color: #0f172a; }
    .ag-tree-q {
      width: 100%; box-sizing: border-box; border: 1px solid #e2e8f0; background: #f8fafc;
      border-radius: 12px; padding: 11px 12px; font-size: 16px; font-weight: 700; margin: 0 0 8px;
    }
    .ag-tree-q:focus { outline: none; border-color: #94a3b8; background: #fff; box-shadow: 0 0 0 3px rgba(15, 23, 42, .06); }
    .ag-tree {
      direction: ltr; text-align: left;
      max-height: calc(100vh - 196px);
      overflow: auto; padding: 2px 2px 6px;
      -webkit-overflow-scrolling: touch;
    }
    .ag-row {
      display: flex; align-items: center; gap: 2px; min-height: 40px; border-radius: 10px; padding: 0 2px;
    }
    .ag-row:hover { background: #f8fafc; }
    .ag-row.is-on { background: #eef2ff; box-shadow: inset 3px 0 0 #1e293b; }
    .ag-row.is-on .ag-label { color: #0f172a; font-weight: 800; }
    .ag-twist, .ag-dot {
      width: 28px; height: 28px; border: 0; background: transparent; border-radius: 8px;
      color: #94a3b8; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 28px; padding: 0;
    }
    .ag-twist { cursor: pointer; }
    .ag-twist svg { width: 14px; height: 14px; transition: transform .15s ease; }
    .ag-twist[data-open="0"] svg { transform: rotate(-90deg); }
    .ag-dot::before { content: ""; width: 5px; height: 5px; border-radius: 50%; background: #cbd5e1; }
    .ag-hit {
      display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0;
      text-decoration: none; color: inherit; padding: 8px 6px; min-height: 40px;
    }
    .ag-label { font-size: 14px; font-weight: 600; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .ag-system { cursor: default; }
    .ag-system .ag-label { font-weight: 800; letter-spacing: .02em; }
    .ag-stop { font-size: 11px; font-weight: 700; color: #b45309; }
    .ag-kids { margin-left: 12px; padding-left: 8px; border-left: 1px solid #eef2f7; }
    .ag-kids[data-open="0"] { display: none; }
    .ag-tree-empty { padding: 14px; color: #64748b; font-weight: 700; display: none; }
    #agentPricePane.is-loading { opacity: .55; }
    .price-sheet { padding: 16px 16px 14px; min-width: 0; }
    .price-sheet h3 { margin: 0 0 14px; font-size: 22px; font-weight: 800; color: #0f172a; }
    .price-sheet .meta { margin: -6px 0 12px; color: #64748b; font-size: 13px; }
    .price-sheet .table-wrap {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      border: 1px solid #e8edf3;
      border-radius: 14px;
    }
    .price-sheet table { min-width: 520px; }
    .price-sheet th { font-size: 12px; color: #64748b; }
    .price-sheet td.pkg-name { font-weight: 800; color: #0f172a; white-space: nowrap; }
    .price-sheet input[type="number"],
    .price-sheet input.ltr,
    .price-sheet input.pkg-price {
      width: 100%;
      max-width: 150px;
      height: 40px;
      min-height: 40px;
      box-sizing: border-box;
      text-align: center;
      font-weight: 700;
      border-radius: 12px;
    }
    .price-sheet input[readonly],
    .price-sheet .pkg-price[readonly] {
      background: #f1f5f9;
      color: #334155;
      border-color: transparent;
    }
    @media (min-width: 761px) {
      .ag-tree-fold:not([open]) .ag-tree-title,
      .ag-tree-fold:not([open]) .ag-tree-q,
      .ag-tree-fold:not([open]) .ag-tree { display: block; }
    }
    @media (max-width: 760px) {
      .price-layout { grid-template-columns: 1fr; gap: 10px; }
      #agentPickPanel { position: static; padding: 12px; }
      .ag-tree-fold > summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-height: 40px;
      }
      .ag-tree-fold > summary::after { content: "▾"; color: #94a3b8; font-size: 14px; }
      .ag-tree-fold:not([open]) > summary::after { content: "▸"; }
      .ag-tree-title { display: none; }
      .ag-tree { max-height: 220px; }
      .price-sheet { padding: 14px 12px 8px; }
      .price-sheet h3 { font-size: 20px; margin-bottom: 10px; }
      .price-sheet table,
      .price-sheet thead,
      .price-sheet tbody,
      .price-sheet tr,
      .price-sheet td { display: block; width: auto; min-width: 0; }
      .price-sheet table { min-width: 0; }
      .price-sheet thead { display: none; }
      .price-sheet .table-wrap { border: 0; background: transparent; overflow: visible; }
      .price-sheet tbody tr {
        border: 1px solid #e8edf3;
        border-radius: 16px;
        padding: 8px 12px 4px;
        margin-bottom: 10px;
        background: #fff;
      }
      .price-sheet td { border: 0; padding: 6px 0; text-align: right; }
      .price-sheet td.pkg-name {
        font-size: 16px;
        padding-bottom: 8px;
        margin-bottom: 2px;
        border-bottom: 1px solid #f1f5f9;
      }
      .price-sheet td[data-label] { display: block; padding-top: 8px; }
      .price-sheet td[data-label]::before {
        content: attr(data-label);
        display: block;
        margin-bottom: 6px;
        font-size: 12px;
        font-weight: 700;
        color: #64748b;
      }
      .price-sheet input[type="number"],
      .price-sheet input.ltr,
      .price-sheet input.pkg-price { width: 100%; max-width: none; }
      .price-actions-row { margin-top: 4px; }
      .price-actions-row .btn { flex: 1; min-height: 44px; }
    }
    </style>
    <?php
    $treePool = $picker;
    if ($homeRow && $homeId > 0) {
        $treeHasHome = false;
        foreach ($treePool as $tp) {
            if ((int) $tp['id'] === (int) $homeId) {
                $treeHasHome = true;
                break;
            }
        }
        if (!$treeHasHome) {
            array_unshift($treePool, $homeRow);
        }
    }
    list($treeRows, $treeKids, $treeRoots) = agent_price_tree_model($pdo, $treePool, $homeId);
    $treeParent = array();
    foreach ($treeKids as $treePid => $treeList) {
        foreach ($treeList as $treeCid) {
            $treeParent[(int) $treeCid] = (int) $treePid;
        }
    }
    $treeOpen = array((int) $agentId => true);
    $treeCur = (int) $agentId;
    $treeGuard = 0;
    while ($treeCur > 0 && isset($treeParent[$treeCur]) && $treeGuard < 8) {
        $treeCur = (int) $treeParent[$treeCur];
        $treeOpen[$treeCur] = true;
        $treeGuard++;
    }
    ?>
    <details class="ag-tree-wrap ag-tree-fold" open>
        <summary><?php echo e($isEn ? 'Agents tree' : 'شجرة الوكلاء'); ?></summary>
        <div class="ag-tree-title"><?php echo e($isEn ? 'Agents tree' : 'شجرة الوكلاء'); ?></div>
        <input type="search" class="ag-tree-q" id="agTreeQ" autocomplete="off" enterkeyhint="search"
               placeholder="<?php echo e($isEn ? 'Search the tree' : 'ابحث في الشجرة'); ?>">
        <div class="ag-tree" id="agTree">
            <?php if (!$treeRoots): ?>
                <div class="ag-tree-empty" style="display:block"><?php echo e($isEn ? 'No agents' : 'ماكو وكلاء'); ?></div>
            <?php else: ?>
                <?php foreach ($treeRoots as $rootId): ?>
                    <?php agent_price_tree_render($treeRows, $treeKids, (int) $rootId, $agentId, $homeId, $isEn, 0, $treeOpen); ?>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="ag-tree-empty" id="agTreeEmpty"><?php echo e($isEn ? 'No match' : 'ماكو نتيجة'); ?></div>
        </div>
    </details>
    <script>
    (function () {
      var fold = document.querySelector('.ag-tree-fold');
      if (fold && window.matchMedia && window.matchMedia('(max-width: 760px)').matches) {
        fold.removeAttribute('open');
      }
      var q = document.getElementById('agTreeQ');
      var tree = document.getElementById('agTree');
      var empty = document.getElementById('agTreeEmpty');
      if (!tree) return;
      function kidsOf(branch) {
        var ch = branch.children;
        for (var i = 0; i < ch.length; i++) {
          if (ch[i].className.indexOf('ag-kids') !== -1) return ch[i];
        }
        return null;
      }
      function rowOf(branch) {
        var ch = branch.children;
        for (var i = 0; i < ch.length; i++) {
          if (ch[i].className.indexOf('ag-row') !== -1) return ch[i];
        }
        return null;
      }
      function matchBranch(branch, needle) {
        if (!branch || branch.className.indexOf('ag-branch') === -1) return false;
        var label = branch.getAttribute('data-label') || '';
        var selfHit = needle === '' || label.indexOf(needle) !== -1;
        var kids = kidsOf(branch);
        var childHit = false;
        if (kids) {
          var subs = kids.children;
          for (var i = 0; i < subs.length; i++) {
            if (matchBranch(subs[i], needle)) childHit = true;
          }
        }
        var show = needle === '' || selfHit || childHit;
        branch.style.display = show ? '' : 'none';
        if (kids && needle === '') {
          var base = kids.getAttribute('data-base') || '0';
          kids.setAttribute('data-open', base);
          var rowBack = rowOf(branch);
          var twistBack = rowBack ? rowBack.querySelector('.ag-twist') : null;
          if (twistBack && twistBack.getAttribute('data-open') !== null) twistBack.setAttribute('data-open', base);
        }
        if (kids && needle !== '') {
          kids.setAttribute('data-open', (selfHit || childHit) ? '1' : '0');
          var row = rowOf(branch);
          var twist = row ? row.querySelector('.ag-twist') : null;
          if (twist && twist.getAttribute('data-open') !== null) twist.setAttribute('data-open', kids.getAttribute('data-open'));
        }
        return show;
      }
      function apply() {
        var needle = String(q && q.value || '').toLowerCase().replace(/\s+/g, '');
        var branches = tree.children;
        var shown = 0;
        for (var i = 0; i < branches.length; i++) {
          if (branches[i].className.indexOf('ag-branch') === -1) continue;
          if (matchBranch(branches[i], needle)) shown++;
        }
        if (empty) empty.style.display = shown ? 'none' : 'block';
      }
      var twists = tree.querySelectorAll('.ag-twist');
      for (var t = 0; t < twists.length; t++) {
        twists[t].addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var branch = this.parentNode;
          while (branch && branch.className.indexOf('ag-branch') === -1) branch = branch.parentNode;
          var kids = branch ? kidsOf(branch) : null;
          if (!kids) return;
          var open = this.getAttribute('data-open') !== '0';
          var next = open ? '0' : '1';
          this.setAttribute('data-open', next);
          kids.setAttribute('data-open', next);
        });
      }
      if (q) {
        q.addEventListener('input', apply);
        q.addEventListener('keydown', function (e) {
          var key = e.key || e.keyCode;
          if (key === 'Enter' || key === 13) e.preventDefault();
        });
      }
      var on = tree.querySelector('.ag-row.is-on');
      if (on && on.scrollIntoView) on.scrollIntoView({ block: 'nearest' });
      function cls(node) {
        if (!node || node.className == null) return '';
        if (typeof node.className === 'string') return node.className;
        if (typeof node.className.baseVal === 'string') return node.className.baseVal;
        return '';
      }
      tree.addEventListener('click', function (e) {
        var node = e.target;
        while (node && node !== tree) {
          if (cls(node).indexOf('ag-twist') !== -1) return;
          node = node.parentNode;
        }
        var hit = e.target;
        while (hit && hit !== tree && cls(hit).indexOf('ag-hit') === -1) hit = hit.parentNode;
        if (!hit || hit === tree) return;
        e.preventDefault();
        var href = hit.getAttribute('href') || '';
        if (!href) return;
        var rows = tree.querySelectorAll('.ag-row');
        for (var i = 0; i < rows.length; i++) {
          rows[i].className = rows[i].className.replace(' is-on', '');
        }
        if (hit.parentNode) hit.parentNode.className += ' is-on';
        var pane = document.getElementById('agentPricePane');
        if (pane) pane.className = 'is-loading';
        var sheet = href + (href.indexOf('?') >= 0 ? '&' : '?') + 'part=sheet';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', sheet, true);
        xhr.onreadystatechange = function () {
          if (xhr.readyState !== 4) return;
          if (pane) pane.className = '';
          if (window.appBusyDone) window.appBusyDone();
          if (xhr.status >= 200 && xhr.status < 300 && xhr.responseText && xhr.responseText.indexOf('<html') === -1) {
            if (pane) pane.innerHTML = xhr.responseText;
            if (window.history && window.history.pushState) window.history.pushState(null, '', href);
            if (window.agentPriceBind) window.agentPriceBind(pane);
            if (pane && window.innerWidth < 860 && pane.scrollIntoView) pane.scrollIntoView({ block: 'start' });
          } else {
            window.location.href = href;
          }
        };
        xhr.send();
      });
    })();
    </script>
</div>
<div id="agentPricePane">
<?php
}
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
$underCount = 0;
if ($aid > 0 && !$isSelf) {
    $underCount = count(agent_price_people_under($pdo, $aid, $agents));
}
$hasDownline = false;
foreach ($picker as $downOpt) {
    if ((int) $downOpt['id'] !== (int) $homeId) {
        $hasDownline = true;
        break;
    }
}
?>
<?php if ($ag): ?>
<div class="panel price-sheet">
    <h3><?php
        $agName = agent_price_sas_label($pdo, $ag);
        if ($agName === '') {
            $agName = trim((string) $ag['username']) !== '' ? $ag['username'] : $ag['display_name'];
        }
        if (!$isSelf && isset($ag['is_active']) && (int) $ag['is_active'] !== 1) {
            $agName .= $isEn ? ' (stopped)' : ' (موقوف)';
        }
        echo e($agName);
        ?>
    </h3>
    <?php if (!$isSelf && !$isAccPrice): ?>
        <p class="meta"><?php echo e($isEn
            ? 'The package price is shown. Set the agent sale at that price or higher.'
            : 'سعر التكلفة ثابت. سعر الوكيل نفسه أو أعلى، والربح فرق الاثنين. الحفظ ينزل على هذا الوكيل وعلى كل اللي تحته.'); ?>
            <?php if ($underCount > 0): ?> <?php echo e($isEn ? ('Under him: ' . $underCount) : ('اللي تحته: ' . $underCount)); ?><?php endif; ?></p>
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
                        if (!$viewerIsTop && $mine > 0) {
                            $knownW = $mine;
                        } elseif (!empty($line['from_plan']) && $sasPrice > 0) {
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
                        <td class="pkg-name">
                            <?php echo e($nm); ?>
                            <input type="hidden" name="rows[<?php echo (int) $i; ?>][profile_name]" value="<?php echo e($nm); ?>">
                            <input type="hidden" name="rows[<?php echo (int) $i; ?>][profile_id]" value="<?php echo (int) (isset($line['id']) ? $line['id'] : 0); ?>">
                            <?php if (!$isSelf && !$isAccPrice): ?>
                            <input type="hidden" data-col="w" name="rows[<?php echo (int) $i; ?>][wholesale_price]" value="<?php echo (int) $orig; ?>">
                            <?php endif; ?>
                        </td>
                        <?php if (!$isSelf && !$isAccPrice): ?>
                        <td data-label="<?php echo e($isEn ? 'Cost' : 'سعر التكلفة'); ?>"><input class="ltr pkg-price" data-col="w" type="text" readonly tabindex="-1" value="<?php echo (int) $orig; ?>"></td>
                        <td data-label="<?php echo e($isEn ? 'Agent price' : 'سعر الوكيل'); ?>"><input class="ltr js-price" data-col="a" type="number" min="0" step="1" data-floor="<?php echo (int) $floorA; ?>" name="rows[<?php echo (int) $i; ?>][agent_price]" value="<?php echo (int) $sell; ?>"></td>
                        <td data-label="<?php echo e($isEn ? 'Subscriber price' : 'سعر المشترك النهائي'); ?>"><input class="ltr js-price" data-col="r" type="number" min="0" step="1" data-floor="<?php echo (int) $floorR; ?>" name="rows[<?php echo (int) $i; ?>][retail_price]" value="<?php echo (int) $retail; ?>"></td>
                        <td class="ltr js-profit" data-label="<?php echo e($isEn ? 'Profit' : 'الربح'); ?>" style="font-weight:800"><?php echo (int) $profitNow; ?></td>
                        <?php elseif ($isSelf): ?>
                        <td data-label="<?php echo e($isEn ? 'Cost' : 'سعر التكلفة'); ?>">
                            <input class="ltr pkg-price" data-col="w" type="text" readonly tabindex="-1" value="<?php echo (int) $orig; ?>">
                            <input type="hidden" name="rows[<?php echo (int) $i; ?>][wholesale_price]" value="<?php echo (int) $savedW; ?>">
                        </td>
                        <td data-label="<?php echo e($isEn ? 'Subscriber price' : 'سعر المشترك النهائي'); ?>"><input class="ltr js-price" data-col="r" data-bind="1" type="number" min="0" step="1" data-floor="<?php echo (int) $floorR; ?>" name="rows[<?php echo (int) $i; ?>][retail_price]" value="<?php echo (int) $retail; ?>"></td>
                        <?php if ($hasDownline): ?>
                        <td data-label="<?php echo e($isEn ? 'Sub-agent sale' : 'سعر البيع للوكيل الفرعي'); ?>"><input class="ltr js-price" data-col="s" data-bind="1" type="number" min="0" step="1" data-floor="<?php echo (int) $floorA; ?>" name="rows[<?php echo (int) $i; ?>][subagent_price]" value="<?php echo (int) $subagent; ?>"></td>
                        <?php endif; ?>
                        <?php else: ?>
                        <?php if ($isAccPrice): ?>
                        <td data-label="<?php echo e($isEn ? 'Cost' : 'سعر التكلفة'); ?>"><input class="ltr js-price" data-col="w" type="number" min="0" step="1" data-floor="<?php echo (int) $floorW; ?>" name="rows[<?php echo (int) $i; ?>][wholesale_price]" value="<?php echo (int) $orig; ?>" <?php echo $lockWholesale ? 'readonly' : ''; ?>></td>
                        <td data-label="<?php echo e($isEn ? 'Subscriber price' : 'سعر المشترك النهائي'); ?>"><input class="ltr js-price" data-col="r" type="number" min="0" step="1" data-floor="<?php echo (int) $floorR; ?>" name="rows[<?php echo (int) $i; ?>][retail_price]" value="<?php echo (int) $retail; ?>" <?php echo $canRetail ? '' : 'readonly'; ?>></td>
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
<?php if ($wantSheet) { exit; } ?>
</div>
</div>
<style>
.price-actions-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:14px; }
.price-actions-row .btn { min-width:120px; }
.js-price.is-over { border-color:#dc2626 !important; color:#b91c1c !important; background:#fef2f2 !important; outline:2px solid #dc2626; }
.js-price[readonly], .pkg-price[readonly] { background:#f3f4f6; color:#111827; }
</style>
<script>
window.agentPriceBind = function (root) {
  root = root || document;
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
  var inputs = root.querySelectorAll ? root.querySelectorAll('.js-price') : [];
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
  var forms = root.querySelectorAll ? root.querySelectorAll('form') : [];
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
};
window.agentPriceBind(document);
</script>
<?php render_footer(); ?>
