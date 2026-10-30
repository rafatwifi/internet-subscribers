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
if ($scope === 'audit') {
    $scope = '';
}
$isEn = (isset($lang) && $lang === 'en');
$isPlatform = function_exists('is_super_admin_user') && is_super_admin_user()
    && !(function_exists('is_impersonating') && is_impersonating());

if ($scope === 'system' && !$isPlatform) {
    redirect('logs.php');
}
if ($scope === 'system' && function_exists('fetch_system_activity')) {
    $rows = fetch_system_activity($pdo, 400, $q);
} else {
    $rows = fetch_global_activity($pdo, 400, $q);
}
$title = ($scope === 'system')
    ? ($isEn ? 'System activity' : 'حركات النظام')
    : ($isEn ? 'Audit log' : 'اللوك');
$navActive = ($scope === 'system') ? 'system_activity' : 'logs';
$selfBase = ($scope === 'system') ? 'logs.php?scope=system' : 'logs.php?scope=audit';

render_header($title, $navActive);
?>
<div class="panel panel-compact">
    <div class="actions" style="margin-top:0;margin-bottom:10px">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1">
            <?php if ($scope === 'system'): ?>
                <input type="hidden" name="scope" value="system">
            <?php else: ?>
                <input type="hidden" name="scope" value="audit">
            <?php endif; ?>
            <input name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e($isEn ? 'Search action or user…' : 'بحث بالحركة أو المستخدم…'); ?>" style="max-width:320px">
            <button class="btn secondary sm" type="submit"><?php echo e($isEn ? 'Search' : 'بحث'); ?></button>
            <?php if ($q !== ''): ?>
                <a class="btn ghost sm" href="<?php echo e($selfBase); ?>"><?php echo e(t('show_all')); ?></a>
            <?php endif; ?>
        </form>
    </div>
    <h2 style="margin-bottom:8px"><?php echo e($isEn ? 'Who changed what' : 'من غيّر إيش'); ?></h2>
    <div class="table-wrap">
        <table class="table-compact log-table">
            <thead>
            <tr>
                <th><?php echo e($isEn ? 'When' : 'الوقت'); ?></th>
                <th><?php echo e($isEn ? 'User' : 'المستخدم'); ?></th>
                <th><?php echo e($isEn ? 'Action' : 'الحركة'); ?></th>
                <th><?php echo e($isEn ? 'IP' : 'عنوان IP'); ?></th>
                <th><?php echo e($isEn ? 'Details' : 'التفاصيل'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="5"><?php echo e($isEn ? 'No log entries yet' : 'ماكو حركات باللوك بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row):
                $ip = isset($row['actor_ip']) ? trim((string) $row['actor_ip']) : '';
                $kind = isset($row['actor_ip_kind']) ? (string) $row['actor_ip_kind'] : '';
                if ($ip !== '' && $kind === '' && function_exists('activity_ip_meta')) {
                    $meta = activity_ip_meta($ip);
                    $kind = $meta['kind'];
                }
                $ipLabel = '—';
                if ($ip !== '') {
                    $kindTxt = function_exists('activity_ip_kind_label')
                        ? activity_ip_kind_label($kind, $isEn)
                        : $kind;
                    $ipLabel = $ip . ' · ' . $kindTxt;
                }
                $detailBits = array();
                if (!empty($row['details'])) {
                    $detailBits[] = (string) $row['details'];
                }
                if (!empty($row['subscriber_id'])) {
                    $subName = !empty($row['subscriber_name'])
                        ? (string) $row['subscriber_name']
                        : ('#' . (int) $row['subscriber_id']);
                    $detailBits[] = ($isEn ? 'Related: ' : 'متعلق: ') . $subName;
                }
                $detailText = $detailBits ? implode("\n", $detailBits) : '—';
                ?>
                <tr>
                    <td class="nowrap"><?php echo e($row['created_at']); ?></td>
                    <td><strong><?php echo e($row['actor_name'] ? $row['actor_name'] : '—'); ?></strong></td>
                    <td>
                        <div><?php echo e($row['summary']); ?></div>
                        <small class="muted"><?php echo e($row['action']); ?></small>
                    </td>
                    <td class="nowrap ltr" style="direction:ltr;unicode-bidi:isolate;text-align:left">
                        <?php echo e($ipLabel); ?>
                    </td>
                    <td class="log-details"><?php echo e($detailText); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
