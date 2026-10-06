# Upgrade notes

Release-by-release notes for operators upgrading an existing appliance install.

## Upgrade procedure (every release)

1. Snapshot the host (or at minimum, dump the DB).
2. Stop the appliance: `systemctl stop sos-vault.service`.
3. Install the new package: `sudo apt install ./sos-vault_<new-version>_amd64.deb` (or the matching `.rpm`).
4. Start the appliance so the new image comes up: `systemctl start sos-vault.service`. Give the `app` container a few seconds to become ready.
5. Run pending migrations inside the running app container: `docker compose -f /opt/sos-vault/docker-compose.yml exec -T app sudo -u www-data php artisan migrate --force`.
6. Confirm `https://<your-host>/admin` loads and the License widget still reports your existing license as ACTIVE.

> The migration step must run **after** the stack is started — `docker compose exec` needs a running container, and stopping the service tears the containers down. Note the compose *service* is `app` (the container is named `sos-vault`), so `exec` targets `app`.

If something looks wrong, the snapshot from step 1 is your rollback.

## Breaking changes

None in the initial release.

When a future release introduces a breaking change, it will be documented here with: what changed, why, what operators need to do, and how to roll back. We tag breaking releases as minor or major version bumps per [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Where to ask

For upgrade questions:

- GitHub Discussions for general "how do I upgrade from X to Y" conversations.
- `support@sos-vault.com` for licensed customers with production upgrade plans.
- An issue on this repository if you hit a migration bug.
