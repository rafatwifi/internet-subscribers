<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$isEn = (isset($lang) && $lang === 'en');
$tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
if ($tid <= 0) {
    $tid = 1;
}
$sqlFile = __DIR__ . '/shop_restore.sql';
$doneFile = dirname(__DIR__) . '/storage/shop_restore_done.txt';

function restore_shop_fail($msg)
{
    flash('error', $msg);
    redirect('restore_shop.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        restore_shop_fail('طلب غير صالح');
    }
    if (!is_file($sqlFile)) {
        restore_shop_fail('ملف النسخة مو موجود جنب الصفحة');
    }
    $sql = file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') {
        restore_shop_fail('ملف النسخة فاضي');
    }
    $allow = array(
        'subscribers' => true,
        'service_plans' => true,
        'subscriptions' => true,
        'invoices' => true,
    );
    $parts = preg_split('/;\s*\n/', $sql);
    $ran = 0;
    $skipped = 0;
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($parts as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '--') === 0 || strpos($stmt, 'SET ') === 0) {
                continue;
            }
            if (!preg_match('/^(TRUNCATE TABLE|INSERT INTO|REPLACE INTO)\s+`([a-z0-9_]+)`/i', $stmt, $m)) {
                $skipped++;
                continue;
            }
            $table = strtolower($m[2]);
            if (empty($allow[$table])) {
                $skipped++;
                continue;
            }
            if (stripos($stmt, 'TRUNCATE') === 0) {
                continue;
            }
            $stmt = preg_replace('/^INSERT INTO/i', 'REPLACE INTO', $stmt, 1);
            $pdo->exec($stmt);
            $ran++;
        }
        try {
            $pdo->prepare('UPDATE subscribers SET tenant_id = :t WHERE id BETWEEN 1 AND 76')
                ->execute(array(':t' => $tid));
        } catch (Exception $e) {
        }
        $hasTenant = false;
        try {
            $hasTenant = (bool) $pdo->query("SHOW COLUMNS FROM sas_users_cache LIKE 'tenant_id'")->fetch();
        } catch (Exception $e) {
        }
        $subs = $pdo->query(
            'SELECT s.id, s.name, s.phone, s.sas_username, s.sas_user_id,
                    (SELECT sub.end_date FROM subscriptions sub WHERE sub.subscriber_id = s.id ORDER BY sub.id DESC LIMIT 1) AS end_date,
                    (SELECT sub.service_name FROM subscriptions sub WHERE sub.subscriber_id = s.id ORDER BY sub.id DESC LIMIT 1) AS service_name
             FROM subscribers s
             WHERE s.id BETWEEN 1 AND 76 AND s.sas_username IS NOT NULL AND s.sas_username <> ""'
        )->fetchAll();
        foreach ($subs as $s) {
            $user = trim((string) $s['sas_username']);
            if ($user === '') {
                continue;
            }
            $exp = !empty($s['end_date']) ? ($s['end_date'] . ' 23:59:59') : null;
            $profile = isset($s['service_name']) ? (string) $s['service_name'] : '';
            try {
                if ($hasTenant) {
                    $pdo->prepare(
                        'REPLACE INTO sas_users_cache
                         (tenant_id, username, sas_user_id, display_name, phone, profile_name, enabled, expire_at, local_subscriber_id, synced_at)
                         VALUES (:t, :u, :sid, :n, :p, :pr, 1, :e, :lid, NOW())'
                    )->execute(array(
                        ':t' => $tid,
                        ':u' => $user,
                        ':sid' => (int) $s['sas_user_id'],
                        ':n' => (string) $s['name'],
                        ':p' => (string) $s['phone'],
                        ':pr' => $profile,
                        ':e' => $exp,
                        ':lid' => (int) $s['id'],
                    ));
                } else {
                    $pdo->prepare(
                        'REPLACE INTO sas_users_cache
                         (username, sas_user_id, display_name, phone, profile_name, enabled, expire_at, local_subscriber_id, synced_at)
                         VALUES (:u, :sid, :n, :p, :pr, 1, :e, :lid, NOW())'
                    )->execute(array(
                        ':u' => $user,
                        ':sid' => (int) $s['sas_user_id'],
                        ':n' => (string) $s['name'],
                        ':p' => (string) $s['phone'],
                        ':pr' => $profile,
                        ':e' => $exp,
                        ':lid' => (int) $s['id'],
                    ));
                }
            } catch (Exception $e2) {
            }
        }
        if (function_exists('sas_relink_ledger_rows')) {
            sas_relink_ledger_rows($pdo);
        }
        $unpaidN = 0;
        $unpaidSum = 0;
        try {
            $stU = $pdo->prepare(
                'SELECT COUNT(*) AS n, COALESCE(SUM(i.amount),0) AS s
                 FROM invoices i
                 JOIN subscribers s ON s.id = i.subscriber_id
                 WHERE i.status = "unpaid" AND s.tenant_id = :t'
            );
            $stU->execute(array(':t' => $tid));
            $ur = $stU->fetch();
            if ($ur) {
                $unpaidN = (int) $ur['n'];
                $unpaidSum = (float) $ur['s'];
            }
        } catch (Exception $e) {
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        @file_put_contents($doneFile, date('c') . ' tenant=' . $tid . ' rows=' . count($subs) . ' unpaid=' . $unpaidN);
        $lock = dirname(__DIR__) . '/storage/shop_home_tenant.txt';
        @file_put_contents($lock, (string) $tid);
        flash('success', 'رجعت نسخة 22 أيلول: ' . count($subs) . ' مشترك، ديون غير مسددة ' . $unpaidN . ' بمبلغ ' . number_format($unpaidSum, 0, '.', ','));
        redirect('sas.php');
    } catch (Exception $e) {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Exception $e2) {
        }
        restore_shop_fail('فشل الترجيع: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/../includes/layout.php';
render_header($isEn ? 'Restore shop' : 'ترجيع البيانات', 'sas');
?>
<div class="panel">
    <h2><?php echo e($isEn ? 'Restore 22 Sep backup' : 'ترجيع نسخة 22 أيلول'); ?></h2>
    <p><?php echo e($isEn
        ? 'This puts subscribers, debts, and subscriptions back on the account you are logged in with. Nothing else is deleted.'
        : 'يرجّع المشتركين والديون والاشتراكات على الحساب اللي داخل بيه هسه. ما ينحذف شي ثاني.'); ?></p>
    <p class="meta"><?php echo e($isEn ? 'Logged-in company id' : 'رقم الوكالة الحالية'); ?>: <?php echo (int) $tid; ?></p>
    <?php if (!is_file($sqlFile)): ?>
        <div class="alert alert-error"><?php echo e($isEn ? 'shop_restore.sql is missing next to this page.' : 'ملف shop_restore.sql مو موجود جنب الصفحة.'); ?></div>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Restore my data' : 'رجّع بياناتي'); ?></button>
        </form>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
