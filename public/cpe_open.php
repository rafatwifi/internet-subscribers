<?php

/**
 * فتح CPE من جهاز الفني (LAN) مباشرة — مثل رابط ticket في UISP.
 * السيرفر البعيد غالباً ما يوصل لـ IP الخاص؛ لذلك الدخول يتم من المتصفح.
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
$isEn = (isset($lang) && $lang === 'en');

// محاولة سريعة جداً من السيرفر (إن كان على نفس الشبكة) — بدون انتظار طويل
$ticket = '';
$ticketScheme = $preferHttps ? 'https' : 'http';
if (function_exists('curl_init')) {
    $schemes = $preferHttps ? array('https', 'http') : array('http', 'https');
    foreach ($schemes as $sch) {
        $ch = curl_init();
        if ($ch === false) {
            break;
        }
        curl_setopt_array($ch, array(
            CURLOPT_URL => $sch . '://' . $ip . '/api/auth',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array('username' => $user, 'password' => $pass)),
            CURLOPT_HTTPHEADER => array('Expect:'),
            CURLOPT_USERAGENT => 'Mozilla/5.0 WiFiNetSales-CPE/2.0',
        ));
        $body = curl_exec($ch);
        curl_close($ch);
        if (is_string($body) && preg_match('/([a-f0-9]{32})/i', $body, $m)) {
            if (preg_match('/ticketid["\']?\s*[:=]\s*["\']?([a-f0-9]{32})/i', $body, $m2)) {
                $ticket = $m2[1];
            } elseif (preg_match('/"ticket"\s*:\s*"([a-f0-9]{32})"/i', $body, $m3)) {
                $ticket = $m3[1];
            }
        }
        if ($ticket !== '') {
            $ticketScheme = $sch;
            break;
        }
    }
}

if ($ticket !== '' && preg_match('/^[a-f0-9]{32}$/i', $ticket)) {
    header('Location: ' . $ticketScheme . '://' . $ip . '/ticket.cgi?ticketid=' . rawurlencode($ticket), true, 302);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="<?php echo $isEn ? 'en' : 'ar'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($isEn ? 'Opening device…' : 'فتح الجهاز…', ENT_QUOTES, 'UTF-8'); ?></title>
<style>
body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#0b1220;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh}
.box{text-align:center;padding:20px;max-width:420px}
.spin{width:34px;height:34px;border:3px solid #334155;border-top-color:#38bdf8;border-radius:50%;margin:0 auto 12px;animation:s .65s linear infinite}
@keyframes s{to{transform:rotate(360deg)}}
small{opacity:.7}
</style>
</head>
<body>
<div class="box">
  <div class="spin" aria-hidden="true"></div>
  <p id="msg"><?php echo htmlspecialchars($isEn ? 'Opening CPE…' : 'جاري فتح الجهاز…', ENT_QUOTES, 'UTF-8'); ?></p>
  <small dir="ltr"><?php echo htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'); ?></small>
</div>
<script>
(function () {
  var ip = <?php echo json_encode($ip); ?>;
  var user = <?php echo json_encode($user); ?>;
  var pass = <?php echo json_encode($pass); ?>;
  var preferHttps = <?php echo $preferHttps ? 'true' : 'false'; ?>;
  var schemes = preferHttps ? ['https', 'http'] : ['http', 'https'];
  var msg = document.getElementById('msg');

  function extractTicket(text) {
    if (!text) return '';
    var m = String(text).match(/ticketid["']?\s*[:=]\s*["']?([a-f0-9]{32})/i);
    if (m) return m[1];
    m = String(text).match(/"ticket"\s*:\s*"([a-f0-9]{32})"/i);
    if (m) return m[1];
    m = String(text).match(/ticket\.cgi\?ticketid=([a-f0-9]{32})/i);
    if (m) return m[1];
    return '';
  }

  function goTicket(scheme, ticket) {
    location.replace(scheme + '://' + ip + '/ticket.cgi?ticketid=' + encodeURIComponent(ticket));
  }

  function postForm(action, fields) {
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = action;
    f.acceptCharset = 'UTF-8';
    Object.keys(fields).forEach(function (k) {
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = k;
      inp.value = fields[k];
      f.appendChild(inp);
    });
    document.body.appendChild(f);
    f.submit();
  }

  function tryFetchAuth(scheme) {
    var base = scheme + '://' + ip;
    var body = 'username=' + encodeURIComponent(user) + '&password=' + encodeURIComponent(pass);
    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var t = ctrl ? setTimeout(function () { try { ctrl.abort(); } catch (e) {} }, 3500) : null;
    return fetch(base + '/api/auth', {
      method: 'POST',
      mode: 'cors',
      credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      signal: ctrl ? ctrl.signal : undefined
    }).then(function (r) {
      return r.text().then(function (txt) {
        var ticket = extractTicket(txt);
        if (ticket) {
          goTicket(scheme, ticket);
          return true;
        }
        try {
          var j = JSON.parse(txt);
          if (j && (j.ticket || j.ticketid || (j.meta && j.meta.ticket))) {
            goTicket(scheme, j.ticket || j.ticketid || j.meta.ticket);
            return true;
          }
        } catch (e) {}
        return false;
      });
    }).catch(function () { return false; }).then(function (ok) {
      if (t) clearTimeout(t);
      return ok;
    });
  }

  function openViaBlobForm(scheme) {
    var actionLogin = scheme + '://' + ip + '/login.cgi';
    var actionApi = scheme + '://' + ip + '/api/auth';
    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>CPE</title></head><body>'
      + '<p style="font-family:Tahoma;text-align:center;margin-top:40px">Opening…</p>'
      + '<form id="a" method="post" action="' + actionApi + '">'
      + '<input type="hidden" name="username" value="' + String(user).replace(/"/g, '&quot;') + '">'
      + '<input type="hidden" name="password" value="' + String(pass).replace(/"/g, '&quot;') + '">'
      + '</form>'
      + '<form id="b" method="post" action="' + actionLogin + '">'
      + '<input type="hidden" name="username" value="' + String(user).replace(/"/g, '&quot;') + '">'
      + '<input type="hidden" name="password" value="' + String(pass).replace(/"/g, '&quot;') + '">'
      + '<input type="hidden" name="uri" value="/">'
      + '</form>'
      + '<script>(function(){try{document.getElementById("a").submit();}catch(e){try{document.getElementById("b").submit();}catch(e2){}}setTimeout(function(){try{document.getElementById("b").submit();}catch(e3){}},900);})();<\/script>'
      + '</body></html>';
    var blob = new Blob([html], { type: 'text/html' });
    var url = URL.createObjectURL(blob);
    location.replace(url);
  }

  (async function () {
    for (var i = 0; i < schemes.length; i++) {
      if (await tryFetchAuth(schemes[i])) return;
    }
    if (msg) msg.textContent = <?php echo json_encode($isEn ? 'Signing in…' : 'تسجيل الدخول للجهاز…'); ?>;
    // من blob حتى لا يمنع المتصفح POST من صفحة HTTPS إلى جهاز HTTP
    openViaBlobForm(schemes[0]);
    setTimeout(function () {
      try { openViaBlobForm(schemes[1] || schemes[0]); } catch (e) {}
    }, 1200);
  })();
})();
</script>
</body>
</html>
