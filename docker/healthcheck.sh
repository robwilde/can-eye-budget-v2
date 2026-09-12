#!/bin/sh
set -e

case "${CONTAINER_ROLE:-web}" in
    web)
        exec curl -fsS http://127.0.0.1/up
        ;;
    horizon|scheduler)
        exit 0
        ;;
    *)
        echo "healthcheck: unknown CONTAINER_ROLE '${CONTAINER_ROLE}'; expected web, horizon, or scheduler" >&2
        exit 1
        ;;
esac
