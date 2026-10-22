#!/bin/bash
# reset-tls-cert.sh — recover the appliance to a working self-signed TLS cert.
#
# Dealing with certificates trips people up: upload a mismatched key, a cert
# missing its chain, or a wrong-format file and nginx refuses to start — which
# also takes down the admin UI, so the in-app "Regenerate self-signed
# certificate" button can no longer help. This host-side script is the escape
# hatch: it backs up whatever is there, mints a fresh self-signed pair
# (CN=sos-vault.local) the same way the installer does, fixes ownership, and
# restarts the stack so nginx comes back up over TLS.
#
# Usage (run as root on the appliance host):
#   sudo /opt/sos-vault/sysadmin/reset-tls-cert.sh           # regenerate + restart
#   sudo /opt/sos-vault/sysadmin/reset-tls-cert.sh --yes     # no confirmation prompt
#   sudo /opt/sos-vault/sysadmin/reset-tls-cert.sh --no-restart
#   sudo /opt/sos-vault/sysadmin/reset-tls-cert.sh --dry-run
#
# Honours SOS_VAULT_DIR (default /opt/sos-vault), also read from
# /etc/default/sos-vault, and SOS_VAULT_SSL_DIR for non-default layouts.

set -euo pipefail

# Pick up SOS_VAULT_DIR from the boot env file when present (mirrors the unit).
if [[ -z "${SOS_VAULT_DIR:-}" && -r /etc/default/sos-vault ]]; then
    # shellcheck disable=SC1091
    . /etc/default/sos-vault
fi
SOS_VAULT_DIR="${SOS_VAULT_DIR:-/opt/sos-vault}"
SSL_DIR="${SOS_VAULT_SSL_DIR:-${SOS_VAULT_DIR}/docker-compose/nginx/ssl/sos-vault.com}"

DRY_RUN=0
ASSUME_YES=0
RESTART=1

usage() {
    cat <<EOF
usage: reset-tls-cert.sh [--yes] [--no-restart] [--dry-run] [--help]

Regenerate the self-signed TLS certificate and restart the appliance so nginx
comes back up. Use when a faulty uploaded certificate has broken TLS / the UI.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -y|--yes) ASSUME_YES=1 ;;
        --no-restart) RESTART=0 ;;
        --dry-run) DRY_RUN=1 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

run() {
    if [[ "$DRY_RUN" == 1 ]]; then
        printf '   [dry-run] %s\n' "$*"
    else
        "$@"
    fi
}

if [[ "$DRY_RUN" != 1 && "$(id -u)" != 0 ]]; then
    echo "reset-tls-cert.sh must run as root (it rewrites cert files and restarts the stack)." >&2
    exit 1
fi

if ! command -v openssl >/dev/null 2>&1; then
    echo "openssl not found — cannot regenerate the certificate." >&2
    exit 1
fi

echo "This will overwrite the current TLS certificate at:"
echo "    ${SSL_DIR}/{fullchain,privkey}.pem"
echo "with a fresh self-signed pair (CN=sos-vault.local), and restart sos-vault."
if [[ "$ASSUME_YES" != 1 && "$DRY_RUN" != 1 ]]; then
    read -r -p "Proceed? [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]] || { echo "aborted."; exit 0; }
fi

run mkdir -p "$SSL_DIR"

# Back up whatever is there (likely the faulty cert) before overwriting, so the
# operator can inspect it later. Timestamped, never clobbered.
stamp="$(date +%Y%m%d-%H%M%S)"
for f in fullchain.pem privkey.pem; do
    if [[ -e "${SSL_DIR}/${f}" ]]; then
        run cp -a "${SSL_DIR}/${f}" "${SSL_DIR}/${f}.bak-${stamp}"
    fi
done

echo "Generating self-signed certificate..."
run openssl req -x509 -newkey rsa:2048 -nodes -days 365 \
    -keyout "${SSL_DIR}/privkey.pem" \
    -out "${SSL_DIR}/fullchain.pem" \
    -subj '/CN=sos-vault.local'
run chmod 644 "${SSL_DIR}/fullchain.pem"
run chmod 600 "${SSL_DIR}/privkey.pem"

# Match the install dir's owner (the app uid) so the container + the
# CertificateManager page can read/replace the files later.
owner="$(stat -c '%u:%g' "$SOS_VAULT_DIR" 2>/dev/null || echo '')"
if [[ -n "$owner" ]]; then
    run chown -R "$owner" "$SSL_DIR"
fi

if [[ "$RESTART" == 1 ]]; then
    echo "Restarting sos-vault..."
    if command -v systemctl >/dev/null 2>&1 \
        && systemctl list-unit-files 2>/dev/null | grep -q '^sos-vault\.service'; then
        run systemctl restart sos-vault
    elif command -v docker >/dev/null 2>&1; then
        run docker compose -f "${SOS_VAULT_DIR}/docker-compose.yml" up -d --remove-orphans
    else
        echo "Could not find systemctl or docker to restart — restart the stack manually." >&2
    fi
else
    echo "Skipping restart (--no-restart). Apply with: sudo systemctl restart sos-vault"
fi

echo "Done. The appliance should now serve TLS with a self-signed certificate."
