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
        if (is_numeric($st) && (int) $st !== 200) {
            return false;
        }
        if (in_array(strtolower((string) $st), array('error', 'fail', 'failed'), true)) {
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

        return array(true, 'SAS: تم التفعيل (' . $username . ')');
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

        $s = sas_config($config);
        $method = $s['extend_method'];
        $userProfileId = 0;
        if (is_array($existing)) {
            if (!empty($existing['profile_id'])) {
                $userProfileId = (int) $existing['profile_id'];
            } elseif (!empty($existing['profileId'])) {
                $userProfileId = (int) $existing['profileId'];
            }
        }
        if ($userProfileId <= 0) {
            $full = $api->getUserById($userId);
            if (is_array($full)) {
                if (!empty($full['profile_id'])) {
                    $userProfileId = (int) $full['profile_id'];
                } elseif (isset($full['profile']) && is_array($full['profile']) && !empty($full['profile']['id'])) {
                    $userProfileId = (int) $full['profile']['id'];
                }
            }
        }
        if ($userProfileId <= 0) {
            $userProfileId = $profileId;
        }

        $allowed = $api->getAllowedExtensions($userProfileId);
        $extData = $api->getExtensionData($userId);
        if (is_array($extData)) {
            foreach (array('allowedExtensions', 'extensions', 'profiles', 'data') as $ek) {
                if (isset($extData[$ek]) && is_array($extData[$ek]) && isset($extData[$ek][0])) {
                    $allowed = $extData[$ek];
                    break;
                }
            }
        }
        $extendProfile = sas_pick_extend_profile($allowed, $s['extend_profile_id'], $api->getProfiles());
        if ($extendProfile <= 0) {
            return array(false, 'SAS: ماكو بروفايل تمديد (Extension) مرتبط بهذه الباقة. من SAS أنشئ بروفايل نوعه Extension (مثلاً 24 ساعة) واربطه، أو حط رقمه في إعدادات «بروفايل التمديد». كود -12 = بروفايل غير صالح للتمديد.');
        }

        $testRes = $api->extendUserService($userId, $extendProfile, $method);
        if (!sas_response_success($testRes)) {
            $msg = sas_response_message($testRes);
            if (strpos($msg, 'invalid_profile') !== false || strpos($msg, '-12') !== false) {
                $msg .= ' — كود SAS -12 يعني البروفايل مو صالح للتمديد (لازم Extension مو باقة شهرية)';
            }
            return array(false, 'SAS: فشل التست — ' . $msg);
        }

        sas_save_subscriber_link($pdo, $subscriberRow, $username, $existing, $testRes);

        $methodLabel = ($method === 'credit') ? 'رصيد' : 'نقاط تشجيعية';
        if (function_exists('activity_log')) {
            activity_log(
                $pdo,
                (int) $subscriberRow['id'],
                'subscriber',
                (int) $subscriberRow['id'],
                'sas_test',
                'تست SAS 24 ساعة — ' . $username,
                'تمديد عبر ' . $methodLabel . ' extend_profile_id=' . $extendProfile
            );
        }

        return array(true, 'SAS: تم التست عبر ' . $methodLabel . ' (' . $username . ')');
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

function sas_pick_extend_profile($allowed, $configured, $allProfiles = array())
{
    $configured = (int) $configured;
    if ($configured > 0) {
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
    }

    $first = 0;
    foreach ($lists as $list) {
        foreach ($list as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = (int) sas_row_id($p);
            if ($id <= 0) {
                continue;
            }
            if ($first <= 0) {
                $first = $id;
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
            if (strpos($name, '24') !== false || strpos($name, 'test') !== false
                || strpos($name, 'تست') !== false || strpos($name, 'trial') !== false) {
                return $id;
            }
            if (($unitS === 1 || $unitS === 'hours' || $unitS === 'hour' || $unitS === 'h') && $amt === 24) {
                return $id;
            }
            if (($unitS === 2 || $unitS === 'days' || $unitS === 'day' || $unitS === 'd') && $amt === 1) {
                return $id;
            }
        }
        if ($first > 0) {
            return $first;
        }
    }

    return 0;
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

function sas_row_reward_points($row)
{
    return sas_find_reward_points($row);
}

function sas_find_reward_points($node, $depth = 0)
{
    if ($depth > 7 || !is_array($node)) {
        return null;
    }
    if (isset($node['__http_error']) || isset($node['__auth_error']) || isset($node['__curl_error'])) {
        return null;
    }

    foreach ($node as $k => $v) {
        if (!is_numeric($v) || $v === '') {
            continue;
        }
        $lk = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $k));
        if ($lk === '') {
            continue;
        }
        if (strpos($lk, 'awarded') !== false) {
            continue;
        }
        if (strpos($lk, 'rewardpoint') !== false || $lk === 'rewardpoints' || $lk === 'rewardpointsbalance') {
            return (float) $v;
        }
    }

    foreach ($node as $k => $v) {
        if (!is_array($v)) {
            continue;
        }
        $lk = strtolower((string) $k);
        if ($lk === 'token' || $lk === 'payload' || $lk === 'profiles') {
            continue;
        }
        $found = sas_find_reward_points($v, $depth + 1);
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
    $cachedAt = isset($_SESSION['sas_points_at']) ? (int) $_SESSION['sas_points_at'] : 0;
    if ($cachedAt > 0 && (time() - $cachedAt) < 45 && array_key_exists('sas_points_val', $_SESSION)
        && $_SESSION['sas_points_val'] !== null) {
        return array(true, $_SESSION['sas_points_val'], '');
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

        $check = function ($row) {
            return sas_find_reward_points($row);
        };

        $points = $check($api->getLoginUser());
        if ($points === null) {
            $points = $check($api->getJwtPayload());
        }

        $login = $api->getLoginUser();
        $mid = sas_extract_user_id($login);
        if ($mid <= 0) {
            $mid = sas_extract_user_id($api->getJwtPayload());
        }
        if ($points === null && $mid > 0) {
            $points = $check($api->getManagerById($mid));
        }
        if ($points === null) {
            $points = $check($api->getDashboardManager());
        }

        if ($points === null) {
            $s = sas_config($config);
            $managers = $api->getManagers();
            if (is_array($managers) && !sas_response_is_error($managers)) {
                if (!isset($managers[0]) && isset($managers['data']) && is_array($managers['data'])) {
                    $managers = $managers['data'];
                }
                $want = strtolower($s['username']);
                foreach ($managers as $mgr) {
                    if (!is_array($mgr)) {
                        continue;
                    }
                    $points = $check($mgr);
                    if ($points !== null) {
                        break;
                    }
                    $u = isset($mgr['username']) ? strtolower((string) $mgr['username']) : '';
                    if ($u === $want) {
                        $mid2 = sas_extract_user_id($mgr);
                        if ($mid2 > 0) {
                            $points = $check($api->getManagerById($mid2));
                            if ($points !== null) {
                                break;
                            }
                        }
                    }
                }
            }
        }

        if ($points === null) {
            $sampleUserId = 0;
            if ($pdo) {
                try {
                    $sampleUserId = (int) $pdo->query(
                        'SELECT sas_user_id FROM subscribers WHERE sas_user_id IS NOT NULL AND sas_user_id > 0 LIMIT 1'
                    )->fetchColumn();
                } catch (Exception $e) {
                    $sampleUserId = 0;
                }
            }
            if ($sampleUserId <= 0) {
                $sampleUserId = sas_extract_user_id($api->getFirstUser());
            }
            if ($sampleUserId > 0) {
                $points = $check($api->getActivationData($sampleUserId));
            }
        }

        if ($points !== null) {
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
