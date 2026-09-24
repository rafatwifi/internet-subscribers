<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
if (!user_can('reports') && !user_can('agents') && !user_can('card_accounting')) {
    require_perm('reports');
}

$isEn = ($lang === 'en');
$tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$teamSql = '';
if (function_exists('is_group_manager_user') && is_group_manager_user()) {
    $team = group_manager_team_ids($pdo);
    if ($team) {
        $teamSql = ' AND t.to_agent_id IN (' . implode(',', array_map('intval', $team)) . ')';
    } else {
        $teamSql = ' AND 1=0';
    }
} elseif (function_exists('is_agent_user') && is_agent_user()) {
    $me = current_admin();
    $teamSql = ' AND t.to_agent_id = ' . (int) ($me ? $me['id'] : 0);
}

$rows = array();
try {
    $rows = $pdo->query(
        'SELECT t.created_at, t.qty, t.profile_name, t.wholesale_price, t.agent_price,
                (t.agent_price - t.wholesale_price) * t.qty AS profit,
                u.display_name AS agent_name, c.display_name AS by_name
         FROM agent_card_transfers t
         LEFT JOIN admin_users u ON u.id = t.to_agent_id
         LEFT JOIN admin_users c ON c.id = t.created_by
         WHERE (t.tenant_id = ' . (int) $tid . ' OR t.tenant_id IS NULL)' . $teamSql . '
         ORDER BY t.id DESC
         LIMIT 300'
    )->fetchAll();
} catch (Exception $e) {
    $rows = array();
}

render_header($isEn ? 'Activation profits' : 'أرباح التفعيل', 'reports');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Activations & profit' : 'التفعيلات والربح'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'Date, who activated, package, amount and profit (agent price − wholesale).'
        : 'التاريخ، الشخص، الباقة، المبلغ، والربح (سعر الوكيل − الجملة).'); ?></p>
    <div class="table-wrap">
        <table class="table-compact">
            <thead>
            <tr>
                <th><?php echo e($isEn ? 'When' : 'الوقت'); ?></th>
                <th><?php echo e($isEn ? 'Agent' : 'الوكيل'); ?></th>
                <th><?php echo e($isEn ? 'By' : 'المنفّذ'); ?></th>
                <th><?php echo e($isEn ? 'Package' : 'الباقة'); ?></th>
                <th><?php echo e($isEn ? 'Qty' : 'العدد'); ?></th>
                <th><?php echo e($isEn ? 'Amount' : 'المبلغ'); ?></th>
                <th><?php echo e($isEn ? 'Profit' : 'الربح'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7"><?php echo e($isEn ? 'No transfers yet' : 'ماكو تحويلات بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $amt = (float) $r['agent_price'] * (int) $r['qty'];
                ?>
                <tr>
                    <td class="ltr"><?php echo e($r['created_at']); ?></td>
                    <td><?php echo e($r['agent_name']); ?></td>
                    <td><?php echo e($r['by_name']); ?></td>
                    <td><?php echo e($r['profile_name']); ?></td>
                    <td><?php echo (int) $r['qty']; ?></td>
                    <td><?php echo e(number_format($amt, 0)); ?></td>
                    <td><?php echo e(number_format((float) $r['profit'], 0)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
