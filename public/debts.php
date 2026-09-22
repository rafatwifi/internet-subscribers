<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

if (isset($_GET['sas_user'])) {
    $sasUser = trim((string) $_GET['sas_user']);
    if ($sasUser !== '' && function_exists('sas_cache_get')) {
        $cacheRow = sas_cache_get($pdo, $sasUser);
        if ($cacheRow && function_exists('sas_cache_ensure_local')) {
            list($localSid, $localErr) = sas_cache_ensure_local($pdo, $config, $cacheRow);
            if ($localSid > 0) {
                redirect('debts.php?status=unpaid&subscriber_id=' . $localSid);
            }
            flash('error', $localErr !== '' ? $localErr : 'تعذر فتح ديون المشترك');
        } else {
            flash('error', 'المشترك مو موجود بكاش SAS — حدّث القائمة');
        }
    }
    redirect('debts.php?status=unpaid');
}

function flash_payment_result($ok, $errMsg, $details, $sendWa)
{
    global $config;
    if (!$ok) {
        flash('error', $errMsg);
        return;
    }
    $currency = isset($config['currency']) ? $config['currency'] : 'د.ع';
    $paid = money_format_iqd($details['paid_amount'], $currency);
    $remain = money_format_iqd($details['remaining_total'], $currency);
    $base = 'تم استلام ' . $paid . ' — المتبقي على المشترك: ' . $remain;
    if ($sendWa && isset($details['whatsapp_ok']) && $details['whatsapp_ok'] === true) {
        flash('success', $base . ' (واتساب تم)');
    } elseif ($sendWa && isset($details['whatsapp_ok']) && $details['whatsapp_ok'] === false) {
        $waFail = !empty($details['whatsapp_msg']) ? $details['whatsapp_msg'] : 'واتساب فشل';
        flash('info', $base . ' (' . $waFail . ')');
    } else {
        flash('success', $base);
    }
}

if (isset($_GET['pay_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $payId = (int) $_GET['pay_id'];
    $stmt = $pdo->prepare('SELECT amount FROM invoices WHERE id = :id AND status = "unpaid"');
    $stmt->execute(array(':id' => $payId));
    $amt = (float) $stmt->fetchColumn();
    list($ok, $msg, $details) = apply_invoice_payment($pdo, $config, $payId, $amt, true);
    flash_payment_result($ok, $msg, $details, true);
    redirect('debts.php?status=unpaid');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        if (post('ajax') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('ok' => false, 'message' => 'طلب غير صالح'));
            exit;
        }
        flash('error', 'طلب غير صالح');
        redirect('debts.php');
    }

    $action = post('action');
    $id = (int) post('id', '0');

    $returnSid = isset($_POST['return_subscriber']) ? (int) $_POST['return_subscriber'] : 0;

    if ($action === 'pay') {
        $sendWa = post('send_whatsapp') === '1';
        $payAmount = (float) post('pay_amount', '0');
        if ($payAmount <= 0) {
            $st = $pdo->prepare('SELECT amount FROM invoices WHERE id = :id AND status = "unpaid"');
            $st->execute(array(':id' => $id));
            $payAmount = (float) $st->fetchColumn();
        }
        list($ok, $msg, $details) = apply_invoice_payment($pdo, $config, $id, $payAmount, $sendWa);
        flash_payment_result($ok, $msg, $details, $sendWa);
        if ($returnSid <= 0 && !empty($details['row']['subscriber_id'])) {
            $returnSid = (int) $details['row']['subscriber_id'];
        }
        if ($returnSid > 0) {
            redirect('debts.php?status=unpaid&subscriber_id=' . $returnSid);
        }
        redirect('debts.php?status=unpaid');
    }

    if ($action === 'pay_all') {
        $sid = (int) post('subscriber_id', '0');
        $sendWa = post('send_whatsapp') === '1';
        $payAmount = (float) post('pay_amount', '0');
        if ($sid <= 0) {
            $stSid = $pdo->prepare('SELECT subscriber_id FROM invoices WHERE id = :id');
            $stSid->execute(array(':id' => $id));
            $sid = (int) $stSid->fetchColumn();
        }
        if ($payAmount <= 0) {
            $payAmount = subscriber_unpaid_total($pdo, $sid);
        }
        list($ok, $msg, $details) = apply_subscriber_payment($pdo, $config, $sid, $payAmount, $sendWa, 0);
        flash_payment_result($ok, $msg, $details, $sendWa);
        $back = $returnSid > 0 ? $returnSid : $sid;
        if ($back > 0) {
            redirect('debts.php?status=unpaid&subscriber_id=' . $back);
        }
        redirect('debts.php?status=unpaid');
    }

    if ($action === 'unpay') {
        if (!user_can_edit_debts()) {
            flash('error', debt_edit_denied_message());
            redirect('debts.php?status=paid');
        }
        list($ok, $msg, $sidOut) = apply_invoice_unpay($pdo, $id);
        flash($ok ? 'success' : 'error', $msg);
        if ($ok && $sidOut) {
            redirect('debts.php?status=unpaid&subscriber_id=' . (int) $sidOut);
        }
        redirect('debts.php?status=paid');
    }

    if ($action === 'delete_invoice') {
        if (!user_can_edit_debts()) {
            flash('error', debt_edit_denied_message());
            redirect('debts.php?status=unpaid');
        }
        $sid = (int) post('subscriber_id', '0');
        if ($sid <= 0) {
            $peek = $pdo->prepare('SELECT subscriber_id FROM invoices WHERE id = :id');
            $peek->execute(array(':id' => $id));
            $sid = (int) $peek->fetchColumn();
        }
        list($ok, $msg) = apply_unpaid_invoice_delete($pdo, $id, $sid);
        flash($ok ? 'success' : 'error', $msg);
        $ret = (int) post('return_subscriber', '0');
        if ($ret <= 0) {
            $ret = $sid;
        }
        redirect('debts.php?status=unpaid' . ($ret > 0 ? ('&subscriber_id=' . $ret) : ''));
    }

    if ($action === 'update_invoice_amount') {
        $wantJson = post('ajax') === '1';
        if (!user_can_edit_debts()) {
            if ($wantJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('ok' => false, 'message' => debt_edit_denied_message()));
                exit;
            }
            flash('error', debt_edit_denied_message());
            redirect('debts.php');
        }
        $invId = (int) post('invoice_id', '0');
        if ($invId <= 0) {
            $invId = $id;
        }
        $amount = (float) post('amount', '0');
        $sid = (int) post('subscriber_id', '0');
        if ($sid <= 0 && $invId > 0) {
            $stSid = $pdo->prepare('SELECT subscriber_id FROM invoices WHERE id = :id');
            $stSid->execute(array(':id' => $invId));
            $sid = (int) $stSid->fetchColumn();
        }
        list($ok, $msg) = apply_unpaid_invoice_update($pdo, $invId, $sid, array('amount' => $amount));
        $total = ($ok && $sid > 0 && function_exists('subscriber_unpaid_total'))
            ? subscriber_unpaid_total($pdo, $sid)
            : $amount;
        $currency = isset($config['currency']) ? $config['currency'] : 'IQD';
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array(
                'ok' => $ok,
                'message' => $msg,
                'debt' => $amount,
                'debt_text' => function_exists('money_format_iqd') ? money_format_iqd($amount, $currency) : (string) (int) $amount,
                'total' => $total,
            ));
            exit;
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect('debts.php?status=unpaid' . ($sid > 0 ? ('&subscriber_id=' . $sid) : ''));
    }

    if ($action === 'add_invoice') {
        if (!user_can_edit_debts()) {
            flash('error', debt_edit_denied_message());
            redirect('debts.php');
        }
        $subscriberId = (int) post('subscriber_id', '0');
        $amount = (float) post('amount', '0');
        $dueDate = (string) post('due_date', date('Y-m-d'));
        $notes = trim((string) post('notes', ''));
        $rawKind = (string) post('debt_kind', 'month');
        if ($rawKind === 'item') {
            $debtKind = 'item';
        } elseif ($rawKind === 'month_rent') {
            $debtKind = 'month_rent';
        } else {
            $debtKind = 'month';
        }
        $monthLabel = trim((string) post('month_label', date('Y-m')));

        if ($debtKind === 'item') {
            if ($monthLabel === '' || preg_match('/^\d{4}-\d{2}$/', $monthLabel)) {
                $monthLabel = 'غرض';
            }
        } elseif ($monthLabel === '') {
            $monthLabel = date('Y-m');
        }

        $subInfo = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $subInfo->execute(array(':id' => $subscriberId));
        $subInfoRow = $subInfo->fetch();

        if ($debtKind === 'month_rent') {
            if (!$subInfoRow || !subscriber_has_rental($subInfoRow)) {
                flash('error', 'هذا المشترك ما عنده إيجار');
                redirect('debts.php');
            }
            $settingsNow = settings_load();
            $rentFee = (float) rental_fee_amount($settingsNow);
            $subPrice = subscriber_monthly_price($pdo, $subscriberId);
            if ($amount <= 0) {
                $amount = $subPrice + $rentFee;
            }
            if ($notes === '') {
                $dev = rental_device_by_id(isset($subInfoRow['rental_device_id']) ? $subInfoRow['rental_device_id'] : '', $settingsNow);
                $notes = 'اشتراك ' . (int) $subPrice . ' + إيجار ' . (int) $rentFee
                    . ($dev && !empty($dev['name']) ? (' (' . $dev['name'] . ')') : '');
            }
        }

        if ($subscriberId <= 0 || $amount <= 0) {
            flash('error', 'بيانات الفاتورة ناقصة');
            redirect('debts.php');
        }

        $subscriptionId = null;
        $cost = 0;
        if ($debtKind === 'month' || $debtKind === 'month_rent') {
            $sub = $pdo->prepare(
                'SELECT id, cost_price FROM subscriptions WHERE subscriber_id = :sid ORDER BY id DESC LIMIT 1'
            );
            $sub->execute(array(':sid' => $subscriberId));
            $subRow = $sub->fetch();
            if ($subRow) {
                $subscriptionId = (int) $subRow['id'];
                $cost = (float) $subRow['cost_price'];
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO invoices (subscription_id, subscriber_id, month_label, amount, cost_price, due_date, status, notes)
             VALUES (:subscription_id, :subscriber_id, :month_label, :amount, :cost_price, :due_date, "unpaid", :notes)'
        );
        $stmt->execute(array(
            ':subscription_id' => $subscriptionId,
            ':subscriber_id' => $subscriberId,
            ':month_label' => $monthLabel,
            ':amount' => $amount,
            ':cost_price' => $cost,
            ':due_date' => $dueDate,
            ':notes' => ($notes !== '' ? $notes : null),
        ));
        $newInvId = (int) $pdo->lastInsertId();
        if (function_exists('log_invoice_accounts')) {
            log_invoice_accounts(
                $pdo,
                $subscriberId,
                $newInvId,
                'create',
                'إضافة دين #' . $newInvId . ' — ' . $monthLabel . ' / ' . $amount,
                'النوع: ' . $debtKind . "\nالشهر: " . $monthLabel . "\nالمبلغ: " . $amount
                . "\nملاحظات: " . ($notes !== '' ? $notes : '-')
            );
        }

        $sendWa = post('send_whatsapp') === '1';
        if ($sendWa) {
            $info = $pdo->prepare('SELECT name, phone FROM subscribers WHERE id = :id');
            $info->execute(array(':id' => $subscriberId));
            $subRow = $info->fetch();
            if ($subRow) {
                $debtTotal = subscriber_unpaid_total($pdo, $subscriberId);
                $row = array(
                    'name' => $subRow['name'],
                    'phone' => $subRow['phone'],
                    'month_label' => $monthLabel,
                    'amount' => $amount,
                    'debt_total' => $debtTotal,
                    'notes' => $notes,
                );
                $msg = debt_created_message($row, $config);
                $result = whatsapp_send($config, $subRow['phone'], $msg, 'debt_created');
                log_message($pdo, $subscriberId, $result);
                if (!empty($result['success'])) {
                    flash('success', 'تمت إضافة الدين وإرسال واتساب');
                } else {
                    flash('info', 'تمت إضافة الدين لكن ' . whatsapp_fail_user_message($result));
                }
                redirect('debts.php?status=unpaid&subscriber_id=' . $subscriberId);
            }
        }

        flash('success', 'تمت إضافة الدين');
        redirect('debts.php?status=unpaid&subscriber_id=' . $subscriberId);
    }

    if ($action === 'remind') {
        $stmt = $pdo->prepare(
            'SELECT i.*, s.name, s.phone
             FROM invoices i
             JOIN subscribers s ON s.id = i.subscriber_id
             WHERE i.id = :id AND i.status = "unpaid"'
        );
        $stmt->execute(array(':id' => $id));
        $row = $stmt->fetch();
        if (!$row) {
            flash('error', 'الفاتورة غير موجودة أو مسددة');
            redirect('debts.php');
        }
        $row['debt_total'] = subscriber_unpaid_total($pdo, (int) $row['subscriber_id']);
        $msg = reminder_message($row, $config);
        $result = whatsapp_send($config, $row['phone'], $msg, 'reminder_manual');
        log_message($pdo, (int) $row['subscriber_id'], $result);
        if (!empty($result['success'])) {
            $pdo->prepare(
                'UPDATE invoices SET reminder_sent_at = NOW(), reminder_count = reminder_count + 1 WHERE id = :id'
            )->execute(array(':id' => $id));
            flash('success', 'تم إرسال تذكير واتساب');
        } else {
            flash('error', whatsapp_fail_user_message($result));
        }
        $backSid = $returnSid > 0 ? $returnSid : (int) $row['subscriber_id'];
        if ($backSid > 0) {
            redirect('debts.php?status=unpaid&subscriber_id=' . $backSid);
        }
        redirect('debts.php?status=unpaid');
    }
}

$status = isset($_GET['status']) ? $_GET['status'] : 'unpaid';
if (!in_array($status, array('unpaid', 'paid', 'all'), true)) {
    $status = 'unpaid';
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filterSubscriberId = isset($_GET['subscriber_id']) ? (int) $_GET['subscriber_id'] : 0;
$showAdd = isset($_GET['add']);

$sql = 'SELECT i.*, s.name, s.phone
        FROM invoices i
        JOIN subscribers s ON s.id = i.subscriber_id
        WHERE 1=1';
$params = array();
if (function_exists('subscriber_agent_scope_sql')) {
    $sql .= subscriber_agent_scope_sql('s');
}
if ($status !== 'all') {
    $sql .= ' AND i.status = :status';
    $params[':status'] = $status;
}
if ($filterSubscriberId > 0) {
    $sql .= ' AND i.subscriber_id = :sid';
    $params[':sid'] = $filterSubscriberId;
}
if ($q !== '') {
    $sql .= ' AND (s.name LIKE :q OR s.phone LIKE :q OR i.notes LIKE :q2 OR i.month_label LIKE :q3)';
    $params[':q'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
$sql .= ' ORDER BY i.due_date ASC, i.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$debtScope = function_exists('subscriber_agent_scope_sql') ? subscriber_agent_scope_sql('s') : '';
$totalDebt = (float) $pdo->query(
    "SELECT COALESCE(SUM(i.amount),0) FROM invoices i
     JOIN subscribers s ON s.id = i.subscriber_id
     WHERE i.status='unpaid'" . $debtScope
)->fetchColumn();
$unpaidBySub = array();
try {
    $ut = $pdo->query(
        "SELECT i.subscriber_id, COALESCE(SUM(i.amount),0) AS t FROM invoices i
         JOIN subscribers s ON s.id = i.subscriber_id
         WHERE i.status = 'unpaid'" . $debtScope . '
         GROUP BY i.subscriber_id'
    );
    foreach ($ut->fetchAll() as $u) {
        $unpaidBySub[(int) $u['subscriber_id']] = (float) $u['t'];
    }
} catch (Exception $e) {
}
$subscribers = $pdo->query(
    'SELECT id, name, phone, rental_enabled, rental_device_id FROM subscribers s WHERE 1=1' . $debtScope . ' ORDER BY name'
)->fetchAll();
$settingsDebt = settings_load();
$rentFeeGlobal = (float) rental_fee_amount($settingsDebt);
$subPriceMap = array();
foreach ($subscribers as $s) {
    $subPriceMap[(int) $s['id']] = subscriber_monthly_price($pdo, (int) $s['id']);
}
$filterName = '';
$filterPhone = '';
if ($filterSubscriberId > 0) {
    foreach ($subscribers as $s) {
        if ((int) $s['id'] === $filterSubscriberId) {
            $filterName = $s['name'];
            $filterPhone = format_phone_display(isset($s['phone']) ? $s['phone'] : '');
            break;
        }
    }
}

$cardDebt = $totalDebt;
$cardLabel = t('debts_total');
$cardHref = 'debts.php?status=unpaid';
if ($filterSubscriberId > 0) {
    $cardDebt = isset($unpaidBySub[$filterSubscriberId])
        ? $unpaidBySub[$filterSubscriberId]
        : subscriber_unpaid_total($pdo, $filterSubscriberId);
    $cardLabel = $lang === 'en' ? 'Subscriber debt' : 'ديون المشترك';
    $cardHref = 'debts.php?status=unpaid&subscriber_id=' . $filterSubscriberId;
}

$canEditDebts = function_exists('user_can_edit_debts') ? user_can_edit_debts() : false;
if (!$canEditDebts) {
    $showAdd = false;
}

render_header(t('debts'), 'debts');
?>
<style>
.debts-page { max-width: 1180px; margin: 0 auto; }
.debts-hero {
  display: flex; flex-wrap: wrap; gap: 14px; align-items: stretch; justify-content: space-between;
  margin: 0 0 14px; padding: 16px 18px; border-radius: 18px;
  background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 55%, #0ea5e9 120%);
  color: #fff; box-shadow: 0 12px 30px rgba(15,23,42,.18);
}
.debts-hero h1 { margin: 0 0 6px; font-size: 20px; font-weight: 800; }
.debts-hero p { margin: 0; opacity: .88; font-size: 13px; font-weight: 600; max-width: 52ch; }
.debts-hero-stat {
  min-width: 180px; padding: 12px 14px; border-radius: 14px;
  background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.22);
  backdrop-filter: blur(8px);
}
.debts-hero-stat .label { font-size: 12px; font-weight: 700; opacity: .9; }
.debts-hero-stat .value { font-size: 26px; font-weight: 800; margin-top: 4px; letter-spacing: -.02em; }
.debts-toolbar {
  display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
  margin: 0 0 12px; padding: 12px; border-radius: 14px;
  background: rgba(255,255,255,.92); border: 1px solid rgba(15,23,42,.08);
  box-shadow: 0 6px 18px rgba(15,23,42,.04);
}
.debts-toolbar input#debtFilter {
  flex: 1 1 220px; max-width: 320px; margin: 0; height: 38px;
}
.debts-tabs { display: inline-flex; gap: 6px; flex-wrap: wrap; }
.debts-tabs a {
  display: inline-flex; align-items: center; height: 36px; padding: 0 14px;
  border-radius: 999px; font-size: 13px; font-weight: 800; text-decoration: none;
  border: 1px solid #e2e8f0; background: #fff; color: #334155;
}
.debts-tabs a.is-on { background: #0f172a; color: #fff; border-color: #0f172a; }
.debts-table-wrap {
  overflow: auto; border-radius: 16px; border: 1px solid rgba(15,23,42,.08);
  background: rgba(255,255,255,.94); box-shadow: 0 8px 24px rgba(15,23,42,.05);
}
.debts-page .debts-mini-table { width: 100%; border-collapse: collapse; min-width: 720px; margin: 0; }
.debts-page .debts-mini-table th,
.debts-page .debts-mini-table td {
  padding: 12px 14px; border-bottom: 1px solid #eef2f7; text-align: start; vertical-align: middle;
}
.debts-page .debts-mini-table th {
  background: #f1f5f9; color: #334155; font-weight: 800; font-size: 12px;
  position: sticky; top: 0; z-index: 1;
}
.debts-page .debts-mini-table tbody tr:hover td { background: #f8fafc; }
.debts-page .debts-mini-table tr:last-child td { border-bottom: 0; }
.debts-page .pay-inline-form { margin: 0; }
.debts-page .pay-inline-row {
  display: flex; gap: 6px; align-items: center; flex-wrap: wrap;
}
.debts-page .pay-inline-row .js-pay-amt {
  width: 96px; height: 34px; margin: 0; border-radius: 10px;
}
.debts-page .btn.money.sm {
  height: 34px; border-radius: 10px; padding: 0 12px; font-weight: 800;
  background: #16a34a; border-color: #16a34a;
}
.debts-page .debt-amt {
  border: 0; background: transparent; color: #0f172a; font-weight: 800;
  cursor: pointer; padding: 2px 4px; border-radius: 6px;
}
.debts-page .debt-amt:hover { background: #e2e8f0; }
.debts-add {
  margin: 0 0 12px; padding: 14px; border-radius: 16px;
  border: 1px solid rgba(15,23,42,.08); background: rgba(255,255,255,.94);
}
@media (max-width: 640px) {
  .debts-hero { padding: 14px; }
  .debts-hero-stat { min-width: 100%; }
}
</style>
<div class="debts-page">
<p class="meta" style="margin:0 0 10px">
    <?php echo e($lang === 'en'
        ? 'Local debts are independent of SAS. Changing debt on SAS does not change amounts here.'
        : 'ديون النظام محلية ومستقلة عن الساس. تعديل الدين بالساس لا يغيّر المبالغ هنا.'); ?>
</p>
<div class="debts-hero">
  <div>
    <h1><?php
      if ($filterSubscriberId > 0) {
          echo e($lang === 'en' ? 'Subscriber debts' : 'ديون المشترك');
      } else {
          echo e($lang === 'en' ? 'Payments & debts' : 'التسديد والديون');
      }
    ?></h1>
    <p><?php
      if ($filterSubscriberId > 0) {
          echo e($filterName !== '' ? $filterName : ('#' . $filterSubscriberId));
          if ($filterPhone !== '') {
              echo ' · ' . e($filterPhone);
          }
      } else {
          echo e($lang === 'en'
              ? 'Track unpaid invoices, collect payments, and send WhatsApp reminders.'
              : 'متابعة الفواتير غير المسددة، استلام الدفعات، وإرسال تذكير واتساب.');
      }
    ?></p>
  </div>
  <a class="debts-hero-stat" href="<?php echo e($cardHref); ?>" style="color:inherit;text-decoration:none">
    <div class="label"><?php echo e($cardLabel); ?></div>
    <div class="value"><?php echo e(money_format_iqd($cardDebt, $config['currency'])); ?></div>
  </a>
</div>

<div class="debts-toolbar">
        <?php if ($canEditDebts): ?>
        <button class="btn secondary" type="button" id="debtsAddBtn"><?php echo e($lang === 'en' ? 'Add debt' : 'إضافة دين'); ?></button>
        <?php endif; ?>
        <input id="debtFilter" placeholder="<?php echo e($lang === 'en' ? 'Instant search...' : 'بحث فوري اسم أو رقم...'); ?>" value="<?php echo e($q); ?>">
        <div class="debts-tabs">
        <a class="<?php echo $status === 'unpaid' ? 'is-on' : ''; ?>" href="?status=unpaid<?php echo $filterSubscriberId ? '&subscriber_id=' . $filterSubscriberId : ''; ?>"><?php echo e($lang === 'en' ? 'Unpaid' : 'غير مسدد'); ?></a>
        <a class="<?php echo $status === 'paid' ? 'is-on' : ''; ?>" href="?status=paid<?php echo $filterSubscriberId ? '&subscriber_id=' . $filterSubscriberId : ''; ?>"><?php echo e($lang === 'en' ? 'Paid' : 'مسدد'); ?></a>
        <a class="<?php echo $status === 'all' ? 'is-on' : ''; ?>" href="?status=all<?php echo $filterSubscriberId ? '&subscriber_id=' . $filterSubscriberId : ''; ?>"><?php echo e(t('show_all')); ?></a>
        </div>
        <?php if ($filterSubscriberId > 0): ?>
            <a class="btn ghost" href="debts.php?status=unpaid"><?php echo e(t('show_all')); ?></a>
        <?php endif; ?>
        <span class="meta" style="margin-inline-start:auto"><?php echo e($lang === 'en' ? 'Right-click a row for actions' : 'كلك يمين على السطر للإجراءات'); ?></span>
</div>

<?php if ($canEditDebts): ?>
<div class="debts-add collapse-box<?php echo $showAdd ? '' : ' hidden'; ?>" id="addDebtBox">
    <h2 style="margin:0 0 10px;font-size:16px"><?php echo e($lang === 'en' ? 'Add invoice / debt' : 'إضافة فاتورة / دين'); ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="add_invoice">
        <div class="form-grid">
            <div>
                <label><?php echo e(t('subscribers')); ?></label>
                <select name="subscriber_id" id="debtSubSelect" required>
                    <option value="">...</option>
                    <?php foreach ($subscribers as $s): ?>
                        <?php $sid = (int) $s['id']; $sHasRent = !empty($s['rental_enabled']) && !empty($s['rental_device_id']); ?>
                        <option value="<?php echo $sid; ?>"
                            data-rent="<?php echo $sHasRent ? '1' : '0'; ?>"
                            data-subprice="<?php echo (float) (isset($subPriceMap[$sid]) ? $subPriceMap[$sid] : 0); ?>"
                            data-rentfee="<?php echo (float) $rentFeeGlobal; ?>"
                            <?php echo $filterSubscriberId === $sid ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Debt type' : 'نوع الدين'); ?></label>
                <select name="debt_kind" id="debtKind">
                    <option value="month"><?php echo e(t('debt_type_month')); ?></option>
                    <option value="month_rent" id="debtKindMonthRent" hidden><?php echo e(t('debt_type_month_rent')); ?></option>
                    <option value="item"><?php echo e(t('debt_type_item')); ?></option>
                </select>
            </div>
            <div id="monthField">
                <label><?php echo e($lang === 'en' ? 'Month (YYYY-MM)' : 'الشهر (YYYY-MM)'); ?></label>
                <input name="month_label" id="monthLabelInput" value="<?php echo e(date('Y-m')); ?>">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Amount' : 'المبلغ'); ?></label>
                <input type="number" name="amount" id="debtAmountInput" min="1" step="1" required>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Due date' : 'تاريخ الاستحقاق'); ?></label>
                <input type="date" name="due_date" value="<?php echo e(date('Y-m-d')); ?>" required>
            </div>
            <div style="grid-column:1/-1">
                <label><?php echo e(t('debt_notes')); ?></label>
                <input name="notes" placeholder="<?php echo e($lang === 'en' ? 'e.g. bought router on credit' : 'مثال: اشترى راوتر بالدين'); ?>">
            </div>
        </div>
        <div class="actions">
            <label class="toggle">
                <input type="checkbox" name="send_whatsapp" value="1" checked>
                <span class="toggle-ui"></span>
                <span class="toggle-text"><?php echo e(t('send_whatsapp')); ?></span>
            </label>
            <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Save debt' : 'حفظ الدين'); ?></button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="debts-table-wrap">
        <table id="debtTable" class="table-compact debts-mini-table">
            <thead>
            <tr>
                <th><?php echo e(t('name')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Month' : 'الشهر'); ?></th>
                <th><?php echo e($lang === 'en' ? 'Amount' : 'المبلغ'); ?></th>
                <th><?php echo e(t('notes')); ?></th>
                <?php if ($status !== 'unpaid'): ?>
                <th><?php echo e(t('profit')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Status' : 'الحالة'); ?></th>
                <th><?php echo e($lang === 'en' ? 'Actions' : 'إجراءات'); ?></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7"><?php echo e($lang === 'en' ? 'No debts' : 'لا توجد ديون'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $rowSubId = (int) $row['subscriber_id'];
                $rowAmt = (int) round((float) $row['amount']);
                $rowSubTotal = isset($unpaidBySub[$rowSubId]) ? (int) round((float) $unpaidBySub[$rowSubId]) : $rowAmt;
                ?>
                <tr
                    data-filter="<?php echo e($row['name'] . ' ' . $row['phone'] . ' ' . (isset($row['notes']) ? $row['notes'] : '') . ' ' . $row['month_label']); ?>"
                    data-invoice="<?php echo (int) $row['id']; ?>"
                    data-sub="<?php echo $rowSubId; ?>"
                    data-amount="<?php echo $rowAmt; ?>"
                    data-sub-total="<?php echo $rowSubTotal; ?>"
                    data-name="<?php echo e($row['name']); ?>"
                    data-status="<?php echo e($row['status']); ?>"
                >
                    <td>
                        <a href="subscriber.php?id=<?php echo $rowSubId; ?>"><strong><?php echo e($row['name']); ?></strong></a>
                        <div class="meta"><?php echo e(format_phone_display($row['phone'])); ?></div>
                    </td>
                    <td>
                        <strong><?php echo e(month_short_label($row['month_label'])); ?></strong>
                        <div class="meta"><?php echo e($row['due_date']); ?></div>
                    </td>
                    <td>
                        <?php if ($row['status'] === 'unpaid' && !empty($canEditDebts)): ?>
                            <button type="button" class="debt-amt debt-due debt-edit-btn"
                                data-invoice="<?php echo (int) $row['id']; ?>"
                                data-sub="<?php echo $rowSubId; ?>"
                                data-amount="<?php echo $rowAmt; ?>"
                                title="<?php echo e($lang === 'en' ? 'Click to edit debt' : 'اضغط لتعديل الدين'); ?>"
                            ><?php echo e(money_format_iqd($row['amount'], $config['currency'])); ?></button>
                        <?php else: ?>
                            <strong><?php echo e(money_format_iqd($row['amount'], $config['currency'])); ?></strong>
                        <?php endif; ?>
                    </td>
                    <td class="notes-cell"><?php echo e(isset($row['notes']) ? $row['notes'] : ''); ?></td>
                    <?php if ($status !== 'unpaid'): ?>
                    <td><?php echo e(money_format_iqd(isset($row['profit']) ? $row['profit'] : 0, $config['currency'])); ?></td>
                    <td><span class="badge <?php echo e($row['status']); ?>"><?php echo $row['status'] === 'paid' ? ($lang === 'en' ? 'Paid' : 'مسدد') : ($lang === 'en' ? 'Unpaid' : 'غير مسدد'); ?></span></td>
                    <td>
                        <?php if ($row['status'] === 'paid' && $canEditDebts): ?>
                        <form method="post">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="unpay">
                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                            <button class="btn ghost sm" type="submit"><?php echo e($lang === 'en' ? 'Undo pay' : 'إلغاء التسديد'); ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
</div>
</div>

<div class="ops-dropdown hidden" id="debtsOpsMenu" role="menu">
    <button type="button" class="ops-item" data-debt-ops="add" id="debtsOpsAdd"><?php echo e($lang === 'en' ? 'Add debt' : 'إضافة دين'); ?></button>
    <button type="button" class="ops-item" data-debt-ops="pay_one" id="debtsOpsPayOne"><?php echo e(t('pay_this_amount')); ?></button>
    <button type="button" class="ops-item" data-debt-ops="pay_all" id="debtsOpsPayAll"><?php echo e(t('pay_all_debts')); ?></button>
    <button type="button" class="ops-item" data-debt-ops="delete" id="debtsOpsDelete"><?php echo e($lang === 'en' ? 'Delete debt' : 'حذف دين'); ?></button>
    <button type="button" class="ops-item" data-debt-ops="remind" id="debtsOpsRemind"><?php echo e(t('remind')); ?></button>
</div>
<form method="post" id="debtDeleteForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="delete_invoice">
    <input type="hidden" name="id" id="debtDeleteId" value="">
    <input type="hidden" name="subscriber_id" id="debtDeleteSub" value="">
    <?php if ($filterSubscriberId > 0): ?>
        <input type="hidden" name="return_subscriber" value="<?php echo (int) $filterSubscriberId; ?>">
    <?php endif; ?>
</form>
<form method="post" id="debtRemindForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" value="remind">
    <input type="hidden" name="id" id="debtRemindId" value="">
    <?php if ($filterSubscriberId > 0): ?>
        <input type="hidden" name="return_subscriber" value="<?php echo (int) $filterSubscriberId; ?>">
    <?php endif; ?>
</form>
<script>
(function () {
  var csrf = <?php echo json_encode(csrf_token()); ?>;
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('.debt-edit-btn') : null;
    if (!btn || btn.classList.contains('editing')) return;
    e.preventDefault();
    e.stopPropagation();
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
      if (!ok || raw === '') {
        btn.textContent = snap;
        return;
      }
      if (isNaN(n) || n < 0) {
        alert(<?php echo json_encode($lang === 'en' ? 'Enter a valid amount' : 'أدخل مبلغ صحيح'); ?>);
        btn.textContent = snap;
        return;
      }
      if (String(n) === String(current)) {
        btn.textContent = snap;
        return;
      }
      var body = new FormData();
      body.append('csrf', csrf);
      body.append('action', 'update_invoice_amount');
      body.append('ajax', '1');
      body.append('invoice_id', btn.getAttribute('data-invoice') || '');
      body.append('subscriber_id', btn.getAttribute('data-sub') || '');
      body.append('amount', String(n));
      fetch('debts.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            alert((data && data.message) ? data.message : <?php echo json_encode($lang === 'en' ? 'Could not save' : 'تعذر الحفظ'); ?>);
            btn.textContent = snap;
            return;
          }
          btn.textContent = data.debt_text || String(n);
          btn.setAttribute('data-amount', String(Math.round(Number(data.debt) || n)));
          var row = btn.closest('tr');
          if (row) row.setAttribute('data-amount', String(Math.round(Number(data.debt) || n)));
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
  });
})();
</script>
<script>
(function () {
  var input = document.getElementById('debtFilter');
  if (input) {
    input.addEventListener('input', function () {
      var q = (input.value || '').toLowerCase();
      var items = document.querySelectorAll('[data-filter]');
      for (var i = 0; i < items.length; i++) {
        var hay = (items[i].getAttribute('data-filter') || '').toLowerCase();
        items[i].style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
      }
    });
  }
  var addBtn = document.getElementById('debtsAddBtn');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      var box = document.getElementById('addDebtBox');
      if (box) box.classList.toggle('hidden');
    });
  }
  var kind = document.getElementById('debtKind');
  var monthInput = document.getElementById('monthLabelInput');
  var monthField = document.getElementById('monthField');
  var subSelect = document.getElementById('debtSubSelect');
  var rentOpt = document.getElementById('debtKindMonthRent');
  var amountInput = document.getElementById('debtAmountInput');
  function selectedSubOpt() {
    if (!subSelect) return null;
    return subSelect.options[subSelect.selectedIndex] || null;
  }
  function syncRentOption() {
    var opt = selectedSubOpt();
    var hasRent = opt && opt.getAttribute('data-rent') === '1';
    if (rentOpt) {
      rentOpt.hidden = !hasRent;
      if (!hasRent && kind && kind.value === 'month_rent') {
        kind.value = 'month';
      }
    }
  }
  function syncKind() {
    if (!kind || !monthInput) return;
    if (kind.value === 'item') {
      monthInput.value = 'غرض';
      if (monthField) {
        var lab = monthField.querySelector('label');
        if (lab) lab.textContent = <?php echo json_encode($lang === 'en' ? 'Label' : 'وصف مختصر'); ?>;
      }
    } else {
      if (!/^\d{4}-\d{2}$/.test(monthInput.value)) {
        monthInput.value = <?php echo json_encode(date('Y-m')); ?>;
      }
      if (monthField) {
        var lab2 = monthField.querySelector('label');
        if (lab2) lab2.textContent = <?php echo json_encode($lang === 'en' ? 'Month (YYYY-MM)' : 'الشهر (YYYY-MM)'); ?>;
      }
    }
    if (kind.value === 'month_rent' && amountInput) {
      var opt = selectedSubOpt();
      if (opt) {
        var sub = parseFloat(opt.getAttribute('data-subprice') || '0') || 0;
        var rent = parseFloat(opt.getAttribute('data-rentfee') || '0') || 0;
        var total = Math.round(sub + rent);
        if (total > 0) amountInput.value = String(total);
      }
    }
  }
  if (kind) kind.addEventListener('change', syncKind);
  if (subSelect) subSelect.addEventListener('change', function () { syncRentOption(); syncKind(); });
  syncRentOption();
  syncKind();
})();
</script>
<div class="modal-backdrop hidden" id="payFloat" role="dialog" aria-modal="true">
    <div class="modal-card pay-float-card">
        <h3 id="payFloatTitle"><?php echo e($lang === 'en' ? 'Confirm payment' : 'تأكيد التسديد'); ?></h3>
        <p class="meta" id="payFloatWho" style="margin:0 0 10px"></p>
        <div class="pay-float-amt" id="payFloatAmt"></div>
        <label class="toggle" style="margin:14px 0">
            <input type="checkbox" id="payAutoToggle" checked>
            <span class="toggle-ui" aria-hidden="true"></span>
            <span class="toggle-text"><?php echo e($lang === 'en' ? 'Automatic' : 'تلقائي'); ?></span>
        </label>
        <p class="meta" style="margin:0 0 12px"><?php echo e($lang === 'en' ? 'On = send WhatsApp' : 'تشغيل = إرسال واتساب'); ?></p>
        <div class="actions" style="margin-top:0">
            <button class="btn" type="button" id="payFloatOk"><?php echo e($lang === 'en' ? 'Pay' : 'تسديد'); ?></button>
            <button class="btn ghost" type="button" id="payFloatCancel"><?php echo e($lang === 'en' ? 'Cancel' : 'إلغاء'); ?></button>
        </div>
    </div>
</div>
<form method="post" id="payConfirmForm" class="hidden" hidden>
    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
    <input type="hidden" name="action" id="payConfirmAction" value="pay">
    <input type="hidden" name="id" id="payConfirmId" value="">
    <input type="hidden" name="subscriber_id" id="payConfirmSub" value="">
    <input type="hidden" name="pay_amount" id="payConfirmAmt" value="">
    <input type="hidden" name="send_whatsapp" id="payConfirmWa" value="1">
    <?php if ($filterSubscriberId > 0): ?>
        <input type="hidden" name="return_subscriber" value="<?php echo (int) $filterSubscriberId; ?>">
    <?php endif; ?>
</form>
<script>
(function () {
  var box = document.getElementById('payFloat');
  var title = document.getElementById('payFloatTitle');
  var who = document.getElementById('payFloatWho');
  var amtEl = document.getElementById('payFloatAmt');
  var tog = document.getElementById('payAutoToggle');
  var form = document.getElementById('payConfirmForm');
  var act = document.getElementById('payConfirmAction');
  var idEl = document.getElementById('payConfirmId');
  var subEl = document.getElementById('payConfirmSub');
  var amtIn = document.getElementById('payConfirmAmt');
  var waEl = document.getElementById('payConfirmWa');
  var pending = null;
  var txtOne = <?php echo json_encode(t('pay_this_amount')); ?>;
  var txtAll = <?php echo json_encode(t('pay_all_debts')); ?>;
  var cur = <?php echo json_encode($config['currency']); ?>;
  function fmt(n) {
    n = Math.round(Number(n) || 0);
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' ' + cur;
  }
  function closePay() {
    if (box) box.classList.add('hidden');
    pending = null;
  }
  function openPay(opts) {
    opts = opts || {};
    var mode = opts.mode || 'one';
    var amount = parseFloat(opts.amount || '0') || 0;
    if (!(amount > 0)) {
      alert(<?php echo json_encode($lang === 'en' ? 'No amount to pay' : 'ماكو مبلغ للتسديد'); ?>);
      return;
    }
    pending = {
      mode: mode,
      invoice: opts.invoice || '',
      sub: opts.sub || '',
      amount: amount,
      name: opts.name || ''
    };
    if (title) title.textContent = mode === 'all' ? txtAll : txtOne;
    if (who) who.textContent = pending.name;
    if (amtEl) amtEl.textContent = fmt(amount);
    if (tog) tog.checked = true;
    if (box) box.classList.remove('hidden');
  }
  window.openDebtPay = openPay;
  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('.js-pay-open') : null;
    if (btn) {
      e.preventDefault();
      openPay({
        mode: btn.getAttribute('data-mode') || 'one',
        invoice: btn.getAttribute('data-invoice') || '',
        sub: btn.getAttribute('data-sub') || '',
        amount: btn.getAttribute('data-amount') || '0',
        name: btn.getAttribute('data-name') || ''
      });
    }
  });
  var okBtn = document.getElementById('payFloatOk');
  var cancelBtn = document.getElementById('payFloatCancel');
  if (okBtn) {
    okBtn.addEventListener('click', function () {
      if (!pending || !form) return;
      act.value = pending.mode === 'all' ? 'pay_all' : 'pay';
      idEl.value = pending.invoice;
      subEl.value = pending.sub;
      amtIn.value = String(pending.amount);
      waEl.value = (tog && tog.checked) ? '1' : '0';
      form.submit();
    });
  }
  if (cancelBtn) cancelBtn.addEventListener('click', closePay);
  if (box) {
    box.addEventListener('click', function (e) {
      if (e.target === box) closePay();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closePay();
  });
})();
</script>
<script>
(function () {
  var menu = document.getElementById('debtsOpsMenu');
  var table = document.getElementById('debtTable');
  var canEdit = <?php echo json_encode(!empty($canEditDebts)); ?>;
  var ctx = null;
  function hideMenu() {
    if (menu) menu.classList.add('hidden');
  }
  function placeMenu(x, y) {
    if (!menu) return;
    menu.classList.remove('hidden');
    menu.classList.add('ops-float');
    var w = menu.offsetWidth || 200;
    var h = menu.offsetHeight || 160;
    var left = Math.min(x, (window.innerWidth || 800) - w - 8);
    var top = Math.min(y, (window.innerHeight || 600) - h - 8);
    if (left < 8) left = 8;
    if (top < 8) top = 8;
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    menu.style.position = 'fixed';
    menu.style.zIndex = '9999';
  }
  function showMenuForRow(tr, x, y) {
    if (!tr || !menu) return;
    ctx = {
      invoice: tr.getAttribute('data-invoice') || '',
      sub: tr.getAttribute('data-sub') || '',
      amount: tr.getAttribute('data-amount') || '0',
      subTotal: tr.getAttribute('data-sub-total') || tr.getAttribute('data-amount') || '0',
      name: tr.getAttribute('data-name') || '',
      status: tr.getAttribute('data-status') || ''
    };
    var unpaid = ctx.status === 'unpaid';
    function vis(id, on) {
      var el = document.getElementById(id);
      if (el) el.hidden = !on;
    }
    vis('debtsOpsAdd', canEdit);
    vis('debtsOpsPayOne', canEdit && unpaid);
    vis('debtsOpsPayAll', canEdit && unpaid && Number(ctx.subTotal) > 0);
    vis('debtsOpsDelete', canEdit && unpaid);
    vis('debtsOpsRemind', unpaid);
    placeMenu(x, y);
  }
  if (table) {
    table.addEventListener('contextmenu', function (e) {
      var tr = e.target && e.target.closest ? e.target.closest('tbody tr[data-invoice]') : null;
      if (!tr || !table.contains(tr)) return;
      if (e.target.closest('a, input, select, textarea, form')) return;
      e.preventDefault();
      showMenuForRow(tr, e.clientX, e.clientY);
    });
  }
  document.addEventListener('click', function (e) {
    if (menu && !menu.classList.contains('hidden') && !e.target.closest('#debtsOpsMenu')) hideMenu();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') hideMenu();
  });
  if (menu) {
    menu.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('[data-debt-ops]') : null;
      if (!btn || !ctx) return;
      var op = btn.getAttribute('data-debt-ops');
      hideMenu();
      if (op === 'add') {
        var box = document.getElementById('addDebtBox');
        var sel = document.getElementById('debtSubSelect');
        if (sel && ctx.sub) sel.value = String(ctx.sub);
        if (box) {
          box.classList.remove('hidden');
          try { box.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (err) {}
        }
        return;
      }
      if (op === 'pay_one') {
        if (typeof window.openDebtPay === 'function') {
          window.openDebtPay({
            mode: 'one',
            invoice: ctx.invoice,
            sub: ctx.sub,
            amount: ctx.amount,
            name: ctx.name
          });
        }
        return;
      }
      if (op === 'pay_all') {
        if (typeof window.openDebtPay === 'function') {
          window.openDebtPay({
            mode: 'all',
            invoice: '0',
            sub: ctx.sub,
            amount: ctx.subTotal,
            name: ctx.name
          });
        }
        return;
      }
      if (op === 'delete') {
        if (!window.confirm(<?php echo json_encode($lang === 'en' ? 'Delete this debt? It will be removed from totals and logged.' : 'تحذف هذا الدين؟ ينشال من المجموع وينحفظ بالسجل.'); ?>)) return;
        var f = document.getElementById('debtDeleteForm');
        var idEl = document.getElementById('debtDeleteId');
        var subEl = document.getElementById('debtDeleteSub');
        if (idEl) idEl.value = ctx.invoice;
        if (subEl) subEl.value = ctx.sub;
        if (f) f.submit();
        return;
      }
      if (op === 'remind') {
        var rf = document.getElementById('debtRemindForm');
        var rid = document.getElementById('debtRemindId');
        if (rid) rid.value = ctx.invoice;
        if (rf) rf.submit();
      }
    });
  }
})();
</script>
<?php render_footer(); ?>
