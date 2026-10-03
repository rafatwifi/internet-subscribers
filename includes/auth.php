<?php

/**
 * تشفير كلمة مرور متوافق حتى لو password_hash غير متاح
 */
function admin_password_hash($plain)
{
    $plain = (string) $plain;
    if (function_exists('password_hash')) {
        $hash = @password_hash($plain, PASSWORD_DEFAULT);
        if (is_string($hash) && $hash !== '') {
            return $hash;
        }
    }
    return 'md5:' . md5($plain);
}

function admin_password_verify($plain, $hash)
{
    $plain = (string) $plain;
    $hash = (string) $hash;
    if ($hash === '') {
        return false;
    }
    if (strpos($hash, 'md5:') === 0) {
        return hash_equals(substr($hash, 4), md5($plain));
    }
    if (strlen($hash) === 32 && ctype_xdigit($hash)) {
        return hash_equals($hash, md5($plain));
    }
    if (function_exists('password_verify')) {
        return @password_verify($plain, $hash);
    }
    return false;
}

/** الأدوار المتاحة */
function admin_roles()
{
    return array('admin', 'group_manager', 'manager', 'staff', 'agent', 'accountant');
}

function admin_role_label($role, $lang = null)
{
    if ($lang === null) {
        $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
    }
    $map = array(
        'admin' => array('ar' => 'مدير', 'en' => 'Admin'),
        'group_manager' => array('ar' => 'مدير وكلاء', 'en' => 'Group manager'),
        'manager' => array('ar' => 'مشرف', 'en' => 'Manager'),
        'staff' => array('ar' => 'موظف', 'en' => 'Staff'),
        'agent' => array('ar' => 'وكيل', 'en' => 'Agent'),
        'accountant' => array('ar' => 'محاسب', 'en' => 'Accountant'),
    );
    if (!isset($map[$role])) {
        return $role;
    }
    return $lang === 'en' ? $map[$role]['en'] : $map[$role]['ar'];
}

function admin_role_hint($role, $lang = null)
{
    if ($lang === null) {
        $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
    }
    if ($role === 'admin') {
        return $lang === 'en'
            ? 'Full access: users, settings, money, delete'
            : 'كل الصلاحيات: مستخدمين، إعدادات، فلوس، حذف';
    }
    if ($role === 'group_manager') {
        return $lang === 'en'
            ? 'Sees agents under him, their subscribers, cards and profits'
            : 'يشوف الوكلاء تحته ومشتركيهم والكروت والأرباح';
    }
    if ($role === 'manager') {
        return $lang === 'en'
            ? 'Daily work + reports + log (no settings/users)'
            : 'الشغل اليومي + تقارير + لوك (بدون إعدادات/مستخدمين)';
    }
    if ($role === 'agent') {
        return $lang === 'en'
            ? 'Only own subscribers + messages (no other agents)'
            : 'يوزراته فقط + رسائل (بدون وكلاء ثانيين)';
    }
    if ($role === 'accountant') {
        return $lang === 'en'
            ? 'Sees the creator agency subscribers, collects debts and sends warnings'
            : 'يشوف مشتركي الوكالة، يجمع ديونهم ويرسل تنبيه. بدون إعدادات';
    }
    return $lang === 'en'
        ? 'Subscribers, activate, debts, messages, rentals'
        : 'مشتركين، تفعيل، ديون، رسائل، إيجار';
}

/** صلاحيات كل دور */
function role_permissions($role)
{
    $role = normalize_admin_role($role);
    if ($role === 'admin') {
        return array(
            'dashboard', 'subscribers', 'activate', 'debts', 'edit_debts', 'messages', 'rentals',
            'subscriptions', 'reports', 'logs', 'plans', 'cards', 'card_accounting',
            'settings', 'users', 'agents', 'backup', 'clear_data',
        );
    }
    if ($role === 'group_manager') {
        return array(
            'dashboard', 'subscribers', 'activate', 'debts', 'edit_debts', 'messages', 'rentals',
            'subscriptions', 'reports', 'agents', 'cards', 'card_accounting', 'plans', 'users',
        );
    }
    if ($role === 'manager') {
        return array(
            'dashboard', 'subscribers', 'activate', 'debts', 'messages', 'rentals',
            'subscriptions', 'reports', 'logs', 'agents', 'cards', 'card_accounting',
        );
    }
    if ($role === 'accountant') {
        return array(
            'dashboard', 'subscribers', 'debts', 'messages',
        );
    }
    if ($role === 'agent') {
        return array(
            'dashboard', 'subscribers', 'activate', 'debts', 'messages', 'rentals',
            'cards',
        );
    }
    return array(
        'dashboard', 'subscribers', 'activate', 'debts', 'messages', 'rentals',
    );
}

function is_group_manager_user($u = null)
{
    if ($u === null) {
        $u = current_admin();
    }
    if (!$u) {
        return false;
    }
    return normalize_admin_role(isset($u['role']) ? $u['role'] : '') === 'group_manager';
}

/** معرفات مدير الكروب + الوكلاء التابعين له */
function group_manager_team_ids($pdo)
{
    $u = current_admin();
    $id = $u ? (int) $u['id'] : 0;
    if ($id <= 0) {
        return array();
    }
    $ids = array($id);
    if (!$pdo) {
        return $ids;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    try {
        $st = $pdo->prepare(
            'SELECT id FROM admin_users WHERE reports_to_user_id = :id AND tenant_id = :t'
        );
        $st->execute(array(':id' => $id, ':t' => $tid));
        foreach ($st->fetchAll() as $r) {
            $ids[] = (int) $r['id'];
        }
    } catch (Exception $e) {
    }
    return array_values(array_unique($ids));
}

function group_manager_sas_parent_ids($pdo)
{
    $ids = group_manager_team_ids($pdo);
    if (!$ids || !$pdo) {
        return array();
    }
    $mids = array();
    try {
        $in = implode(',', array_map('intval', $ids));
        $rows = $pdo->query(
            'SELECT sas_manager_id FROM admin_users WHERE id IN (' . $in . ') AND sas_manager_id IS NOT NULL AND sas_manager_id > 0'
        )->fetchAll();
        foreach ($rows as $r) {
            $mids[] = (int) $r['sas_manager_id'];
        }
    } catch (Exception $e) {
    }
    $me = current_admin();
    if ($me && !empty($me['sas_manager_id'])) {
        $mids[] = (int) $me['sas_manager_id'];
    }
    return array_values(array_unique(array_filter($mids)));
}

function is_agent_user($u = null)
{
    if ($u === null) {
        $u = current_admin();
    }
    if (!$u) {
        return false;
    }
    return normalize_admin_role(isset($u['role']) ? $u['role'] : '') === 'agent';
}

function is_accountant_user($u = null)
{
    if ($u === null) {
        $u = current_admin();
    }
    if (!$u) {
        return false;
    }
    return normalize_admin_role(isset($u['role']) ? $u['role'] : '') === 'accountant';
}

function accountant_linked_agent_id($u = null)
{
    if ($u === null) {
        $u = current_admin();
    }
    if (!$u) {
        return 0;
    }
    return isset($u['linked_agent_id']) ? (int) $u['linked_agent_id'] : 0;
}

function accountant_boss_row($pdo)
{
    $id = accountant_linked_agent_id();
    if ($id <= 0 || !$pdo || !function_exists('get_admin_user')) {
        return null;
    }
    $row = get_admin_user($pdo, $id);
    return $row ? $row : null;
}

/** agent | group_manager | admin — مستوى الشخص اللي المحاسب تابع له */
function accountant_scope_level($pdo)
{
    $boss = accountant_boss_row($pdo);
    if (!$boss) {
        return '';
    }
    $role = normalize_admin_role(isset($boss['role']) ? $boss['role'] : '');
    if ($role === 'admin') {
        return 'admin';
    }
    if ($role === 'group_manager') {
        return 'group_manager';
    }
    return 'agent';
}

function user_card_source_id($pdo)
{
    if (function_exists('is_accountant_user') && is_accountant_user()) {
        return accountant_linked_agent_id();
    }
    $me = current_admin();
    return $me ? (int) $me['id'] : 0;
}

function user_boss_has_downline($pdo)
{
    if (!function_exists('is_accountant_user') || !is_accountant_user()) {
        return false;
    }
    $lv = accountant_scope_level($pdo);
    return $lv === 'admin' || $lv === 'group_manager';
}

function user_may_transfer_cards($pdo)
{
    if (function_exists('is_super_admin_user') && is_super_admin_user()) {
        return false;
    }
    if (function_exists('is_accountant_user') && is_accountant_user()) {
        if (!user_boss_has_downline($pdo)) {
            return false;
        }
        $u = current_admin();
        return $u && current_admin_card_flag($pdo, 'can_transfer_cards');
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if (function_exists('is_admin_user') && is_admin_user()) {
        return $tid > 1;
    }
    if (function_exists('is_group_manager_user') && is_group_manager_user()) {
        $me = current_admin();
        $id = $me ? (int) $me['id'] : 0;
        return $id > 0 && function_exists('admin_user_child_count') && admin_user_child_count($pdo, $id, $tid) > 0;
    }
    return false;
}

function user_may_price_cards($pdo)
{
    if (function_exists('is_super_admin_user') && is_super_admin_user()) {
        return false;
    }
    if (function_exists('is_accountant_user') && is_accountant_user()) {
        if (!user_boss_has_downline($pdo)) {
            return false;
        }
        $u = current_admin();
        return $u && current_admin_card_flag($pdo, 'can_price_cards');
    }
    if (function_exists('is_admin_user') && is_admin_user()) {
        $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        return $tid > 1;
    }
    return function_exists('is_group_manager_user') && is_group_manager_user();
}

function creator_can_grant_card_tools($pdo)
{
    $me = current_admin();
    $id = $me ? (int) $me['id'] : 0;
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($id <= 0 || $tid <= 1) {
        return false;
    }
    if (function_exists('is_admin_user') && is_admin_user() && function_exists('agency_has_downline')) {
        return agency_has_downline($pdo, $tid, $id);
    }
    if (function_exists('admin_user_child_count') && admin_user_child_count($pdo, $id, $tid) > 0) {
        return true;
    }
    return false;
}

function current_admin_card_flag($pdo, $key)
{
    if ($key !== 'can_transfer_cards' && $key !== 'can_price_cards') {
        return false;
    }
    $sess = 'admin_' . $key;
    if (isset($_SESSION[$sess])) {
        return !empty($_SESSION[$sess]);
    }
    $u = current_admin();
    $id = $u ? (int) $u['id'] : 0;
    if ($id <= 0 || !$pdo) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT `' . $key . '` FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $id));
        $v = (int) $st->fetchColumn() === 1 ? 1 : 0;
        $_SESSION[$sess] = $v;
        return $v === 1;
    } catch (Exception $e) {
        return false;
    }
}

/** من الحساب المرتبط ونزولاً بكل الشجرة */
function accountant_tree_ids($pdo)
{
    $root = accountant_linked_agent_id();
    if ($root <= 0 || !$pdo) {
        return array();
    }
    $ids = array($root);
    $frontier = array($root);
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    for ($depth = 0; $depth < 8 && $frontier; $depth++) {
        $in = implode(',', array_map('intval', $frontier));
        try {
            $rows = $pdo->query(
                'SELECT id FROM admin_users WHERE tenant_id = ' . $tid . ' AND reports_to_user_id IN (' . $in . ')'
            )->fetchAll();
        } catch (Exception $e) {
            break;
        }
        $frontier = array();
        foreach ($rows as $r) {
            $cid = (int) $r['id'];
            if ($cid > 0 && !in_array($cid, $ids, true)) {
                $ids[] = $cid;
                $frontier[] = $cid;
            }
        }
    }
    return $ids;
}

function is_admin_user($u = null)
{
    if ($u === null) {
        $u = current_admin();
    }
    if (!$u) {
        return false;
    }
    return normalize_admin_role(isset($u['role']) ? $u['role'] : '') === 'admin';
}

function user_can_edit_debts()
{
    return user_can('edit_debts');
}

function can_manage_agents()
{
    return user_can('agents');
}

/** عمود تابع إلى + إسناد الموجودين للمدير */
function ensure_subscriber_agent_column($pdo)
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        ensure_admin_users_table($pdo);
        $col = $pdo->query("SHOW COLUMNS FROM subscribers LIKE 'agent_user_id'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE subscribers ADD COLUMN agent_user_id INT UNSIGNED NULL DEFAULT NULL AFTER notes');
            try {
                $pdo->exec('CREATE INDEX idx_subscribers_agent ON subscribers (agent_user_id)');
            } catch (Exception $e) {
            }
        }
        $adminId = (int) $pdo->query(
            "SELECT id FROM admin_users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1"
        )->fetchColumn();
        if ($adminId > 0) {
            $pdo->exec(
                'UPDATE subscribers SET agent_user_id = ' . $adminId . ' WHERE agent_user_id IS NULL'
            );
        }
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
}

function default_admin_user_id($pdo)
{
    try {
        return (int) $pdo->query(
            "SELECT id FROM admin_users WHERE role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1"
        )->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function list_agent_users($pdo, $activeOnly = true)
{
    try {
        ensure_admin_users_table($pdo);
        $tenantSql = '';
        if (function_exists('current_tenant_id')) {
            $tenantSql = ' AND tenant_id = ' . (int) current_tenant_id();
        }
        $sql = "SELECT id, username, display_name, role, is_active, created_at, updated_at, sas_manager_id, wa_local_url, wa_local_key, tenant_id, phone
                FROM admin_users WHERE role = 'agent'" . $tenantSql;
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY display_name ASC, id ASC';
        return $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        try {
            $tenantSql = '';
            if (function_exists('current_tenant_id')) {
                $tenantSql = ' AND tenant_id = ' . (int) current_tenant_id();
            }
            $sql = "SELECT id, username, display_name, role, is_active, created_at, updated_at, tenant_id
                    FROM admin_users WHERE role = 'agent'" . $tenantSql;
            if ($activeOnly) {
                $sql .= ' AND is_active = 1';
            }
            $sql .= ' ORDER BY display_name ASC, id ASC';
            return $pdo->query($sql)->fetchAll();
        } catch (Exception $e2) {
            return array();
        }
    }
}

function subscriber_agent_scope_sql($alias = 's')
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
    if ($a === '') {
        $a = 's';
    }
    $tenantSql = '';
    if (function_exists('current_tenant_id')) {
        $tenantSql = ' AND ' . $a . '.tenant_id = ' . (int) current_tenant_id();
    }
    // الدخول بوكيل ساس بدون عضوية: الجلسة تبقى رقم وكالة أوفس، فلازم النطاق يكون أبوه بالساس
    if (!empty($_SESSION['admin_sas_shadow'])) {
        $u = current_admin();
        $mid = $u && !empty($u['sas_manager_id']) ? (int) $u['sas_manager_id'] : 0;
        if ($mid <= 0) {
            return ' AND 1=0';
        }
        $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $nameEq = function_exists('sas_sql_username_eq')
            ? sas_sql_username_eq($a . '.sas_username', 'shadow_c.username')
            : ($a . '.sas_username = shadow_c.username');
        return $tenantSql . ' AND EXISTS (
            SELECT 1 FROM sas_users_cache shadow_c
            WHERE shadow_c.tenant_id = ' . (int) $tid . '
              AND shadow_c.parent_id = ' . (int) $mid . '
              AND (
                shadow_c.local_subscriber_id = ' . $a . '.id
                OR (
                    ' . $a . '.sas_username IS NOT NULL AND ' . $a . '.sas_username <> ""
                    AND ' . $nameEq . '
                )
              )
        )';
    }
    if (is_accountant_user()) {
        global $pdo;
        $aid = accountant_linked_agent_id();
        if ($aid <= 0) {
            return ' AND 1=0';
        }
        $ids = function_exists('accountant_tree_ids') ? accountant_tree_ids($pdo) : array($aid);
        if (!$ids) {
            $ids = array($aid);
        }
        return $tenantSql . ' AND ' . $a . '.agent_user_id IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
    if (!is_agent_user() && !is_group_manager_user()) {
        return $tenantSql;
    }
    $u = current_admin();
    $id = $u ? (int) $u['id'] : 0;
    if ($id <= 0) {
        return ' AND 1=0';
    }
    if (is_group_manager_user()) {
        global $pdo;
        $team = isset($pdo) && $pdo ? group_manager_team_ids($pdo) : array($id);
        if (!$team) {
            return $tenantSql;
        }
        return $tenantSql . ' AND ' . $a . '.agent_user_id IN (' . implode(',', array_map('intval', $team)) . ')';
    }
    return $tenantSql . ' AND ' . $a . '.agent_user_id = ' . $id;
}

function user_can_access_subscriber($pdo, $subscriberId)
{
    $subscriberId = (int) $subscriberId;
    if ($subscriberId <= 0) {
        return false;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    try {
        ensure_subscriber_agent_column($pdo);
        $st = $pdo->prepare('SELECT agent_user_id, tenant_id FROM subscribers WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $subscriberId));
        $row = $st->fetch();
        if (!$row) {
            return false;
        }
        $rowTid = isset($row['tenant_id']) ? (int) $row['tenant_id'] : 1;
        if ($rowTid !== $tid) {
            return false;
        }
        if (!empty($_SESSION['admin_sas_shadow'])) {
            $mid = function_exists('current_admin_sas_manager_id') ? (int) current_admin_sas_manager_id() : 0;
            if ($mid <= 0) {
                return false;
            }
            $nameEq = function_exists('sas_sql_username_eq')
                ? sas_sql_username_eq('s.sas_username', 'c.username')
                : 's.sas_username = c.username';
            $st2 = $pdo->prepare(
                'SELECT 1 FROM subscribers s
                 WHERE s.id = :id AND EXISTS (
                    SELECT 1 FROM sas_users_cache c
                    WHERE c.tenant_id = :t AND c.parent_id = :p
                      AND (
                        c.local_subscriber_id = s.id
                        OR (
                            s.sas_username IS NOT NULL AND s.sas_username <> ""
                            AND ' . $nameEq . '
                        )
                      )
                 ) LIMIT 1'
            );
            $st2->execute(array(':id' => $subscriberId, ':t' => $tid, ':p' => $mid));
            return (bool) $st2->fetchColumn();
        }
        if (is_accountant_user()) {
            $aid = accountant_linked_agent_id();
            if ($aid <= 0) {
                return false;
            }
            $team = function_exists('accountant_tree_ids') ? accountant_tree_ids($pdo) : array($aid);
            return in_array((int) $row['agent_user_id'], $team, true);
        }
        if (!is_agent_user() && !is_group_manager_user()) {
            return true;
        }
        $u = current_admin();
        $uid = $u ? (int) $u['id'] : 0;
        if ($uid <= 0) {
            return false;
        }
        if (is_group_manager_user()) {
            $team = group_manager_team_ids($pdo);
            return in_array((int) $row['agent_user_id'], $team, true);
        }
        return (int) $row['agent_user_id'] === $uid;
    } catch (Exception $e) {
        return false;
    }
}

function require_subscriber_access($pdo, $subscriberId)
{
    if (!user_can_access_subscriber($pdo, $subscriberId)) {
        $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
        flash('error', $lang === 'en' ? 'No access to this subscriber' : 'ما عندك صلاحية لهذا المشترك');
        redirect('sas.php');
    }
}

function normalize_admin_role($role)
{
    $role = strtolower(trim((string) $role));
    if (!in_array($role, admin_roles(), true)) {
        return 'staff';
    }
    return $role;
}

function user_can($perm, $role = null)
{
    if ($role === null) {
        $u = current_admin();
        $role = $u && isset($u['role']) ? $u['role'] : 'staff';
    }
    if ($role === 'accountant' && $perm !== 'dashboard') {
        global $pdo;
        $forced = function_exists('accountant_widget_perms') ? accountant_widget_perms(isset($pdo) ? $pdo : null) : null;
        if (is_array($forced)) {
            if ($perm === 'activate') {
                $uAct = current_admin();
                return $uAct && !empty($uAct['can_activate']);
            }
            if ($perm === 'card_accounting') {
                $lv = function_exists('accountant_scope_level') ? accountant_scope_level(isset($pdo) ? $pdo : null) : '';
                return $lv === 'admin' || $lv === 'group_manager';
            }
            return in_array($perm, $forced, true);
        }
    }
    $perms = role_permissions($role);
    if (in_array($perm, $perms, true)) {
        return true;
    }
    if ($role === 'accountant') {
        if (in_array($perm, array('subscriptions', 'cards', 'messages', 'reports', 'rentals'), true)) {
            return true;
        }
        if ($perm === 'activate') {
            $u = current_admin();
            return $u && !empty($u['can_activate']);
        }
        if ($perm === 'card_accounting') {
            global $pdo;
            $lv = function_exists('accountant_scope_level') ? accountant_scope_level(isset($pdo) ? $pdo : null) : '';
            return $lv === 'admin' || $lv === 'group_manager';
        }
    }
    return false;
}

function accountant_widget_catalog()
{
    return array(
        'subscribers' => array('ar' => 'المشتركين', 'en' => 'Subscribers', 'tone' => 'tone-blue', 'perm' => 'subscribers', 'nav' => 'sas'),
        'active' => array('ar' => 'فعال', 'en' => 'Active', 'tone' => 'tone-green', 'perm' => 'subscribers', 'nav' => ''),
        'online' => array('ar' => 'متصل حاليا', 'en' => 'Online', 'tone' => 'tone-aqua', 'perm' => 'subscribers', 'nav' => ''),
        'expired' => array('ar' => 'منتهي', 'en' => 'Expired', 'tone' => 'tone-red', 'perm' => 'subscribers', 'nav' => ''),
        'soon' => array('ar' => 'على وشك الانتهاء', 'en' => 'About to expire', 'tone' => 'tone-yellow', 'perm' => 'subscribers', 'nav' => ''),
        'today' => array('ar' => 'ينتهي اليوم', 'en' => 'Expiring today', 'tone' => 'tone-teal', 'perm' => 'subscribers', 'nav' => ''),
        'agents' => array('ar' => 'الوكلاء', 'en' => 'Agents', 'tone' => 'tone-purple', 'perm' => 'agents', 'nav' => 'agents'),
        'cards' => array('ar' => 'الكروت', 'en' => 'Cards', 'tone' => 'tone-navy', 'perm' => 'cards', 'nav' => 'cards'),
        'stock' => array('ar' => 'المخزون الشاغر', 'en' => 'Remaining stock', 'tone' => 'tone-navy', 'perm' => '', 'nav' => ''),
        'xfers' => array('ar' => 'التحويلات', 'en' => 'Transfers', 'tone' => 'tone-purple', 'perm' => '', 'nav' => ''),
        'card_profit' => array('ar' => 'ربح الكروت', 'en' => 'Card profit', 'tone' => 'tone-green', 'perm' => '', 'nav' => ''),
        'card_paid' => array('ar' => 'دفعات الكروت', 'en' => 'Card payments', 'tone' => 'tone-teal', 'perm' => '', 'nav' => ''),
        'card_due' => array('ar' => 'المتبقي', 'en' => 'Remaining', 'tone' => 'tone-red', 'perm' => '', 'nav' => ''),
        'collected' => array('ar' => 'المقبوض', 'en' => 'Collected', 'tone' => 'tone-yellow', 'perm' => 'reports', 'nav' => ''),
        'debts' => array('ar' => 'الديون', 'en' => 'Debts', 'tone' => 'tone-red', 'perm' => 'debts', 'nav' => 'debts'),
        'profit' => array('ar' => 'الربح', 'en' => 'Profit', 'tone' => 'tone-lime', 'perm' => 'reports', 'nav' => ''),
        'capital' => array('ar' => 'رأس المال', 'en' => 'Capital', 'tone' => 'tone-teal', 'perm' => '', 'nav' => ''),
        'sales' => array('ar' => 'المبيعات', 'en' => 'Sales', 'tone' => 'tone-purple', 'perm' => 'subscriptions', 'nav' => ''),
        'activations' => array('ar' => 'التفعيلات', 'en' => 'Activations', 'tone' => 'tone-yellow', 'perm' => 'subscriptions', 'nav' => 'subscriptions'),
        'rentals' => array('ar' => 'الإيجار', 'en' => 'Rentals', 'tone' => 'tone-teal', 'perm' => 'rentals', 'nav' => 'rentals'),
        'points' => array('ar' => 'نقاط تشجيعية', 'en' => 'Reward points', 'tone' => 'tone-lime', 'perm' => '', 'nav' => ''),
        'latency' => array('ar' => 'بنك الساس', 'en' => 'SAS latency', 'tone' => 'tone-navy', 'perm' => '', 'nav' => ''),
        'reports' => array('ar' => 'التقارير', 'en' => 'Reports', 'tone' => 'tone-green', 'perm' => 'reports', 'nav' => 'reports'),
        'messages' => array('ar' => 'الرسائل', 'en' => 'Messages', 'tone' => 'tone-aqua', 'perm' => 'messages', 'nav' => 'messages'),
    );
}

function accountant_pkg_id($name)
{
    return 'pkg:' . rawurlencode(trim((string) $name));
}

function accountant_card_package_widgets()
{
    $groups = array();
    if (function_exists('sas_dash_cards_preferred_persisted')) {
        $p = sas_dash_cards_preferred_persisted();
        if ($p && !empty($p['groups']) && is_array($p['groups'])) {
            $groups = $p['groups'];
        }
    }
    $tones = array('tone-blue', 'tone-green', 'tone-red', 'tone-yellow', 'tone-teal', 'tone-navy', 'tone-lime', 'tone-purple', 'tone-aqua');
    $out = array();
    $i = 0;
    foreach ($groups as $g) {
        $name = isset($g['name']) ? trim((string) $g['name']) : '';
        if ($name === '') {
            continue;
        }
        $id = accountant_pkg_id($name);
        if (isset($out[$id])) {
            continue;
        }
        $out[$id] = array(
            'ar' => $name,
            'en' => $name,
            'tone' => $tones[$i % count($tones)],
            'perm' => '',
            'nav' => '',
        );
        $i++;
    }
    return $out;
}

function accountant_widget_catalog_full()
{
    $base = accountant_widget_catalog();
    $pkgs = accountant_card_package_widgets();
    $out = array();
    foreach ($base as $id => $meta) {
        $out[$id] = $meta;
        if ($id === 'cards') {
            foreach ($pkgs as $pid => $pm) {
                $out[$pid] = $pm;
            }
        }
    }
    return $out;
}

function accountant_widget_id_ok($id)
{
    $id = (string) $id;
    if (isset(accountant_widget_catalog()[$id])) {
        return true;
    }
    return strpos($id, 'pkg:') === 0 && strlen($id) > 4;
}

function &accountant_widgets_rev_store()
{
    static $rev = array();
    return $rev;
}

function accountant_widgets_saved($pdo, $userId = 0)
{
    static $mem = array();
    if ($userId <= 0) {
        $u = function_exists('current_admin') ? current_admin() : null;
        $userId = $u ? (int) $u['id'] : 0;
    }
    if ($userId <= 0) {
        return null;
    }
    if (array_key_exists($userId, $mem)) {
        return $mem[$userId];
    }
    $mem[$userId] = null;
    if (!$pdo) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT ui_prefs FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $userId));
        $raw = $st->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['acc_widgets_set'])) {
            return null;
        }
        $ids = isset($data['acc_widgets']) && is_array($data['acc_widgets']) ? $data['acc_widgets'] : array();
        $clean = array();
        foreach ($ids as $id) {
            $id = (string) $id;
            if (accountant_widget_id_ok($id) && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        $revStore = &accountant_widgets_rev_store();
        $revStore[$userId] = isset($data['acc_widgets_v']) ? (int) $data['acc_widgets_v'] : 1;
        $mem[$userId] = $clean;
        return $clean;
    } catch (Exception $e) {
        return null;
    }
}

function accountant_widgets_save($pdo, $userId, $ids)
{
    $userId = (int) $userId;
    if ($userId <= 0 || !$pdo || !is_array($ids)) {
        return false;
    }
    $clean = array();
    foreach ($ids as $id) {
        $id = (string) $id;
        if (accountant_widget_id_ok($id) && !in_array($id, $clean, true)) {
            $clean[] = $id;
        }
    }
    try {
        $st = $pdo->prepare('SELECT ui_prefs, role FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $userId));
        $row = $st->fetch();
        if (!$row || normalize_admin_role($row['role']) !== 'accountant') {
            return false;
        }
        $data = array();
        if (!empty($row['ui_prefs'])) {
            $decoded = json_decode((string) $row['ui_prefs'], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $data['acc_widgets_set'] = 1;
        $data['acc_widgets_v'] = 2;
        $data['acc_widgets'] = $clean;
        $revStore = &accountant_widgets_rev_store();
        $revStore[$userId] = 2;
        $json = json_encode($data);
        if ($json === false) {
            return false;
        }
        $up = $pdo->prepare('UPDATE admin_users SET ui_prefs = :p, updated_at = NOW() WHERE id = :id AND role = "accountant"');
        $up->execute(array(':p' => $json, ':id' => $userId));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function accountant_widgets_revision($pdo, $userId = 0)
{
    if ($userId <= 0) {
        $u = function_exists('current_admin') ? current_admin() : null;
        $userId = $u ? (int) $u['id'] : 0;
    }
    accountant_widgets_saved($pdo, $userId);
    $store = &accountant_widgets_rev_store();
    return isset($store[$userId]) ? (int) $store[$userId] : 0;
}

function accountant_widgets_effective($pdo, $userId = 0)
{
    $saved = accountant_widgets_saved($pdo, $userId);
    if (!is_array($saved)) {
        return null;
    }
    if (accountant_widgets_revision($pdo, $userId) >= 2) {
        return $saved;
    }
    $extra = array('stock', 'xfers', 'card_profit', 'card_paid', 'card_due');
    if (in_array('profit', $saved, true)) {
        $extra[] = 'capital';
    }
    if (in_array('activations', $saved, true)) {
        $extra[] = 'sales';
    }
    if (in_array('cards', $saved, true)) {
        foreach (array_keys(accountant_card_package_widgets()) as $pid) {
            $extra[] = $pid;
        }
    }
    foreach ($extra as $id) {
        if (!in_array($id, $saved, true)) {
            $saved[] = $id;
        }
    }
    return $saved;
}

function accountant_widget_perms($pdo)
{
    $ids = accountant_widgets_saved($pdo);
    if (!is_array($ids)) {
        return null;
    }
    $cat = accountant_widget_catalog();
    $perms = array('dashboard');
    foreach ($ids as $id) {
        if (!isset($cat[$id]['perm'])) {
            continue;
        }
        $p = (string) $cat[$id]['perm'];
        if ($p !== '' && !in_array($p, $perms, true)) {
            $perms[] = $p;
        }
    }
    return $perms;
}

function require_perm($perm)
{
    require_login();
    if (!user_can($perm)) {
        $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
        flash('error', $lang === 'en' ? 'No permission' : 'ما عندك صلاحية لهالصفحة');
        redirect('index.php');
    }
}

function ensure_admin_users_table($pdo, $config = null)
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(60) NOT NULL,
                display_name VARCHAR(80) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT "staff",
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uq_admin_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'role'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT "staff" AFTER password_hash');
            }
        } catch (Exception $e) {
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'ui_prefs'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN ui_prefs TEXT NULL');
            }
        } catch (Exception $e) {
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'sas_manager_id'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN sas_manager_id INT UNSIGNED NULL DEFAULT NULL');
            }
        } catch (Exception $e) {
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'wa_local_url'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN wa_local_url VARCHAR(255) NULL DEFAULT NULL');
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN wa_local_key VARCHAR(120) NULL DEFAULT NULL');
            }
        } catch (Exception $e) {
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'linked_agent_id'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN linked_agent_id INT UNSIGNED NULL DEFAULT NULL');
            }
        } catch (Exception $e) {
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'tenant_id'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 1');
            }
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'phone'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN phone VARCHAR(32) NULL DEFAULT NULL');
            }
        } catch (Exception $e) {
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'can_activate'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN can_activate TINYINT(1) NOT NULL DEFAULT 0');
            }
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'can_transfer_cards'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN can_transfer_cards TINYINT(1) NOT NULL DEFAULT 0');
            }
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'can_price_cards'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN can_price_cards TINYINT(1) NOT NULL DEFAULT 0');
            }
        } catch (Exception $e) {
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'allow_login_as'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN allow_login_as TINYINT(1) NOT NULL DEFAULT 1');
            }
        } catch (Exception $e) {
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'wa_cover_ids'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN wa_cover_ids TEXT NULL');
            }
        } catch (Exception $e) {
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'reports_to_user_id'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN reports_to_user_id INT UNSIGNED NULL DEFAULT NULL');
            }
        } catch (Exception $e) {
        }
        try {
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'avatar_path'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN avatar_path VARCHAR(255) NULL DEFAULT NULL');
            }
            $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'created_by_user_id'")->fetch();
            if (!$col) {
                $pdo->exec('ALTER TABLE admin_users ADD COLUMN created_by_user_id INT UNSIGNED NULL DEFAULT NULL');
            }
        } catch (Exception $e) {
        }

        $count = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        if ($count === 0) {
            $plain = 'admin123';
            if (is_array($config) && isset($config['admin_password']) && (string) $config['admin_password'] !== '') {
                $plain = (string) $config['admin_password'];
            }
            $hash = admin_password_hash($plain);
            $ins = $pdo->prepare(
                'INSERT INTO admin_users (username, display_name, password_hash, role)
                 VALUES (:u, :d, :h, :r)'
            );
            $ins->execute(array(':u' => 'admin', ':d' => 'Admin', ':h' => $hash, ':r' => 'admin'));
            $ins->execute(array(':u' => 'staff', ':d' => 'Staff', ':h' => $hash, ':r' => 'staff'));
        } else {
            // أول مستخدم admin بدون دور واضح → مدير
            try {
                $pdo->exec("UPDATE admin_users SET role = 'admin' WHERE username = 'admin' AND (role IS NULL OR role = '' OR role = 'staff') AND id = (SELECT mid FROM (SELECT MIN(id) AS mid FROM admin_users WHERE username = 'admin') t)");
            } catch (Exception $e) {
                try {
                    $pdo->exec("UPDATE admin_users SET role = 'admin' WHERE username = 'admin'");
                } catch (Exception $e2) {
                }
            }
        }
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
        throw $e;
    }
}

function require_login()
{
    if (empty($_SESSION['admin_logged_in'])) {
        redirect('login.php');
    }
    // اشتراك منتهٍ: يسمح فقط بصفحة الفوترة وتسجيل الخروج
    $page = isset($_SERVER['PHP_SELF']) ? basename((string) $_SERVER['PHP_SELF']) : '';
    $allowWhenExpired = array('billing.php', 'zaincash_callback.php', 'logout.php', 'login.php');
    if (!empty($_SESSION['saas_force_billing']) && !in_array($page, $allowWhenExpired, true)) {
        if (function_exists('flash')) {
            flash('error', 'انتهى الاشتراك — جدّد من صفحة الفوترة');
        }
        redirect('billing.php');
    }
    global $pdo;
    if ($pdo && function_exists('is_super_admin_user') && !is_super_admin_user() && function_exists('tenant_subscription_status')) {
        $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        if ($tid > 1) {
            try {
                if (function_exists('saas_mark_expired_tenants')) {
                    saas_mark_expired_tenants($pdo);
                }
            } catch (Exception $e) {
            }
            list($ok, $code) = tenant_subscription_status($pdo, $tid);
            if (!$ok && ($code === 'expired' || $code === 'suspended' || $code === 'pending')) {
                if ($code === 'expired') {
                    $_SESSION['saas_force_billing'] = 1;
                    if (!in_array($page, $allowWhenExpired, true)) {
                        redirect('billing.php');
                    }
                } elseif ($code !== 'expired') {
                    // معلق / pending — اخرج
                    $_SESSION = array();
                    if (function_exists('flash')) {
                        flash('error', $code === 'pending' ? 'بانتظار موافقة الإدارة' : 'الحساب معلّق');
                    }
                    redirect('login.php');
                }
            } else {
                unset($_SESSION['saas_force_billing']);
            }
        }
    }
}

function current_admin()
{
    if (empty($_SESSION['admin_logged_in'])) {
        return null;
    }
    return array(
        'id' => isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : 0,
        'username' => isset($_SESSION['admin_username']) ? (string) $_SESSION['admin_username'] : 'admin',
        'display_name' => isset($_SESSION['admin_display_name']) ? (string) $_SESSION['admin_display_name'] : 'Admin',
        'role' => isset($_SESSION['admin_role']) ? normalize_admin_role($_SESSION['admin_role']) : 'admin',
        'sas_manager_id' => isset($_SESSION['admin_sas_manager_id']) ? (int) $_SESSION['admin_sas_manager_id'] : 0,
        'wa_local_url' => isset($_SESSION['admin_wa_local_url']) ? (string) $_SESSION['admin_wa_local_url'] : '',
        'wa_local_key' => isset($_SESSION['admin_wa_local_key']) ? (string) $_SESSION['admin_wa_local_key'] : '',
        'linked_agent_id' => isset($_SESSION['admin_linked_agent_id']) ? (int) $_SESSION['admin_linked_agent_id'] : 0,
        'can_activate' => !empty($_SESSION['admin_can_activate']) ? 1 : 0,
        'can_transfer_cards' => !empty($_SESSION['admin_can_transfer_cards']) ? 1 : 0,
        'can_price_cards' => !empty($_SESSION['admin_can_price_cards']) ? 1 : 0,
        'tenant_id' => isset($_SESSION['admin_tenant_id']) ? max(1, (int) $_SESSION['admin_tenant_id']) : 1,
    );
}

function is_impersonating()
{
    return !empty($_SESSION['admin_real_user_id']);
}

function impersonation_real_admin_id()
{
    return isset($_SESSION['admin_real_user_id']) ? (int) $_SESSION['admin_real_user_id'] : 0;
}

/**
 * فلترة كاش الساس حسب وكيل SAS المرتبط بالمستخدم الحالي.
 * الأدمن/المشرف يشوف الكل. الوكيل يشوف يوزرات parent_id = sas_manager_id.
 */
function sas_agent_scope_sql($alias = 'c')
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
    if ($a === '') {
        $a = 'c';
    }
    $tenantSql = '';
    if (function_exists('current_tenant_id')) {
        $tenantSql = ' AND ' . $a . '.tenant_id = ' . (int) current_tenant_id();
    }
    if (!is_agent_user() && !is_group_manager_user()) {
        return $tenantSql;
    }
    $u = current_admin();
    if (is_group_manager_user()) {
        global $pdo;
        $mids = (isset($pdo) && $pdo) ? group_manager_sas_parent_ids($pdo) : array();
        if (!$mids) {
            return $tenantSql;
        }
        $in = implode(',', array_map('intval', $mids));
        return $tenantSql . ' AND ' . $a . '.parent_id IN (' . $in . ')';
    }
    $mid = $u && !empty($u['sas_manager_id']) ? (int) $u['sas_manager_id'] : 0;
    if ($mid <= 0) {
        return $tenantSql;
    }
    return $tenantSql . ' AND ' . $a . '.parent_id = ' . $mid;
}

function current_admin_sas_manager_id()
{
    $u = current_admin();
    return $u && !empty($u['sas_manager_id']) ? (int) $u['sas_manager_id'] : 0;
}

function user_can_access_sas_username($pdo, $username)
{
    $username = trim((string) $username);
    if ($username === '') {
        return false;
    }
    if (!is_agent_user() && !is_group_manager_user()) {
        return true;
    }
    $mid = current_admin_sas_manager_id();
    $allowed = array();
    if (is_group_manager_user()) {
        $allowed = group_manager_sas_parent_ids($pdo);
    } elseif ($mid > 0) {
        $allowed = array($mid);
    }
    if (!$allowed) {
        return false;
    }
    try {
        $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $st = $pdo->prepare(
            'SELECT parent_id FROM sas_users_cache WHERE username = :u AND tenant_id = :t LIMIT 1'
        );
        $st->execute(array(':u' => $username, ':t' => $tid));
        $pid = $st->fetchColumn();
        return $pid !== false && in_array((int) $pid, $allowed, true);
    } catch (Exception $e) {
        return false;
    }
}

function require_sas_user_access($pdo, $username)
{
    if (!user_can_access_sas_username($pdo, $username)) {
        $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
        flash('error', $lang === 'en' ? 'No access to this SAS user' : 'ما عندك صلاحية لهذا المشترك');
        redirect('sas.php');
    }
}

function current_admin_label()
{
    $u = current_admin();
    if (!$u) {
        return '';
    }
    if ($u['display_name'] !== '' && $u['display_name'] !== $u['username']) {
        return $u['display_name'] . ' (' . $u['username'] . ')';
    }
    return $u['username'];
}

function admin_ui_prefs_load($pdo)
{
    if (!empty($_SESSION['ui_prefs']) && is_array($_SESSION['ui_prefs'])) {
        return $_SESSION['ui_prefs'];
    }
    $u = current_admin();
    $uid = $u ? (int) $u['id'] : 0;
    $data = array();
    if ($uid > 0) {
        try {
            $st = $pdo->prepare('SELECT ui_prefs FROM admin_users WHERE id = :id LIMIT 1');
            $st->execute(array(':id' => $uid));
            $raw = $st->fetchColumn();
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        } catch (Exception $e) {
        }
    }
    $_SESSION['ui_prefs'] = $data;
    return $data;
}

function admin_ui_prefs_save($pdo, $key, $value)
{
    $prefs = admin_ui_prefs_load($pdo);
    $prefs[$key] = $value;
    $_SESSION['ui_prefs'] = $prefs;
    $u = current_admin();
    $uid = $u ? (int) $u['id'] : 0;
    if ($uid <= 0) {
        return true;
    }
    try {
        $st = $pdo->prepare('UPDATE admin_users SET ui_prefs = :p, updated_at = NOW() WHERE id = :id');
        $st->execute(array(
            ':p' => json_encode($prefs),
            ':id' => $uid,
        ));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function set_admin_session_from_row($row)
{
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_user_id'] = (int) $row['id'];
    $_SESSION['admin_username'] = $row['username'];
    $_SESSION['admin_display_name'] = $row['display_name'];
    $_SESSION['admin_role'] = normalize_admin_role(isset($row['role']) ? $row['role'] : 'staff');
    $_SESSION['admin_sas_manager_id'] = isset($row['sas_manager_id']) ? (int) $row['sas_manager_id'] : 0;
    $_SESSION['admin_wa_local_url'] = isset($row['wa_local_url']) ? (string) $row['wa_local_url'] : '';
    $_SESSION['admin_wa_local_key'] = isset($row['wa_local_key']) ? (string) $row['wa_local_key'] : '';
    $_SESSION['admin_linked_agent_id'] = isset($row['linked_agent_id']) ? (int) $row['linked_agent_id'] : 0;
    $_SESSION['admin_can_activate'] = !empty($row['can_activate']) ? 1 : 0;
    $_SESSION['admin_can_transfer_cards'] = !empty($row['can_transfer_cards']) ? 1 : 0;
    $_SESSION['admin_can_price_cards'] = !empty($row['can_price_cards']) ? 1 : 0;
    $_SESSION['admin_tenant_id'] = isset($row['tenant_id']) ? max(1, (int) $row['tenant_id']) : 1;
    unset($_SESSION['ui_prefs']);
}

/**
 * دخول بصفة وكيل (للأدمن فقط) مع حفظ الجلسة الأصلية.
 */
function agency_has_downline($pdo, $tenantId, $exceptId)
{
    $tenantId = (int) $tenantId;
    $exceptId = (int) $exceptId;
    if ($tenantId <= 1 || !$pdo) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_users
             WHERE tenant_id = :t AND id <> :me AND is_active = 1
               AND role IN ("agent", "group_manager")'
        );
        $st->execute(array(':t' => $tenantId, ':me' => $exceptId));
        return ((int) $st->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

/** super = أدمن المنصة، agency = حساب الوكالة، parent = وكيل تحته وكلاء */
function portal_sas_host_key($host)
{
    $host = strtolower(trim((string) $host));
    $host = preg_replace('#^https?://#i', '', $host);
    return rtrim($host, '/');
}

function portal_sas_manager_tree_file()
{
    return dirname(__DIR__) . '/storage/sas_manager_tree.json';
}

/** شجرة مدراء الساس: id واسم والأب. تتحدث مرة باليوم حتى ما نسأل السيرفر بكل صفحة */
function portal_sas_manager_tree($pdo)
{
    static $memo = null;
    if (is_array($memo)) {
        return $memo;
    }
    $memo = array();
    $file = portal_sas_manager_tree_file();
    $saved = array();
    $age = 999999;
    if (is_file($file)) {
        $raw = json_decode((string) @file_get_contents($file), true);
        if (is_array($raw) && isset($raw['rows']) && is_array($raw['rows'])) {
            $saved = $raw['rows'];
            $age = time() - (isset($raw['at']) ? (int) $raw['at'] : 0);
        }
    }
    if ($saved && $age >= 0 && $age < 86400) {
        $memo = $saved;
        return $memo;
    }
    $fresh = portal_sas_manager_tree_fetch($pdo);
    if ($fresh) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($file, json_encode(array('at' => time(), 'rows' => $fresh)));
        $memo = $fresh;
        return $memo;
    }
    $memo = $saved;
    return $memo;
}

function portal_sas_manager_tree_fetch($pdo)
{
    if (!$pdo || !function_exists('sas_make_connector_from_account') || !function_exists('tenant_sas_account_default')) {
        return array();
    }
    if (!class_exists('SASConnector')) {
        $cf = __DIR__ . '/sas/SASConnector.php';
        if (is_file($cf)) {
            require_once $cf;
        }
    }
    if (!class_exists('SASConnector')) {
        return array();
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
    $acc = ($tid > 0) ? tenant_sas_account_default($pdo, $tid) : null;
    if (!$acc || empty($acc['sas_enabled'])) {
        $acc = null;
        try {
            $ids = $pdo->query('SELECT id FROM tenants WHERE id > 1 ORDER BY id ASC')->fetchAll();
            foreach ($ids as $tr) {
                $try = tenant_sas_account_default($pdo, (int) $tr['id']);
                if ($try && !empty($try['sas_enabled'])) {
                    $acc = $try;
                    break;
                }
            }
        } catch (Exception $e) {
            $acc = null;
        }
    }
    if (!$acc) {
        return array();
    }
    $api = sas_make_connector_from_account($acc);
    if (!$api || !method_exists($api, 'getManagers') || !method_exists($api, 'getManagerById')) {
        return array();
    }
    $raw = $api->getManagers();
    $list = is_array($raw) ? $raw : array();
    if (isset($list['data']) && is_array($list['data'])) {
        $list = $list['data'];
    }
    $out = array();
    $n = 0;
    foreach ($list as $m) {
        if (!is_array($m) || empty($m['id'])) {
            continue;
        }
        $n++;
        if ($n > 200) {
            break;
        }
        $id = (int) $m['id'];
        $one = $api->getManagerById($id);
        $name = (is_array($one) && !empty($one['username'])) ? (string) $one['username'] : (isset($m['username']) ? (string) $m['username'] : '');
        if ($name === '') {
            continue;
        }
        $out[] = array(
            'id' => $id,
            'username' => $name,
            'parent_id' => (is_array($one) && isset($one['parent_id'])) ? (int) $one['parent_id'] : 0,
        );
    }
    return $out;
}

function portal_sas_tree_maps($pdo)
{
    static $maps = null;
    if (is_array($maps)) {
        return $maps;
    }
    $byUser = array();
    $parentOf = array();
    foreach (portal_sas_manager_tree($pdo) as $node) {
        if (!is_array($node)) {
            continue;
        }
        $id = isset($node['id']) ? (int) $node['id'] : 0;
        $name = isset($node['username']) ? strtolower(trim((string) $node['username'])) : '';
        if ($id <= 0 || $name === '') {
            continue;
        }
        $byUser[$name] = $id;
        $parentOf[$id] = isset($node['parent_id']) ? (int) $node['parent_id'] : 0;
    }
    $maps = array('by_user' => $byUser, 'parent_of' => $parentOf);
    return $maps;
}

function portal_sas_id_for_names($pdo, $names, $explicitId)
{
    $explicitId = (int) $explicitId;
    if ($explicitId > 0) {
        return $explicitId;
    }
    $maps = portal_sas_tree_maps($pdo);
    foreach ($names as $name) {
        $key = strtolower(trim((string) $name));
        if ($key !== '' && isset($maps['by_user'][$key])) {
            return (int) $maps['by_user'][$key];
        }
    }
    return 0;
}

function portal_sas_id_is_under($pdo, $childId, $rootId)
{
    $childId = (int) $childId;
    $rootId = (int) $rootId;
    if ($childId <= 0 || $rootId <= 0 || $childId === $rootId) {
        return false;
    }
    $maps = portal_sas_tree_maps($pdo);
    $parentOf = $maps['parent_of'];
    $cur = $childId;
    for ($i = 0; $i < 8; $i++) {
        if (!isset($parentOf[$cur])) {
            return false;
        }
        $p = (int) $parentOf[$cur];
        if ($p <= 0) {
            return false;
        }
        if ($p === $rootId) {
            return true;
        }
        $cur = $p;
    }
    return false;
}

/** وكالة بوابة أبوها المباشر هو الحساب الحالي، مو مجرد نفس هوست الساس */
function portal_agency_reports_to_me($pdo, $theirRow)
{
    $me = current_admin();
    if (!$me || !$pdo || !is_array($theirRow)) {
        return false;
    }
    $mySas = isset($me['sas_manager_id']) ? (int) $me['sas_manager_id'] : 0;
    if ($mySas <= 0 && !empty($me['id'])) {
        try {
            $stMe = $pdo->prepare('SELECT sas_manager_id FROM admin_users WHERE id = :id LIMIT 1');
            $stMe->execute(array(':id' => (int) $me['id']));
            $mySas = (int) $stMe->fetchColumn();
        } catch (Exception $e) {
            $mySas = 0;
        }
    }
    $myUser = isset($me['username']) ? strtolower(trim((string) $me['username'])) : '';
    $myDisp = isset($me['display_name']) ? strtolower(trim((string) $me['display_name'])) : '';
    $myNames = array();
    if ($myUser !== '') {
        $myNames[$myUser] = true;
    }
    if ($myDisp !== '') {
        $myNames[$myDisp] = true;
    }
    $names = array();
    $u = isset($theirRow['username']) ? trim((string) $theirRow['username']) : '';
    $d = isset($theirRow['display_name']) ? trim((string) $theirRow['display_name']) : '';
    if ($u !== '') {
        $names[$u] = true;
    }
    if ($d !== '' && strcasecmp($d, $u) !== 0) {
        $names[$d] = true;
    }
    if ($mySas <= 0 && !$myNames) {
        return false;
    }
    $theirSas = isset($theirRow['sas_manager_id']) ? (int) $theirRow['sas_manager_id'] : 0;
    if ($theirSas > 0) {
        try {
            $stSas = $pdo->prepare(
                'SELECT parent_id, parent_name FROM sas_users_cache WHERE sas_user_id = :id LIMIT 20'
            );
            $stSas->execute(array(':id' => $theirSas));
            foreach ($stSas->fetchAll() as $row) {
                $pid = isset($row['parent_id']) ? (int) $row['parent_id'] : 0;
                $pn = isset($row['parent_name']) ? strtolower(trim((string) $row['parent_name'])) : '';
                if ($mySas > 0 && $pid === $mySas) {
                    return true;
                }
                if ($pn !== '' && isset($myNames[$pn])) {
                    return true;
                }
            }
        } catch (Exception $e) {
        }
    }
    if (!$names) {
        return false;
    }
    $params = array();
    $holders = array();
    $i = 0;
    foreach ($names as $name => $yes) {
        $i++;
        $key = ':n' . $i;
        $holders[] = $key;
        $params[$key] = $name;
    }
    try {
        $st = $pdo->prepare(
            'SELECT parent_id, parent_name FROM sas_users_cache WHERE username IN (' . implode(',', $holders) . ') LIMIT 30'
        );
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (Exception $e) {
        $rows = array();
    }
    if (!is_array($rows)) {
        return false;
    }
    foreach ($rows as $row) {
        $pid = isset($row['parent_id']) ? (int) $row['parent_id'] : 0;
        $pn = isset($row['parent_name']) ? strtolower(trim((string) $row['parent_name'])) : '';
        if ($mySas > 0 && $pid === $mySas) {
            return true;
        }
        if ($pn !== '' && isset($myNames[$pn])) {
            return true;
        }
    }
    $myResolved = portal_sas_id_for_names($pdo, array($myUser, $myDisp), $mySas);
    $theirResolved = portal_sas_id_for_names($pdo, array($u, $d), $theirSas);
    if (portal_sas_id_is_under($pdo, $theirResolved, $myResolved)) {
        return true;
    }
    return false;
}

/** وكالات البوابة اللي أبوها المباشر هو الحساب الحالي */
function portal_agencies_under_current($pdo, $q = '')
{
    $me = current_admin();
    $myTid = $me && isset($me['tenant_id']) ? (int) $me['tenant_id'] : 1;
    $myId = $me ? (int) $me['id'] : 0;
    if ($myId <= 0 || !$pdo) {
        return array();
    }
    if ($myTid <= 0) {
        $myTid = 1;
    }
    $q = trim((string) $q);
    try {
        $sql = 'SELECT u.id, u.username, u.display_name, u.role, u.is_active, u.created_at, u.updated_at,
                       u.sas_manager_id, u.tenant_id, u.phone, t.sas_host, t.sas_username, t.owner_user_id, t.name AS tenant_name
                FROM admin_users u
                INNER JOIN tenants t ON t.id = u.tenant_id
                WHERE u.role = "admin" AND u.tenant_id > 1 AND u.tenant_id <> :my AND u.id <> :uid';
        $params = array(':my' => $myTid, ':uid' => $myId);
        if ($q !== '') {
            $sql .= ' AND (u.username LIKE :q OR u.display_name LIKE :q2 OR t.name LIKE :q3)';
            $like = '%' . $q . '%';
            $params[':q'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }
        $sql .= ' ORDER BY u.username ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (Exception $e) {
        return array();
    }
    $best = array();
    foreach ($rows as $r) {
        $uid = (int) $r['id'];
        $tidRow = isset($r['tenant_id']) ? (int) $r['tenant_id'] : 0;
        if ($uid <= 0 || $tidRow <= 1) {
            continue;
        }
        if (!portal_agency_reports_to_me($pdo, $r)) {
            continue;
        }
        $sasName = isset($r['sas_username']) ? strtolower(trim((string) $r['sas_username'])) : '';
        $uname = isset($r['username']) ? strtolower(trim((string) $r['username'])) : '';
        $ownerId = isset($r['owner_user_id']) ? (int) $r['owner_user_id'] : 0;
        $score = 1;
        if ($ownerId > 0 && $ownerId === $uid) {
            $score = 3;
        }
        if ($sasName !== '' && $sasName === $uname) {
            $score = 4;
        }
        if (!isset($best[$tidRow]) || $score > $best[$tidRow]['score']) {
            $best[$tidRow] = array('score' => $score, 'row' => $r);
        }
    }
    $out = array();
    foreach ($best as $item) {
        $out[] = $item['row'];
    }
    return $out;
}

function admin_allow_login_as($pdo, $userId = 0)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        $me = current_admin();
        $userId = $me ? (int) $me['id'] : 0;
    }
    if ($userId <= 0 || !$pdo) {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT allow_login_as FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $userId));
        $v = $st->fetchColumn();
        if ($v === false) {
            return false;
        }
        return (int) $v === 1;
    } catch (Exception $e) {
        return true;
    }
}

function portal_sas_manager_under_me($pdo, $sasId, $tenantId)
{
    $sasId = (int) $sasId;
    $tenantId = (int) $tenantId;
    if ($sasId <= 0 || $tenantId <= 1 || !$pdo) {
        return false;
    }
    $me = current_admin();
    if (!$me) {
        return false;
    }
    $myMid = isset($me['sas_manager_id']) ? (int) $me['sas_manager_id'] : 0;
    if ($myMid > 0 && $myMid === $sasId) {
        return false;
    }
    if (is_admin_user()) {
        return true;
    }
    $roots = array();
    if (is_group_manager_user() && function_exists('group_manager_sas_parent_ids')) {
        $roots = group_manager_sas_parent_ids($pdo);
    }
    if ($myMid > 0) {
        $roots[] = $myMid;
    }
    $roots = array_values(array_unique(array_map('intval', $roots)));
    if (!$roots) {
        return false;
    }
    $cur = $sasId;
    for ($i = 0; $i < 8; $i++) {
        if (in_array($cur, $roots, true)) {
            return true;
        }
        try {
            $st = $pdo->prepare(
                'SELECT parent_id FROM sas_users_cache WHERE tenant_id = :t AND sas_user_id = :id LIMIT 1'
            );
            $st->execute(array(':t' => $tenantId, ':id' => $cur));
            $pid = (int) $st->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
        if ($pid <= 0) {
            return false;
        }
        if (in_array($pid, $roots, true)) {
            return true;
        }
        $cur = $pid;
    }
    return false;
}

/** وكيل ساس تحت الحساب الحالي، مو مضاف كمستخدم بالبوابة */
function portal_unlicensed_managers($pdo, $q = '')
{
    $me = current_admin();
    $tid = $me && isset($me['tenant_id']) ? (int) $me['tenant_id'] : 1;
    if ($tid <= 1 || !$pdo || !$me) {
        return array();
    }
    if (!admin_allow_login_as($pdo, (int) $me['id'])) {
        return array();
    }
    $q = trim((string) $q);
    if ($q === '') {
        return array();
    }
    $like = '%' . $q . '%';
    try {
        $st = $pdo->prepare(
            'SELECT parent_id, MAX(parent_name) AS parent_name
             FROM sas_users_cache
             WHERE tenant_id = :t AND parent_id > 0 AND parent_name LIKE :q
             GROUP BY parent_id
             ORDER BY MAX(parent_name) ASC
             LIMIT 30'
        );
        $st->execute(array(':t' => $tid, ':q' => $like));
        $rows = $st->fetchAll();
    } catch (Exception $e) {
        return array();
    }
    $linked = array();
    $licensedNames = array();
    try {
        $ls = $pdo->prepare(
            'SELECT sas_manager_id, username, display_name FROM admin_users
             WHERE sas_manager_id IS NOT NULL AND sas_manager_id > 0'
        );
        $ls->execute();
        foreach ($ls->fetchAll() as $lr) {
            $mid = (int) $lr['sas_manager_id'];
            if ($mid > 0) {
                $linked[$mid] = true;
            }
            $un = strtolower(trim((string) $lr['username']));
            $dn = strtolower(trim((string) $lr['display_name']));
            if ($un !== '') {
                $licensedNames[$un] = true;
            }
            if ($dn !== '') {
                $licensedNames[$dn] = true;
            }
        }
    } catch (Exception $e) {
    }
    try {
        foreach ($pdo->query('SELECT username FROM admin_users')->fetchAll() as $ur) {
            $un = strtolower(trim((string) (isset($ur['username']) ? $ur['username'] : '')));
            if ($un !== '') {
                $licensedNames[$un] = true;
            }
        }
    } catch (Exception $e) {
    }
    try {
        foreach ($pdo->query('SELECT sas_username, name FROM tenants WHERE id > 1')->fetchAll() as $tr) {
            $su = strtolower(trim((string) (isset($tr['sas_username']) ? $tr['sas_username'] : '')));
            $nm = strtolower(trim((string) (isset($tr['name']) ? $tr['name'] : '')));
            if ($su !== '') {
                $licensedNames[$su] = true;
            }
            if ($nm !== '') {
                $licensedNames[$nm] = true;
            }
        }
    } catch (Exception $e) {
    }
    $out = array();
    foreach ($rows as $r) {
        $sid = (int) $r['parent_id'];
        $name = trim((string) $r['parent_name']);
        if ($sid <= 0 || $name === '' || isset($linked[$sid])) {
            continue;
        }
        if (isset($licensedNames[strtolower($name)])) {
            continue;
        }
        if (!portal_sas_manager_under_me($pdo, $sid, $tid)) {
            continue;
        }
        $out[] = array(
            'id' => 0,
            'sas_id' => $sid,
            'username' => $name,
            'display_name' => $name,
            'role' => 'sas',
            'tenant_id' => $tid,
        );
        if (count($out) >= 20) {
            break;
        }
    }
    return $out;
}

function impersonate_shadow_cookie_write($val, $exp)
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
        setcookie('app_shadow', (string) $val, array(
            'expires' => (int) $exp,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    } else {
        setcookie('app_shadow', (string) $val, (int) $exp, '/', '', $secure, true);
    }
    if ((string) $val === '') {
        unset($_COOKIE['app_shadow']);
    } else {
        $_COOKIE['app_shadow'] = (string) $val;
    }
}

function impersonate_shadow_cookie_clear()
{
    impersonate_shadow_cookie_write('', time() - 3600);
}

function impersonate_shadow_cookie_set($sasId, $name, $realId, $realName)
{
    $sasId = (int) $sasId;
    $realId = (int) $realId;
    $name = str_replace(array('|', "\n", "\r"), '', (string) $name);
    $realName = str_replace(array('|', "\n", "\r"), '', (string) $realName);
    if ($sasId <= 0 || $realId <= 0 || $name === '') {
        return;
    }
    $exp = time() + 86400;
    $secret = function_exists('app_remember_secret') ? app_remember_secret() : 'shadow';
    $sig = hash_hmac('sha256', $sasId . '|' . $realId . '|' . $name . '|' . $realName . '|' . $exp, $secret);
    $val = $sasId . '|' . $realId . '|' . rawurlencode($name) . '|' . rawurlencode($realName) . '|' . $exp . '|' . $sig;
    impersonate_shadow_cookie_write($val, $exp);
}

function impersonate_shadow_restore()
{
    if (empty($_COOKIE['app_shadow']) || !is_string($_COOKIE['app_shadow'])) {
        return;
    }
    if (empty($_SESSION['admin_logged_in'])) {
        return;
    }
    $parts = explode('|', (string) $_COOKIE['app_shadow']);
    if (count($parts) !== 6) {
        impersonate_shadow_cookie_clear();
        return;
    }
    $sasId = (int) $parts[0];
    $realId = (int) $parts[1];
    $name = rawurldecode($parts[2]);
    $realName = rawurldecode($parts[3]);
    $exp = (int) $parts[4];
    $sig = $parts[5];
    if ($exp < time() || $sasId <= 0 || $realId <= 0 || $name === '') {
        impersonate_shadow_cookie_clear();
        return;
    }
    $secret = function_exists('app_remember_secret') ? app_remember_secret() : 'shadow';
    $expect = hash_hmac('sha256', $sasId . '|' . $realId . '|' . $name . '|' . $realName . '|' . $exp, $secret);
    if (!hash_equals($expect, $sig)) {
        impersonate_shadow_cookie_clear();
        return;
    }
    $sid = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : 0;
    if ($sid !== $realId) {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE && function_exists('app_session_start')) {
        app_session_start();
    }
    $_SESSION['admin_sas_shadow'] = 1;
    $_SESSION['admin_real_user_id'] = $realId;
    if ($realName !== '' && empty($_SESSION['admin_real_username'])) {
        $_SESSION['admin_real_username'] = $realName;
        $_SESSION['admin_real_display_name'] = $realName;
    }
    $_SESSION['admin_role'] = 'agent';
    $_SESSION['admin_sas_manager_id'] = $sasId;
    $_SESSION['admin_username'] = $name;
    $_SESSION['admin_display_name'] = $name;
}

function impersonate_session_writable()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (function_exists('app_session_start')) {
        app_session_start();
    } elseif (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
}

function impersonate_start_sas($pdo, $sasId)
{
    $sasId = (int) $sasId;
    if ($sasId <= 0) {
        return array(false, 'الوكيل غير محدد');
    }
    if (is_impersonating()) {
        return array(false, 'ارجع لحسابك أولاً');
    }
    $mode = impersonate_actor_mode($pdo);
    if ($mode !== 'agency' && $mode !== 'parent') {
        return array(false, 'غير مسموح');
    }
    $me = current_admin();
    if (!$me || !admin_allow_login_as($pdo, (int) $me['id'])) {
        return array(false, 'الدخول بـ موقف لهذا الحساب');
    }
    $tid = isset($me['tenant_id']) ? (int) $me['tenant_id'] : 1;
    if ($tid <= 1) {
        return array(false, 'غير مسموح');
    }
    try {
        $st = $pdo->prepare(
            'SELECT parent_name FROM sas_users_cache
             WHERE tenant_id = :t AND parent_id = :id AND parent_name IS NOT NULL AND parent_name <> ""
             LIMIT 1'
        );
        $st->execute(array(':t' => $tid, ':id' => $sasId));
        $name = trim((string) $st->fetchColumn());
    } catch (Exception $e) {
        return array(false, 'تعذر التحقق');
    }
    if ($name === '') {
        return array(false, 'الوكيل مو ضمن حسابك');
    }
    try {
        $tn = $pdo->prepare('SELECT id FROM tenants WHERE id > 1 AND LOWER(sas_username) = :u LIMIT 1');
        $tn->execute(array(':u' => strtolower($name)));
        if ((int) $tn->fetchColumn() > 0) {
            $own = $pdo->prepare(
                'SELECT id FROM admin_users WHERE LOWER(username) = :u AND tenant_id <> :t AND role = "admin" LIMIT 1'
            );
            $own->execute(array(':u' => strtolower($name), ':t' => $tid));
            $ownId = (int) $own->fetchColumn();
            if ($ownId > 0 && $ownId !== (int) $me['id']) {
                $sw = impersonate_start($pdo, $ownId);
                if (!empty($sw[0])) {
                    return $sw;
                }
            }
        }
    } catch (Exception $e) {
    }
    $linkedId = 0;
    try {
        $ls = $pdo->prepare(
            'SELECT id FROM admin_users WHERE sas_manager_id = :m AND tenant_id = :t LIMIT 1'
        );
        $ls->execute(array(':m' => $sasId, ':t' => $tid));
        $linkedId = (int) $ls->fetchColumn();
    } catch (Exception $e) {
        $linkedId = 0;
    }
    if ($linkedId > 0 && $linkedId !== (int) $me['id']) {
        $sw = impersonate_start($pdo, $linkedId);
        if (!empty($sw[0])) {
            return $sw;
        }
    }
    if (!portal_sas_manager_under_me($pdo, $sasId, $tid)) {
        return array(false, 'هذا الوكيل مو تحتك');
    }
    impersonate_session_writable();
    $_SESSION['admin_real_user_id'] = (int) $me['id'];
    $_SESSION['admin_real_username'] = $me['username'];
    $_SESSION['admin_real_display_name'] = $me['display_name'];
    $_SESSION['admin_sas_shadow'] = 1;
    $_SESSION['admin_role'] = 'agent';
    $_SESSION['admin_sas_manager_id'] = $sasId;
    $_SESSION['admin_username'] = $name;
    $_SESSION['admin_display_name'] = $name;
    impersonate_shadow_cookie_set($sasId, $name, (int) $me['id'], (string) $me['username']);
    if (function_exists('app_session_refresh_cookie')) {
        app_session_refresh_cookie();
    }
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    return array(true, 'تم الدخول بصفة الوكيل ' . $name);
}

function impersonate_actor_mode($pdo)
{
    if (is_impersonating()) {
        return '';
    }
    if (function_exists('is_super_admin_user') && is_super_admin_user()) {
        return 'super';
    }
    if (!admin_allow_login_as($pdo)) {
        return '';
    }
    $me = current_admin();
    if (!$me) {
        return '';
    }
    $tid = isset($me['tenant_id']) ? (int) $me['tenant_id'] : 1;
    $id = (int) $me['id'];
    if ($tid <= 1 || $id <= 0) {
        return '';
    }
    if (is_admin_user()) {
        return 'agency';
    }
    if (is_group_manager_user()) {
        $gmid = isset($me['sas_manager_id']) ? (int) $me['sas_manager_id'] : 0;
        if (admin_user_child_count($pdo, $id, $tid) > 0 || portal_user_has_agent_downline($pdo, $tid, $gmid)) {
            return 'agency';
        }
        return '';
    }
    if (function_exists('user_can') && user_can('agents') && !is_agent_user() && !is_accountant_user()) {
        return 'agency';
    }
    if (is_agent_user()) {
        $mid = isset($me['sas_manager_id']) ? (int) $me['sas_manager_id'] : 0;
        if (portal_user_has_agent_downline($pdo, $tid, $mid) || admin_user_child_count($pdo, $id, $tid) > 0) {
            return 'parent';
        }
        return '';
    }
    if (admin_user_child_count($pdo, $id, $tid) > 0) {
        return 'parent';
    }
    return '';
}

function portal_user_has_agent_downline($pdo, $tenantId, $sasManagerId)
{
    $tenantId = (int) $tenantId;
    $sasManagerId = (int) $sasManagerId;
    if ($tenantId <= 1 || $sasManagerId <= 0 || !$pdo) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(DISTINCT c.parent_id) FROM sas_users_cache c
             INNER JOIN sas_users_cache mgr
               ON mgr.tenant_id = c.tenant_id AND mgr.sas_user_id = c.parent_id
             WHERE c.tenant_id = :t AND c.parent_id > 0 AND c.parent_id <> :m
               AND mgr.parent_id = :m2'
        );
        $st->execute(array(':t' => $tenantId, ':m' => $sasManagerId, ':m2' => $sasManagerId));
        return ((int) $st->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

function admin_user_child_count($pdo, $userId, $tenantId)
{
    $userId = (int) $userId;
    $tenantId = (int) $tenantId;
    if ($userId <= 0 || !$pdo) {
        return 0;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM admin_users
             WHERE reports_to_user_id = :id AND tenant_id = :t AND id <> :id2
               AND role IN ("agent", "group_manager")'
        );
        $st->execute(array(':id' => $userId, ':id2' => $userId, ':t' => $tenantId));
        return (int) $st->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function impersonate_start($pdo, $targetUserId)
{
    $targetUserId = (int) $targetUserId;
    if ($targetUserId <= 0) {
        return array(false, 'المستخدم غير محدد');
    }
    $me = current_admin();
    if (!$me || (int) $me['id'] === $targetUserId) {
        return array(false, 'لا يمكن');
    }
    $already = is_impersonating();
    $mode = impersonate_actor_mode($pdo);
    if ($already) {
        if (!is_admin_user()) {
            return array(false, 'غير مسموح');
        }
    } elseif ($mode === '') {
        return array(false, 'غير مسموح');
    }
    try {
        ensure_admin_users_table($pdo);
        $activeSql = ($mode === 'agency' || $mode === 'parent') ? '' : ' AND is_active = 1';
        $st = $pdo->prepare(
            'SELECT * FROM admin_users WHERE id = :id' . $activeSql . ' LIMIT 1'
        );
        $st->execute(array(':id' => $targetUserId));
        $row = $st->fetch();
        if (!$row) {
            return array(false, 'المستخدم غير موجود');
        }
        $selfNames = array();
        if (!empty($me['username'])) {
            $selfNames[strtolower(trim((string) $me['username']))] = true;
        }
        if (!empty($me['display_name'])) {
            $selfNames[strtolower(trim((string) $me['display_name']))] = true;
        }
        $rowUser = strtolower(trim((string) (isset($row['username']) ? $row['username'] : '')));
        $rowName = strtolower(trim((string) (isset($row['display_name']) ? $row['display_name'] : '')));
        if (isset($selfNames[$rowUser]) || ($rowName !== '' && isset($selfNames[$rowName]))) {
            return array(false, 'هذا حسابك');
        }
        $role = normalize_admin_role(isset($row['role']) ? $row['role'] : '');
        $tid = isset($row['tenant_id']) ? (int) $row['tenant_id'] : 1;
        $myTid = isset($me['tenant_id']) ? (int) $me['tenant_id'] : 1;
        $isOwner = false;
        if ($tid > 1) {
            try {
                $own = $pdo->prepare('SELECT owner_user_id FROM tenants WHERE id = :t LIMIT 1');
                $own->execute(array(':t' => $tid));
                $isOwner = ((int) $own->fetchColumn() === (int) $row['id']);
            } catch (Exception $e2) {
                $isOwner = false;
            }
        }
        $underMe = false;
        if (($role === 'admin' || $isOwner) && $tid > 1 && $tid !== $myTid && function_exists('portal_agencies_under_current')) {
            foreach (portal_agencies_under_current($pdo, '') as $sib) {
                if ((int) $sib['id'] === (int) $row['id']) {
                    $underMe = true;
                    break;
                }
            }
        }
        if (($role === 'admin' || $isOwner) && $tid > 1 && !$underMe) {
            if ($already || !function_exists('is_super_admin_user') || !is_super_admin_user()) {
                return array(false, 'دخول مستخدم النظام للمدير العام فقط');
            }
        } elseif ($underMe) {
            // وكالة البوابة التابعة لنفس الساس
        } elseif ($role === 'agent' || $role === 'group_manager') {
            if ($tid !== $myTid) {
                return array(false, 'الوكيل من وكالة ثانية');
            }
            if ($mode === 'agency') {
                // أي وكيل داخل نفس الوكالة
            } elseif ($mode === 'parent') {
                $rep = isset($row['reports_to_user_id']) ? (int) $row['reports_to_user_id'] : 0;
                if ($rep !== (int) $me['id']) {
                    return array(false, 'هذا الوكيل مو تحتك');
                }
            } elseif ($mode === 'super' || $already) {
                if (admin_user_child_count($pdo, (int) $row['id'], $tid) < 1) {
                    return array(false, 'الدخول بصفة وكيل فقط إذا عنده وكلاء فرعيين');
                }
            } else {
                return array(false, 'غير مسموح');
            }
        } else {
            return array(false, 'ما يكدر يدخل بهالحساب');
        }
        if (!$already) {
            $_SESSION['admin_real_user_id'] = (int) $me['id'];
            $_SESSION['admin_real_username'] = $me['username'];
            $_SESSION['admin_real_display_name'] = $me['display_name'];
        }
        set_admin_session_from_row($row);
        if (function_exists('app_session_refresh_cookie')) {
            app_session_refresh_cookie();
        }
        if (function_exists('app_session_close')) {
            app_session_close();
        }
        $label = $role === 'admin' ? 'مستخدم النظام' : 'الوكيل';
        return array(true, 'تم الدخول بصفة ' . $label . ' ' . $row['display_name']);
    } catch (Exception $e) {
        return array(false, $e->getMessage());
    }
}

function impersonate_stop($pdo)
{
    if (!is_impersonating()) {
        return array(false, 'ماكو جلسة بديلة');
    }
    $rid = impersonation_real_admin_id();
    try {
        $st = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $rid));
        $row = $st->fetch();
        unset(
            $_SESSION['admin_real_user_id'],
            $_SESSION['admin_real_username'],
            $_SESSION['admin_real_display_name'],
            $_SESSION['admin_sas_shadow']
        );
        impersonate_shadow_cookie_clear();
        if ($row) {
            set_admin_session_from_row($row);
            return array(true, 'رجعت لحسابك');
        }
        return array(false, 'تعذر استرجاع الحساب الأصلي');
    } catch (Exception $e) {
        return array(false, $e->getMessage());
    }
}

function attempt_login($pdo, $config, $username, $password)
{
    $username = trim((string) $username);
    $password = (string) $password;
    if ($password === '') {
        return false;
    }

    try {
        ensure_admin_users_table($pdo, $config);
    } catch (Exception $e) {
    }

    if ($username !== '') {
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM admin_users WHERE username = :u AND is_active = 1 LIMIT 1'
            );
            $stmt->execute(array(':u' => $username));
            $row = $stmt->fetch();
            if ($row && !empty($row['password_hash']) && admin_password_verify($password, $row['password_hash'])) {
                // تحقق اشتراك الشركة (ما عدا السوبر / tenant 1)
                $tid = isset($row['tenant_id']) ? (int) $row['tenant_id'] : 1;
                global $pdo;
                if ($tid > 1 && $pdo && function_exists('saas_mark_expired_tenants')) {
                    try {
                        saas_mark_expired_tenants($pdo);
                    } catch (Exception $e) {
                    }
                }
                if ($tid > 1 && $pdo && function_exists('tenant_subscription_status')) {
                    list($okSub, $code) = tenant_subscription_status($pdo, $tid);
                    if (!$okSub && $code === 'pending') {
                        $GLOBALS['login_block_reason'] = 'pending';
                        return false;
                    }
                    if (!$okSub && $code === 'suspended') {
                        $GLOBALS['login_block_reason'] = 'suspended';
                        return false;
                    }
                    if (!$okSub && $code === 'expired') {
                        $_SESSION['saas_force_billing'] = 1;
                    } else {
                        unset($_SESSION['saas_force_billing']);
                    }
                }
                set_admin_session_from_row($row);
                if (function_exists('app_session_refresh_cookie')) {
                    app_session_refresh_cookie();
                }
                if (function_exists('app_remember_set')) {
                    app_remember_set((int) $row['id'], isset($row['username']) ? $row['username'] : $username);
                }
                return true;
            }
        } catch (Exception $e) {
        }
    }

    if (isset($config['admin_password'])
        && hash_equals((string) $config['admin_password'], $password)
        && ($username === '' || strtolower($username) === 'admin')
    ) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user_id'] = 0;
        $_SESSION['admin_username'] = 'admin';
        $_SESSION['admin_display_name'] = 'Admin';
        $_SESSION['admin_role'] = 'admin';
        unset($_SESSION['ui_prefs']);
        try {
            $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = "admin" LIMIT 1');
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                $pdo->prepare('UPDATE admin_users SET password_hash = :h, role = "admin", updated_at = NOW() WHERE id = :id')
                    ->execute(array(
                        ':h' => admin_password_hash($password),
                        ':id' => (int) $row['id'],
                    ));
                set_admin_session_from_row($row);
                $_SESSION['admin_role'] = 'admin';
            }
        } catch (Exception $e) {
        }
        if (function_exists('app_session_refresh_cookie')) {
            app_session_refresh_cookie();
        }
        if (function_exists('app_remember_set')) {
            app_remember_set(
                isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : 0,
                isset($_SESSION['admin_username']) ? (string) $_SESSION['admin_username'] : 'admin'
            );
        }
        return true;
    }

    return false;
}

function verify_user_password($pdo, $userId, $password)
{
    try {
        $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = :id AND is_active = 1');
        $stmt->execute(array(':id' => (int) $userId));
        $hash = $stmt->fetchColumn();
        if (!$hash) {
            return false;
        }
        return admin_password_verify((string) $password, $hash);
    } catch (Exception $e) {
        return false;
    }
}

function change_user_password($pdo, $userId, $newPassword)
{
    $hash = admin_password_hash((string) $newPassword);
    $pdo->prepare('UPDATE admin_users SET password_hash = :h, updated_at = NOW() WHERE id = :id')
        ->execute(array(':h' => $hash, ':id' => (int) $userId));
}

function admin_user_manage_scope_sql($alias = '')
{
    $p = $alias !== '' ? $alias . '.' : '';
    if (function_exists('is_super_admin_user') && is_super_admin_user()) {
        return '1=1';
    }
    $me = function_exists('current_admin') ? current_admin() : null;
    $id = $me ? (int) $me['id'] : 0;
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($id <= 0) {
        return '1=0';
    }
    if ($me && normalize_admin_role(isset($me['role']) ? $me['role'] : '') === 'admin' && $tid > 1) {
        return $p . 'tenant_id = ' . $tid;
    }
    return '(' . $p . 'id = ' . $id
        . ' OR ' . $p . 'created_by_user_id = ' . $id
        . ' OR ' . $p . 'reports_to_user_id = ' . $id . ') AND ' . $p . 'tenant_id = ' . $tid;
}

function admin_user_in_manage_scope($pdo, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }
    if (function_exists('is_super_admin_user') && is_super_admin_user()) {
        return true;
    }
    try {
        $st = $pdo->query(
            'SELECT id FROM admin_users WHERE id = ' . $userId . ' AND ' . admin_user_manage_scope_sql('') . ' LIMIT 1'
        );
        return (bool) $st->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function list_admin_users($pdo)
{
    try {
        ensure_admin_users_table($pdo);
        $scope = admin_user_manage_scope_sql('');
        return $pdo->query(
            'SELECT id, username, display_name, role, is_active, linked_agent_id, created_at, updated_at, tenant_id, created_by_user_id, reports_to_user_id
             FROM admin_users
             WHERE ' . $scope . '
             ORDER BY id ASC'
        )->fetchAll();
    } catch (Exception $e) {
        try {
            return $pdo->query(
                'SELECT id, username, display_name, role, is_active, created_at, updated_at
                 FROM admin_users ORDER BY id ASC'
            )->fetchAll();
        } catch (Exception $e2) {
            return array();
        }
    }
}

function get_admin_user($pdo, $id)
{
    $st = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => (int) $id));
    $row = $st->fetch();
    return $row ? $row : null;
}

function create_admin_user($pdo, $username, $displayName, $password, $role, $linkedAgentId = null)
{
    $username = trim((string) $username);
    $displayName = trim((string) $displayName);
    $role = normalize_admin_role($role);
    if ($username === '' || $displayName === '' || strlen((string) $password) < 4) {
        return 'invalid';
    }
    if (!preg_match('/^[a-zA-Z0-9._@-]{2,60}$/', $username)) {
        return 'username';
    }
    $exists = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u LIMIT 1');
    $exists->execute(array(':u' => $username));
    if ($exists->fetchColumn()) {
        return 'taken';
    }
    $linkedVal = null;
    if ($role === 'accountant' && $linkedAgentId !== null && (int) $linkedAgentId > 0) {
        $linkedVal = (int) $linkedAgentId;
    }
    $tenantId = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tenantId <= 0) {
        $tenantId = 1;
    }
    $me = function_exists('current_admin') ? current_admin() : null;
    $createdBy = ($me && !empty($me['id'])) ? (int) $me['id'] : null;
    $reportsTo = null;
    if ($createdBy && !(function_exists('is_super_admin_user') && is_super_admin_user())) {
        $reportsTo = $createdBy;
    }
    try {
        $pdo->prepare(
            'INSERT INTO admin_users (username, display_name, password_hash, role, is_active, linked_agent_id, tenant_id, created_by_user_id, reports_to_user_id)
             VALUES (:u, :d, :h, :r, 1, :la, :tid, :cb, :rp)'
        )->execute(array(
            ':u' => $username,
            ':d' => $displayName,
            ':h' => admin_password_hash($password),
            ':r' => $role,
            ':la' => $linkedVal,
            ':tid' => $tenantId,
            ':cb' => $createdBy,
            ':rp' => $reportsTo,
        ));
    } catch (Exception $e) {
        try {
            $pdo->prepare(
                'INSERT INTO admin_users (username, display_name, password_hash, role, is_active, tenant_id)
                 VALUES (:u, :d, :h, :r, 1, :tid)'
            )->execute(array(
                ':u' => $username,
                ':d' => $displayName,
                ':h' => admin_password_hash($password),
                ':r' => $role,
                ':tid' => $tenantId,
            ));
        } catch (Exception $e2) {
            $pdo->prepare(
                'INSERT INTO admin_users (username, display_name, password_hash, role, is_active)
                 VALUES (:u, :d, :h, :r, 1)'
            )->execute(array(
                ':u' => $username,
                ':d' => $displayName,
                ':h' => admin_password_hash($password),
                ':r' => $role,
            ));
        }
    }
    return 'ok';
}

function delete_admin_user($pdo, $id, $currentId)
{
    $id = (int) $id;
    $currentId = (int) $currentId;
    if ($id <= 0 || $id === $currentId) {
        return 'self';
    }
    $admins = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    $row = get_admin_user($pdo, $id);
    if (!$row) {
        return 'missing';
    }
    if (normalize_admin_role($row['role']) === 'admin' && $admins <= 1) {
        return 'last_admin';
    }
    $pdo->prepare('DELETE FROM admin_users WHERE id = :id')->execute(array(':id' => $id));
    return 'ok';
}

function update_admin_user_meta($pdo, $id, $displayName, $role = null, $linkedAgentId = null)
{
    $displayName = trim((string) $displayName);
    if ($displayName === '') {
        return false;
    }
    $uid = (int) $id;
    $linkedVal = null;
    if ($linkedAgentId !== null) {
        $linkedVal = ((int) $linkedAgentId > 0) ? (int) $linkedAgentId : null;
    }
    if ($role !== null) {
        $role = normalize_admin_role($role);
        if ($role !== 'accountant') {
            $linkedVal = null;
        }
        if ($linkedAgentId !== null || $role !== 'accountant') {
            try {
                $pdo->prepare(
                    'UPDATE admin_users SET display_name = :d, role = :r, linked_agent_id = :la, updated_at = NOW() WHERE id = :id'
                )->execute(array(':d' => $displayName, ':r' => $role, ':la' => $linkedVal, ':id' => $uid));
            } catch (Exception $e) {
                $pdo->prepare('UPDATE admin_users SET display_name = :d, role = :r, updated_at = NOW() WHERE id = :id')
                    ->execute(array(':d' => $displayName, ':r' => $role, ':id' => $uid));
            }
        } else {
            $pdo->prepare('UPDATE admin_users SET display_name = :d, role = :r, updated_at = NOW() WHERE id = :id')
                ->execute(array(':d' => $displayName, ':r' => $role, ':id' => $uid));
        }
    } elseif ($linkedAgentId !== null) {
        try {
            $pdo->prepare('UPDATE admin_users SET display_name = :d, linked_agent_id = :la, updated_at = NOW() WHERE id = :id')
                ->execute(array(':d' => $displayName, ':la' => $linkedVal, ':id' => $uid));
        } catch (Exception $e) {
            $pdo->prepare('UPDATE admin_users SET display_name = :d, updated_at = NOW() WHERE id = :id')
                ->execute(array(':d' => $displayName, ':id' => $uid));
        }
    } else {
        $pdo->prepare('UPDATE admin_users SET display_name = :d, updated_at = NOW() WHERE id = :id')
            ->execute(array(':d' => $displayName, ':id' => $uid));
    }
    return true;
}

function count_active_admins($pdo)
{
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function logout()
{
    $_SESSION = array();
    if (function_exists('impersonate_shadow_cookie_clear')) {
        impersonate_shadow_cookie_clear();
    }
    if (function_exists('app_remember_clear')) {
        app_remember_clear();
    }
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }
    session_destroy();
}
