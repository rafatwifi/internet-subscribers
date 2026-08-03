const express = require('express');
const qrcodeTerminal = require('qrcode-terminal');
const QRCode = require('qrcode');
const fs = require('fs');
const path = require('path');
const pino = require('pino');

const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  delay,
  Browsers
} = require('@whiskeysockets/baileys');

const PORT = process.env.PORT || 3001;
const API_KEY = process.env.API_KEY || 'local-secret-change-me';
const AUTH_DIR = path.join(__dirname, 'auth');
const SEND_DELAY_MS = Number(process.env.SEND_DELAY_MS || 2500);

let sock = null;
let ready = false;
let lastQr = null;
let lastQrDataUrl = null;
let connectedPhone = null;
let starting = false;
let ignoreReconnect = false;
let failCount = 0;
let reconnectTimer = null;
let lastStatusMsg = 'starting';

function ensureAuthDir() {
  if (!fs.existsSync(AUTH_DIR)) fs.mkdirSync(AUTH_DIR, { recursive: true });
}

function wipeAuthDir() {
  try {
    ensureAuthDir();
    const files = fs.readdirSync(AUTH_DIR);
    for (let i = 0; i < files.length; i++) {
      try { fs.unlinkSync(path.join(AUTH_DIR, files[i])); } catch (e) {}
    }
  } catch (e) {
    console.error('wipeAuthDir', e);
  }
}

function clearReconnectTimer() {
  if (reconnectTimer) {
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  }
}

function scheduleReconnect(ms, wipeFirst) {
  clearReconnectTimer();
  reconnectTimer = setTimeout(function () {
    reconnectTimer = null;
    if (wipeFirst) {
      console.log('مسح الجلسة وبدء من جديد...');
      wipeAuthDir();
      failCount = 0;
    }
    startSocket().catch(console.error);
  }, ms);
}

function normalizePhone(phone) {
  let p = String(phone || '').replace(/\D+/g, '');
  if (p.startsWith('07') && p.length === 11) p = '964' + p.slice(1);
  if (p.startsWith('7') && p.length === 10) p = '964' + p;
  if (p.startsWith('9640')) p = '964' + p.slice(4);
  return p;
}

async function startSocket() {
  if (starting) return;
  starting = true;
  ready = false;
  connectedPhone = null;
  lastStatusMsg = 'connecting';

  try {
    if (sock) {
      try { sock.ev.removeAllListeners('connection.update'); } catch (e) {}
      try { sock.ev.removeAllListeners('creds.update'); } catch (e) {}
      try { sock.end(undefined); } catch (e) {}
      sock = null;
    }

    ensureAuthDir();
    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);

    // بدون تثبيت version يدوي — يقلل أخطاء 500
    sock = makeWASocket({
      auth: state,
      logger: pino({ level: 'silent' }),
      printQRInTerminal: false,
      syncFullHistory: false,
      markOnlineOnConnect: false,
      generateHighQualityLinkPreview: false,
      browser: Browsers && Browsers.ubuntu ? Browsers.ubuntu('Chrome') : ['Ubuntu', 'Chrome', '22.04.4']
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
      const { connection, lastDisconnect, qr } = update;

      if (qr) {
        ready = false;
        lastQr = qr;
        failCount = 0;
        lastStatusMsg = 'qr_ready';
        try {
          lastQrDataUrl = await QRCode.toDataURL(qr, { width: 360, margin: 2 });
        } catch (e) {
          lastQrDataUrl = null;
        }
        console.log('\n========== امسح QR من واتساب ==========');
        qrcodeTerminal.generate(qr, { small: true });
        console.log('========================================');
        console.log('أو افتح بالمتصفح على الحاسبة:');
        console.log('http://127.0.0.1:' + PORT + '/link?key=' + API_KEY);
        console.log('أو من الشبكة:');
        console.log('http://172.16.16.13:' + PORT + '/link?key=' + API_KEY);
      }

      if (connection === 'open') {
        ready = true;
        lastQr = null;
        lastQrDataUrl = null;
        ignoreReconnect = false;
        failCount = 0;
        lastStatusMsg = 'connected';
        try {
          connectedPhone = sock.user && sock.user.id ? String(sock.user.id).split(':')[0] : null;
        } catch (e) {
          connectedPhone = null;
        }
        console.log('WhatsApp connected.', connectedPhone || '');
      }

      if (connection === 'close') {
        ready = false;
        connectedPhone = null;
        const err = lastDisconnect && lastDisconnect.error ? lastDisconnect.error : null;
        const code = (err && err.output && err.output.statusCode) || 0;
        const loggedOut = code === DisconnectReason.loggedOut;
        lastStatusMsg = 'closed_' + code;
        console.log('Connection closed. code=', code);

        if (ignoreReconnect || loggedOut) {
          if (loggedOut) {
            wipeAuthDir();
            console.log('Logged out. امسح auth وانتظر QR بعد إعادة التشغيل.');
          }
          return;
        }

        failCount += 1;
        // تهدئة قوية عند 500
        let waitMs = 5000;
        if (code === 500 || code === 515 || code === 408 || code === 428) {
          waitMs = Math.min(60000, 8000 * failCount);
        } else {
          waitMs = Math.min(20000, 3000 * failCount);
        }
        const wipe = failCount >= 2;
        console.log('إعادة محاولة بعد', Math.round(waitMs / 1000), 'ثواني', wipe ? '(مع مسح جلسة)' : '');
        scheduleReconnect(waitMs, wipe);
      }
    });
  } catch (e) {
    console.error('startSocket error', e);
    lastStatusMsg = 'error';
    scheduleReconnect(8000, false);
  } finally {
    starting = false;
  }
}

async function hardLogout() {
  ignoreReconnect = true;
  clearReconnectTimer();
  ready = false;
  lastQr = null;
  lastQrDataUrl = null;
  connectedPhone = null;
  failCount = 0;
  lastStatusMsg = 'logout';

  if (sock) {
    try { await sock.logout(); } catch (e) {}
    try { sock.end(undefined); } catch (e) {}
    sock = null;
  }
  wipeAuthDir();
  ensureAuthDir();

  setTimeout(function () {
    ignoreReconnect = false;
    startSocket().catch(console.error);
  }, 3000);

  return { success: true, message: 'Logged out. Waiting for new QR...' };
}

async function sendText(phone, message) {
  if (!sock || !ready) throw new Error('WhatsApp not ready. Scan QR first.');
  const normalized = normalizePhone(phone);
  const jid = normalized + '@s.whatsapp.net';
  const info = await sock.onWhatsApp(normalized);
  const exists = Array.isArray(info) && info[0] && info[0].exists;
  if (!exists) {
    const err = new Error('Number not on WhatsApp: ' + normalized);
    err.code = 'no_whatsapp';
    throw err;
  }
  const realJid = (info[0].jid) ? info[0].jid : jid;
  await delay(SEND_DELAY_MS);
  return sock.sendMessage(realJid, { text: String(message) });
}

const app = express();
app.use(express.json({ limit: '100kb' }));
app.use(function (req, res, next) {
  res.header('Access-Control-Allow-Origin', '*');
  res.header('Access-Control-Allow-Headers', 'Content-Type, X-Api-Key');
  res.header('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});

function checkKey(req, res, next) {
  const key = req.headers['x-api-key'] || (req.query && req.query.key);
  if (key !== API_KEY) return res.status(403).json({ success: false, error: 'Forbidden' });
  next();
}

app.get('/status', checkKey, (req, res) => {
  res.json({
    success: true,
    ready: ready,
    has_qr: !!lastQr,
    phone: connectedPhone,
    status: lastStatusMsg
  });
});

app.get('/qr', checkKey, async (req, res) => {
  try {
    if (ready) {
      return res.json({ success: true, ready: true, phone: connectedPhone, qr_data_url: null });
    }
    if (!lastQr) {
      return res.json({ success: true, ready: false, qr_data_url: null, message: 'Waiting for QR...', status: lastStatusMsg });
    }
    let dataUrl = lastQrDataUrl;
    if (!dataUrl) {
      dataUrl = await QRCode.toDataURL(lastQr, { width: 360, margin: 2 });
      lastQrDataUrl = dataUrl;
    }
    res.json({ success: true, ready: false, qr_data_url: dataUrl });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message || String(err) });
  }
});

// صفحة QR محلية — افتحها من أي جهاز على الشبكة
app.get('/link', checkKey, (req, res) => {
  const img = lastQrDataUrl
    ? '<img src="' + lastQrDataUrl + '" style="width:320px;height:320px;background:#fff;padding:12px;border-radius:12px">'
    : '<div style="padding:40px;border:2px dashed #999;border-radius:12px">بانتظار QR... حدّث الصفحة بعد ثواني<br><small>' + lastStatusMsg + '</small></div>';
  const phone = connectedPhone ? ('<p style="color:green">متصل: ' + connectedPhone + '</p>') : '';
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.end(
    '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">' +
    '<meta http-equiv="refresh" content="3">' +
    '<title>WhatsApp QR</title>' +
    '<style>body{font-family:Tahoma,Arial;display:grid;place-items:center;min-height:100vh;background:#eef2f6;margin:0}' +
    '.box{background:#fff;padding:24px;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.08);text-align:center}</style></head><body>' +
    '<div class="box"><h2>امسح QR من واتساب</h2>' + phone + img +
    '<p>الأجهزة المرتبطة ← ربط جهاز</p></div></body></html>'
  );
});

async function logoutHandler(req, res) {
  res.json({ success: true, message: 'Logged out. Waiting for new QR...' });
  setImmediate(function () {
    hardLogout().catch(console.error);
  });
}
app.post('/logout', checkKey, logoutHandler);
app.get('/logout', checkKey, logoutHandler);

app.post('/send', checkKey, async (req, res) => {
  try {
    const body = req.body || {};
    if (!body.phone || !body.message) {
      return res.status(400).json({ success: false, error: 'phone and message required' });
    }
    const result = await sendText(body.phone, body.message);
    res.json({ success: true, result: result });
  } catch (err) {
    const msg = (err && err.message) ? err.message : String(err);
    const payload = { success: false, error: msg };
    if ((err && err.code === 'no_whatsapp') || /not on WhatsApp/i.test(msg)) {
      payload.code = 'no_whatsapp';
    }
    res.status(500).json(payload);
  }
});

app.listen(PORT, '0.0.0.0', () => {
  console.log('WhatsApp gateway on http://0.0.0.0:' + PORT);
  console.log('QR page: http://127.0.0.1:' + PORT + '/link?key=' + API_KEY);
  startSocket().catch(console.error);
});
