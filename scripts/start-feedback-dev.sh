#!/usr/bin/env bash
set -euo pipefail

CDP_PORT="${CDP_PORT:-9222}"
SERVER_PORT="${SERVER_PORT:-9223}"
CHROME_DATA_DIR="${CHROME_DATA_DIR:-$HOME/.config/chrome-feedback-cdp}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }
green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
dim()   { printf '\033[0;90m%s\033[0m\n' "$*"; }

check_port() { curl -sf "http://localhost:$1" >/dev/null 2>&1 || curl -sf "http://localhost:$1/json/version" >/dev/null 2>&1; }

echo "── Feedback Dev Environment ──"
echo

if check_port "$SERVER_PORT"; then
    green "✓ Screenshot server already running on :$SERVER_PORT"
else
    echo "Starting screenshot server on :$SERVER_PORT..."
    nohup node "$SCRIPT_DIR/feedback-screenshot.mjs" >"$SCRIPT_DIR/../storage/logs/feedback-server.log" 2>&1 &
    sleep 1
    if check_port "$SERVER_PORT"; then
        green "✓ Screenshot server started on :$SERVER_PORT"
    else
        red "✗ Screenshot server failed to start — check storage/logs/feedback-server.log"
    fi
fi

if curl -sf "http://localhost:$CDP_PORT/json/version" >/dev/null 2>&1; then
    green "✓ Chrome CDP already running on :$CDP_PORT"
else
    echo "Launching Chrome with CDP on :$CDP_PORT..."
    if [ ! -d "$CHROME_DATA_DIR" ]; then
        dim "  First run — this is a fresh Chrome profile."
        dim "  Install any needed extensions; they will persist across restarts."
    fi
    nohup google-chrome \
        --remote-debugging-port="$CDP_PORT" \
        --user-data-dir="$CHROME_DATA_DIR" \
        --window-size=1280,900 \
        >/dev/null 2>&1 &
    sleep 2
    if curl -sf "http://localhost:$CDP_PORT/json/version" >/dev/null 2>&1; then
        green "✓ Chrome CDP started on :$CDP_PORT"
    else
        red "✗ Chrome CDP failed to start"
        dim "  Try manually: google-chrome --remote-debugging-port=$CDP_PORT --user-data-dir=$CHROME_DATA_DIR"
    fi
fi

echo
dim "Navigate to the app in the CDP Chrome window and press Ctrl+Shift+F"
