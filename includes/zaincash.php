<?php

/**
 * تكامل ZainCash Iraq — PHP 7.0 بدون Composer
 * تدفق: JWT → POST /transaction/init → redirect /transaction/pay?id=
 * Callback: ?token= JWT يحتوي status / orderid / id
 */

function zaincash_cfg($settings = null)
{
    if (function_exists('saas_settings')) {
        $s = saas_settings($settings);
        return array(
            'merchant_id' => $s['zaincash_merchant_id'],
            'secret' => $s['zaincash_secret'],
            'msisdn' => $s['zaincash_msisdn'],
            'production' => !empty($s['zaincash_production']),
            'redirect_base' => $s['zaincash_redirect_base'],
        );
    }
    return array(
        'merchant_id' => '',
        'secret' => '',
        'msisdn' => '',
        'production' => false,
        'redirect_base' => '',
    );
}

function zaincash_is_configured($settings = null)
{
    $c = zaincash_cfg($settings);
    return $c['merchant_id'] !== '' && $c['secret'] !== '' && $c['msisdn'] !== '';
}

function zaincash_b64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function zaincash_b64url_decode($data)
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/** JWT HS256 بسيط */
function zaincash_jwt_encode(array $payload, $secret)
{
    $header = array('typ' => 'JWT', 'alg' => 'HS256');
    $segments = array(
        zaincash_b64url_encode(json_encode($header)),
        zaincash_b64url_encode(json_encode($payload)),
    );
    $signing = implode('.', $segments);
    $sig = hash_hmac('sha256', $signing, $secret, true);
    $segments[] = zaincash_b64url_encode($sig);
    return implode('.', $segments);
}

function zaincash_jwt_decode($token, $secret)
{
    $parts = explode('.', (string) $token);
    if (count($parts) !== 3) {
        return null;
    }
    $signing = $parts[0] . '.' . $parts[1];
    $expected = zaincash_b64url_encode(hash_hmac('sha256', $signing, $secret, true));
    if (!hash_equals($expected, $parts[2])) {
        return null;
    }
    $json = zaincash_b64url_decode($parts[1]);
    $payload = json_decode($json, true);
    return is_array($payload) ? $payload : null;
}

function zaincash_endpoints($production)
{
    if ($production) {
        return array(
            'init' => 'https://api.zaincash.iq/transaction/init',
            'pay' => 'https://api.zaincash.iq/transaction/pay?id=',
        );
    }
    return array(
        'init' => 'https://test.zaincash.iq/transaction/init',
        'pay' => 'https://test.zaincash.iq/transaction/pay?id=',
    );
}

function zaincash_callback_url($settings = null)
{
    $c = zaincash_cfg($settings);
    $base = $c['redirect_base'];
    if ($base === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $base = ($https ? 'https://' : 'http://') . $host;
        // إن كان التطبيق تحت مجلد public
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '';
        if ($script !== '' && $script !== '/' && $script !== '.') {
            $base .= rtrim($script, '/');
        }
    }
    return rtrim($base, '/') . '/zaincash_callback.php';
}

/**
 * إنشاء معاملة والدفع — يرجع [ok, payUrl|error, zaincashId, orderId]
 */
function zaincash_initiate($amount, $serviceType, $orderId, $settings = null, $lang = 'ar')
{
    $c = zaincash_cfg($settings);
    if (!zaincash_is_configured($settings)) {
        return array(false, 'إعدادات ZainCash ناقصة', '', $orderId);
    }
    $amount = (int) round((float) $amount);
    if ($amount < 250) {
        return array(false, 'أقل مبلغ لـ ZainCash هو 250 دينار', '', $orderId);
    }
    $now = time();
    $payload = array(
        'amount' => $amount,
        'serviceType' => (string) $serviceType,
        'msisdn' => $c['msisdn'],
        'orderId' => (string) $orderId,
        'redirectUrl' => zaincash_callback_url($settings),
        'iat' => $now,
        'exp' => $now + (4 * 3600),
    );
    $token = zaincash_jwt_encode($payload, $c['secret']);
    $ep = zaincash_endpoints(!empty($c['production']));
    $post = http_build_query(array(
        'token' => $token,
        'merchantId' => $c['merchant_id'],
        'lang' => ($lang === 'en') ? 'en' : 'ar',
    ));
    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $post,
            'timeout' => 45,
            'ignore_errors' => true,
        ),
    ));
    $raw = @file_get_contents($ep['init'], false, $ctx);
    if ($raw === false || $raw === '') {
        return array(false, 'تعذر الاتصال بـ ZainCash', '', $orderId);
    }
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        return array(false, 'رد ZainCash غير مفهوم', '', $orderId);
    }
    if (isset($arr['err'])) {
        $msg = isset($arr['err']['msg']) ? (string) $arr['err']['msg'] : 'فشل إنشاء المعاملة';
        return array(false, $msg, '', $orderId);
    }
    if (empty($arr['id'])) {
        return array(false, 'ما رجع رقم معاملة من ZainCash', '', $orderId);
    }
    $zcId = (string) $arr['id'];
    return array(true, $ep['pay'] . $zcId, $zcId, $orderId);
}

function zaincash_decode_callback_token($token, $settings = null)
{
    $c = zaincash_cfg($settings);
    if ($c['secret'] === '') {
        return null;
    }
    return zaincash_jwt_decode($token, $c['secret']);
}

/**
 * بدء دفع اشتراك وحفظ صف في subscription_payments
 * @return array(bool, string urlOrError)
 */
function saas_start_plan_payment($pdo, $tenantId, $planCode, $settings = null, $lang = 'ar')
{
    ensure_tenants_schema($pdo);
    $saas = saas_settings($settings);
    $plans = $saas['plans'];
    if (!isset($plans[$planCode]) || !is_array($plans[$planCode])) {
        return array(false, 'باقة غير معروفة');
    }
    $plan = $plans[$planCode];
    $amount = isset($plan['amount']) ? (float) $plan['amount'] : 0;
    $days = isset($plan['days']) ? (int) $plan['days'] : 30;
    if ($amount <= 0) {
        return array(false, 'سعر الباقة غير مضبوط');
    }
    $orderId = 'sub_' . (int) $tenantId . '_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6);
    try {
        $pdo->prepare(
            'INSERT INTO subscription_payments
                (tenant_id, amount, currency, plan_code, period_days, order_id, status)
             VALUES (:t, :a, "IQD", :p, :d, :o, "initiated")'
        )->execute(array(
            ':t' => (int) $tenantId,
            ':a' => $amount,
            ':p' => $planCode,
            ':d' => $days,
            ':o' => $orderId,
        ));
    } catch (Exception $e) {
        return array(false, 'تعذر تسجيل الدفعة');
    }
    $label = isset($plan['label']) ? $plan['label'] : $planCode;
    list($ok, $urlOrErr, $zcId) = zaincash_initiate(
        $amount,
        'اشتراك ' . $label,
        $orderId,
        $settings,
        $lang
    );
    if (!$ok) {
        try {
            $pdo->prepare('UPDATE subscription_payments SET status = "failed", raw_response = :r WHERE order_id = :o')
                ->execute(array(':r' => $urlOrErr, ':o' => $orderId));
        } catch (Exception $e) {
        }
        return array(false, $urlOrErr);
    }
    try {
        $pdo->prepare('UPDATE subscription_payments SET zaincash_id = :z WHERE order_id = :o')
            ->execute(array(':z' => $zcId, ':o' => $orderId));
    } catch (Exception $e) {
    }
    return array(true, $urlOrErr);
}

/**
 * معالجة رجوع ZainCash
 * @return array(bool, string message, int tenantId)
 */
function saas_handle_zaincash_callback($pdo, $token, $settings = null)
{
    $payload = zaincash_decode_callback_token($token, $settings);
    if (!$payload) {
        return array(false, 'رمز الدفع غير صالح', 0);
    }
    $status = isset($payload['status']) ? strtolower((string) $payload['status']) : '';
    $orderId = '';
    if (!empty($payload['orderid'])) {
        $orderId = (string) $payload['orderid'];
    } elseif (!empty($payload['orderId'])) {
        $orderId = (string) $payload['orderId'];
    }
    $zcId = isset($payload['id']) ? (string) $payload['id'] : '';
    if ($orderId === '') {
        return array(false, 'رقم الطلب مفقود', 0);
    }
    try {
        $st = $pdo->prepare('SELECT * FROM subscription_payments WHERE order_id = :o LIMIT 1');
        $st->execute(array(':o' => $orderId));
        $pay = $st->fetch();
    } catch (Exception $e) {
        return array(false, 'تعذر قراءة الدفعة', 0);
    }
    if (!$pay) {
        return array(false, 'الدفعة غير موجودة', 0);
    }
    $tenantId = (int) $pay['tenant_id'];
    if ($status !== 'success') {
        try {
            $pdo->prepare(
                'UPDATE subscription_payments SET status = "failed", raw_response = :r, zaincash_id = COALESCE(NULLIF(:z, ""), zaincash_id)
                 WHERE order_id = :o'
            )->execute(array(
                ':r' => json_encode($payload),
                ':z' => $zcId,
                ':o' => $orderId,
            ));
        } catch (Exception $e) {
        }
        return array(false, 'فشل الدفع أو أُلغي', $tenantId);
    }
    if (isset($pay['status']) && $pay['status'] === 'paid') {
        return array(true, 'الدفع مسجّل مسبقاً', $tenantId);
    }
    $days = isset($pay['period_days']) ? (int) $pay['period_days'] : 30;
    $planCode = isset($pay['plan_code']) ? $pay['plan_code'] : null;
    saas_extend_subscription($pdo, $tenantId, $days, $planCode);
    try {
        $pdo->prepare(
            'UPDATE subscription_payments SET status = "paid", paid_at = NOW(), raw_response = :r,
                zaincash_id = COALESCE(NULLIF(:z, ""), zaincash_id)
             WHERE order_id = :o'
        )->execute(array(
            ':r' => json_encode($payload),
            ':z' => $zcId,
            ':o' => $orderId,
        ));
    } catch (Exception $e) {
    }
    return array(true, 'تم تأكيد الدفع وتمديد الاشتراك', $tenantId);
}
