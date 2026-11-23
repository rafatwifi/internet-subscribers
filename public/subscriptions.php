<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('subscriptions');

$movScope = function_exists('subscriber_agent_scope_sql') ? subscriber_agent_scope_sql('s') : '';
if (function_exists('subscriber_ledger_owner_sql')) {
    $movScope .= subscriber_ledger_owner_sql('s');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $lang === 'en' ? 'Invalid request' : 'طلب غير صالح');
        redirect('subscriptions.php');
    }

    $action = post('action');

    if ($action === 'expire') {
        $id = (int) post('id', '0');
        $info = $pdo->prepare(
            'SELECT sub.subscriber_id, sub.service_name
             FROM subscriptions sub
             JOIN subscribers s ON s.id = sub.subscriber_id
             WHERE sub.id = :id' . $movScope . ' LIMIT 1'
        );
        $info->execute(array(':id' => $id));
        $subRow = $info->fetch();
        if ($subRow && function_exists('reverse_subscription_movement')) {
            list($okRev, $msgRev) = reverse_subscription_movement($pdo, $id);
            flash($okRev ? 'success' : 'error', $msgRev);
        } elseif ($subRow) {
            $pdo->prepare('UPDATE subscriptions SET status = "expired" WHERE id = :id')
                ->execute(array(':id' => $id));
            activity_log(
                $pdo,
                (int) $subRow['subscriber_id'],
                'subscription',
                $id,
                'expire',
                'إنهاء اشتراك: ' . $subRow['service_name'],
                ''
            );
            flash('success', $lang === 'en' ? 'Ended' : 'تم إنهاء الاشتراك');
        }
        redirect('subscriptions.php');
    }

    if ($action === 'delete_selected') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();
        $n = 0;
        $info = $pdo->prepare(
            'SELECT sub.subscriber_id, sub.service_name
             FROM subscriptions sub
             JOIN subscribers s ON s.id = sub.subscriber_id
             WHERE sub.id = :id' . $movScope . ' LIMIT 1'
        );
        $stmt = $pdo->prepare('DELETE FROM subscriptions WHERE id = :id');
        foreach ($ids as $idRaw) {
            $id = (int) $idRaw;
            if ($id > 0) {
                $info->execute(array(':id' => $id));
                $subRow = $info->fetch();
                if (!$subRow) {
                    continue;
                }
                $stmt->execute(array(':id' => $id));
                activity_log(
                    $pdo,
                    (int) $subRow['subscriber_id'],
                    'subscription',
                    $id,
                    'delete',
                    'حذف حركة اشتراك: ' . $subRow['service_name'],
                    ''
                );
                $n++;
            }
        }
        flash('success', ($lang === 'en' ? 'Deleted: ' : 'تم الحذف: ') . $n);
        redirect('subscriptions.php');
    }
}

$pdo->exec(
    "UPDATE subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     SET sub.status = 'expired'
     WHERE sub.status = 'active' AND sub.end_date < CURDATE()" . $movScope
);

$filterSid = isset($_GET['subscriber_id']) ? (int) $_GET['subscriber_id'] : 0;
$filterName = '';
$perPageRaw = isset($_GET['per_page']) ? (string) $_GET['per_page'] : '15';
$showAll = ($perPageRaw === 'all');
$perPage = $showAll ? 500 : max(1, (int) $perPageRaw);
if (!$showAll && $perPage < 1) {
    $perPage = 15;
}
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$listWhere = '1=1' . $movScope;
$listParams = array();
if ($filterSid > 0) {
    $listWhere .= ' AND sub.subscriber_id = :sid';
    $listParams[':sid'] = $filterSid;
}
$countSql = 'SELECT COUNT(*) FROM subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     WHERE ' . $listWhere;
$cst = $pdo->prepare($countSql);
$cst->execute($listParams);
$totalRows = (int) $cst->fetchColumn();
$totalPages = $showAll ? 1 : max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = $showAll ? 0 : (($page - 1) * $perPage);
$listSql = 'SELECT sub.*, s.name, s.phone
     FROM subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     WHERE ' . $listWhere . '
     ORDER BY sub.id DESC
     LIMIT ' . (int) $offset . ', ' . (int) $perPage;
$st = $pdo->prepare($listSql);
$st->execute($listParams);
$list = $st->fetchAll();
if ($filterSid > 0) {
    if ($list) {
        $filterName = $list[0]['name'];
    } else {
        $nm = $pdo->prepare('SELECT name FROM subscribers s WHERE s.id = :id' . $movScope . ' LIMIT 1');
        $nm->execute(array(':id' => $filterSid));
        $filterName = (string) $nm->fetchColumn();
    }
}
// تفعيلات الساس بدون صفوف subscriptions محلية — اعرضها كحركات
if ($totalRows <= 0 && $filterSid <= 0 && isset($pdo)) {
    try {
        $where = function_exists('dash_cache_owner_where') ? dash_cache_owner_where() : '';
        if ($where !== '') {
            $cntSas = $pdo->query(
                'SELECT COUNT(*) FROM sas_users_cache WHERE ' . $where . '
                   AND (sas_missing = 0 OR sas_missing IS NULL)'
            );
            $totalRows = $cntSas ? (int) $cntSas->fetchColumn() : 0;
            $totalPages = $showAll ? 1 : max(1, (int) ceil($totalRows / max(1, $perPage)));
            if ($totalRows <= 0) {
                $totalPages = 1;
            }
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $offset = $showAll ? 0 : (($page - 1) * $perPage);
            $lim = $showAll ? 500 : (int) $perPage;
            $sasList = $pdo->query(
                'SELECT display_name, username, phone, profile_name, profile_id,
                        COALESCE(sas_created_at, synced_at) AS sas_created_at, expire_at, local_subscriber_id
                 FROM sas_users_cache WHERE ' . $where . '
                   AND (sas_missing = 0 OR sas_missing IS NULL)
                 ORDER BY COALESCE(sas_created_at, synced_at) DESC
                 LIMIT ' . (int) $offset . ', ' . (int) $lim
            );
            if ($sasList) {
                foreach ($sasList->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                    $prof = isset($sr['profile_name']) ? (string) $sr['profile_name'] : '';
                    $pid = isset($sr['profile_id']) ? (int) $sr['profile_id'] : 0;
                    $sell = function_exists('report_resolve_sell_price')
                        ? (float) report_resolve_sell_price($pdo, $prof, $pid)
                        : 0.0;
                    $list[] = array(
                        'id' => 0,
                        'subscriber_id' => !empty($sr['local_subscriber_id']) ? (int) $sr['local_subscriber_id'] : 0,
                        'name' => trim((string) $sr['display_name']) !== '' ? $sr['display_name'] : $sr['username'],
                        'phone' => isset($sr['phone']) ? $sr['phone'] : '',
                        'service_name' => $prof,
                        'monthly_price' => $sell,
                        'start_date' => substr((string) $sr['sas_created_at'], 0, 10),
                        'end_date' => !empty($sr['expire_at']) ? substr((string) $sr['expire_at'], 0, 10) : '',
                        'status' => 'active',
                        'activation_msg_sent' => 0,
                        '_sas_only' => 1,
                    );
                }
            }
        }
    } catch (Exception $e) {
    }
}

render_header(t('movements'), 'subscriptions');
?>
<div class="panel panel-compact">
    <form method="post" id="movForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="delete_selected" id="movAction">
        <input type="hidden" name="id" value="0" id="movId">
        <div class="actions actions-tight" style="margin-top:0;margin-bottom:8px">
            <a class="btn secondary sm" href="activate.php<?php echo $filterSid > 0 ? ('?subscriber_id=' . $filterSid) : ''; ?>"><?php echo e(t('activate')); ?></a>
            <?php if ($filterSid > 0): ?>
                <span class="badge unpaid"><?php echo e($filterName !== '' ? $filterName : ('#' . $filterSid)); ?></span>
                <a class="btn ghost sm" href="subscriptions.php"><?php echo e(t('show_all')); ?></a>
                <a class="btn ghost sm" href="subscriber.php?id=<?php echo (int) $filterSid; ?>"><?php echo e($lang === 'en' ? 'Profile' : 'صفحة المشترك'); ?></a>
            <?php endif; ?>
            <button class="btn ghost sm" type="button" id="selAll"><?php echo e(t('select_all')); ?></button>
            <button class="btn danger sm" type="submit" onclick="return confirm('<?php echo e(t('confirm_delete')); ?>');"><?php echo e(t('delete_selected')); ?></button>
        </div>
        <h2><?php echo e(t('movements_list')); ?><?php echo $filterName !== '' ? (' — ' . e($filterName)) : ''; ?></h2>
        <div class="table-wrap">
            <table class="table-compact">
                <thead>
                <tr>
                    <th class="chk-col"><input type="checkbox" id="checkAll" class="chk-sm"></th>
                    <th>#</th>
                    <th><?php echo e(t('name')); ?></th>
                    <th><?php echo e(t('package')); ?></th>
                    <th><?php echo e(t('sell_price')); ?></th>
                    <th><?php echo e(t('from_date')); ?></th>
                    <th><?php echo e(t('to_date')); ?></th>
                    <th>WA</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php
                $i = $showAll ? 1 : (($page - 1) * $perPage + 1);
                foreach ($list as $row):
                    $sasOnly = !empty($row['_sas_only']) || (int) $row['id'] <= 0;
                    ?>
                    <tr>
                        <td class="chk-col"><?php if (!$sasOnly): ?><input type="checkbox" class="row-check chk-sm" name="ids[]" value="<?php echo (int) $row['id']; ?>"><?php endif; ?></td>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo e($row['name']); ?> <small><?php echo e(format_phone_display($row['phone'])); ?></small></td>
                        <td><?php echo e($row['service_name']); ?></td>
                        <td><?php echo e(money_format_iqd($row['monthly_price'], $config['currency'])); ?></td>
                        <td><?php echo e($row['start_date']); ?></td>
                        <td><?php echo e($row['end_date']); ?></td>
                        <td><?php echo ((int) $row['activation_msg_sent'] === 1) ? 'OK' : '-'; ?></td>
                        <td>
                            <?php if ($sasOnly): ?>
                                <span class="badge active"><?php echo e($lang === 'en' ? 'SAS' : 'ساس'); ?></span>
                            <?php elseif ($row['status'] === 'active'): ?>
                                <button class="btn sm danger" type="button"
                                        onclick="expireOne(<?php echo (int) $row['id']; ?>)"><?php echo e(t('expire')); ?></button>
                            <?php else: ?>
                                <span class="badge expired"><?php echo e($row['status']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$list): ?>
                    <tr><td colspan="9"><?php echo e($lang === 'en' ? 'No movements yet' : 'ماكو حركات بعد'); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalRows > 15 || $perPageRaw !== '15'): ?>
        <div class="actions" style="margin-top:12px;flex-wrap:wrap">
            <?php
            $movQs = array();
            if ($filterSid > 0) {
                $movQs[] = 'subscriber_id=' . (int) $filterSid;
            }
            if ($perPageRaw !== '15') {
                $movQs[] = 'per_page=' . rawurlencode($perPageRaw);
            }
            $movBase = $movQs ? ('&' . implode('&', $movQs)) : '';
            $movExtra = '';
            if ($filterSid > 0) {
                $movExtra .= '&subscriber_id=' . (int) $filterSid;
            }
            if (!$showAll && $totalPages > 1):
                for ($p = 1; $p <= $totalPages && $p <= 20; $p++):
            ?>
                <a class="btn <?php echo $p === $page ? '' : 'ghost'; ?> sm" href="?page=<?php echo $p . $movBase; ?>"><?php echo $p; ?></a>
            <?php
                endfor;
            endif;
            if (!$showAll):
            ?>
                <a class="btn ghost sm" href="?per_page=all<?php echo $movExtra; ?>"><?php echo e(t('show_all')); ?></a>
            <?php else: ?>
                <a class="btn ghost sm" href="?per_page=15<?php echo $movExtra; ?>">15</a>
            <?php endif; ?>
            <span class="muted" style="font-weight:700;font-size:12px;align-self:center">
                <?php echo e(($lang === 'en' ? 'Total: ' : 'المجموع: ') . (int) $totalRows); ?>
            </span>
        </div>
        <?php endif; ?>
    </form>
</div>
<script>
function expireOne(id) {
  if (!confirm(<?php echo json_encode($lang === 'en' ? 'End this subscription?' : 'إنهاء الاشتراك؟'); ?>)) return;
  var form = document.getElementById('movForm');
  document.getElementById('movAction').value = 'expire';
  document.getElementById('movId').value = String(id);
  form.submit();
}
(function () {
  var all = document.getElementById('checkAll');
  var selAll = document.getElementById('selAll');
  var boxes = document.querySelectorAll('.row-check');
  function setAll(v) {
    for (var i = 0; i < boxes.length; i++) boxes[i].checked = v;
    if (all) all.checked = v;
  }
  if (all) all.addEventListener('change', function () { setAll(all.checked); });
  if (selAll) selAll.addEventListener('click', function () { setAll(true); });
})();
</script>
<?php render_footer(); ?>
