<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$subscribers = $pdo->query('SELECT id, name, phone FROM subscribers ORDER BY name')->fetchAll();
$plans = $pdo->query('SELECT * FROM service_plans WHERE is_active = 1 ORDER BY sort_order ASC, monthly_price ASC, id ASC')->fetchAll();

$preselectId = isset($_GET['subscriber_id']) ? (int) $_GET['subscriber_id'] : 0;
$preDays = isset($_GET['days']) ? (int) $_GET['days'] : 0;
$periodMode = subscription_period_mode($config);
$todayStart = date('Y-m-d');
// إذا جاي من الدفتر: نملأ الأيام. غير هيج نخلي الحقل فاضي حتى يكتبها المستخدم
$defaultDaysLeft = ($preDays > 0) ? $preDays : '';
$defaultEnd = ($preDays > 0)
    ? date('Y-m-d', strtotime($todayStart . ' +' . $preDays . ' days'))
    : subscription_period_end($todayStart, $config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect('activate.php');
    }

    $action = post('action');
    if ($action === 'create') {
        $subscriberId = (int) post('subscriber_id', '0');
        $planId = (int) post('plan_id', '0');
        $startDate = (string) post('start_date', '');
        $endDate = (string) post('end_date', '');
        $daysRaw = trim((string) post('days_left', ''));
        $daysLeftPost = ($daysRaw === '') ? -1 : (int) $daysRaw;
        $msgNote = (string) post('message_note', '');
        $sendWhatsapp = post('send_whatsapp') === '1';
        $ledgerImport = post('ledger_import') === '1';
        // نقد = دفع الآن | آجل = دين
        $payMode = post('pay_mode') === 'credit' ? 'credit' : 'cash';

        if ($subscriberId <= 0 || $planId <= 0 || $startDate === '') {
            flash('error', 'اختر المشترك والباقة وتاريخ البداية');
            redirect('activate.php');
        }

        $planStmt = $pdo->prepare('SELECT * FROM service_plans WHERE id = :id AND is_active = 1');
        $planStmt->execute(array(':id' => $planId));
        $plan = $planStmt->fetch();
        if (!$plan) {
            flash('error', 'الباقة غير موجودة');
            redirect('activate.php');
        }

        // الأولوية لأيام يكتبها المستخدم — النهاية تُحسب من تاريخ البداية
        if ($daysLeftPost >= 0) {
            $endDate = date('Y-m-d', strtotime($startDate . ' +' . $daysLeftPost . ' days'));
        } elseif ($endDate === '') {
            $endDate = subscription_period_end($startDate, $config);
        }
        if ($endDate < $startDate) {
            flash('error', 'تاريخ الانتهاء غير صحيح');
            redirect('activate.php');
        }

        // نقل دفتر: أيام + باقة فقط بدون فاتورة/واتساب تفعيل
        if ($ledgerImport) {
            $daysForLedger = ($daysLeftPost >= 0)
                ? $daysLeftPost
                : max(0, (int) ceil((strtotime($endDate) - strtotime(date('Y-m-d'))) / 86400));
            list($ok, $msg) = apply_subscriber_days_left($pdo, $subscriberId, $daysForLedger, $planId);
            flash($ok ? 'success' : 'error', $msg);
            redirect($ok ? ('subscriber.php?id=' . $subscriberId) : 'activate.php?subscriber_id=' . $subscriberId . '&days=' . $daysForLedger);
        }

        $serviceName = $plan['name'];
        $monthlyPrice = (float) $plan['monthly_price'];
        $costPrice = isset($plan['cost_price']) ? (float) $plan['cost_price'] : 0;

        $subRowSt = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $subRowSt->execute(array(':id' => $subscriberId));
        $subscriberRow = $subRowSt->fetch();
        if (!$subscriberRow) {
            flash('error', 'المشترك غير موجود');
            redirect('activate.php');
        }
        $settingsNow = settings_load();
        $rentalFee = 0;
        $rentalDev = null;
        if (subscriber_has_rental($subscriberRow)) {
            $rentalFee = rental_fee_amount($settingsNow);
            $rentalDev = rental_device_by_id($subscriberRow['rental_device_id'], $settingsNow);
        }
        $chargeTotal = $monthlyPrice + $rentalFee;

        $isCash = ($payMode === 'cash');
        $invStatus = $isCash ? 'paid' : 'unpaid';
        $profit = $isCash ? ($chargeTotal - $costPrice) : 0;
        $modeLabel = $isCash ? 'نقد' : 'آجل';
        $invNotes = $modeLabel;
        if ($rentalFee > 0) {
            $invNotes .= ' — اشتراك ' . $monthlyPrice . ' + إيجار ' . $rentalFee;
            if ($rentalDev) {
                $invNotes .= ' (' . $rentalDev['name'] . ')';
            }
        }
        if ($msgNote !== '') {
            $invNotes .= ' — ' . $msgNote;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO subscriptions
                (subscriber_id, service_name, monthly_price, cost_price, start_date, end_date, status, activation_msg_sent)
                VALUES
                (:subscriber_id, :service_name, :monthly_price, :cost_price, :start_date, :end_date, "active", 0)'
            );
            $stmt->execute(array(
                ':subscriber_id' => $subscriberId,
                ':service_name' => $serviceName,
                ':monthly_price' => $monthlyPrice,
                ':cost_price' => $costPrice,
                ':start_date' => $startDate,
                ':end_date' => $endDate,
            ));
            $subscriptionId = (int) $pdo->lastInsertId();

            $monthLabel = date('Y-m', strtotime($startDate));
            $inv = $pdo->prepare(
                'INSERT INTO invoices
                (subscription_id, subscriber_id, month_label, amount, cost_price, profit, due_date, status, paid_at, notes)
                VALUES
                (:subscription_id, :subscriber_id, :month_label, :amount, :cost_price, :profit, :due_date, :status, :paid_at, :notes)'
            );
            $inv->execute(array(
                ':subscription_id' => $subscriptionId,
                ':subscriber_id' => $subscriberId,
                ':month_label' => $monthLabel,
                ':amount' => $chargeTotal,
                ':cost_price' => $costPrice,
                ':profit' => $profit,
                ':due_date' => $startDate,
                ':status' => $invStatus,
                ':paid_at' => $isCash ? date('Y-m-d H:i:s') : null,
                ':notes' => $invNotes,
            ));

            $subInfo = $pdo->prepare(
                'SELECT sub.*, s.name, s.phone, s.rental_enabled, s.rental_device_id
                 FROM subscriptions sub
                 JOIN subscribers s ON s.id = sub.subscriber_id
                 WHERE sub.id = :id'
            );
            $subInfo->execute(array(':id' => $subscriptionId));
            $row = $subInfo->fetch();

            if ($sendWhatsapp && $row) {
                $extra = $msgNote;
                if ($isCash) {
                    $extra = trim(($extra !== '' ? $extra . "\n" : '') . 'تم استلام المبلغ نقداً');
                } else {
                    $extra = trim(($extra !== '' ? $extra . "\n" : '') . 'الدفع آجل — يرجى التسديد');
                }
                $rentName = $rentalDev ? $rentalDev['name'] : '';
                $msg = activation_message_with_rental($row, $config, $extra, $rentalFee, $rentName);
                $result = whatsapp_send($config, $row['phone'], $msg, 'activation');
                log_message($pdo, $subscriberId, $result);
                if (!empty($result['success'])) {
                    $pdo->prepare('UPDATE subscriptions SET activation_msg_sent = 1 WHERE id = :id')
                        ->execute(array(':id' => $subscriptionId));
                    flash('success', 'تم التفعيل (' . $modeLabel . ') وإرسال واتساب');
                } else {
                    flash('info', 'تم التفعيل (' . $modeLabel . ') لكن ' . whatsapp_fail_user_message($result));
                }
            } else {
                flash('success', 'تم التفعيل (' . $modeLabel . ')' . ($rentalFee > 0 ? ' مع إيجار ' . $rentalFee : ''));
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('error', 'فشل التفعيل: ' . $e->getMessage());
        }
        redirect('subscriptions.php');
    }
}

render_header(t('activate'), 'activate');
?>
<div class="panel">
    <h2><?php echo e(t('activate_new')); ?></h2>
    <p style="color:#6b7a88;margin:-6px 0 14px;font-weight:600">
        <?php echo e($lang === 'en'
            ? 'Choose subscriber, package, and Cash or Credit — or ledger import for remaining days only'
            : 'تفعيل جديد (نقد/آجل) — أو نقل من الدفتر للأيام الباقية فقط بدون فاتورة'); ?>
    </p>
    <form method="post" id="subForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">
        <div class="actions toggle-row" style="margin-top:0;margin-bottom:14px">
            <label class="toggle">
                <input type="checkbox" name="ledger_import" value="1" id="ledgerImportChk"<?php echo $preDays > 0 ? ' checked' : ''; ?>>
                <span class="toggle-ui"></span>
                <span class="toggle-text"><?php echo e($lang === 'en' ? 'Ledger import (days only, no invoice)' : 'نقل من الدفتر (أيام فقط — بدون فاتورة)'); ?></span>
            </label>
        </div>
        <div class="form-grid">
            <div>
                <label><?php echo e(t('subscribers')); ?></label>
                <select name="subscriber_id" required>
                    <option value="">...</option>
                    <?php foreach ($subscribers as $s): ?>
                        <option value="<?php echo (int) $s['id']; ?>"<?php echo ($preselectId === (int) $s['id']) ? ' selected' : ''; ?>>
                            <?php echo e($s['name'] . ' - ' . format_phone_display($s['phone'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?php echo e(t('package')); ?></label>
                <select name="plan_id" id="planSelect" required>
                    <option value="">...</option>
                    <?php foreach ($plans as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>">
                            <?php echo e($p['name'] . ' | ' . money_format_iqd($p['monthly_price'], $config['currency'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?php echo e(t('from_date')); ?></label>
                <input type="date" name="start_date" id="startDate" value="<?php echo e(date('Y-m-d')); ?>" required>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Remaining days (type manually)' : 'الأيام المتبقية (اكتبها يدوياً)'); ?></label>
                <input type="number" name="days_left" id="daysLeft" class="days-left-input" min="0" max="3650" step="1"
                    value="<?php echo e($defaultDaysLeft === '' ? '' : (string) (int) $defaultDaysLeft); ?>"
                    placeholder="<?php echo e($lang === 'en' ? 'e.g. 15' : 'مثال: 15'); ?>"
                    <?php echo $preDays > 0 ? 'required' : ''; ?>>
                <small class="field-hint"><?php echo e($lang === 'en'
                    ? ('Type days — end date updates from start. Empty = system default (' . ($periodMode === 'calendar_month' ? '1 calendar month' : '30 days') . ').')
                    : ('اكتب عدد الأيام — النهاية من تاريخ البداية. فاضي = الوضع من الإعدادات (' . ($periodMode === 'calendar_month' ? 'شهر ميلادي' : '30 يوم') . ').')); ?></small>
            </div>
            <div>
                <label><?php echo e(t('to_date')); ?></label>
                <input type="date" name="end_date" id="endDate" value="<?php echo e($defaultEnd); ?>" required>
            </div>
            <div>
                <label><?php echo e(t('message_note')); ?></label>
                <input name="message_note" placeholder="<?php echo e($lang === 'en' ? 'Optional' : 'اختياري'); ?>">
            </div>
        </div>

        <div id="payModeBox" class="pay-mode-box">
            <div class="pay-mode-label"><?php echo e(t('pay_mode')); ?></div>
            <div class="pay-mode-row">
                <label class="pay-mode-option">
                    <input type="radio" name="pay_mode" value="cash" checked>
                    <span class="pay-mode-card cash">
                        <strong><?php echo e(t('pay_cash')); ?></strong>
                        <small><?php echo e(t('pay_cash_hint')); ?></small>
                    </span>
                </label>
                <label class="pay-mode-option">
                    <input type="radio" name="pay_mode" value="credit">
                    <span class="pay-mode-card credit">
                        <strong><?php echo e(t('pay_credit')); ?></strong>
                        <small><?php echo e(t('pay_credit_hint')); ?></small>
                    </span>
                </label>
            </div>
        </div>

        <div class="actions toggle-row" id="waToggleRow">
            <label class="toggle">
                <input type="checkbox" name="send_whatsapp" value="1" id="sendWaChk" checked>
                <span class="toggle-ui"></span>
                <span class="toggle-text"><?php echo e(t('send_whatsapp')); ?></span>
            </label>
        </div>
        <div class="actions">
            <button class="btn" type="submit" id="activateSubmitBtn"><?php echo e(t('activate')); ?></button>
            <a class="btn ghost" href="subscriptions.php"><?php echo e(t('movements_list')); ?></a>
        </div>
    </form>
</div>
<script>
(function () {
  var start = document.getElementById('startDate');
  var end = document.getElementById('endDate');
  var days = document.getElementById('daysLeft');
  var ledger = document.getElementById('ledgerImportChk');
  var payBox = document.getElementById('payModeBox');
  var waRow = document.getElementById('waToggleRow');
  var waChk = document.getElementById('sendWaChk');
  var submitBtn = document.getElementById('activateSubmitBtn');
  var periodMode = <?php echo json_encode($periodMode); ?>;
  var syncing = false;
  function ymd(d) {
    var m = d.getMonth() + 1;
    var day = d.getDate();
    if (m < 10) m = '0' + m;
    if (day < 10) day = '0' + day;
    return d.getFullYear() + '-' + m + '-' + day;
  }
  function addDays(ymdStr, n) {
    var p = ymdStr.split('-');
    var d = new Date(parseInt(p[0],10), parseInt(p[1],10)-1, parseInt(p[2],10));
    d.setDate(d.getDate() + n);
    return ymd(d);
  }
  function addOneMonth(ymdStr) {
    var p = ymdStr.split('-');
    var y = parseInt(p[0], 10);
    var m = parseInt(p[1], 10) - 1;
    var day = parseInt(p[2], 10);
    var d = new Date(y, m, day);
    d.setMonth(d.getMonth() + 1);
    // PHP strtotime('+1 month') clamps overflow differently; keep simple month+1 same day when possible
    if (d.getDate() !== day) {
      // overflow (e.g. Jan 31) → last day of next month
      d = new Date(y, m + 2, 0);
    }
    return ymd(d);
  }
  function defaultEndFromStart(startYmd) {
    if (periodMode === 'calendar_month') return addOneMonth(startYmd);
    return addDays(startYmd, 30);
  }
  function daysBetween(a, b) {
    var pa = a.split('-');
    var pb = b.split('-');
    var da = new Date(parseInt(pa[0],10), parseInt(pa[1],10)-1, parseInt(pa[2],10));
    var db = new Date(parseInt(pb[0],10), parseInt(pb[1],10)-1, parseInt(pb[2],10));
    return Math.max(0, Math.round((db - da) / 86400000));
  }
  function fromDays() {
    if (syncing || !days || !end || !start || !start.value) return;
    var n = parseInt(days.value, 10);
    if (isNaN(n) || n < 0 || days.value === '') return;
    syncing = true;
    end.value = addDays(start.value, n);
    syncing = false;
  }
  function fromEnd() {
    if (syncing || !days || !end || !end.value || !start || !start.value) return;
    syncing = true;
    days.value = String(Math.max(0, daysBetween(start.value, end.value)));
    syncing = false;
  }
  function applyDefaultPeriod() {
    if (!start || !start.value || !end) return;
    if (days && days.value !== '') {
      fromDays();
      return;
    }
    syncing = true;
    end.value = defaultEndFromStart(start.value);
    syncing = false;
  }
  function syncLedgerUi() {
    var on = ledger && ledger.checked;
    if (payBox) payBox.style.display = on ? 'none' : '';
    if (waRow) waRow.style.display = on ? 'none' : '';
    if (waChk && on) waChk.checked = false;
    if (days) days.required = !!on;
    if (submitBtn) {
      submitBtn.textContent = on
        ? <?php echo json_encode($lang === 'en' ? 'Save days from ledger' : 'حفظ الأيام من الدفتر'); ?>
        : <?php echo json_encode(t('activate')); ?>;
    }
  }
  if (days) days.addEventListener('input', fromDays);
  if (days) days.addEventListener('change', fromDays);
  if (end) end.addEventListener('change', fromEnd);
  if (start) start.addEventListener('change', applyDefaultPeriod);
  if (ledger) {
    ledger.addEventListener('change', syncLedgerUi);
    syncLedgerUi();
  }
  if (days && days.value === '' && !(ledger && ledger.checked)) {
    try { days.focus(); } catch (e) {}
  }
})();
</script>
<?php render_footer(); ?>
