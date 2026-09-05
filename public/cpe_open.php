<?php

/**
 * فتح واجهة CPE بتسجيل دخول تلقائي (مثل رابط ticket في UISP).
 * يحاول استخراج ticket ثم التحويل إلى /ticket.cgi?ticketid=…
 * وإلا يرسل نموذج login.cgi تلقائياً للمتصفح.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$ip = isset($_GET['ip']) ? trim((string) $_GET['ip']) : '';
if ($ip === '' || (!filter_var($ip, FILTER_VALIDATE_IP) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))) {
    http_response_code(400);
    echo 'Invalid IP';
    exit;
}

$user = 'ubnt';
$pass = 'ubnt';
if (isset($config['cpe_http_user']) && trim((string) $config['cpe_http_user']) !== '') {
    $user = trim((string) $config['cpe_http_user']);
}
if (isset($config['cpe_http_pass'])) {
    $pass = (string) $config['cpe_http_pass'];
}

$preferHttps = !isset($config['cpe_use_https']) || !empty($config['cpe_use_https']);
$schemes = $preferHttps ? array('https', 'http') : array('http', 'https');

/**
 * محاولة تسجيل دخول من السيرفر واستخراج ticketid إن وُجد.
 */
function cpe_try_fetch_ticket($scheme, $ip, $user, $pass)
{
    if (!function_exists('curl_init')) {
        return '';
    }
    $cookieFile = tempnam(sys_get_temp_dir(), 'cpe');
    if ($cookieFile === false) {
        $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cpe_' . md5($ip . microtime()) . '.txt';
    }
    $bases = array(
        $scheme . '://' . $ip,
    );
    $ticket = '';
    foreach ($bases as $base) {
        $ch = curl_init();
        if ($ch === false) {
            break;
        }
        $common = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_USERAGENT => 'Mozilla/5.0 WiFiNetSales-CPE/1.0',
            CURLOPT_HTTPHEADER => array('Expect:'),
        );
        // airOS 8+: /api/auth
        curl_setopt_array($ch, $common);
        curl_setopt($ch, CURLOPT_URL, $base . '/api/auth');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
            'username' => $user,
            'password' => $pass,
        )));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 200 && $code < 300 && is_string($body)) {
            if (preg_match('/ticketid["\']?\s*[:=]\s*["\']?([a-f0-9]{32})/i', $body, $m)) {
                $ticket = $m[1];
            }
        }
        // airOS أقدم: login.cgi
        if ($ticket === '') {
            curl_setopt_array($ch, $common);
            curl_setopt($ch, CURLOPT_URL, $base . '/login.cgi');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
                'username' => $user,
                'password' => $pass,
                'uri' => '/',
            )));
            $body2 = curl_exec($ch);
            $code2 = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (is_string($body2) && preg_match('/ticketid[=\"\'\s:]+([a-f0-9]{32})/i', $body2, $m2)) {
                $ticket = $m2[1];
            }
            if ($ticket === '' && $code2 > 0) {
                // بعض الأجهزة تضع الجلسة بالكوكي فقط — نكمل بالنموذج من المتصفح
            }
        }
        curl_close($ch);
        if ($ticket !== '') {
            break;
        }
    }
    if (is_string($cookieFile) && is_file($cookieFile)) {
        @unlink($cookieFile);
    }
    return $ticket;
}

$ticket = '';
$ticketScheme = $schemes[0];
foreach ($schemes as $sch) {
    $ticket = cpe_try_fetch_ticket($sch, $ip, $user, $pass);
    if ($ticket !== '') {
        $ticketScheme = $sch;
        break;
    }
}

if ($ticket !== '' && preg_match('/^[a-f0-9]{32}$/i', $ticket)) {
    $url = $ticketScheme . '://' . $ip . '/ticket.cgi?ticketid=' . rawurlencode($ticket);
    header('Location: ' . $url, true, 302);
    exit;
}

// Fallback: نموذج POST تلقائي إلى login.cgi (تسجيل مباشر بدون صفحة إدخال)
$scheme = $schemes[0];
$host = $ip;
$action = $scheme . '://' . $host . '/login.cgi';
$isEn = (isset($lang) && $lang === 'en');
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?php echo $isEn ? 'en' : 'ar'; ?>" dir="<?php echo $isEn ? 'ltr' : 'rtl'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($isEn ? 'Opening CPE…' : 'جاري فتح الجهاز…', ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        body{font-family:Tahoma,Arial,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
        .box{text-align:center;padding:24px}
        .spin{width:36px;height:36px;border:3px solid #334155;border-top-color:#38bdf8;border-radius:50%;margin:0 auto 14px;animation:s .7s linear infinite}
        @keyframes s{to{transform:rotate(360deg)}}
        a{color:#7dd3fc}
    </style>
</head>
<body>
<div class="box">
    <div class="spin" aria-hidden="true"></div>
    <p><?php echo htmlspecialchars($isEn ? 'Signing in to CPE…' : 'جاري تسجيل الدخول للجهاز…', ENT_QUOTES, 'UTF-8'); ?></p>
    <p style="opacity:.7;font-size:13px"><?php echo htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'); ?></p>
    <form id="cpeLogin" method="post" action="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="username" value="<?php echo htmlspecialchars($user, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="password" value="<?php echo htmlspecialchars($pass, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="uri" value="/">
        <noscript>
            <button type="submit"><?php echo htmlspecialchars($isEn ? 'Continue' : 'متابعة', ENT_QUOTES, 'UTF-8'); ?></button>
        </noscript>
    </form>
    <p style="margin-top:18px;font-size:12px;opacity:.65">
        <a href="<?php echo htmlspecialchars($scheme . '://' . $host . '/', ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
            <?php echo htmlspecialchars($isEn ? 'Open without login' : 'فتح بدون تسجيل', ENT_QUOTES, 'UTF-8'); ?>
        </a>
        ·
        <a href="<?php echo htmlspecialchars(($schemes[1]) . '://' . $host . '/login.cgi', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($isEn ? 'Try other protocol' : 'جرّب بروتوكول آخر', ENT_QUOTES, 'UTF-8'); ?>
        </a>
    </p>
</div>
<script>
(function () {
  try { document.getElementById('cpeLogin').submit(); } catch (e) {}
})();
</script>
</body>
</html>
