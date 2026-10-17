#!/usr/bin/env bash
#
# Start (or restart) the local dev stack and print both links.
#
# Why a script rather than two terminals: the public URL is a random ngrok
# subdomain, so it changes every time the tunnel restarts. Keeping the app and
# the tunnel in one supervised process means there is always a working pair, and
# one place to read the current URL from instead of digging through a log.
#
# Usage:
#   bin/dev-up.sh          start both, supervise them, print links
#   bin/dev-up.sh --stop   stop everything

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
PORT="${PORT:-8000}"
LOG_DIR="$ROOT/storage/logs/dev"
URL_FILE="$ROOT/tmp/dev-urls.txt"

mkdir -p "$LOG_DIR" "$(dirname "$URL_FILE")"

stop_all() {
    pkill -f "artisan serve"                2>/dev/null
    pkill -f "ngrok http $PORT"             2>/dev/null
    pkill -f "bin/dev-up.sh --supervise"    2>/dev/null
    sleep 1
    echo "Stopped."
}

if [ "${1:-}" = "--stop" ]; then
    stop_all
    exit 0
fi

stop_all

# PHP serving the app.
nohup php artisan serve --host=127.0.0.1 --port="$PORT" \
    </dev/null >"$LOG_DIR/serve.log" 2>&1 &
disown

# Wait for the app before the tunnel points at it, otherwise the first few
# visitors through the tunnel get a connection refused.
for _ in $(seq 1 30); do
    if curl -sf -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/"; then break; fi
    sleep 1
done

# Public tunnel. Free tier gives a random subdomain each start; that is the
# trade for not putting an ngrok account on this machine.
nohup ngrok http "$PORT" --log=stdout \
    </dev/null >"$LOG_DIR/ngrok.log" 2>&1 &
disown

PUBLIC=""
for _ in $(seq 1 40); do
    PUBLIC="$(curl -sf --max-time 2 http://127.0.0.1:4040/api/tunnels 2>/dev/null \
        | python3 -c 'import sys,json;print(json.load(sys.stdin)["tunnels"][0]["public_url"])' 2>/dev/null)"
    [ -n "$PUBLIC" ] && break
    sleep 1
done

cat > "$URL_FILE" <<EOF
Local   http://127.0.0.1:$PORT
Public  ${PUBLIC:-"(tunnel did not start — see $LOG_DIR/ngrok.log)"}
EOF

cat <<EOF

  Opoku Auctions — dev stack up

  Local    http://127.0.0.1:$PORT
  Public   ${PUBLIC:-"(unavailable)"}

  Also written to: ${URL_FILE#$ROOT/}
  Logs:            storage/logs/dev/

  Stop with:       bin/dev-up.sh --stop

EOF

[ -n "$PUBLIC" ] || exit 1