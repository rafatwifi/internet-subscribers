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
            'subscriptions', 'reports', 'agents', 'cards', 'card_accounting', 'plans',
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
    if (is_accountant_user()) {
        global $pdo;
        $aid = accountant_linked_agent_id();
        if ($aid <= 0) {
            return ' AND 1=0';
        }
        $ids = array($aid);
        if (isset($pdo) && $pdo) {
            $tidAcc = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
            try {
                $stAcc = $pdo->prepare(
                    'SELECT id FROM admin_users WHERE reports_to_user_id = :id AND tenant_id = :t AND role IN ("agent","group_manager")'
                );
                $stAcc->execute(array(':id' => $aid, ':t' => $tidAcc));
                foreach ($stAcc->fetchAll() as $ar) {
                    $ids[] = (int) $ar['id'];
                }
            } catch (Exception $e) {
            }
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
    $mid = $u && !empty($u['sas_manager_id']) ? (int) $u['sas_manager_id'] : 0;
    if ($mid <= 0) {
        return $tenantSql;
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
        if (is_accountant_user()) {
            $aid = accountant_linked_agent_id();
            if ($aid <= 0) {
                return false;
            }
            if ((int) $row['agent_user_id'] === $aid) {
                return true;
            }
            $team = array($aid);
            try {
                $stAcc = $pdo->prepare(
                    'SELECT id FROM admin_users WHERE reports_to_user_id = :id AND tenant_id = :t'
                );
                $stAcc->execute(array(':id' => $aid, ':t' => $tid));
                foreach ($stAcc->fetchAll() as $ar) {
                    $team[] = (int) $ar['id'];
                }
            } catch (Exception $e) {
            }
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
    $perms = role_permissions($role);
    if (in_array($perm, $perms, true)) {
        return true;
    }
    if ($perm === 'activate' && $role === 'accountant') {
        $u = current_admin();
        return $u && !empty($u['can_activate']);
    }
    return false;
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
    $_SESSION['admin_tenant_id'] = isset($row['tenant_id']) ? max(1, (int) $row['tenant_id']) : 1;
    unset($_SESSION['ui_prefs']);
}

/**
 * دخول بصفة وكيل (للأدمن فقط) مع حفظ الجلسة الأصلية.
 */
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
    if ($already) {
        if (!is_admin_user()) {
            return array(false, 'غير مسموح');
        }
    } elseif (!is_admin_user()) {
        return array(false, 'غير مسموح');
    }
    try {
        ensure_admin_users_table($pdo);
        $st = $pdo->prepare(
            'SELECT * FROM admin_users WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $st->execute(array(':id' => $targetUserId));
        $row = $st->fetch();
        if (!$row) {
            return array(false, 'المستخدم غير موجود');
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
        if (($role === 'admin' || $isOwner) && $tid > 1) {
            if ($already || !function_exists('is_super_admin_user') || !is_super_admin_user()) {
                return array(false, 'دخول مستخدم النظام للمدير العام فقط');
            }
        } elseif ($role === 'agent' || $role === 'group_manager') {
            if ($tid !== $myTid && $myTid > 1) {
                return array(false, 'الوكيل من وكالة ثانية');
            }
            if (admin_user_child_count($pdo, (int) $row['id'], $tid) < 1) {
                return array(false, 'الدخول بصفة وكيل فقط إذا عنده وكلاء فرعيين');
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
            $_SESSION['admin_real_display_name']
        );
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
