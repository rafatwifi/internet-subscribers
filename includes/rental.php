<?php

function ensure_rental_columns($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $cols = array(
            'rental_enabled' => "ADD COLUMN rental_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER notes",
            'rental_device_id' => "ADD COLUMN rental_device_id VARCHAR(60) DEFAULT NULL AFTER rental_enabled",
        );
        foreach ($cols as $name => $ddl) {
            $exists = $pdo->query("SHOW COLUMNS FROM subscribers LIKE " . $pdo->quote($name))->fetch();
            if (!$exists) {
                $pdo->exec('ALTER TABLE subscribers ' . $ddl);
            }
        }
    } catch (Exception $e) {
        // تجاهل
    }
    $done = true;
}

function rental_default_devices()
{
    return array(
        array('id' => 'powerbeam', 'name' => 'بور بيم', 'icon' => 'PB', 'color' => '#3b82f6'),
        array('id' => 'litebeam', 'name' => 'لايت بيم', 'icon' => 'LB', 'color' => '#30d158'),
        array('id' => 'nanostation', 'name' => 'نانو ستيشن', 'icon' => 'NS', 'color' => '#ff9f0a'),
    );
}

function rental_devices_list($settings = null)
{
    if ($settings === null) {
        $settings = settings_load();
    }
    $list = array();
    if (!empty($settings['rental_devices']) && is_array($settings['rental_devices'])) {
        foreach ($settings['rental_devices'] as $d) {
            if (!is_array($d)) {
                continue;
            }
            $id = isset($d['id']) ? trim((string) $d['id']) : '';
            $name = isset($d['name']) ? trim((string) $d['name']) : '';
            if ($id === '' || $name === '') {
                continue;
            }
            $list[] = array(
                'id' => $id,
                'name' => $name,
                'icon' => isset($d['icon']) && $d['icon'] !== '' ? (string) $d['icon'] : strtoupper(substr($id, 0, 2)),
                'color' => isset($d['color']) && $d['color'] !== '' ? (string) $d['color'] : '#5e5ce6',
            );
        }
    }
    if (!$list) {
        $list = rental_default_devices();
    }
    return $list;
}

function rental_fee_amount($settings = null)
{
    if ($settings === null) {
        $settings = settings_load();
    }
    $fee = isset($settings['rental_fee']) ? (float) $settings['rental_fee'] : 5000;
    if ($fee < 0) {
        $fee = 0;
    }
    return $fee;
}

function rental_device_by_id($deviceId, $settings = null)
{
    $deviceId = trim((string) $deviceId);
    if ($deviceId === '') {
        return null;
    }
    foreach (rental_devices_list($settings) as $d) {
        if ($d['id'] === $deviceId) {
            return $d;
        }
    }
    return null;
}

function subscriber_has_rental($sub)
{
    return !empty($sub['rental_enabled']) && !empty($sub['rental_device_id']);
}

function rental_device_ids_matching_query($q, $settings = null)
{
    $q = trim((string) $q);
    if ($q === '') {
        return array();
    }
    $ql = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $ids = array();
    foreach (rental_devices_list($settings) as $d) {
        $hay = $d['id'] . ' ' . $d['name'] . ' ' . (isset($d['icon']) ? $d['icon'] : '');
        $hayl = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : strtolower($hay);
        if (strpos($hayl, $ql) !== false) {
            $ids[] = $d['id'];
        }
    }
    return $ids;
}

function sas_user_is_live($cacheRow)
{
    if (!$cacheRow || !is_array($cacheRow)) {
        return false;
    }
    $enabled = isset($cacheRow['enabled']) ? (int) $cacheRow['enabled'] : 0;
    $expireAt = !empty($cacheRow['expire_at']) ? $cacheRow['expire_at'] : '';
    return $enabled === 1 && $expireAt !== '' && strtotime($expireAt) >= time();
}

/**
 * حفظ إيجار مشترك SAS في قاعدة السيرفر (subscribers) — مو في كومنت الساس
 * يرجع array($ok, $message, $extra)
 */
function sas_save_user_rental($pdo, $config, $username, $enabled, $deviceId)
{
    $username = trim((string) $username);
    if ($username === '') {
        return array(false, 'مشترك غير محدد', array());
    }
    if (!function_exists('sas_cache_get') || !function_exists('sas_cache_ensure_local')) {
        return array(false, 'ملف SAS غير مكتمل', array());
    }
    $cache = sas_cache_get($pdo, $username);
    if (!$cache) {
        return array(false, 'المشترك مو موجود بكاش SAS — حدّث القائمة', array());
    }
    $enabled = (bool) $enabled;
    $deviceId = trim((string) $deviceId);
    if ($enabled && $deviceId === '') {
        return array(false, 'اختر نوع الجهاز', array());
    }
    if ($enabled && !rental_device_by_id($deviceId)) {
        return array(false, 'نوع الجهاز غير معروف — أضفه من الإعدادات', array());
    }
    if (!$enabled) {
        $deviceId = null;
    }

    list($localId, $err) = sas_cache_ensure_local($pdo, $config, $cache);
    if ($localId <= 0) {
        return array(false, ($err !== '' ? $err : 'تعذر ربط المشترك محلياً'), array());
    }

    $oldSt = $pdo->prepare('SELECT rental_enabled, rental_device_id FROM subscribers WHERE id = :id');
    $oldSt->execute(array(':id' => $localId));
    $oldRent = $oldSt->fetch();
    $wasOn = $oldRent ? subscriber_has_rental($oldRent) : false;

    $pdo->prepare(
        'UPDATE subscribers SET rental_enabled = :en, rental_device_id = :dev WHERE id = :id'
    )->execute(array(
        ':en' => $enabled ? 1 : 0,
        ':dev' => $deviceId,
        ':id' => $localId,
    ));

    $dev = $enabled ? rental_device_by_id($deviceId) : null;
    if (function_exists('activity_log')) {
        activity_log(
            $pdo,
            $localId,
            'subscriber',
            $localId,
            $enabled ? 'rental_on' : 'rental_off',
            $enabled ? ('تفعيل إيجار: ' . ($dev ? $dev['name'] : $deviceId)) : 'إيقاف جهاز الإيجار',
            'اليوزرنيم: ' . $username . "\n"
            . ($enabled ? ('الرسوم الشهرية: ' . rental_fee_amount()) : 'بدون جهاز')
        );
    }

    $msg = $enabled ? 'تم حفظ جهاز الإيجار' : 'تم إيقاف جهاز الإيجار';
    $isLive = sas_user_is_live($cache);
    if (!$isLive && function_exists('subscriber_is_active')) {
        $isLive = subscriber_is_active($pdo, $localId);
    }
    if ($enabled && !$wasOn && $isLive) {
        list($debtOk, $debtVal) = add_immediate_rental_debt($pdo, $localId, $deviceId);
        if ($debtOk) {
            $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
            $msg .= ' — أُضيف دين إيجار ' . money_format_iqd($debtVal, $currency) . ' للحساب';
        }
    }

    $subNow = array(
        'rental_enabled' => $enabled ? 1 : 0,
        'rental_device_id' => $deviceId,
    );
    $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
    $debt = function_exists('subscriber_unpaid_total') ? subscriber_unpaid_total($pdo, $localId) : 0.0;
    $currency = isset($config['currency']) ? $config['currency'] : 'IQD';
    return array(true, $msg, array(
        'local_id' => $localId,
        'rental_enabled' => $enabled ? 1 : 0,
        'rental_device_id' => $deviceId ? $deviceId : '',
        'device_name' => $dev ? $dev['name'] : '',
        'cell_html' => rental_cell_html($subNow, $username, $lang),
        'badge_html' => rental_badge_html($subNow),
        'debt' => $debt,
        'debt_text' => function_exists('money_format_iqd') ? money_format_iqd($debt, $currency) : (string) $debt,
    ));
}

function sas_save_user_local_details($pdo, $config, $username, $address, $notes)
{
    $username = trim((string) $username);
    if ($username === '' || !function_exists('sas_cache_get') || !function_exists('sas_cache_ensure_local')) {
        return array(false, 'مشترك غير محدد');
    }
    $cache = sas_cache_get($pdo, $username);
    if (!$cache) {
        return array(false, 'المشترك مو موجود بكاش SAS — حدّث القائمة');
    }
    list($localId, $err) = sas_cache_ensure_local($pdo, $config, $cache);
    if ($localId <= 0) {
        return array(false, ($err !== '' ? $err : 'تعذر ربط المشترك محلياً'));
    }
    $address = trim((string) $address);
    $notes = trim((string) $notes);
    $oldSt = $pdo->prepare('SELECT address, notes FROM subscribers WHERE id = :id');
    $oldSt->execute(array(':id' => $localId));
    $old = $oldSt->fetch();
    $pdo->prepare(
        'UPDATE subscribers SET address = :a, notes = :n WHERE id = :id'
    )->execute(array(
        ':a' => ($address !== '' ? $address : null),
        ':n' => ($notes !== '' ? $notes : null),
        ':id' => $localId,
    ));
    if (function_exists('activity_log')) {
        $lines = array('اليوزرنيم: ' . $username);
        if (function_exists('activity_diff_line')) {
            $d1 = activity_diff_line('العنوان', $old && isset($old['address']) ? $old['address'] : '', $address);
            $d2 = activity_diff_line('الملاحظات', $old && isset($old['notes']) ? $old['notes'] : '', $notes);
            if ($d1 !== '') {
                $lines[] = $d1;
            }
            if ($d2 !== '') {
                $lines[] = $d2;
            }
        }
        activity_log(
            $pdo,
            $localId,
            'subscriber',
            $localId,
            'update',
            'تعديل تفاصيل محلية (SAS)',
            implode("\n", $lines)
        );
    }
    return array(true, 'تم حفظ التفاصيل');
}

function sas_save_user_grace_days($pdo, $config, $username, $days)
{
    $username = trim((string) $username);
    if ($username === '' || !function_exists('sas_cache_get') || !function_exists('sas_cache_ensure_local')) {
        return array(false, 'مشترك غير محدد', null);
    }
    // null / '' / 'system' = حسب النظام
    $isSystem = ($days === null || $days === '' || $days === 'system' || $days === 'sys');
    if ($isSystem) {
        $store = null;
        $disp = null;
    } else {
        $store = (int) $days;
        if ($store < 0) {
            $store = 0;
        }
        if ($store > 90) {
            $store = 90;
        }
        $disp = $store;
    }
    $cache = sas_cache_get($pdo, $username);
    if (!$cache) {
        return array(false, 'المشترك مو موجود بكاش SAS — حدّث القائمة', $disp);
    }
    list($localId, $err) = sas_cache_ensure_local($pdo, $config, $cache);
    if ($localId <= 0) {
        return array(false, ($err !== '' ? $err : 'تعذر ربط المشترك محلياً'), $disp);
    }
    if (function_exists('ensure_subscriber_grace_days_column')) {
        ensure_subscriber_grace_days_column($pdo);
    }
    $oldSt = $pdo->prepare('SELECT grace_days FROM subscribers WHERE id = :id');
    $oldSt->execute(array(':id' => $localId));
    $oldRaw = $oldSt->fetchColumn();
    $oldLabel = ($oldRaw === null || $oldRaw === false || $oldRaw === '')
        ? 'حسب النظام'
        : (string) (int) $oldRaw;
    $pdo->prepare('UPDATE subscribers SET grace_days = :g WHERE id = :id')
        ->execute(array(':g' => $store, ':id' => $localId));
    $newLabel = $isSystem ? 'حسب النظام' : (string) (int) $store;
    if (function_exists('activity_log')) {
        activity_log(
            $pdo,
            $localId,
            'subscriber',
            $localId,
            'update',
            'تعديل أيام السماح',
            'اليوزرنيم: ' . $username . "\nمن: " . $oldLabel . ' إلى: ' . $newLabel
        );
    }
    return array(true, 'تم حفظ أيام السماح', $disp);
}

function rental_cell_html($sub, $username, $lang = 'ar', $settings = null)
{
    $has = subscriber_has_rental($sub);
    $devId = $has ? (string) $sub['rental_device_id'] : '';
    $badge = $has ? rental_badge_html($sub, $settings) : '';
    if ($has) {
        $dev = rental_device_by_id($devId, $settings);
        $label = $dev ? $dev['name'] : $devId;
    } else {
        $label = '';
    }
    $cls = 'rent-cell-edit' . ($has ? '' : ' rent-cell-empty');
    $tip = $lang === 'en' ? 'Click to set rental device' : 'اضغط لاختيار جهاز الإيجار';
    return '<button type="button" class="' . $cls . '" data-id="' . e($username) . '" data-device="' . e($devId) . '" title="' . e($tip) . '">'
        . $badge . '<span class="rent-cell-label">' . e($label) . '</span></button>';
}

/**
 * هل للمشترك اشتراك نشط حالياً؟
 */
function subscriber_is_active($pdo, $subscriberId)
{
    $st = $pdo->prepare(
        'SELECT id FROM subscriptions
         WHERE subscriber_id = :id AND status = "active" AND end_date >= CURDATE()
         LIMIT 1'
    );
    $st->execute(array(':id' => (int) $subscriberId));
    return (bool) $st->fetchColumn();
}

/**
 * إضافة دين إيجار فوري لحساب المشترك (عند تفعيل الإيجار وهو نشط).
 * يرجع: array($ok, $amountOrMessage)
 */
function add_immediate_rental_debt($pdo, $subscriberId, $deviceId = '', $settings = null)
{
    $subscriberId = (int) $subscriberId;
    if ($subscriberId <= 0) {
        return array(false, 'مشترك غير صالح');
    }
    if ($settings === null) {
        $settings = function_exists('settings_load') ? settings_load() : array();
    }
    $fee = rental_fee_amount($settings);
    if ($fee <= 0) {
        return array(false, 'رسوم الإيجار صفر');
    }
    $dev = rental_device_by_id($deviceId, $settings);
    $devName = $dev ? $dev['name'] : 'جهاز إيجار';
    $month = date('Y-m');
    $note = 'إيجار (' . $devName . ')';

    // تجنب تكرار نفس دين الإيجار لنفس الشهر إن كان غير مسدد
    $dup = $pdo->prepare(
        'SELECT id FROM invoices
         WHERE subscriber_id = :sid AND status = "unpaid" AND month_label = :m
           AND notes LIKE :note
         LIMIT 1'
    );
    $dup->execute(array(
        ':sid' => $subscriberId,
        ':m' => $month,
        ':note' => '%' . $note . '%',
    ));
    if ($dup->fetch()) {
        return array(false, 'موجود مسبقاً');
    }

    $ins = $pdo->prepare(
        'INSERT INTO invoices
            (subscription_id, subscriber_id, month_label, amount, cost_price, profit, due_date, status, notes)
         VALUES
            (NULL, :sid, :month, :amount, 0, 0, :due, "unpaid", :notes)'
    );
    $ins->execute(array(
        ':sid' => $subscriberId,
        ':month' => $month,
        ':amount' => $fee,
        ':due' => date('Y-m-d'),
        ':notes' => $note,
    ));
    $newId = (int) $pdo->lastInsertId();
    if (function_exists('activity_log')) {
        activity_log(
            $pdo,
            $subscriberId,
            'invoice',
            $newId,
            'create',
            'إضافة دين إيجار فوري #' . $newId,
            $note . "\nالمبلغ: " . $fee . "\nالشهر: " . $month
        );
    }
    return array(true, $fee);
}

function rental_badge_html($sub, $settings = null)
{
    if (!subscriber_has_rental($sub)) {
        return '';
    }
    $dev = rental_device_by_id($sub['rental_device_id'], $settings);
    if (!$dev) {
        return '';
    }
    $title = e($dev['name']);
    $icon = e($dev['icon']);
    $color = e($dev['color']);
    return '<span class="rent-badge" title="' . $title . '" style="background:' . $color . '">' . $icon . '</span>';
}

function rental_only_message($sub, $config, $settings = null)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $fee = rental_fee_amount($settings);
    $dev = rental_device_by_id(isset($sub['rental_device_id']) ? $sub['rental_device_id'] : '', $settings);
    $devName = $dev ? $dev['name'] : 'جهاز إيجار';
    $amount = money_format_iqd($fee, $currency);
    return "مرحباً {$sub['name']}\n"
        . "إيجار جهاز ({$devName})\n"
        . 'المبلغ: ' . $amount . "\n"
        . 'يرجى تسديد إيجار الجهاز.';
}

function rental_return_message($sub, $config, $settings = null)
{
    $dev = rental_device_by_id(isset($sub['rental_device_id']) ? $sub['rental_device_id'] : '', $settings);
    $devName = $dev ? $dev['name'] : 'الجهاز';
    return "مرحباً {$sub['name']}\n"
        . "نطلب منكم إعادة جهاز الإيجار ({$devName}) إلى الشركة\n"
        . "بسبب انتهاء/إيقاف الاشتراك.\n"
        . 'يرجى التواصل معنا لاستلام الجهاز.';
}

/**
 * نص تفعيل يتضمن الاشتراك + الإيجار (تفصيلي ثم الإجمالي)
 */
function activation_message_with_rental($sub, $config, $extraNote = '', $rentalFee = 0, $rentalDeviceName = '')
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $subAmount = (float) $sub['monthly_price'];
    $rentalFee = (float) $rentalFee;
    $total = $subAmount + $rentalFee;
    $subFmt = money_format_iqd($subAmount, $currency);
    $rentFmt = money_format_iqd($rentalFee, $currency);
    $totalFmt = money_format_iqd($total, $currency);

    $msg = '';
    if (function_exists('activation_message')) {
        $msg = activation_message($sub, $config, '');
    }
    if ($msg === '') {
        $msg = "مرحباً {$sub['name']}\n"
            . "تم تفعيل خدمة الإنترنت ({$sub['service_name']})\n"
            . 'من تاريخ ' . $sub['start_date'] . ' إلى تاريخ ' . $sub['end_date'] . "\n"
            . 'الاشتراك: ' . $subFmt;
    }
    if ($rentalFee > 0) {
        $label = $rentalDeviceName !== '' ? $rentalDeviceName : 'جهاز إيجار';
        $msg .= "\nإيجار ({$label}): " . $rentFmt;
        $msg .= "\nالإجمالي: " . $totalFmt;
    }
    if ($extraNote !== '') {
        $msg .= "\n" . $extraNote;
    }
    $senderNote = '';
    if (isset($config['whatsapp']['sender_note'])) {
        $senderNote = trim((string) $config['whatsapp']['sender_note']);
    }
    if ($senderNote !== '' && strpos($msg, $senderNote) === false) {
        $msg .= "\n" . $senderNote;
    }
    return $msg;
}
