<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/invoice_studio.php';

if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('require_login')) {
        require_login();
    }
    if (!function_exists('verify_csrf') || !verify_csrf(function_exists('post') ? (string) post('csrf') : '')) {
        echo json_encode(array('ok' => false, 'message' => 'طلب غير صالح'));
        exit;
    }
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    $username = trim((string) (function_exists('post') ? post('username') : ''));
    if ($username === '') {
        echo json_encode(array('ok' => false, 'message' => 'اليوزر ناقص'));
        exit;
    }
    $design = invoice_design_load($tid);
    $slip = invoice_slip_issue($tid, array(
        'username' => $username,
        'name' => function_exists('post') ? (string) post('name') : '',
        'phone' => function_exists('post') ? (string) post('phone') : '',
        'package' => function_exists('post') ? (string) post('package') : '',
        'amount' => function_exists('post') ? (string) post('amount') : '',
        'start' => function_exists('post') ? (string) post('start') : '',
        'end' => function_exists('post') ? (string) post('end') : '',
        'title' => function_exists('post') ? (string) post('title') : 'وصل',
    ), $design);
    if (!$slip) {
        echo json_encode(array('ok' => false, 'message' => 'تعذر حفظ الوصل'));
        exit;
    }
    echo json_encode(array('ok' => true, 'url' => $slip['url'], 'token' => $slip['token']));
    exit;
}

$slip = invoice_slip_load(isset($_GET['k']) ? (string) $_GET['k'] : '');
if (!$slip) {
    http_response_code(404);
    echo 'الوصل غير موجود';
    exit;
}
$autoPrint = isset($_GET['print']);
$w = isset($slip['paper_w']) ? (int) $slip['paper_w'] : 80;
if ($w < 48) {
    $w = 80;
}
$h = isset($slip['paper_h']) ? (float) $slip['paper_h'] : 0;
$frame = isset($slip['frame']) ? (string) $slip['frame'] : 'line';
if (!in_array($frame, array('none', 'line', 'double', 'dashed', 'round'), true)) {
    $frame = 'line';
}
$logo = isset($slip['logo']) ? (string) $slip['logo'] : '';
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
$minH = $h >= 40 ? $h : 0;
$designed = function_exists('invoice_slip_design_html') ? invoice_slip_design_html($slip) : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="stylesheet" href="assets/style.css?v=ui26">
<style>
  html, body { margin: 0; background: #e8eef5; }
  body { min-height: 100vh; display: flex; justify-content: center; padding: 18px 10px 28px; }
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
  .slip.frame-line { border: 1.5px solid #0f172a; }
  .slip.frame-double { border: 4px double #0f172a; }
  .slip.frame-dashed { border: 1.5px dashed #334155; }
  .slip.frame-round { border: 1.5px solid #0f172a; border-radius: 4mm; }
  .slip-logo { display: block; width: 18mm; height: 18mm; object-fit: contain; margin: 0 auto 3mm; }
  .slip h1 { margin: 0; text-align: center; font-size: 16px; font-weight: 800; }
  .slip-no { margin: 3mm 0 0; text-align: center; font-size: 15px; font-weight: 800; letter-spacing: .04em; }
  .slip-when { margin: 1mm 0 0; text-align: center; color: #334155; font-size: 12px; font-weight: 700; }
  .slip-rule { height: 0; border: 0; border-top: 1px solid #e2e8f0; margin: 3.5mm 0; }
  .slip-title { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; font-size: 12px; font-weight: 800; }
  .slip-title span { color: #0f766e; }
  .slip-row { display: flex; justify-content: space-between; gap: 8px; padding: 1.6mm 0; border-bottom: 1px dashed #e2e8f0; font-size: 12px; }
  .slip-row em { font-style: normal; color: #64748b; font-weight: 700; }
  .slip-row b { font-weight: 800; text-align: left; }
  .slip-pay { margin-top: 3mm; background: #f0fdfa; border-radius: 3mm; padding: 3mm 3.5mm; display: flex; justify-content: space-between; align-items: center; }
  .slip-pay span { color: #0f766e; font-weight: 800; font-size: 12px; }
  .slip-pay strong { font-size: 18px; letter-spacing: .01em; }
  .slip-qr { display: block; width: 28mm; height: 28mm; margin: 4mm auto 1mm; background: #fff; }
  .slip-hint { margin: 0; text-align: center; color: #94a3b8; font-size: 10px; font-weight: 700; }
  .slip-foot { margin: 4mm 0 0; text-align: center; color: #94a3b8; font-size: 10px; font-weight: 800; }
  @media print {
    html, body { background: #fff; padding: 0; }
    .slip { box-shadow: none; margin: 0; }
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
  var img = document.querySelector('[data-qr]');
  if (!img || !window.qrcode) return;
  try {
    if (window.qrcode.stringToBytesFuncs && window.qrcode.stringToBytesFuncs['UTF-8']) {
      window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];
    }
    var qr = window.qrcode(0, 'M');
    qr.addData(img.getAttribute('data-qr') || '');
    qr.make();
    img.src = qr.createDataURL(4, 0);
  } catch (e) {}
  <?php if ($autoPrint): ?>
  setTimeout(function () { try { window.print(); } catch (e) {} }, 400);
  <?php endif; ?>
})();
</script>
</body>
</html>
