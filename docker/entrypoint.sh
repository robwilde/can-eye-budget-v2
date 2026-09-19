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
# env('APP_ENV') resolves and the env-first precedence in ray.php:40 governs.
# env() only sees exported variables, and once config:cache has run Laravel never
# reads .env again, so an APP_ENV living only in .env is invisible to env() and
# resolution falls back to the cached config('app.env'). Absent this block a cache
# built at local and then run elsewhere would enable Ray off that stale value (#419).
# This runs ahead of every php artisan call, not merely ahead of config:cache:
# storage:link and migrate also boot the framework and evaluate ray.php.
#
# An already-exported APP_ENV is authoritative and is never overwritten. When
# .env defines the key its value is exported even if empty, because ray.php
# treats a present-but-empty APP_ENV as a deliberate non-local signal. When
# neither source defines it the variable is exported as production, matching
# Laravel's own default in config/app.php. Precedence: exported var > .env > production.
# No codepath leaves it unset, and the null/(null) sentinels are dropped below,
# so env('APP_ENV') cannot resolve null and never reaches the config cache.
if [ -z "${APP_ENV+x}" ] && [ -f .env ]; then
    # Parse .env without sourcing it: values may contain #, $, quotes or backticks
    # that a `.` would execute. The key is anchored whole, so a commented
    # #APP_ENV= line and a decoy MY_APP_ENV= both fail to match, while the
    # optional `export` prefix and whitespace around `=` that phpdotenv accepts
    # are matched. The last definition wins, as it does in phpdotenv.
    # Matched quote spans, inline # comments and surrounding whitespace are
    # handled too; ${VAR} interpolation, double-quote escapes (\" and \\) and
    # multi-line values are not. Each resolves to a non-local value here, so
    # Ray fails closed, and APP_ENV is the root others interpolate from.
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

# Laravel's own encoding of absence includes two literals: Env.php:256 lowercases
# before matching and Env.php:266-268 maps null and (null) to PHP null. A guard
# testing shell presence would disagree with env() about what absent means and
# leak those two to config('app.env'), so they are dropped here and picked up by
# the fallback below. Matched case-insensitively and untrimmed, mirroring
# strtolower($value) with no trim: ' null ' is not a sentinel to env() either.
# empty, false, true and the (empty)/(false)/(true) forms are deliberately kept
# -- each is a value env() reports, and each compares unequal to local.
case "${APP_ENV-}" in
    [Nn][Uu][Ll][Ll]|'('[Nn][Uu][Ll][Ll]')') unset APP_ENV ;;
esac

if [ -z "${APP_ENV+x}" ]; then
    export APP_ENV=production
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

# Dokploy's Application "command" field maps to Swarm ContainerSpec.Command, and Docker
# replaces the image ENTRYPOINT whenever that is set, so any process written there boots
# without this script: no role validation, no APP_ENV export, no caches, no chown, no
# privilege drop. The image therefore declares no CMD and each role's process is resolved
# here, so the horizon and scheduler services need neither a command nor args -- only
# CONTAINER_ROLE (#430). An explicit argument list still wins, for `docker run ... php
# artisan tinker` and the entrypoint tests. The default arm is unreachable while the
# validation at the top of this file holds; it exists so a regression there fails here with
# a named role instead of an empty exec.
if [ "$#" -eq 0 ]; then
    case "${CONTAINER_ROLE-web}" in
        web)       set -- supervisord -c /etc/supervisord.conf ;;
        horizon)   set -- php artisan horizon ;;
        scheduler) set -- php artisan schedule:work ;;
        *)
            echo "entrypoint: no process for CONTAINER_ROLE '${CONTAINER_ROLE}'; expected web, horizon, or scheduler" >&2
            exit 1
            ;;
    esac
fi

if [ "${CONTAINER_ROLE:-web}" = "web" ]; then
    exec "$@"
fi

exec su-exec www-data "$@"
