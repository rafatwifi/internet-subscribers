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

    $msg = "مرحباً {$sub['name']}\n"
        . "تم تفعيل خدمة الإنترنت ({$sub['service_name']})\n"
        . 'من تاريخ ' . $sub['start_date'] . ' إلى تاريخ ' . $sub['end_date'] . "\n"
        . 'الاشتراك: ' . $subFmt;
    if ($rentalFee > 0) {
        $label = $rentalDeviceName !== '' ? $rentalDeviceName : 'جهاز إيجار';
        $msg .= "\nإيجار ({$label}): " . $rentFmt;
        $msg .= "\nالإجمالي: " . $totalFmt;
    } else {
        $msg .= "\nالمبلغ: " . $subFmt;
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
