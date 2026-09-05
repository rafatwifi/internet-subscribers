<?php

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect($path)
{
    header('Location: ' . $path);
    exit;
}

function flash($type, $message)
{
    $_SESSION['flash'] = array('type' => $type, 'message' => $message);
}

function get_flash()
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function money_format_iqd($amount, $currency = 'د.ع')
{
    return number_format((float) $amount, 0, '.', ',') . ' ' . $currency;
}

function normalize_phone($phone)
{
    $phone = preg_replace('/\D+/', '', (string) $phone);
    if ($phone === null) {
        $phone = '';
    }

    if (strpos($phone, '07') === 0 && strlen($phone) === 11) {
        $phone = '964' . substr($phone, 1);
    }
    if (strpos($phone, '7') === 0 && strlen($phone) === 10) {
        $phone = '964' . $phone;
    }
    return $phone;
}

function format_phone_display($phone)
{
    $p = preg_replace('/\D+/', '', (string) $phone);
    if ($p === null) {
        $p = '';
    }
    if (strpos($p, '964') === 0 && strlen($p) >= 12) {
        return '0' . substr($p, 3);
    }
    return (string) $phone;
}

function add_one_month($dateYmd)
{
    $ts = strtotime($dateYmd . ' +1 month');
    if ($ts === false) {
        return $dateYmd;
    }
    return date('Y-m-d', $ts);
}

/**
 * مدة الاشتراك الافتراضية: days_30 | calendar_month
 */
function subscription_period_mode($config = null)
{
    if ($config === null && isset($GLOBALS['config'])) {
        $config = $GLOBALS['config'];
    }
    $mode = 'days_30';
    if (is_array($config) && isset($config['subscription_period_mode'])) {
        $mode = (string) $config['subscription_period_mode'];
    }
    return ($mode === 'calendar_month') ? 'calendar_month' : 'days_30';
}

/** تاريخ نهاية الاشتراك الافتراضي من تاريخ البداية */
function subscription_period_end($startDate, $config = null)
{
    $startDate = (string) $startDate;
    if ($startDate === '' || strtotime($startDate) === false) {
        $startDate = date('Y-m-d');
    }
    if (subscription_period_mode($config) === 'calendar_month') {
        return add_one_month($startDate);
    }
    // 30 يوم ثابت: 30-7 → 29-8
    return date('Y-m-d', strtotime($startDate . ' +30 days'));
}

/** عدد الأيام بين البداية والنهاية حسب وضع النظام */
function subscription_period_default_days($startDate, $config = null)
{
    $start = strtotime((string) $startDate);
    $end = strtotime(subscription_period_end($startDate, $config));
    if ($start === false || $end === false) {
        return 30;
    }
    return max(0, (int) round(($end - $start) / 86400));
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        if (function_exists('random_bytes')) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        } else {
            $_SESSION['csrf'] = md5(uniqid((string) mt_rand(), true));
        }
    }
    return $_SESSION['csrf'];
}

function verify_csrf($token)
{
    return is_string($token)
        && isset($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}

function post($key, $default = null)
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function arabic_month_label($ym)
{
    $parts = explode('-', $ym);
    $y = isset($parts[0]) ? $parts[0] : '';
    $m = isset($parts[1]) ? $parts[1] : '';
    $months = array(
        '01' => 'كانون الثاني', '02' => 'شباط', '03' => 'آذار',
        '04' => 'نيسان', '05' => 'أيار', '06' => 'حزيران',
        '07' => 'تموز', '08' => 'آب', '09' => 'أيلول',
        '10' => 'تشرين الأول', '11' => 'تشرين الثاني', '12' => 'كانون الأول',
    );
    $monthName = isset($months[$m]) ? $months[$m] : $m;
    return $monthName . ' ' . $y;
}

/**
 * تسمية شهر للقوائم: 1-1-2026 / 1-2-2026 (اليوم-الشهر-السنة)
 * السنة تتبع شهر الخيار نفسه (تتغير تلقائياً مع مرور السنين)
 */
function month_ym_label_numeric($ym)
{
    $parts = explode('-', (string) $ym);
    $y = isset($parts[0]) && preg_match('/^\d{4}$/', $parts[0]) ? $parts[0] : date('Y');
    $m = isset($parts[1]) ? (int) $parts[1] : (int) date('n');
    if ($m < 1) {
        $m = 1;
    }
    if ($m > 12) {
        $m = 12;
    }
    return '1-' . $m . '-' . $y;
}

/**
 * خيارات الأشهر (للتوافق) — مجمّعة بالسنة
 */
function month_ym_options_html($selected = '', $monthsBack = 11, $monthsFwd = 2)
{
    $selected = trim((string) $selected);
    if ($selected === '') {
        $selected = date('Y-m');
    }
    $byYear = array();
    for ($i = -$monthsBack; $i <= $monthsFwd; $i++) {
        $ts = strtotime(date('Y-m-01') . ' ' . ($i >= 0 ? '+' : '') . $i . ' month');
        if ($ts === false) {
            continue;
        }
        $ym = date('Y-m', $ts);
        $y = date('Y', $ts);
        if (!isset($byYear[$y])) {
            $byYear[$y] = array();
        }
        $byYear[$y][] = $ym;
    }
    $html = '';
    foreach ($byYear as $y => $months) {
        $html .= '<optgroup label="' . htmlspecialchars((string) $y, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($months as $ym) {
            $label = month_ym_label_numeric($ym);
            $sel = ($ym === $selected) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($ym, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

/**
 * اختيار شهر مضغوط: شهر + سنة (ما يملأ الشاشة)
 * القيمة المخفية Y-m بنفس اسم الحقل
 */
function month_ym_picker_html($name = 'debt_month[]', $selected = '')
{
    $selected = trim((string) $selected);
    if ($selected === '' || !preg_match('/^\d{4}-\d{1,2}$/', $selected)) {
        $selected = date('Y-m');
    }
    $parts = explode('-', $selected);
    $year = (int) $parts[0];
    $month = (int) $parts[1];
    if ($month < 1) {
        $month = 1;
    }
    if ($month > 12) {
        $month = 12;
    }
    $curY = (int) date('Y');
    $years = array();
    for ($y = $curY - 1; $y <= $curY + 1; $y++) {
        $years[] = $y;
    }
    if (!in_array($year, $years, true)) {
        $years[] = $year;
        sort($years);
    }
    $ymVal = $year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT);
    $html = '<div class="ym-picker">';
    $html .= '<select class="ym-month" aria-label="month">';
    for ($m = 1; $m <= 12; $m++) {
        $lab = '1-' . $m . '-' . $year;
        $sel = ($m === $month) ? ' selected' : '';
        $html .= '<option value="' . $m . '"' . $sel . '>' . htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    $html .= '</select>';
    $html .= '<select class="ym-year" aria-label="year">';
    foreach ($years as $y) {
        $sel = ((int) $y === $year) ? ' selected' : '';
        $html .= '<option value="' . (int) $y . '"' . $sel . '>' . (int) $y . '</option>';
    }
    $html .= '</select>';
    $html .= '<input type="hidden" class="ym-value" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="'
        . htmlspecialchars($ymVal, ENT_QUOTES, 'UTF-8') . '">';
    $html .= '</div>';
    return $html;
}

function month_short_label($ym, $shortOnly = false)
{
    $ym = trim((string) $ym);
    if ($ym === '') {
        $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
        return $lang === 'en' ? 'Item' : 'غرض';
    }
    // ليس شهر تقويمي (مثل غرض / راوتر)
    if (!preg_match('/^\d{4}-\d{1,2}$/', $ym)) {
        return $ym;
    }
    $parts = explode('-', $ym);
    $y = isset($parts[0]) ? $parts[0] : date('Y');
    $m = isset($parts[1]) ? (int) $parts[1] : (int) date('n');
    if ($m < 1) {
        $m = 1;
    }
    if ($m > 12) {
        $m = 12;
    }
    $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
    if ($lang === 'en') {
        $label = 'M' . $m;
    } else {
        $label = 'ش' . $m;
    }
    if ($shortOnly) {
        return $label;
    }
    return $label . ' ' . $y;
}

function tpl_fill($template, $vars)
{
    $out = (string) $template;
    foreach ($vars as $k => $v) {
        $out = str_replace(array('{' . $k . '}', '$' . $k), (string) $v, $out);
    }
    return $out;
}

function subscription_days_info($startDate, $endDate)
{
    $today = strtotime(date('Y-m-d'));
    $start = strtotime($startDate);
    $end = strtotime($endDate);
    if ($start === false || $end === false) {
        return array('total' => 30, 'left' => 0, 'pct' => 0);
    }
    if ($end < $start) {
        // نهاية قبل البداية: اعتبر المتبقي سالباً من اليوم
        $left = (int) ceil(($end - $today) / 86400);
        return array('total' => 30, 'left' => $left, 'pct' => 0);
    }
    $total = max(1, (int) round(($end - $start) / 86400));
    $left = (int) ceil(($end - $today) / 86400);
    // لا نقصّر السالب إلى 0 — نعرض كم يوم منتهي
    if ($left > $total) {
        $left = $total;
    }
    $pct = ($left <= 0) ? 0 : (int) round(($left / $total) * 100);
    return array('total' => $total, 'left' => $left, 'pct' => $pct);
}

function export_csv($filename, $headers, $rows)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function message_type_base($type)
{
    return preg_replace('/(_retry)+$/', '', (string) $type);
}

function message_type_title($type)
{
    $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
    $en = ($lang === 'en');
    $map = array(
        'payment' => $en ? 'payment notice' : 'إشعار تسديد',
        'reminder_debt' => $en ? 'debt notice' : 'إشعار بالدين',
        'reminder_manual' => $en ? 'debt notice' : 'إشعار بالدين',
        'bulk_debt' => $en ? 'debt notice' : 'إشعار بالدين',
        'debt_created' => $en ? 'new debt notice' : 'إشعار إضافة دين',
        'activation' => $en ? 'activation notice' : 'إشعار تفعيل',
        'rental_fee' => $en ? 'rental fee notice' : 'إشعار إيجار جهاز',
        'rental_return' => $en ? 'device return request' : 'طلب استرجاع جهاز',
        'bulk_filter' => $en ? 'expiry notice' : 'تنبيه قرب الانتهاء',
        'remind_days' => $en ? 'days left notice' : 'إشعار الأيام المتبقية',
        'unpaid_overdue' => $en ? 'unpaid warning' : 'تنبيه تسديد/قطع',
        'bulk_overdue' => $en ? 'unpaid warning' : 'تنبيه تسديد/قطع',
        'expiry_auto' => $en ? 'expiry reminder' : 'تذكير قرب الانتهاء',
        'reminder_auto' => $en ? 'auto debt reminder' : 'تذكير دين تلقائي',
        'text' => $en ? 'message' : 'رسالة',
    );
    $type = message_type_base($type);
    return isset($map[$type]) ? $map[$type] : ($en ? 'message' : 'رسالة');
}

function message_extract_amount_text($body)
{
    $body = (string) $body;
    if (preg_match('/([\d]{1,3}(?:[,\s][\d]{3})+|\d{4,})(?:\s*(?:د\.ع|دينار))?/u', $body, $m)) {
        return trim(str_replace(' ', '', $m[1]));
    }
    return '';
}

function message_extract_month_text($body)
{
    $body = (string) $body;
    if (preg_match('/ش\s*([0-9]{1,2})/u', $body, $m)) {
        return 'ش' . (int) $m[1];
    }
    if (preg_match('/عن(?:\s*شهر)?[:\s]+([^\n\r]+)/u', $body, $m)) {
        $t = trim($m[1]);
        if ($t !== '') {
            return $t;
        }
    }
    return '';
}

/**
 * ملخص مختصر لحالة الرسالة
 * مثال: تم إرسال إشعار بالدين بمبلغ 35,000 عن ش9
 */
function message_short_summary($type, $body, $success = true)
{
    $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
    $en = ($lang === 'en');
    $title = message_type_title($type);
    if (!$success) {
        return $en
            ? ('Failed to send ' . $title)
            : ('فشل إرسال ' . $title);
    }
    $amount = message_extract_amount_text($body);
    $month = message_extract_month_text($body);
    if ($en) {
        $out = 'Sent ' . $title;
        if ($amount !== '') {
            $out .= ' amount ' . $amount;
        }
        if ($month !== '') {
            $out .= ' for ' . $month;
        }
        return $out;
    }
    $out = 'تم إرسال ' . $title;
    if ($amount !== '') {
        $out .= ' بمبلغ ' . $amount;
    }
    if ($month !== '') {
        $out .= ' عن ' . $month;
    }
    return $out;
}

/**
 * ضبط الأيام المتبقية للمشترك.
 * إن وُجد اشتراك يحدّث end_date، وإلا ينشئ اشتراكاً نشطاً من الباقة بدون فاتورة (نقل دفتر).
 * يرجع: array(ok, message)
 */
function apply_subscriber_days_left($pdo, $subscriberId, $days, $planId = 0)
{
    $subscriberId = (int) $subscriberId;
    $days = (int) $days;
    $planId = (int) $planId;
    if ($subscriberId <= 0) {
        return array(false, 'مشترك غير صالح');
    }
    if ($days < 0) {
        return array(false, 'عدد الأيام غير صالح');
    }
    if ($days > 3650) {
        $days = 3650;
    }
    $endDate = date('Y-m-d', strtotime('+' . $days . ' days'));

    $st = $pdo->prepare(
        'SELECT * FROM subscriptions
         WHERE subscriber_id = :sid AND status = "active" AND end_date >= CURDATE()
         ORDER BY id DESC LIMIT 1'
    );
    $st->execute(array(':sid' => $subscriberId));
    $activeSub = $st->fetch();
    if (!$activeSub) {
        $st = $pdo->prepare(
            'SELECT * FROM subscriptions WHERE subscriber_id = :sid ORDER BY id DESC LIMIT 1'
        );
        $st->execute(array(':sid' => $subscriberId));
        $activeSub = $st->fetch();
    }

    if ($activeSub) {
        $oldEnd = $activeSub['end_date'];
        $newStatus = $days > 0 ? 'active' : 'expired';
        $pdo->prepare(
            'UPDATE subscriptions SET end_date = :end, status = :st WHERE id = :id AND subscriber_id = :sid'
        )->execute(array(
            ':end' => $endDate,
            ':st' => $newStatus,
            ':id' => (int) $activeSub['id'],
            ':sid' => $subscriberId,
        ));
        if (function_exists('activity_log')) {
            activity_log(
                $pdo,
                $subscriberId,
                'subscription',
                (int) $activeSub['id'],
                'update',
                'تعديل الأيام المتبقية: ' . $days,
                'الانتهاء: ' . $oldEnd . ' ← ' . $endDate
            );
        }
        return array(true, 'تم ضبط الأيام المتبقية: ' . $days);
    }

    if ($planId <= 0) {
        return array(false, 'اختر الباقة لتسجيل الأيام من الدفتر');
    }
    $planStmt = $pdo->prepare('SELECT * FROM service_plans WHERE id = :id AND is_active = 1');
    $planStmt->execute(array(':id' => $planId));
    $plan = $planStmt->fetch();
    if (!$plan) {
        return array(false, 'الباقة غير موجودة');
    }
    $startDate = date('Y-m-d');
    $costPrice = isset($plan['cost_price']) ? (float) $plan['cost_price'] : 0;
    $newStatus = $days > 0 ? 'active' : 'expired';
    $pdo->prepare(
        'INSERT INTO subscriptions
        (subscriber_id, service_name, monthly_price, cost_price, start_date, end_date, status, activation_msg_sent)
        VALUES
        (:subscriber_id, :service_name, :monthly_price, :cost_price, :start_date, :end_date, :status, 0)'
    )->execute(array(
        ':subscriber_id' => $subscriberId,
        ':service_name' => $plan['name'],
        ':monthly_price' => (float) $plan['monthly_price'],
        ':cost_price' => $costPrice,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':status' => $newStatus,
    ));
    $newId = (int) $pdo->lastInsertId();
    if (function_exists('activity_log')) {
        activity_log(
            $pdo,
            $subscriberId,
            'subscription',
            $newId,
            'create',
            'نقل من الدفتر — أيام متبقية: ' . $days,
            'الباقة: ' . $plan['name'] . "\nمن: " . $startDate . "\nإلى: " . $endDate . "\nبدون فاتورة جديدة"
        );
    }
    return array(true, 'تم تسجيل الأيام من الدفتر: ' . $days . ' (بدون فاتورة)');
}

/** السماح بتكرار رقم الهاتف (اشتراكان لنفس الرقم / نفس العائلة). */
function ensure_phone_not_unique($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $idx = $pdo->query("SHOW INDEX FROM subscribers WHERE Key_name = 'uq_phone'")->fetch();
        if ($idx) {
            $pdo->exec('ALTER TABLE subscribers DROP INDEX uq_phone');
        }
    } catch (Exception $e) {
        // تجاهل
    }
}

/** توحيد كتابة الاسم قبل الحفظ/المقارنة */
function normalize_subscriber_name($name)
{
    $name = trim((string) $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    return $name === null ? '' : $name;
}

/** هل الاسم مستخدم لمشترك آخر؟ */
function subscriber_name_taken($pdo, $name, $excludeId = 0)
{
    $name = normalize_subscriber_name($name);
    if ($name === '') {
        return false;
    }
    $sql = 'SELECT id FROM subscribers WHERE LOWER(TRIM(name)) = LOWER(:name)';
    $params = array(':name' => $name);
    if ((int) $excludeId > 0) {
        $sql .= ' AND id <> :id';
        $params[':id'] = (int) $excludeId;
    }
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (bool) $st->fetchColumn();
}

/** منع تكرار الاسم في قاعدة البيانات (إن لم توجد مكررات). */
function ensure_name_unique($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $idx = $pdo->query("SHOW INDEX FROM subscribers WHERE Key_name = 'uq_name'")->fetch();
        if ($idx) {
            return;
        }
        $dup = $pdo->query(
            "SELECT LOWER(TRIM(name)) AS n, COUNT(*) AS c
             FROM subscribers
             GROUP BY LOWER(TRIM(name))
             HAVING c > 1
             LIMIT 1"
        )->fetch();
        if ($dup) {
            return;
        }
        $pdo->exec('ALTER TABLE subscribers ADD UNIQUE KEY uq_name (name)');
    } catch (Exception $e) {
        // تجاهل إن فشل (مكررات موجودة أو صلاحيات)
    }
}

function csv_blob_from_assoc_rows($rows)
{
    if (!is_array($rows) || !$rows) {
        return '';
    }
    $headers = array_keys($rows[0]);
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
        $line = array();
        foreach ($headers as $h) {
            $v = isset($row[$h]) ? $row[$h] : '';
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v);
            }
            $line[] = ($v === null) ? '' : $v;
        }
        fputcsv($fp, $line);
    }
    rewind($fp);
    $out = stream_get_contents($fp);
    fclose($fp);
    return $out;
}

function csv_blob_from_matrix($headers, $rows)
{
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, "\xEF\xBB\xBF");
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
        $line = array();
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $v) {
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v);
            }
            $line[] = ($v === null) ? '' : $v;
        }
        fputcsv($fp, $line);
    }
    rewind($fp);
    $out = stream_get_contents($fp);
    fclose($fp);
    return $out;
}

function export_table_assoc($pdo, $sql)
{
    try {
        $st = $pdo->query($sql);
        if (!$st) {
            return array();
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : array();
    } catch (Exception $e) {
        return array();
    }
}

/**
 * تصدير كامل لدفتر الأوفلاين: مشتركين + ديون + اشتراكات + رسائل + حركات
 */
function export_offline_subscribers_full($pdo)
{
    $stamp = date('Y-m-d');
    $subs = export_table_assoc($pdo, 'SELECT * FROM subscribers ORDER BY name ASC, id ASC');
    $invoices = export_table_assoc(
        $pdo,
        'SELECT i.*, s.name AS subscriber_name, s.phone AS subscriber_phone
         FROM invoices i
         JOIN subscribers s ON s.id = i.subscriber_id
         ORDER BY s.name ASC, i.due_date ASC, i.id ASC'
    );
    $subscriptions = export_table_assoc(
        $pdo,
        'SELECT sub.*, s.name AS subscriber_name, s.phone AS subscriber_phone
         FROM subscriptions sub
         JOIN subscribers s ON s.id = sub.subscriber_id
         ORDER BY s.name ASC, sub.id DESC'
    );
    $messages = export_table_assoc(
        $pdo,
        'SELECT m.*, s.name AS subscriber_name
         FROM message_logs m
         LEFT JOIN subscribers s ON s.id = m.subscriber_id
         ORDER BY m.id DESC'
    );
    $activity = export_table_assoc(
        $pdo,
        'SELECT a.*, s.name AS subscriber_name
         FROM activity_logs a
         LEFT JOIN subscribers s ON s.id = a.subscriber_id
         WHERE a.subscriber_id IS NOT NULL
         ORDER BY a.id DESC'
    );

    $lastPkg = array();
    $activeSub = array();
    foreach ($subscriptions as $sub) {
        $sid = isset($sub['subscriber_id']) ? (int) $sub['subscriber_id'] : 0;
        if ($sid <= 0) {
            continue;
        }
        if (!isset($lastPkg[$sid]) && !empty($sub['service_name'])) {
            $lastPkg[$sid] = $sub['service_name'];
        }
        if (!isset($activeSub[$sid]) && isset($sub['status']) && $sub['status'] === 'active') {
            $activeSub[$sid] = $sub;
        }
    }
    $unpaidTotal = array();
    $unpaidBySub = array();
    foreach ($invoices as $inv) {
        if (!isset($inv['status']) || $inv['status'] !== 'unpaid') {
            continue;
        }
        $sid = isset($inv['subscriber_id']) ? (int) $inv['subscriber_id'] : 0;
        if ($sid <= 0) {
            continue;
        }
        if (!isset($unpaidTotal[$sid])) {
            $unpaidTotal[$sid] = 0;
            $unpaidBySub[$sid] = array();
        }
        $unpaidTotal[$sid] += isset($inv['amount']) ? (float) $inv['amount'] : 0;
        $lab = isset($inv['month_label']) ? $inv['month_label'] : '';
        if ($lab !== '' && function_exists('month_short_label')) {
            $lab = month_short_label($lab);
        }
        $amt = isset($inv['amount']) ? $inv['amount'] : 0;
        $note = isset($inv['notes']) ? $inv['notes'] : '';
        $unpaidBySub[$sid][] = $lab . ': ' . $amt . ($note !== '' ? (' (' . $note . ')') : '');
    }
    $subsById = array();
    foreach ($subs as $k => $row) {
        $sid = isset($row['id']) ? (int) $row['id'] : 0;
        $subs[$k]['phone_display'] = isset($row['phone']) && function_exists('format_phone_display')
            ? format_phone_display($row['phone'])
            : (isset($row['phone']) ? $row['phone'] : '');
        $subs[$k]['last_package'] = isset($lastPkg[$sid]) ? $lastPkg[$sid] : '';
        $subs[$k]['active_start'] = (isset($activeSub[$sid]) && !empty($activeSub[$sid]['start_date']))
            ? $activeSub[$sid]['start_date'] : '';
        $subs[$k]['active_end'] = (isset($activeSub[$sid]) && !empty($activeSub[$sid]['end_date']))
            ? $activeSub[$sid]['end_date'] : '';
        $subs[$k]['unpaid_total'] = isset($unpaidTotal[$sid]) ? $unpaidTotal[$sid] : 0;
        $subsById[$sid] = $subs[$k];
    }

    $unpaidRows = array();
    $unpaidHeaders = array(
        'الاسم', 'الهاتف', 'عنوان', 'ملاحظات المشترك', 'يوزرنيم SAS',
        'الشهر', 'المبلغ', 'حالة الفاتورة', 'تاريخ الاستحقاق', 'ملاحظات الدين',
        'الباقة الحالية', 'من', 'إلى', 'إجمالي الدين غير المسدد',
    );
    foreach ($invoices as $inv) {
        if (!isset($inv['status']) || $inv['status'] !== 'unpaid') {
            continue;
        }
        $sid = isset($inv['subscriber_id']) ? (int) $inv['subscriber_id'] : 0;
        $subRow = isset($subsById[$sid]) ? $subsById[$sid] : null;
        $phone = '';
        if ($subRow && isset($subRow['phone_display'])) {
            $phone = $subRow['phone_display'];
        } elseif (isset($inv['subscriber_phone'])) {
            $phone = function_exists('format_phone_display')
                ? format_phone_display($inv['subscriber_phone'])
                : $inv['subscriber_phone'];
        }
        $monthLab = isset($inv['month_label']) ? $inv['month_label'] : '';
        if ($monthLab !== '' && function_exists('month_short_label')) {
            $monthLab = month_short_label($monthLab);
        }
        $unpaidRows[] = array(
            isset($inv['subscriber_name']) ? $inv['subscriber_name'] : '',
            $phone,
            ($subRow && isset($subRow['address'])) ? $subRow['address'] : '',
            ($subRow && isset($subRow['notes'])) ? $subRow['notes'] : '',
            ($subRow && isset($subRow['sas_username'])) ? $subRow['sas_username'] : '',
            $monthLab,
            isset($inv['amount']) ? $inv['amount'] : 0,
            isset($inv['status']) ? $inv['status'] : '',
            isset($inv['due_date']) ? $inv['due_date'] : '',
            isset($inv['notes']) ? $inv['notes'] : '',
            ($subRow && isset($subRow['last_package'])) ? $subRow['last_package'] : '',
            ($subRow && isset($subRow['active_start'])) ? $subRow['active_start'] : '',
            ($subRow && isset($subRow['active_end'])) ? $subRow['active_end'] : '',
            ($subRow && isset($subRow['unpaid_total'])) ? $subRow['unpaid_total'] : '',
        );
    }
    $overviewHeaders = array(
        'الاسم', 'الهاتف', 'العنوان', 'ملاحظات', 'يوزرنيم SAS', 'الباقة', 'من', 'إلى',
        'إجمالي الدين', 'تفاصيل الديون غير المسددة',
    );
    $overviewRows = array();
    foreach ($subs as $s) {
        $sid = isset($s['id']) ? (int) $s['id'] : 0;
        $phone = isset($s['phone_display']) ? $s['phone_display'] : (isset($s['phone']) ? $s['phone'] : '');
        $detail = isset($unpaidBySub[$sid]) ? implode(' | ', $unpaidBySub[$sid]) : '';
        $overviewRows[] = array(
            isset($s['name']) ? $s['name'] : '',
            $phone,
            isset($s['address']) ? $s['address'] : '',
            isset($s['notes']) ? $s['notes'] : '',
            isset($s['sas_username']) ? $s['sas_username'] : '',
            isset($s['last_package']) ? $s['last_package'] : '',
            isset($s['active_start']) ? $s['active_start'] : '',
            isset($s['active_end']) ? $s['active_end'] : '',
            isset($s['unpaid_total']) ? $s['unpaid_total'] : 0,
            $detail,
        );
    }

    $files = array(
        '00-README.txt' => "تصدير دفتر الأوفلاين — " . $stamp . "\r\n"
            . "احفظ هذا الأرشيف قبل حذف المشتركين الأوفلاين.\r\n\r\n"
            . "01-subscribers.csv = كل المشتركين\r\n"
            . "02-invoices.csv = كل الفواتير (مسدد وغير مسدد)\r\n"
            . "03-subscriptions.csv = الاشتراكات\r\n"
            . "04-messages.csv = سجل الرسائل\r\n"
            . "05-activity.csv = سجل الحركات\r\n"
            . "06-unpaid-debts.csv = الديون غير المسددة لإعادة الإدخال\r\n"
            . "07-overview.csv = ملخص لكل مشترك مع تفاصيل الدين\r\n",
        '01-subscribers.csv' => csv_blob_from_assoc_rows($subs),
        '02-invoices.csv' => csv_blob_from_assoc_rows($invoices),
        '03-subscriptions.csv' => csv_blob_from_assoc_rows($subscriptions),
        '04-messages.csv' => csv_blob_from_assoc_rows($messages),
        '05-activity.csv' => csv_blob_from_assoc_rows($activity),
        '06-unpaid-debts.csv' => csv_blob_from_matrix($unpaidHeaders, $unpaidRows),
        '07-overview.csv' => csv_blob_from_matrix($overviewHeaders, $overviewRows),
    );

    if (class_exists('ZipArchive')) {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'offline-' . uniqid('', true) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE) === true) {
            foreach ($files as $name => $body) {
                $zip->addFromString($name, $body !== '' ? $body : "\xEF\xBB\xBF");
            }
            $zip->close();
            $zipName = 'offline-subscribers-' . $stamp . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipName . '"');
            header('Content-Length: ' . (string) filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
        @unlink($tmp);
    }

    export_csv(
        'offline-unpaid-debts-' . $stamp . '.csv',
        $unpaidHeaders,
        $unpaidRows
    );
}

function ensure_subscriber_grace_days_column($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM subscribers LIKE 'grace_days'")->fetch();
        if (!$col) {
            $pdo->exec(
                'ALTER TABLE subscribers
                 ADD COLUMN grace_days INT NULL DEFAULT NULL AFTER notes'
            );
        } else {
            // NULL = حسب إعداد النظام
            $nullOk = isset($col['Null']) && strtoupper((string) $col['Null']) === 'YES';
            if (!$nullOk) {
                $pdo->exec('ALTER TABLE subscribers MODIFY COLUMN grace_days INT NULL DEFAULT NULL');
                // القيم القديمة الافتراضية → حسب النظام للجميع
                $pdo->exec('UPDATE subscribers SET grace_days = NULL');
            }
        }
    } catch (Exception $e) {
    }
}

function subscriber_default_grace_days($config = null)
{
    if (!is_array($config) && isset($GLOBALS['config']) && is_array($GLOBALS['config'])) {
        $config = $GLOBALS['config'];
    }
    if (is_array($config) && isset($config['grace_days'])) {
        return max(0, (int) $config['grace_days']);
    }
    return 3;
}

/**
 * أيام السماح الفعلية:
 * - grace_days = NULL → حسب النظام
 * - رقم → استثناء خاص بهذا المشترك فقط
 */
function subscriber_grace_days($row, $config = null)
{
    if (is_array($row) && array_key_exists('grace_days', $row)
        && $row['grace_days'] !== null && $row['grace_days'] !== '') {
        return max(0, (int) $row['grace_days']);
    }
    return subscriber_default_grace_days($config);
}

function subscriber_grace_is_custom($row)
{
    return is_array($row) && array_key_exists('grace_days', $row)
        && $row['grace_days'] !== null && $row['grace_days'] !== '';
}

function subscriber_grace_label($row, $config = null, $lang = 'ar')
{
    $en = ($lang === 'en');
    if (subscriber_grace_is_custom($row)) {
        return (string) max(0, (int) $row['grace_days']);
    }
    $sys = subscriber_default_grace_days($config);
    return $en ? ('System (' . $sys . ')') : ('حسب النظام (' . $sys . ')');
}
