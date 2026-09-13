#!/bin/sh
set -e

case "${CONTAINER_ROLE-web}" in
    web)
        exec curl -fsS http://127.0.0.1/up
        ;;
    horizon)
        # Use php artisan horizon:status to verify the Horizon supervisor is running
        # and communicating with Redis. This costs ~0.13s (framework boot) with 36x timeout
        # margin on the 5s limit. A Redis outage correctly marks unhealthy.
        # Bounded ~14s false-healthy window after kill -9 (Redis key TTL); within --retries=5
        # at 15s interval. Measured empirically; exit 0 = running, exit 2 = inactive.
        exec php artisan horizon:status >/dev/null 2>&1
        ;;
    scheduler)
        # Verify the scheduler is actively dispatching by checking a heartbeat file.
        # Safer than pgrep: catches a wedged-but-alive process. The scheduled task
        # writes ~every minute; threshold 120s tolerates scheduler tick interval (60s) + jitter
        # within healthcheck interval (15s, 5 retries = 75s at worst before failing).
        # Does not boot Laravel; entirely shell-based for minimal overhead.
        HEARTBEAT_FILE="storage/framework/scheduler.heartbeat"
        THRESHOLD_SECS=120
        if [ ! -f "$HEARTBEAT_FILE" ]; then
            exit 1
        fi
        MTIME=$(stat -c %Y "$HEARTBEAT_FILE" 2>/dev/null || stat -f %m "$HEARTBEAT_FILE" 2>/dev/null || echo 0)
        NOW=$(date +%s)
        AGE=$((NOW - MTIME))
        if [ "$AGE" -le "$THRESHOLD_SECS" ]; then
            exit 0
        else
            exit 1
        fi
        ;;
    "")
        echo "healthcheck: CONTAINER_ROLE is set but empty; expected web, horizon, or scheduler" >&2
        exit 1
        ;;
    *)
        echo "healthcheck: unknown CONTAINER_ROLE '${CONTAINER_ROLE}'; expected web, horizon, or scheduler" >&2
        exit 1
        ;;
esac
