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
- **SQLite** default, all services (session/cache/queue) use `database` driver

## Architecture notes

- **No traditional HTTP controllers.** Fortify handles auth; Livewire handles settings UI.
- **Inline Livewire components** (`SFC`) in `resources/views/pages/settings/` embed `<?php new class extends Component {} ?>` directly in Blade. Routes use `Route::livewire('path', 'pages::settings.name')`.
- Each SFC template needs **exactly one root HTML element** — wrap multiple blocks in a single `<div>`.
- Always add **`wire:key`** on every item inside `@foreach` loops that can reorder or be removed.
- **View namespaces:** `pages::` → `resources/views/pages/`, `layouts::` → `resources/views/layouts/`, `flux::` → `resources/views/flux/`, `components::` → `resources/views/components/`.
- **Custom Blade components** (`<x-layouts::app>`, `<x-layouts::auth>`, etc.) for layout inheritance.

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
