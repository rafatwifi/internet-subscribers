<?php

/**
 * كاش مشتركين SAS — القراءة السريعة من النظام، والتحديث من الساس
 * لا يمسح ولا يعدّل ديون المشتركين المحليين (بيانات أوف لاين)
 */

if (!function_exists('ensure_sas_users_cache_table')) {

function ensure_sas_users_cache_table($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sas_users_cache (
            username VARCHAR(80) NOT NULL,
            sas_user_id INT UNSIGNED NULL DEFAULT NULL,
            firstname VARCHAR(150) NULL DEFAULT NULL,
            lastname VARCHAR(150) NULL DEFAULT NULL,
            display_name VARCHAR(200) NOT NULL,
            phone VARCHAR(40) NULL DEFAULT NULL,
            profile_id INT UNSIGNED NULL DEFAULT NULL,
            profile_name VARCHAR(150) NULL DEFAULT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            expire_at DATETIME NULL DEFAULT NULL,
            local_subscriber_id INT UNSIGNED NULL DEFAULT NULL,
            synced_at DATETIME NOT NULL,
            PRIMARY KEY (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sas_sync_meta (
            id TINYINT UNSIGNED NOT NULL,
            last_ok_at DATETIME NULL DEFAULT NULL,
            last_try_at DATETIME NULL DEFAULT NULL,
            syncing_at DATETIME NULL DEFAULT NULL,
            last_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NULL DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
    );

    $exists = $pdo->query('SELECT id FROM sas_sync_meta WHERE id = 1')->fetch();
    if (!$exists) {
        $pdo->exec('INSERT INTO sas_sync_meta (id, last_count) VALUES (1, 0)');
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM sas_sync_meta LIKE 'sync_offset'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE sas_sync_meta ADD COLUMN sync_offset INT UNSIGNED NOT NULL DEFAULT 0');
        }
    } catch (Exception $e) {
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM sas_sync_meta LIKE 'sync_expected'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE sas_sync_meta ADD COLUMN sync_expected INT UNSIGNED NOT NULL DEFAULT 0');
        }
    } catch (Exception $e) {
    }
}

function sas_sync_meta($pdo)
{
    ensure_sas_users_cache_table($pdo);
    $row = $pdo->query('SELECT * FROM sas_sync_meta WHERE id = 1')->fetch();
    return $row ? $row : array(
        'last_ok_at' => null,
        'last_try_at' => null,
        'syncing_at' => null,
        'last_count' => 0,
        'last_error' => null,
    );
}

function sas_sync_meta_save($pdo, $fields)
{
    $cols = array();
    $params = array();
    foreach ($fields as $k => $v) {
        $cols[] = $k . ' = :' . $k;
        $params[':' . $k] = $v;
    }
    if (!$cols) {
        return;
    }
    try {
        $pdo->prepare('UPDATE sas_sync_meta SET ' . implode(', ', $cols) . ' WHERE id = 1')
            ->execute($params);
    } catch (Exception $e) {
        unset($fields['sync_offset'], $fields['sync_expected']);
        $cols = array();
        $params = array();
        foreach ($fields as $k => $v) {
            $cols[] = $k . ' = :' . $k;
            $params[':' . $k] = $v;
        }
        if ($cols) {
            $pdo->prepare('UPDATE sas_sync_meta SET ' . implode(', ', $cols) . ' WHERE id = 1')
                ->execute($params);
        }
    }
}

function sas_cache_username($row)
{
    if (!is_array($row)) {
        return '';
    }
    foreach (array('username', 'user_name', 'login', 'user') as $k) {
        if (!isset($row[$k]) || is_array($row[$k])) {
            continue;
        }
        $v = trim((string) $row[$k]);
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

function sas_cache_display_name($row)
{
    if (!is_array($row)) {
        return 'SAS';
    }
    $fn = isset($row['firstname']) ? trim((string) $row['firstname']) : '';
    $ln = isset($row['lastname']) ? trim((string) $row['lastname']) : '';
    $full = trim($fn . ' ' . $ln);
    if ($full !== '') {
        return $full;
    }
    foreach (array('name', 'full_name', 'fullname', 'display_name', 'customer_name') as $k) {
        if (!empty($row[$k]) && !is_array($row[$k])) {
            return trim((string) $row[$k]);
        }
    }
    $u = sas_cache_username($row);
    return $u !== '' ? $u : 'SAS';
}

function sas_cache_phone_raw($row)
{
    if (!is_array($row)) {
        return '';
    }
    foreach (array('phone', 'mobile', 'tel', 'cellphone', 'mobile_number') as $k) {
        if (!empty($row[$k]) && !is_array($row[$k])) {
            return trim((string) $row[$k]);
        }
    }
    $u = sas_cache_username($row);
    $d = preg_replace('/\D+/', '', $u);
    return (strlen($d) >= 8) ? $u : '';
}

function sas_cache_profile_id($row)
{
    if (!is_array($row)) {
        return 0;
    }
    foreach (array('profile_id', 'profileId', 'srv_profile_id') as $k) {
        if (isset($row[$k]) && is_numeric($row[$k]) && (int) $row[$k] > 0) {
            return (int) $row[$k];
        }
    }
    if (isset($row['profile_details']) && is_array($row['profile_details'])) {
        $pid = sas_cache_profile_id($row['profile_details']);
        if ($pid > 0) {
            return $pid;
        }
        if (isset($row['profile_details']['id']) && is_numeric($row['profile_details']['id'])) {
            return (int) $row['profile_details']['id'];
        }
    }
    if (isset($row['profile']) && is_array($row['profile'])) {
        return sas_cache_profile_id($row['profile']);
    }
    return 0;
}

function sas_cache_profile_name($row)
{
    if (!is_array($row)) {
        return '';
    }
    foreach (array('profile_name', 'profileName', 'package', 'service_name') as $k) {
        if (!empty($row[$k]) && !is_array($row[$k])) {
            return trim((string) $row[$k]);
        }
    }
    if (isset($row['profile_details']) && is_array($row['profile_details'])) {
        if (function_exists('sas_row_name')) {
            $n = sas_row_name($row['profile_details']);
            if ($n !== '') {
                return $n;
            }
        }
        if (!empty($row['profile_details']['name']) && !is_array($row['profile_details']['name'])) {
            return trim((string) $row['profile_details']['name']);
        }
    }
    if (isset($row['profile']) && is_array($row['profile'])) {
        $n = function_exists('sas_row_name') ? sas_row_name($row['profile']) : '';
        if ($n !== '') {
            return $n;
        }
    }
    if (isset($row['profile']) && is_string($row['profile'])) {
        return trim($row['profile']);
    }
    return '';
}

function sas_cache_enabled($row)
{
    if (!is_array($row)) {
        return 1;
    }
    foreach (array('enabled', 'is_enabled') as $k) {
        if (!isset($row[$k]) || is_array($row[$k])) {
            continue;
        }
        $v = $row[$k];
        if ($v === 0 || $v === '0' || $v === false || $v === 'false') {
            return 0;
        }
        $s = strtolower((string) $v);
        if (in_array($s, array('disabled', 'disable', 'inactive', 'off', 'stopped'), true)) {
            return 0;
        }
        return 1;
    }
    if (isset($row['status']) && !is_array($row['status'])) {
        $s = strtolower((string) $row['status']);
        if (in_array($s, array('disabled', 'disable', 'inactive', 'off', 'stopped'), true)) {
            return 0;
        }
    }
    return 1;
}

function sas_cache_expire_at($row)
{
    if (!is_array($row)) {
        return null;
    }
    $keys = array(
        'expiration', 'expiration_date', 'expire_date', 'expiry_date',
        'service_expiration', 'acctexpiration', 'acct_expiration',
        'expiry', 'expires', 'valid_until', 'end_date', 'expire',
    );
    foreach ($keys as $k) {
        if (!isset($row[$k]) || $row[$k] === '' || $row[$k] === null || is_array($row[$k])) {
            continue;
        }
        $v = $row[$k];
        if (is_numeric($v)) {
            $n = (int) $v;
            if ($n > 1000000000 && $n < 2000000000) {
                return date('Y-m-d H:i:s', $n);
            }
            if ($n > 1000000000000) {
                return date('Y-m-d H:i:s', (int) floor($n / 1000));
            }
        }
        $ts = strtotime((string) $v);
        if ($ts && $ts > 946684800) {
            return date('Y-m-d H:i:s', $ts);
        }
    }
    return null;
}

function sas_phone_digits($phone)
{
    $d = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($d) >= 10 && substr($d, 0, 3) === '964') {
        $d = substr($d, 3);
    }
    if (strlen($d) >= 10 && substr($d, 0, 1) === '0') {
        $d = substr($d, 1);
    }
    return $d;
}

function sas_cache_pick_local($pdo, $username, $sasUserId, $phone)
{
    $username = trim((string) $username);
    $sasUserId = (int) $sasUserId;

    if ($sasUserId > 0) {
        $st = $pdo->prepare('SELECT * FROM subscribers WHERE sas_user_id = :id LIMIT 1');
        $st->execute(array(':id' => $sasUserId));
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
    }

    if ($username !== '') {
        $st = $pdo->prepare('SELECT * FROM subscribers WHERE sas_username = :u LIMIT 1');
        $st->execute(array(':u' => $username));
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
    }

    $digits = sas_phone_digits($phone !== '' ? $phone : $username);
    if (strlen($digits) < 8) {
        return null;
    }

    $st = $pdo->prepare(
        'SELECT s.*,
            (SELECT COALESCE(SUM(amount),0) FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt
         FROM subscribers s
         WHERE REPLACE(REPLACE(REPLACE(s.phone, "+", ""), "-", ""), " ", "") LIKE :tail
            OR s.phone LIKE :q
         ORDER BY debt DESC, s.id ASC'
    );
    $st->execute(array(
        ':tail' => '%' . $digits,
        ':q' => '%' . $digits . '%',
    ));
    $matches = $st->fetchAll();
    if (!$matches) {
        return null;
    }

    $exact = array();
    foreach ($matches as $m) {
        if (sas_phone_digits($m['phone']) === $digits) {
            $exact[] = $m;
        }
    }
    $pool = $exact ? $exact : $matches;
    if (count($pool) === 1) {
        return $pool[0];
    }

    // أكثر من سجل بنفس الرقم: نختار صاحب أكبر دين حتى ما تضيع الديون
    $best = $pool[0];
    foreach ($pool as $m) {
        if ((float) $m['debt'] > (float) $best['debt']) {
            $best = $m;
        }
    }
    return $best;
}

function sas_cache_link_local_fields($pdo, $local, $username, $sasUserId)
{
    if (!$local || empty($local['id'])) {
        return (int) (isset($local['id']) ? $local['id'] : 0);
    }
    $sets = array();
    $params = array(':id' => (int) $local['id']);
    if ($username !== '' && empty($local['sas_username'])) {
        $sets[] = 'sas_username = :u';
        $params[':u'] = $username;
    }
    if ($sasUserId > 0 && (empty($local['sas_user_id']) || (int) $local['sas_user_id'] <= 0)) {
        $sets[] = 'sas_user_id = :sid';
        $params[':sid'] = $sasUserId;
    }
    if ($sets) {
        $pdo->prepare('UPDATE subscribers SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);
    }
    return (int) $local['id'];
}

function sas_cache_unique_name($pdo, $base, $username)
{
    $base = function_exists('normalize_subscriber_name')
        ? normalize_subscriber_name($base)
        : trim((string) $base);
    if ($base === '') {
        $base = $username !== '' ? $username : 'SAS';
    }
    if (!subscriber_name_taken($pdo, $base)) {
        return $base;
    }
    $withUser = $base . ' · ' . $username;
    if ($username !== '' && !subscriber_name_taken($pdo, $withUser)) {
        return $withUser;
    }
    $i = 2;
    while ($i < 80) {
        $try = $base . ' · ' . $i;
        if (!subscriber_name_taken($pdo, $try)) {
            return $try;
        }
        $i++;
    }
    return $base . ' · ' . date('His');
}

function sas_cache_plan_id_for_profile($pdo, $profileId)
{
    $profileId = (int) $profileId;
    if ($profileId <= 0) {
        return 0;
    }
    $st = $pdo->prepare(
        'SELECT id FROM service_plans WHERE sas_profile_id = :p AND is_active = 1 ORDER BY id ASC LIMIT 1'
    );
    $st->execute(array(':p' => $profileId));
    return (int) $st->fetchColumn();
}

/**
 * إيجاد سجل محلي أو إنشاء واحد جديد للعمليات — بدون مسح ديون أو تغيير اسم موجود
 */
function sas_cache_ensure_local($pdo, $config, $cacheRow)
{
    $username = isset($cacheRow['username']) ? trim((string) $cacheRow['username']) : '';
    if ($username === '') {
        return array(0, 'ماكو يوزرنيم بالساس');
    }
    $sasUserId = isset($cacheRow['sas_user_id']) ? (int) $cacheRow['sas_user_id'] : 0;
    $phone = isset($cacheRow['phone']) ? (string) $cacheRow['phone'] : '';
    $local = sas_cache_pick_local($pdo, $username, $sasUserId, $phone);
    if ($local) {
        $id = sas_cache_link_local_fields($pdo, $local, $username, $sasUserId);
        $pdo->prepare('UPDATE sas_users_cache SET local_subscriber_id = :lid WHERE username = :u')
            ->execute(array(':lid' => $id, ':u' => $username));
        return array($id, '');
    }

    $display = isset($cacheRow['display_name']) ? $cacheRow['display_name'] : $username;
    $name = sas_cache_unique_name($pdo, $display, $username);
    $phoneStore = $phone !== '' ? normalize_phone($phone) : normalize_phone($username);
    if ($phoneStore === '') {
        $phoneStore = '964000000000';
    }
    $planId = sas_cache_plan_id_for_profile($pdo, isset($cacheRow['profile_id']) ? $cacheRow['profile_id'] : 0);
    $agentId = 0;
    if (function_exists('is_agent_user') && is_agent_user() && function_exists('current_admin')) {
        $me = current_admin();
        $agentId = $me ? (int) $me['id'] : 0;
    } elseif (function_exists('default_admin_user_id')) {
        $agentId = default_admin_user_id($pdo);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO subscribers (name, phone, notes, preferred_plan_id, agent_user_id, sas_username, sas_user_id)
         VALUES (:name, :phone, :notes, :plan_id, :agent_id, :sas_u, :sas_id)'
    );
    $stmt->execute(array(
        ':name' => $name,
        ':phone' => $phoneStore,
        ':notes' => 'من SAS — ' . $username,
        ':plan_id' => $planId > 0 ? $planId : null,
        ':agent_id' => $agentId > 0 ? $agentId : null,
        ':sas_u' => $username,
        ':sas_id' => $sasUserId > 0 ? $sasUserId : null,
    ));
    $newId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE sas_users_cache SET local_subscriber_id = :lid WHERE username = :u')
        ->execute(array(':lid' => $newId, ':u' => $username));
    return array($newId, '');
}

function sas_cache_get($pdo, $username)
{
    $st = $pdo->prepare('SELECT * FROM sas_users_cache WHERE username = :u LIMIT 1');
    $st->execute(array(':u' => trim((string) $username)));
    $row = $st->fetch();
    return $row ? $row : null;
}

function sas_user_url($username, $focus = '')
{
    $url = 'sas_user.php?u=' . rawurlencode((string) $username);
    if ($focus !== '') {
        $url .= '&focus=' . rawurlencode($focus);
    }
    return $url;
}

function sas_page_connector($config)
{
    $api = function_exists('sas_make_connector') ? sas_make_connector($config) : null;
    if (!$api) {
        return null;
    }
    if (method_exists($api, 'setTimeout')) {
        $api->setTimeout(30);
    }
    if (!$api->login()) {
        return null;
    }
    return $api;
}

function sas_profiles_for_ui($api)
{
    $out = array();
    if (!$api || !method_exists($api, 'getProfiles')) {
        return $out;
    }
    $raw = $api->getProfiles();
    if (function_exists('sas_response_is_error') && sas_response_is_error($raw)) {
        return $out;
    }
    $rows = is_array($raw) ? $raw : array();
    if (isset($rows['data']) && is_array($rows['data'])) {
        $rows = $rows['data'];
    }
    foreach ($rows as $p) {
        if (!is_array($p)) {
            continue;
        }
        $id = function_exists('sas_row_id') ? (int) sas_row_id($p) : 0;
        if ($id <= 0 && function_exists('sas_extract_user_id')) {
            $id = sas_extract_user_id($p);
        }
        $name = function_exists('sas_row_name') ? sas_row_name($p) : '';
        if ($id <= 0) {
            continue;
        }
        $out[] = array('id' => $id, 'name' => $name !== '' ? $name : ('#' . $id));
    }
    return $out;
}

function sas_cards_for_ui($api, $profileId)
{
    $out = array();
    if (!$api || !method_exists($api, 'listUnusedCards')) {
        return $out;
    }
    $rows = $api->listUnusedCards((int) $profileId);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = function_exists('sas_extract_user_id') ? sas_extract_user_id($row) : 0;
        $pin = '';
        foreach (array('pin', 'serial', 'code', 'card', 'number') as $k) {
            if (!empty($row[$k]) && !is_array($row[$k])) {
                $pin = trim((string) $row[$k]);
                break;
            }
        }
        if ($pin === '' && $id > 0) {
            $pin = (string) $id;
        }
        if ($pin === '') {
            continue;
        }
        $pname = sas_cache_profile_name($row);
        $out[] = array(
            'id' => $id,
            'pin' => $pin,
            'profile_id' => sas_cache_profile_id($row),
            'label' => $pin . ($pname !== '' ? (' — ' . $pname) : ''),
        );
    }
    return $out;
}

function sas_write_user($pdo, $config, $action, $username, $fields)
{
    $username = trim((string) $username);
    $fields = is_array($fields) ? $fields : array();
    $cache = $username !== '' ? sas_cache_get($pdo, $username) : null;
    if (!$cache) {
        return array(false, 'المشترك مو موجود بكاش SAS — حدّث القائمة', array());
    }
    $sasUserId = !empty($cache['sas_user_id']) ? (int) $cache['sas_user_id'] : 0;
    $api = sas_page_connector($config);
    if (!$api) {
        return array(false, 'تعذر الدخول للساس', array());
    }
    if ($sasUserId <= 0 && method_exists($api, 'findUserByUsername')) {
        $found = $api->findUserByUsername($username);
        if (is_array($found) && function_exists('sas_extract_user_id')) {
            $sasUserId = sas_extract_user_id($found);
            if ($sasUserId > 0) {
                sas_cache_patch($pdo, $username, array('sas_user_id' => $sasUserId));
            }
        }
    }
    if ($sasUserId <= 0 && $action !== 'sas_activate_card' && $action !== 'sas_activate_credit') {
        return array(false, 'ماكو رقم مستخدم بالساس لهذا المشترك', array());
    }

    if ($action === 'sas_inline' || $action === 'sas_update_info') {
        $name = isset($fields['name']) ? trim((string) $fields['name']) : '';
        $phoneIn = isset($fields['phone']) ? trim((string) $fields['phone']) : '';
        $field = isset($fields['field']) ? (string) $fields['field'] : '';
        $value = isset($fields['value']) ? trim((string) $fields['value']) : '';
        if ($action === 'sas_inline' && $field === 'name') {
            $name = $value;
        }
        if ($action === 'sas_inline' && $field === 'phone') {
            $phoneIn = $value;
        }
        $patch = array();
        $extra = array();
        if ($name !== '') {
            $name = function_exists('normalize_subscriber_name') ? normalize_subscriber_name($name) : $name;
            if ($name === '') {
                return array(false, 'الاسم فارغ', array());
            }
            list($first, $last) = function_exists('sas_split_name') ? sas_split_name($name) : array($name, '');
            $res = $api->updateUser($sasUserId, array('firstname' => $first, 'lastname' => $last));
            if (function_exists('sas_response_success') && !sas_response_success($res)) {
                return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل تعديل الاسم'), array());
            }
            $patch['firstname'] = $first !== '' ? $first : null;
            $patch['lastname'] = $last !== '' ? $last : null;
            $patch['display_name'] = $name;
            $extra['value'] = $name;
        }
        if ($phoneIn !== '') {
            $phone = function_exists('normalize_phone') ? normalize_phone($phoneIn) : $phoneIn;
            if ($phone === '') {
                $phone = $phoneIn;
            }
            $phoneDisp = function_exists('format_phone_display') ? format_phone_display($phone) : $phone;
            $res = $api->updateUser($sasUserId, array('phone' => $phoneDisp));
            if (function_exists('sas_response_success') && !sas_response_success($res)) {
                return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل تعديل الهاتف'), array());
            }
            $patch['phone'] = $phoneDisp;
            $extra['value'] = $phoneDisp;
            $extra['raw'] = $phone;
        }
        if (isset($fields['enabled']) && ($fields['enabled'] === '0' || $fields['enabled'] === '1' || $fields['enabled'] === 0 || $fields['enabled'] === 1)) {
            $on = ((string) $fields['enabled'] === '1' || $fields['enabled'] === 1) ? 1 : 0;
            $res = $api->setUserEnabled($sasUserId, $on);
            if (function_exists('sas_response_success') && !sas_response_success($res)) {
                return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل تغيير الحالة'), array());
            }
            $patch['enabled'] = $on;
            $extra['enabled'] = $on;
        }
        if ($patch) {
            sas_cache_patch($pdo, $username, $patch);
        }
        return array(true, 'تم الحفظ بالساس', $extra);
    }

    if ($action === 'sas_enable') {
        $on = (isset($fields['enabled']) && (string) $fields['enabled'] === '1') ? 1 : 0;
        $res = $api->setUserEnabled($sasUserId, $on);
        if (function_exists('sas_response_success') && !sas_response_success($res)) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل تغيير الحالة'), array());
        }
        sas_cache_patch($pdo, $username, array('enabled' => $on));
        return array(true, $on ? 'تم تشغيل المشترك بالساس' : 'تم إيقاف المشترك بالساس', array('enabled' => $on));
    }

    if ($action === 'sas_change_profile') {
        $profileId = isset($fields['profile_id']) ? (int) $fields['profile_id'] : 0;
        if ($profileId <= 0) {
            return array(false, 'اختر نوع الاشتراك', array());
        }
        $res = $api->changeUserProfile($sasUserId, $profileId);
        if (function_exists('sas_response_success') && !sas_response_success($res)) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل تغيير الباقة'), array());
        }
        $pname = isset($fields['profile_name']) ? trim((string) $fields['profile_name']) : '';
        sas_cache_patch($pdo, $username, array(
            'profile_id' => $profileId,
            'profile_name' => $pname !== '' ? $pname : null,
        ));
        sas_cache_refresh_one($pdo, $config, $username, $sasUserId);
        return array(true, 'تم تغيير نوع الاشتراك بالساس', array('profile_id' => $profileId, 'profile_name' => $pname));
    }

    if ($action === 'sas_activate_credit') {
        $profileId = isset($fields['profile_id']) ? (int) $fields['profile_id'] : 0;
        if ($profileId <= 0 && !empty($cache['profile_id'])) {
            $profileId = (int) $cache['profile_id'];
        }
        if ($profileId <= 0) {
            return array(false, 'ماكو بروفايل محدد للتفعيل', array());
        }
        $units = isset($fields['units']) ? (int) $fields['units'] : 1;
        if ($units <= 0) {
            $units = 1;
        }
        $res = $api->activateUserCredit($username, $profileId, $units);
        if (function_exists('sas_response_success') && !sas_response_success($res)) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل التفعيل بالرصيد'), array());
        }
        sas_cache_refresh_one($pdo, $config, $username, $sasUserId);
        return array(true, 'تم التفعيل بالرصيد على الساس', array());
    }

    if ($action === 'sas_activate_card') {
        $pin = isset($fields['pin']) ? trim((string) $fields['pin']) : '';
        $cardId = isset($fields['card_id']) ? (int) $fields['card_id'] : 0;
        if ($pin === '' && $cardId <= 0) {
            return array(false, 'اختر كرت غير مستخدم', array());
        }
        $res = $api->activateUserCard($username, $pin !== '' ? $pin : (string) $cardId, $sasUserId, $cardId);
        if (function_exists('sas_response_success') && !sas_response_success($res)) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل التفعيل بالكرت'), array());
        }
        sas_cache_refresh_one($pdo, $config, $username, $sasUserId);
        return array(true, 'تم التفعيل بالكرت على الساس', array());
    }

    return array(false, 'عملية غير معروفة', array());
}

function sas_clip($s, $max)
{
    $s = (string) $s;
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max, 'UTF-8');
    }
    return substr($s, 0, $max);
}

function sas_cache_upsert_row($pdo, $row, $nowSql = null, $ins = null)
{
    if (!is_array($row)) {
        return false;
    }
    $username = sas_clip(sas_cache_username($row), 80);
    if ($username === '') {
        return false;
    }
    if ($nowSql === null) {
        $nowSql = date('Y-m-d H:i:s');
    }
    if (!$ins) {
        $ins = $pdo->prepare(
            'INSERT INTO sas_users_cache
                (username, sas_user_id, firstname, lastname, display_name, phone, profile_id, profile_name,
                 enabled, expire_at, local_subscriber_id, synced_at)
             VALUES
                (:username, :sas_user_id, :firstname, :lastname, :display_name, :phone, :profile_id, :profile_name,
                 :enabled, :expire_at, :local_subscriber_id, :synced_at)
             ON DUPLICATE KEY UPDATE
                sas_user_id = VALUES(sas_user_id),
                firstname = VALUES(firstname),
                lastname = VALUES(lastname),
                display_name = VALUES(display_name),
                phone = VALUES(phone),
                profile_id = VALUES(profile_id),
                profile_name = VALUES(profile_name),
                enabled = VALUES(enabled),
                expire_at = VALUES(expire_at),
                local_subscriber_id = IF(VALUES(local_subscriber_id) IS NULL, local_subscriber_id, VALUES(local_subscriber_id)),
                synced_at = VALUES(synced_at)'
        );
    }
    $sasUserId = function_exists('sas_extract_user_id') ? sas_extract_user_id($row) : 0;
    $phone = sas_clip(sas_cache_phone_raw($row), 40);
    $fn = isset($row['firstname']) && !is_array($row['firstname']) ? sas_clip($row['firstname'], 150) : '';
    $ln = isset($row['lastname']) && !is_array($row['lastname']) ? sas_clip($row['lastname'], 150) : '';
    try {
        $ins->execute(array(
            ':username' => $username,
            ':sas_user_id' => $sasUserId > 0 ? $sasUserId : null,
            ':firstname' => $fn !== '' ? $fn : null,
            ':lastname' => $ln !== '' ? $ln : null,
            ':display_name' => sas_clip(sas_cache_display_name($row), 200),
            ':phone' => $phone !== '' ? $phone : null,
            ':profile_id' => ($pid = sas_cache_profile_id($row)) > 0 ? $pid : null,
            ':profile_name' => (($pn = sas_cache_profile_name($row)) !== '' ? sas_clip($pn, 150) : null),
            ':enabled' => sas_cache_enabled($row),
            ':expire_at' => sas_cache_expire_at($row),
            ':local_subscriber_id' => null,
            ':synced_at' => $nowSql,
        ));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function sas_cache_patch($pdo, $username, $fields)
{
    $username = trim((string) $username);
    if ($username === '' || !is_array($fields) || !$fields) {
        return;
    }
    $allow = array(
        'sas_user_id', 'firstname', 'lastname', 'display_name', 'phone',
        'profile_id', 'profile_name', 'enabled', 'expire_at',
    );
    $cols = array();
    $params = array(':u' => $username);
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allow, true)) {
            continue;
        }
        $cols[] = $k . ' = :' . $k;
        $params[':' . $k] = $v;
    }
    if (!$cols) {
        return;
    }
    $cols[] = 'synced_at = NOW()';
    $pdo->prepare('UPDATE sas_users_cache SET ' . implode(', ', $cols) . ' WHERE username = :u')
        ->execute($params);
}

function sas_cache_refresh_one($pdo, $config, $username, $sasUserId = 0)
{
    $username = trim((string) $username);
    $api = function_exists('sas_make_connector') ? sas_make_connector($config) : null;
    if (!$api || !$api->login()) {
        return false;
    }
    $found = null;
    if ((int) $sasUserId > 0 && method_exists($api, 'getUserById')) {
        $got = $api->getUserById((int) $sasUserId);
        if (is_array($got) && !(function_exists('sas_response_is_error') && sas_response_is_error($got))) {
            if (isset($got[0]) && is_array($got[0])) {
                $found = $got[0];
            } elseif (sas_cache_username($got) !== '' || !empty($got['id'])) {
                $found = $got;
            }
        }
    }
    if (!$found && $username !== '' && method_exists($api, 'findUserByUsername')) {
        $found = $api->findUserByUsername($username);
    }
    if (!$found || !is_array($found)) {
        return false;
    }
    return sas_cache_upsert_row($pdo, $found);
}

/**
 * دفعة واحدة من الساس حتى ما ينهار السيرفر
 * يرجع array($ok, $count, $mode, $meta)
 * $mode: cache | progress | synced | busy | error
 */
function sas_sync_users_from_api($pdo, $config, $force = false, $reset = false)
{
    ensure_sas_users_cache_table($pdo);
    $meta = sas_sync_meta($pdo);

    if (!function_exists('sas_is_ready') || !sas_is_ready($config)) {
        return array(false, 0, 'error', array_merge($meta, array('last_error' => 'SAS غير مفعّل أو بياناته ناقصة')));
    }

    $now = time();
    $offset = isset($meta['sync_offset']) ? (int) $meta['sync_offset'] : 0;
    if ($reset) {
        $offset = 0;
        sas_sync_meta_save($pdo, array(
            'sync_offset' => 0,
            'sync_expected' => 0,
            'syncing_at' => null,
        ));
        $meta = sas_sync_meta($pdo);
    }
    if (!$force && !$reset && $offset <= 0 && !empty($meta['last_ok_at'])) {
        $okTs = strtotime($meta['last_ok_at']);
        if ($okTs && ($now - $okTs) < 180) {
            return array(true, (int) $meta['last_count'], 'cache', $meta);
        }
    }

    $api = sas_make_connector($config);
    if (!$api) {
        sas_sync_meta_save($pdo, array(
            'last_try_at' => date('Y-m-d H:i:s'),
            'last_error' => 'تعذر إنشاء اتصال SAS',
        ));
        return array(false, 0, 'error', sas_sync_meta($pdo));
    }

    sas_sync_meta_save($pdo, array(
        'last_try_at' => date('Y-m-d H:i:s'),
        'syncing_at' => date('Y-m-d H:i:s'),
        'last_error' => null,
    ));

    try {
        $api->setTimeout(45);
        if (!$api->login()) {
            $err = $api->getLastError();
            sas_sync_meta_save($pdo, array(
                'syncing_at' => null,
                'last_error' => $err !== '' ? $err : 'فشل الدخول للساس',
            ));
            return array(false, 0, 'error', sas_sync_meta($pdo));
        }

        $pageSize = 100;
        $pageNum = $offset > 0 ? (int) $offset : 1;
        $start = ($pageNum - 1) * $pageSize;
        $saved = 0;
        $expected = isset($meta['sync_expected']) ? (int) $meta['sync_expected'] : 0;
        $nowSql = date('Y-m-d H:i:s');

        if (!method_exists($api, 'listUsersPage')) {
            sas_sync_meta_save($pdo, array(
                'syncing_at' => null,
                'last_error' => 'ارفع ملف includes/sas/SASConnector.php المحدّث',
            ));
            return array(false, 0, 'error', sas_sync_meta($pdo));
        }

        $ins = $pdo->prepare(
            'INSERT INTO sas_users_cache
                (username, sas_user_id, firstname, lastname, display_name, phone, profile_id, profile_name,
                 enabled, expire_at, local_subscriber_id, synced_at)
             VALUES
                (:username, :sas_user_id, :firstname, :lastname, :display_name, :phone, :profile_id, :profile_name,
                 :enabled, :expire_at, :local_subscriber_id, :synced_at)
             ON DUPLICATE KEY UPDATE
                sas_user_id = VALUES(sas_user_id),
                firstname = VALUES(firstname),
                lastname = VALUES(lastname),
                display_name = VALUES(display_name),
                phone = VALUES(phone),
                profile_id = VALUES(profile_id),
                profile_name = VALUES(profile_name),
                enabled = VALUES(enabled),
                expire_at = VALUES(expire_at),
                local_subscriber_id = IF(VALUES(local_subscriber_id) IS NULL, local_subscriber_id, VALUES(local_subscriber_id)),
                synced_at = VALUES(synced_at)'
        );

        $page = $api->listUsersPage($start, $pageSize, '');
        $sasPer = isset($page['per_page']) ? (int) $page['per_page'] : 0;
        if ($sasPer >= 10 && $sasPer !== $pageSize) {
            $pageSize = $sasPer;
            $start = ($pageNum - 1) * $pageSize;
            if ($pageNum > 1) {
                $page = $api->listUsersPage($start, $pageSize, '');
            }
        }
        if (empty($page['ok'])) {
            sas_sync_meta_save($pdo, array(
                'syncing_at' => null,
                'last_error' => isset($page['message']) ? sas_clip($page['message'], 240) : 'فشل جلب المشتركين',
            ));
            return array(false, $saved, 'error', sas_sync_meta($pdo));
        }
        if ($expected <= 0) {
            $expected = isset($page['filtered']) ? (int) $page['filtered'] : (int) $page['total'];
        }
        $rows = isset($page['rows']) ? $page['rows'] : array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (sas_cache_upsert_row($pdo, $row, $nowSql, $ins)) {
                $saved++;
            }
        }

        $nextPage = $pageNum + 1;
        $done = !empty($page['complete'])
            || !$rows
            || ($expected > 0 && (($pageNum * $pageSize) >= $expected))
            || $pageNum >= 400;
        $totalNow = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache')->fetchColumn();

        if ($done) {
            sas_sync_meta_save($pdo, array(
                'syncing_at' => null,
                'sync_offset' => 0,
                'sync_expected' => $expected,
                'last_ok_at' => date('Y-m-d H:i:s'),
                'last_count' => $totalNow,
                'last_error' => null,
            ));
            return array(true, $totalNow, 'synced', sas_sync_meta($pdo));
        }

        sas_sync_meta_save($pdo, array(
            'syncing_at' => date('Y-m-d H:i:s'),
            'sync_offset' => $nextPage,
            'sync_expected' => $expected,
            'last_count' => $totalNow,
            'last_error' => null,
        ));
        return array(true, $totalNow, 'progress', sas_sync_meta($pdo));
    } catch (Exception $e) {
        sas_sync_meta_save($pdo, array(
            'syncing_at' => null,
            'last_error' => $e->getMessage(),
        ));
        return array(false, 0, 'error', sas_sync_meta($pdo));
    } catch (Error $e) {
        try {
            sas_sync_meta_save($pdo, array(
                'syncing_at' => null,
                'last_error' => $e->getMessage(),
            ));
        } catch (Exception $e2) {
        }
        return array(false, 0, 'error', sas_sync_meta($pdo));
    }
}

function sas_cache_search_sql($q, &$params)
{
    $where = '1=1';
    $params = array();
    if ($q === '') {
        return $where;
    }
    $qDigits = preg_replace('/\D+/', '', $q);
    $where .= ' AND (
        c.display_name LIKE :q
        OR c.username LIKE :q
        OR c.phone LIKE :q
        OR c.firstname LIKE :q
        OR c.lastname LIKE :q
        OR c.profile_name LIKE :q
    ';
    $params[':q'] = '%' . $q . '%';
    if ($qDigits !== '') {
        $where .= ' OR c.username LIKE :qd OR c.phone LIKE :qd';
        $params[':qd'] = '%' . $qDigits . '%';
    }
    $where .= ')';
    return $where;
}

function sas_cache_filter_sql($subFilter)
{
    if ($subFilter === 'active') {
        return ' AND c.enabled = 1 AND c.expire_at IS NOT NULL AND c.expire_at >= NOW()';
    }
    if ($subFilter === 'expired') {
        return ' AND (c.enabled = 0 OR c.expire_at IS NULL OR c.expire_at < NOW())';
    }
    if ($subFilter === 'today') {
        return ' AND c.expire_at IS NOT NULL AND DATE(c.expire_at) = CURDATE()';
    }
    if ($subFilter === 'soon') {
        return ' AND c.expire_at IS NOT NULL AND c.expire_at > NOW()
                 AND c.expire_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)';
    }
    if ($subFilter === 'debt') {
        return ' AND EXISTS (
            SELECT 1 FROM invoices i
            WHERE i.subscriber_id = c.local_subscriber_id AND i.status = "unpaid" AND i.amount > 0
        )';
    }
    return '';
}

function sas_cache_list_select_sql()
{
    return 'SELECT c.*,
        s.id AS local_id,
        s.name AS local_name,
        s.phone AS local_phone,
        (SELECT COALESCE(SUM(amount),0) FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt,
        (SELECT GROUP_CONCAT(DISTINCT i.month_label ORDER BY i.month_label SEPARATOR \',\')
            FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt_months,
        (SELECT m.success FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_ok,
        (SELECT m.message_type FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_type,
        (SELECT m.body FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_body,
        (SELECT m.response_json FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_response,
        (SELECT m.created_at FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_at,
        (SELECT m.id FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_id';
}

function sas_render_table_row($row, $n, $config, $lang)
{
    $username = isset($row['username']) ? (string) $row['username'] : '';
    $sasId = !empty($row['sas_user_id']) ? (int) $row['sas_user_id'] : 0;
    $profileId = !empty($row['profile_id']) ? (int) $row['profile_id'] : 0;
    $name = isset($row['display_name']) && $row['display_name'] !== ''
        ? (string) $row['display_name']
        : $username;
    $phone = isset($row['phone']) && $row['phone'] !== ''
        ? (string) $row['phone']
        : $username;
    $phoneDisp = function_exists('format_phone_display') ? format_phone_display($phone) : $phone;
    $localId = !empty($row['local_id']) ? (int) $row['local_id'] : (!empty($row['local_subscriber_id']) ? (int) $row['local_subscriber_id'] : 0);
    $debt = isset($row['debt']) ? (float) $row['debt'] : 0.0;
    $enabled = isset($row['enabled']) ? (int) $row['enabled'] : 1;
    $expireAt = !empty($row['expire_at']) ? $row['expire_at'] : '';
    $hasExpire = $expireAt !== '';
    $expireDay = $hasExpire ? date('Y-m-d', strtotime($expireAt)) : '';
    $isActive = $enabled && $hasExpire && strtotime($expireAt) >= strtotime(date('Y-m-d H:i:s'));

    if ($isActive) {
        $rowClass = 'row-status-active';
        $statusTitle = $lang === 'en' ? 'Active' : 'فعال';
        $statusKey = 'active';
    } elseif ($enabled && $hasExpire) {
        $rowClass = 'row-status-expired';
        $statusTitle = $lang === 'en' ? 'Expired' : 'منتهي';
        $statusKey = 'expired';
    } else {
        $rowClass = 'row-status-left';
        $statusTitle = $lang === 'en' ? 'Disabled / none' : 'موقوف / بدون باقة';
        $statusKey = 'left';
    }

    $daysInfo = null;
    if ($hasExpire) {
        $daysInfo = subscription_days_info(date('Y-m-d', strtotime($expireDay . ' -30 days')), $expireDay);
    }
    $pkgLabel = !empty($row['profile_name']) ? $row['profile_name'] : '-';

    $hasMsg = isset($row['last_msg_at']) && $row['last_msg_at'] !== null && $row['last_msg_at'] !== '';
    $msgOk = $hasMsg && !empty($row['last_msg_ok']);
    $msgResp = isset($row['last_msg_response']) ? $row['last_msg_response'] : '';
    $noWa = $hasMsg && !$msgOk && function_exists('subscriber_msg_is_no_whatsapp')
        && subscriber_msg_is_no_whatsapp($msgResp);
    $msgShort = $hasMsg && function_exists('message_short_summary')
        ? message_short_summary($row['last_msg_type'], $row['last_msg_body'], $msgOk)
        : ($lang === 'en' ? 'No message sent' : 'لم تُرسل رسالة');
    if ($noWa) {
        $msgShort = 'لا يتوفر واتساب لدى المشترك';
    }
    $msgFail = ($hasMsg && !$msgOk && !$noWa) ? '1' : '0';
    $logId = (!empty($row['last_msg_id'])) ? (int) $row['last_msg_id'] : 0;

    $searchText = strtolower($name . ' ' . $username . ' ' . $phone . ' ' . $phoneDisp);
    $detailUrl = function_exists('sas_user_url') ? sas_user_url($username) : ('sas_user.php?u=' . rawurlencode($username));

    $html = '<tr class="' . e($rowClass) . '"'
        . ' data-search="' . e($searchText) . '"'
        . ' data-id="' . e($username) . '"'
        . ' data-sas-id="' . $sasId . '"'
        . ' data-profile-id="' . $profileId . '"'
        . ' data-enabled="' . ($enabled ? '1' : '0') . '"'
        . ' data-local-id="' . $localId . '"'
        . ' data-name="' . e($name) . '"'
        . ' data-debt="' . ($debt > 0 ? '1' : '0') . '"'
        . ' data-active="' . ($isActive ? '1' : '0') . '"'
        . ' data-msg-fail="' . $msgFail . '"'
        . ' data-log-id="' . $logId . '"'
        . ' data-has-days="' . ($daysInfo ? '1' : '0') . '"'
        . ' id="sas-row-' . e(preg_replace('/[^a-zA-Z0-9_-]/', '_', $username)) . '">';
    $html .= '<td class="sub-check-cell"><label class="sub-check-lab"><input type="checkbox" class="sub-check" value="' . e($username) . '"></label></td>';
    $html .= '<td class="status-cell" title="' . e($statusTitle) . '"><span class="status-sq status-' . e($statusKey) . '" aria-label="' . e($statusTitle) . '"></span></td>';
    $html .= '<td class="col-num">' . (int) $n . '</td>';
    $html .= '<td class="col-name"><a class="sub-name" href="' . e($detailUrl) . '">' . e($name) . '</a>';
    if ($localId > 0) {
        $html .= ' <span class="badge sas-offline-dot" title="' . e($lang === 'en' ? 'Has offline ledger' : 'عنده سجل أوف لاين') . '">دفتر</span>';
    }
    $html .= '</td>';
    $html .= '<td class="col-phone">' . e($phoneDisp) . '</td>';
    $html .= '<td class="col-pkg">' . e($pkgLabel) . '</td>';
    $html .= '<td class="col-days">';
    if ($daysInfo) {
        $daysLeftVal = (int) $daysInfo['left'];
        $daysCls = $daysLeftVal < 0 ? ' days-neg' : '';
        $html .= '<span class="days-num' . $daysCls . '">' . $daysLeftVal . '</span>';
    } else {
        $html .= '<span class="days-num">—</span>';
    }
    $html .= '</td>';
    $html .= '<td class="debt-cell col-debt">';
    if ($debt > 0) {
        $html .= '<span class="debt-amt debt-due">' . e(money_format_iqd($debt, $config['currency'])) . '</span>';
    } else {
        $html .= '<span class="debt-amt debt-zero">' . e(money_format_iqd(0, $config['currency'])) . '</span>';
    }
    $html .= '</td>';
    $html .= '<td class="col-month">';
    if ($localId > 0 && !empty($row['debt_months'])) {
        $first = '';
        $parts = explode(',', $row['debt_months']);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $first = function_exists('month_short_label') ? month_short_label($p, true) : $p;
                break;
            }
        }
        $extra = max(0, count(array_filter(array_map('trim', $parts))) - 1);
        $html .= '<span class="month-link">' . e($first !== '' ? $first : '-');
        if ($extra > 0) {
            $html .= '<span class="month-more">+' . (int) $extra . '</span>';
        }
        $html .= '</span>';
    } else {
        $html .= '<span class="month-link month-empty">-</span>';
    }
    $html .= '</td>';
    $html .= '<td class="msg-status-cell col-msg" title="' . e($msgShort) . '"><span class="msg-status-row">';
    if (!$hasMsg) {
        $html .= '<span class="dot-msg off"></span>';
    } elseif ($msgOk) {
        $html .= '<span class="dot-msg ok"></span>';
    } elseif ($noWa) {
        $html .= '<span class="dot-msg fail"></span><span class="msg-x">✕</span>';
    } else {
        $html .= '<span class="dot-msg fail"></span>';
    }
    $html .= '</span></td></tr>';
    return $html;
}

}
