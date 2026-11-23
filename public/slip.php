<?php
$slipIsPost = isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST';
if ($slipIsPost) {
    require_once __DIR__ . '/../includes/bootstrap.php';
}
require_once __DIR__ . '/../includes/invoice_studio.php';

if ($slipIsPost) {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('require_login')) {
        require_login();
    }
    if (!function_exists('verify_csrf') || !verify_csrf(function_exists('post') ? (string) post('csrf') : '')) {
        echo json_encode(array('ok' => false, 'message' => 'طلب غير صالح'));
        exit;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $act = function_exists('post') ? trim((string) post('action', 'issue')) : 'issue';
    if ($act === 'print') {
        $tok = function_exists('post') ? (string) post('token', '') : '';
        $slipPrint = function_exists('invoice_slip_agent_may')
            ? invoice_slip_agent_may($tid, $tok)
            : invoice_slip_load($tok);
        if (!is_array($slipPrint)) {
            echo json_encode(array('ok' => false, 'printed' => false, 'message' => 'الفاتورة غير موجودة'));
            exit;
        }
        $res = function_exists('invoice_slip_print_default')
            ? invoice_slip_print_default($tid, $slipPrint)
            : array('ok' => false, 'via' => '', 'message' => 'الطباعة غير جاهزة');
        echo json_encode(array(
            'ok' => !empty($res['ok']),
            'printed' => !empty($res['ok']),
            'via' => isset($res['via']) ? $res['via'] : '',
            'message' => isset($res['message']) ? $res['message'] : '',
            'token' => isset($slipPrint['token']) ? $slipPrint['token'] : $tok,
            'url' => isset($slipPrint['url']) ? $slipPrint['url'] : ('slip.php?k=' . invoice_slip_token_clean($tok)),
        ));
        exit;
    }
    if ($act === 'send') {
        global $config;
        $tok = function_exists('post') ? (string) post('token', '') : '';
        $slipSend = function_exists('invoice_slip_agent_may')
            ? invoice_slip_agent_may($tid, $tok)
            : invoice_slip_load($tok);
        if (!is_array($slipSend)) {
            echo json_encode(array('ok' => false, 'message' => 'الفاتورة غير موجودة'));
            exit;
        }
        $fillPhone = function_exists('post') ? trim((string) post('phone', '')) : '';
        if ($fillPhone !== '' && trim((string) (isset($slipSend['phone']) ? $slipSend['phone'] : '')) === '') {
            $slipSend['phone'] = $fillPhone;
        }
        list($sentOk, $sentMsg) = invoice_slip_send_whatsapp($config, $slipSend);
        echo json_encode(array('ok' => (bool) $sentOk, 'message' => (string) $sentMsg));
        exit;
    }
    $username = trim((string) (function_exists('post') ? post('username') : ''));
    if ($username === '') {
        echo json_encode(array('ok' => false, 'message' => 'اليوزر ناقص'));
        exit;
    }
    $start = trim((string) (function_exists('post') ? post('start') : ''));
    $end = trim((string) (function_exists('post') ? post('end') : ''));
    if (($start === '' || $end === '') && isset($pdo)) {
        try {
            $stExp = $pdo->prepare('SELECT expire_at FROM sas_users_cache WHERE tenant_id = :t AND username = :u LIMIT 1');
            $stExp->execute(array(':t' => $tid, ':u' => $username));
            $exp = $stExp->fetchColumn();
            $expTs = $exp ? strtotime((string) $exp) : 0;
            if ($end === '' && $expTs > 0) {
                $end = date('Y-m-d', $expTs);
            }
        } catch (Exception $eExp) {
        }
    }
    if ($start === '') {
        $start = date('Y-m-d');
    }
    $pkg = function_exists('post') ? trim((string) post('package')) : '';
    $amount = function_exists('post') ? trim((string) post('amount')) : '';
    if ($amount === '' && $pkg !== '' && isset($pdo) && function_exists('report_resolve_sell_price')) {
        $sell = (float) report_resolve_sell_price($pdo, $pkg, 0);
        if ($sell > 0) {
            $amount = function_exists('money_format_iqd')
                ? money_format_iqd($sell, isset($config['currency']) ? $config['currency'] : 'د.ع')
                : (string) (int) $sell;
        }
    }
    $design = invoice_design_load($tid);
    $slip = invoice_slip_issue($tid, array(
        'username' => $username,
        'name' => function_exists('post') ? (string) post('name') : '',
        'phone' => function_exists('post') ? (string) post('phone') : '',
        'package' => $pkg,
        'amount' => $amount,
        'start' => $start,
        'end' => $end,
        'title' => function_exists('post') ? (string) post('title') : 'وصل',
    ), $design);
    if (!$slip) {
        echo json_encode(array('ok' => false, 'message' => 'تعذر حفظ الوصل'));
        exit;
    }
    echo json_encode(array('ok' => true, 'url' => $slip['url'], 'token' => $slip['token']));
    exit;
}

$tokenIn = isset($_GET['k']) ? (string) $_GET['k'] : '';
if (!preg_match('/^[a-f0-9]{16,64}$/', strtolower($tokenIn))) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'الوصل غير موجود';
    exit;
}
$slip = invoice_slip_load($tokenIn);
if (!$slip) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'الوصل غير موجود';
    exit;
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\' data:; style-src \'self\' \'unsafe-inline\'; script-src \'self\' \'unsafe-inline\'; font-src \'self\'; base-uri \'none\'; form-action \'none\'; frame-ancestors \'self\'');
header('Cache-Control: private, no-store');
$tokenShown = invoice_slip_token_clean($tokenIn);
$slip['url'] = invoice_request_base() . 'slip.php?k=' . $tokenShown;
$autoPrint = isset($_GET['print']) && (string) $_GET['print'] === '1';
$printOnce = isset($_GET['once']) && (string) $_GET['once'] === '1';
$w = isset($slip['paper_w']) ? (int) $slip['paper_w'] : 80;
if ($w < 48) {
    $w = 80;
}
$h = isset($slip['paper_h']) ? (float) $slip['paper_h'] : 0;
$frame = isset($slip['frame']) ? (string) $slip['frame'] : 'line';
if (!in_array($frame, array('none', 'line', 'double', 'dashed', 'round'), true)) {
    $frame = 'line';
}
$logo = function_exists('invoice_slip_safe_asset') ? invoice_slip_safe_asset(isset($slip['logo']) ? $slip['logo'] : '') : '';
$company = isset($slip['company_name']) && $slip['company_name'] !== '' ? $slip['company_name'] : 'الوصل';
$cphone = isset($slip['company_phone']) ? (string) $slip['company_phone'] : '';
$address = isset($slip['address']) ? (string) $slip['address'] : '';
$rows = array(
    array('المشترك', isset($slip['name']) ? $slip['name'] : ''),
    array('اليوزر', isset($slip['username']) ? $slip['username'] : ''),
    array('الهاتف', isset($slip['phone']) ? $slip['phone'] : ''),
    array('الباقة', isset($slip['package']) ? $slip['package'] : ''),
    array('من', isset($slip['start']) ? $slip['start'] : ''),
    array('إلى', isset($slip['end']) ? $slip['end'] : ''),
);
$amount = isset($slip['amount']) ? trim((string) $slip['amount']) : '';
$title = isset($slip['title']) ? (string) $slip['title'] : 'وصل';
$when = isset($slip['created_at']) ? (string) $slip['created_at'] : '';
$whenDate = strlen($when) >= 10 ? substr($when, 0, 10) : $when;
$whenTime = strlen($when) >= 16 ? substr($when, 11, 8) : '';
$number = isset($slip['number']) && $slip['number'] !== '' ? (string) $slip['number'] : '';
$footer = isset($slip['footer']) ? (string) $slip['footer'] : '';
$url = isset($slip['url']) ? (string) $slip['url'] : '';
$designedH = 0;
$designed = function_exists('invoice_slip_design_html') ? invoice_slip_design_html($slip, $designedH) : '';
$minH = $designedH > 0 ? (float) $designedH : ($h >= 40 ? $h : 0);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<style>
  @font-face {
    font-family: "Tajawal";
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url("assets/fonts/tajawal-400-ar.woff2") format("woff2");
    unicode-range: U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FEFC, U+10E60-10E7E, U+1EE00-1EEFF;
  }
  @font-face {
    font-family: "Tajawal";
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url("assets/fonts/tajawal-400-lat.woff2") format("woff2");
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
  }
  @font-face {
    font-family: "Tajawal";
    font-style: normal;
    font-weight: 500;
    font-display: swap;
    src: url("assets/fonts/tajawal-500-ar.woff2") format("woff2");
    unicode-range: U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FEFC, U+10E60-10E7E, U+1EE00-1EEFF;
  }
  @font-face {
    font-family: "Tajawal";
    font-style: normal;
    font-weight: 500;
    font-display: swap;
    src: url("assets/fonts/tajawal-500-lat.woff2") format("woff2");
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
  }
  @font-face {
    font-family: "Tajawal";
    font-style: normal;
    font-weight: 700;
    font-display: swap;
    src: url("assets/fonts/tajawal-700-ar.woff2") format("woff2");
    unicode-range: U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FEFC, U+10E60-10E7E, U+1EE00-1EEFF;
  }
  @font-face {
    font-family: "Tajawal";
    font-style: normal;
    font-weight: 700;
    font-display: swap;
    src: url("assets/fonts/tajawal-700-lat.woff2") format("woff2");
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
  }
  html, body { margin: 0; background: #e8eef5; }
  body {
    min-height: 100vh; display: flex; justify-content: center; align-items: flex-start;
    padding: 12px; box-sizing: border-box;
    font-family: "Tajawal", "Segoe UI", Tahoma, Arial, sans-serif;
  }
  .slip, .slip-design { max-width: none; }
  .slip {
    width: <?php echo (int) $w; ?>mm;
    <?php if ($minH > 0): ?>min-height: <?php echo (float) $minH; ?>mm;<?php endif; ?>
    background: #fff;
    color: #0f172a;
    box-shadow: 0 18px 50px rgba(15,23,42,.16);
    padding: 7mm 5mm 6mm;
    box-sizing: border-box;
    position: relative;
  }
  .slip.slip-design { padding: 0 !important; width: <?php echo (int) $w; ?>mm !important; max-width: none !important; }
  .slip.frame-line { border: 1.5px solid #0f172a; }
  .slip.frame-double { border: 4px double #0f172a; }
  .slip.frame-dashed { border: 1.5px dashed #334155; }
  .slip.frame-round { border: 1.5px solid #0f172a; border-radius: 4mm; }
  .slip-logo { display: block; width: 18mm; height: 18mm; object-fit: contain; margin: 0 auto 3mm; }
  .slip h1 { margin: 0; text-align: center; font-size: 4.2mm; font-weight: 800; }
  .slip-no { margin: 3mm 0 0; text-align: center; font-size: 4mm; font-weight: 800; letter-spacing: .04em; }
  .slip-when { margin: 1mm 0 0; text-align: center; color: #334155; font-size: 3.2mm; font-weight: 700; }
  .slip-rule { height: 0; border: 0; border-top: 1px solid #e2e8f0; margin: 3.5mm 0; }
  .slip-title { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; font-size: 3.2mm; font-weight: 800; }
  .slip-title span { color: #0f766e; }
  .slip-row { display: flex; justify-content: space-between; gap: 8px; padding: 1.6mm 0; border-bottom: 1px dashed #e2e8f0; font-size: 3.2mm; }
  .slip-row em { font-style: normal; color: #64748b; font-weight: 700; }
  .slip-row b { font-weight: 800; text-align: left; }
  .slip-pay { margin-top: 3mm; background: #f0fdfa; border-radius: 3mm; padding: 3mm 3.5mm; display: flex; justify-content: space-between; align-items: center; }
  .slip-pay span { color: #0f766e; font-weight: 800; font-size: 3.2mm; }
  .slip-pay strong { font-size: 4.8mm; letter-spacing: .01em; }
  .slip-qr { display: block; width: 28mm; height: 28mm; margin: 4mm auto 1mm; background: #fff; }
  .slip-hint { margin: 0; text-align: center; color: #94a3b8; font-size: 2.6mm; font-weight: 700; }
  .slip-foot { margin: 4mm 0 0; text-align: center; color: #94a3b8; font-size: 2.6mm; font-weight: 800; }
  .slip, .slip-design, .slip-line, .slip-pair {
    font-family: "Tajawal", "Segoe UI", Tahoma, Arial, sans-serif;
    font-weight: 400;
  }
  .slip-design { box-shadow: 0 18px 50px rgba(15,23,42,.16); }
  .slip-design .slip-foot { margin: 0; }
  @media print {
    html, body {
      background: #fff !important; padding: 0 !important; margin: 0 !important;
      min-height: 0 !important; height: auto !important; width: auto !important;
      display: block !important; overflow: visible !important;
    }
    .slip-scale {
      width: auto !important; height: auto !important; margin: 0 !important;
      overflow: visible !important; transform: none !important;
    }
    .slip, .slip-design {
      box-shadow: none !important; margin: 0 !important; transform: none !important;
      overflow: visible !important;
      page-break-after: avoid !important; page-break-inside: avoid !important;
    }
    .slip-line, .slip-pair, .slip-foot {
      overflow: visible !important;
    }
    @page { size: <?php echo (int) $w; ?>mm <?php echo $minH > 0 ? ((float) $minH . 'mm') : 'auto'; ?>; margin: 0; }
  }
</style>
</head>
<body>
<?php if ($designed !== ''): ?>
<?php echo $designed; ?>
<?php else: ?>
<article class="slip frame-<?php echo htmlspecialchars($frame, ENT_QUOTES, 'UTF-8'); ?>">
  <?php if ($logo !== ''): ?><img class="slip-logo" src="<?php echo htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php endif; ?>
  <h1><?php echo htmlspecialchars($company, ENT_QUOTES, 'UTF-8'); ?></h1>
  <?php if ($cphone !== '' || $address !== ''): ?>
    <p class="slip-sub"><?php echo htmlspecialchars(trim($cphone . ($cphone !== '' && $address !== '' ? ' · ' : '') . $address), ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>
  <?php if ($number !== ''): ?><p class="slip-no" dir="ltr"><?php echo htmlspecialchars($number, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
  <?php if ($whenDate !== '' || $whenTime !== ''): ?>
    <p class="slip-when" dir="ltr"><?php echo htmlspecialchars(trim($whenDate . '  ' . $whenTime), ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>
  <hr class="slip-rule">
  <div class="slip-title"><span><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span></div>
  <hr class="slip-rule">
  <?php if ($number !== ''): ?>
    <div class="slip-row"><em>رقم الفاتورة</em><b dir="ltr"><?php echo htmlspecialchars($number, ENT_QUOTES, 'UTF-8'); ?></b></div>
  <?php endif; ?>
  <?php if ($whenDate !== ''): ?>
    <div class="slip-row"><em>التاريخ</em><b dir="ltr"><?php echo htmlspecialchars($whenDate, ENT_QUOTES, 'UTF-8'); ?></b></div>
  <?php endif; ?>
  <?php if ($whenTime !== ''): ?>
    <div class="slip-row"><em>الوقت</em><b dir="ltr"><?php echo htmlspecialchars($whenTime, ENT_QUOTES, 'UTF-8'); ?></b></div>
  <?php endif; ?>
  <?php foreach ($rows as $pair): if (trim((string) $pair[1]) === '') continue; ?>
    <div class="slip-row"><em><?php echo htmlspecialchars($pair[0], ENT_QUOTES, 'UTF-8'); ?></em><b><?php echo htmlspecialchars($pair[1], ENT_QUOTES, 'UTF-8'); ?></b></div>
  <?php endforeach; ?>
  <?php if ($amount !== ''): ?>
    <div class="slip-pay"><span>المبلغ</span><strong dir="ltr"><?php echo htmlspecialchars($amount, ENT_QUOTES, 'UTF-8'); ?></strong></div>
  <?php endif; ?>
  <?php if ($url !== ''): ?><img class="slip-qr" alt="" data-qr="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
  <p class="slip-hint">امسح الرمز لعرض نفس الوصل</p>
  <?php if ($footer !== ''): ?><p class="slip-foot"><?php echo htmlspecialchars($footer, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
</article>
<?php endif; ?>
<script src="assets/qrcode.js"></script>
<script>
(function () {
  var autoPrint = <?php echo $autoPrint ? 'true' : 'false'; ?>;
  var printOnce = <?php echo $printOnce ? 'true' : 'false'; ?>;
  var tok = <?php echo json_encode($tokenShown); ?>;
  var paper = document.querySelector('.slip, .slip-design');
  function fitPaper() {
    if (autoPrint || !paper || !window.frameElement) return;
    var wrap = paper.parentNode;
    if (!wrap || !wrap.classList || !wrap.classList.contains('slip-scale')) {
      wrap = document.createElement('div');
      wrap.className = 'slip-scale';
      paper.parentNode.insertBefore(wrap, paper);
      wrap.appendChild(paper);
    }
    paper.style.transform = 'none';
    var avail = Math.max(40, document.documentElement.clientWidth - 28);
    var w = paper.offsetWidth || paper.scrollWidth;
    var h = paper.offsetHeight || paper.scrollHeight;
    var scale = w > avail ? (avail / w) : 1;
    paper.style.transformOrigin = 'top center';
    paper.style.transform = 'scale(' + scale + ')';
    paper.style.display = 'block';
    wrap.style.width = Math.ceil(w * scale) + 'px';
    wrap.style.height = Math.ceil(h * scale) + 'px';
    wrap.style.margin = '12px auto';
    wrap.style.overflow = 'visible';
    document.body.style.display = 'block';
    document.body.style.padding = '0';
    document.documentElement.style.overflow = 'auto';
  }
  fitPaper();
  window.addEventListener('resize', fitPaper);
  var img = document.querySelector('[data-qr]');
  if (img && window.qrcode) {
    try {
      if (window.qrcode.stringToBytesFuncs && window.qrcode.stringToBytesFuncs['UTF-8']) {
        window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];
      }
      var qr = window.qrcode(0, 'M');
      qr.addData(img.getAttribute('data-qr') || '');
      qr.make();
      img.src = qr.createDataURL(4, 0);
    } catch (e) {}
  }
  if (!autoPrint) return;
  var agentPrint = /(?:\?|&)agent=1(?:&|$)/.test(location.search);
  var key = 'slip_printed_' + tok;
  try {
    if (!agentPrint && printOnce && sessionStorage.getItem(key) === '1') return;
    if (printOnce) sessionStorage.setItem(key, '1');
  } catch (eKey) {}
  var printed = false;
  function doPrint() {
    if (printed) return;
    printed = true;
    try { window.print(); } catch (ePrint) {}
    try { parent.postMessage({ type: 'slip-printed', token: tok }, '*'); } catch (eMsg) {}
    if (agentPrint) {
      setTimeout(function () { try { window.close(); } catch (eClose) {} }, 4000);
    }
  }
  if (img && !img.complete) {
    img.addEventListener('load', function () { setTimeout(doPrint, 120); });
    img.addEventListener('error', function () { setTimeout(doPrint, 120); });
    setTimeout(doPrint, 900);
  } else {
    setTimeout(doPrint, 350);
  }
})();
</script>
</body>
</html>
