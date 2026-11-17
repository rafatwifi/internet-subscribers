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
    if (function_exists('platform_sales_summary')) {
        $plat = platform_sales_summary($pdo);
        $out['sales'] = isset($plat['sales']) ? (float) $plat['sales'] : 0.0;
        $out['received'] = isset($plat['received']) ? (float) $plat['received'] : 0.0;
        $out['debt'] = isset($plat['debt']) ? (float) $plat['debt'] : 0.0;
        $out['sales_count'] = isset($plat['count']) ? (int) $plat['count'] : 0;
    }
    return $out;
}

function ensure_platform_sales_schema($pdo)
{
    static $done = false;
    if ($done || !$pdo) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS platform_membership_sales (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL,
                plan_code VARCHAR(40) NOT NULL DEFAULT "",
                plan_label VARCHAR(80) NOT NULL DEFAULT "",
                days INT NOT NULL DEFAULT 0,
                price DECIMAL(14,2) NOT NULL DEFAULT 0,
                discount DECIMAL(14,2) NOT NULL DEFAULT 0,
                net DECIMAL(14,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pms_tenant (tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Exception $e) {
    }
}

function platform_sale_record($pdo, $tenantId, $planCode)
{
    $tenantId = (int) $tenantId;
    $planCode = trim((string) $planCode);
    if ($tenantId <= 1 || $planCode === '' || $planCode === 'manual') {
        return false;
    }
    $saas = function_exists('saas_settings') ? saas_settings() : array('plans' => array());
    $plans = isset($saas['plans']) && is_array($saas['plans']) ? $saas['plans'] : array();
    if (!isset($plans[$planCode]) || !is_array($plans[$planCode])) {
        return false;
    }
    $p = $plans[$planCode];
    $price = isset($p['amount']) ? (float) $p['amount'] : 0;
    $discount = isset($p['discount']) ? (float) $p['discount'] : 0;
    if ($discount < 0) {
        $discount = 0;
    }
    if ($discount > $price) {
        $discount = $price;
    }
    $net = $price - $discount;
    $label = isset($p['label']) ? (string) $p['label'] : $planCode;
    $days = isset($p['days']) ? (int) $p['days'] : 0;
    ensure_platform_sales_schema($pdo);
    try {
        $st = $pdo->prepare(
            'INSERT INTO platform_membership_sales
                (tenant_id, plan_code, plan_label, days, price, discount, net)
             VALUES (:t, :c, :l, :d, :p, :dis, :n)'
        );
        $st->execute(array(
            ':t' => $tenantId,
            ':c' => $planCode,
            ':l' => $label,
            ':d' => $days,
            ':p' => $price,
            ':dis' => $discount,
            ':n' => $net,
        ));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function platform_sales_summary($pdo)
{
    ensure_platform_sales_schema($pdo);
    $out = array('sales' => 0.0, 'received' => 0.0, 'debt' => 0.0, 'count' => 0);
    try {
        $row = $pdo->query(
            'SELECT COALESCE(SUM(net),0) AS net, COALESCE(SUM(price),0) AS price, COUNT(*) AS n
             FROM platform_membership_sales'
        )->fetch();
        if ($row) {
            $out['sales'] = (float) $row['net'];
            $out['received'] = (float) $row['net'];
            $out['count'] = (int) $row['n'];
        }
    } catch (Exception $e) {
    }
    return $out;
}

function platform_tenant_last_sale($pdo, $tenantId)
{
    ensure_platform_sales_schema($pdo);
    try {
        $st = $pdo->prepare(
            'SELECT plan_code, plan_label, price, discount, net, days, created_at
             FROM platform_membership_sales WHERE tenant_id = :t ORDER BY id DESC LIMIT 1'
        );
        $st->execute(array(':t' => (int) $tenantId));
        $row = $st->fetch();
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
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
                provider_type VARCHAR(32) NOT NULL DEFAULT "sas",
                logo_path VARCHAR(255) NOT NULL DEFAULT "",
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_pc_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Exception $e) {
    }
    foreach (array(
        'provider_type VARCHAR(32) NOT NULL DEFAULT "sas"',
        'logo_path VARCHAR(255) NOT NULL DEFAULT ""',
        'api_alt_host VARCHAR(255) NOT NULL DEFAULT ""',
    ) as $colDef) {
        $col = preg_replace('/\s.*/', '', $colDef);
        try {
            $has = $pdo->query("SHOW COLUMNS FROM platform_companies LIKE " . $pdo->quote($col))->fetch();
            if (!$has) {
                $pdo->exec('ALTER TABLE platform_companies ADD COLUMN ' . $colDef);
            }
        } catch (Exception $e) {
        }
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
                    'INSERT INTO platform_companies (name, sas_host, provider_type, is_active) VALUES (:n, :h, :p, 1)'
                );
                $seen = array();
                foreach ($rows as $r) {
                    $h = strtolower(preg_replace('#^https?://#i', '', rtrim(trim((string) $r['sas_host']), '/')));
                    if ($h === '' || isset($seen[$h])) {
                        continue;
                    }
                    $seen[$h] = true;
                    $name = isset($r['name']) && $r['name'] !== '' ? $r['name'] : $h;
                    $ins->execute(array(
                        ':n' => $name,
                        ':h' => $h,
                        ':p' => platform_company_guess_provider($name, $h),
                    ));
                }
            }
        }
    } catch (Exception $e) {
    }
    if (function_exists('platform_companies_seed_logos')) {
        platform_companies_seed_logos($pdo);
    }
}

/** sas | earthlink */
function platform_company_normalize_provider($type)
{
    $type = strtolower(trim((string) $type));
    return ($type === 'earthlink' || $type === 'el') ? 'earthlink' : 'sas';
}

function platform_company_guess_provider($name, $host = '')
{
    $blob = strtolower(trim((string) $name) . ' ' . trim((string) $host));
    if (strpos($blob, 'earth') !== false || strpos($blob, 'ايرث') !== false || strpos($blob, 'إيرث') !== false) {
        return 'earthlink';
    }
    return 'sas';
}

function platform_company_default_logo($provider, $name = '')
{
    $provider = platform_company_normalize_provider($provider);
    $blob = strtolower((string) $name);
    if ($provider === 'earthlink' || strpos($blob, 'earth') !== false || strpos($blob, 'ايرث') !== false) {
        return 'uploads/companies/earthlink.png';
    }
    // نور البداية / NB-TEL وما شابه
    return 'uploads/companies/nb-tel.png';
}

function platform_companies_seed_logos($pdo)
{
    try {
        $rows = $pdo->query('SELECT id, name, sas_host, provider_type, logo_path FROM platform_companies')->fetchAll();
        if (!is_array($rows)) {
            return;
        }
        $upd = $pdo->prepare(
            'UPDATE platform_companies SET provider_type = :p, logo_path = :l WHERE id = :id'
        );
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $name = isset($r['name']) ? (string) $r['name'] : '';
            $host = isset($r['sas_host']) ? (string) $r['sas_host'] : '';
            $prov = platform_company_normalize_provider(
                isset($r['provider_type']) && $r['provider_type'] !== ''
                    ? $r['provider_type']
                    : platform_company_guess_provider($name, $host)
            );
            $logo = isset($r['logo_path']) ? trim((string) $r['logo_path']) : '';
            if ($logo === '') {
                $logo = platform_company_default_logo($prov, $name);
            }
            $upd->execute(array(':p' => $prov, ':l' => $logo, ':id' => $id));
        }
    } catch (Exception $e) {
    }
}

function platform_company_logo_url($row)
{
    if (!is_array($row)) {
        return '';
    }
    $path = isset($row['logo_path']) ? trim((string) $row['logo_path']) : '';
    if ($path === '') {
        $path = platform_company_default_logo(
            isset($row['provider_type']) ? $row['provider_type'] : 'sas',
            isset($row['name']) ? $row['name'] : ''
        );
    }
    $path = ltrim(str_replace('\\', '/', $path), '/');
    if ($path === '') {
        return '';
    }
    return $path;
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

/**
 * هوست قياس البنغ: لإيرث الخاص استخدم الاحتياطي (أو سبايسي الافتراضي) بدل admin.earthlink.iq.
 */
function platform_company_ping_target($co)
{
    if (!is_array($co)) {
        return '';
    }
    $host = isset($co['sas_host']) ? trim((string) $co['sas_host']) : '';
    $host = preg_replace('#^https?://#i', '', rtrim($host, '/'));
    $alt = isset($co['api_alt_host']) ? trim((string) $co['api_alt_host']) : '';
    $alt = preg_replace('#^https?://#i', '', rtrim($alt, '/'));
    $prov = platform_company_normalize_provider(
        isset($co['provider_type']) ? $co['provider_type'] : 'sas'
    );
    if ($prov === 'earthlink') {
        $private = function_exists('earthlink_is_private_host') && $host !== '' && earthlink_is_private_host($host);
        if ($private || $host === '') {
            if ($alt !== '') {
                return $alt;
            }
            if (function_exists('earthlink_default_alt_host')) {
                return earthlink_default_alt_host();
            }
        }
    }
    return $host;
}

function platform_company_save($pdo, $id, $name, $host, $isActive = 1, $provider = 'sas', $logoPath = null, $altHost = null)
{
    ensure_platform_companies_schema($pdo);
    $name = trim((string) $name);
    $host = preg_replace('#^https?://#i', '', rtrim(trim((string) $host), '/'));
    if ($name === '' || $host === '') {
        return array(false, 'الاسم والهوست مطلوبين', 0);
    }
    $provider = platform_company_normalize_provider(
        $provider !== null && $provider !== '' ? $provider : platform_company_guess_provider($name, $host)
    );
    $id = (int) $id;
    $logo = $logoPath !== null ? trim((string) $logoPath) : null;
    $alt = $altHost !== null
        ? preg_replace('#^https?://#i', '', rtrim(trim((string) $altHost), '/'))
        : null;
    try {
        if ($id > 0) {
            $cur = platform_company_row($pdo, $id);
            if ($logo === null) {
                $logo = $cur && !empty($cur['logo_path'])
                    ? (string) $cur['logo_path']
                    : platform_company_default_logo($provider, $name);
            } elseif ($logo === '') {
                $logo = platform_company_default_logo($provider, $name);
            }
            if ($alt === null) {
                $alt = $cur && isset($cur['api_alt_host']) ? (string) $cur['api_alt_host'] : '';
            }
            $pdo->prepare(
                'UPDATE platform_companies SET name = :n, sas_host = :h, provider_type = :p, logo_path = :l, is_active = :a, api_alt_host = :alt WHERE id = :id'
            )->execute(array(
                ':n' => $name,
                ':h' => $host,
                ':p' => $provider,
                ':l' => $logo,
                ':a' => $isActive ? 1 : 0,
                ':alt' => $alt,
                ':id' => $id,
            ));
            return array(true, 'تم الحفظ', $id);
        }
        if ($logo === null || $logo === '') {
            $logo = platform_company_default_logo($provider, $name);
        }
        if ($alt === null) {
            $alt = '';
        }
        $pdo->prepare(
            'INSERT INTO platform_companies (name, sas_host, provider_type, logo_path, is_active, api_alt_host) VALUES (:n, :h, :p, :l, :a, :alt)'
        )->execute(array(
            ':n' => $name,
            ':h' => $host,
            ':p' => $provider,
            ':l' => $logo,
            ':a' => $isActive ? 1 : 0,
            ':alt' => $alt,
        ));
        return array(true, 'تم الإنشاء', (int) $pdo->lastInsertId());
    } catch (Exception $e) {
        return array(false, 'فشل الحفظ', 0);
    }
}

/**
 * حفظ لوكو شركة مرفوع → uploads/companies/co_{id}.ext
 */
function platform_company_store_logo_upload($file, $companyId, &$fail = '')
{
    $fail = '';
    $companyId = (int) $companyId;
    if ($companyId <= 0 || !is_array($file) || empty($file['tmp_name'])) {
        $fail = 'empty';
        return false;
    }
    if (!empty($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
        $fail = 'upload';
        return false;
    }
    if (!empty($file['size']) && (int) $file['size'] > 2 * 1024 * 1024) {
        $fail = 'size';
        return false;
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || empty($info['mime'])) {
        $fail = 'type';
        return false;
    }
    $map = array(
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    );
    $mime = (string) $info['mime'];
    if (!isset($map[$mime])) {
        $fail = 'type';
        return false;
    }
    $dir = dirname(__DIR__) . '/public/uploads/companies';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $fail = 'mkdir';
        return false;
    }
    if (!is_writable($dir)) {
        $fail = 'writable';
        return false;
    }
    $rel = 'uploads/companies/co_' . $companyId . '.' . $map[$mime];
    $dest = dirname(__DIR__) . '/public/' . $rel;
    foreach (glob(dirname(__DIR__) . '/public/uploads/companies/co_' . $companyId . '.*') as $old) {
        @unlink($old);
    }
    if (!@move_uploaded_file($file['tmp_name'], $dest)) {
        if (!@copy($file['tmp_name'], $dest)) {
            $fail = 'move';
            return false;
        }
    }
    @chmod($dest, 0644);
    return $rel;
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
