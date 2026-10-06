<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
$accAgentsOk = function_exists('is_accountant_user') && is_accountant_user()
    && function_exists('user_boss_has_downline') && user_boss_has_downline($pdo);
if (!$accAgentsOk) {
    require_perm('agents');
}
ensure_subscriber_agent_column($pdo);
ensure_admin_users_table($pdo);

$isEn = ($lang === 'en');
$me = current_admin();
$meId = $me ? (int) $me['id'] : 0;
$agentsAjaxStock = (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'card_stock');
if ($agentsAjaxStock) {
    if (function_exists('csrf_token')) {
        csrf_token();
    }
}
if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'GET' && function_exists('app_session_close')) {
    app_session_close();
}
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
    if (function_exists('is_accountant_user') && is_accountant_user()) {
        flash('error', $isEn ? 'View only' : 'عرض فقط');
        redirect('agents.php');
    }
    $action = post('action');

    if ($action === 'return_agent_cards') {
        $aid = (int) post('user_id', '0');
        $profileName = trim((string) post('profile_name', ''));
        $profileId = (int) post('profile_id', '0');
        $homeIdRet = function_exists('user_card_source_id') ? (int) user_card_source_id($pdo) : $meId;
        $rowRet = function_exists('get_admin_user') ? get_admin_user($pdo, $aid) : null;
        $myTidRet = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $rowTidRet = $rowRet && isset($rowRet['tenant_id']) ? (int) $rowRet['tenant_id'] : 0;
        $rowRoleRet = $rowRet ? normalize_admin_role($rowRet['role']) : '';
        if ($aid <= 0 || $aid === $homeIdRet || $profileName === '' || !$rowRet || $rowTidRet !== $myTidRet
            || !in_array($rowRoleRet, array('agent', 'group_manager'), true)) {
            flash('error', $isEn ? 'Choose an agent under you' : 'اختر وكيلاً من اللي تحتك');
            redirect('agents.php');
        }
        if (!function_exists('sas_make_connector') || !function_exists('sas_is_ready') || !sas_is_ready($config)
            || !function_exists('card_sas_stock_map') || !function_exists('card_user_sas_manager_id')) {
            flash('error', $isEn ? 'SAS is not ready' : 'الساس غير جاهز');
            redirect('agents.php');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        if (function_exists('card_sas_stock_forget')) {
            card_sas_stock_forget();
        }
        $mapRet = card_sas_stock_map($pdo, $config, $homeIdRet);
        $bagRet = isset($mapRet[$aid]) && is_array($mapRet[$aid]) ? $mapRet[$aid] : array();
        $qtyRet = 0;
        $wantRet = function_exists('card_name_key') ? card_name_key($profileName) : strtolower($profileName);
        foreach ($bagRet as $nmRet => $qRet) {
            $kRet = function_exists('card_name_key') ? card_name_key($nmRet) : strtolower(trim((string) $nmRet));
            if ($wantRet !== '' && $kRet === $wantRet) {
                $qtyRet = (int) $qRet;
                $profileName = trim((string) $nmRet);
                break;
            }
        }
        if ($qtyRet <= 0) {
            flash('error', $isEn ? 'This agent has no free cards in that package' : 'ماكو كروت شاغرة بهالفئة عند الوكيل');
            redirect('agents.php');
        }
        $wholesale = 0;
        $agentPrice = 0;
        if (function_exists('agent_card_price_get')) {
            $prRet = agent_card_price_get($pdo, $aid, $profileId, $profileName);
            if ($prRet) {
                $wholesale = (float) $prRet['wholesale_price'];
                $agentPrice = (float) $prRet['agent_price'];
                if ($profileId <= 0 && isset($prRet['profile_id'])) {
                    $profileId = (int) $prRet['profile_id'];
                }
            }
        }
        if ($agentPrice <= 0 && function_exists('agent_card_prices_list') && function_exists('card_price_soft_key')) {
            $wantSoft = card_price_soft_key($profileName);
            if ($wantSoft !== '') {
                foreach (agent_card_prices_list($pdo, $aid) as $rowSoft) {
                    $rowName = isset($rowSoft['profile_name']) ? (string) $rowSoft['profile_name'] : '';
                    if (card_price_soft_key($rowName) !== $wantSoft) {
                        continue;
                    }
                    $wholesale = isset($rowSoft['wholesale_price']) ? (float) $rowSoft['wholesale_price'] : 0;
                    $agentPrice = isset($rowSoft['agent_price']) ? (float) $rowSoft['agent_price'] : 0;
                    if ($profileId <= 0 && isset($rowSoft['profile_id'])) {
                        $profileId = (int) $rowSoft['profile_id'];
                    }
                    break;
                }
            }
        }
        $apiMove = sas_make_connector($config);
        if (!$apiMove || !method_exists($apiMove, 'moveUnusedCardsToOwner')) {
            flash('error', $isEn ? 'SAS is not ready' : 'الساس غير جاهز');
            redirect('agents.php');
        }
        if (method_exists($apiMove, 'setTimeout')) {
            $apiMove->setTimeout(50);
        }
        $fromMid = card_user_sas_manager_id($pdo, $aid, $homeIdRet, $apiMove);
        $toMid = card_user_sas_manager_id($pdo, $homeIdRet, $homeIdRet, $apiMove);
        if ($toMid <= 0) {
            flash('error', function_exists('card_transfer_error_message') ? card_transfer_error_message('sas_home_missing', $lang) : 'تعذر معرفة حساب الساس');
            redirect('agents.php');
        }
        if ($fromMid <= 0) {
            flash('error', function_exists('card_transfer_error_message') ? card_transfer_error_message('sas_manager_missing', $lang) : 'الوكيل غير مربوط بمدير ساس');
            redirect('agents.php');
        }
        if ($fromMid === $toMid) {
            flash('error', function_exists('card_transfer_error_message') ? card_transfer_error_message('sas_same_owner', $lang) : 'نفس الحساب');
            redirect('agents.php');
        }
        $move = $apiMove->moveUnusedCardsToOwner($profileId, $profileName, $qtyRet, $toMid, $fromMid, array());
        if (empty($move['ok'])) {
            if (function_exists('card_sas_stock_forget')) {
                card_sas_stock_forget();
            }
            flash('error', function_exists('card_sas_move_error') ? card_sas_move_error($move, $lang) : 'الساس ما رجّع الكروت');
            redirect('agents.php');
        }
        $movedQty = isset($move['moved']) ? (int) $move['moved'] : $qtyRet;
        list($okRet, $codeRet) = transfer_cards(
            $pdo,
            $aid,
            $homeIdRet,
            $profileId,
            $profileName,
            $movedQty,
            $wholesale,
            $agentPrice,
            'استرجاع من صفحة الوكلاء',
            $meId,
            false,
            isset($move['ranges']) ? $move['ranges'] : array()
        );
        if (!$okRet && method_exists($apiMove, 'restoreCardRanges')) {
            $apiMove->restoreCardRanges(isset($move['ranges']) ? $move['ranges'] : array(), $fromMid);
            flash('error', function_exists('card_transfer_error_message') ? card_transfer_error_message($codeRet, $lang) : 'ما انحفظ الاسترجاع');
            redirect('agents.php');
        }
        if (function_exists('card_sas_stock_forget')) {
            card_sas_stock_forget();
        }
        flash('success', $isEn
            ? ('Returned — ' . $movedQty . ' ' . $profileName . ' cards are back on your agency')
            : ('تم الاسترجاع — رجعت ' . $movedQty . ' كرت من ' . $profileName . ' إلى وكالتك'));
        redirect('agents.php');
    }

    if ($action === 'save_acc_widgets') {
        $uid = (int) post('user_id', '0');
        $row = get_admin_user($pdo, $uid);
        $rowTid = $row && isset($row['tenant_id']) ? (int) $row['tenant_id'] : 0;
        $myTidNow = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $canSetWid = (function_exists('is_admin_user') && is_admin_user())
            || (function_exists('is_group_manager_user') && is_group_manager_user());
        $raw = trim((string) post('widgets', ''));
        $ids = $raw === '' ? array() : explode(',', $raw);
        if (!$canSetWid || !$row || normalize_admin_role($row['role']) !== 'accountant' || $rowTid !== $myTidNow || !function_exists('accountant_widgets_save') || !accountant_widgets_save($pdo, $uid, $ids)) {
            flash('error', $isEn ? 'Could not save' : 'ما انحفظ');
        } else {
            flash('success', $isEn ? 'Saved' : 'تم الحفظ');
        }
        redirect('agents.php?view=accountant');
    }

    if ($action === 'allow_login_as') {
        if (!function_exists('is_admin_user') || !is_admin_user()) {
            flash('error', $isEn ? 'Portal admin only' : 'أدمن البوابة فقط');
            redirect('agents.php');
        }
        $uid = (int) post('user_id', '0');
        $row = get_admin_user($pdo, $uid);
        $rowTid = $row && isset($row['tenant_id']) ? (int) $row['tenant_id'] : 0;
        $myTidNow = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $rowRole = $row ? normalize_admin_role($row['role']) : '';
        $childAgency = ($rowRole === 'admin' && $rowTid > 1 && $rowTid !== $myTidNow);
        if ($childAgency && function_exists('portal_agencies_under_current')) {
            $under = false;
            foreach (portal_agencies_under_current($pdo, '') as $sib) {
                if ((int) $sib['id'] === $uid) {
                    $under = true;
                    break;
                }
            }
            if (!$under) {
                $childAgency = false;
            }
        }
        if (!$row || $uid === $meId || (!$childAgency && ($rowTid !== $myTidNow || !in_array($rowRole, array('agent', 'group_manager'), true)))) {
            flash('error', $isEn ? 'Agent not found' : 'الوكيل غير موجود');
            redirect('agents.php');
        }
        $allowAs = post('allow_login_as') === '1' ? 1 : 0;
        try {
            $pdo->prepare(
                'UPDATE admin_users SET allow_login_as = :a WHERE id = :id AND tenant_id = :t'
            )->execute(array(':a' => $allowAs, ':id' => $uid, ':t' => $rowTid));
        } catch (Exception $e) {
            flash('error', $isEn ? 'Could not save' : 'ما انحفظ');
        }
        redirect('agents.php');
    }

    if ($action === 'create') {
        $username = trim((string) post('username', ''));
        $display = trim((string) post('display_name', ''));
        $password = (string) post('password', '');
        $res = create_admin_user($pdo, $username, $display, $password, 'agent');
        if ($res === 'ok') {
            $newId = 0;
            try {
                $newId = (int) $pdo->query('SELECT id FROM admin_users WHERE username = ' . $pdo->quote($username) . ' LIMIT 1')->fetchColumn();
            } catch (Exception $e) {
                $newId = 0;
            }
            if (function_exists('is_group_manager_user') && is_group_manager_user() && $meId > 0 && $newId > 0) {
                try {
                    $pdo->prepare('UPDATE admin_users SET reports_to_user_id = :r WHERE id = :id')
                        ->execute(array(':r' => $meId, ':id' => $newId));
                } catch (Exception $e) {
                }
            }
            $holdId = (int) post('restore_hold_id', '0');
            if ($holdId > 0 && $newId > 0 && function_exists('user_hold_restore')) {
                user_hold_restore($pdo, $holdId, $newId);
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
        $rowTid = $row && isset($row['tenant_id']) ? (int) $row['tenant_id'] : 0;
        $myTidNow = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        if (!$row || normalize_admin_role($row['role']) !== 'agent' || $rowTid !== $myTidNow) {
            flash('error', $isEn ? 'Agent not found' : 'الوكيل غير موجود');
            redirect('agents.php');
        }
        update_admin_user_meta($pdo, $uid, $display !== '' ? $display : $row['display_name'], 'agent');
        $pdo->prepare('UPDATE admin_users SET is_active = :a, updated_at = NOW() WHERE id = :id AND role = "agent"')
            ->execute(array(':a' => $active, ':id' => $uid));
        $sasMid = (int) post('sas_manager_id', '0');
        try {
            $pdo->prepare('UPDATE admin_users SET sas_manager_id = :m WHERE id = :id AND role = "agent" AND tenant_id = :t')
                ->execute(array(
                    ':m' => $sasMid > 0 ? $sasMid : null,
                    ':id' => $uid,
                    ':t' => function_exists('current_tenant_id') ? (int) current_tenant_id() : 1,
                ));
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
        $canXfer = (function_exists('creator_can_grant_card_tools') && creator_can_grant_card_tools($pdo) && post('can_transfer_cards') === '1') ? 1 : 0;
        $canPrice = (function_exists('creator_can_grant_card_tools') && creator_can_grant_card_tools($pdo) && post('can_price_cards') === '1') ? 1 : 0;
        update_admin_user_meta($pdo, $uid, $display !== '' ? $display : $row['display_name'], 'accountant', $linked);
        $pdo->prepare('UPDATE admin_users SET is_active = :a, can_activate = :c, can_transfer_cards = :x, can_price_cards = :p, updated_at = NOW() WHERE id = :id AND role = "accountant"')
            ->execute(array(':a' => $active, ':c' => $canAct, ':x' => $canXfer, ':p' => $canPrice, ':id' => $uid));
        $newPass = (string) post('password', '');
        if (strlen($newPass) >= 4) {
            change_user_password($pdo, $uid, $newPass);
        }
        flash('success', $isEn ? 'Accountant updated' : 'تم تعديل المحاسب');
        redirect('agents.php?view=accountant');
    }

    if ($action === 'save_collected') {
        if (function_exists('is_accountant_user') && is_accountant_user()) {
            flash('error', $isEn ? 'Not allowed' : 'غير مسموح');
            redirect('agents.php?view=accountant');
        }
        $uid = (int) post('user_id', '0');
        $row = get_admin_user($pdo, $uid);
        if (!$row || normalize_admin_role($row['role']) !== 'accountant') {
            flash('error', $isEn ? 'Accountant not found' : 'المحاسب غير موجود');
            redirect('agents.php?view=accountant');
        }
        $amt = (float) post('collected', '0');
        if ($amt < 0) {
            $amt = 0;
        }
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS accountant_cash (
                    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                    tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
                    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP NULL DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $tidCash = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
            $pdo->prepare(
                'INSERT INTO accountant_cash (user_id, tenant_id, amount, updated_at)
                 VALUES (:id, :t, :a, NOW())
                 ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_at = NOW()'
            )->execute(array(':id' => $uid, ':t' => $tidCash, ':a' => $amt));
        } catch (Exception $e) {
            flash('error', $isEn ? 'Could not save' : 'ما انحفظ');
            redirect('agents.php?view=accountant');
        }
        flash('success', $isEn ? 'Collected amount saved' : 'تم تعديل المستلم');
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
                    $canXfer = (function_exists('creator_can_grant_card_tools') && creator_can_grant_card_tools($pdo) && post('can_transfer_cards') === '1') ? 1 : 0;
                    $canPrice = (function_exists('creator_can_grant_card_tools') && creator_can_grant_card_tools($pdo) && post('can_price_cards') === '1') ? 1 : 0;
                    $pdo->prepare('UPDATE admin_users SET can_activate = :c, can_transfer_cards = :x, can_price_cards = :p, linked_agent_id = :l WHERE id = :id')
                        ->execute(array(':c' => $canAct, ':x' => $canXfer, ':p' => $canPrice, ':l' => $linked, ':id' => $newId));
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
        $delRole = $row ? normalize_admin_role($row['role']) : '';
        if (!$row || ($delRole !== 'agent' && $delRole !== 'group_manager')) {
            flash('error', $isEn ? 'Agent not found' : 'الوكيل غير موجود');
            redirect('agents.php');
        }
        $dest = (string) post('data_dest', '');
        if (function_exists('user_hold_apply_delete')) {
            list($okDel, $msgDel) = user_hold_apply_delete($pdo, $uid, $meId, $dest);
            flash($okDel ? 'success' : 'error', $msgDel);
        } else {
            flash('error', $isEn ? 'Could not delete' : 'تعذر الحذف');
        }
        redirect('agents.php');
    }
}

if (isset($_GET['export_agent'])) {
    $exportId = (int) $_GET['export_agent'];
    $exportRow = $exportId > 0 ? get_admin_user($pdo, $exportId) : null;
    $exportTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $exportOk = $exportRow && (int) $exportRow['tenant_id'] === $exportTid && $exportId !== $meId;
    if (!$exportOk) {
        flash('error', $isEn ? 'Cannot export' : 'ما ينزل الملف');
        redirect('agents.php');
    }
    $sets = array(
        'subscribers' => 'SELECT * FROM subscribers WHERE tenant_id = ' . $exportTid . ' AND agent_user_id = ' . $exportId,
        'invoices' => 'SELECT i.* FROM invoices i INNER JOIN subscribers s ON s.id = i.subscriber_id WHERE s.tenant_id = ' . $exportTid . ' AND s.agent_user_id = ' . $exportId,
        'subscriptions' => 'SELECT sub.* FROM subscriptions sub INNER JOIN subscribers s ON s.id = sub.subscriber_id WHERE s.tenant_id = ' . $exportTid . ' AND s.agent_user_id = ' . $exportId,
    );
    if (class_exists('ZipArchive')) {
        $tmp = tempnam(sys_get_temp_dir(), 'ag');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($sets as $name => $sql) {
                $csv = "\xEF\xBB\xBF";
                try {
                    $rowsEx = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $rowsEx = array();
                }
                if ($rowsEx) {
                    $csv .= implode(',', array_keys($rowsEx[0])) . "\n";
                    foreach ($rowsEx as $rx) {
                        $cells = array();
                        foreach ($rx as $cell) {
                            $cells[] = '"' . str_replace('"', '""', (string) $cell) . '"';
                        }
                        $csv .= implode(',', $cells) . "\n";
                    }
                }
                $zip->addFromString($name . '.csv', $csv);
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="agent-' . $exportId . '.zip"');
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
        @unlink($tmp);
    }
    flash('error', $isEn ? 'Export failed' : 'فشل تحميل النسخة');
    redirect('agents.php');
}

$sasManagers = array();
$sasReady = function_exists('sas_is_ready') && sas_is_ready($config);
$canEditAgents = !(function_exists('is_agent_user') && is_agent_user());
$importTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$importFile = dirname(__DIR__) . '/storage/cache/agents_import_t' . $importTid . '.txt';
$importAge = is_file($importFile) ? (time() - (int) trim((string) @file_get_contents($importFile))) : 99999;
if ($sasReady && $canEditAgents && function_exists('sas_page_connector') && !empty($agentsAjaxStock) && $importAge > 600) {
    @file_put_contents($importFile, (string) time());
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    try {
        $apiPull = sas_page_connector($config);
    } catch (Exception $e) {
        $apiPull = null;
    }
    if ($apiPull) {
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
                if (function_exists('portal_find_existing_member')) {
                    $already = portal_find_existing_member($pdo, $mid, $rawName);
                    if ($already) {
                        if ((int) $already['tenant_id'] === $tidPull && (empty($already['sas_manager_id']) || (int) $already['sas_manager_id'] !== $mid)) {
                            try {
                                $pdo->prepare('UPDATE admin_users SET sas_manager_id = :m WHERE id = :id AND tenant_id = :t')
                                    ->execute(array(':m' => $mid, ':id' => (int) $already['id'], ':t' => $tidPull));
                            } catch (Exception $e) {
                            }
                        }
                        $bySas[$mid] = (int) $already['id'];
                        continue;
                    }
                }
                $username = preg_replace('/[^A-Za-z0-9._@\-]/', '', $rawName);
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
            if ($sasManagers) {
                $mgrFile = dirname(__DIR__) . '/storage/cache/sas_managers_t' . $importTid . '.json';
                @file_put_contents($mgrFile, json_encode($sasManagers));
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
            $seenIds[(int) $gm['id']] = true;
        }
    }
    if (function_exists('portal_agencies_under_current')) {
        foreach (portal_agencies_under_current($pdo, '') as $sib) {
            if (empty($seenIds[(int) $sib['id']])) {
                $agents[] = $sib;
                $seenIds[(int) $sib['id']] = true;
            }
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
    $countTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $st = $pdo->prepare(
        'SELECT agent_user_id, COUNT(*) AS c FROM subscribers
         WHERE tenant_id = :t AND agent_user_id IS NOT NULL
         GROUP BY agent_user_id'
    );
    $st->execute(array(':t' => $countTid));
    foreach ($st->fetchAll() as $r) {
        $counts[(int) $r['agent_user_id']] = (int) $r['c'];
    }
} catch (Exception $e) {
}

$cardHomeId = function_exists('user_card_source_id') ? (int) user_card_source_id($pdo) : $meId;
$agentCardMap = array();
if ($cardHomeId > 0 && function_exists('card_sas_stock_map') && function_exists('sas_is_ready') && sas_is_ready($config)) {
    $agentCardMap = card_sas_stock_map($pdo, $config, $cardHomeId, !empty($agentsAjaxStock));
    if (!is_array($agentCardMap)) {
        $agentCardMap = array();
    }
}
$agentPriceRows = array();
try {
    if (function_exists('ensure_agent_card_prices_table')) {
        ensure_agent_card_prices_table($pdo);
    }
    $priceIds = array();
    foreach ($agents as $aPrice) {
        $priceIds[] = (int) $aPrice['id'];
    }
    if ($priceIds) {
        $inPrice = implode(',', $priceIds);
        $stPrice = $pdo->query(
            'SELECT agent_user_id, profile_id, profile_name, wholesale_price, agent_price
             FROM agent_card_prices WHERE agent_user_id IN (' . $inPrice . ')'
        );
        foreach ($stPrice->fetchAll() as $prRow) {
            $pAid = (int) $prRow['agent_user_id'];
            if (!isset($agentPriceRows[$pAid])) {
                $agentPriceRows[$pAid] = array();
            }
            $agentPriceRows[$pAid][] = $prRow;
        }
    }
} catch (Exception $e) {
}
if (!function_exists('agents_card_lines_html')) {
    function agents_card_lines_html($aid, $lines, $withReturn, $isEn, $currency)
    {
        if (!$lines) {
            return '<span class="meta">' . e($isEn ? 'None' : 'ماكو') . '</span>';
        }
        $html = '<div class="ag-cards">';
        $due = 0.0;
        foreach ($lines as $ln) {
            $html .= '<div class="ag-card-line">';
            $html .= '<span class="ag-card-name">' . e($ln['name']) . '</span>';
            $html .= '<span class="ag-card-math">' . (int) $ln['qty'] . ' ' . e($isEn ? 'cards' : 'كرت');
            if ((float) $ln['unit'] > 0) {
                $due += (float) $ln['amount'];
                $html .= ' × ' . e(number_format((float) $ln['unit'], 0, '.', ','));
                $html .= ' = ' . e(function_exists('money_format_iqd') ? money_format_iqd($ln['amount'], $currency) : (string) $ln['amount']);
            } else {
                $html .= ' — ' . e($isEn ? 'no price' : 'بدون سعر');
            }
            $html .= '</span>';
            if ($withReturn) {
                $confirm = $isEn
                    ? ('Return all ' . (int) $ln['qty'] . ' ' . $ln['name'] . ' cards to your agency?')
                    : ('ترجع كل كروت ' . $ln['name'] . ' (' . (int) $ln['qty'] . ') إلى وكالتك بالساس؟');
                $html .= '<form method="post">';
                $html .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
                $html .= '<input type="hidden" name="action" value="return_agent_cards">';
                $html .= '<input type="hidden" name="user_id" value="' . (int) $aid . '">';
                $html .= '<input type="hidden" name="profile_id" value="' . (int) $ln['profile_id'] . '">';
                $html .= '<input type="hidden" name="profile_name" value="' . e($ln['name']) . '">';
                $html .= '<button class="btn ghost sm" type="submit" onclick="return confirm('
                    . htmlspecialchars(json_encode($confirm), ENT_QUOTES, 'UTF-8') . ');">'
                    . e($isEn ? 'Return' : 'استرجاع') . '</button>';
                $html .= '</form>';
            }
            $html .= '</div>';
        }
        if ($due > 0) {
            $html .= '<div class="ag-card-due" style="margin-top:6px;font-weight:800;color:#9a3412">'
                . e($isEn ? 'Card debt: ' : 'دين الكروت: ')
                . e(function_exists('money_format_iqd') ? money_format_iqd($due, $currency) : (string) $due)
                . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
$cardCurrency = (isset($config['currency']) && trim((string) $config['currency']) !== '') ? (string) $config['currency'] : 'د.ع';

if (empty($sasManagers)) {
    $mgrFile = dirname(__DIR__) . '/storage/cache/sas_managers_t' . (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1) . '.json';
    if (is_file($mgrFile)) {
        $mj = @json_decode((string) @file_get_contents($mgrFile), true);
        if (is_array($mj)) {
            $sasManagers = $mj;
        }
    }
    if (empty($sasManagers) && isset($_SESSION['sas_managers_ui']) && is_array($_SESSION['sas_managers_ui'])) {
        $sasManagers = $_SESSION['sas_managers_ui'];
    }
}

if (!empty($agentsAjaxStock)) {
    header('Content-Type: application/json; charset=utf-8');
    $portalAjax = array();
    if ($agencyLogin !== '') {
        $portalAjax[strtolower($agencyLogin)] = true;
    }
    if ($me && !empty($me['username'])) {
        $portalAjax[strtolower(trim((string) $me['username']))] = true;
    }
    $cells = array();
    foreach ($agents as $agAjax) {
        $aidAjax = (int) $agAjax['id'];
        $cardLinesAjax = function_exists('card_agent_category_lines')
            ? card_agent_category_lines(
                isset($agentCardMap[$aidAjax]) ? $agentCardMap[$aidAjax] : array(),
                isset($agentPriceRows[$aidAjax]) ? $agentPriceRows[$aidAjax] : array()
            )
            : array();
        $isPortalAjax = ($aidAjax === $meId) || isset($portalAjax[strtolower((string) $agAjax['username'])]);
        $canReturnAjax = ($aidAjax !== $cardHomeId) && !$isPortalAjax;
        $cells[(string) $aidAjax] = agents_card_lines_html($aidAjax, $cardLinesAjax, $canReturnAjax, $isEn, $cardCurrency);
    }
    echo json_encode(array('ok' => true, 'cells' => $cells));
    exit;
}
$agentsStockScript = '<script>(function(){var nodes=document.querySelectorAll("[data-ag-cards]");if(!nodes.length)return;setTimeout(function(){fetch("agents.php?ajax=card_stock",{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){if(!d||!d.cells)return;for(var id in d.cells){var el=document.querySelector(\'[data-ag-cards="\'+id+\'"]\');if(el)el.innerHTML=d.cells[id];}}).catch(function(){});},6000);})();</script>';

$accOnly = (isset($_GET['view']) && $_GET['view'] === 'accountant');
$childAgents = function_exists('admin_user_child_count')
    ? admin_user_child_count($pdo, $meId, function_exists('current_tenant_id') ? (int) current_tenant_id() : 1)
    : 0;
if (function_exists('is_accountant_user') && is_accountant_user()) {
    $tree = function_exists('accountant_tree_ids') ? accountant_tree_ids($pdo) : array();
    $rootBoss = function_exists('accountant_linked_agent_id') ? accountant_linked_agent_id() : 0;
    $mineAgents = array();
    foreach ($agents as $ag) {
        $aid = (int) $ag['id'];
        if ($aid === $rootBoss || !in_array($aid, $tree, true)) {
            continue;
        }
        $mineAgents[] = $ag;
    }
    $pick = isset($_GET['pick']) ? (int) $_GET['pick'] : 0;
    $mayPrice = function_exists('user_may_price_cards') && user_may_price_cards($pdo);
    render_header($isEn ? 'Agents' : 'الوكلاء', 'agents');
    echo '<div class="panel"><h2 style="margin:0 0 12px">' . e($isEn ? 'Agents' : 'الوكلاء') . '</h2>';
    if ($mayPrice && $pick > 0) {
        echo '<p class="actions" style="margin:0 0 12px"><a class="btn" href="agent_prices.php?agent=' . (int) $pick . '">' . e($isEn ? 'Price services' : 'تسعير الخدمات') . '</a></p>';
    }
    echo '<div class="table-wrap"><table class="table-compact"><thead><tr>';
    echo '<th>' . e($isEn ? 'Agent' : 'الوكيل') . '</th>';
    echo '<th>' . e($isEn ? 'Owed' : 'مطلوب فلوس') . '</th>';
    echo '<th>' . e($isEn ? 'Free cards' : 'كروت شاغرة') . '</th>';
    echo '</tr></thead><tbody>';
    if (!$mineAgents) {
        echo '<tr><td colspan="3">' . e($isEn ? 'No agents' : 'ماكو وكلاء') . '</td></tr>';
    }
    foreach ($mineAgents as $ag) {
        $aid = (int) $ag['id'];
        $nm = trim((string) $ag['display_name']) !== '' ? $ag['display_name'] : $ag['username'];
        $owed = function_exists('card_agent_remaining_balance') ? card_agent_remaining_balance($pdo, $aid) : 0;
        $cardLines = function_exists('card_agent_category_lines')
            ? card_agent_category_lines(
                isset($agentCardMap[$aid]) ? $agentCardMap[$aid] : array(),
                isset($agentPriceRows[$aid]) ? $agentPriceRows[$aid] : array()
            )
            : array();
        $on = ($pick === $aid) ? ' style="background:#ecfeff"' : '';
        echo '<tr' . $on . '><td><a href="agents.php?pick=' . $aid . '">' . e($nm) . '</a></td>';
        echo '<td>' . e(function_exists('money_format_iqd') ? money_format_iqd($owed, isset($config['currency']) ? $config['currency'] : '') : (string) $owed) . '</td>';
        echo '<td data-ag-cards="' . (int) $aid . '">' . agents_card_lines_html($aid, $cardLines, false, $isEn, $cardCurrency) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
    echo $agentsStockScript;
    render_footer();
    return;
}
if ($childAgents <= 0 && empty($agents)) {
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
    <?php
    $agentQ = trim((string) (isset($_GET['q']) ? $_GET['q'] : ''));
    $agentShown = array();
    foreach ($agents as $aQ) {
        if ($agentQ !== '') {
            $hay = strtolower((string) $aQ['username'] . ' ' . (string) $aQ['display_name'] . ' ' . (isset($aQ['phone']) ? $aQ['phone'] : ''));
            $needle = function_exists('mb_strtolower') ? mb_strtolower($agentQ, 'UTF-8') : strtolower($agentQ);
            $hay = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : $hay;
            $hit = function_exists('mb_strpos') ? (mb_strpos($hay, $needle, 0, 'UTF-8') !== false) : (strpos($hay, $needle) !== false);
            if (!$hit) {
                continue;
            }
        }
        $agentShown[] = $aQ;
    }
    $portalNames = array();
    if ($agencyLogin !== '') {
        $portalNames[strtolower($agencyLogin)] = true;
    }
    if ($me && !empty($me['username'])) {
        $portalNames[strtolower(trim((string) $me['username']))] = true;
    }
    $myLogin = ($me && !empty($me['username'])) ? (string) $me['username'] : '';
    $principalId = function_exists('user_hold_principal_id') ? user_hold_principal_id($pdo) : 0;
    $principalName = '';
    if ($principalId > 0) {
        try {
            $pn = $pdo->prepare('SELECT username FROM admin_users WHERE id = :id LIMIT 1');
            $pn->execute(array(':id' => $principalId));
            $principalName = trim((string) $pn->fetchColumn());
        } catch (Exception $e) {
        }
    }
    ?>
    <div class="sys-head">
        <h2><?php echo e($isEn ? 'Agents' : 'الوكلاء'); ?></h2>
        <button class="btn" type="button" id="agentAddToggle"><?php echo e($isEn ? 'Add agent' : 'إضافة وكيل'); ?></button>
    </div>
    <form method="get" class="sys-search" id="agentSearchForm" action="agents.php">
        <input type="search" name="q" id="agentLiveQ" value="<?php echo e($agentQ); ?>" placeholder="<?php echo e($isEn ? 'Search name or username…' : 'بحث بالاسم أو الدخول…'); ?>" autocomplete="off">
    </form>
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
        <div>
            <label><?php echo e($isEn ? 'Return saved usernames' : 'إرجاع يوزرات محفوظة'); ?></label>
            <select name="restore_hold_id">
                <option value="0"><?php echo e($isEn ? '— none —' : '— بدون —'); ?></option>
                <?php
                $userHolds = function_exists('user_hold_open_list') ? user_hold_open_list($pdo) : array();
                foreach ($userHolds as $hold):
                    $holdN = substr_count((string) $hold['subscriber_ids'], ',') + ((string) $hold['subscriber_ids'] === '' ? 0 : 1);
                    ?>
                    <option value="<?php echo (int) $hold['id']; ?>"><?php echo e($hold['username'] . ' (' . $holdN . ')'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions" style="align-items:end">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
        </div>
    </form>

    <div class="table-wrap">
    <table class="table-compact" id="agentTable">
        <thead>
        <tr>
            <th>#</th>
            <th><?php echo e($isEn ? 'Username' : 'اسم الدخول'); ?></th>
            <th><?php echo e($isEn ? 'Name' : 'الاسم'); ?></th>
            <th><?php echo e($isEn ? 'Subscribers' : 'المشتركين'); ?></th>
            <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
            <th><?php echo e($isEn ? 'Cards' : 'الكروت'); ?></th>
            <?php if (function_exists('is_admin_user') && is_admin_user()): ?>
            <th class="ag-as-col" title="<?php echo e($isEn ? 'Allow login-as' : 'السماح بالدخول بـ'); ?>"><?php echo e($isEn ? 'Login-as' : 'دخول بـ'); ?></th>
            <?php endif; ?>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <tr id="agentNone"<?php echo $agentShown ? ' hidden' : ''; ?>><td colspan="<?php echo (function_exists('is_admin_user') && is_admin_user()) ? 8 : 7; ?>" class="msg-empty"><?php echo e($isEn ? 'No matches' : 'ماكو نتيجة'); ?></td></tr>
        <?php
        $allowMap = array();
        try {
            $afAll = $pdo->prepare('SELECT id, allow_login_as FROM admin_users WHERE role IN ("agent","group_manager","admin")');
            $afAll->execute();
            foreach ($afAll->fetchAll() as $ar) {
                $allowMap[(int) $ar['id']] = ((int) $ar['allow_login_as'] === 1);
            }
        } catch (Exception $e) {
            $allowMap = array();
        }
        $agNo = 0; foreach ($agentShown as $a):
            $agNo++;
            $aid = (int) $a['id'];
            $curSas = isset($a['sas_manager_id']) ? (int) $a['sas_manager_id'] : 0;
            $active = (int) $a['is_active'] === 1;
            $isPortal = ($aid === $meId) || isset($portalNames[strtolower((string) $a['username'])]);
            $rowRole = normalize_admin_role(isset($a['role']) ? $a['role'] : '');
            $rowTid = isset($a['tenant_id']) ? (int) $a['tenant_id'] : 0;
            $myTidView = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
            $canSetAs = function_exists('is_admin_user') && is_admin_user()
                && $aid !== $meId
                && (
                    ($rowTid === $myTidView && in_array($rowRole, array('agent', 'group_manager'), true))
                    || ($rowRole === 'admin' && $rowTid > 1 && $rowTid !== $myTidView)
                );
            $rowAllow = !isset($allowMap[$aid]) || !empty($allowMap[$aid]);
            $span = (function_exists('is_admin_user') && is_admin_user()) ? 8 : 7;
            ?>
        <tr class="agent-main" data-find="<?php echo e(strtolower((string) $a['username'] . ' ' . (string) $a['display_name'] . ' ' . (isset($a['phone']) ? $a['phone'] : ''))); ?>">
            <td class="agent-no"><?php echo (int) $agNo; ?></td>
            <td class="ltr"><?php echo e($a['username']); ?></td>
            <td><?php echo e($a['display_name']); ?></td>
            <td><?php echo isset($counts[$aid]) ? (int) $counts[$aid] : 0; ?></td>
            <td><span class="<?php echo $active ? 'sas-logged' : 'sas-logged-off'; ?>"><?php echo e($active ? ($isEn ? 'Active' : 'فعال') : ($isEn ? 'Off' : 'موقوف')); ?></span></td>
            <td class="ag-cards-cell" data-ag-cards="<?php echo (int) $aid; ?>">
                <?php
                $cardLines = function_exists('card_agent_category_lines')
                    ? card_agent_category_lines(
                        isset($agentCardMap[$aid]) ? $agentCardMap[$aid] : array(),
                        isset($agentPriceRows[$aid]) ? $agentPriceRows[$aid] : array()
                    )
                    : array();
                $canReturnCards = ($aid !== $cardHomeId) && !$isPortal;
                echo agents_card_lines_html($aid, $cardLines, $canReturnCards, $isEn, $cardCurrency);
                ?>
            </td>
            <?php if (function_exists('is_admin_user') && is_admin_user()): ?>
            <td class="ag-as-col">
                <?php if ($canSetAs): ?>
                <form method="post" class="ag-as-form">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="allow_login_as">
                    <input type="hidden" name="user_id" value="<?php echo $aid; ?>">
                    <input type="hidden" name="allow_login_as" value="0">
                    <label class="ag-as" title="<?php echo e($isEn ? 'Allow login-as' : 'السماح بالدخول بـ'); ?>">
                        <input type="checkbox" name="allow_login_as" value="1" <?php echo $rowAllow ? 'checked' : ''; ?> onchange="this.form.submit()">
                        <i></i>
                    </label>
                </form>
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="ag-actions">
                <?php if (!empty($childCounts[$aid]) && $active && function_exists('is_admin_user') && is_admin_user() && !$isPortal): ?>
                <form method="post" action="impersonate.php" class="inline-form">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="start">
                    <input type="hidden" name="user_id" value="<?php echo $aid; ?>">
                    <button class="btn sm" type="submit"><?php echo e($isEn ? 'Login as' : 'دخول'); ?></button>
                </form>
                <?php endif; ?>
                <button class="btn ghost sm" type="button" data-agent-edit="<?php echo $aid; ?>"><?php echo e($isEn ? 'Edit' : 'تعديل'); ?></button>
                <?php if (!$isPortal): ?>
                <button class="btn danger sm js-del-open" type="button" data-del="<?php echo $aid; ?>"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                <?php endif; ?>
            </td>
        </tr>
        <tr class="del-row agent-extra" id="delRow<?php echo $aid; ?>" hidden>
            <td colspan="<?php echo (int) $span; ?>">
                <form method="post" class="ag-del-form">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?php echo $aid; ?>">
                    <label><?php echo e($isEn ? 'Where does the data go?' : 'وين تروح البيانات؟'); ?></label>
                    <select name="data_dest" required>
                        <option value=""><?php echo e($isEn ? 'Choose' : 'اختار'); ?></option>
                        <option value="download"><?php echo e($isEn ? 'Download a copy of the data' : 'تحميل نسخة من البيانات'); ?></option>
                        <?php if ($myLogin !== '' && $meId !== $aid): ?>
                        <option value="owner"><?php echo e(($isEn ? 'To my login: ' : 'إلى حسابي المسجّل: ') . $myLogin); ?></option>
                        <?php endif; ?>
                        <?php if ($principalId > 0 && $principalId !== $meId && $principalId !== $aid): ?>
                        <option value="principal"><?php echo e(($isEn ? 'To the main portal admin: ' : 'إلى الأدمن الرئيسي للبوابة: ') . ($principalName !== '' ? $principalName : ('#' . $principalId))); ?></option>
                        <?php endif; ?>
                        <?php foreach ($agents as $sag):
                            $sid = (int) $sag['id'];
                            if ($sid === $aid || $sid === $meId) { continue; }
                            $sagUser = strtolower((string) $sag['username']);
                            $sagName = strtolower((string) $sag['display_name']);
                            if (isset($portalNames[$sagUser]) || isset($portalNames[$sagName])) { continue; }
                            if ($myLogin !== '' && ($sagUser === strtolower($myLogin) || $sagName === strtolower($myLogin))) { continue; }
                            if (isset($sag['role']) && $sag['role'] === 'admin') { continue; }
                            ?>
                            <option value="agent:<?php echo $sid; ?>"><?php echo e(($isEn ? 'To ' : 'إلى ') . $sag['display_name']); ?></option>
                        <?php endforeach; ?>
                        <option value="hold"><?php echo e($isEn ? 'Keep until a new agent' : 'تبقى محفوظة حتى وكيل جديد'); ?></option>
                    </select>
                    <button class="btn danger sm js-del-confirm" type="submit" disabled><?php echo e($isEn ? 'Confirm delete' : 'تأكيد الحذف'); ?></button>
                    <button class="btn ghost sm js-del-close" type="button"><?php echo e($isEn ? 'Cancel' : 'إلغاء'); ?></button>
                </form>
            </td>
        </tr>
        <tr class="edit-row agent-extra" id="editRow<?php echo $aid; ?>" hidden>
            <td colspan="<?php echo (int) $span; ?>">
            <form method="post" id="agentEdit<?php echo $aid; ?>" class="ag-del-form">
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
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="ag-pager" id="agentPager" hidden></div>
    <script>
    (function () {
      var table = document.getElementById('agentTable');
      var input = document.getElementById('agentLiveQ');
      var pager = document.getElementById('agentPager');
      var form = document.getElementById('agentSearchForm');
      if (!table || !input || !pager) return;
      if (form) form.addEventListener('submit', function (e) { e.preventDefault(); });
      var per = 10;
      var page = 1;
      var mains = [];
      var rows = table.querySelectorAll('tbody tr.agent-main');
      for (var i = 0; i < rows.length; i++) mains.push(rows[i]);
      var emptyRow = document.getElementById('agentNone');
      function blockOf(main) {
        var list = [main];
        var n = main.nextElementSibling;
        if (n && n.classList.contains('agent-extra')) list.push(n);
        n = list[list.length - 1].nextElementSibling;
        if (n && n.classList.contains('agent-extra')) list.push(n);
        return list;
      }
      function paint(list, on) {
        for (var i = 0; i < list.length; i++) {
          if (list[i].classList.contains('agent-extra')) {
            if (!on) list[i].hidden = true;
            continue;
          }
          if (on) list[i].classList.add('is-on');
          else list[i].classList.remove('is-on');
        }
      }
      function draw() {
        var q = (input.value || '').replace(/^\s+|\s+$/g, '').toLowerCase();
        var hit = [];
        for (var i = 0; i < mains.length; i++) {
          var hay = (mains[i].getAttribute('data-find') || '').toLowerCase();
          if (!q || hay.indexOf(q) !== -1) hit.push(mains[i]);
        }
        var pages = Math.max(1, Math.ceil(hit.length / per));
        if (page > pages) page = 1;
        var start = (page - 1) * per;
        for (var j = 0; j < mains.length; j++) paint(blockOf(mains[j]), false);
        for (var k = start; k < start + per && k < hit.length; k++) {
          paint(blockOf(hit[k]), true);
          var num = hit[k].querySelector('.agent-no');
          if (num) num.textContent = String(k + 1);
        }
        if (emptyRow) emptyRow.hidden = hit.length > 0;
        pager.innerHTML = '';
        if (hit.length <= per) {
          pager.hidden = true;
          return;
        }
        pager.hidden = false;
        function btn(label, go, on) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'btn ghost sm' + (on ? ' is-on' : '');
          b.textContent = label;
          b.disabled = go < 1 || go > pages;
          b.addEventListener('click', function () { page = go; draw(); });
          pager.appendChild(b);
        }
        btn('‹', page - 1, false);
        var from = Math.max(1, page - 2);
        var to = Math.min(pages, from + 4);
        from = Math.max(1, to - 4);
        for (var p = from; p <= to; p++) btn(String(p), p, p === page);
        btn('›', page + 1, false);
      }
      input.addEventListener('input', function () { page = 1; draw(); });
      draw();
    })();
    </script>
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
        <?php
        $accQ = trim((string) (isset($_GET['q']) ? $_GET['q'] : ''));
        $accShown = array();
        foreach ($accountants as $ac) {
            if ((int) $ac['linked_agent_id'] !== $meId && (int) $ac['linked_agent_id'] !== 0) {
                continue;
            }
            if ($accQ !== '') {
                $hay = strtolower((string) $ac['username'] . ' ' . (string) $ac['display_name']);
                $needle = function_exists('mb_strtolower') ? mb_strtolower($accQ, 'UTF-8') : strtolower($accQ);
                $hay = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : $hay;
                $hit = function_exists('mb_strpos') ? (mb_strpos($hay, $needle, 0, 'UTF-8') !== false) : (strpos($hay, $needle) !== false);
                if (!$hit) {
                    continue;
                }
            }
            $accShown[] = $ac;
        }
        $cashOverride = array();
        $cashSum = array();
        $cashTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        $cashFile = dirname(__DIR__) . '/storage/cache/acc_cash_t' . $cashTid . '.json';
        $cashHit = null;
        if (is_file($cashFile) && (time() - (int) @filemtime($cashFile)) < 180) {
            $cashHit = json_decode((string) @file_get_contents($cashFile), true);
        }
        if (is_array($cashHit) && isset($cashHit['sum']) && isset($cashHit['over'])) {
            $cashSum = $cashHit['sum'];
            $cashOverride = $cashHit['over'];
        } else {
            try {
                if (!function_exists('app_schema_fresh') || !app_schema_fresh('acc_cash', 86400)) {
                    $pdo->exec(
                        'CREATE TABLE IF NOT EXISTS accountant_cash (
                            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
                            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
                            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                            updated_at TIMESTAMP NULL DEFAULT NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                    );
                    $colPay = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'collected_by'")->fetch();
                    if (!$colPay) {
                        $pdo->exec('ALTER TABLE invoices ADD COLUMN collected_by INT UNSIGNED NULL DEFAULT NULL');
                    }
                    if (function_exists('app_schema_touch')) {
                        app_schema_touch('acc_cash');
                    }
                }
                $stCash = $pdo->prepare('SELECT user_id, amount FROM accountant_cash WHERE tenant_id = :t');
                $stCash->execute(array(':t' => $cashTid));
                foreach ($stCash->fetchAll() as $cr) {
                    $cashOverride[(int) $cr['user_id']] = (float) $cr['amount'];
                }
                $stSum = $pdo->prepare(
                    'SELECT i.collected_by AS uid, COALESCE(SUM(i.amount),0) AS t
                     FROM invoices i
                     WHERE i.status = "paid" AND i.collected_by IS NOT NULL AND i.tenant_id = :t
                     GROUP BY i.collected_by'
                );
                $stSum->execute(array(':t' => $cashTid));
                foreach ($stSum->fetchAll() as $sr) {
                    $cashSum[(string) (int) $sr['uid']] = (float) $sr['t'];
                }
                $dirCash = dirname($cashFile);
                if (!is_dir($dirCash)) {
                    @mkdir($dirCash, 0775, true);
                }
                @file_put_contents($cashFile, json_encode(array('sum' => $cashSum, 'over' => $cashOverride)));
            } catch (Exception $e) {
                try {
                    $stSum = $pdo->prepare(
                        'SELECT i.collected_by AS uid, COALESCE(SUM(i.amount),0) AS t
                         FROM invoices i
                         JOIN subscribers s ON s.id = i.subscriber_id
                         WHERE i.status = "paid" AND i.collected_by IS NOT NULL AND s.tenant_id = :t
                         GROUP BY i.collected_by'
                    );
                    $stSum->execute(array(':t' => $cashTid));
                    foreach ($stSum->fetchAll() as $sr) {
                        $cashSum[(int) $sr['uid']] = (float) $sr['t'];
                    }
                    @file_put_contents($cashFile, json_encode(array('sum' => $cashSum, 'over' => $cashOverride)));
                } catch (Exception $e2) {
                }
            }
        }
        $accReportId = isset($_GET['acc']) ? (int) $_GET['acc'] : 0;
        $accReportRows = array();
        $accReportName = '';
        if ($accReportId > 0) {
            foreach ($accShown as $acR) {
                if ((int) $acR['id'] === $accReportId) {
                    $accReportName = (string) $acR['display_name'];
                }
            }
            if ($accReportName !== '') {
                try {
                    $stRep = $pdo->prepare(
                        'SELECT i.amount, i.month_label, i.paid_at, s.name
                         FROM invoices i
                         JOIN subscribers s ON s.id = i.subscriber_id
                         WHERE i.status = "paid" AND i.collected_by = :u AND s.tenant_id = :t
                         ORDER BY i.paid_at DESC, i.id DESC
                         LIMIT 300'
                    );
                    $stRep->execute(array(
                        ':u' => $accReportId,
                        ':t' => function_exists('current_tenant_id') ? (int) current_tenant_id() : 1,
                    ));
                    $accReportRows = $stRep->fetchAll();
                } catch (Exception $e) {
                }
            }
        }
        $canEditCash = !(function_exists('is_accountant_user') && is_accountant_user());
        ?>
        <div class="sys-head" style="display:flex;align-items:center;justify-content:flex-end;direction:ltr;gap:12px;margin:0 0 12px">
            <h2 style="margin:0"><?php echo e($isEn ? 'Accountants' : 'المحاسبين'); ?></h2>
            <button class="btn" type="button" id="accAddToggle"><?php echo e($isEn ? 'Add accountant' : 'إضافة محاسب'); ?></button>
        </div>
        <form method="get" class="sys-search" style="display:flex;gap:8px;margin:0 0 12px">
            <?php if ($accOnly): ?><input type="hidden" name="view" value="accountant"><?php endif; ?>
            <input type="search" name="q" value="<?php echo e($accQ); ?>" placeholder="<?php echo e($isEn ? 'Search name or username…' : 'بحث بالاسم أو الدخول…'); ?>" style="max-width:320px">
            <button class="btn" type="submit"><?php echo e($isEn ? 'Search' : 'بحث'); ?></button>
        </form>
        <?php if ($accReportId > 0 && $accReportName !== ''): ?>
        <div class="panel" style="margin-bottom:12px">
            <h3 style="margin:0 0 8px"><?php echo e($accReportName); ?></h3>
            <div class="table-wrap">
            <table class="table-compact">
                <thead>
                <tr>
                    <th><?php echo e($isEn ? 'Subscriber' : 'المشترك'); ?></th>
                    <th><?php echo e($isEn ? 'Amount' : 'المبلغ'); ?></th>
                    <th><?php echo e($isEn ? 'Subscription / purpose' : 'الاشتراك أو الغرض'); ?></th>
                    <th><?php echo e($isEn ? 'When' : 'الوقت'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$accReportRows): ?>
                    <tr><td colspan="4"><?php echo e($isEn ? 'No collections yet' : 'ماكو استلام بعد'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($accReportRows as $rr): ?>
                <tr>
                    <td><?php echo e($rr['name']); ?></td>
                    <td><?php echo e(function_exists('money_format_iqd') ? money_format_iqd($rr['amount'], isset($config['currency']) ? $config['currency'] : '') : $rr['amount']); ?></td>
                    <td><?php echo e($rr['month_label']); ?></td>
                    <td class="nowrap"><?php echo e($rr['paid_at']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>
        <form method="post" id="accAddBox" class="form-grid" hidden style="margin-bottom:12px">
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
            <?php if (function_exists('creator_can_grant_card_tools') && creator_can_grant_card_tools($pdo)): ?>
            <div>
                <label><?php echo e($isEn ? 'Card transfer' : 'تحويل الكروت'); ?></label>
                <label class="toggle" style="display:flex;align-items:center;gap:8px;margin-top:8px">
                    <input type="checkbox" name="can_transfer_cards" value="1">
                    <span class="toggle-ui"></span>
                    <span><?php echo e($isEn ? 'Allow transfer' : 'يقدر يحوّل كروت'); ?></span>
                </label>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Pricing' : 'تسعير الخدمات'); ?></label>
                <label class="toggle" style="display:flex;align-items:center;gap:8px;margin-top:8px">
                    <input type="checkbox" name="can_price_cards" value="1">
                    <span class="toggle-ui"></span>
                    <span><?php echo e($isEn ? 'Allow pricing' : 'يقدر يسعّر'); ?></span>
                </label>
            </div>
            <?php endif; ?>
            <div class="actions" style="align-items:end">
                <button class="btn" type="submit"><?php echo e($isEn ? 'Add' : 'إضافة'); ?></button>
            </div>
        </form>
        <div class="table-wrap">
        <table class="table-compact">
            <thead>
            <tr>
                <th>#</th>
                <th><?php echo e($isEn ? 'Username' : 'اسم الدخول'); ?></th>
                <th><?php echo e($isEn ? 'Name' : 'الاسم'); ?></th>
                <th><?php echo e($isEn ? 'Activate' : 'تفعيل'); ?></th>
                <th><?php echo e($isEn ? 'Collected' : 'المستلم'); ?></th>
                <th><?php echo e($isEn ? 'Password' : 'الرمز'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$accShown): ?>
                <tr><td colspan="7" class="msg-empty"><?php echo e($accQ !== '' ? ($isEn ? 'No matches' : 'ماكو نتيجة') : ($isEn ? 'No accountants' : 'ماكو محاسبين')); ?></td></tr>
            <?php endif; ?>
            <?php $accNo = 0; foreach ($accShown as $ac):
                $accNo++;
                $acid = (int) $ac['id'];
                $canAct = !empty($ac['can_activate']);
                ?>
            <tr>
                <td><?php echo (int) $accNo; ?></td>
                <td class="ltr"><a href="agents.php?view=accountant&amp;acc=<?php echo $acid; ?>"><?php echo e($ac['username']); ?></a></td>
                <td><input form="accForm<?php echo $acid; ?>" name="display_name" value="<?php echo e($ac['display_name']); ?>" required></td>
                <td>
                    <label class="toggle" style="margin:0">
                        <input form="accForm<?php echo $acid; ?>" type="checkbox" name="can_activate" value="1" <?php echo $canAct ? 'checked' : ''; ?>>
                        <span class="toggle-ui" aria-hidden="true"></span>
                    </label>
                    <?php if (function_exists('creator_can_grant_card_tools') && creator_can_grant_card_tools($pdo)):
                        $canXferRow = !empty($ac['can_transfer_cards']);
                        $canPriceRow = !empty($ac['can_price_cards']);
                        ?>
                    <label class="toggle" style="margin:6px 0 0" title="<?php echo e($isEn ? 'Card transfer' : 'تحويل الكروت'); ?>">
                        <input form="accForm<?php echo $acid; ?>" type="checkbox" name="can_transfer_cards" value="1" <?php echo $canXferRow ? 'checked' : ''; ?>>
                        <span class="toggle-ui" aria-hidden="true"></span>
                    </label>
                    <label class="toggle" style="margin:6px 0 0" title="<?php echo e($isEn ? 'Pricing' : 'تسعير'); ?>">
                        <input form="accForm<?php echo $acid; ?>" type="checkbox" name="can_price_cards" value="1" <?php echo $canPriceRow ? 'checked' : ''; ?>>
                        <span class="toggle-ui" aria-hidden="true"></span>
                    </label>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $shownCash = isset($cashOverride[$acid]) ? $cashOverride[$acid] : (isset($cashSum[$acid]) ? $cashSum[$acid] : 0);
                    ?>
                    <?php if ($canEditCash): ?>
                    <input form="accCash<?php echo $acid; ?>" name="collected" type="number" min="0" step="1" value="<?php echo (int) round($shownCash); ?>" style="max-width:120px">
                    <?php else: ?>
                    <?php echo e((string) (int) round($shownCash)); ?>
                    <?php endif; ?>
                </td>
                <td><input form="accForm<?php echo $acid; ?>" name="password" type="password" minlength="4" placeholder="<?php echo e($isEn ? 'Optional' : 'اختياري'); ?>"></td>
                <td>
                    <form method="post" id="accForm<?php echo $acid; ?>" class="inline-form">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_accountant">
                        <input type="hidden" name="user_id" value="<?php echo $acid; ?>">
                        <input type="hidden" name="is_active" value="<?php echo (int) $ac['is_active'] === 1 ? '1' : '0'; ?>">
                        <button class="btn sm" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
                    </form>
                    <?php if ($canEditCash): ?>
                    <form method="post" id="accCash<?php echo $acid; ?>" class="inline-form" style="margin-top:4px">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_collected">
                        <input type="hidden" name="user_id" value="<?php echo $acid; ?>">
                        <button class="btn ghost sm" type="submit"><?php echo e($isEn ? 'Save cash' : 'حفظ المستلم'); ?></button>
                    </form>
                    <?php endif; ?>
                    <button class="btn ghost sm" type="button" data-acc-wid-open="<?php echo $acid; ?>" style="margin-top:4px"><?php echo e($isEn ? 'Customize dashboard' : 'تخصيص الداشبورد'); ?></button>
                </td>
            </tr>
            <?php
            $widCat = function_exists('accountant_widget_catalog_full') ? accountant_widget_catalog_full() : (function_exists('accountant_widget_catalog') ? accountant_widget_catalog() : array());
            $widEff = function_exists('accountant_widgets_effective') ? accountant_widgets_effective($pdo, $acid) : null;
            $widMine = is_array($widEff) ? $widEff : array_keys($widCat);
            $widPool = array();
            foreach ($widCat as $wid => $meta) {
                if (!in_array($wid, $widMine, true)) {
                    $widPool[] = $wid;
                }
            }
            ?>
            <tr class="acc-wid-row" id="accWid<?php echo $acid; ?>" hidden>
                <td colspan="7">
                    <form method="post" class="acc-wid-form">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_acc_widgets">
                        <input type="hidden" name="user_id" value="<?php echo $acid; ?>">
                        <input type="hidden" name="widgets" value="<?php echo e(implode(',', $widMine)); ?>">
                        <div class="acc-board">
                            <div>
                                <div class="acc-board-h"><?php echo e($isEn ? 'Available' : 'المتوفرة'); ?></div>
                                <div class="acc-col" data-col="pool">
                                    <?php foreach ($widPool as $wid):
                                        if (!isset($widCat[$wid])) { continue; }
                                        $meta = $widCat[$wid];
                                        $lab = $isEn ? $meta['en'] : $meta['ar'];
                                        ?>
                                    <div class="acc-chip" draggable="true" data-wid="<?php echo e($wid); ?>">
                                        <span class="acc-ord"></span>
                                        <span class="acc-lab"><?php echo e($lab); ?></span>
                                        <span class="acc-move">
                                            <button type="button" class="acc-up" draggable="false" title="<?php echo e($isEn ? 'Up' : 'أعلى'); ?>">↑</button>
                                            <button type="button" class="acc-dn" draggable="false" title="<?php echo e($isEn ? 'Down' : 'أسفل'); ?>">↓</button>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div>
                                <div class="acc-board-h"><?php echo e($isEn ? 'On the dashboard — order' : 'تظهر بالداشبورد — الترتيب'); ?></div>
                                <div class="acc-col" data-col="mine">
                                    <?php foreach ($widMine as $wid):
                                        if (isset($widCat[$wid])) {
                                            $meta = $widCat[$wid];
                                            $lab = $isEn ? $meta['en'] : $meta['ar'];
                                        } elseif (strpos((string) $wid, 'pkg:') === 0) {
                                            $lab = rawurldecode(substr((string) $wid, 4));
                                        } else {
                                            continue;
                                        }
                                        ?>
                                    <div class="acc-chip" draggable="true" data-wid="<?php echo e($wid); ?>">
                                        <span class="acc-ord"></span>
                                        <span class="acc-lab"><?php echo e($lab); ?></span>
                                        <span class="acc-move">
                                            <button type="button" class="acc-up" draggable="false" title="<?php echo e($isEn ? 'Up' : 'أعلى'); ?>">↑</button>
                                            <button type="button" class="acc-dn" draggable="false" title="<?php echo e($isEn ? 'Down' : 'أسفل'); ?>">↓</button>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <p class="meta" style="margin:8px 0"><?php echo e($isEn
                            ? 'Drag to show or hide. In the order column, drag a row or use the arrows. Number 1 is the first box on the dashboard.'
                            : 'اسحب للإظهار أو الإخفاء. بعمود الترتيب اسحب السطر أو استخدم الأسهم. الرقم 1 هو أول صندوق بالداشبورد.'); ?></p>
                        <button class="btn sm" type="submit"><?php echo e($isEn ? 'Save dashboard' : 'حفظ الداش بورد'); ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <style>
    #accAddBox[hidden], #agentAddBox[hidden], .del-row[hidden], .edit-row[hidden], .acc-wid-row[hidden] { display:none !important; }
    .sys-head { display:flex; align-items:center; justify-content:flex-end; direction:ltr; gap:12px; margin:0 0 12px; }
    .sys-head h2 { margin:0; }
    .sys-search { display:flex; gap:8px; margin:0 0 14px; }
    .sys-search input[type="search"] { max-width:320px; width:100%; }
    #agentTable tbody tr.agent-main { display: none; }
    #agentTable tbody tr.agent-main.is-on { display: table-row; }
    .ag-pager { display:flex; flex-wrap:wrap; gap:6px; justify-content:center; margin:14px 0 4px; }
    .ag-pager .btn.is-on { background:#0f172a; color:#fff; border-color:#0f172a; }
    .ag-actions { display:flex; gap:6px; align-items:center; justify-content:flex-end; flex-wrap:wrap; }
    .ag-cards-cell { min-width: 240px; }
    .ag-cards { display:flex; flex-direction:column; gap:6px; }
    .ag-card-line { display:flex; flex-wrap:wrap; align-items:center; gap:8px; font-size:13px; }
    .ag-card-name { font-weight:800; }
    .ag-card-math { color:#334155; }
    .ag-card-line form { margin:0; }
    .ag-as-col { width:52px; text-align:center; padding-left:4px; padding-right:4px; }
    .ag-as-form { margin:0; display:inline-flex; }
    .ag-as { position:relative; display:inline-flex; margin:0; cursor:pointer; }
    .ag-as input { position:absolute; opacity:0; width:1px; height:1px; }
    .ag-as i { width:30px; height:16px; border-radius:999px; background:#cbd5e1; display:inline-block; position:relative; }
    .ag-as i::after { content:""; position:absolute; top:2px; right:2px; width:12px; height:12px; border-radius:50%; background:#fff; transition:right .15s; }
    .ag-as input:checked + i { background:#16a34a; }
    .ag-as input:checked + i::after { right:16px; }
    .acc-board { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:8px; }
    @media (max-width:700px) { .acc-board { grid-template-columns:1fr; } }
    .acc-board-h { font-size:12px; font-weight:800; color:#475569; margin-bottom:6px; }
    .acc-col { min-height:88px; border:1px dashed #cbd5e1; border-radius:12px; padding:8px; background:#f8fafc; display:flex; flex-wrap:wrap; gap:8px; align-content:flex-start; }
    .acc-col[data-col="mine"] { flex-direction:column; flex-wrap:nowrap; align-items:stretch; max-height:440px; overflow:auto; }
    .acc-col.is-over { background:#ecfeff; border-color:#0f766e; }
    .acc-chip { padding:8px 12px; border-radius:999px; background:#0f172a; color:#fff; font-size:13px; font-weight:800; cursor:grab; }
    .acc-col[data-col="mine"] .acc-chip { display:flex; align-items:center; gap:8px; width:100%; border-radius:12px; }
    .acc-ord { display:none; }
    .acc-col[data-col="mine"] .acc-ord { display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; border-radius:999px; background:#fff; color:#0f172a; font-size:12px; }
    .acc-lab { flex:1; }
    .acc-move { display:none; }
    .acc-col[data-col="mine"] .acc-move { display:inline-flex; gap:4px; }
    .acc-move button { width:26px; height:26px; border:0; border-radius:8px; background:rgba(255,255,255,.16); color:#fff; font-weight:800; cursor:pointer; line-height:1; }
    .acc-chip.is-drag { opacity:.45; }
    .ag-as input:focus + i { box-shadow:0 0 0 2px rgba(22,163,74,.25); }
    .ag-del-form { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .ag-del-form select { min-width:240px; max-width:360px; }
    .del-row td, .edit-row td { background:#f8fafc; }
    .table-compact td { vertical-align:middle; }
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
      tog('accAddToggle', 'accAddBox');
      var edits = document.querySelectorAll('[data-agent-edit]');
      for (var i = 0; i < edits.length; i++) {
        edits[i].addEventListener('click', function () {
          var row = document.getElementById('editRow' + this.getAttribute('data-agent-edit'));
          if (!row) return;
          row.hidden = !row.hidden;
        });
      }
      var opens = document.querySelectorAll('.js-del-open');
      for (var o = 0; o < opens.length; o++) {
        opens[o].addEventListener('click', function () {
          var row = document.getElementById('delRow' + this.getAttribute('data-del'));
          if (!row) return;
          var rows = document.querySelectorAll('.del-row');
          for (var r = 0; r < rows.length; r++) {
            if (rows[r] !== row) rows[r].hidden = true;
          }
          row.hidden = !row.hidden;
        });
      }
      var closers = document.querySelectorAll('.js-del-close');
      for (var c = 0; c < closers.length; c++) {
        closers[c].addEventListener('click', function () {
          var row = this.closest ? this.closest('tr') : null;
          if (row) row.hidden = true;
        });
      }
      var delForms = document.querySelectorAll('.ag-del-form');
      for (var d = 0; d < delForms.length; d++) {
        (function (form) {
          if (!form.querySelector('[name="data_dest"]')) return;
          var sel = form.querySelector('[name="data_dest"]');
          var go = form.querySelector('.js-del-confirm');
          if (sel) sel.addEventListener('change', function () {
            form.removeAttribute('data-ready');
            if (!sel.value) {
              if (go) go.disabled = true;
              return;
            }
            if (sel.value !== 'download') {
              if (go) go.disabled = false;
              return;
            }
            if (go) go.disabled = true;
            var uidEl = form.querySelector('[name="user_id"]');
            var uid = uidEl ? uidEl.value : '0';
            fetch('agents.php?export_agent=' + encodeURIComponent(uid), { credentials: 'same-origin' })
              .then(function (r) {
                if (!r.ok) throw new Error('fail');
                return r.blob();
              })
              .then(function (blob) {
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'agent-' + uid + '.zip';
                document.body.appendChild(a);
                a.click();
                a.remove();
                form.setAttribute('data-ready', '1');
                if (go) go.disabled = false;
              })
              .catch(function () {
                if (go) go.disabled = true;
                alert(<?php echo json_encode($isEn ? 'Download failed. The agent was not deleted.' : 'ما انحمل الملف، وما انحذف الوكيل'); ?>);
              });
          });
          form.addEventListener('submit', function (e) {
            if (!sel || !sel.value || (go && go.disabled)) {
              e.preventDefault();
              return;
            }
            if (sel.value === 'download' && form.getAttribute('data-ready') !== '1') {
              e.preventDefault();
            }
          });
        })(delForms[d]);
      }
      document.querySelectorAll('[data-acc-wid-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var row = document.getElementById('accWid' + btn.getAttribute('data-acc-wid-open'));
          if (!row) return;
          if (row.hasAttribute('hidden')) row.removeAttribute('hidden');
          else row.setAttribute('hidden', 'hidden');
        });
      });
      var dragChip = null;
      function formOf(el) {
        return el && el.closest ? el.closest('form') : null;
      }
      function syncWid(form) {
        var mine = form.querySelector('[data-col="mine"]');
        var input = form.querySelector('[name="widgets"]');
        if (!mine || !input) return;
        var ids = [];
        mine.querySelectorAll('[data-wid]').forEach(function (el) { ids.push(el.getAttribute('data-wid')); });
        input.value = ids.join(',');
      }
      function renumber(form) {
        var mine = form.querySelector('[data-col="mine"]');
        if (!mine) return;
        var n = 0;
        mine.querySelectorAll('.acc-chip').forEach(function (el) {
          n += 1;
          var ord = el.querySelector('.acc-ord');
          if (ord) ord.textContent = String(n);
        });
      }
      function placeChip(col, clientX, clientY) {
        if (!dragChip) return;
        var over = null;
        var chips = col.querySelectorAll('.acc-chip');
        var i;
        for (i = 0; i < chips.length; i++) {
          if (chips[i] === dragChip) continue;
          var r = chips[i].getBoundingClientRect();
          if (clientY >= r.top && clientY <= r.bottom && clientX >= r.left && clientX <= r.right) {
            over = chips[i];
            break;
          }
        }
        if (over) {
          var box = over.getBoundingClientRect();
          var before = col.getAttribute('data-col') === 'mine'
            ? (clientY < box.top + box.height / 2)
            : (clientX < box.left + box.width / 2);
          if (before) {
            if (dragChip.nextSibling !== over) col.insertBefore(dragChip, over);
          } else if (dragChip.previousSibling !== over) {
            col.insertBefore(dragChip, over.nextSibling);
          }
          return;
        }
        if (col.getAttribute('data-col') !== 'mine') {
          if (dragChip.parentNode !== col) col.appendChild(dragChip);
          return;
        }
        var nearest = null;
        var best = 1e9;
        for (i = 0; i < chips.length; i++) {
          if (chips[i] === dragChip) continue;
          var mid = chips[i].getBoundingClientRect();
          var dist = Math.abs(clientY - (mid.top + mid.height / 2));
          if (dist < best) { best = dist; nearest = chips[i]; }
        }
        if (!nearest) {
          if (dragChip.parentNode !== col) col.appendChild(dragChip);
          return;
        }
        var nb = nearest.getBoundingClientRect();
        if (clientY < nb.top + nb.height / 2) {
          if (dragChip.nextSibling !== nearest) col.insertBefore(dragChip, nearest);
        } else if (dragChip.previousSibling !== nearest) {
          col.insertBefore(dragChip, nearest.nextSibling);
        }
      }
      document.querySelectorAll('.acc-wid-form').forEach(function (form) { renumber(form); });
      document.querySelectorAll('.acc-move button').forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) { e.stopPropagation(); });
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var chip = btn.parentNode ? btn.parentNode.parentNode : null;
          if (!chip || !chip.parentNode || chip.parentNode.getAttribute('data-col') !== 'mine') return;
          var col = chip.parentNode;
          if (btn.classList.contains('acc-up')) {
            if (chip.previousElementSibling) col.insertBefore(chip, chip.previousElementSibling);
          } else if (chip.nextElementSibling) {
            col.insertBefore(chip.nextElementSibling, chip);
          }
          var form = formOf(col);
          if (form) { renumber(form); syncWid(form); }
        });
      });
      document.querySelectorAll('.acc-chip').forEach(function (chip) {
        chip.addEventListener('dragstart', function () {
          dragChip = chip;
          chip.classList.add('is-drag');
        });
        chip.addEventListener('dragend', function () {
          chip.classList.remove('is-drag');
          var form = formOf(chip);
          if (form) { renumber(form); syncWid(form); }
          dragChip = null;
        });
      });
      document.querySelectorAll('.acc-col').forEach(function (col) {
        col.addEventListener('dragover', function (e) {
          e.preventDefault();
          col.classList.add('is-over');
          placeChip(col, e.clientX, e.clientY);
        });
        col.addEventListener('dragleave', function () { col.classList.remove('is-over'); });
        col.addEventListener('drop', function (e) {
          e.preventDefault();
          col.classList.remove('is-over');
          placeChip(col, e.clientX, e.clientY);
          var form = formOf(col);
          if (form) { renumber(form); syncWid(form); }
        });
      });
      document.querySelectorAll('.acc-wid-form').forEach(function (form) {
        form.addEventListener('submit', function () { syncWid(form); });
      });
    })();
    </script>
</div>
<?php echo $agentsStockScript; ?>
<?php render_footer(); ?>
