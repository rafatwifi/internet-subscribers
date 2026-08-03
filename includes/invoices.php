<?php

/**
 * مجموع الديون غير المسددة لمشترك
 */
function subscriber_unpaid_total($pdo, $subscriberId)
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount),0) FROM invoices WHERE subscriber_id = :id AND status = "unpaid"'
    );
    $stmt->execute(array(':id' => (int) $subscriberId));
    return (float) $stmt->fetchColumn();
}

/**
 * تسديد فاتورة بالكامل أو جزئياً.
 * يرجع: array($ok, $messageOrCode, $details)
 * $details: paid_amount, remaining_invoice, remaining_total, row
 */
function apply_invoice_payment($pdo, $config, $invoiceId, $payAmount, $sendWhatsapp)
{
    $invoiceId = (int) $invoiceId;
    $payAmount = (float) $payAmount;

    $stmt = $pdo->prepare(
        'SELECT i.*, s.name, s.phone, sub.cost_price AS sub_cost
         FROM invoices i
         JOIN subscribers s ON s.id = i.subscriber_id
         LEFT JOIN subscriptions sub ON sub.id = i.subscription_id
         WHERE i.id = :id AND i.status = "unpaid"'
    );
    $stmt->execute(array(':id' => $invoiceId));
    $row = $stmt->fetch();
    if (!$row) {
        return array(false, 'الفاتورة غير موجودة أو مسددة مسبقاً', null);
    }

    $due = (float) $row['amount'];
    if ($payAmount <= 0) {
        return array(false, 'أدخل مبلغ تسديد صحيح', null);
    }
    if ($payAmount > $due) {
        $payAmount = $due;
    }

    $cost = isset($row['cost_price']) && (float) $row['cost_price'] > 0
        ? (float) $row['cost_price']
        : (float) (isset($row['sub_cost']) ? $row['sub_cost'] : 0);

    $isFull = ($payAmount + 0.0001) >= $due;
    $paidCost = $isFull ? $cost : round($cost * ($payAmount / $due));
    if ($paidCost > $cost) {
        $paidCost = $cost;
    }
    $paidProfit = $payAmount - $paidCost;

    try {
        $pdo->beginTransaction();

        if ($isFull) {
            $upd = $pdo->prepare(
                'UPDATE invoices
                 SET status = "paid", paid_at = NOW(), cost_price = :cost, profit = :profit
                 WHERE id = :id'
            );
            $upd->execute(array(
                ':cost' => $paidCost,
                ':profit' => $paidProfit,
                ':id' => $invoiceId,
            ));
            $remainingInvoice = 0;
        } else {
            $remain = $due - $payAmount;
            $remainCost = $cost - $paidCost;
            if ($remainCost < 0) {
                $remainCost = 0;
            }

            $ins = $pdo->prepare(
                'INSERT INTO invoices
                    (subscription_id, subscriber_id, month_label, amount, cost_price, profit, due_date, status, paid_at, notes)
                 VALUES
                    (:subscription_id, :subscriber_id, :month_label, :amount, :cost_price, :profit, :due_date, "paid", NOW(), :notes)'
            );
            $notePaid = 'تسديد جزئي من فاتورة #' . $invoiceId;
            if (!empty($row['notes'])) {
                $notePaid .= ' — ' . $row['notes'];
            }
            $ins->execute(array(
                ':subscription_id' => $row['subscription_id'] ? (int) $row['subscription_id'] : null,
                ':subscriber_id' => (int) $row['subscriber_id'],
                ':month_label' => $row['month_label'],
                ':amount' => $payAmount,
                ':cost_price' => $paidCost,
                ':profit' => $paidProfit,
                ':due_date' => $row['due_date'],
                ':notes' => $notePaid,
            ));

            $upd = $pdo->prepare(
                'UPDATE invoices SET amount = :amount, cost_price = :cost WHERE id = :id AND status = "unpaid"'
            );
            $upd->execute(array(
                ':amount' => $remain,
                ':cost' => $remainCost,
                ':id' => $invoiceId,
            ));
            $remainingInvoice = $remain;
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array(false, 'فشل التسديد: ' . $e->getMessage(), null);
    }

    $remainingTotal = subscriber_unpaid_total($pdo, (int) $row['subscriber_id']);
    $details = array(
        'paid_amount' => $payAmount,
        'remaining_invoice' => $remainingInvoice,
        'remaining_total' => $remainingTotal,
        'row' => $row,
        'full' => $isFull,
    );

    if (function_exists('activity_log')) {
        $payDetails = 'المبلغ المسدد: ' . $payAmount . "\n"
            . 'الشهر: ' . $row['month_label'] . "\n"
            . 'متبقي الفاتورة: ' . $remainingInvoice . "\n"
            . 'إجمالي المتبقي: ' . $remainingTotal;
        activity_log(
            $pdo,
            (int) $row['subscriber_id'],
            'invoice',
            (int) $invoiceId,
            'pay',
            ($isFull ? 'تسديد كامل' : 'تسديد جزئي') . ' دين #' . (int) $invoiceId,
            $payDetails
        );
    }

    $msgOk = null;
    if ($sendWhatsapp) {
        $payRow = array(
            'name' => $row['name'],
            'phone' => $row['phone'],
            'month_label' => $row['month_label'],
            'amount' => $payAmount,
            'remaining' => $remainingTotal,
            'notes' => isset($row['notes']) ? $row['notes'] : '',
        );
        $msg = payment_message($payRow, $config);
        $result = whatsapp_send($config, $row['phone'], $msg, 'payment');
        log_message($pdo, (int) $row['subscriber_id'], $result);
        $msgOk = !empty($result['success']);
        $details['whatsapp_ok'] = $msgOk;
        $details['whatsapp_msg'] = $msgOk ? '' : whatsapp_fail_user_message($result);
    }

    return array(true, 'ok', $details);
}

/**
 * إدخال ديون لمشترك (من نموذج إضافة)
 * يرجع array('count' => int, 'sum' => float)
 */
function insert_opening_debts($pdo, $subscriberId, $post)
{
    $amounts = isset($post['debt_amount']) && is_array($post['debt_amount']) ? $post['debt_amount'] : array();
    $months = isset($post['debt_month']) && is_array($post['debt_month']) ? $post['debt_month'] : array();
    $kinds = isset($post['debt_kind']) && is_array($post['debt_kind']) ? $post['debt_kind'] : array();
    $dnotes = isset($post['debt_notes']) && is_array($post['debt_notes']) ? $post['debt_notes'] : array();
    $debtCount = 0;
    $debtSum = 0.0;
    $insDebt = $pdo->prepare(
        'INSERT INTO invoices (subscription_id, subscriber_id, month_label, amount, cost_price, due_date, status, notes)
         VALUES (NULL, :subscriber_id, :month_label, :amount, 0, :due_date, "unpaid", :notes)'
    );
    $n = max(count($amounts), count($months), count($kinds), count($dnotes));
    for ($i = 0; $i < $n; $i++) {
        $amt = isset($amounts[$i]) ? (float) $amounts[$i] : 0;
        if ($amt <= 0) {
            continue;
        }
        $kind = isset($kinds[$i]) && $kinds[$i] === 'item' ? 'item' : 'month';
        $month = isset($months[$i]) ? trim((string) $months[$i]) : '';
        if ($kind === 'item') {
            if ($month === '' || preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = 'غرض';
            }
        } elseif ($month === '') {
            $month = date('Y-m');
        }
        $dnote = isset($dnotes[$i]) ? trim((string) $dnotes[$i]) : '';
        $insDebt->execute(array(
            ':subscriber_id' => (int) $subscriberId,
            ':month_label' => $month,
            ':amount' => $amt,
            ':due_date' => date('Y-m-d'),
            ':notes' => ($dnote !== '' ? $dnote : null),
        ));
        $newId = (int) $pdo->lastInsertId();
        if (function_exists('activity_log')) {
            $details = 'الشهر: ' . $month . "\n"
                . 'المبلغ: ' . $amt . "\n"
                . 'ملاحظات: ' . ($dnote !== '' ? $dnote : '-');
            activity_log(
                $pdo,
                (int) $subscriberId,
                'invoice',
                $newId,
                'create',
                'إضافة دين #' . $newId . ' — ' . month_short_label($month) . ' / ' . $amt,
                $details
            );
        }
        $debtCount++;
        $debtSum += $amt;
    }
    return array('count' => $debtCount, 'sum' => $debtSum);
}
