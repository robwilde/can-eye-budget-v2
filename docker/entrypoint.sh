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

# Export APP_ENV as a real OS variable before anything boots Laravel, so that
# env('APP_ENV') resolves and the env-first precedence in ray.php:36 governs.
# env() only sees exported variables, and once config:cache has run Laravel never
# reads .env again, so an APP_ENV living only in .env is invisible to env() and
# resolution falls back to the cached config('app.env'). A cache built at local
# and then run elsewhere would enable Ray off that stale value (issue #419).
# This runs ahead of every php artisan call, not merely ahead of config:cache:
# storage:link and migrate also boot the framework and evaluate ray.php.
#
# An already-exported APP_ENV is authoritative and is never overwritten. When
# .env defines the key its value is exported even if empty, because ray.php
# treats a present-but-empty APP_ENV as a deliberate non-local signal. When
# neither source defines it the variable is left unset rather than invented;
# resolution then falls back to config('app.env'), which is production unless a
# cache was baked in some other environment. A stale cache reading local is the
# one case this cannot cover -- export RAY_ENABLED=false to force Ray off there.
if [ -z "${APP_ENV+x}" ] && [ -f .env ]; then
    # Parse .env without sourcing it: values may contain #, $, quotes or backticks
    # that a `.` would execute. The key is anchored whole, so a commented
    # #APP_ENV= line and a decoy MY_APP_ENV= both fail to match, while the
    # optional `export` prefix and whitespace around `=` that phpdotenv accepts
    # are matched. The last definition wins, as it does in phpdotenv.
    app_env_line=$(grep -E '^[[:space:]]*(export[[:space:]]+)?APP_ENV[[:space:]]*=' .env 2>/dev/null | tail -n 1) || app_env_line=''
    if [ -n "$app_env_line" ]; then
        app_env_value=$(printf '%s\n' "$app_env_line" | sed -e 's/^[[:space:]]*//' -e 's/^export[[:space:]]\{1,\}//' -e 's/^APP_ENV[[:space:]]*=//' -e 's/^[[:space:]]*//')
        case "$app_env_value" in
            # Quoted: take the quoted span and discard any trailing inline comment.
            '"'*) app_env_value=$(printf '%s\n' "$app_env_value" | sed -e 's/^"\([^"]*\)".*$/\1/') ;;
            "'"*) app_env_value=$(printf '%s\n' "$app_env_value" | sed -e "s/^'\([^']*\)'.*\$/\1/") ;;
            # Unquoted: phpdotenv ends the value at the first #, then trims.
            *) app_env_value=$(printf '%s\n' "$app_env_value" | sed -e 's/#.*$//' -e 's/[[:space:]]*$//') ;;
        esac
        export APP_ENV="$app_env_value"
    fi
fi

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
