# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# Appliance nginx image — stock nginx with the application's compiled public/
# assets baked in, copied from the app image so the static files ALWAYS match
# the running app version (no shared volume / boot-time copy to drift).
#
# Built by build/publish-images.sh AFTER the app image:
#   docker build --build-arg APP_IMAGE=ghcr.io/sos-vault/app:<v> \
#     -f docker-compose/nginx/Dockerfile -t ghcr.io/sos-vault/nginx:<v> .
#
# The nginx server config itself is NOT baked: it is bind-mounted from the host
# (/opt/sos-vault/docker-compose/nginx) so the operator can change the port /
# redirects via the admin "Host & Port" page.
# ---------------------------------------------------------------------------
ARG APP_IMAGE=ghcr.io/sos-vault/app:latest
FROM ${APP_IMAGE} AS app

FROM nginx:alpine3.23-slim

# The Laravel web root (index.php + compiled public/build/* assets). nginx's
# `root /var/www/site/public` (host-mounted conf) serves these directly.
COPY --from=app /var/www/site/public /var/www/site/public

# public/storage is excluded from the app image build context, so recreate the
# relative symlink here. /storage/* URLs (post images, avatars) resolve to
# storage/app/public, which the compose nginx service bind-mounts read-only
# from the host (/opt/sos-vault/storage). Also make sure nginx's worker can
# read everything it serves.
RUN ln -sfn ../storage/app/public /var/www/site/public/storage \
 && chmod -R a+rX /var/www/site/public
