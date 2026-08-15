# Kiosk Flows & System Notes

Internal notes on how the Visitor Management System (VMS) kiosk and its surrounding flows behave. Keep in sync when behavior changes.

## 1. Architecture (no traditional controllers)

- **Laravel 13 + Livewire 4 SFC + Flux UI v2 + Tailwind v4 + Fortify**.
- Livewire handles all UI; there are no HTTP controllers except small route closures (`/qr/{token}`, `/locale/{locale}`).
- Kiosk pages are inline SFCs using `layouts::kiosk`:
  - `pages::welcome` → `/` (`resources/views/pages/⚡welcome.blade.php`)
  - `pages::pre-register` → `/pre-register`
- Admin pages live under `pages::` and use the app layout.

## 2. Kiosk UI layout

- Page container: `max-w-7xl` full page; **header + content are NOT one card**.
- **Header** (full-width, `border-b`): logo + "Visitor Kiosk" title; right side shows **Today** and **On-site** counts (always visible on mobile, slightly smaller there). No subtitle, no language switcher (both removed/commented for now).
- Header content and main content are both constrained to `max-w-4xl mx-auto` so their left/right margins match ("contained" only on desktop; full-width on mobile).
- **Tabs** (segmented style built from scratch — Flux Pro `flux:tabs` not available): compact `bg-neutral-100` pill container (`py-0.5`, `rounded-md`, `max-w-sm`), active segment is raised `bg-white shadow-sm`. Two tabs:
  - **Check In** (`activeTab = 'checkin'`, default)
  - **On-Site Visitors** (`activeTab = 'onsite'`) with a live count badge
- Only the active tab renders. `resetForm()` returns to the Check In tab.
- After a successful check-in the **badge success view** replaces the tabs and shows a printable badge (`.no-print` + `#badge-print-area` print CSS).

## 3. Check-in flow (3-step wizard)

1. **Identity** — search existing visitor (`Visitor::search` by name/email/phone/ID) or select a pending pre-registration match, or "Register as new visitor".
   - Search result rows are fully clickable (no redundant "Select" button).
   - New-person form: Name (required), Email, Phone, Company, Valid ID Number, ID-photo capture (WebRTC), ID scan (Tesseract OCR → autofills name/email/phone/company).
   - **Validation:** `name` required; email/phone/validIdNumber optional. `company` was removed from rules (input is not `required`).
   - **Nothing is persisted during steps 1–2** — all data lives in component state until the final Check In. Abandoning mid-wizard creates no records.
2. **Visit** — host (employee directory search or custom), purpose, visit selfie (WebRTC).
3. **Confirm** — review person + visit, watchlist warning if `flaggedMatch`, then **Check In**.

## 4. Check-in execution (`checkIn()`)

- Loads existing visitor **or creates one** (persists `visitors` row — one-time person registration).
- **Assigns a permanent QR token** if the visitor doesn't have one yet (`visitors.qr_code_token`, 32 chars).
- Creates a `visitor_logs` row: status `checked_in`, `checked_in_at`, badge number (`V-YYYYMMDD-NNN`), host, purpose, visit selfie.
- Notifies host (`VisitorCheckedIn`); if watchlist match → marks visitor flagged + notifies all users (`VisitorFlagged`); consumes pre-registration (`status → used`).
- Shows badge view with the **visitor's** QR.

## 5. Check-out flows

### Manual (On-Site tab)
Search by name/host → **Check Out** → `confirmCheckOut()` → modal → `executeCheckOut()` sets `status = checked_out` + `checked_out_at`, notifies host (`VisitorCheckedOut`).

### QR
- Badge QR encodes `home?checkout=<visitor_token>`.
- Two entry points: **Scan QR** button (html5-qrcode) or visiting `/` with `?checkout=<token>` (handled in `mount()`).
- Token resolves the **visitor** (`visitors.qr_code_token`) → finds their **active visit** (`visitor_logs` where `status = checked_in`, latest) → confirmation modal → checks out that visit.
- If no active visit: error toast *"Invalid QR code or visitor is not on-site."* Nothing is checked out.

## 6. Permanent QR (important)

- QR is **per-visitor, not per-visit**. One token per visitor, reused across all future visits.
- `visitor_logs.qr_code_token` is no longer used (column kept for legacy data; factory no longer generates it).
- The same QR always targets whatever visit is currently active; after check-out it errors until the visitor checks in again.

## 7. Pre-registration flow

- Visitor books ahead at `/pre-register` (public, kiosk layout): Name (required), Email, Phone, Company, Purpose, Expected date. **No host field** (host is captured on-site at the kiosk instead — removed by design).
- Creates `pre_registrations` with `status = pending`. No host notification (no host known).
- At the kiosk, searching shows **pending** matches in a green "Pre-registered visit" section; selecting prefills name/email/phone/company/purpose (host fields now prefill empty).
- On check-in the pre-registration is marked `used` (single use).
- Admin: `pages::pre-registrations` (search, status filter, create/edit/cancel/delete). Host columns still exist for legacy data.

## 8. Data model (key columns)

| Table | Column | Notes |
|---|---|---|
| `visitors` | `qr_code_token` | nullable unique, permanent per visitor (added 2026-08-15; earlier column was dropped by `remove_remaining_visit_columns` migration — don't re-drop) |
| `visitors` | `photo`, `company`, `valid_id_number`, `is_flagged`, `notes` | person-level data |
| `visitor_logs` | `badge_number`, `host`, `host_user_id`, `purpose`, `photo`, `status`, `checked_in_at`, `checked_out_at` | per-visit; `qr_code_token` legacy/unused |
| `pre_registrations` | `name`, `email`, `phone`, `company`, `host`, `host_user_id`, `purpose`, `expected_date`, `status` | status: `pending` / `used` / `cancelled` |

## 9. Migrations gotchas

- A fresh migration run is required for `visitors.qr_code_token` (in-memory test DB migrates from scratch).
- Existing `2026_07_07_012922_add_qr_code_token_to_visitors_table.php` is effectively dead (table was recreated without the column later).

## 10. Notifications

- `VisitorPreRegistered` — legacy, no longer sent from the public form (admin create still sends it).
- `VisitorCheckedIn` — to host on check-in (if host set).
- `VisitorCheckedOut` — to host on check-out (if host set).
- `VisitorFlagged` — to all users when a watchlist match checks in.
