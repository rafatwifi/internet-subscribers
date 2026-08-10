<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$subscribers = $pdo->query(
    'SELECT s.id, s.name, s.phone,
        (SELECT DATEDIFF(sub.end_date, CURDATE())
         FROM subscriptions sub
         WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
         ORDER BY sub.end_date DESC LIMIT 1) AS days_left
     FROM subscribers s
     ORDER BY s.name'
)->fetchAll();
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
        $sendOldDebts = $sendWhatsapp && post('send_old_debts') === '1';
        $ledgerImport = post('ledger_import') === '1';
        $payMode = post('pay_mode') === 'credit' ? 'credit' : 'cash';

        if ($startDate === '') {
            $startDate = date('Y-m-d');
        }
        if ($subscriberId <= 0 || $planId <= 0 || $startDate === '') {
            flash('error', 'اختر المشترك والباقة وتاريخ البداية');
            redirect($subscriberId > 0 ? ('activate.php?subscriber_id=' . $subscriberId) : 'activate.php');
        }

        if ($ledgerImport) {
            if ($endDate === '' && $daysLeftPost < 0) {
                $endDate = subscription_period_end($startDate, $config);
            }
            $daysForLedger = ($daysLeftPost >= 0)
                ? $daysLeftPost
                : max(0, (int) ceil((strtotime($endDate) - strtotime(date('Y-m-d'))) / 86400));
            list($ok, $msg) = apply_subscriber_days_left($pdo, $subscriberId, $daysForLedger, $planId);
            flash($ok ? 'success' : 'error', $msg);
            redirect($ok ? 'subscribers.php' : ('activate.php?subscriber_id=' . $subscriberId . '&days=' . $daysForLedger));
        }

        list($ok, $msg, $details) = activate_one_subscriber($pdo, $config, $subscriberId, array(
            'plan_id' => $planId,
            'pay_mode' => $payMode,
            'send_whatsapp' => $sendWhatsapp,
            'send_old_debts' => $sendOldDebts,
            'message_note' => $msgNote,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'days_left' => ($daysRaw === '' ? null : $daysLeftPost),
            'carry_days' => true,
        ));
        if ($ok) {
            $waOk = is_array($details) && array_key_exists('whatsapp_ok', $details) ? $details['whatsapp_ok'] : null;
            if ($sendWhatsapp && $waOk === false) {
                flash('info', $msg);
            } else {
                flash('success', $msg);
            }
        } else {
            flash('error', $msg);
            redirect('activate.php?subscriber_id=' . $subscriberId);
        }
        redirect('subscribers.php');
    }
}

// بيانات المشترك المختار مسبقاً + باقته الحالية
$quickMode = ($preselectId > 0 && empty($_GET['advanced']));
$preselectSub = null;
$carryDaysUi = 0;
$currentPlanId = 0;
$currentPlan = null;
$lastServiceName = '';
$rentalFeeUi = 0.0;
$chargePreview = 0.0;
$hasRentUi = false;
if ($preselectId > 0) {
    foreach ($subscribers as $s) {
        if ((int) $s['id'] === $preselectId) {
            $preselectSub = $s;
            $carryDaysUi = isset($s['days_left']) ? (int) $s['days_left'] : 0;
            if ($carryDaysUi < 0) {
                $carryDaysUi = 0;
            }
            break;
        }
    }
    $lastSubSt = $pdo->prepare(
        'SELECT service_name, monthly_price FROM subscriptions
         WHERE subscriber_id = :id
         ORDER BY (status = "active") DESC, id DESC
         LIMIT 1'
    );
    $lastSubSt->execute(array(':id' => $preselectId));
    $lastSub = $lastSubSt->fetch();
    if ($lastSub) {
        $lastServiceName = (string) $lastSub['service_name'];
        $lastPrice = (float) $lastSub['monthly_price'];
        foreach ($plans as $p) {
            if (strcasecmp((string) $p['name'], $lastServiceName) === 0) {
                $currentPlan = $p;
                $currentPlanId = (int) $p['id'];
                break;
            }
        }
        if (!$currentPlan) {
            foreach ($plans as $p) {
                if ((float) $p['monthly_price'] === $lastPrice) {
                    $currentPlan = $p;
                    $currentPlanId = (int) $p['id'];
                    break;
                }
            }
        }
    }
    if (!$currentPlan && $plans) {
        $currentPlan = $plans[0];
        $currentPlanId = (int) $currentPlan['id'];
    }
    $subFullSt = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
    $subFullSt->execute(array(':id' => $preselectId));
    $subFull = $subFullSt->fetch();
    $settingsUi = settings_load();
    if ($subFull && subscriber_has_rental($subFull)) {
        $hasRentUi = true;
        $rentalFeeUi = (float) rental_fee_amount($settingsUi);
    }
    if ($currentPlan) {
        $chargePreview = (float) $currentPlan['monthly_price'] + $rentalFeeUi;
    }
}

$oldDebtsUi = array();
$oldDebtsSumUi = 0.0;
if ($quickMode && $preselectId > 0) {
    $odUi = $pdo->prepare(
        'SELECT month_label, amount, notes FROM invoices
         WHERE subscriber_id = :id AND status = "unpaid"
         ORDER BY due_date ASC, id ASC'
    );
    $odUi->execute(array(':id' => $preselectId));
    $oldDebtsUi = $odUi->fetchAll();
    foreach ($oldDebtsUi as $od) {
        $oldDebtsSumUi += (float) $od['amount'];
    }
}

if ($quickMode && $preselectSub):
    $backUrl = 'subscriber.php?id=' . (int) $preselectId;
    $advUrl = 'activate.php?subscriber_id=' . (int) $preselectId . '&advanced=1';
    $hasOldDebtsUi = count($oldDebtsUi) > 0;
    render_header(t('quick_activate'), 'activate');
?>
<div class="modal-backdrop quick-activate-wrap" id="quickActivateBox">
    <div class="modal-card quick-activate-card">
        <div class="quick-top">
            <div>
                <h3><?php echo e(t('quick_activate')); ?></h3>
                <p class="quick-sub-name"><?php echo e($preselectSub['name']); ?></p>
                <p class="meta quick-phone"><?php echo e(format_phone_display($preselectSub['phone'])); ?></p>
            </div>
            <a class="quick-adv-link" href="<?php echo e($advUrl); ?>" title="<?php echo e(t('advanced')); ?>">
                <span class="quick-adv-ico" aria-hidden="true">⚙</span>
                <span><?php echo e(t('advanced')); ?></span>
            </a>
        </div>
        <?php if ($carryDaysUi > 0): ?>
            <div class="carry-days-hint quick-carry">
                <strong>+<?php echo (int) $carryDaysUi; ?> <?php echo e($lang === 'en' ? 'extra days' : 'يوم إضافي'); ?></strong>
                — <?php echo e($lang === 'en' ? 'added on top of the new period' : 'تنضاف فوق مدة الاشتراك الجديد'); ?>
            </div>
        <?php endif; ?>

        <form method="post" id="quickActivateForm">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="subscriber_id" value="<?php echo (int) $preselectId; ?>">
            <input type="hidden" name="start_date" value="<?php echo e(date('Y-m-d')); ?>">
            <input type="hidden" name="end_date" id="quickEndDate" value="<?php echo e($defaultEnd); ?>">
            <input type="hidden" name="days_left" value="">

            <div class="quick-grid">
                <div class="quick-col">
                    <div class="quick-plan-line">
                        <span class="meta"><?php echo e(t('current_package')); ?></span>
                        <strong id="quickPlanLabel"><?php echo e($currentPlan ? $currentPlan['name'] : '—'); ?></strong>
                    </div>
                    <select name="plan_id" id="quickPlanSelect" required class="quick-plan-select">
                        <?php foreach ($plans as $p): ?>
                            <option value="<?php echo (int) $p['id']; ?>"
                                data-price="<?php echo (float) $p['monthly_price']; ?>"
                                <?php echo $currentPlanId === (int) $p['id'] ? ' selected' : ''; ?>>
                                <?php echo e($p['name'] . ' | ' . money_format_iqd($p['monthly_price'], $config['currency'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn ghost sm quick-details-btn" id="togglePlanDetails">
                        <?php echo e(t('show_details')); ?>
                    </button>

                    <div class="quick-amount-box">
                        <span><?php echo e(t('activate_amount')); ?></span>
                        <strong id="quickAmountLabel"><?php echo e(money_format_iqd($chargePreview, $config['currency'])); ?></strong>
                        <?php if ($hasRentUi): ?>
                            <small class="meta" id="quickRentNote"><?php echo e($lang === 'en' ? 'Includes rent' : 'يشمل الإيجار'); ?> (<?php echo e(money_format_iqd($rentalFeeUi, $config['currency'])); ?>)</small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="quick-col">
                    <div class="pay-mode-box">
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

                    <?php if ($hasOldDebtsUi): ?>
                    <div class="quick-old-debts">
                        <div class="quick-old-head">
                            <span><?php echo e(t('old_debts')); ?></span>
                            <strong><?php echo e(money_format_iqd($oldDebtsSumUi, $config['currency'])); ?></strong>
                        </div>
                        <ul class="quick-old-list">
                            <?php foreach ($oldDebtsUi as $od): ?>
                                <li>
                                    <span><?php echo e(month_short_label($od['month_label'])); ?><?php if (!empty($od['notes'])): ?> — <?php echo e($od['notes']); ?><?php endif; ?></span>
                                    <strong><?php echo e(money_format_iqd($od['amount'], $config['currency'])); ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="quick-old-total meta">
                            <?php echo e(t('grand_total')); ?>:
                            <strong id="quickGrandTotal"><?php echo e(money_format_iqd($oldDebtsSumUi + $chargePreview, $config['currency'])); ?></strong>
                            <small>(<?php echo e($lang === 'en' ? 'if credit / remaining if cash' : 'مع التفعيل إن كان آجل'); ?>)</small>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="quick-msg-box">
                        <label class="toggle">
                            <input type="checkbox" name="send_whatsapp" id="quickSendMsg" value="1" checked>
                            <span class="toggle-ui"></span>
                            <span class="toggle-text"><?php echo e(t('send_message')); ?></span>
                        </label>
                        <?php if ($hasOldDebtsUi): ?>
                        <div class="quick-nested-toggle" id="quickOldDebtsToggle">
                            <label class="toggle">
                                <input type="checkbox" name="send_old_debts" value="1" checked>
                                <span class="toggle-ui"></span>
                                <span class="toggle-text"><?php echo e(t('send_old_debts_detail')); ?></span>
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="quick-actions">
                <button class="btn quick-activate-btn" type="submit" onclick="return confirm(<?php echo json_encode(t('confirm_activate')); ?>);"><?php echo e(t('activate')); ?></button>
                <a class="btn ghost quick-cancel-btn" href="<?php echo e($backUrl); ?>"><?php echo e($lang === 'en' ? 'Cancel' : 'إلغاء'); ?></a>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
  var rentFee = <?php echo json_encode((float) $rentalFeeUi); ?>;
  var oldSum = <?php echo json_encode((float) $oldDebtsSumUi); ?>;
  var currency = <?php echo json_encode(isset($config['currency']) ? $config['currency'] : 'د.ع'); ?>;
  var planSel = document.getElementById('quickPlanSelect');
  var planLabel = document.getElementById('quickPlanLabel');
  var amountLabel = document.getElementById('quickAmountLabel');
  var grandLabel = document.getElementById('quickGrandTotal');
  var toggleBtn = document.getElementById('togglePlanDetails');
  var sendMsg = document.getElementById('quickSendMsg');
  var nested = document.getElementById('quickOldDebtsToggle');
  var showTxt = <?php echo json_encode(t('show_details')); ?>;
  var hideTxt = <?php echo json_encode(t('hide_details')); ?>;
  function money(n) {
    try { return Math.round(n).toLocaleString('en-US') + ' ' + currency; } catch (e) { return Math.round(n) + ' ' + currency; }
  }
  function syncMsgNest() {
    if (!nested || !sendMsg) return;
    var on = !!sendMsg.checked;
    nested.style.display = on ? '' : 'none';
    var inp = nested.querySelector('input[type="checkbox"]');
    if (!inp) return;
    inp.disabled = !on;
    if (!on) {
      inp.checked = false;
    } else if (!inp.dataset.userTouched) {
      inp.checked = true;
    }
  }
  function sync() {
    if (!planSel) return;
    var opt = planSel.options[planSel.selectedIndex];
    if (!opt) return;
    var price = parseFloat(opt.getAttribute('data-price') || '0') || 0;
    var total = price + (rentFee || 0);
    if (planLabel) {
      var name = (opt.textContent || '').split('|')[0];
      planLabel.textContent = name ? name.replace(/^\s+|\s+$/g, '') : opt.textContent;
    }
    if (amountLabel) amountLabel.textContent = money(total);
    if (grandLabel) grandLabel.textContent = money(oldSum + total);
  }
  if (planSel) {
    planSel.classList.add('is-collapsed');
    planSel.addEventListener('change', sync);
    sync();
  }
  if (toggleBtn && planSel) {
    toggleBtn.addEventListener('click', function () {
      var open = planSel.classList.toggle('is-open');
      planSel.classList.toggle('is-collapsed', !open);
      toggleBtn.textContent = open ? hideTxt : showTxt;
    });
  }
  if (sendMsg) {
    sendMsg.addEventListener('change', syncMsgNest);
    syncMsgNest();
  }
  if (nested) {
    var nestedInp = nested.querySelector('input[type="checkbox"]');
    if (nestedInp) {
      nestedInp.addEventListener('change', function () { nestedInp.dataset.userTouched = '1'; });
    }
  }
})();
</script>
<?php
render_footer();
return;
endif;

render_header(t('activate'), 'activate');
?>
<div class="panel">
    <h2><?php echo e(t('activate_new')); ?></h2>
    <p style="color:#6b7a88;margin:-6px 0 14px;font-weight:600">
        <?php echo e($lang === 'en'
            ? 'Choose subscriber, package, and Cash or Credit — or ledger import for remaining days only'
            : 'تفعيل جديد (نقد/آجل) — أو نقل من الدفتر للأيام الباقية فقط بدون فاتورة'); ?>
    </p>
    <?php if ($preselectId > 0): ?>
    <div class="actions" style="margin-top:0;margin-bottom:10px">
        <a class="btn secondary sm" href="activate.php?subscriber_id=<?php echo (int) $preselectId; ?>"><?php echo e($lang === 'en' ? 'Back to quick' : 'رجوع للتفعيل السريع'); ?></a>
    </div>
    <?php endif; ?>
    <form method="post" id="subForm" onsubmit="return confirm(<?php echo json_encode(t('confirm_activate')); ?>);">
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
                <select name="subscriber_id" id="subscriberSelect" required>
                    <option value="">...</option>
                    <?php foreach ($subscribers as $s): ?>
                        <?php $dLeft = isset($s['days_left']) ? (int) $s['days_left'] : 0; if ($dLeft < 0) { $dLeft = 0; } ?>
                        <option value="<?php echo (int) $s['id']; ?>"
                            data-days-left="<?php echo $dLeft; ?>"
                            <?php echo ($preselectId === (int) $s['id']) ? ' selected' : ''; ?>>
                            <?php echo e($s['name'] . ' - ' . format_phone_display($s['phone'])); ?>
                            <?php if ($dLeft > 0): ?> (+<?php echo $dLeft; ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div id="carryDaysHint" class="carry-days-hint" hidden></div>
            </div>
            <div>
                <label><?php echo e(t('package')); ?></label>
                <select name="plan_id" id="planSelect" required>
                    <option value="">...</option>
                    <?php foreach ($plans as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>"<?php echo $currentPlanId === (int) $p['id'] ? ' selected' : ''; ?>>
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
                <small class="field-hint" id="carryEndNote" hidden></small>
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
            <a class="btn ghost" href="subscriptions.php<?php echo $preselectId > 0 ? ('?subscriber_id=' . (int) $preselectId) : ''; ?>" id="movementsLink"><?php echo e(t('movements_list')); ?></a>
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
  var subSelect = document.getElementById('subscriberSelect');
  var carryHint = document.getElementById('carryDaysHint');
  var movLink = document.getElementById('movementsLink');
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
  function carryDays() {
    if (!subSelect) return 0;
    var opt = subSelect.options[subSelect.selectedIndex];
    if (!opt) return 0;
    var n = parseInt(opt.getAttribute('data-days-left') || '0', 10);
    return (isNaN(n) || n < 0) ? 0 : n;
  }
  var carryEndNote = document.getElementById('carryEndNote');
  function syncCarryUi() {
    var n = carryDays();
    if (carryHint) {
      if (n > 0) {
        carryHint.hidden = false;
        carryHint.innerHTML = <?php echo json_encode($lang === 'en'
            ? '<strong>+{n} extra days</strong> — still left on current sub; will be added on top of the new period'
            : '<strong>+{n} يوم إضافي</strong> — باقية من الاشتراك الحالي، وتنضاف فوق مدة الاشتراك الجديد'); ?>
          .replace(/\{n\}/g, String(n));
      } else {
        carryHint.hidden = true;
        carryHint.innerHTML = '';
      }
    }
    if (carryEndNote) {
      if (n > 0) {
        carryEndNote.hidden = false;
        carryEndNote.textContent = <?php echo json_encode($lang === 'en'
            ? 'End date already includes +{n} carry days from the old subscription.'
            : 'تاريخ النهاية فيه أصلاً +{n} يوم مرحّلة من الاشتراك القديم.'); ?>
          .replace(/\{n\}/g, String(n));
      } else {
        carryEndNote.hidden = true;
        carryEndNote.textContent = '';
      }
    }
    if (movLink && subSelect && subSelect.value) {
      movLink.href = 'subscriptions.php?subscriber_id=' + encodeURIComponent(subSelect.value);
    } else if (movLink) {
      movLink.href = 'subscriptions.php';
    }
  }
  function fromDays() {
    if (syncing || !days || !end || !start || !start.value) return;
    var n = parseInt(days.value, 10);
    if (isNaN(n) || n < 0 || days.value === '') return;
    syncing = true;
    end.value = addDays(addDays(start.value, n), carryDays());
    syncing = false;
  }
  function fromEnd() {
    if (syncing || !days || !end || !end.value || !start || !start.value) return;
    syncing = true;
    var total = Math.max(0, daysBetween(start.value, end.value));
    var c = carryDays();
    days.value = String(Math.max(0, total - c));
    syncing = false;
  }
  function applyDefaultPeriod() {
    if (!start || !start.value || !end) return;
    if (days && days.value !== '') {
      fromDays();
      return;
    }
    syncing = true;
    end.value = addDays(defaultEndFromStart(start.value), carryDays());
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
  if (subSelect) {
    subSelect.addEventListener('change', function () {
      syncCarryUi();
      applyDefaultPeriod();
    });
  }
  if (ledger) {
    ledger.addEventListener('change', syncLedgerUi);
    syncLedgerUi();
  }
  syncCarryUi();
  if (subSelect && subSelect.value) applyDefaultPeriod();
  if (days && days.value === '' && !(ledger && ledger.checked)) {
    try { days.focus(); } catch (e) {}
  }
})();
</script>
<?php render_footer(); ?>
