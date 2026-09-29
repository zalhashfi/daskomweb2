#!/bin/sh
# =============================================================================
# Production entrypoint for the self-contained daskomweb2 image.
#
# Deliberately minimal per spec:
#   1. create + chown the writable paths Laravel needs,
#   2. warm the Laravel caches (config/route/view) now that the real runtime
#      environment variables are present -- see the long comment in
#      docker/Dockerfile.prod explaining why this is NOT done at build time,
#   3. exec supervisord (the three-program web/reverb/horizon supervisor).
#
# No bind-mount is assumed: the code, vendor/ and compiled assets are all baked
# into the image. The writable paths are chowned only if we can (i.e. running as
# root); when started as an unprivileged user we skip chown instead of failing.
# =============================================================================
set -e

APP_DIR=/app
WRITABLE_PATHS="$APP_DIR/storage $APP_DIR/bootstrap/cache"

mkdir -p \
    "$APP_DIR/storage/framework/cache/data" \
    "$APP_DIR/storage/framework/sessions" \
    "$APP_DIR/storage/framework/views" \
    "$APP_DIR/storage/logs" \
    "$APP_DIR/bootstrap/cache" \
    /var/log/supervisor \
    /var/run

# Chown only the writable paths (cheap, no recursive chown of the whole app).
if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data $WRITABLE_PATHS
    chmod -R ug+rwX $WRITABLE_PATHS
fi

# ---------------------------------------------------------------------------
# Laravel cache warm-up at container start (NOT build time).
#
# `config:cache` serialises resolved config and therefore needs the real
# APP_KEY / DB_* / REDIS_* values, which only exist at runtime. Each step is
# guarded so a transient/unavailable dependency (e.g. DB not yet reachable for
# route:cache) degrades to a warning instead of a crash-loop.
# ---------------------------------------------------------------------------
php "$APP_DIR/artisan" config:cache || echo "WARN: artisan config:cache failed; continuing with uncached config"
php "$APP_DIR/artisan" route:cache  || echo "WARN: artisan route:cache failed;  continuing with uncached routes"
php "$APP_DIR/artisan" view:cache   || echo "WARN: artisan view:cache failed;   continuing with uncached views"

exec "$@"
