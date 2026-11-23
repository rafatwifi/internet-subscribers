# -*- coding: utf-8 -*-
"""طباعة الفاتورة من اللابتوب على الطابعة الافتراضية بدون نافذة تأكيد.
يشغّل Edge ببروفايل مستقل و --kiosk-printing حتى window.print() يروح للطابعة مباشرة.
"""
from __future__ import print_function
import json
import os
import subprocess
import tempfile
try:
    from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
except ImportError:
    from BaseHTTPServer import BaseHTTPRequestHandler, HTTPServer as ThreadingHTTPServer

PORT = 9240
HOST = "127.0.0.1"


def edge_path():
    cands = [
        os.path.expandvars(r"%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"),
        os.path.expandvars(r"%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"),
        os.path.expandvars(r"%LocalAppData%\Microsoft\Edge\Application\msedge.exe"),
    ]
    for p in cands:
        if p and os.path.isfile(p):
            return p
    return ""


def allowed_url(url):
    u = (url or "").strip()
    if not (u.startswith("http://") or u.startswith("https://")):
        return False
    low = u.lower()
    if "slip.php" not in low:
        return False
    return True


def launch(url):
    edge = edge_path()
    if not edge:
        return False, "Edge not found"
    if "print=" not in url:
        url += ("&" if "?" in url else "?") + "print=1"
    if "agent=1" not in url:
        url += "&agent=1"
    profile = os.path.join(tempfile.gettempdir(), "isp-slip-print")
    if not os.path.isdir(profile):
        try:
            os.makedirs(profile)
        except Exception:
            pass
    args = [
        edge,
        "--user-data-dir=" + profile,
        "--kiosk-printing",
        "--no-first-run",
        "--no-default-browser-check",
        "--disable-sync",
        "--disable-extensions",
        "--disable-popup-blocking",
        "--disable-translate",
        "--window-position=-2600,-2600",
        "--window-size=420,780",
        url,
    ]
    try:
        subprocess.Popen(args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception as e:
        return False, str(e)
    return True, "started"


class Handler(BaseHTTPRequestHandler):
    def _cors(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Private-Network", "true")
        self.send_header("Access-Control-Allow-Methods", "POST, GET, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Access-Control-Max-Age", "600")

    def do_OPTIONS(self):
        self.send_response(204)
        self._cors()
        self.end_headers()

    def do_GET(self):
        if self.path.startswith("/health"):
            body = json.dumps({"ok": True, "edge": bool(edge_path())}).encode("utf-8")
            self.send_response(200)
            self._cors()
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        self.send_response(404)
        self.end_headers()

    def do_POST(self):
        if not self.path.startswith("/print"):
            self.send_response(404)
            self.end_headers()
            return
        n = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(n) if n else b"{}"
        try:
            data = json.loads(raw.decode("utf-8", "ignore"))
        except Exception:
            data = {}
        url = str(data.get("url") or "")
        if not allowed_url(url):
            body = b'{"ok":false,"message":"bad url"}'
            self.send_response(400)
            self._cors()
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        ok, msg = launch(url)
        body = json.dumps({"ok": ok, "message": msg}).encode("utf-8")
        self.send_response(200 if ok else 500)
        self._cors()
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        return


def main():
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()


if __name__ == "__main__":
    main()
