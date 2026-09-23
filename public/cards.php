<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
if (!user_can('cards') && !user_can('card_accounting')) {
    require_perm('cards');
}
ensure_card_accounting_tables($pdo);

$isEn = ($lang === 'en');
$me = current_admin();
$meId = $me ? (int) $me['id'] : 0;
$canTransfer = (user_can('cards') || user_can('card_accounting')) && !(function_exists('is_agent_user') && is_agent_user());
$sasReady = function_exists('sas_is_ready') && sas_is_ready($config);
$agents = list_agent_users($pdo, true);
$tidCards = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
$agents = array_values(array_filter($agents, function ($a) use ($tidCards) {
    $at = isset($a['tenant_id']) ? (int) $a['tenant_id'] : 1;
    return $at === $tidCards;
}));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canTransfer) {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('cards.php');
    }
    $action = post('action');
    if ($action === 'transfer') {
        $fromAgentId = (int) post('from_agent_id', '0');
        $toAgentId = (int) post('to_agent_id', '0');
        $profileId = (int) post('profile_id', '0');
        $profileName = trim((string) post('profile_name', ''));
        $qty = (int) post('qty', '0');
        $wholesale = (float) post('wholesale_price', '0');
        $agentPrice = (float) post('agent_price', '0');
        $note = trim((string) post('note', ''));

        if (is_accountant_user()) {
            $linked = accountant_linked_agent_id();
            if ($linked > 0) {
                if ($toAgentId <= 0) {
                    $toAgentId = $linked;
                }
            }
        }

        // أسعار من كتالوج الوكيل إن تُركت 0
        if (($wholesale <= 0 || $agentPrice <= 0) && function_exists('agent_card_price_get') && $toAgentId > 0) {
            $pr = agent_card_price_get($pdo, $toAgentId, $profileId, $profileName);
            if ($pr) {
                if ($wholesale <= 0) {
                    $wholesale = (float) $pr['wholesale_price'];
                }
                if ($agentPrice <= 0) {
                    $agentPrice = (float) $pr['agent_price'];
                }
            }
        }

        list($ok, $code) = transfer_cards(
            $pdo,
            $fromAgentId,
            $toAgentId,
            $profileId,
            $profileName,
            $qty,
            $wholesale,
            $agentPrice,
            $note,
            $meId
        );
        if ($ok) {
            flash('success', $isEn ? 'Transfer recorded' : 'تم تسجيل التحويل');
        } else {
            flash('error', card_transfer_error_message($code, $lang));
        }
        redirect('cards.php#card-transfer');
    }
}

$scopeAgentId = null;
if (function_exists('is_agent_user') && is_agent_user()) {
    $scopeAgentId = $meId;
} elseif (is_accountant_user()) {
    $linked = accountant_linked_agent_id();
    if ($linked > 0) {
        $scopeAgentId = $linked;
    }
}
$recentTransfers = list_recent_card_transfers($pdo, 25, $scopeAgentId);
$agentStockPanel = null;
if ($scopeAgentId > 0 && function_exists('card_agent_dashboard')) {
    $agentStockPanel = card_agent_dashboard($pdo, $scopeAgentId);
} elseif ($scopeAgentId > 0 && function_exists('card_agent_stock_summary')) {
    $agentStockPanel = array('stock' => card_agent_stock_summary($pdo, $scopeAgentId));
}

function cards_page_fetch_inventory($config, $force = false)
{
    $groups = array();
    if (!$force && function_exists('sas_cards_inventory_load_persisted')) {
        $cached = sas_cards_inventory_load_persisted(300);
        if ($cached && !empty($cached['groups'])) {
            return array($cached['groups'], true);
        }
    }
    if (!function_exists('sas_page_connector')) {
        return array($groups, false);
    }
    $api = sas_page_connector($config);
    if (!$api) {
        return array($groups, false);
    }
    if (method_exists($api, 'setTimeout')) {
        $api->setTimeout(35);
    }
    if (method_exists($api, 'listCardsInventory')) {
        $groups = $api->listCardsInventory(14);
    } elseif (function_exists('sas_unused_cards_grouped')) {
        $raw = sas_unused_cards_grouped($api);
        foreach ($raw as $g) {
            $groups[] = array(
                'name' => isset($g['name']) ? $g['name'] : '',
                'profile_id' => isset($g['profile_id']) ? (int) $g['profile_id'] : 0,
                'total' => isset($g['count']) ? (int) $g['count'] : 0,
                'used' => 0,
                'unused' => isset($g['count']) ? (int) $g['count'] : 0,
                'cards' => array(),
            );
        }
    }
    if (!is_array($groups)) {
        $groups = array();
    }
    if (function_exists('sas_cards_inventory_save_persisted')) {
        sas_cards_inventory_save_persisted($groups);
    }
    if (function_exists('sas_dash_groups_from_inventory') && function_exists('sas_store_dash_card_groups')) {
        sas_store_dash_card_groups(sas_dash_groups_from_inventory($groups), 'inventory');
    }
    // حدّث كاش نافذة التفعيل من نفس الجرد
    if (function_exists('sas_unused_pins_from_inventory_cache')) {
        $pins = sas_unused_pins_from_inventory_cache();
        if (is_array($pins)) {
            $_SESSION['sas_unused_ui_v6'] = $pins;
            $_SESSION['sas_unused_ui_v6_at'] = time();
        }
    }
    return array($groups, false);
}

/**
 * اربط used_by بصفحة المشترك المحلي (#activations) أو sas_user كاحتياط.
 */
function cards_enrich_used_by_links($pdo, $groups)
{
    if (!is_array($groups) || !$groups) {
        return $groups;
    }
    $names = array();
    foreach ($groups as $g) {
        if (empty($g['cards']) || !is_array($g['cards'])) {
            continue;
        }
        foreach ($g['cards'] as $c) {
            if (empty($c['used']) || empty($c['used_by'])) {
                continue;
            }
            $u = trim((string) $c['used_by']);
            if ($u !== '') {
                $names[strtolower($u)] = $u;
            }
        }
    }
    if (!$names) {
        return $groups;
    }
    $map = array(); // lower => local_id
    $list = array_values($names);
    // sas_users_cache.local_subscriber_id
    try {
        $ph = array();
        $bind = array();
        $i = 0;
        foreach ($list as $u) {
            $k = ':u' . $i;
            $ph[] = $k;
            $bind[$k] = $u;
            $i++;
        }
        $sql = 'SELECT username, local_subscriber_id FROM sas_users_cache
                WHERE username IN (' . implode(',', $ph) . ') AND tenant_id = ' . (int) (function_exists('current_tenant_id') ? current_tenant_id() : 1);
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        while ($row = $st->fetch()) {
            $lid = !empty($row['local_subscriber_id']) ? (int) $row['local_subscriber_id'] : 0;
            if ($lid > 0) {
                $map[strtolower((string) $row['username'])] = $lid;
            }
        }
    } catch (Exception $e) {
    }
    // subscribers.sas_username
    $missing = array();
    foreach ($list as $u) {
        if (!isset($map[strtolower($u)])) {
            $missing[] = $u;
        }
    }
    if ($missing) {
        try {
            $ph = array();
            $bind = array();
            $i = 0;
            foreach ($missing as $u) {
                $k = ':s' . $i;
                $ph[] = $k;
                $bind[$k] = $u;
                $i++;
            }
            $sql = 'SELECT id, sas_username FROM subscribers
                    WHERE sas_username IN (' . implode(',', $ph) . ')
                      AND tenant_id = ' . (int) (function_exists('current_tenant_id') ? current_tenant_id() : 1);
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            while ($row = $st->fetch()) {
                $map[strtolower((string) $row['sas_username'])] = (int) $row['id'];
            }
        } catch (Exception $e) {
        }
    }
    foreach ($groups as &$g) {
        if (empty($g['cards']) || !is_array($g['cards'])) {
            continue;
        }
        foreach ($g['cards'] as &$c) {
            if (empty($c['used']) || empty($c['used_by'])) {
                continue;
            }
            $u = trim((string) $c['used_by']);
            $key = strtolower($u);
            if (isset($map[$key]) && $map[$key] > 0) {
                $c['local_id'] = (int) $map[$key];
                $c['user_href'] = 'subscriber.php?id=' . (int) $map[$key] . '#activations';
            } elseif (function_exists('sas_user_url')) {
                $c['local_id'] = 0;
                $c['user_href'] = sas_user_url($u);
            } else {
                $c['local_id'] = 0;
                $c['user_href'] = 'sas.php?q=' . rawurlencode($u);
            }
        }
        unset($c);
    }
    unset($g);
    return $groups;
}

/**
 * صفّ الكروت المستخدمة: فقط يوزرات ضمن نطاق الشركة/الوكيل الحالي
 */
function cards_filter_groups_scope($pdo, $groups)
{
    if (!is_array($groups) || !$groups) {
        return $groups;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $allowed = array();
    try {
        $sql = 'SELECT username FROM sas_users_cache WHERE tenant_id = :t';
        $params = array(':t' => $tid);
        if (function_exists('is_agent_user') && is_agent_user()) {
            $mid = function_exists('current_admin_sas_manager_id') ? (int) current_admin_sas_manager_id() : 0;
            if ($mid <= 0) {
                foreach ($groups as &$g0) {
                    if (empty($g0['cards']) || !is_array($g0['cards'])) {
                        continue;
                    }
                    $g0['cards'] = array_values(array_filter($g0['cards'], function ($c) {
                        return empty($c['used']);
                    }));
                    $g0['used'] = 0;
                    $g0['unused'] = count($g0['cards']);
                    $g0['total'] = $g0['unused'];
                }
                unset($g0);
                return $groups;
            }
            $sql .= ' AND parent_id = :p';
            $params[':p'] = $mid;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch()) {
            $allowed[strtolower(trim((string) $r['username']))] = true;
        }
    } catch (Exception $e) {
        // فشل الفلترة = لا تُظهر كروت مستخدمة أجنبية
        foreach ($groups as &$g0) {
            if (empty($g0['cards']) || !is_array($g0['cards'])) {
                continue;
            }
            $g0['cards'] = array_values(array_filter($g0['cards'], function ($c) {
                return empty($c['used']);
            }));
            $g0['used'] = 0;
            $g0['unused'] = count($g0['cards']);
            $g0['total'] = $g0['unused'];
        }
        unset($g0);
        return $groups;
    }
    foreach ($groups as &$g) {
        if (empty($g['cards']) || !is_array($g['cards'])) {
            continue;
        }
        $keep = array();
        $usedN = 0;
        $unusedN = 0;
        foreach ($g['cards'] as $c) {
            if (empty($c['used'])) {
                $keep[] = $c;
                $unusedN++;
                continue;
            }
            $by = isset($c['used_by']) ? strtolower(trim((string) $c['used_by'])) : '';
            if ($by !== '' && isset($allowed[$by])) {
                $keep[] = $c;
                $usedN++;
            }
        }
        $g['cards'] = $keep;
        $g['used'] = $usedN;
        $g['unused'] = $unusedN;
        $g['total'] = $usedN + $unusedN;
    }
    unset($g);
    return $groups;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'inventory') {
    header('Content-Type: application/json; charset=utf-8');
    $force = (isset($_GET['refresh']) && $_GET['refresh'] === '1');
    $out = array('ok' => true, 'groups' => array(), 'from_cache' => false, 'error' => '');
    if (!$sasReady) {
        $out['ok'] = false;
        $out['error'] = $isEn ? 'Enable SAS in settings first' : 'فعّل ربط SAS من الإعدادات أولاً';
        echo json_encode($out);
        exit;
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(90);
    }
    try {
        list($groups, $fromCache) = cards_page_fetch_inventory($config, $force);
        $out['groups'] = cards_filter_groups_scope($pdo, cards_enrich_used_by_links($pdo, $groups));
        $out['from_cache'] = $fromCache;
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $isEn ? 'Failed to load cards' : 'تعذر جلب الكروت';
    }
    echo json_encode($out);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'agent_price') {
    header('Content-Type: application/json; charset=utf-8');
    $aid = (int) (isset($_GET['agent']) ? $_GET['agent'] : 0);
    $pid = (int) (isset($_GET['profile_id']) ? $_GET['profile_id'] : 0);
    $pname = isset($_GET['profile_name']) ? trim((string) $_GET['profile_name']) : '';
    $out = array('ok' => false, 'wholesale_price' => 0, 'agent_price' => 0);
    if ($aid > 0 && function_exists('agent_card_price_get')) {
        $pr = agent_card_price_get($pdo, $aid, $pid, $pname);
        if ($pr) {
            $out['ok'] = true;
            $out['wholesale_price'] = (float) $pr['wholesale_price'];
            $out['agent_price'] = (float) $pr['agent_price'];
        }
    }
    echo json_encode($out);
    exit;
}

$groups = array();
$err = '';
$fromCache = false;
// الوكيل: مخزونه فقط — بدون مخزن الساس العام
if (function_exists('is_agent_user') && is_agent_user()) {
    $sasReady = false;
}
if ($sasReady) {
    // عرض فوري من كاش السيرفر — بدون انتظار SAS
    if (function_exists('sas_cards_inventory_load_persisted')) {
        $cached = sas_cards_inventory_load_persisted(0); // حتى لو قديم، اعرضه فوراً
        if ($cached && !empty($cached['groups'])) {
            $groups = $cached['groups'];
            $fromCache = true;
        }
    }
    if (!$groups && function_exists('sas_dash_cards_load_persisted')) {
        $dash = sas_dash_cards_load_persisted();
        if ($dash && !empty($dash['groups'])) {
            foreach ($dash['groups'] as $g) {
                $n = isset($g['count']) ? (int) $g['count'] : 0;
                $groups[] = array(
                    'name' => isset($g['name']) ? $g['name'] : '',
                    'profile_id' => isset($g['profile_id']) ? (int) $g['profile_id'] : 0,
                    'total' => $n,
                    'used' => 0,
                    'unused' => $n,
                    'cards' => array(),
                );
            }
            $fromCache = true;
        }
    }
} else {
    $err = $isEn ? 'Enable SAS in settings first' : 'فعّل ربط SAS من الإعدادات أولاً';
}

$groups = cards_filter_groups_scope($pdo, cards_enrich_used_by_links($pdo, $groups));

$sumTotal = 0;
$sumUsed = 0;
$sumUnused = 0;
foreach ($groups as $g0) {
    $sumTotal += isset($g0['total']) ? (int) $g0['total'] : 0;
    $sumUsed += isset($g0['used']) ? (int) $g0['used'] : 0;
    $sumUnused += isset($g0['unused']) ? (int) $g0['unused'] : 0;
}

render_header($isEn ? 'Cards' : 'الكارتات', 'cards');
?>
<?php if ($agentStockPanel && !empty($agentStockPanel['stock']['rows'])): ?>
<div class="panel">
    <h2><?php echo e($isEn ? 'My card stock' : 'مخزون كروتي'); ?></h2>
    <div class="table-wrap">
        <table class="table-compact">
            <thead>
            <tr>
                <th><?php echo e($isEn ? 'Package' : 'الباقة'); ?></th>
                <th><?php echo e($isEn ? 'Qty' : 'الكمية'); ?></th>
                <th><?php echo e($isEn ? 'Wholesale' : 'الجملة'); ?></th>
                <th><?php echo e($isEn ? 'Agent price' : 'سعر الوكيل'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($agentStockPanel['stock']['rows'] as $sr): ?>
                <tr>
                    <td><?php echo e($sr['profile_name']); ?></td>
                    <td class="ltr"><?php echo (int) $sr['qty']; ?></td>
                    <td class="ltr"><?php echo e($sr['wholesale_price']); ?></td>
                    <td class="ltr"><?php echo e($sr['agent_price']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="meta"><?php echo e($isEn ? 'Total qty' : 'إجمالي الكمية'); ?>:
        <strong><?php echo (int) $agentStockPanel['stock']['total_qty']; ?></strong></p>
</div>
<?php elseif (function_exists('is_agent_user') && is_agent_user()): ?>
<div class="panel">
    <h2><?php echo e($isEn ? 'My card stock' : 'مخزون كروتي'); ?></h2>
    <p class="meta"><?php echo e($isEn ? 'No stock yet — ask admin to transfer cards.' : 'ماكو مخزون بعد — اطلب من الإدارة تحويل كروت.'); ?></p>
</div>
<?php endif; ?>
<style>
.cards-page .cards-summary {
  display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px;
}
.cards-page .sum-pill {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 12px; border-radius: 999px; font-size: 12px; font-weight: 800;
  background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;
}
.cards-page .sum-pill.ok { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
.cards-page .sum-pill.bad { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
.cards-page .cards-sync {
  font-size: 12px; font-weight: 700; color: #64748b; margin: 0 0 10px;
}
.cards-page .cards-sync.is-busy { color: #0f766e; }
.cards-page .cat-block {
  border: 1px solid #d8dee8;
  border-radius: 14px;
  background: #fff;
  margin-bottom: 10px;
  overflow: hidden;
}
.cards-page .cat-head {
  display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
  gap: 8px; padding: 12px 14px; background: #f4f7fb; cursor: pointer; user-select: none;
  width: 100%; border: 0; text-align: inherit; font: inherit; color: inherit;
}
.cards-page .cat-head h3 { margin: 0; font-size: 15px; display: flex; align-items: center; gap: 8px; }
.cards-page .cat-chevron { display: inline-block; transition: transform .15s ease; font-weight: 900; }
.cards-page .cat-block.is-open .cat-chevron { transform: rotate(90deg); }
.cards-page .cat-meta { display: flex; flex-wrap: wrap; gap: 6px; }
.cards-page .pill {
  display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 999px;
  font-size: 11px; font-weight: 800; background: #e2e8f0; color: #334155;
}
.cards-page .pill.ok { background: #dcfce7; color: #166534; }
.cards-page .pill.bad { background: #fee2e2; color: #991b1b; }
.cards-page .cat-body { display: none; padding: 12px 14px 14px; border-top: 1px solid #e8edf4; }
.cards-page .cat-block.is-open .cat-body { display: block; }
.cards-page .chip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(148px, 1fr)); gap: 10px; }
.cards-page .chip-grid-used { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
.cards-page .chip {
  border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; background: #fff;
  min-width: 0; overflow: hidden; box-sizing: border-box;
  user-select: text; -webkit-user-select: text;
}
.cards-page .chip.free {
  display: flex; flex-direction: column; gap: 6px;
  background: #f8fffb; border-color: #bbf7d0;
}
.cards-page .by-line a, .cards-page a.by-link {
  color: #1d4ed8; text-decoration: none; font-weight: 800;
  word-break: break-all; overflow-wrap: anywhere;
}
.cards-page .by-line a:hover, .cards-page a.by-link:hover {
  text-decoration: underline; color: #1e40af;
}
.cards-page .chip-top {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;
  min-width: 0;
}
.cards-page .chip-main { min-width: 0; flex: 1; }
.cards-page .pin-row {
  display: flex; flex-direction: column; align-items: flex-start; gap: 4px;
  min-width: 0; width: 100%;
}
.cards-page .pin {
  font-weight: 800; font-family: ui-monospace, Consolas, monospace; letter-spacing: .02em;
  font-size: 13px; color: #0f172a;
  word-break: break-all; overflow-wrap: anywhere; max-width: 100%;
  user-select: text; -webkit-user-select: text;
}
.cards-page .by-line {
  display: block; font-size: 12px; color: #475569; font-weight: 700;
  word-break: break-all; overflow-wrap: anywhere; max-width: 100%;
  line-height: 1.35;
  user-select: text; -webkit-user-select: text;
}
.cards-page .st {
  display: block; margin-top: 2px; font-size: 11px; font-weight: 800; color: #166534;
  word-break: break-word;
}
.cards-page .chip.used .st { color: #b91c1c; margin-top: 0; }
.cards-page .chip-copy {
  flex: 0 0 auto; border: 1px solid #e2e8f0; background: #fff; color: #334155;
  border-radius: 8px; padding: 4px 8px; font: inherit; font-size: 11px; font-weight: 800;
  cursor: pointer; line-height: 1.2; white-space: nowrap;
}
.cards-page .chip-copy:hover { background: #f1f5f9; }
.cards-page .chip-copy.is-ok { background: #dcfce7; border-color: #86efac; color: #166534; }
.cards-page .empty-cat { color: #64748b; font-weight: 700; padding: 8px 0; }
.cards-page .cards-search {
  width: 100%; max-width: 420px; margin: 0 0 10px; padding: 10px 12px;
  border: 1px solid #d8dee8; border-radius: 10px; font: inherit;
}
.cards-page .filter-row { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 12px; }
.cards-page .filter-row button {
  border: 1px solid #d8dee8; background: #fff; border-radius: 999px;
  padding: 6px 10px; font: inherit; font-weight: 700; cursor: pointer;
}
.cards-page .filter-row button.is-on { background: #0f766e; color: #fff; border-color: #0f766e; }
.cards-page .used-fold { margin-top: 12px; border-top: 1px dashed #e2e8f0; padding-top: 10px; }
.cards-page .used-fold-btn {
  width: 100%; display: flex; justify-content: space-between; align-items: center;
  border: 0; background: transparent; font: inherit; font-weight: 800; cursor: pointer; color: #991b1b;
}
.cards-page .used-fold-body { display: none; margin-top: 10px; }
.cards-page .used-fold.is-open .used-fold-body { display: block; }
.cards-page .used-fold.is-open .used-fold-chevron { transform: rotate(90deg); display: inline-block; }
.cards-page .xfer-panel {
  border: 1px solid #d8dee8; border-radius: 14px; background: #fff;
  padding: 16px; margin: 0 0 18px;
}
.cards-page .xfer-panel h2 { margin: 0 0 12px; font-size: 16px; }
.cards-page .xfer-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px;
}
.cards-page .xfer-grid label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 4px; color: #475569; }
.cards-page .xfer-grid input, .cards-page .xfer-grid select {
  width: 100%; padding: 8px 10px; border: 1px solid #d8dee8; border-radius: 8px; font: inherit;
}
.cards-page .xfer-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 12px; }
.cards-page .xfer-table th, .cards-page .xfer-table td {
  border-bottom: 1px solid #e8edf4; padding: 8px 6px; text-align: inherit;
}
.cards-page .xfer-table th { font-size: 12px; color: #64748b; }
</style>

<div class="cards-page">
    <?php if ($canTransfer): ?>
    <div class="xfer-panel" id="card-transfer">
        <h2><?php echo e($isEn ? 'Card transfer' : 'تحويل كروت'); ?></h2>
        <?php if (user_can('users') || user_can('cards')): ?>
            <p class="meta" style="margin:0 0 10px">
                <a href="agent_prices.php"><?php echo e($isEn ? 'Manage agent card prices' : 'إدارة تسعير كروت الوكيل'); ?></a>
                — <?php echo e($isEn ? 'prices auto-fill when you pick agent + package' : 'الأسعار تتعبّى تلقائياً عند اختيار الوكيل والباقة'); ?>
            </p>
        <?php endif; ?>
        <form method="post" id="cardXferForm">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="transfer">
            <div class="xfer-grid">
                <div>
                    <label><?php echo e($isEn ? 'From agent' : 'من وكيل'); ?></label>
                    <select name="from_agent_id">
                        <option value="0"><?php echo e($isEn ? '— warehouse / none —' : '— مخزن / بدون —'); ?></option>
                        <?php foreach ($agents as $ag): ?>
                            <option value="<?php echo (int) $ag['id']; ?>"><?php echo e($ag['display_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'To agent' : 'إلى وكيل'); ?><?php echo is_accountant_user() && $scopeAgentId ? ' *' : ''; ?></label>
                    <select name="to_agent_id"<?php echo is_accountant_user() && $scopeAgentId ? ' required' : ''; ?>>
                        <?php if (!is_accountant_user() || !$scopeAgentId): ?>
                            <option value="0"><?php echo e($isEn ? '— select —' : '— اختر —'); ?></option>
                        <?php endif; ?>
                        <?php foreach ($agents as $ag):
                            $aid = (int) $ag['id'];
                            if (is_accountant_user() && $scopeAgentId && $aid !== $scopeAgentId) {
                                continue;
                            }
                            ?>
                            <option value="<?php echo $aid; ?>"<?php echo ($scopeAgentId === $aid) ? ' selected' : ''; ?>>
                                <?php echo e($ag['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Package / category' : 'الفئة / الباقة'); ?></label>
                    <input name="profile_name" list="cardProfileList" required placeholder="<?php echo e($isEn ? 'Package name' : 'اسم الفئة'); ?>">
                    <datalist id="cardProfileList">
                        <?php foreach ($groups as $g0):
                            $gn = isset($g0['name']) ? (string) $g0['name'] : '';
                            if ($gn === '') { continue; }
                            $gpid = isset($g0['profile_id']) ? (int) $g0['profile_id'] : 0;
                            ?>
                            <option value="<?php echo e($gn); ?>" data-pid="<?php echo $gpid; ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="profile_id" id="xferProfileId" value="0">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Quantity' : 'الكمية'); ?></label>
                    <input name="qty" type="number" min="1" step="1" required value="1">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Wholesale price' : 'سعر الجملة'); ?></label>
                    <input name="wholesale_price" type="number" min="0" step="0.01" value="0">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Agent price' : 'سعر الوكيل'); ?></label>
                    <input name="agent_price" type="number" min="0" step="0.01" value="0">
                </div>
                <div style="grid-column: 1 / -1">
                    <label><?php echo e($isEn ? 'Note (optional)' : 'ملاحظة (اختياري)'); ?></label>
                    <input name="note" maxlength="255" placeholder="<?php echo e($isEn ? 'Transfer note…' : 'ملاحظة التحويل…'); ?>">
                </div>
            </div>
            <div class="actions" style="margin-top:12px">
                <button class="btn" type="submit"><?php echo e($isEn ? 'Record transfer' : 'تسجيل التحويل'); ?></button>
            </div>
        </form>

        <?php if ($recentTransfers): ?>
        <h3 style="margin:18px 0 8px;font-size:14px"><?php echo e($isEn ? 'Recent transfers' : 'آخر التحويلات'); ?></h3>
        <div class="table-wrap">
            <table class="xfer-table">
                <thead>
                <tr>
                    <th><?php echo e($isEn ? 'Date' : 'التاريخ'); ?></th>
                    <th><?php echo e($isEn ? 'From' : 'من'); ?></th>
                    <th><?php echo e($isEn ? 'To' : 'إلى'); ?></th>
                    <th><?php echo e($isEn ? 'Package' : 'الفئة'); ?></th>
                    <th><?php echo e($isEn ? 'Qty' : 'كم'); ?></th>
                    <th><?php echo e($isEn ? 'Profit' : 'ربح'); ?></th>
                    <th><?php echo e($isEn ? 'Note' : 'ملاحظة'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recentTransfers as $tr):
                    $fromLbl = $tr['from_name'] ? $tr['from_name'] : ($isEn ? 'Warehouse' : 'مخزن');
                    $profit = card_transfer_profit($tr['wholesale_price'], $tr['agent_price'], $tr['qty']);
                    ?>
                    <tr>
                        <td><?php echo e(isset($tr['created_at']) ? $tr['created_at'] : ''); ?></td>
                        <td><?php echo e($fromLbl); ?></td>
                        <td><?php echo e(isset($tr['to_name']) ? $tr['to_name'] : ''); ?></td>
                        <td><?php echo e(isset($tr['profile_name']) ? $tr['profile_name'] : ''); ?></td>
                        <td><?php echo (int) $tr['qty']; ?></td>
                        <td><?php echo e(money_format_iqd($profit, $config['currency'])); ?></td>
                        <td><?php echo e(isset($tr['note']) ? $tr['note'] : ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <p class="cards-sync<?php echo $fromCache ? '' : ' is-busy'; ?>" id="cardsSync">
        <?php
        if ($err) {
            echo e($err);
        } elseif ($fromCache) {
            echo e($isEn ? 'Showing saved cards — syncing quietly…' : 'عرض الكروت المحفوظة — مزامنة بهدوء…');
        } else {
            echo e($isEn ? 'Loading cards…' : 'جاري تحميل الكروت…');
        }
        ?>
    </p>
    <div id="cardsRoot">
    <?php if ($err && !$groups): ?>
        <p style="color:#dd4b39;font-weight:700"><?php echo e($err); ?></p>
    <?php elseif (!$groups): ?>
        <p style="font-weight:700;color:#64748b" id="cardsEmpty"><?php echo e($isEn ? 'Loading…' : 'جاري التحميل…'); ?></p>
    <?php else: ?>
        <div class="cards-summary" id="cardsSummary">
            <span class="sum-pill"><?php echo e($isEn ? 'Categories' : 'فئات'); ?>: <span data-sum="cats"><?php echo count($groups); ?></span></span>
            <span class="sum-pill"><?php echo e($isEn ? 'Total' : 'الكل'); ?>: <span data-sum="total"><?php echo (int) $sumTotal; ?></span></span>
            <span class="sum-pill ok"><?php echo e($isEn ? 'Unused' : 'شاغر'); ?>: <span data-sum="unused"><?php echo (int) $sumUnused; ?></span></span>
            <span class="sum-pill bad"><?php echo e($isEn ? 'Used' : 'مستخدم'); ?>: <span data-sum="used"><?php echo (int) $sumUsed; ?></span></span>
        </div>
        <input type="search" id="cardsSearch" class="cards-search" placeholder="<?php echo e($isEn ? 'Search pin / username / name…' : 'بحث برقم الكارت أو اليوزر أو الاسم…'); ?>">
        <div class="filter-row" id="cardsFilter">
            <button type="button" class="is-on" data-f="all"><?php echo e($isEn ? 'All packages' : 'كل الفئات'); ?></button>
            <button type="button" data-f="free"><?php echo e($isEn ? 'With free cards' : 'فيها شواغر'); ?></button>
            <button type="button" data-f="expand"><?php echo e($isEn ? 'Expand all' : 'فتح الكل'); ?></button>
            <button type="button" data-f="collapse"><?php echo e($isEn ? 'Collapse' : 'طي الكل'); ?></button>
            <button type="button" data-f="refresh"><?php echo e($isEn ? 'Refresh' : 'تحديث'); ?></button>
        </div>
        <div id="cardsList">
        <?php foreach ($groups as $gi => $g): ?>
            <?php
            $gName = isset($g['name']) ? (string) $g['name'] : '';
            $total = isset($g['total']) ? (int) $g['total'] : 0;
            $used = isset($g['used']) ? (int) $g['used'] : 0;
            $unused = isset($g['unused']) ? (int) $g['unused'] : 0;
            $cards = (isset($g['cards']) && is_array($g['cards'])) ? $g['cards'] : array();
            $freeCards = array();
            $usedCards = array();
            foreach ($cards as $c) {
                if (!empty($c['used'])) {
                    $usedCards[] = $c;
                } else {
                    $freeCards[] = $c;
                }
            }
            $openFirst = ($gi === 0 && $unused > 0);
            ?>
            <div class="cat-block<?php echo $openFirst ? ' is-open' : ''; ?>" data-cat data-has-free="<?php echo $unused > 0 ? '1' : '0'; ?>">
                <button type="button" class="cat-head" data-toggle-cat>
                    <h3>
                        <span class="cat-chevron">›</span>
                        <?php echo e($gName !== '' ? $gName : '—'); ?>
                    </h3>
                    <div class="cat-meta">
                        <span class="pill"><?php echo (int) $total; ?></span>
                        <span class="pill ok"><?php echo e($isEn ? 'Free' : 'شاغر'); ?> <?php echo (int) $unused; ?></span>
                        <span class="pill bad"><?php echo e($isEn ? 'Used' : 'مستخدم'); ?> <?php echo (int) $used; ?></span>
                    </div>
                </button>
                <div class="cat-body">
                    <?php if (!$freeCards && !$usedCards): ?>
                        <div class="empty-cat"><?php echo e($isEn ? 'Details load on sync…' : 'التفاصيل تكتمل مع المزامنة…'); ?></div>
                    <?php else: ?>
                        <?php if ($freeCards): ?>
                            <div class="chip-grid" data-free-grid>
                                <?php foreach ($freeCards as $c):
                                    $pin = isset($c['pin']) ? (string) $c['pin'] : '';
                                    $hay = strtolower(trim($pin));
                                    ?>
                                    <div class="chip free" data-used="0" data-search="<?php echo e($hay); ?>" data-copy="<?php echo e($pin); ?>">
                                        <div class="chip-top">
                                            <div class="chip-main">
                                                <div class="pin-row"><span class="pin"><?php echo e($pin); ?></span></div>
                                            </div>
                                            <button type="button" class="chip-copy" data-copy-btn title="<?php echo e($isEn ? 'Copy' : 'نسخ'); ?>"><?php echo e($isEn ? 'Copy' : 'نسخ'); ?></button>
                                        </div>
                                        <span class="st free"><?php echo e($isEn ? 'Available' : 'شاغر'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-cat"><?php echo e($isEn ? 'No free cards' : 'ماكو كروت شاغرة'); ?></div>
                        <?php endif; ?>
                        <?php if ($usedCards): ?>
                            <div class="used-fold" data-used-fold>
                                <button type="button" class="used-fold-btn" data-toggle-used>
                                    <span><?php echo e($isEn ? 'Used cards' : 'الكروت المستخدمة'); ?> (<?php echo count($usedCards); ?>)</span>
                                    <span class="used-fold-chevron">›</span>
                                </button>
                                <div class="used-fold-body">
                                    <div class="chip-grid chip-grid-used">
                                        <?php foreach ($usedCards as $c):
                                            $pin = isset($c['pin']) ? (string) $c['pin'] : '';
                                            $usedBy = !empty($c['used_by']) ? (string) $c['used_by'] : '';
                                            $usedAt = !empty($c['used_at']) ? (string) $c['used_at'] : '';
                                            $hay = strtolower(trim($pin . ' ' . $usedBy . ' ' . $usedAt));
                                            $copyText = trim($pin . ($usedBy !== '' ? (' ' . $usedBy) : ''));
                                            $userHref = !empty($c['user_href']) ? (string) $c['user_href'] : '';
                                            ?>
                                            <div class="chip used" data-used="1" data-search="<?php echo e($hay); ?>" data-copy="<?php echo e($copyText); ?>">
                                                <div class="chip-top">
                                                    <div class="chip-main">
                                                        <div class="pin-row">
                                                            <span class="pin"><?php echo e($pin); ?></span>
                                                            <?php if ($usedBy !== '' && $userHref !== ''): ?>
                                                                <span class="by-line"><a class="by-link" href="<?php echo e($userHref); ?>" title="<?php echo e($isEn ? 'Open subscriber activations' : 'فتح تفعيلات المشترك'); ?>"><?php echo e($usedBy); ?></a></span>
                                                            <?php else: ?>
                                                                <span class="by-line"><?php echo e($usedBy !== '' ? $usedBy : '—'); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <button type="button" class="chip-copy" data-copy-btn title="<?php echo e($isEn ? 'Copy' : 'نسخ'); ?>"><?php echo e($isEn ? 'Copy' : 'نسخ'); ?></button>
                                                </div>
                                                <?php if ($usedAt !== ''): ?>
                                                    <div class="st"><?php echo e($usedAt); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </div>
</div>

<script>
(function () {
  var isEn = <?php echo $isEn ? 'true' : 'false'; ?>;
  var syncEl = document.getElementById('cardsSync');
  var root = document.getElementById('cardsRoot');

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderGroups(groups) {
    if (!root) return;
    groups = groups || [];
    var sumTotal = 0, sumUsed = 0, sumUnused = 0;
    groups.forEach(function (g) {
      sumTotal += g.total || 0;
      sumUsed += g.used || 0;
      sumUnused += g.unused || 0;
    });
    if (!groups.length) {
      root.innerHTML = '<p style="font-weight:700;color:#64748b">' + (isEn ? 'No cards found' : 'ماكو كروت') + '</p>';
      return;
    }
    var html = '';
    html += '<div class="cards-summary" id="cardsSummary">';
    html += '<span class="sum-pill">' + (isEn ? 'Categories' : 'فئات') + ': ' + groups.length + '</span>';
    html += '<span class="sum-pill">' + (isEn ? 'Total' : 'الكل') + ': ' + sumTotal + '</span>';
    html += '<span class="sum-pill ok">' + (isEn ? 'Unused' : 'شاغر') + ': ' + sumUnused + '</span>';
    html += '<span class="sum-pill bad">' + (isEn ? 'Used' : 'مستخدم') + ': ' + sumUsed + '</span>';
    html += '</div>';
    html += '<input type="search" id="cardsSearch" class="cards-search" placeholder="' + (isEn ? 'Search…' : 'بحث…') + '">';
    html += '<div class="filter-row" id="cardsFilter">';
    html += '<button type="button" class="is-on" data-f="all">' + (isEn ? 'All packages' : 'كل الفئات') + '</button>';
    html += '<button type="button" data-f="free">' + (isEn ? 'With free cards' : 'فيها شواغر') + '</button>';
    html += '<button type="button" data-f="expand">' + (isEn ? 'Expand all' : 'فتح الكل') + '</button>';
    html += '<button type="button" data-f="collapse">' + (isEn ? 'Collapse' : 'طي الكل') + '</button>';
    html += '<button type="button" data-f="refresh">' + (isEn ? 'Refresh' : 'تحديث') + '</button>';
    html += '</div><div id="cardsList">';
    groups.forEach(function (g, gi) {
      var free = [], used = [];
      (g.cards || []).forEach(function (c) { (c.used ? used : free).push(c); });
      var open = (gi === 0 && (g.unused || 0) > 0) ? ' is-open' : '';
      html += '<div class="cat-block' + open + '" data-cat data-has-free="' + ((g.unused || 0) > 0 ? '1' : '0') + '">';
      html += '<button type="button" class="cat-head" data-toggle-cat><h3><span class="cat-chevron">›</span> ' + esc(g.name || '—') + '</h3>';
      html += '<div class="cat-meta"><span class="pill">' + (g.total || 0) + '</span>';
      html += '<span class="pill ok">' + (isEn ? 'Free' : 'شاغر') + ' ' + (g.unused || 0) + '</span>';
      html += '<span class="pill bad">' + (isEn ? 'Used' : 'مستخدم') + ' ' + (g.used || 0) + '</span></div></button>';
      html += '<div class="cat-body">';
      if (!free.length && !used.length) {
        html += '<div class="empty-cat">' + (isEn ? 'No cards in this package' : 'لا توجد كروت لهذه الباقة') + '</div>';
      } else {
        if (free.length) {
          html += '<div class="chip-grid">';
          free.forEach(function (c) {
            var pin = c.pin || '';
            html += '<div class="chip free" data-used="0" data-search="' + esc(String(pin).toLowerCase()) + '" data-copy="' + esc(pin) + '">';
            html += '<div class="chip-top"><div class="chip-main"><div class="pin-row"><span class="pin">' + esc(pin) + '</span></div></div>';
            html += '<button type="button" class="chip-copy" data-copy-btn>' + (isEn ? 'Copy' : 'نسخ') + '</button></div>';
            html += '<span class="st free">' + (isEn ? 'Available' : 'شاغر') + '</span></div>';
          });
          html += '</div>';
        } else {
          html += '<div class="empty-cat">' + (isEn ? 'No free cards' : 'ماكو كروت شاغرة') + '</div>';
        }
        if (used.length) {
          html += '<div class="used-fold" data-used-fold><button type="button" class="used-fold-btn" data-toggle-used><span>' + (isEn ? 'Used cards' : 'الكروت المستخدمة') + ' (' + used.length + ')</span><span class="used-fold-chevron">›</span></button><div class="used-fold-body"><div class="chip-grid chip-grid-used">';
          used.forEach(function (c) {
            var pin = c.pin || '';
            var by = c.used_by || '';
            var href = c.user_href || '';
            var copyTxt = String(pin) + (by ? (' ' + by) : '');
            html += '<div class="chip used" data-used="1" data-search="' + esc((pin + ' ' + by + ' ' + (c.used_at || '')).toLowerCase()) + '" data-copy="' + esc(copyTxt) + '">';
            html += '<div class="chip-top"><div class="chip-main"><div class="pin-row"><span class="pin">' + esc(pin) + '</span>';
            if (by && href) {
              html += '<span class="by-line"><a class="by-link" href="' + esc(href) + '">' + esc(by) + '</a></span>';
            } else {
              html += '<span class="by-line">' + esc(by || '—') + '</span>';
            }
            html += '</div></div>';
            html += '<button type="button" class="chip-copy" data-copy-btn>' + (isEn ? 'Copy' : 'نسخ') + '</button></div>';
            if (c.used_at) html += '<div class="st">' + esc(c.used_at) + '</div>';
            html += '</div>';
          });
          html += '</div></div></div>';
        }
      }
      html += '</div></div>';
    });
    html += '</div>';
    root.innerHTML = html;
    bindUi();
  }

  function bindUi() {
    var list = document.getElementById('cardsList') || root;
    if (!list) return;
    list.querySelectorAll('[data-toggle-cat]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var block = btn.closest('[data-cat]');
        if (block) block.classList.toggle('is-open');
      });
    });
    list.querySelectorAll('[data-toggle-used]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var fold = btn.closest('[data-used-fold]');
        if (fold) fold.classList.toggle('is-open');
      });
    });
    function copyText(txt, btn) {
      txt = String(txt || '');
      if (!txt) return;
      var done = function () {
        if (!btn) return;
        var old = btn.textContent;
        btn.textContent = isEn ? 'Copied' : 'تم';
        btn.classList.add('is-ok');
        setTimeout(function () {
          btn.textContent = old;
          btn.classList.remove('is-ok');
        }, 1200);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(done).catch(function () {
          try {
            var ta = document.createElement('textarea');
            ta.value = txt;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            done();
          } catch (e) {}
        });
      } else {
        try {
          var ta2 = document.createElement('textarea');
          ta2.value = txt;
          document.body.appendChild(ta2);
          ta2.select();
          document.execCommand('copy');
          document.body.removeChild(ta2);
          done();
        } catch (e2) {}
      }
    }
    list.querySelectorAll('[data-copy-btn]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var chip = btn.closest('.chip');
        copyText(chip ? chip.getAttribute('data-copy') : '', btn);
      });
    });
    list.querySelectorAll('.chip .pin').forEach(function (el) {
      el.addEventListener('click', function (e) {
        if (e.target && e.target.closest && e.target.closest('a')) return;
        e.stopPropagation();
        try {
          var range = document.createRange();
          range.selectNodeContents(el);
          var sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(range);
        } catch (err) {}
      });
    });
    var filter = document.getElementById('cardsFilter');
    if (filter) {
      filter.addEventListener('click', function (e) {
        var b = e.target.closest('button[data-f]');
        if (!b) return;
        var f = b.getAttribute('data-f');
        if (f === 'refresh') { sync(true); return; }
        if (f === 'expand') {
          list.querySelectorAll('[data-cat]').forEach(function (el) { el.classList.add('is-open'); });
          return;
        }
        if (f === 'collapse') {
          list.querySelectorAll('[data-cat]').forEach(function (el) { el.classList.remove('is-open'); });
          return;
        }
        filter.querySelectorAll('button[data-f="all"],button[data-f="free"]').forEach(function (x) { x.classList.remove('is-on'); });
        b.classList.add('is-on');
        list.querySelectorAll('[data-cat]').forEach(function (el) {
          if (f === 'free') el.style.display = el.getAttribute('data-has-free') === '1' ? '' : 'none';
          else el.style.display = '';
        });
      });
    }
    var search = document.getElementById('cardsSearch');
    if (search) {
      search.addEventListener('input', function () {
        var q = (search.value || '').toLowerCase().trim();
        list.querySelectorAll('.chip').forEach(function (chip) {
          var hay = chip.getAttribute('data-search') || '';
          chip.style.display = (!q || hay.indexOf(q) >= 0) ? '' : 'none';
        });
      });
    }
  }

  function sync(force) {
    if (syncEl) {
      syncEl.classList.add('is-busy');
      syncEl.textContent = isEn ? 'Syncing with SAS…' : 'جاري المزامنة مع الساس…';
    }
    fetch('cards.php?ajax=inventory' + (force ? '&refresh=1' : ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) {
          if (syncEl) {
            syncEl.classList.remove('is-busy');
            syncEl.textContent = (d && d.error) ? d.error : (isEn ? 'Sync failed' : 'فشلت المزامنة');
          }
          return;
        }
        renderGroups(d.groups || []);
        if (syncEl) {
          syncEl.classList.remove('is-busy');
          syncEl.textContent = isEn ? 'Synced' : 'تمت المزامنة';
        }
      })
      .catch(function () {
        if (syncEl) {
          syncEl.classList.remove('is-busy');
          syncEl.textContent = isEn ? 'Sync failed' : 'فشلت المزامنة';
        }
      });
  }

  bindUi();
  // مزامنة خلفية بعد الرسم الفوري من الكاش
  setTimeout(function () { sync(false); }, 200);

  var profileInput = document.querySelector('input[name="profile_name"]');
  var profileIdInput = document.getElementById('xferProfileId');
  var profileList = document.getElementById('cardProfileList');
  var toAgentSel = document.querySelector('select[name="to_agent_id"]');
  var wholesaleInput = document.querySelector('input[name="wholesale_price"]');
  var agentPriceInput = document.querySelector('input[name="agent_price"]');

  function fillAgentPrices() {
    if (!toAgentSel || !wholesaleInput || !agentPriceInput) return;
    var aid = parseInt(toAgentSel.value || '0', 10) || 0;
    var pname = profileInput ? (profileInput.value || '') : '';
    var pid = profileIdInput ? (profileIdInput.value || '0') : '0';
    if (aid <= 0 || pname === '') return;
    var url = 'cards.php?ajax=agent_price&agent=' + encodeURIComponent(aid)
      + '&profile_id=' + encodeURIComponent(pid)
      + '&profile_name=' + encodeURIComponent(pname);
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        if (parseFloat(wholesaleInput.value || '0') <= 0) {
          wholesaleInput.value = d.wholesale_price;
        }
        if (parseFloat(agentPriceInput.value || '0') <= 0) {
          agentPriceInput.value = d.agent_price;
        }
      }).catch(function () {});
  }

  if (profileInput && profileIdInput && profileList) {
    profileInput.addEventListener('change', function () {
      var val = profileInput.value || '';
      profileIdInput.value = '0';
      var opts = profileList.querySelectorAll('option');
      for (var i = 0; i < opts.length; i++) {
        if (opts[i].value === val) {
          profileIdInput.value = opts[i].getAttribute('data-pid') || '0';
          break;
        }
      }
      fillAgentPrices();
    });
  }
  if (toAgentSel) {
    toAgentSel.addEventListener('change', function () {
      if (wholesaleInput) wholesaleInput.value = '0';
      if (agentPriceInput) agentPriceInput.value = '0';
      fillAgentPrices();
    });
  }
})();
</script>
<?php render_footer(); ?>
