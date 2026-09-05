# Known Issues / Things to Address

Tracking list of open problems to address. Add new items at the top of their section with a short description and any proposed fix.

---

## 🔴 High priority

### 1. Image storage consumes large data
- **Where:** all images stored as base64 data URLs in SQLite `longText` columns:
  - `visitors.photo`, `pre_registrations.valid_id_photo`, `visitor_logs.photo`, `visitor_logs.checkout_photo`
- **Problem:** canvas captures (640×480) are ~80–200 KB base64 each, but **uploaded ID photos keep full resolution** (several MB). Every visit stores up to 3 images, duplicated across `visitors` + `visitor_logs` rows. DB grows to hundreds of MB–GB, slowing backups, exports, and `visitor-logs` queries.
- **Proposed fix (pick one or both):**
  - Client-side resize/compress uploaded photos to 640×480 JPEG before saving (caps everything ~200 KB).
  - Or move images to disk (`storage/app/public/`) and store only the file path in the DB.
- **Status:** 🔲 Not started

---

## 🟡 Medium priority

### 2. Existing-visitor re-check-in photo is not saved
- **Where:** `⚡welcome.blade.php` `checkIn()` (existing visitor branch, ~line 497)
- **Problem:** when a previously registered visitor checks in and captures a fresh ID photo, the capture is discarded — only `qr_code_token` is updated on the existing `Visitor`. The new photo never reaches the DB.
- **Proposed fix:** update `visitor->photo` (or the log) when a new capture is present for existing visitors.
- **Status:** 🔲 Not started

### 3. `WatchlistTest` is flaky in full-suite runs
- **Where:** `tests/Feature/WatchlistTest.php`
- **Problem:** occasionally fails when the full suite runs, passes alone / on re-run.
- **Proposed fix:** investigate ordering/shared-state dependency; run with `RefreshDatabase` isolation if needed.
- **Status:** 🔲 Not started

---

## 🟢 Low priority / Info

### 4. Port 8000 unusable (WSL2 mirrored networking) — SUPERSEDED
> **Superseded:** the deployment now runs on a separate, isolated WSL2 distro (`Ubuntu-26.04`) in **NAT mode** with Windows `portproxy` + FrankenPHP on `https://192.168.1.100:9443` and `http://192.168.1.100:8888`. See `docs/DEPLOYMENT.md`. Mirrored networking / mkcert / Caddy on `192.168.1.13` are no longer in use; this entry is kept as history.

- **Where:** machine environment (Windows/WSL networking), not app code
- **Problem:** Windows IP Helper service (`iphlpsvc`) binds `0.0.0.0:8000`, so Laravel can never bind it; `artisan serve` silently fell back to 8001/8002.
- **Resolution (root cause was Windows-side):** how Windows reaches WSL. `[wsl2] networkingMode=mirrored` in `.wslconfig` makes WSL share the host's LAN IP, and inbound TCP **8001 + 5173** firewall rules were added on the Windows host (admin). The app itself needed only minor config: `composer dev` pins `--port=8001`; `APP_URL=http://192.168.1.13:8001` and `VITE_HOST` in `.env` make Vite advertise the LAN IP so phones load CSS/JS from `http://<lan-ip>:5173` instead of `localhost`. The app is now reachable from other devices at `http://192.168.1.13:8001`. Reclaiming 8000 requires stopping `iphlpsvc` as Windows admin (optional).
- **Note:** `VITE_HOST` must match the machine's current LAN IP — update `.env` if it changes (WSL2 mirrored networking shares the Windows host IP).

### 4b. Camera blocked from other devices (secure-context rule) — SUPERSEDED
> **Superseded:** HTTPS is now served by FrankenPHP itself (`https://192.168.1.100:9443`, cert signed by the project-local "VMS-Livewire Local CA"); see `docs/DEPLOYMENT.md` §3 for the per-device CA install.
- **Where:** browser rule — `getUserMedia` only works on `localhost` or `https://`; plain `http://<ip>` never allows it.
- **Resolution (in place):** HTTPS via mkcert + Caddy proxy `https://192.168.1.13:8443` → `127.0.0.1:8001`; `bootstrap/app.php` trusts the proxy; built assets served same-origin (no `public/hot`). Full walkthrough in `docs/MOBILE-ACCESS.md`.
- **Still required (per new device):** install the mkcert root CA on the phone, and the Windows host still needs an inbound firewall rule for **TCP 8443** (admin):
  ```
  netsh advfirewall firewall add rule name="VMS WSL 8443" dir=in action=allow protocol=TCP localport=8443 profile=private,domain
  ```

### 4c. Kiosk API plain-HTTP port 8888 not reachable from devices (new port, no firewall rule) — RESOLVED
> **Resolved:** the current deployment has this in place — Windows Firewall rule `VMS-HTTP-8888` and portproxy to the isolated `Ubuntu-26.04` distro (NAT). Also fixed on the app side: `http://:8888` must be host-agnostic (a `192.168.1.100`-scoped site returned empty `200`s for other Host headers). See `docs/DEPLOYMENT.md` §1/§6.
- **Where:** machine environment (Windows Firewall / WSL2 mirrored), not app code.
- **Problem:** the kiosk RN app and device browser reach `https://192.168.1.4:9443` but fail on `http://192.168.1.4:8888` ("Cannot reach the server"). The WSL box serves 8888 on all interfaces, but the Windows host drops inbound TCP 8888 because no firewall rule exists for it (unlike 9443/8443/5173/8001, which were all opened previously).
- **Note:** the LAN IP changed from `192.168.1.13` → `192.168.1.4`. Whenever the PC's LAN IP or a kiosk port changes, Windows Firewall needs a matching inbound rule.
- **Fix (run once on the Windows host, elevated PowerShell):**
  ```
  netsh advfirewall firewall add rule name="VMS HTTP 8888" dir=in action=allow protocol=TCP localport=8888 profile=private,domain
  ```
- **App URL expected:** `http://192.168.1.4:8888` (React Native kiosk default in `src/config/store.ts`). No certificate needed over plain HTTP.


### 5. Vite bundle chunk-size warning
- **Where:** `resources/js/app.js` (vendored `html5-qrcode` → ~558 KB bundle)
- **Problem:** `npm run build` prints a chunk-size warning. Harmless, but adds load time.
- **Proposed fix:** lazy-load the QR scanner (`import()` on demand) or accept the warning.
- **Status:** 🔲 Not started

### 6. Flux Pro components not available
- **Where:** repo constraint (only free/core Flux ships)
- **Problem:** accordion, date-picker, tabs, file-upload, etc. unavailable; kiosk tabs are hand-rolled.
- **Status:** Known limitation
