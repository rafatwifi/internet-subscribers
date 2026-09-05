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
                   c.username AS cache_username, c.sas_user_id, c.enabled AS sas_enabled,
                   c.profile_name,
                   MIN(i.due_date) AS oldest_due,
                   SUM(i.amount) AS debt_total,
                   GROUP_CONCAT(DISTINCT i.month_label ORDER BY i.month_label SEPARATOR ', ') AS months
            FROM subscribers s
            INNER JOIN invoices i ON i.subscriber_id = s.id AND i.status = 'unpaid'
            LEFT JOIN sas_users_cache c ON (
                c.local_subscriber_id = s.id
                OR (s.sas_username IS NOT NULL AND s.sas_username <> '' AND {$userEq})
            )
            WHERE s.sas_username IS NOT NULL AND s.sas_username <> ''
            GROUP BY s.id
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
