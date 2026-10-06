<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/settings_tabs.php';
require_login();
require_perm('plans');
$plansLeaf = function_exists('account_viewer_is_leaf_child') && account_viewer_is_leaf_child($pdo);

function plans_priced_user_id($pdo)
{
    $me = function_exists('current_admin') ? current_admin() : null;
    if (!$me) {
        return 0;
    }
    $uid = (int) $me['id'];
    $role = isset($me['role']) ? (string) $me['role'] : '';
    if ($role === 'admin') {
        return $uid;
    }
    $tid = isset($me['tenant_id']) ? (int) $me['tenant_id'] : 0;
    if ($tid <= 0 || !$pdo) {
        return $uid;
    }
    try {
        $st = $pdo->prepare('SELECT id FROM admin_users WHERE role = "admin" AND tenant_id = :t ORDER BY id ASC LIMIT 1');
        $st->execute(array(':t' => $tid));
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
    } catch (Exception $e) {
    }
    return $uid;
}

function plans_price_floors($pdo)
{
    $byName = array();
    $byPid = array();
    // أعلى حساب بالبوابة ما فوقه أحد: يسعّر لنفسه وينزل بالسعر. الحد للأدنى يطبق على من أبوه مسعّره.
    if (!function_exists('account_viewer_parent_id') || account_viewer_parent_id($pdo) <= 0) {
        return array($byName, $byPid);
    }
    $uid = plans_priced_user_id($pdo);
    if ($uid <= 0 || !$pdo || !function_exists('agent_card_prices_list')) {
        return array($byName, $byPid);
    }
    foreach (agent_card_prices_list($pdo, $uid) as $row) {
        $v = isset($row['agent_price']) ? (float) $row['agent_price'] : 0;
        if ($v <= 0 && isset($row['wholesale_price'])) {
            $v = (float) $row['wholesale_price'];
        }
        if ($v <= 0) {
            continue;
        }
        $nk = strtolower(trim((string) $row['profile_name']));
        if ($nk !== '' && (!isset($byName[$nk]) || $byName[$nk] < $v)) {
            $byName[$nk] = $v;
        }
        $pid = isset($row['profile_id']) ? (int) $row['profile_id'] : 0;
        if ($pid > 0 && (!isset($byPid[$pid]) || $byPid[$pid] < $v)) {
            $byPid[$pid] = $v;
        }
    }
    return array($byName, $byPid);
}

function plans_account_book($pdo)
{
    $byName = array();
    $bySoft = array();
    $byPid = array();
    $me = function_exists('current_admin') ? current_admin() : null;
    $uid = $me ? (int) $me['id'] : 0;
    if ($uid > 0 && $pdo && function_exists('agent_card_prices_list')) {
        foreach (agent_card_prices_list($pdo, $uid) as $row) {
            // سعر الأب لهذا الحساب فقط. سعر التكلفة المنسوخ من الساس ما ينحسب تسعيرة.
            $v = isset($row['agent_price']) ? (float) $row['agent_price'] : 0;
            if ($v <= 0) {
                continue;
            }
            $name = isset($row['profile_name']) ? (string) $row['profile_name'] : '';
            $nk = strtolower(trim($name));
            if ($nk !== '' && !isset($byName[$nk])) {
                $byName[$nk] = $v;
            }
            if ($nk !== '' && function_exists('card_price_soft_key')) {
                $sk = card_price_soft_key($name);
                if ($sk !== '' && !isset($bySoft[$sk])) {
                    $bySoft[$sk] = $v;
                }
            }
            $pid = isset($row['profile_id']) ? (int) $row['profile_id'] : 0;
            if ($pid > 0 && !isset($byPid[$pid])) {
                $byPid[$pid] = $v;
            }
        }
    }
    $has = ($byName || $bySoft || $byPid);
    $role = ($me && isset($me['role'])) ? (string) $me['role'] : '';
    $parentId = 0;
    if ($uid > 0 && $pdo) {
        try {
            $stP = $pdo->prepare('SELECT reports_to_user_id FROM admin_users WHERE id = :id LIMIT 1');
            $stP->execute(array(':id' => $uid));
            $parentId = (int) $stP->fetchColumn();
        } catch (Exception $e) {
            $parentId = 0;
        }
    }
    $child = ($parentId > 0 || $role === 'agent' || $role === 'group_manager');
    // الابن ما يكتب فوق سعر الساس العام. الباقة اللي الأب ما مسعّرها تبقى على سعر الساس بالعرض.
    $shareCatalog = !$child;
    return array($uid, $has, $shareCatalog, $byName, $bySoft, $byPid);
}

function plans_account_price($book, $name, $sasId)
{
    $byName = $book[3];
    $bySoft = $book[4];
    $byPid = $book[5];
    $nk = strtolower(trim((string) $name));
    if ($nk !== '' && isset($byName[$nk])) {
        return (float) $byName[$nk];
    }
    $sasId = (int) $sasId;
    if ($sasId > 0 && isset($byPid[$sasId])) {
        return (float) $byPid[$sasId];
    }
    if ($nk !== '' && function_exists('card_price_soft_key')) {
        $sk = card_price_soft_key($name);
        if ($sk !== '' && isset($bySoft[$sk])) {
            return (float) $bySoft[$sk];
        }
    }
    return null;
}

function plans_floor_for($byName, $byPid, $name, $sasId)
{
    $floor = 0;
    $nk = strtolower(trim((string) $name));
    if ($nk !== '' && isset($byName[$nk]) && (float) $byName[$nk] > $floor) {
        $floor = (float) $byName[$nk];
    }
    $sasId = (int) $sasId;
    if ($sasId > 0 && isset($byPid[$sasId]) && (float) $byPid[$sasId] > $floor) {
        $floor = (float) $byPid[$sasId];
    }
    return $floor;
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editPlan = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($plansLeaf) {
        flash('error', 'الباقات للعرض فقط');
        redirect('plans.php');
    }
    if (!verify_csrf(post('csrf'))) {
        flash('error', 'طلب غير صالح');
        redirect('plans.php');
    }

    $action = post('action');

    if ($action === 'create' || $action === 'update') {
        $id = (int) post('id', '0');
        $name = trim((string) post('name', ''));
        $cost = (float) post('cost_price', '0');
        $sort = (int) post('sort_order', '100');
        $sasProfile = (int) post('sas_profile_id', '0');
        $price = $cost;
        if ($action === 'update' && $id > 0) {
            try {
                $stKeep = $pdo->prepare('SELECT monthly_price FROM service_plans WHERE id = :id LIMIT 1');
                $stKeep->execute(array(':id' => $id));
                $kept = $stKeep->fetchColumn();
                if ($kept !== false && $kept !== null) {
                    $price = (float) $kept;
                }
            } catch (Exception $e) {
            }
        }

        if ($name === '' || $price < 0 || $cost < 0) {
            flash('error', 'اسم الباقة مطلوب والأسعار لا تكون سالبة');
            redirect($action === 'update' ? ('plans.php?edit=' . $id) : 'plans.php');
        }

        $priceBook = plans_account_book($pdo);
        // الحساب الأعلى يكتب السعر على الباقة نفسها. الوكيل اللي فوقه أحد يحفظ سعره عنده وما يغيّر سعر الباقة العام.
        $keepSharedCost = empty($priceBook[2]);
        $globalCost = $cost;
        if ($keepSharedCost && $action === 'update' && $id > 0) {
            try {
                $stGlob = $pdo->prepare('SELECT cost_price FROM service_plans WHERE id = :id LIMIT 1');
                $stGlob->execute(array(':id' => $id));
                $glob = $stGlob->fetchColumn();
                if ($glob !== false && $glob !== null) {
                    $globalCost = (float) $glob;
                }
            } catch (Exception $e) {
            }
        }

        list($floorByName, $floorByPid) = plans_price_floors($pdo);
        $floor = plans_floor_for($floorByName, $floorByPid, $name, $sasProfile);
        if ($action === 'update' && $id > 0) {
            try {
                $stOld = $pdo->prepare('SELECT name, sas_profile_id FROM service_plans WHERE id = :id LIMIT 1');
                $stOld->execute(array(':id' => $id));
                $oldPlan = $stOld->fetch();
                if ($oldPlan) {
                    $oldFloor = plans_floor_for(
                        $floorByName,
                        $floorByPid,
                        isset($oldPlan['name']) ? $oldPlan['name'] : '',
                        isset($oldPlan['sas_profile_id']) ? $oldPlan['sas_profile_id'] : 0
                    );
                    if ($oldFloor > $floor) {
                        $floor = $oldFloor;
                    }
                }
            } catch (Exception $e) {
            }
        }
        if ($floor > 0 && $cost + 0.001 < $floor) {
            flash('error', 'السعر ما ينزل عن ' . (int) $floor . '. الصفحة اللي فوق مسعّرة هذا الوكيل بهذا السعر.');
            redirect($action === 'update' ? ('plans.php?edit=' . $id) : 'plans.php');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO service_plans (name, monthly_price, cost_price, sas_profile_id, sort_order, is_active)
                 VALUES (:name, :price, :cost, :sas_profile, :sort, 1)'
            );
            $stmt->execute(array(
                ':name' => $name,
                ':price' => $price,
                ':cost' => $keepSharedCost ? 0 : $cost,
                ':sas_profile' => $sasProfile > 0 ? $sasProfile : null,
                ':sort' => $sort,
            ));
            if ((int) $priceBook[0] > 0 && function_exists('agent_card_price_save')) {
                agent_card_price_save($pdo, (int) $priceBook[0], $sasProfile, $name, $cost, $cost, $cost);
            }
            flash('success', 'تمت إضافة الباقة');
        } else {
            if ($id <= 0) {
                flash('error', 'باقة غير موجودة');
                redirect('plans.php');
            }
            $stmt = $pdo->prepare(
                'UPDATE service_plans
                 SET name = :name, monthly_price = :price, cost_price = :cost,
                     sas_profile_id = :sas_profile, sort_order = :sort
                 WHERE id = :id'
            );
            $stmt->execute(array(
                ':id' => $id,
                ':name' => $name,
                ':price' => $price,
                ':cost' => $globalCost,
                ':sas_profile' => $sasProfile > 0 ? $sasProfile : null,
                ':sort' => $sort,
            ));
            if ((int) $priceBook[0] > 0 && function_exists('agent_card_price_save')) {
                agent_card_price_save($pdo, (int) $priceBook[0], $sasProfile, $name, $cost, $cost, null);
            }
            flash('success', 'تم تعديل الباقة');
        }
        redirect('plans.php');
    }

    if ($action === 'reorder') {
        $orderRaw = (string) post('order', '');
        $ids = array_filter(array_map('intval', explode(',', $orderRaw)));
        $pos = 1;
        $stmt = $pdo->prepare('UPDATE service_plans SET sort_order = :s WHERE id = :id');
        foreach ($ids as $id) {
            if ($id > 0) {
                $stmt->execute(array(':s' => $pos, ':id' => $id));
                $pos++;
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('success' => true));
        exit;
    }

    if ($action === 'toggle') {
        $id = (int) post('id', '0');
        $pdo->prepare('UPDATE service_plans SET is_active = IF(is_active=1,0,1) WHERE id = :id')
            ->execute(array(':id' => $id));
        flash('success', 'تم تحديث حالة الباقة');
        redirect('plans.php');
    }

    if ($action === 'delete') {
        $id = (int) post('id', '0');
        if ($id <= 0) {
            flash('error', 'باقة غير موجودة');
            redirect('plans.php');
        }
        $pdo->prepare('DELETE FROM service_plans WHERE id = :id')->execute(array(':id' => $id));
        flash('success', 'تم حذف الباقة');
        redirect('plans.php');
    }

    if ($action === 'import_sas') {
        if (!function_exists('sas_is_ready') || !sas_is_ready($config)) {
            flash('error', 'فعّل ربط SAS من الإعدادات أولاً');
            redirect('plans.php');
        }
        $api = function_exists('sas_page_connector') ? sas_page_connector($config) : null;
        if (!$api) {
            flash('error', 'ماكو اتصال بالساس — تعذر استيراد الباقات');
            redirect('plans.php');
        }
        unset($_SESSION['sas_profiles_ui'], $_SESSION['sas_profiles_ui_at']);
        $profiles = function_exists('sas_profiles_for_ui') ? sas_profiles_for_ui($api) : array();
        if (!$profiles) {
            flash('error', 'ماكو باقات راجعة من الساس');
            redirect('plans.php');
        }
        $existing = $pdo->query('SELECT id, name, sas_profile_id FROM service_plans')->fetchAll();
        $byPid = array();
        $byName = array();
        foreach ($existing as $er) {
            if (!empty($er['sas_profile_id'])) {
                $byPid[(int) $er['sas_profile_id']] = (int) $er['id'];
            }
            $nm = function_exists('mb_strtolower')
                ? mb_strtolower(trim((string) $er['name']), 'UTF-8')
                : strtolower(trim((string) $er['name']));
            if ($nm !== '') {
                $byName[$nm] = (int) $er['id'];
            }
        }
        $added = 0;
        $linked = 0;
        $sort = $nextSortPreview = 1;
        try {
            $maxSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM service_plans')->fetchColumn();
            $sort = $maxSort + 1;
        } catch (Exception $e) {
        }
        $ins = $pdo->prepare(
            'INSERT INTO service_plans (name, monthly_price, cost_price, sas_profile_id, sort_order, is_active)
             VALUES (:name, 0, 0, :pid, :sort, 1)'
        );
        $link = $pdo->prepare('UPDATE service_plans SET sas_profile_id = :pid WHERE id = :id AND (sas_profile_id IS NULL OR sas_profile_id = 0)');
        foreach ($profiles as $pr) {
            $pid = isset($pr['id']) ? (int) $pr['id'] : 0;
            $pname = isset($pr['name']) ? trim((string) $pr['name']) : '';
            if ($pid <= 0 || $pname === '') {
                continue;
            }
            if (isset($byPid[$pid])) {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($pname, 'UTF-8') : strtolower($pname);
            if (isset($byName[$key])) {
                $link->execute(array(':pid' => $pid, ':id' => $byName[$key]));
                $byPid[$pid] = $byName[$key];
                $linked++;
                continue;
            }
            $ins->execute(array(':name' => $pname, ':pid' => $pid, ':sort' => $sort));
            $newId = (int) $pdo->lastInsertId();
            $byPid[$pid] = $newId;
            $byName[$key] = $newId;
            $sort++;
            $added++;
        }
        flash('success', 'تم الاستيراد من الساس: ' . $added . ' باقة جديدة' . ($linked > 0 ? ('، وربط ' . $linked) : '') . '.');
        redirect('plans.php');
    }
}

if ($editId > 0) {
    $st = $pdo->prepare('SELECT * FROM service_plans WHERE id = :id');
    $st->execute(array(':id' => $editId));
    $editPlan = $st->fetch();
    if (!$editPlan) {
        flash('error', 'الباقة غير موجودة');
        redirect('plans.php');
    }
}

if ($plansLeaf && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $pullProfiles = array();
    if (isset($_SESSION['sas_profiles_ui']) && is_array($_SESSION['sas_profiles_ui'])) {
        $pullProfiles = $_SESSION['sas_profiles_ui'];
    }
    if (!$pullProfiles && function_exists('sas_is_ready') && sas_is_ready($config) && function_exists('sas_page_connector') && function_exists('sas_profiles_for_ui')) {
        $pullApi = sas_page_connector($config);
        if ($pullApi) {
            $pullProfiles = sas_profiles_for_ui($pullApi);
        }
    }
    if ($pullProfiles) {
        try {
            $existingPull = $pdo->query('SELECT id, name, sas_profile_id FROM service_plans')->fetchAll();
            $byPidPull = array();
            $byNamePull = array();
            foreach ($existingPull as $er) {
                if (!empty($er['sas_profile_id'])) {
                    $byPidPull[(int) $er['sas_profile_id']] = (int) $er['id'];
                }
                $nmPull = function_exists('mb_strtolower')
                    ? mb_strtolower(trim((string) $er['name']), 'UTF-8')
                    : strtolower(trim((string) $er['name']));
                if ($nmPull !== '') {
                    $byNamePull[$nmPull] = (int) $er['id'];
                }
            }
            $sortPull = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM service_plans')->fetchColumn() + 1;
            $insPull = $pdo->prepare(
                'INSERT INTO service_plans (name, monthly_price, cost_price, sas_profile_id, sort_order, is_active)
                 VALUES (:name, 0, 0, :pid, :sort, 1)'
            );
            foreach ($pullProfiles as $pr) {
                $pidPull = isset($pr['id']) ? (int) $pr['id'] : 0;
                $pnamePull = isset($pr['name']) ? trim((string) $pr['name']) : '';
                if ($pidPull <= 0 || $pnamePull === '' || isset($byPidPull[$pidPull])) {
                    continue;
                }
                $keyPull = function_exists('mb_strtolower') ? mb_strtolower($pnamePull, 'UTF-8') : strtolower($pnamePull);
                if (isset($byNamePull[$keyPull])) {
                    continue;
                }
                $insPull->execute(array(':name' => $pnamePull, ':pid' => $pidPull, ':sort' => $sortPull));
                $byPidPull[$pidPull] = (int) $pdo->lastInsertId();
                $byNamePull[$keyPull] = $byPidPull[$pidPull];
                $sortPull++;
            }
        } catch (Exception $e) {
        }
    }
}

$plans = $pdo->query('SELECT * FROM service_plans ORDER BY sort_order ASC, monthly_price ASC, id ASC')->fetchAll();
$priceBook = plans_account_book($pdo);
list($floorByName, $floorByPid) = plans_price_floors($pdo);
$editFloor = 0;
$editCostShown = 0;
if ($editPlan) {
    $editFloor = plans_floor_for(
        $floorByName,
        $floorByPid,
        isset($editPlan['name']) ? $editPlan['name'] : '',
        isset($editPlan['sas_profile_id']) ? $editPlan['sas_profile_id'] : 0
    );
    $editCostShown = (int) account_viewer_package_price(
        $pdo,
        isset($editPlan['name']) ? $editPlan['name'] : '',
        isset($editPlan['sas_profile_id']) ? $editPlan['sas_profile_id'] : 0,
        isset($editPlan['cost_price']) ? $editPlan['cost_price'] : 0
    );
}
$nextSort = 1;
if ($plans) {
    $maxSort = 0;
    foreach ($plans as $p) {
        $maxSort = max($maxSort, (int) $p['sort_order']);
    }
    $nextSort = $maxSort + 1;
}

$showAdd = $editPlan || (isset($_GET['add']) && (string) $_GET['add'] === '1');

render_header(t('plans'), 'plans');
render_settings_tabs('plans');
?>
<style>
.plans-head {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  margin-bottom: 12px; flex-wrap: wrap;
}
.plans-head h2 { margin: 0; }
.plans-add-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 40px; height: 40px; border-radius: 10px;
  background: #15803d; color: #fff !important; text-decoration: none !important;
  font-size: 26px; font-weight: 700; line-height: 1;
  box-shadow: 0 4px 0 rgba(21, 128, 61, 0.25);
}
.plans-add-btn:hover { background: #166534; color: #fff !important; }
.plans-add-panel[hidden] { display: none !important; }
</style>

<div class="panel">
    <div class="plans-head">
        <div>
            <h2><?php echo e($lang === 'en' ? 'Packages' : 'الباقات'); ?></h2>
            <?php if (!$plansLeaf): ?>
            <p style="color:#6b7a88;margin:4px 0 0;font-weight:600"><?php echo e(t('drag_hint')); ?></p>
            <?php endif; ?>
        </div>
        <?php if (!$plansLeaf): ?>
        <div class="actions" style="margin:0;gap:8px;align-items:center">
            <a class="btn secondary sm" href="agent_prices.php"><?php echo e($lang === 'en' ? 'Price table' : 'جدول الأسعار'); ?></a>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="import_sas">
                <button class="btn secondary sm" type="submit"><?php echo e($lang === 'en' ? 'Import from SAS' : 'استيراد من الساس'); ?></button>
            </form>
            <a class="plans-add-btn" href="plans.php?add=1" title="<?php echo e($lang === 'en' ? 'Add package' : 'إضافة باقة'); ?>" aria-label="<?php echo e($lang === 'en' ? 'Add package' : 'إضافة باقة'); ?>">+</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$plansLeaf): ?>
    <div class="plans-add-panel" id="plansAddPanel"<?php echo $showAdd ? '' : ' hidden'; ?>>
        <h2 style="margin-top:0"><?php echo $editPlan ? t('edit') . ' — ' . e($editPlan['name']) : ($lang === 'en' ? 'Add package' : 'إضافة باقة جديدة'); ?></h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="<?php echo $editPlan ? 'update' : 'create'; ?>">
            <?php if ($editPlan): ?>
                <input type="hidden" name="id" value="<?php echo (int) $editPlan['id']; ?>">
            <?php endif; ?>
            <div class="form-grid cols-4">
                <div>
                    <label>اسم الباقة</label>
                    <input name="name" placeholder="NB-MAX" required
                           value="<?php echo e($editPlan ? $editPlan['name'] : ''); ?>">
                </div>
                <div>
                    <label>السعر</label>
                    <input type="number" name="cost_price" min="<?php echo $editFloor > 0 ? (int) $editFloor : 0; ?>" step="any" required
                           value="<?php echo e($editPlan ? (string) $editCostShown : '0'); ?>">
                    <?php if ($editFloor > 0): ?>
                    <p class="meta" style="margin:6px 0 0">ما ينزل عن <?php echo (int) $editFloor; ?> — سعر الصفحة اللي فوق.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label><?php echo e(t('sort_order')); ?></label>
                    <input type="number" name="sort_order" min="1" step="1" required
                           value="<?php echo e($editPlan ? (string) (int) $editPlan['sort_order'] : (string) $nextSort); ?>">
                </div>
                <div>
                    <label>SAS Profile ID</label>
                    <input type="number" name="sas_profile_id" min="0" step="1"
                           value="<?php echo e($editPlan && !empty($editPlan['sas_profile_id']) ? (string) (int) $editPlan['sas_profile_id'] : ''); ?>"
                           placeholder="من sas_setup.php">
                </div>
            </div>
            <div class="actions">
                <button class="btn" type="submit"><?php echo $editPlan ? 'حفظ التعديل' : 'حفظ الباقة'; ?></button>
                <a class="btn ghost" href="plans.php"><?php echo e($lang === 'en' ? 'Cancel' : 'إلغاء'); ?></a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="table-wrap" style="margin-top:14px">
        <table>
            <thead>
            <?php if ($plansLeaf): ?>
            <tr>
                <th>الباقة</th>
                <th>التكلفة</th>
                <th>سعر المشترك</th>
            </tr>
            <?php else: ?>
            <tr>
                <th></th>
                <th>#</th>
                <th>التسلسل</th>
                <th>الباقة</th>
                <th>السعر</th>
                <th>SAS</th>
                <th>الحالة</th>
                <th>إجراءات</th>
            </tr>
            <?php endif; ?>
            </thead>
            <tbody id="plansBody">
            <?php if (!$plans): ?>
                <tr><td colspan="<?php echo $plansLeaf ? 3 : 8; ?>"><?php echo e($lang === 'en' ? 'No packages yet' : 'لا توجد باقات بعد'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($plans as $p): ?>
                <?php
                $cost = function_exists('account_viewer_package_price')
                    ? account_viewer_package_price(
                        $pdo,
                        isset($p['name']) ? $p['name'] : '',
                        isset($p['sas_profile_id']) ? $p['sas_profile_id'] : 0,
                        isset($p['cost_price']) ? $p['cost_price'] : 0
                    )
                    : (isset($p['cost_price']) ? (float) $p['cost_price'] : 0);
                $retailShown = function_exists('account_viewer_package_retail')
                    ? account_viewer_package_retail(
                        $pdo,
                        isset($p['name']) ? $p['name'] : '',
                        isset($p['sas_profile_id']) ? $p['sas_profile_id'] : 0,
                        isset($p['monthly_price']) ? $p['monthly_price'] : 0
                    )
                    : (isset($p['monthly_price']) ? (float) $p['monthly_price'] : 0);
                $unpriced = ($cost <= 0);
                $rowFloor = plans_floor_for(
                    $floorByName,
                    $floorByPid,
                    isset($p['name']) ? $p['name'] : '',
                    isset($p['sas_profile_id']) ? $p['sas_profile_id'] : 0
                );
                ?>
                <?php if ($plansLeaf): ?>
                <tr>
                    <td><strong><?php echo e($p['name']); ?></strong></td>
                    <td><?php echo $unpriced ? '—' : e(money_format_iqd($cost, $config['currency'])); ?></td>
                    <td><?php echo e(money_format_iqd($retailShown, $config['currency'])); ?></td>
                </tr>
                <?php else: ?>
                <tr draggable="true" data-id="<?php echo (int) $p['id']; ?>">
                    <td class="drag-handle" title="اسحب">☰</td>
                    <td class="row-num"></td>
                    <td><strong><?php echo (int) $p['sort_order']; ?></strong></td>
                    <td><strong><?php echo e($p['name']); ?></strong></td>
                    <td><?php if ($unpriced): ?><span class="meta">غير مسعر</span><?php else: ?><?php echo e(money_format_iqd($cost, $config['currency'])); ?><?php endif; ?><?php if ($rowFloor > 0 && $cost + 0.001 < $rowFloor): ?><div class="meta">الحد <?php echo (int) $rowFloor; ?></div><?php endif; ?></td>
                    <td><?php echo !empty($p['sas_profile_id']) ? ('#' . (int) $p['sas_profile_id']) : '—'; ?></td>
                    <td>
                        <span class="badge <?php echo ((int) $p['is_active'] === 1) ? 'active' : 'expired'; ?>">
                            <?php echo ((int) $p['is_active'] === 1) ? 'مفعّلة' : 'موقوفة'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn sm secondary" href="plans.php?edit=<?php echo (int) $p['id']; ?>">تعديل</a>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                                <button class="btn sm ghost" type="submit">تفعيل/إيقاف</button>
                            </form>
                            <form method="post" style="display:inline" onsubmit="return confirm('تحذف الباقة؟');">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                                <button class="btn sm danger" type="submit">حذف</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
  var body = document.getElementById('plansBody');
  if (!body) return;
  var dragEl = null;
  var csrf = <?php echo json_encode(csrf_token()); ?>;

  function renumber() {
    var rows = body.querySelectorAll('tr[data-id]');
    for (var i = 0; i < rows.length; i++) {
      var num = rows[i].querySelector('.row-num');
      if (num) num.textContent = String(i + 1);
    }
  }

  function saveOrder() {
    var ids = [];
    var rows = body.querySelectorAll('tr[data-id]');
    for (var i = 0; i < rows.length; i++) ids.push(rows[i].getAttribute('data-id'));
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'reorder');
    fd.append('order', ids.join(','));
    fetch('plans.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function () {
        for (var i = 0; i < rows.length; i++) {
          var cell = rows[i].children[2];
          if (cell) cell.innerHTML = '<strong>' + (i + 1) + '</strong>';
        }
      })
      .catch(function () {});
  }

  body.addEventListener('dragstart', function (e) {
    var tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    dragEl = tr;
    tr.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });
  body.addEventListener('dragend', function () {
    if (dragEl) dragEl.classList.remove('dragging');
    dragEl = null;
    renumber();
    saveOrder();
  });
  body.addEventListener('dragover', function (e) {
    e.preventDefault();
    var tr = e.target.closest('tr[data-id]');
    if (!tr || !dragEl || tr === dragEl) return;
    var rect = tr.getBoundingClientRect();
    var before = (e.clientY - rect.top) < rect.height / 2;
    body.insertBefore(dragEl, before ? tr : tr.nextSibling);
  });

  renumber();
})();
</script>
<?php render_footer(); ?>
