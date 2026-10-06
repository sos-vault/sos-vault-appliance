# Configuration reference

**Most configuration no longer lives in the clear-text `.env` file.** Earlier versions kept integration credentials, tunables, and feature flags in `.env`; those have been moved into the `settings` table in the database. Sensitive values (API keys, integration passwords, licensing passphrases) are **encrypted at rest** and are never rendered back to the browser once saved. This is a deliberate privacy and security improvement: a clear-text `.env` on disk is an easy target, whereas the settings table keeps secrets encrypted and edits them only through the authenticated admin UI.

sos-vault therefore uses two configuration mechanisms:

- **`settings` table** — admin-editable runtime values, updated through the **Manage Settings** admin page, never in code. Sensitive rows are encrypted at rest; the resolved values are cached, and the cache is invalidated on save. This is where the vast majority of configuration now lives.
- **`.env` file** — only the environment-bound values that must exist *before* the Laravel container boots: the application key, session driver, and the one-time installer admin bootstrap variables.

The rule: anything an operator might reasonably want to change at runtime lives in the settings table (encrypted if sensitive). Anything that must exist before the app boots lives in `.env`.

## `.env` keys

The bundled `.env.example` is the source of truth. Highlights:

| Key | Required | Purpose |
| --- | --- | --- |
| `APP_KEY` | yes | Laravel encryption key. Generate with `php artisan key:generate`. |
| `APP_URL` | yes | The host URL operators visit. Used for link generation in emails. |
| `DB_CONNECTION` / `DB_DATABASE` | preset | The appliance ships with a bundled SQLite database (`storage/app/db/database.sqlite`, set by the installer). You do not normally change these. |
| `SESSION_DRIVER` | yes | `database` for the appliance; `redis` if you have Redis. |
| `INSTALLER_ADMIN_EMAIL` | install-time | Only read by `ApplianceAdminSeeder` on first boot. Wiped from history by the installer's `docker compose exec -e` invocation. |
| `INSTALLER_ADMIN_PASSWORD` | install-time | Same. |
| `INSTALLER_ADMIN_NAME` | optional | Default `Administrator`. |
| `TEST_FIXTURE_PASSPHRASE` | dev only | Used by the test suite to decrypt sosreport fixtures. Set in your local gitignored `.env`. |

## Outbound proxy (private networks)

The appliance and its day-to-day use need **no** Internet access: the web UI and the command-line sosreport upload are LAN-local (inbound to the appliance), and sosreport extraction, vaults, search, licensing (offline signature verification) and 2FA (offline TOTP) all work air-gapped.

A proxy only matters for the appliance's **outbound** calls, which happen only when you enable an integration that reaches the Internet:

| Integration | Goes through `HTTPS_PROXY`? |
| --- | --- |
| Jira / ITSM, Telegram, remote AI (OpenAI) | Yes — HTTPS via the HTTP client |
| SMTP mail | **No** — SMTP is a separate TCP connection, not HTTP. Use an internal/LAN relay or a smarthost; an HTTP proxy cannot tunnel it |
| Docker image pulls / upgrades (`ghcr.io`) | Configured on the **host docker daemon**, not here (systemd drop-in `…/docker.service.d/http-proxy.conf`) |

To route the app's outbound HTTPS through a proxy, edit `/opt/sos-vault/.env` (the installer leaves a commented block at the bottom):

```dotenv
HTTPS_PROXY=http://proxy.example.com:3128
NO_PROXY=localhost,127.0.0.1,172.16.0.0/12,<appliance-host>,<internal-jira-host>,<mail-host>
```

Notes:

- Use **`HTTPS_PROXY`**, not `HTTP_PROXY`. In the web (php-fpm) context the HTTP client ignores `HTTP_PROXY` by design (httpoxy mitigation); since the integrations are all HTTPS, `HTTPS_PROXY` is what takes effect.
- Keep LAN/internal hosts in **`NO_PROXY`** so the appliance, the docker network and any internal Jira/mail endpoints are not bounced through the proxy.
- **Restart after changing it:** `systemctl restart sos-vault` (the running PHP/queue/scheduler processes only re-read `.env` on restart).
- If the proxy does **TLS interception**, upload its root CA via the in-app **Certificate Manager** — otherwise outbound HTTPS fails certificate validation. (For docker image pulls the CA must also be in the host trust store.)

## Settings table keys

Editable from `/admin/manage-settings`. Grouped by section.

### Always visible (open-core + licensed)

- `mail.mailer`, `mail.encryption`, `mail.host`, `mail.port`, `mail.username`, `mail.password`, `mail.from_address`, `mail.from_name` — SMTP configuration.

### Visible only with an active license

- **Authentication** — `auth.dashboard_redirect`, `auth.email_or_username`, `auth.username_in_registration`, `auth.verify_email`, `auth.default_role`, plus password complexity tunables.
- **AI Assistant** — `ai.provider`, `ai.local_url`, `ai.local_model`, `ai.ollama_url`, `ai.ollama_model`, `ai.ollama_api_key`, `ai.ollama_tools`, `ai.openai_model`, `ai.openai_api_key`, `ai.anthropic_model`, `ai.anthropic_api_key`, `ai.max_tokens`, `ai.temperature`, `ai.rate_limit`, `ai.inject_case_context`. The `*_api_key` values are encrypted at rest. See [`AI_ASSISTANT.md`](AI_ASSISTANT.md) for the local-vs-cloud model split and how to obtain an OpenAI / Anthropic key.
- **Appliance Vaults** — `appliance.default_vault_size_mb` (defaults to 10240 MB).
- **ServiceNow / ITSM** — `servicenow.instance`, `servicenow.username`, `servicenow.password`.

### Set indirectly by Disk Manager

- `appliance.vault_dir` — path to the open-core plain vault directory. Persisted when the operator saves the Disk Manager form on an unlicensed install. Default `/vault`.

## Privileged helper scripts

Under `/opt/sos-vault/sysadmin/`. Each is invoked via `sudo` with a narrowly-scoped sudoers fragment dropped into `/etc/sudoers.d/` by the installer.

| Script | Purpose |
| --- | --- |
| `installer.sh` | First-boot interactive installer. 15 steps, idempotent and safe to re-run. Supports `--dry-run`. |
| `cert-helper` | Validates and installs TLS certificate material. |
| `machine-token-helper` | Reads baseboard / system serial via `sudo dmidecode -s` and filters DMI placeholder strings. Used by `MachineTokenService::currentHostTokens()`. |
| `init.sh` | One-shot GPG keyring initialisation. |
| `sudoers.d/sos-vault-cert` | Grants `www-data` NOPASSWD on the exact verbs used by `cert-helper`. |
| `sudoers.d/sos-vault-machine-token` | Same, for `machine-token-helper` (only the two `dmidecode -s` invocations). |

The fragments use `Cmnd_Alias` with exact arguments — no `*` wildcards on flags, no blanket access. Audit them after install with `sudo visudo -cf /etc/sudoers.d/sos-vault-*`.

## Logging

Application logs land in the Laravel log channel as configured by `LOG_CHANNEL` in `.env` (default `stack` writing to `storage/logs/laravel.log`). Inside the container that maps to `/var/www/html/storage/logs/`. Tail with:

```bash
docker compose exec -T sos-vault tail -f storage/logs/laravel.log
```

Or via `php artisan pail` for a structured live view.

## Where to look when something is mis-configured

1. `php artisan about` — dumps the resolved configuration the framework sees.
2. `php artisan config:show <key>` — print one specific resolved value.
3. The Settings admin page — `/admin/manage-settings` — shows what the operator-editable values are right now. Cached; if you change a row in the DB by hand, `php artisan cache:clear` is needed.

For licensing-related issues, the `License` admin page surfaces the resolved status (active / expired / revoked, seats used / total, expires-soon flag).
