<?php

/**
 * حسابات ريسيلر ساس متعددة لكل وكالة — إدارة مشتركين من أكثر من شركة بمكان واحد
 */

function ensure_tenant_sas_accounts_schema($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tenant_sas_accounts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                label VARCHAR(120) NOT NULL DEFAULT "",
                company_id INT UNSIGNED NULL DEFAULT NULL,
                sas_enabled TINYINT(1) NOT NULL DEFAULT 1,
                sas_host VARCHAR(255) NOT NULL DEFAULT "",
                sas_username VARCHAR(120) NOT NULL DEFAULT "",
                sas_password VARCHAR(255) NOT NULL DEFAULT "",
                sas_parent_id INT UNSIGNED NOT NULL DEFAULT 1,
                sas_default_password VARCHAR(80) NOT NULL DEFAULT "1234",
                sas_activate_units INT UNSIGNED NOT NULL DEFAULT 1,
                sas_extend_method VARCHAR(32) NOT NULL DEFAULT "reward_points",
                sas_extend_profile_id INT UNSIGNED NOT NULL DEFAULT 0,
                sas_on_failure VARCHAR(20) NOT NULL DEFAULT "warn",
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                last_ok_at DATETIME NULL DEFAULT NULL,
                last_error VARCHAR(255) NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_tsa_tenant (tenant_id),
                KEY idx_tsa_default (tenant_id, is_default)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Exception $e) {
    }
    if (function_exists('tenants_ensure_column')) {
        try {
            tenants_ensure_column($pdo, 'sas_users_cache', 'sas_account_id', 'INT UNSIGNED NULL DEFAULT NULL');
        } catch (Exception $e) {
        }
    }
    // ترحيل بيانات الساس القديمة من صف الـ tenant إن ماكو حسابات
    try {
        $rows = $pdo->query(
            'SELECT id, sas_enabled, sas_host, sas_username, sas_password, sas_parent_id,
                    sas_default_password, sas_activate_units, sas_extend_method,
                    sas_extend_profile_id, sas_on_failure, sas_company_id, name
             FROM tenants
             WHERE sas_host IS NOT NULL AND TRIM(sas_host) <> ""
               AND sas_username IS NOT NULL AND TRIM(sas_username) <> ""'
        )->fetchAll();
        if (is_array($rows)) {
            foreach ($rows as $t) {
                $tid = (int) $t['id'];
                $cnt = (int) $pdo->query(
                    'SELECT COUNT(*) FROM tenant_sas_accounts WHERE tenant_id = ' . $tid
                )->fetchColumn();
                if ($cnt > 0) {
                    continue;
                }
                $st = $pdo->prepare(
                    'INSERT INTO tenant_sas_accounts
                     (tenant_id, label, company_id, sas_enabled, sas_host, sas_username, sas_password,
                      sas_parent_id, sas_default_password, sas_activate_units, sas_extend_method,
                      sas_extend_profile_id, sas_on_failure, is_default)
                     VALUES
                     (:t, :l, :c, :en, :h, :u, :p, :pid, :dp, :au, :em, :ep, :of, 1)'
                );
                $st->execute(array(
                    ':t' => $tid,
                    ':l' => isset($t['name']) && $t['name'] !== '' ? $t['name'] : 'حساب ساس 1',
                    ':c' => !empty($t['sas_company_id']) ? (int) $t['sas_company_id'] : null,
                    ':en' => !empty($t['sas_enabled']) ? 1 : 0,
                    ':h' => isset($t['sas_host']) ? (string) $t['sas_host'] : '',
                    ':u' => isset($t['sas_username']) ? (string) $t['sas_username'] : '',
                    ':p' => isset($t['sas_password']) ? (string) $t['sas_password'] : '',
                    ':pid' => isset($t['sas_parent_id']) ? (int) $t['sas_parent_id'] : 1,
                    ':dp' => isset($t['sas_default_password']) && $t['sas_default_password'] !== ''
                        ? (string) $t['sas_default_password'] : '1234',
                    ':au' => isset($t['sas_activate_units']) ? max(1, (int) $t['sas_activate_units']) : 1,
                    ':em' => (isset($t['sas_extend_method']) && $t['sas_extend_method'] === 'credit')
                        ? 'credit' : 'reward_points',
                    ':ep' => isset($t['sas_extend_profile_id']) ? (int) $t['sas_extend_profile_id'] : 0,
                    ':of' => (isset($t['sas_on_failure']) && $t['sas_on_failure'] === 'rollback')
                        ? 'rollback' : 'warn',
                ));
            }
        }
    } catch (Exception $e) {
    }
}

function tenant_sas_accounts_list($pdo, $tenantId)
{
    ensure_tenant_sas_accounts_schema($pdo);
    $tenantId = max(1, (int) $tenantId);
    try {
        $st = $pdo->prepare(
            'SELECT * FROM tenant_sas_accounts WHERE tenant_id = :t ORDER BY is_default DESC, id ASC'
        );
        $st->execute(array(':t' => $tenantId));
        $rows = $st->fetchAll();
        return is_array($rows) ? $rows : array();
    } catch (Exception $e) {
        return array();
    }
}

function tenant_sas_account_row($pdo, $accountId, $tenantId = null)
{
    ensure_tenant_sas_accounts_schema($pdo);
    $accountId = (int) $accountId;
    if ($accountId <= 0) {
        return null;
    }
    try {
        if ($tenantId !== null) {
            $st = $pdo->prepare(
                'SELECT * FROM tenant_sas_accounts WHERE id = :id AND tenant_id = :t LIMIT 1'
            );
            $st->execute(array(':id' => $accountId, ':t' => (int) $tenantId));
        } else {
            $st = $pdo->prepare('SELECT * FROM tenant_sas_accounts WHERE id = :id LIMIT 1');
            $st->execute(array(':id' => $accountId));
        }
        $row = $st->fetch();
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}

function tenant_sas_account_default($pdo, $tenantId)
{
    $list = tenant_sas_accounts_list($pdo, $tenantId);
    foreach ($list as $r) {
        if (!empty($r['is_default'])) {
            return $r;
        }
    }
    return $list ? $list[0] : null;
}

function tenant_sas_accounts_ready($pdo, $tenantId)
{
    $out = array();
    foreach (tenant_sas_accounts_list($pdo, $tenantId) as $r) {
        if (!empty($r['sas_enabled'])
            && trim((string) $r['sas_host']) !== ''
            && trim((string) $r['sas_username']) !== ''
            && (string) $r['sas_password'] !== ''
        ) {
            $out[] = $r;
        }
    }
    return $out;
}

function sas_config_from_account_row($row)
{
    if (!$row || !is_array($row)) {
        return array(
            'enabled' => false,
            'host' => '',
            'username' => '',
            'password' => '',
            'parent_id' => 1,
            'default_password' => '1234',
            'activate_units' => 1,
            'extend_method' => 'reward_points',
            'extend_profile_id' => 0,
            'on_failure' => 'warn',
            'account_id' => 0,
            'tenant_id' => 0,
        );
    }
    return array(
        'enabled' => !empty($row['sas_enabled']),
        'host' => preg_replace('#^https?://#i', '', rtrim(trim((string) $row['sas_host']), '/')),
        'username' => trim((string) $row['sas_username']),
        'password' => (string) $row['sas_password'],
        'parent_id' => isset($row['sas_parent_id']) ? (int) $row['sas_parent_id'] : 1,
        'default_password' => isset($row['sas_default_password']) && $row['sas_default_password'] !== ''
            ? (string) $row['sas_default_password'] : '1234',
        'activate_units' => isset($row['sas_activate_units']) ? max(0, (int) $row['sas_activate_units']) : 1,
        'extend_method' => (isset($row['sas_extend_method']) && $row['sas_extend_method'] === 'credit')
            ? 'credit' : 'reward_points',
        'extend_profile_id' => isset($row['sas_extend_profile_id']) ? (int) $row['sas_extend_profile_id'] : 0,
        'on_failure' => (isset($row['sas_on_failure']) && $row['sas_on_failure'] === 'rollback')
            ? 'rollback' : 'warn',
        'account_id' => isset($row['id']) ? (int) $row['id'] : 0,
        'tenant_id' => isset($row['tenant_id']) ? (int) $row['tenant_id'] : 0,
        'label' => isset($row['label']) ? (string) $row['label'] : '',
        'company_id' => !empty($row['company_id']) ? (int) $row['company_id'] : 0,
    );
}

/**
 * انسخ الحساب الافتراضي إلى صف tenants (للتوافق مع الكود القديم)
 */
function tenant_sas_accounts_mirror_default($pdo, $tenantId)
{
    $def = tenant_sas_account_default($pdo, $tenantId);
    if (!$def || !function_exists('tenant_save')) {
        return false;
    }
    return tenant_save($pdo, (int) $tenantId, array(
        'sas_enabled' => !empty($def['sas_enabled']) ? 1 : 0,
        'sas_host' => isset($def['sas_host']) ? $def['sas_host'] : '',
        'sas_username' => isset($def['sas_username']) ? $def['sas_username'] : '',
        'sas_password' => isset($def['sas_password']) ? $def['sas_password'] : '',
        'sas_parent_id' => isset($def['sas_parent_id']) ? (int) $def['sas_parent_id'] : 1,
        'sas_default_password' => isset($def['sas_default_password']) ? $def['sas_default_password'] : '1234',
        'sas_activate_units' => isset($def['sas_activate_units']) ? (int) $def['sas_activate_units'] : 1,
        'sas_extend_method' => isset($def['sas_extend_method']) ? $def['sas_extend_method'] : 'reward_points',
        'sas_extend_profile_id' => isset($def['sas_extend_profile_id']) ? (int) $def['sas_extend_profile_id'] : 0,
        'sas_on_failure' => isset($def['sas_on_failure']) ? $def['sas_on_failure'] : 'warn',
        'sas_company_id' => !empty($def['company_id']) ? (int) $def['company_id'] : null,
    ));
}

function tenant_sas_account_set_default($pdo, $tenantId, $accountId)
{
    ensure_tenant_sas_accounts_schema($pdo);
    $tenantId = (int) $tenantId;
    $accountId = (int) $accountId;
    try {
        $pdo->prepare('UPDATE tenant_sas_accounts SET is_default = 0 WHERE tenant_id = :t')
            ->execute(array(':t' => $tenantId));
        $pdo->prepare('UPDATE tenant_sas_accounts SET is_default = 1 WHERE id = :id AND tenant_id = :t')
            ->execute(array(':id' => $accountId, ':t' => $tenantId));
        tenant_sas_accounts_mirror_default($pdo, $tenantId);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * @return array(bool ok, string msg, int id)
 */
function tenant_sas_account_save($pdo, $tenantId, $fields, $accountId = 0)
{
    ensure_tenant_sas_accounts_schema($pdo);
    $tenantId = max(1, (int) $tenantId);
    $accountId = (int) $accountId;
    $label = isset($fields['label']) ? trim((string) $fields['label']) : '';
    $host = isset($fields['sas_host'])
        ? preg_replace('#^https?://#i', '', rtrim(trim((string) $fields['sas_host']), '/'))
        : '';
    $user = isset($fields['sas_username']) ? trim((string) $fields['sas_username']) : '';
    $pass = isset($fields['sas_password']) ? (string) $fields['sas_password'] : '';
    $companyId = !empty($fields['company_id']) ? (int) $fields['company_id'] : null;
    if ($host === '' || $user === '') {
        return array(false, 'الهوست ويوزر الساس مطلوبين', 0);
    }
    if ($label === '') {
        $label = $user . '@' . $host;
    }
    $parentId = isset($fields['sas_parent_id']) ? (int) $fields['sas_parent_id'] : 1;
    if ($parentId <= 0) {
        $parentId = 1;
    }
    $enabled = !empty($fields['sas_enabled']) ? 1 : 1;
    $defPass = isset($fields['sas_default_password']) && trim((string) $fields['sas_default_password']) !== ''
        ? trim((string) $fields['sas_default_password']) : '1234';
    $units = isset($fields['sas_activate_units']) ? max(1, (int) $fields['sas_activate_units']) : 1;
    $extMethod = (isset($fields['sas_extend_method']) && $fields['sas_extend_method'] === 'credit')
        ? 'credit' : 'reward_points';
    $extProf = isset($fields['sas_extend_profile_id']) ? (int) $fields['sas_extend_profile_id'] : 0;
    $onFail = (isset($fields['sas_on_failure']) && $fields['sas_on_failure'] === 'rollback')
        ? 'rollback' : 'warn';
    $makeDefault = !empty($fields['is_default']);

    try {
        if ($accountId > 0) {
            $prev = tenant_sas_account_row($pdo, $accountId, $tenantId);
            if (!$prev) {
                return array(false, 'الحساب غير موجود', 0);
            }
            if ($pass === '') {
                $pass = (string) $prev['sas_password'];
            }
            if ($pass === '') {
                return array(false, 'باسورد الساس مطلوب', 0);
            }
            $pdo->prepare(
                'UPDATE tenant_sas_accounts SET
                    label = :l, company_id = :c, sas_enabled = :en, sas_host = :h,
                    sas_username = :u, sas_password = :p, sas_parent_id = :pid,
                    sas_default_password = :dp, sas_activate_units = :au,
                    sas_extend_method = :em, sas_extend_profile_id = :ep, sas_on_failure = :of
                 WHERE id = :id AND tenant_id = :t'
            )->execute(array(
                ':l' => $label,
                ':c' => $companyId,
                ':en' => $enabled,
                ':h' => $host,
                ':u' => $user,
                ':p' => $pass,
                ':pid' => $parentId,
                ':dp' => $defPass,
                ':au' => $units,
                ':em' => $extMethod,
                ':ep' => $extProf,
                ':of' => $onFail,
                ':id' => $accountId,
                ':t' => $tenantId,
            ));
            $id = $accountId;
        } else {
            if ($pass === '') {
                return array(false, 'باسورد الساس مطلوب', 0);
            }
            $list = tenant_sas_accounts_list($pdo, $tenantId);
            if (!$list) {
                $makeDefault = true;
            }
            $pdo->prepare(
                'INSERT INTO tenant_sas_accounts
                 (tenant_id, label, company_id, sas_enabled, sas_host, sas_username, sas_password,
                  sas_parent_id, sas_default_password, sas_activate_units, sas_extend_method,
                  sas_extend_profile_id, sas_on_failure, is_default)
                 VALUES
                 (:t, :l, :c, :en, :h, :u, :p, :pid, :dp, :au, :em, :ep, :of, :def)'
            )->execute(array(
                ':t' => $tenantId,
                ':l' => $label,
                ':c' => $companyId,
                ':en' => $enabled,
                ':h' => $host,
                ':u' => $user,
                ':p' => $pass,
                ':pid' => $parentId,
                ':dp' => $defPass,
                ':au' => $units,
                ':em' => $extMethod,
                ':ep' => $extProf,
                ':of' => $onFail,
                ':def' => $makeDefault ? 1 : 0,
            ));
            $id = (int) $pdo->lastInsertId();
        }
        if ($makeDefault && $id > 0) {
            tenant_sas_account_set_default($pdo, $tenantId, $id);
        } else {
            tenant_sas_accounts_mirror_default($pdo, $tenantId);
        }
        return array(true, 'تم الحفظ', $id);
    } catch (Exception $e) {
        return array(false, 'فشل الحفظ: ' . $e->getMessage(), 0);
    }
}

function tenant_sas_account_delete($pdo, $tenantId, $accountId)
{
    ensure_tenant_sas_accounts_schema($pdo);
    $tenantId = (int) $tenantId;
    $accountId = (int) $accountId;
    $row = tenant_sas_account_row($pdo, $accountId, $tenantId);
    if (!$row) {
        return array(false, 'الحساب غير موجود');
    }
    try {
        $pdo->prepare('DELETE FROM tenant_sas_accounts WHERE id = :id AND tenant_id = :t')
            ->execute(array(':id' => $accountId, ':t' => $tenantId));
        $left = tenant_sas_accounts_list($pdo, $tenantId);
        if ($left) {
            $hasDef = false;
            foreach ($left as $r) {
                if (!empty($r['is_default'])) {
                    $hasDef = true;
                    break;
                }
            }
            if (!$hasDef) {
                tenant_sas_account_set_default($pdo, $tenantId, (int) $left[0]['id']);
            } else {
                tenant_sas_accounts_mirror_default($pdo, $tenantId);
            }
        } else {
            if (function_exists('tenant_save')) {
                tenant_save($pdo, $tenantId, array(
                    'sas_enabled' => 0,
                    'sas_host' => '',
                    'sas_username' => '',
                    'sas_password' => '',
                    'sas_company_id' => null,
                ));
            }
        }
        return array(true, 'تم الحذف');
    } catch (Exception $e) {
        return array(false, 'فشل الحذف');
    }
}

function sas_make_connector_from_account($accountRow)
{
    if (!class_exists('SASConnector')) {
        return null;
    }
    $s = sas_config_from_account_row($accountRow);
    if ($s['host'] === '' || $s['username'] === '' || $s['password'] === '') {
        return null;
    }
    return new SASConnector($s['host'], $s['username'], $s['password'], 'acp');
}
