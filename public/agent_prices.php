<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$isEn = ($lang === 'en');
$me = current_admin();
$meId = $me ? (int) $me['id'] : 0;
$isAgent = function_exists('is_agent_user') && is_agent_user();
$canManageAll = user_can('users') || (user_can('cards') && !$isAgent);

if (!$canManageAll && !$isAgent && !user_can('cards')) {
    require_perm('cards');
}

ensure_agent_card_prices_table($pdo);

$tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$agents = function_exists('list_agent_users') ? list_agent_users($pdo, true) : array();
$agents = array_values(array_filter($agents, function ($a) use ($tid) {
    $at = isset($a['tenant_id']) ? (int) $a['tenant_id'] : 1;
    return $at === $tid;
}));

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
    if ($action === 'clear') {
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
            $w = isset($row['wholesale_price']) ? (float) $row['wholesale_price'] : 0;
            $ap = isset($row['agent_price']) ? (float) $row['agent_price'] : 0;
            $key = strtolower($profileName);
            $floor = isset($myFloor[$key]) ? (float) $myFloor[$key] : 0;
            $hasKids = function_exists('admin_user_child_count') && admin_user_child_count($pdo, $meId, $tid) > 0;
            if ($agentId === $meId) {
                $old = function_exists('agent_card_price_get') ? agent_card_price_get($pdo, $meId, $profileId, $profileName) : null;
                $locked = $old ? (float) $old['wholesale_price'] : 0;
                if ($floor <= 0 && $locked > 0) {
                    $floor = $locked;
                }
                if ($floor > 0 && $w < $floor) {
                    $w = $floor;
                }
                if (!$hasKids && $floor > 0) {
                    $w = $floor;
                }
            } else {
                if ($floor > 0 && $w < $floor) {
                    $w = $floor;
                }
                $child = function_exists('agent_card_price_get') ? agent_card_price_get($pdo, $agentId, $profileId, $profileName) : null;
                if ($child) {
                    $ap = (float) $child['agent_price'];
                }
            }
            if (agent_card_price_save($pdo, $agentId, $profileId, $profileName, $w, $ap)) {
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

render_header($isEn ? 'Card prices' : 'تسعير الكروت', 'agent_prices');
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
                    <th><?php echo e($isEn ? 'Price on agent (IQD)' : 'السعر عليه (د.ع)'); ?></th>
                    <th><?php echo e($isEn ? 'Subscriber sell price (IQD)' : 'سعر البيع للمشترك (د.ع)'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $i => $line):
                    $nm = isset($line['name']) ? (string) $line['name'] : '';
                    $key = strtolower(trim($nm));
                    $have = isset($byName[$key]) ? $byName[$key] : null;
                    $orig = $have ? (float) $have['wholesale_price'] : 0;
                    $sell = $have ? (float) $have['agent_price'] : 0;
                    $floorShow = ($aid !== $meId && isset($myFloor[$key])) ? (float) $myFloor[$key] : 0;
                    $lockWholesale = ($aid === $meId && $orig > 0);
                    ?>
                    <tr>
                        <td>
                            <?php echo e($nm); ?>
                            <input type="hidden" name="rows[<?php echo (int) $i; ?>][profile_name]" value="<?php echo e($nm); ?>">
                            <input type="hidden" name="rows[<?php echo (int) $i; ?>][profile_id]" value="<?php echo (int) (isset($line['id']) ? $line['id'] : 0); ?>">
                        </td>
                        <td><input class="ltr" type="number" min="<?php echo $floorShow > 0 ? (int) $floorShow : 0; ?>" step="1" name="rows[<?php echo (int) $i; ?>][wholesale_price]" value="<?php echo (int) $orig; ?>" style="max-width:140px" <?php echo $lockWholesale ? 'readonly' : ''; ?>></td>
                        <td><input class="ltr" type="number" min="0" step="1" name="rows[<?php echo (int) $i; ?>][agent_price]" value="<?php echo (int) $sell; ?>" style="max-width:140px" <?php echo ($aid !== $meId) ? 'readonly' : ''; ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
        </div>
    </form>
    <form method="post" style="margin-top:8px" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Clear this agent prices?' : 'تصفير أسعار هذا الوكيل؟'); ?>);">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="clear">
        <input type="hidden" name="agent_id" value="<?php echo $aid; ?>">
        <button class="btn ghost" type="submit"><?php echo e($isEn ? 'Reset' : 'تصفير'); ?></button>
    </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php render_footer(); ?>
