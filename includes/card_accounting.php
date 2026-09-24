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
    if (function_exists('agent_card_prices_list')) {
        $priced = agent_card_prices_list($pdo, $toAgentId);
        if (!$priced) {
            return array(false, 'price_required', 0);
        }
    }
    if ($fromAgentId > 0 && $fromAgentId === $toAgentId) {
        return array(false, 'same_agent', 0);
    }
    if (function_exists('admin_user_same_tenant')) {
        if (!admin_user_same_tenant($pdo, $toAgentId)) {
            return array(false, 'to_agent_required', 0);
        }
        if ($fromAgentId > 0 && !admin_user_same_tenant($pdo, $fromAgentId)) {
            return array(false, 'to_agent_required', 0);
        }
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

        $tenantId = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
        try {
            $stT = $pdo->prepare('SELECT tenant_id FROM admin_users WHERE id = :id LIMIT 1');
            $stT->execute(array(':id' => $toAgentId));
            $t = (int) $stT->fetchColumn();
            if ($t > 0) {
                $tenantId = $t;
            }
        } catch (Exception $e) {
        }

        try {
            $ins = $pdo->prepare(
                'INSERT INTO agent_card_transfers
                 (from_agent_id, to_agent_id, profile_id, profile_name, qty, wholesale_price, agent_price, note, created_by, tenant_id)
                 VALUES (:f, :t, :p, :n, :q, :w, :ap, :note, :by, :tid)'
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
                ':tid' => $tenantId,
            ));
        } catch (Exception $e) {
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
        }
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
    $remaining = card_agent_remaining_balance($pdo, $agentUserId);
    if ($remaining <= 0) {
        return array(false, 'no_balance', 0);
    }
    if ($amount > $remaining + 0.009) {
        $amount = round($remaining, 2);
    }

    $tenantId = 1;
    try {
        $stT = $pdo->prepare('SELECT tenant_id FROM admin_users WHERE id = :id LIMIT 1');
        $stT->execute(array(':id' => $agentUserId));
        $tenantId = max(1, (int) $stT->fetchColumn());
    } catch (Exception $e) {
        $tenantId = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    }

    try {
        $pdo->prepare(
            'INSERT INTO agent_card_payments (agent_user_id, amount, note, created_by, tenant_id)
             VALUES (:a, :m, :n, :by, :tid)'
        )->execute(array(
            ':a' => $agentUserId,
            ':m' => $amount,
            ':n' => $note !== '' ? $note : null,
            ':by' => $createdBy > 0 ? $createdBy : null,
            ':tid' => $tenantId,
        ));
        return array(true, 'ok', (int) $pdo->lastInsertId());
    } catch (Exception $e) {
        // بدون عمود tenant_id
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
        } catch (Exception $e2) {
            return array(false, $e2->getMessage(), 0);
        }
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
        $tenantSql = '';
        if (function_exists('current_tenant_id')) {
            $tenantSql = ' AND tenant_id = ' . (int) current_tenant_id();
        }
        $sql = 'SELECT id, username, display_name, role, is_active, linked_agent_id, created_at, tenant_id
                FROM admin_users WHERE role = "accountant"' . $tenantSql;
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
        'price_required' => $isEn ? 'Set card/package prices for this agent first' : 'لازم تسعر الباقات/الكروت لهذا الوكيل أولاً',
        'same_agent' => $isEn ? 'Source and destination must differ' : 'المصدر والوجهة لازم يختلفون',
        'insufficient_stock' => $isEn ? 'Insufficient stock at source agent' : 'المخزون غير كافٍ عند الوكيل المصدر',
        'stock_update_failed' => $isEn ? 'Could not update stock' : 'تعذر تحديث المخزون',
        'agent_required' => $isEn ? 'Agent required' : 'الوكيل مطلوب',
        'amount_invalid' => $isEn ? 'Amount must be greater than zero' : 'المبلغ لازم أكبر من صفر',
        'no_balance' => $isEn ? 'No remaining balance' : 'ماكو رصيد متبقٍ',
    );
    if (isset($map[$code])) {
        return $map[$code];
    }
    return $code;
}

function ensure_agent_card_prices_table($pdo)
{
    if (function_exists('ensure_tenants_schema')) {
        try {
            ensure_tenants_schema($pdo);
        } catch (Exception $e) {
        }
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS agent_card_prices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
                agent_user_id INT UNSIGNED NOT NULL,
                profile_id INT UNSIGNED NOT NULL DEFAULT 0,
                profile_name VARCHAR(120) NOT NULL DEFAULT "",
                wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                agent_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_agent_price (agent_user_id, profile_id, profile_name(60)),
                KEY idx_price_tenant (tenant_id),
                KEY idx_price_agent (agent_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Exception $e) {
    }
}

function agent_card_price_get($pdo, $agentUserId, $profileId, $profileName)
{
    ensure_agent_card_prices_table($pdo);
    $agentUserId = (int) $agentUserId;
    list($profileId, $profileName) = card_stock_row_key($profileId, $profileName);
    if ($agentUserId <= 0) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT * FROM agent_card_prices
         WHERE agent_user_id = :a AND profile_id = :p AND profile_name = :n LIMIT 1'
    );
    $st->execute(array(':a' => $agentUserId, ':p' => $profileId, ':n' => $profileName));
    $row = $st->fetch();
    return $row ? $row : null;
}

function agent_card_prices_list($pdo, $agentUserId)
{
    ensure_agent_card_prices_table($pdo);
    $agentUserId = (int) $agentUserId;
    if ($agentUserId <= 0) {
        return array();
    }
    $st = $pdo->prepare(
        'SELECT * FROM agent_card_prices WHERE agent_user_id = :a ORDER BY profile_name ASC, profile_id ASC'
    );
    $st->execute(array(':a' => $agentUserId));
    return $st->fetchAll();
}

function agent_card_price_save($pdo, $agentUserId, $profileId, $profileName, $wholesale, $agentPrice)
{
    ensure_agent_card_prices_table($pdo);
    $agentUserId = (int) $agentUserId;
    list($profileId, $profileName) = card_stock_row_key($profileId, $profileName);
    if ($agentUserId <= 0) {
        return false;
    }
    $tenantId = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    try {
        $stT = $pdo->prepare('SELECT tenant_id FROM admin_users WHERE id = :id LIMIT 1');
        $stT->execute(array(':id' => $agentUserId));
        $t = (int) $stT->fetchColumn();
        if ($t > 0) {
            $tenantId = $t;
        }
    } catch (Exception $e) {
    }
    $pdo->prepare(
        'INSERT INTO agent_card_prices
            (tenant_id, agent_user_id, profile_id, profile_name, wholesale_price, agent_price, updated_at)
         VALUES (:tid, :a, :p, :n, :w, :ap, NOW())
         ON DUPLICATE KEY UPDATE
            wholesale_price = VALUES(wholesale_price),
            agent_price = VALUES(agent_price),
            updated_at = NOW()'
    )->execute(array(
        ':tid' => $tenantId,
        ':a' => $agentUserId,
        ':p' => $profileId,
        ':n' => $profileName,
        ':w' => (float) $wholesale,
        ':ap' => (float) $agentPrice,
    ));
    return true;
}

function agent_card_price_delete($pdo, $priceId, $agentUserId)
{
    ensure_agent_card_prices_table($pdo);
    $pdo->prepare('DELETE FROM agent_card_prices WHERE id = :id AND agent_user_id = :a')
        ->execute(array(':id' => (int) $priceId, ':a' => (int) $agentUserId));
    return true;
}

/** سجل تحويلات مفصّل للمحاسب */
function card_agent_transfers_ledger($pdo, $agentUserId, $limit = 100)
{
    ensure_card_accounting_tables($pdo);
    $agentUserId = (int) $agentUserId;
    $limit = max(1, min(500, (int) $limit));
    if ($agentUserId <= 0) {
        return array();
    }
    $st = $pdo->prepare(
        'SELECT t.*, fa.display_name AS from_name
         FROM agent_card_transfers t
         LEFT JOIN admin_users fa ON fa.id = t.from_agent_id
         WHERE t.to_agent_id = :a
         ORDER BY t.created_at DESC, t.id DESC
         LIMIT ' . $limit
    );
    $st->execute(array(':a' => $agentUserId));
    return $st->fetchAll();
}

/**
 * تعطيل ساس الوكيل (مدير + يوزرات تحته) — بدون حذف ديون/كاش محلي
 * @return array(bool, string message)
 */
function disable_agent_sas($pdo, $config, $agentUserId, $actorUserId = 0)
{
    $agentUserId = (int) $agentUserId;
    if ($agentUserId <= 0) {
        return array(false, 'وكيل غير محدد');
    }
    if (!function_exists('sas_write_user') || !function_exists('sas_is_ready') || !sas_is_ready($config)) {
        return array(false, 'الساس غير جاهز');
    }
    try {
        $st = $pdo->prepare(
            'SELECT id, display_name, sas_manager_id, tenant_id FROM admin_users
             WHERE id = :id AND role = "agent" LIMIT 1'
        );
        $st->execute(array(':id' => $agentUserId));
        $agent = $st->fetch();
    } catch (Exception $e) {
        return array(false, 'تعذر قراءة الوكيل');
    }
    if (!$agent) {
        return array(false, 'الوكيل غير موجود');
    }
    $mid = isset($agent['sas_manager_id']) ? (int) $agent['sas_manager_id'] : 0;
    if ($mid <= 0) {
        return array(false, 'الوكيل غير مربوط بمدير ساس');
    }

    $okN = 0;
    $failN = 0;
    $usernames = array();
    try {
        $tid = isset($agent['tenant_id']) ? (int) $agent['tenant_id'] : (function_exists('current_tenant_id') ? current_tenant_id() : 1);
        $q = $pdo->prepare(
            'SELECT username FROM sas_users_cache
             WHERE parent_id = :m AND enabled = 1' . ($tid > 0 ? ' AND tenant_id = ' . (int) $tid : '') . '
             LIMIT 500'
        );
        $q->execute(array(':m' => $mid));
        $usernames = $q->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $usernames = array();
    }

    foreach ($usernames as $u) {
        $u = trim((string) $u);
        if ($u === '') {
            continue;
        }
        list($okDis, $msgDis) = sas_write_user($pdo, $config, 'sas_enable', $u, array('enabled' => '0'));
        if ($okDis) {
            $okN++;
        } else {
            $failN++;
        }
        usleep(80000);
    }

    if (function_exists('activity_log')) {
        try {
            activity_log(
                $pdo,
                0,
                'admin_user',
                $agentUserId,
                'agent_sas_disable',
                'تعطيل ساس وكيل ' . $agent['display_name'] . ' — نجح ' . $okN . ' / فشل ' . $failN,
                'actor=' . (int) $actorUserId
            );
        } catch (Exception $e) {
        }
    }

    $msg = 'تم تعطيل ' . $okN . ' يوزر على الساس';
    if ($failN > 0) {
        $msg .= ' (فشل ' . $failN . ')';
    }
    if ($okN === 0 && $failN === 0) {
        return array(false, 'ماكو يوزرات مفعّلة تحت هذا المدير بالكاش');
    }
    return array($okN > 0, $msg);
}

/**
 * تذكير واتساب بتسديد كروت الوكيل (جزئي/متأخر)
 * @return array(bool, string)
 */
function card_agent_payment_remind($pdo, $config, $agentUserId, $lang = 'ar')
{
    $agentUserId = (int) $agentUserId;
    $isEn = ($lang === 'en');
    if ($agentUserId <= 0) {
        return array(false, $isEn ? 'Agent required' : 'الوكيل مطلوب');
    }
    $remaining = card_agent_remaining_balance($pdo, $agentUserId);
    if ($remaining <= 0) {
        return array(false, $isEn ? 'No remaining balance' : 'ماكو رصيد متبقٍ');
    }
    $agent = function_exists('get_admin_user') ? get_admin_user($pdo, $agentUserId) : null;
    if (!$agent) {
        return array(false, $isEn ? 'Agent not found' : 'الوكيل غير موجود');
    }
    $phone = '';
    if (!empty($agent['phone'])) {
        $phone = trim((string) $agent['phone']);
    }
    if ($phone === '') {
        return array(false, $isEn ? 'Set agent phone first' : 'أضف رقم هاتف الوكيل أولاً');
    }
    $name = !empty($agent['display_name']) ? $agent['display_name'] : $agent['username'];
    $currency = isset($config['currency']) ? $config['currency'] : 'IQD';
    $amt = function_exists('money_format_iqd')
        ? money_format_iqd($remaining, $currency)
        : (string) (int) $remaining;
    $msg = $isEn
        ? ('Hello ' . $name . ', please settle remaining card balance: ' . $amt)
        : ('السلام عليكم ' . $name . ' يرجى تسديد متبقي كروت بقيمة ' . $amt);
    if (!function_exists('whatsapp_send')) {
        return array(false, $isEn ? 'WhatsApp not available' : 'واتساب غير متاح');
    }
    $result = whatsapp_send($config, $phone, $msg, 'card_debt_remind');
    $ok = is_array($result) ? !empty($result['success']) : (bool) $result;
    return array($ok, $ok
        ? ($isEn ? 'Reminder sent' : 'تم إرسال التذكير')
        : ($isEn ? 'Send failed' : 'فشل الإرسال'));
}

/**
 * تذكير جماعي لوكلاء لديهم متبقي كروت (للجدول الدوري)
 * @return array
 */
function run_card_debt_reminders($pdo, $config, $limit = 40)
{
    ensure_card_accounting_tables($pdo);
    $limit = max(1, min(200, (int) $limit));
    $out = array('checked' => 0, 'sent' => 0, 'failed' => 0);
    try {
        $rows = $pdo->query(
            'SELECT t.to_agent_id AS agent_id,
                    COALESCE(SUM(t.qty * t.agent_price),0) AS sale_total
             FROM agent_card_transfers t
             GROUP BY t.to_agent_id
             HAVING sale_total > 0
             LIMIT ' . $limit
        )->fetchAll();
    } catch (Exception $e) {
        return $out;
    }
    $lang = isset($GLOBALS['lang']) ? $GLOBALS['lang'] : 'ar';
    foreach ($rows as $r) {
        $aid = (int) $r['agent_id'];
        if ($aid <= 0) {
            continue;
        }
        $out['checked']++;
        $remaining = card_agent_remaining_balance($pdo, $aid);
        if ($remaining <= 0) {
            continue;
        }
        list($ok) = card_agent_payment_remind($pdo, $config, $aid, $lang);
        if ($ok) {
            $out['sent']++;
        } else {
            $out['failed']++;
        }
        usleep(120000);
    }
    return $out;
}
