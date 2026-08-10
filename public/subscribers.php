<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

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
        $addDebts = post('add_debts') === '1';
        $sendDebtWa = post('send_debt_whatsapp') === '1';

        if ($name === '' || $phone === '') {
            flash('error', 'الاسم والهاتف مطلوبان');
            redirect('subscribers.php?add=1');
        }
        if (subscriber_name_taken($pdo, $name)) {
            flash('error', 'الاسم مكرر — اختر اسماً مختلفاً');
            redirect('subscribers.php?add=1');
        }

        $newId = 0;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO subscribers (name, phone, address, notes) VALUES (:name, :phone, :address, :notes)'
            );
            $stmt->execute(array(
                ':name' => $name,
                ':phone' => $phone,
                ':address' => ($address !== '' && $address !== null) ? $address : null,
                ':notes' => ($notes !== '' && $notes !== null) ? $notes : null,
            ));
            $newId = (int) $pdo->lastInsertId();
            $debtInfo = array('count' => 0, 'sum' => 0.0);
            if ($addDebts) {
                $debtInfo = insert_opening_debts($pdo, $newId, $_POST);
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
                flash('success', 'تم إضافة المشترك بنجاح');
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

    if ($action === 'delete') {
        $id = (int) post('id', '0');
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

    if ($action === 'update_days_left') {
        $sid = (int) post('id', '0');
        $days = (int) post('days_left', '0');
        $planId = (int) post('plan_id', '0');
        list($ok, $msg) = apply_subscriber_days_left($pdo, $sid, $days, $planId);
        flash($ok ? 'success' : 'error', $msg);
        redirect('subscribers.php');
    }

    if ($action === 'remind_debt') {
        $id = (int) post('id', '0');
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
    return '';
}

// بحث مباشر يستبدل صفوف الجدول (بدون نافذة منبثقة)
if (isset($_GET['live']) && $_GET['live'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $paramsLive = array();
    $whereLive = subscribers_search_where($q, $paramsLive);
    $subLive = isset($_GET['sub']) ? (string) $_GET['sub'] : '';
    $whereLive .= subscribers_sub_filter_sql($subLive);
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
        $html = '<tr><td colspan="9">' . e($lang === 'en' ? 'No matches' : 'ماكو نتيجة') . '</td></tr>';
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
    $monthLabels = array();
    $monthTitle = '';
    if (!empty($row['debt_months'])) {
        $monthsParts = explode(',', $row['debt_months']);
        foreach ($monthsParts as $ymPart) {
            $ymPart = trim($ymPart);
            if ($ymPart === '') {
                continue;
            }
            $monthLabels[] = month_short_label($ymPart, true);
        }
    } elseif (!empty($row['active_start']) && !empty($row['active_end'])) {
        $monthLabels[] = month_short_label(date('Y-m', strtotime($row['active_start'])));
    }
    if (!$monthLabels) {
        return '<td class="month-cell"><span style="color:var(--muted)">-</span></td>';
    }
    $monthTitle = implode(' + ', $monthLabels);
    $detailUrl = 'subscriber.php?id=' . (int) $row['id'] . '&tab=list#debts';
    $html = '<td class="month-cell" title="' . e($monthTitle) . '"><div class="month-chips">';
    $show = array_slice($monthLabels, 0, 2);
    foreach ($show as $lab) {
        $html .= '<a class="month-chip" href="' . e($detailUrl) . '">' . e($lab) . '</a>';
    }
    $extra = count($monthLabels) - count($show);
    if ($extra > 0) {
        $html .= '<a class="month-more" href="' . e($detailUrl) . '">+' . (int) $extra . '</a>';
    }
    $html .= '</div></td>';
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
    $isLeft = !$hasActive && (int) $row['sub_count'] > 0;
    if ($debt > 0) {
        $rowClass = 'row-debt';
    } elseif ($isLeft) {
        $rowClass = 'row-left';
    } else {
        $rowClass = 'row-normal';
    }

    $html = '<tr class="' . e($rowClass) . '" data-search="' . e($searchText) . '" data-id="' . (int) $row['id'] . '" id="sub-row-' . (int) $row['id'] . '">';
    $html .= '<td>' . (int) $n . '</td>';
    $html .= '<td><a class="sub-name" href="subscriber.php?id=' . (int) $row['id'] . '">' . e($row['name']) . '</a>';
    $html .= rental_badge_html($row);
    $html .= '</td>';
    $html .= '<td>' . e(format_phone_display($row['phone'])) . '</td>';
    $html .= '<td>' . e(!empty($row['active_service']) ? $row['active_service'] : '-') . '</td>';
    $html .= render_subscriber_month_cell($row, $lang);
    $html .= '<td>';
    if ($daysInfo) {
        $daysLeftVal = (int) $daysInfo['left'];
        $daysCls = $daysLeftVal < 0 ? ' days-neg' : '';
        $hasSubDays = (!empty($row['active_end']) || !empty($row['last_end'])) ? '1' : '0';
        $html .= '<div class="days-cell-wrap">';
        $html .= '<button type="button" class="days-edit-btn" data-id="' . (int) $row['id'] . '" data-days="' . $daysLeftVal . '" data-has-sub="' . $hasSubDays . '" title="' . e($lang === 'en' ? 'Edit days left' : 'تعديل الأيام المتبقية') . '">';
        $html .= '<span class="days-num' . $daysCls . '">' . $daysLeftVal . '</span></button>';
        $html .= '<form method="post" class="inline-form days-wa-form" title="' . e($lang === 'en' ? 'Send days left (+ debt if any)' : 'إرسال الأيام المتبقية (+ الدين إن وجد)') . '">';
        $html .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
        $html .= '<input type="hidden" name="action" value="remind_days">';
        $html .= '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
        $html .= '<button class="days-wa-btn" type="submit">WA</button></form>';
        $html .= '</div>';
    } else {
        $html .= '<button type="button" class="days-edit-btn days-empty" data-id="' . (int) $row['id'] . '" data-days="0" data-has-sub="0" title="' . e($lang === 'en' ? 'Set days from ledger' : 'تسجيل الأيام من الدفتر') . '">—</button>';
    }
    $html .= '</td>';
    $html .= '<td class="debt-cell">';
    if ($debt > 0) {
        $html .= '<span class="debt-pill">' . e(money_format_iqd($debt, $config['currency'])) . '</span>';
    } else {
        $html .= '<span class="debt-ok">' . e(money_format_iqd(0, $config['currency'])) . '</span>';
    }
    $html .= '</td>';
    $html .= '<td class="msg-status-cell" title="' . e($msgShort) . '"><span class="msg-status-row">';
    if (!$hasMsg) {
        $html .= '<span class="dot-msg off"></span>';
    } elseif ($msgOk) {
        $html .= '<span class="dot-msg ok"></span>';
    } elseif ($noWa) {
        $html .= '<span class="dot-msg fail"></span>';
        $html .= '<span class="msg-x" title="' . e('لا يتوفر واتساب لدى المشترك') . '">✕</span>';
    } else {
        $html .= '<span class="dot-msg fail"></span>';
        if (!empty($row['last_msg_id'])) {
            $html .= '<form method="post" class="inline-form msg-retry-form">';
            $html .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
            $html .= '<input type="hidden" name="action" value="retry_message">';
            $html .= '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
            $html .= '<input type="hidden" name="log_id" value="' . (int) $row['last_msg_id'] . '">';
            $html .= '<button class="msg-retry-btn" type="submit" title="' . e($lang === 'en' ? 'Retry send' : 'إعادة إرسال') . '">↻</button>';
            $html .= '</form>';
        }
    }
    $html .= '</span></td>';
    $html .= '<td class="acts-cell"><div class="row-actions">';
    $html .= '<a class="link-act act-blue" href="activate.php?subscriber_id=' . (int) $row['id'] . '">' . e(t('activate')) . '</a>';
    if ($debt > 0) {
        $html .= '<a class="link-act act-orange" href="debts.php?status=unpaid&subscriber_id=' . (int) $row['id'] . '">' . e(t('pay_debt')) . '</a>';
        $html .= '<form method="post" class="inline-form">';
        $html .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
        $html .= '<input type="hidden" name="action" value="remind_debt">';
        $html .= '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
        $html .= '<button class="link-act" type="submit">' . e(t('remind')) . '</button></form>';
    }
    // زر تنبيه التأخر: مفعّل + دين + مضى N يوم من التفعيل
    $afterDays = unpaid_remind_after_days($config);
    $daysPassed = (!empty($row['active_start'])) ? days_since_date($row['active_start']) : 0;
    if ($hasActive && $debt > 0 && $daysPassed >= $afterDays) {
        $html .= '<form method="post" class="inline-form">';
        $html .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
        $html .= '<input type="hidden" name="action" value="remind_unpaid_overdue">';
        $html .= '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
        $html .= '<button class="link-act act-warn" type="submit" title="' . e($lang === 'en'
            ? ('Unpaid for ' . $daysPassed . ' days — send cut warning')
            : ('مضى ' . $daysPassed . ' يوم بدون تسديد — إرسال تنبيه قطع')) . '">';
        $html .= e($lang === 'en' ? 'Pay warn' : 'تسديد/قطع') . '</button></form>';
    }
    $html .= '<form method="post" class="inline-form" onsubmit="return confirm(\'' . e(t('confirm_delete')) . '\');">';
    $html .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    $html .= '<input type="hidden" name="action" value="delete">';
    $html .= '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
    $html .= '<button class="link-act act-red" type="submit">' . e(t('delete')) . '</button></form>';
    $html .= '</div></td></tr>';
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
$subFilter = isset($_GET['sub']) ? (string) $_GET['sub'] : '';
$subSql = subscribers_sub_filter_sql($subFilter);
if ($subSql === '') {
    $subFilter = '';
} else {
    $where .= $subSql;
}

$countSql = 'SELECT COUNT(*) FROM subscribers s WHERE ' . $where;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();

$totalAll = (int) $pdo->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();
$showAdd = isset($_GET['add']) && $_GET['add'] === '1';
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
    : t('subscribers');
render_header($pageTitle, 'subscribers', $showAdd ? '' : 'إضافة وإدارة أرقام المشتركين');

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
  font-size: 13px !important;
  line-height: 1.3 !important;
  height: 38px !important;
  white-space: nowrap;
  vertical-align: middle !important;
}
#subsTable.table-compact th { height: 30px !important; font-size: 12px !important; }
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
#subsTable .debt-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 6px;
  background: rgba(255, 159, 10, 0.15);
  color: #b86a00;
  font-size: 12px;
  font-weight: 800;
}
#subsTable .debt-ok { color: #15803d; font-weight: 800; font-size: 12px; }
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
#subsTable td.month-cell {
  white-space: normal !important;
  max-width: 118px;
  height: auto !important;
  min-height: 38px;
}
#subsTable .month-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  align-items: center;
}
#subsTable .month-chip,
#subsTable a.month-chip {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 6px;
  background: rgba(47, 109, 246, 0.10);
  color: #1c4fd8;
  font-size: 11px;
  font-weight: 800;
  line-height: 1.35;
  text-decoration: none;
  cursor: pointer;
}
#subsTable a.month-chip:hover,
#subsTable a.month-more:hover {
  background: rgba(47, 109, 246, 0.18);
  text-decoration: underline;
}
#subsTable .month-more,
#subsTable a.month-more {
  font-size: 11px;
  font-weight: 800;
  color: #6b7a88;
  text-decoration: none;
}
#subsTable tbody tr.row-normal:nth-child(even) td {
  background: rgba(28, 36, 48, 0.035);
}
#subsTable tbody tr.row-debt td {
  background: rgba(255, 159, 10, 0.12) !important;
}
#subsTable tbody tr.row-left td {
  background: transparent !important;
  color: rgba(28, 36, 48, 0.55);
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
<div class="panel">
    <?php if ($showAdd): ?>
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
                    <label><?php echo e(t('address')); ?> <span class="meta">(<?php echo e($lang === 'en' ? 'optional' : 'اختياري'); ?>)</span></label>
                    <input name="address" placeholder="<?php echo e($lang === 'en' ? 'Optional' : 'اختياري'); ?>">
                </div>
                <div>
                    <label><?php echo e(t('notes')); ?></label>
                    <input name="notes" placeholder="<?php echo e($lang === 'en' ? 'Optional' : 'اختياري'); ?>">
                </div>
            </div>

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

            <div class="actions" style="margin-top:16px">
                <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Add' : 'إضافة'); ?></button>
                <a class="btn ghost" href="subscribers.php"><?php echo e($lang === 'en' ? 'Back' : 'رجوع'); ?></a>
            </div>
        </form>
    </div>
    <?php else: ?>
    <div class="actions" style="margin-top:0;margin-bottom:10px">
        <a class="btn" href="subscribers.php?add=1"><?php echo e(t('add_subscriber')); ?></a>
        <button type="button" class="btn ghost" onclick="window.print()"><?php echo e(t('print')); ?></button>
    </div>
    <?php endif; ?>
</div>

<?php if (!$showAdd): ?>
<div class="panel">
    <div class="actions" style="margin-top:0;margin-bottom:12px">
        <form method="get" action="subscribers.php" id="subsSearchForm" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1" autocomplete="off">
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
            <div class="search-suggest-wrap">
                <input id="filterInput" name="q" value="<?php echo e($q); ?>" placeholder="بحث بالاسم أو الرقم..." style="max-width:280px;width:100%" autocomplete="off">
            </div>
            <button class="btn secondary sm" type="submit"><?php echo e($lang === 'en' ? 'Search' : 'بحث'); ?></button>
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
        <p class="meta" style="margin:-4px 0 12px;font-weight:700">
            <?php
            $subTitles = array(
                'active' => ($lang === 'en' ? 'Filter: Active' : 'عرض: الفعالين'),
                'expired' => ($lang === 'en' ? 'Filter: Expired / no sub' : 'عرض: المنتهية / بدون اشتراك'),
                'soon' => ($lang === 'en' ? 'Filter: Expiring in 3 days' : 'عرض: على وشك الانتهاء (3 أيام)'),
                'today' => ($lang === 'en' ? 'Filter: Ends today' : 'عرض: ينتهي اليوم'),
            );
            echo e(isset($subTitles[$subFilter]) ? $subTitles[$subFilter] : $subFilter);
            ?>
        </p>
    <?php endif; ?>
    <div class="table-wrap">
        <table id="subsTable" class="table-compact">
            <thead>
            <tr>
                <th><?php echo subs_sort_link('id', '#', $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th><?php echo subs_sort_link('name', t('name'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th><?php echo subs_sort_link('phone', t('phone'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th><?php echo subs_sort_link('package', t('package'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th><?php echo subs_sort_link('month', $lang === 'en' ? 'Month' : 'الشهر', $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th><?php echo subs_sort_link('days', t('days_left'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th><?php echo subs_sort_link('debt', t('debts_total'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th><?php echo subs_sort_link('msg', t('msg_status'), $sortKey, $sortDir, $q, $perPageRaw); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody id="subsTableBody">
            <?php
            $n = $offset + 1;
            if (!$rows) {
                echo '<tr><td colspan="9">' . e($lang === 'en' ? 'No subscribers' : 'ماكو مشتركين') . '</td></tr>';
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

  function fetchLive(q) {
    var myReq = ++liveReq;
    if (!tbody) return;
    if (!q) {
      tbody.innerHTML = originalHtml;
      if (pager) pager.style.display = originalPagerDisplay || '';
      return;
    }
    var url = 'subscribers.php?live=1&q=' + encodeURIComponent(q);
    <?php if ($subFilter !== ''): ?>
    url += '&sub=<?php echo rawurlencode($subFilter); ?>';
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
      } catch (e) {}
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
<?php endif; ?>

<?php if (!$showAdd): ?>
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
