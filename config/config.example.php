<?php
/**
 * انسخ هذا الملف إلى config.php وعدّل القيم
 * cp config/config.example.php config/config.php
 */

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'internet_subs',
        'user' => 'subs_user',
        'pass' => 'CHANGE_THIS_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    'admin_password' => 'admin123',

    // provider: local = واتساب اللابتوب/الموبايل عبر البوابة المجانية
    // provider: meta  = WhatsApp Cloud API الرسمي
    'whatsapp' => [
        'enabled' => true,
        'provider' => 'local',
        'local_url' => 'http://127.0.0.1:3001',
        'local_key' => 'local-secret-change-me',

        // إعدادات Meta (إذا رجعت للرسمي)
        'token' => 'YOUR_ACCESS_TOKEN',
        'phone_number_id' => 'YOUR_PHONE_NUMBER_ID',
        'api_version' => 'v21.0',
        'reminder_template' => '',
        'activation_template' => '',
    ],

    'grace_days' => 2,
    'cron_secret' => 'CHANGE_CRON_SECRET',
    'currency' => 'د.ع',
    'timezone' => 'Asia/Baghdad',
];
