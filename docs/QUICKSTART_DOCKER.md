# Quickstart with Docker Compose

If you want to evaluate sos-vault without committing to a full appliance install, this is the fastest path. You will end up with a working open-core baseline (single admin, plain vault directory) running on your laptop or any host with Docker.

This path does **not** install the systemd unit, configure UFW, or set up the privileged sudoers fragments needed for certificate management. For long-running production use, follow [`INSTALL.md`](INSTALL.md) instead.

## Prerequisites

- Docker 24+ with the Compose plugin (`docker compose version` should work).
- ~6 GB free disk for images and the SQLite data file.
- A free TCP port to expose the web UI (the compose file defaults to 8080).

## 1. Clone the repository

```bash
git clone https://github.com/sos-vault/sos-vault-appliance.git
cd sos-vault-appliance
```

## 2. Copy the example environment file

```bash
cp .env.example .env
```

Edit `.env` if you want to change the admin email, app key, or the host port. The defaults work for evaluation.

## 3. Bring the stack up

```bash
docker compose up -d
```

First boot pulls the bundled images, runs migrations, and seeds the admin account from `INSTALLER_ADMIN_EMAIL` / `INSTALLER_ADMIN_PASSWORD` (set in `.env`).

Watch the logs until you see `Server started`:

```bash
docker compose logs -f sos-vault
```

## 4. Sign in

Open `http://localhost:8080/` and sign in with the credentials from `.env`.

The dashboard's License widget invites you to install a license. On Docker Compose evaluation, you almost certainly want to skip that and use the open-core baseline.

## 5. Provision the plain vault directory

Inside the container:

```bash
docker compose exec -T sos-vault sudo -u www-data \
  php artisan sos-vault:ensure-plain-vault
```

By default this creates `/vault` inside the container. The compose file mounts a host-side bind mount at `./data/vault` — anything written to `/vault` lands there on the host.

## 6. Use it

Upload a sosreport from the Customer Portal-style upload form, watch the case enrich, browse the structured view. Same code path as the appliance build; only the licensing-gated features (multi-user, groups, modules, ITSM, event log, encrypted vaults) are hidden.

## Stopping and tearing down

```bash
docker compose down              # stop containers, keep volumes
docker compose down -v           # also delete the data volumes (wipes the DB)
```

## What is NOT covered by this quickstart

- TLS — Docker Compose serves plaintext on port 8080. Front it with a reverse proxy or `caddy` for a real demo.
- Vault storage — the Compose path uses a plain host bind mount for `/vault`, the same plain-directory model a full appliance install uses.
- systemd — the container is owned by Docker; if your host reboots, `docker compose up -d` does not run automatically. Add a systemd unit or use `--restart unless-stopped` in the compose file.
- UFW / sudoers — not configured on the host. Anything running on your machine can hit port 8080.

When you are ready to move from evaluation to production, follow [`INSTALL.md`](INSTALL.md).
