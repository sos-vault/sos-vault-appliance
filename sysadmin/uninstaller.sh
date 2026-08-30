#!/bin/bash
# sos-vault appliance uninstaller — reverses sysadmin/installer.sh.
#
# Brings the host back to (approximately) its pre-installer state so the
# installer can be re-run from a clean slate. It undoes every mutation the
# 16-step installer makes — in reverse order — and is idempotent: anything
# already gone is skipped, so a half-finished install is safe to clean up.
#
# It does NOT uninstall Docker itself, and by DEFAULT it does NOT delete the
# deb payload under /opt/sos-vault (so `installer.sh` can be re-run straight
# away). Pulled docker images are kept too (faster re-install). Use the flags
# below to widen the blast radius.
#
# By DEFAULT it also removes the sos-vault package itself (apt purge /
# dnf remove). Pass --keep-package to leave the deb installed so installer.sh
# can be re-run without reinstalling the package.
#
# Usage:
#     sudo ./uninstaller.sh                 # full uninstall incl. the package (confirm)
#     sudo ./uninstaller.sh --yes           # …without the confirmation prompt
#     sudo ./uninstaller.sh --dry-run       # print every action, mutate nothing
#     sudo ./uninstaller.sh --keep-package  # revert state but KEEP the deb installed
#     sudo ./uninstaller.sh --keep-data     # preserve /vault + the app database
#     sudo ./uninstaller.sh --remove-images # also remove the pulled docker images
#     ./uninstaller.sh --help               # usage
#
# Environment overrides (must match the install; defaults are correct):
#     SOS_VAULT_DIR        install root. Default /opt/sos-vault.
#     SOS_VAULT_VAULT_DIR  vault directory. Default /vault.
#     SVAULT_KEYDIR        svault LUKS key device dir. Default
#                          /var/lib/sos-vault/svaultkey.
#     SOS_VAULT_APP_USER   app service user. Default sosvault.

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults (mirror installer.sh)
# ---------------------------------------------------------------------------
SOS_VAULT_DIR="${SOS_VAULT_DIR:-/opt/sos-vault}"
SOS_VAULT_VAULT_DIR="${SOS_VAULT_VAULT_DIR:-/vault}"
SVAULT_KEYDIR="${SVAULT_KEYDIR:-/var/lib/sos-vault/svaultkey}"
SOS_VAULT_APP_USER="${SOS_VAULT_APP_USER:-sosvault}"
INSTALL_CACHE="${INSTALL_CACHE:-/var/lib/sos-vault/.install-answers}"
SOS_VAULT_STATE_DIR="${SOS_VAULT_STATE_DIR:-/var/lib/sos-vault}"

# Containers the appliance compose declares (container_name:), removed by name
# as a fallback when `docker compose down` can't (e.g. compose file deleted).
APPLIANCE_CONTAINERS=(sos-vault sos-vault_nginx sos-vault_redis sos-vault_llama)
# Images pulled in installer Step 7 (only removed with --remove-images).
APPLIANCE_IMAGES=(
    "ghcr.io/sos-vault/app"
    "nginx:alpine3.23-slim"
    "redis:alpine"
    "ghcr.io/ggml-org/llama.cpp:server"
)

DRY_RUN=0
ASSUME_YES=0
KEEP_DATA=0
REMOVE_IMAGES=0
# Remove the deb/rpm package itself by default — this is an *uninstaller*.
# --keep-package flips it off for the "wipe state, re-run installer.sh" workflow.
REMOVE_PACKAGE=1

# ---------------------------------------------------------------------------
# Helpers (mirror installer.sh)
# ---------------------------------------------------------------------------
log()  { printf '\033[1;34m[uninstall]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[uninstall]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[uninstall]\033[0m %s\n' "$*" >&2; exit 1; }

run() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] %s\n' "$*"
    else
        "$@"
    fi
}

require_root() {
    [[ "$DRY_RUN" -eq 1 ]] && return
    if [[ "$(id -u)" -ne 0 ]]; then
        die 'uninstaller.sh must run as root (or under sudo).'
    fi
}

usage() {
    cat <<'EOF'
sos-vault appliance uninstaller

  Reverses sysadmin/installer.sh AND removes the sos-vault package, returning
  the host to its pre-install state. Idempotent — safe on a partial install.

  Usage:
    sudo ./uninstaller.sh                 Full uninstall incl. the package (confirm).
    sudo ./uninstaller.sh --yes           Skip the confirmation prompt.
    sudo ./uninstaller.sh --dry-run       Print every action, mutate nothing.
    sudo ./uninstaller.sh --keep-package  Revert state but KEEP the deb installed.
    sudo ./uninstaller.sh --keep-data     Preserve /vault + the app database.
    sudo ./uninstaller.sh --remove-images Also remove pulled docker images.
    ./uninstaller.sh --help               This message.

  Always removed: systemd units, the docker stack (containers/network/volume),
  the svault key device + escrow, the install answer-cache, sudoers fragments,
  /etc/default/sos-vault, generated .env / TLS certs / model dir, the UFW
  80+443 rules, the app service user, and — unless --keep-package — the
  sos-vault package itself (apt purge / dnf remove).

  Never touched: the Docker engine itself. Kept unless a flag opts in:
  /vault + the database (--keep-data preserves), pulled images (--remove-images),
  the package (--keep-package leaves the deb installed for an installer.sh re-run).
EOF
}

# resolve_app_uid — echo the app user's uid if it still exists, else empty.
resolve_app_uid() {
    id -u "$SOS_VAULT_APP_USER" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Confirmation
# ---------------------------------------------------------------------------
confirm() {
    [[ "$DRY_RUN" -eq 1 || "$ASSUME_YES" -eq 1 ]] && return 0

    cat <<EOF

  About to REVERT this sos-vault appliance install:
    • stop + remove the docker stack and systemd units
    • delete the svault key device + escrow (${SVAULT_KEYDIR})
    • remove sudoers fragments, /etc/default/sos-vault, generated .env/certs
    • remove the app service user (${SOS_VAULT_APP_USER})
$(if [[ "$KEEP_DATA" -eq 1 ]]; then echo "    • KEEP your data (/vault + database)"; else echo "    • DELETE /vault and the application database"; fi)
$(if [[ "$REMOVE_IMAGES" -eq 1 ]]; then echo "    • remove the pulled docker images"; fi)
$(if [[ "$REMOVE_PACKAGE" -eq 1 ]]; then echo "    • apt purge / dnf remove the sos-vault package (${SOS_VAULT_DIR})"; else echo "    • KEEP the sos-vault package installed (--keep-package)"; fi)

EOF
    local reply
    read -r -p "  Type 'yes' to proceed: " reply
    [[ "$reply" == "yes" ]] || die 'aborted — nothing was changed.'
}

# ---------------------------------------------------------------------------
# Step 1 — stop + disable systemd units (reverses installer step 11)
# ---------------------------------------------------------------------------
u_01_stop_services() {
    log 'Step 1/12 — stopping + disabling systemd units'
    if ! command -v systemctl >/dev/null 2>&1; then
        warn '  systemctl not present — skipping.'
        return
    fi
    local unit
    for unit in sos-vault.service svaultKey.service; do
        run systemctl stop "$unit" 2>/dev/null || true
        run systemctl disable "$unit" 2>/dev/null || true
    done
}

# ---------------------------------------------------------------------------
# Step 2 — tear down the docker stack (reverses installer step 8)
# ---------------------------------------------------------------------------
u_02_compose_down() {
    log 'Step 2/12 — tearing down the docker stack'
    if ! command -v docker >/dev/null 2>&1; then
        warn '  docker not present — skipping.'
        return
    fi

    local compose_file="${SOS_VAULT_DIR}/docker-compose.yml"
    if [[ -f "$compose_file" || "$DRY_RUN" -eq 1 ]]; then
        # -v drops the named volume (sailredis); --remove-orphans sweeps any
        # renamed/leftover services. Tolerate failure and fall back to by-name.
        run docker compose -f "$compose_file" down -v --remove-orphans 2>/dev/null || true
    fi

    # Fallback: kill anything left by container_name even if compose couldn't.
    local c
    for c in "${APPLIANCE_CONTAINERS[@]}"; do
        if [[ "$DRY_RUN" -eq 1 ]]; then
            printf '   [dry-run] docker rm -f %s (if present)\n' "$c"
        elif docker inspect "$c" >/dev/null 2>&1; then
            docker rm -f "$c" >/dev/null 2>&1 || true
        fi
    done

    # The compose project network + redis volume, by their stable names.
    run docker network rm sos-vault_sail 2>/dev/null || true
    run docker volume rm sos-vault_sailredis 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Step 3 — remove pulled docker images (opt-in; reverses installer step 7)
# ---------------------------------------------------------------------------
u_03_remove_images() {
    if [[ "$REMOVE_IMAGES" -ne 1 ]]; then
        log 'Step 3/12 — keeping docker images (use --remove-images to drop them)'
        return
    fi
    log 'Step 3/12 — removing pulled docker images'
    command -v docker >/dev/null 2>&1 || { warn '  docker not present — skipping.'; return; }
    local img
    for img in "${APPLIANCE_IMAGES[@]}"; do
        # app image is tagged by version; match the repo prefix to catch any tag.
        if [[ "$img" == */app ]]; then
            if [[ "$DRY_RUN" -eq 1 ]]; then
                printf '   [dry-run] docker rmi <all tags of %s>\n' "$img"
            else
                docker images --format '{{.Repository}}:{{.Tag}}' \
                    | grep "^${img}:" | xargs -r docker rmi -f >/dev/null 2>&1 || true
            fi
        else
            run docker rmi -f "$img" 2>/dev/null || true
        fi
    done
}

# ---------------------------------------------------------------------------
# Step 4 — drop the svault keys + close the LUKS device (reverses step 6)
# ---------------------------------------------------------------------------
u_04_teardown_keyring() {
    log 'Step 4/12 — revoking svault keyring + closing the LUKS device'
    local uid
    uid="$(resolve_app_uid)"

    # Purge svault0..3 from the app uid's @u keyring (loaded by execStart.sh).
    if command -v keyctl >/dev/null 2>&1 && [[ -n "$uid" ]]; then
        local i
        for i in 0 1 2 3; do
            if [[ "$DRY_RUN" -eq 1 ]]; then
                printf '   [dry-run] sudo -u #%s keyctl purge user svault%s:key\n' "$uid" "$i"
            else
                sudo -u "#${uid}" keyctl purge user "svault${i}:key" >/dev/null 2>&1 || true
            fi
        done
    else
        warn '  keyctl or app user absent — skipping keyring purge.'
    fi

    # Close the mapper device if execStart left it open (normally it is closed).
    if command -v cryptsetup >/dev/null 2>&1; then
        local mountp="${SVAULT_KEYDIR}/m"
        if mountpoint -q "$mountp" 2>/dev/null; then
            run umount "$mountp" 2>/dev/null || true
        fi
        if [[ -e /dev/mapper/svault ]]; then
            run cryptsetup luksClose svault 2>/dev/null || true
        fi
    fi
}

# ---------------------------------------------------------------------------
# Step 5 — remove sudoers fragments (reverses installer step 13/6)
# ---------------------------------------------------------------------------
u_05_remove_sudoers() {
    log 'Step 5/12 — removing sudoers fragments'
    local name
    for name in sos-vault-cert sos-vault-machine-token sos-vault-svaultkey; do
        run rm -f "/etc/sudoers.d/${name}"
    done
}

# ---------------------------------------------------------------------------
# Step 6 — remove systemd unit files + /etc/default (reverses step 11)
# ---------------------------------------------------------------------------
u_06_remove_systemd_files() {
    log 'Step 6/12 — removing systemd unit files + /etc/default/sos-vault'
    run rm -f /etc/systemd/system/sos-vault.service
    run rm -f /etc/systemd/system/svaultKey.service
    run rm -f /etc/default/sos-vault
    if command -v systemctl >/dev/null 2>&1; then
        run systemctl daemon-reload 2>/dev/null || true
    fi
}

# ---------------------------------------------------------------------------
# Step 7 — remove the UFW rules we added (reverses installer step 12)
# ---------------------------------------------------------------------------
# We delete only the two rules the installer added; we do NOT disable UFW,
# since the operator may rely on it for other services.
u_07_remove_ufw_rules() {
    log 'Step 7/12 — removing UFW rules (80/tcp, 443/tcp)'
    if ! command -v ufw >/dev/null 2>&1; then
        warn '  ufw not installed — skipping.'
        return
    fi
    run ufw --force delete allow 80/tcp 2>/dev/null || true
    run ufw --force delete allow 443/tcp 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Step 8 — remove generated app files (reverses steps 7b/9/10)
# ---------------------------------------------------------------------------
u_08_remove_generated_files() {
    log 'Step 8/12 — removing generated .env, TLS certs, model dir'
    run rm -f "${SOS_VAULT_DIR}/.env"
    run rm -rf "${SOS_VAULT_DIR}/docker-compose/nginx/ssl/sos-vault.com"
    run rm -rf "${SOS_VAULT_DIR}/models"
}

# ---------------------------------------------------------------------------
# Step 9 — reset the application database (reverses step 13's migrate/seed)
# ---------------------------------------------------------------------------
# The DB is SQLite at ${SOS_VAULT_DIR}/database/database.sqlite (bind-mounted
# into the app container). Removing it drops the seeded admin user + all
# migrated tables; a re-install rebuilds the schema. An empty 0-byte file is a
# valid empty SQLite DB, so we recreate one (owned by the app user when it
# still exists) — `php artisan migrate` then has a file to populate.
u_09_reset_database() {
    if [[ "$KEEP_DATA" -eq 1 ]]; then
        log 'Step 9/12 — keeping the application database (--keep-data)'
        return
    fi
    log 'Step 9/12 — resetting the application database'
    local db="${SOS_VAULT_DIR}/database/database.sqlite"
    run rm -f "$db" "${db}-wal" "${db}-shm"
    if [[ -d "${SOS_VAULT_DIR}/database" || "$DRY_RUN" -eq 1 ]]; then
        if [[ "$DRY_RUN" -eq 1 ]]; then
            printf '   [dry-run] recreate empty %s (chown to app user)\n' "$db"
        else
            : > "$db"
            chmod 664 "$db" 2>/dev/null || true
            local uid
            uid="$(resolve_app_uid)"
            [[ -n "$uid" ]] && chown "${uid}:${uid}" "$db" 2>/dev/null || true
        fi
    fi
}

# ---------------------------------------------------------------------------
# Step 10 — remove the svault key dir + install answer-cache (reverses steps 6/5b)
# ---------------------------------------------------------------------------
u_10_remove_state() {
    log 'Step 10/12 — removing key device, escrow, and install answer-cache'
    run rm -rf "$SVAULT_KEYDIR"
    if command -v shred >/dev/null 2>&1 && [[ -f "$INSTALL_CACHE" && "$DRY_RUN" -ne 1 ]]; then
        shred -u "$INSTALL_CACHE" 2>/dev/null || rm -f "$INSTALL_CACHE"
    else
        run rm -f "$INSTALL_CACHE"
    fi
}

# ---------------------------------------------------------------------------
# Step 11 — remove the vault dir (reverses steps 2b/14; skipped by --keep-data)
# ---------------------------------------------------------------------------
u_11_remove_vault() {
    if [[ "$KEEP_DATA" -eq 1 ]]; then
        log 'Step 11/12 — keeping the vault directory (--keep-data)'
        return
    fi
    log "Step 11/12 — removing the vault directory (${SOS_VAULT_VAULT_DIR})"
    run rm -rf "$SOS_VAULT_VAULT_DIR"
}

# ---------------------------------------------------------------------------
# Step 12 — remove the app service user (reverses installer step 2b)
# ---------------------------------------------------------------------------
# Done last so earlier steps could still resolve its uid (keyring, db chown).
u_12_remove_app_user() {
    log "Step 12/12 — removing the app service user (${SOS_VAULT_APP_USER})"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] userdel -r %s ; groupdel %s (if present)\n' \
            "$SOS_VAULT_APP_USER" "$SOS_VAULT_APP_USER"
    elif getent passwd "$SOS_VAULT_APP_USER" >/dev/null 2>&1; then
        # -r removes its home (/var/lib/sos-vault/home). Tolerate the group
        # already being gone or still referenced.
        userdel -r "$SOS_VAULT_APP_USER" 2>/dev/null \
            || userdel "$SOS_VAULT_APP_USER" 2>/dev/null || true
        getent group "$SOS_VAULT_APP_USER" >/dev/null 2>&1 \
            && groupdel "$SOS_VAULT_APP_USER" 2>/dev/null || true
    else
        log '  user already absent — skipping.'
    fi

    # Drop the now-empty state dir (home + any stragglers) unless data is kept.
    if [[ "$KEEP_DATA" -ne 1 ]]; then
        run rm -rf "${SOS_VAULT_STATE_DIR}/home"
        run rmdir "$SOS_VAULT_STATE_DIR" 2>/dev/null || true
    fi
}

# ---------------------------------------------------------------------------
# Remove the sos-vault package itself + its payload (default; skip --keep-package)
# ---------------------------------------------------------------------------
# Prefer the package manager so dpkg/rpm forgets the package AND its own
# prerm/postrm run (which also clear the deb-dropped /etc files). Fall back to
# a plain rm only when the package isn't tracked (a manual tarball extract).
# Safe to self-delete the running script: bash parses the whole file before
# main() executes, so removing /opt/sos-vault mid-run does not break it.
u_remove_package() {
    if [[ "$REMOVE_PACKAGE" -ne 1 ]]; then
        log 'Package — keeping the sos-vault package installed (--keep-package)'
        return
    fi
    log 'Package — removing the sos-vault package + payload'

    if command -v dpkg >/dev/null 2>&1 && dpkg -s sos-vault >/dev/null 2>&1; then
        # apt purge runs the deb maintainer scripts and wipes config files too.
        if command -v apt-get >/dev/null 2>&1; then
            run apt-get purge -y sos-vault
        else
            run dpkg --purge sos-vault
        fi
    elif command -v rpm >/dev/null 2>&1 && rpm -q sos-vault >/dev/null 2>&1; then
        if command -v dnf >/dev/null 2>&1; then
            run dnf remove -y sos-vault
        else
            run rpm -e sos-vault
        fi
    else
        warn '  sos-vault not tracked by dpkg/rpm — removing the payload dir directly.'
    fi

    # The package manager only removes files it owns; the installer wrote .env,
    # certs, models and the sqlite DB into the package dir, so sweep any remnant
    # (also the whole dir in the untracked case) for a clean re-install.
    run rm -rf "$SOS_VAULT_DIR"
    warn "  package removed — re-run requires re-installing the .deb/.rpm."
}

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
u_done() {
    log 'uninstall complete'
    cat <<EOF

================================================================
  sos-vault appliance reverted.
$(if [[ "$REMOVE_PACKAGE" -eq 1 ]]; then
    echo "  The sos-vault package was removed — re-install the .deb/.rpm, then"
    echo "  run sysadmin/installer.sh to set the appliance up again."
else
    echo "  Package kept — re-run ${SOS_VAULT_DIR}/sysadmin/installer.sh to set it up again."
fi)
$(if [[ "$KEEP_DATA" -eq 1 ]]; then echo "  Your data (/vault + database) was preserved."; fi)
================================================================
EOF
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)       DRY_RUN=1 ;;
        --yes|-y)        ASSUME_YES=1 ;;
        --keep-data)     KEEP_DATA=1 ;;
        --remove-images) REMOVE_IMAGES=1 ;;
        --keep-package)  REMOVE_PACKAGE=0 ;;
        # Back-compat: --purge used to opt INTO package removal, which is now
        # the default. Accept it as a no-op so old commands keep working.
        --purge)         REMOVE_PACKAGE=1 ;;
        --help|-h)       usage; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
    require_root
    log "sos-vault uninstaller (DRY_RUN=${DRY_RUN})"
    confirm

    u_01_stop_services
    u_02_compose_down
    u_03_remove_images
    u_04_teardown_keyring
    u_05_remove_sudoers
    u_06_remove_systemd_files
    u_07_remove_ufw_rules
    u_08_remove_generated_files
    u_09_reset_database
    u_10_remove_state
    u_11_remove_vault
    u_12_remove_app_user
    u_remove_package
    u_done
}

main "$@"
