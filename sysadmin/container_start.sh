#!/bin/sh
#https://serversideup.net/open-source/docker-php/docs/laravel/laravel-queue

set -e

# Remap the in-container www-data uid/gid to the host app user (WWWUSER/WWWGROUP
# from the compose env, sourced from /opt/sos-vault/.env). The published image
# bakes www-data at 1000; the appliance installer may provision a different uid
# (e.g. a system uid to avoid a human at 1000). The app process must share that
# uid so it reads svault0..3 from the host's @u keyring. We run as root here, so
# we can edit /etc/passwd + /etc/group before php-fpm forks its workers — no
# image rebuild needed. Mirrors the sed the Dockerfile uses at build time.
if [ -n "${WWWUSER:-}" ] && [ "$(id -u www-data)" != "$WWWUSER" ]; then
    echo "remapping www-data -> uid ${WWWUSER} gid ${WWWGROUP:-$WWWUSER}"
    sed -i -e "/^www-data:/s/:[0-9][0-9]*:[0-9][0-9]*:/:${WWWUSER}:${WWWGROUP:-$WWWUSER}:/" /etc/passwd
    sed -i -e "/^www-data:/s/:[0-9][0-9]*:/:${WWWGROUP:-$WWWUSER}:/" /etc/group
fi

# The application code is now BAKED into the image (owned by the image build
# uid, 1000). bootstrap/cache is the one baked directory the app writes to
# (compiled config/routes/package manifests), so hand it to the possibly-remapped
# app uid — otherwise an installer that provisioned a non-1000 system uid gets
# EACCES when Laravel rewrites those caches.
chown -R www-data:www-data /var/www/site/bootstrap/cache 2>/dev/null || true

# The license/module VERIFICATION keyring (.gnupg, public keys only) is also
# baked into the image owned by the build uid (1000), mode 700. gpg refuses to
# operate on a homedir it can't own — it needs to create lockfiles and the
# trustdb inside it — so an installer that provisioned a non-1000 system uid
# would get "Permission denied" on pubring.kbx and license verification would
# fail with "not signed by the build keyring". Hand the homedir to the remapped
# app uid so `gpg --verify` (LicenseGeneratorService) can read it. In the old
# bind-mount layout the installer's repo-wide chown covered this; baking it
# hardcodes 1000, so we must re-own it here.
chown -R www-data:www-data /var/www/site/.gnupg 2>/dev/null || true

# Seed the storage skeleton. storage/ is bind-mounted from the host
# (/opt/sos-vault/storage) and is EMPTY on a fresh install — Laravel needs these
# dirs (file sessions, cache, compiled views, logs) and the sqlite app DB dir
# (DB_DATABASE = storage/app/db/database.sqlite) to exist. Idempotent: -p is a
# no-op when they already exist on an established install.
for d in \
    /var/www/site/storage/framework/cache/data \
    /var/www/site/storage/framework/sessions \
    /var/www/site/storage/framework/views \
    /var/www/site/storage/logs \
    /var/www/site/storage/app/public \
    /var/www/site/storage/app/db; do
    mkdir -p "$d"
done
# Laravel's SQLite connector won't create the DB file; seed an empty one so a
# fresh boot before the installer's migrate (or a manual `up`) has a valid path.
# touch never truncates an existing DB.
touch /var/www/site/storage/app/db/database.sqlite

# Seed the default public assets (login logo + favicons, default avatar, and the
# Documentation / page / post images the appliance renders) that the deb ships
# read-only at storage-seed/. Copy them into the host-mounted storage/app/public
# ONLY where missing: `cp -rn` never overwrites an operator's uploaded file, so a
# fresh install gets every default while an established install keeps its own
# content and only gains newly-added defaults on an image/deb upgrade.
if [ -d /var/www/site/storage-seed/app/public ]; then
    cp -rn /var/www/site/storage-seed/app/public/. \
        /var/www/site/storage/app/public/ 2>/dev/null || true
fi

chown -R www-data:www-data /var/www/site/storage 2>/dev/null || true

# Fold any operator-installed corporate root CAs into the container trust bundle.
# The CertificateManager page drops uploaded CAs (as the app user) into the
# bind-mounted /usr/local/share/ca-certificates; running update-ca-certificates
# here — as root, on every boot — is what makes an uploaded CA take effect after
# `systemctl restart sos-vault`, so the appliance trusts internal-CA endpoints
# for outbound HTTPS. Best-effort: never block startup if it fails.
if command -v update-ca-certificates >/dev/null 2>&1; then
    mkdir -p /usr/local/share/ca-certificates
    update-ca-certificates || echo "update-ca-certificates failed — continuing"
fi

# Ensure public/storage is a RELATIVE symlink so nginx serves blog/post images
# (storage/app/public/posts/*) and avatars. The deb ships this link, but recreate
# it defensively each boot: `artisan storage:link` writes an ABSOLUTE target
# (/var/www/site/...) that is wrong on the host side of the bind mount, and a
# missing/stale link leaves every /storage/* asset 404. ln -sfn replaces it
# atomically; -n keeps it from descending into an existing link target.
if [ "$(readlink /var/www/site/public/storage 2>/dev/null)" != "../storage/app/public" ]; then
    ln -sfn ../storage/app/public /var/www/site/public/storage
    echo "fixed public/storage symlink -> ../storage/app/public"
fi

# wait for the database container
/bin/sleep 4

# wait for redis to accept connections (queue:work dies fast if it can't connect)
redis_host=${REDIS_HOST:-redis}
redis_port=${REDIS_PORT:-6379}
i=0
until /usr/bin/bash -c "exec 3<>/dev/tcp/${redis_host}/${redis_port}" 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then
        echo "redis at ${redis_host}:${redis_port} unreachable after 30s — aborting"
        exit 1
    fi
    /bin/sleep 1
done
echo "redis reachable at ${redis_host}:${redis_port}"

echo "role: $CONTAINER_ROLE"

role=${CONTAINER_ROLE:-app}
work_on_queues=${WORK_ON_QUEUES:-default}

if [ "$role" = "queue" ]; then
    echo "Starting Laravel Async Queue..."
    /bin/sudo -u www-data -g www-data /var/www/site/artisan queue:work --queue="$work_on_queues" --sleep=3 --tries=3 -v

elif [ "$role" = "scheduler" ]; then
    echo "Starting Laravel Task Scheduler..."
    /bin/sudo -u www-data -g www-data /var/www/site/artisan schedule:work

elif [ "$role" = "app" ]; then
    echo ""
    /bin/uname -a
    /usr/local/sbin/php-fpm -v
    /bin/sudo -u www-data -g www-data /var/www/site/artisan --version

    echo "Starting Laravel Async Queue..."
    /bin/sudo -u www-data -g www-data /var/www/site/artisan queue:work --queue="$work_on_queues" --tries=3 -vvv &
    /bin/sleep 3

    echo "Starting Laravel Task Scheduler..."
    /bin/sudo -u www-data -g www-data /var/www/site/artisan schedule:work &

    echo "Starting php Engine..."
    echo ""
    /usr/local/sbin/php-fpm

else
    echo "Could not match the container role \"$role\""
    exit 1
fi
