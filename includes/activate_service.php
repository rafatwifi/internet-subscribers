<?php

/**
 * عمود الباقة المفضلة على المشتركين (نوع الاشتراك عند الإضافة)
 */
function ensure_preferred_plan_column($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $exists = $pdo->query("SHOW COLUMNS FROM subscribers LIKE 'preferred_plan_id'")->fetch();
        if (!$exists) {
            $pdo->exec(
                'ALTER TABLE subscribers
                 ADD COLUMN preferred_plan_id INT NULL DEFAULT NULL AFTER notes'
            );
        }
    } catch (Exception $e) {
        // تجاهل
    }
    $done = true;
}

/**
 * إيجاد باقة بالاسم (مطابقة غير حساسة لحالة الأحرف)
 */
function find_plan_by_service_name($pdo, $serviceName)
{
    $serviceName = trim((string) $serviceName);
    if ($serviceName === '') {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT * FROM service_plans
         WHERE is_active = 1 AND LOWER(name) = LOWER(:n)
         ORDER BY sort_order ASC, id ASC
         LIMIT 1'
    );
    $st->execute(array(':n' => $serviceName));
    $row = $st->fetch();
    if ($row) {
        return $row;
    }
    // مطابقة تقريبية بالسعر إن فشل الاسم
    return null;
}

/**
 * تحديد باقة المشترك: الاشتراك النشط ← المفضلة ← آخر اشتراك
 */
function resolve_subscriber_plan($pdo, $subscriberId)
{
    $subscriberId = (int) $subscriberId;
    if ($subscriberId <= 0) {
        return null;
    }

    $st = $pdo->prepare(
        'SELECT service_name, monthly_price FROM subscriptions
         WHERE subscriber_id = :id AND status = "active" AND end_date >= CURDATE()
         ORDER BY id DESC LIMIT 1'
    );
    $st->execute(array(':id' => $subscriberId));
    $active = $st->fetch();
    if ($active) {
        $plan = find_plan_by_service_name($pdo, $active['service_name']);
        if ($plan) {
            return $plan;
        }
        // باقة محذوفة — نبني صفاً مؤقتاً من الاشتراك
        return array(
            'id' => 0,
            'name' => $active['service_name'],
            'monthly_price' => (float) $active['monthly_price'],
            'cost_price' => 0,
            'is_active' => 1,
            '_virtual' => 1,
        );
    }

    ensure_preferred_plan_column($pdo);
    $pref = $pdo->prepare('SELECT preferred_plan_id FROM subscribers WHERE id = :id');
    $pref->execute(array(':id' => $subscriberId));
    $prefId = (int) $pref->fetchColumn();
    if ($prefId > 0) {
        $pst = $pdo->prepare('SELECT * FROM service_plans WHERE id = :id AND is_active = 1');
        $pst->execute(array(':id' => $prefId));
        $plan = $pst->fetch();
        if ($plan) {
            return $plan;
        }
    }

    $last = $pdo->prepare(
        'SELECT service_name, monthly_price FROM subscriptions
         WHERE subscriber_id = :id
         ORDER BY id DESC LIMIT 1'
    );
    $last->execute(array(':id' => $subscriberId));
    $lastRow = $last->fetch();
    if ($lastRow) {
        $plan = find_plan_by_service_name($pdo, $lastRow['service_name']);
        if ($plan) {
            return $plan;
        }
        return array(
            'id' => 0,
            'name' => $lastRow['service_name'],
            'monthly_price' => (float) $lastRow['monthly_price'],
            'cost_price' => 0,
            'is_active' => 1,
            '_virtual' => 1,
        );
    }

    return null;
}

/**
 * تفعيل مشترك واحد (اشتراك + فاتورة + رسالة اختيارية + لوك)
 * $opts: plan_id, pay_mode, send_whatsapp, send_old_debts, message_note,
 *        start_date, end_date, days_left (-1=غير محدد), carry_days (bool)
 * يرجع: array($ok, $message, $details)
 */
function activate_one_subscriber($pdo, $config, $subscriberId, $opts = array())
{
    $subscriberId = (int) $subscriberId;
    if ($subscriberId <= 0) {
        return array(false, 'مشترك غير صالح', null);
    }

    $planId = isset($opts['plan_id']) ? (int) $opts['plan_id'] : 0;
    $payMode = (isset($opts['pay_mode']) && $opts['pay_mode'] === 'credit') ? 'credit' : 'cash';
    $sendWhatsapp = !empty($opts['send_whatsapp']);
    $sendOldDebts = $sendWhatsapp && !empty($opts['send_old_debts']);
    $msgNote = isset($opts['message_note']) ? trim((string) $opts['message_note']) : '';
    $startDate = isset($opts['start_date']) && $opts['start_date'] !== ''
        ? (string) $opts['start_date']
        : date('Y-m-d');
    $endDate = isset($opts['end_date']) ? (string) $opts['end_date'] : '';
    $daysRaw = isset($opts['days_left']) ? $opts['days_left'] : null;
    $daysLeftPost = ($daysRaw === null || $daysRaw === '') ? -1 : (int) $daysRaw;
    $doCarry = !isset($opts['carry_days']) || $opts['carry_days'];

    $subRowSt = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
    $subRowSt->execute(array(':id' => $subscriberId));
    $subscriberRow = $subRowSt->fetch();
    if (!$subscriberRow) {
        return array(false, 'المشترك غير موجود', null);
    }

    if ($planId > 0) {
        $planStmt = $pdo->prepare('SELECT * FROM service_plans WHERE id = :id AND is_active = 1');
        $planStmt->execute(array(':id' => $planId));
        $plan = $planStmt->fetch();
    } else {
        $plan = resolve_subscriber_plan($pdo, $subscriberId);
    }
    if (!$plan) {
        return array(false, 'اختر نوع الاشتراك / الباقة أولاً', null);
    }

    $serviceName = $plan['name'];
    $monthlyPrice = (float) $plan['monthly_price'];
    $costPrice = isset($plan['cost_price']) ? (float) $plan['cost_price'] : 0;
    $planIdSaved = isset($plan['id']) ? (int) $plan['id'] : 0;

    if ($daysLeftPost >= 0) {
        $endDate = date('Y-m-d', strtotime($startDate . ' +' . $daysLeftPost . ' days'));
    } elseif ($endDate === '') {
        $endDate = subscription_period_end($startDate, $config);
    }
    if ($endDate < $startDate) {
        return array(false, 'تاريخ الانتهاء غير صحيح', null);
    }

    $carryDays = 0;
    if ($doCarry) {
        $carrySt = $pdo->prepare(
            'SELECT end_date FROM subscriptions
             WHERE subscriber_id = :id AND status = "active" AND end_date >= CURDATE()
             ORDER BY end_date DESC LIMIT 1'
        );
        $carrySt->execute(array(':id' => $subscriberId));
        $oldEnd = $carrySt->fetchColumn();
        if ($oldEnd) {
            $carryDays = (int) floor((strtotime($oldEnd) - strtotime(date('Y-m-d'))) / 86400);
            if ($carryDays < 0) {
                $carryDays = 0;
            }
        }
        if ($carryDays > 0) {
            $endDate = date('Y-m-d', strtotime($endDate . ' +' . $carryDays . ' days'));
        }
    }

    $graceDays = 0;
    if ($daysLeftPost < 0 && function_exists('subscriber_grace_days')) {
        $graceDays = subscriber_grace_days($subscriberRow, $config);
        if ($graceDays > 0) {
            $endDate = date('Y-m-d', strtotime($endDate . ' +' . $graceDays . ' days'));
        }
    }

    $oldDebtLines = array();
    $oldDebtSum = 0.0;
    if ($sendOldDebts) {
        $oldSt = $pdo->prepare(
            'SELECT month_label, amount, notes FROM invoices
             WHERE subscriber_id = :id AND status = "unpaid"
             ORDER BY due_date ASC, id ASC'
        );
        $oldSt->execute(array(':id' => $subscriberId));
        foreach ($oldSt->fetchAll() as $od) {
            $oldDebtLines[] = $od;
            $oldDebtSum += (float) $od['amount'];
        }
    }

    $settingsNow = function_exists('settings_load') ? settings_load() : array();
    $rentalFee = 0.0;
    $rentalDev = null;
    if (function_exists('subscriber_has_rental') && subscriber_has_rental($subscriberRow)) {
        $rentalFee = function_exists('rental_fee_amount') ? (float) rental_fee_amount($settingsNow) : 0.0;
        $rentalDev = function_exists('rental_device_by_id')
            ? rental_device_by_id($subscriberRow['rental_device_id'], $settingsNow)
            : null;
    }
    $chargeTotal = $monthlyPrice + $rentalFee;

    $isCash = ($payMode === 'cash');
    $invStatus = $isCash ? 'paid' : 'unpaid';
    $profit = $isCash ? ($chargeTotal - $costPrice) : 0;
    $modeLabel = $isCash ? 'نقد' : 'آجل';
    $invNotes = $modeLabel;
    if ($rentalFee > 0) {
        $invNotes .= ' — اشتراك ' . $monthlyPrice . ' + إيجار ' . $rentalFee;
        if ($rentalDev) {
            $invNotes .= ' (' . $rentalDev['name'] . ')';
        }
    }
    if ($msgNote !== '') {
        $invNotes .= ' — ' . $msgNote;
    }

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare(
            'UPDATE subscriptions SET status = "expired"
             WHERE subscriber_id = :id AND status = "active"'
        )->execute(array(':id' => $subscriberId));

        $stmt = $pdo->prepare(
            'INSERT INTO subscriptions
            (subscriber_id, service_name, monthly_price, cost_price, start_date, end_date, status, activation_msg_sent)
            VALUES
            (:subscriber_id, :service_name, :monthly_price, :cost_price, :start_date, :end_date, "active", 0)'
        );
        $stmt->execute(array(
            ':subscriber_id' => $subscriberId,
            ':service_name' => $serviceName,
            ':monthly_price' => $monthlyPrice,
            ':cost_price' => $costPrice,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ));
        $subscriptionId = (int) $pdo->lastInsertId();

        $monthLabel = date('Y-m', strtotime($startDate));
        $inv = $pdo->prepare(
            'INSERT INTO invoices
            (subscription_id, subscriber_id, month_label, amount, cost_price, profit, due_date, status, paid_at, notes)
            VALUES
            (:subscription_id, :subscriber_id, :month_label, :amount, :cost_price, :profit, :due_date, :status, :paid_at, :notes)'
        );
        $inv->execute(array(
            ':subscription_id' => $subscriptionId,
            ':subscriber_id' => $subscriberId,
            ':month_label' => $monthLabel,
            ':amount' => $chargeTotal,
            ':cost_price' => $costPrice,
            ':profit' => $profit,
            ':due_date' => $startDate,
            ':status' => $invStatus,
            ':paid_at' => $isCash ? date('Y-m-d H:i:s') : null,
            ':notes' => $invNotes,
        ));
        $invoiceId = (int) $pdo->lastInsertId();

        if ($planIdSaved > 0) {
            ensure_preferred_plan_column($pdo);
            $pdo->prepare('UPDATE subscribers SET preferred_plan_id = :p WHERE id = :id')
                ->execute(array(':p' => $planIdSaved, ':id' => $subscriberId));
        }

        if (function_exists('activity_log')) {
            activity_log(
                $pdo,
                $subscriberId,
                'subscription',
                $subscriptionId,
                'activate',
                'تفعيل اشتراك (' . $modeLabel . ') — ' . $serviceName,
                'الباقة: ' . $serviceName . "\n"
                . 'المبلغ: ' . $chargeTotal . "\n"
                . 'من: ' . $startDate . ' إلى: ' . $endDate . "\n"
                . ($carryDays > 0 ? ('أيام محمولة: ' . $carryDays . "\n") : '')
                . ($graceDays > 0 ? ('أيام سماح: ' . $graceDays . "\n") : '')
                . 'فاتورة #' . $invoiceId
            );
        }

        $waOk = null;
        $waMsg = '';
        if ($sendWhatsapp) {
            $subInfo = $pdo->prepare(
                'SELECT sub.*, s.name, s.phone, s.rental_enabled, s.rental_device_id
                 FROM subscriptions sub
                 JOIN subscribers s ON s.id = sub.subscriber_id
                 WHERE sub.id = :id'
            );
            $subInfo->execute(array(':id' => $subscriptionId));
            $row = $subInfo->fetch();
            if ($row) {
                $extra = $msgNote;
                if ($isCash) {
                    $extra = trim(($extra !== '' ? $extra . "\n" : '') . 'تم استلام المبلغ نقداً');
                } else {
                    $extra = trim(($extra !== '' ? $extra . "\n" : '') . 'الدفع آجل — يرجى التسديد');
                }
                if ($sendOldDebts && $oldDebtLines) {
                    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
                    $extra .= "\n\nالديون السابقة:";
                    foreach ($oldDebtLines as $od) {
                        $lab = month_short_label($od['month_label']);
                        $extra .= "\n• " . $lab . ': ' . money_format_iqd($od['amount'], $currency);
                        if (!empty($od['notes'])) {
                            $extra .= ' (' . $od['notes'] . ')';
                        }
                    }
                    $extra .= "\nإجمالي السابق: " . money_format_iqd($oldDebtSum, $currency);
                    if (!$isCash) {
                        $extra .= "\nدين التفعيل: " . money_format_iqd($chargeTotal, $currency);
                        $extra .= "\nالإجمالي الكلي: " . money_format_iqd($oldDebtSum + $chargeTotal, $currency);
                    } else {
                        $totalAfter = subscriber_unpaid_total($pdo, $subscriberId);
                        $extra .= "\nالإجمالي المتبقي: " . money_format_iqd($totalAfter, $currency);
                    }
                } elseif (!$isCash) {
                    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
                    $totalAfter = subscriber_unpaid_total($pdo, $subscriberId);
                    $extra .= "\nإجمالي الدين: " . money_format_iqd($totalAfter, $currency);
                }
                $rentName = $rentalDev ? $rentalDev['name'] : '';
                $msg = activation_message_with_rental($row, $config, $extra, $rentalFee, $rentName);
                $result = whatsapp_send($config, $row['phone'], $msg, 'activation');
                log_message($pdo, $subscriberId, $result);
                $waOk = !empty($result['success']);
                $waMsg = $waOk ? '' : whatsapp_fail_user_message($result);
                if ($waOk) {
                    $pdo->prepare('UPDATE subscriptions SET activation_msg_sent = 1 WHERE id = :id')
                        ->execute(array(':id' => $subscriptionId));
                }
            }
        }

        $sasOk = null;
        $sasMsg = '';
        if (empty($opts['skip_sas']) && function_exists('sas_sync_on_activate')) {
            list($sasOk, $sasMsg) = sas_sync_on_activate($pdo, $config, $subscriberRow, $plan, $opts);
            if (!$sasOk) {
                $sasCfg = function_exists('sas_config') ? sas_config($config) : array('on_failure' => 'warn');
                if (isset($sasCfg['on_failure']) && $sasCfg['on_failure'] === 'rollback' && $ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                    return array(false, $sasMsg, null);
                }
            }
        }

        if ($ownTx) {
            $pdo->commit();
        }

        $okMsg = 'تم التفعيل (' . $modeLabel . ') — ' . $serviceName;
        if ($rentalFee > 0) {
            $okMsg .= ' مع إيجار ' . $rentalFee;
        }
        if ($carryDays > 0) {
            $okMsg .= ' — أُضيف +' . $carryDays . ' يوم';
        }
        if ($graceDays > 0) {
            $okMsg .= ' — سماح +' . $graceDays . ' يوم';
        }
        if ($sendWhatsapp) {
            if ($waOk) {
                $okMsg .= ' وإرسال رسالة';
            } elseif ($waMsg !== '') {
                $okMsg .= ' — ' . $waMsg;
            }
        }
        if ($sasMsg !== '') {
            if ($sasOk) {
                $okMsg .= ' — ' . $sasMsg;
            } else {
                $okMsg .= ' — تحذير: ' . $sasMsg;
            }
        }

        return array(true, $okMsg, array(
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
            'charge_total' => $chargeTotal,
            'carry_days' => $carryDays,
            'pay_mode' => $payMode,
            'whatsapp_ok' => $waOk,
            'sas_ok' => $sasOk,
            'sas_message' => $sasMsg,
            'service_name' => $serviceName,
        ));
    } catch (Exception $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array(false, 'فشل التفعيل: ' . $e->getMessage(), null);
    }
}

/**
 * تغيير نوع اشتراك المشترك النشط + تعديل مبلغ الفاتورة القديمة / إضافة فرق
 * يرجع array($ok, $message)
 */
function change_subscriber_plan($pdo, $config, $subscriberId, $planId)
{
    $subscriberId = (int) $subscriberId;
    $planId = (int) $planId;
    if ($subscriberId <= 0 || $planId <= 0) {
        return array(false, 'بيانات غير كاملة');
    }

    $planStmt = $pdo->prepare('SELECT * FROM service_plans WHERE id = :id AND is_active = 1');
    $planStmt->execute(array(':id' => $planId));
    $plan = $planStmt->fetch();
    if (!$plan) {
        return array(false, 'الباقة غير موجودة');
    }

    $st = $pdo->prepare(
        'SELECT * FROM subscriptions
         WHERE subscriber_id = :id AND status = "active" AND end_date >= CURDATE()
         ORDER BY id DESC LIMIT 1'
    );
    $st->execute(array(':id' => $subscriberId));
    $active = $st->fetch();
    if (!$active) {
        return array(false, 'ماكو اشتراك نشط — فعّل المشترك أولاً أو اختر الباقة عند الإضافة');
    }

    $oldName = (string) $active['service_name'];
    $oldPrice = (float) $active['monthly_price'];
    $newName = (string) $plan['name'];
    $newPrice = (float) $plan['monthly_price'];
    $newCost = isset($plan['cost_price']) ? (float) $plan['cost_price'] : 0;

    if (strcasecmp($oldName, $newName) === 0 && abs($oldPrice - $newPrice) < 0.01) {
        return array(false, 'نوع الاشتراك نفسه — ماكو تغيير');
    }

    $subRowSt = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
    $subRowSt->execute(array(':id' => $subscriberId));
    $subscriberRow = $subRowSt->fetch();
    $rentalFee = 0.0;
    $rentNote = '';
    if ($subscriberRow && function_exists('subscriber_has_rental') && subscriber_has_rental($subscriberRow)) {
        $settingsNow = function_exists('settings_load') ? settings_load() : array();
        $rentalFee = function_exists('rental_fee_amount') ? (float) rental_fee_amount($settingsNow) : 0.0;
        $dev = function_exists('rental_device_by_id')
            ? rental_device_by_id($subscriberRow['rental_device_id'], $settingsNow)
            : null;
        if ($dev && !empty($dev['name'])) {
            $rentNote = $dev['name'];
        }
    }
    $newCharge = $newPrice + $rentalFee;
    $oldChargeEst = $oldPrice + $rentalFee;

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE subscriptions
             SET service_name = :name, monthly_price = :price, cost_price = :cost
             WHERE id = :id AND subscriber_id = :sid'
        )->execute(array(
            ':name' => $newName,
            ':price' => $newPrice,
            ':cost' => $newCost,
            ':id' => (int) $active['id'],
            ':sid' => $subscriberId,
        ));

        ensure_preferred_plan_column($pdo);
        $pdo->prepare('UPDATE subscribers SET preferred_plan_id = :p WHERE id = :id')
            ->execute(array(':p' => $planId, ':id' => $subscriberId));

        $invSt = $pdo->prepare(
            'SELECT * FROM invoices
             WHERE subscription_id = :sid AND subscriber_id = :uid AND status = "unpaid"
             ORDER BY id DESC LIMIT 1'
        );
        $invSt->execute(array(':sid' => (int) $active['id'], ':uid' => $subscriberId));
        $unpaidInv = $invSt->fetch();

        $invoiceNote = '';
        if ($unpaidInv) {
            $notes = 'تعديل باقة — ' . $oldName . ' ← ' . $newName;
            if ($rentalFee > 0) {
                $notes .= ' — اشتراك ' . $newPrice . ' + إيجار ' . $rentalFee
                    . ($rentNote !== '' ? (' (' . $rentNote . ')') : '');
            }
            $pdo->prepare(
                'UPDATE invoices
                 SET amount = :amount, cost_price = :cost, notes = :notes
                 WHERE id = :id AND status = "unpaid"'
            )->execute(array(
                ':amount' => $newCharge,
                ':cost' => $newCost,
                ':notes' => $notes,
                ':id' => (int) $unpaidInv['id'],
            ));
            $invoiceNote = 'تعديل فاتورة غير مسددة #' . (int) $unpaidInv['id']
                . ' من ' . (float) $unpaidInv['amount'] . ' إلى ' . $newCharge;
            if (function_exists('activity_log')) {
                activity_log(
                    $pdo,
                    $subscriberId,
                    'invoice',
                    (int) $unpaidInv['id'],
                    'update',
                    'تعديل مبلغ دين بسبب تغيير الباقة',
                    $invoiceNote
                );
            }
        } else {
            // لا يوجد دين مفتوح لهذا الاشتراك — إن زاد المبلغ نضيف فرق كدين
            $diff = $newCharge - $oldChargeEst;
            if ($diff > 0.009) {
                $notes = 'فرق ترقية باقة — ' . $oldName . ' ← ' . $newName;
                $ins = $pdo->prepare(
                    'INSERT INTO invoices
                    (subscription_id, subscriber_id, month_label, amount, cost_price, profit, due_date, status, notes)
                    VALUES
                    (:subscription_id, :subscriber_id, :month_label, :amount, 0, 0, :due_date, "unpaid", :notes)'
                );
                $ins->execute(array(
                    ':subscription_id' => (int) $active['id'],
                    ':subscriber_id' => $subscriberId,
                    ':month_label' => date('Y-m'),
                    ':amount' => $diff,
                    ':due_date' => date('Y-m-d'),
                    ':notes' => $notes,
                ));
                $newInvId = (int) $pdo->lastInsertId();
                $invoiceNote = 'إضافة دين فرق #' . $newInvId . ' بمبلغ ' . $diff;
                if (function_exists('activity_log')) {
                    activity_log(
                        $pdo,
                        $subscriberId,
                        'invoice',
                        $newInvId,
                        'create',
                        'دين فرق ترقية باقة',
                        $notes . "\n" . $invoiceNote
                    );
                }
            } else {
                $invoiceNote = 'ماكو فاتورة غير مسددة — لم يُضف فرق (المبلغ الجديد أقل أو مساوي)';
            }
        }

        if (function_exists('activity_log')) {
            activity_log(
                $pdo,
                $subscriberId,
                'subscription',
                (int) $active['id'],
                'change_plan',
                'تغيير نوع الاشتراك: ' . $oldName . ' ← ' . $newName,
                'السعر: ' . $oldPrice . ' ← ' . $newPrice . "\n"
                . 'الإجمالي مع الإيجار: ' . $oldChargeEst . ' ← ' . $newCharge . "\n"
                . $invoiceNote
            );
        }

        $pdo->commit();
        $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
        $msg = 'تم تغيير الاشتراك إلى ' . $newName
            . ' — المبلغ ' . money_format_iqd($newCharge, $currency);
        return array(true, $msg);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array(false, 'فشل تغيير الباقة: ' . $e->getMessage());
    }
}

/**
 * تست 24 ساعة: اشتراك يوم واحد بدون فاتورة + تفعيل تجريبي على SAS
 * يرجع array($ok, $message)
 */
function activate_subscriber_test($pdo, $config, $subscriberId)
{
    $subscriberId = (int) $subscriberId;
    if ($subscriberId <= 0) {
        return array(false, 'مشترك غير صالح');
    }

    $subRowSt = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
    $subRowSt->execute(array(':id' => $subscriberId));
    $subscriberRow = $subRowSt->fetch();
    if (!$subscriberRow) {
        return array(false, 'المشترك غير موجود');
    }

    $plan = resolve_subscriber_plan($pdo, $subscriberId);
    if (!$plan) {
        return array(false, 'اختر نوع الاشتراك / الباقة أولاً');
    }

    $activeSt = $pdo->prepare(
        'SELECT id, end_date, monthly_price FROM subscriptions
         WHERE subscriber_id = :id AND status = "active" AND end_date >= CURDATE()
         ORDER BY end_date DESC LIMIT 1'
    );
    $activeSt->execute(array(':id' => $subscriberId));
    $active = $activeSt->fetch();
    if ($active) {
        $left = (int) ceil((strtotime($active['end_date']) - strtotime(date('Y-m-d'))) / 86400);
        $isTestRow = ((float) $active['monthly_price'] <= 0);
        if ($left > 1 && !$isTestRow) {
            return array(false, 'المشترك عنده اشتراك فعال (' . $left . ' يوم) — التست للمنتهين أو الجدد');
        }
    }

    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+1 day'));
    $serviceName = $plan['name'];
    $planIdSaved = isset($plan['id']) ? (int) $plan['id'] : 0;

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare(
            'UPDATE subscriptions SET status = "expired"
             WHERE subscriber_id = :id AND status = "active"'
        )->execute(array(':id' => $subscriberId));

        $stmt = $pdo->prepare(
            'INSERT INTO subscriptions
            (subscriber_id, service_name, monthly_price, cost_price, start_date, end_date, status, activation_msg_sent)
            VALUES
            (:subscriber_id, :service_name, 0, 0, :start_date, :end_date, "active", 0)'
        );
        $stmt->execute(array(
            ':subscriber_id' => $subscriberId,
            ':service_name' => $serviceName,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ));
        $subscriptionId = (int) $pdo->lastInsertId();

        if ($planIdSaved > 0) {
            ensure_preferred_plan_column($pdo);
            $pdo->prepare('UPDATE subscribers SET preferred_plan_id = :p WHERE id = :id')
                ->execute(array(':p' => $planIdSaved, ':id' => $subscriberId));
        }

        if (function_exists('activity_log')) {
            activity_log(
                $pdo,
                $subscriberId,
                'subscription',
                $subscriptionId,
                'test_24h',
                'تست 24 ساعة — ' . $serviceName,
                'من: ' . $startDate . ' إلى: ' . $endDate . "\nبدون فاتورة"
            );
        }

        $sasMsg = '';
        if (function_exists('sas_sync_on_test')) {
            list($sasOk, $sasMsg) = sas_sync_on_test($pdo, $config, $subscriberRow, $plan);
            if (!$sasOk) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return array(false, $sasMsg);
            }
        } else {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return array(false, 'خدمة SAS غير موجودة على السيرفر');
        }

        if ($ownTx) {
            $pdo->commit();
        }

        $okMsg = 'تم إعطاء تست 24 ساعة — ' . $serviceName;
        if ($sasMsg !== '') {
            $okMsg .= ' — ' . $sasMsg;
        }
        return array(true, $okMsg);
    } catch (Exception $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array(false, 'فشل التست: ' . $e->getMessage());
    }
}
