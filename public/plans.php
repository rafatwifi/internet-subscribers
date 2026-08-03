<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/settings_tabs.php';
require_login();
require_perm('plans');

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editPlan = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect('plans.php');
    }

    $action = post('action');

    if ($action === 'create' || $action === 'update') {
        $id = (int) post('id', '0');
        $name = trim((string) post('name', ''));
        $price = (float) post('monthly_price', '0');
        $cost = (float) post('cost_price', '0');
        $sort = (int) post('sort_order', '100');

        if ($name === '' || $price <= 0) {
            flash('error', 'اسم الباقة وسعر البيع مطلوبان');
            redirect($action === 'update' ? ('plans.php?edit=' . $id) : 'plans.php');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO service_plans (name, monthly_price, cost_price, sort_order, is_active)
                 VALUES (:name, :price, :cost, :sort, 1)'
            );
            $stmt->execute(array(
                ':name' => $name,
                ':price' => $price,
                ':cost' => $cost,
                ':sort' => $sort,
            ));
            flash('success', 'تمت إضافة الباقة');
        } else {
            if ($id <= 0) {
                flash('error', 'باقة غير موجودة');
                redirect('plans.php');
            }
            $stmt = $pdo->prepare(
                'UPDATE service_plans
                 SET name = :name, monthly_price = :price, cost_price = :cost, sort_order = :sort
                 WHERE id = :id'
            );
            $stmt->execute(array(
                ':id' => $id,
                ':name' => $name,
                ':price' => $price,
                ':cost' => $cost,
                ':sort' => $sort,
            ));
            flash('success', 'تم تعديل الباقة');
        }
        redirect('plans.php');
    }

    if ($action === 'reorder') {
        $orderRaw = (string) post('order', '');
        $ids = array_filter(array_map('intval', explode(',', $orderRaw)));
        $pos = 1;
        $stmt = $pdo->prepare('UPDATE service_plans SET sort_order = :s WHERE id = :id');
        foreach ($ids as $id) {
            if ($id > 0) {
                $stmt->execute(array(':s' => $pos, ':id' => $id));
                $pos++;
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => true));
        exit;
    }

    if ($action === 'toggle') {
        $id = (int) post('id', '0');
        $pdo->prepare('UPDATE service_plans SET is_active = IF(is_active=1,0,1) WHERE id = :id')
            ->execute(array(':id' => $id));
        flash('success', 'تم تحديث حالة الباقة');
        redirect('plans.php');
    }

    if ($action === 'delete') {
        $id = (int) post('id', '0');
        if ($id <= 0) {
            flash('error', 'باقة غير موجودة');
            redirect('plans.php');
        }
        $pdo->prepare('DELETE FROM service_plans WHERE id = :id')->execute(array(':id' => $id));
        flash('success', 'تم حذف الباقة');
        redirect('plans.php');
    }
}

if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM service_plans WHERE id = :id');
    $st->execute(array(':id' => $editId));
    $editPlan = $st->fetch();
    if (!$editPlan) {
        flash('error', 'الباقة غير موجودة');
        redirect('plans.php');
    }
}

$plans = $pdo->query('SELECT * FROM service_plans ORDER BY sort_order ASC, monthly_price ASC, id ASC')->fetchAll();
$nextSort = 1;
if ($plans) {
    $maxSort = 0;
    foreach ($plans as $p) {
        $maxSort = max($maxSort, (int) $p['sort_order']);
    }
    $nextSort = $maxSort + 1;
}

render_header(t('plans'), 'plans');
render_settings_tabs('plans');
?>

<div class="panel">
    <h2><?php echo $editPlan ? t('edit') . ' — ' . e($editPlan['name']) : 'إضافة باقة جديدة'; ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo $editPlan ? 'update' : 'create'; ?>">
        <?php if ($editPlan): ?>
            <input type="hidden" name="id" value="<?php echo (int) $editPlan['id']; ?>">
        <?php endif; ?>
        <div class="form-grid cols-4">
            <div>
                <label>اسم الباقة</label>
                <input name="name" placeholder="NB-MAX" required
                       value="<?php echo e($editPlan ? $editPlan['name'] : ''); ?>">
            </div>
            <div>
                <label>سعر البيع للمشترك</label>
                <input type="number" name="monthly_price" min="1000" step="1000" required
                       value="<?php echo e($editPlan ? (string) (int) $editPlan['monthly_price'] : ''); ?>">
            </div>
            <div>
                <label>سعر الجملة / تكلفتك</label>
                <input type="number" name="cost_price" min="0" step="1000" required
                       value="<?php echo e($editPlan ? (string) (int) $editPlan['cost_price'] : '0'); ?>">
            </div>
            <div>
                <label><?php echo e(t('sort_order')); ?></label>
                <input type="number" name="sort_order" min="1" step="1" required
                       value="<?php echo e($editPlan ? (string) (int) $editPlan['sort_order'] : (string) $nextSort); ?>">
                <small style="color:#6b7a88;font-weight:600">مثال: MAX=1 ثم NB-2=2 ثم NB-3=3 ثم NB-4=4</small>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo $editPlan ? 'حفظ التعديل' : 'حفظ الباقة'; ?></button>
            <?php if ($editPlan): ?>
                <a class="btn ghost" href="plans.php">إلغاء</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="panel">
    <h2>كل الباقات</h2>
    <p style="color:#6b7a88;margin:-4px 0 12px;font-weight:600"><?php echo e(t('drag_hint')); ?> — الأقل رقمًا يظهر أول بالقائمة</p>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th></th>
                <th>#</th>
                <th>التسلسل</th>
                <th>الباقة</th>
                <th>سعر البيع</th>
                <th>التكلفة</th>
                <th>الربح</th>
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
            </thead>
            <tbody id="plansBody">
            <?php if (!$plans): ?>
                <tr><td colspan="9">لا توجد باقات بعد</td></tr>
            <?php endif; ?>
            <?php foreach ($plans as $p): ?>
                <?php
                $sell = (float) $p['monthly_price'];
                $cost = isset($p['cost_price']) ? (float) $p['cost_price'] : 0;
                $profit = $sell - $cost;
                ?>
                <tr draggable="true" data-id="<?php echo (int) $p['id']; ?>">
                    <td class="drag-handle" title="اسحب">☰</td>
                    <td class="row-num"></td>
                    <td><strong><?php echo (int) $p['sort_order']; ?></strong></td>
                    <td><strong><?php echo e($p['name']); ?></strong></td>
                    <td><?php echo e(money_format_iqd($sell, $config['currency'])); ?></td>
                    <td><?php echo e(money_format_iqd($cost, $config['currency'])); ?></td>
                    <td><?php echo e(money_format_iqd($profit, $config['currency'])); ?></td>
                    <td>
                        <span class="badge <?php echo ((int) $p['is_active'] === 1) ? 'active' : 'expired'; ?>">
                            <?php echo ((int) $p['is_active'] === 1) ? 'مفعّلة' : 'موقوفة'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn sm secondary" href="plans.php?edit=<?php echo (int) $p['id']; ?>">تعديل</a>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                                <button class="btn sm ghost" type="submit">تفعيل/إيقاف</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('تحذف الباقة؟');">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                                <button class="btn sm danger" type="submit">حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
  var body = document.getElementById('plansBody');
  if (!body) return;
  var dragEl = null;
  var csrf = <?php echo json_encode(csrf_token()); ?>;

  function renumber() {
    var rows = body.querySelectorAll('tr[data-id]');
    for (var i = 0; i < rows.length; i++) {
      var num = rows[i].querySelector('.row-num');
      if (num) num.textContent = String(i + 1);
    }
  }

  function saveOrder() {
    var ids = [];
    var rows = body.querySelectorAll('tr[data-id]');
    for (var i = 0; i < rows.length; i++) ids.push(rows[i].getAttribute('data-id'));
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'reorder');
    fd.append('order', ids.join(','));
    fetch('plans.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function () {
        for (var i = 0; i < rows.length; i++) {
          var cell = rows[i].children[2];
          if (cell) cell.innerHTML = '<strong>' + (i + 1) + '</strong>';
        }
      })
      .catch(function () {});
  }

  body.addEventListener('dragstart', function (e) {
    var tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    dragEl = tr;
    tr.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });
  body.addEventListener('dragend', function () {
    if (dragEl) dragEl.classList.remove('dragging');
    dragEl = null;
    renumber();
    saveOrder();
  });
  body.addEventListener('dragover', function (e) {
    e.preventDefault();
    var tr = e.target.closest('tr[data-id]');
    if (!tr || !dragEl || tr === dragEl) return;
    var rect = tr.getBoundingClientRect();
    var before = (e.clientY - rect.top) < rect.height / 2;
    body.insertBefore(dragEl, before ? tr : tr.nextSibling);
  });

  renumber();
})();
</script>
<?php render_footer(); ?>
