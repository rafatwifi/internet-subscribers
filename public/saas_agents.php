<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();

if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
    flash('error', ($lang === 'en') ? 'Super admin only' : 'للمدير العام فقط');
    redirect('index.php');
}

$isEn = ($lang === 'en');
ensure_tenants_schema($pdo, $config);
if (function_exists('saas_mark_expired_tenants')) {
    saas_mark_expired_tenants($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('saas_agents.php');
    }
    $action = post('action');
    $tid = (int) post('tenant_id', '0');
    if ($action === 'create') {
        $loginName = trim((string) post('username', ''));
        list($ok, $msg) = saas_admin_create_user(
            $pdo,
            $loginName,
            $loginName,
            post('display_name', ''),
            post('password', ''),
            post('phone', ''),
            $settings,
            post('email', '')
        );
        $restorePick = (string) post('restore_agency_id', '0');
        if ($ok && $restorePick === 'file' && !empty($_FILES['agency_pack']['tmp_name']) && function_exists('platform_agents_pack_import')) {
            $find = $pdo->prepare('SELECT tenant_id FROM admin_users WHERE username = :u ORDER BY id DESC LIMIT 1');
            $find->execute(array(':u' => $loginName));
            $newTid = (int) $find->fetchColumn();
            if ($newTid > 1) {
                list($okI, $msgI) = platform_agents_pack_import($pdo, $_FILES['agency_pack']['tmp_name'], $newTid);
                $msg = $okI
                    ? ($isEn ? 'Agent added and file imported' : 'تمت إضافة الوكيل وتحميل ملف بياناته')
                    : $msgI;
                $ok = $okI;
            }
        } elseif ($ok && function_exists('saas_attach_parked_agency')) {
            $oldTid = (int) $restorePick;
            if ($oldTid > 1) {
                $find = $pdo->prepare('SELECT tenant_id FROM admin_users WHERE username = :u ORDER BY id DESC LIMIT 1');
                $find->execute(array(':u' => $loginName));
                $newTid = (int) $find->fetchColumn();
                if ($newTid > 1) {
                    saas_attach_parked_agency($pdo, $newTid, $oldTid);
                    $msg = $isEn ? 'Agent added and saved data attached' : 'تمت إضافة الوكيل وربط بياناته المحفوظة';
                }
            }
        }
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'update') {
        $loginName = trim((string) post('username', ''));
        list($ok, $msg) = saas_admin_update_user(
            $pdo,
            $tid,
            $loginName,
            $loginName,
            post('display_name', ''),
            post('password', ''),
            post('phone', ''),
            post('email', '')
        );
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'delete') {
        $dest = (string) post('data_dest', '');
        list($ok, $msg) = saas_admin_delete_user($pdo, $tid, $dest);
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'approve') {
        list($ok, $msg) = saas_approve_tenant($pdo, $tid, $settings);
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'reject') {
        flash(saas_reject_tenant($pdo, $tid) ? 'success' : 'error', $isEn ? 'Suspended' : 'تم التعليق');
    } elseif ($action === 'extend') {
        $days = max(1, (int) post('days', '30'));
        flash(saas_extend_subscription($pdo, $tid, $days, post('plan_code', 'manual'))
            ? 'success' : 'error', $isEn ? 'Extended' : 'تم التمديد');
    }
    redirect('saas_agents.php');
}

if (isset($_GET['export_agent']) && function_exists('platform_agents_pack_zip')) {
    $exportTid = (int) $_GET['export_agent'];
    if ($exportTid <= 1) {
        flash('error', $isEn ? 'Cannot export' : 'ما ينزل الملف');
        redirect('saas_agents.php');
    }
    list($expOk, $expPath, $expMsg) = platform_agents_pack_zip($pdo, $exportTid);
    if (!$expOk || !is_file($expPath)) {
        flash('error', $expMsg !== '' ? $expMsg : ($isEn ? 'Export failed' : 'فشل تحميل النسخة'));
        redirect('saas_agents.php');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="agent-data-' . $exportTid . '.zip"');
    readfile($expPath);
    @unlink($expPath);
    exit;
}

$rows = array();
try {
    $rows = $pdo->query(
        'SELECT t.*, u.username AS owner_username, u.display_name AS owner_name, u.is_active AS owner_active
         FROM tenants t
         LEFT JOIN admin_users u ON u.id = t.owner_user_id
         WHERE t.id > 1
         ORDER BY FIELD(t.status, "pending", "active", "expired", "suspended"), t.id DESC'
    )->fetchAll();
} catch (Exception $e) {
    $rows = tenants_list($pdo);
    $rows = array_values(array_filter($rows, function ($r) {
        return (int) $r['id'] > 1;
    }));
}

$allRows = $rows;
$view = isset($_GET['view']) ? (string) $_GET['view'] : '';
if (!in_array($view, array('pending', 'active', 'expired'), true)) {
    $view = '';
}
$q = trim((string) (isset($_GET['q']) ? $_GET['q'] : ''));
$statusLabel = array(
    'pending' => $isEn ? 'Pending' : 'بانتظار',
    'active' => $isEn ? 'Active' : 'نشط',
    'expired' => $isEn ? 'Expired' : 'منتهي',
    'suspended' => $isEn ? 'Suspended' : 'معلّق',
);

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 1) {
    foreach ($allRows as $r) {
        if ((int) $r['id'] === $editId) {
            $editRow = $r;
            break;
        }
    }
}

if ($view !== '') {
    $filtered = array();
    foreach ($rows as $r) {
        $stRow = isset($r['status']) ? (string) $r['status'] : 'active';
        if ($stRow === $view) {
            $filtered[] = $r;
        }
    }
    $rows = $filtered;
}
if ($q !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $filtered = array();
    foreach ($rows as $r) {
        $stRow = isset($r['status']) ? (string) $r['status'] : '';
        $bits = array(isset($statusLabel[$stRow]) ? $statusLabel[$stRow] : '');
        foreach ($r as $val) {
            if (is_scalar($val) && $val !== null && $val !== '') {
                $bits[] = $val;
            }
        }
        $hay = function_exists('mb_strtolower') ? mb_strtolower(implode(' ', $bits), 'UTF-8') : strtolower(implode(' ', $bits));
        $hit = function_exists('mb_strpos') ? (mb_strpos($hay, $needle, 0, 'UTF-8') !== false) : (strpos($hay, $needle) !== false);
        if ($hit) {
            $filtered[] = $r;
        }
    }
    $rows = $filtered;
}

render_header($isEn ? 'Agents' : 'وكلاء', 'saas_agents');
$viewLabel = array(
    'pending' => $isEn ? 'Registration requests' : 'طلبات التسجيل',
    'active' => $isEn ? 'Active' : 'الفعالين',
    'expired' => $isEn ? 'Expired' : 'المنتهين',
);
?>
<div class="panel">
    <div class="sys-head">
        <h2><?php echo e($view !== '' && isset($viewLabel[$view]) ? $viewLabel[$view] : ($isEn ? 'Subscribers' : 'المشتركين')); ?></h2>
        <button class="btn" type="button" id="sysUserToggle"><?php echo e($editRow ? ($isEn ? 'Edit system user' : 'تعديل مستخدم النظام') : ($isEn ? 'Add system user' : 'إضافة مستخدم نظام')); ?></button>
    </div>
    <form method="get" class="sys-search">
        <?php if ($view !== ''): ?>
            <input type="hidden" name="view" value="<?php echo e($view); ?>">
        <?php endif; ?>
        <input type="search" name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e($isEn ? 'Search username, name, phone, email, status…' : 'بحث باليوزر أو الاسم أو الهاتف أو الإيميل أو الحالة…'); ?>" autocomplete="off">
        <button class="btn" type="submit"><?php echo e($isEn ? 'Search' : 'بحث'); ?></button>
        <?php if ($q !== '' || $view !== ''): ?>
            <a class="btn ghost" href="saas_agents.php"><?php echo e($isEn ? 'Show all' : 'عرض الكل'); ?></a>
        <?php endif; ?>
    </form>
    <form method="post" id="sysUserBox" enctype="multipart/form-data" <?php echo $editRow ? '' : 'hidden'; ?>>
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
        <?php if ($editRow): ?>
            <input type="hidden" name="tenant_id" value="<?php echo (int) $editRow['id']; ?>">
        <?php endif; ?>
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($isEn ? 'Username' : 'اسم المستخدم'); ?></label>
                <input class="ltr" name="username" required pattern="[A-Za-z0-9._@\-]{2,40}" value="<?php echo e($editRow && isset($editRow['owner_username']) ? $editRow['owner_username'] : ''); ?>" autocomplete="off">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Owner name' : 'اسم المالك'); ?></label>
                <input name="display_name" value="<?php echo e($editRow && isset($editRow['owner_name']) ? $editRow['owner_name'] : ''); ?>" autocomplete="off">
            </div>
            <div>
                <label><?php echo e($editRow ? ($isEn ? 'New password (optional)' : 'باسورد جديد (اختياري)') : ($isEn ? 'Password' : 'الباسورد')); ?></label>
                <input type="password" name="password" <?php echo $editRow ? '' : 'required'; ?> minlength="4">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?></label>
                <input class="ltr" name="phone" value="<?php echo e($editRow && !empty($editRow['contact_phone']) ? $editRow['contact_phone'] : ''); ?>" placeholder="07xxxxxxxxx">
            </div>
            <div>
                <label><?php echo e($isEn ? 'Email' : 'الإيميل'); ?></label>
                <input class="ltr" type="email" name="email" value="<?php echo e($editRow && !empty($editRow['contact_email']) ? $editRow['contact_email'] : ''); ?>" placeholder="name@example.com">
            </div>
            <?php if (!$editRow):
                $parkedAgencies = function_exists('saas_parked_agencies') ? saas_parked_agencies($pdo) : array();
                ?>
            <div>
                <label><?php echo e($isEn ? 'Saved data' : 'البيانات المحفوظة'); ?></label>
                <select name="restore_agency_id" id="restoreAgencyPick">
                    <option value="0"><?php echo e($isEn ? '— none —' : '— بدون —'); ?></option>
                    <option value="file"><?php echo e($isEn ? 'Upload agent data file' : 'تحميل بيانات الوكيل'); ?></option>
                    <?php foreach ($parkedAgencies as $park):
                        $parkLabel = $park['username'];
                        if (!empty($park['display_name']) && $park['display_name'] !== $park['username']) {
                            $parkLabel .= ' — ' . $park['display_name'];
                        }
                        $parkLabel .= ' (' . (int) $park['subscribers'] . ($isEn ? ' subscribers' : ' مشترك');
                        if ((int) $park['cache'] > 0) {
                            $parkLabel .= ' · ' . (int) $park['cache'] . ($isEn ? ' cache' : ' كاش');
                        }
                        $parkLabel .= ')';
                        ?>
                        <option value="<?php echo (int) $park['old_tenant_id']; ?>"><?php echo e($parkLabel); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="file" name="agency_pack" id="agencyPackFile" accept=".zip,application/zip" hidden style="margin-top:8px">
            </div>
            <?php endif; ?>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e($editRow ? ($isEn ? 'Save' : 'حفظ التعديل') : ($isEn ? 'Add' : 'إضافة')); ?></button>
            <?php if ($editRow): ?>
                <a class="btn ghost" href="saas_agents.php"><?php echo e($isEn ? 'Cancel' : 'إلغاء'); ?></a>
            <?php endif; ?>
        </div>
    </form>
    <div class="table-wrap">
        <table class="table-compact">
            <thead>
            <tr>
                <th>#</th>
                <th><?php echo e($isEn ? 'Username' : 'اسم المستخدم'); ?></th>
                <th><?php echo e($isEn ? 'Owner' : 'المالك'); ?></th>
                <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                <th><?php echo e($isEn ? 'Trial / Sub' : 'تجريبي / اشتراك'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="msg-empty"><?php echo e($q !== '' ? ($isEn ? 'No matches' : 'ماكو نتيجة') : ($isEn ? 'No registrations yet' : 'ماكو تسجيلات بعد')); ?></td></tr>
            <?php endif; ?>
            <?php $rowNo = 0; foreach ($rows as $r):
                $rowNo++;
                $tid = (int) $r['id'];
                $st = isset($r['status']) ? $r['status'] : 'active';
                ?>
                <tr>
                    <td><?php echo (int) $rowNo; ?></td>
                    <td><?php echo e(!empty($r['owner_username']) ? $r['owner_username'] : $r['name']); ?><?php if (!empty($r['contact_phone'])): ?><br><small class="meta ltr"><?php echo e($r['contact_phone']); ?></small><?php endif; ?></td>
                    <td><?php echo e(isset($r['owner_name']) && $r['owner_name'] !== '' ? $r['owner_name'] : '—'); ?><?php if (!empty($r['contact_email'])): ?><br><small class="meta ltr"><?php echo e($r['contact_email']); ?></small><?php endif; ?></td>
                    <td><span class="sys-st st-<?php echo e($st); ?>"><?php echo e(isset($statusLabel[$st]) ? $statusLabel[$st] : $st); ?></span></td>
                    <td class="meta" style="font-size:12px">
                        <?php echo e($isEn ? 'Trial' : 'تجريبي'); ?>: <?php echo e(!empty($r['trial_ends_at']) ? $r['trial_ends_at'] : '—'); ?><br>
                        <?php echo e($isEn ? 'Until' : 'حتى'); ?>: <?php echo e(!empty($r['subscription_expires_at']) ? $r['subscription_expires_at'] : '—'); ?>
                    </td>
                    <td class="ag-actions">
                        <?php if ($st === 'pending'): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="tenant_id" value="<?php echo $tid; ?>">
                                <button class="btn sm" type="submit"><?php echo e($isEn ? 'Approve' : 'موافقة'); ?></button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Reject/suspend?' : 'رفض/تعليق؟'); ?>);">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="tenant_id" value="<?php echo $tid; ?>">
                                <button class="btn ghost sm" type="submit"><?php echo e($isEn ? 'Reject' : 'رفض'); ?></button>
                            </form>
                        <?php else: ?>
                            <form method="post" class="inline-form ag-extend">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="extend">
                                <input type="hidden" name="tenant_id" value="<?php echo $tid; ?>">
                                <input type="number" name="days" value="30" min="1">
                                <button class="btn sm" type="submit"><?php echo e($isEn ? 'Extend' : 'تمديد'); ?></button>
                            </form>
                            <?php if ($st !== 'suspended'): ?>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="tenant_id" value="<?php echo $tid; ?>">
                                <button class="btn ghost sm" type="submit"><?php echo e($isEn ? 'Suspend' : 'تعليق'); ?></button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php
                        $loginUid = !empty($r['owner_user_id']) ? (int) $r['owner_user_id'] : 0;
                        if ($loginUid <= 0 && $st === 'active') {
                            try {
                                $ownSt = $pdo->prepare('SELECT id FROM admin_users WHERE tenant_id = :t AND role = "admin" AND is_active = 1 ORDER BY id ASC LIMIT 1');
                                $ownSt->execute(array(':t' => $tid));
                                $loginUid = (int) $ownSt->fetchColumn();
                            } catch (Exception $e) {
                                $loginUid = 0;
                            }
                        }
                        ?>
                        <?php if ($st === 'active' && $loginUid > 0): ?>
                        <form method="post" action="impersonate.php" class="inline-form">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="start">
                            <input type="hidden" name="user_id" value="<?php echo $loginUid; ?>">
                            <button class="btn sm" type="submit"><?php echo e($isEn ? 'Login as user' : 'دخول'); ?></button>
                        </form>
                        <?php endif; ?>
                        <a class="btn secondary sm" href="saas_agents.php?edit=<?php echo $tid; ?>"><?php echo e($isEn ? 'Edit' : 'تعديل'); ?></a>
                        <button class="btn danger sm js-del-open" type="button" data-del="<?php echo $tid; ?>"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                    </td>
                </tr>
                <tr class="del-row" id="delRow<?php echo $tid; ?>" hidden>
                    <td colspan="6">
                        <form method="post" class="js-agency-del ag-del-form">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="tenant_id" value="<?php echo $tid; ?>">
                            <label><?php echo e($isEn ? 'Where does the data go?' : 'وين تروح البيانات؟'); ?></label>
                            <select name="data_dest" required>
                                <option value=""><?php echo e($isEn ? 'Choose' : 'اختار'); ?></option>
                                <option value="download"><?php echo e($isEn ? 'Download a copy of the data' : 'تحميل نسخة من البيانات'); ?></option>
                                <?php foreach ($allRows as $otherAg):
                                    if ((int) $otherAg['id'] === $tid) { continue; }
                                    $otherName = !empty($otherAg['owner_username']) ? $otherAg['owner_username'] : $otherAg['name'];
                                    ?>
                                    <option value="tenant:<?php echo (int) $otherAg['id']; ?>"><?php echo e(($isEn ? 'To ' : 'إلى ') . $otherName); ?></option>
                                <?php endforeach; ?>
                                <option value="hold"><?php echo e($isEn ? 'Keep until a new agency' : 'تبقى محفوظة حتى وكالة جديدة'); ?></option>
                            </select>
                            <button class="btn danger sm js-del-confirm" type="submit" disabled><?php echo e($isEn ? 'Confirm delete' : 'تأكيد الحذف'); ?></button>
                            <button class="btn ghost sm js-del-close" type="button"><?php echo e($isEn ? 'Cancel' : 'إلغاء'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<style>
.sys-head { display:flex; align-items:center; justify-content:flex-end; direction:ltr; gap:12px; margin:0 0 12px; flex-wrap:wrap; }
.sys-head h2 { margin:0; }
#sysUserBox { margin:0 0 14px; }
.sys-search { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 14px; }
.sys-search input[type="search"] { flex:1; min-width:220px; max-width:480px; }
.sys-st { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:800; }
.st-active { background:#dcfce7; color:#166534; }
.st-pending { background:#fef9c3; color:#854d0e; }
.st-expired { background:#fee2e2; color:#991b1b; }
.st-suspended { background:#f1f5f9; color:#475569; }
.ag-actions { display:flex; flex-wrap:wrap; gap:6px; align-items:center; justify-content:flex-end; }
.ag-extend { display:inline-flex; gap:4px; align-items:center; }
.ag-extend input[type="number"] { width:64px; height:32px; }
.del-row[hidden] { display:none !important; }
.del-row td { background:#f8fafc; }
.ag-del-form { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.ag-del-form select { min-width:240px; max-width:360px; }
.table-compact td { vertical-align:middle; }
</style>
<script>
(function () {
  var btn = document.getElementById('sysUserToggle');
  var box = document.getElementById('sysUserBox');
  if (btn && box) {
  btn.addEventListener('click', function () {
    box.hidden = !box.hidden;
    if (!box.hidden) {
      var first = box.querySelector('input[name="username"]');
      if (first) first.focus();
    }
  });
  }
  var pick = document.getElementById('restoreAgencyPick');
  var pack = document.getElementById('agencyPackFile');
  if (pick && pack) {
    pick.addEventListener('change', function () {
      var on = pick.value === 'file';
      pack.hidden = !on;
      pack.required = on;
    });
  }
  function closeDels(exceptId) {
    var rows = document.querySelectorAll('.del-row');
    for (var i = 0; i < rows.length; i++) {
      if (exceptId && rows[i].id === exceptId) continue;
      rows[i].hidden = true;
    }
  }
  var opens = document.querySelectorAll('.js-del-open');
  for (var o = 0; o < opens.length; o++) {
    opens[o].addEventListener('click', function () {
      var id = 'delRow' + this.getAttribute('data-del');
      var row = document.getElementById(id);
      if (!row) return;
      var open = row.hidden;
      closeDels(open ? id : '');
      row.hidden = !open;
    });
  }
  var closers = document.querySelectorAll('.js-del-close');
  for (var c = 0; c < closers.length; c++) {
    closers[c].addEventListener('click', function () {
      var row = this.closest ? this.closest('tr') : null;
      if (row) row.hidden = true;
    });
  }
  var forms = document.querySelectorAll('.js-agency-del');
  for (var i = 0; i < forms.length; i++) {
    (function (form) {
      var sel = form.querySelector('[name="data_dest"]');
      var go = form.querySelector('.js-del-confirm');
      var tidEl = form.querySelector('[name="tenant_id"]');
      function arm(on) {
        if (go) go.disabled = !on;
      }
      if (sel) {
        sel.addEventListener('change', function () {
          form.removeAttribute('data-ready');
          if (!sel.value) {
            arm(false);
            return;
          }
          if (sel.value !== 'download') {
            arm(true);
            return;
          }
          arm(false);
          var tid = tidEl ? tidEl.value : '0';
          fetch('saas_agents.php?export_agent=' + encodeURIComponent(tid), { credentials: 'same-origin' })
            .then(function (r) {
              if (!r.ok) throw new Error('fail');
              return r.blob();
            })
            .then(function (blob) {
              var a = document.createElement('a');
              a.href = URL.createObjectURL(blob);
              a.download = 'agent-data-' + tid + '.zip';
              document.body.appendChild(a);
              a.click();
              a.remove();
              form.setAttribute('data-ready', '1');
              arm(true);
            })
            .catch(function () {
              arm(false);
              alert(<?php echo json_encode($isEn ? 'Download failed. The agent was not deleted.' : 'ما انحمل الملف، وما انحذف الوكيل'); ?>);
            });
        });
      }
      form.addEventListener('submit', function (e) {
        if (!sel || !sel.value || (go && go.disabled)) {
          e.preventDefault();
          return;
        }
        if (sel.value === 'download' && form.getAttribute('data-ready') !== '1') {
          e.preventDefault();
          return;
        }
      });
    })(forms[i]);
  }
})();
</script>
<?php render_footer(); ?>
