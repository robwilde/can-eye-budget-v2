#!/bin/sh
set -e

if [ "${CONTAINER_ROLE:-web}" != "web" ]; then
    exit 0
fi

exec curl -fsS http://127.0.0.1/up
