<?php

/**
 * إحصائيات لوحة أدمن المنصة (مو لوحة مشتركين الوكالة)
 */
function platform_admin_dashboard_stats($pdo, $config)
{
    $out = array(
        'users_total' => 0,
        'users_active' => 0,
        'users_expired' => 0,
        'users_trial' => 0,
        'users_pending' => 0,
        'version' => function_exists('app_version') ? app_version() : '—',
        'google_ms' => null,
        'google_ok' => false,
        'datetime' => date('Y-m-d H:i:s'),
        'sales' => 0.0,
        'received' => 0.0,
        'debt' => 0.0,
    );
    try {
        $out['users_total'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM tenants WHERE id > 1'
        )->fetchColumn();
        $out['users_active'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM tenants WHERE id > 1 AND status = 'active'"
        )->fetchColumn();
        $out['users_expired'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM tenants WHERE id > 1 AND status = 'expired'"
        )->fetchColumn();
        $out['users_pending'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM tenants WHERE id > 1 AND status = 'pending'"
        )->fetchColumn();
        $out['users_trial'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM tenants
             WHERE id > 1 AND status = 'active'
               AND trial_ends_at IS NOT NULL AND trial_ends_at > NOW()
               AND (subscription_expires_at IS NULL OR subscription_expires_at <= trial_ends_at)"
        )->fetchColumn();
    } catch (Exception $e) {
    }
    if (function_exists('system_latency_to_host')) {
        $g = system_latency_to_host('www.google.com', 2.5);
        if (is_array($g)) {
            $out['google_ms'] = isset($g['ms']) ? $g['ms'] : null;
            $out['google_ok'] = !empty($g['ok']);
        }
    }
    if (function_exists('platform_card_summary')) {
        $plat = platform_card_summary($pdo);
        $out['sales'] = isset($plat['sold_amount']) ? (float) $plat['sold_amount'] : 0.0;
        $out['received'] = isset($plat['received']) ? (float) $plat['received'] : 0.0;
        $out['debt'] = isset($plat['remaining']) ? (float) $plat['remaining'] : 0.0;
    }
    return $out;
}

/**
 * جدول شركات الكتالوج (اسم + هوست فقط) — لاختيار الوكيل
 */
function ensure_platform_companies_schema($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS platform_companies (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                sas_host VARCHAR(255) NOT NULL DEFAULT "",
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_pc_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Exception $e) {
    }
    // ترحيل من tenants الكتالوج القديم إن الجدول فاضي
    try {
        $cnt = (int) $pdo->query('SELECT COUNT(*) FROM platform_companies')->fetchColumn();
        if ($cnt <= 0 && function_exists('tenants_sas_company_catalog')) {
            // لا نعتمد config هنا — نقرأ tenants بهوست وبدون مالك
            $rows = $pdo->query(
                "SELECT name, sas_host FROM tenants
                 WHERE is_active = 1 AND sas_host IS NOT NULL AND TRIM(sas_host) <> ''
                   AND (owner_user_id IS NULL OR owner_user_id = 0 OR id = 1)
                 ORDER BY id ASC"
            )->fetchAll();
            if (is_array($rows)) {
                $ins = $pdo->prepare(
                    'INSERT INTO platform_companies (name, sas_host, is_active) VALUES (:n, :h, 1)'
                );
                $seen = array();
                foreach ($rows as $r) {
                    $h = strtolower(preg_replace('#^https?://#i', '', rtrim(trim((string) $r['sas_host']), '/')));
                    if ($h === '' || isset($seen[$h])) {
                        continue;
                    }
                    $seen[$h] = true;
                    $ins->execute(array(
                        ':n' => isset($r['name']) && $r['name'] !== '' ? $r['name'] : $h,
                        ':h' => $h,
                    ));
                }
            }
        }
    } catch (Exception $e) {
    }
}

function platform_companies_list($pdo, $activeOnly = false)
{
    ensure_platform_companies_schema($pdo);
    try {
        $sql = 'SELECT * FROM platform_companies';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $rows = $pdo->query($sql)->fetchAll();
        return is_array($rows) ? $rows : array();
    } catch (Exception $e) {
        return array();
    }
}

function platform_company_row($pdo, $id)
{
    ensure_platform_companies_schema($pdo);
    try {
        $st = $pdo->prepare('SELECT * FROM platform_companies WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => (int) $id));
        $r = $st->fetch();
        return $r ? $r : null;
    } catch (Exception $e) {
        return null;
    }
}

function platform_company_save($pdo, $id, $name, $host, $isActive = 1)
{
    ensure_platform_companies_schema($pdo);
    $name = trim((string) $name);
    $host = preg_replace('#^https?://#i', '', rtrim(trim((string) $host), '/'));
    if ($name === '' || $host === '') {
        return array(false, 'الاسم والهوست مطلوبين', 0);
    }
    $id = (int) $id;
    try {
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE platform_companies SET name = :n, sas_host = :h, is_active = :a WHERE id = :id'
            )->execute(array(':n' => $name, ':h' => $host, ':a' => $isActive ? 1 : 0, ':id' => $id));
            return array(true, 'تم الحفظ', $id);
        }
        $pdo->prepare(
            'INSERT INTO platform_companies (name, sas_host, is_active) VALUES (:n, :h, :a)'
        )->execute(array(':n' => $name, ':h' => $host, ':a' => $isActive ? 1 : 0));
        return array(true, 'تم الإنشاء', (int) $pdo->lastInsertId());
    } catch (Exception $e) {
        return array(false, 'فشل الحفظ', 0);
    }
}

function platform_company_delete($pdo, $id)
{
    ensure_platform_companies_schema($pdo);
    try {
        $pdo->prepare('DELETE FROM platform_companies WHERE id = :id')->execute(array(':id' => (int) $id));
        return true;
    } catch (Exception $e) {
        return false;
    }
}
