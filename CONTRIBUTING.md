# Contributing to sos-vault

Thanks for your interest. This document covers the practical "how do I get a PR landed" workflow.

## Code of conduct

By participating you agree to abide by the [Code of Conduct](CODE_OF_CONDUCT.md). Be kind, assume good faith, focus on the work.

## What we welcome

- **Bug fixes** — please include a reproduction, the platform/PHP/Filament versions you tested on, and a test that fails before your fix and passes after.
- **Documentation improvements** — typos, clarifications, missing screenshots, broken links, better examples.
- **New modules** — see [`docs/MODULES.md`](docs/MODULES.md) for the manifest format.
- **Translation review** — the four shipped locales (en, es, ja, de) were authored by the maintainers; native-speaker review is invaluable.
- **Performance work** with benchmarks attached.

## What we are cautious about

- **Large refactors without prior discussion.** Open an issue first so we can agree on the shape before you invest hours.
- **New top-level dependencies.** Composer / npm packages add supply-chain surface; expect to justify them.
- **Features that overlap with the licensed Pro tier.** This is not a hard "no" — the boundary is documented in [`docs/FEATURES.md`](docs/FEATURES.md) — but please open an issue first to talk through whether a contribution belongs in open core or as a paid feature.
- **Telemetry, "anonymous usage stats," or auto-update pings.** sos-vault is opt-in only.

## Local setup

```bash
git clone https://github.com/sos-vault/sos-vault-appliance.git
cd sos-vault-appliance
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate --seed
composer dev      # starts php artisan serve + queue + vite + pail
```

The full stack runs at `http://localhost:8000`. The Filament admin panel is at `/admin`. Default seeded admin credentials are documented in `.env.example`.

## Running tests

```bash
php artisan test --compact
php artisan test --compact --filter='Appliance|License|OpenCore'   # focus on licensing surface
```

Tests use an in-memory SQLite database — they do not touch your local Postgres/MySQL instance. Adding a new feature without a corresponding test will block the PR.

## Code style

```bash
vendor/bin/pint --dirty --format agent
```

Run this before each commit. The CI will fail otherwise.

## Pull request checklist

- [ ] Branch is up to date with `main`.
- [ ] All tests pass locally (`php artisan test --compact`).
- [ ] Pint produced no diff.
- [ ] New behaviour is covered by a test.
- [ ] If the PR adds user-visible strings, the four language files (`lang/{en,es,ja,de}/*.php`) have all been updated.
- [ ] If you touched any file under `app/Filament/`, you exercised the change in a browser before opening the PR.
- [ ] PR title is short (under 70 chars). The body covers the **why**, not just the what.

## Sign your commits (optional but appreciated)

```bash
git commit -s -m "fix: …"
```

The `-s` adds a `Signed-off-by:` trailer, which records that you have read and accept the terms in the AGPLv3.

## Reporting security issues

Do **not** open a public issue. Email `security@sos-vault.com`. See [`SECURITY.md`](SECURITY.md) for the full policy.
