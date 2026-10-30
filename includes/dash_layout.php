<?php

function dash_layout_tones()
{
    return array(
        'tone-blue' => 'أزرق',
        'tone-green' => 'أخضر',
        'tone-red' => 'أحمر',
        'tone-yellow' => 'أصفر',
        'tone-teal' => 'تركواز',
        'tone-navy' => 'كحلي',
        'tone-lime' => 'ليموني',
        'tone-purple' => 'بنفسجي',
        'tone-aqua' => 'سماوي',
    );
}

function dash_layout_platform_catalog()
{
    global $lang;
    $en = (isset($lang) && $lang === 'en');
    $rows = array(
        'plat_users' => array('ar' => 'عدد المستخدمين', 'en' => 'Users', 'tone' => 'tone-blue', 'perm' => ''),
        'plat_pending' => array('ar' => 'طلبات التسجيل', 'en' => 'Registration requests', 'tone' => 'tone-yellow', 'perm' => ''),
        'plat_active' => array('ar' => 'الفعالين', 'en' => 'Active', 'tone' => 'tone-green', 'perm' => ''),
        'plat_expired' => array('ar' => 'المنتهين', 'en' => 'Expired', 'tone' => 'tone-red', 'perm' => ''),
        'version' => array('ar' => 'إصدار النظام', 'en' => 'System version', 'tone' => 'tone-navy', 'perm' => ''),
        'plat_google' => array('ar' => 'البنك على كوكل', 'en' => 'Google bank', 'tone' => 'tone-lime', 'perm' => ''),
        'plat_reseller_bank' => array('ar' => 'البنك على الرسلرات', 'en' => 'Reseller bank', 'tone' => 'tone-teal', 'perm' => ''),
        'plat_datetime' => array('ar' => 'الوقت والتاريخ', 'en' => 'Date & time', 'tone' => 'tone-teal', 'perm' => ''),
        'plat_sales' => array('ar' => 'المبيعات', 'en' => 'Sales', 'tone' => 'tone-purple', 'perm' => ''),
        'plat_received' => array('ar' => 'المستلم', 'en' => 'Received', 'tone' => 'tone-aqua', 'perm' => ''),
        'plat_debt' => array('ar' => 'الدين', 'en' => 'Debt', 'tone' => 'tone-red', 'perm' => ''),
    );
    $out = array();
    foreach ($rows as $id => $meta) {
        $meta['label'] = $en ? $meta['en'] : $meta['ar'];
        $out[$id] = $meta;
    }
    return $out;
}

function dash_layout_catalog($pdo = null)
{
    global $lang;
    $en = (isset($lang) && $lang === 'en');
    // لوحة البوابة: وجدز المنصة فقط — مو وجدز الشركات/الوكلاء
    if (function_exists('is_super_admin_user') && is_super_admin_user()
        && !(function_exists('is_impersonating') && is_impersonating())) {
        return dash_layout_platform_catalog();
    }
    $ftth = function_exists('tenant_is_ftth') && tenant_is_ftth($pdo);
    $can = function ($perm) {
        return $perm === '' || (function_exists('user_can') && user_can($perm));
    };
    $rows = array(
        'subscribers' => array('ar' => 'المشتركين', 'en' => 'Subscribers', 'tone' => 'tone-blue', 'perm' => 'subscribers'),
        'active' => array('ar' => 'فعال', 'en' => 'Active', 'tone' => 'tone-green', 'perm' => 'subscribers'),
        'online' => array('ar' => 'متصل حاليا', 'en' => 'Online', 'tone' => 'tone-aqua', 'perm' => 'subscribers'),
        'expired' => array('ar' => 'منتهي', 'en' => 'Expired', 'tone' => 'tone-red', 'perm' => 'subscribers'),
        'soon' => array('ar' => 'على وشك الانتهاء', 'en' => 'About to expire', 'tone' => 'tone-yellow', 'perm' => 'subscribers'),
        'today' => array('ar' => 'ينتهي اليوم', 'en' => 'Expiring today', 'tone' => 'tone-teal', 'perm' => 'subscribers'),
        'agents' => array('ar' => 'الوكلاء', 'en' => 'Agents', 'tone' => 'tone-purple', 'perm' => 'agents'),
        'cards' => array('ar' => 'الكروت', 'en' => 'Cards', 'tone' => 'tone-navy', 'perm' => 'cards'),
        'collected' => array('ar' => 'المقبوض', 'en' => 'Collected', 'tone' => 'tone-yellow', 'perm' => 'reports'),
        'debts' => array('ar' => 'الديون', 'en' => 'Debts', 'tone' => 'tone-red', 'perm' => 'debts'),
        'profit' => array('ar' => 'الربح', 'en' => 'Profit', 'tone' => 'tone-green', 'perm' => 'reports'),
        'capital' => array('ar' => 'رأس المال', 'en' => 'Capital', 'tone' => 'tone-teal', 'perm' => 'reports'),
        'sales' => array('ar' => 'المبيعات', 'en' => 'Sales', 'tone' => 'tone-purple', 'perm' => 'subscriptions'),
        'activations' => array('ar' => 'التفعيلات', 'en' => 'Activations', 'tone' => 'tone-aqua', 'perm' => 'subscriptions'),
        'rentals' => array('ar' => 'الإيجار', 'en' => 'Rentals', 'tone' => 'tone-navy', 'perm' => 'rentals'),
        'points' => array('ar' => 'نقاط تشجيعية', 'en' => 'Reward points', 'tone' => 'tone-lime', 'perm' => ''),
        'latency' => array('ar' => 'بنك الريسلر', 'en' => 'Reseller ping', 'tone' => 'tone-navy', 'perm' => ''),
        'cabinets' => array('ar' => 'الكابينات', 'en' => 'Cabinets', 'tone' => 'tone-teal', 'perm' => 'subscribers'),
        'version' => array('ar' => 'إصدار النظام', 'en' => 'System version', 'tone' => 'tone-purple', 'perm' => ''),
        'growth' => array('ar' => 'نمو المشتركين', 'en' => 'Subscriber growth', 'tone' => 'tone-lime', 'perm' => ''),
    );
    $out = array();
    foreach ($rows as $id => $meta) {
        if (!$can($meta['perm'])) {
            continue;
        }
        if ($ftth && ($id === 'debts' || $id === 'rentals')) {
            continue;
        }
        if ($id === 'rentals' && function_exists('rental_system_is_on') && !rental_system_is_on()) {
            continue;
        }
        if (!$ftth && $id === 'cabinets') {
            continue;
        }
        $meta['label'] = $en ? $meta['en'] : $meta['ar'];
        $out[$id] = $meta;
    }
    if (function_exists('is_accountant_user') && is_accountant_user()
        && function_exists('accountant_widget_catalog_full')) {
        $full = accountant_widget_catalog_full();
        $allowed = function_exists('accountant_widgets_effective') ? accountant_widgets_effective($pdo) : null;
        $ids = is_array($allowed) ? $allowed : (is_array($full) ? array_keys($full) : array());
        $picked = array();
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id === '' || isset($picked[$id]) || $id === 'reports' || $id === 'messages') {
                continue;
            }
            if (isset($out[$id])) {
                $picked[$id] = $out[$id];
                continue;
            }
            if (!is_array($full) || !isset($full[$id]) || !is_array($full[$id])) {
                continue;
            }
            $meta = $full[$id];
            $perm = isset($meta['perm']) ? (string) $meta['perm'] : '';
            if ($perm !== '' && !$can($perm)) {
                continue;
            }
            if ($ftth && ($id === 'debts' || $id === 'rentals')) {
                continue;
            }
            if ($id === 'rentals' && function_exists('rental_system_is_on') && !rental_system_is_on()) {
                continue;
            }
            $meta['label'] = $en
                ? (isset($meta['en']) ? $meta['en'] : $id)
                : (isset($meta['ar']) ? $meta['ar'] : $id);
            if (empty($meta['tone'])) {
                $meta['tone'] = 'tone-navy';
            }
            $picked[$id] = $meta;
        }
        return $picked;
    }
    return $out;
}

function dash_layout_edit_rows($pdo)
{
    $cat = dash_layout_catalog($pdo);
    $edit = array();
    $saved = dash_layout_saved($pdo);
    if (is_array($saved)) {
        foreach ($saved as $row) {
            if (is_array($row) && !empty($row['id']) && isset($cat[(string) $row['id']])) {
                $edit[] = $row;
            }
        }
    }
    foreach ($cat as $id => $meta) {
        $seen = false;
        foreach ($edit as $row) {
            if ((string) $row['id'] === (string) $id) {
                $seen = true;
                break;
            }
        }
        if (!$seen) {
            $edit[] = array('id' => $id, 'on' => 1, 'tone' => $meta['tone']);
        }
    }
    return array($cat, $edit);
}

function dash_layout_saved($pdo, $userId = 0)
{
    if ($userId <= 0 && function_exists('current_admin')) {
        $u = current_admin();
        $userId = $u ? (int) $u['id'] : 0;
    }
    if ($userId <= 0 || !$pdo) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT ui_prefs FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $userId));
        $raw = $st->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['dash_layout']) || !is_array($data['dash_layout'])) {
        return null;
    }
    return $data['dash_layout'];
}

function dash_layout_map($pdo)
{
    $saved = dash_layout_saved($pdo);
    $cat = dash_layout_catalog($pdo);
    if (!is_array($saved)) {
        return null;
    }
    $map = array();
    $ord = 1;
    foreach ($saved as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $id = (string) $row['id'];
        if (!isset($cat[$id])) {
            continue;
        }
        $tone = isset($row['tone']) ? (string) $row['tone'] : '';
        if (!isset(dash_layout_tones()[$tone])) {
            $tone = $cat[$id]['tone'];
        }
        $map[$id] = array(
            'on' => !empty($row['on']) ? 1 : 0,
            'tone' => $tone,
            'ord' => $ord,
        );
        $ord++;
    }
    foreach ($cat as $id => $meta) {
        if (!isset($map[$id])) {
            $map[$id] = array('on' => 1, 'tone' => $meta['tone'], 'ord' => $ord);
            $ord++;
        }
    }
    return $map;
}

function dash_layout_save($pdo, $userId, $rows)
{
    $userId = (int) $userId;
    if ($userId <= 0 || !$pdo) {
        return false;
    }
    $cat = dash_layout_catalog($pdo);
    $tones = dash_layout_tones();
    $clean = array();
    $seen = array();
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $id = (string) $row['id'];
        if (!isset($cat[$id]) || isset($seen[$id])) {
            continue;
        }
        $tone = isset($row['tone']) ? (string) $row['tone'] : $cat[$id]['tone'];
        if (!isset($tones[$tone])) {
            $tone = $cat[$id]['tone'];
        }
        $clean[] = array(
            'id' => $id,
            'on' => !empty($row['on']) ? 1 : 0,
            'tone' => $tone,
        );
        $seen[$id] = 1;
    }
    try {
        $st = $pdo->prepare('SELECT ui_prefs FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $userId));
        $raw = $st->fetchColumn();
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : array();
        if (!is_array($data)) {
            $data = array();
        }
        $data['dash_layout'] = $clean;
        $json = json_encode($data);
        if ($json === false) {
            return false;
        }
        $up = $pdo->prepare('UPDATE admin_users SET ui_prefs = :p WHERE id = :id');
        $up->execute(array(':p' => $json, ':id' => $userId));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function dash_layout_apply(&$tone, &$attrs, $dashKey)
{
    $dashKey = (string) $dashKey;
    if ($dashKey === 'debts' || $dashKey === 'rentals') {
        global $pdo;
        if (function_exists('tenant_is_ftth') && tenant_is_ftth($pdo)) {
            return false;
        }
    }
    if ($dashKey === 'rentals' && function_exists('rental_system_is_on') && !rental_system_is_on()) {
        return false;
    }
    $map = isset($GLOBALS['dashLayoutMap']) && is_array($GLOBALS['dashLayoutMap']) ? $GLOBALS['dashLayoutMap'] : null;
    if ($dashKey === '') {
        // بدون مفتاح: لا تطفو فوق الوجدز المرتبة (order الافتراضي 0 يتقدم على 1+)
        if ($map !== null) {
            $attrs .= ' style="order:900"';
        }
        return true;
    }
    if ($map === null) {
        $attrs .= ' data-dash="' . htmlspecialchars($dashKey, ENT_QUOTES, 'UTF-8') . '"';
        return true;
    }
    if (isset($map[$dashKey]) && empty($map[$dashKey]['on'])) {
        return false;
    }
    if (!empty($map[$dashKey]['tone'])) {
        $tone = (string) $map[$dashKey]['tone'];
    }
    $ord = isset($map[$dashKey]['ord']) ? (int) $map[$dashKey]['ord'] : 900;
    $attrs .= ' data-dash="' . htmlspecialchars($dashKey, ENT_QUOTES, 'UTF-8') . '" style="order:' . $ord . '"';
    return true;
}

/**
 * أيقونة SVG حديثة للوجدز — المفتاح يطابق وصف البطاقة
 */
function dash_widget_icon($key)
{
    $key = strtolower(trim((string) $key));
    $alias = array(
        'subscribers' => 'users',
        'plat_users' => 'users',
        'plat_pending' => 'inbox',
        'plat_active' => 'active',
        'plat_expired' => 'expired',
        'plat_google' => 'globe',
        'plat_reseller_bank' => 'signal',
        'plat_datetime' => 'clock',
        'plat_sales' => 'sales',
        'plat_received' => 'collected',
        'plat_debt' => 'debts',
        'latency' => 'signal',
        'stock' => 'stock',
        'xfers' => 'transfer',
        'card_profit' => 'profit',
        'card_paid' => 'collected',
        'card_due' => 'debts',
        '👤' => 'users',
        '☺' => 'active',
        '💡' => 'online',
        '☹' => 'expired',
        '📅' => 'soon',
        '👥' => 'agents',
        '🃏' => 'cards',
        '📦' => 'version',
        '📈' => 'profit',
        '💵' => 'collected',
        '📄' => 'debts',
        '🏦' => 'capital',
        '🧾' => 'sales',
        '⚡' => 'activations',
        '📡' => 'signal',
        '🎁' => 'points',
        '🌐' => 'globe',
        '🕒' => 'clock',
        '✉' => 'inbox',
        '✓' => 'active',
        '⏱' => 'expired',
    );
    if (isset($alias[$key])) {
        $key = $alias[$key];
    }
    $icons = array(
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'active' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'online' => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1.2"/>',
        'expired' => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
        'soon' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'today' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/>',
        'agents' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'cards' => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
        'stock' => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
        'transfer' => '<path d="M7 17l-4-4 4-4"/><path d="M3 13h13a4 4 0 0 0 0-8h-1"/><path d="M17 7l4 4-4 4"/><path d="M21 11H8a4 4 0 0 0 0 8h1"/>',
        'collected' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01"/><path d="M18 12h.01"/>',
        'debts' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>',
        'profit' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'capital' => '<line x1="3" y1="22" x2="21" y2="22"/><line x1="6" y1="18" x2="6" y2="11"/><line x1="10" y1="18" x2="10" y2="11"/><line x1="14" y1="18" x2="14" y2="11"/><line x1="18" y1="18" x2="18" y2="11"/><polygon points="12 2 20 7 4 7"/>',
        'sales' => '<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'activations' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'rentals' => '<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"/>',
        'points' => '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
        'signal' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'cabinets' => '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>',
        'version' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'growth' => '<path d="M3 3v18h18"/><path d="M7 14l4-4 4 4 5-6"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'default' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
    );
    if ($key === 'today') {
        // keep distinct from soon
    } elseif ($key === 'soon') {
        // clock already
    }
    $inner = isset($icons[$key]) ? $icons[$key] : $icons['default'];
    return '<svg class="sas-ico-svg" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $inner
        . '</svg>';
}
