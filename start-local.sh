#!/usr/bin/env bash
# Stream Recorder™ - run it on this computer (Linux, macOS or Windows with WSL).
#
#   ./start-local.sh            dashboard at http://127.0.0.1:8080
#   PORT=9000 ./start-local.sh  another port
#   HOST=0.0.0.0 ./start-local.sh   reachable from other devices on your network
#
# It runs the recorder every minute (what cron does on a web server) and serves the dashboard with
# PHP's built-in web server. Stop with Ctrl+C. Recordings end up in server/recordings/.
# See docs/install-local.md.

set -u
cd "$(dirname "$0")" || exit 1
ROOT="$(pwd)"
PHP="${PHP:-php}"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8080}"

say()  { printf '%s\n' "$*"; }
fail() { printf 'Error: %s\n' "$*" >&2; exit 1; }

# ---- requirements
command -v "$PHP" >/dev/null 2>&1 || fail "PHP not found. Install PHP 8.0 or newer with the curl and openssl extensions (e.g. 'sudo apt install php-cli php-curl')."
"$PHP" -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' || fail "PHP 8.0 or newer is needed (found $("$PHP" -r 'echo PHP_VERSION;'))."
for ext in curl openssl json; do
    "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);" || fail "the PHP '$ext' extension is missing (e.g. 'sudo apt install php-$ext')."
done
if command -v ffmpeg >/dev/null 2>&1 || [ -x server/bin/ffmpeg ]; then
    say "ffmpeg: found - finished streams are converted to MP4."
else
    say "ffmpeg: not found - recordings stay .ts files (VLC plays them). Install ffmpeg to get MP4s."
fi

# ---- reachable from other devices? Then it's plain HTTP on your network.
case "$HOST" in
    127.*|localhost|::1) ;;
    *)
        say ""
        say "WARNING: HOST=$HOST makes the dashboard reachable from other devices on your network over plain"
        say "HTTP: the dashboard key and your recordings travel unencrypted. Only do this on a network you trust."
        if [ -t 0 ]; then
            printf 'Continue? [y/N] '
            read -r answer
            case "$answer" in y|Y|yes|YES) ;; *) fail "stopped. Run without HOST to keep it on this computer only." ;; esac
        else
            say "(Not asked because there is no terminal; set HOST only if you mean it.)"
        fi
        ;;
esac

# ---- passphrase for encrypted tokens (optional)
if [ -z "${REC_KEY:-}" ]; then
    if [ -t 0 ]; then
        say ""
        say "Passphrase that encrypts your Twitch/YouTube tokens (REC_KEY)."
        say "Only needed for the optional Twitch token or YouTube upload - press Enter to skip."
        say "Use the same one every time, or the stored tokens can't be read."
        printf 'REC_KEY: '
        stty -echo 2>/dev/null; read -r REC_KEY; stty echo 2>/dev/null; printf '\n'
    else
        REC_KEY=""
    fi
fi
if [ -n "$REC_KEY" ] && ! printf '%s' "$REC_KEY" | grep -Eq '^[A-Za-z0-9]{16,}$'; then
    fail "REC_KEY should be 16 or more letters and digits only."
fi
export REC_KEY
export RECORDER_DIR="$ROOT/server"

# ---- start
LOGDIR="$ROOT/server"
PIDS=()
cleanup() {
    say ""
    say "Stopping the dashboard and the minute timer..."
    for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done
    say "Recording runs already started finish by themselves within ~5 minutes (or stop them now: pkill -f 'server/record.php')."
    exit 0
}
trap cleanup INT TERM

PHP_CLI_SERVER_WORKERS=4 "$PHP" -S "$HOST:$PORT" -t "$ROOT/dashboard" >>"$LOGDIR/dashboard_server.log" 2>&1 &
PIDS+=($!)
sleep 1
kill -0 "${PIDS[0]}" 2>/dev/null || fail "the dashboard could not start on $HOST:$PORT (port in use? try PORT=9000 ./start-local.sh)."

(
    while true; do
        "$PHP" -q "$ROOT/server/record.php" >>"$LOGDIR/cron_output.txt" 2>&1 &
        sleep $((60 - $(date +%s) % 60))   # next whole minute, like cron
    done
) &
PIDS+=($!)

# The first run creates the dashboard key.
for _ in $(seq 1 20); do [ -f server/secret/dashboard_key.txt ] && break; sleep 0.5; done
URLHOST="$HOST"; [ "$HOST" = "0.0.0.0" ] && URLHOST="<this computer's IP>"
say ""
say "Stream Recorder™ is running."
say "  Dashboard: http://$URLHOST:$PORT/"
if [ -f server/secret/dashboard_key.txt ]; then
    say "  Login key: $(cat server/secret/dashboard_key.txt)   (also in server/secret/dashboard_key.txt)"
else
    say "  Login key: appears in server/secret/dashboard_key.txt within a minute"
fi
say "  Add streamers on the dashboard. Press Ctrl+C to stop."
wait
