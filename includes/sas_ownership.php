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
            sas_ownership_remember_login($logins, $login, $hid, $myUser);
            if ($tname !== '' && $tname !== $login) {
                sas_ownership_remember_login($logins, $tname, $hid, $myUser);
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
                sas_ownership_remember_login($logins, $login, $hid, $myUser);
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
    if ($parentName !== '' && sas_ownership_login_hit($idx['logins'], $parentName)) {
        return true;
    }
    if (sas_ownership_login_hit($idx['logins'], $username)) {
        return true;
    }
    return false;
}

function sas_ownership_remember_login(&$logins, $login, $hid, $myUser)
{
    $login = strtolower(trim((string) $login));
    $myUser = strtolower(trim((string) $myUser));
    $hid = (int) $hid;
    if ($login === '' || $hid <= 0 || $login === $myUser) {
        return;
    }
    if (!isset($logins[$login])) {
        $logins[$login] = $hid;
    }
    $flat = str_replace('@', '', $login);
    if ($flat !== '' && $flat !== $login && $flat !== $myUser && !isset($logins[$flat])) {
        $logins[$flat] = $hid;
    }
}

function sas_ownership_fold($name)
{
    $name = strtolower(trim((string) $name));
    $name = str_replace('@', '', $name);
    return preg_replace('/[^a-z0-9]/', '', $name);
}

function sas_ownership_names_same($a, $b)
{
    $a = strtolower(trim((string) $a));
    $b = strtolower(trim((string) $b));
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }
    $fa = sas_ownership_fold($a);
    $fb = sas_ownership_fold($b);
    if ($fa !== '' && $fa === $fb) {
        return true;
    }
    if ($fa === '' || $fb === '' || strlen($fa) < 8 || strlen($fb) < 8) {
        return false;
    }
    if (strlen($fa) > strlen($fb)) {
        $swap = $fa;
        $fa = $fb;
        $fb = $swap;
    }
    if ((strlen($fb) - strlen($fa)) !== 1) {
        return false;
    }
    $ia = 0;
    $ib = 0;
    $skip = 0;
    $la = strlen($fa);
    $lb = strlen($fb);
    while ($ia < $la && $ib < $lb) {
        if ($fa[$ia] === $fb[$ib]) {
            $ia++;
            $ib++;
        } else {
            $skip++;
            $ib++;
            if ($skip > 1) {
                return false;
            }
        }
    }
    return $ia === $la && ($skip + ($lb - $ib)) === 1;
}

function sas_ownership_login_hit($logins, $name)
{
    $name = strtolower(trim((string) $name));
    if ($name === '' || !is_array($logins) || !$logins) {
        return false;
    }
    if (isset($logins[$name])) {
        return true;
    }
    $flat = str_replace('@', '', $name);
    if ($flat !== '' && isset($logins[$flat])) {
        return true;
    }
    foreach ($logins as $login => $hid) {
        if (sas_ownership_names_same($name, $login)) {
            return true;
        }
    }
    return false;
}

function portal_find_existing_member($pdo, $sasId, $name)
{
    $sasId = (int) $sasId;
    $name = trim((string) $name);
    if (!$pdo) {
        return null;
    }
    try {
        $rows = $pdo->query(
            'SELECT id, username, display_name, role, tenant_id, sas_manager_id
             FROM admin_users ORDER BY id ASC'
        )->fetchAll();
    } catch (Exception $e) {
        return null;
    }
    $hit = null;
    foreach ($rows as $r) {
        $sid = !empty($r['sas_manager_id']) ? (int) $r['sas_manager_id'] : 0;
        $same = ($sasId > 0 && $sid === $sasId);
        if (!$same && $name !== '') {
            $same = sas_ownership_names_same($name, isset($r['username']) ? $r['username'] : '')
                || sas_ownership_names_same($name, isset($r['display_name']) ? $r['display_name'] : '');
        }
        if (!$same) {
            continue;
        }
        if ($hit === null || ($r['role'] === 'admin' && $hit['role'] !== 'admin') || ((int) $r['id'] < (int) $hit['id'] && $hit['role'] !== 'admin')) {
            $hit = $r;
        }
    }
    return $hit;
}

function portal_drop_duplicate_agents($pdo)
{
    if (!$pdo) {
        return 0;
    }
    try {
        $rows = $pdo->query(
            'SELECT id, username, display_name, role, tenant_id, sas_manager_id
             FROM admin_users ORDER BY id ASC'
        )->fetchAll();
    } catch (Exception $e) {
        return 0;
    }
    $keepOf = array();
    $drop = array();
    foreach ($rows as $r) {
        $keys = array();
        $sid = !empty($r['sas_manager_id']) ? (int) $r['sas_manager_id'] : 0;
        if ($sid > 0) {
            $keys[] = 'id:' . $sid;
        }
        $fold = sas_ownership_fold(isset($r['username']) ? $r['username'] : '');
        if (strlen($fold) >= 6) {
            $keys[] = 'n:' . $fold;
        }
        foreach ($keys as $key) {
            if (!isset($keepOf[$key])) {
                $keepOf[$key] = $r;
                continue;
            }
            $keep = $keepOf[$key];
            $keepIsAdmin = isset($keep['role']) && $keep['role'] === 'admin';
            $rowIsAdmin = isset($r['role']) && $r['role'] === 'admin';
            if ($rowIsAdmin && $keepIsAdmin) {
                continue;
            }
            if ($rowIsAdmin && !$keepIsAdmin) {
                if ($keep['role'] === 'agent') {
                    $drop[(int) $keep['id']] = true;
                }
                $keepOf[$key] = $r;
                continue;
            }
            if (!$rowIsAdmin && $r['role'] === 'agent' && (int) $r['id'] !== (int) $keep['id']) {
                $drop[(int) $r['id']] = true;
            }
        }
    }
    foreach ($rows as $r) {
        if (!isset($r['role']) || $r['role'] !== 'agent' || isset($drop[(int) $r['id']])) {
            continue;
        }
        foreach ($rows as $o) {
            if ((int) $o['id'] === (int) $r['id'] || isset($drop[(int) $o['id']])) {
                continue;
            }
            if (!sas_ownership_names_same(isset($r['username']) ? $r['username'] : '', isset($o['username']) ? $o['username'] : '')) {
                continue;
            }
            if ($o['role'] === 'admin' || (int) $o['id'] < (int) $r['id']) {
                $drop[(int) $r['id']] = true;
                break;
            }
        }
    }
    $n = 0;
    foreach ($drop as $id => $yes) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        try {
            $st = $pdo->prepare('DELETE FROM admin_users WHERE id = :id AND role = "agent"');
            $st->execute(array(':id' => $id));
            if ($st->rowCount() < 1) {
                continue;
            }
            $n++;
            try {
                $pdo->prepare('DELETE FROM agent_card_prices WHERE agent_user_id = :id')->execute(array(':id' => $id));
            } catch (Exception $e2) {
            }
        } catch (Exception $e) {
        }
    }
    return $n;
}

function sas_ownership_drop_copied_cache($pdo, $tenantId)
{
    $tenantId = (int) $tenantId;
    if (!$pdo || $tenantId <= 0) {
        return;
    }
    $idx = sas_ownership_index($pdo, $tenantId);
    if (!$idx || empty($idx['logins']) || empty($idx['ready'])) {
        return;
    }
    $names = array();
    foreach ($idx['logins'] as $login => $hid) {
        if (empty($idx['ready'][(int) $hid])) {
            continue;
        }
        $login = strtolower(trim((string) $login));
        if ($login !== '') {
            $names[$login] = true;
        }
    }
    if (!$names) {
        return;
    }
    $del = $pdo->prepare(
        'DELETE FROM sas_users_cache WHERE tenant_id = :t AND (LOWER(parent_name) = :n OR LOWER(username) = :n2)'
    );
    foreach (array_keys($names) as $name) {
        try {
            $del->execute(array(':t' => $tenantId, ':n' => $name, ':n2' => $name));
        } catch (Exception $e) {
        }
    }
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
