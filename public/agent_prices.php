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
        redirect('agent_prices.php?agent=' . (int) $homeId);
    }
    $back = 'agent_prices.php?agent=' . (int) $agentId;
    if ($action === 'clear' && ($isPriceAdmin || $isAccPrice) && $agentId !== $homeId) {
        $homeSaved = function_exists('agent_card_prices_list') ? agent_card_prices_list($pdo, $homeId) : array();
        $homeBy = array();
        foreach ($homeSaved as $hp) {
            $homeBy[strtolower(trim((string) $hp['profile_name']))] = $hp;
        }
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
        foreach ($srcLines as $line) {
            $nm = isset($line['name']) ? trim((string) $line['name']) : '';
            if ($nm === '') {
                continue;
            }
            $key = strtolower($nm);
            $hp = isset($homeBy[$key]) ? $homeBy[$key] : null;
            $sasPrice = isset($line['price']) ? (float) $line['price'] : 0;
            $w = ($hp && (float) $hp['wholesale_price'] > 0) ? (float) $hp['wholesale_price'] : $sasPrice;
            $ap = ($hp && (float) $hp['agent_price'] > 0) ? (float) $hp['agent_price'] : $w;
            $rp = ($hp && isset($hp['retail_price']) && (float) $hp['retail_price'] > 0) ? (float) $hp['retail_price'] : $w;
            $pid = isset($line['id']) ? (int) $line['id'] : ($hp ? (int) $hp['profile_id'] : 0);
            if (agent_card_price_save($pdo, $agentId, $pid, $nm, $w, $ap, $rp)) {
                $n++;
            }
        }
        if ($n > 0 && function_exists('activity_log')) {
            activity_log($pdo, 0, 'card_price', $agentId, 'card_price', 'إرجاع أسعار الوكيل لسعر الوكالة', '');
        }
        flash('success', $isEn ? 'Restored to the agency price' : 'رجعت الأسعار لسعر الوكالة');
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
            if ($agentId === $homeId) {
                $floorW = $above;
                $floorA = ($postedW > $above) ? $postedW : $above;
                $floorR = $floorA;
            } else {
                $floorW = $mine;
                $floorA = $mine;
                $floorR = $mine;
            }
            $check = array(array($postedW, $floorW), array($postedR, $floorR));
            if (!$isAccPrice) {
                $check[] = array($postedA, $floorA);
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
            $postedR = isset($row['retail_price']) ? (float) $row['retail_price'] : 0;
            $old = function_exists('agent_card_price_get') ? agent_card_price_get($pdo, $agentId, $profileId, $profileName) : null;
            if ($isPriceAdmin || $isAccPrice) {
                $w = $postedW;
                $ap = $isAccPrice ? $postedW : $postedA;
                $rp = $postedR;
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
                $rp = $postedR;
            }
            if (agent_card_price_save($pdo, $agentId, $profileId, $profileName, $w, $ap, $rp)) {
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
<div class="panel">
    <h2><?php echo e($isEn ? 'Card pricing' : 'تسعير الكروت'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'Agent sale cannot be below your price. If the one above you set a price, you and the people under you can match it or go higher. The difference is your profit on this page.'
        : 'سعر البيع للوكيل وسعر المواطن ما ينزلون عن سعر الجملة. إذا اللي فوقك مخلي سعر، أنت واللي تحتك تحطون نفسه أو أعلى. الفرق ربح ويرجع لهالصفحة.'); ?></p>
    <style>
    .price-pick { min-width:280px; direction:ltr; text-align:left; font-size:16px; font-weight:700; line-height:1.45; padding:8px 12px; }
    </style>
    <form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:10px">
        <div>
            <label><?php echo e($isEn ? 'Show' : 'استعراض'); ?></label>
            <select class="price-pick" name="agent" dir="ltr" onchange="this.form.submit()">
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
                    <option value="<?php echo $oid; ?>" <?php echo $oid === $agentId ? 'selected' : ''; ?>><?php echo e($oname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn" type="submit"><?php echo e($isEn ? 'Open' : 'اختيار'); ?></button>
    </form>
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
foreach ($saved as $sp) {
    $byName[strtolower(trim((string) $sp['profile_name']))] = $sp;
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
        <?php if (!empty($ag['username'])): ?><small class="meta ltr"><?php echo e($ag['username']); ?></small><?php endif; ?>
    </h3>
    <?php if (!$lines): ?>
        <p class="meta"><?php echo e($isEn ? 'No packages from SAS yet. Sync SAS then reopen this page.' : 'ماكو فئات من الساس بعد. زامن الساس ثم ارجع لهنا.'); ?></p>
    <?php else: ?>
    <form method="post">
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
                    <th><?php echo e($isEn ? 'Subscriber price' : 'سعر بيع المشترك'); ?></th>
                    <?php else: ?>
                    <th><?php echo e($isEn ? 'Wholesale' : 'سعر الجملة'); ?></th>
                    <th><?php echo e($isEn ? 'Agent sale' : 'سعر البيع للوكيل'); ?></th>
                    <th><?php echo e($isEn ? 'Citizen sale' : 'سعر البيع للمواطن'); ?></th>
                    <th><?php echo e($isEn ? 'Profit' : 'الربح'); ?></th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $i => $line):
                    $nm = isset($line['name']) ? (string) $line['name'] : '';
                    $key = strtolower(trim($nm));
                    $have = isset($byName[$key]) ? $byName[$key] : null;
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
                    $orig = ($have && isset($have['wholesale_price'])) ? (float) $have['wholesale_price'] : 0;
                    $sell = ($have && isset($have['agent_price'])) ? (float) $have['agent_price'] : 0;
                    $retail = ($have && isset($have['retail_price'])) ? (float) $have['retail_price'] : 0;
                    $profitBase = $isSelf ? $orig : $mine;
                    $profitNow = $sell - $profitBase;
                    if ($profitNow < 0) {
                        $profitNow = 0;
                    }
                    $lockAdmin = !$isPriceAdmin && !$isAccPrice;
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
                        </td>
                        <td><input class="ltr js-price" data-col="w" type="number" min="0" step="1" data-floor="<?php echo (int) $floorW; ?>" name="rows[<?php echo (int) $i; ?>][wholesale_price]" value="<?php echo (int) $orig; ?>" style="max-width:140px" <?php echo $lockAdmin ? 'readonly' : ''; ?>></td>
                        <?php if (!$isAccPrice): ?>
                        <td><input class="ltr js-price" data-col="a" type="number" min="0" step="1" data-floor="<?php echo (int) $floorA; ?>" <?php echo $isSelf ? 'data-bind="1"' : ''; ?> name="rows[<?php echo (int) $i; ?>][agent_price]" value="<?php echo (int) $sell; ?>" style="max-width:140px" <?php echo $lockAdmin ? 'readonly' : ''; ?>></td>
                        <?php endif; ?>
                        <td><input class="ltr js-price" data-col="r" type="number" min="0" step="1" data-floor="<?php echo (int) $floorR; ?>" <?php echo $isSelf ? 'data-bind="1"' : ''; ?> name="rows[<?php echo (int) $i; ?>][retail_price]" value="<?php echo (int) $retail; ?>" style="max-width:140px" <?php echo $canRetail ? '' : 'readonly'; ?>></td>
                        <?php if (!$isAccPrice): ?>
                        <td class="ltr js-profit" style="font-weight:800"><?php echo (int) $profitNow; ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
        </div>
    </form>
    <?php if (($isPriceAdmin || $isAccPrice) && !$isSelf): ?>
    <form method="post" style="margin-top:8px" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Restore this agent to your agency price?' : 'ترجع أسعار هذا الوكيل لسعر وكالتك؟'); ?>);">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="clear">
        <input type="hidden" name="agent_id" value="<?php echo $aid; ?>">
        <button class="btn ghost" type="submit"><?php echo e($isEn ? 'Restore agency price' : 'إرجاع لسعر الوكالة'); ?></button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>
<style>
.js-price.is-over { border-color:#dc2626 !important; color:#b91c1c !important; background:#fef2f2 !important; outline:2px solid #dc2626; }
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
