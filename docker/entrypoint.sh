#!/bin/sh
set -e

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
