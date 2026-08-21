<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    flash('error', 'مشترك غير موجود');
    redirect('subscribers.php');
}
require_subscriber_access($pdo, $id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect('subscriber.php?id=' . $id);
    }

    $action = post('action');

    if ($action === 'update') {
        $name = normalize_subscriber_name((string) post('name', ''));
        $phone = normalize_phone((string) post('phone', ''));
        $address = post('address');
        $notes = post('notes');

        if ($name === '' || $phone === '') {
            flash('error', 'الاسم والهاتف مطلوبان');
            redirect('subscriber.php?id=' . $id);
        }
        if (subscriber_name_taken($pdo, $name, $id)) {
            flash('error', 'الاسم مكرر — اختر اسماً مختلفاً');
            redirect('subscriber.php?id=' . $id . '&edit=1');
        }

        try {
            $stmt = $pdo->prepare(
                'UPDATE subscribers
                 SET name = :name, phone = :phone, address = :address, notes = :notes
                 WHERE id = :id'
            );
            $stmt->execute(array(
                ':id' => $id,
                ':name' => $name,
                ':phone' => $phone,
                ':address' => ($address !== '' && $address !== null) ? $address : null,
                ':notes' => ($notes !== '' && $notes !== null) ? $notes : null,
            ));
            flash('success', 'تم تعديل المشترك');
            activity_log($pdo, $id, 'subscriber', $id, 'update', 'تعديل بيانات المشترك', $name . ' / ' . $phone);
        } catch (PDOException $e) {
            $msg = 'تعذر التعديل. تحقق من البيانات وحاول مرة أخرى.';
            if (stripos($e->getMessage(), 'uq_name') !== false || (int) $e->getCode() === 23000) {
                $msg = 'الاسم مكرر — اختر اسماً مختلفاً';
            }
            flash('error', $msg);
        }
        redirect('subscriber.php?id=' . $id);
    }

    if ($action === 'change_plan') {
        $planId = (int) post('plan_id', '0');
        list($ok, $msg) = change_subscriber_plan($pdo, $config, $id, $planId);
        flash($ok ? 'success' : 'error', $msg);
        redirect('subscriber.php?id=' . $id);
    }

    if ($action === 'give_test') {
        list($ok, $msg) = activate_subscriber_test($pdo, $config, $id);
        flash($ok ? 'success' : 'error', $msg);
        redirect('subscriber.php?id=' . $id);
    }

    if ($action === 'update_rental') {
        $enabled = post('rental_enabled') === '1';
        $deviceId = trim((string) post('rental_device_id', ''));
        if ($enabled && $deviceId === '') {
            flash('error', 'اختر نوع الجهاز');
            redirect('subscriber.php?id=' . $id . '#rental');
        }
        if ($enabled && !rental_device_by_id($deviceId)) {
            flash('error', 'نوع الجهاز غير معروف — أضفه من الإعدادات');
            redirect('subscriber.php?id=' . $id . '#rental');
        }
        if (!$enabled) {
            $deviceId = null;
        }

        $oldSt = $pdo->prepare('SELECT rental_enabled, rental_device_id FROM subscribers WHERE id = :id');
        $oldSt->execute(array(':id' => $id));
        $oldRent = $oldSt->fetch();
        $wasOn = $oldRent ? subscriber_has_rental($oldRent) : false;

        $pdo->prepare(
            'UPDATE subscribers SET rental_enabled = :en, rental_device_id = :dev WHERE id = :id'
        )->execute(array(
            ':en' => $enabled ? 1 : 0,
            ':dev' => $deviceId,
            ':id' => $id,
        ));
        $dev = $enabled ? rental_device_by_id($deviceId) : null;
        activity_log(
            $pdo,
            $id,
            'subscriber',
            $id,
            $enabled ? 'rental_on' : 'rental_off',
            $enabled ? ('تفعيل إيجار: ' . ($dev ? $dev['name'] : $deviceId)) : 'إيقاف جهاز الإيجار',
            $enabled ? ('الرسوم الشهرية: ' . rental_fee_amount()) : ''
        );

        $flashMsg = $enabled ? 'تم حفظ جهاز الإيجار' : 'تم إيقاف جهاز الإيجار';
        // إذا فعّلنا الإيجار الآن والمشترك نشط — يُضاف مبلغ الإيجار كدين فوراً
        if ($enabled && !$wasOn && subscriber_is_active($pdo, $id)) {
            list($debtOk, $debtVal) = add_immediate_rental_debt($pdo, $id, $deviceId);
            if ($debtOk) {
                $flashMsg .= ' — أُضيف دين إيجار ' . money_format_iqd($debtVal, $config['currency']) . ' للحساب';
            }
        }
        flash('success', $flashMsg);
        redirect('subscriber.php?id=' . $id . '#rental');
    }

    if ($action === 'msg_rental_only') {
        $st = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $st->execute(array(':id' => $id));
        $sub = $st->fetch();
        if (!$sub || !subscriber_has_rental($sub)) {
            flash('error', 'لا يوجد جهاز إيجار لهذا المشترك');
            redirect('subscriber.php?id=' . $id . '#rental');
        }
        $msg = rental_only_message($sub, $config);
        $result = whatsapp_send($config, $sub['phone'], $msg, 'rental_fee');
        log_message($pdo, $id, $result);
        flash(!empty($result['success']) ? 'success' : 'error', !empty($result['success']) ? 'تم إرسال رسالة الإيجار' : whatsapp_fail_user_message($result));
        redirect('subscriber.php?id=' . $id . '#messages');
    }

    if ($action === 'msg_rental_return') {
        $st = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $st->execute(array(':id' => $id));
        $sub = $st->fetch();
        if (!$sub || !subscriber_has_rental($sub)) {
            flash('error', 'لا يوجد جهاز إيجار لهذا المشترك');
            redirect('subscriber.php?id=' . $id . '#rental');
        }
        $msg = rental_return_message($sub, $config);
        $result = whatsapp_send($config, $sub['phone'], $msg, 'rental_return');
        log_message($pdo, $id, $result);
        activity_log($pdo, $id, 'subscriber', $id, 'rental_return', 'طلب استرجاع جهاز', '');
        flash(!empty($result['success']) ? 'success' : 'error', !empty($result['success']) ? 'تم إرسال طلب استرجاع الجهاز' : whatsapp_fail_user_message($result));
        redirect('subscriber.php?id=' . $id . '#messages');
    }

    if ($action === 'pay_debts') {
        $mode = post('pay_mode', 'full') === 'partial' ? 'partial' : 'full';
        $sendWa = post('send_whatsapp') === '1';
        $targetInvoiceId = (int) post('invoice_id', '0');
        $totalDue = subscriber_unpaid_total($pdo, $id);
        if ($totalDue <= 0) {
            flash('error', 'ماكو ديون غير مسددة');
            redirect('subscriber.php?id=' . $id . '&tab=pay#debts');
        }
        if ($mode === 'partial') {
            if ($targetInvoiceId <= 0) {
                flash('error', 'اختر أي دين تريد تسديده');
                redirect('subscriber.php?id=' . $id . '&tab=pay#debts');
            }
            $invChk = $pdo->prepare(
                'SELECT amount FROM invoices
                 WHERE id = :iid AND subscriber_id = :sid AND status = "unpaid"'
            );
            $invChk->execute(array(':iid' => $targetInvoiceId, ':sid' => $id));
            $invDue = $invChk->fetchColumn();
            if ($invDue === false) {
                flash('error', 'الدين المختار غير موجود أو مسدد');
                redirect('subscriber.php?id=' . $id . '&tab=pay#debts');
            }
            $payAmount = (float) post('pay_amount', '0');
            if ($payAmount <= 0) {
                flash('error', 'أدخل مبلغ تسديد صحيح');
                redirect('subscriber.php?id=' . $id . '&tab=pay#debts');
            }
            if ($payAmount > (float) $invDue) {
                $payAmount = (float) $invDue;
            }
        } else {
            $payAmount = $totalDue;
            $targetInvoiceId = 0;
        }
        list($ok, $msg, $details) = apply_subscriber_payment(
            $pdo,
            $config,
            $id,
            $payAmount,
            $sendWa,
            $targetInvoiceId
        );
        if (!$ok) {
            flash('error', $msg);
        } else {
            $paidAmt = isset($details['paid_amount']) ? (float) $details['paid_amount'] : $payAmount;
            $remain = isset($details['remaining_total']) ? (float) $details['remaining_total'] : 0;
            $note = 'تم تسديد ' . money_format_iqd($paidAmt, $config['currency']);
            if ($remain > 0) {
                $note .= ' — المتبقي ' . money_format_iqd($remain, $config['currency']);
            }
            if ($sendWa) {
                if (!empty($details['whatsapp_ok'])) {
                    $note .= ' + واتساب';
                } elseif (!empty($details['whatsapp_msg'])) {
                    $note .= ' — ' . $details['whatsapp_msg'];
                }
            }
            flash('success', $note);
        }
        redirect('subscriber.php?id=' . $id . '&tab=pay#debts');
    }

    if ($action === 'add_debts') {
        $debtInfo = insert_opening_debts($pdo, $id, $_POST);
        $debtCount = is_array($debtInfo) ? (int) $debtInfo['count'] : (int) $debtInfo;
        if ($debtCount > 0) {
            $sendWa = post('send_whatsapp') === '1';
            $waNote = '';
            if ($sendWa) {
                $info = $pdo->prepare('SELECT name, phone FROM subscribers WHERE id = :id');
                $info->execute(array(':id' => $id));
                $subRow = $info->fetch();
                if ($subRow) {
                    $useCustom = post('use_custom_msg') === '1';
                    $customBody = trim((string) post('custom_msg', ''));
                    if ($useCustom && $customBody !== '') {
                        $msg = $customBody;
                    } else {
                        $debtTotal = subscriber_unpaid_total($pdo, $id);
                        $msg = reminder_message(array(
                            'name' => $subRow['name'],
                            'phone' => $subRow['phone'],
                            'month_label' => date('Y-m'),
                            'amount' => $debtTotal,
                            'debt_total' => $debtTotal,
                            'notes' => '',
                        ), $config);
                    }
                    $result = whatsapp_send($config, $subRow['phone'], $msg, 'debt_created');
                    log_message($pdo, $id, $result);
                    $waNote = !empty($result['success'])
                        ? ' وتم إرسال واتساب'
                        : (' لكن ' . whatsapp_fail_user_message($result));
                }
            }
            flash('success', 'تمت إضافة ' . $debtCount . ' دين' . $waNote);
        } else {
            flash('error', 'أدخل مبلغ دين واحد على الأقل');
        }
        redirect('subscriber.php?id=' . $id . '&tab=list#debts');
    }

    if ($action === 'update_invoice') {
        $invId = (int) post('invoice_id', '0');
        $monthLabel = trim((string) post('month_label', ''));
        $amount = (float) post('amount', '0');
        $dueDate = trim((string) post('due_date', ''));
        $notes = trim((string) post('notes', ''));
        if ($invId <= 0 || $amount <= 0 || $monthLabel === '' || $dueDate === '') {
            flash('error', 'بيانات الدين غير كاملة');
            redirect('subscriber.php?id=' . $id . '&tab=list#debts');
        }

        $oldSt = $pdo->prepare(
            'SELECT * FROM invoices WHERE id = :id AND subscriber_id = :sid AND status = "unpaid"'
        );
        $oldSt->execute(array(':id' => $invId, ':sid' => $id));
        $oldInv = $oldSt->fetch();
        if (!$oldInv) {
            flash('error', 'تعذر تعديل الدين (ربما مسدد أو محذوف)');
            redirect('subscriber.php?id=' . $id . '&tab=list#debts');
        }

        $st = $pdo->prepare(
            'UPDATE invoices
             SET month_label = :month_label, amount = :amount, due_date = :due_date, notes = :notes
             WHERE id = :id AND subscriber_id = :sid AND status = "unpaid"'
        );
        $st->execute(array(
            ':month_label' => $monthLabel,
            ':amount' => $amount,
            ':due_date' => $dueDate,
            ':notes' => ($notes !== '' ? $notes : null),
            ':id' => $invId,
            ':sid' => $id,
        ));

        $diffLines = array();
        $d1 = activity_diff_line('الشهر', $oldInv['month_label'], $monthLabel);
        $d2 = activity_diff_line('المبلغ', $oldInv['amount'], $amount);
        $d3 = activity_diff_line('الاستحقاق', $oldInv['due_date'], $dueDate);
        $oldNotes = isset($oldInv['notes']) ? $oldInv['notes'] : '';
        $d4 = activity_diff_line('الملاحظات', $oldNotes, $notes);
        if ($d1 !== '') {
            $diffLines[] = $d1;
        }
        if ($d2 !== '') {
            $diffLines[] = $d2;
        }
        if ($d3 !== '') {
            $diffLines[] = $d3;
        }
        if ($d4 !== '') {
            $diffLines[] = $d4;
        }
        if (!$diffLines) {
            $diffLines[] = 'حفظ بدون تغيير ظاهر';
        }
        activity_log(
            $pdo,
            $id,
            'invoice',
            $invId,
            'update',
            'تعديل دين #' . $invId,
            implode("\n", $diffLines)
        );

        $sendWa = post('send_whatsapp') === '1';
        $waNote = '';
        if ($sendWa) {
            $info = $pdo->prepare('SELECT name, phone FROM subscribers WHERE id = :id');
            $info->execute(array(':id' => $id));
            $subRow = $info->fetch();
            if ($subRow) {
                $debtTotal = subscriber_unpaid_total($pdo, $id);
                $msg = reminder_message(array(
                    'name' => $subRow['name'],
                    'phone' => $subRow['phone'],
                    'month_label' => $monthLabel,
                    'amount' => $amount,
                    'debt_total' => $debtTotal,
                    'notes' => $notes,
                ), $config);
                $result = whatsapp_send($config, $subRow['phone'], $msg, 'debt_updated');
                log_message($pdo, $id, $result);
                $waNote = !empty($result['success'])
                    ? ' وتم إرسال إشعار'
                    : (' لكن ' . whatsapp_fail_user_message($result));
                activity_log(
                    $pdo,
                    $id,
                    'invoice',
                    $invId,
                    'notify',
                    'إشعار تعديل دين #' . $invId,
                    !empty($result['success']) ? 'تم إرسال واتساب' : whatsapp_fail_user_message($result)
                );
            }
        }
        flash('success', 'تم تعديل الدين' . $waNote);
        redirect('subscriber.php?id=' . $id . '&tab=list#debts');
    }

    if ($action === 'update_days_left') {
        $days = (int) post('days_left', '0');
        $planId = (int) post('plan_id', '0');
        list($ok, $msg) = apply_subscriber_days_left($pdo, $id, $days, $planId);
        flash($ok ? 'success' : 'error', $msg);
        redirect('subscriber.php?id=' . $id);
    }

    if ($action === 'update_invoice_amount') {
        $invId = (int) post('invoice_id', '0');
        $amount = (float) post('amount', '0');
        if ($invId <= 0 || $amount <= 0) {
            flash('error', 'مبلغ غير صالح');
            redirect('subscriber.php?id=' . $id . '&tab=list#debts');
        }
        $oldSt = $pdo->prepare(
            'SELECT * FROM invoices WHERE id = :id AND subscriber_id = :sid AND status = "unpaid"'
        );
        $oldSt->execute(array(':id' => $invId, ':sid' => $id));
        $oldInv = $oldSt->fetch();
        if (!$oldInv) {
            flash('error', 'تعذر التعديل (الدين مسدد أو محذوف)');
            redirect('subscriber.php?id=' . $id . '&tab=list#debts');
        }
        $pdo->prepare(
            'UPDATE invoices SET amount = :amount WHERE id = :id AND subscriber_id = :sid AND status = "unpaid"'
        )->execute(array(
            ':amount' => $amount,
            ':id' => $invId,
            ':sid' => $id,
        ));
        $diff = activity_diff_line('المبلغ', $oldInv['amount'], $amount);
        activity_log(
            $pdo,
            $id,
            'invoice',
            $invId,
            'update',
            'تعديل مبلغ دين #' . $invId,
            $diff !== '' ? $diff : ('المبلغ: ' . $amount)
        );
        flash('success', 'تم تعديل المبلغ');
        redirect('subscriber.php?id=' . $id . '&tab=list#debts');
    }

    if ($action === 'delete_invoice') {
        $invId = (int) post('invoice_id', '0');
        $oldSt = $pdo->prepare(
            'SELECT * FROM invoices WHERE id = :id AND subscriber_id = :sid AND status = "unpaid"'
        );
        $oldSt->execute(array(':id' => $invId, ':sid' => $id));
        $oldInv = $oldSt->fetch();
        $pdo->prepare(
            'DELETE FROM invoices WHERE id = :id AND subscriber_id = :sid AND status = "unpaid"'
        )->execute(array(':id' => $invId, ':sid' => $id));
        if ($oldInv) {
            activity_log(
                $pdo,
                $id,
                'invoice',
                $invId,
                'delete',
                'حذف دين #' . $invId,
                'الشهر: ' . $oldInv['month_label'] . "\nالمبلغ: " . $oldInv['amount']
            );
        }
        flash('success', 'تم حذف الدين');
        redirect('subscriber.php?id=' . $id . '#debts');
    }

    if ($action === 'retry_message') {
        $logId = (int) post('log_id', '0');
        list($ok, $msg) = retry_failed_message($pdo, $config, $logId, $id);
        flash($ok ? 'success' : 'error', $msg);
        redirect('subscriber.php?id=' . $id . '#messages');
    }

    if ($action === 'delete') {
        if (function_exists('log_subscriber_delete')) {
            log_subscriber_delete($pdo, $id);
        }
        $stmt = $pdo->prepare('DELETE FROM subscribers WHERE id = :id');
        $stmt->execute(array(':id' => $id));
        $max = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM subscribers')->fetchColumn();
        $pdo->exec('ALTER TABLE subscribers AUTO_INCREMENT = ' . ($max + 1));
        flash('success', 'تم حذف المشترك');
        redirect('subscribers.php');
    }
}

$stmt = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
$stmt->execute(array(':id' => $id));
$subscriber = $stmt->fetch();

if (!$subscriber) {
    flash('error', 'مشترك غير موجود');
    redirect('subscribers.php');
}

$st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM invoices WHERE subscriber_id = :id AND status = "unpaid"');
$st->execute(array(':id' => $id));
$unpaid = (float) $st->fetchColumn();

$st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM invoices WHERE subscriber_id = :id AND status = "paid"');
$st->execute(array(':id' => $id));
$paid = (float) $st->fetchColumn();

$st = $pdo->prepare('SELECT * FROM subscriptions WHERE subscriber_id = :id ORDER BY id DESC');
$st->execute(array(':id' => $id));
$subs = $st->fetchAll();

$st = $pdo->prepare('SELECT * FROM invoices WHERE subscriber_id = :id ORDER BY due_date DESC, id DESC');
$st->execute(array(':id' => $id));
$invoices = $st->fetchAll();

$st = $pdo->prepare(
    'SELECT * FROM message_logs WHERE subscriber_id = :id ORDER BY id DESC LIMIT 50'
);
$st->execute(array(':id' => $id));
$msgLogs = $st->fetchAll();
$lastMsg = $msgLogs ? $msgLogs[0] : null;
$activityLogs = fetch_subscriber_activity($pdo, $id, 150);

$editMode = isset($_GET['edit']) && $_GET['edit'] === '1';
$debtTab = 'list';
if (isset($_GET['tab'])) {
    $tabGet = (string) $_GET['tab'];
    if ($tabGet === 'add_debt' || $tabGet === 'pay' || $tabGet === 'list') {
        $debtTab = $tabGet;
    }
}
if (isset($_GET['edit_inv']) && (int) $_GET['edit_inv'] > 0) {
    $debtTab = 'list';
}

$titleAfter = '';
if (!$editMode) {
    $titleAfter = '<a class="edit-icon" href="subscriber.php?id=' . (int) $id . '&edit=1" title="' . e(t('edit')) . '">✎</a>';
}
$editInvId = isset($_GET['edit_inv']) ? (int) $_GET['edit_inv'] : 0;
$editInvoice = null;
if ($editInvId > 0) {
    foreach ($invoices as $invRow) {
        if ((int) $invRow['id'] === $editInvId && $invRow['status'] === 'unpaid') {
            $editInvoice = $invRow;
            break;
        }
    }
}
render_header($subscriber['name'], 'subscribers', format_phone_display($subscriber['phone']), $titleAfter);

if ($editMode):
?>
<div class="panel glass-panel">
    <h2><?php echo e(t('edit')); ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <div class="form-grid">
            <div>
                <label><?php echo e(t('name')); ?></label>
                <input name="name" value="<?php echo e($subscriber['name']); ?>" required>
            </div>
            <div>
                <label><?php echo e(t('phone')); ?></label>
                <div class="phone-pick-row">
                    <input id="subPhone" name="phone" type="tel" inputmode="tel" autocomplete="tel" value="<?php echo e(format_phone_display($subscriber['phone'])); ?>" required>
                    <button type="button" class="btn secondary" id="pickContactBtn"><?php echo e(t('pick_contact')); ?></button>
                </div>
            </div>
            <div>
                <label><?php echo e(t('address')); ?></label>
                <input name="address" value="<?php echo e($subscriber['address']); ?>">
            </div>
            <div>
                <label><?php echo e(t('notes')); ?></label>
                <input name="notes" value="<?php echo e($subscriber['notes']); ?>">
            </div>
        </div>
        <div class="actions">
            <button class="btn" name="action" value="update" type="submit"><?php echo e(t('save')); ?></button>
            <button class="btn danger" name="action" value="delete" type="submit" onclick="return confirm('<?php echo e(t('confirm_delete')); ?>');"><?php echo e(t('delete')); ?></button>
            <a class="btn ghost" href="subscriber.php?id=<?php echo (int) $id; ?>"><?php echo e($lang === 'en' ? 'Cancel' : 'إلغاء'); ?></a>
        </div>
    </form>
</div>
<script>
(function () {
  var pickBtn = document.getElementById('pickContactBtn');
  var phoneInput = document.getElementById('subPhone');
  var nameInput = document.querySelector('input[name="name"]');
  var contactsMsg = <?php echo json_encode(t('contacts_unsupported')); ?>;
  if (pickBtn && phoneInput) {
    pickBtn.addEventListener('click', function () {
      if (!(navigator.contacts && typeof navigator.contacts.select === 'function')) {
        alert(contactsMsg);
        return;
      }
      navigator.contacts.select(['name', 'tel'], { multiple: false }).then(function (contacts) {
        if (!contacts || !contacts.length) return;
        var c = contacts[0];
        var tel = '';
        if (c.tel && c.tel.length) {
          tel = String(c.tel[0] || '').replace(/[^\d+]/g, '');
        }
        if (tel) phoneInput.value = tel;
        if (nameInput && !nameInput.value && c.name && c.name.length) {
          nameInput.value = String(c.name[0] || '');
        }
      }).catch(function () {});
    });
  }
})();
</script>
<?php
render_footer();
return;
endif;
?>

<div class="cards">
    <div class="card-stat glass g-red">
        <div class="label">المتبقي / الدين</div>
        <div class="value"><?php echo e(money_format_iqd($unpaid, $config['currency'])); ?></div>
    </div>
    <div class="card-stat glass g-green">
        <div class="label">المستلم</div>
        <div class="value"><?php echo e(money_format_iqd($paid, $config['currency'])); ?></div>
    </div>
    <?php
    $activeSubCard = null;
    $latestSubCard = !empty($subs) ? $subs[0] : null;
    foreach ($subs as $subRow) {
        if ($subRow['status'] === 'active' && $subRow['end_date'] >= date('Y-m-d')) {
            $activeSubCard = $subRow;
            break;
        }
    }
    $daysSrc = $activeSubCard ? $activeSubCard : $latestSubCard;
    $daysCardInfo = ($daysSrc && !empty($daysSrc['start_date']) && !empty($daysSrc['end_date']))
        ? subscription_days_info($daysSrc['start_date'], $daysSrc['end_date'])
        : null;
    $daysCardVal = $daysCardInfo ? (int) $daysCardInfo['left'] : null;
    ?>
    <button type="button" class="card-stat glass g-blue days-edit-card" id="openDaysModalBtn"
            data-days="<?php echo $daysCardVal !== null ? (int) $daysCardVal : '0'; ?>"
            data-has-sub="<?php echo $daysSrc ? '1' : '0'; ?>"
            title="<?php echo e($lang === 'en' ? 'Edit days left' : 'تعديل الأيام المتبقية'); ?>">
        <div class="label"><?php echo e(t('days_left')); ?></div>
        <div class="value<?php echo ($daysCardVal !== null && $daysCardVal < 0) ? ' days-neg' : ''; ?>"><?php echo $daysCardVal !== null ? (int) $daysCardVal : '—'; ?></div>
        <div class="hint"><?php echo e($lang === 'en' ? 'Tap to edit' : 'اضغط للتعديل'); ?></div>
    </button>
    <div class="card-stat glass <?php
        if (!$lastMsg) {
            echo 'g-cyan';
        } elseif (!empty($lastMsg['success'])) {
            echo 'g-green';
        } else {
            echo 'g-orange';
        }
    ?>">
        <div class="label"><?php echo e(t('msg_status')); ?></div>
        <div class="value" style="font-size:16px;line-height:1.35">
            <?php
            if (!$lastMsg) {
                echo e($lang === 'en' ? 'Not sent' : 'ما انرسلت');
            } elseif (!empty($lastMsg['success'])) {
                echo e($lang === 'en' ? 'Sent' : 'أُرسلت');
            } else {
                echo e($lang === 'en' ? 'Failed' : 'فشلت');
            }
            ?>
        </div>
        <?php if ($lastMsg): ?>
            <div class="hint"><?php echo e(message_short_summary($lastMsg['message_type'], $lastMsg['body'], !empty($lastMsg['success']))); ?></div>
        <?php endif; ?>
    </div>
</div>

<?php
$settingsRental = settings_load();
$rentalDevices = rental_devices_list($settingsRental);
$rentalFee = rental_fee_amount($settingsRental);
$hasRent = subscriber_has_rental($subscriber);
$currentRentDev = $hasRent ? rental_device_by_id($subscriber['rental_device_id'], $settingsRental) : null;
$subMonthlyPrice = subscriber_monthly_price($pdo, $id);
$isActiveSub = !empty($activeSubCard);
?>
<div class="page-head name-with-edit">
    <h1>
        <?php echo e($subscriber['name']); ?>
        <a class="edit-icon" href="subscriber.php?id=<?php echo (int) $id; ?>&edit=1" title="<?php echo e(t('edit')); ?>">✎</a>
        <span class="sub-status <?php echo $isActiveSub ? 'is-active' : 'is-off'; ?>">
            <span class="status-dot" aria-hidden="true"></span>
            <?php echo e($isActiveSub ? t('status_active') : t('status_inactive')); ?>
        </span>
    </h1>
    <p><?php echo e(format_phone_display($subscriber['phone'])); ?></p>
</div>

<div class="actions sub-toolbar">
    <a class="btn ghost" href="subscribers.php"><?php echo e($lang === 'en' ? 'Back' : 'رجوع'); ?></a>
    <a class="btn" href="subscriber.php?id=<?php echo (int) $id; ?>&edit=1"><?php echo e(t('edit')); ?></a>
    <a class="btn secondary" href="activate.php?subscriber_id=<?php echo (int) $id; ?>"><?php echo e(t('activate')); ?></a>
    <form method="post" style="display:inline" onsubmit="return confirm(<?php echo json_encode(t('confirm_give_test')); ?>);">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="give_test">
        <button class="btn ghost" type="submit"><?php echo e(t('give_test')); ?></button>
    </form>
    <a class="btn money" href="subscriber.php?id=<?php echo (int) $id; ?>&tab=add_debt#debts"><?php echo e($lang === 'en' ? 'Add debt' : 'إضافة دين'); ?></a>
    <?php if ($unpaid > 0): ?>
    <a class="btn" style="background:#16a34a;border-color:#16a34a;color:#fff" href="subscriber.php?id=<?php echo (int) $id; ?>&tab=pay#debts"><?php echo e(t('pay_debts')); ?></a>
    <?php endif; ?>
</div>

<?php
$plansForChange = $pdo->query(
    'SELECT id, name, monthly_price FROM service_plans WHERE is_active = 1 ORDER BY sort_order ASC, monthly_price ASC, id ASC'
)->fetchAll();
$currentPlanMatchId = 0;
if ($activeSubCard) {
    foreach ($plansForChange as $pf) {
        if (strcasecmp((string) $pf['name'], (string) $activeSubCard['service_name']) === 0) {
            $currentPlanMatchId = (int) $pf['id'];
            break;
        }
    }
} elseif (!empty($subscriber['preferred_plan_id'])) {
    $currentPlanMatchId = (int) $subscriber['preferred_plan_id'];
}
?>
<div class="panel glass-panel" id="changePlan">
    <h2><?php echo e(t('change_sub_type')); ?></h2>
    <?php if ($activeSubCard): ?>
        <p class="meta" style="margin:0 0 10px">
            <?php echo e($lang === 'en' ? 'Current' : 'الحالي'); ?>:
            <strong><?php echo e($activeSubCard['service_name']); ?></strong>
            — <?php echo e(money_format_iqd($activeSubCard['monthly_price'], $config['currency'])); ?>
            <?php if ($hasRent): ?>
                + <?php echo e($lang === 'en' ? 'rent' : 'إيجار'); ?> <?php echo e(money_format_iqd($rentalFee, $config['currency'])); ?>
            <?php endif; ?>
        </p>
        <form method="post" class="change-plan-form" onsubmit="return confirm(<?php echo json_encode($lang === 'en' ? 'Change package and update the account amount?' : 'تغيير نوع الاشتراك وتعديل المبلغ على الحساب؟'); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="change_plan">
            <div class="form-grid" style="max-width:520px">
                <div>
                    <label><?php echo e(t('sub_type')); ?></label>
                    <select name="plan_id" required>
                        <?php foreach ($plansForChange as $pf): ?>
                            <option value="<?php echo (int) $pf['id']; ?>" <?php echo $currentPlanMatchId === (int) $pf['id'] ? 'selected' : ''; ?>>
                                <?php echo e($pf['name'] . ' — ' . money_format_iqd($pf['monthly_price'], $config['currency'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="actions" style="margin-top:12px">
                <button class="btn" type="submit"><?php echo e(t('change_sub_type')); ?></button>
            </div>
        </form>
    <?php else: ?>
        <p class="meta" style="margin:0">
            <?php echo e($lang === 'en'
                ? 'No active subscription. Set package when adding, or activate first.'
                : 'ماكو اشتراك نشط. حدّد الباقة عند الإضافة أو فعّل المشترك أولاً.'); ?>
        </p>
        <?php if (!empty($subscriber['preferred_plan_id'])): ?>
            <?php
            $prefName = '';
            foreach ($plansForChange as $pf) {
                if ((int) $pf['id'] === (int) $subscriber['preferred_plan_id']) {
                    $prefName = $pf['name'];
                    break;
                }
            }
            ?>
            <p class="meta"><?php echo e(t('sub_type')); ?>: <strong><?php echo e($prefName !== '' ? $prefName : ('#' . (int) $subscriber['preferred_plan_id'])); ?></strong></p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="panel glass-panel" id="rental">
    <h2><?php echo e($lang === 'en' ? 'Rental device' : 'جهاز إيجار'); ?></h2>
    <form method="post" id="rentalForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="update_rental">
        <div class="actions toggle-row" style="margin-top:0">
            <label class="toggle">
                <input type="checkbox" name="rental_enabled" value="1" id="rentalEnabledChk" <?php echo $hasRent ? 'checked' : ''; ?>>
                <span class="toggle-ui"></span>
                <span class="toggle-text"><?php echo e($lang === 'en' ? 'Rental device' : 'جهاز إيجار'); ?></span>
            </label>
            <span class="meta" style="font-weight:700;color:var(--muted)">
                <?php echo e($lang === 'en' ? 'Fee' : 'الإيجار'); ?>:
                <?php echo e(money_format_iqd($rentalFee, $config['currency'])); ?> / <?php echo e($lang === 'en' ? 'month' : 'شهر'); ?>
            </span>
        </div>

        <div id="rentalDetails"<?php echo $hasRent ? '' : ' hidden'; ?> style="<?php echo $hasRent ? '' : 'display:none'; ?>">
            <div id="rentalDevicePick" style="margin-top:12px">
                <label><?php echo e($lang === 'en' ? 'Device type' : 'نوع الجهاز'); ?></label>
                <select name="rental_device_id" id="rentalDeviceSelect"<?php echo $hasRent ? '' : ' disabled'; ?>>
                    <option value=""><?php echo e($lang === 'en' ? 'Choose…' : 'اختر…'); ?></option>
                    <?php foreach ($rentalDevices as $d): ?>
                        <option value="<?php echo e($d['id']); ?>" <?php echo ($hasRent && $subscriber['rental_device_id'] === $d['id']) ? 'selected' : ''; ?>>
                            <?php echo e($d['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($currentRentDev): ?>
                    <div style="margin-top:10px">
                        <?php echo rental_badge_html($subscriber, $settingsRental); ?>
                        <strong style="margin-right:8px"><?php echo e($currentRentDev['name']); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
            <div class="actions" style="margin-top:12px">
                <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
            </div>
        </div>

        <div id="rentalSaveOff" class="actions"<?php echo $hasRent ? ' hidden' : ''; ?> style="margin-top:12px;<?php echo $hasRent ? 'display:none' : ''; ?>">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>

    <div id="rentalMsgActions" class="actions"<?php echo $hasRent ? '' : ' hidden'; ?> style="margin-top:10px;<?php echo $hasRent ? '' : 'display:none'; ?>">
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="msg_rental_only">
            <button class="btn secondary" type="submit"><?php echo e($lang === 'en' ? 'Send rent message' : 'رسالة الإيجار فقط'); ?></button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('<?php echo e($lang === 'en' ? 'Send device return request?' : 'إرسال طلب استرجاع الجهاز؟'); ?>');">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="msg_rental_return">
            <button class="btn danger" type="submit"><?php echo e($lang === 'en' ? 'Request device return' : 'طلب استرجاع الجهاز'); ?></button>
        </form>
    </div>
</div>

<?php if ($lastMsg && empty($lastMsg['success'])): ?>
<div class="panel" style="border-color:#f5c6cb;background:#fff8f8">
    <div class="actions" style="margin-top:0;align-items:center">
        <div style="flex:1">
            <strong style="color:#c0392b"><?php echo e($lang === 'en' ? 'Last message failed' : 'آخر رسالة فشلت'); ?></strong>
            <div class="msg-short" style="margin-top:4px"><?php echo e(message_short_summary($lastMsg['message_type'], $lastMsg['body'], false)); ?></div>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="retry_message">
            <input type="hidden" name="log_id" value="<?php echo (int) $lastMsg['id']; ?>">
            <button class="btn secondary" type="submit"><?php echo e(t('retry_send')); ?></button>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="panel glass-panel sub-section">
    <h2>كل التفعيلات</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>الخدمة</th>
                <th>السعر</th>
                <th>من</th>
                <th>إلى</th>
                <th>الحالة</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$subs): ?>
                <tr><td colspan="6">لا توجد تفعيلات</td></tr>
            <?php endif; ?>
            <?php $n = 1; foreach ($subs as $row): ?>
                <tr>
                    <td><?php echo $n++; ?></td>
                    <td><?php echo e($row['service_name']); ?>
                        <?php if ((float) $row['monthly_price'] <= 0): ?>
                            <span class="badge" style="background:#f59e0b;color:#111"><?php echo e($lang === 'en' ? 'TEST' : 'تست'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e(money_format_iqd($row['monthly_price'], $config['currency'])); ?></td>
                    <td><?php echo e($row['start_date']); ?></td>
                    <td><?php echo e($row['end_date']); ?></td>
                    <td>
                        <span class="badge <?php echo e($row['status']); ?>">
                            <?php echo $row['status'] === 'active' ? 'نشط' : ($row['status'] === 'expired' ? 'منتهي' : 'ملغي'); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel glass-panel" id="debts">
    <h2><?php echo e($lang === 'en' ? 'Debts' : 'الديون'); ?></h2>
    <div class="soft-tabs">
        <a class="soft-tab<?php echo $debtTab === 'list' ? ' on' : ''; ?>" href="subscriber.php?id=<?php echo (int) $id; ?>&tab=list#debts"><?php echo e(t('debts_list_tab')); ?></a>
        <a class="soft-tab<?php echo $debtTab === 'pay' ? ' on' : ''; ?>" href="subscriber.php?id=<?php echo (int) $id; ?>&tab=pay#debts"><?php echo e(t('pay_debts')); ?></a>
        <a class="soft-tab<?php echo $debtTab === 'add_debt' ? ' on' : ''; ?>" href="subscriber.php?id=<?php echo (int) $id; ?>&tab=add_debt#debts"><?php echo e(t('add_debts_tab')); ?></a>
    </div>

    <div class="tab-pane<?php echo $debtTab === 'pay' ? '' : ' hidden'; ?>">
        <?php if ($unpaid <= 0): ?>
            <p class="meta" style="margin:0"><?php echo e($lang === 'en' ? 'No unpaid debts.' : 'ماكو ديون غير مسددة.'); ?></p>
        <?php else: ?>
        <?php
        $unpaidInvoices = array();
        foreach ($invoices as $invPay) {
            if (isset($invPay['status']) && $invPay['status'] === 'unpaid') {
                $unpaidInvoices[] = $invPay;
            }
        }
        // الأقدم أولاً في قائمة الاختيار
        usort($unpaidInvoices, function ($a, $b) {
            $da = isset($a['due_date']) ? (string) $a['due_date'] : '';
            $db = isset($b['due_date']) ? (string) $b['due_date'] : '';
            if ($da === $db) {
                return (int) $a['id'] - (int) $b['id'];
            }
            return strcmp($da, $db);
        });
        ?>
        <form method="post" id="payDebtsForm" class="pay-debts-form">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="pay_debts">
            <div class="pay-total-line">
                <span><?php echo e($lang === 'en' ? 'Total due' : 'إجمالي المتبقي'); ?></span>
                <strong><?php echo e(money_format_iqd($unpaid, $config['currency'])); ?></strong>
            </div>
            <div class="pay-mode-row">
                <label class="pay-mode-opt">
                    <input type="radio" name="pay_mode" value="full" id="payModeFull" checked>
                    <span><?php echo e(t('pay_full')); ?></span>
                </label>
                <label class="pay-mode-opt">
                    <input type="radio" name="pay_mode" value="partial" id="payModePartial">
                    <span><?php echo e(t('pay_partial_mode')); ?></span>
                </label>
            </div>
            <div id="payPartialBox" class="hidden pay-partial-box">
                <label for="payInvoiceSelect"><?php echo e(t('pay_choose_debt')); ?></label>
                <select name="invoice_id" id="payInvoiceSelect">
                    <option value=""><?php echo e($lang === 'en' ? '— Select debt —' : '— اختر الدين —'); ?></option>
                    <?php foreach ($unpaidInvoices as $uinv): ?>
                        <option value="<?php echo (int) $uinv['id']; ?>"
                            data-amount="<?php echo (float) $uinv['amount']; ?>">
                            <?php
                            echo e(invoice_debt_label($uinv) . ' — ' . money_format_iqd($uinv['amount'], $config['currency']));
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="payAmountInput" style="margin-top:10px"><?php echo e(t('partial_pay')); ?></label>
                <input type="number" name="pay_amount" id="payAmountInput" min="1" step="1" max="<?php echo (int) $unpaid; ?>" value="">
                <p class="meta" id="payDebtHint" style="margin:6px 0 0"></p>
            </div>
            <div class="actions toggle-row" style="margin-top:14px">
                <label class="toggle">
                    <input type="checkbox" name="send_whatsapp" value="1" checked>
                    <span class="toggle-ui"></span>
                    <span class="toggle-text"><?php echo e($lang === 'en' ? 'Send notice to subscriber' : 'إرسال إشعار للمشترك'); ?></span>
                </label>
            </div>
            <div class="actions" style="margin-top:12px">
                <button class="btn" type="submit" style="background:#16a34a;border-color:#16a34a"><?php echo e(t('pay_debts')); ?></button>
            </div>
        </form>
        <script>
        (function () {
          var full = document.getElementById('payModeFull');
          var partial = document.getElementById('payModePartial');
          var box = document.getElementById('payPartialBox');
          var amt = document.getElementById('payAmountInput');
          var sel = document.getElementById('payInvoiceSelect');
          var hint = document.getElementById('payDebtHint');
          var currency = <?php echo json_encode(isset($config['currency']) ? $config['currency'] : 'د.ع'); ?>;
          function money(n) {
            try { return Math.round(n).toLocaleString('en-US') + ' ' + currency; }
            catch (e) { return Math.round(n) + ' ' + currency; }
          }
          function syncDebt() {
            if (!sel || !amt) return;
            var opt = sel.options[sel.selectedIndex];
            var due = opt ? (parseFloat(opt.getAttribute('data-amount') || '0') || 0) : 0;
            if (due > 0) {
              amt.max = String(Math.round(due));
              if (!amt.value || parseFloat(amt.value) > due || parseFloat(amt.value) <= 0) {
                amt.value = String(Math.round(due));
              }
              if (hint) hint.textContent = <?php echo json_encode($lang === 'en' ? 'Max for this debt: ' : 'أعلى مبلغ لهذا الدين: '); ?> + money(due);
            } else {
              amt.max = '';
              if (hint) hint.textContent = <?php echo json_encode($lang === 'en' ? 'Choose which debt to pay' : 'اختر أي دين تريد تسديده من القائمة'); ?>;
            }
          }
          function sync() {
            var isPartial = partial && partial.checked;
            if (box) box.classList.toggle('hidden', !isPartial);
            if (amt) amt.required = !!isPartial;
            if (sel) sel.required = !!isPartial;
            if (isPartial) syncDebt();
          }
          if (full) full.addEventListener('change', sync);
          if (partial) partial.addEventListener('change', sync);
          if (sel) sel.addEventListener('change', syncDebt);
          sync();
        })();
        </script>
        <?php endif; ?>
    </div>

    <div class="tab-pane<?php echo $debtTab === 'add_debt' ? '' : ' hidden'; ?>">
        <form method="post" id="addDebtsForm">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="add_debts">
            <div class="debt-entry-box">
                <div class="actions" style="margin-top:0;margin-bottom:8px">
                    <strong><?php echo e(t('opening_debts')); ?></strong>
                    <button type="button" class="plus-btn" id="addDebtRowBtn" title="<?php echo e(t('add_debt_line')); ?>">+</button>
                </div>
                <div id="debtRows">
                    <div class="debt-entry-row">
                        <div class="form-grid">
                            <div>
                                <label><?php echo e($lang === 'en' ? 'Type' : 'النوع'); ?></label>
                                <select name="debt_kind[]">
                                    <?php if ($hasRent): ?>
                                    <option value="month_rent" selected><?php echo e(t('debt_type_month_rent')); ?></option>
                                    <option value="month"><?php echo e(t('debt_type_month')); ?></option>
                                    <?php else: ?>
                                    <option value="month" selected><?php echo e(t('debt_type_month')); ?></option>
                                    <?php endif; ?>
                                    <option value="item"><?php echo e(t('debt_type_item')); ?></option>
                                </select>
                            </div>
                            <div>
                                <label><?php echo e($lang === 'en' ? 'Month' : 'الشهر'); ?></label>
                                <?php echo month_ym_picker_html('debt_month[]', date('Y-m')); ?>
                            </div>
                            <div>
                                <label><?php echo e($lang === 'en' ? 'Amount' : 'المبلغ'); ?></label>
                                <input type="number" name="debt_amount[]" min="0" step="1" autocomplete="off"
                                    value="<?php echo ($hasRent && ($subMonthlyPrice + $rentalFee) > 0) ? (int) round($subMonthlyPrice + $rentalFee) : ''; ?>">
                            </div>
                            <div>
                                <label><?php echo e(t('debt_notes')); ?></label>
                                <input name="debt_notes[]" autocomplete="off"
                                    value="<?php
                                    if ($hasRent && ($subMonthlyPrice + $rentalFee) > 0) {
                                        $dn = 'اشتراك ' . (int) $subMonthlyPrice . ' + إيجار ' . (int) $rentalFee;
                                        if ($currentRentDev && !empty($currentRentDev['name'])) {
                                            $dn .= ' (' . $currentRentDev['name'] . ')';
                                        }
                                        echo e($dn);
                                    }
                                    ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="actions toggle-row">
                <label class="toggle">
                    <input type="checkbox" name="send_whatsapp" value="1" id="addDebtSendWa" checked>
                    <span class="toggle-ui"></span>
                    <span class="toggle-text"><?php echo e(t('send_whatsapp')); ?></span>
                </label>
                <label class="check-inline">
                    <input type="checkbox" name="use_custom_msg" value="1" id="addDebtCustomChk">
                    <span><?php echo e($lang === 'en' ? 'Custom text' : 'نص مخصص'); ?></span>
                </label>
            </div>
            <div id="addDebtCustomBox" class="hidden" style="margin-bottom:12px">
                <label><?php echo e($lang === 'en' ? 'Message text' : 'نص الرسالة'); ?></label>
                <textarea name="custom_msg" rows="3" placeholder="<?php echo e($lang === 'en' ? 'Write the message…' : 'اكتب النص الذي تريده…'); ?>"></textarea>
            </div>
            <div class="actions">
                <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Save debts' : 'حفظ الديون'); ?></button>
            </div>
        </form>
    </div>

    <div class="tab-pane<?php echo $debtTab === 'list' ? '' : ' hidden'; ?>">
        <?php if ($editInvoice): ?>
        <div class="panel" style="margin-bottom:12px;background:rgba(59,130,246,0.08)">
            <h2 style="font-size:16px"><?php echo e($lang === 'en' ? 'Edit debt' : 'تعديل الدين'); ?> #<?php echo (int) $editInvoice['id']; ?></h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="update_invoice">
                <input type="hidden" name="invoice_id" value="<?php echo (int) $editInvoice['id']; ?>">
                <div class="form-grid">
                    <div>
                        <label><?php echo e($lang === 'en' ? 'Month' : 'الشهر'); ?></label>
                        <input name="month_label" value="<?php echo e($editInvoice['month_label']); ?>" required>
                    </div>
                    <div>
                        <label><?php echo e($lang === 'en' ? 'Amount' : 'المبلغ'); ?></label>
                        <input type="number" name="amount" min="1" step="1" value="<?php echo e((float) $editInvoice['amount']); ?>" required>
                    </div>
                    <div>
                        <label><?php echo e($lang === 'en' ? 'Due' : 'الاستحقاق'); ?></label>
                        <input type="date" name="due_date" value="<?php echo e($editInvoice['due_date']); ?>" required>
                    </div>
                    <div>
                        <label><?php echo e(t('notes')); ?></label>
                        <input name="notes" value="<?php echo e(isset($editInvoice['notes']) ? $editInvoice['notes'] : ''); ?>">
                    </div>
                </div>
                <div class="actions toggle-row">
                    <label class="toggle">
                        <input type="checkbox" name="send_whatsapp" value="1">
                        <span class="toggle-ui"></span>
                        <span class="toggle-text"><?php echo e($lang === 'en' ? 'Send notice to subscriber' : 'إرسال إشعار إلى المشترك'); ?></span>
                    </label>
                </div>
                <div class="actions">
                    <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
                    <a class="btn ghost" href="subscriber.php?id=<?php echo (int) $id; ?>&tab=list#debts"><?php echo e($lang === 'en' ? 'Cancel' : 'إلغاء'); ?></a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="table-compact debts-mini-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th><?php echo e($lang === 'en' ? 'Month' : 'الشهر'); ?></th>
                    <th><?php echo e($lang === 'en' ? 'Amount' : 'المبلغ'); ?></th>
                    <th><?php echo e($lang === 'en' ? 'Status' : 'الحالة'); ?></th>
                    <th><?php echo e(t('notes')); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$invoices): ?>
                    <tr><td colspan="6"><?php echo e($lang === 'en' ? 'No invoices' : 'لا توجد فواتير'); ?></td></tr>
                <?php endif; ?>
                <?php $n = 1; foreach ($invoices as $row): ?>
                    <tr class="<?php echo $row['status'] === 'unpaid' ? 'row-unpaid' : 'row-paid'; ?>">
                        <td><?php echo $n++; ?></td>
                        <td>
                            <strong><?php echo e(month_short_label($row['month_label'])); ?></strong>
                            <div class="meta"><?php echo e($row['due_date']); ?></div>
                        </td>
                        <td>
                            <?php if ($row['status'] === 'unpaid'): ?>
                                <button
                                    type="button"
                                    class="amount-edit-btn"
                                    data-id="<?php echo (int) $row['id']; ?>"
                                    data-amount="<?php echo e((float) $row['amount']); ?>"
                                    data-month="<?php echo e(month_short_label($row['month_label'])); ?>"
                                    title="<?php echo e($lang === 'en' ? 'Click to edit' : 'اضغط للتعديل'); ?>"
                                ><?php echo e(money_format_iqd($row['amount'], $config['currency'])); ?></button>
                            <?php else: ?>
                                <?php echo e(money_format_iqd($row['amount'], $config['currency'])); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo e($row['status']); ?>">
                                <?php echo $row['status'] === 'paid' ? ($lang === 'en' ? 'Paid' : 'مسدد') : ($lang === 'en' ? 'Unpaid' : 'غير مسدد'); ?>
                            </span>
                        </td>
                        <td class="notes-cell"><?php echo e(isset($row['notes']) ? $row['notes'] : ''); ?></td>
                        <td>
                            <?php if ($row['status'] === 'unpaid'): ?>
                                <form method="post" onsubmit="return confirm('حذف هذا الدين؟');" style="display:inline">
                                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="delete_invoice">
                                    <input type="hidden" name="invoice_id" value="<?php echo (int) $row['id']; ?>">
                                    <button class="btn ghost sm" type="submit"><?php echo e(t('delete')); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="panel glass-panel" id="messages">
    <h2><?php echo e(t('msg_history')); ?></h2>
    <?php if (!$msgLogs): ?>
        <p style="color:var(--muted);margin:0"><?php echo e($lang === 'en' ? 'No messages yet for this subscriber.' : 'ما انرسلت أي رسالة لهذا المشترك لحد الآن.'); ?></p>
    <?php else: ?>
        <?php $msgResolvedMap = message_logs_resolved_map($pdo, $msgLogs); ?>
        <div class="msg-log-list">
            <?php foreach ($msgLogs as $log): ?>
                <?php
                $ok = !empty($log['success']);
                $resolved = !$ok && !empty($msgResolvedMap[(int) $log['id']]);
                $short = message_short_summary($log['message_type'], $log['body'], $ok);
                $resolvedTitle = $lang === 'en' ? 'Resolved by a later successful send' : 'انحلت لاحقاً بإرسال ناجح';
                $itemCls = $ok ? '' : ($resolved ? ' msg-resolved' : ' msg-failed');
                ?>
                <div class="msg-log-item<?php echo $itemCls; ?>">
                    <div class="msg-log-head">
                        <?php if ($ok): ?>
                            <span class="badge paid"><?php echo e($lang === 'en' ? 'Sent' : 'أُرسلت'); ?></span>
                        <?php elseif ($resolved): ?>
                            <span class="badge paid msg-resolved-badge" title="<?php echo e($resolvedTitle); ?>">
                                <?php echo e($lang === 'en' ? 'Fail → Fixed' : 'فشل → انحلت'); ?>
                            </span>
                            <span class="msg-resolved-arrow" title="<?php echo e($resolvedTitle); ?>">→</span>
                        <?php else: ?>
                            <span class="badge expired"><?php echo e($lang === 'en' ? 'Failed' : 'فشلت'); ?></span>
                        <?php endif; ?>
                        <strong><?php echo e(message_type_title($log['message_type'])); ?></strong>
                        <span class="meta"><?php echo e($log['created_at']); ?></span>
                    </div>
                    <div class="msg-short"><?php echo e($short); ?></div>
                    <details class="msg-full-details">
                        <summary><?php echo e($lang === 'en' ? 'Full message content' : 'المحتوى الكامل للرسالة'); ?></summary>
                        <pre class="msg-full-body"><?php echo e($log['body']); ?></pre>
                    </details>
                    <?php if (!$ok && !$resolved): ?>
                        <form method="post" class="msg-retry-form">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="retry_message">
                            <input type="hidden" name="log_id" value="<?php echo (int) $log['id']; ?>">
                            <button class="btn secondary sm" type="submit"><?php echo e(t('retry_send')); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="panel glass-panel" id="activity">
    <h2><?php echo e($lang === 'en' ? 'Change log' : 'سجل التغييرات'); ?></h2>
    <div class="actions" style="margin-top:0;margin-bottom:10px">
        <input id="activitySearch" type="search" placeholder="<?php echo e($lang === 'en' ? 'Search log…' : 'بحث في السجل…'); ?>" style="max-width:320px">
    </div>
    <?php if (!$activityLogs): ?>
        <p style="color:var(--muted);margin:0"><?php echo e($lang === 'en' ? 'No changes recorded yet.' : 'ماكو تغييرات مسجّلة بعد.'); ?></p>
    <?php else: ?>
        <div class="activity-list" id="activityList">
            <?php foreach ($activityLogs as $act): ?>
                <?php
                $searchBlob = strtolower(
                    $act['summary'] . ' ' . (isset($act['details']) ? $act['details'] : '') . ' ' . $act['action'] . ' ' . $act['created_at']
                );
                ?>
                <div class="activity-item" data-search="<?php echo e($searchBlob); ?>">
                    <div class="activity-head">
                        <span class="badge unpaid"><?php echo e($act['action']); ?></span>
                        <strong><?php echo e($act['summary']); ?></strong>
                        <span class="meta"><?php echo e($act['created_at']); ?></span>
                    </div>
                    <?php if (!empty($act['details'])): ?>
                        <pre class="activity-details"><?php echo e($act['details']); ?></pre>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>
window.DEBT_ENTRY = {
  month: <?php echo json_encode(date('Y-m')); ?>,
  years: <?php echo json_encode(array((int) date('Y') - 1, (int) date('Y'), (int) date('Y') + 1)); ?>,
  hasRent: <?php echo $hasRent ? 'true' : 'false'; ?>,
  subPrice: <?php echo json_encode((float) $subMonthlyPrice); ?>,
  rentFee: <?php echo json_encode((float) $rentalFee); ?>,
  rentDevice: <?php echo json_encode($currentRentDev && !empty($currentRentDev['name']) ? $currentRentDev['name'] : ''); ?>,
  labels: {
    type: <?php echo json_encode($lang === 'en' ? 'Type' : 'النوع'); ?>,
    month: <?php echo json_encode($lang === 'en' ? 'Month' : 'الشهر'); ?>,
    amount: <?php echo json_encode($lang === 'en' ? 'Amount' : 'المبلغ'); ?>,
    notes: <?php echo json_encode(t('debt_notes')); ?>,
    monthOpt: <?php echo json_encode(t('debt_type_month')); ?>,
    itemOpt: <?php echo json_encode(t('debt_type_item')); ?>,
    monthRentOpt: <?php echo json_encode(t('debt_type_month_rent')); ?>,
    remove: <?php echo json_encode($lang === 'en' ? 'Remove line' : 'حذف السطر'); ?>
  }
};
</script>
<script src="assets/debt-entry.js?v=5"></script>

<div class="modal-backdrop hidden" id="amountModal">
    <div class="modal-card">
        <h3 id="amountModalTitle"><?php echo e($lang === 'en' ? 'Edit amount' : 'تعديل المبلغ'); ?></h3>
        <form method="post" id="amountEditForm">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="update_invoice_amount">
            <input type="hidden" name="invoice_id" id="amountInvoiceId" value="">
            <label><?php echo e($lang === 'en' ? 'Amount' : 'المبلغ'); ?></label>
            <input type="number" name="amount" id="amountInput" min="1" step="1" required autofocus>
            <div class="actions" style="margin-top:14px;justify-content:flex-end">
                <button type="button" class="btn ghost" id="amountCancelBtn"><?php echo e($lang === 'en' ? 'Cancel' : 'إلغاء'); ?></button>
                <button type="submit" class="btn"><?php echo e($lang === 'en' ? 'OK' : 'موافق'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop hidden" id="daysModal">
    <div class="modal-card">
        <h3><?php echo e($lang === 'en' ? 'Days left' : 'الأيام المتبقية'); ?></h3>
        <p class="meta" style="margin:0 0 10px"><?php echo e($lang === 'en' ? 'From paper ledger — no new invoice.' : 'من الدفتر الورقي — بدون فاتورة جديدة.'); ?></p>
        <form method="post" id="daysEditForm">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="update_days_left">
            <?php
            $plansForDaysSub = $pdo->query(
                'SELECT id, name FROM service_plans WHERE is_active = 1 ORDER BY sort_order ASC, monthly_price ASC, id ASC'
            )->fetchAll();
            ?>
            <label><?php echo e(t('days_left')); ?></label>
            <input type="number" name="days_left" id="daysInput" min="0" max="3650" step="1" required>
            <div id="daysPlanWrap" class="<?php echo $activeSubCard ? 'hidden' : ''; ?>" style="margin-top:10px<?php echo $activeSubCard ? ';display:none' : ''; ?>">
                <label><?php echo e(t('package')); ?></label>
                <select name="plan_id" id="daysPlanSelect"<?php echo $activeSubCard ? '' : ' required'; ?>>
                    <option value=""><?php echo e($lang === 'en' ? 'Choose package…' : 'اختر الباقة…'); ?></option>
                    <?php foreach ($plansForDaysSub as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>"><?php echo e($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="meta" style="margin-top:6px"><?php echo e($lang === 'en' ? 'Registers remaining days only (ledger import).' : 'يسجّل الأيام الباقية فقط (نقل دفتر).'); ?></div>
            </div>
            <div class="actions" style="margin-top:14px;justify-content:flex-end">
                <button type="button" class="btn ghost" id="daysCancelBtn"><?php echo e($lang === 'en' ? 'Cancel' : 'إلغاء'); ?></button>
                <button type="submit" class="btn"><?php echo e($lang === 'en' ? 'OK' : 'موافق'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
  var chk = document.getElementById('addDebtCustomChk');
  var box = document.getElementById('addDebtCustomBox');
  if (chk && box) {
    function sync() {
      if (chk.checked) box.classList.remove('hidden');
      else box.classList.add('hidden');
    }
    chk.addEventListener('change', sync);
    sync();
  }
  var search = document.getElementById('activitySearch');
  var list = document.getElementById('activityList');
  if (search && list) {
    search.addEventListener('input', function () {
      var q = search.value.toLowerCase().trim();
      var items = list.querySelectorAll('.activity-item');
      for (var i = 0; i < items.length; i++) {
        var hay = items[i].getAttribute('data-search') || '';
        items[i].style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
      }
    });
  }

  var modal = document.getElementById('amountModal');
  var invInput = document.getElementById('amountInvoiceId');
  var amtInput = document.getElementById('amountInput');
  var title = document.getElementById('amountModalTitle');
  var cancelBtn = document.getElementById('amountCancelBtn');
  function openModal(id, amount, month) {
    if (!modal || !invInput || !amtInput) return;
    invInput.value = id;
    amtInput.value = amount;
    if (title) {
      title.textContent = <?php echo json_encode($lang === 'en' ? 'Edit amount' : 'تعديل المبلغ'); ?> + (month ? ' — ' + month : '');
    }
    modal.classList.remove('hidden');
    setTimeout(function () { amtInput.focus(); amtInput.select(); }, 50);
  }
  function closeModal() {
    if (modal) modal.classList.add('hidden');
  }
  var btns = document.querySelectorAll('.amount-edit-btn');
  for (var b = 0; b < btns.length; b++) {
    btns[b].addEventListener('click', function () {
      openModal(this.getAttribute('data-id'), this.getAttribute('data-amount'), this.getAttribute('data-month'));
    });
  }
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeModal();
      closeDaysModal();
    }
  });

  var daysModal = document.getElementById('daysModal');
  var daysInput = document.getElementById('daysInput');
  var daysOpenBtn = document.getElementById('openDaysModalBtn');
  var daysCancel = document.getElementById('daysCancelBtn');
  var daysPlanWrap = document.getElementById('daysPlanWrap');
  var daysPlanSelect = document.getElementById('daysPlanSelect');
  function openDaysModal(days, hasSub) {
    if (!daysModal || !daysInput) return;
    daysInput.value = (days === '' || days === null || typeof days === 'undefined') ? '' : String(days);
    var needPlan = String(hasSub) !== '1';
    if (daysPlanWrap) {
      if (needPlan) {
        daysPlanWrap.classList.remove('hidden');
        daysPlanWrap.style.display = '';
        if (daysPlanSelect) daysPlanSelect.required = true;
      } else {
        daysPlanWrap.classList.add('hidden');
        daysPlanWrap.style.display = 'none';
        if (daysPlanSelect) {
          daysPlanSelect.required = false;
          daysPlanSelect.value = '';
        }
      }
    }
    daysModal.classList.remove('hidden');
    setTimeout(function () { daysInput.focus(); daysInput.select(); }, 30);
  }
  function closeDaysModal() {
    if (daysModal) daysModal.classList.add('hidden');
  }
  if (daysOpenBtn) {
    daysOpenBtn.addEventListener('click', function () {
      openDaysModal(daysOpenBtn.getAttribute('data-days'), daysOpenBtn.getAttribute('data-has-sub'));
    });
  }
  if (daysCancel) daysCancel.addEventListener('click', closeDaysModal);
  if (daysModal) {
    daysModal.addEventListener('click', function (e) {
      if (e.target === daysModal) closeDaysModal();
    });
  }

  var rentChk = document.getElementById('rentalEnabledChk');
  var rentDetails = document.getElementById('rentalDetails');
  var rentSaveOff = document.getElementById('rentalSaveOff');
  var rentMsgs = document.getElementById('rentalMsgActions');
  var rentSelect = document.getElementById('rentalDeviceSelect');
  function setShown(el, on) {
    if (!el) return;
    if (on) {
      el.removeAttribute('hidden');
      el.style.display = '';
      el.classList.remove('hidden');
    } else {
      el.setAttribute('hidden', 'hidden');
      el.style.display = 'none';
      el.classList.add('hidden');
    }
  }
  function syncRentalUi() {
    if (!rentChk) return;
    var on = !!rentChk.checked;
    setShown(rentDetails, on);
    setShown(rentSaveOff, !on);
    setShown(rentMsgs, on);
    if (rentSelect) rentSelect.disabled = !on;
  }
  if (rentChk) {
    rentChk.addEventListener('change', syncRentalUi);
    syncRentalUi();
  }
})();
</script>
<?php render_footer(); ?>
