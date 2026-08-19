#!/bin/bash
# build/publish-images.sh — publish the appliance app image to GHCR.
#
# The appliance pulls its containers from registries instead of building them
# on the customer host (see docker-compose.appliance.yml). redis and llama.cpp
# are upstream public images; the two that are ours are built + pushed here:
#
#     ghcr.io/sos-vault/app:<version>    (+ :latest)   PHP-FPM runtime WITH the
#                                                      application code baked in.
#     ghcr.io/sos-vault/nginx:<version>  (+ :latest)   nginx WITH the compiled
#                                                      public/ assets baked in
#                                                      (COPY --from the app image).
#
# Run at RELEASE time (alongside `git push origin v*`), NOT as part of the
# per-customer deb build. The application code (and its compiled front-end
# assets) is now BAKED into these images, so the deb ships no source and the
# customer host holds none. The nginx image is built FROM the app image, so its
# assets always match the app version.
#
# Usage:
#     ./publish-images.sh --version 2.0.0     # build + push :2.0.0 and :latest
#     ./publish-images.sh                     # version from nearest git tag
#     ./publish-images.sh --dry-run           # print every step, push nothing
#     ./publish-images.sh --no-latest         # skip the :latest tag
#     ./publish-images.sh --help
#
# Auth: `echo $GHCR_TOKEN | docker login ghcr.io -u <user> --password-stdin`
# before running (a PAT / Actions token with packages:write on the org).

set -euo pipefail

# CDPATH= prevents bash's `cd` from echoing the target dir (which would make
# the command substitution capture the path twice when the user has CDPATH set).
BUILD_ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
REGISTRY_IMAGE="${SOS_VAULT_REGISTRY_IMAGE:-ghcr.io/sos-vault/app}"
REGISTRY_NGINX_IMAGE="${SOS_VAULT_REGISTRY_NGINX_IMAGE:-ghcr.io/sos-vault/nginx}"

DRY_RUN=0
PUSH_LATEST=1
VERSION_OVERRIDE=""

log()  { printf '\033[1;34m[publish]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[publish]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[publish]\033[0m %s\n' "$*" >&2; exit 1; }

run() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] %s\n' "$*"
    else
        "$@"
    fi
}

# build_frontend_assets — run `vite build` so public/build/{manifest.json,assets}
# are a consistent set before the app image bakes them via `COPY .`. Requires
# node/npm on the build host (already needed to produce assets at all). Uses the
# existing node_modules; falls back to `npm ci` only if they are absent.
build_frontend_assets() {
    if ! command -v npm >/dev/null 2>&1; then
        die 'npm not on PATH — cannot regenerate public/build before baking (install node, or build assets first)'
    fi
    if [[ ! -d "${BUILD_ROOT}/node_modules" ]]; then
        log 'node_modules missing — running npm ci'
        run npm --prefix "${BUILD_ROOT}" ci
    fi
    log 'building front-end assets (vite build) so manifest + assets match'
    run npm --prefix "${BUILD_ROOT}" run build
}

usage() {
    cat <<'EOF'
sos-vault appliance image publisher (GHCR)

  Usage:
    ./publish-images.sh --version V   Build + push ghcr.io/sos-vault/app:V (+ :latest).
    ./publish-images.sh               Version from the nearest git tag.
    ./publish-images.sh --dry-run     Walk every step, push nothing.
    ./publish-images.sh --no-latest   Do not also tag/push :latest.
    ./publish-images.sh --help        This message.

  Auth before running:
    echo $GHCR_TOKEN | docker login ghcr.io -u <user> --password-stdin
EOF
}

resolve_version() {
    local version="${VERSION_OVERRIDE:-}"
    if [[ -z "$version" ]]; then
        version="$(git -C "${BUILD_ROOT}" describe --tags 2>/dev/null || true)"
        version="${version#v}"
        version="${version//-/+}"
    fi
    [[ "$version" =~ ^[0-9] ]] || die "could not resolve a numeric version (pass --version X.Y.Z)"
    echo "$version"
}

main() {
    command -v docker >/dev/null 2>&1 || die 'docker not on PATH'

    local version tag_version tag_latest
    version="$(resolve_version)"
    tag_version="${REGISTRY_IMAGE}:${version}"
    tag_latest="${REGISTRY_IMAGE}:latest"
    local nginx_version nginx_latest
    nginx_version="${REGISTRY_NGINX_IMAGE}:${version}"
    nginx_latest="${REGISTRY_NGINX_IMAGE}:latest"

    # --- front-end assets ----------------------------------------------------
    # Regenerate public/build here so the manifest and the hashed assets/ are
    # ALWAYS a matched pair at bake time. The Dockerfile bakes public/build via
    # `COPY .` (build.sh's npm step is a no-op) and public/build/manifest.json
    # is git-tracked while public/build/assets/ is gitignored — so a stray
    # branch switch / checkout can reset the tracked manifest to a stale hash
    # while the ignored assets stay put, baking a manifest that 404s against the
    # assets on disk. A fresh `vite build` immediately before `docker build`
    # rewrites both together and removes that drift regardless of git state.
    build_frontend_assets

    # --- app image -----------------------------------------------------------
    log "building ${tag_version} (generic build args for a public image)"
    # Build with a generic, non-personal user so the published image carries no
    # developer identity. uid 1000 matches the deb .env WWWUSER/WWWGROUP and the
    # installer's `sudo -u www-data` (the Dockerfile remaps www-data to 1000).
    run docker build \
        --build-arg user=sosvault \
        --build-arg uid=1000 \
        -f "${BUILD_ROOT}/Dockerfile" \
        -t "$tag_version" \
        "${BUILD_ROOT}"

    log "pushing ${tag_version}"
    run docker push "$tag_version"

    if [[ "$PUSH_LATEST" -eq 1 ]]; then
        log "tagging + pushing ${tag_latest}"
        run docker tag "$tag_version" "$tag_latest"
        run docker push "$tag_latest"
    fi

    # --- nginx image (FROM the app image, so assets match the app version) ----
    log "building ${nginx_version} (public/ assets baked from ${tag_version})"
    run docker build \
        --build-arg "APP_IMAGE=${tag_version}" \
        -f "${BUILD_ROOT}/docker-compose/nginx/Dockerfile" \
        -t "$nginx_version" \
        "${BUILD_ROOT}"

    log "pushing ${nginx_version}"
    run docker push "$nginx_version"

    if [[ "$PUSH_LATEST" -eq 1 ]]; then
        log "tagging + pushing ${nginx_latest}"
        run docker tag "$nginx_version" "$nginx_latest"
        run docker push "$nginx_latest"
    fi

    log "done — docker-compose.appliance.yml pins app + nginx to :${version} (build.sh seds both)"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        --no-latest) PUSH_LATEST=0 ;;
        --version) shift; VERSION_OVERRIDE="${1:-}" ;;
        --help|-h) usage; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

main
