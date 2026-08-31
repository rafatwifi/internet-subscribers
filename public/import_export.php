<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('subscribers');

$canReports = function_exists('user_can') ? user_can('reports') : true;
$canBackup = function_exists('user_can') ? user_can('backup') : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect('import_export.php');
    }

    $action = post('action');

    if ($action === 'import_subscribers') {
        if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            flash('error', 'اختر ملف CSV');
            redirect('import_export.php');
        }
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) {
            flash('error', 'تعذر قراءة الملف');
            redirect('import_export.php');
        }
        $added = 0;
        $skipped = 0;
        $first = true;
        while (($data = fgetcsv($handle)) !== false) {
            if ($first) {
                $first = false;
                if (isset($data[0]) && (strpos(strtolower($data[0]), 'name') !== false || strpos($data[0], 'اسم') !== false)) {
                    continue;
                }
            }
            if (count($data) < 2) {
                $skipped++;
                continue;
            }
            $name = normalize_subscriber_name((string) $data[0]);
            $phone = normalize_phone(trim((string) $data[1]));
            $address = isset($data[2]) ? trim((string) $data[2]) : '';
            $notes = isset($data[3]) ? trim((string) $data[3]) : '';
            if ($name === '' || $phone === '') {
                $skipped++;
                continue;
            }
            if (subscriber_name_taken($pdo, $name)) {
                $skipped++;
                continue;
            }
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO subscribers (name, phone, address, notes) VALUES (:name, :phone, :address, :notes)'
                );
                $stmt->execute(array(
                    ':name' => $name,
                    ':phone' => $phone,
                    ':address' => ($address !== '') ? $address : null,
                    ':notes' => ($notes !== '') ? $notes : null,
                ));
                $added++;
            } catch (PDOException $e) {
                $skipped++;
            }
        }
        fclose($handle);
        flash('success', 'تم استيراد ' . $added . ' مشترك' . ($skipped > 0 ? ' (تخطي ' . $skipped . ')' : ''));
        redirect('import_export.php');
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'subscribers') {
    if (function_exists('export_offline_subscribers_full')) {
        export_offline_subscribers_full($pdo);
    }
    flash('error', 'التصدير غير متوفر');
    redirect('import_export.php');
}

if ($canReports && isset($_GET['export']) && $_GET['export'] === 'report') {
    $month = isset($_GET['month']) ? trim((string) $_GET['month']) : date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
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
            array('الاسم', 'الهاتف', 'المبلغ', 'التكلفة', 'الربح', 'تاريخ التسديد'),
            $rows
        );
    } catch (Exception $e) {
        flash('error', 'فشل تصدير التقرير');
        redirect('import_export.php');
    }
}

$pageTitle = $lang === 'en' ? 'Import & Export' : 'استيراد وتصدير';
render_header($pageTitle, 'import_export', $lang === 'en' ? 'CSV import/export tools' : 'أدوات الاستيراد والتصدير');
?>
<div class="panel">
    <h2><?php echo e($lang === 'en' ? 'Import subscribers' : 'استيراد مشتركين'); ?></h2>
    <p class="meta" style="margin:0 0 14px">
        <?php echo e($lang === 'en'
            ? 'CSV columns: name, phone, address, notes. Header row is skipped automatically.'
            : 'أعمدة الملف: الاسم، الهاتف، العنوان، الملاحظات. سطر العناوين يُتخطّى تلقائياً.'); ?>
    </p>
    <form method="post" enctype="multipart/form-data" id="importSubsForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="import_subscribers">
        <label class="file-pick" for="csvFileInput">
            <input type="file" name="csv_file" id="csvFileInput" accept=".csv,text/csv" required>
            <span class="file-pick-ui">
                <strong class="file-pick-title"><?php echo e($lang === 'en' ? 'Choose CSV file' : 'اختر ملف CSV'); ?></strong>
                <span class="file-pick-name" id="csvFileName"><?php echo e($lang === 'en' ? 'No file selected' : 'ما محدد ملف'); ?></span>
                <span class="file-pick-hint">.csv</span>
            </span>
        </label>
        <div class="actions" style="margin-top:14px">
            <button class="btn" type="submit"><?php echo e(t('import')); ?></button>
        </div>
    </form>
</div>

<div class="panel">
    <h2><?php echo e($lang === 'en' ? 'Export local debts & subscriptions' : 'تصدير الديون والاشتراكات المحلية'); ?></h2>
    <p class="meta" style="margin:0 0 14px">
        <?php echo e($lang === 'en'
            ? 'Backup of local invoices/debts, subscriptions, WhatsApp logs, and activity.'
            : 'نسخة من الفواتير/الديون والاشتراكات المحلية وسجل الرسائل والحركات.'); ?>
    </p>
    <div class="actions" style="margin-top:0">
        <a class="btn secondary" href="import_export.php?export=subscribers"><?php echo e($lang === 'en' ? 'Download full backup' : 'تنزيل النسخة الكاملة'); ?></a>
    </div>
</div>

<?php if ($canReports): ?>
<div class="panel">
    <h2><?php echo e($lang === 'en' ? 'Export paid report' : 'تصدير تقرير التسديدات'); ?></h2>
    <p class="meta" style="margin:0 0 14px">
        <?php echo e($lang === 'en'
            ? 'Export paid invoices for a selected month.'
            : 'تصدير فواتير المسدَّد لشهر محدد.'); ?>
    </p>
    <form method="get" action="import_export.php">
        <input type="hidden" name="export" value="report">
        <div class="form-grid" style="max-width:420px">
            <div>
                <label><?php echo e($lang === 'en' ? 'Month' : 'الشهر'); ?></label>
                <?php echo month_ym_picker_html('month', date('Y-m')); ?>
            </div>
        </div>
        <div class="actions" style="margin-top:14px">
            <button class="btn secondary" type="submit"><?php echo e($lang === 'en' ? 'Download report' : 'تنزيل التقرير'); ?></button>
            <a class="btn ghost" href="reports.php"><?php echo e(t('reports')); ?></a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($canBackup): ?>
<div class="panel">
    <h2><?php echo e($lang === 'en' ? 'Full database backup' : 'نسخة احتياطية كاملة'); ?></h2>
    <p class="meta" style="margin:0 0 14px">
        <?php echo e($lang === 'en'
            ? 'SQL backup / restore is in the backup page.'
            : 'النسخ والاسترجاع الكامل لقاعدة البيانات من صفحة النسخ الاحتياطي.'); ?>
    </p>
    <div class="actions" style="margin-top:0">
        <a class="btn ghost" href="backup.php"><?php echo e(t('backup')); ?></a>
    </div>
</div>
<?php endif; ?>

<script src="assets/debt-entry.js?v=3"></script>
<script>
(function () {
  if (window.bindYmPickers) window.bindYmPickers(document);
  var input = document.getElementById('csvFileInput');
  var nameEl = document.getElementById('csvFileName');
  if (!input || !nameEl) return;
  input.addEventListener('change', function () {
    var f = input.files && input.files[0] ? input.files[0].name : '';
    nameEl.textContent = f || <?php echo json_encode($lang === 'en' ? 'No file selected' : 'ما محدد ملف'); ?>;
    nameEl.classList.toggle('has-file', !!f);
  });
})();
</script>
<?php render_footer(); ?>
