#!/bin/sh
# Stream Recorder™ container: keeps the program files in /data/server up to date, then runs the
# dashboard (PHP's web server, port 8080) and the recorder every minute until the container stops.
set -eu
APP=${SR_APP:-/opt/stream-recorder}   # (SR_APP and SR_ROOT only for testing the script outside Docker)
ROOT=${SR_ROOT:-/data}
DATA=$ROOT/server
mkdir -p "$DATA"

# The program files always come from the image; config.php, keys and recordings in /data are left alone.
for f in "$APP"/server/*.php; do
    n=$(basename "$f")
    [ "$n" = config.php ] && continue
    cp -f "$f" "$DATA/$n"
done
# Optional dashboard settings (see config.example.php in the dashboard folder): /data/dashboard-config.php
if [ -f "$ROOT/dashboard-config.php" ]; then cp -f "$ROOT/dashboard-config.php" "$APP/dashboard/config.php"; fi

if [ -n "${REC_KEY:-}" ] && ! printf '%s' "$REC_KEY" | grep -Eq '^[A-Za-z0-9]{16,}$'; then
    echo "REC_KEY in docker/.env should be 16 or more letters and digits only." >&2
    exit 1
fi
export RECORDER_DIR="$DATA"
# PHP's time zone follows TZ (docker/.env), so times in chat and recordings match your clock.
PHPTZ="-d date.timezone=${TZ:-UTC}"

PHP_CLI_SERVER_WORKERS=4 php $PHPTZ -S 0.0.0.0:${SR_PORT:-8080} -t "$APP/dashboard" >>"$DATA/dashboard_server.log" 2>&1 &
WEB=$!
trap 'kill $WEB 2>/dev/null; echo "Stopped. Recording runs still going finish by themselves."; exit 0' TERM INT

echo "Stream Recorder: dashboard on port ${SR_PORT:-8080}, recorder every minute. Data in $ROOT."
first=1
while true; do
    php $PHPTZ -q "$DATA/record.php" >>"$DATA/cron_output.txt" 2>&1 &
    if [ "$first" = 1 ]; then
        first=0
        sleep 5
        if [ -f "$DATA/secret/dashboard_key.txt" ]; then
            echo "Dashboard key (also in $DATA/secret/dashboard_key.txt): $(cat "$DATA/secret/dashboard_key.txt")"
        fi
    fi
    sleep $((60 - $(date +%s) % 60)) &
    wait $!
done
