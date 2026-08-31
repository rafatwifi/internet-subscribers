<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
redirect('sas.php');

$pdo->exec("UPDATE subscriptions SET status = 'expired' WHERE status = 'active' AND end_date < CURDATE()");

function mark_subscriber_invoice_paid($pdo, $invoiceId)
{
    // توافق قديم — استخدم apply_invoice_payment
    global $config;
    $st = $pdo->prepare('SELECT amount FROM invoices WHERE id = :id AND status = "unpaid"');
    $st->execute(array(':id' => (int) $invoiceId));
    $amt = (float) $st->fetchColumn();
    if ($amt <= 0) {
        return false;
    }
    list($ok) = apply_invoice_payment($pdo, $config, (int) $invoiceId, $amt, false);
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect('subscribers.php');
    }

    $action = post('action');

    if ($action === 'create') {
        $name = normalize_subscriber_name((string) post('name', ''));
        $phone = normalize_phone((string) post('phone', ''));
        $address = post('address');
        $notes = post('notes');
        $planId = (int) post('plan_id', '0');
        $addDebts = post('add_debts') === '1' && user_can_edit_debts();
        $sendDebtWa = post('send_debt_whatsapp') === '1';

        if ($name === '' || $phone === '') {
            flash('error', 'الاسم والهاتف مطلوبان');
            redirect('subscribers.php?add=1');
        }
        if ($planId <= 0) {
            flash('error', 'اختر نوع الاشتراك');
            redirect('subscribers.php?add=1');
        }
        $planChk = $pdo->prepare('SELECT id, name FROM service_plans WHERE id = :id AND is_active = 1');
        $planChk->execute(array(':id' => $planId));
        $planRow = $planChk->fetch();
        if (!$planRow) {
            flash('error', 'نوع الاشتراك غير موجود');
            redirect('subscribers.php?add=1');
        }
        if (subscriber_name_taken($pdo, $name)) {
            flash('error', 'الاسم مكرر — اختر اسماً مختلفاً');
            redirect('subscribers.php?add=1');
        }

        $newId = 0;
        try {
            ensure_preferred_plan_column($pdo);
            ensure_subscriber_agent_column($pdo);
            $agentId = 0;
            if (is_agent_user()) {
                $me = current_admin();
                $agentId = $me ? (int) $me['id'] : 0;
            } else {
                $agentId = (int) post('agent_user_id', '0');
                if ($agentId <= 0) {
                    $agentId = default_admin_user_id($pdo);
                }
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO subscribers (name, phone, address, notes, preferred_plan_id, agent_user_id)
                 VALUES (:name, :phone, :address, :notes, :plan_id, :agent_id)'
            );
            $stmt->execute(array(
                ':name' => $name,
                ':phone' => $phone,
                ':address' => ($address !== '' && $address !== null) ? $address : null,
                ':notes' => ($notes !== '' && $notes !== null) ? $notes : null,
                ':plan_id' => $planId,
                ':agent_id' => $agentId > 0 ? $agentId : null,
            ));
            $newId = (int) $pdo->lastInsertId();
            $debtInfo = array('count' => 0, 'sum' => 0.0);
            if ($addDebts) {
                $debtInfo = insert_opening_debts($pdo, $newId, $_POST);
            }
            if (function_exists('activity_log')) {
                activity_log(
                    $pdo,
                    $newId,
                    'subscriber',
                    $newId,
                    'create',
                    'إضافة مشترك جديد — ' . $name,
                    'الهاتف: ' . $phone . "\nنوع الاشتراك: " . $planRow['name']
                );
            }
            $pdo->commit();

            if ($addDebts && $sendDebtWa && (int) $debtInfo['count'] > 0) {
                $debtTotal = subscriber_unpaid_total($pdo, $newId);
                $row = array(
                    'name' => $name,
                    'phone' => $phone,
                    'month_label' => date('Y-m'),
                    'amount' => (float) $debtInfo['sum'],
                    'debt_total' => $debtTotal,
                    'notes' => '',
                );
                $msg = debt_created_message($row, $config);
                $result = whatsapp_send($config, $phone, $msg, 'debt_created');
                log_message($pdo, $newId, $result);
                if (!empty($result['success'])) {
                    flash('success', 'تم إضافة المشترك مع دين وإرسال واتساب');
                } else {
                    flash('info', 'تم إضافة المشترك مع دين — ' . whatsapp_fail_user_message($result));
                }
            } elseif ((int) $debtInfo['count'] > 0) {
                flash('success', 'تم إضافة المشترك مع ' . (int) $debtInfo['count'] . ' دين');
            } else {
                flash('success', 'تم إضافة المشترك — الباقة: ' . $planRow['name']);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = 'تعذر الإضافة. تحقق من البيانات وحاول مرة أخرى.';
            if (stripos($e->getMessage(), 'uq_name') !== false || (int) $e->getCode() === 23000) {
                $msg = 'الاسم مكرر — اختر اسماً مختلفاً';
            }
            flash('error', $msg);
            redirect('subscribers.php?add=1');
        }
        redirect('subscribers.php?focus=' . (int) $newId . '&per_page=all');
    }

    if ($action === 'bulk_activate') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();
        $payMode = post('pay_mode') === 'credit' ? 'credit' : 'cash';
        $sendWa = post('send_whatsapp') === '1';
        $cleanIds = array();
        foreach ($ids as $rawId) {
            $sid = (int) $rawId;
            if ($sid > 0) {
                $cleanIds[$sid] = $sid;
            }
        }
        $cleanIds = array_values($cleanIds);
        if (!$cleanIds) {
            flash('error', 'حدد مشتركاً واحداً على الأقل');
            redirect('subscribers.php');
        }
        $okN = 0;
        $failN = 0;
        $failNames = array();
        foreach ($cleanIds as $sid) {
            list($ok, $msg) = activate_one_subscriber($pdo, $config, $sid, array(
                'plan_id' => 0,
                'pay_mode' => $payMode,
                'send_whatsapp' => $sendWa,
                'send_old_debts' => false,
                'carry_days' => true,
            ));
            if ($ok) {
                $okN++;
            } else {
                $failN++;
                $nm = $pdo->prepare('SELECT name FROM subscribers WHERE id = :id');
                $nm->execute(array(':id' => $sid));
                $failNames[] = (string) $nm->fetchColumn() . ' (' . $msg . ')';
            }
        }
        $modeLabel = $payMode === 'credit' ? 'آجل' : 'نقد';
        $note = 'تفعيل جماعي (' . $modeLabel . '): نجح ' . $okN;
        if ($failN > 0) {
            $note .= ' / فشل ' . $failN;
            if ($failNames) {
                $note .= ' — ' . implode('؛ ', array_slice($failNames, 0, 5));
            }
            flash($okN > 0 ? 'info' : 'error', $note);
        } else {
            flash('success', $note);
        }
        redirect('subscribers.php');
    }

    if ($action === 'bulk_remind_debt') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();
        $okN = 0;
        $failN = 0;
        foreach ($ids as $rawId) {
            $sid = (int) $rawId;
            if ($sid <= 0) {
                continue;
            }
            $st = $pdo->prepare('SELECT id, name, phone FROM subscribers WHERE id = :id');
            $st->execute(array(':id' => $sid));
            $sub = $st->fetch();
            if (!$sub) {
                $failN++;
                continue;
            }
            $debt = subscriber_unpaid_total($pdo, $sid);
            if ($debt <= 0) {
                continue;
            }
            $msg = reminder_message(array(
                'name' => $sub['name'],
                'phone' => $sub['phone'],
                'month_label' => date('Y-m'),
                'amount' => $debt,
                'debt_total' => $debt,
                'notes' => '',
            ), $config);
            $result = whatsapp_send($config, $sub['phone'], $msg, 'reminder_debt');
            log_message($pdo, $sid, $result);
            if (!empty($result['success'])) {
                $okN++;
            } else {
                $failN++;
            }
        }
        flash($okN > 0 ? 'success' : 'error', 'تذكير دين: نجح ' . $okN . ($failN ? (' / فشل ' . $failN) : ''));
        redirect('subscribers.php');
    }

    if ($action === 'give_test') {
        $id = (int) post('id', '0');
        require_subscriber_access($pdo, $id);
        list($ok, $msg) = activate_subscriber_test($pdo, $config, $id);
        flash($ok ? 'success' : 'error', $msg);
        redirect('subscribers.php?focus=' . $id . '&per_page=all');
    }

    if ($action === 'delete') {
        $id = (int) post('id', '0');
        require_subscriber_access($pdo, $id);
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

    if ($action === 'inline_update') {
        header('Content-Type: application/json; charset=utf-8');
        $sid = (int) post('id', '0');
        $field = (string) post('field', '');
        $value = (string) post('value', '');
        if ($sid <= 0 || !user_can_access_subscriber($pdo, $sid)) {
            echo json_encode(array('ok' => false, 'error' => 'forbidden'));
            exit;
        }
        try {
            if ($field === 'phone') {
                $phone = normalize_phone($value);
                if ($phone === '') {
                    echo json_encode(array('ok' => false, 'error' => 'empty'));
                    exit;
                }
                $pdo->prepare('UPDATE subscribers SET phone = :p WHERE id = :id')
                    ->execute(array(':p' => $phone, ':id' => $sid));
                if (function_exists('activity_log')) {
                    activity_log($pdo, $sid, 'subscriber', $sid, 'update', 'تعديل الهاتف من الجدول', $phone);
                }
                echo json_encode(array('ok' => true, 'value' => format_phone_display($phone), 'raw' => $phone));
                exit;
            }
            if ($field === 'name') {
                $name = normalize_subscriber_name($value);
                if ($name === '') {
                    echo json_encode(array('ok' => false, 'error' => 'empty'));
                    exit;
                }
                if (subscriber_name_taken($pdo, $name, $sid)) {
                    echo json_encode(array('ok' => false, 'error' => 'taken'));
                    exit;
                }
                $pdo->prepare('UPDATE subscribers SET name = :n WHERE id = :id')
                    ->execute(array(':n' => $name, ':id' => $sid));
                if (function_exists('activity_log')) {
                    activity_log($pdo, $sid, 'subscriber', $sid, 'update', 'تعديل الاسم من الجدول', $name);
                }
                echo json_encode(array('ok' => true, 'value' => $name));
                exit;
            }
            echo json_encode(array('ok' => false, 'error' => 'field'));
        } catch (Exception $e) {
            echo json_encode(array('ok' => false, 'error' => 'db'));
        }
        exit;
    }

    if ($action === 'inline_update_debt') {
        header('Content-Type: application/json; charset=utf-8');
        $sid = (int) post('id', '0');
        $amount = (float) post('amount', '0');
        if ($sid <= 0 || !user_can_access_subscriber($pdo, $sid)) {
            echo json_encode(array('ok' => false, 'message' => 'forbidden'));
            exit;
        }
        if (!function_exists('apply_subscriber_unpaid_total_update')) {
            echo json_encode(array('ok' => false, 'message' => 'ملف الديون غير مكتمل'));
            exit;
        }
        list($ok, $msg, $total) = apply_subscriber_unpaid_total_update($pdo, $sid, $amount);
        $currency = isset($config['currency']) ? $config['currency'] : 'IQD';
        echo json_encode(array(
            'ok' => $ok,
            'message' => $msg,
            'debt' => $total,
            'debt_text' => function_exists('money_format_iqd') ? money_format_iqd($total, $currency) : (string) (int) $total,
        ));
        exit;
    }

    if ($action === 'assign_agent') {
        if (!can_manage_agents()) {
            flash('error', $lang === 'en' ? 'No permission' : 'ما عندك صلاحية');
            redirect('subscribers.php');
        }
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();
        $agentId = (int) post('agent_user_id', '0');
        if ($agentId <= 0) {
            $agentId = default_admin_user_id($pdo);
        }
        $n = 0;
        $st = $pdo->prepare('UPDATE subscribers SET agent_user_id = :a WHERE id = :id');
        foreach ($ids as $sid) {
            $sid = (int) $sid;
            if ($sid <= 0) {
                continue;
            }
            $st->execute(array(':a' => $agentId, ':id' => $sid));
            $n++;
        }
        flash('success', ($lang === 'en' ? 'Assigned: ' : 'تم الإسناد: ') . $n);
        redirect('subscribers.php' . ($agentId ? ('?agent=' . $agentId) : ''));
    }

    if ($action === 'update_days_left') {
        $sid = (int) post('id', '0');
        require_subscriber_access($pdo, $sid);
        $days = (int) post('days_left', '0');
        $planId = (int) post('plan_id', '0');
        list($ok, $msg) = apply_subscriber_days_left($pdo, $sid, $days, $planId);
        flash($ok ? 'success' : 'error', $msg);
        redirect('subscribers.php');
    }

    if ($action === 'remind_debt') {
        $id = (int) post('id', '0');
        require_subscriber_access($pdo, $id);
        $stmt = $pdo->prepare(
            'SELECT s.id, s.name, s.phone,
                (SELECT COALESCE(SUM(amount),0) FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt_total
             FROM subscribers s WHERE s.id = :id'
        );
        $stmt->execute(array(':id' => $id));
        $sub = $stmt->fetch();
        if (!$sub || (float) $sub['debt_total'] <= 0) {
            flash('error', 'لا يوجد دين لهذا المشترك');
            redirect('subscribers.php');
        }
        $row = array(
            'name' => $sub['name'],
            'phone' => $sub['phone'],
            'month_label' => date('Y-m'),
            'amount' => (float) $sub['debt_total'],
            'debt_total' => (float) $sub['debt_total'],
        );
        $msg = reminder_message($row, $config);
        $result = whatsapp_send($config, $sub['phone'], $msg, 'reminder_debt');
        log_message($pdo, $id, $result);
        if (!empty($result['success'])) {
            flash('success', 'تم إرسال تذكير الدين');
        } else {
            flash('error', whatsapp_fail_user_message($result));
        }
        redirect('subscribers.php');
    }

    if ($action === 'remind_days') {
        $id = (int) post('id', '0');
        $stmt = $pdo->prepare(
            'SELECT s.id, s.name, s.phone,
                (SELECT COALESCE(SUM(amount),0) FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt_total,
                (SELECT sub.service_name FROM subscriptions sub
                    WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
                    ORDER BY sub.id DESC LIMIT 1) AS active_service,
                (SELECT sub.start_date FROM subscriptions sub
                    WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
                    ORDER BY sub.id DESC LIMIT 1) AS active_start,
                (SELECT sub.end_date FROM subscriptions sub
                    WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
                    ORDER BY sub.id DESC LIMIT 1) AS active_end,
                (SELECT sub.start_date FROM subscriptions sub WHERE sub.subscriber_id = s.id ORDER BY sub.id DESC LIMIT 1) AS last_start,
                (SELECT sub.end_date FROM subscriptions sub WHERE sub.subscriber_id = s.id ORDER BY sub.id DESC LIMIT 1) AS last_end
             FROM subscribers s WHERE s.id = :id'
        );
        $stmt->execute(array(':id' => $id));
        $sub = $stmt->fetch();
        if (!$sub) {
            flash('error', 'المشترك غير موجود');
            redirect('subscribers.php');
        }
        $daysInfo = null;
        if (!empty($sub['active_start']) && !empty($sub['active_end'])) {
            $daysInfo = subscription_days_info($sub['active_start'], $sub['active_end']);
        } elseif (!empty($sub['last_start']) && !empty($sub['last_end'])) {
            $daysInfo = subscription_days_info($sub['last_start'], $sub['last_end']);
        }
        if (!$daysInfo) {
            flash('error', 'لا يوجد اشتراك لحساب الأيام');
            redirect('subscribers.php');
        }
        $body = days_left_message(array(
            'name' => $sub['name'],
            'days' => (int) $daysInfo['left'],
            'package' => !empty($sub['active_service']) ? $sub['active_service'] : '',
            'debt_total' => (float) $sub['debt_total'],
        ), $config);
        $result = whatsapp_send($config, $sub['phone'], $body, 'remind_days');
        log_message($pdo, $id, $result);
        if (!empty($result['success'])) {
            flash('success', $lang === 'en' ? 'Days reminder sent' : 'تم إرسال رسالة الأيام المتبقية');
        } else {
            flash('error', whatsapp_fail_user_message($result));
        }
        redirect('subscribers.php');
    }

    if ($action === 'remind_unpaid_overdue') {
        $id = (int) post('id', '0');
        $afterDays = unpaid_remind_after_days($config);
        $stmt = $pdo->prepare(
            'SELECT s.id, s.name, s.phone,
                (SELECT COALESCE(SUM(amount),0) FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt_total,
                (SELECT sub.service_name FROM subscriptions sub
                    WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
                    ORDER BY sub.id DESC LIMIT 1) AS active_service,
                (SELECT sub.start_date FROM subscriptions sub
                    WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
                    ORDER BY sub.id DESC LIMIT 1) AS active_start
             FROM subscribers s WHERE s.id = :id'
        );
        $stmt->execute(array(':id' => $id));
        $sub = $stmt->fetch();
        if (!$sub || empty($sub['active_start']) || (float) $sub['debt_total'] <= 0) {
            flash('error', $lang === 'en' ? 'Not eligible (need active + unpaid)' : 'غير مؤهل (لازم مفعّل وعليه دين)');
            redirect('subscribers.php');
        }
        $daysPassed = days_since_date($sub['active_start']);
        if ($daysPassed < $afterDays) {
            flash('error', ($lang === 'en' ? 'Too early. After ' : 'بعد ') . $afterDays . ($lang === 'en' ? ' days from activation' : ' يوم من التفعيل'));
            redirect('subscribers.php');
        }
        $body = unpaid_overdue_message(array(
            'name' => $sub['name'],
            'days_passed' => $daysPassed,
            'debt_total' => (float) $sub['debt_total'],
            'package' => !empty($sub['active_service']) ? $sub['active_service'] : '',
        ), $config);
        $result = whatsapp_send($config, $sub['phone'], $body, 'unpaid_overdue');
        log_message($pdo, $id, $result);
        if (!empty($result['success'])) {
            flash('success', $lang === 'en' ? 'Payment warning sent' : 'تم إرسال تنبيه التسديد');
        } else {
            flash('error', whatsapp_fail_user_message($result));
        }
        redirect('subscribers.php');
    }

    if ($action === 'pay_all') {
        // التحويل لصفحة الديون لاختيار الشهر/المبلغ جزئياً
        $id = (int) post('id', '0');
        redirect('debts.php?status=unpaid&subscriber_id=' . $id);
    }

    if ($action === 'retry_message') {
        $logId = (int) post('log_id', '0');
        $sid = (int) post('id', '0');
        list($ok, $msg) = retry_failed_message($pdo, $config, $logId, $sid);
        flash($ok ? 'success' : 'error', $msg);
        if ($sid > 0) {
            redirect('subscriber.php?id=' . $sid . '#messages');
        }
        redirect('subscribers.php');
    }

}

if (isset($_GET['export']) && $_GET['export'] === 'offline') {
    if (function_exists('export_offline_subscribers_full')) {
        export_offline_subscribers_full($pdo);
    }
    flash('error', $lang === 'en' ? 'Export is not available' : 'التصدير غير متوفر');
    redirect('subscribers.php');
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

function subscribers_search_where($q, &$params)
{
    $where = '1=1';
    $params = array();
    if ($q === '') {
        return $where;
    }
    $qDigits = preg_replace('/\D+/', '', $q);
    $qNorm = $qDigits !== '' ? normalize_phone($qDigits) : '';
    $where .= ' AND (
        s.name LIKE :q
        OR s.phone LIKE :q
        OR s.phone LIKE :qDigits
        OR s.phone LIKE :qNorm
        OR s.phone LIKE :qTail
    )';
    $params[':q'] = '%' . $q . '%';
    $params[':qDigits'] = $qDigits !== '' ? ('%' . $qDigits . '%') : '%__no_digit__%';
    $params[':qNorm'] = ($qNorm !== '' && $qNorm !== $qDigits) ? ('%' . $qNorm . '%') : '%__no_norm__%';
    $params[':qTail'] = (strlen($qDigits) >= 9) ? ('%' . substr($qDigits, -9) . '%') : '%__no_tail__%';
    return $where;
}

function subscribers_sub_filter_sql($subFilter)
{
    if ($subFilter === 'active') {
        return ' AND EXISTS (
            SELECT 1 FROM subscriptions sub
            WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
        )';
    }
    if ($subFilter === 'expired') {
        return ' AND NOT EXISTS (
            SELECT 1 FROM subscriptions sub
            WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
          )';
    }
    if ($subFilter === 'today') {
        return ' AND EXISTS (
            SELECT 1 FROM subscriptions sub
            WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date = CURDATE()
        )';
    }
    if ($subFilter === 'soon') {
        return ' AND EXISTS (
            SELECT 1 FROM subscriptions sub
            WHERE sub.subscriber_id = s.id AND sub.status = "active"
              AND sub.end_date > CURDATE()
              AND sub.end_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
        )';
    }
    if ($subFilter === 'debt') {
        return ' AND EXISTS (
            SELECT 1 FROM invoices i
            WHERE i.subscriber_id = s.id AND i.status = "unpaid" AND i.amount > 0
        )';
    }
    return '';
}

// بحث مباشر يستبدل صفوف الجدول (بدون نافذة منبثقة)
if (isset($_GET['live']) && $_GET['live'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $paramsLive = array();
    $whereLive = subscribers_search_where($q, $paramsLive);
    $whereLive .= subscriber_agent_scope_sql('s');
    $subLive = isset($_GET['sub']) ? (string) $_GET['sub'] : '';
    $whereLive .= subscribers_sub_filter_sql($subLive);
    $agentLive = isset($_GET['agent']) ? (int) $_GET['agent'] : 0;
    if ($agentLive > 0 && can_manage_agents()) {
        $whereLive .= ' AND s.agent_user_id = ' . $agentLive;
    }
    $sqlLive = subscribers_list_select_sql() . '
 FROM subscribers s
 WHERE ' . $whereLive . '
 ORDER BY s.name ASC
 LIMIT 80';
    $stLive = $pdo->prepare($sqlLive);
    $stLive->execute($paramsLive);
    $liveRows = $stLive->fetchAll();
    $html = '';
    $nLive = 1;
    foreach ($liveRows as $liveRow) {
        $html .= render_subscriber_table_row($liveRow, $nLive++, $config, $lang);
    }
    if ($html === '') {
        $html = '<tr><td colspan="10">' . e($lang === 'en' ? 'No matches' : 'ماكو نتيجة') . '</td></tr>';
    }
    echo json_encode(array(
        'html' => $html,
        'count' => count($liveRows),
        'capped' => count($liveRows) >= 80,
    ));
    exit;
}

function subscribers_list_select_sql()
{
    return 'SELECT s.*,
    (SELECT COALESCE(SUM(amount),0) FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt,
    (SELECT sp.name FROM service_plans sp WHERE sp.id = s.preferred_plan_id LIMIT 1) AS preferred_plan_name,
    (SELECT sub.service_name FROM subscriptions sub
        WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
        ORDER BY sub.id DESC LIMIT 1) AS active_service,
    (SELECT sub.start_date FROM subscriptions sub
        WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
        ORDER BY sub.id DESC LIMIT 1) AS active_start,
    (SELECT sub.end_date FROM subscriptions sub
        WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
        ORDER BY sub.id DESC LIMIT 1) AS active_end,
    (SELECT sub.monthly_price FROM subscriptions sub
        WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
        ORDER BY sub.id DESC LIMIT 1) AS active_price,
    (SELECT sub.start_date FROM subscriptions sub WHERE sub.subscriber_id = s.id ORDER BY sub.id DESC LIMIT 1) AS last_start,
    (SELECT sub.end_date FROM subscriptions sub WHERE sub.subscriber_id = s.id ORDER BY sub.id DESC LIMIT 1) AS last_end,
    (SELECT COUNT(*) FROM subscriptions sub WHERE sub.subscriber_id = s.id) AS sub_count,
    (SELECT m.success FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_ok,
    (SELECT m.message_type FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_type,
    (SELECT m.body FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_body,
    (SELECT m.response_json FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_response,
    (SELECT m.created_at FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_at,
    (SELECT m.id FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_id,
    (SELECT GROUP_CONCAT(DISTINCT i.month_label ORDER BY i.month_label SEPARATOR \',\')
        FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt_months';
}

function subscriber_msg_is_no_whatsapp($response)
{
    $response = (string) $response;
    if ($response === '') {
        return false;
    }
    return (
        stripos($response, 'not on WhatsApp') !== false
        || stripos($response, 'no_whatsapp') !== false
        || strpos($response, 'لا يتوفر واتساب') !== false
    );
}

function render_subscriber_month_cell($row, $lang)
{
    $detailUrl = 'subscriber.php?id=' . (int) $row['id'] . '#debts';
    $viewTitle = $lang === 'en' ? 'View details' : 'عرض';
    $monthLabels = array();
    if (!empty($row['debt_months'])) {
        $monthsParts = explode(',', $row['debt_months']);
        foreach ($monthsParts as $ymPart) {
            $ymPart = trim($ymPart);
            if ($ymPart === '') {
                continue;
            }
            // دائماً مختصر: ش7 مو ش7 2026
            $monthLabels[] = month_short_label($ymPart, true);
        }
    } elseif (!empty($row['active_start']) && !empty($row['active_end'])) {
        $monthLabels[] = month_short_label(date('Y-m', strtotime($row['active_start'])), true);
    }
    // إزالة التكرار مع الحفاظ على الترتيب
    $uniq = array();
    foreach ($monthLabels as $lab) {
        if ($lab === '' || isset($uniq[$lab])) {
            continue;
        }
        $uniq[$lab] = true;
    }
    $monthLabels = array_keys($uniq);
    if (!$monthLabels) {
        return '<td class="col-month"><a class="month-link month-empty" href="' . e($detailUrl) . '" title="' . e($viewTitle) . '">-</a></td>';
    }
    $title = $viewTitle . ' — ' . implode(' · ', $monthLabels);
    $first = $monthLabels[0];
    $extra = count($monthLabels) - 1;
    $html = '<td class="col-month">';
    $html .= '<a class="month-link" href="' . e($detailUrl) . '" title="' . e($title) . '">';
    $html .= e($first);
    if ($extra > 0) {
        $html .= '<span class="month-more">+' . (int) $extra . '</span>';
    }
    $html .= '</a></td>';
    return $html;
}

function render_subscriber_table_row($row, $n, $config, $lang)
{
    $debt = (float) $row['debt'];
    $daysInfo = null;
    if (!empty($row['active_start']) && !empty($row['active_end'])) {
        $daysInfo = subscription_days_info($row['active_start'], $row['active_end']);
    } elseif (!empty($row['last_start']) && !empty($row['last_end'])) {
        $daysInfo = subscription_days_info($row['last_start'], $row['last_end']);
    }
    $searchText = strtolower($row['name'] . ' ' . format_phone_display($row['phone']) . ' ' . $row['phone']);
    $hasMsg = isset($row['last_msg_at']) && $row['last_msg_at'] !== null && $row['last_msg_at'] !== '';
    $msgOk = $hasMsg && !empty($row['last_msg_ok']);
    $msgResp = isset($row['last_msg_response']) ? $row['last_msg_response'] : '';
    $noWa = $hasMsg && !$msgOk && subscriber_msg_is_no_whatsapp($msgResp);
    $msgShort = $hasMsg
        ? message_short_summary($row['last_msg_type'], $row['last_msg_body'], $msgOk)
        : ($lang === 'en' ? 'No message sent' : 'لم تُرسل رسالة');
    if ($noWa) {
        $msgShort = 'لا يتوفر واتساب لدى المشترك';
    }
    $hasActive = !empty($row['active_end']);
    $hadSub = (int) $row['sub_count'] > 0;
    if ($hasActive) {
        $rowClass = 'row-status-active';
        $statusTitle = $lang === 'en' ? 'Active' : 'فعال';
        $statusKey = 'active';
    } elseif ($hadSub) {
        $rowClass = 'row-status-expired';
        $statusTitle = $lang === 'en' ? 'Expired' : 'منتهي';
        $statusKey = 'expired';
    } else {
        $rowClass = 'row-status-left';
        $statusTitle = $lang === 'en' ? 'Abandoned' : 'متروك';
        $statusKey = 'left';
    }

    $pkgLabel = !empty($row['active_service'])
        ? $row['active_service']
        : (!empty($row['preferred_plan_name']) ? $row['preferred_plan_name'] : '-');

    $msgFail = ($hasMsg && !$msgOk && !$noWa) ? '1' : '0';
    $logId = (!empty($row['last_msg_id'])) ? (int) $row['last_msg_id'] : 0;
    $hasDays = $daysInfo ? '1' : '0';

    $html = '<tr class="' . e($rowClass) . '"'
        . ' data-search="' . e($searchText) . '"'
        . ' data-id="' . (int) $row['id'] . '"'
        . ' data-name="' . e($row['name']) . '"'
        . ' data-debt="' . ($debt > 0 ? '1' : '0') . '"'
        . ' data-active="' . ($hasActive ? '1' : '0') . '"'
        . ' data-msg-fail="' . $msgFail . '"'
        . ' data-log-id="' . $logId . '"'
        . ' data-has-days="' . $hasDays . '"'
        . ' id="sub-row-' . (int) $row['id'] . '">';
    $html .= '<td class="sub-check-cell"><label class="sub-check-lab"><input type="checkbox" class="sub-check" value="' . (int) $row['id'] . '"></label></td>';
    $html .= '<td class="status-cell" title="' . e($statusTitle) . '"><span class="status-sq status-' . e($statusKey) . '" aria-label="' . e($statusTitle) . '"></span></td>';
    $html .= '<td class="col-num">' . (int) $n . '</td>';
    $html .= '<td class="col-name"><a class="sub-name cell-edit" href="subscriber.php?id=' . (int) $row['id'] . '" data-edit="name" data-id="' . (int) $row['id'] . '" data-value="' . e($row['name']) . '">' . e($row['name']) . '</a>';
    $html .= rental_badge_html($row);
    $html .= '</td>';
    $phoneDisp = format_phone_display($row['phone']);
    $html .= '<td class="col-phone"><span class="cell-edit phone-edit" tabindex="0" data-edit="phone" data-id="' . (int) $row['id'] . '" data-value="' . e($row['phone']) . '" title="' . e($lang === 'en' ? 'Click to edit' : 'اضغط للتعديل') . '">' . e($phoneDisp) . '</span></td>';
    $html .= '<td class="col-pkg">' . e($pkgLabel);
    if (!empty($row['active_service']) && isset($row['active_price']) && (float) $row['active_price'] <= 0) {
        $html .= ' <span class="badge" style="background:#f59e0b;color:#111;font-size:11px;padding:1px 6px">' . e($lang === 'en' ? 'TEST' : 'تست') . '</span>';
    }
    $html .= '</td>';
    $html .= '<td class="col-days">';
    if ($daysInfo) {
        $daysLeftVal = (int) $daysInfo['left'];
        $daysCls = $daysLeftVal < 0 ? ' days-neg' : '';
        $hasSubDays = (!empty($row['active_end']) || !empty($row['last_end'])) ? '1' : '0';
        $html .= '<button type="button" class="days-edit-btn" data-id="' . (int) $row['id'] . '" data-days="' . $daysLeftVal . '" data-has-sub="' . $hasSubDays . '" title="' . e($lang === 'en' ? 'Edit days left' : 'تعديل الأيام المتبقية') . '">';
        $html .= '<span class="days-num' . $daysCls . '">' . $daysLeftVal . '</span></button>';
    } else {
        $html .= '<button type="button" class="days-edit-btn days-empty" data-id="' . (int) $row['id'] . '" data-days="0" data-has-sub="0" title="' . e($lang === 'en' ? 'Set days from ledger' : 'تسجيل الأيام من الدفتر') . '">—</button>';
    }
    $html .= '</td>';
    $html .= '<td class="debt-cell col-debt">';
    $canEditDebt = function_exists('user_can_edit_debts') && user_can_edit_debts();
    if (function_exists('debt_amount_cell_html')) {
        $html .= debt_amount_cell_html($debt, $config, $lang, array(
            'can_edit' => $canEditDebt,
            'subscriber_id' => (int) $row['id'],
            'href' => $debt > 0 ? ('debts.php?status=unpaid&subscriber_id=' . (int) $row['id']) : '',
        ));
    } elseif ($debt > 0) {
        $html .= '<span class="debt-amt debt-due">' . e(money_format_iqd($debt, $config['currency'])) . '</span>';
    } else {
        $html .= '<span class="debt-amt debt-zero">' . e(money_format_iqd(0, $config['currency'])) . '</span>';
    }
    $html .= '</td>';
    $html .= render_subscriber_month_cell($row, $lang);
    $html .= '<td class="msg-status-cell col-msg" title="' . e($msgShort) . '"><span class="msg-status-row">';
    if (!$hasMsg) {
        $html .= '<span class="dot-msg off"></span>';
    } elseif ($msgOk) {
        $html .= '<span class="dot-msg ok"></span>';
    } elseif ($noWa) {
        $html .= '<span class="dot-msg fail"></span>';
        $html .= '<span class="msg-x" title="' . e('لا يتوفر واتساب لدى المشترك') . '">✕</span>';
    } else {
        $html .= '<span class="dot-msg fail"></span>';
    }
    $html .= '</span></td>';
    $html .= '</tr>';
    return $html;
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPageRaw = isset($_GET['per_page']) ? $_GET['per_page'] : '20';
$showAll = ($perPageRaw === 'all');
$perPage = $showAll ? 0 : max(1, (int) $perPageRaw);

$sortKey = isset($_GET['sort']) ? (string) $_GET['sort'] : 'name';
$sortDir = isset($_GET['dir']) && strtolower((string) $_GET['dir']) === 'desc' ? 'desc' : 'asc';
$sortMap = array(
    'id' => 's.id',
    'name' => 's.name',
    'phone' => 's.phone',
    'package' => 'active_service',
    'month' => 'active_start',
    'days' => 'active_end',
    'debt' => 'debt',
    'msg' => 'last_msg_at',
);
if (!isset($sortMap[$sortKey])) {
    $sortKey = 'name';
}
$orderSql = $sortMap[$sortKey] . ' ' . strtoupper($sortDir);
// قيم فارغة تنزل بآخر القائمة عند الترتيب
if ($sortKey === 'package' || $sortKey === 'month' || $sortKey === 'days' || $sortKey === 'msg') {
    $orderSql = '(' . $sortMap[$sortKey] . ' IS NULL) ASC, ' . $orderSql;
}
if ($sortKey === 'name') {
    $orderSql = 's.name ' . strtoupper($sortDir) . ', s.id ASC';
}

$where = subscribers_search_where($q, $params);
$where .= subscriber_agent_scope_sql('s');
$subFilter = isset($_GET['sub']) ? (string) $_GET['sub'] : '';
$subSql = subscribers_sub_filter_sql($subFilter);
if ($subSql === '') {
    $subFilter = '';
} else {
    $where .= $subSql;
}
$agentFilter = isset($_GET['agent']) ? (int) $_GET['agent'] : 0;
if (is_agent_user()) {
    $agentFilter = 0;
} elseif ($agentFilter > 0 && can_manage_agents()) {
    $where .= ' AND s.agent_user_id = ' . $agentFilter;
} else {
    $agentFilter = 0;
}

$countSql = 'SELECT COUNT(*) FROM subscribers s WHERE ' . $where;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();

$totalAllSql = 'SELECT COUNT(*) FROM subscribers s WHERE 1=1' . subscriber_agent_scope_sql('s');
$totalAll = (int) $pdo->query($totalAllSql)->fetchColumn();
$showAdd = isset($_GET['add']) && $_GET['add'] === '1';
$servicePlans = $pdo->query(
    'SELECT id, name, monthly_price FROM service_plans WHERE is_active = 1 ORDER BY sort_order ASC, monthly_price ASC, id ASC'
)->fetchAll();
$agentUsers = can_manage_agents() ? list_agent_users($pdo, true) : array();
$adminUserId = default_admin_user_id($pdo);
if ($adminUserId > 0 && can_manage_agents()) {
    $adminRow = get_admin_user($pdo, $adminUserId);
    if ($adminRow) {
        array_unshift($agentUsers, $adminRow);
    }
}
$focusId = isset($_GET['focus']) ? (int) $_GET['focus'] : 0;
if ($focusId > 0 && $perPageRaw !== 'all') {
    // حتى يظهر المشترك المضاف ونقدر نسكرول عليه
    $showAll = true;
    $perPageRaw = 'all';
}

if ($showAll) {
    $perPage = $totalRows > 0 ? $totalRows : 1;
    $totalPages = 1;
    $page = 1;
    $offset = 0;
} else {
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
}

$sql = subscribers_list_select_sql() . '
 FROM subscribers s
 WHERE ' . $where . '
 ORDER BY ' . $orderSql;

if (!$showAll) {
    $sql .= ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$plansForDays = $pdo->query(
    'SELECT id, name, monthly_price FROM service_plans WHERE is_active = 1 ORDER BY sort_order ASC, monthly_price ASC, id ASC'
)->fetchAll();

$pageTitle = $showAdd
    ? ($lang === 'en' ? 'Add subscriber' : 'إضافة مشترك')
    : t('offline_data');

render_header($pageTitle, 'subscribers', $showAdd ? '' : ($lang === 'en' ? 'Local ledger — debts stay here' : 'الدفتر المحلي — الديون تبقى هنا'));

function subs_sort_link($key, $label, $currentKey, $currentDir, $q, $perPageRaw)
{
    if ($currentKey === $key) {
        $nextDir = ($currentDir === 'asc') ? 'desc' : 'asc';
    } elseif ($key === 'debt' || $key === 'days' || $key === 'id') {
        $nextDir = 'desc';
    } else {
        $nextDir = 'asc';
    }
    $qs = array('sort=' . urlencode($key), 'dir=' . $nextDir);
    if ($q !== '') {
        $qs[] = 'q=' . urlencode($q);
    }
    if ($perPageRaw !== '20') {
        $qs[] = 'per_page=' . urlencode($perPageRaw);
    }
    $arrow = '';
    if ($currentKey === $key) {
        $arrow = $currentDir === 'asc' ? ' ↑' : ' ↓';
    }
    return '<a class="th-sort' . ($currentKey === $key ? ' on' : '') . '" href="?' . implode('&', $qs) . '">' . e($label) . $arrow . '</a>';
}
?>
<style>
#subsTable.table-compact th,
#subsTable.table-compact td {
  padding: 7px 8px !important;
  font-size: 14px !important;
  line-height: 1.428 !important;
  height: 38px !important;
  white-space: nowrap;
  vertical-align: middle !important;
}
#subsTable.table-compact th { height: 32px !important; font-size: 13px !important; }
#subsTable .th-sort {
  color: inherit;
  text-decoration: none;
  font-weight: 800;
  display: inline-flex;
  align-items: center;
  gap: 2px;
}
#subsTable .th-sort.on { color: #1c4fd8; }
#subsTable .th-sort:hover { color: #2563eb; }
#subsTable .row-actions {
  display: inline-flex !important;
  flex-wrap: nowrap !important;
  gap: 8px !important;
  align-items: center;
}
#subsTable .link-act {
  border: 0;
  background: transparent;
  padding: 0;
  margin: 0;
  font: inherit;
  font-size: 12px;
  font-weight: 800;
  cursor: pointer;
  color: #475569;
  text-decoration: none;
  white-space: nowrap;
}
#subsTable .link-act.act-blue { color: #2563eb; }
#subsTable .link-act.act-orange { color: #c27800; }
#subsTable .link-act.act-red { color: #dc2626; }
#subsTable .days-num { font-weight: 800; font-variant-numeric: tabular-nums; font-size: 13px; }
#subsTable .days-num.days-neg,
#subsTable .days-num.neg { color: #c62828; }
.days-edit-card .value.days-neg { color: #ffd6d6; }
#subsTable .debt-pill,
#subsTable .debt-amt,
#subsTable .debt-ok,
#subsTable .debt-due,
#subsTable .debt-zero {
  display: inline !important;
  padding: 0 !important;
  margin: 0 !important;
  border: 0 !important;
  border-radius: 0 !important;
  background: transparent !important;
  box-shadow: none !important;
  outline: 0 !important;
  font-size: 14px !important;
  font-weight: 700 !important;
  line-height: 1.3 !important;
  white-space: nowrap !important;
}
#subsTable .debt-amt.debt-due,
#subsTable .debt-due,
#subsTable .debt-pill {
  color: #b86a00 !important;
}
#subsTable .debt-amt.debt-zero,
#subsTable .debt-zero,
#subsTable .debt-ok {
  color: #15803d !important;
}
#subsTable .debt-cell {
  background-clip: padding-box;
}
#subsTable .debt-edit-btn {
  cursor: pointer;
  background: transparent;
  border: 0;
  font: inherit;
  font-weight: 700;
  padding: 0;
}
#subsTable .debt-edit-btn:hover { text-decoration: underline; }
#subsTable .debt-edit-btn.editing {
  border: 1px solid #94a3b8 !important;
  background: #fff !important;
  padding: 2px 6px !important;
  min-width: 4.5rem;
  border-radius: 4px;
  outline: none;
  text-decoration: none !important;
}
#subsTable {
  table-layout: auto;
  width: 100%;
  border-collapse: collapse;
}
#subsTable th,
#subsTable td {
  border-radius: 0 !important;
  vertical-align: middle !important;
}
#subsTable .col-name {
  white-space: nowrap;
}
#subsTable .col-pkg,
#subsTable .col-month,
#subsTable .col-days,
#subsTable .col-debt,
#subsTable .col-msg,
#subsTable .col-phone,
#subsTable .col-num,
#subsTable .status-cell,
#subsTable .sub-check-cell {
  white-space: nowrap;
}
#subsTable td.col-month,
#subsTable th.col-month {
  white-space: nowrap !important;
  width: auto !important;
  min-width: 3.2rem;
  max-width: none !important;
  overflow: visible !important;
  vertical-align: middle !important;
  height: 38px !important;
  line-height: 1.3 !important;
}
#subsTable .month-link {
  display: inline;
  font-size: 12px;
  font-weight: 700;
  color: #2563eb;
  text-decoration: none;
  white-space: nowrap;
  line-height: 1.3;
  border-bottom: 1px dotted rgba(37, 99, 235, 0.45);
}
#subsTable .month-link:hover {
  color: #1d4ed8;
  border-bottom-color: #1d4ed8;
}
#subsTable .month-link.month-empty {
  color: #94a3b8;
  font-weight: 600;
  border-bottom: 0;
}
#subsTable .month-more {
  margin-inline-start: 2px;
  font-size: 11px;
  font-weight: 800;
  color: #64748b;
}
#subsTable .phone-edit,
#subsTable .cell-edit.editing {
  cursor: text;
  outline: none;
}
#subsTable .phone-edit:hover {
  background: rgba(37, 99, 235, 0.08);
  border-radius: 4px;
}
#subsTable .cell-edit.editing {
  display: inline-block;
  min-width: 7rem;
  padding: 2px 6px;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}
#subsTable.table-compact th,
#subsTable.table-compact td {
  border-radius: 0 !important;
}
#subsTable .dot-msg {
  display: inline-block;
  width: 11px;
  height: 11px;
  border-radius: 3px;
  vertical-align: middle;
}
#subsTable .dot-msg.ok { background: #34c759; }
#subsTable .dot-msg.fail { background: #ff9f0a; }
#subsTable .dot-msg.off { background: #cbd5e1; }
#subsTable .msg-status-row {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
#subsTable .msg-retry-btn {
  width: 22px;
  height: 22px;
  border-radius: 999px;
  border: 1px solid rgba(255, 159, 10, 0.45);
  background: rgba(255, 159, 10, 0.14);
  color: #b86a00;
  font-size: 14px;
  font-weight: 800;
  line-height: 1;
  cursor: pointer;
  padding: 0;
}
#subsTable .msg-retry-btn:hover { background: rgba(255, 159, 10, 0.28); }
#subsTable .msg-x {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 999px;
  background: rgba(255, 59, 48, 0.12);
  color: #c62828;
  font-size: 11px;
  font-weight: 900;
  line-height: 1;
}
#subsTable .days-edit-btn {
  border: 0 !important;
  background: transparent !important;
  border-radius: 0 !important;
  padding: 0 2px !important;
  min-width: 0 !important;
  box-shadow: none !important;
}
#subsTable tbody tr.row-status-active:nth-child(even) td {
  background: #f1f5f9;
}
#subsTable tbody tr.row-status-active td {
  background: #ffffff;
}
#subsTable tbody tr.row-status-expired td {
  background: #f8fafc !important;
}
#subsTable tbody tr.row-status-left td {
  background: #f1f5f9 !important;
}
#subsTable tbody tr.row-status-active:hover td {
  background: #e2e8f0 !important;
}
#subsTable tbody tr.row-status-expired:hover td {
  background: #e2e8f0 !important;
}
#subsTable tbody tr.row-status-left:hover td {
  background: #e2e8f0 !important;
}
.subs-tool-icons {
  display: inline-flex;
  align-items: stretch;
  border: 1px solid rgba(28, 36, 48, 0.14);
  border-radius: 8px;
  overflow: hidden;
  background: #3a424d;
}
.subs-tool-icons .tool-ico {
  appearance: none;
  border: 0;
  margin: 0;
  width: 36px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  color: #d7dde6;
  cursor: pointer;
  border-inline-start: 1px solid rgba(255,255,255,0.12);
}
.subs-tool-icons .tool-ico:first-child {
  border-inline-start: 0;
}
.subs-tool-icons .tool-ico:hover,
.subs-tool-icons .tool-ico.is-on {
  background: rgba(255,255,255,0.10);
  color: #fff;
}
.ops-item.is-on {
  background: rgba(37, 99, 235, 0.10);
  font-weight: 800;
}
#subsTable .edit-icon {
  width: 22px !important;
  height: 22px !important;
  font-size: 11px !important;
}
#subsTable .msg-short,
#subsTable .msg-full-link,
#subsTable .badge { display: none !important; }
#subsTable .inline-form { display: inline !important; margin: 0 !important; }
</style>
<?php if ($showAdd): ?>
<div class="panel">
    <div class="actions" style="margin-top:0;margin-bottom:10px">
        <a class="btn ghost" href="subscribers.php"><?php echo e($lang === 'en' ? 'Back' : 'رجوع'); ?></a>
    </div>
    <div id="addBox" class="collapse-box">
        <h2><?php echo e(t('add_subscriber')); ?></h2>
        <form method="post" id="addSubForm">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-grid">
                <div>
                    <label><?php echo e(t('name')); ?></label>
                    <input name="name" required autofocus>
                </div>
                <div>
                    <label><?php echo e(t('phone')); ?></label>
                    <div class="phone-pick-row">
                        <input id="subPhone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="07XXXXXXXXX" required>
                        <button type="button" class="btn secondary" id="pickContactBtn" title="<?php echo e(t('pick_contact')); ?>"><?php echo e(t('pick_contact')); ?></button>
                    </div>
                </div>
                <div>
                    <label><?php echo e(t('sub_type')); ?></label>
                    <select name="plan_id" required>
                        <option value=""><?php echo e($lang === 'en' ? 'Choose package…' : 'اختر نوع الاشتراك…'); ?></option>
                        <?php foreach ($servicePlans as $sp): ?>
                            <option value="<?php echo (int) $sp['id']; ?>">
                                <?php echo e($sp['name'] . ' — ' . money_format_iqd($sp['monthly_price'], $config['currency'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo e(t('address')); ?> <span class="meta">(<?php echo e($lang === 'en' ? 'optional' : 'اختياري'); ?>)</span></label>
                    <input name="address" placeholder="<?php echo e($lang === 'en' ? 'Optional' : 'اختياري'); ?>">
                </div>
                <div>
                    <label><?php echo e(t('notes')); ?></label>
                    <input name="notes" placeholder="<?php echo e($lang === 'en' ? 'Optional' : 'اختياري'); ?>">
                </div>
                <?php if (can_manage_agents() && !empty($agentUsers)): ?>
                <div>
                    <label><?php echo e($lang === 'en' ? 'Belongs to' : 'تابع إلى'); ?></label>
                    <select name="agent_user_id">
                        <?php foreach ($agentUsers as $au): ?>
                            <option value="<?php echo (int) $au['id']; ?>" <?php echo ((int) $au['id'] === (int) $adminUserId) ? 'selected' : ''; ?>>
                                <?php echo e($au['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <?php if (user_can_edit_debts()): ?>
            <div class="settings-block" style="margin-top:14px">
                <label class="toggle" for="addDebtsToggle">
                    <input type="checkbox" name="add_debts" value="1" id="addDebtsToggle">
                    <span class="toggle-ui" aria-hidden="true"></span>
                    <span class="toggle-text"><?php echo e($lang === 'en' ? 'Add other debts' : 'إضافة ديون أخرى'); ?></span>
                </label>
            </div>

            <div id="extraDebtsBox" class="debt-entry-box" style="display:none;margin-top:12px">
                <div class="actions" style="margin-top:0;margin-bottom:8px">
                    <strong><?php echo e($lang === 'en' ? 'Add other debts' : 'إضافة ديون أخرى'); ?></strong>
                    <button type="button" class="plus-btn" id="addDebtRowBtn" title="<?php echo e(t('add_debt_line')); ?>">+</button>
                </div>
                <div id="debtRows">
                    <div class="debt-entry-row">
                        <div class="form-grid">
                            <div>
                                <label><?php echo e($lang === 'en' ? 'Type' : 'النوع'); ?></label>
                                <select name="debt_kind[]">
                                    <option value="month"><?php echo e(t('debt_type_month')); ?></option>
                                    <option value="item"><?php echo e(t('debt_type_item')); ?></option>
                                </select>
                            </div>
                            <div>
                                <label><?php echo e($lang === 'en' ? 'Month' : 'الشهر'); ?></label>
                                <?php echo month_ym_picker_html('debt_month[]', date('Y-m')); ?>
                            </div>
                            <div>
                                <label><?php echo e($lang === 'en' ? 'Amount' : 'المبلغ'); ?></label>
                                <input type="number" name="debt_amount[]" min="0" step="1" autocomplete="off">
                            </div>
                            <div>
                                <label><?php echo e(t('debt_notes')); ?></label>
                                <input name="debt_notes[]" autocomplete="off">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="settings-block" style="border-top:0;padding-top:8px;margin-top:8px">
                    <label class="toggle" for="sendDebtWaToggle">
                        <input type="checkbox" name="send_debt_whatsapp" value="1" id="sendDebtWaToggle" checked>
                        <span class="toggle-ui" aria-hidden="true"></span>
                        <span class="toggle-text"><?php echo e($lang === 'en' ? 'Send WhatsApp debt message' : 'إرسال رسالة واتساب بالدين'); ?></span>
                    </label>
                </div>
            </div>
            <?php endif; ?>

            <div class="actions" style="margin-top:16px">
                <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Add' : 'إضافة'); ?></button>
                <a class="btn ghost" href="subscribers.php"><?php echo e($lang === 'en' ? 'Back' : 'رجوع'); ?></a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$showAdd): ?>
<div class="panel panel-subs">
    <div class="subs-sas-bar">
        <div class="subs-ops-side">
            <div class="subs-ops-anchor" id="opsAnchor">
                <button type="button" class="btn ops-top-btn" id="openOpsBtn" aria-haspopup="true" aria-expanded="false"><?php echo e(t('operations')); ?></button>
            </div>
            <span class="meta" id="bulkSelectedCount">0</span>
        </div>
        <div class="subs-left-tools">
        <a class="btn ghost sm" href="subscribers.php?export=offline"><?php echo e($lang === 'en' ? 'Export all' : 'تصدير الكل'); ?></a>
        <button type="button" class="btn ghost sm" onclick="window.print()"><?php echo e(t('print')); ?></button>
        <div class="subs-tool-icons" role="toolbar" aria-label="<?php echo e($lang === 'en' ? 'Table tools' : 'أدوات الجدول'); ?>">
            <?php if (can_manage_agents()): ?>
            <button type="button" class="tool-ico" id="agentFilterBtn" title="<?php echo e($lang === 'en' ? 'Belongs to' : 'تابع إلى'); ?>" aria-haspopup="true">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 2a3 3 0 1 1 0 6 3 3 0 0 1 0-6zm-7.5 16a3 3 0 1 1 0 6 3 3 0 0 1 0-6zm15 0a3 3 0 1 1 0 6 3 3 0 0 1 0-6zM12 9.5c-2.1 0-4 .9-5.3 2.3L5.2 10.3A8.4 8.4 0 0 1 12 8c2.6 0 5 .1 6.8 2.3l-1.5 1.5A7 7 0 0 0 12 9.5zm-5.8 4.4 1.5 1.5c-.5.7-.8 1.5-.9 2.4H4.9c.2-1.5.8-2.9 1.8-3.9zm11.6 0c1 1 1.6 2.4 1.8 3.9h-1.9c-.1-.9-.4-1.7-.9-2.4l1-1.5z"/></svg>
            </button>
            <?php endif; ?>
            <button type="button" class="tool-ico" id="colsToggleBtn" title="<?php echo e($lang === 'en' ? 'Columns' : 'الأعمدة'); ?>" aria-haspopup="true">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M4 6h2v2H4V6zm4 0h12v2H8V6zM4 11h2v2H4v-2zm4 0h12v2H8v-2zM4 16h2v2H4v-2zm4 0h12v2H8v-2z"/></svg>
            </button>
            <button type="button" class="tool-ico" id="autoRefreshBtn" title="<?php echo e($lang === 'en' ? 'Auto refresh' : 'تحديث تلقائي'); ?>" aria-haspopup="true">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M8 6h2v12H8V6zm6 0h2v12h-2V6zm5.5 1.5a1 1 0 0 1 1 1V17a4.5 4.5 0 1 1-2.2-3.9V8.5a1 1 0 0 1 1.2-1zm0 6.2a2.5 2.5 0 1 0 .1 0z"/></svg>
            </button>
            <button type="button" class="tool-ico" id="filterToggleBtn" title="<?php echo e($lang === 'en' ? 'Filter' : 'فلترة'); ?>" aria-haspopup="true">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M3 5h18l-7 8v5l-4 2v-7L3 5z"/></svg>
            </button>
            <button type="button" class="tool-ico" id="refreshTableBtn" title="<?php echo e($lang === 'en' ? 'Refresh table' : 'تحديث الجدول'); ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M17.65 6.35A7.95 7.95 0 0 0 12 4a8 8 0 1 0 7.75 10h-2.1A6 6 0 1 1 12 6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
            </button>
        </div>
        </div>
        <div class="status-legend inline sas-legend">
            <span><i class="status-sq status-active"></i> <?php echo e(t('status_active_short')); ?></span>
            <span><i class="status-sq status-expired"></i> <?php echo e(t('status_expired_short')); ?></span>
            <span><i class="status-sq status-left"></i> <?php echo e(t('status_left_short')); ?></span>
        </div>
        <form method="get" action="subscribers.php" id="subsSearchForm" class="subs-search-row header-search" autocomplete="off">
            <?php if ($perPageRaw !== '20'): ?>
                <input type="hidden" name="per_page" value="<?php echo e($perPageRaw); ?>">
            <?php endif; ?>
            <?php if ($sortKey !== 'name'): ?>
                <input type="hidden" name="sort" value="<?php echo e($sortKey); ?>">
            <?php endif; ?>
            <?php if (!($sortKey === 'name' && $sortDir === 'asc')): ?>
                <input type="hidden" name="dir" value="<?php echo e($sortDir); ?>">
            <?php endif; ?>
            <?php if ($subFilter !== ''): ?>
                <input type="hidden" name="sub" value="<?php echo e($subFilter); ?>">
            <?php endif; ?>
            <?php if ($agentFilter > 0): ?>
                <input type="hidden" name="agent" value="<?php echo (int) $agentFilter; ?>">
            <?php endif; ?>
            <div class="search-suggest-wrap">
                <input id="filterInput" name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e($lang === 'en' ? 'Search name or number…' : 'بحث بالاسم أو الرقم...'); ?>" autocomplete="off">
            </div>
            <?php if ($q !== '' || $subFilter !== ''): ?>
                <a class="btn ghost sm" href="subscribers.php<?php
                    $clearQs = array();
                    if ($perPageRaw !== '20') {
                        $clearQs[] = 'per_page=' . urlencode($perPageRaw);
                    }
                    echo $clearQs ? ('?' . implode('&', $clearQs)) : '';
                ?>"><?php echo e(t('show_all')); ?></a>
            <?php endif; ?>
        </form>
    </div>
    <?php if ($subFilter !== ''): ?>
        <p class="meta" style="margin:0 0 10px;font-weight:700">
            <?php
            $subTitles = array(
                'active' => ($lang === 'en' ? 'Filter: Active' : 'عرض: الفعالين'),
                'expired' => ($lang === 'en' ? 'Filter: Expired / no sub' : 'عرض: المنتهية / بدون اشتراك'),
                'soon' => ($lang === 'en' ? 'Filter: Expiring in 3 days' : 'عرض: على وشك الانتهاء (3 أيام)'),
                'today' => ($lang === 'en' ? 'Filter: Ends today' : 'عرض: ينتهي اليوم'),
                'debt' => ($lang === 'en' ? 'Filter: Has debt' : 'عرض: عليهم دين'),
            );
            echo e(isset($subTitles[$subFilter]) ? $subTitles[$subFilter] : $subFilter);
            ?>
        </p>
    <?php endif; ?>

    <div class="table-wrap">
        <table id="subsTable" class="table-compact data-table">
            <thead>
            <tr>
                <th class="sub-check-cell">
                    <label class="th-check-only" title="<?php echo e(t('select_all')); ?>">
                        <input type="checkbox" id="subCheckAll">
                    </label>
                </th>
                <th class="status-cell"><?php echo e($lang === 'en' ? 'Status' : 'الحالة'); ?></th>
                <th class="col-num"><?php echo subs_sort_link('id', '#', $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th class="col-name"><?php echo subs_sort_link('name', t('name'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th class="col-phone"><?php echo subs_sort_link('phone', t('phone'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th class="col-pkg"><?php echo subs_sort_link('package', t('package'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th class="col-days"><?php echo subs_sort_link('days', t('days_left'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th class="col-debt"><?php echo subs_sort_link('debt', t('debts_total'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th class="col-month"><?php echo subs_sort_link('month', $lang === 'en' ? 'Month' : 'الشهر', $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th class="col-msg"><?php echo subs_sort_link('msg', t('msg_status'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
            </tr>
            </thead>
            <tbody id="subsTableBody">
            <?php
            $n = $offset + 1;
            if (!$rows) {
                echo '<tr><td colspan="10">' . e($lang === 'en' ? 'No subscribers' : 'ماكو مشتركين') . '</td></tr>';
            }
            foreach ($rows as $row) {
                echo render_subscriber_table_row($row, $n++, $config, $lang);
            }
            ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1 || $perPageRaw !== 'all'): ?>
        <div class="actions" id="subsPager" style="margin-top:14px">
            <?php
            $baseQs = array();
            if ($q !== '') {
                $baseQs[] = 'q=' . urlencode($q);
            }
            if ($subFilter !== '') {
                $baseQs[] = 'sub=' . urlencode($subFilter);
            }
            if ($perPageRaw !== '20') {
                $baseQs[] = 'per_page=' . urlencode($perPageRaw);
            }
            if ($sortKey !== 'name') {
                $baseQs[] = 'sort=' . urlencode($sortKey);
            }
            if (!($sortKey === 'name' && $sortDir === 'asc')) {
                $baseQs[] = 'dir=' . urlencode($sortDir);
            }
            $baseStr = count($baseQs) ? '&' . implode('&', $baseQs) : '';
            $extraQs = '';
            if ($q !== '') {
                $extraQs .= '&q=' . urlencode($q);
            }
            if ($subFilter !== '') {
                $extraQs .= '&sub=' . urlencode($subFilter);
            }
            if ($sortKey !== 'name' || $sortDir !== 'asc') {
                $extraQs .= '&sort=' . urlencode($sortKey) . '&dir=' . urlencode($sortDir);
            }
            for ($p = 1; $p <= $totalPages; $p++):
            ?>
                <a class="btn <?php echo $p === $page ? '' : 'ghost'; ?> sm" href="?page=<?php echo $p . $baseStr; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if (!$showAll): ?>
                <a class="btn ghost sm" href="?per_page=all<?php echo $extraQs; ?>"><?php echo e(t('show_all')); ?></a>
            <?php else: ?>
                <a class="btn ghost sm" href="?per_page=20<?php echo $extraQs; ?>">20</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($showAdd): ?>
<script>
(function () {
  var debtsToggle = document.getElementById('addDebtsToggle');
  var debtsBox = document.getElementById('extraDebtsBox');
  function syncDebts() {
    if (!debtsBox || !debtsToggle) return;
    debtsBox.style.display = debtsToggle.checked ? '' : 'none';
  }
  if (debtsToggle) {
    debtsToggle.addEventListener('change', syncDebts);
    syncDebts();
  }

  var pickBtn = document.getElementById('pickContactBtn');
  var phoneInput = document.getElementById('subPhone');
  var nameInput = document.querySelector('#addBox input[name="name"]');
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
<script>
window.DEBT_ENTRY = {
  month: <?php echo json_encode(date('Y-m')); ?>,
  years: <?php echo json_encode(array((int) date('Y') - 1, (int) date('Y'), (int) date('Y') + 1)); ?>,
  labels: {
    type: <?php echo json_encode($lang === 'en' ? 'Type' : 'النوع'); ?>,
    month: <?php echo json_encode($lang === 'en' ? 'Month' : 'الشهر'); ?>,
    amount: <?php echo json_encode($lang === 'en' ? 'Amount' : 'المبلغ'); ?>,
    notes: <?php echo json_encode(t('debt_notes')); ?>,
    monthOpt: <?php echo json_encode(t('debt_type_month')); ?>,
    itemOpt: <?php echo json_encode(t('debt_type_item')); ?>,
    remove: <?php echo json_encode($lang === 'en' ? 'Remove line' : 'حذف السطر'); ?>
  }
};
</script>
<script src="assets/debt-entry.js?v=3"></script>
<?php else: ?>
<!-- قوائم منسدلة ثابتة على الـ body حتى ما تنحبس داخل الجدول -->
<div class="ops-dropdown hidden" id="opsDropdown" role="menu">
    <a class="ops-item" href="subscribers.php?add=1" id="opsAddLink"><?php echo e(t('add_subscriber')); ?></a>
    <div class="ops-sep" id="opsSep" hidden></div>
    <button type="button" class="ops-item" data-ops="open" id="opsItemOpen" hidden><?php echo e($lang === 'en' ? 'Open' : 'فتح'); ?></button>
    <button type="button" class="ops-item" data-ops="activate" id="opsItemActivate" hidden><?php echo e(t('activate')); ?></button>
    <button type="button" class="ops-item" data-ops="give_test" id="opsItemGiveTest" hidden><?php echo e(t('give_test')); ?></button>
    <button type="button" class="ops-item" data-ops="bulk_activate" id="opsItemBulkActivate" hidden><?php echo e(t('bulk_activate')); ?></button>
    <button type="button" class="ops-item" data-ops="pay" id="opsItemPay" hidden><?php echo e(t('pay_debt')); ?></button>
    <button type="button" class="ops-item" data-ops="remind_debt" id="opsItemRemind" hidden><?php echo e(t('remind')); ?></button>
    <button type="button" class="ops-item" data-ops="remind_days" id="opsItemDays" hidden><?php echo e($lang === 'en' ? 'Send days left' : 'إرسال الأيام المتبقية'); ?></button>
    <button type="button" class="ops-item" data-ops="retry" id="opsItemRetry" hidden><?php echo e(t('retry_send')); ?></button>
    <button type="button" class="ops-item ops-danger" data-ops="delete" id="opsItemDelete" hidden><?php echo e(t('delete')); ?></button>
</div>
<div class="ops-dropdown cols-dropdown hidden" id="colsDropdown">
    <label class="ops-item cols-check"><input type="checkbox" data-col="phone"> <?php echo e(t('phone')); ?></label>
    <label class="ops-item cols-check"><input type="checkbox" data-col="pkg" checked> <?php echo e(t('package')); ?></label>
    <label class="ops-item cols-check"><input type="checkbox" data-col="days" checked> <?php echo e(t('days_left')); ?></label>
    <label class="ops-item cols-check"><input type="checkbox" data-col="debt" checked> <?php echo e(t('debts_total')); ?></label>
    <label class="ops-item cols-check"><input type="checkbox" data-col="month"> <?php echo e($lang === 'en' ? 'Month' : 'الشهر'); ?></label>
    <label class="ops-item cols-check"><input type="checkbox" data-col="msg" checked> <?php echo e(t('msg_status')); ?></label>
</div>
<div class="ops-dropdown hidden" id="filterDropdown">
    <a class="ops-item<?php echo $subFilter === '' ? ' is-on' : ''; ?>" href="subscribers.php<?php echo $agentFilter ? ('?agent=' . (int) $agentFilter) : ''; ?>"><?php echo e($lang === 'en' ? 'All' : 'الكل'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'active' ? ' is-on' : ''; ?>" href="subscribers.php?sub=active<?php echo $agentFilter ? ('&agent=' . (int) $agentFilter) : ''; ?>"><?php echo e($lang === 'en' ? 'Active' : 'فعال'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'expired' ? ' is-on' : ''; ?>" href="subscribers.php?sub=expired<?php echo $agentFilter ? ('&agent=' . (int) $agentFilter) : ''; ?>"><?php echo e($lang === 'en' ? 'Expired / none' : 'منتهي / بدون اشتراك'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'soon' ? ' is-on' : ''; ?>" href="subscribers.php?sub=soon<?php echo $agentFilter ? ('&agent=' . (int) $agentFilter) : ''; ?>"><?php echo e($lang === 'en' ? 'Expiring soon' : 'قرب ينتهي'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'today' ? ' is-on' : ''; ?>" href="subscribers.php?sub=today<?php echo $agentFilter ? ('&agent=' . (int) $agentFilter) : ''; ?>"><?php echo e($lang === 'en' ? 'Ends today' : 'ينتهي اليوم'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'debt' ? ' is-on' : ''; ?>" href="subscribers.php?sub=debt<?php echo $agentFilter ? ('&agent=' . (int) $agentFilter) : ''; ?>"><?php echo e($lang === 'en' ? 'Has debt' : 'عليهم دين'); ?></a>
</div>
<div class="ops-dropdown hidden" id="autoRefreshDropdown">
    <button type="button" class="ops-item" data-refresh="0"><?php echo e($lang === 'en' ? 'Off' : 'إيقاف'); ?></button>
    <button type="button" class="ops-item" data-refresh="30"><?php echo e($lang === 'en' ? 'Every 30s' : 'كل 30 ثانية'); ?></button>
    <button type="button" class="ops-item" data-refresh="60"><?php echo e($lang === 'en' ? 'Every 1 min' : 'كل دقيقة'); ?></button>
    <button type="button" class="ops-item" data-refresh="120"><?php echo e($lang === 'en' ? 'Every 2 min' : 'كل دقيقتين'); ?></button>
    <button type="button" class="ops-item" data-refresh="300"><?php echo e($lang === 'en' ? 'Every 5 min' : 'كل 5 دقائق'); ?></button>
</div>
<?php if (can_manage_agents()): ?>
<div class="ops-dropdown hidden" id="agentDropdown">
    <a class="ops-item<?php echo $agentFilter === 0 ? ' is-on' : ''; ?>" href="subscribers.php<?php echo $subFilter !== '' ? ('?sub=' . urlencode($subFilter)) : ''; ?>"><?php echo e($lang === 'en' ? 'All agents' : 'كل الوكلاء'); ?></a>
    <?php foreach ($agentUsers as $au): ?>
        <?php $auId = (int) $au['id']; ?>
        <a class="ops-item<?php echo $agentFilter === $auId ? ' is-on' : ''; ?>" href="subscribers.php?agent=<?php echo $auId; ?><?php echo $subFilter !== '' ? ('&sub=' . urlencode($subFilter)) : ''; ?>">
            <?php echo e($au['display_name']); ?>
            <?php if (normalize_admin_role($au['role']) === 'admin'): ?>
                <span class="meta">(<?php echo e($lang === 'en' ? 'admin' : 'مدير'); ?>)</span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
    <div class="ops-sep"></div>
    <div class="ops-item" style="display:block;cursor:default">
        <label style="display:block;font-size:12px;margin-bottom:4px"><?php echo e($lang === 'en' ? 'Assign selected to' : 'إسناد المحددين إلى'); ?></label>
        <select id="assignAgentSelect" style="width:100%;margin-bottom:6px">
            <?php foreach ($agentUsers as $au): ?>
                <option value="<?php echo (int) $au['id']; ?>"><?php echo e($au['display_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn sm" id="opsAssignAgentBtn" style="width:100%"><?php echo e($lang === 'en' ? 'Assign' : 'إسناد'); ?></button>
    </div>
</div>
<form method="post" id="assignAgentForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="assign_agent">
    <input type="hidden" name="agent_user_id" id="assignAgentId" value="">
</form>
<?php endif; ?>

<div class="modal-backdrop hidden" id="opsBulkModal">
    <div class="modal-card ops-modal-card">
        <div class="ops-modal-head">
            <h3><?php echo e(t('bulk_activate')); ?></h3>
            <button type="button" class="btn ghost sm" id="opsBulkModalClose">×</button>
        </div>
        <p class="meta" style="margin:0 0 8px"><?php echo e($lang === 'en' ? 'Selected subscribers' : 'المشتركون المحددون'); ?></p>
        <ul class="ops-selected-list" id="opsSelectedList"></ul>
        <div class="ops-section" style="border-top:0;padding-top:0;margin-top:0">
            <div class="pay-mode-label"><?php echo e(t('pay_mode')); ?></div>
            <div class="pay-mode-row" id="opsPayModeRow">
                <label class="pay-mode-option">
                    <input type="radio" name="pay_mode" value="cash" form="opsBulkActivateForm" checked>
                    <span class="pay-mode-card cash">
                        <strong><?php echo e(t('pay_cash')); ?></strong>
                        <small><?php echo e(t('pay_cash_hint')); ?></small>
                    </span>
                </label>
                <label class="pay-mode-option">
                    <input type="radio" name="pay_mode" value="credit" form="opsBulkActivateForm">
                    <span class="pay-mode-card credit">
                        <strong><?php echo e(t('pay_credit')); ?></strong>
                        <small><?php echo e(t('pay_credit_hint')); ?></small>
                    </span>
                </label>
            </div>
            <form method="post" id="opsBulkActivateForm" class="ops-activate-form">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="bulk_activate">
                <label class="toggle" style="margin:12px 0">
                    <input type="checkbox" name="send_whatsapp" value="1" checked>
                    <span class="toggle-ui"></span>
                    <span class="toggle-text"><?php echo e(t('send_message')); ?></span>
                </label>
                <button class="btn" type="submit" style="width:100%"><?php echo e(t('bulk_activate')); ?></button>
            </form>
        </div>
    </div>
</div>

<form method="post" id="opsRemindOneForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="remind_debt">
    <input type="hidden" name="id" value="">
</form>
<form method="post" id="opsBulkRemindForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="bulk_remind_debt">
</form>
<form method="post" id="opsDaysForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="remind_days">
    <input type="hidden" name="id" value="">
</form>
<form method="post" id="opsRetryForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="retry_message">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="log_id" value="">
</form>
<form method="post" id="opsGiveTestForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="give_test">
    <input type="hidden" name="id" value="">
</form>
<form method="post" id="opsDeleteForm" class="hidden" hidden onsubmit="return confirm(<?php echo json_encode(t('confirm_delete')); ?>);">
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="">
</form>

<script>
(function () {
  document.querySelectorAll('#opsPayModeRow .pay-mode-option').forEach(function (lab) {
    lab.addEventListener('mousedown', function (e) { e.preventDefault(); });
  });
})();
</script>
<script>
(function () {
  var filter = document.getElementById('filterInput');
  var searchForm = document.getElementById('subsSearchForm');
  var tbody = document.getElementById('subsTableBody');
  var pager = document.getElementById('subsPager');
  var liveTimer = null;
  var liveReq = 0;
  var originalHtml = tbody ? tbody.innerHTML : '';
  var originalPagerDisplay = pager ? pager.style.display : '';

  var checkAll = document.getElementById('subCheckAll');
  var opsBtn = document.getElementById('openOpsBtn');
  var opsDrop = document.getElementById('opsDropdown');
  var opsAnchor = document.getElementById('opsAnchor');
  var bulkCount = document.getElementById('bulkSelectedCount');
  var bulkModal = document.getElementById('opsBulkModal');
  var bulkClose = document.getElementById('opsBulkModalClose');
  var opsList = document.getElementById('opsSelectedList');
  var countLabel = <?php echo json_encode($lang === 'en' ? 'selected' : 'محدد'); ?>;
  var confirmDel = <?php echo json_encode(t('confirm_delete')); ?>;
  var confirmTest = <?php echo json_encode(t('confirm_give_test')); ?>;

  function visibleChecks() {
    return tbody ? Array.prototype.slice.call(tbody.querySelectorAll('input.sub-check')) : [];
  }
  function selectedRows() {
    var out = [];
    visibleChecks().forEach(function (c) {
      if (!c.checked) return;
      var tr = c.closest('tr');
      if (!tr) return;
      out.push({
        id: tr.getAttribute('data-id'),
        name: tr.getAttribute('data-name') || '',
        debt: tr.getAttribute('data-debt') === '1',
        active: tr.getAttribute('data-active') === '1',
        msgFail: tr.getAttribute('data-msg-fail') === '1',
        logId: tr.getAttribute('data-log-id') || '0',
        hasDays: tr.getAttribute('data-has-days') === '1'
      });
    });
    return out;
  }
  function syncBulk() {
    var list = visibleChecks();
    var n = 0;
    list.forEach(function (c) { if (c.checked) n++; });
    if (bulkCount) bulkCount.textContent = n + ' ' + countLabel;
    if (checkAll && list.length) {
      checkAll.checked = n > 0 && n === list.length;
      checkAll.indeterminate = n > 0 && n < list.length;
    } else if (checkAll) {
      checkAll.checked = false;
      checkAll.indeterminate = false;
    }
  }
  function fillHiddenIds(form) {
    if (!form) return;
    Array.prototype.slice.call(form.querySelectorAll('input.ops-id-dyn')).forEach(function (el) {
      el.parentNode.removeChild(el);
    });
    selectedRows().forEach(function (r) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'ids[]';
      inp.value = r.id;
      inp.className = 'ops-id-dyn';
      form.appendChild(inp);
    });
  }
  function showEl(el, on) {
    if (!el) return;
    if (on) {
      el.hidden = false;
      el.removeAttribute('hidden');
      el.style.display = '';
    } else {
      el.hidden = true;
      el.setAttribute('hidden', 'hidden');
      el.style.display = 'none';
    }
  }
  function refreshMenuItems() {
    var rows = selectedRows();
    var n = rows.length;
    var one = n === 1 ? rows[0] : null;
    var anyDebt = rows.some(function (r) { return r.debt; });
    // مشترك جديد بدون دين/أيام/رسالة فاشلة: فتح + تفعيل + حذف فقط
    showEl(document.getElementById('opsSep'), n > 0);
    showEl(document.getElementById('opsItemOpen'), !!one);
    showEl(document.getElementById('opsItemActivate'), !!one);
    showEl(document.getElementById('opsItemGiveTest'), !!one);
    showEl(document.getElementById('opsItemBulkActivate'), n > 1);
    showEl(document.getElementById('opsItemPay'), anyDebt);
    showEl(document.getElementById('opsItemRemind'), anyDebt);
    showEl(document.getElementById('opsItemDays'), !!(one && one.hasDays));
    showEl(document.getElementById('opsItemRetry'), !!(one && one.msgFail));
    showEl(document.getElementById('opsItemDelete'), !!one);
  }
  function placeMenuAt(el, clientX, clientY, anchorBtn) {
    if (!el) return;
    if (el.parentNode !== document.body) {
      document.body.appendChild(el);
    }
    el.classList.add('ops-float');
    el.classList.remove('hidden');
    el.style.position = 'fixed';
    el.style.visibility = 'hidden';
    el.style.left = '0px';
    el.style.top = '0px';
    el.style.right = 'auto';
    el.style.bottom = 'auto';
    el.style.insetInlineStart = 'auto';
    el.style.insetInlineEnd = 'auto';
    var w = el.offsetWidth || 220;
    var h = el.offsetHeight || 260;
    var x, y;
    if (typeof clientX === 'number' && typeof clientY === 'number') {
      // افتح يسار المؤشر إذا قريب من حافة اليمين (جنب السايدبار)
      x = clientX;
      y = clientY;
      if (x + w > window.innerWidth - 12) {
        x = clientX - w;
      }
      if (x < 8) x = 8;
      if (y + h > window.innerHeight - 8) {
        y = window.innerHeight - h - 8;
      }
      if (y < 8) y = 8;
    } else if (anchorBtn) {
      var r = anchorBtn.getBoundingClientRect();
      x = r.left;
      y = r.bottom + 6;
      if (document.documentElement.dir === 'rtl') {
        x = r.right - w;
      }
      if (x < 8) x = 8;
      if (x + w > window.innerWidth - 8) x = window.innerWidth - w - 8;
      if (y + h > window.innerHeight - 8) y = Math.max(8, r.top - h - 6);
    } else {
      x = 24;
      y = 80;
    }
    el.style.left = Math.round(x) + 'px';
    el.style.top = Math.round(y) + 'px';
    el.style.visibility = 'visible';
  }
  function closeOpsMenu() {
    if (!opsDrop) return;
    opsDrop.classList.add('hidden');
    opsDrop.classList.remove('ops-float');
    opsDrop.style.left = '';
    opsDrop.style.top = '';
    opsDrop.style.right = '';
    opsDrop.style.visibility = '';
    if (opsBtn) opsBtn.setAttribute('aria-expanded', 'false');
  }
  function openOpsMenu(clientX, clientY) {
    if (!opsDrop) return;
    refreshMenuItems();
    placeMenuAt(opsDrop, clientX, clientY, opsBtn);
    if (opsBtn) opsBtn.setAttribute('aria-expanded', 'true');
  }
  function openBulkActivateModal() {
    var rows = selectedRows();
    if (rows.length < 1 || !bulkModal) return;
    if (opsList) {
      opsList.innerHTML = rows.map(function (r) {
        return '<li><strong>' + r.name + '</strong></li>';
      }).join('');
    }
    fillHiddenIds(document.getElementById('opsBulkActivateForm'));
    bulkModal.classList.remove('hidden');
  }
  function closeBulkModal() {
    if (bulkModal) bulkModal.classList.add('hidden');
  }
  function selectOnlyRow(tr) {
    if (!tr) return;
    visibleChecks().forEach(function (c) { c.checked = false; });
    var chk = tr.querySelector('input.sub-check');
    if (chk) chk.checked = true;
    syncBulk();
  }
  function runOps(action) {
    var rows = selectedRows();
    var one = rows.length === 1 ? rows[0] : null;
    closeOpsMenu();
    if (action === 'open' && one) {
      window.location.href = 'subscriber.php?id=' + encodeURIComponent(one.id);
      return;
    }
    if (action === 'activate' && one) {
      window.location.href = 'activate.php?subscriber_id=' + encodeURIComponent(one.id);
      return;
    }
    if (action === 'give_test' && one) {
      if (!window.confirm(confirmTest)) return;
      var ft = document.getElementById('opsGiveTestForm');
      var tid = ft && ft.querySelector('input[name="id"]');
      if (tid) tid.value = one.id;
      if (ft) ft.submit();
      return;
    }
    if (action === 'bulk_activate') {
      openBulkActivateModal();
      return;
    }
    if (action === 'pay') {
      if (one) {
        window.location.href = 'debts.php?status=unpaid&subscriber_id=' + encodeURIComponent(one.id);
      } else if (rows.length) {
        window.location.href = 'debts.php?status=unpaid';
      }
      return;
    }
    if (action === 'remind_debt') {
      if (one) {
        var f1 = document.getElementById('opsRemindOneForm');
        var id1 = f1 && f1.querySelector('input[name="id"]');
        if (id1) id1.value = one.id;
        if (f1) f1.submit();
      } else if (rows.length) {
        var fb = document.getElementById('opsBulkRemindForm');
        fillHiddenIds(fb);
        if (fb) fb.submit();
      }
      return;
    }
    if (action === 'remind_days' && one) {
      var fd = document.getElementById('opsDaysForm');
      var idd = fd && fd.querySelector('input[name="id"]');
      if (idd) idd.value = one.id;
      if (fd) fd.submit();
      return;
    }
    if (action === 'retry' && one) {
      var fr = document.getElementById('opsRetryForm');
      var rid = fr && fr.querySelector('input[name="id"]');
      var lid = fr && fr.querySelector('input[name="log_id"]');
      if (rid) rid.value = one.id;
      if (lid) lid.value = one.logId;
      if (fr) fr.submit();
      return;
    }
    if (action === 'delete' && one) {
      var fdel = document.getElementById('opsDeleteForm');
      var did = fdel && fdel.querySelector('input[name="id"]');
      if (did) did.value = one.id;
      if (fdel) fdel.submit();
    }
  }

  if (checkAll) {
    checkAll.addEventListener('change', function () {
      visibleChecks().forEach(function (c) { c.checked = !!checkAll.checked; });
      syncBulk();
    });
  }
  if (tbody) {
    tbody.addEventListener('change', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('sub-check')) syncBulk();
    });
    tbody.addEventListener('contextmenu', function (e) {
      var tr = e.target && e.target.closest ? e.target.closest('tr[data-id]') : null;
      if (!tr || !tbody.contains(tr)) return;
      e.preventDefault();
      e.stopPropagation();
      var chk = tr.querySelector('input.sub-check');
      if (chk && !chk.checked) selectOnlyRow(tr);
      else syncBulk();
      openOpsMenu(e.clientX, e.clientY);
    });
    var lpTimer = null;
    var lpStart = null;
    tbody.addEventListener('touchstart', function (e) {
      var tr = e.target && e.target.closest ? e.target.closest('tr[data-id]') : null;
      if (!tr || !e.touches || !e.touches[0]) return;
      if (e.target.closest('a,button,input,label')) return;
      lpStart = { tr: tr, x: e.touches[0].clientX, y: e.touches[0].clientY };
      clearTimeout(lpTimer);
      lpTimer = setTimeout(function () {
        if (!lpStart) return;
        e.preventDefault && e.preventDefault();
        selectOnlyRow(lpStart.tr);
        openOpsMenu(lpStart.x, lpStart.y);
        lpStart = null;
      }, 520);
    }, { passive: true });
    tbody.addEventListener('touchmove', function () {
      clearTimeout(lpTimer);
      lpStart = null;
    }, { passive: true });
    tbody.addEventListener('touchend', function () {
      clearTimeout(lpTimer);
      lpStart = null;
    });
    tbody.addEventListener('touchcancel', function () {
      clearTimeout(lpTimer);
      lpStart = null;
    });
  }

  if (opsBtn) {
    opsBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      closeFloatMenu(document.getElementById('colsDropdown'));
      closeFloatMenu(document.getElementById('filterDropdown'));
      closeFloatMenu(document.getElementById('autoRefreshDropdown'));
      closeFloatMenu(document.getElementById('agentDropdown'));
      if (opsDrop && !opsDrop.classList.contains('hidden') && !opsDrop.classList.contains('ops-float')) {
        closeOpsMenu();
        return;
      }
      openOpsMenu();
    });
  }
  if (opsDrop) {
    opsDrop.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('[data-ops]') : null;
      if (!btn) return;
      e.preventDefault();
      runOps(btn.getAttribute('data-ops'));
    });
  }
  function closeFloatMenu(el) {
    if (!el) return;
    el.classList.add('hidden');
    el.classList.remove('ops-float');
    el.style.left = '';
    el.style.top = '';
    el.style.right = '';
    el.style.visibility = '';
  }
  function closeAllToolMenus() {
    closeOpsMenu();
    closeFloatMenu(document.getElementById('colsDropdown'));
    closeFloatMenu(document.getElementById('filterDropdown'));
    closeFloatMenu(document.getElementById('autoRefreshDropdown'));
    closeFloatMenu(document.getElementById('agentDropdown'));
  }
  function toggleToolMenu(btn, drop) {
    if (!btn || !drop) return;
    var wasOpen = !drop.classList.contains('hidden');
    closeAllToolMenus();
    if (!wasOpen) placeMenuAt(drop, null, null, btn);
  }
  document.addEventListener('click', function (e) {
    if (opsDrop && !opsDrop.classList.contains('hidden')) {
      if (!(opsAnchor && opsAnchor.contains(e.target)) && !opsDrop.contains(e.target)) {
        closeOpsMenu();
      }
    }
    ['colsDropdown', 'filterDropdown', 'autoRefreshDropdown', 'agentDropdown'].forEach(function (id) {
      var drop = document.getElementById(id);
      if (!drop || drop.classList.contains('hidden')) return;
      var btnIds = {
        colsDropdown: 'colsToggleBtn',
        filterDropdown: 'filterToggleBtn',
        autoRefreshDropdown: 'autoRefreshBtn',
        agentDropdown: 'agentFilterBtn'
      };
      var btn = document.getElementById(btnIds[id]);
      if ((btn && btn.contains(e.target)) || drop.contains(e.target)) return;
      closeFloatMenu(drop);
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAllToolMenus();
      closeBulkModal();
    }
  });
  if (bulkClose) bulkClose.addEventListener('click', closeBulkModal);
  if (bulkModal) {
    bulkModal.addEventListener('click', function (e) {
      if (e.target === bulkModal) closeBulkModal();
    });
  }
  var actForm = document.getElementById('opsBulkActivateForm');
  if (actForm) {
    actForm.addEventListener('submit', function () { fillHiddenIds(actForm); });
  }

  /* إظهار/إخفاء أعمدة الجدول */
  var applySubsCols = function () {};
  (function setupCols() {
    var table = document.getElementById('subsTable');
    var colsBtn = document.getElementById('colsToggleBtn');
    var colsDrop = document.getElementById('colsDropdown');
    if (!table || !colsDrop) return;
    var map = {
      phone: '.col-phone',
      pkg: '.col-pkg',
      month: '.col-month',
      days: '.col-days',
      debt: '.col-debt',
      msg: '.col-msg'
    };
    var key = 'subsTableCols_v2';
    var defaults = { phone: false, pkg: true, month: false, days: true, debt: true, msg: true };
    var state = defaults;
    try {
      var raw = localStorage.getItem(key);
      if (raw) state = Object.assign({}, defaults, JSON.parse(raw));
    } catch (err) {}
    applySubsCols = function () {
      Object.keys(map).forEach(function (k) {
        var on = state[k] !== false;
        Array.prototype.slice.call(table.querySelectorAll(map[k])).forEach(function (el) {
          if (on) el.classList.remove('col-hide');
          else el.classList.add('col-hide');
        });
        var inp = colsDrop.querySelector('input[data-col="' + k + '"]');
        if (inp) inp.checked = on;
      });
    };
    applySubsCols();
    if (colsBtn) {
      colsBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleToolMenu(colsBtn, colsDrop);
      });
    }
    colsDrop.addEventListener('change', function (e) {
      var inp = e.target;
      if (!inp || !inp.getAttribute('data-col')) return;
      var k = inp.getAttribute('data-col');
      state[k] = !!inp.checked;
      try { localStorage.setItem(key, JSON.stringify(state)); } catch (err2) {}
      applySubsCols();
    });
    colsDrop.addEventListener('click', function (e) { e.stopPropagation(); });
  })();

  (function setupToolIcons() {
    var refreshBtn = document.getElementById('refreshTableBtn');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function (e) {
        e.preventDefault();
        window.location.reload();
      });
    }
    var filterBtn = document.getElementById('filterToggleBtn');
    var filterDrop = document.getElementById('filterDropdown');
    if (filterBtn && filterDrop) {
      filterBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleToolMenu(filterBtn, filterDrop);
      });
      if (<?php echo $subFilter !== '' ? 'true' : 'false'; ?>) filterBtn.classList.add('is-on');
    }
    var autoBtn = document.getElementById('autoRefreshBtn');
    var autoDrop = document.getElementById('autoRefreshDropdown');
    var autoTimer = null;
    var autoKey = 'subsAutoRefreshSec';
    function applyAutoRefresh(sec) {
      sec = parseInt(sec, 10) || 0;
      try { localStorage.setItem(autoKey, String(sec)); } catch (err) {}
      if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
      if (autoBtn) autoBtn.classList.toggle('is-on', sec > 0);
      if (sec > 0) {
        autoTimer = setInterval(function () { window.location.reload(); }, sec * 1000);
      }
      if (autoDrop) {
        Array.prototype.slice.call(autoDrop.querySelectorAll('[data-refresh]')).forEach(function (b) {
          b.classList.toggle('is-on', String(b.getAttribute('data-refresh')) === String(sec));
        });
      }
    }
    if (autoBtn && autoDrop) {
      autoBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleToolMenu(autoBtn, autoDrop);
      });
      autoDrop.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-refresh]') : null;
        if (!b) return;
        e.preventDefault();
        applyAutoRefresh(b.getAttribute('data-refresh'));
        closeFloatMenu(autoDrop);
      });
      var saved = 0;
      try { saved = parseInt(localStorage.getItem(autoKey) || '0', 10) || 0; } catch (err2) {}
      applyAutoRefresh(saved);
    }
    var agentBtn = document.getElementById('agentFilterBtn');
    var agentDrop = document.getElementById('agentDropdown');
    if (agentBtn && agentDrop) {
      agentBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleToolMenu(agentBtn, agentDrop);
      });
      if (<?php echo $agentFilter > 0 ? 'true' : 'false'; ?>) agentBtn.classList.add('is-on');
    }
    var assignBtn = document.getElementById('opsAssignAgentBtn');
    if (assignBtn) {
      assignBtn.addEventListener('click', function () {
        var rows = selectedRows();
        if (!rows.length) {
          alert(<?php echo json_encode($lang === 'en' ? 'Select subscribers first' : 'حدد مشتركين أولاً'); ?>);
          return;
        }
        var sel = document.getElementById('assignAgentSelect');
        var form = document.getElementById('assignAgentForm');
        var hid = document.getElementById('assignAgentId');
        if (!form || !hid || !sel) return;
        hid.value = sel.value;
        fillHiddenIds(form);
        form.submit();
      });
    }
  })();

  (function setupInlineEdit() {
    var csrf = <?php echo json_encode(csrf_token()); ?>;
    var saving = false;
    function saveField(el, field, id, value) {
      if (saving) return;
      saving = true;
      var body = new FormData();
      body.append('csrf', csrf);
      body.append('action', 'inline_update');
      body.append('id', id);
      body.append('field', field);
      body.append('value', value);
      fetch('subscribers.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          saving = false;
          if (!data || !data.ok) {
            alert(<?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
            el.textContent = el.getAttribute('data-display') || el.getAttribute('data-value') || '';
            return;
          }
          el.textContent = data.value;
          el.setAttribute('data-value', field === 'phone' ? (data.raw || value) : data.value);
          el.setAttribute('data-display', data.value);
          if (field === 'name') {
            el.setAttribute('href', 'subscriber.php?id=' + id);
          }
        })
        .catch(function () {
          saving = false;
          alert(<?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
        });
    }
    function beginEdit(el) {
      if (!el || el.classList.contains('editing')) return;
      var field = el.getAttribute('data-edit');
      var id = el.getAttribute('data-id');
      if (!field || !id) return;
      var current = el.getAttribute('data-value') || el.textContent.trim();
      el.setAttribute('data-display', el.textContent.trim());
      el.classList.add('editing');
      el.contentEditable = 'true';
      el.focus();
      try {
        var range = document.createRange();
        range.selectNodeContents(el);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      } catch (err) {}
      function finish(ok) {
        if (!el.classList.contains('editing')) return;
        el.classList.remove('editing');
        el.contentEditable = 'false';
        var val = el.textContent.trim();
        if (!ok || val === '') {
          el.textContent = el.getAttribute('data-display') || current;
          return;
        }
        if (val === current) return;
        saveField(el, field, id, val);
      }
      el.onkeydown = function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
        if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
      };
      el.onblur = function () { finish(true); };
    }
    function beginDebtEdit(btn) {
      if (!btn || btn.classList.contains('editing')) return;
      var current = btn.getAttribute('data-amount') || '0';
      var snap = btn.textContent;
      var id = btn.getAttribute('data-sub') || '';
      btn.classList.add('editing');
      btn.contentEditable = 'true';
      btn.textContent = current;
      btn.focus();
      try {
        var range = document.createRange();
        range.selectNodeContents(btn);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      } catch (err) {}
      var done = false;
      function finish(ok) {
        if (done || !btn.classList.contains('editing')) return;
        done = true;
        btn.classList.remove('editing');
        btn.contentEditable = 'false';
        var raw = String(btn.textContent || '').replace(/[^\d]/g, '');
        var n = parseInt(raw, 10);
        if (!ok || !raw) {
          btn.textContent = snap;
          return;
        }
        if (String(n) === String(current)) {
          btn.textContent = snap;
          return;
        }
        if (!(n > 0)) {
          alert(<?php echo json_encode($lang === 'en' ? 'Enter a valid amount' : 'أدخل مبلغ صحيح'); ?>);
          btn.textContent = snap;
          return;
        }
        var body = new FormData();
        body.append('csrf', csrf);
        body.append('action', 'inline_update_debt');
        body.append('id', id);
        body.append('amount', String(n));
        fetch('subscribers.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data || !data.ok) {
              alert((data && data.message) ? data.message : <?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
              btn.textContent = snap;
              return;
            }
            btn.textContent = data.debt_text || String(n);
            btn.setAttribute('data-amount', String(Math.round(Number(data.debt) || n)));
            btn.className = (Number(data.debt) > 0 ? 'debt-amt debt-due' : 'debt-amt debt-zero') + ' debt-edit-btn';
            var tr = btn.closest('tr');
            if (tr) tr.setAttribute('data-debt', Number(data.debt) > 0 ? '1' : '0');
          })
          .catch(function () {
            alert(<?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
            btn.textContent = snap;
          });
      }
      btn.onkeydown = function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
        if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
      };
      btn.onblur = function () { finish(true); };
    }
    document.addEventListener('click', function (e) {
      var debtBtn = e.target && e.target.closest ? e.target.closest('.debt-edit-btn') : null;
      if (debtBtn && !debtBtn.classList.contains('editing')) {
        e.preventDefault();
        e.stopPropagation();
        beginDebtEdit(debtBtn);
        return;
      }
      var phone = e.target && e.target.closest ? e.target.closest('.phone-edit') : null;
      if (phone) {
        e.preventDefault();
        beginEdit(phone);
      }
    });
    document.addEventListener('dblclick', function (e) {
      var name = e.target && e.target.closest ? e.target.closest('a.sub-name[data-edit="name"]') : null;
      if (name) {
        e.preventDefault();
        beginEdit(name);
      }
    });
  })();

  function fetchLive(q) {
    var myReq = ++liveReq;
    if (!tbody) return;
    if (!q) {
      tbody.innerHTML = originalHtml;
      if (pager) pager.style.display = originalPagerDisplay || '';
      syncBulk();
      applySubsCols();
      return;
    }
    var url = 'subscribers.php?live=1&q=' + encodeURIComponent(q);
    <?php if ($subFilter !== ''): ?>
    url += '&sub=<?php echo rawurlencode($subFilter); ?>';
    <?php endif; ?>
    <?php if ($agentFilter > 0): ?>
    url += '&agent=<?php echo (int) $agentFilter; ?>';
    <?php endif; ?>
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4 || myReq !== liveReq) return;
      if (xhr.status < 200 || xhr.status >= 300) return;
      try {
        var data = JSON.parse(xhr.responseText);
        tbody.innerHTML = data.html || '';
        if (pager) pager.style.display = 'none';
        syncBulk();
        applySubsCols();
      } catch (err) {}
    };
    xhr.send();
  }

  if (filter) {
    filter.addEventListener('input', function () {
      var q = filter.value.trim();
      if (liveTimer) clearTimeout(liveTimer);
      liveTimer = setTimeout(function () { fetchLive(q); }, 200);
    });
    filter.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (liveTimer) clearTimeout(liveTimer);
        fetchLive(filter.value.trim());
        filter.focus();
      }
    });
  }
  if (searchForm) {
    searchForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!filter) return;
      if (liveTimer) clearTimeout(liveTimer);
      fetchLive(filter.value.trim());
      filter.focus();
    });
  }
  syncBulk();

  var focusId = <?php echo (int) $focusId; ?>;
  if (focusId > 0) {
    var row = document.getElementById('sub-row-' + focusId) || document.querySelector('tr[data-id="' + focusId + '"]');
    if (row) {
      row.classList.add('row-focus');
      try {
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
      } catch (e) {
        row.scrollIntoView(true);
      }
      var link = row.querySelector('a');
      if (link) {
        try { link.focus(); } catch (e2) {}
      }
      setTimeout(function () { row.classList.remove('row-focus'); }, 4000);
    }
  }
})();
</script>

<div class="modal-backdrop hidden" id="daysModal">
    <div class="modal-card">
        <h3><?php echo e($lang === 'en' ? 'Days left' : 'الأيام المتبقية'); ?></h3>
        <p class="meta" style="margin:0 0 10px"><?php echo e($lang === 'en' ? 'From paper ledger — no new invoice.' : 'من الدفتر الورقي — بدون فاتورة جديدة.'); ?></p>
        <form method="post" id="daysEditForm">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="update_days_left">
            <input type="hidden" name="id" id="daysSubId" value="">
            <label><?php echo e(t('days_left')); ?></label>
            <input type="number" name="days_left" id="daysInput" min="0" max="3650" step="1" required>
            <div id="daysPlanWrap" class="hidden" style="margin-top:10px">
                <label><?php echo e(t('package')); ?></label>
                <select name="plan_id" id="daysPlanSelect">
                    <option value=""><?php echo e($lang === 'en' ? 'Choose package…' : 'اختر الباقة…'); ?></option>
                    <?php foreach ($plansForDays as $p): ?>
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
  var modal = document.getElementById('daysModal');
  var idInput = document.getElementById('daysSubId');
  var daysInput = document.getElementById('daysInput');
  var planWrap = document.getElementById('daysPlanWrap');
  var planSelect = document.getElementById('daysPlanSelect');
  var cancelBtn = document.getElementById('daysCancelBtn');
  function openDays(id, days, hasSub) {
    if (!modal || !idInput || !daysInput) return;
    idInput.value = id;
    daysInput.value = (days === '' || days === null) ? '' : String(days);
    var needPlan = String(hasSub) !== '1';
    if (planWrap) {
      if (needPlan) {
        planWrap.classList.remove('hidden');
        planWrap.style.display = '';
        if (planSelect) planSelect.required = true;
      } else {
        planWrap.classList.add('hidden');
        planWrap.style.display = 'none';
        if (planSelect) {
          planSelect.required = false;
          planSelect.value = '';
        }
      }
    }
    modal.classList.remove('hidden');
    setTimeout(function () { daysInput.focus(); daysInput.select(); }, 30);
  }
  function closeDays() {
    if (modal) modal.classList.add('hidden');
  }
  document.addEventListener('click', function (e) {
    var t = e.target;
    while (t && t !== document) {
      if (t.classList && t.classList.contains('days-edit-btn')) {
        openDays(t.getAttribute('data-id'), t.getAttribute('data-days'), t.getAttribute('data-has-sub'));
        return;
      }
      t = t.parentNode;
    }
  });
  if (cancelBtn) cancelBtn.addEventListener('click', closeDays);
  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeDays();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDays();
  });
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
