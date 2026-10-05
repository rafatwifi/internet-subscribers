<?php

/**
 * الجدول الدوري: قطع الخدمة بعد تجاوز أيام السماح بدون تسديد.
 * إعدادات التشغيل خاصة بكل وكالة، مو ملف واحد للكل.
 */

function schedule_setting_defaults()
{
    $global = function_exists('settings_load') ? settings_load() : array();
    $days = isset($global['expiry_auto_remind_days']) ? (int) $global['expiry_auto_remind_days'] : 1;
    if ($days < 0) {
        $days = 0;
    }
    if ($days > 60) {
        $days = 60;
    }
    $after = isset($global['unpaid_remind_after_days']) ? (int) $global['unpaid_remind_after_days'] : 7;
    if ($after < 1) {
        $after = 1;
    }
    if ($after > 365) {
        $after = 365;
    }
    return array(
        'schedule_cut_enabled' => !empty($global['schedule_cut_enabled']),
        'schedule_cut_send_wa' => !isset($global['schedule_cut_send_wa']) || !empty($global['schedule_cut_send_wa']),
        'expiry_auto_remind_enabled' => !empty($global['expiry_auto_remind_enabled']),
        'expiry_auto_remind_days' => $days,
        'unpaid_remind_enabled' => !empty($global['unpaid_remind_enabled']),
        'unpaid_remind_after_days' => $after,
        'wa_case_expiry_soon' => isset($global['wa_case_expiry_soon']) ? (string) $global['wa_case_expiry_soon'] : '',
        'wa_case_schedule_cut' => isset($global['wa_case_schedule_cut']) ? (string) $global['wa_case_schedule_cut'] : '',
        'wa_case_unpaid_overdue' => isset($global['wa_case_unpaid_overdue']) ? (string) $global['wa_case_unpaid_overdue'] : '',
    );
}

function schedule_settings_normalize($data, $base = null)
{
    if (!is_array($base)) {
        $base = schedule_setting_defaults();
    }
    if (!is_array($data)) {
        $data = array();
    }
    $out = $base;
    if (array_key_exists('schedule_cut_enabled', $data)) {
        $out['schedule_cut_enabled'] = !empty($data['schedule_cut_enabled']);
    }
    if (array_key_exists('schedule_cut_send_wa', $data)) {
        $out['schedule_cut_send_wa'] = !empty($data['schedule_cut_send_wa']);
    }
    if (array_key_exists('expiry_auto_remind_enabled', $data)) {
        $out['expiry_auto_remind_enabled'] = !empty($data['expiry_auto_remind_enabled']);
    }
    if (array_key_exists('expiry_auto_remind_days', $data)) {
        $days = (int) $data['expiry_auto_remind_days'];
        if ($days < 0) {
            $days = 0;
        }
        if ($days > 60) {
            $days = 60;
        }
        $out['expiry_auto_remind_days'] = $days;
    }
    if (array_key_exists('unpaid_remind_enabled', $data)) {
        $out['unpaid_remind_enabled'] = !empty($data['unpaid_remind_enabled']);
    }
    if (array_key_exists('unpaid_remind_after_days', $data)) {
        $after = (int) $data['unpaid_remind_after_days'];
        if ($after < 1) {
            $after = 1;
        }
        if ($after > 365) {
            $after = 365;
        }
        $out['unpaid_remind_after_days'] = $after;
    }
    foreach (array('expiry_soon', 'schedule_cut', 'unpaid_overdue') as $case) {
        $k = 'wa_case_' . $case;
        if (array_key_exists($k, $data)) {
            $out[$k] = trim((string) $data[$k]);
        }
    }
    return $out;
}

function ensure_tenant_schedule_table($pdo)
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tenant_schedule (
            tenant_id INT NOT NULL PRIMARY KEY,
            settings_json TEXT NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function schedule_settings_read_row($pdo, $tenantId)
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0) {
        return null;
    }
    try {
        ensure_tenant_schedule_table($pdo);
        $st = $pdo->prepare('SELECT settings_json FROM tenant_schedule WHERE tenant_id = :t LIMIT 1');
        $st->execute(array(':t' => $tenantId));
        $raw = $st->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
    if ($raw === false || $raw === null || trim((string) $raw) === '') {
        return null;
    }
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : null;
}

function schedule_settings_for_tenant($pdo, $tenantId)
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0) {
        $tenantId = 1;
    }
    $row = schedule_settings_read_row($pdo, $tenantId);
    if (is_array($row)) {
        return schedule_settings_normalize($row, array(
            'schedule_cut_enabled' => false,
            'schedule_cut_send_wa' => true,
            'expiry_auto_remind_enabled' => false,
            'expiry_auto_remind_days' => 1,
            'unpaid_remind_enabled' => false,
            'unpaid_remind_after_days' => 7,
            'wa_case_expiry_soon' => '',
            'wa_case_schedule_cut' => '',
            'wa_case_unpaid_overdue' => '',
        ));
    }
    $seed = schedule_setting_defaults();
    schedule_settings_save($pdo, $tenantId, $seed);
    return $seed;
}

function schedule_settings_save($pdo, $tenantId, $data)
{
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0) {
        return false;
    }
    $existing = schedule_settings_read_row($pdo, $tenantId);
    $base = is_array($existing)
        ? schedule_settings_normalize($existing, array(
            'schedule_cut_enabled' => false,
            'schedule_cut_send_wa' => true,
            'expiry_auto_remind_enabled' => false,
            'expiry_auto_remind_days' => 1,
            'unpaid_remind_enabled' => false,
            'unpaid_remind_after_days' => 7,
            'wa_case_expiry_soon' => '',
            'wa_case_schedule_cut' => '',
            'wa_case_unpaid_overdue' => '',
        ))
        : schedule_setting_defaults();
    $merged = schedule_settings_normalize($data, $base);
    $flags = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) {
        $flags |= JSON_UNESCAPED_UNICODE;
    }
    $json = json_encode($merged, $flags);
    try {
        ensure_tenant_schedule_table($pdo);
        $pdo->prepare(
            'INSERT INTO tenant_schedule (tenant_id, settings_json, updated_at)
             VALUES (:t, :j, NOW())
             ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json), updated_at = NOW()'
        )->execute(array(':t' => $tenantId, ':j' => $json));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function schedule_config_with_tenant($config, $sched)
{
    if (!is_array($config)) {
        $config = array();
    }
    if (!is_array($sched)) {
        $sched = array();
    }
    $config['schedule_cut_enabled'] = !empty($sched['schedule_cut_enabled']);
    $config['schedule_cut_send_wa'] = !empty($sched['schedule_cut_send_wa']);
    $config['expiry_auto_remind_enabled'] = !empty($sched['expiry_auto_remind_enabled']);
    $config['expiry_auto_remind_days'] = isset($sched['expiry_auto_remind_days'])
        ? max(0, (int) $sched['expiry_auto_remind_days'])
        : 1;
    $config['unpaid_remind_enabled'] = !empty($sched['unpaid_remind_enabled']);
    $config['unpaid_remind_after_days'] = isset($sched['unpaid_remind_after_days'])
        ? max(1, (int) $sched['unpaid_remind_after_days'])
        : 7;
    if (!isset($config['wa_cases']) || !is_array($config['wa_cases'])) {
        $config['wa_cases'] = array();
    }
    foreach (array('expiry_soon', 'schedule_cut', 'unpaid_overdue') as $case) {
        $k = 'wa_case_' . $case;
        if (!array_key_exists($k, $sched)) {
            continue;
        }
        $v = trim((string) $sched[$k]);
        if ($v === '__none__') {
            $config['wa_cases'][$case] = '';
        } elseif ($v !== '') {
            $config['wa_cases'][$case] = $v;
        }
    }
    return $config;
}

function schedule_each_tenant($pdo, $config, $fn)
{
    $ids = array();
    try {
        $st = $pdo->query('SELECT id FROM tenants ORDER BY id ASC');
        if ($st) {
            foreach ($st->fetchAll() as $r) {
                $id = isset($r['id']) ? (int) $r['id'] : 0;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
    } catch (Exception $e) {
    }
    if (!$ids) {
        $ids[] = 1;
    }
    $out = array();
    foreach ($ids as $tid) {
        $GLOBALS['schedule_tenant_id'] = $tid;
        $sched = schedule_settings_for_tenant($pdo, $tid);
        $cfg = schedule_config_with_tenant($config, $sched);
        $out[$tid] = call_user_func($fn, $tid, $cfg, $sched);
    }
    unset($GLOBALS['schedule_tenant_id']);
    return $out;
}

function schedule_cut_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $debt = money_format_iqd(isset($row['debt_total']) ? $row['debt_total'] : 0, $currency);
    $days = isset($row['days_passed']) ? (string) $row['days_passed'] : '';
    $grace = isset($row['grace_used']) ? (string) $row['grace_used'] : '';
    $key = 'schedule_cut';
    if (function_exists('wa_case_template_key')) {
        $key = wa_case_template_key($config, 'schedule_cut');
    }
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['schedule_cut']) && trim((string) $config['templates']['schedule_cut']) !== '') {
        $tpl = $config['templates']['schedule_cut'];
    }
    if ($tpl !== '' && function_exists('tpl_fill')) {
        return tpl_fill($tpl, array(
            'name' => isset($row['name']) ? $row['name'] : '',
            'debt' => $debt,
            'amount' => $debt,
            'days' => $days,
            'days_passed' => $days,
            'grace' => $grace,
            'package' => isset($row['package']) ? $row['package'] : '',
            'month' => isset($row['month']) ? $row['month'] : '',
        ));
    }
    return 'السلام عليكم ' . (isset($row['name']) ? $row['name'] : '') . "\n"
        . "تم قطع الإنترنت بسبب عدم تسديد الديون غير المسددة والبالغة {$debt}\n"
        . 'بعد تجاوز أيام السماح. يرجى التسديد لإعادة الخدمة.';
}

/**
 * قائمة المدينين للجدول الدوري (عرض الصفحة).
 * يرجع array('rows' => [...], 'error' => '')
 */
function schedule_viewer_scope_sql($alias)
{
    $forced = !empty($GLOBALS['schedule_tenant_id']) ? (int) $GLOBALS['schedule_tenant_id'] : 0;
    if ($forced > 0) {
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
        if ($a === '') {
            $a = 's';
        }
        return ' AND ' . $a . '.tenant_id = ' . $forced;
    }
    if (empty($_SESSION['admin_logged_in'])) {
        return '';
    }
    if (function_exists('is_super_admin_user') && is_super_admin_user()) {
        return '';
    }
    if (function_exists('subscriber_agent_scope_sql')) {
        return subscriber_agent_scope_sql($alias);
    }
    if (!function_exists('current_tenant_id')) {
        return '';
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
    if ($a === '') {
        $a = 's';
    }
    return ' AND ' . $a . '.tenant_id = ' . (int) current_tenant_id();
}

function schedule_debtors_list($pdo, $config, $limit = 500)
{
    $out = array('rows' => array(), 'error' => '');
    $limit = max(1, min(1000, (int) $limit));
    $sysGrace = function_exists('subscriber_default_grace_days')
        ? subscriber_default_grace_days($config)
        : (isset($config['grace_days']) ? (int) $config['grace_days'] : 3);

    if (function_exists('ensure_subscriber_grace_days_column')) {
        try {
            ensure_subscriber_grace_days_column($pdo);
        } catch (Exception $e) {
        }
    }

    $rows = array();
    $sql = "SELECT s.id AS subscriber_id, s.name, s.phone, s.grace_days, s.sas_username,
                   MAX(c.username) AS cache_username,
                   MAX(c.enabled) AS sas_enabled,
                   MAX(c.is_online) AS is_online,
                   MIN(CASE
                         WHEN i.due_date IS NULL OR i.due_date < '1971-01-01' THEN CURDATE()
                         ELSE i.due_date
                       END) AS oldest_due,
                   SUM(i.amount) AS debt_total
            FROM subscribers s
            INNER JOIN invoices i ON i.subscriber_id = s.id AND i.status = 'unpaid'
            LEFT JOIN sas_users_cache c ON c.tenant_id = s.tenant_id AND (
                c.local_subscriber_id = s.id
                OR (
                  s.sas_username IS NOT NULL AND TRIM(s.sas_username) <> ''
                  AND LOWER(TRIM(c.username)) = LOWER(TRIM(s.sas_username))
                )
            )
            WHERE 1=1" . schedule_viewer_scope_sql('s') . "
            GROUP BY s.id, s.name, s.phone, s.grace_days, s.sas_username
            HAVING SUM(i.amount) > 0
            ORDER BY oldest_due ASC
            LIMIT " . (int) $limit;
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
        try {
            $sql2 = "SELECT s.id AS subscriber_id, s.name, s.phone, s.grace_days, s.sas_username,
                            '' AS cache_username, 1 AS sas_enabled, 0 AS is_online,
                            MIN(i.due_date) AS oldest_due, SUM(i.amount) AS debt_total
                     FROM subscribers s
                     INNER JOIN invoices i ON i.subscriber_id = s.id AND i.status = 'unpaid'
                     WHERE 1=1" . schedule_viewer_scope_sql('s') . "
                     GROUP BY s.id, s.name, s.phone, s.grace_days, s.sas_username
                     HAVING SUM(i.amount) > 0
                     ORDER BY oldest_due ASC
                     LIMIT " . (int) $limit;
            $rows = $pdo->query($sql2)->fetchAll();
            $out['error'] = '';
        } catch (Exception $e2) {
            $out['error'] = $e2->getMessage();
            return $out;
        }
    }

    $list = array();
    $stats = array('total' => 0, 'will_cut' => 0, 'grace' => 0, 'disabled' => 0, 'debt_sum' => 0.0);
    foreach ($rows as $row) {
        $grace = function_exists('subscriber_grace_days')
            ? subscriber_grace_days($row, $config)
            : $sysGrace;
        $oldest = !empty($row['oldest_due']) ? (string) $row['oldest_due'] : date('Y-m-d');
        if ($oldest === '' || $oldest < '1971-01-01') {
            $oldest = date('Y-m-d');
        }
        $dueTs = strtotime($oldest);
        if ($dueTs <= 0) {
            $dueTs = strtotime(date('Y-m-d'));
        }
        $daysPassed = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $dueTs))) / 86400);
        if ($daysPassed < 0) {
            $daysPassed = 0;
        }
        $enabled = isset($row['sas_enabled']) ? (int) $row['sas_enabled'] : 1;
        $willCut = ($enabled !== 0 && $daysPassed > $grace);
        $daysLeft = $grace - $daysPassed;
        $username = trim((string) (!empty($row['cache_username']) ? $row['cache_username'] : $row['sas_username']));
        $debt = isset($row['debt_total']) ? (float) $row['debt_total'] : 0;
        $status = 'grace';
        if ($enabled === 0) {
            $status = 'disabled';
        } elseif ($willCut) {
            $status = 'will_cut';
        }
        $pct = 0;
        if ($grace > 0) {
            $pct = (int) min(100, round(($daysPassed / max(1, $grace)) * 100));
        } elseif ($daysPassed > 0) {
            $pct = 100;
        }
        $item = array(
            'id' => (int) $row['subscriber_id'],
            'name' => $row['name'],
            'username' => $username,
            'phone' => isset($row['phone']) ? $row['phone'] : '',
            'debt' => $debt,
            'grace' => $grace,
            'days_passed' => $daysPassed,
            'days_left' => $daysLeft,
            'will_cut' => $willCut,
            'enabled' => $enabled,
            'online' => !empty($row['is_online']),
            'status' => $status,
            'oldest_due' => $oldest,
            'grace_pct' => $pct,
        );
        $list[] = $item;
        $stats['total']++;
        $stats['debt_sum'] += $debt;
        if ($status === 'will_cut') {
            $stats['will_cut']++;
        } elseif ($status === 'disabled') {
            $stats['disabled']++;
        } else {
            $stats['grace']++;
        }
    }
    $out['rows'] = $list;
    $out['stats'] = $stats;
    return $out;
}

/**
 * يرجع ملخص: checked, cut, wa_sent, wa_failed, skipped
 */
function run_schedule_debt_cuts($pdo, $config, $limit = 80)
{
    $out = array(
        'checked' => 0,
        'cut' => 0,
        'wa_sent' => 0,
        'wa_failed' => 0,
        'skipped' => 0,
        'enabled' => !empty($config['schedule_cut_enabled']),
    );
    if (empty($config['schedule_cut_enabled'])) {
        return $out;
    }
    if (function_exists('ensure_subscriber_grace_days_column')) {
        ensure_subscriber_grace_days_column($pdo);
    }
    $limit = max(1, min(200, (int) $limit));
    $userEq = function_exists('sas_sql_username_eq')
        ? sas_sql_username_eq('s.sas_username', 'c.username')
        : 'LOWER(TRIM(s.sas_username)) = LOWER(TRIM(c.username))';
    $sql = "SELECT s.id AS subscriber_id, s.name, s.phone, s.grace_days, s.sas_username,
                   MAX(c.username) AS cache_username,
                   MAX(c.sas_user_id) AS sas_user_id,
                   MAX(c.enabled) AS sas_enabled,
                   MAX(c.profile_name) AS profile_name,
                   MIN(CASE
                         WHEN i.due_date IS NULL OR i.due_date < '1971-01-01' THEN CURDATE()
                         ELSE i.due_date
                       END) AS oldest_due,
                   SUM(i.amount) AS debt_total,
                   GROUP_CONCAT(DISTINCT i.month_label ORDER BY i.month_label SEPARATOR ', ') AS months
            FROM subscribers s
            INNER JOIN invoices i ON i.subscriber_id = s.id AND i.status = 'unpaid'
            LEFT JOIN sas_users_cache c ON c.tenant_id = s.tenant_id AND (
                c.local_subscriber_id = s.id
                OR (s.sas_username IS NOT NULL AND s.sas_username <> '' AND {$userEq})
            )
            WHERE 1=1" . schedule_viewer_scope_sql('s') . " AND (
                (s.sas_username IS NOT NULL AND TRIM(s.sas_username) <> '')
                OR c.username IS NOT NULL
            )
            GROUP BY s.id, s.name, s.phone, s.grace_days, s.sas_username
            HAVING SUM(i.amount) > 0
            ORDER BY oldest_due ASC
            LIMIT " . (int) $limit;
    try {
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
        return $out;
    }
    $out['checked'] = count($rows);
    $sendWa = !empty($config['schedule_cut_send_wa']);

    foreach ($rows as $row) {
        $grace = function_exists('subscriber_grace_days')
            ? subscriber_grace_days($row, $config)
            : (isset($config['grace_days']) ? (int) $config['grace_days'] : 3);
        $oldest = !empty($row['oldest_due']) ? (string) $row['oldest_due'] : '';
        if ($oldest === '') {
            $out['skipped']++;
            continue;
        }
        $dueTs = strtotime($oldest);
        if ($dueTs <= 0) {
            $out['skipped']++;
            continue;
        }
        $daysPassed = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $dueTs))) / 86400);
        if ($daysPassed <= $grace) {
            $out['skipped']++;
            continue;
        }
        $enabled = isset($row['sas_enabled']) ? (int) $row['sas_enabled'] : 1;
        if ($enabled === 0) {
            $out['skipped']++;
            continue;
        }
        $username = trim((string) (!empty($row['cache_username']) ? $row['cache_username'] : $row['sas_username']));
        if ($username === '') {
            $out['skipped']++;
            continue;
        }
        if (!function_exists('sas_write_user')) {
            $out['skipped']++;
            continue;
        }
        list($ok, $msg) = sas_write_user($pdo, $config, 'sas_enable', $username, array('enabled' => '0'));
        if (!$ok) {
            $out['skipped']++;
            continue;
        }
        $out['cut']++;
        if (function_exists('activity_log')) {
            activity_log(
                $pdo,
                (int) $row['subscriber_id'],
                'subscriber',
                (int) $row['subscriber_id'],
                'schedule_cut',
                'قطع تلقائي — تجاوز أيام السماح',
                'اليوزر: ' . $username
                . "\nأيام متأخرة: " . $daysPassed
                . "\nأيام السماح: " . $grace
                . "\nالدين: " . (isset($row['debt_total']) ? $row['debt_total'] : 0)
            );
        }
        if (!$sendWa) {
            continue;
        }
        $phone = isset($row['phone']) ? trim((string) $row['phone']) : '';
        if ($phone === '') {
            continue;
        }
        $msgRow = array(
            'name' => $row['name'],
            'phone' => $phone,
            'debt_total' => isset($row['debt_total']) ? $row['debt_total'] : 0,
            'days_passed' => $daysPassed,
            'grace_used' => $grace,
            'package' => isset($row['profile_name']) ? $row['profile_name'] : '',
            'month' => isset($row['months']) ? $row['months'] : '',
        );
        $body = schedule_cut_message($msgRow, $config);
        $waSession = function_exists('whatsapp_session_for_subscriber')
            ? whatsapp_session_for_subscriber($pdo, (int) $row['subscriber_id'], 0)
            : '';
        if ($waSession === '') {
            $out['wa_failed']++;
            continue;
        }
        $result = function_exists('whatsapp_send')
            ? whatsapp_send($config, $phone, $body, 'schedule_cut', $waSession)
            : array('success' => false);
        if (function_exists('log_message')) {
            log_message($pdo, (int) $row['subscriber_id'], $result);
        }
        if (!empty($result['success'])) {
            $out['wa_sent']++;
        } else {
            $out['wa_failed']++;
        }
    }
    return $out;
}
