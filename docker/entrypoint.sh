#!/bin/sh
# Container entrypoint.
#   - Default command (supervisord): prepare storage, migrate, cache config, serve.
#   - Any other command (e.g. `php artisan user:create`): run it as www-data.
set -e
cd /var/www/html

if [ "$1" != "supervisord" ]; then
    exec su-exec www-data "$@"
fi

# No APP_KEY supplied: generate one on first start and keep it in the storage
# volume, so a default install needs no secrets and a backup of the volume
# carries the key with it. Set APP_KEY explicitly to manage it yourself.
KEY_FILE=storage/app/.app_key
if [ -z "$APP_KEY" ]; then
    if [ ! -s "$KEY_FILE" ]; then
        mkdir -p "$(dirname "$KEY_FILE")"
        (umask 077; php artisan key:generate --show > "$KEY_FILE")
        echo "Generated a new APP_KEY and stored it in $KEY_FILE" >&2
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs \
    bootstrap/cache

# Volumes may arrive owned by root; only touch what's wrong (fast on big volumes).
find storage bootstrap/cache \( ! -user www-data -o ! -group www-data \) \
    -exec chown www-data:www-data {} + 2>/dev/null || true

php artisan storage:link --force >/dev/null

if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    tries=0
    # An existing install with migrations to apply gets a database backup first
    # (BACKUP_BEFORE_MIGRATE=false disables); if the backup fails, startup stops
    # rather than migrating without a safety net. Fresh installs are skipped.
    until su-exec www-data php artisan backup:create --if-migrations-pending --tag=pre-migrate --prune \
        && su-exec www-data php artisan migrate --force; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "ERROR: pre-migration backup or database migration failed after $tries attempts." >&2
            exit 1
        fi
        echo "Database not ready or backup/migration failed (attempt $tries/30), retrying in 2s..." >&2
        sleep 2
    done
fi

su-exec www-data php artisan config:cache
su-exec www-data php artisan view:cache
su-exec www-data php artisan event:cache
su-exec www-data php artisan route:cache || echo "route:cache skipped" >&2

exec "$@"
