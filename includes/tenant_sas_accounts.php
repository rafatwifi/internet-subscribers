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
            tenants_ensure_column($pdo, 'tenant_sas_accounts', 'last_ok_at', 'DATETIME NULL DEFAULT NULL');
            tenants_ensure_column($pdo, 'tenant_sas_accounts', 'last_error', 'VARCHAR(255) NULL DEFAULT NULL');
            tenants_ensure_column($pdo, 'tenant_sas_accounts', 'ping_mode', "VARCHAR(16) NOT NULL DEFAULT 'off'");
            tenants_ensure_column($pdo, 'tenant_sas_accounts', 'login_fail_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
            tenants_ensure_column($pdo, 'tenant_sas_accounts', 'login_next_retry_at', 'DATETIME NULL DEFAULT NULL');
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

function tenant_sas_host_norm($host)
{
    $host = strtolower(trim((string) $host));
    $host = preg_replace('#^https?://#', '', $host);
    $host = rtrim($host, '/');
    $slash = strpos($host, '/');
    if ($slash !== false) {
        $host = substr($host, 0, $slash);
    }
    $host = preg_replace('#:(80|443)$#', '', $host);
    return $host;
}

function tenant_sas_account_key($host, $user)
{
    $host = tenant_sas_host_norm($host);
    $user = strtolower(trim((string) $user));
    if ($host === '' || $user === '') {
        return '';
    }
    return $user . "\n" . $host;
}

function tenant_sas_account_keep_better($row, $keep)
{
    $rowCo = !empty($row['company_id']) ? 1 : 0;
    $keepCo = !empty($keep['company_id']) ? 1 : 0;
    if ($rowCo !== $keepCo) {
        return $rowCo > $keepCo;
    }
    $rowDef = !empty($row['is_default']) ? 1 : 0;
    $keepDef = !empty($keep['is_default']) ? 1 : 0;
    if ($rowDef !== $keepDef) {
        return $rowDef > $keepDef;
    }
    $rowOk = isset($row['last_ok_at']) ? (string) $row['last_ok_at'] : '';
    $keepOk = isset($keep['last_ok_at']) ? (string) $keep['last_ok_at'] : '';
    if ($rowOk !== $keepOk) {
        return $rowOk > $keepOk;
    }
    return (int) $row['id'] > (int) $keep['id'];
}

/** نفس اليوزر والهوست = حساب واحد. يحذف النسخ الزائدة ويبقي الافتراضي أو آخر دخول ناجح. */
function tenant_sas_accounts_dedupe($pdo, $tenantId)
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0 || !$pdo) {
        return;
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, sas_host, sas_username, is_default, last_ok_at, company_id, label
             FROM tenant_sas_accounts WHERE tenant_id = :t ORDER BY id ASC'
        );
        $st->execute(array(':t' => $tenantId));
        $rows = $st->fetchAll();
    } catch (Exception $e) {
        return;
    }
    if (!is_array($rows) || count($rows) < 2) {
        return;
    }
    $groups = array();
    foreach ($rows as $r) {
        $key = tenant_sas_account_key(isset($r['sas_host']) ? $r['sas_host'] : '', isset($r['sas_username']) ? $r['sas_username'] : '');
        if ($key === '') {
            continue;
        }
        if (!isset($groups[$key])) {
            $groups[$key] = array();
        }
        $groups[$key][] = $r;
    }
    foreach ($groups as $list) {
        if (count($list) < 2) {
            continue;
        }
        $keep = $list[0];
        foreach ($list as $r) {
            if (tenant_sas_account_keep_better($r, $keep)) {
                $keep = $r;
            }
        }
        $drop = array();
        foreach ($list as $r) {
            if ((int) $r['id'] !== (int) $keep['id']) {
                $drop[] = (int) $r['id'];
            }
        }
        if (!$drop) {
            continue;
        }
        $in = implode(',', $drop);
        $keepId = (int) $keep['id'];
        $normHost = tenant_sas_host_norm(isset($keep['sas_host']) ? $keep['sas_host'] : '');
        try {
            if ($normHost !== '') {
                $pdo->prepare(
                    'UPDATE tenant_sas_accounts SET sas_host = :h WHERE id = :id AND tenant_id = :t'
                )->execute(array(
                    ':h' => $normHost,
                    ':id' => $keepId,
                    ':t' => $tenantId,
                ));
            }
        } catch (Exception $e) {
        }
        try {
            $pdo->exec(
                'UPDATE sas_users_cache SET sas_account_id = ' . $keepId
                . ' WHERE tenant_id = ' . $tenantId . ' AND sas_account_id IN (' . $in . ')'
            );
        } catch (Exception $e) {
        }
        try {
            $pdo->exec('DELETE FROM tenant_sas_accounts WHERE tenant_id = ' . $tenantId . ' AND id IN (' . $in . ')');
        } catch (Exception $e) {
        }
    }
}

function tenant_sas_accounts_list($pdo, $tenantId)
{
    ensure_tenant_sas_accounts_schema($pdo);
    $tenantId = max(1, (int) $tenantId);
    tenant_sas_accounts_dedupe($pdo, $tenantId);
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

/**
 * نوع مزود الحساب: sas | earthlink
 * لا تعتمد فقط على company_id — الهوست يكفي لكشف إيرث.
 */
function tenant_sas_account_provider($pdo, $acc)
{
    if (!is_array($acc)) {
        return 'sas';
    }
    $host = strtolower(preg_replace('#^https?://#i', '', rtrim(trim((string) (isset($acc['sas_host']) ? $acc['sas_host'] : '')), '/')));
    if ($host !== '' && (
        strpos($host, 'earthlink') !== false
        || strpos($host, 'spicywifi') !== false
        || strpos($host, 'earthlink-api') !== false
    )) {
        return 'earthlink';
    }
    $cid = !empty($acc['company_id']) ? (int) $acc['company_id'] : 0;
    if ($cid > 0 && function_exists('platform_company_row')) {
        $co = platform_company_row($pdo, $cid);
        if ($co && function_exists('platform_company_normalize_provider')) {
            return platform_company_normalize_provider(
                isset($co['provider_type']) ? $co['provider_type'] : 'sas'
            );
        }
    }
    return 'sas';
}

function tenant_sas_account_is_earthlink($pdo, $acc)
{
    return tenant_sas_account_provider($pdo, $acc) === 'earthlink';
}

/** حسابات جاهزة من نوع SAS فقط (لإشعارات/مزامنة الساس). */
function tenant_sas_accounts_ready_sas($pdo, $tenantId)
{
    $out = array();
    foreach (tenant_sas_accounts_ready($pdo, $tenantId) as $r) {
        if (!tenant_sas_account_is_earthlink($pdo, $r)) {
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
            $wantKey = tenant_sas_account_key($host, $user);
            foreach ($list as $ex) {
                $exKey = tenant_sas_account_key(
                    isset($ex['sas_host']) ? $ex['sas_host'] : '',
                    isset($ex['sas_username']) ? $ex['sas_username'] : ''
                );
                if ($wantKey !== '' && $exKey === $wantKey) {
                    return tenant_sas_account_save($pdo, $tenantId, $fields, (int) $ex['id']);
                }
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
        if (function_exists('sas_profiles_cache_clear')) {
            sas_profiles_cache_clear();
        }
        $ftthFile = dirname(__DIR__) . '/storage/cache/ftth_profiles_t' . (int) $tenantId . '.json';
        if (is_file($ftthFile)) {
            @unlink($ftthFile);
        }
        return array(true, 'تم الحفظ', $id);
    } catch (Exception $e) {
        return array(false, 'فشل الحفظ: ' . $e->getMessage(), 0);
    }
}

/**
 * تأخير إعادة المحاولة بالثواني حسب رقم الفشل (بعد هذه المحاولة).
 * 1–10: كل دقيقة، 11–22: كل 5 دقائق، بعدها كل 15 دقيقة.
 */
function tenant_sas_login_retry_delay_sec($failCount)
{
    $n = (int) $failCount;
    if ($n <= 0) {
        $n = 1;
    }
    if ($n <= 10) {
        return 60;
    }
    if ($n <= 22) {
        return 300;
    }
    return 900;
}

function tenant_sas_login_retry_schedule_sql($failCount)
{
    $sec = tenant_sas_login_retry_delay_sec($failCount);
    return date('Y-m-d H:i:s', time() + $sec);
}

/**
 * إعادة محاولة الدخول بنفس بيانات الحساب المحفوظة (SAS أو Earthlink).
 * @return array {0:bool ok, 1:string message, 2?:array meta}
 */
function tenant_sas_account_retry_login($pdo, $tenantId, $accountId, $config = null)
{
    ensure_tenant_sas_accounts_schema($pdo);
    $tenantId = (int) $tenantId;
    $accountId = (int) $accountId;
    $row = tenant_sas_account_row($pdo, $accountId, $tenantId);
    if (!$row) {
        return array(false, 'الحساب غير موجود', array());
    }
    $host = trim((string) (isset($row['sas_host']) ? $row['sas_host'] : ''));
    $user = trim((string) (isset($row['sas_username']) ? $row['sas_username'] : ''));
    $pass = (string) (isset($row['sas_password']) ? $row['sas_password'] : '');
    if ($host === '' || $user === '' || $pass === '') {
        return array(false, 'بيانات الدخول ناقصة — احذف الحساب وأضفه من جديد', array());
    }
    $isEl = function_exists('tenant_sas_account_is_earthlink')
        && tenant_sas_account_is_earthlink($pdo, $row);
    $ok = false;
    $msg = '';
    if ($isEl && function_exists('earthlink_test_connection')) {
        $cid = !empty($row['company_id']) ? (int) $row['company_id'] : 0;
        $alt = ($cid > 0 && function_exists('earthlink_company_alt_host'))
            ? earthlink_company_alt_host($pdo, $cid)
            : '';
        $test = earthlink_test_connection($host, $user, $pass, $alt);
        $ok = !empty($test['ok']);
        $msg = isset($test['message']) ? (string) $test['message'] : '';
        if (!$ok && empty($msg) && isset($test['error'])) {
            $msg = (string) $test['error'];
        }
    } elseif (function_exists('tenant_test_sas_connection')) {
        list($ok, $msg) = tenant_test_sas_connection($host, $user, $pass);
    } else {
        return array(false, 'دالة فحص الاتصال غير موجودة', array());
    }
    $errStore = $ok
        ? null
        : (function_exists('mb_substr')
            ? mb_substr((string) $msg, 0, 250, 'UTF-8')
            : substr((string) $msg, 0, 250));
    $prevFails = isset($row['login_fail_count']) ? (int) $row['login_fail_count'] : 0;
    try {
        if ($ok) {
            $pdo->prepare(
                'UPDATE tenant_sas_accounts
                 SET sas_enabled = 1, last_ok_at = NOW(), last_error = NULL,
                     login_fail_count = 0, login_next_retry_at = NULL, updated_at = NOW()
                 WHERE id = :id AND tenant_id = :t'
            )->execute(array(':id' => $accountId, ':t' => $tenantId));
            if ($isEl && function_exists('earthlink_sync_to_cache')) {
                $cid = !empty($row['company_id']) ? (int) $row['company_id'] : 0;
                // مزامنة خفيفة لاحقاً — لا تمنع ردّ النجاح
                try {
                    earthlink_sync_to_cache($pdo, $tenantId, $host, $user, $pass, $cid);
                } catch (Exception $eSync) {
                }
            }
            if (!$isEl && function_exists('sas_mark_connection') && is_array($config)) {
                sas_mark_connection($pdo, $config, true, $msg !== '' ? $msg : 'ok', $tenantId);
            }
            return array(true, $msg !== '' ? $msg : 'تم الدخول بنجاح', array('fail_count' => 0));
        }
        $failCount = $prevFails + 1;
        $nextAt = tenant_sas_login_retry_schedule_sql($failCount);
        $pdo->prepare(
            'UPDATE tenant_sas_accounts
             SET last_ok_at = NULL, last_error = :e,
                 login_fail_count = :fc, login_next_retry_at = :nr, updated_at = NOW()
             WHERE id = :id AND tenant_id = :t'
        )->execute(array(
            ':e' => $errStore,
            ':fc' => $failCount,
            ':nr' => $nextAt,
            ':id' => $accountId,
            ':t' => $tenantId,
        ));
        if (!$isEl && function_exists('sas_mark_connection') && is_array($config)) {
            sas_mark_connection($pdo, $config, false, (string) $msg, $tenantId);
        }
        return array(false, $msg !== '' ? $msg : 'فشل الدخول', array(
            'fail_count' => $failCount,
            'next_retry_at' => $nextAt,
            'next_retry_sec' => tenant_sas_login_retry_delay_sec($failCount),
        ));
    } catch (Exception $e) {
        return array(false, 'تعذر حفظ نتيجة المحاولة', array());
    }
}

/**
 * حسابات فاشلة حان وقت إعادة محاولتها — خفيف: حد أقصى قليل لكل دورة.
 * @return int عدد المحاولات التي نُفّذت
 */
function tenant_sas_accounts_retry_due($pdo, $config = null, $max = 1)
{
    ensure_tenant_sas_accounts_schema($pdo);
    $max = max(1, min(3, (int) $max));
    $rows = array();
    try {
        $st = $pdo->query(
            "SELECT id, tenant_id, last_error, login_fail_count, login_next_retry_at
             FROM tenant_sas_accounts
             WHERE sas_enabled = 1
               AND sas_username IS NOT NULL AND TRIM(sas_username) <> ''
               AND sas_password IS NOT NULL AND sas_password <> ''
               AND last_error IS NOT NULL AND TRIM(last_error) <> ''
               AND (login_next_retry_at IS NULL OR login_next_retry_at <= NOW())
             ORDER BY COALESCE(login_next_retry_at, '1970-01-01') ASC, id ASC
             LIMIT " . (int) $max
        );
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        return 0;
    }
    $n = 0;
    foreach ($rows as $r) {
        $tid = (int) $r['tenant_id'];
        $aid = (int) $r['id'];
        if ($tid <= 0 || $aid <= 0) {
            continue;
        }
        // إن ماكو موعد بعد — حدّد أول محاولة بعد دقيقة بدون فحص ثقيل الآن إن فشل حديث جداً
        if (empty($r['login_next_retry_at'])) {
            $updated = 0;
            try {
                $chk = $pdo->prepare(
                    'SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW()) FROM tenant_sas_accounts WHERE id = :id LIMIT 1'
                );
                $chk->execute(array(':id' => $aid));
                $updated = (int) $chk->fetchColumn();
            } catch (Exception $eAge) {
                $updated = 60;
            }
            if ($updated >= 0 && $updated < 45) {
                $next = tenant_sas_login_retry_schedule_sql(max(1, (int) $r['login_fail_count']));
                try {
                    $pdo->prepare(
                        'UPDATE tenant_sas_accounts SET login_next_retry_at = :nr WHERE id = :id'
                    )->execute(array(':nr' => $next, ':id' => $aid));
                } catch (Exception $eSet) {
                }
                continue;
            }
        }
        $cfg = $config;
        if (is_array($config) && function_exists('sas_config_for_tenant')) {
            try {
                $cfg = sas_config_for_tenant($pdo, $config, $tid);
            } catch (Exception $eCfg) {
                $cfg = $config;
            }
        }
        tenant_sas_account_retry_login($pdo, $tid, $aid, $cfg);
        $n++;
        // استراحة قصيرة بين حسابين حتى ما نضغط الشبكة/المعالج
        if ($n < $max) {
            usleep(200000);
        }
    }
    return $n;
}

function tenant_sas_account_logout($pdo, $tenantId, $accountId)
{
    ensure_tenant_sas_accounts_schema($pdo);
    $tenantId = (int) $tenantId;
    $accountId = (int) $accountId;
    $row = tenant_sas_account_row($pdo, $accountId, $tenantId);
    if (!$row) {
        return array(false, 'الحساب غير موجود');
    }
    try {
        $pdo->prepare(
            'UPDATE tenant_sas_accounts SET sas_enabled = 0, is_default = 0, updated_at = NOW() WHERE id = :id AND tenant_id = :t'
        )->execute(array(':id' => $accountId, ':t' => $tenantId));
        $left = tenant_sas_accounts_list($pdo, $tenantId);
        $hasDef = false;
        foreach ($left as $r) {
            if (!empty($r['is_default']) && !empty($r['sas_enabled'])) {
                $hasDef = true;
                break;
            }
        }
        if (!$hasDef) {
            foreach ($left as $r) {
                if (!empty($r['sas_enabled'])) {
                    tenant_sas_account_set_default($pdo, $tenantId, (int) $r['id']);
                    break;
                }
            }
        }
        if (function_exists('tenant_sas_accounts_mirror_default')) {
            tenant_sas_accounts_mirror_default($pdo, $tenantId);
        }
        return array(true, 'تم تسجيل الخروج');
    } catch (Exception $e) {
        return array(false, 'تعذر تسجيل الخروج');
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
