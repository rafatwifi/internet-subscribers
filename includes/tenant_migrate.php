<?php

/**
 * ترحيل بيانات الشركة التشغيلية (tenant 1) إلى وكالة جديدة — بدون مسح ديون/فواتير
 */

function tenant_migrate_count_owner_data($pdo)
{
    $out = array(
        'subscribers' => 0,
        'sas_cache' => 0,
        'agents' => 0,
        'staff' => 0,
        'card_stock' => 0,
        'card_transfers' => 0,
        'card_payments' => 0,
        'card_prices' => 0,
        'invoices_unpaid' => 0,
    );
    try {
        $out['subscribers'] = (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE tenant_id = 1')->fetchColumn();
    } catch (Exception $e) {
    }
    try {
        $out['sas_cache'] = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = 1')->fetchColumn();
    } catch (Exception $e) {
    }
    try {
        $out['agents'] = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE tenant_id = 1 AND role = 'agent'")->fetchColumn();
        $out['staff'] = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE tenant_id = 1 AND role IN ('staff','manager','accountant')")->fetchColumn();
    } catch (Exception $e) {
    }
    try {
        $out['card_stock'] = (int) $pdo->query('SELECT COUNT(*) FROM agent_card_stock WHERE tenant_id = 1')->fetchColumn();
        $out['card_transfers'] = (int) $pdo->query('SELECT COUNT(*) FROM agent_card_transfers WHERE tenant_id = 1')->fetchColumn();
        $out['card_payments'] = (int) $pdo->query('SELECT COUNT(*) FROM agent_card_payments WHERE tenant_id = 1')->fetchColumn();
        $out['card_prices'] = (int) $pdo->query('SELECT COUNT(*) FROM agent_card_prices WHERE tenant_id = 1')->fetchColumn();
    } catch (Exception $e) {
    }
    try {
        $out['invoices_unpaid'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM invoices i
             JOIN subscribers s ON s.id = i.subscriber_id
             WHERE s.tenant_id = 1 AND i.status = 'unpaid'"
        )->fetchColumn();
    } catch (Exception $e) {
    }
    return $out;
}

/**
 * إنشاء وكالة تشغيلية ونقل بيانات tenant 1 إليها
 * @return array(bool ok, string msg, int newTenantId, int newUserId)
 */
function tenant_migrate_owner_to_agency($pdo, $config, $username, $displayName, $password, $agencyName, $keepAdminIds)
{
    ensure_tenants_schema($pdo, $config);
    $username = trim((string) $username);
    $displayName = trim((string) $displayName);
    $agencyName = trim((string) $agencyName);
    $password = (string) $password;
    if ($agencyName === '') {
        $agencyName = 'WiFi Office';
    }
    if ($username === '' || strlen($password) < 4) {
        return array(false, 'يوزر وباسورد مطلوبين (باسورد 4 أحرف على الأقل)', 0, 0);
    }
    if (!preg_match('/^[a-zA-Z0-9._@-]{2,40}$/', $username)) {
        return array(false, 'اسم الدخول غير صالح', 0, 0);
    }
    if (!is_array($keepAdminIds)) {
        $keepAdminIds = array();
    }
    $keepAdminIds = array_values(array_filter(array_map('intval', $keepAdminIds)));

    try {
        $ex = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u LIMIT 1');
        $ex->execute(array(':u' => $username));
        if ($ex->fetchColumn()) {
            return array(false, 'اسم الدخول مستخدم', 0, 0);
        }
    } catch (Exception $e) {
        return array(false, 'تعذر التحقق من اليوزر', 0, 0);
    }

    // انسخ إعدادات ساس الحالية من tenant 1 / config
    $sas = function_exists('sas_config_for_tenant')
        ? sas_config_for_tenant($pdo, $config, 1)
        : (function_exists('sas_config') ? sas_config($config) : array());

    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare(
            'INSERT INTO tenants (name, is_active, status, sas_enabled, sas_host, sas_username, sas_password,
                sas_parent_id, sas_default_password, sas_activate_units, sas_extend_method, sas_extend_profile_id, sas_on_failure,
                trial_ends_at, subscription_expires_at)
             VALUES (:n, 1, "active", :en, :h, :u, :p, :pid, :dp, :au, :em, :ep, :of, :tr, :tr2)'
        );
        $trialEnd = date('Y-m-d H:i:s', time() + (3650 * 86400)); // طويل — حسابك التشغيلي
        $st->execute(array(
            ':n' => $agencyName,
            ':en' => !empty($sas['enabled']) ? 1 : 0,
            ':h' => isset($sas['host']) ? $sas['host'] : '',
            ':u' => isset($sas['username']) ? $sas['username'] : '',
            ':p' => isset($sas['password']) ? $sas['password'] : '',
            ':pid' => isset($sas['parent_id']) ? (int) $sas['parent_id'] : 1,
            ':dp' => isset($sas['default_password']) ? $sas['default_password'] : '1234',
            ':au' => isset($sas['activate_units']) ? (int) $sas['activate_units'] : 1,
            ':em' => (isset($sas['extend_method']) && $sas['extend_method'] === 'credit') ? 'credit' : 'reward_points',
            ':ep' => isset($sas['extend_profile_id']) ? (int) $sas['extend_profile_id'] : 0,
            ':of' => (isset($sas['on_failure']) && $sas['on_failure'] === 'rollback') ? 'rollback' : 'warn',
            ':tr' => $trialEnd,
            ':tr2' => $trialEnd,
        ));
        $newTid = (int) $pdo->lastInsertId();
        if ($newTid <= 1) {
            $pdo->rollBack();
            return array(false, 'تعذر إنشاء الوكالة', 0, 0);
        }

        $hash = function_exists('admin_password_hash') ? admin_password_hash($password) : password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO admin_users (username, display_name, password_hash, role, is_active, tenant_id)
             VALUES (:u, :d, :h, "admin", 1, :t)'
        )->execute(array(
            ':u' => $username,
            ':d' => $displayName !== '' ? $displayName : $agencyName,
            ':h' => $hash,
            ':t' => $newTid,
        ));
        $newUid = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE tenants SET owner_user_id = :u WHERE id = :id')
            ->execute(array(':u' => $newUid, ':id' => $newTid));

        try {
            $pdo->prepare('INSERT INTO sas_sync_meta (id, tenant_id, last_count) VALUES (:id, :t, 0)')
                ->execute(array(':id' => min(255, $newTid), ':t' => $newTid));
        } catch (Exception $e) {
        }

        // انقل المشتركين والكاش
        $pdo->prepare('UPDATE subscribers SET tenant_id = :n WHERE tenant_id = 1')->execute(array(':n' => $newTid));
        $pdo->prepare('UPDATE sas_users_cache SET tenant_id = :n WHERE tenant_id = 1')->execute(array(':n' => $newTid));

        // انقل موظفي/وكلاء الشركة — استثنِ أدمنز المنصة المحفوظين
        $keepSql = '';
        $params = array(':n' => $newTid);
        if ($keepAdminIds) {
            $ins = array();
            foreach ($keepAdminIds as $i => $kid) {
                $k = ':k' . $i;
                $ins[] = $k;
                $params[$k] = $kid;
            }
            $keepSql = ' AND id NOT IN (' . implode(',', $ins) . ')';
        }
        $pdo->prepare(
            "UPDATE admin_users SET tenant_id = :n
             WHERE tenant_id = 1 AND role IN ('agent','staff','manager','accountant')" . $keepSql
        )->execute($params);

        // جداول الكروت
        foreach (array('agent_card_stock', 'agent_card_transfers', 'agent_card_payments', 'agent_card_prices') as $tbl) {
            try {
                $pdo->prepare("UPDATE {$tbl} SET tenant_id = :n WHERE tenant_id = 1")->execute(array(':n' => $newTid));
            } catch (Exception $e) {
            }
        }

        // meta مزامنة tenant 1 تبقى للمنصة؛ انسخ العدد للوكالة الجديدة
        try {
            $cnt = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . (int) $newTid)->fetchColumn();
            $pdo->prepare('UPDATE sas_sync_meta SET last_count = :c, last_ok_at = NOW() WHERE tenant_id = :t')
                ->execute(array(':c' => $cnt, ':t' => $newTid));
        } catch (Exception $e) {
        }

        // فرّغ ساس الشركة 1 (المنصة) — الساس صار على الوكالة التشغيلية
        try {
            $pdo->exec(
                'UPDATE tenants SET sas_enabled = 0, sas_host = "", sas_username = "", sas_password = "" WHERE id = 1'
            );
            $pdo->prepare('UPDATE sas_sync_meta SET last_count = 0 WHERE tenant_id = 1')->execute();
        } catch (Exception $e) {
        }

        $pdo->commit();
        return array(true, 'تم إنشاء الوكالة ونقل البيانات بنجاح', $newTid, $newUid);
    } catch (Exception $e) {
        try {
            $pdo->rollBack();
        } catch (Exception $e2) {
        }
        return array(false, 'فشل الترحيل: ' . $e->getMessage(), 0, 0);
    }
}

/**
 * إذا دخلت وكالة فاضية (مثل wifi@office) والبيانات لسه على الشركة 1،
 * انقل الصفوف إليها بدون حذف مشترك أو دين.
 */
function tenant_attach_owner_data_if_empty($pdo, $config)
{
    if (empty($_SESSION['admin_logged_in']) || !function_exists('current_tenant_id')) {
        return;
    }
    $tid = (int) current_tenant_id();
    if ($tid <= 1) {
        return;
    }
    $lockFile = dirname(__DIR__) . '/storage/shop_home_tenant.txt';
    $locked = 0;
    if (is_file($lockFile)) {
        $locked = (int) trim((string) @file_get_contents($lockFile));
    }
    $mineSubs = 0;
    $mineCache = 0;
    try {
        $mineSubs = (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE tenant_id = ' . $tid)->fetchColumn();
    } catch (Exception $e) {
    }
    try {
        $mineCache = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . $tid)->fetchColumn();
    } catch (Exception $e) {
    }
    if ($mineSubs > 0 && $mineCache > 0) {
        if ($locked <= 0) {
            @file_put_contents($lockFile, (string) $tid);
        }
        return;
    }
    /* وكالة جديدة تبقى فاضية. لا تنقل مشتركين أو كاش وكالة ثانية. */
    return;
    $src = 0;
    $srcN = 0;
    try {
        $rows = $pdo->query(
            'SELECT tenant_id, COUNT(*) AS c FROM sas_users_cache GROUP BY tenant_id ORDER BY c DESC'
        )->fetchAll();
        foreach ($rows as $r) {
            $rid = (int) $r['tenant_id'];
            $c = (int) $r['c'];
            if ($rid !== $tid && $c > $srcN) {
                $src = $rid;
                $srcN = $c;
            }
        }
    } catch (Exception $e) {
        $rows = array();
    }
    if ($srcN <= 0) {
        try {
            $rows = $pdo->query(
                'SELECT tenant_id, COUNT(*) AS c FROM subscribers GROUP BY tenant_id ORDER BY c DESC'
            )->fetchAll();
            foreach ($rows as $r) {
                $rid = (int) $r['tenant_id'];
                $c = (int) $r['c'];
                if ($rid !== $tid && $c > $srcN) {
                    $src = $rid;
                    $srcN = $c;
                }
            }
        } catch (Exception $e) {
        }
    }
    if ($srcN <= 0) {
        if ($mineCache <= 0 && function_exists('sas_sync_users_from_api') && function_exists('sas_is_ready') && sas_is_ready($config)) {
            try {
                sas_sync_users_from_api($pdo, $config, true, false);
            } catch (Exception $e) {
            }
        }
        return;
    }
    if ($locked > 0 && $locked !== $tid && $mineCache > 0) {
        return;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE subscribers SET tenant_id = :n WHERE tenant_id = :s')->execute(array(':n' => $tid, ':s' => $src));
        try {
            $pdo->prepare('UPDATE sas_users_cache SET tenant_id = :n WHERE tenant_id = :s')->execute(array(':n' => $tid, ':s' => $src));
        } catch (Exception $e2) {
        }
        foreach (array('agent_card_stock', 'agent_card_transfers', 'agent_card_payments', 'agent_card_prices') as $tbl) {
            try {
                $pdo->prepare('UPDATE `' . $tbl . '` SET tenant_id = :n WHERE tenant_id = :s')->execute(array(':n' => $tid, ':s' => $src));
            } catch (Exception $e2) {
            }
        }
        try {
            $pdo->prepare('UPDATE tenant_sas_accounts SET tenant_id = :n WHERE tenant_id = :s')->execute(array(':n' => $tid, ':s' => $src));
        } catch (Exception $e2) {
        }
        if ($tid > 1 && $src > 0) {
            try {
                $st = $pdo->prepare('SELECT sas_host, sas_username, sas_password, sas_enabled FROM tenants WHERE id = :id LIMIT 1');
                $st->execute(array(':id' => $src));
                $srcRow = $st->fetch();
                $meSt = $pdo->prepare('SELECT sas_host, sas_username FROM tenants WHERE id = :id LIMIT 1');
                $meSt->execute(array(':id' => $tid));
                $meRow = $meSt->fetch();
                $meHost = $meRow && isset($meRow['sas_host']) ? trim((string) $meRow['sas_host']) : '';
                /* لا تنسخ دخول ساس من وكالة ثانية إلى وكالة فاضية */
                if (false && $srcRow && $meHost === '' && !empty($srcRow['sas_host'])) {
                    $pdo->prepare(
                        'UPDATE tenants SET sas_enabled = 1, sas_host = :h, sas_username = :u, sas_password = :p WHERE id = :id'
                    )->execute(array(
                        ':h' => $srcRow['sas_host'],
                        ':u' => $srcRow['sas_username'],
                        ':p' => $srcRow['sas_password'],
                        ':id' => $tid,
                    ));
                }
            } catch (Exception $e2) {
            }
        }
        $pdo->commit();
        @file_put_contents($lockFile, (string) $tid);
    } catch (Exception $e) {
        try {
            $pdo->rollBack();
        } catch (Exception $e2) {
        }
        return;
    }
    return;
    try {
        $mineSubs = (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE tenant_id = ' . $tid)->fetchColumn();
        $oldSubs = (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE tenant_id = 1')->fetchColumn();
        $mineCache = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = ' . $tid)->fetchColumn();
        $oldCache = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = 1')->fetchColumn();
    } catch (Exception $e) {
        return;
    }
    $mineReady = false;
    if (function_exists('sas_config_for_tenant')) {
        $mineCfg = sas_config_for_tenant($pdo, $config, $tid);
        $mineReady = !empty($mineCfg['enabled']) && $mineCfg['host'] !== '' && $mineCfg['username'] !== '' && $mineCfg['password'] !== '';
    }
    if ($mineSubs > 0 && $mineCache > 0 && $mineReady) {
        return;
    }
    if ($oldSubs <= 0 && $oldCache <= 0 && $mineReady) {
        return;
    }
    try {
        $pdo->beginTransaction();
        if ($mineSubs === 0 && $oldSubs > 0) {
            $pdo->prepare('UPDATE subscribers SET tenant_id = :n WHERE tenant_id = 1')->execute(array(':n' => $tid));
        }
        if ($mineCache === 0 && $oldCache > 0) {
            $pdo->prepare('UPDATE sas_users_cache SET tenant_id = :n WHERE tenant_id = 1')->execute(array(':n' => $tid));
        }
        foreach (array('agent_card_stock', 'agent_card_transfers', 'agent_card_payments', 'agent_card_prices') as $tbl) {
            try {
                $mineN = (int) $pdo->query('SELECT COUNT(*) FROM `' . $tbl . '` WHERE tenant_id = ' . $tid)->fetchColumn();
                $oldN = (int) $pdo->query('SELECT COUNT(*) FROM `' . $tbl . '` WHERE tenant_id = 1')->fetchColumn();
                if ($mineN === 0 && $oldN > 0) {
                    $pdo->prepare('UPDATE `' . $tbl . '` SET tenant_id = :n WHERE tenant_id = 1')->execute(array(':n' => $tid));
                }
            } catch (Exception $e2) {
            }
        }
        try {
            $mineAcc = (int) $pdo->query('SELECT COUNT(*) FROM tenant_sas_accounts WHERE tenant_id = ' . $tid)->fetchColumn();
            $oldAcc = (int) $pdo->query('SELECT COUNT(*) FROM tenant_sas_accounts WHERE tenant_id = 1')->fetchColumn();
            if ($mineAcc === 0 && $oldAcc > 0) {
                $pdo->prepare('UPDATE tenant_sas_accounts SET tenant_id = :n WHERE tenant_id = 1')->execute(array(':n' => $tid));
            }
        } catch (Exception $e2) {
        }
        if (false && !$mineReady && function_exists('sas_config_for_tenant')) {
            $src = sas_config_for_tenant($pdo, $config, 1);
            if (!empty($src['host']) && !empty($src['username']) && !empty($src['password'])) {
                $pdo->prepare(
                    'UPDATE tenants SET sas_enabled = 1, sas_host = :h, sas_username = :u, sas_password = :p,
                        sas_parent_id = :pid, sas_default_password = :dp, sas_activate_units = :au,
                        sas_extend_method = :em, sas_extend_profile_id = :ep, sas_on_failure = :of
                     WHERE id = :id'
                )->execute(array(
                    ':h' => $src['host'],
                    ':u' => $src['username'],
                    ':p' => $src['password'],
                    ':pid' => isset($src['parent_id']) ? (int) $src['parent_id'] : 1,
                    ':dp' => isset($src['default_password']) ? $src['default_password'] : '',
                    ':au' => isset($src['activate_units']) ? (int) $src['activate_units'] : 1,
                    ':em' => (isset($src['extend_method']) && $src['extend_method'] === 'credit') ? 'credit' : 'reward_points',
                    ':ep' => isset($src['extend_profile_id']) ? (int) $src['extend_profile_id'] : 0,
                    ':of' => (isset($src['on_failure']) && $src['on_failure'] === 'rollback') ? 'rollback' : 'warn',
                    ':id' => $tid,
                ));
                try {
                    $has = (int) $pdo->query('SELECT COUNT(*) FROM tenant_sas_accounts WHERE tenant_id = ' . $tid)->fetchColumn();
                    if ($has === 0 && function_exists('tenant_sas_account_save')) {
                        tenant_sas_account_save($pdo, $tid, array(
                            'label' => 'الحساب الرئيسي',
                            'sas_host' => $src['host'],
                            'sas_username' => $src['username'],
                            'sas_password' => $src['password'],
                            'is_default' => 1,
                        ));
                    }
                } catch (Exception $e3) {
                }
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        try {
            $pdo->rollBack();
        } catch (Exception $e2) {
        }
    }
}

/**
 * ملخص منصة: كروت/رأس مال عبر كل الوكالات (للسوبر أدمن)
 */
function platform_card_summary($pdo)
{
    $out = array(
        'agencies' => 0,
        'agencies_active' => 0,
        'stock_qty' => 0,
        'transfer_qty' => 0,
        'sold_amount' => 0.0,
        'wholesale_amount' => 0.0,
        'profit' => 0.0,
        'received' => 0.0,
        'remaining' => 0.0,
        'used_cards' => 0,
        'available_cards' => 0,
    );
    try {
        $out['agencies'] = (int) $pdo->query('SELECT COUNT(*) FROM tenants WHERE id > 1')->fetchColumn();
        $out['agencies_active'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM tenants WHERE id > 1 AND status = 'active'"
        )->fetchColumn();
    } catch (Exception $e) {
    }
    try {
        $out['stock_qty'] = (int) $pdo->query(
            'SELECT COALESCE(SUM(qty),0) FROM agent_card_stock'
        )->fetchColumn();
    } catch (Exception $e) {
    }
    try {
        $row = $pdo->query(
            'SELECT COALESCE(SUM(qty),0) AS q,
                    COALESCE(SUM(agent_price * qty),0) AS sold,
                    COALESCE(SUM(wholesale_price * qty),0) AS cost,
                    COALESCE(SUM((agent_price - wholesale_price) * qty),0) AS profit
             FROM agent_card_transfers'
        )->fetch();
        if ($row) {
            $out['transfer_qty'] = (int) $row['q'];
            $out['sold_amount'] = (float) $row['sold'];
            $out['wholesale_amount'] = (float) $row['cost'];
            $out['profit'] = (float) $row['profit'];
        }
    } catch (Exception $e) {
    }
    try {
        $out['received'] = (float) $pdo->query(
            'SELECT COALESCE(SUM(amount),0) FROM agent_card_payments'
        )->fetchColumn();
    } catch (Exception $e) {
    }
    $out['remaining'] = (float) $out['sold_amount'] - (float) $out['received'];
    $out['available_cards'] = max(0, (int) $out['stock_qty']);
    // المستخدمة ≈ المحوّلة للوكلاء (تقريبي من التحويلات)
    $out['used_cards'] = (int) $out['transfer_qty'];
    return $out;
}
