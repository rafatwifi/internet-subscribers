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
    try {
        $pingCol = $pdo->query("SHOW COLUMNS FROM sas_users_cache LIKE 'online_ping'")->fetch();
        if (!$pingCol) {
            $pdo->exec('ALTER TABLE sas_users_cache ADD COLUMN online_ping SMALLINT UNSIGNED NULL DEFAULT NULL');
            if (function_exists('app_schema_touch')) {
                app_schema_touch('sas_cache');
            }
        }
    } catch (Exception $e) {
    }
    try {
        $macCol = $pdo->query("SHOW COLUMNS FROM sas_users_cache LIKE 'mac_address'")->fetch();
        if (!$macCol) {
            $pdo->exec('ALTER TABLE sas_users_cache ADD COLUMN mac_address VARCHAR(32) NULL DEFAULT NULL');
        }
    } catch (Exception $e) {
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sas_user_sessions (
                tenant_id INT UNSIGNED NOT NULL,
                username VARCHAR(80) NOT NULL,
                session_key VARCHAR(80) NOT NULL,
                mac_address VARCHAR(32) NULL DEFAULT NULL,
                framed_ip VARCHAR(45) NULL DEFAULT NULL,
                nas_ip VARCHAR(45) NULL DEFAULT NULL,
                started_at DATETIME NULL DEFAULT NULL,
                stopped_at DATETIME NULL DEFAULT NULL,
                session_seconds INT UNSIGNED NULL DEFAULT NULL,
                bytes_in BIGINT UNSIGNED NULL DEFAULT NULL,
                bytes_out BIGINT UNSIGNED NULL DEFAULT NULL,
                is_open TINYINT(1) NOT NULL DEFAULT 0,
                synced_at DATETIME NOT NULL,
                PRIMARY KEY (tenant_id, username, session_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8'
        );
    } catch (Exception $e) {
    }
    foreach (array('bytes_in', 'bytes_out') as $byteCol) {
        try {
            $hasByte = $pdo->query("SHOW COLUMNS FROM sas_user_sessions LIKE " . $pdo->quote($byteCol))->fetch();
            if (!$hasByte) {
                $pdo->exec('ALTER TABLE sas_user_sessions ADD COLUMN ' . $byteCol . ' BIGINT UNSIGNED NULL DEFAULT NULL');
            }
        } catch (Exception $e) {
        }
    }
    if (function_exists('app_schema_fresh') && app_schema_fresh('sas_cache')) {
        return;
    }

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
        'online_ping' => 'SMALLINT UNSIGNED NULL DEFAULT NULL',
        'mac_address' => 'VARCHAR(32) NULL DEFAULT NULL',
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
    if (function_exists('app_schema_touch')) {
        app_schema_touch('sas_cache');
    }
}

function sas_sync_meta($pdo, $tenantId = null)
{
    ensure_sas_users_cache_table($pdo);
    $tenantId = $tenantId === null
        ? (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1)
        : max(1, (int) $tenantId);
    try {
        $st = $pdo->prepare('SELECT * FROM sas_sync_meta WHERE tenant_id = :t LIMIT 1');
        $st->execute(array(':t' => $tenantId));
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
    } catch (Exception $e) {
    }
    // لا تقرأ meta شركة أخرى — أنشئ صف فارغ لهذه الشركة
    if ($tenantId === 1) {
        try {
            $row = $pdo->query('SELECT * FROM sas_sync_meta WHERE id = 1 LIMIT 1')->fetch();
            if ($row) {
                // تأكد أن الصف مربوط بـ tenant 1
                if (!isset($row['tenant_id']) || (int) $row['tenant_id'] <= 0) {
                    try {
                        $pdo->exec('UPDATE sas_sync_meta SET tenant_id = 1 WHERE id = 1');
                    } catch (Exception $e2) {
                    }
                }
                return $row;
            }
        } catch (Exception $e) {
        }
    }
    try {
        $pdo->prepare(
            'INSERT INTO sas_sync_meta (id, tenant_id, last_count) VALUES (:id, :t, 0)'
        )->execute(array(':id' => min(255, max(1, $tenantId)), ':t' => $tenantId));
    } catch (Exception $e) {
        // قد يكون id مأخوذ — جرّب بدون id إن أمكن
        try {
            $pdo->prepare('INSERT INTO sas_sync_meta (tenant_id, last_count) VALUES (:t, 0)')
                ->execute(array(':t' => $tenantId));
        } catch (Exception $e2) {
        }
    }
    try {
        $st = $pdo->prepare('SELECT * FROM sas_sync_meta WHERE tenant_id = :t LIMIT 1');
        $st->execute(array(':t' => $tenantId));
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
    } catch (Exception $e) {
    }
    return array(
        'last_ok_at' => null,
        'last_try_at' => null,
        'syncing_at' => null,
        'last_count' => 0,
        'last_error' => null,
        'sync_offset' => 0,
        'sync_expected' => 0,
        'tenant_id' => $tenantId,
    );
}

function sas_sync_meta_save($pdo, $fields, $tenantId = null)
{
    $tenantId = $tenantId === null
        ? (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1)
        : max(1, (int) $tenantId);
    // تأكد من وجود صف الشركة قبل التحديث
    sas_sync_meta($pdo, $tenantId);
    $cols = array();
    $params = array(':t' => $tenantId);
    foreach ($fields as $k => $v) {
        if ($k === 'tenant_id' || $k === 'id') {
            continue;
        }
        $cols[] = $k . ' = :' . $k;
        $params[':' . $k] = $v;
    }
    if (!$cols) {
        return;
    }
    try {
        $n = $pdo->prepare(
            'UPDATE sas_sync_meta SET ' . implode(', ', $cols) . ' WHERE tenant_id = :t'
        );
        $n->execute($params);
        if ($n->rowCount() === 0) {
            try {
                $pdo->prepare(
                    'INSERT INTO sas_sync_meta (id, tenant_id, last_count) VALUES (:id, :t, 0)'
                )->execute(array(':id' => min(255, max(1, $tenantId)), ':t' => $tenantId));
            } catch (Exception $e) {
            }
            $pdo->prepare(
                'UPDATE sas_sync_meta SET ' . implode(', ', $cols) . ' WHERE tenant_id = :t'
            )->execute($params);
        }
    } catch (Exception $e) {
        // لا تسقط على شركة أخرى
        if ($tenantId === 1) {
            try {
                unset($params[':t']);
                $pdo->prepare('UPDATE sas_sync_meta SET ' . implode(', ', $cols) . ' WHERE id = 1')
                    ->execute($params);
            } catch (Exception $e2) {
            }
        }
    }
}

function sas_cache_username($row)
{
    if (!is_array($row)) {
        return '';
    }
    foreach (array('username', 'user_name', 'login') as $k) {
        if (!isset($row[$k]) || is_array($row[$k])) {
            continue;
        }
        $v = trim((string) $row[$k]);
        if ($v !== '') {
            return $v;
        }
    }
    foreach (array('user', 'subscriber', 'customer', 'account') as $nest) {
        if (!isset($row[$nest]) || !is_array($row[$nest])) {
            continue;
        }
        foreach (array('username', 'user_name', 'login') as $k) {
            if (empty($row[$nest][$k]) || is_array($row[$nest][$k])) {
                continue;
            }
            $v = trim((string) $row[$nest][$k]);
            if ($v !== '') {
                return $v;
            }
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
    foreach (array('manager_id', 'reseller_id', 'agent_id') as $k) {
        if (isset($row[$k]) && is_numeric($row[$k]) && (int) $row[$k] > 0) {
            return (int) $row[$k];
        }
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

function sas_maybe_background_sync($pdo, $config, $forceIfNoExpire = false)
{
    if (!function_exists('sas_sync_users_from_api') || empty($config['sas']['enabled'])) {
        return array(false, 'off');
    }
    if (function_exists('ensure_sas_users_cache_table')) {
        try {
            ensure_sas_users_cache_table($pdo);
        } catch (Exception $e) {
            return array(false, 'table');
        }
    }
    $need = false;
    $meta = function_exists('sas_sync_meta') ? sas_sync_meta($pdo) : array();
    if (!empty($meta['sync_offset']) || !empty($meta['syncing_at'])) {
        $need = true;
    }
    $last = !empty($meta['last_ok_at']) ? strtotime((string) $meta['last_ok_at']) : 0;
    if (!$last || (time() - $last) > 240) {
        $need = true;
    }
    if ($forceIfNoExpire) {
        try {
            $n = (int) $pdo->query(
                "SELECT COUNT(*) FROM sas_users_cache WHERE expire_at IS NOT NULL AND expire_at > '2000-01-01'"
            )->fetchColumn();
            if ($n <= 0) {
                $need = true;
            }
        } catch (Exception $e) {
            $need = true;
        }
    }
    if (!$need) {
        return array(true, 'fresh');
    }
    $lock = __DIR__ . '/../config/sas_bg_sync.lock';
    $now = time();
    if (is_file($lock)) {
        $prev = (int) trim((string) @file_get_contents($lock));
        if ($prev > 0 && ($now - $prev) < 45) {
            return array(true, 'busy');
        }
    }
    @file_put_contents($lock, (string) $now);
    try {
        list($ok, $count, $mode, $meta2) = sas_sync_users_from_api($pdo, $config, false, false);
        return array($ok, $mode);
    } catch (Exception $e) {
        return array(false, 'error');
    }
}

/**
 * صفوف قرب الانتهاء من كاش المشتركين (آمن للكولاجن).
 */
function sas_list_expiring_rows($pdo, $daysMax, $limit = 2000)
{
    $daysMax = max(0, (int) $daysMax);
    $limit = max(1, (int) $limit);
    $out = array();
    if (function_exists('ensure_sas_users_cache_table')) {
        try {
            ensure_sas_users_cache_table($pdo);
        } catch (Exception $e) {
            return $out;
        }
    }
    $scope = '';
    $schedTid = 0;
    if (!empty($GLOBALS['schedule_tenant_id'])) {
        $schedTid = (int) $GLOBALS['schedule_tenant_id'];
    } elseif (!empty($_SESSION['admin_logged_in']) && function_exists('current_tenant_id')) {
        $schedTid = (int) current_tenant_id();
    }
    if ($schedTid > 0) {
        $scope .= ' AND c.tenant_id = ' . $schedTid;
    }
    if (function_exists('is_agent_user') && is_agent_user()
        && function_exists('current_admin_sas_manager_id')
        && current_admin_sas_manager_id() > 0
        && function_exists('sas_agent_scope_sql')
    ) {
        $scope = sas_agent_scope_sql('c');
    }
    $userEq = function_exists('sas_sql_username_eq')
        ? sas_sql_username_eq('s.sas_username', 'c.username')
        : 's.sas_username = c.username';
    $sql = "SELECT c.username, c.display_name, c.phone AS sas_phone, c.profile_name, c.expire_at,
                   c.local_subscriber_id, c.sas_user_id, c.parent_id, c.enabled,
                   s.id AS sub_id, s.name AS sub_name, s.phone AS sub_phone, s.agent_user_id
            FROM sas_users_cache c
            LEFT JOIN subscribers s ON s.tenant_id = c.tenant_id AND (
                (c.local_subscriber_id IS NOT NULL AND s.id = c.local_subscriber_id)
                OR (c.local_subscriber_id IS NULL AND s.sas_username IS NOT NULL AND s.sas_username <> '' AND {$userEq})
            )
            WHERE c.expire_at IS NOT NULL
              AND DATE(c.expire_at) >= CURDATE()
              AND DATE(c.expire_at) <= DATE_ADD(CURDATE(), INTERVAL " . (int) $daysMax . " DAY)
              {$scope}
            ORDER BY c.expire_at ASC
            LIMIT " . (int) $limit;
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        // استعلام مبسّط بدون JOIN إذا فشل الكولاجن
        try {
            $sql2 = "SELECT c.username, c.display_name, c.phone AS sas_phone, c.profile_name, c.expire_at,
                            c.local_subscriber_id, c.sas_user_id, c.parent_id, c.enabled,
                            c.local_subscriber_id AS sub_id, NULL AS sub_name, NULL AS sub_phone, NULL AS agent_user_id
                     FROM sas_users_cache c
                     WHERE c.expire_at IS NOT NULL
                       AND DATE(c.expire_at) >= CURDATE()
                       AND DATE(c.expire_at) <= DATE_ADD(CURDATE(), INTERVAL " . (int) $daysMax . " DAY)
                       {$scope}
                     ORDER BY c.expire_at ASC
                     LIMIT " . (int) $limit;
            $rows = $pdo->query($sql2)->fetchAll();
        } catch (Exception $e2) {
            return $out;
        }
    }
    foreach ($rows as $crow) {
        if (isset($crow['enabled']) && (int) $crow['enabled'] !== 1) {
            continue;
        }
        $leftRaw = sas_remaining_days($crow['expire_at']);
        if ($leftRaw === '') {
            continue;
        }
        $left = (int) $leftRaw;
        if ($left < 0 || $left > $daysMax) {
            continue;
        }
        $crow['_days'] = $left;
        $out[] = $crow;
    }
    return $out;
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

function sas_cache_mac($row)
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
        'callingstationid', 'calling_station_id', 'callingStationId',
        'Calling-Station-Id', 'calling-station-id',
        'mac_address', 'macaddress', 'macAddress', 'mac', 'user_mac', 'client_mac',
    );
    foreach ($nests as $block) {
        foreach ($keys as $k) {
            if (!isset($block[$k]) || is_array($block[$k])) {
                continue;
            }
            $v = strtoupper(trim((string) $block[$k]));
            $v = str_replace('-', ':', $v);
            if ($v === '' || $v === '00:00:00:00:00:00' || $v === '0') {
                continue;
            }
            if (!preg_match('/^[0-9A-F:]{11,32}$/', $v)) {
                continue;
            }
            return sas_clip($v, 32);
        }
    }
    return '';
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

function sas_cache_ping_number($value)
{
    if (is_array($value) || $value === null || is_bool($value)) {
        return null;
    }
    $year = (int) date('Y');
    if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d+(\.\d+)?$/', trim($value)))) {
        $n = (int) round((float) $value);
        if ($n >= 2010 && $n <= $year + 1) {
            return null;
        }
        if ($n > 1000000000) {
            return null;
        }
        if ($n > 0 && $n < 10000) {
            return $n;
        }
        return null;
    }
    $s = trim((string) $value);
    if ($s === '' || $s === '-' || $s === '—') {
        return null;
    }
    if (preg_match('/\d{4}[-:\/]/', $s) || preg_match('/[-:\/]\d{4}/', $s) || preg_match('/\d{1,2}:\d{2}/', $s)) {
        return null;
    }
    if (!preg_match('/^(\d+(?:\.\d+)?)(\s*ms)?$/i', $s, $m)) {
        return null;
    }
    $n = (int) round((float) $m[1]);
    if ($n >= 2010 && $n <= $year + 1) {
        return null;
    }
    if ($n > 0 && $n < 10000) {
        return $n;
    }
    return null;
}

function sas_cache_online_ping($row)
{
    if (!is_array($row)) {
        return null;
    }
    $nests = array($row);
    foreach (array('acct', 'session', 'radius', 'online', 'data', 'user', 'info', 'attributes') as $nk) {
        if (isset($row[$nk]) && is_array($row[$nk]) && !isset($row[$nk][0])) {
            $nests[] = $row[$nk];
        }
    }
    $keys = array(
        'ping', 'ping_ms', 'pingMs', 'ping_time', 'pingTime', 'user_ping',
        'latency', 'latency_ms', 'rtt', 'rtt_ms', 'delay', 'response_time', 'avg_rtt',
    );
    foreach ($nests as $block) {
        foreach ($keys as $k) {
            if (!isset($block[$k]) || is_array($block[$k])) {
                continue;
            }
            $n = sas_cache_ping_number($block[$k]);
            if ($n !== null) {
                return $n;
            }
        }
        foreach ($block as $k => $v) {
            if (is_array($v)) {
                continue;
            }
            $name = strtolower((string) $k);
            if (strpos($name, 'ping') === false && strpos($name, 'rtt') === false && strpos($name, 'latency') === false) {
                continue;
            }
            $n = sas_cache_ping_number($v);
            if ($n !== null) {
                return $n;
            }
        }
    }
    return null;
}

function sas_ping_mode_normalize($mode)
{
    $mode = strtolower(trim((string) $mode));
    if ($mode === 'sas' || $mode === 'portal') {
        return $mode;
    }
    return 'off';
}

function sas_ping_mode($pdo)
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $mode = 'off';
    if ($pdo && function_exists('admin_ui_prefs_load')) {
        $prefs = admin_ui_prefs_load($pdo);
        if (is_array($prefs) && isset($prefs['sub_ping_mode'])) {
            $mode = sas_ping_mode_normalize($prefs['sub_ping_mode']);
        }
    }
    $cached = $mode;
    return $cached;
}

function sas_ping_mode_style()
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<style>
.ping-mode{display:inline-flex;direction:ltr;align-items:stretch;border:1px solid #dbe3ee;border-radius:999px;overflow:hidden;background:#f8fafc;max-width:100%}
.ping-mode-btn{border:0;background:transparent;color:#64748b;font-weight:600;font-size:12px;line-height:1.2;padding:7px 10px;cursor:pointer;font-family:inherit}
.ping-mode-btn + .ping-mode-btn{border-inline-start:1px solid #e2e8f0}
.ping-mode-btn .ping-short{display:none}
.ping-mode-btn.is-on[data-ping-mode="portal"]{background:#0f766e;color:#fff}
.ping-mode-btn.is-on[data-ping-mode="off"]{background:#e2e8f0;color:#334155}
.ping-mode-btn.is-on[data-ping-mode="sas"]{background:#0284c7;color:#fff}
@media(max-width:760px){
  .ping-mode{display:inline-flex;width:auto;margin-inline:auto}
  .ping-mode-btn{flex:0 0 auto;font-size:12px;font-weight:500;padding:6px 12px}
  .ping-mode-btn .ping-long{display:none}
  .ping-mode-btn .ping-short{display:inline}
}
</style>';
}

function sas_ping_mode_buttons($en, $mode)
{
    $mode = sas_ping_mode_normalize($mode);
    $opts = array(
        array('portal', $en ? 'Portal ping' : 'البنك من خلال البوابة', $en ? 'Portal' : 'البوابة'),
        array('off', $en ? 'Off' : 'طافي', $en ? 'Off' : 'طافي'),
        array('sas', $en ? 'SAS ping' : 'البنك من خلال الساس', $en ? 'SAS' : 'الساس'),
    );
    $html = '<div class="ping-mode" id="subPingMode" dir="ltr" role="radiogroup" data-mode="' . e($mode) . '">';
    foreach ($opts as $opt) {
        $on = ($mode === $opt[0]) ? ' is-on' : '';
        $html .= '<button type="button" class="ping-mode-btn' . $on . '" data-ping-mode="' . e($opt[0]) . '" title="' . e($opt[1]) . '">'
            . '<span class="ping-long">' . e($opt[1]) . '</span>'
            . '<span class="ping-short">' . e($opt[2]) . '</span>'
            . '</button>';
    }
    $html .= '</div>';
    return $html;
}

function sas_cache_radacct_id($row)
{
    if (!is_array($row)) {
        return '';
    }
    $nests = array($row);
    foreach (array('acct', 'session', 'radius', 'online') as $nk) {
        if (isset($row[$nk]) && is_array($row[$nk]) && !isset($row[$nk][0])) {
            $nests[] = $row[$nk];
        }
    }
    foreach ($nests as $src) {
        foreach (array('radacctid', 'radacct_id') as $k) {
            if (!isset($src[$k]) || is_array($src[$k])) {
                continue;
            }
            $v = trim((string) $src[$k]);
            if ($v !== '' && $v !== '0') {
                return $v;
            }
        }
    }
    return '';
}

function sas_radacct_cache_path($tid)
{
    $tid = (int) $tid;
    if ($tid <= 0) {
        $tid = 1;
    }
    return dirname(__DIR__) . '/storage/cache/sub_rad_t' . $tid . '.json';
}

function sas_icmp_parse_ms($text)
{
    if (!is_string($text) || $text === '') {
        return null;
    }
    $ms = null;
    if (preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $text, $m)) {
        $ms = (float) $m[1];
    } elseif (preg_match('/Average\s*=\s*([\d.]+)\s*ms/i', $text, $m)) {
        $ms = (float) $m[1];
    } elseif (preg_match('/=\s*([\d.]+)\/([\d.]+)\/([\d.]+)/', $text, $m)) {
        $ms = (float) $m[2];
    }
    if ($ms === null) {
        return null;
    }
    $n = (int) round($ms);
    if ($n <= 0 || $n >= 10000) {
        return null;
    }
    return $n;
}

function sas_icmp_many($hosts)
{
    $clean = array();
    if (!is_array($hosts)) {
        return array();
    }
    foreach ($hosts as $host) {
        $host = trim((string) $host);
        if ($host === '' || !filter_var($host, FILTER_VALIDATE_IP)) {
            continue;
        }
        $clean[$host] = true;
        if (count($clean) >= 40) {
            break;
        }
    }
    $hosts = array_keys($clean);
    $out = array();
    if (!$hosts || !function_exists('proc_open')) {
        return $out;
    }
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $procs = array();
    foreach ($hosts as $host) {
        $cmd = $isWin
            ? ('ping -n 1 -w 1000 ' . escapeshellarg($host))
            : ('ping -c 1 -W 1 ' . escapeshellarg($host));
        $pipes = array();
        $proc = @proc_open($cmd, array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        ), $pipes);
        if (!is_resource($proc)) {
            continue;
        }
        @fclose($pipes[0]);
        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);
        $procs[] = array(
            'host' => $host,
            'proc' => $proc,
            'out' => $pipes[1],
            'err' => $pipes[2],
            'buf' => '',
        );
    }
    $deadline = microtime(true) + 2.6;
    while ($procs && microtime(true) < $deadline) {
        $read = array();
        foreach ($procs as $p) {
            if (isset($p['out']) && is_resource($p['out'])) {
                $read[] = $p['out'];
            }
        }
        if ($read) {
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200000);
        }
        $left = array();
        foreach ($procs as $p) {
            if (isset($p['out']) && is_resource($p['out'])) {
                $chunk = @fread($p['out'], 8192);
                if (is_string($chunk) && $chunk !== '') {
                    $p['buf'] .= $chunk;
                }
            }
            $status = @proc_get_status($p['proc']);
            $running = is_array($status) && !empty($status['running']);
            if ($running && microtime(true) < $deadline) {
                $left[] = $p;
                continue;
            }
            if (isset($p['out']) && is_resource($p['out'])) {
                $rest = @stream_get_contents($p['out']);
                if (is_string($rest)) {
                    $p['buf'] .= $rest;
                }
                @fclose($p['out']);
            }
            if (isset($p['err']) && is_resource($p['err'])) {
                @fclose($p['err']);
            }
            @proc_close($p['proc']);
            $ms = sas_icmp_parse_ms($p['buf']);
            if ($ms !== null) {
                $out[$p['host']] = $ms;
            }
        }
        $procs = $left;
    }
    foreach ($procs as $p) {
        if (is_resource($p['proc'])) {
            @proc_terminate($p['proc']);
            @proc_close($p['proc']);
        }
        if (isset($p['out']) && is_resource($p['out'])) {
            @fclose($p['out']);
        }
        if (isset($p['err']) && is_resource($p['err'])) {
            @fclose($p['err']);
        }
    }
    return $out;
}

function sas_ping_na_path($tid)
{
    $tid = (int) $tid;
    if ($tid <= 0) {
        $tid = 1;
    }
    return dirname(__DIR__) . '/storage/cache/sub_ping_na_t' . $tid . '.json';
}

function sas_ping_na_load($tid)
{
    $tid = (int) $tid;
    if ($tid <= 0) {
        $tid = 1;
    }
    if (isset($GLOBALS['sas_ping_na_map'][$tid]) && is_array($GLOBALS['sas_ping_na_map'][$tid])) {
        return $GLOBALS['sas_ping_na_map'][$tid];
    }
    $map = array();
    $file = sas_ping_na_path($tid);
    if (is_file($file)) {
        $raw = json_decode((string) @file_get_contents($file), true);
        if (is_array($raw)) {
            $now = time();
            foreach ($raw as $u => $at) {
                $u = strtolower(trim((string) $u));
                if ($u === '' || ($now - (int) $at) > 300) {
                    continue;
                }
                $map[$u] = (int) $at;
            }
        }
    }
    if (!isset($GLOBALS['sas_ping_na_map']) || !is_array($GLOBALS['sas_ping_na_map'])) {
        $GLOBALS['sas_ping_na_map'] = array();
    }
    $GLOBALS['sas_ping_na_map'][$tid] = $map;
    return $map;
}

function sas_ping_na_mark($tid, $user, $on)
{
    $user = strtolower(trim((string) $user));
    if ($user === '') {
        return;
    }
    $tid = (int) $tid;
    if ($tid <= 0) {
        $tid = 1;
    }
    $map = sas_ping_na_load($tid);
    if ($on) {
        $map[$user] = time();
    } else {
        unset($map[$user]);
    }
    $GLOBALS['sas_ping_na_map'][$tid] = $map;
    $dir = dirname(sas_ping_na_path($tid));
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(sas_ping_na_path($tid), json_encode($map));
}

function sas_ping_user_is_na($username)
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    $user = strtolower(trim((string) $username));
    if ($user === '') {
        return false;
    }
    $map = sas_ping_na_load($tid);
    return isset($map[$user]);
}

function sas_ping_warm($pdo)
{
    if (!$pdo || !function_exists('sas_ping_mode') || sas_ping_mode($pdo) === 'off') {
        return;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $lockPath = $dir . '/sub_ping_warm_t' . $tid . '.lock';
    $fh = @fopen($lockPath, 'c');
    if (!$fh) {
        return;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return;
    }
    try {
        $scope = function_exists('sas_agent_scope_sql') ? sas_agent_scope_sql('c') : '';
        $offFile = $dir . '/sub_ping_warm_t' . $tid . '.off';
        $off = is_file($offFile) ? (int) trim((string) @file_get_contents($offFile)) : 0;
        if ($off < 0) {
            $off = 0;
        }
        $missing = 0;
        try {
            $cntSql = 'SELECT COUNT(*) FROM sas_users_cache c WHERE c.tenant_id = ' . $tid
                . ' AND c.is_online = 1 AND (c.online_ping IS NULL OR c.online_ping = 0)' . $scope;
            $missing = (int) $pdo->query($cntSql)->fetchColumn();
        } catch (Exception $e) {
            $missing = 0;
        }
        if ($missing > 0) {
            $off = 0;
        }
        $sql = 'SELECT c.username, c.is_online, c.framed_ip, c.online_ping FROM sas_users_cache c WHERE c.tenant_id = ' . $tid . ' AND c.is_online = 1' . $scope
            . ' ORDER BY (c.online_ping IS NULL OR c.online_ping = 0) DESC, c.username ASC LIMIT 40 OFFSET ' . $off;
        $rows = $pdo->query($sql)->fetchAll();
        if ($missing > 0 || !is_array($rows) || count($rows) < 40) {
            @file_put_contents($offFile, '0');
        } else {
            @file_put_contents($offFile, (string) ($off + 40));
        }
        if (is_array($rows) && $rows) {
            sas_fill_online_pings($pdo, $rows);
        }
    } catch (Exception $e) {
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}

function sas_fill_online_pings($pdo, $rows, $maxAge = 30)
{
    if (!$pdo || !is_array($rows) || !$rows) {
        return is_array($rows) ? $rows : array();
    }
    $maxAge = (int) $maxAge;
    $mode = function_exists('sas_ping_mode') ? sas_ping_mode($pdo) : 'off';
    if ($mode === 'off') {
        foreach ($rows as $i => $row) {
            if (is_array($row)) {
                $rows[$i]['online_ping'] = null;
                $rows[$i]['_ping_fresh'] = 0;
                $rows[$i]['_ping_checked'] = 1;
            }
        }
        return $rows;
    }
    if ($mode === 'sas') {
        $rows = sas_fill_sas_pings($pdo, $rows, $maxAge);
        $rows = sas_ping_icmp_missing($pdo, $rows);
    } else {
        $rows = sas_fill_portal_pings($pdo, $rows, $maxAge);
    }
    if (!is_array($rows)) {
        return array();
    }
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        if (empty($row['is_online']) || !empty($row['_ping_attempted'])) {
            $rows[$i]['_ping_checked'] = 1;
        }
    }
    return $rows;
}

function sas_ping_icmp_missing($pdo, $rows)
{
    if (!is_array($rows) || !$rows) {
        return is_array($rows) ? $rows : array();
    }
    $need = array();
    foreach ($rows as $i => $row) {
        if (!is_array($row) || empty($row['is_online'])) {
            continue;
        }
        if (isset($row['online_ping']) && (int) $row['online_ping'] > 0) {
            continue;
        }
        $ip = isset($row['framed_ip']) ? trim((string) $row['framed_ip']) : '';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            continue;
        }
        if (!isset($need[$ip])) {
            $need[$ip] = array();
        }
        $need[$ip][] = $i;
        if (count($need) >= 40) {
            break;
        }
    }
    if (!$need) {
        return $rows;
    }
    $hit = sas_icmp_many(array_keys($need));
    if (!is_array($hit)) {
        $hit = array();
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    $upd = null;
    if ($pdo) {
        try {
            $upd = $pdo->prepare('UPDATE sas_users_cache SET online_ping = :ms WHERE tenant_id = :t AND LOWER(username) = :u');
        } catch (Exception $e) {
            $upd = null;
        }
    }
    foreach ($need as $ip => $indexes) {
        $ms = isset($hit[$ip]) ? (int) $hit[$ip] : 0;
        foreach ($indexes as $i) {
            $rows[$i]['_ping_attempted'] = 1;
            if ($ms <= 0) {
                continue;
            }
            $rows[$i]['online_ping'] = $ms;
            $rows[$i]['_ping_fresh'] = 1;
            if ($upd) {
                $user = strtolower(trim((string) $rows[$i]['username']));
                if ($user === '') {
                    continue;
                }
                try {
                    $upd->execute(array(':ms' => $ms, ':t' => $tid, ':u' => $user));
                } catch (Exception $e) {
                }
                if (function_exists('sas_ping_na_mark')) {
                    sas_ping_na_mark($tid, $user, false);
                }
            }
        }
    }
    return $rows;
}

function sas_fill_sas_pings($pdo, $rows, $maxAge = 30)
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(40);
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/sub_ping_sas_t' . $tid . '.json';
    $saved = array();
    if (is_file($file)) {
        $raw = json_decode((string) @file_get_contents($file), true);
        if (is_array($raw)) {
            $saved = $raw;
        }
    }
    $radPath = sas_radacct_cache_path($tid);
    $radMap = array();
    if (is_file($radPath)) {
        $rawRad = json_decode((string) @file_get_contents($radPath), true);
        if (is_array($rawRad)) {
            $radMap = $rawRad;
        }
    }
    $now = time();
    $need = array();
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        if (empty($row['is_online'])) {
            $rows[$i]['online_ping'] = null;
            $rows[$i]['_ping_fresh'] = 0;
            continue;
        }
        $storedRaw = isset($row['online_ping']) ? $row['online_ping'] : null;
        if ($storedRaw === null || $storedRaw === '') {
            $rows[$i]['online_ping'] = null;
            $rows[$i]['_ping_fresh'] = 0;
        }
        $user = isset($row['username']) ? strtolower(trim((string) $row['username'])) : '';
        if ($user === '') {
            continue;
        }
        if ($maxAge > 0 && isset($saved[$user]) && is_array($saved[$user])
            && isset($saved[$user]['at']) && ($now - (int) $saved[$user]['at']) < $maxAge
            && !empty($saved[$user]['ms'])) {
            $rows[$i]['online_ping'] = (int) $saved[$user]['ms'];
            $rows[$i]['_ping_fresh'] = 1;
            continue;
        }
        if (count($need) >= 40) {
            continue;
        }
        $need[$user] = $i;
    }
    $missingRad = false;
    foreach ($need as $user => $i) {
        if (!isset($radMap[$user]) || (string) $radMap[$user] === '') {
            $missingRad = true;
            break;
        }
    }
    $api = null;
    if ($missingRad && function_exists('sas_page_connector')) {
        global $config;
        $api = sas_page_connector($config);
        if ($api && method_exists($api, 'listOnlineUsers')) {
            try {
                if (method_exists($api, 'setTimeout')) {
                    $api->setTimeout(8);
                }
                $online = $api->listOnlineUsers();
            } catch (Exception $e) {
                $online = array();
            }
            if (is_array($online)) {
                foreach ($online as $orow) {
                    $u = function_exists('sas_cache_username') ? sas_cache_username($orow) : '';
                    $rad = sas_cache_radacct_id($orow);
                    if ($u === '' || $rad === '') {
                        continue;
                    }
                    $keys = function_exists('sas_cache_username_match_keys')
                        ? sas_cache_username_match_keys($u)
                        : array(strtolower($u));
                    foreach ($keys as $key) {
                        $radMap[$key] = $rad;
                    }
                }
                @file_put_contents($radPath, json_encode($radMap));
            }
        }
    }
    $pairs = array();
    $pairIndex = array();
    foreach ($need as $user => $i) {
        $rad = isset($radMap[$user]) ? (string) $radMap[$user] : '';
        if ($rad === '' && function_exists('sas_cache_username_match_keys')) {
            $radKeys = sas_cache_username_match_keys($user);
            foreach ($radKeys as $radKey) {
                if (isset($radMap[$radKey]) && (string) $radMap[$radKey] !== '') {
                    $rad = (string) $radMap[$radKey];
                    break;
                }
            }
        }
        if ($rad === '') {
            continue;
        }
        $shown = isset($rows[$i]['username']) ? trim((string) $rows[$i]['username']) : $user;
        $pairs[] = array('username' => $shown, 'radacctid' => $rad);
        $pairIndex[strtolower($shown)] = $i;
        $rows[$i]['_ping_attempted'] = 1;
    }
    if ($pairs && function_exists('sas_page_connector')) {
        if (!$api) {
            global $config;
            $api = sas_page_connector($config);
        }
        $hit = array();
        if ($api && method_exists($api, 'pingOnlineUsers')) {
            try {
                $hit = $api->pingOnlineUsers($pairs);
            } catch (Exception $e) {
                $hit = array();
            }
        }
        if (!is_array($hit)) {
            $hit = array();
        }
        foreach ($hit as $user => $ms) {
            $user = strtolower(trim((string) $user));
            $ms = (int) $ms;
            if ($user === '' || !isset($pairIndex[$user])) {
                continue;
            }
            $i = $pairIndex[$user];
            if ($ms <= 0) {
                $rows[$i]['online_ping'] = 0;
                if ($pdo) {
                    try {
                        $updNa = $pdo->prepare('UPDATE sas_users_cache SET online_ping = 0 WHERE tenant_id = :t AND LOWER(username) = :u');
                        $updNa->execute(array(':t' => $tid, ':u' => $user));
                    } catch (Exception $e) {
                    }
                }
                sas_ping_na_mark($tid, $user, true);
                continue;
            }
            $rows[$i]['online_ping'] = $ms;
            $rows[$i]['_ping_fresh'] = 1;
            $saved[$user] = array('ms' => $ms, 'at' => $now);
            sas_ping_na_mark($tid, $user, false);
            if ($pdo) {
                try {
                    $updSas = $pdo->prepare('UPDATE sas_users_cache SET online_ping = :ms WHERE tenant_id = :t AND LOWER(username) = :u');
                    $updSas->execute(array(':ms' => $ms, ':t' => $tid, ':u' => $user));
                } catch (Exception $e) {
                }
            }
        }
    }
    $fresh = array();
    foreach ($saved as $user => $item) {
        if (!is_array($item) || empty($item['at']) || ($now - (int) $item['at']) > 180) {
            continue;
        }
        $fresh[$user] = $item;
    }
    @file_put_contents($file, json_encode($fresh));
    return $rows;
}

function sas_fill_portal_pings($pdo, $rows, $maxAge = 30)
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/sub_ping_t' . $tid . '.json';
    $saved = array();
    if (is_file($file)) {
        $raw = json_decode((string) @file_get_contents($file), true);
        if (is_array($raw)) {
            $saved = $raw;
        }
    }
    $now = time();
    $need = array();
    foreach ($rows as $i => $row) {
        if (!is_array($row) || empty($row['is_online'])) {
            continue;
        }
        $user = isset($row['username']) ? strtolower(trim((string) $row['username'])) : '';
        $ip = isset($row['framed_ip']) ? trim((string) $row['framed_ip']) : '';
        if ($user === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            continue;
        }
        if ($maxAge > 0 && isset($saved[$user]) && is_array($saved[$user])
            && isset($saved[$user]['ip']) && (string) $saved[$user]['ip'] === $ip
            && isset($saved[$user]['at']) && ($now - (int) $saved[$user]['at']) < $maxAge
            && !empty($saved[$user]['ms'])) {
            $rows[$i]['online_ping'] = (int) $saved[$user]['ms'];
            $rows[$i]['_ping_fresh'] = 1;
            continue;
        }
        if (!isset($need[$ip])) {
            if (count($need) >= 40) {
                continue;
            }
            $need[$ip] = array();
        }
        $need[$ip][] = $i;
    }
    if ($need) {
        $hit = sas_icmp_many(array_keys($need));
        if (!is_array($hit)) {
            $hit = array();
        }
        $upd = null;
        try {
            $upd = $pdo->prepare('UPDATE sas_users_cache SET online_ping = :ms WHERE tenant_id = :t AND LOWER(username) = :u');
        } catch (Exception $e) {
            $upd = null;
        }
        foreach ($need as $ip => $indexes) {
            $ms = isset($hit[$ip]) ? (int) $hit[$ip] : 0;
            foreach ($indexes as $i) {
                $rows[$i]['_ping_attempted'] = 1;
                $user = strtolower(trim((string) $rows[$i]['username']));
                if ($ms <= 0 || $user === '') {
                    continue;
                }
                $rows[$i]['online_ping'] = $ms;
                $rows[$i]['_ping_fresh'] = 1;
                $saved[$user] = array('ms' => $ms, 'at' => $now, 'ip' => $ip);
                if ($upd) {
                    try {
                        $upd->execute(array(':ms' => $ms, ':t' => $tid, ':u' => $user));
                    } catch (Exception $e) {
                    }
                }
                sas_ping_na_mark($tid, $user, false);
            }
        }
    }
    $fresh = array();
    foreach ($saved as $user => $item) {
        if (!is_array($item) || empty($item['at']) || ($now - (int) $item['at']) > 180) {
            continue;
        }
        $fresh[$user] = $item;
    }
    @file_put_contents($file, json_encode($fresh));
    return $rows;
}

function sas_ago_label($dt, $en)
{
    $ts = strtotime((string) $dt);
    if (!$ts) {
        return '';
    }
    $sec = time() - $ts;
    if ($sec < 0) {
        $sec = 0;
    }
    if ($sec < 60) {
        return (int) $sec . ($en ? 's' : 'ث');
    }
    if ($sec < 3600) {
        return (int) floor($sec / 60) . ($en ? 'm' : 'د');
    }
    if ($sec < 86400) {
        return (int) floor($sec / 3600) . ($en ? 'h' : 'س');
    }
    if ($sec < 30 * 86400) {
        return (int) floor($sec / 86400) . ($en ? 'd' : 'ي');
    }
    $months = (int) floor($sec / (30 * 86400));
    if ($months < 12) {
        if ($months < 1) {
            $months = 1;
        }
        return $months . ($en ? 'mo' : 'ش');
    }
    $years = (int) floor($sec / (365 * 86400));
    if ($years < 1) {
        $years = 1;
    }
    return $years . ($en ? 'y' : 'سنة');
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

function sas_online_flags_throttle_file()
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'isp_sas_online_flags_t' . $tid . '.txt';
}

function sas_refresh_online_flags($pdo, $config)
{
    $throttleFile = sas_online_flags_throttle_file();
    $tidHeavy = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tidHeavy <= 0) {
        $tidHeavy = 1;
    }
    $heavyFile = dirname($throttleFile) . DIRECTORY_SEPARATOR . 'isp_sas_online_heavy_t' . $tidHeavy . '.txt';
    $heavyRaw = is_file($heavyFile) ? (string) @file_get_contents($heavyFile) : '';
    $heavyAge = is_file($heavyFile) ? (time() - (int) @filemtime($heavyFile)) : 99999;
    if (strncmp($heavyRaw, 'full', 4) === 0 && $heavyAge < 30) {
        return 0;
    }
    if (strncmp($heavyRaw, 'part', 4) === 0 && $heavyAge < 12) {
        return 0;
    }
    $throttleAge = is_file($throttleFile) ? (time() - (int) @filemtime($throttleFile)) : 99999;
    if ($throttleAge < 2) {
        return 0;
    }
    $lockPath = dirname($throttleFile) . DIRECTORY_SEPARATOR . 'isp_sas_online_flags_' . md5($throttleFile) . '.lock';
    $lockFh = @fopen($lockPath, 'c');
    if ($lockFh && !@flock($lockFh, LOCK_EX | LOCK_NB)) {
        @fclose($lockFh);
        return 0;
    }
    @touch($throttleFile);
    ensure_sas_users_cache_table($pdo);
    $api = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
    if (!$api || !method_exists($api, 'listOnlineUsers')) {
        return 0;
    }
    // لا تمسك قفل الجلسة أثناء طلب الساس (يمنع صفنة التنقّل بين الصفحات)
    if (function_exists('app_session_close')) {
        app_session_close();
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    try {
        if (function_exists('set_time_limit')) {
            @set_time_limit(70);
        }
        if (method_exists($api, 'setTimeout')) {
            $api->setTimeout(25);
        }
        $rows = $api->listOnlineUsers();
    } catch (Exception $e) {
        return 0;
    }
    if (!is_array($rows)) {
        return 0;
    }
    // إذا فشل الاتصال بالساس — لا تمسح الأونلاين القديم
    if (isset($api->lastOnlineListOk) && !$api->lastOnlineListOk) {
        return 0;
    }
    if (isset($api->lastOnlineComplete) && !$api->lastOnlineComplete) {
        @file_put_contents($heavyFile, 'part ' . time());
        return 0;
    }
        $names = array();
        $trafMap = array();
        $ipMap = array();
        $macMap = array();
        $pingMap = array();
        $radMap = array();
        $rowByUser = array();
        foreach ($rows as $row) {
            $u = sas_cache_username($row);
            if ($u === '') {
                continue;
            }
            if (!isset($rowByUser[$u])) {
                $rowByUser[$u] = $row;
            }
        $keys = sas_cache_username_match_keys($u);
        if (!$keys) {
            continue;
        }
        $ip = sas_cache_framed_ip($row);
        $mac = function_exists('sas_cache_mac') ? sas_cache_mac($row) : '';
        $tr = sas_cache_daily_traffic($row);
        $ping = sas_cache_online_ping($row);
        $rad = sas_cache_radacct_id($row);
        foreach ($keys as $key) {
            $names[$key] = $u;
            if ($ip !== '') {
                $ipMap[$key] = sas_clip($ip, 45);
            }
            if ($mac !== '') {
                $macMap[$key] = sas_clip($mac, 32);
            }
            if ($tr !== '') {
                $trafMap[$key] = sas_clip($tr, 60);
            }
            if ($ping !== null) {
                $pingMap[$key] = (int) $ping;
            }
            if ($rad !== '') {
                $radMap[$key] = $rad;
            }
        }
    }
    try {
        // امسح حالة الاتصال والـ IP ثم عبّ من قائمة الأونلاين الحقيقية
        $tidOnline = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        if ($tidOnline <= 0) {
            $tidOnline = 1;
        }
        $pdo->exec('UPDATE sas_users_cache SET is_online = 0 WHERE tenant_id = ' . $tidOnline . ' AND is_online = 1');
        if ($names) {
            $onlineKeys = array_keys($names);
            $chunks = array_chunk($onlineKeys, 40);
            foreach ($chunks as $chunk) {
                $in = array();
                $bind = array();
                $caseIp = 'CASE LOWER(username)';
                $caseMac = 'CASE LOWER(username)';
                $caseTr = 'CASE LOWER(username)';
                $casePing = 'CASE LOWER(username)';
                $hasIp = false;
                $hasMac = false;
                $hasTr = false;
                $hasPing = false;
                $i = 0;
                foreach ($chunk as $key) {
                    $in[] = ':in' . $i;
                    $bind[':in' . $i] = $key;
                    if (isset($ipMap[$key]) && $ipMap[$key] !== '') {
                        $caseIp .= ' WHEN :ik' . $i . ' THEN :ip' . $i;
                        $bind[':ik' . $i] = $key;
                        $bind[':ip' . $i] = $ipMap[$key];
                        $hasIp = true;
                    }
                    if (isset($macMap[$key]) && $macMap[$key] !== '') {
                        $caseMac .= ' WHEN :mk' . $i . ' THEN :mv' . $i;
                        $bind[':mk' . $i] = $key;
                        $bind[':mv' . $i] = $macMap[$key];
                        $hasMac = true;
                    }
                    if (isset($trafMap[$key]) && $trafMap[$key] !== '') {
                        $caseTr .= ' WHEN :tk' . $i . ' THEN :tr' . $i;
                        $bind[':tk' . $i] = $key;
                        $bind[':tr' . $i] = $trafMap[$key];
                        $hasTr = true;
                    }
                    if (isset($pingMap[$key]) && (int) $pingMap[$key] > 0) {
                        $casePing .= ' WHEN :pgk' . $i . ' THEN :pgv' . $i;
                        $bind[':pgk' . $i] = $key;
                        $bind[':pgv' . $i] = (int) $pingMap[$key];
                        $hasPing = true;
                    }
                    $i++;
                }
                $set = 'is_online = 1, last_online = NOW()';
                if ($hasIp) {
                    $set .= ', framed_ip = ' . $caseIp . ' ELSE framed_ip END';
                }
                if ($hasMac) {
                    $set .= ', mac_address = ' . $caseMac . ' ELSE mac_address END';
                }
                if ($hasTr) {
                    $set .= ', daily_traffic = ' . $caseTr . ' ELSE daily_traffic END';
                }
                if ($hasPing) {
                    $set .= ', online_ping = ' . $casePing . ' ELSE online_ping END';
                }
                $sqlUp = 'UPDATE sas_users_cache SET ' . $set
                    . ' WHERE tenant_id = ' . $tidOnline
                    . ' AND LOWER(username) IN (' . implode(',', $in) . ')';
                $pdo->prepare($sqlUp)->execute($bind);
            }
        }
        $radDir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($radDir)) {
            @mkdir($radDir, 0775, true);
        }
        @file_put_contents($radDir . '/sub_rad_t' . $tidOnline . '.json', json_encode($radMap));
        try {
            sas_sessions_note_online($pdo, $rowByUser);
        } catch (Exception $e2) {
        }
        @file_put_contents($throttleFile, (string) time());
        if (isset($api->lastOnlineTotal) && (int) $api->lastOnlineTotal > 900) {
            @file_put_contents($heavyFile, 'full ' . time());
        }
        if ($lockFh) {
            @flock($lockFh, LOCK_UN);
            @fclose($lockFh);
            $lockFh = null;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['sas_online_flags_at'] = time();
        }
    } catch (Exception $e) {
        return 0;
    }
    return count($names);
}

/**
 * تحديث أونلاين/IP مع حد أدنى بين الطلبات (للبحث السريع).
 * $force=true يتجاوز الانتظار.
 */
function sas_refresh_online_flags_throttled($pdo, $config, $minInterval = 4, $force = false)
{
    $minInterval = (int) $minInterval;
    if ($minInterval < 0) {
        $minInterval = 0;
    }
    $at = 0;
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['sas_online_flags_at'])) {
        $at = (int) $_SESSION['sas_online_flags_at'];
    }
    $fileAt = @file_get_contents(sas_online_flags_throttle_file());
    if ($fileAt !== false && (int) $fileAt > $at) {
        $at = (int) $fileAt;
    }
    if (!$force && $at > 0 && $minInterval > 0 && (time() - $at) < $minInterval) {
        return -1;
    }
    return sas_refresh_online_flags($pdo, $config);
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

function sas_subscriber_identity_ok($row, $username, $sasUserId)
{
    if (!is_array($row) || empty($row['id'])) {
        return false;
    }
    $uname = isset($row['sas_username']) ? trim((string) $row['sas_username']) : '';
    $sid = isset($row['sas_user_id']) ? (int) $row['sas_user_id'] : 0;
    $username = trim((string) $username);
    $sasUserId = (int) $sasUserId;
    if ($username !== '' && $uname !== '' && strcasecmp($uname, $username) !== 0) {
        return false;
    }
    if ($sasUserId > 0 && $sid > 0 && $sid !== $sasUserId) {
        return false;
    }
    return true;
}

function sas_cache_pick_local($pdo, $username, $sasUserId, $phone, $tenantId = 0)
{
    $username = trim((string) $username);
    $sasUserId = (int) $sasUserId;
    $tid = (int) $tenantId;
    if ($tid <= 0) {
        $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    }
    if ($tid <= 0) {
        $tid = 1;
    }
    $tenantSql = ' AND tenant_id = ' . (int) $tid;

    if ($sasUserId > 0) {
        try {
            $st = $pdo->prepare('SELECT * FROM subscribers WHERE sas_user_id = :id' . $tenantSql . ' LIMIT 1');
            $st->execute(array(':id' => $sasUserId));
            $row = $st->fetch();
            if ($row) {
                return $row;
            }
        } catch (Exception $e) {
            $st = $pdo->prepare('SELECT * FROM subscribers WHERE sas_user_id = :id LIMIT 1');
            $st->execute(array(':id' => $sasUserId));
            $row = $st->fetch();
            if ($row) {
                return $row;
            }
        }
    }

    if ($username !== '') {
        try {
            $st = $pdo->prepare('SELECT * FROM subscribers WHERE sas_username = :u' . $tenantSql . ' LIMIT 1');
            $st->execute(array(':u' => $username));
            $row = $st->fetch();
            if ($row) {
                return $row;
            }
        } catch (Exception $e) {
            $st = $pdo->prepare('SELECT * FROM subscribers WHERE sas_username = :u LIMIT 1');
            $st->execute(array(':u' => $username));
            $row = $st->fetch();
            if ($row) {
                return $row;
            }
        }
    }

    $digits = sas_phone_digits($phone !== '' ? $phone : $username);
    if (strlen($digits) < 8) {
        return null;
    }

    try {
        $st = $pdo->prepare(
            'SELECT s.*,
                (SELECT COALESCE(SUM(amount),0) FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt
             FROM subscribers s
             WHERE (REPLACE(REPLACE(REPLACE(s.phone, "+", ""), "-", ""), " ", "") LIKE :tail
                OR s.phone LIKE :q)' . $tenantSql . '
             ORDER BY debt DESC, s.id ASC'
        );
        $st->execute(array(
            ':tail' => '%' . $digits,
            ':q' => '%' . $digits . '%',
        ));
        $matches = $st->fetchAll();
    } catch (Exception $e) {
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
    }
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
    $free = array();
    foreach ($pool as $m) {
        if (!sas_subscriber_identity_ok($m, $username, $sasUserId)) {
            continue;
        }
        $free[] = $m;
    }
    if (count($free) === 1) {
        return $free[0];
    }
    return null;
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
    $rowTid = sas_cache_row_tenant($cacheRow);
    if (!empty($cacheRow['local_subscriber_id'])) {
        try {
            $stKeep = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id LIMIT 1');
            $stKeep->execute(array(':id' => (int) $cacheRow['local_subscriber_id']));
            $kept = $stKeep->fetch();
            if ($kept && sas_subscriber_identity_ok($kept, $username, $sasUserId)) {
                $id = sas_cache_link_local_fields($pdo, $kept, $username, $sasUserId);
                return array($id, '');
            }
        } catch (Exception $e) {
        }
    }
    $local = sas_cache_pick_local($pdo, $username, $sasUserId, $phone, $rowTid);
    if ($local) {
        $id = sas_cache_link_local_fields($pdo, $local, $username, $sasUserId);
        $tidLink = $rowTid;
        try {
            $pdo->prepare('UPDATE sas_users_cache SET local_subscriber_id = :lid WHERE username = :u AND tenant_id = :t')
                ->execute(array(':lid' => $id, ':u' => $username, ':t' => $tidLink));
        } catch (Exception $e) {
        }
        // إذا المحلي وهمي والكاش فيه رقم حقيقي — حدّث المحلي
        $localPhone = isset($local['phone']) ? (string) $local['phone'] : '';
        if ($phone !== '' && function_exists('phone_is_placeholder') && phone_is_placeholder($localPhone)
            && function_exists('sas_cache_sync_local_phone')
        ) {
            sas_cache_sync_local_phone($pdo, $username, $phone);
        } elseif ($phone !== '' && $localPhone === '' && function_exists('sas_cache_sync_local_phone')) {
            sas_cache_sync_local_phone($pdo, $username, $phone);
        }
        return array($id, '');
    }

    $display = isset($cacheRow['display_name']) ? $cacheRow['display_name'] : $username;
    $name = sas_cache_unique_name($pdo, $display, $username);
    $phoneStore = $phone !== '' ? normalize_phone($phone) : '';
    if ($phoneStore === '' || (function_exists('phone_is_placeholder') && phone_is_placeholder($phoneStore))) {
        // لا نخزّن رقم وهمي — نخلي فاضي ونكمّل من الكاش لاحقاً
        $phoneStore = $phone !== '' ? trim($phone) : '';
        if ($phoneStore === '') {
            $phoneStore = '0';
        }
    }
    $planId = sas_cache_plan_id_for_profile($pdo, isset($cacheRow['profile_id']) ? $cacheRow['profile_id'] : 0);
    $agentId = 0;
    $parentId = isset($cacheRow['parent_id']) ? (int) $cacheRow['parent_id'] : 0;
    if ($parentId > 0) {
        try {
            $tidScope = $rowTid;
            $stAg = $pdo->prepare(
                'SELECT id FROM admin_users
                 WHERE role = "agent" AND sas_manager_id = :m AND is_active = 1
                   AND tenant_id = :t
                 LIMIT 1'
            );
            $stAg->execute(array(':m' => $parentId, ':t' => $tidScope));
            $agentId = (int) $stAg->fetchColumn();
        } catch (Exception $e) {
            $agentId = 0;
        }
    }
    if ($agentId <= 0 && function_exists('is_agent_user') && is_agent_user() && function_exists('current_admin')) {
        $me = current_admin();
        $agentId = $me ? (int) $me['id'] : 0;
    } elseif ($agentId <= 0 && function_exists('default_admin_user_id')) {
        $agentId = default_admin_user_id($pdo);
    }

    $graceDef = null; // حسب النظام
    $tidIns = $rowTid;
    $params = array(
        ':name' => $name,
        ':phone' => $phoneStore,
        ':notes' => 'من SAS — ' . $username,
        ':plan_id' => $planId > 0 ? $planId : null,
        ':agent_id' => $agentId > 0 ? $agentId : null,
        ':sas_u' => $username,
        ':sas_id' => $sasUserId > 0 ? $sasUserId : null,
        ':grace' => $graceDef,
        ':tid' => $tidIns,
    );
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO subscribers (name, phone, notes, preferred_plan_id, agent_user_id, sas_username, sas_user_id, grace_days, tenant_id)
             VALUES (:name, :phone, :notes, :plan_id, :agent_id, :sas_u, :sas_id, :grace, :tid)'
        );
        $stmt->execute($params);
    } catch (Exception $e) {
        unset($params[':grace']);
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO subscribers (name, phone, notes, preferred_plan_id, agent_user_id, sas_username, sas_user_id, tenant_id)
                 VALUES (:name, :phone, :notes, :plan_id, :agent_id, :sas_u, :sas_id, :tid)'
            );
            $stmt->execute($params);
        } catch (Exception $e2) {
            unset($params[':tid']);
            $stmt = $pdo->prepare(
                'INSERT INTO subscribers (name, phone, notes, preferred_plan_id, agent_user_id, sas_username, sas_user_id)
                 VALUES (:name, :phone, :notes, :plan_id, :agent_id, :sas_u, :sas_id)'
            );
            $stmt->execute($params);
        }
    }
    $newId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE sas_users_cache SET local_subscriber_id = :lid WHERE username = :u AND tenant_id = :t')
        ->execute(array(':lid' => $newId, ':u' => $username, ':t' => $tidIns));
    return array($newId, '');
}

function sas_cache_row_tenant($cacheRow)
{
    $tid = (is_array($cacheRow) && isset($cacheRow['tenant_id'])) ? (int) $cacheRow['tenant_id'] : 0;
    if ($tid > 0) {
        return $tid;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    return $tid > 0 ? $tid : 1;
}

function sas_cache_visible_tenant_ids($pdo)
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    $ids = array($tid => $tid);
    if (!$pdo) {
        return array_values($ids);
    }
    $locked = (function_exists('is_agent_user') && is_agent_user())
        || (function_exists('is_group_manager_user') && is_group_manager_user())
        || (function_exists('account_viewer_is_leaf_child') && account_viewer_is_leaf_child($pdo));
    if ($locked || !function_exists('sas_ownership_search_tenant_ids')) {
        return array_values($ids);
    }
    $extra = sas_ownership_search_tenant_ids($pdo);
    if (is_array($extra)) {
        foreach ($extra as $x) {
            $x = (int) $x;
            if ($x > 0) {
                $ids[$x] = $x;
            }
        }
    }
    return array_values($ids);
}

function sas_cache_pick_visible_row($rows)
{
    if (!$rows || !is_array($rows)) {
        return null;
    }
    if (count($rows) === 1) {
        return $rows[0];
    }
    $withLocal = array();
    foreach ($rows as $row) {
        if (is_array($row) && !empty($row['local_subscriber_id'])) {
            $withLocal[] = $row;
        }
    }
    if (count($withLocal) === 1) {
        return $withLocal[0];
    }
    return null;
}

function sas_cache_get($pdo, $username)
{
    $username = trim((string) $username);
    if ($username === '') {
        return null;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    try {
        $st = $pdo->prepare('SELECT * FROM sas_users_cache WHERE username = :u AND tenant_id = :t LIMIT 1');
        $st->execute(array(':u' => $username, ':t' => $tid));
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
    } catch (Exception $e) {
    }
    $ids = sas_cache_visible_tenant_ids($pdo);
    $in = array();
    foreach ($ids as $x) {
        $x = (int) $x;
        if ($x > 0 && $x !== $tid) {
            $in[$x] = $x;
        }
    }
    if ($in) {
        try {
            $st = $pdo->prepare(
                'SELECT * FROM sas_users_cache WHERE username = :u AND tenant_id IN (' . implode(',', $in) . ')'
            );
            $st->execute(array(':u' => $username));
            $picked = sas_cache_pick_visible_row($st->fetchAll());
            if ($picked) {
                return $picked;
            }
        } catch (Exception $e) {
        }
    }
    if ($tid === 1) {
        try {
            $st = $pdo->prepare('SELECT * FROM sas_users_cache WHERE username = :u');
            $st->execute(array(':u' => $username));
            $picked = sas_cache_pick_visible_row($st->fetchAll());
            if ($picked) {
                return $picked;
            }
        } catch (Exception $e) {
        }
    }
    return null;
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
        $price = 0;
        foreach (array('price', 'user_price', 'unit_price', 'monthly_price') as $pk) {
            if (isset($p[$pk]) && is_numeric($p[$pk])) {
                $price = (float) $p[$pk];
                break;
            }
        }
        $out[] = array('id' => $id, 'name' => $name !== '' ? $name : ('#' . $id), 'price' => $price);
    }
    if ($out) {
        $_SESSION['sas_profiles_ui'] = $out;
        $_SESSION['sas_profiles_ui_at'] = time();
        if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_touch')) {
            app_session_touch();
        }
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
    foreach (array('managers', 'agents', 'rows', 'items') as $wrapKey) {
        if (isset($rows[$wrapKey]) && is_array($rows[$wrapKey])) {
            $rows = $rows[$wrapKey];
            break;
        }
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
        $name = !empty($m['username']) ? (string) $m['username'] : '';
        if ($name === '' && isset($m['user']) && is_array($m['user']) && !empty($m['user']['username'])) {
            $name = (string) $m['user']['username'];
        }
        if ($name === '' && function_exists('sas_row_name')) {
            $name = sas_row_name($m);
        }
        if ($id <= 0) {
            continue;
        }
        $out[] = array('id' => $id, 'name' => $name !== '' ? $name : ('#' . $id));
    }
    if ($out) {
        $_SESSION['sas_managers_ui'] = $out;
        $_SESSION['sas_managers_ui_at'] = time();
        if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_touch')) {
            app_session_touch();
        }
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
        $_SESSION['sas_unused_ui_v4'],
        $_SESSION['sas_unused_ui_v4_at'],
        $_SESSION['sas_unused_ui_v5'],
        $_SESSION['sas_unused_ui_v5_at'],
        $_SESSION['sas_unused_ui_v6'],
        $_SESSION['sas_unused_ui_v6_at'],
        $_SESSION['sas_card_groups'],
        $_SESSION['sas_card_groups_at'],
        $_SESSION['sas_card_groups_v2'],
        $_SESSION['sas_card_groups_v2_at'],
        $_SESSION['sas_card_groups_v5'],
        $_SESSION['sas_card_groups_v5_at']
    );
}

/**
 * كروت استُخدمت مؤخراً — تمنع إعادة اختيارها لو الساس لسا ما حدّث الجرد.
 */
function sas_remember_used_pin($pin)
{
    $pin = trim((string) $pin);
    if ($pin === '') {
        return;
    }
    if (!isset($_SESSION['sas_pins_used_recent']) || !is_array($_SESSION['sas_pins_used_recent'])) {
        $_SESSION['sas_pins_used_recent'] = array();
    }
    $now = time();
    $_SESSION['sas_pins_used_recent'][strtolower($pin)] = $now;
    foreach ($_SESSION['sas_pins_used_recent'] as $k => $ts) {
        if (($now - (int) $ts) > 900) {
            unset($_SESSION['sas_pins_used_recent'][$k]);
        }
    }
}

function sas_pin_is_recently_used($pin)
{
    $pin = strtolower(trim((string) $pin));
    if ($pin === '' || empty($_SESSION['sas_pins_used_recent']) || !is_array($_SESSION['sas_pins_used_recent'])) {
        return false;
    }
    if (!isset($_SESSION['sas_pins_used_recent'][$pin])) {
        return false;
    }
    $ts = (int) $_SESSION['sas_pins_used_recent'][$pin];
    if ($ts <= 0 || (time() - $ts) > 900) {
        unset($_SESSION['sas_pins_used_recent'][$pin]);
        return false;
    }
    return true;
}

function sas_filter_recently_used_cards($cards)
{
    if (!is_array($cards) || !$cards) {
        return is_array($cards) ? $cards : array();
    }
    $out = array();
    foreach ($cards as $c) {
        if (!is_array($c)) {
            continue;
        }
        $p = isset($c['pin']) ? trim((string) $c['pin']) : '';
        if ($p !== '' && sas_pin_is_recently_used($p)) {
            continue;
        }
        $out[] = $c;
    }
    return $out;
}

/**
 * يشيل كرت من قائمة الشاغر فوراً بعد التفعيل/الرفض حتى ما ينعاد استخدامه من الكاش.
 */
function sas_mark_card_pin_used($pin)
{
    $pin = trim((string) $pin);
    if ($pin === '') {
        return false;
    }
    sas_remember_used_pin($pin);
    $changed = false;
    if (function_exists('sas_cards_inventory_load_persisted') && function_exists('sas_cards_inventory_save_persisted')) {
        $inv = sas_cards_inventory_load_persisted(0);
        if ($inv && !empty($inv['groups']) && is_array($inv['groups'])) {
            $groups = $inv['groups'];
            foreach ($groups as $gi => $g) {
                if (!is_array($g) || empty($g['cards']) || !is_array($g['cards'])) {
                    continue;
                }
                $groupChanged = false;
                foreach ($g['cards'] as $ci => $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $cPin = isset($c['pin']) ? trim((string) $c['pin']) : '';
                    if ($cPin === '' || strcasecmp($cPin, $pin) !== 0) {
                        continue;
                    }
                    if (empty($c['used'])) {
                        $groups[$gi]['cards'][$ci]['used'] = 1;
                        $groupChanged = true;
                        $changed = true;
                    }
                }
                if ($groupChanged) {
                    $curFree = isset($groups[$gi]['unused']) ? (int) $groups[$gi]['unused'] : 0;
                    $sample = isset($groups[$gi]['cards']) && is_array($groups[$gi]['cards']) ? count($groups[$gi]['cards']) : 0;
                    if (!empty($groups[$gi]['counts_only']) || $curFree > $sample) {
                        $groups[$gi]['unused'] = max(0, $curFree - 1);
                        if (isset($groups[$gi]['used'])) {
                            $groups[$gi]['used'] = (int) $groups[$gi]['used'] + 1;
                        }
                    } else {
                        $unused = 0;
                        foreach ($groups[$gi]['cards'] as $c2) {
                            if (is_array($c2) && empty($c2['used'])) {
                                $p2 = isset($c2['pin']) ? trim((string) $c2['pin']) : '';
                                if ($p2 !== '' && strlen($p2) >= 6 && !sas_pin_is_recently_used($p2)) {
                                    $unused++;
                                }
                            }
                        }
                        $groups[$gi]['unused'] = $unused;
                    }
                }
            }
            if ($changed) {
                sas_cards_inventory_save_persisted($groups);
                if (function_exists('sas_dash_groups_from_inventory') && function_exists('sas_store_dash_card_groups')) {
                    sas_store_dash_card_groups(sas_dash_groups_from_inventory($groups), 'inventory');
                }
            }
        }
    }
    sas_clear_unused_card_cache();
    // أعد بناء قائمة الشاغر من الجرد المحدّث بدون انتظار API
    if (function_exists('sas_unused_pins_from_inventory_cache')) {
        $fromInv = sas_unused_pins_from_inventory_cache();
        if (is_array($fromInv)) {
            $_SESSION['sas_unused_ui_v6'] = $fromInv;
            $_SESSION['sas_unused_ui_v6_at'] = time();
        }
    }
    if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_touch')) {
        app_session_touch();
    }
    return $changed;
}

function sas_unused_cards_snapshot_for_ui()
{
    if (function_exists('sas_unused_pins_from_inventory_cache')) {
        $fromInv = sas_unused_pins_from_inventory_cache();
        if (is_array($fromInv)) {
            return $fromInv;
        }
    }
    if (!empty($_SESSION['sas_unused_ui_v6']) && is_array($_SESSION['sas_unused_ui_v6'])) {
        return sas_filter_recently_used_cards($_SESSION['sas_unused_ui_v6']);
    }
    return array();
}

function sas_cards_server_cache_dir()
{
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function sas_cards_tenant_suffix()
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    return 't' . $tid;
}

function sas_cards_server_cache_path()
{
    return sas_cards_server_cache_dir() . '/dash_cards_page_' . sas_cards_tenant_suffix() . '.json';
}

function sas_cards_inventory_cache_path()
{
    return sas_cards_server_cache_dir() . '/cards_inventory_own_' . sas_cards_tenant_suffix() . '.json';
}

function sas_dash_cards_build_payload($groups, $source = 'dash')
{
    if (!is_array($groups)) {
        $groups = array();
    }
    $total = 0;
    $parts = array();
    foreach ($groups as $g) {
        if (!is_array($g)) {
            continue;
        }
        $n = isset($g['count']) ? (int) $g['count'] : 0;
        if ($n <= 0) {
            continue;
        }
        $total += $n;
        $nm = isset($g['name']) ? (string) $g['name'] : '';
        $parts[] = trim($nm . ' ' . $n);
    }
    return array(
        'updated_at' => time(),
        'source' => (string) $source,
        'card_total' => $total,
        'card_sub' => $parts ? implode(' · ', $parts) : '',
        'groups' => array_values($groups),
    );
}

function sas_dash_cards_load_persisted()
{
    $path = sas_cards_server_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['groups']) || !is_array($data['groups'])) {
        return null;
    }
    return $data;
}

/**
 * يفضّل جرد صفحة الكروت (المصدر الأدق) على عدّ الداش القديم.
 */
function sas_dash_cards_preferred_persisted()
{
    $cur = sas_dash_cards_load_persisted();
    $curTotal = ($cur && isset($cur['card_total'])) ? (int) $cur['card_total'] : 0;
    if ($cur && $curTotal > 0 && !empty($cur['groups']) && is_array($cur['groups'])) {
        return $cur;
    }
    $inv = function_exists('sas_cards_inventory_load_persisted')
        ? sas_cards_inventory_load_persisted(0)
        : null;
    if ($inv && !empty($inv['groups']) && is_array($inv['groups'])) {
        $groups = sas_dash_groups_from_inventory($inv['groups']);
        if ($groups) {
            $payload = sas_dash_cards_build_payload($groups, 'inventory');
            $payload['updated_at'] = isset($inv['updated_at']) ? (int) $inv['updated_at'] : time();
            return $payload;
        }
    }
    if ($cur && isset($cur['groups']) && is_array($cur['groups'])) {
        return $cur;
    }
    return null;
}

function sas_dash_cards_save_persisted($groups, $source = 'dash')
{
    $payload = sas_dash_cards_build_payload($groups, $source);
    // لا نستبدل كاش inventory أدق بعدّ dash أقدم/أعلى خطأً
    if ($source !== 'inventory') {
        $cur = sas_dash_cards_load_persisted();
        if ($cur && isset($cur['source']) && $cur['source'] === 'inventory') {
            $curAt = isset($cur['updated_at']) ? (int) $cur['updated_at'] : 0;
            $curTotal = isset($cur['card_total']) ? (int) $cur['card_total'] : 0;
            $newTotal = isset($payload['card_total']) ? (int) $payload['card_total'] : 0;
            // إذا inventory حديث (أقل من 30 دقيقة) والرقم الجديد أكبر بكثير → تجاهل الكتابة الخاطئة
            if ($curAt > 0 && (time() - $curAt) < 1800 && $newTotal > $curTotal && $curTotal > 0) {
                return $cur;
            }
        }
    }
    $path = sas_cards_server_cache_path();
    $json = json_encode($payload);
    if ($json === false) {
        return $payload;
    }
    @file_put_contents($path, $json, LOCK_EX);
    return $payload;
}

function sas_cards_inventory_load_persisted($maxAge = 300)
{
    $path = sas_cards_inventory_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['groups']) || !is_array($data['groups'])) {
        return null;
    }
    $at = isset($data['updated_at']) ? (int) $data['updated_at'] : 0;
    if ($maxAge > 0 && $at > 0 && (time() - $at) > $maxAge) {
        return null;
    }
    return $data;
}

function sas_groups_keep_known_pins($groups, $prev)
{
    if (!is_array($groups) || !$prev || empty($prev['groups']) || !is_array($prev['groups'])) {
        return is_array($groups) ? $groups : array();
    }
    $old = array();
    foreach ($prev['groups'] as $g) {
        if (!is_array($g) || empty($g['cards']) || !is_array($g['cards'])) {
            continue;
        }
        $name = isset($g['name']) ? strtolower(trim((string) $g['name'])) : '';
        $soft = function_exists('card_price_soft_key') ? card_price_soft_key($name) : $name;
        if ($soft === '') {
            continue;
        }
        $pins = array();
        foreach ($g['cards'] as $c) {
            if (!is_array($c) || !empty($c['used'])) {
                continue;
            }
            $pin = isset($c['pin']) ? trim((string) $c['pin']) : '';
            if ($pin !== '' && strlen($pin) >= 6) {
                $pins[] = $c;
            }
        }
        if ($pins && !isset($old[$soft])) {
            $old[$soft] = $pins;
        }
    }
    foreach ($groups as $i => $g) {
        if (!is_array($g)) {
            continue;
        }
        $unused = isset($g['unused']) ? (int) $g['unused'] : 0;
        if ($unused < 1) {
            continue;
        }
        $name = isset($g['name']) ? strtolower(trim((string) $g['name'])) : '';
        $soft = function_exists('card_price_soft_key') ? card_price_soft_key($name) : $name;
        if ($soft === '' || empty($old[$soft])) {
            continue;
        }
        $have = 0;
        if (!empty($g['cards']) && is_array($g['cards'])) {
            foreach ($g['cards'] as $c) {
                if (is_array($c) && empty($c['used']) && !empty($c['pin']) && strlen(trim((string) $c['pin'])) >= 6) {
                    $have++;
                }
            }
        }
        if ($have > 0) {
            continue;
        }
        $keep = array_slice($old[$soft], 0, $unused);
        if (!$keep) {
            continue;
        }
        if (!isset($groups[$i]['cards']) || !is_array($groups[$i]['cards'])) {
            $groups[$i]['cards'] = array();
        }
        $groups[$i]['cards'] = array_merge($keep, $groups[$i]['cards']);
    }
    return $groups;
}

function sas_inventory_ensure_free_pins($api, $groups)
{
    if (!is_array($groups)) {
        $groups = array();
    }
    $prev = function_exists('sas_cards_inventory_load_persisted') ? sas_cards_inventory_load_persisted(0) : null;
    $groups = sas_groups_keep_known_pins($groups, $prev);
    $pack = array('groups' => $groups);
    if (!function_exists('sas_inventory_needs_free_pins') || !sas_inventory_needs_free_pins($pack)) {
        return $groups;
    }
    if (!$api || !method_exists($api, 'listOwnedFreePins')) {
        return $groups;
    }
    $live = sas_cards_rows_to_ui($api->listOwnedFreePins(20));
    if (function_exists('sas_pins_capped_by_inventory')) {
        $live = sas_pins_capped_by_inventory($live, $pack);
    }
    if (!is_array($live) || !$live) {
        return $groups;
    }
    foreach ($live as $pinRow) {
        if (!is_array($pinRow)) {
            continue;
        }
        $pin = isset($pinRow['pin']) ? trim((string) $pinRow['pin']) : '';
        $name = isset($pinRow['profile_name']) ? strtolower(trim((string) $pinRow['profile_name'])) : '';
        $soft = function_exists('card_price_soft_key') ? card_price_soft_key($name) : $name;
        if ($pin === '' || $soft === '') {
            continue;
        }
        foreach ($groups as $i => $g) {
            if (!is_array($g)) {
                continue;
            }
            $gName = isset($g['name']) ? strtolower(trim((string) $g['name'])) : '';
            $gSoft = function_exists('card_price_soft_key') ? card_price_soft_key($gName) : $gName;
            if ($gSoft !== $soft) {
                continue;
            }
            if (!isset($groups[$i]['cards']) || !is_array($groups[$i]['cards'])) {
                $groups[$i]['cards'] = array();
            }
            $exists = false;
            foreach ($groups[$i]['cards'] as $c) {
                if (is_array($c) && isset($c['pin']) && strcasecmp((string) $c['pin'], $pin) === 0) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $groups[$i]['cards'][] = array(
                    'pin' => $pin,
                    'used' => 0,
                    'profile_id' => isset($pinRow['profile_id']) ? (int) $pinRow['profile_id'] : 0,
                );
            }
            break;
        }
    }
    return $groups;
}

function sas_cards_inventory_save_persisted($groups)
{
    if (!is_array($groups)) {
        $groups = array();
    }
    $prev = sas_cards_inventory_load_persisted(0);
    $groups = sas_groups_keep_known_pins($groups, $prev);
    $payload = array(
        'updated_at' => time(),
        'groups' => array_values($groups),
    );
    $json = json_encode($payload);
    if ($json === false) {
        return false;
    }
    return @file_put_contents(sas_cards_inventory_cache_path(), $json, LOCK_EX) !== false;
}

function sas_cards_inventory_forget()
{
    $path = sas_cards_inventory_cache_path();
    if (is_file($path)) {
        @unlink($path);
    }
    unset(
        $_SESSION['sas_card_groups_v5'],
        $_SESSION['sas_card_groups_v5_at'],
        $_SESSION['sas_card_groups_v2'],
        $_SESSION['sas_card_groups_v2_at'],
        $_SESSION['sas_unused_ui_v6'],
        $_SESSION['sas_unused_ui_v6_at']
    );
}

function sas_store_dash_card_groups($groups, $source = 'dash')
{
    if (!is_array($groups)) {
        $groups = array();
    }
    $_SESSION['sas_card_groups_v5'] = $groups;
    $_SESSION['sas_card_groups_v5_at'] = time();
    $_SESSION['sas_card_groups_v2'] = $groups;
    $_SESSION['sas_card_groups_v2_at'] = time();
    sas_dash_cards_save_persisted($groups, $source);
}

function sas_dash_groups_from_inventory($inventory)
{
    $groups = array();
    if (!is_array($inventory)) {
        return $groups;
    }
    foreach ($inventory as $g) {
        if (!is_array($g)) {
            continue;
        }
        $n = isset($g['unused']) ? (int) $g['unused'] : 0;
        if ($n <= 0) {
            continue;
        }
        $groups[] = array(
            'profile_id' => isset($g['profile_id']) ? (int) $g['profile_id'] : 0,
            'name' => isset($g['name']) ? (string) $g['name'] : '',
            'count' => $n,
        );
    }
    usort($groups, function ($a, $b) {
        if ($a['count'] === $b['count']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return ($a['count'] > $b['count']) ? -1 : 1;
    });
    return $groups;
}

function sas_inventory_needs_free_pins($inv)
{
    if (!$inv || empty($inv['groups']) || !is_array($inv['groups'])) {
        return false;
    }
    foreach ($inv['groups'] as $g) {
        if (!is_array($g)) {
            continue;
        }
        $unused = isset($g['unused']) ? (int) $g['unused'] : 0;
        if ($unused <= 0) {
            continue;
        }
        $freePins = 0;
        if (!empty($g['cards']) && is_array($g['cards'])) {
            foreach ($g['cards'] as $c) {
                if (!is_array($c) || !empty($c['used'])) {
                    continue;
                }
                $pin = isset($c['pin']) ? trim((string) $c['pin']) : '';
                if ($pin !== '' && strlen($pin) >= 6) {
                    $freePins++;
                }
            }
        }
        if ($freePins < 1) {
            return true;
        }
    }
    return false;
}

function sas_pins_capped_by_inventory($pins, $inv)
{
    if (!is_array($pins) || !$inv || empty($inv['groups']) || !is_array($inv['groups'])) {
        return array();
    }
    $allow = array();
    foreach ($inv['groups'] as $g) {
        if (!is_array($g)) {
            continue;
        }
        $unused = isset($g['unused']) ? (int) $g['unused'] : 0;
        $name = isset($g['name']) ? strtolower(trim((string) $g['name'])) : '';
        if ($unused <= 0 || $name === '') {
            continue;
        }
        $soft = function_exists('card_price_soft_key') ? card_price_soft_key($name) : $name;
        if (!isset($allow[$soft])) {
            $allow[$soft] = 0;
        }
        $allow[$soft] += $unused;
    }
    $out = array();
    $used = array();
    foreach ($pins as $pinRow) {
        if (!is_array($pinRow)) {
            continue;
        }
        $name = isset($pinRow['profile_name']) ? strtolower(trim((string) $pinRow['profile_name'])) : '';
        $soft = function_exists('card_price_soft_key') ? card_price_soft_key($name) : $name;
        if ($soft === '' || !isset($allow[$soft])) {
            continue;
        }
        if (!isset($used[$soft])) {
            $used[$soft] = 0;
        }
        if ($used[$soft] >= $allow[$soft]) {
            continue;
        }
        $out[] = $pinRow;
        $used[$soft]++;
    }
    return $out;
}

function sas_inventory_store_free_pins($pins)
{
    if (!is_array($pins) || !$pins || !function_exists('sas_cards_inventory_load_persisted')) {
        return;
    }
    $inv = sas_cards_inventory_load_persisted(0);
    if (!$inv || empty($inv['groups']) || !is_array($inv['groups'])) {
        return;
    }
    $groups = $inv['groups'];
    foreach ($pins as $pinRow) {
        if (!is_array($pinRow)) {
            continue;
        }
        $pin = isset($pinRow['pin']) ? trim((string) $pinRow['pin']) : '';
        $name = isset($pinRow['profile_name']) ? strtolower(trim((string) $pinRow['profile_name'])) : '';
        if ($pin === '' || $name === '') {
            continue;
        }
        foreach ($groups as $i => $g) {
            if (!is_array($g)) {
                continue;
            }
            $gName = isset($g['name']) ? strtolower(trim((string) $g['name'])) : '';
            if ($gName !== $name) {
                continue;
            }
            if (!isset($groups[$i]['cards']) || !is_array($groups[$i]['cards'])) {
                $groups[$i]['cards'] = array();
            }
            $exists = false;
            foreach ($groups[$i]['cards'] as $c) {
                if (is_array($c) && isset($c['pin']) && strcasecmp((string) $c['pin'], $pin) === 0) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $groups[$i]['cards'][] = array(
                    'pin' => $pin,
                    'used' => 0,
                    'profile_id' => isset($pinRow['profile_id']) ? (int) $pinRow['profile_id'] : 0,
                );
            }
            break;
        }
    }
    if (function_exists('sas_cards_inventory_save_persisted')) {
        sas_cards_inventory_save_persisted($groups);
    }
}

function sas_unused_pins_from_inventory_cache()
{
    if (!function_exists('sas_cards_inventory_load_persisted')) {
        return null;
    }
    $inv = sas_cards_inventory_load_persisted(0);
    if (!$inv || empty($inv['groups']) || !is_array($inv['groups'])) {
        return null;
    }
    $out = array();
    $seen = array();
    foreach ($inv['groups'] as $g) {
        if (!is_array($g) || empty($g['cards']) || !is_array($g['cards'])) {
            continue;
        }
        $pid = isset($g['profile_id']) ? (int) $g['profile_id'] : 0;
        $pname = isset($g['name']) ? (string) $g['name'] : '';
        foreach ($g['cards'] as $c) {
            if (!is_array($c) || !empty($c['used'])) {
                continue;
            }
            $pin = isset($c['pin']) ? trim((string) $c['pin']) : '';
            if ($pin === '' || strlen($pin) < 6) {
                continue;
            }
            if (function_exists('sas_pin_is_recently_used') && sas_pin_is_recently_used($pin)) {
                continue;
            }
            $key = strtolower($pin);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = 1;
            $out[] = array(
                'id' => 0,
                'pin' => $pin,
                'profile_id' => $pid,
                'profile_name' => $pname,
                'label' => $pin . ($pname !== '' ? (' — ' . $pname) : ''),
            );
        }
    }
    return $out;
}

function sas_unused_cards_cached($api, $force = false)
{
    $ttl = 120;
    $at = isset($_SESSION['sas_unused_ui_v6_at']) ? (int) $_SESSION['sas_unused_ui_v6_at'] : 0;
    if (!$force && $at > 0 && isset($_SESSION['sas_unused_ui_v6']) && is_array($_SESSION['sas_unused_ui_v6'])) {
        $age = time() - $at;
        $empty = !$_SESSION['sas_unused_ui_v6'];
        $needsPins = false;
        if ($empty && function_exists('sas_cards_inventory_load_persisted') && function_exists('sas_inventory_needs_free_pins')) {
            $gapInv = sas_cards_inventory_load_persisted(0);
            $needsPins = sas_inventory_needs_free_pins($gapInv);
        }
        if (!$needsPins && ((!$empty && $age < $ttl) || ($empty && $age < 20))) {
            $sess = function_exists('sas_filter_recently_used_cards')
                ? sas_filter_recently_used_cards($_SESSION['sas_unused_ui_v6'])
                : $_SESSION['sas_unused_ui_v6'];
            $_SESSION['sas_unused_ui_v6'] = $sess;
            return $sess;
        }
    }

    // جرد صفحة الكروت هو المصدر الوحيد الموثوق (حتى لو 0 شاغر)
    if (!$force && function_exists('sas_cards_inventory_load_persisted')) {
        $invFile = sas_cards_inventory_load_persisted(0);
        if ($invFile && isset($invFile['groups']) && is_array($invFile['groups'])) {
            $fromInv = sas_unused_pins_from_inventory_cache();
            $out = is_array($fromInv) ? $fromInv : array();
            if (!$out && function_exists('sas_inventory_needs_free_pins') && sas_inventory_needs_free_pins($invFile)
                && $api && method_exists($api, 'listOwnedFreePins')) {
                $livePins = sas_cards_rows_to_ui($api->listOwnedFreePins(20));
                if (function_exists('sas_pins_capped_by_inventory')) {
                    $livePins = sas_pins_capped_by_inventory($livePins, $invFile);
                }
                if (is_array($livePins) && $livePins) {
                    $out = $livePins;
                    if (function_exists('sas_inventory_store_free_pins')) {
                        sas_inventory_store_free_pins($out);
                    }
                }
            }
            $_SESSION['sas_unused_ui_v6'] = $out;
            $_SESSION['sas_unused_ui_v6_at'] = time();
            if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_touch')) {
                app_session_touch();
            }
            return $out;
        }
    }

    $wasOpen = (session_status() === PHP_SESSION_ACTIVE);
    if ($wasOpen && function_exists('app_session_close')) {
        app_session_close();
    } elseif ($wasOpen) {
        session_write_close();
    }
    $out = array();
    $fromInventory = false;
    // تحديث/بناء الجرد — نفس listCardsInventory — لا listUnusedCards (يضخّم الشاغر)
    if ($api && method_exists($api, 'listCardsInventory')) {
        $inv = $api->listCardsInventory(14);
        if (is_array($inv)) {
            if (function_exists('sas_cards_inventory_save_persisted')) {
                sas_cards_inventory_save_persisted($inv);
            }
            if (function_exists('sas_dash_groups_from_inventory') && function_exists('sas_store_dash_card_groups')) {
                sas_store_dash_card_groups(sas_dash_groups_from_inventory($inv), 'inventory');
            }
            $fromInventory = true;
        }
    }
    if (!$fromInventory && $api && method_exists($api, 'listUnusedCards')) {
        $out = sas_cards_rows_to_ui($api->listUnusedCards(0, ''));
    }
    if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_reopen')) {
        app_session_reopen();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if ($fromInventory) {
        $fromInv = sas_unused_pins_from_inventory_cache();
        $out = is_array($fromInv) ? $fromInv : array();
        if (!$out && $api && method_exists($api, 'listOwnedFreePins') && function_exists('sas_inventory_needs_free_pins')) {
            $gapInv = function_exists('sas_cards_inventory_load_persisted') ? sas_cards_inventory_load_persisted(0) : null;
            if (sas_inventory_needs_free_pins($gapInv)) {
                $livePins = sas_cards_rows_to_ui($api->listOwnedFreePins(20));
                if (function_exists('sas_pins_capped_by_inventory')) {
                    $livePins = sas_pins_capped_by_inventory($livePins, $gapInv);
                }
                if (is_array($livePins) && $livePins) {
                    $out = $livePins;
                    if (function_exists('sas_inventory_store_free_pins')) {
                        sas_inventory_store_free_pins($out);
                    }
                }
            }
        }
    }
    if (!is_array($out)) {
        $out = array();
    }
    if (function_exists('sas_filter_recently_used_cards')) {
        $out = sas_filter_recently_used_cards($out);
    }
    $_SESSION['sas_unused_ui_v6'] = $out;
    $_SESSION['sas_unused_ui_v6_at'] = time();
    unset(
        $_SESSION['sas_unused_ui'],
        $_SESSION['sas_unused_ui_at'],
        $_SESSION['sas_unused_ui_v3'],
        $_SESSION['sas_unused_ui_v3_at'],
        $_SESSION['sas_unused_ui_v4'],
        $_SESSION['sas_unused_ui_v4_at'],
        $_SESSION['sas_unused_ui_v5'],
        $_SESSION['sas_unused_ui_v5_at']
    );
    if (function_exists('app_session_close')) {
        app_session_close();
    }
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
        if ($profileId > 0 && $pid > 0 && $pid === $profileId) {
            $hit[] = $c;
            continue;
        }
        if ($want !== '' && $pn !== '' && $pn === $want) {
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

function sas_dash_card_groups($api, $force = false)
{
    $ttl = 180;
    if (!$force) {
        $preferred = function_exists('sas_dash_cards_preferred_persisted')
            ? sas_dash_cards_preferred_persisted()
            : sas_dash_cards_load_persisted();
        if ($preferred && !empty($preferred['groups']) && is_array($preferred['groups'])) {
            $pat = isset($preferred['updated_at']) ? (int) $preferred['updated_at'] : 0;
            if ($pat > 0 && (time() - $pat) < $ttl) {
                $_SESSION['sas_card_groups_v5'] = $preferred['groups'];
                $_SESSION['sas_card_groups_v5_at'] = $pat;
                $_SESSION['sas_card_groups_v2'] = $preferred['groups'];
                $_SESSION['sas_card_groups_v2_at'] = $pat;
                if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_touch')) {
                    app_session_touch();
                }
                return $preferred['groups'];
            }
        }
    }
    $wasOpen = (session_status() === PHP_SESSION_ACTIVE);
    if ($wasOpen && function_exists('app_session_close')) {
        app_session_close();
    } elseif ($wasOpen) {
        session_write_close();
    }
    $groups = array();
    $source = 'dash';
    // المصدر الأدق = نفس جرد صفحة الكروت
    if ($api && method_exists($api, 'listCardsInventory')) {
        $inv = $api->listCardsInventory(14);
        if (is_array($inv)) {
            if (function_exists('sas_cards_inventory_save_persisted')) {
                sas_cards_inventory_save_persisted($inv);
            }
            $groups = sas_dash_groups_from_inventory($inv);
            $source = 'inventory';
        }
    }
    if (!is_array($groups)) {
        $groups = array();
    }
    if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_reopen')) {
        app_session_reopen();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    sas_store_dash_card_groups($groups, $source);
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    return $groups;
}

function sas_activation_quote($pdo, $config, $username, $profileId = 0, $profileName = '')
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
    if ($cache && function_exists('sas_cache_ensure_local')) {
        list($localId) = sas_cache_ensure_local($pdo, $config, $cache);
    } elseif ($cache && !empty($cache['local_subscriber_id'])) {
        $localId = (int) $cache['local_subscriber_id'];
    }
    $out['local_id'] = (int) $localId;
    $profileId = (int) $profileId;
    if ($profileId <= 0 && $cache && !empty($cache['profile_id'])) {
        $profileId = (int) $cache['profile_id'];
    }
    $shownName = trim((string) $profileName);
    if ($shownName === '' && $cache && !empty($cache['profile_name'])) {
        $shownName = trim((string) $cache['profile_name']);
    }
    $planName = '';
    if ($profileId > 0) {
        try {
            $pst = $pdo->prepare(
                'SELECT name, monthly_price FROM service_plans
                 WHERE sas_profile_id = :p AND is_active = 1 ORDER BY id ASC LIMIT 1'
            );
            $pst->execute(array(':p' => $profileId));
            $planRow = $pst->fetch();
            if ($planRow) {
                $out['charge'] = (float) $planRow['monthly_price'];
                $planName = isset($planRow['name']) ? trim((string) $planRow['name']) : '';
            }
        } catch (Exception $e) {
        }
    }
    if (function_exists('account_customer_sell_price')) {
        $sell = 0.0;
        if ($planName !== '') {
            $sell = (float) account_customer_sell_price($pdo, $planName, $profileId);
        }
        if ($sell <= 0 && $shownName !== '' && strtolower($shownName) !== strtolower($planName)) {
            $sell = (float) account_customer_sell_price($pdo, $shownName, 0);
        }
        if ($sell > 0) {
            $out['charge'] = $sell;
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
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'http://[' . $ip . ']/';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return 'http://' . $ip . '/';
    }
    return '';
}

function sas_unused_cards_grouped($api)
{
    return sas_group_unused_cards(sas_unused_cards_cached($api, false));
}

function sas_cache_fill_from_subscribers($pdo)
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    try {
        $have = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . $tid)->fetchColumn();
        if ($have > 0) {
            return $have;
        }
    } catch (Exception $e) {
        $have = 0;
    }
    $subs = array();
    try {
        $subs = $pdo->query(
            'SELECT s.id, s.name, s.phone, s.sas_username, s.sas_user_id,
                    (SELECT sub.end_date FROM subscriptions sub WHERE sub.subscriber_id = s.id ORDER BY sub.id DESC LIMIT 1) AS end_date,
                    (SELECT sub.service_name FROM subscriptions sub WHERE sub.subscriber_id = s.id ORDER BY sub.id DESC LIMIT 1) AS service_name,
                    (SELECT sub.status FROM subscriptions sub WHERE sub.subscriber_id = s.id ORDER BY sub.id DESC LIMIT 1) AS sub_status
             FROM subscribers s
             WHERE s.tenant_id = ' . $tid . ' AND s.sas_username IS NOT NULL AND s.sas_username <> ""'
        )->fetchAll();
    } catch (Exception $e) {
        try {
            $subs = $pdo->query(
                'SELECT s.id, s.name, s.phone, s.sas_username, s.sas_user_id, NULL AS end_date, NULL AS service_name, NULL AS sub_status
                 FROM subscribers s
                 WHERE s.sas_username IS NOT NULL AND s.sas_username <> ""'
            )->fetchAll();
        } catch (Exception $e2) {
            return 0;
        }
    }
    $n = 0;
    foreach ($subs as $s) {
        $user = trim((string) $s['sas_username']);
        if ($user === '') {
            continue;
        }
        $exp = !empty($s['end_date']) ? ($s['end_date'] . ' 23:59:59') : null;
        $row = array(
            'username' => $user,
            'id' => (int) $s['sas_user_id'],
            'user_id' => (int) $s['sas_user_id'],
            'firstname' => (string) $s['name'],
            'name' => (string) $s['name'],
            'phone' => (string) $s['phone'],
            'profile' => isset($s['service_name']) ? (string) $s['service_name'] : '',
            'enabled' => (!empty($s['sub_status']) && $s['sub_status'] === 'expired') ? 0 : 1,
            'expiration' => $exp,
        );
        $ok = false;
        if (function_exists('sas_cache_upsert_row')) {
            $ok = sas_cache_upsert_row($pdo, $row);
        }
        try {
            $pdo->prepare('UPDATE sas_users_cache SET local_subscriber_id = :lid, display_name = :n WHERE username = :u AND tenant_id = :t')
                ->execute(array(
                    ':lid' => (int) $s['id'],
                    ':n' => (string) $s['name'],
                    ':u' => $user,
                    ':t' => $tid,
                ));
            $ok = true;
        } catch (Exception $e) {
        }
        if ($ok) {
            $n++;
        }
    }
    return $n;
}

/** اربط كاش الساس بصف المشترك اللي عليه الفواتير، وانقل الدفتر للوكالة الحالية */
function sas_relink_ledger_rows($pdo)
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    // جملة واحدة للصفوف غير المربوطة. الربط صفّاً صفّاً مع عدّ الفواتير كان يعلّق اللوحة عند آلاف المشتركين.
    try {
        $st = $pdo->prepare(
            'UPDATE sas_users_cache c
             INNER JOIN subscribers s
               ON s.tenant_id = c.tenant_id
              AND CONVERT(s.sas_username USING utf8mb4) COLLATE utf8mb4_unicode_ci
                  = CONVERT(c.username USING utf8mb4) COLLATE utf8mb4_unicode_ci
             SET c.local_subscriber_id = s.id,
                 c.display_name = CASE
                     WHEN s.name IS NOT NULL AND s.name <> "" THEN s.name
                     ELSE c.display_name
                 END
             WHERE c.tenant_id = :t
               AND (c.local_subscriber_id IS NULL OR c.local_subscriber_id = 0)'
        );
        $st->execute(array(':t' => $tid));
        return (int) $st->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}

function shop_tenant_unpaid_sum($pdo, $tid)
{
    try {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(i.amount),0) FROM invoices i
             JOIN subscribers s ON s.id = i.subscriber_id
             WHERE i.status = "unpaid" AND s.tenant_id = :t'
        );
        $st->execute(array(':t' => (int) $tid));
        return (float) $st->fetchColumn();
    } catch (Exception $e) {
        return 0.0;
    }
}

/** إذا ديون الوكالة صفر، رجّع دفتر 22 أيلول مرة وحدة على الحساب الحالي */
function shop_restore_sep22_if_empty($pdo)
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    $lockFile = dirname(__DIR__) . '/storage/shop_home_tenant.txt';
    $lockedHome = is_file($lockFile) ? (int) trim((string) @file_get_contents($lockFile)) : 0;
    if ($lockedHome <= 0 || $tid !== $lockedHome) {
        return false;
    }
    if (function_exists('sas_relink_ledger_rows')) {
        sas_relink_ledger_rows($pdo);
    }
    if (shop_tenant_unpaid_sum($pdo, $tid) > 0) {
        return false;
    }
    $sqlFile = dirname(__DIR__) . '/public/shop_restore.sql';
    $doneFile = dirname(__DIR__) . '/storage/shop_restore_done.txt';
    if (!is_file($sqlFile)) {
        return false;
    }
    $done = is_file($doneFile) ? (string) @file_get_contents($doneFile) : '';
    if (strpos($done, 'tenant=' . $tid . ' ') !== false && strpos($done, 'unpaid=0') === false && strpos($done, 'applied=1') !== false) {
        return false;
    }
    $sql = @file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') {
        return false;
    }
    $allow = array(
        'subscribers' => true,
        'service_plans' => true,
        'subscriptions' => true,
        'invoices' => true,
    );
    $parts = preg_split('/;\s*\n/', $sql);
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($parts as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '--') === 0 || strpos($stmt, 'SET ') === 0) {
                continue;
            }
            if (!preg_match('/^(TRUNCATE TABLE|INSERT INTO|REPLACE INTO)\s+`([a-z0-9_]+)`/i', $stmt, $m)) {
                continue;
            }
            $table = strtolower($m[2]);
            if (empty($allow[$table]) || stripos($stmt, 'TRUNCATE') === 0) {
                continue;
            }
            $stmt = preg_replace('/^INSERT INTO/i', 'REPLACE INTO', $stmt, 1);
            $pdo->exec($stmt);
        }
        try {
            if ($lockedHome > 0 && $tid === $lockedHome) {
                $pdo->prepare('UPDATE subscribers SET tenant_id = :t WHERE id BETWEEN 1 AND 76 AND (tenant_id IS NULL OR tenant_id = 0 OR tenant_id = :t2)')
                    ->execute(array(':t' => $tid, ':t2' => $tid));
            }
        } catch (Exception $e) {
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Exception $e) {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Exception $e2) {
        }
        return false;
    }
    if (function_exists('sas_relink_ledger_rows')) {
        sas_relink_ledger_rows($pdo);
    }
    $sum = shop_tenant_unpaid_sum($pdo, $tid);
    @file_put_contents($doneFile, date('c') . ' tenant=' . $tid . ' applied=1 unpaid=' . $sum);
    @file_put_contents(dirname(__DIR__) . '/storage/shop_home_tenant.txt', (string) $tid);
    return $sum > 0;
}

function sas_dash_live_file($tenantId)
{
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/sas_dash_live_t' . (int) $tenantId . '.json';
}

function sas_dash_live_allowed()
{
    if (function_exists('is_agent_user') && is_agent_user()) {
        return false;
    }
    if (function_exists('is_group_manager_user') && is_group_manager_user()) {
        return false;
    }
    if (function_exists('is_accountant_user') && is_accountant_user()) {
        return false;
    }
    global $pdo;
    if ($pdo && function_exists('account_viewer_is_leaf_child') && account_viewer_is_leaf_child($pdo)) {
        return false;
    }
    return true;
}

function sas_dash_live_read($tenantId, $maxAge)
{
    $file = sas_dash_live_file($tenantId);
    if (!is_file($file)) {
        return null;
    }
    $age = time() - (int) @filemtime($file);
    if ($maxAge > 0 && $age > $maxAge) {
        return null;
    }
    $raw = @file_get_contents($file);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data) || empty($data['total'])) {
        return null;
    }
    return $data;
}

function sas_dash_live_add($tenantId, $key, $delta)
{
    $data = sas_dash_live_read($tenantId, 0);
    if (!is_array($data) || !isset($data[$key])) {
        return;
    }
    $data[$key] = max(0, (int) $data[$key] + (int) $delta);
    $data['at'] = time();
    @file_put_contents(sas_dash_live_file($tenantId), json_encode($data));
}

function sas_dash_live_refresh($pdo, $config, $force = false, $withSoon = null)
{
    if (!sas_dash_live_allowed()) {
        return null;
    }
    if (!function_exists('sas_is_ready') || !sas_is_ready($config)) {
        return null;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    if (!$force) {
        $fresh = sas_dash_live_read($tid, 8);
        if ($fresh) {
            return $fresh;
        }
    }
    $lockPath = dirname(sas_dash_live_file($tid)) . '/sas_dash_live_lock_t' . $tid;
    $lockFh = @fopen($lockPath, 'c');
    if ($lockFh && !@flock($lockFh, LOCK_EX | LOCK_NB)) {
        @fclose($lockFh);
        return sas_dash_live_read($tid, 0);
    }
    $api = function_exists('sas_make_connector') ? sas_make_connector($config) : null;
    if (!$api || !method_exists($api, 'dashboardCounts')) {
        if ($lockFh) {
            @flock($lockFh, LOCK_UN);
            @fclose($lockFh);
        }
        return sas_dash_live_read($tid, 0);
    }
    try {
        if (method_exists($api, 'setTimeout')) {
            $api->setTimeout(8);
        }
        if ($withSoon === null) {
            $withSoon = !$force;
        }
        $live = $api->dashboardCounts($withSoon ? true : false);
    } catch (Exception $e) {
        $live = null;
    }
    if ($lockFh) {
        @flock($lockFh, LOCK_UN);
        @fclose($lockFh);
    }
    if (!is_array($live) || empty($live['total'])) {
        return sas_dash_live_read($tid, 0);
    }
    if ($live['soon'] === null) {
        $prevSoon = sas_dash_live_read($tid, 0);
        $live['soon'] = is_array($prevSoon) && isset($prevSoon['soon']) ? (int) $prevSoon['soon'] : 0;
    }
    $out = array(
        'total' => (int) $live['total'],
        'active' => (int) $live['active'],
        'expired' => (int) $live['expired'],
        'online' => (int) $live['online'],
        'today' => (int) $live['today'],
        'soon' => (int) $live['soon'],
        'at' => time(),
    );
    if ($out['active'] <= 0 || $out['expired'] <= 0) {
        return sas_dash_live_read($tid, 0);
    }
    @file_put_contents(sas_dash_live_file($tid), json_encode($out));
    return $out;
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
    $scope = function_exists('sas_agent_scope_sql') ? sas_agent_scope_sql('c') : '';
    if (sas_dash_live_allowed() && function_exists('sas_ownership_search_tenant_ids')) {
        $wide = sas_ownership_search_tenant_ids($pdo);
        $in = array();
        foreach ($wide as $wid) {
            $wid = (int) $wid;
            if ($wid > 0) {
                $in[$wid] = $wid;
            }
        }
        if (count($in) > 1) {
            $scope = ' AND c.tenant_id IN (' . implode(',', $in) . ')';
        }
    }
    $countExpr = (strpos($scope, ' IN (') !== false) ? 'COUNT(DISTINCT c.username)' : 'COUNT(*)';
    try {
        $out['total'] = (int) $pdo->query('SELECT ' . $countExpr . ' FROM sas_users_cache c WHERE 1=1' . $scope)->fetchColumn();
        $out['disabled'] = (int) $pdo->query('SELECT ' . $countExpr . ' FROM sas_users_cache c WHERE c.enabled = 0' . $scope)->fetchColumn();
        $out['online'] = (int) $pdo->query('SELECT ' . $countExpr . ' FROM sas_users_cache c WHERE c.is_online = 1' . $scope)->fetchColumn();
        $out['active'] = (int) $pdo->query(
            'SELECT ' . $countExpr . ' FROM sas_users_cache c
             WHERE c.enabled = 1 AND c.expire_at IS NOT NULL AND c.expire_at >= NOW()' . $scope
        )->fetchColumn();
        $out['expired'] = (int) $pdo->query(
            'SELECT ' . $countExpr . ' FROM sas_users_cache c
             WHERE NOT (c.enabled = 1 AND c.expire_at IS NOT NULL AND c.expire_at >= NOW())' . $scope
        )->fetchColumn();
        $out['soon'] = (int) $pdo->query(
            'SELECT ' . $countExpr . ' FROM sas_users_cache c
             WHERE c.expire_at IS NOT NULL AND DATE(c.expire_at) > CURDATE()
               AND DATE(c.expire_at) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)' . $scope
        )->fetchColumn();
        $out['today'] = (int) $pdo->query(
            'SELECT ' . $countExpr . ' FROM sas_users_cache c
             WHERE c.expire_at IS NOT NULL AND DATE(c.expire_at) = CURDATE()' . $scope
        )->fetchColumn();
    } catch (Exception $e) {
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $live = sas_dash_live_allowed() ? sas_dash_live_read($tid, 8) : null;
    if (is_array($live)) {
        foreach (array('total', 'active', 'online', 'expired', 'soon', 'today') as $key) {
            if (isset($live[$key])) {
                $out[$key] = (int) $live[$key];
            }
        }
    }
    return $out;
}

/** تاريخ الساس أبعد من آخر اشتراك مسجّل — ما ينكتب دين ولا تفعيل */
function sas_sas_ahead_of_ledger($pdo)
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    $scope = function_exists('sas_agent_scope_sql') ? sas_agent_scope_sql('c') : '';
    $sql = 'SELECT c.username, c.display_name, c.firstname, c.local_subscriber_id,
            DATE(c.expire_at) AS sas_end,
            (SELECT sub.end_date FROM subscriptions sub
             WHERE sub.subscriber_id = c.local_subscriber_id
             ORDER BY sub.end_date DESC, sub.id DESC LIMIT 1) AS local_end
            FROM sas_users_cache c
            WHERE c.tenant_id = ' . $tid . '
              AND c.expire_at IS NOT NULL
              AND c.local_subscriber_id IS NOT NULL' . $scope;
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        return array();
    }
    $out = array();
    foreach ($rows as $r) {
        $sasEnd = isset($r['sas_end']) ? (string) $r['sas_end'] : '';
        $localEnd = isset($r['local_end']) ? (string) $r['local_end'] : '';
        if ($sasEnd === '' || $sasEnd === '0000-00-00') {
            continue;
        }
        if ($localEnd === '' || $localEnd === '0000-00-00' || $sasEnd <= $localEnd) {
            continue;
        }
        $name = trim((string) $r['firstname']);
        if ($name === '') {
            $name = (string) $r['display_name'];
        }
        $days = 0;
        if ($localEnd !== '' && $localEnd !== '0000-00-00') {
            $days = (int) round((strtotime($sasEnd) - strtotime($localEnd)) / 86400);
        }
        $out[] = array(
            'username' => (string) $r['username'],
            'name' => $name,
            'sas_end' => $sasEnd,
            'local_end' => $localEnd,
            'days' => $days,
            'id' => (int) $r['local_subscriber_id'],
        );
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

function sas_notify_activation_whatsapp($pdo, $config, $username, $sendOldDebts, $payMode = 'cash')
{
    $username = trim((string) $username);
    $payMode = ($payMode === 'credit') ? 'credit' : 'cash';
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
    $shownName = !empty($cache['profile_name']) ? trim((string) $cache['profile_name']) : '';
    $planName = $shownName;
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
                $sheetName = !empty($plan['name']) ? trim((string) $plan['name']) : '';
                if ($sheetName !== '') {
                    $planName = $sheetName;
                }
                $price = (float) $plan['monthly_price'];
                if (function_exists('account_customer_sell_price')) {
                    $sell = $sheetName !== '' ? (float) account_customer_sell_price($pdo, $sheetName, $pid) : 0.0;
                    if ($sell <= 0 && $shownName !== '' && strtolower($shownName) !== strtolower($sheetName)) {
                        $sell = (float) account_customer_sell_price($pdo, $shownName, 0);
                    }
                    if ($sell > 0) {
                        $price = $sell;
                    }
                }
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
    $oldDebtSum = 0.0;
    $notesLine = '';
    $oldLines = array();
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
            $parts = array();
            foreach ($oldLines as $od) {
                $lab = function_exists('month_short_label') ? month_short_label($od['month_label']) : $od['month_label'];
                $amt = function_exists('money_format_iqd')
                    ? money_format_iqd($od['amount'], $currency)
                    : $od['amount'];
                $parts[] = '• ' . $lab . ': ' . $amt;
                $oldDebtSum += (float) $od['amount'];
            }
            $notesLine = implode("\n", $parts);
        }
    }
    if ($payMode === 'credit' && function_exists('activation_credit_customer_message')) {
        $msg = activation_credit_customer_message(
            $msgRow,
            $config,
            $price,
            ($sendOldDebts ? $oldDebtSum : 0),
            ($sendOldDebts ? $notesLine : '')
        );
    } else {
        // نقدي + ديون قديمة فقط: رسالة التفعيل + ملحق الديون
        if ($payMode !== 'credit' && $sendOldDebts && $notesLine !== '') {
            $debtKey = function_exists('wa_case_template_key')
                ? wa_case_template_key($config, 'activation_debts')
                : 'activation_debts';
            if (function_exists('wa_render_named_template')) {
                $extra = wa_render_named_template($debtKey, $msgRow, $config, array(
                    'debt' => $oldDebtSum,
                    'amount' => $oldDebtSum,
                    'notes' => $notesLine,
                ));
            }
            if ($extra === '') {
                $debtOn = !function_exists('wa_template_is_on') || wa_template_is_on($config, $debtKey);
                if ($debtOn) {
                    $extra = "\n\nالديون السابقة:\n" . $notesLine;
                }
            } else {
                $extra = "\n\n" . $extra;
            }
        }
        $msg = activation_message($msgRow, $config, $extra);
    }
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
        'invalid_profile', 'rsp_invalid_profile', 'invalid profile',
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
    $now = time();
    if ($beforeTs <= 0) {
        return $afterTs > ($now - 3600);
    }
    // تمديد واضح (ساعة فأكثر)
    if (($afterTs - $beforeTs) >= 3600) {
        return true;
    }
    // كان منتهي وصار عنده انتهاء بالمستقبل
    if ($beforeTs < ($now - 60) && $afterTs > ($now + 3600)) {
        return true;
    }
    return false;
}

/** نجاح API واضح — مو مجرد رد فاضي */
function sas_activate_api_ok($res)
{
    if (!is_array($res) || !$res) {
        return false;
    }
    if (!empty($res['_verified']) || !empty($res['_api_ok'])) {
        return true;
    }
    if (function_exists('sas_response_is_error') && sas_response_is_error($res)) {
        return false;
    }
    if (isset($res['success']) && ($res['success'] === false || $res['success'] === 0 || $res['success'] === '0')) {
        return false;
    }
    if (isset($res['status']) && is_numeric($res['status'])) {
        $n = (int) $res['status'];
        if ($n < 0 || ($n >= 100 && $n !== 200)) {
            return false;
        }
    }
    $msg = '';
    if (function_exists('sas_response_message')) {
        $msg = strtolower(trim(sas_response_message($res)));
    } elseif (isset($res['message'])) {
        $msg = strtolower(trim((string) $res['message']));
    }
    $hard = array(
        'insufficient', 'not enough', 'no balance', 'no credit',
        'invalid pin', 'wrong pin', 'already used', 'used card', 'card used',
        'permission', 'forbidden', 'unauthorized',
        'ماكو رصيد', 'رصيد غير', 'غير كاف', 'كرت مستخدم', 'مستخدم مسبقا',
        'غير صالح', 'pin invalid', 'expired card', 'ما غيّر تاريخ',
    );
    foreach ($hard as $n) {
        if ($msg !== '' && strpos($msg, $n) !== false) {
            return false;
        }
    }
    if (!empty($res['success']) || (isset($res['success']) && ($res['success'] === true || $res['success'] === 1 || $res['success'] === '1'))) {
        return true;
    }
    if (isset($res['status']) && in_array((int) $res['status'], array(1, 200), true)) {
        return true;
    }
    if ($msg !== '' && (
        strpos($msg, 'success') !== false
        || strpos($msg, 'activat') !== false
        || strpos($msg, 'تم ') !== false
        || strpos($msg, 'تمّ') !== false
    )) {
        return true;
    }
    return false;
}

/** @deprecated استخدم sas_activate_api_ok */
function sas_activate_sas_accepted($res)
{
    return sas_activate_api_ok($res);
}

function sas_activate_fail_detail($res, $beforeTs, $afterRow)
{
    $afterSql = $afterRow ? sas_cache_expire_at($afterRow) : '';
    $beforeSql = $beforeTs > 0 ? date('Y-m-d H:i', $beforeTs) : '-';
    $hint = 'الساس ما فعّل المشترك (التاريخ: ' . $beforeSql . ' → ' . ($afterSql ? $afterSql : '-') . ')';
    if (is_array($res) && function_exists('sas_response_message')) {
        $m = trim(sas_response_message($res));
        if ($m !== '' && $m !== 'خطأ SAS غير معروف') {
            $hint .= ' — ' . $m;
        }
    }
    return $hint . '. ما تم تسجيل المبلغ.';
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
            usleep(250000);
        }
        $after = sas_live_user_row($api, $username, $sasUserId);
        if (sas_activation_took_effect($beforeTs, $after)) {
            return array(true, $after);
        }
        $try++;
    }
    return array(false, $after);
}

/**
 * نجاح محلي فقط إذا تغيّر تاريخ الانتهاء فعلياً على الساس
 * @return array($confirmed, $afterRow)
 */
function sas_confirm_activation_result($api, $username, $sasUserId, $beforeTs, $profileId, $units, $config, $res)
{
    list($confirmed, $afterRow) = sas_confirm_live_activation($api, $username, $sasUserId, $beforeTs);
    if ($confirmed) {
        return array(true, $afterRow);
    }

    // فرض انتهاء فقط عند نجاح API واضح (مو رد فاضي/_accepted وهمي)
    $apiOk = !empty($res['_verified']) || !empty($res['_api_ok']) || sas_activate_api_ok($res);
    if ($apiOk && function_exists('sas_activate_may_force_expire') && sas_activate_may_force_expire($res)) {
        list($confirmed, $afterRow) = sas_force_activation_expire(
            $api,
            $username,
            $sasUserId,
            $beforeTs,
            $profileId,
            $units,
            $config
        );
        if ($confirmed) {
            return array(true, $afterRow);
        }
        if ((int) $sasUserId > 0 && $api && method_exists($api, 'setUserEnabled')) {
            $api->setUserEnabled((int) $sasUserId, true);
            list($confirmed, $afterRow) = sas_confirm_live_activation($api, $username, $sasUserId, $beforeTs);
        }
    }

    // ممنوع اعتباره نجاح بدون تغيّر تاريخ — حتى لا يتحاسب المشترك والساس فاضي
    return array($confirmed ? true : false, $afterRow);
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
            sas_notify_activation_whatsapp($pdo, $config, $username, $sendOld, $payMode);
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
    list($lok, $lmsg) = activate_one_subscriber($pdo, $config, $localId, array(
        'plan_id' => $planId,
        'pay_mode' => $payMode,
        'send_whatsapp' => $sendWa,
        'send_old_debts' => $sendOld,
        'skip_sas' => true,
        'skip_grace' => true,
        'simple_msg' => true,
        'carry_days' => true,
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
    if (in_array($action, array('sas_create', 'sas_activate_card', 'sas_activate_credit', 'sas_activate_reward'), true)
        && function_exists('account_open_price_block')) {
        $pricePid = isset($fields['profile_id']) ? (int) $fields['profile_id'] : 0;
        $priceName = isset($fields['profile_name']) ? (string) $fields['profile_name'] : '';
        $priceBlock = account_open_price_block($pdo, $pricePid, $priceName);
        if ($priceBlock !== '') {
            return array(false, $priceBlock, array());
        }
    }
    if (function_exists('sas_writes_allowed') && !sas_writes_allowed($pdo, $config)) {
        return array(false, 'الساس غير متصل — الكتابة موقوفة مؤقتاً (البيانات المحلية محفوظة)', array());
    }
    $api = sas_page_connector($config);
    if (!$api) {
        if (function_exists('sas_mark_connection')) {
            sas_mark_connection($pdo, $config, false, 'تعذر الدخول للساس');
        }
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
        $createPhone = isset($fields['phone']) ? trim((string) $fields['phone']) : '';
        if ($createPhone !== '') {
            $createDigits = preg_replace('/\D+/', '', $createPhone);
            if ($createDigits === null) {
                $createDigits = '';
            }
            if (strlen($createDigits) !== 11) {
                return array(false, 'رقم الهاتف لازم يكون 11 رقم', array());
            }
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
        if (($action === 'sas_inline' && $field === 'phone') || $phoneIn !== '') {
            $phoneDigits = preg_replace('/\D+/', '', $phoneIn);
            if ($phoneDigits === null) {
                $phoneDigits = '';
            }
            if (strlen($phoneDigits) !== 11) {
                return array(false, 'رقم الهاتف لازم يكون 11 رقم', array());
            }
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
        if (isset($payload['phone'])) {
            sas_cache_sync_local_phone($pdo, $username, $payload['phone']);
        }
        if ($renamedOk) {
            try {
                $tidRename = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
                if ($tidRename <= 0) {
                    $tidRename = 1;
                }
                $pdo->prepare('UPDATE sas_users_cache SET username = :n WHERE username = :o AND tenant_id = :t')
                    ->execute(array(':n' => $newUser, ':o' => $username, ':t' => $tidRename));
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

    if ($action === 'sas_disconnect') {
        if (!method_exists($api, 'disconnectUser')) {
            return array(false, 'قطع الاتصال غير مدعوم', array());
        }
        $res = $api->disconnectUser($sasUserId, $username);
        if (function_exists('sas_response_success') && !sas_response_success($res)) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل قطع الاتصال'), array());
        }
        sas_cache_patch($pdo, $username, array('is_online' => 0, 'framed_ip' => null));
        if (function_exists('sas_sessions_mark_user_closed')) {
            sas_sessions_mark_user_closed($pdo, $username);
        }
        $dashTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
        if ($dashTid > 0 && function_exists('sas_dash_live_add')) {
            sas_dash_live_add($dashTid, 'online', -1);
        }
        return array(true, 'تم قطع اتصال المشترك (Disconnect)', array(
            'is_online' => 0,
            'framed_ip' => '',
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
        if (!sas_activate_api_ok($res) && !sas_activate_may_force_expire($res)) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل التفعيل'), array());
        }
        list($confirmed, $afterRow) = sas_confirm_activation_result(
            $api,
            $username,
            $sasUserId,
            $beforeTs,
            $profileId,
            $units,
            $config,
            $res
        );
        if (!$confirmed) {
            return array(false, sas_activate_fail_detail($res, $beforeTs, $afterRow), array());
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
        if (!sas_activate_api_ok($res) && !sas_activate_may_force_expire($res)) {
            return array(false, 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل التفعيل'), array());
        }
        list($confirmed, $afterRow) = sas_confirm_activation_result(
            $api,
            $username,
            $sasUserId,
            $beforeTs,
            $profileId,
            $units,
            $config,
            $res
        );
        if (!$confirmed) {
            return array(false, sas_activate_fail_detail($res, $beforeTs, $afterRow), array());
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
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
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
        if (!empty($res['_verified'])) {
            // موثّق من الساس مباشرة
        } elseif (!sas_activate_api_ok($res) && !sas_activate_may_force_expire($res)) {
            $failMsg = 'SAS: ' . (function_exists('sas_response_message') ? sas_response_message($res) : 'فشل التفعيل');
            // كرت مرفوض/مستخدم — لا ترجعه بالقائمة الشاغرة
            if ($pin !== '' && function_exists('sas_mark_card_pin_used')) {
                $lm = strtolower($failMsg);
                if (strpos($lm, 'invalid_profile') !== false
                    || strpos($lm, 'رفض باقة') !== false
                    || strpos($lm, 'used') !== false
                    || strpos($lm, 'مستخدم') !== false) {
                    sas_mark_card_pin_used($pin);
                }
            }
            $extraFail = array();
            if (function_exists('sas_unused_cards_snapshot_for_ui')) {
                $extraFail['cards'] = sas_unused_cards_snapshot_for_ui();
            }
            return array(false, $failMsg, $extraFail);
        }
        list($confirmed, $afterRow) = sas_confirm_activation_result(
            $api,
            $username,
            $sasUserId,
            $beforeTs,
            $profileId,
            1,
            $config,
            $res
        );
        if (!$confirmed) {
            $failDetail = sas_activate_fail_detail($res, $beforeTs, $afterRow);
            if ($pin !== '' && function_exists('sas_mark_card_pin_used')) {
                $lm = strtolower($failDetail);
                if (strpos($lm, 'invalid_profile') !== false || strpos($lm, 'رفض باقة') !== false) {
                    sas_mark_card_pin_used($pin);
                }
            }
            $extraFail = array();
            if (function_exists('sas_unused_cards_snapshot_for_ui')) {
                $extraFail['cards'] = sas_unused_cards_snapshot_for_ui();
            }
            return array(false, $failDetail, $extraFail);
        }
        if ($afterRow) {
            sas_cache_upsert_row($pdo, $afterRow);
        } else {
            sas_cache_refresh_one($pdo, $config, $username, $sasUserId);
        }
        if ($pin !== '' && function_exists('sas_mark_card_pin_used')) {
            sas_mark_card_pin_used($pin);
        } else {
            sas_clear_unused_card_cache();
        }
        $okMsg = sas_finish_local_activation($pdo, $config, $username, $fields, 'تم تفعيل المشترك');
        $extraOk = array();
        if (function_exists('sas_unused_cards_snapshot_for_ui')) {
            $extraOk['cards'] = sas_unused_cards_snapshot_for_ui();
        }
        return array(true, $okMsg, $extraOk);
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

function sas_cache_touch_owner_row($pdo, $viewerTenant, $username, $parentName, $params)
{
    if (!$pdo || !is_array($params) || !function_exists('sas_ownership_index')) {
        return false;
    }
    $idx = sas_ownership_index($pdo, (int) $viewerTenant);
    if (!$idx || empty($idx['logins']) || !is_array($idx['logins'])) {
        return false;
    }
    $hid = 0;
    $names = array(strtolower(trim((string) $parentName)), strtolower(trim((string) $username)));
    foreach ($names as $name) {
        if ($name === '') {
            continue;
        }
        if (isset($idx['logins'][$name])) {
            $hid = (int) $idx['logins'][$name];
            break;
        }
        $flat = str_replace('@', '', $name);
        if ($flat !== '' && isset($idx['logins'][$flat])) {
            $hid = (int) $idx['logins'][$flat];
            break;
        }
    }
    if ($hid <= 0 || $hid === (int) $viewerTenant) {
        return false;
    }
    $username = strtolower(trim((string) $username));
    try {
        $ex = $pdo->prepare('SELECT 1 FROM sas_users_cache WHERE tenant_id = :t AND username = :u LIMIT 1');
        $ex->execute(array(':t' => $hid, ':u' => $username));
        if ($ex->fetchColumn()) {
            $pdo->prepare(
                'UPDATE sas_users_cache SET
                    sas_user_id = :sas_user_id,
                    enabled = :enabled,
                    expire_at = :expire_at,
                    profile_id = :profile_id,
                    profile_name = :profile_name,
                    parent_id = :parent_id,
                    parent_name = :parent_name,
                    is_online = :is_online,
                    synced_at = :synced_at
                 WHERE tenant_id = :tenant_id AND username = :username'
            )->execute(array(
                ':sas_user_id' => $params[':sas_user_id'],
                ':enabled' => $params[':enabled'],
                ':expire_at' => $params[':expire_at'],
                ':profile_id' => $params[':profile_id'],
                ':profile_name' => $params[':profile_name'],
                ':parent_id' => $params[':parent_id'],
                ':parent_name' => $params[':parent_name'],
                ':is_online' => $params[':is_online'],
                ':synced_at' => $params[':synced_at'],
                ':tenant_id' => $hid,
                ':username' => $username,
            ));
            return true;
        }
        $ins = array(
            ':tenant_id' => $hid,
            ':username' => $username,
            ':sas_user_id' => $params[':sas_user_id'],
            ':firstname' => $params[':firstname'],
            ':lastname' => $params[':lastname'],
            ':display_name' => $params[':display_name'],
            ':phone' => $params[':phone'],
            ':profile_id' => $params[':profile_id'],
            ':profile_name' => $params[':profile_name'],
            ':enabled' => $params[':enabled'],
            ':expire_at' => $params[':expire_at'],
            ':parent_id' => $params[':parent_id'],
            ':parent_name' => $params[':parent_name'],
            ':city' => $params[':city'],
            ':email' => $params[':email'],
            ':company' => $params[':company'],
            ':last_online' => $params[':last_online'],
            ':is_online' => $params[':is_online'],
            ':framed_ip' => $params[':framed_ip'],
            ':mac_address' => $params[':mac_address'],
            ':daily_traffic' => $params[':daily_traffic'],
            ':synced_at' => $params[':synced_at'],
        );
        $pdo->prepare(
            'INSERT INTO sas_users_cache
                (tenant_id, username, sas_user_id, firstname, lastname, display_name, phone, profile_id, profile_name,
                 enabled, expire_at, parent_id, parent_name, city, email, company, last_online, is_online, framed_ip, mac_address, daily_traffic, local_subscriber_id, synced_at)
             VALUES
                (:tenant_id, :username, :sas_user_id, :firstname, :lastname, :display_name, :phone, :profile_id, :profile_name,
                 :enabled, :expire_at, :parent_id, :parent_name, :city, :email, :company, :last_online, :is_online, :framed_ip, :mac_address, :daily_traffic, NULL, :synced_at)'
        )->execute($ins);
        return true;
    } catch (Exception $e) {
        return false;
    }
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
    $tenantId = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tenantId <= 0) {
        $tenantId = 1;
    }
    // حاول إصلاح PK المركب قبل الكتابة (لا تسرق صف شركة أخرى)
    if (function_exists('tenants_migrate_sas_cache_pk')) {
        try {
            tenants_migrate_sas_cache_pk($pdo);
        } catch (Exception $e) {
        }
    }
    $sasUserId = function_exists('sas_extract_user_id') ? sas_extract_user_id($row) : 0;
    $phone = sas_clip(sas_cache_phone_raw($row), 40);
    $fn = isset($row['firstname']) && !is_array($row['firstname']) ? sas_clip($row['firstname'], 150) : '';
    $ln = isset($row['lastname']) && !is_array($row['lastname']) ? sas_clip($row['lastname'], 150) : '';
    $params = array(
        ':tenant_id' => $tenantId,
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
        ':parent_name' => (($parN = sas_cache_parent_name($row)) !== '' ? sas_clip($parN, 190) : null),
        ':city' => (($city = sas_cache_str_field($row, array('city'))) !== '' ? sas_clip($city, 120) : null),
        ':email' => (($em = sas_cache_str_field($row, array('email'))) !== '' ? sas_clip($em, 150) : null),
        ':company' => (($co = sas_cache_str_field($row, array('company'))) !== '' ? sas_clip($co, 150) : null),
        ':last_online' => sas_cache_last_online($row),
        ':is_online' => sas_cache_is_online_row($row),
        ':framed_ip' => (($ip = sas_cache_framed_ip($row)) !== '' ? sas_clip($ip, 45) : null),
        ':mac_address' => (($macUp = sas_cache_mac($row)) !== '' ? sas_clip($macUp, 32) : null),
        ':daily_traffic' => (($tr = sas_cache_daily_traffic($row)) !== '' ? sas_clip($tr, 60) : null),
        ':synced_at' => $nowSql,
    );
    $accountId = 0;
    if (isset($row['_sas_account_id'])) {
        $accountId = (int) $row['_sas_account_id'];
    } elseif (!empty($GLOBALS['sas_force_account']['id'])) {
        $accountId = (int) $GLOBALS['sas_force_account']['id'];
    }
    if ($accountId > 0) {
        $params[':sas_account_id'] = $accountId;
    }
    try {
        // هل يوجد صف لهذه الشركة؟
        $ex = $pdo->prepare('SELECT 1 FROM sas_users_cache WHERE tenant_id = :t AND username = :u LIMIT 1');
        $ex->execute(array(':t' => $tenantId, ':u' => $username));
        $exists = (bool) $ex->fetchColumn();
        if (function_exists('sas_cache_skip_owned_elsewhere')
            && sas_cache_skip_owned_elsewhere($pdo, $tenantId, $username, isset($params[':parent_name']) ? $params[':parent_name'] : '')
        ) {
            sas_cache_touch_owner_row(
                $pdo,
                $tenantId,
                $username,
                isset($params[':parent_name']) ? (string) $params[':parent_name'] : '',
                $params
            );
            if ($exists) {
                try {
                    $pdo->prepare('DELETE FROM sas_users_cache WHERE tenant_id = :t AND username = :u')
                        ->execute(array(':t' => $tenantId, ':u' => $username));
                } catch (Exception $eDel) {
                }
            }
            return true;
        }
        if ($exists) {
            // تحديث صف هذه الشركة فقط — لا تلمس tenant آخر حتى لو PK قديم على username فقط
            $accSet = ($accountId > 0) ? ', sas_account_id = :sas_account_id' : '';
            $upd = $pdo->prepare(
                'UPDATE sas_users_cache SET
                    sas_user_id = :sas_user_id,
                    firstname = :firstname,
                    lastname = :lastname,
                    display_name = :display_name,
                    phone = :phone,
                    profile_id = :profile_id,
                    profile_name = :profile_name,
                    enabled = :enabled,
                    expire_at = :expire_at,
                    parent_id = :parent_id,
                    parent_name = :parent_name,
                    city = :city,
                    email = :email,
                    company = :company,
                    last_online = IF(:last_online_chk IS NULL, last_online, :last_online2),
                    is_online = IF(:is_online_chk = 1, 1, is_online),
                    framed_ip = IF(:framed_ip_chk IS NULL OR :framed_ip_chk = "", framed_ip, :framed_ip2),
                    mac_address = IF(:mac_chk IS NULL OR :mac_chk = "", mac_address, :mac2),
                    daily_traffic = IF(:daily_traffic_chk IS NULL, daily_traffic, :daily_traffic2),
                    synced_at = :synced_at' . $accSet . '
                 WHERE tenant_id = :tenant_id AND username = :username'
            );
            $updParams = $params;
            $updParams[':last_online_chk'] = $params[':last_online'];
            $updParams[':last_online2'] = $params[':last_online'];
            $updParams[':framed_ip_chk'] = $params[':framed_ip'];
            $updParams[':framed_ip2'] = $params[':framed_ip'];
            $updParams[':mac_chk'] = $params[':mac_address'];
            $updParams[':mac2'] = $params[':mac_address'];
            $updParams[':daily_traffic_chk'] = $params[':daily_traffic'];
            $updParams[':daily_traffic2'] = $params[':daily_traffic'];
            $updParams[':is_online_chk'] = $params[':is_online'];
            unset(
                $updParams[':last_online'],
                $updParams[':is_online'],
                $updParams[':framed_ip'],
                $updParams[':mac_address'],
                $updParams[':daily_traffic']
            );
            $upd->execute($updParams);
            return true;
        }
        if ($ins === false) {
            return false;
        }
        // لا يوجد صف لهذه الشركة — أدخل صفاً جديداً
        if ($accountId > 0) {
            $insSql = $pdo->prepare(
                'INSERT INTO sas_users_cache
                    (tenant_id, username, sas_user_id, firstname, lastname, display_name, phone, profile_id, profile_name,
                     enabled, expire_at, parent_id, parent_name, city, email, company, last_online, is_online, framed_ip, mac_address, daily_traffic, local_subscriber_id, synced_at, sas_account_id)
                 VALUES
                    (:tenant_id, :username, :sas_user_id, :firstname, :lastname, :display_name, :phone, :profile_id, :profile_name,
                     :enabled, :expire_at, :parent_id, :parent_name, :city, :email, :company, :last_online, :is_online, :framed_ip, :mac_address, :daily_traffic, NULL, :synced_at, :sas_account_id)'
            );
        } else {
            $insSql = $pdo->prepare(
                'INSERT INTO sas_users_cache
                    (tenant_id, username, sas_user_id, firstname, lastname, display_name, phone, profile_id, profile_name,
                     enabled, expire_at, parent_id, parent_name, city, email, company, last_online, is_online, framed_ip, mac_address, daily_traffic, local_subscriber_id, synced_at)
                 VALUES
                    (:tenant_id, :username, :sas_user_id, :firstname, :lastname, :display_name, :phone, :profile_id, :profile_name,
                     :enabled, :expire_at, :parent_id, :parent_name, :city, :email, :company, :last_online, :is_online, :framed_ip, :mac_address, :daily_traffic, NULL, :synced_at)'
            );
        }
        $insSql->execute($params);
        return true;
    } catch (Exception $e) {
        // إن تعارض username بسبب PK قديم: حدّث فقط إن كان نفس الشركة، وإلا تجاهل السرقة
        try {
            $chk = $pdo->prepare('SELECT tenant_id FROM sas_users_cache WHERE username = :u AND tenant_id = :t LIMIT 1');
            $chk->execute(array(':u' => $username, ':t' => $tenantId));
            $existTid = (int) $chk->fetchColumn();
            if ($existTid === $tenantId) {
                $pdo->prepare(
                    'UPDATE sas_users_cache SET synced_at = :s, display_name = :d, enabled = :e, expire_at = :x, parent_id = :p
                     WHERE username = :u AND tenant_id = :t'
                )->execute(array(
                    ':s' => $nowSql,
                    ':d' => $params[':display_name'],
                    ':e' => $params[':enabled'],
                    ':x' => $params[':expire_at'],
                    ':p' => $params[':parent_id'],
                    ':u' => $username,
                    ':t' => $tenantId,
                ));
                return true;
            }
        } catch (Exception $e2) {
        }
        return false;
    }
}
function sas_cache_sync_local_phone($pdo, $username, $phone)
{
    $username = trim((string) $username);
    if ($username === '') {
        return;
    }
    $store = function_exists('normalize_phone') ? normalize_phone($phone) : trim((string) $phone);
    if ($store === '') {
        $store = trim((string) $phone);
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    try {
        $lid = 0;
        $st = $pdo->prepare('SELECT local_subscriber_id FROM sas_users_cache WHERE username = :u AND tenant_id = :t LIMIT 1');
        $st->execute(array(':u' => $username, ':t' => $tid));
        $lid = (int) $st->fetchColumn();
        if ($lid <= 0) {
            $st2 = $pdo->prepare('SELECT id FROM subscribers WHERE sas_username = :u AND tenant_id = :t LIMIT 1');
            $st2->execute(array(':u' => $username, ':t' => $tid));
            $lid = (int) $st2->fetchColumn();
        }
        if ($lid > 0) {
            $oldPhone = '';
            try {
                $ost = $pdo->prepare('SELECT phone FROM subscribers WHERE id = :id LIMIT 1');
                $ost->execute(array(':id' => $lid));
                $oldPhone = (string) $ost->fetchColumn();
            } catch (Exception $e) {
            }
            $pdo->prepare('UPDATE subscribers SET phone = :p WHERE id = :id')
                ->execute(array(':p' => $store, ':id' => $lid));
            if (function_exists('log_subscriber_phone_change')) {
                log_subscriber_phone_change(
                    $pdo,
                    $lid,
                    $oldPhone,
                    $store,
                    'جدول الساس (' . $username . ')'
                );
            }
        }
    } catch (Exception $e) {
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
        'parent_id', 'parent_name', 'city', 'email', 'company', 'last_online', 'is_online', 'framed_ip', 'mac_address', 'daily_traffic',
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
    $tidPatch = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tidPatch <= 0) {
        $tidPatch = 1;
    }
    $params[':t'] = $tidPatch;
    $pdo->prepare('UPDATE sas_users_cache SET ' . implode(', ', $cols) . ' WHERE username = :u AND tenant_id = :t')
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
function sas_sync_submanager_users($pdo, $api, $nowSql)
{
    if (!$api || !method_exists($api, 'getManagers') || !method_exists($api, 'listUsersPage')) {
        return 0;
    }
    $raw = $api->getManagers();
    if (function_exists('sas_response_is_error') && sas_response_is_error($raw)) {
        return 0;
    }
    $rows = is_array($raw) ? $raw : array();
    if (isset($rows['data']) && is_array($rows['data'])) {
        $rows = $rows['data'];
    }
    $saved = 0;
    $seen = array();
    foreach ($rows as $m) {
        if (!is_array($m)) {
            continue;
        }
        $pid = 0;
        if (isset($m['id']) && is_numeric($m['id'])) {
            $pid = (int) $m['id'];
        }
        if ($pid <= 0 || isset($seen[$pid])) {
            continue;
        }
        $seen[$pid] = true;
        $pageNum = 1;
        $guard = 0;
        do {
            $page = $api->listUsersPage(($pageNum - 1) * 100, 100, '', $pid);
            if (empty($page['ok'])) {
                break;
            }
            $users = isset($page['rows']) ? $page['rows'] : array();
            if (!$users) {
                break;
            }
            foreach ($users as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (sas_cache_upsert_row($pdo, $row, $nowSql, false)) {
                    $saved++;
                }
            }
            $pageNum++;
            $guard++;
            $complete = !empty($page['complete']);
        } while (!$complete && $guard < 40);
    }
    return $saved;
}

function sas_sync_users_from_api($pdo, $config, $force = false, $reset = false)
{
    ensure_sas_users_cache_table($pdo);
    if (function_exists('tenants_migrate_sas_cache_pk')) {
        try {
            tenants_migrate_sas_cache_pk($pdo);
        } catch (Exception $e) {
        }
    }
    if (function_exists('ensure_tenant_sas_accounts_schema')) {
        ensure_tenant_sas_accounts_schema($pdo);
    }

    $tenantIdEarly = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if (function_exists('sas_ownership_drop_copied_cache')) {
        sas_ownership_drop_copied_cache($pdo, $tenantIdEarly);
    }
    // مزامنة كل حسابات الريسيلر دفعة واحدة (بدون تكرار متداخل)
    if (empty($GLOBALS['sas_sync_multi_lock'])
        && function_exists('tenant_sas_accounts_ready')
        && $tenantIdEarly > 0
    ) {
        $readyAcc = tenant_sas_accounts_ready($pdo, $tenantIdEarly);
        if (count($readyAcc) > 1) {
            $GLOBALS['sas_sync_multi_lock'] = true;
            $GLOBALS['sas_sync_skip_purge'] = true;
            $anyOk = false;
            $sum = 0;
            $lastMode = 'error';
            $lastMeta = sas_sync_meta($pdo);
            $first = true;
            foreach ($readyAcc as $acc) {
                $GLOBALS['sas_force_account'] = $acc;
                $resetThis = $first ? $reset : true;
                $first = false;
                $accOk = false;
                $accN = 0;
                $guard = 0;
                do {
                    list($ok, $n, $mode, $meta) = sas_sync_users_from_api($pdo, $config, true, $resetThis);
                    $resetThis = false;
                    $lastMeta = $meta;
                    $lastMode = $mode;
                    if ($ok) {
                        $accOk = true;
                        $accN = max($accN, (int) $n);
                    }
                    $guard++;
                } while ($ok && $mode === 'progress' && $guard < 500);
                if ($accOk) {
                    $anyOk = true;
                    $sum += $accN;
                }
                try {
                    $pdo->prepare(
                        'UPDATE tenant_sas_accounts SET last_ok_at = IF(:ok = 1, NOW(), last_ok_at), last_error = :e WHERE id = :id'
                    )->execute(array(
                        ':ok' => $accOk ? 1 : 0,
                        ':e' => $accOk ? null : (isset($lastMeta['last_error']) ? $lastMeta['last_error'] : 'fail'),
                        ':id' => (int) $acc['id'],
                    ));
                } catch (Exception $e) {
                }
            }
            unset($GLOBALS['sas_force_account'], $GLOBALS['sas_sync_multi_lock'], $GLOBALS['sas_sync_skip_purge']);
            if ($anyOk) {
                sas_sync_meta_save($pdo, array(
                    'last_ok_at' => date('Y-m-d H:i:s'),
                    'last_count' => $sum,
                    'syncing_at' => null,
                    'sync_offset' => 0,
                    'last_error' => null,
                ));
                return array(true, $sum, 'synced', sas_sync_meta($pdo));
            }
            return array(false, $sum, $lastMode, $lastMeta);
        }
    }

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
    $tenantId = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tenantId <= 0) {
        $tenantId = 1;
    }
    $cacheNow = 0;
    try {
        $stC = $pdo->prepare('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = :t');
        $stC->execute(array(':t' => $tenantId));
        $cacheNow = (int) $stC->fetchColumn();
    } catch (Exception $e) {
        $cacheNow = 0;
    }
    if (!$force && !$reset && $offset <= 0 && $cacheNow > 0 && !empty($meta['last_ok_at'])) {
        $okTs = strtotime($meta['last_ok_at']);
        if ($okTs && ($now - $okTs) < 180) {
            return array(true, (int) $meta['last_count'], 'cache', $meta);
        }
    }

    if (!empty($GLOBALS['sas_force_account']) && function_exists('sas_make_connector_from_account')) {
        $api = sas_make_connector_from_account($GLOBALS['sas_force_account']);
    } else {
        $api = sas_make_connector($config);
    }
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
        $api->setTimeout(!empty($GLOBALS['sas_warm_pages']) ? 12 : 20);
        if (!$api->login()) {
            $err = $api->getLastError();
            if (function_exists('sas_mark_connection')) {
                sas_mark_connection($pdo, $config, false, $err !== '' ? $err : 'فشل الدخول للساس');
            }
            sas_sync_meta_save($pdo, array(
                'syncing_at' => null,
                'last_error' => $err !== '' ? $err : 'فشل الدخول للساس',
            ));
            // مهم: لا purge عند فشل الدخول
            return array(false, 0, 'error', sas_sync_meta($pdo));
        }
        if (function_exists('sas_mark_connection')) {
            sas_mark_connection($pdo, $config, true, 'ok');
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

        $tenantId = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        if ($tenantId <= 0) {
            $tenantId = 1;
        }

        $warmBurst = isset($GLOBALS['sas_warm_pages']) ? (int) $GLOBALS['sas_warm_pages'] : 1;
        if ($warmBurst < 1) {
            $warmBurst = 1;
        }
        if ($warmBurst > 8) {
            $warmBurst = 8;
        }
        $burstStep = 0;
        sas_warm_next_page:
        $burstStep++;
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
            if (sas_cache_upsert_row($pdo, $row, $nowSql, null)) {
                $saved++;
            }
        }
        if ($rows && $saved <= 0) {
            $pkHint = '';
            if (function_exists('tenants_sas_cache_pk_is_ok') && !tenants_sas_cache_pk_is_ok($pdo)) {
                $pkHint = ' — مفتاح كاش الساس غير مركّب (tenant_id+username)';
            }
            sas_sync_meta_save($pdo, array(
                'syncing_at' => null,
                'last_error' => 'الساس رجّع مشتركين لكن ما انحفظوا بالكاش' . $pkHint,
            ));
            return array(false, 0, 'error', sas_sync_meta($pdo));
        }

        $nextPage = $pageNum + 1;
        if (!$rows && $pageNum <= 1) {
            $existing = (int) $pdo->query(
                'SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . (int) $tenantId
            )->fetchColumn();
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
        $totalNow = (int) $pdo->query(
            'SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . (int) $tenantId
        )->fetchColumn();

        if ($done) {
            sas_sync_submanager_users($pdo, $api, $nowSql);
            $started = isset($meta['sync_started_at']) ? $meta['sync_started_at'] : '';
            if ($started === '' && !empty($meta['last_try_at'])) {
                $started = $meta['last_try_at'];
            }
            if ($started !== '') {
                try {
                    $freshSt = $pdo->prepare(
                        'SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = :tid AND synced_at >= :t'
                    );
                    $freshSt->execute(array(':tid' => $tenantId, ':t' => $started));
                    $freshCnt = (int) $freshSt->fetchColumn();
                    $need = ($expected > 0) ? max(1, (int) ceil($expected * 0.5)) : 1;
                    if ($freshCnt >= $need && empty($GLOBALS['sas_sync_skip_purge'])) {
                        // امسح فقط كاش هذه الشركة — لا تلمس شركات أخرى ولا المشتركين/الديون
                        $del = $pdo->prepare(
                            'DELETE FROM sas_users_cache WHERE tenant_id = :tid AND synced_at < :t'
                        );
                        $del->execute(array(':tid' => $tenantId, ':t' => $started));
                    }
                } catch (Exception $e) {
                }
            }
            $totalNow = (int) $pdo->query(
                'SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . (int) $tenantId
            )->fetchColumn();
            if ($expected > 0 && $totalNow <= 0) {
                sas_sync_meta_save($pdo, array(
                    'syncing_at' => null,
                    'last_error' => 'انتهت المزامنة بدون حفظ مشتركين لهذه الشركة',
                ));
                return array(false, 0, 'error', sas_sync_meta($pdo));
            }
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

        if ($burstStep < $warmBurst) {
            $pageNum = $nextPage;
            $start = ($pageNum - 1) * $pageSize;
            goto sas_warm_next_page;
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

function sas_owner_parent_label($pdo = null)
{
    if (!$pdo && isset($GLOBALS['pdo'])) {
        $pdo = $GLOBALS['pdo'];
    }
    $label = '';
    if ($pdo && function_exists('tenant_row') && function_exists('current_tenant_id')) {
        $row = tenant_row($pdo, current_tenant_id());
        if ($row && !empty($row['name'])) {
            $label = trim((string) $row['name']);
        }
    }
    if ($label === '' && $pdo && function_exists('sas_config_for_tenant')) {
        $cfg = sas_config_for_tenant($pdo, isset($GLOBALS['config']) ? $GLOBALS['config'] : array());
        if (!empty($cfg['username'])) {
            $label = trim((string) $cfg['username']);
        }
    }
    return $label;
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
        OR c.parent_name LIKE :q
        OR IFNULL(NULLIF(c.parent_name, ""), :selflab) LIKE :q
        OR CONCAT(IFNULL(c.firstname,""), " ", IFNULL(c.lastname,"")) LIKE :q
        OR CONCAT(IFNULL(c.lastname,""), " ", IFNULL(c.firstname,"")) LIKE :q
        OR REPLACE(REPLACE(IFNULL(c.display_name,""), " ", ""), "-", "") LIKE :qns
    ';
    $params[':q'] = '%' . $q . '%';
    $params[':qns'] = '%' . preg_replace('/[\s\-]+/', '', $q) . '%';
    $params[':selflab'] = function_exists('sas_owner_parent_label') ? sas_owner_parent_label() : '';
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
        return ' AND NOT (c.enabled = 1 AND c.expire_at IS NOT NULL AND c.expire_at >= NOW())';
    }
    if ($subFilter === 'today') {
        return ' AND c.expire_at IS NOT NULL AND DATE(c.expire_at) = CURDATE()';
    }
    if ($subFilter === 'soon') {
        return ' AND c.expire_at IS NOT NULL AND DATE(c.expire_at) > CURDATE()
                 AND DATE(c.expire_at) <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)';
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
    $userEq = function_exists('sas_sql_username_eq')
        ? sas_sql_username_eq('su.sas_username', 'c.username')
        : 'su.sas_username = c.username';
    $selfEq = function_exists('sas_sql_username_eq')
        ? sas_sql_username_eq('s.sas_username', 'c.username')
        : 's.sas_username = c.username';
    return ' FROM sas_users_cache c
     LEFT JOIN subscribers su ON ' . $userEq . ' AND su.tenant_id = c.tenant_id
     LEFT JOIN subscribers s ON s.id = IF(su.id IS NOT NULL, su.id, c.local_subscriber_id)
       AND (s.sas_username IS NULL OR s.sas_username = \'\' OR ' . $selfEq . ')
       AND (s.sas_user_id IS NULL OR s.sas_user_id = 0 OR c.sas_user_id IS NULL OR c.sas_user_id = 0 OR s.sas_user_id = c.sas_user_id)';
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
        NULL AS last_msg_phone,
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
        (SELECT m.phone FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_phone,
        (SELECT m.created_at FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_at,
        (SELECT m.id FROM message_logs m WHERE m.subscriber_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_msg_id';
    return $sql;
}

/** آخر رسالة لكل مشترك ظاهر، حتى ما تبقى خانة الرسالة رصاصية في قائمة الصفحة. */
function sas_attach_last_messages($pdo, $rows)
{
    if (!is_array($rows) || !$rows) {
        return is_array($rows) ? $rows : array();
    }
    $ids = array();
    foreach ($rows as $r) {
        $id = !empty($r['local_id']) ? (int) $r['local_id'] : 0;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return $rows;
    }
    $by = array();
    try {
        $sql = 'SELECT m.subscriber_id, m.success AS last_msg_ok, m.message_type AS last_msg_type,
                m.body AS last_msg_body, m.response_json AS last_msg_response, m.phone AS last_msg_phone,
                m.created_at AS last_msg_at, m.id AS last_msg_id
            FROM message_logs m
            INNER JOIN (
                SELECT subscriber_id, MAX(id) AS mid
                FROM message_logs
                WHERE subscriber_id IN (' . implode(',', $ids) . ')
                GROUP BY subscriber_id
            ) t ON t.mid = m.id';
        foreach ($pdo->query($sql)->fetchAll() as $m) {
            $by[(int) $m['subscriber_id']] = $m;
        }
    } catch (Exception $e) {
        return $rows;
    }
    foreach ($rows as $i => $r) {
        $id = !empty($r['local_id']) ? (int) $r['local_id'] : 0;
        if ($id <= 0 || !isset($by[$id])) {
            continue;
        }
        $m = $by[$id];
        $rows[$i]['last_msg_ok'] = $m['last_msg_ok'];
        $rows[$i]['last_msg_type'] = $m['last_msg_type'];
        $rows[$i]['last_msg_body'] = $m['last_msg_body'];
        $rows[$i]['last_msg_response'] = $m['last_msg_response'];
        $rows[$i]['last_msg_phone'] = $m['last_msg_phone'];
        $rows[$i]['last_msg_at'] = $m['last_msg_at'];
        $rows[$i]['last_msg_id'] = $m['last_msg_id'];
    }
    return $rows;
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
    $phone = '';
    if (isset($row['local_phone']) && trim((string) $row['local_phone']) !== '') {
        $localP = (string) $row['local_phone'];
        if (!function_exists('phone_is_placeholder') || !phone_is_placeholder($localP)) {
            $phone = $localP;
        }
    }
    if ($phone === '' && isset($row['phone']) && $row['phone'] !== '') {
        $phone = (string) $row['phone'];
    }
    if ($phone !== '' && function_exists('format_phone_display')) {
        $shown = format_phone_display($phone);
        $phone = ($shown === '—') ? '' : $shown;
    }
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
    $parentName = !empty($row['parent_name']) ? $row['parent_name'] : (function_exists('sas_owner_parent_label') ? sas_owner_parent_label() : '');
    if ($parentName === '') {
        $parentName = '-';
    }
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
    $noPhone = function_exists('subscriber_phone_missing')
        ? subscriber_phone_missing($phone)
        : ($phone === '' || $phone === '-' || $phone === '—');
    $noWa = (!$noPhone && function_exists('subscriber_row_is_no_whatsapp'))
        ? subscriber_row_is_no_whatsapp($row)
        : false;
    $msgFail = ($hasMsg && !$msgOk) ? '1' : '0';
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
        . ' data-online="' . ($isOnline ? '1' : '0') . '"'
        . ' data-msg-fail="' . $msgFail . '"'
        . ' data-log-id="' . $logId . '"'
        . ' data-has-days="' . ($hasExpire ? '1' : '0') . '"'
        . ' data-rental="' . e(isset($row['rental_device_id']) ? (string) $row['rental_device_id'] : '') . '"'
        . ' id="sas-row-' . e(preg_replace('/[^a-zA-Z0-9_-]/', '_', $username)) . '">';
    $html .= '<td class="sub-check-cell"><label class="sub-check-lab"><input type="checkbox" class="sub-check" value="' . e($username) . '"></label></td>';
    $html .= '<td class="col-num">' . (int) $n . '</td>';
    $html .= '<td class="status-cell col-status" title="' . e($statusTitle) . '">' . $statusHtml . '</td>';
    $pingHtml = '';
    $pingMode = 'off';
    if (isset($GLOBALS['pdo']) && function_exists('sas_ping_mode')) {
        $pingMode = sas_ping_mode($GLOBALS['pdo']);
    }
    $pingConfirmedNa = $isOnline && function_exists('sas_ping_user_is_na') && sas_ping_user_is_na($username);
    if ($pingConfirmedNa) {
        $pingHtml = '<span class="sub-ping-na" dir="ltr">N/A</span>';
    } elseif ($isOnline && isset($row['online_ping']) && $row['online_ping'] !== null && $row['online_ping'] !== '' && (int) $row['online_ping'] > 0) {
        $ms = (int) $row['online_ping'];
        $tone = ($ms <= 80) ? '' : (($ms <= 180) ? ' is-mid' : ' is-slow');
        $pingHtml = '<span class="sub-ping' . $tone . '" dir="ltr">' . $ms . '<small>ms</small></span>';
    } elseif ($pingMode !== 'off' && $isOnline) {
        $pingHtml = '';
    } elseif (!$isOnline) {
        $ago = !empty($row['last_online']) ? sas_ago_label($row['last_online'], $lang === 'en') : '';
        if ($ago !== '') {
            $pingHtml = '<span class="sub-ago" title="' . e((string) $row['last_online']) . '">' . e($ago) . '</span>';
        } else {
            $pingHtml = '';
        }
    }
    $html .= '<td class="col-ping">' . $pingHtml . '</td>';
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
        $ipOpenTip = $lang === 'en' ? 'Open device' : 'فتح الجهاز';
        $html .= '<td class="col-ip" dir="ltr"><span class="sas-ip-wrap">'
            . '<button type="button" class="sas-user-copy" data-copy="' . e($framedIp) . '" title="' . e($ipCopyTip) . '" aria-label="' . e($ipCopyTip) . '">⧉</button>';
        if ($ipHref !== '') {
            $html .= '<a class="sas-link sas-ip-link" href="' . e($ipHref) . '" target="_blank" rel="noopener noreferrer" title="' . e($ipOpenTip) . '">' . e($framedIp) . '</a>';
        } else {
            $html .= '<span>' . e($framedIp) . '</span>';
        }
        $html .= '</span></td>';
    } else {
        $html .= '<td class="col-ip" dir="ltr">-</td>';
    }
    $macShow = isset($row['mac_address']) ? trim((string) $row['mac_address']) : '';
    $macTitle = $isOnline
        ? ($lang === 'en' ? 'Connected MAC' : 'ماك الاتصال الحالي')
        : ($lang === 'en' ? 'Last MAC' : 'آخر ماك');
    $html .= '<td class="col-mac" dir="ltr" title="' . e($macTitle) . '">' . ($macShow !== '' ? e($macShow) : '—') . '</td>';
    $rentBadge = '';
    if (function_exists('subscriber_has_rental') && subscriber_has_rental($rentSub) && function_exists('rental_badge_html')) {
        $rentBadge = rental_badge_html($rentSub);
    }
    // الشعار يسار الاسم (في RTL: الاسم أولاً ثم الشعار)
    $fnText = ($fn !== '') ? $fn : '-';
    $html .= '<td class="col-fn"><span class="sas-fn-wrap' . ($rentBadge !== '' ? ' has-rent' : '') . '">'
        . '<a class="sas-link sas-fn-link" href="' . e($detailUrl) . '" title="' . e($fnText) . '">' . e($fnText) . '</a>'
        . ($rentBadge !== '' ? '<span class="sas-rent-mini" title="' . e($rentDevName) . '">' . $rentBadge . '</span>' : '')
        . '</span></td>';
    $html .= '<td class="col-ln"><span class="cell-edit" tabindex="0" data-edit="lastname" data-allow-empty="1" data-id="' . e($username) . '" data-value="' . e($ln) . '" title="' . e($editTip) . '">' . e($ln !== '' ? $ln : '-') . '</span></td>';
    $html .= '<td class="col-phone"><span class="cell-edit" tabindex="0" data-edit="phone" data-allow-empty="1" data-id="' . e($username) . '" data-value="' . e($phone) . '" title="' . e($editTip) . '">' . e($phone !== '' ? $phone : '-') . '</span>'
        . (function_exists('wa_miss_html') ? wa_miss_html($noWa) : '') . '</td>';
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
    $msgShort = $hasMsg
        ? (function_exists('message_short_summary')
            ? message_short_summary(isset($row['last_msg_type']) ? $row['last_msg_type'] : '', isset($row['last_msg_body']) ? $row['last_msg_body'] : '', $msgOk)
            : '')
        : ($lang === 'en' ? 'No message sent' : 'لم تُرسل رسالة');
    if ($noPhone) {
        $msgShort = $lang === 'en' ? 'Warning: this subscriber has no phone number' : 'تحذير: المشترك ما عنده رقم';
    } elseif ($noWa) {
        $msgShort = $lang === 'en' ? 'Warning: this number is not on WhatsApp' : 'تحذير: الرقم موجود بس مو على واتساب';
    } elseif ($hasMsg && !$msgOk) {
        $msgShort = $lang === 'en' ? 'Warning: send failed' : 'تحذير: فشل الإرسال';
    }
    $html .= '<td class="msg-status-cell col-msg" title="' . e($msgShort) . '">';
    $html .= function_exists('msg_table_status_html')
        ? msg_table_status_html($hasMsg, $msgOk, $noWa, $username, $logId, $lang, $noPhone)
        : '<span class="dot-msg ' . ($hasMsg ? ($msgOk ? 'ok' : 'fail') : 'off') . '"></span>';
    $html .= '</td>';
    $html .= '</tr>';
    return $html;
}

function sas_session_tenant_id()
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    return $tid > 0 ? $tid : 1;
}

function sas_session_time($value)
{
    if ($value === null || $value === '' || is_array($value)) {
        return null;
    }
    if (is_numeric($value)) {
        $n = (int) $value;
        if ($n > 1000000000000) {
            $n = (int) floor($n / 1000);
        }
        if ($n > 946684800 && $n < 4102444800) {
            return date('Y-m-d H:i:s', $n);
        }
        return null;
    }
    $raw = trim((string) $value);
    if ($raw === '' || $raw === '0' || $raw === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime($raw);
    if ($ts && $ts > 946684800) {
        return date('Y-m-d H:i:s', $ts);
    }
    return null;
}

function sas_session_pick($row, $keys)
{
    if (!is_array($row)) {
        return '';
    }
    $nests = array($row);
    foreach (array('acct', 'session', 'radius', 'online', 'data') as $nk) {
        if (isset($row[$nk]) && is_array($row[$nk]) && !isset($row[$nk][0])) {
            $nests[] = $row[$nk];
        }
    }
    foreach ($nests as $block) {
        foreach ($keys as $k) {
            if (!isset($block[$k]) || is_array($block[$k])) {
                continue;
            }
            $v = trim((string) $block[$k]);
            if ($v !== '' && $v !== '0') {
                return $v;
            }
        }
    }
    return '';
}

function sas_session_number($raw)
{
    if ($raw === null || is_array($raw)) {
        return null;
    }
    $raw = trim((string) $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $n = (float) $raw;
    if ($n < 0) {
        return null;
    }
    return $n;
}

function sas_session_side_bytes($row, $octetKeys, $gigaKeys)
{
    $oct = null;
    $giga = 0.0;
    $found = false;
    foreach ($octetKeys as $k) {
        $n = sas_session_number(sas_session_pick($row, array($k)));
        if ($n !== null) {
            $oct = $n;
            $found = true;
            break;
        }
    }
    foreach ($gigaKeys as $k) {
        $n = sas_session_number(sas_session_pick($row, array($k)));
        if ($n !== null && $n > 0) {
            $giga = $n;
            $found = true;
            break;
        }
    }
    if (!$found) {
        return null;
    }
    $bytes = ($giga * 4294967296) + ($oct === null ? 0 : $oct);
    if ($bytes > 9000000000000000) {
        $bytes = 9000000000000000;
    }
    return (int) round($bytes);
}

function sas_session_usage($row)
{
    $down = sas_session_side_bytes($row, array(
        'acctoutputoctets', 'acct_output_octets', 'outputoctets', 'output_octets',
        'bytes_out', 'download', 'rx_bytes', 'rx', 'down_bytes', 'total_download',
    ), array('acctoutputgigawords', 'acct_output_gigawords'));
    $up = sas_session_side_bytes($row, array(
        'acctinputoctets', 'acct_input_octets', 'inputoctets', 'input_octets',
        'bytes_in', 'upload', 'tx_bytes', 'tx', 'up_bytes', 'total_upload',
    ), array('acctinputgigawords', 'acct_input_gigawords'));
    if ($down === null && $up === null) {
        $total = sas_session_side_bytes($row, array(
            'acctsessionoctets', 'total_bytes', 'totalbytes', 'session_bytes', 'usage', 'used',
        ), array());
        return array('in' => null, 'out' => $total);
    }
    return array(
        'in' => $up === null ? 0 : $up,
        'out' => $down === null ? 0 : $down,
    );
}

function sas_session_pack($row)
{
    if (!is_array($row)) {
        return null;
    }
    $mac = sas_cache_mac($row);
    $ip = sas_cache_framed_ip($row);
    $nas = sas_session_pick($row, array('nasipaddress', 'nas_ip_address', 'nasip', 'nas_ip'));
    $start = null;
    foreach (array('acctstarttime', 'acct_start_time', 'started_at', 'start_time') as $k) {
        $start = sas_session_time(sas_session_pick($row, array($k)));
        if ($start) {
            break;
        }
    }
    $stop = null;
    foreach (array('acctstoptime', 'acct_stop_time', 'stopped_at', 'stop_time') as $k) {
        $stop = sas_session_time(sas_session_pick($row, array($k)));
        if ($stop) {
            break;
        }
    }
    $sec = null;
    $secRaw = sas_session_pick($row, array('acctsessiontime', 'acct_session_time', 'session_time'));
    if ($secRaw !== '' && is_numeric($secRaw)) {
        $sec = (int) $secRaw;
    }
    $usage = sas_session_usage($row);
    $rad = sas_cache_radacct_id($row);
    $sid = sas_session_pick($row, array('acctsessionid', 'acct_session_id'));
    if ($rad !== '') {
        $key = 'r' . $rad;
    } elseif ($sid !== '') {
        $key = 's' . substr($sid, 0, 70);
    } elseif ($start) {
        $key = 't' . substr(md5($start . '|' . $mac . '|' . $ip), 0, 24);
    } else {
        $key = 'live';
    }
    return array(
        'key' => sas_clip($key, 80),
        'mac' => $mac !== '' ? sas_clip($mac, 32) : null,
        'ip' => $ip !== '' ? sas_clip($ip, 45) : null,
        'nas' => $nas !== '' ? sas_clip($nas, 45) : null,
        'start' => $start,
        'stop' => $stop,
        'seconds' => $sec,
        'bytes_in' => $usage['in'],
        'bytes_out' => $usage['out'],
        'open' => $stop === null ? 1 : 0,
    );
}

function sas_session_duration_label($start, $stop, $seconds, $open)
{
    $sec = null;
    if ($seconds !== null && $seconds !== '' && is_numeric($seconds) && (int) $seconds > 0) {
        $sec = (int) $seconds;
    } elseif ($start) {
        $a = strtotime((string) $start);
        $b = 0;
        if ($stop) {
            $b = strtotime((string) $stop);
        } elseif ($open) {
            $b = time();
        }
        if ($a && $b && $b >= $a) {
            $sec = $b - $a;
        }
    }
    if ($sec === null || $sec < 0) {
        return '';
    }
    $h = (int) floor($sec / 3600);
    $m = (int) floor(($sec % 3600) / 60);
    $s = (int) ($sec % 60);
    return sprintf('%d:%02d:%02d', $h, $m, $s);
}

function sas_sessions_upsert($pdo, $username, $packs)
{
    $username = sas_clip(trim((string) $username), 80);
    if ($username === '' || !is_array($packs) || !$packs) {
        return;
    }
    ensure_sas_users_cache_table($pdo);
    $tid = sas_session_tenant_id();
    $now = date('Y-m-d H:i:s');
    $sql = 'INSERT INTO sas_user_sessions
        (tenant_id, username, session_key, mac_address, framed_ip, nas_ip, started_at, stopped_at, session_seconds, bytes_in, bytes_out, is_open, synced_at)
        VALUES (:t, :u, :k, :m, :ip, :nas, :st, :sp, :sec, :bi, :bo, :op, :sy)
        ON DUPLICATE KEY UPDATE
          mac_address = IF(:m2 IS NULL OR :m2 = "", mac_address, :m3),
          framed_ip = IF(:ip2 IS NULL OR :ip2 = "", framed_ip, :ip3),
          nas_ip = IF(:nas2 IS NULL OR :nas2 = "", nas_ip, :nas3),
          started_at = IFNULL(:st2, started_at),
          stopped_at = :sp2,
          session_seconds = IFNULL(:sec2, session_seconds),
          bytes_in = IF(:bik = 1, :bi2, bytes_in),
          bytes_out = IF(:bok = 1, :bo2, bytes_out),
          is_open = :op2,
          synced_at = :sy2';
    $st = $pdo->prepare($sql);
    $latestMac = '';
    $dropLive = false;
    foreach ($packs as $p) {
        if (!is_array($p) || empty($p['key'])) {
            continue;
        }
        $key = sas_clip((string) $p['key'], 80);
        if (strpos($key, 'live:') !== 0 && $key !== 'live') {
            $dropLive = true;
        }
        $mac = !empty($p['mac']) ? $p['mac'] : null;
        $ip = !empty($p['ip']) ? $p['ip'] : null;
        $nas = !empty($p['nas']) ? $p['nas'] : null;
        $start = !empty($p['start']) ? $p['start'] : null;
        $stop = !empty($p['stop']) ? $p['stop'] : null;
        $sec = (isset($p['seconds']) && $p['seconds'] !== null && $p['seconds'] !== '') ? (int) $p['seconds'] : null;
        $open = !empty($p['open']) ? 1 : 0;
        $binKnown = array_key_exists('bytes_in', $p) && $p['bytes_in'] !== null;
        $boutKnown = array_key_exists('bytes_out', $p) && $p['bytes_out'] !== null;
        $bin = $binKnown ? (int) $p['bytes_in'] : 0;
        $bout = $boutKnown ? (int) $p['bytes_out'] : 0;
        $st->execute(array(
            ':t' => $tid,
            ':u' => $username,
            ':k' => $key,
            ':m' => $mac,
            ':ip' => $ip,
            ':nas' => $nas,
            ':st' => $start,
            ':sp' => $stop,
            ':sec' => $sec,
            ':bi' => $bin,
            ':bo' => $bout,
            ':op' => $open,
            ':sy' => $now,
            ':m2' => $mac,
            ':m3' => $mac,
            ':ip2' => $ip,
            ':ip3' => $ip,
            ':nas2' => $nas,
            ':nas3' => $nas,
            ':st2' => $start,
            ':sp2' => $stop,
            ':sec2' => $sec,
            ':bik' => $binKnown ? 1 : 0,
            ':bi2' => $bin,
            ':bok' => $boutKnown ? 1 : 0,
            ':bo2' => $bout,
            ':op2' => $open,
            ':sy2' => $now,
        ));
        if ($mac && ($latestMac === '' || $open)) {
            $latestMac = $mac;
        }
    }
    if ($dropLive) {
        $pdo->prepare('DELETE FROM sas_user_sessions WHERE tenant_id = :t AND username = :u AND session_key LIKE :lk')
            ->execute(array(':t' => $tid, ':u' => $username, ':lk' => 'live:%'));
    }
    if ($latestMac !== '') {
        $pdo->prepare('UPDATE sas_users_cache SET mac_address = :m WHERE tenant_id = :t AND username = :u')
            ->execute(array(':m' => $latestMac, ':t' => $tid, ':u' => $username));
    }
}

function sas_sessions_replace_missing($pdo, $username, $keys)
{
    $username = sas_clip(trim((string) $username), 80);
    if ($username === '' || !is_array($keys) || !$keys) {
        return;
    }
    $tid = sas_session_tenant_id();
    $ph = array();
    $bind = array(':t' => $tid, ':u' => $username);
    $i = 0;
    foreach ($keys as $k) {
        $k = sas_clip((string) $k, 80);
        if ($k === '') {
            continue;
        }
        $ph[] = ':k' . $i;
        $bind[':k' . $i] = $k;
        $i++;
    }
    if (!$ph) {
        return;
    }
    $sql = 'DELETE FROM sas_user_sessions WHERE tenant_id = :t AND username = :u AND session_key NOT IN (' . implode(',', $ph) . ')';
    $pdo->prepare($sql)->execute($bind);
}

function sas_sessions_note_online($pdo, $rowByUser)
{
    ensure_sas_users_cache_table($pdo);
    $tid = sas_session_tenant_id();
    $pdo->prepare('UPDATE sas_user_sessions SET is_open = 0 WHERE tenant_id = :t AND is_open = 1')
        ->execute(array(':t' => $tid));
    if (!is_array($rowByUser) || !$rowByUser) {
        return;
    }
    foreach ($rowByUser as $username => $row) {
        $username = sas_clip((string) $username, 80);
        if ($username === '' || !is_array($row)) {
            continue;
        }
        $pack = sas_session_pack($row);
        if (!$pack) {
            continue;
        }
        $pack['open'] = 1;
        $pack['stop'] = null;
        if ($pack['key'] === '' || $pack['key'] === 'live') {
            $pack['key'] = sas_clip('live:' . $username, 80);
        }
        sas_sessions_upsert($pdo, $username, array($pack));
    }
}

function sas_sessions_mark_user_closed($pdo, $username)
{
    $username = sas_clip(trim((string) $username), 80);
    if ($username === '') {
        return;
    }
    ensure_sas_users_cache_table($pdo);
    $pdo->prepare('UPDATE sas_user_sessions SET is_open = 0 WHERE tenant_id = :t AND username = :u AND is_open = 1')
        ->execute(array(':t' => sas_session_tenant_id(), ':u' => $username));
}

function sas_sessions_month($pdo, $username)
{
    $empty = array('month_used' => '', 'month_up' => '', 'month_down' => '');
    $username = sas_clip(trim((string) $username), 80);
    if ($username === '') {
        return $empty;
    }
    $from = date('Y-m-01 00:00:00');
    $st = $pdo->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN bytes_in IS NOT NULL THEN bytes_in ELSE 0 END), 0) AS up_b,
            COALESCE(SUM(CASE WHEN bytes_in IS NOT NULL THEN IFNULL(bytes_out, 0) ELSE 0 END), 0) AS down_b,
            COALESCE(SUM(CASE WHEN bytes_in IS NULL THEN IFNULL(bytes_out, 0) ELSE 0 END), 0) AS only_b,
            SUM(CASE WHEN bytes_in IS NOT NULL THEN 1 ELSE 0 END) AS split_n
         FROM sas_user_sessions
         WHERE tenant_id = :t AND username = :u
           AND (
                (started_at IS NOT NULL AND started_at >= :from)
                OR (started_at IS NULL AND is_open = 1)
           )'
    );
    $st->execute(array(
        ':t' => sas_session_tenant_id(),
        ':u' => $username,
        ':from' => $from,
    ));
    $row = $st->fetch();
    if (!is_array($row)) {
        return $empty;
    }
    $up = (float) $row['up_b'];
    $down = (float) $row['down_b'];
    $only = (float) $row['only_b'];
    $total = $up + $down + $only;
    $out = array(
        'month_used' => $total > 0 ? sas_format_bytes($total) : '',
        'month_up' => '',
        'month_down' => '',
    );
    if (!empty($row['split_n'])) {
        $out['month_up'] = sas_format_bytes($up);
        $out['month_down'] = sas_format_bytes($down);
    }
    return $out;
}

function sas_sessions_public($pdo, $username)
{
    $username = sas_clip(trim((string) $username), 80);
    $out = array();
    if ($username === '') {
        return $out;
    }
    ensure_sas_users_cache_table($pdo);
    $st = $pdo->prepare(
        'SELECT mac_address, framed_ip, nas_ip, started_at, stopped_at, session_seconds, bytes_in, bytes_out, is_open
         FROM sas_user_sessions
         WHERE tenant_id = :t AND username = :u
         ORDER BY is_open DESC, started_at DESC, synced_at DESC
         LIMIT 80'
    );
    $st->execute(array(':t' => sas_session_tenant_id(), ':u' => $username));
    $rows = $st->fetchAll();
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $r) {
        $open = !empty($r['is_open']) ? 1 : 0;
        $start = !empty($r['started_at']) ? (string) $r['started_at'] : '';
        $stop = !empty($r['stopped_at']) ? (string) $r['stopped_at'] : '';
        $in = (isset($r['bytes_in']) && $r['bytes_in'] !== null && $r['bytes_in'] !== '') ? (float) $r['bytes_in'] : null;
        $down = (isset($r['bytes_out']) && $r['bytes_out'] !== null && $r['bytes_out'] !== '') ? (float) $r['bytes_out'] : null;
        $upLabel = '';
        $downLabel = '';
        $usedLabel = '';
        if ($in === null && $down === null) {
            $usedLabel = '';
        } elseif ($in === null) {
            $usedLabel = sas_format_bytes($down);
        } else {
            $upLabel = sas_format_bytes($in);
            $downLabel = sas_format_bytes($down === null ? 0 : $down);
            $usedLabel = sas_format_bytes($in + ($down === null ? 0 : $down));
        }
        $out[] = array(
            'mac' => isset($r['mac_address']) ? (string) $r['mac_address'] : '',
            'ip' => isset($r['framed_ip']) ? (string) $r['framed_ip'] : '',
            'nas' => isset($r['nas_ip']) ? (string) $r['nas_ip'] : '',
            'start' => $start,
            'stop' => $stop,
            'open' => $open,
            'duration' => sas_session_duration_label($start, $stop, isset($r['session_seconds']) ? $r['session_seconds'] : null, $open),
            'up' => $upLabel,
            'down' => $downLabel,
            'used' => $usedLabel,
        );
    }
    return $out;
}

function sas_sessions_pull($pdo, $config, $username, $userId = 0)
{
    $username = sas_clip(trim((string) $username), 80);
    $userId = (int) $userId;
    ensure_sas_users_cache_table($pdo);
    $fresh = false;
    $api = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
    if ($api && method_exists($api, 'listUserSessions')) {
        if (function_exists('app_session_close')) {
            app_session_close();
        } elseif (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        try {
            if (method_exists($api, 'setTimeout')) {
                $api->setTimeout(8);
            }
            $rows = $api->listUserSessions($username, $userId);
            $fresh = !empty($api->lastSessionListOk);
        } catch (Exception $e) {
            $rows = array();
        }
        if (is_array($rows) && $rows) {
            $packs = array();
            $keys = array();
            $stopped = 0;
            foreach ($rows as $row) {
                $p = sas_session_pack($row);
                if (!$p) {
                    continue;
                }
                if ($p['key'] === '' || $p['key'] === 'live') {
                    $p['key'] = sas_clip('live:' . substr(md5($username . '|' . $p['mac'] . '|' . $p['ip'] . '|' . $p['start']), 0, 24), 80);
                }
                $packs[] = $p;
                $keys[] = $p['key'];
                if (!empty($p['stop'])) {
                    $stopped++;
                }
            }
            if ($packs) {
                sas_sessions_upsert($pdo, $username, $packs);
                if ($stopped > 0 || count($packs) > 1) {
                    sas_sessions_replace_missing($pdo, $username, $keys);
                } else {
                    $pdo->prepare('UPDATE sas_user_sessions SET is_open = 0 WHERE tenant_id = :t AND username = :u AND is_open = 1 AND session_key <> :k')
                        ->execute(array(
                            ':t' => sas_session_tenant_id(),
                            ':u' => $username,
                            ':k' => $packs[0]['key'],
                        ));
                }
            }
        }
    }
    $month = sas_sessions_month($pdo, $username);
    return array(
        'ok' => true,
        'fresh' => $fresh ? 1 : 0,
        'rows' => sas_sessions_public($pdo, $username),
        'month_used' => $month['month_used'],
        'month_up' => $month['month_up'],
        'month_down' => $month['month_down'],
    );
}

}

