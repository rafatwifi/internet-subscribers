<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_perm('agents');
ensure_subscriber_agent_column($pdo);
ensure_admin_users_table($pdo);

$isEn = ($lang === 'en');
$me = current_admin();
$meId = $me ? (int) $me['id'] : 0;
$agencyLogin = '';
try {
    $tidAgency = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $stAgency = $pdo->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
    $stAgency->execute(array(':id' => $tidAgency));
    $agencyLogin = trim((string) $stAgency->fetchColumn());
} catch (Exception $e) {
}
if ($agencyLogin === '' && $me && !empty($me['username'])) {
    $agencyLogin = trim((string) $me['username']);
}
$accountantPrefix = $agencyLogin !== '' ? ($agencyLogin . '@') : '';

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
        $row = get_admin_user($pdo, $uid);
        if (!$row || normalize_admin_role($row['role']) !== 'accountant') {
            flash('error', $isEn ? 'Accountant not found' : 'المحاسب غير موجود');
            redirect('agents.php?view=accountant');
        }
        $linked = (int) $meId;
        $canAct = post('can_activate') === '1' ? 1 : 0;
        update_admin_user_meta($pdo, $uid, $display !== '' ? $display : $row['display_name'], 'accountant', $linked);
        $pdo->prepare('UPDATE admin_users SET is_active = :a, can_activate = :c, updated_at = NOW() WHERE id = :id AND role = "accountant"')
            ->execute(array(':a' => $active, ':c' => $canAct, ':id' => $uid));
        $newPass = (string) post('password', '');
        if (strlen($newPass) >= 4) {
            change_user_password($pdo, $uid, $newPass);
        }
        flash('success', $isEn ? 'Accountant updated' : 'تم تعديل المحاسب');
        redirect('agents.php?view=accountant');
    }

    if ($action === 'create_accountant') {
        $username = trim((string) post('username', ''));
        $display = trim((string) post('display_name', ''));
        $password = (string) post('password', '');
        $linked = (int) $meId;
        $canAct = post('can_activate') === '1' ? 1 : 0;
        $prefixOk = $accountantPrefix !== '' && stripos($username, $accountantPrefix) === 0
            && preg_match('/^[A-Za-z0-9._@-]{2,60}$/', $username)
            && preg_match('/^' . preg_quote($accountantPrefix, '/') . '[A-Za-z0-9._-]{1,30}$/', $username);
        if (!$prefixOk) {
            flash('error', $isEn
                ? ('Username must start with ' . $accountantPrefix)
                : ('اسم الدخول لازم يبدأ بـ ' . $accountantPrefix . ' مثل ' . $accountantPrefix . 'account'));
            redirect('agents.php?view=accountant');
        }
        $res = create_admin_user($pdo, $username, $display, $password, 'accountant', $linked);
        if ($res === 'ok') {
            try {
                $newId = (int) $pdo->query('SELECT id FROM admin_users WHERE username = ' . $pdo->quote($username) . ' LIMIT 1')->fetchColumn();
                if ($newId > 0) {
                    $pdo->prepare('UPDATE admin_users SET can_activate = :c, linked_agent_id = :l WHERE id = :id')
                        ->execute(array(':c' => $canAct, ':l' => $linked, ':id' => $newId));
                }
            } catch (Exception $e) {
            }
            flash('success', $isEn ? 'Accountant created' : 'تم إضافة المحاسب');
        } elseif ($res === 'taken') {
            flash('error', $isEn ? 'Username taken' : 'اسم المستخدم مستخدم');
        } elseif ($res === 'username') {
            flash('error', $isEn ? 'Invalid username' : 'اسم مستخدم غير صالح');
        } else {
            flash('error', $isEn ? 'Check the fields (password min 4)' : 'تحقق من الحقول (كلمة المرور 4 أحرف على الأقل)');
        }
        redirect('agents.php?view=accountant');
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

$sasManagers = array();
$sasReady = function_exists('sas_is_ready') && sas_is_ready($config);
$canEditAgents = !(function_exists('is_agent_user') && is_agent_user());
if ($sasReady && $canEditAgents && function_exists('sas_page_connector') && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $pullAt = isset($_SESSION['agents_auto_import_at']) ? (int) $_SESSION['agents_auto_import_at'] : 0;
    if ($pullAt <= 0 || (time() - $pullAt) > 90) {
        try {
            $apiPull = sas_page_connector($config);
        } catch (Exception $e) {
            $apiPull = null;
        }
        if ($apiPull) {
            $_SESSION['agents_auto_import_at'] = time();
            $rawManagers = array();
            if (method_exists($apiPull, 'getManagers')) {
                $raw = $apiPull->getManagers();
                if (!(function_exists('sas_response_is_error') && sas_response_is_error($raw))) {
                    $rawManagers = is_array($raw) ? $raw : array();
                    if (isset($rawManagers['data']) && is_array($rawManagers['data'])) {
                        $rawManagers = $rawManagers['data'];
                    }
                }
            }
            $parsed = array();
            $ownerIds = array();
            $ownerNames = array();
            $meUser = current_admin();
            if ($meUser && !empty($meUser['username'])) {
                $ownerNames[] = strtolower(trim((string) $meUser['username']));
            }
            if ($meUser && !empty($meUser['sas_manager_id'])) {
                $ownerIds[] = (int) $meUser['sas_manager_id'];
            }
            if (method_exists($apiPull, 'getLoginUser')) {
                $loginUser = $apiPull->getLoginUser();
                if (is_array($loginUser)) {
                    foreach (array('id', 'user_id', 'manager_id') as $ik) {
                        if (isset($loginUser[$ik]) && is_numeric($loginUser[$ik]) && (int) $loginUser[$ik] > 0) {
                            $ownerIds[] = (int) $loginUser[$ik];
                            break;
                        }
                    }
                    if (!empty($loginUser['username'])) {
                        $ownerNames[] = strtolower(trim((string) $loginUser['username']));
                    }
                }
            }
            $tidPull = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
            try {
                $tn = $pdo->prepare('SELECT sas_username FROM tenants WHERE id = :id LIMIT 1');
                $tn->execute(array(':id' => $tidPull));
                $tnUser = trim((string) $tn->fetchColumn());
                if ($tnUser !== '') {
                    $ownerNames[] = strtolower($tnUser);
                }
            } catch (Exception $e) {
            }
            if (function_exists('tenant_sas_accounts_list')) {
                try {
                    foreach (tenant_sas_accounts_list($pdo, $tidPull) as $acc) {
                        $au = isset($acc['sas_username']) ? strtolower(trim((string) $acc['sas_username'])) : '';
                        if ($au !== '') {
                            $ownerNames[] = $au;
                        }
                    }
                } catch (Exception $e) {
                }
            }
            $ownerIds = array_values(array_unique(array_filter($ownerIds)));
            $ownerNames = array_values(array_unique(array_filter($ownerNames)));
            $hasParent = false;
            foreach ($rawManagers as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $mid = 0;
                if (isset($m['id']) && is_numeric($m['id'])) {
                    $mid = (int) $m['id'];
                } elseif (function_exists('sas_extract_user_id')) {
                    $mid = (int) sas_extract_user_id($m);
                }
                if ($mid <= 0) {
                    continue;
                }
                $rawName = !empty($m['username']) ? trim((string) $m['username']) : '';
                if ($rawName === '' && function_exists('sas_row_name')) {
                    $rawName = trim((string) sas_row_name($m));
                }
                $parentId = 0;
                foreach (array('parent_id', 'manager_id') as $pk) {
                    if (isset($m[$pk]) && is_numeric($m[$pk]) && (int) $m[$pk] > 0) {
                        $parentId = (int) $m[$pk];
                        break;
                    }
                }
                $parentName = '';
                foreach (array('parent_username', 'parent_name', 'manager_username') as $nk) {
                    if (!empty($m[$nk]) && !is_array($m[$nk])) {
                        $parentName = trim((string) $m[$nk]);
                        break;
                    }
                }
                if ($parentId > 0 || $parentName !== '') {
                    $hasParent = true;
                }
                $parsed[] = array(
                    'id' => $mid,
                    'name' => $rawName !== '' ? $rawName : ('#' . $mid),
                    'parent_id' => $parentId,
                    'parent_name' => $parentName,
                );
                $sasManagers[] = array('id' => $mid, 'name' => $rawName !== '' ? $rawName : ('#' . $mid));
            }
            $byUser = array();
            $bySas = array();
            try {
                $st = $pdo->prepare('SELECT id, username, sas_manager_id FROM admin_users WHERE role = "agent" AND tenant_id = :t');
                $st->execute(array(':t' => $tidPull));
                foreach ($st->fetchAll() as $er) {
                    $byUser[strtolower((string) $er['username'])] = (int) $er['id'];
                    if (!empty($er['sas_manager_id'])) {
                        $bySas[(int) $er['sas_manager_id']] = (int) $er['id'];
                    }
                }
            } catch (Exception $e) {
            }
            $added = 0;
            $temps = array();
            foreach ($parsed as $m) {
                $mid = (int) $m['id'];
                $rawName = trim((string) $m['name']);
                if ($mid <= 0 || $rawName === '' || $rawName[0] === '#') {
                    continue;
                }
                $nameKey = strtolower($rawName);
                if (in_array($nameKey, $ownerNames, true) || in_array($mid, $ownerIds, true)) {
                    continue;
                }
                if ($hasParent) {
                    $belongs = false;
                    if ($m['parent_id'] > 0 && in_array((int) $m['parent_id'], $ownerIds, true)) {
                        $belongs = true;
                    }
                    $pn = strtolower(trim((string) $m['parent_name']));
                    if ($pn !== '' && in_array($pn, $ownerNames, true)) {
                        $belongs = true;
                    }
                    if (!$belongs) {
                        continue;
                    }
                }
                if (isset($bySas[$mid])) {
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
                        $pdo->prepare('UPDATE admin_users SET sas_manager_id = :m WHERE id = :id AND tenant_id = :t')
                            ->execute(array(':m' => $mid, ':id' => $byUser[$ukey], ':t' => $tidPull));
                        $bySas[$mid] = $byUser[$ukey];
                    } catch (Exception $e) {
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
                }
            }
            if ($added > 0) {
                $msg = $isEn
                    ? ('Imported from SAS: ' . $added)
                    : ('تم استيراد وكلاء الساس: ' . $added);
                if ($temps) {
                    $msg .= $isEn
                        ? ('. Temp passwords: ' . implode(' · ', $temps))
                        : ('. كلمات مرور مؤقتة: ' . implode(' · ', $temps));
                }
                flash('success', $msg);
            }
        }
    }
}

$agents = list_agent_users($pdo, false);
try {
    $tidList = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $gmSt = $pdo->prepare(
        'SELECT id, username, display_name, role, is_active, created_at, updated_at, sas_manager_id, tenant_id, phone
         FROM admin_users WHERE role = "group_manager" AND tenant_id = :t
         ORDER BY display_name ASC, id ASC'
    );
    $gmSt->execute(array(':t' => $tidList));
    $seenIds = array();
    foreach ($agents as $ag) {
        $seenIds[(int) $ag['id']] = true;
    }
    foreach ($gmSt->fetchAll() as $gm) {
        if (empty($seenIds[(int) $gm['id']])) {
            $agents[] = $gm;
        }
    }
} catch (Exception $e) {
}
$childCounts = array();
try {
    $tidKids = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $kidSt = $pdo->prepare(
        'SELECT reports_to_user_id AS pid, COUNT(*) AS c
         FROM admin_users
         WHERE tenant_id = :t AND reports_to_user_id IS NOT NULL AND reports_to_user_id > 0
           AND role IN ("agent", "group_manager")
         GROUP BY reports_to_user_id'
    );
    $kidSt->execute(array(':t' => $tidKids));
    foreach ($kidSt->fetchAll() as $kr) {
        $childCounts[(int) $kr['pid']] = (int) $kr['c'];
    }
} catch (Exception $e) {
}
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

if (empty($sasManagers)) {
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
}

$accOnly = (isset($_GET['view']) && $_GET['view'] === 'accountant');
$childAgents = function_exists('admin_user_child_count')
    ? admin_user_child_count($pdo, $meId, function_exists('current_tenant_id') ? (int) current_tenant_id() : 1)
    : 0;
if ($childAgents <= 0) {
    $accOnly = true;
}
render_header($accOnly ? ($isEn ? 'Accountant' : 'المحاسب') : ($isEn ? 'Agents' : 'الوكلاء'), $accOnly ? 'accountants' : 'agents');
?>
<div class="panel">
    <?php if (!$accOnly && !empty($gmMissingPrices)): ?>
    <div class="alert alert-error" style="font-weight:700">
        <?php echo e($isEn
            ? ($gmMissingPrices . ' agents have no prices.')
            : ($gmMissingPrices . ' وكلاء بدون تسعير.')); ?>
        — <a href="agent_prices.php"><?php echo e($isEn ? 'Set prices' : 'تسعير'); ?></a>
    </div>
    <?php endif; ?>
    <?php if (!$accOnly): ?>
    <div class="actions" style="margin:0 0 14px;gap:8px;flex-wrap:wrap">
        <button class="btn" type="button" id="agentAddToggle"><?php echo e($isEn ? 'Add agent' : 'إضافة وكيل'); ?></button>
        <?php if (function_exists('is_group_manager_user') && is_group_manager_user()): ?>
        <a class="btn ghost" href="profit_report.php"><?php echo e($isEn ? 'Profits' : 'الأرباح'); ?></a>
        <?php endif; ?>
    </div>
    <form method="post" id="agentAddBox" class="form-grid" hidden style="margin-bottom:16px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="create">
        <div>
            <label><?php echo e($isEn ? 'Username' : 'اسم الدخول'); ?></label>
            <input name="username" required pattern="[A-Za-z0-9._\-]{2,40}" placeholder="agent1">
        </div>
        <div>
            <label><?php echo e($isEn ? 'Name' : 'الاسم'); ?></label>
            <input name="display_name" required>
        </div>
        <div>
            <label><?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?></label>
            <input name="password" type="password" required minlength="4">
        </div>
        <div class="actions" style="align-items:end">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
        </div>
    </form>

    <?php if (!$agents): ?>
        <p class="meta"><?php echo e($isEn ? 'No agents yet.' : 'ماكو وكلاء بعد.'); ?></p>
    <?php else: ?>
    <div class="sas-acc-list">
        <?php foreach ($agents as $a):
            $aid = (int) $a['id'];
            $curSas = isset($a['sas_manager_id']) ? (int) $a['sas_manager_id'] : 0;
            $active = (int) $a['is_active'] === 1;
            ?>
        <div class="sas-acc-card">
            <div>
                <div class="sas-acc-name"><?php echo e($a['display_name']); ?></div>
                <div class="sas-acc-meta ltr"><?php echo e($a['username']); ?> · <?php echo isset($counts[$aid]) ? (int) $counts[$aid] : 0; ?> <?php echo e($isEn ? 'subscribers' : 'مشترك'); ?></div>
            </div>
            <div class="sas-acc-actions">
                <span class="<?php echo $active ? 'sas-logged' : 'sas-logged-off'; ?>"><?php echo e($active ? ($isEn ? 'Active' : 'فعال') : ($isEn ? 'Off' : 'موقوف')); ?></span>
                <a class="btn ghost sm" href="agent_prices.php?agent=<?php echo $aid; ?>"><?php echo e($isEn ? 'Prices' : 'تسعير'); ?></a>
                <?php if (!empty($childCounts[$aid]) && $active && function_exists('is_admin_user') && is_admin_user()): ?>
                <form method="post" action="impersonate.php" class="inline-form">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="user_id" value="<?php echo $aid; ?>">
                    <button class="btn sm" type="submit"><?php echo e($isEn ? 'Login as' : 'دخول'); ?></button>
                </form>
                <?php endif; ?>
                <button class="btn ghost sm" type="button" data-agent-edit="<?php echo $aid; ?>"><?php echo e($isEn ? 'Edit' : 'تعديل'); ?></button>
                <form method="post" class="inline-form" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Delete this agent?' : 'تحذف هذا الوكيل؟'); ?>);">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?php echo $aid; ?>">
                    <button class="btn danger sm" type="submit"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                </form>
            </div>
            <form method="post" id="agentEdit<?php echo $aid; ?>" hidden style="flex:1 1 100%;display:none;gap:8px;flex-wrap:wrap;align-items:end">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" value="<?php echo $aid; ?>">
                <input name="display_name" value="<?php echo e($a['display_name']); ?>" required placeholder="<?php echo e($isEn ? 'Name' : 'الاسم'); ?>" style="max-width:160px">
                <select name="sas_manager_id" style="max-width:180px">
                    <option value="0"><?php echo e($isEn ? 'No SAS' : 'بدون ساس'); ?></option>
                    <?php foreach ($sasManagers as $sm):
                        $smid = (int) $sm['id'];
                        ?>
                        <option value="<?php echo $smid; ?>"<?php echo $curSas === $smid ? ' selected' : ''; ?>><?php echo e($sm['name']); ?></option>
                    <?php endforeach; ?>
                    <?php if ($curSas > 0):
                        $found = false;
                        foreach ($sasManagers as $sm) {
                            if ((int) $sm['id'] === $curSas) { $found = true; break; }
                        }
                        if (!$found): ?>
                        <option value="<?php echo $curSas; ?>" selected>#<?php echo $curSas; ?></option>
                    <?php endif; endif; ?>
                </select>
                <input name="password" type="password" minlength="4" placeholder="<?php echo e($isEn ? 'New password' : 'رمز جديد'); ?>" style="max-width:140px">
                <input name="phone" value="<?php echo e(isset($a['phone']) ? $a['phone'] : ''); ?>" placeholder="<?php echo e($isEn ? 'Phone' : 'الهاتف'); ?>" style="max-width:140px">
                <label class="toggle" style="margin:0">
                    <input type="checkbox" name="is_active" value="1" <?php echo $active ? 'checked' : ''; ?>>
                    <span class="toggle-ui" aria-hidden="true"></span>
                </label>
                <button class="btn sm" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!$accOnly): ?>
    <div class="actions" style="margin-top:18px;gap:8px">
        <?php if ($agents && count($agents) >= 2): ?>
        <button class="btn ghost sm" type="button" id="agentMoveToggle"><?php echo e($isEn ? 'Move subscribers' : 'نقل مشتركين'); ?></button>
        <?php endif; ?>
        <button class="btn ghost sm" type="button" id="agentAccToggle"><?php echo e($isEn ? 'Accountants' : 'المحاسبين'); ?> (<?php echo count($accountants); ?>)</button>
    </div>

    <?php if ($agents && count($agents) >= 2): ?>
    <form method="post" id="agentMoveBox" class="form-grid" hidden style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="transfer_subs">
        <div>
            <label><?php echo e($isEn ? 'From' : 'من'); ?></label>
            <select name="from_agent_id" required>
                <?php foreach ($agents as $ag): ?>
                    <option value="<?php echo (int) $ag['id']; ?>"><?php echo e($ag['display_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label><?php echo e($isEn ? 'To' : 'إلى'); ?></label>
            <select name="to_agent_id" required>
                <?php foreach ($agents as $ag): ?>
                    <option value="<?php echo (int) $ag['id']; ?>"><?php echo e($ag['display_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions" style="align-items:end">
            <button class="btn" type="submit" onclick="return confirm(<?php echo json_encode($isEn ? 'Move all subscribers?' : 'نقل كل المشتركين؟'); ?>);"><?php echo e($isEn ? 'Move' : 'نقل'); ?></button>
        </div>
    </form>
    <?php endif; ?>
    <?php endif; ?>

    <div id="agentAccBox" style="margin-top:12px<?php echo $accOnly ? '' : ';display:none'; ?>">
        <form method="post" class="form-grid" style="margin-bottom:12px">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="create_accountant">
            <div>
                <label><?php echo e($isEn ? 'Username' : 'اسم الدخول'); ?></label>
                <input class="ltr" name="username" required autocomplete="off"
                       placeholder="<?php echo e($accountantPrefix . 'account'); ?>"
                       pattern="<?php echo e(preg_quote($accountantPrefix, '/') . '[A-Za-z0-9._-]{1,30}'); ?>"
                       title="<?php echo e($isEn ? ('Must start with ' . $accountantPrefix) : ('لازم يبدأ بـ ' . $accountantPrefix)); ?>">
                <div class="hint" style="color:#64748b;font-size:12px;margin-top:4px"><?php echo e($isEn
                    ? ('Example: ' . $accountantPrefix . 'account. System user only, not SAS.')
                    : ('مثال: ' . $accountantPrefix . 'account. ينضاف بالنظام فقط، مو بالساس.')); ?></div>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Name' : 'الاسم'); ?></label>
                <input name="display_name" required>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Password' : 'كلمة المرور'); ?></label>
                <input name="password" type="password" required minlength="4">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Can activate' : 'يقدر يفعّل'); ?></label>
                <label class="toggle" style="display:flex;align-items:center;gap:8px;margin-top:8px">
                    <input type="checkbox" name="can_activate" value="1">
                    <span class="toggle-ui"></span>
                    <span><?php echo e($isEn ? 'Allow activation' : 'السماح بالتفعيل'); ?></span>
                </label>
            </div>
            <div class="actions" style="align-items:end">
                <button class="btn" type="submit"><?php echo e($isEn ? 'Add' : 'إضافة'); ?></button>
            </div>
        </form>
        <?php foreach ($accountants as $ac):
            if ((int) $ac['linked_agent_id'] !== $meId && (int) $ac['linked_agent_id'] !== 0) {
                continue;
            }
            $acid = (int) $ac['id'];
            $canAct = !empty($ac['can_activate']);
            ?>
        <form method="post" class="sas-acc-card" style="margin-bottom:8px">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_accountant">
            <input type="hidden" name="user_id" value="<?php echo $acid; ?>">
            <input name="display_name" value="<?php echo e($ac['display_name']); ?>" required style="max-width:140px">
            <span class="meta ltr"><?php echo e($ac['username']); ?></span>
            <label class="toggle" style="margin:0">
                <input type="checkbox" name="can_activate" value="1" <?php echo $canAct ? 'checked' : ''; ?>>
                <span class="toggle-ui" aria-hidden="true"></span>
                <span><?php echo e($isEn ? 'Activate' : 'تفعيل'); ?></span>
            </label>
            <input name="password" type="password" minlength="4" placeholder="<?php echo e($isEn ? 'Password' : 'رمز'); ?>" style="max-width:110px">
            <label class="toggle" style="margin:0">
                <input type="checkbox" name="is_active" value="1" <?php echo (int) $ac['is_active'] === 1 ? 'checked' : ''; ?>>
                <span class="toggle-ui" aria-hidden="true"></span>
            </label>
            <button class="btn sm" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
        </form>
        <?php endforeach; ?>
    </div>
    <style>
    .sas-acc-list { display:flex; flex-direction:column; gap:10px; }
    .sas-acc-card { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; border:1px solid #e6ebf2; border-radius:14px; padding:12px 14px; background:#fff; }
    .sas-acc-name { font-weight:800; }
    .sas-acc-meta { color:#64748b; font-size:13px; margin-top:2px; }
    .sas-acc-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .sas-logged { background:#dcfce7; color:#166534; font-weight:800; border-radius:999px; padding:4px 10px; font-size:13px; }
    .sas-logged-off { background:#f1f5f9; color:#64748b; font-weight:700; border-radius:999px; padding:4px 10px; font-size:13px; }
    </style>
    <script>
    (function () {
      function tog(btnId, boxId) {
        var b = document.getElementById(btnId);
        var x = document.getElementById(boxId);
        if (!b || !x) return;
        b.addEventListener('click', function () { x.hidden = !x.hidden; });
      }
      tog('agentAddToggle', 'agentAddBox');
      tog('agentMoveToggle', 'agentMoveBox');
      tog('agentAccToggle', 'agentAccBox');
      var edits = document.querySelectorAll('[data-agent-edit]');
      for (var i = 0; i < edits.length; i++) {
        edits[i].addEventListener('click', function () {
          var f = document.getElementById('agentEdit' + this.getAttribute('data-agent-edit'));
          if (!f) return;
          var open = f.hidden;
          f.hidden = !open;
          f.style.display = open ? 'flex' : 'none';
        });
      }
    })();
    </script>
</div>
<?php render_footer(); ?>
