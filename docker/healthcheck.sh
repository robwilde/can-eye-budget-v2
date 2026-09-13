#!/bin/sh
set -e

# Runs as root: the image declares no USER, so Docker's HEALTHCHECK executes as root even
# though the horizon and scheduler workloads drop to www-data via su-exec (entrypoint.sh).
# Verified in a running container that this creates no root-owned files under storage/ or
# bootstrap/cache/, which would otherwise silently break www-data writes later: LOG_STACK=stderr
# means a framework boot writes no log file, and the probes are read-only. Re-check if logging
# is ever pointed back at a file (LOG_STACK=single) or a probe is added that writes to disk;
# the fix then is to run the probe under `su-exec www-data`. See issue #410.

case "${CONTAINER_ROLE-web}" in
    web)
        exec curl -fsS http://127.0.0.1/up
        ;;
    horizon)
        # Container-local liveness, NOT horizon:status. horizon:status returns 1 when any
        # master is paused, so a deploy-time `horizon:pause` held longer than
        # --interval=15s x --retries=5 (~75s) would mark this container unhealthy and invite
        # a restart mid-deploy. It is also fleet-wide: Horizon's `masters` sorted set is read
        # without a host filter, so a healthy peer on the same Redis + HORIZON_PREFIX masks a
        # dead local master. horizon:liveness scopes the check to this host's master name
        # prefix and treats paused as alive. Costs ~0.13s (framework boot), well inside the 5s
        # limit. Bounded ~14s false-healthy window after kill -9 (Horizon's own stale-master
        # cutoff in RedisMasterSupervisorRepository::names()); within --retries=5 at 15s
        # interval. Redis outage correctly marks unhealthy. stdout is dropped; stderr carries
        # the failure reason into `docker inspect` health output.
        exec php artisan horizon:liveness --no-ansi >/dev/null
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
