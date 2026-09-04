<?php

function settings_path()
{
    return __DIR__ . '/../config/settings.json';
}

function settings_defaults()
{
    return array(
        'site_name' => 'WiFi-Net-SALES',
        'language' => 'ar',
        'currency' => 'د.ع',
        'grace_days' => 3,
        'subscription_period_mode' => 'days_30',
        'whatsapp_enabled' => true,
        'whatsapp_provider' => 'local',
        'whatsapp_local_url' => 'http://172.16.16.13:3001',
        'whatsapp_local_key' => 'local-secret-change-me',
        'whatsapp_sender_note' => '',
        'tpl_debt_remind' => 'السلام عليكم {name} يرجى تسديد الديون البالغة {debt} لتجنب قطع الخدمة',
        'tpl_payment_ok' => "مرحباً {name}\nتم استلام مبلغ {amount}\nعن: {month}\nالمتبقي عليك: {remaining}",
        'tpl_debt_created' => "مرحباً {name}\nتم تسجيل دين بمبلغ {amount}\nعن: {month}\n{notes}",
        'tpl_activation' => "مرحباً {name}\nتم تفعيل خدمة الإنترنت ({package})\nمن {from} إلى {to}\nالمبلغ: {amount}",
        'tpl_days_left' => "السلام عليكم {name}\nتبقى لديك {days} يوم على الاشتراك",
        'tpl_unpaid_overdue' => "السلام عليكم {name}\nمضى على تفعيل خطك {days_passed} أيام\nيرجى تسديد الديون البالغة {debt}\nوبعكسه سيتم إيقاف الخدمة",
        'unpaid_remind_after_days' => 7,
        'expiry_auto_remind_enabled' => false,
        'expiry_auto_remind_days' => 1,
        'tpl_expiry_soon' => "السلام عليكم {name}\nتبقى لديك {days} يوم على اشتراك ({package})\nينتهي بتاريخ {to}\nيرجى التجديد لتجنب انقطاع الخدمة",
        'rental_fee' => 5000,
        'rental_devices' => array(
            array('id' => 'powerbeam', 'name' => 'بور بيم', 'icon' => 'PB', 'color' => '#3b82f6'),
            array('id' => 'litebeam', 'name' => 'لايت بيم', 'icon' => 'LB', 'color' => '#30d158'),
            array('id' => 'nanostation', 'name' => 'نانو ستيشن', 'icon' => 'NS', 'color' => '#ff9f0a'),
        ),
        'sas_saved' => false,
        'sas_enabled' => false,
        'sas_host' => 'reseller.nbtel.iq',
        'sas_username' => '',
        'sas_password' => '',
        'sas_parent_id' => 1,
        'sas_default_password' => '',
        'sas_activate_units' => 1,
        'sas_extend_method' => 'reward_points',
        'sas_extend_profile_id' => 0,
        'sas_on_failure' => 'warn',
        'login_bg' => '',
        'login_bg_color' => '#1b2a38',
        'bg_mode' => 'color',
        'brand_icon' => '',
    );
}

function login_uploads_dir()
{
    return dirname(__DIR__) . '/public/uploads';
}

function login_bg_color($settings)
{
    $c = isset($settings['login_bg_color']) ? trim((string) $settings['login_bg_color']) : '';
    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $c)) {
        return '#1b2a38';
    }
    return strtolower($c);
}

function login_bg_filename($settings)
{
    $file = isset($settings['login_bg']) ? basename(str_replace('\\', '/', (string) $settings['login_bg'])) : '';
    if ($file === '' || $file === '.' || $file === '..') {
        return '';
    }
    if (!preg_match('/^login-bg\.(jpe?g|png|gif|webp)$/i', $file)) {
        return '';
    }
    return $file;
}

function login_bg_url($settings)
{
    $file = login_bg_filename($settings);
    if ($file === '') {
        return '';
    }
    $path = login_uploads_dir() . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        return '';
    }
    return 'uploads/' . rawurlencode($file) . '?v=' . (string) filemtime($path);
}

function login_bg_delete_files()
{
    $dir = login_uploads_dir();
    if (!is_dir($dir)) {
        return;
    }
    $list = glob($dir . DIRECTORY_SEPARATOR . 'login-bg.*');
    if (!is_array($list)) {
        return;
    }
    foreach ($list as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }
}

function login_bg_store_upload($fileInfo)
{
    if (!is_array($fileInfo) || empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
        return false;
    }
    if (!empty($fileInfo['error']) && (int) $fileInfo['error'] !== 0) {
        return false;
    }
    if ((int) $fileInfo['size'] > 5 * 1024 * 1024) {
        return false;
    }
    $info = @getimagesize($fileInfo['tmp_name']);
    if (!is_array($info) || empty($info[2])) {
        return false;
    }
    $map = array(
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
    );
    if (defined('IMAGETYPE_WEBP')) {
        $map[IMAGETYPE_WEBP] = 'webp';
    }
    $type = (int) $info[2];
    if (!isset($map[$type])) {
        return false;
    }
    $dir = login_uploads_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    login_bg_delete_files();
    $name = 'login-bg.' . $map[$type];
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!@move_uploaded_file($fileInfo['tmp_name'], $dest)) {
        return false;
    }
    @chmod($dest, 0644);
    return $name;
}

function app_bg_mode($settings)
{
    $m = isset($settings['bg_mode']) ? (string) $settings['bg_mode'] : 'color';
    return $m === 'image' ? 'image' : 'color';
}

function brand_icon_filename($settings)
{
    $file = isset($settings['brand_icon']) ? basename(str_replace('\\', '/', (string) $settings['brand_icon'])) : '';
    if ($file === '' || $file === '.' || $file === '..') {
        return '';
    }
    if (!preg_match('/^brand-icon\.(jpe?g|png|gif|webp)$/i', $file)) {
        return '';
    }
    return $file;
}

function brand_icon_url($settings)
{
    $file = brand_icon_filename($settings);
    if ($file === '') {
        return '';
    }
    $path = login_uploads_dir() . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        return '';
    }
    return 'uploads/' . rawurlencode($file) . '?v=' . (string) filemtime($path);
}

function brand_icon_delete_files()
{
    $dir = login_uploads_dir();
    if (!is_dir($dir)) {
        return;
    }
    $list = glob($dir . DIRECTORY_SEPARATOR . 'brand-icon.*');
    if (!is_array($list)) {
        return;
    }
    foreach ($list as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }
}

function brand_icon_store_upload($fileInfo)
{
    if (!is_array($fileInfo) || empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
        return false;
    }
    if (!empty($fileInfo['error']) && (int) $fileInfo['error'] !== 0) {
        return false;
    }
    if ((int) $fileInfo['size'] > 2 * 1024 * 1024) {
        return false;
    }
    $info = @getimagesize($fileInfo['tmp_name']);
    if (!is_array($info) || empty($info[2])) {
        return false;
    }
    $map = array(
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
    );
    if (defined('IMAGETYPE_WEBP')) {
        $map[IMAGETYPE_WEBP] = 'webp';
    }
    $type = (int) $info[2];
    if (!isset($map[$type])) {
        return false;
    }
    $dir = login_uploads_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    brand_icon_delete_files();
    $name = 'brand-icon.' . $map[$type];
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!@move_uploaded_file($fileInfo['tmp_name'], $dest)) {
        return false;
    }
    @chmod($dest, 0644);
    return $name;
}

function settings_load()
{
    $defaults = settings_defaults();
    $path = settings_path();
    if (!is_file($path)) {
        return $defaults;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }
    return array_merge($defaults, $data);
}

function settings_save($data)
{
    $current = settings_load();
    $merged = array_merge($current, $data);
    $path = settings_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return file_put_contents($path, $json) !== false;
}

function apply_settings_to_config($config, $settings)
{
    if (!is_array($config)) {
        $config = array();
    }
    $config['site_name'] = $settings['site_name'];
    $config['currency'] = $settings['currency'];
    $config['grace_days'] = (int) $settings['grace_days'];
    $config['subscription_period_mode'] = (isset($settings['subscription_period_mode'])
        && $settings['subscription_period_mode'] === 'calendar_month')
        ? 'calendar_month'
        : 'days_30';
    if (!isset($config['whatsapp']) || !is_array($config['whatsapp'])) {
        $config['whatsapp'] = array();
    }
    $config['whatsapp']['enabled'] = !empty($settings['whatsapp_enabled']);
    $config['whatsapp']['provider'] = $settings['whatsapp_provider'];
    $config['whatsapp']['local_url'] = $settings['whatsapp_local_url'];
    $config['whatsapp']['local_key'] = $settings['whatsapp_local_key'];
    $config['whatsapp']['sender_note'] = $settings['whatsapp_sender_note'];
    $config['templates'] = array(
        'debt_remind' => isset($settings['tpl_debt_remind']) ? $settings['tpl_debt_remind'] : '',
        'payment_ok' => isset($settings['tpl_payment_ok']) ? $settings['tpl_payment_ok'] : '',
        'debt_created' => isset($settings['tpl_debt_created']) ? $settings['tpl_debt_created'] : '',
        'activation' => isset($settings['tpl_activation']) ? $settings['tpl_activation'] : '',
        'days_left' => isset($settings['tpl_days_left']) ? $settings['tpl_days_left'] : '',
        'unpaid_overdue' => isset($settings['tpl_unpaid_overdue']) ? $settings['tpl_unpaid_overdue'] : '',
        'expiry_soon' => isset($settings['tpl_expiry_soon']) ? $settings['tpl_expiry_soon'] : '',
    );
    $config['unpaid_remind_after_days'] = isset($settings['unpaid_remind_after_days'])
        ? max(1, (int) $settings['unpaid_remind_after_days'])
        : 7;
    $config['expiry_auto_remind_enabled'] = !empty($settings['expiry_auto_remind_enabled']);
    $config['expiry_auto_remind_days'] = isset($settings['expiry_auto_remind_days'])
        ? max(0, (int) $settings['expiry_auto_remind_days'])
        : 1;

    if (!isset($config['sas']) || !is_array($config['sas'])) {
        $config['sas'] = array();
    }
    if (!empty($settings['sas_saved'])) {
        $config['sas']['enabled'] = !empty($settings['sas_enabled']);
        $config['sas']['host'] = isset($settings['sas_host']) ? trim((string) $settings['sas_host']) : '';
        $config['sas']['username'] = isset($settings['sas_username']) ? trim((string) $settings['sas_username']) : '';
        if (isset($settings['sas_password']) && (string) $settings['sas_password'] !== '') {
            $config['sas']['password'] = (string) $settings['sas_password'];
        }
        $config['sas']['parent_id'] = isset($settings['sas_parent_id']) ? (int) $settings['sas_parent_id'] : 1;
        $config['sas']['default_password'] = isset($settings['sas_default_password'])
            ? (string) $settings['sas_default_password']
            : '';
        $config['sas']['activate_units'] = isset($settings['sas_activate_units'])
            ? max(1, (int) $settings['sas_activate_units'])
            : 1;
        $config['sas']['extend_method'] = (isset($settings['sas_extend_method']) && $settings['sas_extend_method'] === 'credit')
            ? 'credit'
            : 'reward_points';
        $config['sas']['extend_profile_id'] = isset($settings['sas_extend_profile_id'])
            ? (int) $settings['sas_extend_profile_id']
            : 0;
        $config['sas']['on_failure'] = (isset($settings['sas_on_failure']) && $settings['sas_on_failure'] === 'rollback')
            ? 'rollback'
            : 'warn';
    }

    return $config;
}
