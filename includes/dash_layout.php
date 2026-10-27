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

function dash_layout_catalog($pdo = null)
{
    global $lang;
    $en = (isset($lang) && $lang === 'en');
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
    );
    $out = array();
    foreach ($rows as $id => $meta) {
        if (!$can($meta['perm'])) {
            continue;
        }
        if ($ftth && ($id === 'debts' || $id === 'rentals')) {
            continue;
        }
        if (!$ftth && $id === 'cabinets') {
            continue;
        }
        $meta['label'] = $en ? $meta['en'] : $meta['ar'];
        $out[$id] = $meta;
    }
    return $out;
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
    if ($dashKey === '') {
        return true;
    }
    $map = isset($GLOBALS['dashLayoutMap']) && is_array($GLOBALS['dashLayoutMap']) ? $GLOBALS['dashLayoutMap'] : null;
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
    $ord = isset($map[$dashKey]['ord']) ? (int) $map[$dashKey]['ord'] : 40;
    $attrs .= ' data-dash="' . htmlspecialchars($dashKey, ENT_QUOTES, 'UTF-8') . '" style="order:' . $ord . '"';
    return true;
}
