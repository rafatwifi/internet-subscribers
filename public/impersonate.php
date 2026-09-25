<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$wantsJson = (
    (isset($_SERVER['HTTP_ACCEPT']) && strpos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_GET['ajax']) && (string) $_GET['ajax'] === '1')
    || (isset($_POST['ajax']) && (string) $_POST['ajax'] === '1')
);

function impersonate_json($ok, $message, $extra = array())
{
    header('Content-Type: application/json; charset=utf-8');
    $out = array_merge(array('ok' => (bool) $ok, 'message' => (string) $message), $extra);
    echo json_encode($out);
    exit;
}

$action = isset($_POST['action']) ? (string) $_POST['action'] : (isset($_GET['action']) ? (string) $_GET['action'] : '');

if ($action === 'search') {
    $mode = function_exists('impersonate_actor_mode') ? impersonate_actor_mode($pdo) : '';
    $q = trim((string) (isset($_GET['q']) ? $_GET['q'] : (isset($_POST['q']) ? $_POST['q'] : '')));
    $rows = array();
    $kind = isset($_GET['kind']) ? (string) $_GET['kind'] : 'system';
    if ($q !== '') {
        try {
            ensure_admin_users_table($pdo);
            if ($kind === 'child') {
                if ($mode !== 'agency' && $mode !== 'parent') {
                    impersonate_json(false, $lang === 'en' ? 'Not allowed' : 'غير مسموح');
                }
                $me = current_admin();
                $tid = $me ? (int) $me['tenant_id'] : 1;
                $meId = $me ? (int) $me['id'] : 0;
                $like = '%' . $q . '%';
                if ($mode === 'parent') {
                    $st = $pdo->prepare(
                        'SELECT id, username, display_name, role
                         FROM admin_users
                         WHERE tenant_id = :t AND reports_to_user_id = :me
                           AND role IN ("agent", "group_manager")
                           AND (display_name LIKE :q OR username LIKE :q2)
                         ORDER BY display_name ASC
                         LIMIT 20'
                    );
                    $st->execute(array(':t' => $tid, ':me' => $meId, ':q' => $like, ':q2' => $like));
                } else {
                    $st = $pdo->prepare(
                        'SELECT id, username, display_name, role
                         FROM admin_users
                         WHERE tenant_id = :t AND id <> :me
                           AND role IN ("agent", "group_manager")
                           AND (display_name LIKE :q OR username LIKE :q2)
                         ORDER BY display_name ASC
                         LIMIT 20'
                    );
                    $st->execute(array(':t' => $tid, ':me' => $meId, ':q' => $like, ':q2' => $like));
                }
            } elseif ($kind === 'agent') {
                if ($mode !== 'super') {
                    impersonate_json(false, $lang === 'en' ? 'Not allowed' : 'غير مسموح');
                }
                $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
                $st = $pdo->prepare(
                    'SELECT u.id, u.username, u.display_name, u.role
                     FROM admin_users u
                     WHERE u.is_active = 1 AND u.role IN ("agent", "group_manager")
                       AND u.tenant_id = :t
                       AND (u.display_name LIKE :q OR u.username LIKE :q2)
                       AND EXISTS (
                         SELECT 1 FROM admin_users c
                         WHERE c.reports_to_user_id = u.id AND c.tenant_id = u.tenant_id
                           AND c.role IN ("agent", "group_manager")
                       )
                     ORDER BY u.display_name ASC
                     LIMIT 20'
                );
                $like = '%' . $q . '%';
                $st->execute(array(':t' => $tid, ':q' => $like, ':q2' => $like));
            } else {
                if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
                    impersonate_json(false, $lang === 'en' ? 'Super admin only' : 'للمدير العام فقط');
                }
                $st = $pdo->prepare(
                    'SELECT id, username, display_name, role
                     FROM admin_users
                     WHERE is_active = 1 AND role = "admin" AND tenant_id > 1
                       AND (display_name LIKE :q OR username LIKE :q2)
                     ORDER BY username ASC
                     LIMIT 20'
                );
                $like = '%' . $q . '%';
                $st->execute(array(':q' => $like, ':q2' => $like));
            }
            $rows = $st->fetchAll();
            if ($kind === 'child' && function_exists('portal_agencies_under_current')) {
                $have = array();
                foreach ($rows as $haveRow) {
                    $have[(int) $haveRow['id']] = true;
                }
                foreach (portal_agencies_under_current($pdo, $q) as $sib) {
                    if (!isset($have[(int) $sib['id']])) {
                        $rows[] = $sib;
                    }
                }
            }
        } catch (Exception $e) {
            $rows = array();
        }
    }
    $skipNames = array();
    if ($kind === 'child') {
        $meNow = function_exists('current_admin') ? current_admin() : null;
        if ($meNow && !empty($meNow['username'])) {
            $skipNames[strtolower(trim((string) $meNow['username']))] = true;
        }
        if ($meNow && !empty($meNow['display_name'])) {
            $skipNames[strtolower(trim((string) $meNow['display_name']))] = true;
        }
        try {
            $tidSkip = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
            $tn = $pdo->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
            $tn->execute(array(':id' => $tidSkip));
            $tname = strtolower(trim((string) $tn->fetchColumn()));
            if ($tname !== '') {
                $skipNames[$tname] = true;
            }
        } catch (Exception $e) {
        }
    }
    $list = array();
    foreach ($rows as $r) {
        if ($kind === 'child') {
            $un = strtolower(trim((string) $r['username']));
            $dn = strtolower(trim((string) $r['display_name']));
            if (isset($skipNames[$un]) || ($dn !== '' && isset($skipNames[$dn]))) {
                continue;
            }
            $sameTenant = !isset($r['tenant_id']) || (int) $r['tenant_id'] === (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1);
            if ($sameTenant && isset($r['role']) && $r['role'] === 'admin') {
                continue;
            }
        }
        $list[] = array(
            'id' => (int) $r['id'],
            'username' => (string) $r['username'],
            'display_name' => (string) $r['display_name'],
            'label' => trim($r['display_name'] . ' (' . $r['username'] . ')'),
        );
    }
    impersonate_json(true, 'ok', array('agents' => $list));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($wantsJson) {
        impersonate_json(false, 'POST required');
    }
    redirect('index.php');
}

if (!verify_csrf(post('csrf'))) {
    if ($wantsJson) {
        impersonate_json(false, $lang === 'en' ? 'Invalid request' : 'طلب غير صالح');
    }
    flash('error', $lang === 'en' ? 'Invalid request' : 'طلب غير صالح');
    redirect('index.php');
}

if ($action === 'stop') {
    list($ok, $msg) = impersonate_stop($pdo);
    if ($wantsJson) {
        impersonate_json($ok, $msg);
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect('index.php');
}

if ($action === 'start') {
    $tid = (int) post('user_id', '0');
    list($ok, $msg) = impersonate_start($pdo, $tid);
    $dest = 'sas.php';
    if ($ok && function_exists('is_super_admin_user') && is_super_admin_user()) {
        $dest = 'index.php';
    }
    if ($wantsJson) {
        impersonate_json($ok, $msg, array('redirect' => $dest));
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect($ok ? $dest : 'index.php');
}

if ($wantsJson) {
    impersonate_json(false, 'Unknown action');
}
flash('error', 'Unknown action');
redirect('index.php');
