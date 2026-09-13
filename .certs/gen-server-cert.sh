#!/usr/bin/env bash
# Re-mint the RoadRunner server cert on every (re)start so TLS works on
# ANY current IP (DHCP-safe). Signed by the stable CA in this dir, so
# clients only ever need to trust ca.pem ONCE.
set -euo pipefail

CERT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CA_CERT="$CERT_DIR/ca.pem"
CA_KEY="$CERT_DIR/ca-key.pem"
OUT_CERT="$CERT_DIR/rr-server.pem"
OUT_KEY="$CERT_DIR/rr-server-key.pem"

if [[ ! -f "$CA_CERT" || ! -f "$CA_KEY" ]]; then
    echo "gen-server-cert: CA files missing in $CERT_DIR" >&2
    exit 1
fi

# Extra IPs not visible from inside WSL (e.g. Windows LAN IP if DHCP moves
# it and interop detection below fails). Space-separated, may be empty.
EXTRA_IPS="192.168.1.17"

# Collect all non-loopback IPv4s: WSL interfaces + Windows host (interop).
IPS="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | grep -v '^127\.' | sort -u)"
WIN_IPS="$(timeout 10 powershell.exe -NoProfile -Command ipconfig 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' | grep -v -E '^(127\.|169\.254\.|255\.)' | sort -u || true)"
IPS="127.0.0.1
$IPS
$WIN_IPS
$(echo "$EXTRA_IPS" | tr ' ' '\n')"
IPS="$(echo "$IPS" | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | sort -u)"

# Hostnames clients may use.
HOSTS="localhost
$(hostname -s 2>/dev/null || true)
$(hostname 2>/dev/null || true)
vms.local"

# Build openssl SAN string: IP:x, DNS:y
SAN=""
while read -r ip; do
    [[ -z "$ip" ]] && continue
    SAN="${SAN}IP:${ip},"
done <<< "$IPS"
while read -r h; do
    [[ -z "$h" ]] && continue
    SAN="${SAN}DNS:${h},"
done <<< "$HOSTS"
SAN="${SAN%,}"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

SERIAL="0x$(openssl rand -hex 8)"

openssl req -x509 -newkey rsa:2048 -sha256 -days 825 -nodes \
    -keyout "$TMP/server-key.pem" \
    -out "$TMP/server.pem" \
    -subj "/CN=vms-livewire" \
    -addext "subjectAltName=${SAN}" \
    -CA "$CA_CERT" -CAkey "$CA_KEY" -set_serial "$SERIAL"

# Only swap into place on success (RR reads these paths from .rr.yaml).
mv "$TMP/server.pem" "$OUT_CERT"
mv "$TMP/server-key.pem" "$OUT_KEY"
chmod 644 "$OUT_CERT"
chmod 640 "$OUT_KEY"
if [[ "$(id -u)" == "0" ]]; then
    # Supervisor runs RR as root; keep the key readable for manual
    # ser_john runs too (ser_john is in www-data).
    chgrp www-data "$OUT_CERT" "$OUT_KEY" 2>/dev/null || true
fi

echo "gen-server-cert: minted cert for SAN [${SAN}]"
