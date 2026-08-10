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
