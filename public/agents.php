<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_perm('agents');
ensure_subscriber_agent_column($pdo);

$isEn = ($lang === 'en');
$me = current_admin();
$meId = $me ? (int) $me['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('agents.php');
    }
    $action = post('action');

    if ($action === 'create') {
        $username = trim((string) post('username', ''));
        $display = trim((string) post('display_name', ''));
        $password = (string) post('password', '');
        $res = create_admin_user($pdo, $username, $display, $password, 'agent');
        if ($res === 'ok') {
            flash('success', $isEn ? 'Agent created' : 'تم إضافة الوكيل');
        } elseif ($res === 'taken') {
            flash('error', $isEn ? 'Username taken' : 'اسم المستخدم مستخدم');
        } elseif ($res === 'username') {
            flash('error', $isEn ? 'Invalid username' : 'اسم مستخدم غير صالح');
        } else {
            flash('error', $isEn ? 'Check the fields (password min 4)' : 'تحقق من الحقول (كلمة المرور 4 أحرف على الأقل)');
        }
        redirect('agents.php');
    }

    if ($action === 'update') {
        $uid = (int) post('user_id', '0');
        $display = trim((string) post('display_name', ''));
        $active = post('is_active') === '1' ? 1 : 0;
        $row = get_admin_user($pdo, $uid);
        if (!$row || normalize_admin_role($row['role']) !== 'agent') {
            flash('error', $isEn ? 'Agent not found' : 'الوكيل غير موجود');
            redirect('agents.php');
        }
        update_admin_user_meta($pdo, $uid, $display !== '' ? $display : $row['display_name'], 'agent');
        $pdo->prepare('UPDATE admin_users SET is_active = :a, updated_at = NOW() WHERE id = :id AND role = "agent"')
            ->execute(array(':a' => $active, ':id' => $uid));
        $newPass = (string) post('password', '');
        if (strlen($newPass) >= 4) {
            change_user_password($pdo, $uid, $newPass);
        }
        flash('success', $isEn ? 'Agent updated' : 'تم تعديل الوكيل');
        redirect('agents.php');
    }

    if ($action === 'delete') {
        $uid = (int) post('user_id', '0');
        $row = get_admin_user($pdo, $uid);
        if (!$row || normalize_admin_role($row['role']) !== 'agent') {
            flash('error', $isEn ? 'Agent not found' : 'الوكيل غير موجود');
            redirect('agents.php');
        }
        $adminId = default_admin_user_id($pdo);
        if ($adminId > 0) {
            $pdo->prepare('UPDATE subscribers SET agent_user_id = :a WHERE agent_user_id = :u')
                ->execute(array(':a' => $adminId, ':u' => $uid));
        }
        $res = delete_admin_user($pdo, $uid, $meId);
        if ($res === 'ok') {
            flash('success', $isEn ? 'Agent deleted — subscribers moved to admin' : 'تم حذف الوكيل — المشتركين صاروا للمدير');
        } else {
            flash('error', $isEn ? 'Could not delete' : 'تعذر الحذف');
        }
        redirect('agents.php');
    }
}

$agents = list_agent_users($pdo, false);
$counts = array();
try {
    $st = $pdo->query(
        'SELECT agent_user_id, COUNT(*) AS c FROM subscribers WHERE agent_user_id IS NOT NULL GROUP BY agent_user_id'
    );
    foreach ($st->fetchAll() as $r) {
        $counts[(int) $r['agent_user_id']] = (int) $r['c'];
    }
} catch (Exception $e) {
}

render_header($isEn ? 'Agents' : 'الوكلاء', 'agents');
?>
<div class="panel">
    <p class="meta" style="margin-top:0">
        <?php echo e($isEn
            ? 'Agents log in and only see their own subscribers. They can send messages but cannot change system settings.'
            : 'الوكيل يدخل للنظام ويشوف مشتركيه فقط. يكدر يرسل رسائل ومايكدر يعدل إعدادات النظام.'); ?>
    </p>

    <h2><?php echo e($isEn ? 'Add agent' : 'إضافة وكيل'); ?></h2>
    <form method="post" class="form-grid" style="margin-bottom:22px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">
        <div>
            <label><?php echo e($isEn ? 'Username' : 'اسم الدخول'); ?></label>
            <input name="username" required pattern="[A-Za-z0-9._\-]{2,40}" placeholder="agent1">
        </div>
        <div>
            <label><?php echo e($isEn ? 'Display name' : 'الاسم الظاهر'); ?></label>
            <input name="display_name" required>
        </div>
        <div>
            <label><?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?></label>
            <input name="password" type="password" required minlength="4">
        </div>
        <div class="actions" style="align-items:end">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Add' : 'إضافة'); ?></button>
        </div>
    </form>

    <h2><?php echo e($isEn ? 'Agents list' : 'قائمة الوكلاء'); ?></h2>
    <div class="table-wrap">
        <table class="data-table table-compact">
            <thead>
            <tr>
                <th>#</th>
                <th><?php echo e($isEn ? 'Name' : 'الاسم'); ?></th>
                <th><?php echo e($isEn ? 'Username' : 'الدخول'); ?></th>
                <th><?php echo e($isEn ? 'Subscribers' : 'المشتركين'); ?></th>
                <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                <th><?php echo e($isEn ? 'Actions' : 'إجراءات'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$agents): ?>
                <tr><td colspan="6"><?php echo e($isEn ? 'No agents yet' : 'ماكو وكلاء بعد'); ?></td></tr>
            <?php else: ?>
                <?php foreach ($agents as $a): ?>
                    <?php $aid = (int) $a['id']; ?>
                    <tr>
                        <td><?php echo $aid; ?></td>
                        <td>
                            <form method="post" class="inline-agent-form">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="user_id" value="<?php echo $aid; ?>">
                                <input name="display_name" value="<?php echo e($a['display_name']); ?>" required>
                        </td>
                        <td><?php echo e($a['username']); ?></td>
                        <td>
                            <a href="sas.php">
                                <?php echo isset($counts[$aid]) ? (int) $counts[$aid] : 0; ?>
                            </a>
                        </td>
                        <td>
                            <label class="toggle" style="margin:0">
                                <input type="checkbox" name="is_active" value="1" <?php echo (int) $a['is_active'] === 1 ? 'checked' : ''; ?>>
                                <span class="toggle-ui" aria-hidden="true"></span>
                                <span class="toggle-text"><?php echo e((int) $a['is_active'] === 1 ? ($isEn ? 'Active' : 'فعال') : ($isEn ? 'Off' : 'موقوف')); ?></span>
                            </label>
                            <div style="margin-top:6px">
                                <input name="password" type="password" minlength="4" placeholder="<?php echo e($isEn ? 'New password (optional)' : 'كلمة مرور جديدة (اختياري)'); ?>">
                            </div>
                        </td>
                        <td class="actions" style="gap:6px">
                                <button class="btn sm" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
                            </form>
                            <form method="post" onsubmit="return confirm('<?php echo e($isEn ? 'Delete this agent?' : 'تحذف هذا الوكيل؟'); ?>');">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?php echo $aid; ?>">
                                <button class="btn ghost sm danger" type="submit"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_footer(); ?>
