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
        'sheet_h' => 0,
        'sheet_w' => 0,
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

function invoice_frame_styles()
{
    return array('none', 'line', 'double', 'dashed', 'round');
}

function invoice_frame_normalize($j)
{
    $styles = invoice_frame_styles();
    $style = 'none';
    if (is_array($j) && !empty($j['frame']) && in_array($j['frame'], $styles, true)) {
        $style = $j['frame'];
    }
    $box = array(
        'style' => $style,
        'color' => '#0f172a',
        'thick' => 0.45,
        'radius' => 0,
        'x' => 0,
        'y' => 0,
        'w' => 0,
        'h' => 0,
    );
    $f = (is_array($j) && isset($j['frame_box']) && is_array($j['frame_box'])) ? $j['frame_box'] : array();
    if (!empty($f['style']) && in_array($f['style'], $styles, true)) {
        $box['style'] = $f['style'];
    }
    if (!empty($f['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $f['color'])) {
        $box['color'] = strtolower((string) $f['color']);
    }
    if ($box['style'] === 'round' && empty($f['radius'])) {
        $box['radius'] = 3;
    }
    if (isset($f['thick'])) {
        $thick = (float) $f['thick'];
        if ($thick < 0.15) {
            $thick = 0.15;
        }
        if ($thick > 3) {
            $thick = 3;
        }
        $box['thick'] = $thick;
    }
    if (isset($f['radius'])) {
        $radius = (float) $f['radius'];
        if ($radius < 0) {
            $radius = 0;
        }
        if ($radius > 20) {
            $radius = 20;
        }
        $box['radius'] = $radius;
    }
    foreach (array('x', 'y', 'w', 'h') as $k) {
        if (!isset($f[$k])) {
            continue;
        }
        $n = (float) $f[$k];
        if ($n < 0) {
            $n = 0;
        }
        if ($n > 450) {
            $n = 450;
        }
        $box[$k] = $n;
    }
    return $box;
}

function invoice_slip_field_value($slip, $kind)
{
    $when = isset($slip['created_at']) ? (string) $slip['created_at'] : '';
    $date = strlen($when) >= 10 ? substr($when, 0, 10) : '';
    $time = strlen($when) >= 16 ? substr($when, 11, 5) : '';
    $map = array(
        'name' => isset($slip['name']) ? $slip['name'] : '',
        'package' => isset($slip['package']) ? $slip['package'] : '',
        'start' => isset($slip['start']) ? $slip['start'] : '',
        'end' => isset($slip['end']) ? $slip['end'] : '',
        'amount' => isset($slip['amount']) ? $slip['amount'] : '',
        'company' => isset($slip['company_name']) ? $slip['company_name'] : '',
        'phone' => isset($slip['company_phone']) ? $slip['company_phone'] : '',
        'address' => isset($slip['address']) ? $slip['address'] : '',
        'username' => isset($slip['username']) ? $slip['username'] : '',
        'mobile' => isset($slip['phone']) ? $slip['phone'] : '',
        'number' => isset($slip['number']) ? $slip['number'] : '',
        'date' => $date,
        'time' => $time,
    );
    return isset($map[$kind]) ? (string) $map[$kind] : '';
}

function invoice_normalize_design($j)
{
    $base = invoice_default_design();
    $base['id'] = (is_array($j) && !empty($j['id'])) ? (string) $j['id'] : 'main';
    $base['name'] = (is_array($j) && !empty($j['name'])) ? (string) $j['name'] : 'القالب الأساسي';
    $frameBox = invoice_frame_normalize(is_array($j) ? $j : array());
    $base['frame'] = $frameBox['style'];
    $base['frame_box'] = $frameBox;
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
    $sh = 0;
    if (is_array($j) && isset($j['sheet_h'])) {
        $sh = (float) $j['sheet_h'];
    }
    if ($sh < 0) {
        $sh = 0;
    }
    if ($sh > 450) {
        $sh = 450;
    }
    $base['sheet_h'] = $sh;
    $sw = 0;
    if (is_array($j) && isset($j['sheet_w'])) {
        $sw = (float) $j['sheet_w'];
    }
    if ($sw < 0) {
        $sw = 0;
    }
    if ($sw > 320) {
        $sw = 320;
    }
    $base['sheet_w'] = $sw;
    $fx = -1;
    $fy = -1;
    $fa = 'center';
    if (is_array($j) && isset($j['footer_x'])) {
        $fx = (float) $j['footer_x'];
    }
    if (is_array($j) && isset($j['footer_y'])) {
        $fy = (float) $j['footer_y'];
    }
    if (is_array($j) && isset($j['footer_align']) && in_array($j['footer_align'], array('left', 'center', 'right'), true)) {
        $fa = $j['footer_align'];
    }
    if ($fx < -1) {
        $fx = -1;
    }
    if ($fx > 320) {
        $fx = 320;
    }
    if ($fy < -1) {
        $fy = -1;
    }
    if ($fy > 450) {
        $fy = 450;
    }
    $base['footer_x'] = $fx;
    $base['footer_y'] = $fy;
    $base['footer_align'] = $fa;
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

function invoice_printer_default($tenantId)
{
    $rows = invoice_printers_load($tenantId);
    $fallback = null;
    foreach ($rows as $r) {
        if (!is_array($r) || empty($r['ip'])) {
            continue;
        }
        if ($fallback === null) {
            $fallback = $r;
        }
        if (!empty($r['is_default'])) {
            return $r;
        }
    }
    return $fallback;
}

function invoice_slip_escpos_line($text)
{
    $text = trim(preg_replace("/[\\r\\n]+/", ' ', (string) $text));
    if ($text === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $enc = @iconv('UTF-8', 'Windows-1256//TRANSLIT//IGNORE', $text);
        if ($enc !== false && $enc !== '') {
            return $enc;
        }
    }
    return $text;
}

function invoice_slip_escpos_bytes($slip)
{
    if (!is_array($slip)) {
        return '';
    }
    $ESC = "\x1B";
    $GS = "\x1D";
    $buf = $ESC . '@';
    $buf .= $ESC . 't' . chr(22);
    $buf .= $ESC . 'a' . chr(1);
    $company = isset($slip['company_name']) ? trim((string) $slip['company_name']) : '';
    if ($company === '') {
        $company = function_exists('invoice_system_footer') ? invoice_system_footer() : 'الوصل';
    }
    $buf .= invoice_slip_escpos_line($company) . "\n";
    $number = isset($slip['number']) ? trim((string) $slip['number']) : '';
    if ($number !== '') {
        $buf .= invoice_slip_escpos_line($number) . "\n";
    }
    $when = isset($slip['created_at']) ? trim((string) $slip['created_at']) : '';
    if ($when !== '') {
        $buf .= invoice_slip_escpos_line($when) . "\n";
    }
    $buf .= "--------------------------------\n";
    $buf .= $ESC . 'a' . chr(0);
    $pairs = array(
        array('المشترك', isset($slip['name']) ? $slip['name'] : ''),
        array('اليوزر', isset($slip['username']) ? $slip['username'] : ''),
        array('الهاتف', isset($slip['phone']) ? $slip['phone'] : ''),
        array('الباقة', isset($slip['package']) ? $slip['package'] : ''),
        array('من', isset($slip['start']) ? $slip['start'] : ''),
        array('إلى', isset($slip['end']) ? $slip['end'] : ''),
        array('المبلغ', isset($slip['amount']) ? $slip['amount'] : ''),
    );
    foreach ($pairs as $pair) {
        $val = trim((string) $pair[1]);
        if ($val === '') {
            continue;
        }
        $buf .= invoice_slip_escpos_line($pair[0] . ': ' . $val) . "\n";
    }
    $buf .= "--------------------------------\n";
    $buf .= $ESC . 'a' . chr(1);
    $buf .= invoice_slip_escpos_line(function_exists('invoice_system_footer') ? invoice_system_footer() : '') . "\n";
    $buf .= "\n\n\n";
    $buf .= $GS . 'V' . chr(0);
    return $buf;
}

/**
 * طباعة مرة واحدة للطابعة الافتراضية (TCP 9100) — مثل الكاشير.
 * @return array{ok:bool,via:string,message:string}
 */
function invoice_slip_print_default($tenantId, $slip)
{
    $printer = invoice_printer_default($tenantId);
    if (!$printer || empty($printer['ip'])) {
        return array('ok' => false, 'via' => '', 'message' => 'ماكو طابعة افتراضية');
    }
    $ip = trim((string) $printer['ip']);
    $port = isset($printer['port']) ? (int) $printer['port'] : 9100;
    if ($port <= 0) {
        $port = 9100;
    }
    $payload = invoice_slip_escpos_bytes($slip);
    if ($payload === '') {
        return array('ok' => false, 'via' => '', 'message' => 'محتوى الفاتورة فاضي');
    }
    $errno = 0;
    $err = '';
    $fp = @fsockopen($ip, $port, $errno, $err, 0.9);
    if (!$fp) {
        return array(
            'ok' => false,
            'via' => '',
            'message' => 'الطابعة الافتراضية ما ردت (' . $ip . ':' . $port . ')',
        );
    }
    stream_set_timeout($fp, 2);
    $wrote = @fwrite($fp, $payload);
    @fclose($fp);
    if ($wrote === false || (int) $wrote < 1) {
        return array('ok' => false, 'via' => '', 'message' => 'فشل إرسال الطباعة للطابعة');
    }
    $label = isset($printer['name']) && trim((string) $printer['name']) !== ''
        ? trim((string) $printer['name'])
        : ($ip . ':' . $port);
    return array('ok' => true, 'via' => 'network', 'message' => 'انطبعت على ' . $label);
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
    if ($section === 'invoice_seq') {
        $mode = function_exists('post') ? (string) post('seq_mode', 'continuous') : 'continuous';
        invoice_slip_seq_set_mode($tenantId, $mode === 'month' ? 'month' : 'continuous');
        return 'seq';
    }
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
        $postedFrame = json_decode((string) post('frame_json'), true);
        if (!is_array($postedFrame)) {
            $postedFrame = array();
        }
        if (empty($postedFrame['style'])) {
            $postedFrame['style'] = (string) post('frame', 'none');
        }
        $design['frame_box'] = $postedFrame;
        $frameBox = invoice_frame_normalize($design);
        $design['frame_box'] = $frameBox;
        $design['frame'] = $frameBox['style'];
        $design['orient'] = ((string) post('orient', 'portrait') === 'landscape') ? 'landscape' : 'portrait';
        $mt = (float) post('margin_top', '3');
        if ($mt < 0) {
            $mt = 0;
        }
        if ($mt > 12) {
            $mt = 12;
        }
        $design['margin_top'] = $mt;
        $sh = (float) post('sheet_h', '0');
        if ($sh < 0) {
            $sh = 0;
        }
        if ($sh > 450) {
            $sh = 450;
        }
        $design['sheet_h'] = $sh;
        $sw = (float) post('sheet_w', '0');
        if ($sw < 0) {
            $sw = 0;
        }
        if ($sw > 320) {
            $sw = 320;
        }
        $design['sheet_w'] = $sw;
        $design['footer_x'] = (float) post('footer_x', '-1');
        $design['footer_y'] = (float) post('footer_y', '-1');
        $design['footer_align'] = (string) post('footer_align', 'center');
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

function invoice_request_base()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string) $_SERVER['HTTP_HOST']) : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';
    $dir = rtrim(dirname($script), '/');
    if ($dir === '.' || $dir === '/') {
        $dir = '';
    }
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function invoice_slip_root()
{
    $dir = invoice_studio_dir() . '/slips';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function invoice_slip_token_clean($token)
{
    $token = strtolower(trim((string) $token));
    return preg_replace('/[^a-f0-9]/', '', $token);
}

function invoice_slip_path($token)
{
    $token = invoice_slip_token_clean($token);
    if (strlen($token) < 8) {
        return '';
    }
    return invoice_slip_root() . '/' . $token . '.json';
}

function invoice_slip_index_path($tenantId)
{
    return invoice_studio_dir() . '/slip_index_t' . (int) $tenantId . '.json';
}

function invoice_slip_index_load($tenantId)
{
    $path = invoice_slip_index_path($tenantId);
    if (!is_file($path)) {
        return array();
    }
    $j = json_decode((string) @file_get_contents($path), true);
    return is_array($j) ? $j : array();
}

function invoice_slip_index_save($tenantId, $rows)
{
    $json = json_encode(array_values($rows));
    return $json !== false && @file_put_contents(invoice_slip_index_path($tenantId), $json, LOCK_EX) !== false;
}

function invoice_slip_load($token)
{
    $path = invoice_slip_path($token);
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $j = json_decode((string) @file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

function invoice_slips_for_user($tenantId, $username, $phone = '')
{
    $username = strtolower(trim((string) $username));
    $phoneNorm = '';
    if (function_exists('normalize_phone')) {
        $phoneNorm = normalize_phone($phone);
    } else {
        $phoneNorm = preg_replace('/\D+/', '', (string) $phone);
    }
    $out = array();
    $seen = array();
    foreach (invoice_slip_index_load($tenantId) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tok = isset($row['token']) ? invoice_slip_token_clean($row['token']) : '';
        if ($tok === '' || isset($seen[$tok])) {
            continue;
        }
        $hit = false;
        if ($username !== ''
            && strtolower(trim((string) (isset($row['username']) ? $row['username'] : ''))) === $username) {
            $hit = true;
        }
        if (!$hit && $phoneNorm !== '') {
            $rowPhone = isset($row['phone']) ? (string) $row['phone'] : '';
            $rowNorm = function_exists('normalize_phone')
                ? normalize_phone($rowPhone)
                : preg_replace('/\D+/', '', $rowPhone);
            if ($rowNorm !== '' && $rowNorm === $phoneNorm) {
                $hit = true;
            }
        }
        if ($hit) {
            $seen[$tok] = 1;
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * هاتف المشترك للفاتورة: من المُدخل، وإلا من كاش الساس / المشترك المحلي
 */
function invoice_slip_resolve_phone($tenantId, $username, $phone = '')
{
    global $pdo;
    $phone = trim((string) $phone);
    if ($phone !== '' && $phone !== '0'
        && (!function_exists('phone_is_placeholder') || !phone_is_placeholder($phone))
    ) {
        return $phone;
    }
    $username = trim((string) $username);
    if ($username === '' || !isset($pdo) || !$pdo) {
        return '';
    }
    $cache = function_exists('sas_cache_get') ? sas_cache_get($pdo, $username) : null;
    if (is_array($cache)) {
        foreach (array('phone', 'local_phone') as $pk) {
            if (empty($cache[$pk])) {
                continue;
            }
            $p = trim((string) $cache[$pk]);
            if ($p === '' || $p === '0') {
                continue;
            }
            if (function_exists('phone_is_placeholder') && phone_is_placeholder($p)) {
                continue;
            }
            return $p;
        }
        $localId = !empty($cache['local_subscriber_id']) ? (int) $cache['local_subscriber_id'] : 0;
        if ($localId > 0) {
            try {
                $st = $pdo->prepare('SELECT phone FROM subscribers WHERE id = :id LIMIT 1');
                $st->execute(array(':id' => $localId));
                $p = trim((string) $st->fetchColumn());
                if ($p !== '' && $p !== '0'
                    && (!function_exists('phone_is_placeholder') || !phone_is_placeholder($p))
                ) {
                    return $p;
                }
            } catch (Exception $e) {
            }
        }
    }
    try {
        $tid = (int) $tenantId;
        $st2 = $pdo->prepare(
            'SELECT phone FROM subscribers WHERE tenant_id = :t AND username = :u LIMIT 1'
        );
        $st2->execute(array(':t' => $tid, ':u' => $username));
        $p2 = trim((string) $st2->fetchColumn());
        if ($p2 !== '' && $p2 !== '0'
            && (!function_exists('phone_is_placeholder') || !phone_is_placeholder($p2))
        ) {
            return $p2;
        }
    } catch (Exception $e2) {
    }
    return '';
}

function invoice_slip_issue($tenantId, $meta, $design)
{
    $tenantId = (int) $tenantId;
    if (!is_array($design)) {
        $design = invoice_design_load($tenantId);
    }
    if (function_exists('random_bytes')) {
        $token = bin2hex(random_bytes(8));
    } else {
        $token = substr(md5(uniqid('slip', true)), 0, 16);
    }
    $url = invoice_request_base() . 'slip.php?k=' . $token;
    $sizes = invoice_paper_sizes();
    $paper = isset($design['paper']) && isset($sizes[$design['paper']]) ? $design['paper'] : 'strip80';
    $w = (int) $sizes[$paper]['w'];
    if (!empty($design['orient']) && $design['orient'] === 'landscape' && (int) $sizes[$paper]['h'] > 0) {
        $w = (int) $sizes[$paper]['h'];
    }
    if (!empty($design['sheet_w']) && (float) $design['sheet_w'] >= 30) {
        $w = (float) $design['sheet_w'];
    }
    $h = isset($design['sheet_h']) ? (float) $design['sheet_h'] : 0;
    if ($h < 40) {
        $h = 0;
    }
    $blocks = array();
    if (!empty($design['blocks']) && is_array($design['blocks'])) {
        foreach ($design['blocks'] as $b) {
            if (is_array($b)) {
                $blocks[] = $b;
            }
        }
    }
    $username = trim((string) (isset($meta['username']) ? $meta['username'] : ''));
    $name = trim((string) (isset($meta['name']) ? $meta['name'] : ''));
    if ($name === '') {
        $name = $username;
    }
    $phoneIn = trim((string) (isset($meta['phone']) ? $meta['phone'] : ''));
    $phoneResolved = invoice_slip_resolve_phone($tenantId, $username, $phoneIn);
    $slip = array(
        'token' => $token,
        'url' => $url,
        'tenant_id' => $tenantId,
        'username' => $username,
        'name' => $name,
        'phone' => $phoneResolved,
        'package' => trim((string) (isset($meta['package']) ? $meta['package'] : '')),
        'amount' => trim((string) (isset($meta['amount']) ? $meta['amount'] : '')),
        'start' => trim((string) (isset($meta['start']) ? $meta['start'] : date('Y-m-d'))),
        'end' => trim((string) (isset($meta['end']) ? $meta['end'] : '')),
        'title' => trim((string) (isset($meta['title']) ? $meta['title'] : 'وصل')),
        'number' => invoice_slip_next_number($tenantId),
        'created_at' => date('Y-m-d H:i:s'),
        'issuer_id' => 0,
        'issuer_name' => '',
        'paper_w' => $w,
        'paper_h' => $h,
        'frame' => isset($design['frame']) ? (string) $design['frame'] : 'line',
        'frame_box' => invoice_frame_normalize($design),
        'blocks' => $blocks,
        'margin_top' => isset($design['margin_top']) ? (float) $design['margin_top'] : 3,
        'footer_x' => isset($design['footer_x']) ? (float) $design['footer_x'] : -1,
        'footer_y' => isset($design['footer_y']) ? (float) $design['footer_y'] : -1,
        'footer_align' => (isset($design['footer_align']) && in_array($design['footer_align'], array('left', 'center', 'right'), true)) ? $design['footer_align'] : 'center',
        'logo' => isset($design['logo']) ? (string) $design['logo'] : '',
        'company_name' => isset($design['company_name']) ? (string) $design['company_name'] : '',
        'company_phone' => isset($design['phone']) ? (string) $design['phone'] : '',
        'address' => isset($design['address']) ? (string) $design['address'] : '',
        'footer' => invoice_system_footer(),
    );
    $issuerId = 0;
    $issuerName = '';
    if (function_exists('current_admin')) {
        $meSlip = current_admin();
        if (is_array($meSlip)) {
            $issuerId = isset($meSlip['id']) ? (int) $meSlip['id'] : 0;
            $issuerName = isset($meSlip['display_name']) ? trim((string) $meSlip['display_name']) : '';
            if ($issuerName === '' && isset($meSlip['username'])) {
                $issuerName = trim((string) $meSlip['username']);
            }
        }
    }
    $slip['issuer_id'] = $issuerId;
    $slip['issuer_name'] = $issuerName;
    $path = invoice_slip_path($token);
    $json = json_encode($slip);
    if ($path === '' || $json === false || @file_put_contents($path, $json, LOCK_EX) === false) {
        return null;
    }
    $index = invoice_slip_index_load($tenantId);
    array_unshift($index, array(
        'token' => $token,
        'username' => $username,
        'name' => $name,
        'title' => $slip['title'],
        'number' => $slip['number'],
        'amount' => $slip['amount'],
        'phone' => $slip['phone'],
        'created_at' => $slip['created_at'],
        'issuer_id' => $issuerId,
        'issuer_name' => $issuerName,
    ));
    if (count($index) > 500) {
        $index = array_slice($index, 0, 500);
    }
    invoice_slip_index_save($tenantId, $index);
    return $slip;
}

function invoice_slips_visible($tenantId, $limit = 0, $offset = 0, &$totalOut = null)
{
    $agent = function_exists('is_agent_user') && is_agent_user();
    $myId = 0;
    if ($agent && function_exists('current_admin')) {
        $me = current_admin();
        $myId = (is_array($me) && isset($me['id'])) ? (int) $me['id'] : 0;
    }
    $all = array();
    foreach (invoice_slip_index_load($tenantId) as $row) {
        if (!is_array($row) || empty($row['token'])) {
            continue;
        }
        $issuer = isset($row['issuer_id']) ? (int) $row['issuer_id'] : 0;
        if ($agent && $issuer > 0 && $issuer !== $myId) {
            continue;
        }
        $all[] = $row;
    }
    if (func_num_args() >= 4) {
        $totalOut = count($all);
    }
    $limit = (int) $limit;
    $offset = max(0, (int) $offset);
    if ($limit > 0) {
        return array_slice($all, $offset, $limit);
    }
    return $all;
}

function invoice_slip_agent_may($tenantId, $token)
{
    $slip = invoice_slip_load($token);
    if (!is_array($slip)) {
        return null;
    }
    if ((int) (isset($slip['tenant_id']) ? $slip['tenant_id'] : 0) !== (int) $tenantId) {
        return null;
    }
    if (function_exists('is_agent_user') && is_agent_user() && function_exists('current_admin')) {
        $me = current_admin();
        $myId = (is_array($me) && isset($me['id'])) ? (int) $me['id'] : 0;
        $issuer = isset($slip['issuer_id']) ? (int) $slip['issuer_id'] : 0;
        if ($issuer > 0 && $issuer !== $myId) {
            return null;
        }
    }
    return $slip;
}

function invoice_slip_send_whatsapp($config, $slip)
{
    if (!is_array($slip) || !function_exists('whatsapp_send')) {
        return array(false, 'إرسال الواتساب غير جاهز');
    }
    $phone = isset($slip['phone']) ? trim((string) $slip['phone']) : '';
    if ($phone === '') {
        return array(false, 'ماكو رقم هاتف لهذا المشترك');
    }
    $token = isset($slip['token']) ? invoice_slip_token_clean($slip['token']) : '';
    $url = $token !== '' ? (invoice_request_base() . 'slip.php?k=' . $token) : '';
    $name = isset($slip['name']) ? trim((string) $slip['name']) : '';
    $number = isset($slip['number']) ? trim((string) $slip['number']) : '';
    $amount = isset($slip['amount']) ? trim((string) $slip['amount']) : '';
    $msg = "فاتورة التفعيل\n" . $name;
    if ($number !== '') {
        $msg .= "\n" . $number;
    }
    if ($amount !== '') {
        $msg .= "\nالمبلغ: " . $amount;
    }
    if ($url !== '') {
        $msg .= "\n" . $url;
    }
    $result = whatsapp_send($config, $phone, $msg, 'invoice');
    $ok = is_array($result) && !empty($result['success']);
    if ($ok) {
        return array(true, 'انرسلت الفاتورة للمشترك');
    }
    $why = (is_array($result) && isset($result['response'])) ? trim((string) $result['response']) : '';
    if ($why === '' && function_exists('whatsapp_fail_user_message')) {
        $why = whatsapp_fail_user_message($result);
    }
    if ($why === '') {
        $why = 'ما انرسلت الفاتورة';
    }
    return array(false, $why);
}

function invoice_slip_seq_path($tenantId)
{
    return invoice_studio_dir() . '/slip_seq_t' . (int) $tenantId . '.json';
}

function invoice_slip_seq_read($tenantId)
{
    $state = array(
        'mode' => 'continuous',
        'n' => 0,
        'letter' => '',
        'month' => date('Y-m'),
        'month_n' => 0,
    );
    $path = invoice_slip_seq_path($tenantId);
    if (is_file($path)) {
        $j = json_decode((string) @file_get_contents($path), true);
        if (is_array($j)) {
            if (isset($j['mode']) && $j['mode'] === 'month') {
                $state['mode'] = 'month';
            }
            $state['n'] = isset($j['n']) ? (int) $j['n'] : 0;
            $state['letter'] = invoice_slip_seq_letter(isset($j['letter']) ? $j['letter'] : '');
            $state['month'] = isset($j['month']) ? (string) $j['month'] : date('Y-m');
            $state['month_n'] = isset($j['month_n']) ? (int) $j['month_n'] : 0;
            return $state;
        }
    }
    $legacy = invoice_studio_dir() . '/slip_seq_t' . (int) $tenantId . '.txt';
    if (is_file($legacy)) {
        $state['n'] = (int) trim((string) @file_get_contents($legacy));
    }
    return $state;
}

function invoice_slip_seq_write($tenantId, $state)
{
    $path = invoice_slip_seq_path($tenantId);
    $json = json_encode(array(
        'mode' => (isset($state['mode']) && $state['mode'] === 'month') ? 'month' : 'continuous',
        'n' => isset($state['n']) ? (int) $state['n'] : 0,
        'letter' => invoice_slip_seq_letter(isset($state['letter']) ? $state['letter'] : ''),
        'month' => isset($state['month']) ? (string) $state['month'] : date('Y-m'),
        'month_n' => isset($state['month_n']) ? (int) $state['month_n'] : 0,
    ));
    return $json !== false && @file_put_contents($path, $json, LOCK_EX) !== false;
}

function invoice_slip_seq_letter($letter)
{
    $letter = strtoupper(trim((string) $letter));
    if ($letter !== '' && preg_match('/^[A-Z]$/', $letter)) {
        return $letter;
    }
    return '';
}

function invoice_slip_seq_mode($tenantId)
{
    $state = invoice_slip_seq_read($tenantId);
    return $state['mode'] === 'month' ? 'month' : 'continuous';
}

function invoice_slip_seq_set_mode($tenantId, $mode)
{
    $state = invoice_slip_seq_read($tenantId);
    $state['mode'] = ($mode === 'month') ? 'month' : 'continuous';
    if ($state['month'] === '') {
        $state['month'] = date('Y-m');
    }
    invoice_slip_seq_write($tenantId, $state);
    return $state['mode'];
}

function invoice_slip_next_number($tenantId)
{
    $path = invoice_slip_seq_path($tenantId);
    $fh = @fopen($path, 'c+');
    if (!$fh) {
        return 'AS_' . strtoupper(substr(md5(uniqid('as', true)), 0, 6));
    }
    @flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $state = invoice_slip_seq_read($tenantId);
    if ($raw !== false && trim($raw) !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $state = array(
                'mode' => (isset($j['mode']) && $j['mode'] === 'month') ? 'month' : 'continuous',
                'n' => isset($j['n']) ? (int) $j['n'] : (int) $state['n'],
                'letter' => invoice_slip_seq_letter(isset($j['letter']) ? $j['letter'] : (isset($state['letter']) ? $state['letter'] : '')),
                'month' => isset($j['month']) ? (string) $j['month'] : date('Y-m'),
                'month_n' => isset($j['month_n']) ? (int) $j['month_n'] : 0,
            );
        }
    }
    if (!isset($state['letter'])) {
        $state['letter'] = '';
    }
    $ym = date('Y-m');
    if ($state['mode'] === 'month') {
        if ($state['month'] !== $ym) {
            $state['month'] = $ym;
            $state['month_n'] = 0;
        }
        $state['month_n'] = (int) $state['month_n'] + 1;
        if ($state['month_n'] > 9999) {
            $state['month_n'] = 1;
        }
        $label = 'AS_' . date('ym') . '_' . str_pad((string) $state['month_n'], 4, '0', STR_PAD_LEFT);
    } else {
        $state['n'] = (int) $state['n'] + 1;
        if ($state['n'] > 999999) {
            $state['n'] = 1;
            $cur = invoice_slip_seq_letter($state['letter']);
            if ($cur === '') {
                $state['letter'] = 'A';
            } elseif ($cur !== 'Z') {
                $state['letter'] = chr(ord($cur) + 1);
            } else {
                $state['letter'] = 'Z';
                $state['n'] = 999999;
            }
        }
        $state['month'] = $ym;
        $pad = str_pad((string) $state['n'], 6, '0', STR_PAD_LEFT);
        $let = invoice_slip_seq_letter($state['letter']);
        $label = ($let !== '') ? ($let . $pad) : ('AS_' . $pad);
    }
    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, json_encode($state));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $label;
}

function invoice_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function invoice_slip_safe_asset($url)
{
    $url = trim((string) $url);
    if ($url === '' || strpos($url, '..') !== false || strpos($url, "\n") !== false || strpos($url, "\r") !== false) {
        return '';
    }
    if (preg_match('#^\s*(javascript|data|file|php):#i', $url)) {
        return '';
    }
    if (preg_match('#\.php($|\?)#i', $url)) {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $url)) {
        return '';
    }
    if (!preg_match('#^(uploads/|assets/)#i', $url)) {
        return '';
    }
    return $url;
}

function invoice_slip_design_html($slip, &$outH = null)
{
    $blocks = (isset($slip['blocks']) && is_array($slip['blocks'])) ? $slip['blocks'] : array();
    if (!$blocks) {
        return '';
    }
    $w = isset($slip['paper_w']) ? (float) $slip['paper_w'] : 80;
    if ($w < 40) {
        $w = 80;
    }
    $h = isset($slip['paper_h']) ? (float) $slip['paper_h'] : 0;
    if ($h < 30) {
        $h = 24;
        foreach ($blocks as $b) {
            if (!is_array($b)) {
                continue;
            }
            $kind = isset($b['kind']) ? (string) $b['kind'] : '';
            $bh = ($kind === 'logo' || $kind === 'qr') ? (isset($b['w']) && (float) $b['w'] > 0 ? (float) $b['w'] : 18) : 8;
            $bottom = (isset($b['y']) ? (float) $b['y'] : 0) + $bh;
            if ($bottom > $h) {
                $h = $bottom;
            }
        }
        $h += 8;
    }
    $frame = invoice_frame_normalize($slip);
    $thick = (float) $frame['thick'];
    if ($frame['style'] === 'double' && $thick < 0.8) {
        $thick = 0.8;
    }
    $fx = (float) $frame['x'];
    $fy = (float) $frame['y'];
    $fw = (float) $frame['w'] >= 8 ? (float) $frame['w'] : max(10, $w - ($fx * 2));
    $fh = (float) $frame['h'] >= 8 ? (float) $frame['h'] : max(10, $h - ($fy * 2));
    $styleMap = array('line' => 'solid', 'double' => 'double', 'dashed' => 'dashed', 'round' => 'solid');
    $footY = isset($slip['footer_y']) ? (float) $slip['footer_y'] : -1;
    // احسب أقصى أسفل للمحتوى حتى ما ينقص كلام أو يتزاحف
    $contentBottom = 0;
    foreach ($blocks as $bEst) {
        if (!is_array($bEst) || (isset($bEst['on']) && !$bEst['on'])) {
            continue;
        }
        $kEst = isset($bEst['kind']) ? (string) $bEst['kind'] : '';
        $yEst = isset($bEst['y']) ? (float) $bEst['y'] : 0;
        $szEst = isset($bEst['size']) ? (float) $bEst['size'] : 13;
        if ($kEst === 'logo' || $kEst === 'qr') {
            $bhEst = isset($bEst['w']) && (float) $bEst['w'] > 0 ? (float) $bEst['w'] : 18;
        } elseif ($kEst === 'table' && !empty($bEst['rows']) && is_array($bEst['rows'])) {
            $rhEst = isset($bEst['rowH']) ? (float) $bEst['rowH'] : 7;
            $bhEst = max(4, $rhEst) * count($bEst['rows']);
        } elseif ($kEst === 'rule') {
            $bhEst = 1;
        } else {
            $bhEst = max(4, ($szEst * 0.3528) + 1.2);
        }
        $botEst = $yEst + $bhEst;
        if ($botEst > $contentBottom) {
            $contentBottom = $botEst;
        }
    }
    if ($footY >= 0 && ($footY + 7) > $contentBottom) {
        $contentBottom = $footY + 7;
    }
    if ($contentBottom + 2 > $h) {
        $h = $contentBottom + 2;
    }
    if ($footY >= 0 && ($footY + 7) > $h) {
        $h = $footY + 8;
    }
    if (($fy + $fh + 1.6) > $h) {
        $h = $fy + $fh + 1.6;
    }
    // الإطار يتبع ارتفاع الورقة حتى ما يقصّ الحد الكلام من تحت
    $needFh = max(10, $h - $fy - 0.8);
    if ($fh < $needFh) {
        $fh = $needFh;
    }
    $html = '<article class="slip slip-design" style="width:' . invoice_h($w) . 'mm;height:' . invoice_h($h) . 'mm;padding:0;position:relative;overflow:visible;background:#fff;box-sizing:border-box;font-family:Tajawal,\'Segoe UI\',Tahoma,Arial,sans-serif;font-weight:400;color:#444;">';
    if ($frame['style'] !== 'none' && isset($styleMap[$frame['style']])) {
        $html .= '<div style="position:absolute;left:' . invoice_h($fx) . 'mm;top:' . invoice_h($fy) . 'mm;width:' . invoice_h($fw) . 'mm;height:' . invoice_h($fh) . 'mm;box-sizing:border-box;border:' . invoice_h($thick) . 'mm ' . $styleMap[$frame['style']] . ' ' . invoice_h($frame['color']) . ';border-radius:' . invoice_h($frame['radius']) . 'mm;"></div>';
    }
    foreach ($blocks as $b) {
        if (!is_array($b) || (isset($b['on']) && !$b['on'])) {
            continue;
        }
        $kind = isset($b['kind']) ? (string) $b['kind'] : 'custom';
        $x = isset($b['x']) ? (float) $b['x'] : 0;
        $y = isset($b['y']) ? (float) $b['y'] : 0;
        $bw = isset($b['w']) ? (float) $b['w'] : 0;
        $size = isset($b['size']) ? (float) $b['size'] : 13;
        $color = (isset($b['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $b['color'])) ? (string) $b['color'] : '#111827';
        $align = (isset($b['align']) && in_array($b['align'], array('left', 'center', 'right'), true)) ? $b['align'] : 'right';
        $dir = (isset($b['dir']) && $b['dir'] === 'ltr') ? 'ltr' : 'rtl';
        $label = isset($b['label']) ? trim((string) $b['label']) : '';
        $text = isset($b['text']) ? (string) $b['text'] : '';
        $pos = 'position:absolute;left:' . invoice_h($x) . 'mm;top:' . invoice_h($y) . 'mm;';
        if ($bw > 0) {
            $pos .= 'width:' . invoice_h($bw) . 'mm;';
        }
        if ($kind === 'rule') {
            $rw = $bw > 0 ? $bw : max(20, $w - 8);
            $border = !empty($b['dash']) ? '0.35mm dashed ' : '0.45mm solid ';
            // نفس إزاحة المحرر حتى الخط يطلع تحت الكلام مو يقطعه
            $html .= '<div style="' . $pos . 'width:' . invoice_h($rw) . 'mm;margin-top:1.4mm;border-top:' . $border . invoice_h($color) . ';height:0;"></div>';
            continue;
        }
        if ($kind === 'table' && !empty($b['rows']) && is_array($b['rows'])) {
            $tw = $bw > 0 ? $bw : max(30, $w - 8);
            $rowH = isset($b['rowH']) ? (float) $b['rowH'] : 7;
            if ($rowH < 4) {
                $rowH = 4;
            }
            if ($rowH > 30) {
                $rowH = 30;
            }
            $cols = (isset($b['cols']) && is_array($b['cols'])) ? $b['cols'] : array();
            $colSum = 0;
            foreach ($cols as $cw) {
                $colSum += ((float) $cw > 0) ? (float) $cw : 0;
            }
            $sizeMmTbl = round(max(2.2, $size * 0.264583), 3);
            $table = '<table dir="rtl" style="width:100%;table-layout:fixed;border-collapse:collapse;border:0.4mm solid #0f172a;box-sizing:border-box;font-size:' . invoice_h($sizeMmTbl) . 'mm;color:' . invoice_h($color) . ';background:#fff;">';
            if ($colSum > 0) {
                $cellN = 0;
                foreach ($b['rows'] as $_r0) {
                    if (is_array($_r0) && count($_r0) > $cellN) {
                        $cellN = count($_r0);
                    }
                }
                if ($cellN > 0 && count($cols) > $cellN) {
                    $cols = array_slice($cols, 0, $cellN);
                    $colSum = 0;
                    foreach ($cols as $cw) {
                        $colSum += ((float) $cw > 0) ? (float) $cw : 0;
                    }
                }
                if ($colSum > 0) {
                    $table .= '<colgroup>';
                    foreach ($cols as $cw) {
                        $pct = (((float) $cw > 0 ? (float) $cw : 0) / $colSum) * 100;
                        $table .= '<col style="width:' . invoice_h(round($pct, 3)) . '%;">';
                    }
                    $table .= '</colgroup>';
                }
            }
            foreach ($b['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $table .= '<tr style="height:' . invoice_h($rowH) . 'mm;">';
                foreach ($row as $cell) {
                    if (!is_array($cell)) {
                        $cell = array();
                    }
                    $ct = isset($cell['t']) ? (string) $cell['t'] : '';
                    $ck = isset($cell['k']) ? (string) $cell['k'] : '';
                    $cv = $ck !== '' ? invoice_slip_field_value($slip, $ck) : '';
                    $show = ($ct !== '' && $cv !== '') ? ($ct . '  ' . $cv) : ($ct !== '' ? $ct : $cv);
                    if ($show === '') {
                        $show = "\xc2\xa0";
                    }
                    $span = 1;
                    $table .= '<td colspan="' . $span . '" style="border:0.35mm solid #0f172a;padding:1mm 1.2mm;height:' . invoice_h($rowH) . 'mm;vertical-align:middle;text-align:right;background:#fff;">' . invoice_h($show) . '</td>';
                }
                $table .= '</tr>';
            }
            $table .= '</table>';
            $html .= '<div style="' . $pos . 'width:' . invoice_h($tw) . 'mm;">' . $table . '</div>';
            continue;
        }
        if ($kind === 'logo') {
            $logo = isset($slip['logo']) ? (string) $slip['logo'] : '';
            if ($logo === '') {
                continue;
            }
            $lw = $bw > 0 ? $bw : 18;
            $logo = invoice_slip_safe_asset($logo);
            if ($logo === '') {
                continue;
            }
            $html .= '<img alt="" src="' . invoice_h($logo) . '" style="' . $pos . 'width:' . invoice_h($lw) . 'mm;height:auto;">';
            continue;
        }
        if ($kind === 'qr') {
            $qw = $bw > 0 ? $bw : 22;
            $url = isset($slip['url']) ? (string) $slip['url'] : '';
            $html .= '<img alt="" data-qr="' . invoice_h($url) . '" style="' . $pos . 'width:' . invoice_h($qw) . 'mm;height:' . invoice_h($qw) . 'mm;background:#fff;">';
            continue;
        }
        $value = ($kind === 'custom') ? $text : invoice_slip_field_value($slip, $kind);
        if ($kind !== 'custom' && $label === '' && $text !== '') {
            $value = $text;
        }
        // لفّ العنوان فقط، مثل المحرر. باقي الأسطر سطر واحد حتى ما تتداخل
        $wrapLine = ($kind === 'address');
        if ($kind === 'custom' && (strpos($text, "\n") !== false || mb_strlen($text, 'UTF-8') > 42)) {
            $wrapLine = true;
        }
        $fw = (isset($b['weight']) && $b['weight'] === 'normal') ? '400' : '500';
        $fst = !empty($b['italic']) ? 'italic' : 'normal';
        $td = !empty($b['under']) ? 'underline' : 'none';
        $type = 'font-weight:' . $fw . ';font-style:' . $fst . ';text-decoration:' . $td . ';';
        $inner = '';
        if ($kind !== 'custom' && $label !== '') {
            if (!empty($b['stack'])) {
                $flow = 'flex-direction:column;align-items:stretch;';
                $valStyle = 'flex:1 1 auto;min-width:0;white-space:normal;word-break:break-word;';
            } elseif ($align === 'center' || $bw > 0) {
                $flow = 'justify-content:space-between;align-items:center;';
                $valStyle = 'flex:0 1 auto;min-width:0;white-space:nowrap;text-align:left;direction:ltr;';
            } else {
                $flow = 'justify-content:flex-start;align-items:center;';
                $valStyle = 'flex:0 1 auto;min-width:0;white-space:nowrap;';
            }
            $pairW = ($bw > 0 || $align === 'center') ? 'width:100%;' : 'width:max-content;max-width:100%;';
            $inner = '<span class="slip-pair" style="display:flex;' . $flow . 'gap:1.5mm;' . $pairW . 'box-sizing:border-box;' . $type . '">'
                . '<span style="flex:0 0 auto;white-space:nowrap;">' . invoice_h($label) . '</span>'
                . '<span style="' . $valStyle . '">' . invoice_h($value) . '</span></span>';
        } else {
            $inner = invoice_h($value);
        }
        $sizeMm = round(max(2.2, $size * 0.264583), 3);
        $lineStyle = $pos . 'margin:0;padding:0;font-family:Tajawal,\'Segoe UI\',Tahoma,Arial,sans-serif;font-size:' . invoice_h($sizeMm) . 'mm;color:' . invoice_h($color) . ';text-align:' . invoice_h($align) . ';direction:' . invoice_h($dir) . ';line-height:' . ($wrapLine ? '1.35' : '1.15') . ';white-space:' . ($wrapLine ? 'normal' : 'nowrap') . ';word-break:' . ($wrapLine ? 'break-word' : 'normal') . ';overflow:visible;box-sizing:border-box;' . $type;
        $lineClass = 'slip-line' . ($wrapLine ? ' is-wrap' : '');
        if ($bw > 0) {
            $lineStyle .= 'width:' . invoice_h($bw) . 'mm;';
        } elseif ($label !== '' && $align === 'center') {
            $frameRight = (isset($frame['x']) ? (float) $frame['x'] : 0) + (isset($frame['w']) ? (float) $frame['w'] : $w);
            $fitW = max(12, $frameRight - $x - 1.2);
            $lineStyle .= 'width:' . invoice_h($fitW) . 'mm;';
        } else {
            $lineStyle .= 'width:max-content;max-width:' . invoice_h(max(20, $w - $x)) . 'mm;';
        }
        $html .= '<div class="' . $lineClass . '" style="' . $lineStyle . '">' . $inner . '</div>';
    }
    $footer = isset($slip['footer']) ? trim((string) $slip['footer']) : '';
    if ($footer === '') {
        $footer = invoice_system_footer();
    }
    $footY = isset($slip['footer_y']) ? (float) $slip['footer_y'] : -1;
    $footX = isset($slip['footer_x']) ? (float) $slip['footer_x'] : -1;
    $fa = (isset($slip['footer_align']) && in_array($slip['footer_align'], array('left', 'center', 'right'), true)) ? $slip['footer_align'] : 'center';
    $fstyle = 'position:absolute;z-index:5;background:transparent;font-family:inherit;font-size:2.9mm;line-height:1.35;font-weight:400;color:#64748b;padding:0.4mm 1.6mm;text-align:' . invoice_h($fa) . ';overflow:visible;white-space:normal;word-break:break-word;box-sizing:border-box;';
    if ($footX >= 0 && $footY >= 0) {
        $fstyle .= 'left:' . invoice_h($footX) . 'mm;top:' . invoice_h($footY) . 'mm;width:' . invoice_h(max(10, $w - $footX - 1)) . 'mm;';
    } elseif ($footY >= 0) {
        $fstyle .= 'left:1mm;right:1mm;top:' . invoice_h($footY) . 'mm;';
    } else {
        $fstyle .= 'left:1mm;right:1mm;bottom:1.2mm;';
    }
    $html .= '<div class="slip-foot" style="' . $fstyle . '">' . invoice_h($footer) . '</div>';
    if (func_num_args() >= 2) {
        $outH = $h;
    }
    $html .= '</article>';
    return $html;
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
        array('id' => 'username', 'label' => $isEn ? 'Username' : 'اليوزر'),
        array('id' => 'mobile', 'label' => $isEn ? 'Mobile' : 'هاتف المشترك'),
        array('id' => 'number', 'label' => $isEn ? 'Number' : 'رقم الفاتورة'),
        array('id' => 'date', 'label' => $isEn ? 'Date' : 'التاريخ'),
        array('id' => 'time', 'label' => $isEn ? 'Time' : 'الوقت'),
        array('id' => 'logo', 'label' => $isEn ? 'Logo' : 'الشعار'),
        array('id' => 'qr', 'label' => 'QR'),
        array('id' => 'rule', 'label' => $isEn ? 'Line' : 'خط'),
        array('id' => 'ruledash', 'label' => $isEn ? 'Dashed' : 'متقطع'),
        array('id' => 'table', 'label' => $isEn ? 'Table' : 'جدول'),
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
            'viewTpl' => $isEn ? 'View templates' : 'عرض القوالب',
            'pick' => $isEn ? 'Choose a template to preview' : 'اختر قالباً للاستعراض',
            'new' => $isEn ? 'New template' : 'قالب جديد',
            'backInvoices' => $isEn ? 'Back to invoices' : 'رجوع للفواتير',
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
            'oneCol' => $isEn ? 'One column' : 'نسق واحد',
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
    body.inv-fit { overflow: auto; }
    body.inv-fit .app { min-height: 100vh; height: auto; overflow: visible; }
    body.inv-fit .main { min-height: 100vh; height: auto; overflow: visible; display: flex; flex-direction: column; }
    body.inv-fit .sidebar { max-height: 100vh; overflow: auto; }
    body.inv-fit .container { margin: 16px; overflow: visible; display: block; }
    body.inv-fit .inv-app.is-edit { height: calc(100vh - 70px); overflow: hidden; }
    body.inv-fit .footer { display: none; }
    body.inv-fit [hidden] { display: none !important; }
    .inv-app { flex: 1; min-height: 0; width: 100%; display: flex; flex-direction: column; }
    .inv-gallery, .inv-desk { flex: 1; min-height: 0; min-width: 0; background: linear-gradient(180deg,#f8fafc,#eef2f6); border: 1px solid #e6ebf2; border-radius: 22px; overflow: hidden; }
    .inv-gallery:not([hidden]), .inv-desk:not([hidden]) { display: flex; flex-direction: column; }
    .inv-gbar { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: rgba(255,255,255,.94); border-bottom: 1px solid #e6ebf2; flex: 0 0 auto; }
    .inv-gbar h2 { margin: 0; font-size: 18px; }
    .inv-gbar p { margin: 2px 0 0; color: #64748b; font-size: 12px; font-weight: 700; }
    .inv-gbar-acts, .inv-top-acts { display: flex; gap: 6px; margin-inline-start: auto; }
    .inv-name { border: 0; background: rgba(255,255,255,.94); font: inherit; font-weight: 800; font-size: 16px; min-width: 0; flex: 1; color: inherit; border-radius: 12px; padding: 6px 10px; box-shadow: 0 6px 16px rgba(15,23,42,.06); }
    .inv-len { color: #0f766e; font-weight: 800; font-size: 12px; white-space: nowrap; background: rgba(255,255,255,.94); border-radius: 999px; padding: 6px 10px; box-shadow: 0 6px 16px rgba(15,23,42,.06); }
    .inv-gallery { width: 100%; align-items: stretch; }
    .inv-board { width: 100%; box-sizing: border-box; background: #fff; border: 1px solid #e7edf4; border-radius: 22px; box-shadow: 0 20px 50px rgba(15,23,42,.06); overflow: hidden; }
    .inv-board-head { display: flex; align-items: center; gap: 8px; padding: 10px 12px; flex-wrap: wrap; }
    .inv-board-head h2 { margin: 0; font-size: 18px; font-weight: 800; letter-spacing: -.03em; color: #0f172a; }
    .inv-count { color: #94a3b8; font-size: 12px; font-weight: 800; }
    .inv-head-acts { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .inv-head-acts .btn { height: 32px; padding: 0 12px; border-radius: 999px; font-weight: 800; }
    .inv-search { margin-inline-start: auto; width: min(280px, 42vw); height: 34px; border-radius: 999px; border: 1px solid #e6ebf2; background: #f8fafc; padding: 0 12px; font: inherit; font-weight: 700; color: #0f172a; outline: none; }
    .inv-search:focus { border-color: #0f766e; background: #fff; box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
    .inv-tpl-panel { display: none; flex-direction: column; flex: 1; min-height: 0; width: 100%; background: linear-gradient(180deg,#f8fafc,#eef2f6); border: 1px solid #e6ebf2; border-radius: 22px; overflow: hidden; }
    .inv-tpl-panel.is-on { display: flex; }
    .inv-board.is-away { display: none; }
    .inv-table-wrap { overflow: auto; max-height: calc(100vh - 200px); }
    .inv-ledger { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 0; font-size: 13px; }
    .inv-ledger th:nth-child(1), .inv-ledger td:nth-child(1) { width: 11%; }
    .inv-ledger th:nth-child(2), .inv-ledger td:nth-child(2) { width: 24%; }
    .inv-ledger th:nth-child(3), .inv-ledger td:nth-child(3) { width: 13%; }
    .inv-ledger th:nth-child(4), .inv-ledger td:nth-child(4) { width: 11%; }
    .inv-ledger th:nth-child(5), .inv-ledger td:nth-child(5) { width: 15%; }
    .inv-ledger th:nth-child(6), .inv-ledger td:nth-child(6) { width: 10%; }
    .inv-ledger th:nth-child(7), .inv-ledger td:nth-child(7) { width: 16%; }
    .inv-ledger td { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .inv-ledger th { position: sticky; top: 0; z-index: 1; background: #f8fafc; color: #64748b; font-size: 10px; font-weight: 800; letter-spacing: .04em; text-align: start; padding: 6px 8px; border-bottom: 1px solid #e8edf3; }
    .inv-ledger td { padding: 5px 8px; border-bottom: 1px solid #f3f6f9; color: #0f172a; vertical-align: middle; line-height: 1.25; }
    .inv-ledger tbody tr { cursor: pointer; transition: background .12s ease; }
    .inv-ledger tbody tr:hover { background: #f8fafc; }
    .inv-ledger tbody tr.is-on { background: #f0fdfa; }
    .inv-ledger .num { font-weight: 800; letter-spacing: .01em; }
    .inv-ledger .amt { font-weight: 800; font-variant-numeric: tabular-nums; }
    .inv-ledger .muted { color: #64748b; font-weight: 700; }
    .inv-mini-acts { display: flex; gap: 4px; justify-content: flex-end; }
    .inv-mini-acts button, .inv-mini-acts a { height: 26px; padding: 0 8px; border-radius: 999px; border: 1px solid #e6ebf2; background: #fff; color: #0f172a; font: inherit; font-size: 11px; font-weight: 800; text-decoration: none; cursor: pointer; }
    .inv-mini-acts .is-main { background: #0f172a; color: #fff; border-color: #0f172a; }
    .inv-pager { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 8px 12px 10px; border-top: 1px solid #eef2f6; background: #fbfcfe; }
    .inv-pager-sizes, .inv-pager-pages { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
    .inv-pager .pager-n {
      display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px;
      padding: 0 8px; border-radius: 999px; border: 0; background: transparent; color: #64748b;
      font: inherit; font-size: 12px; font-weight: 800; text-decoration: none; cursor: pointer;
    }
    .inv-pager .pager-n:hover { color: #334155; background: rgba(255,255,255,.85); }
    .inv-pager .pager-n.is-on { color: #0f766e; background: #ecfdf5; }
    .inv-pager .pager-n.is-off { opacity: .28; pointer-events: none; }
    .inv-sheet { position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; z-index: 100080; background: rgba(15,23,42,.45); display: flex; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; direction: ltr; }
    .inv-sheet[hidden] { display: none !important; }
    .inv-sheet-card { width: min(440px, calc(100vw - 32px)); height: min(90vh, 860px); margin: 0; background: #fff; display: flex; flex-direction: column; border-radius: 22px; overflow: hidden; box-shadow: 0 30px 80px rgba(15,23,42,.28); direction: rtl; }
    .inv-seq { display: inline-flex; align-items: center; gap: 4px; background: #f1f5f9; border-radius: 999px; padding: 3px; }
    .inv-seq button { height: 28px; padding: 0 10px; border: 0; border-radius: 999px; background: transparent; color: #475569; font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; }
    .inv-seq button.is-on { background: #0f172a; color: #fff; }
    .inv-sheet-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding: 14px 16px; border-bottom: 1px solid #eef2f6; }
    .inv-sheet-acts { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
    .inv-sheet-bar button { height: 32px; padding: 0 12px; border-radius: 999px; border: 1px solid #e6ebf2; background: #fff; color: #0f172a; font: inherit; font-size: 12px; font-weight: 800; cursor: pointer; }
    .inv-sheet-bar form { margin: 0; }
    .inv-sheet-bar form button { background: #0f172a; color: #fff; border-color: #0f172a; }
    .inv-sheet-bar strong { font-size: 16px; }
    .inv-sheet-bar .grow { flex: 1; }
    .inv-sheet-card iframe { flex: 1; width: 100%; border: 0; background: #f4f7fb; }
    .inv-sheet-bar form { margin: 0; }
    .inv-x { width: 36px; height: 36px; border: 0; border-radius: 999px; background: #f1f5f9; font-size: 18px; cursor: pointer; }
    .inv-empty-cell { text-align: center; color: #94a3b8; font-weight: 700; padding: 36px 16px !important; }
    @media (max-width: 760px) {
      .inv-board-head { flex-wrap: wrap; }
      .inv-search { width: 100%; margin-inline-start: 0; }
      .inv-col-phone, .inv-col-who { display: none; }
      .inv-table-wrap { max-height: none; }
      .inv-sheet { padding: 0; align-items: stretch; }
      .inv-sheet-card { width: 100%; height: 100%; border-radius: 0; }
      .inv-sheet-bar { padding: 10px; gap: 6px; }
      .inv-sheet-bar strong { order: 1; flex: 1 1 auto; font-size: 14px; }
      .inv-sheet-bar .inv-x { order: 2; }
      .inv-sheet-bar .grow { display: none; }
      .inv-sheet-acts { order: 3; width: 100%; }
      .inv-sheet-acts button, .inv-sheet-acts a, .inv-sheet-acts form { flex: 1 1 auto; }
      .inv-sheet-acts form button, .inv-sheet-acts > button, .inv-sheet-acts a span { width: 100%; justify-content: center; }
      .inv-ledger td, .inv-ledger th { padding: 5px 6px; }
      .inv-pager { justify-content: center; }
    }
    .inv-cards { flex: 0 0 auto; max-height: none; min-height: 0; display: grid; grid-template-columns: repeat(auto-fill,minmax(180px,1fr)); gap: 10px; padding: 12px; overflow: visible; align-content: start; }
    .inv-card { position: relative; overflow: hidden; background: #fff; border: 1px solid #e6ebf2; border-radius: 18px; padding: 10px; cursor: pointer; box-shadow: 0 10px 28px rgba(15,23,42,.05); text-align: center; }
    .inv-card:hover { border-color: #99f6e4; transform: translateY(-2px); }
    .inv-card strong { display: block; margin-top: 8px; }
    .inv-card em { display: block; color: #64748b; font-style: normal; font-size: 12px; font-weight: 700; }
    .inv-thumb { height: 150px; border-radius: 12px; background: #f1f5f9; overflow: hidden; display: flex; align-items: flex-start; justify-content: center; }
    .inv-mini { position: relative; }
    .inv-card-del { position: absolute; top: 8px; inset-inline-end: 8px; width: 26px; height: 26px; border: 0; border-radius: 999px; background: #fff; color: #b91c1c; font-weight: 800; cursor: pointer; box-shadow: 0 4px 12px rgba(15,23,42,.12); }
    .inv-desk:not([hidden]) {
      display: grid;
      grid-template-columns: 56px minmax(0, 1fr);
      grid-template-rows: minmax(0, 1fr) auto auto;
    }
    .inv-body { display: contents; }
    .inv-prop {
      grid-column: 2; grid-row: 2;
      width: auto; max-width: 100%; min-width: 0;
      display: flex; flex-direction: row; flex-wrap: nowrap; align-items: center;
      gap: 8px; padding: 8px 10px; background: #fff;
      border-top: 1px solid #e6ebf2; overflow-x: auto; overflow-y: hidden;
    }
    .inv-prop[hidden] { display: none !important; }
    .inv-prop > * { position: relative; flex: 0 0 auto; }
    .inv-field { display: flex; align-items: center; gap: 6px; }
    .inv-prop .inv-seg { display: flex; flex-wrap: nowrap; width: auto; }
    #pText { width: 150px; max-width: 150px; }
    #pBind { width: 132px; max-width: 132px; height: 32px; }
    .inv-prop input[type="number"] { width: 46px; }
    .inv-field > span { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); border: 0; }
    .inv-field > span.inv-keep { position: static; width: auto; height: auto; margin: 0; overflow: visible; clip: auto; font-size: 11px; font-weight: 800; color: #64748b; }
    .inv-prop input[type="text"], .inv-prop input[type="number"] { height: 32px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0 8px; background: #fff; }
    #pSizeN { width: 42px; text-align: center; border: 0; background: transparent; font-weight: 800; }
    #pColor, #pFrameColor { width: 28px; height: 28px; border: 0; background: transparent; padding: 0; }
    .inv-seg { display: flex; background: #f1f5f9; border-radius: 10px; padding: 2px; gap: 2px; }
    .inv-seg button { width: 32px; height: 32px; padding: 0; border: 0; border-radius: 8px; background: transparent; color: #334155; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
    .inv-seg button svg { display: block; }
    .inv-seg button.is-on { background: #fff; color: #0f766e; box-shadow: 0 1px 4px rgba(15,23,42,.08); }
    .inv-ico { position: relative; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; color: #334155; cursor: pointer; }
    .inv-ico input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
    .inv-ico.is-on { background: #ccfbf1; color: #0f766e; }
    .inv-rail { grid-column: 1; grid-row: 1 / 3; width: 56px; display: flex; flex-direction: column; gap: 8px; align-items: center; justify-content: flex-start; padding: 10px 6px; background: #fff; border-left: 1px solid #e6ebf2; }
    .inv-stage { grid-column: 2; grid-row: 1; position: relative; min-width: 0; min-height: 0; display: flex; align-items: center; justify-content: center; overflow: auto; background: #e7edf3; padding: 20px; direction: ltr; }
    .inv-bottom { grid-column: 1 / -1; grid-row: 3; }
    .inv-rail button { width: 42px; height: 42px; border: 0; border-radius: 12px; background: transparent; color: #334155; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .inv-rail button.is-on, .inv-rail button:hover { background: #ccfbf1; color: #0f766e; }
    .inv-name { border: 0; background: transparent; font: inherit; font-weight: 800; font-size: 15px; min-width: 0; width: 180px; flex: 0 1 220px; color: inherit; border-radius: 10px; padding: 4px 8px; box-shadow: none; }
    .inv-len { color: #0f766e; font-weight: 800; font-size: 12px; white-space: nowrap; background: transparent; box-shadow: none; padding: 0; }
    .inv-fit { position: relative; flex: 0 0 auto; margin: 0; }
    .inv-paper { position: absolute; left: 0; top: 0; background: #fff; box-shadow: 0 16px 40px rgba(15,23,42,.14); overflow: visible; box-sizing: border-box; font-family: "Tajawal", "Segoe UI", Tahoma, Arial, sans-serif; font-weight: 400; color: #444; }
    .inv-frame { position: absolute; box-sizing: border-box; z-index: 1; pointer-events: none; }
    .inv-frame.is-pick { outline: 1px dashed #0f766e; outline-offset: 2px; }
    .inv-edge { position: absolute; pointer-events: auto; background: transparent; touch-action: none; }
    .inv-edge[data-h="n"], .inv-edge[data-h="s"] { left: 0; right: 0; height: 12px; cursor: move; }
    .inv-edge[data-h="n"] { top: -6px; }
    .inv-edge[data-h="s"] { bottom: -6px; }
    .inv-edge[data-h="e"], .inv-edge[data-h="w"] { top: 0; bottom: 0; width: 12px; cursor: move; }
    .inv-edge[data-h="e"] { right: -6px; }
    .inv-edge[data-h="w"] { left: -6px; }
    .inv-fr-h { display: none; position: absolute; width: 9px; height: 9px; background: #0f766e; border-radius: 2px; pointer-events: auto; touch-action: none; z-index: 2; }
    .inv-app.is-edit .inv-frame.is-pick .inv-fr-h { display: block; }
    .inv-fr-h[data-h="nw"] { top: -5px; left: -5px; cursor: nwse-resize; }
    .inv-fr-h[data-h="ne"] { top: -5px; right: -5px; cursor: nesw-resize; }
    .inv-fr-h[data-h="sw"] { bottom: -5px; left: -5px; cursor: nesw-resize; }
    .inv-fr-h[data-h="se"] { bottom: -5px; right: -5px; cursor: nwse-resize; }
    .inv-fr-h[data-h="n"] { top: -5px; left: 50%; margin-left: -4px; cursor: ns-resize; }
    .inv-fr-h[data-h="s"] { bottom: -5px; left: 50%; margin-left: -4px; cursor: ns-resize; }
    .inv-fr-h[data-h="e"] { right: -5px; top: 50%; margin-top: -4px; cursor: ew-resize; }
    .inv-fr-h[data-h="w"] { left: -5px; top: 50%; margin-top: -4px; cursor: ew-resize; }
    .inv-block { position: absolute; margin: 0; padding: 0; line-height: 1.15; letter-spacing: 0; background: transparent; border: 0; white-space: nowrap; box-sizing: border-box; user-select: none; z-index: 5; touch-action: none; }
    .inv-pair { display: flex; align-items: center; justify-content: flex-start; gap: 1.5mm; width: max-content; max-width: 100%; }
    .inv-block:not(.is-hug) .inv-pair { width: 100%; justify-content: space-between; }
    .inv-block.is-center .inv-pair { width: 100%; justify-content: space-between; }
    .inv-block.is-center .inv-var { direction: ltr; text-align: left; }
    .inv-block.is-wrap { white-space: normal; line-height: 1.35; }
    .inv-const, .inv-var { font-weight: inherit; font-style: inherit; text-decoration: inherit; }
    .inv-app.is-edit .inv-var { background: rgba(15,118,110,.12); border-radius: 4px; padding: 0 1.2mm; }
    #pBind { height: 32px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; font-weight: 800; max-width: 150px; }
    .inv-block.is-hug { width: max-content; }
    .inv-app.is-edit .inv-block { cursor: grab; }
    .inv-block.is-pick { outline: 1.5px solid #0f766e; outline-offset: 1px; }
    .inv-val { padding: 0; margin: 0; }
    .inv-resize {
      display: none; position: absolute; width: 12px; height: 12px; border-radius: 3px;
      background: #0f766e; border: 2px solid #fff; box-shadow: 0 1px 5px rgba(15,23,42,.35);
      z-index: 12; box-sizing: border-box; touch-action: none;
    }
    .inv-resize[data-h="nw"] { top: -6px; left: -6px; cursor: nwse-resize; }
    .inv-resize[data-h="n"]  { top: -6px; left: 50%; margin-left: -6px; cursor: ns-resize; }
    .inv-resize[data-h="ne"] { top: -6px; right: -6px; cursor: nesw-resize; }
    .inv-resize[data-h="e"]  { top: 50%; right: -6px; margin-top: -6px; cursor: ew-resize; }
    .inv-resize[data-h="se"] { bottom: -6px; right: -6px; cursor: nwse-resize; }
    .inv-resize[data-h="s"]  { bottom: -6px; left: 50%; margin-left: -6px; cursor: ns-resize; }
    .inv-resize[data-h="sw"] { bottom: -6px; left: -6px; cursor: nesw-resize; }
    .inv-resize[data-h="w"]  { top: 50%; left: -6px; margin-top: -6px; cursor: ew-resize; }
    .inv-guide { position: absolute; pointer-events: none; z-index: 6; display: none; }
    .inv-guide.is-on { display: block; }
    .inv-guide-v { top: 0; bottom: 0; width: 0; border-left: 1px dashed #0f766e; }
    .inv-len-handle { position: absolute; left: 50%; bottom: -12px; transform: translateX(-50%); height: 22px; min-width: 72px; padding: 0 10px; border-radius: 999px; background: #0f172a; color: #fff; font-size: 11px; font-weight: 800; display: none; align-items: center; justify-content: center; cursor: ns-resize; z-index: 8; box-shadow: 0 8px 18px rgba(15,23,42,.28); letter-spacing: .02em; }
    .inv-app.is-edit .inv-len-handle { display: flex; }
    .inv-len-handle::before { content: ''; width: 18px; height: 2px; border-radius: 2px; background: rgba(255,255,255,.7); margin-inline-end: 6px; }
    .inv-wid-handle { position: absolute; top: 50%; left: -16px; transform: translateY(-50%); width: 26px; min-height: 74px; padding: 8px 0; border-radius: 999px; background: #0f172a; color: #fff; font-size: 11px; font-weight: 800; display: none; align-items: center; justify-content: center; cursor: ew-resize; z-index: 8; box-shadow: 0 8px 18px rgba(15,23,42,.28); writing-mode: vertical-rl; }
    .inv-len-handle, .inv-wid-handle { touch-action: none; }
    #pCount { font-size: 12px; font-weight: 800; color: #0f766e; white-space: nowrap; }
    .inv-app.is-edit .inv-block.is-pick .inv-resize { display: block; }
    .inv-block img { width: 100%; height: auto; display: block; }
    .inv-logo-ph { display: flex; align-items: center; justify-content: center; width: 100%; height: 12mm; background: #f8fafc; color: #94a3b8; font-size: 10px; }
    .inv-foot { position: absolute; z-index: 4; margin: 0; background: transparent; text-align: center; font-size: 2.9mm; line-height: 1.35; font-weight: 400; color: #64748b; padding: 0.4mm 1.6mm; box-sizing: border-box; max-width: calc(100% - 2mm); overflow: visible; white-space: normal; word-break: break-word; user-select: none; font-family: "Tajawal", "Segoe UI", Tahoma, Arial, sans-serif; }
    .inv-app.is-edit .inv-foot { cursor: grab; }
    .inv-foot.is-pick { outline: 1.5px solid #0f766e; outline-offset: 1px; }
    #pDel { width: 32px; min-width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; }
    #pFootNote { display: none !important; }
    .inv-pop { position: absolute; top: 12px; left: 12px; right: auto; width: 280px; max-height: calc(100% - 24px); overflow: auto; background: #fff; border-radius: 16px; border: 1px solid #e6ebf2; box-shadow: 0 18px 48px rgba(15,23,42,.16); padding: 12px; z-index: 5; }
    .inv-pair.is-stack { flex-direction: column; align-items: stretch; gap: 0.4mm; white-space: normal; }
    .inv-block.is-stack { white-space: normal; }
    .inv-pair.is-stack .inv-const, .inv-pair.is-stack .inv-var { display: block; white-space: normal; max-width: 100%; }
    .inv-table {
      width: 100%; table-layout: fixed; border-collapse: collapse;
      border: none; background: #fff; box-sizing: border-box;
      font: inherit; color: #0f172a; direction: rtl;
    }
    .inv-block.is-table {
      overflow: hidden; box-sizing: border-box; background: #fff; padding: 0;
    }
    .inv-table td {
      padding: 1.2mm 1.5mm; vertical-align: middle; text-align: right;
      border: 0.35mm solid #0f172a; background: #fff; box-sizing: border-box;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      color: #0f172a; font-size: inherit; line-height: 1.25;
    }
    .inv-app.is-edit .inv-table td { cursor: grab; }
    .inv-app.is-edit .inv-table td.inv-var { background: rgba(15,118,110,.08); }
    .inv-table td.is-cell { background: rgba(15,118,110,.1); outline: 2px solid #0f766e; outline-offset: -2px; }
    .inv-col-line {
      position: absolute; top: 0; bottom: 0; width: 12px; margin-left: -6px;
      cursor: col-resize; z-index: 11; touch-action: none;
    }
    .inv-col-line::after {
      content: ''; position: absolute; left: 50%; top: 0; bottom: 0; width: 2px;
      transform: translateX(-50%); background: #0f766e; opacity: .4;
    }
    .inv-col-line:hover::after, .inv-col-line.is-drag::after { opacity: 1; width: 3px; }
    .inv-block.is-table.is-pick .inv-resize { width: 14px; height: 14px; }
    .inv-tbar { display: flex; flex-wrap: wrap; gap: 4px; justify-content: center; align-items: center; }
    .inv-tbar button { height: 28px; padding: 0 6px; border: 0; border-radius: 8px; background: #f1f5f9; font-weight: 800; cursor: pointer; }
    .inv-tbar .inv-rowh { display: inline-flex; align-items: center; gap: 4px; height: 28px; padding: 0 6px; border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 11px; font-weight: 800; color: #475569; }
    .inv-tbar .inv-rowh input { width: 44px; height: 22px; border: 0; background: transparent; font-weight: 800; text-align: center; }
    .inv-trow { display: flex; gap: 4px; margin-bottom: 6px; }
    .inv-trow input, .inv-trow select { height: 30px; border: 1px solid #e2e8f0; border-radius: 8px; min-width: 0; }
    .inv-trow input { flex: 1; }
    .inv-seg button.inv-lang, #pPack button { width: auto; min-width: 32px; padding: 0 8px; font-size: 12px; font-weight: 800; letter-spacing: .04em; }
    .inv-pop h3 { margin: 0 0 8px; font-size: 14px; }
    .inv-pop > button { margin-top: 10px; height: 34px; width: 100%; border-radius: 10px; border: 1px solid #e2e8f0; background: #fff; font-weight: 800; cursor: pointer; }
    .inv-pop label { display: block; margin: 8px 0 4px; font-size: 12px; font-weight: 800; color: #64748b; }
    .inv-pop select, .inv-pop input[type="text"] { width: 100%; height: 36px; border-radius: 10px; border: 1px solid #e2e8f0; padding: 0 8px; background: #fff; }
    .inv-kind, .inv-frames { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    .inv-kind button, .inv-frames button { height: 34px; border-radius: 10px; border: 1px solid #e2e8f0; background: #fff; font-weight: 800; cursor: pointer; }
    .inv-frames button.is-on, .inv-kind button:hover { border-color: #0f766e; color: #0f766e; }
    .inv-bottom { grid-column: 1 / -1; grid-row: 3; display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #fff; border-top: 1px solid #e6ebf2; }
    .inv-bottom-gap { flex: 1; }
    .inv-modal { position: fixed; inset: 0; background: rgba(15,23,42,.38); z-index: 80; display: flex; align-items: center; justify-content: center; padding: 16px; }
    .inv-modal[hidden] { display: none !important; }
    .inv-modal-card { width: min(560px,100%); max-height: min(78vh,640px); overflow: auto; background: #fff; border-radius: 20px; padding: 16px; box-shadow: 0 24px 60px rgba(15,23,42,.22); }
    .inv-modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .inv-add { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .inv-add input { height: 40px; border-radius: 12px; border: 1px solid #e2e8f0; padding: 0 10px; }
    .inv-printer { display: flex; justify-content: space-between; gap: 10px; align-items: center; border: 1px solid #e6ebf2; border-radius: 14px; padding: 10px 12px; margin-bottom: 8px; }
    .inv-printer.is-def { border-color: #86efac; background: #f0fdf4; box-shadow: 0 0 0 1px rgba(22,163,74,.12); }
    .inv-printer span, .inv-printer em { display: block; color: #64748b; font-size: 12px; font-style: normal; font-weight: 700; }
    .inv-acts { display: flex; gap: 6px; align-items: center; }
    .inv-def {
      display: inline-flex; align-items: center; gap: 6px;
      background: #dcfce7; color: #166534; font-weight: 800; border-radius: 999px; padding: 5px 12px;
    }
    .inv-def::before {
      content: ''; width: 16px; height: 16px; border-radius: 50%; background: #16a34a;
      box-shadow: inset 0 0 0 2px #fff;
      background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'/></svg>");
      background-size: 10px 10px; background-repeat: no-repeat; background-position: center;
    }
    @media (max-width: 760px) {
      .inv-desk:not([hidden]) { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto minmax(0, 1fr) auto auto; }
      .inv-rail { grid-column: 1; grid-row: 1; flex-direction: row; width: auto; justify-content: center; border-left: 0; border-bottom: 1px solid #e6ebf2; }
      .inv-stage { grid-column: 1; grid-row: 2; }
      .inv-prop { grid-column: 1; grid-row: 3; }
      .inv-bottom { grid-row: 4; }
    }
    </style>
    <div class="inv-app" id="invApp">
        <section class="inv-gallery" id="invGallery">
            <?php
            $invPerRaw = isset($_GET['inv_per']) ? (string) $_GET['inv_per'] : '20';
            $invAllowed = array(5, 10, 20, 50, 100);
            $invShowAll = ($invPerRaw === 'all');
            if ($invShowAll) {
                $invPer = 0;
            } else {
                $invPer = (int) $invPerRaw;
                if (!in_array($invPer, $invAllowed, true)) {
                    $invPer = 20;
                    $invPerRaw = '20';
                }
            }
            $invPage = isset($_GET['inv_page']) ? max(1, (int) $_GET['inv_page']) : 1;
            $invQ = isset($_GET['inv_q']) ? trim((string) $_GET['inv_q']) : '';
            $issuedTotal = 0;
            $issuedAll = function_exists('invoice_slips_visible')
                ? invoice_slips_visible($tenantId, 0, 0, $issuedTotal)
                : array();
            if (!is_array($issuedAll)) {
                $issuedAll = array();
                $issuedTotal = 0;
            }
            if ($invQ !== '') {
                $invNeedle = function_exists('mb_strtolower') ? mb_strtolower($invQ, 'UTF-8') : strtolower($invQ);
                $filtered = array();
                foreach ($issuedAll as $slipFilterRow) {
                    if (!is_array($slipFilterRow)) {
                        continue;
                    }
                    $hay = strtolower(
                        (isset($slipFilterRow['number']) ? (string) $slipFilterRow['number'] : '') . ' '
                        . (isset($slipFilterRow['name']) ? (string) $slipFilterRow['name'] : '') . ' '
                        . (isset($slipFilterRow['phone']) ? (string) $slipFilterRow['phone'] : '') . ' '
                        . (isset($slipFilterRow['amount']) ? (string) $slipFilterRow['amount'] : '') . ' '
                        . (isset($slipFilterRow['created_at']) ? (string) $slipFilterRow['created_at'] : '') . ' '
                        . (isset($slipFilterRow['issuer_name']) ? (string) $slipFilterRow['issuer_name'] : '') . ' '
                        . (isset($slipFilterRow['username']) ? (string) $slipFilterRow['username'] : '')
                    );
                    if ($hay !== '' && strpos($hay, $invNeedle) !== false) {
                        $filtered[] = $slipFilterRow;
                    }
                }
                $issuedAll = $filtered;
                $issuedTotal = count($issuedAll);
            }
            if ($invShowAll) {
                $invPerPage = $issuedTotal > 0 ? $issuedTotal : 1;
                $invPages = 1;
                $invPage = 1;
                $invOffset = 0;
                $issued = $issuedAll;
            } else {
                $invPages = max(1, (int) ceil(($issuedTotal > 0 ? $issuedTotal : 1) / max(1, $invPer)));
                if ($invPage > $invPages) {
                    $invPage = $invPages;
                }
                $invOffset = ($invPage - 1) * $invPer;
                $issued = array_slice($issuedAll, $invOffset, $invPer);
                $invPerPage = $invPer;
            }
            $issuedN = count($issued);
            $invKeep = array('tab=invoices');
            if ($invQ !== '') {
                $invKeep[] = 'inv_q=' . rawurlencode($invQ);
            }
            if ($invPerRaw !== '20') {
                $invKeep[] = 'inv_per=' . rawurlencode($invPerRaw);
            }
            $invKeepStr = implode('&', $invKeep);
            $invWaOn = true;
            if (function_exists('whatsapp_notifications_enabled')) {
                global $pdo;
                $invWaOn = isset($pdo) && $pdo ? (bool) whatsapp_notifications_enabled($pdo) : false;
            }
            ?>
            <div class="inv-board" id="invBoard">
                <div class="inv-board-head">
                    <h2><?php echo e($isEn ? 'Invoices' : 'الفواتير'); ?></h2>
                    <span class="inv-count" id="invSlipCount"><?php echo (int) $issuedTotal; ?></span>
                    <div class="inv-head-acts">
                        <button class="btn ghost sm" type="button" id="invShowTpl"><?php echo e($payload['ui']['viewTpl']); ?></button>
                        <button class="btn ghost sm" type="button" id="invPrintOpen"><?php echo e($payload['ui']['printers']); ?></button>
                    </div>
                    <form method="get" action="settings.php" class="inv-search-form" style="margin-inline-start:auto;display:flex;gap:6px;align-items:center;min-width:min(280px,42vw);flex:1 1 200px">
                        <input type="hidden" name="tab" value="invoices">
                        <?php if ($invPerRaw !== '20'): ?><input type="hidden" name="inv_per" value="<?php echo e($invPerRaw); ?>"><?php endif; ?>
                        <input type="search" class="inv-search" name="inv_q" id="invSlipQ" value="<?php echo e($invQ); ?>" placeholder="<?php echo e($isEn ? 'Search name, number, phone' : 'بحث بالاسم أو الرقم أو الهاتف'); ?>" autocomplete="off" style="margin:0;width:100%;flex:1">
                    </form>
                </div>
                <div class="inv-table-wrap">
                    <table class="inv-ledger" id="invLedger">
                        <thead>
                            <tr>
                                <th><?php echo e($isEn ? 'Number' : 'الرقم'); ?></th>
                                <th><?php echo e($isEn ? 'Subscriber' : 'المشترك'); ?></th>
                                <th class="inv-col-phone"><?php echo e($isEn ? 'Phone' : 'الهاتف'); ?></th>
                                <th><?php echo e($isEn ? 'Amount' : 'المبلغ'); ?></th>
                                <th><?php echo e($isEn ? 'Date' : 'التاريخ'); ?></th>
                                <th class="inv-col-who"><?php echo e($isEn ? 'By' : 'بواسطة'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="invSlipBody">
                        <?php if (!$issuedN): ?>
                            <tr class="inv-empty-row"><td class="inv-empty-cell" colspan="7"><?php echo e($isEn ? 'No invoices yet' : 'ماكو فواتير صادرة بعد'); ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($issued as $slipRow):
                            if (!is_array($slipRow)) continue;
                            $tok = isset($slipRow['token']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string) $slipRow['token'])) : '';
                            if ($tok === '') continue;
                            $slipNo = isset($slipRow['number']) ? (string) $slipRow['number'] : '';
                            $slipName = isset($slipRow['name']) ? (string) $slipRow['name'] : '';
                            $slipAmt = isset($slipRow['amount']) ? trim((string) $slipRow['amount']) : '';
                            $slipWhen = isset($slipRow['created_at']) ? (string) $slipRow['created_at'] : '';
                            $slipAgent = isset($slipRow['issuer_name']) ? trim((string) $slipRow['issuer_name']) : '';
                            $slipPhone = isset($slipRow['phone']) ? trim((string) $slipRow['phone']) : '';
                            $slipUser = isset($slipRow['username']) ? trim((string) $slipRow['username']) : '';
                            if (($slipPhone === '' || $slipPhone === '0' || $slipPhone === '—')
                                && $slipUser !== ''
                                && function_exists('invoice_slip_resolve_phone')
                            ) {
                                $slipPhone = invoice_slip_resolve_phone($tenantId, $slipUser, '');
                            }
                            if (($slipPhone === '' || $slipPhone === '0') && !empty($tok) && function_exists('invoice_slip_load')) {
                                $slipFull = invoice_slip_load($tok);
                                if (is_array($slipFull)) {
                                    $pFull = isset($slipFull['phone']) ? trim((string) $slipFull['phone']) : '';
                                    if ($pFull === '' && !empty($slipFull['username'])) {
                                        $pFull = invoice_slip_resolve_phone(
                                            $tenantId,
                                            (string) $slipFull['username'],
                                            ''
                                        );
                                    }
                                    if ($pFull !== '' && $pFull !== '0') {
                                        $slipPhone = $pFull;
                                    }
                                }
                            }
                            $hay = strtolower($slipNo . ' ' . $slipName . ' ' . $slipPhone . ' ' . $slipAmt . ' ' . $slipWhen . ' ' . $slipAgent);
                            ?>
                            <tr data-tok="<?php echo e($tok); ?>" data-no="<?php echo e($slipNo !== '' ? $slipNo : $tok); ?>" data-q="<?php echo e($hay); ?>">
                                <td class="num ltr"><?php echo e($slipNo !== '' ? $slipNo : $tok); ?></td>
                                <td><?php echo e($slipName !== '' ? $slipName : '—'); ?></td>
                                <td class="inv-col-phone ltr muted"><?php echo e($slipPhone !== '' ? $slipPhone : '—'); ?></td>
                                <td class="amt"><?php echo e($slipAmt !== '' ? $slipAmt : '—'); ?></td>
                                <td class="muted ltr"><?php echo e($slipWhen !== '' ? $slipWhen : '—'); ?></td>
                                <td class="inv-col-who muted"><?php echo e($slipAgent !== '' ? $slipAgent : '—'); ?></td>
                                <td>
                                    <div class="inv-mini-acts">
                                        <button type="button" data-act="view"><?php echo e($isEn ? 'View' : 'عرض'); ?></button>
                                        <button type="button" data-act="print"><?php echo e($isEn ? 'Print' : 'طباعة'); ?></button>
                                        <?php if ($invWaOn): ?>
                                        <button type="button" class="is-main" data-act="send"><?php echo e($isEn ? 'Send' : 'إرسال'); ?></button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($issuedTotal > 0 && ($invPages > 1 || !$invShowAll || $invPerRaw !== '20')): ?>
                <div class="inv-pager" id="invPager">
                    <div class="inv-pager-sizes">
                        <?php foreach ($invAllowed as $invChoice): ?>
                        <a class="pager-n<?php echo (!$invShowAll && (int) $invPerRaw === (int) $invChoice) ? ' is-on' : ''; ?>" href="settings.php?tab=invoices&amp;inv_per=<?php echo (int) $invChoice; ?><?php echo $invQ !== '' ? '&amp;inv_q=' . rawurlencode($invQ) : ''; ?>"><?php echo (int) $invChoice; ?></a>
                        <?php endforeach; ?>
                        <?php if (!$invShowAll): ?>
                        <a class="pager-n" href="settings.php?tab=invoices&amp;inv_per=all<?php echo $invQ !== '' ? '&amp;inv_q=' . rawurlencode($invQ) : ''; ?>"><?php echo e($isEn ? 'Show all' : t('show_all')); ?></a>
                        <?php else: ?>
                        <a class="pager-n is-on" href="settings.php?tab=invoices&amp;inv_per=20<?php echo $invQ !== '' ? '&amp;inv_q=' . rawurlencode($invQ) : ''; ?>">20</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($invPages > 1 && !$invShowAll):
                        $invPrev = max(1, $invPage - 1);
                        $invNext = min($invPages, $invPage + 1);
                        $invBase = 'settings.php?' . $invKeepStr;
                        ?>
                    <div class="inv-pager-pages">
                        <a class="pager-n pager-arrow<?php echo $invPage <= 1 ? ' is-off' : ''; ?>" href="<?php echo e($invBase . '&inv_page=1'); ?>">&laquo;</a>
                        <a class="pager-n pager-arrow<?php echo $invPage <= 1 ? ' is-off' : ''; ?>" href="<?php echo e($invBase . '&inv_page=' . $invPrev); ?>">&lsaquo;</a>
                        <?php
                        $iw = 3;
                        $iStart = max(1, $invPage - 1);
                        $iEnd = min($invPages, $iStart + $iw - 1);
                        if ($iEnd - $iStart + 1 < $iw) {
                            $iStart = max(1, $iEnd - $iw + 1);
                        }
                        for ($ip = $iStart; $ip <= $iEnd; $ip++):
                        ?>
                        <a class="pager-n<?php echo $ip === $invPage ? ' is-on' : ''; ?>" href="<?php echo e($invBase . '&inv_page=' . $ip); ?>"><?php echo (int) $ip; ?></a>
                        <?php endfor; ?>
                        <a class="pager-n pager-arrow<?php echo $invPage >= $invPages ? ' is-off' : ''; ?>" href="<?php echo e($invBase . '&inv_page=' . $invNext); ?>">&rsaquo;</a>
                        <a class="pager-n pager-arrow<?php echo $invPage >= $invPages ? ' is-off' : ''; ?>" href="<?php echo e($invBase . '&inv_page=' . $invPages); ?>">&raquo;</a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="inv-sheet" id="invSheet" hidden>
                <div class="inv-sheet-card" role="dialog" aria-modal="true">
                    <div class="inv-sheet-bar">
                        <strong id="invSheetTitle"><?php echo e($isEn ? 'Invoice' : 'فاتورة'); ?></strong>
                        <span class="grow"></span>
                        <button type="button" class="inv-x" id="invSheetClose" aria-label="<?php echo e($isEn ? 'Close' : 'إغلاق'); ?>">×</button>
                        <div class="inv-sheet-acts">
                        <a class="inv-mini-acts" id="invSlipOpen" href="slip.php" target="_blank"><span style="display:inline-flex;align-items:center;height:32px;padding:0 12px;border-radius:999px;border:1px solid #e6ebf2;font-weight:800;font-size:12px;color:#0f172a;text-decoration:none"><?php echo e($isEn ? 'Open' : 'فتح'); ?></span></a>
                        <button type="button" id="invSlipPrint"><?php echo e($isEn ? 'Print' : 'طباعة'); ?></button>
                        <?php if ($invWaOn): ?>
                        <form method="post" action="settings.php?tab=invoices" id="invSlipSend">
                            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="section" value="invoice_send">
                            <input type="hidden" name="slip_token" id="invSlipToken" value="">
                            <button class="is-main" type="submit"><?php echo e($isEn ? 'Send' : 'إرسال'); ?></button>
                        </form>
                        <?php else: ?>
                        <input type="hidden" id="invSlipToken" value="">
                        <?php endif; ?>
                        </div>
                    </div>
                    <iframe id="invSlipFrame" title="<?php echo e($isEn ? 'Invoice preview' : 'استعراض الفاتورة'); ?>"></iframe>
                </div>
            </div>
            <script>
            (function () {
              var body = document.getElementById('invSlipBody');
              var frame = document.getElementById('invSlipFrame');
              var sheet = document.getElementById('invSheet');
              var q = document.getElementById('invSlipQ');
              var openA = document.getElementById('invSlipOpen');
              var printB = document.getElementById('invSlipPrint');
              var token = document.getElementById('invSlipToken');
              var title = document.getElementById('invSheetTitle');
              var count = document.getElementById('invSlipCount');
              if (!body || !frame || !sheet) return;
              var current = '';
              function rows() { return body.querySelectorAll('tr[data-tok]'); }
              function openSheet(tok, no) {
                if (sheet.parentNode !== document.body) document.body.appendChild(sheet);
                current = tok || '';
                var list = rows();
                for (var i = 0; i < list.length; i++) {
                  list[i].classList.toggle('is-on', list[i].getAttribute('data-tok') === current);
                }
                if (!current) return;
                frame.src = 'slip.php?k=' + encodeURIComponent(current);
                if (openA) openA.href = 'slip.php?k=' + encodeURIComponent(current);
                if (token) token.value = current;
                if (title) title.textContent = no || current;
                sheet.hidden = false;
              }
              function closeSheet() { sheet.hidden = true; }
              body.addEventListener('click', function (ev) {
                var btn = ev.target && ev.target.closest ? ev.target.closest('[data-act]') : null;
                var row = ev.target && ev.target.closest ? ev.target.closest('tr[data-tok]') : null;
                if (!row) return;
                var tok = row.getAttribute('data-tok') || '';
                var no = row.getAttribute('data-no') || tok;
                if (btn) {
                  ev.stopPropagation();
                  var act = btn.getAttribute('data-act');
                  if (act === 'print') {
                    openSheet(tok, no);
                    setTimeout(function () {
                      try { if (frame.contentWindow) frame.contentWindow.print(); } catch (e) {}
                    }, 500);
                    return;
                  }
                  if (act === 'send') {
                    if (token) token.value = tok;
                    var form = document.getElementById('invSlipSend');
                    if (form) form.submit();
                    return;
                  }
                }
                openSheet(tok, no);
              });
              if (q) {
                q.addEventListener('keydown', function (ev) {
                  if (ev.key === 'Enter') {
                    ev.preventDefault();
                    if (q.form) q.form.submit();
                  }
                });
              }
              if (printB) {
                printB.addEventListener('click', function () {
                  if (!current) return;
                  try { if (frame.contentWindow) frame.contentWindow.print(); } catch (e) {}
                });
              }
              var closeB = document.getElementById('invSheetClose');
              if (closeB) closeB.addEventListener('click', closeSheet);
              sheet.addEventListener('click', function (ev) { if (ev.target === sheet) closeSheet(); });
              document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeSheet(); });
            })();
            </script>
            <div class="inv-tpl-panel" id="invTplPanel">
                <div class="inv-gbar">
                    <div>
                        <h2><?php echo e($payload['ui']['templates']); ?></h2>
                        <p><?php echo e($payload['ui']['pick']); ?></p>
                    </div>
                    <div class="inv-gbar-acts">
                        <button class="btn ghost sm" type="button" id="invBackInvoices"><?php echo e($payload['ui']['backInvoices']); ?></button>
                        <button class="btn sm" type="button" id="invNew"><?php echo e($payload['ui']['new']); ?></button>
                    </div>
                </div>
                <div class="inv-cards" id="invCards">
                    <?php foreach ($lib['templates'] as $tplCard): if (!is_array($tplCard)) continue; ?>
                    <div class="inv-card" role="button" tabindex="0" data-tpl="<?php echo e(isset($tplCard['id']) ? $tplCard['id'] : ''); ?>">
                        <strong><?php echo e(isset($tplCard['name']) ? $tplCard['name'] : 'قالب'); ?></strong>
                        <em><?php echo e(isset($tplCard['paper']) ? $tplCard['paper'] : ''); ?></em>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <form method="post" action="settings.php?tab=invoices" enctype="multipart/form-data" id="invDesignForm" class="inv-desk" hidden>
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="section" value="invoice_design">
            <input type="hidden" name="template_id" id="fId" value="">
            <input type="hidden" name="template_act" id="fAct" value="save">
            <input type="hidden" name="paper" id="fPaper" value="">
            <input type="hidden" name="orient" id="fOrient" value="">
            <input type="hidden" name="margin_top" id="fMargin" value="3">
            <input type="hidden" name="sheet_h" id="fSheet" value="0">
            <input type="hidden" name="sheet_w" id="fSheetW" value="0">
            <input type="hidden" name="footer_x" id="fFootX" value="-1">
            <input type="hidden" name="footer_y" id="fFootY" value="-1">
            <input type="hidden" name="footer_align" id="fFootAlign" value="center">
            <input type="hidden" name="frame" id="fFrame" value="">
            <input type="hidden" name="frame_json" id="fFrameBox" value="">
            <input type="hidden" name="company_name" id="fCompany" value="">
            <input type="hidden" name="phone" id="fPhone" value="">
            <input type="hidden" name="address" id="fAddress" value="">
            <input type="hidden" name="blocks_json" id="fBlocks" value="">
            <input type="file" name="logo" id="invLogo" accept="image/*" hidden>
            <div class="inv-body">
            <div class="inv-prop" id="invProp" hidden>
                <label class="inv-field inv-for-text" title="<?php echo e($payload['ui']['text']); ?>"><span id="pTextLab"><?php echo e($payload['ui']['text']); ?></span><input id="pText" type="text" placeholder="<?php echo e($payload['ui']['text']); ?>"></label>
                <label class="inv-field inv-for-var" title="<?php echo e($isEn ? 'Variable' : 'المتغير'); ?>"><span><?php echo e($isEn ? 'Variable' : 'المتغير'); ?></span><select id="pBind"></select></label>
                <div class="inv-seg inv-for-frame" id="pFrameStyle" hidden>
                    <button type="button" data-v="line" data-ico="fline" title="<?php echo e($isEn ? 'Line' : 'خط'); ?>"></button>
                    <button type="button" data-v="double" data-ico="fdouble" title="<?php echo e($isEn ? 'Double' : 'مزدوج'); ?>"></button>
                    <button type="button" data-v="dashed" data-ico="fdash" title="<?php echo e($isEn ? 'Dashed' : 'متقطع'); ?>"></button>
                    <button type="button" data-v="round" data-ico="fround" title="<?php echo e($isEn ? 'Round' : 'دائري'); ?>"></button>
                    <button type="button" data-v="none" data-ico="fnone" title="<?php echo e($isEn ? 'None' : 'بدون'); ?>"></button>
                </div>
                <label class="inv-field inv-for-frame" hidden title="<?php echo e($payload['ui']['color']); ?>"><span><?php echo e($payload['ui']['color']); ?></span><input id="pFrameColor" type="color"></label>
                <label class="inv-field inv-for-frame" hidden title="<?php echo e($isEn ? 'Weight' : 'السماكة'); ?>"><span><?php echo e($isEn ? 'Weight' : 'السماكة'); ?></span><input id="pFrameThick" type="number" min="0.2" max="3" step="0.1"></label>
                <label class="inv-field inv-for-frame" hidden title="<?php echo e($isEn ? 'Corners' : 'الزوايا'); ?>"><span><?php echo e($isEn ? 'Corners' : 'الزوايا'); ?></span><input id="pFrameRadius" type="number" min="0" max="20" step="0.5"></label>
                <div class="inv-seg inv-for-text" id="pZoom">
                    <button type="button" id="pSizeMinus" data-ico="zoomout" title="<?php echo e($isEn ? 'Smaller' : 'تصغير'); ?>"></button>
                    <input id="pSizeN" type="number" min="6" max="72" title="<?php echo e($payload['ui']['size']); ?>">
                    <button type="button" id="pSizePlus" data-ico="zoomin" title="<?php echo e($isEn ? 'Larger' : 'تكبير'); ?>"></button>
                </div>
                <label class="inv-field inv-for-text" title="<?php echo e($payload['ui']['color']); ?>"><span><?php echo e($payload['ui']['color']); ?></span><input id="pColor" type="color" value="#111827"></label>
                <input id="pSize" type="range" min="6" max="72" step="1" hidden>
                <input id="pHug" type="checkbox" hidden>
                <div class="inv-seg inv-for-text" id="pWeight">
                    <button type="button" data-v="normal" data-ico="regular" title="<?php echo e($isEn ? 'Regular' : 'عادي'); ?>"></button>
                    <button type="button" data-v="bold" data-ico="bold" title="<?php echo e($isEn ? 'Bold' : 'بولد'); ?>"></button>
                </div>
                <div class="inv-seg inv-for-text" id="pStyle">
                    <button type="button" data-v="italic" data-ico="italic" title="<?php echo e($isEn ? 'Italic' : 'مائل'); ?>"></button>
                    <button type="button" data-v="under" data-ico="under" title="<?php echo e($isEn ? 'Underline' : 'تحت الخط'); ?>"></button>
                </div>
                <div class="inv-seg" id="pPack">
                    <button type="button" data-pack="right" title="رص من اليمين">ي</button>
                    <button type="button" data-pack="left" title="رص من اليسار">س</button>
                </div>
                <div class="inv-seg inv-for-text" id="pAlign">
                    <button type="button" data-v="right" data-ico="alignr" title="<?php echo e($payload['ui']['right']); ?>"></button>
                    <button type="button" data-v="center" data-ico="alignc" title="<?php echo e($payload['ui']['center']); ?>"></button>
                    <button type="button" data-v="left" data-ico="alignl" title="<?php echo e($payload['ui']['left']); ?>"></button>
                </div>
                <div class="inv-seg inv-for-text" id="pDir">
                    <button type="button" class="inv-lang" data-v="rtl" title="عربي">AR</button>
                    <button type="button" class="inv-lang" data-v="ltr" title="إنجليزي">EN</button>
                </div>
                <div class="inv-seg inv-for-var" id="pStack">
                    <button type="button" data-v="row" data-ico="alignc" title="بجانب بعض"></button>
                    <button type="button" data-v="stack" data-ico="stack" title="واحد تحت واحد"></button>
                </div>
                <button class="btn ghost sm inv-for-table" type="button" id="pTableEdit" hidden title="خلايا الجدول">جدول</button>
                <div class="inv-tbar inv-for-table" id="pTableBar" hidden>
                    <button type="button" id="tRowAdd" title="صف جديد">صف+</button>
                    <button type="button" id="tRowDel" title="حذف صف">صف-</button>
                    <button type="button" id="tColAdd" title="عمود جديد">عمود+</button>
                    <button type="button" id="tColDel" title="حذف عمود">عمود-</button>
                    <button type="button" id="tMerge" title="دمج الخلية مع اللي جنبها">دمج</button>
                    <button type="button" id="tPack" title="ملاءمة العرض على المحتوى">ملاءمة</button>
                    <label class="inv-rowh" title="ارتفاع الصف بالمليمتر"><span>ارتفاع</span><input id="pRowH" type="number" min="4" max="30" step="0.5" value="7"><span>مم</span></label>
                </div>
                <label class="inv-field inv-for-pos"><span class="inv-keep">X</span><input id="pX" type="number" step="0.5"></label>
                <label class="inv-field inv-for-pos"><span class="inv-keep">Y</span><input id="pY" type="number" step="0.5"></label>
                <span id="pFootNote" hidden>الفوتر ثابت</span>
                <span id="pCount" hidden></span>
                <button class="btn danger sm" type="button" id="pDel" data-ico="trash" title="<?php echo e($payload['ui']['delItem']); ?>"></button>
                <button class="btn ghost sm inv-for-logo" type="button" id="pLogo" hidden><?php echo e($payload['ui']['swapLogo']); ?></button>
            </div>
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
                <button class="btn ghost sm" type="button" id="invBack"><?php echo e($payload['ui']['back']); ?></button>
                <input class="inv-name" name="template_name" id="invName" value="">
                <span class="inv-len" id="invLen"></span>
                <span class="inv-bottom-gap"></span>
                <button class="btn ghost sm" type="button" id="invPrintOpen2"><?php echo e($payload['ui']['printers']); ?></button>
                <button class="btn ghost sm" type="button" id="invTestPrint"><?php echo e($payload['ui']['print']); ?></button>
                <button class="btn ghost sm" type="button" id="invPdf"><?php echo e($payload['ui']['pdf']); ?></button>
                <button class="btn danger sm" type="button" id="invDelTpl"><?php echo e($isEn ? 'Delete' : 'حذف'); ?></button>
                <button class="btn sm" type="button" id="invEditBtn"><?php echo e($payload['ui']['edit']); ?></button>
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
                    <em><?php echo !empty($r['online']) ? ($isEn ? 'Portal can reach it' : 'البوابة توصلها') : ($isEn ? 'Prints from this computer' : 'الطباعة من هذا الجهاز'); ?></em>
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
                    <span class="inv-def"><?php echo e($isEn ? 'Default printer' : 'طابعة افتراضية'); ?></span>
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
      var board = document.getElementById('invBoard');
      var tplPanel = document.getElementById('invTplPanel');
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
      var pickedIds = [];
      var openTool = '';
      var drag = null;
      var ICO = {
        add: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg>',
        paper: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="6" y="3" width="12" height="18" rx="1.5"/></svg>',
        frame: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>',
        company: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3"/><path d="M6 19c1.2-3 3.2-4.5 6-4.5s4.8 1.5 6 4.5"/></svg>',
        align: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v18M3 12h18"/></svg>',
        zoomout: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10" cy="10" r="6"/><path d="M14.5 14.5L20 20M7 10h6"/></svg>',
        zoomin: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10" cy="10" r="6"/><path d="M14.5 14.5L20 20M10 7v6M7 10h6"/></svg>',
        regular: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.4"><path d="M7 18L12 6l5 12M9 14h6"/></svg>',
        bold: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M7 18L12 6l5 12M9 14h6"/></svg>',
        italic: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 5H9M16 19H9M14 5l-4 14"/></svg>',
        under: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 4v7a5 5 0 0 0 10 0V4M6 20h12"/></svg>',
        alignr: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M8 12h12M6 18h14"/></svg>',
        alignc: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M7 12h10M5 18h14"/></svg>',
        alignl: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h12M4 18h14"/></svg>',
        dirrtl: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 6H8a3 3 0 0 0 0 6h4M12 6v12M19 15l-3 3-3-3"/></svg>',
        dirltr: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 6h7a3 3 0 0 1 0 6h-4M12 6v12M5 15l3 3 3-3"/></svg>',
        stack: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 7h14M5 12h14M5 17h14"/></svg>',
        fline: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="5" width="14" height="14"/></svg>',
        fdouble: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.4"><rect x="4" y="4" width="16" height="16"/><rect x="7" y="7" width="10" height="10"/></svg>',
        fdash: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-dasharray="3 2"><rect x="5" y="5" width="14" height="14"/></svg>',
        fround: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="5" width="14" height="14" rx="4"/></svg>',
        fnone: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="5" width="14" height="14" opacity=".35"/><path d="M7 17L17 7"/></svg>',
        trash: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 7h14M9 7V5h6v2M8 7l1 12h6l1-12"/></svg>'
      };
      var railBtns = rail ? rail.querySelectorAll('button') : [];
      for (var ri = 0; ri < railBtns.length; ri++) railBtns[ri].innerHTML = ICO[railBtns[ri].getAttribute('data-tool')] || '';
      var icoNodes = document.querySelectorAll('[data-ico]');
      for (var ii = 0; ii < icoNodes.length; ii++) {
        var icoName = icoNodes[ii].getAttribute('data-ico');
        if (ICO[icoName]) icoNodes[ii].innerHTML = ICO[icoName];
      }

      function ymd(d) {
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
      }
      function sample(kind, design) {
        var d = design || {};
        var today = new Date();
        var end = new Date(today.getTime() + 30 * 86400000);
        var map = {
          company: d.company_name || 'اسم الشركة',
          phone: d.phone || '07xx xxx xxxx',
          address: d.address || 'عنوان الشركة',
          name: 'أحمد محمد',
          package: 'NB MAX-MSL',
          start: ymd(today),
          end: ymd(end),
          amount: '35,000',
          username: 'user01',
          mobile: '07700000000',
          number: 'AS_000128',
          date: ymd(today),
          time: '12:30',
          custom: 'نص'
        };
        return map[kind] || 'نص';
      }
      var VAR_LABEL = {
        name: 'اسم المشترك', package: 'نوع الاشتراك', start: 'تاريخ التفعيل', end: 'تاريخ الانتهاء',
        amount: 'المبلغ', company: 'الشركة', phone: 'هاتف الشركة', address: 'العنوان',
        username: 'اليوزر', mobile: 'هاتف المشترك', number: 'رقم الفاتورة', date: 'التاريخ', time: 'الوقت'
      };
      function isVarKind(kind) { return !!VAR_LABEL[kind]; }
      function shownValue(b, design) {
        if (!isVarKind(b.kind)) return b.text || 'نص';
        if (b.label) return sample(b.kind, design);
        if (b.text) return b.text;
        return sample(b.kind, design);
      }
      function fillText(el, b, design) {
        var old = el.querySelectorAll('.inv-val, .inv-pair');
        for (var i = 0; i < old.length; i++) if (old[i].parentNode) old[i].parentNode.removeChild(old[i]);
        var paired = isVarKind(b.kind) && String(b.label || '') !== '';
        var handle = el.querySelector('.inv-resize');
        var node;
        if (paired) {
          node = document.createElement('span');
          node.className = 'inv-pair inv-val' + (b.stack ? ' is-stack' : '');
          if (b.stack) el.classList.add('is-stack');
          else el.classList.remove('is-stack');
          var c = document.createElement('span');
          c.className = 'inv-const';
          c.textContent = b.label;
          var v = document.createElement('span');
          v.className = 'inv-var';
          v.textContent = sample(b.kind, design);
          if (b.kind === 'phone' || b.kind === 'amount' || b.kind === 'start' || b.kind === 'end' || b.kind === 'package' || b.kind === 'number' || b.kind === 'date' || b.kind === 'time' || b.kind === 'username' || b.kind === 'mobile') v.style.direction = 'ltr';
          node.appendChild(c);
          node.appendChild(v);
          if (!(b.w > 0)) {
            el.classList.add('is-hug');
            el.style.width = '';
          }
        } else {
          node = document.createElement('span');
          node.className = 'inv-val';
          node.textContent = shownValue(b, design);
        }
        if (handle) el.insertBefore(node, handle);
        else el.appendChild(node);
      }
      function paintType(el, b) {
        el.style.fontWeight = b.weight === 'normal' ? '400' : '500';
        el.style.fontStyle = b.italic ? 'italic' : 'normal';
        el.style.textDecoration = b.under ? 'underline' : 'none';
        el.style.textUnderlineOffset = b.under ? '2px' : '';
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
          label: b.label ? String(b.label) : '',
          weight: b.weight === 'normal' ? 'normal' : 'bold',
          italic: b.italic ? 1 : 0,
          under: b.under ? 1 : 0,
          stack: b.stack ? 1 : 0,
          dash: b.dash ? 1 : 0,
          rows: (b.rows && b.rows.length) ? b.rows : [],
          rowH: (parseFloat(b.rowH) > 0 ? Math.max(4, Math.min(30, parseFloat(b.rowH))) : 7),
          cols: (b.cols && b.cols.length) ? b.cols.slice() : [],
          pack: b.pack === 0 ? 0 : 1,
          on: 1
        };
      }
      function cloneDesign(d) {
        var o = {
          id: d.id, name: d.name || '', paper: d.paper || 'strip80',
          orient: d.orient === 'landscape' ? 'landscape' : 'portrait',
          frame: (d.frame_box && d.frame_box.style) || d.frame || 'none',
          frame_box: frameCopy(d),
          company_name: d.company_name || '',
          phone: d.phone || '', address: d.address || '', logo: d.logo || '',
          margin_top: (isFinite(parseFloat(d.margin_top)) ? Math.max(0, Math.min(12, parseFloat(d.margin_top))) : 3),
          sheet_h: (parseFloat(d.sheet_h) > 0 ? Math.max(0, Math.min(450, parseFloat(d.sheet_h))) : 0),
          sheet_w: (parseFloat(d.sheet_w) > 0 ? Math.max(0, Math.min(320, parseFloat(d.sheet_w))) : 0),
          footer_x: (isFinite(parseFloat(d.footer_x)) ? parseFloat(d.footer_x) : -1),
          footer_y: (isFinite(parseFloat(d.footer_y)) ? parseFloat(d.footer_y) : -1),
          footer_align: (d.footer_align === 'left' || d.footer_align === 'right') ? d.footer_align : 'center',
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
          var bh = (b.kind === 'logo' || b.kind === 'qr') ? (parseFloat(b.w) > 0 ? parseFloat(b.w) : 18) : (b.kind === 'rule' ? 3 : (b.kind === 'table' ? Math.max(8, ((b.rows && b.rows.length) || 1) * (parseFloat(b.rowH) > 0 ? parseFloat(b.rowH) : 7)) : (b.stack ? (((parseFloat(b.size) || 13) * 25.4 / 96) * 2 + 3) : (((parseFloat(b.size) || 13) * 25.4 / 96) + 1))));
          var bottom = (parseFloat(b.y) || 0) + bh;
          if (bottom > h) h = bottom;
        }
        var fy = parseFloat(design.footer_y);
        if (isFinite(fy) && fy >= 0 && fy + 6 > h) h = fy + 6;
        return Math.max(18, Math.ceil(h + 8));
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
        var customW = parseFloat(design.sheet_w) || 0;
        if (customW >= 30) w = customW;
        if (!fixed) {
          var custom = parseFloat(design.sheet_h) || 0;
          if (custom >= 30) h = custom;
          else h = contentH(design);
        }
        return {w: w, h: h, fixed: fixed};
      }
      function blockById(id) {
        var blocks = current && current.blocks ? current.blocks : [];
        for (var i = 0; i < blocks.length; i++) if (blocks[i].id === id) return blocks[i];
        return null;
      }
      function frameCopy(d) {
        var src = (d && d.frame_box) ? d.frame_box : {};
        var style = src.style || (d && d.frame) || 'none';
        if (['none', 'line', 'double', 'dashed', 'round'].indexOf(style) < 0) style = 'none';
        var color = /^#[0-9a-fA-F]{6}$/.test(src.color || '') ? src.color : '#0f172a';
        var thick = parseFloat(src.thick);
        if (!isFinite(thick) || thick <= 0) thick = 0.45;
        thick = Math.max(0.15, Math.min(3, thick));
        var radius = parseFloat(src.radius);
        if (!isFinite(radius) || radius < 0) radius = (style === 'round' ? 3 : 0);
        radius = Math.min(20, radius);
        function num(v) { var n = parseFloat(v); return isFinite(n) && n > 0 ? Math.min(450, n) : 0; }
        return { style: style, color: color, thick: thick, radius: radius, x: num(src.x), y: num(src.y), w: num(src.w), h: num(src.h) };
      }
      function frameGeom(design) {
        var box = boxMm(design || current);
        var f = frameCopy(design || current);
        var w = f.w >= 8 ? f.w : Math.max(10, box.w - f.x * 2);
        var h = f.h >= 8 ? f.h : Math.max(10, box.h - f.y * 2);
        f.w = w;
        f.h = h;
        return f;
      }
      function paintFrame(el, design) {
        var f = frameCopy(design);
        if (f.style === 'none') return;
        var g = frameGeom(design);
        var fr = document.createElement('div');
        fr.className = 'inv-frame frame-' + g.style;
        fr.style.left = g.x + 'mm';
        fr.style.top = g.y + 'mm';
        fr.style.width = g.w + 'mm';
        fr.style.height = g.h + 'mm';
        var thick = g.thick;
        if (g.style === 'double' && thick < 0.8) thick = 0.8;
        fr.style.borderStyle = g.style === 'dashed' ? 'dashed' : (g.style === 'double' ? 'double' : 'solid');
        fr.style.borderWidth = thick + 'mm';
        fr.style.borderColor = g.color;
        fr.style.borderRadius = g.radius + 'mm';
        var edges = ['n', 'e', 's', 'w'];
        for (var i = 0; i < edges.length; i++) {
          var ed = document.createElement('i');
          ed.className = 'inv-edge';
          ed.setAttribute('data-h', edges[i]);
          fr.appendChild(ed);
        }
        var hands = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
        for (var k = 0; k < hands.length; k++) {
          var h = document.createElement('i');
          h.className = 'inv-fr-h';
          h.setAttribute('data-h', hands[k]);
          fr.appendChild(h);
        }
        el.appendChild(fr);
      }
      function tableRowH(b) {
        var h = parseFloat(b && b.rowH);
        if (!(h > 0)) h = 7;
        return Math.max(4, Math.min(28, h));
      }
      function normalizeTable(b) {
        if (!b) return;
        if (!b.rows || !b.rows.length) {
          b.rows = [[{ t: '', k: '' }, { t: '', k: '' }]];
        }
        var n = 0;
        for (var r = 0; r < b.rows.length; r++) {
          if (!b.rows[r]) b.rows[r] = [];
          var clean = [];
          for (var c = 0; c < b.rows[r].length; c++) {
            var cell = b.rows[r][c] || {};
            clean.push({ t: cell.t ? String(cell.t) : '', k: cell.k ? String(cell.k) : '' });
          }
          if (!clean.length) clean.push({ t: '', k: '' });
          b.rows[r] = clean;
          if (clean.length > n) n = clean.length;
        }
        for (var r2 = 0; r2 < b.rows.length; r2++) {
          while (b.rows[r2].length < n) b.rows[r2].push({ t: '', k: '' });
          if (b.rows[r2].length > n) b.rows[r2] = b.rows[r2].slice(0, n);
        }
        var sumCols = 0;
        if (b.cols && b.cols.length === n) {
          for (var s = 0; s < b.cols.length; s++) sumCols += parseFloat(b.cols[s]) || 0;
        }
        /* قيم بكسل قديمة أو عدد أعمدة غلط → نسب مئوية نظيفة */
        if (!(b.cols && b.cols.length === n) || sumCols > 200 || sumCols <= 0) {
          b.cols = [];
          for (var i = 0; i < n; i++) b.cols.push(n === 2 ? (i === 0 ? 42 : 58) : (100 / n));
        }
      }
      function clampTableBox(b) {
        if (!b || b.kind !== 'table' || !current) return;
        var box = boxMm(current);
        if (!(b.x >= 0) || !isFinite(b.x)) b.x = 4;
        b.x = Math.round(Math.max(0, Math.min(b.x, box.w - 20)) * 10) / 10;
        var maxW = Math.max(20, box.w - b.x - 2);
        if (!(b.w > 0)) b.w = Math.max(40, box.w - 8);
        b.w = Math.round(Math.max(20, Math.min(b.w, maxW)) * 10) / 10;
        b.rowH = tableRowH(b);
        normalizeTable(b);
      }
      function addResizeHandles(el, full) {
        var old = el.querySelectorAll('.inv-resize');
        for (var i = 0; i < old.length; i++) {
          if (old[i].parentNode) old[i].parentNode.removeChild(old[i]);
        }
        var spots = full ? ['nw', 'ne', 'se', 'sw'] : ['se', 'e'];
        for (var s = 0; s < spots.length; s++) {
          var h = document.createElement('i');
          h.className = 'inv-resize';
          h.setAttribute('data-h', spots[s]);
          h.title = 'تكبير / تصغير';
          el.appendChild(h);
        }
      }
      function tableColCount(b) {
        normalizeTable(b);
        return b.rows[0].length;
      }
      function ensureTableCols(b) {
        normalizeTable(b);
        return b.cols;
      }
      function mergeTableCells(b, r, c) {
        /* الدمج مبسّط: دمج نص الخليتين وحذف التالية */
        if (!b || !b.rows || !b.rows[r] || !b.rows[r][c] || !b.rows[r][c + 1]) return false;
        var a = b.rows[r][c];
        var nxt = b.rows[r][c + 1];
        if (!a.t && nxt.t) a.t = nxt.t;
        if (!a.k && nxt.k) a.k = nxt.k;
        b.rows[r].splice(c + 1, 1);
        normalizeTable(b);
        b._cell = { r: r, c: c };
        return true;
      }
      function applyTableColsLive(el, b) {
        var table = el && el.querySelector('table');
        if (!table || !b) return;
        normalizeTable(b);
        var cols = b.cols;
        var sum = 0;
        for (var i = 0; i < cols.length; i++) sum += cols[i];
        var nodes = table.querySelectorAll('col');
        for (var c = 0; c < nodes.length && c < cols.length; c++) {
          nodes[c].style.width = ((cols[c] / sum) * 100).toFixed(2) + '%';
        }
        var line = el.querySelector('.inv-col-line');
        if (line && cols.length >= 2) {
          line.style.left = ((cols[1] / sum) * 100) + '%';
        }
      }
      function paintTable(el, b, design) {
        var old = el.querySelector('table');
        if (old && old.parentNode) old.parentNode.removeChild(old);
        var oldLines = el.querySelectorAll('.inv-col-line');
        for (var oi = 0; oi < oldLines.length; oi++) {
          if (oldLines[oi].parentNode) oldLines[oi].parentNode.removeChild(oldLines[oi]);
        }
        normalizeTable(b);
        clampTableBox(b);
        /* عرض ثابت داخل الورقة — بدون قياس تلقائي يتكسر */
        el.style.left = b.x + 'mm';
        el.style.top = b.y + 'mm';
        el.style.width = b.w + 'mm';
        el.style.maxWidth = b.w + 'mm';
        el.style.minWidth = b.w + 'mm';
        var table = document.createElement('table');
        table.className = 'inv-table';
        var rowH = tableRowH(b);
        b.rowH = rowH;
        var cols = b.cols;
        var sum = 0;
        for (var s = 0; s < cols.length; s++) sum += cols[s];
        var cg = document.createElement('colgroup');
        for (var ci = 0; ci < cols.length; ci++) {
          var col = document.createElement('col');
          col.style.width = ((cols[ci] / sum) * 100).toFixed(2) + '%';
          cg.appendChild(col);
        }
        table.appendChild(cg);
        for (var r = 0; r < b.rows.length; r++) {
          var tr = document.createElement('tr');
          tr.style.height = rowH + 'mm';
          for (var c = 0; c < b.rows[r].length; c++) {
            var cell = b.rows[r][c] || {};
            var td = document.createElement('td');
            td.setAttribute('data-r', String(r));
            td.setAttribute('data-c', String(c));
            var key = String(cell.k || '').trim();
            var label = String(cell.t || '').trim();
            if (key) {
              td.className = 'inv-var';
              td.textContent = sample(key, design || current || {});
            } else {
              td.textContent = label || '\u00a0';
            }
            if (b._cell && b._cell.r === r && b._cell.c === c) {
              td.className = (td.className ? td.className + ' ' : '') + 'is-cell';
            }
            tr.appendChild(td);
          }
          table.appendChild(tr);
        }
        var firstHandle = el.querySelector('.inv-resize');
        if (firstHandle) el.insertBefore(table, firstHandle);
        else el.appendChild(table);
        if (mode === 'edit' && cols.length >= 2) {
          var line = document.createElement('i');
          line.className = 'inv-col-line';
          line.setAttribute('data-col', '1');
          line.title = 'اسحب لتغيير عرض العمود';
          line.style.left = ((cols[1] / sum) * 100) + '%';
          el.appendChild(line);
        }
      }
      function blockNode(b, design) {
        var el = document.createElement('div');
        el.className = 'inv-block' + (b.w > 0 ? '' : ' is-hug') + (b.kind === 'address' ? ' is-wrap' : '');
        el.setAttribute('data-id', b.id);
        el.style.left = (parseFloat(b.x) || 0) + 'mm';
        el.style.top = (parseFloat(b.y) || 0) + 'mm';
        if (b.kind === 'rule') {
          el.classList.remove('is-hug');
          el.style.width = (b.w > 0 ? b.w : 40) + 'mm';
          el.style.height = '0';
          el.style.borderTop = (b.dash ? '0.35mm dashed ' : '0.45mm solid ') + (b.color || '#111827');
          el.style.marginTop = '1.4mm';
          addResizeHandles(el, false);
          return el;
        }
        if (b.kind === 'table') {
          el.classList.remove('is-hug');
          el.classList.add('is-table');
          el.style.fontSize = Math.max(2.2, (b.size || 13) * 0.264583) + 'mm';
          paintType(el, b);
          paintTable(el, b, design);
          addResizeHandles(el, true);
          return el;
        }
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
          el.style.fontSize = Math.max(2.2, (b.size || 13) * 0.264583) + 'mm';
          el.style.color = b.color || '#111827';
          el.style.textAlign = b.align || 'right';
          el.classList.toggle('is-center', (b.align || 'right') === 'center');
          el.style.direction = b.dir || 'rtl';
          paintType(el, b);
          fillText(el, b, design);
        }
        addResizeHandles(el, false);
        return el;
      }
      function paint(el, design) {
        var box = boxMm(design);
        el.className = 'inv-paper';
        el.style.width = box.w + 'mm';
        el.style.height = box.h + 'mm';
        el.innerHTML = '';
        paintFrame(el, design, box);
        var blocks = design.blocks || [];
        for (var i = 0; i < blocks.length; i++) el.appendChild(blockNode(blocks[i], design));
        var gv = document.createElement('i');
        gv.className = 'inv-guide inv-guide-v';
        var gh = document.createElement('i');
        gh.className = 'inv-guide inv-guide-h';
        el.appendChild(gv);
        el.appendChild(gh);
        var handle = document.createElement('div');
        handle.className = 'inv-len-handle';
        handle.innerHTML = '<b></b>';
        el.appendChild(handle);
        var wide = document.createElement('div');
        wide.className = 'inv-wid-handle';
        wide.innerHTML = '<b></b>';
        el.appendChild(wide);
        var foot = document.createElement('div');
        foot.className = 'inv-foot';
        foot.textContent = footText();
        foot.style.textAlign = (design.footer_align === 'left' || design.footer_align === 'right') ? design.footer_align : 'center';
        var fx0 = parseFloat(design.footer_x);
        var fy0 = parseFloat(design.footer_y);
        foot.style.bottom = 'auto';
        foot.style.right = 'auto';
        if (!isFinite(fy0) || fy0 < 0) {
          foot.style.top = 'auto';
          foot.style.bottom = '1.2mm';
        } else foot.style.top = fy0 + 'mm';
        if (!isFinite(fx0) || fx0 < 0) {
          foot.style.left = '1mm';
          foot.style.right = '1mm';
          foot.style.width = 'auto';
        } else foot.style.left = fx0 + 'mm';
        el.appendChild(foot);
        return box;
      }
      function footText() {
        var t = DATA.footer ? String(DATA.footer) : '';
        return t || 'البوابة';
      }
      function settleFoot() {
        if (!current || !paper) return null;
        var foot = paper.querySelector('.inv-foot');
        if (!foot) return null;
        var box = boxMm(current);
        var rect = paper.getBoundingClientRect();
        if (!rect.width || !rect.height) return null;
        foot.textContent = footText();
        foot.style.whiteSpace = 'nowrap';
        foot.style.width = 'max-content';
        foot.style.maxWidth = (box.w - 2) + 'mm';
        foot.style.bottom = 'auto';
        foot.style.right = 'auto';
        var footRect = foot.getBoundingClientRect();
        var naturalW = footRect.width / rect.width * box.w;
        var naturalH = Math.max(3.2, footRect.height / rect.height * box.h);
        if (naturalW > box.w - 2) {
          foot.style.whiteSpace = 'normal';
          foot.style.width = (box.w - 2) + 'mm';
          var wrapped = foot.getBoundingClientRect();
          naturalW = box.w - 2;
          naturalH = Math.max(3.2, wrapped.height / rect.height * box.h);
        }
        var x = parseFloat(current.footer_x);
        var y = parseFloat(current.footer_y);
        if (!isFinite(x) || x < 0) x = Math.max(0, (box.w - naturalW) / 2);
        if (!isFinite(y) || y < 0) y = Math.max(0, box.h - naturalH - 1.2);
        if (x + naturalW > box.w - 0.4) x = Math.max(0, box.w - naturalW - 0.4);
        if (y + naturalH > box.h - 0.4) y = Math.max(0, box.h - naturalH - 0.4);
        if (isFinite(parseFloat(current.footer_x)) && current.footer_x >= 0 && Math.abs(current.footer_x - x) > 0.05) current.footer_x = Math.round(x * 10) / 10;
        if (isFinite(parseFloat(current.footer_y)) && current.footer_y >= 0 && Math.abs(current.footer_y - y) > 0.05) current.footer_y = Math.round(y * 10) / 10;
        foot.style.left = x + 'mm';
        foot.style.top = y + 'mm';
        var band = { x: x, y: y, w: naturalW, h: naturalH };
        current._foot = band;
        return band;
      }
      function shoveOneBlock(b) {
        var f = current && current._foot;
        if (!f || !paper || !b) return;
        var rect = paper.getBoundingClientRect();
        var box = boxMm(current);
        if (!rect.width || !rect.height) return;
        var el = qBlock(b.id);
        if (!el) return;
        var r = el.getBoundingClientRect();
        var l = (r.left - rect.left) / rect.width * box.w;
        var t = (r.top - rect.top) / rect.height * box.h;
        var rr = (r.right - rect.left) / rect.width * box.w;
        var bb = (r.bottom - rect.top) / rect.height * box.h;
        var hit = rr > f.x + 0.3 && l < f.x + f.w - 0.3 && bb > f.y + 0.2 && t < f.y + f.h - 0.2;
        if (!hit) return;
        var bh = Math.max(2, bb - t);
        var above = f.y - 0.7 - bh;
        if (above >= 0) b.y = Math.round(above * 10) / 10;
        else b.y = Math.round((f.y + f.h + 0.7) * 10) / 10;
        syncBlock(b);
      }
      function shoveOffFooter() {
        var blocks = current && current.blocks ? current.blocks : [];
        for (var i = 0; i < blocks.length; i++) shoveOneBlock(blocks[i]);
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
        fitInto(fitEl, paper, Math.max(120, stage.clientWidth - 48), Math.max(120, stage.clientHeight - 48), 2.4);
        if (stage) { stage.scrollLeft = 0; stage.scrollTop = 0; }
        if (len) len.textContent = (box.w / 10).toFixed(1) + ' × ' + (box.h / 10).toFixed(1) + ' سم';
        var handleLab = paper.querySelector('.inv-len-handle b');
        if (handleLab) handleLab.textContent = (box.h / 10).toFixed(1) + ' سم';
        var wideLab = paper.querySelector('.inv-wid-handle b');
        if (wideLab) wideLab.textContent = (box.w / 10).toFixed(1) + ' سم';
        clampInside(box);
        var nodes = paper.querySelectorAll('.inv-block');
        for (var pi = 0; pi < nodes.length; pi++) {
          nodes[pi].classList.toggle('is-pick', pickedIds.indexOf(nodes[pi].getAttribute('data-id')) >= 0);
        }
        var fr = paper.querySelector('.inv-frame');
        if (fr) fr.classList.toggle('is-pick', picked === '__frame__');
        settleFoot();
        var footPick = paper.querySelector('.inv-foot');
        if (footPick) footPick.classList.toggle('is-pick', picked === '__foot__');
      }
      function repairDesignLayout(d) {
        if (!d) return;
        var sw = parseFloat(d.sheet_w) || 0;
        /* عرض 15سم على شريط 80مم كان يقص اليسار بالكامل */
        if (sw > 100 && String(d.paper || 'strip80').indexOf('strip') === 0) d.sheet_w = 0;
        var box = boxMm(d);
        var blocks = d.blocks || [];
        var defKeys = ['name', 'package', 'start', 'end', 'amount'];
        for (var i = 0; i < blocks.length; i++) {
          var b = blocks[i];
          if (!b || b.kind !== 'table') continue;
          normalizeTable(b);
          if (b.rows[0] && b.rows[0].length === 2) {
            b.cols = [40, 60];
            for (var ri = 0; ri < b.rows.length; ri++) {
              var row = b.rows[ri];
              if (!row[1]) row[1] = { t: '', k: '' };
              if (!String(row[1].k || '').trim() && ri < defKeys.length) row[1].k = defKeys[ri];
            }
          }
          b.x = 4;
          b.w = Math.max(30, box.w - 8);
          b.rowH = tableRowH(b);
          clampTableBox(b);
        }
      }
      function clampInside(box) {
        if (!current || !paper) return;
        var changed = false;
        var blocks = current.blocks || [];
        for (var i = 0; i < blocks.length; i++) {
          var b = blocks[i];
          if (!b) continue;
          if (b.x < 0) { b.x = 0; changed = true; }
          if (b.y < 0) { b.y = 0; changed = true; }
          if (b.kind === 'table') {
            var before = b.x + ':' + b.w;
            clampTableBox(b);
            if (b.x + ':' + b.w !== before) changed = true;
          }
        }
        if (!changed) return;
        for (var k = 0; k < blocks.length; k++) syncBlock(blocks[k]);
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
        if (b.kind === 'rule') {
          el.classList.remove('is-hug');
          el.style.width = (b.w > 0 ? b.w : 40) + 'mm';
          el.style.borderTop = (b.dash ? '0.35mm dashed ' : '0.45mm solid ') + (b.color || '#111827');
          return;
        }
        if (b.kind === 'table') {
          el.classList.remove('is-hug');
          el.classList.add('is-table');
          el.style.fontSize = Math.max(2.2, (b.size || 13) * 0.264583) + 'mm';
          paintType(el, b);
          paintTable(el, b, current);
          if (!el.querySelector('.inv-resize[data-h="se"]')) addResizeHandles(el, true);
          return;
        }
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
        el.style.fontSize = Math.max(2.2, (b.size || 13) * 0.264583) + 'mm';
        el.style.color = b.color || '#111827';
        el.style.textAlign = b.align || 'right';
        el.style.direction = b.dir || 'rtl';
        paintType(el, b);
        if (b.w > 0) { el.classList.remove('is-hug'); el.style.width = b.w + 'mm'; }
        else { el.classList.add('is-hug'); el.style.width = ''; }
        fillText(el, b, current);
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
      function showLedger() {
        mode = 'ledger';
        if (app) app.classList.remove('is-edit');
        if (gallery) gallery.hidden = false;
        if (form) form.hidden = true;
        if (board) board.classList.remove('is-away');
        if (tplPanel) tplPanel.classList.remove('is-on');
        closePop();
        picked = null;
      }
      function showGallery() {
        mode = 'gallery';
        if (app) app.classList.remove('is-edit');
        if (gallery) gallery.hidden = false;
        if (form) form.hidden = true;
        if (board) board.classList.add('is-away');
        if (tplPanel) tplPanel.classList.add('is-on');
        closePop();
        picked = null;
        if (!cards) return;
        var list = DATA.templates || [];
        var frag = document.createDocumentFragment();
        for (var i = 0; i < list.length; i++) {
          try {
            frag.appendChild(cardEl(list[i], list.length > 1));
          } catch (err) {
            var fallback = document.createElement('div');
            fallback.className = 'inv-card';
            fallback.setAttribute('data-tpl', list[i] && list[i].id ? list[i].id : '');
            var strong = document.createElement('strong');
            strong.textContent = (list[i] && list[i].name) || 'قالب';
            fallback.appendChild(strong);
            frag.appendChild(fallback);
          }
        }
        cards.innerHTML = '';
        cards.appendChild(frag);
        requestAnimationFrame(function () { requestAnimationFrame(function () { try { fitThumbs(); } catch (err) {} }); });
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
        document.getElementById('invBottom').hidden = false;
        document.getElementById('invCancel').hidden = !editing;
        document.getElementById('invSave').hidden = !editing;
        document.getElementById('invEditBtn').hidden = editing;
        document.getElementById('invDelTpl').hidden = editing || (DATA.templates || []).length < 2;
        nameInput.readOnly = !editing;
        if (!editing) { prop.hidden = true; closePop(); }
      }
      function openPreview(id) {
        current = cloneDesign(findTpl(id));
        repairDesignLayout(current);
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
        repairDesignLayout(current);
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
        var col = el('button', '', 'رص من اليمين');
        col.type = 'button';
        col.addEventListener('click', function () { packEdge('right'); closePop(); });
        var left = el('button', '', 'رص من اليسار');
        left.type = 'button';
        left.addEventListener('click', function () { packEdge('left'); closePop(); });
        var beside = el('button', '', UI.beside || 'بجانب بعض');
        beside.type = 'button';
        beside.addEventListener('click', function () { alignBeside(); closePop(); });
        pop.appendChild(col);
        pop.appendChild(left);
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
              var boxf = frameCopy(current);
              boxf.style = id;
              if (id === 'round' && !(boxf.radius > 0)) boxf.radius = 3;
              current.frame_box = boxf;
              current.frame = id;
              var all = grid.querySelectorAll('button');
              for (var n = 0; n < all.length; n++) all[n].classList.toggle('is-on', all[n] === b);
              fitMain();
              if (id !== 'none') pick('__frame__', false, true);
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
          var t = (b.label ? String(b.label) + ' ' : '') + shownValue(b, design);
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
      function packEdge(side) {
        if (!current || !paper) return;
        var rect = paper.getBoundingClientRect();
        var box = boxMm(current);
        if (!rect.width) return;
        var items = [];
        for (var i = 0; i < current.blocks.length; i++) {
          var b = current.blocks[i];
          if (!b || b.kind === 'logo' || b.kind === 'qr') continue;
          if (pickedIds.length > 1 && pickedIds.indexOf(b.id) < 0) continue;
          var el = qBlock(b.id);
          var m = el ? blockMm(el, rect, box) : { l: b.x, r: b.x + (b.w || 20), t: b.y, b: b.y + 6, w: b.w || 20 };
          items.push({ b: b, m: m });
        }
        if (!items.length) return;
        items.sort(function (a, c) { return a.m.t - c.m.t; });
        var edge = side === 'left' ? items[0].m.l : items[0].m.r;
        for (var k = 1; k < items.length; k++) {
          if (side === 'left') edge = Math.min(edge, items[k].m.l);
          else edge = Math.max(edge, items[k].m.r);
        }
        var y = items[0].m.t;
        for (var n = 0; n < items.length; n++) {
          var it = items[n];
          var w = Math.max(4, it.m.w);
          if (side === 'left') it.b.x = Math.max(0, Math.round(edge * 10) / 10);
          else it.b.x = Math.max(0, Math.round((edge - w) * 10) / 10);
          it.b.y = Math.round(y * 10) / 10;
          y += Math.max(5, it.m.b - it.m.t) + 1.4;
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
      function editTable(b) {
        if (!b || !pop) return;
        if (!b.rows || !b.rows.length) b.rows = [[{t:'', k:''}, {t:'', k:''}]];
        pop.hidden = false;
        pop.innerHTML = '';
        normalizeTable(b);
        pop.appendChild(el('h3', '', 'خلايا الجدول'));
        var counts = el('div', 'inv-trow');
        var rowLab = el('label', '');
        rowLab.textContent = 'صفوف';
        rowLab.style.fontSize = '11px';
        rowLab.style.fontWeight = '800';
        var rowN = document.createElement('input');
        rowN.type = 'number';
        rowN.min = '1';
        rowN.max = '20';
        rowN.value = String(b.rows.length);
        rowN.title = 'عدد الصفوف';
        rowLab.appendChild(rowN);
        var colLab = el('label', '');
        colLab.textContent = 'أعمدة';
        colLab.style.fontSize = '11px';
        colLab.style.fontWeight = '800';
        var colN = document.createElement('input');
        colN.type = 'number';
        colN.min = '1';
        colN.max = '8';
        colN.value = String((b.rows[0] && b.rows[0].length) || 1);
        colN.title = 'عدد الأعمدة';
        colLab.appendChild(colN);
        function resizeGrid() {
          var nr = Math.max(1, Math.min(20, parseInt(rowN.value, 10) || 1));
          var nc = Math.max(1, Math.min(8, parseInt(colN.value, 10) || 1));
          while (b.rows.length < nr) {
            var row = [];
            for (var i = 0; i < nc; i++) row.push({ t: '', k: '', span: 1 });
            b.rows.push(row);
          }
          if (b.rows.length > nr) b.rows = b.rows.slice(0, nr);
          for (var r = 0; r < b.rows.length; r++) {
            while (b.rows[r].length < nc) b.rows[r].push({ t: '', k: '', span: 1 });
            if (b.rows[r].length > nc) b.rows[r] = b.rows[r].slice(0, nc);
            for (var c = 0; c < b.rows[r].length; c++) b.rows[r][c].span = 1;
          }
          b.cols = [];
          for (var ci = 0; ci < nc; ci++) b.cols.push(nc === 2 ? (ci === 0 ? 52 : 48) : (100 / nc));
          normalizeTable(b);
          clampTableBox(b);
          syncBlock(b);
          editTable(b);
        }
        rowN.addEventListener('change', resizeGrid);
        colN.addEventListener('change', resizeGrid);
        counts.appendChild(rowLab);
        counts.appendChild(colLab);
        pop.appendChild(counts);
        function opts(sel, val) {
          var empty = document.createElement('option');
          empty.value = '';
          empty.textContent = 'نص';
          sel.appendChild(empty);
          for (var key in VAR_LABEL) if (VAR_LABEL.hasOwnProperty(key)) {
            var op = document.createElement('option');
            op.value = key;
            op.textContent = VAR_LABEL[key];
            if (key === val) op.selected = true;
            sel.appendChild(op);
          }
        }
        for (var r = 0; r < b.rows.length; r++) {
          (function (row, ri) {
            var line = el('div', 'inv-trow');
            for (var c = 0; c < row.length; c++) {
              (function (cell) {
                var inp = document.createElement('input');
                inp.type = 'text';
                inp.value = cell.t || '';
                inp.placeholder = 'نص';
                inp.addEventListener('input', function () { cell.t = inp.value; syncBlock(b); });
                var sel = document.createElement('select');
                opts(sel, cell.k || '');
                sel.addEventListener('change', function () { cell.k = sel.value; syncBlock(b); });
                var wrap = el('div', '');
                wrap.style.flex = '1';
                wrap.style.minWidth = '0';
                wrap.appendChild(inp);
                wrap.appendChild(sel);
                line.appendChild(wrap);
              })(row[c]);
            }
            var del = el('button', '', 'حذف الصف');
            del.type = 'button';
            del.style.width = 'auto';
            del.style.marginTop = '0';
            del.addEventListener('click', function () {
              if (b.rows.length < 2) return;
              b.rows.splice(ri, 1);
              syncBlock(b);
              editTable(b);
            });
            line.appendChild(del);
            pop.appendChild(line);
          })(b.rows[r], r);
        }
        var addRow = el('button', '', 'صف جديد');
        addRow.type = 'button';
        addRow.addEventListener('click', function () {
          var cols = (b.rows[0] && b.rows[0].length) || 2;
          var row = [];
          for (var i = 0; i < cols; i++) row.push({t:'', k:''});
          b.rows.push(row);
          syncBlock(b);
          editTable(b);
        });
        var addCol = el('button', '', 'عمود جديد');
        addCol.type = 'button';
        addCol.addEventListener('click', function () {
          for (var i = 0; i < b.rows.length; i++) b.rows[i].push({t:'', k:''});
          syncBlock(b);
          editTable(b);
        });
        var dropCol = el('button', '', 'حذف آخر عمود');
        dropCol.type = 'button';
        dropCol.addEventListener('click', function () {
          if (!b.rows[0] || b.rows[0].length < 2) return;
          for (var i = 0; i < b.rows.length; i++) b.rows[i].pop();
          syncBlock(b);
          editTable(b);
        });
        pop.appendChild(addRow);
        pop.appendChild(addCol);
        pop.appendChild(dropCol);
      }
      function addKind(kind) {
        var box = boxMm(current);
        var variable = isVarKind(kind);
        var b = norm({
          id: 'b' + Date.now(),
          kind: kind,
          x: variable ? 4 : Math.max(2, box.w - 30),
          y: 4,
          w: kind === 'logo' ? 18 : (kind === 'qr' ? 22 : 0),
          size: kind === 'amount' ? 18 : 13,
          color: '#111827',
          align: 'right',
          dir: 'rtl',
          text: kind === 'custom' ? 'نص' : '',
          label: variable ? (VAR_LABEL[kind] || '') : ''
        });
        if (kind === 'rule' || kind === 'ruledash' || kind === 'table') {
          b.kind = kind === 'table' ? 'table' : 'rule';
          b.dash = kind === 'ruledash' ? 1 : 0;
          b.x = 4;
          b.w = Math.max(24, box.w - 8);
          var below = 4;
          for (var ri = 0; ri < current.blocks.length; ri++) {
            var bot = (parseFloat(current.blocks[ri].y) || 0) + 8;
            if (bot > below) below = bot;
          }
          b.y = Math.round(below * 10) / 10;
          if (kind === 'table') {
            b.rowH = 7;
            b.cols = [42, 58];
            b.x = 4;
            b.w = Math.max(40, box.w - 8);
            b.rows = [
              [{ t: 'اسم العميل', k: '' }, { t: '', k: 'name' }],
              [{ t: 'نوع الاشتراك', k: '' }, { t: '', k: 'package' }],
              [{ t: 'تاريخ التفعيل', k: '' }, { t: '', k: 'start' }],
              [{ t: 'تاريخ الانتهاء', k: '' }, { t: '', k: 'end' }],
              [{ t: 'المبلغ', k: '' }, { t: '', k: 'amount' }]
            ];
            clampTableBox(b);
          }
          current.blocks.push(b);
          closePop();
          fitMain();
          pick(b.id);
          if (kind === 'table') editTable(b);
          return;
        }
        if (variable) {
          var prevY = 4;
          for (var vi = 0; vi < current.blocks.length; vi++) {
            var bottom = (parseFloat(current.blocks[vi].y) || 0) + 8;
            if (bottom > prevY) prevY = bottom;
          }
          b.y = Math.round(prevY * 10) / 10;
          current.blocks.push(b);
          closePop();
          fitMain();
          pick(b.id);
          return;
        }
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
      function showBits(sel, on) {
        var nodes = prop.querySelectorAll(sel);
        for (var i = 0; i < nodes.length; i++) nodes[i].hidden = !on;
      }
      function fillProp() {
        if (mode !== 'edit' || !picked) { prop.hidden = true; return; }
        prop.hidden = false;
        if (picked === '__frame__') {
          showBits('.inv-for-text', false);
          showBits('.inv-for-var', false);
          showBits('.inv-for-logo', false);
          showBits('.inv-for-table', false);
          showBits('.inv-for-frame', true);
          showBits('.inv-for-pos', true);
          var noteF = document.getElementById('pFootNote');
          if (noteF) noteF.hidden = true;
          var delF = document.getElementById('pDel');
          if (delF) delF.hidden = false;
          var f = frameCopy(current);
          var g = frameGeom(current);
          var styleBox = document.getElementById('pFrameStyle');
          if (styleBox) setSeg(styleBox, f.style);
          var fc = document.getElementById('pFrameColor');
          if (fc && document.activeElement !== fc) fc.value = f.color;
          var ft = document.getElementById('pFrameThick');
          if (ft && document.activeElement !== ft) ft.value = String(f.thick);
          var frad = document.getElementById('pFrameRadius');
          if (frad && document.activeElement !== frad) frad.value = String(f.radius);
          if (document.activeElement !== document.getElementById('pX')) document.getElementById('pX').value = g.x;
          if (document.activeElement !== document.getElementById('pY')) document.getElementById('pY').value = g.y;
          var pc0 = document.getElementById('pCount');
          if (pc0) { pc0.hidden = true; pc0.textContent = ''; }
          return;
        }
        if (picked === '__foot__') {
          showBits('.inv-for-text', false);
          showBits('.inv-for-var', false);
          showBits('.inv-for-logo', false);
          showBits('.inv-for-frame', false);
          showBits('.inv-for-table', false);
          showBits('.inv-for-pos', true);
          var note = document.getElementById('pFootNote');
          if (note) note.hidden = false;
          var del = document.getElementById('pDel');
          if (del) del.hidden = true;
          var alignBox = document.getElementById('pAlign');
          if (alignBox) { alignBox.hidden = false; setSeg(alignBox, current.footer_align || 'center'); }
          var dirBox = document.getElementById('pDir');
          if (dirBox) dirBox.hidden = true;
          var band = current._foot || { x: 0, y: 0 };
          var fx = parseFloat(current.footer_x);
          var fy = parseFloat(current.footer_y);
          var pXf = document.getElementById('pX');
          var pYf = document.getElementById('pY');
          if (pXf && document.activeElement !== pXf) pXf.value = (isFinite(fx) && fx >= 0) ? fx : Math.round(band.x * 10) / 10;
          if (pYf && document.activeElement !== pYf) pYf.value = (isFinite(fy) && fy >= 0) ? fy : Math.round(band.y * 10) / 10;
          var pc1 = document.getElementById('pCount');
          if (pc1) { pc1.hidden = true; pc1.textContent = ''; }
          return;
        }
        showBits('.inv-for-frame', false);
        var noteOff = document.getElementById('pFootNote');
        if (noteOff) noteOff.hidden = true;
        var delOn = document.getElementById('pDel');
        if (delOn) delOn.hidden = false;
        showBits('.inv-for-pos', true);
        var b = blockById(picked);
        if (!b) { prop.hidden = true; return; }
        var special = b.kind === 'logo' || b.kind === 'qr';
        var variable = isVarKind(b.kind);
        showBits('.inv-for-text', !special && b.kind !== 'table' && b.kind !== 'rule');
        showBits('.inv-for-var', variable);
        showBits('.inv-for-table', b.kind === 'table');
        var pRowH = document.getElementById('pRowH');
        if (pRowH && b.kind === 'table') pRowH.value = String(tableRowH(b));
        var logoBits = prop.querySelectorAll('.inv-for-logo');
        for (var j = 0; j < logoBits.length; j++) logoBits[j].hidden = b.kind !== 'logo';
        var pText = document.getElementById('pText');
        var pSize = document.getElementById('pSize');
        var pSizeN = document.getElementById('pSizeN');
        var lab = document.getElementById('pTextLab');
        if (lab) lab.textContent = (isVarKind(b.kind) && String(b.label || '') !== '') ? 'ثابت' : (UI.text || 'النص');
        if (pText) {
          pText.placeholder = (isVarKind(b.kind) && String(b.label || '') !== '') ? (VAR_LABEL[b.kind] || 'كلمة ثابتة') : '';
          if (document.activeElement !== pText) {
            pText.value = (isVarKind(b.kind) && String(b.label || '') !== '') ? (b.label || '') : (b.text || '');
          }
        }
        var bind = document.getElementById('pBind');
        if (bind && document.activeElement !== bind) bind.value = variable ? b.kind : 'custom';
        if (pSize && pSizeN && document.activeElement !== pSize && document.activeElement !== pSizeN) {
          pSize.value = b.size;
          pSizeN.value = b.size;
        }
        var pColorEl = document.getElementById('pColor');
        if (pColorEl) pColorEl.value = /^#[0-9a-fA-F]{6}$/.test(b.color || '') ? b.color : '#111827';
        var pHugEl = document.getElementById('pHug');
        if (pHugEl) pHugEl.checked = !(b.w > 0);
        var hugWrap = document.getElementById('pHugWrap');
        if (hugWrap) hugWrap.classList.toggle('is-on', !(b.w > 0));
        var pXel = document.getElementById('pX');
        var pYel = document.getElementById('pY');
        if (pXel && document.activeElement !== pXel) pXel.value = b.x;
        if (pYel && document.activeElement !== pYel) pYel.value = b.y;
        var alignEl = document.getElementById('pAlign');
        var dirEl = document.getElementById('pDir');
        if (alignEl) setSeg(alignEl, b.align);
        if (dirEl) setSeg(dirEl, b.dir);
        var stackBox = document.getElementById('pStack');
        if (stackBox) setSeg(stackBox, b.stack ? 'stack' : 'row');
        var weightBox = document.getElementById('pWeight');
        if (weightBox) setSeg(weightBox, b.weight === 'normal' ? 'normal' : 'bold');
        var styleBox = document.getElementById('pStyle');
        if (styleBox) {
          var styleBtns = styleBox.querySelectorAll('button');
          for (var si = 0; si < styleBtns.length; si++) {
            var key = styleBtns[si].getAttribute('data-v');
            styleBtns[si].classList.toggle('is-on', (key === 'italic' && !!b.italic) || (key === 'under' && !!b.under));
          }
        }
        var pc = document.getElementById('pCount');
        if (pc) {
          if (pickedIds.length > 1) {
            pc.hidden = false;
            pc.textContent = pickedIds.length + ' عناصر';
          } else {
            pc.hidden = true;
            pc.textContent = '';
          }
        }
      }
      function setSeg(box, val) {
        if (!box) return;
        var btns = box.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) btns[i].classList.toggle('is-on', btns[i].getAttribute('data-v') === val);
      }
      function eachSelected(fn) {
        var ids = pickedIds.length ? pickedIds : (picked ? [picked] : []);
        for (var i = 0; i < ids.length; i++) {
          var b = blockById(ids[i]);
          if (b) fn(b);
        }
      }
      function pick(id, additive, keepPop) {
        if (id && !keepPop) closePop();
        var wasHidden = prop.hidden;
        if (id === '__foot__') {
          picked = '__foot__';
          pickedIds = ['__foot__'];
          var nodesFoot = paper.querySelectorAll('.inv-block');
          for (var ffi = 0; ffi < nodesFoot.length; ffi++) nodesFoot[ffi].classList.remove('is-pick');
          var frClear = paper.querySelector('.inv-frame');
          if (frClear) frClear.classList.remove('is-pick');
          var footOn = paper.querySelector('.inv-foot');
          if (footOn) footOn.classList.add('is-pick');
          try { fillProp(); } catch (errF) {}
          return;
        }
        if (id === '__frame__') {
          picked = '__frame__';
          pickedIds = ['__frame__'];
          var nodesF = paper.querySelectorAll('.inv-block');
          for (var fi = 0; fi < nodesF.length; fi++) nodesF[fi].classList.remove('is-pick');
          var frPick = paper.querySelector('.inv-frame');
          if (frPick) frPick.classList.add('is-pick');
          try { fillProp(); } catch (errFr) {}
          return;
        }
        if (!id) {
          picked = null;
          pickedIds = [];
        } else if (additive) {
          var at = pickedIds.indexOf(id);
          if (at >= 0) pickedIds.splice(at, 1);
          else pickedIds.push(id);
          picked = pickedIds.length ? pickedIds[pickedIds.length - 1] : null;
        } else if (pickedIds.indexOf(id) < 0) {
          pickedIds = [id];
          picked = id;
        } else {
          picked = id;
        }
        var nodes = paper.querySelectorAll('.inv-block');
        for (var i = 0; i < nodes.length; i++) nodes[i].classList.toggle('is-pick', pickedIds.indexOf(nodes[i].getAttribute('data-id')) >= 0);
        var frOff = paper.querySelector('.inv-frame');
        if (frOff) frOff.classList.remove('is-pick');
        var footOff = paper.querySelector('.inv-foot');
        if (footOff) footOff.classList.remove('is-pick');
        try { fillProp(); } catch (err) {}
        if (prop.hidden !== wasHidden) requestAnimationFrame(function () { requestAnimationFrame(function () { if (!drag) fitMain(); }); });
      }
      function removeBlock(b) {
        var drop = {};
        if (b) drop[b.id] = true;
        else eachSelected(function (item) { drop[item.id] = true; });
        var next = [];
        for (var i = 0; i < current.blocks.length; i++) if (!drop[current.blocks[i].id]) next.push(current.blocks[i]);
        current.blocks = next;
        picked = null;
        pickedIds = [];
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
          if (isVarKind(b.kind) && String(b.label || '') !== '') b.label = pText.value;
          else b.text = pText.value;
          syncBlock(b);
        });
        var pBind = document.getElementById('pBind');
        if (pBind && !pBind.options.length) {
          var opt0 = document.createElement('option');
          opt0.value = 'custom';
          opt0.textContent = 'نص فقط';
          pBind.appendChild(opt0);
          for (var bk in VAR_LABEL) if (VAR_LABEL.hasOwnProperty(bk)) {
            var opt = document.createElement('option');
            opt.value = bk;
            opt.textContent = VAR_LABEL[bk];
            pBind.appendChild(opt);
          }
        }
        if (pBind) pBind.addEventListener('change', function () {
          var b = blockById(picked); if (!b) return;
          var v = pBind.value;
          if (v === 'custom') {
            if (!b.text) b.text = b.label || shownValue(b, current);
            b.kind = 'custom';
            b.label = '';
          } else {
            b.kind = v;
            if (!b.label) b.label = VAR_LABEL[v] || '';
            b.dir = 'rtl';
          }
          syncBlock(b);
          fillProp();
        });
        function writeFrame(patch) {
          var f = frameCopy(current);
          var key;
          for (key in patch) if (patch.hasOwnProperty(key)) f[key] = patch[key];
          current.frame_box = f;
          current.frame = f.style || 'none';
          fitMain();
        }
        var pFrameStyle = document.getElementById('pFrameStyle');
        if (pFrameStyle) pFrameStyle.addEventListener('click', function (e) {
          var btn = e.target.closest ? e.target.closest('button') : null;
          if (!btn || picked !== '__frame__') return;
          var style = btn.getAttribute('data-v');
          var patch = { style: style };
          if (style === 'round') {
            var now = frameCopy(current);
            if (!(now.radius > 0)) patch.radius = 3;
          }
          writeFrame(patch);
        });
        var pFrameColor = document.getElementById('pFrameColor');
        if (pFrameColor) pFrameColor.addEventListener('input', function () {
          if (picked !== '__frame__') return;
          writeFrame({ color: pFrameColor.value });
        });
        var pFrameThick = document.getElementById('pFrameThick');
        if (pFrameThick) pFrameThick.addEventListener('input', function () {
          if (picked !== '__frame__') return;
          var n = parseFloat(pFrameThick.value);
          if (!isFinite(n)) return;
          writeFrame({ thick: Math.max(0.15, Math.min(3, n)) });
        });
        var pFrameRadius = document.getElementById('pFrameRadius');
        if (pFrameRadius) pFrameRadius.addEventListener('input', function () {
          if (picked !== '__frame__') return;
          var n = parseFloat(pFrameRadius.value);
          if (!isFinite(n)) return;
          writeFrame({ radius: Math.max(0, Math.min(20, n)) });
        });
        function setSize(n) {
          n = Math.max(6, Math.min(72, parseFloat(n) || 13));
          if (pSize) pSize.value = n;
          if (pSizeN) pSizeN.value = n;
          eachSelected(function (b) {
            if (b.kind === 'logo') return;
            if (b.kind === 'qr') b.w = Math.max(12, Math.min(60, n));
            else b.size = n;
            syncBlock(b);
          });
        }
        if (pSize) pSize.addEventListener('input', function () { setSize(pSize.value); });
        if (pSizeN) pSizeN.addEventListener('input', function () { setSize(pSizeN.value); });
        var pSizeMinus = document.getElementById('pSizeMinus');
        var pSizePlus = document.getElementById('pSizePlus');
        if (pSizeMinus) pSizeMinus.addEventListener('click', function () { setSize((parseFloat(pSizeN && pSizeN.value) || 13) - 1); });
        if (pSizePlus) pSizePlus.addEventListener('click', function () { setSize((parseFloat(pSizeN && pSizeN.value) || 13) + 1); });
        if (pColor) pColor.addEventListener('input', function () {
          eachSelected(function (b) {
            if (b.kind === 'logo' || b.kind === 'qr') return;
            b.color = pColor.value;
            syncBlock(b);
          });
        });
        if (pHug) pHug.addEventListener('change', function () {
          var hugWrap = document.getElementById('pHugWrap');
          if (hugWrap) hugWrap.classList.toggle('is-on', pHug.checked);
          eachSelected(function (b) {
            if (b.kind === 'logo' || b.kind === 'qr') return;
            if (pHug.checked) b.w = 0;
            else {
              var el = qBlock(b.id);
              var rect = paper.getBoundingClientRect();
              var box = boxMm(current);
              b.w = el ? Math.max(8, Math.round(el.getBoundingClientRect().width / rect.width * box.w * 10) / 10) : 20;
            }
            syncBlock(b);
          });
        });
        if (pX) pX.addEventListener('input', function () {
          if (picked === '__foot__') {
            var n = parseFloat(pX.value);
            if (!isFinite(n) || n < 0) n = 0;
            current.footer_x = n;
            settleFoot();
            return;
          }
          if (picked === '__frame__') {
            var g = frameGeom(current);
            writeFrame({ x: Math.max(0, parseFloat(pX.value) || 0), y: g.y, w: g.w, h: g.h });
            return;
          }
          var b = blockById(picked); if (!b) return;
          b.x = Math.max(0, parseFloat(pX.value) || 0);
          syncBlock(b);
        });
        if (pY) pY.addEventListener('input', function () {
          if (picked === '__foot__') {
            var n = parseFloat(pY.value);
            if (!isFinite(n) || n < 0) n = 0;
            current.footer_y = n;
            settleFoot();
            return;
          }
          if (picked === '__frame__') {
            var g = frameGeom(current);
            writeFrame({ y: Math.max(0, parseFloat(pY.value) || 0), x: g.x, w: g.w, h: g.h });
            return;
          }
          var b = blockById(picked); if (!b) return;
          b.y = Math.max(0, parseFloat(pY.value) || 0);
          syncBlock(b);
        });
        var pAlignEl = document.getElementById('pAlign');
        if (pAlignEl) pAlignEl.addEventListener('click', function (e) {
          var btn = e.target.closest ? e.target.closest('button') : null;
          if (!btn) return;
          var align = btn.getAttribute('data-v');
          setSeg(pAlignEl, align);
          if (picked === '__foot__') {
            current.footer_align = align;
            var foot = paper.querySelector('.inv-foot');
            if (foot) foot.style.textAlign = align;
            return;
          }
          eachSelected(function (b) {
            if (b.kind === 'logo' || b.kind === 'qr') return;
            b.align = align;
            syncBlock(b);
          });
        });
        var pWeight = document.getElementById('pWeight');
        if (pWeight) pWeight.addEventListener('click', function (e) {
          var btn = e.target.closest ? e.target.closest('button') : null;
          if (!btn) return;
          var weight = btn.getAttribute('data-v') === 'normal' ? 'normal' : 'bold';
          setSeg(pWeight, weight);
          eachSelected(function (b) {
            if (b.kind === 'logo' || b.kind === 'qr') return;
            b.weight = weight;
            syncBlock(b);
          });
        });
        var pStyle = document.getElementById('pStyle');
        if (pStyle) pStyle.addEventListener('click', function (e) {
          var btn = e.target.closest ? e.target.closest('button') : null;
          if (!btn) return;
          var key = btn.getAttribute('data-v');
          var on = !btn.classList.contains('is-on');
          btn.classList.toggle('is-on', on);
          eachSelected(function (b) {
            if (b.kind === 'logo' || b.kind === 'qr') return;
            if (key === 'italic') b.italic = on ? 1 : 0;
            if (key === 'under') b.under = on ? 1 : 0;
            syncBlock(b);
          });
        });
        var pDirEl = document.getElementById('pDir');
        if (pDirEl) pDirEl.addEventListener('click', function (e) {
          var btn = e.target.closest ? e.target.closest('button') : null;
          if (!btn) return;
          var dir = btn.getAttribute('data-v');
          setSeg(document.getElementById('pDir'), dir);
          eachSelected(function (b) {
            if (b.kind === 'logo' || b.kind === 'qr') return;
            b.dir = dir;
            syncBlock(b);
          });
        });
        var pStack = document.getElementById('pStack');
        if (pStack) pStack.addEventListener('click', function (e) {
          var btn = e.target.closest ? e.target.closest('button') : null;
          if (!btn) return;
          var stacked = btn.getAttribute('data-v') === 'stack';
          setSeg(pStack, stacked ? 'stack' : 'row');
          eachSelected(function (b) {
            if (!isVarKind(b.kind)) return;
            b.stack = stacked ? 1 : 0;
            syncBlock(b);
          });
        });
        var pPack = document.getElementById('pPack');
        if (pPack) pPack.addEventListener('click', function (e) {
          var btn = e.target.closest ? e.target.closest('button') : null;
          if (!btn) return;
          packEdge(btn.getAttribute('data-pack') === 'left' ? 'left' : 'right');
        });
        var pTableEdit = document.getElementById('pTableEdit');
        if (pTableEdit) pTableEdit.addEventListener('click', function () {
          var b = blockById(picked);
          if (b && b.kind === 'table') editTable(b);
        });
        function tableNow() {
          var b = blockById(picked);
          return (b && b.kind === 'table') ? b : null;
        }
        function blankCells(n) {
          var row = [];
          for (var i = 0; i < n; i++) row.push({t:'', k:'', span:1});
          return row;
        }
        var tRowAdd = document.getElementById('tRowAdd');
        if (tRowAdd) tRowAdd.addEventListener('click', function () {
          var b = tableNow(); if (!b) return;
          var n = (b.rows[0] && b.rows[0].length) || 2;
          b.rowH = tableRowH(b);
          b.pack = 1;
          b.rows.push(blankCells(n));
          ensureTableCols(b);
          syncBlock(b);
        });
        var tRowDel = document.getElementById('tRowDel');
        if (tRowDel) tRowDel.addEventListener('click', function () {
          var b = tableNow(); if (!b || b.rows.length < 2) return;
          var ri = b._cell ? b._cell.r : b.rows.length - 1;
          if (ri < 0 || ri >= b.rows.length) ri = b.rows.length - 1;
          b.rows.splice(ri, 1);
          b._cell = null;
          syncBlock(b);
        });
        var tColAdd = document.getElementById('tColAdd');
        if (tColAdd) tColAdd.addEventListener('click', function () {
          var b = tableNow(); if (!b) return;
          var oldN = tableColCount(b);
          ensureTableCols(b);
          var avg = 0;
          for (var a = 0; a < b.cols.length; a++) avg += b.cols[a];
          avg = oldN > 0 ? avg / oldN : 50;
          for (var i = 0; i < b.rows.length; i++) b.rows[i].push({t:'', k:'', span:1});
          b.cols.push(avg);
          b.pack = 1;
          clampTableBox(b);
          ensureTableCols(b);
          syncBlock(b);
        });
        var tColDel = document.getElementById('tColDel');
        if (tColDel) tColDel.addEventListener('click', function () {
          var b = tableNow(); if (!b || !b.rows[0] || b.rows[0].length < 2) return;
          var ci = b._cell ? b._cell.c : b.rows[0].length - 1;
          for (var i = 0; i < b.rows.length; i++) {
            if (ci >= 0 && ci < b.rows[i].length) b.rows[i].splice(ci, 1);
          }
          b._cell = null;
          ensureTableCols(b);
          syncBlock(b);
        });
        var tMerge = document.getElementById('tMerge');
        if (tMerge) tMerge.addEventListener('click', function () {
          var b = tableNow(); if (!b || !b._cell) return;
          if (mergeTableCells(b, b._cell.r, b._cell.c)) {
            ensureTableCols(b);
            syncBlock(b);
          }
        });
        var pRowHEl = document.getElementById('pRowH');
        if (pRowHEl) pRowHEl.addEventListener('input', function () {
          var b = tableNow(); if (!b) return;
          b.rowH = Math.max(4, Math.min(30, parseFloat(pRowHEl.value) || 7));
          syncBlock(b);
        });
        var tPack = document.getElementById('tPack');
        if (tPack) tPack.addEventListener('click', function () {
          var b = tableNow(); if (!b) return;
          b.pack = 1;
          syncBlock(b);
        });
        var pDelEl = document.getElementById('pDel');
        if (pDelEl) pDelEl.addEventListener('click', function () {
          if (picked === '__foot__') return;
          if (picked === '__frame__') {
            writeFrame({ style: 'none' });
            pick(null);
            return;
          }
          if (!picked && !pickedIds.length) return;
          removeBlock(null);
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
        var grip = e.target && e.target.closest ? e.target.closest('.inv-len-handle, .inv-wid-handle') : null;
        if (grip) {
          var box0 = boxMm(current);
          drag = {
            mode: grip.classList.contains('inv-wid-handle') ? 'sheetw' : 'sheet',
            sy: e.clientY,
            sx: e.clientX,
            sh: box0.h,
            sw: box0.w,
            id: ''
          };
          if (grip.setPointerCapture) { try { grip.setPointerCapture(e.pointerId); } catch (err) {} }
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        var frameHit = e.target && e.target.closest ? e.target.closest('.inv-edge, .inv-fr-h') : null;
        if (frameHit && !closestBlock(e.target)) {
          var g0 = frameGeom(current);
          var stored = frameCopy(current);
          stored.x = g0.x;
          stored.y = g0.y;
          stored.w = g0.w;
          stored.h = g0.h;
          current.frame_box = stored;
          current.frame = stored.style;
          drag = {
            mode: frameHit.classList.contains('inv-fr-h') ? 'frame-size' : 'frame-move',
            handle: frameHit.getAttribute('data-h') || 'move',
            sx: e.clientX,
            sy: e.clientY,
            fx: g0.x,
            fy: g0.y,
            fw: g0.w,
            fh: g0.h,
            id: '__frame__',
            moved: false
          };
          pick('__frame__');
          if (frameHit.setPointerCapture) { try { frameHit.setPointerCapture(e.pointerId); } catch (err) {} }
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        var footHit = e.target && e.target.closest ? e.target.closest('.inv-foot') : null;
        if (footHit && !closestBlock(e.target)) {
          var band0 = current._foot || { x: 0, y: 0, w: 10, h: 4 };
          drag = {
            mode: 'foot',
            sx: e.clientX,
            sy: e.clientY,
            fx: band0.x,
            fy: band0.y,
            fw: band0.w,
            fh: band0.h,
            id: '__foot__',
            moved: false
          };
          pick('__foot__');
          if (footHit.setPointerCapture) { try { footHit.setPointerCapture(e.pointerId); } catch (err) {} }
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        var block = closestBlock(e.target);
        if (!block) { pick(null); return; }
        var id = block.getAttribute('data-id');
        var b = blockById(id);
        if (!b) return;
        var mergeHit = e.target && e.target.closest ? e.target.closest('.inv-merge') : null;
        if (mergeHit && b.kind === 'table') {
          var mr = parseInt(mergeHit.getAttribute('data-r'), 10);
          var mc = parseInt(mergeHit.getAttribute('data-c'), 10);
          pick(id);
          if (isFinite(mr) && isFinite(mc) && mc > 0 && mergeTableCells(b, mr, mc - 1)) {
            ensureTableCols(b);
            syncBlock(b);
          }
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        var colGrip = e.target && e.target.closest ? e.target.closest('.inv-col-line') : null;
        if (colGrip && b.kind === 'table') {
          ensureTableCols(b);
          var slot = parseInt(colGrip.getAttribute('data-col'), 10);
          pick(id);
          normalizeTable(b);
          colGrip.classList.add('is-drag');
          drag = {
            id: id,
            mode: 'col',
            sx: e.clientX,
            sy: e.clientY,
            slot: slot,
            cols: b.cols.slice(),
            line: colGrip,
            moved: false
          };
          if (colGrip.setPointerCapture) { try { colGrip.setPointerCapture(e.pointerId); } catch (errC) {} }
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        var rowGrip = e.target && e.target.closest ? e.target.closest('.inv-row-grip') : null;
        if (rowGrip && b.kind === 'table') {
          pick(id);
          drag = {
            id: id,
            mode: 'rowh',
            sx: e.clientX,
            sy: e.clientY,
            startH: tableRowH(b),
            moved: false
          };
          if (rowGrip.setPointerCapture) { try { rowGrip.setPointerCapture(e.pointerId); } catch (errR) {} }
          e.preventDefault();
          e.stopPropagation();
          return;
        }
        var additive = e.shiftKey || e.ctrlKey || e.metaKey;
        if (additive) {
          pick(id, true);
          return;
        }
        if (pickedIds.indexOf(id) < 0) pick(id, false);
        var ids = pickedIds.slice();
        var starts = {};
        for (var si = 0; si < ids.length; si++) {
          var sb = blockById(ids[si]);
          if (sb) starts[ids[si]] = { x: sb.x, y: sb.y };
        }
        pick(id);
        var br = block.getBoundingClientRect();
        var rect = paper.getBoundingClientRect();
        var box = boxMm(current);
        var resizeHit = e.target && e.target.closest ? e.target.closest('.inv-resize') : null;
        if (resizeHit && !block.contains(resizeHit)) resizeHit = null;
        if (resizeHit && b.kind === 'table') b.pack = 0;
        drag = {
          id: id,
          mode: resizeHit ? 'size' : 'move',
          handle: resizeHit ? (resizeHit.getAttribute('data-h') || 'se') : '',
          sx: e.clientX,
          sy: e.clientY,
          dx: e.clientX - br.left,
          dy: e.clientY - br.top,
          startW: br.width / rect.width * box.w,
          startX: b.x,
          startY: b.y,
          startRowH: tableRowH(b),
          rowCount: (b.rows && b.rows.length) ? b.rows.length : 1,
          selfH: br.height / rect.height * box.h,
          ids: ids,
          starts: starts,
          originX: b.x,
          originY: b.y,
          moved: false,
          cell: (!resizeHit && e.target && e.target.closest) ? (function () {
            var td = e.target.closest('td');
            if (!td || !block.contains(td)) return null;
            return { r: parseInt(td.getAttribute('data-r'), 10), c: parseInt(td.getAttribute('data-c'), 10) };
          })() : null
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
        drag.moved = true;
        var rect = paper.getBoundingClientRect();
        var box = boxMm(current);
        if (!rect.width || !rect.height) return;
        if (drag.mode === 'col') {
          var tbCol = blockById(drag.id);
          if (!tbCol || tbCol.kind !== 'table') return;
          var slot = drag.slot;
          if (!(slot > 0) || !drag.cols || slot >= drag.cols.length) return;
          var elCol = qBlock(drag.id);
          if (!elCol) return;
          var tw = elCol.offsetWidth || 1;
          var dxPx = e.clientX - drag.sx;
          var c0 = drag.cols[slot - 1];
          var c1 = drag.cols[slot];
          var pair = c0 + c1;
          var next0 = c0 - (dxPx / tw) * pair;
          if (next0 < pair * 0.2) next0 = pair * 0.2;
          if (next0 > pair * 0.8) next0 = pair * 0.8;
          tbCol.cols = drag.cols.slice();
          tbCol.cols[slot - 1] = next0;
          tbCol.cols[slot] = pair - next0;
          applyTableColsLive(elCol, tbCol);
          return;
        }
        if (drag.mode === 'rowh') {
          var tbRow = blockById(drag.id);
          if (!tbRow || tbRow.kind !== 'table') return;
          var dyMm = (e.clientY - drag.sy) / (rect.height / box.h);
          var nh = Math.max(4, Math.min(30, (drag.startH || 7) + dyMm));
          tbRow.rowH = Math.round(nh * 10) / 10;
          var pRowLive = document.getElementById('pRowH');
          if (pRowLive && document.activeElement !== pRowLive) pRowLive.value = String(tbRow.rowH);
          syncBlock(tbRow);
          return;
        }
        if (drag.mode === 'sheet' || drag.mode === 'sheetw') {
          if (drag.mode === 'sheet') {
            var per = rect.height / (drag.sh || 1);
            var next = drag.sh + (e.clientY - drag.sy) / (per || 1);
            if (next < 40) next = 40;
            if (next > 450) next = 450;
            next = Math.round(next * 10) / 10;
            current.sheet_h = next;
            paper.style.height = next + 'mm';
            var hb = paper.querySelector('.inv-len-handle b');
            if (hb) hb.textContent = (next / 10).toFixed(1) + ' سم';
            var footKeep = paper.querySelector('.inv-foot');
            if (footKeep) {
              var keepY = parseFloat(current.footer_y);
              var keepH = (current._foot && current._foot.h) || 4.2;
              if (!isFinite(keepY) || keepY < 0) keepY = Math.max(0, next - keepH - 1.2);
              if (keepY + keepH > next - 0.4) {
                keepY = Math.max(0, next - keepH - 0.4);
                if (isFinite(parseFloat(current.footer_y)) && current.footer_y >= 0) current.footer_y = Math.round(keepY * 10) / 10;
              }
              footKeep.style.top = keepY + 'mm';
              footKeep.style.bottom = 'auto';
            }
          } else {
            var perW = rect.width / (drag.sw || 1);
            var nextW = drag.sw + (drag.sx - e.clientX) / (perW || 1);
            if (nextW < 40) nextW = 40;
            if (nextW > 320) nextW = 320;
            nextW = Math.round(nextW * 10) / 10;
            current.sheet_w = nextW;
            paper.style.width = nextW + 'mm';
            var wb = paper.querySelector('.inv-wid-handle b');
            if (wb) wb.textContent = (nextW / 10).toFixed(1) + ' سم';
          }
          if (len) len.textContent = ((current.sheet_w || box.w) / 10).toFixed(1) + ' × ' + ((current.sheet_h || box.h) / 10).toFixed(1) + ' سم';
          return;
        }
        if (drag.mode === 'frame-move' || drag.mode === 'frame-size') {
          var fdx = (e.clientX - drag.sx) / rect.width * box.w;
          var fdy = (e.clientY - drag.sy) / rect.height * box.h;
          var fx = drag.fx, fy = drag.fy, fw = drag.fw, fh = drag.fh;
          var H = drag.handle || '';
          if (drag.mode === 'frame-move') { fx = drag.fx + fdx; fy = drag.fy + fdy; }
          else {
            if (H === 'e' || H === 'ne' || H === 'se') fw = drag.fw + fdx;
            if (H === 's' || H === 'sw' || H === 'se') fh = drag.fh + fdy;
            if (H === 'w' || H === 'nw' || H === 'sw') { fx = drag.fx + fdx; fw = drag.fw - fdx; }
            if (H === 'n' || H === 'nw' || H === 'ne') { fy = drag.fy + fdy; fh = drag.fh - fdy; }
          }
          if (fw < 12) fw = 12;
          if (fh < 12) fh = 12;
          if (fx < 0) fx = 0;
          if (fy < 0) fy = 0;
          if (fx + fw > box.w) fx = Math.max(0, box.w - fw);
          if (fy + fh > box.h) fy = Math.max(0, box.h - fh);
          var ff = frameCopy(current);
          ff.x = Math.round(fx * 10) / 10;
          ff.y = Math.round(fy * 10) / 10;
          ff.w = Math.round(fw * 10) / 10;
          ff.h = Math.round(fh * 10) / 10;
          current.frame_box = ff;
          current.frame = ff.style;
          var fr = paper.querySelector('.inv-frame');
          if (fr) {
            fr.style.left = ff.x + 'mm';
            fr.style.top = ff.y + 'mm';
            fr.style.width = ff.w + 'mm';
            fr.style.height = ff.h + 'mm';
          }
          var px = document.getElementById('pX');
          var py = document.getElementById('pY');
          if (px && document.activeElement !== px) px.value = ff.x;
          if (py && document.activeElement !== py) py.value = ff.y;
          return;
        }
        if (drag.mode === 'foot') {
          var mdx = (e.clientX - drag.sx) / rect.width * box.w;
          var mdy = (e.clientY - drag.sy) / rect.height * box.h;
          var nx = drag.fx + mdx;
          var ny = drag.fy + mdy;
          if (nx < 0) nx = 0;
          if (ny < 0) ny = 0;
          if (nx + drag.fw > box.w - 0.4) nx = Math.max(0, box.w - drag.fw - 0.4);
          if (ny + drag.fh > box.h - 0.4) ny = Math.max(0, box.h - drag.fh - 0.4);
          current.footer_x = Math.round(nx * 10) / 10;
          current.footer_y = Math.round(ny * 10) / 10;
          var footEl = paper.querySelector('.inv-foot');
          if (footEl) {
            footEl.style.left = current.footer_x + 'mm';
            footEl.style.top = current.footer_y + 'mm';
            footEl.style.bottom = 'auto';
          }
          current._foot = { x: current.footer_x, y: current.footer_y, w: drag.fw, h: drag.fh };
          shoveOffFooter();
          var pxf = document.getElementById('pX');
          var pyf = document.getElementById('pY');
          if (pxf && document.activeElement !== pxf) pxf.value = current.footer_x;
          if (pyf && document.activeElement !== pyf) pyf.value = current.footer_y;
          return;
        }
        var b = blockById(drag.id);
        if (!b) return;
        if (drag.mode === 'size') {
          var H = drag.handle || 'se';
          var dxMm = (e.clientX - drag.sx) / rect.width * box.w;
          var dyMm = (e.clientY - drag.sy) / rect.height * box.h;
          var nw = drag.startW;
          var nx = drag.startX;
          if (H.indexOf('e') >= 0) nw = drag.startW + dxMm;
          if (H.indexOf('w') >= 0) {
            nw = drag.startW - dxMm;
            nx = drag.startX + dxMm;
          }
          if (nw < 10) {
            if (H.indexOf('w') >= 0) nx = drag.startX + drag.startW - 10;
            nw = 10;
          }
          b.w = Math.round(nw * 10) / 10;
          b.x = Math.max(0, Math.round(nx * 10) / 10);
          if (b.kind === 'table') {
            if (H.indexOf('s') >= 0 || H.indexOf('n') >= 0) {
              var rowsN = Math.max(1, drag.rowCount || 1);
              var nextH = (drag.startRowH || 7) + (dyMm / rowsN) * (H.indexOf('n') >= 0 ? -1 : 1);
              b.rowH = Math.max(4, Math.min(28, Math.round(nextH * 10) / 10));
              var pRowLive2 = document.getElementById('pRowH');
              if (pRowLive2 && document.activeElement !== pRowLive2) pRowLive2.value = String(b.rowH);
            }
            clampTableBox(b);
          } else if (b.x + b.w > box.w) b.x = Math.max(0, box.w - b.w);
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
          var dx = snapped.x - drag.originX;
          var dy = snapped.y - drag.originY;
          var group = drag.ids && drag.ids.length ? drag.ids : [b.id];
          for (var gi = 0; gi < group.length; gi++) {
            var item = blockById(group[gi]);
            var st = drag.starts ? drag.starts[group[gi]] : null;
            if (!item || !st) continue;
            item.x = Math.max(0, Math.round((st.x + dx) * 10) / 10);
            item.y = Math.max(0, Math.round((st.y + dy) * 10) / 10);
            var movedEl = qBlock(item.id);
            if (movedEl) {
              movedEl.style.left = item.x + 'mm';
              movedEl.style.top = item.y + 'mm';
            }
          }
          var pxm = document.getElementById('pX');
          var pym = document.getElementById('pY');
          if (pxm && document.activeElement !== pxm) pxm.value = b.x;
          if (pym && document.activeElement !== pym) pym.value = b.y;
          return;
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
        var bestX = 0.6, bestY = 0.6, sx = null, sy = null, gx = null, gy = null;
        for (var i = 0; i < nodes.length; i++) {
          if (drag.ids && drag.ids.indexOf(nodes[i].getAttribute('data-id')) >= 0) continue;
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
        var frameDrag = drag.mode === 'frame-move' || drag.mode === 'frame-size';
        var tableGeom = drag.mode === 'col' || drag.mode === 'rowh';
        var cell = drag.cell;
        var id = drag.id;
        if (drag.line && drag.line.classList) drag.line.classList.remove('is-drag');
        drag = null;
        showGuide(null, null);
        if (!moved && cell && isFinite(cell.r)) {
          var tb = blockById(id);
          if (tb && tb.kind === 'table') {
            tb._cell = cell;
            syncBlock(tb);
          }
        }
        if (tableGeom) {
          var tbDone = blockById(id);
          if (tbDone) syncBlock(tbDone);
        }
        if (moved || frameDrag || tableGeom) fitMain();
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
      var invNewBtn = document.getElementById('invNew');
      if (invNewBtn) invNewBtn.addEventListener('click', function () { openEdit(DATA.blank, true); });
      var invShowTpl = document.getElementById('invShowTpl');
      if (invShowTpl) invShowTpl.addEventListener('click', function () { showGallery(); });
      var invBackInv = document.getElementById('invBackInvoices');
      if (invBackInv) invBackInv.addEventListener('click', function () { showLedger(); });
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
        var fSheet = document.getElementById('fSheet');
        if (fSheet) fSheet.value = current && parseFloat(current.sheet_h) > 0 ? String(current.sheet_h) : '0';
        var fSheetW = document.getElementById('fSheetW');
        if (fSheetW) fSheetW.value = current && parseFloat(current.sheet_w) > 0 ? String(current.sheet_w) : '0';
        document.getElementById('fFrame').value = current ? ((current.frame_box && current.frame_box.style) || current.frame || 'none') : 'none';
        var fFrameBox = document.getElementById('fFrameBox');
        if (fFrameBox) fFrameBox.value = JSON.stringify(current ? frameCopy(current) : {});
        var fFootX = document.getElementById('fFootX');
        var fFootY = document.getElementById('fFootY');
        var fFootAlign = document.getElementById('fFootAlign');
        if (fFootX) fFootX.value = current && isFinite(parseFloat(current.footer_x)) ? String(current.footer_x) : '-1';
        if (fFootY) fFootY.value = current && isFinite(parseFloat(current.footer_y)) ? String(current.footer_y) : '-1';
        if (fFootAlign) fFootAlign.value = current && current.footer_align ? current.footer_align : 'center';
        document.getElementById('fCompany').value = current ? (current.company_name || '') : '';
        document.getElementById('fPhone').value = current ? (current.phone || '') : '';
        document.getElementById('fAddress').value = current ? (current.address || '') : '';
        var blocks = [];
        var src = current && current.blocks ? current.blocks : [];
        for (var i = 0; i < src.length; i++) {
          var b = src[i];
          blocks.push({
            id: b.id, kind: b.kind, x: b.x, y: b.y, w: b.w > 0 ? b.w : 0,
            size: b.size, color: b.color, align: b.align, dir: b.dir, text: b.text || '', label: b.label || '',
            weight: b.weight === 'normal' ? 'normal' : 'bold', italic: b.italic ? 1 : 0, under: b.under ? 1 : 0,
            stack: b.stack ? 1 : 0, dash: b.dash ? 1 : 0, rows: b.rows || [],
            rowH: (parseFloat(b.rowH) > 0 ? parseFloat(b.rowH) : 7),
            cols: b.cols || [],
            pack: b.pack === 0 ? 0 : 1,
            on: 1
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
        var handles = clone.querySelectorAll('.inv-resize, .inv-edge, .inv-fr-h, .inv-len-handle, .inv-wid-handle, .inv-guide');
        for (var i = 0; i < handles.length; i++) if (handles[i].parentNode) handles[i].parentNode.removeChild(handles[i]);
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
        var chosen = parseFloat(current && current.sheet_h) || 0;
        if (chosen >= 30) {
          clone.style.height = chosen + 'mm';
          box = { w: box.w, h: chosen, fixed: true };
          return { holder: holder, node: clone, box: box };
        }
        var crect = clone.getBoundingClientRect();
        var pxPerMm = crect.width / (box.w || 1);
        var nodes = clone.querySelectorAll('.inv-block, .inv-foot');
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
          var keepH = parseFloat(current && current.sheet_h) || 0;
          if (!(keepH >= 30)) {
            try { canvas = cropVertical(canvas, topMarginMm(), 2.4, box.w); } catch (err) {}
          }
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
      var invPrint1 = document.getElementById('invPrintOpen');
      if (invPrint1) invPrint1.addEventListener('click', openPrinters);
      var invPrint2 = document.getElementById('invPrintOpen2');
      if (invPrint2) invPrint2.addEventListener('click', openPrinters);
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
        if (mode !== 'edit' || !pickedIds.length) return;
        if (picked === '__foot__') {
          var tagFoot = e.target && e.target.tagName;
          if (tagFoot === 'INPUT' || tagFoot === 'TEXTAREA' || tagFoot === 'SELECT') return;
          if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); return; }
          var stepFoot = e.shiftKey ? 2 : 0.5;
          var bandK = current._foot || { x: 0, y: 0, w: 12, h: 4 };
          var kx = isFinite(parseFloat(current.footer_x)) && current.footer_x >= 0 ? current.footer_x : bandK.x;
          var ky = isFinite(parseFloat(current.footer_y)) && current.footer_y >= 0 ? current.footer_y : bandK.y;
          if (e.key === 'ArrowLeft') kx -= stepFoot;
          else if (e.key === 'ArrowRight') kx += stepFoot;
          else if (e.key === 'ArrowUp') ky -= stepFoot;
          else if (e.key === 'ArrowDown') ky += stepFoot;
          else return;
          e.preventDefault();
          current.footer_x = Math.max(0, kx);
          current.footer_y = Math.max(0, ky);
          settleFoot();
          shoveOffFooter();
          fillProp();
          return;
        }
        if (picked === '__frame__') {
          var tagF = e.target && e.target.tagName;
          if (tagF === 'INPUT' || tagF === 'TEXTAREA' || tagF === 'SELECT') return;
          var stepF = e.shiftKey ? 2 : 0.5;
          var gF = frameGeom(current);
          var nx = gF.x, ny = gF.y;
          if (e.key === 'ArrowLeft') nx -= stepF;
          else if (e.key === 'ArrowRight') nx += stepF;
          else if (e.key === 'ArrowUp') ny -= stepF;
          else if (e.key === 'ArrowDown') ny += stepF;
          else if (e.key === 'Delete' || e.key === 'Backspace') {
            var gone = frameCopy(current);
            gone.style = 'none';
            current.frame_box = gone;
            current.frame = 'none';
            pick(null);
            fitMain();
            return;
          } else return;
          e.preventDefault();
          gF.x = Math.max(0, nx);
          gF.y = Math.max(0, ny);
          current.frame_box = gF;
          current.frame = gF.style;
          fitMain();
          return;
        }
        var tag = e.target && e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (e.key === 'Delete' || e.key === 'Backspace') { removeBlock(null); return; }
        var step = e.shiftKey ? 2 : 0.5;
        var dx = 0;
        var dy = 0;
        if (e.key === 'ArrowLeft') dx = -step;
        else if (e.key === 'ArrowRight') dx = step;
        else if (e.key === 'ArrowUp') dy = -step;
        else if (e.key === 'ArrowDown') dy = step;
        else return;
        e.preventDefault();
        eachSelected(function (item) {
          item.x = Math.max(0, item.x + dx);
          item.y = Math.max(0, item.y + dy);
          syncBlock(item);
        });
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
      try { bindProp(); } catch (err) {}
      try { showLedger(); } catch (err) {}
      if (cards) cards.addEventListener('click', function (e) {
        var card = e.target && e.target.closest ? e.target.closest('[data-tpl]') : null;
        if (!card || (e.target.closest && e.target.closest('.inv-card-del'))) return;
        if (card.querySelector && card.querySelector('.inv-thumb')) return;
        var id = card.getAttribute('data-tpl');
        if (id) openPreview(id);
      });
    })();
    </script>
    <?php
}
