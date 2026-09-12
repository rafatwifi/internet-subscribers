<?php

/**
 * الجدول الدوري: قطع الخدمة بعد تجاوز أيام السماح بدون تسديد
 */

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
            LEFT JOIN sas_users_cache c ON (
                c.local_subscriber_id = s.id
                OR (
                  s.sas_username IS NOT NULL AND TRIM(s.sas_username) <> ''
                  AND LOWER(TRIM(c.username)) = LOWER(TRIM(s.sas_username))
                )
            )
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
            LEFT JOIN sas_users_cache c ON (
                c.local_subscriber_id = s.id
                OR (s.sas_username IS NOT NULL AND s.sas_username <> '' AND {$userEq})
            )
            WHERE (
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
        $result = function_exists('whatsapp_send')
            ? whatsapp_send($config, $phone, $body, 'schedule_cut')
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
