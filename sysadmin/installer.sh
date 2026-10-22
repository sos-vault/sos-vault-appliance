#!/bin/bash
# sos-vault appliance installer — Sprint 6 Step D.
#
# Master plan §7.1: 16-step interactive flow that turns a freshly-imaged
# Ubuntu / RHEL host into a working sos-vault appliance. The installer
# is a SKELETON at this stage — heavy operations (GPG keyring init,
# docker compose up, LLM model download) are wired with real commands but
# several gate behind --dry-run for CI portability. sos-vault stores its
# vaults in a plain directory (default /vault); no ZFS is required.
# See HANDOFF §4.7 for which pieces are skeleton vs production-ready.
#
# Usage:
#     sudo ./installer.sh                  # interactive install
#     sudo ./installer.sh --dry-run        # walk through every step,
#                                          # print intended actions, mutate nothing.
#     ./installer.sh --help                # usage
#
# Environment overrides (rarely needed; defaults are the right answer):
#     SOS_VAULT_DIR              — install root. Default /opt/sos-vault.
#     SOS_VAULT_VAULT_DIR        — vault directory. Default /vault.
#                                  (Override later on the Disk Manager page.)
#     SOS_VAULT_SSL_DIR          — host nginx ssl dir. Default
#                                  docker-compose/nginx/ssl/sos-vault.com
#                                  (relative to SOS_VAULT_DIR).
#     SOS_VAULT_NGINX_CONTAINER  — docker container. Default sos-vault-nginx.

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------

SOS_VAULT_DIR="${SOS_VAULT_DIR:-/opt/sos-vault}"
SOS_VAULT_VAULT_DIR="${SOS_VAULT_VAULT_DIR:-/vault}"
SOS_VAULT_NGINX_CONTAINER="${SOS_VAULT_NGINX_CONTAINER:-sos-vault-nginx}"
# Compose SERVICE name for the app (NOT the container_name). `docker compose
# exec` resolves services, so this must be the key under `services:` in
# docker-compose.appliance.yml — which is `app` (container_name is "sos-vault").
# Passing the container_name here makes compose report "service sos-vault is
# not running" even when the container is up.
SOS_VAULT_APP_SERVICE="${SOS_VAULT_APP_SERVICE:-app}"
SOS_VAULT_DEFAULT_PORT="${SOS_VAULT_DEFAULT_PORT:-2002}"
# Where the svault LUKS key device + escrowed passphrase blobs live. Kept out
# of SOS_VAULT_DIR (which is bind-mounted into the app container) so the key
# device is never exposed inside a container. Owned by the app service user.
SVAULT_KEYDIR="${SVAULT_KEYDIR:-/var/lib/sos-vault/svaultkey}"
# The app service user — name + uid/gid the app runs as on BOTH sides of the
# coupling: the host key service (svaultKey.service) and the container process
# (www-data is remapped to this uid at container start). Step 2b resolves the
# real uid/gid into APP_UID/APP_GID; 1000 is only the dry-run / shipped default
# (the published image bakes www-data at 1000). Override the name via env.
SOS_VAULT_APP_USER="${SOS_VAULT_APP_USER:-sosvault}"
APP_UID="${APP_UID:-1000}"
APP_GID="${APP_GID:-1000}"

DRY_RUN=0

# Where the prompted answers (admin creds + passphrase + policy) are cached so a
# RESUMED install — one that died at a later step — does not re-ask for them.
# Root-only 0600, kept OUT of SVAULT_KEYDIR (which is chowned to the app uid) so
# the app user can never read the admin password, and shredded on success
# (step 16). Survives across re-runs but not a clean machine wipe.
INSTALL_CACHE="${INSTALL_CACHE:-/var/lib/sos-vault/.install-answers}"

# Minimum length enforced on any prompted password/passphrase.
MIN_PASSWORD_LEN=5

# Filled by interactive prompts.
ADMIN_NAME=""
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
GPG_PASSPHRASE=""

# Passphrase escrow policy (Step 5b): tpm | relaxed | vault.
KEYSTORE_POLICY=""
VAULT_ADDR_IN=""
VAULT_SECRET_PATH_IN=""
VAULT_ROLE_ID_IN=""
VAULT_SECRET_ID_IN=""

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

log()  { printf '\033[1;34m[installer]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[installer]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[installer]\033[0m %s\n' "$*" >&2; exit 1; }

run() {
    # Execute a command unless --dry-run is set, in which case just print it.
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] %s\n' "$*"
    else
        "$@"
    fi
}

prompt() {
    local question="$1" default="${2:-}" reply
    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo "${default:-<dry-run-stub>}"
        return
    fi
    if [[ -n "$default" ]]; then
        read -r -p "$question [$default]: " reply
        echo "${reply:-$default}"
    else
        read -r -p "$question: " reply
        echo "$reply"
    fi
}

prompt_secret() {
    local question="$1" reply
    if [[ "$DRY_RUN" -eq 1 ]]; then
        echo '<dry-run-stub-secret>'
        return
    fi
    read -r -s -p "$question: " reply
    echo >&2
    echo "$reply"
}

# prompt_password — like prompt_secret, but re-prompts until the reply is at least
# MIN_PASSWORD_LEN characters. Length is not checked under DRY_RUN, where
# prompt_secret returns a fixed stub (validating it would loop forever).
prompt_password() {
    local question="$1" reply
    reply="$(prompt_secret "$question")"
    while [[ "$DRY_RUN" -ne 1 && ${#reply} -lt "$MIN_PASSWORD_LEN" ]]; do
        warn "  Must be at least ${MIN_PASSWORD_LEN} characters — please try again."
        reply="$(prompt_secret "$question")"
    done
    echo "$reply"
}

# load_answer_cache — repopulate prompted answers from a prior (failed) run so a
# resume skips the prompts. Sourced, so values are `printf %q`-quoted on save.
load_answer_cache() {
    [[ "$DRY_RUN" -eq 1 ]] && return 0
    [[ -r "$INSTALL_CACHE" ]] || return 0
    # shellcheck disable=SC1090
    . "$INSTALL_CACHE"
    log "  reusing answers from a previous run (${INSTALL_CACHE})"
}

# save_answer_cache — persist the prompted answers root-only so the next run can
# resume without re-asking. Called once the prompts (steps 4-5b) are collected.
save_answer_cache() {
    [[ "$DRY_RUN" -eq 1 ]] && return 0
    mkdir -p "$(dirname "$INSTALL_CACHE")"
    local old_umask
    old_umask="$(umask)"
    umask 077
    {
        printf 'ADMIN_NAME=%q\n'           "$ADMIN_NAME"
        printf 'ADMIN_EMAIL=%q\n'          "$ADMIN_EMAIL"
        printf 'ADMIN_PASSWORD=%q\n'       "$ADMIN_PASSWORD"
        printf 'GPG_PASSPHRASE=%q\n'       "$GPG_PASSPHRASE"
        printf 'KEYSTORE_POLICY=%q\n'      "$KEYSTORE_POLICY"
        printf 'VAULT_ADDR_IN=%q\n'        "$VAULT_ADDR_IN"
        printf 'VAULT_SECRET_PATH_IN=%q\n' "$VAULT_SECRET_PATH_IN"
        printf 'VAULT_ROLE_ID_IN=%q\n'     "$VAULT_ROLE_ID_IN"
        printf 'VAULT_SECRET_ID_IN=%q\n'   "$VAULT_SECRET_ID_IN"
    } > "$INSTALL_CACHE"
    umask "$old_umask"
    chmod 600 "$INSTALL_CACHE" 2>/dev/null || true
    chown root:root "$INSTALL_CACHE" 2>/dev/null || true
}

# clear_answer_cache — best-effort secure wipe of the cached secrets once the
# install has fully succeeded (step 16).
clear_answer_cache() {
    [[ "$DRY_RUN" -eq 1 ]] && return 0
    [[ -e "$INSTALL_CACHE" ]] || return 0
    if command -v shred >/dev/null 2>&1; then
        shred -u "$INSTALL_CACHE" 2>/dev/null || rm -f "$INSTALL_CACHE"
    else
        rm -f "$INSTALL_CACHE"
    fi
}

require_root() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        return
    fi
    if [[ "$(id -u)" -ne 0 ]]; then
        die 'installer.sh must run as root (or under sudo).'
    fi
}

usage() {
    cat <<'EOF'
sos-vault appliance installer

  Usage:
    sudo ./installer.sh                  Interactive install (default).
    sudo ./installer.sh --dry-run        Walk every step, mutate nothing.
    ./installer.sh --help                This message.

  Steps:
     1. Verify supported OS                     9. Ensure self-signed TLS cert
     2. Verify hardware (RAM/CPU/disk)         10. Prepare AI model dir (model is
     2b. Provision app service user                downloaded later from admin UI)
     3. Install Docker + Compose if missing    11. Install systemd units
     4. Prompt for admin credentials           12. Configure host firewall
     5. Prompt for GPG passphrase              13. Run migrate + Appliance seeder
     5b. Choose passphrase storage policy      13b. Capture host HW fingerprint
     6. Initialize GPG keyring + escrow        14. Ensure plain vault dir
     7. Pull docker images (ghcr.io)           15. Print success message
     7b. Write application .env (if missing)
     8. docker compose up -d
EOF
}

# ---------------------------------------------------------------------------
# Step 1 — supported OS
# ---------------------------------------------------------------------------
step_01_check_os() {
    log 'Step 1/15 — checking OS version'
    if [[ ! -r /etc/os-release ]]; then
        die '/etc/os-release missing — cannot identify OS.'
    fi
    # shellcheck disable=SC1091
    . /etc/os-release

    local supported=0
    case "${ID:-}" in
        ubuntu)
            # Accept Ubuntu 22.04 and newer, including non-LTS interim
            # releases (22.10, 23.04, 24.10, 26.04, …). VERSION_ID is "YY.MM";
            # `sort -V` orders the lower version first, so the host qualifies
            # when 22.04 is the smaller of {22.04, VERSION_ID}.
            if [[ -n "${VERSION_ID:-}" ]] \
                && [[ "$(printf '%s\n%s\n' '22.04' "${VERSION_ID}" | sort -V | head -n1)" == '22.04' ]]; then
                supported=1
            fi
            ;;
        rhel|rocky|almalinux)
            case "${VERSION_ID:-}" in
                8*|9*) supported=1 ;;
            esac
            ;;
    esac

    if [[ "$supported" -eq 1 ]]; then
        log "  detected: ${PRETTY_NAME:-$ID $VERSION_ID}"
    else
        warn "  unsupported OS: ${ID:-?} ${VERSION_ID:-?} — install will continue but is untested."
    fi
}

# ---------------------------------------------------------------------------
# Step 2 — hardware floor
# ---------------------------------------------------------------------------
step_02_check_hardware() {
    log 'Step 2/15 — checking hardware'

    local mem_kb mem_gb cores
    mem_kb="$(awk '/MemTotal/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
    mem_gb=$(( mem_kb / 1024 / 1024 ))
    cores="$(nproc 2>/dev/null || echo 0)"

    log "  RAM: ${mem_gb} GB ; CPU cores: ${cores}"

    if (( mem_gb < 32 )); then
        warn "  RAM below 32GB recommended floor — install will continue."
    fi
    if (( cores < 16 )); then
        warn "  CPU cores below 16 recommended floor — install will continue."
    fi
}

# ---------------------------------------------------------------------------
# Step 2b — app service user
#
# The app runs as one uid on BOTH sides of the kernel-keyring coupling: the
# host key service (svaultKey.service) and the container process. We create a
# dedicated, locked system user so the uid is reserved and is NOT a human login
# (a human at the app's uid could read the master keys from the @u keyring).
# Using a system uid (<1000) also sidesteps a collision with the conventional
# first human account at 1000. www-data inside the container is remapped to
# this uid at container start (container_start.sh), so no image rebuild is
# needed. Everything downstream keys off APP_UID/APP_GID.
# ---------------------------------------------------------------------------
step_02b_provision_app_user() {
    log 'Step 2b/15 — provisioning the app service user'

    if [[ "$DRY_RUN" -eq 1 ]]; then
        log "  [dry-run] would ensure system user ${SOS_VAULT_APP_USER}; APP_UID/APP_GID default to ${APP_UID}/${APP_GID}."
        return
    fi

    if getent passwd "$SOS_VAULT_APP_USER" >/dev/null 2>&1; then
        log "  user ${SOS_VAULT_APP_USER} already exists — reusing it."
    else
        # Locked, no-login system account with a matching primary group. Its
        # home holds nothing secret (the key device lives under SVAULT_KEYDIR).
        # RHEL/AlmaLinux 8 ship shadow-utils 4.6, whose useradd --create-home
        # does NOT create missing parent dirs of --home-dir (newer shadow on
        # Ubuntu 24.04 does); /var/lib/sos-vault isn't created until the
        # answer-cache step, so make the parent here or useradd fails with
        # "cannot create directory /var/lib/sos-vault/home".
        mkdir -p /var/lib/sos-vault
        useradd --system --user-group \
            --home-dir /var/lib/sos-vault/home --create-home \
            --shell /usr/sbin/nologin "$SOS_VAULT_APP_USER" \
            || die "could not create app user ${SOS_VAULT_APP_USER}"
        log "  created system user ${SOS_VAULT_APP_USER}."
    fi

    APP_UID="$(id -u "$SOS_VAULT_APP_USER")"
    APP_GID="$(id -g "$SOS_VAULT_APP_USER")"
    log "  app service identity: ${SOS_VAULT_APP_USER} (uid ${APP_UID}, gid ${APP_GID})"

    # Hand the bind-mounted app tree + vault dir to the app user so the
    # container (running as this uid) can write storage/, .gnupg, logs, vaults.
    # The deb unpacks root-owned, so this is required for ANY uid != the
    # deb owner — not just non-1000.
    if [[ -d "$SOS_VAULT_DIR" ]]; then
        log "  chowning ${SOS_VAULT_DIR} -> ${APP_UID}:${APP_GID}"
        chown -R "${APP_UID}:${APP_GID}" "$SOS_VAULT_DIR" 2>/dev/null \
            || warn "  could not fully chown ${SOS_VAULT_DIR}"
    fi
    mkdir -p "$SOS_VAULT_VAULT_DIR"
    chown "${APP_UID}:${APP_GID}" "$SOS_VAULT_VAULT_DIR" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Step 3 — Docker + Compose
# ---------------------------------------------------------------------------
step_03_install_docker() {
    log 'Step 3/15 — installing Docker + Compose if missing'

    if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        log '  docker + compose already present — skipping.'
        return
    fi

    if [[ -r /etc/os-release ]]; then
        # shellcheck disable=SC1091
        . /etc/os-release
    fi
    case "${ID:-}" in
        ubuntu|debian)
            run apt-get update
            run apt-get install -y docker.io
            # The Compose v2 plugin is packaged under different names: Docker's
            # official apt repo ships `docker-compose-plugin`, while Ubuntu's
            # own archive ships it as `docker-compose-v2`. Try the Docker name
            # first, fall back to the Ubuntu name so a stock host (no Docker
            # repo enabled) still resolves it.
            if ! run apt-get install -y docker-compose-plugin; then
                run apt-get install -y docker-compose-v2
            fi
            ;;
        rhel|rocky|almalinux|centos|fedora)
            # RHEL-family repos have NO `docker` package (they ship podman), so
            # Docker CE must come from Docker's own repo. dnf-plugins-core
            # provides `config-manager`, which is absent on minimal images.
            # RHEL/Alma/Rocky/CentOS all use the centos repo ($releasever
            # resolves to 8/9); Fedora has its own path. --allowerasing lets
            # docker-ce's containerd.io replace a pre-existing podman/runc.
            local docker_repo='https://download.docker.com/linux/centos/docker-ce.repo'
            [[ "${ID:-}" == 'fedora' ]] && docker_repo='https://download.docker.com/linux/fedora/docker-ce.repo'
            run dnf install -y dnf-plugins-core
            run dnf config-manager --add-repo "$docker_repo"
            run dnf install -y --allowerasing \
                docker-ce docker-ce-cli containerd.io docker-compose-plugin
            ;;
        *)
            warn '  unknown distro — install Docker + Compose manually before re-running.'
            ;;
    esac

    run systemctl enable --now docker
}

# ---------------------------------------------------------------------------
# Prerequisite host packages (RHEL family)
#
# The .deb declares these via its Depends: line, so apt pulls them in
# automatically. The .rpm, however, is produced by `alien --to-rpm`, which
# DROPS the Debian dependency metadata — so on a fresh RHEL/Alma/Rocky host
# none of them are guaranteed present. Install the handful the later steps
# need directly: cryptsetup + keyutils for the LUKS key device and kernel
# keyring (Step 6), dmidecode for the license fingerprint (Step 13b), and
# openssl for the .env APP_KEY + self-signed cert (Steps 7b/9). Debian/Ubuntu
# already have them via the .deb, so this is a no-op there.
# ---------------------------------------------------------------------------
install_prereq_packages() {
    log 'Ensuring prerequisite host packages'

    if [[ -r /etc/os-release ]]; then
        # shellcheck disable=SC1091
        . /etc/os-release
    fi
    case "${ID:-}" in
        rhel|rocky|almalinux|centos|fedora)
            run dnf install -y cryptsetup keyutils dmidecode openssl
            ;;
        *)
            log '  Debian/Ubuntu resolve these via the .deb Depends: — skipping.'
            ;;
    esac
}

# ---------------------------------------------------------------------------
# Step 4 — admin credentials
# ---------------------------------------------------------------------------
step_04_prompt_admin_credentials() {
    log 'Step 4/15 — prompting for admin credentials'
    if [[ -n "$ADMIN_EMAIL" && -n "$ADMIN_PASSWORD" ]]; then
        log '  admin credentials reused from a previous run — skipping prompt.'
        return
    fi
    ADMIN_NAME="$(prompt 'Admin display name' 'Administrator')"
    ADMIN_EMAIL="$(prompt 'Admin email address (used for sign-in)')"
    # Re-prompt until the address is well-formed. Skipped under DRY_RUN, where the
    # prompt returns a fixed stub (validating it would loop forever).
    while [[ "$DRY_RUN" -ne 1 && ! "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]; do
        warn "  '$ADMIN_EMAIL' is not a valid email address — please try again."
        ADMIN_EMAIL="$(prompt 'Admin email address (used for sign-in)')"
    done
    ADMIN_PASSWORD="$(prompt_password 'Admin password (used to sign in to /admin)')"
}

# ---------------------------------------------------------------------------
# Step 5 — GPG passphrase for the svault* keyring
# ---------------------------------------------------------------------------
step_05_prompt_gpg_passphrase() {
    log 'Step 5/15 — prompting for GPG keyring passphrase'
    if [[ -n "$GPG_PASSPHRASE" ]]; then
        log '  passphrase reused from a previous run — skipping prompt.'
        return
    fi
    GPG_PASSPHRASE="$(prompt_password 'Passphrase for svault keyring (NEVER FORGET THIS)')"
}

# ---------------------------------------------------------------------------
# Step 5b — passphrase storage policy (reboot survival)
#
# The keyring is non-persistent across reboots, so the LUKS passphrase must be
# escrowed for an unattended restart. The SAME operator-typed passphrase is
# escrowed — never a second LUKS key — so it always stays a valid manual
# recovery passphrase. Three backends (see sysadmin/resolve-passphrase.sh):
#   tpm     — auto-selected when a usable TPM 2.0 is present (no PCR binding,
#             so kernel/firmware updates never lock the box out).
#   relaxed — default with no TPM: AES on disk, machine-derived key. Honest:
#             obfuscation, not theft-proof.
#   vault   — opt-in: HashiCorp Vault over the network.
# ---------------------------------------------------------------------------

# tpm_usable — true when a TPM 2.0 resource manager exists and responds.
tpm_usable() {
    [[ -e /dev/tpmrm0 ]] || return 1
    command -v tpm2_pcrread >/dev/null 2>&1 || return 1
    tpm2_pcrread sha256:0 >/dev/null 2>&1
}

# install_tpm_tools — pull tpm2-tools only when a TPM is actually present.
install_tpm_tools() {
    command -v tpm2_unseal >/dev/null 2>&1 && return
    log '  installing tpm2-tools (TPM detected)'
    if [[ -r /etc/os-release ]]; then
        # shellcheck disable=SC1091
        . /etc/os-release
    fi
    case "${ID:-}" in
        ubuntu|debian) run apt-get install -y tpm2-tools ;;
        rhel|rocky|almalinux|centos|fedora) run dnf install -y tpm2-tools ;;
        *) warn '  unknown distro — install tpm2-tools manually for TPM sealing.' ;;
    esac
}

step_05b_choose_keystore_policy() {
    log 'Step 5b/15 — choosing passphrase storage policy (reboot survival)'

    if [[ -n "$KEYSTORE_POLICY" ]]; then
        log "  storage policy reused from a previous run (${KEYSTORE_POLICY}) — skipping prompt."
        return
    fi

    if [[ "$DRY_RUN" -eq 1 ]]; then
        log '  [dry-run] skipping TPM probe; policy defaults to relaxed.'
        KEYSTORE_POLICY='relaxed'
        return
    fi

    if [[ -e /dev/tpmrm0 ]]; then
        install_tpm_tools
        if tpm_usable; then
            KEYSTORE_POLICY='tpm'
            log '  TPM 2.0 detected — passphrase will be sealed to the TPM (no PCR binding).'
            return
        fi
        warn '  TPM device present but not usable — falling back to a policy prompt.'
    fi

    cat <<'EOF'

  No usable TPM found. Choose how the main passphrase is stored so the
  appliance can unlock its keyring automatically after a reboot:

    1) Relaxed (default) — passphrase stored encrypted on this server.
       Survives reboot unattended. NOT protected against disk theft.
    2) Strong — retrieve from a network secrets vault (HashiCorp Vault).
       Requires Vault reachable at boot; you provide connection parameters.

EOF
    local choice
    choice="$(prompt 'Storage policy [1=Relaxed, 2=Strong]' '1')"
    case "$choice" in
        2)
            KEYSTORE_POLICY='vault'
            VAULT_ADDR_IN="$(prompt 'Vault address (e.g. https://vault.corp:8200)')"
            VAULT_SECRET_PATH_IN="$(prompt 'KV secret path (e.g. secret/sos-vault/passphrase)')"
            VAULT_ROLE_ID_IN="$(prompt 'AppRole role_id')"
            VAULT_SECRET_ID_IN="$(prompt_secret 'AppRole secret_id')"
            if [[ -z "$VAULT_ADDR_IN" || -z "$VAULT_SECRET_PATH_IN" \
                || -z "$VAULT_ROLE_ID_IN" || -z "$VAULT_SECRET_ID_IN" ]]; then
                die 'Strong policy requires Vault address, secret path, role_id and secret_id.'
            fi
            ;;
        *)
            KEYSTORE_POLICY='relaxed'
            ;;
    esac
    log "  policy: ${KEYSTORE_POLICY}"
}

# escrow_passphrase — store the LUKS passphrase per KEYSTORE_POLICY so the boot
# service can recover it unattended. Writes ${SVAULT_KEYDIR}/policy plus the
# backend's blobs, then hands the dir to UID 1000 (the boot service user).
escrow_passphrase() {
    local policy_file="${SVAULT_KEYDIR}/policy"
    log "  escrowing passphrase under ${SVAULT_KEYDIR} (policy: ${KEYSTORE_POLICY})"

    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] write %s (POLICY=%s) + backend blobs, chown %s:%s\n' \
            "$policy_file" "$KEYSTORE_POLICY" "$APP_UID" "$APP_GID"
        return
    fi

    mkdir -p "$SVAULT_KEYDIR"
    chmod 700 "$SVAULT_KEYDIR"

    case "$KEYSTORE_POLICY" in
        tpm)
            local primary="${SVAULT_KEYDIR}/.primary.ctx" handle='0x81010001'
            tpm2_evictcontrol -c "$handle" >/dev/null 2>&1 || true
            tpm2_createprimary -C o -g sha256 -G rsa -c "$primary" >/dev/null
            tpm2_evictcontrol -c "$primary" "$handle" >/dev/null
            # No PCR policy → unseal needs no authorization (theft-resistant,
            # update-safe). Passphrase is fed in on stdin.
            printf '%s' "$GPG_PASSPHRASE" | tpm2_create -C "$primary" \
                -u "${SVAULT_KEYDIR}/passphrase.tpm.pub" \
                -r "${SVAULT_KEYDIR}/passphrase.tpm.priv" \
                -i - >/dev/null
            rm -f "$primary"
            cat > "$policy_file" <<EOF
POLICY_VERSION=1
POLICY=tpm
TPM_PUB=passphrase.tpm.pub
TPM_PRIV=passphrase.tpm.priv
TPM_PRIMARY_HANDLE=${handle}
EOF
            ;;
        relaxed)
            local enc="${SVAULT_KEYDIR}/passphrase.enc" ptmp keymat mid
            # Derive ONLY from /etc/machine-id (world-readable, so identical at
            # boot under UID 1000). Must match resolve-passphrase.sh::machine_keymat.
            [[ -r /etc/machine-id ]] || die 'no /etc/machine-id — cannot use the relaxed policy'
            mid="$(cat /etc/machine-id)"
            [[ -n "$mid" ]] || die '/etc/machine-id is empty — cannot use the relaxed policy'
            keymat="machine-id:${mid}"
            ptmp="$(mktemp)"
            printf '%s' "$GPG_PASSPHRASE" > "$ptmp"
            # Symmetric with resolve-passphrase.sh: data via -in/-out, derive
            # material (keymat) via -pass stdin.
            printf '%s' "$keymat" | openssl enc -aes-256-cbc -pbkdf2 -salt \
                -in "$ptmp" -out "$enc" -pass stdin
            rm -f "$ptmp"
            cat > "$policy_file" <<EOF
POLICY_VERSION=1
POLICY=relaxed
ENC_FILE=passphrase.enc
KDF=machine-id
EOF
            ;;
        vault)
            export VAULT_ADDR="$VAULT_ADDR_IN"
            local token
            token="$(vault write -field=token auth/approle/login \
                role_id="$VAULT_ROLE_ID_IN" secret_id="$VAULT_SECRET_ID_IN")" \
                || die 'Vault AppRole login failed — check address/role_id/secret_id.'
            VAULT_TOKEN="$token" vault kv put "$VAULT_SECRET_PATH_IN" passphrase="$GPG_PASSPHRASE" \
                || die 'Vault kv put failed — check the secret path and AppRole policy.'
            local creds="${SVAULT_KEYDIR}/vault.conf"
            printf 'VAULT_SECRET_ID=%s\n' "$VAULT_SECRET_ID_IN" > "$creds"
            chmod 600 "$creds"
            cat > "$policy_file" <<EOF
POLICY_VERSION=1
POLICY=vault
VAULT_ADDR=${VAULT_ADDR_IN}
VAULT_SECRET_PATH=${VAULT_SECRET_PATH_IN}
VAULT_FIELD=passphrase
VAULT_AUTH=approle
VAULT_ROLE_ID=${VAULT_ROLE_ID_IN}
VAULT_CREDS_FILE=vault.conf
EOF
            ;;
        *)
            die "internal: unknown KEYSTORE_POLICY '${KEYSTORE_POLICY}'"
            ;;
    esac

    chmod 600 "$policy_file"
    # The boot service runs as the app user and must read the dir + blobs + device.
    chown -R "${APP_UID}:${APP_GID}" "$SVAULT_KEYDIR" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Step 6 — GPG keyring init (delegates to sysadmin/init.sh)
# ---------------------------------------------------------------------------
step_06_init_gpg_keyring() {
    log 'Step 6/15 — initializing GPG keyring'

    local script="${SOS_VAULT_DIR}/sysadmin/init.sh"
    if [[ ! -x "$script" ]]; then
        warn "  $script not found or not executable — skipping (re-run after image extract)."
        return
    fi

    # Idempotency: init.sh is DESTRUCTIVE — it dd-zeroes and luksFormats a fresh
    # key device with brand-new random svault keys. Re-running it after a
    # partial install (the installer died at a later step) would discard the
    # keys existing vaults were sealed with. So if the device AND its escrowed
    # passphrase are already present, skip (re)creation + escrow and only
    # (re)wire the boot service and load the keyring for this boot.
    if [[ "$DRY_RUN" -ne 1 && -f "${SVAULT_KEYDIR}/svault.key" && -f "${SVAULT_KEYDIR}/policy" ]]; then
        log '  svault key device + escrow already present — skipping re-init (idempotent).'
    else
        # Pipe the passphrase + key location in via the environment so init.sh
        # runs unattended and creates the device under SVAULT_KEYDIR (not $HOME).
        SVAULT_PASSPHRASE="$GPG_PASSPHRASE" SVAULT_KEYDIR="$SVAULT_KEYDIR" \
            SVAULT_UID="$APP_UID" SVAULT_GID="$APP_GID" run bash "$script"

        # Escrow the passphrase per the Step 5b policy so reboots unlock unattended.
        escrow_passphrase
    fi

    # The boot service opens the LUKS device via its sudoers fragment; install
    # it now, then populate the keyring for THIS boot so the app works before
    # the first reboot. Both are idempotent and safe to re-run.
    install_one_sudoers sos-vault-svaultkey
    load_keyring_now
}

# load_keyring_now — run execStart.sh as the app uid to load svault0..3 into the
# keyring for the current boot (subsequent boots use svaultKey.service). Must
# run as APP_UID so the keys land in the @u keyring the app container shares.
load_keyring_now() {
    log '  loading svault keys into the kernel keyring (this boot)'
    local es="${SOS_VAULT_DIR}/sysadmin/execStart.sh"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] sudo -u #%s env SVAULT_KEYDIR=%s %s\n' "$APP_UID" "$SVAULT_KEYDIR" "$es"
        return
    fi
    if [[ ! -x "$es" ]]; then
        warn "  $es missing — keyring will load on next boot."
        return
    fi
    sudo -u "#${APP_UID}" env SVAULT_KEYDIR="$SVAULT_KEYDIR" "$es" \
        || warn '  keyring load failed — reboot (svaultKey.service) will retry.'
}

# ---------------------------------------------------------------------------
# Step 7 — pull docker images from their registries
#
# Images are NOT bundled in the deb (keeps it ~50-80 MB). docker-compose.yml
# pins the app image to ghcr.io/sos-vault/app:<version> (published to GHCR at
# release time); nginx, redis and llama.cpp are upstream public images. We pull
# everything here so Step 8's `up --no-build` never has to build on this host.
# Needs outbound HTTPS to ghcr.io / docker.io. Air-gapped hosts can instead
# `docker load` a side-loaded images tarball before running the installer.
# ---------------------------------------------------------------------------
step_07_pull_images() {
    log 'Step 7/15 — pulling docker images (ghcr.io / docker.io)'
    if ! run docker compose -f "${SOS_VAULT_DIR}/docker-compose.yml" pull; then
        warn '  image pull failed — check outbound HTTPS to ghcr.io, or side-load'
        warn '  the images with `docker load` (air-gapped install) and re-run.'
    fi
}

# ---------------------------------------------------------------------------
# Step 7b — application environment file
#
# The deb ships WITHOUT a .env (build.sh excludes it), so a fresh install has
# no APP_KEY/APP_URL and neither the containers (Step 8) nor migrate (Step 13)
# can run. Generate one here — ONLY if absent, so an operator-edited file is
# never clobbered. APP_KEY is freshly generated per install; APP_URL is built
# from the system hostname and the default HTTPS port (2002), matching the
# admin "Host & Port" settings section.
# ---------------------------------------------------------------------------
step_07b_write_env_file() {
    log 'Step 7b/15 — writing application .env (created only if missing)'

    local env_file="${SOS_VAULT_DIR}/.env"

    if [[ -f "$env_file" ]]; then
        log "  ${env_file} already exists — leaving it untouched."
        return
    fi

    local host port app_key
    host="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)"
    [[ -z "$host" ]] && host='localhost'
    port="${SOS_VAULT_DEFAULT_PORT}"
    app_key="base64:$(openssl rand -base64 32)"

    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] write %s (APP_URL=https://%s:%s, freshly generated APP_KEY)\n' "$env_file" "$host" "$port"
        return
    fi

    # The generated .env is kept comment-free EXCEPT for the optional outbound
    # HTTP(S) proxy block at the end (operators need those notes in front of the
    # keys they toggle). All other rationale lives here in the installer source.
    # About DB_DATABASE: the application code is baked
    # into the image and only the storage/ tree is bind-mounted from the host, so
    # the sqlite app DB lives under storage so it persists across image pulls (the
    # baked database/ dir holds migrations, not the live DB). DB_CONNECTION is
    # intentionally omitted — config/database.php hardcodes 'default' => 'sqlite',
    # so it would be a no-op — but DB_DATABASE IS required: the config fallback
    # (database_path()) points at the non-persistent in-image database/ dir.
    cat > "$env_file" <<EOF
APP_NAME=sos-vault
APP_ENV=production
APP_KEY=${app_key}
APP_DEBUG=false
APP_URL=https://${host}:${port}

BROADCAST_DRIVER=log
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=file
SESSION_LIFETIME=10

REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CLIENT=predis

DB_DATABASE=/var/www/site/storage/app/db/database.sqlite

WWWUSER=${APP_UID}
WWWGROUP=${APP_GID}

# --- Outbound HTTP(S) proxy (optional) -------------------------------------
# Only needed when this appliance reaches the Internet through a corporate proxy
# AND you enable an integration that calls out: Jira/ITSM, Telegram, or remote
# AI (OpenAI). To enable, uncomment HTTPS_PROXY and set your proxy URL.
#   * Use HTTPS_PROXY (the web context ignores HTTP_PROXY); nearly all outbound
#     is HTTPS, so this one variable covers those integrations.
#   * SMTP mail does NOT use an HTTP proxy — it is a separate TCP connection.
#   * If the proxy does TLS interception, upload its root CA via the in-app
#     Certificate Manager or outbound HTTPS will fail certificate validation.
#   * After enabling or changing the proxy you MUST restart the app so the new
#     value is read:  systemctl restart sos-vault
# NO_PROXY keeps the appliance itself, the docker network and other LAN/internal
# endpoints (e.g. an internal Jira or mail host) off the proxy — append yours.
#HTTPS_PROXY=http://proxy.example.com:3128
NO_PROXY=localhost,127.0.0.1,172.16.0.0/12,${host}
EOF

    chmod 0640 "$env_file"
    chown "${APP_UID}:${APP_GID}" "$env_file" 2>/dev/null || true
    log "  wrote ${env_file} (APP_URL=https://${host}:${port})"
}

# ensure_self_signed_cert — generate a per-install self-signed TLS cert if the
# pair is absent. The deb ships NO cert (build.sh excludes the dev sos-vault.com
# key so it isn't reused across appliances), so this is what gives each box its
# own key. Idempotent: called before Step 8's compose up (nginx crash-loops
# without a cert) and again at Step 9. Operator can replace it later via the
# CertificateManager admin page.
ensure_self_signed_cert() {
    local ssl_dir="${SOS_VAULT_DIR}/docker-compose/nginx/ssl/sos-vault.com"
    run mkdir -p "$ssl_dir"

    if [[ ! -f "${ssl_dir}/fullchain.pem" || ! -f "${ssl_dir}/privkey.pem" ]]; then
        log '  generating self-signed TLS certificate'
        run openssl req -x509 -newkey rsa:2048 -nodes -days 365 \
            -keyout "${ssl_dir}/privkey.pem" \
            -out "${ssl_dir}/fullchain.pem" \
            -subj '/CN=sos-vault.local'
        run chmod 644 "${ssl_dir}/fullchain.pem"
        run chmod 600 "${ssl_dir}/privkey.pem"
    fi

    # The CertificateManager admin page replaces these files from the app
    # container (running as APP_UID), so the dir + cert pair must be owned by
    # APP_UID — the cert is minted here as root, after the repo-wide chown.
    run chown -R "${APP_UID}:${APP_GID}" "$ssl_dir"
}

# ensure_corp_ca_dir — host store for operator-installed corporate root CAs,
# bind-mounted into the app container at /usr/local/share/ca-certificates. Must
# exist and be APP_UID-owned BEFORE compose up, otherwise docker creates the
# bind-mount source as root and the app user can't write uploaded CAs into it.
ensure_corp_ca_dir() {
    local ca_dir="${SOS_VAULT_DIR}/docker-compose/ca-certificates"
    run mkdir -p "$ca_dir"
    run chown -R "${APP_UID}:${APP_GID}" "$ca_dir"
}

# ensure_storage_dir — the app code is baked into the image; only storage/ is
# bind-mounted from the host. The slimmed deb ships NO storage/ tree, so it must
# be created (APP_UID-owned) BEFORE compose up — otherwise docker makes the
# bind-mount source root-owned and the app user gets EACCES on sessions, logs,
# uploads, and the sqlite DB. container_start.sh seeds the same skeleton
# defensively on every boot; this is the host-side counterpart. The sqlite DB
# (DB_DATABASE) lives in storage/app/db so it persists across image pulls.
ensure_storage_dir() {
    local storage="${SOS_VAULT_DIR}/storage"
    run mkdir -p \
        "${storage}/framework/cache/data" \
        "${storage}/framework/sessions" \
        "${storage}/framework/views" \
        "${storage}/logs" \
        "${storage}/app/public" \
        "${storage}/app/db"
    # Laravel's SQLite connector refuses to auto-create the DB file, so seed an
    # empty one before `artisan migrate` (Step 13). Idempotent: never truncates
    # an existing DB.
    run touch "${storage}/app/db/database.sqlite"
    run chown -R "${APP_UID}:${APP_GID}" "$storage"
}

# ---------------------------------------------------------------------------
# Step 8 — docker compose up
# ---------------------------------------------------------------------------
step_08_compose_up() {
    log 'Step 8/15 — starting containers (docker compose up -d)'
    # nginx is brought up here and refuses to start without its TLS cert; the
    # deb ships none, so mint the per-install self-signed pair FIRST.
    ensure_self_signed_cert
    # Pre-create the corp-CA bind-mount source (app-owned) before compose up.
    ensure_corp_ca_dir
    # Pre-create the storage/ bind-mount source (app-owned) — the deb no longer
    # ships it, and the sqlite DB + file sessions live there.
    ensure_storage_dir
    # --no-build / --pull never: images were already pulled in Step 7. Fail loudly
    # if one is missing rather than silently rebuilding from a Dockerfile.
    run docker compose -f "${SOS_VAULT_DIR}/docker-compose.yml" up -d \
        --no-build --pull never --remove-orphans
}

# ---------------------------------------------------------------------------
# Step 9 — self-signed TLS cert (operator can replace via CertificateManager later)
# ---------------------------------------------------------------------------
step_09_generate_self_signed_cert() {
    log 'Step 9/15 — ensuring self-signed TLS certificate'
    ensure_self_signed_cert
}

# ---------------------------------------------------------------------------
# Step 10 — prepare AI model directory
#
# The ~1.1 GB bot LLM model is NOT downloaded at install time: it is fetched
# on demand by the operator from the admin "Software Updates" page (queued
# DownloadAiModelJob). Here we only create the bind-mount target so the llama
# container starts cleanly; the model lands in it later. The deb ships the dir
# root-owned, so hand it to the app uid (the app/llama container user) which
# does the actual write when the admin triggers the download.
# ---------------------------------------------------------------------------
step_10_prepare_model_dir() {
    log 'Step 10/15 — preparing AI model directory (download deferred to admin UI)'

    run mkdir -p "${SOS_VAULT_DIR}/models"
    run chown "${APP_UID}:${APP_GID}" "${SOS_VAULT_DIR}/models"
}

# ---------------------------------------------------------------------------
# Step 11 — systemd unit
# ---------------------------------------------------------------------------
step_11_install_systemd_unit() {
    log 'Step 11/15 — installing systemd units (sos-vault + svaultKey)'

    local app_src="${SOS_VAULT_DIR}/sysadmin/sos-vault.service"
    local app_dst='/etc/systemd/system/sos-vault.service'
    local key_src="${SOS_VAULT_DIR}/sysadmin/svaultKey.service"
    local key_dst='/etc/systemd/system/svaultKey.service'

    if [[ ! -f "$app_src" ]]; then
        if [[ "$DRY_RUN" -eq 1 ]]; then
            warn "  $app_src missing on dev host — would install in production."
            return
        fi
        die "missing systemd unit: $app_src"
    fi

    run cp "$app_src" "$app_dst"
    if [[ -f "$key_src" ]]; then
        run cp "$key_src" "$key_dst"
        # The shipped unit defaults to User=/Group=1000; pin it to the real app
        # uid/gid (systemd parses User= before env expansion, so it cannot read
        # APP_UID from /etc/default — we rewrite the literal here).
        if [[ "$DRY_RUN" -eq 1 ]]; then
            printf '   [dry-run] sed svaultKey.service User=/Group= -> %s/%s\n' "$APP_UID" "$APP_GID"
        else
            sed -i -e "s/^User=.*/User=${APP_UID}/" -e "s/^Group=.*/Group=${APP_GID}/" "$key_dst"
        fi
    else
        warn "  $key_src missing — reboot-survival key service NOT installed."
    fi

    # /etc/default/sos-vault is read by both units: SOS_VAULT_DIR for the app
    # stack, SVAULT_KEYDIR for the key service, APP_UID/APP_GID for reference.
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] write /etc/default/sos-vault (SOS_VAULT_DIR=%s, SVAULT_KEYDIR=%s, APP_UID=%s)\n' \
            "$SOS_VAULT_DIR" "$SVAULT_KEYDIR" "$APP_UID"
    else
        {
            printf 'SOS_VAULT_DIR=%s\n' "$SOS_VAULT_DIR"
            printf 'SVAULT_KEYDIR=%s\n' "$SVAULT_KEYDIR"
            printf 'APP_UID=%s\n' "$APP_UID"
            printf 'APP_GID=%s\n' "$APP_GID"
        } > /etc/default/sos-vault || warn '  could not write /etc/default/sos-vault'
    fi

    run systemctl daemon-reload
    # svaultKey is ordered After=/PartOf=sos-vault.service so it repopulates the
    # @u keyring once the container is up to pin it — on every reboot and every
    # `systemctl restart sos-vault` (see svaultKey.service). Only `enable` it here
    # (not `--now`): at install time the keyring is loaded explicitly in Step 13,
    # after the container is up to hold the @u user keyring open (see the note there).
    if [[ -f "$key_src" ]]; then
        run systemctl enable svaultKey.service
    fi
    run systemctl enable --now sos-vault.service
}

# ---------------------------------------------------------------------------
# install_sudoers_fragments — drops the privileged-helper sudoers fragments
# ---------------------------------------------------------------------------
install_one_sudoers() {
    local name="$1"
    local src="${SOS_VAULT_DIR}/sysadmin/sudoers.d/${name}"
    local dst="/etc/sudoers.d/${name}"
    if [[ ! -f "$src" ]]; then
        warn "    $src missing — skipping"
        return
    fi
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] install -m 0440 -o root -g root %s %s (principal -> #%s)\n' \
            "$src" "$dst" "$APP_UID"
        return
    fi
    # Stage a copy with the app uid substituted for the shipped "#1000"
    # principal (no-op for fragments that grant www-data), and the shipped
    # default key dir rewritten to the provisioned SVAULT_KEYDIR (no-op for
    # fragments that don't reference it — only sos-vault-svaultkey does).
    # sudo-rs forbids wildcards in command args, so these paths are literal
    # and must be templated to the real install location here. Validate, install.
    local staged keydir
    keydir="${SVAULT_KEYDIR:-/var/lib/sos-vault/svaultkey}"
    staged="$(mktemp)"
    sed -e "s/^#1000 /#${APP_UID} /" \
        -e "s|/var/lib/sos-vault/svaultkey|${keydir}|g" \
        "$src" > "$staged"
    # visudo -cf returns non-zero on parse error; bail before clobbering.
    visudo -cf "$staged" >/dev/null \
        || { rm -f "$staged"; die "    $name does not parse after uid templating — refusing to install"; }
    install -m 0440 -o root -g root "$staged" "$dst"
    rm -f "$staged"
}

install_sudoers_fragments() {
    log '  installing sudoers fragments'
    local name
    # cert-helper no longer needs sudo (it writes the app-owned, bind-mounted
    # ssl / ca-certificates dirs directly), so the old sos-vault-cert fragment is
    # gone. The uninstaller / postrm still rm any stragglers from older installs.
    for name in sos-vault-machine-token sos-vault-svaultkey; do
        install_one_sudoers "$name"
    done
}

# ---------------------------------------------------------------------------
# Step 12 — host firewall
#
# The appliance publishes host ports 80 and ${SOS_VAULT_DEFAULT_PORT} (mapped to
# the nginx container's :443) — so THOSE, not 443, are what must be open. RHEL/
# Alma/Rocky ship firewalld (active by default, default-deny); Ubuntu ships ufw.
# Docker's published-port rules largely bypass both firewalls, but we open the
# ports explicitly so a hardened host with default-deny still serves the UI.
# ---------------------------------------------------------------------------
step_12_configure_firewall() {
    local https_port="${SOS_VAULT_DEFAULT_PORT}"
    log "Step 12/15 — configuring host firewall (allow 80, ${https_port})"

    if command -v firewall-cmd >/dev/null 2>&1 \
        && systemctl is-active --quiet firewalld 2>/dev/null; then
        run firewall-cmd --permanent --add-port=80/tcp
        run firewall-cmd --permanent --add-port="${https_port}/tcp"
        run firewall-cmd --reload
        return
    fi

    if command -v ufw >/dev/null 2>&1; then
        # Allow SSH FIRST so `ufw --force enable` can never lock out an operator
        # working over ssh (the old code opened only 80/443 then force-enabled).
        run ufw allow 22/tcp
        run ufw allow 80/tcp
        run ufw allow "${https_port}/tcp"
        run ufw --force enable
        return
    fi

    warn "  no active firewalld or ufw found — open 80 + ${https_port}/tcp manually if a host firewall is enabled."
}

# wait_for_app — block until the app compose service has a container that
# accepts an `exec`, so steps 13-15 don't race the asynchronous stack start
# (Step 11 fires `systemctl enable --now`, which returns before the container
# is ready). Args: the compose command array (docker compose -f <file>).
wait_for_app() {
    [[ "$DRY_RUN" -eq 1 ]] && return 0
    local i
    for ((i = 0; i < 60; i++)); do
        if "$@" exec -T "$SOS_VAULT_APP_SERVICE" true >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done
    warn "  app service '${SOS_VAULT_APP_SERVICE}' not ready after 120s — exec steps may fail"
    return 1
}

# wait_for_app_uid — block until a process owned by $APP_UID (www-data, remapped
# to the provisioned uid) is actually alive INSIDE the container. wait_for_app
# only proves the container accepts an `exec`, and that exec runs as root — it
# does NOT prove any $APP_UID process exists yet. The svault* keys are added to
# that uid's @u user keyring, which the kernel garbage-collects the instant no
# process of the uid is alive; so if svaultKey's oneshot runs before php-fpm's
# www-data workers are up, the keys are GC'd the moment it exits and the app
# comes up to an empty keyring (observed on AlmaLinux, where php-fpm started
# slower than on Ubuntu). Gate the keyring load on the exact condition we know
# pins @u: a live $APP_UID process. Uses only shell builtins so it needs no
# procps/pgrep in the image. Args: the compose command array.
wait_for_app_uid() {
    [[ "$DRY_RUN" -eq 1 ]] && return 0
    local i
    for ((i = 0; i < 60; i++)); do
        if "$@" exec -T "$SOS_VAULT_APP_SERVICE" sh -c '
            for s in /proc/[0-9]*/status; do
                while IFS= read -r line; do
                    case $line in
                        Uid:*) set -- $line; [ "$2" = "'"$APP_UID"'" ] && exit 0; break;;
                    esac
                done < "$s" 2>/dev/null
            done
            exit 1
        ' >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done
    warn "  no ${APP_UID}-owned process in the app container after 120s — keyring may GC on load; 'systemctl restart svaultKey' after install will reload it"
    return 1
}

# ---------------------------------------------------------------------------
# Step 13 — migrate + appliance seeder
# ---------------------------------------------------------------------------
step_13_migrate_and_seed() {
    log 'Step 13/15 — running migrate + ApplianceAdminSeeder'

    install_sudoers_fragments

    # The live sqlite DB lives under storage/app/db (bind-mounted from the host;
    # DB_DATABASE in .env points here) so it persists across image pulls — the
    # baked database/ dir holds only migrations, and is NOT on the host. Create
    # an empty DB (0 bytes IS a valid empty sqlite) so `migrate --force` builds a
    # clean schema and the seeder makes the admin the sole user. ensure_storage_dir
    # (Step 8) already seeds it; this repeats it so a resumed run that jumps
    # straight to migrate still has a valid, app-owned DB file. Owned by the app
    # uid so the container (www-data remapped to APP_UID) can write it.
    local sqlite_db="${SOS_VAULT_DIR}/storage/app/db/database.sqlite"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] create empty %s (chown %s:%s) if missing\n' "$sqlite_db" "$APP_UID" "$APP_GID"
    elif [[ ! -f "$sqlite_db" ]]; then
        mkdir -p "$(dirname "$sqlite_db")"
        : > "$sqlite_db"
        chmod 664 "$sqlite_db" 2>/dev/null || true
        chown "${APP_UID}:${APP_GID}" "$sqlite_db" 2>/dev/null || true
    fi

    local compose=("docker" "compose" "-f" "${SOS_VAULT_DIR}/docker-compose.yml")

    # Step 11's `systemctl enable --now sos-vault.service` brings the stack up
    # asynchronously; block until the app container actually accepts an exec so
    # migrate/seed don't race a still-starting container.
    wait_for_app "${compose[@]}"

    # Populate the svault* keyring NOW — the container is up. Step 11 only
    # `enable`d svaultKey.service (for reboots); it does not run at install time,
    # and sos-vault.service does not pull it in, so without this the app comes up
    # with an empty keyring and migrate/seed log "No svault0 key found". It must
    # run AFTER the container so a live app-uid process holds the @u user keyring
    # open: the service adds svault0..3 to @u (KeyringMode=shared, User=$APP_UID),
    # and the container — same uid (www-data remapped to $APP_UID), no userns —
    # shares that keyring. Run it here rather than in Step 11 because before the
    # container exists nothing pins @u and the keys are garbage-collected when the
    # oneshot exits. The unit's User= is already the provisioned uid (Step 11 seds
    # it), so this is uid-agnostic. execStart.sh resolves the passphrase via the
    # escrow policy, so it runs non-interactively. A failure here is non-fatal —
    # migrate will surface the missing key loudly if the keyring truly did not load.
    #
    # wait_for_app above only proves the container accepts an exec (as root); the
    # keys need a live $APP_UID process to pin @u across the oneshot's exit. On a
    # slower box (AlmaLinux) php-fpm's www-data workers can still be starting at
    # this point, so the oneshot's keys get GC'd. Block until an $APP_UID process
    # is actually alive before loading.
    wait_for_app_uid "${compose[@]}"

    if [[ -f /etc/systemd/system/svaultKey.service ]]; then
        run systemctl start svaultKey.service \
            || warn '  svaultKey.service did not load the keyring — migrate/seed may fail; check the escrow policy'
    else
        warn '  svaultKey.service not installed — skipping keyring load; migrate/seed may fail'
    fi

    run "${compose[@]}" exec -T "$SOS_VAULT_APP_SERVICE" \
        sudo -u www-data php artisan migrate --force

    # Pass admin creds via -e so they leave no trace in shell history. The exec
    # runs as the container's root, which then drops to www-data via sudo — but
    # `Defaults env_reset` (docker-compose/etc/sudoers) strips the -e vars, so
    # --preserve-env is required for the seeder's getenv() to see them.
    run "${compose[@]}" exec -T \
        -e "INSTALLER_ADMIN_NAME=$ADMIN_NAME" \
        -e "INSTALLER_ADMIN_EMAIL=$ADMIN_EMAIL" \
        -e "INSTALLER_ADMIN_PASSWORD=$ADMIN_PASSWORD" \
        "$SOS_VAULT_APP_SERVICE" \
        sudo -u www-data \
        --preserve-env=INSTALLER_ADMIN_NAME,INSTALLER_ADMIN_EMAIL,INSTALLER_ADMIN_PASSWORD \
        php artisan db:seed --force \
        --class='Database\Seeders\ApplianceAdminSeeder'

    stamp_app_version "${compose[@]}"
}

# ---------------------------------------------------------------------------
# stamp_app_version — write the installed package version into site.app_version
# ---------------------------------------------------------------------------
# The settings migration seeds site.app_version with a static placeholder, so
# the app (e.g. the wave-info-widget "v{{ setting('site.app_version') }}")
# would otherwise show a stale version. Read the version from the installed
# package (deb or rpm) and write it straight into the settings table.
#
# The app image intentionally ships without tinker or the sqlite3 CLI, so the
# row is written directly through PHP's PDO driver (pdo_sqlite is present — the
# app runs on sqlite) and the cached copy is dropped via the core cache:forget
# command. This needs NO application code change: bumping the version only
# requires rebuilding the .deb/.rpm, never the container image.
stamp_app_version() {
    local compose=("$@")

    local app_version
    app_version="$(dpkg-query -W -f='${Version}' sos-vault 2>/dev/null \
        || rpm -q --qf '%{VERSION}' sos-vault 2>/dev/null \
        || true)"
    app_version="${app_version#v}"                          # tolerate a leading v
    app_version="$(printf '%s' "$app_version" | tr -cd '0-9A-Za-z.-')"  # sanitize

    if [[ -z "$app_version" ]]; then
        warn '  could not determine installed package version — leaving site.app_version at its seeded default'
        return
    fi

    # DB_DATABASE inside the container (see the .env this installer writes).
    local db_path='/var/www/site/storage/app/db/database.sqlite'
    # app_version is sanitized above, so it is safe to inline into the SQL.
    local php_code="\$db=new PDO('sqlite:${db_path}'); \$db->exec(\"UPDATE settings SET value='${app_version}' WHERE key='site.app_version'\");"

    log "  stamping site.app_version = ${app_version}"
    if run "${compose[@]}" exec -T "$SOS_VAULT_APP_SERVICE" \
        sudo -u www-data php -r "$php_code"; then
        run "${compose[@]}" exec -T "$SOS_VAULT_APP_SERVICE" \
            sudo -u www-data php artisan cache:forget wave_settings \
            || warn '  could not clear the wave_settings cache — new version shows after the next cache flush'
    else
        warn '  stamping site.app_version failed — set it later on the Manage Settings page'
    fi
}

# ---------------------------------------------------------------------------
# Step 13b — capture the host hardware fingerprint into encrypted settings
# ---------------------------------------------------------------------------
# Licensing binds to a set of host identifiers. We gather them HERE on the host
# (root, full dmidecode access) — the app container cannot read host DMI
# reliably — and hand them to an artisan command that stores them encrypted
# (svault0 key) in the settings table. The admin "Generate License Request"
# action and the .lic install gate both read this stored fingerprint, so no
# single identifier (e.g. /etc/machine-id, which some systems lack) is mandatory.
#
# Runs AFTER migrate+seed (settings table exists) and after the keyring is up
# (Step 11), so the svault0 key is available to encrypt. Tolerant: a host that
# yields no usable identifier only logs a warning — the operator can re-run
# `php artisan sos-vault:store-machine-fingerprint` later.
fp_clean() {
    # Echo the value unless it is empty or a known DMI placeholder.
    local v norm
    v="$(printf '%s' "$1" | head -n1)"
    norm="$(printf '%s' "$v" | tr '[:upper:]' '[:lower:]' | tr -d '[:space:]')"
    case "$norm" in
        ''|'tobefilledbyo.e.m.'|'notspecified'|'none'|'systemserialnumber'|'defaultstring'|'00000000-0000-0000-0000-000000000000') return 0 ;;
    esac
    printf '%s' "$v"
}

step_13b_capture_fingerprint() {
    log 'Step 13b/15 — capturing host hardware fingerprint'

    local machine_id='' dmi_uuid='' board_serial='' system_serial=''
    [[ -r /etc/machine-id ]] && machine_id="$(fp_clean "$(cat /etc/machine-id 2>/dev/null)")"
    if command -v dmidecode >/dev/null 2>&1; then
        dmi_uuid="$(fp_clean "$(dmidecode -s system-uuid 2>/dev/null)")"
        board_serial="$(fp_clean "$(dmidecode -s baseboard-serial-number 2>/dev/null)")"
        system_serial="$(fp_clean "$(dmidecode -s system-serial-number 2>/dev/null)")"
    else
        warn '  dmidecode not installed — capturing /etc/machine-id only'
    fi

    if [[ -z "$machine_id$dmi_uuid$board_serial$system_serial" ]]; then
        warn '  no usable host identifiers found — skipping fingerprint (run sos-vault:store-machine-fingerprint later)'
        return
    fi

    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] store fingerprint (machine-id:%s dmi-uuid:%s board:%s system:%s)\n' \
            "${machine_id:+set}" "${dmi_uuid:+set}" "${board_serial:+set}" "${system_serial:+set}"
        return
    fi

    local compose=("docker" "compose" "-f" "${SOS_VAULT_DIR}/docker-compose.yml")
    run "${compose[@]}" exec -T \
        -e "INSTALLER_FP_MACHINE_ID=$machine_id" \
        -e "INSTALLER_FP_DMI_UUID=$dmi_uuid" \
        -e "INSTALLER_FP_BOARD_SERIAL=$board_serial" \
        -e "INSTALLER_FP_SYSTEM_SERIAL=$system_serial" \
        "$SOS_VAULT_APP_SERVICE" \
        sudo -u www-data \
        --preserve-env=INSTALLER_FP_MACHINE_ID,INSTALLER_FP_DMI_UUID,INSTALLER_FP_BOARD_SERIAL,INSTALLER_FP_SYSTEM_SERIAL \
        php artisan sos-vault:store-machine-fingerprint \
        || warn '  store-machine-fingerprint exited non-zero — see logs'
}

# ---------------------------------------------------------------------------
# Step 14 — ensure plain vault directory
# ---------------------------------------------------------------------------
# sos-vault stores its vaults in a plain directory (default /vault, or
# whatever appliance.vault_dir is set to). The artisan command is a no-op
# when the directory already exists, so running it on every install — even
# after a license is later uploaded — is safe.
step_14_ensure_plain_vault() {
    log 'Step 14/15 — ensuring plain vault directory exists'

    local compose=("docker" "compose" "-f" "${SOS_VAULT_DIR}/docker-compose.yml")
    run "${compose[@]}" exec -T "$SOS_VAULT_APP_SERVICE" \
        sudo -u www-data php artisan sos-vault:ensure-plain-vault \
        || warn '  ensure-plain-vault exited non-zero — see logs'
}

# ---------------------------------------------------------------------------
# Step 15 — success
# ---------------------------------------------------------------------------
step_15_print_success() {
    log 'Step 15/15 — install complete'

    # Resolve the primary LAN IP so the operator gets a clickable URL rather
    # than "<this-host>". Falls back to the hostname if no IP is detectable.
    local ip port
    port="${SOS_VAULT_DEFAULT_PORT}"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        ip='<this-host>'
    else
        ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
        [[ -z "$ip" ]] && ip="$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo localhost)"
    fi

    cat <<EOF

================================================================
  sos-vault appliance is up.

  Sign in at:        https://${ip}:${port}/admin
  Admin email:       ${ADMIN_EMAIL:-<not set>}
  Vault directory:   ${SOS_VAULT_VAULT_DIR}

  RECOMMENDED: reboot before your first sign-in. First login
  provisions the admin's encrypted LUKS vault, which needs the
  loop / device-mapper kernel modules fully live — a fresh reboot
  guarantees that. (If a first login is ever interrupted, the next
  one now self-heals the half-provisioned vault automatically.)

  Next: upload your .lic file from the admin "License" page.
  Open-core baseline allows one admin only; uploading a license
  unlocks multi-user, groups, modules, ITSM, encrypted vaults,
  and the event log.

  AI assistant: the ~1.1 GB bot LLM model is NOT installed yet.
  To enable the in-app assistant, sign in and download it from
  the admin "Software Updates" page (Settings → Software Updates).
  The download runs in the background; the assistant becomes
  available once it finishes.
================================================================

  If a bad TLS certificate ever breaks HTTPS access (the admin UI
  becomes unreachable), restore a working self-signed certificate
  and restart with:

      sudo ${SOS_VAULT_DIR}/sysadmin/reset-tls-cert.sh

  Installation complete. Access the sos-vault web interface at:

      https://${ip}:${port}/
EOF
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        --help|-h) usage; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
    require_root

    printf '\033[1;36m'
    cat <<'BANNER'
════════════════════════════════════════════════════════════════════════════
  sos-vault installer

  Safe to re-run. This installer is idempotent: if it stops or fails at any
  stage — a dropped download, a transient error, or an interrupted run — just
  run it again. It detects the work already completed and continues from where
  it left off instead of starting over. Your previous answers (admin account,
  keyring passphrase, storage policy) are remembered, so a resume won't
  re-prompt, and re-running a finished install does no harm.
════════════════════════════════════════════════════════════════════════════
BANNER
    printf '\033[0m\n'

    log "sos-vault installer (DRY_RUN=${DRY_RUN})"

    # Repopulate any answers cached by a prior, failed run so a resume does not
    # re-prompt (notably the passphrase — step 6 skips re-init when the key
    # device already exists, so a re-typed passphrase would just be discarded).
    load_answer_cache

    step_01_check_os
    step_02_check_hardware
    step_02b_provision_app_user
    step_03_install_docker
    install_prereq_packages
    step_04_prompt_admin_credentials
    step_05_prompt_gpg_passphrase
    step_05b_choose_keystore_policy
    # All interactive prompts are now collected — cache them for a resume.
    save_answer_cache
    step_06_init_gpg_keyring
    step_07_pull_images
    step_07b_write_env_file
    step_08_compose_up
    step_09_generate_self_signed_cert
    step_10_prepare_model_dir
    step_11_install_systemd_unit
    step_12_configure_firewall
    step_13_migrate_and_seed
    step_13b_capture_fingerprint
    step_14_ensure_plain_vault
    step_15_print_success
    # Full success — wipe the cached admin password + passphrase.
    clear_answer_cache
}

main "$@"
