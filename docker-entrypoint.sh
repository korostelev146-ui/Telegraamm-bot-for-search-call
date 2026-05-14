#!/bin/sh
set -e

INTERVAL="${MONITOR_INTERVAL:-1200}"

echo "Monitor loop starting (interval ${INTERVAL}s)"
while true; do
    php bin/console app:monitor:run || echo "monitor run failed, continuing"
    sleep "$INTERVAL"
done
