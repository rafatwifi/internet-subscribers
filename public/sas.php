<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('subscribers');

if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

$pageError = '';
$rows = array();
$totalRows = 0;
$offset = 0;
$syncMode = '';
$syncMeta = array();
$sasReady = function_exists('sas_is_ready') && sas_is_ready($config);

if (function_exists('ensure_sas_users_cache_table')) {
    try {
        ensure_sas_users_cache_table($pdo);
    } catch (Exception $e) {
        $pageError = 'تعذر إنشاء جداول SAS: ' . $e->getMessage();
    } catch (Error $e) {
        $pageError = 'تعذر إنشاء جداول SAS: ' . $e->getMessage();
    }
} else {
    $pageError = 'ملف includes/sas_cache.php غير مرفوع على السيرفر';
}

if (!function_exists('subscriber_msg_is_no_whatsapp')) {
    function subscriber_msg_is_no_whatsapp($response)
    {
        $response = (string) $response;
        if ($response === '') {
            return false;
        }
        return (
            stripos($response, 'not on WhatsApp') !== false
            || stripos($response, 'no_whatsapp') !== false
            || strpos($response, 'لا يتوفر واتساب') !== false
        );
    }
}

function sas_page_redirect($extra = '')
{
    $url = 'sas.php';
    if ($extra !== '') {
        $url .= (strpos($extra, '?') === 0) ? $extra : ('?' . ltrim($extra, '&?'));
    }
    redirect($url);
}

function sas_resolve_local_from_username($pdo, $config, $username)
{
    $username = trim((string) $username);
    if ($username === '') {
        return array(0, 'ماكو يوزرنيم');
    }
    $cache = sas_cache_get($pdo, $username);
    if (!$cache) {
        return array(0, 'المشترك مو موجود بكاش SAS — حدّث القائمة');
    }
    return sas_cache_ensure_local($pdo, $config, $cache);
}

function sas_json_out($ok, $message, $extra = array())
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $payload = array_merge(array('ok' => (bool) $ok, 'message' => (string) $message), is_array($extra) ? $extra : array());
    echo json_encode($payload);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'quote') {
    $username = isset($_GET['username']) ? trim((string) $_GET['username']) : '';
    $profileId = isset($_GET['profile_id']) ? (int) $_GET['profile_id'] : 0;
    $quote = function_exists('sas_activation_quote')
        ? sas_activation_quote($pdo, $config, $username, $profileId)
        : array();
    sas_json_out(true, '', is_array($quote) ? $quote : array());
}

if (isset($_GET['ajax']) && ($_GET['ajax'] === 'profiles' || $_GET['ajax'] === 'cards' || $_GET['ajax'] === 'managers')) {
    $api = sas_page_connector($config);
    if (!$api) {
        sas_json_out(false, 'تعذر الدخول للساس');
    }
    if (method_exists($api, 'setTimeout')) {
        $api->setTimeout(40);
    }
    if ($_GET['ajax'] === 'profiles') {
        sas_json_out(true, '', array('profiles' => sas_profiles_for_ui($api)));
    }
    if ($_GET['ajax'] === 'managers') {
        sas_json_out(true, '', array('managers' => sas_managers_for_ui($api)));
    }
    $username = isset($_GET['username']) ? trim((string) $_GET['username']) : '';
    $profileId = isset($_GET['profile_id']) ? (int) $_GET['profile_id'] : 0;
    $profileName = isset($_GET['profile_name']) ? trim((string) $_GET['profile_name']) : '';
    $preload = isset($_GET['preload']) && $_GET['preload'] === '1';
    $forceCards = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    if ($username !== '' && !$preload) {
        $cache = sas_cache_get($pdo, $username);
        if ($cache) {
            if ($profileId <= 0 && !empty($cache['profile_id'])) {
                $profileId = (int) $cache['profile_id'];
            }
            if ($profileName === '' && !empty($cache['profile_name'])) {
                $profileName = (string) $cache['profile_name'];
            }
        }
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(50);
    }
    $allCards = function_exists('sas_unused_cards_cached')
        ? sas_unused_cards_cached($api, $forceCards)
        : sas_cards_for_ui($api, 0, 0, '');
    $cards = $preload
        ? $allCards
        : (function_exists('sas_cards_filter_cached')
            ? sas_cards_filter_cached($allCards, $profileId, $profileName)
            : $allCards);
    $quote = array();
    if (!$preload && function_exists('sas_activation_quote')) {
        $quote = sas_activation_quote($pdo, $config, $username, $profileId);
    }
    sas_json_out(true, '', array_merge(array(
        'cards' => is_array($cards) ? $cards : array(),
        'profile_id' => $profileId,
    ), is_array($quote) ? $quote : array()));
}

if (isset($_GET['ajax']) && ($_GET['ajax'] === 'sync' || $_GET['ajax'] === 'pull' || $_GET['ajax'] === 'diag')) {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('set_time_limit')) {
        @set_time_limit(60);
    }
    register_shutdown_function(function () {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array(
            'ok' => false,
            'mode' => 'error',
            'count' => 0,
            'last_error' => 'PHP: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line'],
        ));
    });

    if ($_GET['ajax'] === 'diag') {
        $diag = array('ok' => true, 'steps' => array());
        $diag['steps'][] = 'php=' . PHP_VERSION;
        $diag['steps'][] = 'sas_cache=' . (function_exists('sas_sync_users_from_api') ? 'yes' : 'NO');
        $diag['steps'][] = 'listUsersPage=' . ((class_exists('SASConnector') && method_exists('SASConnector', 'listUsersPage')) ? 'yes' : 'NO');
        try {
            if (function_exists('ensure_sas_users_cache_table')) {
                ensure_sas_users_cache_table($pdo);
                $diag['steps'][] = 'tables=ok count=' . (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache')->fetchColumn();
            }
        } catch (Exception $e) {
            $diag['ok'] = false;
            $diag['steps'][] = 'tables=' . $e->getMessage();
        }
        try {
            $api = function_exists('sas_make_connector') ? sas_make_connector($config) : null;
            if (!$api) {
                $diag['ok'] = false;
                $diag['steps'][] = 'connector=NO';
            } else {
                $api->setTimeout(15);
                $diag['steps'][] = 'login=' . ($api->login() ? 'ok' : ('FAIL ' . $api->getLastError()));
                $one = $api->getFirstUser();
                if (is_array($one)) {
                    $diag['steps'][] = 'firstUser=' . implode(',', array_slice(array_keys($one), 0, 12));
                    $diag['steps'][] = 'username=' . (isset($one['username']) ? $one['username'] : '?');
                } else {
                    $diag['ok'] = false;
                    $diag['steps'][] = 'firstUser=empty';
                }
                if (method_exists($api, 'listUsersPage')) {
                    $pg = $api->listUsersPage(0, 1, '');
                    $diag['steps'][] = 'list1 ok=' . (!empty($pg['ok']) ? '1' : '0')
                        . ' rows=' . (isset($pg['rows']) ? count($pg['rows']) : 0)
                        . ' via=' . (isset($pg['via']) ? $pg['via'] : '')
                        . ' msg=' . (isset($pg['message']) ? $pg['message'] : '');
                }
            }
        } catch (Exception $e) {
            $diag['ok'] = false;
            $diag['steps'][] = 'ex=' . $e->getMessage();
        } catch (Error $e) {
            $diag['ok'] = false;
            $diag['steps'][] = 'err=' . $e->getMessage();
        }
        echo json_encode($diag);
        exit;
    }

    $force = isset($_GET['force']) && $_GET['force'] === '1';
    $reset = isset($_GET['reset']) && $_GET['reset'] === '1';
    if (!function_exists('sas_sync_users_from_api')) {
        echo json_encode(array('ok' => false, 'count' => 0, 'mode' => 'error', 'last_error' => 'sas_cache missing'));
        exit;
    }
    try {
        list($ok, $count, $mode, $meta) = sas_sync_users_from_api($pdo, $config, $force, $reset);
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'count' => 0, 'mode' => 'error', 'last_error' => $e->getMessage()));
        exit;
    } catch (Error $e) {
        echo json_encode(array('ok' => false, 'count' => 0, 'mode' => 'error', 'last_error' => $e->getMessage()));
        exit;
    }
    echo json_encode(array(
        'ok' => $ok,
        'count' => (int) $count,
        'mode' => $mode,
        'offset' => isset($meta['sync_offset']) ? (int) $meta['sync_offset'] : 0,
        'expected' => isset($meta['sync_expected']) ? (int) $meta['sync_expected'] : 0,
        'last_ok_at' => isset($meta['last_ok_at']) ? $meta['last_ok_at'] : null,
        'last_error' => isset($meta['last_error']) ? $meta['last_error'] : null,
    ));
    exit;
}

if (isset($_GET['prepare']) && (string) $_GET['prepare'] !== '') {
    $username = (string) $_GET['prepare'];
    $next = isset($_GET['next']) ? (string) $_GET['next'] : 'open';
    if ($next === 'activate') {
        redirect(sas_user_url($username, 'activate'));
    }
    if ($next === 'open' || $next === '') {
        redirect(sas_user_url($username));
    }
    list($localId, $err) = sas_resolve_local_from_username($pdo, $config, $username);
    if ($localId <= 0) {
        flash('error', $err !== '' ? $err : 'تعذر ربط المشترك');
        sas_page_redirect();
    }
    if ($next === 'pay') {
        redirect('debts.php?status=unpaid&subscriber_id=' . $localId);
    }
    redirect(sas_user_url($username));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        if (in_array(post('action'), array('sas_inline', 'sas_enable', 'sas_activate_card', 'sas_activate_credit', 'sas_change_profile', 'give_test', 'sas_update_rental', 'sas_update_debt', 'sas_update_grace', 'sas_save_cols'), true)) {
            sas_json_out(false, 'طلب غير صالح');
        }
        flash('error', 'طلب غير صالح');
        sas_page_redirect();
    }
    $action = post('action');

    if ($action === 'sas_save_cols') {
        $decoded = json_decode((string) post('cols', '{}'), true);
        if (!is_array($decoded)) {
            $decoded = array();
        }
        if (function_exists('admin_ui_prefs_save')) {
            admin_ui_prefs_save($pdo, 'sas_table_cols', $decoded);
        } else {
            if (!isset($_SESSION['ui_prefs']) || !is_array($_SESSION['ui_prefs'])) {
                $_SESSION['ui_prefs'] = array();
            }
            $_SESSION['ui_prefs']['sas_table_cols'] = $decoded;
        }
        $refreshSec = (int) post('refresh_sec', '0');
        if ($refreshSec < 0) {
            $refreshSec = 0;
        }
        if (function_exists('admin_ui_prefs_save')) {
            admin_ui_prefs_save($pdo, 'sas_refresh_sec', $refreshSec);
        } else {
            $_SESSION['ui_prefs']['sas_refresh_sec'] = $refreshSec;
        }
        sas_json_out(true, 'ok');
    }

    if ($action === 'sas_inline' || $action === 'sas_enable' || $action === 'sas_activate_card'
        || $action === 'sas_activate_credit' || $action === 'sas_change_profile' || $action === 'give_test') {
        if ($action === 'give_test') {
            $username = trim((string) post('id', ''));
            if (!function_exists('sas_extend_one_day')) {
                sas_json_out(false, 'ملف SAS غير مكتمل');
            }
            list($ok, $msg) = sas_extend_one_day($pdo, $config, $username);
            sas_json_out($ok, $msg);
        }
        $username = trim((string) post('id', ''));
        $fields = array(
            'field' => post('field', ''),
            'value' => post('value', ''),
            'enabled' => post('enabled', ''),
            'profile_id' => post('profile_id', '0'),
            'profile_name' => post('profile_name', ''),
            'units' => post('units', '1'),
            'pin' => post('pin', ''),
            'card_id' => post('card_id', '0'),
            'send_whatsapp' => post('send_whatsapp', '0'),
            'send_old_debts' => post('send_old_debts', '0'),
            'pay_mode' => post('pay_mode', 'cash'),
        );
        list($ok, $msg, $extra) = sas_write_user($pdo, $config, $action, $username, $fields);
        sas_json_out($ok, $msg, is_array($extra) ? $extra : array());
    }

    if ($action === 'sas_update_rental') {
        $username = trim((string) post('id', ''));
        $deviceId = trim((string) post('device_id', ''));
        $enabled = ($deviceId !== '');
        if (!function_exists('sas_save_user_rental')) {
            sas_json_out(false, 'ملف الإيجار غير مكتمل');
        }
        list($ok, $msg, $extra) = sas_save_user_rental($pdo, $config, $username, $enabled, $deviceId);
        sas_json_out($ok, $msg, is_array($extra) ? $extra : array());
    }

    if ($action === 'sas_update_debt') {
        $username = trim((string) post('id', ''));
        $amount = (float) post('amount', '0');
        if ($username === '') {
            sas_json_out(false, 'مشترك غير محدد');
        }
        if (!function_exists('apply_subscriber_unpaid_total_update')) {
            sas_json_out(false, 'ملف الديون غير مكتمل');
        }
        $cache = sas_cache_get($pdo, $username);
        if (!$cache) {
            sas_json_out(false, 'المشترك مو موجود بكاش SAS — حدّث القائمة');
        }
        list($localId, $err) = sas_cache_ensure_local($pdo, $config, $cache);
        if ($localId <= 0) {
            sas_json_out(false, $err !== '' ? $err : 'تعذر ربط المشترك');
        }
        list($ok, $msg, $total) = apply_subscriber_unpaid_total_update($pdo, $localId, $amount);
        $currency = isset($config['currency']) ? $config['currency'] : 'IQD';
        sas_json_out($ok, $msg, array(
            'local_id' => $localId,
            'debt' => $total,
            'debt_text' => function_exists('money_format_iqd') ? money_format_iqd($total, $currency) : (string) (int) $total,
        ));
    }

    if ($action === 'sas_update_grace') {
        $username = trim((string) post('id', ''));
        $days = (int) post('value', '3');
        if (!function_exists('sas_save_user_grace_days')) {
            sas_json_out(false, 'تعذر حفظ أيام السماح');
        }
        list($ok, $msg, $saved) = sas_save_user_grace_days($pdo, $config, $username, $days);
        sas_json_out($ok, $msg, array(
            'value' => (string) (int) $saved,
            'raw' => (string) (int) $saved,
        ));
    }

    if ($action === 'bulk_activate') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();
        $payMode = post('pay_mode') === 'credit' ? 'credit' : 'cash';
        $sendWa = post('send_whatsapp') === '1';
        $okN = 0;
        $failN = 0;
        $failNames = array();
        foreach ($ids as $raw) {
            $username = trim((string) $raw);
            if ($username === '') {
                continue;
            }
            list($localId, $err) = sas_resolve_local_from_username($pdo, $config, $username);
            if ($localId <= 0) {
                $failN++;
                $failNames[] = $username . ' (' . $err . ')';
                continue;
            }
            list($ok, $msg) = activate_one_subscriber($pdo, $config, $localId, array(
                'plan_id' => 0,
                'pay_mode' => $payMode,
                'send_whatsapp' => $sendWa,
                'send_old_debts' => false,
                'carry_days' => true,
            ));
            if ($ok) {
                $okN++;
            } else {
                $failN++;
                $failNames[] = $username . ' (' . $msg . ')';
            }
        }
        $note = 'تفعيل SAS: نجح ' . $okN;
        if ($failN > 0) {
            $note .= ' / فشل ' . $failN;
            if ($failNames) {
                $note .= ' — ' . implode('؛ ', array_slice($failNames, 0, 5));
            }
            flash($okN > 0 ? 'info' : 'error', $note);
        } else {
            flash($okN > 0 ? 'success' : 'error', $okN > 0 ? $note : 'حدد مشتركاً واحداً على الأقل');
        }
        sas_page_redirect();
    }

    if ($action === 'remind_debt' || $action === 'bulk_remind_debt') {
        $ids = array();
        if ($action === 'remind_debt') {
            $ids[] = post('id', '');
        } else {
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();
        }
        $okN = 0;
        $failN = 0;
        foreach ($ids as $raw) {
            $username = trim((string) $raw);
            if ($username === '') {
                continue;
            }
            $cache = sas_cache_get($pdo, $username);
            $localId = $cache && !empty($cache['local_subscriber_id']) ? (int) $cache['local_subscriber_id'] : 0;
            if ($localId <= 0 && $cache) {
                $picked = sas_cache_pick_local(
                    $pdo,
                    $username,
                    isset($cache['sas_user_id']) ? (int) $cache['sas_user_id'] : 0,
                    isset($cache['phone']) ? $cache['phone'] : ''
                );
                $localId = $picked ? (int) $picked['id'] : 0;
            }
            if ($localId <= 0) {
                $failN++;
                continue;
            }
            $st = $pdo->prepare('SELECT id, name, phone FROM subscribers WHERE id = :id');
            $st->execute(array(':id' => $localId));
            $sub = $st->fetch();
            if (!$sub) {
                $failN++;
                continue;
            }
            $debt = subscriber_unpaid_total($pdo, $localId);
            if ($debt <= 0) {
                continue;
            }
            $msg = reminder_message(array(
                'name' => $sub['name'],
                'phone' => $sub['phone'],
                'month_label' => date('Y-m'),
                'amount' => $debt,
                'debt_total' => $debt,
                'notes' => '',
            ), $config);
            $result = whatsapp_send($config, $sub['phone'], $msg, 'reminder_debt');
            log_message($pdo, $localId, $result);
            if (!empty($result['success'])) {
                $okN++;
            } else {
                $failN++;
            }
        }
        flash($okN > 0 ? 'success' : 'error', 'تذكير دين: نجح ' . $okN . ($failN ? (' / فشل ' . $failN) : ''));
        sas_page_redirect();
    }

    if ($action === 'remind_days') {
        $username = trim((string) post('id', ''));
        list($localId, $err) = sas_resolve_local_from_username($pdo, $config, $username);
        if ($localId <= 0) {
            flash('error', $err !== '' ? $err : 'تعذر الإرسال');
            sas_page_redirect();
        }
        $st = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $st->execute(array(':id' => $localId));
        $sub = $st->fetch();
        $cache = sas_cache_get($pdo, $username);
        $days = 0;
        if ($cache && !empty($cache['expire_at'])) {
            $days = function_exists('sas_remaining_days')
                ? (int) sas_remaining_days($cache['expire_at'])
                : (int) round((strtotime(date('Y-m-d', strtotime($cache['expire_at']))) - strtotime(date('Y-m-d'))) / 86400);
        }
        $body = days_left_message(array(
            'name' => $sub ? $sub['name'] : $username,
            'phone' => $sub ? $sub['phone'] : '',
            'days' => $days,
        ), $config);
        $result = whatsapp_send($config, $sub ? $sub['phone'] : '', $body, 'days_left');
        log_message($pdo, $localId, $result);
        flash(!empty($result['success']) ? 'success' : 'error', !empty($result['success']) ? 'تم إرسال الأيام المتبقية' : whatsapp_fail_user_message($result));
        sas_page_redirect();
    }

    if ($action === 'retry_message') {
        $logId = (int) post('log_id', '0');
        $username = trim((string) post('id', ''));
        list($localId) = sas_resolve_local_from_username($pdo, $config, $username);
        list($ok, $msg) = retry_failed_message($pdo, $config, $logId, $localId);
        flash($ok ? 'success' : 'error', $msg);
        sas_page_redirect();
    }

    flash('info', 'الحذف من صفحة تفاصيل المشترك حتى ما تضيع الديون');
    sas_page_redirect();
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$subFilter = isset($_GET['sub']) ? (string) $_GET['sub'] : '';
if (!function_exists('sas_cache_filter_sql') || sas_cache_filter_sql($subFilter) === '') {
    $subFilter = '';
}
$parentFilter = isset($_GET['parent']) ? trim((string) $_GET['parent']) : '';
if (strlen($parentFilter) > 80) {
    $parentFilter = '';
}

$cacheCount = 0;
try {
    $cacheCount = (int) $pdo->query('SELECT COUNT(*) FROM sas_users_cache')->fetchColumn();
} catch (Exception $e) {
    $cacheCount = 0;
    if ($pageError === '') {
        $pageError = $e->getMessage();
    }
}

try {
    $syncMeta = function_exists('sas_sync_meta') ? sas_sync_meta($pdo) : array();
} catch (Exception $e) {
    $syncMeta = array();
    if ($pageError === '') {
        $pageError = $e->getMessage();
    }
}

if ($sasReady && $cacheCount <= 0) {
    $syncMode = 'stale';
} elseif ($sasReady && $cacheCount > 0) {
    $stale = true;
    if (!empty($syncMeta['last_ok_at'])) {
        $stale = (time() - strtotime($syncMeta['last_ok_at'])) > 300;
    }
    if ($stale || isset($_GET['refresh'])) {
        $syncMode = 'stale';
    }
}

$params = array();
$where = function_exists('sas_cache_search_sql') ? sas_cache_search_sql($q, $params) : '1=1';
if (function_exists('sas_cache_filter_sql')) {
    $where .= sas_cache_filter_sql($subFilter);
}
if ($parentFilter !== '') {
    $where .= ' AND c.parent_name = :parent_name';
    $params[':parent_name'] = $parentFilter;
}

if ($sasReady && $q !== '' && strlen($q) >= 2 && function_exists('sas_cache_pull_search')) {
    try {
        sas_cache_pull_search($pdo, $config, $q);
    } catch (Exception $e) {
    } catch (Error $e) {
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'refresh_now') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('set_time_limit')) {
        @set_time_limit(40);
    }
    $qNow = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
    $n = 0;
    try {
        if ($qNow !== '' && function_exists('sas_cache_pull_search')) {
            $n = sas_cache_pull_search($pdo, $config, $qNow);
        } elseif ($sasReady) {
            $apiNow = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
            if ($apiNow && method_exists($apiNow, 'listUsersPage')) {
                if (method_exists($apiNow, 'setTimeout')) {
                    $apiNow->setTimeout(18);
                }
                $pageNow = $apiNow->listUsersPage(0, 80, '');
                if (!empty($pageNow['rows']) && is_array($pageNow['rows'])) {
                    foreach ($pageNow['rows'] as $rowNow) {
                        if (sas_cache_upsert_row($pdo, $rowNow)) {
                            $n++;
                        }
                    }
                }
            }
        }
        if (function_exists('sas_refresh_online_flags')) {
            sas_refresh_online_flags($pdo, $config);
        }
        echo json_encode(array('ok' => true, 'count' => $n, 'mode' => 'synced'));
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'count' => 0, 'mode' => 'error', 'last_error' => $e->getMessage()));
    } catch (Error $e) {
        echo json_encode(array('ok' => false, 'count' => 0, 'mode' => 'error', 'last_error' => $e->getMessage()));
    }
    exit;
}

if (isset($_GET['live']) && $_GET['live'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $html = '<tr><td colspan="15">' . e($lang === 'en' ? 'No matches' : 'ماكو نتيجة') . '</td></tr>';
    $liveCount = 0;
    try {
        $fromSql = function_exists('sas_cache_list_from_sql')
            ? sas_cache_list_from_sql()
            : ' FROM sas_users_cache c LEFT JOIN subscribers s ON s.id = c.local_subscriber_id';
        $sqlLive = sas_cache_list_select_sql() . $fromSql . '
            WHERE ' . $where . '
            ORDER BY c.display_name ASC
            LIMIT 80';
        $stLive = $pdo->prepare($sqlLive);
        $stLive->execute($params);
        $liveRows = $stLive->fetchAll();
        $html = '';
        $nLive = 1;
        foreach ($liveRows as $liveRow) {
            $html .= sas_render_table_row($liveRow, $nLive++, $config, $lang);
        }
        $liveCount = count($liveRows);
        if ($html === '') {
            $html = '<tr><td colspan="15">' . e($lang === 'en' ? 'No matches' : 'ماكو نتيجة') . '</td></tr>';
        }
    } catch (Exception $e) {
        $html = '<tr><td colspan="15">' . e($e->getMessage()) . '</td></tr>';
    } catch (Error $e) {
        $html = '<tr><td colspan="15">' . e($e->getMessage()) . '</td></tr>';
    }
    echo json_encode(array(
        'html' => $html,
        'count' => $liveCount,
        'capped' => $liveCount >= 80,
    ));
    exit;
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPageRaw = isset($_GET['per_page']) ? $_GET['per_page'] : '10';
$showAll = ($perPageRaw === 'all');
$perPage = $showAll ? 0 : max(1, (int) $perPageRaw);

$sortKey = isset($_GET['sort']) ? (string) $_GET['sort'] : 'name';
$sortDir = isset($_GET['dir']) && strtolower((string) $_GET['dir']) === 'desc' ? 'desc' : 'asc';
$sortMap = array(
    'id' => 'c.sas_user_id',
    'username' => 'c.username',
    'firstname' => 'c.firstname',
    'lastname' => 'c.lastname',
    'name' => 'c.display_name',
    'phone' => 'c.phone',
    'package' => 'c.profile_name',
    'parent' => 'c.parent_name',
    'expire' => 'c.expire_at',
    'month' => 'c.expire_at',
    'days' => 'c.expire_at',
    'debt' => 'debt',
    'rent' => 's.rental_device_id',
    'ip' => 'c.framed_ip',
    'msg' => 'last_msg_at',
);
if (!isset($sortMap[$sortKey])) {
    $sortKey = 'name';
}
$orderSql = $sortMap[$sortKey] . ' ' . strtoupper($sortDir);
if ($sortKey === 'name') {
    $orderSql = 'c.display_name ' . strtoupper($sortDir) . ', c.username ASC';
}

$fromSql = function_exists('sas_cache_list_from_sql')
    ? sas_cache_list_from_sql()
    : ' FROM sas_users_cache c LEFT JOIN subscribers s ON s.id = c.local_subscriber_id';

try {
    $countSql = 'SELECT COUNT(DISTINCT c.username)' . $fromSql . ' WHERE ' . $where;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();
} catch (Exception $e) {
    $totalRows = 0;
    if ($pageError === '') {
        $pageError = $e->getMessage();
    }
} catch (Error $e) {
    $totalRows = 0;
    if ($pageError === '') {
        $pageError = $e->getMessage();
    }
}

if ($showAll) {
    $perPage = $totalRows > 0 ? $totalRows : 1;
    $totalPages = 1;
    $page = 1;
    $offset = 0;
} else {
    $totalPages = max(1, (int) ceil(($totalRows > 0 ? $totalRows : 1) / max(1, $perPage)));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
}

$sql = '';
try {
    $sql = sas_cache_list_select_sql() . $fromSql . '
     WHERE ' . $where . '
     ORDER BY ' . $orderSql;
    if (!$showAll) {
        $sql .= ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    $rows = array();
    if ($pageError === '') {
        $pageError = $e->getMessage();
    }
} catch (Error $e) {
    $rows = array();
    if ($pageError === '') {
        $pageError = $e->getMessage();
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'live_table') {
    header('Content-Type: application/json; charset=utf-8');
    if ($sql === '') {
        echo json_encode(array('ok' => false, 'html' => '', 'last_error' => 'query'));
        exit;
    }
    try {
        if (function_exists('sas_refresh_online_flags')) {
            sas_refresh_online_flags($pdo, $config);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $html = '';
        $nLive = $offset + 1;
        foreach ($rows as $liveRow) {
            $html .= sas_render_table_row($liveRow, $nLive++, $config, $lang);
        }
        if ($html === '') {
            $html = '<tr><td colspan="15">' . e($lang === 'en' ? 'No SAS users in cache yet' : 'ماكو مشتركين من الساس بعد') . '</td></tr>';
        }
        echo json_encode(array('ok' => true, 'html' => $html, 'count' => count($rows)));
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'html' => '', 'last_error' => $e->getMessage()));
    } catch (Error $e) {
        echo json_encode(array('ok' => false, 'html' => '', 'last_error' => $e->getMessage()));
    }
    exit;
}

function sas_sort_link($key, $label, $currentKey, $currentDir, $q, $perPageRaw, $subFilter)
{
    if ($currentKey === $key) {
        $nextDir = ($currentDir === 'asc') ? 'desc' : 'asc';
    } elseif ($key === 'debt' || $key === 'days' || $key === 'id' || $key === 'rent') {
        $nextDir = 'desc';
    } else {
        $nextDir = 'asc';
    }
    $qs = array('sort=' . urlencode($key), 'dir=' . $nextDir);
    if ($q !== '') {
        $qs[] = 'q=' . urlencode($q);
    }
    if ($perPageRaw !== '10') {
        $qs[] = 'per_page=' . urlencode($perPageRaw);
    }
    if ($subFilter !== '') {
        $qs[] = 'sub=' . urlencode($subFilter);
    }
    if (!empty($GLOBALS['parentFilter'])) {
        $qs[] = 'parent=' . urlencode((string) $GLOBALS['parentFilter']);
    }
    $arrow = '';
    if ($currentKey === $key) {
        $arrow = $currentDir === 'asc' ? ' ↑' : ' ↓';
    }
    return '<a class="th-sort' . ($currentKey === $key ? ' on' : '') . '" href="?' . implode('&', $qs) . '">' . e($label) . $arrow . '</a>';
}

$lastOk = !empty($syncMeta['last_ok_at']) ? $syncMeta['last_ok_at'] : '';
$syncHint = $lang === 'en' ? 'SAS subscribers' : 'مشتركين الساس';
if ($lastOk !== '') {
    $syncHint .= ($lang === 'en' ? ' · last sync ' : ' · آخر تحديث ') . $lastOk;
}
$sasLastErr = (!empty($syncMeta['last_error'])) ? (string) $syncMeta['last_error'] : '';
$sasOfflineSnap = $sasReady && $sasLastErr !== '';
$uiPrefs = function_exists('admin_ui_prefs_load') ? admin_ui_prefs_load($pdo) : array();
if (!is_array($uiPrefs)) {
    $uiPrefs = array();
}
$savedCols = (isset($uiPrefs['sas_table_cols']) && is_array($uiPrefs['sas_table_cols'])) ? $uiPrefs['sas_table_cols'] : array();
$savedRefreshSec = isset($uiPrefs['sas_refresh_sec']) ? (int) $uiPrefs['sas_refresh_sec'] : 30;
if ($savedRefreshSec < 0) {
    $savedRefreshSec = 0;
}
$parentNames = array();
try {
    $parentNames = $pdo->query(
        'SELECT DISTINCT parent_name FROM sas_users_cache
         WHERE parent_name IS NOT NULL AND parent_name <> ""
         ORDER BY parent_name ASC LIMIT 100'
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($parentNames)) {
        $parentNames = array();
    }
} catch (Exception $e) {
    $parentNames = array();
} catch (Error $e) {
    $parentNames = array();
}

function sas_filter_href($sub, $parent, $q, $perPageRaw)
{
    $qs = array();
    if ($sub !== '') {
        $qs[] = 'sub=' . urlencode($sub);
    }
    if ($parent !== '') {
        $qs[] = 'parent=' . urlencode($parent);
    }
    if ($q !== '') {
        $qs[] = 'q=' . urlencode($q);
    }
    if ($perPageRaw !== '10') {
        $qs[] = 'per_page=' . urlencode($perPageRaw);
    }
    return 'sas.php' . (count($qs) ? ('?' . implode('&', $qs)) : '');
}

render_header(t('sas'), 'sas', '');
?>
<style>
.sas-radius-page { font-family: inherit; overflow-anchor: none; }
.sas-radius-page .sas-legend { display: flex; gap: 18px; flex-wrap: wrap; margin: 0 0 12px; font-size: 14px; color: #444; }
.sas-radius-page .sas-legend > span { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; flex: 0 0 auto; }
.sas-radius-page .status-sq {
  display: inline-block;
  width: 14px;
  height: 14px;
  border-radius: 2px;
  vertical-align: middle;
  box-sizing: border-box;
  overflow: hidden;
  font-style: normal;
  line-height: 0;
}
.sas-radius-page .status-sq.status-active { background: #00a65a; }
.sas-radius-page .status-sq.status-online { background: #00c0ef; }
.sas-radius-page .status-sq.status-expired { background: #f39c12; }
.sas-radius-page .status-sq.status-left { background: #dd4b39; }
.sas-radius-page .status-sq.status-expired-online {
  background: linear-gradient(to right, #00c0ef 50%, #f39c12 50%);
}
.sas-radius-page .col-exp,
.sas-radius-page .sas-expire-dt {
  direction: ltr;
  unicode-bidi: isolate;
  font-family: inherit;
  font-variant-numeric: tabular-nums;
}
.sas-radius-page #subsTable th.col-exp,
.sas-radius-page #subsTable td.col-exp {
  text-align: center !important;
  vertical-align: middle;
  white-space: nowrap;
}
.sas-expire-dt {
  display: inline-flex;
  flex-direction: row;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin: 0 auto;
  white-space: nowrap;
  line-height: 1.2;
  text-align: center;
  font-size: 14px;
  font-weight: 700;
}
.sas-expire-dt .sas-exp-d,
.sas-expire-dt .sas-exp-t {
  display: inline;
  width: auto;
  font-size: 14px;
  font-weight: 700;
  opacity: 1;
  text-align: center;
}
.sas-user-copywrap,
.sas-ip-wrap {
  display: inline-flex;
  flex-direction: row;
  align-items: center;
  justify-content: flex-start;
  gap: 6px;
  max-width: 100%;
  width: 100%;
}
.sas-user-copywrap a,
.sas-ip-wrap a,
.sas-ip-wrap span {
  overflow: visible;
  white-space: nowrap;
  min-width: 0;
  text-align: left;
}
.sas-user-copy {
  flex: 0 0 auto;
  border: 0; background: #e8eef3; color: #334155; border-radius: 4px;
  cursor: pointer; padding: 2px 6px; font-size: 12px; line-height: 1.3;
}
.sas-user-copy:hover { background: #d5dee7; }
.sas-card-list { list-style: none; margin: 6px 0 0; padding: 0; max-height: 132px; overflow: auto; border: 1px solid #e5e5e5; border-radius: 4px; }
.sas-card-list li { border-bottom: 1px solid #f0f0f0; }
.sas-card-list label { display: flex; gap: 8px; align-items: center; padding: 5px 8px; cursor: pointer; font-family: Consolas, Monaco, monospace; font-size: 12px; }
.sas-card-list label:hover { background: #eef7fb; }
.sas-card-empty { padding: 10px; text-align: center; color: #dd4b39; font-weight: 700; font-size: 13px; }
.sas-table-card { background: #fff; border: 1px solid #d2d6de; }
.sas-table-headbar {
  background: #eef2f6; color: #1e293b; padding: 6px 10px;
  display: flex; align-items: center; justify-content: flex-start; gap: 8px 10px;
  font-weight: 700; font-size: 14px; flex-wrap: nowrap;
  border-bottom: 1px solid #d2d6de;
}
.sas-table-headbar .sas-found { font-weight: 600; opacity: 1; color: #64748b; font-size: 11px; line-height: 1.2; }
.sas-headbar-lead {
  display: flex; align-items: center; gap: 8px;
  flex: 0 1 auto; min-width: 0;
}
.sas-headbar-title {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 1px;
  flex: 0 1 auto;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  line-height: 1.15;
}
.sas-headbar-name { font-weight: 800; font-size: 14px; color: #0f172a; }
.sas-radius-page .sas-table-headbar .ops-top-btn::after { content: none !important; display: none !important; }
.sas-headbar-actions {
  display: flex; align-items: center; gap: 8px; flex: 1 1 auto; min-width: 0;
}
.sas-table-tools {
  display: flex; align-items: stretch; flex: 0 0 auto;
  border: 1px solid #c5d0da; border-radius: 4px; overflow: hidden;
  background: #fff;
}
.sas-table-tools .tool-ico {
  appearance: none; border: 0; background: transparent; color: #475569;
  width: 36px; height: 30px; cursor: pointer; padding: 0;
  display: inline-flex; align-items: center; justify-content: center;
  border-inline-end: 1px solid #e2e8f0;
}
.sas-table-tools .tool-ico:last-child { border-inline-end: 0; }
.sas-table-tools .tool-ico:hover { color: #0f172a; background: #e8eef3; }
.sas-table-tools .tool-ico.is-on { color: #1d4ed8; background: #dbeafe; }
.sas-table-tools .tool-ico svg { display: block; }
.sas-table-headbar .sas-search-wrap { flex: 1 1 280px; width: auto; min-width: 180px; max-width: none; align-self: center; }
.sas-table-headbar .sas-search-wrap input {
  height: 40px;
  min-height: 40px;
  font-size: 14px;
  background: #fff;
  border-color: #9aa8b5;
  text-align: center;
  line-height: normal;
  padding-block: 0;
}
.sas-table-headbar .ops-top-btn {
  height: 28px !important; min-height: 28px; min-width: 0 !important;
  padding: 0 12px !important; font-size: 13px !important; line-height: 28px;
  overflow: hidden;
  position: relative;
  z-index: 3;
}
.sas-mode-row {
  display: flex; flex-wrap: wrap; align-items: center; gap: 12px 18px; margin: 10px 0 8px;
}
.sas-mode-row > label {
  display: inline-flex !important; align-items: center; gap: 8px;
  margin: 0; min-height: 0; font-size: 13px; font-weight: 600; color: #333; cursor: pointer;
}
.sas-mode-row input[type="radio"] {
  width: 16px !important; height: 16px !important; min-height: 0 !important;
  padding: 0 !important; margin: 0; flex: 0 0 16px; accent-color: #2b6c9a;
}
.sas-card-list input[type="radio"] {
  width: 16px !important; height: 16px !important; min-height: 0 !important; padding: 0 !important; flex: 0 0 16px;
}
.sas-table-card { padding-bottom: 8px; }
#subsPager {
  margin: 10px 12px 16px !important;
  padding-bottom: 4px;
}
#subsPager .btn.sm {
  height: 28px; min-width: 28px; padding: 0 11px; font-size: 12px;
  border-radius: 999px; font-weight: 600;
}
.sas-row-flash td { background: #fff6d6 !important; }
.sas-act-wa { margin-top: 8px; width: 100%; }
.sas-radius-page #sasActModal .sas-act-wa {
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 100%;
}
.sas-radius-page #sasActModal .sas-act-wa .toggle {
  display: flex;
  width: 100%;
  margin: 0;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  box-sizing: border-box;
  min-height: 28px;
}
.sas-radius-page #sasActModal .sas-act-wa .toggle-ui {
  width: 38px; height: 22px; flex: 0 0 38px;
}
.sas-radius-page #sasActModal .sas-act-wa .toggle-ui::after {
  width: 16px; height: 16px; top: 3px;
}
.sas-radius-page #sasActModal .sas-act-wa .toggle-text { font-size: 12px; }
.sas-radius-page #sasActModal .quick-nested-toggle {
  display: none !important;
}
.sas-radius-page #sasActModal .sas-act-field {
  display: block;
  margin: 0 0 8px;
  min-height: 0;
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
}
.sas-radius-page #sasActModal select,
.sas-radius-page #sasActModal input[type="number"] {
  width: 100%;
  height: 36px;
  margin-top: 4px;
  border-radius: 8px;
  font-size: 13px;
}
.sas-radius-page #sasActModal .sas-seg {
  display: flex;
  flex-direction: row;
  flex-wrap: nowrap;
  align-items: stretch;
  width: 100%;
  box-sizing: border-box;
  margin: 0 0 10px;
  padding: 3px;
  gap: 3px;
  background: #eef2f7;
  border-radius: 10px;
}
.sas-radius-page #sasActModal .sas-seg-opt {
  flex: 1 1 0;
  display: flex !important;
  align-items: stretch;
  margin: 0 !important;
  min-width: 0;
  min-height: 0;
  font-size: inherit;
  font-weight: inherit;
  color: inherit;
  cursor: pointer;
}
.sas-radius-page #sasActModal .sas-seg-opt input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
  width: 1px;
  height: 1px;
}
.sas-radius-page #sasActModal .sas-seg-opt span {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 36px;
  min-height: 36px;
  padding: 0 8px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 800;
  color: #475569;
  text-align: center;
}
.sas-radius-page #sasActModal .sas-seg-opt input:checked + span {
  background: #fff;
  color: #0f172a;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.12);
}
.sas-radius-page #sasActModal .pay-mode-box {
  margin: 10px 0 8px;
}
.sas-radius-page #sasActModal .pay-mode-row {
  margin-top: 6px;
}
.sas-radius-page #sasActModal #sasActCardBox { margin: 0 0 8px; }
.sas-radius-page #sasActModal #sasActCardBox select { margin-top: 0; }
.sas-radius-page #sasActModal .sas-act-debts {
  margin: 0 0 8px;
  padding: 8px 10px;
  border: 1px solid #dbe4ee;
  border-radius: 8px;
  background: #f8fafc;
}
.sas-radius-page #sasActModal .sas-act-debts .d-head {
  display: flex; justify-content: space-between; gap: 8px;
  font-weight: 700; color: #1e293b; margin-bottom: 2px;
  font-size: 12px;
}
.sas-radius-page #sasActModal .sas-act-debts .d-empty { color: #64748b; font-size: 12px; margin: 0; }
.sas-radius-page #sasActModal .sas-act-debts ul { list-style: none; margin: 0; padding: 0; }
.sas-radius-page #sasActModal .sas-act-debts li,
.sas-radius-page #sasActModal .sas-act-debts .d-row {
  display: flex; justify-content: space-between; gap: 8px;
  font-size: 13px; padding: 2px 0; color: #334155;
}
.sas-radius-page #sasActModal .sas-act-debts .d-total {
  margin-top: 4px; padding-top: 4px; border-top: 1px dashed #cbd5e1;
  font-weight: 800; color: #0f172a;
}
.sas-radius-page #sasActModal .sas-act-debts.is-empty .d-head,
.sas-radius-page #sasActModal .sas-act-debts.is-empty .d-empty,
.sas-radius-page #sasActModal .sas-act-debts.is-empty #sasActGrandRow {
  display: none !important;
}
.sas-radius-page #sasActModal #sasActCardHint { font-size: 11px; margin: 0 0 8px; }
.sas-radius-page #sasActModal #sasActCardHint.is-ok { display: none; }
.sas-act-who { margin: 0; }
.sas-act-name { font-weight: 800; font-size: 14px; color: #1e293b; line-height: 1.25; }
.sas-act-user {
  display: flex; align-items: center; gap: 6px;
  margin-top: 1px; font-size: 12px; color: #64748b;
  font-family: Consolas, Monaco, monospace;
}
.sas-act-copy {
  border: 0; background: #e2e8f0; color: #334155;
  border-radius: 4px; cursor: pointer; padding: 1px 6px;
  font-size: 11px; line-height: 1.4;
}
.sas-act-copy:hover { background: #cbd5e1; }
.sas-radius-page .ops-top-btn {
  background: #2b6c9a !important; border-color: #245e86 !important; color: #fff !important;
  border-radius: 3px; font-weight: 700; min-width: 7.5rem;
}
.sas-radius-page #sasActModal:not(.hidden) {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 10px;
  overflow: hidden;
}
#sasActModal .ops-modal-card.sas-act-card,
.sas-radius-page #sasActModal .ops-modal-card.sas-act-card {
  position: relative;
  display: flex;
  flex-direction: column;
  max-width: 400px;
  width: min(400px, calc(100vw - 20px));
  padding: 12px 14px 12px;
  max-height: min(92vh, 620px);
  max-height: min(92dvh, 620px);
  overflow: hidden;
  box-sizing: border-box;
  border-radius: 14px;
}
#sasActLock {
  display: none !important;
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  z-index: 80;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  background: rgba(15, 23, 42, 0.55);
  border-radius: inherit;
  pointer-events: auto;
}
#sasActModal.is-locking #sasActLock {
  display: flex !important;
}
#sasActModal.is-locking .sas-act-card > *:not(#sasActLock) {
  pointer-events: none !important;
}
#sasActLock .sas-act-lock-spin {
  width: 38px;
  height: 38px;
  border: 3px solid rgba(255,255,255,0.35);
  border-top-color: #fff;
  border-radius: 50%;
  animation: sasActSpin .7s linear infinite;
}
#sasActLock .sas-act-lock-txt {
  color: #fff;
  font-size: 15px;
  font-weight: 800;
}
@keyframes sasActSpin {
  to { transform: rotate(360deg); }
}
.sas-radius-page #sasActModal .ops-modal-head {
  margin-bottom: 10px;
  align-items: flex-start;
}
.sas-radius-page #sasActModal .ops-modal-head h3 { font-size: 16px; }
.sas-radius-page #sasActModal .sas-act-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
}
.sas-radius-page #sasActModal .sas-act-foot {
  flex: 0 0 auto;
  padding-top: 10px;
  margin-top: 4px;
  border-top: 1px solid #e2e8f0;
  background: #fff;
}
.sas-radius-page #sasActModal .sas-act-foot .btn,
.sas-radius-page #sasActModal #sasActSubmit {
  width: 100%;
  margin: 0;
  height: 42px;
  border-radius: 10px;
  font-weight: 800;
  background: #2563eb;
  color: #fff;
  border: 0;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.22);
  transition: background .12s ease, transform .12s ease, box-shadow .12s ease;
}
.sas-radius-page #sasActModal #sasActSubmit:hover:not(:disabled):not(.is-busy) {
  background: #1d4ed8;
}
.sas-radius-page #sasActModal #sasActSubmit:active,
.sas-radius-page #sasActModal #sasActSubmit.is-pressed,
.sas-radius-page #sasActModal #sasActSubmit.is-busy,
.sas-radius-page #sasActModal #sasActSubmit:disabled {
  background: #15803d !important;
  color: #fff !important;
  opacity: 1;
  transform: scale(0.985);
  box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.18);
}
.sas-radius-page #sasActModal .sas-modal-err:empty { display: none; margin: 0; }
.sas-radius-page #sasActResultModal {
  z-index: 120;
}
.sas-radius-page #sasActResultModal .sas-act-result-card {
  width: min(400px, 100%);
  text-align: center;
  padding: 22px 20px 18px;
}
.sas-radius-page #sasActResultModal .sas-act-result-card.is-ok h3 { color: #166534; }
.sas-radius-page #sasActResultModal .sas-act-result-card.is-error h3 { color: #b91c1c; }
.sas-radius-page #sasActResultModal .sas-act-result-ico {
  width: 52px;
  height: 52px;
  margin: 0 auto 10px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  font-weight: 800;
  line-height: 1;
}
.sas-radius-page #sasActResultModal .sas-act-result-card.is-ok .sas-act-result-ico {
  background: #dcfce7;
  color: #166534;
}
.sas-radius-page #sasActResultModal .sas-act-result-card.is-error .sas-act-result-ico {
  background: #fee2e2;
  color: #b91c1c;
}
.sas-radius-page #sasActResultModal h3 {
  margin: 0 0 8px;
  font-size: 20px;
}
.sas-radius-page #sasActResultModal p {
  margin: 0 0 16px;
  font-size: 14px;
  font-weight: 600;
  color: #334155;
  line-height: 1.55;
  white-space: pre-wrap;
}
.sas-radius-page #sasActResultModal .btn {
  width: 100%;
  height: 42px;
  font-weight: 800;
}
.sas-radius-page #sasProfModal .ops-modal-card {
  max-width: 360px;
  width: calc(100% - 32px);
  padding: 12px 14px;
  max-height: 92vh;
  overflow: auto;
  box-sizing: border-box;
}
@media (max-width: 560px) {
  .sas-radius-page #sasActModal:not(.hidden) {
    padding: 0;
    align-items: flex-end;
  }
  .sas-radius-page #sasProfModal {
    padding: 8px;
    align-items: flex-end;
  }
  .sas-radius-page #sasActModal .sas-seg {
    flex-direction: row !important;
    flex-wrap: nowrap !important;
  }
  .sas-radius-page #sasActModal .sas-seg-opt span {
    font-size: 12px;
    height: 34px;
    min-height: 34px;
  }
  .sas-radius-page #sasActModal .ops-modal-card.sas-act-card {
    max-width: 100%;
    width: 100%;
    height: auto;
    max-height: 100vh;
    max-height: 100dvh;
    padding: 12px 14px max(12px, env(safe-area-inset-bottom));
    border-radius: 16px 16px 0 0;
  }
  .sas-radius-page #sasProfModal .ops-modal-card {
    max-width: 100%;
    width: 100%;
    padding: 12px;
    border-radius: 16px 16px 12px 12px;
  }
}
.sas-refresh-hint { font-size: 11px; font-weight: 600; color: #475569; padding: 0 12px; min-height: 0; }
.sas-refresh-hint:empty { display: none; padding: 0; }
.sas-search-wrap {
  position: relative;
  display: flex;
  align-items: center;
}
.sas-search-wrap .sas-search-ico {
  position: absolute;
  inset-inline-start: 8px;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  color: #64748b;
  pointer-events: none;
}
.sas-search-wrap .sas-search-ico svg { display: block; }
.sas-search-wrap input {
  width: 100%; min-width: 0; height: 40px;
  padding-block: 0; padding-inline: 32px 30px;
  border: 1px solid #c5d0da; border-radius: 3px;
  font-size: 14px; font-family: inherit; background: #fff;
  text-align: center;
  line-height: normal;
  box-sizing: border-box;
}
.sas-search-wrap input:focus {
  outline: 0; border-color: #2b6c9a; box-shadow: 0 0 0 2px rgba(43,108,154,.18);
}
.sas-search-wrap .sas-search-clear {
  position: absolute; inset-inline-end: 6px; top: 50%; transform: translateY(-50%);
  width: 18px; height: 18px; line-height: 16px; text-align: center;
  border-radius: 50%; background: #e2e8f0; color: #334155;
  font-size: 14px; font-weight: 700; text-decoration: none;
}
.sas-radius-page .ops-dropdown { min-width: 188px; font-size: 13px; }
#colsDropdown { min-width: 260px; max-height: min(72vh, 520px); overflow: auto; }
#colsDropdown .cols-check {
  display: flex !important;
  align-items: center;
  gap: 6px;
  cursor: default;
  padding: 4px 6px;
}
#colsDropdown .cols-check > label {
  flex: 1 1 auto;
  min-width: 0;
  margin: 0;
  display: flex !important;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  min-height: 0;
}
#colsDropdown .cols-handle,
#colsDropdown .cols-shift {
  flex: 0 0 auto;
  border: 0;
  background: #eef2f7;
  color: #334155;
  width: 26px;
  height: 26px;
  border-radius: 4px;
  padding: 0;
  font-size: 11px;
  line-height: 26px;
  cursor: pointer;
}
#colsDropdown .cols-handle { cursor: grab; letter-spacing: -1px; font-weight: 800; }
#colsDropdown .cols-check.is-drag { opacity: .45; }
#colsDropdown .cols-check.is-over { outline: 1px dashed #2b6c9a; }
@media (max-width: 860px) {
  .sas-radius-page #subsTable .col-num:not(.col-off) { display: table-cell !important; }
}
.sas-radius-page #subsTable .col-num,
.sas-radius-page #subsTable .sub-check-cell,
.sas-radius-page #subsTable td.status-cell,
.sas-radius-page #subsTable th.status-cell {
  white-space: nowrap;
  text-align: center !important;
  vertical-align: middle;
  color: #334155;
  box-sizing: border-box;
}
.sas-radius-page #subsTable .sub-check-cell {
  width: 22px;
  min-width: 22px;
  max-width: 26px;
  padding: 4px 0 !important;
}
.sas-radius-page #subsTable .col-num {
  width: 22px;
  min-width: 22px;
  max-width: 32px;
  padding: 4px 2px !important;
}
.sas-radius-page #subsTable td.status-cell,
.sas-radius-page #subsTable th.status-cell {
  width: 28px;
  min-width: 28px;
  padding: 4px 2px !important;
}
.sas-radius-page .table-wrap { width: 100%; overflow: auto; max-width: 100%; overflow-anchor: none; }
.sas-radius-page #subsTable {
  width: 100%;
  min-width: 0;
  border-collapse: collapse;
  table-layout: auto;
}
.sas-radius-page #subsTable th,
.sas-radius-page #subsTable td {
  text-align: center !important;
  vertical-align: middle;
  overflow: visible;
  white-space: nowrap;
}
.sas-radius-page #subsTable th {
  background: #f4f4f4; color: #222; font-size: 13px; font-weight: 800;
  padding: 5px 6px; border-bottom: 1px solid #e5e5e5;
  line-height: 1.3;
}
.sas-radius-page #subsTable th .th-sort {
  display: inline;
  white-space: nowrap;
}
.sas-radius-page #subsTable td {
  padding: 4px 6px; font-size: 14px; font-weight: 600;
  border-bottom: 1px solid #ececec;
}
.sas-radius-page #subsTable td.col-user,
.sas-radius-page #subsTable th.col-user {
  text-align: left !important;
  direction: ltr;
  unicode-bidi: isolate;
}
.sas-radius-page #subsTable .col-fn,
.sas-radius-page #subsTable .col-pkg,
.sas-radius-page #subsTable .col-parent {
  max-width: none;
}
.sas-radius-page #subsTable .col-rent { min-width: 0 !important; }
.sas-radius-page #subsTable .cell-edit,
.sas-radius-page #subsTable .sas-link,
.sas-radius-page #subsTable .debt-amt,
.sas-radius-page #subsTable .rent-cell-edit {
  text-align: center !important;
}
.sas-radius-page #subsTable .cell-edit {
  display: inline-block;
  max-width: 100%;
}
.sas-radius-page #subsTable th.col-fn,
.sas-radius-page #subsTable td.col-fn,
.sas-radius-page #subsTable td.col-fn .cell-edit {
  text-align: right !important;
}
.sas-radius-page #subsTable .col-ip {
  direction: ltr;
  unicode-bidi: isolate;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.sas-ip-link { color: #3c8dbc; text-decoration: none; font-weight: 600; }
.sas-ip-link:hover { text-decoration: underline; }
.sas-radius-page #subsTable th.col-off,
.sas-radius-page #subsTable td.col-off { display: none !important; }
.sas-filter-label {
  font-size: 11px; font-weight: 800; color: #64748b;
  padding: 8px 10px 4px; letter-spacing: .02em;
}
.sas-filter-parents { max-height: 200px; overflow-y: auto; }
#filterDropdown { min-width: min(280px, calc(100vw - 20px)); max-height: min(72vh, 520px); overflow: auto; }
.sas-radius-page #subsTable .rent-cell-empty {
  min-height: 26px;
  min-width: 22px;
  opacity: 0.35;
}
.sas-offline-banner {
  border-radius: 6px; padding: 10px 14px; font-weight: 700; margin: 0 0 12px;
  background: #fff7ed; color: #9a3412; border: 1px solid #fdba74;
}
.sas-toast {
  position: fixed;
  top: 18px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 10050;
  background: #166534;
  color: #fff;
  padding: 14px 22px;
  border-radius: 12px;
  font-weight: 800;
  font-size: 15px;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.28);
  cursor: pointer;
  max-width: min(520px, calc(100% - 24px));
  text-align: center;
  line-height: 1.45;
}
.sas-toast.is-ok { background: #166534; }
.sas-toast.is-error { background: #b91c1c; }
.sas-radius-page #subsTable tbody tr:nth-child(even) td { background: #fcfcfc; }
.sas-radius-page #subsTable tbody tr:hover td { background: #eef7fb; }
.sas-radius-page #subsTable tbody tr.row-status-expired td { background: #fff8dc !important; }
.sas-radius-page #subsTable tbody tr.row-status-expired:hover td { background: #fff3c4 !important; }
.sas-radius-page #subsTable tbody tr.row-status-left td { background: #fde8e8 !important; }
.sas-radius-page #subsTable tbody tr.row-status-left:hover td { background: #fbd5d5 !important; }
.sas-radius-page a.sas-link { color: #3c8dbc; text-decoration: none; font-weight: 600; }
.sas-radius-page a.sas-link:hover { text-decoration: underline; }
.sas-radius-page .th-sort { color: inherit; text-decoration: none; }
.ops-item.is-on { background: rgba(60, 141, 188, 0.12); font-weight: 800; }
#subsTable .debt-amt {
  display: inline !important;
  padding: 0 !important;
  margin: 0 !important;
  border: 0 !important;
  background: transparent !important;
  font-size: inherit !important;
  font-weight: 700 !important;
  text-decoration: none;
}
#subsTable .debt-amt.debt-due { color: #b86a00 !important; }
#subsTable .debt-amt.debt-zero { color: #15803d !important; }
#subsTable .debt-edit-btn { cursor: pointer; }
#subsTable .debt-edit-btn:hover { text-decoration: underline; }
#subsTable .debt-edit-btn.editing {
  border: 1px solid #94a3b8 !important;
  background: #fff !important;
  padding: 2px 6px !important;
  min-width: 4.5rem;
  border-radius: 4px;
  text-decoration: none !important;
  outline: none;
}
#subsTable .cell-edit { cursor: text; }
#subsTable .cell-edit:hover { background: rgba(37, 99, 235, 0.08); border-radius: 4px; }
#subsTable .cell-edit.editing {
  display: inline-block;
  min-width: 7rem;
  padding: 2px 6px;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}
@media (max-width: 760px) {
  .sas-radius-page .sas-legend {
    flex-wrap: nowrap;
    overflow-x: auto;
    overflow-y: hidden;
    gap: 10px 14px;
    font-size: 12px;
    margin-bottom: 8px;
    padding-bottom: 2px;
    -webkit-overflow-scrolling: touch;
  }
  .sas-table-headbar {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    grid-template-areas:
      "title ops tools"
      "search search search";
    align-items: center;
    padding: 8px;
    gap: 8px;
  }
  .sas-headbar-lead {
    display: contents;
  }
  .sas-headbar-title {
    grid-area: title;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    overflow: hidden;
    min-width: 0;
    max-width: 9.5rem;
  }
  .sas-headbar-name {
    font-size: 13px;
    white-space: nowrap;
  }
  .sas-headbar-title .sas-found {
    display: block;
    font-size: 10px;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .sas-headbar-lead .subs-ops-side,
  .sas-headbar-lead .subs-ops-anchor {
    grid-area: ops;
    flex: 1 1 auto;
    min-width: 0;
    display: flex !important;
    width: auto;
  }
  .sas-headbar-lead .ops-top-btn {
    flex: 1 1 auto;
    width: 100%;
    min-width: 0 !important;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    overflow: hidden !important;
    height: 36px !important;
    min-height: 36px;
    line-height: 1.2 !important;
    padding: 0 12px !important;
    font-size: 13px !important;
  }
  .sas-table-headbar .sas-search-wrap {
    grid-area: search;
    flex: 1 1 auto;
    min-width: 0;
    width: 100%;
    order: unset;
  }
  .sas-table-tools {
    grid-area: tools;
    margin-inline-start: 0;
  }
  .sas-search-wrap input {
    height: 52px;
    min-height: 52px;
    font-size: 16px;
    text-align: center;
    line-height: normal;
    padding-block: 0;
    padding-inline: 36px 32px;
  }
  .sas-radius-page .table-wrap {
    overflow: auto;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
  }
  .sas-radius-page #subsTable { min-width: 0; width: 100%; table-layout: auto; }
  .sas-radius-page #subsTable th,
  .sas-radius-page #subsTable td {
    padding: 4px 4px;
    font-size: 13px;
  }
  .sas-expire-dt,
  .sas-expire-dt .sas-exp-d,
  .sas-expire-dt .sas-exp-t {
    font-size: 13px;
  }
}
@media (max-width: 480px) {
  .sas-headbar-title { font-size: 12px; }
  .sas-table-tools .tool-ico { width: 32px; height: 32px; }
}
@media (min-width: 761px) {
  body:has(.sas-radius-page) .footer { display: none; }
  body:has(.sas-radius-page) .main-top {
    position: sticky;
    top: 0;
    z-index: 60;
  }
  .sas-table-card {
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 168px);
    min-height: 0;
  }
  .sas-table-headbar { flex: 0 0 auto; position: sticky; top: 0; z-index: 4; }
  .sas-radius-page .table-wrap {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
  }
  .sas-radius-page #subsTable thead th {
    position: sticky;
    top: 0;
    z-index: 3;
    background: #f4f4f4;
  }
  #subsPager { flex: 0 0 auto; }
}
</style>
<script>
(function () {
  try {
    var o = JSON.parse(localStorage.getItem('sas_table_cols_v1') || '{}');
    if (!o || typeof o !== 'object') return;
    var parts = [];
    for (var k in o) {
      if (Object.prototype.hasOwnProperty.call(o, k) && !o[k]) {
        parts.push('#subsTable .col-' + k + '{display:none!important}');
      }
    }
    if (!parts.length) return;
    var s = document.createElement('style');
    s.id = 'sasColsBoot';
    s.appendChild(document.createTextNode(parts.join('')));
    document.head.appendChild(s);
  } catch (e) {}
})();
</script>
<div class="sas-radius-page">
    <?php if ($sasOfflineSnap): ?>
        <div class="sas-offline-banner" id="sasOfflineBanner" role="status">
            <?php echo e($lang === 'en'
                ? 'No connection to SAS — showing stored offline snapshot. Sync will resume automatically when SAS is back.'
                : 'ماكو اتصال بالساس — البيانات مخزّنة أوف لاين (لقطة محلية). عند رجوع الساس تبدأ المزامنة تلقائياً.'); ?>
        </div>
    <?php else: ?>
        <div class="sas-offline-banner" id="sasOfflineBanner" role="status" hidden></div>
    <?php endif; ?>
    <div class="sas-toast" id="sasSyncOkBanner" role="status" hidden>
        <?php echo e($lang === 'en' ? 'Synced successfully' : 'تمت المزامنة بنجاح'); ?>
    </div>
    <?php if ($pageError !== ''): ?>
        <p style="color:#dd4b39;font-weight:700"><?php echo e($pageError); ?></p>
    <?php endif; ?>
    <?php if (!$sasReady): ?>
        <p><?php echo e($lang === 'en' ? 'Enable SAS in settings first.' : 'فعّل ربط SAS من الإعدادات أولاً.'); ?>
            <a href="settings.php?tab=sas"><?php echo e($lang === 'en' ? 'SAS settings' : 'إعدادات SAS'); ?></a>
        </p>
    <?php endif; ?>

    <div class="sas-legend">
        <span><i class="status-sq status-online"></i> <?php echo e($lang === 'en' ? 'Active + connected' : 'فعال ومتصل'); ?></span>
        <span><i class="status-sq status-active"></i> <?php echo e($lang === 'en' ? 'Active' : 'فعال غير متصل'); ?></span>
        <span><i class="status-sq status-expired"></i> <?php echo e($lang === 'en' ? 'Expired' : 'منتهي'); ?></span>
        <span><i class="status-sq status-expired-online"></i> <?php echo e($lang === 'en' ? 'Expired + connected' : 'منتهي ومتصل'); ?></span>
        <span><i class="status-sq status-left"></i> <?php echo e($lang === 'en' ? 'Disabled' : 'معطل'); ?></span>
    </div>

    <div class="sas-table-card">
        <div class="sas-table-headbar">
            <div class="sas-headbar-lead">
            <div class="sas-headbar-title">
                <span class="sas-headbar-name"><?php echo e($lang === 'en' ? 'Subscribers' : 'المشتركين'); ?></span>
                <span class="sas-found" id="sasFoundLabel">
                    <?php echo $lang === 'en'
                        ? ('Found ' . (int) $totalRows . ' record(s)')
                        : ('عُثر على ' . (int) $totalRows . ' قيد'); ?>
                </span>
            </div>
            <div class="subs-ops-side" style="display:flex;align-items:center;gap:8px">
                <div class="subs-ops-anchor" id="opsAnchor">
                    <button type="button" class="btn ops-top-btn" id="openOpsBtn" aria-haspopup="true" aria-expanded="false"><?php echo e($lang === 'en' ? 'Actions' : 'العمليات'); ?></button>
                </div>
            </div>
            </div>
            <form method="get" action="sas.php" id="subsSearchForm" class="sas-search-wrap" autocomplete="off">
                <?php if ($perPageRaw !== '10'): ?>
                    <input type="hidden" name="per_page" value="<?php echo e($perPageRaw); ?>">
                <?php endif; ?>
                <?php if ($subFilter !== ''): ?>
                    <input type="hidden" name="sub" value="<?php echo e($subFilter); ?>">
                <?php endif; ?>
                <?php if ($parentFilter !== ''): ?>
                    <input type="hidden" name="parent" value="<?php echo e($parentFilter); ?>">
                <?php endif; ?>
                <span class="sas-search-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="15" height="15"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                </span>
                <input id="filterInput" name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e($lang === 'en' ? 'Search username, name, or number…' : 'اسم الدخول أو الاسم أو الرقم'); ?>" autocomplete="off">
                <?php if ($q !== ''): ?>
                    <a class="sas-search-clear" href="<?php echo e(sas_filter_href($subFilter, $parentFilter, '', $perPageRaw)); ?>" title="<?php echo e($lang === 'en' ? 'Clear' : 'مسح'); ?>">×</a>
                <?php endif; ?>
            </form>
            <div class="sas-table-tools">
                <button type="button" class="tool-ico" id="colsToggleBtn" title="<?php echo e($lang === 'en' ? 'Columns' : 'الأعمدة'); ?>">
                    <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M4 5h4v14H4zm6 0h4v14h-4zm6 0h4v14h-4z"/></svg>
                </button>
                <button type="button" class="tool-ico" id="autoRefreshBtn" title="<?php echo e($lang === 'en' ? 'Auto refresh' : 'تحديث تلقائي'); ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M6 6h2.4v12H6zm3.6 0H12v12H9.6zM14 12a5 5 0 1 1 1.5 3.5L14 14.2V12zm2 0a3 3 0 1 0 2.1 1.2L16.8 12H16z"/></svg>
                </button>
                <button type="button" class="tool-ico" id="filterToggleBtn" title="<?php echo e($lang === 'en' ? 'Filter' : 'فلترة'); ?>">
                    <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M3 5h18l-7 8v5l-4 2v-7z"/></svg>
                </button>
                <button type="button" class="tool-ico" id="refreshTableBtn" title="<?php echo e($lang === 'en' ? 'Refresh from SAS' : 'تحديث من الساس'); ?>">
                    <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M12 6V3L8 7l4 4V8a4 4 0 1 1-4 4H6a6 6 0 1 0 6-6z"/></svg>
                </button>
            </div>
        </div>
        <div class="sas-refresh-hint" id="sasRefreshHint"></div>

    <div class="table-wrap">
        <table id="subsTable" class="data-table">
            <thead>
            <tr>
                <th class="sub-check-cell"><label class="th-check-only"><input type="checkbox" id="subCheckAll"></label></th>
                <th class="col-num">#</th>
                <th class="status-cell col-status"><?php echo e($lang === 'en' ? 'Status' : 'الحالة'); ?></th>
                <th class="col-user"><?php echo sas_sort_link('username', $lang === 'en' ? 'Username' : 'اسم الدخول', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-ip"><?php echo sas_sort_link('ip', 'IP Address', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-fn"><?php echo sas_sort_link('firstname', $lang === 'en' ? 'First Name' : 'الاسم الأول', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-ln"><?php echo sas_sort_link('lastname', $lang === 'en' ? 'Last Name' : 'الاسم الثاني', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-phone"><?php echo e($lang === 'en' ? 'Phone' : 'الهاتف'); ?></th>
                <th class="col-exp"><?php echo sas_sort_link('expire', $lang === 'en' ? 'Expiration' : 'تاريخ الانتهاء', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-parent"><?php echo sas_sort_link('parent', $lang === 'en' ? 'Parent' : 'تابع الى', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-pkg"><?php echo sas_sort_link('package', $lang === 'en' ? 'Profile' : 'الباقة', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-rent"><?php echo sas_sort_link('rent', $lang === 'en' ? 'Rental' : 'الإيجار', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-debt"><?php echo sas_sort_link('debt', $lang === 'en' ? 'Debts' : 'الديون', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-traf" title="<?php echo e($lang === 'en' ? 'Daily traffic' : 'الاستهلاك اليومي'); ?>"><?php echo e($lang === 'en' ? 'Daily' : 'الاستهلاك'); ?></th>
                <th class="col-days" title="<?php echo e($lang === 'en' ? 'Remaining days' : 'الأيام المتبقية'); ?>"><?php echo sas_sort_link('days', $lang === 'en' ? 'Days left' : 'المتبقي', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
            </tr>
            </thead>
            <tbody id="subsTableBody">
            <?php
            $n = $offset + 1;
            if (!$rows) {
                $emptyMsg = ($sasReady && $cacheCount <= 0)
                    ? ($lang === 'en' ? 'Loading users from SAS…' : 'جاري جلب المشتركين من الساس…')
                    : ($lang === 'en' ? 'No SAS users in cache yet' : 'ماكو مشتركين من الساس بعد');
                echo '<tr><td colspan="15">' . e($emptyMsg) . '</td></tr>';
            }
            foreach ($rows as $row) {
                echo sas_render_table_row($row, $n++, $config, $lang);
            }
            ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1 || $perPageRaw !== 'all'): ?>
        <div class="actions" id="subsPager" style="margin-top:14px">
            <?php
            $baseQs = array();
            if ($q !== '') {
                $baseQs[] = 'q=' . urlencode($q);
            }
            if ($subFilter !== '') {
                $baseQs[] = 'sub=' . urlencode($subFilter);
            }
            if ($parentFilter !== '') {
                $baseQs[] = 'parent=' . urlencode($parentFilter);
            }
            if ($perPageRaw !== '10') {
                $baseQs[] = 'per_page=' . urlencode($perPageRaw);
            }
            $baseStr = count($baseQs) ? '&' . implode('&', $baseQs) : '';
            $extraQs = $baseStr;
            for ($p = 1; $p <= $totalPages; $p++):
            ?>
                <a class="btn <?php echo $p === $page ? '' : 'ghost'; ?> sm" href="?page=<?php echo $p . $baseStr; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
            <?php if (!$showAll): ?>
                <a class="btn ghost sm" href="?per_page=all<?php echo $extraQs; ?>"><?php echo e(t('show_all')); ?></a>
            <?php else: ?>
                <a class="btn ghost sm" href="?per_page=10<?php echo $extraQs; ?>">10</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </div>
</div>

<div class="ops-dropdown hidden" id="opsDropdown" role="menu">
    <button type="button" class="ops-item" data-ops="new" id="opsItemNew"><?php echo e($lang === 'en' ? '+ New' : '+ مشترك جديد'); ?></button>
    <div class="ops-item" id="opsItemHint" style="cursor:default;color:#64748b"><?php echo e($lang === 'en' ? 'Select a subscriber first' : 'حدد مشتركاً من الجدول أولاً'); ?></div>
    <button type="button" class="ops-item" data-ops="open" id="opsItemOpen" hidden><?php echo e($lang === 'en' ? 'Edit' : 'تعديل'); ?></button>
    <button type="button" class="ops-item" data-ops="activate" id="opsItemActivate" hidden><?php echo e(t('activate')); ?></button>
    <button type="button" class="ops-item" data-ops="change_profile" id="opsItemProfile" hidden><?php echo e($lang === 'en' ? 'Change package' : 'تغيير نوع الاشتراك'); ?></button>
    <button type="button" class="ops-item" data-ops="enable" id="opsItemEnable" hidden><?php echo e($lang === 'en' ? 'Enable' : 'تشغيل'); ?></button>
    <button type="button" class="ops-item" data-ops="disable" id="opsItemDisable" hidden><?php echo e($lang === 'en' ? 'Disable' : 'إيقاف'); ?></button>
    <button type="button" class="ops-item" data-ops="give_test" id="opsItemGiveTest" hidden><?php echo e(t('give_test')); ?></button>
    <button type="button" class="ops-item" data-ops="bulk_activate" id="opsItemBulkActivate" hidden><?php echo e(t('bulk_activate')); ?></button>
    <button type="button" class="ops-item" data-ops="pay" id="opsItemPay" hidden><?php echo e($lang === 'en' ? 'Debts' : 'الديون'); ?></button>
    <button type="button" class="ops-item" data-ops="remind_debt" id="opsItemRemind" hidden><?php echo e($lang === 'en' ? 'Send WhatsApp notice' : 'إرسال إشعار واتساب'); ?></button>
    <button type="button" class="ops-item" data-ops="remind_days" id="opsItemDays" hidden><?php echo e($lang === 'en' ? 'Send days left' : 'إرسال الأيام المتبقية'); ?></button>
    <button type="button" class="ops-item" data-ops="retry" id="opsItemRetry" hidden><?php echo e(t('retry_send')); ?></button>
</div>
<div class="ops-dropdown cols-dropdown hidden" id="colsDropdown">
    <div class="sas-filter-label"><?php echo e($lang === 'en' ? 'Show / reorder columns' : 'إظهار وترتيب الأعمدة'); ?></div>
    <div class="cols-check ops-item" data-col-row="num">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="num" checked> #</label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="status">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="status" checked> <?php echo e($lang === 'en' ? 'Status' : 'الحالة'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="user">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="user" checked> <?php echo e($lang === 'en' ? 'Username' : 'اسم الدخول'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="ip">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="ip" checked> IP Address</label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="fn">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="fn" checked> <?php echo e($lang === 'en' ? 'First Name' : 'الاسم الأول'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="ln">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="ln" checked> <?php echo e($lang === 'en' ? 'Last Name' : 'الاسم الأخير'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="phone">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="phone" checked> <?php echo e($lang === 'en' ? 'Phone' : 'الهاتف'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="exp">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="exp" checked> <?php echo e($lang === 'en' ? 'Expiration' : 'الانتهاء'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="parent">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="parent" checked> <?php echo e($lang === 'en' ? 'Parent' : 'تابع الى'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="pkg">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="pkg" checked> <?php echo e($lang === 'en' ? 'Profile' : 'البروفايل'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="rent">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="rent" checked> <?php echo e($lang === 'en' ? 'Rental' : 'الإيجار'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="debt">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="debt" checked> <?php echo e($lang === 'en' ? 'Debts' : 'الديون'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="traf">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="traf" checked> <?php echo e($lang === 'en' ? 'Daily Traffic' : 'الترافيك اليومي'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
    <div class="cols-check ops-item" data-col-row="days">
        <span class="cols-handle" draggable="true" title="<?php echo e($lang === 'en' ? 'Drag to reorder' : 'اسحب للترتيب'); ?>">⋮⋮</span>
        <label><input type="checkbox" data-col="days" checked> <?php echo e($lang === 'en' ? 'Remaining Days' : 'الأيام المتبقية'); ?></label>
        <button type="button" class="cols-shift" data-dir="-1" aria-label="up">▲</button>
        <button type="button" class="cols-shift" data-dir="1" aria-label="down">▼</button>
    </div>
</div>
<div class="ops-dropdown hidden" id="filterDropdown">
    <div class="sas-filter-label"><?php echo e($lang === 'en' ? 'Status' : 'الحالة'); ?></div>
    <a class="ops-item<?php echo $subFilter === '' ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href('', $parentFilter, $q, $perPageRaw)); ?>"><?php echo e($lang === 'en' ? 'All' : 'الكل'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'active' ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href('active', $parentFilter, $q, $perPageRaw)); ?>"><?php echo e($lang === 'en' ? 'Active' : 'فعال'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'online' ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href('online', $parentFilter, $q, $perPageRaw)); ?>"><?php echo e($lang === 'en' ? 'Connected' : 'متصل حاليا'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'expired' ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href('expired', $parentFilter, $q, $perPageRaw)); ?>"><?php echo e($lang === 'en' ? 'Expired' : 'منتهي'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'disabled' ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href('disabled', $parentFilter, $q, $perPageRaw)); ?>"><?php echo e($lang === 'en' ? 'Disabled' : 'معطل'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'soon' ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href('soon', $parentFilter, $q, $perPageRaw)); ?>"><?php echo e($lang === 'en' ? 'Expiring soon' : 'قرب ينتهي'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'today' ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href('today', $parentFilter, $q, $perPageRaw)); ?>"><?php echo e($lang === 'en' ? 'Ends today' : 'ينتهي اليوم'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'debt' ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href('debt', $parentFilter, $q, $perPageRaw)); ?>"><?php echo e($lang === 'en' ? 'Has debt' : 'عليهم دين'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'rental' ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href('rental', $parentFilter, $q, $perPageRaw)); ?>"><?php echo e($lang === 'en' ? 'Has rental' : 'عليهم إيجار'); ?></a>
    <div class="sas-filter-label"><?php echo e($lang === 'en' ? 'Parent' : 'تابع الى'); ?></div>
    <a class="ops-item<?php echo $parentFilter === '' ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href($subFilter, '', $q, $perPageRaw)); ?>"><?php echo e($lang === 'en' ? 'All parents' : 'كل التوابع'); ?></a>
    <div class="sas-filter-parents">
        <?php foreach ($parentNames as $pn): ?>
            <a class="ops-item<?php echo $parentFilter === (string) $pn ? ' is-on' : ''; ?>" href="<?php echo e(sas_filter_href($subFilter, (string) $pn, $q, $perPageRaw)); ?>"><?php echo e((string) $pn); ?></a>
        <?php endforeach; ?>
        <?php if (!$parentNames): ?>
            <div class="ops-item" style="cursor:default;color:#94a3b8"><?php echo e($lang === 'en' ? 'No parents yet' : 'ماكو تابع الى بعد'); ?></div>
        <?php endif; ?>
    </div>
</div>
<div class="ops-dropdown hidden" id="autoRefreshDropdown">
    <button type="button" class="ops-item" data-refresh="0"><?php echo e($lang === 'en' ? 'Off' : 'إيقاف'); ?></button>
    <button type="button" class="ops-item" data-refresh="30"><?php echo e($lang === 'en' ? 'Every 30s' : 'كل 30 ثانية'); ?></button>
    <button type="button" class="ops-item" data-refresh="60"><?php echo e($lang === 'en' ? 'Every 1 min' : 'كل دقيقة'); ?></button>
    <button type="button" class="ops-item" data-refresh="180"><?php echo e($lang === 'en' ? 'Every 3 min' : 'كل 3 دقائق'); ?></button>
    <button type="button" class="ops-item" data-refresh="300"><?php echo e($lang === 'en' ? 'Every 5 min' : 'كل 5 دقائق'); ?></button>
</div>

<div class="modal-backdrop hidden" id="opsBulkModal">
    <div class="modal-card ops-modal-card">
        <div class="ops-modal-head">
            <h3><?php echo e(t('bulk_activate')); ?></h3>
            <button type="button" class="btn ghost sm" id="opsBulkModalClose">×</button>
        </div>
        <ul class="ops-selected-list" id="opsSelectedList"></ul>
        <div class="ops-section">
            <div class="pay-mode-label"><?php echo e(t('pay_mode')); ?></div>
            <div class="pay-mode-row" id="opsPayModeRow">
                <label class="pay-mode-option">
                    <input type="radio" name="pay_mode" value="cash" form="opsBulkActivateForm" checked>
                    <span class="pay-mode-card cash"><strong><?php echo e(t('pay_cash')); ?></strong></span>
                </label>
                <label class="pay-mode-option">
                    <input type="radio" name="pay_mode" value="credit" form="opsBulkActivateForm">
                    <span class="pay-mode-card credit"><strong><?php echo e(t('pay_credit')); ?></strong></span>
                </label>
            </div>
            <form method="post" id="opsBulkActivateForm" class="ops-activate-form">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="bulk_activate">
                <label class="toggle" style="margin:12px 0">
                    <input type="checkbox" name="send_whatsapp" value="1" checked>
                    <span class="toggle-ui"></span>
                    <span class="toggle-text"><?php echo e(t('send_message')); ?></span>
                </label>
                <button class="btn" type="submit" style="width:100%"><?php echo e(t('bulk_activate')); ?></button>
            </form>
        </div>
    </div>
</div>

<form method="post" id="opsRemindOneForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="remind_debt">
    <input type="hidden" name="id" value="">
</form>
<form method="post" id="opsBulkRemindForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="bulk_remind_debt">
</form>
<form method="post" id="opsDaysForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="remind_days">
    <input type="hidden" name="id" value="">
</form>
<form method="post" id="opsRetryForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="retry_message">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="log_id" value="">
</form>
<form method="post" id="opsGiveTestForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="give_test">
    <input type="hidden" name="id" value="">
</form>

<div class="modal-backdrop hidden" id="sasActModal">
    <div class="modal-card ops-modal-card sas-act-card">
        <div class="ops-modal-head">
            <div>
                <h3 id="sasActTitle"><?php echo e(t('activate')); ?></h3>
                <div class="sas-act-who" id="sasActWho">
                    <div class="sas-act-name" id="sasActName"></div>
                    <div class="sas-act-user">
                        <span id="sasActUser"></span>
                        <button type="button" class="sas-act-copy" id="sasActCopy" title="<?php echo e($lang === 'en' ? 'Copy username' : 'نسخ اسم الدخول'); ?>">⧉</button>
                    </div>
                </div>
            </div>
            <button type="button" class="btn ghost sm" id="sasActClose">×</button>
        </div>
        <div class="sas-act-body">
        <label class="sas-act-field"><?php echo e($lang === 'en' ? 'Package' : 'نوع الاشتراك'); ?>
            <select id="sasActProfile"></select>
        </label>
        <div class="sas-seg" role="radiogroup" aria-label="<?php echo e($lang === 'en' ? 'Activation source' : 'مصدر التفعيل'); ?>">
            <label class="sas-seg-opt">
                <input type="radio" name="sas_act_mode" value="card" checked>
                <span><?php echo e($lang === 'en' ? 'By card' : 'تفعيل بالبطاقة'); ?></span>
            </label>
            <label class="sas-seg-opt">
                <input type="radio" name="sas_act_mode" value="credit">
                <span><?php echo e($lang === 'en' ? 'By balance' : 'تفعيل بالرصيد'); ?></span>
            </label>
        </div>
        <div id="sasActCardBox">
            <select id="sasActCardSelect"></select>
            <div class="sas-sync-note" id="sasActCardHint"><?php echo e($lang === 'en' ? 'Unused cards' : 'الكروت الشاغرة'); ?></div>
        </div>
        <div id="sasActCreditBox" class="hidden">
            <input type="hidden" id="sasActUnits" value="1">
        </div>
        <div class="pay-mode-box">
            <div class="pay-mode-label"><?php echo e(t('pay_mode')); ?></div>
            <div class="pay-mode-row">
                <label class="pay-mode-option">
                    <input type="radio" name="sas_pay_mode" value="cash" checked>
                    <span class="pay-mode-card cash">
                        <strong><?php echo e(t('pay_cash')); ?></strong>
                        <small><?php echo e(t('pay_cash_hint')); ?></small>
                    </span>
                </label>
                <label class="pay-mode-option">
                    <input type="radio" name="sas_pay_mode" value="credit">
                    <span class="pay-mode-card credit">
                        <strong><?php echo e(t('pay_credit')); ?></strong>
                        <small><?php echo e(t('pay_credit_hint')); ?></small>
                    </span>
                </label>
            </div>
        </div>
        <div class="sas-act-debts is-empty" id="sasActDebtsBox">
            <div class="d-head"><span><?php echo e(t('old_debts')); ?></span><strong id="sasActOldSum"></strong></div>
            <p class="d-empty" id="sasActNoDebt"><?php echo e(t('no_old_debts')); ?></p>
            <ul id="sasActDebtList"></ul>
            <div class="d-row" id="sasActNewRow">
                <span><?php echo e(t('this_activation')); ?></span>
                <strong id="sasActNewAmt"></strong>
            </div>
            <div class="d-row d-total" id="sasActGrandRow">
                <span><?php echo e(t('after_activate')); ?></span>
                <strong id="sasActGrandAmt"></strong>
            </div>
        </div>
        <div class="quick-msg-box sas-act-wa">
            <label class="toggle">
                <input type="checkbox" id="sasActSendWa" value="1" checked>
                <span class="toggle-ui"></span>
                <span class="toggle-text"><?php echo e(t('send_wa_notice')); ?></span>
            </label>
            <label class="toggle" id="sasActOldDebtsBox" style="display:none">
                <input type="checkbox" id="sasActOldDebts" value="1" checked>
                <span class="toggle-ui"></span>
                <span class="toggle-text"><?php echo e(t('include_old_debts')); ?></span>
            </label>
        </div>
        <p class="sas-modal-err" id="sasActErr"></p>
        </div>
        <div class="sas-act-foot">
            <button type="button" class="btn" id="sasActSubmit"><?php echo e($lang === 'en' ? 'Activate' : 'تفعيل'); ?></button>
        </div>
        <div class="sas-act-lock hidden" id="sasActLock" aria-hidden="true">
            <div class="sas-act-lock-spin"></div>
            <div class="sas-act-lock-txt"><?php echo e($lang === 'en' ? 'Activating…' : 'جاري التفعيل...'); ?></div>
        </div>
    </div>
</div>
<div class="modal-backdrop hidden" id="sasProfModal">
    <div class="modal-card ops-modal-card">
        <div class="ops-modal-head">
            <h3><?php echo e($lang === 'en' ? 'Change package' : 'تغيير نوع الاشتراك'); ?></h3>
            <button type="button" class="btn ghost sm" id="sasProfClose">×</button>
        </div>
        <p class="sas-sync-note" id="sasProfWho"></p>
        <p class="sas-sync-note" id="sasProfCurrent" style="font-weight:700;color:#333"></p>
        <label><?php echo e($lang === 'en' ? 'New package' : 'الاشتراك الجديد'); ?>
            <select id="sasProfSelect" style="width:100%;margin-top:4px"></select>
        </label>
        <p class="sas-modal-err" id="sasProfErr"></p>
        <div style="display:flex;gap:8px;margin-top:12px">
            <button type="button" class="btn" id="sasProfSubmit" style="flex:1"><?php echo e($lang === 'en' ? 'Save' : 'حفظ'); ?></button>
            <button type="button" class="btn ghost" id="sasProfCancel" style="flex:1"><?php echo e($lang === 'en' ? 'Cancel' : 'إلغاء'); ?></button>
        </div>
    </div>
</div>

<script>
(function () {
  var filter = document.getElementById('filterInput');
  var searchForm = document.getElementById('subsSearchForm');
  var tbody = document.getElementById('subsTableBody');
  var pager = document.getElementById('subsPager');
  var liveTimer = null;
  var originalHtml = tbody ? tbody.innerHTML : '';
  var actBill = { old_sum: 0, old_lines: [], charge: 0, currency: <?php echo json_encode(isset($config['currency']) ? $config['currency'] : 'د.ع'); ?> };
  var checkAll = document.getElementById('subCheckAll');
  var opsBtn = document.getElementById('openOpsBtn');
  var opsDrop = document.getElementById('opsDropdown');
  var bulkCount = document.getElementById('bulkSelectedCount');
  var bulkModal = document.getElementById('opsBulkModal');
  var countLabel = <?php echo json_encode($lang === 'en' ? 'selected' : 'محدد'); ?>;
  var totalCount = <?php echo (int) $totalRows; ?>;
  syncBulk();
  var confirmTest = <?php echo json_encode(t('confirm_give_test')); ?>;
  var stale = <?php echo json_encode($syncMode === 'stale'); ?>;
  var refreshSec = <?php echo (int) $savedRefreshSec; ?>;
  try {
    var rs = localStorage.getItem('sas_refresh_sec_v1');
    if (rs !== null) {
      var rn = parseInt(rs, 10);
      if (!isNaN(rn) && rn >= 0) refreshSec = rn;
    }
  } catch (e) {}
  var refreshTimer = null;
  var liveBusy = false;
  var COLS_KEY = 'sas_table_cols_v1';
  var ORDER_KEY = 'sas_table_col_order_v1';
  var DEFAULT_COL_ORDER = ['num','status','user','ip','fn','ln','phone','exp','parent','pkg','rent','debt','traf','days'];
  function loadLocalCols() {
    try {
      var raw = localStorage.getItem(COLS_KEY);
      if (!raw) return {};
      var o = JSON.parse(raw);
      return (o && typeof o === 'object' && !Array.isArray(o)) ? o : {};
    } catch (e) { return {}; }
  }
  function saveLocalCols() {
    try { localStorage.setItem(COLS_KEY, JSON.stringify(savedCols || {})); } catch (e) {}
  }
  function mergeColOrder(saved) {
    var seen = {};
    var out = [];
    if (saved && saved.length) {
      saved.forEach(function (k) {
        if (DEFAULT_COL_ORDER.indexOf(k) !== -1 && !seen[k]) {
          seen[k] = 1;
          out.push(k);
        }
      });
    }
    DEFAULT_COL_ORDER.forEach(function (k) {
      if (!seen[k]) out.push(k);
    });
    return out;
  }
  function loadColOrder() {
    try {
      var a = JSON.parse(localStorage.getItem(ORDER_KEY) || '[]');
      return mergeColOrder(Array.isArray(a) ? a : []);
    } catch (e) { return DEFAULT_COL_ORDER.slice(); }
  }
  function saveColOrder() {
    try { localStorage.setItem(ORDER_KEY, JSON.stringify(colOrder || [])); } catch (e) {}
  }
  var savedCols = loadLocalCols();
  var colOrder = loadColOrder();
  var liveQs = <?php echo json_encode(array(
      'q' => $q,
      'sub' => $subFilter,
      'parent' => $parentFilter,
      'sort' => $sortKey,
      'dir' => $sortDir,
      'page' => (string) $page,
      'per_page' => $perPageRaw,
  )); ?>;
  var csrf = <?php echo json_encode(csrf_token()); ?>;
  var rentalDevices = <?php echo json_encode(function_exists('rental_devices_list') ? rental_devices_list() : array()); ?>;
  var rentNoneLabel = <?php echo json_encode($lang === 'en' ? 'No rental' : 'بدون إيجار'); ?>;
  var actUser = null;
  var profilesCache = null;
  var cardsCacheAll = null;
  var cardsPrefetch = null;

  function actModalOpen() {
    var m = document.getElementById('sasActModal');
    var p = document.getElementById('sasProfModal');
    return (m && !m.classList.contains('hidden')) || (p && !p.classList.contains('hidden'));
  }
  function reloadIfIdle() {
    if (actModalOpen()) return false;
    refreshTableLive();
    return true;
  }

  function visibleChecks() {
    return tbody ? Array.prototype.slice.call(tbody.querySelectorAll('input.sub-check')) : [];
  }
  function selectedRows() {
    var out = [];
    visibleChecks().forEach(function (c) {
      if (!c.checked) return;
      var tr = c.closest('tr');
      if (!tr) return;
      out.push({
        id: tr.getAttribute('data-id'),
        sasId: tr.getAttribute('data-sas-id') || '0',
        profileId: tr.getAttribute('data-profile-id') || '0',
        profileName: tr.getAttribute('data-profile-name') || '',
        enabled: tr.getAttribute('data-enabled') === '1',
        localId: tr.getAttribute('data-local-id') || '0',
        name: tr.getAttribute('data-name') || '',
        firstname: tr.getAttribute('data-firstname') || '',
        username: tr.getAttribute('data-username') || tr.getAttribute('data-id') || '',
        debt: tr.getAttribute('data-debt') === '1',
        hasDays: tr.getAttribute('data-has-days') === '1',
        msgFail: tr.getAttribute('data-msg-fail') === '1',
        logId: tr.getAttribute('data-log-id') || '0',
        tr: tr
      });
    });
    return out;
  }
  function syncBulk() {
    var list = visibleChecks();
    var n = 0;
    list.forEach(function (c) { if (c.checked) n++; });
    if (bulkCount) bulkCount.textContent = n > 0 ? (n + ' ' + countLabel) : String(totalCount);
    if (checkAll && list.length) {
      checkAll.checked = n > 0 && n === list.length;
      checkAll.indeterminate = n > 0 && n < list.length;
    }
  }
  function fillHiddenIds(form) {
    if (!form) return;
    Array.prototype.slice.call(form.querySelectorAll('input.ops-id-dyn')).forEach(function (el) {
      el.parentNode.removeChild(el);
    });
    selectedRows().forEach(function (r) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'ids[]';
      inp.value = r.id;
      inp.className = 'ops-id-dyn';
      form.appendChild(inp);
    });
  }
  function showEl(el, on) {
    if (!el) return;
    el.hidden = !on;
    if (on) el.removeAttribute('hidden');
  }
  function closeMenus() {
    document.querySelectorAll('.ops-dropdown').forEach(function (d) {
      d.classList.add('hidden');
      d.classList.remove('ops-float');
      d.style.left = '';
      d.style.top = '';
      d.style.visibility = '';
    });
    if (opsBtn) opsBtn.setAttribute('aria-expanded', 'false');
  }
  function placeMenu(drop, anchor) {
    if (!drop || !anchor) return;
    drop.classList.remove('hidden');
    var r = anchor.getBoundingClientRect();
    drop.style.position = 'fixed';
    drop.style.top = Math.round(r.bottom + 6) + 'px';
    drop.style.left = Math.round(r.left) + 'px';
  }
  function placeMenuAt(el, clientX, clientY, anchorBtn) {
    if (!el) return;
    if (el.parentNode !== document.body) document.body.appendChild(el);
    el.classList.add('ops-float');
    el.classList.remove('hidden');
    el.style.position = 'fixed';
    el.style.visibility = 'hidden';
    el.style.left = '0px';
    el.style.top = '0px';
    el.style.right = 'auto';
    var w = el.offsetWidth || 220;
    var h = el.offsetHeight || 260;
    var x, y;
    if (typeof clientX === 'number' && typeof clientY === 'number') {
      x = clientX;
      y = clientY;
      if (x + w > window.innerWidth - 12) x = clientX - w;
      if (x < 8) x = 8;
      if (y + h > window.innerHeight - 8) y = window.innerHeight - h - 8;
      if (y < 8) y = 8;
    } else if (anchorBtn) {
      var r = anchorBtn.getBoundingClientRect();
      x = r.left;
      y = r.bottom + 6;
      if (document.documentElement.dir === 'rtl') x = r.right - w;
      if (x < 8) x = 8;
      if (x + w > window.innerWidth - 8) x = window.innerWidth - w - 8;
      if (y + h > window.innerHeight - 8) y = Math.max(8, r.top - h - 6);
    } else {
      x = 24;
      y = 80;
    }
    el.style.left = Math.round(x) + 'px';
    el.style.top = Math.round(y) + 'px';
    el.style.visibility = 'visible';
  }
  function selectOnlyRow(tr) {
    if (!tr) return;
    visibleChecks().forEach(function (c) { c.checked = false; });
    var chk = tr.querySelector('input.sub-check');
    if (chk) chk.checked = true;
    syncBulk();
  }
  function openOpsMenu(clientX, clientY) {
    if (!opsDrop) return;
    updateOps();
    placeMenuAt(opsDrop, clientX, clientY, opsBtn);
    if (opsBtn) opsBtn.setAttribute('aria-expanded', 'true');
  }
  function updateOps() {
    var rows = selectedRows();
    var n = rows.length;
    var one = n === 1 ? rows[0] : null;
    var anyDebt = rows.some(function (r) { return r.debt; });
    showEl(document.getElementById('opsItemHint'), n === 0);
    showEl(document.getElementById('opsItemOpen'), !!one);
    showEl(document.getElementById('opsItemActivate'), !!one);
    showEl(document.getElementById('opsItemProfile'), !!one);
    showEl(document.getElementById('opsItemEnable'), !!(one && !one.enabled));
    showEl(document.getElementById('opsItemDisable'), !!(one && one.enabled));
    showEl(document.getElementById('opsItemGiveTest'), !!one);
    showEl(document.getElementById('opsItemBulkActivate'), n > 1);
    showEl(document.getElementById('opsItemPay'), !!one);
    showEl(document.getElementById('opsItemRemind'), !!one);
    showEl(document.getElementById('opsItemDays'), !!(one && one.hasDays));
    showEl(document.getElementById('opsItemRetry'), !!(one && one.msgFail));
  }
  function runOps(action) {
    var rows = selectedRows();
    var one = rows.length === 1 ? rows[0] : null;
    closeMenus();
    if (action === 'new') {
      window.location.href = 'sas_user.php?new=1';
      return;
    }
    if (action === 'open' && one) {
      window.location.href = 'sas_user.php?u=' + encodeURIComponent(one.id);
      return;
    }
    if (action === 'activate' && one) {
      openActModal(one);
      return;
    }
    if (action === 'change_profile' && one) {
      openProfModal(one);
      return;
    }
    if ((action === 'enable' || action === 'disable') && one) {
      setEnabled(one, action === 'enable');
      return;
    }
    if (action === 'give_test' && one) {
      if (!window.confirm(confirmTest)) return;
      postSas('give_test', { id: one.id }).then(function (d) {
        alert((d && d.message) || <?php echo json_encode($lang === 'en' ? 'Request failed' : 'فشل الطلب'); ?>);
        if (d && d.ok) window.location.reload();
      }).catch(function () {
        alert(<?php echo json_encode($lang === 'en' ? 'Network error' : 'فشل الاتصال'); ?>);
      });
      return;
    }
    if (action === 'bulk_activate') {
      var list = document.getElementById('opsSelectedList');
      if (list) list.innerHTML = rows.map(function (r) { return '<li><strong>' + r.name + '</strong></li>'; }).join('');
      fillHiddenIds(document.getElementById('opsBulkActivateForm'));
      if (bulkModal) bulkModal.classList.remove('hidden');
      return;
    }
    if (action === 'pay') {
      if (one && one.localId && one.localId !== '0') {
        window.location.href = 'debts.php?status=unpaid&subscriber_id=' + encodeURIComponent(one.localId);
      } else if (one) {
        window.location.href = 'debts.php?sas_user=' + encodeURIComponent(one.id);
      }
      return;
    }
    if (action === 'remind_debt') {
      if (one) {
        var f1 = document.getElementById('opsRemindOneForm');
        var id1 = f1 && f1.querySelector('input[name="id"]');
        if (id1) id1.value = one.id;
        if (f1) f1.submit();
      } else {
        var fb = document.getElementById('opsBulkRemindForm');
        fillHiddenIds(fb);
        if (fb) fb.submit();
      }
      return;
    }
    if (action === 'remind_days' && one) {
      var fd = document.getElementById('opsDaysForm');
      var idd = fd && fd.querySelector('input[name="id"]');
      if (idd) idd.value = one.id;
      if (fd) fd.submit();
      return;
    }
    if (action === 'retry' && one) {
      var fr = document.getElementById('opsRetryForm');
      var rid = fr && fr.querySelector('input[name="id"]');
      var lid = fr && fr.querySelector('input[name="log_id"]');
      if (rid) rid.value = one.id;
      if (lid) lid.value = one.logId;
      if (fr) fr.submit();
    }
  }

  if (checkAll) {
    checkAll.addEventListener('change', function () {
      visibleChecks().forEach(function (c) { c.checked = !!checkAll.checked; });
      syncBulk();
    });
  }
  if (tbody) {
    tbody.addEventListener('change', function (e) {
      if (e.target && e.target.classList.contains('sub-check')) syncBulk();
    });
    tbody.addEventListener('contextmenu', function (e) {
      var tr = e.target && e.target.closest ? e.target.closest('tr[data-id]') : null;
      if (!tr || !tbody.contains(tr)) return;
      e.preventDefault();
      e.stopPropagation();
      var chk = tr.querySelector('input.sub-check');
      if (chk && !chk.checked) selectOnlyRow(tr);
      else syncBulk();
      openOpsMenu(e.clientX, e.clientY);
    });
    var lpTimer = null;
    var lpStart = null;
    tbody.addEventListener('touchstart', function (e) {
      var tr = e.target && e.target.closest ? e.target.closest('tr[data-id]') : null;
      if (!tr || !e.touches || !e.touches[0]) return;
      if (e.target.closest('a,button,input,label')) return;
      lpStart = { tr: tr, x: e.touches[0].clientX, y: e.touches[0].clientY };
      clearTimeout(lpTimer);
      lpTimer = setTimeout(function () {
        if (!lpStart) return;
        selectOnlyRow(lpStart.tr);
        openOpsMenu(lpStart.x, lpStart.y);
        lpStart = null;
      }, 520);
    }, { passive: true });
    tbody.addEventListener('touchmove', function () {
      clearTimeout(lpTimer);
      lpStart = null;
    }, { passive: true });
    tbody.addEventListener('touchend', function () {
      clearTimeout(lpTimer);
      lpStart = null;
    });
    tbody.addEventListener('touchcancel', function () {
      clearTimeout(lpTimer);
      lpStart = null;
    });
  }
  if (opsBtn && opsDrop) {
    opsBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var open = opsDrop.classList.contains('hidden');
      closeMenus();
      if (open) openOpsMenu();
    });
  }
  if (opsDrop) {
    opsDrop.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-ops]');
      if (btn) runOps(btn.getAttribute('data-ops'));
    });
  }
  var bulkClose = document.getElementById('opsBulkModalClose');
  if (bulkClose) bulkClose.addEventListener('click', function () {
    if (bulkModal) bulkModal.classList.add('hidden');
  });

  function showAppToast(msg, kind) {
    var b = document.getElementById('sasSyncOkBanner');
    var text = String(msg || '');
    if (!b) {
      if (text) window.alert(text);
      return;
    }
    b.textContent = text;
    b.classList.remove('is-error', 'is-ok');
    b.classList.add(kind === 'error' ? 'is-error' : 'is-ok');
    b.hidden = false;
    if (window._sasToastT) clearTimeout(window._sasToastT);
    window._sasToastT = setTimeout(function () {
      b.hidden = true;
    }, kind === 'error' ? 8000 : 5000);
  }
  function rememberActFlash(msg, kind) {
    try {
      sessionStorage.setItem('sas_flash', JSON.stringify({
        t: String(msg || ''),
        k: kind === 'error' ? 'error' : 'ok'
      }));
    } catch (e) {}
  }
  function postSas(action, fields) {
    var body = new FormData();
    body.append('csrf', csrf);
    body.append('action', action);
    Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
    return fetch('sas.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }
  function fillSelect(sel, items, selected) {
    if (!sel) return;
    sel.innerHTML = '';
    (items || []).forEach(function (p) {
      var o = document.createElement('option');
      o.value = String(p.id);
      o.textContent = p.name || ('#' + p.id);
      if (String(p.id) === String(selected)) o.selected = true;
      sel.appendChild(o);
    });
  }
  function loadProfiles() {
    if (profilesCache) return Promise.resolve(profilesCache);
    if (loadProfiles._p) return loadProfiles._p;
    loadProfiles._p = fetch('sas.php?ajax=profiles', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        profilesCache = (d && d.profiles) ? d.profiles : [];
        return profilesCache;
      })
      .catch(function () {
        loadProfiles._p = null;
        profilesCache = profilesCache || [];
        return profilesCache;
      });
    return loadProfiles._p;
  }
  function applyQuote(d) {
    actBill = {
      old_sum: d && d.old_sum ? Number(d.old_sum) : 0,
      old_lines: (d && d.old_lines) ? d.old_lines : [],
      charge: d && d.charge ? Number(d.charge) : 0,
      currency: (d && d.currency) ? d.currency : actBill.currency
    };
    renderActDebts();
  }
  function loadQuote(username, profileId) {
    return fetch('sas.php?ajax=quote&username=' + encodeURIComponent(username || '') + '&profile_id=' + encodeURIComponent(profileId || '0'), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { applyQuote(d); return d; })
      .catch(function () { return null; });
  }
  function prefetchCards(force) {
    if (!force && cardsPrefetch) return cardsPrefetch;
    cardsPrefetch = fetch('sas.php?ajax=cards&preload=1' + (force ? '&refresh=1' : ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        cardsCacheAll = (d && d.cards) ? d.cards : [];
        return cardsCacheAll;
      })
      .catch(function () {
        cardsPrefetch = null;
        if (!cardsCacheAll) cardsCacheAll = [];
        return cardsCacheAll;
      });
    return cardsPrefetch;
  }
  function cardsForProfile(profileId, profileName) {
    var all = cardsCacheAll || [];
    if (!all.length) return [];
    var pid = String(profileId || '0');
    var pn = String(profileName || '').toLowerCase();
    var hit = [];
    all.forEach(function (c) {
      if (pid !== '0' && String(c.profile_id || '0') === pid) hit.push(c);
      else if (pn && String(c.profile_name || '').toLowerCase().indexOf(pn) !== -1) hit.push(c);
    });
    return hit.length ? hit : all;
  }
  function loadCards(username, profileId, profileName) {
    var cached = cardsForProfile(profileId, profileName);
    if (cardsCacheAll) {
      loadQuote(username, profileId);
      return Promise.resolve(cached);
    }
    return prefetchCards().then(function () {
      loadQuote(username, profileId);
      return cardsForProfile(profileId, profileName);
    });
  }
  function moneyAct(n) {
    var cur = actBill.currency || '';
    try { return Math.round(Number(n) || 0).toLocaleString('en-US') + ' ' + cur; }
    catch (e) { return Math.round(Number(n) || 0) + ' ' + cur; }
  }
  function renderActDebts() {
    var empty = document.getElementById('sasActNoDebt');
    var list = document.getElementById('sasActDebtList');
    var oldSumEl = document.getElementById('sasActOldSum');
    var newAmt = document.getElementById('sasActNewAmt');
    var grandAmt = document.getElementById('sasActGrandAmt');
    var oldSum = actBill.old_sum || 0;
    var charge = actBill.charge || 0;
    var lines = actBill.old_lines || [];
    if (oldSumEl) oldSumEl.textContent = oldSum > 0 ? moneyAct(oldSum) : '';
    if (list) {
      list.innerHTML = '';
      lines.forEach(function (od) {
        var li = document.createElement('li');
        var lab = (od.label || '') + (od.notes ? (' — ' + od.notes) : '');
        li.innerHTML = '<span></span><strong></strong>';
        li.querySelector('span').textContent = lab;
        li.querySelector('strong').textContent = moneyAct(od.amount);
        list.appendChild(li);
      });
    }
    if (empty) empty.style.display = oldSum > 0 ? 'none' : 'block';
    if (list) list.style.display = oldSum > 0 ? 'block' : 'none';
    if (newAmt) newAmt.textContent = moneyAct(charge);
    if (grandAmt) grandAmt.textContent = moneyAct(oldSum + charge);
    var debtsBox = document.getElementById('sasActDebtsBox');
    if (debtsBox) {
      if (oldSum > 0) debtsBox.classList.remove('is-empty');
      else debtsBox.classList.add('is-empty');
    }
    syncActWa();
  }
  function selectedProfileName(sel) {
    if (!sel || !sel.options || sel.selectedIndex < 0) return '';
    var opt = sel.options[sel.selectedIndex];
    return opt ? String(opt.textContent || '').replace(/^\s+|\s+$/g, '') : '';
  }
  function syncActWa() {
    var wa = document.getElementById('sasActSendWa');
    var box = document.getElementById('sasActOldDebtsBox');
    var inp = document.getElementById('sasActOldDebts');
    if (!wa || !box) return;
    var hasOld = !!(actBill && actBill.old_sum > 0);
    var on = !!wa.checked && hasOld;
    box.style.display = on ? 'flex' : 'none';
    if (!inp) return;
    inp.disabled = !on;
    if (!on) inp.checked = false;
    else if (!inp.dataset.userTouched) inp.checked = true;
  }
  function renderCards(cards) {
    var sel = document.getElementById('sasActCardSelect');
    var hint = document.getElementById('sasActCardHint');
    if (!sel) return;
    sel.innerHTML = '';
    if (!cards.length) {
      var empty = document.createElement('option');
      empty.value = '';
      empty.textContent = <?php echo json_encode($lang === 'en' ? 'No unused cards' : 'ماكو كروت شاغرة'); ?>;
      sel.appendChild(empty);
      sel.disabled = true;
      if (hint) {
        hint.className = 'sas-sync-note';
        hint.textContent = <?php echo json_encode($lang === 'en' ? 'No unused cards for this profile' : 'ماكو كروت شاغرة لهذه الفئة'); ?>;
      }
      return;
    }
    sel.disabled = false;
    cards.forEach(function (c, i) {
      var o = document.createElement('option');
      o.value = String(c.pin || '');
      o.setAttribute('data-card-id', String(c.id || 0));
      o.textContent = (c.pin || c.label || '') + (c.profile_name ? (' — ' + c.profile_name) : '');
      if (i === 0) o.selected = true;
      sel.appendChild(o);
    });
    if (hint) {
      hint.className = 'sas-sync-note is-ok';
      hint.textContent = '';
    }
  }
  function currentActMode() {
    var on = document.querySelector('#sasActModal input[name="sas_act_mode"]:checked');
    return (on && on.value === 'card') ? 'card' : 'credit';
  }
  function setActMode(mode) {
    var want = (mode === 'card') ? 'card' : 'credit';
    document.querySelectorAll('#sasActModal input[name="sas_act_mode"]').forEach(function (el) {
      el.checked = el.value === want;
    });
    toggleActMode();
  }
  function toggleActMode() {
    var card = currentActMode() === 'card';
    var boxC = document.getElementById('sasActCardBox');
    if (boxC) boxC.classList.toggle('hidden', !card);
  }
  function openActModal(row) {
    actUser = row;
    var modal = document.getElementById('sasActModal');
    var nameEl = document.getElementById('sasActName');
    var userEl = document.getElementById('sasActUser');
    var err = document.getElementById('sasActErr');
    var hint = document.getElementById('sasActCardHint');
    var cardSel = document.getElementById('sasActCardSelect');
    if (nameEl) nameEl.textContent = row.firstname || row.name || row.id || '';
    if (userEl) userEl.textContent = row.username || row.id || '';
    if (err) err.textContent = '';
    if (modal) {
      modal.classList.remove('hidden', 'is-locking');
    }
    var lockEl = document.getElementById('sasActLock');
    if (lockEl) {
      lockEl.classList.add('hidden');
      lockEl.setAttribute('aria-hidden', 'true');
    }
    setActMode('card');
    loadQuote(row.id, row.profileId);
    var ready = cardsForProfile(row.profileId, row.profileName);
    if (cardsCacheAll) {
      renderCards(ready);
      loadProfiles().then(function (ps) {
        fillSelect(document.getElementById('sasActProfile'), ps, row.profileId);
      }).catch(function () {});
    } else {
      if (hint) {
        hint.className = 'sas-sync-note';
        hint.textContent = <?php echo json_encode($lang === 'en' ? 'Loading unused cards…' : 'جاري جلب الكروت الشاغرة…'); ?>;
      }
      if (cardSel) { cardSel.innerHTML = ''; cardSel.disabled = true; }
      prefetchCards().then(function () {
        return loadProfiles();
      }).then(function (ps) {
        fillSelect(document.getElementById('sasActProfile'), ps, row.profileId);
        var sel = document.getElementById('sasActProfile');
        renderCards(cardsForProfile(sel ? sel.value : row.profileId, selectedProfileName(sel) || row.profileName || ''));
      }).catch(function () { renderCards([]); });
    }
    syncActWa();
  }
  function paintRowStatus(tr, d) {
    if (!tr || !d) return;
    var on = String(d.enabled) === '1' || d.enabled === 1;
    var online = String(d.is_online) === '1' || d.is_online === 1;
    var active = String(d.is_active) === '1' || d.is_active === 1;
    tr.setAttribute('data-enabled', on ? '1' : '0');
    tr.className = (tr.className || '').replace(/row-status-\S+/g, '');
    var cell = tr.querySelector('.status-cell');
    var html = '';
    var title = '';
    if (!on) {
      tr.classList.add('row-status-left');
      html = '<span class="status-sq status-left"></span>';
      title = <?php echo json_encode($lang === 'en' ? 'Disabled' : 'معطل'); ?>;
    } else if (online && active) {
      tr.classList.add('row-status-active');
      html = '<span class="status-sq status-online"></span>';
      title = <?php echo json_encode($lang === 'en' ? 'Active + connected' : 'فعال ومتصل'); ?>;
    } else if (online) {
      tr.classList.add('row-status-expired');
      html = '<span class="status-sq status-expired-online"></span>';
      title = <?php echo json_encode($lang === 'en' ? 'Expired + connected' : 'منتهي ومتصل'); ?>;
    } else if (active) {
      tr.classList.add('row-status-active');
      html = '<span class="status-sq status-active"></span>';
      title = <?php echo json_encode($lang === 'en' ? 'Active' : 'فعال'); ?>;
    } else {
      tr.classList.add('row-status-expired');
      html = '<span class="status-sq status-expired"></span>';
      title = <?php echo json_encode($lang === 'en' ? 'Expired' : 'منتهي'); ?>;
    }
    if (cell) {
      cell.innerHTML = html;
      cell.setAttribute('title', title);
    }
  }
  function setEnabled(row, on) {
    var msg = on
      ? <?php echo json_encode($lang === 'en' ? 'Enable this user on SAS?' : 'تشغيل هذا المشترك بالساس؟'); ?>
      : <?php echo json_encode($lang === 'en' ? 'Disable this user on SAS?' : 'إيقاف هذا المشترك بالساس؟'); ?>;
    if (!window.confirm(msg)) return;
    postSas('sas_enable', { id: row.id, enabled: on ? '1' : '0' }).then(function (d) {
      if (!d || !d.ok) {
        alert((d && d.message) || <?php echo json_encode($lang === 'en' ? 'SAS update failed' : 'فشل التعديل بالساس'); ?>);
        return;
      }
      paintRowStatus(row.tr, d);
    }).catch(function () { alert(<?php echo json_encode($lang === 'en' ? 'Network error' : 'فشل الاتصال'); ?>); });
  }
  function openProfModal(row) {
    actUser = row;
    var modal = document.getElementById('sasProfModal');
    var who = document.getElementById('sasProfWho');
    var current = document.getElementById('sasProfCurrent');
    var err = document.getElementById('sasProfErr');
    if (who) who.textContent = (row.name || row.id) + ' · ' + row.id;
    if (current) current.textContent = <?php echo json_encode($lang === 'en' ? 'Current: ' : 'الاشتراك الحالي: '); ?> + (row.profileName || '-');
    if (err) err.textContent = '';
    if (modal) modal.classList.remove('hidden');
    loadProfiles().then(function (ps) {
      fillSelect(document.getElementById('sasProfSelect'), ps, row.profileId);
    });
  }
  document.querySelectorAll('#sasActModal input[name="sas_act_mode"]').forEach(function (el) {
    el.addEventListener('change', toggleActMode);
  });
  var actProf = document.getElementById('sasActProfile');
  if (actProf) {
    actProf.addEventListener('change', function () {
      if (!actUser) return;
      loadCards(actUser.id, actProf.value, selectedProfileName(actProf)).then(renderCards);
    });
  }
  var actWa = document.getElementById('sasActSendWa');
  if (actWa) actWa.addEventListener('change', syncActWa);
  var actOld = document.getElementById('sasActOldDebts');
  if (actOld) {
    actOld.addEventListener('change', function () { actOld.dataset.userTouched = '1'; });
  }
  var actClose = document.getElementById('sasActClose');
  if (actClose) actClose.addEventListener('click', function () {
    var m = document.getElementById('sasActModal');
    if (m && m.classList.contains('is-locking')) return;
    if (m) m.classList.add('hidden');
  });
  var actCopy = document.getElementById('sasActCopy');
  if (actCopy) {
    actCopy.addEventListener('click', function () {
      var u = document.getElementById('sasActUser');
      var t = u ? String(u.textContent || '').replace(/^\s+|\s+$/g, '') : '';
      if (!t) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t).then(function () {
          actCopy.textContent = '✓';
          setTimeout(function () { actCopy.textContent = '⧉'; }, 900);
        }).catch(function () {});
      } else {
        var ta = document.createElement('textarea');
        ta.value = t;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (err) {}
        document.body.removeChild(ta);
        actCopy.textContent = '✓';
        setTimeout(function () { actCopy.textContent = '⧉'; }, 900);
      }
    });
  }
  var profClose = document.getElementById('sasProfClose');
  var profCancel = document.getElementById('sasProfCancel');
  function closeProfModal() {
    var m = document.getElementById('sasProfModal');
    if (m) m.classList.add('hidden');
  }
  if (profClose) profClose.addEventListener('click', closeProfModal);
  if (profCancel) profCancel.addEventListener('click', closeProfModal);
  var actSubmit = document.getElementById('sasActSubmit');
  if (actSubmit) {
    var actSubmitLabel = actSubmit.textContent;
    var actBusyText = <?php echo json_encode($lang === 'en' ? 'Activating…' : 'جاري التفعيل...'); ?>;
    function setActBusy(on) {
      var modal = document.getElementById('sasActModal');
      var lock = document.getElementById('sasActLock');
      on = !!on;
      if (modal) {
        if (on) modal.classList.add('is-locking');
        else modal.classList.remove('is-locking');
        modal.querySelectorAll('input, select, textarea, button').forEach(function (el) {
          el.disabled = on;
        });
      }
      actSubmit.disabled = on;
      if (actClose) actClose.disabled = on;
      if (on) {
        actSubmit.classList.add('is-busy', 'is-pressed');
        actSubmit.textContent = actSubmitLabel;
        if (lock) {
          lock.classList.remove('hidden');
          lock.setAttribute('aria-hidden', 'false');
        }
      } else {
        actSubmit.classList.remove('is-busy', 'is-pressed');
        actSubmit.textContent = actSubmitLabel;
        if (lock) {
          lock.classList.add('hidden');
          lock.setAttribute('aria-hidden', 'true');
        }
      }
    }
    function markActPressed() {
      actSubmit.classList.add('is-pressed');
    }
    function clearActPressed() {
      if (!actSubmit.classList.contains('is-busy')) {
        actSubmit.classList.remove('is-pressed');
      }
    }
    actSubmit.addEventListener('pointerdown', markActPressed);
    actSubmit.addEventListener('mousedown', markActPressed);
    actSubmit.addEventListener('pointerup', clearActPressed);
    actSubmit.addEventListener('mouseup', clearActPressed);
    actSubmit.addEventListener('pointerleave', clearActPressed);
    actSubmit.addEventListener('mouseleave', clearActPressed);
    actSubmit.addEventListener('click', function () {
      if (!actUser) return;
      var err = document.getElementById('sasActErr');
      if (err) err.textContent = '';
      var profileSel = document.getElementById('sasActProfile');
      var profileId = profileSel ? profileSel.value : actUser.profileId;
      var profileName = selectedProfileName(profileSel);
      var sendWa = document.getElementById('sasActSendWa');
      var sendOld = document.getElementById('sasActOldDebts');
      var payModeEl = document.querySelector('#sasActModal input[name="sas_pay_mode"]:checked');
      var waFields = {
        send_whatsapp: (sendWa && sendWa.checked) ? '1' : '0',
        send_old_debts: (sendWa && sendWa.checked && sendOld && sendOld.checked) ? '1' : '0',
        pay_mode: (payModeEl && payModeEl.value === 'credit') ? 'credit' : 'cash'
      };
      var req;
      if (currentActMode() === 'credit') {
        var units = document.getElementById('sasActUnits');
        setActBusy(true);
        req = postSas('sas_activate_credit', {
          id: actUser.id,
          profile_id: profileId,
          profile_name: profileName,
          units: units ? units.value : '1',
          send_whatsapp: waFields.send_whatsapp,
          send_old_debts: waFields.send_old_debts,
          pay_mode: waFields.pay_mode
        });
      } else {
        var cardSel = document.getElementById('sasActCardSelect');
        var pin = cardSel ? cardSel.value : '';
        var cardId = '0';
        if (cardSel && cardSel.selectedIndex >= 0 && cardSel.options[cardSel.selectedIndex]) {
          cardId = cardSel.options[cardSel.selectedIndex].getAttribute('data-card-id') || '0';
        }
        if (!pin) {
          if (err) err.textContent = <?php echo json_encode($lang === 'en' ? 'No unused cards' : 'ماكو كروت شاغرة'); ?>;
          setActBusy(false);
          return;
        }
        setActBusy(true);
        req = postSas('sas_activate_card', {
          id: actUser.id,
          pin: pin,
          card_id: cardId,
          profile_id: profileId,
          profile_name: profileName,
          send_whatsapp: waFields.send_whatsapp,
          send_old_debts: waFields.send_old_debts,
          pay_mode: waFields.pay_mode
        });
      }
      req.then(function (d) {
        if (!d || !d.ok) {
          setActBusy(false);
          var failMsg = (d && d.message) || <?php echo json_encode($lang === 'en' ? 'Activate failed' : 'فشل التفعيل'); ?>;
          if (err) err.textContent = failMsg;
          showAppToast(failMsg, 'error');
          return;
        }
        rememberActFlash((d && d.message) || <?php echo json_encode($lang === 'en' ? 'Subscriber activated' : 'تم تفعيل المشترك'); ?>, 'ok');
        window.location.reload();
      }).catch(function () {
        setActBusy(false);
        var netMsg = <?php echo json_encode($lang === 'en' ? 'Network error' : 'فشل الاتصال'); ?>;
        if (err) err.textContent = netMsg;
        showAppToast(netMsg, 'error');
      });
    });
  }
  var profSubmit = document.getElementById('sasProfSubmit');
  if (profSubmit) {
    profSubmit.addEventListener('click', function () {
      if (!actUser) return;
      var sel = document.getElementById('sasProfSelect');
      var err = document.getElementById('sasProfErr');
      if (!sel || !sel.value) {
        if (err) err.textContent = <?php echo json_encode($lang === 'en' ? 'Select a profile' : 'اختر نوع الاشتراك'); ?>;
        return;
      }
      if (err) err.textContent = '';
      profSubmit.disabled = true;
      postSas('sas_change_profile', {
        id: actUser.id,
        profile_id: sel.value,
        profile_name: sel.selectedOptions[0] ? sel.selectedOptions[0].textContent : ''
      }).then(function (d) {
        profSubmit.disabled = false;
        if (!d || !d.ok) {
          if (err) err.textContent = (d && d.message) || <?php echo json_encode($lang === 'en' ? 'Update failed' : 'فشل التغيير'); ?>;
          return;
        }
        window.location.reload();
      }).catch(function () {
        profSubmit.disabled = false;
        if (err) err.textContent = <?php echo json_encode($lang === 'en' ? 'Network error' : 'فشل الاتصال'); ?>;
      });
    });
  }
  function bindDrop(btnId, dropId) {
    var b = document.getElementById(btnId);
    var d = document.getElementById(dropId);
    if (!b || !d) return;
    b.addEventListener('click', function (e) {
      e.preventDefault();
      var open = d.classList.contains('hidden');
      closeMenus();
      if (open) placeMenu(d, b);
    });
  }
  bindDrop('colsToggleBtn', 'colsDropdown');
  bindDrop('filterToggleBtn', 'filterDropdown');
  bindDrop('autoRefreshBtn', 'autoRefreshDropdown');
  document.addEventListener('click', function (e) {
    if (e.target.closest('.ops-dropdown') || e.target.closest('.tool-ico') || e.target.closest('#openOpsBtn')) return;
    closeMenus();
  });

  var colsDrop = document.getElementById('colsDropdown');
  var colsSkipUntil = 0;
  var colsReady = false;
  function applyOrder() {
    var table = document.getElementById('subsTable');
    if (!table || !colOrder || !colOrder.length) return;
    var rows = table.querySelectorAll('tr');
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      if (!row.querySelector('.col-user') && !row.querySelector('.col-num')) continue;
      colOrder.forEach(function (col) {
        var cell = row.querySelector('.col-' + col);
        if (cell) row.appendChild(cell);
      });
    }
  }
  function syncMenuToOrder() {
    if (!colsDrop) return;
    colOrder.forEach(function (k) {
      var row = colsDrop.querySelector('[data-col-row="' + k + '"]');
      if (row) colsDrop.appendChild(row);
    });
  }
  function applyCols() {
    if (!colsDrop) return;
    if (!savedCols || typeof savedCols !== 'object') savedCols = {};
    colsDrop.querySelectorAll('input[data-col]').forEach(function (inp) {
      var col = inp.getAttribute('data-col');
      if (!col) return;
      if (!colsReady && Object.prototype.hasOwnProperty.call(savedCols, col)) {
        inp.checked = !!savedCols[col];
      }
      var on = !!inp.checked;
      savedCols[col] = on;
      document.querySelectorAll('#subsTable .col-' + col).forEach(function (el) {
        if (on) {
          el.classList.remove('col-off');
          el.style.removeProperty('display');
        } else {
          el.classList.add('col-off');
          el.style.setProperty('display', 'none', 'important');
        }
      });
    });
    applyOrder();
  }
  function persistCols() {
    saveLocalCols();
    saveColOrder();
    try { localStorage.setItem('sas_refresh_sec_v1', String(refreshSec || 0)); } catch (e) {}
  }
  function moveCol(key, dir) {
    var i = colOrder.indexOf(key);
    if (i < 0) return;
    var j = i + dir;
    if (j < 0 || j >= colOrder.length) return;
    var tmp = colOrder[i];
    colOrder[i] = colOrder[j];
    colOrder[j] = tmp;
    syncMenuToOrder();
    applyOrder();
    persistCols();
  }
  if (colsDrop) {
    colsDrop.addEventListener('change', function (e) {
      var inp = e.target && e.target.closest ? e.target.closest('input[data-col]') : null;
      if (!inp) return;
      colsSkipUntil = Date.now() + 1500;
      applyCols();
      persistCols();
    });
    colsDrop.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.cols-shift') : null;
      if (!btn) return;
      e.preventDefault();
      e.stopPropagation();
      var row = btn.closest('[data-col-row]');
      if (!row) return;
      moveCol(row.getAttribute('data-col-row'), parseInt(btn.getAttribute('data-dir'), 10) || 0);
    });
    var dragKey = '';
    colsDrop.addEventListener('dragstart', function (e) {
      var h = e.target && e.target.closest ? e.target.closest('.cols-handle') : null;
      if (!h) { e.preventDefault(); return; }
      var row = h.closest('[data-col-row]');
      if (!row) return;
      dragKey = row.getAttribute('data-col-row') || '';
      row.classList.add('is-drag');
      try { e.dataTransfer.setData('text/plain', dragKey); e.dataTransfer.effectAllowed = 'move'; } catch (err) {}
    });
    colsDrop.addEventListener('dragend', function () {
      colsDrop.querySelectorAll('.is-drag,.is-over').forEach(function (el) {
        el.classList.remove('is-drag');
        el.classList.remove('is-over');
      });
      dragKey = '';
    });
    colsDrop.addEventListener('dragover', function (e) {
      if (!dragKey) return;
      e.preventDefault();
      var row = e.target && e.target.closest ? e.target.closest('[data-col-row]') : null;
      colsDrop.querySelectorAll('.is-over').forEach(function (el) { el.classList.remove('is-over'); });
      if (row) row.classList.add('is-over');
    });
    colsDrop.addEventListener('drop', function (e) {
      e.preventDefault();
      var row = e.target && e.target.closest ? e.target.closest('[data-col-row]') : null;
      var from = dragKey;
      var to = row ? row.getAttribute('data-col-row') : '';
      colsDrop.querySelectorAll('.is-drag,.is-over').forEach(function (el) {
        el.classList.remove('is-drag');
        el.classList.remove('is-over');
      });
      dragKey = '';
      if (!from || !to || from === to) return;
      var fi = colOrder.indexOf(from);
      var ti = colOrder.indexOf(to);
      if (fi < 0 || ti < 0) return;
      colOrder.splice(fi, 1);
      colOrder.splice(ti, 0, from);
      colOrder = mergeColOrder(colOrder);
      syncMenuToOrder();
      applyOrder();
      persistCols();
    });
  }
  function tableIsBusy() {
    if (actModalOpen()) return true;
    if (!tbody) return false;
    return !!(tbody.querySelector('.editing') || tbody.querySelector('select') || tbody.querySelector('[contenteditable="true"]'));
  }
  function liveTableUrl() {
    var p = liveQs || {};
    return 'sas.php?ajax=live_table'
      + '&q=' + encodeURIComponent(p.q || '')
      + '&sub=' + encodeURIComponent(p.sub || '')
      + '&parent=' + encodeURIComponent(p.parent || '')
      + '&sort=' + encodeURIComponent(p.sort || 'name')
      + '&dir=' + encodeURIComponent(p.dir || 'asc')
      + '&page=' + encodeURIComponent(p.page || '1')
      + '&per_page=' + encodeURIComponent(p.per_page || '10');
  }
  function replaceTableHtml(html) {
    if (!tbody || !html) return;
    var wrap = document.querySelector('.sas-table-card .table-wrap');
    var y = window.pageYOffset || document.documentElement.scrollTop;
    var wrapTop = wrap ? wrap.scrollTop : 0;
    var wrapLeft = wrap ? wrap.scrollLeft : 0;
    var kept = {};
    visibleChecks().forEach(function (c) { if (c.checked) kept[c.value] = 1; });
    tbody.innerHTML = html;
    originalHtml = html;
    visibleChecks().forEach(function (c) { if (kept[c.value]) c.checked = true; });
    applyCols();
    syncBulk();
    if (wrap) {
      wrap.scrollTop = wrapTop;
      wrap.scrollLeft = wrapLeft;
    }
    if (window.scrollTo) window.scrollTo(0, y);
  }
  function refreshTableLive() {
    if (liveBusy || tableIsBusy() || document.hidden) return Promise.resolve(null);
    if (Date.now() < colsSkipUntil) return Promise.resolve(null);
    if (filter && filter.value.trim() !== String((liveQs && liveQs.q) || '')) return Promise.resolve(null);
    liveBusy = true;
    return fetch(liveTableUrl(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        liveBusy = false;
        if (d && d.ok && d.html && !tableIsBusy()) replaceTableHtml(d.html);
        return d;
      })
      .catch(function () { liveBusy = false; return null; });
  }
  function applyAutoRefresh(sec, skipSave) {
    refreshSec = parseInt(sec, 10) || 0;
    if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
    var autoBtn = document.getElementById('autoRefreshBtn');
    if (autoBtn) autoBtn.classList.toggle('is-on', refreshSec > 0);
    var autoDrop = document.getElementById('autoRefreshDropdown');
    if (autoDrop) {
      Array.prototype.slice.call(autoDrop.querySelectorAll('[data-refresh]')).forEach(function (b) {
        b.classList.toggle('is-on', String(b.getAttribute('data-refresh')) === String(refreshSec));
      });
    }
    if (refreshSec > 0) {
      refreshTimer = setInterval(refreshTableLive, refreshSec * 1000);
    }
    if (!skipSave) persistCols();
  }
  syncMenuToOrder();
  applyCols();
  colsReady = true;
  persistCols();
  var autoRefreshDrop = document.getElementById('autoRefreshDropdown');
  if (autoRefreshDrop) {
    autoRefreshDrop.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('[data-refresh]') : null;
      if (!b) return;
      e.preventDefault();
      applyAutoRefresh(b.getAttribute('data-refresh'));
      closeMenus();
    });
  }
  applyAutoRefresh(refreshSec, true);

  function liveSearch() {
    if (!filter || !tbody) return;
    var q = filter.value.trim();
    if (q === '') {
      tbody.innerHTML = originalHtml;
      if (pager) pager.style.display = '';
      return;
    }
    if (pager) pager.style.display = 'none';
    fetch('sas.php?live=1&q=' + encodeURIComponent(q) + '&sub=<?php echo rawurlencode($subFilter); ?>&parent=<?php echo rawurlencode($parentFilter); ?>')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.html) {
          tbody.innerHTML = d.html;
          syncBulk();
          applyCols();
        }
      }).catch(function () {});
  }
  if (filter) {
    filter.addEventListener('input', function () {
      clearTimeout(liveTimer);
      liveTimer = setTimeout(liveSearch, 280);
    });
  }

  function showSyncNote(text) {
    var btn = document.getElementById('refreshTableBtn');
    var hint = document.getElementById('sasRefreshHint');
    if (btn && text) btn.title = text;
    if (hint) hint.textContent = text || '';
  }
  var offlineBannerText = <?php echo json_encode($lang === 'en'
      ? 'No connection to SAS — showing stored offline snapshot. Sync will resume automatically when SAS is back.'
      : 'ماكو اتصال بالساس — البيانات مخزّنة أوف لاين (لقطة محلية). عند رجوع الساس تبدأ المزامنة تلقائياً.'); ?>;
  function setOfflineBanner(on) {
    var b = document.getElementById('sasOfflineBanner');
    if (!b) return;
    if (on) {
      b.hidden = false;
      b.textContent = offlineBannerText;
    } else {
      b.hidden = true;
    }
  }
  function showSyncOkBanner() {
    setOfflineBanner(false);
    showAppToast(<?php echo json_encode($lang === 'en' ? 'Synced successfully' : 'تمت المزامنة بنجاح'); ?>, 'ok');
  }
  var toastEl = document.getElementById('sasSyncOkBanner');
  if (toastEl) {
    toastEl.addEventListener('click', function () {
      toastEl.hidden = true;
      if (window._sasToastT) clearTimeout(window._sasToastT);
    });
  }
  try {
    var flashRaw = sessionStorage.getItem('sas_flash');
    if (flashRaw) {
      sessionStorage.removeItem('sas_flash');
      var flashObj = JSON.parse(flashRaw);
      if (flashObj && flashObj.t) {
        showAppToast(flashObj.t, flashObj.k === 'error' ? 'error' : 'ok');
      }
    } else if (sessionStorage.getItem('sas_sync_ok') === '1') {
      sessionStorage.removeItem('sas_sync_ok');
      showSyncOkBanner();
    }
  } catch (e) {}
  function markSyncOkAndReload() {
    showSyncOkBanner();
    refreshTableLive();
  }
  function parseSyncRes(r) {
    return r.text().then(function (t) {
      try {
        return JSON.parse(t);
      } catch (err) {
        return { ok: false, mode: 'error', last_error: (t || ('HTTP ' + r.status)).replace(/<[^>]+>/g, ' ').substring(0, 240) };
      }
    });
  }
  function runQuickRefresh() {
    var q = filter ? filter.value.trim() : '';
    showSyncNote(<?php echo json_encode($lang === 'en' ? 'Refreshing from SAS…' : 'جاري التحديث من الساس…'); ?>);
    return fetch('sas.php?ajax=refresh_now&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then(parseSyncRes)
      .then(function (d) {
        if (d && d.ok) {
          showSyncNote(<?php echo json_encode($lang === 'en' ? 'Updated' : 'تم التحديث'); ?>);
          return refreshTableLive().then(function () {
            showSyncOkBanner();
            return d;
          });
        }
        setOfflineBanner(true);
        showSyncNote((d && (d.last_error || d.message)) || <?php echo json_encode($lang === 'en' ? 'Refresh failed' : 'فشل التحديث'); ?>);
        return d;
      }).catch(function (err) {
        setOfflineBanner(true);
        showSyncNote(err && err.message ? err.message : <?php echo json_encode($lang === 'en' ? 'Network error' : 'فشل الاتصال'); ?>);
        return null;
      });
  }
  function runSync(reset) {
    var url = 'sas.php?ajax=pull&force=1' + (reset ? '&reset=1' : '');
    return fetch(url, { credentials: 'same-origin' }).then(parseSyncRes).then(function (d) {
      if (!d) return d;
      if (d.last_error && !d.ok) {
        setOfflineBanner(true);
        showSyncNote(d.last_error);
        return d;
      }
      if (d.mode === 'progress') {
        showSyncNote((<?php echo json_encode($lang === 'en' ? 'Loading from SAS…' : 'جاري الجلب من الساس…'); ?>) + ' ' + (d.count || 0) + (d.expected ? (' / ' + d.expected) : ''));
        return runSync(false);
      }
      if (d.ok && (d.mode === 'synced' || d.mode === 'cache') && (d.count > 0 || d.mode === 'synced')) {
        markSyncOkAndReload();
      }
      return d;
    }).catch(function (err) {
      setOfflineBanner(true);
      showSyncNote(err && err.message ? err.message : 'فشل الاتصال بالصفحة');
      return null;
    });
  }
  function runDiagThenSync() {
    return runSync(true);
  }
  var refreshBtn = document.getElementById('refreshTableBtn');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      refreshBtn.classList.add('is-on');
      runQuickRefresh().then(function () { refreshBtn.classList.remove('is-on'); });
    });
  }

  (function setupInlineEdit() {
    var saving = false;
    function saveField(el, field, id, value) {
      if (saving) return;
      saving = true;
      var body = new FormData();
      body.append('csrf', csrf);
      body.append('action', field === 'grace_days' ? 'sas_update_grace' : 'sas_inline');
      body.append('id', id);
      body.append('field', field);
      body.append('value', value);
      fetch('sas.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          saving = false;
          if (!data || !data.ok) {
            alert((data && data.message) ? data.message : <?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
            el.textContent = el.getAttribute('data-display') || el.getAttribute('data-value') || '';
            return;
          }
          var shown = data.value || value;
          el.textContent = shown !== '' ? shown : '-';
          el.setAttribute('data-value', data.raw || value);
          el.setAttribute('data-display', shown);
          if (field === 'username' && data.username) {
            el.setAttribute('data-id', data.username);
            el.setAttribute('href', 'sas_user.php?u=' + encodeURIComponent(data.username));
            var tr = el.closest('tr');
            if (tr) tr.setAttribute('data-id', data.username);
          }
        })
        .catch(function () {
          saving = false;
          alert(<?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
        });
    }
    function beginEdit(el) {
      if (!el || el.classList.contains('editing')) return;
      var field = el.getAttribute('data-edit');
      var id = el.getAttribute('data-id');
      if (!field || !id) return;
      var current = el.getAttribute('data-value') || el.textContent.trim();
      if (current === '-') current = '';
      el.setAttribute('data-display', el.textContent.trim());
      el.classList.add('editing');
      el.contentEditable = 'true';
      el.focus();
      try {
        var range = document.createRange();
        range.selectNodeContents(el);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      } catch (err) {}
      function finish(ok) {
        if (!el.classList.contains('editing')) return;
        el.classList.remove('editing');
        el.contentEditable = 'false';
        var val = el.textContent.trim();
        var allowEmpty = el.getAttribute('data-allow-empty') === '1';
        if (val === '-') val = '';
        if (!ok) {
          el.textContent = el.getAttribute('data-display') || current || '-';
          return;
        }
        if (!allowEmpty && val === '') {
          el.textContent = el.getAttribute('data-display') || current || '-';
          return;
        }
        if (val === current) {
          el.textContent = current !== '' ? current : '-';
          return;
        }
        saveField(el, field, id, val);
      }
      el.onkeydown = function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
        if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
      };
      el.onblur = function () { finish(true); };
    }
    function beginDebtEdit(btn) {
      if (!btn || btn.classList.contains('editing')) return;
      var current = btn.getAttribute('data-amount') || '0';
      var snap = btn.textContent;
      btn.classList.add('editing');
      btn.contentEditable = 'true';
      btn.textContent = current;
      btn.focus();
      try {
        var range = document.createRange();
        range.selectNodeContents(btn);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
      } catch (err) {}
      var done = false;
      function finish(ok) {
        if (done || !btn.classList.contains('editing')) return;
        done = true;
        btn.classList.remove('editing');
        btn.contentEditable = 'false';
        var raw = String(btn.textContent || '').replace(/[^\d]/g, '');
        var n = parseInt(raw, 10);
        if (!ok || !raw) {
          btn.textContent = snap;
          return;
        }
        if (String(n) === String(current)) {
          btn.textContent = snap;
          return;
        }
        if (!(n > 0)) {
          alert(<?php echo json_encode($lang === 'en' ? 'Enter a valid amount' : 'أدخل مبلغ صحيح'); ?>);
          btn.textContent = snap;
          return;
        }
        var tr = btn.closest('tr');
        var username = btn.getAttribute('data-username') || (tr ? tr.getAttribute('data-id') : '') || '';
        var body = new FormData();
        body.append('csrf', csrf);
        body.append('action', 'sas_update_debt');
        body.append('id', username);
        body.append('amount', String(n));
        fetch('sas.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data || !data.ok) {
              alert((data && data.message) ? data.message : <?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
              btn.textContent = snap;
              return;
            }
            btn.textContent = data.debt_text || String(n);
            btn.setAttribute('data-amount', String(Math.round(Number(data.debt) || n)));
            btn.className = (Number(data.debt) > 0 ? 'debt-amt debt-due' : 'debt-amt debt-zero') + ' debt-edit-btn';
            if (tr) {
              tr.setAttribute('data-debt', Number(data.debt) > 0 ? '1' : '0');
              if (data.local_id) tr.setAttribute('data-local-id', String(data.local_id));
            }
          })
          .catch(function () {
            alert(<?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
            btn.textContent = snap;
          });
      }
      btn.onkeydown = function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
        if (ev.key === 'Escape') { ev.preventDefault(); finish(false); }
      };
      btn.onblur = function () { finish(true); };
    }
    function beginRentEdit(btn) {
      if (!btn || btn.classList.contains('editing')) return;
      var id = btn.getAttribute('data-id');
      var current = btn.getAttribute('data-device') || '';
      var td = btn.parentNode;
      var snap = btn.outerHTML;
      btn.classList.add('editing');
      var sel = document.createElement('select');
      sel.className = 'rent-cell-select';
      var none = document.createElement('option');
      none.value = '';
      none.textContent = rentNoneLabel;
      if (current === '') none.selected = true;
      sel.appendChild(none);
      (rentalDevices || []).forEach(function (d) {
        var o = document.createElement('option');
        o.value = d.id;
        o.textContent = d.name;
        if (d.id === current) o.selected = true;
        sel.appendChild(o);
      });
      btn.innerHTML = '';
      btn.appendChild(sel);
      sel.focus();
      var done = false;
      function restore() {
        if (td) td.innerHTML = snap;
      }
      function saveRent(val) {
        if (done) return;
        done = true;
        if (val === current) {
          restore();
          return;
        }
        var body = new FormData();
        body.append('csrf', csrf);
        body.append('action', 'sas_update_rental');
        body.append('id', id);
        body.append('device_id', val);
        fetch('sas.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data || !data.ok) {
              alert((data && data.message) ? data.message : <?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
              restore();
              return;
            }
            if (td && data.cell_html) td.innerHTML = data.cell_html;
            var tr = td ? td.closest('tr') : null;
            if (tr) {
              tr.setAttribute('data-rental', data.rental_device_id || '');
              if (data.local_id) tr.setAttribute('data-local-id', String(data.local_id));
              var debtEl = tr.querySelector('.col-debt .debt-amt');
              if (debtEl && data.debt_text) {
                debtEl.textContent = data.debt_text;
                var due = Number(data.debt) > 0;
                var extra = debtEl.classList.contains('debt-edit-btn') ? ' debt-edit-btn' : '';
                debtEl.className = (due ? 'debt-amt debt-due' : 'debt-amt debt-zero') + extra;
                if (debtEl.classList.contains('debt-edit-btn')) {
                  debtEl.setAttribute('data-amount', String(Math.round(Number(data.debt) || 0)));
                }
                tr.setAttribute('data-debt', due ? '1' : '0');
              }
            }
          })
          .catch(function () {
            alert(<?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
            restore();
          });
      }
      sel.addEventListener('click', function (ev) { ev.stopPropagation(); });
      sel.addEventListener('mousedown', function (ev) { ev.stopPropagation(); });
      sel.addEventListener('change', function () { saveRent(sel.value); });
      sel.addEventListener('blur', function () {
        setTimeout(function () {
          if (!done) saveRent(sel.value);
        }, 120);
      });
    }
    if (tbody) {
      tbody.addEventListener('click', function (e) {
        var debtBtn = e.target && e.target.closest ? e.target.closest('.debt-edit-btn') : null;
        if (debtBtn && tbody.contains(debtBtn) && !debtBtn.classList.contains('editing')) {
          e.preventDefault();
          e.stopPropagation();
          beginDebtEdit(debtBtn);
          return;
        }
        var rentBtn = e.target && e.target.closest ? e.target.closest('.rent-cell-edit') : null;
        if (rentBtn && tbody.contains(rentBtn) && !rentBtn.querySelector('select')) {
          e.preventDefault();
          e.stopPropagation();
          beginRentEdit(rentBtn);
          return;
        }
        var copyBtn = e.target && e.target.closest ? e.target.closest('.sas-user-copy') : null;
        if (copyBtn && tbody.contains(copyBtn)) {
          e.preventDefault();
          e.stopPropagation();
          var txt = copyBtn.getAttribute('data-copy') || '';
          if (!txt) return;
          var done = function () {
            copyBtn.textContent = '✓';
            setTimeout(function () { copyBtn.textContent = '⧉'; }, 900);
          };
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(txt).then(done).catch(function () {});
          } else {
            var ta = document.createElement('textarea');
            ta.value = txt;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (err) {}
            document.body.removeChild(ta);
            done();
          }
          return;
        }
        var pkg = e.target && e.target.closest ? e.target.closest('.sas-pkg-open') : null;
        if (pkg && tbody.contains(pkg)) {
          e.preventDefault();
          e.stopPropagation();
          var tr = pkg.closest('tr');
          if (tr) {
            selectOnlyRow(tr);
            var rows = selectedRows();
            if (rows[0]) openProfModal(rows[0]);
          }
          return;
        }
        var el = e.target && e.target.closest ? e.target.closest('.cell-edit') : null;
        if (!el || !tbody.contains(el)) return;
        e.preventDefault();
        e.stopPropagation();
        beginEdit(el);
      });
    }
  })();

  prefetchCards().then(function () {
    loadProfiles();
    if (stale) runDiagThenSync();
  }).catch(function () {
    loadProfiles();
    if (stale) runDiagThenSync();
  });
  if (window.location.hash) {
    var hid = window.location.hash.replace(/^#/, '');
    var rowEl = hid ? document.getElementById(hid) : null;
    if (rowEl) {
      try { rowEl.scrollIntoView({ block: 'center' }); } catch (e) { rowEl.scrollIntoView(true); }
      rowEl.classList.add('sas-row-flash');
      setTimeout(function () { rowEl.classList.remove('sas-row-flash'); }, 2600);
    }
  }
})();
</script>
<?php
render_footer();
