# CLAUDE.md

## Current work
- `HANDOFF.md` (repo root) — lean current-state status + durable conventions. The Self-Hosted / Customer Portal / Paddle sprint is complete (shipped as 2.1.0); full sprint history is preserved in git. Read it before any licensing / Paddle / portal / open-core work.
- `docs/architecture.md` — project architecture overview.
- `docs/laravel-boost-guidelines.md` — full Laravel Boost framework rules. Read on demand when needed; not load-bearing for most turns.

## Stack versions
php 8.4.10 · laravel/framework 12 · filament/filament 4 · livewire/livewire 3 · livewire/volt 1 · laravel/folio 1 · laravel/socialite 5 · laravel/boost 2 · laravel/dusk 8 · laravel/mcp 0 · laravel/pail 1 · laravel/pint 1 · pestphp/pest 4 · phpunit 12 · alpinejs 3 · tailwindcss 4

## Project conventions
- Follow existing code conventions — check sibling files before creating/editing.
- Descriptive names (`isRegisteredForDiscounts`, not `discount()`).
- Reuse existing components before writing new ones.
- Do not refactor unrelated code. Preserve existing patterns. Make the smallest safe change.
- Avoid schema changes unless necessary.
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change dependencies without approval.
- Only create documentation files if explicitly requested.
- Be concise — focus on what's important.

## Configuration: settings table, NOT .env
- All NEW configuration values (secrets, tunables, vendor IDs, feature flags) must be stored in the wave `settings` table (or the existing `plans` table when the value is a plan attribute), never in `.env`.
- `.env` is reserved for things that truly must be environment-bound (DB creds, Paddle API key/vendor id, mail host/port, `APP_*`, `SESSION_*`, etc.) and for existing entries. Do not introduce new `env('FOO', ...)` calls in `config/*.php`.
- For admin-editable secrets or tunables, add a field to the admin "Manage Settings" page (`app/Filament/Pages/ManageSettings.php`) — create a new section if none fits. Sensitive values (e.g. licensing passphrases, API secrets) must be encrypted at rest via `App\Services\LicensingPassphraseService` (or an analogous service kept in `App\Services` so `SvaultKeyStub` can shadow it in tests) and never rendered back to the browser.
- Paddle price IDs live on the `plans` table (`monthly_price_id`, `yearly_price_id`, `onetime_price_id`). Resolve them via a plan lookup (`Wave\Plan::where('slug', ...)`), not env vars. The Self-hosted bundle is `slug='standalone'`.

## Unit tests
- Unit tests MUST NOT affect the main database. Always use the in-memory DB. Before each test run, back up the existing database; after, revert from the backup and verify it works before removing the backup.
- Do not create verification scripts or `tinker` snippets when tests already cover the functionality.
- Every change must be programmatically tested (new or updated test). Run `php artisan test --compact` with a filter to run the minimum set.
- sosreport test fixtures live under `test_data/`. Tests decrypt them with the passphrase in `env('TEST_FIXTURE_PASSPHRASE')` — set it in your local `.env` (gitignored) or export it in CI.

## Filament v4 — project-specific gotchas
- Actions namespace is `Filament\Actions\` (NOT `Filament\Tables\Actions\` or `Filament\Forms\Actions\`).
- Form fields: `Filament\Forms\Components\`. Infolist entries: `Filament\Infolists\Components\`. Layout: `Filament\Schemas\Components\`. Schema utilities (`Get`, `Set`): `Filament\Schemas\Components\Utilities\`. Icons: `Filament\Support\Icons\Heroicon`.
- Public file visibility is NOT default — use `->visibility('public')` when needed.
- `Grid`, `Section`, `Fieldset` do not span all columns by default — set column spans explicitly.
- CustomerPortal panel id is `'portal'` (singular). In tests, call `Filament::setCurrentPanel('portal')` before `Livewire::test(...)`.

## Pint (formatter)
After modifying PHP files: `vendor/bin/pint --dirty --format agent`. Do not pass `--test`.

## Common commands
```bash
composer dev                                    # full dev stack (server + queue + logs + vite)
php artisan serve
npm run dev                                     # or: npm run build
php artisan test --compact
php artisan test --compact --filter=TestName
vendor/bin/pint --dirty --format agent
php artisan migrate
php artisan db:seed
```

## Skills
The Claude Code harness auto-loads skill descriptions every turn — do not re-list them here. Activate the relevant skill (`folio-routing`, `livewire-development`, `volt-development`, `pest-testing`, `tailwindcss-development`, `laravel-permission-development`, `socialite-development`) when the domain matches.
