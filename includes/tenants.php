<?php

/**
 * شركات متعددة (tenants) — الداتا الحالية = tenant_id = 1
 * لا تمسح ديون ولا مشتركين؛ الكاش معزول لكل شركة.
 */

function ensure_tenants_schema($pdo, $config = null)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tenants (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sas_enabled TINYINT(1) NOT NULL DEFAULT 0,
            sas_host VARCHAR(255) NULL DEFAULT NULL,
            sas_username VARCHAR(120) NULL DEFAULT NULL,
            sas_password VARCHAR(255) NULL DEFAULT NULL,
            sas_parent_id INT UNSIGNED NULL DEFAULT 1,
            sas_default_password VARCHAR(120) NULL DEFAULT NULL,
            sas_activate_units INT UNSIGNED NOT NULL DEFAULT 1,
            sas_extend_method VARCHAR(32) NOT NULL DEFAULT \'reward_points\',
            sas_extend_profile_id INT UNSIGNED NOT NULL DEFAULT 0,
            sas_on_failure VARCHAR(16) NOT NULL DEFAULT \'warn\',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $exists = $pdo->query('SELECT id FROM tenants WHERE id = 1')->fetch();
    if (!$exists) {
        $pdo->exec("INSERT INTO tenants (id, name, is_active) VALUES (1, 'الشركة الافتراضية', 1)");
    }

    // نسخ إعدادات الساس الحالية إلى tenant 1 إن كانت فارغة
    try {
        $row = $pdo->query('SELECT sas_host, sas_username FROM tenants WHERE id = 1')->fetch();
        $needCopy = $row && (empty($row['sas_host']) || empty($row['sas_username']));
        if ($needCopy && is_array($config) && !empty($config['sas']) && is_array($config['sas'])) {
            $s = $config['sas'];
            $pdo->prepare(
                'UPDATE tenants SET
                    sas_enabled = :en,
                    sas_host = :h,
                    sas_username = :u,
                    sas_password = :p,
                    sas_parent_id = :pid,
                    sas_default_password = :dp,
                    sas_activate_units = :au,
                    sas_extend_method = :em,
                    sas_extend_profile_id = :ep,
                    sas_on_failure = :of
                 WHERE id = 1
                   AND (sas_host IS NULL OR sas_host = \'\' OR sas_username IS NULL OR sas_username = \'\')'
            )->execute(array(
                ':en' => !empty($s['enabled']) ? 1 : 0,
                ':h' => isset($s['host']) ? preg_replace('#^https?://#i', '', rtrim(trim((string) $s['host']), '/')) : '',
                ':u' => isset($s['username']) ? trim((string) $s['username']) : '',
                ':p' => isset($s['password']) ? (string) $s['password'] : '',
                ':pid' => isset($s['parent_id']) ? (int) $s['parent_id'] : 1,
                ':dp' => isset($s['default_password']) ? (string) $s['default_password'] : '',
                ':au' => max(1, isset($s['activate_units']) ? (int) $s['activate_units'] : 1),
                ':em' => (isset($s['extend_method']) && $s['extend_method'] === 'credit') ? 'credit' : 'reward_points',
                ':ep' => isset($s['extend_profile_id']) ? (int) $s['extend_profile_id'] : 0,
                ':of' => (isset($s['on_failure']) && $s['on_failure'] === 'rollback') ? 'rollback' : 'warn',
            ));
        }
    } catch (Exception $e) {
    }

    tenants_ensure_column($pdo, 'admin_users', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
    tenants_ensure_column($pdo, 'subscribers', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
    try {
        $pdo->exec('UPDATE admin_users SET tenant_id = 1 WHERE tenant_id IS NULL OR tenant_id = 0');
    } catch (Exception $e) {
    }
    try {
        $pdo->exec('UPDATE subscribers SET tenant_id = 1 WHERE tenant_id IS NULL OR tenant_id = 0');
    } catch (Exception $e) {
    }
    try {
        $pdo->exec('ALTER TABLE admin_users ADD INDEX idx_admin_tenant (tenant_id)');
    } catch (Exception $e) {
    }
    try {
        $pdo->exec('ALTER TABLE subscribers ADD INDEX idx_sub_tenant (tenant_id)');
    } catch (Exception $e) {
    }

    // كاش الساس: tenant_id + PK مركّب
    if (function_exists('ensure_sas_users_cache_table')) {
        ensure_sas_users_cache_table($pdo);
    }
    tenants_ensure_column($pdo, 'sas_users_cache', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
    try {
        $pdo->exec('UPDATE sas_users_cache SET tenant_id = 1 WHERE tenant_id IS NULL OR tenant_id = 0');
    } catch (Exception $e) {
    }
    tenants_migrate_sas_cache_pk($pdo);
    tenants_migrate_sync_meta($pdo);

    // أسعار كروت الوكلاء
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS agent_card_prices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
                agent_user_id INT UNSIGNED NOT NULL,
                profile_id INT UNSIGNED NOT NULL DEFAULT 0,
                profile_name VARCHAR(120) NOT NULL DEFAULT "",
                wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                agent_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_agent_price (agent_user_id, profile_id, profile_name(60)),
                KEY idx_price_tenant (tenant_id),
                KEY idx_price_agent (agent_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Exception $e) {
    }

    // tenant_id على جداول الكروت (وراثة لاحقة من الوكيل)
    if (function_exists('ensure_card_accounting_tables')) {
        try {
            ensure_card_accounting_tables($pdo);
        } catch (Exception $e) {
        }
    }
    tenants_ensure_column($pdo, 'agent_card_stock', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
    tenants_ensure_column($pdo, 'agent_card_transfers', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
    tenants_ensure_column($pdo, 'agent_card_payments', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');

    // اشتراك SaaS للوكلاء
    tenants_ensure_column($pdo, 'tenants', 'status', "VARCHAR(20) NOT NULL DEFAULT 'active'");
    tenants_ensure_column($pdo, 'tenants', 'owner_user_id', 'INT UNSIGNED NULL DEFAULT NULL');
    tenants_ensure_column($pdo, 'tenants', 'trial_ends_at', 'DATETIME NULL DEFAULT NULL');
    tenants_ensure_column($pdo, 'tenants', 'subscription_expires_at', 'DATETIME NULL DEFAULT NULL');
    tenants_ensure_column($pdo, 'tenants', 'plan_code', "VARCHAR(32) NULL DEFAULT NULL");
    tenants_ensure_column($pdo, 'tenants', 'contact_phone', 'VARCHAR(32) NULL DEFAULT NULL');
    tenants_ensure_column($pdo, 'tenants', 'company_email', 'VARCHAR(150) NULL DEFAULT NULL');
    tenants_ensure_column($pdo, 'tenants', 'company_address', 'VARCHAR(255) NULL DEFAULT NULL');
    tenants_ensure_column($pdo, 'tenants', 'company_about', 'TEXT NULL');
    tenants_ensure_column($pdo, 'tenants', 'company_logo', 'VARCHAR(255) NULL DEFAULT NULL');
    tenants_ensure_column($pdo, 'tenants', 'wa_templates', 'LONGTEXT NULL');
    tenants_ensure_column($pdo, 'tenants', 'sas_company_id', 'INT UNSIGNED NULL DEFAULT NULL');
    try {
        $pdo->exec("UPDATE tenants SET status = 'active' WHERE id = 1 AND (status IS NULL OR status = '')");
    } catch (Exception $e) {
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS subscription_payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                currency VARCHAR(16) NOT NULL DEFAULT "IQD",
                plan_code VARCHAR(32) NULL DEFAULT NULL,
                period_days INT UNSIGNED NOT NULL DEFAULT 30,
                zaincash_id VARCHAR(64) NULL DEFAULT NULL,
                order_id VARCHAR(120) NULL DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "initiated",
                raw_response TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                paid_at DATETIME NULL DEFAULT NULL,
                KEY idx_pay_tenant (tenant_id),
                KEY idx_pay_order (order_id),
                KEY idx_pay_zc (zaincash_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Exception $e) {
    }
}

function tenants_ensure_column($pdo, $table, $col, $sqlType)
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $col);
    if ($table === '' || $col === '') {
        return;
    }
    try {
        $chk = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetch();
        if (!$chk) {
            return;
        }
        $c = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($col))->fetch();
        if (!$c) {
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $col . '` ' . $sqlType);
        }
    } catch (Exception $e) {
    }
}

function tenants_sas_cache_pk_is_ok($pdo)
{
    try {
        $pk = $pdo->query("SHOW KEYS FROM sas_users_cache WHERE Key_name = 'PRIMARY'")->fetchAll();
        $cols = array();
        foreach ($pk as $r) {
            $cols[] = isset($r['Column_name']) ? $r['Column_name'] : '';
        }
        return in_array('tenant_id', $cols, true) && in_array('username', $cols, true) && count($cols) >= 2;
    } catch (Exception $e) {
        return false;
    }
}

function tenants_migrate_sas_cache_pk($pdo)
{
    static $pkDone = false;
    static $pkOk = false;
    if ($pkDone && $pkOk) {
        return $pkOk;
    }
    if (function_exists('ensure_sas_users_cache_table')) {
        try {
            ensure_sas_users_cache_table($pdo);
        } catch (Exception $e) {
        }
    }
    tenants_ensure_column($pdo, 'sas_users_cache', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
    try {
        $pdo->exec('UPDATE sas_users_cache SET tenant_id = 1 WHERE tenant_id IS NULL OR tenant_id = 0');
    } catch (Exception $e) {
    }
    if (tenants_sas_cache_pk_is_ok($pdo)) {
        $pkDone = true;
        $pkOk = true;
        return true;
    }
    try {
        try {
            $pdo->exec('ALTER TABLE sas_users_cache DROP PRIMARY KEY');
        } catch (Exception $e) {
        }
        $pdo->exec('ALTER TABLE sas_users_cache ADD PRIMARY KEY (tenant_id, username)');
    } catch (Exception $e) {
        $pkDone = false;
        $pkOk = false;
        return false;
    }
    try {
        $pdo->exec('ALTER TABLE sas_users_cache ADD INDEX idx_sas_tenant (tenant_id)');
    } catch (Exception $e) {
    }
    $pkOk = tenants_sas_cache_pk_is_ok($pdo);
    $pkDone = $pkOk;
    return $pkOk;
}

function tenants_migrate_sync_meta($pdo)
{
    tenants_ensure_column($pdo, 'sas_sync_meta', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
    try {
        $pdo->exec('UPDATE sas_sync_meta SET tenant_id = 1 WHERE id = 1 AND (tenant_id IS NULL OR tenant_id = 0)');
    } catch (Exception $e) {
    }
    // صف لكل tenant لاحقاً؛ نضمن صف tenant=1
    try {
        $has = $pdo->query('SELECT tenant_id FROM sas_sync_meta WHERE tenant_id = 1 LIMIT 1')->fetch();
        if (!$has) {
            $pdo->exec('INSERT INTO sas_sync_meta (id, tenant_id, last_count) VALUES (1, 1, 0)');
        }
    } catch (Exception $e) {
    }
    try {
        $pdo->exec('ALTER TABLE sas_sync_meta ADD UNIQUE KEY uq_sync_tenant (tenant_id)');
    } catch (Exception $e) {
    }
}

function current_tenant_id()
{
    if (!empty($_SESSION['admin_tenant_id'])) {
        $t = (int) $_SESSION['admin_tenant_id'];
        if ($t > 0) {
            return $t;
        }
    }
    if (function_exists('current_admin')) {
        $a = current_admin();
        if ($a && !empty($a['tenant_id'])) {
            return max(1, (int) $a['tenant_id']);
        }
    }
    return 1;
}

function tenant_scope_sql($alias = 's')
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
    if ($a === '') {
        $a = 's';
    }
    return ' AND ' . $a . '.tenant_id = ' . (int) current_tenant_id();
}

function tenant_row($pdo, $tenantId = null)
{
    $tenantId = $tenantId === null ? current_tenant_id() : max(1, (int) $tenantId);
    try {
        ensure_tenants_schema($pdo);
        $st = $pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $tenantId));
        $row = $st->fetch();
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * إعدادات ساس للشركة — مع سقوط آمن لإعدادات النظام الحالية لـ tenant 1
 */
function sas_config_for_tenant($pdo, $config, $tenantId = null)
{
    $tenantId = $tenantId === null ? current_tenant_id() : max(1, (int) $tenantId);
    $base = function_exists('sas_config') ? sas_config($config) : array(
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

    $row = tenant_row($pdo, $tenantId);
    if (!$row) {
        return $base;
    }

    $host = isset($row['sas_host']) ? trim((string) $row['sas_host']) : '';
    $user = isset($row['sas_username']) ? trim((string) $row['sas_username']) : '';
    // tenant 1: إذا فاضي استخدم الإعدادات العامة
    if ($tenantId === 1 && ($host === '' || $user === '')) {
        return $base;
    }

    return array(
        'enabled' => !empty($row['sas_enabled']),
        'host' => preg_replace('#^https?://#i', '', rtrim($host, '/')),
        'username' => $user,
        'password' => isset($row['sas_password']) ? (string) $row['sas_password'] : '',
        'parent_id' => isset($row['sas_parent_id']) ? (int) $row['sas_parent_id'] : 1,
        'default_password' => isset($row['sas_default_password']) ? (string) $row['sas_default_password'] : '',
        'activate_units' => isset($row['sas_activate_units']) ? max(0, (int) $row['sas_activate_units']) : 1,
        'extend_method' => (isset($row['sas_extend_method']) && $row['sas_extend_method'] === 'credit') ? 'credit' : 'reward_points',
        'extend_profile_id' => isset($row['sas_extend_profile_id']) ? (int) $row['sas_extend_profile_id'] : 0,
        'on_failure' => (isset($row['sas_on_failure']) && $row['sas_on_failure'] === 'rollback') ? 'rollback' : 'warn',
        'tenant_id' => $tenantId,
    );
}

function sas_make_connector_for_tenant($pdo, $config, $tenantId = null)
{
    if (!class_exists('SASConnector')) {
        return null;
    }
    $s = sas_config_for_tenant($pdo, $config, $tenantId);
    if (empty($s['host']) || $s['username'] === '' || $s['password'] === '') {
        return null;
    }
    return new SASConnector($s['host'], $s['username'], $s['password'], 'acp');
}

function tenant_save($pdo, $tenantId, $fields)
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0 || !is_array($fields) || !$fields) {
        return false;
    }
    $allow = array(
        'name', 'is_active', 'sas_enabled', 'sas_host', 'sas_username', 'sas_password',
        'sas_parent_id', 'sas_default_password', 'sas_activate_units', 'sas_extend_method',
        'sas_extend_profile_id', 'sas_on_failure',
        'status', 'owner_user_id', 'trial_ends_at', 'subscription_expires_at', 'plan_code', 'contact_phone',
        'company_email', 'company_address', 'company_about', 'company_logo', 'wa_templates', 'sas_company_id',
    );
    $cols = array();
    $params = array(':id' => $tenantId);
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allow, true)) {
            continue;
        }
        if ($k === 'wa_templates' && is_array($v)) {
            $flags = 0;
            if (defined('JSON_UNESCAPED_UNICODE')) {
                $flags |= JSON_UNESCAPED_UNICODE;
            }
            $v = json_encode($v, $flags);
        }
        $cols[] = '`' . $k . '` = :' . $k;
        $params[':' . $k] = $v;
    }
    if (!$cols) {
        return false;
    }
    try {
        $pdo->prepare('UPDATE tenants SET ' . implode(', ', $cols) . ' WHERE id = :id')->execute($params);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function tenant_create($pdo, $name, $sasFields = array())
{
    $name = trim((string) $name);
    if ($name === '') {
        return array(0, 'اسم الشركة مطلوب');
    }
    ensure_tenants_schema($pdo);
    try {
        $st = $pdo->prepare(
            'INSERT INTO tenants (name, is_active, sas_enabled, sas_host, sas_username, sas_password, sas_parent_id,
                sas_default_password, sas_activate_units, sas_extend_method, sas_extend_profile_id, sas_on_failure)
             VALUES (:n, 1, :en, :h, :u, :p, :pid, :dp, :au, :em, :ep, :of)'
        );
        $st->execute(array(
            ':n' => $name,
            ':en' => !empty($sasFields['sas_enabled']) ? 1 : 0,
            ':h' => isset($sasFields['sas_host']) ? preg_replace('#^https?://#i', '', rtrim(trim((string) $sasFields['sas_host']), '/')) : '',
            ':u' => isset($sasFields['sas_username']) ? trim((string) $sasFields['sas_username']) : '',
            ':p' => isset($sasFields['sas_password']) ? (string) $sasFields['sas_password'] : '',
            ':pid' => isset($sasFields['sas_parent_id']) ? (int) $sasFields['sas_parent_id'] : 1,
            ':dp' => isset($sasFields['sas_default_password']) ? (string) $sasFields['sas_default_password'] : '',
            ':au' => max(1, isset($sasFields['sas_activate_units']) ? (int) $sasFields['sas_activate_units'] : 1),
            ':em' => (isset($sasFields['sas_extend_method']) && $sasFields['sas_extend_method'] === 'credit') ? 'credit' : 'reward_points',
            ':ep' => isset($sasFields['sas_extend_profile_id']) ? (int) $sasFields['sas_extend_profile_id'] : 0,
            ':of' => (isset($sasFields['sas_on_failure']) && $sasFields['sas_on_failure'] === 'rollback') ? 'rollback' : 'warn',
        ));
        $id = (int) $pdo->lastInsertId();
        if ($id > 0) {
            try {
                $pdo->prepare(
                    'INSERT INTO sas_sync_meta (id, tenant_id, last_count) VALUES (:id, :t, 0)
                     ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)'
                )->execute(array(':id' => min(255, $id), ':t' => $id));
            } catch (Exception $e) {
                try {
                    $pdo->prepare('INSERT IGNORE INTO sas_sync_meta (id, tenant_id, last_count) VALUES (1, :t, 0)')
                        ->execute(array(':t' => $id));
                } catch (Exception $e2) {
                }
            }
        }
        return array($id, '');
    } catch (Exception $e) {
        return array(0, 'تعذر إنشاء الشركة');
    }
}

function tenants_list($pdo)
{
    ensure_tenants_schema($pdo);
    try {
        return $pdo->query('SELECT * FROM tenants ORDER BY id ASC')->fetchAll();
    } catch (Exception $e) {
        return array();
    }
}

/**
 * شركات الإدمن اللي عليها هوست ساس — لاختيار الوكالة
 * (يفضّل الشركات بدون مالك SaaS، مع إبقاء أي هوست فريد موجود)
 */
function tenants_sas_company_catalog($pdo, $config = null)
{
    ensure_tenants_schema($pdo);
    $raw = array();
    try {
        $raw = $pdo->query(
            "SELECT id, name, sas_host, sas_enabled, owner_user_id
             FROM tenants
             WHERE is_active = 1
               AND sas_host IS NOT NULL AND TRIM(sas_host) <> ''
             ORDER BY
               CASE WHEN id = 1 THEN 0
                    WHEN owner_user_id IS NULL OR owner_user_id = 0 THEN 1
                    ELSE 2 END,
               id ASC"
        )->fetchAll();
        if (!is_array($raw)) {
            $raw = array();
        }
    } catch (Exception $e) {
        $raw = array();
    }
    // إن كانت الشركة 1 فاضي بالجدول لكن مضبوط بالإعدادات العامة
    $has1 = false;
    foreach ($raw as $r) {
        if ((int) $r['id'] === 1) {
            $has1 = true;
            break;
        }
    }
    if (!$has1 && is_array($config) && function_exists('sas_config')) {
        $base = sas_config($config);
        $h = isset($base['host']) ? trim((string) $base['host']) : '';
        if ($h !== '') {
            array_unshift($raw, array(
                'id' => 1,
                'name' => 'الشركة الرئيسية',
                'sas_host' => $h,
                'sas_enabled' => !empty($base['enabled']) ? 1 : 0,
                'owner_user_id' => null,
            ));
        }
    }
    // هوست فريد واحد لكل سيرفر (أول ظهور حسب الأولوية)
    $out = array();
    $seenHost = array();
    foreach ($raw as $r) {
        $h = strtolower(preg_replace('#^https?://#i', '', rtrim(trim((string) $r['sas_host']), '/')));
        if ($h === '' || isset($seenHost[$h])) {
            continue;
        }
        $seenHost[$h] = true;
        $out[] = $r;
    }
    return $out;
}

/**
 * هوست الشركة المختارة (لربط وكالة)
 */
function tenant_company_sas_host($pdo, $companyId, $config = null)
{
    $companyId = (int) $companyId;
    if ($companyId <= 0) {
        return '';
    }
    $row = tenant_row($pdo, $companyId);
    if ($row && isset($row['sas_host']) && trim((string) $row['sas_host']) !== '') {
        return preg_replace('#^https?://#i', '', rtrim(trim((string) $row['sas_host']), '/'));
    }
    if ($companyId === 1 && is_array($config) && function_exists('sas_config')) {
        $base = sas_config($config);
        $h = isset($base['host']) ? trim((string) $base['host']) : '';
        return $h !== '' ? preg_replace('#^https?://#i', '', rtrim($h, '/')) : '';
    }
    return '';
}

function is_super_admin_user($user = null)
{
    if ($user === null && function_exists('current_admin')) {
        $user = current_admin();
    }
    if (!$user || !is_array($user)) {
        return false;
    }
    $role = isset($user['role']) ? (string) $user['role'] : '';
    if ($role !== 'admin') {
        return false;
    }
    // سوبر أدمن = أدمن على tenant 1 أو بدون قيد شركة أخرى
    $tid = isset($user['tenant_id']) ? (int) $user['tenant_id'] : 1;
    return $tid <= 1;
}

/**
 * فحص اتصال ساس بدون حفظ — يرجع [ok, message]
 */
function tenant_test_sas_connection($host, $username, $password)
{
    $host = preg_replace('#^https?://#i', '', rtrim(trim((string) $host), '/'));
    $username = trim((string) $username);
    $password = (string) $password;
    if ($host === '' || $username === '' || $password === '') {
        return array(false, 'أكمل الرابط واسم المستخدم وكلمة المرور');
    }
    if (!class_exists('SASConnector')) {
        return array(false, 'مكتبة الساس غير محمّلة');
    }
    try {
        $api = new SASConnector($host, $username, $password, 'acp');
        if (method_exists($api, 'setTimeout')) {
            $api->setTimeout(20);
        }
        if (!$api->login()) {
            $err = method_exists($api, 'getLastError') ? (string) $api->getLastError() : '';
            $errLow = strtolower($err);
            if ($err === '' || strpos($errLow, 'pass') !== false || strpos($errLow, 'user') !== false
                || strpos($errLow, 'auth') !== false || strpos($errLow, 'login') !== false
                || strpos($errLow, 'credential') !== false || strpos($err, 'باسورد') !== false
                || strpos($err, 'مستخدم') !== false) {
                return array(false, 'خطأ باسم المستخدم أو كلمة المرور');
            }
            if (strpos($errLow, 'resolve') !== false || strpos($errLow, 'connect') !== false
                || strpos($errLow, 'timeout') !== false || strpos($errLow, 'curl') !== false) {
                return array(false, 'غير متصل — تعذر الوصول لرابط الساس');
            }
            return array(false, $err);
        }
        return array(true, 'متصل بهذا المكان');
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $low = strtolower($msg);
        if (strpos($low, 'pass') !== false || strpos($low, 'auth') !== false || strpos($low, 'login') !== false) {
            return array(false, 'خطأ باسم المستخدم أو كلمة المرور');
        }
        if (strpos($low, 'resolve') !== false || strpos($low, 'connect') !== false || strpos($low, 'timeout') !== false) {
            return array(false, 'غير متصل — تعذر الوصول لرابط الساس');
        }
        return array(false, $msg);
    }
}

/** حالة الساس للواجهة — لا تمسح داتا عند الفشل */
function sas_connection_status($pdo, $config, $tenantId = null)
{
    $tenantId = $tenantId === null ? current_tenant_id() : max(1, (int) $tenantId);
    $out = array(
        'ok' => false,
        'ready' => false,
        'label' => 'غير مضبوط',
        'detail' => '',
        'tenant_id' => $tenantId,
    );
    $s = sas_config_for_tenant($pdo, $config, $tenantId);
    if (empty($s['enabled']) || $s['host'] === '' || $s['username'] === '') {
        $out['label'] = 'ساس مطفأ / غير مضبوط';
        return $out;
    }
    $out['ready'] = true;
    $lock = __DIR__ . '/../config/sas_status_t' . $tenantId . '.json';
    $now = time();
    if (is_file($lock)) {
        $prev = @json_decode((string) @file_get_contents($lock), true);
        if (is_array($prev) && !empty($prev['at']) && ($now - (int) $prev['at']) < 90) {
            return array_merge($out, $prev, array('tenant_id' => $tenantId));
        }
    }
    list($ok, $msg) = tenant_test_sas_connection($s['host'], $s['username'], $s['password']);
    $out['ok'] = $ok;
    $out['label'] = $ok ? 'ساس متصل' : 'ساس غير متصل';
    $out['detail'] = $msg;
    $out['at'] = $now;
    @file_put_contents($lock, json_encode($out));
    return $out;
}

/**
 * هل يُسمح بالكتابة للساس؟ القراءة المحلية دائماً مسموحة.
 * يستخدم كاش الحالة — بدون فحص حي في كل طلب كتابة إن كان حديثاً.
 */
function sas_writes_allowed($pdo, $config, $tenantId = null)
{
    if (!function_exists('sas_is_ready') || !sas_is_ready($config)) {
        return false;
    }
    $tenantId = $tenantId === null ? current_tenant_id() : max(1, (int) $tenantId);
    $lock = __DIR__ . '/../config/sas_status_t' . $tenantId . '.json';
    if (is_file($lock)) {
        $prev = @json_decode((string) @file_get_contents($lock), true);
        if (is_array($prev) && array_key_exists('ok', $prev) && !empty($prev['at'])) {
            // إذا آخر فحص خلال 3 دقائق وقال فاشل → امنع الكتابة
            if ((time() - (int) $prev['at']) < 180 && empty($prev['ok'])) {
                return false;
            }
            if (!empty($prev['ok'])) {
                return true;
            }
        }
    }
    // لا كاش سلبي حديث → اسمح بالمحاولة (الـ connector يفشل بأمان بدون مسح)
    return true;
}

function sas_mark_connection($pdo, $config, $ok, $detail = '', $tenantId = null)
{
    $tenantId = $tenantId === null ? current_tenant_id() : max(1, (int) $tenantId);
    $lock = __DIR__ . '/../config/sas_status_t' . $tenantId . '.json';
    $out = array(
        'ok' => (bool) $ok,
        'ready' => true,
        'label' => $ok ? 'ساس متصل' : 'ساس غير متصل',
        'detail' => (string) $detail,
        'tenant_id' => $tenantId,
        'at' => time(),
    );
    @file_put_contents($lock, json_encode($out));
    return $out;
}

/** مزامنة إعدادات settings.json إلى صف tenant الحالي (عادة 1) */
function tenant_sync_from_settings($pdo, $settings, $tenantId = null)
{
    $tenantId = $tenantId === null ? current_tenant_id() : max(1, (int) $tenantId);
    ensure_tenants_schema($pdo);
    $fields = array(
        'sas_enabled' => !empty($settings['sas_enabled']) ? 1 : 0,
        'sas_host' => isset($settings['sas_host'])
            ? preg_replace('#^https?://#i', '', rtrim(trim((string) $settings['sas_host']), '/'))
            : '',
        'sas_username' => isset($settings['sas_username']) ? trim((string) $settings['sas_username']) : '',
        'sas_parent_id' => isset($settings['sas_parent_id']) ? (int) $settings['sas_parent_id'] : 1,
        'sas_default_password' => isset($settings['sas_default_password']) ? (string) $settings['sas_default_password'] : '',
        'sas_activate_units' => isset($settings['sas_activate_units']) ? max(1, (int) $settings['sas_activate_units']) : 1,
        'sas_extend_method' => (isset($settings['sas_extend_method']) && $settings['sas_extend_method'] === 'credit')
            ? 'credit' : 'reward_points',
        'sas_extend_profile_id' => isset($settings['sas_extend_profile_id']) ? (int) $settings['sas_extend_profile_id'] : 0,
        'sas_on_failure' => (isset($settings['sas_on_failure']) && $settings['sas_on_failure'] === 'rollback')
            ? 'rollback' : 'warn',
    );
    if (isset($settings['sas_password']) && (string) $settings['sas_password'] !== '') {
        $fields['sas_password'] = (string) $settings['sas_password'];
    }
    return tenant_save($pdo, $tenantId, $fields);
}

/** هل الوكيل/المستخدم ضمن نفس الشركة الحالية؟ */
function admin_user_same_tenant($pdo, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT tenant_id FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $userId));
        $tid = (int) $st->fetchColumn();
        $cur = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        return $tid === $cur || ($tid <= 0 && $cur <= 1);
    } catch (Exception $e) {
        return true;
    }
}

/**
 * هل اشتراك الـ tenant ساري؟ (tenant 1 دائماً OK)
 * @return array(bool ok, string reason_code, string message_ar)
 */
function tenant_subscription_status($pdo, $tenantId = null)
{
    $tenantId = $tenantId === null
        ? (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1)
        : max(1, (int) $tenantId);
    if ($tenantId <= 1) {
        return array(true, 'ok', '');
    }
    $row = tenant_row($pdo, $tenantId);
    if (!$row) {
        return array(false, 'missing', 'الشركة غير موجودة');
    }
    $status = isset($row['status']) ? (string) $row['status'] : 'active';
    if ($status === 'pending') {
        return array(false, 'pending', 'بانتظار موافقة الإدارة');
    }
    if ($status === 'suspended') {
        return array(false, 'suspended', 'الحساب معلّق — تواصل مع الإدارة');
    }
    $now = time();
    $subExp = !empty($row['subscription_expires_at']) ? strtotime($row['subscription_expires_at']) : 0;
    $trialExp = !empty($row['trial_ends_at']) ? strtotime($row['trial_ends_at']) : 0;
    $validUntil = max($subExp, $trialExp);
    if ($status === 'expired' || ($validUntil > 0 && $validUntil < $now)) {
        return array(false, 'expired', 'انتهى الاشتراك — جدّد من صفحة الفوترة');
    }
    // نشط بدون تواريخ = اعتبر صالحاً (tenant قديم)
    return array(true, 'ok', '');
}

function tenant_access_allowed($pdo, $tenantId = null)
{
    list($ok) = tenant_subscription_status($pdo, $tenantId);
    return $ok;
}

/**
 * قوالب واتساب الخاصة بالشركة (معزول عن settings العامة)
 * @return array
 */
function tenant_wa_templates_get($pdo, $tenantId = null)
{
    $tenantId = $tenantId === null
        ? (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1)
        : max(1, (int) $tenantId);
    if ($tenantId <= 1 || !$pdo) {
        return array();
    }
    $row = tenant_row($pdo, $tenantId);
    if (!$row || empty($row['wa_templates'])) {
        return array();
    }
    $raw = $row['wa_templates'];
    if (is_array($raw)) {
        return $raw;
    }
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : array();
}

function tenant_wa_templates_save($pdo, $catalog, $tenantId = null)
{
    $tenantId = $tenantId === null
        ? (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1)
        : max(1, (int) $tenantId);
    if ($tenantId <= 1 || !$pdo) {
        return false;
    }
    if (!is_array($catalog)) {
        $catalog = array();
    }
    return tenant_save($pdo, $tenantId, array('wa_templates' => $catalog));
}

/** دمج قوالب الشركة في $config للواجهة والإرسال */
function tenant_apply_wa_templates_to_config(&$config, $pdo = null)
{
    if (!$pdo && isset($GLOBALS['pdo'])) {
        $pdo = $GLOBALS['pdo'];
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 1 || !$pdo) {
        return;
    }
    $catalog = tenant_wa_templates_get($pdo, $tid);
    if (!$catalog) {
        // أول مرة: انسخ القوالب العامة كنقطة بداية خاصة بالشركة
        if (!empty($config['wa_templates']) && is_array($config['wa_templates'])) {
            $catalog = $config['wa_templates'];
            tenant_wa_templates_save($pdo, $catalog, $tid);
        } else {
            return;
        }
    }
    if (!isset($config['templates']) || !is_array($config['templates'])) {
        $config['templates'] = array();
    }
    if (!isset($config['template_labels']) || !is_array($config['template_labels'])) {
        $config['template_labels'] = array();
    }
    $config['wa_templates'] = array();
    foreach ($catalog as $tKey => $tRow) {
        if (!is_array($tRow)) {
            continue;
        }
        $body = isset($tRow['body']) ? (string) $tRow['body'] : '';
        $label = isset($tRow['label']) ? (string) $tRow['label'] : (string) $tKey;
        $config['templates'][$tKey] = $body;
        $config['template_labels'][$tKey] = $label;
        $config['wa_templates'][$tKey] = array(
            'label' => $label,
            'body' => $body,
            'builtin' => !empty($tRow['builtin']),
        );
    }
}

function saas_settings($settings = null)
{
    if ($settings === null && function_exists('settings_load')) {
        $settings = settings_load();
    }
    if (!is_array($settings)) {
        $settings = array();
    }
    $plans = isset($settings['saas_plans']) && is_array($settings['saas_plans'])
        ? $settings['saas_plans']
        : array(
            'monthly' => array('label' => 'شهري', 'days' => 30, 'amount' => 25000),
            'yearly' => array('label' => 'سنوي', 'days' => 365, 'amount' => 250000),
        );
    return array(
        'registration_enabled' => !isset($settings['saas_registration_enabled']) || !empty($settings['saas_registration_enabled']),
        'trial_days' => isset($settings['saas_trial_days']) ? max(0, (int) $settings['saas_trial_days']) : 7,
        'plans' => $plans,
        'zaincash_merchant_id' => isset($settings['zaincash_merchant_id']) ? trim((string) $settings['zaincash_merchant_id']) : '',
        'zaincash_secret' => isset($settings['zaincash_secret']) ? (string) $settings['zaincash_secret'] : '',
        'zaincash_msisdn' => isset($settings['zaincash_msisdn']) ? trim((string) $settings['zaincash_msisdn']) : '',
        'zaincash_production' => !empty($settings['zaincash_production']),
        'zaincash_redirect_base' => isset($settings['zaincash_redirect_base'])
            ? rtrim(trim((string) $settings['zaincash_redirect_base']), '/')
            : '',
    );
}

/**
 * تسجيل وكيل جديد → tenant pending + مستخدم admin غير نشط للدخول حتى الموافقة
 * @return array(bool, string message, int tenantId, int userId)
 */
function saas_register_agent($pdo, $agencyName, $username, $displayName, $password, $phone)
{
    ensure_tenants_schema($pdo);
    $agencyName = trim((string) $agencyName);
    $username = trim((string) $username);
    $displayName = trim((string) $displayName);
    $phone = trim((string) $phone);
    if ($agencyName === '' || $username === '' || strlen($password) < 4) {
        return array(false, 'أكمل الحقول (الباسورد 4 أحرف على الأقل)', 0, 0);
    }
    if (!preg_match('/^[a-zA-Z0-9._@-]{2,40}$/', $username)) {
        return array(false, 'اسم الدخول غير صالح', 0, 0);
    }
    try {
        $ex = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u LIMIT 1');
        $ex->execute(array(':u' => $username));
        if ($ex->fetchColumn()) {
            return array(false, 'اسم الدخول مستخدم', 0, 0);
        }
    } catch (Exception $e) {
        return array(false, 'تعذر التحقق من اليوزر', 0, 0);
    }

    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare(
            'INSERT INTO tenants (name, is_active, status, contact_phone, sas_enabled)
             VALUES (:n, 0, "pending", :ph, 0)'
        );
        $st->execute(array(
            ':n' => $agencyName,
            ':ph' => $phone !== '' ? $phone : null,
        ));
        $tenantId = (int) $pdo->lastInsertId();
        if ($tenantId <= 0) {
            $pdo->rollBack();
            return array(false, 'تعذر إنشاء الشركة', 0, 0);
        }
        $hash = function_exists('admin_password_hash') ? admin_password_hash($password) : password_hash($password, PASSWORD_DEFAULT);
        $disp = $displayName !== '' ? $displayName : $agencyName;
        $pdo->prepare(
            'INSERT INTO admin_users (username, display_name, password_hash, role, is_active, tenant_id, phone)
             VALUES (:u, :d, :h, "admin", 0, :t, :ph)'
        )->execute(array(
            ':u' => $username,
            ':d' => $disp,
            ':h' => $hash,
            ':t' => $tenantId,
            ':ph' => $phone !== '' ? $phone : null,
        ));
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE tenants SET owner_user_id = :u WHERE id = :id')
            ->execute(array(':u' => $userId, ':id' => $tenantId));
        $pdo->commit();
        return array(true, 'تم التسجيل — بانتظار موافقة الإدارة', $tenantId, $userId);
    } catch (Exception $e) {
        try {
            $pdo->rollBack();
        } catch (Exception $e2) {
        }
        return array(false, 'فشل التسجيل', 0, 0);
    }
}

/**
 * موافقة سوبر أدمن → تفعيل + بدء التجريبي
 */
function saas_approve_tenant($pdo, $tenantId, $settings = null)
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 1) {
        return array(false, 'لا يمكن تعديل الشركة الافتراضية هكذا');
    }
    $saas = saas_settings($settings);
    $trialDays = (int) $saas['trial_days'];
    $trialEnd = $trialDays > 0
        ? date('Y-m-d H:i:s', time() + ($trialDays * 86400))
        : null;
    try {
        $pdo->prepare(
            'UPDATE tenants SET status = "active", is_active = 1, trial_ends_at = :tr WHERE id = :id'
        )->execute(array(
            ':tr' => $trialEnd,
            ':id' => $tenantId,
        ));
        if ($trialEnd) {
            $pdo->prepare(
                'UPDATE tenants SET subscription_expires_at = :tr
                 WHERE id = :id AND (subscription_expires_at IS NULL OR subscription_expires_at < :tr2)'
            )->execute(array(':tr' => $trialEnd, ':tr2' => $trialEnd, ':id' => $tenantId));
        }
        $pdo->prepare('UPDATE admin_users SET is_active = 1 WHERE tenant_id = :t AND role = "admin"')
            ->execute(array(':t' => $tenantId));
        return array(true, 'تمت الموافقة وبدء الفترة التجريبية');
    } catch (Exception $e) {
        return array(false, 'تعذر الموافقة');
    }
}

function saas_reject_tenant($pdo, $tenantId)
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 1) {
        return false;
    }
    try {
        $pdo->prepare('UPDATE tenants SET status = "suspended", is_active = 0 WHERE id = :id')
            ->execute(array(':id' => $tenantId));
        $pdo->prepare('UPDATE admin_users SET is_active = 0 WHERE tenant_id = :t')
            ->execute(array(':t' => $tenantId));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function saas_extend_subscription($pdo, $tenantId, $periodDays, $planCode = null)
{
    $tenantId = (int) $tenantId;
    $periodDays = max(1, (int) $periodDays);
    $row = tenant_row($pdo, $tenantId);
    if (!$row) {
        return false;
    }
    $base = time();
    $cur = !empty($row['subscription_expires_at']) ? strtotime($row['subscription_expires_at']) : 0;
    if ($cur > $base) {
        $base = $cur;
    }
    $trial = !empty($row['trial_ends_at']) ? strtotime($row['trial_ends_at']) : 0;
    if ($trial > $base) {
        $base = $trial;
    }
    $newExp = date('Y-m-d H:i:s', $base + ($periodDays * 86400));
    $data = array(
        'status' => 'active',
        'is_active' => 1,
        'subscription_expires_at' => $newExp,
    );
    if ($planCode !== null && $planCode !== '') {
        $data['plan_code'] = (string) $planCode;
    }
    tenant_save($pdo, $tenantId, $data);
    try {
        $pdo->prepare('UPDATE admin_users SET is_active = 1 WHERE tenant_id = :t AND role = "admin"')
            ->execute(array(':t' => $tenantId));
    } catch (Exception $e) {
    }
    return true;
}

function saas_mark_expired_tenants($pdo)
{
    try {
        $pdo->exec(
            "UPDATE tenants SET status = 'expired'
             WHERE id > 1 AND status = 'active'
               AND (
                 (subscription_expires_at IS NOT NULL AND subscription_expires_at < NOW())
                 OR (subscription_expires_at IS NULL AND trial_ends_at IS NOT NULL AND trial_ends_at < NOW())
               )
               AND NOT (
                 (subscription_expires_at IS NOT NULL AND subscription_expires_at >= NOW())
                 OR (trial_ends_at IS NOT NULL AND trial_ends_at >= NOW())
               )"
        );
    } catch (Exception $e) {
    }
}
