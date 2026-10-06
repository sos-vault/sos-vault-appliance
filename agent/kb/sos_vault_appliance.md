# Self-Hosted Appliance — Operator & Admin Reference

For operators running the sos-vault **self-hosted appliance** from the `/admin` panel.
Answer from these; use the exact page/command names. Full docs live in the app under
**Documentation** (`/blog/standalone/*`). Analyst app usage is in the sos-vault usage reference.

## Common operator questions (answer from these — do not invent steps)
- **How do I install the appliance?** Install the package (`sudo apt-get install ./sos-vault.deb`
  on Ubuntu/Debian, `sudo dnf install ./sos-vault.rpm` on RHEL/Rocky/AlmaLinux), then run the
  interactive installer: `sudo /opt/sos-vault/sysadmin/installer.sh`. Rehearse safely with
  `--dry-run`. After it finishes, open `https://<host>/admin` and sign in.
- **How do I get and install a license?** **Manage License → Request a License → Generate
  License Request** produces a short `SOSV1.` key carrying only this host's hardware
  fingerprint. Paste it at sos-vault.com under *Verify License Request*, purchase, download the
  signed `.lic`, then upload it under **Manage License → Install License**. Until a license is
  installed only the single admin can exist.
- **What do I get for free vs. with a license?** Open-core baseline (no license) = **one admin
  user**, a plain (unencrypted) vault directory, mail config, and the full sosreport
  upload/decrypt/browse/analyse pipeline. A license unlocks **multi-user** (up to the seat cap),
  **Groups/teams** with LUKS-encrypted shared vaults, **module installation**, **ITSM**
  (ServiceNow/Jira/Salesforce), the **Event Log** audit trail (with optional **SIEM forwarding**),
  and the AI Assistant / Auth / Appliance Vaults settings pages.
- **How do I add users / teams?** With an active license, create users from the admin panel up
  to the license seat cap (over-cap creation is refused); use **Groups** to create teams, each
  owning its own vault. Without a license, user creation is blocked beyond the admin.
- **Where does the vault live / how do I change it?** **Disk Manager** sets the vault directory
  (default `/vault`), stored in the `settings` table. It is a plain directory — no ZFS required
  — and can sit on the system disk, a dedicated mount, or an OS-mounted network share (NFS/CIFS).
- **How do I replace the TLS certificate?** **Certificate Manager → Replace Server Certificate**:
  upload `fullchain.pem` + `privkey.pem` (it validates them and reloads nginx). Upload an
  internal CA under **Corporate Root CA** on the same page.
- **How do I download the AI model?** **Software Updates → Download AI model** (top section).
  The ~1.1 GB local model is fetched in the background and the assistant enables itself when
  done. Needs outbound HTTPS for the duration; only needed once.
- **Does the appliance phone home?** No. Licensing is verified offline once the `.lic` is
  uploaded; there is no telemetry. Only operator-initiated module downloads and the optional AI
  model pull make outbound calls.

## Licensing details
- The license binds to the host fingerprint (machine-id + DMI identifiers). **Moving to new
  hardware requires a new license** — regenerate the request key on the new host and order a
  replacement `.lic`.
- Status shows on the installed-license card and the dashboard **Appliance License** widget:
  ACTIVE / EXPIRED / REVOKED, seats used/total, granted features, expiry + "expiring soon".
- **On expiry** the gated UI re-hides, non-admin users are blocked at the next request (redirected
  to login), but existing users/groups stay in the database untouched — renewing restores access
  with zero data loss. Renewals extend from the previous expiry, so renewing early loses no days.
- **"machine token mismatch" on install** = the `.lic` tokens don't include this host's fingerprint
  (usually changed hardware). Regenerate the request key, re-verify at sos-vault.com, get a new `.lic`.

## Two-factor authentication (2FA)
- Offline TOTP; works air-gapped. **Mandatory for admins** (enrol on first sign-in at
  **Settings → Security**), optional for other users. Governed by the
  `auth.two_factor_required_for_admins` setting (default on).
- **Break-glass (locked-out admin)** — from a shell on the host:
  `docker compose exec -T <app-container> sudo -u www-data php artisan 2fa:disable <email|username|id>`,
  then have them sign in and re-enrol. If codes are rejected, fix host time sync (NTP/chrony).

## Manage Settings (appliance)
- **Always visible:** Mail (SMTP) — so the admin can receive password-reset mail on the baseline.
- **Licensed only:** Authentication, AI Assistant, Appliance Vaults (default new-group vault size),
  ServiceNow/ITSM, SIEM Integration. SaaS-only sections (Site, Billing, Social Auth, Analytics,
  etc.) are always hidden.

## SIEM Integration
- **What is a SIEM?** A Security Information and Event Management system (e.g. Splunk, Elastic/ELK,
  Wazuh, Graylog, QRadar) that centralises security/audit events from many systems for search,
  correlation, alerting, and compliance. sos-vault can forward its audit events to yours.
- **What gets forwarded?** Every recorded event (the same events shown in the **Event Log** — logins,
  vault open/close, uploads/downloads, user/key/case changes, 2FA enable/disable, etc.). Each message
  carries an extra top-level `LOGTYPE="sos-vault"` field so it is easy to filter on the SIEM side.
- **How to configure:** **Manage Settings → SIEM Integration** → turn on **Enable SIEM forwarding**,
  set the **Host** (FQDN or IP) and **Port** of your SIEM's syslog receiver, choose the **Transport
  Protocol** (TCP default, UDP, or TLS) and **Wire Format** (**ECS (JSON)** default, or **Syslog
  (RFC 5424)**), then **Save**.
- **TLS:** sos-vault connects to the SIEM as a TLS client and verifies the server's certificate.
  Upload the **CA Certificate** that signed your SIEM server's certificate; for a self-signed SIEM,
  upload its own certificate as the **SIEM Server Certificate**. (This is independent of the
  appliance's own HTTPS certificate.)
- **Delivery:** forwarding runs on the background queue, so a slow or unreachable SIEM never affects
  the app; failures are logged and retried. All SIEM settings (including the certificates) are
  **encrypted at rest**.

## View Fleet (appliance notes)
The `/fleet` page groups all uploaded reports by system (machine-id from `etc/machine-id`,
falling back to the filename host). Pre-existing cases self-heal when opened in the browser,
or backfill in bulk with:
`docker compose exec -T <app-container> sudo -u www-data php artisan fleet:backfill-identity`
(options `--case=ID`, `--force`, `--dry-run`). Vaults must be open (unlocked) to backfill —
closed vaults are counted and skipped; re-run after members log in and their vaults open.

## Support & diagnostics
- **Capture a server report for support:**
  `docker compose exec -T <app-container> sudo -u www-data php artisan sos-vault:capture-server-report`
  — runs `sosreport`, GPG-encrypts it to the sos-vault support recipient, and writes
  `storage/app/private/server-report.tar.xz.gpg`. Attach that file to your ticket.
- **Service won't start:** `systemctl status sos-vault.service`,
  `journalctl -u sos-vault.service -n 200 --no-pager`, and the docker compose `ps`/`logs`.
  Common causes: wrong install root in `/etc/default/sos-vault`, or `/var/lib/docker` out of disk.
- **Outbound proxy:** only needed for outbound integrations (Jira/ITSM, Telegram, remote AI, AI
  model download) and docker pulls — the web UI and CLI upload are LAN-local. Set `HTTPS_PROXY`
  (not `HTTP_PROXY`) and `NO_PROXY` in `/opt/sos-vault/.env`, then `systemctl restart sos-vault`.
  SMTP is not HTTP and does not use the proxy. For TLS-intercepting proxies, upload the proxy's
  root CA via Certificate Manager.

## GPG keyring passphrase
Collected at installer step 5 and stored encrypted in the `settings` table (never in `.env`). It
unwraps the vault's at-rest key and **cannot be recovered** — if lost, restore from backup or
rebuild and re-ingest. Store it in a password manager.
