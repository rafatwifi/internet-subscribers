<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$mode = isset($_GET['mode']) ? (string) $_GET['mode'] : 'send';
$filter = isset($_GET['filter']) ? (string) $_GET['filter'] : 'overdue';
// توافق مع الروابط القديمة: ?mode=debt|days|overdue
if (in_array($mode, array('debt', 'days', 'overdue'), true)) {
    $filter = $mode;
    $mode = 'send';
}
if (!in_array($mode, array('send', 'log', 'templates'), true)) {
    $mode = 'send';
}
if (!in_array($filter, array('debt', 'days', 'overdue'), true)) {
    $filter = 'overdue';
}

$logQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$logType = isset($_GET['type']) ? trim((string) $_GET['type']) : '';
$logPage = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$logPerPage = 40;

$daysMax = isset($_GET['days']) ? (int) $_GET['days'] : 7;
if ($daysMax < 0) {
    $daysMax = 0;
}

$afterDays = unpaid_remind_after_days($config);

$defaultDebtTpl = '';
if (isset($config['templates']['debt_remind'])) {
    $defaultDebtTpl = (string) $config['templates']['debt_remind'];
}
if ($defaultDebtTpl === '') {
    $defaultDebtTpl = 'السلام عليكم {name} يرجى تسديد الديون البالغة {debt} لتجنب قطع الخدمة';
}

$defaultDaysTpl = '';
if (isset($config['templates']['days_left']) && trim((string) $config['templates']['days_left']) !== '') {
    $defaultDaysTpl = (string) $config['templates']['days_left'];
} else {
    $defaultDaysTpl = 'السلام عليكم {name} تبقى لديك {days} يوم على الاشتراك ({package})';
}

$defaultOverdueTpl = '';
if (isset($config['templates']['unpaid_overdue']) && trim((string) $config['templates']['unpaid_overdue']) !== '') {
    $defaultOverdueTpl = (string) $config['templates']['unpaid_overdue'];
} else {
    $defaultOverdueTpl = "السلام عليكم {name}\nمضى على تفعيل خطك {days_passed} أيام\nيرجى تسديد الديون البالغة {debt}\nوبعكسه سيتم إيقاف الخدمة";
}

$previewMsg = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
if ($previewMsg === '') {
    if ($filter === 'debt') {
        $previewMsg = $defaultDebtTpl;
    } elseif ($filter === 'days') {
        $previewMsg = $defaultDaysTpl;
    } else {
        $previewMsg = $defaultOverdueTpl;
    }
}
$agentScopeSql = function_exists('subscriber_agent_scope_sql') ? subscriber_agent_scope_sql('s') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $lang === 'en' ? 'Invalid request' : 'طلب غير صالح');
        redirect('messages.php');
    }

    $action = post('action');

    if ($action === 'save_templates') {
        $caseKeys = array(
            'activation_cash', 'activation_credit', 'activation_debts', 'activation_credit_debts',
            'debt_created', 'payment_ok', 'debt_remind', 'reminder_auto', 'days_left',
            'expiry_soon', 'schedule_cut'
        );

        $keysIn = isset($_POST['tpl_key']) && is_array($_POST['tpl_key']) ? $_POST['tpl_key'] : array();
        $labelsIn = isset($_POST['tpl_label']) && is_array($_POST['tpl_label']) ? $_POST['tpl_label'] : array();
        $bodiesIn = isset($_POST['tpl_body']) && is_array($_POST['tpl_body']) ? $_POST['tpl_body'] : array();

        $catalog = array();
        $n = max(count($keysIn), count($labelsIn), count($bodiesIn));
        for ($i = 0; $i < $n; $i++) {
            $rawKey = isset($keysIn[$i]) ? (string) $keysIn[$i] : '';
            $label = isset($labelsIn[$i]) ? trim((string) $labelsIn[$i]) : '';
            $body = isset($bodiesIn[$i]) ? (string) $bodiesIn[$i] : '';
            $key = function_exists('wa_sanitize_tpl_key') ? wa_sanitize_tpl_key($rawKey) : preg_replace('/[^a-z0-9_]/', '', strtolower($rawKey));
            if ($key === '' && $label !== '') {
                $slug = function_exists('wa_sanitize_tpl_key') ? wa_sanitize_tpl_key($label) : '';
                if ($slug === '') {
                    $slug = 'tpl_' . substr(md5($label . '|' . $i . '|' . microtime(true)), 0, 8);
                }
                $key = $slug;
            }
            if ($key === '') {
                continue;
            }
            if ($label === '') {
                $label = $key;
            }
            // Avoid collisions: keep first, rename later duplicates.
            $base = $key;
            $suffix = 2;
            while (isset($catalog[$key])) {
                $key = $base . '_' . $suffix;
                $suffix++;
                if ($suffix > 50) {
                    $key = $base . '_' . substr(md5(uniqid('', true)), 0, 6);
                    break;
                }
            }
            $catalog[$key] = array(
                'label' => $label,
                'body' => $body,
            );
        }

        // Optional single "add new" fields.
        $newLabel = trim((string) post('tpl_new_label', ''));
        $newBody = (string) post('tpl_new_body', '');
        $newKeyRaw = trim((string) post('tpl_new_key', ''));
        if ($newLabel !== '' || trim($newBody) !== '') {
            $nk = function_exists('wa_sanitize_tpl_key') ? wa_sanitize_tpl_key($newKeyRaw !== '' ? $newKeyRaw : $newLabel) : '';
            if ($nk === '') {
                $nk = 'tpl_' . substr(md5($newLabel . microtime(true)), 0, 8);
            }
            $base = $nk;
            $suffix = 2;
            while (isset($catalog[$nk])) {
                $nk = $base . '_' . $suffix;
                $suffix++;
            }
            $catalog[$nk] = array(
                'label' => $newLabel !== '' ? $newLabel : $nk,
                'body' => $newBody,
            );
        }

        if (!$catalog) {
            flash('error', $lang === 'en' ? 'Keep at least one template.' : 'لازم يبقى قالب واحد على الأقل.');
            redirect('messages.php?mode=templates');
        }

        $allowedKeys = array_keys($catalog);
        $payload = array(
            'wa_templates' => $catalog,
        );

        // Mirror legacy tpl_* for schedule/settings pages that still edit those fields.
        $legacyMap = function_exists('wa_legacy_tpl_field_map') ? wa_legacy_tpl_field_map() : array();
        foreach ($legacyMap as $legacyField => $tplKey) {
            if (isset($catalog[$tplKey]['body'])) {
                $payload[$legacyField] = (string) $catalog[$tplKey]['body'];
            }
        }
        // Case bindings from form (optional)
        foreach ($caseKeys as $ck) {
            $v = trim((string) post('wa_case_' . $ck, ''));
            $v = function_exists('wa_sanitize_tpl_key') ? wa_sanitize_tpl_key($v) : $v;
            if ($v !== '') {
                $payload['wa_case_' . $ck] = $v;
            }
        }

        $tidTpl = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        if ($tidTpl > 1 && function_exists('tenant_wa_templates_save')) {
            $okSave = tenant_wa_templates_save($pdo, $catalog, $tidTpl);
            if ($okSave && function_exists('tenant_apply_wa_templates_to_config')) {
                tenant_apply_wa_templates_to_config($config, $pdo);
            }
            flash($okSave ? 'success' : 'error', $okSave ? t('saved') : ($lang === 'en' ? 'Save failed' : 'فشل الحفظ'));
            redirect('messages.php?mode=templates');
        }

        foreach ($legacyMap as $tKey => $field) {
            $payload[$field] = isset($catalog[$tKey]['body']) ? $catalog[$tKey]['body'] : '';
        }

        foreach ($caseKeys as $ck) {
            $v = trim((string) post('wa_case_' . $ck, ''));
            if ($v === '' || $v === '__none__') {
                $payload['wa_case_' . $ck] = '__none__';
                continue;
            }
            $v = function_exists('wa_sanitize_tpl_key') ? wa_sanitize_tpl_key($v) : $v;
            if (!in_array($v, $allowedKeys, true)) {
                $payload['wa_case_' . $ck] = '__none__';
                continue;
            }
            $payload['wa_case_' . $ck] = $v;
        }

        $okSave = settings_save($payload);
        flash($okSave ? 'success' : 'error', $okSave ? t('saved') : 'Cannot write settings.json');
        redirect('messages.php?mode=templates');
    }

    $modePost = post('mode', 'send');
    $filterPost = post('filter', post('mode', 'overdue'));
    if (in_array($modePost, array('debt', 'days', 'overdue'), true)) {
        $filterPost = $modePost;
        $modePost = 'send';
    }
    if (!in_array($filterPost, array('debt', 'days', 'overdue'), true)) {
        $filterPost = 'overdue';
    }
    $mode = 'send';
    $filter = $filterPost;
    $msgTpl = (string) post('msg', '');
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : array();

    if ($action === 'retry_log') {
        $logId = (int) post('log_id', '0');
        list($ok, $msg) = retry_failed_message($pdo, $config, $logId, 0);
        flash($ok ? 'success' : 'error', $msg);
        $redir = 'messages.php?mode=log';
        $rq = trim((string) post('q', ''));
        if ($rq !== '') {
            $redir .= '&q=' . rawurlencode($rq);
        }
        $rp = (int) post('page', '1');
        if ($rp > 1) {
            $redir .= '&page=' . $rp;
        }
        redirect($redir);
    }

    if ($action === 'delete_log') {
        $logId = (int) post('log_id', '0');
        list($ok, $msg) = function_exists('delete_failed_message_log')
            ? delete_failed_message_log($pdo, $logId)
            : array(false, 'غير متاح');
        flash($ok ? 'success' : 'error', $msg);
        $redir = 'messages.php?mode=log';
        $rq = trim((string) post('q', ''));
        if ($rq !== '') {
            $redir .= '&q=' . rawurlencode($rq);
        }
        $rt = trim((string) post('type', ''));
        if ($rt !== '') {
            $redir .= '&type=' . rawurlencode($rt);
        }
        $rp = (int) post('page', '1');
        if ($rp > 1) {
            $redir .= '&page=' . $rp;
        }
        redirect($redir);
    }

    if ($action === 'disable_users') {
        $okN = 0;
        $failN = 0;
        $skipped = 0;
        foreach ($ids as $idRaw) {
            $id = (int) $idRaw;
            if ($id <= 0) {
                continue;
            }
            if (function_exists('user_can_access_subscriber') && !user_can_access_subscriber($pdo, $id)) {
                $skipped++;
                continue;
            }
            $st = $pdo->prepare('SELECT id, name, sas_username FROM subscribers WHERE id = :id LIMIT 1');
            $st->execute(array(':id' => $id));
            $sub = $st->fetch();
            if (!$sub) {
                $skipped++;
                continue;
            }
            $username = trim((string) (isset($sub['sas_username']) ? $sub['sas_username'] : ''));
            if ($username === '') {
                $st2 = $pdo->prepare('SELECT username FROM sas_users_cache WHERE local_subscriber_id = :id LIMIT 1');
                $st2->execute(array(':id' => $id));
                $username = trim((string) $st2->fetchColumn());
            }
            if ($username === '' || !function_exists('sas_write_user')) {
                $failN++;
                continue;
            }
            if (function_exists('user_can_access_sas_username') && !user_can_access_sas_username($pdo, $username)) {
                $skipped++;
                continue;
            }
            list($okDis, $msgDis) = sas_write_user($pdo, $config, 'sas_enable', $username, array('enabled' => '0'));
            if ($okDis) {
                $okN++;
            } else {
                $failN++;
            }
            usleep(120000);
        }
        $msg = ($lang === 'en' ? 'Disabled: ' : 'تم الإيقاف: ') . $okN
            . ($lang === 'en' ? ' / Failed: ' : ' / فشل: ') . $failN;
        if ($skipped > 0) {
            $msg .= ($lang === 'en' ? ' / Skipped: ' : ' / تخطي: ') . $skipped;
        }
        flash($failN > 0 && $okN === 0 ? 'error' : 'success', $msg);
        $redir = 'messages.php?mode=send&filter=' . rawurlencode($filter);
        if ($filter === 'days') {
            $redir .= '&days=' . (int) post('days', '7');
        }
        redirect($redir);
    }

    if ($action === 'send') {
        $ok = 0;
        $fail = 0;
        $skipped = 0;

        if ($filter === 'debt') {
            foreach ($ids as $idRaw) {
                $id = (int) $idRaw;
                if ($id <= 0) {
                    continue;
                }
                $st = $pdo->prepare(
                    'SELECT s.id, s.name, s.phone,
                        (SELECT COALESCE(SUM(amount),0) FROM invoices i
                         WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt_total
                     FROM subscribers s WHERE s.id = :id'
                );
                $st->execute(array(':id' => $id));
                $sub = $st->fetch();
                if (!$sub || (float) $sub['debt_total'] <= 0) {
                    $skipped++;
                    continue;
                }
                $row = array(
                    'name' => $sub['name'],
                    'phone' => $sub['phone'],
                    'month_label' => date('Y-m'),
                    'amount' => (float) $sub['debt_total'],
                    'debt_total' => (float) $sub['debt_total'],
                    'notes' => '',
                );
                if (trim($msgTpl) !== '') {
                    $body = tpl_fill($msgTpl, array(
                        'name' => $sub['name'],
                        'debt' => money_format_iqd($sub['debt_total'], $config['currency']),
                        'amount' => money_format_iqd($sub['debt_total'], $config['currency']),
                        'month' => month_short_label(date('Y-m')),
                        'notes' => '',
                    ));
                } else {
                    $body = reminder_message($row, $config);
                }
                $result = whatsapp_send($config, $sub['phone'], $body, 'bulk_debt');
                log_message($pdo, $id, $result);
                if (!empty($result['success'])) {
                    $ok++;
                } else {
                    $fail++;
                }
                usleep(350000);
            }
        } elseif ($filter === 'overdue') {
            if (empty($config['unpaid_remind_enabled'])) {
                flash('error', $lang === 'en'
                    ? 'Enable “warn after N days” in Schedule settings first.'
                    : 'فعّل «تنبيه بعد * يوم من التفعيل» من إعدادات الجدول الدوري أولاً.');
                redirect('messages.php');
            }
            $afterDays = unpaid_remind_after_days($config);
            foreach ($ids as $idRaw) {
                $id = (int) $idRaw;
                if ($id <= 0) {
                    continue;
                }
                $st = $pdo->prepare(
                    'SELECT s.id, s.name, s.phone,
                        (SELECT COALESCE(SUM(amount),0) FROM invoices i
                         WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt_total,
                        (SELECT sub.service_name FROM subscriptions sub
                            WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
                            ORDER BY sub.id DESC LIMIT 1) AS active_service,
                        (SELECT sub.start_date FROM subscriptions sub
                            WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
                            ORDER BY sub.id DESC LIMIT 1) AS active_start
                     FROM subscribers s WHERE s.id = :id'
                );
                $st->execute(array(':id' => $id));
                $sub = $st->fetch();
                if (!$sub || empty($sub['active_start']) || (float) $sub['debt_total'] <= 0) {
                    $skipped++;
                    continue;
                }
                $daysPassed = days_since_date($sub['active_start']);
                if ($daysPassed < $afterDays) {
                    $skipped++;
                    continue;
                }
                if (trim($msgTpl) !== '') {
                    $body = tpl_fill($msgTpl, array(
                        'name' => $sub['name'],
                        'days_passed' => (string) $daysPassed,
                        'debt' => money_format_iqd($sub['debt_total'], $config['currency']),
                        'amount' => money_format_iqd($sub['debt_total'], $config['currency']),
                        'package' => !empty($sub['active_service']) ? $sub['active_service'] : '',
                    ));
                } else {
                    $body = unpaid_overdue_message(array(
                        'name' => $sub['name'],
                        'days_passed' => $daysPassed,
                        'debt_total' => (float) $sub['debt_total'],
                        'package' => !empty($sub['active_service']) ? $sub['active_service'] : '',
                    ), $config);
                }
                $result = whatsapp_send($config, $sub['phone'], $body, 'bulk_overdue');
                log_message($pdo, $id, $result);
                if (!empty($result['success'])) {
                    $ok++;
                } else {
                    $fail++;
                }
                usleep(350000);
            }
        } else {
            $daysMax = (int) post('days', '7');
            $skipReasons = array();
            foreach ($ids as $idRaw) {
                $id = (int) $idRaw;
                if ($id <= 0) {
                    continue;
                }
                if (function_exists('user_can_access_subscriber') && !user_can_access_subscriber($pdo, $id)) {
                    $skipped++;
                    $skipReasons[] = '#' . $id . ': بدون صلاحية';
                    continue;
                }
                $st = $pdo->prepare(
                    'SELECT s.id AS subscriber_id, s.name, s.phone, s.sas_username,
                        (SELECT COALESCE(SUM(amount),0) FROM invoices i
                         WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt_total
                     FROM subscribers s WHERE s.id = :id LIMIT 1'
                );
                $st->execute(array(':id' => $id));
                $sub = $st->fetch();
                if (!$sub) {
                    $skipped++;
                    $skipReasons[] = '#' . $id . ': مشترك غير موجود';
                    continue;
                }
                $endDate = '';
                $startDate = '';
                $serviceName = '';
                try {
                    $stExp = $pdo->prepare(
                        'SELECT expire_at, profile_name, phone FROM sas_users_cache
                         WHERE (local_subscriber_id = :id)
                            OR (username = :u AND :u <> \'\')
                         ORDER BY expire_at IS NULL, expire_at ASC
                         LIMIT 1'
                    );
                    $uname = isset($sub['sas_username']) ? trim((string) $sub['sas_username']) : '';
                    $stExp->execute(array(':id' => $id, ':u' => $uname));
                    $exp = $stExp->fetch();
                    if ($exp && !empty($exp['expire_at'])) {
                        $endDate = date('Y-m-d', strtotime((string) $exp['expire_at']));
                        $startDate = date('Y-m-d', strtotime($endDate . ' -30 days'));
                        $serviceName = isset($exp['profile_name']) ? (string) $exp['profile_name'] : '';
                        if (!empty($exp['phone'])) {
                            $sub['_cache_phone'] = $exp['phone'];
                        }
                    }
                } catch (Exception $e) {
                    // ignore
                }
                if ($endDate === '') {
                    $stSub = $pdo->prepare(
                        'SELECT service_name, start_date, end_date, monthly_price FROM subscriptions
                         WHERE subscriber_id = :id AND status = "active"
                         ORDER BY end_date ASC, id DESC LIMIT 1'
                    );
                    $stSub->execute(array(':id' => $id));
                    $locSub = $stSub->fetch();
                    if ($locSub) {
                        $endDate = $locSub['end_date'];
                        $startDate = $locSub['start_date'];
                        $serviceName = $locSub['service_name'];
                        $sub['monthly_price'] = $locSub['monthly_price'];
                    }
                }
                if ($endDate === '') {
                    $skipped++;
                    $skipReasons[] = $sub['name'] . ': ماكو تاريخ انتهاء';
                    continue;
                }
                $phone = function_exists('subscriber_whatsapp_phone')
                    ? subscriber_whatsapp_phone(
                        $pdo,
                        $id,
                        phone_first_valid(array(
                            isset($sub['_cache_phone']) ? $sub['_cache_phone'] : '',
                            $sub['phone'],
                        ))
                    )
                    : (string) $sub['phone'];
                if ($phone === '' || (function_exists('phone_is_placeholder') && phone_is_placeholder($phone))) {
                    $skipped++;
                    $skipReasons[] = $sub['name'] . ': ماكو رقم هاتف صالح';
                    continue;
                }
                // لا تعيد إرسال قرب الانتهاء لنفس تاريخ النهاية
                if (function_exists('subscribers_expiry_notice_map')) {
                    $already = subscribers_expiry_notice_map($pdo, array(array(
                        'subscriber_id' => $id,
                        'end_date' => $endDate,
                    )));
                    if (!empty($already[$id])) {
                        $skipped++;
                        $skipReasons[] = $sub['name'] . ': تم إرسال إشعار مسبقاً';
                        continue;
                    }
                }
                $info = subscription_days_info($startDate !== '' ? $startDate : date('Y-m-d', strtotime($endDate . ' -30 days')), $endDate);
                $row = array(
                    'subscriber_id' => $id,
                    'name' => $sub['name'],
                    'phone' => $phone,
                    'service_name' => $serviceName,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'monthly_price' => isset($sub['monthly_price']) ? $sub['monthly_price'] : 0,
                    'debt_total' => $sub['debt_total'],
                );
                if (trim($msgTpl) !== '') {
                    $body = tpl_fill($msgTpl, array(
                        'name' => $row['name'],
                        'days' => (string) (int) $info['left'],
                        'package' => $row['service_name'],
                        'from' => $row['start_date'],
                        'to' => $row['end_date'],
                        'amount' => money_format_iqd($row['monthly_price'], $config['currency']),
                        'debt' => ((float) $row['debt_total'] > 0)
                            ? money_format_iqd($row['debt_total'], $config['currency'])
                            : '',
                        'month' => month_short_label(date('Y-m', strtotime($row['start_date'] ? $row['start_date'] : 'now'))),
                    ));
                    if ((float) $row['debt_total'] > 0) {
                        $debtFmt = money_format_iqd($row['debt_total'], $config['currency']);
                        if (strpos($body, $debtFmt) === false) {
                            $body .= "\nعليك دين بمبلغ " . $debtFmt;
                        }
                    }
                } else {
                    $body = days_left_message(array(
                        'name' => $row['name'],
                        'days' => (int) $info['left'],
                        'package' => $row['service_name'],
                        'debt_total' => (float) $row['debt_total'],
                    ), $config);
                }
                $result = whatsapp_send($config, $phone, $body, 'bulk_filter');
                log_message($pdo, $id, $result);
                if (!empty($result['success'])) {
                    $ok++;
                    if (function_exists('mark_expiry_notice_sent')) {
                        mark_expiry_notice_sent($pdo, $id, $endDate);
                    }
                } elseif (!empty($result['skipped'])) {
                    $skipped++;
                    $why = isset($result['response']) ? (string) $result['response'] : 'تخطّي';
                    $skipReasons[] = $row['name'] . ': ' . $why;
                } else {
                    $fail++;
                }
                usleep(350000);
            }
            if ($skipped > 0 && $skipReasons) {
                $extra = implode(' | ', array_slice($skipReasons, 0, 5));
                if (count($skipReasons) > 5) {
                    $extra .= ' …';
                }
                // يُلحق لاحقاً برسالة الفلاش عبر متغير عام بسيط
                $GLOBALS['_msg_skip_detail'] = $extra;
            }
        }

        $msg = ($lang === 'en' ? 'Sent OK: ' : 'تم الإرسال: ') . $ok
            . ($lang === 'en' ? ' / Failed: ' : ' / فشل: ') . $fail;
        if ($skipped > 0) {
            $msg .= ($lang === 'en' ? ' / Skipped: ' : ' / تخطي: ') . $skipped;
            if (!empty($GLOBALS['_msg_skip_detail'])) {
                $msg .= ' — ' . $GLOBALS['_msg_skip_detail'];
                unset($GLOBALS['_msg_skip_detail']);
            }
        }
        flash($fail > 0 && $ok === 0 ? 'error' : 'success', $msg);
        $redir = 'messages.php?mode=send&filter=' . rawurlencode($filter);
        if ($filter === 'days') {
            $redir .= '&days=' . (int) post('days', '7');
        }
        redirect($redir);
    }
}

$pdo->exec(
    "UPDATE subscriptions SET status = 'expired'
     WHERE status = 'active' AND end_date < CURDATE()"
);

$filtered = array();
$logRows = array();
$logResolvedMap = array();
$logTotal = 0;
$logPages = 1;
$daysEmptyHint = '';

if ($mode === 'log') {
    $where = '1=1';
    $params = array();
    if (function_exists('subscriber_agent_scope_sql')) {
        // سجلات بدون مشترك تظهر للأدمن فقط داخل الشركة؛ للوكيل نخفيها إن لم تطابق
        $scopeLog = subscriber_agent_scope_sql('s');
        if (is_agent_user()) {
            $where .= ' AND m.subscriber_id IS NOT NULL' . $scopeLog;
        } elseif ($scopeLog !== '') {
            $where .= ' AND (m.subscriber_id IS NULL OR (1=1' . $scopeLog . '))';
        }
    }
    if ($logQ !== '') {
        $where .= ' AND (m.body LIKE :q OR m.phone LIKE :q OR m.message_type LIKE :q OR s.name LIKE :q)';
        $params[':q'] = '%' . $logQ . '%';
    }
    $autoTypes = array('expiry_auto', 'reminder_auto', 'unpaid_overdue', 'bulk_overdue', 'days_left', 'remind_days');
    if ($logType === 'auto') {
        $ins = array();
        foreach ($autoTypes as $i => $t) {
            $k = ':lt' . $i;
            $ins[] = $k;
            $params[$k] = $t;
        }
        $where .= ' AND m.message_type IN (' . implode(',', $ins) . ')';
    } elseif ($logType !== '') {
        $where .= ' AND m.message_type = :ltype';
        $params[':ltype'] = $logType;
    }
    $stCount = $pdo->prepare(
        "SELECT COUNT(*) FROM message_logs m
         LEFT JOIN subscribers s ON s.id = m.subscriber_id
         WHERE $where"
    );
    $stCount->execute($params);
    $logTotal = (int) $stCount->fetchColumn();
    $logPages = max(1, (int) ceil($logTotal / $logPerPage));
    if ($logPage > $logPages) {
        $logPage = $logPages;
    }
    $offset = ($logPage - 1) * $logPerPage;
    $st = $pdo->prepare(
        "SELECT m.*, s.name AS subscriber_name
         FROM message_logs m
         LEFT JOIN subscribers s ON s.id = m.subscriber_id
         WHERE $where
         ORDER BY m.id DESC
         LIMIT " . (int) $logPerPage . ' OFFSET ' . (int) $offset
    );
    $st->execute($params);
    $logRows = $st->fetchAll();
    $logResolvedMap = message_logs_resolved_map($pdo, $logRows);
} elseif ($mode === 'templates') {
    $sTpl = settings_load();
} elseif ($mode === 'send' && $filter === 'debt') {
    $filtered = $pdo->query(
        "SELECT s.id, s.name, s.phone,
            d.debt_total, d.debt_count
         FROM subscribers s
         INNER JOIN (
            SELECT subscriber_id,
                   COALESCE(SUM(amount),0) AS debt_total,
                   COUNT(*) AS debt_count
            FROM invoices
            WHERE status = 'unpaid'
            GROUP BY subscriber_id
         ) d ON d.subscriber_id = s.id
         WHERE d.debt_total > 0" . $agentScopeSql . "
         ORDER BY d.debt_total DESC, s.name ASC"
    )->fetchAll();
} elseif ($mode === 'send' && $filter === 'overdue') {
    $candidates = $pdo->query(
        "SELECT s.id, s.name, s.phone,
            d.debt_total, d.debt_count,
            (SELECT sub.service_name FROM subscriptions sub
                WHERE sub.subscriber_id = s.id AND sub.status = 'active' AND sub.end_date >= CURDATE()
                ORDER BY sub.id DESC LIMIT 1) AS active_service,
            (SELECT sub.start_date FROM subscriptions sub
                WHERE sub.subscriber_id = s.id AND sub.status = 'active' AND sub.end_date >= CURDATE()
                ORDER BY sub.id DESC LIMIT 1) AS active_start
         FROM subscribers s
         INNER JOIN (
            SELECT subscriber_id,
                   COALESCE(SUM(amount),0) AS debt_total,
                   COUNT(*) AS debt_count
            FROM invoices
            WHERE status = 'unpaid'
            GROUP BY subscriber_id
         ) d ON d.subscriber_id = s.id
         WHERE d.debt_total > 0" . $agentScopeSql . "
         ORDER BY s.name ASC"
    )->fetchAll();
    foreach ($candidates as $row) {
        if (empty($row['active_start'])) {
            continue;
        }
        $daysPassed = days_since_date($row['active_start']);
        if ($daysPassed >= $afterDays) {
            $row['_days_passed'] = $daysPassed;
            $filtered[] = $row;
        }
    }
    usort($filtered, function ($a, $b) {
        return (int) $b['_days_passed'] - (int) $a['_days_passed'];
    });
} elseif ($mode === 'send' && $filter === 'days') {
    $seenSubIds = array();
    // تحديث صامت لبيانات الانتهاء قبل العرض
    if (function_exists('sas_maybe_background_sync')) {
        try {
            @sas_maybe_background_sync($pdo, $config, true);
        } catch (Exception $e) {
            // ignore
        }
    }
    $sasCandidates = function_exists('sas_list_expiring_rows')
        ? sas_list_expiring_rows($pdo, $daysMax, 3000)
        : array();

    $cacheWithExpire = 0;
    try {
        $cacheWithExpire = (int) $pdo->query(
            "SELECT COUNT(*) FROM sas_users_cache WHERE expire_at IS NOT NULL AND expire_at > '2000-01-01'"
        )->fetchColumn();
    } catch (Exception $e) {
        $cacheWithExpire = count($sasCandidates);
    }

    $agentIdFilter = 0;
    if (function_exists('is_agent_user') && is_agent_user()
        && !(function_exists('current_admin_sas_manager_id') && current_admin_sas_manager_id() > 0)
        && function_exists('current_admin')
    ) {
        $me = current_admin();
        $agentIdFilter = $me ? (int) $me['id'] : 0;
    }

    foreach ($sasCandidates as $crow) {
        $sid = isset($crow['sub_id']) ? (int) $crow['sub_id'] : 0;
        if ($sid <= 0 && !empty($crow['local_subscriber_id'])) {
            $sid = (int) $crow['local_subscriber_id'];
        }
        if ($sid <= 0 && function_exists('sas_cache_ensure_local')) {
            list($sid, $errLink) = sas_cache_ensure_local($pdo, $config, $crow);
            $sid = (int) $sid;
            if ($sid > 0) {
                try {
                    $stN = $pdo->prepare('SELECT name, phone, agent_user_id FROM subscribers WHERE id = :id LIMIT 1');
                    $stN->execute(array(':id' => $sid));
                    $loc = $stN->fetch();
                    if ($loc) {
                        $crow['sub_name'] = $loc['name'];
                        $crow['sub_phone'] = $loc['phone'];
                        $crow['agent_user_id'] = $loc['agent_user_id'];
                    }
                } catch (Exception $e) {
                    // ignore
                }
            }
        }
        if ($sid <= 0) {
            continue;
        }
        if ($agentIdFilter > 0) {
            $aid = isset($crow['agent_user_id']) ? (int) $crow['agent_user_id'] : 0;
            if ($aid !== $agentIdFilter) {
                continue;
            }
        }
        if (isset($seenSubIds[$sid])) {
            continue;
        }
        $left = isset($crow['_days']) ? (int) $crow['_days'] : 0;
        $endDate = date('Y-m-d', strtotime((string) $crow['expire_at']));
        $startDate = date('Y-m-d', strtotime($endDate . ' -30 days'));
        $info = subscription_days_info($startDate, $endDate);
        $name = !empty($crow['sub_name']) ? (string) $crow['sub_name']
            : (!empty($crow['display_name']) ? (string) $crow['display_name'] : (string) $crow['username']);
        $phone = '';
        if (function_exists('phone_first_valid')) {
            $phone = phone_first_valid(array(
                isset($crow['sas_phone']) ? $crow['sas_phone'] : '',
                isset($crow['sub_phone']) ? $crow['sub_phone'] : '',
            ));
        }
        if ($phone === '' && function_exists('subscriber_whatsapp_phone')) {
            $phone = subscriber_whatsapp_phone($pdo, $sid, isset($crow['sub_phone']) ? $crow['sub_phone'] : '');
        }
        if ($phone === '') {
            $phone = !empty($crow['sas_phone']) ? (string) $crow['sas_phone']
                : (isset($crow['sub_phone']) ? (string) $crow['sub_phone'] : '');
        }
        $filtered[] = array(
            'id' => $sid,
            'subscriber_id' => $sid,
            'name' => $name,
            'phone' => $phone,
            'service_name' => isset($crow['profile_name']) ? (string) $crow['profile_name'] : '',
            'start_date' => $startDate,
            'end_date' => $endDate,
            '_days' => $left,
            '_pct' => (int) $info['pct'],
        );
        $seenSubIds[$sid] = true;
    }
    // احتياطي: اشتراكات محلية
    try {
        $candidates = $pdo->query(
            "SELECT sub.*, s.name, s.phone
             FROM subscriptions sub
             JOIN subscribers s ON s.id = sub.subscriber_id
             WHERE sub.status = 'active'
               AND sub.end_date >= CURDATE()"
            . $agentScopeSql . "
             ORDER BY sub.end_date ASC
             LIMIT 800"
        )->fetchAll();
    } catch (Exception $e) {
        $candidates = array();
    }
    foreach ($candidates as $row) {
        $sid = (int) $row['subscriber_id'];
        if (isset($seenSubIds[$sid])) {
            continue;
        }
        $info = subscription_days_info($row['start_date'], $row['end_date']);
        if ((int) $info['left'] >= 0 && (int) $info['left'] <= $daysMax) {
            $row['_days'] = (int) $info['left'];
            $row['_pct'] = (int) $info['pct'];
            $filtered[] = $row;
            $seenSubIds[$sid] = true;
        }
    }
    usort($filtered, function ($a, $b) {
        return (int) $a['_days'] - (int) $b['_days'];
    });
    if ($filtered && function_exists('subscribers_expiry_notice_map')) {
        $noticeMap = subscribers_expiry_notice_map($pdo, $filtered);
        foreach ($filtered as $i => $row) {
            $sid = isset($row['subscriber_id']) ? (int) $row['subscriber_id'] : (int) $row['id'];
            $filtered[$i]['_notified'] = !empty($noticeMap[$sid]);
        }
    }
    if (!$filtered) {
        $hintEn = ($lang === 'en');
        if ($cacheWithExpire <= 0) {
            $daysEmptyHint = $hintEn
                ? 'Expiry dates are still updating in the background — wait a few seconds and press Show again.'
                : 'تواريخ الانتهاء عم تتحدث بالخلفية — انتظر ثواني واضغط عرض مرة ثانية.';
        } else {
            $daysEmptyHint = $hintEn
                ? ('No one within ' . $daysMax . ' day(s) right now. Try a larger number.')
                : ('حالياً ماكو أحد ضمن ' . $daysMax . ' يوم. جرّب رقم أكبر.');
        }
    }
}

render_header(t('messages'), 'messages');
$isEnMsg = ($lang === 'en');
$msgModes = array(
    'send' => array('label' => $isEnMsg ? 'Send' : 'الإرسال', 'hint' => $isEnMsg ? 'Filter & WhatsApp' : 'فلترة وواتساب'),
    'templates' => array('label' => t('templates'), 'hint' => $isEnMsg ? 'Edit & assign' : 'تعديل وتخصيص'),
    'log' => array('label' => $isEnMsg ? 'Sent log' : 'سجل الرسائل', 'hint' => $isEnMsg ? 'History' : 'الأرشيف'),
);
$sendFilters = array(
    'overdue' => array('label' => $isEnMsg ? 'Late payers' : 'المتأخرين بالتسديد', 'hint' => $isEnMsg ? 'Unpaid after activation' : 'دين بعد التفعيل'),
    'debt' => array('label' => t('msg_mode_debt'), 'hint' => $isEnMsg ? 'Everyone with debt' : 'عليهم دين'),
    'days' => array('label' => t('msg_mode_days'), 'hint' => $isEnMsg ? 'Expiring soon' : 'قرب الانتهاء'),
);
?>
<div class="msg-page">
    <style>
      .msg-sent-badge {
        display: inline-flex; align-items: center; gap: 5px;
        margin-inline-start: 8px; font-size: 12px; font-weight: 600; color: #15803d;
        background: #dcfce7; border-radius: 999px; padding: 2px 8px; white-space: nowrap;
      }
      .msg-sent-badge .dot-msg {
        width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: #22c55e;
      }
      tr.msg-row-notified td { background: rgba(34, 197, 94, 0.04); }
    </style>
    <div class="msg-hero">
        <div class="msg-hero-text">
            <h2><?php echo e(t('messages')); ?></h2>
            <p><?php echo e($isEnMsg
                ? 'Bulk WhatsApp and templates — delivery log at the end.'
                : 'إرسال جماعي والقوالب — سجل الرسائل بالآخر.'); ?></p>
        </div>
        <nav class="msg-tabs" aria-label="<?php echo e($isEnMsg ? 'Message sections' : 'أقسام الرسائل'); ?>">
            <?php foreach ($msgModes as $mk => $meta): ?>
                <?php
                $href = 'messages.php?mode=' . rawurlencode($mk);
                if ($mk === 'send') {
                    $href .= '&filter=' . rawurlencode($filter);
                    if ($filter === 'days') {
                        $href .= '&days=' . (int) $daysMax;
                    }
                }
                ?>
                <a class="msg-tab<?php echo $mode === $mk ? ' is-on' : ''; ?>" href="<?php echo e($href); ?>">
                    <strong><?php echo e($meta['label']); ?></strong>
                    <span><?php echo e($meta['hint']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

<?php if ($mode === 'templates'): ?>
    <?php
    if (!isset($sTpl) || !is_array($sTpl)) {
        $sTpl = settings_load();
    }
    $catalog = isset($config['wa_templates']) && is_array($config['wa_templates'])
        ? $config['wa_templates']
        : (function_exists('wa_build_templates_catalog') ? wa_build_templates_catalog($sTpl, $lang) : array());
    $caseLabels = function_exists('wa_case_labels') ? wa_case_labels($lang) : array();
    $sysGroups = function_exists('wa_system_cases') ? wa_system_cases($lang) : array();
    $caseIssues = isset($config['wa_case_issues']) && is_array($config['wa_case_issues']) ? $config['wa_case_issues'] : array();
    $caseMap = isset($config['wa_cases']) && is_array($config['wa_cases']) ? $config['wa_cases'] : array();
    $warnMsgs = array();
    foreach ($sysGroups as $g) {
        foreach ($g['cases'] as $c) {
            $ck = $c['key'];
            $stored = isset($sTpl['wa_case_' . $ck]) ? trim((string) $sTpl['wa_case_' . $ck]) : null;
            $issue = null;
            if ($stored === '__none__') {
                $issue = 'unassigned';
            } elseif (isset($caseIssues[$ck])) {
                $issue = $caseIssues[$ck];
            } elseif ($stored !== null && $stored !== '' && !isset($catalog[$stored])) {
                $issue = 'missing_template';
            }
            $useKey = '';
            if ($stored !== null && $stored !== '' && $stored !== '__none__') {
                $useKey = $stored;
            } elseif (isset($caseMap[$ck])) {
                $useKey = (string) $caseMap[$ck];
            }
            if ($issue !== 'unassigned' && $useKey !== '' && isset($catalog[$useKey])
                && trim((string) $catalog[$useKey]['body']) === '') {
                $issue = 'empty_body';
            }
            if ($issue) {
                $warnMsgs[] = array(
                    'case' => $ck,
                    'text' => function_exists('wa_case_issue_message')
                        ? wa_case_issue_message($issue, $c['label'], $lang)
                        : $c['label'],
                );
            }
        }
    }
    $usedBy = array();
    foreach ($caseMap as $ck => $tk) {
        if ($ck === 'activation' || $tk === '' || $tk === '__none__') {
            continue;
        }
        if (!isset($usedBy[$tk])) {
            $usedBy[$tk] = array();
        }
        $usedBy[$tk][] = $ck;
    }
    $commonVars = '{name} {package} {from} {to} {amount} {debt} {month} {notes} {days} {days_passed} {remaining} {grace}';
    ?>
    <p class="meta tpl-lead">
        <?php echo e($isEnMsg
            ? 'Edit templates below, add or delete freely, then assign each system action to a template.'
            : 'عدّل القوالب بالأسفل، أضف أو احذف كما تريد، ثم خصّص كل حركة بالنظام لقالب.'); ?>
    </p>

    <?php if ($warnMsgs): ?>
        <div class="tpl-warn" role="alert">
            <strong><?php echo e($isEnMsg ? 'Missing assignment for important actions:' : 'ماكو تخصيص لأشياء مهمة:'); ?></strong>
            <ul>
                <?php foreach ($warnMsgs as $w): ?>
                    <li><a href="#case-<?php echo e($w['case']); ?>"><?php echo e($w['text']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="tpl-form" id="tplDynForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_templates">

        <section class="tpl-assign panel">
            <div class="tpl-section-head">
                <div>
                    <h3><?php echo e($isEnMsg ? 'Assign templates to actions' : 'تخصيص القوالب للحركات'); ?></h3>
                    <p class="meta"><?php echo e($isEnMsg
                        ? 'Assign a template for each activation case (cash, credit, credit + old debts, debts appendix).'
                        : 'خصّص قالباً لكل حالة تفعيل (نقدي، آجل، آجل + ديون قديمة، ملحق الديون).'); ?></p>
                </div>
            </div>
            <div id="tplCaseMap">
                <?php foreach ($sysGroups as $g): ?>
                    <div class="tpl-case-group">
                        <h4><?php echo e($g['group']); ?></h4>
                        <div class="tpl-case-grid">
                            <?php foreach ($g['cases'] as $c): ?>
                                <?php
                                $caseKey = $c['key'];
                                $caseLab = $c['label'];
                                $stored = isset($sTpl['wa_case_' . $caseKey]) ? trim((string) $sTpl['wa_case_' . $caseKey]) : null;
                                if ($stored === '__none__') {
                                    $sel = '__none__';
                                } elseif ($stored !== null && $stored !== '' && isset($catalog[$stored])) {
                                    $sel = $stored;
                                } else {
                                    $sel = isset($caseMap[$caseKey]) ? $caseMap[$caseKey] : '';
                                    if ($sel === '' || !isset($catalog[$sel])) {
                                        $sel = '__none__';
                                    }
                                }
                                $hasIssue = false;
                                foreach ($warnMsgs as $w) {
                                    if ($w['case'] === $caseKey) {
                                        $hasIssue = true;
                                        break;
                                    }
                                }
                                ?>
                                <label class="tpl-case-lab<?php echo $hasIssue ? ' is-warn' : ''; ?>" id="case-<?php echo e($caseKey); ?>">
                                    <span class="tpl-case-title"><?php echo e($caseLab); ?></span>
                                    <?php if (!empty($c['hint'])): ?>
                                        <span class="tpl-case-hint"><?php echo e($c['hint']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($c['vars'])): ?>
                                        <span class="tpl-case-vars"><?php echo e($c['vars']); ?></span>
                                    <?php endif; ?>
                                    <select name="wa_case_<?php echo e($caseKey); ?>" class="js-case-select">
                                        <option value="__none__"<?php echo $sel === '__none__' ? ' selected' : ''; ?>><?php echo e($isEnMsg ? '— Choose template —' : '— اختر قالباً —'); ?></option>
                                        <?php foreach ($catalog as $tk => $trow): ?>
                                            <option value="<?php echo e($tk); ?>"<?php echo $sel === $tk ? ' selected' : ''; ?>><?php echo e(isset($trow['label']) ? $trow['label'] : $tk); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="tpl-lib-toolbar">
            <div>
                <strong><?php echo e($isEnMsg ? 'Templates' : 'القوالب'); ?></strong>
                <span class="meta" id="tplCountHint"><?php echo count($catalog); ?></span>
            </div>
            <input type="search" id="tplSearch" class="tpl-search" placeholder="<?php echo e($isEnMsg ? 'Filter templates…' : 'تصفية القوالب…'); ?>" autocomplete="off">
            <button type="button" class="btn secondary sm" id="tplAddBtn"><?php echo e($isEnMsg ? '+ Add template' : '+ إضافة قالب'); ?></button>
        </div>

        <div class="tpl-new panel" id="tplNewBox" hidden>
            <div class="form-grid tpl-new-grid">
                <div>
                    <label><?php echo e($isEnMsg ? 'Name' : 'الاسم'); ?></label>
                    <input type="text" name="tpl_new_label" id="tplNewLabel" placeholder="<?php echo e($isEnMsg ? 'e.g. Ramadan offer' : 'مثال: عرض رمضان'); ?>">
                </div>
                <div>
                    <label><?php echo e($isEnMsg ? 'Key (optional)' : 'مفتاح (اختياري)'); ?></label>
                    <input type="text" name="tpl_new_key" id="tplNewKey" dir="ltr" placeholder="my_template">
                </div>
            </div>
            <label class="tpl-new-body-lab"><?php echo e($isEnMsg ? 'Message text' : 'نص الرسالة'); ?></label>
            <textarea name="tpl_new_body" id="tplNewBody" rows="3" placeholder="<?php echo e($commonVars); ?>"></textarea>
            <div class="actions actions-tight" style="margin-top:8px">
                <button type="button" class="btn sm" id="tplNewConfirm"><?php echo e($isEnMsg ? 'Add to list' : 'أضف للقائمة'); ?></button>
                <button type="button" class="btn ghost sm" id="tplNewCancel"><?php echo e($isEnMsg ? 'Cancel' : 'إلغاء'); ?></button>
            </div>
        </div>

        <div class="tpl-grid" id="tplLib">
            <?php foreach ($catalog as $tk => $trow): ?>
                <?php
                $inUseLabels = array();
                if (!empty($usedBy[$tk])) {
                    foreach ($usedBy[$tk] as $uck) {
                        $inUseLabels[] = isset($caseLabels[$uck]) ? $caseLabels[$uck] : $uck;
                    }
                }
                $bodyVal = isset($trow['body']) ? (string) $trow['body'] : '';
                $labVal = isset($trow['label']) ? (string) $trow['label'] : $tk;
                $searchCard = strtolower($tk . ' ' . $labVal . ' ' . $bodyVal);
                ?>
                <article class="tpl-card" data-tpl-card data-search="<?php echo e($searchCard); ?>">
                    <header class="tpl-card-head">
                        <input type="hidden" name="tpl_key[]" value="<?php echo e($tk); ?>">
                        <input type="text" name="tpl_label[]" class="tpl-label-input" value="<?php echo e($labVal); ?>" placeholder="<?php echo e($isEnMsg ? 'Template name' : 'اسم القالب'); ?>">
                        <span class="tpl-vars" title="<?php echo e($tk); ?>"><?php echo e($tk); ?></span>
                        <button type="button" class="btn ghost sm js-tpl-del"><?php echo e($isEnMsg ? 'Delete' : 'حذف'); ?></button>
                    </header>
                    <?php if ($inUseLabels): ?>
                        <p class="tpl-used"><?php echo e($isEnMsg ? 'Used by: ' : 'مستخدم في: '); ?><?php echo e(implode(' · ', $inUseLabels)); ?></p>
                    <?php endif; ?>
                    <textarea name="tpl_body[]" rows="4" class="js-tpl-body" placeholder="<?php echo e($commonVars); ?>"><?php echo e($bodyVal); ?></textarea>
                    <div class="tpl-card-foot">
                        <span class="tpl-len"><?php echo function_exists('mb_strlen') ? mb_strlen($bodyVal, 'UTF-8') : strlen($bodyVal); ?> <?php echo e($isEnMsg ? 'chars' : 'حرف'); ?></span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="tpl-save is-sticky">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
            <span class="meta"><?php echo e($isEnMsg ? 'Saves templates and action mapping together.' : 'يحفظ القوالب وتخصيص الحركات معاً.'); ?></span>
        </div>
    </form>

    <template id="tplCardTpl">
        <article class="tpl-card" data-tpl-card data-search="">
            <header class="tpl-card-head">
                <input type="hidden" name="tpl_key[]" value="">
                <input type="text" name="tpl_label[]" class="tpl-label-input" value="" placeholder="<?php echo e($isEnMsg ? 'Template name' : 'اسم القالب'); ?>">
                <span class="tpl-vars"></span>
                <button type="button" class="btn ghost sm js-tpl-del"><?php echo e($isEnMsg ? 'Delete' : 'حذف'); ?></button>
            </header>
            <textarea name="tpl_body[]" rows="4" class="js-tpl-body" placeholder="<?php echo e($commonVars); ?>"></textarea>
            <div class="tpl-card-foot"><span class="tpl-len">0 <?php echo e($isEnMsg ? 'chars' : 'حرف'); ?></span></div>
        </article>
    </template>
    <script>
    (function () {
      var form = document.getElementById('tplDynForm');
      var lib = document.getElementById('tplLib');
      var tplNode = document.getElementById('tplCardTpl');
      var addBtn = document.getElementById('tplAddBtn');
      var newBox = document.getElementById('tplNewBox');
      var newConfirm = document.getElementById('tplNewConfirm');
      var newCancel = document.getElementById('tplNewCancel');
      var search = document.getElementById('tplSearch');
      var countHint = document.getElementById('tplCountHint');
      var charsWord = <?php echo json_encode($isEnMsg ? 'chars' : 'حرف'); ?>;
      var confirmDel = <?php echo json_encode($isEnMsg
          ? 'Delete this template? Reassign actions that used it.'
          : 'تحذف هذا القالب؟ عيّن قالباً آخر للحركات اللي كانت تستخدمه.'); ?>;
      var needOne = <?php echo json_encode($isEnMsg ? 'Keep at least one template.' : 'لازم يبقى قالب واحد على الأقل.'); ?>;
      var pickLabel = <?php echo json_encode($isEnMsg ? '— Choose template —' : '— اختر قالباً —'); ?>;

      function slugify(s) {
        s = String(s || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
        if (!s) s = 'tpl_' + Math.random().toString(36).slice(2, 8);
        if (s.length > 40) s = s.slice(0, 40).replace(/_+$/, '');
        return s;
      }
      function existingKeys() {
        var keys = {}, inputs = lib ? lib.querySelectorAll('input[name="tpl_key[]"]') : [];
        for (var i = 0; i < inputs.length; i++) keys[inputs[i].value] = true;
        return keys;
      }
      function uniqueKey(base) {
        var keys = existingKeys(), k = slugify(base), n = 2;
        if (!keys[k]) return k;
        while (keys[k + '_' + n]) n++;
        return k + '_' + n;
      }
      function updateLen(card) {
        var ta = card.querySelector('.js-tpl-body');
        var el = card.querySelector('.tpl-len');
        if (!ta || !el) return;
        el.textContent = String(ta.value.length) + ' ' + charsWord;
      }
      function refreshCount() {
        if (!lib || !countHint) return;
        countHint.textContent = String(lib.querySelectorAll('[data-tpl-card]:not(.is-hidden)').length);
      }
      function rebuildCaseOptions() {
        var cards = lib ? lib.querySelectorAll('[data-tpl-card]') : [];
        var opts = [{ v: '__none__', t: pickLabel }];
        for (var i = 0; i < cards.length; i++) {
          var keyEl = cards[i].querySelector('input[name="tpl_key[]"]');
          var labEl = cards[i].querySelector('input[name="tpl_label[]"]');
          if (!keyEl) continue;
          opts.push({ v: keyEl.value, t: (labEl && labEl.value) ? labEl.value : keyEl.value });
        }
        var sels = document.querySelectorAll('.js-case-select');
        for (var s = 0; s < sels.length; s++) {
          var cur = sels[s].value;
          sels[s].innerHTML = '';
          for (var o = 0; o < opts.length; o++) {
            var op = document.createElement('option');
            op.value = opts[o].v;
            op.textContent = opts[o].t;
            sels[s].appendChild(op);
          }
          var ok = false;
          for (var o2 = 0; o2 < opts.length; o2++) if (opts[o2].v === cur) ok = true;
          sels[s].value = ok ? cur : '__none__';
        }
      }
      function bindCard(card) {
        var del = card.querySelector('.js-tpl-del');
        if (del) {
          del.addEventListener('click', function () {
            var cards = lib.querySelectorAll('[data-tpl-card]');
            if (cards.length <= 1) { alert(needOne); return; }
            if (!window.confirm(confirmDel)) return;
            card.parentNode.removeChild(card);
            rebuildCaseOptions();
            refreshCount();
          });
        }
        var lab = card.querySelector('input[name="tpl_label[]"]');
        if (lab) lab.addEventListener('input', rebuildCaseOptions);
        var ta = card.querySelector('.js-tpl-body');
        if (ta) {
          ta.addEventListener('input', function () { updateLen(card); });
          updateLen(card);
        }
      }
      function addTemplateFromNew() {
        var labelEl = document.getElementById('tplNewLabel');
        var keyEl = document.getElementById('tplNewKey');
        var bodyEl = document.getElementById('tplNewBody');
        var label = labelEl ? String(labelEl.value || '').trim() : '';
        var keyRaw = keyEl ? String(keyEl.value || '').trim() : '';
        var body = bodyEl ? String(bodyEl.value || '') : '';
        if (!label && !body) {
          alert(<?php echo json_encode($isEnMsg ? 'Enter a name or message text.' : 'اكتب اسم القالب أو نص الرسالة.'); ?>);
          return;
        }
        if (!label) label = keyRaw || 'template';
        var key = uniqueKey(keyRaw || label);
        var node = document.importNode(tplNode.content, true);
        var card = node.querySelector('[data-tpl-card]');
        card.querySelector('input[name="tpl_key[]"]').value = key;
        card.querySelector('input[name="tpl_label[]"]').value = label;
        var badge = card.querySelector('.tpl-vars');
        if (badge) badge.textContent = key;
        card.querySelector('textarea[name="tpl_body[]"]').value = body;
        card.setAttribute('data-search', (key + ' ' + label + ' ' + body).toLowerCase());
        lib.insertBefore(card, lib.firstChild);
        bindCard(card);
        rebuildCaseOptions();
        refreshCount();
        if (labelEl) labelEl.value = '';
        if (keyEl) keyEl.value = '';
        if (bodyEl) bodyEl.value = '';
        if (newBox) newBox.setAttribute('hidden', 'hidden');
      }
      if (lib) {
        var cards0 = lib.querySelectorAll('[data-tpl-card]');
        for (var i = 0; i < cards0.length; i++) bindCard(cards0[i]);
      }
      if (addBtn && newBox) {
        addBtn.addEventListener('click', function () {
          newBox.removeAttribute('hidden');
          var labelEl = document.getElementById('tplNewLabel');
          if (labelEl) try { labelEl.focus(); } catch (e) {}
        });
      }
      if (newConfirm) newConfirm.addEventListener('click', addTemplateFromNew);
      if (newCancel) {
        newCancel.addEventListener('click', function () {
          if (newBox) newBox.setAttribute('hidden', 'hidden');
        });
      }
      if (search && lib) {
        search.addEventListener('input', function () {
          var q = String(search.value || '').toLowerCase().trim();
          var cards = lib.querySelectorAll('[data-tpl-card]');
          for (var i = 0; i < cards.length; i++) {
            var hay = cards[i].getAttribute('data-search') || '';
            cards[i].classList.toggle('is-hidden', !!(q && hay.indexOf(q) === -1));
          }
          refreshCount();
        });
      }
      refreshCount();
    })();
    </script>

<?php elseif ($mode === 'log'): ?>
    <form method="get" class="msg-toolbar">
        <input type="hidden" name="mode" value="log">
        <input name="q" value="<?php echo e($logQ); ?>" placeholder="<?php echo e($isEnMsg ? 'Search message text, name, phone…' : 'بحث بنص الرسالة أو الاسم أو الرقم…'); ?>">
        <select name="type">
            <option value=""><?php echo e($isEnMsg ? 'All types' : 'كل الأنواع'); ?></option>
            <option value="auto"<?php echo $logType === 'auto' ? ' selected' : ''; ?>><?php echo e($isEnMsg ? 'Automatic only' : 'التلقائي فقط'); ?></option>
            <option value="activation"<?php echo $logType === 'activation' ? ' selected' : ''; ?>><?php echo e($isEnMsg ? 'Activation' : 'تفعيل'); ?></option>
            <option value="reminder_debt"<?php echo $logType === 'reminder_debt' ? ' selected' : ''; ?>><?php echo e($isEnMsg ? 'Debt reminder' : 'تذكير دين'); ?></option>
            <option value="unpaid_overdue"<?php echo $logType === 'unpaid_overdue' ? ' selected' : ''; ?>><?php echo e($isEnMsg ? 'Unpaid / delay' : 'تأخير الدين'); ?></option>
            <option value="expiry_auto"<?php echo $logType === 'expiry_auto' ? ' selected' : ''; ?>><?php echo e($isEnMsg ? 'Expiry auto' : 'قرب الانتهاء'); ?></option>
        </select>
        <button class="btn secondary sm" type="submit"><?php echo e($isEnMsg ? 'Search' : 'بحث'); ?></button>
        <?php if ($logQ !== '' || $logType !== ''): ?>
            <a class="btn ghost sm" href="messages.php?mode=log"><?php echo e(t('show_all')); ?></a>
        <?php endif; ?>
        <span class="meta msg-count"><?php echo (int) $logTotal; ?> <?php echo e($isEnMsg ? 'messages' : 'رسالة'); ?></span>
    </form>
    <div class="table-wrap msg-table-wrap">
        <table class="table-compact log-table" id="msgLogTable">
            <thead>
            <tr>
                <th><?php echo e($isEnMsg ? 'When' : 'الوقت'); ?></th>
                <th><?php echo e(t('name')); ?></th>
                <th><?php echo e(t('phone')); ?></th>
                <th><?php echo e($isEnMsg ? 'Type' : 'النوع'); ?></th>
                <th><?php echo e($isEnMsg ? 'Status' : 'الحالة'); ?></th>
                <th><?php echo e($isEnMsg ? 'Message' : 'نص الرسالة'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$logRows): ?>
                <tr><td colspan="7" class="msg-empty"><?php echo e($isEnMsg ? 'No messages yet' : 'ماكو رسائل بالسجل بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($logRows as $row): ?>
                <?php
                $ok = !empty($row['success']);
                $resolved = !$ok && !empty($logResolvedMap[(int) $row['id']]);
                $bodyFull = (string) $row['body'];
                $bodyShort = $bodyFull;
                if (function_exists('mb_substr')) {
                    if (mb_strlen($bodyShort, 'UTF-8') > 90) {
                        $bodyShort = mb_substr($bodyShort, 0, 90, 'UTF-8') . '…';
                    }
                } elseif (strlen($bodyShort) > 120) {
                    $bodyShort = substr($bodyShort, 0, 120) . '…';
                }
                $bodyShort = str_replace(array("\r\n", "\n", "\r"), ' ', $bodyShort);
                $rowCls = $ok ? '' : ($resolved ? 'row-msg-resolved' : 'row-msg-fail');
                $resolvedTitle = $isEnMsg ? 'Resolved by a later successful send' : 'انحلت لاحقاً بإرسال ناجح';
                ?>
                <tr class="<?php echo e($rowCls); ?>"
                    data-log-id="<?php echo (int) $row['id']; ?>"
                    data-log-fail="<?php echo (!$ok && !$resolved) ? '1' : '0'; ?>">
                    <td class="nowrap"><?php echo e($row['created_at']); ?></td>
                    <td>
                        <?php if (!empty($row['subscriber_id'])): ?>
                            <a href="subscriber.php?id=<?php echo (int) $row['subscriber_id']; ?>">
                                <?php echo e($row['subscriber_name'] ? $row['subscriber_name'] : ('#' . $row['subscriber_id'])); ?>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="nowrap"><?php echo e(format_phone_display($row['phone'])); ?></td>
                    <td><small><?php echo e(message_type_title($row['message_type'])); ?></small></td>
                    <td class="msg-status-log">
                        <?php if ($ok): ?>
                            <span class="dot-msg ok" title="<?php echo e($isEnMsg ? 'Sent' : 'تم'); ?>"></span>
                            <?php echo e($isEnMsg ? 'OK' : 'تم'); ?>
                        <?php elseif ($resolved): ?>
                            <span class="dot-msg resolved" title="<?php echo e($resolvedTitle); ?>"></span>
                            <span class="msg-fail-muted"><?php echo e($isEnMsg ? 'Fail' : 'فشل'); ?></span>
                            <span class="msg-resolved-arrow" title="<?php echo e($resolvedTitle); ?>" aria-label="<?php echo e($resolvedTitle); ?>">→</span>
                            <span class="msg-resolved-ok"><?php echo e($isEnMsg ? 'Fixed' : 'انحلت'); ?></span>
                        <?php else: ?>
                            <span class="dot-msg fail"></span>
                            <?php echo e($isEnMsg ? 'Fail' : 'فشل'); ?>
                        <?php endif; ?>
                    </td>
                    <td class="log-details msg-log-body" title="<?php echo e($bodyFull); ?>">
                        <?php echo e($bodyShort); ?>
                        <?php if ($bodyShort !== str_replace(array("\r\n", "\n", "\r"), ' ', $bodyFull)): ?>
                            <details class="msg-log-more">
                                <summary><?php echo e($isEnMsg ? 'Full' : 'كامل'); ?></summary>
                                <pre class="msg-log-pre"><?php echo e($bodyFull); ?></pre>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td class="acts-cell">
                        <?php if (!$ok && !$resolved): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="retry_log">
                                <input type="hidden" name="log_id" value="<?php echo (int) $row['id']; ?>">
                                <input type="hidden" name="q" value="<?php echo e($logQ); ?>">
                                <input type="hidden" name="page" value="<?php echo (int) $logPage; ?>">
                                <button class="link-act" type="submit" title="<?php echo e($isEnMsg ? 'Retry' : 'إعادة إرسال'); ?>">↻</button>
                            </form>
                        <?php elseif ($resolved): ?>
                            <span class="msg-resolved-arrow acts-resolved" title="<?php echo e($resolvedTitle); ?>">→</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($logPages > 1): ?>
        <div class="actions actions-tight" style="margin-top:10px">
            <?php if ($logPage > 1): ?>
                <a class="btn ghost sm" href="messages.php?mode=log&page=<?php echo (int) ($logPage - 1); ?><?php echo $logQ !== '' ? '&q=' . rawurlencode($logQ) : ''; ?><?php echo $logType !== '' ? '&type=' . rawurlencode($logType) : ''; ?>">‹</a>
            <?php endif; ?>
            <span class="meta" style="margin:0"><?php echo (int) $logPage; ?> / <?php echo (int) $logPages; ?></span>
            <?php if ($logPage < $logPages): ?>
                <a class="btn ghost sm" href="messages.php?mode=log&page=<?php echo (int) ($logPage + 1); ?><?php echo $logQ !== '' ? '&q=' . rawurlencode($logQ) : ''; ?><?php echo $logType !== '' ? '&type=' . rawurlencode($logType) : ''; ?>">›</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <form method="post" id="msgLogDeleteForm" style="display:none">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="delete_log">
        <input type="hidden" name="log_id" id="msgLogDeleteId" value="">
        <input type="hidden" name="q" value="<?php echo e($logQ); ?>">
        <input type="hidden" name="type" value="<?php echo e($logType); ?>">
        <input type="hidden" name="page" value="<?php echo (int) $logPage; ?>">
    </form>
    <div id="msgLogCtx" class="msg-log-ctx" hidden>
        <button type="button" id="msgLogCtxDel"><?php echo e($isEnMsg ? 'Delete failed message' : 'حذف رسالة الفشل'); ?></button>
    </div>
    <style>
      .msg-sent-badge {
        display: inline-flex; align-items: center; gap: 5px;
        margin-inline-start: 8px; font-size: 12px; font-weight: 600; color: #15803d;
        background: #dcfce7; border-radius: 999px; padding: 2px 8px;
      }
      .msg-sent-badge .dot-msg {
        width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: #22c55e;
      }
      tr.msg-row-notified td { background: rgba(34, 197, 94, 0.04); }
      .msg-log-ctx {
        position: fixed; z-index: 9999; min-width: 160px;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.15); padding: 6px;
      }
      .msg-log-ctx button {
        display: block; width: 100%; text-align: start; border: 0; background: transparent;
        padding: 8px 10px; border-radius: 8px; cursor: pointer; color: #b91c1c; font-weight: 600;
      }
      .msg-log-ctx button:hover { background: #fef2f2; }
      #msgLogTable tr.row-msg-fail { cursor: context-menu; }
    </style>
    <script>
    (function () {
      var menu = document.getElementById('msgLogCtx');
      var delBtn = document.getElementById('msgLogCtxDel');
      var form = document.getElementById('msgLogDeleteForm');
      var idInput = document.getElementById('msgLogDeleteId');
      var activeId = 0;
      function hide() {
        if (menu) menu.hidden = true;
        activeId = 0;
      }
      document.addEventListener('click', hide);
      document.addEventListener('scroll', hide, true);
      var table = document.getElementById('msgLogTable');
      if (table) {
        table.addEventListener('contextmenu', function (e) {
          var tr = e.target && e.target.closest ? e.target.closest('tr[data-log-fail="1"]') : null;
          if (!tr) return;
          e.preventDefault();
          activeId = parseInt(tr.getAttribute('data-log-id') || '0', 10) || 0;
          if (!menu || activeId <= 0) return;
          menu.hidden = false;
          var x = e.clientX;
          var y = e.clientY;
          menu.style.left = Math.min(window.innerWidth - 180, Math.max(8, x)) + 'px';
          menu.style.top = Math.min(window.innerHeight - 60, Math.max(8, y)) + 'px';
        });
      }
      if (delBtn) {
        delBtn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          if (activeId <= 0 || !form || !idInput) return;
          if (!confirm(<?php echo json_encode($isEnMsg ? 'Delete this failed message from the log?' : 'تحذف رسالة الفشل من السجل؟'); ?>)) {
            hide();
            return;
          }
          idInput.value = String(activeId);
          form.submit();
        });
      }
    })();
    </script>

<?php else: ?>

    <div class="msg-bulk-intro">
        <nav class="msg-filter-chips" aria-label="<?php echo e($isEnMsg ? 'Send filters' : 'فلاتر الإرسال'); ?>">
            <?php foreach ($sendFilters as $fk => $fmeta): ?>
                <?php
                $fhref = 'messages.php?mode=send&filter=' . rawurlencode($fk);
                if ($fk === 'days') {
                    $fhref .= '&days=' . (int) $daysMax;
                }
                ?>
                <a class="msg-chip<?php echo $filter === $fk ? ' is-on' : ''; ?>" href="<?php echo e($fhref); ?>">
                    <strong><?php echo e($fmeta['label']); ?></strong>
                    <span><?php echo e($fmeta['hint']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    <?php if ($filter === 'days'): ?>
        <form method="get" class="msg-toolbar">
            <input type="hidden" name="mode" value="send">
            <input type="hidden" name="filter" value="days">
            <label class="msg-inline-lab">
                <span><?php echo e(t('filter_days')); ?></span>
                <input type="number" min="0" name="days" value="<?php echo (int) $daysMax; ?>">
            </label>
            <button class="btn sm" type="submit"><?php echo e(t('show')); ?></button>
            <span class="meta msg-count"><?php echo count($filtered); ?> <?php echo e($isEnMsg ? 'people' : 'مشترك'); ?></span>
        </form>
    <?php elseif ($filter === 'overdue'): ?>
        <p class="meta">
            <?php echo e($isEnMsg
                ? ('Active + unpaid for ' . $afterDays . '+ days. Edit days/text in Templates.')
                : ('مفعّل وعليه دين ومضى ' . $afterDays . '+ يوم. الأيام والنص من القوالب.')); ?>
            · <strong><?php echo count($filtered); ?></strong>
        </p>
    <?php else: ?>
        <p class="meta">
            <?php echo e($isEnMsg
                ? 'Everyone with unpaid debt. Uncheck to exclude.'
                : 'كل من عليه دين. شيل الجك بوكس للاستثناء.'); ?>
            · <strong><?php echo count($filtered); ?></strong>
        </p>
    <?php endif; ?>
    </div>

    <form method="post" id="bulkMsgForm" class="msg-bulk-form">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="send" id="bulkMsgAction">
        <input type="hidden" name="mode" value="send">
        <input type="hidden" name="filter" value="<?php echo e($filter); ?>">
        <?php if ($filter === 'days'): ?>
            <input type="hidden" name="days" value="<?php echo (int) $daysMax; ?>">
        <?php endif; ?>
        <div class="msg-compose panel">
            <label>
                <?php echo e($isEnMsg ? 'Message' : 'نص الرسالة'); ?>
                <?php if ($filter === 'debt'): ?>
                    <span class="tpl-case-vars">({name} {debt} {amount} {month})</span>
                <?php elseif ($filter === 'overdue'): ?>
                    <span class="tpl-case-vars">({name} {days_passed} {debt} {package})</span>
                <?php else: ?>
                    <span class="tpl-case-vars">({name} {days} {package} {from} {to} {debt})</span>
                <?php endif; ?>
            </label>
            <textarea name="msg" rows="4" class="msg-textarea-compact" id="bulkMsgText"><?php echo e($previewMsg); ?></textarea>
            <div class="msg-compose-bar">
                <button class="btn ghost sm" type="button" id="selAllMsg"><?php echo e(t('select_all')); ?></button>
                <button class="btn ghost sm" type="button" id="selNoneMsg"><?php echo e(t('select_none')); ?></button>
                <button class="btn secondary sm" type="submit" id="sendBulkBtn"
                    onclick="document.getElementById('bulkMsgAction').value='send'; return confirm(<?php echo json_encode($isEnMsg
                        ? 'Send WhatsApp to selected people only?'
                        : 'إرسال واتساب للمحددين فقط؟'); ?>);">
                    <?php echo e(t('send_selected')); ?> (<span id="selCount"><?php echo count($filtered); ?></span>)
                </button>
                <button class="btn danger sm" type="submit" id="disableBulkBtn"
                    onclick="document.getElementById('bulkMsgAction').value='disable_users'; return confirm(<?php echo json_encode($isEnMsg
                        ? 'Disable selected users on SAS?'
                        : 'إيقاف اليوزرات المحددين على الساس؟'); ?>);">
                    <?php echo e($isEnMsg ? 'Disable selected' : 'إيقاف المحددين'); ?>
                </button>
            </div>
        </div>

        <div class="table-wrap msg-table-wrap">
            <table class="table-compact" id="msgBulkTable">
                <thead>
                <tr>
                    <th class="chk-col"><input type="checkbox" id="checkAllMsg" class="chk-sm" checked></th>
                    <th><?php echo e(t('name')); ?></th>
                    <th><?php echo e(t('phone')); ?></th>
                    <?php if ($filter === 'debt'): ?>
                        <th><?php echo e(t('debts_total')); ?></th>
                        <th><?php echo e($isEnMsg ? 'Items' : 'عدد الديون'); ?></th>
                    <?php elseif ($filter === 'overdue'): ?>
                        <th><?php echo e(t('package')); ?></th>
                        <th><?php echo e($isEnMsg ? 'Activated' : 'تاريخ التفعيل'); ?></th>
                        <th><?php echo e($isEnMsg ? 'Days since' : 'مضى (يوم)'); ?></th>
                        <th><?php echo e(t('debts_total')); ?></th>
                    <?php else: ?>
                        <th><?php echo e(t('package')); ?></th>
                        <th><?php echo e(t('to_date')); ?></th>
                        <th><?php echo e(t('days_left')); ?></th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (!$filtered): ?>
                    <tr><td colspan="6" class="msg-empty"><?php echo e($daysEmptyHint !== '' ? $daysEmptyHint : ($isEnMsg ? 'No results' : 'لا توجد نتائج')); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($filtered as $row): ?>
                    <?php
                    $chkId = ($filter === 'days' && isset($row['subscriber_id']))
                        ? (int) $row['subscriber_id']
                        : (int) $row['id'];
                    $alreadyNotified = ($filter === 'days' && !empty($row['_notified']));
                    ?>
                    <tr class="<?php echo $alreadyNotified ? 'msg-row-notified' : ''; ?>">
                        <td class="chk-col">
                            <input type="checkbox" class="msg-check chk-sm" name="ids[]"
                                value="<?php echo $chkId; ?>"
                                <?php echo $alreadyNotified ? '' : 'checked'; ?>>
                        </td>
                        <td>
                            <?php echo e($row['name']); ?>
                            <?php if ($alreadyNotified): ?>
                                <span class="msg-sent-badge" title="<?php echo e($isEnMsg ? 'Expiry notice already sent' : 'تم إرسال إشعار قرب الانتهاء'); ?>">
                                    <span class="dot-msg ok"></span>
                                    <?php echo e($isEnMsg ? 'Notified' : 'تم الإشعار'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e(format_phone_display($row['phone'])); ?></td>
                        <?php if ($filter === 'debt'): ?>
                            <td><strong><?php echo e(money_format_iqd($row['debt_total'], $config['currency'])); ?></strong></td>
                            <td><?php echo (int) $row['debt_count']; ?></td>
                        <?php elseif ($filter === 'overdue'): ?>
                            <td><?php echo e(!empty($row['active_service']) ? $row['active_service'] : '-'); ?></td>
                            <td><?php echo e($row['active_start']); ?></td>
                            <td><strong><?php echo (int) $row['_days_passed']; ?></strong></td>
                            <td><strong><?php echo e(money_format_iqd($row['debt_total'], $config['currency'])); ?></strong></td>
                        <?php else: ?>
                            <td><?php echo e($row['service_name']); ?></td>
                            <td><?php echo e($row['end_date']); ?></td>
                            <td>
                                <div class="days-bar">
                                    <div class="days-fill" style="width:<?php echo (int) $row['_pct']; ?>%"></div>
                                    <span><?php echo (int) $row['_days']; ?> <?php echo e(t('days_unit')); ?></span>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
<script>
(function () {
  var all = document.getElementById('checkAllMsg');
  var btnAll = document.getElementById('selAllMsg');
  var btnNone = document.getElementById('selNoneMsg');
  var countEl = document.getElementById('selCount');
  function boxes() { return document.querySelectorAll('.msg-check'); }
  function refreshCount() {
    var n = 0;
    var list = boxes();
    for (var i = 0; i < list.length; i++) if (list[i].checked) n++;
    if (countEl) countEl.textContent = String(n);
    if (all) all.checked = (list.length > 0 && n === list.length);
  }
  function setAll(v) {
    var list = boxes();
    for (var i = 0; i < list.length; i++) list[i].checked = v;
    refreshCount();
  }
  if (all) all.addEventListener('change', function () { setAll(all.checked); });
  if (btnAll) btnAll.addEventListener('click', function () { setAll(true); });
  if (btnNone) btnNone.addEventListener('click', function () { setAll(false); });
  var list = boxes();
  for (var i = 0; i < list.length; i++) {
    list[i].addEventListener('change', refreshCount);
  }
  refreshCount();
})();
</script>
<?php endif; ?>
</div>
<?php render_footer(); ?>
