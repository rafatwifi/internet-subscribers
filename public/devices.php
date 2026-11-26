<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_login();
require_perm('devices');

if (function_exists('devices_system_is_on') && !devices_system_is_on()) {
    flash('error', (isset($lang) && $lang === 'en') ? 'Network devices are off' : 'قسم أجهزة الشبكة متوقف');
    redirect('index.php');
}

ensure_network_devices_schema($pdo);
$isEn = (isset($lang) && $lang === 'en');

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $op = (string) $_GET['ajax'];
    if ($op === 'live_one') {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $row = devices_get($pdo, $id);
        if (!$row) {
            echo json_encode(array('ok' => false, 'error' => 'missing'));
            exit;
        }
        $doPoll = !isset($_GET['poll']) || (string) $_GET['poll'] !== '0';
        $meta = array();
        $st = isset($row['status']) ? (string) $row['status'] : 'unknown';
        if ($doPoll) {
            list($okP, $st, $meta) = devices_poll($pdo, $row);
            $row = devices_get($pdo, $id);
            unset($okP);
        }
        $pub = devices_row_public($row);
        $linked = devices_subscribers_for_device($pdo, $row);
        $linkedOut = array();
        foreach ($linked as $ls) {
            $linkedOut[] = array(
                'username' => isset($ls['username']) ? (string) $ls['username'] : '',
                'display_name' => trim((string) (isset($ls['display_name']) ? $ls['display_name'] : (isset($ls['firstname']) ? $ls['firstname'] : ''))),
                'mac_address' => isset($ls['mac_address']) ? (string) $ls['mac_address'] : '',
            );
        }
        echo json_encode(array(
            'ok' => true,
            'status' => $st,
            'device' => $pub,
            'live' => isset($meta['live']) ? $meta['live'] : null,
            'linked' => $linkedOut,
            'labels' => array(
                'status' => devices_status_label($st, $lang),
                'type' => devices_type_label($row['device_type'], $lang),
                'role' => devices_role_label($row['device_role'], $lang),
                'login' => devices_login_label(isset($pub['login_ok']) ? $pub['login_ok'] : null, $lang),
            ),
        ));
        exit;
    }
    if ($op === 'live_table') {
        $filters = array(
            'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
            'type' => isset($_GET['type']) ? (string) $_GET['type'] : '',
            'role' => isset($_GET['role']) ? (string) $_GET['role'] : '',
            'status' => isset($_GET['status']) ? (string) $_GET['status'] : '',
            'agent' => isset($_GET['agent']) ? (string) $_GET['agent'] : '',
        );
        $doPoll = isset($_GET['poll']) && (string) $_GET['poll'] === '1';
        $rows = devices_list($pdo, $filters);
        if ($doPoll) {
            $n = 0;
            foreach ($rows as $row) {
                devices_poll($pdo, $row);
                $n++;
                if ($n >= 20) {
                    break;
                }
            }
            $rows = devices_list($pdo, $filters);
        }
        $out = array();
        foreach ($rows as $r) {
            $out[] = devices_row_public($r);
        }
        echo json_encode(array(
            'ok' => true,
            'rows' => $out,
            'stats' => devices_count_by_status($pdo),
        ));
        exit;
    }
    echo json_encode(array('ok' => false, 'error' => 'bad_op'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(post('csrf'))) {
        flash('error', $isEn ? 'Invalid request' : 'طلب غير صالح');
        redirect('devices.php');
    }
    $action = post('action');

    if ($action === 'save') {
        $id = (int) post('id', '0');
        $devType = post('device_type', 'nanostation');
        $fields = array(
            'name' => post('name', ''),
            'device_type' => $devType,
            'device_role' => post('device_role', 'ap'),
            'mgmt_ip' => post('mgmt_ip', ''),
            'username' => ($devType === 'mimosa') ? '' : post('username', ''),
            'password' => post('password', ''),
            'mac' => '',
            'notes' => post('notes', ''),
            'agent_user_id' => (int) post('agent_user_id', '0'),
        );
        list($ok, $err, $newId) = devices_save($pdo, $id, $fields);
        if ($ok) {
            if (function_exists('activity_log')) {
                activity_log(
                    $pdo,
                    0,
                    'network_device',
                    (int) $newId,
                    $id > 0 ? 'device_edit' : 'device_add',
                    ($id > 0 ? 'تعديل جهاز: ' : 'إضافة جهاز: ') . $fields['name'],
                    $fields['mgmt_ip']
                );
            }
            $row = devices_get($pdo, (int) $newId);
            $extra = '';
            if ($row) {
                list($okP, $st, $meta) = devices_poll($pdo, $row);
                $row = devices_get($pdo, (int) $newId);
                $extra = devices_poll_flash_extra($row, $meta, $isEn);
                unset($okP, $st);
            }
            flash('success', ($isEn ? 'Saved' : 'تم الحفظ') . $extra);
            redirect('devices.php?id=' . (int) $newId);
        }
        $msg = $isEn ? 'Could not save' : 'ما انحفظ';
        if ($err === 'ip') {
            $msg = $isEn ? 'Valid IP required' : 'آيبـي صحيح مطلوب';
        } elseif ($err === 'name') {
            $msg = $isEn ? 'Name required' : 'الاسم مطلوب';
        }
        flash('error', $msg);
        redirect('devices.php' . ($id > 0 ? ('?edit=' . $id) : '?new=1'));
    }

    if ($action === 'delete') {
        $id = (int) post('id', '0');
        $row = devices_get($pdo, $id);
        if ($row && devices_delete($pdo, $id)) {
            if (function_exists('activity_log')) {
                activity_log($pdo, 0, 'network_device', $id, 'device_delete', 'حذف جهاز: ' . $row['name'], $row['mgmt_ip']);
            }
            flash('success', $isEn ? 'Deleted' : 'تم الحذف');
        } else {
            flash('error', $isEn ? 'Not found' : 'ماكو جهاز');
        }
        redirect('devices.php');
    }

    if ($action === 'transfer') {
        $id = (int) post('id', '0');
        $to = (int) post('to_agent', '0');
        if (devices_transfer($pdo, $id, $to)) {
            if (function_exists('activity_log')) {
                activity_log($pdo, 0, 'network_device', $id, 'device_transfer', 'نقل جهاز لوكيل', 'agent=' . $to);
            }
            flash('success', $isEn ? 'Transferred' : 'تم النقل');
        } else {
            flash('error', $isEn ? 'Transfer failed' : 'فشل النقل');
        }
        redirect('devices.php?id=' . $id);
    }

    if ($action === 'import') {
        $csv = '';
        if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $csv = file_get_contents($_FILES['csv']['tmp_name']);
        } else {
            $csv = (string) post('csv_text', '');
        }
        list($nOk, $nFail, $why) = devices_import_csv($pdo, $csv);
        if ($why === 'header' || $why === 'empty') {
            flash('error', $isEn ? 'Bad CSV' : 'ملف CSV غير صالح');
        } else {
            flash('success', ($isEn ? 'Imported: ' : 'تم الاستيراد: ') . $nOk . ($nFail ? ((' / ' . ($isEn ? 'failed ' : 'فشل ') . $nFail)) : ''));
        }
        redirect('devices.php');
    }

    if ($action === 'poll_one') {
        $id = (int) post('id', '0');
        $row = devices_get($pdo, $id);
        if ($row) {
            list($okP, $st, $meta) = devices_poll($pdo, $row);
            $row = devices_get($pdo, $id);
            $extra = devices_poll_flash_extra($row, isset($meta) ? $meta : array(), $isEn);
            flash($okP ? 'success' : 'error', $okP
                ? (($isEn ? 'Updated: ' : 'تحديث: ') . devices_status_label($st, $lang) . $extra)
                : ($isEn ? 'Poll failed' : 'فشل التحديث'));
            redirect('devices.php?id=' . $id);
        }
        redirect('devices.php');
    }

    if ($action === 'poll_all') {
        $rows = devices_list($pdo, array());
        $n = 0;
        foreach ($rows as $row) {
            list($okP) = devices_poll($pdo, $row);
            if ($okP) {
                $n++;
            }
            if ($n >= 40) {
                break;
            }
        }
        flash('success', ($isEn ? 'Polled: ' : 'تم فحص: ') . $n);
        redirect('devices.php');
    }

    if ($action === 'apply_freq') {
        $id = (int) post('id', '0');
        $row = devices_get($pdo, $id);
        if ($row) {
            list($okA, $errA) = devices_apply_best_freq($pdo, $row);
            flash($okA ? 'success' : 'error', $okA
                ? ($isEn ? 'Frequency applied' : 'تم ضبط التردد')
                : (($isEn ? 'Could not set frequency' : 'ما انضبط التردد') . ($errA ? (': ' . $errA) : '')));
            redirect('devices.php?id=' . $id);
        }
        redirect('devices.php');
    }

    redirect('devices.php');
}

if (isset($_GET['export']) && (string) $_GET['export'] === '1') {
    $csv = devices_export_csv($pdo, array(
        'q' => isset($_GET['q']) ? (string) $_GET['q'] : '',
        'type' => isset($_GET['type']) ? (string) $_GET['type'] : '',
        'role' => isset($_GET['role']) ? (string) $_GET['role'] : '',
        'status' => isset($_GET['status']) ? (string) $_GET['status'] : '',
        'agent' => isset($_GET['agent']) ? (string) $_GET['agent'] : '',
    ));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="network-devices.csv"');
    echo "\xEF\xBB\xBF";
    echo $csv;
    exit;
}

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$filterType = isset($_GET['type']) ? (string) $_GET['type'] : '';
$filterRole = isset($_GET['role']) ? (string) $_GET['role'] : '';
$filterStatus = isset($_GET['status']) ? (string) $_GET['status'] : '';
$filterAgent = isset($_GET['agent']) ? (string) $_GET['agent'] : '';
$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$isNew = isset($_GET['new']) && (string) $_GET['new'] === '1';

$filters = array(
    'q' => $q,
    'type' => $filterType,
    'role' => $filterRole,
    'status' => $filterStatus,
    'agent' => $filterAgent,
);
$rows = devices_list($pdo, $filters);
$stats = devices_count_by_status($pdo);
$agents = devices_agents_for_select($pdo);
$types = devices_types();
$roles = devices_roles();
$statuses = devices_statuses();

$editRow = null;
if ($editId > 0) {
    $editRow = devices_get($pdo, $editId);
}
$viewRow = null;
if ($viewId > 0 && !$editRow && !$isNew) {
    $viewRow = devices_get($pdo, $viewId);
}
$linkedSubs = ($viewRow && function_exists('devices_subscribers_for_device'))
    ? devices_subscribers_for_device($pdo, $viewRow)
    : array();

$formRow = $editRow ? $editRow : array(
    'id' => 0,
    'name' => '',
    'device_type' => 'nanostation',
    'device_role' => 'ap',
    'mgmt_ip' => '',
    'username' => '',
    'password' => '',
    'mac' => '',
    'notes' => '',
    'agent_user_id' => 0,
);
if (function_exists('is_agent_user') && is_agent_user() && function_exists('current_admin')) {
    $formMe = current_admin();
    if ($formMe) {
        $formRow['agent_user_id'] = (int) $formMe['id'];
    }
}
$showForm = $isNew || $editRow;
$detailOnly = ($viewRow && !$showForm);

render_header($detailOnly
    ? (($isEn ? 'Device · ' : 'جهاز · ') . $viewRow['name'])
    : ($isEn ? 'Network devices' : 'أجهزة الشبكة'), 'devices');
?>
<style>
.dev-stats { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:8px; margin:0 0 12px; }
@media (max-width:900px){ .dev-stats{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
.dev-stat { border-radius:12px; padding:10px 12px; background:rgba(15,23,42,.04); }
.dev-stat b { display:block; font-size:20px; font-weight:800; }
.dev-stat span { font-size:11px; font-weight:700; color:#64748b; }
.dev-stat.is-ok b { color:#15803d; }
.dev-stat.is-full b { color:#c2410c; }
.dev-stat.is-timeout b { color:#b45309; }
.dev-stat.is-off b { color:#be123c; }
#devicesTable.table-compact th,#devicesTable.table-compact td{
  padding:6px 8px!important; font-size:12.5px!important; line-height:1.25!important;
  white-space:nowrap; vertical-align:middle!important;
}
#devicesTable .dev-name{ font-weight:800; color:#1c4fd8; text-decoration:none; }
#devicesTable .dev-name:hover{ text-decoration:underline; }
.badge{ display:inline-block; padding:2px 7px; border-radius:6px; font-size:11px; font-weight:800; }
.badge.ok{ background:rgba(34,197,94,.15); color:#15803d; }
.badge.full_load{ background:rgba(249,115,22,.18); color:#c2410c; }
.badge.timeout{ background:rgba(234,179,8,.2); color:#a16207; }
.badge.offline{ background:rgba(244,63,94,.16); color:#be123c; }
.badge.unknown{ background:rgba(100,116,139,.14); color:#475569; }
.dev-ico{
  display:inline-flex; align-items:center; justify-content:center;
  width:22px; height:22px; border-radius:6px; font-size:10px; font-weight:800; color:#0f172a;
  margin-inline-end:4px; vertical-align:middle;
}
.dev-ico.powerbeam{ background:#93c5fd; }
.dev-ico.litebeam{ background:#86efac; }
.dev-ico.nanostation{ background:#fdba74; }
.dev-ico.mimosa{ background:#c4b5fd; }
.dev-ico.mikrotik{ background:#67e8f9; }
.dev-actions{ display:inline-flex; gap:6px; flex-wrap:wrap; align-items:center; }
.dev-actions form{ display:inline; margin:0; }
.dev-panel{ margin-top:12px; }
.dev-radio{ font-variant-numeric:tabular-nums; font-weight:700; color:#334155; }
.dev-adv-filter{
  display:flex; flex-wrap:wrap; gap:8px; align-items:center;
  margin:0 0 12px; padding:10px; border-radius:12px;
  background:#fff; border:1px solid #e2e8f0;
}
.dev-adv-filter input[type="search"],
.dev-adv-filter input[name="q"],
.dev-adv-filter select{
  min-height:38px; border:1px solid #d8dee8; border-radius:10px;
  padding:6px 12px; font:inherit; font-weight:700; background:#fff; color:#334155;
}
.dev-adv-filter input[name="q"]{ flex:1 1 220px; min-width:180px; }
.dev-adv-filter select{ flex:0 1 140px; min-width:120px; }
.dev-adv-filter .btn{ border-radius:10px; min-height:38px; padding:0 16px; font-weight:800; }
.dev-live-bar{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 10px; }
.dev-live-bar .live-chip{
  border:1px solid #d8dee8; background:#fff; border-radius:999px;
  padding:5px 12px; font-size:12px; font-weight:800; cursor:pointer; color:#475569;
}
.dev-live-bar .live-chip.is-on{ background:#16a34a; border-color:#16a34a; color:#fff; }
.dev-live-bar .live-chip.is-mid{ background:#0ea5e9; border-color:#0ea5e9; color:#fff; }
.dev-detail-top{
  display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center;
  margin:0 0 14px;
}
.dev-hero{
  display:grid; grid-template-columns:1.1fr 1fr; gap:12px; margin:0 0 12px;
}
@media (max-width:980px){ .dev-hero{ grid-template-columns:1fr; } }
.dev-card{
  border-radius:16px; padding:14px 16px; color:#fff; position:relative; overflow:hidden;
  box-shadow:0 10px 28px rgba(15,23,42,.12);
}
.dev-card h3{ margin:0 0 8px; font-size:14px; font-weight:800; opacity:.92; }
.dev-card .big{ font-size:34px; font-weight:900; letter-spacing:-.02em; line-height:1.1; }
.dev-card .sub{ margin-top:6px; font-size:12px; font-weight:700; opacity:.9; }
.dev-card.signal{ background:linear-gradient(135deg,#0ea5e9,#2563eb 55%,#4f46e5); }
.dev-card.noise{ background:linear-gradient(135deg,#f59e0b,#ea580c); }
.dev-card.ping{ background:linear-gradient(135deg,#10b981,#059669); }
.dev-card.freq{ background:linear-gradient(135deg,#a855f7,#7c3aed); }
.dev-card.status{ background:linear-gradient(135deg,#334155,#0f172a); }
.dev-meter-wrap{ margin-top:10px; height:10px; border-radius:999px; background:rgba(255,255,255,.22); overflow:hidden; }
.dev-meter{ height:100%; width:0; border-radius:999px; background:#fff; transition:width .45s ease; }
.dev-grid4{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin:0 0 12px; }
@media (max-width:900px){ .dev-grid4{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
.dev-mini{
  border-radius:14px; padding:12px; background:#fff; border:1px solid #e2e8f0;
}
.dev-mini b{ display:block; font-size:11px; color:#64748b; font-weight:800; margin-bottom:4px; }
.dev-mini span{ font-size:16px; font-weight:900; color:#0f172a; font-variant-numeric:tabular-nums; }
.dev-spec{
  border-radius:16px; padding:14px; background:#0b1220; color:#e2e8f0; margin:0 0 12px;
  border:1px solid #1e293b;
}
.dev-spec .head{ display:flex; justify-content:space-between; gap:8px; align-items:center; margin-bottom:10px; }
.dev-spec h3{ margin:0; font-size:15px; font-weight:900; color:#7dd3fc; }
.dev-spec canvas{ width:100%; height:220px; display:block; border-radius:10px; background:#020617; }
.dev-pulse{ display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:800; color:#86efac; }
.dev-pulse i{ width:8px; height:8px; border-radius:50%; background:#22c55e; box-shadow:0 0 0 0 rgba(34,197,94,.7); animation:devPulse 1.4s infinite; }
@keyframes devPulse{
  0%{ box-shadow:0 0 0 0 rgba(34,197,94,.55); }
  70%{ box-shadow:0 0 0 10px rgba(34,197,94,0); }
  100%{ box-shadow:0 0 0 0 rgba(34,197,94,0); }
}
.dev-sta-table td,.dev-sta-table th{ font-size:12.5px!important; }
</style>

<?php if ($detailOnly):
    $vPing = (isset($viewRow['ping_ms']) && $viewRow['ping_ms'] !== null && $viewRow['ping_ms'] !== '')
        ? round((float) $viewRow['ping_ms'], 1)
        : null;
    $vLogin = array_key_exists('login_ok', $viewRow) ? $viewRow['login_ok'] : null;
    $viewStations = array();
    if (!empty($viewRow['station_macs'])) {
        $viewStations = devices_normalize_stations(json_decode($viewRow['station_macs'], true));
    }
    $sigNum = ($viewRow['signal_dbm'] !== null && $viewRow['signal_dbm'] !== '') ? (float) $viewRow['signal_dbm'] : null;
    $noiseNum = ($viewRow['noise_dbm'] !== null && $viewRow['noise_dbm'] !== '') ? (float) $viewRow['noise_dbm'] : null;
    $freqNum = ($viewRow['freq_mhz'] !== null && $viewRow['freq_mhz'] !== '') ? (float) $viewRow['freq_mhz'] : null;
    $cpe = function_exists('sas_cpe_login_url') ? sas_cpe_login_url($viewRow['mgmt_ip']) : ('http://' . $viewRow['mgmt_ip'] . '/');
    $sigPct = 0;
    if ($sigNum !== null) {
        $sigPct = max(0, min(100, (($sigNum + 90) / 60) * 100));
    }
?>
<div class="dev-detail-top">
    <div>
        <a class="btn ghost sm" href="devices.php">← <?php echo e($isEn ? 'Back' : 'رجوع'); ?></a>
        <h2 style="margin:8px 0 0" id="devTitle"><?php echo e($viewRow['name']); ?></h2>
        <div class="meta" style="font-weight:700;margin-top:4px">
            <span id="devTypeLbl"><?php echo e(devices_type_label($viewRow['device_type'], $lang)); ?></span>
            · <span id="devRoleLbl"><?php echo e(devices_role_label($viewRow['device_role'], $lang)); ?></span>
            · <span class="badge <?php echo e($viewRow['status']); ?>" id="devStatusBadge"><?php echo e(devices_status_label($viewRow['status'], $lang)); ?></span>
            · IP <a class="ltr" id="devIpLink" href="<?php echo e($cpe); ?>" target="_blank" rel="noopener"><?php echo e($viewRow['mgmt_ip']); ?></a>
            · MAC <span class="ltr" id="devMac"><?php echo e($viewRow['mac'] ? $viewRow['mac'] : '—'); ?></span>
        </div>
    </div>
    <div class="dev-actions">
        <span class="dev-pulse" id="devLivePulse"><i></i> Live</span>
        <a class="btn secondary sm" href="devices.php?edit=<?php echo (int) $viewRow['id']; ?>"><?php echo e($isEn ? 'Edit' : 'تعديل'); ?></a>
        <?php if ($viewRow['best_freq_mhz'] !== null && $viewRow['best_freq_mhz'] !== ''): ?>
        <form method="post" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Apply best frequency on the live radio?' : 'تضبط أفضل تردد على الجهاز الحي؟'); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="apply_freq">
            <input type="hidden" name="id" value="<?php echo (int) $viewRow['id']; ?>">
            <button class="btn sm" type="submit"><?php echo e($isEn ? 'Set best freq' : 'ضبط أفضل تردد'); ?></button>
        </form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm(<?php echo json_encode($isEn ? 'Delete this device?' : 'تحذف الجهاز؟'); ?>);">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo (int) $viewRow['id']; ?>">
            <button class="btn danger sm" type="submit"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
        </form>
    </div>
</div>

<div class="dev-hero">
    <div class="dev-card signal">
        <h3><?php echo e($isEn ? 'Signal' : 'الإشارة'); ?></h3>
        <div class="big" id="devSignalBig"><?php echo e($sigNum !== null ? ($sigNum . ' dBm') : '—'); ?></div>
        <div class="dev-meter-wrap"><div class="dev-meter" id="devSignalMeter" style="width:<?php echo (int) $sigPct; ?>%"></div></div>
        <div class="sub" id="devRatesSub"><?php echo e(($viewRow['tx_rate'] ? $viewRow['tx_rate'] : '—') . ' / ' . ($viewRow['rx_rate'] ? $viewRow['rx_rate'] : '—')); ?> Mbps</div>
    </div>
    <div class="dev-card status">
        <h3><?php echo e($isEn ? 'Device mode' : 'وضعية الجهاز'); ?></h3>
        <div class="big" id="devModeBig" style="font-size:26px"><?php echo e(devices_role_label($viewRow['device_role'], $lang)); ?></div>
        <div class="sub" id="devModeSub">
            <?php echo e(devices_login_label($vLogin, $lang)); ?>
            · <?php echo e($isEn ? 'Ping' : 'بنق'); ?> <span id="devPingSub"><?php echo e($vPing !== null ? ($vPing . ' ms') : '—'); ?></span>
        </div>
        <div class="sub" id="devLiveMeta" style="margin-top:8px"></div>
    </div>
</div>

<div class="dev-grid4">
    <div class="dev-mini"><b><?php echo e($isEn ? 'Noise' : 'النويز'); ?></b><span id="devNoise"><?php echo e($noiseNum !== null ? ($noiseNum . ' dBm') : '—'); ?></span></div>
    <div class="dev-mini"><b><?php echo e($isEn ? 'Frequency' : 'التردد'); ?></b><span id="devFreq"><?php echo e($freqNum !== null ? ($freqNum . ' MHz') : '—'); ?></span></div>
    <div class="dev-mini"><b><?php echo e($isEn ? 'Load' : 'الحمل'); ?></b><span id="devLoad"><?php echo e(($viewRow['load_pct'] !== null && $viewRow['load_pct'] !== '') ? ($viewRow['load_pct'] . '%') : '—'); ?></span></div>
    <div class="dev-mini"><b><?php echo e($isEn ? 'Login' : 'الدخول'); ?></b><span id="devLogin"><?php echo e(devices_login_label($vLogin, $lang)); ?></span></div>
</div>

<div class="dev-spec">
    <div class="head">
        <h3><?php echo e($isEn ? 'Spectrum analyzer' : 'سبكتروم أنالايزر'); ?></h3>
        <span class="dev-pulse"><i></i> <span id="devSpecHint"><?php echo e($isEn ? 'Live RF view' : 'عرض تردد حي'); ?></span></span>
    </div>
    <canvas id="devSpectrum" width="960" height="220"></canvas>
</div>

<div class="panel glass-panel" style="margin:0 0 12px">
    <h3 style="margin:0 0 8px;font-size:15px"><?php echo e($isEn ? 'Connected stations' : 'المحطات المتصلة'); ?></h3>
    <div class="table-wrap">
        <table class="table-compact dev-sta-table" id="devStaTable">
            <thead>
            <tr>
                <th><?php echo e($isEn ? 'MAC' : 'ماك'); ?></th>
                <th><?php echo e($isEn ? 'Signal' : 'الإشارة'); ?></th>
                <th><?php echo e($isEn ? 'Name' : 'الاسم'); ?></th>
                <th><?php echo e($isEn ? 'Subscriber' : 'المشترك'); ?></th>
            </tr>
            </thead>
            <tbody id="devStaBody">
            <?php if (!$viewStations): ?>
                <tr><td colspan="4"><?php echo e($isEn ? 'Waiting for live data…' : 'بانتظار بيانات حية…'); ?></td></tr>
            <?php else:
                $linkedByMac = array();
                foreach ($linkedSubs as $ls) {
                    $linkedByMac[devices_mac_fold(isset($ls['mac_address']) ? $ls['mac_address'] : '')] = $ls;
                }
                foreach ($viewStations as $vs):
                    $fold = devices_mac_fold($vs['mac']);
                    $ls = isset($linkedByMac[$fold]) ? $linkedByMac[$fold] : null;
                    ?>
                    <tr>
                        <td class="ltr" style="direction:ltr"><?php echo e($vs['mac']); ?></td>
                        <td><?php echo e($vs['signal'] !== null ? ($vs['signal'] . ' dBm') : '—'); ?></td>
                        <td><?php echo e($vs['name'] !== '' ? $vs['name'] : '—'); ?></td>
                        <td><?php
                            if ($ls) {
                                $uname = isset($ls['username']) ? (string) $ls['username'] : '';
                                $dname = trim((string) (isset($ls['display_name']) ? $ls['display_name'] : ''));
                                echo '<a href="sas_user.php?u=' . e(rawurlencode($uname)) . '">' . e($dname !== '' ? $dname : $uname) . '</a>';
                            } else {
                                echo '—';
                            }
                        ?></td>
                    </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!(function_exists('is_agent_user') && is_agent_user())): ?>
<div class="panel glass-panel" style="margin:0 0 12px">
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="transfer">
        <input type="hidden" name="id" value="<?php echo (int) $viewRow['id']; ?>">
        <div>
            <label><?php echo e($isEn ? 'Transfer to agent' : 'نقل لوكيل'); ?></label>
            <select name="to_agent" required>
                <option value=""><?php echo e($isEn ? 'Choose…' : 'اختر…'); ?></option>
                <?php foreach ($agents as $ag): ?>
                    <option value="<?php echo (int) $ag['id']; ?>"><?php echo e(trim((string) $ag['display_name']) !== '' ? $ag['display_name'] : $ag['username']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn secondary sm" type="submit"><?php echo e($isEn ? 'Transfer' : 'نقل'); ?></button>
    </form>
</div>
<?php endif; ?>

<script>
(function () {
  var id = <?php echo (int) $viewRow['id']; ?>;
  var isEn = <?php echo $isEn ? 'true' : 'false'; ?>;
  var canvas = document.getElementById('devSpectrum');
  var ctx = canvas && canvas.getContext ? canvas.getContext('2d') : null;
  var state = {
    signal: <?php echo json_encode($sigNum); ?>,
    noise: <?php echo json_encode($noiseNum); ?>,
    freq: <?php echo json_encode($freqNum); ?>,
    bw: 80,
    t: 0
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function setTxt(id, v) {
    var el = document.getElementById(id);
    if (el) el.textContent = v;
  }
  function sigPct(v) {
    if (v == null || isNaN(v)) return 0;
    return Math.max(0, Math.min(100, ((v + 90) / 60) * 100));
  }
  function drawSpectrum() {
    if (!ctx || !canvas) return;
    var w = canvas.width, h = canvas.height;
    ctx.clearRect(0, 0, w, h);
    var grd = ctx.createLinearGradient(0, 0, 0, h);
    grd.addColorStop(0, '#0b1220');
    grd.addColorStop(1, '#020617');
    ctx.fillStyle = grd;
    ctx.fillRect(0, 0, w, h);
    ctx.strokeStyle = 'rgba(125,211,252,.12)';
    ctx.lineWidth = 1;
    for (var g = 0; g < 6; g++) {
      var y = (h / 6) * g;
      ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke();
    }
    var bars = 64;
    var noise = state.noise != null ? state.noise : -95;
    var sig = state.signal != null ? state.signal : -70;
    var center = Math.floor(bars / 2);
    var bwBars = Math.max(4, Math.round((state.bw || 80) / 5));
    for (var i = 0; i < bars; i++) {
      var dist = Math.abs(i - center);
      var base = noise - 5 + Math.sin(state.t * 0.08 + i * 0.35) * 3 + (Math.random() * 4 - 2);
      var peak = 0;
      if (dist < bwBars) {
        peak = (sig - noise) * Math.exp(-(dist * dist) / (2 * (bwBars * 0.35) * (bwBars * 0.35)));
      }
      var db = base + peak;
      var norm = Math.max(0, Math.min(1, (db + 110) / 80));
      var bh = norm * (h - 28);
      var x = (i / bars) * w;
      var bw = w / bars * 0.82;
      var col = peak > 8 ? '#38bdf8' : (peak > 2 ? '#22d3ee' : '#334155');
      if (db > -55) col = '#f472b6';
      else if (db > -65) col = '#a78bfa';
      ctx.fillStyle = col;
      ctx.fillRect(x, h - 18 - bh, bw, bh);
    }
    ctx.fillStyle = '#94a3b8';
    ctx.font = '11px sans-serif';
    var f = state.freq != null ? state.freq : 0;
    var label = f ? ((f - 40).toFixed(0) + ' — ' + f.toFixed(0) + ' — ' + (f + 40).toFixed(0) + ' MHz') : 'MHz';
    ctx.fillText(label, 10, h - 4);
    if (state.signal != null) {
      ctx.fillStyle = '#7dd3fc';
      ctx.fillText('RSSI ' + state.signal + ' dBm', w - 120, 16);
    }
  }
  function animate() {
    state.t++;
    drawSpectrum();
    requestAnimationFrame(animate);
  }
  requestAnimationFrame(animate);

  function applyPayload(data) {
    if (!data || !data.ok || !data.device) return;
    var d = data.device;
    var live = data.live || {};
    setTxt('devTitle', d.name || '');
    setTxt('devMac', d.mac || '—');
    var badge = document.getElementById('devStatusBadge');
    if (badge) {
      badge.className = 'badge ' + (d.status || 'unknown');
      badge.textContent = (data.labels && data.labels.status) ? data.labels.status : (d.status || '');
    }
    state.signal = d.signal_dbm;
    state.noise = d.noise_dbm;
    state.freq = d.freq_mhz;
    if (live.bandwidth) {
      var m = String(live.bandwidth).match(/(\d+)/);
      if (m) state.bw = parseInt(m[1], 10) || state.bw;
    }
    setTxt('devSignalBig', d.signal_dbm != null ? (d.signal_dbm + ' dBm') : '—');
    var meter = document.getElementById('devSignalMeter');
    if (meter) meter.style.width = sigPct(d.signal_dbm) + '%';
    setTxt('devRatesSub', ((d.tx_rate || '—') + ' / ' + (d.rx_rate || '—')) + ' Mbps');
    setTxt('devNoise', d.noise_dbm != null ? (d.noise_dbm + ' dBm') : '—');
    setTxt('devFreq', d.freq_mhz != null ? (d.freq_mhz + ' MHz') : '—');
    setTxt('devLoad', d.load_pct != null ? (d.load_pct + '%') : '—');
    setTxt('devLogin', (data.labels && data.labels.login) ? data.labels.login : '—');
    setTxt('devPingSub', d.ping_ms != null ? (d.ping_ms + ' ms') : '—');
    if (live.device_mode) setTxt('devModeBig', live.device_mode);
    var meta = [];
    if (live.ssid) meta.push('SSID ' + live.ssid);
    if (live.distance_m != null) meta.push((isEn ? 'Distance ' : 'المسافة ') + live.distance_m + ' m');
    if (live.availability != null) meta.push((isEn ? 'Avail ' : 'توفّر ') + live.availability + '%');
    if (live.temp_c != null) meta.push((isEn ? 'Temp ' : 'حرارة ') + live.temp_c + '°C');
    if (live.tx_power != null) meta.push('TX ' + live.tx_power + ' dBm');
    if (live.connected === true) meta.push(isEn ? 'Link up' : 'اللِنك متصل');
    if (live.connected === false) meta.push(isEn ? 'Link down' : 'اللِنك طافي');
    setTxt('devLiveMeta', meta.join(' · '));
    setTxt('devSpecHint', live.bandwidth
      ? ((isEn ? 'BW ' : 'عرض ') + live.bandwidth + (live.link_name ? (' · ' + live.link_name) : ''))
      : (isEn ? 'Live RF view' : 'عرض تردد حي'));

    var linked = {};
    (data.linked || []).forEach(function (ls) {
      linked[String(ls.mac_address || '').toLowerCase().replace(/[^0-9a-f]/g, '')] = ls;
    });
    var body = document.getElementById('devStaBody');
    if (body) {
      var stas = d.stations || [];
      if (!stas.length) {
        body.innerHTML = '<tr><td colspan="4">' + esc(isEn ? 'No stations' : 'ماكو محطات') + '</td></tr>';
      } else {
        body.innerHTML = stas.map(function (s) {
          var fold = String(s.mac || '').toLowerCase().replace(/[^0-9a-f]/g, '');
          var ls = linked[fold];
          var sub = '—';
          if (ls) {
            var dn = ls.display_name || ls.username || '';
            sub = '<a href="sas_user.php?u=' + encodeURIComponent(ls.username || '') + '">' + esc(dn) + '</a>';
          }
          return '<tr><td class="ltr" style="direction:ltr">' + esc(s.mac || '') + '</td><td>' +
            esc(s.signal != null ? (s.signal + ' dBm') : '—') + '</td><td>' +
            esc(s.name || '—') + '</td><td>' + sub + '</td></tr>';
        }).join('');
      }
    }
  }

  var busy = false;
  function tick() {
    if (busy || document.hidden) return;
    busy = true;
    var t0 = Date.now();
    var ringTimer = setTimeout(function () {
      if (window.appBusySet) window.appBusySet({ force: true });
    }, 700);
    fetch('devices.php?ajax=live_one&id=' + id + '&poll=1&_=' + Date.now(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { applyPayload(data); })
      .catch(function () {})
      .then(function () {
        clearTimeout(ringTimer);
        if (window.appBusyDone) window.appBusyDone();
        busy = false;
      });
  }
  tick();
  setInterval(tick, 3000);
})();
</script>
<?php
    render_footer();
    exit;
endif;
?>

<div class="dev-stats" id="devStats">
    <div class="dev-stat"><b id="stTotal"><?php echo (int) $stats['total']; ?></b><span><?php echo e($isEn ? 'Total' : 'المجموع'); ?></span></div>
    <div class="dev-stat is-ok"><b id="stOk"><?php echo (int) $stats['ok']; ?></b><span><?php echo e(devices_status_label('ok', $lang)); ?></span></div>
    <div class="dev-stat is-full"><b id="stFull"><?php echo (int) $stats['full_load']; ?></b><span><?php echo e(devices_status_label('full_load', $lang)); ?></span></div>
    <div class="dev-stat is-timeout"><b id="stTimeout"><?php echo (int) $stats['timeout']; ?></b><span><?php echo e(devices_status_label('timeout', $lang)); ?></span></div>
    <div class="dev-stat is-off"><b id="stOff"><?php echo (int) $stats['offline']; ?></b><span><?php echo e(devices_status_label('offline', $lang)); ?></span></div>
</div>

<div class="panel panel-compact">
    <div class="actions actions-tight" style="margin-top:0;margin-bottom:10px;flex-wrap:wrap">
        <a class="btn sm" href="devices.php?new=1"><?php echo e($isEn ? 'Add device' : 'إضافة جهاز'); ?></a>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="poll_all">
            <button class="btn secondary sm" type="submit"><?php echo e($isEn ? 'Refresh all' : 'تحديث الكل'); ?></button>
        </form>
        <a class="btn ghost sm" href="devices.php?export=1<?php
            echo $q !== '' ? ('&q=' . rawurlencode($q)) : '';
            echo $filterType !== '' ? ('&type=' . rawurlencode($filterType)) : '';
            echo $filterRole !== '' ? ('&role=' . rawurlencode($filterRole)) : '';
            echo $filterStatus !== '' ? ('&status=' . rawurlencode($filterStatus)) : '';
            echo $filterAgent !== '' ? ('&agent=' . rawurlencode($filterAgent)) : '';
        ?>"><?php echo e($isEn ? 'Export CSV' : 'تصدير CSV'); ?></a>
        <a class="btn ghost sm" href="settings.php?tab=devices"><?php echo e($isEn ? 'Settings' : 'الإعدادات'); ?></a>
    </div>

    <div class="dev-live-bar">
        <span style="font-size:12px;font-weight:800;color:#64748b"><?php echo e($isEn ? 'Table refresh' : 'تحديث الجدول'); ?></span>
        <button type="button" class="live-chip" data-refresh="0" id="devRefOff"><?php echo e($isEn ? 'Off' : 'إيقاف'); ?></button>
        <button type="button" class="live-chip" data-refresh="live" id="devRefLive">Live</button>
        <button type="button" class="live-chip" data-refresh="5" id="devRef5"><?php echo e($isEn ? 'Every 5s' : 'كل 5 ثواني'); ?></button>
    </div>

    <form method="get" class="dev-adv-filter" id="devAdvFilter">
        <input name="q" value="<?php echo e($q); ?>" placeholder="<?php echo e($isEn ? 'Search by name, IP or MAC…' : 'بحث بالاسم أو الآيبي أو الماك…'); ?>">
        <select name="type">
            <option value=""><?php echo e($isEn ? 'All types' : 'كل الأنواع'); ?></option>
            <?php foreach ($types as $tid => $meta): ?>
                <option value="<?php echo e($tid); ?>" <?php echo $filterType === $tid ? 'selected' : ''; ?>><?php echo e($isEn ? $meta['en'] : $meta['ar']); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="role">
            <option value=""><?php echo e($isEn ? 'All roles' : 'كل الأدوار'); ?></option>
            <?php foreach ($roles as $rid => $meta): ?>
                <option value="<?php echo e($rid); ?>" <?php echo $filterRole === $rid ? 'selected' : ''; ?>><?php echo e($isEn ? $meta['en'] : $meta['ar']); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value=""><?php echo e($isEn ? 'All statuses' : 'كل الحالات'); ?></option>
            <?php foreach ($statuses as $sid => $meta): ?>
                <option value="<?php echo e($sid); ?>" <?php echo $filterStatus === $sid ? 'selected' : ''; ?>><?php echo e($isEn ? $meta['en'] : $meta['ar']); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!(function_exists('is_agent_user') && is_agent_user())): ?>
        <select name="agent">
            <option value=""><?php echo e($isEn ? 'All agents' : 'كل الوكلاء'); ?></option>
            <?php foreach ($agents as $ag): ?>
                <option value="<?php echo (int) $ag['id']; ?>" <?php echo (string) $filterAgent === (string) $ag['id'] ? 'selected' : ''; ?>>
                    <?php echo e(trim((string) $ag['display_name']) !== '' ? $ag['display_name'] : $ag['username']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button class="btn secondary sm" type="submit"><?php echo e($isEn ? 'Filter' : 'تصفية'); ?></button>
        <?php if ($q !== '' || $filterType !== '' || $filterRole !== '' || $filterStatus !== '' || $filterAgent !== ''): ?>
            <a class="btn ghost sm" href="devices.php"><?php echo e(t('show_all')); ?></a>
        <?php endif; ?>
    </form>

    <h2 style="margin:0 0 8px"><?php echo e($isEn ? 'Devices on the network' : 'الأجهزة على الشبكة'); ?></h2>
    <div class="table-wrap">
        <table id="devicesTable" class="table-compact">
            <thead>
            <tr>
                <th>#</th>
                <th><?php echo e($isEn ? 'Name' : 'الاسم'); ?></th>
                <th><?php echo e($isEn ? 'Type / role' : 'النوع / الدور'); ?></th>
                <th>IP</th>
                <th>MAC</th>
                <th><?php echo e($isEn ? 'Agent' : 'الوكيل'); ?></th>
                <th><?php echo e($isEn ? 'Status' : 'الحالة'); ?></th>
                <th><?php echo e($isEn ? 'Ping' : 'بنق'); ?></th>
                <th><?php echo e($isEn ? 'Login' : 'دخول'); ?></th>
                <th><?php echo e($isEn ? 'Signal / freq' : 'إشارة / تردد'); ?></th>
                <th></th>
            </tr>
            </thead>
            <tbody id="devicesTbody">
            <?php if (!$rows): ?>
                <tr><td colspan="11"><?php echo e($isEn ? 'No devices yet' : 'ماكو أجهزة بعد'); ?></td></tr>
            <?php endif; ?>
            <?php $n = 1; foreach ($rows as $r):
                $agentLabel = '—';
                if (!empty($r['agent_display'])) {
                    $agentLabel = (string) $r['agent_display'];
                } elseif (!empty($r['agent_username'])) {
                    $agentLabel = (string) $r['agent_username'];
                }
                $st = isset($r['status']) ? (string) $r['status'] : 'unknown';
                $sig = ($r['signal_dbm'] !== null && $r['signal_dbm'] !== '') ? ((string) $r['signal_dbm'] . ' dBm') : '—';
                $freq = ($r['freq_mhz'] !== null && $r['freq_mhz'] !== '') ? ((string) $r['freq_mhz'] . ' MHz') : '—';
                $pingTxt = (isset($r['ping_ms']) && $r['ping_ms'] !== null && $r['ping_ms'] !== '')
                    ? (round((float) $r['ping_ms'], 1) . ' ms')
                    : '—';
                $loginVal = array_key_exists('login_ok', $r) ? $r['login_ok'] : null;
                $loginCls = ($loginVal === null || $loginVal === '') ? 'unknown' : (((int) $loginVal === 1) ? 'ok' : 'offline');
                $cpe = function_exists('sas_cpe_login_url') ? sas_cpe_login_url($r['mgmt_ip']) : ('http://' . $r['mgmt_ip'] . '/');
                ?>
                <tr data-id="<?php echo (int) $r['id']; ?>">
                    <td><?php echo $n++; ?></td>
                    <td><a class="dev-name" href="devices.php?id=<?php echo (int) $r['id']; ?>"><?php echo e($r['name']); ?></a></td>
                    <td>
                        <span class="dev-ico <?php echo e($r['device_type']); ?>"><?php echo e(strtoupper(substr($r['device_type'], 0, 2))); ?></span>
                        <?php echo e(devices_type_label($r['device_type'], $lang)); ?>
                        <small class="muted"> · <?php echo e(devices_role_label($r['device_role'], $lang)); ?></small>
                    </td>
                    <td class="ltr" style="direction:ltr"><a href="<?php echo e($cpe); ?>" target="_blank" rel="noopener"><?php echo e($r['mgmt_ip']); ?></a></td>
                    <td class="ltr" style="direction:ltr"><?php echo e($r['mac'] ? $r['mac'] : '—'); ?></td>
                    <td><?php echo e($agentLabel); ?></td>
                    <td><span class="badge <?php echo e($st); ?>"><?php echo e(devices_status_label($st, $lang)); ?></span></td>
                    <td class="dev-radio ltr" style="direction:ltr"><?php echo e($pingTxt); ?></td>
                    <td><span class="badge <?php echo e($loginCls); ?>"><?php echo e(devices_login_label($loginVal, $lang)); ?></span></td>
                    <td class="dev-radio"><?php echo e($sig); ?> <small>/ <?php echo e($freq); ?></small></td>
                    <td>
                        <div class="dev-actions">
                            <a class="link-act act-blue" href="devices.php?edit=<?php echo (int) $r['id']; ?>"><?php echo e($isEn ? 'Edit' : 'تعديل'); ?></a>
                            <a class="link-act act-green" href="devices.php?id=<?php echo (int) $r['id']; ?>"><?php echo e($isEn ? 'Open' : 'فتح'); ?></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($showForm): ?>
<div class="panel glass-panel dev-panel">
    <h2><?php echo e($editRow ? ($isEn ? 'Edit device' : 'تعديل جهاز') : ($isEn ? 'New device' : 'جهاز جديد')); ?></h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int) $formRow['id']; ?>">
        <div class="form-grid cols-2">
            <div>
                <label><?php echo e($isEn ? 'Name' : 'الاسم'); ?></label>
                <input name="name" value="<?php echo e($formRow['name']); ?>" required>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Type' : 'النوع'); ?></label>
                <select name="device_type" id="devTypeSel" required>
                    <?php foreach ($types as $tid => $meta): ?>
                        <option value="<?php echo e($tid); ?>" <?php echo $formRow['device_type'] === $tid ? 'selected' : ''; ?>><?php echo e($isEn ? $meta['en'] : $meta['ar']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Role' : 'الدور'); ?></label>
                <select name="device_role" required>
                    <?php foreach ($roles as $rid => $meta): ?>
                        <option value="<?php echo e($rid); ?>" <?php echo $formRow['device_role'] === $rid ? 'selected' : ''; ?>><?php echo e($isEn ? $meta['en'] : $meta['ar']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>IP</label>
                <input class="ltr" name="mgmt_ip" value="<?php echo e($formRow['mgmt_ip']); ?>" required>
            </div>
            <div id="devUserBox">
                <label><?php echo e($isEn ? 'Login user' : 'يوزر الدخول'); ?></label>
                <input class="ltr" name="username" id="devUsername" value="<?php echo e($formRow['username']); ?>">
            </div>
            <div>
                <label id="devPassLabel"><?php echo e($isEn ? 'Password' : 'الباسورد'); ?></label>
                <input class="ltr" type="password" name="password" id="devPassword" value="" placeholder="<?php echo e($editRow ? ($isEn ? 'Leave blank to keep' : 'فارغ = نفس الباسورد') : ''); ?>">
                <small class="muted" id="devUserHint" style="display:none;font-weight:700"><?php echo e($isEn ? 'Mimosa: password only (no username)' : 'ميموسا: باسورد فقط بدون يوزر'); ?></small>
            </div>
            <div>
                <label><?php echo e($isEn ? 'Agent' : 'الوكيل'); ?></label>
                <select name="agent_user_id">
                    <option value="0"><?php echo e($isEn ? '— none —' : '— بدون —'); ?></option>
                    <?php foreach ($agents as $ag): ?>
                        <option value="<?php echo (int) $ag['id']; ?>" <?php echo (int) $formRow['agent_user_id'] === (int) $ag['id'] ? 'selected' : ''; ?>>
                            <?php echo e(trim((string) $ag['display_name']) !== '' ? $ag['display_name'] : $ag['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="grid-column:1/-1">
                <label><?php echo e($isEn ? 'Notes' : 'ملاحظات'); ?></label>
                <input name="notes" value="<?php echo e(isset($formRow['notes']) ? $formRow['notes'] : ''); ?>">
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit"><?php echo e(t('save')); ?></button>
            <a class="btn ghost" href="devices.php"><?php echo e($isEn ? 'Cancel' : 'إلغاء'); ?></a>
        </div>
    </form>
    <script>
    (function () {
      var sel = document.getElementById('devTypeSel');
      var userBox = document.getElementById('devUserBox');
      var userInp = document.getElementById('devUsername');
      var hint = document.getElementById('devUserHint');
      function syncMimosa() {
        var isM = sel && sel.value === 'mimosa';
        if (userBox) userBox.style.display = isM ? 'none' : '';
        if (userInp) {
          userInp.disabled = !!isM;
          if (isM) userInp.value = '';
        }
        if (hint) hint.style.display = isM ? 'block' : 'none';
      }
      if (sel) {
        sel.addEventListener('change', syncMimosa);
        syncMimosa();
      }
    })();
    </script>
</div>
<?php endif; ?>

<div class="panel glass-panel dev-panel">
    <h2><?php echo e($isEn ? 'Import devices (CSV)' : 'استيراد أجهزة (CSV)'); ?></h2>
    <p class="meta" style="margin-top:-4px;font-weight:600">
        <?php echo e($isEn
            ? 'Columns: name, device_type, device_role, mgmt_ip, username, password, mac, notes, agent_user_id'
            : 'الأعمدة: name, device_type, device_role, mgmt_ip, username, password, mac, notes, agent_user_id'); ?>
    </p>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="import">
        <input type="file" name="csv" accept=".csv,text/csv">
        <div class="actions">
            <button class="btn secondary sm" type="submit"><?php echo e($isEn ? 'Import' : 'استيراد'); ?></button>
        </div>
    </form>
</div>

<script>
(function () {
  var isEn = <?php echo $isEn ? 'true' : 'false'; ?>;
  var mode = localStorage.getItem('devTableRefresh') || '0';
  var timer = null;
  var busy = false;
  var qs = <?php echo json_encode(array(
      'q' => $q,
      'type' => $filterType,
      'role' => $filterRole,
      'status' => $filterStatus,
      'agent' => $filterAgent,
  )); ?>;
  var statusLabels = <?php
    $sl = array();
    foreach ($statuses as $sid => $meta) {
        $sl[$sid] = $isEn ? $meta['en'] : $meta['ar'];
    }
    echo json_encode($sl);
  ?>;
  var typeLabels = <?php
    $tl = array();
    foreach ($types as $tid => $meta) {
        $tl[$tid] = $isEn ? $meta['en'] : $meta['ar'];
    }
    echo json_encode($tl);
  ?>;
  var roleLabels = <?php
    $rl = array();
    foreach ($roles as $rid => $meta) {
        $rl[$rid] = $isEn ? $meta['en'] : $meta['ar'];
    }
    echo json_encode($rl);
  ?>;

  function paintChips() {
    ['devRefOff','devRefLive','devRef5'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.classList.remove('is-on', 'is-mid');
      var v = el.getAttribute('data-refresh');
      if (String(mode) === String(v)) {
        el.classList.add(v === 'live' ? 'is-on' : (v === '5' ? 'is-mid' : 'is-on'));
      }
    });
    if (mode === '0') {
      var off = document.getElementById('devRefOff');
      if (off) off.classList.add('is-on');
    }
  }
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  function loginLabel(v) {
    if (v == null || v === '') return isEn ? 'Not checked' : 'ما انفحص';
    return parseInt(v, 10) === 1 ? (isEn ? 'Logged in' : 'تم الدخول') : (isEn ? 'Login failed' : 'فشل الدخول');
  }
  function renderRows(rows) {
    var body = document.getElementById('devicesTbody');
    if (!body) return;
    if (!rows || !rows.length) {
      body.innerHTML = '<tr><td colspan="11">' + esc(isEn ? 'No devices yet' : 'ماكو أجهزة بعد') + '</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (r, i) {
      var st = r.status || 'unknown';
      var loginCls = (r.login_ok == null || r.login_ok === '') ? 'unknown' : (parseInt(r.login_ok,10)===1 ? 'ok' : 'offline');
      var sig = r.signal_dbm != null ? (r.signal_dbm + ' dBm') : '—';
      var freq = r.freq_mhz != null ? (r.freq_mhz + ' MHz') : '—';
      var ping = r.ping_ms != null ? (r.ping_ms + ' ms') : '—';
      var cpe = 'http://' + (r.mgmt_ip || '') + '/';
      return '<tr data-id="' + r.id + '">' +
        '<td>' + (i+1) + '</td>' +
        '<td><a class="dev-name" href="devices.php?id=' + r.id + '">' + esc(r.name) + '</a></td>' +
        '<td><span class="dev-ico ' + esc(r.device_type) + '">' + esc(String(r.device_type||'').slice(0,2).toUpperCase()) + '</span>' +
          esc(typeLabels[r.device_type] || r.device_type) +
          '<small class="muted"> · ' + esc(roleLabels[r.device_role] || r.device_role) + '</small></td>' +
        '<td class="ltr" style="direction:ltr"><a href="' + esc(cpe) + '" target="_blank" rel="noopener">' + esc(r.mgmt_ip) + '</a></td>' +
        '<td class="ltr" style="direction:ltr">' + esc(r.mac || '—') + '</td>' +
        '<td>' + esc(r.agent_display || '—') + '</td>' +
        '<td><span class="badge ' + esc(st) + '">' + esc(statusLabels[st] || st) + '</span></td>' +
        '<td class="dev-radio ltr" style="direction:ltr">' + esc(ping) + '</td>' +
        '<td><span class="badge ' + loginCls + '">' + esc(loginLabel(r.login_ok)) + '</span></td>' +
        '<td class="dev-radio">' + esc(sig) + ' <small>/ ' + esc(freq) + '</small></td>' +
        '<td><div class="dev-actions">' +
          '<a class="link-act act-blue" href="devices.php?edit=' + r.id + '">' + esc(isEn ? 'Edit' : 'تعديل') + '</a>' +
          '<a class="link-act act-green" href="devices.php?id=' + r.id + '">' + esc(isEn ? 'Open' : 'فتح') + '</a>' +
        '</div></td></tr>';
    }).join('');
  }
  function applyStats(st) {
    if (!st) return;
    var map = {stTotal:'total', stOk:'ok', stFull:'full_load', stTimeout:'timeout', stOff:'offline'};
    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id);
      if (el && st[map[id]] != null) el.textContent = st[map[id]];
    });
  }
  function refresh(poll) {
    if (busy || document.hidden) return;
    busy = true;
    var params = new URLSearchParams();
    params.set('ajax', 'live_table');
    params.set('poll', poll ? '1' : '0');
    Object.keys(qs).forEach(function (k) { if (qs[k]) params.set(k, qs[k]); });
    params.set('_', Date.now());
    fetch('devices.php?' + params.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          renderRows(data.rows || []);
          applyStats(data.stats || {});
        }
      })
      .catch(function () {})
      .then(function () { busy = false; });
  }
  function arm() {
    if (timer) { clearInterval(timer); timer = null; }
    paintChips();
    if (mode === 'live') timer = setInterval(function () { refresh(true); }, 5000);
    else if (String(mode) === '5') timer = setInterval(function () { refresh(true); }, 5000);
  }
  document.querySelectorAll('.live-chip').forEach(function (btn) {
    btn.addEventListener('click', function () {
      mode = btn.getAttribute('data-refresh') || '0';
      localStorage.setItem('devTableRefresh', mode);
      arm();
      if (mode !== '0') refresh(true);
    });
  });
  arm();
})();
</script>
<?php render_footer(); ?>
