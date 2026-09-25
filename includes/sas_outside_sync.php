<?php

/**
 * سحب تاريخ الانتهاء من الساس لليوزرات الظاهرة.
 * التفعيل/التست من لوحة الساس ما يوصل للقائمة إلا إذا نقرأ اليوزر نفسه.
 */

function sas_outside_expire_sql($row, $depth = 0)
{
    if (!is_array($row) || $depth > 4) {
        return null;
    }
    if ($depth === 0 && function_exists('sas_unwrap_user_row')) {
        $inner = sas_unwrap_user_row($row);
        if (is_array($inner)) {
            $row = $inner;
        }
    }
    if (function_exists('sas_cache_expire_at')) {
        $sql = sas_cache_expire_at($row);
        if ($sql) {
            return $sql;
        }
    }
    foreach (array('user', 'data', 'profile', 'service', 'subscription', 'account') as $k) {
        if (!isset($row[$k]) || !is_array($row[$k])) {
            continue;
        }
        $sql = sas_outside_expire_sql($row[$k], $depth + 1);
        if ($sql) {
            return $sql;
        }
    }
    return null;
}

function sas_outside_refresh_users($pdo, $config, $usernames)
{
    if (!$pdo || !is_array($usernames)) {
        return 0;
    }
    $clean = array();
    foreach ($usernames as $u) {
        $u = trim((string) $u);
        if ($u === '' || strlen($u) > 80) {
            continue;
        }
        $clean[$u] = $u;
        if (count($clean) >= 25) {
            break;
        }
    }
    if (!$clean) {
        return 0;
    }
    if (!function_exists('sas_is_ready') || !sas_is_ready($config)) {
        return 0;
    }
    $api = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
    if (!$api || !method_exists($api, 'login') || !$api->login()) {
        return 0;
    }
    if (method_exists($api, 'setTimeout')) {
        $api->setTimeout(12);
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $n = 0;
    foreach ($clean as $username) {
        $sid = 0;
        if (function_exists('sas_cache_get')) {
            $cache = sas_cache_get($pdo, $username);
            if ($cache && !empty($cache['sas_user_id'])) {
                $sid = (int) $cache['sas_user_id'];
            }
        }
        $row = null;
        if ($sid > 0 && method_exists($api, 'getUserById')) {
            $got = $api->getUserById($sid);
            $row = function_exists('sas_unwrap_user_row') ? sas_unwrap_user_row($got) : (is_array($got) ? $got : null);
        }
        if (!$row && method_exists($api, 'findUserByUsername')) {
            $found = $api->findUserByUsername($username);
            $row = function_exists('sas_unwrap_user_row') ? sas_unwrap_user_row($found) : (is_array($found) ? $found : null);
        }
        if (!is_array($row)) {
            continue;
        }
        $exp = sas_outside_expire_sql($row);
        if (!$exp) {
            continue;
        }
        $en = function_exists('sas_cache_enabled') ? (int) sas_cache_enabled($row) : 1;
        try {
            $st = $pdo->prepare(
                'UPDATE sas_users_cache
                 SET expire_at = :e, enabled = :en, synced_at = NOW()
                 WHERE tenant_id = :t AND username = :u'
            );
            $st->execute(array(
                ':e' => $exp,
                ':en' => $en ? 1 : 0,
                ':t' => $tid,
                ':u' => $username,
            ));
            if ($st->rowCount() > 0) {
                $n++;
            } else {
                $n++;
            }
        } catch (Exception $e) {
        }
    }
    return $n;
}
