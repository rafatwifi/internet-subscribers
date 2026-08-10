<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

function flash_payment_result($ok, $errMsg, $details, $sendWa)
{
    global $config;
    if (!$ok) {
        flash('error', $errMsg);
        return;
    }
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $paid = money_format_iqd($details['paid_amount'], $currency);
    $remain = money_format_iqd($details['remaining_total'], $currency);
    $base = 'تم استلام ' . $paid . ' — المتبقي على المشترك: ' . $remain;
    if ($sendWa && isset($details['whatsapp_ok']) && $details['whatsapp_ok'] === true) {
        flash('success', $base . ' (واتساب تم)');
    } elseif ($sendWa && isset($details['whatsapp_ok']) && $details['whatsapp_ok'] === false) {
        $waFail = !empty($details['whatsapp_msg']) ? $details['whatsapp_msg'] : 'واتساب فشل';
        flash('info', $base . ' (' . $waFail . ')');
    } else {
        flash('success', $base);
    }
}

if (isset($_GET['pay_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $payId = (int) $_GET['pay_id'];
    $stmt = $pdo->prepare('SELECT amount FROM invoices WHERE id = :id AND status = "unpaid"');
    $stmt->execute(array(':id' => $payId));
    $amt = (float) $stmt->fetchColumn();
    list($ok, $msg, $details) = apply_invoice_payment($pdo, $config, $payId, $amt, true);
    flash_payment_result($ok, $msg, $details, true);
    redirect('debts.php?status=unpaid');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect('debts.php');
    }

    $action = post('action');
    $id = (int) post('id', '0');

    if ($action === 'pay') {
        $sendWa = post('send_whatsapp') === '1';
        $payAmount = (float) post('pay_amount', '0');
        if ($payAmount <= 0) {
            $st = $pdo->prepare('SELECT amount FROM invoices WHERE id = :id AND status = "unpaid"');
            $st->execute(array(':id' => $id));
            $payAmount = (float) $st->fetchColumn();
        }
        list($ok, $msg, $details) = apply_invoice_payment($pdo, $config, $id, $payAmount, $sendWa);
        flash_payment_result($ok, $msg, $details, $sendWa);
        $sid = isset($_POST['return_subscriber']) ? (int) $_POST['return_subscriber'] : 0;
        if ($sid > 0) {
            redirect('debts.php?status=unpaid&subscriber_id=' . $sid);
        }
        redirect('debts.php?status=unpaid');
    }

    if ($action === 'unpay') {
        $pdo->prepare(
            'UPDATE invoices SET status = "unpaid", paid_at = NULL, profit = 0 WHERE id = :id'
        )->execute(array(':id' => $id));
        flash('success', 'تم إرجاع الفاتورة لغير مسدد');
        redirect('debts.php?status=paid');
    }

    if ($action === 'add_invoice') {
        $subscriberId = (int) post('subscriber_id', '0');
        $amount = (float) post('amount', '0');
        $dueDate = (string) post('due_date', date('Y-m-d'));
        $notes = trim((string) post('notes', ''));
        $rawKind = (string) post('debt_kind', 'month');
        if ($rawKind === 'item') {
            $debtKind = 'item';
        } elseif ($rawKind === 'month_rent') {
            $debtKind = 'month_rent';
        } else {
            $debtKind = 'month';
        }
        $monthLabel = trim((string) post('month_label', date('Y-m')));

        if ($debtKind === 'item') {
            if ($monthLabel === '' || preg_match('/^\d{4}-\d{2}$/', $monthLabel)) {
                $monthLabel = 'غرض';
            }
        } elseif ($monthLabel === '') {
            $monthLabel = date('Y-m');
        }

        $subInfo = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $subInfo->execute(array(':id' => $subscriberId));
        $subInfoRow = $subInfo->fetch();

        if ($debtKind === 'month_rent') {
            if (!$subInfoRow || !subscriber_has_rental($subInfoRow)) {
                flash('error', 'هذا المشترك ما عنده إيجار');
                redirect('debts.php');
            }
            $settingsNow = settings_load();
            $rentFee = (float) rental_fee_amount($settingsNow);
            $subPrice = subscriber_monthly_price($pdo, $subscriberId);
            if ($amount <= 0) {
                $amount = $subPrice + $rentFee;
            }
            if ($notes === '') {
                $dev = rental_device_by_id(isset($subInfoRow['rental_device_id']) ? $subInfoRow['rental_device_id'] : '', $settingsNow);
                $notes = 'اشتراك ' . (int) $subPrice . ' + إيجار ' . (int) $rentFee
                    . ($dev && !empty($dev['name']) ? (' (' . $dev['name'] . ')') : '');
            }
        }

        if ($subscriberId <= 0 || $amount <= 0) {
            flash('error', 'بيانات الفاتورة ناقصة');
            redirect('debts.php');
        }

        $subscriptionId = null;
        $cost = 0;
        if ($debtKind === 'month' || $debtKind === 'month_rent') {
            $sub = $pdo->prepare(
                'SELECT id, cost_price FROM subscriptions WHERE subscriber_id = :sid ORDER BY id DESC LIMIT 1'
            );
            $sub->execute(array(':sid' => $subscriberId));
            $subRow = $sub->fetch();
            if ($subRow) {
                $subscriptionId = (int) $subRow['id'];
                $cost = (float) $subRow['cost_price'];
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO invoices (subscription_id, subscriber_id, month_label, amount, cost_price, due_date, status, notes)
             VALUES (:subscription_id, :subscriber_id, :month_label, :amount, :cost_price, :due_date, "unpaid", :notes)'
        );
        $stmt->execute(array(
            ':subscription_id' => $subscriptionId,
            ':subscriber_id' => $subscriberId,
            ':month_label' => $monthLabel,
            ':amount' => $amount,
            ':cost_price' => $cost,
            ':due_date' => $dueDate,
            ':notes' => ($notes !== '' ? $notes : null),
        ));

        $sendWa = post('send_whatsapp') === '1';
        if ($sendWa) {
            $info = $pdo->prepare('SELECT name, phone FROM subscribers WHERE id = :id');
            $info->execute(array(':id' => $subscriberId));
            $subRow = $info->fetch();
            if ($subRow) {
                $debtTotal = subscriber_unpaid_total($pdo, $subscriberId);
                $row = array(
                    'name' => $subRow['name'],
                    'phone' => $subRow['phone'],
                    'month_label' => $monthLabel,
                    'amount' => $amount,
                    'debt_total' => $debtTotal,
                    'notes' => $notes,
                );
                $msg = debt_created_message($row, $config);
                $result = whatsapp_send($config, $subRow['phone'], $msg, 'debt_created');
                log_message($pdo, $subscriberId, $result);
                if (!empty($result['success'])) {
                    flash('success', 'تمت إضافة الدين وإرسال واتساب');
                } else {
                    flash('info', 'تمت إضافة الدين لكن ' . whatsapp_fail_user_message($result));
                }
                redirect('debts.php?status=unpaid&subscriber_id=' . $subscriberId);
            }
        }

        flash('success', 'تمت إضافة الدين');
        redirect('debts.php?status=unpaid&subscriber_id=' . $subscriberId);
    }

    if ($action === 'remind') {
        $stmt = $pdo->prepare(
            'SELECT i.*, s.name, s.phone
             FROM invoices i
             JOIN subscribers s ON s.id = i.subscriber_id
             WHERE i.id = :id AND i.status = "unpaid"'
        );
        $stmt->execute(array(':id' => $id));
        $row = $stmt->fetch();
        if (!$row) {
            flash('error', 'الفاتورة غير موجودة أو مسددة');
            redirect('debts.php');
        }
        $row['debt_total'] = subscriber_unpaid_total($pdo, (int) $row['subscriber_id']);
        $msg = reminder_message($row, $config);
        $result = whatsapp_send($config, $row['phone'], $msg, 'reminder_manual');
        log_message($pdo, (int) $row['subscriber_id'], $result);
        if (!empty($result['success'])) {
            $pdo->prepare(
                'UPDATE invoices SET reminder_sent_at = NOW(), reminder_count = reminder_count + 1 WHERE id = :id'
            )->execute(array(':id' => $id));
            flash('success', 'تم إرسال تذكير واتساب');
        } else {
            flash('error', whatsapp_fail_user_message($result));
        }
        redirect('debts.php?status=unpaid');
    }
}

$status = isset($_GET['status']) ? $_GET['status'] : 'unpaid';
if (!in_array($status, array('unpaid', 'paid', 'all'), true)) {
    $status = 'unpaid';
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filterSubscriberId = isset($_GET['subscriber_id']) ? (int) $_GET['subscriber_id'] : 0;
$showAdd = isset($_GET['add']);

$sql = 'SELECT i.*, s.name, s.phone
        FROM invoices i
        JOIN subscribers s ON s.id = i.subscriber_id
        WHERE 1=1';
$params = array();
if ($status !== 'all') {
    $sql .= ' AND i.status = :status';
    $params[':status'] = $status;
}
if ($filterSubscriberId > 0) {
    $sql .= ' AND i.subscriber_id = :sid';
    $params[':sid'] = $filterSubscriberId;
}
if ($q !== '') {
    $sql .= ' AND (s.name LIKE :q OR s.phone LIKE :q OR i.notes LIKE :q2 OR i.month_label LIKE :q3)';
    $params[':q'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
$sql .= ' ORDER BY i.due_date ASC, i.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalDebt = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='unpaid'")->fetchColumn();
$subscribers = $pdo->query(
    'SELECT id, name, rental_enabled, rental_device_id FROM subscribers ORDER BY name'
)->fetchAll();
$settingsDebt = settings_load();
$rentFeeGlobal = (float) rental_fee_amount($settingsDebt);
$subPriceMap = array();
foreach ($subscribers as $s) {
    $subPriceMap[(int) $s['id']] = subscriber_monthly_price($pdo, (int) $s['id']);
}
$filterName = '';
if ($filterSubscriberId > 0) {
    foreach ($subscribers as $s) {
        if ((int) $s['id'] === $filterSubscriberId) {
            $filterName = $s['name'];
            break;
        }
    }
}

render_header(t('debts'), 'debts');
?>
<div class="cards">
    <a class="card-stat red" href="debts.php?status=unpaid">
        <div class="label"><?php echo e(t('debts_total')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($totalDebt, $config['currency'])); ?></div>
    </a>
</div>

<div class="panel">
    <div class="actions" style="margin-top:0">
        <button class="btn secondary" type="button" onclick="document.getElementById('addDebtBox').classList.toggle('hidden')"><?php echo e($lang === 'en' ? 'Add debt' : 'إضافة دين'); ?></button>
        <input id="debtFilter" placeholder="<?php echo e($lang === 'en' ? 'Instant search...' : 'بحث فوري اسم أو رقم...'); ?>" style="max-width:280px" value="<?php echo e($q); ?>">
        <?php if ($filterSubscriberId > 0): ?>
            <span class="badge unpaid"><?php echo e($filterName !== '' ? $filterName : ('#' . $filterSubscriberId)); ?></span>
            <a class="btn ghost" href="debts.php?status=unpaid"><?php echo e(t('show_all')); ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="panel collapse-box<?php echo $showAdd ? '' : ' hidden'; ?>" id="addDebtBox">
    <h2><?php echo e($lang === 'en' ? 'Add invoice / debt' : 'إضافة فاتورة / دين'); ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="add_invoice">
        <div class="form-grid">
            <div>
                <label><?php echo e(t('subscribers')); ?></label>
                <select name="subscriber_id" id="debtSubSelect" required>
                    <option value="">...</option>
                    <?php foreach ($subscribers as $s): ?>
                        <?php $sid = (int) $s['id']; $sHasRent = !empty($s['rental_enabled']) && !empty($s['rental_device_id']); ?>
                        <option value="<?php echo $sid; ?>"
                            data-rent="<?php echo $sHasRent ? '1' : '0'; ?>"
                            data-subprice="<?php echo (float) (isset($subPriceMap[$sid]) ? $subPriceMap[$sid] : 0); ?>"
                            data-rentfee="<?php echo (float) $rentFeeGlobal; ?>"
                            <?php echo $filterSubscriberId === $sid ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Debt type' : 'نوع الدين'); ?></label>
                <select name="debt_kind" id="debtKind">
                    <option value="month"><?php echo e(t('debt_type_month')); ?></option>
                    <option value="month_rent" id="debtKindMonthRent" hidden><?php echo e(t('debt_type_month_rent')); ?></option>
                    <option value="item"><?php echo e(t('debt_type_item')); ?></option>
                </select>
            </div>
            <div id="monthField">
                <label><?php echo e($lang === 'en' ? 'Month (YYYY-MM)' : 'الشهر (YYYY-MM)'); ?></label>
                <input name="month_label" id="monthLabelInput" value="<?php echo e(date('Y-m')); ?>">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Amount' : 'المبلغ'); ?></label>
                <input type="number" name="amount" id="debtAmountInput" min="1" step="1" required>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Due date' : 'تاريخ الاستحقاق'); ?></label>
                <input type="date" name="due_date" value="<?php echo e(date('Y-m-d')); ?>" required>
            </div>
            <div style="grid-column:1/-1">
                <label><?php echo e(t('debt_notes')); ?></label>
                <input name="notes" placeholder="<?php echo e($lang === 'en' ? 'e.g. bought router on credit' : 'مثال: اشترى راوتر بالدين'); ?>">
            </div>
        </div>
        <div class="actions">
            <label class="toggle">
                <input type="checkbox" name="send_whatsapp" value="1" checked>
                <span class="toggle-ui"></span>
                <span class="toggle-text"><?php echo e(t('send_whatsapp')); ?></span>
            </label>
            <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Save debt' : 'حفظ الدين'); ?></button>
        </div>
    </form>
</div>

<div class="panel">
    <div class="actions" style="margin-top:0">
        <a class="btn <?php echo $status === 'unpaid' ? '' : 'ghost'; ?>" href="?status=unpaid<?php echo $filterSubscriberId ? '&subscriber_id=' . $filterSubscriberId : ''; ?>"><?php echo e($lang === 'en' ? 'Unpaid' : 'غير مسدد'); ?></a>
        <a class="btn <?php echo $status === 'paid' ? '' : 'ghost'; ?>" href="?status=paid<?php echo $filterSubscriberId ? '&subscriber_id=' . $filterSubscriberId : ''; ?>"><?php echo e($lang === 'en' ? 'Paid' : 'مسدد'); ?></a>
        <a class="btn <?php echo $status === 'all' ? '' : 'ghost'; ?>" href="?status=all<?php echo $filterSubscriberId ? '&subscriber_id=' . $filterSubscriberId : ''; ?>"><?php echo e(t('show_all')); ?></a>
    </div>

    <div class="table-wrap" style="margin-top:0.9rem">
        <table id="debtTable" class="table-compact debts-mini-table">
            <thead>
            <tr>
                <th><?php echo e(t('name')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Month' : 'الشهر'); ?></th>
                <th><?php echo e($lang === 'en' ? 'Amount' : 'المبلغ'); ?></th>
                <th><?php echo e(t('notes')); ?></th>
                <?php if ($status !== 'unpaid'): ?>
                <th><?php echo e(t('profit')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Status' : 'الحالة'); ?></th>
                <?php endif; ?>
                <th><?php echo e($lang === 'en' ? 'Actions' : 'إجراءات'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7"><?php echo e($lang === 'en' ? 'No debts' : 'لا توجد ديون'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr data-filter="<?php echo e($row['name'] . ' ' . $row['phone'] . ' ' . (isset($row['notes']) ? $row['notes'] : '') . ' ' . $row['month_label']); ?>">
                    <td>
                        <a href="subscriber.php?id=<?php echo (int) $row['subscriber_id']; ?>"><strong><?php echo e($row['name']); ?></strong></a>
                        <div class="meta"><?php echo e(format_phone_display($row['phone'])); ?></div>
                    </td>
                    <td>
                        <strong><?php echo e(month_short_label($row['month_label'])); ?></strong>
                        <div class="meta"><?php echo e($row['due_date']); ?></div>
                    </td>
                    <td><strong><?php echo e(money_format_iqd($row['amount'], $config['currency'])); ?></strong></td>
                    <td class="notes-cell"><?php echo e(isset($row['notes']) ? $row['notes'] : ''); ?></td>
                    <?php if ($status !== 'unpaid'): ?>
                    <td><?php echo e(money_format_iqd(isset($row['profit']) ? $row['profit'] : 0, $config['currency'])); ?></td>
                    <td><span class="badge <?php echo e($row['status']); ?>"><?php echo $row['status'] === 'paid' ? ($lang === 'en' ? 'Paid' : 'مسدد') : ($lang === 'en' ? 'Unpaid' : 'غير مسدد'); ?></span></td>
                    <?php endif; ?>
                    <td>
                        <?php if ($row['status'] === 'unpaid'): ?>
                        <form method="post" class="pay-inline-form">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="pay">
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <?php if ($filterSubscriberId > 0): ?>
                                <input type="hidden" name="return_subscriber" value="<?php echo (int) $filterSubscriberId; ?>">
                            <?php endif; ?>
                            <div class="pay-inline-row">
                                <input type="number" name="pay_amount" min="1" step="1" value="<?php echo (int) $row['amount']; ?>" required title="<?php echo e(t('partial_pay')); ?>">
                                <button class="btn money sm" type="submit" name="send_whatsapp" value="1"><?php echo e(t('pay_send')); ?></button>
                                <button class="btn ghost sm" type="submit" name="send_whatsapp" value="0"><?php echo e(t('pay_only')); ?></button>
                            </div>
                        </form>
                        <form method="post" style="margin-top:4px">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="remind">
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <button class="btn secondary sm" type="submit"><?php echo e(t('remind')); ?></button>
                        </form>
                        <?php elseif ($row['status'] === 'paid'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="unpay">
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <button class="btn ghost sm" type="submit"><?php echo e($lang === 'en' ? 'Undo pay' : 'إلغاء التسديد'); ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(function () {
  var input = document.getElementById('debtFilter');
  if (input) {
    input.addEventListener('input', function () {
      var q = (input.value || '').toLowerCase();
      var items = document.querySelectorAll('[data-filter]');
      for (var i = 0; i < items.length; i++) {
        var hay = (items[i].getAttribute('data-filter') || '').toLowerCase();
        items[i].style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
      }
    });
  }
  var kind = document.getElementById('debtKind');
  var monthInput = document.getElementById('monthLabelInput');
  var monthField = document.getElementById('monthField');
  var subSelect = document.getElementById('debtSubSelect');
  var rentOpt = document.getElementById('debtKindMonthRent');
  var amountInput = document.getElementById('debtAmountInput');
  function selectedSubOpt() {
    if (!subSelect) return null;
    return subSelect.options[subSelect.selectedIndex] || null;
  }
  function syncRentOption() {
    var opt = selectedSubOpt();
    var hasRent = opt && opt.getAttribute('data-rent') === '1';
    if (rentOpt) {
      rentOpt.hidden = !hasRent;
      if (!hasRent && kind && kind.value === 'month_rent') {
        kind.value = 'month';
      }
    }
  }
  function syncKind() {
    if (!kind || !monthInput) return;
    if (kind.value === 'item') {
      monthInput.value = 'غرض';
      if (monthField) {
        var lab = monthField.querySelector('label');
        if (lab) lab.textContent = <?php echo json_encode($lang === 'en' ? 'Label' : 'وصف مختصر'); ?>;
      }
    } else {
      if (!/^\d{4}-\d{2}$/.test(monthInput.value)) {
        monthInput.value = <?php echo json_encode(date('Y-m')); ?>;
      }
      if (monthField) {
        var lab2 = monthField.querySelector('label');
        if (lab2) lab2.textContent = <?php echo json_encode($lang === 'en' ? 'Month (YYYY-MM)' : 'الشهر (YYYY-MM)'); ?>;
      }
    }
    if (kind.value === 'month_rent' && amountInput) {
      var opt = selectedSubOpt();
      if (opt) {
        var sub = parseFloat(opt.getAttribute('data-subprice') || '0') || 0;
        var rent = parseFloat(opt.getAttribute('data-rentfee') || '0') || 0;
        var total = Math.round(sub + rent);
        if (total > 0) amountInput.value = String(total);
      }
    }
  }
  if (kind) kind.addEventListener('change', syncKind);
  if (subSelect) subSelect.addEventListener('change', function () { syncRentOption(); syncKind(); });
  syncRentOption();
  syncKind();
})();
</script>
<?php render_footer(); ?>
