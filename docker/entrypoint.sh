#!/bin/sh
# Container entrypoint.
#   - Default command (supervisord): prepare storage, migrate, cache config, serve.
#   - Any other command (e.g. `php artisan user:create`): run it as www-data.
set -e
cd /var/www/html

if [ "$1" != "supervisord" ]; then
    exec su-exec www-data "$@"
fi

if [ -z "$APP_KEY" ]; then
    cat >&2 <<'MSG'
ERROR: APP_KEY is not set.
Generate one and pass it as an environment variable:
    docker run --rm ghcr.io/fiforms/announcementslides php artisan key:generate --show
Keep it safe: it is needed to restore a backup on another server.
MSG
    exit 1
fi

mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs storage/database \
    bootstrap/cache

if [ "$DB_CONNECTION" = "sqlite" ] && [ ! -e "$DB_DATABASE" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
fi

# Volumes may arrive owned by root; only touch what's wrong (fast on big volumes).
find storage bootstrap/cache \( ! -user www-data -o ! -group www-data \) \
    -exec chown www-data:www-data {} + 2>/dev/null || true

php artisan storage:link --force >/dev/null

if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    tries=0
    until su-exec www-data php artisan migrate --force; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "ERROR: database migration failed after $tries attempts." >&2
            exit 1
        fi
        echo "Database not ready (attempt $tries/30), retrying in 2s..." >&2
        sleep 2
    done
fi

su-exec www-data php artisan config:cache
su-exec www-data php artisan view:cache
su-exec www-data php artisan event:cache
su-exec www-data php artisan route:cache || echo "route:cache skipped" >&2

exec "$@"
