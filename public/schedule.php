<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('settings');

$isEn = ($lang === 'en');
$s = settings_load();
$sysGrace = function_exists('subscriber_default_grace_days')
    ? subscriber_default_grace_days($config)
    : (int) (isset($s['grace_days']) ? $s['grace_days'] : 3);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('schedule.php');
    }
    $section = post('section', '');
    if ($section === 'schedule') {
        $expDays = (int) post('expiry_auto_remind_days', '1');
        if ($expDays < 0) {
            $expDays = 0;
        }
        if ($expDays > 60) {
            $expDays = 60;
        }
        $data = array(
            'schedule_cut_enabled' => post('schedule_cut_enabled') === '1',
            'schedule_cut_send_wa' => post('schedule_cut_send_wa') === '1',
            'tpl_schedule_cut' => (string) post('tpl_schedule_cut', ''),
            'wa_case_schedule_cut' => 'schedule_cut',
            'expiry_auto_remind_enabled' => post('expiry_auto_remind_enabled') === '1',
            'expiry_auto_remind_days' => $expDays,
        );
        if (post('schedule_run_now') === '1' && function_exists('run_schedule_debt_cuts')) {
            if (settings_save($data)) {
                $settings = settings_load();
                $config = apply_settings_to_config($config, $settings);
                $run = run_schedule_debt_cuts($pdo, $config, 100);
                flash('success', ($isEn ? 'Run: checked ' : 'تشغيل: فحص ')
                    . (int) $run['checked']
                    . ($isEn ? ' — cut ' : ' — قطع ')
                    . (int) $run['cut']
                    . ($isEn ? ' — WA ' : ' — واتساب ')
                    . (int) $run['wa_sent']);
            } else {
                flash('error', 'Cannot write settings.json');
            }
            redirect('schedule.php');
        }
        if (settings_save($data)) {
            flash('success', t('saved'));
        } else {
            flash('error', 'Cannot write settings.json');
        }
        redirect('schedule.php');
    }
}

if (function_exists('ensure_subscriber_grace_days_column')) {
    ensure_subscriber_grace_days_column($pdo);
}

$rows = array();
$queryError = '';
try {
    // متوافق مع ONLY_FULL_GROUP_BY — كل المدينين غير المسددين (حتى بدون يوزر ساس)
    $sql = "SELECT s.id AS subscriber_id, s.name, s.phone, s.grace_days, s.sas_username,
                   MAX(c.username) AS cache_username,
                   MAX(c.enabled) AS sas_enabled,
                   MAX(c.is_online) AS is_online,
                   MIN(CASE
                         WHEN i.due_date IS NULL OR i.due_date < '1971-01-01' THEN CURDATE()
                         ELSE i.due_date
                       END) AS oldest_due,
                   SUM(i.amount) AS debt_total
            FROM subscribers s
            INNER JOIN invoices i ON i.subscriber_id = s.id AND i.status = 'unpaid'
            LEFT JOIN sas_users_cache c ON (
                c.local_subscriber_id = s.id
                OR (
                  s.sas_username IS NOT NULL AND TRIM(s.sas_username) <> ''
                  AND LOWER(TRIM(c.username)) = LOWER(TRIM(s.sas_username))
                )
            )
            GROUP BY s.id, s.name, s.phone, s.grace_days, s.sas_username
            HAVING SUM(i.amount) > 0
            ORDER BY oldest_due ASC
            LIMIT 500";
    $rows = $pdo->query($sql)->fetchAll();
} catch (Exception $e) {
    $queryError = $e->getMessage();
    try {
        $sql2 = "SELECT s.id AS subscriber_id, s.name, s.phone, s.grace_days, s.sas_username,
                        '' AS cache_username, 1 AS sas_enabled, 0 AS is_online,
                        MIN(i.due_date) AS oldest_due, SUM(i.amount) AS debt_total
                 FROM subscribers s
                 INNER JOIN invoices i ON i.subscriber_id = s.id AND i.status = 'unpaid'
                 GROUP BY s.id, s.name, s.phone, s.grace_days, s.sas_username
                 HAVING SUM(i.amount) > 0
                 ORDER BY oldest_due ASC
                 LIMIT 500";
        $rows = $pdo->query($sql2)->fetchAll();
        $queryError = '';
    } catch (Exception $e2) {
        $rows = array();
        $queryError = $e2->getMessage();
    }
}

$list = array();
foreach ($rows as $row) {
    $grace = function_exists('subscriber_grace_days')
        ? subscriber_grace_days($row, $config)
        : $sysGrace;
    $oldest = !empty($row['oldest_due']) ? (string) $row['oldest_due'] : date('Y-m-d');
    if ($oldest === '' || $oldest < '1971-01-01') {
        $oldest = date('Y-m-d');
    }
    $dueTs = strtotime($oldest);
    if ($dueTs <= 0) {
        $dueTs = strtotime(date('Y-m-d'));
    }
    $daysPassed = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $dueTs))) / 86400);
    if ($daysPassed < 0) {
        $daysPassed = 0;
    }
    $willCut = ($daysPassed > $grace);
    $daysLeft = $grace - $daysPassed;
    $username = trim((string) (!empty($row['cache_username']) ? $row['cache_username'] : $row['sas_username']));
    $list[] = array(
        'id' => (int) $row['subscriber_id'],
        'name' => $row['name'],
        'username' => $username,
        'phone' => isset($row['phone']) ? $row['phone'] : '',
        'debt' => isset($row['debt_total']) ? (float) $row['debt_total'] : 0,
        'grace' => $grace,
        'days_passed' => $daysPassed,
        'days_left' => $daysLeft,
        'will_cut' => $willCut,
        'enabled' => isset($row['sas_enabled']) ? (int) $row['sas_enabled'] : 1,
        'online' => !empty($row['is_online']),
    );
}

$cronSecret = isset($config['cron_secret']) ? (string) $config['cron_secret'] : '';
$cronUrl = 'cron/schedule_cut.php?key=' . rawurlencode($cronSecret);
$enabled = !empty($s['schedule_cut_enabled']);
$sendWa = !isset($s['schedule_cut_send_wa']) || !empty($s['schedule_cut_send_wa']);

$topTools = '<button type="button" class="btn ghost sm" id="schedSettingsBtn" title="'
    . e($isEn ? 'Schedule settings' : 'إعدادات الجدول الدوري') . '">⚙</button>';

render_header($isEn ? 'Periodic jobs' : 'الجدول الدوري', 'schedule', '', '', $topTools);
?>
<style>
.sched-page { max-width: 1180px; margin: 0 auto; }
.sched-hero {
  margin: 0 0 14px; padding: 16px 18px; border-radius: 16px;
  background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); color: #fff;
  box-shadow: 0 10px 28px rgba(15,23,42,.18);
}
.sched-hero h2 { margin: 0 0 6px; font-size: 18px; }
.sched-hero p { margin: 0 0 12px; opacity: .88; font-size: 13px; font-weight: 600; max-width: 62ch; }
.sched-hero-pills { display: flex; flex-wrap: wrap; gap: 8px; }
.sched-pill {
  display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 999px;
  font-size: 12px; font-weight: 800; border: 1px solid rgba(255,255,255,.22);
  background: rgba(255,255,255,.12); color: #fff;
}
.sched-pill.on { background: rgba(34,197,94,.22); border-color: rgba(134,239,172,.45); }
.sched-pill.off { background: rgba(244,63,94,.2); border-color: rgba(253,164,175,.45); }
.sched-toolbar {
  display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 10px;
}
.sched-table-wrap {
  overflow: auto; border: 1px solid rgba(15,23,42,.08); border-radius: 16px;
  background: rgba(255,255,255,.92); box-shadow: 0 8px 24px rgba(15,23,42,.05);
}
.sched-table { width: 100%; border-collapse: collapse; min-width: 860px; }
.sched-table th, .sched-table td {
  padding: 9px 10px; border-bottom: 1px solid #eef2f7; text-align: start; font-size: 13px;
  vertical-align: middle;
}
.sched-table th { background: #f1f5f9; font-weight: 800; color: #334155; position: sticky; top: 0; z-index: 1; }
.sched-table tr:last-child td { border-bottom: 0; }
.sched-table tbody tr:hover td { background: #f8fafc; }
.sched-table tbody tr.is-checked td { background: #eef2ff; }
.sched-table .will-cut td { background: #fff7ed; }
.sched-table .cut-done { opacity: .72; }
.sched-num { width: 42px; text-align: center !important; color: #64748b; font-weight: 700; }
.sched-check { width: 36px; text-align: center !important; }
.sched-ops { width: 70px; white-space: nowrap; }
.sched-badge {
  display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 800;
  white-space: nowrap;
}
.sched-badge.warn { background: #ffedd5; color: #9a3412; }
.sched-badge.ok { background: #dcfce7; color: #166534; }
.sched-badge.bad { background: #fee2e2; color: #991b1b; }
.sched-badge.muted { background: #e2e8f0; color: #475569; }
.sched-name { font-weight: 800; color: #0f172a; }
.sched-user { font-size: 12px; color: #64748b; font-weight: 600; direction: ltr; unicode-bidi: isolate; }
.sched-ops-drop {
  position: fixed; z-index: 90; min-width: 180px; background: #fff; border: 1px solid #e2e8f0;
  border-radius: 12px; box-shadow: 0 12px 28px rgba(15,23,42,.16); padding: 6px; display: none;
}
.sched-ops-drop.open { display: block; }
.sched-ops-drop a, .sched-ops-drop button {
  display: block; width: 100%; text-align: start; border: 0; background: transparent;
  padding: 9px 10px; border-radius: 8px; font: inherit; font-weight: 700; font-size: 13px;
  color: #0f172a; cursor: pointer; text-decoration: none;
}
.sched-ops-drop a:hover, .sched-ops-drop button:hover { background: #f1f5f9; }
.sched-drawer {
  position: fixed; inset: 0; z-index: 80; display: none; align-items: stretch; justify-content: flex-end;
  background: rgba(15,23,42,.35);
}
.sched-drawer.open { display: flex; }
.sched-drawer-panel {
  width: min(420px, 100%); background: #fff; height: 100%; overflow: auto;
  padding: 18px 16px 28px; box-shadow: -12px 0 40px rgba(15,23,42,.18);
}
body.rtl .sched-drawer { justify-content: flex-start; }
body.rtl .sched-drawer-panel { box-shadow: 12px 0 40px rgba(15,23,42,.18); }
.sched-drawer h2 { margin: 0 0 8px; font-size: 18px; }
.sched-drawer .meta { margin: 0 0 14px; color: #64748b; font-size: 13px; }
@media (max-width: 640px) {
  .sched-drawer-panel { width: 100%; }
  .sched-hero { padding: 14px; }
}
</style>

<div class="sched-page">
  <div class="sched-hero">
    <h2><?php echo e($isEn ? 'Periodic schedule' : 'الجدول الدوري'); ?></h2>
    <p><?php echo e($isEn
        ? 'Unpaid debtors — days past due vs grace, and who will be cut.'
        : 'المدينين غير المسددين — الأيام الماضية مقابل السماح، ومن راح ينقطع.'); ?></p>
    <div class="sched-hero-pills">
      <span class="sched-pill <?php echo $enabled ? 'on' : 'off'; ?>">
        <?php echo $enabled
            ? e($isEn ? 'Auto-cut ON' : 'القطع التلقائي يعمل')
            : e($isEn ? 'Auto-cut OFF' : 'القطع التلقائي متوقف'); ?>
      </span>
      <span class="sched-pill">
        <?php echo e($isEn ? 'System grace' : 'سماح النظام'); ?>: <?php echo (int) $sysGrace; ?>
      </span>
      <span class="sched-pill">
        <?php echo e($isEn ? 'WA notice' : 'إشعار واتساب'); ?>:
        <?php echo $sendWa ? e($isEn ? 'On' : 'يعمل') : e($isEn ? 'Off' : 'مطفأ'); ?>
      </span>
      <span class="sched-pill">
        <?php echo e($isEn ? 'Debtors' : 'مدينين'); ?>: <?php echo count($list); ?>
      </span>
    </div>
  </div>

  <div class="sched-toolbar">
    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:13px">
      <input type="checkbox" id="schedCheckAll">
      <?php echo e($isEn ? 'Select all' : 'تحديد الكل'); ?>
    </label>
    <span class="meta" id="schedSelectedHint" style="margin:0"></span>
  </div>

  <?php if ($queryError !== '' && !$list): ?>
    <p style="color:#dd4b39;font-weight:700"><?php echo e($isEn ? 'Query error' : 'خطأ بالاستعلام'); ?>: <?php echo e($queryError); ?></p>
  <?php endif; ?>

  <div class="sched-table-wrap">
    <table class="sched-table" id="schedTable">
      <thead>
        <tr>
          <th class="sched-num">#</th>
          <th class="sched-check"><input type="checkbox" id="schedCheckAllHead" title="<?php echo e($isEn ? 'Select all' : 'تحديد الكل'); ?>"></th>
          <th><?php echo e($isEn ? 'Subscriber' : 'المشترك'); ?></th>
          <th><?php echo e($isEn ? 'Username' : 'اليوزر'); ?></th>
          <th><?php echo e($isEn ? 'Debt' : 'الدين'); ?></th>
          <th><?php echo e($isEn ? 'Days past' : 'مضى'); ?></th>
          <th><?php echo e($isEn ? 'Grace' : 'السماح'); ?></th>
          <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
          <th class="sched-ops"><?php echo e($isEn ? 'Ops' : 'عمليات'); ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="9"><?php echo e($isEn ? 'No unpaid debtors found.' : 'ماكو مدينين غير مسددين.'); ?></td></tr>
      <?php endif; ?>
      <?php $rowNum = 0; foreach ($list as $r): $rowNum++; ?>
        <?php
          $cls = '';
          if ((int) $r['enabled'] === 0) {
              $cls = 'cut-done';
          } elseif ($r['will_cut']) {
              $cls = 'will-cut';
          }
          $userUrl = $r['username'] !== ''
              ? ('sas_user.php?u=' . rawurlencode($r['username']))
              : ('subscriber.php?id=' . (int) $r['id']);
          $debtsUrl = 'debts.php?status=unpaid&subscriber_id=' . (int) $r['id'];
        ?>
        <tr class="<?php echo e($cls); ?>" data-sid="<?php echo (int) $r['id']; ?>">
          <td class="sched-num"><?php echo (int) $rowNum; ?></td>
          <td class="sched-check"><input type="checkbox" class="sched-row-check" value="<?php echo (int) $r['id']; ?>"></td>
          <td>
            <a class="sched-name" href="<?php echo e($userUrl); ?>"><?php echo e($r['name'] !== '' ? $r['name'] : $r['username']); ?></a>
            <?php if ($r['phone'] !== ''): ?><div class="meta" style="margin:2px 0 0;direction:ltr;text-align:left"><?php echo e($r['phone']); ?></div><?php endif; ?>
          </td>
          <td><?php if ($r['username'] !== ''): ?><a class="sched-user" href="<?php echo e($userUrl); ?>"><?php echo e($r['username']); ?></a><?php else: ?>—<?php endif; ?></td>
          <td><?php echo e(money_format_iqd($r['debt'], $config['currency'])); ?></td>
          <td><?php echo (int) $r['days_passed']; ?></td>
          <td><?php echo (int) $r['grace']; ?></td>
          <td>
            <?php if ((int) $r['enabled'] === 0): ?>
              <span class="sched-badge bad"><?php echo e($isEn ? 'Already disabled' : 'معطّل حالياً'); ?></span>
            <?php elseif ($r['will_cut']): ?>
              <span class="sched-badge warn"><?php echo e($isEn ? 'Will be cut' : 'راح ينقطع'); ?></span>
              <span class="sched-badge muted"><?php echo e($sendWa ? ($isEn ? 'WA: yes' : 'إشعار القطع: نعم') : ($isEn ? 'WA: no' : 'إشعار القطع: لا')); ?></span>
            <?php else: ?>
              <span class="sched-badge ok"><?php echo e($isEn ? ('In grace (' . (int) $r['days_left'] . ' left)') : ('ضمن السماح (باقي ' . (int) $r['days_left'] . ')')); ?></span>
            <?php endif; ?>
          </td>
          <td class="sched-ops">
            <button type="button" class="btn ghost sm sched-ops-btn"
              data-user="<?php echo e($userUrl); ?>"
              data-debts="<?php echo e($debtsUrl); ?>"
              data-name="<?php echo e($r['name'] !== '' ? $r['name'] : $r['username']); ?>">⋯</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="sched-ops-drop" id="schedOpsDrop" hidden>
  <a href="#" id="schedOpsOpen"><?php echo e($isEn ? 'Open subscriber' : 'فتح المشترك'); ?></a>
  <a href="#" id="schedOpsDebts"><?php echo e($isEn ? 'Debts' : 'الديون'); ?></a>
</div>

<div class="sched-drawer" id="schedDrawer" aria-hidden="true">
  <div class="sched-drawer-panel" role="dialog" aria-modal="true">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px">
      <h2><?php echo e($isEn ? 'Schedule settings' : 'إعدادات الجدول الدوري'); ?></h2>
      <button type="button" class="btn ghost sm" id="schedDrawerClose">×</button>
    </div>
    <p class="meta"><?php echo e($isEn
        ? 'Enable auto-disable after grace, WhatsApp notice, and message template.'
        : 'تشغيل/إيقاف القطع بعد السماح، إشعار واتساب، وقالب الرسالة.'); ?></p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
      <input type="hidden" name="section" value="schedule">
      <label class="toggle" style="display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:10px">
        <input type="checkbox" name="schedule_cut_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
        <span class="toggle-ui"></span>
        <span><strong><?php echo e($isEn ? 'Auto-disable after grace' : 'قطع تلقائي بعد أيام السماح'); ?></strong></span>
      </label>
      <label class="toggle" style="display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:12px">
        <input type="checkbox" name="schedule_cut_send_wa" value="1" <?php echo $sendWa ? 'checked' : ''; ?>>
        <span class="toggle-ui"></span>
        <span><strong><?php echo e($isEn ? 'Send cut WhatsApp' : 'إرسال رسالة القطع'); ?></strong></span>
      </label>
      <label class="toggle" style="display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:10px">
        <input type="checkbox" id="expiryAutoToggle" name="expiry_auto_remind_enabled" value="1"
          <?php echo !empty($s['expiry_auto_remind_enabled']) ? 'checked' : ''; ?>>
        <span class="toggle-ui"></span>
        <span><strong><?php echo e($isEn ? 'Auto reminder before subscription ends' : 'تذكير تلقائي قبل انتهاء الاشتراك'); ?></strong></span>
      </label>
      <div id="expiryAutoFields" style="display:none;margin:0 0 12px;padding:12px;border:1px solid #e2e8f0;border-radius:10px">
        <label><?php echo e($isEn ? 'Days before end' : 'قبل الانتهاء بـ (يوم)'); ?></label>
        <input type="number" min="0" max="60" name="expiry_auto_remind_days"
          value="<?php echo (int) (isset($s['expiry_auto_remind_days']) ? $s['expiry_auto_remind_days'] : 1); ?>" style="width:100%;max-width:120px">
        <p class="meta" style="margin:8px 0 0">
          <?php echo e($isEn ? 'Message text:' : 'نص الرسالة:'); ?>
          <a href="messages.php?mode=templates"><?php echo e(t('templates')); ?></a>
        </p>
      </div>
      <label><?php echo e($isEn ? 'Cut message template' : 'قالب رسالة القطع'); ?></label>
      <p class="meta">{name} {debt} {days_passed} {grace} {package} {month}</p>
      <textarea name="tpl_schedule_cut" rows="6" style="width:100%"><?php echo e(isset($s['tpl_schedule_cut']) ? $s['tpl_schedule_cut'] : ''); ?></textarea>
      <p class="meta" style="margin-top:10px"><?php echo e($isEn ? 'Cron URL:' : 'رابط الكرون:'); ?><br><code class="ltr" style="word-break:break-all"><?php echo e($cronUrl); ?></code></p>
      <div class="actions" style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
        <?php if ($enabled && function_exists('run_schedule_debt_cuts')): ?>
          <button class="btn ghost" type="submit" name="schedule_run_now" value="1"><?php echo e($isEn ? 'Run once now' : 'تشغيل مرة الآن'); ?></button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var t = document.getElementById('expiryAutoToggle');
  var f = document.getElementById('expiryAutoFields');
  function syncExp() {
    if (!f) return;
    f.style.display = (t && t.checked) ? 'block' : 'none';
  }
  if (t) t.addEventListener('change', syncExp);
  syncExp();
})();
(function () {
  var btn = document.getElementById('schedSettingsBtn');
  var drawer = document.getElementById('schedDrawer');
  var closeBtn = document.getElementById('schedDrawerClose');
  function open() {
    if (!drawer) return;
    drawer.classList.add('open');
    drawer.setAttribute('aria-hidden', 'false');
  }
  function close() {
    if (!drawer) return;
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
  }
  if (btn) btn.addEventListener('click', open);
  if (closeBtn) closeBtn.addEventListener('click', close);
  if (drawer) drawer.addEventListener('click', function (e) {
    if (e.target === drawer) close();
  });

  function syncChecks(master) {
    document.querySelectorAll('.sched-row-check').forEach(function (c) {
      c.checked = !!master.checked;
      var tr = c.closest('tr');
      if (tr) tr.classList.toggle('is-checked', c.checked);
    });
    var all = document.getElementById('schedCheckAll');
    var head = document.getElementById('schedCheckAllHead');
    if (all && all !== master) all.checked = !!master.checked;
    if (head && head !== master) head.checked = !!master.checked;
    updateHint();
  }
  function updateHint() {
    var n = document.querySelectorAll('.sched-row-check:checked').length;
    var hint = document.getElementById('schedSelectedHint');
    if (hint) hint.textContent = n ? (n + ' <?php echo $isEn ? 'selected' : 'محدد'; ?>') : '';
  }
  var all = document.getElementById('schedCheckAll');
  var head = document.getElementById('schedCheckAllHead');
  if (all) all.addEventListener('change', function () { syncChecks(all); });
  if (head) head.addEventListener('change', function () { syncChecks(head); });
  document.querySelectorAll('.sched-row-check').forEach(function (c) {
    c.addEventListener('change', function () {
      var tr = c.closest('tr');
      if (tr) tr.classList.toggle('is-checked', c.checked);
      updateHint();
    });
  });

  var drop = document.getElementById('schedOpsDrop');
  var opsOpen = document.getElementById('schedOpsOpen');
  var opsDebts = document.getElementById('schedOpsDebts');
  function hideOps() {
    if (!drop) return;
    drop.classList.remove('open');
    drop.hidden = true;
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.sched-ops-btn') : null;
    if (b && drop) {
      e.preventDefault();
      e.stopPropagation();
      if (opsOpen) opsOpen.href = b.getAttribute('data-user') || '#';
      if (opsDebts) opsDebts.href = b.getAttribute('data-debts') || '#';
      drop.hidden = false;
      drop.classList.add('open');
      var r = b.getBoundingClientRect();
      drop.style.top = Math.round(r.bottom + 4) + 'px';
      drop.style.left = Math.round(Math.min(window.innerWidth - 200, Math.max(8, r.left))) + 'px';
      return;
    }
    if (!drop || (e.target.closest && e.target.closest('#schedOpsDrop'))) return;
    hideOps();
  });
})();
</script>
<?php render_footer(); ?>
