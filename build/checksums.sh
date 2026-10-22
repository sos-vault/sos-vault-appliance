#!/bin/bash
# build/checksums.sh — Sprint 6 Step E.
#
# Generates SHA256SUMS over every shippable artifact under dist/.
# Called by build.sh step 9. Re-runnable; overwrites SHA256SUMS each time.

set -euo pipefail

DIST_DIR="${BUILD_DIST_DIR:-$(cd "$(dirname "$0")/.." && pwd)/dist}"

if [[ ! -d "$DIST_DIR" ]]; then
    echo "[checksums] $DIST_DIR does not exist — nothing to checksum." >&2
    exit 0
fi

cd "$DIST_DIR"

# Collect *.deb, *.rpm, and any docker-images/*.tar.gz blobs.
mapfile -t artifacts < <(find . -maxdepth 2 -type f \( -name '*.deb' -o -name '*.rpm' -o -name '*.tar.gz' \) | sort)

if [[ "${#artifacts[@]}" -eq 0 ]]; then
    echo '[checksums] no artifacts found under dist/.' >&2
    : > SHA256SUMS
    exit 0
fi

sha256sum "${artifacts[@]}" > SHA256SUMS
echo '[checksums] wrote SHA256SUMS:'
cat SHA256SUMS
