# Changelog

All notable changes to the sos-vault open-core appliance are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.2.3] - 2026-10-22

### Fixed
- Self-hosted appliance: the local AI assistant no longer fails to start on new installs. An upstream llama.cpp update renamed a startup option; the appliance now pins a tested llama.cpp build (b11783) so upstream changes can't break it again.
- sos-vault.com: the installer download pages now always offer the newest `.rpm` package, even if an older one is still published (website fix; no change to the appliance itself).

### Security
- Updated the front-end build tooling: `vite` to 6.4.5, fixing `launch-editor` issues [CVE-2026-112942](https://github.com/advisories/GHSA-587h-7wmj-h5pm), [CVE-2026-112943](https://github.com/advisories/GHSA-698v-4r3x-3vcv) and [CVE-2026-112944](https://github.com/advisories/GHSA-r825-w8mm-5pxw); and `follow-redirects` to 1.16.1, fixing credential-header leaks on redirects [CVE-2026-111755](https://github.com/advisories/GHSA-8r9p-f939-6h3c) and [CVE-2026-111756](https://github.com/advisories/GHSA-q3jh-wrc6-jgq8). These are build-time tools only and were never included in the application images.

## [2.2.2] - 2026-10-22

### Security
- The app image is rebuilt on PHP 8.4.27 (from 8.4.26), fixing two critical, unauthenticated remote code execution vulnerabilities in PHP's request handling: [CVE-2026-109618](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) (out-of-memory state corruption) and [CVE-2026-109619](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp) (stale global state). Update promptly.
- Updated Filament to 4.13.3, fixing [CVE-2026-104181](https://github.com/advisories/GHSA-7m6h-rg42-m449): multi-factor authentication management actions did not require password re-authentication.
- Updated `symfony/yaml` to 8.1.9, fixing [CVE-2026-109413](https://symfony.com/cve-2026-109413): a deeply nested YAML document could crash the PHP process (a development dependency, not included in the self-hosted appliance image).
- Turning off two-factor authentication now requires a current authenticator code or an unused recovery code, so a hijacked session alone can no longer remove it.
- The two-factor sign-in challenge and the disable form now allow 5 wrong codes per minute per user, blocking code guessing.

## [2.2.1] - 2026-10-06

### Security
- Patched `league/commonmark` (DisallowedRawHtml bypass; GitHub-flavored-markdown table quadratic-time denial of service) and `phpseclib/phpseclib` (CVE-2026-84308, non-constant-time X25519 key exchange).
- Removed a stray, unused `npm` package-manager dependency that pulled in several vulnerable transitive packages.
- Updated `postcss-selector-parser` and other front-end build dependencies flagged by our npm security audit.

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
