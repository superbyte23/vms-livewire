# Pre-Registration Design

Date: 2026-08-14

## Overview

Add visitor pre-registration to the VMS so visitors can book a visit ahead of time and the kiosk detects the match at check-in. Includes a self-service public page and a full admin CRUD page. Built on Laravel 13 + Livewire 4 SFC + Flux UI v2 + Tailwind v4 + Pest 4.

## Data model

New `PreRegistration` model (UUID pk, `HasFactory`, `SoftDeletes`, matching `Visitor`/`VisitorLog` conventions):

| Column | Type |
|---|---|
| `id` | uuid PK |
| `name` | string |
| `email` | nullable string |
| `phone` | nullable string (20) |
| `company` | nullable string |
| `host` | nullable string |
| `host_user_id` | nullable FK → users (nullOnDelete) |
| `purpose` | nullable string |
| `expected_date` | nullable date (admin planning only; ignored by kiosk matching) |
| `status` | string, default `pending` |
| `notes` | nullable text |
| `timestamps` + `softDeletes` | |

Scopes: `search(name|email|company|host)`, `pending()`, `status()`.

## Status lifecycle

- `pending` — created by public page or admin
- `used` — visitor checked in at the kiosk using the pre-registration
- `cancelled` — admin cancels; record retained

## Public page (`pages::pre-register`)

Route: `GET /pre-register`, name `pre-register`, kiosk layout.

Form: name, email, phone, company, host (searchable user list + custom host option, same pattern as kiosk step 2), purpose, optional expected_date.

Submit:
- validates required name; validates host_user_id nullable; optional fields nullable
- creates `PreRegistration` with status `pending`
- notifies host via `VisitorPreRegistered` (email + database) when `host_user_id` set
- shows success state (toast + reset form)

## Kiosk integration (`pages::welcome` / `⚡welcome.blade.php`)

Step 1 search:
- Extend results to include pending pre-registrations matching by name/email/phone, rendered under a "Pre-registered visit" section above existing person results.
- Selecting a pre-registration sets `selectedPreRegistrationId`, prefills `name`, `email`, `phone`, `company`, `host`, `hostUserId`, `purpose`, and clears `selectedVisitorId` (person is created at check-in).

Check-in:
- When `selectedPreRegistrationId` is set, reuse the existing create-visitor path (no `Visitor` person exists yet) and after the `VisitorLog` is created, mark the pre-registration `used`.
- Existing notifications (`VisitorCheckedIn`, `VisitorFlagged`) are unchanged.
- If a flagged person is checked in via a pre-registration, the person record is flagged as today.

## Admin page (`pages::pre-registrations`)

Route: `GET /pre-registrations`, name `pre-registrations`, app layout, auth middleware, sidebar link.

Features:
- Paginated list (10/page), search by name/email/company/host
- Status filter tabs: all / pending / used / cancelled
- Create modal (same fields as public page)
- Edit modal (name, email, phone, company, host, purpose, expected_date)
- View details modal
- Cancel with confirmation (status → `cancelled`)
- Delete with confirmation (soft delete)
- Status + pending-date rendered with Flux badges

## Notifications

New `VisitorPreRegistered` notification (mail + database channels) to the host user. Template shows visitor name, company, phone, purpose, expected_date.

## Testing (Pest)

- Public submission: creates pending record, notifies host, validation errors
- Kiosk search returns matching pending pre-registrations
- Kiosk check-in with selected pre-registration: creates Visitor person, creates VisitorLog, marks pre-registration `used`
- Admin: list, search, status filter, create, edit, cancel, delete
- Auth: admin page requires login; public page is open

## Files touched

- `database/migrations/<timestamp>_create_pre_registrations_table.php`
- `app/Models/PreRegistration.php`
- `app/Notifications/VisitorPreRegistered.php`
- `resources/views/notifications/visitor-pre-registered.blade.php` (mail template)
- `resources/views/pages/⚡pre-register.blade.php` (new SFC)
- `resources/views/pages/⚡pre-registrations.blade.php` (new SFC)
- `resources/views/pages/⚡welcome.blade.php` (kiosk matching + check-in)
- `resources/views/layouts/app/sidebar.blade.php`
- `routes/web.php`
- `tests/Feature/PreRegistrationTest.php`
