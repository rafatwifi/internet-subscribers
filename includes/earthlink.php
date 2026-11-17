<?php

/**
 * Earthlink Reseller client (PHP 7.0).
 *
 * مساران:
 *  1) REST: POST /token ثم user/all (يعمل على الشبكة الخاصة admin.earthlink.iq / VPN)
 *  2) بوابة ASP.NET عبر البروكسي العام:
 *     https://api.spicywifi-iq.com/earthlink-api  (بدون VPN على سيرفرنا)
 *
 * admin.earthlink.iq يحل إلى IP خاص — يُحوَّل تلقائياً إلى البروكسي العام.
 */

function earthlink_normalize_host($host)
{
    $host = trim((string) $host);
    $host = preg_replace('#^https?://#i', '', $host);
    $host = rtrim($host, '/');
    return $host;
}

function earthlink_default_alt_host()
{
    return 'api.spicywifi-iq.com/earthlink-api';
}

function earthlink_is_private_host($host)
{
    $low = strtolower(earthlink_normalize_host($host));
    return ($low === 'admin.earthlink.iq'
        || strpos($low, 'admin.earthlink.iq/') === 0
        || $low === '192.168.222.131');
}

/**
 * قائمة هوستات للتجربة: الأساسي ثم البديل (سبايسي)، بدون تكرار.
 * @return array
 */
function earthlink_host_candidates($host, $altHost = '')
{
    $out = array();
    foreach (array($host, $altHost) as $h) {
        $h = earthlink_normalize_host($h);
        if ($h === '') {
            continue;
        }
        if (!in_array($h, $out, true)) {
            $out[] = $h;
        }
    }
    // إن الأساسي خاص/VPN وماكو بديل صريح — أضف سبايسي تلقائياً
    if ($out && earthlink_is_private_host($out[0])) {
        $alt = earthlink_default_alt_host();
        if (!in_array($alt, $out, true)) {
            $out[] = $alt;
        }
    }
    return $out;
}

function earthlink_resolve_host($host)
{
    return earthlink_normalize_host($host);
}

function earthlink_base_url($host)
{
    $host = earthlink_normalize_host($host);
    if ($host === '') {
        return '';
    }
    if (stripos($host, 'http://') === 0 || stripos($host, 'https://') === 0) {
        return rtrim($host, '/');
    }
    return 'https://' . $host;
}

function earthlink_cookie_file($host, $username)
{
    $key = md5(strtolower(earthlink_normalize_host($host) . '|' . trim((string) $username)));
    $dir = sys_get_temp_dir();
    return $dir . DIRECTORY_SEPARATOR . 'el_ck_' . $key . '.txt';
}

/**
 * جلب هوست بديل من شركة المنصة إن وُجد.
 */
function earthlink_company_alt_host($pdo, $companyId)
{
    $companyId = (int) $companyId;
    if ($companyId <= 0 || !$pdo || !function_exists('platform_company_row')) {
        return '';
    }
    $co = platform_company_row($pdo, $companyId);
    if (!$co) {
        return '';
    }
    return earthlink_normalize_host(isset($co['api_alt_host']) ? $co['api_alt_host'] : '');
}

function earthlink_ua()
{
    return 'Mozilla/5.0 (compatible; SpicyWiFi-Agent/12.15.10)';
}

/**
 * @param string $altHost هوست بديل (مثل سبايسي)
 * @return array{ok:bool,token?:string,mode?:string,cookie_file?:string,host?:string,expires_at?:int,error?:string,raw?:mixed}
 */
function earthlink_auth($host, $username, $password, $force = false, $altHost = '')
{
    static $cache = array();
    $username = trim((string) $username);
    $password = (string) $password;
    $candidates = earthlink_host_candidates($host, $altHost);
    if (!$candidates || $username === '' || $password === '') {
        return array('ok' => false, 'error' => 'بيانات Earthlink ناقصة');
    }
    $cacheKey = strtolower(implode(',', $candidates) . '|' . $username);
    $now = time();
    if (!$force && isset($cache[$cacheKey]) && !empty($cache[$cacheKey]['ok'])
        && !empty($cache[$cacheKey]['expires_at']) && ($cache[$cacheKey]['expires_at'] - 60) > $now) {
        return $cache[$cacheKey];
    }

    $lastErr = 'فشل الدخول إلى Earthlink';
    foreach ($candidates as $tryHost) {
        // 1) REST /token
        $rest = earthlink_auth_rest($tryHost, $username, $password);
        if (!empty($rest['ok'])) {
            $rest['host'] = $tryHost;
            $cache[$cacheKey] = $rest;
            return $rest;
        }
        if (!empty($rest['error'])) {
            $lastErr = (string) $rest['error'];
        }
        // 2) بوابة Login.aspx
        $portal = earthlink_auth_portal($tryHost, $username, $password);
        if (!empty($portal['ok'])) {
            $portal['host'] = $tryHost;
            $cache[$cacheKey] = $portal;
            return $portal;
        }
        if (!empty($portal['error'])) {
            $lastErr = (string) $portal['error'];
        }
    }
    return array('ok' => false, 'error' => $lastErr);
}

/**
 * @return array
 */
function earthlink_auth_rest($host, $username, $password)
{
    $base = earthlink_base_url($host);
    $body = http_build_query(array(
        'username' => $username,
        'password' => $password,
        'loginType' => '1',
        'grant_type' => 'password',
    ));
    list($raw, $code, $err) = earthlink_http('POST', $base . '/token', $body, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'User-Agent: ' . earthlink_ua(),
    ), 20, null);
    if ($raw === false) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'تعذر الاتصال بهوست Earthlink');
    }
    // 404 = REST غير منشور على هذا المسار (شائع على البروكسي العام)
    if ((int) $code === 404 || (int) $code === 405) {
        return array('ok' => false, 'error' => 'REST /token غير متاح على هذا الهوست', 'code' => $code);
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return array(
            'ok' => false,
            'error' => 'الهوست ما رجّع token JSON. HTTP ' . (int) $code,
            'raw' => substr((string) $raw, 0, 200),
            'code' => $code,
        );
    }
    $token = '';
    if (!empty($json['access_token'])) {
        $token = (string) $json['access_token'];
    } elseif (!empty($json['accessToken'])) {
        $token = (string) $json['accessToken'];
    }
    if ($token === '') {
        $msg = '';
        if (!empty($json['error_description'])) {
            $msg = (string) $json['error_description'];
        } elseif (!empty($json['error'])) {
            $msg = (string) $json['error'];
        } else {
            $msg = 'رفض Earthlink تسجيل الدخول';
        }
        return array('ok' => false, 'error' => $msg, 'raw' => $json);
    }
    $exp = isset($json['expires_in']) ? (int) $json['expires_in'] : 3599;
    if ($exp < 60) {
        $exp = 60;
    }
    return array(
        'ok' => true,
        'mode' => 'api',
        'token' => $token,
        'expires_at' => time() + $exp,
    );
}

/**
 * تسجيل دخول بوابة ASP.NET (Login.aspx) — يعمل عبر البروكسي العام بدون VPN.
 * @return array
 */
function earthlink_auth_portal($host, $username, $password)
{
    $base = earthlink_base_url($host);
    $cookie = earthlink_cookie_file($host, $username);
    @unlink($cookie);

    list($html, $code, $err) = earthlink_http('GET', $base . '/Login.aspx', null, array(
        'Accept: text/html,application/xhtml+xml',
        'User-Agent: ' . earthlink_ua(),
    ), 20, $cookie);
    if ($html === false) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'تعذر فتح بوابة Earthlink');
    }
    if ((int) $code >= 400 && (int) $code !== 302) {
        return array('ok' => false, 'error' => 'بوابة Earthlink HTTP ' . (int) $code);
    }

    $fields = earthlink_portal_form_fields($html);
    if ($fields === null) {
        return array('ok' => false, 'error' => 'تعذر قراءة نموذج تسجيل الدخول');
    }

    $userField = isset($fields['_user_field']) ? $fields['_user_field'] : 'Login1$UserName';
    $passField = isset($fields['_pass_field']) ? $fields['_pass_field'] : 'Login1$Password';
    $submitField = isset($fields['_submit_field']) ? $fields['_submit_field'] : 'Login1$LoginButton';
    $submitVal = isset($fields['_submit_value']) ? $fields['_submit_value'] : 'Log In';
    unset($fields['_user_field'], $fields['_pass_field'], $fields['_submit_field'], $fields['_submit_value']);

    $fields[$userField] = $username;
    $fields[$passField] = $password;
    $fields[$submitField] = $submitVal;
    if (!isset($fields['Logincountf'])) {
        $fields['Logincountf'] = '0';
    }

    $body = http_build_query($fields);
    list($after, $code2, $err2) = earthlink_http('POST', $base . '/Login.aspx', $body, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: text/html,application/xhtml+xml',
        'User-Agent: ' . earthlink_ua(),
        'Referer: ' . $base . '/Login.aspx',
    ), 25, $cookie);

    if ($after === false) {
        return array('ok' => false, 'error' => $err2 !== '' ? $err2 : 'فشل إرسال تسجيل الدخول');
    }

    if (earthlink_portal_looks_like_login($after)) {
        $hint = 'بيانات دخول Earthlink غير صحيحة أو الحساب موقف';
        if (stripos($after, "doesn't match") !== false || stripos($after, 'does not match') !== false) {
            $hint = 'اسم المستخدم أو كلمة المرور غير صحيحة';
        }
        return array('ok' => false, 'error' => $hint);
    }

    // تأكيد الجلسة: Home بدون نموذج دخول (محاولة ثانية قصيرة إن تأخر الكوكي)
    $homeOk = false;
    $homeLooksLogin = false;
    $homeCode = 0;
    for ($try = 0; $try < 2; $try++) {
        if ($try > 0) {
            usleep(450000);
        }
        list($home, $hc, $he) = earthlink_http('GET', $base . '/Home.aspx', null, array(
            'Accept: text/html',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $base . '/Login.aspx',
        ), 20, $cookie);
        $homeCode = (int) $hc;
        if ($home === false) {
            continue;
        }
        if (earthlink_portal_looks_like_login($home)) {
            $homeLooksLogin = true;
            continue;
        }
        $homeOk = true;
        break;
    }
    if (!$homeOk) {
        if ($homeLooksLogin) {
            return array('ok' => false, 'error' => 'الجلسة لم تثبت بعد تسجيل الدخول (البوابة رجّعت صفحة دخول)');
        }
        if ($homeCode >= 400) {
            return array('ok' => false, 'error' => 'الجلسة لم تثبت — بوابة Earthlink HTTP ' . $homeCode);
        }
        return array('ok' => false, 'error' => 'الجلسة لم تثبت بعد تسجيل الدخول');
    }

    return array(
        'ok' => true,
        'mode' => 'portal',
        'token' => 'portal',
        'cookie_file' => $cookie,
        'expires_at' => time() + 1800,
    );
}

/**
 * @return array|null
 */
function earthlink_portal_form_fields($html)
{
    if (!is_string($html) || $html === '') {
        return null;
    }
    $out = array(
        '__EVENTTARGET' => '',
        '__EVENTARGUMENT' => '',
    );
    foreach (array('__VIEWSTATE', '__VIEWSTATEGENERATOR', '__EVENTVALIDATION', 'Logincountf', 'RadScriptManager1_TSM') as $name) {
        if (preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/i', $html, $m)
            || preg_match('/id="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/i', $html, $m)
        ) {
            $out[$name] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
    }
    if (empty($out['__VIEWSTATE'])) {
        return null;
    }

    $userField = 'Login1$UserName';
    $passField = 'Login1$Password';
    $submitField = 'Login1$LoginButton';
    $submitVal = 'Log In';
    if (preg_match('/name="(Login1\$UserName|[^"]*UserName[^"]*)"/i', $html, $m)) {
        $userField = $m[1];
    }
    if (preg_match('/<input[^>]+type="password"[^>]+name="([^"]+)"/i', $html, $m)) {
        $passField = $m[1];
    } elseif (preg_match('/name="(Login1\$Password|[^"]*Password[^"]*)"/i', $html, $m)) {
        $passField = $m[1];
    }
    if (preg_match('/<input[^>]+type="submit"[^>]+name="([^"]+)"[^>]*value="([^"]*)"/i', $html, $m)) {
        $submitField = $m[1];
        $submitVal = $m[2];
    } elseif (preg_match('/name="(Login1\$LoginButton)"[^>]*value="([^"]*)"/i', $html, $m)) {
        $submitField = $m[1];
        $submitVal = $m[2];
    }
    $out['_user_field'] = $userField;
    $out['_pass_field'] = $passField;
    $out['_submit_field'] = $submitField;
    $out['_submit_value'] = $submitVal;
    return $out;
}

function earthlink_portal_looks_like_login($html)
{
    if (!is_string($html) || $html === '') {
        return true;
    }
    if (stripos($html, 'Login1_UserName') !== false || stripos($html, 'Login1$UserName') !== false) {
        return true;
    }
    if (stripos($html, 'Username or password') !== false) {
        return true;
    }
    return false;
}

/**
 * @return array{0:string|false,1:int,2:string}
 */
function earthlink_http($method, $url, $body, $headers, $timeout = 25, $cookieFile = null)
{
    $method = strtoupper((string) $method);
    $ch = curl_init($url);
    // مهلة اتصال قصيرة للهوست الخاص حتى ننتقل للبديل بسرعة
    $connectTo = 10;
    if (is_string($url) && (stripos($url, 'admin.earthlink.iq') !== false || stripos($url, '192.168.222.') !== false)) {
        $connectTo = 3;
        if ((int) $timeout > 8) {
            $timeout = 8;
        }
    }
    $opts = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => (int) $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTo,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => is_array($headers) ? $headers : array(),
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERAGENT => earthlink_ua(),
    );
    if ($cookieFile) {
        $opts[CURLOPT_COOKIEJAR] = $cookieFile;
        $opts[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    if ($method === 'POST' || $method === 'PUT') {
        $opts[CURLOPT_POSTFIELDS] = $body !== null ? $body : '';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return array(false, 0, $err);
    }
    return array($raw, $code, '');
}

function earthlink_api_get($host, $token, $path, $cookieFile = null)
{
    $base = earthlink_base_url($host);
    $url = $base . '/' . ltrim((string) $path, '/');
    $headers = array(
        'Accept: application/json',
        'User-Agent: ' . earthlink_ua(),
    );
    if ($token && $token !== 'portal') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    list($raw, $code, $err) = earthlink_http('GET', $url, null, $headers, 30, $cookieFile);
    if ($raw === false) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'GET failed', 'code' => 0);
    }
    $json = json_decode($raw, true);
    return array(
        'ok' => ($code >= 200 && $code < 300),
        'code' => $code,
        'json' => is_array($json) ? $json : null,
        'raw' => $raw,
        'error' => ($code >= 200 && $code < 300) ? '' : ('HTTP ' . $code),
    );
}

function earthlink_api_post_json($host, $token, $path, $payload, $cookieFile = null)
{
    $base = earthlink_base_url($host);
    $url = $base . '/' . ltrim((string) $path, '/');
    $body = json_encode($payload);
    $headers = array(
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: ' . earthlink_ua(),
    );
    if ($token && $token !== 'portal') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    list($raw, $code, $err) = earthlink_http('POST', $url, $body, $headers, 45, $cookieFile);
    if ($raw === false) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'POST failed', 'code' => 0);
    }
    $json = json_decode($raw, true);
    return array(
        'ok' => ($code >= 200 && $code < 300),
        'code' => $code,
        'json' => is_array($json) ? $json : null,
        'raw' => $raw,
        'error' => ($code >= 200 && $code < 300) ? '' : ('HTTP ' . $code),
    );
}

/**
 * فحص اتصال + رصيد إن توفر
 * @return array{ok:bool,message:string,balance?:mixed}
 */
function earthlink_test_connection($host, $username, $password, $altHost = '')
{
    $auth = earthlink_auth($host, $username, $password, true, $altHost);
    if (empty($auth['ok'])) {
        return array(
            'ok' => false,
            'message' => isset($auth['error']) ? $auth['error'] : 'فشل الاتصال',
        );
    }
    $mode = isset($auth['mode']) ? $auth['mode'] : 'api';
    $used = isset($auth['host']) ? $auth['host'] : earthlink_normalize_host($host);
    $viaAlt = (strcasecmp($used, earthlink_normalize_host($host)) !== 0);
    $msg = ($mode === 'portal')
        ? ('تم الدخول إلى بوابة Earthlink' . ($viaAlt ? ' (عبر البديل)' : ''))
        : ('تم الاتصال بـ Earthlink Reseller API' . ($viaAlt ? ' (عبر البديل)' : ''));
    if ($mode === 'api' && !empty($auth['token'])) {
        $bal = earthlink_fetch_balance($used, $auth['token']);
        if (!empty($bal['ok']) && isset($bal['iqd'])) {
            $msg .= ' • الرصيد ' . number_format((float) $bal['iqd'], 0) . ' IQD';
        }
    }
    return array('ok' => true, 'message' => $msg, 'mode' => $mode, 'host' => $used);
}

function earthlink_fetch_balance($host, $token)
{
    $res = earthlink_api_get($host, $token, 'affiliate/deposit/balance');
    if (empty($res['ok']) || !is_array($res['json'])) {
        return array('ok' => false);
    }
    $amount = earthlink_find_money($res['json']);
    if ($amount === null) {
        return array('ok' => false, 'json' => $res['json']);
    }
    return array(
        'ok' => true,
        'value' => $amount,
        'iqd' => (int) round($amount),
    );
}

function earthlink_find_money($node)
{
    $preferred = array(
        'balance', 'amount', 'value', 'currentbalance', 'depositbalance',
        'affiliatebalance', 'availablebalance', 'credit', 'walletbalance',
    );
    return earthlink_walk_number($node, $preferred, true);
}

function earthlink_walk_number($node, $preferred, $preferKeys)
{
    if (is_array($node)) {
        $isList = array_keys($node) === range(0, count($node) - 1);
        if (!$isList && $preferKeys) {
            foreach ($node as $k => $v) {
                $nk = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $k));
                if (in_array($nk, $preferred, true)) {
                    $n = earthlink_scalar_number($v);
                    if ($n !== null) {
                        return $n;
                    }
                    $deep = earthlink_walk_number($v, $preferred, true);
                    if ($deep !== null) {
                        return $deep;
                    }
                }
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $deep = earthlink_walk_number($v, $preferred, true);
                if ($deep !== null) {
                    return $deep;
                }
            }
        }
    }
    return earthlink_scalar_number($node);
}

function earthlink_scalar_number($value)
{
    if (is_array($value) || $value === null) {
        return null;
    }
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }
    $cleaned = preg_replace('/[^0-9.\\-]/', '', str_replace(array(',', '٬'), '', (string) $value));
    if ($cleaned === '' || $cleaned === '-' || $cleaned === '.') {
        return null;
    }
    return is_numeric($cleaned) ? (float) $cleaned : null;
}

function earthlink_find_string($arr, $keys)
{
    if (!is_array($arr)) {
        return '';
    }
    foreach ($keys as $k) {
        if (isset($arr[$k]) && !is_array($arr[$k]) && trim((string) $arr[$k]) !== '') {
            return trim((string) $arr[$k]);
        }
    }
    return '';
}

function earthlink_find_int($arr, $keys)
{
    $s = earthlink_find_string($arr, $keys);
    if ($s !== '' && ctype_digit(ltrim($s, '-'))) {
        return (int) $s;
    }
    foreach ($keys as $k) {
        if (isset($arr[$k]) && (is_int($arr[$k]) || is_float($arr[$k]))) {
            return (int) $arr[$k];
        }
    }
    return null;
}

/**
 * جلب كل المشتركين عبر REST أو بوابة HTML
 * @return array{ok:bool,rows?:array,error?:string}
 */
function earthlink_fetch_all_subscribers($host, $username, $password, $altHost = '')
{
    $auth = earthlink_auth($host, $username, $password, false, $altHost);
    if (empty($auth['ok'])) {
        return array('ok' => false, 'error' => isset($auth['error']) ? $auth['error'] : 'auth failed');
    }
    $usedHost = isset($auth['host']) ? $auth['host'] : earthlink_normalize_host($host);
    $mode = isset($auth['mode']) ? $auth['mode'] : 'api';
    if ($mode === 'portal') {
        return earthlink_fetch_all_subscribers_portal($usedHost, $auth);
    }

    $token = $auth['token'];
    $merged = array();
    $start = 0;
    $pageSize = 300;
    $max = 6000;
    while ($start < $max) {
        $res = earthlink_api_post_json($usedHost, $token, 'user/all', array(
            'StartIndex' => $start,
            'OrderDescending' => false,
            'RowCount' => $pageSize,
        ));
        if (empty($res['ok']) || !is_array($res['json'])) {
            if ($start === 0) {
                // REST فشل — جرّب البوابة إن أمكن
                $portal = earthlink_auth_portal($usedHost, $username, $password);
                if (!empty($portal['ok'])) {
                    $portal['host'] = $usedHost;
                    return earthlink_fetch_all_subscribers_portal($usedHost, $portal);
                }
                return array(
                    'ok' => false,
                    'error' => !empty($res['error']) ? $res['error'] : 'فشل جلب المشتركين',
                );
            }
            break;
        }
        $page = earthlink_extract_user_items($res['json']);
        if (!$page) {
            break;
        }
        foreach ($page as $item) {
            $sub = earthlink_parse_subscriber($item);
            if ($sub && $sub['username'] !== '') {
                $merged[strtolower($sub['username'])] = $sub;
            }
        }
        if (count($page) < $pageSize) {
            break;
        }
        $start += $pageSize;
    }
    return array('ok' => true, 'rows' => array_values($merged), 'host' => $usedHost);
}

/**
 * جلب المشتركين من UserManagement.aspx عبر جلسة البوابة.
 * @return array{ok:bool,rows?:array,error?:string}
 */
function earthlink_fetch_all_subscribers_portal($host, $auth)
{
    $cookie = isset($auth['cookie_file']) ? $auth['cookie_file'] : '';
    if ($cookie === '' || !is_file($cookie)) {
        return array('ok' => false, 'error' => 'جلسة البوابة غير موجودة');
    }
    $base = earthlink_base_url($host);
    $merged = array();
    // userst=1 نشطين، userst=6 منتهين
    foreach (array(
        array('status' => 1, 'extra' => '', 'expired' => 0),
        array('status' => 6, 'extra' => 't=7', 'expired' => 1),
    ) as $pack) {
        $pageSize = 50;
        $maxPages = 40;
        for ($page = 1; $page <= $maxPages; $page++) {
            $qs = 'userst=' . (int) $pack['status'];
            if ($pack['extra'] !== '') {
                $qs .= '&' . $pack['extra'];
            }
            if ($page > 1) {
                $qs .= '&ps=' . $page . '_' . $pageSize;
            }
            list($html, $code, $err) = earthlink_http(
                'GET',
                $base . '/UserManagement.aspx?' . $qs,
                null,
                array('Accept: text/html', 'User-Agent: ' . earthlink_ua()),
                40,
                $cookie
            );
            if ($html === false) {
                if ($page === 1 && empty($merged)) {
                    return array('ok' => false, 'error' => $err !== '' ? $err : 'فشل فتح UserManagement');
                }
                break;
            }
            if (earthlink_portal_looks_like_login($html)) {
                return array('ok' => false, 'error' => 'انتهت جلسة Earthlink — أعد المحاولة');
            }
            $rows = earthlink_portal_parse_users($html, (int) $pack['expired']);
            if (!$rows) {
                break;
            }
            $new = 0;
            foreach ($rows as $sub) {
                $k = strtolower($sub['username']);
                if (!isset($merged[$k])) {
                    $new++;
                    $merged[$k] = $sub;
                } else {
                    // لا تستبدل نشط بمنتهي إلا إذا صف المنتهي أوضح؛ احتفظ بالأونلاين/الانتهاء الأفضل
                    $prev = $merged[$k];
                    $prevExp = !empty($prev['is_expired']);
                    $curExp = !empty($sub['is_expired']);
                    if ($prevExp && !$curExp) {
                        $merged[$k] = $sub;
                    } elseif (!$prevExp && $curExp) {
                        // خلّي النشط
                    } else {
                        if (empty($prev['is_online']) && !empty($sub['is_online'])) {
                            $prev['is_online'] = 1;
                        }
                        if ((empty($prev['expiry_date']) || $prev['expiry_date'] === '')
                            && !empty($sub['expiry_date'])
                        ) {
                            $prev['expiry_date'] = $sub['expiry_date'];
                        }
                        if ((empty($prev['framed_ip']) || $prev['framed_ip'] === '')
                            && !empty($sub['framed_ip'])
                        ) {
                            $prev['framed_ip'] = $sub['framed_ip'];
                        }
                        $merged[$k] = $prev;
                    }
                }
            }
            if ($new === 0 || count($rows) < 5) {
                break;
            }
        }
    }
    return array('ok' => true, 'rows' => array_values($merged));
}

/**
 * قراءة نص عنصر بالـ id (جزئي أو كامل) من صف HTML.
 */
function earthlink_html_id_text($html, $idNeedle)
{
    $idNeedle = preg_quote($idNeedle, '/');
    if (preg_match('/id="[^"]*' . $idNeedle . '"[^>]*>([^<]*)</i', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
    return '';
}

/**
 * استخراج صفوف المشتركين من HTML بوابة الإدارة.
 * @return array
 */
function earthlink_portal_parse_users($html, $forceExpired = 0)
{
    $out = array();
    if (!is_string($html) || $html === '') {
        return $out;
    }
    if (!preg_match_all('/<tr([^>]*)>(.*?)<\/tr>/is', $html, $trs)) {
        return $out;
    }
    $n = count($trs[0]);
    for ($i = 0; $i < $n; $i++) {
        $trAttrs = $trs[1][$i];
        $trBody = $trs[2][$i];
        $isUser = (stripos($trAttrs, 'userRow') !== false)
            || (stripos($trBody, 'UserEdit.aspx') !== false && stripos($trBody, 'uid=') !== false);
        if (!$isUser) {
            continue;
        }
        $username = '';
        if (preg_match('/UserEdit\.aspx[^"\']*uid=([^"\'&\s]+)/i', $trBody, $m)) {
            $username = urldecode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        if ($username === '' && preg_match('/class="[^"]*userIdLink[^"]*"[^>]*>([^<]+)</i', $trBody, $m)) {
            $username = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        if ($username === '') {
            continue;
        }

        $display = earthlink_html_id_text($trBody, 'displayname');
        if ($display === '') {
            $display = earthlink_html_id_text($trBody, 'arabicname');
        }
        if ($display === '') {
            $display = earthlink_html_id_text($trBody, 'ArabicName');
        }
        $firstName = earthlink_html_id_text($trBody, 'firstname');
        // الاسم الظاهر بالتطبيق/DisplayName = displayname (عربي). firstname بالبوابة حقل إنجليزي منفصل.
        if ($display === '' && $firstName !== '') {
            $display = $firstName;
        }
        $profile = earthlink_html_id_text($trBody, 'AccountNamelabel');
        if ($profile === '') {
            $profile = earthlink_html_id_text($trBody, 'AccountName');
        }
        $accStatus = earthlink_html_id_text($trBody, 'AccoutnStatusLabel');
        if ($accStatus === '') {
            $accStatus = earthlink_html_id_text($trBody, 'AccountStatus');
        }
        $netStatus = earthlink_html_id_text($trBody, '_statuslabel');
        if ($netStatus === '') {
            $netStatus = earthlink_html_id_text($trBody, 'statuslabel');
        }
        $daysLeft = earthlink_html_id_text($trBody, 'ActiveDaysLeft');
        $plainRow = preg_replace('/\s+/', ' ', strip_tags($trBody));
        if ($daysLeft === '' && preg_match('/Days left\s*:?\s*(\d+)/i', $plainRow, $m)) {
            $daysLeft = $m[1];
        }
        $ip = earthlink_html_id_text($trBody, 'userip');
        $mac = earthlink_html_id_text($trBody, 'usermac');
        $agent = earthlink_html_id_text($trBody, 'agentlabel');
        if ($agent === '' && preg_match('/class="[^"]*affiliatelabel[^"]*"[^>]*>([^<]*)</i', $trBody, $m)) {
            $agent = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        $notes = earthlink_html_id_text($trBody, 'noteslabel');
        $phone = '';
        if (preg_match('/id="[^"]*mobilenolabel"[^>]*>([^<]*)</i', $trBody, $m)) {
            $phone = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        if ($phone === '' && preg_match('/Mobile\s*:?\s*([0-9+]{8,})/i', $plainRow, $m)) {
            $phone = trim($m[1]);
        }
        $expiry = '';
        if (preg_match('/<td[^>]*class="[^"]*expirationDate[^"]*"[^>]*>(.*?)<\/td>/is', $trBody, $m)) {
            $expiry = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')));
        }
        if ($expiry === '' && preg_match('/(\d{1,2}\/\d{1,2}\/\d{4}\s+\d{1,2}:\d{2}\s*[AP]M)/i', $plainRow, $m)) {
            $expiry = trim($m[1]);
        }
        // إن ماكو تاريخ صريح وأيام متبقية رقمية — احسب من الآن
        if ($expiry === '' && $daysLeft !== '' && preg_match('/^\d+$/', trim($daysLeft))) {
            $expiry = date('Y-m-d H:i:s', time() + ((int) $daysLeft * 86400));
        }

        $status = $accStatus !== '' ? $accStatus : $netStatus;
        $blobLow = strtolower($netStatus . ' ' . $accStatus . ' ' . $status . ' ' . $plainRow);
        $isOnline = (
            stripos($netStatus, 'Online') !== false
            || stripos($plainRow, 'Online') !== false
            || strpos($blobLow, 'متصل') !== false
        ) ? 1 : 0;
        // IP حي + مو منتهي بالصف غالباً يعني أونلاين حتى لو الليبل ناقص
        if (!$isOnline && $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)
            && strpos($blobLow, 'offline') === false
            && strpos($blobLow, 'expire') === false
            && !$forceExpired
        ) {
            $isOnline = 1;
        }
        $expired = $forceExpired ? 1 : 0;
        $stLow = strtolower($accStatus . ' ' . $status);
        if (strpos($stLow, 'expire') !== false || strpos($stLow, 'suspend') !== false
            || ($daysLeft !== '' && ctype_digit(trim($daysLeft)) && (int) $daysLeft <= 0)
        ) {
            $expired = 1;
        }
        // صفحة النشطين: لا تعتبره منتهي إذا الحالة Active صراحةً
        if (!$forceExpired && (strpos($stLow, 'active') !== false || strpos($blobLow, 'فعال') !== false)) {
            $expired = 0;
        }

        $out[] = array(
            'username' => $username,
            'display_name' => $display,
            'firstname' => $firstName !== '' ? $firstName : $display,
            'profile_name' => $profile,
            'expiry_date' => $expiry,
            'status' => $status !== '' ? $status : ($forceExpired ? 'Expired' : 'Active'),
            'phone' => $phone,
            'email' => '',
            'notes' => $notes,
            'framed_ip' => $ip,
            'mac' => $mac,
            'active_days_left' => $daysLeft,
            'agent_name' => $agent,
            'parent_name' => $agent,
            'user_index' => 0,
            'account_index' => '',
            'is_online' => $isOnline,
            'is_expired' => $expired,
            'provider' => 'earthlink',
        );
    }
    return $out;
}

function earthlink_extract_user_items($root)
{
    if (!is_array($root)) {
        return array();
    }
    $value = isset($root['value']) && is_array($root['value']) ? $root['value'] : $root;
    foreach (array('itemsList', 'items', 'data') as $k) {
        if (isset($value[$k]) && is_array($value[$k])) {
            $out = array();
            foreach ($value[$k] as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            return $out;
        }
    }
    if (isset($root['data']) && is_array($root['data'])) {
        $out = array();
        foreach ($root['data'] as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }
    return array();
}

function earthlink_parse_subscriber($item)
{
    if (!is_array($item)) {
        return null;
    }
    $value = $item;
    if (isset($item['value']) && is_array($item['value'])
        && !isset($item['userObject']) && !isset($item['userID']) && !isset($item['accountName'])) {
        $value = $item['value'];
    }
    $userObject = isset($value['userObject']) && is_array($value['userObject']) ? $value['userObject'] : array();
    $username = earthlink_find_string($value, array('userID', 'UserID', 'username'));
    if ($username === '') {
        $username = earthlink_find_string($userObject, array('userId', 'userID', 'UserID', 'username'));
    }
    if ($username === '') {
        return null;
    }
    $userIndex = earthlink_find_int($value, array('userIndex', 'UserIndex'));
    if ($userIndex === null) {
        $userIndex = earthlink_find_int($userObject, array('userIndex', 'UserIndex'));
    }
    $status = earthlink_find_string($value, array('accountStatus', 'AccountStatus', 'status', 'Status'));
    if ($status === '') {
        $status = earthlink_find_string($userObject, array('status', 'Status'));
    }
    $online = earthlink_find_string($value, array('onlineStatus', 'OnlineStatus'));
    if ($online === '') {
        $online = earthlink_find_string($userObject, array('status', 'Status'));
    }
    $days = earthlink_find_string($value, array('activeDaysLeft', 'remaining_days', 'remainingDays'));
    $blocked = !empty($value['isBlocked']) || !empty($value['IsBlocked']);
    $isOnline = (strcasecmp($online, 'Online') === 0);
    $expired = false;
    $stLow = strtolower($status);
    if ($blocked || strpos($stLow, 'suspend') !== false || strpos($stLow, 'expire') !== false
        || strpos($stLow, 'block') !== false) {
        $expired = true;
    }
    if ($days !== '' && ctype_digit($days) && (int) $days <= 0) {
        $expired = true;
    }

    return array(
        'username' => $username,
        'display_name' => earthlink_find_string($value, array(
            'displayName', 'DisplayName', 'arName', 'ArabicName', 'arabicName', 'fullName',
        )),
        'firstname' => earthlink_find_string($value, array('firstName', 'FirstName', 'fullName', 'FullName')),
        'profile_name' => earthlink_find_string($value, array('accountName', 'AccountName', 'accountPackageType')),
        'expiry_date' => earthlink_find_string($value, array(
            'manualExpirationDate', 'accountExpirationDate', 'expirationDate', 'expiryDate',
        )),
        'status' => $status,
        'phone' => earthlink_find_string($value, array('mobileNumber', 'mobileNumber2', 'mobilePhone', 'phoneHome')),
        'email' => earthlink_find_string($value, array('email', 'Email')),
        'notes' => earthlink_find_string($value, array('userNotes', 'notes', 'Note')),
        'framed_ip' => earthlink_find_string($value, array('userIP', 'lastIpAddress', 'last_ip_address', 'ipAddress')),
        'mac' => earthlink_find_string($value, array('maxmac', 'mac', 'callerID', 'callerId')),
        'active_days_left' => $days,
        'agent_name' => earthlink_find_string($value, array('agentName', 'affiliateName')),
        'parent_name' => earthlink_find_string($value, array('affiliateName', 'AffiliateName')),
        'user_index' => $userIndex ? $userIndex : 0,
        'account_index' => earthlink_find_string($value, array('accountIndex', 'AccountIndex')),
        'is_online' => $isOnline ? 1 : 0,
        'is_expired' => $expired ? 1 : 0,
        'provider' => 'earthlink',
    );
}

/**
 * مزامنة مشتركي Earthlink إلى sas_users_cache لنفس الـ tenant
 * @return array{ok:bool,count:int,message:string}
 */
function earthlink_sync_to_cache($pdo, $tenantId, $host, $username, $password, $companyId = 0, $altHost = '')
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0 || !$pdo) {
        return array('ok' => false, 'count' => 0, 'message' => 'tenant غير صالح');
    }
    if ($altHost === '' && $companyId > 0) {
        $altHost = earthlink_company_alt_host($pdo, $companyId);
    }
    if ($altHost === '' && !earthlink_is_private_host($host)
        && stripos(earthlink_normalize_host($host), 'spicywifi') === false
    ) {
        // هوست أساسي غير سبايسي — خلّي سبايسي بديل افتراضي إن ما محدد
        $altHost = earthlink_default_alt_host();
    }
    $pack = earthlink_fetch_all_subscribers($host, $username, $password, $altHost);
    if (empty($pack['ok'])) {
        return array(
            'ok' => false,
            'count' => 0,
            'message' => isset($pack['error']) ? $pack['error'] : 'فشل الجلب',
        );
    }
    $rows = isset($pack['rows']) ? $pack['rows'] : array();
    if (function_exists('ensure_sas_users_cache_table')) {
        ensure_sas_users_cache_table($pdo);
    }
    earthlink_ensure_cache_columns($pdo);

    // امسح فقط مشتركي إيرث لهذه الشركة — لا تمسح كاش الساس/الرسلرات الثانية
    try {
        if ($companyId > 0 && earthlink_cache_has_column($pdo, 'company_id')) {
            $pdo->prepare('DELETE FROM sas_users_cache WHERE tenant_id = :t AND company_id = :c')
                ->execute(array(':t' => $tenantId, ':c' => (int) $companyId));
        } elseif (earthlink_cache_has_column($pdo, 'provider')) {
            $pdo->prepare('DELETE FROM sas_users_cache WHERE tenant_id = :t AND provider = :p')
                ->execute(array(':t' => $tenantId, ':p' => 'earthlink'));
        } else {
            $names = array();
            foreach ($rows as $sub) {
                if (!empty($sub['username'])) {
                    $names[] = $pdo->quote((string) $sub['username']);
                }
            }
            if ($names) {
                $pdo->exec(
                    'DELETE FROM sas_users_cache WHERE tenant_id = ' . (int) $tenantId
                    . ' AND username IN (' . implode(',', $names) . ')'
                );
            }
        }
    } catch (Exception $e) {
        return array('ok' => false, 'count' => 0, 'message' => 'فشل مسح الكاش');
    }

    $n = 0;
    $hasProvider = earthlink_cache_has_column($pdo, 'provider');
    $hasCompany = earthlink_cache_has_column($pdo, 'company_id');
    $hasMac = earthlink_cache_has_column($pdo, 'mac_address');
    $hasAcc = earthlink_cache_has_column($pdo, 'sas_account_id');
    foreach ($rows as $sub) {
        $disp = isset($sub['display_name']) ? trim((string) $sub['display_name']) : '';
        if ($disp === '') {
            $disp = $sub['username'];
        }
        $expRaw = isset($sub['expiry_date']) ? trim((string) $sub['expiry_date']) : '';
        $expAt = null;
        if ($expRaw !== '') {
            $ts = strtotime($expRaw);
            if ($ts) {
                $expAt = date('Y-m-d H:i:s', $ts);
            }
        }
        $first = isset($sub['firstname']) ? trim((string) $sub['firstname']) : '';
        if ($first === '') {
            $first = $disp;
        }
        $cols = array(
            'tenant_id' => $tenantId,
            'username' => $sub['username'],
            'display_name' => $disp,
            'profile_name' => $sub['profile_name'],
            'parent_name' => $sub['parent_name'] !== '' ? $sub['parent_name'] : $sub['agent_name'],
            'phone' => $sub['phone'],
            'expire_at' => $expAt,
            'is_online' => (int) $sub['is_online'],
            'framed_ip' => $sub['framed_ip'],
            'enabled' => empty($sub['is_expired']) ? 1 : 0,
            'synced_at' => date('Y-m-d H:i:s'),
        );
        if (!empty($sub['is_online']) && earthlink_cache_has_column($pdo, 'last_online')) {
            $cols['last_online'] = date('Y-m-d H:i:s');
        }
        if (earthlink_cache_has_column($pdo, 'firstname')) {
            $cols['firstname'] = $first;
        }
        if ($hasProvider) {
            $cols['provider'] = 'earthlink';
        }
        if ($hasCompany && $companyId > 0) {
            $cols['company_id'] = (int) $companyId;
        }
        if ($hasMac && !empty($sub['mac'])) {
            $cols['mac_address'] = $sub['mac'];
        }
        if ($hasAcc && !empty($GLOBALS['sas_force_account']['id'])) {
            $cols['sas_account_id'] = (int) $GLOBALS['sas_force_account']['id'];
        }
        if (earthlink_cache_has_column($pdo, 'email') && !empty($sub['email'])) {
            $cols['email'] = $sub['email'];
        }
        $fields = array_keys($cols);
        $place = array();
        $bind = array();
        foreach ($fields as $f) {
            $place[] = ':' . $f;
            $bind[':' . $f] = $cols[$f];
        }
        try {
            $sql = 'INSERT INTO sas_users_cache (' . implode(',', $fields) . ') VALUES (' . implode(',', $place) . ')';
            $pdo->prepare($sql)->execute($bind);
            $n++;
        } catch (Exception $e) {
            // صف واحد فاشل لا يوقف الباقي
        }
    }
    // خزّن باقات إيرث من أسماء البروفايل الظاهرة بالمشتركين
    if (function_exists('earthlink_packages_persist_from_cache')) {
        earthlink_packages_persist_from_cache($pdo, $tenantId, $companyId);
    }
    return array(
        'ok' => true,
        'count' => $n,
        'message' => 'تم جلب ' . $n . ' مشترك من Earthlink',
    );
}

/**
 * مفتاح ثابت لباقة إيرث (ماكو رقم ساس).
 */
function earthlink_package_ui_id($name)
{
    $name = trim((string) $name);
    if ($name === '') {
        return '';
    }
    return 'el:' . rawurlencode($name);
}

function earthlink_package_name_from_ui_id($id)
{
    $id = trim((string) $id);
    if ($id === '') {
        return '';
    }
    if (stripos($id, 'el:') === 0) {
        return rawurldecode(substr($id, 3));
    }
    return '';
}

function earthlink_packages_file($tenantId, $companyId = 0)
{
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $companyId = (int) $companyId;
    $suffix = $companyId > 0 ? ('_c' . $companyId) : '';
    return $dir . '/el_packages_t' . (int) $tenantId . $suffix . '.json';
}

/**
 * باقات إيرث من كاش المشتركين (أسماء فريدة).
 * @return array list of {id,name,price,users}
 */
function earthlink_packages_from_cache($pdo, $tenantId, $companyId = 0)
{
    $out = array();
    $tenantId = (int) $tenantId;
    $companyId = (int) $companyId;
    if ($tenantId <= 0 || !$pdo) {
        return $out;
    }
    try {
        $sql = 'SELECT profile_name, COUNT(*) AS c FROM sas_users_cache WHERE tenant_id = :t';
        $bind = array(':t' => $tenantId);
        if (earthlink_cache_has_column($pdo, 'provider')) {
            $sql .= ' AND provider = :p';
            $bind[':p'] = 'earthlink';
        }
        if ($companyId > 0 && earthlink_cache_has_column($pdo, 'company_id')) {
            $sql .= ' AND company_id = :c';
            $bind[':c'] = $companyId;
        }
        $sql .= ' AND profile_name IS NOT NULL AND TRIM(profile_name) <> ""'
            . ' GROUP BY profile_name ORDER BY c DESC, profile_name ASC';
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        foreach ($st->fetchAll() as $row) {
            $name = trim((string) $row['profile_name']);
            if ($name === '') {
                continue;
            }
            $out[] = array(
                'id' => earthlink_package_ui_id($name),
                'name' => $name,
                'price' => 0.0,
                'users' => isset($row['c']) ? (int) $row['c'] : 0,
                'provider' => 'earthlink',
            );
        }
    } catch (Exception $e) {
    }
    return $out;
}

function earthlink_packages_persist_from_cache($pdo, $tenantId, $companyId = 0)
{
    $rows = earthlink_packages_from_cache($pdo, $tenantId, $companyId);
    $file = earthlink_packages_file($tenantId, $companyId);
    @file_put_contents($file, json_encode(array(
        'at' => time(),
        'rows' => $rows,
    )));
    if ($companyId > 0) {
        // نسخة عامة للوكالة أيضاً
        $all = earthlink_packages_from_cache($pdo, $tenantId, 0);
        @file_put_contents(earthlink_packages_file($tenantId, 0), json_encode(array(
            'at' => time(),
            'rows' => $all,
        )));
    }
    return $rows;
}

/**
 * دمج عدّاد المشتركين من الكاش مع قائمة الباقات الكاملة.
 */
function earthlink_packages_merge_user_counts($rows, $pdo, $tenantId, $companyId = 0)
{
    $counts = array();
    foreach (earthlink_packages_from_cache($pdo, $tenantId, $companyId) as $c) {
        $nk = strtolower(trim((string) $c['name']));
        if ($nk !== '') {
            $counts[$nk] = isset($c['users']) ? (int) $c['users'] : 0;
        }
    }
    foreach ($rows as &$r) {
        $nk = strtolower(trim((string) $r['name']));
        $r['users'] = isset($counts[$nk]) ? (int) $counts[$nk] : 0;
    }
    unset($r);
    return $rows;
}

/**
 * باقات إيرث للواجهة: بوابة كاملة أولاً، ثم كاش الملف، ثم أسماء المشتركين فقط.
 * @return array
 */
function earthlink_packages_for_ui($pdo, $config = null, $companyId = 0)
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
    $companyId = (int) $companyId;
    $file = earthlink_packages_file($tid, $companyId);
    // ملف كاش حديث وفيه أكثر من 3 باقات = قائمة بوابة كاملة
    if (is_file($file)) {
        $raw = json_decode((string) @file_get_contents($file), true);
        if (is_array($raw) && !empty($raw['rows']) && is_array($raw['rows'])
            && !empty($raw['at']) && (time() - (int) $raw['at']) < 86400
            && count($raw['rows']) >= 4
            && !empty($raw['source']) && $raw['source'] === 'portal'
        ) {
            return earthlink_packages_merge_user_counts($raw['rows'], $pdo, $tid, $companyId);
        }
    }
    // اجلب من حساب إيرث (بوابة AddUser = كل أنواع الحساب)
    if ($pdo && function_exists('tenant_sas_accounts_list')) {
        foreach (tenant_sas_accounts_list($pdo, $tid) as $acc) {
            if (!function_exists('tenant_sas_account_is_earthlink') || !tenant_sas_account_is_earthlink($pdo, $acc)) {
                continue;
            }
            $accCo = !empty($acc['company_id']) ? (int) $acc['company_id'] : 0;
            if ($companyId > 0 && $accCo > 0 && $accCo !== $companyId) {
                continue;
            }
            $host = isset($acc['sas_host']) ? $acc['sas_host'] : '';
            $user = isset($acc['sas_username']) ? $acc['sas_username'] : '';
            $pass = isset($acc['sas_password']) ? $acc['sas_password'] : '';
            $alt = ($accCo > 0) ? earthlink_company_alt_host($pdo, $accCo) : '';
            $pack = earthlink_fetch_packages($host, $user, $pass, $alt);
            if (!empty($pack['ok']) && !empty($pack['rows'])) {
                $rows = array();
                foreach ($pack['rows'] as $r) {
                    $name = isset($r['name']) ? trim((string) $r['name']) : '';
                    if ($name === '') {
                        continue;
                    }
                    $rows[] = array(
                        'id' => earthlink_package_ui_id($name),
                        'name' => $name,
                        'price' => 0.0,
                        'users' => 0,
                        'provider' => 'earthlink',
                        'account_index' => isset($r['id']) ? (string) $r['id'] : '',
                    );
                }
                if ($rows) {
                    $rows = earthlink_packages_merge_user_counts($rows, $pdo, $tid, $companyId > 0 ? $companyId : $accCo);
                    @file_put_contents(earthlink_packages_file($tid, $companyId > 0 ? $companyId : $accCo), json_encode(array(
                        'at' => time(),
                        'source' => 'portal',
                        'rows' => $rows,
                    )));
                    return $rows;
                }
            }
        }
    }
    // احتياط: أسماء ظاهرة عند المشتركين فقط
    $fromDb = earthlink_packages_from_cache($pdo, $tid, $companyId);
    if ($fromDb) {
        earthlink_packages_persist_from_cache($pdo, $tid, $companyId);
        return $fromDb;
    }
    return array();
}

/** هل للوكالة حساب إيرث لنك؟ */
function tenant_has_earthlink_account($pdo, $tenantId = 0)
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0 && function_exists('current_tenant_id')) {
        $tenantId = (int) current_tenant_id();
    }
    if ($tenantId <= 0 || !$pdo || !function_exists('tenant_sas_accounts_list')) {
        return false;
    }
    foreach (tenant_sas_accounts_list($pdo, $tenantId) as $acc) {
        if (function_exists('tenant_sas_account_is_earthlink') && tenant_sas_account_is_earthlink($pdo, $acc)) {
            return true;
        }
    }
    return false;
}

function earthlink_cache_has_column($pdo, $col)
{
    static $map = array();
    $col = (string) $col;
    if (array_key_exists($col, $map)) {
        return $map[$col];
    }
    try {
        $st = $pdo->query("SHOW COLUMNS FROM sas_users_cache LIKE " . $pdo->quote($col));
        $map[$col] = (bool) $st->fetch();
    } catch (Exception $e) {
        $map[$col] = false;
    }
    return $map[$col];
}

function earthlink_ensure_cache_columns($pdo)
{
    foreach (array(
        'provider VARCHAR(32) NOT NULL DEFAULT "sas"',
        'company_id INT UNSIGNED NOT NULL DEFAULT 0',
    ) as $def) {
        $col = preg_replace('/\s.*/', '', $def);
        if (!earthlink_cache_has_column($pdo, $col)) {
            try {
                $pdo->exec('ALTER TABLE sas_users_cache ADD COLUMN ' . $def);
            } catch (Exception $e) {
            }
        }
    }
}

/**
 * قراءة أنواع الحساب من Users/AddUser.aspx (قائمة AccountTypes كاملة).
 */
function earthlink_fetch_packages_portal($host, $cookieFile)
{
    $base = earthlink_base_url($host);
    $url = $base . '/Users/AddUser.aspx';
    list($html, $code, $err) = earthlink_http(
        'GET',
        $url,
        null,
        array(
            'Accept: text/html',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $base . '/UserManagement.aspx',
        ),
        40,
        $cookieFile
    );
    if ($html === false || earthlink_portal_looks_like_login($html)) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'تعذر فتح AddUser', 'rows' => array());
    }
    $list = array();
    $seen = array();
    // select عادي
    if (preg_match(
        '/name="[^"]*AccountTypes(?:Drop)?\$AccountTypesList"[^>]*>(.*?)<\/select>/is',
        $html,
        $sm
    ) || preg_match(
        '/id="[^"]*AccountTypesList"[^>]*>(.*?)<\/select>/is',
        $html,
        $sm
    )) {
        if (preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>([^<]*)</i', $sm[1], $om, PREG_SET_ORDER)) {
            foreach ($om as $o) {
                $id = trim(html_entity_decode($o[1], ENT_QUOTES, 'UTF-8'));
                $name = trim(html_entity_decode($o[2], ENT_QUOTES, 'UTF-8'));
                if ($id === '' || $name === '' || stripos($name, 'select') !== false) {
                    continue;
                }
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $list[] = array('id' => $id, 'name' => $name);
            }
        }
    }
    // RadComboBox / JSON داخل الصفحة
    if (!$list && preg_match_all('/\{"Text":"([^"]+)","Value":"([^"]+)"\}/', $html, $jm, PREG_SET_ORDER)) {
        foreach ($jm as $j) {
            $name = trim(html_entity_decode($j[1], ENT_QUOTES, 'UTF-8'));
            $id = trim(html_entity_decode($j[2], ENT_QUOTES, 'UTF-8'));
            if ($id === '' || $name === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $list[] = array('id' => $id, 'name' => $name);
        }
    }
    if (!$list) {
        return array('ok' => false, 'error' => 'ماكو قائمة باقات بصفحة AddUser', 'rows' => array());
    }
    return array('ok' => true, 'rows' => $list, 'mode' => 'portal');
}

function earthlink_fetch_packages($host, $username, $password, $altHost = '')
{
    $auth = earthlink_auth($host, $username, $password, false, $altHost);
    if (empty($auth['ok'])) {
        return array('ok' => false, 'error' => isset($auth['error']) ? $auth['error'] : 'auth', 'rows' => array());
    }
    $usedHost = isset($auth['host']) ? $auth['host'] : earthlink_normalize_host($host);
    $mode = isset($auth['mode']) ? $auth['mode'] : 'api';

    // بوابة HTML: كل الباقات من AddUser.aspx
    if ($mode === 'portal') {
        $cookie = isset($auth['cookie_file']) ? $auth['cookie_file'] : '';
        if ($cookie === '') {
            return array('ok' => false, 'error' => 'جلسة البوابة غير موجودة', 'rows' => array());
        }
        return earthlink_fetch_packages_portal($usedHost, $cookie);
    }

    $token = isset($auth['token']) ? $auth['token'] : '';
    $paths = array('accounts/all', 'account/all', 'Account/All');
    $list = array();
    $seen = array();
    foreach ($paths as $path) {
        $res = earthlink_api_get($usedHost, $token, $path);
        if (empty($res['ok']) || !is_array($res['json'])) {
            continue;
        }
        $items = earthlink_extract_user_items($res['json']);
        if (!$items && isset($res['json']) && is_array($res['json'])) {
            if (isset($res['json'][0])) {
                $items = $res['json'];
            } elseif (isset($res['json']['value']) && is_array($res['json']['value'])) {
                $items = isset($res['json']['value'][0]) ? $res['json']['value'] : earthlink_extract_user_items($res['json']['value']);
            }
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = earthlink_find_string($item, array(
                'accountIndex', 'AccountIndex', 'accountID', 'AccountID', 'accountId', 'id', 'ID',
            ));
            $name = earthlink_find_string($item, array(
                'accountName', 'AccountName', 'name', 'Name', 'displayName', 'title',
            ));
            if ($name === '') {
                continue;
            }
            $key = $id !== '' ? $id : strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $list[] = array('id' => $id !== '' ? $id : $name, 'name' => $name);
        }
        if ($list) {
            break;
        }
    }
    if ($list) {
        return array('ok' => true, 'rows' => $list);
    }
    // REST فشل — جرّب بوابة AddUser
    $portal = earthlink_auth_portal($usedHost, $username, $password);
    if (!empty($portal['ok']) && !empty($portal['cookie_file'])) {
        return earthlink_fetch_packages_portal($usedHost, $portal['cookie_file']);
    }
    return array('ok' => false, 'error' => 'فشل جلب الباقات', 'rows' => array());
}

/**
 * جمع حقول نموذج ASP.NET من HTML (بدون أزرار جانبية غير مطلوبة).
 * @return array|null
 */
function earthlink_portal_collect_post_fields($html)
{
    if (!is_string($html) || $html === '') {
        return null;
    }
    $fields = array();
    if (!preg_match_all('/<(input|select|textarea)\b([^>]*)>(?:(.*?)<\/\1>)?/is', $html, $m, PREG_SET_ORDER)) {
        return null;
    }
    foreach ($m as $row) {
        $tag = strtolower($row[1]);
        $attrs = $row[2];
        $inner = isset($row[3]) ? $row[3] : '';
        if (!preg_match('/\bname="([^"]+)"/i', $attrs, $nm)) {
            continue;
        }
        $name = html_entity_decode($nm[1], ENT_QUOTES, 'UTF-8');
        $type = 'text';
        if (preg_match('/\btype="([^"]+)"/i', $attrs, $tm)) {
            $type = strtolower($tm[1]);
        }
        if ($type === 'file' || $type === 'image' || $type === 'button') {
            continue;
        }
        // لا ترسل أزرار ثانية (تغيير كلمة سر / Check) مع التحديث
        if ($type === 'submit') {
            if (stripos($name, 'UpdateButton') === false) {
                continue;
            }
        }
        if ($tag === 'select') {
            $val = '';
            if (preg_match('/<option[^>]*selected[^>]*value="([^"]*)"/i', $inner, $om)
                || preg_match('/<option[^>]*value="([^"]*)"[^>]*selected/i', $inner, $om)
            ) {
                $val = html_entity_decode($om[1], ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<option[^>]*value="([^"]*)"/i', $inner, $om)) {
                $val = html_entity_decode($om[1], ENT_QUOTES, 'UTF-8');
            }
            $fields[$name] = $val;
            continue;
        }
        if ($tag === 'textarea') {
            $fields[$name] = html_entity_decode(trim(strip_tags($inner)), ENT_QUOTES, 'UTF-8');
            continue;
        }
        if ($type === 'checkbox' || $type === 'radio') {
            if (!preg_match('/\bchecked\b/i', $attrs)) {
                continue;
            }
            $val = 'on';
            if (preg_match('/\bvalue="([^"]*)"/i', $attrs, $vm)) {
                $val = html_entity_decode($vm[1], ENT_QUOTES, 'UTF-8');
            }
            $fields[$name] = $val;
            continue;
        }
        $val = '';
        if (preg_match('/\bvalue="([^"]*)"/i', $attrs, $vm)) {
            $val = html_entity_decode($vm[1], ENT_QUOTES, 'UTF-8');
        }
        $fields[$name] = $val;
    }
    if (empty($fields['__VIEWSTATE'])) {
        return null;
    }
    return $fields;
}

/**
 * جلب جلسات مشترك إيرث من Reports/Sessions.aspx
 * @return array{ok:bool,rows?:array,error?:string}
 */
function earthlink_fetch_user_sessions($host, $loginUser, $loginPass, $uid, $altHost = '', $days = 30)
{
    $uid = trim((string) $uid);
    if ($uid === '') {
        return array('ok' => false, 'error' => 'يوزرنيم فارغ', 'rows' => array());
    }
    $auth = earthlink_auth($host, $loginUser, $loginPass, false, $altHost);
    if (empty($auth['ok'])) {
        return array('ok' => false, 'error' => isset($auth['error']) ? $auth['error'] : 'auth', 'rows' => array());
    }
    $usedHost = isset($auth['host']) ? $auth['host'] : earthlink_normalize_host($host);
    $cookie = isset($auth['cookie_file']) ? $auth['cookie_file'] : '';
    if ((isset($auth['mode']) && $auth['mode'] !== 'portal') || $cookie === '') {
        $portal = earthlink_auth_portal($usedHost, $loginUser, $loginPass);
        if (empty($portal['ok'])) {
            foreach (earthlink_host_candidates($host, $altHost) as $tryHost) {
                $portal = earthlink_auth_portal($tryHost, $loginUser, $loginPass);
                if (!empty($portal['ok'])) {
                    $usedHost = $tryHost;
                    break;
                }
            }
        }
        if (empty($portal['ok'])) {
            return array('ok' => false, 'error' => 'تعذر فتح بوابة الجلسات', 'rows' => array());
        }
        $cookie = $portal['cookie_file'];
    }
    $base = earthlink_base_url($usedHost);
    $url = $base . '/Reports/Sessions.aspx?uid=' . rawurlencode($uid);
    list($html, $code, $err) = earthlink_http(
        'GET',
        $url,
        null,
        array('Accept: text/html', 'User-Agent: ' . earthlink_ua(), 'Referer: ' . $base . '/UserManagement.aspx'),
        35,
        $cookie
    );
    if ($html === false || earthlink_portal_looks_like_login($html)) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'تعذر فتح صفحة الجلسات', 'rows' => array());
    }
    $fields = earthlink_portal_collect_post_fields($html);
    if (!$fields) {
        return array('ok' => false, 'error' => 'تعذر قراءة نموذج الجلسات', 'rows' => array());
    }
    $prefix = 'ctl00$ctl00$MainContentPlaceHolder$MainContentPlaceHolder$';
    $days = max(1, min(90, (int) $days));
    $from = date('m/d/Y', strtotime('-' . $days . ' days'));
    $to = date('m/d/Y');
    $fields[$prefix . 'UserIdTextBox'] = $uid;
    $fields[$prefix . 'FromDate'] = $from;
    $fields[$prefix . 'FromDate$dateInput'] = $from;
    $fields[$prefix . 'ToDate'] = $to;
    $fields[$prefix . 'ToDate$dateInput'] = $to;
    unset($fields[$prefix . 'ResetControlsBtn']);
    $fields[$prefix . 'SearchButton'] = 'Search';
    $body = http_build_query($fields);
    list($after, $code2, $err2) = earthlink_http(
        'POST',
        $url,
        $body,
        array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $url,
        ),
        50,
        $cookie
    );
    if ($after === false) {
        return array('ok' => false, 'error' => $err2 !== '' ? $err2 : 'فشل جلب الجلسات', 'rows' => array());
    }
    $rows = earthlink_parse_sessions_html($after);
    return array('ok' => true, 'rows' => $rows);
}

/**
 * استخراج صفوف الجلسات من HTML شبكة SessionsGrid.
 * @return array packs compatible with sas_session_pack-ish
 */
function earthlink_parse_sessions_html($html)
{
    $out = array();
    if (!is_string($html) || $html === '') {
        return $out;
    }
    if (!preg_match_all('/<tr[^>]*class="[^"]*rg(?:Alt)?Row[^"]*"[^>]*>(.*?)<\/tr>/is', $html, $trs)
        && !preg_match_all('/class="rg(?:Alt)?Row"[^>]*>(.*?)<\/tr>/is', $html, $trs)
    ) {
        return $out;
    }
    foreach ($trs[1] as $tr) {
        if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $tr, $cells)) {
            continue;
        }
        $vals = array();
        foreach ($cells[1] as $c) {
            $vals[] = trim(html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8'));
        }
        if (count($vals) < 4) {
            continue;
        }
        // ترتيب شائع: Start, Stop, Duration, MAC/Caller, IP, Upload, Download, …
        $start = '';
        $stop = '';
        $mac = '';
        $ip = '';
        $up = '';
        $down = '';
        $dur = '';
        foreach ($vals as $v) {
            if ($start === '' && preg_match('/\d{1,2}\/\d{1,2}\/\d{2,4}/', $v)) {
                $start = $v;
                continue;
            }
            if ($start !== '' && $stop === '' && (preg_match('/\d{1,2}\/\d{1,2}\/\d{2,4}/', $v) || strcasecmp($v, 'Online') === 0 || $v === '-')) {
                $stop = (strcasecmp($v, 'Online') === 0) ? '' : (($v === '-') ? '' : $v);
                continue;
            }
            if ($dur === '' && preg_match('/^\d+:\d{2}(:\d{2})?$/', $v)) {
                $dur = $v;
                continue;
            }
            if ($mac === '' && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $v)) {
                $mac = $v;
                continue;
            }
            if ($ip === '' && filter_var($v, FILTER_VALIDATE_IP)) {
                $ip = $v;
                continue;
            }
            if ($up === '' && preg_match('/[\d.,]+\s*(KB|MB|GB|B|KiB|MiB|GiB)?/i', $v) && stripos($v, ':') === false) {
                if ($down === '') {
                    // أول رقم استهلاك غالباً رفع أو تحميل حسب ترتيب الأعمدة
                    $up = $v;
                } else {
                    break;
                }
                continue;
            }
            if ($up !== '' && $down === '' && preg_match('/[\d.,]+\s*(KB|MB|GB|B|KiB|MiB|GiB)?/i', $v) && stripos($v, ':') === false) {
                $down = $v;
            }
        }
        if ($start === '' && $mac === '' && $ip === '') {
            continue;
        }
        $open = ($stop === '');
        $startSql = earthlink_session_time_to_sql($start);
        $stopSql = $open ? null : earthlink_session_time_to_sql($stop);
        $out[] = array(
            'mac' => $mac,
            'mac_address' => $mac,
            'ip' => $ip,
            'framed_ip' => $ip,
            'start' => $startSql ? $startSql : $start,
            'started_at' => $startSql,
            'stop' => $stopSql ? $stopSql : '',
            'stopped_at' => $stopSql,
            'open' => $open ? 1 : 0,
            'is_open' => $open ? 1 : 0,
            'duration' => $dur,
            'up' => $up,
            'down' => $down,
            'acctsessionid' => 'el:' . md5($start . '|' . $mac . '|' . $ip . '|' . $stop),
        );
    }
    return $out;
}

/**
 * جلب جلسات إيرث لمشترك من الكاش باستخدام حساب الشركة.
 */
function earthlink_fetch_user_sessions_for_cache($pdo, $cache, $days = 30)
{
    if (!$pdo || !is_array($cache) || empty($cache['username'])) {
        return array('ok' => false, 'error' => 'بيانات ناقصة', 'rows' => array());
    }
    $tid = isset($cache['tenant_id']) ? (int) $cache['tenant_id'] : (function_exists('current_tenant_id') ? (int) current_tenant_id() : 0);
    $companyId = !empty($cache['company_id']) ? (int) $cache['company_id'] : 0;
    $acc = null;
    if (function_exists('tenant_sas_accounts_list')) {
        foreach (tenant_sas_accounts_list($pdo, $tid) as $row) {
            if (!function_exists('tenant_sas_account_is_earthlink') || !tenant_sas_account_is_earthlink($pdo, $row)) {
                continue;
            }
            $accCo = !empty($row['company_id']) ? (int) $row['company_id'] : 0;
            if ($companyId > 0 && $accCo > 0 && $accCo !== $companyId) {
                continue;
            }
            $acc = $row;
            if ($companyId <= 0 || $accCo === $companyId) {
                break;
            }
        }
    }
    if (!$acc) {
        return array('ok' => false, 'error' => 'ماكو حساب إيرث', 'rows' => array());
    }
    $host = isset($acc['sas_host']) ? $acc['sas_host'] : '';
    $user = isset($acc['sas_username']) ? $acc['sas_username'] : '';
    $pass = isset($acc['sas_password']) ? $acc['sas_password'] : '';
    $alt = ($companyId > 0) ? earthlink_company_alt_host($pdo, $companyId) : '';
    if ($alt === '' && !empty($acc['company_id'])) {
        $alt = earthlink_company_alt_host($pdo, (int) $acc['company_id']);
    }
    return earthlink_fetch_user_sessions($host, $user, $pass, $cache['username'], $alt, $days);
}

function earthlink_session_time_to_sql($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    if (!$ts) {
        // m/d/Y h:i:s
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?#', $raw, $m)) {
            $ts = mktime(
                isset($m[4]) ? (int) $m[4] : 0,
                isset($m[5]) ? (int) $m[5] : 0,
                isset($m[6]) ? (int) $m[6] : 0,
                (int) $m[1],
                (int) $m[2],
                (int) $m[3]
            );
        }
    }
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/**
 * افتح جلسة بوابة إيرث (كوكيز) مع تجربة الهوستات البديلة.
 * @return array{ok:bool,host?:string,cookie?:string,error?:string}
 */
function earthlink_portal_session($host, $loginUser, $loginPass, $altHost = '')
{
    $auth = earthlink_auth($host, $loginUser, $loginPass, false, $altHost);
    if (empty($auth['ok'])) {
        return array('ok' => false, 'error' => isset($auth['error']) ? $auth['error'] : 'فشل الدخول لإيرث');
    }
    $usedHost = isset($auth['host']) ? $auth['host'] : earthlink_normalize_host($host);
    $cookie = isset($auth['cookie_file']) ? $auth['cookie_file'] : '';
    if ($cookie === '') {
        return array('ok' => false, 'error' => 'جلسة إيرث غير متاحة');
    }
    if (isset($auth['mode']) && $auth['mode'] !== 'portal') {
        $portal = earthlink_auth_portal($usedHost, $loginUser, $loginPass);
        if (empty($portal['ok'])) {
            foreach (earthlink_host_candidates($host, $altHost) as $tryHost) {
                $portal = earthlink_auth_portal($tryHost, $loginUser, $loginPass);
                if (!empty($portal['ok'])) {
                    $usedHost = $tryHost;
                    break;
                }
            }
        }
        if (empty($portal['ok'])) {
            return array('ok' => false, 'error' => isset($portal['error']) ? $portal['error'] : 'تعذر فتح بوابة إيرث');
        }
        $cookie = $portal['cookie_file'];
    }
    return array('ok' => true, 'host' => $usedHost, 'cookie' => $cookie);
}

/**
 * قراءة DisplayName من WebAPI بعد التعديل.
 */
function earthlink_get_user_display_info($base, $cookie, $uid)
{
    $uid = trim((string) $uid);
    if ($uid === '' || $base === '' || $cookie === '') {
        return array();
    }
    $url = rtrim($base, '/') . '/WebAPIs/api/Users/GetUserDisplayInfo?userId=' . rawurlencode($uid);
    list($body, $code, $err) = earthlink_http(
        'GET',
        $url,
        null,
        array(
            'Accept: application/json',
            'User-Agent: ' . earthlink_ua(),
        ),
        25,
        $cookie
    );
    if (!is_string($body) || $body === '') {
        return array();
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return array();
    }
    $val = isset($json['Value']) && is_array($json['Value']) ? $json['Value'] : $json;
    $userObj = isset($val['UserObject']) && is_array($val['UserObject']) ? $val['UserObject'] : array();
    return array(
        'display_name' => earthlink_find_string($userObj, array('DisplayName', 'displayName'))
            ?: earthlink_find_string($val, array('DisplayName', 'displayName')),
        'account_name' => earthlink_find_string($val, array('AccountName', 'accountName')),
        'account_status' => earthlink_find_string($val, array('AccountStatus', 'accountStatus')),
    );
}

/**
 * تغيير نوع اشتراك إيرث عبر Users/ChangeAccount.aspx
 * @param string $accountWant اسم الباقة أو رقم AccountTypesList
 * @return array{ok:bool,error?:string,message?:string,account_name?:string}
 */
function earthlink_change_account_type($host, $loginUser, $loginPass, $uid, $accountWant, $altHost = '')
{
    $uid = trim((string) $uid);
    $accountWant = trim((string) $accountWant);
    if ($uid === '' || $accountWant === '') {
        return array('ok' => false, 'error' => 'اليوزرنيم أو نوع الاشتراك فارغ');
    }
    if (stripos($accountWant, 'el:') === 0 && function_exists('earthlink_package_name_from_ui_id')) {
        $decoded = earthlink_package_name_from_ui_id($accountWant);
        if ($decoded !== '') {
            $accountWant = $decoded;
        }
    }
    $sess = earthlink_portal_session($host, $loginUser, $loginPass, $altHost);
    if (empty($sess['ok'])) {
        return array('ok' => false, 'error' => isset($sess['error']) ? $sess['error'] : 'فشل جلسة إيرث');
    }
    $usedHost = $sess['host'];
    $cookie = $sess['cookie'];
    $base = earthlink_base_url($usedHost);
    $url = $base . '/Users/ChangeAccount.aspx?uid=' . rawurlencode($uid);
    list($html, $code, $err) = earthlink_http(
        'GET',
        $url,
        null,
        array(
            'Accept: text/html',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $base . '/Users/UserEdit.aspx?uid=' . rawurlencode($uid),
        ),
        40,
        $cookie
    );
    if ($html === false || earthlink_portal_looks_like_login($html) || stripos($html, 'Error Page') !== false) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'تعذر فتح صفحة تغيير نوع الاشتراك');
    }
    $fields = array();
    if (preg_match('/name="__VIEWSTATE"[^>]*value="([^"]*)"/i', $html, $m)) {
        $fields['__VIEWSTATE'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/name="__VIEWSTATEGENERATOR"[^>]*value="([^"]*)"/i', $html, $m)) {
        $fields['__VIEWSTATEGENERATOR'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/name="__EVENTVALIDATION"[^>]*value="([^"]*)"/i', $html, $m)) {
        $fields['__EVENTVALIDATION'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    if (empty($fields['__VIEWSTATE'])) {
        return array('ok' => false, 'error' => 'نموذج تغيير الاشتراك غير مكتمل');
    }
    $selectName = 'AccountTypesDrop$AccountTypesList';
    $options = array();
    if (preg_match('/<select[^>]*name="' . preg_quote($selectName, '/') . '"[^>]*>(.*?)<\/select>/is', $html, $sm)
        || preg_match('/<select[^>]*name="[^"]*AccountTypesList"[^>]*>(.*?)<\/select>/is', $html, $sm)
    ) {
        if (preg_match('/name="([^"]*AccountTypesList)"/i', $sm[0], $nm)) {
            $selectName = html_entity_decode($nm[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>(.*?)<\/option>/is', $sm[1], $om)) {
            $n = count($om[1]);
            for ($i = 0; $i < $n; $i++) {
                $val = html_entity_decode($om[1][$i], ENT_QUOTES, 'UTF-8');
                $text = trim(html_entity_decode(strip_tags($om[2][$i]), ENT_QUOTES, 'UTF-8'));
                if ($val === '' && ($text === '' || stripos($text, 'select') !== false)) {
                    continue;
                }
                $options[] = array('id' => $val, 'name' => $text);
            }
        }
    }
    if (!$options) {
        return array('ok' => false, 'error' => 'ماكو قائمة أنواع اشتراك بإيرث');
    }
    $picked = '';
    $pickedName = '';
    $wantLow = function_exists('mb_strtolower') ? mb_strtolower($accountWant, 'UTF-8') : strtolower($accountWant);
    foreach ($options as $opt) {
        $id = (string) $opt['id'];
        $nm = (string) $opt['name'];
        $nmLow = function_exists('mb_strtolower') ? mb_strtolower($nm, 'UTF-8') : strtolower($nm);
        if ($id === $accountWant || $nm === $accountWant || $nmLow === $wantLow) {
            $picked = $id;
            $pickedName = $nm;
            break;
        }
    }
    if ($picked === '') {
        return array('ok' => false, 'error' => 'نوع الاشتراك غير موجود بالقائمة: ' . $accountWant);
    }
    $fields['__EVENTTARGET'] = '';
    $fields['__EVENTARGUMENT'] = '';
    $fields[$selectName] = $picked;
    $fields['OkButton'] = 'OK';
    $body = http_build_query($fields);
    list($after, $code2, $err2) = earthlink_http(
        'POST',
        $url,
        $body,
        array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $url,
        ),
        45,
        $cookie
    );
    if ($after === false) {
        return array('ok' => false, 'error' => $err2 !== '' ? $err2 : 'فشل إرسال تغيير الاشتراك');
    }
    if (earthlink_portal_looks_like_login($after)) {
        return array('ok' => false, 'error' => 'انتهت جلسة إيرث أثناء تغيير الاشتراك');
    }
    $info = earthlink_get_user_display_info($base, $cookie, $uid);
    $gotName = isset($info['account_name']) ? trim((string) $info['account_name']) : '';
    if ($gotName !== '' && $pickedName !== '') {
        $gotLow = function_exists('mb_strtolower') ? mb_strtolower($gotName, 'UTF-8') : strtolower($gotName);
        $pickLow = function_exists('mb_strtolower') ? mb_strtolower($pickedName, 'UTF-8') : strtolower($pickedName);
        if ($gotLow !== $pickLow) {
            return array('ok' => false, 'error' => 'إيرث ما ثبّت نوع الاشتراك (بقي: ' . $gotName . ')');
        }
    }
    return array(
        'ok' => true,
        'message' => 'تم تغيير نوع الاشتراك على إيرث',
        'account_name' => $gotName !== '' ? $gotName : $pickedName,
        'account_id' => $picked,
    );
}

function earthlink_change_account_type_for_cache($pdo, $cache, $accountWant)
{
    if (!$pdo || !is_array($cache) || empty($cache['username'])) {
        return array('ok' => false, 'error' => 'بيانات المشترك ناقصة');
    }
    $tid = isset($cache['tenant_id']) ? (int) $cache['tenant_id'] : (function_exists('current_tenant_id') ? (int) current_tenant_id() : 0);
    $companyId = !empty($cache['company_id']) ? (int) $cache['company_id'] : 0;
    $acc = null;
    if (function_exists('tenant_sas_accounts_list')) {
        foreach (tenant_sas_accounts_list($pdo, $tid) as $row) {
            if (!function_exists('tenant_sas_account_is_earthlink') || !tenant_sas_account_is_earthlink($pdo, $row)) {
                continue;
            }
            $accCo = !empty($row['company_id']) ? (int) $row['company_id'] : 0;
            if ($companyId > 0 && $accCo > 0 && $accCo !== $companyId) {
                continue;
            }
            $acc = $row;
            if ($companyId <= 0 || $accCo === $companyId) {
                break;
            }
        }
    }
    if (!$acc) {
        return array('ok' => false, 'error' => 'ماكو حساب إيرث مرتبط');
    }
    $host = isset($acc['sas_host']) ? $acc['sas_host'] : '';
    $user = isset($acc['sas_username']) ? $acc['sas_username'] : '';
    $pass = isset($acc['sas_password']) ? $acc['sas_password'] : '';
    $alt = ($companyId > 0) ? earthlink_company_alt_host($pdo, $companyId) : '';
    if ($alt === '' && !empty($acc['company_id'])) {
        $alt = earthlink_company_alt_host($pdo, (int) $acc['company_id']);
    }
    return earthlink_change_account_type($host, $user, $pass, $cache['username'], $accountWant, $alt);
}

/**
 * تحديث مشترك إيرث عبر بوابة Users/UserEdit.aspx
 * الحقل القابل للتعديل للاسم العربي: ArabicName
 *
 * @param array $changes keys: display_name|arabic_name, notes, mac, router_ip, enabled, password, profile_name|account_type
 * @return array{ok:bool,error?:string,message?:string}
 */
function earthlink_update_user($host, $loginUser, $loginPass, $uid, $changes, $altHost = '')
{
    $uid = trim((string) $uid);
    $changes = is_array($changes) ? $changes : array();
    if ($uid === '') {
        return array('ok' => false, 'error' => 'يوزرنيم فارغ');
    }
    $accountWant = '';
    $chgAcc = null;
    if (!empty($changes['profile_name'])) {
        $accountWant = trim((string) $changes['profile_name']);
    } elseif (!empty($changes['account_type'])) {
        $accountWant = trim((string) $changes['account_type']);
    }
    if ($accountWant !== '') {
        $chgAcc = earthlink_change_account_type($host, $loginUser, $loginPass, $uid, $accountWant, $altHost);
        if (empty($chgAcc['ok'])) {
            return $chgAcc;
        }
    }
    $sess = earthlink_portal_session($host, $loginUser, $loginPass, $altHost);
    if (empty($sess['ok'])) {
        return array('ok' => false, 'error' => isset($sess['error']) ? $sess['error'] : 'فشل جلسة إيرث');
    }
    $usedHost = $sess['host'];
    $cookie = $sess['cookie'];
    $base = earthlink_base_url($usedHost);
    $url = $base . '/Users/UserEdit.aspx?uid=' . rawurlencode($uid);
    list($html, $code, $err) = earthlink_http(
        'GET',
        $url,
        null,
        array(
            'Accept: text/html',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $base . '/UserManagement.aspx',
        ),
        40,
        $cookie
    );
    if ($html === false || earthlink_portal_looks_like_login($html)) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'تعذر فتح صفحة تعديل المشترك');
    }
    if (stripos($html, 'Error Page') !== false && stripos($html, 'ArabicName') === false) {
        return array('ok' => false, 'error' => 'صفحة تعديل المشترك غير موجودة على هذا الهوست');
    }
    $fields = earthlink_portal_collect_post_fields($html);
    if (!$fields) {
        return array('ok' => false, 'error' => 'تعذر قراءة نموذج التعديل');
    }
    $prefix = 'ctl00$ctl00$MainContentPlaceHolder$MainContentPlaceHolder$';
    $map = array(
        'arabic_name' => $prefix . 'ArabicName',
        'display_name' => $prefix . 'ArabicName',
        'firstname' => $prefix . 'ArabicName',
        'notes' => $prefix . 'UserNotes',
        'mac' => $prefix . 'MacTextBox',
        'router_ip' => $prefix . 'RouterIp',
    );
    foreach ($map as $src => $dst) {
        if (!array_key_exists($src, $changes)) {
            continue;
        }
        $fields[$dst] = (string) $changes[$src];
    }
    if (isset($changes['enabled'])) {
        $chk = $prefix . 'UserActiveManageCheckBox';
        if ((string) $changes['enabled'] === '1' || $changes['enabled'] === 1 || $changes['enabled'] === true) {
            $fields[$chk] = 'on';
        } else {
            unset($fields[$chk]);
        }
    }
    $btn = $prefix . 'UpdateButton2';
    $fields[$btn] = 'Update';
    $fields['__EVENTTARGET'] = '';
    $fields['__EVENTARGUMENT'] = '';

    $body = http_build_query($fields);
    list($after, $code2, $err2) = earthlink_http(
        'POST',
        $url,
        $body,
        array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $url,
        ),
        45,
        $cookie
    );
    if ($after === false) {
        return array('ok' => false, 'error' => $err2 !== '' ? $err2 : 'فشل إرسال التعديل');
    }
    if (earthlink_portal_looks_like_login($after)) {
        return array('ok' => false, 'error' => 'انتهت جلسة إيرث أثناء الحفظ');
    }
    // تحقق: الاسم العربي / DisplayName
    $want = '';
    if (isset($changes['arabic_name'])) {
        $want = trim((string) $changes['arabic_name']);
    } elseif (isset($changes['display_name'])) {
        $want = trim((string) $changes['display_name']);
    } elseif (isset($changes['firstname'])) {
        $want = trim((string) $changes['firstname']);
    }
    if ($want !== '') {
        $got = '';
        if (preg_match('/name="' . preg_quote($prefix . 'ArabicName', '/') . '"[^>]*value="([^"]*)"/i', $after, $m)
            || preg_match('/id="[^"]*ArabicName"[^>]*value="([^"]*)"/i', $after, $m)
        ) {
            $got = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        if ($got === '') {
            list($reload, $rc, $re) = earthlink_http(
                'GET',
                $url,
                null,
                array(
                    'Accept: text/html',
                    'User-Agent: ' . earthlink_ua(),
                    'Referer: ' . $url,
                ),
                40,
                $cookie
            );
            if (is_string($reload) && $reload !== '') {
                if (preg_match('/name="' . preg_quote($prefix . 'ArabicName', '/') . '"[^>]*value="([^"]*)"/i', $reload, $m)
                    || preg_match('/id="[^"]*ArabicName"[^>]*value="([^"]*)"/i', $reload, $m)
                ) {
                    $got = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                }
            }
        }
        if ($got === '') {
            $info = earthlink_get_user_display_info($base, $cookie, $uid);
            if (!empty($info['display_name'])) {
                $got = trim((string) $info['display_name']);
            }
        }
        $wantLow = function_exists('mb_strtolower') ? mb_strtolower($want, 'UTF-8') : strtolower($want);
        $gotLow = function_exists('mb_strtolower') ? mb_strtolower($got, 'UTF-8') : strtolower($got);
        if ($got === '' || $gotLow !== $wantLow) {
            return array('ok' => false, 'error' => 'إيرث ما حفظ الاسم العربي بالبوابة — تأكد من الصلاحيات');
        }
    }
    // كلمة السر من نافذة منفصلة إن طُلبت
    if (!empty($changes['password'])) {
        $pass = (string) $changes['password'];
        $confirm = isset($changes['confirm_password']) ? (string) $changes['confirm_password'] : $pass;
        if ($pass !== $confirm) {
            return array('ok' => false, 'error' => 'تأكيد كلمة السر غير مطابق');
        }
    }
    $msg = 'تم الحفظ على إيرث لنك';
    $accName = ($chgAcc && !empty($chgAcc['account_name'])) ? (string) $chgAcc['account_name'] : '';
    if ($accountWant !== '' && $accName !== '') {
        $msg .= ' · الاشتراك: ' . $accName;
    }
    return array(
        'ok' => true,
        'message' => $msg,
        'display_name' => $want,
        'account_name' => $accName,
    );
}

/**
 * حدّث مشترك إيرث باستخدام حساب الشركة المرتبط بالكاش.
 * @return array{ok:bool,error?:string,message?:string}
 */
function earthlink_update_user_for_cache($pdo, $cache, $changes)
{
    if (!$pdo || !is_array($cache) || empty($cache['username'])) {
        return array('ok' => false, 'error' => 'بيانات المشترك ناقصة');
    }
    $tid = isset($cache['tenant_id']) ? (int) $cache['tenant_id'] : (function_exists('current_tenant_id') ? (int) current_tenant_id() : 0);
    $companyId = !empty($cache['company_id']) ? (int) $cache['company_id'] : 0;
    $acc = null;
    if (function_exists('tenant_sas_accounts_list')) {
        foreach (tenant_sas_accounts_list($pdo, $tid) as $row) {
            if (!function_exists('tenant_sas_account_is_earthlink') || !tenant_sas_account_is_earthlink($pdo, $row)) {
                continue;
            }
            $accCo = !empty($row['company_id']) ? (int) $row['company_id'] : 0;
            if ($companyId > 0 && $accCo > 0 && $accCo !== $companyId) {
                continue;
            }
            $acc = $row;
            if ($companyId <= 0 || $accCo === $companyId) {
                break;
            }
        }
    }
    if (!$acc) {
        return array('ok' => false, 'error' => 'ماكو حساب إيرث مرتبط');
    }
    $host = isset($acc['sas_host']) ? $acc['sas_host'] : '';
    $user = isset($acc['sas_username']) ? $acc['sas_username'] : '';
    $pass = isset($acc['sas_password']) ? $acc['sas_password'] : '';
    $alt = ($companyId > 0) ? earthlink_company_alt_host($pdo, $companyId) : '';
    if ($alt === '' && !empty($acc['company_id'])) {
        $alt = earthlink_company_alt_host($pdo, (int) $acc['company_id']);
    }
    return earthlink_update_user($host, $user, $pass, $cache['username'], $changes, $alt);
}

/**
 * رصيد الإيداع من شريط بوابة إيرث (Home / أي صفحة).
 * @return array{ok:bool,iqd?:int,disp?:string,error?:string}
 */
function earthlink_portal_balance($host, $loginUser, $loginPass, $altHost = '')
{
    $sess = earthlink_portal_session($host, $loginUser, $loginPass, $altHost);
    if (empty($sess['ok'])) {
        return array('ok' => false, 'error' => isset($sess['error']) ? $sess['error'] : 'فشل جلسة إيرث');
    }
    $used = $sess['host'];
    $cookie = $sess['cookie'];
    $base = earthlink_base_url($used);
    list($html, $code, $err) = earthlink_http(
        'GET',
        $base . '/Home.aspx',
        null,
        array('Accept: text/html', 'User-Agent: ' . earthlink_ua()),
        30,
        $cookie
    );
    if (!is_string($html) || $html === '' || earthlink_portal_looks_like_login($html)) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'تعذر قراءة الرصيد');
    }
    $raw = '';
    // الهيدر: balanacelabel (خطأ إملائي ببوابة إيرث) أو balancesection
    if (preg_match('/id="[^"]*balanacelabel[^"]*"[^>]*>([\s\S]{0,80}?)</i', $html, $m)
        || preg_match('/id="[^"]*BalanceLabel[^"]*"[^>]*>([\s\S]{0,80}?)</i', $html, $m)
    ) {
        $raw = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    if ($raw === '' || !preg_match('/\d/', $raw)) {
        if (preg_match('/class="[^"]*accountbalance[^"]*"[^>]*>([\s\S]{0,240}?)<\/(?:div|span|a|td|li)/i', $html, $m)
            || preg_match('/id="[^"]*accountbalance[^"]*"[^>]*>([\s\S]{0,240}?)<\/(?:div|span|a|td|li)/i', $html, $m)
        ) {
            $raw = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        }
    }
    if ($raw === '' || !preg_match('/\d/', $raw)) {
        if (preg_match('/Balance\s*:\s*([\d,]+(?:\.\d+)?)\s*IQD/i', $html, $m)) {
            $raw = $m[1] . ' IQD';
        } elseif (preg_match('/accountbalance[^>]*>[\s\S]{0,300}?([\d,]{3,}\s*IQD)/i', $html, $m)) {
            $raw = trim($m[1]);
        }
    }
    if ($raw === '' || !preg_match('/\d/', $raw)) {
        return array('ok' => false, 'error' => 'ماكو رصيد ظاهر بالبوابة');
    }
    $num = null;
    if (preg_match('/([\d,]+(?:\.\d+)?)/', $raw, $nm)) {
        $num = (float) str_replace(',', '', $nm[1]);
    }
    $iqd = $num !== null ? (int) round($num) : 0;
    $disp = ($iqd > 0)
        ? (number_format($iqd) . ' IQD')
        : $raw;
    return array('ok' => true, 'iqd' => $iqd, 'value' => $num, 'disp' => $disp, 'raw' => $raw);
}

function earthlink_portal_balance_for_account($pdo, $acc)
{
    if (!$acc || !is_array($acc)) {
        return array('ok' => false, 'error' => 'حساب فارغ');
    }
    $host = isset($acc['sas_host']) ? $acc['sas_host'] : '';
    $user = isset($acc['sas_username']) ? $acc['sas_username'] : '';
    $pass = isset($acc['sas_password']) ? $acc['sas_password'] : '';
    $cid = !empty($acc['company_id']) ? (int) $acc['company_id'] : 0;
    $alt = ($cid > 0) ? earthlink_company_alt_host($pdo, $cid) : '';
    return earthlink_portal_balance($host, $user, $pass, $alt);
}

/**
 * اختر قيمة باقة من قائمة Refill حسب الاسم.
 */
function earthlink_refill_pick_account_type($html, $wantName = '')
{
    $wantName = trim((string) $wantName);
    $first = '';
    $wantLow = function_exists('mb_strtolower') ? mb_strtolower($wantName, 'UTF-8') : strtolower($wantName);
    if (!preg_match_all('/<option[^>]*value=["\']([^"\']*)["\'][^>]*>([^<]*)</i', (string) $html, $mm, PREG_SET_ORDER)) {
        return array('id' => '', 'label' => '', 'cost' => null);
    }
    $best = null;
    foreach ($mm as $o) {
        $id = (string) $o[1];
        $lab = trim(html_entity_decode($o[2], ENT_QUOTES, 'UTF-8'));
        if ($id === '' || $lab === '') {
            continue;
        }
        if ($first === '') {
            $first = $id;
            $best = array('id' => $id, 'label' => $lab);
        }
        $labLow = function_exists('mb_strtolower') ? mb_strtolower($lab, 'UTF-8') : strtolower($lab);
        if ($wantLow !== '' && ($labLow === $wantLow || strpos($labLow, $wantLow) !== false || strpos($wantLow, $labLow) !== false)) {
            $best = array('id' => $id, 'label' => $lab);
            break;
        }
    }
    if (!$best) {
        return array('id' => $first, 'label' => '', 'cost' => null);
    }
    $cost = null;
    if (preg_match('/([\d]{2,}[\d,]*)/', $best['label'], $cm)) {
        $cost = (int) str_replace(',', '', $cm[1]);
    }
    $best['cost'] = $cost;
    return $best;
}

/**
 * تفعيل/تجديد مشترك إيرث عبر RefillUser.aspx (رصيد أونلاين أو كرت مسبق).
 * $mode: deposit|card
 * @return array{ok:bool,message?:string,error?:string,cost?:int}
 */
function earthlink_refill_user($host, $loginUser, $loginPass, $uid, $mode = 'deposit', $opts = array(), $altHost = '')
{
    $uid = trim((string) $uid);
    $mode = ($mode === 'card') ? 'card' : 'deposit';
    $opts = is_array($opts) ? $opts : array();
    if ($uid === '') {
        return array('ok' => false, 'error' => 'يوزرنيم فارغ');
    }
    $sess = earthlink_portal_session($host, $loginUser, $loginPass, $altHost);
    if (empty($sess['ok'])) {
        return array('ok' => false, 'error' => isset($sess['error']) ? $sess['error'] : 'فشل جلسة إيرث');
    }
    $used = $sess['host'];
    $cookie = $sess['cookie'];
    $base = earthlink_base_url($used);
    $url = $base . '/users/RefillUser.aspx?uid=' . rawurlencode($uid);
    list($html, $code, $err) = earthlink_http(
        'GET',
        $url,
        null,
        array(
            'Accept: text/html',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $base . '/UserManagement.aspx',
        ),
        40,
        $cookie
    );
    if (!is_string($html) || $html === '' || earthlink_portal_looks_like_login($html) || stripos($html, 'Error Page') !== false) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'تعذر فتح صفحة التعبئة');
    }
    $fields = earthlink_portal_collect_post_fields($html);
    if (!$fields) {
        return array('ok' => false, 'error' => 'تعذر قراءة نموذج التعبئة');
    }
    $payWith = isset($opts['pay_with']) ? strtolower(trim((string) $opts['pay_with'])) : '';
    if ($mode === 'points' || $payWith === 'points' || $payWith === 'reward') {
        $payWith = 'points';
    }
    if ($mode === 'card') {
        $pin = isset($opts['pin']) ? trim((string) $opts['pin']) : '';
        $cardPass = isset($opts['card_password']) ? (string) $opts['card_password'] : '';
        if ($pin === '') {
            return array('ok' => false, 'error' => 'أدخل رقم الكرت');
        }
        $fields['refillgroup'] = 'userprepayradio';
        $fields['PrePayCardIdentifier'] = $pin;
        $fields['PrePayCardPassword'] = $cardPass;
    } else {
        // رصيد أونلاين أو نقاط تشجيعية (إن وُجد خيار بالنموذج)
        $fields['refillgroup'] = 'depositradio';
        $fields['PrePayCardIdentifier'] = '';
        $fields['PrePayCardPassword'] = '';
        if ($payWith === 'points') {
            // جرّب راديو النقاط إن ظهر بصفحة التعبئة
            foreach (array('incentiveradio', 'pointsradio', 'rewardradio', 'rewardpointsradio') as $ptRadio) {
                if (stripos($html, 'value="' . $ptRadio . '"') !== false
                    || stripos($html, "value='" . $ptRadio . "'") !== false
                    || stripos($html, 'id="' . $ptRadio . '"') !== false
                ) {
                    $fields['refillgroup'] = $ptRadio;
                    break;
                }
            }
        }
    }
    $fields['payment'] = 'nopayment';
    $fields['willpayinbox'] = '1';
    $fields['notesbox'] = isset($opts['notes']) ? (string) $opts['notes'] : '';
    $fields['NextButton'] = 'Next';
    $fields['__EVENTTARGET'] = '';
    $fields['__EVENTARGUMENT'] = '';
    list($step2, $c2, $e2) = earthlink_http(
        'POST',
        $url,
        http_build_query($fields),
        array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $url,
        ),
        45,
        $cookie
    );
    if (!is_string($step2) || $step2 === '' || earthlink_portal_looks_like_login($step2)) {
        return array('ok' => false, 'error' => $e2 !== '' ? $e2 : 'فشل خطوة التعبئة');
    }
    $fields2 = earthlink_portal_collect_post_fields($step2);
    if (!$fields2) {
        return array('ok' => false, 'error' => 'تعذر قراءة خطوة تأكيد التعبئة');
    }
    $wantPkg = isset($opts['profile_name']) ? trim((string) $opts['profile_name']) : '';
    $pick = earthlink_refill_pick_account_type($step2, $wantPkg);
    if ($pick['id'] !== '') {
        $fields2['AccountTypesDrop$AccountTypesList'] = $pick['id'];
    }
    // كلمة سر الريسلر مطلوبة لتأكيد الدفع الأونلاين
    $fields2['onlinepassword'] = (string) $loginPass;
    if ($mode === 'card') {
        $fields2['refillgroup'] = 'userprepayradio';
    } elseif ($payWith === 'points') {
        $fields2['refillgroup'] = isset($fields['refillgroup']) ? $fields['refillgroup'] : 'depositradio';
        // حقول شائعة للنقاط إن وُجدت بخطوة التأكيد
        foreach (array('UseIncentivePoints', 'IncentivePoints', 'RewardPoints', 'UsePoints', 'paywithpoints') as $pk) {
            if (array_key_exists($pk, $fields2) || stripos($step2, $pk) !== false) {
                $fields2[$pk] = 'on';
            }
        }
        if (preg_match('/name="([^"]*point[^"]*)"/i', $step2, $pm)) {
            $fields2[$pm[1]] = isset($fields2[$pm[1]]) ? $fields2[$pm[1]] : 'on';
        }
    } else {
        $fields2['refillgroup'] = 'depositradio';
    }
    $fields2['payment'] = 'nopayment';
    $fields2['__EVENTTARGET'] = 'RadAjaxManager1';
    $fields2['__EVENTARGUMENT'] = 'refilluser';
    $fields2['RadAJAXControlID'] = 'RadAjaxManager1';
    list($after, $c3, $e3) = earthlink_http(
        'POST',
        $url,
        http_build_query($fields2),
        array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/plain, */*',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $url,
            'X-Requested-With: XMLHttpRequest',
            'X-MicrosoftAjax: Delta=true',
        ),
        55,
        $cookie
    );
    if ($after === false) {
        return array('ok' => false, 'error' => $e3 !== '' ? $e3 : 'فشل تأكيد التعبئة');
    }
    $raw = is_string($after) ? $after : '';
    $low = strtolower($raw);
    $failHints = array('error', 'invalid', 'incorrect', 'failed', 'not enough', 'insufficient', 'رفض', 'فشل', 'غير كاف');
    $okHints = array('success', 'refill', 'تم', 'successpanel', 'closewindow');
    $looksFail = false;
    foreach ($failHints as $h) {
        if (strpos($low, $h) !== false && strpos($low, 'success') === false) {
            // soft — verify via display info below
            $looksFail = true;
            break;
        }
    }
    $info = earthlink_get_user_display_info($base, $cookie, $uid);
    $active = false;
    if (is_array($info)) {
        foreach (array('AccountStatus', 'accountStatus', 'ServiceStatus', 'IsActive', 'isActive') as $k) {
            if (!isset($info[$k])) {
                continue;
            }
            $v = $info[$k];
            if (is_bool($v) && $v) {
                $active = true;
            }
            $vs = strtolower(trim((string) $v));
            if ($vs === '1' || $vs === 'active' || $vs === 'enabled' || strpos($vs, 'active') !== false) {
                $active = true;
            }
        }
        if (!$active && !empty($info['Value']) && is_array($info['Value'])) {
            $val = $info['Value'];
            foreach (array('AccountStatus', 'accountStatus', 'OnlineStatus') as $k) {
                if (empty($val[$k])) {
                    continue;
                }
                $vs = strtolower(trim((string) $val[$k]));
                if (strpos($vs, 'active') !== false || strpos($vs, 'online') !== false) {
                    $active = true;
                }
            }
        }
    }
    if ($active || (!$looksFail && $c3 >= 200 && $c3 < 400)) {
        $msg = 'تم تفعيل المشترك على إيرث';
        if (!empty($pick['label'])) {
            $msg .= ' · ' . $pick['label'];
        }
        return array(
            'ok' => true,
            'message' => $msg,
            'cost' => isset($pick['cost']) ? $pick['cost'] : null,
            'account_type' => isset($pick['label']) ? $pick['label'] : '',
            'raw' => substr($raw, 0, 400),
        );
    }
    $snip = trim(preg_replace('/\s+/', ' ', strip_tags($raw)));
    if (strlen($snip) > 180) {
        $snip = substr($snip, 0, 180);
    }
    return array(
        'ok' => false,
        'error' => $snip !== '' ? ('إيرث: ' . $snip) : 'فشل تفعيل إيرث — تحقق من الرصيد أو الكرت',
        'cost' => isset($pick['cost']) ? $pick['cost'] : null,
    );
}

function earthlink_refill_user_for_cache($pdo, $cache, $mode = 'deposit', $opts = array())
{
    if (!$pdo || !is_array($cache) || empty($cache['username'])) {
        return array('ok' => false, 'error' => 'بيانات المشترك ناقصة');
    }
    $tid = isset($cache['tenant_id']) ? (int) $cache['tenant_id'] : (function_exists('current_tenant_id') ? (int) current_tenant_id() : 0);
    $companyId = !empty($cache['company_id']) ? (int) $cache['company_id'] : 0;
    $acc = null;
    if (function_exists('tenant_sas_accounts_list')) {
        foreach (tenant_sas_accounts_list($pdo, $tid) as $row) {
            if (!function_exists('tenant_sas_account_is_earthlink') || !tenant_sas_account_is_earthlink($pdo, $row)) {
                continue;
            }
            $accCo = !empty($row['company_id']) ? (int) $row['company_id'] : 0;
            if ($companyId > 0 && $accCo > 0 && $accCo !== $companyId) {
                continue;
            }
            $acc = $row;
            if ($companyId <= 0 || $accCo === $companyId) {
                break;
            }
        }
    }
    if (!$acc) {
        return array('ok' => false, 'error' => 'ماكو حساب إيرث مرتبط');
    }
    if (empty($opts['profile_name']) && !empty($cache['profile_name'])) {
        $opts['profile_name'] = (string) $cache['profile_name'];
    }
    $host = isset($acc['sas_host']) ? $acc['sas_host'] : '';
    $user = isset($acc['sas_username']) ? $acc['sas_username'] : '';
    $pass = isset($acc['sas_password']) ? $acc['sas_password'] : '';
    $alt = ($companyId > 0) ? earthlink_company_alt_host($pdo, $companyId) : '';
    if ($alt === '' && !empty($acc['company_id'])) {
        $alt = earthlink_company_alt_host($pdo, (int) $acc['company_id']);
    }
    return earthlink_refill_user($host, $user, $pass, $cache['username'], $mode, $opts, $alt);
}

/**
 * هل اليوزر موجود على حساب إيرث؟
 */
function earthlink_user_exists($host, $loginUser, $loginPass, $uid, $altHost = '')
{
    $uid = trim((string) $uid);
    if ($uid === '') {
        return array('ok' => false, 'exists' => false, 'error' => 'يوزر فارغ');
    }
    $sess = earthlink_portal_session($host, $loginUser, $loginPass, $altHost);
    if (empty($sess['ok'])) {
        return array('ok' => false, 'exists' => false, 'error' => isset($sess['error']) ? $sess['error'] : 'فشل جلسة إيرث');
    }
    $base = earthlink_base_url($sess['host']);
    $cookie = $sess['cookie'];
    $info = earthlink_get_user_display_info($base, $cookie, $uid);
    if (is_array($info) && (isset($info['Value']) || isset($info['UserName']) || isset($info['Username']))) {
        $has = !empty($info['Value']) || !empty($info['UserName']) || !empty($info['Username']);
        if ($has && empty($info['Error']) && empty($info['error'])) {
            return array('ok' => true, 'exists' => true);
        }
    }
    list($html) = earthlink_http(
        'GET',
        $base . '/Users/UserEdit.aspx?uid=' . rawurlencode($uid),
        null,
        array('Accept: text/html', 'User-Agent: ' . earthlink_ua()),
        30,
        $cookie
    );
    if (!is_string($html) || earthlink_portal_looks_like_login($html)) {
        return array('ok' => false, 'exists' => false, 'error' => 'تعذر التحقق');
    }
    if (stripos($html, 'Error Page') !== false) {
        return array('ok' => true, 'exists' => false);
    }
    if (stripos($html, 'ArabicName') !== false || stripos($html, 'UserEdit') !== false) {
        return array('ok' => true, 'exists' => true);
    }
    return array('ok' => true, 'exists' => false);
}

/**
 * إنشاء يوزر على إيرث عبر AddUser.aspx (نقل/إنشاء).
 */
function earthlink_create_user($host, $loginUser, $loginPass, $newUser, $password, $accountTypeId = '', $altHost = '')
{
    $newUser = trim((string) $newUser);
    $password = (string) $password;
    if ($newUser === '' || $password === '') {
        return array('ok' => false, 'error' => 'اليوزر وكلمة السر مطلوبة');
    }
    $exists = earthlink_user_exists($host, $loginUser, $loginPass, $newUser, $altHost);
    if (!empty($exists['ok']) && !empty($exists['exists'])) {
        return array('ok' => false, 'error' => 'exists', 'exists' => true);
    }
    $sess = earthlink_portal_session($host, $loginUser, $loginPass, $altHost);
    if (empty($sess['ok'])) {
        return array('ok' => false, 'error' => isset($sess['error']) ? $sess['error'] : 'فشل جلسة إيرث');
    }
    $base = earthlink_base_url($sess['host']);
    $cookie = $sess['cookie'];
    $url = $base . '/Users/AddUser.aspx';
    list($html, $code, $err) = earthlink_http(
        'GET',
        $url,
        null,
        array('Accept: text/html', 'User-Agent: ' . earthlink_ua()),
        40,
        $cookie
    );
    if (!is_string($html) || $html === '' || earthlink_portal_looks_like_login($html)) {
        return array('ok' => false, 'error' => $err !== '' ? $err : 'تعذر فتح AddUser');
    }
    $fields = earthlink_portal_collect_post_fields($html);
    if (!$fields) {
        return array('ok' => false, 'error' => 'تعذر قراءة نموذج الإنشاء');
    }
    $fields['useridbox'] = $newUser;
    $fields['passwordbox'] = $password;
    $accKey = 'ctl00$ctl00$MainContentPlaceHolder$MainContentPlaceHolder$AccountTypesDrop$AccountTypesList';
    if ($accountTypeId !== '') {
        $fields[$accKey] = (string) $accountTypeId;
    } elseif (empty($fields[$accKey])) {
        $pick = earthlink_refill_pick_account_type($html, '');
        if ($pick['id'] !== '') {
            $fields[$accKey] = $pick['id'];
        }
    }
    $btn = 'ctl00$ctl00$MainContentPlaceHolder$MainContentPlaceHolder$NextButton';
    $fields[$btn] = 'Next';
    $fields['__EVENTTARGET'] = '';
    $fields['__EVENTARGUMENT'] = '';
    list($after, $c2, $e2) = earthlink_http(
        'POST',
        $url,
        http_build_query($fields),
        array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html',
            'User-Agent: ' . earthlink_ua(),
            'Referer: ' . $url,
        ),
        50,
        $cookie
    );
    if ($after === false) {
        return array('ok' => false, 'error' => $e2 !== '' ? $e2 : 'فشل إنشاء المستخدم');
    }
    $check = earthlink_user_exists($host, $loginUser, $loginPass, $newUser, $altHost);
    if (!empty($check['ok']) && !empty($check['exists'])) {
        return array('ok' => true, 'message' => 'تم إنشاء المشترك على إيرث', 'username' => $newUser);
    }
    $snip = is_string($after) ? trim(preg_replace('/\s+/', ' ', strip_tags($after))) : '';
    if (preg_match('/(already|exist|محجوز|موجود|duplicate)/iu', $snip)) {
        return array('ok' => false, 'error' => 'exists', 'exists' => true);
    }
    return array(
        'ok' => false,
        'error' => $snip !== '' ? substr($snip, 0, 160) : 'ما تأكد إنشاء اليوزر على إيرث',
    );
}
