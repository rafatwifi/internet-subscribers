<?php

function invoice_studio_dir()
{
    $dir = dirname(__DIR__) . '/storage/invoices';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function invoice_paper_sizes()
{
    return array(
        'strip58' => array('label' => 'شريط 58 مم', 'w' => 58, 'h' => 0),
        'strip80' => array('label' => 'شريط 80 مم', 'w' => 80, 'h' => 0),
        'a4' => array('label' => 'A4', 'w' => 210, 'h' => 297),
        'a5' => array('label' => 'نصف A4', 'w' => 148, 'h' => 210),
    );
}

function invoice_default_design()
{
    return array(
        'paper' => 'strip80',
        'orient' => 'portrait',
        'margin_top' => 3,
        'company_name' => '',
        'phone' => '',
        'address' => '',
        'logo' => '',
        'blocks' => array(
            array('id' => 'company', 'kind' => 'company', 'x' => 4, 'y' => 3, 'w' => 0, 'size' => 16, 'color' => '#0f172a', 'align' => 'right', 'dir' => 'rtl', 'text' => '', 'on' => 1),
            array('id' => 'phone', 'kind' => 'phone', 'x' => 4, 'y' => 10, 'w' => 0, 'size' => 11, 'color' => '#334155', 'align' => 'right', 'dir' => 'ltr', 'text' => '', 'on' => 1),
            array('id' => 'name', 'kind' => 'name', 'x' => 4, 'y' => 20, 'w' => 0, 'size' => 13, 'color' => '#0f172a', 'align' => 'right', 'dir' => 'rtl', 'text' => '', 'on' => 1),
            array('id' => 'package', 'kind' => 'package', 'x' => 36, 'y' => 20, 'w' => 0, 'size' => 13, 'color' => '#0f766e', 'align' => 'right', 'dir' => 'ltr', 'text' => '', 'on' => 1),
            array('id' => 'start', 'kind' => 'start', 'x' => 4, 'y' => 28, 'w' => 0, 'size' => 11, 'color' => '#334155', 'align' => 'right', 'dir' => 'ltr', 'text' => '', 'on' => 1),
            array('id' => 'end', 'kind' => 'end', 'x' => 36, 'y' => 28, 'w' => 0, 'size' => 11, 'color' => '#334155', 'align' => 'right', 'dir' => 'ltr', 'text' => '', 'on' => 1),
            array('id' => 'amount', 'kind' => 'amount', 'x' => 4, 'y' => 38, 'w' => 0, 'size' => 18, 'color' => '#0f172a', 'align' => 'right', 'dir' => 'ltr', 'text' => '', 'on' => 1),
        ),
    );
}

function invoice_design_path($tenantId)
{
    return invoice_studio_dir() . '/design_t' . (int) $tenantId . '.json';
}

function invoice_normalize_design($j)
{
    $base = invoice_default_design();
    $base['id'] = (is_array($j) && !empty($j['id'])) ? (string) $j['id'] : 'main';
    $base['name'] = (is_array($j) && !empty($j['name'])) ? (string) $j['name'] : 'القالب الأساسي';
    $frames = array('none', 'line', 'double', 'dashed', 'round');
    $frame = (is_array($j) && !empty($j['frame'])) ? (string) $j['frame'] : 'none';
    $base['frame'] = in_array($frame, $frames, true) ? $frame : 'none';
    $base['orient'] = (is_array($j) && isset($j['orient']) && $j['orient'] === 'landscape') ? 'landscape' : 'portrait';
    $mt = 3;
    if (is_array($j) && isset($j['margin_top'])) {
        $mt = (float) $j['margin_top'];
    }
    if ($mt < 0) {
        $mt = 0;
    }
    if ($mt > 12) {
        $mt = 12;
    }
    $base['margin_top'] = $mt;
    if (!is_array($j)) {
        return $base;
    }
    if (!empty($j['paper']) && isset(invoice_paper_sizes()[$j['paper']])) {
        $base['paper'] = $j['paper'];
    }
    foreach (array('company_name', 'phone', 'address', 'logo') as $k) {
        if (isset($j[$k])) {
            $base[$k] = (string) $j[$k];
        }
    }
    if (!empty($j['blocks']) && is_array($j['blocks'])) {
        $base['blocks'] = $j['blocks'];
    }
    return $base;
}

function invoice_library_load($tenantId)
{
    $path = invoice_design_path($tenantId);
    $blank = invoice_normalize_design(null);
    $lib = array('active' => $blank['id'], 'templates' => array($blank));
    if (!is_file($path)) {
        return $lib;
    }
    $j = json_decode((string) @file_get_contents($path), true);
    if (!is_array($j)) {
        return $lib;
    }
    if (!empty($j['templates']) && is_array($j['templates'])) {
        $list = array();
        foreach ($j['templates'] as $t) {
            if (is_array($t)) {
                $list[] = invoice_normalize_design($t);
            }
        }
        if ($list) {
            $lib['templates'] = $list;
            $lib['active'] = !empty($j['active']) ? (string) $j['active'] : $list[0]['id'];
        }
        return $lib;
    }
    $one = invoice_normalize_design($j);
    $lib['templates'] = array($one);
    $lib['active'] = $one['id'];
    return $lib;
}

function invoice_library_save($tenantId, $lib)
{
    $json = json_encode($lib);
    return $json !== false && @file_put_contents(invoice_design_path($tenantId), $json, LOCK_EX) !== false;
}

function invoice_library_active($tenantId, $id)
{
    $lib = invoice_library_load($tenantId);
    foreach ($lib['templates'] as $t) {
        if ((string) $t['id'] === (string) $id) {
            $lib['active'] = (string) $id;
            invoice_library_save($tenantId, $lib);
            return true;
        }
    }
    return false;
}

function invoice_design_load($tenantId)
{
    $lib = invoice_library_load($tenantId);
    foreach ($lib['templates'] as $t) {
        if ((string) $t['id'] === (string) $lib['active']) {
            return $t;
        }
    }
    return $lib['templates'][0];
}

function invoice_design_save($tenantId, $design)
{
    $lib = invoice_library_load($tenantId);
    $id = !empty($design['id']) ? (string) $design['id'] : (string) $lib['active'];
    $design['id'] = $id;
    $found = false;
    foreach ($lib['templates'] as $i => $t) {
        if ((string) $t['id'] === $id) {
            if (empty($design['logo']) && !empty($t['logo'])) {
                $design['logo'] = $t['logo'];
            }
            $lib['templates'][$i] = invoice_normalize_design($design);
            $found = true;
            break;
        }
    }
    if (!$found) {
        $lib['templates'][] = invoice_normalize_design($design);
    }
    $lib['active'] = $id;
    return invoice_library_save($tenantId, $lib);
}

function invoice_printers_path($tenantId)
{
    return invoice_studio_dir() . '/printers_t' . (int) $tenantId . '.json';
}

function invoice_printers_load($tenantId)
{
    $path = invoice_printers_path($tenantId);
    if (!is_file($path)) {
        return array();
    }
    $j = json_decode((string) @file_get_contents($path), true);
    return is_array($j) ? $j : array();
}

function invoice_printers_save($tenantId, $rows)
{
    $json = json_encode(array_values($rows));
    return $json !== false && @file_put_contents(invoice_printers_path($tenantId), $json, LOCK_EX) !== false;
}

function invoice_probe_printer($ip, $port)
{
    $ip = trim((string) $ip);
    $port = (int) $port;
    if ($port <= 0) {
        $port = 9100;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    $errno = 0;
    $err = '';
    $fp = @fsockopen($ip, $port, $errno, $err, 1.2);
    if (!$fp) {
        return false;
    }
    fclose($fp);
    return true;
}

function invoice_system_footer()
{
    $name = 'البوابة';
    if (function_exists('settings_load')) {
        $s = settings_load();
        if (!empty($s['invoice_footer_name'])) {
            $name = (string) $s['invoice_footer_name'];
        } elseif (!empty($s['site_name'])) {
            $name = (string) $s['site_name'];
        }
    }
    return $name;
}

function invoice_block_label($id)
{
    $map = array(
        'logo' => 'الشعار',
        'company' => 'اسم الشركة',
        'phone' => 'الهاتف',
        'address' => 'العنوان',
        'name' => 'اسم المشترك',
        'package' => 'نوع الاشتراك',
        'start' => 'تاريخ التفعيل',
        'end' => 'تاريخ الانتهاء',
        'amount' => 'المبلغ',
    );
    return isset($map[$id]) ? $map[$id] : $id;
}

function invoice_sample_text($id, $design)
{
    $today = date('Y-m-d');
    $end = date('Y-m-d', time() + 30 * 86400);
    $map = array(
        'company' => $design['company_name'] !== '' ? $design['company_name'] : 'اسم الشركة',
        'phone' => $design['phone'] !== '' ? $design['phone'] : '07xx xxx xxxx',
        'address' => $design['address'] !== '' ? $design['address'] : 'عنوان الشركة',
        'name' => 'أحمد محمد',
        'package' => 'NB MAX-MSL',
        'start' => $today,
        'end' => $end,
        'amount' => '35,000',
    );
    return isset($map[$id]) ? $map[$id] : '';
}

function invoice_design_height_mm($design)
{
    $h = 28;
    if (!empty($design['blocks']) && is_array($design['blocks'])) {
        foreach ($design['blocks'] as $b) {
            if (empty($b['on'])) {
                continue;
            }
            $bottom = (isset($b['y']) ? (float) $b['y'] : 0) + 12;
            if ($bottom > $h) {
                $h = $bottom;
            }
        }
    }
    return $h + 14;
}

function invoice_sheet_mm($design)
{
    $sizes = invoice_paper_sizes();
    $key = (is_array($design) && !empty($design['paper']) && isset($sizes[$design['paper']])) ? $design['paper'] : 'strip80';
    $meta = $sizes[$key];
    $w = (int) $meta['w'];
    $h = (int) $meta['h'];
    $land = is_array($design) && isset($design['orient']) && $design['orient'] === 'landscape';
    $fixed = $h > 0;
    if ($land) {
        if ($h > 0) {
            $tmp = $w;
            $w = $h;
            $h = $tmp;
        } else {
            $w = (int) max(160, round($meta['w'] * 2.2));
            $h = (int) $meta['w'];
            $fixed = true;
        }
    }
    if (!$fixed) {
        $h = (int) ceil(invoice_design_height_mm($design));
    }
    return array('w' => $w, 'h' => $h, 'fixed' => $fixed ? 1 : 0);
}

function invoice_apply_post($tenantId)
{
    $section = function_exists('post') ? (string) post('section') : '';
    if ($section === 'invoice_printer') {
        $rows = invoice_printers_load($tenantId);
        $act = function_exists('post') ? (string) post('printer_act') : '';
        if ($act === 'delete') {
            $id = (string) post('printer_id');
            $next = array();
            foreach ($rows as $r) {
                if (!is_array($r) || (string) $r['id'] === $id) {
                    continue;
                }
                $next[] = $r;
            }
            invoice_printers_save($tenantId, $next);
            return 'deleted';
        }
        if ($act === 'default') {
            $id = (string) post('printer_id');
            foreach ($rows as $i => $r) {
                if (!is_array($r)) {
                    continue;
                }
                $rows[$i]['is_default'] = ((string) $r['id'] === $id) ? 1 : 0;
            }
            invoice_printers_save($tenantId, $rows);
            return 'default';
        }
        $ip = trim((string) post('printer_ip'));
        $port = (int) post('printer_port', '9100');
        if ($port <= 0) {
            $port = 9100;
        }
        $name = trim((string) post('printer_name'));
        if ($name === '') {
            $name = $ip;
        }
        $ok = invoice_probe_printer($ip, $port);
        $row = array(
            'id' => substr(md5($ip . ':' . $port . microtime(true)), 0, 10),
            'name' => $name,
            'ip' => $ip,
            'port' => $port,
            'online' => $ok ? 1 : 0,
            'is_default' => $rows ? 0 : 1,
        );
        $rows[] = $row;
        invoice_printers_save($tenantId, $rows);
        return $ok ? 'added' : 'added_off';
    }
    if ($section === 'invoice_design') {
        $act = (string) post('template_act', 'save');
        $lib = invoice_library_load($tenantId);
        $postedId = (string) post('template_id');
        if ($act === 'delete') {
            if (count($lib['templates']) > 1) {
                $next = array();
                foreach ($lib['templates'] as $t) {
                    if (!is_array($t) || (string) $t['id'] === $postedId) {
                        continue;
                    }
                    $next[] = $t;
                }
                if ($next) {
                    $lib['templates'] = $next;
                    $lib['active'] = (string) $next[0]['id'];
                    invoice_library_save($tenantId, $lib);
                }
            }
            return 'deleted';
        }
        $design = null;
        $keepLogo = '';
        if ($act !== 'add') {
            foreach ($lib['templates'] as $t) {
                if (is_array($t) && (string) $t['id'] === $postedId) {
                    $design = $t;
                    $keepLogo = isset($t['logo']) ? (string) $t['logo'] : '';
                    break;
                }
            }
        }
        if (!is_array($design)) {
            $design = invoice_default_design();
        }
        $paper = (string) post('paper');
        if (isset(invoice_paper_sizes()[$paper])) {
            $design['paper'] = $paper;
        }
        $design['name'] = trim((string) post('template_name'));
        if ($design['name'] === '') {
            $design['name'] = ($act === 'add') ? 'قالب جديد' : 'قالب';
        }
        $frame = (string) post('frame', 'none');
        $design['frame'] = in_array($frame, array('none', 'line', 'double', 'dashed', 'round'), true) ? $frame : 'none';
        $design['orient'] = ((string) post('orient', 'portrait') === 'landscape') ? 'landscape' : 'portrait';
        $mt = (float) post('margin_top', '3');
        if ($mt < 0) {
            $mt = 0;
        }
        if ($mt > 12) {
            $mt = 12;
        }
        $design['margin_top'] = $mt;
        $design['company_name'] = trim((string) post('company_name'));
        $design['phone'] = trim((string) post('phone'));
        $design['address'] = trim((string) post('address'));
        $raw = (string) post('blocks_json');
        $blocks = json_decode($raw, true);
        if (is_array($blocks)) {
            $design['blocks'] = $blocks;
        }
        if ($act === 'add') {
            $design['id'] = substr(md5(uniqid('tpl', true)), 0, 8);
            $design['logo'] = '';
        } else {
            $design['id'] = $postedId !== '' ? $postedId : (isset($design['id']) ? (string) $design['id'] : 'main');
            $design['logo'] = $keepLogo;
        }
        if (!empty($_FILES['logo']['tmp_name'])) {
            $ext = strtolower(pathinfo((string) $_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, array('png', 'jpg', 'jpeg', 'webp', 'gif'), true)) {
                $dir = dirname(__DIR__) . '/public/uploads/invoices';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $rel = 'uploads/invoices/t' . (int) $tenantId . '_' . substr(md5($design['id'] . microtime(true)), 0, 6) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
                if (@move_uploaded_file($_FILES['logo']['tmp_name'], dirname(__DIR__) . '/public/' . $rel)) {
                    $design['logo'] = $rel;
                }
            }
        }
        invoice_design_save($tenantId, $design);
        return $act === 'add' ? 'added' : 'saved';
    }
    return '';
}

function invoice_render_tab($tab, $tenantId, $isEn)
{
    $rows = invoice_printers_load($tenantId);
    $lib = invoice_library_load($tenantId);
    $blank = invoice_default_design();
    $blank['id'] = 'new';
    $blank['name'] = $isEn ? 'New template' : 'قالب جديد';
    $kinds = array(
        array('id' => 'custom', 'label' => $isEn ? 'Text' : 'نص'),
        array('id' => 'name', 'label' => $isEn ? 'Subscriber' : 'اسم المشترك'),
        array('id' => 'package', 'label' => $isEn ? 'Package' : 'الاشتراك'),
        array('id' => 'start', 'label' => $isEn ? 'Start' : 'التفعيل'),
        array('id' => 'end', 'label' => $isEn ? 'End' : 'الانتهاء'),
        array('id' => 'amount', 'label' => $isEn ? 'Amount' : 'المبلغ'),
        array('id' => 'company', 'label' => $isEn ? 'Company' : 'الشركة'),
        array('id' => 'phone', 'label' => $isEn ? 'Phone' : 'الهاتف'),
        array('id' => 'address', 'label' => $isEn ? 'Address' : 'العنوان'),
        array('id' => 'logo', 'label' => $isEn ? 'Logo' : 'الشعار'),
        array('id' => 'qr', 'label' => 'QR'),
    );
    $frames = array(
        'none' => $isEn ? 'None' : 'بدون',
        'line' => $isEn ? 'Line' : 'خط',
        'double' => $isEn ? 'Double' : 'مزدوج',
        'dashed' => $isEn ? 'Dashed' : 'متقطع',
        'round' => $isEn ? 'Round' : 'دائري',
    );
    $payload = array(
        'footer' => invoice_system_footer(),
        'sizes' => invoice_paper_sizes(),
        'templates' => $lib['templates'],
        'blank' => $blank,
        'kinds' => $kinds,
        'frames' => $frames,
        'ui' => array(
            'templates' => $isEn ? 'Templates' : 'القوالب',
            'pick' => $isEn ? 'Choose a template to preview' : 'اختر قالباً للاستعراض',
            'new' => $isEn ? 'New template' : 'قالب جديد',
            'edit' => $isEn ? 'Edit' : 'تعديل',
            'save' => $isEn ? 'Save' : 'حفظ',
            'cancel' => $isEn ? 'Cancel' : 'إلغاء',
            'back' => $isEn ? 'Templates' : 'القوالب',
            'pdf' => $isEn ? 'PDF' : 'تصدير PDF',
            'print' => $isEn ? 'Print' : 'طباعة',
            'printers' => $isEn ? 'Printers' : 'الطابعات',
            'discard' => $isEn ? 'Discard changes?' : 'تجاهل التعديلات؟',
            'delTpl' => $isEn ? 'Delete this template?' : 'حذف القالب؟',
            'delItem' => $isEn ? 'Remove' : 'حذف',
            'text' => $isEn ? 'Text' : 'النص',
            'size' => $isEn ? 'Size' : 'الحجم',
            'color' => $isEn ? 'Color' : 'اللون',
            'align' => $isEn ? 'Align' : 'الاتجاه',
            'right' => $isEn ? 'Right' : 'يمين',
            'center' => $isEn ? 'Center' : 'وسط',
            'left' => $isEn ? 'Left' : 'يسار',
            'ar' => $isEn ? 'Arabic' : 'عربي',
            'ltr' => $isEn ? 'English' : 'إنجليزي',
            'hug' => $isEn ? 'Fit text' : 'على قدر النص',
            'add' => $isEn ? 'Add' : 'إضافة',
            'paper' => $isEn ? 'Paper' : 'الورق',
            'orient' => $isEn ? 'Direction' : 'اتجاه الورقة',
            'portrait' => $isEn ? 'Portrait' : 'عمودي',
            'landscape' => $isEn ? 'Landscape' : 'أفقي',
            'margin' => $isEn ? 'Top margin' : 'هامش علوي',
            'frame' => $isEn ? 'Frame' : 'الإطار',
            'details' => $isEn ? 'Details' : 'البيانات',
            'company' => $isEn ? 'Company' : 'الشركة',
            'phone' => $isEn ? 'Phone' : 'الهاتف',
            'address' => $isEn ? 'Address' : 'العنوان',
            'logo' => $isEn ? 'Logo' : 'الشعار',
            'swapLogo' => $isEn ? 'Replace logo' : 'تبديل الشعار',
            'oneCol' => $isEn ? 'One column' : 'عمود واحد',
            'beside' => $isEn ? 'Side by side' : 'بجانب بعض',
            'guides' => $isEn ? 'Align' : 'محاذاة',
            'exporting' => $isEn ? 'Exporting…' : 'جارٍ التصدير…',
            'fail' => $isEn ? 'Failed' : 'تعذر التصدير',
            'close' => $isEn ? 'Close' : 'إغلاق',
        ),
    );
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $json = '{}';
    }
    ?>
    <script>document.body.classList.add('inv-fit');</script>
    <style>
    body.inv-fit { overflow: hidden; }
    body.inv-fit .app { height: 100vh; overflow: hidden; }
    body.inv-fit .main { height: 100vh; overflow: hidden; display: flex; flex-direction: column; }
    body.inv-fit .sidebar { max-height: 100vh; overflow: auto; }
    body.inv-fit .container { flex: 1; min-height: 0; margin: 8px 12px 10px; overflow: hidden; display: flex; flex-direction: column; }
    body.inv-fit .footer { display: none; }
    body.inv-fit [hidden] { display: none !important; }
    .inv-app { flex: 1; min-height: 0; width: 100%; display: flex; flex-direction: column; }
    .inv-gallery, .inv-desk { flex: 1; min-height: 0; min-width: 0; background: linear-gradient(180deg,#f8fafc,#eef2f6); border: 1px solid #e6ebf2; border-radius: 22px; overflow: hidden; }
    .inv-gallery:not([hidden]), .inv-desk:not([hidden]) { display: flex; flex-direction: column; }
    .inv-gbar, .inv-top { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: rgba(255,255,255,.94); border-bottom: 1px solid #e6ebf2; flex: 0 0 auto; }
    .inv-gbar h2 { margin: 0; font-size: 18px; }
    .inv-gbar p { margin: 2px 0 0; color: #64748b; font-size: 12px; font-weight: 700; }
    .inv-gbar-acts, .inv-top-acts { display: flex; gap: 6px; margin-inline-start: auto; }
    .inv-name { border: 0; background: transparent; font: inherit; font-weight: 800; font-size: 16px; min-width: 0; flex: 1; color: inherit; }
    .inv-len { color: #0f766e; font-weight: 800; font-size: 12px; white-space: nowrap; }
    .inv-cards { display: grid; grid-template-columns: repeat(auto-fill,minmax(190px,1fr)); gap: 12px; padding: 14px; overflow: auto; min-height: 0; }
    .inv-card { position: relative; background: #fff; border: 1px solid #e6ebf2; border-radius: 18px; padding: 10px; cursor: pointer; box-shadow: 0 10px 28px rgba(15,23,42,.05); text-align: center; }
    .inv-card:hover { border-color: #99f6e4; transform: translateY(-2px); }
    .inv-card strong { display: block; margin-top: 8px; }
    .inv-card em { display: block; color: #64748b; font-style: normal; font-size: 12px; font-weight: 700; }
    .inv-thumb { height: 150px; border-radius: 12px; background: #f1f5f9; overflow: hidden; display: flex; align-items: flex-start; justify-content: center; }
    .inv-mini { position: relative; }
    .inv-card-del { position: absolute; top: 8px; inset-inline-end: 8px; width: 26px; height: 26px; border: 0; border-radius: 999px; background: #fff; color: #b91c1c; font-weight: 800; cursor: pointer; box-shadow: 0 4px 12px rgba(15,23,42,.12); }
    .inv-prop { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #fff; border-bottom: 1px solid #e6ebf2; overflow-x: auto; flex: 0 0 auto; }
    .inv-prop[hidden] { display: none !important; }
    .inv-field { display: flex; align-items: center; gap: 6px; flex: 0 0 auto; }
    .inv-field > span { font-size: 11px; font-weight: 800; color: #64748b; }
    .inv-prop input[type="text"], .inv-prop input[type="number"] { height: 32px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0 8px; background: #fff; }
    #pText { width: 150px; }
    .inv-prop input[type="number"] { width: 62px; }
    #pSize { width: 88px; }
    #pColor { width: 36px; height: 32px; border: 0; background: transparent; padding: 0; }
    .inv-seg { display: flex; background: #f1f5f9; border-radius: 10px; padding: 2px; }
    .inv-seg button { border: 0; background: transparent; height: 28px; padding: 0 8px; border-radius: 8px; font-weight: 800; cursor: pointer; color: #334155; }
    .inv-seg button.is-on { background: #fff; color: #0f766e; box-shadow: 0 1px 4px rgba(15,23,42,.08); }
    .inv-check { display: flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 800; color: #334155; white-space: nowrap; }
    .inv-body { flex: 1; min-height: 0; display: flex; gap: 10px; padding: 10px; }
    .inv-rail { width: 58px; flex: 0 0 58px; display: flex; flex-direction: column; gap: 8px; align-items: center; align-self: center; padding: 8px; background: #fff; border: 1px solid #e6ebf2; border-radius: 18px; box-shadow: 0 10px 30px rgba(15,23,42,.06); }
    .inv-rail button { width: 42px; height: 42px; border: 0; border-radius: 12px; background: transparent; color: #334155; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .inv-rail button.is-on, .inv-rail button:hover { background: #ccfbf1; color: #0f766e; }
    .inv-stage { position: relative; flex: 1; min-width: 0; min-height: 0; display: flex; align-items: flex-start; justify-content: center; overflow: auto; border-radius: 16px; background: #e7edf3; padding: 18px 12px 28px; }
    .inv-fit { position: relative; flex: 0 0 auto; margin: 0 auto; }
    .inv-paper { position: absolute; left: 0; top: 0; background: #fff; box-shadow: 0 16px 40px rgba(15,23,42,.14); overflow: visible; box-sizing: border-box; }
    .inv-paper.frame-line { border: 1.5px solid #0f172a; }
    .inv-paper.frame-double { border: 4px double #0f172a; }
    .inv-paper.frame-dashed { border: 1.5px dashed #334155; }
    .inv-paper.frame-round { border: 1.5px solid #0f172a; border-radius: 14px; }
    .inv-block { position: absolute; margin: 0; padding: 0; line-height: 1; letter-spacing: 0; background: transparent; border: 0; white-space: nowrap; box-sizing: border-box; user-select: none; }
    .inv-block.is-hug { width: max-content; }
    .inv-app.is-edit .inv-block { cursor: grab; }
    .inv-block.is-pick { outline: 1.5px solid #0f766e; outline-offset: 1px; }
    .inv-val { padding: 0; margin: 0; }
    .inv-resize { display: none; position: absolute; width: 8px; height: 8px; border-radius: 2px; background: #0f766e; bottom: -4px; inset-inline-end: -4px; cursor: ew-resize; }
    .inv-guide { position: absolute; pointer-events: none; z-index: 6; display: none; }
    .inv-guide.is-on { display: block; }
    .inv-guide-v { top: 0; bottom: 0; width: 0; border-left: 1px dashed #0f766e; }
    .inv-guide-h { left: 0; right: 0; height: 0; border-top: 1px dashed #e11d48; }
    .inv-app.is-edit .inv-block.is-pick .inv-resize { display: block; }
    .inv-block img { width: 100%; height: auto; display: block; }
    .inv-logo-ph { display: flex; align-items: center; justify-content: center; width: 100%; height: 12mm; background: #f8fafc; color: #94a3b8; font-size: 10px; }
    .inv-foot { position: absolute; left: 0; right: 0; bottom: 0; text-align: center; font-size: 9px; line-height: 1.2; font-weight: 700; color: #94a3b8; padding: 1px 0 2px; }
    .inv-pop { position: absolute; top: 12px; inset-inline-start: 12px; width: 240px; max-height: calc(100% - 24px); overflow: auto; background: #fff; border-radius: 16px; border: 1px solid #e6ebf2; box-shadow: 0 18px 48px rgba(15,23,42,.16); padding: 12px; z-index: 5; }
    .inv-pop h3 { margin: 0 0 8px; font-size: 14px; }
    .inv-pop > button { margin-top: 10px; height: 34px; width: 100%; border-radius: 10px; border: 1px solid #e2e8f0; background: #fff; font-weight: 800; cursor: pointer; }
    .inv-pop label { display: block; margin: 8px 0 4px; font-size: 12px; font-weight: 800; color: #64748b; }
    .inv-pop select, .inv-pop input[type="text"] { width: 100%; height: 36px; border-radius: 10px; border: 1px solid #e2e8f0; padding: 0 8px; background: #fff; }
    .inv-kind, .inv-frames { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .inv-kind button, .inv-frames button { height: 34px; border-radius: 10px; border: 1px solid #e2e8f0; background: #fff; font-weight: 800; cursor: pointer; }
    .inv-frames button.is-on, .inv-kind button:hover { border-color: #0f766e; color: #0f766e; }
    .inv-bottom { display: flex; justify-content: flex-end; gap: 8px; padding: 10px 12px; background: #fff; border-top: 1px solid #e6ebf2; flex: 0 0 auto; }
    .inv-modal { position: fixed; inset: 0; background: rgba(15,23,42,.38); z-index: 80; display: flex; align-items: center; justify-content: center; padding: 16px; }
    .inv-modal[hidden] { display: none !important; }
    .inv-modal-card { width: min(560px,100%); max-height: min(78vh,640px); overflow: auto; background: #fff; border-radius: 20px; padding: 16px; box-shadow: 0 24px 60px rgba(15,23,42,.22); }
    .inv-modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .inv-add { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .inv-add input { height: 40px; border-radius: 12px; border: 1px solid #e2e8f0; padding: 0 10px; }
    .inv-printer { display: flex; justify-content: space-between; gap: 10px; align-items: center; border: 1px solid #e6ebf2; border-radius: 14px; padding: 10px 12px; margin-bottom: 8px; }
    .inv-printer.is-def { border-color: #99f6e4; }
    .inv-printer span, .inv-printer em { display: block; color: #64748b; font-size: 12px; font-style: normal; font-weight: 700; }
    .inv-acts { display: flex; gap: 6px; align-items: center; }
    .inv-def { background: #ccfbf1; color: #0f766e; font-weight: 800; border-radius: 999px; padding: 4px 10px; }
    @media (max-width: 760px) {
      .inv-rail { flex-direction: row; width: auto; flex: 0 0 auto; }
      .inv-body { flex-direction: column; }
      .inv-gbar { flex-wrap: wrap; }
    }
    </style>
    <div class="inv-app" id="invApp">
        <section class="inv-gallery" id="invGallery">
            <div class="inv-gbar">
                <div>
                    <h2><?php echo e($payload['ui']['templates']); ?></h2>
                    <p><?php echo e($payload['ui']['pick']); ?></p>
                </div>
                <div class="inv-gbar-acts">
                    <button class="btn ghost sm" type="button" id="invPrintOpen"><?php echo e($payload['ui']['printers']); ?></button>
                    <button class="btn sm" type="button" id="invNew"><?php echo e($payload['ui']['new']); ?></button>
                </div>
            </div>
            <div class="inv-cards" id="invCards"></div>
        </section>
        <form method="post" action="settings.php?tab=invoices" enctype="multipart/form-data" id="invDesignForm" class="inv-desk" hidden>
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="section" value="invoice_design">
            <input type="hidden" name="template_id" id="fId" value="">
            <input type="hidden" name="template_act" id="fAct" value="save">
            <input type="hidden" name="paper" id="fPaper" value="">
            <input type="hidden" name="orient" id="fOrient" value="">
            <input type="hidden" name="margin_top" id="fMargin" value="3">
            <input type="hidden" name="frame" id="fFrame" value="">
            <input type="hidden" name="company_name" id="fCompany" value="">
            <input type="hidden" name="phone" id="fPhone" value="">
            <input type="hidden" name="address" id="fAddress" value="">
            <input type="hidden" name="blocks_json" id="fBlocks" value="">
            <input type="file" name="logo" id="invLogo" accept="image/*" hidden>
            <div class="inv-top">
                <button class="btn ghost sm" type="button" id="invBack"><?php echo e($payload['ui']['back']); ?></button>
                <input class="inv-name" name="template_name" id="invName" value="">
                <span class="inv-len" id="invLen"></span>
                <div class="inv-top-acts">
                    <button class="btn ghost sm" type="button" id="invPrintOpen2"><?php echo e($payload['ui']['printers']); ?></button>
                    <button class="btn ghost sm" type="button" id="invTestPrint"><?php echo e($payload['ui']['print']); ?></button>
                    <button class="btn ghost sm" type="button" id="invPdf"><?php echo e($payload['ui']['pdf']); ?></button>
                    <button class="btn danger sm" type="button" id="invDelTpl"><?php echo e($isEn ? 'Delete' : 'حذف القالب'); ?></button>
                    <button class="btn sm" type="button" id="invEditBtn"><?php echo e($payload['ui']['edit']); ?></button>
                </div>
            </div>
            <div class="inv-prop" id="invProp" hidden>
                <label class="inv-field inv-for-text"><span><?php echo e($payload['ui']['text']); ?></span><input id="pText" type="text"></label>
                <label class="inv-field inv-for-text"><span><?php echo e($payload['ui']['size']); ?></span><input id="pSize" type="range" min="6" max="72" step="1"><input id="pSizeN" type="number" min="6" max="72"></label>
                <label class="inv-field inv-for-text"><span><?php echo e($payload['ui']['color']); ?></span><input id="pColor" type="color"></label>
                <div class="inv-seg inv-for-text" id="pAlign">
                    <button type="button" data-v="right"><?php echo e($payload['ui']['right']); ?></button>
                    <button type="button" data-v="center"><?php echo e($payload['ui']['center']); ?></button>
                    <button type="button" data-v="left"><?php echo e($payload['ui']['left']); ?></button>
                </div>
                <div class="inv-seg inv-for-text" id="pDir">
                    <button type="button" data-v="rtl"><?php echo e($payload['ui']['ar']); ?></button>
                    <button type="button" data-v="ltr"><?php echo e($payload['ui']['ltr']); ?></button>
                </div>
                <label class="inv-check inv-for-text"><input id="pHug" type="checkbox"> <?php echo e($payload['ui']['hug']); ?></label>
                <label class="inv-field"><span>X</span><input id="pX" type="number" step="0.5"></label>
                <label class="inv-field"><span>Y</span><input id="pY" type="number" step="0.5"></label>
                <button class="btn danger sm" type="button" id="pDel"><?php echo e($payload['ui']['delItem']); ?></button>
                <button class="btn ghost sm inv-for-logo" type="button" id="pLogo" hidden><?php echo e($payload['ui']['swapLogo']); ?></button>
            </div>
            <div class="inv-body">
                <aside class="inv-rail" id="invRail">
                    <button type="button" data-tool="add" title="<?php echo e($payload['ui']['add']); ?>"></button>
                    <button type="button" data-tool="align" title="<?php echo e($payload['ui']['guides']); ?>"></button>
                    <button type="button" data-tool="paper" title="<?php echo e($payload['ui']['paper']); ?>"></button>
                    <button type="button" data-tool="frame" title="<?php echo e($payload['ui']['frame']); ?>"></button>
                    <button type="button" data-tool="company" title="<?php echo e($payload['ui']['details']); ?>"></button>
                </aside>
                <div class="inv-stage" id="invStage">
                    <div class="inv-fit" id="invFit"><div class="inv-paper" id="invPaperBox"></div></div>
                    <div class="inv-pop" id="invPop" hidden></div>
                </div>
            </div>
            <div class="inv-bottom" id="invBottom">
                <button class="btn ghost" type="button" id="invCancel"><?php echo e($payload['ui']['cancel']); ?></button>
                <button class="btn" type="button" id="invSave"><?php echo e($payload['ui']['save']); ?></button>
            </div>
        </form>
    </div>
    <div class="inv-modal" id="invPrintModal" hidden>
        <div class="inv-modal-card">
            <div class="inv-modal-head">
                <strong><?php echo e($payload['ui']['printers']); ?></strong>
                <button class="btn ghost sm" type="button" id="invPrintClose"><?php echo e($payload['ui']['close']); ?></button>
            </div>
            <form method="post" action="settings.php?tab=invoices" class="inv-add">
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="section" value="invoice_printer">
                <input type="hidden" name="printer_act" value="add">
                <input name="printer_name" placeholder="<?php echo e($isEn ? 'Name' : 'الاسم'); ?>">
                <input class="ltr" name="printer_ip" placeholder="IP" required>
                <input class="ltr" name="printer_port" value="9100" inputmode="numeric">
                <button class="btn sm" type="submit"><?php echo e($isEn ? 'Add' : 'إضافة'); ?></button>
            </form>
            <?php foreach ($rows as $r): if (!is_array($r)) continue; ?>
            <div class="inv-printer<?php echo !empty($r['is_default']) ? ' is-def' : ''; ?>">
                <div>
                    <strong><?php echo e($r['name']); ?></strong>
                    <span class="ltr"><?php echo e($r['ip'] . ':' . $r['port']); ?></span>
                    <em><?php echo !empty($r['online']) ? ($isEn ? 'Online' : 'متصلة') : ($isEn ? 'Offline' : 'غير متصلة'); ?></em>
                </div>
                <div class="inv-acts">
                    <?php if (empty($r['is_default'])): ?>
                    <form method="post" action="settings.php?tab=invoices">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="section" value="invoice_printer">
                        <input type="hidden" name="printer_act" value="default">
                        <input type="hidden" name="printer_id" value="<?php echo e($r['id']); ?>">
                        <button class="btn ghost sm" type="submit"><?php echo e($isEn ? 'Default' : 'افتراضية'); ?></button>
                    </form>
                    <?php else: ?>
                    <span class="inv-def"><?php echo e($isEn ? 'Default' : 'الافتراضية'); ?></span>
                    <?php endif; ?>
                    <form method="post" action="settings.php?tab=invoices" onsubmit="return confirm('<?php echo e($isEn ? 'Delete?' : 'حذف؟'); ?>');">
                        <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="section" value="invoice_printer">
                        <input type="hidden" name="printer_act" value="delete">
                        <input type="hidden" name="printer_id" value="<?php echo e($r['id']); ?>">
                        <button class="btn danger sm" type="submit"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <script>window.__INV = <?php echo $json; ?>;</script>
    <script src="assets/html2canvas.min.js"></script>
    <script src="assets/qrcode.js"></script>
    <script>
    (function () {
      var DATA = window.__INV || {};
      var UI = DATA.ui || {};
      var app = document.getElementById('invApp');
      var gallery = document.getElementById('invGallery');
      var cards = document.getElementById('invCards');
      var form = document.getElementById('invDesignForm');
      var paper = document.getElementById('invPaperBox');
      var fitEl = document.getElementById('invFit');
      var stage = document.getElementById('invStage');
      var pop = document.getElementById('invPop');
      var prop = document.getElementById('invProp');
      var rail = document.getElementById('invRail');
      var len = document.getElementById('invLen');
      var nameInput = document.getElementById('invName');
      var mode = 'gallery';
      var current = null;
      var snapshot = '';
      var isNew = false;
      var picked = null;
      var openTool = '';
      var drag = null;
      var ICO = {
        add: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg>',
        paper: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="6" y="3" width="12" height="18" rx="1.5"/></svg>',
        frame: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>',
        company: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3"/><path d="M6 19c1.2-3 3.2-4.5 6-4.5s4.8 1.5 6 4.5"/></svg>',
        align: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v18M3 12h18"/></svg>'
      };
      var railBtns = rail.querySelectorAll('button');
      for (var ri = 0; ri < railBtns.length; ri++) railBtns[ri].innerHTML = ICO[railBtns[ri].getAttribute('data-tool')] || '';

      function ymd(d) {
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
      }
      function sample(kind, design) {
        var today = new Date();
        var end = new Date(today.getTime() + 30 * 86400000);
        var map = {
          company: design.company_name || 'اسم الشركة',
          phone: design.phone || '07xx xxx xxxx',
          address: design.address || 'عنوان الشركة',
          name: 'أحمد محمد',
          package: 'NB MAX-MSL',
          start: ymd(today),
          end: ymd(end),
          amount: '35,000',
          custom: 'نص'
        };
        return map[kind] || 'نص';
      }
      function norm(b) {
        var align = (b.align === 'left' || b.align === 'center' || b.align === 'right') ? b.align : 'right';
        var color = /^#[0-9a-fA-F]{6}$/.test(b.color || '') ? b.color : '#111827';
        return {
          id: String(b.id || ('b' + Math.random().toString(16).slice(2, 8))),
          kind: String(b.kind || b.id || 'custom'),
          x: parseFloat(b.x) || 0,
          y: parseFloat(b.y) || 0,
          w: parseFloat(b.w) > 0 ? parseFloat(b.w) : 0,
          size: Math.max(6, Math.min(72, parseFloat(b.size) || 13)),
          color: color,
          align: align,
          dir: b.dir === 'ltr' ? 'ltr' : 'rtl',
          text: b.text ? String(b.text) : '',
          on: 1
        };
      }
      function cloneDesign(d) {
        var o = {
          id: d.id, name: d.name || '', paper: d.paper || 'strip80',
          orient: d.orient === 'landscape' ? 'landscape' : 'portrait',
          frame: d.frame || 'none', company_name: d.company_name || '',
          phone: d.phone || '', address: d.address || '', logo: d.logo || '',
          margin_top: (isFinite(parseFloat(d.margin_top)) ? Math.max(0, Math.min(12, parseFloat(d.margin_top))) : 3),
          blocks: []
        };
        var blocks = d.blocks || [];
        for (var i = 0; i < blocks.length; i++) {
          if (blocks[i] && blocks[i].on !== 0 && blocks[i].on !== '0') o.blocks.push(norm(blocks[i]));
        }
        return o;
      }
      function findTpl(id) {
        var list = DATA.templates || [];
        for (var i = 0; i < list.length; i++) if (String(list[i].id) === String(id)) return list[i];
        return list[0] || DATA.blank;
      }
      function contentH(design) {
        var h = 12;
        var blocks = design.blocks || [];
        for (var i = 0; i < blocks.length; i++) {
          var b = blocks[i];
          var bh = (b.kind === 'logo' || b.kind === 'qr') ? (parseFloat(b.w) > 0 ? parseFloat(b.w) : 18) : (((parseFloat(b.size) || 13) * 25.4 / 96) + 1);
          var bottom = (parseFloat(b.y) || 0) + bh;
          if (bottom > h) h = bottom;
        }
        return Math.max(18, Math.ceil(h + 6));
      }
      function boxMm(design) {
        var sizes = DATA.sizes || {};
        var meta = sizes[design.paper] || sizes.strip80 || {w: 80, h: 0};
        var w = parseFloat(meta.w) || 80;
        var h = parseFloat(meta.h) || 0;
        var fixed = h > 0;
        if (design.orient === 'landscape') {
          if (h > 0) { var t = w; w = h; h = t; }
          else { w = Math.max(160, Math.round(w * 2.2)); h = parseFloat(meta.w) || 80; fixed = true; }
        }
        if (!fixed) h = contentH(design);
        return {w: w, h: h, fixed: fixed};
      }
      function blockById(id) {
        var blocks = current && current.blocks ? current.blocks : [];
        for (var i = 0; i < blocks.length; i++) if (blocks[i].id === id) return blocks[i];
        return null;
      }
      function blockNode(b, design) {
        var el = document.createElement('div');
        el.className = 'inv-block' + (b.w > 0 ? '' : ' is-hug');
        el.setAttribute('data-id', b.id);
        el.style.left = (parseFloat(b.x) || 0) + 'mm';
        el.style.top = (parseFloat(b.y) || 0) + 'mm';
        if (b.kind === 'logo') {
          el.classList.remove('is-hug');
          el.style.width = (b.w > 0 ? b.w : 18) + 'mm';
          if (design.logo) {
            var img = document.createElement('img');
            img.alt = '';
            img.src = design.logo;
            el.appendChild(img);
          } else {
            var ph = document.createElement('span');
            ph.className = 'inv-logo-ph';
            ph.textContent = UI.logo || 'شعار';
            el.appendChild(ph);
          }
        } else if (b.kind === 'qr') {
          el.classList.remove('is-hug');
          var qmm = b.w > 0 ? b.w : 22;
          el.style.width = qmm + 'mm';
          el.style.height = qmm + 'mm';
          var qimg = document.createElement('img');
          qimg.alt = 'QR';
          qimg.src = qrDataUrl(receiptCopy(design));
          qimg.style.width = '100%';
          qimg.style.height = '100%';
          qimg.style.background = '#fff';
          el.appendChild(qimg);
        } else {
          if (b.w > 0) el.style.width = b.w + 'mm';
          el.style.fontSize = (b.size || 13) + 'px';
          el.style.color = b.color || '#111827';
          el.style.textAlign = b.align || 'right';
          el.style.direction = b.dir || 'rtl';
          var span = document.createElement('span');
          span.className = 'inv-val';
          span.textContent = b.text ? b.text : sample(b.kind, design);
          el.appendChild(span);
        }
        var handle = document.createElement('i');
        handle.className = 'inv-resize';
        el.appendChild(handle);
        return el;
      }
      function paint(el, design) {
        var box = boxMm(design);
        el.className = 'inv-paper frame-' + (design.frame || 'none');
        el.style.width = box.w + 'mm';
        el.style.height = box.h + 'mm';
        el.innerHTML = '';
        var blocks = design.blocks || [];
        for (var i = 0; i < blocks.length; i++) el.appendChild(blockNode(blocks[i], design));
        var gv = document.createElement('i');
        gv.className = 'inv-guide inv-guide-v';
        var gh = document.createElement('i');
        gh.className = 'inv-guide inv-guide-h';
        el.appendChild(gv);
        el.appendChild(gh);
        var foot = document.createElement('div');
        foot.className = 'inv-foot';
        foot.textContent = DATA.footer || '';
        el.appendChild(foot);
        return box;
      }
      function fitInto(box, paperEl, maxW, maxH, cap) {
        paperEl.style.transform = 'none';
        var pw = paperEl.offsetWidth || 1;
        var ph = paperEl.offsetHeight || 1;
        var sc = Math.min(maxW / pw, maxH / ph);
        if (!isFinite(sc) || sc <= 0) sc = 1;
        if (cap && sc > cap) sc = cap;
        paperEl.style.transformOrigin = '0 0';
        paperEl.style.transform = 'scale(' + sc + ')';
        box.style.width = Math.max(1, Math.ceil(pw * sc)) + 'px';
        box.style.height = Math.max(1, Math.ceil(ph * sc)) + 'px';
      }
      function fitMain() {
        if (!current || !paper) return;
        var box = paint(paper, current);
        fitInto(fitEl, paper, Math.max(80, stage.clientWidth - 36), Math.max(80, stage.clientHeight - 36), 1.6);
        if (len) len.textContent = (box.w / 10).toFixed(1) + ' × ' + (box.h / 10).toFixed(1) + ' سم';
        clampInside(box);
        if (picked) {
          var el = qBlock(picked);
          if (el) el.classList.add('is-pick');
        }
      }
      function clampInside(box) {
        if (!current || !paper) return;
        var rect = paper.getBoundingClientRect();
        if (!rect.width || !box) return;
        var nodes = paper.querySelectorAll('.inv-block');
        var changed = false;
        for (var i = 0; i < nodes.length; i++) {
          var b = blockById(nodes[i].getAttribute('data-id'));
          if (!b) continue;
          var br = nodes[i].getBoundingClientRect();
          var right = (br.right - rect.left) / rect.width * box.w;
          if (right > box.w - 0.6) {
            b.x = Math.max(0, Math.round((b.x - (right - (box.w - 0.6))) * 10) / 10);
            changed = true;
          }
          if (b.x < 0) { b.x = 0; changed = true; }
        }
        if (!changed) return;
        for (var k = 0; k < current.blocks.length; k++) syncBlock(current.blocks[k]);
      }
      function qBlock(id) {
        var nodes = paper.querySelectorAll('.inv-block');
        for (var i = 0; i < nodes.length; i++) if (nodes[i].getAttribute('data-id') === id) return nodes[i];
        return null;
      }
      function syncBlock(b) {
        var el = qBlock(b.id);
        if (!el) return;
        el.style.left = b.x + 'mm';
        el.style.top = b.y + 'mm';
        if (b.kind === 'logo') {
          el.style.width = (b.w > 0 ? b.w : 18) + 'mm';
          return;
        }
        if (b.kind === 'qr') {
          var qmm = b.w > 0 ? b.w : 22;
          el.style.width = qmm + 'mm';
          el.style.height = qmm + 'mm';
          var qimg = el.querySelector('img');
          if (qimg) qimg.src = qrDataUrl(receiptCopy(current));
          return;
        }
        el.style.fontSize = (b.size || 13) + 'px';
        el.style.color = b.color || '#111827';
        el.style.textAlign = b.align || 'right';
        el.style.direction = b.dir || 'rtl';
        if (b.w > 0) { el.classList.remove('is-hug'); el.style.width = b.w + 'mm'; }
        else { el.classList.add('is-hug'); el.style.width = ''; }
        var val = el.querySelector('.inv-val');
        if (val) val.textContent = b.text ? b.text : sample(b.kind, current);
      }
      function paperLabel(design) {
        var sizes = DATA.sizes || {};
        var meta = sizes[design.paper] || {};
        var orient = design.orient === 'landscape' ? UI.landscape : UI.portrait;
        return (meta.label || design.paper || '') + ' · ' + (orient || '');
      }
      function fitThumbs() {
        var thumbs = cards.querySelectorAll('.inv-thumb');
        for (var i = 0; i < thumbs.length; i++) {
          var mini = thumbs[i].querySelector('.inv-mini');
          var p = mini && mini.querySelector('.inv-paper');
          if (!mini || !p) continue;
          fitInto(mini, p, Math.max(40, thumbs[i].clientWidth - 12), Math.max(40, thumbs[i].clientHeight - 12), 1);
        }
      }
      function showGallery() {
        mode = 'gallery';
        app.classList.remove('is-edit');
        gallery.hidden = false;
        form.hidden = true;
        closePop();
        picked = null;
        cards.innerHTML = '';
        var list = DATA.templates || [];
        for (var i = 0; i < list.length; i++) cards.appendChild(cardEl(list[i], list.length > 1));
        requestAnimationFrame(function () { requestAnimationFrame(fitThumbs); });
      }
      function cardEl(design, canDel) {
        var card = document.createElement('div');
        card.className = 'inv-card';
        card.setAttribute('role', 'button');
        card.tabIndex = 0;
        var thumb = document.createElement('div');
        thumb.className = 'inv-thumb';
        var mini = document.createElement('div');
        mini.className = 'inv-mini';
        var p = document.createElement('div');
        mini.appendChild(p);
        thumb.appendChild(mini);
        paint(p, cloneDesign(design));
        var strong = document.createElement('strong');
        strong.textContent = design.name || '';
        var em = document.createElement('em');
        em.textContent = paperLabel(design);
        card.appendChild(thumb);
        card.appendChild(strong);
        card.appendChild(em);
        card.addEventListener('click', function () { openPreview(design.id); });
        card.addEventListener('keydown', function (e) { if (e.key === 'Enter') openPreview(design.id); });
        if (canDel) {
          var del = document.createElement('button');
          del.type = 'button';
          del.className = 'inv-card-del';
          del.textContent = '×';
          del.addEventListener('click', function (e) {
            e.stopPropagation();
            removeTpl(design.id);
          });
          card.appendChild(del);
        }
        return card;
      }
      function setChrome() {
        var editing = mode === 'edit';
        app.classList.toggle('is-edit', editing);
        rail.hidden = !editing;
        document.getElementById('invBottom').hidden = !editing;
        document.getElementById('invEditBtn').hidden = editing;
        document.getElementById('invDelTpl').hidden = editing || (DATA.templates || []).length < 2;
        nameInput.readOnly = !editing;
        if (!editing) { prop.hidden = true; closePop(); }
      }
      function openPreview(id) {
        current = cloneDesign(findTpl(id));
        isNew = false;
        mode = 'preview';
        picked = null;
        gallery.hidden = true;
        form.hidden = false;
        nameInput.value = current.name || '';
        setChrome();
        requestAnimationFrame(function () { requestAnimationFrame(fitMain); });
      }
      function openEdit(design, asNew) {
        current = cloneDesign(design);
        isNew = !!asNew;
        mode = 'edit';
        picked = null;
        snapshot = JSON.stringify(current);
        gallery.hidden = true;
        form.hidden = false;
        nameInput.value = current.name || '';
        setChrome();
        requestAnimationFrame(function () { requestAnimationFrame(fitMain); });
      }
      function dirty() { return snapshot && JSON.stringify(current) !== snapshot; }
      function cancelEdit() {
        if (dirty() && !window.confirm(UI.discard || 'تجاهل التعديلات؟')) return;
        if (isNew) showGallery();
        else openPreview(current.id);
      }
      function closePop() {
        openTool = '';
        if (pop) pop.hidden = true;
        var btns = rail.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) btns[i].classList.remove('is-on');
      }
      function markRail(name) {
        var btns = rail.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) btns[i].classList.toggle('is-on', btns[i].getAttribute('data-tool') === name);
      }
      function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text) n.textContent = text;
        return n;
      }
      function openToolPanel(name) {
        if (mode !== 'edit') return;
        if (openTool === name) { closePop(); return; }
        openTool = name;
        markRail(name);
        pop.hidden = false;
        pop.innerHTML = '';
        if (name === 'add') buildAdd();
        else if (name === 'align') buildAlign();
        else if (name === 'paper') buildPaper();
        else if (name === 'frame') buildFrame();
        else if (name === 'company') buildCompany();
      }
      function buildAdd() {
        pop.appendChild(el('h3', '', UI.add));
        var grid = el('div', 'inv-kind');
        var kinds = DATA.kinds || [];
        for (var i = 0; i < kinds.length; i++) {
          (function (k) {
            var b = el('button', '', k.label);
            b.type = 'button';
            b.addEventListener('click', function () { addKind(k.id); });
            grid.appendChild(b);
          })(kinds[i]);
        }
        pop.appendChild(grid);
      }
      function buildAlign() {
        pop.appendChild(el('h3', '', UI.guides || 'محاذاة'));
        var col = el('button', '', UI.oneCol || 'عمود واحد');
        col.type = 'button';
        col.addEventListener('click', function () { alignColumn(); closePop(); });
        var beside = el('button', '', UI.beside || 'بجانب بعض');
        beside.type = 'button';
        beside.addEventListener('click', function () { alignBeside(); closePop(); });
        pop.appendChild(col);
        pop.appendChild(beside);
      }
      function buildPaper() {
        pop.appendChild(el('h3', '', UI.paper));
        var sel = document.createElement('select');
        var sizes = DATA.sizes || {};
        for (var k in sizes) if (sizes.hasOwnProperty(k)) {
          var opt = document.createElement('option');
          opt.value = k;
          opt.textContent = sizes[k].label || k;
          if (k === current.paper) opt.selected = true;
          sel.appendChild(opt);
        }
        sel.addEventListener('change', function () { current.paper = sel.value; fitMain(); });
        pop.appendChild(sel);
        pop.appendChild(el('label', '', UI.orient));
        var orient = document.createElement('select');
        [['portrait', UI.portrait], ['landscape', UI.landscape]].forEach(function (pair) {
          var opt = document.createElement('option');
          opt.value = pair[0];
          opt.textContent = pair[1];
          if (current.orient === pair[0]) opt.selected = true;
          orient.appendChild(opt);
        });
        orient.addEventListener('change', function () { current.orient = orient.value; fitMain(); });
        pop.appendChild(orient);
        pop.appendChild(el('label', '', UI.margin || 'هامش علوي'));
        var mt = document.createElement('input');
        mt.type = 'number';
        mt.min = '0';
        mt.max = '12';
        mt.step = '0.5';
        mt.value = String(topMarginMm());
        mt.addEventListener('input', function () {
          var n = parseFloat(mt.value);
          if (!isFinite(n) || n < 0) n = 0;
          if (n > 12) n = 12;
          current.margin_top = n;
        });
        pop.appendChild(mt);
      }
      function buildFrame() {
        pop.appendChild(el('h3', '', UI.frame));
        var grid = el('div', 'inv-frames');
        var frames = DATA.frames || {};
        for (var k in frames) if (frames.hasOwnProperty(k)) {
          (function (id, label) {
            var b = el('button', id === current.frame ? 'is-on' : '', label);
            b.type = 'button';
            b.addEventListener('click', function () {
              current.frame = id;
              var all = grid.querySelectorAll('button');
              for (var n = 0; n < all.length; n++) all[n].classList.toggle('is-on', all[n] === b);
              fitMain();
            });
            grid.appendChild(b);
          })(k, frames[k]);
        }
        pop.appendChild(grid);
      }
      function buildCompany() {
        pop.appendChild(el('h3', '', UI.details));
        [['company_name', UI.company], ['phone', UI.phone], ['address', UI.address]].forEach(function (pair) {
          pop.appendChild(el('label', '', pair[1]));
          var input = document.createElement('input');
          input.type = 'text';
          input.value = current[pair[0]] || '';
          input.addEventListener('input', function () {
            current[pair[0]] = input.value;
            refreshSamples();
          });
          pop.appendChild(input);
        });
        var logoBtn = el('button', '', UI.swapLogo || 'تبديل الشعار');
        logoBtn.type = 'button';
        logoBtn.style.marginTop = '10px';
        logoBtn.addEventListener('click', function () { document.getElementById('invLogo').click(); });
        pop.appendChild(logoBtn);
      }
      function refreshSamples() {
        var blocks = current.blocks || [];
        for (var i = 0; i < blocks.length; i++) {
          if (!blocks[i].text || blocks[i].kind === 'qr') syncBlock(blocks[i]);
        }
      }
      function receiptCopy(design) {
        var lines = [];
        var blocks = (design && design.blocks) ? design.blocks : [];
        for (var i = 0; i < blocks.length; i++) {
          var b = blocks[i];
          if (!b || b.kind === 'logo' || b.kind === 'qr') continue;
          var t = b.text ? String(b.text) : sample(b.kind, design);
          if (t) lines.push(t);
        }
        if (!lines.length) lines.push('وصل');
        return lines.join('\n');
      }
      function qrDataUrl(text) {
        if (!window.qrcode) return '';
        try {
          if (window.qrcode.stringToBytesFuncs && window.qrcode.stringToBytesFuncs['UTF-8']) {
            window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];
          }
          var qr = window.qrcode(0, 'M');
          qr.addData(String(text || 'وصل'));
          qr.make();
          return qr.createDataURL(4, 0);
        } catch (err) {
          return '';
        }
      }
      function blockMm(el, rect, box) {
        var r = el.getBoundingClientRect();
        return {
          l: (r.left - rect.left) / rect.width * box.w,
          r: (r.right - rect.left) / rect.width * box.w,
          t: (r.top - rect.top) / rect.height * box.h,
          b: (r.bottom - rect.top) / rect.height * box.h,
          w: r.width / rect.width * box.w
        };
      }
      function showGuide(xMm, yMm) {
        var v = paper.querySelector('.inv-guide-v');
        var h = paper.querySelector('.inv-guide-h');
        if (v) {
          if (xMm === null || xMm === undefined) v.classList.remove('is-on');
          else { v.style.left = xMm + 'mm'; v.classList.add('is-on'); }
        }
        if (h) {
          if (yMm === null || yMm === undefined) h.classList.remove('is-on');
          else { h.style.top = yMm + 'mm'; h.classList.add('is-on'); }
        }
      }
      function alignColumn() {
        if (!current) return;
        var rect = paper.getBoundingClientRect();
        var box = boxMm(current);
        if (!rect.width) return;
        var anchor = blockById(picked);
        var texts = [];
        for (var i = 0; i < current.blocks.length; i++) {
          if (current.blocks[i].kind !== 'logo' && current.blocks[i].kind !== 'qr') texts.push(current.blocks[i]);
        }
        if (!texts.length) return;
        if (!anchor || anchor.kind === 'logo' || anchor.kind === 'qr') anchor = texts[0];
        var aEl = qBlock(anchor.id);
        var right = aEl ? blockMm(aEl, rect, box).r : (anchor.x + 20);
        for (var k = 0; k < texts.length; k++) {
          var el = qBlock(texts[k].id);
          var w = el ? blockMm(el, rect, box).w : 12;
          texts[k].x = Math.max(0, Math.round((right - w) * 10) / 10);
          texts[k].align = 'right';
        }
        fitMain();
      }
      function alignBeside() {
        if (!current) return;
        var rect = paper.getBoundingClientRect();
        var box = boxMm(current);
        if (!rect.width) return;
        var texts = [];
        for (var i = 0; i < current.blocks.length; i++) {
          if (current.blocks[i].kind !== 'logo' && current.blocks[i].kind !== 'qr') texts.push(current.blocks[i]);
        }
        if (!texts.length) return;
        texts.sort(function (a, b) { return b.x - a.x; });
        var y = texts[0].y;
        var edge = box.w - 2;
        for (var k = 0; k < texts.length; k++) {
          var el = qBlock(texts[k].id);
          var w = el ? blockMm(el, rect, box).w : 12;
          var x = edge - w;
          if (x < 1 && k > 0) {
            y += 7;
            edge = box.w - 2;
            x = edge - w;
          }
          texts[k].x = Math.max(0, Math.round(x * 10) / 10);
          texts[k].y = Math.round(y * 10) / 10;
          edge = texts[k].x - 1.4;
        }
        fitMain();
      }
      function addKind(kind) {
        var box = boxMm(current);
        var b = norm({
          id: 'b' + Date.now(),
          kind: kind,
          x: Math.max(2, box.w - 30),
          y: 4,
          w: kind === 'logo' ? 18 : (kind === 'qr' ? 22 : 0),
          size: kind === 'amount' ? 18 : 13,
          color: '#111827',
          align: 'right',
          dir: (kind === 'phone' || kind === 'amount' || kind === 'start' || kind === 'end' || kind === 'package') ? 'ltr' : 'rtl',
          text: kind === 'custom' ? 'نص' : ''
        });
        var prev = null;
        for (var i = current.blocks.length - 1; i >= 0; i--) {
          if (current.blocks[i].kind !== 'logo') { prev = current.blocks[i]; break; }
        }
        if (prev) {
          b.y = prev.y;
          var prevEl = qBlock(prev.id);
          if (prevEl) {
            var rect = paper.getBoundingClientRect();
            var leftMm = (prevEl.getBoundingClientRect().left - rect.left) / rect.width * box.w;
            b.x = Math.round((leftMm - 2) * 10) / 10;
            if (b.x < 1) { b.x = Math.max(2, box.w - 30); b.y = prev.y + 7; }
          }
        }
        current.blocks.push(b);
        closePop();
        fitMain();
        var el = qBlock(b.id);
        var rect = paper.getBoundingClientRect();
        var boxNow = boxMm(current);
        if (el && rect.width) {
          var myW = el.getBoundingClientRect().width / rect.width * boxNow.w;
          if (prev) {
            var prevEl = qBlock(prev.id);
            if (prevEl) {
              var prevLeft = (prevEl.getBoundingClientRect().left - rect.left) / rect.width * boxNow.w;
              var x = prevLeft - myW - 1.2;
              if (x < 1) { b.x = Math.max(1, boxNow.w - myW - 2); b.y = prev.y + 7; }
              else { b.x = Math.round(x * 10) / 10; b.y = prev.y; }
            }
          } else {
            b.x = Math.max(1, boxNow.w - myW - 2);
          }
          fitMain();
        }
        pick(b.id);
      }
      function fillProp() {
        var b = picked ? blockById(picked) : null;
        if (!b || mode !== 'edit') { prop.hidden = true; return; }
        prop.hidden = false;
        var special = b.kind === 'logo' || b.kind === 'qr';
        var bits = prop.querySelectorAll('.inv-for-text');
        for (var i = 0; i < bits.length; i++) bits[i].hidden = special;
        var logoBits = prop.querySelectorAll('.inv-for-logo');
        for (var j = 0; j < logoBits.length; j++) logoBits[j].hidden = b.kind !== 'logo';
        var pText = document.getElementById('pText');
        var pSize = document.getElementById('pSize');
        var pSizeN = document.getElementById('pSizeN');
        if (document.activeElement !== pText) pText.value = b.text ? b.text : sample(b.kind, current);
        if (document.activeElement !== pSize && document.activeElement !== pSizeN) {
          pSize.value = b.size;
          pSizeN.value = b.size;
        }
        document.getElementById('pColor').value = /^#[0-9a-fA-F]{6}$/.test(b.color || '') ? b.color : '#111827';
        document.getElementById('pHug').checked = !(b.w > 0);
        if (document.activeElement !== document.getElementById('pX')) document.getElementById('pX').value = b.x;
        if (document.activeElement !== document.getElementById('pY')) document.getElementById('pY').value = b.y;
        setSeg(document.getElementById('pAlign'), b.align);
        setSeg(document.getElementById('pDir'), b.dir);
      }
      function setSeg(box, val) {
        var btns = box.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) btns[i].classList.toggle('is-on', btns[i].getAttribute('data-v') === val);
      }
      function pick(id) {
        if (id) closePop();
        var wasHidden = prop.hidden;
        picked = id || null;
        var nodes = paper.querySelectorAll('.inv-block');
        for (var i = 0; i < nodes.length; i++) nodes[i].classList.toggle('is-pick', nodes[i].getAttribute('data-id') === picked);
        fillProp();
        if (prop.hidden !== wasHidden) requestAnimationFrame(function () { requestAnimationFrame(function () { if (!drag) fitMain(); }); });
      }
      function removeBlock(b) {
        var next = [];
        for (var i = 0; i < current.blocks.length; i++) if (current.blocks[i].id !== b.id) next.push(current.blocks[i]);
        current.blocks = next;
        picked = null;
        prop.hidden = true;
        fitMain();
      }
      function bindProp() {
        var pText = document.getElementById('pText');
        var pSize = document.getElementById('pSize');
        var pSizeN = document.getElementById('pSizeN');
        var pColor = document.getElementById('pColor');
        var pHug = document.getElementById('pHug');
        var pX = document.getElementById('pX');
        var pY = document.getElementById('pY');
        pText.addEventListener('input', function () {
          var b = blockById(picked); if (!b) return;
          b.text = pText.value;
          syncBlock(b);
        });
        function setSize(n) {
          var b = blockById(picked); if (!b) return;
          n = Math.max(6, Math.min(72, parseFloat(n) || 13));
          b.size = n;
          pSize.value = n;
          pSizeN.value = n;
          syncBlock(b);
        }
        pSize.addEventListener('input', function () { setSize(pSize.value); });
        pSizeN.addEventListener('input', function () { setSize(pSizeN.value); });
        pColor.addEventListener('input', function () {
          var b = blockById(picked); if (!b) return;
          b.color = pColor.value;
          syncBlock(b);
        });
        pHug.addEventListener('change', function () {
          var b = blockById(picked); if (!b) return;
          if (pHug.checked) b.w = 0;
          else {
            var el = qBlock(b.id);
            var rect = paper.getBoundingClientRect();
            var box = boxMm(current);
            b.w = el ? Math.max(8, Math.round(el.getBoundingClientRect().width / rect.width * box.w * 10) / 10) : 20;
          }
          syncBlock(b);
        });
        pX.addEventListener('input', function () {
          var b = blockById(picked); if (!b) return;
          b.x = Math.max(0, parseFloat(pX.value) || 0);
          syncBlock(b);
        });
        pY.addEventListener('input', function () {
          var b = blockById(picked); if (!b) return;
          b.y = Math.max(0, parseFloat(pY.value) || 0);
          syncBlock(b);
        });
        document.getElementById('pAlign').addEventListener('click', function (e) {
          var btn = e.target.closest ? e.target.closest('button') : null;
          if (!btn) return;
          var b = blockById(picked); if (!b) return;
          b.align = btn.getAttribute('data-v');
          setSeg(document.getElementById('pAlign'), b.align);
          syncBlock(b);
        });
        document.getElementById('pDir').addEventListener('click', function (e) {
          var btn = e.target.closest ? e.target.closest('button') : null;
          if (!btn) return;
          var b = blockById(picked); if (!b) return;
          b.dir = btn.getAttribute('data-v');
          setSeg(document.getElementById('pDir'), b.dir);
          syncBlock(b);
        });
        document.getElementById('pDel').addEventListener('click', function () {
          var b = blockById(picked); if (b) removeBlock(b);
        });
        var pLogo = document.getElementById('pLogo');
        if (pLogo) pLogo.addEventListener('click', function () { document.getElementById('invLogo').click(); });
      }
      function closestBlock(node) {
        while (node && node !== paper) {
          if (node.classList && node.classList.contains('inv-block')) return node;
          node = node.parentNode;
        }
        return null;
      }
      paper.addEventListener('pointerdown', function (e) {
        if (mode !== 'edit') return;
        var block = closestBlock(e.target);
        if (!block) { pick(null); return; }
        var id = block.getAttribute('data-id');
        var b = blockById(id);
        if (!b) return;
        pick(id);
        var br = block.getBoundingClientRect();
        var rect = paper.getBoundingClientRect();
        var box = boxMm(current);
        drag = {
          id: id,
          mode: (e.target.classList && e.target.classList.contains('inv-resize')) ? 'size' : 'move',
          sx: e.clientX,
          dx: e.clientX - br.left,
          dy: e.clientY - br.top,
          startW: br.width / rect.width * box.w,
          selfH: br.height / rect.height * box.h,
          moved: false
        };
        if (block.setPointerCapture) { try { block.setPointerCapture(e.pointerId); } catch (err) {} }
        e.preventDefault();
      });
      paper.addEventListener('dblclick', function (e) {
        if (mode !== 'edit') return;
        var block = closestBlock(e.target);
        if (!block) return;
        var b = blockById(block.getAttribute('data-id'));
        if (b && b.kind === 'logo') document.getElementById('invLogo').click();
      });
      document.addEventListener('pointermove', function (e) {
        if (!drag) return;
        var b = blockById(drag.id);
        if (!b) return;
        drag.moved = true;
        var rect = paper.getBoundingClientRect();
        var box = boxMm(current);
        if (!rect.width || !rect.height) return;
        if (drag.mode === 'size') {
          var rtl = (document.documentElement.getAttribute('dir') || 'rtl') !== 'ltr';
          var delta = (rtl ? (drag.sx - e.clientX) : (e.clientX - drag.sx)) / rect.width * box.w;
          b.w = Math.max(6, Math.round((drag.startW + delta) * 10) / 10);
          var hug = document.getElementById('pHug');
          if (hug) hug.checked = false;
        } else {
          var x = (e.clientX - rect.left - drag.dx) / rect.width * box.w;
          var y = (e.clientY - rect.top - drag.dy) / rect.height * box.h;
          if (x < 0) x = 0;
          if (y < 0) y = 0;
          if (box.fixed) {
            if (x > box.w - 2) x = box.w - 2;
            if (y > box.h - 4) y = box.h - 4;
          }
          var snapped = snapToOthers(b, x, y, rect, box);
          x = snapped.x;
          y = snapped.y;
          b.x = Math.round(x * 10) / 10;
          b.y = Math.round(y * 10) / 10;
        }
        syncBlock(b);
        fillProp();
      });
      function snapToOthers(b, x, y, rect, box) {
        var selfW = drag.startW || 10;
        var selfH = drag.selfH || 4;
        var mine = [x, x + selfW, x + selfW / 2];
        var mineY = [y, y + selfH, y + selfH / 2];
        var nodes = paper.querySelectorAll('.inv-block');
        var bestX = 0.8, bestY = 0.8, sx = null, sy = null, gx = null, gy = null;
        for (var i = 0; i < nodes.length; i++) {
          if (nodes[i].getAttribute('data-id') === b.id) continue;
          var o = blockMm(nodes[i], rect, box);
          var xs = [o.l, o.r, (o.l + o.r) / 2];
          var ys = [o.t, o.b, (o.t + o.b) / 2];
          var a, k, d;
          for (a = 0; a < mine.length; a++) {
            for (k = 0; k < xs.length; k++) {
              d = Math.abs(mine[a] - xs[k]);
              if (d < bestX) { bestX = d; sx = x + (xs[k] - mine[a]); gx = xs[k]; }
            }
          }
          for (a = 0; a < mineY.length; a++) {
            for (k = 0; k < ys.length; k++) {
              d = Math.abs(mineY[a] - ys[k]);
              if (d < bestY) { bestY = d; sy = y + (ys[k] - mineY[a]); gy = ys[k]; }
            }
          }
        }
        showGuide(gx, gy);
        return { x: sx === null ? x : sx, y: sy === null ? y : sy };
      }
      document.addEventListener('pointerup', function () {
        if (!drag) return;
        var moved = drag.moved;
        drag = null;
        showGuide(null, null);
        if (moved) fitMain();
      });
      rail.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('button') : null;
        if (!btn) return;
        openToolPanel(btn.getAttribute('data-tool'));
      });
      stage.addEventListener('pointerdown', function (e) {
        if (e.target === stage) closePop();
      });
      document.getElementById('invBack').addEventListener('click', function () {
        if (mode === 'edit') cancelEdit();
        else showGallery();
      });
      document.getElementById('invEditBtn').addEventListener('click', function () { if (current) openEdit(current, false); });
      document.getElementById('invCancel').addEventListener('click', cancelEdit);
      document.getElementById('invNew').addEventListener('click', function () { openEdit(DATA.blank, true); });
      nameInput.addEventListener('input', function () { if (current && mode === 'edit') current.name = nameInput.value; });
      document.getElementById('invLogo').addEventListener('change', function () {
        var f = this.files && this.files[0];
        if (!f || !current) return;
        var reader = new FileReader();
        reader.onload = function () { current.logo = reader.result; fitMain(); };
        reader.readAsDataURL(f);
      });
      function pushFields(act) {
        document.getElementById('fAct').value = act;
        document.getElementById('fId').value = current ? current.id : '';
        document.getElementById('fPaper').value = current ? current.paper : '';
        document.getElementById('fOrient').value = current ? current.orient : '';
        document.getElementById('fMargin').value = current ? String(topMarginMm()) : '3';
        document.getElementById('fFrame').value = current ? current.frame : 'none';
        document.getElementById('fCompany').value = current ? (current.company_name || '') : '';
        document.getElementById('fPhone').value = current ? (current.phone || '') : '';
        document.getElementById('fAddress').value = current ? (current.address || '') : '';
        var blocks = [];
        var src = current && current.blocks ? current.blocks : [];
        for (var i = 0; i < src.length; i++) {
          var b = src[i];
          blocks.push({
            id: b.id, kind: b.kind, x: b.x, y: b.y, w: b.w > 0 ? b.w : 0,
            size: b.size, color: b.color, align: b.align, dir: b.dir, text: b.text || '', on: 1
          });
        }
        document.getElementById('fBlocks').value = JSON.stringify(blocks);
      }
      document.getElementById('invSave').addEventListener('click', function () {
        if (!current) return;
        if (nameInput.value) current.name = nameInput.value;
        pushFields(isNew ? 'add' : 'save');
        form.setAttribute('data-go', '1');
        form.submit();
      });
      function removeTpl(id) {
        if ((DATA.templates || []).length < 2) return;
        if (!window.confirm(UI.delTpl || 'حذف القالب؟')) return;
        current = findTpl(id);
        pushFields('delete');
        form.setAttribute('data-go', '1');
        form.submit();
      }
      document.getElementById('invDelTpl').addEventListener('click', function () { if (current) removeTpl(current.id); });
      form.addEventListener('submit', function (e) {
        if (form.getAttribute('data-go') !== '1') e.preventDefault();
      });
      form.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') e.preventDefault();
      });
      function pageMm() {
        var box = boxMm(current);
        return {w: box.w, h: box.h};
      }
      function receiptNode() {
        var clone = paper.cloneNode(true);
        clone.removeAttribute('id');
        clone.style.transform = 'none';
        clone.style.webkitTransform = 'none';
        clone.style.transformOrigin = '0 0';
        clone.style.position = 'relative';
        clone.style.margin = '0';
        clone.style.left = '0';
        clone.style.top = '0';
        clone.style.boxShadow = 'none';
        var handles = clone.querySelectorAll('.inv-resize');
        for (var i = 0; i < handles.length; i++) handles[i].parentNode.removeChild(handles[i]);
        var marks = clone.querySelectorAll('.is-pick');
        for (var j = 0; j < marks.length; j++) marks[j].classList.remove('is-pick');
        return clone;
      }
      function trimTail(canvas) {
        var ctx = canvas.getContext('2d');
        var w = canvas.width;
        var h = canvas.height;
        var stride = Math.max(1, Math.floor(w / 70));
        var last = 0;
        var y, x, row, ink;
        for (y = h - 1; y >= 0; y -= 2) {
          row = ctx.getImageData(0, y, w, 1).data;
          ink = false;
          for (x = 0; x < row.length; x += stride * 4) {
            if (row[x] < 246 || row[x + 1] < 246 || row[x + 2] < 246) { ink = true; break; }
          }
          if (ink) { last = Math.min(h, y + 14); break; }
        }
        if (!last || last > h - 8) return canvas;
        var out = document.createElement('canvas');
        out.width = w;
        out.height = last;
        out.getContext('2d').drawImage(canvas, 0, 0);
        return out;
      }
      function u8(str) {
        var a = new Uint8Array(str.length);
        for (var i = 0; i < str.length; i++) a[i] = str.charCodeAt(i) & 255;
        return a;
      }
      function joinBytes(parts) {
        var n = 0, i, o = 0, out;
        for (i = 0; i < parts.length; i++) n += parts[i].length;
        out = new Uint8Array(n);
        for (i = 0; i < parts.length; i++) { out.set(parts[i], o); o += parts[i].length; }
        return out;
      }
      function jpegPdf(jpeg, imgW, imgH, pageWmm, pageHmm) {
        var ptW = pageWmm * 72 / 25.4, ptH = pageHmm * 72 / 25.4;
        var content = 'q\n' + ptW.toFixed(2) + ' 0 0 ' + ptH.toFixed(2) + ' 0 0 cm\n/Im Do\nQ\n';
        var objs = [
          u8('1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n'),
          u8('2 0 obj\n<< /Type /Pages /Count 1 /Kids [3 0 R] >>\nendobj\n'),
          u8('3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + ptW.toFixed(2) + ' ' + ptH.toFixed(2) + '] /Contents 4 0 R /Resources << /XObject << /Im 5 0 R >> >> >>\nendobj\n'),
          u8('4 0 obj\n<< /Length ' + content.length + ' >>\nstream\n' + content + 'endstream\nendobj\n'),
          joinBytes([
            u8('5 0 obj\n<< /Type /XObject /Subtype /Image /Width ' + imgW + ' /Height ' + imgH + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpeg.length + ' >>\nstream\n'),
            jpeg,
            u8('\nendstream\nendobj\n')
          ])
        ];
        var header = u8('%PDF-1.3\n');
        var parts = [header], offsets = [0], pos = header.length, i;
        for (i = 0; i < objs.length; i++) { offsets.push(pos); parts.push(objs[i]); pos += objs[i].length; }
        var xref = 'xref\n0 6\n0000000000 65535 f \n';
        for (var j = 1; j <= 5; j++) {
          var off = String(offsets[j]);
          while (off.length < 10) off = '0' + off;
          xref += off + ' 00000 n \n';
        }
        xref += 'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + pos + '\n%%EOF';
        parts.push(u8(xref));
        return joinBytes(parts);
      }
      function dataUrlBytes(dataUrl) {
        var bin = atob(dataUrl.split(',')[1]);
        var u = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
        return u;
      }
      function topMarginMm() {
        var n = parseFloat(current && current.margin_top);
        if (!isFinite(n) || n < 0) n = 3;
        if (n > 12) n = 12;
        return n;
      }
      function mountPrintSheet() {
        var box = boxMm(current);
        var clone = receiptNode();
        var holder = document.createElement('div');
        holder.style.cssText = 'position:fixed;left:0;top:0;z-index:-1;background:#fff;pointer-events:none;';
        clone.style.position = 'relative';
        clone.style.left = '0';
        clone.style.top = '0';
        clone.style.transform = 'none';
        clone.style.background = '#fff';
        clone.style.margin = '0';
        holder.appendChild(clone);
        document.body.appendChild(holder);
        var crect = clone.getBoundingClientRect();
        var pxPerMm = crect.width / (box.w || 1);
        var nodes = clone.querySelectorAll('.inv-block');
        var minTop = Infinity;
        var maxBottom = 0;
        var rows = [];
        var i, r, t, btm;
        for (i = 0; i < nodes.length; i++) {
          r = nodes[i].getBoundingClientRect();
          t = (r.top - crect.top) / (pxPerMm || 1);
          btm = (r.bottom - crect.top) / (pxPerMm || 1);
          rows.push({ el: nodes[i], t: t });
          if (t < minTop) minTop = t;
          if (btm > maxBottom) maxBottom = btm;
        }
        if (!isFinite(minTop)) minTop = 0;
        var margin = topMarginMm();
        var shift = minTop - margin;
        for (i = 0; i < rows.length; i++) rows[i].el.style.top = (rows[i].t - shift) + 'mm';
        var span = Math.max(8, maxBottom - minTop);
        var h = Math.ceil(span + margin + 6);
        clone.style.height = h + 'mm';
        var foot = clone.querySelector('.inv-foot');
        if (foot) {
          foot.style.bottom = 'auto';
          foot.style.top = (span + margin + 1) + 'mm';
          foot.style.background = '#fff';
        }
        box = { w: box.w, h: h, fixed: false };
        return { holder: holder, node: clone, box: box };
      }
      function cropVertical(canvas, topMm, botMm, pageWmm) {
        var ctx = canvas.getContext('2d');
        var w = canvas.width;
        var h = canvas.height;
        var stride = Math.max(1, Math.floor(w / 90));
        function inkAt(y) {
          if (y < 0 || y >= h) return false;
          var row = ctx.getImageData(0, y, w, 1).data;
          var x;
          for (x = 0; x < row.length; x += stride * 4) {
            if (row[x] < 247 || row[x + 1] < 247 || row[x + 2] < 247) return true;
          }
          return false;
        }
        var top = 0;
        var bot = h - 1;
        var y;
        for (y = 0; y < h; y++) { if (inkAt(y)) { top = y; break; } }
        for (y = h - 1; y >= top; y--) { if (inkAt(y)) { bot = y; break; } }
        var pxPerMm = w / (pageWmm || 80);
        var y0 = Math.max(0, top - Math.round((topMm || 0) * pxPerMm));
        var y1 = Math.min(h - 1, bot + Math.round((botMm || 1) * pxPerMm));
        var ch = Math.max(8, y1 - y0 + 1);
        if (y0 === 0 && ch >= h - 2) return canvas;
        var out = document.createElement('canvas');
        out.width = w;
        out.height = ch;
        var octx = out.getContext('2d');
        octx.fillStyle = '#ffffff';
        octx.fillRect(0, 0, w, ch);
        octx.drawImage(canvas, 0, y0, w, ch, 0, 0, w, ch);
        return out;
      }
      function clearPrintSheet(mounted) {
        if (mounted && mounted.holder && mounted.holder.parentNode) mounted.holder.parentNode.removeChild(mounted.holder);
      }
      function renderOutput(done) {
        if (!window.html2canvas) { done(new Error('canvas')); return; }
        var mounted = mountPrintSheet();
        window.html2canvas(mounted.node, {
          scale: 3,
          backgroundColor: '#ffffff',
          useCORS: true,
          width: mounted.node.offsetWidth,
          height: mounted.node.offsetHeight,
          windowWidth: mounted.node.offsetWidth,
          windowHeight: mounted.node.offsetHeight
        }).then(function (canvas) {
          var box = mounted.box;
          clearPrintSheet(mounted);
          try { canvas = cropVertical(canvas, topMarginMm(), 1.2, box.w); } catch (err) {}
          box = { w: box.w, h: box.w * canvas.height / canvas.width, fixed: false };
          done(null, canvas, box);
        }).catch(function (err) {
          clearPrintSheet(mounted);
          done(err || new Error('canvas'));
        });
      }
      function openPrintImage(canvas, box) {
        var url = canvas.toDataURL('image/jpeg', 0.92);
        var w = window.open('', 'invtest', 'width=420,height=640');
        if (!w) return;
        var css = '@page{size:' + box.w + 'mm ' + box.h + 'mm;margin:0} html,body{margin:0;padding:0;background:#fff;width:' + box.w + 'mm;height:' + box.h + 'mm} img{display:block;width:' + box.w + 'mm;height:' + box.h + 'mm;margin:0;border:0;background:#fff}';
        w.document.open();
        w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title> </title><style>' + css + '</style></head><body><img src="' + url + '" alt=""></body></html>');
        w.document.close();
        w.focus();
        setTimeout(function () { try { w.print(); } catch (e) {} }, 350);
      }
      document.getElementById('invTestPrint').addEventListener('click', function () {
        if (!paper || !current) return;
        var btn = document.getElementById('invTestPrint');
        var prevText = btn.textContent;
        btn.disabled = true;
        renderOutput(function (err, canvas, box) {
          btn.disabled = false;
          btn.textContent = prevText;
          if (err || !canvas) return;
          openPrintImage(canvas, box);
        });
      });
      document.getElementById('invPdf').addEventListener('click', function () {
        var btn = document.getElementById('invPdf');
        var prevText = btn.textContent;
        if (!window.html2canvas || !paper) {
          btn.textContent = UI.fail || 'تعذر التصدير';
          setTimeout(function () { btn.textContent = prevText; }, 1400);
          return;
        }
        btn.disabled = true;
        btn.textContent = UI.exporting || 'جارٍ التصدير…';
        renderOutput(function (err, canvas, box) {
          btn.disabled = false;
          if (err || !canvas) {
            btn.textContent = UI.fail || 'تعذر التصدير';
            setTimeout(function () { btn.textContent = prevText; }, 1400);
            return;
          }
          btn.textContent = prevText;
          var pageH = box.w * canvas.height / canvas.width;
          var bytes = jpegPdf(dataUrlBytes(canvas.toDataURL('image/jpeg', 0.92)), canvas.width, canvas.height, box.w, pageH);
          var blob = new Blob([bytes], { type: 'application/pdf' });
          var a = document.createElement('a');
          var name = (current && current.name) ? current.name : 'وصل';
          a.href = URL.createObjectURL(blob);
          a.download = name.replace(/[\\/:*?"<>|]+/g, ' ').trim() + '.pdf';
          document.body.appendChild(a);
          a.click();
          a.parentNode.removeChild(a);
          if (window.appBusyDone) window.appBusyDone();
        });
      });
      function openPrinters() { document.getElementById('invPrintModal').hidden = false; }
      document.getElementById('invPrintOpen').addEventListener('click', openPrinters);
      document.getElementById('invPrintOpen2').addEventListener('click', openPrinters);
      document.getElementById('invPrintClose').addEventListener('click', function () { document.getElementById('invPrintModal').hidden = true; });
      document.getElementById('invPrintModal').addEventListener('click', function (e) {
        if (e.target.id === 'invPrintModal') e.currentTarget.hidden = true;
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          document.getElementById('invPrintModal').hidden = true;
          closePop();
          return;
        }
        if (mode !== 'edit' || !picked) return;
        var tag = e.target && e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        var b = blockById(picked);
        if (!b) return;
        var step = e.shiftKey ? 2 : 0.5;
        if (e.key === 'ArrowLeft') { b.x = Math.max(0, b.x - step); e.preventDefault(); }
        else if (e.key === 'ArrowRight') { b.x += step; e.preventDefault(); }
        else if (e.key === 'ArrowUp') { b.y = Math.max(0, b.y - step); e.preventDefault(); }
        else if (e.key === 'ArrowDown') { b.y += step; e.preventDefault(); }
        else if (e.key === 'Delete' || e.key === 'Backspace') { removeBlock(b); return; }
        else return;
        syncBlock(b);
        fillProp();
      });
      window.addEventListener('resize', function () {
        if (mode === 'gallery') fitThumbs();
        else fitMain();
      });
      if (window.ResizeObserver && stage) {
        var stageSize = '';
        var stageWatch = new ResizeObserver(function () {
          var key = stage.clientWidth + 'x' + stage.clientHeight;
          if (key === stageSize || drag) return;
          stageSize = key;
          if (mode !== 'gallery') fitMain();
        });
        stageWatch.observe(stage);
      }
      bindProp();
      showGallery();
    })();
    </script>
    <?php
}
