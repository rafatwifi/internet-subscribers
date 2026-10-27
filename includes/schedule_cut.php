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
        'expiry_auto_remind_repeat' => !empty($global['expiry_auto_remind_repeat']),
        'expiry_auto_remind_by' => 'profile',
        'expiry_auto_remind_profiles' => array(),
        'expiry_auto_remind_packages' => array(),
        'expiry_auto_remind_services' => array(),
        'expiry_auto_remind_once_enabled' => false,
        'expiry_auto_remind_once_days' => 1,
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
    if (array_key_exists('expiry_auto_remind_repeat', $data)) {
        $out['expiry_auto_remind_repeat'] = !empty($data['expiry_auto_remind_repeat']);
    }
    if (array_key_exists('expiry_auto_remind_by', $data)) {
        $out['expiry_auto_remind_by'] = ((string) $data['expiry_auto_remind_by'] === 'service') ? 'service' : 'profile';
    }
    $cleanList = function ($raw) {
        return function_exists('expiry_profile_list_clean') ? expiry_profile_list_clean($raw) : array();
    };
    if (!empty($data['expiry_auto_remind_lists_set'])) {
        $out['expiry_auto_remind_packages'] = $cleanList(isset($data['expiry_auto_remind_packages']) ? $data['expiry_auto_remind_packages'] : array());
        $out['expiry_auto_remind_services'] = $cleanList(isset($data['expiry_auto_remind_services']) ? $data['expiry_auto_remind_services'] : array());
        $byNow = (isset($out['expiry_auto_remind_by']) && $out['expiry_auto_remind_by'] === 'service') ? 'service' : 'profile';
        $out['expiry_auto_remind_profiles'] = ($byNow === 'service')
            ? $out['expiry_auto_remind_services']
            : $out['expiry_auto_remind_packages'];
    } else {
        if (array_key_exists('expiry_auto_remind_packages', $data)) {
            $out['expiry_auto_remind_packages'] = $cleanList($data['expiry_auto_remind_packages']);
        }
        if (array_key_exists('expiry_auto_remind_services', $data)) {
            $out['expiry_auto_remind_services'] = $cleanList($data['expiry_auto_remind_services']);
        }
        if (array_key_exists('expiry_auto_remind_profiles', $data) || !empty($data['expiry_auto_remind_profiles_set'])) {
            $rawProfiles = isset($data['expiry_auto_remind_profiles']) ? $data['expiry_auto_remind_profiles'] : array();
            $out['expiry_auto_remind_profiles'] = $cleanList($rawProfiles);
        }
    }
    if (empty($data['expiry_auto_remind_lists_set'])) {
        $out = schedule_expiry_apply_legacy($out);
    }
    if (array_key_exists('expiry_auto_remind_once_enabled', $data)) {
        $out['expiry_auto_remind_once_enabled'] = !empty($data['expiry_auto_remind_once_enabled']);
    }
    if (array_key_exists('expiry_auto_remind_once_days', $data)) {
        $onceDays = (int) $data['expiry_auto_remind_once_days'];
        if ($onceDays < 0) {
            $onceDays = 0;
        }
        if ($onceDays > 60) {
            $onceDays = 60;
        }
        $out['expiry_auto_remind_once_days'] = $onceDays;
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
            'expiry_auto_remind_repeat' => false,
            'expiry_auto_remind_by' => 'profile',
            'expiry_auto_remind_profiles' => array(),
            'expiry_auto_remind_packages' => array(),
            'expiry_auto_remind_services' => array(),
            'expiry_auto_remind_once_enabled' => false,
            'expiry_auto_remind_once_days' => 1,
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
            'expiry_auto_remind_repeat' => false,
            'expiry_auto_remind_by' => 'profile',
            'expiry_auto_remind_profiles' => array(),
            'expiry_auto_remind_packages' => array(),
            'expiry_auto_remind_services' => array(),
            'expiry_auto_remind_once_enabled' => false,
            'expiry_auto_remind_once_days' => 1,
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

function schedule_expiry_profile_choices($pdo, $tenantId)
{
    $tenantId = (int) $tenantId;
    $names = array();
    $seen = array();
    if (!$pdo || $tenantId <= 0) {
        return $names;
    }
    try {
        $st = $pdo->prepare(
            'SELECT DISTINCT profile_name FROM sas_users_cache
             WHERE tenant_id = :t AND profile_name IS NOT NULL AND profile_name <> \'\'
             ORDER BY profile_name ASC
             LIMIT 80'
        );
        $st->execute(array(':t' => $tenantId));
        $rows = $st->fetchAll();
    } catch (Exception $e) {
        $rows = array();
    }
    foreach ($rows as $row) {
        $name = isset($row['profile_name']) ? trim((string) $row['profile_name']) : '';
        $key = function_exists('expiry_profile_key') ? expiry_profile_key($name) : $name;
        if ($name === '' || $key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $names[] = $name;
    }
    return $names;
}

function schedule_expiry_package_choices($pdo, $tenantId)
{
    $tenantId = (int) $tenantId;
    $names = array();
    $seen = array();
    if (!$pdo || $tenantId <= 0) {
        return $names;
    }
    $push = function ($name) use (&$names, &$seen) {
        $name = trim((string) $name);
        $key = function_exists('expiry_profile_key') ? expiry_profile_key($name) : $name;
        if ($name === '' || $key === '' || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $names[] = $name;
    };
    $hasSubs = 0;
    try {
        $st = $pdo->prepare(
            'SELECT DISTINCT sub.service_name
             FROM subscriptions sub
             JOIN subscribers s ON s.id = sub.subscriber_id
             WHERE s.tenant_id = :t AND sub.service_name IS NOT NULL AND sub.service_name <> \'\'
             ORDER BY sub.service_name ASC
             LIMIT 80'
        );
        $st->execute(array(':t' => $tenantId));
        foreach ($st->fetchAll() as $row) {
            $push(isset($row['service_name']) ? $row['service_name'] : '');
            $hasSubs++;
        }
    } catch (Exception $e) {
        $hasSubs = 0;
    }
    if ($hasSubs > 0) {
        try {
            $planRows = $pdo->query(
                'SELECT name FROM service_plans WHERE is_active = 1 AND name IS NOT NULL AND name <> \'\' ORDER BY name ASC LIMIT 40'
            )->fetchAll();
            foreach ($planRows as $row) {
                $push(isset($row['name']) ? $row['name'] : '');
            }
        } catch (Exception $e) {
        }
    }
    return $names;
}

function schedule_expiry_apply_legacy($out)
{
    if (!is_array($out)) {
        return array();
    }
    $by = (isset($out['expiry_auto_remind_by']) && $out['expiry_auto_remind_by'] === 'service') ? 'service' : 'profile';
    $packages = (isset($out['expiry_auto_remind_packages']) && is_array($out['expiry_auto_remind_packages']))
        ? $out['expiry_auto_remind_packages'] : array();
    $services = (isset($out['expiry_auto_remind_services']) && is_array($out['expiry_auto_remind_services']))
        ? $out['expiry_auto_remind_services'] : array();
    $profiles = (isset($out['expiry_auto_remind_profiles']) && is_array($out['expiry_auto_remind_profiles']))
        ? $out['expiry_auto_remind_profiles'] : array();
    if ($by !== 'service' && !$packages && !$services && $profiles) {
        $packages = $profiles;
    }
    $out['expiry_auto_remind_by'] = $by;
    $out['expiry_auto_remind_packages'] = $packages;
    $out['expiry_auto_remind_services'] = $services;
    $out['expiry_auto_remind_profiles'] = ($by === 'service') ? $services : $packages;
    return $out;
}

function schedule_expiry_place_saved($sched, $lists)
{
    if (!is_array($sched)) {
        $sched = array();
    }
    $by = (isset($sched['expiry_auto_remind_by']) && $sched['expiry_auto_remind_by'] === 'service') ? 'service' : 'profile';
    $packages = (isset($sched['expiry_auto_remind_packages']) && is_array($sched['expiry_auto_remind_packages']))
        ? $sched['expiry_auto_remind_packages'] : array();
    $services = (isset($sched['expiry_auto_remind_services']) && is_array($sched['expiry_auto_remind_services']))
        ? $sched['expiry_auto_remind_services'] : array();
    $svcKeys = array();
    $profKeys = array();
    if (is_array($lists)) {
        $svcRows = (isset($lists['services']) && is_array($lists['services'])) ? $lists['services'] : array();
        $profRows = (isset($lists['profiles']) && is_array($lists['profiles'])) ? $lists['profiles'] : array();
        foreach ($svcRows as $name) {
            $key = function_exists('expiry_profile_key') ? expiry_profile_key($name) : strtolower(trim((string) $name));
            if ($key !== '') {
                $svcKeys[$key] = $name;
            }
        }
        foreach ($profRows as $name) {
            $key = function_exists('expiry_profile_key') ? expiry_profile_key($name) : strtolower(trim((string) $name));
            if ($key !== '' && !isset($svcKeys[$key])) {
                $profKeys[$key] = $name;
            }
        }
    }
    if (!$svcKeys && !$profKeys) {
        return $sched;
    }
    $svcOut = array();
    $profOut = array();
    $seenS = array();
    $seenP = array();
    $take = function ($name, $preferService) use (&$svcOut, &$profOut, &$seenS, &$seenP, $svcKeys, $profKeys) {
        $name = trim((string) $name);
        $key = function_exists('expiry_profile_key') ? expiry_profile_key($name) : strtolower($name);
        if ($name === '' || $key === '') {
            return;
        }
        if (isset($svcKeys[$key])) {
            if (!isset($seenS[$key])) {
                $seenS[$key] = true;
                $svcOut[] = $svcKeys[$key];
            }
            return;
        }
        if (isset($profKeys[$key])) {
            if (!isset($seenP[$key])) {
                $seenP[$key] = true;
                $profOut[] = $profKeys[$key];
            }
            return;
        }
        if ($preferService) {
            if (!isset($seenS[$key])) {
                $seenS[$key] = true;
                $svcOut[] = $name;
            }
        } elseif (!isset($seenP[$key])) {
            $seenP[$key] = true;
            $profOut[] = $name;
        }
    };
    foreach ($services as $name) {
        $take($name, true);
    }
    foreach ($packages as $name) {
        $take($name, false);
    }
    if ($by === 'service' && !$svcOut && $profOut) {
        $by = 'profile';
    } elseif ($by !== 'service' && $svcOut && !$profOut) {
        $by = 'service';
    }
    $sched['expiry_auto_remind_by'] = $by;
    $sched['expiry_auto_remind_packages'] = $profOut;
    $sched['expiry_auto_remind_services'] = $svcOut;
    $sched['expiry_auto_remind_profiles'] = ($by === 'service') ? $svcOut : $profOut;
    return $sched;
}

function schedule_expiry_choice_lists($pdo, $config, $tenantId)
{
    $tenantId = (int) $tenantId;
    $out = array('profiles' => array(), 'services' => array());
    $file = dirname(__DIR__) . '/storage/cache/expiry_sas_lists2_t' . $tenantId . '.json';
    if ($tenantId > 0 && is_file($file) && (time() - (int) @filemtime($file)) < 600) {
        $raw = json_decode((string) @file_get_contents($file), true);
        if (is_array($raw) && isset($raw['profiles']) && isset($raw['services']) && is_array($raw['profiles']) && is_array($raw['services'])) {
            return $raw;
        }
    }
    $seenP = array();
    $seenS = array();
    $push = function ($bucket, $name) use (&$out, &$seenP, &$seenS) {
        $name = trim((string) $name);
        $key = function_exists('expiry_profile_key') ? expiry_profile_key($name) : strtolower($name);
        if ($name === '' || $key === '') {
            return;
        }
        if ($bucket === 'services') {
            if (isset($seenS[$key])) {
                return;
            }
            $seenS[$key] = true;
            $out['services'][] = $name;
            if (isset($seenP[$key])) {
                $next = array();
                foreach ($out['profiles'] as $have) {
                    $haveKey = function_exists('expiry_profile_key') ? expiry_profile_key($have) : strtolower(trim((string) $have));
                    if ($haveKey !== $key) {
                        $next[] = $have;
                    }
                }
                $out['profiles'] = $next;
                unset($seenP[$key]);
            }
            return;
        }
        if (isset($seenP[$key]) || isset($seenS[$key])) {
            return;
        }
        $seenP[$key] = true;
        $out['profiles'][] = $name;
    };
    $api = null;
    $got = false;
    if ($tenantId > 0 && function_exists('sas_make_connector_for_tenant')) {
        try {
            $api = sas_make_connector_for_tenant($pdo, $config, $tenantId);
        } catch (Exception $e) {
            $api = null;
        }
    }
    if ($api && method_exists($api, 'getProfiles')) {
        try {
            $profiles = $api->getProfiles();
        } catch (Exception $e) {
            $profiles = array();
        }
        if (is_array($profiles)) {
            $ids = array();
            foreach ($profiles as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $push('profiles', isset($p['name']) ? $p['name'] : '');
                $id = isset($p['id']) ? (int) $p['id'] : 0;
                if ($id > 0) {
                    $ids[$id] = $id;
                    $got = true;
                }
            }
            if (method_exists($api, 'getAllowedExtensions')) {
                foreach ($ids as $id) {
                    try {
                        $allowed = $api->getAllowedExtensions($id);
                    } catch (Exception $e) {
                        $allowed = array();
                    }
                    if (!is_array($allowed)) {
                        continue;
                    }
                    foreach ($allowed as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $nm = (!empty($row['name']) && !is_array($row['name'])) ? $row['name'] : '';
                        if ($nm !== '') {
                            $push('services', $nm);
                            $got = true;
                        }
                    }
                }
            }
        }
    }
    if (!$out['profiles'] && function_exists('schedule_expiry_profile_choices')) {
        foreach (schedule_expiry_profile_choices($pdo, $tenantId) as $name) {
            $push('profiles', $name);
        }
    }
    if ($got) {
        @file_put_contents($file, json_encode($out));
    }
    return $out;
}

function schedule_config_with_tenant($config, $sched)
{
    if (!is_array($config)) {
        $config = array();
    }
    if (!is_array($sched)) {
        $sched = array();
    }
    $sched = schedule_expiry_apply_legacy($sched);
    $placeTid = 0;
    if (!empty($GLOBALS['schedule_tenant_id'])) {
        $placeTid = (int) $GLOBALS['schedule_tenant_id'];
    } elseif (!empty($GLOBALS['portal_warm_tenant_id'])) {
        $placeTid = (int) $GLOBALS['portal_warm_tenant_id'];
    } elseif (function_exists('current_tenant_id')) {
        $placeTid = (int) current_tenant_id();
    }
    if ($placeTid > 0 && isset($GLOBALS['pdo']) && $GLOBALS['pdo']) {
        $placeLists = schedule_expiry_choice_lists($GLOBALS['pdo'], $config, $placeTid);
        $sched = schedule_expiry_place_saved($sched, $placeLists);
    }
    $config['schedule_cut_enabled'] = !empty($sched['schedule_cut_enabled']);
    $config['schedule_cut_send_wa'] = !empty($sched['schedule_cut_send_wa']);
    $config['expiry_auto_remind_enabled'] = !empty($sched['expiry_auto_remind_enabled']);
    $config['expiry_auto_remind_days'] = isset($sched['expiry_auto_remind_days'])
        ? max(0, (int) $sched['expiry_auto_remind_days'])
        : 1;
    $config['expiry_auto_remind_repeat'] = !empty($sched['expiry_auto_remind_repeat']);
    $config['expiry_auto_remind_by'] = (isset($sched['expiry_auto_remind_by']) && $sched['expiry_auto_remind_by'] === 'service')
        ? 'service'
        : 'profile';
    $config['expiry_auto_remind_packages'] = (isset($sched['expiry_auto_remind_packages']) && is_array($sched['expiry_auto_remind_packages']))
        ? $sched['expiry_auto_remind_packages']
        : array();
    $config['expiry_auto_remind_services'] = (isset($sched['expiry_auto_remind_services']) && is_array($sched['expiry_auto_remind_services']))
        ? $sched['expiry_auto_remind_services']
        : array();
    if ($config['expiry_auto_remind_by'] === 'service') {
        $config['expiry_auto_remind_profiles'] = $config['expiry_auto_remind_services'];
    } elseif ($config['expiry_auto_remind_packages']) {
        $config['expiry_auto_remind_profiles'] = $config['expiry_auto_remind_packages'];
    } else {
        $config['expiry_auto_remind_profiles'] = (isset($sched['expiry_auto_remind_profiles']) && is_array($sched['expiry_auto_remind_profiles']))
            ? $sched['expiry_auto_remind_profiles']
            : array();
    }
    $config['expiry_auto_remind_once_enabled'] = !empty($sched['expiry_auto_remind_once_enabled']);
    $config['expiry_auto_remind_once_days'] = isset($sched['expiry_auto_remind_once_days'])
        ? max(0, (int) $sched['expiry_auto_remind_once_days'])
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
        if (function_exists('tenant_schedule_wa_user_id') && function_exists('user_apply_wa_pack_to_config')) {
            $packUid = tenant_schedule_wa_user_id($pdo, $tid);
            if ($packUid > 0) {
                user_apply_wa_pack_to_config($cfg, $pdo, $packUid);
            }
        }
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
    if (function_exists('wa_template_is_on') && !wa_template_is_on($config, $key)) {
        return '';
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
