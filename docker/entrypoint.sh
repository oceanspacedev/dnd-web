#!/bin/sh

set -eu

optimize_laravel() {
    if [ "${LARAVEL_OPTIMIZE:-true}" = "true" ]; then
        php artisan optimize
    fi
}

case "${1:-web}" in
    web)
        optimize_laravel
        exec php artisan octane:frankenphp \
            --host=0.0.0.0 \
            --port=8080 \
            --admin-host=127.0.0.1 \
            --admin-port=2019 \
            --workers="${OCTANE_WORKERS:-2}" \
            --max-requests="${OCTANE_MAX_REQUESTS:-500}" \
            --caddyfile=/etc/frankenphp/Caddyfile \
            --log-level="${OCTANE_LOG_LEVEL:-WARN}" \
            --no-interaction
        ;;
    worker)
        optimize_laravel
        exec php artisan queue:work \
            --sleep="${QUEUE_SLEEP:-1}" \
            --tries="${QUEUE_TRIES:-3}" \
            --timeout="${QUEUE_TIMEOUT:-300}" \
            --max-time="${QUEUE_MAX_TIME:-3600}" \
            --memory="${QUEUE_MEMORY:-384}" \
            --no-interaction
        ;;
    scheduler)
        optimize_laravel
        exec php artisan schedule:work --no-interaction
        ;;
    release)
        rm -f /tmp/dnd-release-ready
        attempt=1

        until php artisan db:show --no-interaction >/dev/null 2>&1; do
            if [ "$attempt" -ge 30 ]; then
                echo "Database belum dapat dihubungi setelah 30 percobaan." >&2
                exit 1
            fi

            echo "Menunggu database (percobaan $attempt/30)..." >&2
            attempt=$((attempt + 1))
            sleep 5
        done

        php artisan app:storage-probe --no-interaction
        php artisan migrate --force --no-interaction
        touch /tmp/dnd-release-ready
        exec sleep infinity
        ;;
    *)
        exec "$@"
        ;;
esac
