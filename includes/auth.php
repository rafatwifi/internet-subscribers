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
    return array('admin', 'manager', 'staff', 'agent');
}

function admin_role_label($role, $lang = null)
{
    if ($lang === null) {
        $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
    }
    $map = array(
        'admin' => array('ar' => 'مدير', 'en' => 'Admin'),
        'manager' => array('ar' => 'مشرف', 'en' => 'Manager'),
        'staff' => array('ar' => 'موظف', 'en' => 'Staff'),
        'agent' => array('ar' => 'وكيل', 'en' => 'Agent'),
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
    if ($role === 'manager') {
        return $lang === 'en'
            ? 'Daily work + reports + log (no settings/users)'
            : 'الشغل اليومي + تقارير + لوك (بدون إعدادات/مستخدمين)';
    }
    if ($role === 'agent') {
        return $lang === 'en'
            ? 'Only own subscribers + messages (no settings)'
            : 'مشتركيه فقط + رسائل (بدون إعدادات النظام)';
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
            'subscriptions', 'reports', 'logs', 'plans',
            'settings', 'users', 'agents', 'backup', 'clear_data',
        );
    }
    if ($role === 'manager') {
        return array(
            'dashboard', 'subscribers', 'activate', 'debts', 'messages', 'rentals',
            'subscriptions', 'reports', 'logs', 'agents',
        );
    }
    if ($role === 'agent') {
        return array(
            'dashboard', 'subscribers', 'activate', 'debts', 'messages', 'rentals',
        );
    }
    return array(
        'dashboard', 'subscribers', 'activate', 'debts', 'messages', 'rentals',
    );
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
        $sql = "SELECT id, username, display_name, role, is_active, created_at, updated_at
                FROM admin_users WHERE role = 'agent'";
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY display_name ASC, id ASC';
        return $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        return array();
    }
}

function subscriber_agent_scope_sql($alias = 's')
{
    if (!is_agent_user()) {
        return '';
    }
    $u = current_admin();
    $id = $u ? (int) $u['id'] : 0;
    if ($id <= 0) {
        return ' AND 1=0';
    }
    return ' AND ' . $alias . '.agent_user_id = ' . $id;
}

function user_can_access_subscriber($pdo, $subscriberId)
{
    $subscriberId = (int) $subscriberId;
    if ($subscriberId <= 0) {
        return false;
    }
    if (!is_agent_user()) {
        return true;
    }
    $u = current_admin();
    $uid = $u ? (int) $u['id'] : 0;
    if ($uid <= 0) {
        return false;
    }
    try {
        ensure_subscriber_agent_column($pdo);
        $st = $pdo->prepare('SELECT agent_user_id FROM subscribers WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $subscriberId));
        $aid = $st->fetchColumn();
        return $aid !== false && (int) $aid === $uid;
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
    return in_array($perm, $perms, true);
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
    );
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
    unset($_SESSION['ui_prefs']);
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
                set_admin_session_from_row($row);
                if (function_exists('app_session_refresh_cookie')) {
                    app_session_refresh_cookie();
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

function list_admin_users($pdo)
{
    try {
        ensure_admin_users_table($pdo);
        return $pdo->query(
            'SELECT id, username, display_name, role, is_active, created_at, updated_at
             FROM admin_users
             ORDER BY id ASC'
        )->fetchAll();
    } catch (Exception $e) {
        return array();
    }
}

function get_admin_user($pdo, $id)
{
    $st = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => (int) $id));
    $row = $st->fetch();
    return $row ? $row : null;
}

function create_admin_user($pdo, $username, $displayName, $password, $role)
{
    $username = trim((string) $username);
    $displayName = trim((string) $displayName);
    $role = normalize_admin_role($role);
    if ($username === '' || $displayName === '' || strlen((string) $password) < 4) {
        return 'invalid';
    }
    if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $username)) {
        return 'username';
    }
    $exists = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u LIMIT 1');
    $exists->execute(array(':u' => $username));
    if ($exists->fetchColumn()) {
        return 'taken';
    }
    $pdo->prepare(
        'INSERT INTO admin_users (username, display_name, password_hash, role, is_active)
         VALUES (:u, :d, :h, :r, 1)'
    )->execute(array(
        ':u' => $username,
        ':d' => $displayName,
        ':h' => admin_password_hash($password),
        ':r' => $role,
    ));
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

function update_admin_user_meta($pdo, $id, $displayName, $role = null)
{
    $displayName = trim((string) $displayName);
    if ($displayName === '') {
        return false;
    }
    if ($role !== null) {
        $role = normalize_admin_role($role);
        $pdo->prepare('UPDATE admin_users SET display_name = :d, role = :r, updated_at = NOW() WHERE id = :id')
            ->execute(array(':d' => $displayName, ':r' => $role, ':id' => (int) $id));
    } else {
        $pdo->prepare('UPDATE admin_users SET display_name = :d, updated_at = NOW() WHERE id = :id')
            ->execute(array(':d' => $displayName, ':id' => (int) $id));
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
