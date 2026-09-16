# Changelog

All notable changes to the sos-vault open-core appliance are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial open-core release of the sos-vault self-hosted appliance under AGPLv3.
- Open-core baseline: single admin user, plain vault directory (default `/vault`), free forever, no telemetry.
- Hardware-bound licensing for the paid tier. Licenses are GPG-signed by the SaaS license-issuer key and validated against the live host's machine tokens (machine-id, baseboard serial, CPU id) so a `.lic` file cannot be transferred between machines.
- Filament admin gates: Groups CRUD, Modules install page, Event Log, Create User action, and several Manage Settings sections (Authentication, AI Assistant, Appliance Vaults, ServiceNow / ITSM) hide when no license is installed.
- Disk Manager: a single vault-directory input (default `/vault`) on every install. sos-vault stores its vaults in a plain directory (default `/vault`).
- "Generate License Request" action on the Manage License page produces a small encrypted sosreport bound to the host's machine tokens, ready for upload to sos-vault.com for license purchase.
- Time-based one-time-password (TOTP) two-factor authentication, enrolled at first sign-in and mandatory for admin accounts. Offline — no external service required.
- File Viewer renders the sosreport's own `sos_reports/sos.html` index as a live, navigable page: every collected file becomes a working in-app link instead of dead on-disk text.
- Mil AI assistant: a bundled local model for general help, with optional OpenAI or Anthropic cloud providers (configured in Manage Settings) for full sosreport analysis. See [`docs/AI_ASSISTANT.md`](docs/AI_ASSISTANT.md).
- Translations for the licensing surfaces in English, Spanish, Japanese, and German.
- Daily `sos-vault:check-license-expiry` scheduled command that emits a single `LICENSE_EXPIRED` event per newly expired license.
- `BlockUnlicensedNonAdmin` middleware logs out non-admin users and redirects them to the login screen when the license expires; the admin can still sign in to renew.
- `publish-opencore` GitHub Actions workflow that mirrors the appliance branch to the public open-core repository minus the SaaS-only paths listed in `scripts/opencore-deny-list.txt`.

### Notes

This is the first publicly-released version. Earlier development happened in a private monorepo; the in-monorepo history is not reproduced here.

[Unreleased]: https://github.com/sos-vault/sos-vault-appliance/commits/main
