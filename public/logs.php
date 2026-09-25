<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('logs');
if (function_exists('is_accountant_user') && is_accountant_user()) {
    flash('error', $lang === 'en' ? 'No permission' : 'المحاسب ما عنده لوك');
    redirect('index.php');
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$scope = isset($_GET['scope']) ? (string) $_GET['scope'] : '';
if ($scope === 'system' && (!function_exists('is_super_admin_user') || !is_super_admin_user())) {
    redirect('logs.php');
}
if ($scope === 'system' && function_exists('fetch_system_activity')) {
    $rows = fetch_system_activity($pdo, 400, $q);
} else {
    $rows = fetch_global_activity($pdo, 400, $q);
}
$title = ($scope === 'system')
    ? ($lang === 'en' ? 'System activity' : 'حركات النظام')
    : ($lang === 'en' ? 'Audit log' : 'اللوك');

render_header($title, $scope === 'system' ? 'system_activity' : 'logs');
?>
<div class="panel panel-compact">
    <div class="actions" style="margin-top:0;margin-bottom:10px">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1">
            <?php if ($scope === 'system'): ?><input type="hidden" name="scope" value="system"><?php endif; ?>
            <input name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e($lang === 'en' ? 'Search action, user, subscriber…' : 'بحث بالحركة أو المستخدم أو المشترك…'); ?>" style="max-width:320px">
            <button class="btn secondary sm" type="submit"><?php echo e($lang === 'en' ? 'Search' : 'بحث'); ?></button>
            <?php if ($q !== ''): ?>
                <a class="btn ghost sm" href="logs.php"><?php echo e(t('show_all')); ?></a>
            <?php endif; ?>
        </form>
    </div>
    <h2 style="margin-bottom:8px"><?php echo e($lang === 'en' ? 'Who changed what' : 'من غيّر إيش'); ?></h2>
    <div class="table-wrap">
        <table class="table-compact log-table">
            <thead>
            <tr>
                <th><?php echo e($lang === 'en' ? 'When' : 'الوقت'); ?></th>
                <th><?php echo e($lang === 'en' ? 'User' : 'المستخدم'); ?></th>
                <th><?php echo e($lang === 'en' ? 'Action' : 'الحركة'); ?></th>
                <th><?php echo e(t('subscribers')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Details' : 'التفاصيل'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5"><?php echo e($lang === 'en' ? 'No log entries yet' : 'ماكو حركات باللوك بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="nowrap"><?php echo e($row['created_at']); ?></td>
                    <td><strong><?php echo e($row['actor_name'] ? $row['actor_name'] : '—'); ?></strong></td>
                    <td>
                        <div><?php echo e($row['summary']); ?></div>
                        <small class="muted"><?php echo e($row['action']); ?></small>
                    </td>
                    <td>
                        <?php if (!empty($row['subscriber_id'])): ?>
                            <a href="subscriber.php?id=<?php echo (int) $row['subscriber_id']; ?>">
                                <?php echo e($row['subscriber_name'] ? $row['subscriber_name'] : ('#' . $row['subscriber_id'])); ?>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="log-details"><?php echo e($row['details'] ? $row['details'] : '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
