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
    $ap = (float) $mp['agent_price'];
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
        redirect('agent_prices.php?agent=' . $agentId);
    }
    $action = post('action');
    $agentId = (int) post('agent_id', (string) $agentId);
    if ($isAgent) {
        $allowed = array($meId);
        foreach ($agents as $a) {
            $allowed[] = (int) $a['id'];
        }
        if (!in_array($agentId, $allowed, true)) {
            flash('error', $isEn ? 'Not allowed' : 'غير مسموح');
            redirect('agent_prices.php?agent=' . $meId);
        }
    }
    if ($action === 'save') {
        $profileId = (int) post('profile_id', '0');
        $profileName = trim((string) post('profile_name', ''));
        $w = (float) post('wholesale_price', '0');
        $ap = (float) post('agent_price', '0');
        if ($profileName === '') {
            flash('error', $isEn ? 'Package name required' : 'اسم الباقة مطلوب');
            redirect('agent_prices.php?agent=' . $agentId);
        }
        // إذا يسعّر على وكيل آخر: السعر >= سعره الحالي لنفس الباقة
        if ($isAgent && $agentId !== $meId) {
            $key = strtolower($profileName);
            $floor = isset($myFloor[$key]) ? (float) $myFloor[$key] : 0;
            if ($floor > 0 && $ap < $floor) {
                flash('error', $isEn
                    ? ('Agent price must be ≥ your price (' . $floor . ')')
                    : ('سعر الوكيل لازم يكون نفس سعرك أو أعلى (' . $floor . ')'));
                redirect('agent_prices.php?agent=' . $agentId);
            }
            if ($floor > 0 && $w < $floor) {
                $w = $floor;
            }
        }
        if (agent_card_price_save($pdo, $agentId, $profileId, $profileName, $w, $ap)) {
            flash('success', t('saved'));
        } else {
            flash('error', $isEn ? 'Save failed' : 'فشل الحفظ');
        }
        redirect('agent_prices.php?agent=' . $agentId);
    }
    if ($action === 'delete') {
        if ($isAgent && $agentId !== $meId) {
            // يسمح بحذف أسعار الوكلاء التابعين فقط إن أراد
        }
        agent_card_price_delete($pdo, (int) post('price_id', '0'), $agentId);
        flash('success', $isEn ? 'Deleted' : 'تم الحذف');
        redirect('agent_prices.php?agent=' . $agentId);
    }
}

$prices = $agentId > 0 ? agent_card_prices_list($pdo, $agentId) : array();
$agentName = '';
if ($agentId === $meId && $me) {
    $agentName = !empty($me['display_name']) ? $me['display_name'] : (isset($me['username']) ? $me['username'] : '');
}
foreach ($agents as $a) {
    if ((int) $a['id'] === $agentId) {
        $agentName = $a['display_name'];
        break;
    }
}

// قائمة الاختيار: أنا + وكلاء الشركة
$selectAgents = $agents;
if ($isAgent && $meId > 0) {
    $hasMe = false;
    foreach ($selectAgents as $a) {
        if ((int) $a['id'] === $meId) {
            $hasMe = true;
            break;
        }
    }
    if (!$hasMe) {
        array_unshift($selectAgents, array(
            'id' => $meId,
            'display_name' => $agentName !== '' ? $agentName : 'أنا',
            'username' => isset($me['username']) ? $me['username'] : '',
        ));
    }
}

render_header($isEn ? 'Card prices' : 'تسعير الكروت', 'agent_prices');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Card pricing' : 'تسعير الكروت'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'Your current prices. You can price sub-agents at the same price or higher.'
        : 'أسعارك الحالية. تقدر تسعّر على الوكلاء تحتك بنفس السعر أو أعلى.'); ?></p>

    <?php if ($selectAgents): ?>
    <form method="get" class="msg-toolbar" style="margin-bottom:14px">
        <label><?php echo e($isEn ? 'Agent' : 'الوكيل'); ?></label>
        <select name="agent" onchange="this.form.submit()">
            <?php foreach ($selectAgents as $a): ?>
                <option value="<?php echo (int) $a['id']; ?>"<?php echo (int) $a['id'] === $agentId ? ' selected' : ''; ?>>
                    <?php echo e($a['display_name']); ?>
                    <?php if (!empty($a['username'])): ?> (<?php echo e($a['username']); ?>)<?php endif; ?>
                    <?php if ((int) $a['id'] === $meId): ?> — <?php echo e($isEn ? 'me' : 'أنا'); ?><?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>

    <?php if ($agentId <= 0): ?>
        <p class="meta"><?php echo e($isEn ? 'No agents yet.' : 'ماكو وكلاء بعد.'); ?></p>
    <?php else: ?>
        <form method="post" class="panel" style="margin-bottom:16px;padding:12px">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="agent_id" value="<?php echo (int) $agentId; ?>">
            <h3 style="margin:0 0 10px;font-size:15px"><?php echo e($isEn ? 'Add / update price' : 'إضافة / تحديث سعر'); ?> — <?php echo e($agentName); ?></h3>
            <div class="form-grid cols-2">
                <label><?php echo e($isEn ? 'Package name' : 'اسم الباقة'); ?>
                    <input name="profile_name" required list="pkgHints">
                </label>
                <label><?php echo e($isEn ? 'Profile ID (optional)' : 'معرّف الباقة (اختياري)'); ?>
                    <input name="profile_id" type="number" min="0" value="0">
                </label>
                <label><?php echo e($isEn ? 'Wholesale' : 'سعر الجملة'); ?>
                    <input name="wholesale_price" type="number" min="0" step="0.01" value="0" required>
                </label>
                <label><?php echo e($isEn ? 'Agent price' : 'سعر الوكيل'); ?>
                    <input name="agent_price" type="number" min="0" step="0.01" value="0" required>
                </label>
            </div>
            <?php if ($isAgent && $agentId !== $meId && $myFloor): ?>
                <p class="meta"><?php echo e($isEn ? 'Minimum = your price for the same package.' : 'الحد الأدنى = سعرك لنفس الباقة.'); ?></p>
            <?php endif; ?>
            <div class="actions" style="margin-top:10px">
                <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
            </div>
        </form>

        <datalist id="pkgHints">
            <?php foreach ($myPrices as $mp): ?>
                <option value="<?php echo e($mp['profile_name']); ?>">
            <?php endforeach; ?>
        </datalist>

        <div class="table-wrap">
            <table class="table-compact">
                <thead>
                <tr>
                    <th><?php echo e($isEn ? 'Package' : 'الباقة'); ?></th>
                    <th><?php echo e($isEn ? 'Wholesale' : 'الجملة'); ?></th>
                    <th><?php echo e($isEn ? 'Agent price' : 'سعر الوكيل'); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$prices): ?>
                    <tr><td colspan="4" class="msg-empty"><?php echo e($isEn ? 'No prices yet' : 'ماكو أسعار بعد'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($prices as $p): ?>
                    <tr>
                        <td><?php echo e($p['profile_name']); ?></td>
                        <td class="ltr"><?php echo e($p['wholesale_price']); ?></td>
                        <td class="ltr"><?php echo e($p['agent_price']); ?></td>
                        <td>
                            <form method="post" style="display:inline" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Delete?' : 'حذف؟'); ?>);">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="agent_id" value="<?php echo (int) $agentId; ?>">
                                <input type="hidden" name="price_id" value="<?php echo (int) $p['id']; ?>">
                                <button class="btn ghost" type="submit"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
