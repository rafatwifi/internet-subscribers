<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$isEn = ($lang === 'en');
$me = current_admin();
$meId = $me ? (int) $me['id'] : 0;
$isAgent = function_exists('is_agent_user') && is_agent_user();
$isGm = function_exists('is_group_manager_user') && is_group_manager_user();
$isPriceAdmin = function_exists('is_admin_user') && is_admin_user()
    && !(function_exists('is_accountant_user') && is_accountant_user());
if (!$isPriceAdmin && !$isAgent && !$isGm) {
    flash('error', $isEn ? 'Not allowed' : 'التسعير للأدمن، والتعديل على سعر المواطن للوكيل ومدير الوكلاء');
    redirect('index.php');
}

ensure_agent_card_prices_table($pdo);

$tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$agents = function_exists('list_agent_users') ? list_agent_users($pdo, true) : array();
$agents = array_values(array_filter($agents, function ($a) use ($tid) {
    $at = isset($a['tenant_id']) ? (int) $a['tenant_id'] : 1;
    return $at === $tid;
}));
try {
    $gmSt = $pdo->prepare(
        'SELECT id, username, display_name, role, is_active, tenant_id
         FROM admin_users WHERE role = "group_manager" AND tenant_id = :t AND is_active = 1'
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
    $an = isset($a['display_name']) ? (string) $a['display_name'] : '';
    $bn = isset($b['display_name']) ? (string) $b['display_name'] : '';
    return strcasecmp($an, $bn);
});
if (!$isPriceAdmin) {
    $allowIds = array($meId);
    if ($isGm && function_exists('group_manager_team_ids')) {
        $allowIds = group_manager_team_ids($pdo);
    }
    $agents = array_values(array_filter($agents, function ($a) use ($allowIds) {
        return in_array((int) $a['id'], $allowIds, true);
    }));
}

// الوكيل: أسعاره + الوكلاء تحت شركته (نفس الـ tenant) للتسعير عليهم
$myPrices = ($meId > 0 && function_exists('agent_card_prices_list'))
    ? agent_card_prices_list($pdo, $meId)
    : array();
$myFloor = array(); // profile_name lower => min agent_price
foreach ($myPrices as $mp) {
    $key = strtolower(trim((string) $mp['profile_name']));
    if ($key === '') {
        continue;
    }
    $ap = (float) $mp['wholesale_price'];
    if (!isset($myFloor[$key]) || $ap > $myFloor[$key]) {
        $myFloor[$key] = $ap;
    }
}

$agentId = isset($_GET['agent']) ? (int) $_GET['agent'] : 0;
if ($isAgent) {
    // افتراضي: أسعاري
    if ($agentId <= 0) {
        $agentId = $meId;
    }
    // يسمح بتسعير وكلاء آخرين بنفس الشركة فقط
    $allowed = array($meId);
    foreach ($agents as $a) {
        $allowed[] = (int) $a['id'];
    }
    if (!in_array($agentId, $allowed, true)) {
        $agentId = $meId;
    }
} else {
    if ($agentId <= 0 && $agents) {
        $agentId = (int) $agents[0]['id'];
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
    foreach ($agents as $a) {
        $allowedIds[] = (int) $a['id'];
    }
    if ($isAgent) {
        $allowedIds[] = $meId;
    }
    if ($agentId <= 0 || !in_array($agentId, $allowedIds, true)) {
        flash('error', $isEn ? 'Not allowed' : 'غير مسموح');
        redirect('agent_prices.php');
    }
    if ($action === 'clear' && $isPriceAdmin) {
        $pdo->prepare('DELETE FROM agent_card_prices WHERE agent_user_id = :a AND tenant_id = :t')
            ->execute(array(':a' => $agentId, ':t' => $tid));
        flash('success', $isEn ? 'Prices cleared' : 'تم التصفير');
        redirect('agent_prices.php');
    }
    if ($action === 'save_all') {
        $rowsIn = isset($_POST['rows']) && is_array($_POST['rows']) ? $_POST['rows'] : array();
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
            if ($isPriceAdmin) {
                $w = $postedW;
                $ap = $postedA;
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
        flash('success', ($isEn ? 'Saved ' : 'تم حفظ ') . $n);
        redirect('agent_prices.php');
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

render_header($isEn ? 'Package prices' : 'تسعير الباقات', 'agent_prices');
require_once __DIR__ . '/../includes/settings_tabs.php';
render_settings_tabs('prices');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Card pricing' : 'تسعير الكروت'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'Each agent under you gets one block: package, original price, and your selling price. Save or clear that agent only.'
        : 'كل وكيل تحتك يطلع بفئاته: السعر الأصلي وسعر البيع على نفس السطر. حفظ أو تصفير لهذا الوكيل فقط.'); ?></p>
    <?php if (!$agents): ?>
        <p class="meta"><?php echo e($isEn
            ? 'Add agents first, then set their prices here.'
            : 'أضف الوكلاء من صفحة الوكلاء، وبعدها تسعّر كل واحد من هنا.'); ?>
            <a href="agents.php"><?php echo e($isEn ? 'Agents' : 'الوكلاء'); ?></a>
        </p>
    <?php endif; ?>
</div>
<?php foreach ($agents as $ag):
    $aid = (int) $ag['id'];
    $saved = agent_card_prices_list($pdo, $aid);
    $byName = array();
    foreach ($saved as $sp) {
        $byName[strtolower(trim((string) $sp['profile_name']))] = $sp;
    }
    $lines = $profiles;
    if (!$lines && $saved) {
        foreach ($saved as $sp) {
            $lines[] = array('id' => (int) $sp['profile_id'], 'name' => $sp['profile_name'], 'price' => (float) $sp['wholesale_price']);
        }
    }
    ?>
<div class="panel">
    <h3 style="margin:0 0 8px"><?php echo e($ag['display_name']); ?>
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
                    <th><?php echo e($isEn ? 'Package' : 'الفئة'); ?></th>
                    <th><?php echo e($isEn ? 'Wholesale' : 'سعر الجملة'); ?></th>
                    <th><?php echo e($isEn ? 'Agent sale' : 'سعر البيع للوكيل'); ?></th>
                    <th><?php echo e($isEn ? 'Citizen sale' : 'سعر البيع للمواطن'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $i => $line):
                    $nm = isset($line['name']) ? (string) $line['name'] : '';
                    $key = strtolower(trim($nm));
                    $have = isset($byName[$key]) ? $byName[$key] : null;
                    $sasPrice = isset($line['price']) ? (float) $line['price'] : 0;
                    $orig = ($have && (float) $have['wholesale_price'] > 0) ? (float) $have['wholesale_price'] : $sasPrice;
                    $sell = ($have && (float) $have['agent_price'] > 0) ? (float) $have['agent_price'] : $sasPrice;
                    $retail = ($have && isset($have['retail_price']) && (float) $have['retail_price'] > 0) ? (float) $have['retail_price'] : $sasPrice;
                    $lockAdmin = !$isPriceAdmin;
                    $canRetail = $isPriceAdmin || $aid === $meId;
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
                        <td><input class="ltr" type="number" min="0" step="1" name="rows[<?php echo (int) $i; ?>][wholesale_price]" value="<?php echo (int) $orig; ?>" style="max-width:140px" <?php echo $lockAdmin ? 'readonly' : ''; ?>></td>
                        <td><input class="ltr" type="number" min="0" step="1" name="rows[<?php echo (int) $i; ?>][agent_price]" value="<?php echo (int) $sell; ?>" style="max-width:140px" <?php echo $lockAdmin ? 'readonly' : ''; ?>></td>
                        <td><input class="ltr" type="number" min="0" step="1" name="rows[<?php echo (int) $i; ?>][retail_price]" value="<?php echo (int) $retail; ?>" style="max-width:140px" <?php echo $canRetail ? '' : 'readonly'; ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
        </div>
    </form>
    <?php if ($isPriceAdmin): ?>
    <form method="post" style="margin-top:8px" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Clear this agent prices?' : 'تصفير أسعار هذا الوكيل؟'); ?>);">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="clear">
        <input type="hidden" name="agent_id" value="<?php echo $aid; ?>">
        <button class="btn ghost" type="submit"><?php echo e($isEn ? 'Reset' : 'تصفير'); ?></button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php render_footer(); ?>
