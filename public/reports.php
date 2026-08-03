<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('reports');

try {
    if (function_exists('archive_closed_months')) {
        archive_closed_months($pdo);
    }
} catch (Exception $e) {
} catch (Throwable $e) {
}

$month = isset($_GET['month']) ? trim((string) $_GET['month']) : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$isCurrentMonth = ($month === date('Y-m'));

$archiveRow = null;
try {
    if (function_exists('get_month_archive')) {
        $archiveRow = get_month_archive($pdo, $month);
    }
} catch (Exception $e) {
} catch (Throwable $e) {
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $details = $pdo->prepare(
            "SELECT i.*, s.name, s.phone
             FROM invoices i
             JOIN subscribers s ON s.id = i.subscriber_id
             WHERE i.status = 'paid' AND DATE_FORMAT(i.paid_at, '%Y-%m') = :m
             ORDER BY i.paid_at DESC"
        );
        $details->execute(array(':m' => $month));
        $paidRows = $details->fetchAll();
        $rows = array();
        foreach ($paidRows as $row) {
            $rows[] = array(
                $row['name'],
                format_phone_display($row['phone']),
                $row['amount'],
                isset($row['cost_price']) ? $row['cost_price'] : 0,
                isset($row['profit']) ? $row['profit'] : 0,
                $row['paid_at'],
            );
        }
        export_csv(
            'report-' . $month . '.csv',
            array('Name', 'Phone', 'Amount', 'Cost', 'Profit', 'Paid at'),
            $rows
        );
    } catch (Exception $e) {
        flash('error', 'Export failed');
        redirect('reports.php?month=' . urlencode($month));
    }
}

$activated = 0;
$sales = 0.0;
$received = 0.0;
$profit = 0.0;
$cost = 0.0;
$monthDebt = 0.0;
$fromArchive = false;

if (!$isCurrentMonth && $archiveRow) {
    $activated = (int) $archiveRow['activations'];
    $sales = (float) $archiveRow['sales'];
    $received = (float) $archiveRow['collected'];
    $profit = (float) $archiveRow['profit'];
    $cost = (float) $archiveRow['cost'];
    $monthDebt = (float) $archiveRow['debt'];
    $fromArchive = true;
} else {
    try {
        if (function_exists('compute_month_stats')) {
            $stats = compute_month_stats($pdo, $month);
            $activated = $stats['activations'];
            $sales = $stats['sales'];
            $received = $stats['collected'];
            $profit = $stats['profit'];
            $cost = $stats['cost'];
            $monthDebt = $stats['debt'];
        } else {
            $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(monthly_price),0) FROM subscriptions WHERE DATE_FORMAT(created_at, '%Y-%m') = :m");
            $st->execute(array(':m' => $month));
            $row = $st->fetch(PDO::FETCH_NUM);
            $activated = (int) $row[0];
            $sales = (float) $row[1];
            $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status = 'paid' AND DATE_FORMAT(paid_at, '%Y-%m') = :m");
            $st->execute(array(':m' => $month));
            $received = (float) $st->fetchColumn();
        }
    } catch (Exception $e) {
    } catch (Throwable $e) {
    }
}

$paidRows = array();
$activationRows = array();
$archives = array();

try {
    $details = $pdo->prepare(
        "SELECT i.*, s.name, s.phone
         FROM invoices i
         JOIN subscribers s ON s.id = i.subscriber_id
         WHERE i.status = 'paid' AND DATE_FORMAT(i.paid_at, '%Y-%m') = :m
         ORDER BY i.paid_at DESC"
    );
    $details->execute(array(':m' => $month));
    $paidRows = $details->fetchAll();
} catch (Exception $e) {
} catch (Throwable $e) {
}

try {
    $salesRows = $pdo->prepare(
        "SELECT sub.*, s.name, s.phone
         FROM subscriptions sub
         JOIN subscribers s ON s.id = sub.subscriber_id
         WHERE DATE_FORMAT(sub.created_at, '%Y-%m') = :m
         ORDER BY sub.id DESC"
    );
    $salesRows->execute(array(':m' => $month));
    $activationRows = $salesRows->fetchAll();
} catch (Exception $e) {
} catch (Throwable $e) {
}

try {
    if (function_exists('list_monthly_archives')) {
        $archives = list_monthly_archives($pdo);
    }
} catch (Exception $e) {
} catch (Throwable $e) {
}

$curStats = array(
    'activations' => 0,
    'sales' => 0.0,
    'collected' => 0.0,
    'profit' => 0.0,
);
try {
    if (function_exists('compute_month_stats')) {
        $curStats = compute_month_stats($pdo, date('Y-m'));
    }
} catch (Exception $e) {
} catch (Throwable $e) {
}

render_header(t('reports'), 'reports');
?>
<div class="panel no-print panel-compact">
    <form method="get" class="actions" style="margin-top:0">
        <label style="margin:0"><?php echo e(t('choose_month')); ?></label>
        <input type="month" name="month" value="<?php echo e($month); ?>" style="max-width:220px">
        <button class="btn" type="submit"><?php echo e(t('show')); ?></button>
        <a class="btn ghost" href="?month=<?php echo e(urlencode($month)); ?>&export=csv"><?php echo e(t('export')); ?></a>
        <button class="btn secondary" type="button" onclick="window.print()"><?php echo e(t('print')); ?></button>
        <?php if ($fromArchive): ?>
            <span class="badge expired"><?php echo e($lang === 'en' ? 'Archived month' : 'شهر مؤرشف'); ?></span>
        <?php elseif ($isCurrentMonth): ?>
            <span class="badge active"><?php echo e($lang === 'en' ? 'Current month (live)' : 'الشهر الحالي (حي)'); ?></span>
        <?php endif; ?>
    </form>
</div>

<div class="cards">
    <div class="card-stat blue">
        <div class="label"><?php echo e(t('activations_month')); ?></div>
        <div class="value"><?php echo (int) $activated; ?></div>
    </div>
    <div class="card-stat purple">
        <div class="label"><?php echo e(t('activation_sales')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($sales, $config['currency'])); ?></div>
    </div>
    <div class="card-stat orange">
        <div class="label"><?php echo e(t('collected')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($received, $config['currency'])); ?></div>
    </div>
    <div class="card-stat red">
        <div class="label"><?php echo e(t('debts_total')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($monthDebt, $config['currency'])); ?></div>
    </div>
    <div class="card-stat cyan">
        <div class="label"><?php echo e(t('cost_price')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($cost, $config['currency'])); ?></div>
    </div>
    <div class="card-stat green">
        <div class="label"><?php echo e(t('profit')); ?></div>
        <div class="value"><?php echo e(money_format_iqd($profit, $config['currency'])); ?></div>
    </div>
</div>

<div class="panel panel-compact">
    <h2><?php echo e($lang === 'en' ? 'Sales (activations)' : 'المبيعات (التفعيلات)'); ?> — <?php echo e(month_short_label($month)); ?></h2>
    <div class="table-wrap">
        <table class="table-compact">
            <thead>
            <tr>
                <th><?php echo e(t('name')); ?></th>
                <th><?php echo e(t('package')); ?></th>
                <th><?php echo e(t('sell_price')); ?></th>
                <th><?php echo e(t('from_date')); ?></th>
                <th><?php echo e(t('to_date')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Created' : 'التسجيل'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$activationRows): ?>
                <tr><td colspan="6"><?php echo e($lang === 'en' ? 'No activations this month' : 'ماكو تفعيلات بهذا الشهر'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($activationRows as $row): ?>
                <tr>
                    <td>
                        <a href="subscriber.php?id=<?php echo (int) $row['subscriber_id']; ?>"><?php echo e($row['name']); ?></a>
                        <br><small><?php echo e(format_phone_display($row['phone'])); ?></small>
                    </td>
                    <td><?php echo e($row['service_name']); ?></td>
                    <td><?php echo e(money_format_iqd($row['monthly_price'], $config['currency'])); ?></td>
                    <td><?php echo e($row['start_date']); ?></td>
                    <td><?php echo e($row['end_date']); ?></td>
                    <td><?php echo e($row['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel panel-compact">
    <h2><?php echo e(t('collected')); ?> — <?php echo e(month_short_label($month)); ?></h2>
    <div class="table-wrap">
        <table class="table-compact">
            <thead>
            <tr>
                <th><?php echo e(t('name')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Amount' : 'المبلغ'); ?></th>
                <th><?php echo e(t('cost_price')); ?></th>
                <th><?php echo e(t('profit')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Paid at' : 'تاريخ التسديد'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$paidRows): ?>
                <tr><td colspan="5"><?php echo e($lang === 'en' ? 'No payments this month' : 'لا توجد تسديدات بهذا الشهر'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($paidRows as $row): ?>
                <tr>
                    <td><?php echo e($row['name']); ?><br><small><?php echo e(format_phone_display($row['phone'])); ?></small></td>
                    <td><?php echo e(money_format_iqd($row['amount'], $config['currency'])); ?></td>
                    <td><?php echo e(money_format_iqd(isset($row['cost_price']) ? $row['cost_price'] : 0, $config['currency'])); ?></td>
                    <td><?php echo e(money_format_iqd(isset($row['profit']) ? $row['profit'] : 0, $config['currency'])); ?></td>
                    <td><?php echo e($row['paid_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel panel-compact">
    <h2><?php echo e($lang === 'en' ? 'Monthly archive' : 'أرشيف الأشهر'); ?></h2>
    <p class="meta" style="margin-top:-4px">
        <?php echo e($lang === 'en'
            ? 'When a month ends, totals are frozen here for reports.'
            : 'من يخلص الشهر، أرقامه تنحفظ هنا وتظهر بالتقارير.'); ?>
    </p>
    <div class="table-wrap">
        <table class="table-compact">
            <thead>
            <tr>
                <th><?php echo e($lang === 'en' ? 'Month' : 'الشهر'); ?></th>
                <th><?php echo e(t('activations_month')); ?></th>
                <th><?php echo e(t('activation_sales')); ?></th>
                <th><?php echo e(t('collected')); ?></th>
                <th><?php echo e(t('profit')); ?></th>
                <th><?php echo e($lang === 'en' ? 'Archived at' : 'تاريخ الأرشفة'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><strong><?php echo e(month_short_label(date('Y-m'))); ?></strong>
                    <span class="badge active"><?php echo e($lang === 'en' ? 'Live' : 'حي'); ?></span>
                </td>
                <td><?php echo (int) $curStats['activations']; ?></td>
                <td><?php echo e(money_format_iqd($curStats['sales'], $config['currency'])); ?></td>
                <td><?php echo e(money_format_iqd($curStats['collected'], $config['currency'])); ?></td>
                <td><?php echo e(money_format_iqd($curStats['profit'], $config['currency'])); ?></td>
                <td>—</td>
                <td><a class="link-act act-blue" href="?month=<?php echo e(urlencode(date('Y-m'))); ?>"><?php echo e(t('show')); ?></a></td>
            </tr>
            <?php if (!$archives): ?>
                <tr><td colspan="7"><?php echo e($lang === 'en' ? 'No archived months yet' : 'ماكو أشهر مؤرشفة بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($archives as $ar): ?>
                <tr>
                    <td><?php echo e(month_short_label($ar['year_month'])); ?></td>
                    <td><?php echo (int) $ar['activations']; ?></td>
                    <td><?php echo e(money_format_iqd($ar['sales'], $config['currency'])); ?></td>
                    <td><?php echo e(money_format_iqd($ar['collected'], $config['currency'])); ?></td>
                    <td><?php echo e(money_format_iqd($ar['profit'], $config['currency'])); ?></td>
                    <td><?php echo e($ar['archived_at']); ?></td>
                    <td><a class="link-act act-blue" href="?month=<?php echo e(urlencode($ar['year_month'])); ?>"><?php echo e(t('show')); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
