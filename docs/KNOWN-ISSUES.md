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

### 4. Port 8000 unusable (WSL2 mirrored networking)
- **Where:** machine environment (Windows/WSL networking), not app code
- **Problem:** Windows IP Helper service (`iphlpsvc`) binds `0.0.0.0:8000`, so Laravel can never bind it; `artisan serve` silently fell back to 8001/8002.
- **Resolution (root cause was Windows-side):** how Windows reaches WSL. `[wsl2] networkingMode=mirrored` in `.wslconfig` makes WSL share the host's LAN IP, and inbound TCP **8001 + 5173** firewall rules were added on the Windows host (admin). The app itself needed only minor config: `composer dev` pins `--port=8001`; `APP_URL=http://192.168.1.13:8001` and `VITE_HOST` in `.env` make Vite advertise the LAN IP so phones load CSS/JS from `http://<lan-ip>:5173` instead of `localhost`. The app is now reachable from other devices at `http://192.168.1.13:8001`. Reclaiming 8000 requires stopping `iphlpsvc` as Windows admin (optional).
- **Note:** `VITE_HOST` must match the machine's current LAN IP — update `.env` if it changes (WSL2 mirrored networking shares the Windows host IP).

### 5. Vite bundle chunk-size warning
- **Where:** `resources/js/app.js` (vendored `html5-qrcode` → ~558 KB bundle)
- **Problem:** `npm run build` prints a chunk-size warning. Harmless, but adds load time.
- **Proposed fix:** lazy-load the QR scanner (`import()` on demand) or accept the warning.
- **Status:** 🔲 Not started

### 6. Flux Pro components not available
- **Where:** repo constraint (only free/core Flux ships)
- **Problem:** accordion, date-picker, tabs, file-upload, etc. unavailable; kiosk tabs are hand-rolled.
- **Status:** Known limitation
