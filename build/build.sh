#!/bin/bash
# build/build.sh — appliance package build orchestrator (Sprint 6 Step E).
#
# Master plan §7.3: 9-step pipeline that turns the `appliance` branch
# of this repo into a deb (and via alien, an rpm) ready to install on a
# customer host. SKELETON at this stage — heavy steps run real commands
# but several gate behind --dry-run for portability and CI checks.
#
# Usage:
#     ./build.sh                  # full pipeline
#     ./build.sh --dry-run        # print every step, mutate nothing
#     ./build.sh --version 1.2.3  # override the package version (defaults
#                                 # to git describe --tags --always)
#     ./build.sh --help

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------

# CDPATH= prevents bash's `cd` from echoing the target dir (which would make
# the command substitution capture the path twice when the user has CDPATH set).
BUILD_ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
DIST_DIR="${BUILD_DIST_DIR:-${BUILD_ROOT}/dist}"
STAGING_DIR="${DIST_DIR}/staging"
DEB_DIR="${DIST_DIR}/sos-vault-deb"

# scp target for publishing the .deb / .rpm / SHA256SUMS to the production site
# so the marketing landing + Customer Portal Download page serve the new build.
PROD_SCP_TARGET="${PROD_SCP_TARGET:-sos-vault.com.production:~/sos-vault/public/downloads}"

DRY_RUN=0
SKIP_PUBLISH_PROD=0
VERSION_OVERRIDE=""

# Default package version when the build is not tagged and --version is not
# given. The SaaS version lives in the settings table as 'site.app_version';
# its source of truth is the seed migration, so derive the default straight
# from there to keep the self-hosted appliance and SaaS in lock-step (no DB is
# available at build time to read the live setting).
#
# Pick the value from the NEWEST migration that sets the key: migration
# filenames are timestamp-prefixed, so a plain lexicographic sort is
# chronological — this stays correct even if several migrations bump the
# version over time (the latest one wins). Falls back to 2.0.0 if nothing
# parses. (For a real release, prefer `git tag vX.Y.Z` / --version, which
# override this entirely.)
DEFAULT_VERSION="$(
    mig="$(grep -rlE "'site\.app_version'" "${BUILD_ROOT}/database/migrations/" 2>/dev/null | LC_ALL=C sort | tail -n1 || true)"
    if [[ -n "$mig" ]]; then
        grep -oE "'site\.app_version'.*'value'[[:space:]]*=>[[:space:]]*'[0-9][^']*'" "$mig" 2>/dev/null \
            | grep -oE "'[0-9][^']*'$" | tr -d "'" | tail -n1 || true
    fi
    true
)"
DEFAULT_VERSION="${DEFAULT_VERSION:-2.0.0}"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

log()  { printf '\033[1;34m[build]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[build]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[build]\033[0m %s\n' "$*" >&2; exit 1; }

run() {
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] %s\n' "$*"
    else
        "$@"
    fi
}

# strip_shell_comments FILE — remove WHOLE-LINE comments from a shell script in
# place so the customer-host copy ships lean (the annotated source stays in the
# repo). Unlike the docker-compose.yml strip above, '#' is real shell syntax
# ($#, ${#v}, ${v##p}, case globs), so trailing/inline comments are LEFT ALONE —
# only lines that are entirely a comment are dropped. The #! shebang and here-doc
# bodies (a '#'-leading line inside a here-doc is data, not a comment) are
# preserved. The result is `bash -n` verified; a broken script aborts the build
# rather than ship a corrupt host script.
strip_shell_comments() {
    local file="$1" tmp="${1}.nc"
    awk '
        BEGIN { in_h = 0 }
        {
            if (in_h) {                       # inside a here-doc: pass through
                print
                t = $0; sub(/^[[:space:]]+/, "", t)
                if ($0 == delim || t == delim) in_h = 0
                next
            }
            # here-doc start: << optional "-", optional quote/backslash, WORD
            if (match($0, /<<-?[[:space:]]*[^[:alnum:][:space:]]*[A-Za-z_][A-Za-z0-9_]*/)) {
                d = substr($0, RSTART, RLENGTH)
                gsub(/[^A-Za-z0-9_]/, "", d)  # reduce to the bare delimiter word
                delim = d; in_h = 1; print; next
            }
            if (NR == 1 && $0 ~ /^#!/) { print; next }   # shebang
            if ($0 ~ /^[[:space:]]*#/) next              # whole-line comment
            print
        }
    ' "$file" > "$tmp" || { rm -f "$tmp"; die "comment strip failed: ${file}"; }
    bash -n "$tmp" 2>/dev/null || { rm -f "$tmp"; die "comment strip broke ${file} (bash -n) — aborting"; }
    cat "$tmp" > "$file"    # write back in place (preserves mode/inode; mv resets perms)
    rm -f "$tmp"
}

usage() {
    cat <<'EOF'
sos-vault appliance package builder (master plan §7.3)

  Usage:
    ./build.sh                  Full pipeline.
    ./build.sh --dry-run          Walk every step, mutate nothing.
    ./build.sh --skip-publish-prod  Don't scp artifacts to production.
    ./build.sh --version V        Override package version
                                  (default: git describe --tags --always).
    ./build.sh --help             This message.

  Steps:
     1. Verify on `appliance` branch        6. dpkg-deb --build
     2. composer (baked in image; no-op)    7. alien --to-rpm
     3. assets (baked in image; no-op)      8. checksums.sh
     4. Stage HOST-SIDE payload             9. List artifacts under dist/
     5. Docker images (pulled from GHCR)   10. scp artifacts to production

  The application code + compiled assets are baked into the published images
  (ghcr.io/sos-vault/{app,nginx}); the deb ships only host-side files (compose,
  docker-compose/ config, sysadmin/ scripts). The build no longer mutates the
  working tree, so there is no dev-dependency restore step.
EOF
}

# ---------------------------------------------------------------------------
# resolve_deb_version — dpkg-valid package/image version, shared by the deb
# control file (Step 6) and the staged appliance compose image tag (Step 4).
# Honours --version; else the nearest git tag (strip leading 'v', '-'→'+');
# else the SaaS-matching DEFAULT_VERSION. Debian versions MUST start with a
# digit, so a bare hash / 'v'-prefixed tag is never returned.
resolve_deb_version() {
    local version="${VERSION_OVERRIDE:-}"
    if [[ -z "$version" ]]; then
        version="$(git -C "${BUILD_ROOT}" describe --tags 2>/dev/null || true)"
        version="${version#v}"
        version="${version//-/+}"
        [[ "$version" =~ ^[0-9] ]] || version="${DEFAULT_VERSION}"
    fi
    echo "$version"
}

# ---------------------------------------------------------------------------
# Step 1 — verify branch
# ---------------------------------------------------------------------------
step_01_verify_branch() {
    log 'Step 1/9 — verifying we are on the appliance branch'
    local branch
    branch="$(git -C "${BUILD_ROOT}" rev-parse --abbrev-ref HEAD 2>/dev/null || echo '')"
    if [[ "$branch" != 'appliance' ]]; then
        if [[ "$DRY_RUN" -eq 1 ]]; then
            warn "  current branch is '$branch' (would refuse outside dry-run)"
        else
            die "build.sh must be run on the 'appliance' branch (current: $branch)"
        fi
    fi
}

# ---------------------------------------------------------------------------
# Step 2 — composer install --no-dev  (now a no-op)
#
# The deb no longer ships vendor/ — the production (--no-dev) vendor/ is built
# and BAKED inside ghcr.io/sos-vault/app by build/publish-images.sh (the
# Dockerfile builder stage runs composer --no-dev). Nothing to do here, and we
# deliberately do NOT mutate the working tree's vendor/ anymore (so no
# restore-dev-state dance is needed).
# ---------------------------------------------------------------------------
step_02_composer_install() {
    log 'Step 2/9 — composer (baked into the app image; nothing to stage)'
}

# ---------------------------------------------------------------------------
# Step 3 — npm build  (now a no-op)
#
# The deb no longer ships public/ — the compiled front-end assets are baked
# into the images (the app image bakes public/build via `COPY .`; the nginx
# image COPYs public/ from the app image). publish-images.sh runs `vite build`
# right before `docker build`, so the baked manifest and assets are always a
# matched set regardless of the git-tracked manifest's state. Nothing is staged
# into the deb here.
# ---------------------------------------------------------------------------
step_03_npm_build() {
    log 'Step 3/9 — assets (baked into the images; nothing to stage)'
}

# ---------------------------------------------------------------------------
# Step 4 — stage payload under dist/staging/opt/sos-vault
# ---------------------------------------------------------------------------
step_04_stage() {
    log "Step 4/9 — staging HOST-SIDE payload to ${STAGING_DIR}"
    run rm -rf "${STAGING_DIR}"
    run mkdir -p "${STAGING_DIR}/opt/sos-vault"

    # The application code + compiled assets are BAKED INTO THE IMAGES
    # (ghcr.io/sos-vault/{app,nginx}), so the deb ships NO PHP source, NO
    # vendor/, NO public/ — only the host-side files the compose stack and the
    # installer need: the appliance compose, the docker-compose/ config
    # (nginx conf, php ini, ssl + ca-cert mount points), and the sysadmin/
    # scripts the installer/systemd run on the HOST. This is a WHITELIST: adding
    # a new host-side file means listing it here, which is the point — nothing
    # leaks into the deb by accident.
    local dst="${STAGING_DIR}/opt/sos-vault"

    if ! command -v rsync >/dev/null 2>&1; then
        warn '  rsync not on PATH — staging copy skipped'
        return
    fi

    # docker-compose/ — nginx conf, php local.ini, etc/sudoers, ssl + ca-cert
    # dirs. Drop any dev TLS key so it is never reused across appliances, and
    # the appliance .tmpl (swapped in for the SaaS conf below).
    run rsync -a \
        --exclude 'nginx/ssl/*/*.pem' \
        --exclude 'ca-certificates/*' \
        "${BUILD_ROOT}/docker-compose/" "${dst}/docker-compose/"

    # sysadmin/ — installer + host helpers + systemd units + sudoers fragments.
    # Exclude the large redirect binary and the dev-only scripts.
    run rsync -a \
        --exclude 'redirect' \
        --exclude 'weylandQuest.sh' \
        --exclude 'destroy.sh' \
        --exclude 'dockerIPs.sh' \
        --exclude 'send2telegram.sh' \
        "${BUILD_ROOT}/sysadmin/" "${dst}/sysadmin/"

    # Loose host-side files: .bashrc (mounted as /root/.profile) + the legal docs.
    local f
    for f in .bashrc LICENSE LICENSE.md NOTICE README.md SECURITY.md; do
        [[ -f "${BUILD_ROOT}/${f}" ]] && run cp "${BUILD_ROOT}/${f}" "${dst}/${f}"
    done

    # Empty bind-mount target for the (deferred, ~1.1 GB) LLM model download.
    run mkdir -p "${dst}/models"

    # Curated default public assets: login logo + favicons, the default avatar,
    # and the Documentation / page / post images the appliance renders (the
    # in-app Documentation menu points at seeded posts). They are staged at
    # storage-seed/ — NOT storage/, which is operator data — and
    # container_start.sh copies them into the host-mounted storage/app/public on
    # boot (no-clobber). Ship ONLY the git-tracked set so stray dev uploads in
    # the working tree never leak into the deb.
    run mkdir -p "${dst}/storage-seed/app/public"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] rsync git-tracked storage/app/public -> %s/storage-seed/app/public\n' "$dst"
    else
        ( cd "${BUILD_ROOT}/storage/app" && git ls-files public ) \
            | rsync -a --files-from=- "${BUILD_ROOT}/storage/app/" "${dst}/storage-seed/app/"
    fi

    # Ship the committed appliance compose AS docker-compose.yml, pinning BOTH
    # the app and nginx GHCR images to this build's version (one placeholder per
    # line, so a non-global sed replaces both). Installer pulls + `up --no-build`.
    local version
    version="$(resolve_deb_version)"
    run cp "${BUILD_ROOT}/docker-compose.appliance.yml" "${dst}/docker-compose.yml"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] sed -i "s/IMAGE_TAG_PLACEHOLDER/%s/" %s/docker-compose.yml\n' "$version" "$dst"
        printf '   [dry-run] strip comments from %s/docker-compose.yml\n' "$dst"
    else
        sed -i "s/IMAGE_TAG_PLACEHOLDER/${version}/" "${dst}/docker-compose.yml"
        # Ship a comment-free compose. The committed source is heavily annotated
        # (the rationale belongs in the repo), but the customer-host copy should be
        # lean. Three passes, in order: drop whole-line comments, strip trailing
        # inline comments (whitespace + #...; no value in this file contains a '#'
        # so this is safe), then squeeze the runs of blank lines left behind.
        sed -i -e '/^[[:space:]]*#/d' \
               -e 's/[[:space:]]\{1,\}#.*$//' \
               "${dst}/docker-compose.yml"
        cat -s "${dst}/docker-compose.yml" > "${dst}/docker-compose.yml.tmp" \
            && mv "${dst}/docker-compose.yml.tmp" "${dst}/docker-compose.yml"
    fi

    # Ship the appliance nginx config IN PLACE OF the SaaS site config. The repo
    # sos-vault.com.conf 301-redirects every host to https://sos-vault.com (right
    # for the hosted site, fatal for an appliance reached by IP); the appliance
    # template is a catch-all default_server that serves the app on any Host.
    local nginx_dir="${STAGING_DIR}/opt/sos-vault/docker-compose/nginx"
    run rm -f "${nginx_dir}/sos-vault.com.conf"
    run cp "${BUILD_ROOT}/docker-compose/nginx/sos-vault.appliance.conf.tmpl" \
        "${nginx_dir}/sos-vault.com.conf"
    # Drop the template from the payload so nginx (which loads *.conf) doesn't
    # also pick it up as a second, conflicting server.
    run rm -f "${nginx_dir}/sos-vault.appliance.conf.tmpl"

    # Ship comment-free host scripts: strip whole-line comments from every
    # shebang script now staged under sysadmin/ (installer + host helpers). The
    # repo keeps the annotated source; the deployed copy is lean. strip_shell_comments
    # preserves the shebang + here-doc bodies and bash -n verifies each result.
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] strip whole-line comments from shebang scripts under %s/sysadmin\n' "$dst"
    else
        local s first
        while IFS= read -r s; do
            IFS= read -r first < "$s" || true
            [[ "$first" == '#!'* ]] && strip_shell_comments "$s"
        done < <(find "${dst}/sysadmin" -type f | LC_ALL=C sort)
    fi
}

# ---------------------------------------------------------------------------
# Step 5 — build + save docker images
# ---------------------------------------------------------------------------
step_05_docker_images() {
    log 'Step 5/9 — Docker images (pulled from GHCR at install, not bundled)'
    # Images are NO LONGER saved into the deb. The appliance pulls them from
    # registries at install time (docker-compose.appliance.yml): nginx, redis
    # and llama.cpp are upstream public images; the sos-vault app image is
    # published to GHCR by build/publish-images.sh at release time. This keeps
    # the deb small (~50-80 MB) and removes the install-host rebuild entirely.
    log '  app image: build/publish-images.sh --version <v>  →  ghcr.io/sos-vault/app:<v>'
}

# ---------------------------------------------------------------------------
# Step 6 — dpkg-deb --build
# ---------------------------------------------------------------------------
step_06_dpkg_deb() {
    log "Step 6/9 — building deb under ${DEB_DIR}"
    if ! command -v dpkg-deb >/dev/null 2>&1; then
        warn '  dpkg-deb not on PATH — skipping'
        return
    fi

    # Lay out the deb tree: <root>/opt/sos-vault + <root>/DEBIAN/...
    run rm -rf "${DEB_DIR}"
    run mkdir -p "${DEB_DIR}"
    run cp -a "${STAGING_DIR}/opt" "${DEB_DIR}/opt"
    run mkdir -p "${DEB_DIR}/DEBIAN"
    run cp "${BUILD_ROOT}/build/deb/DEBIAN/control" "${DEB_DIR}/DEBIAN/control"
    run cp "${BUILD_ROOT}/build/deb/DEBIAN/preinst" "${DEB_DIR}/DEBIAN/preinst"
    run cp "${BUILD_ROOT}/build/deb/DEBIAN/postinst" "${DEB_DIR}/DEBIAN/postinst"
    run cp "${BUILD_ROOT}/build/deb/DEBIAN/prerm" "${DEB_DIR}/DEBIAN/prerm"
    run cp "${BUILD_ROOT}/build/deb/DEBIAN/postrm" "${DEB_DIR}/DEBIAN/postrm"
    run chmod 0755 "${DEB_DIR}/DEBIAN/preinst" "${DEB_DIR}/DEBIAN/postinst" "${DEB_DIR}/DEBIAN/prerm" "${DEB_DIR}/DEBIAN/postrm"

    # dpkg-valid package version (shared with the staged compose image tag).
    local version
    version="$(resolve_deb_version)"
    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf '   [dry-run] sed -i "s/VERSION_PLACEHOLDER/%s/" %s/DEBIAN/control\n' "$version" "$DEB_DIR"
    else
        sed -i "s/VERSION_PLACEHOLDER/${version}/" "${DEB_DIR}/DEBIAN/control"
    fi

    run dpkg-deb --root-owner-group --build "${DEB_DIR}" "${DIST_DIR}/sos-vault.deb"
}

# ---------------------------------------------------------------------------
# Step 7 — alien --to-rpm
# ---------------------------------------------------------------------------
step_07_alien_rpm() {
    log 'Step 7/9 — alien --to-rpm sos-vault.deb'
    if ! command -v alien >/dev/null 2>&1; then
        warn '  alien not on PATH — skipping rpm build'
        return
    fi
    run bash -c "cd '${DIST_DIR}' && alien --to-rpm --scripts sos-vault.deb"
}

# ---------------------------------------------------------------------------
# Step 8 — checksums
# ---------------------------------------------------------------------------
step_08_checksums() {
    log 'Step 8/9 — generating SHA256SUMS'
    local script="${BUILD_ROOT}/build/checksums.sh"
    if [[ ! -x "$script" ]]; then
        warn "  $script missing — skipping"
        return
    fi
    run "$script"
}

# ---------------------------------------------------------------------------
# Step 9 — list artifacts
# ---------------------------------------------------------------------------
step_09_list_artifacts() {
    log "Step 9/9 — artifacts under ${DIST_DIR}"
    if [[ -d "${DIST_DIR}" ]]; then
        run ls -la "${DIST_DIR}"
    else
        warn "  ${DIST_DIR} does not exist (dry-run or no artifacts produced)"
    fi
}

# ---------------------------------------------------------------------------
# Step 10 — publish the .deb / .rpm / SHA256SUMS to production over scp so the
# public landing card + Customer Portal Download page serve the new build.
# Overridable via PROD_SCP_TARGET; skip with --skip-publish-prod.
# ---------------------------------------------------------------------------
step_10_publish_to_production() {
    if [[ "$SKIP_PUBLISH_PROD" -eq 1 ]]; then
        log 'Step 10 — --skip-publish-prod set, not uploading to production'
        return
    fi
    log "Step 10 — publishing artifacts to ${PROD_SCP_TARGET}"
    if [[ ! -d "${DIST_DIR}" ]]; then
        warn "  ${DIST_DIR} does not exist — nothing to publish"
        return
    fi
    shopt -s nullglob
    local artifacts=("${DIST_DIR}"/*.deb "${DIST_DIR}"/*.rpm "${DIST_DIR}"/SHA256SUMS)
    shopt -u nullglob
    if [[ "${#artifacts[@]}" -eq 0 ]]; then
        warn "  no .deb/.rpm/SHA256SUMS found under ${DIST_DIR} — nothing to upload"
        return
    fi
    run scp -P 1967 "${artifacts[@]}" "${PROD_SCP_TARGET}"
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        --skip-publish-prod) SKIP_PUBLISH_PROD=1 ;;
        --version) shift; VERSION_OVERRIDE="${1:-}" ;;
        --help|-h) usage; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
    shift
done

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
    log "sos-vault build (DRY_RUN=${DRY_RUN})"
    # Steps 2/3 no longer mutate the working tree (code + assets are baked into
    # the published images), so no restore-dev-state trap is needed.
    step_01_verify_branch
    step_02_composer_install
    step_03_npm_build
    step_04_stage
    step_05_docker_images
    step_06_dpkg_deb
    step_07_alien_rpm
    step_08_checksums
    step_09_list_artifacts
    step_10_publish_to_production
    log 'build complete'
}

main "$@"
