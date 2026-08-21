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

    // ربط SAS Radius (NBTel / Snono) — عدّل config.php على السيرفر فقط
    'sas' => [
        'enabled' => false,
        'host' => 'reseller.nbtel.iq',
        'username' => 'YOUR_SAS_USERNAME',
        'password' => 'YOUR_SAS_PASSWORD',
        'parent_id' => 1,
        'default_password' => '',
        'activate_units' => 1,
        // طريقة تست 24 ساعة: reward_points أو credit
        'extend_method' => 'reward_points',
        'extend_profile_id' => 0,
        // warn = التفعيل المحلي ينجح + تحذير | rollback = يلغي التفعيل إذا SAS فشل
        'on_failure' => 'warn',
    ],
];
