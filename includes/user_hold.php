<?php

function user_hold_ensure($pdo)
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS deleted_user_holds (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL,
            old_user_id INT UNSIGNED NOT NULL,
            username VARCHAR(80) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            subscriber_ids TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            restored_user_id INT UNSIGNED NULL DEFAULT NULL,
            KEY idx_hold_tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function user_hold_open_list($pdo)
{
    user_hold_ensure($pdo);
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $st = $pdo->prepare(
        'SELECT * FROM deleted_user_holds
         WHERE tenant_id = :t AND restored_user_id IS NULL
         ORDER BY id DESC'
    );
    $st->execute(array(':t' => $tid));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return $rows ? $rows : array();
}

function user_hold_owner_id($pdo, $tenantId, $exceptId)
{
    $st = $pdo->prepare(
        'SELECT id FROM admin_users
         WHERE tenant_id = :t AND role = "admin" AND is_active = 1 AND id <> :id
         ORDER BY id ASC LIMIT 1'
    );
    $st->execute(array(':t' => (int) $tenantId, ':id' => (int) $exceptId));
    return (int) $st->fetchColumn();
}

function user_hold_apply_delete($pdo, $userId, $currentId, $dest)
{
    user_hold_ensure($pdo);
    $userId = (int) $userId;
    $currentId = (int) $currentId;
    if ($userId <= 0 || $userId === $currentId) {
        return array(false, 'ما تكدر تحذف نفسك');
    }
    $row = get_admin_user($pdo, $userId);
    if (!$row) {
        return array(false, 'المستخدم مو موجود');
    }
    if (function_exists('normalize_admin_role') && normalize_admin_role($row['role']) === 'admin') {
        $admins = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        if ($admins <= 1) {
            return array(false, 'ما تكدر تحذف آخر مدير');
        }
    }
    $tid = isset($row['tenant_id']) ? (int) $row['tenant_id'] : (function_exists('current_tenant_id') ? (int) current_tenant_id() : 1);
    $idsSt = $pdo->prepare('SELECT id FROM subscribers WHERE tenant_id = :t AND agent_user_id = :a');
    $idsSt->execute(array(':t' => $tid, ':a' => $userId));
    $ids = $idsSt->fetchAll(PDO::FETCH_COLUMN);
    $target = 0;
    $hold = false;
    if ($dest === 'hold') {
        $hold = true;
        $target = user_hold_owner_id($pdo, $tid, $userId);
        if ($target <= 0) {
            $target = $currentId;
        }
    } elseif ($dest === 'owner') {
        $target = user_hold_owner_id($pdo, $tid, $userId);
        if ($target <= 0) {
            return array(false, 'ماكو مدير ثاني حتى أنقل له البيانات');
        }
    } elseif (strpos((string) $dest, 'agent:') === 0) {
        $target = (int) substr($dest, 6);
        if ($target <= 0 || $target === $userId) {
            return array(false, 'اختر وكيلاً غيره');
        }
        $chk = $pdo->prepare('SELECT id FROM admin_users WHERE id = :id AND tenant_id = :t LIMIT 1');
        $chk->execute(array(':id' => $target, ':t' => $tid));
        if (!(int) $chk->fetchColumn()) {
            return array(false, 'الوكيل مو من نفس الوكالة');
        }
    } else {
        return array(false, 'حدد وين تروح بيانات المشتركين');
    }
    if ($ids && $target > 0) {
        $pdo->prepare('UPDATE subscribers SET agent_user_id = :to WHERE tenant_id = :t AND agent_user_id = :from')
            ->execute(array(':to' => $target, ':t' => $tid, ':from' => $userId));
    }
    if ($hold) {
        $pdo->prepare(
            'INSERT INTO deleted_user_holds (tenant_id, old_user_id, username, display_name, subscriber_ids)
             VALUES (:t, :oid, :u, :d, :ids)'
        )->execute(array(
            ':t' => $tid,
            ':oid' => $userId,
            ':u' => (string) $row['username'],
            ':d' => (string) $row['display_name'],
            ':ids' => implode(',', array_map('intval', $ids)),
        ));
    }
    $del = delete_admin_user($pdo, $userId, $currentId);
    if ($del !== 'ok') {
        return array(false, 'تعذر حذف حساب الدخول');
    }
    $n = count($ids);
    if ($hold) {
        return array(true, 'تم حذف المستخدم. ' . $n . ' مشترك محفوظين، وترجّعهم من إضافة مستخدم.');
    }
    return array(true, 'تم حذف المستخدم ونقل ' . $n . ' مشترك.');
}

function user_hold_restore($pdo, $holdId, $newUserId)
{
    user_hold_ensure($pdo);
    $holdId = (int) $holdId;
    $newUserId = (int) $newUserId;
    if ($holdId <= 0 || $newUserId <= 0) {
        return false;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $st = $pdo->prepare('SELECT * FROM deleted_user_holds WHERE id = :id AND tenant_id = :t AND restored_user_id IS NULL LIMIT 1');
    $st->execute(array(':id' => $holdId, ':t' => $tid));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $ids = array_filter(array_map('intval', explode(',', (string) $row['subscriber_ids'])));
    if ($ids) {
        $in = implode(',', $ids);
        $pdo->exec('UPDATE subscribers SET agent_user_id = ' . $newUserId . ' WHERE tenant_id = ' . (int) $tid . ' AND id IN (' . $in . ')');
    }
    $pdo->prepare('UPDATE deleted_user_holds SET restored_user_id = :u WHERE id = :id')
        ->execute(array(':u' => $newUserId, ':id' => $holdId));
    return true;
}
