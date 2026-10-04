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
                return_of_id INT UNSIGNED NULL DEFAULT NULL,
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

        if (function_exists('tenants_ensure_column')) {
            tenants_ensure_column($pdo, 'agent_card_transfers', 'return_of_id', 'INT UNSIGNED NULL DEFAULT NULL');
            tenants_ensure_column($pdo, 'agent_card_transfers', 'tenant_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
            tenants_ensure_column($pdo, 'agent_card_transfers', 'sas_ranges', 'TEXT NULL');
        }

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

function card_agent_category_lines($bag, $priceRows)
{
    $byKey = array();
    $bySoft = array();
    if (is_array($priceRows)) {
        foreach ($priceRows as $pr) {
            if (!is_array($pr)) {
                continue;
            }
            $k = card_name_key(isset($pr['profile_name']) ? $pr['profile_name'] : '');
            if ($k === '') {
                continue;
            }
            $byKey[$k] = $pr;
            $soft = card_price_soft_key(isset($pr['profile_name']) ? $pr['profile_name'] : '');
            if ($soft !== '' && !isset($bySoft[$soft])) {
                $bySoft[$soft] = $pr;
            }
        }
    }
    $lines = array();
    if (!is_array($bag)) {
        return $lines;
    }
    foreach ($bag as $name => $qty) {
        $qty = (int) $qty;
        $name = trim((string) $name);
        if ($qty <= 0 || $name === '') {
            continue;
        }
        $k = card_name_key($name);
        $pr = ($k !== '' && isset($byKey[$k])) ? $byKey[$k] : null;
        if (!$pr) {
            $soft = card_price_soft_key($name);
            if ($soft !== '' && isset($bySoft[$soft])) {
                $pr = $bySoft[$soft];
            }
        }
        $pid = ($pr && isset($pr['profile_id'])) ? (int) $pr['profile_id'] : 0;
        $unit = 0.0;
        if ($pr) {
            $unit = isset($pr['agent_price']) ? (float) $pr['agent_price'] : 0.0;
            if ($unit <= 0 && isset($pr['wholesale_price'])) {
                $unit = (float) $pr['wholesale_price'];
            }
        }
        $lines[] = array(
            'name' => $name,
            'profile_id' => $pid,
            'qty' => $qty,
            'unit' => $unit,
            'amount' => $unit * $qty,
        );
    }
    return $lines;
}

function card_name_key($name)
{
    $s = strtolower(trim((string) $name));
    $s = str_replace(array('_', '–', '—', ' '), '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim((string) $s, '-');
}

function card_price_soft_key($name)
{
    $s = card_name_key($name);
    $s = preg_replace('/-?msl$/', '', $s);
    $s = str_replace('-', '', $s);
    return $s;
}

function card_stock_match($pdo, $agentUserId, $profileId, $profileName)
{
    $agentUserId = (int) $agentUserId;
    list($profileId, $profileName) = card_stock_row_key($profileId, $profileName);
    if ($agentUserId <= 0) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id, profile_id, profile_name, qty FROM agent_card_stock
         WHERE agent_user_id = :a AND qty > 0'
    );
    $st->execute(array(':a' => $agentUserId));
    $rows = $st->fetchAll();
    $want = card_name_key($profileName);
    $exact = null;
    $byName = null;
    $byPid = null;
    foreach ($rows as $r) {
        $rn = card_name_key($r['profile_name']);
        $rp = (int) $r['profile_id'];
        if ($rp === (int) $profileId && $rn === $want) {
            $exact = $r;
            break;
        }
        if ($want !== '' && $rn === $want) {
            if (!$byName || (int) $r['qty'] > (int) $byName['qty']) {
                $byName = $r;
            }
        }
        if ((int) $profileId > 0 && $rp === (int) $profileId) {
            if (!$byPid || (int) $r['qty'] > (int) $byPid['qty']) {
                $byPid = $r;
            }
        }
    }
    if ($exact) {
        return $exact;
    }
    if ($byName) {
        return $byName;
    }
    if ($byPid) {
        return $byPid;
    }
    return null;
}

/**
 * @return array(bool ok, string message, int transfer_id)
 */
/** نفس السجل، أو وكالة بوابتها تحت الحساب الحالي بشجرة الساس */
function card_transfer_party_allowed($pdo, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }
    if (function_exists('admin_user_same_tenant') && admin_user_same_tenant($pdo, $userId)) {
        return true;
    }
    if (!function_exists('get_admin_user') || !function_exists('portal_agency_reports_to_me')) {
        return false;
    }
    $row = get_admin_user($pdo, $userId);
    return $row && portal_agency_reports_to_me($pdo, $row);
}

function transfer_cards($pdo, $fromAgentId, $toAgentId, $profileId, $profileName, $qty, $wholesalePrice, $agentPrice, $note, $createdBy, $skipSourceDeduct = false, $sasRanges = null)
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
    $rangesJson = '';
    if (is_array($sasRanges)) {
        $rangesJson = json_encode(array_values($sasRanges));
    } elseif (is_string($sasRanges)) {
        $rangesJson = trim($sasRanges);
    }
    $sasBacked = ($rangesJson !== '' && $rangesJson !== '[]' && $rangesJson !== 'null');
    if (!$sasBacked && function_exists('agent_card_prices_list')) {
        $priced = agent_card_prices_list($pdo, $toAgentId);
        if (!$priced) {
            return array(false, 'price_required', 0);
        }
    }
    if ($fromAgentId > 0 && $fromAgentId === $toAgentId) {
        return array(false, 'same_agent', 0);
    }
    if (!card_transfer_party_allowed($pdo, $toAgentId)) {
        return array(false, 'to_agent_required', 0);
    }
    if ($fromAgentId > 0 && !card_transfer_party_allowed($pdo, $fromAgentId)) {
        return array(false, 'to_agent_required', 0);
    }

    try {
        if ($fromAgentId > 0 && !$skipSourceDeduct && !$sasBacked) {
            $src = card_stock_match($pdo, $fromAgentId, $profileId, $profileName);
            if (!$src || (int) $src['qty'] < $qty) {
                return array(false, 'insufficient_stock', 0);
            }
            $profileId = (int) $src['profile_id'];
            $profileName = (string) $src['profile_name'];
        }

        $pdo->beginTransaction();

        if ($fromAgentId > 0 && !$skipSourceDeduct) {
            $srcNow = card_stock_match($pdo, $fromAgentId, $profileId, $profileName);
            if ($srcNow && (int) $srcNow['qty'] >= $qty) {
                $profileId = (int) $srcNow['profile_id'];
                $profileName = (string) $srcNow['profile_name'];
                $ok = card_stock_adjust($pdo, $fromAgentId, $profileId, $profileName, -$qty, $wholesalePrice, $agentPrice);
                if (!$ok && !$sasBacked) {
                    $pdo->rollBack();
                    return array(false, 'insufficient_stock', 0);
                }
            } elseif (!$sasBacked) {
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

        $insParams = array(
            ':f' => $fromAgentId > 0 ? $fromAgentId : null,
            ':t' => $toAgentId,
            ':p' => $profileId,
            ':n' => $profileName,
            ':q' => $qty,
            ':w' => (float) $wholesalePrice,
            ':ap' => (float) $agentPrice,
            ':note' => $note !== '' ? $note : null,
            ':by' => $createdBy > 0 ? $createdBy : null,
        );
        try {
            $ins = $pdo->prepare(
                'INSERT INTO agent_card_transfers
                 (from_agent_id, to_agent_id, profile_id, profile_name, qty, wholesale_price, agent_price, note, created_by, tenant_id, sas_ranges)
                 VALUES (:f, :t, :p, :n, :q, :w, :ap, :note, :by, :tid, :sas)'
            );
            $insParams[':tid'] = $tenantId;
            $insParams[':sas'] = $sasBacked ? $rangesJson : null;
            $ins->execute($insParams);
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
    $qty = $row ? (int) $row['q'] : 0;
    $sale = $row ? (float) $row['sale'] : 0.0;
    $profit = $row ? (float) $row['profit'] : 0.0;
    $count = $row ? (int) $row['c'] : 0;
    try {
        $stBack = $pdo->prepare(
            'SELECT COALESCE(SUM(qty),0) AS q,
                    COALESCE(SUM(agent_price * qty),0) AS sale,
                    COALESCE(SUM((agent_price - wholesale_price) * qty),0) AS profit
             FROM agent_card_transfers
             WHERE from_agent_id = :a AND return_of_id IS NOT NULL'
        );
        $stBack->execute(array(':a' => $agentUserId));
        $back = $stBack->fetch();
        if ($back) {
            $qty -= (int) $back['q'];
            $sale -= (float) $back['sale'];
            $profit -= (float) $back['profit'];
        }
    } catch (Exception $e) {
    }
    return array(
        'count' => $count,
        'qty' => $qty,
        'sale_total' => $sale,
        'profit_total' => $profit,
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

function card_child_sas_manager_ids($pdo, $homeId)
{
    $homeId = (int) $homeId;
    $ids = array();
    try {
        $st = $pdo->query(
            'SELECT id, sas_manager_id FROM admin_users
             WHERE sas_manager_id IS NOT NULL AND sas_manager_id > 0'
        );
        foreach ($st->fetchAll() as $u) {
            if ((int) $u['id'] === $homeId) {
                continue;
            }
            $mid = (int) $u['sas_manager_id'];
            if ($mid > 0) {
                $ids[$mid] = $mid;
            }
        }
    } catch (Exception $e) {
    }
    return array_values($ids);
}

function card_user_sas_manager_id($pdo, $userId, $homeId, $api)
{
    $userId = (int) $userId;
    $homeId = (int) $homeId;
    if ($userId <= 0) {
        return 0;
    }
    if ($userId === $homeId) {
        if ($api && method_exists($api, 'loggedManagerId')) {
            return (int) $api->loggedManagerId();
        }
        return 0;
    }
    if (!function_exists('get_admin_user')) {
        return 0;
    }
    $row = get_admin_user($pdo, $userId);
    if (!$row || empty($row['sas_manager_id'])) {
        return 0;
    }
    return (int) $row['sas_manager_id'];
}

function card_sas_stock_forget()
{
    unset($_SESSION['card_sas_stock_map_v1'], $_SESSION['card_sas_stock_map_v1_at']);
    if (function_exists('sas_cards_inventory_forget')) {
        sas_cards_inventory_forget();
    }
}

function card_sas_stock_map($pdo, $config, $homeId)
{
    $homeId = (int) $homeId;
    $ck = 'card_sas_stock_map_v2';
    if (!empty($_SESSION[$ck]) && is_array($_SESSION[$ck]) && !empty($_SESSION[$ck . '_at'])
        && (time() - (int) $_SESSION[$ck . '_at']) < 20) {
        return $_SESSION[$ck];
    }
    $map = array();
    if (!function_exists('sas_make_connector') || !function_exists('sas_is_ready') || !sas_is_ready($config)) {
        return $map;
    }
    $api = sas_make_connector($config);
    if (!$api || !method_exists($api, 'listSeriesStock')) {
        return $map;
    }
    if (method_exists($api, 'setTimeout')) {
        $api->setTimeout(40);
    }
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    $rows = $api->listSeriesStock();
    if (!is_array($rows)) {
        return $map;
    }
    $byOwner = array();
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $owner = isset($r['owner']) ? (int) $r['owner'] : 0;
        $name = isset($r['name']) ? trim((string) $r['name']) : '';
        if ($name === '') {
            continue;
        }
        if (!isset($byOwner[$owner])) {
            $byOwner[$owner] = array();
        }
        if (!isset($byOwner[$owner][$name])) {
            $byOwner[$owner][$name] = 0;
        }
        $byOwner[$owner][$name] += isset($r['unused']) ? (int) $r['unused'] : 0;
    }
    $portalByManager = array();
    $pinlessDone = array();
    $homeTenant = 0;
    $sameTenant = array();
    try {
        $st = $pdo->prepare('SELECT tenant_id FROM admin_users WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $homeId));
        $homeTenant = (int) $st->fetchColumn();
        $st = $pdo->query(
            'SELECT id, sas_manager_id, tenant_id FROM admin_users
             WHERE sas_manager_id IS NOT NULL AND sas_manager_id > 0'
        );
        foreach ($st->fetchAll() as $u) {
            $uid = (int) $u['id'];
            $mid = (int) $u['sas_manager_id'];
            $rowTenant = isset($u['tenant_id']) ? (int) $u['tenant_id'] : 0;
            if ($homeTenant > 0 && $rowTenant === $homeTenant) {
                $sameTenant[$uid] = true;
            }
            if ($uid <= 0 || $uid === $homeId || $mid <= 0) {
                continue;
            }
            if (empty($byOwner[$mid]) && empty($pinlessDone[$mid]) && $homeTenant > 0 && isset($sameTenant[$uid])
                && method_exists($api, 'listPinlessOwnerSeries')) {
                $pinlessDone[$mid] = true;
                $extra = $api->listPinlessOwnerSeries($mid);
                if (is_array($extra)) {
                    foreach ($extra as $er) {
                        if (!is_array($er)) {
                            continue;
                        }
                        $nm = isset($er['name']) ? trim((string) $er['name']) : '';
                        $q = isset($er['unused']) ? (int) $er['unused'] : 0;
                        if ($nm === '' || $q <= 0) {
                            continue;
                        }
                        if (!isset($byOwner[$mid])) {
                            $byOwner[$mid] = array();
                        }
                        if (!isset($byOwner[$mid][$nm])) {
                            $byOwner[$mid][$nm] = 0;
                        }
                        $byOwner[$mid][$nm] += $q;
                    }
                }
            }
            $portalByManager[$mid] = $uid;
            $map[$uid] = isset($byOwner[$mid]) ? $byOwner[$mid] : array();
        }
    } catch (Exception $e) {
    }
    if ($homeId > 0) {
        $homeBag = array();
        foreach ($byOwner as $owner => $names) {
            if ($owner > 0 && isset($portalByManager[$owner])) {
                continue;
            }
            foreach ($names as $nm => $q) {
                if (!isset($homeBag[$nm])) {
                    $homeBag[$nm] = 0;
                }
                $homeBag[$nm] += (int) $q;
            }
        }
        $map[$homeId] = $homeBag;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION[$ck] = $map;
    $_SESSION[$ck . '_at'] = time();
    if (function_exists('app_session_close')) {
        app_session_close();
    }
    return $map;
}

function card_sas_move_error($move, $lang)
{
    $isEn = ($lang === 'en');
    $msg = (is_array($move) && isset($move['message'])) ? trim((string) $move['message']) : '';
    if (strpos($msg, 'short:') === 0) {
        $n = (int) substr($msg, 6);
        return $isEn ? ('SAS only has ' . $n . ' unused') : ('المتوفر بالساس ' . $n . ' فقط');
    }
    if ($msg === 'login') {
        return $isEn ? 'Could not sign in to SAS' : 'تعذر الدخول إلى الساس';
    }
    if ($msg === 'not_first') {
        return $isEn ? 'The first card in the series is not free, so nothing was moved' : 'أول كارت بالسلسلة مو شاغر، ما اننقل شي حتى ما نمس كارت مستخدم';
    }
    if ($msg === 'pins') {
        return $isEn ? 'Could not read the series cards, so nothing was moved' : 'ما قدرنا نقرأ كروت السلسلة، ما اننقل شي';
    }
    if ($msg === 'undone') {
        return $isEn ? 'SAS moved a different count, the cards were put back, and the transfer was not saved' : 'الساس نقل عدد غير المطلوب، رجعنا الكروت وما انحفظ التحويل';
    }
    if ($msg === 'stuck') {
        return $isEn ? 'SAS moved extra cards and they could not be put back. Do not press return. Check the agent series in SAS' : 'الساس نقل كروت زيادة وما لقيناها حتى نرجعها. لا تدوس استرجاع، شوف سلسلة الوكيل بالساس';
    }
    if ($msg !== '' && $msg !== 'sas' && $msg !== 'qty' && $msg !== 'range') {
        return $isEn ? ('SAS refused the move: ' . $msg) : ('الساس رفض النقل: ' . $msg);
    }
    return $isEn ? 'SAS did not move the cards' : 'الساس ما نقل الكروت';
}

function card_transfer_return($pdo, $transferId, $homeId, $createdBy, $config = null)
{
    ensure_card_accounting_tables($pdo);
    $transferId = (int) $transferId;
    $homeId = (int) $homeId;
    $createdBy = (int) $createdBy;
    if ($transferId <= 0 || $homeId <= 0) {
        return array(false, 'not_returnable');
    }
    $st = $pdo->prepare('SELECT * FROM agent_card_transfers WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => $transferId));
    $row = $st->fetch();
    if (!$row) {
        return array(false, 'not_returnable');
    }
    if (!empty($row['return_of_id'])) {
        return array(false, 'already_returned');
    }
    $chk = $pdo->prepare('SELECT id FROM agent_card_transfers WHERE return_of_id = :id LIMIT 1');
    $chk->execute(array(':id' => $transferId));
    if ($chk->fetch()) {
        return array(false, 'already_returned');
    }
    $toId = (int) $row['to_agent_id'];
    $fromId = isset($row['from_agent_id']) ? (int) $row['from_agent_id'] : 0;
    if ($toId <= 0 || $toId === $homeId) {
        return array(false, 'not_returnable');
    }
    if ($fromId > 0 && $fromId !== $homeId) {
        return array(false, 'not_returnable');
    }
    if (!card_transfer_party_allowed($pdo, $toId) || !card_transfer_party_allowed($pdo, $homeId)) {
        return array(false, 'not_returnable');
    }
    $qty = (int) $row['qty'];
    if ($qty <= 0) {
        return array(false, 'qty_invalid');
    }
    $pid = isset($row['profile_id']) ? (int) $row['profile_id'] : 0;
    $pname = isset($row['profile_name']) ? (string) $row['profile_name'] : '';
    $rangesRaw = isset($row['sas_ranges']) ? trim((string) $row['sas_ranges']) : '';
    $ranges = ($rangesRaw !== '') ? json_decode($rangesRaw, true) : null;
    if (!is_array($ranges) || !$ranges) {
        return array(false, 'sas_not_recorded');
    }
    if (!$config || !function_exists('sas_make_connector') || !function_exists('sas_is_ready') || !sas_is_ready($config)) {
        return array(false, 'sas_not_ready');
    }
    $api = sas_make_connector($config);
    if (!$api || !method_exists($api, 'restoreCardRanges')) {
        return array(false, 'sas_not_ready');
    }
    if (method_exists($api, 'setTimeout')) {
        $api->setTimeout(50);
    }
    $homeMid = card_user_sas_manager_id($pdo, $homeId, $homeId, $api);
    $childMid = card_user_sas_manager_id($pdo, $toId, $homeId, $api);
    if ($homeMid <= 0) {
        $rangeOwner = 0;
        foreach ($ranges as $rOwner) {
            if (is_array($rOwner) && !empty($rOwner['owner'])) {
                $rangeOwner = (int) $rOwner['owner'];
                break;
            }
        }
        if ($rangeOwner <= 0) {
            return array(false, 'sas_home_missing');
        }
    }
    if ($childMid <= 0) {
        return array(false, 'sas_manager_missing');
    }
    if (!$api->restoreCardRanges($ranges, $homeMid)) {
        $err = method_exists($api, 'getLastError') ? trim((string) $api->getLastError()) : '';
        if ($err === 'prefix') {
            return array(false, 'sas_prefix_lost');
        }
        return array(false, 'sas_move_failed');
    }
    $tail = method_exists($api, 'getLastError') ? trim((string) $api->getLastError()) : '';
    if ($tail === 'already_home') {
        return array(false, 'already_home');
    }
    $src = card_stock_match($pdo, $toId, $pid, $pname);
    if ($src) {
        $pid = (int) $src['profile_id'];
        $pname = (string) $src['profile_name'];
    }
    $w = (float) $row['wholesale_price'];
    $ap = (float) $row['agent_price'];
    $tenantId = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    try {
        $pdo->beginTransaction();
        if ($src && (int) $src['qty'] >= $qty) {
            if (!card_stock_adjust($pdo, $toId, $pid, $pname, -$qty, $w, $ap)) {
                $pdo->rollBack();
                $api->restoreCardRanges($ranges, $childMid, $childMid);
                return array(false, 'stock_update_failed');
            }
        }
        card_stock_adjust($pdo, $homeId, $pid, $pname, $qty, $w, $ap);
        try {
            $ins = $pdo->prepare(
                'INSERT INTO agent_card_transfers
                 (from_agent_id, to_agent_id, profile_id, profile_name, qty, wholesale_price, agent_price, note, created_by, tenant_id, return_of_id)
                 VALUES (:f, :t, :p, :n, :q, :w, :ap, :note, :by, :tid, :ret)'
            );
            $ins->execute(array(
                ':f' => $toId,
                ':t' => $homeId,
                ':p' => $pid,
                ':n' => $pname,
                ':q' => $qty,
                ':w' => $w,
                ':ap' => $ap,
                ':note' => 'استرجاع',
                ':by' => $createdBy > 0 ? $createdBy : null,
                ':tid' => $tenantId,
                ':ret' => $transferId,
            ));
        } catch (Exception $e) {
            $ins = $pdo->prepare(
                'INSERT INTO agent_card_transfers
                 (from_agent_id, to_agent_id, profile_id, profile_name, qty, wholesale_price, agent_price, note, created_by, return_of_id)
                 VALUES (:f, :t, :p, :n, :q, :w, :ap, :note, :by, :ret)'
            );
            $ins->execute(array(
                ':f' => $toId,
                ':t' => $homeId,
                ':p' => $pid,
                ':n' => $pname,
                ':q' => $qty,
                ':w' => $w,
                ':ap' => $ap,
                ':note' => 'استرجاع',
                ':by' => $createdBy > 0 ? $createdBy : null,
                ':ret' => $transferId,
            ));
        }
        $pdo->commit();
        card_sas_stock_forget();
        return array(true, 'ok', $pname, $qty, $toId);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $api->restoreCardRanges($ranges, $childMid, $childMid);
        return array(false, 'stock_update_failed');
    }
}

function card_transfer_recover_prefix($pdo, $transferId, $homeId, $config = null)
{
    ensure_card_accounting_tables($pdo);
    $transferId = (int) $transferId;
    $homeId = (int) $homeId;
    if ($transferId <= 0 || $homeId <= 0) {
        return array(false, 'not_returnable');
    }
    $st = $pdo->prepare('SELECT * FROM agent_card_transfers WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => $transferId));
    $row = $st->fetch();
    if (!$row || !empty($row['return_of_id'])) {
        return array(false, 'not_returnable');
    }
    $chk = $pdo->prepare('SELECT id FROM agent_card_transfers WHERE return_of_id = :id LIMIT 1');
    $chk->execute(array(':id' => $transferId));
    if (!$chk->fetch()) {
        return array(false, 'not_returnable');
    }
    $toId = (int) $row['to_agent_id'];
    $fromId = isset($row['from_agent_id']) ? (int) $row['from_agent_id'] : 0;
    if ($toId <= 0 || $toId === $homeId || ($fromId > 0 && $fromId !== $homeId)) {
        return array(false, 'not_returnable');
    }
    if (!card_transfer_party_allowed($pdo, $toId) || !card_transfer_party_allowed($pdo, $homeId)) {
        return array(false, 'not_returnable');
    }
    $rangesRaw = isset($row['sas_ranges']) ? trim((string) $row['sas_ranges']) : '';
    $ranges = ($rangesRaw !== '') ? json_decode($rangesRaw, true) : null;
    if (!is_array($ranges) || !$ranges) {
        return array(false, 'sas_not_recorded');
    }
    if (!$config || !function_exists('sas_make_connector') || !function_exists('sas_is_ready') || !sas_is_ready($config)) {
        return array(false, 'sas_not_ready');
    }
    $api = sas_make_connector($config);
    if (!$api || !method_exists($api, 'restoreCardRanges')) {
        return array(false, 'sas_not_ready');
    }
    if (method_exists($api, 'setTimeout')) {
        $api->setTimeout(50);
    }
    $homeMid = card_user_sas_manager_id($pdo, $homeId, $homeId, $api);
    if ($homeMid <= 0) {
        return array(false, 'sas_home_missing');
    }
    if (!$api->restoreCardRanges($ranges, $homeMid)) {
        $err = method_exists($api, 'getLastError') ? trim((string) $api->getLastError()) : '';
        if ($err === 'prefix') {
            return array(false, 'sas_prefix_lost');
        }
        return array(false, 'sas_move_failed');
    }
    card_sas_stock_forget();
    $tail = method_exists($api, 'getLastError') ? trim((string) $api->getLastError()) : '';
    if ($tail === 'already_home') {
        return array(false, 'already_home');
    }
    return array(true, 'ok');
}

function card_transfer_history_clear($pdo, $tenantId, $createdBy = null)
{
    ensure_card_accounting_tables($pdo);
    $tenantId = (int) $tenantId;
    if ($tenantId <= 0) {
        return 0;
    }
    $sql = 'DELETE FROM agent_card_transfers WHERE tenant_id = :tid';
    $params = array(':tid' => $tenantId);
    if ($createdBy !== null && (int) $createdBy > 0) {
        $sql .= ' AND created_by = :by';
        $params[':by'] = (int) $createdBy;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $st->rowCount();
}

function list_recent_card_transfers($pdo, $limit = 30, $agentUserId = null, $createdBy = null)
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
    $where = array();
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
    if ($tid > 0) {
        $where[] = 't.tenant_id = :tid';
        $params[':tid'] = $tid;
    }
    if ($agentUserId !== null && (int) $agentUserId > 0) {
        $where[] = '(t.to_agent_id = :a OR t.from_agent_id = :a2)';
        $params[':a'] = (int) $agentUserId;
        $params[':a2'] = (int) $agentUserId;
    }
    if ($createdBy !== null && (int) $createdBy > 0) {
        $where[] = 't.created_by = :by';
        $params[':by'] = (int) $createdBy;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY t.created_at DESC, t.id DESC LIMIT ' . $limit;
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Exception $e) {
        return array();
    }
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
        $sql = 'SELECT id, username, display_name, role, is_active, linked_agent_id, created_at, tenant_id, can_activate, can_transfer_cards, can_price_cards
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
        'already_returned' => $isEn ? 'These cards were already returned' : 'هذي الكروت مسترجعة من قبل',
        'not_returnable' => $isEn ? 'This line cannot be returned' : 'ما ينرجع هذا السطر',
        'sas_not_recorded' => $isEn ? 'This transfer never moved cards in SAS' : 'هذا التحويل ما اننقل بالساس، ما ينرجع',
        'sas_not_ready' => $isEn ? 'SAS is not ready' : 'الساس غير جاهز',
        'sas_home_missing' => $isEn ? 'Could not read this agency SAS account' : 'تعذر معرفة حساب الساس لهذه الوكالة',
        'sas_manager_missing' => $isEn ? 'This agent is not linked to a SAS manager' : 'الوكيل غير مربوط بمدير ساس',
        'sas_move_failed' => $isEn ? 'SAS did not move the cards' : 'الساس ما نقل الكروت',
        'sas_prefix_lost' => $isEn
            ? 'Return stopped. The first card of that series changed, so the remaining card was left untouched. Check the agent series in SAS'
            : 'الاسترجاع توقف. أول كارت بالسلسلة تبدل، فتركنا الكرت الباقي بمكانه. شوف سلسلة الوكيل بالساس',
        'already_home' => $isEn
            ? 'No missing cards were found on the agent. The remaining card was left as it is'
            : 'ما لقينا كروت ناقصة عند الوكيل. الكرت الباقي ما انلمس',
        'sas_same_owner' => $isEn ? 'Source and destination are the same SAS account' : 'المصدر والوجهة نفس حساب الساس',
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
        if (function_exists('tenants_ensure_column')) {
            tenants_ensure_column($pdo, 'agent_card_prices', 'retail_price', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
        }
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

function agent_card_price_save($pdo, $agentUserId, $profileId, $profileName, $wholesale, $agentPrice, $retailPrice = null)
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
    if ($retailPrice === null) {
        $oldRetail = agent_card_price_get($pdo, $agentUserId, $profileId, $profileName);
        $retailPrice = $oldRetail && isset($oldRetail['retail_price']) ? (float) $oldRetail['retail_price'] : (float) $agentPrice;
    }
    $pdo->prepare(
        'INSERT INTO agent_card_prices
            (tenant_id, agent_user_id, profile_id, profile_name, wholesale_price, agent_price, retail_price, updated_at)
         VALUES (:tid, :a, :p, :n, :w, :ap, :rp, NOW())
         ON DUPLICATE KEY UPDATE
            wholesale_price = VALUES(wholesale_price),
            agent_price = VALUES(agent_price),
            retail_price = VALUES(retail_price),
            updated_at = NOW()'
    )->execute(array(
        ':tid' => $tenantId,
        ':a' => $agentUserId,
        ':p' => $profileId,
        ':n' => $profileName,
        ':w' => (float) $wholesale,
        ':ap' => (float) $agentPrice,
        ':rp' => (float) $retailPrice,
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
    $waSession = function_exists('whatsapp_session_id') ? whatsapp_session_id() : '';
    if ($waSession === '' || $waSession === 'default') {
        $waSession = function_exists('whatsapp_agency_sender_session')
            ? whatsapp_agency_sender_session($pdo, $agent)
            : '';
    }
    if ($waSession === '' || $waSession === 'default') {
        return array(false, $isEn ? 'Link this agency WhatsApp first' : 'اربط واتساب هذه الوكالة أولاً');
    }
    $result = whatsapp_send($config, $phone, $msg, 'card_debt_remind', $waSession);
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
