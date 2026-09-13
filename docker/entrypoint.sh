#!/bin/sh
set -e

case "${CONTAINER_ROLE-web}" in
    web|horizon|scheduler)
        ;;
    "")
        echo "entrypoint: CONTAINER_ROLE is set but empty; expected web, horizon, or scheduler" >&2
        exit 1
        ;;
    *)
        echo "entrypoint: unknown CONTAINER_ROLE '${CONTAINER_ROLE}'; expected web, horizon, or scheduler" >&2
        exit 1
        ;;
esac

mkdir -p \
  storage/app/private \
  storage/app/public \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

php artisan storage:link --force

if [ "${CONTAINER_ROLE:-web}" = "web" ]; then
    php artisan migrate --force --isolated
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

chown -R www-data:www-data storage bootstrap/cache

if [ "${CONTAINER_ROLE:-web}" = "web" ]; then
    exec "$@"
fi

exec su-exec www-data "$@"
