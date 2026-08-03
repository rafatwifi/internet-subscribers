<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('subscriptions');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $lang === 'en' ? 'Invalid request' : 'طلب غير صالح');
        redirect('subscriptions.php');
    }

    $action = post('action');

    if ($action === 'expire') {
        $id = (int) post('id', '0');
        $info = $pdo->prepare('SELECT subscriber_id, service_name FROM subscriptions WHERE id = :id');
        $info->execute(array(':id' => $id));
        $subRow = $info->fetch();
        $pdo->prepare('UPDATE subscriptions SET status = "expired" WHERE id = :id')
            ->execute(array(':id' => $id));
        if ($subRow) {
            activity_log(
                $pdo,
                (int) $subRow['subscriber_id'],
                'subscription',
                $id,
                'expire',
                'إنهاء اشتراك: ' . $subRow['service_name'],
                ''
            );
        }
        flash('success', $lang === 'en' ? 'Ended' : 'تم إنهاء الاشتراك');
        redirect('subscriptions.php');
    }

    if ($action === 'delete_selected') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();
        $n = 0;
        $info = $pdo->prepare('SELECT subscriber_id, service_name FROM subscriptions WHERE id = :id');
        $stmt = $pdo->prepare('DELETE FROM subscriptions WHERE id = :id');
        foreach ($ids as $idRaw) {
            $id = (int) $idRaw;
            if ($id > 0) {
                $info->execute(array(':id' => $id));
                $subRow = $info->fetch();
                $stmt->execute(array(':id' => $id));
                if ($subRow) {
                    activity_log(
                        $pdo,
                        (int) $subRow['subscriber_id'],
                        'subscription',
                        $id,
                        'delete',
                        'حذف حركة اشتراك: ' . $subRow['service_name'],
                        ''
                    );
                }
                $n++;
            }
        }
        flash('success', ($lang === 'en' ? 'Deleted: ' : 'تم الحذف: ') . $n);
        redirect('subscriptions.php');
    }
}

$pdo->exec(
    "UPDATE subscriptions SET status = 'expired'
     WHERE status = 'active' AND end_date < CURDATE()"
);

$list = $pdo->query(
    "SELECT sub.*, s.name, s.phone
     FROM subscriptions sub
     JOIN subscribers s ON s.id = sub.subscriber_id
     ORDER BY sub.id DESC
     LIMIT 500"
)->fetchAll();

render_header(t('movements'), 'subscriptions');
?>
<div class="panel panel-compact">
    <form method="post" id="movForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="delete_selected" id="movAction">
        <input type="hidden" name="id" value="0" id="movId">
        <div class="actions actions-tight" style="margin-top:0;margin-bottom:8px">
            <a class="btn secondary sm" href="activate.php"><?php echo e(t('activate')); ?></a>
            <button class="btn ghost sm" type="button" id="selAll"><?php echo e(t('select_all')); ?></button>
            <button class="btn danger sm" type="submit" onclick="return confirm('<?php echo e(t('confirm_delete')); ?>');"><?php echo e(t('delete_selected')); ?></button>
        </div>
        <h2><?php echo e(t('movements_list')); ?></h2>
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
                <?php $i = 1; foreach ($list as $row): ?>
                    <tr>
                        <td class="chk-col"><input type="checkbox" class="row-check chk-sm" name="ids[]" value="<?php echo (int) $row['id']; ?>"></td>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo e($row['name']); ?> <small><?php echo e(format_phone_display($row['phone'])); ?></small></td>
                        <td><?php echo e($row['service_name']); ?></td>
                        <td><?php echo e(money_format_iqd($row['monthly_price'], $config['currency'])); ?></td>
                        <td><?php echo e($row['start_date']); ?></td>
                        <td><?php echo e($row['end_date']); ?></td>
                        <td><?php echo ((int) $row['activation_msg_sent'] === 1) ? 'OK' : '-'; ?></td>
                        <td>
                            <?php if ($row['status'] === 'active'): ?>
                                <button class="btn sm danger" type="button"
                                        onclick="expireOne(<?php echo (int) $row['id']; ?>)"><?php echo e(t('expire')); ?></button>
                            <?php else: ?>
                                <span class="badge expired"><?php echo e($row['status']); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
