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

if (isset($_GET['ajax']) && ($_GET['ajax'] === 'profiles' || $_GET['ajax'] === 'cards')) {
    $api = sas_page_connector($config);
    if (!$api) {
        sas_json_out(false, 'تعذر الدخول للساس');
    }
    if ($_GET['ajax'] === 'profiles') {
        sas_json_out(true, '', array('profiles' => sas_profiles_for_ui($api)));
    }
    $username = isset($_GET['username']) ? trim((string) $_GET['username']) : '';
    $profileId = isset($_GET['profile_id']) ? (int) $_GET['profile_id'] : 0;
    if ($profileId <= 0 && $username !== '') {
        $cache = sas_cache_get($pdo, $username);
        $profileId = $cache && !empty($cache['profile_id']) ? (int) $cache['profile_id'] : 0;
    }
    sas_json_out(true, '', array(
        'cards' => sas_cards_for_ui($api, $profileId),
        'profile_id' => $profileId,
    ));
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
        if (in_array(post('action'), array('sas_inline', 'sas_enable', 'sas_activate_card', 'sas_activate_credit', 'sas_change_profile'), true)) {
            sas_json_out(false, 'طلب غير صالح');
        }
        flash('error', 'طلب غير صالح');
        sas_page_redirect();
    }
    $action = post('action');

    if ($action === 'sas_inline' || $action === 'sas_enable' || $action === 'sas_activate_card'
        || $action === 'sas_activate_credit' || $action === 'sas_change_profile') {
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
        );
        list($ok, $msg, $extra) = sas_write_user($pdo, $config, $action, $username, $fields);
        sas_json_out($ok, $msg, is_array($extra) ? $extra : array());
    }

    if ($action === 'give_test') {
        $username = trim((string) post('id', ''));
        list($localId, $err) = sas_resolve_local_from_username($pdo, $config, $username);
        if ($localId <= 0) {
            flash('error', $err !== '' ? $err : 'تعذر التست');
            sas_page_redirect();
        }
        list($ok, $msg) = activate_subscriber_test($pdo, $config, $localId);
        flash($ok ? 'success' : 'error', $msg);
        sas_page_redirect('q=' . rawurlencode($username));
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
            $days = (int) ceil((strtotime($cache['expire_at']) - strtotime(date('Y-m-d'))) / 86400);
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

    flash('info', 'الحذف والإضافة من صفحة بيانات أوف لاين حتى ما تضيع الديون');
    sas_page_redirect();
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$subFilter = isset($_GET['sub']) ? (string) $_GET['sub'] : '';
if (!function_exists('sas_cache_filter_sql') || sas_cache_filter_sql($subFilter) === '') {
    $subFilter = '';
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

if (isset($_GET['live']) && $_GET['live'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $html = '<tr><td colspan="10">' . e($lang === 'en' ? 'No matches' : 'ماكو نتيجة') . '</td></tr>';
    $liveCount = 0;
    try {
        $sqlLive = sas_cache_list_select_sql() . '
            FROM sas_users_cache c
            LEFT JOIN subscribers s ON s.id = c.local_subscriber_id
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
            $html = '<tr><td colspan="10">' . e($lang === 'en' ? 'No matches' : 'ماكو نتيجة') . '</td></tr>';
        }
    } catch (Exception $e) {
        $html = '<tr><td colspan="10">' . e($e->getMessage()) . '</td></tr>';
    } catch (Error $e) {
        $html = '<tr><td colspan="10">' . e($e->getMessage()) . '</td></tr>';
    }
    echo json_encode(array(
        'html' => $html,
        'count' => $liveCount,
        'capped' => $liveCount >= 80,
    ));
    exit;
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPageRaw = isset($_GET['per_page']) ? $_GET['per_page'] : '20';
$showAll = ($perPageRaw === 'all');
$perPage = $showAll ? 0 : max(1, (int) $perPageRaw);

$sortKey = isset($_GET['sort']) ? (string) $_GET['sort'] : 'name';
$sortDir = isset($_GET['dir']) && strtolower((string) $_GET['dir']) === 'desc' ? 'desc' : 'asc';
$sortMap = array(
    'id' => 'c.sas_user_id',
    'name' => 'c.display_name',
    'phone' => 'c.phone',
    'package' => 'c.profile_name',
    'month' => 'c.expire_at',
    'days' => 'c.expire_at',
    'debt' => 'debt',
    'msg' => 'last_msg_at',
);
if (!isset($sortMap[$sortKey])) {
    $sortKey = 'name';
}
$orderSql = $sortMap[$sortKey] . ' ' . strtoupper($sortDir);
if ($sortKey === 'name') {
    $orderSql = 'c.display_name ' . strtoupper($sortDir) . ', c.username ASC';
}

try {
    $countSql = 'SELECT COUNT(*) FROM sas_users_cache c WHERE ' . $where;
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

try {
    $sql = sas_cache_list_select_sql() . '
     FROM sas_users_cache c
     LEFT JOIN subscribers s ON s.id = c.local_subscriber_id
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

function sas_sort_link($key, $label, $currentKey, $currentDir, $q, $perPageRaw, $subFilter)
{
    if ($currentKey === $key) {
        $nextDir = ($currentDir === 'asc') ? 'desc' : 'asc';
    } elseif ($key === 'debt' || $key === 'days' || $key === 'id') {
        $nextDir = 'desc';
    } else {
        $nextDir = 'asc';
    }
    $qs = array('sort=' . urlencode($key), 'dir=' . $nextDir);
    if ($q !== '') {
        $qs[] = 'q=' . urlencode($q);
    }
    if ($perPageRaw !== '20') {
        $qs[] = 'per_page=' . urlencode($perPageRaw);
    }
    if ($subFilter !== '') {
        $qs[] = 'sub=' . urlencode($subFilter);
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
if ($pageError === '' && !empty($syncMeta['last_error'])
    && stripos($syncMeta['last_error'], 'Server Error') === false) {
    $pageError = $syncMeta['last_error'];
}

render_header(t('sas'), 'sas', $syncHint);
?>
<style>
#subsTable.table-compact th,
#subsTable.table-compact td {
  padding: 7px 8px !important;
  font-size: 13px !important;
  line-height: 1.3 !important;
  height: 38px !important;
  white-space: nowrap;
  vertical-align: middle !important;
}
#subsTable.table-compact th { height: 30px !important; font-size: 12px !important; }
#subsTable .th-sort { color: inherit; text-decoration: none; font-weight: 800; }
#subsTable .th-sort.on { color: #1c4fd8; }
#subsTable .days-num { font-weight: 800; font-variant-numeric: tabular-nums; font-size: 13px; }
#subsTable .days-num.days-neg { color: #c62828; }
#subsTable .debt-amt { font-size: 12px !important; font-weight: 800 !important; }
#subsTable .debt-amt.debt-due { color: #b86a00 !important; }
#subsTable .debt-amt.debt-zero { color: #15803d !important; }
#subsTable tbody tr.row-status-expired td { background: #f8fafc !important; }
#subsTable tbody tr.row-status-left td { background: #f1f5f9 !important; }
#subsTable .month-link { color: #2563eb; text-decoration: none; font-weight: 700; font-size: 12px; }
#subsTable .month-link.month-empty { color: #94a3b8; font-weight: 600; }
#subsTable .dot-msg { display: inline-block; width: 11px; height: 11px; border-radius: 3px; }
#subsTable .dot-msg.ok { background: #34c759; }
#subsTable .dot-msg.fail { background: #ff9f0a; }
#subsTable .dot-msg.off { background: #cbd5e1; }
#subsTable .msg-status-row { display: inline-flex; align-items: center; gap: 6px; }
.subs-tool-icons { display: inline-flex; align-items: stretch; border: 1px solid rgba(28, 36, 48, 0.14); border-radius: 8px; overflow: hidden; background: #3a424d; }
.subs-tool-icons .tool-ico { appearance: none; border: 0; margin: 0; width: 36px; height: 32px; display: inline-flex; align-items: center; justify-content: center; background: transparent; color: #d7dde6; cursor: pointer; border-inline-start: 1px solid rgba(255,255,255,0.12); }
.subs-tool-icons .tool-ico:first-child { border-inline-start: 0; }
.subs-tool-icons .tool-ico:hover,
.subs-tool-icons .tool-ico.is-on { background: rgba(255,255,255,0.10); color: #fff; }
.ops-item.is-on { background: rgba(37, 99, 235, 0.10); font-weight: 800; }
.sas-offline-dot { background: #0f766e; color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 999px; margin-inline-start: 4px; }
.sas-sync-note { margin: 0 0 10px; font-size: 13px; color: #475569; }
#subsTable .phone-edit,
#subsTable .cell-edit.editing { cursor: text; outline: none; }
#subsTable .phone-edit:hover { background: rgba(37, 99, 235, 0.08); border-radius: 4px; }
#subsTable .cell-edit.editing {
  display: inline-block; min-width: 7rem; padding: 2px 6px;
  border: 1px solid #94a3b8; border-radius: 4px; background: #fff;
}
#subsTable .status-cell.sas-toggle-en { cursor: pointer; }
#subsTable .pkg-edit {
  appearance: none; border: 0; background: transparent; color: inherit;
  font: inherit; font-weight: 700; cursor: pointer; padding: 0;
}
#subsTable .pkg-edit:hover { color: #1c4fd8; text-decoration: underline; }
.sas-card-list { max-height: 240px; overflow: auto; margin: 8px 0 0; padding: 0; list-style: none; }
.sas-card-list li { margin: 0 0 6px; }
.sas-card-list label {
  display: flex; gap: 8px; align-items: center; padding: 8px 10px;
  border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer;
}
.sas-card-list label:has(input:checked) { border-color: #2563eb; background: #eff6ff; }
.sas-mode-row { display: flex; gap: 10px; margin: 10px 0; }
.sas-mode-row label { display: flex; gap: 6px; align-items: center; font-weight: 700; }
.sas-modal-err { color: #b91c1c; font-size: 13px; min-height: 1.2em; }
</style>
<div class="panel panel-subs">
    <?php if ($pageError !== ''): ?>
        <p class="sas-sync-note" style="color:#b91c1c;font-weight:700"><?php echo e($pageError); ?></p>
    <?php endif; ?>
    <?php if (!$sasReady): ?>
        <p class="sas-sync-note"><?php echo e($lang === 'en' ? 'Enable SAS in settings first.' : 'فعّل ربط SAS من الإعدادات أولاً.'); ?>
            <a href="settings.php?tab=sas"><?php echo e($lang === 'en' ? 'SAS settings' : 'إعدادات SAS'); ?></a>
        </p>
    <?php else: ?>
        <p class="sas-sync-note" id="sasSyncLive">
            <?php echo e($lang === 'en'
                ? 'Edits here write to SAS. Debts stay on the offline ledger. If you still see 10, press refresh — SAS pages the list.'
                : 'التعديل من هنا ينحفظ بالساس. الديون تبقى بالدفتر المحلي. إذا طلع 10 فقط اضغط تحديث — الساس يرجّعهم صفحات.'); ?>
            · <?php echo (int) $totalRows; ?>
            <?php echo e($lang === 'en' ? 'SAS users' : 'مشترك بالساس'); ?>
        </p>
    <?php endif; ?>

    <div class="subs-sas-bar">
        <div class="subs-ops-side">
            <div class="subs-ops-anchor" id="opsAnchor">
                <button type="button" class="btn ops-top-btn" id="openOpsBtn" aria-haspopup="true" aria-expanded="false"><?php echo e(t('operations')); ?></button>
            </div>
            <span class="meta" id="bulkSelectedCount">0</span>
        </div>
        <div class="subs-left-tools">
            <button type="button" class="btn ghost sm" onclick="window.print()"><?php echo e(t('print')); ?></button>
            <div class="subs-tool-icons" role="toolbar">
                <button type="button" class="tool-ico" id="colsToggleBtn" title="<?php echo e($lang === 'en' ? 'Columns' : 'الأعمدة'); ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M4 6h2v2H4V6zm4 0h12v2H8V6zM4 11h2v2H4v-2zm4 0h12v2H8v-2zM4 16h2v2H4v-2zm4 0h12v2H8v-2z"/></svg>
                </button>
                <button type="button" class="tool-ico" id="autoRefreshBtn" title="<?php echo e($lang === 'en' ? 'Auto refresh' : 'تحديث تلقائي'); ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M8 6h2v12H8V6zm6 0h2v12h-2V6z"/></svg>
                </button>
                <button type="button" class="tool-ico" id="filterToggleBtn" title="<?php echo e($lang === 'en' ? 'Filter' : 'فلترة'); ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M3 5h18l-7 8v5l-4 2v-7L3 5z"/></svg>
                </button>
                <button type="button" class="tool-ico" id="refreshTableBtn" title="<?php echo e($lang === 'en' ? 'Refresh from SAS' : 'تحديث من الساس'); ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M17.65 6.35A7.95 7.95 0 0 0 12 4a8 8 0 1 0 7.75 10h-2.1A6 6 0 1 1 12 6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
                </button>
            </div>
        </div>
        <div class="status-legend inline sas-legend">
            <span><i class="status-sq status-active"></i> <?php echo e(t('status_active_short')); ?></span>
            <span><i class="status-sq status-expired"></i> <?php echo e(t('status_expired_short')); ?></span>
            <span><i class="status-sq status-left"></i> <?php echo e(t('status_left_short')); ?></span>
        </div>
        <form method="get" action="sas.php" id="subsSearchForm" class="subs-search-row header-search" autocomplete="off">
            <?php if ($perPageRaw !== '20'): ?>
                <input type="hidden" name="per_page" value="<?php echo e($perPageRaw); ?>">
            <?php endif; ?>
            <?php if ($subFilter !== ''): ?>
                <input type="hidden" name="sub" value="<?php echo e($subFilter); ?>">
            <?php endif; ?>
            <div class="search-suggest-wrap">
                <input id="filterInput" name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e($lang === 'en' ? 'Search SAS name or username…' : 'بحث بالاسم أو اليوزرنيم...'); ?>" autocomplete="off">
            </div>
            <?php if ($q !== '' || $subFilter !== ''): ?>
                <a class="btn ghost sm" href="sas.php"><?php echo e(t('show_all')); ?></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-wrap">
        <table id="subsTable" class="table-compact data-table">
            <thead>
            <tr>
                <th class="sub-check-cell"><label class="th-check-only"><input type="checkbox" id="subCheckAll"></label></th>
                <th class="status-cell"><?php echo e($lang === 'en' ? 'Status' : 'الحالة'); ?></th>
                <th class="col-num"><?php echo sas_sort_link('id', '#', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-name"><?php echo sas_sort_link('name', t('name'), $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-phone"><?php echo sas_sort_link('phone', t('phone'), $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-pkg"><?php echo sas_sort_link('package', t('package'), $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-days"><?php echo sas_sort_link('days', t('days_left'), $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-debt"><?php echo sas_sort_link('debt', t('debts_total'), $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-month"><?php echo sas_sort_link('month', $lang === 'en' ? 'Month' : 'الشهر', $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
                <th class="col-msg"><?php echo sas_sort_link('msg', t('msg_status'), $sortKey, $sortDir, $q, $perPageRaw, $subFilter); ?></th>
            </tr>
            </thead>
            <tbody id="subsTableBody">
            <?php
            $n = $offset + 1;
            if (!$rows) {
                $emptyMsg = ($sasReady && $cacheCount <= 0)
                    ? ($lang === 'en' ? 'Loading users from SAS…' : 'جاري جلب المشتركين من الساس…')
                    : ($lang === 'en' ? 'No SAS users in cache yet' : 'ماكو مشتركين من الساس بعد');
                echo '<tr><td colspan="10">' . e($emptyMsg) . '</td></tr>';
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
            if ($perPageRaw !== '20') {
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
                <a class="btn ghost sm" href="?per_page=20<?php echo $extraQs; ?>">20</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="ops-dropdown hidden" id="opsDropdown" role="menu">
    <div class="ops-item" id="opsItemHint" style="cursor:default;color:#64748b"><?php echo e($lang === 'en' ? 'Select a subscriber first' : 'حدد مشتركاً من الجدول أولاً'); ?></div>
    <button type="button" class="ops-item" data-ops="open" id="opsItemOpen" hidden><?php echo e($lang === 'en' ? 'Open' : 'فتح'); ?></button>
    <button type="button" class="ops-item" data-ops="activate" id="opsItemActivate" hidden><?php echo e($lang === 'en' ? 'Activate on SAS' : 'تفعيل بالساس'); ?></button>
    <button type="button" class="ops-item" data-ops="change_profile" id="opsItemProfile" hidden><?php echo e($lang === 'en' ? 'Change package' : 'تغيير نوع الاشتراك'); ?></button>
    <button type="button" class="ops-item" data-ops="enable" id="opsItemEnable" hidden><?php echo e($lang === 'en' ? 'Enable' : 'تشغيل'); ?></button>
    <button type="button" class="ops-item" data-ops="disable" id="opsItemDisable" hidden><?php echo e($lang === 'en' ? 'Disable' : 'إيقاف'); ?></button>
    <button type="button" class="ops-item" data-ops="give_test" id="opsItemGiveTest" hidden><?php echo e(t('give_test')); ?></button>
    <button type="button" class="ops-item" data-ops="bulk_activate" id="opsItemBulkActivate" hidden><?php echo e(t('bulk_activate')); ?></button>
    <button type="button" class="ops-item" data-ops="pay" id="opsItemPay" hidden><?php echo e(t('pay_debt')); ?></button>
    <button type="button" class="ops-item" data-ops="remind_debt" id="opsItemRemind" hidden><?php echo e(t('remind')); ?></button>
    <button type="button" class="ops-item" data-ops="remind_days" id="opsItemDays" hidden><?php echo e($lang === 'en' ? 'Send days left' : 'إرسال الأيام المتبقية'); ?></button>
    <button type="button" class="ops-item" data-ops="retry" id="opsItemRetry" hidden><?php echo e(t('retry_send')); ?></button>
</div>
<div class="ops-dropdown cols-dropdown hidden" id="colsDropdown">
    <label class="ops-item cols-check"><input type="checkbox" data-col="phone"> <?php echo e(t('phone')); ?></label>
    <label class="ops-item cols-check"><input type="checkbox" data-col="pkg" checked> <?php echo e(t('package')); ?></label>
    <label class="ops-item cols-check"><input type="checkbox" data-col="days" checked> <?php echo e(t('days_left')); ?></label>
    <label class="ops-item cols-check"><input type="checkbox" data-col="debt" checked> <?php echo e(t('debts_total')); ?></label>
    <label class="ops-item cols-check"><input type="checkbox" data-col="month"> <?php echo e($lang === 'en' ? 'Month' : 'الشهر'); ?></label>
    <label class="ops-item cols-check"><input type="checkbox" data-col="msg" checked> <?php echo e(t('msg_status')); ?></label>
</div>
<div class="ops-dropdown hidden" id="filterDropdown">
    <a class="ops-item<?php echo $subFilter === '' ? ' is-on' : ''; ?>" href="sas.php"><?php echo e($lang === 'en' ? 'All' : 'الكل'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'active' ? ' is-on' : ''; ?>" href="sas.php?sub=active"><?php echo e($lang === 'en' ? 'Active' : 'فعال'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'expired' ? ' is-on' : ''; ?>" href="sas.php?sub=expired"><?php echo e($lang === 'en' ? 'Expired / none' : 'منتهي / بدون اشتراك'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'soon' ? ' is-on' : ''; ?>" href="sas.php?sub=soon"><?php echo e($lang === 'en' ? 'Expiring soon' : 'قرب ينتهي'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'today' ? ' is-on' : ''; ?>" href="sas.php?sub=today"><?php echo e($lang === 'en' ? 'Ends today' : 'ينتهي اليوم'); ?></a>
    <a class="ops-item<?php echo $subFilter === 'debt' ? ' is-on' : ''; ?>" href="sas.php?sub=debt"><?php echo e($lang === 'en' ? 'Has debt' : 'عليهم دين'); ?></a>
</div>
<div class="ops-dropdown hidden" id="autoRefreshDropdown">
    <button type="button" class="ops-item" data-refresh="0"><?php echo e($lang === 'en' ? 'Off' : 'إيقاف'); ?></button>
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
    <div class="modal-card ops-modal-card">
        <div class="ops-modal-head">
            <h3 id="sasActTitle"><?php echo e($lang === 'en' ? 'Activate on SAS' : 'تفعيل بالساس'); ?></h3>
            <button type="button" class="btn ghost sm" id="sasActClose">×</button>
        </div>
        <p class="sas-sync-note" id="sasActWho"></p>
        <label><?php echo e($lang === 'en' ? 'Package' : 'نوع الاشتراك'); ?>
            <select id="sasActProfile" style="width:100%;margin-top:4px"></select>
        </label>
        <div class="sas-mode-row">
            <label><input type="radio" name="sas_act_mode" value="card" checked> <?php echo e($lang === 'en' ? 'Unused cards (default)' : 'كروت غير مفعّلة (افتراضي)'); ?></label>
            <label><input type="radio" name="sas_act_mode" value="credit"> <?php echo e($lang === 'en' ? 'Manager credit' : 'رصيد المدير'); ?></label>
        </div>
        <div id="sasActCardBox">
            <div class="sas-sync-note" id="sasActCardHint"><?php echo e($lang === 'en' ? 'Unused cards for this profile' : 'الكروت غير المفعّلة حسب البروفايل'); ?></div>
            <ul class="sas-card-list" id="sasActCards"></ul>
        </div>
        <div id="sasActCreditBox" class="hidden">
            <label><?php echo e($lang === 'en' ? 'Units' : 'عدد الوحدات'); ?>
                <input type="number" id="sasActUnits" min="1" value="1" style="width:100%;margin-top:4px">
            </label>
        </div>
        <p class="sas-modal-err" id="sasActErr"></p>
        <button type="button" class="btn" id="sasActSubmit" style="width:100%;margin-top:8px"><?php echo e($lang === 'en' ? 'Activate' : 'تفعيل'); ?></button>
    </div>
</div>
<div class="modal-backdrop hidden" id="sasProfModal">
    <div class="modal-card ops-modal-card">
        <div class="ops-modal-head">
            <h3><?php echo e($lang === 'en' ? 'Change package' : 'تغيير نوع الاشتراك'); ?></h3>
            <button type="button" class="btn ghost sm" id="sasProfClose">×</button>
        </div>
        <p class="sas-sync-note" id="sasProfWho"></p>
        <label><?php echo e($lang === 'en' ? 'SAS profile' : 'بروفايل الساس'); ?>
            <select id="sasProfSelect" style="width:100%;margin-top:4px"></select>
        </label>
        <p class="sas-modal-err" id="sasProfErr"></p>
        <button type="button" class="btn" id="sasProfSubmit" style="width:100%;margin-top:12px"><?php echo e($lang === 'en' ? 'Save on SAS' : 'حفظ بالساس'); ?></button>
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
  var checkAll = document.getElementById('subCheckAll');
  var opsBtn = document.getElementById('openOpsBtn');
  var opsDrop = document.getElementById('opsDropdown');
  var bulkCount = document.getElementById('bulkSelectedCount');
  var bulkModal = document.getElementById('opsBulkModal');
  var countLabel = <?php echo json_encode($lang === 'en' ? 'selected' : 'محدد'); ?>;
  var confirmTest = <?php echo json_encode(t('confirm_give_test')); ?>;
  var stale = <?php echo json_encode($syncMode === 'stale'); ?>;
  var refreshSec = 0;
  var refreshTimer = null;
  var csrf = <?php echo json_encode(csrf_token()); ?>;
  var actUser = null;
  var profilesCache = null;

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
        enabled: tr.getAttribute('data-enabled') === '1',
        localId: tr.getAttribute('data-local-id') || '0',
        name: tr.getAttribute('data-name') || '',
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
    if (bulkCount) bulkCount.textContent = n + ' ' + countLabel;
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
    showEl(document.getElementById('opsItemPay'), anyDebt);
    showEl(document.getElementById('opsItemRemind'), anyDebt);
    showEl(document.getElementById('opsItemDays'), !!(one && one.hasDays));
    showEl(document.getElementById('opsItemRetry'), !!(one && one.msgFail));
  }
  function runOps(action) {
    var rows = selectedRows();
    var one = rows.length === 1 ? rows[0] : null;
    closeMenus();
    if (action === 'open' && one) {
      window.location.href = 'sas_user.php?u=' + encodeURIComponent(one.id);
      return;
    }
    if (action === 'activate' && one) {
      window.location.href = 'sas_user.php?u=' + encodeURIComponent(one.id) + '&focus=activate';
      return;
    }
    if (action === 'change_profile' && one) {
      window.location.href = 'sas_user.php?u=' + encodeURIComponent(one.id) + '&focus=profile';
      return;
    }
    if ((action === 'enable' || action === 'disable') && one) {
      setEnabled(one, action === 'enable');
      return;
    }
    if (action === 'give_test' && one) {
      if (!window.confirm(confirmTest)) return;
      var ft = document.getElementById('opsGiveTestForm');
      var tid = ft && ft.querySelector('input[name="id"]');
      if (tid) tid.value = one.id;
      if (ft) ft.submit();
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
      if (one) window.location.href = 'sas.php?prepare=' + encodeURIComponent(one.id) + '&next=pay';
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
    return fetch('sas.php?ajax=profiles', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        profilesCache = (d && d.profiles) ? d.profiles : [];
        return profilesCache;
      });
  }
  function loadCards(username, profileId) {
    return fetch('sas.php?ajax=cards&username=' + encodeURIComponent(username || '') + '&profile_id=' + encodeURIComponent(profileId || '0'), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { return (d && d.cards) ? d.cards : []; });
  }
  function renderCards(cards) {
    var list = document.getElementById('sasActCards');
    var hint = document.getElementById('sasActCardHint');
    if (!list) return;
    if (!cards.length) {
      list.innerHTML = '';
      if (hint) hint.textContent = <?php echo json_encode($lang === 'en' ? 'No unused cards for this profile' : 'ماكو كروت غير مفعّلة لهذا البروفايل'); ?>;
      return;
    }
    if (hint) hint.textContent = <?php echo json_encode($lang === 'en' ? 'Unused cards for this profile' : 'الكروت غير المفعّلة حسب البروفايل'); ?>;
    list.innerHTML = cards.map(function (c, i) {
      return '<li><label><input type="radio" name="sas_card" value="' + String(c.pin).replace(/"/g, '') + '" data-card-id="' + (c.id || 0) + '"' + (i === 0 ? ' checked' : '') + '> <span>' + (c.label || c.pin) + '</span></label></li>';
    }).join('');
  }
  function currentActMode() {
    var on = document.querySelector('input[name="sas_act_mode"]:checked');
    return on ? on.value : 'card';
  }
  function toggleActMode() {
    var card = currentActMode() === 'card';
    var boxC = document.getElementById('sasActCardBox');
    var boxR = document.getElementById('sasActCreditBox');
    if (boxC) boxC.classList.toggle('hidden', !card);
    if (boxR) boxR.classList.toggle('hidden', card);
  }
  function openActModal(row) {
    actUser = row;
    var modal = document.getElementById('sasActModal');
    var who = document.getElementById('sasActWho');
    var err = document.getElementById('sasActErr');
    if (who) who.textContent = (row.name || row.id) + ' · ' + row.id;
    if (err) err.textContent = '';
    if (modal) modal.classList.remove('hidden');
    loadProfiles().then(function (ps) {
      fillSelect(document.getElementById('sasActProfile'), ps, row.profileId);
      return loadCards(row.id, document.getElementById('sasActProfile').value || row.profileId);
    }).then(renderCards).catch(function () { renderCards([]); });
  }
  function openProfModal(row) {
    actUser = row;
    var modal = document.getElementById('sasProfModal');
    var who = document.getElementById('sasProfWho');
    var err = document.getElementById('sasProfErr');
    if (who) who.textContent = (row.name || row.id) + ' · ' + row.id;
    if (err) err.textContent = '';
    if (modal) modal.classList.remove('hidden');
    loadProfiles().then(function (ps) {
      fillSelect(document.getElementById('sasProfSelect'), ps, row.profileId);
    });
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
      window.location.reload();
    }).catch(function () { alert(<?php echo json_encode($lang === 'en' ? 'Network error' : 'فشل الاتصال'); ?>); });
  }
  document.querySelectorAll('input[name="sas_act_mode"]').forEach(function (r) {
    r.addEventListener('change', toggleActMode);
  });
  var actProf = document.getElementById('sasActProfile');
  if (actProf) {
    actProf.addEventListener('change', function () {
      if (!actUser) return;
      loadCards(actUser.id, actProf.value).then(renderCards);
    });
  }
  var actClose = document.getElementById('sasActClose');
  if (actClose) actClose.addEventListener('click', function () {
    var m = document.getElementById('sasActModal');
    if (m) m.classList.add('hidden');
  });
  var profClose = document.getElementById('sasProfClose');
  if (profClose) profClose.addEventListener('click', function () {
    var m = document.getElementById('sasProfModal');
    if (m) m.classList.add('hidden');
  });
  var actSubmit = document.getElementById('sasActSubmit');
  if (actSubmit) {
    actSubmit.addEventListener('click', function () {
      if (!actUser) return;
      var err = document.getElementById('sasActErr');
      if (err) err.textContent = '';
      var profileSel = document.getElementById('sasActProfile');
      var profileId = profileSel ? profileSel.value : actUser.profileId;
      var profileName = profileSel && profileSel.selectedOptions[0] ? profileSel.selectedOptions[0].textContent : '';
      actSubmit.disabled = true;
      var req;
      if (currentActMode() === 'credit') {
        var units = document.getElementById('sasActUnits');
        req = postSas('sas_activate_credit', {
          id: actUser.id,
          profile_id: profileId,
          units: units ? units.value : '1'
        });
      } else {
        var picked = document.querySelector('input[name="sas_card"]:checked');
        if (!picked) {
          if (err) err.textContent = <?php echo json_encode($lang === 'en' ? 'Select an unused card' : 'اختر كرت غير مفعّل'); ?>;
          actSubmit.disabled = false;
          return;
        }
        req = postSas('sas_activate_card', {
          id: actUser.id,
          pin: picked.value,
          card_id: picked.getAttribute('data-card-id') || '0',
          profile_id: profileId
        });
      }
      req.then(function (d) {
        actSubmit.disabled = false;
        if (!d || !d.ok) {
          if (err) err.textContent = (d && d.message) || <?php echo json_encode($lang === 'en' ? 'Activate failed' : 'فشل التفعيل'); ?>;
          return;
        }
        window.location.reload();
      }).catch(function () {
        actSubmit.disabled = false;
        if (err) err.textContent = <?php echo json_encode($lang === 'en' ? 'Network error' : 'فشل الاتصال'); ?>;
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
  if (colsDrop) {
    colsDrop.addEventListener('change', function (e) {
      var inp = e.target;
      if (!inp || !inp.getAttribute('data-col')) return;
      var col = inp.getAttribute('data-col');
      document.querySelectorAll('#subsTable .col-' + col).forEach(function (el) {
        el.style.display = inp.checked ? '' : 'none';
      });
    });
  }

  function liveSearch() {
    if (!filter || !tbody) return;
    var q = filter.value.trim();
    if (q === '') {
      tbody.innerHTML = originalHtml;
      if (pager) pager.style.display = '';
      return;
    }
    if (pager) pager.style.display = 'none';
    fetch('sas.php?live=1&q=' + encodeURIComponent(q) + '&sub=<?php echo rawurlencode($subFilter); ?>')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.html) tbody.innerHTML = d.html;
      }).catch(function () {});
  }
  if (filter) {
    filter.addEventListener('input', function () {
      clearTimeout(liveTimer);
      liveTimer = setTimeout(liveSearch, 280);
    });
  }

  function showSyncNote(text) {
    var note = document.getElementById('sasSyncLive');
    if (!note) note = document.querySelector('.sas-sync-note');
    if (note && text) note.textContent = text;
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
  function runSync(reset) {
    var url = 'sas.php?ajax=pull&force=1' + (reset ? '&reset=1' : '');
    return fetch(url, { credentials: 'same-origin' }).then(parseSyncRes).then(function (d) {
      if (!d) return d;
      if (d.last_error && !d.ok) {
        showSyncNote(d.last_error);
        return d;
      }
      if (d.mode === 'progress') {
        showSyncNote((<?php echo json_encode($lang === 'en' ? 'Loading from SAS…' : 'جاري الجلب من الساس…'); ?>) + ' ' + (d.count || 0) + (d.expected ? (' / ' + d.expected) : ''));
        return runSync(false);
      }
      if (d.ok && (d.mode === 'synced' || d.mode === 'cache') && (d.count > 0 || d.mode === 'synced')) {
        window.location.reload();
      }
      return d;
    }).catch(function (err) {
      showSyncNote(err && err.message ? err.message : 'فشل الاتصال بالصفحة');
      return null;
    });
  }
  function runDiagThenSync() {
    showSyncNote(<?php echo json_encode($lang === 'en' ? 'Checking SAS…' : 'جاري فحص الاتصال بالساس…'); ?>);
    return fetch('sas.php?ajax=diag', { credentials: 'same-origin' }).then(parseSyncRes).then(function (d) {
      if (!d) {
        showSyncNote('فشل فحص SAS');
        return null;
      }
      if (d.steps && d.steps.length) {
        showSyncNote(d.steps.join(' | '));
      }
      if (!d.ok) {
        return d;
      }
      return runSync(true);
    });
  }
  var refreshBtn = document.getElementById('refreshTableBtn');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      refreshBtn.classList.add('is-on');
      runDiagThenSync().then(function () { refreshBtn.classList.remove('is-on'); });
    });
  }
  if (stale) {
    runDiagThenSync();
  }
})();
</script>
<?php
render_footer();
