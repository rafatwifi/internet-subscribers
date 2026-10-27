<?php

function tenant_is_ftth($pdo = null, $config = null)
{
    $blob = '';
    if (function_exists('system_reseller_bank')) {
        $bank = system_reseller_bank($config);
        if (is_array($bank)) {
            $blob = strtolower(trim((isset($bank['name']) ? $bank['name'] : '') . ' ' . (isset($bank['host']) ? $bank['host'] : '')));
        }
    }
    return strpos($blob, 'ftth') !== false;
}

function ensure_cabinets_schema($pdo)
{
    if (!$pdo) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cabinets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            location VARCHAR(255) NOT NULL DEFAULT "",
            tier_label VARCHAR(80) NOT NULL DEFAULT "",
            investor_user_id INT UNSIGNED NULL DEFAULT NULL,
            source_agent_id INT UNSIGNED NULL DEFAULT NULL,
            auto_made TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            INDEX idx_cab_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cabinet_members (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cabinet_id INT UNSIGNED NOT NULL,
            tenant_id INT UNSIGNED NOT NULL,
            username VARCHAR(190) NOT NULL,
            joined_at DATETIME NOT NULL,
            free_until DATE NULL DEFAULT NULL,
            UNIQUE KEY uq_cab_user (cabinet_id, username),
            INDEX idx_cab_mem_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cabinet_year_rates (
            tenant_id INT UNSIGNED NOT NULL,
            year_no TINYINT UNSIGNED NOT NULL,
            profit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (tenant_id, year_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cabinet_investors (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            name VARCHAR(160) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_inv_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    cabinet_add_column($pdo, 'cabinets', 'source_parent', 'ALTER TABLE cabinets ADD COLUMN source_parent VARCHAR(190) NULL DEFAULT NULL');
    cabinet_add_column($pdo, 'cabinets', 'investor_id', 'ALTER TABLE cabinets ADD COLUMN investor_id INT UNSIGNED NULL DEFAULT NULL');
    cabinet_add_column($pdo, 'sas_users_cache', 'sas_created_at', 'ALTER TABLE sas_users_cache ADD COLUMN sas_created_at DATETIME NULL DEFAULT NULL');
    try {
        $col = $pdo->query("SHOW COLUMNS FROM sas_users_cache LIKE 'parent_name'")->fetch(PDO::FETCH_ASSOC);
        if ($col && isset($col['Type']) && stripos((string) $col['Type'], 'varchar(80)') !== false) {
            $pdo->exec('ALTER TABLE sas_users_cache MODIFY parent_name VARCHAR(190) NULL DEFAULT NULL');
        }
    } catch (Exception $e) {
    }
}

function cabinet_add_column($pdo, $table, $column, $ddl)
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

function cabinet_child_agents($pdo, $userId, $tenantId)
{
    $userId = (int) $userId;
    $tenantId = (int) $tenantId;
    if ($userId <= 0 || $tenantId <= 0 || !$pdo) {
        return array();
    }
    $st = $pdo->prepare(
        'SELECT id, username, display_name, role
         FROM admin_users
         WHERE tenant_id = :t AND reports_to_user_id = :me AND is_active = 1
           AND role IN ("agent", "group_manager", "admin")
         ORDER BY display_name, username'
    );
    $st->execute(array(':t' => $tenantId, ':me' => $userId));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}

function cabinet_sync_from_agents($pdo, $tenantId, $userId)
{
    ensure_cabinets_schema($pdo);
    $agents = cabinet_child_agents($pdo, $userId, $tenantId);
    if (!$agents) {
        return;
    }
    $have = array();
    $st = $pdo->prepare('SELECT source_agent_id FROM cabinets WHERE tenant_id = :t AND source_agent_id IS NOT NULL');
    $st->execute(array(':t' => (int) $tenantId));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $have[(int) $row['source_agent_id']] = 1;
    }
    $ins = $pdo->prepare(
        'INSERT INTO cabinets (tenant_id, name, location, tier_label, investor_user_id, source_agent_id, auto_made, created_at)
         VALUES (:t, :n, "", "", :inv, :src, 1, NOW())'
    );
    foreach ($agents as $ag) {
        $id = (int) $ag['id'];
        if ($id <= 0 || isset($have[$id])) {
            continue;
        }
        $name = trim((string) $ag['display_name']);
        if ($name === '') {
            $name = trim((string) $ag['username']);
        }
        if ($name === '') {
            continue;
        }
        $ins->execute(array(
            ':t' => (int) $tenantId,
            ':n' => $name,
            ':inv' => $id,
            ':src' => $id,
        ));
    }
}

function cabinet_year_rates($pdo, $tenantId)
{
    ensure_cabinets_schema($pdo);
    $st = $pdo->prepare('SELECT year_no, profit_amount FROM cabinet_year_rates WHERE tenant_id = :t ORDER BY year_no');
    $st->execute(array(':t' => (int) $tenantId));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['year_no']] = (float) $row['profit_amount'];
    }
    return $out;
}

function cabinet_rate_for_age($rates, $createdAt)
{
    $year = 1;
    $ts = strtotime((string) $createdAt);
    if ($ts) {
        $days = (int) floor((time() - $ts) / 86400);
        if ($days >= 365) {
            $year = (int) floor($days / 365) + 1;
        }
    }
    if (isset($rates[$year])) {
        return array($year, (float) $rates[$year]);
    }
    if ($rates) {
        $keys = array_keys($rates);
        $last = (int) max($keys);
        return array($year, (float) $rates[$last]);
    }
    return array($year, 0.0);
}

function cabinet_rows($pdo, $tenantId)
{
    ensure_cabinets_schema($pdo);
    $st = $pdo->prepare(
        'SELECT c.*, 
            (SELECT COUNT(*) FROM cabinet_members m WHERE m.cabinet_id = c.id) AS member_count,
            (SELECT COUNT(*) FROM cabinet_members m WHERE m.cabinet_id = c.id AND m.free_until IS NOT NULL AND m.free_until >= CURDATE()) AS free_count,
            u.display_name AS investor_name, u.username AS investor_user
         FROM cabinets c
         LEFT JOIN admin_users u ON u.id = c.investor_user_id
         WHERE c.tenant_id = :t
         ORDER BY c.id DESC'
    );
    $st->execute(array(':t' => (int) $tenantId));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}

function cabinet_sync_from_parents($pdo, $tenantId)
{
    ensure_cabinets_schema($pdo);
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0 || !$pdo) {
        return;
    }
    try {
        $pdo->prepare(
            'DELETE c FROM cabinets c
             WHERE c.tenant_id = :t AND c.auto_made = 1
               AND c.source_agent_id IS NOT NULL
               AND (c.source_parent IS NULL OR c.source_parent = "")
               AND (c.investor_id IS NULL OR c.investor_id = 0)
               AND NOT EXISTS (SELECT 1 FROM cabinet_members m WHERE m.cabinet_id = c.id)'
        )->execute(array(':t' => $tenantId));
    } catch (Exception $e) {
    }
    $st = $pdo->prepare(
        'SELECT parent_name FROM sas_users_cache
         WHERE tenant_id = :t AND parent_name IS NOT NULL AND parent_name <> ""
         GROUP BY parent_name'
    );
    $st->execute(array(':t' => $tenantId));
    $have = array();
    $own = $pdo->prepare('SELECT id, source_parent, name FROM cabinets WHERE tenant_id = :t AND source_parent IS NOT NULL AND source_parent <> ""');
    $own->execute(array(':t' => $tenantId));
    foreach ($own->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $have[(string) $row['source_parent']] = $row;
    }
    $ins = $pdo->prepare(
        'INSERT INTO cabinets (tenant_id, name, location, tier_label, investor_user_id, source_agent_id, auto_made, created_at, source_parent)
         VALUES (:t, :n, "", "", NULL, NULL, 1, NOW(), :p)'
    );
    $ren = $pdo->prepare('UPDATE cabinets SET name = :n WHERE id = :id AND tenant_id = :t');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim((string) $row['parent_name']);
        if ($name === '') {
            continue;
        }
        if (!isset($have[$name])) {
            $ins->execute(array(':t' => $tenantId, ':n' => $name, ':p' => $name));
            continue;
        }
        if ((string) $have[$name]['name'] !== $name) {
            $ren->execute(array(':n' => $name, ':id' => (int) $have[$name]['id'], ':t' => $tenantId));
        }
    }
}

function cabinet_parent_stats($pdo, $tenantId)
{
    $st = $pdo->prepare(
        'SELECT parent_name,
                COUNT(*) AS members,
                SUM(CASE WHEN sas_created_at IS NOT NULL AND sas_created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS news
         FROM sas_users_cache
         WHERE tenant_id = :t AND parent_name IS NOT NULL AND parent_name <> ""
         GROUP BY parent_name
         ORDER BY members DESC'
    );
    $st->execute(array(':t' => (int) $tenantId));
    $out = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim((string) $row['parent_name']);
        if ($name === '') {
            continue;
        }
        $out[$name] = array(
            'members' => (int) $row['members'],
            'news' => (int) $row['news'],
        );
    }
    return $out;
}

function cabinet_investors($pdo, $tenantId)
{
    ensure_cabinets_schema($pdo);
    $st = $pdo->prepare('SELECT id, name, created_at FROM cabinet_investors WHERE tenant_id = :t ORDER BY name, id');
    $st->execute(array(':t' => (int) $tenantId));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : array();
}

function cabinet_fill_created($pdo, $config, $tenantId)
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0 || !$pdo) {
        return 0;
    }
    ensure_cabinets_schema($pdo);
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $mark = $dir . '/cab_created_t' . $tenantId . '.txt';
    $leftSt = $pdo->prepare('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = :t AND sas_created_at IS NULL');
    $leftSt->execute(array(':t' => $tenantId));
    $left = (int) $leftSt->fetchColumn();
    $age = is_file($mark) ? (time() - (int) @filemtime($mark)) : 99999;
    if ($left <= 0 && $age < 21600) {
        return 0;
    }
    if ($left > 0 && $age < 90) {
        return 0;
    }
    if (!function_exists('sas_page_connector')) {
        return 0;
    }
    @touch($mark);
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(70);
    }
    $api = sas_page_connector($config);
    if (!$api || !method_exists($api, 'listUsersPage')) {
        return 0;
    }
    if (method_exists($api, 'setTimeout')) {
        $api->setTimeout(22);
    }
    $upd = $pdo->prepare(
        'UPDATE sas_users_cache SET sas_created_at = :c
         WHERE tenant_id = :t AND LOWER(username) = :u AND (sas_created_at IS NULL OR sas_created_at <> :c2)'
    );
    $start = 0;
    $length = 800;
    $written = 0;
    $guard = 0;
    $total = 0;
    do {
        $pack = $api->listUsersPage($start, $length, '');
        if (!is_array($pack) || empty($pack['ok'])) {
            break;
        }
        $rows = isset($pack['rows']) && is_array($pack['rows']) ? $pack['rows'] : array();
        if (!$rows) {
            break;
        }
        $total = isset($pack['total']) ? (int) $pack['total'] : 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $user = function_exists('sas_cache_username') ? sas_cache_username($row) : '';
            $created = isset($row['created_at']) && !is_array($row['created_at']) ? trim((string) $row['created_at']) : '';
            if ($user === '' || $created === '') {
                continue;
            }
            $upd->execute(array(
                ':c' => $created,
                ':c2' => $created,
                ':t' => $tenantId,
                ':u' => strtolower($user),
            ));
            $written += $upd->rowCount();
        }
        $start += $length;
        $guard++;
        if ($guard >= 8 || ($total > 0 && $start >= $total)) {
            break;
        }
    } while (true);
    @file_put_contents($mark, (string) time());
    return $written;
}

function cabinet_ftth_profiles($config, $tenantId)
{
    $tenantId = (int) $tenantId;
    $dir = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/ftth_profiles_t' . $tenantId . '.json';
    if (is_file($file) && (time() - (int) @filemtime($file)) < 600) {
        $cached = json_decode((string) @file_get_contents($file), true);
        if (is_array($cached) && $cached) {
            return $cached;
        }
    }
    if (!function_exists('sas_page_connector')) {
        return array();
    }
    $api = sas_page_connector($config);
    if (!$api || !method_exists($api, 'getProfiles')) {
        return array();
    }
    $raw = $api->getProfiles();
    $rows = is_array($raw) ? $raw : array();
    if (isset($rows['data']) && is_array($rows['data'])) {
        $rows = $rows['data'];
    }
    $out = array();
    $seen = array();
    foreach ($rows as $p) {
        if (!is_array($p)) {
            continue;
        }
        $name = '';
        foreach (array('name', 'profile_name', 'title', 'srvname') as $k) {
            if (!empty($p[$k]) && !is_array($p[$k])) {
                $name = trim((string) $p[$k]);
                break;
            }
        }
        if ($name === '') {
            continue;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = 1;
        $out[] = $name;
    }
    if ($out) {
        @file_put_contents($file, json_encode($out));
    }
    return $out;
}

function cabinet_title_parts($name)
{
    $name = trim((string) $name);
    if (preg_match('/^\((\d{4}-\d{2}-\d{2})\)\s*(.+)$/', $name, $m)) {
        return array($m[1], $m[2]);
    }
    return array('', $name);
}
