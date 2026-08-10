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
            'about' => invoice_debt_label($row),
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
 * سعر الاشتراك الشهري للمشترك (آخر اشتراك، أو باقة نشطة)
 */
function subscriber_monthly_price($pdo, $subscriberId)
{
    $subscriberId = (int) $subscriberId;
    if ($subscriberId <= 0) {
        return 0.0;
    }
    try {
        $st = $pdo->prepare(
            'SELECT monthly_price FROM subscriptions
             WHERE subscriber_id = :id
             ORDER BY (status = "active") DESC, id DESC
             LIMIT 1'
        );
        $st->execute(array(':id' => $subscriberId));
        $price = (float) $st->fetchColumn();
        if ($price > 0) {
            return $price;
        }
    } catch (Exception $e) {
    }
    return 0.0;
}

/**
 * تسمية دين للعرض/الرسالة (شهر أو غرض + ملاحظة قصيرة)
 */
function invoice_debt_label($inv)
{
    $month = isset($inv['month_label']) ? (string) $inv['month_label'] : '';
    $label = function_exists('month_short_label') ? month_short_label($month) : $month;
    $notes = isset($inv['notes']) ? trim((string) $inv['notes']) : '';
    if ($notes === '') {
        return $label;
    }
    // تجاهل ملاحظات التسديد الجزئي التقنية
    if (strpos($notes, 'تسديد جزئي من فاتورة') === 0) {
        $parts = explode('—', $notes, 2);
        $notes = isset($parts[1]) ? trim($parts[1]) : '';
        if ($notes === '') {
            return $label;
        }
    }
    // للأغراض أو الملاحظات المفيدة نضيفها
    if ($month === '' || !preg_match('/^\d{4}-\d{1,2}$/', $month)) {
        return $label . ' — ' . $notes;
    }
    return $label;
}

/**
 * تسديد ديون مشترك.
 * $targetInvoiceId > 0 = تسديد دين محدد (جزئي أو كامل لهذا الدين فقط)
 * بدون هدف = توزيع على أقدم الديون (تسديد الكل عادة)
 */
function apply_subscriber_payment($pdo, $config, $subscriberId, $payAmount, $sendWhatsapp, $targetInvoiceId = 0)
{
    $subscriberId = (int) $subscriberId;
    $payAmount = (float) $payAmount;
    $targetInvoiceId = (int) $targetInvoiceId;
    $remainingPay = $payAmount;
    if ($subscriberId <= 0 || $remainingPay <= 0) {
        return array(false, 'أدخل مبلغ تسديد صحيح', null);
    }

    // تسديد دين محدد — الرسالة تأخذ شهر/غرض ذلك الدين
    if ($targetInvoiceId > 0) {
        $chk = $pdo->prepare(
            'SELECT id FROM invoices
             WHERE id = :id AND subscriber_id = :sid AND status = "unpaid"'
        );
        $chk->execute(array(':id' => $targetInvoiceId, ':sid' => $subscriberId));
        if (!$chk->fetch()) {
            return array(false, 'الدين المختار غير موجود أو مسدد', null);
        }
        return apply_invoice_payment($pdo, $config, $targetInvoiceId, $payAmount, $sendWhatsapp);
    }

    $st = $pdo->prepare(
        'SELECT id, amount, month_label, notes FROM invoices
         WHERE subscriber_id = :id AND status = "unpaid"
         ORDER BY due_date ASC, id ASC'
    );
    $st->execute(array(':id' => $subscriberId));
    $invoices = $st->fetchAll();
    if (!$invoices) {
        return array(false, 'ماكو ديون غير مسددة', null);
    }

    $paidTotal = 0.0;
    $lastDetails = null;
    $lastOk = false;
    $paidLabels = array();
    foreach ($invoices as $inv) {
        if ($remainingPay <= 0) {
            break;
        }
        $due = (float) $inv['amount'];
        $chunk = ($remainingPay >= $due) ? $due : $remainingPay;
        // واتساب مرة واحدة بعد آخر دفعة
        list($ok, $msg, $details) = apply_invoice_payment($pdo, $config, (int) $inv['id'], $chunk, false);
        if (!$ok) {
            if ($paidTotal <= 0) {
                return array(false, $msg, null);
            }
            break;
        }
        $paidTotal += $chunk;
        $remainingPay -= $chunk;
        $lastDetails = $details;
        $lastOk = true;
        $lab = invoice_debt_label($inv);
        if ($lab !== '' && !in_array($lab, $paidLabels, true)) {
            $paidLabels[] = $lab;
        }
    }

    if (!$lastOk || $paidTotal <= 0) {
        return array(false, 'فشل التسديد', null);
    }

    $remainingTotal = subscriber_unpaid_total($pdo, $subscriberId);
    $info = $pdo->prepare('SELECT name, phone FROM subscribers WHERE id = :id');
    $info->execute(array(':id' => $subscriberId));
    $subRow = $info->fetch();

    $aboutLabel = '';
    if (count($paidLabels) === 1) {
        $aboutLabel = $paidLabels[0];
    } elseif (count($paidLabels) > 1) {
        $aboutLabel = implode(' + ', $paidLabels);
    } elseif ($lastDetails && !empty($lastDetails['row'])) {
        $aboutLabel = invoice_debt_label($lastDetails['row']);
    }

    $details = array(
        'paid_amount' => $paidTotal,
        'remaining_invoice' => 0,
        'remaining_total' => $remainingTotal,
        'row' => array(
            'name' => $subRow ? $subRow['name'] : '',
            'phone' => $subRow ? $subRow['phone'] : '',
            'month_label' => ($lastDetails && !empty($lastDetails['row']['month_label']))
                ? $lastDetails['row']['month_label']
                : date('Y-m'),
            'notes' => ($lastDetails && !empty($lastDetails['row']['notes']))
                ? $lastDetails['row']['notes']
                : '',
        ),
        'full' => ($remainingTotal <= 0.0001),
        'about_label' => $aboutLabel,
    );

    if ($sendWhatsapp && $subRow) {
        $payRow = array(
            'name' => $subRow['name'],
            'phone' => $subRow['phone'],
            'month_label' => $details['row']['month_label'],
            'amount' => $paidTotal,
            'remaining' => $remainingTotal,
            'notes' => $details['row']['notes'],
            'about' => $aboutLabel,
        );
        $msg = payment_message($payRow, $config);
        $result = whatsapp_send($config, $subRow['phone'], $msg, 'payment');
        log_message($pdo, $subscriberId, $result);
        $details['whatsapp_ok'] = !empty($result['success']);
        $details['whatsapp_msg'] = !empty($result['success']) ? '' : whatsapp_fail_user_message($result);
    }

    return array(true, 'ok', $details);
}

/**
 * إدخال ديون لمشترك (من نموذج إضافة)
 * يرجع array('count' => int, 'sum' => float)
 * الأنواع: month | item | month_rent (اشتراك + إيجار)
 */
function insert_opening_debts($pdo, $subscriberId, $post)
{
    $amounts = isset($post['debt_amount']) && is_array($post['debt_amount']) ? $post['debt_amount'] : array();
    $months = isset($post['debt_month']) && is_array($post['debt_month']) ? $post['debt_month'] : array();
    $kinds = isset($post['debt_kind']) && is_array($post['debt_kind']) ? $post['debt_kind'] : array();
    $dnotes = isset($post['debt_notes']) && is_array($post['debt_notes']) ? $post['debt_notes'] : array();
    $debtCount = 0;
    $debtSum = 0.0;

    $subPrice = subscriber_monthly_price($pdo, $subscriberId);
    $rentFee = 0.0;
    $rentLabel = '';
    $hasRent = false;
    if (function_exists('subscriber_has_rental')) {
        $subStmt = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $subStmt->execute(array(':id' => (int) $subscriberId));
        $subRow = $subStmt->fetch();
        if ($subRow && subscriber_has_rental($subRow)) {
            $hasRent = true;
            $settings = function_exists('settings_load') ? settings_load() : array();
            $rentFee = function_exists('rental_fee_amount') ? (float) rental_fee_amount($settings) : 0.0;
            $dev = function_exists('rental_device_by_id')
                ? rental_device_by_id(isset($subRow['rental_device_id']) ? $subRow['rental_device_id'] : '', $settings)
                : null;
            if ($dev && !empty($dev['name'])) {
                $rentLabel = $dev['name'];
            }
        }
    }

    $insDebt = $pdo->prepare(
        'INSERT INTO invoices (subscription_id, subscriber_id, month_label, amount, cost_price, due_date, status, notes)
         VALUES (NULL, :subscriber_id, :month_label, :amount, 0, :due_date, "unpaid", :notes)'
    );
    $n = max(count($amounts), count($months), count($kinds), count($dnotes));
    for ($i = 0; $i < $n; $i++) {
        $rawKind = isset($kinds[$i]) ? (string) $kinds[$i] : 'month';
        if ($rawKind === 'item') {
            $kind = 'item';
        } elseif ($rawKind === 'month_rent') {
            $kind = 'month_rent';
        } else {
            $kind = 'month';
        }

        $amt = isset($amounts[$i]) ? (float) $amounts[$i] : 0;
        $dnote = isset($dnotes[$i]) ? trim((string) $dnotes[$i]) : '';
        $month = isset($months[$i]) ? trim((string) $months[$i]) : '';

        if ($kind === 'month_rent') {
            if (!$hasRent) {
                continue;
            }
            $calc = $subPrice + $rentFee;
            if ($amt <= 0) {
                $amt = $calc;
            }
            if ($amt <= 0) {
                continue;
            }
            if ($month === '') {
                $month = date('Y-m');
            }
            if ($dnote === '') {
                $dnote = 'اشتراك ' . (int) $subPrice . ' + إيجار ' . (int) $rentFee
                    . ($rentLabel !== '' ? (' (' . $rentLabel . ')') : '');
            }
        } else {
            if ($amt <= 0) {
                continue;
            }
            if ($kind === 'item') {
                if ($month === '' || preg_match('/^\d{4}-\d{2}$/', $month)) {
                    $month = 'غرض';
                }
            } elseif ($month === '') {
                $month = date('Y-m');
            }
        }

        $insDebt->execute(array(
            ':subscriber_id' => (int) $subscriberId,
            ':month_label' => $month,
            ':amount' => $amt,
            ':due_date' => date('Y-m-d'),
            ':notes' => ($dnote !== '' ? $dnote : null),
        ));
        $newId = (int) $pdo->lastInsertId();
        if (function_exists('activity_log')) {
            $details = 'النوع: ' . $kind . "\n"
                . 'الشهر: ' . $month . "\n"
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
