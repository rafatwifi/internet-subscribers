<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$me = current_admin();
if (!$me || $me['id'] <= 0) {
    flash('error', $lang === 'en' ? 'Re-login required' : 'سجّل دخول من جديد');
    redirect('login.php');
}

$row = get_admin_user($pdo, $me['id']);
if (!$row) {
    flash('error', $lang === 'en' ? 'User not found' : 'المستخدم مو موجود');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $lang === 'en' ? 'Invalid request' : 'طلب غير صالح');
        redirect('profile.php');
    }
    $action = post('action');

    if ($action === 'profile') {
        $display = trim((string) post('display_name', ''));
        if ($display === '') {
            flash('error', $lang === 'en' ? 'Name required' : 'الاسم مطلوب');
            redirect('profile.php');
        }
        update_admin_user_meta($pdo, $me['id'], $display, null);
        $_SESSION['admin_display_name'] = $display;
        activity_log($pdo, null, 'system', $me['id'], 'profile_update', 'تحديث البروفايل', $display);
        flash('success', $lang === 'en' ? 'Profile saved' : 'تم حفظ البروفايل');
        redirect('profile.php');
    }

    if ($action === 'password') {
        $old = (string) post('old_password', '');
        $new = (string) post('new_password', '');
        $new2 = (string) post('new_password2', '');
        if (strlen($new) < 4) {
            flash('error', $lang === 'en' ? 'Password too short (min 4)' : 'الرمز قصير (أقل شي 4)');
            redirect('profile.php');
        }
        if ($new !== $new2) {
            flash('error', $lang === 'en' ? 'Passwords do not match' : 'الرمزين مو متطابقين');
            redirect('profile.php');
        }
        if (!verify_user_password($pdo, $me['id'], $old)) {
            flash('error', $lang === 'en' ? 'Current password wrong' : 'الرمز الحالي غلط');
            redirect('profile.php');
        }
        change_user_password($pdo, $me['id'], $new);
        activity_log($pdo, null, 'system', $me['id'], 'password_change', 'تغيير رمز الدخول من البروفايل', $me['username']);
        flash('success', $lang === 'en' ? 'Password updated' : 'تم تغيير الرمز');
        redirect('profile.php');
    }

    redirect('profile.php');
}

$role = normalize_admin_role(isset($row['role']) ? $row['role'] : $me['role']);
render_header($lang === 'en' ? 'My profile' : 'بروفايلي', 'profile');
?>
<div class="panel panel-compact profile-card">
    <h2><?php echo e($lang === 'en' ? 'My profile' : 'بروفايلي'); ?></h2>
    <div class="profile-summary">
        <div class="profile-avatar"><?php
            $dn = (string) $row['display_name'];
            if (function_exists('mb_substr')) {
                echo e(mb_substr($dn, 0, 1, 'UTF-8'));
            } else {
                echo e(substr($dn, 0, 1));
            }
        ?></div>
        <div>
            <div class="profile-name"><?php echo e($row['display_name']); ?></div>
            <div class="meta">@<?php echo e($row['username']); ?> —
                <span class="badge active"><?php echo e(admin_role_label($role, $lang)); ?></span>
            </div>
            <p class="meta" style="margin:6px 0 0"><?php echo e(admin_role_hint($role, $lang)); ?></p>
        </div>
    </div>
</div>

<div class="panel panel-compact">
    <h2><?php echo e($lang === 'en' ? 'Edit profile' : 'تعديل البروفايل'); ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="profile">
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($lang === 'en' ? 'Login username' : 'اسم الدخول'); ?></label>
                <input value="<?php echo e($row['username']); ?>" disabled>
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Display name' : 'الاسم الظاهر'); ?></label>
                <input name="display_name" value="<?php echo e($row['display_name']); ?>" required>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Save profile' : 'حفظ البروفايل'); ?></button>
        </div>
    </form>
</div>

<div class="panel panel-compact">
    <h2><?php echo e($lang === 'en' ? 'Change password' : 'تغيير الرمز'); ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="password">
        <div class="form-grid cols-3">
            <div>
                <label><?php echo e($lang === 'en' ? 'Current password' : 'الرمز الحالي'); ?></label>
                <input type="password" name="old_password" required autocomplete="current-password">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'New password' : 'الرمز الجديد'); ?></label>
                <input type="password" name="new_password" required minlength="4" autocomplete="new-password">
            </div>
            <div>
                <label><?php echo e($lang === 'en' ? 'Confirm' : 'تأكيد الرمز'); ?></label>
                <input type="password" name="new_password2" required minlength="4" autocomplete="new-password">
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($lang === 'en' ? 'Update password' : 'حفظ الرمز'); ?></button>
        </div>
    </form>
</div>
<?php render_footer(); ?>
