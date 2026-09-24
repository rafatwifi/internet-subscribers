<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_perm('agents');
ensure_subscriber_agent_column($pdo);
ensure_admin_users_table($pdo);

$isEn = ($lang === 'en');
$me = current_admin();
$meId = $me ? (int) $me['id'] : 0;

try {
    $col = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'sas_manager_id'")->fetch();
    if (!$col) {
        $pdo->exec('ALTER TABLE admin_users ADD COLUMN sas_manager_id INT UNSIGNED NULL DEFAULT NULL AFTER role');
    }
} catch (Exception $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('agents.php');
    }
    // الوكيل يشوف القائمة فقط — بدون إنشاء/تعديل
    if (function_exists('is_agent_user') && is_agent_user()) {
        flash('error', $isEn ? 'View only' : 'عرض فقط — ما عندك صلاحية التعديل');
        redirect('agents.php');
    }
    $action = post('action');

    if ($action === 'create') {
        $username = trim((string) post('username', ''));
        $display = trim((string) post('display_name', ''));
        $password = (string) post('password', '');
        $res = create_admin_user($pdo, $username, $display, $password, 'agent');
        if ($res === 'ok') {
            if (function_exists('is_group_manager_user') && is_group_manager_user() && $meId > 0) {
                try {
                    $newId = (int) $pdo->query('SELECT id FROM admin_users WHERE username = ' . $pdo->quote($username) . ' LIMIT 1')->fetchColumn();
                    if ($newId > 0) {
                        $pdo->prepare('UPDATE admin_users SET reports_to_user_id = :r WHERE id = :id')
                            ->execute(array(':r' => $meId, ':id' => $newId));
                    }
                } catch (Exception $e) {
                }
            }
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
        $sasMid = (int) post('sas_manager_id', '0');
        try {
            $pdo->prepare('UPDATE admin_users SET sas_manager_id = :m WHERE id = :id AND role = "agent"')
                ->execute(array(':m' => $sasMid > 0 ? $sasMid : null, ':id' => $uid));
        } catch (Exception $e) {
        }
        $phone = trim((string) post('phone', ''));
        try {
            $pdo->prepare('UPDATE admin_users SET phone = :p WHERE id = :id AND role = "agent"')
                ->execute(array(':p' => $phone !== '' ? $phone : null, ':id' => $uid));
        } catch (Exception $e) {
        }
        $newPass = (string) post('password', '');
        if (strlen($newPass) >= 4) {
            change_user_password($pdo, $uid, $newPass);
        }
        flash('success', $isEn ? 'Agent updated' : 'تم تعديل الوكيل');
        redirect('agents.php');
    }

    if ($action === 'transfer_subs') {
        $fromId = (int) post('from_agent_id', '0');
        $toId = (int) post('to_agent_id', '0');
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            flash('error', $isEn ? 'Pick two different agents' : 'اختر وكيلين مختلفين');
            redirect('agents.php');
        }
        if (function_exists('admin_user_same_tenant')) {
            if (!admin_user_same_tenant($pdo, $fromId) || !admin_user_same_tenant($pdo, $toId)) {
                flash('error', $isEn ? 'Both agents must be in the same company' : 'الوكيلين لازم يكونون بنفس الشركة');
                redirect('agents.php');
            }
        }
        $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        try {
            $st = $pdo->prepare(
                'UPDATE subscribers SET agent_user_id = :to
                 WHERE agent_user_id = :from AND tenant_id = :t'
            );
            $st->execute(array(':to' => $toId, ':from' => $fromId, ':t' => $tid));
            $n = (int) $st->rowCount();
        } catch (Exception $e) {
            $st = $pdo->prepare('UPDATE subscribers SET agent_user_id = :to WHERE agent_user_id = :from');
            $st->execute(array(':to' => $toId, ':from' => $fromId));
            $n = (int) $st->rowCount();
        }
        flash('success', ($isEn ? 'Moved subscribers: ' : 'تم نقل المشتركين: ') . $n);
        redirect('agents.php');
    }

    if ($action === 'disable_sas') {
        $uid = (int) post('user_id', '0');
        if (post('confirm_disable') !== '1') {
            flash('error', $isEn ? 'Confirm required' : 'يلزم التأكيد');
            redirect('agents.php');
        }
        if (function_exists('admin_user_same_tenant') && !admin_user_same_tenant($pdo, $uid)) {
            flash('error', $isEn ? 'Wrong company' : 'شركة خاطئة');
            redirect('agents.php');
        }
        list($okD, $msgD) = disable_agent_sas($pdo, $config, $uid, $meId);
        flash($okD ? 'success' : 'error', $msgD);
        redirect('agents.php');
    }

    if ($action === 'save_accountant') {
        $uid = (int) post('user_id', '0');
        $display = trim((string) post('display_name', ''));
        $active = post('is_active') === '1' ? 1 : 0;
        $linked = (int) post('linked_agent_id', '0');
        $row = get_admin_user($pdo, $uid);
        if (!$row || normalize_admin_role($row['role']) !== 'accountant') {
            flash('error', $isEn ? 'Accountant not found' : 'المحاسب غير موجود');
            redirect('agents.php');
        }
        update_admin_user_meta($pdo, $uid, $display !== '' ? $display : $row['display_name'], 'accountant', $linked);
        $pdo->prepare('UPDATE admin_users SET is_active = :a, updated_at = NOW() WHERE id = :id AND role = "accountant"')
            ->execute(array(':a' => $active, ':id' => $uid));
        $newPass = (string) post('password', '');
        if (strlen($newPass) >= 4) {
            change_user_password($pdo, $uid, $newPass);
        }
        flash('success', $isEn ? 'Accountant updated' : 'تم تعديل المحاسب');
        redirect('agents.php');
    }

    if ($action === 'create_accountant') {
        $username = trim((string) post('username', ''));
        $display = trim((string) post('display_name', ''));
        $password = (string) post('password', '');
        $linked = (int) post('linked_agent_id', '0');
        $res = create_admin_user($pdo, $username, $display, $password, 'accountant', $linked);
        if ($res === 'ok') {
            flash('success', $isEn ? 'Accountant created' : 'تم إضافة المحاسب');
        } elseif ($res === 'taken') {
            flash('error', $isEn ? 'Username taken' : 'اسم المستخدم مستخدم');
        } elseif ($res === 'username') {
            flash('error', $isEn ? 'Invalid username' : 'اسم مستخدم غير صالح');
        } else {
            flash('error', $isEn ? 'Check the fields (password min 4)' : 'تحقق من الحقول (كلمة المرور 4 أحرف على الأقل)');
        }
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

    if ($action === 'import_sas') {
        if (!function_exists('sas_is_ready') || !sas_is_ready($config)) {
            flash('error', $isEn ? 'Enable SAS in settings first' : 'فعّل ربط SAS من الإعدادات أولاً');
            redirect('agents.php');
        }
        $api = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
        if (!$api) {
            flash('error', $isEn ? 'No SAS connection' : 'ماكو اتصال بالساس');
            redirect('agents.php');
        }
        unset($_SESSION['sas_managers_ui'], $_SESSION['sas_managers_ui_at']);
        $managers = function_exists('sas_managers_for_ui') ? sas_managers_for_ui($api) : array();
        if (!$managers) {
            flash('error', $isEn ? 'No managers returned from SAS' : 'ماكو وكلاء راجعين من الساس');
            redirect('agents.php');
        }

        $byUser = array();
        $bySas = array();
        try {
            $st = $pdo->query('SELECT id, username, sas_manager_id FROM admin_users WHERE role = "agent"');
            foreach ($st->fetchAll() as $er) {
                $byUser[strtolower((string) $er['username'])] = (int) $er['id'];
                if (!empty($er['sas_manager_id'])) {
                    $bySas[(int) $er['sas_manager_id']] = (int) $er['id'];
                }
            }
        } catch (Exception $e) {
            $st = $pdo->query('SELECT id, username FROM admin_users WHERE role = "agent"');
            foreach ($st->fetchAll() as $er) {
                $byUser[strtolower((string) $er['username'])] = (int) $er['id'];
            }
        }

        $added = 0;
        $linked = 0;
        $skipped = 0;
        $temps = array();
        foreach ($managers as $m) {
            $mid = isset($m['id']) ? (int) $m['id'] : 0;
            $rawName = isset($m['name']) ? trim((string) $m['name']) : '';
            if ($mid <= 0 || $rawName === '') {
                continue;
            }
            if (isset($bySas[$mid])) {
                $linked++;
                continue;
            }
            $username = preg_replace('/[^A-Za-z0-9._\-]/', '', $rawName);
            if (strlen($username) < 2) {
                $username = 'mgr' . $mid;
            }
            if (strlen($username) > 40) {
                $username = substr($username, 0, 40);
            }
            $ukey = strtolower($username);
            if (isset($byUser[$ukey])) {
                try {
                    $pdo->prepare('UPDATE admin_users SET sas_manager_id = :m WHERE id = :id')
                        ->execute(array(':m' => $mid, ':id' => $byUser[$ukey]));
                    $bySas[$mid] = $byUser[$ukey];
                    $linked++;
                } catch (Exception $e) {
                    $skipped++;
                }
                continue;
            }
            $pass = 'Ag' . $mid . '!' . substr(md5($username . $mid), 0, 4);
            $res = create_admin_user($pdo, $username, $rawName, $pass, 'agent');
            if ($res === 'ok') {
                $newId = (int) $pdo->lastInsertId();
                try {
                    $pdo->prepare('UPDATE admin_users SET sas_manager_id = :m WHERE id = :id')
                        ->execute(array(':m' => $mid, ':id' => $newId));
                } catch (Exception $e) {
                }
                $byUser[$ukey] = $newId;
                $bySas[$mid] = $newId;
                $temps[] = $username . ' / ' . $pass;
                $added++;
            } elseif ($res === 'taken') {
                $skipped++;
            } else {
                $skipped++;
            }
        }
        $msg = $isEn
            ? ('SAS import: ' . $added . ' new, ' . $linked . ' linked')
            : ('استيراد الساس: ' . $added . ' جديد، ' . $linked . ' مربوط');
        if ($skipped > 0) {
            $msg .= $isEn ? (', skipped ' . $skipped) : ('، تخطي ' . $skipped);
        }
        if ($temps) {
            $msg .= $isEn
                ? ('. Temp passwords: ' . implode(' · ', $temps))
                : ('. كلمات مرور مؤقتة: ' . implode(' · ', $temps));
        }
        flash('success', $msg);
        redirect('agents.php');
    }
}

$agents = list_agent_users($pdo, false);
$gmMissingPrices = 0;
if (function_exists('is_group_manager_user') && is_group_manager_user()) {
    $team = group_manager_team_ids($pdo);
    $agents = array_values(array_filter($agents, function ($a) use ($team) {
        return in_array((int) $a['id'], $team, true);
    }));
    if (function_exists('agent_card_prices_list')) {
        foreach ($agents as $a) {
            $pr = agent_card_prices_list($pdo, (int) $a['id']);
            if (!$pr) {
                $gmMissingPrices++;
            }
        }
    }
}
$accountants = list_accountant_users($pdo, false);
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

$sasManagers = array();
$sasReady = function_exists('sas_is_ready') && sas_is_ready($config);
if ($sasReady && function_exists('sas_page_connector') && function_exists('sas_managers_for_ui')) {
    try {
        $apiMgr = sas_page_connector($config);
        if ($apiMgr) {
            $sasManagers = sas_managers_for_ui($apiMgr);
        }
    } catch (Exception $e) {
        $sasManagers = array();
    }
}

render_header($isEn ? 'Agents' : 'الوكلاء', 'agents');
?>
<div class="panel">
    <?php if (!empty($gmMissingPrices)): ?>
    <div class="alert alert-error" style="font-weight:700">
        <?php echo e($isEn
            ? ($gmMissingPrices . ' agents have no prices. Set package/card prices so profit is calculated.')
            : ($gmMissingPrices . ' وكلاء بدون تسعير. لازم تسعر الباقات/الكروت حتى ينحسب الربح.')); ?>
        — <a href="agent_prices.php"><?php echo e($isEn ? 'Set prices' : 'تسعير'); ?></a>
    </div>
    <?php endif; ?>
    <?php if (function_exists('is_group_manager_user') && is_group_manager_user()): ?>
    <p class="meta"><a href="profit_report.php"><?php echo e($isEn ? 'Activation profit report' : 'تقرير أرباح التفعيل'); ?></a></p>
    <?php endif; ?>
    <p class="meta" style="margin-top:0">
        <?php echo e($isEn
            ? 'Agents log in and only see their own subscribers. Import them from SAS managers, then edit passwords and status.'
            : 'الوكيل يدخل للنظام ويشوف مشتركيه فقط. نستوردهم من مدراء الساس، ونعدّل كلمة المرور والحالة والعمليات.'); ?>
    </p>

    <?php if ($sasReady): ?>
    <form method="post" style="margin-bottom:16px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="import_sas">
        <div class="actions" style="margin:0;align-items:center;gap:10px;flex-wrap:wrap">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Import agents from SAS' : 'استيراد الوكلاء من الساس'); ?></button>
            <span style="color:#6b7a88;font-weight:600;font-size:13px">
                <?php echo e($isEn
                    ? (count($sasManagers) . ' manager(s) available from SAS')
                    : (count($sasManagers) . ' مدير متاح من الساس')); ?>
            </span>
        </div>
    </form>
    <?php endif; ?>

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
                <th>SAS</th>
                <th><?php echo e($isEn ? 'Subscribers' : 'المشتركين'); ?></th>
                <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                <th><?php echo e($isEn ? 'Actions' : 'إجراءات'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$agents): ?>
                <tr><td colspan="7"><?php echo e($isEn ? 'No agents yet' : 'ماكو وكلاء بعد'); ?></td></tr>
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
                            <select name="sas_manager_id" style="min-width:120px">
                                <option value="0"><?php echo e($isEn ? '— none —' : '— بدون —'); ?></option>
                                <?php
                                $curSas = isset($a['sas_manager_id']) ? (int) $a['sas_manager_id'] : 0;
                                foreach ($sasManagers as $sm):
                                    $smid = (int) $sm['id'];
                                    $sel = ($curSas === $smid) ? ' selected' : '';
                                ?>
                                    <option value="<?php echo $smid; ?>"<?php echo $sel; ?>>
                                        <?php echo e($sm['name'] . ' (#' . $smid . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($curSas > 0):
                                    $found = false;
                                    foreach ($sasManagers as $sm) {
                                        if ((int) $sm['id'] === $curSas) { $found = true; break; }
                                    }
                                    if (!$found):
                                ?>
                                    <option value="<?php echo $curSas; ?>" selected>#<?php echo $curSas; ?></option>
                                <?php endif; endif; ?>
                            </select>
                        </td>
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
                            <div style="margin-top:6px">
                                <input name="phone" value="<?php echo e(isset($a['phone']) ? $a['phone'] : ''); ?>" placeholder="<?php echo e($isEn ? 'WhatsApp phone' : 'هاتف واتساب'); ?>">
                            </div>
                        </td>
                        <td class="actions" style="gap:6px">
                                <button class="btn sm" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
                            </form>
                            <a class="btn ghost sm" href="agent_prices.php?agent=<?php echo $aid; ?>"><?php echo e($isEn ? 'Prices' : 'تسعير'); ?></a>
                            <form method="post" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Disable this agent SAS users? Debts stay.' : 'تعطيل يوزرات ساس الوكيل؟ الديون تبقى.'); ?>);">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="disable_sas">
                                <input type="hidden" name="confirm_disable" value="1">
                                <input type="hidden" name="user_id" value="<?php echo $aid; ?>">
                                <button class="btn ghost sm" type="submit"><?php echo e($isEn ? 'Disable SAS' : 'تعطيل ساس'); ?></button>
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

    <?php if ($agents && count($agents) >= 2): ?>
    <h2 style="margin-top:28px"><?php echo e($isEn ? 'Transfer subscribers between agents' : 'نقل مشتركين بين وكلاء'); ?></h2>
    <p class="meta"><?php echo e($isEn ? 'Same company only — debts stay linked to subscribers.' : 'داخل نفس الشركة فقط — الديون تبقى على المشتركين.'); ?></p>
    <form method="post" class="form-grid" style="margin-bottom:22px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="transfer_subs">
        <div>
            <label><?php echo e($isEn ? 'From agent' : 'من وكيل'); ?></label>
            <select name="from_agent_id" required>
                <?php foreach ($agents as $ag): ?>
                    <option value="<?php echo (int) $ag['id']; ?>"><?php echo e($ag['display_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?php echo e($isEn ? 'To agent' : 'إلى وكيل'); ?></label>
            <select name="to_agent_id" required>
                <?php foreach ($agents as $ag): ?>
                    <option value="<?php echo (int) $ag['id']; ?>"><?php echo e($ag['display_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions" style="align-items:end">
            <button class="btn" type="submit" onclick="return confirm(<?php echo json_encode($isEn ? 'Move all subscribers?' : 'نقل كل المشتركين؟'); ?>);">
                <?php echo e($isEn ? 'Transfer' : 'نقل'); ?>
            </button>
        </div>
    </form>
    <?php endif; ?>

    <h2 style="margin-top:28px"><?php echo e($isEn ? 'Add accountant' : 'إضافة محاسب'); ?></h2>
    <p class="meta"><?php echo e($isEn
        ? 'Accountants manage card transfers and payments for one linked agent.'
        : 'المحاسب يدير تحويل الكروت ودفعات وكيل واحد مرتبط.'); ?></p>
    <form method="post" class="form-grid" style="margin-bottom:22px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="create_accountant">
        <div>
            <label><?php echo e($isEn ? 'Username' : 'اسم الدخول'); ?></label>
            <input name="username" required pattern="[A-Za-z0-9._\-]{2,40}" placeholder="acct1">
        </div>
        <div>
            <label><?php echo e($isEn ? 'Display name' : 'الاسم الظاهر'); ?></label>
            <input name="display_name" required>
        </div>
        <div>
            <label><?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?></label>
            <input name="password" type="password" required minlength="4">
        </div>
        <div>
            <label><?php echo e($isEn ? 'Linked agent' : 'الوكيل المرتبط'); ?></label>
            <select name="linked_agent_id">
                <option value="0"><?php echo e($isEn ? '— select —' : '— اختر —'); ?></option>
                <?php foreach ($agents as $ag): ?>
                    <option value="<?php echo (int) $ag['id']; ?>"><?php echo e($ag['display_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions" style="align-items:end">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Add accountant' : 'إضافة محاسب'); ?></button>
        </div>
    </form>

    <h2><?php echo e($isEn ? 'Accountants' : 'المحاسبين'); ?></h2>
    <div class="table-wrap">
        <table class="data-table table-compact">
            <thead>
            <tr>
                <th>#</th>
                <th><?php echo e($isEn ? 'Name' : 'الاسم'); ?></th>
                <th><?php echo e($isEn ? 'Username' : 'الدخول'); ?></th>
                <th><?php echo e($isEn ? 'Linked agent' : 'الوكيل المرتبط'); ?></th>
                <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                <th><?php echo e($isEn ? 'Actions' : 'إجراءات'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$accountants): ?>
                <tr><td colspan="6"><?php echo e($isEn ? 'No accountants yet' : 'ماكو محاسبين بعد'); ?></td></tr>
            <?php else: ?>
                <?php foreach ($accountants as $ac): ?>
                    <?php $acid = (int) $ac['id']; ?>
                    <tr>
                        <td><?php echo $acid; ?></td>
                        <td>
                            <form method="post" class="inline-agent-form">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="save_accountant">
                                <input type="hidden" name="user_id" value="<?php echo $acid; ?>">
                                <input name="display_name" value="<?php echo e($ac['display_name']); ?>" required>
                        </td>
                        <td><?php echo e($ac['username']); ?></td>
                        <td>
                            <select name="linked_agent_id" style="min-width:140px">
                                <option value="0"><?php echo e($isEn ? '— none —' : '— بدون —'); ?></option>
                                <?php
                                $curLinked = isset($ac['linked_agent_id']) ? (int) $ac['linked_agent_id'] : 0;
                                foreach ($agents as $ag):
                                    $gid = (int) $ag['id'];
                                    ?>
                                    <option value="<?php echo $gid; ?>"<?php echo $curLinked === $gid ? ' selected' : ''; ?>>
                                        <?php echo e($ag['display_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <label class="toggle" style="margin:0">
                                <input type="checkbox" name="is_active" value="1" <?php echo (int) $ac['is_active'] === 1 ? 'checked' : ''; ?>>
                                <span class="toggle-ui" aria-hidden="true"></span>
                                <span class="toggle-text"><?php echo e((int) $ac['is_active'] === 1 ? ($isEn ? 'Active' : 'فعال') : ($isEn ? 'Off' : 'موقوف')); ?></span>
                            </label>
                            <div style="margin-top:6px">
                                <input name="password" type="password" minlength="4" placeholder="<?php echo e($isEn ? 'New password (optional)' : 'كلمة مرور جديدة (اختياري)'); ?>">
                            </div>
                        </td>
                        <td class="actions">
                                <button class="btn sm" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
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
