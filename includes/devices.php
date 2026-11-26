<?php

/**
 * أجهزة الشبكة: جرد، مراقبة، ربط بالماك، استيراد/نقل.
 */

function devices_system_is_on($settings = null)
{
    if ($settings === null) {
        $settings = function_exists('settings_load') ? settings_load() : array();
    }
    if (!is_array($settings)) {
        return true;
    }
    if (!array_key_exists('devices_system_enabled', $settings)) {
        return true;
    }
    return !empty($settings['devices_system_enabled']);
}

function devices_types()
{
    return array(
        'powerbeam' => array('ar' => 'بور بيم', 'en' => 'PowerBeam'),
        'litebeam' => array('ar' => 'لايت بيم', 'en' => 'LiteBeam'),
        'nanostation' => array('ar' => 'نانو ستيشن', 'en' => 'NanoStation'),
        'mimosa' => array('ar' => 'ميموسا', 'en' => 'Mimosa'),
        'mikrotik' => array('ar' => 'مايكروتك', 'en' => 'MikroTik'),
    );
}

function devices_roles()
{
    return array(
        'switch' => array('ar' => 'سويتش', 'en' => 'Switch'),
        'ap' => array('ar' => 'مرسلة / AP', 'en' => 'Access point'),
        'link' => array('ar' => 'لنك', 'en' => 'Link'),
    );
}

function devices_statuses()
{
    return array(
        'ok' => array('ar' => 'سليم', 'en' => 'OK'),
        'full_load' => array('ar' => 'فول لود', 'en' => 'Full load'),
        'timeout' => array('ar' => 'تايم أوت', 'en' => 'Timeout'),
        'offline' => array('ar' => 'طافي', 'en' => 'Offline'),
        'unknown' => array('ar' => 'غير معروف', 'en' => 'Unknown'),
    );
}

function devices_type_label($id, $useLang = null)
{
    if ($useLang === null) {
        global $lang;
        $useLang = isset($lang) ? $lang : 'ar';
    }
    $all = devices_types();
    if (!isset($all[$id])) {
        return (string) $id;
    }
    return ($useLang === 'en') ? $all[$id]['en'] : $all[$id]['ar'];
}

function devices_role_label($id, $useLang = null)
{
    if ($useLang === null) {
        global $lang;
        $useLang = isset($lang) ? $lang : 'ar';
    }
    $all = devices_roles();
    if (!isset($all[$id])) {
        return (string) $id;
    }
    return ($useLang === 'en') ? $all[$id]['en'] : $all[$id]['ar'];
}

function devices_status_label($id, $useLang = null)
{
    if ($useLang === null) {
        global $lang;
        $useLang = isset($lang) ? $lang : 'ar';
    }
    $all = devices_statuses();
    if (!isset($all[$id])) {
        return (string) $id;
    }
    return ($useLang === 'en') ? $all[$id]['en'] : $all[$id]['ar'];
}

function devices_login_label($val, $useLang = null)
{
    if ($useLang === null) {
        global $lang;
        $useLang = isset($lang) ? $lang : 'ar';
    }
    if ($val === null || $val === '') {
        return $useLang === 'en' ? 'Not checked' : 'ما انفحص';
    }
    if ((int) $val === 1) {
        return $useLang === 'en' ? 'Logged in' : 'تم الدخول';
    }
    return $useLang === 'en' ? 'Login failed' : 'فشل الدخول';
}

function devices_poll_flash_extra($row, $meta, $isEn)
{
    $parts = array();
    $ping = null;
    if (is_array($meta) && array_key_exists('ping_ms', $meta)) {
        $ping = $meta['ping_ms'];
    } elseif (is_array($row) && array_key_exists('ping_ms', $row)) {
        $ping = $row['ping_ms'];
    }
    if ($ping !== null && $ping !== '') {
        $parts[] = ($isEn ? 'ping ' : 'بنق ') . round((float) $ping, 1) . ' ms';
    } else {
        $parts[] = $isEn ? 'no ping' : 'ماكو بنق';
    }
    $login = null;
    if (is_array($meta) && array_key_exists('login_ok', $meta)) {
        $login = $meta['login_ok'];
    } elseif (is_array($row) && array_key_exists('login_ok', $row)) {
        $login = $row['login_ok'];
    }
    if ($login !== null && $login !== '') {
        $parts[] = ((int) $login === 1)
            ? ($isEn ? 'login OK' : 'دخول ناجح')
            : ($isEn ? 'login failed' : 'فشل الدخول');
    }
    $staN = 0;
    if (is_array($meta) && !empty($meta['stations']) && is_array($meta['stations'])) {
        $staN = count($meta['stations']);
    } elseif (is_array($row) && !empty($row['station_macs'])) {
        $staN = count(devices_normalize_stations(json_decode($row['station_macs'], true)));
    }
    if ($staN > 0) {
        $parts[] = ($isEn ? 'stations ' : 'محطات ') . $staN;
    }
    if (!$parts) {
        return '';
    }
    return ' · ' . implode(' · ', $parts);
}

function ensure_network_devices_schema($pdo)
{
    if (!$pdo) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS network_devices (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                agent_user_id INT UNSIGNED NULL DEFAULT NULL,
                name VARCHAR(160) NOT NULL DEFAULT "",
                device_type VARCHAR(40) NOT NULL DEFAULT "nanostation",
                device_role VARCHAR(20) NOT NULL DEFAULT "ap",
                mgmt_ip VARCHAR(45) NOT NULL DEFAULT "",
                username VARCHAR(120) NOT NULL DEFAULT "",
                password VARCHAR(255) NOT NULL DEFAULT "",
                mac VARCHAR(32) NULL DEFAULT NULL,
                notes VARCHAR(255) NOT NULL DEFAULT "",
                status VARCHAR(20) NOT NULL DEFAULT "unknown",
                last_seen_at DATETIME NULL DEFAULT NULL,
                last_error VARCHAR(255) NULL DEFAULT NULL,
                signal_dbm DECIMAL(8,2) NULL DEFAULT NULL,
                noise_dbm DECIMAL(8,2) NULL DEFAULT NULL,
                load_pct DECIMAL(8,2) NULL DEFAULT NULL,
                tx_rate VARCHAR(40) NULL DEFAULT NULL,
                rx_rate VARCHAR(40) NULL DEFAULT NULL,
                freq_mhz DECIMAL(10,2) NULL DEFAULT NULL,
                best_freq_mhz DECIMAL(10,2) NULL DEFAULT NULL,
                station_macs TEXT NULL,
                polled_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL DEFAULT NULL,
                INDEX idx_nd_tenant (tenant_id),
                INDEX idx_nd_agent (agent_user_id),
                INDEX idx_nd_mac (mac),
                INDEX idx_nd_status (status),
                INDEX idx_nd_ip (mgmt_ip)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Exception $e) {
    }
    devices_add_column($pdo, 'network_devices', 'ping_ms', 'ALTER TABLE network_devices ADD COLUMN ping_ms DECIMAL(10,1) NULL DEFAULT NULL AFTER status');
    devices_add_column($pdo, 'network_devices', 'login_ok', 'ALTER TABLE network_devices ADD COLUMN login_ok TINYINT(1) NULL DEFAULT NULL AFTER ping_ms');
}

function devices_add_column($pdo, $table, $column, $ddl)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $column);
    if ($table === '' || $column === '' || !$pdo) {
        return;
    }
    try {
        $col = $pdo->query('SHOW COLUMNS FROM ' . $table . ' LIKE ' . $pdo->quote($column))->fetch();
        if (!$col) {
            $pdo->exec($ddl);
        }
    } catch (Exception $e) {
    }
}

function devices_normalize_mac($mac)
{
    $mac = strtoupper(trim((string) $mac));
    $mac = preg_replace('/[^0-9A-F]/', '', $mac);
    if (strlen($mac) !== 12) {
        return '';
    }
    return implode(':', str_split($mac, 2));
}

function devices_mac_fold($mac)
{
    return strtolower(preg_replace('/[^0-9a-f]/i', '', (string) $mac));
}

function devices_agent_scope_sql($alias = 'd')
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
    if ($a === '') {
        $a = 'd';
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    $sql = ' AND ' . $a . '.tenant_id = ' . $tid;
    if (function_exists('is_super_admin_user') && is_super_admin_user()
        && !(function_exists('is_impersonating') && is_impersonating())) {
        return '';
    }
    if (function_exists('is_agent_user') && is_agent_user() && function_exists('current_admin')) {
        $me = current_admin();
        $id = $me ? (int) $me['id'] : 0;
        if ($id > 0) {
            $sql .= ' AND ' . $a . '.agent_user_id = ' . $id;
        }
    } elseif (function_exists('is_group_manager_user') && is_group_manager_user() && function_exists('group_manager_team_ids') && isset($GLOBALS['pdo'])) {
        $ids = group_manager_team_ids($GLOBALS['pdo']);
        if ($ids) {
            $sql .= ' AND (' . $a . '.agent_user_id IN (' . implode(',', array_map('intval', $ids)) . ') OR ' . $a . '.agent_user_id IS NULL)';
        }
    }
    return $sql;
}

function devices_can_access($pdo, $row)
{
    if (!$row || !is_array($row)) {
        return false;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ((int) $row['tenant_id'] !== $tid
        && !(function_exists('is_super_admin_user') && is_super_admin_user() && !(function_exists('is_impersonating') && is_impersonating()))) {
        return false;
    }
    if (function_exists('is_agent_user') && is_agent_user() && function_exists('current_admin')) {
        $me = current_admin();
        return $me && (int) $row['agent_user_id'] === (int) $me['id'];
    }
    if (function_exists('is_group_manager_user') && is_group_manager_user() && function_exists('group_manager_team_ids')) {
        $ids = group_manager_team_ids($pdo);
        $aid = isset($row['agent_user_id']) ? (int) $row['agent_user_id'] : 0;
        return $aid <= 0 || in_array($aid, $ids, true);
    }
    return true;
}

function devices_get($pdo, $id)
{
    ensure_network_devices_schema($pdo);
    $id = (int) $id;
    if ($id <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM network_devices WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => $id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !devices_can_access($pdo, $row)) {
        return null;
    }
    return $row;
}

function devices_list($pdo, $filters = array())
{
    ensure_network_devices_schema($pdo);
    $where = '1=1' . devices_agent_scope_sql('d');
    $params = array();
    $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
    if ($q !== '') {
        $where .= ' AND (d.name LIKE :q OR d.mgmt_ip LIKE :q OR d.mac LIKE :q OR d.notes LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if (!empty($filters['type'])) {
        $where .= ' AND d.device_type = :type';
        $params[':type'] = (string) $filters['type'];
    }
    if (!empty($filters['role'])) {
        $where .= ' AND d.device_role = :role';
        $params[':role'] = (string) $filters['role'];
    }
    if (!empty($filters['status'])) {
        $where .= ' AND d.status = :status';
        $params[':status'] = (string) $filters['status'];
    }
    if (isset($filters['agent']) && $filters['agent'] !== '' && $filters['agent'] !== null) {
        $where .= ' AND d.agent_user_id = :agent';
        $params[':agent'] = (int) $filters['agent'];
    }
    $sql = 'SELECT d.*, a.username AS agent_username, a.display_name AS agent_display
            FROM network_devices d
            LEFT JOIN admin_users a ON a.id = d.agent_user_id
            WHERE ' . $where . '
            ORDER BY d.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}

function devices_count_by_status($pdo)
{
    ensure_network_devices_schema($pdo);
    $out = array('ok' => 0, 'full_load' => 0, 'timeout' => 0, 'offline' => 0, 'unknown' => 0, 'total' => 0);
    $sql = 'SELECT status, COUNT(*) AS c FROM network_devices d WHERE 1=1' . devices_agent_scope_sql('d') . ' GROUP BY status';
    try {
        foreach ($pdo->query($sql) as $r) {
            $s = isset($r['status']) ? (string) $r['status'] : 'unknown';
            $c = (int) $r['c'];
            if (!isset($out[$s])) {
                $out[$s] = 0;
            }
            $out[$s] += $c;
            $out['total'] += $c;
        }
    } catch (Exception $e) {
    }
    return $out;
}

function devices_validate_fields($fields)
{
    $types = devices_types();
    $roles = devices_roles();
    $type = isset($fields['device_type']) ? (string) $fields['device_type'] : '';
    $role = isset($fields['device_role']) ? (string) $fields['device_role'] : '';
    $ip = isset($fields['mgmt_ip']) ? trim((string) $fields['mgmt_ip']) : '';
    $name = isset($fields['name']) ? trim((string) $fields['name']) : '';
    if ($name === '') {
        return array(false, 'name');
    }
    if (!isset($types[$type])) {
        return array(false, 'type');
    }
    if (!isset($roles[$role])) {
        return array(false, 'role');
    }
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return array(false, 'ip');
    }
    return array(true, '');
}

function devices_save($pdo, $id, $fields)
{
    ensure_network_devices_schema($pdo);
    list($ok, $err) = devices_validate_fields($fields);
    if (!$ok) {
        return array(false, $err, 0);
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $mac = devices_normalize_mac(isset($fields['mac']) ? $fields['mac'] : '');
    $agentId = isset($fields['agent_user_id']) ? (int) $fields['agent_user_id'] : 0;
    if ($agentId <= 0 && function_exists('is_agent_user') && is_agent_user() && function_exists('current_admin')) {
        $me = current_admin();
        $agentId = $me ? (int) $me['id'] : 0;
    }
    if ($agentId <= 0) {
        $agentId = null;
    }
    $now = date('Y-m-d H:i:s');
    $params = array(
        ':name' => trim((string) $fields['name']),
        ':device_type' => (string) $fields['device_type'],
        ':device_role' => (string) $fields['device_role'],
        ':mgmt_ip' => trim((string) $fields['mgmt_ip']),
        ':username' => ((string) $fields['device_type'] === 'mimosa')
            ? ''
            : (isset($fields['username']) ? trim((string) $fields['username']) : ''),
        ':notes' => isset($fields['notes']) ? trim((string) $fields['notes']) : '',
        ':agent_user_id' => $agentId,
    );
    $id = (int) $id;
    if ($id > 0) {
        $cur = devices_get($pdo, $id);
        if (!$cur) {
            return array(false, 'missing', 0);
        }
        $pass = isset($fields['password']) ? (string) $fields['password'] : '';
        if ($pass === '') {
            $pass = isset($cur['password']) ? (string) $cur['password'] : '';
        }
        $params[':password'] = $pass;
        $params[':id'] = $id;
        $params[':updated_at'] = $now;
        // الماك ما يُطلب يدوياً — يبقى من فحص المرسلة (station list)
        $pdo->prepare(
            'UPDATE network_devices SET
                name = :name, device_type = :device_type, device_role = :device_role,
                mgmt_ip = :mgmt_ip, username = :username, password = :password,
                notes = :notes, agent_user_id = :agent_user_id,
                updated_at = :updated_at
             WHERE id = :id'
        )->execute($params);
        return array(true, '', $id);
    }
    $params[':mac'] = $mac !== '' ? $mac : null;
    $params[':password'] = isset($fields['password']) ? (string) $fields['password'] : '';
    $params[':tenant_id'] = $tid;
    $params[':created_at'] = $now;
    $pdo->prepare(
        'INSERT INTO network_devices
            (tenant_id, agent_user_id, name, device_type, device_role, mgmt_ip, username, password, mac, notes, status, created_at)
         VALUES
            (:tenant_id, :agent_user_id, :name, :device_type, :device_role, :mgmt_ip, :username, :password, :mac, :notes, "unknown", :created_at)'
    )->execute($params);
    return array(true, '', (int) $pdo->lastInsertId());
}

function devices_delete($pdo, $id)
{
    $row = devices_get($pdo, $id);
    if (!$row) {
        return false;
    }
    $pdo->prepare('DELETE FROM network_devices WHERE id = :id')->execute(array(':id' => (int) $id));
    return true;
}

function devices_transfer($pdo, $id, $toAgentId)
{
    $row = devices_get($pdo, $id);
    if (!$row) {
        return false;
    }
    $toAgentId = (int) $toAgentId;
    if ($toAgentId <= 0) {
        return false;
    }
    $tid = (int) $row['tenant_id'];
    $chk = $pdo->prepare('SELECT id FROM admin_users WHERE id = :id AND tenant_id = :t LIMIT 1');
    $chk->execute(array(':id' => $toAgentId, ':t' => $tid));
    if (!$chk->fetchColumn()) {
        return false;
    }
    $pdo->prepare(
        'UPDATE network_devices SET agent_user_id = :a, updated_at = :u WHERE id = :id'
    )->execute(array(
        ':a' => $toAgentId,
        ':u' => date('Y-m-d H:i:s'),
        ':id' => (int) $id,
    ));
    return true;
}

function devices_agents_for_select($pdo)
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $rows = array();
    try {
        $st = $pdo->prepare(
            'SELECT id, username, display_name, role FROM admin_users
             WHERE tenant_id = :t AND is_active = 1
             ORDER BY display_name, username'
        );
        $st->execute(array(':t' => $tid));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return array();
    }
    if (function_exists('is_agent_user') && is_agent_user() && function_exists('current_admin')) {
        $me = current_admin();
        $mid = $me ? (int) $me['id'] : 0;
        $out = array();
        foreach ($rows as $r) {
            if ((int) $r['id'] === $mid) {
                $out[] = $r;
            }
        }
        return $out;
    }
    if (function_exists('is_group_manager_user') && is_group_manager_user() && function_exists('group_manager_team_ids')) {
        $ids = group_manager_team_ids($pdo);
        $out = array();
        foreach ($rows as $r) {
            if (in_array((int) $r['id'], $ids, true)) {
                $out[] = $r;
            }
        }
        return $out;
    }
    return is_array($rows) ? $rows : array();
}

function devices_export_csv($pdo, $filters = array())
{
    $rows = devices_list($pdo, $filters);
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, array(
        'name', 'device_type', 'device_role', 'mgmt_ip', 'username', 'password', 'mac', 'notes', 'agent_user_id', 'status',
        'signal_dbm', 'noise_dbm', 'load_pct', 'tx_rate', 'rx_rate', 'freq_mhz', 'best_freq_mhz',
    ));
    foreach ($rows as $r) {
        fputcsv($fh, array(
            $r['name'], $r['device_type'], $r['device_role'], $r['mgmt_ip'], $r['username'], $r['password'],
            $r['mac'], $r['notes'], $r['agent_user_id'], $r['status'],
            $r['signal_dbm'], $r['noise_dbm'], $r['load_pct'], $r['tx_rate'], $r['rx_rate'], $r['freq_mhz'], $r['best_freq_mhz'],
        ));
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

function devices_import_csv($pdo, $csvText)
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $csvText);
    if (!$lines) {
        return array(0, 0, 'empty');
    }
    $header = null;
    $ok = 0;
    $fail = 0;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $cols = str_getcsv($line);
        if ($header === null) {
            $header = array();
            foreach ($cols as $i => $h) {
                $header[strtolower(trim((string) $h))] = $i;
            }
            if (!isset($header['name']) || !isset($header['mgmt_ip'])) {
                return array(0, 0, 'header');
            }
            continue;
        }
        $get = function ($key) use ($cols, $header) {
            if (!isset($header[$key])) {
                return '';
            }
            $i = (int) $header[$key];
            return isset($cols[$i]) ? trim((string) $cols[$i]) : '';
        };
        $fields = array(
            'name' => $get('name'),
            'device_type' => $get('device_type') !== '' ? $get('device_type') : 'nanostation',
            'device_role' => $get('device_role') !== '' ? $get('device_role') : 'ap',
            'mgmt_ip' => $get('mgmt_ip'),
            'username' => $get('username'),
            'password' => $get('password'),
            'mac' => $get('mac'),
            'notes' => $get('notes'),
            'agent_user_id' => $get('agent_user_id') !== '' ? (int) $get('agent_user_id') : 0,
        );
        list($saved) = devices_save($pdo, 0, $fields);
        if ($saved) {
            $ok++;
        } else {
            $fail++;
        }
    }
    return array($ok, $fail, '');
}

function devices_http_request($url, $opts = array())
{
    $timeout = isset($opts['timeout']) ? (float) $opts['timeout'] : 4;
    $method = isset($opts['method']) ? strtoupper((string) $opts['method']) : 'GET';
    $body = isset($opts['body']) ? $opts['body'] : null;
    $headers = isset($opts['headers']) && is_array($opts['headers']) ? $opts['headers'] : array();
    $user = isset($opts['user']) ? (string) $opts['user'] : '';
    $pass = isset($opts['pass']) ? (string) $opts['pass'] : '';
    $cookie = isset($opts['cookie']) ? (string) $opts['cookie'] : '';
    $useAuth = !empty($opts['auth']) || $user !== '' || (isset($opts['pass_only']) && $opts['pass_only'] && $pass !== '');
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'code' => 0, 'body' => '', 'error' => 'curl', 'cookie' => '', 'ms' => null);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(1, (int) $timeout));
    curl_setopt($ch, CURLOPT_TIMEOUT, max(2, (int) ceil($timeout)));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ISP-Portal-Devices/1.0');
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
    if ($useAuth) {
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    }
    if ($cookie !== '') {
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $ms = round((microtime(true) - $t0) * 1000, 1);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    $hdr = '';
    $resp = '';
    if (is_string($raw)) {
        $hdr = substr($raw, 0, $headerSize);
        $resp = substr($raw, $headerSize);
    }
    $cookieOut = '';
    if ($hdr !== '' && preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)/mi', $hdr, $mm)) {
        $cookieOut = implode('; ', $mm[1]);
    }
    return array(
        'ok' => ($raw !== false && $code >= 200 && $code < 400),
        'code' => $code,
        'body' => is_string($resp) ? $resp : '',
        'error' => $err,
        'cookie' => $cookieOut,
        'ms' => $ms,
    );
}

/**
 * بنق من البوابة إلى آيبي الجهاز — يرجع ملي ثانية أو null.
 */
function devices_ping_ms($ip)
{
    $ip = trim((string) $ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    if (function_exists('system_icmp_ping_ms')) {
        $p = system_icmp_ping_ms($ip, 2);
        if (is_array($p) && !empty($p['ok']) && isset($p['ms']) && $p['ms'] !== null) {
            return round((float) $p['ms'], 1);
        }
    }
    if (function_exists('sas_icmp_many')) {
        $map = sas_icmp_many(array($ip));
        if (isset($map[$ip]) && $map[$ip] !== null && $map[$ip] !== false && $map[$ip] !== '') {
            return round((float) $map[$ip], 1);
        }
    }
    $ports = array(443, 80, 22);
    foreach ($ports as $port) {
        $errno = 0;
        $errstr = '';
        $t0 = microtime(true);
        $fp = @fsockopen($ip, $port, $errno, $errstr, 1.5);
        if (is_resource($fp)) {
            fclose($fp);
            return round((microtime(true) - $t0) * 1000, 1);
        }
    }
    return null;
}

function devices_ping_status($ip)
{
    $ms = devices_ping_ms($ip);
    if ($ms === null) {
        return 'offline';
    }
    if ((float) $ms > 900) {
        return 'timeout';
    }
    return 'ok';
}

function devices_poll_ubiquiti($device)
{
    $ip = trim((string) $device['mgmt_ip']);
    $user = trim((string) $device['username']);
    $pass = (string) $device['password'];
    if ($user === '') {
        $user = 'ubnt';
    }
    $https = true;
    $base = ($https ? 'https' : 'http') . '://' . $ip;
    $auth = devices_http_request($base . '/api/auth', array(
        'method' => 'POST',
        'body' => http_build_query(array('username' => $user, 'password' => $pass)),
        'headers' => array('Content-Type: application/x-www-form-urlencoded'),
        'timeout' => 5,
    ));
    $cookie = !empty($auth['cookie']) ? (string) $auth['cookie'] : '';
    // airOS status JSON
    $st = devices_http_request($base . '/status.cgi', array(
        'user' => $user,
        'pass' => $pass,
        'timeout' => 5,
        'cookie' => $cookie,
    ));
    if (empty($st['ok']) || $st['body'] === '') {
        $st = devices_http_request($base . '/api/status', array(
            'user' => $user,
            'pass' => $pass,
            'timeout' => 5,
        ));
    }
    if (empty($st['ok']) || $st['body'] === '') {
        return array('ok' => false, 'login_ok' => 0, 'error' => 'auth_or_status');
    }
    $j = json_decode($st['body'], true);
    if (!is_array($j)) {
        return array('ok' => false, 'login_ok' => 1, 'error' => 'bad_json');
    }
    $signal = null;
    $noise = null;
    $tx = null;
    $rx = null;
    $freq = null;
    $load = null;
    $stations = array();
    if (isset($j['wireless']['signal'])) {
        $signal = (float) $j['wireless']['signal'];
    } elseif (isset($j['signal'])) {
        $signal = (float) $j['signal'];
    }
    if (isset($j['wireless']['noisefloor'])) {
        $noise = (float) $j['wireless']['noisefloor'];
    } elseif (isset($j['noisefloor'])) {
        $noise = (float) $j['noisefloor'];
    }
    if (isset($j['wireless']['txrate'])) {
        $tx = (string) $j['wireless']['txrate'];
    }
    if (isset($j['wireless']['rxrate'])) {
        $rx = (string) $j['wireless']['rxrate'];
    }
    if (isset($j['wireless']['frequency'])) {
        $freq = (float) $j['wireless']['frequency'];
    } elseif (isset($j['frequency'])) {
        $freq = (float) $j['frequency'];
    }
    if (isset($j['host']['uptime']) && isset($j['wireless']['count'])) {
        $load = min(100, (float) $j['wireless']['count'] * 5);
    }
    if (!empty($j['wireless']['sta']) && is_array($j['wireless']['sta'])) {
        foreach ($j['wireless']['sta'] as $sta) {
            if (empty($sta['mac'])) {
                continue;
            }
            $stations[] = array(
                'mac' => devices_normalize_mac($sta['mac']),
                'signal' => isset($sta['signal']) && is_numeric($sta['signal']) ? (float) $sta['signal'] : null,
                'name' => isset($sta['remote']['hostname']) ? trim((string) $sta['remote']['hostname']) : (isset($sta['name']) ? trim((string) $sta['name']) : ''),
            );
        }
    }
    return array(
        'ok' => true,
        'login_ok' => 1,
        'signal_dbm' => $signal,
        'noise_dbm' => $noise,
        'load_pct' => $load,
        'tx_rate' => $tx,
        'rx_rate' => $rx,
        'freq_mhz' => $freq,
        'best_freq_mhz' => $freq,
        'stations' => $stations,
        'station_macs' => array_map(function ($s) {
            return is_array($s) ? (isset($s['mac']) ? $s['mac'] : '') : (string) $s;
        }, $stations),
    );
}

/**
 * تطبيع قائمة المحطات إلى [{mac,signal,name},…] سواء أتت كسلاسل أو كائنات.
 */
function devices_normalize_stations($list)
{
    $out = array();
    $seen = array();
    if (!is_array($list)) {
        return $out;
    }
    foreach ($list as $item) {
        $mac = '';
        $sig = null;
        $name = '';
        if (is_array($item)) {
            $mac = devices_normalize_mac(isset($item['mac']) ? (string) $item['mac'] : '');
            if (isset($item['signal']) && is_numeric($item['signal'])) {
                $sig = (float) $item['signal'];
            }
            $name = isset($item['name']) ? trim((string) $item['name']) : '';
        } else {
            $mac = devices_normalize_mac((string) $item);
        }
        $fold = devices_mac_fold($mac);
        if ($fold === '' || isset($seen[$fold])) {
            continue;
        }
        $seen[$fold] = true;
        $out[] = array('mac' => $mac, 'signal' => $sig, 'name' => $name);
    }
    return $out;
}

function devices_station_mac_list($deviceOrJson)
{
    $list = array();
    if (is_array($deviceOrJson) && isset($deviceOrJson['station_macs'])) {
        $decoded = $deviceOrJson['station_macs'];
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        $list = is_array($decoded) ? $decoded : array();
    } elseif (is_string($deviceOrJson)) {
        $decoded = json_decode($deviceOrJson, true);
        $list = is_array($decoded) ? $decoded : array();
    } elseif (is_array($deviceOrJson)) {
        $list = $deviceOrJson;
    }
    $macs = array();
    foreach (devices_normalize_stations($list) as $s) {
        if (!empty($s['mac'])) {
            $macs[] = $s['mac'];
        }
    }
    return $macs;
}

function devices_mimosa_parse_phy($getPhy)
{
    $out = array(
        'signal_dbm' => null,
        'noise_dbm' => null,
        'tx_rate' => null,
        'rx_rate' => null,
    );
    if (!is_string($getPhy) || $getPhy === '') {
        return $out;
    }
    $parts = array();
    parse_str($getPhy, $parts);
    if (isset($parts['rssi_average']) && is_numeric($parts['rssi_average'])) {
        $out['signal_dbm'] = round((float) $parts['rssi_average'], 1);
    }
    if (isset($parts['noise']) && is_numeric($parts['noise'])) {
        $out['noise_dbm'] = round((float) $parts['noise'], 1);
    }
    if (isset($parts['tx_phy_rate']) && $parts['tx_phy_rate'] !== '') {
        $out['tx_rate'] = (string) $parts['tx_phy_rate'];
    }
    if (isset($parts['rx_phy_rate']) && $parts['rx_phy_rate'] !== '') {
        $out['rx_rate'] = (string) $parts['rx_phy_rate'];
    }
    return $out;
}

function devices_poll_mimosa($device)
{
    $ip = trim((string) $device['mgmt_ip']);
    $pass = (string) $device['password'];
    if ($pass === '') {
        return array('ok' => false, 'login_ok' => 0, 'error' => 'no_password');
    }

    $bases = array('http://' . $ip, 'https://' . $ip);
    foreach ($bases as $base) {
        $seed = devices_http_request($base . '/', array('timeout' => 4));
        if ((int) $seed['code'] === 0 && $seed['body'] === '') {
            continue;
        }
        $cookie = !empty($seed['cookie']) ? (string) $seed['cookie'] : '';

        $login = devices_http_request($base . '/login.php', array(
            'method' => 'POST',
            'body' => http_build_query(array(
                'username' => 'configure',
                'password' => $pass,
            )),
            'headers' => array(
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Referer: ' . $base . '/',
                'Origin: ' . $base,
            ),
            'cookie' => $cookie,
            'timeout' => 6,
        ));
        if (!empty($login['cookie'])) {
            $map = array();
            foreach (explode('; ', $cookie . '; ' . $login['cookie']) as $part) {
                $part = trim($part);
                if ($part === '' || strpos($part, '=') === false) {
                    continue;
                }
                list($k, $v) = explode('=', $part, 2);
                $map[$k] = $v;
            }
            $parts = array();
            foreach ($map as $k => $v) {
                $parts[] = $k . '=' . $v;
            }
            $cookie = implode('; ', $parts);
        }

        $loginJson = json_decode(isset($login['body']) ? $login['body'] : '', true);
        $role = (is_array($loginJson) && isset($loginJson['role'])) ? (int) $loginJson['role'] : 0;
        if ($role <= 0) {
            if ((int) $login['code'] > 0) {
                return array('ok' => false, 'login_ok' => 0, 'error' => 'login_failed');
            }
            continue;
        }

        $dash = devices_http_request($base . '/cgi/dashboard.php', array(
            'cookie' => $cookie,
            'headers' => array(
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Referer: ' . $base . '/',
            ),
            'timeout' => 8,
        ));
        if ((int) $dash['code'] === 401 || trim((string) $dash['body']) === '{"role":0}') {
            return array('ok' => false, 'login_ok' => 0, 'error' => 'session_expired');
        }
        $j = json_decode(isset($dash['body']) ? $dash['body'] : '', true);
        if (!is_array($j) || empty($j['realtime']) || !is_array($j['realtime'])) {
            return array(
                'ok' => true,
                'login_ok' => 1,
                'signal_dbm' => null,
                'noise_dbm' => null,
                'load_pct' => null,
                'tx_rate' => null,
                'rx_rate' => null,
                'freq_mhz' => null,
                'best_freq_mhz' => null,
                'stations' => array(),
                'device_mac' => null,
                'error' => 'dashboard_empty',
            );
        }

        $rt = $j['realtime'];
        $sm = isset($rt['signalmeter']) && is_array($rt['signalmeter']) ? $rt['signalmeter'] : array();
        $conn = isset($rt['connection']) && is_array($rt['connection']) ? $rt['connection'] : array();
        $dev = isset($rt['device_info']) && is_array($rt['device_info']) ? $rt['device_info'] : array();
        $remote = isset($rt['remote_info']) && is_array($rt['remote_info']) ? $rt['remote_info'] : array();

        $phy = devices_mimosa_parse_phy(isset($sm['get_phy']) ? (string) $sm['get_phy'] : '');
        $signal = $phy['signal_dbm'];
        $noise = $phy['noise_dbm'];
        if ($noise === null && isset($sm['noise']) && is_numeric($sm['noise'])) {
            $noise = round((float) $sm['noise'], 1);
        }
        $tx = $phy['tx_rate'];
        $rx = $phy['rx_rate'];

        $freq = null;
        foreach (array('Chains_1_2', 'Chains_3_4', 'Ext_lo_freq') as $fk) {
            if (empty($sm[$fk])) {
                continue;
            }
            if (preg_match('/(\d+(?:\.\d+)?)\s*MHz/i', (string) $sm[$fk], $mm)) {
                $freq = (float) $mm[1];
                break;
            }
            if (is_numeric($sm[$fk])) {
                $freq = (float) $sm[$fk];
                break;
            }
        }

        $deviceMac = '';
        if (!empty($dev['MacEther'])) {
            $deviceMac = devices_normalize_mac((string) $dev['MacEther']);
        } elseif (!empty($dev['Mac5_0'])) {
            $deviceMac = devices_normalize_mac((string) $dev['Mac5_0']);
        }

        $stations = array();
        $remoteMac = '';
        if (!empty($remote['remote_mac'])) {
            $remoteMac = devices_normalize_mac((string) $remote['remote_mac']);
        } elseif (!empty($remote['Mac5_0'])) {
            $remoteMac = devices_normalize_mac((string) $remote['Mac5_0']);
        } elseif (!empty($remote['MacEther'])) {
            $remoteMac = devices_normalize_mac((string) $remote['MacEther']);
        }
        if ($remoteMac !== '') {
            $rName = '';
            if (!empty($remote['DeviceName'])) {
                $rName = trim((string) $remote['DeviceName']);
            } elseif (!empty($remote['friendlyname'])) {
                $rName = trim((string) $remote['friendlyname']);
            }
            if ($rName === '' && !empty($conn['linkName'])) {
                $rName = trim((string) $conn['linkName']);
            }
            $stations[] = array(
                'mac' => $remoteMac,
                'signal' => $signal,
                'name' => $rName,
            );
        }

        $load = null;
        if (!empty($conn['connected']) && (string) $conn['connected'] === '1') {
            $load = $stations ? 8.0 : 0.0;
        }
        if (isset($conn['availability']) && is_numeric($conn['availability'])) {
            // availability عالية = لنك مستقر؛ مو حمل
        }

        $bw = isset($sm['BandWidth']) ? (string) $sm['BandWidth'] : '';
        $txPower = null;
        if (isset($sm['ActualTotalTxPower']) && is_numeric($sm['ActualTotalTxPower'])) {
            $txPower = (float) $sm['ActualTotalTxPower'];
        } elseif (isset($sm['ChannelPower']) && is_numeric($sm['ChannelPower'])) {
            $txPower = (float) $sm['ChannelPower'];
        }

        return array(
            'ok' => true,
            'login_ok' => 1,
            'signal_dbm' => $signal,
            'noise_dbm' => $noise,
            'load_pct' => $load,
            'tx_rate' => $tx,
            'rx_rate' => $rx,
            'freq_mhz' => $freq,
            'best_freq_mhz' => $freq,
            'stations' => $stations,
            'device_mac' => $deviceMac !== '' ? $deviceMac : null,
            'live' => array(
                'bandwidth' => $bw,
                'ssid' => isset($conn['SSID']) ? (string) $conn['SSID'] : '',
                'link_name' => isset($conn['linkName']) ? (string) $conn['linkName'] : '',
                'connected' => !empty($conn['connected']) && (string) $conn['connected'] === '1',
                'distance_m' => isset($conn['distance']) && is_numeric($conn['distance']) ? (float) $conn['distance'] : null,
                'availability' => isset($conn['availability']) && is_numeric($conn['availability']) ? (float) $conn['availability'] : null,
                'uptime_sec' => isset($conn['ConnectingUptime']) && is_numeric($conn['ConnectingUptime']) ? (int) $conn['ConnectingUptime'] : null,
                'device_mode' => isset($dev['DeviceMode']) ? (string) $dev['DeviceMode'] : '',
                'device_name' => isset($dev['DeviceName']) ? (string) $dev['DeviceName'] : '',
                'model' => isset($dev['Model']) ? (string) $dev['Model'] : '',
                'version' => isset($dev['Version']) ? (string) $dev['Version'] : '',
                'temp_c' => isset($dev['Temperature']) && is_numeric($dev['Temperature']) ? (float) $dev['Temperature'] : null,
                'tx_power' => $txPower,
                'remote_name' => !empty($remote['DeviceName']) ? (string) $remote['DeviceName'] : '',
                'remote_ip' => !empty($remote['IPAddress']) ? (string) $remote['IPAddress'] : '',
                'remote_mac' => $remoteMac,
            ),
            'error' => '',
        );
    }

    return array('ok' => false, 'login_ok' => 0, 'error' => 'mimosa_unreachable');
}

function devices_row_public($row)
{
    if (!is_array($row)) {
        return null;
    }
    $stations = array();
    if (!empty($row['station_macs'])) {
        $stations = devices_normalize_stations(json_decode($row['station_macs'], true));
    }
    return array(
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'device_type' => (string) $row['device_type'],
        'device_role' => (string) $row['device_role'],
        'mgmt_ip' => (string) $row['mgmt_ip'],
        'mac' => isset($row['mac']) ? (string) $row['mac'] : '',
        'status' => isset($row['status']) ? (string) $row['status'] : 'unknown',
        'ping_ms' => isset($row['ping_ms']) && $row['ping_ms'] !== null && $row['ping_ms'] !== '' ? (float) $row['ping_ms'] : null,
        'login_ok' => array_key_exists('login_ok', $row) && $row['login_ok'] !== null && $row['login_ok'] !== '' ? (int) $row['login_ok'] : null,
        'signal_dbm' => isset($row['signal_dbm']) && $row['signal_dbm'] !== null && $row['signal_dbm'] !== '' ? (float) $row['signal_dbm'] : null,
        'noise_dbm' => isset($row['noise_dbm']) && $row['noise_dbm'] !== null && $row['noise_dbm'] !== '' ? (float) $row['noise_dbm'] : null,
        'load_pct' => isset($row['load_pct']) && $row['load_pct'] !== null && $row['load_pct'] !== '' ? (float) $row['load_pct'] : null,
        'tx_rate' => isset($row['tx_rate']) ? (string) $row['tx_rate'] : '',
        'rx_rate' => isset($row['rx_rate']) ? (string) $row['rx_rate'] : '',
        'freq_mhz' => isset($row['freq_mhz']) && $row['freq_mhz'] !== null && $row['freq_mhz'] !== '' ? (float) $row['freq_mhz'] : null,
        'best_freq_mhz' => isset($row['best_freq_mhz']) && $row['best_freq_mhz'] !== null && $row['best_freq_mhz'] !== '' ? (float) $row['best_freq_mhz'] : null,
        'polled_at' => isset($row['polled_at']) ? (string) $row['polled_at'] : '',
        'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : '',
        'stations' => $stations,
        'agent_display' => isset($row['agent_display']) ? (string) $row['agent_display'] : (isset($row['agent_username']) ? (string) $row['agent_username'] : ''),
    );
}

function devices_poll_mikrotik($device)
{
    $ip = trim((string) $device['mgmt_ip']);
    $user = trim((string) $device['username']);
    $pass = (string) $device['password'];
    if ($user === '') {
        $user = 'admin';
    }
    $urls = array(
        'https://' . $ip . '/rest/interface/wireless',
        'http://' . $ip . '/rest/interface/wireless',
        'https://' . $ip . '/rest/interface/wifiwave2',
        'http://' . $ip . '/rest/interface/wifiwave2',
    );
    foreach ($urls as $url) {
        $r = devices_http_request($url, array(
            'user' => $user,
            'pass' => $pass,
            'timeout' => 5,
            'headers' => array('Accept: application/json'),
        ));
        if (empty($r['ok']) || $r['body'] === '') {
            continue;
        }
        $j = json_decode($r['body'], true);
        if (!is_array($j)) {
            continue;
        }
        $row = isset($j[0]) && is_array($j[0]) ? $j[0] : $j;
        $freq = isset($row['frequency']) ? (float) $row['frequency'] : null;
        $tx = isset($row['tx-power']) ? (string) $row['tx-power'] : null;
        return array(
            'ok' => true,
            'login_ok' => 1,
            'signal_dbm' => null,
            'noise_dbm' => null,
            'load_pct' => null,
            'tx_rate' => $tx,
            'rx_rate' => null,
            'freq_mhz' => $freq,
            'best_freq_mhz' => $freq,
            'stations' => array(),
            'station_macs' => array(),
        );
    }
    return array('ok' => false, 'login_ok' => 0, 'error' => 'mikrotik_unreachable');
}

function devices_suggest_best_freq($poll)
{
    if (!is_array($poll)) {
        return null;
    }
    if (isset($poll['best_freq_mhz']) && $poll['best_freq_mhz'] !== null && $poll['best_freq_mhz'] !== '') {
        return (float) $poll['best_freq_mhz'];
    }
    if (isset($poll['freq_mhz']) && $poll['freq_mhz'] !== null && $poll['freq_mhz'] !== '') {
        // اقتراح بسيط: إن كانت الضوضاء سيئة انقل ±20 ميجا
        $freq = (float) $poll['freq_mhz'];
        $noise = isset($poll['noise_dbm']) ? (float) $poll['noise_dbm'] : null;
        if ($noise !== null && $noise > -80) {
            return $freq + 20;
        }
        return $freq;
    }
    return null;
}

function devices_poll($pdo, $device)
{
    if (!is_array($device) || empty($device['id'])) {
        return array(false, 'missing');
    }
    $ip = trim((string) $device['mgmt_ip']);
    $pingMs = devices_ping_ms($ip);
    $ping = ($pingMs === null) ? 'offline' : (((float) $pingMs > 900) ? 'timeout' : 'ok');
    $now = date('Y-m-d H:i:s');
    $type = isset($device['device_type']) ? (string) $device['device_type'] : '';
    $poll = array('ok' => false, 'login_ok' => null);
    if ($ping === 'ok' || $ping === 'timeout') {
        if (in_array($type, array('powerbeam', 'litebeam', 'nanostation'), true)) {
            $poll = devices_poll_ubiquiti($device);
        } elseif ($type === 'mimosa') {
            $poll = devices_poll_mimosa($device);
        } elseif ($type === 'mikrotik') {
            $poll = devices_poll_mikrotik($device);
        }
    }
    $status = $ping;
    $err = null;
    $loginOk = null;
    if (array_key_exists('login_ok', $poll) && $poll['login_ok'] !== null) {
        $loginOk = (int) $poll['login_ok'];
    }
    if ($ping === 'offline') {
        $status = 'offline';
        $err = 'unreachable';
        $loginOk = null;
    } elseif ($ping === 'timeout') {
        $status = 'timeout';
        $err = 'ping_timeout';
    } elseif (!empty($poll['ok'])) {
        $status = 'ok';
        if ($loginOk === null) {
            $loginOk = 1;
        }
        if (isset($poll['load_pct']) && $poll['load_pct'] !== null && (float) $poll['load_pct'] >= 85) {
            $status = 'full_load';
        }
    } else {
        $status = ($ping === 'ok') ? 'ok' : $ping;
        if ($loginOk === null && isset($poll['error'])) {
            $loginOk = 0;
        }
        if (empty($poll['ok']) && isset($poll['error'])) {
            $err = (string) $poll['error'];
        }
    }
    $best = devices_suggest_best_freq($poll);
    $stations = array();
    if (!empty($poll['stations']) && is_array($poll['stations'])) {
        $stations = devices_normalize_stations($poll['stations']);
    } elseif (!empty($poll['station_macs']) && is_array($poll['station_macs'])) {
        $stations = devices_normalize_stations($poll['station_macs']);
    }
    $stationJson = $stations ? json_encode($stations) : null;
    $macUpdate = null;
    if (!empty($poll['device_mac'])) {
        $macUpdate = devices_normalize_mac((string) $poll['device_mac']);
    }
    $sql = 'UPDATE network_devices SET
            status = :status,
            ping_ms = :ping,
            login_ok = :login,
            last_seen_at = :seen,
            last_error = :err,
            signal_dbm = :sig,
            noise_dbm = :noise,
            load_pct = :load,
            tx_rate = :tx,
            rx_rate = :rx,
            freq_mhz = :freq,
            best_freq_mhz = :best,
            station_macs = :stas,
            polled_at = :polled,
            updated_at = :upd';
    $params = array(
        ':status' => $status,
        ':ping' => $pingMs,
        ':login' => $loginOk,
        ':seen' => ($status === 'offline') ? (isset($device['last_seen_at']) ? $device['last_seen_at'] : null) : $now,
        ':err' => $err,
        ':sig' => isset($poll['signal_dbm']) ? $poll['signal_dbm'] : null,
        ':noise' => isset($poll['noise_dbm']) ? $poll['noise_dbm'] : null,
        ':load' => isset($poll['load_pct']) ? $poll['load_pct'] : null,
        ':tx' => isset($poll['tx_rate']) ? $poll['tx_rate'] : null,
        ':rx' => isset($poll['rx_rate']) ? $poll['rx_rate'] : null,
        ':freq' => isset($poll['freq_mhz']) ? $poll['freq_mhz'] : null,
        ':best' => $best,
        ':stas' => $stationJson,
        ':polled' => $now,
        ':upd' => $now,
        ':id' => (int) $device['id'],
    );
    if ($macUpdate) {
        $sql .= ', mac = :mac';
        $params[':mac'] = $macUpdate;
    }
    $sql .= ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);
    $meta = array(
        'ping_ms' => $pingMs,
        'login_ok' => $loginOk,
        'stations' => $stations,
    );
    if (!empty($poll['live']) && is_array($poll['live'])) {
        $meta['live'] = $poll['live'];
    }
    return array(true, $status, $meta);
}

function devices_apply_best_freq($pdo, $device)
{
    $device = is_array($device) ? $device : devices_get($pdo, (int) $device);
    if (!$device) {
        return array(false, 'missing');
    }
    $best = isset($device['best_freq_mhz']) ? $device['best_freq_mhz'] : null;
    if ($best === null || $best === '') {
        list($okPoll) = devices_poll($pdo, $device);
        $device = devices_get($pdo, (int) $device['id']);
        $best = isset($device['best_freq_mhz']) ? $device['best_freq_mhz'] : null;
    }
    if ($best === null || $best === '') {
        return array(false, 'no_freq');
    }
    $type = (string) $device['device_type'];
    $ip = trim((string) $device['mgmt_ip']);
    $user = trim((string) $device['username']);
    $pass = (string) $device['password'];
    $applied = false;
    $err = 'unsupported';
    if (in_array($type, array('powerbeam', 'litebeam', 'nanostation'), true)) {
        if ($user === '') {
            $user = 'ubnt';
        }
        $base = 'https://' . $ip;
        $r = devices_http_request($base . '/api/system.channel', array(
            'method' => 'POST',
            'user' => $user,
            'pass' => $pass,
            'body' => http_build_query(array('frequency' => (string) $best)),
            'headers' => array('Content-Type: application/x-www-form-urlencoded'),
            'timeout' => 6,
        ));
        if (empty($r['ok'])) {
            $r = devices_http_request($base . '/status.cgi', array(
                'method' => 'POST',
                'user' => $user,
                'pass' => $pass,
                'body' => http_build_query(array('freq' => (string) $best, 'frequency' => (string) $best)),
                'timeout' => 6,
            ));
        }
        $applied = !empty($r['ok']);
        $err = $applied ? '' : 'ubiquiti_write_failed';
    } elseif ($type === 'mikrotik') {
        if ($user === '') {
            $user = 'admin';
        }
        $r = devices_http_request('https://' . $ip . '/rest/interface/wireless/set', array(
            'method' => 'POST',
            'user' => $user,
            'pass' => $pass,
            'body' => json_encode(array('frequency' => (string) ((int) $best))),
            'headers' => array('Content-Type: application/json', 'Accept: application/json'),
            'timeout' => 6,
        ));
        $applied = !empty($r['ok']);
        $err = $applied ? '' : 'mikrotik_write_failed';
    } elseif ($type === 'mimosa') {
        $r = devices_http_request('https://' . $ip . '/api/v1/radio/frequency', array(
            'method' => 'POST',
            'user' => '',
            'pass' => $pass,
            'body' => json_encode(array('frequency' => (float) $best)),
            'headers' => array('Content-Type: application/json'),
            'timeout' => 6,
        ));
        $applied = !empty($r['ok']);
        $err = $applied ? '' : 'mimosa_write_failed';
    }
    if ($applied) {
        $pdo->prepare(
            'UPDATE network_devices SET freq_mhz = :f, updated_at = :u, last_error = NULL WHERE id = :id'
        )->execute(array(
            ':f' => (float) $best,
            ':u' => date('Y-m-d H:i:s'),
            ':id' => (int) $device['id'],
        ));
        if (function_exists('activity_log')) {
            activity_log(
                $pdo,
                0,
                'network_device',
                (int) $device['id'],
                'device_set_freq',
                'ضبط تردد جهاز: ' . $device['name'],
                'freq=' . $best
            );
        }
        return array(true, '');
    }
    return array(false, $err);
}

function devices_find_by_subscriber_mac($pdo, $mac)
{
    $fold = devices_mac_fold($mac);
    if ($fold === '' || strlen($fold) < 12) {
        return null;
    }
    ensure_network_devices_schema($pdo);
    $rows = devices_list($pdo, array());
    foreach ($rows as $d) {
        $dm = devices_mac_fold(isset($d['mac']) ? $d['mac'] : '');
        if ($dm !== '' && $dm === $fold) {
            return $d;
        }
        foreach (devices_station_mac_list($d) as $sm) {
            if (devices_mac_fold($sm) === $fold) {
                return $d;
            }
        }
    }
    return null;
}

function devices_subscribers_for_device($pdo, $device)
{
    if (!$pdo || !is_array($device)) {
        return array();
    }
    $macs = array();
    $own = devices_mac_fold(isset($device['mac']) ? $device['mac'] : '');
    if ($own !== '') {
        $macs[$own] = true;
    }
    foreach (devices_station_mac_list($device) as $sm) {
        $f = devices_mac_fold($sm);
        if ($f !== '') {
            $macs[$f] = true;
        }
    }
    if (!$macs) {
        return array();
    }
    $tid = (int) $device['tenant_id'];
    $out = array();
    try {
        $st = $pdo->prepare(
            'SELECT username, display_name, firstname, mac_address, framed_ip, local_subscriber_id
             FROM sas_users_cache
             WHERE tenant_id = :t AND mac_address IS NOT NULL AND mac_address <> ""'
        );
        $st->execute(array(':t' => $tid));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $f = devices_mac_fold($r['mac_address']);
            if ($f !== '' && isset($macs[$f])) {
                $out[] = $r;
            }
        }
    } catch (Exception $e) {
    }
    return $out;
}
