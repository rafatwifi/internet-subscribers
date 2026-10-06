<?php

/**
 * جلسة واتساب لهذا الحساب على البوابة المشتركة.
 * الوكيل: agent_{id}. وكالة: u{id}. ما نرجع default إلا لمدير المنصة وهو فاتح الجلسة.
 */
function whatsapp_session_for_user_row($user, $pdo = null)
{
    if (!$user || empty($user['id'])) {
        return '';
    }
    $id = (int) $user['id'];
    $role = function_exists('normalize_admin_role')
        ? normalize_admin_role(isset($user['role']) ? $user['role'] : '')
        : (isset($user['role']) ? (string) $user['role'] : '');
    $tid = isset($user['tenant_id']) ? (int) $user['tenant_id'] : 1;
    if ($role === 'agent' && empty($_SESSION['admin_sas_shadow'])) {
        return 'agent_' . $id;
    }
    if ($role === 'admin' && $tid <= 1) {
        return 'default';
    }
    if ($role === 'admin' || $tid <= 1) {
        return $role === 'admin' ? ('u' . $id) : '';
    }
    if ($pdo && $tid > 1) {
        try {
            $st = $pdo->prepare(
                'SELECT id FROM admin_users WHERE tenant_id = :t AND role = "admin" AND is_active = 1 ORDER BY id ASC LIMIT 1'
            );
            $st->execute(array(':t' => $tid));
            $oid = (int) $st->fetchColumn();
            if ($oid > 0) {
                return 'u' . $oid;
            }
        } catch (Exception $e) {
        }
    }
    return 'u' . $id;
}

function whatsapp_session_id()
{
    if (!function_exists('current_admin')) {
        return '';
    }
    $admin = current_admin();
    if (!$admin || empty($admin['id'])) {
        return '';
    }
    global $pdo;
    if (!empty($_SESSION['admin_sas_shadow']) && !empty($_SESSION['admin_sas_manager_id']) && $pdo) {
        $shadow = whatsapp_session_for_sas_parent($pdo, (int) $_SESSION['admin_sas_manager_id']);
        if ($shadow !== '') {
            return $shadow;
        }
    }
    return whatsapp_session_for_user_row($admin, isset($pdo) ? $pdo : null);
}

function whatsapp_user_match_score($row)
{
    $role = function_exists('normalize_admin_role')
        ? normalize_admin_role(isset($row['role']) ? $row['role'] : '')
        : '';
    $score = !empty($row['is_active']) ? 2 : 0;
    if ($role === 'admin') {
        $score += 4;
    } elseif ($role === 'agent') {
        $score += 2;
    }
    return $score;
}

function whatsapp_portal_user_for_sas($pdo, $sasId)
{
    static $cache = array();
    static $ensured = false;
    $sasId = (int) $sasId;
    if ($sasId <= 0 || !$pdo) {
        return null;
    }
    if (array_key_exists($sasId, $cache)) {
        return $cache[$sasId];
    }
    if (!$ensured && function_exists('ensure_admin_users_table')) {
        try {
            ensure_admin_users_table($pdo);
        } catch (Exception $e) {
        }
        $ensured = true;
    }
    $best = null;
    $bestScore = -1;
    $consider = function ($row) use (&$best, &$bestScore) {
        if (!$row || empty($row['id'])) {
            return;
        }
        $score = whatsapp_user_match_score($row);
        if ($score > $bestScore) {
            $best = $row;
            $bestScore = $score;
        }
    };
    try {
        $st = $pdo->prepare(
            'SELECT id, username, display_name, role, is_active, tenant_id, sas_manager_id, wa_cover_ids
             FROM admin_users WHERE sas_manager_id = :m'
        );
        $st->execute(array(':m' => $sasId));
        foreach ($st->fetchAll() as $row) {
            $consider($row);
        }
    } catch (Exception $e) {
    }
    if ($bestScore < 5 && function_exists('portal_sas_tree_maps')) {
        $name = '';
        $maps = portal_sas_tree_maps($pdo);
        if (!empty($maps['by_user']) && is_array($maps['by_user'])) {
            foreach ($maps['by_user'] as $uname => $mid) {
                if ((int) $mid === $sasId) {
                    $name = strtolower(trim((string) $uname));
                    break;
                }
            }
        }
        if ($name !== '') {
            try {
                $stn = $pdo->prepare(
                    'SELECT id, username, display_name, role, is_active, tenant_id, sas_manager_id, wa_cover_ids
                     FROM admin_users
                     WHERE LOWER(username) = :u OR LOWER(display_name) = :d'
                );
                $stn->execute(array(':u' => $name, ':d' => $name));
                foreach ($stn->fetchAll() as $row) {
                    $consider($row);
                }
            } catch (Exception $e) {
            }
        }
    }
    $cache[$sasId] = $best;
    return $best;
}

function whatsapp_cover_id_set($row)
{
    $raw = ($row && isset($row['wa_cover_ids'])) ? (string) $row['wa_cover_ids'] : '';
    $out = array();
    foreach (explode(',', $raw) as $part) {
        $id = (int) trim($part);
        if ($id > 0) {
            $out[$id] = true;
        }
    }
    return $out;
}

/** جلسة الإشعار لمشتركي هذا المدير: رقمه، أو رقم اللي فوقه إذا مفعّل التغطية */
function whatsapp_session_for_sas_parent($pdo, $parentId)
{
    $parentId = (int) $parentId;
    $owner = whatsapp_portal_user_for_sas($pdo, $parentId);
    if (!$owner) {
        return '';
    }
    $ownerId = (int) $owner['id'];
    $parentOf = array();
    if (function_exists('portal_sas_tree_maps')) {
        $maps = portal_sas_tree_maps($pdo);
        if (!empty($maps['parent_of']) && is_array($maps['parent_of'])) {
            $parentOf = $maps['parent_of'];
        }
    }
    $cur = $parentId;
    for ($i = 0; $i < 8; $i++) {
        if (!isset($parentOf[$cur])) {
            break;
        }
        $up = (int) $parentOf[$cur];
        if ($up <= 0) {
            break;
        }
        $anc = whatsapp_portal_user_for_sas($pdo, $up);
        if ($anc && $anc !== $owner) {
            $cover = whatsapp_cover_id_set($anc);
            if (!empty($cover[$ownerId])) {
                $sess = whatsapp_session_for_user_row($anc, $pdo);
                if ($sess !== '' && $sess !== 'default') {
                    return $sess;
                }
            }
        }
        $cur = $up;
    }
    $own = whatsapp_session_for_user_row($owner, $pdo);
    if ($own === 'default') {
        return '';
    }
    return $own;
}

function whatsapp_session_for_subscriber($pdo, $subscriberId, $parentId = 0)
{
    $parentId = (int) $parentId;
    $subscriberId = (int) $subscriberId;
    if ($parentId <= 0 && $subscriberId > 0 && $pdo) {
        try {
            $st = $pdo->prepare(
                'SELECT parent_id FROM sas_users_cache
                 WHERE local_subscriber_id = :id AND parent_id > 0
                 ORDER BY synced_at DESC LIMIT 1'
            );
            $st->execute(array(':id' => $subscriberId));
            $parentId = (int) $st->fetchColumn();
        } catch (Exception $e) {
            $parentId = 0;
        }
    }
    if ($parentId <= 0) {
        return '';
    }
    return whatsapp_session_for_sas_parent($pdo, $parentId);
}

/** هذا الرقم يرسل فقط لمن جلسة واتسابه هي جلسة الحساب المفتوح */
function whatsapp_may_message_subscriber($pdo, $subscriberId, $parentId = 0)
{
    $mine = function_exists('whatsapp_session_id') ? whatsapp_session_id() : '';
    if ($mine === '' || $mine === 'default') {
        return false;
    }
    $sess = whatsapp_session_for_subscriber($pdo, (int) $subscriberId, (int) $parentId);
    return $sess !== '' && $sess === $mine;
}

/** مدراء الساس اللي رسائلهم تطلع من واتساب الحساب المفتوح */
function whatsapp_my_sas_parent_ids($pdo)
{
    static $done = false;
    static $ready = false;
    static $ids = array();
    if ($done) {
        return array($ready, $ids);
    }
    $done = true;
    $mine = function_exists('whatsapp_session_id') ? whatsapp_session_id() : '';
    if ($mine === '' || $mine === 'default' || !$pdo || !function_exists('whatsapp_session_for_sas_parent')) {
        return array($ready, $ids);
    }
    $pool = array();
    if (function_exists('portal_sas_tree_maps')) {
        $maps = portal_sas_tree_maps($pdo);
        if (!empty($maps['parent_of']) && is_array($maps['parent_of'])) {
            $ready = true;
            foreach ($maps['parent_of'] as $child => $parent) {
                $pool[(int) $child] = true;
                $pool[(int) $parent] = true;
            }
        }
        if (!empty($maps['by_user']) && is_array($maps['by_user'])) {
            $ready = true;
            foreach ($maps['by_user'] as $mid) {
                $pool[(int) $mid] = true;
            }
        }
    }
    foreach (array_keys($pool) as $pid) {
        if ($pid > 0 && whatsapp_session_for_sas_parent($pdo, $pid) === $mine) {
            $ids[] = (int) $pid;
        }
    }
    return array($ready, $ids);
}

function whatsapp_log_scope_sql($alias = 's')
{
    global $pdo;
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
    if ($a === '') {
        $a = 's';
    }
    list($ready, $ids) = whatsapp_my_sas_parent_ids(isset($pdo) ? $pdo : null);
    if (!$ready) {
        return '';
    }
    if (!$ids) {
        return ' AND 1=0';
    }
    $in = implode(',', array_map('intval', $ids));
    $nameEq = function_exists('sas_sql_username_eq')
        ? sas_sql_username_eq($a . '.sas_username', 'cwa.username')
        : ($a . '.sas_username = cwa.username');
    return ' AND (
        SELECT cwa.parent_id FROM sas_users_cache cwa
        WHERE cwa.parent_id > 0
          AND (
            cwa.local_subscriber_id = ' . $a . '.id
            OR (
                ' . $a . '.sas_username IS NOT NULL AND ' . $a . '.sas_username <> \'\'
                AND ' . $nameEq . '
            )
          )
        ORDER BY cwa.synced_at DESC
        LIMIT 1
    ) IN (' . $in . ')';
}

function whatsapp_agency_sender_session($pdo, $userRow)
{
    if (!$userRow) {
        return '';
    }
    $tid = isset($userRow['tenant_id']) ? (int) $userRow['tenant_id'] : 0;
    if ($pdo && $tid > 1) {
        try {
            $st = $pdo->prepare(
                'SELECT id, username, display_name, role, is_active, tenant_id
                 FROM admin_users WHERE tenant_id = :t AND role = "admin" AND is_active = 1
                 ORDER BY id ASC LIMIT 1'
            );
            $st->execute(array(':t' => $tid));
            $boss = $st->fetch();
            if ($boss) {
                return whatsapp_session_for_user_row($boss, $pdo);
            }
        } catch (Exception $e) {
        }
    }
    $sess = whatsapp_session_for_user_row($userRow, $pdo);
    return $sess === 'default' ? '' : $sess;
}

function whatsapp_cover_targets($pdo)
{
    $me = function_exists('current_admin') ? current_admin() : null;
    $meId = $me ? (int) $me['id'] : 0;
    if ($meId <= 0 || !$pdo) {
        return array();
    }
    $out = array();
    $seen = array();
    $add = function ($row) use (&$out, &$seen, $meId) {
        if (!is_array($row) || empty($row['id'])) {
            return;
        }
        $id = (int) $row['id'];
        if ($id <= 0 || $id === $meId || isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $out[] = $row;
    };
    $isAgent = function_exists('is_agent_user') && is_agent_user();
    $isGm = function_exists('is_group_manager_user') && is_group_manager_user();
    if ($isGm && function_exists('group_manager_team_ids')) {
        $ids = group_manager_team_ids($pdo);
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            try {
                foreach ($pdo->query(
                    'SELECT id, username, display_name, role, is_active, tenant_id FROM admin_users WHERE id IN (' . $in . ')'
                )->fetchAll() as $row) {
                    $add($row);
                }
            } catch (Exception $e) {
            }
        }
        return $out;
    }
    if ($isAgent) {
        $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
        try {
            $st = $pdo->prepare(
                'SELECT id, username, display_name, role, is_active, tenant_id
                 FROM admin_users WHERE reports_to_user_id = :me AND tenant_id = :t AND is_active = 1'
            );
            $st->execute(array(':me' => $meId, ':t' => $tid));
            foreach ($st->fetchAll() as $row) {
                $add($row);
            }
        } catch (Exception $e) {
        }
        return $out;
    }
    if (function_exists('list_agent_users')) {
        foreach (list_agent_users($pdo, false) as $row) {
            $add($row);
        }
    }
    if (function_exists('portal_agencies_under_current')) {
        foreach (portal_agencies_under_current($pdo, '') as $row) {
            $add($row);
        }
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
    if ($tid > 1) {
        try {
            $st = $pdo->prepare(
                'SELECT id, username, display_name, role, is_active, tenant_id
                 FROM admin_users WHERE tenant_id = :t AND role = "group_manager"'
            );
            $st->execute(array(':t' => $tid));
            foreach ($st->fetchAll() as $row) {
                $add($row);
            }
        } catch (Exception $e) {
        }
    }
    return $out;
}

function whatsapp_cover_selected($pdo, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0 || !$pdo) {
        return array();
    }
    if (function_exists('ensure_admin_users_table')) {
        try {
            ensure_admin_users_table($pdo);
        } catch (Exception $e) {
        }
    }
    try {
        $st = $pdo->prepare('SELECT wa_cover_ids FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $userId));
        $raw = $st->fetchColumn();
    } catch (Exception $e) {
        return array();
    }
    $out = array();
    foreach (explode(',', (string) $raw) as $part) {
        $id = (int) trim($part);
        if ($id > 0) {
            $out[$id] = true;
        }
    }
    return $out;
}

function whatsapp_notify_owner_id($pdo)
{
    $me = function_exists('current_admin') ? current_admin() : null;
    $meId = $me ? (int) $me['id'] : 0;
    if ($meId > 0 && function_exists('is_admin_user') && is_admin_user()
        && !(function_exists('is_accountant_user') && is_accountant_user())) {
        return $meId;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
    if ($tid > 0 && $pdo) {
        try {
            $st = $pdo->prepare(
                'SELECT id FROM admin_users WHERE role = "admin" AND tenant_id = :t ORDER BY id ASC LIMIT 1'
            );
            $st->execute(array(':t' => $tid));
            $oid = (int) $st->fetchColumn();
            if ($oid > 0) {
                return $oid;
            }
        } catch (Exception $e) {
        }
    }
    return $meId;
}

function whatsapp_notifications_enabled($pdo = null, $sessionId = null)
{
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) {
        return true;
    }
    if (function_exists('ensure_admin_users_table')) {
        ensure_admin_users_table($pdo);
    }
    $uid = 0;
    $sessionId = trim((string) $sessionId);
    if (preg_match('/^u(\d+)$/', $sessionId, $m)) {
        $uid = (int) $m[1];
    } elseif (preg_match('/^agent_(\d+)$/', $sessionId, $m)) {
        $uid = (int) $m[1];
    }
    if ($uid <= 0) {
        $uid = whatsapp_notify_owner_id($pdo);
    } else {
        try {
            $stT = $pdo->prepare('SELECT tenant_id, role FROM admin_users WHERE id = :id LIMIT 1');
            $stT->execute(array(':id' => $uid));
            $rowT = $stT->fetch();
            if ($rowT && (string) $rowT['role'] !== 'admin') {
                $stO = $pdo->prepare(
                    'SELECT id FROM admin_users WHERE role = "admin" AND tenant_id = :t ORDER BY id ASC LIMIT 1'
                );
                $stO->execute(array(':t' => (int) $rowT['tenant_id']));
                $oid = (int) $stO->fetchColumn();
                if ($oid > 0) {
                    $uid = $oid;
                }
            }
        } catch (Exception $e) {
        }
    }
    if ($uid <= 0) {
        return true;
    }
    try {
        $st = $pdo->prepare('SELECT wa_notify FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $uid));
        $v = $st->fetchColumn();
        if ($v === false || $v === null) {
            return true;
        }
        return (int) $v === 1;
    } catch (Exception $e) {
        return true;
    }
}

function whatsapp_send($config, $phone, $message, $type = 'text', $sessionId = null)
{
    $wa = isset($config['whatsapp']) ? $config['whatsapp'] : array();
    $phone = normalize_phone($phone);

    if ($phone === '' || (function_exists('phone_is_placeholder') && phone_is_placeholder($phone))) {
        return array(
            'success' => false,
            'skipped' => true,
            'response' => 'رقم هاتف غير صالح أو فارغ',
            'phone' => $phone,
            'body' => $message,
            'type' => $type,
        );
    }

    if (empty($wa['enabled'])) {
        return array(
            'success' => false,
            'skipped' => true,
            'response' => 'WhatsApp disabled in config',
            'phone' => $phone,
            'body' => $message,
            'type' => $type,
        );
    }

    $notifySession = $sessionId;
    if ($notifySession === null && function_exists('whatsapp_session_id')) {
        $notifySession = whatsapp_session_id();
    }
    if (function_exists('whatsapp_notifications_enabled')) {
        global $pdo;
        if (isset($pdo) && !whatsapp_notifications_enabled($pdo, $notifySession)) {
            return array(
                'success' => false,
                'skipped' => true,
                'response' => 'إشعارات واتساب متوقفة لهذه الوكالة',
                'phone' => $phone,
                'body' => $message,
                'type' => $type,
            );
        }
    }

    if ($sessionId === null) {
        $sessionId = function_exists('whatsapp_session_id') ? whatsapp_session_id() : '';
    }
    $sessionId = trim((string) $sessionId);
    if ($sessionId === '' || $sessionId === 'default') {
        $logged = function_exists('current_admin') ? current_admin() : null;
        $loggedId = $logged ? (int) $logged['id'] : 0;
        $allowDefault = $sessionId === 'default' && $loggedId > 0
            && function_exists('is_super_admin_user') && is_super_admin_user($logged);
        if (!$allowDefault) {
            return array(
                'success' => false,
                'skipped' => true,
                'response' => 'لا توجد جلسة واتساب لهذا الوكيل',
                'phone' => $phone,
                'body' => $message,
                'type' => $type,
            );
        }
    }

    $provider = isset($wa['provider']) ? $wa['provider'] : 'meta';
    if ($provider === 'local') {
        return whatsapp_send_local($wa, $phone, $message, $type, $sessionId);
    }

    return whatsapp_send_meta($wa, $phone, $message, $type);
}

function whatsapp_gateway_fetch_status($wa, $sessionId)
{
    $base = isset($wa['local_url']) ? rtrim((string) $wa['local_url'], '/') : '';
    $key = isset($wa['local_key']) ? (string) $wa['local_key'] : '';
    $sessionId = trim((string) $sessionId);
    if ($base === '' || strpos($base, 'http') !== 0 || $sessionId === '') {
        return null;
    }
    $url = $base . '/status?key=' . rawurlencode($key) . '&session=' . rawurlencode($sessionId);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => array(
            'X-Api-Key: ' . $key,
            'X-Wa-Session: ' . $sessionId,
            'Accept: application/json',
        ),
    ));
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function whatsapp_gateway_status_for_account($wa, $sessionId)
{
    $sessionId = trim((string) $sessionId);
    $blank = array(
        'success' => true,
        'ready' => false,
        'has_qr' => false,
        'phone' => '',
        'session' => '',
        'session_ok' => false,
        'status' => 'need_link',
    );
    $data = whatsapp_gateway_fetch_status($wa, $sessionId);
    if (!is_array($data) || !isset($data['session']) || (string) $data['session'] !== $sessionId || $sessionId === '') {
        return $blank;
    }
    $data['session_ok'] = true;
    if (empty($data['ready'])) {
        $data['phone'] = '';
    }
    return $data;
}

function whatsapp_send_local($wa, $phone, $message, $type, $sessionId = '')
{
    $base = isset($wa['local_url']) ? rtrim($wa['local_url'], '/') : 'http://127.0.0.1:3001';
    $key = isset($wa['local_key']) ? (string) $wa['local_key'] : 'local-secret-change-me';
    $url = $base . '/send';

    $sessionId = trim((string) $sessionId);
    if ($sessionId === '') {
        $sessionId = function_exists('whatsapp_session_id') ? whatsapp_session_id() : '';
    }
    if ($sessionId === '') {
        return array(
            'success' => false,
            'skipped' => true,
            'response' => 'لا توجد جلسة واتساب لهذا الوكيل',
            'phone' => $phone,
            'body' => $message,
            'type' => $type,
        );
    }
    $gate = whatsapp_gateway_status_for_account($wa, $sessionId);
    $fromPhone = isset($gate['phone']) ? trim((string) $gate['phone']) : '';
    if (empty($gate['ready']) || !isset($gate['session']) || (string) $gate['session'] !== $sessionId) {
        return array(
            'success' => false,
            'skipped' => true,
            'response' => 'يرجى ربط واتساب',
            'phone' => $phone,
            'from_phone' => $fromPhone,
            'body' => $message,
            'type' => $type,
        );
    }
    $payload = array(
        'phone' => $phone,
        'message' => $message,
        'session' => $sessionId,
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'X-Api-Key: ' . $key,
            'X-Wa-Session: ' . $sessionId,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 60,
    ));

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($raw !== false && $code >= 200 && $code < 300);
    $decoded = null;
    $noWhatsapp = false;
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $errText = isset($decoded['error']) ? (string) $decoded['error'] : '';
            $sentAnyway = stripos($errText, 'did not confirm') !== false;
            if (isset($decoded['success']) && !$decoded['success'] && !$sentAnyway) {
                $ok = false;
            }
            if ($sentAnyway) {
                $ok = true;
                $decoded['success'] = true;
                $decoded['acked'] = true;
                $raw = json_encode($decoded);
            }
            $echo = isset($decoded['session']) ? (string) $decoded['session'] : '';
            $msgId = (isset($decoded['result']) && is_array($decoded['result']) && !empty($decoded['result']['id']))
                ? (string) $decoded['result']['id'] : '';
            if ($ok && !$sentAnyway && ($echo !== $sessionId || $msgId === '' || empty($decoded['acked']))) {
                $ok = false;
                $decoded['success'] = false;
                $decoded['code'] = 'no_ack';
                if (empty($decoded['error'])) {
                    $decoded['error'] = 'WhatsApp did not confirm the message';
                }
                $raw = json_encode($decoded);
            }
            $errText = '';
            if (isset($decoded['error'])) {
                $errText = (string) $decoded['error'];
            }
            if (isset($decoded['code']) && $decoded['code'] === 'no_whatsapp') {
                $noWhatsapp = true;
            }
            if ($errText !== '' && (
                stripos($errText, 'not on WhatsApp') !== false
                || stripos($errText, 'no_whatsapp') !== false
            )) {
                $noWhatsapp = true;
            }
        }
        if (!$ok && !$noWhatsapp && stripos((string) $raw, 'not on WhatsApp') !== false) {
            $noWhatsapp = true;
        }
    }

    return array(
        'success' => $ok,
        'skipped' => false,
        'no_whatsapp' => $noWhatsapp,
        'http_code' => $code,
        'response' => ($raw !== false) ? $raw : $err,
        'phone' => $phone,
        'from_phone' => $fromPhone,
        'body' => $message,
        'type' => $type,
    );
}

function whatsapp_send_meta($wa, $phone, $message, $type)
{
    $token = isset($wa['token']) ? (string) $wa['token'] : '';
    $phoneId = isset($wa['phone_number_id']) ? (string) $wa['phone_number_id'] : '';
    $version = isset($wa['api_version']) ? (string) $wa['api_version'] : 'v21.0';

    if ($token === '' || $phoneId === '' || strpos($token, 'YOUR_') !== false) {
        return array(
            'success' => false,
            'skipped' => true,
            'response' => 'WhatsApp credentials missing',
            'phone' => $phone,
            'body' => $message,
            'type' => $type,
        );
    }

    $url = 'https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages';

    $payload = array(
        'messaging_product' => 'whatsapp',
        'to' => $phone,
        'type' => 'text',
        'text' => array(
            'preview_url' => false,
            'body' => $message,
        ),
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ),
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ));

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($raw !== false && $code >= 200 && $code < 300);

    return array(
        'success' => $ok,
        'skipped' => false,
        'http_code' => $code,
        'response' => ($raw !== false) ? $raw : $err,
        'phone' => $phone,
        'body' => $message,
        'type' => $type,
    );
}

/**
 * الرقم مو على واتساب (من رد البوابة / سجل الرسائل)
 */
if (!function_exists('subscriber_msg_is_no_whatsapp')) {
function subscriber_msg_is_no_whatsapp($response)
{
    $response = (string) $response;
    if ($response === '') {
        return false;
    }
    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        if (array_key_exists('no_whatsapp', $decoded)) {
            return !empty($decoded['no_whatsapp']);
        }
        if (isset($decoded['code']) && (string) $decoded['code'] === 'no_whatsapp') {
            return true;
        }
        $err = '';
        if (isset($decoded['error'])) {
            $err .= ' ' . (string) $decoded['error'];
        }
        if (isset($decoded['message'])) {
            $err .= ' ' . (string) $decoded['message'];
        }
        return (stripos($err, 'not on WhatsApp') !== false)
            || (strpos($err, 'لا يتوفر واتساب') !== false)
            || (strpos($err, 'ماعنده واتساب') !== false)
            || (strpos($err, 'ماكو واتساب') !== false);
    }
    return (stripos($response, 'not on WhatsApp') !== false)
        || (strpos($response, 'لا يتوفر واتساب') !== false)
        || (strpos($response, 'ماعنده واتساب') !== false)
        || (strpos($response, 'ماكو واتساب') !== false);
}
}

if (!function_exists('subscriber_phone_missing')) {
function subscriber_phone_missing($phone)
{
    $phone = trim((string) $phone);
    if ($phone === '' || $phone === '-' || $phone === '—') {
        return true;
    }
    if (function_exists('phone_is_placeholder') && phone_is_placeholder($phone)) {
        return true;
    }
    return false;
}
}

if (!function_exists('phones_wa_same')) {
function phones_wa_same($a, $b)
{
    $ka = phone_wa_keys($a);
    $kb = phone_wa_keys($b);
    if (!$ka || !$kb) {
        return false;
    }
    foreach ($ka as $k) {
        if (in_array($k, $kb, true)) {
            return true;
        }
    }
    return false;
}
}

if (!function_exists('phone_wa_keys')) {
function phone_wa_keys($phone)
{
    $d = preg_replace('/\D+/', '', (string) $phone);
    if ($d === '' || $d === '964000000000') {
        return array();
    }
    $keys = array($d);
    if (function_exists('normalize_phone')) {
        $n = normalize_phone($d);
        if ($n !== '') {
            $keys[] = $n;
        }
    }
    if (strlen($d) >= 10) {
        $tail = substr($d, -10);
        $keys[] = $tail;
        $keys[] = '964' . $tail;
        if (strpos($tail, '7') === 0) {
            $keys[] = '0' . $tail;
        }
    }
    if (strpos($d, '964') === 0 && strlen($d) >= 12) {
        $rest = substr($d, 3);
        $keys[] = $rest;
        $keys[] = '0' . $rest;
    }
    return array_values(array_unique($keys));
}
}

if (!function_exists('phones_known_on_whatsapp')) {
function phones_known_on_whatsapp($pdo = null, $addPhones = null)
{
    static $set = null;
    if ($set === null) {
        $set = array();
        if (!$pdo && isset($GLOBALS['pdo'])) {
            $pdo = $GLOBALS['pdo'];
        }
        if ($pdo) {
            try {
                $rows = $pdo->query(
                    "SELECT DISTINCT phone FROM message_logs
                     WHERE success = 1 AND phone IS NOT NULL AND phone <> ''"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $p) {
                    foreach (phone_wa_keys($p) as $k) {
                        $set[$k] = true;
                    }
                }
            } catch (Exception $e) {
            }
            try {
                $rows2 = $pdo->query(
                    "SELECT DISTINCT s.phone FROM subscribers s
                     INNER JOIN message_logs m ON m.subscriber_id = s.id AND m.success = 1
                     WHERE s.phone IS NOT NULL AND s.phone <> ''"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows2 as $p) {
                    foreach (phone_wa_keys($p) as $k) {
                        $set[$k] = true;
                    }
                }
            } catch (Exception $e2) {
            }
            try {
                $rows3 = $pdo->query(
                    "SELECT DISTINCT c.phone FROM sas_users_cache c
                     INNER JOIN subscribers s ON s.id = c.local_subscriber_id
                     INNER JOIN message_logs m ON m.subscriber_id = s.id AND m.success = 1
                     WHERE c.phone IS NOT NULL AND c.phone <> ''"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows3 as $p) {
                    foreach (phone_wa_keys($p) as $k) {
                        $set[$k] = true;
                    }
                }
            } catch (Exception $e3) {
            }
        }
    }
    if ($addPhones) {
        if (!is_array($addPhones)) {
            $addPhones = array($addPhones);
        }
        foreach ($addPhones as $p) {
            foreach (phone_wa_keys($p) as $k) {
                $set[$k] = true;
            }
        }
    }
    return $set;
}
}

if (!function_exists('phones_known_register_from_rows')) {
function phones_known_register_from_rows($rows, $pdo = null)
{
    if (!$rows || !is_array($rows)) {
        return;
    }
    $add = array();
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['last_msg_ok'])) {
            continue;
        }
        if (!empty($row['local_phone'])) {
            $add[] = $row['local_phone'];
        }
        if (!empty($row['phone'])) {
            $add[] = $row['phone'];
        }
        if (!empty($row['last_msg_phone'])) {
            $add[] = $row['last_msg_phone'];
        }
    }
    if ($add) {
        phones_known_on_whatsapp($pdo, $add);
    }
}
}

if (!function_exists('subscriber_no_whatsapp_for_current')) {
/**
 * آخر فشل "ماكو واتساب" يخص الرقم الحالي فقط.
 * إذا تغيّر رقم المشترك، الفشل القديم ما ينعرض كماكو واتساب.
 */
function subscriber_no_whatsapp_for_current($response, $logPhone, $currentPhones, $everOk = false)
{
    if (!subscriber_msg_is_no_whatsapp($response)) {
        return false;
    }
    if (!is_array($currentPhones)) {
        $currentPhones = array($currentPhones);
    }
    $shown = '';
    foreach ($currentPhones as $p) {
        if ((string) $p !== '') {
            $shown = (string) $p;
            break;
        }
    }
    $logPhone = (string) $logPhone;
    if ($logPhone !== '' && $shown !== '' && !phones_wa_same($logPhone, $shown)) {
        return false;
    }
    if (function_exists('subscriber_should_hide_no_whatsapp')
        && subscriber_should_hide_no_whatsapp($currentPhones, $everOk)) {
        return false;
    }
    return true;
}
}

if (!function_exists('wa_miss_html')) {
function wa_miss_html($noWa)
{
    if (!$noWa) {
        return '';
    }
    return '<span class="wa-miss" title="تحذير: الرقم موجود بس مو على واتساب">⊘</span>';
}
}

if (!function_exists('subscriber_row_is_no_whatsapp')) {
function subscriber_row_is_no_whatsapp($row)
{
    if (!is_array($row)) {
        return false;
    }
    $hasMsg = isset($row['last_msg_at']) && $row['last_msg_at'] !== null && $row['last_msg_at'] !== '';
    if (!$hasMsg || !empty($row['last_msg_ok'])) {
        return false;
    }
    $shown = '';
    if (isset($row['phone']) && $row['phone'] !== null && (string) $row['phone'] !== '') {
        $shown = (string) $row['phone'];
    } elseif (isset($row['local_phone']) && $row['local_phone'] !== null && (string) $row['local_phone'] !== '') {
        $shown = (string) $row['local_phone'];
    }
    return subscriber_no_whatsapp_for_current(
        isset($row['last_msg_response']) ? $row['last_msg_response'] : '',
        isset($row['last_msg_phone']) ? $row['last_msg_phone'] : '',
        $shown,
        false
    );
}
}

if (!function_exists('subscriber_whatsapp_phone')) {
function subscriber_whatsapp_phone($pdo, $subscriberId, $fallback = '')
{
    $subscriberId = (int) $subscriberId;
    $candidates = array();
    $live = '';
    $cachePhones = array();
    if ($subscriberId > 0 && $pdo) {
        try {
            $stU = $pdo->prepare('SELECT sas_username, phone FROM subscribers WHERE id = :id LIMIT 1');
            $stU->execute(array(':id' => $subscriberId));
            $loc = $stU->fetch();
            if ($loc) {
                $live = isset($loc['phone']) ? (string) $loc['phone'] : '';
                $u = isset($loc['sas_username']) ? trim((string) $loc['sas_username']) : '';
                if ($u !== '') {
                    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
                    $sqlC = 'SELECT phone FROM sas_users_cache WHERE username = :u AND phone IS NOT NULL AND phone <> \'\'';
                    $parC = array(':u' => $u);
                    if ($tid > 0) {
                        $sqlC .= ' AND tenant_id = :t';
                        $parC[':t'] = $tid;
                    }
                    $sqlC .= ' LIMIT 1';
                    $stC = $pdo->prepare($sqlC);
                    $stC->execute($parC);
                    $cp = $stC->fetchColumn();
                    if ($cp !== false && (string) $cp !== '') {
                        $cachePhones[] = (string) $cp;
                    }
                }
            }
        } catch (Exception $e2) {
        }
    }
    if ($live !== '') {
        $candidates[] = $live;
    }
    if ((string) $fallback !== '') {
        $candidates[] = (string) $fallback;
    }
    foreach ($cachePhones as $cachePhone) {
        $candidates[] = $cachePhone;
    }
    if (function_exists('phone_first_valid')) {
        return phone_first_valid($candidates);
    }
    foreach ($candidates as $p) {
        $p = trim((string) $p);
        if ($p !== '' && $p !== '964000000000') {
            return $p;
        }
    }
    return '';
}
}

if (!function_exists('subscriber_should_hide_no_whatsapp')) {
function subscriber_should_hide_no_whatsapp($phones, $everOk = false)
{
    if ($everOk) {
        return true;
    }
    if (!is_array($phones)) {
        $phones = array($phones);
    }
    $known = phones_known_on_whatsapp();
    foreach ($phones as $phone) {
        foreach (phone_wa_keys($phone) as $k) {
            if (!empty($known[$k])) {
                return true;
            }
        }
    }
    return false;
}
}

if (!function_exists('msg_table_status_html')) {
function msg_table_status_html($hasMsg, $msgOk, $noWa, $rowId, $logId, $lang = 'ar', $noPhone = false)
{
    $retryTitle = ($lang === 'en') ? 'Retry send' : 'إعادة المحاولة';
    $noWaLabel = ($lang === 'en') ? 'No WhatsApp' : 'ماعنده واتساب';
    $noWaTitle = ($lang === 'en') ? 'Warning: this number is not on WhatsApp' : 'تحذير: الرقم موجود بس مو على واتساب';
    $noPhoneLabel = ($lang === 'en') ? 'No number' : 'ماعنده رقم';
    $noPhoneTitle = ($lang === 'en') ? 'Warning: this subscriber has no phone number' : 'تحذير: المشترك ما عنده رقم';
    $failTitle = ($lang === 'en') ? 'Warning: send failed' : 'تحذير: فشل الإرسال';
    $retryBtn = '';
    if ((int) $logId > 0) {
        $retryBtn = '<button type="button" class="msg-retry-btn" data-retry="1" data-id="'
            . e((string) $rowId) . '" data-log-id="' . (int) $logId . '" title="' . e($retryTitle)
            . '" aria-label="' . e($retryTitle) . '">'
            . '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">'
            . '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" d="M13.15 3.45A6 6 0 1 0 14 8"/>'
            . '<path fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" d="M13.2 1.55v3.15h-3.15"/>'
            . '</svg></button>';
    }
    $html = '<span class="msg-status-row">';
    if ($noPhone) {
        $html .= '<span class="msg-nowa msg-nophone" title="' . e($noPhoneTitle) . '">' . e($noPhoneLabel) . '</span>';
    } elseif ($noWa) {
        $html .= '<span class="msg-nowa" title="' . e($noWaTitle) . '">' . e($noWaLabel) . '</span>';
    } elseif (!$hasMsg) {
        $html .= '<span class="dot-msg off" title="' . e($lang === 'en' ? 'No message sent' : 'لم تُرسل رسالة') . '"></span>';
    } elseif ($msgOk) {
        $html .= '<span class="dot-msg ok" title="' . e($lang === 'en' ? 'Sent' : 'أُرسلت') . '"></span>';
    } else {
        $html .= '<span class="dot-msg fail" title="' . e($failTitle) . '"></span>';
        $html .= $retryBtn;
    }
    $html .= '</span>';
    return $html;
}
}

function whatsapp_fail_user_message($result, $fallback = 'فشل إرسال واتساب')
{
    if (!empty($result['success'])) {
        return '';
    }
    if (!empty($result['no_whatsapp'])) {
        return 'لا يتوفر واتساب لدى المشترك';
    }
    $resp = isset($result['response']) ? (string) $result['response'] : '';
    if ($resp !== '' && function_exists('subscriber_msg_is_no_whatsapp') && subscriber_msg_is_no_whatsapp($resp)) {
        return 'لا يتوفر واتساب لدى المشترك';
    }
    if (!empty($result['skipped'])) {
        return 'واتساب غير مفعّل بالإعدادات';
    }

    $err = '';
    $decoded = json_decode($resp, true);
    if (is_array($decoded) && isset($decoded['error']) && (string) $decoded['error'] !== '') {
        $err = (string) $decoded['error'];
    } elseif ($resp !== '') {
        $err = $resp;
    }

    $http = isset($result['http_code']) ? (int) $result['http_code'] : 0;
    if ($http === 0 && ($err === '' || stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false || stripos($err, 'Failed to connect') !== false)) {
        if (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) {
            return 'واتساب متصل لكن الإرسال تأخر — أعد المحاولة';
        }
        return 'السيرفر ما وصل لبوابة واتساب — تأكد أن الجهاز شغّال';
    }
    if ($err !== '') {
        if (stripos($err, 'did not confirm') !== false || stripos($err, 'no_ack') !== false || stripos($err, 'Could not verify') !== false) {
            return 'واتساب ما أكد الإرسال — الرسالة ما وصلت للتلفون';
        }
        if (stripos($err, 'rejected the message') !== false || stripos($err, 'send_rejected') !== false) {
            return 'واتساب رفض الرسالة';
        }
        if (stripos($err, 'not ready') !== false || stripos($err, 'Scan QR') !== false) {
            return 'فشلت إعادة الإرسال — تأكد أن واتساب متصل';
        }
        if (stripos($err, 'circular') !== false || stripos($err, 'Converting circular') !== false) {
            return 'الرسالة انرسلت لكن رد البوابة فشل — حدّث ملف البوابة وأعد تشغيلها';
        }
        if (stripos($err, 'timeout') !== false || stripos($err, 'timed out') !== false) {
            return 'واتساب متصل لكن الإرسال تأخر — أعد المحاولة';
        }
        if (stripos($err, 'Connection Closed') !== false || stripos($err, 'Connection Terminated') !== false) {
            return 'انقطع اتصال واتساب أثناء الإرسال — أعد تشغيل البوابة ثم أعد المحاولة';
        }
        if (stripos($err, 'Forbidden') !== false) {
            return 'مفتاح البوابة غير مطابق';
        }
        $short = trim(preg_replace('/\s+/', ' ', $err));
        if (function_exists('mb_substr')) {
            if (mb_strlen($short, 'UTF-8') > 140) {
                $short = mb_substr($short, 0, 137, 'UTF-8') . '...';
            }
        } elseif (strlen($short) > 140) {
            $short = substr($short, 0, 137) . '...';
        }
        return 'فشل الإرسال: ' . $short;
    }
    return $fallback;
}

function ensure_message_log_extra($pdo)
{
    static $done = false;
    if ($done || !$pdo) {
        return;
    }
    $done = true;
    $cols = array(
        'from_phone' => 'VARCHAR(40) NULL DEFAULT NULL',
        'attempt' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
        'next_retry_at' => 'DATETIME NULL DEFAULT NULL',
        'fail_reason' => 'VARCHAR(255) NULL DEFAULT NULL',
    );
    foreach ($cols as $name => $def) {
        try {
            $c = $pdo->query('SHOW COLUMNS FROM message_logs LIKE ' . $pdo->quote($name))->fetch();
            if (!$c) {
                $pdo->exec('ALTER TABLE message_logs ADD COLUMN ' . $name . ' ' . $def);
            }
        } catch (Exception $e) {
        }
    }
    try {
        $idx = $pdo->query("SHOW INDEX FROM message_logs WHERE Key_name = 'idx_msg_retry'")->fetch();
        if (!$idx) {
            $pdo->exec('ALTER TABLE message_logs ADD INDEX idx_msg_retry (success, next_retry_at)');
        }
    } catch (Exception $e) {
    }
}

function message_log_fail_text($row)
{
    if (is_array($row) && !empty($row['fail_reason'])) {
        return trim((string) $row['fail_reason']);
    }
    $resp = (is_array($row) && isset($row['response_json'])) ? (string) $row['response_json'] : '';
    if ($resp === '') {
        return '';
    }
    return whatsapp_fail_user_message(array(
        'success' => false,
        'response' => $resp,
    ));
}

function message_log_schedule_retry($result, $attempt)
{
    $attempt = (int) $attempt;
    $type = isset($result['type']) ? (string) $result['type'] : '';
    if ($type === 'expiry_auto' || strpos($type, 'expiry_auto') === 0) {
        return null;
    }
    if (!empty($result['success']) || $attempt >= 3) {
        return null;
    }
    if (!empty($result['no_whatsapp']) || !empty($result['skipped'])) {
        return null;
    }
    return date('Y-m-d H:i:s', time() + 300);
}

function log_message($pdo, $subscriberId, $result)
{
    ensure_message_log_extra($pdo);
    $ok = !empty($result['success']);
    $attempt = isset($result['attempt']) ? (int) $result['attempt'] : 1;
    if ($attempt < 1) {
        $attempt = 1;
    }
    if ($attempt > 3) {
        $attempt = 3;
    }
    $reason = $ok ? '' : whatsapp_fail_user_message($result);
    if (function_exists('mb_substr') && mb_strlen($reason, 'UTF-8') > 250) {
        $reason = mb_substr($reason, 0, 247, 'UTF-8') . '...';
    } elseif (strlen($reason) > 250) {
        $reason = substr($reason, 0, 247) . '...';
    }
    $next = message_log_schedule_retry($result, $attempt);
    $nextSql = ($next === null || $next === '') ? 'NULL' : $pdo->quote($next);
    $stmt = $pdo->prepare(
        'INSERT INTO message_logs
            (subscriber_id, phone, message_type, body, success, response_json, from_phone, attempt, next_retry_at, fail_reason)
         VALUES
            (:subscriber_id, :phone, :message_type, :body, :success, :response_json, :from_phone, :attempt, ' . $nextSql . ', :fail_reason)'
    );
    $stmt->execute(array(
        ':subscriber_id' => $subscriberId,
        ':phone' => isset($result['phone']) ? $result['phone'] : '',
        ':message_type' => isset($result['type']) ? $result['type'] : 'text',
        ':body' => isset($result['body']) ? $result['body'] : '',
        ':success' => $ok ? 1 : 0,
        ':response_json' => isset($result['response']) && is_string($result['response'])
            ? $result['response']
            : json_encode($result),
        ':from_phone' => isset($result['from_phone']) ? (string) $result['from_phone'] : '',
        ':attempt' => $attempt,
        ':fail_reason' => $reason,
    ));
}

function message_log_apply_result($pdo, $logId, $result, $attempt)
{
    ensure_message_log_extra($pdo);
    $logId = (int) $logId;
    $ok = !empty($result['success']);
    $attempt = (int) $attempt;
    if ($attempt < 1) {
        $attempt = 1;
    }
    if ($attempt > 3) {
        $attempt = 3;
    }
    $reason = $ok ? '' : whatsapp_fail_user_message($result);
    if (function_exists('mb_substr') && mb_strlen($reason, 'UTF-8') > 250) {
        $reason = mb_substr($reason, 0, 247, 'UTF-8') . '...';
    } elseif (strlen($reason) > 250) {
        $reason = substr($reason, 0, 247) . '...';
    }
    $next = message_log_schedule_retry($result, $attempt);
    $nextSql = ($next === null || $next === '') ? 'NULL' : $pdo->quote($next);
    $stmt = $pdo->prepare(
        'UPDATE message_logs
         SET success = :success, response_json = :response_json, phone = :phone,
             from_phone = :from_phone, attempt = :attempt, next_retry_at = ' . $nextSql . ', fail_reason = :fail_reason
         WHERE id = :id'
    );
    $stmt->execute(array(
        ':success' => $ok ? 1 : 0,
        ':response_json' => isset($result['response']) && is_string($result['response'])
            ? $result['response']
            : json_encode($result),
        ':phone' => isset($result['phone']) ? $result['phone'] : '',
        ':from_phone' => isset($result['from_phone']) ? (string) $result['from_phone'] : '',
        ':attempt' => $attempt,
        ':fail_reason' => $reason,
        ':id' => $logId,
    ));
}

/** تعليم أن تذكير قرب الانتهاء أُرسل لهذا التاريخ */
function mark_expiry_notice_sent($pdo, $subscriberId, $endDate)
{
    $subscriberId = (int) $subscriberId;
    $endDate = date('Y-m-d', strtotime((string) $endDate));
    if ($subscriberId <= 0 || !$endDate || strtotime($endDate) === false) {
        return;
    }
    if (function_exists('ensure_sas_expiry_remind_column')) {
        ensure_sas_expiry_remind_column($pdo);
    }
    try {
        $pdo->prepare(
            'UPDATE sas_users_cache SET expiry_remind_for_expire = :e
             WHERE local_subscriber_id = :id'
        )->execute(array(':e' => $endDate, ':id' => $subscriberId));
    } catch (Exception $e) {
    }
    try {
        $st = $pdo->prepare('SELECT sas_username FROM subscribers WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $subscriberId));
        $u = trim((string) $st->fetchColumn());
        if ($u !== '') {
            $pdo->prepare(
                'UPDATE sas_users_cache SET expiry_remind_for_expire = :e WHERE username = :u'
            )->execute(array(':e' => $endDate, ':u' => $u));
        }
    } catch (Exception $e) {
    }
}

/**
 * خريطة: subscriber_id => true إذا سبق إرسال إشعار قرب انتهاء لنفس تاريخ النهاية.
 * $rows عناصر فيها subscriber_id و end_date
 */
function subscribers_expiry_notice_map($pdo, $rows)
{
    $map = array();
    if (!$rows || !is_array($rows)) {
        return $map;
    }
    $byEnd = array();
    foreach ($rows as $row) {
        $sid = isset($row['subscriber_id']) ? (int) $row['subscriber_id'] : (isset($row['id']) ? (int) $row['id'] : 0);
        $end = isset($row['end_date']) ? date('Y-m-d', strtotime((string) $row['end_date'])) : '';
        if ($sid <= 0 || $end === '' || strtotime($end) === false) {
            continue;
        }
        if (!isset($byEnd[$end])) {
            $byEnd[$end] = array();
        }
        $byEnd[$end][$sid] = true;
    }
    if (!$byEnd) {
        return $map;
    }
    if (function_exists('ensure_sas_expiry_remind_column')) {
        try {
            ensure_sas_expiry_remind_column($pdo);
        } catch (Exception $e) {
        }
    }
    foreach ($byEnd as $end => $idsMap) {
        $ids = array_map('intval', array_keys($idsMap));
        if (!$ids) {
            continue;
        }
        $in = implode(',', $ids);
        try {
            $q = $pdo->query(
                "SELECT local_subscriber_id AS sid FROM sas_users_cache
                 WHERE local_subscriber_id IN ($in) AND expiry_remind_for_expire = " . $pdo->quote($end)
            );
            if ($q) {
                while ($r = $q->fetch()) {
                    $map[(int) $r['sid']] = true;
                }
            }
        } catch (Exception $e) {
        }
        try {
            $q2 = $pdo->query(
                "SELECT s.id AS sid FROM subscribers s
                 INNER JOIN sas_users_cache c ON c.username = s.sas_username
                 WHERE s.id IN ($in) AND c.expiry_remind_for_expire = " . $pdo->quote($end)
            );
            if ($q2) {
                while ($r = $q2->fetch()) {
                    $map[(int) $r['sid']] = true;
                }
            }
        } catch (Exception $e) {
        }
        // سجل رسائل ناجحة قرب الانتهاء تتضمن تاريخ النهاية
        try {
            $like = '%' . $end . '%';
            $st = $pdo->prepare(
                "SELECT DISTINCT subscriber_id FROM message_logs
                 WHERE success = 1
                   AND subscriber_id IN ($in)
                   AND message_type IN ('expiry_auto','bulk_filter','days_left','remind_days')
                   AND body LIKE :like"
            );
            $st->execute(array(':like' => $like));
            while ($sid = $st->fetchColumn()) {
                $map[(int) $sid] = true;
            }
        } catch (Exception $e) {
        }
    }
    return $map;
}

function delete_failed_message_log($pdo, $logId)
{
    $logId = (int) $logId;
    if ($logId <= 0) {
        return array(false, 'معرّف غير صالح');
    }
    try {
        $st = $pdo->prepare('SELECT id, subscriber_id FROM message_logs WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $logId));
        $row = $st->fetch();
        if (!$row) {
            return array(false, 'الرسالة غير موجودة');
        }
        $sid = isset($row['subscriber_id']) ? (int) $row['subscriber_id'] : 0;
        if ($sid > 0 && function_exists('user_can_access_subscriber') && !user_can_access_subscriber($pdo, $sid)) {
            return array(false, 'ما عندك صلاحية لهذه الرسالة');
        }
        $pdo->prepare('DELETE FROM message_logs WHERE id = :id')
            ->execute(array(':id' => $logId));
        return array(true, 'تم الحذف');
    } catch (Exception $e) {
        return array(false, 'فشل الحذف');
    }
}

/**
 * فشل انحل لاحقاً: إرسال ناجح بعده لنفس المشترك (نفس النص أو نفس نوع الرسالة).
 * يرجع: [log_id => true, ...]
 */
function message_logs_resolved_map($pdo, $logRows)
{
    $map = array();
    $failIds = array();
    foreach ($logRows as $row) {
        if (empty($row['success']) && !empty($row['id']) && !empty($row['subscriber_id'])) {
            $failIds[] = (int) $row['id'];
        }
    }
    if (!$failIds) {
        return $map;
    }
    $failIds = array_values(array_unique($failIds));
    $in = implode(',', array_map('intval', $failIds));
    $sql = "SELECT m1.id
            FROM message_logs m1
            WHERE m1.id IN ($in)
              AND m1.success = 0
              AND EXISTS (
                  SELECT 1 FROM message_logs m2
                  WHERE m2.subscriber_id = m1.subscriber_id
                    AND m2.success = 1
                    AND m2.id > m1.id
                    AND (
                        m2.body = m1.body
                        OR REPLACE(m2.message_type, '_retry', '') = REPLACE(m1.message_type, '_retry', '')
                    )
              )";
    try {
        foreach ($pdo->query($sql)->fetchAll() as $r) {
            $map[(int) $r['id']] = true;
        }
    } catch (Exception $e) {
        // لا تكسر صفحة السجل
    }
    return $map;
}

/**
 * إعادة محاولة إرسال رسالة فاشلة من السجل
 * يرجع array($ok, $message)
 */
function wa_session_for_subscriber($pdo, $subscriberId)
{
    $subscriberId = (int) $subscriberId;
    if ($subscriberId <= 0 || !$pdo) {
        return '';
    }
    try {
        $st = $pdo->prepare('SELECT tenant_id FROM subscribers WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $subscriberId));
        $tid = (int) $st->fetchColumn();
    } catch (Exception $e) {
        return '';
    }
    if ($tid <= 0) {
        return '';
    }
    try {
        $st = $pdo->prepare(
            'SELECT id FROM admin_users WHERE tenant_id = :t AND role = "admin" AND is_active = 1 ORDER BY id ASC LIMIT 1'
        );
        $st->execute(array(':t' => $tid));
        $oid = (int) $st->fetchColumn();
    } catch (Exception $e) {
        return '';
    }
    if ($oid <= 0) {
        return '';
    }
    return 'u' . $oid;
}

function retry_failed_message($pdo, $config, $logId, $subscriberId = 0)
{
    $logId = (int) $logId;
    if ($logId <= 0) {
        return array(false, 'ما تحددت الرسالة. اضغط إعادة الإرسال مرة ثانية.');
    }
    $sql = 'SELECT m.*, s.phone AS sub_phone, s.id AS sid
            FROM message_logs m
            LEFT JOIN subscribers s ON s.id = m.subscriber_id
            WHERE m.id = :id';
    $params = array(':id' => $logId);
    if ($subscriberId > 0) {
        $sql .= ' AND m.subscriber_id = :sid';
        $params[':sid'] = (int) $subscriberId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $log = $stmt->fetch();
    if (!$log) {
        return array(false, 'الرسالة غير موجودة بالسجل. حدّث الصفحة وحاول مرة ثانية.');
    }
    $body = trim((string) $log['body']);
    if ($body === '') {
        return array(false, 'نص الرسالة فارغ');
    }
    $currentPhone = !empty($log['sub_phone']) ? (string) $log['sub_phone'] : '';
    $phone = '';
    if ($currentPhone !== '' && (!function_exists('phone_is_placeholder') || !phone_is_placeholder($currentPhone))) {
        $phone = function_exists('normalize_phone') ? normalize_phone($currentPhone) : $currentPhone;
        if ($phone === '') {
            $phone = $currentPhone;
        }
    }
    if ($phone === '' && function_exists('subscriber_whatsapp_phone')) {
        $phone = subscriber_whatsapp_phone($pdo, (int) $log['sid'], '');
    }
    if ($phone === '') {
        $phone = !empty($log['phone']) ? (string) $log['phone'] : '';
    }
    $type = (string) $log['message_type'];
    if ($type === '') {
        $type = 'text';
    }
    if (substr($type, -6) !== '_retry') {
        $type .= '_retry';
    }
    if (function_exists('whatsapp_may_message_subscriber') && !whatsapp_may_message_subscriber($pdo, (int) $log['sid'])) {
        $sessNow = function_exists('whatsapp_session_id') ? whatsapp_session_id() : '';
        if ($sessNow !== '') {
            return array(false, 'هذا المشترك مو تابع لواتساب الحساب المفتوح');
        }
    }
    $retrySession = function_exists('whatsapp_session_id') ? whatsapp_session_id() : '';
    if ($retrySession === '') {
        $retrySession = wa_session_for_subscriber($pdo, (int) $log['sid']);
    }
    $result = whatsapp_send($config, $phone, $body, $type, $retrySession);
    $result['type'] = preg_replace('/_retry$/', '', (string) $log['message_type']);
    if ($result['type'] === '') {
        $result['type'] = 'text';
    }
    $prevTry = isset($log['attempt']) ? (int) $log['attempt'] : 1;
    if ($prevTry < 1) {
        $prevTry = 1;
    }
    $attempt = $prevTry + 1;
    if ($attempt > 3) {
        $attempt = 3;
    }
    if (empty($result['from_phone']) && !empty($log['from_phone'])) {
        $result['from_phone'] = (string) $log['from_phone'];
    }
    message_log_apply_result($pdo, (int) $log['id'], $result, $attempt);
    if (!empty($result['success'])) {
        return array(true, 'تمت إعادة الإرسال بنجاح');
    }
    return array(false, whatsapp_fail_user_message($result, 'فشلت إعادة الإرسال — تأكد أن واتساب متصل'));
}

/**
 * إعادة رسائل فاشلة مستحقة: رسالتين كحد أقصى، بعد 5 دقائق، لحد 3 محاولات.
 */
function wa_retry_due_batch($pdo, $config, $limit = 2)
{
    if (!$pdo) {
        return 0;
    }
    $lock = __DIR__ . '/../config/wa_retry.lock';
    $now = time();
    if (is_file($lock)) {
        $prev = (int) trim((string) @file_get_contents($lock));
        if ($prev > 0 && ($now - $prev) < 50) {
            return 0;
        }
    }
    @file_put_contents($lock, (string) $now);
    ensure_message_log_extra($pdo);
    $limit = (int) $limit;
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 2) {
        $limit = 2;
    }
    $tid = 0;
    if (!empty($_SESSION['admin_logged_in']) && function_exists('current_tenant_id')) {
        $tid = (int) current_tenant_id();
    }
    $sql = 'SELECT m.id FROM message_logs m';
    if ($tid > 0) {
        $sql .= ' INNER JOIN subscribers s ON s.id = m.subscriber_id AND s.tenant_id = ' . $tid;
    }
    $sql .= ' WHERE m.success = 0 AND m.subscriber_id IS NOT NULL
              AND m.next_retry_at IS NOT NULL AND m.next_retry_at <= NOW() AND m.attempt < 3
              ORDER BY m.next_retry_at ASC
              LIMIT ' . $limit;
    try {
        $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return 0;
    }
    if (!is_array($ids) || !$ids) {
        return 0;
    }
    $claim = $pdo->prepare(
        'UPDATE message_logs SET next_retry_at = NULL
         WHERE id = :id AND success = 0 AND next_retry_at IS NOT NULL AND next_retry_at <= NOW()'
    );
    $done = 0;
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        try {
            $claim->execute(array(':id' => $id));
            if ($claim->rowCount() < 1) {
                continue;
            }
        } catch (Exception $e) {
            continue;
        }
        try {
            retry_failed_message($pdo, $config, $id, 0);
            $done++;
        } catch (Exception $e) {
        }
    }
    return $done;
}

function wa_template_choices($lang = 'ar', $cfg = null)
{
    if ($cfg === null) {
        global $config;
        $cfg = (isset($config) && is_array($config)) ? $config : null;
    }
    if (is_array($cfg) && !empty($cfg['template_labels']) && is_array($cfg['template_labels'])) {
        return $cfg['template_labels'];
    }
    if (is_array($cfg) && !empty($cfg['wa_templates']) && is_array($cfg['wa_templates'])) {
        $out = array();
        foreach ($cfg['wa_templates'] as $k => $row) {
            $out[$k] = is_array($row) && isset($row['label']) && $row['label'] !== ''
                ? (string) $row['label']
                : $k;
        }
        if ($out) {
            return $out;
        }
    }
    return function_exists('wa_default_template_labels')
        ? wa_default_template_labels($lang)
        : array();
}

function wa_case_labels($lang = 'ar')
{
    $en = ($lang === 'en');
    return array(
        'activation_cash' => $en ? '1) Cash activation' : '1) تفعيل نقدي',
        'activation_credit' => $en ? '2) Credit activation' : '2) تفعيل آجل',
        'activation_credit_debts' => $en ? '3) Credit + old debts' : '3) تفعيل آجل + ديون قديمة',
        'activation_debts' => $en ? '4) Old-debts appendix' : '4) ملحق ديون قديمة',
        'debt_created' => $en ? 'Debt added' : 'إضافة دين',
        'payment_ok' => $en ? 'Payment received' : 'استلام التسديد',
        'debt_remind' => $en ? 'Debt reminder (manual / bulk)' : 'تذكير دين (يدوي / جماعي)',
        'reminder_auto' => $en ? 'Debt reminder (automatic)' : 'تذكير دين (تلقائي)',
        'days_left' => $en ? 'Days left (manual send)' : 'أيام متبقية (إرسال يدوي)',
        'unpaid_overdue' => $en ? 'Late payers warning' : 'تحذير المتأخرين بالدفع',
        'expiry_soon' => $en ? 'Expiry soon (auto cron)' : 'قرب الانتهاء (تلقائي)',
        'schedule_cut' => $en ? 'Auto-cut after grace' : 'القطع التلقائي بعد السماح',
    );
}

/**
 * Fixed system events that must be mapped to a template.
 */
function wa_system_cases($lang = 'ar')
{
    $en = ($lang === 'en');
    $labels = wa_case_labels($lang);
    return array(
        array(
            'group' => $en ? 'Activation (pick a template for each case)' : 'التفعيل (خصّص قالباً لكل حالة)',
            'cases' => array(
                array(
                    'key' => 'activation_cash',
                    'label' => $labels['activation_cash'],
                    'vars' => '{name} {package} {from} {to} {amount}',
                    'hint' => $en ? 'When activating with cash payment.' : 'عند التفعيل نقداً.',
                    'required' => true,
                ),
                array(
                    'key' => 'activation_credit',
                    'label' => $labels['activation_credit'],
                    'vars' => '{name} {package} {from} {to} {amount}',
                    'hint' => $en
                        ? 'When activating on credit without attaching old debts.'
                        : 'عند التفعيل بالآجل بدون إرفاق ديون قديمة.',
                    'required' => true,
                ),
                array(
                    'key' => 'activation_credit_debts',
                    'label' => $labels['activation_credit_debts'],
                    'vars' => '{name} {package} {from} {to} {amount} {debt} {month} {notes}',
                    'hint' => $en
                        ? 'When activating on credit and attaching old debts — one message.'
                        : 'عند التفعيل بالآجل مع إرفاق ديون قديمة — رسالة واحدة.',
                    'required' => true,
                ),
                array(
                    'key' => 'activation_debts',
                    'label' => $labels['activation_debts'],
                    'vars' => '{name} {debt} {amount} {month} {notes}',
                    'hint' => $en
                        ? 'Short appendix with cash activation when old debts are attached.'
                        : 'ملحق يُضاف مع التفعيل النقدي عند إرفاق ديون قديمة.',
                    'required' => true,
                ),
            ),
        ),
        array(
            'group' => $en ? 'Debts & payments' : 'الديون والتسديد',
            'cases' => array(
                array(
                    'key' => 'debt_created',
                    'label' => $labels['debt_created'],
                    'vars' => '{name} {amount} {month} {notes}',
                    'hint' => $en ? 'When staff add a debt.' : 'عند إضافة دين من النظام.',
                    'required' => true,
                ),
                array(
                    'key' => 'payment_ok',
                    'label' => $labels['payment_ok'],
                    'vars' => '{name} {amount} {month} {remaining}',
                    'hint' => $en ? 'After a successful payment.' : 'بعد تسجيل تسديد ناجح.',
                    'required' => true,
                ),
                array(
                    'key' => 'debt_remind',
                    'label' => $labels['debt_remind'],
                    'vars' => '{name} {debt} {amount} {month}',
                    'hint' => $en ? 'Manual remind and bulk “has debt”.' : 'تذكير يدوي والإرسال الجماعي لمن عليهم دين.',
                    'required' => true,
                ),
                array(
                    'key' => 'reminder_auto',
                    'label' => $labels['reminder_auto'],
                    'vars' => '{name} {debt} {amount} {month}',
                    'hint' => $en ? 'Automatic cron debt reminders.' : 'تذكير الديون التلقائي بالكرون.',
                    'required' => true,
                ),
            ),
        ),
        array(
            'group' => $en ? 'Reminders & cuts' : 'التذكيرات والقطع',
            'cases' => array(
                array(
                    'key' => 'days_left',
                    'label' => $labels['days_left'],
                    'vars' => '{name} {days} {package} {from} {to} {debt}',
                    'hint' => $en ? 'Manual bulk from Messages → Expiring.' : 'الإرسال اليدوي من تبويب قرب الانتهاء.',
                    'required' => true,
                ),
                array(
                    'key' => 'expiry_soon',
                    'label' => $labels['expiry_soon'],
                    'vars' => '{name} {days} {package} {to}',
                    'hint' => $en ? 'Automatic expiry reminder (Schedule settings).' : 'تذكير قرب الانتهاء التلقائي (إعدادات الجدول الدوري).',
                    'required' => true,
                ),
                array(
                    'key' => 'unpaid_overdue',
                    'label' => $labels['unpaid_overdue'],
                    'vars' => '{name} {days_passed} {debt} {package}',
                    'hint' => $en
                        ? 'Warning N days after activation (Schedule settings / Messages bulk).'
                        : 'تنبيه بعد أيام من التفعيل (إعدادات الجدول / إرسال جماعي).',
                    'required' => true,
                ),
                array(
                    'key' => 'schedule_cut',
                    'label' => $labels['schedule_cut'],
                    'vars' => '{name} {debt} {grace} {package}',
                    'hint' => $en ? 'When auto-cut disconnects unpaid lines.' : 'عند القطع التلقائي بسبب الدين.',
                    'required' => true,
                ),
            ),
        ),
    );
}

function wa_case_issue_message($issue, $caseLabel, $lang = 'ar')
{
    $en = ($lang === 'en');
    if ($issue === 'unassigned') {
        return $en
            ? ('Choose a template for: ' . $caseLabel)
            : ('لازم تختار قالب لـ: ' . $caseLabel);
    }
    if ($issue === 'missing_template') {
        return $en
            ? ('Template missing for: ' . $caseLabel . ' — pick another.')
            : ('القالب المربوط غير موجود لـ: ' . $caseLabel . ' — اختر قالباً آخر.');
    }
    if ($issue === 'empty_body') {
        return $en
            ? ('Template text is empty for: ' . $caseLabel)
            : ('نص القالب فارغ لـ: ' . $caseLabel . ' — اكتب النص أو غيّر القالب.');
    }
    return $en ? ('Check template for: ' . $caseLabel) : ('راجع القالب لـ: ' . $caseLabel);
}

/** @deprecated kept for compatibility */
function wa_template_editor_groups($lang = 'ar')
{
    return array();
}

/** @deprecated kept for compatibility */
function wa_case_editor_groups($lang = 'ar')
{
    $groups = wa_system_cases($lang);
    $out = array();
    foreach ($groups as $g) {
        $keys = array();
        foreach ($g['cases'] as $c) {
            $keys[] = $c['key'];
        }
        $out[] = array('title' => $g['group'], 'keys' => $keys);
    }
    return $out;
}

function wa_case_template_key($config, $case)
{
    $case = trim((string) $case);
    if ($case === '' || $case === 'activation') {
        $case = 'activation_cash';
    }
    if (isset($config['wa_cases'][$case]) && trim((string) $config['wa_cases'][$case]) !== '') {
        return (string) $config['wa_cases'][$case];
    }
    $fallback = array(
        'activation_cash' => 'activation',
        'activation_credit' => 'activation_credit',
        'activation_debts' => 'activation_debts',
        'activation_credit_debts' => 'activation_credit_debts',
        'activation' => 'activation',
        'debt_created' => 'debt_created',
        'payment_ok' => 'payment_ok',
        'debt_remind' => 'debt_remind',
        'reminder_auto' => 'debt_remind',
        'days_left' => 'days_left',
        'unpaid_overdue' => 'unpaid_overdue',
        'expiry_soon' => 'expiry_soon',
        'schedule_cut' => 'schedule_cut',
    );
    return isset($fallback[$case]) ? $fallback[$case] : $case;
}

function wa_render_named_template($key, $sub, $config, $extra = array())
{
    $key = trim((string) $key);
    if ($key === '') {
        $key = 'activation';
    }
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $amountVal = isset($extra['amount']) ? $extra['amount'] : (isset($sub['monthly_price']) ? $sub['monthly_price'] : 0);
    $debtVal = isset($extra['debt']) ? $extra['debt'] : (isset($sub['debt_total']) ? $sub['debt_total'] : $amountVal);
    $vars = array(
        'name' => isset($sub['name']) ? $sub['name'] : '',
        'package' => isset($extra['package']) ? $extra['package'] : (isset($sub['service_name']) ? $sub['service_name'] : ''),
        'from' => isset($sub['start_date']) ? $sub['start_date'] : '',
        'to' => isset($sub['end_date']) ? $sub['end_date'] : '',
        'amount' => money_format_iqd($amountVal, $currency),
        'debt' => money_format_iqd($debtVal, $currency),
        'month' => function_exists('month_short_label')
            ? month_short_label(isset($sub['month_label']) ? $sub['month_label'] : date('Y-m'))
            : date('Y-m'),
        'notes' => isset($extra['notes']) ? $extra['notes'] : (isset($sub['notes']) ? $sub['notes'] : ''),
        'days' => isset($extra['days']) ? $extra['days'] : (isset($sub['days']) ? $sub['days'] : ''),
        'days_passed' => isset($extra['days_passed']) ? $extra['days_passed'] : '',
        'remaining' => isset($extra['remaining'])
            ? money_format_iqd($extra['remaining'], $currency)
            : '',
    );
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    }
    if ($tpl === '' && $key === 'activation' && isset($config['templates']['activation'])) {
        $tpl = $config['templates']['activation'];
    }
    if ($tpl === '') {
        return '';
    }
    return function_exists('tpl_fill') ? tpl_fill($tpl, $vars) : $tpl;
}

function activation_message($sub, $config, $extraNote = '')
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $amount = money_format_iqd($sub['monthly_price'], $currency);
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'activation') : 'activation';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['activation']) && trim((string) $config['templates']['activation']) !== '') {
        $tpl = $config['templates']['activation'];
    }
    if ($tpl !== '') {
        $msg = tpl_fill($tpl, array(
            'name' => $sub['name'],
            'package' => $sub['service_name'],
            'from' => $sub['start_date'],
            'to' => $sub['end_date'],
            'amount' => $amount,
        ));
    } else {
        $msg = "مرحباً {$sub['name']}\n"
            . "تم تفعيل خدمة الإنترنت ({$sub['service_name']})\n"
            . 'من تاريخ ' . $sub['start_date'] . ' إلى تاريخ ' . $sub['end_date'] . "\n"
            . 'المبلغ: ' . $amount;
    }
    if ($extraNote !== '') {
        $msg .= "\n" . $extraNote;
    }
    $senderNote = '';
    if (isset($config['whatsapp']['sender_note'])) {
        $senderNote = trim((string) $config['whatsapp']['sender_note']);
    }
    if ($senderNote !== '') {
        $msg .= "\n" . $senderNote;
    }
    return $msg;
}

function reminder_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $debt = money_format_iqd(isset($row['debt_total']) ? $row['debt_total'] : $row['amount'], $currency);
    $amount = money_format_iqd($row['amount'], $currency);
    $month = month_short_label($row['month_label']);
    $notes = isset($row['notes']) ? trim((string) $row['notes']) : '';
    $case = !empty($row['_wa_case']) ? (string) $row['_wa_case'] : 'debt_remind';
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, $case) : 'debt_remind';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['debt_remind']) && trim((string) $config['templates']['debt_remind']) !== '') {
        $tpl = $config['templates']['debt_remind'];
    }
    if ($tpl !== '') {
        return tpl_fill($tpl, array(
            'name' => $row['name'],
            'debt' => $debt,
            'amount' => $amount,
            'month' => $month,
            'notes' => $notes,
        ));
    }
    return 'السلام عليكم ' . $row['name'] . ' يرجى تسديد الديون البالغة ' . $debt . ' لتجنب قطع الخدمة';
}

function debt_created_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $amount = money_format_iqd($row['amount'], $currency);
    $month = month_short_label($row['month_label']);
    $notes = isset($row['notes']) ? trim((string) $row['notes']) : '';
    $debt = money_format_iqd(isset($row['debt_total']) ? $row['debt_total'] : $row['amount'], $currency);
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'debt_created') : 'debt_created';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['debt_created']) && trim((string) $config['templates']['debt_created']) !== '') {
        $tpl = $config['templates']['debt_created'];
    }
    if ($tpl !== '') {
        return tpl_fill($tpl, array(
            'name' => $row['name'],
            'amount' => $amount,
            'month' => $month,
            'notes' => $notes,
            'debt' => $debt,
        ));
    }
    $msg = "مرحباً {$row['name']}\nتم تسجيل دين بمبلغ {$amount}";
    if ($month !== '') {
        $msg .= "\nعن: {$month}";
    }
    if ($notes !== '') {
        $msg .= "\n{$notes}";
    }
    return $msg;
}

/**
 * رسالة الأيام المتبقية (+ سطر الدين إن وُجد)
 * $row: name, days, package?, debt_total?
 */
function days_left_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $days = isset($row['days']) ? (int) $row['days'] : 0;
    $debtTotal = isset($row['debt_total']) ? (float) $row['debt_total'] : 0;
    $debtFmt = $debtTotal > 0 ? money_format_iqd($debtTotal, $currency) : '';
    $package = isset($row['package']) ? (string) $row['package'] : '';
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'days_left') : 'days_left';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['days_left']) && trim((string) $config['templates']['days_left']) !== '') {
        $tpl = $config['templates']['days_left'];
    }
    if ($tpl !== '') {
        $msg = tpl_fill($tpl, array(
            'name' => $row['name'],
            'days' => (string) $days,
            'package' => $package,
            'debt' => $debtFmt,
        ));
    } else {
        $msg = 'السلام عليكم ' . $row['name'] . "\nتبقى لديك " . $days . ' يوم على الاشتراك';
        if ($package !== '') {
            $msg .= ' (' . $package . ')';
        }
    }
    // إذا القالب ما فيه {debt} والدين موجود — نضيف سطر الدين
    if ($debtTotal > 0 && strpos($msg, $debtFmt) === false) {
        $msg .= "\nعليك دين بمبلغ " . $debtFmt;
    }
    return $msg;
}

/**
 * رسالة تأخر التسديد بعد أيام من التفعيل
 * $row: name, days_passed, debt_total, package?
 */
function unpaid_overdue_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $daysPassed = isset($row['days_passed']) ? (int) $row['days_passed'] : 0;
    $debtTotal = isset($row['debt_total']) ? (float) $row['debt_total'] : 0;
    $debtFmt = money_format_iqd($debtTotal, $currency);
    $package = isset($row['package']) ? (string) $row['package'] : '';
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'unpaid_overdue') : 'unpaid_overdue';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['unpaid_overdue']) && trim((string) $config['templates']['unpaid_overdue']) !== '') {
        $tpl = $config['templates']['unpaid_overdue'];
    }
    if ($tpl !== '') {
        return tpl_fill($tpl, array(
            'name' => $row['name'],
            'days_passed' => (string) $daysPassed,
            'debt' => $debtFmt,
            'amount' => $debtFmt,
            'package' => $package,
        ));
    }
    return 'السلام عليكم ' . $row['name'] . "\n"
        . 'مضى على تفعيل خطك ' . $daysPassed . " أيام\n"
        . 'يرجى تسديد الديون البالغة ' . $debtFmt . "\n"
        . 'وبعكسه سيتم إيقاف الخدمة';
}

function unpaid_remind_after_days($config)
{
    if (isset($config['unpaid_remind_after_days'])) {
        return max(1, (int) $config['unpaid_remind_after_days']);
    }
    return 7;
}

/** أيام مضت منذ تاريخ التفعيل (من يوم التفعيل) */
function days_since_date($dateYmd)
{
    $start = strtotime(date('Y-m-d', strtotime($dateYmd)));
    $today = strtotime(date('Y-m-d'));
    if ($start === false || $today === false) {
        return 0;
    }
    return (int) floor(($today - $start) / 86400);
}

function payment_message($row, $config)
{
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $amount = money_format_iqd($row['amount'], $currency);
    if (!empty($row['about'])) {
        $month = trim((string) $row['about']);
    } elseif (function_exists('invoice_debt_label')) {
        $month = invoice_debt_label($row);
    } else {
        $month = month_short_label(isset($row['month_label']) ? $row['month_label'] : '');
        $notes = isset($row['notes']) ? trim((string) $row['notes']) : '';
        if ($notes !== '' && (empty($row['month_label']) || !preg_match('/^\d{4}-\d{1,2}$/', (string) $row['month_label']))) {
            $month .= ' — ' . $notes;
        }
    }
    $remaining = isset($row['remaining']) ? money_format_iqd($row['remaining'], $currency) : '';
    $hasRemaining = array_key_exists('remaining', $row);
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'payment_ok') : 'payment_ok';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['payment_ok']) && trim((string) $config['templates']['payment_ok']) !== '') {
        $tpl = $config['templates']['payment_ok'];
    }
    if ($tpl !== '') {
        $msg = tpl_fill($tpl, array(
            'name' => $row['name'],
            'amount' => $amount,
            'month' => $month,
            'debt' => $amount,
            'remaining' => $remaining,
        ));
        if (strpos($tpl, '{amount}') === false && strpos($tpl, '{debt}') === false) {
            $msg .= "\nتم استلام مبلغ {$amount}";
        }
        if ($hasRemaining && strpos($tpl, '{remaining}') === false) {
            $msg .= "\nالمتبقي عليك: {$remaining}";
        }
        return $msg;
    }
    $msg = "مرحباً {$row['name']}\nتم استلام مبلغ {$amount}\nعن: {$month}";
    if ($hasRemaining) {
        $msg .= "\nالمتبقي عليك: {$remaining}";
    } else {
        $msg .= "\nشكراً لتسديدك.";
    }
    return $msg;
}

/**
 * رسالة قرب انتهاء الاشتراك (تلقائي)
 * $row: name, days, package, from?, to?
 */
function expiry_soon_message($row, $config)
{
    $package = isset($row['package']) ? (string) $row['package'] : '';
    $days = isset($row['days']) ? (int) $row['days'] : 0;
    $from = isset($row['from']) ? (string) $row['from'] : '';
    $to = isset($row['to']) ? (string) $row['to'] : '';
    $key = function_exists('wa_case_template_key') ? wa_case_template_key($config, 'expiry_soon') : 'expiry_soon';
    $tpl = '';
    if (isset($config['templates'][$key]) && trim((string) $config['templates'][$key]) !== '') {
        $tpl = $config['templates'][$key];
    } elseif (isset($config['templates']['expiry_soon']) && trim((string) $config['templates']['expiry_soon']) !== '') {
        $tpl = $config['templates']['expiry_soon'];
    }
    if ($tpl !== '') {
        return tpl_fill($tpl, array(
            'name' => $row['name'],
            'days' => (string) $days,
            'package' => $package,
            'from' => $from,
            'to' => $to,
        ));
    }
    $msg = 'السلام عليكم ' . $row['name'] . "\nتبقى لديك " . $days . ' يوم على الاشتراك';
    if ($package !== '') {
        $msg .= ' (' . $package . ')';
    }
    if ($to !== '') {
        $msg .= "\nينتهي بتاريخ " . $to;
    }
    $msg .= "\nيرجى التجديد لتجنب انقطاع الخدمة";
    return $msg;
}

function ensure_subscription_expiry_remind_column($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM subscriptions LIKE 'expiry_remind_for_end'");
        if ($chk && $chk->fetch()) {
            return;
        }
        $pdo->exec('ALTER TABLE subscriptions ADD COLUMN expiry_remind_for_end DATE NULL DEFAULT NULL');
    } catch (Exception $e) {
        // ignore
    }
}

function ensure_sas_expiry_remind_column($pdo)
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (function_exists('ensure_sas_users_cache_table')) {
        try {
            ensure_sas_users_cache_table($pdo);
        } catch (Exception $e) {
            // ignore
        }
    }
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM sas_users_cache LIKE 'expiry_remind_for_expire'");
        if ($chk && $chk->fetch()) {
            return;
        }
        $pdo->exec('ALTER TABLE sas_users_cache ADD COLUMN expiry_remind_for_expire DATE NULL DEFAULT NULL');
    } catch (Exception $e) {
        // ignore
    }
}

function expiry_remind_lock_name($key)
{
    return 'exr' . substr(sha1((string) $key), 0, 20);
}

function expiry_remind_lock($pdo, $key)
{
    if (!$pdo || $key === '') {
        return true;
    }
    try {
        $st = $pdo->query('SELECT GET_LOCK(' . $pdo->quote(expiry_remind_lock_name($key)) . ', 4) AS lk');
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        return $row && isset($row['lk']) && (string) $row['lk'] === '1';
    } catch (Exception $e) {
        return true;
    }
}

function expiry_remind_unlock($pdo, $key)
{
    if (!$pdo || $key === '') {
        return;
    }
    try {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote(expiry_remind_lock_name($key)) . ')');
    } catch (Exception $e) {
    }
}

function expiry_cache_has_mark($pdo, $username, $tenantId, $endDate)
{
    $username = trim((string) $username);
    $endDate = trim((string) $endDate);
    if ($username === '' || $endDate === '' || !$pdo) {
        return false;
    }
    $sql = 'SELECT expiry_remind_for_expire FROM sas_users_cache WHERE username = :u';
    $params = array(':u' => $username);
    if ((int) $tenantId > 0) {
        $sql .= ' AND tenant_id = :t';
        $params[':t'] = (int) $tenantId;
    }
    $sql .= ' LIMIT 1';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $prev = $st->fetchColumn();
        return $prev !== false && $prev !== null && (string) $prev === $endDate;
    } catch (Exception $e) {
        return false;
    }
}

function expiry_remind_already($pdo, $phone, $subscriberId)
{
    $phone = trim((string) $phone);
    if ($phone !== '' && function_exists('normalize_phone')) {
        $norm = normalize_phone($phone);
        if ($norm !== '') {
            $phone = $norm;
        }
    }
    $subscriberId = (int) $subscriberId;
    $parts = array();
    $params = array();
    if ($subscriberId > 0) {
        $parts[] = 'subscriber_id = :sid';
        $params[':sid'] = $subscriberId;
    }
    if ($phone !== '') {
        $parts[] = 'phone = :ph';
        $params[':ph'] = $phone;
    }
    if (!$parts) {
        return false;
    }
    $sql = 'SELECT id FROM message_logs
            WHERE success = 1
              AND message_type = \'expiry_auto\'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
              AND (' . implode(' OR ', $parts) . ')
            LIMIT 1';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function expiry_remind_mark($pdo, $username, $tenantId, $endDate, $subRowId, $subscriberId)
{
    $endDate = trim((string) $endDate);
    if ($endDate === '' || !$pdo) {
        return;
    }
    $username = trim((string) $username);
    $tenantId = (int) $tenantId;
    if ($username !== '') {
        try {
            $sql = 'UPDATE sas_users_cache SET expiry_remind_for_expire = :e WHERE username = :u';
            $params = array(':e' => $endDate, ':u' => $username);
            if ($tenantId > 0) {
                $sql .= ' AND tenant_id = :t';
                $params[':t'] = $tenantId;
            }
            $pdo->prepare($sql)->execute($params);
        } catch (Exception $e) {
        }
    }
    $subRowId = (int) $subRowId;
    if ($subRowId > 0) {
        try {
            $pdo->prepare('UPDATE subscriptions SET expiry_remind_for_end = :e WHERE id = :id')
                ->execute(array(':e' => $endDate, ':id' => $subRowId));
        } catch (Exception $e) {
        }
    }
    $subscriberId = (int) $subscriberId;
    if ($subscriberId > 0) {
        try {
            $pdo->prepare(
                'UPDATE subscriptions SET expiry_remind_for_end = end_date
                 WHERE subscriber_id = :sid AND status = \'active\'
                   AND end_date >= CURDATE()
                   AND (expiry_remind_for_end IS NULL OR expiry_remind_for_end <> end_date)'
            )->execute(array(':sid' => $subscriberId));
        } catch (Exception $e) {
        }
    }
}

function expiry_remind_claim($pdo, $username, $tenantId, $endDate, $subRowId)
{
    $claimed = false;
    $endDate = trim((string) $endDate);
    $username = trim((string) $username);
    $tenantId = (int) $tenantId;
    $subRowId = (int) $subRowId;
    if ($username !== '') {
        try {
            $sql = 'UPDATE sas_users_cache SET expiry_remind_for_expire = :e
                    WHERE username = :u
                      AND (expiry_remind_for_expire IS NULL OR expiry_remind_for_expire <> :e2)';
            $params = array(':e' => $endDate, ':e2' => $endDate, ':u' => $username);
            if ($tenantId > 0) {
                $sql .= ' AND tenant_id = :t';
                $params[':t'] = $tenantId;
            }
            $st = $pdo->prepare($sql);
            $st->execute($params);
            if ($st->rowCount() > 0) {
                $claimed = true;
            }
        } catch (Exception $e) {
        }
    }
    if ($subRowId > 0) {
        try {
            $st = $pdo->prepare(
                'UPDATE subscriptions SET expiry_remind_for_end = :e
                 WHERE id = :id AND (expiry_remind_for_end IS NULL OR expiry_remind_for_end <> :e2)'
            );
            $st->execute(array(':e' => $endDate, ':e2' => $endDate, ':id' => $subRowId));
            if ($st->rowCount() > 0) {
                $claimed = true;
            }
        } catch (Exception $e) {
        }
    }
    return $claimed;
}

/**
 * إرسال تذكير لمن ينتهي اشتراكهم خلال N أيام
 * (اشتراكات محلية + تواريخ انتهاء كاش الساس)
 */
function run_expiry_soon_reminders($pdo, $config, $limit = 40)
{
    $out = array('sent' => 0, 'failed' => 0, 'skipped' => 0, 'checked' => 0);
    if (empty($config['expiry_auto_remind_enabled'])) {
        return $out;
    }
    if (empty($config['whatsapp']['enabled'])) {
        return $out;
    }
    $daysN = isset($config['expiry_auto_remind_days']) ? (int) $config['expiry_auto_remind_days'] : 1;
    if ($daysN < 0) {
        $daysN = 0;
    }
    if ($daysN > 60) {
        $daysN = 60;
    }
    ensure_subscription_expiry_remind_column($pdo);
    ensure_sas_expiry_remind_column($pdo);
    try {
        $pdo->exec(
            "UPDATE subscriptions SET status = 'expired'
             WHERE status = 'active' AND end_date < CURDATE()"
        );
    } catch (Exception $e) {
        // ignore
    }

    $limit = max(1, (int) $limit);
    $doneUsers = array();
    $donePhones = array();
    $remindTid = 0;
    if (!empty($GLOBALS['schedule_tenant_id'])) {
        $remindTid = (int) $GLOBALS['schedule_tenant_id'];
    } elseif (function_exists('current_tenant_id')) {
        $remindTid = (int) current_tenant_id();
    }

    // 1) تواريخ الانتهاء من كاش المشتركين
    $sasRows = array();
    if (function_exists('sas_list_expiring_rows')) {
        $sasRows = sas_list_expiring_rows($pdo, $daysN, $limit * 3);
    }
    // صفّي من تذكّروا مسبقاً لنفس تاريخ الانتهاء
    $sasFiltered = array();
    foreach ($sasRows as $crow) {
        $u = isset($crow['username']) ? trim((string) $crow['username']) : '';
        if ($u === '') {
            continue;
        }
        try {
            $prevSql = 'SELECT expiry_remind_for_expire FROM sas_users_cache WHERE username = :u';
            $prevParams = array(':u' => $u);
            if ($remindTid > 0) {
                $prevSql .= ' AND tenant_id = :t';
                $prevParams[':t'] = $remindTid;
            }
            $prevSql .= ' LIMIT 1';
            $stR = $pdo->prepare($prevSql);
            $stR->execute($prevParams);
            $prev = $stR->fetchColumn();
            $endDate = date('Y-m-d', strtotime((string) $crow['expire_at']));
            if ($prev !== false && $prev !== null && (string) $prev === $endDate) {
                continue;
            }
        } catch (Exception $e) {
            // ignore
        }
        $crow['subscriber_id'] = isset($crow['sub_id']) ? (int) $crow['sub_id'] : 0;
        $sasFiltered[] = $crow;
        if (count($sasFiltered) >= $limit) {
            break;
        }
    }
    $sasRows = $sasFiltered;

    foreach ($sasRows as $crow) {
        if ($out['sent'] + $out['failed'] + $out['skipped'] >= $limit) {
            break;
        }
        $username = isset($crow['username']) ? trim((string) $crow['username']) : '';
        if ($username === '' || isset($doneUsers[$username])) {
            continue;
        }
        $out['checked']++;
        $sid = isset($crow['subscriber_id']) ? (int) $crow['subscriber_id'] : 0;
        if ($sid <= 0 && !empty($crow['local_subscriber_id'])) {
            $sid = (int) $crow['local_subscriber_id'];
        }
        if ($sid <= 0 && function_exists('sas_cache_ensure_local')) {
            list($sid, $errLink) = sas_cache_ensure_local($pdo, $config, $crow);
            $sid = (int) $sid;
        }
        if ($sid <= 0) {
            $out['skipped']++;
            continue;
        }
        $name = '';
        if (!empty($crow['sub_name'])) {
            $name = (string) $crow['sub_name'];
        } elseif (!empty($crow['display_name'])) {
            $name = (string) $crow['display_name'];
        } else {
            $name = $username;
        }
        $phone = '';
        if (function_exists('phone_first_valid')) {
            $phone = phone_first_valid(array(
                isset($crow['sas_phone']) ? $crow['sas_phone'] : '',
                isset($crow['sub_phone']) ? $crow['sub_phone'] : '',
            ));
        }
        if ($phone === '' && function_exists('subscriber_whatsapp_phone')) {
            $phone = subscriber_whatsapp_phone($pdo, $sid, '');
        }
        if ($phone === '') {
            try {
                $stPh = $pdo->prepare('SELECT phone FROM subscribers WHERE id = :id LIMIT 1');
                $stPh->execute(array(':id' => $sid));
                $phone = (string) $stPh->fetchColumn();
            } catch (Exception $e) {
                $phone = '';
            }
        }
        if ($phone !== '' && function_exists('normalize_phone')) {
            $normPhone = normalize_phone($phone);
            if ($normPhone !== '') {
                $phone = $normPhone;
            }
        }
        if ($phone === '' || (function_exists('phone_is_placeholder') && phone_is_placeholder($phone))) {
            $out['skipped']++;
            continue;
        }
        $endDate = date('Y-m-d', strtotime((string) $crow['expire_at']));
        $waSession = whatsapp_session_for_subscriber($pdo, $sid, isset($crow['parent_id']) ? (int) $crow['parent_id'] : 0);
        if ($waSession === '') {
            $out['skipped']++;
            continue;
        }
        if (isset($donePhones[$phone])) {
            expiry_remind_mark($pdo, $username, $remindTid, $endDate, 0, $sid);
            $doneUsers[$username] = true;
            $out['skipped']++;
            continue;
        }
        if (!expiry_remind_lock($pdo, $phone)) {
            $out['skipped']++;
            continue;
        }
        if (expiry_remind_already($pdo, $phone, $sid) || !expiry_remind_claim($pdo, $username, $remindTid, $endDate, 0)) {
            expiry_remind_mark($pdo, $username, $remindTid, $endDate, 0, $sid);
            expiry_remind_unlock($pdo, $phone);
            $doneUsers[$username] = true;
            $donePhones[$phone] = true;
            $out['skipped']++;
            continue;
        }
        $startDate = date('Y-m-d', strtotime($endDate . ' -30 days'));
        $info = subscription_days_info($startDate, $endDate);
        $pkg = isset($crow['profile_name']) ? (string) $crow['profile_name'] : '';
        $body = expiry_soon_message(array(
            'name' => $name,
            'days' => isset($crow['_days']) ? (int) $crow['_days'] : (int) $info['left'],
            'package' => $pkg,
            'from' => $startDate,
            'to' => $endDate,
        ), $config);
        $result = whatsapp_send($config, $phone, $body, 'expiry_auto', $waSession);
        if (!is_array($result)) {
            $result = array('success' => false, 'skipped' => true);
        }
        $result['type'] = 'expiry_auto';
        log_message($pdo, $sid, $result);
        expiry_remind_unlock($pdo, $phone);
        $doneUsers[$username] = true;
        $donePhones[$phone] = true;
        if (!empty($result['success'])) {
            expiry_remind_mark($pdo, $username, $remindTid, $endDate, 0, $sid);
            $out['sent']++;
            usleep(250000);
        } elseif (!empty($result['skipped'])) {
            try {
                $rel = 'UPDATE sas_users_cache SET expiry_remind_for_expire = NULL
                        WHERE username = :u AND expiry_remind_for_expire = :e';
                $relParams = array(':u' => $username, ':e' => $endDate);
                if ($remindTid > 0) {
                    $rel .= ' AND tenant_id = :t';
                    $relParams[':t'] = $remindTid;
                }
                $pdo->prepare($rel)->execute($relParams);
            } catch (Exception $e) {
            }
            $out['skipped']++;
        } else {
            $out['failed']++;
            usleep(150000);
        }
    }

    // 2) اشتراكات محلية بدون ربط ساس (أو بدون صف كاش)
    $remain = $limit - (int) $out['sent'] - (int) $out['failed'] - (int) $out['skipped'];
    if ($remain < 1) {
        $remain = 0;
    }
    if ($remain > 0) {
        $tenantSql = ($remindTid > 0) ? (' AND s.tenant_id = ' . (int) $remindTid) : '';
        $sql = 'SELECT sub.id AS sub_id, sub.subscriber_id, sub.service_name, sub.start_date, sub.end_date,
                       s.name, s.phone, s.sas_username
                FROM subscriptions sub
                JOIN subscribers s ON s.id = sub.subscriber_id
                WHERE sub.status = \'active\'
                  AND sub.end_date >= CURDATE()
                  AND sub.end_date <= DATE_ADD(CURDATE(), INTERVAL ' . (int) $daysN . ' DAY)
                  AND (sub.expiry_remind_for_end IS NULL OR sub.expiry_remind_for_end <> sub.end_date)'
                  . $tenantSql . '
                ORDER BY sub.end_date ASC
                LIMIT ' . (int) $remain;
        try {
            $rows = $pdo->query($sql)->fetchAll();
        } catch (Exception $e) {
            $rows = array();
        }
        foreach ($rows as $row) {
            $u = isset($row['sas_username']) ? trim((string) $row['sas_username']) : '';
            if ($u !== '' && isset($doneUsers[$u])) {
                continue;
            }
            $out['checked']++;
            $info = subscription_days_info($row['start_date'], $row['end_date']);
            $body = expiry_soon_message(array(
                'name' => $row['name'],
                'days' => (int) $info['left'],
                'package' => $row['service_name'],
                'from' => $row['start_date'],
                'to' => $row['end_date'],
            ), $config);
            $waSession = whatsapp_session_for_subscriber($pdo, (int) $row['subscriber_id'], 0);
            if ($waSession === '') {
                $out['skipped']++;
                continue;
            }
            $localPhone = isset($row['phone']) ? trim((string) $row['phone']) : '';
            if ($localPhone !== '' && function_exists('normalize_phone')) {
                $normLocal = normalize_phone($localPhone);
                if ($normLocal !== '') {
                    $localPhone = $normLocal;
                }
            }
            if ($localPhone !== '' && isset($donePhones[$localPhone])) {
                expiry_remind_mark($pdo, $u, $remindTid, (string) $row['end_date'], (int) $row['sub_id'], (int) $row['subscriber_id']);
                $out['skipped']++;
                continue;
            }
            $lockKey = $localPhone !== '' ? $localPhone : ('s' . (int) $row['subscriber_id']);
            if (!expiry_remind_lock($pdo, $lockKey)) {
                $out['skipped']++;
                continue;
            }
            if (expiry_cache_has_mark($pdo, $u, $remindTid, (string) $row['end_date'])
                || expiry_remind_already($pdo, $localPhone, (int) $row['subscriber_id'])
                || !expiry_remind_claim($pdo, $u, $remindTid, (string) $row['end_date'], (int) $row['sub_id'])
            ) {
                expiry_remind_mark($pdo, $u, $remindTid, (string) $row['end_date'], (int) $row['sub_id'], (int) $row['subscriber_id']);
                expiry_remind_unlock($pdo, $lockKey);
                if ($localPhone !== '') {
                    $donePhones[$localPhone] = true;
                }
                $out['skipped']++;
                continue;
            }
            $result = whatsapp_send($config, $row['phone'], $body, 'expiry_auto', $waSession);
            if (!is_array($result)) {
                $result = array('success' => false, 'skipped' => true);
            }
            $result['type'] = 'expiry_auto';
            log_message($pdo, (int) $row['subscriber_id'], $result);
            expiry_remind_unlock($pdo, $lockKey);
            if ($u !== '') {
                $doneUsers[$u] = true;
            }
            if ($localPhone !== '') {
                $donePhones[$localPhone] = true;
            }
            if (!empty($result['success'])) {
                expiry_remind_mark($pdo, $u, $remindTid, (string) $row['end_date'], (int) $row['sub_id'], (int) $row['subscriber_id']);
                $out['sent']++;
                usleep(250000);
            } elseif (!empty($result['skipped'])) {
                try {
                    $pdo->prepare(
                        'UPDATE subscriptions SET expiry_remind_for_end = NULL
                         WHERE id = :id AND expiry_remind_for_end = :e'
                    )->execute(array(':id' => (int) $row['sub_id'], ':e' => $row['end_date']));
                } catch (Exception $e) {
                }
                $out['skipped']++;
            } else {
                $out['failed']++;
                usleep(150000);
            }
        }
    }
    return $out;
}

/** تشغيل خفيف من الواجهة (تذكير انتهاء + قطع) بدون كرون */
function maybe_run_expiry_auto_reminders($pdo, $config)
{
    if (function_exists('maybe_run_auto_schedule_jobs')) {
        maybe_run_auto_schedule_jobs($pdo, $config);
        return;
    }
    if (empty($config['expiry_auto_remind_enabled'])) {
        return;
    }
    $lock = __DIR__ . '/../config/expiry_auto_remind.lock';
    $now = time();
    if (is_file($lock)) {
        $prev = (int) trim((string) @file_get_contents($lock));
        if ($prev > 0 && ($now - $prev) < 180) {
            return;
        }
    }
    @file_put_contents($lock, (string) $now);
    @run_expiry_soon_reminders($pdo, $config, 25);
}

/**
 * تشغيل تلقائي للتذكير والقطع + تحديث بيانات الانتهاء أثناء استخدام اللوحة.
 */
function maybe_run_auto_schedule_jobs($pdo, $config)
{
    $lockTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($lockTid <= 0) {
        $lockTid = 1;
    }
    if (function_exists('schedule_settings_for_tenant') && function_exists('schedule_config_with_tenant')) {
        try {
            $schedNow = schedule_settings_for_tenant($pdo, $lockTid);
            $config = schedule_config_with_tenant($config, $schedNow);
        } catch (Exception $e) {
        }
    }
    $lock = __DIR__ . '/../config/auto_schedule_t' . $lockTid . '.lock';
    $now = time();
    if (is_file($lock)) {
        $prev = (int) trim((string) @file_get_contents($lock));
        if ($prev > 0 && ($now - $prev) < 120) {
            return;
        }
    }
    @file_put_contents($lock, (string) $now);

    if (!empty($config['expiry_auto_remind_enabled']) && function_exists('run_expiry_soon_reminders')) {
        try {
            @run_expiry_soon_reminders($pdo, $config, 25);
        } catch (Exception $e) {
            // ignore
        }
    }
    if (!empty($config['schedule_cut_enabled']) && function_exists('run_schedule_debt_cuts')) {
        try {
            @run_schedule_debt_cuts($pdo, $config, 40);
        } catch (Exception $e) {
            // ignore
        }
    }
}
