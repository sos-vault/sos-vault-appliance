# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# sos-vault appliance app image — multi-stage PHP-FPM runtime.
#
# Stage 1 (builder) compiles the PHP extensions and resolves a production
# (--no-dev) vendor/ tree. Stage 2 (runtime) installs ONLY the shared libraries
# and CLI tools needed to RUN — no compilers, no -dev headers, no mysql client.
# The compiled extensions and the lean application code are copied across, so
# the final image is a fraction of the old single-stage build (~2.3 GB → ~0.5 GB)
# with identical functionality.
#
# Built + pushed by build/publish-images.sh as ghcr.io/sos-vault/app:<version>.
# ---------------------------------------------------------------------------

# ===========================================================================
# Stage 1 — builder: compile extensions + composer install --no-dev
# ===========================================================================
FROM php:8.4-fpm AS builder

ARG user
ARG uid

# Build-only dependencies: the toolchain + the -dev headers each extension
# needs to compile. None of this is carried into the runtime image.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    autoconf \
    pkg-config \
    build-essential \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libldap2-dev \
    libsnmp-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libxslt-dev \
    libgpgme-dev \
    libgpg-error-dev \
    libassuan-dev \
    # shellcheck stays in the BUILDER only — InstallerScriptTest lints
    # installer.sh against it on the CI/build host; the runtime image never
    # invokes it (no PHP code calls shellcheck).
    shellcheck \
  && rm -rf /var/lib/apt/lists/*

# Compile the extensions the app actually uses. pdo_mysql is intentionally
# dropped (the appliance DB is sqlite; pdo_sqlite + sqlite3 are built into the
# php base image).
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" exif gd zip ldap snmp intl \
 && pecl install gnupg \
 && docker-php-ext-enable gnupg

# Determine the runtime shared-library packages the compiled extensions link
# against — resolved HERE (where the -dev libs are installed) and written to a
# manifest the runtime stage installs verbatim. This is base-distro agnostic:
# it adapts automatically to package renames (e.g. trixie's libzip5/libicu76)
# instead of hardcoding names that drift between Debian releases.
RUN set -eux; \
    find /usr/local/lib/php/extensions -name '*.so' -exec ldd {} ';' \
      | awk '/=> \// { print $3 }' \
      | sort -u \
      | while read -r lib; do \
          real="$(readlink -f "$lib")"; \
          dpkg-query -S "$real" 2>/dev/null || true; \
        done \
      | cut -d: -f1 \
      | sort -u \
      > /tmp/php-ext-runtime-deps.txt; \
    test -s /tmp/php-ext-runtime-deps.txt; \
    cat /tmp/php-ext-runtime-deps.txt

# Pinned (not :latest) for reproducible builds. :latest silently rolling forward
# is what removed Composer's automatic dist→source fallback and turned a transient
# GitHub codeload 400 into a hard build failure. Bump deliberately, not by surprise.
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/site

# Copy the (lean, via .dockerignore) source tree and build a production-only
# vendor/ with an optimized autoloader.
COPY --chown=${uid}:${uid} . /var/www/site
# composer install, hardened against GitHub's flaky codeload archive endpoint.
# api.github.com/...zipball redirects to codeload.github.com/...legacy.zip, which
# intermittently returns HTTP 400 — and current Composer no longer auto-falls-back
# to a git source clone on a dist failure ("Source fallback is disabled"). So:
# retry the dist install with backoff to ride out a transient blip, then as a last
# resort do a --prefer-source install (git is in this builder stage), which clones
# the repo and sidesteps the broken archive entirely.
#
# Then drop bootstrap/cache/{packages,services}.php: the repo commits the versions
# generated in the DEV tree, so they list dev-only auto-discovered providers
# (laravel/dusk, duskapiconf, boost, pail, ignition…). With --no-dev those classes
# are absent and the app fatals at boot ("Class … not found"); removing the stale
# manifests makes Laravel rebuild them from the no-dev vendor at runtime.
RUN set -eux; \
    dist() { composer install --no-ansi --no-scripts --no-dev --prefer-dist \
               --no-progress --no-interaction --optimize-autoloader; }; \
    src()  { composer install --no-ansi --no-scripts --no-dev --prefer-source \
               --no-progress --no-interaction --optimize-autoloader; }; \
    dist || { sleep 15; dist; } || { sleep 30; dist; } || src; \
    rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# ===========================================================================
# Stage 2 — runtime: only what is needed to RUN the app
# ===========================================================================
FROM php:8.4-fpm

ARG user
ARG uid
ENV TZ=Australia/Adelaide

# The compiled extensions + their enable-ini files (gd, zip, ldap, snmp, intl,
# exif, gnupg) from the builder. Both stages share the same php base, so the
# builder's extensions/ and conf.d/ are a superset of the runtime base
# (pdo_sqlite, sqlite3, opcache, sodium remain available).
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=builder /tmp/php-ext-runtime-deps.txt /tmp/php-ext-runtime-deps.txt

# Runtime shared libs (auto-resolved above) + the CLI tools the app shells out
# to (gpg, cryptsetup, tar/xz/bzip2/gzip, mkfs/fsck via e2fsprogs, keyutils).
# Dropped vs the old image: build-essential, gcc-multilib, all -dev headers,
# git, vim-tiny, shellcheck, default-mysql-client.
RUN apt-get update && apt-get install -y --no-install-recommends \
      $(cat /tmp/php-ext-runtime-deps.txt) \
      ca-certificates \
      sudo \
      bash \
      gnupg \
      cryptsetup \
      tar \
      xz-utils \
      bzip2 \
      gzip \
      e2fsprogs \
      keyutils \
      openssl \
  && cp /usr/share/zoneinfo/${TZ} /etc/localtime \
  && rm -f /tmp/php-ext-runtime-deps.txt \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

# Create the system user and remap www-data to the build uid. container_start.sh
# remaps again at boot from WWWUSER/WWWGROUP, but baking the default keeps the
# image self-consistent.
RUN useradd -G www-data,root -u $uid -d /home/$user -s /bin/bash $user \
 && mkdir -m 750 -p /home/$user/.composer && chown -R $user:$user /home/$user \
 && sed -i.bak -e"/^www-data/s/:[0-9][0-9]*:[0-9][0-9]*:/:${uid}:${uid}:/" /etc/passwd

# The app runs with HOME=/var/www; give it a writable XDG config root so the
# on-box `php artisan tinker` REPL (psysh writes history under $HOME/.config)
# does not abort during field troubleshooting. Prefer `php artisan ai:doctor`
# for routine checks — it needs no REPL.
RUN mkdir -p /var/www/.config && chown $user:$user /var/www/.config

WORKDIR /var/www/site

# Bake the lean application code (source + production vendor/ + built public/
# assets) from the builder.
COPY --from=builder --chown=$user:$user /var/www/site /var/www/site

# Wire sudoers + bashrc exactly as the single-stage image did, and make the
# runtime-writable dirs writable (regenerated each boot / overlaid by host
# mounts on the appliance).
RUN cat /var/www/site/docker-compose/etc/sudoers > /etc/sudoers \
 && rm -f /root/.bashrc && ln -s /var/www/site/.bashrc /root/.bashrc \
 && ln -s /var/www/site/.bashrc /var/www/.bashrc && chown www-data: /var/www/.bashrc \
 && ln -s /var/www/site/.bashrc /var/www/.profile && chown www-data: /var/www/.profile \
 && chown -R $user:$user /var/www/site/bootstrap/cache /var/www/site/storage \
 && chmod -R ug+rwX /var/www/site/bootstrap/cache /var/www/site/storage

ENTRYPOINT ["/var/www/site/sysadmin/container_start.sh"]
