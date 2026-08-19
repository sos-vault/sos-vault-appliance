# Feature matrix — open-core vs. Pro

This is the canonical reference for what sos-vault gives you for free and what requires an active license. The runtime checks reference `applianceLicensed()` / `applianceUnlicensed()` in `app/Helpers/sosVaultHelper.php`, which simply ask whether a non-expired `LocalLicense` row exists.

## Always available (open-core baseline, free forever)

- **Single admin user.** The account planted by `ApplianceAdminSeeder` on first boot.
- **Plain vault directory.** Default `/vault`, configurable from Disk Manager. Not LUKS-encrypted.
- **Full sosreport import / analysis pipeline.** Upload, decrypt, browse, annotate, export. The core value of the product.
- **Local AI Assistant runtime.** Uses the bundled Ollama model. The Assistant *settings* page is gated; the chat itself works.
- **Mail (SMTP) configuration.** So the single admin can recover their password.
- **Standalone documentation** at `/blog/standalone/*`, served from the seeded blog category.
- **Manage License page.** Generate License Request + Install License sections are always visible — that is how you get into the licensed tier.
- **Disk Manager.** Single vault-directory text input (default `/vault`). A plain directory on every install, licensed or not.
- **Certificate Manager.** TLS replacement and corporate root CA install (so HTTPS works regardless of license state).
- **Capture Server Report.** Useful for support escalation; available at `/admin/manage-license` and as the `sos-vault:capture-server-report` artisan command.

## Requires an active license

Installing a valid signed `.lic` file unhides:

- **Multi-user.** Up to the license's seat cap. The original admin is always permitted; additional Team Member users count against the seat budget. `User::creating()` enforces the cap.
- **Groups (teams).** Admin creates a Group from the Filament panel; a LUKS-backed shared vault is provisioned synchronously. All Team Member users assigned to the group share the same vault. The full GroupResource CRUD reappears.
- **Module installation.** The Modules admin page accepts `.tar.gz` and signed `.tar.gz.gpg` module packages, with per-license feature gating via each module's `manifest.json`.
- **ITSM integration.** ServiceNow / Jira / Salesforce ticket creation and update. The settings credentials section reappears.
- **SIEM integration.** Forward every recorded event to an external SIEM over Syslog (UDP / TCP / TLS) in ECS (JSON) or RFC 5424 format, each message tagged `LOGTYPE="sos-vault"`. The SIEM Integration settings section reappears; forwarding also stops at runtime if the license lapses.
- **Event Log.** The full audit trail of vault open / close / expand / shrink, license install / expire, login blocks, group create / delete, certificate replace, and more.
- **AI Assistant settings page.** Switching providers (local Ollama / OpenAI / Anthropic), model selection, system prompt customisation, rate limits.
- **Appliance Vaults settings.** Default size for newly-provisioned group vaults.
- **Authentication settings page.** Sign-in providers, password complexity rules, default role.

## What happens when the license expires

The expiry check runs on every request via `LocalLicense::current()` (the query filters `expires_at > now()`). Once the license has lapsed:

- Gated UI surfaces re-hide on the next page load.
- Non-admin users are logged out at the next request by the `BlockUnlicensedNonAdmin` middleware and redirected to the login screen with a "license expired" flash.
- The admin can still sign in to renew.
- **Existing extra users and groups remain in the database.** Renewing the license restores access with zero data loss.

The daily `sos-vault:check-license-expiry` scheduled command emits a single `LICENSE_EXPIRED` event the first time it sees a freshly expired license. The `expiry_event_logged_at` column dedupes so subsequent runs do not re-emit.

## Why this boundary

The features behind the license are the ones a team uses, not the ones a solo operator uses. A homelab analyst or a single-system administrator gets the entire core value of sos-vault for free, indefinitely. A team of N analysts buying N seats funds the project.

We picked this boundary specifically to avoid the open-core anti-pattern of "the free version is intentionally crippled." Everything in the open-core baseline is fully functional — it just only serves one person.
