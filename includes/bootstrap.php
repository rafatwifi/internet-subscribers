<?php

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo 'Config missing';
    exit;
}

$config = require $configFile;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/lang.php';

$settings = settings_load();
$config = apply_settings_to_config($config, $settings);

date_default_timezone_set(isset($config['timezone']) ? $config['timezone'] : 'Asia/Baghdad');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET['lang']) && ($_GET['lang'] === 'ar' || $_GET['lang'] === 'en')) {
    set_lang_preference($_GET['lang']);
}

$lang = current_lang($settings);
$GLOBALS['lang'] = $lang;
$siteName = isset($config['site_name']) ? $config['site_name'] : 'WiFi-Net-SALES';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/system_status.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/whatsapp.php';

$pdo = db_connect($config);

require_once __DIR__ . '/invoices.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/rental.php';

$archivesFile = __DIR__ . '/archives.php';
if (is_file($archivesFile)) {
    require_once $archivesFile;
} else {
    if (!function_exists('ensure_monthly_archives_table')) {
        function ensure_monthly_archives_table($pdo) {}
    }
    if (!function_exists('archive_closed_months')) {
        function archive_closed_months($pdo) {}
    }
    if (!function_exists('compute_month_stats')) {
        function compute_month_stats($pdo, $ym)
        {
            return array(
                'activations' => 0,
                'sales' => 0.0,
                'collected' => 0.0,
                'cost' => 0.0,
                'profit' => 0.0,
                'debt' => 0.0,
            );
        }
    }
    if (!function_exists('list_monthly_archives')) {
        function list_monthly_archives($pdo)
        {
            return array();
        }
    }
    if (!function_exists('get_month_archive')) {
        function get_month_archive($pdo, $ym)
        {
            return null;
        }
    }
}

try {
    ensure_admin_users_table($pdo, $config);
} catch (Exception $e) {
    // لا توقف الموقع إذا فشل إنشاء جدول المستخدمين
} catch (Throwable $e) {
}
try {
    ensure_activity_logs_table($pdo);
} catch (Exception $e) {
} catch (Throwable $e) {
}
try {
    if (function_exists('ensure_monthly_archives_table')) {
        ensure_monthly_archives_table($pdo);
    }
} catch (Exception $e) {
} catch (Throwable $e) {
}
try {
    if (function_exists('ensure_rental_columns')) {
        ensure_rental_columns($pdo);
    }
} catch (Exception $e) {
} catch (Throwable $e) {
}
try {
    if (function_exists('ensure_phone_not_unique')) {
        ensure_phone_not_unique($pdo);
    }
} catch (Exception $e) {
} catch (Throwable $e) {
}
try {
    if (function_exists('ensure_name_unique')) {
        ensure_name_unique($pdo);
    }
} catch (Exception $e) {
} catch (Throwable $e) {
}

// مزامنة الدور/الاسم من قاعدة البيانات للجلسة الحالية
try {
    if (!empty($_SESSION['admin_logged_in']) && !empty($_SESSION['admin_user_id'])) {
        $stRole = $pdo->prepare('SELECT role, display_name, username, is_active FROM admin_users WHERE id = :id LIMIT 1');
        $stRole->execute(array(':id' => (int) $_SESSION['admin_user_id']));
        $liveUser = $stRole->fetch();
        if ($liveUser && (int) $liveUser['is_active'] === 1) {
            $_SESSION['admin_role'] = normalize_admin_role(isset($liveUser['role']) ? $liveUser['role'] : 'staff');
            $_SESSION['admin_display_name'] = $liveUser['display_name'];
            $_SESSION['admin_username'] = $liveUser['username'];
        } elseif ($liveUser && (int) $liveUser['is_active'] !== 1) {
            $_SESSION = array();
        }
    }
} catch (Exception $e) {
} catch (Throwable $e) {
}

// ترقية خفيفة: عمود ترتيب الباقات
try {
    $col = $pdo->query("SHOW COLUMNS FROM service_plans LIKE 'sort_order'")->fetch();
    if (!$col) {
        $pdo->exec('ALTER TABLE service_plans ADD COLUMN sort_order INT NOT NULL DEFAULT 100 AFTER cost_price');
        $pdo->exec('UPDATE service_plans SET sort_order = id * 10');
    }
} catch (Exception $e) {
    // تجاهل إن الجدول غير موجود بعد
}

// ترقية: دين أغراض بدون اشتراك (subscription_id اختياري)
try {
    $col = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'subscription_id'")->fetch();
    if ($col && strtoupper($col['Null']) === 'NO') {
        $fkRows = $pdo->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'invoices'
               AND COLUMN_NAME = 'subscription_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL"
        )->fetchAll();
        foreach ($fkRows as $fk) {
            $pdo->exec('ALTER TABLE invoices DROP FOREIGN KEY `' . str_replace('`', '``', $fk['CONSTRAINT_NAME']) . '`');
        }
        $pdo->exec('ALTER TABLE invoices MODIFY subscription_id INT UNSIGNED NULL');
        try {
            $pdo->exec(
                'ALTER TABLE invoices
                 ADD CONSTRAINT fk_inv_subscription
                 FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE'
            );
        } catch (Exception $e3) {
            // تجاهل إن القيد موجود
        }
    }
} catch (Exception $e) {
    // تجاهل
}
