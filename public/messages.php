<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$mode = isset($_GET['mode']) ? (string) $_GET['mode'] : 'overdue';
if (!in_array($mode, array('debt', 'days', 'overdue', 'log'), true)) {
    $mode = 'overdue';
}

$logQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
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
$logTotal = 0;
$logPages = 1;

if ($mode === 'log') {
    $where = '1=1';
    $params = array();
    if ($logQ !== '') {
        $where = '(m.body LIKE :q OR m.phone LIKE :q OR m.message_type LIKE :q OR s.name LIKE :q)';
        $params[':q'] = '%' . $logQ . '%';
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
} else {
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
        <a class="btn sm <?php echo $mode === 'overdue' ? '' : 'ghost'; ?>" href="messages.php?mode=overdue"><?php echo e($lang === 'en' ? 'Late payers' : 'المتأخرين بالتسديد'); ?></a>
        <a class="btn sm <?php echo $mode === 'debt' ? '' : 'ghost'; ?>" href="messages.php?mode=debt"><?php echo e(t('msg_mode_debt')); ?></a>
        <a class="btn sm <?php echo $mode === 'days' ? '' : 'ghost'; ?>" href="messages.php?mode=days&days=<?php echo (int) $daysMax; ?>"><?php echo e(t('msg_mode_days')); ?></a>
        <a class="btn sm <?php echo $mode === 'log' ? '' : 'ghost'; ?>" href="messages.php?mode=log"><?php echo e($lang === 'en' ? 'Sent log' : 'سجل الرسائل'); ?></a>
    </div>

<?php if ($mode === 'log'): ?>
    <form method="get" class="actions actions-tight" style="margin-bottom:10px">
        <input type="hidden" name="mode" value="log">
        <input name="q" value="<?php echo e($logQ); ?>" placeholder="<?php echo e($lang === 'en' ? 'Search message text, name, phone…' : 'بحث بنص الرسالة أو الاسم أو الرقم…'); ?>" style="max-width:340px;flex:1">
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
                ?>
                <tr class="<?php echo $ok ? '' : 'row-msg-fail'; ?>">
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
                    <td>
                        <?php if ($ok): ?>
                            <span class="dot-msg ok" title="<?php echo e($lang === 'en' ? 'Sent' : 'تم'); ?>"></span>
                            <?php echo e($lang === 'en' ? 'OK' : 'تم'); ?>
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
                        <?php if (!$ok): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="retry_log">
                                <input type="hidden" name="log_id" value="<?php echo (int) $row['id']; ?>">
                                <input type="hidden" name="q" value="<?php echo e($logQ); ?>">
                                <input type="hidden" name="page" value="<?php echo (int) $logPage; ?>">
                                <button class="link-act" type="submit" title="<?php echo e($lang === 'en' ? 'Retry' : 'إعادة إرسال'); ?>">↻</button>
                            </form>
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
        <form method="get" class="form-grid form-grid-tight" style="margin-bottom:8px">
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
                ? ('Active + unpaid for ' . $afterDays . '+ days since activation. Uncheck to exclude. Change days/text in Settings → Templates.')
                : ('مفعّل وعليه دين ومضى ' . $afterDays . '+ يوم من التفعيل. شيل الجك بوكس للاستثناء. الأيام والنص من الإعدادات → القوالب.')); ?>
        </p>
    <?php else: ?>
        <p class="meta" style="margin-top:0">
            <?php echo e($lang === 'en'
                ? 'All subscribers with unpaid debt. Uncheck anyone you do not want to message.'
                : 'كل من عليه دين. شيل الجك بوكس عن أي واحد ما تريد ترسل له.'); ?>
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
