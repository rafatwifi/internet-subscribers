<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$pdo->exec("UPDATE subscriptions SET status = 'expired' WHERE status = 'active' AND end_date < CURDATE()");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $lang === 'en' ? 'Invalid request' : 'طلب غير صالح');
        redirect('rentals.php');
    }
    $action = post('action');
    $id = (int) post('id', '0');
    if ($id > 0 && ($action === 'msg_rental_return' || $action === 'msg_rental_renew_hint')) {
        $st = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $st->execute(array(':id' => $id));
        $sub = $st->fetch();
        if (!$sub || !subscriber_has_rental($sub)) {
            flash('error', $lang === 'en' ? 'No rental device' : 'ماكو جهاز إيجار');
            redirect('rentals.php');
        }
        if ($action === 'msg_rental_return') {
            $msg = rental_return_message($sub, $config);
            $result = whatsapp_send($config, $sub['phone'], $msg, 'rental_return');
            log_message($pdo, $id, $result);
            activity_log($pdo, $id, 'subscriber', $id, 'rental_return', 'تبليغ استرجاع برج/جهاز', '');
            flash(
                !empty($result['success']) ? 'success' : 'error',
                !empty($result['success'])
                    ? ($lang === 'en' ? 'Return notice sent' : 'تم إرسال تبليغ الاسترجاع')
                    : whatsapp_fail_user_message($result)
            );
        }
        $backQ = trim((string) post('q', ''));
        redirect('rentals.php' . ($backQ !== '' ? ('?q=' . urlencode($backQ)) : ''));
    }
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$params = array();
$where = '(s.rental_enabled = 1 OR s.rental_enabled = "1")
 AND s.rental_device_id IS NOT NULL
 AND TRIM(s.rental_device_id) <> ""';
if ($q !== '') {
    $where .= ' AND (s.name LIKE :q OR s.phone LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$sql = 'SELECT s.*,
  (SELECT COALESCE(SUM(amount),0) FROM invoices i WHERE i.subscriber_id = s.id AND i.status = "unpaid") AS debt,
  (SELECT sub.end_date FROM subscriptions sub WHERE sub.subscriber_id = s.id ORDER BY sub.id DESC LIMIT 1) AS last_end,
  (SELECT sub.end_date FROM subscriptions sub
      WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
      ORDER BY sub.id DESC LIMIT 1) AS active_end,
  CASE WHEN EXISTS (
     SELECT 1 FROM subscriptions sub
     WHERE sub.subscriber_id = s.id AND sub.status = "active" AND sub.end_date >= CURDATE()
   ) THEN 1 ELSE 0 END AS is_rent_active
 FROM subscribers s
 WHERE ' . $where . '
 ORDER BY is_rent_active ASC, s.name ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$settingsRental = settings_load();
$fee = rental_fee_amount($settingsRental);
$totalRent = count($rows);
$activeRent = 0;
foreach ($rows as $rChk) {
    if (!empty($rChk['is_rent_active'])) {
        $activeRent++;
    }
}

render_header($lang === 'en' ? 'Rentals' : 'الإيجار', 'rentals');
?>
<style>
#rentalsTable.table-compact th,
#rentalsTable.table-compact td {
  padding: 7px 8px !important;
  font-size: 13px !important;
  line-height: 1.3 !important;
  height: 38px !important;
  white-space: nowrap;
  vertical-align: middle !important;
  border-bottom: 1px solid rgba(28, 36, 48, 0.08);
}
#rentalsTable.table-compact th {
  height: 30px !important;
  font-size: 12px !important;
  font-weight: 800 !important;
  color: #334155;
  background: rgba(28, 36, 48, 0.04);
}
#rentalsTable .sub-name {
  font-weight: 800;
  font-size: 13px;
  color: #1c4fd8;
  text-decoration: none;
}
#rentalsTable .sub-name:hover { text-decoration: underline; }
#rentalsTable .seq-col {
  width: 36px;
  text-align: center;
  font-weight: 800;
  color: #475569;
}
#rentalsTable tbody .seq-col {
  background: transparent !important;
  border-radius: 0;
}
#rentalsTable tbody tr.rent-row-on:nth-child(even) td {
  background: rgba(28, 36, 48, 0.035);
}
#rentalsTable tbody tr.rent-row-on:nth-child(odd) td {
  background: transparent;
}
#rentalsTable tbody tr.rent-row-off td,
#rentalsTable tbody tr.rent-ended-alert td {
  background: rgba(255, 59, 48, 0.12) !important;
  font-weight: 700;
}
#rentalsTable tbody tr.rent-ended-alert {
  box-shadow: inset 3px 0 0 #e11d48;
}
#rentalsTable tbody tr.rent-ended-alert .sub-name { color: #b71c1c; }
#rentalsTable .badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 800;
}
#rentalsTable .row-actions {
  display: inline-flex !important;
  flex-wrap: nowrap !important;
  gap: 8px !important;
  align-items: center;
}
#rentalsTable .link-act {
  font-size: 12px;
  font-weight: 800;
  text-decoration: none;
  white-space: nowrap;
}
#rentalsTable .debt-val {
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}
#rentalsTable .debt-val.has-debt { color: #c27800; }
#rentalsTable .rent-badge {
  margin-inline-start: 6px;
  vertical-align: middle;
}
</style>
<div class="panel glass-panel" id="rentalsPrintArea">
    <div class="rental-print-head">
        <div>
            <h2><?php echo e($lang === 'en' ? 'Rental towers' : 'أبراج الإيجار'); ?>
                <span style="font-weight:800;margin-right:8px"><?php echo (int) $totalRent; ?>\<?php echo (int) $activeRent; ?></span>
            </h2>
            <p class="meta" style="margin-top:-4px;font-weight:600">
                <?php echo e($lang === 'en'
                    ? 'All subscribers with a rental device — active or left. Device is still with them.'
                    : 'كل المشتركين عندهم جهاز إيجار — مستمرين أو تاركين الاشتراك، والغرض باقي بذمّتهم.'); ?>
                —
                <?php echo e($lang === 'en' ? 'Monthly rent' : 'إيجار شهري'); ?>:
                <?php echo e(money_format_iqd($fee, $config['currency'])); ?>
            </p>
        </div>
        <div class="actions no-print" style="margin:0">
            <button type="button" class="btn secondary sm" onclick="window.print()"><?php echo e($lang === 'en' ? 'Print' : 'طباعة'); ?></button>
        </div>
    </div>
    <div class="actions no-print" style="margin-top:0;margin-bottom:12px">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <input name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e($lang === 'en' ? 'Search name or phone…' : 'بحث بالاسم أو الرقم…'); ?>" style="max-width:280px">
            <button class="btn secondary sm" type="submit"><?php echo e($lang === 'en' ? 'Search' : 'بحث'); ?></button>
            <?php if ($q !== ''): ?>
                <a class="btn ghost sm" href="rentals.php"><?php echo e(t('show_all')); ?></a>
            <?php endif; ?>
        </form>
    </div>
    <div class="table-wrap">
        <table id="rentalsTable" class="table-compact rental-seq-table">
            <thead>
            <tr>
                <th class="seq-col">#</th>
                <th><?php echo e(t('name')); ?></th>
                <th><?php echo e(t('phone')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Device' : 'الجهاز'); ?></th>
                <th><?php echo e($lang === 'en' ? 'Status' : 'الحالة'); ?></th>
                <th><?php echo e($lang === 'en' ? 'End date' : 'الانتهاء'); ?></th>
                <th><?php echo e(t('debts_total')); ?></th>
                <th class="no-print"></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8"><?php echo e($lang === 'en' ? 'No rental subscribers' : 'ماكو مشتركين بإيجار'); ?></td></tr>
            <?php endif; ?>
            <?php $n = 1; foreach ($rows as $row):
                $dev = rental_device_by_id($row['rental_device_id'], $settingsRental);
                $isActive = !empty($row['is_rent_active']) || !empty($row['active_end']);
                $endShow = !empty($row['active_end']) ? $row['active_end'] : (!empty($row['last_end']) ? $row['last_end'] : '-');
                $debtAmt = (float) $row['debt'];
                ?>
                <tr class="<?php echo $isActive ? 'rent-row-on' : 'rent-row-off rent-ended-alert'; ?>">
                    <td class="seq-col"><?php echo $n++; ?></td>
                    <td>
                        <a class="sub-name" href="subscriber.php?id=<?php echo (int) $row['id']; ?>">
                            <?php echo e($row['name']); ?>
                        </a>
                        <?php echo rental_badge_html($row, $settingsRental); ?>
                    </td>
                    <td><?php echo e(format_phone_display($row['phone'])); ?></td>
                    <td><?php echo e($dev ? $dev['name'] : $row['rental_device_id']); ?></td>
                    <td>
                        <?php if ($isActive): ?>
                            <span class="badge active"><?php echo e($lang === 'en' ? 'Active' : 'مستمر'); ?></span>
                        <?php else: ?>
                            <span class="badge expired"><?php echo e($lang === 'en' ? 'Ended — return / renew' : 'منتهي — استرجاع أو تجديد'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($endShow); ?></td>
                    <td>
                        <span class="debt-val<?php echo $debtAmt > 0 ? ' has-debt' : ''; ?>">
                            <?php echo e(money_format_iqd($debtAmt, $config['currency'])); ?>
                        </span>
                    </td>
                    <td class="no-print">
                        <div class="row-actions">
                            <a class="link-act act-blue" href="subscriber.php?id=<?php echo (int) $row['id']; ?>#rental"><?php echo e($lang === 'en' ? 'Open' : 'فتح'); ?></a>
                            <?php if (!$isActive): ?>
                                <a class="link-act act-green" href="activate.php?subscriber_id=<?php echo (int) $row['id']; ?>"><?php echo e($lang === 'en' ? 'Renew' : 'تجديد الاشتراك'); ?></a>
                                <form method="post" style="display:inline" onsubmit="return confirm(<?php echo json_encode($lang === 'en' ? 'Send return notice via WhatsApp?' : 'تبليغ استرجاع عبر واتساب؟'); ?>);">
                                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="msg_rental_return">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <?php if ($q !== ''): ?>
                                        <input type="hidden" name="q" value="<?php echo e($q); ?>">
                                    <?php endif; ?>
                                    <button class="link-act act-orange" type="submit" style="border:0;background:none;cursor:pointer;padding:0;font:inherit"><?php echo e($lang === 'en' ? 'Return notice' : 'تبليغ استرجاع'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
