<?php

function ensure_activity_logs_table($pdo)
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS activity_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                subscriber_id INT UNSIGNED DEFAULT NULL,
                entity_type VARCHAR(40) NOT NULL,
                entity_id INT UNSIGNED DEFAULT NULL,
                action VARCHAR(40) NOT NULL,
                summary VARCHAR(255) NOT NULL,
                details TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_activity_sub (subscriber_id),
                INDEX idx_activity_created (created_at),
                INDEX idx_activity_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Exception $e) {
        // تجاهل
    }

    try {
        $col = $pdo->query("SHOW COLUMNS FROM activity_logs LIKE 'actor_user_id'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE activity_logs ADD COLUMN actor_user_id INT UNSIGNED DEFAULT NULL AFTER details');
        }
    } catch (Exception $e) {
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM activity_logs LIKE 'actor_name'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE activity_logs ADD COLUMN actor_name VARCHAR(80) DEFAULT NULL AFTER actor_user_id');
        }
    } catch (Exception $e) {
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM activity_logs LIKE 'actor_ip'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE activity_logs ADD COLUMN actor_ip VARCHAR(45) DEFAULT NULL AFTER actor_name');
        }
    } catch (Exception $e) {
    }
    try {
        $col = $pdo->query("SHOW COLUMNS FROM activity_logs LIKE 'actor_ip_kind'")->fetch();
        if (!$col) {
            $pdo->exec('ALTER TABLE activity_logs ADD COLUMN actor_ip_kind VARCHAR(20) DEFAULT NULL AFTER actor_ip');
        }
    } catch (Exception $e) {
    }

    $ready = true;
}

/**
 * عنوان الطلب الحالي (مع احترام X-Forwarded-For عند البروكسي)
 */
function activity_request_ip()
{
    $candidates = array();
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $candidates[] = trim($part);
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = (string) $_SERVER['HTTP_X_REAL_IP'];
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = (string) $_SERVER['REMOTE_ADDR'];
    }
    foreach ($candidates as $ip) {
        $ip = trim((string) $ip);
        if ($ip === '') {
            continue;
        }
        if (strpos($ip, ':') !== false && strpos($ip, '.') !== false) {
            // ممكن IPv4:port
            $bits = explode(':', $ip);
            if (count($bits) === 2 && filter_var($bits[0], FILTER_VALIDATE_IP)) {
                $ip = $bits[0];
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '';
}

/**
 * داخلي (شبكة خاصة/محلية) أو خارجي (إنترنت عام)
 * يرجع array(ip, kind) حيث kind = internal|external|unknown
 */
function activity_ip_meta($ip = null)
{
    if ($ip === null) {
        $ip = activity_request_ip();
    }
    $ip = trim((string) $ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return array('ip' => '', 'kind' => 'unknown');
    }
    $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($public) {
        return array('ip' => $ip, 'kind' => 'external');
    }
    return array('ip' => $ip, 'kind' => 'internal');
}

function activity_ip_kind_label($kind, $en = false)
{
    $kind = (string) $kind;
    if ($kind === 'external') {
        return $en ? 'external' : 'خارجي';
    }
    if ($kind === 'internal') {
        return $en ? 'internal' : 'داخلي';
    }
    return $en ? 'unknown' : 'غير معروف';
}

/**
 * تسجيل حركة في السجل (مع اسم المستخدم وعنوان الـ IP إن وُجد)
 */
function activity_log($pdo, $subscriberId, $entityType, $entityId, $action, $summary, $details = '')
{
    try {
        ensure_activity_logs_table($pdo);
        $actorId = null;
        $actorName = null;
        if (function_exists('current_admin')) {
            $u = current_admin();
            if ($u) {
                $actorId = $u['id'] > 0 ? $u['id'] : null;
                $actorName = current_admin_label();
            }
        }
        $ipMeta = activity_ip_meta();
        $actorIp = $ipMeta['ip'] !== '' ? $ipMeta['ip'] : null;
        $actorIpKind = $ipMeta['kind'] !== 'unknown' ? $ipMeta['kind'] : ($actorIp ? $ipMeta['kind'] : null);

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO activity_logs
                 (subscriber_id, entity_type, entity_id, action, summary, details, actor_user_id, actor_name, actor_ip, actor_ip_kind)
                 VALUES (:sid, :etype, :eid, :action, :summary, :details, :aid, :aname, :aip, :akind)'
            );
            $stmt->execute(array(
                ':sid' => $subscriberId ? (int) $subscriberId : null,
                ':etype' => (string) $entityType,
                ':eid' => $entityId ? (int) $entityId : null,
                ':action' => (string) $action,
                ':summary' => (string) $summary,
                ':details' => ($details !== '' && $details !== null) ? (string) $details : null,
                ':aid' => $actorId,
                ':aname' => $actorName,
                ':aip' => $actorIp,
                ':akind' => $actorIpKind,
            ));
        } catch (Exception $e2) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO activity_logs
                     (subscriber_id, entity_type, entity_id, action, summary, details, actor_user_id, actor_name)
                     VALUES (:sid, :etype, :eid, :action, :summary, :details, :aid, :aname)'
                );
                $stmt->execute(array(
                    ':sid' => $subscriberId ? (int) $subscriberId : null,
                    ':etype' => (string) $entityType,
                    ':eid' => $entityId ? (int) $entityId : null,
                    ':action' => (string) $action,
                    ':summary' => (string) $summary,
                    ':details' => ($details !== '' && $details !== null) ? (string) $details : null,
                    ':aid' => $actorId,
                    ':aname' => $actorName,
                ));
            } catch (Exception $e3) {
                $stmt = $pdo->prepare(
                    'INSERT INTO activity_logs
                     (subscriber_id, entity_type, entity_id, action, summary, details)
                     VALUES (:sid, :etype, :eid, :action, :summary, :details)'
                );
                $stmt->execute(array(
                    ':sid' => $subscriberId ? (int) $subscriberId : null,
                    ':etype' => (string) $entityType,
                    ':eid' => $entityId ? (int) $entityId : null,
                    ':action' => (string) $action,
                    ':summary' => (string) $summary,
                    ':details' => ($details !== '' && $details !== null) ? (string) $details : null,
                ));
            }
        }
    } catch (Exception $e) {
        // لا تكسر الصفحة بسبب فشل اللوك
    }
}

/**
 * يسجّل حذف مشترك قبل المسح (الاسم/الهاتف/الدين) حتى يبقى أثر باللوك
 */
function log_subscriber_delete($pdo, $subscriberId)
{
    $subscriberId = (int) $subscriberId;
    if ($subscriberId <= 0) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT id, name, phone, address, notes FROM subscribers WHERE id = :id');
        $stmt->execute(array(':id' => $subscriberId));
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }
        $debt = 0.0;
        if (function_exists('subscriber_unpaid_total')) {
            $debt = (float) subscriber_unpaid_total($pdo, $subscriberId);
        } else {
            $st = $pdo->prepare(
                'SELECT COALESCE(SUM(amount),0) FROM invoices WHERE subscriber_id = :id AND status = "unpaid"'
            );
            $st->execute(array(':id' => $subscriberId));
            $debt = (float) $st->fetchColumn();
        }
        $name = isset($row['name']) ? (string) $row['name'] : '';
        $phone = isset($row['phone']) ? (string) $row['phone'] : '';
        if (function_exists('format_phone_display') && $phone !== '') {
            $phoneDisp = format_phone_display($phone);
        } else {
            $phoneDisp = $phone;
        }
        $summary = 'حذف مشترك: ' . $name;
        $details = 'الاسم: ' . $name . "\n"
            . 'الهاتف: ' . $phoneDisp . "\n"
            . 'إجمالي الدين: ' . $debt;
        if (!empty($row['address'])) {
            $details .= "\nالعنوان: " . $row['address'];
        }
        if (!empty($row['notes'])) {
            $details .= "\nملاحظات: " . $row['notes'];
        }
        activity_log($pdo, $subscriberId, 'subscriber', $subscriberId, 'delete', $summary, $details);
    } catch (Exception $e) {
        // لا تكسر الحذف بسبب اللوك
    }
}

function activity_diff_line($label, $oldVal, $newVal)
{
    $oldVal = (string) $oldVal;
    $newVal = (string) $newVal;
    if ($oldVal === $newVal) {
        return '';
    }
    if ($oldVal === '') {
        $oldVal = '-';
    }
    if ($newVal === '') {
        $newVal = '-';
    }
    return $label . ': ' . $oldVal . ' ← ' . $newVal;
}

/**
 * تسجيل تغيير رقم الهاتف (القديم + الجديد + من نفّذ) في اللوك.
 */
function log_subscriber_phone_change($pdo, $subscriberId, $oldPhone, $newPhone, $source = '')
{
    $subscriberId = (int) $subscriberId;
    if ($subscriberId <= 0 || !function_exists('activity_log')) {
        return false;
    }
    $oldRaw = trim((string) $oldPhone);
    $newRaw = trim((string) $newPhone);
    $oldNorm = function_exists('normalize_phone') ? normalize_phone($oldRaw) : preg_replace('/\D+/', '', $oldRaw);
    $newNorm = function_exists('normalize_phone') ? normalize_phone($newRaw) : preg_replace('/\D+/', '', $newRaw);
    if ($oldNorm === null) {
        $oldNorm = '';
    }
    if ($newNorm === null) {
        $newNorm = '';
    }
    if ($oldNorm === $newNorm) {
        return false;
    }
    $oldDisp = $oldRaw !== ''
        ? (function_exists('format_phone_display') ? format_phone_display($oldRaw) : $oldRaw)
        : '—';
    $newDisp = $newRaw !== ''
        ? (function_exists('format_phone_display') ? format_phone_display($newRaw) : $newRaw)
        : '—';
    if ($oldDisp === '') {
        $oldDisp = '—';
    }
    if ($newDisp === '') {
        $newDisp = '—';
    }
    $details = 'من: ' . $oldDisp . "\nإلى: " . $newDisp;
    if ($source !== '') {
        $details .= "\nالمصدر: " . $source;
    }
    activity_log(
        $pdo,
        $subscriberId,
        'subscriber',
        $subscriberId,
        'phone_change',
        'تغيير رقم الهاتف',
        $details
    );
    return true;
}

function fetch_subscriber_activity($pdo, $subscriberId, $limit = 100)
{
    ensure_activity_logs_table($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM activity_logs
         WHERE subscriber_id = :sid
         ORDER BY id DESC
         LIMIT ' . (int) $limit
    );
    $stmt->execute(array(':sid' => (int) $subscriberId));
    return $stmt->fetchAll();
}

function fetch_global_activity($pdo, $limit = 300, $q = '')
{
    return fetch_activity_filtered($pdo, $limit, $q, '');
}

function fetch_system_activity($pdo, $limit = 300, $q = '')
{
    return fetch_activity_filtered($pdo, $limit, $q, 'system');
}

function fetch_activity_filtered($pdo, $limit, $q, $scope)
{
    ensure_activity_logs_table($pdo);
    $limit = (int) $limit;
    if ($limit < 1) {
        $limit = 300;
    }
    $params = array();
    $where = '1=1';
    if ($scope === 'system') {
        $where .= " AND (a.subscriber_id IS NULL OR a.entity_type IN ('system','tenant','user','saas','backup','auth'))";
    }
    $logPlatform = function_exists('is_super_admin_user') && is_super_admin_user()
        && !(function_exists('is_impersonating') && is_impersonating());
    if ($logPlatform && $scope !== 'system') {
        // لوك البوابة: حركات طاقم المنصة فقط — مو تفعيل/قبض/تسديد وكلاء الشركات
        $where .= ' AND ('
            . 'a.actor_user_id IN (SELECT id FROM admin_users WHERE COALESCE(tenant_id, 1) <= 1)'
            . " OR ("
            . ' (a.actor_user_id IS NULL OR a.actor_user_id = 0)'
            . " AND (a.subscriber_id IS NULL OR a.subscriber_id = 0)"
            . " AND a.entity_type IN ('system','tenant','user','saas','backup','auth','company','platform','membership')"
            . ')'
            . ')';
        $where .= " AND NOT ("
            . " a.entity_type = 'subscriber'"
            . ' AND a.actor_user_id IN (SELECT id FROM admin_users WHERE COALESCE(tenant_id, 1) > 1)'
            . ')';
    } elseif (!$logPlatform && function_exists('current_tenant_id')) {
        $logTid = (int) current_tenant_id();
        $where .= ' AND ('
            . '(a.subscriber_id IS NOT NULL AND a.subscriber_id > 0 AND s.tenant_id = ' . $logTid . ')'
            . ' OR ((a.subscriber_id IS NULL OR a.subscriber_id = 0) AND a.actor_user_id IN (SELECT id FROM admin_users WHERE tenant_id = ' . $logTid . '))'
            . ')';
        if (function_exists('is_accountant_user') && is_accountant_user() && function_exists('current_admin')) {
            $logMe = current_admin();
            $logId = $logMe ? (int) $logMe['id'] : 0;
            if ($logId > 0) {
                $where .= ' AND a.actor_user_id = ' . $logId;
            }
        } elseif (function_exists('is_agent_user') && is_agent_user() && function_exists('current_admin')) {
            $logMe = current_admin();
            $logId = $logMe ? (int) $logMe['id'] : 0;
            if ($logId > 0) {
                $where .= ' AND (a.actor_user_id = ' . $logId
                    . ' OR a.actor_user_id IN (SELECT id FROM admin_users WHERE reports_to_user_id = ' . $logId . ' AND tenant_id = ' . $logTid . ')'
                    . ' OR s.agent_user_id = ' . $logId . ')';
            }
        }
    }
    $q = trim((string) $q);
    if ($q !== '') {
        $where .= ' AND (a.summary LIKE :q OR a.details LIKE :q OR a.actor_name LIKE :q OR a.action LIKE :q OR s.name LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    $sql = 'SELECT a.*, s.name AS subscriber_name
            FROM activity_logs a
            LEFT JOIN subscribers s ON s.id = a.subscriber_id
            WHERE ' . $where . '
            ORDER BY a.id DESC
            LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
