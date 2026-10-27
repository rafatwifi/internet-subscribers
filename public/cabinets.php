<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

$isEn = (isset($lang) && $lang === 'en');
$tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 0;
$me = function_exists('current_admin') ? current_admin() : null;
$meId = $me ? (int) $me['id'] : 0;
if (!function_exists('tenant_is_ftth') || !tenant_is_ftth($pdo) || $tid <= 0 || $meId <= 0) {
    redirect('index.php');
}
if (function_exists('user_can') && !user_can('subscribers') && !user_can('dashboard')) {
    redirect('index.php');
}

ensure_cabinets_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('cabinets.php');
    }
    $act = (string) post('action');
    if ($act === 'investor_add') {
        $name = trim((string) post('name'));
        if ($name === '') {
            flash('error', $isEn ? 'Investor name is required' : 'اسم المستثمر مطلوب');
            redirect('cabinets.php#investors');
        }
        $pdo->prepare('INSERT INTO cabinet_investors (tenant_id, name, created_at) VALUES (:t, :n, NOW())')
            ->execute(array(':t' => $tid, ':n' => $name));
        flash('success', $isEn ? 'Investor added' : 'انضاف المستثمر');
        redirect('cabinets.php#investors');
    }
    if ($act === 'investor_del') {
        $invId = (int) post('investor_id', '0');
        $pdo->prepare('UPDATE cabinets SET investor_id = NULL WHERE tenant_id = :t AND investor_id = :i')
            ->execute(array(':t' => $tid, ':i' => $invId));
        $pdo->prepare('DELETE FROM cabinet_investors WHERE id = :i AND tenant_id = :t')
            ->execute(array(':i' => $invId, ':t' => $tid));
        flash('success', $isEn ? 'Investor removed' : 'انحذف المستثمر');
        redirect('cabinets.php#investors');
    }
    if ($act === 'assign') {
        $cabId = (int) post('cabinet_id', '0');
        $invId = (int) post('investor_id', '0');
        $loc = trim((string) post('location'));
        $tier = trim((string) post('tier_label'));
        $own = $pdo->prepare('SELECT id FROM cabinets WHERE id = :id AND tenant_id = :t LIMIT 1');
        $own->execute(array(':id' => $cabId, ':t' => $tid));
        if (!$own->fetch()) {
            flash('error', $isEn ? 'Cabinet was not found' : 'الكابينة مو موجودة');
            redirect('cabinets.php');
        }
        if ($invId > 0) {
            $ok = $pdo->prepare('SELECT id FROM cabinet_investors WHERE id = :i AND tenant_id = :t LIMIT 1');
            $ok->execute(array(':i' => $invId, ':t' => $tid));
            if (!$ok->fetch()) {
                $invId = 0;
            }
        }
        $pdo->prepare(
            'UPDATE cabinets SET investor_id = :i, location = :l, tier_label = :tier WHERE id = :id AND tenant_id = :t'
        )->execute(array(
            ':i' => $invId > 0 ? $invId : null,
            ':l' => $loc,
            ':tier' => $tier,
            ':id' => $cabId,
            ':t' => $tid,
        ));
        flash('success', $isEn ? 'Cabinet saved' : 'انحفظت الكابينة');
        redirect('cabinets.php');
    }
    if ($act === 'add') {
        $name = trim((string) post('name'));
        $loc = trim((string) post('location'));
        $tier = trim((string) post('tier_label'));
        $invId = (int) post('investor_id', '0');
        if ($name === '') {
            flash('error', $isEn ? 'Cabinet name is required' : 'اسم الكابينة مطلوب');
            redirect('cabinets.php#add');
        }
        if ($invId > 0) {
            $ok = $pdo->prepare('SELECT id FROM cabinet_investors WHERE id = :i AND tenant_id = :t LIMIT 1');
            $ok->execute(array(':i' => $invId, ':t' => $tid));
            if (!$ok->fetch()) {
                $invId = 0;
            }
        }
        $dup = $pdo->prepare('SELECT id FROM cabinets WHERE tenant_id = :t AND name = :n LIMIT 1');
        $dup->execute(array(':t' => $tid, ':n' => $name));
        if ($dup->fetch()) {
            flash('error', $isEn ? 'This cabinet already exists' : 'هالكابينة موجودة');
            redirect('cabinets.php');
        }
        $pdo->prepare(
            'INSERT INTO cabinets (tenant_id, name, location, tier_label, investor_user_id, investor_id, source_agent_id, auto_made, created_at, source_parent)
             VALUES (:t, :n, :l, :tier, NULL, :inv, NULL, 0, NOW(), NULL)'
        )->execute(array(
            ':t' => $tid,
            ':n' => $name,
            ':l' => $loc,
            ':tier' => $tier,
            ':inv' => $invId > 0 ? $invId : null,
        ));
        flash('success', $isEn ? 'Cabinet added' : 'انضافت الكابينة');
        redirect('cabinets.php');
    }
}

if (function_exists('cabinet_fill_created')) {
    try {
        cabinet_fill_created($pdo, $config, $tid);
    } catch (Exception $e) {
    }
}
cabinet_sync_from_parents($pdo, $tid);

$stats = cabinet_parent_stats($pdo, $tid);
$investors = cabinet_investors($pdo, $tid);
$invById = array();
foreach ($investors as $inv) {
    $invById[(int) $inv['id']] = (string) $inv['name'];
}
$cabSt = $pdo->prepare('SELECT * FROM cabinets WHERE tenant_id = :t ORDER BY auto_made DESC, name');
$cabSt->execute(array(':t' => $tid));
$cabs = $cabSt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($cabs)) {
    $cabs = array();
}

$cards = array();
$seenParents = array();
$sumMembers = 0;
$sumNew = 0;
foreach ($cabs as $cab) {
    $parent = isset($cab['source_parent']) ? trim((string) $cab['source_parent']) : '';
    $key = $parent !== '' ? $parent : ('manual-' . (int) $cab['id']);
    $members = 0;
    $news = 0;
    if ($parent !== '' && isset($stats[$parent])) {
        $members = (int) $stats[$parent]['members'];
        $news = (int) $stats[$parent]['news'];
        $seenParents[$parent] = 1;
    }
    $sumMembers += $members;
    $sumNew += $news;
    $cards[] = array(
        'id' => (int) $cab['id'],
        'name' => (string) $cab['name'],
        'location' => isset($cab['location']) ? (string) $cab['location'] : '',
        'tier' => isset($cab['tier_label']) ? (string) $cab['tier_label'] : '',
        'investor_id' => isset($cab['investor_id']) ? (int) $cab['investor_id'] : 0,
        'auto' => !empty($cab['auto_made']),
        'members' => $members,
        'news' => $news,
        'old' => max(0, $members - $news),
    );
}

$pendingCreated = 0;
$agesPending = false;
try {
    $pst = $pdo->prepare('SELECT COUNT(*) FROM sas_users_cache WHERE tenant_id = :t AND parent_name IS NOT NULL AND parent_name <> "" AND sas_created_at IS NULL');
    $pst->execute(array(':t' => $tid));
    $pendingCreated = (int) $pst->fetchColumn();
    $agesPending = $sumMembers > 0 && $pendingCreated > max(40, (int) floor($sumMembers * 0.2));
} catch (Exception $e) {
    $pendingCreated = 0;
}

$onlyNew = isset($_GET['new']) && (string) $_GET['new'] === '1';
$newRows = array();
if ($onlyNew) {
    $stN = $pdo->prepare(
        'SELECT username, parent_name, sas_created_at
         FROM sas_users_cache
         WHERE tenant_id = :t AND sas_created_at IS NOT NULL AND sas_created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND parent_name IS NOT NULL AND parent_name <> ""
         ORDER BY sas_created_at DESC
         LIMIT 400'
    );
    $stN->execute(array(':t' => $tid));
    $newRows = $stN->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($newRows)) {
        $newRows = array();
    }
}

$profiles = array();
if (function_exists('cabinet_ftth_profiles')) {
    try {
        $profiles = cabinet_ftth_profiles($config, $tid);
    } catch (Exception $e) {
        $profiles = array();
    }
}

$invLoad = array();
foreach ($cards as $card) {
    $iid = (int) $card['investor_id'];
    if ($iid <= 0) {
        continue;
    }
    if (!isset($invLoad[$iid])) {
        $invLoad[$iid] = array('cabinets' => 0, 'members' => 0);
    }
    $invLoad[$iid]['cabinets']++;
    $invLoad[$iid]['members'] += (int) $card['members'];
}

render_header($isEn ? 'Cabinets' : 'الكابينات', 'cabinets');
?>
<style>
.cab-wrap { display: flex; flex-direction: column; gap: 16px; }
.cab-hero {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}
.cab-stat {
    background: #fff;
    border: 1px solid #e6edf5;
    border-radius: 18px;
    padding: 14px 16px;
    box-shadow: 0 10px 24px rgba(15, 23, 42, .04);
}
.cab-stat b { display: block; font-size: 28px; line-height: 1.1; color: #0f172a; }
.cab-stat span { color: #64748b; font-size: 13px; font-weight: 700; }
.cab-lead { margin: 0; color: #475569; font-size: 14px; }
.cab-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.cab-tools a, .cab-tools button.btn {
    border-radius: 999px;
    min-height: 40px;
}
.cab-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.cab-card {
    background: linear-gradient(180deg, #f8fbff 0%, #fff 42%);
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 14px 14px 12px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, .05);
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.cab-date {
    display: inline-flex;
    align-self: flex-start;
    background: #e0f2fe;
    color: #075985;
    border-radius: 999px;
    padding: 3px 10px;
    font-size: 12px;
    font-weight: 800;
}
.cab-card h3 { margin: 0; font-size: 16px; line-height: 1.35; word-break: break-word; }
.cab-num { font-size: 34px; font-weight: 800; line-height: 1; color: #0f172a; }
.cab-num small { font-size: 13px; font-weight: 700; color: #64748b; margin-right: 6px; }
.cab-pills { display: flex; gap: 8px; }
.cab-pill { flex: 1; border-radius: 14px; padding: 8px 10px; background: #f8fafc; }
.cab-pill b { display: block; font-size: 18px; }
.cab-pill.new { background: #ecfdf3; color: #166534; }
.cab-pill.old { background: #f1f5f9; color: #334155; }
.cab-pill span { font-size: 12px; font-weight: 700; }
.cab-meta { color: #64748b; font-size: 13px; }
.cab-edit { border: 0; background: transparent; padding: 0; }
.cab-edit summary { cursor: pointer; color: #0369a1; font-weight: 800; font-size: 13px; }
.cab-edit label { display: block; font-size: 12px; font-weight: 800; margin: 8px 0 4px; }
.cab-edit input, .cab-edit select, .cab-block input, .cab-block select {
    width: 100%;
    min-height: 42px;
    border-radius: 12px;
}
.cab-block {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 20px;
    padding: 16px;
}
.cab-block h2 { margin: 0 0 8px; font-size: 20px; }
.cab-inv { display: flex; justify-content: space-between; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
.cab-pkgs { display: flex; flex-wrap: wrap; gap: 8px; }
.cab-pkg {
    background: #0f172a;
    color: #fff;
    border-radius: 999px;
    padding: 8px 14px;
    font-weight: 800;
    font-size: 13px;
}
.cab-table { width: 100%; border-collapse: collapse; }
.cab-table th, .cab-table td { text-align: right; padding: 8px 6px; border-bottom: 1px solid #eef2f7; font-size: 14px; }
@media (max-width: 760px) {
    .cab-hero { grid-template-columns: 1fr 1fr; }
    .cab-num { font-size: 28px; }
}
</style>
<div class="cab-wrap">
    <p class="cab-lead"><?php echo e($isEn
        ? 'Cabinets come from the SAS parent name. Pick an investor for each one. Packages load from this company only.'
        : 'الكابينة اسمها هو «تابع إلى» بالساس، وتنزل تلقائي. تختار لها مستثمر. الباقات تنزل من هالشركة وما تمس أسعار الوكلاء القديمة.'); ?></p>
    <div class="cab-hero">
        <div class="cab-stat"><b><?php echo count($cards); ?></b><span><?php echo e($isEn ? 'Cabinets' : 'كابينات'); ?></span></div>
        <div class="cab-stat"><b><?php echo (int) $sumMembers; ?></b><span><?php echo e($isEn ? 'Subscribers' : 'مشتركين'); ?></span></div>
        <div class="cab-stat"><b><?php echo $agesPending ? '…' : (int) $sumNew; ?></b><span><?php echo e($isEn ? 'New · first month' : 'جدد · أول شهر'); ?></span></div>
        <div class="cab-stat"><b><?php echo $agesPending ? '…' : (int) max(0, $sumMembers - $sumNew); ?></b><span><?php echo e($isEn ? 'Older' : 'قدامى'); ?></span></div>
    </div>
    <div class="cab-tools">
        <?php if ($onlyNew): ?>
            <a class="btn" href="cabinets.php"><?php echo e($isEn ? 'All cabinets' : 'كل الكابينات'); ?></a>
        <?php else: ?>
            <a class="btn" href="cabinets.php?new=1"><?php echo e($isEn ? 'New subscribers' : 'المشتركين الجدد'); ?></a>
        <?php endif; ?>
        <a class="btn secondary" href="#investors"><?php echo e($isEn ? 'Investors' : 'المستثمرين'); ?></a>
        <a class="btn secondary" href="#packages"><?php echo e($isEn ? 'Packages' : 'الباقات'); ?></a>
    </div>

    <?php if ($onlyNew): ?>
    <div class="cab-block">
        <h2><?php echo e($isEn ? 'New subscribers, all cabinets' : 'المشتركين الجدد لكل الكابينات'); ?></h2>
        <p class="cab-meta"><?php echo e($isEn ? 'Opened in the last 30 days. The first month is free.' : 'انفتحوا خلال آخر ٣٠ يوم. أول شهر مجاني.'); ?></p>
        <?php if (!$newRows): ?>
            <p class="cab-meta"><?php echo e($agesPending ? ($isEn ? 'Still reading dates from SAS. Refresh in a moment.' : 'بعده ينقرأ تاريخ الفتح من الساس. حدّث بعد لحظات.') : ($isEn ? 'No new subscribers.' : 'ماكو مشتركين جدد.')); ?></p>
        <?php else: ?>
            <table class="cab-table">
                <thead>
                    <tr>
                        <th><?php echo e($isEn ? 'Username' : 'اليوزر'); ?></th>
                        <th><?php echo e($isEn ? 'Cabinet' : 'الكابينة'); ?></th>
                        <th><?php echo e($isEn ? 'Opened' : 'تاريخ الفتح'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($newRows as $nr): ?>
                    <tr>
                        <td><?php echo e((string) $nr['username']); ?></td>
                        <td><?php echo e((string) $nr['parent_name']); ?></td>
                        <td><?php echo e(substr((string) $nr['sas_created_at'], 0, 10)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="cab-grid">
        <?php foreach ($cards as $card):
            $parts = cabinet_title_parts($card['name']);
            $invName = ($card['investor_id'] > 0 && isset($invById[$card['investor_id']])) ? $invById[$card['investor_id']] : '';
            ?>
        <article class="cab-card">
            <?php if ($parts[0] !== ''): ?><span class="cab-date"><?php echo e($parts[0]); ?></span><?php endif; ?>
            <h3><?php echo e($parts[1] !== '' ? $parts[1] : $card['name']); ?></h3>
            <div class="cab-num"><?php echo (int) $card['members']; ?> <small><?php echo e($isEn ? 'subscribers' : 'مشترك'); ?></small></div>
            <div class="cab-pills">
                <div class="cab-pill new"><b><?php echo $agesPending ? '…' : (int) $card['news']; ?></b><span><?php echo e($isEn ? 'New' : 'جدد'); ?></span></div>
                <div class="cab-pill old"><b><?php echo $agesPending ? '…' : (int) $card['old']; ?></b><span><?php echo e($isEn ? 'Older' : 'قدامى'); ?></span></div>
            </div>
            <div class="cab-meta">
                <?php echo e($invName !== '' ? (($isEn ? 'Investor: ' : 'المستثمر: ') . $invName) : ($isEn ? 'No investor yet' : 'بدون مستثمر')); ?>
                <?php if ($card['location'] !== ''): ?> · <?php echo e($card['location']); ?><?php endif; ?>
                <?php if ($card['tier'] !== ''): ?> · <?php echo e($card['tier']); ?><?php endif; ?>
            </div>
            <details class="cab-edit">
                <summary><?php echo e($isEn ? 'Investor, place, tier' : 'المستثمر والموقع والتير'); ?></summary>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="cabinet_id" value="<?php echo (int) $card['id']; ?>">
                    <label><?php echo e($isEn ? 'Investor' : 'المستثمر'); ?></label>
                    <select name="investor_id">
                        <option value="0"><?php echo e($isEn ? 'None' : 'بدون'); ?></option>
                        <?php foreach ($investors as $inv): ?>
                        <option value="<?php echo (int) $inv['id']; ?>"<?php echo (int) $card['investor_id'] === (int) $inv['id'] ? ' selected' : ''; ?>><?php echo e((string) $inv['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label><?php echo e($isEn ? 'Location' : 'الموقع'); ?></label>
                    <input name="location" maxlength="255" value="<?php echo e($card['location']); ?>">
                    <label><?php echo e($isEn ? 'Tier' : 'التير'); ?></label>
                    <input name="tier_label" maxlength="80" value="<?php echo e($card['tier']); ?>">
                    <div style="margin-top:10px"><button class="btn" type="submit"><?php echo e($isEn ? 'Save' : 'حفظ'); ?></button></div>
                </form>
            </details>
        </article>
        <?php endforeach; ?>
        <?php if (!$cards): ?>
            <div class="cab-block"><?php echo e($isEn ? 'No cabinets yet. They appear after the subscriber list is synced.' : 'ماكو كابينات بعد. تطلع بعد ما تتزامن قائمة المشتركين.'); ?></div>
        <?php endif; ?>
    </div>

    <div class="cab-block" id="investors">
        <h2><?php echo e($isEn ? 'Investors' : 'المستثمرين'); ?></h2>
        <p class="cab-meta"><?php echo e($isEn ? 'An investor can hold one or more cabinets. There is no price list here.' : 'المستثمر يتملك كابينة أو أكثر. هنا ماكو جدول أسعار.'); ?></p>
        <?php foreach ($investors as $inv):
            $iid = (int) $inv['id'];
            $load = isset($invLoad[$iid]) ? $invLoad[$iid] : array('cabinets' => 0, 'members' => 0);
            ?>
        <div class="cab-inv">
            <div>
                <strong><?php echo e((string) $inv['name']); ?></strong>
                <div class="cab-meta"><?php echo (int) $load['cabinets']; ?> <?php echo e($isEn ? 'cabinets' : 'كابينة'); ?> · <?php echo (int) $load['members']; ?> <?php echo e($isEn ? 'subscribers' : 'مشترك'); ?></div>
            </div>
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="investor_del">
                <input type="hidden" name="investor_id" value="<?php echo $iid; ?>">
                <button class="btn secondary" type="submit"><?php echo e($isEn ? 'Remove' : 'حذف'); ?></button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php if (!$investors): ?>
            <p class="cab-meta"><?php echo e($isEn ? 'No investors yet.' : 'ماكو مستثمرين بعد.'); ?></p>
        <?php endif; ?>
        <form method="post" style="margin-top:12px">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="investor_add">
            <label><?php echo e($isEn ? 'New investor' : 'مستثمر جديد'); ?></label>
            <div class="cab-grid" style="grid-template-columns: 1fr auto; align-items: end;">
                <input name="name" maxlength="160" required placeholder="<?php echo e($isEn ? 'Name' : 'الاسم'); ?>">
                <button class="btn" type="submit"><?php echo e($isEn ? 'Add' : 'إضافة'); ?></button>
            </div>
        </form>
    </div>

    <div class="cab-block" id="packages">
        <h2><?php echo e($isEn ? 'Packages' : 'الباقات'); ?></h2>
        <p class="cab-meta"><?php echo e($isEn ? 'Loaded from this SAS. The old agent price tables are left as they are.' : 'تنزل من ساس هالشركة. جداول أسعار الوكلاء القديمة ما تتغير.'); ?></p>
        <div class="cab-pkgs">
            <?php foreach ($profiles as $pkg): ?>
                <span class="cab-pkg"><?php echo e((string) $pkg); ?></span>
            <?php endforeach; ?>
            <?php if (!$profiles): ?>
                <span class="cab-meta"><?php echo e($isEn ? 'Packages did not load yet.' : 'الباقات ما نزلت بعد.'); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="cab-block" id="add">
        <h2><?php echo e($isEn ? 'Add a cabinet by hand' : 'إضافة كابينة يدوياً'); ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="add">
            <div class="cab-grid">
                <div>
                    <label><?php echo e($isEn ? 'Name' : 'الاسم'); ?></label>
                    <input name="name" required maxlength="160">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Location' : 'الموقع'); ?></label>
                    <input name="location" maxlength="255">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Tier' : 'التير'); ?></label>
                    <input name="tier_label" maxlength="80">
                </div>
                <div>
                    <label><?php echo e($isEn ? 'Investor' : 'المستثمر'); ?></label>
                    <select name="investor_id">
                        <option value="0"><?php echo e($isEn ? 'None' : 'بدون'); ?></option>
                        <?php foreach ($investors as $inv): ?>
                        <option value="<?php echo (int) $inv['id']; ?>"><?php echo e((string) $inv['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:10px"><button class="btn" type="submit"><?php echo e($isEn ? 'Add cabinet' : 'إضافة الكابينة'); ?></button></div>
        </form>
    </div>
</div>
<?php
render_footer();
