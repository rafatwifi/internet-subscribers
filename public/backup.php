<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/settings_tabs.php';
require_login();
require_perm('backup');

function backup_tables()
{
    return array('subscribers', 'service_plans', 'subscriptions', 'invoices', 'message_logs', 'activity_logs');
}

if (isset($_GET['download'])) {
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

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        // split by ;\n roughly
        $parts = preg_split('/;\s*\n/', $sql);
        foreach ($parts as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '--') === 0) {
                continue;
            }
            $pdo->exec($stmt);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        flash('success', 'Backup restored');
    } catch (Exception $e) {
        flash('error', 'Restore failed: ' . $e->getMessage());
    }
    redirect('backup.php');
}

render_header(t('backup'), 'backup', 'Download / restore database');
render_settings_tabs('backup');
?>
<div class="panel">
    <h2><?php echo e(t('backup_now')); ?></h2>
    <div class="actions">
        <a class="btn" href="backup.php?download=1"><?php echo e(t('backup_now')); ?></a>
    </div>
</div>

<div class="panel">
    <h2><?php echo e(t('restore')); ?></h2>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="restore">
        <input type="file" name="backup_file" accept=".sql,text/plain" required>
        <div class="actions">
            <button class="btn danger" type="submit" onclick="return confirm('Restore will overwrite current data. Continue?');"><?php echo e(t('restore')); ?></button>
        </div>
    </form>
</div>
<?php render_footer(); ?>
