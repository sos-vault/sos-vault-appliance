#!/bin/bash
# resolve-passphrase.sh — emit the svault LUKS passphrase for unattended boot.
#
# There is exactly one secret that matters on an appliance: the LUKS
# passphrase for the svault key device (see init.sh / execStart.sh). To
# survive a reboot without a human, that passphrase is escrowed at install
# time (installer.sh step 6) under one of three policies and recovered here
# at boot. The same operator-typed passphrase is escrowed — never a second
# LUKS key — so it always remains a valid manual recovery passphrase.
#
# This script reads $KEYDIR/policy, dispatches to the named backend, and
# prints the passphrase to STDOUT (nothing else goes to stdout). On any
# failure it prints a diagnostic to STDERR and exits non-zero, so the caller
# (execStart.sh) can fall back to an interactive prompt.
#
#   tpm      — passphrase sealed to TPM 2.0 (no PCR policy). Disk theft alone
#              yields nothing; local root can unseal (threat model is theft).
#   relaxed  — AES-256 ciphertext on disk, key derived from machine identity
#              (/etc/machine-id + DMI product_uuid). Obfuscation, not security:
#              full-disk theft defeats it. The default when no TPM is present.
#   vault    — fetched from a HashiCorp Vault over the network using a
#              least-privilege AppRole. Secret lives off-box; Vault auth creds
#              still live on-box (vault.conf, 0600).
#
# Policy/blob paths are all relative to KEYDIR (see installer.sh).

set -euo pipefail

# KEYDIR is provided by the boot service via /etc/default/sos-vault. When run
# by hand (no EnvironmentFile), pick it up from that same file so the operator
# need not export SVAULT_KEYDIR; fall back to the legacy per-user location last.
if [[ -z "${SVAULT_KEYDIR:-}" && -r /etc/default/sos-vault ]]; then
    # shellcheck disable=SC1091
    . /etc/default/sos-vault
fi
KEYDIR="${SVAULT_KEYDIR:-$HOME/.config/svaultKey}"
POLICY_FILE="${KEYDIR}/policy"

err() { printf 'resolve-passphrase: %s\n' "$*" >&2; }

[[ -r "$POLICY_FILE" ]] || { err "no policy file at ${POLICY_FILE}"; exit 1; }

# shellcheck disable=SC1090
source "$POLICY_FILE"

resolve_tpm() {
    command -v tpm2_load   >/dev/null 2>&1 || { err 'tpm2-tools not installed'; return 1; }
    command -v tpm2_unseal >/dev/null 2>&1 || { err 'tpm2-tools not installed'; return 1; }

    local pub="${KEYDIR}/${TPM_PUB:?TPM_PUB unset in policy}"
    local priv="${KEYDIR}/${TPM_PRIV:?TPM_PRIV unset in policy}"
    local handle="${TPM_PRIMARY_HANDLE:?TPM_PRIMARY_HANDLE unset in policy}"

    [[ -r "$pub" && -r "$priv" ]] || { err "sealed blobs missing under ${KEYDIR}"; return 1; }

    local ctx
    ctx="$(mktemp)"
    # No PCR policy was set at seal time, so unseal needs no authorization.
    if ! tpm2_load -C "$handle" -u "$pub" -r "$priv" -c "$ctx" >/dev/null 2>&1; then
        rm -f "$ctx"; err 'tpm2_load failed (TPM cleared or different chip?)'; return 1
    fi
    if ! tpm2_unseal -c "$ctx"; then
        rm -f "$ctx"; err 'tpm2_unseal failed'; return 1
    fi
    rm -f "$ctx"
}

# machine_keymat — stable per-host key material for the relaxed backend.
# Mirrors the derivation used at escrow time in installer.sh. We use ONLY
# /etc/machine-id: it is world-readable (0444), so the value is identical
# whether read by root at escrow time or by UID 1000 at boot. (DMI fields like
# product_uuid are root-only, so they would mismatch between escrow and boot.)
# machine-id is per-install and differs on another host, so a copied .enc is
# not portable — but it lives on disk, hence "obfuscation, not theft-proof".
machine_keymat() {
    [[ -r /etc/machine-id ]] || { err 'no readable /etc/machine-id to derive key from'; return 1; }
    local mid
    mid="$(cat /etc/machine-id)"
    [[ -n "$mid" ]] || { err '/etc/machine-id is empty'; return 1; }
    printf 'machine-id:%s' "$mid"
}

resolve_relaxed() {
    local enc="${KEYDIR}/${ENC_FILE:?ENC_FILE unset in policy}"
    [[ -r "$enc" ]] || { err "ciphertext missing at ${enc}"; return 1; }

    local keymat
    keymat="$(machine_keymat)" || return 1

    # -pass stdin reads the derive material from stdin; the passphrase itself
    # is the decrypted file content, written to stdout.
    if ! printf '%s' "$keymat" \
        | openssl enc -d -aes-256-cbc -pbkdf2 -salt -in "$enc" -pass stdin; then
        err 'openssl decrypt failed (machine identity changed?)'; return 1
    fi
}

resolve_vault() {
    command -v vault >/dev/null 2>&1 || { err 'vault CLI not installed'; return 1; }

    local addr="${VAULT_ADDR:?VAULT_ADDR unset in policy}"
    local secret_path="${VAULT_SECRET_PATH:?VAULT_SECRET_PATH unset in policy}"
    local field="${VAULT_FIELD:-passphrase}"
    local role_id="${VAULT_ROLE_ID:?VAULT_ROLE_ID unset in policy}"
    local creds="${KEYDIR}/${VAULT_CREDS_FILE:?VAULT_CREDS_FILE unset in policy}"

    [[ -r "$creds" ]] || { err "vault creds missing at ${creds}"; return 1; }
    # creds file defines VAULT_SECRET_ID (0600, root/uid-1000 only).
    # shellcheck disable=SC1090
    source "$creds"
    : "${VAULT_SECRET_ID:?VAULT_SECRET_ID unset in vault creds file}"

    export VAULT_ADDR="$addr"
    local token
    if ! token="$(vault write -field=token auth/approle/login \
            role_id="$role_id" secret_id="$VAULT_SECRET_ID" 2>/dev/null)"; then
        err 'vault AppRole login failed'; return 1
    fi
    if ! VAULT_TOKEN="$token" vault kv get -field="$field" "$secret_path" 2>/dev/null; then
        err "vault kv get ${secret_path} failed"; return 1
    fi
}

case "${POLICY:-}" in
    tpm)     resolve_tpm ;;
    relaxed) resolve_relaxed ;;
    vault)   resolve_vault ;;
    *)       err "unknown or empty POLICY '${POLICY:-}' in ${POLICY_FILE}"; exit 1 ;;
esac
