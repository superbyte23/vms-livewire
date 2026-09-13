# AGENTS.md

This repo bundles a `tall-stack-expert.skill` file with detailed references for **Livewire 4 SFC**, **Flux UI v2**, and **Pest testing**. Load it via the skill tool when writing or debugging components/views/tests.

## Quick start

```bash
composer setup          # full install: composer install + .env + key:generate + migrate + npm i + build
composer dev            # runs serve + queue:listen + vite concurrently
```

## Commands

| command | what |
|---|---|
| `composer lint` | Pint (auto-fix) |
| `composer lint:check` | Pint (dry-run) |
| `composer test` | `config:clear` → `lint:check` → `php artisan test` |
| `./vendor/bin/pest` | run tests directly (CI does this) |
| `npm run build` / `npm run dev` | Vite build / dev server |
| `php artisan migrate` | SQLite at `database/database.sqlite` by default |

## Stack

- **Laravel 13** + **Livewire 4 SFC** + **Flux 2** + **Laravel Fortify** (auth backend)
- **Tailwind CSS v4** via `@tailwindcss/vite`
- **Pest PHP 4** for testing (function-based, `pest()->extend(TestCase::class)`)
- **Pint** for code style (preset: `laravel`)
- **MySQL** default connection (`DB_CONNECTION=mysql`), dedicated `vms` database + user. Tests run on in-memory **SQLite** (`DB_DATABASE=:memory:`), so raw SQL must be portable — `resources/views/pages/⚡dashboard.blade.php` `hourExpression()` switches between `DATE_FORMAT` (MySQL) and `strftime` (SQLite).

## Architecture notes

- **No traditional HTTP controllers.** Fortify handles auth; Livewire handles settings UI.
- **Inline Livewire components** (`SFC`) in `resources/views/pages/settings/` embed `<?php new class extends Component {} ?>` directly in Blade. Routes use `Route::livewire('path', 'pages::settings.name')`.
- Each SFC template needs **exactly one root HTML element** — wrap multiple blocks in a single `<div>`.
- Always add **`wire:key`** on every item inside `@foreach` loops that can reorder or be removed.
- **View namespaces:** `pages::` → `resources/views/pages/`, `layouts::` → `resources/views/layouts/`, `flux::` → `resources/views/flux/`, `components::` → `resources/views/components/`.
- **Custom Blade components** (`<x-layouts::app>`, `<x-layouts::auth>`, etc.) for layout inheritance.

## Icons (Tabler by default)

- **Use Tabler icons for all new icon work** via `superbyte/blade-tabler-icons` (path repo at `packages/blade-tabler-icons`, 6,184 outline + filled icons).
- `<x-tabler-heart class="w-5 h-5" />`, `@svg('tabler-'.$name, '...')` for dynamic names, or Flux props after generating views: `php artisan tabler-icons:flux star heart` → `icon="tabler.star"` / `icon="tabler-filled.trash"`.
- Generated Flux views default to 16px (`size-4`); pass `class=` to override.
- Browse names at tabler.io/icons.

## Testing quirks

- `./vendor/bin/pest` runs all tests (no `phpunit` wrapper needed).
- Feature tests use `pest()->extend(TestCase::class)` → `RefreshDatabase` is **commented out** in `tests/Pest.php`.
- Base `TestCase` provides `skipUnlessFortifyHas(string $feature)` — tests that depend on optional Fortify features should call this.
- Tests use in-memory SQLite (`DB_DATABASE=:memory:` in `phpunit.xml`).
- Default to `Livewire::test('component.name')` → `set()` → `call()` → `assertSet()` / `assertHasErrors()` / `assertDispatched()`.

## Environment & setup gotchas

- **Flux requires auth:** `composer config http-basic.composer.fluxui.dev "$FLUX_USERNAME" "$FLUX_LICENSE_KEY"` before `composer install`. CI does this via secrets.
- `.npmrc` sets `ignore-scripts=true` — installs won't run postinstall hooks.
- Password rules differ by env: `APP_ENV=production` enforces `min:12+mixed+nums+symbols+uncompromised`; local has no constraint.
- Rate limiters registered in `FortifyServiceProvider`: login (5/min), two-factor (5/min), passkeys (10/min).
- Flux Pro components (accordion, date-picker, tabs, file-upload, etc.) are **not available** — only free/core tier ships with this repo.

## CI pipeline

Two workflows on push/PR to `main|master|develop|workos`:
1. **lint.yml** — PHP 8.4, Pint check
2. **tests.yml** — PHP 8.3/8.4/8.5 matrix, `npm i && npm run build && ./vendor/bin/pest`

## Development plan

See [`PLAN.md`](./PLAN.md) for the full phased development roadmap — 9 phases tracking what's built, what's in progress, and what's planned.

## Key app structure

```
app/
├── Actions/Fortify/        # CreateNewUser, ResetUserPassword
├── Concerns/               # PasswordValidationRules, ProfileValidationRules
├── Livewire/Actions/       # Logout (single-action invokable)
├── Models/User.php         # HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable
├── Providers/              # AppServiceProvider, FortifyServiceProvider
config/fortify.php          # features: registration, resetPasswords, emailVerification, 2FA, passkeys
resources/views/
├── pages/auth/             # Fortify auth views (login, register, etc.)
├── pages/settings/         # Inline Livewire components (profile, security, appearance, delete-user)
├── layouts/                # app/ (sidebar or header), auth/ (simple, card, split)
├── components/             # Shared components
└── flux/                   # Flux overrides (icons, navlist)
routes/
├── web.php                 # home + dashboard (auth+verified)
└── settings.php            # profile, appearance, security routes
tests/
├── Feature/Auth/           # Full auth flow tests
├── Feature/Settings/       # Profile + security tests
└── Unit/                   # Basic unit test
```

## Production (Octane + RoadRunner on `:8085`)

- **RoadRunner v2025.1.15** (`./rr`) via Octane serves `0.0.0.0:8085`. Supervisor programs: `vms-roadrunner` (user `root`) + `vms-worker` (user `www-data`, `numprocs=2`, `queue:work redis`). Confs in `/etc/supervisor/conf.d/vms-{roadrunner,worker}.conf` (mirror `laravel-*`).
- **`.env`:** `production`, `DEBUG=false`, `APP_URL=https://192.168.1.17:8085`, `OCTANE_SERVER=roadrunner`, `OCTANE_HTTPS=true`, `CACHE_PREFIX=vms_`. Backup: `.env.pre-octane.bak`. DB/Redis/queue hosts unchanged (`mysql vms:3306`, `redis:6379`).
- **Reachability:** `https://127.0.0.1:8085` (Windows via WSL forwarding), `https://192.168.1.17:8085` (LAN/kiosk). Plain HTTP only on loopback `http://127.0.0.1:18085`: browser `GET`/`HEAD` requests 302-redirect to `https://<same-host>:8085` (`app/Http/Middleware/RedirectDebugHttpToHttps.php`, ports via `config/app.php` `https_port`/`http_debug_port` — port-gated, never scheme-gated, or `:8085` would loop behind RR TLS termination); `/up` and non-cacheable methods pass through for curl/health/API use. Asset hosts follow the request host, so LAN clients get correct URLs automatically.

### Deploy / rebuild

```bash
composer install --no-interaction --prefer-dist --optimize-autoloader
npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan migrate --force
sudo supervisorctl restart vms-roadrunner   # ~60s port-check delay, brief :8085 downtime
sudo supervisorctl restart vms-worker:*
```

- Never run `composer test` in prod — its `config:clear` wipes the prod config cache. Run `./vendor/bin/pest` directly instead, then re-cache if needed.
- `octane:status` / `octane:reload` report "not running" (stale `storage/logs/octane-server-state.json`, same as the `:8080` reference) — Supervisor is the source of truth; use `restart`.
- Keep `storage/` + `bootstrap/cache/` owned `ser_john:www-data` with `g+w` (root-run workers write logs there).

### Offline-install notes (GitHub unreachable from this box)

- Octane stack was assembled from `/var/www/laravel` `vendor/` (16/19 exact versions); `rr` binary + `config/octane.php` copied; `vendor/bin/{rr,roadrunner-worker}` proxies restored manually.
- `composer.lock` pins `google/protobuf v5.36.0`, `symfony/http-client v8.1.5`, `http-client-contracts v3.7.1` (installed) vs upstream `.1/.6/.3`. Original lock at `composer.lock.pre-offline-pin`. When online: `composer update google/protobuf symfony/http-client symfony/http-client-contracts`, then delete the backup.
- Always pass `--no-interaction` to composer here, otherwise a network stall hangs on a token prompt / `git clone`.

### Gotchas

- Redis DB 0 is shared across apps, but key prefixes separate traffic (`visita-database-` from the `APP_NAME` slug + `CACHE_PREFIX=vms_`).
- Tesseract binary missing → ID OCR 500s (pre-existing, not Octane-caused).
- Queued closures defined via `tinker < stdin` can't serialize (no source file) — test queues with file-backed code or real `ShouldQueue` Notification classes.
- A stale `:8010` listener exists from another distro — unrelated, leave alone.

### HTTPS (RoadRunner native TLS, any-IP)

- Still 100% Octane: same `vms-roadrunner` program, only `.rr.yaml` + env changed. RR 2025 schema is **`http.ssl`** (not `http.tls` — silently ignored): `http.ssl.address=0.0.0.0:8085` + `cert/key` → `.certs/rr-server.pem`.
- Octane `--host/--port` still defines the plain-HTTP listener → set to loopback-only (`127.0.0.1:18085`) so `:8085` is TLS-only. Plaintext on `:8085` gets HTTP 400.
- **DHCP-safe certs:** `.certs/gen-server-cert.sh` runs before every (re)start (Supervisor wraps the command; cert failure = fail-fast, no PHP boot). It signs with the stable CA (`ca.pem`/`ca-key.pem`, clients install `ca.pem` once) and covers all current WSL + Windows IPs (via `powershell.exe ipconfig` interop), `127.0.0.1`, `localhost`, hostname, `vms.local`. Override/add IPs via `EXTRA_IPS` at the top of the script. 825-day validity.
- Key perms matter: `rr-server-key.pem` must be readable by the runner — script sets `root:www-data`/`640` when root, leaves owner files otherwise (ser_john is in `www-data`).
- `OCTANE_HTTPS=true` only forces `https://` absolute URLs (QR/badge links) — it does NOT enable TLS by itself.
- Verify: `curl -sk https://127.0.0.1:8085/login` → 200; `openssl s_client -connect 127.0.0.1:8085 -CAfile .certs/ca.pem` → `Verify return code: 0 (ok)`.
- Phone/kiosk checklist: install `ca.pem` as trusted CA on each device, browse `https://192.168.1.17:8085` (or current LAN IP), confirm no warning + kiosk camera works (secure context required for `getUserMedia`).
