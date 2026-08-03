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
        'grace_days' => 2,
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
    );
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
    return $config;
}
