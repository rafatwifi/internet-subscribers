<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
if (!user_can('reports') && !user_can('agents') && !user_can('card_accounting')) {
    require_perm('reports');
}
if (function_exists('ensure_card_accounting_tables')) {
    ensure_card_accounting_tables($pdo);
}

$isEn = ($lang === 'en');
$leafProfit = function_exists('account_viewer_is_leaf_child') && account_viewer_is_leaf_child($pdo);
if ($leafProfit) {
    $month = date('Y-m');
    $scope = function_exists('subscriber_agent_scope_sql') ? subscriber_agent_scope_sql('s') : '';
    $actRows = array();
    try {
        $stAct = $pdo->prepare(
            'SELECT sub.service_name, sub.monthly_price, s.name, s.phone
             FROM subscriptions sub
             JOIN subscribers s ON s.id = sub.subscriber_id
             WHERE DATE_FORMAT(sub.created_at, \'%Y-%m\') = :m' . $scope . '
             ORDER BY sub.id DESC'
        );
        $stAct->execute(array(':m' => $month));
        $actRows = $stAct->fetchAll();
    } catch (Exception $e) {
        $actRows = array();
    }
    $planCost = array();
    try {
        foreach ($pdo->query('SELECT name, cost_price, sas_profile_id FROM service_plans') as $pl) {
            $planCost[strtolower(trim((string) $pl['name']))] = $pl;
        }
    } catch (Exception $e) {
    }
    $sumSell = 0.0;
    $sumCost = 0.0;
    $sumProfit = 0.0;
    $lines = array();
    foreach ($actRows as $ar) {
        $nm = isset($ar['service_name']) ? (string) $ar['service_name'] : '';
        $key = strtolower(trim($nm));
        $sell = isset($ar['monthly_price']) ? (float) $ar['monthly_price'] : 0;
        $cat = isset($planCost[$key]) ? (float) $planCost[$key]['cost_price'] : 0;
        $sasId = isset($planCost[$key]['sas_profile_id']) ? (int) $planCost[$key]['sas_profile_id'] : 0;
        $his = function_exists('account_viewer_package_price')
            ? (float) account_viewer_package_price($pdo, $nm, $sasId, $cat)
            : $cat;
        $gain = $sell - $his;
        if ($gain < 0) {
            $gain = 0;
        }
        $sumSell += $sell;
        $sumCost += $his;
        $sumProfit += $gain;
        $lines[] = array(
            'name' => isset($ar['name']) ? (string) $ar['name'] : '',
            'phone' => isset($ar['phone']) ? (string) $ar['phone'] : '',
            'pkg' => $nm,
            'sell' => $sell,
            'cost' => $his,
            'profit' => $gain,
        );
    }
    render_header($isEn ? 'Activation profits' : 'أرباح التفعيل', 'profit');
    ?>
    <div class="panel">
        <h2><?php echo e($isEn ? 'Subscriber profit' : 'ربح المشتركين'); ?></h2>
        <p class="meta"><?php echo e($isEn
            ? 'Profit is the subscriber price minus your cost, for people you activate.'
            : 'الربح هو سعر المشترك ناقص التكلفة المحددة لك، على المشتركين اللي تفعّلهم.'); ?></p>
        <div class="cards">
            <div class="card-stat purple">
                <div class="label"><?php echo e($isEn ? 'Subscriber price' : 'سعر المشترك'); ?></div>
                <div class="value"><?php echo e(money_format_iqd($sumSell, $config['currency'])); ?></div>
            </div>
            <div class="card-stat cyan">
                <div class="label"><?php echo e($isEn ? 'Your cost' : 'التكلفة'); ?></div>
                <div class="value"><?php echo e(money_format_iqd($sumCost, $config['currency'])); ?></div>
            </div>
            <div class="card-stat green">
                <div class="label"><?php echo e($isEn ? 'Profit' : 'الربح'); ?></div>
                <div class="value"><?php echo e(money_format_iqd($sumProfit, $config['currency'])); ?></div>
            </div>
        </div>
        <div class="table-wrap" style="margin-top:12px">
            <table class="table-compact">
                <thead>
                <tr>
                    <th><?php echo e($isEn ? 'Subscriber' : 'المشترك'); ?></th>
                    <th><?php echo e($isEn ? 'Package' : 'الباقة'); ?></th>
                    <th><?php echo e($isEn ? 'Subscriber price' : 'سعر المشترك'); ?></th>
                    <th><?php echo e($isEn ? 'Cost' : 'التكلفة'); ?></th>
                    <th><?php echo e($isEn ? 'Profit' : 'الربح'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$lines): ?>
                    <tr><td colspan="5"><?php echo e($isEn ? 'No activations this month.' : 'ماكو تفعيلات هذا الشهر.'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($lines as $ln): ?>
                    <tr>
                        <td><?php echo e($ln['name']); ?><?php if ($ln['phone'] !== ''): ?><br><small><?php echo e(format_phone_display($ln['phone'])); ?></small><?php endif; ?></td>
                        <td><?php echo e($ln['pkg']); ?></td>
                        <td><?php echo e(money_format_iqd($ln['sell'], $config['currency'])); ?></td>
                        <td><?php echo e(money_format_iqd($ln['cost'], $config['currency'])); ?></td>
                        <td><?php echo e(money_format_iqd($ln['profit'], $config['currency'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    render_footer();
    exit;
}
$tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$me = function_exists('current_admin') ? current_admin() : null;
$homeId = function_exists('user_card_source_id') ? (int) user_card_source_id($pdo) : ($me ? (int) $me['id'] : 0);
$onlyIds = array();
if (function_exists('is_group_manager_user') && is_group_manager_user() && function_exists('group_manager_team_ids')) {
    $onlyIds = group_manager_team_ids($pdo);
} elseif (function_exists('is_agent_user') && is_agent_user() && $me) {
    $onlyIds = array((int) $me['id']);
}

$rows = array();
try {
    $rows = $pdo->query(
        'SELECT t.*, u.display_name AS to_name, u.username AS to_user,
                f.display_name AS from_name, f.username AS from_user,
                c.display_name AS by_name
         FROM agent_card_transfers t
         LEFT JOIN admin_users u ON u.id = t.to_agent_id
         LEFT JOIN admin_users f ON f.id = t.from_agent_id
         LEFT JOIN admin_users c ON c.id = t.created_by
         WHERE (t.tenant_id = ' . (int) $tid . ' OR t.tenant_id IS NULL OR t.tenant_id = 0)
         ORDER BY t.id DESC
         LIMIT 500'
    )->fetchAll();
} catch (Exception $e) {
    $rows = array();
}

$stockMap = array();
if (function_exists('card_sas_stock_map')) {
    $stockMap = card_sas_stock_map($pdo, $config, $homeId);
    if (isset($stockMap[$homeId])) {
        unset($stockMap[$homeId]);
    }
}

$paidMap = array();
try {
    $stPay = $pdo->query('SELECT agent_user_id, SUM(amount) AS amt FROM agent_card_payments GROUP BY agent_user_id');
    foreach ($stPay->fetchAll() as $pr) {
        $paidMap[(int) $pr['agent_user_id']] = (float) $pr['amt'];
    }
} catch (Exception $e) {
}

$agents = array();
$touch = function ($id, $name) use (&$agents) {
    $id = (int) $id;
    if ($id <= 0) {
        return;
    }
    if (!isset($agents[$id])) {
        $agents[$id] = array(
            'name' => $name !== '' ? $name : ('#' . $id),
            'taken' => 0,
            'returned' => 0,
            'profit' => 0.0,
            'sale' => 0.0,
            'lines' => array(),
        );
    } elseif ($name !== '' && strpos($agents[$id]['name'], '#') === 0) {
        $agents[$id]['name'] = $name;
    }
};
$lineAdd = function ($id, $name, $qty, $profit, $sale) use (&$agents) {
    if (!isset($agents[$id]['lines'][$name])) {
        $agents[$id]['lines'][$name] = array('qty' => 0, 'profit' => 0.0, 'sale' => 0.0);
    }
    $agents[$id]['lines'][$name]['qty'] += (int) $qty;
    $agents[$id]['lines'][$name]['profit'] += (float) $profit;
    $agents[$id]['lines'][$name]['sale'] += (float) $sale;
};

foreach ($rows as $r) {
    $toId = (int) $r['to_agent_id'];
    $fromId = isset($r['from_agent_id']) ? (int) $r['from_agent_id'] : 0;
    $qty = (int) $r['qty'];
    $sale = (float) $r['agent_price'] * $qty;
    $profit = ((float) $r['agent_price'] - (float) $r['wholesale_price']) * $qty;
    $pkg = trim((string) $r['profile_name']);
    $isReturn = !empty($r['return_of_id']);
    if ($isReturn && $fromId > 0) {
        $nm = trim((string) $r['from_name']);
        if ($nm === '' && !empty($r['from_user'])) {
            $nm = (string) $r['from_user'];
        }
        $touch($fromId, $nm);
        $agents[$fromId]['returned'] += $qty;
        $agents[$fromId]['profit'] -= $profit;
        $agents[$fromId]['sale'] -= $sale;
        $lineAdd($fromId, $pkg, 0 - $qty, 0 - $profit, 0 - $sale);
        continue;
    }
    if ($toId > 0 && $toId !== $homeId) {
        $nm = trim((string) $r['to_name']);
        if ($nm === '' && !empty($r['to_user'])) {
            $nm = (string) $r['to_user'];
        }
        $touch($toId, $nm);
        $agents[$toId]['taken'] += $qty;
        $agents[$toId]['profit'] += $profit;
        $agents[$toId]['sale'] += $sale;
        $lineAdd($toId, $pkg, $qty, $profit, $sale);
    }
}

if ($onlyIds) {
    $keep = array();
    foreach ($onlyIds as $oid) {
        $oid = (int) $oid;
        if (isset($agents[$oid])) {
            $keep[$oid] = $agents[$oid];
        }
    }
    $agents = $keep;
}

uasort($agents, function ($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

render_header($isEn ? 'Activation profits' : 'أرباح التفعيل', 'profit');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Profit by agent' : 'ربح كل وكيل'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'Cards taken, cards still with the agent, profit, and the amount he owes. Returns reduce the numbers.'
        : 'سحب الكروت، الشاغر عنده، الربح، والمبلغ اللي عليه. الاسترجاع ينقص الأرقام.'); ?></p>
    <?php if (!$agents): ?>
        <p class="meta"><?php echo e($isEn ? 'No card movements yet.' : 'ماكو حركات كروت بعد.'); ?></p>
    <?php endif; ?>
    <?php foreach ($agents as $aid => $ag):
        $stockQty = 0;
        $stockLines = isset($stockMap[$aid]) ? $stockMap[$aid] : array();
        foreach ($stockLines as $sq) {
            $stockQty += (int) $sq;
        }
        $paid = isset($paidMap[$aid]) ? (float) $paidMap[$aid] : 0.0;
        $owed = (float) $ag['sale'] - $paid;
        $netQty = (int) $ag['taken'] - (int) $ag['returned'];
        ?>
        <div class="panel" style="margin-top:12px;padding:14px">
            <h3 style="margin:0 0 8px"><?php echo e($ag['name']); ?></h3>
            <div class="table-wrap">
                <table class="table-compact">
                    <thead>
                    <tr>
                        <th><?php echo e($isEn ? 'Taken' : 'سحب كروت'); ?></th>
                        <th><?php echo e($isEn ? 'Returned' : 'استرجاع'); ?></th>
                        <th><?php echo e($isEn ? 'Net' : 'الصافي'); ?></th>
                        <th><?php echo e($isEn ? 'Still with him' : 'شاغر عنده'); ?></th>
                        <th><?php echo e($isEn ? 'Profit' : 'ربحنا منه'); ?></th>
                        <th><?php echo e($isEn ? 'Amount owed' : 'المبلغ اللي عليه'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><?php echo (int) $ag['taken']; ?></td>
                        <td><?php echo (int) $ag['returned']; ?></td>
                        <td><?php echo $netQty; ?></td>
                        <td><?php echo $stockQty; ?></td>
                        <td><?php echo e(number_format((float) $ag['profit'], 0)); ?></td>
                        <td><?php echo e(number_format($owed, 0)); ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <h4 style="margin:12px 0 6px"><?php echo e($isEn ? 'By package' : 'تفاصيل كل فئة'); ?></h4>
            <div class="table-wrap">
                <table class="table-compact">
                    <thead>
                    <tr>
                        <th><?php echo e($isEn ? 'Package' : 'الفئة'); ?></th>
                        <th><?php echo e($isEn ? 'Net qty' : 'الكمية'); ?></th>
                        <th><?php echo e($isEn ? 'Still with him' : 'شاغر'); ?></th>
                        <th><?php echo e($isEn ? 'Profit' : 'الربح'); ?></th>
                        <th><?php echo e($isEn ? 'Amount' : 'المبلغ'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $lineNames = array_unique(array_merge(array_keys($ag['lines']), array_keys($stockLines)));
                    sort($lineNames);
                    if (!$lineNames): ?>
                        <tr><td colspan="5"><?php echo e($isEn ? 'None' : 'ماكو'); ?></td></tr>
                    <?php endif;
                    foreach ($lineNames as $ln):
                        $lnRow = isset($ag['lines'][$ln]) ? $ag['lines'][$ln] : array('qty' => 0, 'profit' => 0, 'sale' => 0);
                        $lnStock = isset($stockLines[$ln]) ? (int) $stockLines[$ln] : 0;
                        ?>
                        <tr>
                            <td><?php echo e($ln); ?></td>
                            <td><?php echo (int) $lnRow['qty']; ?></td>
                            <td><?php echo $lnStock; ?></td>
                            <td><?php echo e(number_format((float) $lnRow['profit'], 0)); ?></td>
                            <td><?php echo e(number_format((float) $lnRow['sale'], 0)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="panel">
    <h2><?php echo e($isEn ? 'Movement log' : 'سجل الحركات'); ?></h2>
    <div class="table-wrap">
        <table class="table-compact">
            <thead>
            <tr>
                <th><?php echo e($isEn ? 'When' : 'الوقت'); ?></th>
                <th><?php echo e($isEn ? 'Type' : 'النوع'); ?></th>
                <th><?php echo e($isEn ? 'From' : 'من'); ?></th>
                <th><?php echo e($isEn ? 'To' : 'إلى'); ?></th>
                <th><?php echo e($isEn ? 'Package' : 'الفئة'); ?></th>
                <th><?php echo e($isEn ? 'Qty' : 'العدد'); ?></th>
                <th><?php echo e($isEn ? 'Amount' : 'المبلغ'); ?></th>
                <th><?php echo e($isEn ? 'Profit' : 'الربح'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8"><?php echo e($isEn ? 'No transfers yet' : 'ماكو تحويلات بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                if ($onlyIds) {
                    $hit = in_array((int) $r['to_agent_id'], $onlyIds, true) || in_array((int) $r['from_agent_id'], $onlyIds, true);
                    if (!$hit) {
                        continue;
                    }
                }
                $isReturn = !empty($r['return_of_id']);
                $amt = (float) $r['agent_price'] * (int) $r['qty'];
                $profit = ((float) $r['agent_price'] - (float) $r['wholesale_price']) * (int) $r['qty'];
                if ($isReturn) {
                    $amt = 0 - $amt;
                    $profit = 0 - $profit;
                }
                $fromLbl = trim((string) $r['from_name']);
                if ($fromLbl === '') {
                    $fromLbl = $isEn ? 'Agency' : 'الوكالة';
                }
                ?>
                <tr>
                    <td class="ltr"><?php echo e($r['created_at']); ?></td>
                    <td><?php echo e($isReturn ? ($isEn ? 'Return' : 'استرجاع') : ($isEn ? 'Transfer' : 'تحويل')); ?></td>
                    <td><?php echo e($fromLbl); ?></td>
                    <td><?php echo e($r['to_name']); ?></td>
                    <td><?php echo e($r['profile_name']); ?></td>
                    <td><?php echo (int) $r['qty']; ?></td>
                    <td><?php echo e(number_format($amt, 0)); ?></td>
                    <td><?php echo e(number_format($profit, 0)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
