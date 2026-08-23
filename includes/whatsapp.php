<?php

function whatsapp_send($config, $phone, $message, $type = 'text')
{
    $wa = isset($config['whatsapp']) ? $config['whatsapp'] : array();
    $phone = normalize_phone($phone);

    if (empty($wa['enabled'])) {
        return array(
            'success' => false,
            'skipped' => true,
            'response' => 'WhatsApp disabled in config',
            'phone' => $phone,
            'body' => $message,
            'type' => $type,
        );
    }

    $provider = isset($wa['provider']) ? $wa['provider'] : 'meta';
    if ($provider === 'local') {
        return whatsapp_send_local($wa, $phone, $message, $type);
    }

    return whatsapp_send_meta($wa, $phone, $message, $type);
}

function whatsapp_send_local($wa, $phone, $message, $type)
{
    $base = isset($wa['local_url']) ? rtrim($wa['local_url'], '/') : 'http://127.0.0.1:3001';
    $key = isset($wa['local_key']) ? (string) $wa['local_key'] : 'local-secret-change-me';
    $url = $base . '/send';

    $payload = array(
        'phone' => $phone,
        'message' => $message,
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'X-Api-Key: ' . $key,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ));

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($raw !== false && $code >= 200 && $code < 300);
    $decoded = null;
    $noWhatsapp = false;
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (isset($decoded['success']) && !$decoded['success']) {
                $ok = false;
            }
            $errText = '';
            if (isset($decoded['error'])) {
                $errText = (string) $decoded['error'];
            }
            if (isset($decoded['code']) && $decoded['code'] === 'no_whatsapp') {
                $noWhatsapp = true;
            }
            if ($errText !== '' && (
                stripos($errText, 'not on WhatsApp') !== false
                || stripos($errText, 'no_whatsapp') !== false
            )) {
                $noWhatsapp = true;
            }
        }
        if (!$ok && !$noWhatsapp && stripos((string) $raw, 'not on WhatsApp') !== false) {
            $noWhatsapp = true;
        }
    }

    return array(
        'success' => $ok,
        'skipped' => false,
        'no_whatsapp' => $noWhatsapp,
        'http_code' => $code,
        'response' => ($raw !== false) ? $raw : $err,
        'phone' => $phone,
        'body' => $message,
        'type' => $type,
    );
}

function whatsapp_send_meta($wa, $phone, $message, $type)
{
    $token = isset($wa['token']) ? (string) $wa['token'] : '';
    $phoneId = isset($wa['phone_number_id']) ? (string) $wa['phone_number_id'] : '';
    $version = isset($wa['api_version']) ? (string) $wa['api_version'] : 'v21.0';

    if ($token === '' || $phoneId === '' || strpos($token, 'YOUR_') !== false) {
        return array(
            'success' => false,
            'skipped' => true,
            'response' => 'WhatsApp credentials missing',
            'phone' => $phone,
            'body' => $message,
            'type' => $type,
        );
    }

    $url = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';

    $payload = array(
        'messaging_product' => 'whatsapp',
        'to' => $phone,
        'type' => 'text',
        'text' => array(
            'preview_url' => false,
            'body' => $message,
        ),
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ),
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ));

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($raw !== false && $code >= 200 && $code < 300);

    return array(
        'success' => $ok,
        'skipped' => false,
        'http_code' => $code,
        'response' => ($raw !== false) ? $raw : $err,
        'phone' => $phone,
        'body' => $message,
        'type' => $type,
    );
}

/**
 * رسالة عربية واضحة عند فشل واتساب
 */
function whatsapp_fail_user_message($result, $fallback = 'فشل إرسال واتساب')
{
    if (!empty($result['success'])) {
        return '';
    }
    if (!empty($result['no_whatsapp'])) {
        return 'لا يتوفر واتساب لدى المشترك';
    }
    $resp = isset($result['response']) ? (string) $result['response'] : '';
    if ($resp !== '' && (
        stripos($resp, 'not on WhatsApp') !== false
        || stripos($resp, 'no_whatsapp') !== false
    )) {
        return 'لا يتوفر واتساب لدى المشترك';
    }
    if (!empty($result['skipped'])) {
        return 'واتساب غير مفعّل بالإعدادات';
    }
    return $fallback;
}

function log_message($pdo, $subscriberId, $result)
{
    $stmt = $pdo->prepare(
        'INSERT INTO message_logs (subscriber_id, phone, message_type, body, success, response_json)
         VALUES (:subscriber_id, :phone, :message_type, :body, :success, :response_json)'
    );
    $stmt->execute(array(
        ':subscriber_id' => $subscriberId,
        ':phone' => isset($result['phone']) ? $result['phone'] : '',
        ':message_type' => isset($result['type']) ? $result['type'] : 'text',
        ':body' => isset($result['body']) ? $result['body'] : '',
        ':success' => !empty($result['success']) ? 1 : 0,
        ':response_json' => isset($result['response']) && is_string($result['response'])
            ? $result['response']
            : json_encode($result),
    ));
}

/**
 * فشل انحل لاحقاً: إرسال ناجح بعده لنفس المشترك (نفس النص أو نفس نوع الرسالة).
 * يرجع: [log_id => true, ...]
 */
function message_logs_resolved_map($pdo, $logRows)
{
    $map = array();
    $failIds = array();
    foreach ($logRows as $row) {
        if (empty($row['success']) && !empty($row['id']) && !empty($row['subscriber_id'])) {
            $failIds[] = (int) $row['id'];
        }
    }
    if (!$failIds) {
        return $map;
    }
    $failIds = array_values(array_unique($failIds));
    $in = implode(',', array_map('intval', $failIds));
    $sql = "SELECT m1.id
            FROM message_logs m1
            WHERE m1.id IN ($in)
              AND m1.success = 0
              AND EXISTS (
                  SELECT 1 FROM message_logs m2
                  WHERE m2.subscriber_id = m1.subscriber_id
                    AND m2.success = 1
                    AND m2.id > m1.id
                    AND (
                        m2.body = m1.body
                        OR REPLACE(m2.message_type, '_retry', '') = REPLACE(m1.message_type, '_retry', '')
                    )
              )";
    try {
        foreach ($pdo->query($sql)->fetchAll() as $r) {
            $map[(int) $r['id']] = true;
        }
    } catch (Exception $e) {
        // لا تكسر صفحة السجل
    }
    return $map;
}

/**
 * إعادة محاولة إرسال رسالة فاشلة من السجل
 * يرجع array($ok, $message)
 */
function retry_failed_message($pdo, $config, $logId, $subscriberId = 0)
{
    $sql = 'SELECT m.*, s.phone AS sub_phone, s.id AS sid
            FROM message_logs m
            JOIN subscribers s ON s.id = m.subscriber_id
            WHERE m.id = :id AND m.success = 0';
    $params = array(':id' => (int) $logId);
    if ($subscriberId > 0) {
        $sql .= ' AND m.subscriber_id = :sid';
        $params[':sid'] = (int) $subscriberId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $log = $stmt->fetch();
    if (!$log) {
        return array(false, 'الرسالة غير موجودة أو تم إرسالها مسبقاً');
    }
    $body = trim((string) $log['body']);
    if ($body === '') {
        return array(false, 'نص الرسالة فارغ');
    }
    $phone = !empty($log['sub_phone']) ? $log['sub_phone'] : $log['phone'];
    $type = (string) $log['message_type'];
    if ($type === '') {
        $type = 'text';
    }
    if (substr($type, -6) !== '_retry') {
        $type .= '_retry';
    }
    $result = whatsapp_send($config, $phone, $body, $type);
    // نخلي النوع الأصلي بالسجل أوضح للعرض
    $result['type'] = preg_replace('/_retry$/', '', (string) $log['message_type']);
    if ($result['type'] === '') {
        $result['type'] = 'text';
    }
    log_message($pdo, (int) $log['sid'], $result);
    if (!empty($result['success'])) {
        return array(true, 'تمت إعادة الإرسال بنجاح');
    }
    return array(false, whatsapp_fail_user_message($result, 'فشلت إعادة الإرسال — تأكد أن واتساب متصل'));
}

function activation_message($sub, $config, $extraNote = '')
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $amount = money_format_iqd($sub['monthly_price'], $currency);
    $tpl = '';
    if (isset($config['templates']['activation']) && trim((string) $config['templates']['activation']) !== '') {
        $tpl = $config['templates']['activation'];
    }
    if ($tpl !== '') {
        $msg = tpl_fill($tpl, array(
            'name' => $sub['name'],
            'package' => $sub['service_name'],
            'from' => $sub['start_date'],
            'to' => $sub['end_date'],
            'amount' => $amount,
        ));
    } else {
        $msg = "مرحباً {$sub['name']}\n"
            . "تم تفعيل خدمة الإنترنت ({$sub['service_name']})\n"
            . 'من تاريخ ' . $sub['start_date'] . ' إلى تاريخ ' . $sub['end_date'] . "\n"
            . 'المبلغ: ' . $amount;
    }
    if ($extraNote !== '') {
        $msg .= "\n" . $extraNote;
    }
    $senderNote = '';
    if (isset($config['whatsapp']['sender_note'])) {
        $senderNote = trim((string) $config['whatsapp']['sender_note']);
    }
    if ($senderNote !== '') {
        $msg .= "\n" . $senderNote;
    }
    return $msg;
}

function reminder_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $debt = money_format_iqd(isset($row['debt_total']) ? $row['debt_total'] : $row['amount'], $currency);
    $amount = money_format_iqd($row['amount'], $currency);
    $month = month_short_label($row['month_label']);
    $notes = isset($row['notes']) ? trim((string) $row['notes']) : '';
    $tpl = '';
    if (isset($config['templates']['debt_remind']) && trim((string) $config['templates']['debt_remind']) !== '') {
        $tpl = $config['templates']['debt_remind'];
    }
    if ($tpl !== '') {
        return tpl_fill($tpl, array(
            'name' => $row['name'],
            'debt' => $debt,
            'amount' => $amount,
            'month' => $month,
            'notes' => $notes,
        ));
    }
    return 'السلام عليكم ' . $row['name'] . ' يرجى تسديد الديون البالغة ' . $debt . ' لتجنب قطع الخدمة';
}

function debt_created_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $amount = money_format_iqd($row['amount'], $currency);
    $month = month_short_label($row['month_label']);
    $notes = isset($row['notes']) ? trim((string) $row['notes']) : '';
    $debt = money_format_iqd(isset($row['debt_total']) ? $row['debt_total'] : $row['amount'], $currency);
    $tpl = '';
    if (isset($config['templates']['debt_created']) && trim((string) $config['templates']['debt_created']) !== '') {
        $tpl = $config['templates']['debt_created'];
    }
    if ($tpl !== '') {
        return tpl_fill($tpl, array(
            'name' => $row['name'],
            'amount' => $amount,
            'month' => $month,
            'notes' => $notes,
            'debt' => $debt,
        ));
    }
    $msg = "مرحباً {$row['name']}\nتم تسجيل دين بمبلغ {$amount}";
    if ($month !== '') {
        $msg .= "\nعن: {$month}";
    }
    if ($notes !== '') {
        $msg .= "\n{$notes}";
    }
    return $msg;
}

/**
 * رسالة الأيام المتبقية (+ سطر الدين إن وُجد)
 * $row: name, days, package?, debt_total?
 */
function days_left_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $days = isset($row['days']) ? (int) $row['days'] : 0;
    $debtTotal = isset($row['debt_total']) ? (float) $row['debt_total'] : 0;
    $debtFmt = $debtTotal > 0 ? money_format_iqd($debtTotal, $currency) : '';
    $package = isset($row['package']) ? (string) $row['package'] : '';
    $tpl = '';
    if (isset($config['templates']['days_left']) && trim((string) $config['templates']['days_left']) !== '') {
        $tpl = $config['templates']['days_left'];
    }
    if ($tpl !== '') {
        $msg = tpl_fill($tpl, array(
            'name' => $row['name'],
            'days' => (string) $days,
            'package' => $package,
            'debt' => $debtFmt,
        ));
    } else {
        $msg = 'السلام عليكم ' . $row['name'] . "\nتبقى لديك " . $days . ' يوم على الاشتراك';
        if ($package !== '') {
            $msg .= ' (' . $package . ')';
        }
    }
    // إذا القالب ما فيه {debt} والدين موجود — نضيف سطر الدين
    if ($debtTotal > 0 && strpos($msg, $debtFmt) === false) {
        $msg .= "\nعليك دين بمبلغ " . $debtFmt;
    }
    return $msg;
}

/**
 * رسالة تأخر التسديد بعد أيام من التفعيل
 * $row: name, days_passed, debt_total, package?
 */
function unpaid_overdue_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $daysPassed = isset($row['days_passed']) ? (int) $row['days_passed'] : 0;
    $debtTotal = isset($row['debt_total']) ? (float) $row['debt_total'] : 0;
    $debtFmt = money_format_iqd($debtTotal, $currency);
    $package = isset($row['package']) ? (string) $row['package'] : '';
    $tpl = '';
    if (isset($config['templates']['unpaid_overdue']) && trim((string) $config['templates']['unpaid_overdue']) !== '') {
        $tpl = $config['templates']['unpaid_overdue'];
    }
    if ($tpl !== '') {
        return tpl_fill($tpl, array(
            'name' => $row['name'],
            'days_passed' => (string) $daysPassed,
            'debt' => $debtFmt,
            'amount' => $debtFmt,
            'package' => $package,
        ));
    }
    return 'السلام عليكم ' . $row['name'] . "\n"
        . 'مضى على تفعيل خطك ' . $daysPassed . " أيام\n"
        . 'يرجى تسديد الديون البالغة ' . $debtFmt . "\n"
        . 'وبعكسه سيتم إيقاف الخدمة';
}

function unpaid_remind_after_days($config)
{
    if (isset($config['unpaid_remind_after_days'])) {
        return max(1, (int) $config['unpaid_remind_after_days']);
    }
    return 7;
}

/** أيام مضت منذ تاريخ التفعيل (من يوم التفعيل) */
function days_since_date($dateYmd)
{
    $start = strtotime(date('Y-m-d', strtotime($dateYmd)));
    $today = strtotime(date('Y-m-d'));
    if ($start === false || $today === false) {
        return 0;
    }
    return (int) floor(($today - $start) / 86400);
}

function payment_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $amount = money_format_iqd($row['amount'], $currency);
    if (!empty($row['about'])) {
        $month = trim((string) $row['about']);
    } elseif (function_exists('invoice_debt_label')) {
        $month = invoice_debt_label($row);
    } else {
        $month = month_short_label(isset($row['month_label']) ? $row['month_label'] : '');
        $notes = isset($row['notes']) ? trim((string) $row['notes']) : '';
        if ($notes !== '' && (empty($row['month_label']) || !preg_match('/^\d{4}-\d{1,2}$/', (string) $row['month_label']))) {
            $month .= ' — ' . $notes;
        }
    }
    $remaining = isset($row['remaining']) ? money_format_iqd($row['remaining'], $currency) : '';
    $hasRemaining = array_key_exists('remaining', $row);
    $tpl = '';
    if (isset($config['templates']['payment_ok']) && trim((string) $config['templates']['payment_ok']) !== '') {
        $tpl = $config['templates']['payment_ok'];
    }
    if ($tpl !== '') {
        $msg = tpl_fill($tpl, array(
            'name' => $row['name'],
            'amount' => $amount,
            'month' => $month,
            'debt' => $amount,
            'remaining' => $remaining,
        ));
        if (strpos($tpl, '{amount}') === false && strpos($tpl, '{debt}') === false) {
            $msg .= "\nتم استلام مبلغ {$amount}";
        }
        if ($hasRemaining && strpos($tpl, '{remaining}') === false) {
            $msg .= "\nالمتبقي عليك: {$remaining}";
        }
        return $msg;
    }
    $msg = "مرحباً {$row['name']}\nتم استلام مبلغ {$amount}\nعن: {$month}";
    if ($hasRemaining) {
        $msg .= "\nالمتبقي عليك: {$remaining}";
    } else {
        $msg .= "\nشكراً لتسديدك.";
    }
    return $msg;
}

/**
 * رسالة قرب انتهاء الاشتراك (تلقائي)
 * $row: name, days, package, from?, to?
 */
function expiry_soon_message($row, $config)
{
    $package = isset($row['package']) ? (string) $row['package'] : '';
    $days = isset($row['days']) ? (int) $row['days'] : 0;
    $from = isset($row['from']) ? (string) $row['from'] : '';
    $to = isset($row['to']) ? (string) $row['to'] : '';
    $tpl = '';
    if (isset($config['templates']['expiry_soon']) && trim((string) $config['templates']['expiry_soon']) !== '') {
        $tpl = $config['templates']['expiry_soon'];
    }
    if ($tpl !== '') {
        return tpl_fill($tpl, array(
            'name' => $row['name'],
            'days' => (string) $days,
            'package' => $package,
            'from' => $from,
            'to' => $to,
        ));
    }
    $msg = 'السلام عليكم ' . $row['name'] . "\nتبقى لديك " . $days . ' يوم على الاشتراك';
    if ($package !== '') {
        $msg .= ' (' . $package . ')';
    }
    if ($to !== '') {
        $msg .= "\nينتهي بتاريخ " . $to;
    }
    $msg .= "\nيرجى التجديد لتجنب انقطاع الخدمة";
    return $msg;
}

function ensure_subscription_expiry_remind_column($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM subscriptions LIKE 'expiry_remind_for_end'");
        if ($chk && $chk->fetch()) {
            return;
        }
        $pdo->exec('ALTER TABLE subscriptions ADD COLUMN expiry_remind_for_end DATE NULL DEFAULT NULL');
    } catch (Exception $e) {
        // ignore
    }
}

/**
 * إرسال تذكير لمن ينتهي اشتراكهم خلال N أيام
 */
function run_expiry_soon_reminders($pdo, $config, $limit = 40)
{
    $out = array('sent' => 0, 'failed' => 0, 'skipped' => 0, 'checked' => 0);
    if (empty($config['expiry_auto_remind_enabled'])) {
        return $out;
    }
    if (empty($config['whatsapp']['enabled'])) {
        return $out;
    }
    $daysN = isset($config['expiry_auto_remind_days']) ? (int) $config['expiry_auto_remind_days'] : 1;
    if ($daysN < 0) {
        $daysN = 0;
    }
    if ($daysN > 60) {
        $daysN = 60;
    }
    ensure_subscription_expiry_remind_column($pdo);
    $pdo->exec(
        "UPDATE subscriptions SET status = 'expired'
         WHERE status = 'active' AND end_date < CURDATE()"
    );

    $limit = max(1, (int) $limit);
    $sql = 'SELECT sub.id AS sub_id, sub.subscriber_id, sub.service_name, sub.start_date, sub.end_date,
                   s.name, s.phone
            FROM subscriptions sub
            JOIN subscribers s ON s.id = sub.subscriber_id
            WHERE sub.status = \'active\'
              AND sub.end_date >= CURDATE()
              AND sub.end_date <= DATE_ADD(CURDATE(), INTERVAL ' . (int) $daysN . ' DAY)
              AND (sub.expiry_remind_for_end IS NULL OR sub.expiry_remind_for_end <> sub.end_date)
            ORDER BY sub.end_date ASC
            LIMIT ' . $limit;
    $rows = $pdo->query($sql)->fetchAll();
    $out['checked'] = count($rows);

    foreach ($rows as $row) {
        $info = subscription_days_info($row['start_date'], $row['end_date']);
        $body = expiry_soon_message(array(
            'name' => $row['name'],
            'days' => (int) $info['left'],
            'package' => $row['service_name'],
            'from' => $row['start_date'],
            'to' => $row['end_date'],
        ), $config);
        $result = whatsapp_send($config, $row['phone'], $body, 'expiry_auto');
        log_message($pdo, (int) $row['subscriber_id'], $result);
        if (!empty($result['success'])) {
            $pdo->prepare(
                'UPDATE subscriptions SET expiry_remind_for_end = :e WHERE id = :id'
            )->execute(array(':e' => $row['end_date'], ':id' => (int) $row['sub_id']));
            $out['sent']++;
            usleep(250000);
        } elseif (!empty($result['skipped'])) {
            $out['skipped']++;
        } else {
            $out['failed']++;
            usleep(150000);
        }
    }
    return $out;
}

/** تشغيل خفيف من الواجهة (مرة كل ~10 دقائق) */
function maybe_run_expiry_auto_reminders($pdo, $config)
{
    if (empty($config['expiry_auto_remind_enabled'])) {
        return;
    }
    $lock = __DIR__ . '/../config/expiry_auto_remind.lock';
    $now = time();
    if (is_file($lock)) {
        $prev = (int) trim((string) @file_get_contents($lock));
        if ($prev > 0 && ($now - $prev) < 600) {
            return;
        }
    }
    @file_put_contents($lock, (string) $now);
    @run_expiry_soon_reminders($pdo, $config, 15);
}
