<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/settings_tabs.php';
require_login();
require_perm('backup');
if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
    flash('error', 'النسخ الاحتياطي للمنصة فقط');
    redirect('settings.php?tab=sas');
}

function backup_tables()
{
    return array('subscribers', 'service_plans', 'subscriptions', 'invoices', 'message_logs', 'activity_logs');
}

$isPlatform = function_exists('is_super_admin_user') && is_super_admin_user();

if (isset($_GET['gdrive']) && $_GET['gdrive'] === 'connect') {
    if (!$isPlatform || !function_exists('gdrive_auth_url')) {
        flash('error', 'الربط لأدمن المنصة');
        redirect('backup.php');
    }
    $g = gdrive_settings();
    if ($g['client_id'] === '' || $g['client_secret'] === '') {
        flash('error', 'احفظ Client ID و Secret أولاً');
        redirect('backup.php');
    }
    header('Location: ' . gdrive_auth_url());
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'platform' && function_exists('platform_backup_sql')) {
    $tid = $isPlatform ? 0 : (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1);
    $out = platform_backup_sql($pdo, null, $tid);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="platform-backup-' . date('Ymd-His') . '.sql"');
    echo $out;
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'agents' && function_exists('platform_agents_pack_zip')) {
    $tid = $isPlatform ? 0 : (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1);
    list($ok, $path, $msg) = platform_agents_pack_zip($pdo, $tid);
    if (!$ok || !is_file($path)) {
        flash('error', $msg);
        redirect('backup.php');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    readfile($path);
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === 'saved' && function_exists('backup_file_for_download')) {
    $path = backup_file_for_download(isset($_GET['name']) ? $_GET['name'] : '');
    if ($path === '') {
        flash('error', 'الملف مو موجود');
        redirect('backup.php');
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    readfile($path);
    exit;
}

if (isset($_GET['download']) && $_GET['download'] === '1' && function_exists('backup_write_now')) {
    list($okNow, $pathNow, $msgNow) = backup_write_now($pdo, 'manual');
    if (!$okNow || !is_file($pathNow)) {
        flash('error', $msgNow !== '' ? $msgNow : 'تعذر إنشاء النسخة');
        redirect('backup.php');
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($pathNow) . '"');
    readfile($pathNow);
    exit;
}

if (isset($_GET['download'])) {
    if (!$isPlatform && function_exists('platform_backup_sql')) {
        $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $out = platform_backup_sql($pdo, null, $tid);
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="agency-backup-' . date('Ymd-His') . '.sql"');
        echo $out;
        exit;
    }
    global $pdo, $config;
    $dbName = $config['db']['name'];
    $out = "-- WiFi-Net-SALES backup " . date('Y-m-d H:i:s') . "\n";
    $out .= "SET NAMES utf8mb4;\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n";

    foreach (backup_tables() as $table) {
        $out .= "\nTRUNCATE TABLE `{$table}`;\n";
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cols = array();
            $vals = array();
            foreach ($row as $k => $v) {
                $cols[] = '`' . str_replace('`', '``', $k) . '`';
                if ($v === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = $pdo->quote($v);
                }
            }
            $out .= 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
        }
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="wifi-net-sales-backup-' . date('Ymd-His') . '.sql"');
    echo $out;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_gdrive') {
    if (!verify_csrf(post('csrf')) || !$isPlatform) {
        flash('error', 'طلب غير صالح');
        redirect('backup.php');
    }
    if (function_exists('settings_save')) {
        settings_save(array(
            'gdrive_client_id' => trim((string) post('gdrive_client_id')),
            'gdrive_client_secret' => trim((string) post('gdrive_client_secret')),
            'gdrive_folder_id' => trim((string) post('gdrive_folder_id')),
        ));
    }
    flash('success', 'حُفظت إعدادات كوكل درايف');
    redirect('backup.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_backup_auto') {
    if (!verify_csrf(post('csrf')) || !function_exists('backup_prefs_set')) {
        flash('error', 'طلب غير صالح');
        redirect('backup.php');
    }
    list(, , , $prefKey) = backup_current_scope();
    backup_prefs_set($prefKey, (int) post('backup_hours', '0'), (int) post('backup_max', '7'));
    flash('success', 'تم حفظ إعداد النسخ التلقائي');
    redirect('backup.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'backup_drive') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect('backup.php');
    }
    if (!function_exists('platform_backup_snapshot')) {
        flash('error', 'النسخ غير متوفر');
        redirect('backup.php');
    }
    list($ok, $path, $msg) = platform_backup_snapshot($pdo, $config, 'manual');
    flash($ok ? 'success' : 'error', $msg);
    redirect('backup.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'import_agents') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect('backup.php');
    }
    if (empty($_FILES['pack']['tmp_name']) || !function_exists('platform_agents_pack_import')) {
        flash('error', 'اختر ملف الحزمة');
        redirect('backup.php');
    }
    if (function_exists('platform_backup_snapshot')) {
        list($bakOk, $bakPath, $bakMsg) = platform_backup_snapshot($pdo, $config, 'pre-import-agents');
        if (!$bakOk) {
            flash('error', 'توقف الاستيراد — النسخة فشلت: ' . $bakMsg);
            redirect('backup.php');
        }
    }
    $tid = $isPlatform ? 0 : (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1);
    list($ok, $msg) = platform_agents_pack_import($pdo, $_FILES['pack']['tmp_name'], $tid);
    flash($ok ? 'success' : 'error', $msg);
    redirect('backup.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'restore') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'Invalid request');
        redirect('backup.php');
    }
    if (empty($_FILES['backup_file']['tmp_name'])) {
        flash('error', 'No file');
        redirect('backup.php');
    }
    $sql = file_get_contents($_FILES['backup_file']['tmp_name']);
    if ($sql === false || trim($sql) === '') {
        flash('error', 'Empty backup');
        redirect('backup.php');
    }

    if (function_exists('backup_restore_sql')) {
        $allowFull = post('allow_full') === '1';
        list($okRe, $msgRe) = backup_restore_sql($pdo, $sql, $isPlatform, $allowFull);
        flash($okRe ? 'success' : 'error', $msgRe);
    } else {
        flash('error', 'الاسترجاع غير متوفر');
    }
    redirect('backup.php');
}

render_header(t('backup'), 'backup', 'Download / restore database');
render_settings_tabs('backup');
?>
<div class="panel">
    <h2><?php echo e(t('backup_now')); ?></h2>
    <p class="meta"><?php
        list($bkKind) = function_exists('backup_current_scope') ? backup_current_scope() : array('tenant');
        if ($bkKind === 'system') {
            echo e($lang === 'en' ? 'A new full-system file is written the moment you click.' : 'ينشأ ملف جديد لكل النظام لحظة الضغط، ويُحفظ في مجلد التصدير.');
        } elseif ($bkKind === 'agent') {
            echo e($lang === 'en' ? 'Only this agent’s subscribers are included.' : 'النسخة لمشتركيك أنت فقط، مو كل الوكالة. تُنشأ الآن وتُحفظ في مجلد التصدير.');
        } else {
            echo e($lang === 'en' ? 'Only this agency is included.' : 'النسخة لوكالتك فقط. تُنشأ الآن وتُحفظ في مجلد التصدير.');
        }
    ?></p>
    <div class="actions">
        <a class="btn" href="backup.php?download=1"><?php echo e(t('backup_now')); ?></a>
        <a class="btn secondary" href="backup.php?download=platform"><?php echo e($lang === 'en' ? 'Platform SQL' : 'نسخة المنصة'); ?></a>
        <a class="btn secondary" href="backup.php?download=agents"><?php echo e($lang === 'en' ? 'Agents pack' : 'حزمة الوكلاء'); ?></a>
    </div>
    <form method="post" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="backup_drive">
        <button class="btn ghost" type="submit"><?php echo e($lang === 'en' ? 'Save + Google Drive' : 'حفظ ورفع كوكل درايف'); ?></button>
    </form>
</div>
<?php
$bkPrefs = function_exists('backup_prefs_get') ? backup_prefs_get(backup_current_scope()[3]) : array('hours' => 0, 'max' => 7);
$bkFiles = function_exists('backup_list_files') ? backup_list_files() : array();
?>
<div class="panel">
    <h2><?php echo e($lang === 'en' ? 'Automatic copies' : 'نسخ تلقائي'); ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_backup_auto">
        <div class="form-grid" style="max-width:520px">
            <div>
                <label><?php echo e($lang === 'en' ? 'Every (hours, 0 = off)' : 'كل كم ساعة (0 = إيقاف)'); ?></label>
                <input type="number" name="backup_hours" min="0" max="720" value="<?php echo (int) $bkPrefs['hours']; ?>">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Max copies' : 'الحد الأقصى للنسخ'); ?></label>
                <input type="number" name="backup_max" min="1" max="60" value="<?php echo (int) $bkPrefs['max']; ?>">
            </div>
        </div>
        <div class="actions"><button class="btn" type="submit"><?php echo e(t('save')); ?></button></div>
    </form>
    <?php if ($bkFiles): ?>
        <ul class="meta">
            <?php foreach ($bkFiles as $bf): ?>
                <li><a href="backup.php?download=saved&amp;name=<?php echo e(basename($bf)); ?>"><?php echo e(basename($bf)); ?></a>
                    — <?php echo e(date('Y-m-d H:i', filemtime($bf))); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="panel">
    <h2><?php echo e($lang === 'en' ? 'Import agents pack' : 'استيراد حزمة الوكلاء'); ?></h2>
    <p class="meta"><?php echo e($lang === 'en'
        ? 'Zip of users, subscribers, debts, activations, and card prices. A backup runs first.'
        : 'ملف الوكلاء مع المشتركين والديون والتفعيلات وأسعار الكروت. تُحفظ نسخة قبل الاستيراد.'); ?></p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="import_agents">
        <input type="file" name="pack" accept=".zip,application/zip" required>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('import')); ?></button>
        </div>
    </form>
</div>

<?php if ($isPlatform):
    $g = function_exists('gdrive_settings') ? gdrive_settings() : array('client_id' => '', 'client_secret' => '', 'folder_id' => '', 'refresh_token' => '');
    $ready = function_exists('gdrive_is_ready') && gdrive_is_ready();
    $redir = function_exists('gdrive_redirect_uri') ? gdrive_redirect_uri() : '';
?>
<div class="panel">
    <h2>كوكول درايف</h2>
    <p class="meta">اربط حساب كوكل ثم أي نسخة (قبل الاستيراد أو من الزر أعلاه) تُرفع تلقائياً. ضع Redirect URI في Google Cloud: <?php echo e($redir); ?></p>
    <p class="meta"><?php echo $ready ? 'الحالة: مربوط' : 'الحالة: غير مربوط'; ?></p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_gdrive">
        <div class="form-grid">
            <div>
                <label>Client ID</label>
                <input name="gdrive_client_id" value="<?php echo e($g['client_id']); ?>">
            </div>
            <div>
                <label>Client Secret</label>
                <input name="gdrive_client_secret" value="<?php echo e($g['client_secret']); ?>">
            </div>
            <div>
                <label>Folder ID</label>
                <input name="gdrive_folder_id" value="<?php echo e($g['folder_id']); ?>" placeholder="اختياري">
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit">حفظ</button>
            <a class="btn secondary" href="backup.php?gdrive=connect">ربط كوكل</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <h2><?php echo e(t('restore')); ?></h2>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="restore">
        <input type="file" name="backup_file" accept=".sql,text/plain" required>
        <?php if ($isPlatform): ?>
            <label class="meta"><input type="checkbox" name="allow_full" value="1"> استبدال قاعدة النظام كامل (الملفات القديمة اللي بيها مسح جداول)</label>
        <?php endif; ?>
        <p class="meta">قبل الاسترجاع تنحفظ نسخة جديدة. نسخة الوكيل ما تمس بيانات وكيل ثاني، ونسخة الوكالة ما تمس وكالة ثانية.</p>
        <div class="actions">
            <button class="btn danger" type="submit" onclick="return confirm('Restore will overwrite current data. Continue?');"><?php echo e(t('restore')); ?></button>
        </div>
    </form>
</div>
<?php render_footer(); ?>
