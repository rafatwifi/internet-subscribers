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

    $ready = true;
}

/**
 * تسجيل حركة في السجل (مع اسم المستخدم الحالي إن وُجد)
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
        } catch (Exception $e2) {
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
    ensure_activity_logs_table($pdo);
    $limit = (int) $limit;
    if ($limit < 1) {
        $limit = 300;
    }
    $params = array();
    $where = '1=1';
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
