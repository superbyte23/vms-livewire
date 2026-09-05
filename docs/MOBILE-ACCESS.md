# Mobile Access: Constraints & Options

Goal: access the VMS app from a phone on the local network — with CSS working *and* the camera (selfie capture) working.

## Hard constraints (in order of impact)

### 1. Browser secure-context rule (immovable)
The camera (`getUserMedia`) only works on:
- `localhost`, or
- `https://`

On a phone reaching the app over plain `http://<ip>:8000`, the camera is blocked by the browser itself. **No Laravel, WSL, or Vite setting bypasses this.** It is the reason HTTPS (or a browser exception) is required.

### 2. Certificate trust
To make `https://` work, the phone must *trust* the certificate:
- A self-signed cert where you "click through" the warning still leaves the page **non-secure** → camera stays blocked (Android Chrome).
- Install the CA on the phone for the page to be secure:
  - **Android:** Settings → Security → Install a certificate → CA certificate
  - **iOS:** install the profile, then enable full trust (Settings → General → About → Certificate Trust Settings)
- A **publicly-valid** cert (own domain + DNS-01 challenge) works with **zero** phone setup.

### 3. TLS must actually be served
`php artisan serve` and `npm run dev` are HTTP-only. A TLS-terminating layer is required in front of Laravel:
- Reverse proxy: Apache / nginx / Caddy (Caddy & FrankenPHP can auto-generate an internal CA)
- Or a tunnel (cloudflared / ngrok) — rejected for this project

Laravel 13's `artisan serve` has **no `--tls` option** (verified), so a proxy is mandatory.

### 4. LAN reachability from WSL2
Default WSL2 is **NAT mode** — phones cannot reach WSL at all.
- Fixed via `[wsl2] networkingMode=mirrored` in `C:\Users\<user>\.wslconfig` (+ `wsl --shutdown`), or a Windows `netsh interface portproxy` + firewall rule.
- After `wsl --shutdown`, **all WSL processes die** (servers must be restarted).
- Observed quirk: with mirrored mode, the Windows host itself could not reach its own LAN IP (`SYN_SENT` hang), possibly compounded by PlanetVPN's adapter (`10.9.8.14`).
- **Status: resolved** — inbound TCP 8001 + 5173 firewall rules added on the Windows host; the app is reachable from LAN devices at `http://192.168.1.13:8001`.

### 5. Vite dev server vs mobile
In dev, the page loads CSS/JS from the URL written in `public/hot` (`http://localhost:5173`). On a phone that hostname resolves to the **phone itself** → no CSS.
- Fix A: `npm run build` → Laravel serves compiled assets same-origin. **Required** when HTTPS is in front (vite dev + HTTPS = mixed-content, blocked).
- Fix B: point the vite `hmr.host` / hot file at a phone-reachable host (HTTP-only path).

### 6. Windows Firewall
LAN devices hitting `http(s)://<pc-ip>:<port>` may be dropped until an inbound rule exists (run once per port on the Windows host, elevated PowerShell):
```
netsh advfirewall firewall add rule name="VMS HTTPS 8443" dir=in action=allow protocol=TCP localport=8443
netsh advfirewall firewall add rule name="VMS HTTP 8888"  dir=in action=allow protocol=TCP localport=8888
```
> Whenever the PC's LAN IP or a kiosk port changes, add/refresh the matching rule. Current IP: `192.168.1.4`; kiosk API: `http://192.168.1.4:8888` (plain HTTP) and `https://192.168.1.4:9443` (HTTPS).

## Realistic combinations

| Path | Setup | Camera on phone | Notes |
|---|---|---|---|
| **Quick test (Android only)** | Android `chrome://flags/#unsafely-treat-insecure-origin-as-secure` → add `http://<pc-ip>:8000` + `npm run build` + `php artisan serve` | ✅ over plain HTTP | Android-only, dev flag |
| **Working local HTTPS (this repo)** | mkcert CA + cert for `192.168.1.13` + Caddy proxy 8443 → `127.0.0.1:8001` + built assets + CA installed on phone | ✅ over HTTPS | Any device; one-time CA install per device; see below |
| **Public cert** | Own domain + DNS-01 Let's Encrypt cert on Apache/nginx | ✅ over HTTPS | Zero phone setup, requires a domain |

## HTTPS setup (in place — Caddy + mkcert)

- **Certs:** mkcert CA at `$(mkcert -CAROOT)`; site cert in `.certs/` (gitignored) for SANs `192.168.1.13`, `localhost`, `127.0.0.1`.
- **Proxy:** `Caddyfile` at repo root serves `https://192.168.1.13:8443` → `127.0.0.1:8001` (`auto_https disable_redirects` so it doesn't fight Apache for port 80). Started via `caddy run` (log: `storage/logs/caddy.log`).
- **Trusted proxy:** `bootstrap/app.php` → `trustProxies(at: '127.0.0.1')` so Laravel generates `https://` asset URLs behind Caddy (no mixed content).
- **Assets:** `public/hot` must be **absent** (vite dev URLs are `http://` and would be mixed-content-blocked). `npm run build` → Laravel serves built assets same-origin. Deleting `public/hot` switches PC dev to built assets too; restarting `composer dev` recreates it for HMR.

### To use from a phone
1. Windows Firewall (admin): allow inbound TCP 8443 (see KNOWN-ISSUES for the netsh command).
2. On the phone, download and install the CA: `http://192.168.1.13:8001/rootCA.pem` (Android: Settings → Security → CA certificate; iOS: install profile + enable full trust).
3. Open `https://192.168.1.13:8443` — camera (`getUserMedia`) works.

> If the LAN IP changes: regenerate the cert (`mkcert <new-ip> …`), update `Caddyfile` + `.env` `VITE_HOST`/`APP_URL`, and re-add the firewall rule.

## Quick reference (from this session)

- PC LAN IP: `192.168.1.13` (WSL mirrored, `eth4`)
- WSL NAT IP: `172.19.117.58`
- Default dev run: `composer dev` (serve `:8000` + queue + vite `:5173`)
- Build assets: `npm run build` (output `public/build/`)
- `http://localhost:8000` → CSS + camera work on the PC (localhost = secure context)
- `http://192.168.1.13:8000` from a phone → page loads (CSS only if built assets used), **camera blocked** (non-secure)
