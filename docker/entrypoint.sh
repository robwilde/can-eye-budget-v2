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

# Export APP_ENV as a real OS variable before config:cache so that env('APP_ENV')
# always resolves and the env-first precedence in ray.php:36 actually governs.
# env() only sees exported variables, and once config:cache has run Laravel never
# reads .env again — so an APP_ENV that lives only in .env is invisible to env()
# and resolution falls through to the baked config value. A cache built at local
# and then run elsewhere would enable Ray off that stale value (issue #419).
# An already-exported APP_ENV is authoritative and is never overwritten. When
# neither source provides a value we leave it unset and let ray.php fall through
# to its production default, which fails closed.
if [ -z "${APP_ENV+x}" ] && [ -f .env ]; then
    # Parse .env without sourcing it: values may contain #, $, quotes or backticks
    # that a `.` would execute. `[[:space:]]` is used over `\s` because only the
    # former is POSIX-guaranteed for the BusyBox grep this image ships. The key is
    # anchored whole, so a commented #APP_ENV= line and a decoy MY_APP_ENV= both
    # fail to match. First match wins, matching phpdotenv's non-overwriting load.
    app_env_value=$(grep -E '^[[:space:]]*APP_ENV=' .env 2>/dev/null | head -n 1 | cut -d= -f2- | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/") || app_env_value=''
    if [ -n "$app_env_value" ]; then
        export APP_ENV="$app_env_value"
    fi
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
