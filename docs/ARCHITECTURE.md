# Architecture overview

A bird's-eye view of the sos-vault appliance for operators evaluating, integrating, or auditing it.

## Container layout

Everything ships as Docker Compose services in `/opt/sos-vault/docker-compose.yml`:

- **nginx** — TLS termination, reverse proxy to PHP-FPM. Mounts the cert directory from the host. Reloaded by `sysadmin/cert-helper` on a new cert install.
- **sos-vault (app)** — PHP 8.4 / Laravel 12 / Filament 4 / Livewire 3. Runs the web tier, queue workers, and scheduler. Holds the bundled SQLite database on its own storage volume.
- **redis** — cache + queue + session store.
- **llama** — local LLM server (llama.cpp) that serves the bundled model for the AI Assistant. The model itself is downloaded from the admin UI after install, not during it.

## Storage

- **`/vault`** — the canonical directory for the data vault, a plain directory on every install (default `/vault`, configurable from Disk Manager). On a licensed install, group vaults are LUKS-encrypted device files stored inside it. The directory can live on local disk or a network share (NFS / CIFS) mounted by the OS.
- **`storage/app/private/`** inside the container — encrypted intermediates: license-request tarballs, server-report tarballs, GPG keyring temp files.
- **Database** — a bundled SQLite file (`storage/app/db/database.sqlite`); schema lives under `database/migrations/`. The `users`, `groups`, `vaults`, `sysevents`, and `local_licenses` tables are the load-bearing ones for the licensing flow.

## Vault provisioning (licensed tier)

Group vaults are LUKS-encrypted device files:

1. Admin creates a Group from the Filament panel with a chosen size (MB).
2. `VaultTools::createGroupVault()` runs the full provisioning flow synchronously: `dd` → `cryptsetup luksFormat` → `luksAddKey` → `luksHeaderBackup` → `luksOpen` → `mkfs.ext4` → `mount`.
3. The Vault row is inserted with `owner=NULL` (appliance group vaults have no manager user); `groups.vault_id` is linked.
4. All Team Member users assigned to the group share the same LUKS volume via the `initializeVault` listener at login.

## Open-core baseline

When no license is installed, the LUKS path is never invoked. Instead `sos-vault:ensure-plain-vault` creates a plain directory at `appliance.vault_dir` (default `/vault`). Single-admin baseline; no group vaults; no multi-user.

## Helper-script boundary

PHP never calls `cryptsetup`, `mount`, `openssl`, `update-ca-certificates`, or `dmidecode` directly. Each privileged operation is routed through a small bash wrapper under `sysadmin/`, paired with a narrowly-scoped sudoers fragment in `sysadmin/sudoers.d/`. The fragments grant `www-data` NOPASSWD on EXACTLY the verbs the helper invokes — no wildcards.

This split keeps the attack surface auditable: an attacker who compromises the PHP application cannot run arbitrary commands as root; they can only invoke the exact verbs the helpers expose.
