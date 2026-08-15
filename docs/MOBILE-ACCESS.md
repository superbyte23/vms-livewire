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
LAN devices hitting `http(s)://<pc-ip>:<port>` may be dropped until an inbound rule exists:
```
netsh advfirewall firewall add rule name="VMS HTTPS 8443" dir=in action=allow protocol=TCP localport=8443
```
(requires an elevated PowerShell)

## Realistic combinations

| Path | Setup | Camera on phone | Notes |
|---|---|---|---|
| **Quick test (Android only)** | Android `chrome://flags/#unsafely-treat-insecure-origin-as-secure` → add `http://<pc-ip>:8000` + `npm run build` + `php artisan serve` | ✅ over plain HTTP | Android-only, dev flag |
| **Proper local HTTPS** | `npm run build` + reverse proxy with phone-trusted cert (mkcert or Caddy internal CA) + firewall rule | ✅ over HTTPS | Any device, one-time CA install |
| **Public cert** | Own domain + DNS-01 Let's Encrypt cert on Apache/nginx | ✅ over HTTPS | Zero phone setup, requires a domain |

## Quick reference (from this session)

- PC LAN IP: `192.168.1.13` (WSL mirrored, `eth4`)
- WSL NAT IP: `172.19.117.58`
- Default dev run: `composer dev` (serve `:8000` + queue + vite `:5173`)
- Build assets: `npm run build` (output `public/build/`)
- `http://localhost:8000` → CSS + camera work on the PC (localhost = secure context)
- `http://192.168.1.13:8000` from a phone → page loads (CSS only if built assets used), **camera blocked** (non-secure)
