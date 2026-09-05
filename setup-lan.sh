#!/usr/bin/env bash
set -euo pipefail

# setup-lan.sh — make the VMS reachable over HTTPS on the local network with a
# DYNAMIC LAN IP.
#
# Self-signed certs are pinned to a specific IP, so this script:
#   1. detects the machine's current LAN IP,
#   2. regenerates the site certificate + key for that IP (from the existing
#      VMS-Livewire Local CA in .certs/, so devices that already trust the CA
#      keep working),
#   3. rewrites Frankenphpfile / Caddyfile and .env (APP_URL, VITE_HOST, Vite
#      cert paths) to match,
#   4. prints the URL and a reminder to install the CA on new devices.
#
# Run it whenever the machine's LAN IP changes:  bash setup-lan.sh

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CERTS="$REPO/.certs"
CA_CERT="$CERTS/ca.pem"
CA_KEY="$CERTS/ca-key.pem"

# FrankenPHP listens on this port, Caddy reverse-proxies on this one.
FRANKEN_PORT="9443"
CADDY_PORT="8443"

# ---- 1. Detect the current LAN IP -------------------------------------------
detect_ip() {
    # Prefer a private IPv4 from hostname -I, excluding loopback.
    local ip
    ip="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)' | head -n1 || true)"
    if [ -z "$ip" ]; then
        # Fall back to the source address of the default route.
        ip="$(ip route get 1 2>/dev/null | sed -n 's/.*src \([0-9.]*\).*/\1/p' | head -n1 || true)"
    fi
    if [ -z "$ip" ]; then
        echo "ERROR: could not auto-detect a LAN IP. Pass one explicitly:" >&2
        echo "  bash setup-lan.sh 192.168.1.50" >&2
        exit 1
    fi
    echo "$ip"
}

IP="${1:-$(detect_ip)}"

# ---- 2. Sanity checks --------------------------------------------------------
if [ ! -f "$CA_CERT" ] || [ ! -f "$CA_KEY" ]; then
    echo "ERROR: local CA not found at $CERTS (ca.pem / ca-key.pem)." >&2
    exit 1
fi

if ! command -v openssl >/dev/null 2>&1; then
    echo "ERROR: openssl is required." >&2
    exit 1
fi

echo ">> Detected LAN IP: $IP"

mkdir -p "$CERTS"
chmod 700 "$CERTS"

CERT="$CERTS/$IP.pem"
KEY="$CERTS/$IP-key.pem"
CSR="$CERTS/$IP.csr"
EXT="$CERTS/$IP.ext"

# ---- 3. Generate a fresh site cert + key for the current IP -----------------
openssl ecparam -name prime256v1 -genkey -noout -out "$KEY" 2>/dev/null \
    || openssl genrsa -out "$KEY" 2048

cat > "$EXT" <<EOF
subjectAltName = IP:$IP, DNS:localhost, DNS:127.0.0.1
basicConstraints = critical,CA:FALSE
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
EOF

openssl req -new -key "$KEY" -subj "/CN=$IP" -out "$CSR" >/dev/null 2>&1
openssl x509 -req -in "$CSR" -CA "$CA_CERT" -CAkey "$CA_KEY" \
    -CAcreateserial -CAserial "$CERTS/ca.srl" \
    -days 825 -sha256 -extfile "$EXT" -out "$CERT" >/dev/null 2>&1

chmod 600 "$KEY" 2>/dev/null || true
rm -f "$CSR" "$EXT"

echo ">> Wrote certificate: $CERT"
echo "   (issued by VMS-Livewire Local CA for IP $IP)"

# ---- 4. Rewrite serving configs ----------------------------------------------
cat > "$REPO/Frankenphpfile" <<EOF
{
    auto_https disable_redirects
}

https://$IP:$FRANKEN_PORT {
    tls $CERT $KEY
    root * $REPO/public
    php_server
    encode gzip
}
EOF

cat > "$REPO/Caddyfile" <<EOF
{
    auto_https disable_redirects
}

https://$IP:$CADDY_PORT {
    tls $CERT $KEY
    reverse_proxy 127.0.0.1:8001
}
EOF

echo ">> Updated Frankenphpfile (https://$IP:$FRANKEN_PORT)"
echo ">> Updated Caddyfile        (https://$IP:$CADDY_PORT)"

# ---- 5. Update .env / .env.example -------------------------------------------
update_env() {
    local file="$1"
    [ -f "$file" ] || return 0
    sed -i -E \
        -e "s|^APP_URL=.*|APP_URL=https://$IP:$FRANKEN_PORT|" \
        -e "s|^VITE_HOST=.*|VITE_HOST=$IP|" \
        -e "s|^VITE_HTTPS_CERT=.*|VITE_HTTPS_CERT=$CERT|" \
        -e "s|^VITE_HTTPS_KEY=.*|VITE_HTTPS_KEY=$KEY|" \
        "$file"
}

update_env "$REPO/.env"
update_env "$REPO/.env.example"

echo ">> Updated .env / .env.example (APP_URL=https://$IP:$FRANKEN_PORT, VITE_HOST=$IP)"

# Clear cached config so the new APP_URL is picked up.
(cd "$REPO" && php artisan config:clear 2>/dev/null || true)

echo
echo "=========================================================================="
echo "  VMS is now set up for LAN HTTPS at:"
echo
echo "    FrankenPHP (kiosk):  https://$IP:$FRANKEN_PORT"
echo "    Caddy (reverse proxy): https://$IP:$CADDY_PORT"
echo
echo "  Start the server:"
echo "      composer franken"
echo
echo "  On each new device, install the CA so the camera works:"
echo "      $CA_CERT"
echo "      Android:  Settings > Security > Install cert > CA certificate"
echo "      iOS:      install profile, then enable full trust in Cert Trust Settings"
echo "=========================================================================="
