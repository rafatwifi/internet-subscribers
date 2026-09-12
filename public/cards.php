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
$canTransfer = user_can('cards') || user_can('card_accounting');
$sasReady = function_exists('sas_is_ready') && sas_is_ready($config);
$agents = list_agent_users($pdo, true);

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
if (is_accountant_user()) {
    $linked = accountant_linked_agent_id();
    if ($linked > 0) {
        $scopeAgentId = $linked;
    }
}
$recentTransfers = list_recent_card_transfers($pdo, 25, $scopeAgentId);

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
        $out['groups'] = $groups;
        $out['from_cache'] = $fromCache;
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $isEn ? 'Failed to load cards' : 'تعذر جلب الكروت';
    }
    echo json_encode($out);
    exit;
}

$groups = array();
$err = '';
$fromCache = false;
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
.cards-page .chip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
.cards-page .chip {
  border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px; background: #fff;
}
.cards-page .chip.used { background: #fff7f7; border-color: #fecaca; }
.cards-page .pin { font-weight: 800; font-family: ui-monospace, monospace; letter-spacing: .02em; }
.cards-page .st { display: inline-block; margin-top: 6px; font-size: 11px; font-weight: 800; color: #166534; }
.cards-page .chip.used .st { color: #991b1b; }
.cards-page .by-inline { font-size: 11px; color: #64748b; font-weight: 700; margin-inline-start: 6px; }
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
        <form method="post">
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
                                    $hay = strtolower(trim((isset($c['pin']) ? $c['pin'] : '') . ' ' . (isset($c['used_by']) ? $c['used_by'] : '')));
                                    ?>
                                    <div class="chip" data-used="0" data-search="<?php echo e($hay); ?>">
                                        <div class="pin-row"><span class="pin"><?php echo e(isset($c['pin']) ? $c['pin'] : ''); ?></span></div>
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
                                    <div class="chip-grid">
                                        <?php foreach ($usedCards as $c):
                                            $usedBy = !empty($c['used_by']) ? (string) $c['used_by'] : '';
                                            $usedAt = !empty($c['used_at']) ? (string) $c['used_at'] : '';
                                            $hay = strtolower(trim((isset($c['pin']) ? $c['pin'] : '') . ' ' . $usedBy . ' ' . $usedAt));
                                            ?>
                                            <div class="chip used" data-used="1" data-search="<?php echo e($hay); ?>">
                                                <div class="pin-row">
                                                    <span class="pin"><?php echo e(isset($c['pin']) ? $c['pin'] : ''); ?></span>
                                                    <span class="by-inline"><?php echo e($usedBy !== '' ? $usedBy : '?'); ?></span>
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
            html += '<div class="chip" data-used="0" data-search="' + esc(String(c.pin || '').toLowerCase()) + '"><div class="pin-row"><span class="pin">' + esc(c.pin || '') + '</span></div><span class="st">' + (isEn ? 'Available' : 'شاغر') + '</span></div>';
          });
          html += '</div>';
        } else {
          html += '<div class="empty-cat">' + (isEn ? 'No free cards' : 'ماكو كروت شاغرة') + '</div>';
        }
        if (used.length) {
          html += '<div class="used-fold" data-used-fold><button type="button" class="used-fold-btn" data-toggle-used><span>' + (isEn ? 'Used cards' : 'الكروت المستخدمة') + ' (' + used.length + ')</span><span class="used-fold-chevron">›</span></button><div class="used-fold-body"><div class="chip-grid">';
          used.forEach(function (c) {
            html += '<div class="chip used" data-used="1" data-search="' + esc(((c.pin || '') + ' ' + (c.used_by || '')).toLowerCase()) + '"><div class="pin-row"><span class="pin">' + esc(c.pin || '') + '</span><span class="by-inline">' + esc(c.used_by || '?') + '</span></div>';
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
    });
  }
})();
</script>
<?php render_footer(); ?>
