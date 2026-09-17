# Changelog

All notable changes to the sos-vault open-core appliance are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.2.0] - 2026-09-16

### Added
- **Alerts** — vault-wide alert rules (file-pattern match, metric threshold, or reference-case diff) evaluated automatically on every new sosreport upload, with in-app, email, and event-log/SIEM delivery on every install (Slack delivery requires a license).
- **Compliance & Exposure Engine** — every uploaded sosreport is automatically assessed against CIS, STIG, and Docker Bench benchmarks, plus a network/software/identity/systemd exposure check, no configuration required. Free on every plan and appliance install.

### Security
- Hardened nginx TLS and security headers: HSTS, a staged Content-Security-Policy, and a www → bare-domain redirect.
- Updated dependencies flagged by our Composer security audit.

## [2.1.2] - 2026-08-30

### Fixed
- sosreport uploads whose filename can't be reliably parsed are now rejected with a clear error instead of being silently accepted — this fix shipped to sos-vault SaaS in 2.1.1 and is now fully in sync on the self-hosted appliance. No other functional changes.

## [2.1.1] - 2026-08-19

### Added
- **View Fleet** — a fleet-wide view that groups your sosreports by host, so you can see each machine's upload history at a glance and drill into any host's timeline.

### Fixed
- AI provider, ServiceNow (ITSM), and AWS credentials configured in Manage Settings are now encrypted at rest.
- Updated dependencies flagged by our Composer security audit.
- sosreport uploads whose filename can't be reliably parsed are now rejected with a clear error instead of being silently accepted.

## [2.1.0] - 2026-07-15

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

[Unreleased]: https://github.com/sos-vault/sos-vault-appliance/compare/v2.2.0...main
[2.2.0]: https://github.com/sos-vault/sos-vault-appliance/compare/v2.1.2...v2.2.0
[2.1.2]: https://github.com/sos-vault/sos-vault-appliance/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/sos-vault/sos-vault-appliance/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/sos-vault/sos-vault-appliance/releases/tag/v2.1.0
