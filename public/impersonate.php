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
    if (!is_admin_user()) {
        if ($wantsJson) {
            impersonate_json(false, $lang === 'en' ? 'Admins only' : 'للأدمن فقط');
        }
        flash('error', $lang === 'en' ? 'Admins only' : 'للأدمن فقط');
        redirect('index.php');
    }
    $q = trim((string) (isset($_GET['q']) ? $_GET['q'] : (isset($_POST['q']) ? $_POST['q'] : '')));
    $rows = array();
    if ($q !== '') {
        try {
            ensure_admin_users_table($pdo);
            $st = $pdo->prepare(
                'SELECT id, username, display_name, role, sas_manager_id
                 FROM admin_users
                 WHERE is_active = 1 AND role = "agent"
                   AND (display_name LIKE :q OR username LIKE :q2)
                 ORDER BY display_name ASC
                 LIMIT 20'
            );
            $like = '%' . $q . '%';
            $st->execute(array(':q' => $like, ':q2' => $like));
            $rows = $st->fetchAll();
        } catch (Exception $e) {
            $rows = array();
        }
    }
    $list = array();
    foreach ($rows as $r) {
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
    if ($wantsJson) {
        impersonate_json($ok, $msg, array('redirect' => 'sas.php'));
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect($ok ? 'sas.php' : 'index.php');
}

if ($wantsJson) {
    impersonate_json(false, 'Unknown action');
}
flash('error', 'Unknown action');
redirect('index.php');
