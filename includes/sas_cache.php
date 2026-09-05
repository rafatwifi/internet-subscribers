<?php

/**
 * كاش مشتركين SAS — القراءة السريعة من النظام، والتحديث من الساس
 * لقطة محلية للحماية عند انقطاع الساس — لا تمسح ديون المشتركين
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
    try {
        $col = $pdo->query("SHOW COLUMNS FROM sas_sync_meta LIKE 'sync_started_at'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE sas_sync_meta ADD COLUMN sync_started_at DATETIME NULL DEFAULT NULL');
        }
    } catch (Exception $e) {
    }
    $extraCols = array(
        'parent_id' => 'INT UNSIGNED NULL DEFAULT NULL',
        'parent_name' => 'VARCHAR(80) NULL DEFAULT NULL',
        'city' => 'VARCHAR(120) NULL DEFAULT NULL',
        'email' => 'VARCHAR(150) NULL DEFAULT NULL',
        'company' => 'VARCHAR(150) NULL DEFAULT NULL',
        'last_online' => 'DATETIME NULL DEFAULT NULL',
        'is_online' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'daily_traffic' => 'VARCHAR(60) NULL DEFAULT NULL',
        'framed_ip' => 'VARCHAR(45) NULL DEFAULT NULL',
    );
    foreach ($extraCols as $cName => $cSql) {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM sas_users_cache LIKE " . $pdo->quote($cName))->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE sas_users_cache ADD COLUMN ' . $cName . ' ' . $cSql);
            }
        } catch (Exception $e) {
        }
    }
    try {
        $pdo->exec('ALTER TABLE sas_users_cache ADD INDEX idx_sas_disp (display_name)');
    } catch (Exception $e) {
    }
    try {
        $pdo->exec('ALTER TABLE sas_users_cache ADD INDEX idx_sas_user (username)');
    } catch (Exception $e) {
    }
    try {
        $pdo->exec('ALTER TABLE sas_users_cache ADD INDEX idx_sas_online (is_online)');
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

function sas_cache_parent_id($row)
{
    if (!is_array($row)) {
        return 0;
    }
    if (isset($row['parent_id']) && is_numeric($row['parent_id']) && (int) $row['parent_id'] > 0) {
        return (int) $row['parent_id'];
    }
    if (isset($row['parent']) && is_array($row['parent']) && isset($row['parent']['id']) && is_numeric($row['parent']['id'])) {
        return (int) $row['parent']['id'];
    }
    return 0;
}

function sas_cache_parent_name($row)
{
    if (!is_array($row)) {
        return '';
    }
    foreach (array('parent_username', 'parent_name') as $k) {
        if (!empty($row[$k]) && !is_array($row[$k])) {
            return trim((string) $row[$k]);
        }
    }
    if (isset($row['parent']) && is_array($row['parent'])) {
        if (!empty($row['parent']['username']) && !is_array($row['parent']['username'])) {
            return trim((string) $row['parent']['username']);
        }
        if (function_exists('sas_row_name')) {
            $n = sas_row_name($row['parent']);
            if ($n !== '') {
                return $n;
            }
        }
    }
    return '';
}

function sas_cache_str_field($row, $keys)
{
    if (!is_array($row) || !is_array($keys)) {
        return '';
    }
    foreach ($keys as $k) {
        if (!empty($row[$k]) && !is_array($row[$k])) {
            return trim((string) $row[$k]);
        }
    }
    return '';
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
        'expire_at', 'expiration', 'expiration_date', 'expire_date', 'expiry_date',
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

function sas_format_expire_display($expireAt)
{
    $expireAt = trim((string) $expireAt);
    if ($expireAt === '') {
        return '';
    }
    $ts = strtotime($expireAt);
    if (!$ts) {
        return $expireAt;
    }
    return date('Y-m-d H:i:s', $ts);
}

function sas_format_expire_html($expireAt)
{
    $expireAt = trim((string) $expireAt);
    if ($expireAt === '') {
        return '<span class="sas-expire-dt">-</span>';
    }
    $ts = strtotime($expireAt);
    if (!$ts) {
        return '<span class="sas-expire-dt" dir="ltr">' . e($expireAt) . '</span>';
    }
    return '<span class="sas-expire-dt" dir="ltr">'
        . '<span class="sas-exp-d">' . e(date('Y-m-d', $ts)) . '</span>'
        . '<span class="sas-exp-t">' . e(date('H:i:s', $ts)) . '</span>'
        . '</span>';
}

function sas_remaining_days($expireAt)
{
    $expireAt = trim((string) $expireAt);
    if ($expireAt === '') {
        return '';
    }
    $ts = strtotime($expireAt);
    if (!$ts) {
        return '';
    }
    $expireDay = strtotime(date('Y-m-d', $ts));
    $today = strtotime(date('Y-m-d'));
    return (string) (int) round(($expireDay - $today) / 86400);
}

function sas_cache_last_online($row)
{
    if (!is_array($row)) {
        return null;
    }
    $keys = array('last_online', 'lastOnline', 'last_seen', 'acctstarttime', 'online_since');
    foreach ($keys as $k) {
        if (!isset($row[$k]) || $row[$k] === '' || $row[$k] === null || is_array($row[$k])) {
            continue;
        }
        $v = $row[$k];
        if (is_numeric($v)) {
            $n = (int) $v;
            if ($n > 1000000000000) {
                $n = (int) floor($n / 1000);
            }
            if ($n > 1000000000 && $n < 2000000000) {
                return date('Y-m-d H:i:s', $n);
            }
        }
        $ts = strtotime((string) $v);
        if ($ts && $ts > 946684800) {
            return date('Y-m-d H:i:s', $ts);
        }
    }
    return null;
}

function sas_cache_is_online_row($row)
{
    if (!is_array($row)) {
        return 0;
    }
    foreach (array('acctsessionid', 'acct_session_id', 'acctSessionId') as $k) {
        if (!isset($row[$k]) || is_array($row[$k])) {
            continue;
        }
        $v = trim((string) $row[$k]);
        if ($v !== '' && $v !== '0') {
            return 1;
        }
    }
    foreach (array('framedipaddress', 'framed_ip_address', 'framedIPAddress') as $k) {
        if (!isset($row[$k]) || is_array($row[$k]) || $row[$k] === '' || $row[$k] === null) {
            continue;
        }
        $v = trim((string) $row[$k]);
        if ($v !== '' && $v !== '0' && $v !== '0.0.0.0') {
            return 1;
        }
    }
    return 0;
}

function sas_cache_framed_ip($row)
{
    if (!is_array($row)) {
        return '';
    }
    $nests = array($row);
    foreach (array('acct', 'session', 'radius', 'online', 'data', 'user', 'info', 'attributes') as $nk) {
        if (isset($row[$nk]) && is_array($row[$nk]) && !isset($row[$nk][0])) {
            $nests[] = $row[$nk];
        }
    }
    $keys = array(
        'framedipaddress', 'framed_ip_address', 'framedIPAddress', 'framed_ip', 'framedIp',
        'Framed-IP-Address', 'framed-ip-address',
        'ipaddress', 'ip_address', 'ipAddress', 'user_ip', 'client_ip', 'wan_ip',
        'remote_address', 'remoteAddress', 'remote_ip', 'remoteIp',
        'address', 'ip',
    );
    foreach ($nests as $block) {
        foreach ($keys as $k) {
            if (!isset($block[$k]) || is_array($block[$k])) {
                continue;
            }
            $v = trim((string) $block[$k]);
            if ($v === '' || $v === '0' || $v === '0.0.0.0' || $v === '::') {
                continue;
            }
            if (filter_var($v, FILTER_VALIDATE_IP)) {
                return $v;
            }
            if (preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $v, $m)) {
                return $m[1];
            }
        }
    }
    return '';
}

/**
 * مفاتيح مطابقة لاسم الدخول (مع/بدون @realm)
 */
function sas_cache_username_match_keys($username)
{
    $u = strtolower(trim((string) $username));
    if ($u === '') {
        return array();
    }
    $keys = array($u => true);
    if (strpos($u, '@') !== false) {
        $base = strtolower(trim(strtok($u, '@')));
        if ($base !== '') {
            $keys[$base] = true;
        }
    }
    return array_keys($keys);
}

function sas_refresh_online_flags($pdo, $config)
{
    ensure_sas_users_cache_table($pdo);
    $api = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
    if (!$api || !method_exists($api, 'listOnlineUsers')) {
        return 0;
    }
    try {
        if (method_exists($api, 'setTimeout')) {
            $api->setTimeout(18);
        }
        $rows = $api->listOnlineUsers();
    } catch (Exception $e) {
        return 0;
    }
    if (!is_array($rows)) {
        return 0;
    }
    $names = array();
    $trafMap = array();
    $ipMap = array();
    foreach ($rows as $row) {
        $u = sas_cache_username($row);
        if ($u === '') {
            continue;
        }
        $keys = sas_cache_username_match_keys($u);
        if (!$keys) {
            continue;
        }
        $ip = sas_cache_framed_ip($row);
        $tr = sas_cache_daily_traffic($row);
        foreach ($keys as $key) {
            $names[$key] = $u;
            if ($ip !== '') {
                $ipMap[$key] = sas_clip($ip, 45);
            }
            if ($tr !== '') {
                $trafMap[$key] = sas_clip($tr, 60);
            }
        }
    }
    try {
        // امسح حالة الاتصال والـ IP ثم عبّ من قائمة الأونلاين الحقيقية
        $pdo->exec('UPDATE sas_users_cache SET is_online = 0, framed_ip = NULL');
        if ($names) {
            $stExact = $pdo->prepare(
                'UPDATE sas_users_cache SET is_online = 1, last_online = NOW(), framed_ip = :ip
                 WHERE LOWER(username) = :u'
            );
            $stBase = $pdo->prepare(
                'UPDATE sas_users_cache SET is_online = 1, last_online = NOW(), framed_ip = :ip
                 WHERE LOWER(username) = :u1
                    OR LOWER(SUBSTRING_INDEX(username, "@", 1)) = :u2'
            );
            $stTrafExact = $pdo->prepare(
                'UPDATE sas_users_cache SET daily_traffic = :t WHERE LOWER(username) = :u'
            );
            $stTrafBase = $pdo->prepare(
                'UPDATE sas_users_cache SET daily_traffic = :t
                 WHERE LOWER(username) = :u1
                    OR LOWER(SUBSTRING_INDEX(username, "@", 1)) = :u2'
            );
            foreach ($names as $key => $u) {
                $ipVal = isset($ipMap[$key]) ? $ipMap[$key] : null;
                if (strpos($key, '@') !== false) {
                    $stExact->execute(array(':u' => $key, ':ip' => $ipVal));
                } else {
                    $stBase->execute(array(':u1' => $key, ':u2' => $key, ':ip' => $ipVal));
                }
                if (isset($trafMap[$key])) {
                    if (strpos($key, '@') !== false) {
                        $stTrafExact->execute(array(':u' => $key, ':t' => $trafMap[$key]));
                    } else {
                        $stTrafBase->execute(array(':u1' => $key, ':u2' => $key, ':t' => $trafMap[$key]));
                    }
                }
            }
        }
    } catch (Exception $e) {
        return 0;
    }
    return count($names);
}

function sas_cache_daily_traffic($row)
{
    if (!is_array($row)) {
        return '';
    }
    $nests = array($row);
    foreach (array('quota', 'traffic', 'stats', 'statistics', 'usage', 'acct', 'radius', 'counters') as $nk) {
        if (isset($row[$nk]) && is_array($row[$nk]) && !isset($row[$nk][0])) {
            $nests[] = $row[$nk];
        }
    }
    $textKeys = array(
        'daily_traffic', 'dailyTraffic', 'daily_total', 'dailyTotal', 'daily_usage', 'dailyUsage',
        'today_traffic', 'traffic_today', 'todayTraffic', 'used_today', 'daily_data',
        'daily', 'traf', 'totaltraffic', 'total_traffic',
    );
    foreach ($nests as $src) {
        foreach ($textKeys as $k) {
            if (!isset($src[$k]) || is_array($src[$k]) || $src[$k] === '' || $src[$k] === null) {
                continue;
            }
            $v = $src[$k];
            if (is_string($v) && preg_match('/\d/', $v) && preg_match('/[a-zA-Z]/', $v)) {
                return trim($v);
            }
            if (is_numeric($v) && (float) $v > 0) {
                return sas_format_bytes((float) $v);
            }
        }
    }
    $down = 0.0;
    $up = 0.0;
    $downKeys = array(
        'daily_download', 'daily_down', 'dailyDownload', 'today_download',
        'acctoutputoctets', 'acct_output_octets', 'output_octets', 'bytes_out',
        'totalDownload', 'total_download',
    );
    $upKeys = array(
        'daily_upload', 'daily_up', 'dailyUpload', 'today_upload',
        'acctinputoctets', 'acct_input_octets', 'input_octets', 'bytes_in',
        'totalUpload', 'total_upload',
    );
    foreach ($nests as $src) {
        foreach ($downKeys as $k) {
            if (isset($src[$k]) && is_numeric($src[$k]) && (float) $src[$k] > $down) {
                $down = (float) $src[$k];
            }
        }
        foreach ($upKeys as $k) {
            if (isset($src[$k]) && is_numeric($src[$k]) && (float) $src[$k] > $up) {
                $up = (float) $src[$k];
            }
        }
    }
    $sum = $down + $up;
    if ($sum > 0) {
        return sas_format_bytes($sum);
    }
    foreach ($nests as $src) {
        foreach ($textKeys as $k) {
            if (isset($src[$k]) && is_numeric($src[$k])) {
                return '0 MB';
            }
        }
    }
    return '';
}

function sas_format_bytes($n)
{
    $n = (float) $n;
    if ($n <= 0) {
        return '0';
    }
    if ($n < 1024 && $n !== (float) ((int) $n)) {
        return number_format($n, 2) . ' GB';
    }
    if ($n >= 1073741824) {
        return number_format($n / 1073741824, 2) . ' GB';
    }
    if ($n >= 1048576) {
        return number_format($n / 1048576, 2) . ' MB';
    }
    if ($n >= 1024 && $n < 1048576) {
        return number_format($n / 1024, 2) . ' KB';
    }
    if ($n >= 100 && $n < 1024) {
        return number_format($n, 2) . ' GB';
    }
    return number_format($n, 2);
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

    $graceDef = null; // حسب النظام
    $params = array(
        ':name' => $name,
        ':phone' => $phoneStore,
        ':notes' => 'من SAS — ' . $username,
        ':plan_id' => $planId > 0 ? $planId : null,
        ':agent_id' => $agentId > 0 ? $agentId : null,
        ':sas_u' => $username,
        ':sas_id' => $sasUserId > 0 ? $sasUserId : null,
        ':grace' => $graceDef,
    );
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO subscribers (name, phone, notes, preferred_plan_id, agent_user_id, sas_username, sas_user_id, grace_days)
             VALUES (:name, :phone, :notes, :plan_id, :agent_id, :sas_u, :sas_id, :grace)'
        );
        $stmt->execute($params);
    } catch (Exception $e) {
        unset($params[':grace']);
        $stmt = $pdo->prepare(
            'INSERT INTO subscribers (name, phone, notes, preferred_plan_id, agent_user_id, sas_username, sas_user_id)
             VALUES (:name, :phone, :notes, :plan_id, :agent_id, :sas_u, :sas_id)'
        );
        $stmt->execute($params);
    }
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
    if (isset($_SESSION['sas_profiles_ui']) && is_array($_SESSION['sas_profiles_ui'])
        && isset($_SESSION['sas_profiles_ui_at']) && (time() - (int) $_SESSION['sas_profiles_ui_at']) < 600
        && $_SESSION['sas_profiles_ui']) {
        return $_SESSION['sas_profiles_ui'];
    }
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
    if ($out) {
        $_SESSION['sas_profiles_ui'] = $out;
        $_SESSION['sas_profiles_ui_at'] = time();
    }
    return $out;
}

function sas_managers_for_ui($api)
{
    if (isset($_SESSION['sas_managers_ui']) && is_array($_SESSION['sas_managers_ui'])
        && isset($_SESSION['sas_managers_ui_at']) && (time() - (int) $_SESSION['sas_managers_ui_at']) < 600
        && $_SESSION['sas_managers_ui']) {
        return $_SESSION['sas_managers_ui'];
    }
    $out = array();
    if (!$api || !method_exists($api, 'getManagers')) {
        return $out;
    }
    $raw = $api->getManagers();
    if (function_exists('sas_response_is_error') && sas_response_is_error($raw)) {
        return $out;
    }
    $rows = is_array($raw) ? $raw : array();
    if (isset($rows['data']) && is_array($rows['data'])) {
        $rows = $rows['data'];
    }
    foreach ($rows as $m) {
        if (!is_array($m)) {
            continue;
        }
        $id = 0;
        if (isset($m['id']) && is_numeric($m['id'])) {
            $id = (int) $m['id'];
        } elseif (function_exists('sas_extract_user_id')) {
            $id = sas_extract_user_id($m);
        }
        $name = !empty($m['username']) ? (string) $m['username'] : (function_exists('sas_row_name') ? sas_row_name($m) : '');
        if ($id <= 0) {
            continue;
        }
        $out[] = array('id' => $id, 'name' => $name !== '' ? $name : ('#' . $id));
    }
    if ($out) {
        $_SESSION['sas_managers_ui'] = $out;
        $_SESSION['sas_managers_ui_at'] = time();
    }
    return $out;
}

function sas_extract_card_rows($data, $depth = 0)
{
    if ($depth > 5 || !is_array($data)) {
        return array();
    }
    $pin = '';
    foreach (array('serial', 'pin', 'code', 'card_number') as $k) {
        if (!empty($data[$k]) && !is_array($data[$k])) {
            $pin = trim((string) $data[$k]);
            break;
        }
    }
    if ($pin !== '' && !isset($data[0])) {
        return array($data);
    }
    $out = array();
    foreach (array('cards', 'unused_cards', 'pins', 'available_cards', 'data', 'items', 'rows', 'aaData') as $k) {
        if (!isset($data[$k]) || !is_array($data[$k])) {
            continue;
        }
        if (isset($data[$k][0]) || $data[$k] === array()) {
            foreach ($data[$k] as $row) {
                if (is_array($row)) {
                    $out = array_merge($out, sas_extract_card_rows($row, $depth + 1));
                }
            }
        } else {
            $out = array_merge($out, sas_extract_card_rows($data[$k], $depth + 1));
        }
    }
    if (isset($data[0]) && is_array($data[0])) {
        foreach ($data as $row) {
            if (is_array($row)) {
                $out = array_merge($out, sas_extract_card_rows($row, $depth + 1));
            }
        }
    }
    return $out;
}

function sas_pin_from_row($row)
{
    if (!is_array($row)) {
        return '';
    }
    if (!empty($row['qty']) || !empty($row['quantity'])) {
        return '';
    }
    foreach (array('pin', 'pincode', 'pin_code', 'card_number', 'serialnumber', 'serial_number', 'card_pin') as $k) {
        if (!empty($row[$k]) && !is_array($row[$k])) {
            $v = trim((string) $row[$k]);
            if ($v === '' || strpos($v, '-') !== false) {
                continue;
            }
            if (preg_match('/^\d{6,16}$/', $v)) {
                return $v;
            }
        }
    }
    return '';
}

function sas_cards_rows_to_ui($rows)
{
    $out = array();
    $seen = array();
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $pin = sas_pin_from_row($row);
        if ($pin === '') {
            continue;
        }
        $key = strtolower($pin);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = 1;
        $id = function_exists('sas_extract_user_id') ? sas_extract_user_id($row) : 0;
        $pname = sas_cache_profile_name($row);
        $rowPid = sas_cache_profile_id($row);
        $out[] = array(
            'id' => $id,
            'pin' => $pin,
            'profile_id' => $rowPid,
            'profile_name' => $pname,
            'label' => $pin . ($pname !== '' ? (' — ' . $pname) : ''),
        );
    }
    return $out;
}

function sas_group_unused_cards($cards)
{
    $groups = array();
    if (!is_array($cards)) {
        return $groups;
    }
    foreach ($cards as $c) {
        if (!is_array($c)) {
            continue;
        }
        $pid = isset($c['profile_id']) ? (int) $c['profile_id'] : 0;
        $name = !empty($c['profile_name']) ? (string) $c['profile_name'] : '';
        if ($name === '') {
            $name = $pid > 0 ? ('#' . $pid) : 'بدون باقة';
        }
        $key = $pid > 0 ? ('p' . $pid) : ('n' . $name);
        if (!isset($groups[$key])) {
            $groups[$key] = array(
                'profile_id' => $pid,
                'name' => $name,
                'count' => 0,
            );
        }
        $groups[$key]['count']++;
    }
    $groups = array_values($groups);
    usort($groups, function ($a, $b) {
        if ($a['count'] === $b['count']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return ($a['count'] > $b['count']) ? -1 : 1;
    });
    return $groups;
}

function sas_clear_unused_card_cache()
{
    unset(
        $_SESSION['sas_unused_ui'],
        $_SESSION['sas_unused_ui_at'],
        $_SESSION['sas_unused_ui_v3'],
        $_SESSION['sas_unused_ui_v3_at'],
        $_SESSION['sas_card_groups'],
        $_SESSION['sas_card_groups_at'],
        $_SESSION['sas_card_groups_v2'],
        $_SESSION['sas_card_groups_v2_at']
    );
}

function sas_unused_cards_cached($api, $force = false)
{
    $ttl = 25;
    $at = isset($_SESSION['sas_unused_ui_v3_at']) ? (int) $_SESSION['sas_unused_ui_v3_at'] : 0;
    if (!$force && $at > 0 && isset($_SESSION['sas_unused_ui_v3']) && is_array($_SESSION['sas_unused_ui_v3'])) {
        $age = time() - $at;
        $empty = !$_SESSION['sas_unused_ui_v3'];
        if ((!$empty && $age < $ttl) || ($empty && $age < 20)) {
            return $_SESSION['sas_unused_ui_v3'];
        }
    }
    $wasOpen = (session_status() === PHP_SESSION_ACTIVE);
    if ($wasOpen) {
        session_write_close();
    }
    $rows = array();
    if ($api && method_exists($api, 'listUnusedCards')) {
        $rows = $api->listUnusedCards(0, '');
    }
    $out = sas_cards_rows_to_ui($rows);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['sas_unused_ui_v3'] = $out;
    $_SESSION['sas_unused_ui_v3_at'] = time();
    unset($_SESSION['sas_unused_ui'], $_SESSION['sas_unused_ui_at']);
    $_SESSION['sas_card_groups_v2'] = sas_group_unused_cards($out);
    $_SESSION['sas_card_groups_v2_at'] = time();
    return $out;
}

function sas_profile_norm($s)
{
    $s = strtolower(trim((string) $s));
    $s = preg_replace('/[\s_\-]+/', '', $s);
    $s = preg_replace('/msl$/', '', $s);
    return $s;
}

function sas_cards_filter_cached($cards, $profileId, $profileName)
{
    $profileId = (int) $profileId;
    $profileName = trim((string) $profileName);
    if (!is_array($cards)) {
        return array();
    }
    if ($profileId <= 0 && $profileName === '') {
        return $cards;
    }
    $hit = array();
    $want = sas_profile_norm($profileName);
    foreach ($cards as $c) {
        if (!is_array($c)) {
            continue;
        }
        $pid = isset($c['profile_id']) ? (int) $c['profile_id'] : 0;
        $pn = sas_profile_norm(isset($c['profile_name']) ? $c['profile_name'] : '');
        if ($profileId > 0 && $pid === $profileId) {
            $hit[] = $c;
            continue;
        }
        if ($want !== '' && $pn !== '' && ($pn === $want || strpos($pn, $want) !== false || strpos($want, $pn) !== false)) {
            $hit[] = $c;
        }
    }
    return $hit;
}

function sas_cards_for_ui($api, $profileId, $sasUserId = 0, $profileName = '')
{
    $all = sas_unused_cards_cached($api, false);
    return sas_cards_filter_cached($all, $profileId, $profileName);
}

function sas_dash_card_groups($api)
{
    $cards = sas_unused_cards_cached($api, false);
    return sas_group_unused_cards($cards);
}

function sas_activation_quote($pdo, $config, $username, $profileId = 0)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $out = array(
        'old_sum' => 0,
        'old_lines' => array(),
        'charge' => 0,
        'currency' => $currency,
        'local_id' => 0,
    );
    $username = trim((string) $username);
    $localId = 0;
    $cache = ($username !== '' && function_exists('sas_cache_get')) ? sas_cache_get($pdo, $username) : null;
    if ($cache && !empty($cache['local_subscriber_id'])) {
        $localId = (int) $cache['local_subscriber_id'];
    }
    if ($localId <= 0 && $cache && function_exists('sas_cache_ensure_local')) {
        list($localId) = sas_cache_ensure_local($pdo, $config, $cache);
    }
    $out['local_id'] = (int) $localId;
    $profileId = (int) $profileId;
    if ($profileId <= 0 && $cache && !empty($cache['profile_id'])) {
        $profileId = (int) $cache['profile_id'];
    }
    if ($profileId > 0) {
        try {
            $pst = $pdo->prepare(
                'SELECT monthly_price FROM service_plans
                 WHERE sas_profile_id = :p AND is_active = 1 ORDER BY id ASC LIMIT 1'
            );
            $pst->execute(array(':p' => $profileId));
            $price = $pst->fetchColumn();
            if ($price !== false) {
                $out['charge'] = (float) $price;
            }
        } catch (Exception $e) {
        }
    }
    if ($localId > 0 && function_exists('subscriber_has_rental')) {
        try {
            $stR = $pdo->prepare('SELECT rental_enabled, rental_device_id FROM subscribers WHERE id = :id');
            $stR->execute(array(':id' => $localId));
            $rentRow = $stR->fetch();
            if ($rentRow && subscriber_has_rental($rentRow) && function_exists('rental_fee_amount')) {
                $out['charge'] += (float) rental_fee_amount();
            }
        } catch (Exception $e) {
        }
    }
    if ($localId > 0) {
        try {
            $st = $pdo->prepare(
                'SELECT month_label, amount, notes FROM invoices
                 WHERE subscriber_id = :id AND status = "unpaid"
                 ORDER BY due_date ASC, id ASC'
            );
            $st->execute(array(':id' => $localId));
            foreach ($st->fetchAll() as $od) {
                $lab = function_exists('month_short_label')
                    ? month_short_label($od['month_label'])
                    : (string) $od['month_label'];
                $amt = (float) $od['amount'];
                $out['old_lines'][] = array(
                    'label' => $lab,
                    'amount' => $amt,
                    'notes' => isset($od['notes']) ? (string) $od['notes'] : '',
                );
                $out['old_sum'] += $amt;
            }
        } catch (Exception $e) {
        }
    }
    return $out;
}

function sas_cpe_login_url($ip, $config = null)
{
    $ip = trim((string) $ip);
    if ($ip === '') {
        return '';
    }
    // فتح مباشر للـ IP فقط (بدون تكت / تسجيل دخول تلقائي)
    $https = is_array($config) && !empty($config['cpe_use_https']);
    $scheme = $https ? 'https' : 'http';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return $scheme . '://[' . $ip . ']/
    }
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $scheme . '://' . $ip . '/';
    }
    return '';
}

function sas_unused_cards_grouped($api)
{
    return sas_group_unused_cards(sas_unused_cards_cached($api, false));
}

function sas_dash_user_counts($pdo)
{
    ensure_sas_users_cache_table($pdo);
    $out = array(
        'total' => 0,
        'active' => 0,
        'online' => 0,
        'expired' => 0,
        'soon' => 0,
        'today' => 0,
        'disabled' => 0,
    );
    try {
        $out['total'] = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache')->fetchColumn();
        $out['disabled'] = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE enabled = 0')->fetchColumn();
        $out['online'] = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE is_online = 1')->fetchColumn();
        $out['active'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM sas_users_cache
             WHERE enabled = 1 AND expire_at IS NOT NULL AND expire_at >= NOW()'
        )->fetchColumn();
        $out['expired'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM sas_users_cache
             WHERE enabled = 1 AND (expire_at IS NULL OR expire_at < NOW())'
        )->fetchColumn();
        $out['soon'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM sas_users_cache
             WHERE enabled = 1 AND expire_at > NOW() AND expire_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)'
        )->fetchColumn();
        $out['today'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM sas_users_cache
             WHERE enabled = 1 AND DATE(expire_at) = CURDATE()'
        )->fetchColumn();
    } catch (Exception $e) {
    }
    return $out;
}

function sas_read_username_from_api($api, $sasUserId)
{
    $sasUserId = (int) $sasUserId;
    if ($sasUserId <= 0 || !$api || !method_exists($api, 'getUserById')) {
        return '';
    }
    $got = $api->getUserById($sasUserId);
    if (!is_array($got) || (function_exists('sas_response_is_error') && sas_response_is_error($got))) {
        return '';
    }
    $found = $got;
    if (sas_cache_username($got) === '') {
        if (isset($got['user']) && is_array($got['user'])) {
            $found = $got['user'];
        } elseif (isset($got['data']) && is_array($got['data'])) {
            $found = $got['data'];
            if (isset($found[0]) && is_array($found[0]) && sas_cache_username($found) === '') {
                $found = $found[0];
            }
        } elseif (isset($got[0]) && is_array($got[0])) {
            $found = $got[0];
        }
    }
    return sas_cache_username($found);
}

function sas_notify_activation_whatsapp($pdo, $config, $username, $sendOldDebts)
{
    $username = trim((string) $username);
    if ($username === '' || !function_exists('whatsapp_send') || !function_exists('activation_message')) {
        return '';
    }
    $cache = sas_cache_get($pdo, $username);
    if (!$cache) {
        return '';
    }
    list($localId, $err) = sas_cache_ensure_local($pdo, $config, $cache);
    if ($localId <= 0) {
        return $err !== '' ? $err : '';
    }
    $st = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => $localId));
    $local = $st->fetch();
    if (!$local) {
        return '';
    }
    $planName = !empty($cache['profile_name']) ? (string) $cache['profile_name'] : '';
    $price = 0.0;
    $pid = !empty($cache['profile_id']) ? (int) $cache['profile_id'] : 0;
    if ($pid > 0) {
        try {
            $pst = $pdo->prepare(
                'SELECT name, monthly_price FROM service_plans
                 WHERE sas_profile_id = :p AND is_active = 1 ORDER BY id ASC LIMIT 1'
            );
            $pst->execute(array(':p' => $pid));
            $plan = $pst->fetch();
            if ($plan) {
                if ($planName === '' && !empty($plan['name'])) {
                    $planName = (string) $plan['name'];
                }
                $price = (float) $plan['monthly_price'];
            }
        } catch (Exception $e) {
        }
    }
    $start = date('Y-m-d');
    $end = $start;
    if (!empty($cache['expire_at'])) {
        $end = date('Y-m-d', strtotime($cache['expire_at']));
    }
    $msgRow = array(
        'name' => isset($local['name']) ? $local['name'] : $username,
        'service_name' => $planName !== '' ? $planName : 'Internet',
        'start_date' => $start,
        'end_date' => $end,
        'monthly_price' => $price,
        'phone' => isset($local['phone']) ? $local['phone'] : '',
    );
    $extra = '';
    if ($sendOldDebts) {
        $oldSt = $pdo->prepare(
            'SELECT month_label, amount, notes FROM invoices
             WHERE subscriber_id = :id AND status = "unpaid"
             ORDER BY due_date ASC, id ASC'
        );
        $oldSt->execute(array(':id' => $localId));
        $oldLines = $oldSt->fetchAll();
        if ($oldLines) {
            $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
            $sum = 0.0;
            $extra .= "\n\nالديون السابقة:";
            foreach ($oldLines as $od) {
                $lab = function_exists('month_short_label') ? month_short_label($od['month_label']) : $od['month_label'];
                $amt = function_exists('money_format_iqd')
                    ? money_format_iqd($od['amount'], $currency)
                    : $od['amount'];
                $extra .= "\n• " . $lab . ': ' . $amt;
                if (!empty($od['notes'])) {
                    $extra .= ' (' . $od['notes'] . ')';
                }
                $sum += (float) $od['amount'];
            }
            $extra .= "\nإجمالي السابق: " . (function_exists('money_format_iqd')
                ? money_format_iqd($sum, $currency)
                : $sum);
        }
    }
    $msg = activation_message($msgRow, $config, $extra);
    $result = whatsapp_send($config, $local['phone'], $msg, 'activation');
    if (function_exists('log_message')) {
        log_message($pdo, $localId, $result);
    }
    if (!empty($result['success'])) {
        return '';
    }
    return function_exists('whatsapp_fail_user_message')
        ? whatsapp_fail_user_message($result)
        : 'فشل إرسال واتساب';
}

function sas_notify_plus_day_whatsapp($pdo, $config, $username, $endTs = 0)
{
    $username = trim((string) $username);
    if ($username === '' || !function_exists('whatsapp_send')) {
        return 'ماكو واتساب';
    }
    $cache = function_exists('sas_cache_get') ? sas_cache_get($pdo, $username) : null;
    $localId = 0;
    $local = null;
    if ($cache && function_exists('sas_cache_ensure_local')) {
        list($localId) = sas_cache_ensure_local($pdo, $config, $cache);
    }
    if ($localId > 0) {
        $st = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $localId));
        $local = $st->fetch();
    }
    if (!$local) {
        try {
            $stU = $pdo->prepare('SELECT * FROM subscribers WHERE sas_username = :u LIMIT 1');
            $stU->execute(array(':u' => $username));
            $local = $stU->fetch();
            if ($local && !empty($local['id'])) {
                $localId = (int) $local['id'];
            }
        } catch (Exception $e) {
            $local = null;
        }
    }
    $phone = '';
    $name = $username;
    if ($local) {
        $phone = isset($local['phone']) ? trim((string) $local['phone']) : '';
        if (!empty($local['name'])) {
            $name = (string) $local['name'];
        }
    }
    if ($phone === '' && $cache && !empty($cache['phone'])) {
        $phone = trim((string) $cache['phone']);
        if (!empty($cache['display_name'])) {
            $name = (string) $cache['display_name'];
        }
    }
    if ($phone === '') {
        return 'ماكو رقم هاتف';
    }
    $end = '';
    if ((int) $endTs > 0) {
        $end = date('Y-m-d', (int) $endTs);
    } elseif ($cache && !empty($cache['expire_at'])) {
        $end = date('Y-m-d', strtotime($cache['expire_at']));
    }
    $msg = 'مرحباً ' . $name . "\nتم إضافة +1 يوم لاشتراك الإنترنت";
    if ($end !== '') {
        $msg .= "\nينتهي بتاريخ " . $end;
    }
    $senderNote = '';
    if (isset($config['whatsapp']['sender_note'])) {
        $senderNote = trim((string) $config['whatsapp']['sender_note']);
    }
    if ($senderNote !== '') {
        $msg .= "\n" . $senderNote;
    }
    $result = whatsapp_send($config, $phone, $msg, 'plus_day');
    if (function_exists('log_message') && $localId > 0) {
        log_message($pdo, $localId, $result);
    }
    if (!empty($result['success'])) {
        return '';
    }
    return function_exists('whatsapp_fail_user_message')
        ? whatsapp_fail_user_message($result)
        : 'فشل إرسال واتساب';
}

function sas_unwrap_user_row($got)
{
    if (!is_array($got) || (function_exists('sas_response_is_error') && sas_response_is_error($got))) {
        return null;
    }
    if (isset($got['username']) && !is_array($got['username'])) {
        return $got;
    }
    if (isset($got['expiration']) || isset($got['expire_at']) || isset($got['expiry'])) {
        return $got;
    }
    foreach (array('data', 'user', 'record', 'result') as $k) {
        if (!isset($got[$k]) || !is_array($got[$k])) {
            continue;
        }
        $inner = sas_unwrap_user_row($got[$k]);
        if ($inner) {
            return $inner;
        }
    }
    if (isset($got[0]) && is_array($got[0])) {
        return sas_unwrap_user_row($got[0]);
    }
    if (!empty($got['id']) && is_numeric($got['id'])) {
        return $got;
    }
    return null;
}

function sas_live_user_row($api, $username, $sasUserId = 0)
{
    $found = null;
    $sasUserId = (int) $sasUserId;
    if ($sasUserId > 0 && $api && method_exists($api, 'getUserById')) {
        $found = sas_unwrap_user_row($api->getUserById($sasUserId));
    }
    if (!$found && $username !== '' && $api && method_exists($api, 'findUserByUsername')) {
        $found = sas_unwrap_user_row($api->findUserByUsername($username));
    }
    return is_array($found) ? $found : null;
}

function sas_row_expire_ts($row)
{
    if (!is_array($row)) {
        return 0;
    }
    $sql = function_exists('sas_cache_expire_at') ? sas_cache_expire_at($row) : null;
    if (!$sql) {
        return 0;
    }
    $ts = strtotime((string) $sql);
    return $ts ? (int) $ts : 0;
}

function sas_activate_may_force_expire($res)
{
    if (!is_array($res)) {
        return true;
    }
    if (!empty($res['__auth_error'])) {
        return false;
    }
    $msg = '';
    if (function_exists('sas_response_message')) {
        $msg = strtolower(sas_response_message($res));
    } elseif (isset($res['message'])) {
        $msg = strtolower((string) $res['message']);
    }
    if ($msg === '') {
        return true;
    }
    $block = array(
        'insufficient', 'not enough', 'no balance', 'no credit',
        'permission', 'forbidden', 'unauthorized',
        'ماكو رصيد', 'رصيد غير', 'غير كاف', 'ماكو صلاح',
    );
    foreach ($block as $n) {
        if (strpos($msg, $n) !== false) {
            return false;
        }
    }
    return true;
}

function sas_activation_took_effect($beforeTs, $afterRow)
{
    $afterTs = sas_row_expire_ts($afterRow);
    if ($afterTs <= 0) {
        return false;
    }
    $beforeTs = (int) $beforeTs;
    if ($beforeTs <= 0) {
        return $afterTs > (time() - 3600);
    }
    return ($afterTs - $beforeTs) >= (8 * 3600);
}

function sas_next_expire_sql($beforeTs, $units, $config)
{
    $units = max(1, (int) $units);
    $beforeTs = (int) $beforeTs;
    $startTs = ($beforeTs > time()) ? $beforeTs : time();
    $startDate = date('Y-m-d', $startTs);
    $endDate = $startDate;
    $i = 0;
    while ($i < $units) {
        if (function_exists('subscription_period_end')) {
            $endDate = subscription_period_end($endDate, $config);
        } else {
            $endDate = date('Y-m-d', strtotime($endDate . ' +30 days'));
        }
        $i++;
    }
    $tod = date('H:i:s', $startTs);
    if ($tod === '00:00:00') {
        $tod = date('H:i:s');
    }
    return $endDate . ' ' . $tod;
}

function sas_force_activation_expire($api, $username, $sasUserId, $beforeTs, $profileId, $units, $config)
{
    $sasUserId = (int) $sasUserId;
    if ($sasUserId <= 0 && $api && method_exists($api, 'findUserByUsername')) {
        $found = $api->findUserByUsername($username);
        if (function_exists('sas_extract_user_id')) {
            $sasUserId = sas_extract_user_id($found);
        } elseif (is_array($found) && !empty($found['id'])) {
            $sasUserId = (int) $found['id'];
        }
    }
    if ($sasUserId <= 0 || !$api || !method_exists($api, 'setUserExpiration')) {
        return array(false, null);
    }
    $expireSql = sas_next_expire_sql($beforeTs, $units, $config);
    $api->setUserExpiration($sasUserId, $expireSql, (int) $profileId);
    return sas_confirm_live_activation($api, $username, $sasUserId, $beforeTs);
}

function sas_confirm_live_activation($api, $username, $sasUserId, $beforeTs)
{
    $after = null;
    $try = 0;
    while ($try < 3) {
        if ($try > 0) {
            usleep(400000);
        }
        $after = sas_live_user_row($api, $username, $sasUserId);
        if (sas_activation_took_effect($beforeTs, $after)) {
            return array(true, $after);
        }
        $try++;
    }
    return array(false, $after);
}

function sas_finish_local_activation($pdo, $config, $username, $fields, $okMsg)
{
    $payMode = (isset($fields['pay_mode']) && $fields['pay_mode'] === 'credit') ? 'credit' : 'cash';
    $sendWa = !empty($fields['send_whatsapp']);
    $sendOld = !empty($fields['send_old_debts']);
    $localId = 0;
    $cache = function_exists('sas_cache_get') ? sas_cache_get($pdo, $username) : null;
    if ($cache && function_exists('sas_cache_ensure_local')) {
        list($localId) = sas_cache_ensure_local($pdo, $config, $cache);
    }
    if ($localId <= 0 || !function_exists('activate_one_subscriber')) {
        if ($sendWa && function_exists('sas_notify_activation_whatsapp')) {
            sas_notify_activation_whatsapp($pdo, $config, $username, $sendOld);
        }
        return 'تم تفعيل المشترك';
    }
    $planId = 0;
    $pid = isset($fields['profile_id']) ? (int) $fields['profile_id'] : 0;
    if ($pid > 0) {
        try {
            $pst = $pdo->prepare(
                'SELECT id FROM service_plans WHERE sas_profile_id = :p AND is_active = 1 ORDER BY id ASC LIMIT 1'
            );
            $pst->execute(array(':p' => $pid));
            $planId = (int) $pst->fetchColumn();
        } catch (Exception $e) {
        }
    }
    $waTpl = '';
    if (function_exists('wa_case_template_key')) {
        $actCase = ($payMode === 'credit') ? 'activation_credit' : 'activation_cash';
        $waTpl = wa_case_template_key($config, $actCase);
    }
    list($lok, $lmsg) = activate_one_subscriber($pdo, $config, $localId, array(
        'plan_id' => $planId,
        'pay_mode' => $payMode,
        'send_whatsapp' => $sendWa,
        'send_old_debts' => $sendOld,
        'skip_sas' => true,
        'skip_grace' => true,
        'simple_msg' => true,
        'carry_days' => true,
        'wa_template' => $waTpl !== '' ? $waTpl : (($payMode === 'credit') ? 'activation_credit' : 'activation'),
    ));
    if (!$lok && $lmsg !== '') {
        return 'تم تفعيل المشترك — محلي: ' . $lmsg;
    }
    return 'تم تفعيل المشترك';
}

function sas_write_user($pdo, $config, $action, $username, $fields)
{
    $username = trim((string) $username);
    $fields = is_array($fields) ? $fields : array();
    $api = sas_page_connector($config);
    if (!$api) {
        return array(false, 'تعذر الدخول للساس', array());
    }
    $cache = $username !== '' ? sas_cache_get($pdo, $username) : null;
    $sasUserId = ($cache && !empty($cache['sas_user_id'])) ? (int) $cache['sas_user_id'] : 0;

    if ($action !== 'sas_create') {
        if (!$cache) {
            return array(false, 'المشترك مو موجود بكاش SAS — حدّث القائمة', array());
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
        if ($sasUserId <= 0 && $action !== 'sas_activate_card' && $action !== 'sas_activate_credit' && $action !== 'sas_activate_reward') {
            return array(false, 'ماكو رقم مستخدم بالساس لهذا المشترك', array());
        }
    }

    if ($action === 'sas_create') {
        $newUser = isset($fields['username']) ? trim((string) $fields['username']) : '';
        $pass = isset($fields['password']) ? (string) $fields['password'] : '';
        $confirm = isset($fields['confirm_password']) ? (string) $fields['confirm_password'] : $pass;
        $profileId = isset($fields['profile_id']) ? (int) $fields['profile_id'] : 0;
        $parentId = isset($fields['parent_id']) ? (int) $fields['parent_id'] : 0;
        if ($newUser === '' || $pass === '' || $profileId <= 0) {
            return array(false, 'اليوزرنيم وكلمة السر والبروفايل مطلوبة', array());
        }
        if ($pass !== $confirm) {
            return array(false, 'تأكيد كلمة السر غير مطابق', array());
        }
        if ($parentId <= 0 && function_exists('sas_config')) {
            $sc = sas_config($config);
            $parentId = isset($sc['parent_id']) ? (int) $sc['parent_id'] : 0;
        }
        $payload = array(
            'username' => $newUser,
            'password' => $pass,
            'confirm_password' => $confirm,
            'profile_id' => $profileId,
            'parent_id' => $parentId,
            'firstname' => isset($fields['firstname']) ? trim((string) $fields['firstname']) : '',
            'lastname' => isset($fields['lastname']) ? trim((string) $fields['lastname']) : '',
            'phone' => isset($fields['phone']) ? trim((string) $fields['phone']) : '',
            'city' => isset($fields['city']) ? trim((string) $fields['city']) : '',
            'email' => isset($fields['email']) ? trim((string) $fields['email']) : '',
            'company' => isset($fields['company']) ? trim((string) $fields['company']) : '',
            'enabled' => (isset($fields['enabled']) && (string) $fields['enabled'] === '0') ? 0 : 1,
        );
        $res = $api->createUser($payload);
        if (function_exists('sas_response_success') && !sas_response_success($res)) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل إنشاء المشترك'), array());
        }
        sas_cache_upsert_row($pdo, array_merge($payload, is_array($res) ? $res : array()));
        $found = method_exists($api, 'findUserByUsername') ? $api->findUserByUsername($newUser) : null;
        if (is_array($found)) {
            sas_cache_upsert_row($pdo, $found);
        }
        return array(true, 'تم إنشاء المشترك بالساس', array('username' => $newUser));
    }

    if ($action === 'sas_inline' || $action === 'sas_update_info') {
        $firstname = isset($fields['firstname']) ? trim((string) $fields['firstname']) : '';
        $lastname = isset($fields['lastname']) ? trim((string) $fields['lastname']) : '';
        $name = isset($fields['name']) ? trim((string) $fields['name']) : '';
        $phoneIn = isset($fields['phone']) ? trim((string) $fields['phone']) : '';
        $newUser = isset($fields['username']) ? trim((string) $fields['username']) : '';
        $field = isset($fields['field']) ? (string) $fields['field'] : '';
        $value = isset($fields['value']) ? trim((string) $fields['value']) : '';
        if ($action === 'sas_inline' && $field === 'name') {
            $name = $value;
        }
        if ($action === 'sas_inline' && $field === 'phone') {
            $phoneIn = $value;
        }
        if ($action === 'sas_inline' && $field === 'firstname') {
            $firstname = $value;
            $fields['firstname'] = $value;
        }
        if ($action === 'sas_inline' && $field === 'lastname') {
            $lastname = $value;
            $fields['lastname'] = $value;
        }
        if ($action === 'sas_inline' && $field === 'username') {
            $newUser = $value;
        }
        if ($firstname === '' && $lastname === '' && $name !== '') {
            list($firstname, $lastname) = function_exists('sas_split_name') ? sas_split_name($name) : array($name, '');
        }
        $payload = array();
        if ($firstname !== '' || isset($fields['firstname'])) {
            $payload['firstname'] = $firstname;
        }
        if ($lastname !== '' || isset($fields['lastname'])) {
            $payload['lastname'] = $lastname;
        }
        if ($phoneIn !== '' || ($action === 'sas_inline' && $field === 'phone')) {
            if ($phoneIn === '') {
                $payload['phone'] = '';
            } else {
                $phone = function_exists('normalize_phone') ? normalize_phone($phoneIn) : $phoneIn;
                if ($phone === '') {
                    $phone = $phoneIn;
                }
                $payload['phone'] = function_exists('format_phone_display') ? format_phone_display($phone) : $phone;
            }
        }
        if (isset($fields['enabled']) && ($fields['enabled'] === '0' || $fields['enabled'] === '1' || $fields['enabled'] === 0 || $fields['enabled'] === 1)) {
            $payload['enabled'] = ((string) $fields['enabled'] === '1' || $fields['enabled'] === 1) ? 1 : 0;
        }
        if (isset($fields['profile_id']) && (int) $fields['profile_id'] > 0) {
            $payload['profile_id'] = (int) $fields['profile_id'];
        }
        if (isset($fields['parent_id']) && (int) $fields['parent_id'] > 0) {
            $payload['parent_id'] = (int) $fields['parent_id'];
        }
        foreach (array('city', 'email', 'company') as $k) {
            if (isset($fields[$k])) {
                $payload[$k] = trim((string) $fields[$k]);
            }
        }
        $pass = isset($fields['password']) ? (string) $fields['password'] : '';
        $confirm = isset($fields['confirm_password']) ? (string) $fields['confirm_password'] : '';
        if ($pass !== '') {
            if ($confirm !== '' && $pass !== $confirm) {
                return array(false, 'تأكيد كلمة السر غير مطابق', array());
            }
            $payload['password'] = $pass;
            $payload['confirm_password'] = $confirm !== '' ? $confirm : $pass;
        }
        $wantRename = ($newUser !== '' && strcasecmp($newUser, $username) !== 0);
        if (!$payload && !$wantRename) {
            return array(false, 'ماكو تعديلات', array());
        }
        $renamedOk = false;
        if ($wantRename) {
            global $lang;
            $lockMsg = (isset($lang) && $lang === 'en')
                ? 'SAS does not allow changing the username'
                : 'الساس ما يسمح بتغيير اسم الدخول';
            if (!method_exists($api, 'renameUser')) {
                return array(false, $lockMsg, array('username' => $username));
            }
            $ren = $api->renameUser($sasUserId, $username, $newUser);
            if (function_exists('sas_response_success') && !sas_response_success($ren)) {
                return array(false, 'SAS: تعذر تغيير اليوزرنيم — ' . (function_exists('sas_response_message') ? sas_response_message($ren) : 'فشل إعادة التسمية'), array());
            }
            $actual = function_exists('sas_read_username_from_api')
                ? sas_read_username_from_api($api, $sasUserId)
                : '';
            if ($actual === '' || strcasecmp($actual, $newUser) !== 0) {
                return array(false, $lockMsg, array('username' => $username));
            }
            $payload['username'] = $newUser;
            $renamedOk = true;
        }
        if ($payload) {
            $res = $api->updateUser($sasUserId, $payload);
            if (function_exists('sas_response_success') && !sas_response_success($res)) {
                return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل الحفظ'), array());
            }
        }
        $patch = array();
        if (isset($payload['firstname']) || isset($payload['lastname'])) {
            $patch['firstname'] = $firstname;
            $patch['lastname'] = $lastname;
            $disp = trim($firstname . ' ' . $lastname);
            $patch['display_name'] = $disp !== '' ? $disp : $username;
        }
        if (isset($payload['phone'])) {
            $patch['phone'] = $payload['phone'];
        }
        if (isset($payload['enabled'])) {
            $patch['enabled'] = $payload['enabled'];
        }
        if (isset($payload['profile_id'])) {
            $patch['profile_id'] = $payload['profile_id'];
            if (!empty($fields['profile_name'])) {
                $patch['profile_name'] = trim((string) $fields['profile_name']);
            }
        }
        if (isset($payload['parent_id'])) {
            $patch['parent_id'] = $payload['parent_id'];
            if (!empty($fields['parent_name'])) {
                $patch['parent_name'] = trim((string) $fields['parent_name']);
            }
        }
        foreach (array('city', 'email', 'company') as $k) {
            if (isset($payload[$k])) {
                $patch[$k] = $payload[$k] !== '' ? $payload[$k] : null;
            }
        }
        if ($patch) {
            sas_cache_patch($pdo, $username, $patch);
        }
        if ($renamedOk) {
            try {
                $pdo->prepare('UPDATE sas_users_cache SET username = :n WHERE username = :o')
                    ->execute(array(':n' => $newUser, ':o' => $username));
            } catch (Exception $e) {
                return array(false, 'تم الحفظ بالساس لكن تعذر تحديث اليوزرنيم بالكاش: ' . $e->getMessage(), array('username' => $newUser));
            }
            $username = $newUser;
        }
        if ($action !== 'sas_inline') {
            sas_cache_refresh_one($pdo, $config, $username, $sasUserId);
        }
        $outVal = '';
        if ($field === 'username') {
            $outVal = $username;
        } elseif ($field === 'firstname') {
            $outVal = $firstname !== '' ? $firstname : $value;
        } elseif ($field === 'lastname') {
            $outVal = $lastname !== '' ? $lastname : $value;
        } elseif ($field === 'phone') {
            $outVal = isset($payload['phone']) ? $payload['phone'] : $value;
        } elseif ($field === 'name') {
            $outVal = isset($patch['display_name']) ? $patch['display_name'] : $value;
        } else {
            $outVal = $value;
        }
        return array(true, 'تم الحفظ بالساس', array(
            'username' => $username,
            'value' => $outVal,
            'raw' => $outVal,
        ));
    }

    if ($action === 'sas_enable') {
        $on = (isset($fields['enabled']) && (string) $fields['enabled'] === '1') ? 1 : 0;
        $res = $api->setUserEnabled($sasUserId, $on);
        if (function_exists('sas_response_success') && !sas_response_success($res)) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل تغيير الحالة'), array());
        }
        sas_cache_patch($pdo, $username, array('enabled' => $on));
        sas_cache_refresh_one($pdo, $config, $username, $sasUserId);
        $fresh = sas_cache_get($pdo, $username);
        $en = $fresh && isset($fresh['enabled']) ? (int) $fresh['enabled'] : $on;
        $expire = ($fresh && !empty($fresh['expire_at'])) ? (string) $fresh['expire_at'] : '';
        $online = $fresh && !empty($fresh['is_online']);
        $isActive = $en && $expire !== '' && strtotime($expire) >= time();
        return array(true, $on ? 'تم تشغيل المشترك بالساس' : 'تم إيقاف المشترك بالساس', array(
            'enabled' => $en,
            'expire_at' => $expire,
            'is_online' => $online ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
        ));
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
        $beforeTs = ($cache && !empty($cache['expire_at'])) ? strtotime((string) $cache['expire_at']) : 0;
        if ($beforeTs <= 0) {
            $beforeTs = sas_row_expire_ts(sas_live_user_row($api, $username, $sasUserId));
        }
        $res = $api->activateUserCredit($username, $profileId, $units, $sasUserId);
        if (is_array($res) && !empty($res['__auth_error'])) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل الدخول'), array());
        }
        list($confirmed, $afterRow) = sas_confirm_live_activation($api, $username, $sasUserId, $beforeTs);
        if (!$confirmed && sas_activate_may_force_expire($res)) {
            list($confirmed, $afterRow) = sas_force_activation_expire(
                $api,
                $username,
                $sasUserId,
                $beforeTs,
                $profileId,
                $units,
                $config
            );
        }
        if (!$confirmed) {
            $afterSql = $afterRow ? sas_cache_expire_at($afterRow) : '';
            $beforeSql = $beforeTs > 0 ? date('Y-m-d H:i', $beforeTs) : '-';
            return array(
                false,
                'الساس ما فعّل المشترك (التاريخ ما تغير: ' . $beforeSql . ' → ' . ($afterSql ? $afterSql : '-') . '). ما تم تسجيل المبلغ.',
                array()
            );
        }
        if ($afterRow) {
            sas_cache_upsert_row($pdo, $afterRow);
        } else {
            sas_cache_refresh_one($pdo, $config, $username, $sasUserId);
        }
        sas_clear_unused_card_cache();
        $okMsg = sas_finish_local_activation($pdo, $config, $username, $fields, 'تم تفعيل المشترك');
        return array(true, $okMsg, array());
    }

    if ($action === 'sas_activate_reward') {
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
        $beforeTs = ($cache && !empty($cache['expire_at'])) ? strtotime((string) $cache['expire_at']) : 0;
        if ($beforeTs <= 0) {
            $beforeTs = sas_row_expire_ts(sas_live_user_row($api, $username, $sasUserId));
        }
        if (!method_exists($api, 'activateUserReward')) {
            return array(false, 'ارفع ملف SASConnector المحدّث للتفعيل بالنقاط', array());
        }
        $res = $api->activateUserReward($username, $profileId, $units, $sasUserId);
        if (is_array($res) && !empty($res['__auth_error'])) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل الدخول'), array());
        }
        list($confirmed, $afterRow) = sas_confirm_live_activation($api, $username, $sasUserId, $beforeTs);
        if (!$confirmed && sas_activate_may_force_expire($res)) {
            list($confirmed, $afterRow) = sas_force_activation_expire(
                $api,
                $username,
                $sasUserId,
                $beforeTs,
                $profileId,
                $units,
                $config
            );
        }
        if (!$confirmed) {
            $afterSql = $afterRow ? sas_cache_expire_at($afterRow) : '';
            $beforeSql = $beforeTs > 0 ? date('Y-m-d H:i', $beforeTs) : '-';
            return array(
                false,
                'الساس ما فعّل المشترك (التاريخ ما تغير: ' . $beforeSql . ' → ' . ($afterSql ? $afterSql : '-') . '). ما تم تسجيل المبلغ.',
                array()
            );
        }
        if ($afterRow) {
            sas_cache_upsert_row($pdo, $afterRow);
        } else {
            sas_cache_refresh_one($pdo, $config, $username, $sasUserId);
        }
        $okMsg = sas_finish_local_activation($pdo, $config, $username, $fields, 'تم تفعيل المشترك');
        return array(true, $okMsg, array());
    }

    if ($action === 'sas_activate_card') {
        $pin = isset($fields['pin']) ? trim((string) $fields['pin']) : '';
        $cardId = isset($fields['card_id']) ? (int) $fields['card_id'] : 0;
        if ($pin === '' && $cardId <= 0) {
            return array(false, 'اختر كرت غير مستخدم', array());
        }
        $profileId = isset($fields['profile_id']) ? (int) $fields['profile_id'] : 0;
        $beforeTs = ($cache && !empty($cache['expire_at'])) ? strtotime((string) $cache['expire_at']) : 0;
        if ($beforeTs <= 0) {
            $beforeTs = sas_row_expire_ts(sas_live_user_row($api, $username, $sasUserId));
        }
        $res = $api->activateUserCard(
            $username,
            $pin !== '' ? $pin : (string) $cardId,
            $sasUserId,
            $cardId,
            $profileId
        );
        if (is_array($res) && !empty($res['__auth_error'])) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل الدخول'), array());
        }
        list($confirmed, $afterRow) = sas_confirm_live_activation($api, $username, $sasUserId, $beforeTs);
        if (!$confirmed && sas_activate_may_force_expire($res)) {
            list($confirmed, $afterRow) = sas_force_activation_expire(
                $api,
                $username,
                $sasUserId,
                $beforeTs,
                $profileId,
                1,
                $config
            );
        }
        if (!$confirmed) {
            $afterSql = $afterRow ? sas_cache_expire_at($afterRow) : '';
            $beforeSql = $beforeTs > 0 ? date('Y-m-d H:i', $beforeTs) : '-';
            return array(
                false,
                'الساس ما فعّل المشترك (التاريخ ما تغير: ' . $beforeSql . ' → ' . ($afterSql ? $afterSql : '-') . '). ما تم تسجيل المبلغ.',
                array()
            );
        }
        if ($afterRow) {
            sas_cache_upsert_row($pdo, $afterRow);
        } else {
            sas_cache_refresh_one($pdo, $config, $username, $sasUserId);
        }
        sas_clear_unused_card_cache();
        $okMsg = sas_finish_local_activation($pdo, $config, $username, $fields, 'تم تفعيل المشترك');
        return array(true, $okMsg, array());
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
                 enabled, expire_at, parent_id, parent_name, city, email, company, last_online, is_online, framed_ip, daily_traffic, local_subscriber_id, synced_at)
             VALUES
                (:username, :sas_user_id, :firstname, :lastname, :display_name, :phone, :profile_id, :profile_name,
                 :enabled, :expire_at, :parent_id, :parent_name, :city, :email, :company, :last_online, :is_online, :framed_ip, :daily_traffic, :local_subscriber_id, :synced_at)
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
                parent_id = VALUES(parent_id),
                parent_name = VALUES(parent_name),
                city = VALUES(city),
                email = VALUES(email),
                company = VALUES(company),
                last_online = IF(VALUES(last_online) IS NULL, last_online, VALUES(last_online)),
                is_online = IF(VALUES(is_online) = 1, 1, is_online),
                framed_ip = IF(VALUES(framed_ip) IS NULL OR VALUES(framed_ip) = "", framed_ip, VALUES(framed_ip)),
                daily_traffic = IF(VALUES(daily_traffic) IS NULL, daily_traffic, VALUES(daily_traffic)),
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
            ':parent_id' => ($parId = sas_cache_parent_id($row)) > 0 ? $parId : null,
            ':parent_name' => (($parN = sas_cache_parent_name($row)) !== '' ? sas_clip($parN, 80) : null),
            ':city' => (($city = sas_cache_str_field($row, array('city'))) !== '' ? sas_clip($city, 120) : null),
            ':email' => (($em = sas_cache_str_field($row, array('email'))) !== '' ? sas_clip($em, 150) : null),
            ':company' => (($co = sas_cache_str_field($row, array('company'))) !== '' ? sas_clip($co, 150) : null),
            ':last_online' => sas_cache_last_online($row),
            ':is_online' => sas_cache_is_online_row($row),
            ':framed_ip' => (($ip = sas_cache_framed_ip($row)) !== '' ? sas_clip($ip, 45) : null),
            ':daily_traffic' => (($tr = sas_cache_daily_traffic($row)) !== '' ? sas_clip($tr, 60) : null),
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
        'parent_id', 'parent_name', 'city', 'email', 'company', 'last_online', 'is_online', 'daily_traffic',
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

function sas_cache_pull_search($pdo, $config, $q)
{
    $q = trim((string) $q);
    if ($q === '' || strlen($q) < 2) {
        return 0;
    }
    if (!function_exists('sas_is_ready') || !sas_is_ready($config)) {
        return 0;
    }
    $api = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
    if (!$api || !method_exists($api, 'listUsersPage')) {
        return 0;
    }
    try {
        if (method_exists($api, 'setTimeout')) {
            $api->setTimeout(12);
        }
        $page = $api->listUsersPage(0, 50, $q);
    } catch (Exception $e) {
        return 0;
    }
    $n = 0;
    if (!empty($page['rows']) && is_array($page['rows'])) {
        foreach ($page['rows'] as $row) {
            if (sas_cache_upsert_row($pdo, $row)) {
                $n++;
            }
        }
    }
    return $n;
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
            'sync_started_at' => date('Y-m-d H:i:s'),
        ));
        $meta = sas_sync_meta($pdo);
    } elseif ($offset <= 0) {
        sas_sync_meta_save($pdo, array(
            'sync_started_at' => date('Y-m-d H:i:s'),
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
                 enabled, expire_at, parent_id, parent_name, city, email, company, last_online, is_online, framed_ip, daily_traffic, local_subscriber_id, synced_at)
             VALUES
                (:username, :sas_user_id, :firstname, :lastname, :display_name, :phone, :profile_id, :profile_name,
                 :enabled, :expire_at, :parent_id, :parent_name, :city, :email, :company, :last_online, :is_online, :framed_ip, :daily_traffic, :local_subscriber_id, :synced_at)
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
                parent_id = VALUES(parent_id),
                parent_name = VALUES(parent_name),
                city = VALUES(city),
                email = VALUES(email),
                company = VALUES(company),
                last_online = IF(VALUES(last_online) IS NULL, last_online, VALUES(last_online)),
                is_online = IF(VALUES(is_online) = 1, 1, is_online),
                framed_ip = IF(VALUES(framed_ip) IS NULL OR VALUES(framed_ip) = "", framed_ip, VALUES(framed_ip)),
                daily_traffic = IF(VALUES(daily_traffic) IS NULL, daily_traffic, VALUES(daily_traffic)),
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
        if (!$rows && $pageNum <= 1) {
            $existing = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache')->fetchColumn();
            if ($existing > 0) {
                sas_sync_meta_save($pdo, array(
                    'syncing_at' => null,
                    'last_error' => 'الساس رجّع قائمة فارغة — ما مسحنا الكاش',
                ));
                return array(false, 0, 'error', sas_sync_meta($pdo));
            }
        }
        $done = !empty($page['complete'])
            || !$rows
            || ($expected > $pageSize && (($pageNum * $pageSize) >= $expected))
            || $pageNum >= 400;
        $totalNow = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache')->fetchColumn();

        if ($done) {
            $started = isset($meta['sync_started_at']) ? $meta['sync_started_at'] : '';
            if ($started === '' && !empty($meta['last_try_at'])) {
                $started = $meta['last_try_at'];
            }
            if ($started !== '') {
                try {
                    $freshSt = $pdo->prepare('SELECT COUNT(*) FROM sas_users_cache WHERE synced_at >= :t');
                    $freshSt->execute(array(':t' => $started));
                    $freshCnt = (int) $freshSt->fetchColumn();
                    $need = ($expected > 0) ? max(1, (int) ceil($expected * 0.5)) : 1;
                    if ($freshCnt >= $need) {
                        $del = $pdo->prepare('DELETE FROM sas_users_cache WHERE synced_at < :t');
                        $del->execute(array(':t' => $started));
                    }
                } catch (Exception $e) {
                }
            }
            $totalNow = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache')->fetchColumn();
            try {
                sas_refresh_online_flags($pdo, $config);
            } catch (Exception $e) {
            }
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
        OR CONCAT(IFNULL(c.firstname,""), " ", IFNULL(c.lastname,"")) LIKE :q
        OR CONCAT(IFNULL(c.lastname,""), " ", IFNULL(c.firstname,"")) LIKE :q
        OR REPLACE(REPLACE(IFNULL(c.display_name,""), " ", ""), "-", "") LIKE :qns
    ';
    $params[':q'] = '%' . $q . '%';
    $params[':qns'] = '%' . preg_replace('/[\s\-]+/', '', $q) . '%';
    if ($qDigits !== '') {
        $where .= ' OR c.username LIKE :qd OR c.phone LIKE :qd';
        $params[':qd'] = '%' . $qDigits . '%';
    }
    $rentIds = function_exists('rental_device_ids_matching_query')
        ? rental_device_ids_matching_query($q)
        : array();
    if ($rentIds) {
        $in = array();
        foreach ($rentIds as $i => $rid) {
            $key = ':rd' . $i;
            $in[] = $key;
            $params[$key] = $rid;
        }
        $where .= ' OR (s.rental_enabled = 1 AND s.rental_device_id IN (' . implode(',', $in) . '))';
    }
    $where .= ')';
    return $where;
}

function sas_cache_filter_sql($subFilter)
{
    if ($subFilter === 'active') {
        return ' AND c.enabled = 1 AND c.expire_at IS NOT NULL AND c.expire_at >= NOW()';
    }
    if ($subFilter === 'online') {
        return ' AND c.is_online = 1';
    }
    if ($subFilter === 'disabled') {
        return ' AND c.enabled = 0';
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
    if ($subFilter === 'rental') {
        return ' AND s.rental_enabled = 1 AND s.rental_device_id IS NOT NULL AND s.rental_device_id <> ""';
    }
    return '';
}

function sas_sql_username_eq($leftExpr, $rightExpr)
{
    return 'CONVERT(' . $leftExpr . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci'
        . ' = CONVERT(' . $rightExpr . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci';
}

function sas_cache_list_from_sql()
{
    $userEq = sas_sql_username_eq('s.sas_username', 'c.username');
    return ' FROM sas_users_cache c
     LEFT JOIN subscribers s ON (
        s.id = c.local_subscriber_id
        OR (c.local_subscriber_id IS NULL AND ' . $userEq . ')
     )';
}

function sas_cache_list_select_sql($light = false)
{
    $sql = 'SELECT c.*,
        s.id AS local_id,
        s.name AS local_name,
        s.phone AS local_phone,
        s.rental_enabled AS rental_enabled,
        s.rental_device_id AS rental_device_id,
        s.address AS local_address,
        s.notes AS local_notes,
        s.grace_days AS grace_days,
        (SELECT COALESCE(SUM(amount),0) FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt';
    if ($light) {
        $sql .= ',
        NULL AS debt_months,
        NULL AS last_msg_ok,
        NULL AS last_msg_type,
        NULL AS last_msg_body,
        NULL AS last_msg_response,
        NULL AS last_msg_at,
        NULL AS last_msg_id';
        return $sql;
    }
    $sql .= ',
        (SELECT GROUP_CONCAT(DISTINCT i.month_label ORDER BY i.month_label SEPARATOR \',\')
            FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt_months,
        (SELECT m.success FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_ok,
        (SELECT m.message_type FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_type,
        (SELECT m.body FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_body,
        (SELECT m.response_json FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_response,
        (SELECT m.created_at FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_at,
        (SELECT m.id FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_id';
    return $sql;
}

function sas_status_squares_html($enabled, $isOnline, $isActive)
{
    if (!$enabled) {
        return '<span class="status-sq status-left"></span>';
    }
    if ($isOnline && $isActive) {
        return '<span class="status-sq status-online"></span>';
    }
    if ($isOnline) {
        return '<span class="status-sq status-expired-online"></span>';
    }
    if ($isActive) {
        return '<span class="status-sq status-active"></span>';
    }
    return '<span class="status-sq status-expired"></span>';
}

function sas_render_table_row($row, $n, $config, $lang)
{
    $username = isset($row['username']) ? (string) $row['username'] : '';
    $sasId = !empty($row['sas_user_id']) ? (int) $row['sas_user_id'] : 0;
    $profileId = !empty($row['profile_id']) ? (int) $row['profile_id'] : 0;
    $fn = !empty($row['firstname']) ? (string) $row['firstname'] : '';
    $ln = !empty($row['lastname']) ? (string) $row['lastname'] : '';
    $name = isset($row['display_name']) && $row['display_name'] !== ''
        ? (string) $row['display_name']
        : ($fn !== '' ? trim($fn . ' ' . $ln) : $username);
    $phone = isset($row['phone']) && $row['phone'] !== '' ? (string) $row['phone'] : '';
    $localId = !empty($row['local_id']) ? (int) $row['local_id'] : (!empty($row['local_subscriber_id']) ? (int) $row['local_subscriber_id'] : 0);
    $debt = isset($row['debt']) ? (float) $row['debt'] : 0.0;
    $enabled = isset($row['enabled']) ? (int) $row['enabled'] : 1;
    $expireAt = !empty($row['expire_at']) ? $row['expire_at'] : '';
    $hasExpire = $expireAt !== '';
    $isActive = $enabled && $hasExpire && strtotime($expireAt) >= time();
    $isOnline = !empty($row['is_online']);
    $currency = isset($config['currency']) ? $config['currency'] : 'IQD';

    if (!$enabled) {
        $rowClass = 'row-status-left';
        $statusTitle = $lang === 'en' ? 'Disabled' : 'معطل';
    } elseif ($isOnline && $isActive) {
        $rowClass = 'row-status-active';
        $statusTitle = $lang === 'en' ? 'Active + connected' : 'فعال ومتصل';
    } elseif ($isOnline) {
        $rowClass = 'row-status-expired';
        $statusTitle = $lang === 'en' ? 'Expired + connected' : 'منتهي ومتصل';
    } elseif ($isActive) {
        $rowClass = 'row-status-active';
        $statusTitle = $lang === 'en' ? 'Active' : 'فعال';
    } else {
        $rowClass = 'row-status-expired';
        $statusTitle = $lang === 'en' ? 'Expired' : 'منتهي';
    }
    $statusHtml = sas_status_squares_html($enabled, $isOnline, $isActive);

    $daysLeft = '';
    if ($hasExpire) {
        $daysLeft = function_exists('sas_remaining_days')
            ? sas_remaining_days($expireAt)
            : (string) (int) round((strtotime(date('Y-m-d', strtotime($expireAt))) - strtotime(date('Y-m-d'))) / 86400);
    }
    $pkgLabel = !empty($row['profile_name']) ? $row['profile_name'] : '-';
    $parentName = !empty($row['parent_name']) ? $row['parent_name'] : '-';
    $expireDisp = '-';
    if ($hasExpire) {
        $expireDisp = function_exists('sas_format_expire_display')
            ? sas_format_expire_display($expireAt)
            : date('Y-m-d H:i:s', strtotime($expireAt));
        if ($expireDisp === '') {
            $expireDisp = '-';
        }
    }
    $traf = (isset($row['daily_traffic']) && $row['daily_traffic'] !== null && $row['daily_traffic'] !== '')
        ? (string) $row['daily_traffic'] : '-';
    $framedIp = '';
    if ($isOnline && isset($row['framed_ip']) && $row['framed_ip'] !== null) {
        $framedIp = trim((string) $row['framed_ip']);
    }
    $ipDisp = ($isOnline && $framedIp !== '') ? $framedIp : '-';
    $detailUrl = function_exists('sas_user_url') ? sas_user_url($username) : ('sas_user.php?u=' . rawurlencode($username));
    $editTip = $lang === 'en' ? 'Click to edit' : 'اضغط للتعديل';
    $rentSub = array(
        'rental_enabled' => isset($row['rental_enabled']) ? $row['rental_enabled'] : 0,
        'rental_device_id' => isset($row['rental_device_id']) ? $row['rental_device_id'] : '',
    );
    $rentDevName = '';
    if (function_exists('subscriber_has_rental') && subscriber_has_rental($rentSub) && function_exists('rental_device_by_id')) {
        $rentDev = rental_device_by_id($rentSub['rental_device_id']);
        if ($rentDev) {
            $rentDevName = $rentDev['name'];
        }
    }
    $searchText = strtolower($name . ' ' . $username . ' ' . $fn . ' ' . $ln . ' ' . $phone . ' ' . $pkgLabel
        . ' ' . $rentDevName . ' ' . (isset($row['rental_device_id']) ? $row['rental_device_id'] : '')
        . ' ' . $ipDisp
        . ' ' . (isset($row['local_address']) ? $row['local_address'] : '')
        . ' ' . (isset($row['local_notes']) ? $row['local_notes'] : ''));

    $hasMsg = isset($row['last_msg_at']) && $row['last_msg_at'] !== null && $row['last_msg_at'] !== '';
    $msgOk = $hasMsg && !empty($row['last_msg_ok']);
    $msgResp = isset($row['last_msg_response']) ? $row['last_msg_response'] : '';
    $noWa = $hasMsg && !$msgOk && function_exists('subscriber_msg_is_no_whatsapp')
        && subscriber_msg_is_no_whatsapp($msgResp);
    $msgFail = ($hasMsg && !$msgOk && !$noWa) ? '1' : '0';
    $logId = (!empty($row['last_msg_id'])) ? (int) $row['last_msg_id'] : 0;

    $html = '<tr class="' . e($rowClass) . '"'
        . ' data-search="' . e($searchText) . '"'
        . ' data-id="' . e($username) . '"'
        . ' data-sas-id="' . $sasId . '"'
        . ' data-profile-id="' . $profileId . '"'
        . ' data-profile-name="' . e($pkgLabel) . '"'
        . ' data-enabled="' . ($enabled ? '1' : '0') . '"'
        . ' data-local-id="' . $localId . '"'
        . ' data-name="' . e($name) . '"'
        . ' data-firstname="' . e($fn) . '"'
        . ' data-username="' . e($username) . '"'
        . ' data-debt="' . ($debt > 0 ? '1' : '0') . '"'
        . ' data-active="' . ($isActive ? '1' : '0') . '"'
        . ' data-msg-fail="' . $msgFail . '"'
        . ' data-log-id="' . $logId . '"'
        . ' data-has-days="' . ($hasExpire ? '1' : '0') . '"'
        . ' data-rental="' . e(isset($row['rental_device_id']) ? (string) $row['rental_device_id'] : '') . '"'
        . ' id="sas-row-' . e(preg_replace('/[^a-zA-Z0-9_-]/', '_', $username)) . '">';
    $html .= '<td class="sub-check-cell"><label class="sub-check-lab"><input type="checkbox" class="sub-check" value="' . e($username) . '"></label></td>';
    $html .= '<td class="col-num">' . (int) $n . '</td>';
    $html .= '<td class="status-cell col-status" title="' . e($statusTitle) . '">' . $statusHtml . '</td>';
    $copyTip = $lang === 'en' ? 'Copy username' : 'نسخ اسم الدخول';
    $html .= '<td class="col-user"><span class="sas-user-copywrap">'
        . '<button type="button" class="sas-user-copy" data-copy="' . e($username) . '" title="' . e($copyTip) . '" aria-label="' . e($copyTip) . '">⧉</button>'
        . '<a class="sas-link" href="' . e($detailUrl) . '" title="' . e($lang === 'en' ? 'Edit' : 'تعديل') . '">' . e($username) . '</a>'
        . '</span></td>';
    if ($ipDisp !== '-' && $framedIp !== '') {
        $ipHref = function_exists('sas_cpe_login_url')
            ? sas_cpe_login_url($framedIp, $config)
            : '';
        if ($ipHref === '') {
            if (filter_var($framedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ipHref = 'http://[' . $framedIp . ']/';
            } elseif (filter_var($framedIp, FILTER_VALIDATE_IP)) {
                $ipHref = 'http://' . $framedIp . '/';
            }
        }
        $ipCopyTip = $lang === 'en' ? 'Copy IP' : 'نسخ عنوان IP';
        $ipOpenTip = $lang === 'en' ? 'Open CPE IP' : 'فتح IP الجهاز';
        $html .= '<td class="col-ip" dir="ltr"><span class="sas-ip-wrap">'
            . '<button type="button" class="sas-user-copy" data-copy="' . e($framedIp) . '" title="' . e($ipCopyTip) . '" aria-label="' . e($ipCopyTip) . '">⧉</button>';
        if ($ipHref !== '') {
            $html .= '<a class="sas-ip-link" href="' . e($ipHref) . '" target="_blank" rel="noopener noreferrer" title="' . e($ipOpenTip) . '">' . e($framedIp) . '</a>';
        } else {
            $html .= '<span>' . e($framedIp) . '</span>';
        }
        $html .= '</span></td>';
    } else {
        $html .= '<td class="col-ip" dir="ltr">-</td>';
    }
    $html .= '<td class="col-fn"><span class="cell-edit" tabindex="0" data-edit="firstname" data-id="' . e($username) . '" data-value="' . e($fn) . '" title="' . e($editTip) . '">' . e($fn !== '' ? $fn : '-') . '</span></td>';
    $html .= '<td class="col-ln"><span class="cell-edit" tabindex="0" data-edit="lastname" data-allow-empty="1" data-id="' . e($username) . '" data-value="' . e($ln) . '" title="' . e($editTip) . '">' . e($ln !== '' ? $ln : '-') . '</span></td>';
    $html .= '<td class="col-phone"><span class="cell-edit" tabindex="0" data-edit="phone" data-allow-empty="1" data-id="' . e($username) . '" data-value="' . e($phone) . '" title="' . e($editTip) . '">' . e($phone !== '' ? $phone : '-') . '</span></td>';
    $html .= '<td class="col-exp">' . (function_exists('sas_format_expire_html')
        ? sas_format_expire_html($hasExpire ? $expireAt : '')
        : '<span class="sas-expire-dt" dir="ltr">' . e($expireDisp) . '</span>') . '</td>';
    $html .= '<td class="col-parent">' . e($parentName) . '</td>';
    $html .= '<td class="col-pkg"><a class="sas-link sas-pkg-open" href="#" data-id="' . e($username) . '" title="' . e($lang === 'en' ? 'Change package' : 'تغيير نوع الاشتراك') . '">' . e($pkgLabel) . '</a></td>';
    $html .= '<td class="col-rent">';
    if (function_exists('rental_cell_html')) {
        $html .= rental_cell_html($rentSub, $username, $lang);
    } else {
        $html .= e($rentDevName);
    }
    $html .= '</td>';
    $html .= '<td class="debt-cell col-debt">';
    $canEditDebt = function_exists('user_can_edit_debts') && user_can_edit_debts();
    $debtHref = $localId > 0
        ? ('debts.php?status=unpaid&subscriber_id=' . $localId)
        : ('debts.php?sas_user=' . rawurlencode($username));
    if (function_exists('debt_amount_cell_html')) {
        $html .= debt_amount_cell_html($debt, $config, $lang, array(
            'can_edit' => $canEditDebt,
            'subscriber_id' => $localId,
            'username' => $username,
            'href' => $debtHref,
        ));
    } else {
        $debtCls = $debt > 0 ? 'debt-amt debt-due' : 'debt-amt debt-zero';
        $fallbackTxt = function_exists('money_format_iqd')
            ? money_format_iqd($debt, $currency)
            : ($currency . ' ' . number_format($debt, 2));
        $html .= '<a class="' . $debtCls . '" href="' . e($debtHref) . '">' . e($fallbackTxt) . '</a>';
    }
    $html .= '</td>';
    $html .= '<td class="col-traf">' . e($traf) . '</td>';
    $graceRow = array('grace_days' => array_key_exists('grace_days', $row) ? $row['grace_days'] : null);
    $graceLabel = function_exists('subscriber_grace_label')
        ? subscriber_grace_label($graceRow, $config, $lang)
        : '-';
    $graceCustom = function_exists('subscriber_grace_is_custom') && subscriber_grace_is_custom($graceRow);
    $graceRaw = $graceCustom ? (string) (int) $row['grace_days'] : 'system';
    $html .= '<td class="col-grace"><span class="cell-edit" tabindex="0" data-edit="grace_days" data-allow-empty="1" data-id="'
        . e($username) . '" data-value="' . e($graceRaw) . '" title="' . e($editTip) . '">'
        . e($graceLabel) . '</span></td>';
    $html .= '<td class="col-days">' . e($daysLeft !== '' ? $daysLeft : '-') . '</td>';
    $html .= '</tr>';
    return $html;
}

}
