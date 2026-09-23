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
