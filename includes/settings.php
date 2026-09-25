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
        'tpl_activation_credit' => "مرحباً {name}\nتم تفعيل خدمة الإنترنت ({package}) بالآجل\nمن {from} إلى {to}\nالمبلغ المستحق: {amount}\nيمكنك التسديد عبر الماستر كارد",
        'tpl_activation_debts' => "تنويه: عليك ديون سابقة بمبلغ {debt}\nالتفاصيل:\n{notes}",
        'tpl_activation_credit_debts' => "مرحباً {name}\nتم تفعيل خدمة الإنترنت ({package}) بالآجل\nمن {from} إلى {to}\nالمبلغ المستحق لهذا التفعيل: {amount}\nوعليك ديون سابقة بمبلغ {debt}\nالتفاصيل:\n{notes}\nيمكنك تسديد الكل عبر الماستر كارد",
        'tpl_days_left' => "السلام عليكم {name}\nتبقى لديك {days} يوم على الاشتراك",
        'tpl_unpaid_overdue' => "السلام عليكم {name}\nمضى على تفعيل خطك {days_passed} أيام\nيرجى تسديد الديون البالغة {debt}\nوبعكسه سيتم إيقاف الخدمة",
        'unpaid_remind_after_days' => 7,
        'unpaid_remind_enabled' => false,
        'wa_case_unpaid_overdue' => 'unpaid_overdue',
        'expiry_auto_remind_enabled' => false,
        'expiry_auto_remind_days' => 1,
        'tpl_expiry_soon' => "السلام عليكم {name}\nتبقى لديك {days} يوم على اشتراك ({package})\nينتهي بتاريخ {to}\nيرجى التجديد لتجنب انقطاع الخدمة",
        'tpl_schedule_cut' => "السلام عليكم {name}\nتم قطع الإنترنت بسبب عدم تسديد الديون غير المسددة والبالغة {debt}\nبعد تجاوز أيام السماح ({grace} يوم).\nيرجى التسديد لإعادة الخدمة.",
        'wa_case_activation_cash' => 'activation',
        'wa_case_activation_credit' => 'activation_credit',
        'wa_case_activation_debts' => 'activation_debts',
        'wa_case_activation_credit_debts' => 'activation_credit_debts',
        'wa_case_debt_created' => 'debt_created',
        'wa_case_payment_ok' => 'payment_ok',
        'wa_case_debt_remind' => 'debt_remind',
        'wa_case_days_left' => 'days_left',
        'wa_case_expiry_soon' => 'expiry_soon',
        'wa_case_reminder_auto' => 'debt_remind',
        'wa_case_schedule_cut' => 'schedule_cut',
        'schedule_cut_enabled' => false,
        'schedule_cut_send_wa' => true,
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
        'sas_default_password' => '1234',
        'sas_activate_units' => 1,
        'sas_extend_method' => 'reward_points',
        'sas_extend_profile_id' => 0,
        'sas_on_failure' => 'warn',
        'cpe_http_user' => 'ubnt',
        'cpe_http_pass' => 'ubnt',
        'cpe_use_https' => true,
        'login_bg' => '',
        'login_bg_color' => '#1b2a38',
        'bg_mode' => 'color',
        'brand_icon' => '',
        'login_session_days' => 3,
        'maint_block_activate' => false,
        'maint_block_give_test' => false,
        'app_update_note' => '',
        'app_update_file' => '',
        'app_update_at' => '',
        'saas_registration_enabled' => true,
        'saas_trial_days' => 7,
        'saas_plans' => array(
            'monthly' => array('label' => 'شهري', 'days' => 30, 'amount' => 25000),
            'yearly' => array('label' => 'سنوي', 'days' => 365, 'amount' => 250000),
        ),
        'zaincash_merchant_id' => '',
        'zaincash_secret' => '',
        'zaincash_msisdn' => '',
        'zaincash_production' => false,
        'zaincash_redirect_base' => '',
        'gdrive_client_id' => '',
        'gdrive_client_secret' => '',
        'gdrive_refresh_token' => '',
        'gdrive_folder_id' => '',
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

function login_bg_store_upload($fileInfo, &$failReason = null)
{
    $failReason = '';
    if (!is_array($fileInfo) || empty($fileInfo['tmp_name'])) {
        $failReason = 'no_file';
        return false;
    }
    if (!is_uploaded_file($fileInfo['tmp_name'])) {
        $failReason = 'upload';
        return false;
    }
    if (!empty($fileInfo['error']) && (int) $fileInfo['error'] !== 0) {
        $failReason = 'php_' . (int) $fileInfo['error'];
        return false;
    }
    if ((int) $fileInfo['size'] > 8 * 1024 * 1024) {
        $failReason = 'size';
        return false;
    }
    $info = @getimagesize($fileInfo['tmp_name']);
    $map = array(
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
    );
    if (defined('IMAGETYPE_WEBP')) {
        $map[IMAGETYPE_WEBP] = 'webp';
    }
    $ext = '';
    if (is_array($info) && !empty($info[2]) && isset($map[(int) $info[2]])) {
        $ext = $map[(int) $info[2]];
    } else {
        $orig = isset($fileInfo['name']) ? strtolower((string) $fileInfo['name']) : '';
        if (preg_match('/\.(jpe?g)$/', $orig)) {
            $ext = 'jpg';
        } elseif (preg_match('/\.png$/', $orig)) {
            $ext = 'png';
        } elseif (preg_match('/\.gif$/', $orig)) {
            $ext = 'gif';
        } elseif (preg_match('/\.webp$/', $orig)) {
            $ext = 'webp';
        }
    }
    if ($ext === '') {
        $failReason = 'type';
        return false;
    }
    $dir = login_uploads_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $failReason = 'mkdir';
        return false;
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0755);
    }
    if (!is_writable($dir)) {
        $failReason = 'writable';
        return false;
    }
    login_bg_delete_files();
    $name = 'login-bg.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!@move_uploaded_file($fileInfo['tmp_name'], $dest)) {
        if (!@copy($fileInfo['tmp_name'], $dest)) {
            $failReason = 'move';
            return false;
        }
        @unlink($fileInfo['tmp_name']);
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

function brand_icon_store_upload($fileInfo, &$failReason = null)
{
    $failReason = '';
    if (!is_array($fileInfo) || empty($fileInfo['tmp_name'])) {
        $failReason = 'no_file';
        return false;
    }
    if (!is_uploaded_file($fileInfo['tmp_name'])) {
        $failReason = 'upload';
        return false;
    }
    if (!empty($fileInfo['error']) && (int) $fileInfo['error'] !== 0) {
        $failReason = 'php_' . (int) $fileInfo['error'];
        return false;
    }
    if ((int) $fileInfo['size'] > 4 * 1024 * 1024) {
        $failReason = 'size';
        return false;
    }
    $info = @getimagesize($fileInfo['tmp_name']);
    $map = array(
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
    );
    if (defined('IMAGETYPE_WEBP')) {
        $map[IMAGETYPE_WEBP] = 'webp';
    }
    $ext = '';
    if (is_array($info) && !empty($info[2]) && isset($map[(int) $info[2]])) {
        $ext = $map[(int) $info[2]];
    } else {
        $orig = isset($fileInfo['name']) ? strtolower((string) $fileInfo['name']) : '';
        if (preg_match('/\.(jpe?g)$/', $orig)) {
            $ext = 'jpg';
        } elseif (preg_match('/\.png$/', $orig)) {
            $ext = 'png';
        } elseif (preg_match('/\.gif$/', $orig)) {
            $ext = 'gif';
        } elseif (preg_match('/\.webp$/', $orig)) {
            $ext = 'webp';
        }
    }
    if ($ext === '') {
        $failReason = 'type';
        return false;
    }
    $dir = login_uploads_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $failReason = 'mkdir';
        return false;
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0755);
    }
    if (!is_writable($dir)) {
        $failReason = 'writable';
        return false;
    }
    brand_icon_delete_files();
    $name = 'brand-icon.' . $ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $name;
    if (!@move_uploaded_file($fileInfo['tmp_name'], $dest)) {
        if (!@copy($fileInfo['tmp_name'], $dest)) {
            $failReason = 'move';
            return false;
        }
        @unlink($fileInfo['tmp_name']);
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
    // Nested catalog must replace wholly when provided (not deep-merge leftovers).
    if (array_key_exists('wa_templates', $data)) {
        $merged['wa_templates'] = is_array($data['wa_templates']) ? $data['wa_templates'] : array();
    }
    $path = settings_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $flags = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) {
        $flags |= JSON_UNESCAPED_UNICODE;
    }
    if (defined('JSON_PRETTY_PRINT')) {
        $flags |= JSON_PRETTY_PRINT;
    }
    $json = json_encode($merged, $flags);
    return file_put_contents($path, $json) !== false;
}

/**
 * When a legacy tpl_* field is saved elsewhere, keep wa_templates in sync if present.
 */
function wa_patch_catalog_body($settings, $tplKey, $body, $label = '')
{
    if (!is_array($settings)) {
        $settings = array();
    }
    $tplKey = wa_sanitize_tpl_key($tplKey);
    if ($tplKey === '') {
        return $settings;
    }
    if (empty($settings['wa_templates']) || !is_array($settings['wa_templates'])) {
        return $settings;
    }
    $labels = wa_default_template_labels(isset($settings['language']) ? $settings['language'] : 'ar');
    if (!isset($settings['wa_templates'][$tplKey]) || !is_array($settings['wa_templates'][$tplKey])) {
        $settings['wa_templates'][$tplKey] = array(
            'label' => $label !== '' ? $label : (isset($labels[$tplKey]) ? $labels[$tplKey] : $tplKey),
            'body' => (string) $body,
        );
    } else {
        $settings['wa_templates'][$tplKey]['body'] = (string) $body;
        if ($label !== '') {
            $settings['wa_templates'][$tplKey]['label'] = $label;
        }
    }
    return $settings;
}

function wa_sanitize_tpl_key($key)
{
    $key = strtolower(trim((string) $key));
    $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
    $key = preg_replace('/_+/', '_', $key);
    $key = trim($key, '_');
    if ($key === '') {
        return '';
    }
    if (strlen($key) > 48) {
        $key = substr($key, 0, 48);
        $key = rtrim($key, '_');
    }
    return $key;
}

function wa_legacy_tpl_field_map()
{
    return array(
        'activation' => 'tpl_activation',
        'activation_credit' => 'tpl_activation_credit',
        'activation_debts' => 'tpl_activation_debts',
        'activation_credit_debts' => 'tpl_activation_credit_debts',
        'debt_created' => 'tpl_debt_created',
        'payment_ok' => 'tpl_payment_ok',
        'debt_remind' => 'tpl_debt_remind',
        'days_left' => 'tpl_days_left',
        'unpaid_overdue' => 'tpl_unpaid_overdue',
        'expiry_soon' => 'tpl_expiry_soon',
        'schedule_cut' => 'tpl_schedule_cut',
    );
}

function wa_default_template_labels($lang = 'ar')
{
    $en = ($lang === 'en');
    return array(
        'activation' => $en ? 'Cash activation' : 'تفعيل نقدي',
        'activation_credit' => $en ? 'Credit activation' : 'تفعيل آجل',
        'activation_debts' => $en ? 'Prior-debts appendix' : 'ملحق ديون سابقة',
        'activation_credit_debts' => $en ? 'Credit + old debts (one message)' : 'تفعيل آجل + ديون قديمة',
        'debt_created' => $en ? 'Debt added' : 'إضافة دين',
        'payment_ok' => $en ? 'Payment confirmed' : 'تأكيد التسديد',
        'debt_remind' => $en ? 'Debt reminder' : 'تذكير بالدين',
        'days_left' => $en ? 'Days left (manual)' : 'أيام متبقية (يدوي)',
        'expiry_soon' => $en ? 'Expiry soon (auto)' : 'قرب الانتهاء (تلقائي)',
        'unpaid_overdue' => $en ? 'Late after activation' : 'تأخير الدفع بعد التفعيل',
        'schedule_cut' => $en ? 'Cut after grace' : 'قطع بعد انتهاء السماح',
    );
}

/**
 * Build dynamic template catalog from settings.
 * Returns: key => array('label' => ..., 'body' => ..., 'builtin' => bool)
 */
function wa_build_templates_catalog($settings, $lang = 'ar')
{
    if (!is_array($settings)) {
        $settings = array();
    }
    $labels = wa_default_template_labels($lang);
    $legacy = wa_legacy_tpl_field_map();
    $out = array();
    $hasCatalog = !empty($settings['wa_templates']) && is_array($settings['wa_templates']);

    if ($hasCatalog) {
        foreach ($settings['wa_templates'] as $rawKey => $row) {
            $key = wa_sanitize_tpl_key($rawKey);
            if ($key === '') {
                continue;
            }
            if (is_string($row)) {
                $body = $row;
                $label = isset($labels[$key]) ? $labels[$key] : $key;
            } elseif (is_array($row)) {
                $body = isset($row['body']) ? (string) $row['body'] : '';
                $label = isset($row['label']) ? trim((string) $row['label']) : '';
                if ($label === '') {
                    $label = isset($labels[$key]) ? $labels[$key] : $key;
                }
            } else {
                continue;
            }
            $out[$key] = array(
                'label' => $label,
                'body' => $body,
                'builtin' => isset($legacy[$key]),
            );
        }
        return $out;
    }

    // First-time / legacy installs: seed from tpl_* fields.
    foreach ($legacy as $key => $field) {
        $body = isset($settings[$field]) ? (string) $settings[$field] : '';
        $out[$key] = array(
            'label' => isset($labels[$key]) ? $labels[$key] : $key,
            'body' => $body,
            'builtin' => true,
        );
    }

    return $out;
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
    $langTpl = isset($settings['language']) ? (string) $settings['language'] : 'ar';
    $catalog = wa_build_templates_catalog($settings, $langTpl);
    $config['templates'] = array();
    $config['template_labels'] = array();
    $config['wa_templates'] = array();
    foreach ($catalog as $tKey => $tRow) {
        $config['templates'][$tKey] = isset($tRow['body']) ? (string) $tRow['body'] : '';
        $config['template_labels'][$tKey] = isset($tRow['label']) ? (string) $tRow['label'] : $tKey;
        $config['wa_templates'][$tKey] = array(
            'label' => $config['template_labels'][$tKey],
            'body' => $config['templates'][$tKey],
            'builtin' => !empty($tRow['builtin']),
        );
    }
    // Soft fallbacks for older installs that left credit/debts empty (runtime send only).
    if (isset($config['templates']['activation_credit']) && trim((string) $config['templates']['activation_credit']) === ''
        && isset($config['templates']['activation'])) {
        $config['templates']['activation_credit'] = $config['templates']['activation'];
    }
    if (isset($config['templates']['activation_debts']) && trim((string) $config['templates']['activation_debts']) === ''
        && isset($config['templates']['debt_remind'])) {
        $config['templates']['activation_debts'] = $config['templates']['debt_remind'];
    }
    // لا تدمج قالب الآجل + ملحق الديون تلقائياً (رسالة واحدة منفصلة)
    if (!isset($config['templates']['activation_credit_debts'])
        || trim((string) $config['templates']['activation_credit_debts']) === '') {
        $config['templates']['activation_credit_debts'] =
            "مرحباً {name}\nتم تفعيل خدمة الإنترنت ({package}) بالآجل\nمن {from} إلى {to}\nالمبلغ المستحق لهذا التفعيل: {amount}\nوعليك ديون سابقة بمبلغ {debt}\nالتفاصيل:\n{notes}\nيمكنك تسديد الكل عبر الماستر كارد";
        if (empty($config['template_labels']['activation_credit_debts'])) {
            $labs = wa_default_template_labels($langTpl);
            $config['template_labels']['activation_credit_debts'] = isset($labs['activation_credit_debts'])
                ? $labs['activation_credit_debts']
                : 'تفعيل آجل + ديون قديمة';
        }
    }
    $tplKeys = array_keys($config['templates']);
    $legacyAct = isset($settings['wa_case_activation']) ? trim((string) $settings['wa_case_activation']) : 'activation';
    if ($legacyAct === '' || !in_array($legacyAct, $tplKeys, true)) {
        $legacyAct = in_array('activation', $tplKeys, true) ? 'activation' : (isset($tplKeys[0]) ? $tplKeys[0] : '');
    }
    $caseDefaults = array(
        'activation_cash' => $legacyAct,
        'activation_credit' => 'activation_credit',
        'activation_debts' => 'activation_debts',
        'activation_credit_debts' => 'activation_credit_debts',
        'debt_created' => 'debt_created',
        'payment_ok' => 'payment_ok',
        'debt_remind' => 'debt_remind',
        'days_left' => 'days_left',
        'expiry_soon' => 'expiry_soon',
        'unpaid_overdue' => 'unpaid_overdue',
        'reminder_auto' => 'debt_remind',
        'schedule_cut' => 'schedule_cut',
    );
    $config['wa_cases'] = array();
    $config['wa_case_issues'] = array();
    foreach ($caseDefaults as $case => $def) {
        $raw = isset($settings['wa_case_' . $case]) ? trim((string) $settings['wa_case_' . $case]) : $def;
        if ($raw === '__none__') {
            $raw = '';
        }
        if ($raw !== '' && !in_array($raw, $tplKeys, true)) {
            // Deleted template still referenced.
            $config['wa_case_issues'][$case] = 'missing_template';
            $raw = in_array($def, $tplKeys, true) ? $def : (isset($tplKeys[0]) ? $tplKeys[0] : '');
        }
        if ($raw === '') {
            $config['wa_case_issues'][$case] = 'unassigned';
            // Runtime fallback so sends do not hard-fail; UI still warns.
            $raw = in_array($def, $tplKeys, true) ? $def : (isset($tplKeys[0]) ? $tplKeys[0] : '');
        } elseif (isset($config['templates'][$raw]) && trim((string) $config['templates'][$raw]) === '') {
            $config['wa_case_issues'][$case] = 'empty_body';
        }
        $config['wa_cases'][$case] = $raw;
    }
    // توافق قديم: activation = نقدي
    $config['wa_cases']['activation'] = $config['wa_cases']['activation_cash'];
    if (isset($config['wa_case_issues']['activation_cash'])) {
        $config['wa_case_issues']['activation'] = $config['wa_case_issues']['activation_cash'];
    }
    $config['unpaid_remind_after_days'] = isset($settings['unpaid_remind_after_days'])
        ? max(1, (int) $settings['unpaid_remind_after_days'])
        : 7;
    $config['unpaid_remind_enabled'] = !empty($settings['unpaid_remind_enabled']);
    $config['expiry_auto_remind_enabled'] = !empty($settings['expiry_auto_remind_enabled']);
    $config['expiry_auto_remind_days'] = isset($settings['expiry_auto_remind_days'])
        ? max(0, (int) $settings['expiry_auto_remind_days'])
        : 1;
    $config['schedule_cut_enabled'] = !empty($settings['schedule_cut_enabled']);
    $config['schedule_cut_send_wa'] = !isset($settings['schedule_cut_send_wa'])
        || !empty($settings['schedule_cut_send_wa']);

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
        $config['sas']['default_password'] = (isset($settings['sas_default_password']) && (string) $settings['sas_default_password'] !== '')
            ? (string) $settings['sas_default_password']
            : '1234';
        $config['sas']['activate_units'] = isset($settings['sas_activate_units'])
            ? max(0, (int) $settings['sas_activate_units'])
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
    $config['cpe_http_user'] = isset($settings['cpe_http_user']) && trim((string) $settings['cpe_http_user']) !== ''
        ? trim((string) $settings['cpe_http_user'])
        : 'ubnt';
    $config['cpe_http_pass'] = isset($settings['cpe_http_pass'])
        ? (string) $settings['cpe_http_pass']
        : 'ubnt';
    $config['cpe_use_https'] = !isset($settings['cpe_use_https']) || !empty($settings['cpe_use_https']);
    $config['login_session_days'] = isset($settings['login_session_days'])
        ? max(1, min(30, (int) $settings['login_session_days']))
        : 3;
    $config['maint_block_activate'] = !empty($settings['maint_block_activate']);
    $config['maint_block_give_test'] = !empty($settings['maint_block_give_test']);

    return $config;
}

/**
 * صيانة النظام: منع التفعيل أو إعطاء يوم تجريبي.
 * $action: activate | give_test
 */
function app_maintenance_blocks($action, $config = null)
{
    if ($config === null) {
        $config = isset($GLOBALS['config']) ? $GLOBALS['config'] : array();
    }
    if ($action === 'activate' && !empty($config['maint_block_activate'])) {
        return true;
    }
    if ($action === 'give_test' && !empty($config['maint_block_give_test'])) {
        return true;
    }
    return false;
}

function app_maintenance_message($action, $lang = 'ar')
{
    $en = ($lang === 'en');
    if ($action === 'activate') {
        return $en
            ? 'Activation is paused for system maintenance.'
            : 'التفعيل متوقف حالياً لصيانة النظام.';
    }
    return $en
        ? 'Give-1-day is paused for system maintenance.'
        : 'إعطاء يوم واحد متوقف حالياً لصيانة النظام.';
}

function app_updates_dir()
{
    return dirname(__DIR__) . '/storage/updates';
}

function app_update_pending($settings = null)
{
    if ($settings === null) {
        $settings = function_exists('settings_load') ? settings_load() : array();
    }
    $file = isset($settings['app_update_file']) ? trim((string) $settings['app_update_file']) : '';
    if ($file === '') {
        return null;
    }
    $path = app_updates_dir() . '/' . basename($file);
    if (!is_file($path)) {
        return null;
    }
    return array(
        'file' => basename($file),
        'path' => $path,
        'note' => isset($settings['app_update_note']) ? (string) $settings['app_update_note'] : '',
        'at' => isset($settings['app_update_at']) ? (string) $settings['app_update_at'] : '',
    );
}

/**
 * تطبيق حزمة تحديث ZIP (مسارات مسموحة فقط — لا يمسّ config/config.php).
 * @return array [ok, message]
 */
function app_update_apply($zipPath)
{
    $zipPath = (string) $zipPath;
    if ($zipPath === '' || !is_file($zipPath)) {
        return array(false, 'ملف التحديث غير موجود');
    }
    if (!class_exists('ZipArchive')) {
        return array(false, 'ZipArchive غير متاح على السيرفر');
    }
    $root = dirname(__DIR__);
    $allowedPrefixes = array('includes/', 'public/', 'cron/', 'whatsapp-gateway/');
    $blocked = array(
        'config/config.php',
        'public/uploads/',
        'storage/settings.json',
    );
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return array(false, 'تعذر فتح ملف ZIP');
    }
    $copied = 0;
    $skipped = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
        $name = ltrim($name, '/');
        if ($name === '' || substr($name, -1) === '/') {
            continue;
        }
        if (strpos($name, '..') !== false) {
            $skipped++;
            continue;
        }
        $okPrefix = false;
        foreach ($allowedPrefixes as $p) {
            if (strpos($name, $p) === 0) {
                $okPrefix = true;
                break;
            }
        }
        if (!$okPrefix) {
            $skipped++;
            continue;
        }
        $blockedHit = false;
        foreach ($blocked as $b) {
            if ($name === $b || strpos($name, $b) === 0) {
                $blockedHit = true;
                break;
            }
        }
        if ($blockedHit) {
            $skipped++;
            continue;
        }
        $dest = $root . '/' . $name;
        $dir = dirname($dest);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $stream = $zip->getStream($name);
        if (!$stream) {
            $skipped++;
            continue;
        }
        $data = stream_get_contents($stream);
        fclose($stream);
        if ($data === false) {
            $skipped++;
            continue;
        }
        if (@file_put_contents($dest, $data) === false) {
            $skipped++;
            continue;
        }
        $copied++;
    }
    $zip->close();
    if ($copied <= 0) {
        return array(false, 'ما انسخ أي ملف (تحقق من بنية الـ ZIP)');
    }
    return array(true, 'تم تطبيق التحديث: ' . $copied . ' ملف' . ($skipped ? (' — تخطي ' . $skipped) : ''));
}

/**
 * حفظ رفع تحديث رسمي.
 * @return array [ok, message, filename]
 */
function app_update_store_upload($fileInfo, $note = '')
{
    if (!is_array($fileInfo) || empty($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
        return array(false, 'ماكو ملف مرفوع', '');
    }
    $name = isset($fileInfo['name']) ? (string) $fileInfo['name'] : '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        return array(false, 'ارفع ملف ZIP فقط', '');
    }
    if (!empty($fileInfo['size']) && (int) $fileInfo['size'] > 40 * 1024 * 1024) {
        return array(false, 'الملف أكبر من 40MB', '');
    }
    $dir = app_updates_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return array(false, 'مجلد storage/updates غير قابل للكتابة', '');
    }
    $safe = 'update_' . date('Ymd_His') . '.zip';
    $dest = $dir . '/' . $safe;
    if (!@move_uploaded_file($fileInfo['tmp_name'], $dest)) {
        return array(false, 'فشل حفظ الملف', '');
    }
    $payload = array(
        'app_update_file' => $safe,
        'app_update_note' => trim((string) $note),
        'app_update_at' => date('Y-m-d H:i:s'),
    );
    if (!settings_save($payload)) {
        return array(false, 'حُفظ الملف لكن فشل تحديث الإعدادات', $safe);
    }
    return array(true, 'تم رفع التحديث — النظام يطلب التطبيق الآن', $safe);
}
