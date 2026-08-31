<?php

$sasConnectorFile = __DIR__ . '/sas/SASConnector.php';
if (is_file($sasConnectorFile) && !class_exists('SASConnector')) {
    require_once $sasConnectorFile;
}

/**
 * ترقية أعمدة SAS على المشتركين والباقات
 */
if (!function_exists('ensure_sas_columns')) {

function ensure_sas_columns($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $col = $pdo->query("SHOW COLUMNS FROM subscribers LIKE 'sas_username'")->fetch();
        if (!$col) {
            $pdo->exec(
                'ALTER TABLE subscribers
                 ADD COLUMN sas_username VARCHAR(80) NULL DEFAULT NULL AFTER notes,
                 ADD COLUMN sas_user_id INT UNSIGNED NULL DEFAULT NULL AFTER sas_username'
            );
        }
    } catch (Exception $e) {
        // تجاهل
    }

    try {
        $col = $pdo->query("SHOW COLUMNS FROM service_plans LIKE 'sas_profile_id'")->fetch();
        if (!$col) {
            $pdo->exec(
                'ALTER TABLE service_plans
                 ADD COLUMN sas_profile_id INT UNSIGNED NULL DEFAULT NULL AFTER cost_price'
            );
        }
    } catch (Exception $e) {
        // تجاهل
    }

    $done = true;
}

function sas_config($config)
{
    if (!is_array($config) || !isset($config['sas']) || !is_array($config['sas'])) {
        return array(
            'enabled' => false,
            'host' => '',
            'username' => '',
            'password' => '',
            'parent_id' => 1,
            'default_password' => '',
            'activate_units' => 1,
            'extend_method' => 'reward_points',
            'extend_profile_id' => 0,
            'on_failure' => 'warn',
        );
    }

    $s = $config['sas'];
    return array(
        'enabled' => !empty($s['enabled']),
        'host' => isset($s['host']) ? preg_replace('#^https?://#i', '', rtrim(trim((string) $s['host']), '/')) : '',
        'username' => isset($s['username']) ? trim((string) $s['username']) : '',
        'password' => isset($s['password']) ? (string) $s['password'] : '',
        'parent_id' => isset($s['parent_id']) ? (int) $s['parent_id'] : 1,
        'default_password' => isset($s['default_password']) ? (string) $s['default_password'] : '',
        'activate_units' => max(1, isset($s['activate_units']) ? (int) $s['activate_units'] : 1),
        'extend_method' => (isset($s['extend_method']) && $s['extend_method'] === 'credit') ? 'credit' : 'reward_points',
        'extend_profile_id' => isset($s['extend_profile_id']) ? (int) $s['extend_profile_id'] : 0,
        'on_failure' => (isset($s['on_failure']) && $s['on_failure'] === 'rollback') ? 'rollback' : 'warn',
    );
}

function sas_is_ready($config)
{
    $s = sas_config($config);
    return $s['enabled']
        && $s['host'] !== ''
        && $s['username'] !== ''
        && $s['password'] !== '';
}

function sas_make_connector($config)
{
    if (!class_exists('SASConnector')) {
        return null;
    }
    $s = sas_config($config);
    return new SASConnector($s['host'], $s['username'], $s['password'], 'acp');
}

/**
 * اسم الدخول في SAS — افتراضياً رقم الهاتف بصيغة 07xxxxxxxx
 */
function sas_username_for_subscriber($subscriberRow, $config)
{
    if (!empty($subscriberRow['sas_username'])) {
        return trim((string) $subscriberRow['sas_username']);
    }

    $phone = isset($subscriberRow['phone']) ? format_phone_display($subscriberRow['phone']) : '';
    $phone = preg_replace('/\D+/', '', (string) $phone);
    if ($phone !== '') {
        return $phone;
    }

    return 'sub' . (int) $subscriberRow['id'];
}

function sas_password_for_subscriber($subscriberRow, $config)
{
    $s = sas_config($config);
    if ($s['default_password'] !== '') {
        return $s['default_password'];
    }

    $phone = preg_replace('/\D+/', '', (string) (isset($subscriberRow['phone']) ? $subscriberRow['phone'] : ''));
    if (strlen($phone) >= 6) {
        return substr($phone, -6);
    }

    return '123456';
}

function sas_split_name($fullName)
{
    $fullName = trim((string) $fullName);
    if ($fullName === '') {
        return array('', '');
    }
    $parts = preg_split('/\s+/u', $fullName);
    if (!$parts || count($parts) === 1) {
        return array($fullName, '');
    }
    $first = array_shift($parts);
    return array($first, trim(implode(' ', $parts)));
}

function sas_response_is_error($res)
{
    return !is_array($res) || isset($res['__http_error']) || isset($res['__exception'])
        || isset($res['__auth_error']) || isset($res['__curl_error']) || isset($res['__decrypt_error']);
}

function sas_response_message($res)
{
    if (!is_array($res)) {
        return 'استجابة SAS غير متوقعة';
    }
    if (isset($res['message']) && $res['message'] !== '') {
        return (string) $res['message'];
    }
    if (isset($res['status']) && is_numeric($res['status']) && (int) $res['status'] !== 200) {
        return 'status=' . (int) $res['status'];
    }
    if (isset($res['error']) && $res['error'] !== '') {
        return is_string($res['error']) ? $res['error'] : json_encode($res['error']);
    }
    if (isset($res['body']) && is_string($res['body']) && $res['body'] !== '') {
        return substr($res['body'], 0, 200);
    }
    return 'خطأ SAS غير معروف';
}

function sas_response_success($res)
{
    if (sas_response_is_error($res)) {
        return false;
    }
    if (isset($res['success']) && ($res['success'] === false || $res['success'] === 0 || $res['success'] === '0')) {
        return false;
    }
    if (isset($res['status'])) {
        $st = $res['status'];
        if (is_numeric($st)) {
            $n = (int) $st;
            if ($n >= 100 && $n !== 200) {
                return false;
            }
        } elseif (in_array(strtolower((string) $st), array('error', 'fail', 'failed'), true)) {
            return false;
        }
    }
    return true;
}

function sas_message_means_username_taken($msg)
{
    $m = strtolower((string) $msg);
    if ($m === '') {
        return false;
    }
    $needles = array(
        'exist',
        'duplicate',
        'taken',
        'already',
        'unique',
        'موجود',
        'مأخوذ',
        'مكرر',
    );
    foreach ($needles as $n) {
        if (strpos($m, $n) !== false) {
            return true;
        }
    }
    return false;
}

function sas_save_subscriber_link($pdo, $subscriberRow, $username, $existing, $activateRes = null)
{
    $sasUserId = sas_extract_user_id($existing);
    if ($sasUserId <= 0) {
        $sasUserId = sas_extract_user_id($activateRes);
    }

    $pdo->prepare(
        'UPDATE subscribers SET sas_username = :u, sas_user_id = :sid WHERE id = :id'
    )->execute(array(
        ':u' => $username,
        ':sid' => $sasUserId > 0 ? $sasUserId : null,
        ':id' => (int) $subscriberRow['id'],
    ));
}

/**
 * إنشاء مستخدم SAS إن لم يكن موجوداً
 * يرجع array($ok, $message, $existing, $username)
 */
function sas_ensure_user($api, $config, $subscriberRow, $profileId)
{
    $s = sas_config($config);
    $username = sas_username_for_subscriber($subscriberRow, $config);
    $password = sas_password_for_subscriber($subscriberRow, $config);
    list($first, $last) = sas_split_name(isset($subscriberRow['name']) ? $subscriberRow['name'] : '');

    $existing = $api->findUserByUsername($username);
    if ($existing) {
        return array(true, '', $existing, $username);
    }

    if ($profileId <= 0) {
        return array(false, 'SAS: اربط بروفايل الباقة من الإعدادات أولاً', null, $username);
    }

    $createPayload = array(
        'username' => $username,
        'password' => $password,
        'confirm_password' => $password,
        'profile_id' => $profileId,
        'parent_id' => $s['parent_id'],
        'firstname' => $first,
        'lastname' => $last,
        'phone' => format_phone_display(isset($subscriberRow['phone']) ? $subscriberRow['phone'] : ''),
        'enabled' => 1,
    );

    $createRes = $api->createUser($createPayload);
    if (!sas_response_success($createRes)) {
        $msg = sas_response_message($createRes);
        if (!sas_message_means_username_taken($msg)) {
            return array(false, 'SAS: فشل إنشاء المستخدم — ' . $msg, null, $username);
        }
        $existing = $api->findUserByUsername($username);
        if (!$existing) {
            $existing = array('username' => $username);
        }
    } else {
        $existing = $api->findUserByUsername($username);
        if (!$existing && isset($createRes['id'])) {
            $existing = array('id' => $createRes['id'], 'username' => $username);
        }
        if (!$existing) {
            $existing = array('username' => $username);
        }
    }

    return array(true, '', $existing, $username);
}

/**
 * تفعيل/تجديد المشترك على SAS عند التفعيل المحلي
 * يرجع array($ok, $message)
 */
function sas_sync_on_activate($pdo, $config, $subscriberRow, $plan, $opts = array())
{
    if (!empty($opts['skip_sas'])) {
        return array(true, '');
    }
    if (!sas_is_ready($config)) {
        return array(true, '');
    }

    ensure_sas_columns($pdo);

    $profileId = isset($plan['sas_profile_id']) ? (int) $plan['sas_profile_id'] : 0;
    if ($profileId <= 0) {
        return array(true, '');
    }

    $s = sas_config($config);
    $units = isset($opts['sas_units']) ? max(1, (int) $opts['sas_units']) : $s['activate_units'];

    if (!class_exists('SASConnector')) {
        return array(false, 'SAS: ملف الاتصال غير موجود');
    }

    try {
        $api = sas_make_connector($config);
        if (!$api || !$api->login()) {
            return array(false, 'SAS: فشل تسجيل الدخول — تحقق من إعدادات SAS');
        }

        list($okUser, $userMsg, $existing, $username) = sas_ensure_user($api, $config, $subscriberRow, $profileId);
        if (!$okUser) {
            return array(false, $userMsg);
        }

        $activateRes = $api->activateUserCredit($username, $profileId, $units);
        if (!sas_response_success($activateRes)) {
            return array(false, 'SAS: فشل التفعيل — ' . sas_response_message($activateRes));
        }

        sas_save_subscriber_link($pdo, $subscriberRow, $username, $existing, $activateRes);

        $graceNote = '';
        $graceDays = function_exists('subscriber_grace_days')
            ? subscriber_grace_days($subscriberRow, $config)
            : 0;
        if ($graceDays > 0 && function_exists('sas_extend_days')) {
            list($gOk, $gMsg) = sas_extend_days($pdo, $config, $username, $graceDays, false);
            if ($gMsg !== '') {
                $graceNote = ' — ' . $gMsg;
            }
        }

        if (function_exists('activity_log')) {
            activity_log(
                $pdo,
                (int) $subscriberRow['id'],
                'subscriber',
                (int) $subscriberRow['id'],
                'sas_activate',
                'تفعيل SAS — ' . $username,
                'profile_id=' . $profileId . ' units=' . $units
            );
        }

        return array(true, 'SAS: تم التفعيل (' . $username . ')' . $graceNote);
    } catch (Exception $e) {
        return array(false, 'SAS: ' . $e->getMessage());
    }
}

/**
 * تست 24 ساعة على SAS (Activate Test Account)
 * يرجع array($ok, $message)
 */
function sas_sync_on_test($pdo, $config, $subscriberRow, $plan)
{
    if (!sas_is_ready($config)) {
        return array(false, 'فعّل ربط SAS من الإعدادات أولاً');
    }

    ensure_sas_columns($pdo);

    $profileId = isset($plan['sas_profile_id']) ? (int) $plan['sas_profile_id'] : 0;
    if ($profileId <= 0) {
        return array(false, 'اربط بروفايل SAS لهذه الباقة من الإعدادات أو الباقات');
    }

    if (!class_exists('SASConnector')) {
        return array(false, 'SAS: ملف الاتصال غير موجود');
    }

    try {
        $api = sas_make_connector($config);
        if (!$api || !$api->login()) {
            return array(false, 'SAS: فشل تسجيل الدخول — تحقق من إعدادات SAS');
        }

        list($okUser, $userMsg, $existing, $username) = sas_ensure_user($api, $config, $subscriberRow, $profileId);
        if (!$okUser) {
            return array(false, $userMsg);
        }

        $userId = sas_extract_user_id($existing);
        if ($userId <= 0 && !empty($subscriberRow['sas_user_id'])) {
            $userId = (int) $subscriberRow['sas_user_id'];
        }
        if ($userId <= 0) {
            $fetched = $api->findUserByUsername($username);
            $userId = sas_extract_user_id($fetched);
            if ($fetched) {
                $existing = $fetched;
            }
        }
        if ($userId <= 0) {
            return array(false, 'SAS: ماكو رقم مستخدم (user_id) — ما يقدر يتمدّد');
        }

        list($okExt, $msgExt) = sas_extend_one_day($pdo, $config, $username);
        if (!$okExt) {
            return array(false, $msgExt);
        }
        sas_save_subscriber_link($pdo, $subscriberRow, $username, $existing, array());
        if (function_exists('activity_log')) {
            activity_log(
                $pdo,
                (int) $subscriberRow['id'],
                'subscriber',
                (int) $subscriberRow['id'],
                'sas_test',
                'تست SAS +1 يوم — ' . $username,
                $msgExt
            );
        }
        return array(true, $msgExt);
    } catch (Exception $e) {
        return array(false, 'SAS: ' . $e->getMessage());
    }
}

function sas_row_id($row)
{
    if (!is_array($row)) {
        return '';
    }
    if (isset($row['id']) && $row['id'] !== '') {
        return (string) $row['id'];
    }
    if (isset($row['profile_id']) && $row['profile_id'] !== '') {
        return (string) $row['profile_id'];
    }
    return '';
}

function sas_extract_user_id($row)
{
    if (!is_array($row)) {
        return 0;
    }
    foreach (array('id', 'user_id', 'userid', 'userId', 'DT_RowId') as $k) {
        if (!isset($row[$k]) || $row[$k] === '' || $row[$k] === null) {
            continue;
        }
        $v = $row[$k];
        if (is_numeric($v) && (int) $v > 0) {
            return (int) $v;
        }
        $d = preg_replace('/\D+/', '', (string) $v);
        if ($d !== '' && ctype_digit($d) && (int) $d > 0) {
            return (int) $d;
        }
    }
    if (isset($row['user']) && is_array($row['user'])) {
        return sas_extract_user_id($row['user']);
    }
    return 0;
}

function sas_profile_is_extension($row)
{
    if (!is_array($row)) {
        return false;
    }
    foreach (array('type', 'profile_type', 'service_type', 'profileType') as $k) {
        if (!isset($row[$k])) {
            continue;
        }
        $t = strtolower((string) $row[$k]);
        if ($t === 'extension' || $t === 'extend' || $t === '4') {
            return true;
        }
    }
    $name = strtolower(sas_row_name($row));
    if (strpos($name, 'extend') !== false || strpos($name, 'extension') !== false
        || strpos($name, 'تمديد') !== false) {
        return true;
    }
    return false;
}

function sas_pick_extend_profile($allowed, $configured, $allProfiles = array(), $excludeId = 0)
{
    $configured = (int) $configured;
    $excludeId = (int) $excludeId;
    if ($configured > 0 && $configured !== $excludeId) {
        return $configured;
    }

    $lists = array();
    if (is_array($allowed)) {
        $lists[] = $allowed;
    }
    if (is_array($allProfiles)) {
        $extOnly = array();
        foreach ($allProfiles as $p) {
            if (sas_profile_is_extension($p)) {
                $extOnly[] = $p;
            }
        }
        if ($extOnly) {
            $lists[] = $extOnly;
        }
        $lists[] = $allProfiles;
    }

    $bestId = 0;
    $bestScore = -1;
    foreach ($lists as $list) {
        foreach ($list as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = (int) sas_row_id($p);
            if ($id <= 0 || $id === $excludeId) {
                continue;
            }
            $name = strtolower(sas_row_name($p));
            $amt = 0;
            if (isset($p['expiration_amount']) && is_numeric($p['expiration_amount'])) {
                $amt = (int) $p['expiration_amount'];
            } elseif (isset($p['expiration']) && is_numeric($p['expiration'])) {
                $amt = (int) $p['expiration'];
            }
            $unit = isset($p['expiration_unit']) ? $p['expiration_unit'] : (isset($p['expire_unit']) ? $p['expire_unit'] : '');
            $unitS = is_numeric($unit) ? (int) $unit : strtolower((string) $unit);
            $score = 0;
            if ($name === 'test' || $name === 'تست') {
                $score = 100;
            } elseif (strpos($name, 'test') !== false || strpos($name, 'تست') !== false || strpos($name, 'trial') !== false) {
                $score = 90;
            } elseif (strpos($name, '1') !== false && (strpos($name, 'day') !== false || strpos($name, 'يوم') !== false)) {
                $score = 88;
            } elseif (strpos($name, '24') !== false) {
                $score = 80;
            } elseif (($unitS === 2 || $unitS === 'days' || $unitS === 'day' || $unitS === 'd') && $amt === 1) {
                $score = 85;
            } elseif (($unitS === 1 || $unitS === 'hours' || $unitS === 'hour' || $unitS === 'h') && $amt === 24) {
                $score = 82;
            } elseif (sas_profile_is_extension($p)) {
                $score = 50;
            } else {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $id;
            }
        }
        if ($bestId > 0 && $bestScore >= 80) {
            return $bestId;
        }
    }

    return $bestId;
}

function sas_user_expire_ts($row)
{
    if (!is_array($row)) {
        return 0;
    }
    $at = function_exists('sas_cache_expire_at') ? sas_cache_expire_at($row) : '';
    if ($at) {
        $t = strtotime($at);
        return $t ? $t : 0;
    }
    return 0;
}

/**
 * إضافة يوم تست على الساس مباشرة (بروفايل Test + نقاط تشجيعية) بدون شاشة SAS
 */
function sas_extend_days($pdo, $config, $username, $days, $sendWa = false)
{
    $days = (int) $days;
    if ($days <= 0) {
        return array(true, '');
    }
    $okN = 0;
    $lastFail = '';
    for ($i = 0; $i < $days; $i++) {
        list($ok, $msg) = sas_extend_one_day($pdo, $config, $username, $sendWa && ($i === $days - 1));
        if (!$ok) {
            $lastFail = $msg;
            break;
        }
        $okN++;
    }
    if ($okN <= 0) {
        return array(false, $lastFail !== '' ? $lastFail : 'تعذر إضافة أيام السماح');
    }
    $out = 'تم إضافة ' . $okN . ' يوم سماح';
    if ($okN < $days && $lastFail !== '') {
        $out .= ' — الباقي فشل: ' . $lastFail;
    }
    return array(true, $out);
}

function sas_extend_one_day($pdo, $config, $username, $sendWa = true)
{
    $username = trim((string) $username);
    if ($username === '') {
        return array(false, 'ماكو يوزرنيم');
    }
    if (!sas_is_ready($config) || !class_exists('SASConnector')) {
        return array(false, 'فعّل ربط SAS من الإعدادات أولاً');
    }
    try {
        $api = sas_make_connector($config);
        if (!$api || !$api->login()) {
            return array(false, 'SAS: فشل تسجيل الدخول');
        }
        $cache = function_exists('sas_cache_get') ? sas_cache_get($pdo, $username) : null;
        $userId = ($cache && !empty($cache['sas_user_id'])) ? (int) $cache['sas_user_id'] : 0;
        $found = $api->findUserByUsername($username);
        if (is_array($found)) {
            if ($userId <= 0) {
                $userId = sas_extract_user_id($found);
            }
        }
        if ($userId <= 0) {
            return array(false, 'SAS: ماكو رقم مستخدم لهذا اليوزرنيم');
        }
        $full = $api->getUserById($userId);
        if (!is_array($full) || (function_exists('sas_response_is_error') && sas_response_is_error($full))) {
            $full = is_array($found) ? $found : array();
        }
        if (isset($full['data']) && is_array($full['data']) && !isset($full['username'])) {
            $full = $full['data'];
        }
        $beforeTs = sas_user_expire_ts($full);
        $userProfileId = 0;
        if (!empty($full['profile_id'])) {
            $userProfileId = (int) $full['profile_id'];
        } elseif ($cache && !empty($cache['profile_id'])) {
            $userProfileId = (int) $cache['profile_id'];
        }
        $s = sas_config($config);
        $allowed = ($userProfileId > 0) ? $api->getAllowedExtensions($userProfileId) : array();
        $extData = $api->getExtensionData($userId);
        if (is_array($extData)) {
            foreach (array('allowedExtensions', 'extensions', 'profiles', 'data') as $ek) {
                if (isset($extData[$ek]) && is_array($extData[$ek]) && isset($extData[$ek][0])) {
                    $allowed = $extData[$ek];
                    break;
                }
            }
        }
        $extendProfile = sas_pick_extend_profile($allowed, $s['extend_profile_id'], $api->getProfiles(), $userProfileId);
        if ($extendProfile <= 0) {
            return array(false, 'SAS: ماكو بروفايل تست/تمديد يوم واحد مرتبط بهذه الباقة');
        }
        list($ptsOk, $ptsVal) = function_exists('sas_manager_reward_points')
            ? sas_manager_reward_points($config, $pdo)
            : array(false, null, '');
        if ($ptsOk && $ptsVal !== null && (float) $ptsVal < 1) {
            return array(false, 'النقاط التشجيعية غير كافية (المتوفر: ' . $ptsVal . ')');
        }
        $testRes = method_exists($api, 'extendUserService')
            ? $api->extendUserService($userId, $extendProfile, 'reward_points')
            : array();
        $after = $api->getUserById($userId);
        if (isset($after['data']) && is_array($after['data']) && !isset($after['username'])) {
            $after = $after['data'];
        }
        $afterTs = sas_user_expire_ts($after);
        if ($afterTs <= $beforeTs && method_exists($api, 'findUserByUsername')) {
            $foundAfter = $api->findUserByUsername($username);
            $foundTs = sas_user_expire_ts($foundAfter);
            if ($foundTs > $afterTs) {
                $after = $foundAfter;
                $afterTs = $foundTs;
            }
        }
        if ($afterTs <= $beforeTs) {
            $hint = function_exists('sas_response_message') ? sas_response_message(is_array($testRes) ? $testRes : array()) : '';
            $st = (is_array($testRes) && isset($testRes['status'])) ? (int) $testRes['status'] : 0;
            $low = strtolower($hint);
            if ($ptsOk && $ptsVal !== null && (float) $ptsVal < 1) {
                return array(false, 'النقاط التشجيعية غير كافية (المتوفر: ' . $ptsVal . ')');
            }
            if ($st === 405 || strpos($hint, '405') !== false
                || strpos($low, 'point') !== false || strpos($low, 'reward') !== false
                || strpos($hint, 'نقاط') !== false || strpos($low, 'insufficient') !== false
                || strpos($low, 'not enough') !== false) {
                $have = ($ptsOk && $ptsVal !== null) ? (' (المتوفر: ' . $ptsVal . ')') : '';
                return array(false, 'النقاط التشجيعية غير كافية أو التمديد غير مسموح' . $have);
            }
            return array(false, 'SAS: التمديد ما تغيّر تاريخ الانتهاء' . ($hint !== '' ? (' — ' . $hint) : ''));
        }
        if (function_exists('sas_cache_upsert_row') && is_array($after) && !empty($after)) {
            if (empty($after['username'])) {
                $after['username'] = $username;
            }
            sas_cache_upsert_row($pdo, $after);
        }
        if (function_exists('sas_cache_patch')) {
            sas_cache_patch($pdo, $username, array('is_online' => 0));
        }
        unset($_SESSION['sas_points_val']);
        unset($_SESSION['sas_points_at']);
        $okMsg = 'تم إضافة +1 يوم للمشترك (' . $username . ')';
        if ($sendWa && function_exists('sas_notify_plus_day_whatsapp')) {
            $waErr = sas_notify_plus_day_whatsapp($pdo, $config, $username, $afterTs);
            if ($waErr === '') {
                $okMsg .= ' — تم إرسال إشعار واتساب';
            } else {
                $okMsg .= ' — واتساب: ' . $waErr;
            }
        }
        return array(true, $okMsg);
    } catch (Exception $e) {
        return array(false, 'SAS: ' . $e->getMessage());
    }
}

function sas_row_name($row)
{
    if (!is_array($row)) {
        return '';
    }
    foreach (array('name', 'profile_name', 'username', 'title') as $k) {
        if (!empty($row[$k])) {
            return (string) $row[$k];
        }
    }
    return '';
}

function sas_test_connection($config)
{
    $out = array(
        'ok' => false,
        'message' => '',
        'profiles' => array(),
        'managers' => array(),
    );

    if (!function_exists('sas_is_ready') || !sas_is_ready($config)) {
        $out['message'] = 'فعّل SAS واحفظ host + username + password أولاً';
        return $out;
    }

    if (!class_exists('SASConnector')) {
        $out['message'] = 'مكتبة SAS غير موجودة على السيرفر';
        return $out;
    }

    try {
        $api = sas_make_connector($config);
        if (!$api) {
            $out['message'] = 'تعذر إنشاء اتصال SAS';
            return $out;
        }
        if (!$api->login()) {
            $err = $api->getLastError();
            $out['message'] = $err !== '' ? $err : 'فشل تسجيل الدخول';
            $dbg = $api->getLastDebug();
            if (!empty($dbg['body_snippet'])) {
                $out['message'] .= ' — ' . $dbg['body_snippet'];
            }
            return $out;
        }

        $profiles = $api->getProfiles();
        $managers = $api->getManagers();
        if (sas_response_is_error($profiles)) {
            $out['message'] = 'دخول OK — فشل جلب البروفايلات: ' . sas_response_message($profiles);
            return $out;
        }

        $out['ok'] = true;
        $out['profiles'] = is_array($profiles) ? $profiles : array();
        $out['managers'] = (is_array($managers) && !sas_response_is_error($managers)) ? $managers : array();
        $out['message'] = 'الاتصال ناجح — ' . count($out['profiles']) . ' بروفايل';
        list($ptsOk, $ptsVal) = sas_manager_reward_points($config);
        $out['reward_points'] = $ptsOk ? $ptsVal : null;
        if ($ptsOk) {
            $out['message'] .= ' — Reward Points: ' . $ptsVal;
        }
        return $out;
    } catch (Exception $e) {
        $out['message'] = $e->getMessage();
        return $out;
    }
}

function sas_reward_points_number($v)
{
    if ($v === '' || $v === null || is_array($v)) {
        return null;
    }
    if (is_string($v)) {
        $v = str_replace(array(',', ' '), '', trim($v));
    }
    if (!is_numeric($v)) {
        return null;
    }
    return (float) $v;
}

function sas_row_reward_points($row)
{
    return sas_find_reward_points($row);
}

function sas_find_reward_points($node, $depth = 0, $wantUser = '', $wantId = 0)
{
    if ($depth > 6 || !is_array($node)) {
        return null;
    }
    if (isset($node['__http_error']) || isset($node['__auth_error']) || isset($node['__curl_error'])) {
        return null;
    }

    $wantUser = strtolower(trim((string) $wantUser));
    $wantId = (int) $wantId;
    $u = isset($node['username']) ? strtolower(trim((string) $node['username'])) : '';
    $id = (isset($node['id']) && is_numeric($node['id'])) ? (int) $node['id'] : 0;
    if ($u !== '' && $wantUser !== '' && $u !== $wantUser) {
        return null;
    }
    if ($id > 0 && $wantId > 0 && $u === '' && $id !== $wantId
        && (isset($node['username']) || isset($node['parent_id']) || isset($node['enabled']))) {
        return null;
    }

    foreach (array(
        'reward_points',
        'rewardPoints',
        'RewardPoints',
        'reward_point',
        'reward_points_balance',
        'available_reward_points',
    ) as $k) {
        if (!array_key_exists($k, $node)) {
            continue;
        }
        $num = sas_reward_points_number($node[$k]);
        if ($num !== null) {
            return $num;
        }
    }

    foreach ($node as $k => $v) {
        if (is_array($v) || $v === '' || $v === null) {
            continue;
        }
        $lk = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $k));
        if ($lk === '' || strpos($lk, 'rewardpoint') === false) {
            continue;
        }
        if (strpos($lk, 'awarded') !== false || strpos($lk, 'max') !== false || strpos($lk, 'limit') !== false) {
            continue;
        }
        $num = sas_reward_points_number($v);
        if ($num !== null) {
            return $num;
        }
    }

    foreach ($node as $k => $v) {
        if (!is_array($v)) {
            continue;
        }
        $lk = strtolower((string) $k);
        if ($lk === 'token' || $lk === 'payload' || $lk === 'profiles' || $lk === 'users'
            || $lk === 'parent' || $lk === 'children' || $lk === 'managers' || $lk === 'cards'
            || $lk === 'traffic' || $lk === 'permissions' || $lk === 'group') {
            continue;
        }
        $found = sas_find_reward_points($v, $depth + 1, $wantUser, $wantId);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

function sas_find_manager_balance($node, $depth = 0)
{
    if ($depth > 6 || !is_array($node)) {
        return null;
    }
    if (isset($node['__http_error']) || isset($node['__auth_error']) || isset($node['__curl_error'])) {
        return null;
    }
    foreach (array(
        'balance', 'Balance', 'available_balance', 'availableBalance',
        'credit', 'Credit', 'manager_balance', 'managerBalance',
        'credit_balance', 'creditBalance', 'wallet', 'money',
        'available_credit', 'availableCredit', 'manager_credit', 'managerCredit',
    ) as $k) {
        if (!array_key_exists($k, $node) || is_array($node[$k])) {
            continue;
        }
        $lk = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $k));
        if (strpos($lk, 'reward') !== false || strpos($lk, 'point') !== false) {
            continue;
        }
        $num = function_exists('sas_reward_points_number')
            ? sas_reward_points_number($node[$k])
            : (is_numeric($node[$k]) ? (float) $node[$k] : null);
        if ($num !== null) {
            return $num;
        }
    }
    foreach ($node as $k => $v) {
        if (is_array($v) || $v === '' || $v === null) {
            continue;
        }
        $lk = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $k));
        if ($lk === '' || (strpos($lk, 'balance') === false && $lk !== 'credit' && $lk !== 'wallet' && $lk !== 'money')) {
            continue;
        }
        if (strpos($lk, 'reward') !== false || strpos($lk, 'point') !== false) {
            continue;
        }
        $num = function_exists('sas_reward_points_number')
            ? sas_reward_points_number($v)
            : (is_numeric($v) ? (float) $v : null);
        if ($num !== null) {
            return $num;
        }
    }
    foreach ($node as $k => $v) {
        if (!is_array($v)) {
            continue;
        }
        $lk = strtolower((string) $k);
        if ($lk === 'token' || $lk === 'payload' || $lk === 'profiles' || $lk === 'users'
            || $lk === 'parent' || $lk === 'children' || $lk === 'cards'
            || $lk === 'traffic' || $lk === 'permissions' || $lk === 'group') {
            continue;
        }
        $found = sas_find_manager_balance($v, $depth + 1);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

/**
 * رصيد النقاط التشجيعية للمدير الحالي في SAS
 * يرجع array($ok, $points, $message)
 */
function sas_manager_reward_points($config, $pdo = null)
{
    $cachedAt = isset($_SESSION['sas_rp_at']) ? (int) $_SESSION['sas_rp_at'] : 0;
    $haveBal = array_key_exists('sas_balance_disp', $_SESSION);
    if ($cachedAt > 0 && (time() - $cachedAt) < 15 && array_key_exists('sas_rp_val', $_SESSION)
        && $_SESSION['sas_rp_val'] !== null && $haveBal) {
        return array(true, $_SESSION['sas_rp_val'], '');
    }

    if (!sas_is_ready($config) || !class_exists('SASConnector')) {
        return array(false, null, '');
    }

    try {
        $api = sas_make_connector($config);
        if (!$api) {
            return array(false, null, '');
        }
        $api->setTimeout(12);
        if (!$api->login()) {
            return array(false, null, $api->getLastError());
        }

        $s = sas_config($config);
        $want = strtolower(trim((string) $s['username']));
        $wantId = 0;
        $login = method_exists($api, 'getLoginUser') ? $api->getLoginUser() : null;
        if (is_array($login)) {
            if (isset($login['user']) && is_array($login['user'])) {
                $login = $login['user'];
            } elseif (isset($login['manager']) && is_array($login['manager'])) {
                $login = $login['manager'];
            }
            if ($want === '' && !empty($login['username'])) {
                $want = strtolower(trim((string) $login['username']));
            }
            if (isset($login['id']) && is_numeric($login['id'])) {
                $wantId = (int) $login['id'];
            }
        }

        $points = null;
        $balance = null;
        if (method_exists($api, 'getFinanceDashboard')) {
            $fin = $api->getFinanceDashboard();
            if (is_array($fin)) {
                $points = sas_find_reward_points($fin);
                $balance = function_exists('sas_find_manager_balance') ? sas_find_manager_balance($fin) : null;
            }
        }
        $rows = array();
        if (method_exists($api, 'getCurrentManagerLive')) {
            $rows = $api->getCurrentManagerLive();
        }
        if (!is_array($rows)) {
            $rows = array();
        }

        if ($points === null) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $got = sas_find_reward_points($row, 0, $want, $wantId);
                if ($got === null) {
                    continue;
                }
                $points = $got;
                $bal = function_exists('sas_find_manager_balance') ? sas_find_manager_balance($row) : null;
                if ($bal !== null) {
                    $balance = $bal;
                }
                break;
            }
        }

        if ($points === null && is_array($login)) {
            $points = sas_find_reward_points($login, 0, $want, $wantId);
        }

        if ($balance === null && $rows) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $bal = function_exists('sas_find_manager_balance') ? sas_find_manager_balance($row) : null;
                if ($bal !== null) {
                    $balance = $bal;
                    break;
                }
            }
        }
        if ($balance === null && is_array($login)) {
            $balance = function_exists('sas_find_manager_balance') ? sas_find_manager_balance($login) : null;
        }

        if ($balance !== null) {
            $disp = ((float) $balance == (int) $balance)
                ? number_format((int) $balance)
                : number_format((float) $balance, 2);
            $_SESSION['sas_balance_disp'] = $disp;
        } elseif ($points !== null) {
            $_SESSION['sas_balance_disp'] = '0';
        }

        if ($points !== null) {
            $_SESSION['sas_rp_val'] = $points;
            $_SESSION['sas_rp_at'] = time();
            $_SESSION['sas_points_val'] = $points;
            $_SESSION['sas_points_at'] = time();
            return array(true, $points, '');
        }

        return array(false, null, 'ماكو حقل Reward Points في رد SAS');
    } catch (Exception $e) {
        return array(false, null, $e->getMessage());
    }
}

}
