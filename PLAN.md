# VMS Development Plan

## Overview
Visitor Management System (VMS) built on Laravel 13 + Livewire 4 SFC + Flux UI v2 + Tailwind v4.

---

## ✅ Phase 1 — Foundation

| Component | Status |
|---|---|
| Laravel setup with Fortify auth | ✅ |
| User model + migrations (passkeys, 2FA, etc.) | ✅ |
| App layout (sidebar, header, dark mode) | ✅ |
| Auth views (login, register, password reset, 2FA, email verify) | ✅ |
| Kiosk layout | ✅ |
| Navigation & routing (home, dashboard, visitors, settings) | ✅ |

---

## ✅ Phase 2 — Self-Service Check-in Kiosk

| Component | Status |
|---|---|
| `pages::welcome` — 3-step check-in wizard | ✅ |
| Manual identity entry (name, email, phone, company) | ✅ |
| Host search / custom host entry | ✅ |
| Purpose of visit | ✅ |
| Camera selfie capture (Alpine.js + WebRTC) | ✅ |
| Badge generation & printing | ✅ |
| Check-out workflow (search on-site → confirm → execute) | ✅ |
| On-site visitors list | ✅ |
| Flagged visitor / watchlist matching | ✅ |
| KPI header (today count, on-site count) | ✅ |

---

## ✅ Phase 3 — ID Scanning / OCR

| Component | Status |
|---|---|
| `tesseract-ocr` system package (v5.3.4) | ✅ |
| `thiagoalessio/tesseract_ocr` PHP wrapper (v2.13.0) | ✅ |
| ID scan button in Step 1 (Identity) | ✅ |
| Drag-and-drop ID card upload modal | ✅ |
| Image preview before processing | ✅ |
| Server-side Tesseract OCR text extraction | ✅ |
| OCR text parsing (name, email, phone, company) | ✅ |
| Extracted data review & edit in modal | ✅ |
| Apply & Continue / Clear & Re-scan workflow | ✅ |
| Raw OCR text expandable for debugging | ✅ |
| Livewire file upload (`WithFileUploads`) | ✅ |

---

## ✅ Phase 4 — Admin Dashboard

| Component | Status |
|---|---|
| `pages::dashboard` — KPI cards | ✅ |
| Visitors today count | ✅ |
| On-site now count | ✅ |
| Average visit duration | ✅ |
| Peak hour calculation | ✅ |
| Recent visitors table | ✅ |
| Chart.js integration (npm + Vite) | ✅ |
| Visitors per day bar chart | ✅ |
| Peak check-in hours bar chart | ✅ |
| Status distribution doughnut chart | ✅ |
| Avg visit duration line chart | ✅ |
| Date range filter (7/14/30/90 days) | ✅ |
| Pest tests for chart data (5 tests) | ✅ |

---

## ✅ Phase 5 — Visitor Log & Management

| Component | Status |
|---|---|
| `pages::visitors` — paginated visitor list | ✅ |
| Search (name, email, host, badge, company, phone) | ✅ |
| Status filter (on-site / checked out) | ✅ |
| Date range filter | ✅ |
| CSV export | ✅ |
| Photo lightbox (Alpine.js) | ✅ |
| View visitor details modal | ✅ |
| Delete visitor with confirmation + soft delete | ✅ |
| Row actions dropdown (flux:dropdown) with View/Delete | ✅ |

---

## ✅ Phase 6 — Settings & Profile

| Component | Status |
|---|---|
| Profile update (name, email, photo) | ✅ |
| Password change with validation | ✅ |
| Two-factor authentication (setup, confirm, recovery codes) | ✅ |
| Passkey authentication | ✅ |
| Appearance (theme toggle) | ✅ |
| Delete user account | ✅ |

---

## ✅ Phase 7 — User Management

| Component | Status |
|---|---|
| `pages::users` — paginated user list | ✅ |
| Search by name/email | ✅ |
| Sort by name, email, created date | ✅ |
| Create user modal (name, email, password) | ✅ |
| Edit user modal (name, email) | ✅ |
| Delete user with confirmation | ✅ |
| Self-deletion protection | ✅ |
| User initials avatar | ✅ |
| Email verification status indicator | ✅ |
| Sidebar navigation link | ✅ |
| Pest feature tests (9 tests) | ✅ |

---

## ✅ Phase 8 — Notifications & Alerts

| Component | Priority | Status |
|---|---|---|
| Email/database notification to host on check-in | Medium | ✅ |
| Flagged visitor real-time alert | Medium | ✅ |
| Check-out notification to host | Low | ✅ |
| Multi-language support (`__()`, language switcher on kiosk) | Low | ✅ |

## ✅ Phase 9 — Advanced Features

| Component | Priority | Status |
|---|---|---|
| QR code badge generation + scanning for check-in/out | Medium | ✅ |
| Advanced reporting with charts | Medium | ✅ |
| Watchlist management UI (`pages::watchlist`) with: | Low | ✅ |
| &nbsp;&nbsp;— Paginated flagged visitors list | Low | ✅ |
| &nbsp;&nbsp;— Search through flagged visitors | Low | ✅ |
| &nbsp;&nbsp;— View visitor details modal | Low | ✅ |
| &nbsp;&nbsp;— Edit watchlist notes modal | Low | ✅ |
| &nbsp;&nbsp;— Remove from watchlist (unflag) with confirmation | Low | ✅ |
| &nbsp;&nbsp;— Add to watchlist modal (search + flag any visitor) | Low | ✅ |
| &nbsp;&nbsp;— Sidebar navigation link (flag icon) | Low | ✅ |
| &nbsp;&nbsp;— Pest tests (8 tests) | Low | ✅ |
| Multi-tenant / multi-location support | Low | 🔲 |
| Pre-registration (visitor books visit ahead, kiosk detects match) | Low | 🔲 |
| Vehicle plate capture on check-in | Low | 🔲 |

---

## Infrastructure

| Item | Status |
|---|---|
| SQLite (default), database driver for all services | ✅ |
| Pest test suite (41+ tests across auth, settings, visitors, dashboard, watchlist) | ⚠️ Tests need DB migration in CI |
| Pint coding style (Laravel preset) | ✅ |
| CI: lint + test matrix on push/PR | ✅ |
| Flux Pro components not available | ⚠️ (free/core only) |

### Data model status (visitors table)

| Column | Type | Status |
|---|---|---|
| `photo` | nullable string (base64) | ✅ |
| `badge_number` | nullable string | ✅ |
| `company` | nullable string | ✅ |
| `host_user_id` | nullable FK → users.id | ✅ |
| `is_flagged` | boolean, default false | ✅ |
| `notes` | nullable text | ✅ |
| `vehicle_plate` | nullable string | ⬜ planned (Phase 9) |
| `qr_code_token` | nullable string, unique | ✅ |
