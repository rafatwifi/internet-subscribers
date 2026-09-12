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
const SESSIONS_DIR = path.join(AUTH_DIR, 'sessions');
const SEND_DELAY_MS = Number(process.env.SEND_DELAY_MS || 2500);

function sanitizeSessionId(raw) {
  let s = String(raw || 'default').trim();
  s = s.replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 40);
  return s || 'default';
}

function resolveSessionId(req) {
  const fromQuery = req.query && req.query.session;
  const fromBody = req.body && req.body.session;
  const fromHeader = req.headers['x-wa-session'];
  return sanitizeSessionId(fromQuery || fromBody || fromHeader || 'default');
}

function ensureDir(dir) {
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

function listAuthFiles(dir) {
  if (!fs.existsSync(dir)) return [];
  try {
    return fs.readdirSync(dir).filter(function (name) {
      if (name === 'sessions') return false;
      try {
        return fs.statSync(path.join(dir, name)).isFile();
      } catch (e) {
        return false;
      }
    });
  } catch (e) {
    return [];
  }
}

function copyAuthFiles(fromDir, toDir) {
  ensureDir(toDir);
  listAuthFiles(fromDir).forEach(function (name) {
    const src = path.join(fromDir, name);
    const dst = path.join(toDir, name);
    if (!fs.existsSync(dst)) {
      try {
        fs.copyFileSync(src, dst);
      } catch (e) {}
    }
  });
}

function resolveAuthDir(sessionId) {
  if (sessionId === 'default') {
    ensureDir(SESSIONS_DIR);
    const sessionDir = path.join(SESSIONS_DIR, 'default');
    ensureDir(sessionDir);
    const legacyFiles = listAuthFiles(AUTH_DIR);
    const sessionFiles = listAuthFiles(sessionDir);
    if (sessionFiles.length === 0 && legacyFiles.length > 0) {
      copyAuthFiles(AUTH_DIR, sessionDir);
      if (listAuthFiles(sessionDir).length > 0) {
        return sessionDir;
      }
      return AUTH_DIR;
    }
    return sessionDir;
  }
  const sessionDir = path.join(SESSIONS_DIR, sessionId);
  ensureDir(sessionDir);
  return sessionDir;
}

function normalizePhone(phone) {
  let p = String(phone || '').replace(/\D+/g, '');
  if (p.startsWith('07') && p.length === 11) p = '964' + p.slice(1);
  if (p.startsWith('7') && p.length === 10) p = '964' + p;
  if (p.startsWith('9640')) p = '964' + p.slice(4);
  return p;
}

function slimSendResult(result) {
  const key = result && result.key ? result.key : {};
  return {
    id: key.id || null,
    remoteJid: key.remoteJid || null,
    fromMe: !!key.fromMe
  };
}

function createWaSession(sessionId) {
  const authDir = resolveAuthDir(sessionId);
  const knownWa = Object.create(null);

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
  let sendChain = Promise.resolve();

  function log(msg) {
    console.log('[' + sessionId + ']', msg);
  }

  function ensureAuthDir() {
    ensureDir(authDir);
  }

  function wipeAuthDir() {
    try {
      ensureAuthDir();
      const files = fs.readdirSync(authDir);
      for (let i = 0; i < files.length; i++) {
        try { fs.unlinkSync(path.join(authDir, files[i])); } catch (e) {}
      }
    } catch (e) {
      console.error('[' + sessionId + '] wipeAuthDir', e);
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
        log('مسح الجلسة وبدء من جديد...');
        wipeAuthDir();
        failCount = 0;
      }
      startSocket().catch(console.error);
    }, ms);
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
      const { state, saveCreds } = await useMultiFileAuthState(authDir);

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
          log('QR ready');
          qrcodeTerminal.generate(qr, { small: true });
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
          log('WhatsApp connected. ' + (connectedPhone || ''));
        }

        if (connection === 'close') {
          ready = false;
          connectedPhone = null;
          const err = lastDisconnect && lastDisconnect.error ? lastDisconnect.error : null;
          const code = (err && err.output && err.output.statusCode) || 0;
          const loggedOut = code === DisconnectReason.loggedOut;
          lastStatusMsg = 'closed_' + code;
          log('Connection closed. code=' + code);

          if (ignoreReconnect || loggedOut) {
            if (loggedOut) {
              wipeAuthDir();
              log('Logged out.');
            }
            return;
          }

          failCount += 1;
          let waitMs = 5000;
          if (code === 500 || code === 515 || code === 408 || code === 428) {
            waitMs = Math.min(60000, 8000 * failCount);
          } else {
            waitMs = Math.min(20000, 3000 * failCount);
          }
          const wipe = failCount >= 2;
          scheduleReconnect(waitMs, wipe);
        }
      });
    } catch (e) {
      console.error('[' + sessionId + '] startSocket error', e);
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
    if (!normalized || normalized.length < 10) {
      const err = new Error('Invalid phone number');
      err.code = 'bad_phone';
      throw err;
    }
    const pnJid = normalized + '@s.whatsapp.net';
    let realJid = (knownWa[normalized] && knownWa[normalized].jid) ? knownWa[normalized].jid : pnJid;

    try {
      const info = await Promise.race([
        sock.onWhatsApp(normalized),
        new Promise(function (resolve) {
          setTimeout(function () { resolve(null); }, 3000);
        })
      ]);
      if (Array.isArray(info) && info[0] && info[0].exists === true && info[0].jid) {
        realJid = info[0].jid;
        knownWa[normalized] = { jid: realJid, at: Date.now() };
      }
    } catch (e) {}

    await delay(SEND_DELAY_MS);
    const sendOpts = { timeoutMs: 25000 };

    try {
      const result = await sock.sendMessage(realJid, { text: String(message) }, sendOpts);
      knownWa[normalized] = { jid: realJid, at: Date.now() };
      return result;
    } catch (e) {
      const msg = (e && e.message) ? e.message : String(e);
      if (/not on WhatsApp/i.test(msg) || (e && e.code === 'no_whatsapp')) {
        const err = new Error('Number not on WhatsApp: ' + normalized);
        err.code = 'no_whatsapp';
        throw err;
      }
      if (realJid !== pnJid) {
        const result2 = await sock.sendMessage(pnJid, { text: String(message) }, sendOpts);
        knownWa[normalized] = { jid: pnJid, at: Date.now() };
        return result2;
      }
      throw e;
    }
  }

  function sendTextQueued(phone, message) {
    const run = sendChain.then(
      function () { return sendText(phone, message); },
      function () { return sendText(phone, message); }
    );
    sendChain = run.then(function () {}, function () {});
    return run;
  }

  function getStatus() {
    return {
      success: true,
      session: sessionId,
      ready: ready,
      has_qr: !!lastQr,
      phone: connectedPhone,
      status: lastStatusMsg
    };
  }

  async function getQr() {
    if (ready) {
      return { success: true, session: sessionId, ready: true, phone: connectedPhone, qr_data_url: null };
    }
    if (!lastQr) {
      return {
        success: true,
        session: sessionId,
        ready: false,
        qr_data_url: null,
        message: 'Waiting for QR...',
        status: lastStatusMsg
      };
    }
    let dataUrl = lastQrDataUrl;
    if (!dataUrl) {
      dataUrl = await QRCode.toDataURL(lastQr, { width: 360, margin: 2 });
      lastQrDataUrl = dataUrl;
    }
    return { success: true, session: sessionId, ready: false, qr_data_url: dataUrl };
  }

  function renderLinkPage() {
    const img = lastQrDataUrl
      ? '<img src="' + lastQrDataUrl + '" style="width:320px;height:320px;background:#fff;padding:12px;border-radius:12px">'
      : '<div style="padding:40px;border:2px dashed #999;border-radius:12px">بانتظار QR... حدّث الصفحة بعد ثواني<br><small>' + lastStatusMsg + '</small></div>';
    const phone = connectedPhone ? ('<p style="color:green">متصل: ' + connectedPhone + '</p>') : '';
    return (
      '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">' +
      '<meta http-equiv="refresh" content="3">' +
      '<title>WhatsApp QR — ' + sessionId + '</title>' +
      '<style>body{font-family:Tahoma,Arial;display:grid;place-items:center;min-height:100vh;background:#eef2f6;margin:0}' +
      '.box{background:#fff;padding:24px;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.08);text-align:center}</style></head><body>' +
      '<div class="box"><h2>امسح QR من واتساب</h2><p><small>session: ' + sessionId + '</small></p>' + phone + img +
      '<p>الأجهزة المرتبطة ← ربط جهاز</p></div></body></html>'
    );
  }

  return {
    sessionId: sessionId,
    startSocket: startSocket,
    hardLogout: hardLogout,
    sendTextQueued: sendTextQueued,
    getStatus: getStatus,
    getQr: getQr,
    renderLinkPage: renderLinkPage
  };
}

const sessions = Object.create(null);

function getOrCreateSession(sessionId) {
  const id = sanitizeSessionId(sessionId);
  if (!sessions[id]) {
    sessions[id] = createWaSession(id);
    sessions[id].startSocket().catch(console.error);
  }
  return sessions[id];
}

const app = express();
app.use(express.json({ limit: '100kb' }));
app.use(function (req, res, next) {
  res.header('Access-Control-Allow-Origin', '*');
  res.header('Access-Control-Allow-Headers', 'Content-Type, X-Api-Key, X-Wa-Session');
  res.header('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});

function checkKey(req, res, next) {
  const key = req.headers['x-api-key'] || (req.query && req.query.key);
  if (key !== API_KEY) return res.status(403).json({ success: false, error: 'Forbidden' });
  next();
}

function attachSession(req, res, next) {
  req.waSessionId = resolveSessionId(req);
  req.waSession = getOrCreateSession(req.waSessionId);
  next();
}

app.get('/status', checkKey, attachSession, (req, res) => {
  res.json(req.waSession.getStatus());
});

app.get('/qr', checkKey, attachSession, async (req, res) => {
  try {
    res.json(await req.waSession.getQr());
  } catch (err) {
    res.status(500).json({ success: false, error: err.message || String(err) });
  }
});

app.get('/link', checkKey, attachSession, (req, res) => {
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.end(req.waSession.renderLinkPage());
});

async function logoutHandler(req, res) {
  res.json({ success: true, session: req.waSessionId, message: 'Logged out. Waiting for new QR...' });
  setImmediate(function () {
    req.waSession.hardLogout().catch(console.error);
  });
}
app.post('/logout', checkKey, attachSession, logoutHandler);
app.get('/logout', checkKey, attachSession, logoutHandler);

app.post('/send', checkKey, attachSession, async (req, res) => {
  try {
    const body = req.body || {};
    if (!body.phone || !body.message) {
      return res.status(400).json({ success: false, error: 'phone and message required' });
    }
    const result = await req.waSession.sendTextQueued(body.phone, body.message);
    res.json({ success: true, session: req.waSessionId, result: slimSendResult(result) });
  } catch (err) {
    const msg = (err && err.message) ? err.message : String(err);
    const payload = { success: false, error: msg, session: req.waSessionId };
    if ((err && err.code === 'no_whatsapp') || /not on WhatsApp/i.test(msg)) {
      payload.code = 'no_whatsapp';
    }
    res.status(500).json(payload);
  }
});

app.listen(PORT, '0.0.0.0', () => {
  console.log('WhatsApp gateway on http://0.0.0.0:' + PORT);
  console.log('Multi-session: pass ?session=id (default: default)');
  getOrCreateSession('default');
});
