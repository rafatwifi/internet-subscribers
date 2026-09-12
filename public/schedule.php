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
    if ($section === 'schedule_run') {
        if (function_exists('run_schedule_debt_cuts') && !empty($s['schedule_cut_enabled'])) {
            $run = run_schedule_debt_cuts($pdo, $config, 100);
            flash('success', ($isEn ? 'Run: checked ' : 'تشغيل: فحص ')
                . (int) $run['checked']
                . ($isEn ? ' — cut ' : ' — قطع ')
                . (int) $run['cut']
                . ($isEn ? ' — WA ' : ' — واتساب ')
                . (int) $run['wa_sent']);
        } else {
            flash('error', $isEn ? 'Enable auto-cut first' : 'فعّل القطع التلقائي أولاً');
        }
        redirect('schedule.php');
    }
    if ($section === 'schedule') {
        $expDays = (int) post('expiry_auto_remind_days', '1');
        if ($expDays < 0) {
            $expDays = 0;
        }
        if ($expDays > 60) {
            $expDays = 60;
        }
        $afterDays = (int) post('unpaid_remind_after_days', '7');
        if ($afterDays < 1) {
            $afterDays = 1;
        }
        if ($afterDays > 365) {
            $afterDays = 365;
        }
        $cutOn = post('schedule_cut_enabled') === '1';
        $sendWa = $cutOn && post('schedule_cut_send_wa') === '1';
        $caseKeys = array('expiry_soon', 'schedule_cut', 'unpaid_overdue');
        $data = array(
            'schedule_cut_enabled' => $cutOn,
            'schedule_cut_send_wa' => $sendWa,
            'expiry_auto_remind_enabled' => post('expiry_auto_remind_enabled') === '1',
            'expiry_auto_remind_days' => $expDays,
            'unpaid_remind_enabled' => post('unpaid_remind_enabled') === '1',
            'unpaid_remind_after_days' => $afterDays,
        );
        foreach ($caseKeys as $ck) {
            $v = trim((string) post('wa_case_' . $ck, ''));
            if ($v === '' || $v === '__none__') {
                $data['wa_case_' . $ck] = '__none__';
            } else {
                $data['wa_case_' . $ck] = $v;
            }
        }
        if (settings_save($data)) {
            flash('success', t('saved'));
        } else {
            flash('error', 'Cannot write settings.json');
        }
        redirect('schedule.php');
    }
}

$preview = function_exists('schedule_debtors_list')
    ? schedule_debtors_list($pdo, $config, 500)
    : array('rows' => array(), 'error' => '', 'stats' => array());
$list = isset($preview['rows']) ? $preview['rows'] : array();
$queryError = isset($preview['error']) ? (string) $preview['error'] : '';
$stats = isset($preview['stats']) && is_array($preview['stats']) ? $preview['stats'] : array(
    'total' => count($list),
    'will_cut' => 0,
    'grace' => 0,
    'disabled' => 0,
    'debt_sum' => 0,
);

$cronCli = 'php ' . str_replace('\\', '/', dirname(__DIR__) . '/cron/schedule_cut.php');
$enabled = !empty($s['schedule_cut_enabled']);
$sendWa = !isset($s['schedule_cut_send_wa']) || !empty($s['schedule_cut_send_wa']);
$unpaidEnabled = !empty($s['unpaid_remind_enabled']);
$unpaidAfterDays = function_exists('unpaid_remind_after_days')
    ? unpaid_remind_after_days($config)
    : max(1, (int) (isset($s['unpaid_remind_after_days']) ? $s['unpaid_remind_after_days'] : 7));
$currency = isset($config['currency']) ? $config['currency'] : 'د.ع';

$waCatalog = isset($config['wa_templates']) && is_array($config['wa_templates'])
    ? $config['wa_templates']
    : array();
if (!$waCatalog && !empty($config['templates']) && is_array($config['templates'])) {
    foreach ($config['templates'] as $tk => $tbody) {
        $lab = (isset($config['template_labels'][$tk]) && $config['template_labels'][$tk] !== '')
            ? $config['template_labels'][$tk]
            : $tk;
        $waCatalog[$tk] = array('label' => $lab, 'body' => $tbody);
    }
}
$caseMap = isset($config['wa_cases']) && is_array($config['wa_cases']) ? $config['wa_cases'] : array();

$sched_sel = function ($caseKey) use ($s, $waCatalog, $caseMap) {
    $stored = isset($s['wa_case_' . $caseKey]) ? trim((string) $s['wa_case_' . $caseKey]) : null;
    if ($stored === '__none__') {
        return '__none__';
    }
    if ($stored !== null && $stored !== '' && isset($waCatalog[$stored])) {
        return $stored;
    }
    $sel = isset($caseMap[$caseKey]) ? (string) $caseMap[$caseKey] : '';
    if ($sel === '' || !isset($waCatalog[$sel])) {
        return '__none__';
    }
    return $sel;
};
$selExpiry = $sched_sel('expiry_soon');
$selCut = $sched_sel('schedule_cut');
$selUnpaid = $sched_sel('unpaid_overdue');
$chooseTpl = $isEn ? '— Choose template —' : '— اختر قالباً —';

$topTools = '<button type="button" class="btn ghost sm" id="schedSettingsBtn" title="'
    . e($isEn ? 'Schedule settings' : 'إعدادات الجدول الدوري') . '">⚙</button>';

render_header($isEn ? 'Periodic jobs' : 'الجدول الدوري', 'schedule', '', '', $topTools);
?>
<style>
.sched-page { max-width: 1200px; margin: 0 auto; }
.sched-hero {
  margin: 0 0 14px; padding: 18px 18px 16px; border-radius: 18px;
  background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 58%, #0f766e 140%);
  color: #fff; box-shadow: 0 12px 30px rgba(15,23,42,.18);
}
.sched-hero h2 { margin: 0 0 6px; font-size: 20px; font-weight: 800; letter-spacing: -.02em; }
.sched-hero p { margin: 0 0 14px; opacity: .9; font-size: 13px; font-weight: 600; max-width: 68ch; line-height: 1.55; }
.sched-hero-pills { display: flex; flex-wrap: wrap; gap: 8px; }
.sched-pill {
  display: inline-flex; align-items: center; gap: 6px; padding: 6px 11px; border-radius: 999px;
  font-size: 12px; font-weight: 800; border: 1px solid rgba(255,255,255,.22);
  background: rgba(255,255,255,.12); color: #fff;
}
.sched-pill.on { background: rgba(34,197,94,.22); border-color: rgba(134,239,172,.45); }
.sched-pill.off { background: rgba(244,63,94,.2); border-color: rgba(253,164,175,.45); }
.sched-stats {
  display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 0 0 12px;
}
.sched-stat {
  padding: 12px 14px; border-radius: 14px; background: rgba(255,255,255,.92);
  border: 1px solid rgba(15,23,42,.08); box-shadow: 0 6px 18px rgba(15,23,42,.04);
}
.sched-stat .k { display:block; font-size:11px; font-weight:800; color:#64748b; margin-bottom:4px; }
.sched-stat .v { display:block; font-size:20px; font-weight:800; color:#0f172a; line-height:1.1; }
.sched-stat.warn .v { color:#c2410c; }
.sched-stat.ok .v { color:#15803d; }
.sched-stat.bad .v { color:#b91c1c; }
.sched-toolbar {
  display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 10px;
  padding: 10px 12px; border-radius: 14px; background: rgba(255,255,255,.9);
  border: 1px solid rgba(15,23,42,.08);
}
.sched-search {
  flex: 1 1 180px; min-width: 160px; height: 38px; border: 1px solid #cbd5e1; border-radius: 10px;
  padding: 0 12px; font: inherit; font-size: 14px; background: #fff;
}
.sched-chips { display:inline-flex; flex-wrap:wrap; gap:6px; }
.sched-chip {
  border: 1px solid #cbd5e1; background:#fff; color:#334155; border-radius:999px;
  padding:6px 11px; font:inherit; font-size:12px; font-weight:800; cursor:pointer;
}
.sched-chip.is-on { background:#0f172a; border-color:#0f172a; color:#fff; }
.sched-table-wrap {
  overflow: auto; border: 1px solid rgba(15,23,42,.08); border-radius: 16px;
  background: rgba(255,255,255,.94); box-shadow: 0 8px 24px rgba(15,23,42,.05);
  max-height: calc(100vh - 280px);
}
.sched-table { width: 100%; border-collapse: collapse; min-width: 920px; }
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
.sched-table tr.is-hidden { display: none; }
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
.sched-name { font-weight: 800; color: #0f172a; text-decoration: none; }
.sched-name:hover { text-decoration: underline; }
.sched-user { font-size: 12px; color: #64748b; font-weight: 600; direction: ltr; unicode-bidi: isolate; text-decoration: none; }
.sched-meter {
  width: 88px; height: 7px; border-radius: 999px; background: #e2e8f0; overflow: hidden; margin-top: 5px;
}
.sched-meter > i { display:block; height:100%; background: linear-gradient(90deg, #22c55e, #f59e0b 70%, #ef4444); }
.sched-meter.is-over > i { background: #ea580c; width: 100% !important; }
.sched-empty {
  padding: 28px 16px; text-align: center; color: #64748b; font-weight: 700;
}
.sched-ops-drop {
  position: fixed; z-index: 90; min-width: 190px; background: #fff; border: 1px solid #e2e8f0;
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
  width: min(440px, 100%); background: #fff; height: 100%; overflow: auto;
  padding: 18px 16px 28px; box-shadow: -12px 0 40px rgba(15,23,42,.18);
}
body.rtl .sched-drawer { justify-content: flex-start; }
body.rtl .sched-drawer-panel { box-shadow: 12px 0 40px rgba(15,23,42,.18); }
.sched-drawer h2 { margin: 0 0 8px; font-size: 18px; }
.sched-drawer .meta { margin: 0 0 14px; color: #64748b; font-size: 13px; }
.sched-cron-row { display:flex; gap:8px; align-items:flex-start; }
.sched-cron-row code {
  flex:1; display:block; padding:8px 10px; border-radius:8px; background:#f8fafc; border:1px solid #e2e8f0;
  font-size:11px; word-break:break-all; direction:ltr;
}
@media (max-width: 900px) {
  .sched-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .sched-table-wrap { max-height: none; }
}
@media (max-width: 640px) {
  .sched-drawer-panel { width: 100%; }
  .sched-hero { padding: 14px; }
  .sched-toolbar { padding: 10px; }
}
</style>

<div class="sched-page">
  <div class="sched-hero">
    <h2><?php echo e($isEn ? 'Periodic schedule' : 'الجدول الدوري'); ?></h2>
    <p><?php echo e($isEn
        ? 'Track unpaid debtors against grace days — who stays online, who will be cut, and WhatsApp notices.'
        : 'متابعة المدينين غير المسددين مقابل أيام السماح — من ضمن السماح، من راح ينقطع، وإشعار واتساب.'); ?></p>
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
    </div>
  </div>

  <div class="sched-stats">
    <div class="sched-stat">
      <span class="k"><?php echo e($isEn ? 'Debtors' : 'مدينين'); ?></span>
      <span class="v"><?php echo (int) $stats['total']; ?></span>
    </div>
    <div class="sched-stat warn">
      <span class="k"><?php echo e($isEn ? 'Will be cut' : 'راح ينقطع'); ?></span>
      <span class="v"><?php echo (int) $stats['will_cut']; ?></span>
    </div>
    <div class="sched-stat ok">
      <span class="k"><?php echo e($isEn ? 'In grace' : 'ضمن السماح'); ?></span>
      <span class="v"><?php echo (int) $stats['grace']; ?></span>
    </div>
    <div class="sched-stat bad">
      <span class="k"><?php echo e($isEn ? 'Already off' : 'معطّل'); ?></span>
      <span class="v"><?php echo (int) $stats['disabled']; ?></span>
    </div>
  </div>

  <div class="sched-toolbar">
    <input type="search" class="sched-search" id="schedSearch"
      placeholder="<?php echo e($isEn ? 'Search name, user, phone…' : 'بحث بالاسم أو اليوزر أو الرقم…'); ?>"
      autocomplete="off">
    <div class="sched-chips" id="schedChips" role="tablist">
      <button type="button" class="sched-chip is-on" data-filter="all"><?php echo e($isEn ? 'All' : 'الكل'); ?></button>
      <button type="button" class="sched-chip" data-filter="will_cut"><?php echo e($isEn ? 'Will cut' : 'راح ينقطع'); ?></button>
      <button type="button" class="sched-chip" data-filter="grace"><?php echo e($isEn ? 'In grace' : 'ضمن السماح'); ?></button>
      <button type="button" class="sched-chip" data-filter="disabled"><?php echo e($isEn ? 'Disabled' : 'معطّل'); ?></button>
    </div>
    <label style="display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:13px;margin-inline-start:auto">
      <input type="checkbox" id="schedCheckAll">
      <?php echo e($isEn ? 'Select visible' : 'تحديد الظاهر'); ?>
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
          <th class="sched-check"><input type="checkbox" id="schedCheckAllHead" title="<?php echo e($isEn ? 'Select visible' : 'تحديد الظاهر'); ?>"></th>
          <th><?php echo e($isEn ? 'Subscriber' : 'المشترك'); ?></th>
          <th><?php echo e($isEn ? 'Username' : 'اليوزر'); ?></th>
          <th><?php echo e($isEn ? 'Debt' : 'الدين'); ?></th>
          <th><?php echo e($isEn ? 'Days / grace' : 'مضى / السماح'); ?></th>
          <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
          <th class="sched-ops"><?php echo e($isEn ? 'Ops' : 'عمليات'); ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$list): ?>
        <tr data-empty="1"><td colspan="8"><div class="sched-empty"><?php echo e($isEn ? 'No unpaid debtors found.' : 'ماكو مدينين غير مسددين.'); ?></div></td></tr>
      <?php endif; ?>
      <?php $rowNum = 0; foreach ($list as $r): $rowNum++; ?>
        <?php
          $cls = '';
          if ((int) $r['enabled'] === 0) {
              $cls = 'cut-done';
          } elseif (!empty($r['will_cut'])) {
              $cls = 'will-cut';
          }
          $userUrl = $r['username'] !== ''
              ? ('sas_user.php?u=' . rawurlencode($r['username']))
              : ('subscriber.php?id=' . (int) $r['id']);
          $debtsUrl = 'debts.php?status=unpaid&subscriber_id=' . (int) $r['id'];
          $search = strtolower(
              (string) $r['name'] . ' ' . (string) $r['username'] . ' ' . preg_replace('/\D+/', '', (string) $r['phone'])
          );
          $status = isset($r['status']) ? $r['status'] : 'grace';
          $pct = isset($r['grace_pct']) ? (int) $r['grace_pct'] : 0;
        ?>
        <tr class="<?php echo e($cls); ?>"
            data-sid="<?php echo (int) $r['id']; ?>"
            data-status="<?php echo e($status); ?>"
            data-search="<?php echo e($search); ?>">
          <td class="sched-num"><?php echo (int) $rowNum; ?></td>
          <td class="sched-check"><input type="checkbox" class="sched-row-check" value="<?php echo (int) $r['id']; ?>"></td>
          <td>
            <a class="sched-name" href="<?php echo e($userUrl); ?>"><?php echo e($r['name'] !== '' ? $r['name'] : $r['username']); ?></a>
            <?php if ($r['phone'] !== ''): ?><div class="meta" style="margin:2px 0 0;direction:ltr;text-align:left"><?php echo e(format_phone_display($r['phone'])); ?></div><?php endif; ?>
          </td>
          <td><?php if ($r['username'] !== ''): ?><a class="sched-user" href="<?php echo e($userUrl); ?>"><?php echo e($r['username']); ?></a><?php else: ?>—<?php endif; ?></td>
          <td><strong><?php echo e(money_format_iqd($r['debt'], $currency)); ?></strong></td>
          <td>
            <div><strong><?php echo (int) $r['days_passed']; ?></strong> / <?php echo (int) $r['grace']; ?></div>
            <div class="sched-meter<?php echo !empty($r['will_cut']) ? ' is-over' : ''; ?>" title="<?php echo e($isEn ? 'Grace progress' : 'تقدم السماح'); ?>">
              <i style="width:<?php echo max(4, min(100, $pct)); ?>%"></i>
            </div>
          </td>
          <td>
            <?php if ($status === 'disabled'): ?>
              <span class="sched-badge bad"><?php echo e($isEn ? 'Already disabled' : 'معطّل حالياً'); ?></span>
            <?php elseif ($status === 'will_cut'): ?>
              <span class="sched-badge warn"><?php echo e($isEn ? 'Will be cut' : 'راح ينقطع'); ?></span>
              <span class="sched-badge muted"><?php echo e($sendWa ? ($isEn ? 'WA: yes' : 'واتساب: نعم') : ($isEn ? 'WA: no' : 'واتساب: لا')); ?></span>
            <?php else: ?>
              <span class="sched-badge ok"><?php echo e($isEn ? ('In grace (' . (int) $r['days_left'] . ' left)') : ('ضمن السماح (باقي ' . (int) $r['days_left'] . ')')); ?></span>
            <?php endif; ?>
          </td>
          <td class="sched-ops">
            <button type="button" class="btn ghost sm sched-ops-btn"
              data-user="<?php echo e($userUrl); ?>"
              data-debts="<?php echo e($debtsUrl); ?>"
              aria-label="<?php echo e($isEn ? 'Actions' : 'عمليات'); ?>">⋯</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="meta" id="schedVisibleHint" style="margin:8px 0 0"></p>
</div>

<div class="sched-ops-drop" id="schedOpsDrop" hidden>
  <a href="#" id="schedOpsOpen"><?php echo e($isEn ? 'Open subscriber' : 'فتح المشترك'); ?></a>
  <a href="#" id="schedOpsDebts"><?php echo e($isEn ? 'Debts' : 'الديون'); ?></a>
</div>

<div class="sched-drawer" id="schedDrawer" aria-hidden="true">
  <div class="sched-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="schedDrawerTitle">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px">
      <h2 id="schedDrawerTitle"><?php echo e($isEn ? 'Schedule settings' : 'إعدادات الجدول الدوري'); ?></h2>
      <button type="button" class="btn ghost sm" id="schedDrawerClose" aria-label="<?php echo e($isEn ? 'Close' : 'إغلاق'); ?>">×</button>
    </div>
    <p class="meta" style="margin:0 0 12px"><?php echo e($isEn
        ? 'One save for all options below. Templates are chosen from Messages → Templates.'
        : 'حفظ واحد لكل الخيارات أدناه. القوالب تُختار من الرسائل → القوالب.'); ?></p>
    <form method="post" id="schedSettingsForm">
      <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
      <input type="hidden" name="section" value="schedule">

      <div class="sched-set-block" style="margin:0 0 12px;padding:12px;border:1px solid #e2e8f0;border-radius:10px">
        <label class="toggle" style="display:flex;align-items:center;gap:10px;margin:0">
          <input type="checkbox" id="expiryAutoToggle" name="expiry_auto_remind_enabled" value="1"
            <?php echo !empty($s['expiry_auto_remind_enabled']) ? 'checked' : ''; ?>>
          <span class="toggle-ui"></span>
          <span><strong><?php echo e($isEn ? 'Expiry reminder' : 'تذكير انتهاء الاشتراك'); ?></strong></span>
        </label>
        <div id="expiryAutoFields" style="display:none;margin-top:12px">
          <label><?php echo e($isEn ? 'Days before end' : 'قبل الانتهاء بـ (يوم)'); ?></label>
          <input type="number" min="0" max="60" name="expiry_auto_remind_days"
            value="<?php echo (int) (isset($s['expiry_auto_remind_days']) ? $s['expiry_auto_remind_days'] : 1); ?>"
            style="width:100%;max-width:120px;margin-bottom:10px">
          <label><?php echo e($isEn ? 'Template' : 'القالب'); ?></label>
          <select name="wa_case_expiry_soon" style="width:100%">
            <option value="__none__"<?php echo $selExpiry === '__none__' ? ' selected' : ''; ?>><?php echo e($chooseTpl); ?></option>
            <?php foreach ($waCatalog as $tk => $trow): ?>
              <option value="<?php echo e($tk); ?>"<?php echo $selExpiry === $tk ? ' selected' : ''; ?>><?php echo e(isset($trow['label']) ? $trow['label'] : $tk); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="meta" style="margin:6px 0 0">{name} {days} {package} {to}</p>
        </div>
      </div>

      <div class="sched-set-block" style="margin:0 0 12px;padding:12px;border:1px solid #e2e8f0;border-radius:10px">
        <label class="toggle" style="display:flex;align-items:center;gap:10px;margin:0">
          <input type="checkbox" id="cutToggle" name="schedule_cut_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
          <span class="toggle-ui"></span>
          <span><strong><?php echo e($isEn ? 'Auto-cut after grace' : 'قطع الاشتراك بعد أيام السماح'); ?></strong></span>
        </label>
        <p class="meta" style="margin:8px 0 0"><?php echo e($isEn
            ? ('Uses each subscriber grace (system default: ' . $sysGrace . ' days).')
            : ('يعتمد أيام السماح لكل مشترك أو الافتراضي: ' . $sysGrace . ' يوم.')); ?></p>
        <div id="cutFields" style="display:none;margin-top:12px">
          <label class="toggle" style="display:flex;align-items:center;gap:10px;padding:10px;border:1px solid #eef2f7;border-radius:8px;margin-bottom:10px">
            <input type="checkbox" id="cutWaToggle" name="schedule_cut_send_wa" value="1" <?php echo $sendWa ? 'checked' : ''; ?>>
            <span class="toggle-ui"></span>
            <span><strong><?php echo e($isEn ? 'Send WhatsApp on cut' : 'إرسال رسالة عند القطع'); ?></strong></span>
          </label>
          <div id="cutTplFields" style="display:none">
            <label><?php echo e($isEn ? 'Cut message template' : 'قالب رسالة القطع'); ?></label>
            <select name="wa_case_schedule_cut" style="width:100%">
              <option value="__none__"<?php echo $selCut === '__none__' ? ' selected' : ''; ?>><?php echo e($chooseTpl); ?></option>
              <?php foreach ($waCatalog as $tk => $trow): ?>
                <option value="<?php echo e($tk); ?>"<?php echo $selCut === $tk ? ' selected' : ''; ?>><?php echo e(isset($trow['label']) ? $trow['label'] : $tk); ?></option>
              <?php endforeach; ?>
            </select>
            <p class="meta" style="margin:6px 0 0">{name} {debt} {grace} {package} {month}</p>
          </div>
        </div>
      </div>

      <div class="sched-set-block" style="margin:0 0 12px;padding:12px;border:1px solid #e2e8f0;border-radius:10px">
        <label class="toggle" style="display:flex;align-items:center;gap:10px;margin:0">
          <input type="checkbox" id="unpaidToggle" name="unpaid_remind_enabled" value="1" <?php echo $unpaidEnabled ? 'checked' : ''; ?>>
          <span class="toggle-ui"></span>
          <span><strong><?php echo e($isEn ? 'Warn after N days from activation' : 'تنبيه بعد * يوم من تفعيل الاشتراك'); ?></strong></span>
        </label>
        <div id="unpaidFields" style="display:none;margin-top:12px">
          <label><?php echo e($isEn ? 'Days after activation' : 'بعد كم يوم من التفعيل'); ?></label>
          <input type="number" min="1" max="365" name="unpaid_remind_after_days"
            value="<?php echo (int) $unpaidAfterDays; ?>" style="width:100%;max-width:120px;margin-bottom:10px">
          <label><?php echo e($isEn ? 'Template' : 'القالب'); ?></label>
          <select name="wa_case_unpaid_overdue" style="width:100%">
            <option value="__none__"<?php echo $selUnpaid === '__none__' ? ' selected' : ''; ?>><?php echo e($chooseTpl); ?></option>
            <?php foreach ($waCatalog as $tk => $trow): ?>
              <option value="<?php echo e($tk); ?>"<?php echo $selUnpaid === $tk ? ' selected' : ''; ?>><?php echo e(isset($trow['label']) ? $trow['label'] : $tk); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="meta" style="margin:6px 0 0">{name} {days_passed} {debt} {package}</p>
          <p class="meta" style="margin:6px 0 0"><?php echo e($isEn
              ? 'Used when sending bulk late payers from Messages.'
              : 'يُستخدم عند الإرسال الجماعي للمتأخرين من تبويب الرسائل.'); ?></p>
        </div>
      </div>

      <div class="actions" style="margin:4px 0 14px">
        <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
      </div>
    </form>

    <div style="padding:12px;border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc">
      <strong style="display:block;margin-bottom:6px"><?php echo e($isEn ? 'What is Cron?' : 'شنو الكرون؟'); ?></strong>
      <p class="meta" style="margin:0 0 8px"><?php echo e($isEn
          ? 'Cron is an automatic job on the server (e.g. every hour). It runs auto-cut even when nobody opens the admin panel. Ask your host to add this command:'
          : 'الكرون مهمة تلقائية على السيرفر (مثلاً كل ساعة). يشغّل القطع التلقائي حتى لو ما أحد فتح لوحة التحكم. اطلب من الاستضافة إضافة هذا الأمر:'); ?></p>
      <code class="ltr" id="schedCronCmd" style="display:block;word-break:break-all;font-size:12px;padding:8px;background:#fff;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:8px"><?php echo e($cronCli); ?></code>
      <button type="button" class="btn ghost sm" id="schedCopyCron"><?php echo e($isEn ? 'Copy command' : 'نسخ الأمر'); ?></button>
      <p class="meta" style="margin:8px 0 0"><?php echo e($isEn
          ? 'Put this command in your hosting Cron Jobs (hourly recommended). If you have no cron access, use Run once now below when needed.'
          : 'ضع هذا الأمر في Cron Jobs بالاستضافة (يفضّل كل ساعة). إذا ما عندك صلاحية كرون، استخدم «تشغيل مرة الآن» عند الحاجة.'); ?></p>
      <?php if ($enabled && function_exists('run_schedule_debt_cuts')): ?>
        <form method="post" style="margin-top:10px" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Run auto-cut once now?' : 'تشغّل القطع التلقائي مرة الآن؟'); ?>);">
          <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="section" value="schedule_run">
          <button class="btn ghost" type="submit"><?php echo e($isEn ? 'Run once now' : 'تشغيل مرة الآن'); ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
(function () {
  function sync() {
    var expOn = document.getElementById('expiryAutoToggle');
    var expF = document.getElementById('expiryAutoFields');
    if (expF) expF.style.display = (expOn && expOn.checked) ? 'block' : 'none';
    var cutOn = document.getElementById('cutToggle');
    var cutF = document.getElementById('cutFields');
    var waOn = document.getElementById('cutWaToggle');
    var tplF = document.getElementById('cutTplFields');
    if (cutF) cutF.style.display = (cutOn && cutOn.checked) ? 'block' : 'none';
    if (tplF) tplF.style.display = (cutOn && cutOn.checked && waOn && waOn.checked) ? 'block' : 'none';
    var unOn = document.getElementById('unpaidToggle');
    var unF = document.getElementById('unpaidFields');
    if (unF) unF.style.display = (unOn && unOn.checked) ? 'block' : 'none';
  }
  ['expiryAutoToggle', 'cutToggle', 'cutWaToggle', 'unpaidToggle'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', sync);
  });
  sync();
})();
(function () {
  var btn = document.getElementById('schedSettingsBtn');
  var drawer = document.getElementById('schedDrawer');
  var closeBtn = document.getElementById('schedDrawerClose');
  var search = document.getElementById('schedSearch');
  var chips = document.getElementById('schedChips');
  var filter = 'all';
  var labels = {
    selected: <?php echo json_encode($isEn ? 'selected' : 'محدد'); ?>,
    visible: <?php echo json_encode($isEn ? 'showing' : 'ظاهر'); ?>,
    copied: <?php echo json_encode($isEn ? 'Copied' : 'تم النسخ'); ?>
  };

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
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      close();
      hideOps();
    }
  });

  function visibleRows() {
    return Array.prototype.slice.call(document.querySelectorAll('#schedTable tbody tr[data-sid]:not(.is-hidden)'));
  }
  function applyFilter() {
    var q = search ? String(search.value || '').toLowerCase().replace(/\s+/g, ' ').trim() : '';
    var qDigits = q.replace(/\D+/g, '');
    var rows = document.querySelectorAll('#schedTable tbody tr[data-sid]');
    var shown = 0;
    for (var i = 0; i < rows.length; i++) {
      var tr = rows[i];
      var st = tr.getAttribute('data-status') || '';
      var hay = tr.getAttribute('data-search') || '';
      var okFilter = (filter === 'all' || st === filter);
      var okSearch = true;
      if (q) {
        okSearch = hay.indexOf(q) !== -1 || (qDigits && hay.indexOf(qDigits) !== -1);
      }
      var on = okFilter && okSearch;
      tr.classList.toggle('is-hidden', !on);
      if (on) shown++;
    }
    var hint = document.getElementById('schedVisibleHint');
    if (hint) {
      hint.textContent = shown + ' / ' + rows.length + ' ' + labels.visible;
    }
    updateHint();
  }
  if (search) {
    var tmr = null;
    search.addEventListener('input', function () {
      clearTimeout(tmr);
      tmr = setTimeout(applyFilter, 120);
    });
  }
  if (chips) {
    chips.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('.sched-chip') : null;
      if (!b) return;
      filter = b.getAttribute('data-filter') || 'all';
      chips.querySelectorAll('.sched-chip').forEach(function (c) {
        c.classList.toggle('is-on', c === b);
      });
      applyFilter();
    });
  }

  function syncChecks(master) {
    visibleRows().forEach(function (tr) {
      var c = tr.querySelector('.sched-row-check');
      if (!c) return;
      c.checked = !!master.checked;
      tr.classList.toggle('is-checked', c.checked);
    });
    var all = document.getElementById('schedCheckAll');
    var head = document.getElementById('schedCheckAllHead');
    if (all && all !== master) all.checked = !!master.checked;
    if (head && head !== master) head.checked = !!master.checked;
    updateHint();
  }
  function updateHint() {
    var n = document.querySelectorAll('#schedTable tbody tr[data-sid]:not(.is-hidden) .sched-row-check:checked').length;
    var hint = document.getElementById('schedSelectedHint');
    if (hint) hint.textContent = n ? (n + ' ' + labels.selected) : '';
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
      var left = r.left;
      if (document.documentElement.dir === 'rtl') left = Math.max(8, r.right - 200);
      drop.style.top = Math.round(r.bottom + 4) + 'px';
      drop.style.left = Math.round(Math.min(window.innerWidth - 200, Math.max(8, left))) + 'px';
      return;
    }
    if (!drop || (e.target.closest && e.target.closest('#schedOpsDrop'))) return;
    hideOps();
  });

  var copyBtn = document.getElementById('schedCopyCron');
  var cronEl = document.getElementById('schedCronCmd');
  if (copyBtn && cronEl) {
    copyBtn.addEventListener('click', function () {
      var txt = cronEl.textContent || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(function () {
          copyBtn.textContent = labels.copied;
          setTimeout(function () { copyBtn.textContent = <?php echo json_encode($isEn ? 'Copy' : 'نسخ'); ?>; }, 1200);
        }).catch(function () {});
      }
    });
  }

  applyFilter();
})();
</script>
<?php render_footer(); ?>
