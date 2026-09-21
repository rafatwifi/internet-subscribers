<?php

/**
 * معرّف جلسة واتساب على البوابة المشتركة: default للأدمن، agent_{id} للوكيل.
 */
function whatsapp_session_id()
{
    if (function_exists('current_admin')) {
        $admin = current_admin();
        if ($admin && function_exists('is_agent_user') && is_agent_user($admin) && !empty($admin['id'])) {
            return 'agent_' . (int) $admin['id'];
        }
    }
    return 'default';
}

function whatsapp_send($config, $phone, $message, $type = 'text')
{
    $wa = isset($config['whatsapp']) ? $config['whatsapp'] : array();
    $phone = normalize_phone($phone);

    if ($phone === '' || (function_exists('phone_is_placeholder') && phone_is_placeholder($phone))) {
        return array(
            'success' => false,
            'skipped' => true,
            'response' => 'رقم هاتف غير صالح أو فارغ',
            'phone' => $phone,
            'body' => $message,
            'type' => $type,
        );
    }

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

    $sessionId = function_exists('whatsapp_session_id') ? whatsapp_session_id() : 'default';
    $payload = array(
        'phone' => $phone,
        'message' => $message,
        'session' => $sessionId,
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'X-Api-Key: ' . $key,
            'X-Wa-Session: ' . $sessionId,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 45,
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
 * الرقم مو على واتساب (من رد البوابة / سجل الرسائل)
 */
if (!function_exists('subscriber_msg_is_no_whatsapp')) {
function subscriber_msg_is_no_whatsapp($response)
{
    $response = (string) $response;
    if ($response === '') {
        return false;
    }
    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        if (array_key_exists('no_whatsapp', $decoded)) {
            return !empty($decoded['no_whatsapp']);
        }
        if (isset($decoded['code']) && (string) $decoded['code'] === 'no_whatsapp') {
            return true;
        }
        $err = '';
        if (isset($decoded['error'])) {
            $err .= ' ' . (string) $decoded['error'];
        }
        if (isset($decoded['message'])) {
            $err .= ' ' . (string) $decoded['message'];
        }
        return (stripos($err, 'not on WhatsApp') !== false);
    }
    return (stripos($response, 'not on WhatsApp') !== false);
}
}

if (!function_exists('phones_wa_same')) {
function phones_wa_same($a, $b)
{
    $ka = phone_wa_keys($a);
    $kb = phone_wa_keys($b);
    if (!$ka || !$kb) {
        return false;
    }
    foreach ($ka as $k) {
        if (in_array($k, $kb, true)) {
            return true;
        }
    }
    return false;
}
}

if (!function_exists('phone_wa_keys')) {
function phone_wa_keys($phone)
{
    $d = preg_replace('/\D+/', '', (string) $phone);
    if ($d === '' || $d === '964000000000') {
        return array();
    }
    $keys = array($d);
    if (function_exists('normalize_phone')) {
        $n = normalize_phone($d);
        if ($n !== '') {
            $keys[] = $n;
        }
    }
    if (strlen($d) >= 10) {
        $tail = substr($d, -10);
        $keys[] = $tail;
        $keys[] = '964' . $tail;
        if (strpos($tail, '7') === 0) {
            $keys[] = '0' . $tail;
        }
    }
    if (strpos($d, '964') === 0 && strlen($d) >= 12) {
        $rest = substr($d, 3);
        $keys[] = $rest;
        $keys[] = '0' . $rest;
    }
    return array_values(array_unique($keys));
}
}

if (!function_exists('phones_known_on_whatsapp')) {
function phones_known_on_whatsapp($pdo = null, $addPhones = null)
{
    static $set = null;
    if ($set === null) {
        $set = array();
        if (!$pdo && isset($GLOBALS['pdo'])) {
            $pdo = $GLOBALS['pdo'];
        }
        if ($pdo) {
            try {
                $rows = $pdo->query(
                    "SELECT DISTINCT phone FROM message_logs
                     WHERE success = 1 AND phone IS NOT NULL AND phone <> ''"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $p) {
                    foreach (phone_wa_keys($p) as $k) {
                        $set[$k] = true;
                    }
                }
            } catch (Exception $e) {
            }
            try {
                $rows2 = $pdo->query(
                    "SELECT DISTINCT s.phone FROM subscribers s
                     INNER JOIN message_logs m ON m.subscriber_id = s.id AND m.success = 1
                     WHERE s.phone IS NOT NULL AND s.phone <> ''"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows2 as $p) {
                    foreach (phone_wa_keys($p) as $k) {
                        $set[$k] = true;
                    }
                }
            } catch (Exception $e2) {
            }
            try {
                $rows3 = $pdo->query(
                    "SELECT DISTINCT c.phone FROM sas_users_cache c
                     INNER JOIN subscribers s ON s.id = c.local_subscriber_id
                     INNER JOIN message_logs m ON m.subscriber_id = s.id AND m.success = 1
                     WHERE c.phone IS NOT NULL AND c.phone <> ''"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows3 as $p) {
                    foreach (phone_wa_keys($p) as $k) {
                        $set[$k] = true;
                    }
                }
            } catch (Exception $e3) {
            }
        }
    }
    if ($addPhones) {
        if (!is_array($addPhones)) {
            $addPhones = array($addPhones);
        }
        foreach ($addPhones as $p) {
            foreach (phone_wa_keys($p) as $k) {
                $set[$k] = true;
            }
        }
    }
    return $set;
}
}

if (!function_exists('phones_known_register_from_rows')) {
function phones_known_register_from_rows($rows, $pdo = null)
{
    if (!$rows || !is_array($rows)) {
        return;
    }
    $add = array();
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['last_msg_ok'])) {
            continue;
        }
        if (!empty($row['local_phone'])) {
            $add[] = $row['local_phone'];
        }
        if (!empty($row['phone'])) {
            $add[] = $row['phone'];
        }
        if (!empty($row['last_msg_phone'])) {
            $add[] = $row['last_msg_phone'];
        }
    }
    if ($add) {
        phones_known_on_whatsapp($pdo, $add);
    }
}
}

if (!function_exists('subscriber_no_whatsapp_for_current')) {
/**
 * آخر فشل "ماكو واتساب" يخص الرقم الحالي فقط.
 * إذا تغيّر رقم المشترك، الفشل القديم ما ينعرض كماكو واتساب.
 */
function subscriber_no_whatsapp_for_current($response, $logPhone, $currentPhones, $everOk = false)
{
    if (!subscriber_msg_is_no_whatsapp($response)) {
        return false;
    }
    if (!is_array($currentPhones)) {
        $currentPhones = array($currentPhones);
    }
    $shown = '';
    foreach ($currentPhones as $p) {
        if ((string) $p !== '') {
            $shown = (string) $p;
            break;
        }
    }
    $logPhone = (string) $logPhone;
    if ($logPhone !== '' && $shown !== '' && !phones_wa_same($logPhone, $shown)) {
        return false;
    }
    if (function_exists('subscriber_should_hide_no_whatsapp')
        && subscriber_should_hide_no_whatsapp($currentPhones, $everOk)) {
        return false;
    }
    return true;
}
}

if (!function_exists('subscriber_row_is_no_whatsapp')) {
function subscriber_row_is_no_whatsapp($row)
{
    if (!is_array($row)) {
        return false;
    }
    $hasMsg = isset($row['last_msg_at']) && $row['last_msg_at'] !== null && $row['last_msg_at'] !== '';
    if (!$hasMsg || !empty($row['last_msg_ok'])) {
        return false;
    }
    $shown = '';
    if (isset($row['phone']) && $row['phone'] !== null && (string) $row['phone'] !== '') {
        $shown = (string) $row['phone'];
    } elseif (isset($row['local_phone']) && $row['local_phone'] !== null && (string) $row['local_phone'] !== '') {
        $shown = (string) $row['local_phone'];
    }
    return subscriber_no_whatsapp_for_current(
        isset($row['last_msg_response']) ? $row['last_msg_response'] : '',
        isset($row['last_msg_phone']) ? $row['last_msg_phone'] : '',
        $shown,
        false
    );
}
}

if (!function_exists('subscriber_whatsapp_phone')) {
function subscriber_whatsapp_phone($pdo, $subscriberId, $fallback = '')
{
    $subscriberId = (int) $subscriberId;
    $candidates = array();
    if ($subscriberId > 0 && $pdo) {
        try {
            $st = $pdo->prepare(
                'SELECT phone FROM sas_users_cache
                 WHERE local_subscriber_id = :id AND phone IS NOT NULL AND phone <> \'\'
                 LIMIT 5'
            );
            $st->execute(array(':id' => $subscriberId));
            while ($cachePhone = $st->fetchColumn()) {
                $candidates[] = (string) $cachePhone;
            }
        } catch (Exception $e) {
        }
        try {
            $stU = $pdo->prepare('SELECT sas_username, phone FROM subscribers WHERE id = :id LIMIT 1');
            $stU->execute(array(':id' => $subscriberId));
            $loc = $stU->fetch();
            if ($loc) {
                $candidates[] = isset($loc['phone']) ? (string) $loc['phone'] : '';
                $u = isset($loc['sas_username']) ? trim((string) $loc['sas_username']) : '';
                if ($u !== '') {
                    $stC = $pdo->prepare(
                        'SELECT phone FROM sas_users_cache WHERE username = :u AND phone IS NOT NULL AND phone <> \'\' LIMIT 1'
                    );
                    $stC->execute(array(':u' => $u));
                    $cp = $stC->fetchColumn();
                    if ($cp !== false) {
                        array_unshift($candidates, (string) $cp);
                    }
                }
            }
        } catch (Exception $e2) {
        }
    }
    $candidates[] = $fallback;
    if (function_exists('phone_first_valid')) {
        return phone_first_valid($candidates);
    }
    foreach ($candidates as $p) {
        $p = trim((string) $p);
        if ($p !== '' && $p !== '964000000000') {
            return $p;
        }
    }
    return '';
}
}

if (!function_exists('subscriber_should_hide_no_whatsapp')) {
function subscriber_should_hide_no_whatsapp($phones, $everOk = false)
{
    if ($everOk) {
        return true;
    }
    if (!is_array($phones)) {
        $phones = array($phones);
    }
    $known = phones_known_on_whatsapp();
    foreach ($phones as $phone) {
        foreach (phone_wa_keys($phone) as $k) {
            if (!empty($known[$k])) {
                return true;
            }
        }
    }
    return false;
}
}

if (!function_exists('msg_table_status_html')) {
function msg_table_status_html($hasMsg, $msgOk, $noWa, $rowId, $logId, $lang = 'ar')
{
    $retryTitle = ($lang === 'en') ? 'Retry send' : 'إعادة المحاولة';
    $noWaLabel = ($lang === 'en') ? 'No WhatsApp' : 'ماكو واتساب';
    $noWaTitle = ($lang === 'en') ? 'This number is not on WhatsApp' : 'لا يتوفر واتساب لدى المشترك';
    $retryBtn = '';
    if ((int) $logId > 0) {
        $retryBtn = '<button type="button" class="msg-retry-btn" data-retry="1" data-id="'
            . e((string) $rowId) . '" data-log-id="' . (int) $logId . '" title="' . e($retryTitle)
            . '" aria-label="' . e($retryTitle) . '">'
            . '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">'
            . '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" d="M13.15 3.45A6 6 0 1 0 14 8"/>'
            . '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" d="M13.2 1.55v3.15h-3.15"/>'
            . '</svg></button>';
    }
    $html = '<span class="msg-status-row">';
    if (!$hasMsg) {
        $html .= '<span class="dot-msg off" title="' . e($lang === 'en' ? 'No message sent' : 'لم تُرسل رسالة') . '"></span>';
    } elseif ($msgOk) {
        $html .= '<span class="dot-msg ok" title="' . e($lang === 'en' ? 'Sent' : 'أُرسلت') . '"></span>';
    } elseif ($noWa) {
        $html .= '<span class="msg-nowa" title="' . e($noWaTitle) . '">' . e($noWaLabel) . '</span>';
        $html .= $retryBtn;
    } else {
        $html .= '<span class="dot-msg fail" title="' . e($lang === 'en' ? 'Send failed' : 'فشل الإرسال') . '"></span>';
        $html .= $retryBtn;
    }
    $html .= '</span>';
    return $html;
}
}

function whatsapp_fail_user_message($result, $fallback = 'فشل إرسال واتساب')
{
    if (!empty($result['success'])) {
        return '';
    }
    if (!empty($result['no_whatsapp'])) {
        return 'لا يتوفر واتساب لدى المشترك';
    }
    $resp = isset($result['response']) ? (string) $result['response'] : '';
    if ($resp !== '' && function_exists('subscriber_msg_is_no_whatsapp') && subscriber_msg_is_no_whatsapp($resp)) {
        return 'لا يتوفر واتساب لدى المشترك';
    }
    if (!empty($result['skipped'])) {
        return 'واتساب غير مفعّل بالإعدادات';
    }

    $err = '';
    $decoded = json_decode($resp, true);
    if (is_array($decoded) && isset($decoded['error']) && (string) $decoded['error'] !== '') {
        $err = (string) $decoded['error'];
    } elseif ($resp !== '') {
        $err = $resp;
    }

    $http = isset($result['http_code']) ? (int) $result['http_code'] : 0;
    if ($http === 0 && ($err === '' || stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false || stripos($err, 'Failed to connect') !== false)) {
        if (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) {
            return 'واتساب متصل لكن الإرسال تأخر — أعد المحاولة';
        }
        return 'السيرفر ما وصل لبوابة واتساب — تأكد أن الجهاز شغّال';
    }
    if ($err !== '') {
        if (stripos($err, 'not ready') !== false || stripos($err, 'Scan QR') !== false) {
            return 'فشلت إعادة الإرسال — تأكد أن واتساب متصل';
        }
        if (stripos($err, 'circular') !== false || stripos($err, 'Converting circular') !== false) {
            return 'الرسالة انرسلت لكن رد البوابة فشل — حدّث ملف البوابة وأعد تشغيلها';
        }
        if (stripos($err, 'timeout') !== false || stripos($err, 'timed out') !== false) {
            return 'واتساب متصل لكن الإرسال تأخر — أعد المحاولة';
        }
        if (stripos($err, 'Connection Closed') !== false || stripos($err, 'Connection Terminated') !== false) {
            return 'انقطع اتصال واتساب أثناء الإرسال — أعد تشغيل البوابة ثم أعد المحاولة';
        }
        if (stripos($err, 'Forbidden') !== false) {
            return 'مفتاح البوابة غير مطابق';
        }
        $short = trim(preg_replace('/\s+/', ' ', $err));
        if (function_exists('mb_substr')) {
            if (mb_strlen($short, 'UTF-8') > 140) {
                $short = mb_substr($short, 0, 137, 'UTF-8') . '...';
            }
        } elseif (strlen($short) > 140) {
            $short = substr($short, 0, 137) . '...';
        }
        return 'فشل الإرسال: ' . $short;
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

/** تعليم أن تذكير قرب الانتهاء أُرسل لهذا التاريخ */
function mark_expiry_notice_sent($pdo, $subscriberId, $endDate)
{
    $subscriberId = (int) $subscriberId;
    $endDate = date('Y-m-d', strtotime((string) $endDate));
    if ($subscriberId <= 0 || !$endDate || strtotime($endDate) === false) {
        return;
    }
    if (function_exists('ensure_sas_expiry_remind_column')) {
        ensure_sas_expiry_remind_column($pdo);
    }
    try {
        $pdo->prepare(
            'UPDATE sas_users_cache SET expiry_remind_for_expire = :e
             WHERE local_subscriber_id = :id'
        )->execute(array(':e' => $endDate, ':id' => $subscriberId));
    } catch (Exception $e) {
    }
    try {
        $st = $pdo->prepare('SELECT sas_username FROM subscribers WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $subscriberId));
        $u = trim((string) $st->fetchColumn());
        if ($u !== '') {
            $pdo->prepare(
                'UPDATE sas_users_cache SET expiry_remind_for_expire = :e WHERE username = :u'
            )->execute(array(':e' => $endDate, ':u' => $u));
        }
    } catch (Exception $e) {
    }
}

/**
 * خريطة: subscriber_id => true إذا سبق إرسال إشعار قرب انتهاء لنفس تاريخ النهاية.
 * $rows عناصر فيها subscriber_id و end_date
 */
function subscribers_expiry_notice_map($pdo, $rows)
{
    $map = array();
    if (!$rows || !is_array($rows)) {
        return $map;
    }
    $byEnd = array();
    foreach ($rows as $row) {
        $sid = isset($row['subscriber_id']) ? (int) $row['subscriber_id'] : (isset($row['id']) ? (int) $row['id'] : 0);
        $end = isset($row['end_date']) ? date('Y-m-d', strtotime((string) $row['end_date'])) : '';
        if ($sid <= 0 || $end === '' || strtotime($end) === false) {
            continue;
        }
        if (!isset($byEnd[$end])) {
            $byEnd[$end] = array();
        }
        $byEnd[$end][$sid] = true;
    }
    if (!$byEnd) {
        return $map;
    }
    if (function_exists('ensure_sas_expiry_remind_column')) {
        try {
            ensure_sas_expiry_remind_column($pdo);
        } catch (Exception $e) {
        }
    }
    foreach ($byEnd as $end => $idsMap) {
        $ids = array_map('intval', array_keys($idsMap));
        if (!$ids) {
            continue;
        }
        $in = implode(',', $ids);
        try {
            $q = $pdo->query(
                "SELECT local_subscriber_id AS sid FROM sas_users_cache
                 WHERE local_subscriber_id IN ($in) AND expiry_remind_for_expire = " . $pdo->quote($end)
            );
            if ($q) {
                while ($r = $q->fetch()) {
                    $map[(int) $r['sid']] = true;
                }
            }
        } catch (Exception $e) {
        }
        try {
            $q2 = $pdo->query(
                "SELECT s.id AS sid FROM subscribers s
                 INNER JOIN sas_users_cache c ON c.username = s.sas_username
                 WHERE s.id IN ($in) AND c.expiry_remind_for_expire = " . $pdo->quote($end)
            );
            if ($q2) {
                while ($r = $q2->fetch()) {
                    $map[(int) $r['sid']] = true;
                }
            }
        } catch (Exception $e) {
        }
        // سجل رسائل ناجحة قرب الانتهاء تتضمن تاريخ النهاية
        try {
            $like = '%' . $end . '%';
            $st = $pdo->prepare(
                "SELECT DISTINCT subscriber_id FROM message_logs
                 WHERE success = 1
                   AND subscriber_id IN ($in)
                   AND message_type IN ('expiry_auto','bulk_filter','days_left','remind_days')
                   AND body LIKE :like"
            );
            $st->execute(array(':like' => $like));
            while ($sid = $st->fetchColumn()) {
                $map[(int) $sid] = true;
            }
        } catch (Exception $e) {
        }
    }
    return $map;
}

function delete_failed_message_log($pdo, $logId)
{
    $logId = (int) $logId;
    if ($logId <= 0) {
        return array(false, 'معرّف غير صالح');
    }
    try {
        $st = $pdo->prepare('SELECT id, success FROM message_logs WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $logId));
        $row = $st->fetch();
        if (!$row) {
            return array(false, 'الرسالة غير موجودة');
        }
        if (!empty($row['success'])) {
            return array(false, 'ما يصير حذف رسالة ناجحة');
        }
        $pdo->prepare('DELETE FROM message_logs WHERE id = :id AND success = 0')
            ->execute(array(':id' => $logId));
        return array(true, 'تم الحذف');
    } catch (Exception $e) {
        return array(false, 'فشل الحذف');
    }
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
    $phone = function_exists('subscriber_whatsapp_phone')
        ? subscriber_whatsapp_phone($pdo, (int) $log['sid'], !empty($log['sub_phone']) ? $log['sub_phone'] : $log['phone'])
        : (!empty($log['sub_phone']) ? $log['sub_phone'] : $log['phone']);
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

function wa_template_choices($lang = 'ar', $cfg = null)
{
    if ($cfg === null) {
        global $config;
        $cfg = (isset($config) && is_array($config)) ? $config : null;
    }
    if (is_array($cfg) && !empty($cfg['template_labels']) && is_array($cfg['template_labels'])) {
        return $cfg['template_labels'];
    }
    if (is_array($cfg) && !empty($cfg['wa_templates']) && is_array($cfg['wa_templates'])) {
        $out = array();
        foreach ($cfg['wa_templates'] as $k => $row) {
            $out[$k] = is_array($row) && isset($row['label']) && $row['label'] !== ''
                ? (string) $row['label']
                : $k;
        }
        if ($out) {
            return $out;
        }
    }
    return function_exists('wa_default_template_labels')
        ? wa_default_template_labels($lang)
        : array();
}

function wa_case_labels($lang = 'ar')
{
    $en = ($lang === 'en');
    return array(
        'activation_cash' => $en ? '1) Cash activation' : '1) تفعيل نقدي',
        'activation_credit' => $en ? '2) Credit activation' : '2) تفعيل آجل',
        'activation_credit_debts' => $en ? '3) Credit + old debts' : '3) تفعيل آجل + ديون قديمة',
        'activation_debts' => $en ? '4) Old-debts appendix' : '4) ملحق ديون قديمة',
        'debt_created' => $en ? 'Debt added' : 'إضافة دين',
        'payment_ok' => $en ? 'Payment received' : 'استلام التسديد',
        'debt_remind' => $en ? 'Debt reminder (manual / bulk)' : 'تذكير دين (يدوي / جماعي)',
        'reminder_auto' => $en ? 'Debt reminder (automatic)' : 'تذكير دين (تلقائي)',
        'days_left' => $en ? 'Days left (manual send)' : 'أيام متبقية (إرسال يدوي)',
        'unpaid_overdue' => $en ? 'Late payers warning' : 'تحذير المتأخرين بالدفع',
        'expiry_soon' => $en ? 'Expiry soon (auto cron)' : 'قرب الانتهاء (تلقائي)',
        'schedule_cut' => $en ? 'Auto-cut after grace' : 'القطع التلقائي بعد السماح',
    );
}

/**
 * Fixed system events that must be mapped to a template.
 */
function wa_system_cases($lang = 'ar')
{
    $en = ($lang === 'en');
    $labels = wa_case_labels($lang);
    return array(
        array(
            'group' => $en ? 'Activation (pick a template for each case)' : 'التفعيل (خصّص قالباً لكل حالة)',
            'cases' => array(
                array(
                    'key' => 'activation_cash',
                    'label' => $labels['activation_cash'],
                    'vars' => '{name} {package} {from} {to} {amount}',
                    'hint' => $en ? 'When activating with cash payment.' : 'عند التفعيل نقداً.',
                    'required' => true,
                ),
                array(
                    'key' => 'activation_credit',
                    'label' => $labels['activation_credit'],
                    'vars' => '{name} {package} {from} {to} {amount}',
                    'hint' => $en
                        ? 'When activating on credit without attaching old debts.'
                        : 'عند التفعيل بالآجل بدون إرفاق ديون قديمة.',
                    'required' => true,
                ),
                array(
                    'key' => 'activation_credit_debts',
                    'label' => $labels['activation_credit_debts'],
                    'vars' => '{name} {package} {from} {to} {amount} {debt} {month} {notes}',
                    'hint' => $en
                        ? 'When activating on credit and attaching old debts — one message.'
                        : 'عند التفعيل بالآجل مع إرفاق ديون قديمة — رسالة واحدة.',
                    'required' => true,
                ),
                array(
                    'key' => 'activation_debts',
                    'label' => $labels['activation_debts'],
                    'vars' => '{name} {debt} {amount} {month} {notes}',
                    'hint' => $en
                        ? 'Short appendix with cash activation when old debts are attached.'
                        : 'ملحق يُضاف مع التفعيل النقدي عند إرفاق ديون قديمة.',
                    'required' => true,
                ),
            ),
        ),
        array(
            'group' => $en ? 'Debts & payments' : 'الديون والتسديد',
            'cases' => array(
                array(
                    'key' => 'debt_created',
                    'label' => $labels['debt_created'],
                    'vars' => '{name} {amount} {month} {notes}',
                    'hint' => $en ? 'When staff add a debt.' : 'عند إضافة دين من النظام.',
                    'required' => true,
                ),
                array(
                    'key' => 'payment_ok',
                    'label' => $labels['payment_ok'],
                    'vars' => '{name} {amount} {month} {remaining}',
                    'hint' => $en ? 'After a successful payment.' : 'بعد تسجيل تسديد ناجح.',
                    'required' => true,
                ),
                array(
                    'key' => 'debt_remind',
                    'label' => $labels['debt_remind'],
                    'vars' => '{name} {debt} {amount} {month}',
                    'hint' => $en ? 'Manual remind and bulk “has debt”.' : 'تذكير يدوي والإرسال الجماعي لمن عليهم دين.',
                    'required' => true,
                ),
                array(
                    'key' => 'reminder_auto',
                    'label' => $labels['reminder_auto'],
                    'vars' => '{name} {debt} {amount} {month}',
                    'hint' => $en ? 'Automatic cron debt reminders.' : 'تذكير الديون التلقائي بالكرون.',
                    'required' => true,
                ),
            ),
        ),
        array(
            'group' => $en ? 'Reminders & cuts' : 'التذكيرات والقطع',
            'cases' => array(
                array(
                    'key' => 'days_left',
                    'label' => $labels['days_left'],
                    'vars' => '{name} {days} {package} {from} {to} {debt}',
                    'hint' => $en ? 'Manual bulk from Messages → Expiring.' : 'الإرسال اليدوي من تبويب قرب الانتهاء.',
                    'required' => true,
                ),
                array(
                    'key' => 'expiry_soon',
                    'label' => $labels['expiry_soon'],
                    'vars' => '{name} {days} {package} {to}',
                    'hint' => $en ? 'Automatic expiry reminder (Schedule settings).' : 'تذكير قرب الانتهاء التلقائي (إعدادات الجدول الدوري).',
                    'required' => true,
                ),
                array(
                    'key' => 'unpaid_overdue',
                    'label' => $labels['unpaid_overdue'],
                    'vars' => '{name} {days_passed} {debt} {package}',
                    'hint' => $en
                        ? 'Warning N days after activation (Schedule settings / Messages bulk).'
                        : 'تنبيه بعد أيام من التفعيل (إعدادات الجدول / إرسال جماعي).',
                    'required' => true,
                ),
                array(
                    'key' => 'schedule_cut',
                    'label' => $labels['schedule_cut'],
                    'vars' => '{name} {debt} {grace} {package}',
                    'hint' => $en ? 'When auto-cut disconnects unpaid lines.' : 'عند القطع التلقائي بسبب الدين.',
                    'required' => true,
                ),
            ),
        ),
    );
}

function wa_case_issue_message($issue, $caseLabel, $lang = 'ar')
{
    $en = ($lang === 'en');
    if ($issue === 'unassigned') {
        return $en
            ? ('Choose a template for: ' . $caseLabel)
            : ('لازم تختار قالب لـ: ' . $caseLabel);
    }
    if ($issue === 'missing_template') {
        return $en
            ? ('Template missing for: ' . $caseLabel . ' — pick another.')
            : ('القالب المربوط غير موجود لـ: ' . $caseLabel . ' — اختر قالباً آخر.');
    }
    if ($issue === 'empty_body') {
        return $en
            ? ('Template text is empty for: ' . $caseLabel)
            : ('نص القالب فارغ لـ: ' . $caseLabel . ' — اكتب النص أو غيّر القالب.');
    }
    return $en ? ('Check template for: ' . $caseLabel) : ('راجع القالب لـ: ' . $caseLabel);
}

/** @deprecated kept for compatibility */
function wa_template_editor_groups($lang = 'ar')
{
    return array();
}

/** @deprecated kept for compatibility */
function wa_case_editor_groups($lang = 'ar')
{
    $groups = wa_system_cases($lang);
    $out = array();
    foreach ($groups as $g) {
        $keys = array();
        foreach ($g['cases'] as $c) {
            $keys[] = $c['key'];
        }
        $out[] = array('title' => $g['group'], 'keys' => $keys);
    }
    return $out;
}

function wa_case_template_key($config, $case)
{
    $case = trim((string) $case);
    if ($case === '' || $case === 'activation') {
        $case = 'activation_cash';
    }
    if (isset($config['wa_cases'][$case]) && trim((string) $config['wa_cases'][$case]) !== '') {
        return (string) $config['wa_cases'][$case];
    }
    $fallback = array(
        'activation_cash' => 'activation',
        'activation_credit' => 'activation_credit',
        'activation_debts' => 'activation_debts',
        'activation_credit_debts' => 'activation_credit_debts',
        'activation' => 'activation',
        'debt_created' => 'debt_created',
        'payment_ok' => 'payment_ok',
        'debt_remind' => 'debt_remind',
        'reminder_auto' => 'debt_remind',
        'days_left' => 'days_left',
        'unpaid_overdue' => 'unpaid_overdue',
        'expiry_soon' => 'expiry_soon',
        'schedule_cut' => 'schedule_cut',
    );
    return isset($fallback[$case]) ? $fallback[$case] : $case;
}

function wa_render_named_template($key, $sub, $config, $extra = array())
{
    $key = trim((string) $key);
    if ($key === '') {
        $key = 'activation';
    }
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $amountVal = isset($extra['amount']) ? $extra['amount'] : (isset($sub['monthly_price']) ? $sub['monthly_price'] : 0);
    $debtVal = isset($extra['debt']) ? $extra['debt'] : (isset($sub['debt_total']) ? $sub['debt_total'] : $amountVal);
    $vars = array(
        'name' => isset($sub['name']) ? $sub['name'] : '',
        'package' => isset($extra['package']) ? $extra['package'] : (isset($sub['service_name']) ? $sub['service_name'] : ''),
        'from' => isset($sub['start_date']) ? $sub['start_date'] : '',
        'to' => isset($sub['end_date']) ? $sub['end_date'] : '',
        'amount' => money_format_iqd($amountVal, $currency),
        'debt' => money_format_iqd($debtVal, $currency),
        'month' => function_exists('month_short_label')
            ? month_short_label(isset($sub['month_label']) ? $sub['month_label'] : date('Y-m'))
            : date('Y-m'),
        'notes' => isset($extra['notes']) ? $extra['notes'] : (isset($sub['notes']) ? $sub['notes'] : ''),
        'days' => isset($extra['days']) ? $extra['days'] : (isset($sub['days']) ? $sub['days'] : ''),
        'days_passed' => isset($extra['days_passed']) ? $extra['days_passed'] : '',
        'remaining' => isset($extra['remaining'])
            ? money_format_iqd($extra['remaining'], $currency)
            : '',
    );
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    }
    if ($tpl === '' && $key === 'activation' && isset($config['templates']['activation'])) {
        $tpl = $config['templates']['activation'];
    }
    if ($tpl === '') {
        return '';
    }
    return function_exists('tpl_fill') ? tpl_fill($tpl, $vars) : $tpl;
}

function activation_message($sub, $config, $extraNote = '')
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $amount = money_format_iqd($sub['monthly_price'], $currency);
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'activation') : 'activation';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['activation']) && trim((string) $config['templates']['activation']) !== '') {
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
    $case = !empty($row['_wa_case']) ? (string) $row['_wa_case'] : 'debt_remind';
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, $case) : 'debt_remind';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['debt_remind']) && trim((string) $config['templates']['debt_remind']) !== '') {
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
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'debt_created') : 'debt_created';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['debt_created']) && trim((string) $config['templates']['debt_created']) !== '') {
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
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'days_left') : 'days_left';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['days_left']) && trim((string) $config['templates']['days_left']) !== '') {
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
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'unpaid_overdue') : 'unpaid_overdue';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['unpaid_overdue']) && trim((string) $config['templates']['unpaid_overdue']) !== '') {
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
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'payment_ok') : 'payment_ok';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['payment_ok']) && trim((string) $config['templates']['payment_ok']) !== '') {
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
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'expiry_soon') : 'expiry_soon';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['expiry_soon']) && trim((string) $config['templates']['expiry_soon']) !== '') {
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

function ensure_sas_expiry_remind_column($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (function_exists('ensure_sas_users_cache_table')) {
        try {
            ensure_sas_users_cache_table($pdo);
        } catch (Exception $e) {
            // ignore
        }
    }
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM sas_users_cache LIKE 'expiry_remind_for_expire'");
        if ($chk && $chk->fetch()) {
            return;
        }
        $pdo->exec('ALTER TABLE sas_users_cache ADD COLUMN expiry_remind_for_expire DATE NULL DEFAULT NULL');
    } catch (Exception $e) {
        // ignore
    }
}

/**
 * إرسال تذكير لمن ينتهي اشتراكهم خلال N أيام
 * (اشتراكات محلية + تواريخ انتهاء كاش الساس)
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
    ensure_sas_expiry_remind_column($pdo);
    try {
        $pdo->exec(
            "UPDATE subscriptions SET status = 'expired'
             WHERE status = 'active' AND end_date < CURDATE()"
        );
    } catch (Exception $e) {
        // ignore
    }

    $limit = max(1, (int) $limit);
    $doneUsers = array();

    // 1) تواريخ الانتهاء من كاش المشتركين
    $sasRows = array();
    if (function_exists('sas_list_expiring_rows')) {
        $sasRows = sas_list_expiring_rows($pdo, $daysN, $limit * 3);
    }
    // صفّي من تذكّروا مسبقاً لنفس تاريخ الانتهاء
    $sasFiltered = array();
    foreach ($sasRows as $crow) {
        $u = isset($crow['username']) ? trim((string) $crow['username']) : '';
        if ($u === '') {
            continue;
        }
        try {
            $stR = $pdo->prepare(
                'SELECT expiry_remind_for_expire FROM sas_users_cache WHERE username = :u LIMIT 1'
            );
            $stR->execute(array(':u' => $u));
            $prev = $stR->fetchColumn();
            $endDate = date('Y-m-d', strtotime((string) $crow['expire_at']));
            if ($prev !== false && $prev !== null && (string) $prev === $endDate) {
                continue;
            }
        } catch (Exception $e) {
            // ignore
        }
        $crow['subscriber_id'] = isset($crow['sub_id']) ? (int) $crow['sub_id'] : 0;
        $sasFiltered[] = $crow;
        if (count($sasFiltered) >= $limit) {
            break;
        }
    }
    $sasRows = $sasFiltered;

    foreach ($sasRows as $crow) {
        if ($out['sent'] + $out['failed'] + $out['skipped'] >= $limit) {
            break;
        }
        $username = isset($crow['username']) ? trim((string) $crow['username']) : '';
        if ($username === '' || isset($doneUsers[$username])) {
            continue;
        }
        $out['checked']++;
        $sid = isset($crow['subscriber_id']) ? (int) $crow['subscriber_id'] : 0;
        if ($sid <= 0 && !empty($crow['local_subscriber_id'])) {
            $sid = (int) $crow['local_subscriber_id'];
        }
        if ($sid <= 0 && function_exists('sas_cache_ensure_local')) {
            list($sid, $errLink) = sas_cache_ensure_local($pdo, $config, $crow);
            $sid = (int) $sid;
        }
        if ($sid <= 0) {
            $out['skipped']++;
            continue;
        }
        $name = '';
        if (!empty($crow['sub_name'])) {
            $name = (string) $crow['sub_name'];
        } elseif (!empty($crow['display_name'])) {
            $name = (string) $crow['display_name'];
        } else {
            $name = $username;
        }
        $phone = '';
        if (function_exists('phone_first_valid')) {
            $phone = phone_first_valid(array(
                isset($crow['sas_phone']) ? $crow['sas_phone'] : '',
                isset($crow['sub_phone']) ? $crow['sub_phone'] : '',
            ));
        }
        if ($phone === '' && function_exists('subscriber_whatsapp_phone')) {
            $phone = subscriber_whatsapp_phone($pdo, $sid, '');
        }
        if ($phone === '') {
            try {
                $stPh = $pdo->prepare('SELECT phone FROM subscribers WHERE id = :id LIMIT 1');
                $stPh->execute(array(':id' => $sid));
                $phone = (string) $stPh->fetchColumn();
            } catch (Exception $e) {
                $phone = '';
            }
        }
        if ($phone === '' || (function_exists('phone_is_placeholder') && phone_is_placeholder($phone))) {
            $out['skipped']++;
            continue;
        }
        $endDate = date('Y-m-d', strtotime((string) $crow['expire_at']));
        $startDate = date('Y-m-d', strtotime($endDate . ' -30 days'));
        $info = subscription_days_info($startDate, $endDate);
        $pkg = isset($crow['profile_name']) ? (string) $crow['profile_name'] : '';
        $body = expiry_soon_message(array(
            'name' => $name,
            'days' => isset($crow['_days']) ? (int) $crow['_days'] : (int) $info['left'],
            'package' => $pkg,
            'from' => $startDate,
            'to' => $endDate,
        ), $config);
        $result = whatsapp_send($config, $phone, $body, 'expiry_auto');
        log_message($pdo, $sid, $result);
        $doneUsers[$username] = true;
        if (!empty($result['success'])) {
            try {
                $pdo->prepare(
                    'UPDATE sas_users_cache SET expiry_remind_for_expire = :e WHERE username = :u'
                )->execute(array(':e' => $endDate, ':u' => $username));
            } catch (Exception $e) {
                // ignore
            }
            $out['sent']++;
            usleep(250000);
        } elseif (!empty($result['skipped'])) {
            $out['skipped']++;
        } else {
            $out['failed']++;
            usleep(150000);
        }
    }

    // 2) اشتراكات محلية بدون ربط ساس (أو بدون صف كاش)
    $remain = $limit - (int) $out['sent'] - (int) $out['failed'] - (int) $out['skipped'];
    if ($remain < 1) {
        $remain = 0;
    }
    if ($remain > 0) {
        $sql = 'SELECT sub.id AS sub_id, sub.subscriber_id, sub.service_name, sub.start_date, sub.end_date,
                       s.name, s.phone, s.sas_username
                FROM subscriptions sub
                JOIN subscribers s ON s.id = sub.subscriber_id
                WHERE sub.status = \'active\'
                  AND sub.end_date >= CURDATE()
                  AND sub.end_date <= DATE_ADD(CURDATE(), INTERVAL ' . (int) $daysN . ' DAY)
                  AND (sub.expiry_remind_for_end IS NULL OR sub.expiry_remind_for_end <> sub.end_date)
                ORDER BY sub.end_date ASC
                LIMIT ' . (int) $remain;
        try {
            $rows = $pdo->query($sql)->fetchAll();
        } catch (Exception $e) {
            $rows = array();
        }
        foreach ($rows as $row) {
            $u = isset($row['sas_username']) ? trim((string) $row['sas_username']) : '';
            if ($u !== '' && isset($doneUsers[$u])) {
                continue;
            }
            $out['checked']++;
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
            if ($u !== '') {
                $doneUsers[$u] = true;
            }
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
    }
    return $out;
}

/** تشغيل خفيف من الواجهة (تذكير انتهاء + قطع) بدون كرون */
function maybe_run_expiry_auto_reminders($pdo, $config)
{
    if (function_exists('maybe_run_auto_schedule_jobs')) {
        maybe_run_auto_schedule_jobs($pdo, $config);
        return;
    }
    if (empty($config['expiry_auto_remind_enabled'])) {
        return;
    }
    $lock = __DIR__ . '/../config/expiry_auto_remind.lock';
    $now = time();
    if (is_file($lock)) {
        $prev = (int) trim((string) @file_get_contents($lock));
        if ($prev > 0 && ($now - $prev) < 180) {
            return;
        }
    }
    @file_put_contents($lock, (string) $now);
    @run_expiry_soon_reminders($pdo, $config, 25);
}

/**
 * تشغيل تلقائي للتذكير والقطع + تحديث بيانات الانتهاء أثناء استخدام اللوحة.
 */
function maybe_run_auto_schedule_jobs($pdo, $config)
{
    $lock = __DIR__ . '/../config/auto_schedule.lock';
    $now = time();
    if (is_file($lock)) {
        $prev = (int) trim((string) @file_get_contents($lock));
        if ($prev > 0 && ($now - $prev) < 120) {
            return;
        }
    }
    @file_put_contents($lock, (string) $now);

    if (function_exists('sas_maybe_background_sync')) {
        try {
            @sas_maybe_background_sync($pdo, $config, false);
        } catch (Exception $e) {
            // ignore
        }
    }

    if (!empty($config['expiry_auto_remind_enabled']) && function_exists('run_expiry_soon_reminders')) {
        try {
            @run_expiry_soon_reminders($pdo, $config, 25);
        } catch (Exception $e) {
            // ignore
        }
    }
    if (!empty($config['schedule_cut_enabled']) && function_exists('run_schedule_debt_cuts')) {
        try {
            @run_schedule_debt_cuts($pdo, $config, 40);
        } catch (Exception $e) {
            // ignore
        }
    }
}
