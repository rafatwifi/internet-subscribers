<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('subscribers');

$isEn = ($lang === 'en');
$fields = array(
    'skip' => $isEn ? '— ignore —' : '— تجاهل —',
    'name' => $isEn ? 'Full name' : 'الاسم',
    'firstname' => $isEn ? 'First name' : 'الاسم الأول',
    'lastname' => $isEn ? 'Last name' : 'الاسم الأخير',
    'phone' => $isEn ? 'Phone' : 'رقم الهاتف',
    'address' => $isEn ? 'Address' : 'العنوان',
    'notes' => $isEn ? 'Notes' : 'ملاحظات',
    'debt' => $isEn ? 'Debt amount' : 'مبلغ الدين',
    'debt_note' => $isEn ? 'Debt note' : 'ملاحظة الدين',
);

function import_map_rows_from_file($path)
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $rows = array();
    if ($ext === 'xlsx' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $shared = array();
            $ss = $zip->getFromName('xl/sharedStrings.xml');
            if ($ss) {
                if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ss, $m)) {
                    foreach ($m[1] as $t) {
                        $shared[] = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
                    }
                }
            }
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            if ($sheet && preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheet, $rm)) {
                foreach ($rm[1] as $rowXml) {
                    $cells = array();
                    if (preg_match_all('/<c([^>]*)>(.*?)<\/c>/s', $rowXml, $cm)) {
                        foreach ($cm[1] as $i => $attrs) {
                            $inner = $cm[2][$i];
                            $val = '';
                            if (strpos($attrs, 't="s"') !== false && preg_match('/<v>(\d+)<\/v>/', $inner, $vm)) {
                                $idx = (int) $vm[1];
                                $val = isset($shared[$idx]) ? $shared[$idx] : '';
                            } elseif (preg_match('/<v>(.*?)<\/v>/', $inner, $vm)) {
                                $val = $vm[1];
                            } elseif (preg_match('/<t[^>]*>(.*?)<\/t>/s', $inner, $vm)) {
                                $val = html_entity_decode(strip_tags($vm[1]), ENT_QUOTES, 'UTF-8');
                            }
                            $cells[] = $val;
                        }
                    }
                    if ($cells) {
                        $rows[] = $cells;
                    }
                    if (count($rows) >= 5000) {
                        break;
                    }
                }
            }
        }
        return $rows;
    }
    $fh = fopen($path, 'r');
    if (!$fh) {
        return array();
    }
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    while (($data = fgetcsv($fh)) !== false) {
        $rows[] = $data;
        if (count($rows) >= 5000) {
            break;
        }
    }
    fclose($fh);
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('import_map.php');
    }
    $step = post('step');
    if ($step === 'upload') {
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            flash('error', $isEn ? 'Choose a file' : 'اختار ملف');
            redirect('import_map.php');
        }
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, array('csv', 'txt', 'xlsx'), true)) {
            flash('error', $isEn ? 'Use CSV or Excel xlsx' : 'استخدم CSV أو Excel xlsx');
            redirect('import_map.php');
        }
        $dir = dirname(__DIR__) . '/storage/import_tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $dest = $dir . '/u' . (int) (current_admin() ? current_admin()['id'] : 0) . '.' . $ext;
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            flash('error', $isEn ? 'Upload failed' : 'فشل الرفع');
            redirect('import_map.php');
        }
        $rows = import_map_rows_from_file($dest);
        if (count($rows) < 2) {
            flash('error', $isEn ? 'File needs a header row and data' : 'الملف لازم عنوان وأقل صف بيانات');
            redirect('import_map.php');
        }
        $_SESSION['import_map_file'] = $dest;
        $_SESSION['import_map_headers'] = $rows[0];
        flash('success', $isEn ? 'Map the columns then import' : 'طابق الأعمدة ثم استورد');
        redirect('import_map.php?step=map');
    }
    if ($step === 'commit') {
        $path = isset($_SESSION['import_map_file']) ? (string) $_SESSION['import_map_file'] : '';
        if ($path === '' || !is_file($path)) {
            flash('error', $isEn ? 'Upload again' : 'ارفع الملف مرة ثانية');
            redirect('import_map.php');
        }
        $map = isset($_POST['map']) && is_array($_POST['map']) ? $_POST['map'] : array();
        $rows = import_map_rows_from_file($path);
        $added = 0;
        $skipped = 0;
        $debts = 0;
        $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $me = current_admin();
        $agentId = ($me && function_exists('is_agent_user') && is_agent_user()) ? (int) $me['id'] : null;
        for ($i = 1; $i < count($rows); $i++) {
            $line = $rows[$i];
            $rec = array();
            foreach ($map as $col => $field) {
                $field = (string) $field;
                if ($field === '' || $field === 'skip') {
                    continue;
                }
                $rec[$field] = isset($line[(int) $col]) ? trim((string) $line[(int) $col]) : '';
            }
            $fn = isset($rec['firstname']) ? $rec['firstname'] : '';
            $ln = isset($rec['lastname']) ? $rec['lastname'] : '';
            $name = isset($rec['name']) ? $rec['name'] : trim($fn . ' ' . $ln);
            $phone = isset($rec['phone']) ? preg_replace('/\D+/', '', $rec['phone']) : '';
            if ($name === '' || $phone === '') {
                $skipped++;
                continue;
            }
            try {
                $st = $pdo->prepare(
                    'INSERT INTO subscribers (name, phone, address, notes, tenant_id, agent_user_id)
                     VALUES (:n, :p, :a, :no, :t, :ag)'
                );
                $st->execute(array(
                    ':n' => $name,
                    ':p' => $phone,
                    ':a' => isset($rec['address']) && $rec['address'] !== '' ? $rec['address'] : null,
                    ':no' => isset($rec['notes']) && $rec['notes'] !== '' ? $rec['notes'] : null,
                    ':t' => $tid,
                    ':ag' => $agentId,
                ));
                $sid = (int) $pdo->lastInsertId();
                $added++;
                $debt = isset($rec['debt']) ? (float) str_replace(',', '', $rec['debt']) : 0;
                if ($sid > 0 && $debt > 0) {
                    try {
                        $pdo->prepare(
                            'INSERT INTO invoices (subscriber_id, amount, status, notes, created_at)
                             VALUES (:s, :a, "unpaid", :n, NOW())'
                        )->execute(array(
                            ':s' => $sid,
                            ':a' => $debt,
                            ':n' => isset($rec['debt_note']) ? $rec['debt_note'] : 'استيراد',
                        ));
                        $debts++;
                    } catch (Exception $e2) {
                    }
                }
            } catch (Exception $e) {
                $skipped++;
            }
        }
        unset($_SESSION['import_map_file'], $_SESSION['import_map_headers']);
        flash('success', ($isEn ? 'Imported ' : 'تم استيراد ') . $added
            . ($isEn ? ' / debts ' : ' / ديون ') . $debts
            . ($isEn ? ' / skipped ' : ' / تخطي ') . $skipped);
        redirect('import_map.php');
    }
}

$step = isset($_GET['step']) ? (string) $_GET['step'] : 'upload';
$headers = ($step === 'map' && !empty($_SESSION['import_map_headers']) && is_array($_SESSION['import_map_headers']))
    ? $_SESSION['import_map_headers'] : array();
if ($step === 'map' && !$headers) {
    $step = 'upload';
}

render_header($isEn ? 'Import with mapping' : 'استيراد بمطابقة الحقول', 'import_export');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Excel / CSV import' : 'استيراد Excel / CSV'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'Upload a file, then tell the system which column is phone, name, debt, notes…'
        : 'ارفع الملف، وبعدين حدد كل عمود: هاتف، اسم، دين، ملاحظات…'); ?></p>
    <p><a href="import_export.php"><?php echo e($isEn ? 'Back to export tools' : 'رجوع لأدوات التصدير'); ?></a></p>

    <?php if ($step !== 'map'): ?>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="step" value="upload">
        <label><?php echo e($isEn ? 'File (.xlsx or .csv)' : 'الملف (xlsx أو csv)'); ?>
            <input type="file" name="file" accept=".csv,.txt,.xlsx" required>
        </label>
        <div class="actions" style="margin-top:12px">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Upload & map' : 'رفع ومطابقة'); ?></button>
        </div>
    </form>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="step" value="commit">
        <div class="table-wrap">
            <table class="table-compact">
                <thead><tr><th><?php echo e($isEn ? 'File column' : 'عمود الملف'); ?></th><th><?php echo e($isEn ? 'Goes to' : 'يدخل كـ'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($headers as $i => $h): ?>
                    <tr>
                        <td><?php echo e($h !== '' ? $h : ('#' . ($i + 1))); ?></td>
                        <td>
                            <select name="map[<?php echo (int) $i; ?>]">
                                <?php foreach ($fields as $k => $lab): ?>
                                    <option value="<?php echo e($k); ?>"><?php echo e($lab); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="actions" style="margin-top:12px">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Import now' : 'استيراد الآن'); ?></button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
