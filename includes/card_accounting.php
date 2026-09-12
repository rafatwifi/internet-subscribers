<?php

/**
 * محاسبة كروت الوكلاء: مخزون، تحويلات، دفعات.
 */

function ensure_card_accounting_tables($pdo)
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS agent_card_stock (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                agent_user_id INT UNSIGNED NOT NULL,
                profile_id INT UNSIGNED NOT NULL DEFAULT 0,
                profile_name VARCHAR(120) NOT NULL DEFAULT "",
                qty INT NOT NULL DEFAULT 0,
                wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                agent_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_agent_profile (agent_user_id, profile_id, profile_name(60)),
                KEY idx_stock_agent (agent_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS agent_card_transfers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                from_agent_id INT UNSIGNED NULL DEFAULT NULL,
                to_agent_id INT UNSIGNED NOT NULL,
                profile_id INT UNSIGNED NOT NULL DEFAULT 0,
                profile_name VARCHAR(120) NOT NULL DEFAULT "",
                qty INT NOT NULL DEFAULT 0,
                wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                agent_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                note VARCHAR(255) NULL DEFAULT NULL,
                created_by INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_xfer_to (to_agent_id),
                KEY idx_xfer_from (from_agent_id),
                KEY idx_xfer_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS agent_card_payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                agent_user_id INT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                note VARCHAR(255) NULL DEFAULT NULL,
                created_by INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pay_agent (agent_user_id),
                KEY idx_pay_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $ready = true;
    } catch (Exception $e) {
        $ready = false;
        throw $e;
    }
}

function card_transfer_profit($wholesalePrice, $agentPrice, $qty)
{
    $w = (float) $wholesalePrice;
    $a = (float) $agentPrice;
    $q = (int) $qty;
    return ($a - $w) * $q;
}

function card_stock_row_key($profileId, $profileName)
{
    $profileId = (int) $profileId;
    $profileName = trim((string) $profileName);
    if ($profileName === '') {
        $profileName = '—';
    }
    return array($profileId, $profileName);
}

function card_stock_adjust($pdo, $agentUserId, $profileId, $profileName, $qtyDelta, $wholesalePrice, $agentPrice)
{
    $agentUserId = (int) $agentUserId;
    if ($agentUserId <= 0) {
        return false;
    }
    list($profileId, $profileName) = card_stock_row_key($profileId, $profileName);
    $qtyDelta = (int) $qtyDelta;
    if ($qtyDelta === 0) {
        return true;
    }

    $st = $pdo->prepare(
        'SELECT id, qty FROM agent_card_stock
         WHERE agent_user_id = :a AND profile_id = :p AND profile_name = :n LIMIT 1'
    );
    $st->execute(array(':a' => $agentUserId, ':p' => $profileId, ':n' => $profileName));
    $row = $st->fetch();

    if ($row) {
        $newQty = (int) $row['qty'] + $qtyDelta;
        if ($newQty < 0) {
            return false;
        }
        if ($newQty === 0) {
            $pdo->prepare('DELETE FROM agent_card_stock WHERE id = :id')->execute(array(':id' => (int) $row['id']));
            return true;
        }
        $pdo->prepare(
            'UPDATE agent_card_stock SET qty = :q, wholesale_price = :w, agent_price = :ap, updated_at = NOW()
             WHERE id = :id'
        )->execute(array(
            ':q' => $newQty,
            ':w' => (float) $wholesalePrice,
            ':ap' => (float) $agentPrice,
            ':id' => (int) $row['id'],
        ));
        return true;
    }

    if ($qtyDelta < 0) {
        return false;
    }

    $pdo->prepare(
        'INSERT INTO agent_card_stock (agent_user_id, profile_id, profile_name, qty, wholesale_price, agent_price)
         VALUES (:a, :p, :n, :q, :w, :ap)'
    )->execute(array(
        ':a' => $agentUserId,
        ':p' => $profileId,
        ':n' => $profileName,
        ':q' => $qtyDelta,
        ':w' => (float) $wholesalePrice,
        ':ap' => (float) $agentPrice,
    ));
    return true;
}

/**
 * @return array(bool ok, string message, int transfer_id)
 */
function transfer_cards($pdo, $fromAgentId, $toAgentId, $profileId, $profileName, $qty, $wholesalePrice, $agentPrice, $note, $createdBy)
{
    ensure_card_accounting_tables($pdo);

    $fromAgentId = (int) $fromAgentId;
    $toAgentId = (int) $toAgentId;
    $qty = (int) $qty;
    list($profileId, $profileName) = card_stock_row_key($profileId, $profileName);
    $note = trim((string) $note);
    $createdBy = (int) $createdBy;

    if ($toAgentId <= 0) {
        return array(false, 'to_agent_required', 0);
    }
    if ($qty <= 0) {
        return array(false, 'qty_invalid', 0);
    }
    if ($fromAgentId > 0 && $fromAgentId === $toAgentId) {
        return array(false, 'same_agent', 0);
    }

    try {
        $pdo->beginTransaction();

        if ($fromAgentId > 0) {
            $ok = card_stock_adjust($pdo, $fromAgentId, $profileId, $profileName, -$qty, $wholesalePrice, $agentPrice);
            if (!$ok) {
                $pdo->rollBack();
                return array(false, 'insufficient_stock', 0);
            }
        }

        $ok = card_stock_adjust($pdo, $toAgentId, $profileId, $profileName, $qty, $wholesalePrice, $agentPrice);
        if (!$ok) {
            $pdo->rollBack();
            return array(false, 'stock_update_failed', 0);
        }

        $ins = $pdo->prepare(
            'INSERT INTO agent_card_transfers
             (from_agent_id, to_agent_id, profile_id, profile_name, qty, wholesale_price, agent_price, note, created_by)
             VALUES (:f, :t, :p, :n, :q, :w, :ap, :note, :by)'
        );
        $ins->execute(array(
            ':f' => $fromAgentId > 0 ? $fromAgentId : null,
            ':t' => $toAgentId,
            ':p' => $profileId,
            ':n' => $profileName,
            ':q' => $qty,
            ':w' => (float) $wholesalePrice,
            ':ap' => (float) $agentPrice,
            ':note' => $note !== '' ? $note : null,
            ':by' => $createdBy > 0 ? $createdBy : null,
        ));
        $xferId = (int) $pdo->lastInsertId();
        $pdo->commit();
        return array(true, 'ok', $xferId);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return array(false, $e->getMessage(), 0);
    }
}

function card_agent_stock_summary($pdo, $agentUserId)
{
    ensure_card_accounting_tables($pdo);
    $agentUserId = (int) $agentUserId;
    if ($agentUserId <= 0) {
        return array('rows' => array(), 'total_qty' => 0);
    }
    $st = $pdo->prepare(
        'SELECT profile_id, profile_name, qty, wholesale_price, agent_price
         FROM agent_card_stock
         WHERE agent_user_id = :a AND qty > 0
         ORDER BY profile_name ASC, profile_id ASC'
    );
    $st->execute(array(':a' => $agentUserId));
    $rows = $st->fetchAll();
    $total = 0;
    foreach ($rows as $r) {
        $total += (int) $r['qty'];
    }
    return array('rows' => $rows, 'total_qty' => $total);
}

function card_agent_transfers_incoming($pdo, $agentUserId)
{
    ensure_card_accounting_tables($pdo);
    $agentUserId = (int) $agentUserId;
    if ($agentUserId <= 0) {
        return array('count' => 0, 'qty' => 0, 'sale_total' => 0.0, 'profit_total' => 0.0);
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(COUNT(*),0) AS c,
                COALESCE(SUM(qty),0) AS q,
                COALESCE(SUM(agent_price * qty),0) AS sale,
                COALESCE(SUM((agent_price - wholesale_price) * qty),0) AS profit
         FROM agent_card_transfers WHERE to_agent_id = :a'
    );
    $st->execute(array(':a' => $agentUserId));
    $row = $st->fetch();
    return array(
        'count' => $row ? (int) $row['c'] : 0,
        'qty' => $row ? (int) $row['q'] : 0,
        'sale_total' => $row ? (float) $row['sale'] : 0.0,
        'profit_total' => $row ? (float) $row['profit'] : 0.0,
    );
}

function card_agent_payments_total($pdo, $agentUserId)
{
    ensure_card_accounting_tables($pdo);
    $agentUserId = (int) $agentUserId;
    if ($agentUserId <= 0) {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(amount),0) FROM agent_card_payments WHERE agent_user_id = :a'
    );
    $st->execute(array(':a' => $agentUserId));
    return (float) $st->fetchColumn();
}

function card_agent_remaining_balance($pdo, $agentUserId)
{
    $incoming = card_agent_transfers_incoming($pdo, $agentUserId);
    $paid = card_agent_payments_total($pdo, $agentUserId);
    return (float) $incoming['sale_total'] - $paid;
}

function list_recent_card_transfers($pdo, $limit = 30, $agentUserId = null)
{
    ensure_card_accounting_tables($pdo);
    $limit = max(1, min(200, (int) $limit));
    $sql = 'SELECT t.*,
                   fa.display_name AS from_name, fa.username AS from_username,
                   ta.display_name AS to_name, ta.username AS to_username,
                   cb.display_name AS created_by_name
            FROM agent_card_transfers t
            LEFT JOIN admin_users fa ON fa.id = t.from_agent_id
            JOIN admin_users ta ON ta.id = t.to_agent_id
            LEFT JOIN admin_users cb ON cb.id = t.created_by';
    $params = array();
    if ($agentUserId !== null && (int) $agentUserId > 0) {
        $sql .= ' WHERE t.to_agent_id = :a OR t.from_agent_id = :a2';
        $params[':a'] = (int) $agentUserId;
        $params[':a2'] = (int) $agentUserId;
    }
    $sql .= ' ORDER BY t.created_at DESC, t.id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function list_recent_card_payments($pdo, $agentUserId, $limit = 20)
{
    ensure_card_accounting_tables($pdo);
    $agentUserId = (int) $agentUserId;
    $limit = max(1, min(100, (int) $limit));
    if ($agentUserId <= 0) {
        return array();
    }
    $st = $pdo->prepare(
        'SELECT p.*, cb.display_name AS created_by_name
         FROM agent_card_payments p
         LEFT JOIN admin_users cb ON cb.id = p.created_by
         WHERE p.agent_user_id = :a
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT ' . $limit
    );
    $st->execute(array(':a' => $agentUserId));
    return $st->fetchAll();
}

/**
 * @return array(bool ok, string message, int payment_id)
 */
function record_card_payment($pdo, $agentUserId, $amount, $note, $createdBy)
{
    ensure_card_accounting_tables($pdo);
    $agentUserId = (int) $agentUserId;
    $amount = (float) $amount;
    $note = trim((string) $note);
    $createdBy = (int) $createdBy;

    if ($agentUserId <= 0) {
        return array(false, 'agent_required', 0);
    }
    if ($amount <= 0) {
        return array(false, 'amount_invalid', 0);
    }

    try {
        $pdo->prepare(
            'INSERT INTO agent_card_payments (agent_user_id, amount, note, created_by)
             VALUES (:a, :m, :n, :by)'
        )->execute(array(
            ':a' => $agentUserId,
            ':m' => $amount,
            ':n' => $note !== '' ? $note : null,
            ':by' => $createdBy > 0 ? $createdBy : null,
        ));
        return array(true, 'ok', (int) $pdo->lastInsertId());
    } catch (Exception $e) {
        return array(false, $e->getMessage(), 0);
    }
}

function card_accounting_dashboard($pdo, $agentUserId)
{
    $stock = card_agent_stock_summary($pdo, $agentUserId);
    $incoming = card_agent_transfers_incoming($pdo, $agentUserId);
    $paid = card_agent_payments_total($pdo, $agentUserId);
    $remaining = (float) $incoming['sale_total'] - $paid;
    return array(
        'stock' => $stock,
        'transfers' => $incoming,
        'payments_total' => $paid,
        'profit_total' => (float) $incoming['profit_total'],
        'remaining' => $remaining,
    );
}

function list_accountant_users($pdo, $activeOnly = true)
{
    try {
        ensure_admin_users_table($pdo);
        $sql = 'SELECT id, username, display_name, role, is_active, linked_agent_id, created_at
                FROM admin_users WHERE role = "accountant"';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY display_name ASC, id ASC';
        return $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        return array();
    }
}

function card_transfer_error_message($code, $lang = 'ar')
{
    $isEn = ($lang === 'en');
    $map = array(
        'to_agent_required' => $isEn ? 'Select destination agent' : 'اختر الوكيل المستلم',
        'qty_invalid' => $isEn ? 'Quantity must be greater than zero' : 'الكمية لازم أكبر من صفر',
        'same_agent' => $isEn ? 'Source and destination must differ' : 'المصدر والوجهة لازم يختلفون',
        'insufficient_stock' => $isEn ? 'Insufficient stock at source agent' : 'المخزون غير كافٍ عند الوكيل المصدر',
        'stock_update_failed' => $isEn ? 'Could not update stock' : 'تعذر تحديث المخزون',
        'agent_required' => $isEn ? 'Agent required' : 'الوكيل مطلوب',
        'amount_invalid' => $isEn ? 'Amount must be greater than zero' : 'المبلغ لازم أكبر من صفر',
    );
    if (isset($map[$code])) {
        return $map[$code];
    }
    return $code;
}
