#!/bin/bash
# execStart.sh — populate the svault* kernel keyring at boot.
#
# Run by svaultKey.service (a system oneshot, User=1000) before the app stack
# starts. It opens the LUKS svault key device and runs the inner script.sh,
# which loads svault0..3 into UID 1000's @u keyring — the keyring the app
# reads via `keyctl show @u` (see app/Helpers/sosVaultHelper.php::getSvaultKey).
#
# The LUKS passphrase is recovered without a human by resolve-passphrase.sh
# according to the escrow policy chosen at install (tpm / relaxed / vault).
# If that fails — no policy, TPM cleared, Vault unreachable, machine identity
# changed — we fall back to prompting interactively, so the operator-typed
# recovery passphrase always works.

set -uo pipefail
umask 077

here="$(CDPATH= cd -- "$(dirname "$0")" && pwd)"
# KEYDIR comes from the boot service (/etc/default/sos-vault). When run by hand
# (no EnvironmentFile), pick it up from that same file so the operator need not
# export it; fall back to the legacy per-user location last.
if [[ -z "${SVAULT_KEYDIR:-}" && -r /etc/default/sos-vault ]]; then
    # shellcheck disable=SC1091
    . /etc/default/sos-vault
fi
dir="${SVAULT_KEYDIR:-$HOME/.config/svaultKey}"
device="$dir/svault.key"
mountp="$dir/m"
# The transient key-file lives at a FIXED path under the (app-owned, 0700)
# key dir — NOT a random mktemp path. cryptsetup runs via sudo, and sudo-rs
# (Ubuntu 26.04) rejects wildcards in sudoers command arguments, so the
# sos-vault-svaultkey fragment must pin an exact --key-file= path. umask 077
# above keeps it 0600; the trap removes it on exit.
keyfile="$dir/.keyfile"

# Already loaded (e.g. service re-run without a reboot)? Nothing to do.
if /bin/grep -q svault0 /proc/keys 2>/dev/null; then
    exit 0
fi

trap '/bin/rm -f "$keyfile"' EXIT

# Try the unattended escrow first; prompt only if it cannot deliver.
if "$here/resolve-passphrase.sh" > "$keyfile" 2>/dev/null && [[ -s "$keyfile" ]]; then
    :
else
    read -r -s -p "passphrase: " REPLY
    echo ""
    n="$(printf '%s' "$REPLY" | /bin/wc -c)"
    if [[ "$n" -le 8 ]]; then echo "phrase is too short"; exit 1; fi
    printf '%s' "$REPLY" > "$keyfile"
    unset REPLY
fi

/bin/mkdir -p "$mountp"
/bin/sudo /sbin/cryptsetup -r --key-file="$keyfile" luksOpen "$device" svault || exit 1
/bin/sudo /bin/mount -o ro /dev/mapper/svault "$mountp" || { /bin/sudo /sbin/cryptsetup luksClose svault; exit 1; }

# Invoke through bash (needs only read perm on the ro mount) rather than
# exec'ing the file directly — robust against a missing exec bit or a noexec
# mount. It still runs as THIS process's uid, so keyctl adds to the right @u.
/bin/bash "$dir/m/script.sh"

/bin/sudo /bin/umount /dev/mapper/svault
/bin/sudo /sbin/cryptsetup luksClose svault
/bin/rmdir "$dir/m" 2>/dev/null || true
