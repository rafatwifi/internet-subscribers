<?php

/**
 * مشترك الوكيل المرخّص يبقى عند وكالته بعد ما يدخل رسلره.
 * الوكالة الأعلى ما تعيد نسخته، والوكالة الصغيرة ما تستلم مشتركين غيرها.
 */

function sas_ownership_host_key($host)
{
    $host = strtolower(trim((string) $host));
    $host = preg_replace('#^https?://#i', '', $host);
    return rtrim($host, '/');
}

function sas_ownership_index($pdo, $tenantId)
{
    static $cache = array();
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0) {
        $tenantId = 1;
    }
    if (isset($cache[$tenantId])) {
        return $cache[$tenantId];
    }
    $empty = array('names' => array(), 'logins' => array());
    $cache[$tenantId] = $empty;
    if (!$pdo) {
        return $empty;
    }
    try {
        $me = $pdo->prepare('SELECT sas_host, sas_username FROM tenants WHERE id = :id LIMIT 1');
        $me->execute(array(':id' => $tenantId));
        $mine = $me->fetch();
        if (!$mine) {
            return $empty;
        }
        $myHost = sas_ownership_host_key(isset($mine['sas_host']) ? $mine['sas_host'] : '');
        $myUser = strtolower(trim((string) (isset($mine['sas_username']) ? $mine['sas_username'] : '')));
        if ($myHost === '') {
            $cache[$tenantId] = array('names' => array(), 'logins' => array(), 'ready' => array(), 'me' => $myUser);
            return $cache[$tenantId];
        }
        $others = $pdo->query(
            'SELECT id, sas_host, sas_username, name FROM tenants WHERE id > 1 AND id <> ' . $tenantId
        )->fetchAll();
        $logins = array();
        $sameIds = array();
        foreach ($others as $t) {
            $hid = (int) $t['id'];
            if ($hid <= 1) {
                continue;
            }
            $h = sas_ownership_host_key(isset($t['sas_host']) ? $t['sas_host'] : '');
            if ($h === '' || $h !== $myHost) {
                continue;
            }
            $sameIds[] = $hid;
            $login = strtolower(trim((string) (isset($t['sas_username']) ? $t['sas_username'] : '')));
            $tname = strtolower(trim((string) (isset($t['name']) ? $t['name'] : '')));
            if ($login !== '' && $login !== $myUser) {
                $logins[$login] = $hid;
            }
            if ($tname !== '' && $tname !== $myUser && $tname !== $login) {
                $logins[$tname] = $hid;
            }
        }
        try {
            $acc = $pdo->query(
                'SELECT tenant_id, sas_username FROM tenant_sas_accounts WHERE tenant_id > 1 AND tenant_id <> ' . $tenantId
            )->fetchAll();
            foreach ($acc as $ar) {
                $hid = (int) $ar['tenant_id'];
                if (!in_array($hid, $sameIds, true)) {
                    continue;
                }
                $login = strtolower(trim((string) (isset($ar['sas_username']) ? $ar['sas_username'] : '')));
                if ($login !== '' && $login !== $myUser) {
                    $logins[$login] = $hid;
                }
            }
        } catch (Exception $e) {
        }
        $ready = array();
        if ($sameIds) {
            $in = implode(',', array_map('intval', $sameIds));
            foreach ($pdo->query(
                'SELECT tenant_id, COUNT(*) AS c FROM sas_users_cache WHERE tenant_id IN (' . $in . ') GROUP BY tenant_id'
            )->fetchAll() as $cr) {
                if ((int) $cr['c'] > 0) {
                    $ready[(int) $cr['tenant_id']] = true;
                }
            }
        }
        $cache[$tenantId] = array('names' => array(), 'logins' => $logins, 'ready' => $ready, 'me' => $myUser);
    } catch (Exception $e) {
        $cache[$tenantId] = $empty;
    }
    return $cache[$tenantId];
}

function sas_cache_skip_owned_elsewhere($pdo, $tenantId, $username, $parentName)
{
    $username = strtolower(trim((string) $username));
    $parentName = strtolower(trim((string) $parentName));
    if ($username === '' || !$pdo) {
        return false;
    }
    $idx = sas_ownership_index($pdo, (int) $tenantId);
    if (!$idx) {
        return false;
    }
    $myUser = isset($idx['me']) ? (string) $idx['me'] : '';
    if ($myUser !== '' && ($parentName === $myUser || $username === $myUser)) {
        return false;
    }
    if ($parentName !== '' && isset($idx['logins'][$parentName])) {
        return true;
    }
    if (isset($idx['logins'][$username])) {
        return true;
    }
    return false;
}

/** أخفِ من قائمة الوكالة الأعلى المشتركين اللي صاروا عند وكالة مرخّصة وعندها بيانات */
function sas_ownership_hide_sql($alias)
{
    if (!empty($_SESSION['admin_sas_shadow'])) {
        return '';
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
    if ($a === '') {
        $a = 'c';
    }
    global $pdo;
    if (!$pdo || !function_exists('current_tenant_id')) {
        return '';
    }
    $idx = sas_ownership_index($pdo, (int) current_tenant_id());
    if (!$idx || empty($idx['logins']) || empty($idx['ready'])) {
        return '';
    }
    $names = array();
    foreach ($idx['logins'] as $login => $hid) {
        if (empty($idx['ready'][(int) $hid])) {
            continue;
        }
        $login = strtolower(trim((string) $login));
        if ($login === '' || !preg_match('/^[a-z0-9@._-]{2,80}$/', $login)) {
            continue;
        }
        $names[$login] = true;
    }
    if (!$names) {
        return '';
    }
    $quoted = array();
    foreach (array_keys($names) as $login) {
        $quoted[] = "'" . str_replace("'", '', $login) . "'";
    }
    return ' AND (' . $a . '.parent_name IS NULL OR LOWER(' . $a . '.parent_name) NOT IN (' . implode(',', $quoted) . '))';
}
