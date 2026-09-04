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
        exec frankenphp run --config /etc/frankenphp/Caddyfile
        ;;
    worker)
        optimize_laravel
        exec php artisan queue:work \
            --sleep=3 \
            --tries=3 \
            --timeout=300 \
            --max-time=3600 \
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
