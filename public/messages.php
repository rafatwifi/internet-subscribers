<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$mode = isset($_GET['mode']) ? (string) $_GET['mode'] : 'overdue';
if (!in_array($mode, array('debt', 'days', 'overdue', 'log', 'templates'), true)) {
    $mode = 'overdue';
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
    if ($mode === 'debt') {
        $previewMsg = $defaultDebtTpl;
    } elseif ($mode === 'days') {
        $previewMsg = $defaultDaysTpl;
    } else {
        $previewMsg = $defaultOverdueTpl;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $lang === 'en' ? 'Invalid request' : 'طلب غير صالح');
        redirect('messages.php');
    }

    $action = post('action');

    if ($action === 'save_templates') {
        $afterDaysSave = (int) post('unpaid_remind_after_days', '7');
        if ($afterDaysSave < 1) {
            $afterDaysSave = 1;
        }
        if ($afterDaysSave > 365) {
            $afterDaysSave = 365;
        }

        $caseKeys = array(
            'activation_cash', 'activation_credit', 'activation_debts', 'debt_created', 'payment_ok',
            'debt_remind', 'reminder_auto', 'days_left', 'unpaid_overdue', 'expiry_soon', 'schedule_cut'
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
            'unpaid_remind_after_days' => $afterDaysSave,
        );

        // Mirror legacy tpl_* for schedule/settings pages that still edit those fields.
        $legacyMap = function_exists('wa_legacy_tpl_field_map') ? wa_legacy_tpl_field_map() : array();
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

    $modePost = post('mode', 'overdue');
    if (!in_array($modePost, array('debt', 'days', 'overdue'), true)) {
        $modePost = 'overdue';
    }
    $mode = $modePost;
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

    if ($action === 'send') {
        $ok = 0;
        $fail = 0;
        $skipped = 0;

        if ($mode === 'debt') {
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
        } elseif ($mode === 'overdue') {
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
            foreach ($ids as $idRaw) {
                $id = (int) $idRaw;
                if ($id <= 0) {
                    continue;
                }
                $st = $pdo->prepare(
                    'SELECT sub.*, s.name, s.phone,
                        (SELECT COALESCE(SUM(amount),0) FROM invoices i
                         WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt_total
                     FROM subscriptions sub
                     JOIN subscribers s ON s.id = sub.subscriber_id
                     WHERE sub.id = :id AND sub.status = "active"'
                );
                $st->execute(array(':id' => $id));
                $row = $st->fetch();
                if (!$row) {
                    $skipped++;
                    continue;
                }
                $info = subscription_days_info($row['start_date'], $row['end_date']);
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
                        'month' => month_short_label(date('Y-m', strtotime($row['start_date']))),
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
                $result = whatsapp_send($config, $row['phone'], $body, 'bulk_filter');
                log_message($pdo, (int) $row['subscriber_id'], $result);
                if (!empty($result['success'])) {
                    $ok++;
                } else {
                    $fail++;
                }
                usleep(350000);
            }
        }

        $msg = ($lang === 'en' ? 'Sent OK: ' : 'تم الإرسال: ') . $ok
            . ($lang === 'en' ? ' / Failed: ' : ' / فشل: ') . $fail;
        if ($skipped > 0) {
            $msg .= ($lang === 'en' ? ' / Skipped: ' : ' / تخطي: ') . $skipped;
        }
        flash($fail > 0 && $ok === 0 ? 'error' : 'success', $msg);
        $redir = 'messages.php?mode=' . $mode;
        if ($mode === 'days') {
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

if ($mode === 'log') {
    $where = '1=1';
    $params = array();
    if ($logQ !== '') {
        $where = '(m.body LIKE :q OR m.phone LIKE :q OR m.message_type LIKE :q OR s.name LIKE :q)';
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
} elseif ($mode === 'debt') {
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
         WHERE d.debt_total > 0
         ORDER BY d.debt_total DESC, s.name ASC"
    )->fetchAll();
} elseif ($mode === 'overdue') {
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
         WHERE d.debt_total > 0
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
} elseif ($mode === 'days') {
    $candidates = $pdo->query(
        "SELECT sub.*, s.name, s.phone
         FROM subscriptions sub
         JOIN subscribers s ON s.id = sub.subscriber_id
         WHERE sub.status = 'active'
         ORDER BY sub.end_date ASC"
    )->fetchAll();
    foreach ($candidates as $row) {
        $info = subscription_days_info($row['start_date'], $row['end_date']);
        if ((int) $info['left'] <= $daysMax) {
            $row['_days'] = (int) $info['left'];
            $row['_pct'] = (int) $info['pct'];
            $filtered[] = $row;
        }
    }
}

render_header(t('messages'), 'messages');
?>
<div class="panel panel-compact">
    <h2><?php echo e(t('messages')); ?></h2>
    <div class="actions actions-tight" style="margin-top:0;margin-bottom:8px">
        <a class="btn sm <?php echo $mode === 'templates' ? '' : 'ghost'; ?>" href="messages.php?mode=templates"><?php echo e(t('templates')); ?></a>
        <a class="btn sm <?php echo $mode === 'overdue' ? '' : 'ghost'; ?>" href="messages.php?mode=overdue"><?php echo e($lang === 'en' ? 'Late payers' : 'المتأخرين بالتسديد'); ?></a>
        <a class="btn sm <?php echo $mode === 'debt' ? '' : 'ghost'; ?>" href="messages.php?mode=debt"><?php echo e(t('msg_mode_debt')); ?></a>
        <a class="btn sm <?php echo $mode === 'days' ? '' : 'ghost'; ?>" href="messages.php?mode=days&days=<?php echo (int) $daysMax; ?>"><?php echo e(t('msg_mode_days')); ?></a>
        <a class="btn sm <?php echo $mode === 'log' ? '' : 'ghost'; ?>" href="messages.php?mode=log"><?php echo e($lang === 'en' ? 'Sent log' : 'سجل الرسائل'); ?></a>
    </div>

<?php if ($mode === 'templates'): ?>
    <?php
    if (!isset($sTpl) || !is_array($sTpl)) {
        $sTpl = settings_load();
    }
    $isEnMsg = ($lang === 'en');
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

        <div class="panel" style="margin:0 0 14px;padding:12px 14px">
            <h3 style="margin:0 0 8px;font-size:15px"><?php echo e($isEnMsg ? 'Assign templates to actions' : 'تخصيص القوالب للحركات'); ?></h3>
            <p class="meta" style="margin:0 0 10px"><?php echo e($isEnMsg
                ? 'Cash and credit activations are separate. Prior-debts appendix is used only when “include old debts” is on.'
                : 'التفعيل النقدي والآجل منفصلان. ملحق الديون يُستخدم فقط عند تفعيل «تضمين الديون القديمة».'); ?></p>
            <div class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px" id="tplCaseMap">
                <?php foreach ($caseLabels as $caseKey => $caseLab): ?>
                    <?php
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
                    <label class="tpl-case-lab<?php echo $hasIssue ? ' is-warn' : ''; ?>" id="case-<?php echo e($caseKey); ?>" style="display:block;font-size:12px;font-weight:700;color:#475569">
                        <?php echo e($caseLab); ?>
                        <select name="wa_case_<?php echo e($caseKey); ?>" class="js-case-select" style="width:100%;margin-top:4px;height:36px">
                            <option value="__none__"<?php echo $sel === '__none__' ? ' selected' : ''; ?>><?php echo e($isEnMsg ? '— Choose template —' : '— اختر قالباً —'); ?></option>
                            <?php foreach ($catalog as $tk => $trow): ?>
                                <option value="<?php echo e($tk); ?>"<?php echo $sel === $tk ? ' selected' : ''; ?>><?php echo e(isset($trow['label']) ? $trow['label'] : $tk); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($caseKey === 'unpaid_overdue'): ?>
                            <span class="tpl-inline" style="margin-top:6px;display:inline-flex">
                                <span><?php echo e($isEnMsg ? 'Warn after (days)' : 'تنبيه بعد (يوم)'); ?></span>
                                <input type="number" name="unpaid_remind_after_days" min="1" max="365"
                                    value="<?php echo (int) (isset($sTpl['unpaid_remind_after_days']) ? $sTpl['unpaid_remind_after_days'] : 7); ?>">
                            </span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="actions actions-tight" style="margin-bottom:8px">
            <strong style="font-size:14px;margin-inline-end:auto"><?php echo e($isEnMsg ? 'Templates' : 'القوالب'); ?></strong>
            <button type="button" class="btn secondary sm" id="tplAddBtn"><?php echo e($isEnMsg ? '+ Add template' : '+ إضافة قالب'); ?></button>
        </div>

        <div class="tpl-new panel" id="tplNewBox" style="margin:0 0 10px;padding:12px 14px" hidden>
            <div class="form-grid" style="grid-template-columns:1.2fr .8fr;gap:8px">
                <div>
                    <label><?php echo e($isEnMsg ? 'Name' : 'الاسم'); ?></label>
                    <input type="text" name="tpl_new_label" id="tplNewLabel" placeholder="<?php echo e($isEnMsg ? 'e.g. Ramadan offer' : 'مثال: عرض رمضان'); ?>">
                </div>
                <div>
                    <label><?php echo e($isEnMsg ? 'Key (optional)' : 'مفتاح (اختياري)'); ?></label>
                    <input type="text" name="tpl_new_key" id="tplNewKey" dir="ltr" placeholder="my_template">
                </div>
            </div>
            <label style="margin-top:8px;display:block"><?php echo e($isEnMsg ? 'Message text' : 'نص الرسالة'); ?></label>
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
                ?>
                <article class="tpl-card" data-tpl-card>
                    <header class="tpl-card-head">
                        <input type="hidden" name="tpl_key[]" value="<?php echo e($tk); ?>">
                        <input type="text" name="tpl_label[]" class="tpl-label-input" value="<?php echo e(isset($trow['label']) ? $trow['label'] : $tk); ?>" placeholder="<?php echo e($isEnMsg ? 'Template name' : 'اسم القالب'); ?>">
                        <span class="tpl-vars" title="<?php echo e($tk); ?>"><?php echo e($tk); ?></span>
                        <button type="button" class="btn ghost sm js-tpl-del"><?php echo e($isEnMsg ? 'Delete' : 'حذف'); ?></button>
                    </header>
                    <?php if ($inUseLabels): ?>
                        <p class="meta" style="margin:0;font-size:11px"><?php echo e($isEnMsg ? 'Used by: ' : 'مستخدم في: '); ?><?php echo e(implode(' · ', $inUseLabels)); ?></p>
                    <?php endif; ?>
                    <textarea name="tpl_body[]" rows="4" placeholder="<?php echo e($commonVars); ?>"><?php echo e(isset($trow['body']) ? $trow['body'] : ''); ?></textarea>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="tpl-save">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>

    <template id="tplCardTpl">
        <article class="tpl-card" data-tpl-card>
            <header class="tpl-card-head">
                <input type="hidden" name="tpl_key[]" value="">
                <input type="text" name="tpl_label[]" class="tpl-label-input" value="" placeholder="<?php echo e($isEnMsg ? 'Template name' : 'اسم القالب'); ?>">
                <span class="tpl-vars"></span>
                <button type="button" class="btn ghost sm js-tpl-del"><?php echo e($isEnMsg ? 'Delete' : 'حذف'); ?></button>
            </header>
            <textarea name="tpl_body[]" rows="4" placeholder="<?php echo e($commonVars); ?>"></textarea>
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
          });
        }
        var lab = card.querySelector('input[name="tpl_label[]"]');
        if (lab) lab.addEventListener('input', rebuildCaseOptions);
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
        lib.insertBefore(card, lib.firstChild);
        bindCard(card);
        rebuildCaseOptions();
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
    })();
    </script>

<?php elseif ($mode === 'log'): ?>
    <form method="get" class="actions actions-tight" style="margin-bottom:10px">
        <input type="hidden" name="mode" value="log">
        <input name="q" value="<?php echo e($logQ); ?>" placeholder="<?php echo e($lang === 'en' ? 'Search message text, name, phone…' : 'بحث بنص الرسالة أو الاسم أو الرقم…'); ?>" style="max-width:340px;flex:1">
        <select name="type" style="max-width:200px">
            <option value=""><?php echo e($lang === 'en' ? 'All types' : 'كل الأنواع'); ?></option>
            <option value="auto"<?php echo $logType === 'auto' ? ' selected' : ''; ?>><?php echo e($lang === 'en' ? 'Automatic only' : 'التلقائي فقط'); ?></option>
            <option value="activation"<?php echo $logType === 'activation' ? ' selected' : ''; ?>><?php echo e($lang === 'en' ? 'Activation' : 'تفعيل'); ?></option>
            <option value="reminder_debt"<?php echo $logType === 'reminder_debt' ? ' selected' : ''; ?>><?php echo e($lang === 'en' ? 'Debt reminder' : 'تذكير دين'); ?></option>
            <option value="unpaid_overdue"<?php echo $logType === 'unpaid_overdue' ? ' selected' : ''; ?>><?php echo e($lang === 'en' ? 'Unpaid / delay' : 'تأخير الدين'); ?></option>
            <option value="expiry_auto"<?php echo $logType === 'expiry_auto' ? ' selected' : ''; ?>><?php echo e($lang === 'en' ? 'Expiry auto' : 'قرب الانتهاء'); ?></option>
        </select>
        <button class="btn secondary sm" type="submit"><?php echo e($lang === 'en' ? 'Search' : 'بحث'); ?></button>
        <?php if ($logQ !== ''): ?>
            <a class="btn ghost sm" href="messages.php?mode=log"><?php echo e(t('show_all')); ?></a>
        <?php endif; ?>
        <span class="meta" style="margin:0"><?php echo (int) $logTotal; ?> <?php echo e($lang === 'en' ? 'messages' : 'رسالة'); ?></span>
    </form>
    <div class="table-wrap">
        <table class="table-compact log-table" id="msgLogTable">
            <thead>
            <tr>
                <th><?php echo e($lang === 'en' ? 'When' : 'الوقت'); ?></th>
                <th><?php echo e(t('name')); ?></th>
                <th><?php echo e(t('phone')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Type' : 'النوع'); ?></th>
                <th><?php echo e($lang === 'en' ? 'Status' : 'الحالة'); ?></th>
                <th><?php echo e($lang === 'en' ? 'Message' : 'نص الرسالة'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$logRows): ?>
                <tr><td colspan="7"><?php echo e($lang === 'en' ? 'No messages yet' : 'ماكو رسائل بالسجل بعد'); ?></td></tr>
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
                $resolvedTitle = $lang === 'en' ? 'Resolved by a later successful send' : 'انحلت لاحقاً بإرسال ناجح';
                ?>
                <tr class="<?php echo e($rowCls); ?>">
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
                            <span class="dot-msg ok" title="<?php echo e($lang === 'en' ? 'Sent' : 'تم'); ?>"></span>
                            <?php echo e($lang === 'en' ? 'OK' : 'تم'); ?>
                        <?php elseif ($resolved): ?>
                            <span class="dot-msg resolved" title="<?php echo e($resolvedTitle); ?>"></span>
                            <span class="msg-fail-muted"><?php echo e($lang === 'en' ? 'Fail' : 'فشل'); ?></span>
                            <span class="msg-resolved-arrow" title="<?php echo e($resolvedTitle); ?>" aria-label="<?php echo e($resolvedTitle); ?>">→</span>
                            <span class="msg-resolved-ok"><?php echo e($lang === 'en' ? 'Fixed' : 'انحلت'); ?></span>
                        <?php else: ?>
                            <span class="dot-msg fail"></span>
                            <?php echo e($lang === 'en' ? 'Fail' : 'فشل'); ?>
                        <?php endif; ?>
                    </td>
                    <td class="log-details msg-log-body" title="<?php echo e($bodyFull); ?>">
                        <?php echo e($bodyShort); ?>
                        <?php if ($bodyShort !== str_replace(array("\r\n", "\n", "\r"), ' ', $bodyFull)): ?>
                            <details class="msg-log-more">
                                <summary><?php echo e($lang === 'en' ? 'Full' : 'كامل'); ?></summary>
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
                                <button class="link-act" type="submit" title="<?php echo e($lang === 'en' ? 'Retry' : 'إعادة إرسال'); ?>">↻</button>
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
                <a class="btn ghost sm" href="messages.php?mode=log&page=<?php echo (int) ($logPage - 1); ?><?php echo $logQ !== '' ? '&q=' . rawurlencode($logQ) : ''; ?>">‹</a>
            <?php endif; ?>
            <span class="meta" style="margin:0"><?php echo (int) $logPage; ?> / <?php echo (int) $logPages; ?></span>
            <?php if ($logPage < $logPages): ?>
                <a class="btn ghost sm" href="messages.php?mode=log&page=<?php echo (int) ($logPage + 1); ?><?php echo $logQ !== '' ? '&q=' . rawurlencode($logQ) : ''; ?>">›</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php else: ?>

    <?php if ($mode === 'days'): ?>
        <form method="get" class="form-grid form-grid-tight" style="margin-bottom:10px">
            <input type="hidden" name="mode" value="days">
            <div>
                <label><?php echo e(t('filter_days')); ?></label>
                <input type="number" min="0" name="days" value="<?php echo (int) $daysMax; ?>">
            </div>
            <div style="display:flex;align-items:flex-end">
                <button class="btn sm" type="submit"><?php echo e(t('show')); ?></button>
            </div>
        </form>
    <?php elseif ($mode === 'overdue'): ?>
        <p class="meta" style="margin-top:0">
            <?php echo e($lang === 'en'
                ? ('Active + unpaid for ' . $afterDays . '+ days. Edit days/text in Templates.')
                : ('مفعّل وعليه دين ومضى ' . $afterDays . '+ يوم. الأيام والنص من القوالب.')); ?>
        </p>
    <?php else: ?>
        <p class="meta" style="margin-top:0">
            <?php echo e($lang === 'en'
                ? 'Everyone with unpaid debt. Uncheck to exclude.'
                : 'كل من عليه دين. شيل الجك بوكس للاستثناء.'); ?>
        </p>
    <?php endif; ?>

    <form method="post" id="bulkMsgForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="mode" value="<?php echo e($mode); ?>">
        <?php if ($mode === 'days'): ?>
            <input type="hidden" name="days" value="<?php echo (int) $daysMax; ?>">
        <?php endif; ?>
        <div>
            <label>
                <?php echo e($lang === 'en' ? 'Message' : 'نص الرسالة'); ?>
                <?php if ($mode === 'debt'): ?>
                    ({name} {debt} {amount} {month})
                <?php elseif ($mode === 'overdue'): ?>
                    ({name} {days_passed} {debt} {package})
                <?php else: ?>
                    ({name} {days} {package} {from} {to} {debt})
                <?php endif; ?>
            </label>
            <textarea name="msg" rows="3" class="msg-textarea-compact"><?php echo e($previewMsg); ?></textarea>
        </div>
        <div class="actions actions-tight">
            <button class="btn ghost sm" type="button" id="selAllMsg"><?php echo e(t('select_all')); ?></button>
            <button class="btn ghost sm" type="button" id="selNoneMsg"><?php echo e(t('select_none')); ?></button>
            <button class="btn secondary sm" type="submit" id="sendBulkBtn"
                onclick="return confirm(<?php echo json_encode($lang === 'en'
                    ? 'Send WhatsApp to selected people only?'
                    : 'إرسال واتساب للمحددين فقط؟'); ?>);">
                <?php echo e(t('send_selected')); ?> (<span id="selCount"><?php echo count($filtered); ?></span>)
            </button>
        </div>

        <div class="table-wrap" style="margin-top:8px">
            <table class="table-compact" id="msgBulkTable">
                <thead>
                <tr>
                    <th class="chk-col"><input type="checkbox" id="checkAllMsg" class="chk-sm" checked></th>
                    <th><?php echo e(t('name')); ?></th>
                    <th><?php echo e(t('phone')); ?></th>
                    <?php if ($mode === 'debt'): ?>
                        <th><?php echo e(t('debts_total')); ?></th>
                        <th><?php echo e($lang === 'en' ? 'Items' : 'عدد الديون'); ?></th>
                    <?php elseif ($mode === 'overdue'): ?>
                        <th><?php echo e(t('package')); ?></th>
                        <th><?php echo e($lang === 'en' ? 'Activated' : 'تاريخ التفعيل'); ?></th>
                        <th><?php echo e($lang === 'en' ? 'Days since' : 'مضى (يوم)'); ?></th>
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
                    <tr><td colspan="6"><?php echo e($lang === 'en' ? 'No results' : 'لا توجد نتائج'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($filtered as $row): ?>
                    <tr>
                        <td class="chk-col">
                            <input type="checkbox" class="msg-check chk-sm" name="ids[]"
                                value="<?php echo (int) $row['id']; ?>" checked>
                        </td>
                        <td><?php echo e($row['name']); ?></td>
                        <td><?php echo e(format_phone_display($row['phone'])); ?></td>
                        <?php if ($mode === 'debt'): ?>
                            <td><strong><?php echo e(money_format_iqd($row['debt_total'], $config['currency'])); ?></strong></td>
                            <td><?php echo (int) $row['debt_count']; ?></td>
                        <?php elseif ($mode === 'overdue'): ?>
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
