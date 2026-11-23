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

$perPageRaw = isset($_GET['per_page']) ? (string) $_GET['per_page'] : '15';
$showAll = ($perPageRaw === 'all');
$perPage = $showAll ? 400 : max(1, (int) $perPageRaw);
if (!$showAll && $perPage < 1) {
    $perPage = 15;
}
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$actScope = ($scope === 'system') ? 'system' : '';
$totalRows = function_exists('count_activity_filtered')
    ? count_activity_filtered($pdo, $q, $actScope)
    : 0;
$totalPages = $showAll ? 1 : max(1, (int) ceil($totalRows / max(1, $perPage)));
if ($totalRows <= 0) {
    $totalPages = 1;
}
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = $showAll ? 0 : (($page - 1) * $perPage);
$fetchLimit = $showAll ? 400 : $perPage;

if ($scope === 'system' && function_exists('fetch_system_activity')) {
    $rows = fetch_system_activity($pdo, $fetchLimit, $q, $offset);
} else {
    $rows = fetch_global_activity($pdo, $fetchLimit, $q, $offset);
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
            <?php if ($perPageRaw !== '15'): ?>
                <input type="hidden" name="per_page" value="<?php echo e($perPageRaw); ?>">
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
    <?php if ($totalRows > 15 || $perPageRaw !== '15'): ?>
    <div class="actions" style="margin-top:12px;flex-wrap:wrap">
        <?php
        $logQs = array();
        if ($scope === 'system') {
            $logQs[] = 'scope=system';
        } else {
            $logQs[] = 'scope=audit';
        }
        if ($q !== '') {
            $logQs[] = 'q=' . rawurlencode($q);
        }
        if ($perPageRaw !== '15') {
            $logQs[] = 'per_page=' . rawurlencode($perPageRaw);
        }
        $logBase = $logQs ? ('&' . implode('&', $logQs)) : '';
        $logExtra = '';
        if ($scope === 'system') {
            $logExtra .= '&scope=system';
        } else {
            $logExtra .= '&scope=audit';
        }
        if ($q !== '') {
            $logExtra .= '&q=' . rawurlencode($q);
        }
        if (!$showAll && $totalPages > 1):
            for ($p = 1; $p <= $totalPages && $p <= 20; $p++):
        ?>
            <a class="btn <?php echo $p === $page ? '' : 'ghost'; ?> sm" href="?page=<?php echo $p . $logBase; ?>"><?php echo $p; ?></a>
        <?php
            endfor;
        endif;
        if (!$showAll):
        ?>
            <a class="btn ghost sm" href="?per_page=all<?php echo $logExtra; ?>"><?php echo e(t('show_all')); ?></a>
        <?php else: ?>
            <a class="btn ghost sm" href="?per_page=15<?php echo $logExtra; ?>">15</a>
        <?php endif; ?>
        <span class="muted" style="font-weight:700;font-size:12px;align-self:center">
            <?php echo e(($isEn ? 'Total: ' : 'المجموع: ') . (int) $totalRows); ?>
        </span>
    </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
