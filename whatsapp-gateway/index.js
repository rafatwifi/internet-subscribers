const express = require('express');
const qrcodeTerminal = require('qrcode-terminal');
const QRCode = require('qrcode');
const fs = require('fs');
const path = require('path');
const pino = require('pino');
const https = require('https');

const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  delay,
  Browsers,
  fetchLatestWaWebVersion,
  fetchLatestBaileysVersion
} = require('@whiskeysockets/baileys');

const PORT = process.env.PORT || 3001;
const API_KEY = process.env.API_KEY || 'local-secret-change-me';
const AUTH_DIR = path.join(__dirname, 'auth');
const SESSIONS_DIR = path.join(AUTH_DIR, 'sessions');
const SEND_DELAY_MS = Number(process.env.SEND_DELAY_MS || 2500);

let useInsecureTls = String(process.env.WA_TLS_INSECURE || '') === '1';

const FALLBACK_WA_VERSION = [2, 3000, 1043857760];
const STALE_WA_BUILDS = {
  '2.3000.1023223821': true
};

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

function versionKey(v) {
  return Array.isArray(v) ? v.join('.') : '';
}

function isUsableVersion(v) {
  if (!v || !Array.isArray(v) || v.length !== 3) return false;
  if (STALE_WA_BUILDS[versionKey(v)]) return false;
  return true;
}

function makeTlsAgent() {
  return new https.Agent({
    keepAlive: true,
    rejectUnauthorized: !useInsecureTls
  });
}

async function resolveWaVersion() {
  if (process.env.WA_VERSION) {
    const parts = String(process.env.WA_VERSION).split(',').map(function (x) { return parseInt(x.trim(), 10); });
    if (parts.length === 3 && parts.every(function (n) { return !isNaN(n); })) {
      console.log('WA version (env):', parts.join('.'));
      return parts;
    }
  }

  try {
    if (typeof fetchLatestWaWebVersion === 'function') {
      const r = await fetchLatestWaWebVersion();
      if (r && isUsableVersion(r.version)) {
        console.log('WA version (web):', r.version.join('.'));
        return r.version;
      }
    }
  } catch (e) {
    console.log('fetchLatestWaWebVersion failed:', e && e.message ? e.message : e);
  }

  try {
    if (typeof fetchLatestBaileysVersion === 'function') {
      const r = await fetchLatestBaileysVersion();
      if (r && isUsableVersion(r.version)) {
        console.log('WA version (baileys):', r.version.join('.'), r.isLatest === false ? '(local/cache)' : '');
        return r.version;
      }
      if (r && r.version && STALE_WA_BUILDS[versionKey(r.version)]) {
        console.log('WA version (baileys stale):', r.version.join('.'), '— skipping');
      }
    }
  } catch (e) {
    console.log('fetchLatestBaileysVersion failed:', e && e.message ? e.message : e);
  }

  console.log('WA version (fallback):', FALLBACK_WA_VERSION.join('.'));
  return FALLBACK_WA_VERSION;
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
  const knownWaFile = path.join(authDir, 'known-wa.json');
  const knownWa = Object.create(null);

  try {
    if (fs.existsSync(knownWaFile)) {
      const saved = JSON.parse(fs.readFileSync(knownWaFile, 'utf8'));
      if (saved && typeof saved === 'object') {
        Object.keys(saved).forEach(function (k) { knownWa[k] = saved[k]; });
      }
    }
  } catch (e) {}

  function rememberWa(phone, jid) {
    knownWa[phone] = { jid: jid, at: Date.now() };
    try {
      fs.writeFileSync(knownWaFile, JSON.stringify(knownWa));
    } catch (e) {}
  }

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
  let lastQrAt = 0;
  let qrHoldUntil = 0;
  let pairingQuietUntil = 0;
  let lastWipeAt = 0;
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
        log('Wiping session and starting fresh...');
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
      const version = await resolveWaVersion();

      const sockOpts = {
        auth: state,
        logger: pino({ level: 'silent' }),
        printQRInTerminal: false,
        syncFullHistory: false,
        markOnlineOnConnect: true,
        generateHighQualityLinkPreview: false,
        connectTimeoutMs: 60000,
        keepAliveIntervalMs: 20000,
        browser: Browsers && Browsers.ubuntu ? Browsers.ubuntu('Chrome') : ['Ubuntu', 'Chrome', '22.04.4'],
        agent: makeTlsAgent(),
        fetchAgent: makeTlsAgent()
      };
      if (version) {
        sockOpts.version = version;
      }

      sock = makeWASocket(sockOpts);

      sock.ev.on('creds.update', saveCreds);

      sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
          ready = false;
          const now = Date.now();
          if (lastQrDataUrl && lastQrAt > 0 && (now - lastQrAt) < 60000) {
            lastStatusMsg = 'qr_ready';
            pairingQuietUntil = Math.max(pairingQuietUntil, now + 120000);
            return;
          }
          lastQr = qr;
          lastQrAt = now;
          qrHoldUntil = now + 180000;
          pairingQuietUntil = now + 180000;
          lastStatusMsg = 'qr_ready';
          try {
            lastQrDataUrl = await QRCode.toDataURL(qr, { width: 360, margin: 2 });
          } catch (e) {
            lastQrDataUrl = null;
          }
          log('QR ready — scan in WhatsApp');
          qrcodeTerminal.generate(qr, { small: true });
        }

        if (connection === 'open') {
          ready = true;
          lastQr = null;
          lastQrDataUrl = null;
          lastQrAt = 0;
          qrHoldUntil = 0;
          pairingQuietUntil = 0;
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
          const errMsg = err && err.message ? String(err.message) : '';
          const loggedOut = code === DisconnectReason.loggedOut;
          const badSession = code === DisconnectReason.badSession || code === 500;
          const certExpired = /certificate has expired/i.test(errMsg);
          lastStatusMsg = certExpired ? 'cert_expired' : ('closed_' + code);
          log('Connection closed. code=' + code + ' ' + (errMsg || ''));

          if (ignoreReconnect || loggedOut) {
            if (loggedOut) {
              wipeAuthDir();
              log('Logged out. Wipe auth and wait for new QR after restart.');
            }
            return;
          }

          if (code === 515 || code === DisconnectReason.restartRequired) {
            failCount = 0;
            scheduleReconnect(3000, false);
            return;
          }

          if (code === 405) {
            failCount = 0;
            scheduleReconnect(5000, false);
            return;
          }

          if (certExpired || badSession) {
            if (!useInsecureTls) {
              useInsecureTls = true;
              log('Cert/TLS error — enabling insecure TLS agent.');
              failCount = 0;
              lastStatusMsg = 'tls_retry';
              scheduleReconnect(4000, false);
              return;
            }
            const nowWipe = Date.now();
            const canWipe = (nowWipe - lastWipeAt) > 15 * 60 * 1000;
            if (canWipe) {
              lastWipeAt = nowWipe;
              wipeAuthDir();
              lastQr = null;
              lastQrDataUrl = null;
              lastQrAt = 0;
              qrHoldUntil = 0;
              pairingQuietUntil = 0;
              failCount = 0;
              lastStatusMsg = 'cert_expired';
              scheduleReconnect(20000, false);
              return;
            }
            failCount += 1;
            const waitMs = Math.min(300000, 45000 * Math.max(1, failCount));
            lastStatusMsg = 'cert_expired';
            scheduleReconnect(waitMs, false);
            return;
          }

          const nowClose = Date.now();
          if (pairingQuietUntil > nowClose || qrHoldUntil > nowClose) {
            const waitHold = Math.max(pairingQuietUntil, qrHoldUntil) - nowClose + 3000;
            scheduleReconnect(waitHold, false);
            return;
          }

          failCount += 1;
          let waitMs = Math.min(180000, 20000 * failCount);
          if (code === 408 || code === 428 || code === 440) {
            waitMs = Math.min(300000, 30000 * failCount);
          }
          const wipe = failCount >= 40;
          scheduleReconnect(waitMs, wipe);
        }
      });
    } catch (e) {
      console.error('[' + sessionId + '] startSocket error', e);
      lastStatusMsg = 'error';
      scheduleReconnect(20000, false);
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
    lastQrAt = 0;
    qrHoldUntil = 0;
    pairingQuietUntil = Date.now() + 20000;
    connectedPhone = null;
    failCount = 0;
    lastStatusMsg = 'logout_cooldown';

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
    }, 12000);

    return { success: true, message: 'Logged out. Wait ~12s for new QR...' };
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
        rememberWa(normalized, realJid);
      }
    } catch (e) {}

    await delay(SEND_DELAY_MS);
    const sendOpts = { timeoutMs: 25000 };

    try {
      const result = await sock.sendMessage(realJid, { text: String(message) }, sendOpts);
      rememberWa(normalized, realJid);
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
        rememberWa(normalized, pnJid);
        return result2;
      }
      console.error('[' + sessionId + '] send fail', normalized, realJid, msg);
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
      has_qr: !!(lastQr || lastQrDataUrl),
      phone: connectedPhone,
      status: lastStatusMsg,
      tls_insecure: !!useInsecureTls
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
      '<meta http-equiv="refresh" content="20">' +
      '<title>WhatsApp QR — ' + sessionId + '</title>' +
      '<style>body{font-family:Tahoma,Arial;display:grid;place-items:center;min-height:100vh;background:#eef2f6;margin:0}' +
      '.box{background:#fff;padding:24px;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.08);text-align:center}</style></head><body>' +
      '<div class="box"><h2>امسح QR من واتساب</h2><p><small>session: ' + sessionId + '</small></p>' + phone + img +
      '<p>الأجهزة المرتبطة ← ربط جهاز</p></div></body></html>'
    );
  }

  return {
    sessionId: sessionId,
    authDir: authDir,
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
    console.error('POST /send [' + req.waSessionId + ']', msg);
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
  console.log('Admin QR: http://127.0.0.1:' + PORT + '/link?key=' + API_KEY + '&session=default');
  getOrCreateSession('default');
});
