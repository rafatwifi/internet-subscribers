#!/bin/bash
# بوابة واتساب على أوبونتو. تشتغل مع إقلاع السيرفر، بدون جهاز الويندوز.
set -e
ROOT="$(cd "$(dirname "$0")" && pwd)"
APP="/var/www/html/internet-subscribers"
AUTH="/var/lib/wa-gateway/auth"
NODE_VER="v20.18.1"
NODE_NAME="node-${NODE_VER}-linux-x64-glibc-217"
NODE_URL="https://unofficial-builds.nodejs.org/download/release/${NODE_VER}/${NODE_NAME}.tar.gz"

mkdir -p "$AUTH" /opt
if [ ! -x "/opt/${NODE_NAME}/bin/node" ]; then
  curl -fsSL "$NODE_URL" -o "/tmp/${NODE_NAME}.tar.gz"
  tar -xzf "/tmp/${NODE_NAME}.tar.gz" -C /opt
fi
ln -sfn "/opt/${NODE_NAME}" /opt/node
export PATH="/opt/node/bin:$PATH"
node -v

cd "$ROOT"
npm ci --omit=dev

chown -R www-data:www-data /var/lib/wa-gateway
chmod 750 /var/lib/wa-gateway
chmod 700 "$AUTH"

php7.0 -r '
$s = json_decode(file_get_contents("/var/www/html/internet-subscribers/config/settings.json"), true);
$key = (is_array($s) && isset($s["whatsapp_local_key"])) ? (string) $s["whatsapp_local_key"] : "";
if ($key === "") { fwrite(STDERR, "missing_gateway_key\n"); exit(1); }
$body = "API_KEY=".$key."\nPORT=3001\nHOST=127.0.0.1\nSEND_DELAY_MS=4000\nAUTH_DIR=/var/lib/wa-gateway/auth\n";
file_put_contents("/var/lib/wa-gateway/env", $body);
'
chmod 600 /var/lib/wa-gateway/env
chown www-data:www-data /var/lib/wa-gateway/env

cat > /etc/systemd/system/wa-gateway.service <<'EOF'
[Unit]
Description=WhatsApp gateway
After=network.target

[Service]
WorkingDirectory=/var/www/html/internet-subscribers/whatsapp-gateway
EnvironmentFile=/var/lib/wa-gateway/env
ExecStart=/opt/node/bin/node index.js
Restart=always
RestartSec=5
User=www-data
Group=www-data

[Install]
WantedBy=multi-user.target
EOF

php7.0 -r '
$p="/var/www/html/internet-subscribers/config/settings.json";
$raw=file_get_contents($p);
$next=preg_replace("/\"whatsapp_local_url\"\\s*:\\s*\"[^\"]*\"/", "\"whatsapp_local_url\": \"http://127.0.0.1:3001\"", $raw, 1, $n);
if (!$n) { fwrite(STDERR, "url_field_missing\n"); exit(1); }
file_put_contents($p, $next);
echo "url_updated\n";
'

systemctl daemon-reload
systemctl enable wa-gateway
systemctl restart wa-gateway
sleep 2
systemctl is-active wa-gateway
echo "gateway_started"
