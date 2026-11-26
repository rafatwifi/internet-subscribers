<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('subscribers');

$isNew = isset($_GET['new']) && (string) $_GET['new'] === '1';
$username = isset($_GET['u']) ? trim((string) $_GET['u']) : '';
$isEn = ($lang === 'en');

if (!$isNew && $username === '') {
    flash('error', 'مشترك SAS غير محدد');
    redirect('sas.php');
}

$cache = array();
if (!$isNew) {
    $cache = function_exists('sas_cache_get') ? sas_cache_get($pdo, $username) : null;
    if (!$cache) {
        flash('error', 'المشترك مو موجود بكاش SAS — حدّث القائمة');
        redirect('sas.php');
    }
    $sasUserId = !empty($cache['sas_user_id']) ? (int) $cache['sas_user_id'] : 0;
    if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'sessions') {
        header('Content-Type: application/json; charset=utf-8');
        $storeName = !empty($cache['username']) ? (string) $cache['username'] : $username;
        $localOnly = isset($_GET['local']) && (string) $_GET['local'] === '1';
        if ($localOnly && function_exists('sas_sessions_local_pack')) {
            echo json_encode(sas_sessions_local_pack($pdo, $storeName, $cache));
            exit;
        }
        if (!function_exists('sas_sessions_pull')) {
            echo json_encode(array('ok' => false, 'fresh' => 0, 'rows' => array()));
            exit;
        }
        $pack = sas_sessions_pull($pdo, $config, $storeName, $sasUserId, $cache);
        echo json_encode($pack);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect($isNew ? 'sas_user.php?new=1' : sas_user_url($username));
    }
    $action = post('action');

    if (!$isNew && $action === 'pause_user') {
        $turnOn = post('enabled') === '1';
        $ajaxPause = post('ajax') === '1';
        $pauseOk = false;
        $pauseMsg = $isEn ? 'Could not change the user' : 'تعذر تغيير حالة اليوزر';
        try {
            if (function_exists('sas_write_user')) {
                list($pauseOk, $pauseMsg) = sas_write_user(
                    $pdo,
                    $config,
                    'sas_enable',
                    $username,
                    array('enabled' => $turnOn ? '1' : '0')
                );
            }
        } catch (Exception $ePause) {
            $pauseOk = false;
            $pauseMsg = $isEn ? 'Could not change the user' : 'تعذر تغيير حالة اليوزر';
        }
        if ($ajaxPause) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array(
                'ok' => (bool) $pauseOk,
                'message' => (string) $pauseMsg,
                'enabled' => $turnOn ? 1 : 0,
            ));
            exit;
        }
        flash($pauseOk ? 'success' : 'error', $pauseMsg);
        redirect(sas_user_url($username));
    }

    if (!$isNew && $action === 'update_rental') {
        $enabled = post('rental_enabled') === '1';
        $deviceId = trim((string) post('rental_device_id', ''));
        if (!function_exists('sas_save_user_rental')) {
            flash('error', 'ملف الإيجار غير مكتمل');
            redirect(sas_user_url($username) . '#rental');
        }
        list($ok, $msg) = sas_save_user_rental($pdo, $config, $username, $enabled, $deviceId);
        flash($ok ? 'success' : 'error', $msg);
        redirect(sas_user_url($username) . '#rental');
    }

    if (!$isNew && $action === 'send_identity') {
        list($localIdSend, $errSend) = function_exists('sas_cache_ensure_local')
            ? sas_cache_ensure_local($pdo, $config, $cache)
            : array(0, '');
        $sendPhone = '';
        if ($localIdSend > 0) {
            $stPh = $pdo->prepare('SELECT phone FROM subscribers WHERE id = :id LIMIT 1');
            $stPh->execute(array(':id' => (int) $localIdSend));
            $sendPhone = (string) $stPh->fetchColumn();
        }
        if ($sendPhone === '' && !empty($cache['phone'])) {
            $sendPhone = (string) $cache['phone'];
        }
        $cardText = isset($_POST['card_text']) ? trim((string) $_POST['card_text']) : '';
        if ($cardText === '') {
            flash('error', 'ماكو نص للهوية');
            redirect(sas_user_url($username));
        }
        if (function_exists('sas_sessions_pull')) {
            $packSend = sas_sessions_pull(
                $pdo,
                $config,
                !empty($cache['username']) ? (string) $cache['username'] : $username,
                !empty($cache['sas_user_id']) ? (int) $cache['sas_user_id'] : 0
            );
            $sessLines = array();
            if (is_array($packSend) && !empty($packSend['rows']) && is_array($packSend['rows'])) {
                $n = 0;
                foreach ($packSend['rows'] as $ps) {
                    if ($n >= 8) {
                        break;
                    }
                    $n++;
                    $sessLines[] = ($n) . ') '
                        . (!empty($ps['open']) ? 'متصل' : 'غير متصل')
                        . ' ' . (isset($ps['start']) ? $ps['start'] : '')
                        . ' ' . (isset($ps['ip']) ? $ps['ip'] : '')
                        . ' ' . (isset($ps['used']) ? $ps['used'] : '');
                }
            }
            if ($sessLines) {
                $cardText .= "\nالجلسات:\n" . implode("\n", $sessLines);
            }
        }
        if (function_exists('mb_substr')) {
            $cardText = mb_substr($cardText, 0, 3500, 'UTF-8');
        } elseif (strlen($cardText) > 3500) {
            $cardText = substr($cardText, 0, 3500);
        }
        $result = whatsapp_send($config, $sendPhone, $cardText, 'text');
        if ($localIdSend > 0) {
            log_message($pdo, (int) $localIdSend, $result);
        }
        flash(!empty($result['success']) ? 'success' : 'error', !empty($result['success'])
            ? 'تم إرسال هوية المشترك على واتساب'
            : (function_exists('whatsapp_fail_user_message') ? whatsapp_fail_user_message($result) : 'فشل الإرسال'));
        redirect(sas_user_url($username));
    }

    if (!$isNew && $action === 'su_activate') {
        if (function_exists('app_maintenance_blocks') && app_maintenance_blocks('activate', $config)) {
            flash('error', app_maintenance_message('activate', $lang));
            redirect(sas_user_url($username));
        }
        $isElAct = !empty($cache['provider']) && strtolower((string) $cache['provider']) === 'earthlink';
        if ($isElAct) {
            flash('error', $isEn ? 'Activate from the subscribers table for Earthlink' : 'تفعيل إيرث من جدول المشتركين');
            redirect(sas_user_url($username));
        }
        $pidAct = !empty($cache['profile_id']) ? (int) $cache['profile_id'] : 0;
        $pnameAct = !empty($cache['profile_name']) ? (string) $cache['profile_name'] : '';
        if ($pidAct <= 0) {
            flash('error', $isEn ? 'No package on this subscriber' : 'ماكو باقة على هذا المشترك');
            redirect(sas_user_url($username));
        }
        list($okA, $msgA) = sas_write_user($pdo, $config, 'sas_activate_credit', $username, array(
            'profile_id' => $pidAct,
            'profile_name' => $pnameAct,
            'units' => 1,
            'pay_mode' => 'cash',
        ));
        flash($okA ? 'success' : 'error', $msgA);
        redirect(sas_user_url($username));
    }

    if (!$isNew && $action === 'su_give_day') {
        if (function_exists('app_maintenance_blocks') && app_maintenance_blocks('give_test', $config)) {
            flash('error', app_maintenance_message('give_test', $lang));
            redirect(sas_user_url($username));
        }
        $isElDay = !empty($cache['provider']) && strtolower((string) $cache['provider']) === 'earthlink';
        if ($isElDay) {
            flash('error', $isEn ? '+1 day is for SAS subscribers' : '+1 يوم لمشتركين الساس فقط');
            redirect(sas_user_url($username));
        }
        if (!function_exists('sas_extend_one_day')) {
            flash('error', 'دالة التمديد غير موجودة');
            redirect(sas_user_url($username));
        }
        $extPidPost = (int) post('profile_id', '0');
        list($okD, $msgD) = sas_extend_one_day($pdo, $config, $username, true, $extPidPost);
        flash($okD ? 'success' : 'error', $msgD);
        redirect(sas_user_url($username));
    }

    if (!$isNew && $action === 'update_local_details') {
        $address = (string) post('address', '');
        $notes = (string) post('notes', '');
        if (!function_exists('sas_save_user_local_details')) {
            flash('error', 'تعذر حفظ التفاصيل');
            redirect(sas_user_url($username) . '#local');
        }
        $graceMode = post('grace_mode', 'system');
        $graceRaw = post('grace_days', '');
        list($ok, $msg) = sas_save_user_local_details($pdo, $config, $username, $address, $notes);
        if ($ok && function_exists('sas_save_user_grace_days')) {
            $graceVal = ($graceMode === 'custom') ? $graceRaw : 'system';
            list($gOk, $gMsg) = sas_save_user_grace_days($pdo, $config, $username, $graceVal);
            if (!$gOk) {
                $ok = false;
                $msg = $gMsg;
            }
        }
        if ($ok && function_exists('sas_save_user_auto_activate')) {
            $autoOn = post('auto_activate_on_expire') === '1';
            $autoNotify = $autoOn && post('auto_activate_notify') === '1';
            list($aOk, $aMsg) = sas_save_user_auto_activate($pdo, $config, $username, $autoOn, $autoNotify);
            if (!$aOk) {
                $ok = false;
                $msg = $aMsg;
            } elseif ($aMsg !== '') {
                $msg = $aMsg;
            }
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect(sas_user_url($username));
    }

    if (!$isNew && ($action === 'msg_rental_only' || $action === 'msg_rental_return')) {
        list($localId, $err) = sas_cache_ensure_local($pdo, $config, $cache);
        if ($localId <= 0) {
            flash('error', $err !== '' ? $err : 'تعذر ربط المشترك');
            redirect(sas_user_url($username) . '#rental');
        }
        $st = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $st->execute(array(':id' => $localId));
        $sub = $st->fetch();
        if (!$sub || !subscriber_has_rental($sub)) {
            flash('error', 'لا يوجد جهاز إيجار لهذا المشترك');
            redirect(sas_user_url($username) . '#rental');
        }
        if ($action === 'msg_rental_only') {
            $msg = rental_only_message($sub, $config);
            $result = whatsapp_send($config, $sub['phone'], $msg, 'rental_fee');
            log_message($pdo, $localId, $result);
            flash(!empty($result['success']) ? 'success' : 'error', !empty($result['success']) ? 'تم إرسال رسالة الإيجار' : whatsapp_fail_user_message($result));
            redirect(sas_user_url($username) . '#rental');
        }
        $msg = rental_return_message($sub, $config);
        $result = whatsapp_send($config, $sub['phone'], $msg, 'rental_return');
        log_message($pdo, $localId, $result);
        if (function_exists('activity_log')) {
            activity_log($pdo, $localId, 'subscriber', $localId, 'rental_return', 'طلب استرجاع جهاز', 'اليوزرنيم: ' . $username);
        }
        flash(!empty($result['success']) ? 'success' : 'error', !empty($result['success']) ? 'تم إرسال طلب استرجاع الجهاز' : whatsapp_fail_user_message($result));
        redirect(sas_user_url($username) . '#rental');
    }

    $fields = array(
        'username' => post('username', ''),
        'firstname' => post('firstname', ''),
        'lastname' => post('lastname', ''),
        'phone' => post('phone', ''),
        'city' => post('city', ''),
        'email' => post('email', ''),
        'company' => post('company', ''),
        'enabled' => post('enabled') === '1' ? '1' : '0',
        'profile_id' => post('profile_id', '0'),
        'parent_id' => post('parent_id', '0'),
        'password' => post('password', ''),
        'confirm_password' => post('confirm_password', ''),
    );
    $isElUser = !$isNew && !empty($cache['provider']) && strtolower((string) $cache['provider']) === 'earthlink';
    $pidRaw = trim((string) $fields['profile_id']);
    $parRaw = trim((string) $fields['parent_id']);
    $pid = (int) $pidRaw;
    $par = (int) $parRaw;
    if ($isElUser && function_exists('earthlink_package_name_from_ui_id')) {
        $elProf = earthlink_package_name_from_ui_id($pidRaw);
        if ($elProf !== '') {
            $fields['profile_name'] = $elProf;
            $fields['profile_id'] = '0';
            $pid = 0;
        }
        if (stripos($parRaw, 'elp:') === 0) {
            $fields['parent_name'] = rawurldecode(substr($parRaw, 4));
            $fields['parent_id'] = '0';
            $par = 0;
        }
    }
    if (isset($_SESSION['sas_profiles_ui']) && is_array($_SESSION['sas_profiles_ui'])) {
        foreach ($_SESSION['sas_profiles_ui'] as $pr) {
            if ((string) $pr['id'] === (string) $pidRaw || (int) $pr['id'] === $pid) {
                $fields['profile_name'] = $pr['name'];
                break;
            }
        }
    }
    if (isset($_SESSION['sas_managers_ui']) && is_array($_SESSION['sas_managers_ui'])) {
        foreach ($_SESSION['sas_managers_ui'] as $mn) {
            if ((string) $mn['id'] === (string) $parRaw || (int) $mn['id'] === $par) {
                $fields['parent_name'] = $mn['name'];
                break;
            }
        }
    }
    if (!$isElUser && (empty($fields['profile_name']) || empty($fields['parent_name']))) {
        $apiTmp = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
        if ($apiTmp) {
            if (empty($fields['profile_name'])) {
                foreach (sas_profiles_for_ui($apiTmp) as $pr) {
                    if ((int) $pr['id'] === $pid) {
                        $fields['profile_name'] = $pr['name'];
                        break;
                    }
                }
            }
            if (empty($fields['parent_name'])) {
                foreach (sas_managers_for_ui($apiTmp) as $mn) {
                    if ((int) $mn['id'] === $par) {
                        $fields['parent_name'] = $mn['name'];
                        break;
                    }
                }
            }
        }
    }
    // إيرث لنك: اكتب على البوابة ثم حدّث الكاش
    if ($isElUser && ($action === 'sas_update_info' || $action === 'sas_create')) {
        if ($action === 'sas_create') {
            flash('error', $isEn ? 'Create via Earthlink portal is not supported yet' : 'إنشاء مشترك إيرث من هنا غير مدعوم بعد');
            redirect('sas_user.php?new=1');
        }
        // حقل الاسم بالواجهة = الاسم العربي (ArabicName / DisplayName) على إيرث
        $elName = trim((string) $fields['firstname']);
        $elChanges = array(
            'arabic_name' => $elName,
            'display_name' => $elName,
            'enabled' => (int) $fields['enabled'],
        );
        $curProf = isset($cache['profile_name']) ? trim((string) $cache['profile_name']) : '';
        $newProf = !empty($fields['profile_name']) ? trim((string) $fields['profile_name']) : '';
        if ($newProf !== '' && strcasecmp($newProf, $curProf) !== 0) {
            $elChanges['profile_name'] = $newProf;
        }
        if (!empty($fields['password'])) {
            $elChanges['password'] = $fields['password'];
            $elChanges['confirm_password'] = $fields['confirm_password'];
        }
        $remote = array('ok' => false, 'error' => 'دالة التحديث غير موجودة');
        if (function_exists('earthlink_update_user_for_cache')) {
            $remote = earthlink_update_user_for_cache($pdo, $cache, $elChanges);
        }
        if (empty($remote['ok'])) {
            flash('error', isset($remote['error']) ? $remote['error'] : ($isEn ? 'Earthlink update failed' : 'فشل الحفظ على إيرث لنك'));
            redirect(sas_user_url($username));
        }
        $savedName = !empty($remote['display_name']) ? trim((string) $remote['display_name']) : $elName;
        $patch = array(
            'display_name' => $savedName,
            'lastname' => $fields['lastname'],
            'phone' => $fields['phone'],
            'city' => $fields['city'],
            'email' => $fields['email'],
            'company' => $fields['company'],
            'enabled' => (int) $fields['enabled'],
        );
        // لا تستبدل firstname الإنجليزي بالبوابة — خلّي العرض على display_name
        if ($newProf !== '') {
            $patch['profile_name'] = !empty($remote['account_name']) ? $remote['account_name'] : $newProf;
            $patch['profile_id'] = null;
        }
        if (!empty($fields['parent_name'])) {
            $patch['parent_name'] = $fields['parent_name'];
        }
        if (function_exists('sas_cache_patch')) {
            sas_cache_patch($pdo, $username, $patch);
        }
        // حدّث الاسم المحلي ليطابق DisplayName
        if ($savedName !== '' && function_exists('sas_cache_ensure_local')) {
            list($localIdUp) = sas_cache_ensure_local($pdo, $config, array_merge($cache, $patch));
            if ($localIdUp > 0) {
                try {
                    $pdo->prepare('UPDATE subscribers SET name = :n WHERE id = :id')
                        ->execute(array(':n' => $savedName, ':id' => (int) $localIdUp));
                } catch (Exception $e) {
                }
            }
        }
        flash('success', isset($remote['message']) ? $remote['message'] : ($isEn ? 'Saved on Earthlink' : 'تم الحفظ على إيرث لنك'));
        redirect(sas_user_url($username));
    }
    // إنشاء مشترك: حدّد حساب الشركة المختارة
    $createCompanyId = (int) post('company_id', '0');
    $createAccountId = (int) post('account_id', '0');
    if (($isNew || $action === 'sas_create') && function_exists('tenant_sas_accounts_list')) {
        $createTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
        $pickedAcc = null;
        foreach (tenant_sas_accounts_list($pdo, $createTid) as $accRow) {
            $aid = isset($accRow['id']) ? (int) $accRow['id'] : 0;
            $acid = !empty($accRow['company_id']) ? (int) $accRow['company_id'] : 0;
            if ($createAccountId > 0 && $aid === $createAccountId) {
                $pickedAcc = $accRow;
                break;
            }
            if ($createAccountId <= 0 && $createCompanyId > 0 && $acid === $createCompanyId) {
                $pickedAcc = $accRow;
                break;
            }
        }
        if ($pickedAcc && function_exists('tenant_sas_account_is_earthlink')
            && tenant_sas_account_is_earthlink($pdo, $pickedAcc)
        ) {
            flash('error', $isEn ? 'Create via Earthlink is not supported yet — pick a SAS company' : 'إنشاء مشترك إيرث غير مدعوم بعد — اختار شركة ساس');
            redirect('sas_user.php?new=1');
        }
        if ($pickedAcc) {
            $GLOBALS['sas_force_account'] = $pickedAcc;
        } elseif ($createCompanyId > 0 || $createAccountId > 0) {
            flash('error', $isEn ? 'Choose a valid company' : 'اختار شركة صحيحة');
            redirect('sas_user.php?new=1');
        }
    }
    if ($isNew || $action === 'sas_create') {
        list($ok, $msg, $extra) = sas_write_user($pdo, $config, 'sas_create', '', $fields);
        flash($ok ? 'success' : 'error', $msg);
        if ($ok && !empty($extra['username'])) {
            redirect(sas_user_url($extra['username']));
        }
        redirect('sas_user.php?new=1');
    }
    list($ok, $msg, $extra) = sas_write_user($pdo, $config, 'sas_update_info', $username, $fields);
    flash($ok ? 'success' : 'error', $msg);
    if (!$ok) {
        redirect(sas_user_url($username));
    }
    $go = (!empty($extra['username'])) ? $extra['username'] : $username;
    $anchor = 'sas-row-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $go);
    redirect('sas.php?q=' . rawurlencode($go) . '#' . $anchor);
}

$fn = isset($cache['firstname']) ? $cache['firstname'] : '';
$ln = isset($cache['lastname']) ? $cache['lastname'] : '';
$phone = isset($cache['phone']) ? $cache['phone'] : '';
$enabled = $isNew ? true : !empty($cache['enabled']);
$isEarthlinkUser = !$isNew && !empty($cache['provider']) && strtolower((string) $cache['provider']) === 'earthlink';
$elCompanyId = ($isEarthlinkUser && !empty($cache['company_id'])) ? (int) $cache['company_id'] : 0;
// إيرث: حقل الاسم القابل للتعديل = الاسم العربي (display_name / ArabicName)
if ($isEarthlinkUser && !empty($cache['display_name'])) {
    $fn = (string) $cache['display_name'];
}
$companyPickOptions = array();
if ($isNew && function_exists('tenant_sas_accounts_list')) {
    $pickTid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
    foreach (tenant_sas_accounts_list($pdo, $pickTid) as $accOpt) {
        if (empty($accOpt['sas_enabled'])) {
            continue;
        }
        $cid = !empty($accOpt['company_id']) ? (int) $accOpt['company_id'] : 0;
        $prov = function_exists('tenant_sas_account_provider')
            ? tenant_sas_account_provider($pdo, $accOpt)
            : 'sas';
        $coName = isset($accOpt['label']) ? trim((string) $accOpt['label']) : '';
        if ($cid > 0 && function_exists('platform_company_row')) {
            $coRow = platform_company_row($pdo, $cid);
            if ($coRow && !empty($coRow['name'])) {
                $coName = (string) $coRow['name'];
            }
        }
        if ($coName === '') {
            $coName = $prov === 'earthlink' ? 'Earthlink' : 'SAS';
        }
        $companyPickOptions[] = array(
            'account_id' => isset($accOpt['id']) ? (int) $accOpt['id'] : 0,
            'company_id' => $cid,
            'name' => $coName,
            'provider' => $prov,
        );
    }
}
$needCompanyPick = $isNew && count($companyPickOptions) > 1;
$profileId = !empty($cache['profile_id']) ? (int) $cache['profile_id'] : 0;
$parentId = !empty($cache['parent_id']) ? (int) $cache['parent_id'] : 0;
$profileUiId = (string) $profileId;
$parentUiId = (string) $parentId;
$profileLabelNow = !empty($cache['profile_name']) ? (string) $cache['profile_name'] : '';
$parentLabelNow = !empty($cache['parent_name']) ? (string) $cache['parent_name'] : '';
if ($isEarthlinkUser && $profileLabelNow !== '' && function_exists('earthlink_package_ui_id')) {
    $profileUiId = earthlink_package_ui_id($profileLabelNow);
}
if ($isEarthlinkUser && $parentLabelNow !== '') {
    $parentUiId = 'elp:' . rawurlencode($parentLabelNow);
}
if ($isNew && function_exists('sas_config')) {
    $sc = sas_config($config);
    if ($parentId <= 0 && !empty($sc['parent_id'])) {
        $parentId = (int) $sc['parent_id'];
        $parentUiId = (string) $parentId;
    }
}

$profiles = array();
$managers = array();
if (!empty($needCompanyPick)) {
    // مشترك جديد + أكثر من شركة: لا تملأ بروفايلات قبل اختيار الشركة
    $profiles = array();
    $managers = array();
} elseif ($isEarthlinkUser && function_exists('earthlink_packages_for_ui')) {
    $profiles = earthlink_packages_for_ui($pdo, $config, $elCompanyId);
    if ($profileLabelNow !== '' && function_exists('earthlink_package_ui_id')) {
        $have = false;
        foreach ($profiles as $pr) {
            if (isset($pr['name']) && strcasecmp((string) $pr['name'], $profileLabelNow) === 0) {
                $have = true;
                break;
            }
        }
        if (!$have) {
            array_unshift($profiles, array(
                'id' => earthlink_package_ui_id($profileLabelNow),
                'name' => $profileLabelNow,
                'price' => 0,
                'provider' => 'earthlink',
            ));
        }
    }
    if ($parentLabelNow !== '') {
        $managers[] = array('id' => 'elp:' . rawurlencode($parentLabelNow), 'name' => $parentLabelNow);
    }
} else {
    if (!$isNew) {
        if ($profileId > 0) {
            $profiles[] = array(
                'id' => $profileId,
                'name' => $profileLabelNow !== '' ? $profileLabelNow : ('#' . $profileId),
            );
        }
        if ($parentId > 0) {
            $managers[] = array(
                'id' => $parentId,
                'name' => $parentLabelNow !== '' ? $parentLabelNow : ('#' . $parentId),
            );
        }
    }
    if (!$isNew || count($companyPickOptions) <= 1) {
        if (isset($_SESSION['sas_profiles_ui']) && is_array($_SESSION['sas_profiles_ui']) && $_SESSION['sas_profiles_ui']) {
            $profiles = $_SESSION['sas_profiles_ui'];
        }
        if (isset($_SESSION['sas_managers_ui']) && is_array($_SESSION['sas_managers_ui']) && $_SESSION['sas_managers_ui']) {
            $managers = $_SESSION['sas_managers_ui'];
        }
    }
}

$pageTitle = $isNew
    ? ($isEn ? 'New User' : 'مشترك جديد')
    : ($username !== '' ? $username : ($isEn ? 'Edit User' : 'تعديل مشترك'));

// جلسات محلية جاهزة فوراً — بدون انتظار المزود
$sessionsSeed = array('ok' => true, 'fresh' => 0, 'from_cache' => 1, 'rows' => array(), 'month_used' => '', 'month_up' => '', 'month_down' => '');
if (!$isNew && $username !== '' && function_exists('sas_sessions_local_pack')) {
    $sessionsSeed = sas_sessions_local_pack($pdo, $username, $cache);
}

$localSub = null;
if (!$isNew && $cache) {
    if (!empty($cache['local_subscriber_id'])) {
        $stLocal = $pdo->prepare('SELECT * FROM subscribers WHERE id = :id');
        $stLocal->execute(array(':id' => (int) $cache['local_subscriber_id']));
        $localSub = $stLocal->fetch();
    }
    if (!$localSub && function_exists('sas_cache_pick_local')) {
        $localSub = sas_cache_pick_local(
            $pdo,
            $username,
            !empty($cache['sas_user_id']) ? $cache['sas_user_id'] : 0,
            isset($cache['phone']) ? $cache['phone'] : ''
        );
    }
}
$settingsRental = function_exists('settings_load') ? settings_load() : array();
$rentalDevices = function_exists('rental_devices_list') ? rental_devices_list($settingsRental) : array();
$rentalFee = function_exists('rental_fee_amount') ? rental_fee_amount($settingsRental) : 0;
$hasRent = $localSub && function_exists('subscriber_has_rental') && subscriber_has_rental($localSub);
$currentRentDev = ($hasRent && function_exists('rental_device_by_id'))
    ? rental_device_by_id($localSub['rental_device_id'], $settingsRental)
    : null;
$localAddress = ($localSub && isset($localSub['address'])) ? $localSub['address'] : '';
$localNotes = ($localSub && isset($localSub['notes'])) ? $localSub['notes'] : '';
$graceIsCustom = $localSub && function_exists('subscriber_grace_is_custom') && subscriber_grace_is_custom($localSub);
$localGraceNum = $graceIsCustom ? (int) $localSub['grace_days'] : (function_exists('subscriber_default_grace_days') ? subscriber_default_grace_days($config) : 3);
$sysGraceNum = function_exists('subscriber_default_grace_days') ? subscriber_default_grace_days($config) : 3;
$autoActivateOn = $localSub && function_exists('subscriber_auto_activate_on_expire')
    ? subscriber_auto_activate_on_expire($localSub)
    : ($localSub && !empty($localSub['auto_activate_on_expire']));
$localId = ($localSub && !empty($localSub['id'])) ? (int) $localSub['id'] : 0;
$debtTotal = 0.0;
$debtRows = array();
if (!$isNew && $localId > 0 && function_exists('subscriber_unpaid_total')) {
    $debtTotal = (float) subscriber_unpaid_total($pdo, $localId);
    try {
        $stDebt = $pdo->prepare(
            'SELECT id, month_label, amount, due_date, notes
             FROM invoices
             WHERE subscriber_id = :id AND status = "unpaid"
             ORDER BY due_date ASC, id ASC
             LIMIT 8'
        );
        $stDebt->execute(array(':id' => $localId));
        $debtRows = $stDebt->fetchAll();
    } catch (Exception $eDebt) {
        $debtRows = array();
    }
}
$dispName = '';
if (!empty($isEarthlinkUser) && !empty($cache['display_name'])) {
    $dispName = trim((string) $cache['display_name']);
}
if ($dispName === '') {
    $dispName = trim($fn . ' ' . $ln);
}
if ($dispName === '' && !empty($cache['display_name'])) {
    $dispName = trim((string) $cache['display_name']);
}
if ($dispName === '') {
    $dispName = $username;
}
$profileLabel = !empty($cache['profile_name']) ? (string) $cache['profile_name'] : '';
$parentLabel = !empty($cache['parent_name']) ? (string) $cache['parent_name'] : '';
$expireRaw = isset($cache['expire_at']) ? (string) $cache['expire_at'] : '';
$expireShow = function_exists('sas_format_expire_display') ? sas_format_expire_display($expireRaw) : $expireRaw;
$daysLeft = function_exists('sas_remaining_days') ? sas_remaining_days($expireRaw) : '';
$isOnlineNow = !empty($cache['is_online']);
$ipShow = isset($cache['framed_ip']) ? trim((string) $cache['framed_ip']) : '';
$macShow = isset($cache['mac_address']) ? trim((string) $cache['mac_address']) : '';
$phoneShow = function_exists('format_phone_display') ? format_phone_display($phone) : $phone;
$paidTotal = 0.0;
$lastMsgRow = null;
$msgState = $isEn ? 'Not sent' : 'ما انرسلت';
$msgHint = '';
if (!$isNew && $localId > 0) {
    if (function_exists('subscriber_paid_total')) {
        $paidTotal = (float) subscriber_paid_total($pdo, $localId);
    }
    try {
        $stMsg = $pdo->prepare(
            'SELECT success, message_type, body, response_json, phone
             FROM message_logs WHERE subscriber_id = :id ORDER BY id DESC LIMIT 1'
        );
        $stMsg->execute(array(':id' => $localId));
        $lastMsgRow = $stMsg->fetch();
    } catch (Exception $eMsg) {
        $lastMsgRow = null;
    }
    if ($lastMsgRow) {
        $noWa = function_exists('subscriber_msg_is_no_whatsapp')
            && subscriber_msg_is_no_whatsapp(isset($lastMsgRow['response_json']) ? $lastMsgRow['response_json'] : '');
        if (!empty($lastMsgRow['success'])) {
            $msgState = $isEn ? 'Sent' : 'أُرسلت';
        } elseif ($noWa) {
            $msgState = $isEn ? 'No WhatsApp' : 'ماكو واتساب';
        } else {
            $msgState = $isEn ? 'Failed' : 'فشلت';
        }
        if (function_exists('message_short_summary')) {
            $msgHint = message_short_summary(
                isset($lastMsgRow['message_type']) ? $lastMsgRow['message_type'] : '',
                isset($lastMsgRow['body']) ? $lastMsgRow['body'] : '',
                !empty($lastMsgRow['success'])
            );
        }
        if ($noWa) {
            $msgHint = $isEn ? 'This number is not on WhatsApp' : 'لا يتوفر واتساب لدى المشترك';
        }
    }
}
$daysShow = ($daysLeft === '' || $daysLeft === null) ? '—' : (string) $daysLeft;
$graceShow = $graceIsCustom
    ? ((string) (int) $localGraceNum . ($isEn ? ' days' : ' يوم'))
    : ($isEn ? ('System (' . (int) $sysGraceNum . ')') : ('حسب النظام (' . (int) $sysGraceNum . ')'));
$idCardText = '';
if (!$isNew) {
    $idBits = array();
    $idBits[] = ($isEn ? 'Subscriber card' : 'هوية المشترك');
    $idBits[] = ($isEn ? 'Name: ' : 'الاسم: ') . $dispName;
    $idBits[] = ($isEn ? 'Username: ' : 'اليوزر: ') . $username;
    $idBits[] = ($isEn ? 'Phone: ' : 'الهاتف: ') . ($phoneShow !== '' ? $phoneShow : '—');
    $idBits[] = ($isEn ? 'Profile: ' : 'الباقة: ') . ($profileLabel !== '' ? $profileLabel : '—');
    $subExpired = ($daysLeft !== '' && $daysLeft !== null && (int) $daysLeft < 0);
    $statusLabel = !$enabled
        ? ($isEn ? 'Disabled' : 'موقف')
        : ($subExpired ? ($isEn ? 'Expired' : 'منتهي') : ($isEn ? 'Active' : 'فعّال'));
    $idBits[] = ($isEn ? 'Status: ' : 'الحالة: ') . $statusLabel;
    $idBits[] = ($isEn ? 'Link: ' : 'الاتصال: ') . ($isOnlineNow ? ($isEn ? 'Online' : 'متصل') : ($isEn ? 'Offline' : 'غير متصل'));
    $idBits[] = ($isEn ? 'Days left: ' : 'الأيام المتبقية: ') . $daysShow;
    $idBits[] = ($isEn ? 'Debt: ' : 'الدين: ') . money_format_iqd($debtTotal, $config['currency']);
    $idBits[] = ($isEn ? 'Received: ' : 'المستلم: ') . money_format_iqd($paidTotal, $config['currency']);
    $idBits[] = ($isEn ? 'Message: ' : 'الرسالة: ') . $msgState . ($msgHint !== '' ? (' — ' . $msgHint) : '');
    $idBits[] = ($isEn ? 'Grace: ' : 'أيام السماح: ') . $graceShow;
    $idBits[] = ($isEn ? 'Expires: ' : 'الانتهاء: ') . ($expireShow !== '' ? $expireShow : '—');
    if ($hasRent) {
        $idBits[] = ($isEn ? 'Rental: ' : 'إيجار: ') . ($currentRentDev && !empty($currentRentDev['name']) ? $currentRentDev['name'] : ($isEn ? 'Yes' : 'نعم'));
    }
    $idCardText = implode("\n", $idBits);
}

if (!$isNew && isset($_GET['print']) && (string) $_GET['print'] === '1') {
    $printSessions = array();
    if (function_exists('sas_sessions_pull')) {
        $packPrint = sas_sessions_pull($pdo, $config, $username, !empty($cache['sas_user_id']) ? (int) $cache['sas_user_id'] : 0);
        if (is_array($packPrint) && !empty($packPrint['rows']) && is_array($packPrint['rows'])) {
            $printSessions = $packPrint['rows'];
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    $printTitle = $dispName !== '' ? $dispName : $username;
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>'
        . e($printTitle) . '</title><style>
        body{font-family:Tahoma,Arial,sans-serif;color:#0f172a;margin:24px;background:#fff}
        h1{margin:0 0 4px;font-size:22px}
        .sub{margin:0 0 14px;color:#475569}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0}
        .cell{border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px}
        .cell span{display:block;font-size:11px;color:#64748b;font-weight:700}
        .cell b{font-size:14px}
        table{width:100%;border-collapse:collapse;margin-top:8px;font-size:12px}
        th,td{border-bottom:1px solid #e2e8f0;padding:6px 4px;text-align:right}
        th{background:#f8fafc}
        @media print { body{margin:12px} .noprint{display:none} }
        </style></head><body>';
    echo '<h1>' . e($printTitle) . '</h1>';
    echo '<p class="sub" dir="ltr">' . e($username) . '</p>';
    echo '<div class="grid">';
    $printCells = array(
        array($isEn ? 'Phone' : 'الهاتف', $phoneShow !== '' ? $phoneShow : '—'),
        array($isEn ? 'Profile' : 'الباقة', $profileLabel !== '' ? $profileLabel : '—'),
        array($isEn ? 'Status' : 'الحالة', $enabled ? ($isEn ? 'Enabled' : 'مفعّل') : ($isEn ? 'Disabled' : 'موقف')),
        array($isEn ? 'Link' : 'الاتصال', $isOnlineNow ? ($isEn ? 'Online' : 'متصل') : ($isEn ? 'Offline' : 'غير متصل')),
        array($isEn ? 'Days left' : 'الأيام المتبقية', $daysShow),
        array($isEn ? 'Debt' : 'الدين', money_format_iqd($debtTotal, $config['currency'])),
        array($isEn ? 'Received' : 'المستلم', money_format_iqd($paidTotal, $config['currency'])),
        array($isEn ? 'Message' : 'الرسالة', $msgState),
        array($isEn ? 'Grace' : 'أيام السماح', $graceShow),
        array($isEn ? 'Expires' : 'الانتهاء', $expireShow !== '' ? $expireShow : '—'),
        array($isEn ? 'Belongs to' : 'تابع الى', $parentLabel !== '' ? $parentLabel : '—'),
        array('IP', $ipShow !== '' ? $ipShow : '—'),
        array('MAC', $macShow !== '' ? $macShow : '—'),
    );
    if ($hasRent) {
        $printCells[] = array($isEn ? 'Rental' : 'إيجار', $currentRentDev && !empty($currentRentDev['name']) ? $currentRentDev['name'] : ($isEn ? 'Yes' : 'نعم'));
    }
    if ($localAddress !== '') {
        $printCells[] = array($isEn ? 'Address' : 'العنوان', $localAddress);
    }
    if ($msgHint !== '') {
        $printCells[] = array($isEn ? 'Last message' : 'آخر رسالة', $msgHint);
    }
    foreach ($printCells as $cell) {
        echo '<div class="cell"><span>' . e($cell[0]) . '</span><b>' . e($cell[1]) . '</b></div>';
    }
    echo '</div>';
    echo '<h2 style="font-size:16px;margin:16px 0 6px">' . e($isEn ? 'Sessions' : 'الجلسات') . '</h2>';
    if (!$printSessions) {
        echo '<p>' . e($isEn ? 'No sessions' : 'ماكو جلسات') . '</p>';
    } else {
        echo '<table><thead><tr><th>' . e($isEn ? 'Status' : 'الحالة') . '</th><th>MAC</th><th>IP</th><th>'
            . e($isEn ? 'Start' : 'البداية') . '</th><th>' . e($isEn ? 'End' : 'النهاية') . '</th><th>'
            . e($isEn ? 'Usage' : 'الاستهلاك') . '</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($printSessions as $ps) {
            if ($shown >= 40) {
                break;
            }
            $shown++;
            $openPs = !empty($ps['open']);
            echo '<tr><td>' . e($openPs ? ($isEn ? 'Online' : 'متصل') : ($isEn ? 'Offline' : 'غير متصل')) . '</td>'
                . '<td dir="ltr">' . e(isset($ps['mac']) ? $ps['mac'] : '—') . '</td>'
                . '<td dir="ltr">' . e(isset($ps['ip']) ? $ps['ip'] : '—') . '</td>'
                . '<td dir="ltr">' . e(isset($ps['start']) ? $ps['start'] : '—') . '</td>'
                . '<td dir="ltr">' . e(isset($ps['stop']) ? $ps['stop'] : '—') . '</td>'
                . '<td dir="ltr">' . e(isset($ps['used']) ? $ps['used'] : '—') . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<p class="noprint" style="margin-top:16px"><button onclick="window.print()">'
        . e($isEn ? 'Save PDF' : 'حفظ PDF') . '</button></p>';
    echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},250);});</script>';
    echo '</body></html>';
    exit;
}

render_header($pageTitle, 'sas', $isNew ? '' : $username);
?>
<style>
.su-page { max-width: 1120px; margin: 0 auto 28px; }
.su-hero {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
  padding: 18px 20px; border-radius: 18px; color: #f8fafc;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 70%, #1d4ed8 140%);
  box-shadow: 0 14px 34px rgba(15, 23, 42, .16);
}
.su-hero-main { flex: 1; min-width: 0; }
.su-hero-who { display: flex; align-items: center; gap: 14px; min-width: 0; }
.su-hero h1 { margin: 0 0 4px; font-size: 22px; font-weight: 800; letter-spacing: -.02em; }
.su-avatar {
  width: 64px; height: 64px; flex: 0 0 auto; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 26px; font-weight: 800; line-height: 1; color: #fff;
  background: radial-gradient(circle at 32% 28%, rgba(255,255,255,.5), rgba(255,255,255,.08) 46%, rgba(14,165,233,.45) 100%);
  border: 2px solid rgba(255,255,255,.75);
  box-shadow: 0 10px 22px rgba(2,6,23,.28), inset 0 0 0 4px rgba(255,255,255,.12);
}
.su-hero .su-user { margin: 0; font-size: 13px; font-weight: 700; opacity: .82; direction: ltr; text-align: right; }
.su-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.su-pill {
  display: inline-flex; align-items: center; height: 26px; padding: 0 10px;
  border-radius: 999px; font-size: 12px; font-weight: 800;
  background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.16);
}
.su-pill.is-on { background: #16a34a; border-color: #16a34a; }
.su-pill.is-off { background: rgba(255,255,255,.08); }
.su-pill.is-down { background: #dc2626; border-color: #dc2626; }
.su-pill.is-rent { background: #0284c7; border-color: #0284c7; }
.su-pill svg { width: 14px; height: 14px; margin-inline-end: 4px; }
.su-stats {
  display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px 14px; margin-top: 14px; padding-top: 12px;
  border-top: 1px solid rgba(255,255,255,.16);
}
.su-stat span { display: block; font-size: 11px; font-weight: 700; opacity: .75; margin-bottom: 2px; }
.su-stat strong { display: block; font-size: 15px; font-weight: 800; line-height: 1.35; }
.su-hero-side { display: flex; flex-direction: column; gap: 8px; align-items: stretch; }
.su-hero-side .su-back, .su-hero-side button.su-back { justify-content: center; cursor: pointer; font: inherit; }
.su-hero-acts {
  display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
  margin: 0 0 14px; padding: 0 0 12px;
  border-bottom: 1px solid rgba(255,255,255,.16);
}
.su-hero-acts .su-quick-form { margin: 0; }
.su-hero-act-btn,
.su-hero-day-btn {
  position: relative; overflow: hidden;
  height: 40px; padding: 0 16px; border: 0; border-radius: 12px;
  font: inherit; font-weight: 800; font-size: 14px; cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  color: #fff; text-decoration: none;
}
.su-hero-act-btn {
  background: linear-gradient(135deg, #16a34a 0%, #22c55e 55%, #4ade80 100%);
  box-shadow: 0 10px 22px rgba(22, 163, 74, 0.35);
  animation: suHeroPulse 1.9s ease-in-out infinite;
}
.su-hero-act-btn::before {
  content: ''; position: absolute; inset: -40% auto auto -30%;
  width: 45%; height: 180%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.35), transparent);
  transform: rotate(18deg); animation: suHeroShine 2.2s ease-in-out infinite; pointer-events: none;
}
.su-hero-act-btn:hover { filter: brightness(1.06); color: #fff; }
.su-hero-day-btn {
  background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.28) !important;
  box-shadow: 0 6px 14px rgba(2, 6, 23, 0.18);
}
.su-hero-day-btn:hover { background: rgba(255,255,255,.22); color: #fff; }
.su-hero-pause-btn {
  background: linear-gradient(135deg, #ea580c 0%, #f97316 55%, #fb923c 100%);
  border: 0 !important;
  box-shadow: 0 10px 22px rgba(234, 88, 12, 0.35);
  gap: 8px;
}
.su-hero-pause-btn:hover { filter: brightness(1.06); color: #fff; background: linear-gradient(135deg, #c2410c 0%, #ea580c 55%, #f97316 100%); }
.su-hero-pause-btn.is-off {
  background: linear-gradient(135deg, #16a34a 0%, #22c55e 55%, #4ade80 100%);
  box-shadow: 0 10px 22px rgba(22, 163, 74, 0.35);
}
.su-hero-pause-btn.is-off:hover { background: linear-gradient(135deg, #15803d 0%, #16a34a 55%, #22c55e 100%); }
.su-hero-ico {
  width: 16px; height: 16px; display: inline-block; flex: 0 0 auto;
  fill: currentColor;
}
.su-hero-act-btn:disabled, .su-hero-day-btn:disabled, .su-hero-pause-btn:disabled { opacity: .55; cursor: not-allowed; filter: none; animation: none; }
@keyframes suHeroPulse {
  0%, 100% { box-shadow: 0 10px 22px rgba(22, 163, 74, 0.35), 0 0 0 0 rgba(74, 222, 128, 0.35); }
  50% { box-shadow: 0 12px 26px rgba(22, 163, 74, 0.42), 0 0 0 10px rgba(74, 222, 128, 0); }
}
@keyframes suHeroShine {
  0% { left: -40%; opacity: 0; }
  30% { opacity: 1; }
  100% { left: 120%; opacity: 0; }
}
.su-panel[hidden] { display: none !important; }
.su-nav button.is-open { background: #0f172a; color: #fff; border-color: #0f172a; }
.id-ask {
  position: fixed; inset: 0; z-index: 80; display: grid; place-items: center;
  background: rgba(15, 23, 42, .45); padding: 16px;
}
.id-ask[hidden] { display: none !important; }
.id-ask .box {
  width: min(420px, 100%); background: #fff; color: #0f172a; border-radius: 16px;
  padding: 16px; box-shadow: 0 18px 40px rgba(0,0,0,.2);
}
.id-ask h3 { margin: 0 0 6px; }
.id-ask p { margin: 0 0 14px; color: #475569; font-size: 13px; }
.id-ask .row { display: flex; gap: 8px; flex-wrap: wrap; }
.id-ask .row .btn, .id-ask .row button, .id-ask .row a { flex: 1; text-align: center; }
.su-back {
  flex: 0 0 auto; height: 34px; padding: 0 14px; border-radius: 999px;
  background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.2);
  text-decoration: none; font-weight: 800; font-size: 13px; display: inline-flex; align-items: center;
}
.su-nav {
  display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0;
}
.su-nav a, .su-nav button {
  display: inline-flex; align-items: center; justify-content: center;
  height: 36px; padding: 0 16px; border-radius: 999px; border: 1px solid #e2e8f0;
  background: #fff; color: #334155; font: inherit; font-size: 13px; font-weight: 800;
  line-height: 1; text-align: center; text-decoration: none; cursor: pointer;
}
.su-nav a:hover, .su-nav button:hover { border-color: #93c5fd; color: #1d4ed8; }
.su-nav button.is-on { background: #fff; color: #334155; border-color: #e2e8f0; }
.su-split { display: grid; grid-template-columns: 1.15fr .85fr; gap: 12px; margin-bottom: 12px; }
.su-card {
  background: #fff; border: 1px solid #e6ebf2; border-radius: 18px;
  box-shadow: 0 8px 24px rgba(15, 23, 42, .04); padding: 16px 16px 14px;
  scroll-margin-top: 72px;
}
.su-page > .su-card + .su-card,
.su-page > .su-card + .su-pair,
.su-page > .su-pair + .su-card { margin-top: 22px; }
#su-sessions { margin-bottom: 8px; }
#rentalMsgActions { margin-top: 16px !important; }
.su-card h2 { margin: 0; font-size: 16px; font-weight: 800; color: #0f172a; }
.su-quick-form { margin: 0; }
@media (max-width: 640px) {
  .su-hero-acts { flex-direction: column; align-items: stretch; }
  .su-hero-act-btn, .su-hero-day-btn, .su-hero-acts .su-quick-form { width: 100%; }
}
.su-card-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.su-card .meta { margin: 0; color: #64748b; font-size: 12px; font-weight: 700; }
.su-inv-board { margin-top: 4px; border: 1px solid #e8edf3; border-radius: 16px; overflow: hidden; background: #fff; }
.su-inv-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 10px 12px; border-bottom: 1px solid #eef2f6; background: #f8fafc; }
.su-inv-tools .su-inv-q {
  flex: 1 1 160px; min-width: 0; height: 38px; border-radius: 999px; border: 1px solid #e2e8f0;
  background: #fff; padding: 0 14px; font: inherit; font-weight: 700; outline: none;
}
.su-inv-tools .su-inv-q:focus { border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
.su-inv-count { color: #94a3b8; font-size: 12px; font-weight: 800; white-space: nowrap; }
.su-inv-wrap { overflow: auto; -webkit-overflow-scrolling: touch; max-height: min(60vh, 520px); }
.su-inv-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
.su-inv-table th {
  position: sticky; top: 0; z-index: 1; background: #f8fafc; color: #64748b;
  font-size: 11px; font-weight: 800; letter-spacing: .04em; text-align: start;
  padding: 10px 12px; border-bottom: 1px solid #e8edf3; white-space: nowrap;
}
.su-inv-table td {
  padding: 12px; border-bottom: 1px solid #f1f5f9; color: #0f172a; vertical-align: middle;
}
.su-inv-table tbody tr { cursor: pointer; transition: background .14s ease; }
.su-inv-table tbody tr:hover { background: #f8fafc; }
.su-inv-table tbody tr.is-on { background: #f0fdfa; }
.su-inv-table .num { font-weight: 800; letter-spacing: .01em; }
.su-inv-table .amt { font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }
.su-inv-table .muted { color: #64748b; font-weight: 700; }
.su-inv-acts { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.su-inv-acts button, .su-inv-acts a {
  height: 32px; padding: 0 12px; border-radius: 999px; border: 1px solid #e6ebf2;
  background: #fff; color: #0f172a; font: inherit; font-size: 12px; font-weight: 800;
  text-decoration: none; cursor: pointer; display: inline-flex; align-items: center;
}
.su-inv-acts .is-main { background: #0f172a; color: #fff; border-color: #0f172a; }
.su-inv-acts .is-send { background: #0f766e; color: #fff; border-color: #0f766e; }
.su-inv-empty { text-align: center; color: #94a3b8; font-weight: 700; padding: 28px 12px !important; }
.su-inv-sheet {
  position: fixed; inset: 0; z-index: 100080; background: rgba(15,23,42,.45);
  display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; direction: ltr;
}
.su-inv-sheet[hidden] { display: none !important; }
.su-inv-sheet-card {
  width: min(440px, calc(100vw - 24px)); height: min(88vh, 820px); margin: 0; background: #fff;
  display: flex; flex-direction: column; border-radius: 20px; overflow: hidden;
  box-shadow: 0 28px 70px rgba(15,23,42,.28); direction: rtl;
}
.su-inv-sheet-bar {
  display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-bottom: 1px solid #eef2f6; flex-wrap: wrap;
}
.su-inv-sheet-bar strong { font-size: 15px; }
.su-inv-sheet-bar .grow { flex: 1; min-width: 8px; }
.su-inv-sheet-bar button, .su-inv-sheet-bar a {
  height: 34px; padding: 0 12px; border-radius: 999px; border: 1px solid #e6ebf2;
  background: #fff; color: #0f172a; font: inherit; font-size: 12px; font-weight: 800;
  text-decoration: none; cursor: pointer; display: inline-flex; align-items: center;
}
.su-inv-sheet-bar .is-main { background: #0f172a; color: #fff; border-color: #0f172a; }
.su-inv-sheet-bar .is-send { background: #0f766e; color: #fff; border-color: #0f766e; }
.su-inv-sheet-card iframe { flex: 1; width: 100%; border: 0; background: #f4f7fb; }
.su-inv-x { width: 34px; height: 34px; border: 0; border-radius: 999px; background: #f1f5f9; font-size: 18px; cursor: pointer; }
@media (max-width: 720px) {
  .su-inv-table thead { display: none; }
  .su-inv-table, .su-inv-table tbody, .su-inv-table tr, .su-inv-table td { display: block; width: 100%; }
  .su-inv-table tr {
    padding: 12px; border-bottom: 1px solid #eef2f6; box-sizing: border-box;
  }
  .su-inv-table td {
    padding: 4px 0; border: 0; display: flex; justify-content: space-between; gap: 10px; align-items: center;
  }
  .su-inv-table td::before {
    content: attr(data-label); color: #94a3b8; font-size: 11px; font-weight: 800; flex: 0 0 auto;
  }
  .su-inv-table td.su-inv-acts-cell {
    display: block; padding-top: 10px; margin-top: 6px; border-top: 1px dashed #e2e8f0;
  }
  .su-inv-table td.su-inv-acts-cell::before { display: none; }
  .su-inv-acts { justify-content: stretch; }
  .su-inv-acts button, .su-inv-acts a { flex: 1; justify-content: center; }
  .su-inv-sheet-card { width: 100%; height: min(92vh, 900px); border-radius: 16px; }
}
.su-facts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.su-fact {
  padding: 10px 12px; border-radius: 12px; background: #f8fafc; border: 1px solid #eef2f7;
}
.su-fact span { display: block; font-size: 11px; font-weight: 800; color: #64748b; margin-bottom: 3px; }
.su-fact strong { font-size: 14px; color: #0f172a; font-weight: 800; word-break: break-word; }
.su-grace { margin-top: 12px; padding-top: 12px; border-top: 1px solid #eef2f7; }
.su-grace > label { display: block; font-size: 12px; font-weight: 800; color: #334155; margin-bottom: 6px; }
.su-grace-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.su-grace-row select, .su-grace-row input[type="number"] { height: 40px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0 10px; font: inherit; background: #fff; }
.su-grace-row select { min-width: 180px; flex: 1; }
.su-grace-row input[type="number"] { width: 90px; }
.su-auto-box {
  margin: 14px 0 16px; padding: 12px 14px;
  border-radius: 14px; background: #f8fafc; border: 1px solid #e8eef5;
}
.su-auto-row {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.su-auto-row .su-auto-txt { font-size: 13px; font-weight: 800; color: #0f172a; line-height: 1.35; }
.su-auto-notify {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  margin-top: 12px; padding-top: 12px; border-top: 1px solid #e8eef5;
}
.su-auto-notify[hidden] { display: none !important; }
.su-auto-notify .su-auto-txt { font-size: 12px; font-weight: 800; color: #334155; line-height: 1.35; }
.su-tog {
  position: relative; display: inline-flex; align-items: center; flex: 0 0 auto;
  width: 56px; height: 32px; cursor: pointer; margin: 0;
}
.su-tog input { position: absolute; opacity: 0; width: 1px; height: 1px; pointer-events: none; }
.su-tog-ui {
  position: absolute; inset: 0; border-radius: 999px;
  background: #cbd5e1; box-shadow: inset 0 1px 3px rgba(15, 23, 42, 0.12);
  transition: background .2s ease, box-shadow .2s ease;
}
.su-tog-ui::after {
  content: ''; position: absolute; top: 3px; inset-inline-start: 3px;
  width: 26px; height: 26px; border-radius: 50%; background: #fff;
  box-shadow: 0 4px 10px rgba(15, 23, 42, 0.18);
  transition: inset-inline-start .22s cubic-bezier(.2, .9, .2, 1), inset-inline-end .22s cubic-bezier(.2, .9, .2, 1);
}
.su-tog input:checked + .su-tog-ui {
  background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);
  box-shadow: 0 6px 16px rgba(22, 163, 74, 0.28);
}
.su-tog input:checked + .su-tog-ui::after {
  inset-inline-start: calc(100% - 29px);
}
.su-grace-foot {
  display: flex; align-items: center; gap: 10px; margin-top: 4px;
}
.su-grace-save {
  flex: 1 1 auto; width: 100%; height: 52px; border: 0; border-radius: 14px;
  background: linear-gradient(145deg, #0f172a 0%, #1e293b 100%);
  color: #fff; font: inherit; font-weight: 800; font-size: 16px;
  cursor: pointer; box-shadow: 0 10px 22px rgba(15, 23, 42, 0.18);
}
.su-grace-save:hover { filter: brightness(1.06); }
.su-debt-sum {
  display: flex; align-items: baseline; justify-content: space-between; gap: 10px;
  padding: 12px 14px; border-radius: 14px; background: #fff7ed; border: 1px solid #fed7aa; margin-bottom: 10px;
}
.su-debt-sum.is-clear { background: #f0fdf4; border-color: #bbf7d0; }
.su-debt-sum b { font-size: 22px; font-weight: 800; color: #9a3412; letter-spacing: -.02em; }
.su-debt-sum.is-clear b { color: #166534; }
.su-debt-list { list-style: none; margin: 0; padding: 0; }
.su-debt-list li {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; font-weight: 700;
}
.su-debt-list li:last-child { border-bottom: 0; }
.su-sec { margin: 0 0 16px; }
.su-sec h3 { margin: 0 0 10px; font-size: 13px; font-weight: 800; color: #475569; }
.sas-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
.sas-grid label { display: block; font-size: 12px; color: #334155; font-weight: 800; }
.sas-grid input, .sas-grid select, .su-card input, .su-card select {
  width: 100%; margin-top: 4px; height: 40px; padding: 0 12px;
  border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; font: inherit;
}
.req { color: #dd4b39; }
.su-switch-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.sas-switch { position: relative; width: 46px; height: 24px; display: inline-block; }
.sas-switch input { opacity: 0; width: 0; height: 0; }
.sas-switch span { position: absolute; inset: 0; background: #cbd5e1; border-radius: 24px; cursor: pointer; transition: .2s; }
.sas-switch span:before {
  content: ""; position: absolute; height: 18px; width: 18px; left: 3px; top: 3px;
  background: #fff; border-radius: 50%; transition: .2s;
}
.sas-switch input:checked + span { background: #16a34a; }
.sas-switch input:checked + span:before { transform: translateX(22px); }
.sas-form-actions { margin-top: 14px; display: flex; gap: 8px; flex-wrap: wrap; }
.sas-more-btn { background: none; border: 0; color: #1d4ed8; font: inherit; font-weight: 800; cursor: pointer; padding: 0; margin: 0 0 10px; }
.sas-more-box.hidden { display: none; }
#sasSessionsNote { font-size: 13px; color: #475569; font-weight: 700; margin: 0 0 8px; }
#sasSessionsTable { width: 100%; border-collapse: collapse; }
#sasSessionsTable th, #sasSessionsTable td { padding: 8px 10px; border-bottom: 1px solid #eef2f7; text-align: start; font-size: 13px; white-space: nowrap; }
#sasSessionsTable th { background: #f8fafc; font-weight: 800; color: #334155; }
#sasSessionsPanel .table-wrap { overflow-x: auto; }
.su-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 22px; }
@media (max-width: 860px) {
  .su-split, .su-pair, .sas-grid, .su-facts, .su-stats { grid-template-columns: 1fr 1fr; }
  .su-page { width: 100%; max-width: 100%; margin-inline: 0; box-sizing: border-box; }
  .su-hero {
    flex-direction: column; align-items: center; text-align: center;
    width: 100%; max-width: 100%; box-sizing: border-box; margin-inline: 0;
  }
  .su-hero-main { width: 100%; display: flex; flex-direction: column; align-items: center; }
  .su-hero-who { flex-direction: column; align-items: center; justify-content: center; text-align: center; width: 100%; }
  .su-hero .su-user { text-align: center; }
  .su-pills { justify-content: center; }
  .su-hero-acts { justify-content: center; width: 100%; }
  .su-stats { width: 100%; text-align: center; }
  .su-hero-side { flex-direction: row; width: 100%; justify-content: center; }
  .su-nav { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .su-nav a, .su-nav button { width: 100%; }
  .su-nav a[href="#su-slips"] { grid-column: 1 / -1; }
}
@media (max-width: 520px) {
  .su-page { margin-bottom: 18px; padding-inline: 0; }
  .su-hero { padding: 14px; border-radius: 16px; }
  .su-hero h1 { font-size: 18px; }
  .su-avatar { width: 52px; height: 52px; font-size: 22px; }
  .su-stats { grid-template-columns: 1fr 1fr; gap: 8px; }
  .su-stat strong { font-size: 14px; }
}
.su-sess-limit {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 800; color: #475569;
}
.su-sess-limit select {
  height: 32px; border-radius: 999px; border: 1px solid #e2e8f0;
  padding: 0 10px; font: inherit; font-weight: 800; background: #fff; color: #0f172a;
}
@media print {
  .sidebar, .sidebar-backdrop, .bg-bubbles, .main-top, .id-ask,
  .su-hero-side, .su-hero-acts, .su-nav, .sas-toast, #appRing { display: none !important; }
  .app { display: block !important; min-height: 0 !important; background: #fff !important; }
  .main, .su-page, .su-hero, .su-card, .su-split, .su-facts, .su-fact, .su-stat, .su-pill {
    animation: none !important;
    opacity: 1 !important;
    transform: none !important;
    filter: none !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  .main, .su-page { display: block !important; width: 100% !important; max-width: none !important; margin: 0 !important; }
  .su-hero { box-shadow: none; }
  #su-sessions, #sasSessionsPanel { display: block !important; opacity: 1 !important; }
  #sasSessionsTable { display: table !important; width: 100% !important; }
  #sasSessionsTable tr { display: table-row !important; }
  #sasSessionsTable th, #sasSessionsTable td { display: table-cell !important; }
  #su-edit[hidden], #rental[hidden] { display: none !important; }
  @page { margin: 8mm; }
}
</style>

<div class="su-page">
<div class="su-hero">
  <div class="su-hero-main">
    <?php if (!$isNew && $username !== ''): ?>
    <div class="su-hero-acts">
        <?php
        $suActBlocked = function_exists('app_maintenance_blocks') && app_maintenance_blocks('activate', $config);
        $suDayBlocked = function_exists('app_maintenance_blocks') && app_maintenance_blocks('give_test', $config);
        $suActQs = array(
            'q' => $username,
            'act' => '1',
            'pid' => !empty($cache['profile_id']) ? (string) (int) $cache['profile_id'] : '0',
            'pn' => !empty($cache['profile_name']) ? (string) $cache['profile_name'] : '',
            'sid' => !empty($cache['sas_user_id']) ? (string) (int) $cache['sas_user_id'] : '0',
            'prov' => !empty($cache['provider']) ? (string) $cache['provider'] : 'sas',
            'co' => !empty($cache['company_id']) ? (string) (int) $cache['company_id'] : '0',
            'fn' => !empty($cache['firstname']) ? (string) $cache['firstname'] : (!empty($cache['display_name']) ? (string) $cache['display_name'] : ''),
            'nm' => !empty($cache['display_name']) ? (string) $cache['display_name'] : '',
        );
        $suActHref = 'sas.php?' . http_build_query($suActQs);
        $suPauseIco = '<svg class="su-hero-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>';
        $suPlayIco = '<svg class="su-hero-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';
        ?>
        <a class="su-hero-act-btn<?php echo $suActBlocked ? ' is-off' : ''; ?>"
           href="<?php echo e($suActHref); ?>"
           <?php echo $suActBlocked ? ' aria-disabled="true" onclick="return false;"' : ''; ?>><?php
            echo e($isEn ? 'Activate' : 'تفعيل');
        ?></a>
        <?php if (empty($isEarthlinkUser)):
            $suExtOpts = function_exists('sas_extensions_for_username')
                ? sas_extensions_for_username($pdo, $config, $username, $isEn)
                : array();
            foreach ($suExtOpts as $suExt):
                $suExtId = isset($suExt['id']) ? (int) $suExt['id'] : 0;
                $suExtDays = isset($suExt['days']) ? (int) $suExt['days'] : 0;
                $suExtLab = isset($suExt['label']) ? (string) $suExt['label'] : ($isEn ? 'Extend' : 'تمديد');
                $suExtConfirm = ($suExtDays > 1)
                    ? ($isEn ? ('Extend ' . $suExtDays . ' days for this subscriber?') : ('تمديد ' . $suExtDays . ' يوم لهذا المشترك؟'))
                    : ($isEn ? 'Add +1 day to this subscriber?' : 'إضافة +1 يوم لهذا المشترك؟');
                ?>
        <form method="post" class="su-quick-form" data-no-wait="1"
              onsubmit="return confirm(<?php echo json_encode($suExtConfirm); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="su_give_day">
            <?php if ($suExtId > 0): ?>
            <input type="hidden" name="profile_id" value="<?php echo (int) $suExtId; ?>">
            <?php endif; ?>
            <button class="su-hero-day-btn" type="submit"<?php echo $suDayBlocked ? ' disabled' : ''; ?>><?php
                echo e($suExtLab);
            ?></button>
        </form>
            <?php endforeach; ?>
        <?php endif; ?>
        <button type="button"
                class="su-hero-day-btn su-hero-pause-btn<?php echo $enabled ? '' : ' is-off'; ?>"
                id="suPauseBtn"
                data-on="<?php echo $enabled ? '1' : '0'; ?>">
            <span class="su-pause-ico" aria-hidden="true"><?php echo $enabled ? $suPauseIco : $suPlayIco; ?></span>
            <span class="su-pause-txt"><?php
                echo e($enabled
                    ? ($isEn ? 'Pause user' : 'إيقاف مؤقت')
                    : ($isEn ? 'Enable user' : 'تشغيل اليوزر'));
            ?></span>
        </button>
    </div>
    <?php endif; ?>
    <div class="su-hero-who">
        <?php if (!$isNew): ?>
            <?php
            $avatarLetter = '•';
            $avatarSrc = trim($dispName);
            if ($avatarSrc !== '' && function_exists('mb_substr')) {
                $avatarLetter = mb_substr($avatarSrc, 0, 1, 'UTF-8');
            } elseif ($avatarSrc !== '') {
                $avatarLetter = substr($avatarSrc, 0, 1);
            }
            ?>
            <span class="su-avatar" aria-hidden="true"><?php echo e($avatarLetter); ?></span>
        <?php endif; ?>
        <div>
        <h1><?php echo e($isNew ? ($isEn ? 'New subscriber' : 'مشترك جديد') : $dispName); ?></h1>
        <?php if (!$isNew && $username !== ''): ?>
            <p class="su-user"><?php echo e($username); ?></p>
        <?php endif; ?>
        <?php if (!$isNew): ?>
        <div class="su-pills">
            <span class="su-pill<?php
                $subExpiredPill = ($daysLeft !== '' && $daysLeft !== null && (int) $daysLeft < 0);
                echo !$enabled || $subExpiredPill ? ' is-off' : ' is-on';
            ?>"><?php
                if (!$enabled) {
                    echo e($isEn ? 'Disabled' : 'موقف');
                } elseif ($subExpiredPill) {
                    echo e($isEn ? 'Expired' : 'منتهي');
                } else {
                    echo e($isEn ? 'Active' : 'فعّال');
                }
            ?></span>
            <span class="su-pill<?php echo $isOnlineNow ? ' is-on' : ' is-down'; ?>"><?php echo e($isOnlineNow ? ($isEn ? 'Online' : 'متصل') : ($isEn ? 'Offline' : 'غير متصل')); ?></span>
            <?php if ($profileLabel !== ''): ?><span class="su-pill"><?php echo e($profileLabel); ?></span><?php endif; ?>
            <?php if ($hasRent): ?>
            <span class="su-pill is-rent" title="<?php echo e($currentRentDev && !empty($currentRentDev['name']) ? $currentRentDev['name'] : ($isEn ? 'Rental' : 'إيجار')); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5z"/></svg>
                <?php echo e($isEn ? 'Rental' : 'إيجار'); ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
    </div>
    <?php if (!$isNew): ?>
    <div class="su-stats">
        <div class="su-stat">
            <span><?php echo e($isEn ? 'Days left' : 'الأيام المتبقية'); ?></span>
            <strong><?php echo e($daysShow); ?></strong>
        </div>
        <div class="su-stat">
            <span><?php echo e($isEn ? 'Debt' : 'الديون'); ?></span>
            <strong><?php echo e(money_format_iqd($debtTotal, $config['currency'])); ?></strong>
        </div>
        <div class="su-stat">
            <span><?php echo e($isEn ? 'Received' : 'المستلم'); ?></span>
            <strong><?php echo e(money_format_iqd($paidTotal, $config['currency'])); ?></strong>
        </div>
        <div class="su-stat" title="<?php echo e($msgHint); ?>">
            <span><?php echo e($isEn ? 'Message' : 'الرسالة'); ?></span>
            <strong><?php echo e($msgState); ?></strong>
        </div>
    </div>
    <?php endif; ?>
  </div>
    <div class="su-hero-side">
        <?php if (!$isNew): ?>
        <button type="button" class="su-back" id="idPrintOpen"><?php echo e($isEn ? 'Print card' : 'طباعة الهوية'); ?></button>
        <?php endif; ?>
        <a class="su-back" href="sas.php"><?php echo e($isEn ? 'Back' : 'رجوع'); ?></a>
    </div>
</div>
<?php if (!$isNew): ?>
<nav class="su-nav">
    <a href="#su-info"><?php echo e($isEn ? 'Subscriber' : 'بيانات المشترك'); ?></a>
    <a href="#su-slips"><?php echo e($isEn ? 'Invoices' : 'الفواتير'); ?></a>
    <button type="button" data-su-toggle="su-edit"><?php echo e($isEn ? 'Edit' : 'تعديل البيانات'); ?></button>
    <button type="button" id="sasSessionsBtn"><?php echo e($isEn ? 'Sessions' : 'الجلسات'); ?></button>
    <button type="button" data-su-toggle="rental"><?php echo e($isEn ? 'Rental' : 'الإيجار'); ?></button>
</nav>
<div class="su-split">
    <section class="su-card" id="su-info">
        <div class="su-card-h">
            <h2><?php echo e($isEn ? 'Subscriber' : 'بيانات المشترك'); ?></h2>
        </div>
        <div class="su-facts">
            <div class="su-fact"><span><?php echo e($isEn ? 'Name' : 'الاسم'); ?></span><strong><?php echo e($dispName !== '' ? $dispName : '—'); ?></strong></div>
            <div class="su-fact"><span><?php echo e($isEn ? 'Username' : 'اليوزرنيم'); ?></span><strong dir="ltr"><?php echo e($username !== '' ? $username : '—'); ?></strong></div>
            <div class="su-fact"><span><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?></span><strong dir="ltr"><?php echo e($phoneShow !== '' ? $phoneShow : '—'); ?></strong></div>
            <div class="su-fact"><span><?php echo e($isEn ? 'Profile' : 'الباقة'); ?></span><strong><?php echo e($profileLabel !== '' ? $profileLabel : '—'); ?></strong></div>
            <div class="su-fact"><span><?php echo e($isEn ? 'Belongs to' : 'تابع الى'); ?></span><strong><?php echo e($parentLabel !== '' ? $parentLabel : '—'); ?></strong></div>
            <div class="su-fact"><span><?php echo e($isEn ? 'Expires' : 'الانتهاء'); ?></span><strong dir="ltr"><?php echo e($expireShow !== '' ? $expireShow : '—'); ?><?php if ($daysLeft !== ''): ?> · <?php echo e($daysLeft . ($isEn ? ' d' : ' يوم')); ?><?php endif; ?></strong></div>
            <div class="su-fact"><span>IP</span><strong dir="ltr"><?php echo e($ipShow !== '' ? $ipShow : '—'); ?></strong></div>
            <div class="su-fact"><span>MAC</span><strong dir="ltr"><?php
                echo e($macShow !== '' ? $macShow : '—');
                $apDev = null;
                if ($macShow !== '' && function_exists('devices_system_is_on') && devices_system_is_on()
                    && function_exists('devices_find_by_subscriber_mac') && isset($pdo)
                    && function_exists('user_can') && user_can('devices')
                ) {
                    $apDev = devices_find_by_subscriber_mac($pdo, $macShow);
                }
                if ($apDev):
                    ?> · <a href="devices.php?id=<?php echo (int) $apDev['id']; ?>"><?php echo e($isEn ? 'AP' : 'المرسلة'); ?>: <?php echo e($apDev['name']); ?></a><?php
                endif;
            ?></strong></div>
            <?php if ($localAddress !== ''): ?>
            <div class="su-fact"><span><?php echo e($isEn ? 'Address' : 'العنوان'); ?></span><strong><?php echo e($localAddress); ?></strong></div>
            <?php endif; ?>
            <?php if ($localNotes !== ''): ?>
            <div class="su-fact"><span><?php echo e($isEn ? 'Notes' : 'ملاحظات'); ?></span><strong><?php echo e($localNotes); ?></strong></div>
            <?php endif; ?>
            <div class="su-fact"><span><?php echo e($isEn ? 'Grace days' : 'أيام السماح'); ?></span><strong><?php echo e($graceShow); ?></strong></div>
        </div>
        <form method="post" class="su-grace" id="suGraceForm">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="update_local_details">
            <input type="hidden" name="address" value="<?php echo e($localAddress); ?>">
            <input type="hidden" name="notes" value="<?php echo e($localNotes); ?>">
            <label for="graceModeSel"><?php echo e($isEn ? 'Grace days after activation' : 'أيام السماح بعد التفعيل'); ?></label>
            <div class="su-grace-row">
                <select name="grace_mode" id="graceModeSel">
                    <option value="system" <?php echo !$graceIsCustom ? 'selected' : ''; ?>>
                        <?php echo e($isEn ? ('Follow system (' . $sysGraceNum . ' days)') : ('حسب النظام (' . $sysGraceNum . ' يوم)')); ?>
                    </option>
                    <option value="custom" <?php echo $graceIsCustom ? 'selected' : ''; ?>>
                        <?php echo e($isEn ? 'Custom number' : 'رقم مخصص'); ?>
                    </option>
                </select>
                <input type="number" name="grace_days" id="graceDaysInp" min="0" max="90" step="1" value="<?php echo (int) $localGraceNum; ?>">
            </div>
            <div class="su-auto-box">
                <div class="su-auto-row">
                    <span class="su-auto-txt"><?php echo e($isEn ? 'Auto-activate when subscription ends' : 'تفعيل تلقائي عند انتهاء الاشتراك'); ?></span>
                    <label class="su-tog" title="<?php echo e($isEn ? 'On / Off' : 'تشغيل / إيقاف'); ?>">
                        <input type="checkbox" name="auto_activate_on_expire" id="suAutoActivate" value="1"<?php echo !empty($autoActivateOn) ? ' checked' : ''; ?>>
                        <span class="su-tog-ui" aria-hidden="true"></span>
                    </label>
                </div>
                <div class="su-auto-notify" id="suAutoNotifyWrap"<?php echo empty($autoActivateOn) ? ' hidden' : ''; ?>>
                    <span class="su-auto-txt"><?php echo e($isEn ? 'Notify subscriber about this feature' : 'إشعار المشترك بهذه الخاصية'); ?></span>
                    <label class="su-tog" title="<?php echo e($isEn ? 'Notify' : 'إشعار'); ?>">
                        <input type="checkbox" name="auto_activate_notify" id="suAutoNotify" value="1" checked>
                        <span class="su-tog-ui" aria-hidden="true"></span>
                    </label>
                </div>
            </div>
            <div class="su-grace-foot">
                <button class="su-grace-save" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button>
            </div>
        </form>
    </section>
    <section class="su-card" id="su-debt">
        <div class="su-card-h">
            <h2><?php echo e($isEn ? 'Debts' : 'موجز الديون'); ?></h2>
            <?php if ($localId > 0 && $debtTotal > 0): ?>
                <a class="btn sm" href="debts.php?status=unpaid&subscriber_id=<?php echo (int) $localId; ?>"><?php echo e($isEn ? 'Pay' : 'تسديد'); ?></a>
            <?php endif; ?>
        </div>
        <div class="su-debt-sum<?php echo $debtTotal > 0 ? '' : ' is-clear'; ?>">
            <span><?php echo e($debtTotal > 0 ? ($isEn ? 'Unpaid' : 'غير مسدد') : ($isEn ? 'No debt' : 'ماكو ديون')); ?></span>
            <b><?php echo e(money_format_iqd($debtTotal, $config['currency'])); ?></b>
        </div>
        <?php if ($debtRows): ?>
        <ul class="su-debt-list">
            <?php foreach ($debtRows as $drow): ?>
                <li>
                    <span><?php echo e(function_exists('invoice_debt_label') ? invoice_debt_label($drow) : (isset($drow['month_label']) ? $drow['month_label'] : '')); ?></span>
                    <strong><?php echo e(money_format_iqd($drow['amount'], $config['currency'])); ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php elseif ($localId <= 0): ?>
            <p class="meta"><?php echo e($isEn ? 'This line is not linked to a local subscriber yet.' : 'هذا الخط مو مربوط بمشترك محلي بعد.'); ?></p>
        <?php endif; ?>
    </section>
    <?php
    $suSlips = array();
    if (!$isNew && $username !== '') {
        require_once __DIR__ . '/../includes/invoice_studio.php';
        $suSlips = invoice_slips_for_user(
            function_exists('current_tenant_id') ? (int) current_tenant_id() : 1,
            $username,
            isset($phoneShow) ? $phoneShow : ''
        );
    }
    $suSlipN = is_array($suSlips) ? count($suSlips) : 0;
    ?>
    <section class="su-card" id="su-slips">
        <div class="su-card-h">
            <h2><?php echo e($isEn ? 'Invoice history' : 'سجل الفواتير'); ?></h2>
            <button class="btn sm" type="button" id="suSlipNew"><?php echo e($isEn ? 'New invoice' : 'فاتورة جديدة'); ?></button>
        </div>
        <div class="su-inv-board">
            <div class="su-inv-tools">
                <input type="search" class="su-inv-q" id="suInvQ" placeholder="<?php echo e($isEn ? 'Search number, amount, date…' : 'بحث بالرقم أو المبلغ أو التاريخ…'); ?>" autocomplete="off">
                <span class="su-inv-count" id="suInvCount"><?php echo (int) $suSlipN; ?></span>
            </div>
            <div class="su-inv-wrap">
                <table class="su-inv-table" id="suInvTable">
                    <thead>
                        <tr>
                            <th><?php echo e($isEn ? 'Number' : 'الرقم'); ?></th>
                            <th><?php echo e($isEn ? 'Amount' : 'المبلغ'); ?></th>
                            <th><?php echo e($isEn ? 'Date' : 'التاريخ'); ?></th>
                            <th><?php echo e($isEn ? 'By' : 'بواسطة'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="suInvBody">
                    <?php if (!$suSlipN): ?>
                        <tr class="su-inv-empty-row"><td class="su-inv-empty" colspan="5"><?php echo e($isEn ? 'No invoices yet' : 'ماكو فواتير بعد'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($suSlips as $srow):
                        if (!is_array($srow)) {
                            continue;
                        }
                        $tok = isset($srow['token']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $srow['token'])) : '';
                        if ($tok === '') {
                            continue;
                        }
                        $no = isset($srow['number']) ? (string) $srow['number'] : '';
                        $amt = isset($srow['amount']) ? trim((string) $srow['amount']) : '';
                        $when = isset($srow['created_at']) ? (string) $srow['created_at'] : '';
                        $who = isset($srow['issuer_name']) ? trim((string) $srow['issuer_name']) : '';
                        $hay = strtolower($no . ' ' . $amt . ' ' . $when . ' ' . $who);
                        ?>
                        <tr data-tok="<?php echo e($tok); ?>" data-no="<?php echo e($no !== '' ? $no : $tok); ?>" data-q="<?php echo e($hay); ?>">
                            <td class="num ltr" data-label="<?php echo e($isEn ? 'Number' : 'الرقم'); ?>"><?php echo e($no !== '' ? $no : $tok); ?></td>
                            <td class="amt" data-label="<?php echo e($isEn ? 'Amount' : 'المبلغ'); ?>"><?php echo e($amt !== '' ? $amt : '—'); ?></td>
                            <td class="muted ltr" data-label="<?php echo e($isEn ? 'Date' : 'التاريخ'); ?>"><?php echo e($when !== '' ? $when : '—'); ?></td>
                            <td class="muted" data-label="<?php echo e($isEn ? 'By' : 'بواسطة'); ?>"><?php echo e($who !== '' ? $who : '—'); ?></td>
                            <td class="su-inv-acts-cell">
                                <div class="su-inv-acts">
                                    <button type="button" data-act="view"><?php echo e($isEn ? 'View' : 'عرض'); ?></button>
                                    <button type="button" data-act="print"><?php echo e($isEn ? 'Print' : 'طباعة'); ?></button>
                                    <button type="button" class="is-send" data-act="send"><?php echo e($isEn ? 'Send' : 'إرسال'); ?></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <div class="su-inv-sheet" id="suInvSheet" hidden>
        <div class="su-inv-sheet-card" role="dialog" aria-modal="true">
            <div class="su-inv-sheet-bar">
                <strong id="suInvSheetTitle"><?php echo e($isEn ? 'Invoice' : 'فاتورة'); ?></strong>
                <span class="grow"></span>
                <a id="suInvOpen" href="slip.php" target="_blank"><?php echo e($isEn ? 'Open' : 'فتح'); ?></a>
                <button type="button" id="suInvPrint"><?php echo e($isEn ? 'Print' : 'طباعة'); ?></button>
                <button type="button" class="is-send" id="suInvSend"><?php echo e($isEn ? 'Send' : 'إرسال'); ?></button>
                <button type="button" class="su-inv-x" id="suInvClose" aria-label="<?php echo e($isEn ? 'Close' : 'إغلاق'); ?>">×</button>
            </div>
            <iframe id="suInvFrame" title="<?php echo e($isEn ? 'Invoice preview' : 'استعراض الفاتورة'); ?>"></iframe>
        </div>
    </div>
    <script>
    (function () {
      var csrf = <?php echo json_encode(csrf_token()); ?>;
      var username = <?php echo json_encode($username); ?>;
      var dispName = <?php echo json_encode($dispName); ?>;
      var phoneShow = <?php echo json_encode(isset($phoneShow) ? $phoneShow : ''); ?>;
      var profileLabel = <?php echo json_encode($profileLabel); ?>;
      var expireShow = <?php echo json_encode(isset($expireShow) ? $expireShow : ''); ?>;
      var body = document.getElementById('suInvBody');
      var frame = document.getElementById('suInvFrame');
      var sheet = document.getElementById('suInvSheet');
      var q = document.getElementById('suInvQ');
      var count = document.getElementById('suInvCount');
      var openA = document.getElementById('suInvOpen');
      var printB = document.getElementById('suInvPrint');
      var sendB = document.getElementById('suInvSend');
      var title = document.getElementById('suInvSheetTitle');
      var current = '';
      function toast(msg, ok) {
        var t = document.createElement('div');
        t.className = 'sas-toast ' + (ok ? 'sas-toast-success' : 'sas-toast-error') + ' sas-toast-mid';
        t.setAttribute('role', 'status');
        t.innerHTML = '<span class="sas-toast-text"></span>';
        t.querySelector('.sas-toast-text').textContent = msg || '';
        document.body.appendChild(t);
        if (window.placeCornerToast) window.placeCornerToast(t);
        setTimeout(function () {
          t.classList.add('is-out');
          setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 320);
        }, 2800);
      }
      function rows() { return body ? body.querySelectorAll('tr[data-tok]') : []; }
      function openSheet(tok, no) {
        if (!sheet || !frame) return;
        if (sheet.parentNode !== document.body) document.body.appendChild(sheet);
        current = tok || '';
        var list = rows();
        for (var i = 0; i < list.length; i++) {
          list[i].classList.toggle('is-on', list[i].getAttribute('data-tok') === current);
        }
        if (!current) return;
        frame.src = 'slip.php?k=' + encodeURIComponent(current);
        if (openA) openA.href = 'slip.php?k=' + encodeURIComponent(current);
        if (title) title.textContent = no || current;
        sheet.hidden = false;
      }
      function closeSheet() { if (sheet) sheet.hidden = true; }
      function sendTok(tok, btn) {
        if (!tok) return;
        if (btn) btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'send');
        fd.append('token', tok);
        fd.append('phone', phoneShow || '');
        fetch('slip.php', { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (btn) btn.disabled = false;
            toast((j && j.message) || (j && j.ok ? 'تم الإرسال' : 'فشل الإرسال'), !!(j && j.ok));
          })
          .catch(function () {
            if (btn) btn.disabled = false;
            toast(<?php echo json_encode($isEn ? 'Send failed' : 'فشل الإرسال'); ?>, false);
          });
      }
      if (body) {
        body.addEventListener('click', function (ev) {
          var btn = ev.target && ev.target.closest ? ev.target.closest('[data-act]') : null;
          var row = ev.target && ev.target.closest ? ev.target.closest('tr[data-tok]') : null;
          if (!row) return;
          var tok = row.getAttribute('data-tok') || '';
          var no = row.getAttribute('data-no') || tok;
          if (btn) {
            ev.stopPropagation();
            var act = btn.getAttribute('data-act');
            if (act === 'print') {
              var fdP = new FormData();
              fdP.append('csrf', csrf);
              fdP.append('action', 'print');
              fdP.append('token', tok);
              fetch('slip.php', { method: 'POST', credentials: 'same-origin', body: fdP })
                .then(function (r) { return r.json(); })
                .then(function (pj) {
                  if (pj && pj.printed) {
                    toast(pj.message || <?php echo json_encode($isEn ? 'Printed' : 'انطبعت'); ?>, true);
                    return;
                  }
                  openSheet(tok, no);
                  setTimeout(function () {
                    try { if (frame && frame.contentWindow) frame.contentWindow.print(); } catch (e) {}
                  }, 450);
                })
                .catch(function () {
                  openSheet(tok, no);
                  setTimeout(function () {
                    try { if (frame && frame.contentWindow) frame.contentWindow.print(); } catch (e) {}
                  }, 450);
                });
              return;
            }
            if (act === 'send') {
              sendTok(tok, btn);
              return;
            }
          }
          openSheet(tok, no);
        });
      }
      if (q && body) {
        q.addEventListener('input', function () {
          var needle = (q.value || '').replace(/^\s+|\s+$/g, '').toLowerCase();
          var list = rows();
          var n = 0;
          for (var i = 0; i < list.length; i++) {
            var hay = (list[i].getAttribute('data-q') || '').toLowerCase();
            var hide = needle && hay.indexOf(needle) < 0;
            list[i].hidden = hide;
            if (!hide) n++;
          }
          if (count) count.textContent = String(n);
        });
      }
      if (printB) {
        printB.addEventListener('click', function () {
          if (!current) return;
          try { if (frame.contentWindow) frame.contentWindow.print(); } catch (e) {}
        });
      }
      if (sendB) {
        sendB.addEventListener('click', function () { sendTok(current, sendB); });
      }
      var closeB = document.getElementById('suInvClose');
      if (closeB) closeB.addEventListener('click', closeSheet);
      if (sheet) sheet.addEventListener('click', function (ev) { if (ev.target === sheet) closeSheet(); });
      document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeSheet(); });

      var btn = document.getElementById('suSlipNew');
      if (!btn) return;
      btn.addEventListener('click', function () {
        btn.disabled = true;
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('action', 'issue');
        fd.append('username', username);
        fd.append('name', dispName);
        fd.append('phone', phoneShow || '');
        fd.append('package', profileLabel || '');
        fd.append('end', expireShow || '');
        fd.append('title', 'وصل');
        fetch('slip.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
          btn.disabled = false;
          if (!j || !j.ok || !j.token) {
            toast((j && j.message) || <?php echo json_encode($isEn ? 'Failed' : 'تعذر الإنشاء'); ?>, false);
            return;
          }
          openSheet(j.token, j.token);
          setTimeout(function () {
            try { if (frame.contentWindow) frame.contentWindow.print(); } catch (e) {}
          }, 600);
          setTimeout(function () { window.location.reload(); }, 1200);
        }).catch(function () {
          btn.disabled = false;
          toast(<?php echo json_encode($isEn ? 'Failed' : 'تعذر الإنشاء'); ?>, false);
        });
      });
    })();
    </script>
</div>
<?php endif; ?>
<section class="su-card su-panel" id="su-edit"<?php echo $isNew ? '' : ' hidden'; ?>>
    <div class="su-card-h">
        <h2><?php echo e($isNew ? ($isEn ? 'New' : 'مشترك جديد') : ($isEn ? 'Edit' : 'تعديل البيانات')); ?></h2>
    </div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo e($isNew ? 'sas_create' : 'sas_update_info'); ?>">

        <div class="su-sec">
            <div class="su-switch-row">
                <h3 style="margin:0"><?php echo e($isEn ? 'Account' : 'الحساب'); ?></h3>
                <label class="sas-switch" title="<?php echo e($isEn ? 'Enabled' : 'مفعّل'); ?>">
                    <input type="checkbox" name="enabled" value="1"<?php echo $enabled ? ' checked' : ''; ?>>
                    <span></span>
                </label>
            </div>
            <div class="sas-grid">
                <?php if (!empty($needCompanyPick)): ?>
                <div style="grid-column:1/-1">
                    <label><?php echo e($isEn ? 'Company' : 'الشركة'); ?> <span class="req">*</span></label>
                    <select name="account_id" id="sasCompanySelect" required>
                        <option value=""><?php echo e($isEn ? 'Select company…' : 'اختار الشركة…'); ?></option>
                        <?php foreach ($companyPickOptions as $coOpt): ?>
                            <option value="<?php echo (int) $coOpt['account_id']; ?>"
                                data-company="<?php echo (int) $coOpt['company_id']; ?>"
                                data-provider="<?php echo e($coOpt['provider']); ?>">
                                <?php echo e($coOpt['name'] . ($coOpt['provider'] === 'earthlink' ? ' · Earthlink' : ' · SAS')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="company_id" id="sasCompanyIdHidden" value="">
                </div>
                <?php elseif ($isNew && count($companyPickOptions) === 1): ?>
                    <input type="hidden" name="account_id" value="<?php echo (int) $companyPickOptions[0]['account_id']; ?>">
                    <input type="hidden" name="company_id" value="<?php echo (int) $companyPickOptions[0]['company_id']; ?>">
                <?php endif; ?>
                <div>
                    <label><?php echo e($isEn ? 'Username' : 'اليوزرنيم'); ?> <span class="req">*</span></label>
                    <input name="username" value="<?php echo e($username); ?>" required>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?></label>
                    <input name="phone" type="tel" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" value="<?php echo e($phoneShow); ?>">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Password' : 'كلمة السر'); ?><?php echo $isNew ? ' <span class="req">*</span>' : ''; ?></label>
                    <input type="password" name="password" id="sasPass" autocomplete="new-password"<?php echo $isNew ? ' required' : ''; ?>>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Confirm Password' : 'تأكيد كلمة السر'); ?><?php echo $isNew ? ' <span class="req">*</span>' : ''; ?></label>
                    <input type="password" name="confirm_password" id="sasPass2" autocomplete="new-password"<?php echo $isNew ? ' required' : ''; ?>>
                </div>
                <div id="sasProfWrap"<?php echo !empty($needCompanyPick) ? ' hidden' : ''; ?>>
                    <label><?php echo e($isEn ? 'Service Profile' : 'البروفايل'); ?> <span class="req">*</span></label>
                    <select name="profile_id" id="sasProfileSelect"<?php echo !empty($needCompanyPick) ? '' : ' required'; ?>>
                        <option value=""><?php echo e(!empty($needCompanyPick)
                            ? ($isEn ? 'Pick a company first' : 'اختار الشركة أولاً')
                            : (($isEarthlinkUser) ? ($isEn ? 'Select…' : 'اختر…') : ($isEn ? 'Loading…' : 'جاري التحميل…'))); ?></option>
                        <?php if (empty($needCompanyPick)): ?>
                        <?php foreach ($profiles as $pr): ?>
                            <?php $prVal = isset($pr['id']) ? (string) $pr['id'] : ''; ?>
                            <option value="<?php echo e($prVal); ?>"<?php echo ($prVal !== '' && $prVal === (string) $profileUiId) ? ' selected' : ''; ?>><?php echo e($pr['name']); ?></option>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div id="sasParentWrap"<?php echo !empty($needCompanyPick) ? ' hidden' : ''; ?>>
                    <label><?php echo e($isEn ? 'Belongs to' : 'تابع الى'); ?><?php echo $isEarthlinkUser ? '' : ' <span class="req">*</span>'; ?></label>
                    <select name="parent_id" id="sasParentSelect"<?php echo ($isEarthlinkUser || !empty($needCompanyPick)) ? '' : ' required'; ?>>
                        <option value=""><?php echo e(!empty($needCompanyPick)
                            ? ($isEn ? 'Pick a company first' : 'اختار الشركة أولاً')
                            : ($isEn ? 'Select…' : 'اختر…')); ?></option>
                        <?php if (empty($needCompanyPick)): ?>
                        <?php foreach ($managers as $mn): ?>
                            <?php $mnVal = isset($mn['id']) ? (string) $mn['id'] : ''; ?>
                            <option value="<?php echo e($mnVal); ?>"<?php echo ($mnVal !== '' && $mnVal === (string) $parentUiId) ? ' selected' : ''; ?>><?php echo e($mn['name']); ?></option>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo e($isEarthlinkUser
                        ? ($isEn ? 'Arabic / display name' : 'الاسم العربي')
                        : ($isEn ? 'First Name' : 'الاسم الأول')); ?></label>
                    <input name="firstname" value="<?php echo e($fn); ?>">
                </div>
            </div>
        </div>

        <button type="button" class="sas-more-btn" id="sasMoreBtn"><?php echo e($isEn ? '+ More fields' : '+ حقول إضافية'); ?></button>
        <div class="sas-sec sas-more-box hidden" id="sasMoreBox">
            <h3><?php echo e($isEn ? 'More' : 'المزيد'); ?></h3>
            <div class="sas-grid">
                <div>
                    <label><?php echo e($isEn ? 'Last Name' : 'الاسم الأخير'); ?></label>
                    <input name="lastname" value="<?php echo e($ln); ?>">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'City' : 'المدينة'); ?></label>
                    <input name="city" value="<?php echo e(isset($cache['city']) ? $cache['city'] : ''); ?>">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Company' : 'الشركة'); ?></label>
                    <input name="company" value="<?php echo e(isset($cache['company']) ? $cache['company'] : ''); ?>">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Email' : 'الإيميل'); ?></label>
                    <input name="email" value="<?php echo e(isset($cache['email']) ? $cache['email'] : ''); ?>">
                </div>
            </div>
        </div>

        <div class="sas-form-actions">
            <button class="btn" type="submit"><?php echo e($isNew ? ($isEn ? 'Create' : 'إنشاء') : ($isEn ? 'Save' : 'حفظ')); ?></button>
            <a class="btn ghost" href="sas.php"><?php echo e($isEn ? 'Cancel' : 'إلغاء'); ?></a>
        </div>
    </form>
</section>
<?php if (!$isNew): ?>
<section class="su-card su-panel" id="su-sessions" hidden>
    <div class="su-card-h">
        <h2><?php echo e($isEn ? 'Sessions' : 'الجلسات'); ?></h2>
        <label class="su-sess-limit"><?php echo e($isEn ? 'Show' : 'العدد'); ?>
            <select id="sasSessionsLimit">
                <option value="10">10</option>
                <option value="30">30</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </label>
    </div>
    <div id="sasSessionsPanel" hidden>
        <div id="sasSessionsNote"></div>
        <div class="table-wrap">
            <table class="data-table" id="sasSessionsTable">
                <thead>
                <tr>
                    <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                    <th>MAC</th>
                    <th>IP</th>
                    <th><?php echo e($isEn ? 'Start' : 'البداية'); ?></th>
                    <th><?php echo e($isEn ? 'End' : 'النهاية'); ?></th>
                    <th><?php echo e($isEn ? 'Duration' : 'المدة'); ?></th>
                    <th><?php echo e($isEn ? 'Upload' : 'الرفع'); ?></th>
                    <th><?php echo e($isEn ? 'Download' : 'التحميل'); ?></th>
                    <th><?php echo e($isEn ? 'Usage' : 'الاستهلاك'); ?></th>
                </tr>
                </thead>
                <tbody id="sasSessionsBody"></tbody>
            </table>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!$isNew): ?>
<div class="su-pair">
<section class="su-card su-panel" id="rental" hidden>
    <div class="su-card-h">
        <h2><?php echo e($isEn ? 'Rental device' : 'جهاز إيجار'); ?></h2>
        <span class="meta"><?php echo e($isEn ? 'On this server only' : 'على السيرفر فقط'); ?></span>
    </div>
    <form method="post" id="rentalForm">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="update_rental">
        <div class="actions toggle-row" style="margin-top:0">
            <label class="toggle">
                <input type="checkbox" name="rental_enabled" value="1" id="rentalEnabledChk" <?php echo $hasRent ? 'checked' : ''; ?>>
                <span class="toggle-ui"></span>
                <span class="toggle-text"><?php echo e($isEn ? 'Rental device' : 'جهاز إيجار'); ?></span>
            </label>
            <span class="meta" style="font-weight:700;color:var(--muted)">
                <?php echo e($isEn ? 'Fee' : 'الإيجار'); ?>:
                <?php echo e(money_format_iqd($rentalFee, $config['currency'])); ?> / <?php echo e($isEn ? 'month' : 'شهر'); ?>
            </span>
        </div>
        <div id="rentalDetails"<?php echo $hasRent ? '' : ' hidden'; ?> style="<?php echo $hasRent ? '' : 'display:none'; ?>">
            <div style="margin-top:12px;max-width:360px">
                <label><?php echo e($isEn ? 'Device type' : 'نوع الجهاز'); ?></label>
                <select name="rental_device_id" id="rentalDeviceSelect"<?php echo $hasRent ? '' : ' disabled'; ?>>
                    <option value=""><?php echo e($isEn ? 'Choose…' : 'اختر…'); ?></option>
                    <?php foreach ($rentalDevices as $d): ?>
                        <option value="<?php echo e($d['id']); ?>" <?php echo ($hasRent && $localSub && $localSub['rental_device_id'] === $d['id']) ? 'selected' : ''; ?>>
                            <?php echo e($d['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($currentRentDev): ?>
                    <div style="margin-top:10px">
                        <?php echo rental_badge_html($localSub, $settingsRental); ?>
                        <strong style="margin-right:8px"><?php echo e($currentRentDev['name']); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
            <div class="actions" style="margin-top:12px">
                <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
            </div>
        </div>
        <div id="rentalSaveOff" class="actions"<?php echo $hasRent ? ' hidden' : ''; ?> style="margin-top:12px;<?php echo $hasRent ? 'display:none' : ''; ?>">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        </div>
    </form>
    <div id="rentalMsgActions" class="actions"<?php echo $hasRent ? '' : ' hidden'; ?> style="margin-top:10px;<?php echo $hasRent ? '' : 'display:none'; ?>">
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="msg_rental_only">
            <button class="btn secondary" type="submit"><?php echo e($isEn ? 'Send rent message' : 'رسالة الإيجار فقط'); ?></button>
        </form>
        <form method="post" style="display:inline" onsubmit="return confirm('<?php echo e($isEn ? 'Send device return request?' : 'إرسال طلب استرجاع الجهاز؟'); ?>');">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="msg_rental_return">
            <button class="btn danger" type="submit"><?php echo e($isEn ? 'Request device return' : 'طلب استرجاع الجهاز'); ?></button>
        </form>
    </div>
</section>
</div>
<?php endif; ?>
</div>
<?php if (!$isNew): ?>
<div class="id-ask" id="idPrintAsk" hidden>
    <div class="box">
        <h3><?php echo e($isEn ? 'Subscriber card' : 'هوية المشترك'); ?></h3>
        <p><?php echo e($isEn ? 'Download a PDF, or send the card to the subscriber on WhatsApp.' : 'تنزيل PDF، أو إرسال الهوية لواتساب المشترك.'); ?></p>
        <div class="row">
            <button type="button" class="btn" id="idPrintDownload"><?php echo e($isEn ? 'Download' : 'تنزيل'); ?></button>
            <form method="post" style="flex:1;display:flex">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="send_identity">
                <input type="hidden" name="card_text" value="<?php echo e($idCardText); ?>">
                <button class="btn secondary" type="submit" style="width:100%"><?php echo e($isEn ? 'Send to subscriber' : 'إرسال للمشترك'); ?></button>
            </form>
        </div>
        <button type="button" class="btn ghost" id="idPrintClose" style="margin-top:10px;width:100%"><?php echo e($isEn ? 'Cancel' : 'إلغاء'); ?></button>
    </div>
</div>
<?php endif; ?>
<script>
(function () {
  var phoneInp = document.querySelector('input[name="phone"]');
  function localPhone11(raw) {
    var d = String(raw || '').replace(/\D/g, '');
    if (d.indexOf('964') === 0 && d.length >= 12) d = '0' + d.slice(3);
    return d.replace(/\D/g, '').slice(0, 11);
  }
  if (phoneInp) {
    phoneInp.addEventListener('input', function () {
      var next = localPhone11(phoneInp.value);
      if (phoneInp.value !== next) phoneInp.value = next;
    });
    if (phoneInp.form) {
      phoneInp.form.addEventListener('submit', function (ev) {
        if (String(phoneInp.value || '').replace(/\D/g, '') === '') return;
        var digits = localPhone11(phoneInp.value);
        phoneInp.value = digits;
        if (digits.length !== 11) {
          ev.preventDefault();
          alert(<?php echo json_encode($isEn ? 'Phone must be 11 digits' : 'رقم الهاتف لازم يكون 11 رقم'); ?>);
        }
      });
    }
  }
  var mode = document.getElementById('graceModeSel');
  var inp = document.getElementById('graceDaysInp');
  if (mode && inp) {
    function syncGrace() {
      inp.style.display = mode.value === 'custom' ? '' : 'none';
    }
    mode.addEventListener('change', syncGrace);
    syncGrace();
  }
  var autoAct = document.getElementById('suAutoActivate');
  var autoNotifyWrap = document.getElementById('suAutoNotifyWrap');
  var autoNotify = document.getElementById('suAutoNotify');
  if (autoAct && autoNotifyWrap) {
    function syncAutoNotify() {
      var on = !!autoAct.checked;
      autoNotifyWrap.hidden = !on;
      if (on && autoNotify) autoNotify.checked = true;
    }
    autoAct.addEventListener('change', syncAutoNotify);
    syncAutoNotify();
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-su-toggle]'), function (tog) {
    tog.addEventListener('click', function () {
      var el = document.getElementById(tog.getAttribute('data-su-toggle'));
      if (!el) return;
      var show = !!el.hidden;
      el.hidden = !show;
      tog.classList.toggle('is-open', show);
      if (show && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
  var printOpen = document.getElementById('idPrintOpen');
  var printAsk = document.getElementById('idPrintAsk');
  var printClose = document.getElementById('idPrintClose');
  if (printOpen && printAsk) {
    printOpen.addEventListener('click', function () { printAsk.hidden = false; });
  }
  var printDownload = document.getElementById('idPrintDownload');
  if (printDownload) {
    printDownload.addEventListener('click', function () {
      if (printAsk) printAsk.hidden = true;
      var start = function () { setTimeout(function () { window.print(); }, 250); };
      if (window.suPreparePrint) window.suPreparePrint(start);
      else start();
    });
  }
  if (printClose && printAsk) {
    printClose.addEventListener('click', function () { printAsk.hidden = true; });
    printAsk.addEventListener('click', function (ev) {
      if (ev.target === printAsk) printAsk.hidden = true;
    });
  }
  var moreBtn = document.getElementById('sasMoreBtn');
  var moreBox = document.getElementById('sasMoreBox');
  if (moreBtn && moreBox) {
    moreBtn.addEventListener('click', function () {
      moreBox.classList.toggle('hidden');
      moreBtn.textContent = moreBox.classList.contains('hidden')
        ? <?php echo json_encode($isEn ? '+ More fields' : '+ حقول إضافية'); ?>
        : <?php echo json_encode($isEn ? 'Hide extra fields' : 'إخفاء الحقول الإضافية'); ?>;
    });
  }
  var pass = document.getElementById('sasPass');
  var pass2 = document.getElementById('sasPass2');
  if (pass && pass2) {
    pass.addEventListener('input', function () {
      if (pass2.value === '' || pass2.dataset.auto === '1') {
        pass2.value = pass.value;
        pass2.dataset.auto = '1';
      }
    });
    pass2.addEventListener('input', function () { pass2.dataset.auto = '0'; });
  }
  function fillSelect(sel, items, selected) {
    if (!sel) return;
    var cur = selected || sel.value;
    sel.innerHTML = '';
    var blank = document.createElement('option');
    blank.value = '';
    blank.textContent = <?php echo json_encode($isEn ? 'Select…' : 'اختر…'); ?>;
    sel.appendChild(blank);
    (items || []).forEach(function (p) {
      var o = document.createElement('option');
      o.value = String(p.id);
      o.textContent = p.name || ('#' + p.id);
      if (String(p.id) === String(cur)) o.selected = true;
      sel.appendChild(o);
    });
  }
  var profSel = document.getElementById('sasProfileSelect');
  var parSel = document.getElementById('sasParentSelect');
  var profWrap = document.getElementById('sasProfWrap');
  var parentWrap = document.getElementById('sasParentWrap');
  var coSel = document.getElementById('sasCompanySelect');
  var coHidden = document.getElementById('sasCompanyIdHidden');
  var profNow = <?php echo json_encode((string) $profileUiId); ?>;
  var parNow = <?php echo json_encode((string) $parentUiId); ?>;
  var isElUser = <?php echo !empty($isEarthlinkUser) ? 'true' : 'false'; ?>;
  var needCoPick = <?php echo !empty($needCompanyPick) ? 'true' : 'false'; ?>;
  var pickFirstLabel = <?php echo json_encode($isEn ? 'Pick a company first' : 'اختار الشركة أولاً'); ?>;
  var singleCoQs = <?php echo json_encode(($isNew && !$needCompanyPick && count($companyPickOptions) === 1)
      ? ('&account_id=' . (int) $companyPickOptions[0]['account_id']
          . '&company=' . (int) $companyPickOptions[0]['company_id']
          . '&provider=' . rawurlencode($companyPickOptions[0]['provider']))
      : ($isEarthlinkUser
          ? ('&provider=earthlink&company=' . (int) $elCompanyId . '&u=' . rawurlencode($username))
          : '')); ?>;
  function setProfVisible(on) {
    [profWrap, parentWrap].forEach(function (el) {
      if (!el) return;
      if (on) {
        el.removeAttribute('hidden');
        el.style.display = '';
      } else {
        el.setAttribute('hidden', 'hidden');
      }
    });
    if (profSel) {
      if (on) profSel.setAttribute('required', 'required');
      else { profSel.removeAttribute('required'); profSel.value = ''; }
    }
    if (parSel && !isElUser) {
      if (on) parSel.setAttribute('required', 'required');
      else { parSel.removeAttribute('required'); parSel.value = ''; }
    }
  }
  function companyQs() {
    if (isElUser) return singleCoQs;
    if (!coSel) return singleCoQs;
    var opt = coSel.options[coSel.selectedIndex];
    if (!opt || !opt.value) return '';
    var co = opt.getAttribute('data-company') || '0';
    var prov = opt.getAttribute('data-provider') || 'sas';
    if (coHidden) coHidden.value = co;
    return '&account_id=' + encodeURIComponent(opt.value)
      + '&company=' + encodeURIComponent(co)
      + '&provider=' + encodeURIComponent(prov);
  }
  function loadProfMgr(keepSel) {
    var qs = companyQs();
    if (needCoPick && !qs) {
      setProfVisible(false);
      if (profSel) {
        fillSelect(profSel, [], '');
        if (profSel.options[0]) profSel.options[0].textContent = pickFirstLabel;
      }
      if (parSel) {
        fillSelect(parSel, [], '');
        if (parSel.options[0]) parSel.options[0].textContent = pickFirstLabel;
      }
      return;
    }
    setProfVisible(true);
    var keepP = keepSel ? (profSel ? profSel.value : '') : '';
    var keepM = keepSel ? (parSel ? parSel.value : '') : '';
    fetch('sas.php?ajax=profiles' + qs, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        fillSelect(profSel, (d && d.profiles) ? d.profiles : [], keepP || (needCoPick ? '' : profNow));
      })
      .catch(function () {});
    fetch('sas.php?ajax=managers' + qs, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (isElUser && (!d || !d.managers || !d.managers.length) && parNow) {
          fillSelect(parSel, [{ id: parNow, name: <?php echo json_encode($parentLabelNow !== '' ? $parentLabelNow : '—'); ?> }], parNow);
          return;
        }
        fillSelect(parSel, (d && d.managers) ? d.managers : [], keepM || (needCoPick ? '' : parNow));
      })
      .catch(function () {});
  }
  if (coSel) {
    coSel.addEventListener('change', function () { loadProfMgr(false); });
  }
  if (needCoPick) {
    setProfVisible(false);
  } else {
    loadProfMgr(true);
  }

  var rentChk = document.getElementById('rentalEnabledChk');
  var rentDetails = document.getElementById('rentalDetails');
  var rentSaveOff = document.getElementById('rentalSaveOff');
  var rentMsgs = document.getElementById('rentalMsgActions');
  var rentSelect = document.getElementById('rentalDeviceSelect');
  function setShown(el, on) {
    if (!el) return;
    if (on) {
      el.removeAttribute('hidden');
      el.style.display = '';
      el.classList.remove('hidden');
    } else {
      el.setAttribute('hidden', 'hidden');
      el.style.display = 'none';
      el.classList.add('hidden');
    }
  }
  function syncRentalUi() {
    if (!rentChk) return;
    var on = !!rentChk.checked;
    setShown(rentDetails, on);
    setShown(rentSaveOff, !on);
    setShown(rentMsgs, on);
    if (rentSelect) rentSelect.disabled = !on;
  }
  if (rentChk) {
    rentChk.addEventListener('change', syncRentalUi);
    syncRentalUi();
  }
})();
</script>
<?php if (!$isNew): ?>
<script>
(function () {
  var btn = document.getElementById('sasSessionsBtn');
  var panel = document.getElementById('sasSessionsPanel');
  var body = document.getElementById('sasSessionsBody');
  var note = document.getElementById('sasSessionsNote');
  if (!btn || !panel || !body) return;
  var timer = null;
  var busy = false;
  var open = false;
  var lastPack = null;
  var limitEl = document.getElementById('sasSessionsLimit');
  function rowLimit() {
    var n = limitEl ? parseInt(limitEl.value, 10) : 10;
    if (n !== 10 && n !== 30 && n !== 50 && n !== 100) n = 10;
    return n;
  }
  var url = 'sas_user.php?u=' + encodeURIComponent(<?php echo json_encode($username); ?>) + '&ajax=sessions';
  var urlLocal = url + '&local=1';
  var txtOpen = <?php echo json_encode($isEn ? 'Online' : 'متصل'); ?>;
  var txtOff = <?php echo json_encode($isEn ? 'Offline' : 'منفصل'); ?>;
  var txtEmpty = <?php echo json_encode($isEn ? 'No sessions for this subscriber' : 'ماكو جلسات لهذا المشترك'); ?>;
  var txtStale = <?php echo json_encode($isEn ? 'Could not refresh from SAS. Showing the saved sessions.' : 'تعذر التحديث من الساس. هذه آخر جلسات محفوظة.'); ?>;
  var txtFail = <?php echo json_encode($isEn ? 'Could not load sessions from SAS' : 'تعذر جلب الجلسات من الساس'); ?>;
  var txtRefresh = <?php echo json_encode($isEn ? 'Updating…' : 'جارٍ التحديث…'); ?>;
  var txtMonth = <?php echo json_encode($isEn ? 'This month' : 'استهلاك هذا الشهر'); ?>;
  var txtUp = <?php echo json_encode($isEn ? 'Upload' : 'رفع'); ?>;
  var txtDown = <?php echo json_encode($isEn ? 'Download' : 'تحميل'); ?>;
  lastPack = <?php echo json_encode($sessionsSeed); ?>;
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c];
    });
  }
  function paint(d) {
    if (d) lastPack = d;
    var src = (d && d.rows) ? d.rows : [];
    var rows = src.slice(0, rowLimit());
    if (!d || !d.ok) {
      if (note && !body.querySelector('tr td[dir="ltr"]')) note.textContent = txtFail;
      return;
    }
    if (!rows.length) {
      if (note) note.textContent = txtEmpty;
    } else if (note) {
      note.textContent = '';
    }
    if (note && d && d.month_used) {
      var monthLine = txtMonth + ': ' + d.month_used;
      if (d.month_up) monthLine += ' · ' + txtUp + ' ' + d.month_up;
      if (d.month_down) monthLine += ' · ' + txtDown + ' ' + d.month_down;
      note.textContent = (note.textContent ? (note.textContent + ' — ') : '') + monthLine;
    }
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="9">—</td></tr>';
      return;
    }
    var html = '';
    var i;
    for (i = 0; i < rows.length; i++) {
      var r = rows[i] || {};
      html += '<tr>'
        + '<td>' + esc(r.open ? txtOpen : txtOff) + '</td>'
        + '<td dir="ltr">' + esc(r.mac || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.ip || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.start || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.stop || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.duration || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.up || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.down || '—') + '</td>'
        + '<td dir="ltr">' + esc(r.used || '—') + '</td>'
        + '</tr>';
    }
    body.innerHTML = html;
  }
  function showSessions() {
    open = true;
    var sec = document.getElementById('su-sessions');
    if (sec) {
      sec.hidden = false;
      sec.removeAttribute('hidden');
      sec.style.display = 'block';
    }
    panel.hidden = false;
    panel.removeAttribute('hidden');
    panel.style.display = 'block';
    btn.classList.add('is-open');
  }
  function load(done, soft) {
    if (busy) {
      if (done) done();
      return;
    }
    busy = true;
    // عندنا لقطة محلية: اعرضها فوراً وما تعلّق على «جاري الجلب»
    if (lastPack && lastPack.ok) {
      paint(lastPack);
      if (note && soft && lastPack.rows && lastPack.rows.length) {
        note.textContent = (note.textContent ? (note.textContent + ' — ') : '') + txtRefresh;
      }
    } else if (note && !soft) {
      note.textContent = <?php echo json_encode($isEn ? 'Loading sessions…' : 'جاري جلب الجلسات…'); ?>;
    }
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        busy = false;
        if (d && d.ok) paint(d);
        else if (!lastPack || !lastPack.rows || !lastPack.rows.length) paint(d);
        else if (note) note.textContent = txtStale;
        if (done) done();
      })
      .catch(function () {
        busy = false;
        if (lastPack && lastPack.ok) paint(lastPack);
        else paint(null);
        if (done) done();
      });
  }
  window.suPreparePrint = function (done) {
    showSessions();
    if (lastPack && lastPack.ok && lastPack.rows && lastPack.rows.length) {
      paint(lastPack);
      if (done) done();
      return;
    }
    load(done, false);
  };
  if (limitEl) {
    limitEl.addEventListener('change', function () {
      if (lastPack) paint(lastPack);
    });
  }
  btn.addEventListener('click', function () {
    if (open) {
      open = false;
      var secClose = document.getElementById('su-sessions');
      if (secClose) {
        secClose.hidden = true;
        secClose.style.display = 'none';
      }
      panel.hidden = true;
      panel.style.display = 'none';
      btn.classList.remove('is-on');
      btn.classList.remove('is-open');
      if (timer) { clearInterval(timer); timer = null; }
      return;
    }
    showSessions();
    var sec = document.getElementById('su-sessions');
    if (sec && sec.scrollIntoView) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    // أولاً اللقطة المحمّلة مع الصفحة، ثم تحديث خلفي
    if (lastPack && lastPack.ok) paint(lastPack);
    load(null, true);
    if (timer) clearInterval(timer);
    timer = setInterval(function () { load(null, true); }, 20000);
  });
})();
</script>
<?php endif; ?>
<script>
(function () {
  var btn = document.getElementById('suPauseBtn');
  if (!btn) return;
  var csrf = <?php echo json_encode(csrf_token()); ?>;
  var pauseLabel = <?php echo json_encode($isEn ? 'Pause user' : 'إيقاف مؤقت'); ?>;
  var onLabel = <?php echo json_encode($isEn ? 'Enable user' : 'تشغيل اليوزر'); ?>;
  var pauseIco = <?php echo json_encode('<svg class="su-hero-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>'); ?>;
  var playIco = <?php echo json_encode('<svg class="su-hero-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>'); ?>;
  function paint(nowOn) {
    btn.setAttribute('data-on', nowOn ? '1' : '0');
    btn.classList.toggle('is-off', !nowOn);
    var ico = btn.querySelector('.su-pause-ico');
    var txt = btn.querySelector('.su-pause-txt');
    if (ico) ico.innerHTML = nowOn ? pauseIco : playIco;
    if (txt) txt.textContent = nowOn ? pauseLabel : onLabel;
  }
  btn.addEventListener('click', function () {
    if (btn.getAttribute('data-busy') === '1') return;
    var on = btn.getAttribute('data-on') === '1';
    var ask = on
      ? <?php echo json_encode($isEn ? 'Pause this user?' : 'إيقاف هذا اليوزر مؤقتاً؟'); ?>
      : <?php echo json_encode($isEn ? 'Enable this user?' : 'تشغيل هذا اليوزر؟'); ?>;
    if (!window.confirm(ask)) return;
    btn.setAttribute('data-busy', '1');
    var body = new FormData();
    body.append('csrf', csrf);
    body.append('action', 'pause_user');
    body.append('ajax', '1');
    body.append('enabled', on ? '0' : '1');
    fetch(window.location.href, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.removeAttribute('data-busy');
        var ok = d && d.ok;
        if (ok) {
          var nowOn = d.enabled === 1 || d.enabled === '1';
          paint(nowOn);
        }
        if (window.showCornerToast) {
          window.showCornerToast((d && d.message) || (ok ? pauseLabel : onLabel), ok ? 'ok' : 'error');
        }
      })
      .catch(function () {
        btn.removeAttribute('data-busy');
        if (window.showCornerToast) {
          window.showCornerToast(<?php echo json_encode($isEn ? 'Network error' : 'فشل الاتصال'); ?>, 'error');
        }
      });
  });
})();
</script>
<?php
render_footer();
