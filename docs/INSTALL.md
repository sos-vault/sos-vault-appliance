# Installation guide

Two supported paths:

1. **Packaged install** (recommended) — `apt install` or `dnf install` the `.deb` / `.rpm` from sos-vault.com, then run the bundled installer once. Best for a real appliance you intend to operate long-term.
2. **Docker Compose** — see [`QUICKSTART_DOCKER.md`](QUICKSTART_DOCKER.md). Best for evaluation; the installer's automated systemd / UFW / sudoers steps are skipped.

This guide covers the packaged path.

## Prerequisites

- Ubuntu 22.04 / 24.04 LTS, RHEL / Rocky / AlmaLinux 8 or 9.
- 16+ CPU cores (recommended).
- 32+ GB RAM (recommended).
- 4+ TB free storage where the vault directory will live.
- Outbound HTTPS to sos-vault.com if you intend to purchase a Pro license (optional — the open-core baseline does not need internet).
- Root access on the target host.

The installer warns rather than fails if hardware is below the recommended floor, so you can install on smaller boxes for evaluation.

## Step 1: install the package

Download the latest `.deb` or `.rpm` from `https://sos-vault.com/#self-hosted`. Verify the SHA-256 checksum against the published `SHA256SUMS` file, then:

```bash
# Ubuntu / Debian
sudo apt install ./sos-vault_<version>_amd64.deb

# RHEL / Rocky / Alma / Fedora
sudo dnf install ./sos-vault-<version>.x86_64.rpm
```

You can also fetch the packages directly. Pass `--content-disposition` so `wget` saves the real filename — without it, wget names the file after the URL's last segment (`deb` / `rpm`) instead:

```bash
wget --content-disposition https://sos-vault.com/download/package/deb
wget --content-disposition https://sos-vault.com/download/package/rpm

# Or enable it once so a plain `wget` always honors the server filename:
echo 'content_disposition = on' >> ~/.wgetrc
```

This drops the application into `/opt/sos-vault`, registers the systemd unit, and installs the privileged helper scripts under `/opt/sos-vault/sysadmin/`. It does NOT start the appliance or create users yet.

## Step 2: run the installer

```bash
sudo /opt/sos-vault/sysadmin/installer.sh
```

The installer walks 15 numbered steps (a few have lettered sub-steps), with operator-confirmation prompts for the destructive ones:

1. Verify the OS is supported.
2. Verify hardware (RAM / CPU / disk). *(2b)* Provision the app service user.
3. Install Docker + Compose if missing.
4. Prompt for admin credentials (name, email, password).
5. Prompt for the GPG keyring passphrase. *(5b)* Choose the passphrase-storage policy for reboot survival (TPM / external vault / relaxed).
6. Initialize the GPG keyring used to verify uploaded licenses (and escrow the passphrase).
7. Pull the bundled docker images (ghcr.io / docker.io). *(7b)* Write the application `.env` (only if missing).
8. Start the containers (`docker compose up -d`).
9. Ensure a self-signed TLS certificate.
10. Prepare the AI model directory. **The local model is not downloaded here** — the installer only creates the directory; the model is fetched later from the admin UI (see [`AI_ASSISTANT.md`](AI_ASSISTANT.md)).
11. Install the systemd units (`sos-vault` + `svaultKey`).
12. Configure the host firewall (allow 80, 443).
13. Run `php artisan migrate` and `db:seed --class=Database\Seeders\ApplianceAdminSeeder`. *(13b)* Capture the host hardware fingerprint.
14. Provision the plain vault directory (default `/vault`).
15. Print the success message with the sign-in URL.

A `--dry-run` flag walks every step and prints what it would do without mutating anything. Use it the first time you read the script.

**The installer is idempotent and resumable — it is safe to re-run.** It saves the answers you give as it goes, and each step detects work that is already done and skips it (Docker + Compose, the application user, the GPG/vault keys and their escrow, the TLS certificate, the vault directory, and so on). If a run is interrupted or a step fails, just run `installer.sh` again: it resumes from where it stopped, does **not** re-ask the prompts you already answered, and never repeats a destructive operation on something that already exists. (A clean machine wipe is the only thing that resets the saved answers.)

## Step 3: sign in

**Recommended: reboot the host before your first sign-in.** The first admin login provisions the admin's encrypted personal vault, which needs the `loop` / `device-mapper` kernel modules fully live — a fresh reboot guarantees that on a freshly installed host. If a first login is ever interrupted mid-provision (e.g. the box is rebooted while the vault is being created), the next login self-heals the half-provisioned vault automatically, so no manual cleanup is needed.

Open `https://<this-host>/` in a browser. Accept the self-signed certificate warning (replace the cert via Manage Certificates once you have a real one). Sign in with the admin email and password you supplied in step 4.

**Set up two-factor authentication.** On the first admin sign-in you are prompted to enroll a TOTP authenticator (Google Authenticator, Aegis, 1Password, etc.). Two-factor is mandatory for admin accounts. Scan the QR code, enter the six-digit code to confirm, and store the recovery codes somewhere safe. This is offline — no external service is contacted.

You will land on the dashboard. The "License" widget invites you to install a license — that is the link to the next document, [`LICENSE_REQUEST.md`](LICENSE_REQUEST.md). If you are evaluating sos-vault, you can stop here and use it as a single-admin baseline indefinitely.

**AI Assistant (optional).** The installer does not download the local model — it only prepares the directory. Download the bundled local model from the admin UI now (or, for full sosreport analysis, configure an OpenAI or Anthropic API key instead). See [`AI_ASSISTANT.md`](AI_ASSISTANT.md).

## Step 4: replace the self-signed certificate (optional)

This step is optional — the appliance is fully functional over HTTPS with the self-signed certificate generated in step 9; replace it only when you want a browser-trusted certificate (no warning) or your own CA-issued one.

Once a license is installed, the Certificate Manager admin page surfaces a `Replace Server Certificate` upload. Drop in `fullchain.pem` and `privkey.pem`; the helper validates with `openssl x509 -noout` / `openssl pkey -noout` before clobbering the live files and reloads nginx.

On the open-core baseline this UI is hidden; replace the cert manually by writing to `/opt/sos-vault/docker-compose/nginx/ssl/sos-vault.com/{fullchain.pem,privkey.pem}` and restarting the nginx container.

## Troubleshooting

- **AI Assistant has no local model after install** — expected: the installer never downloads the model (step 10 only prepares its directory). Download the ~1.1 GB local model from the admin UI after first sign-in; the host needs outbound internet for that (or supply the model file out-of-band on an air-gapped box). See [`AI_ASSISTANT.md`](AI_ASSISTANT.md).
- **`docker compose up -d` (step 8) fails with port conflicts** — another service is using port 80 / 443. Stop it or change `ports:` in `/opt/sos-vault/docker-compose.yml`.
- **Cannot sign in after step 13** — the admin password is hashed at insert time; if you mistyped it during the prompt, re-run `php artisan db:seed --class=Database\Seeders\ApplianceAdminSeeder` with corrected `INSTALLER_ADMIN_PASSWORD` env var.
- **First sign-in was interrupted while the vault was being created** — the next login detects the half-provisioned vault and rebuilds it automatically; just sign in again (a reboot first is fine). No manual `vault:Admin` cleanup is required.

For anything else, capture a server report (`docker compose exec -T sos-vault sudo -u www-data php artisan sos-vault:capture-server-report`) and email it to `support@sos-vault.com`.
