#!/bin/sh
set -e

ROLE="${CONTAINER_ROLE:-web}"

case "$ROLE" in
    web)
        exec curl -fsS http://127.0.0.1/up
        ;;
    horizon|scheduler)
        exec php /var/www/html/artisan app:worker-health "$ROLE" --no-ansi
        ;;
    *)
        exit 0
        ;;
esac
