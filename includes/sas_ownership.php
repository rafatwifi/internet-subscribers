<?php

/**
 * وكالة أعلى ما تنسخ مشترك وكالة مرخّصة أصغر على نفس الساس.
 * النسخ الموجودة مسبقاً تبقى؛ الجديد ما ينضاف مرة ثانية.
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
            return $empty;
        }
        $myCount = 0;
        $cst = $pdo->prepare('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = :t');
        $cst->execute(array(':t' => $tenantId));
        $myCount = (int) $cst->fetchColumn();

        $others = $pdo->query(
            'SELECT id, sas_host, sas_username FROM tenants WHERE id > 1 AND id <> ' . $tenantId
        )->fetchAll();
        $sameIds = array();
        $logins = array();
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
            if ($login !== '' && $login !== $myUser) {
                $logins[$login] = $hid;
            }
        }
        if (!$sameIds) {
            $cache[$tenantId] = array('names' => array(), 'logins' => $logins);
            return $cache[$tenantId];
        }
        $in = implode(',', array_map('intval', $sameIds));
        $counts = array();
        foreach ($pdo->query(
            'SELECT tenant_id, COUNT(*) AS c FROM sas_users_cache WHERE tenant_id IN (' . $in . ') GROUP BY tenant_id'
        )->fetchAll() as $cr) {
            $counts[(int) $cr['tenant_id']] = (int) $cr['c'];
        }
        $blockIds = array();
        foreach ($sameIds as $hid) {
            $c = isset($counts[$hid]) ? (int) $counts[$hid] : 0;
            $largerParent = ($c > $myCount && $myCount > 0);
            if (!$largerParent) {
                $blockIds[] = $hid;
            }
        }
        $names = array();
        if ($blockIds) {
            $bin = implode(',', array_map('intval', $blockIds));
            foreach ($pdo->query(
                'SELECT username, parent_name FROM sas_users_cache WHERE tenant_id IN (' . $bin . ')'
            )->fetchAll() as $r) {
                $u = strtolower(trim((string) (isset($r['username']) ? $r['username'] : '')));
                $p = strtolower(trim((string) (isset($r['parent_name']) ? $r['parent_name'] : '')));
                if ($u !== '' && $u !== $myUser) {
                    $names[$u] = true;
                }
                if ($p !== '' && $p !== $myUser) {
                    $names[$p] = true;
                }
            }
        }
        $cache[$tenantId] = array('names' => $names, 'logins' => $logins);
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
    if (isset($idx['names'][$username])) {
        return true;
    }
    if ($parentName !== '' && isset($idx['logins'][$parentName])) {
        return true;
    }
    if ($parentName !== '' && isset($idx['names'][$parentName])) {
        return true;
    }
    return false;
}
