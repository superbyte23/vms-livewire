#!/usr/bin/env bash
set -euo pipefail

# Enable SSL for vms-livewire (dev, artisan serve on :8001) via Apache reverse proxy
# Usage: sudo bash enable_ssl.sh

IP="192.168.1.4"
CONF=/etc/apache2/sites-available/vms-livewire-ssl.conf

# 1. Enable required Apache modules
a2enmod ssl socache_shmcb proxy proxy_http proxy_wstunnel

# 2. Generate self-signed cert (SAN covers IP + localhost)
mkdir -p /etc/apache2/ssl
openssl req -x509 -nodes -newkey rsa:2048 -days 825 -keyout /etc/apache2/ssl/vms-livewire.key \
  -out /etc/apache2/ssl/vms-livewire.crt \
  -subj "/CN=${IP}" \
  -addext "subjectAltName=IP:${IP},DNS:localhost" >/dev/null 2>&1

# 3. Write reverse-proxy vhost
cat > "$CONF" <<EOF
<VirtualHost *:443>
    ServerName ${IP}
    DocumentRoot /var/www/vms-livewire/public

    SSLEngine on
    SSLCertificateFile /etc/apache2/ssl/vms-livewire.crt
    SSLCertificateKeyFile /etc/apache2/ssl/vms-livewire.key

    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:8001/
    ProxyPassReverse / http://127.0.0.1:8001/

    ErrorLog \${APACHE_LOG_DIR}/vms-livewire_ssl_error.log
    CustomLog \${APACHE_LOG_DIR}/vms-livewire_ssl_access.log combined
</VirtualHost>
EOF

a2ensite vms-livewire-ssl >/dev/null
systemctl reload apache2

echo "Done. HTTPS now available at https://${IP}"
echo "Next: update APP_URL and cache, then test in a browser."
