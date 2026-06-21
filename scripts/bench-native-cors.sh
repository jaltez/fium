#!/usr/bin/env bash
#
# World-B thesis proof: how much does a request cost when it has to cross into PHP
# versus when Rust serves it directly?
#
# Probe: the CORS preflight (OPTIONS to a cors-protected path).
#   BEFORE (FIUM_NATIVE_CORS=0): Rust dispatches OPTIONS to a PHP handler that returns 204.
#   AFTER  (FIUM_NATIVE_CORS=1): Rust answers 204 itself; PHP is never touched.
# Both paths return the same bytes, so the latency delta isolates the PHP round-trip.
#
# Control: GET /bench/json always crosses to PHP in both modes — it should be ~unchanged,
# proving the toggle is scoped to preflight only.
#
# Usage: scripts/bench-native-cors.sh
# Env overrides: FIUM_BIN, FIUM_REQUESTS, FIUM_CONCURRENCY, FIUM_WORKERS, FIUM_PORT

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BIN="${FIUM_BIN:-$ROOT/target/release/fium}"
APP="$ROOT/php/bench.php"
HOST=127.0.0.1
PORT="${FIUM_PORT:-3803}"
WORKERS="${FIUM_WORKERS:-4}"
REQUESTS="${FIUM_REQUESTS:-8000}"
CONCURRENCY="${FIUM_CONCURRENCY:-16}"
BASE_URL="http://$HOST:$PORT"

command -v ab >/dev/null   || { echo "missing: ab (apache2-utils)" >&2; exit 1; }
command -v curl >/dev/null || { echo "missing: curl" >&2; exit 1; }
[[ -x "$BIN" ]]            || { echo "release binary missing: $BIN (cargo build --release)" >&2; exit 1; }

SERVER_PID=""
cleanup() { [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true; }
trap cleanup EXIT

wait_for_ready() {
  local i
  for ((i = 0; i < 60; i++)); do
    if curl -sf -o /dev/null "$BASE_URL/healthz"; then return 0; fi
    sleep 0.25
  done
  echo "server did not become ready on $BASE_URL" >&2
  exit 1
}

start_server() {
  local native="$1"
  SERVER_PID=$(
    FIUM_NATIVE_CORS="$native" FIUM_DEBUG=1 RUST_LOG=error \
      "$BIN" serve "$APP" --host "$HOST" --port "$PORT" --workers "$WORKERS" \
      > /tmp/fium_bench_server.log 2>&1 &
    echo $!
  )
  wait_for_ready
  # Warm the PHP pool (opcache/file cache) so measurements are steady-state.
  for _ in $(seq 1 80); do curl -sf -o /dev/null "$BASE_URL/bench/json" || true; done
}

stop_server() {
  [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true
  wait "$SERVER_PID" 2>/dev/null || true
  SERVER_PID=""
}

# measure <label> <path> <ab-method-args...>
# Prints: label <tab> rps <tab> mean_ms <tab> p50_ms <tab> p99_ms
measure() {
  local label="$1" path="$2"; shift 2
  ab -n "$REQUESTS" -c "$CONCURRENCY" -k -q -e /tmp/fium_pct.csv "$@" "$BASE_URL$path" \
    > /tmp/fium_ab.txt 2>&1 || { echo "ab failed for $label:" >&2; cat /tmp/fium_ab.txt >&2; exit 1; }

  local rps mean p50 p99
  rps=$(grep  'Requests per second' /tmp/fium_ab.txt | awk '{print $4}')
  mean=$(grep 'Time per request'    /tmp/fium_ab.txt | head -1 | awk '{print $4}')
  p50=$(awk -F, 'NR>1 && $1==50 {print $2}' /tmp/fium_pct.csv)
  p99=$(awk -F, 'NR>1 && $1==99 {print $2}' /tmp/fium_pct.csv)
  printf '%s\t%s\t%s\t%s\t%s\n' "$label" "${rps:-NA}" "${mean:-NA}" "${p50:-NA}" "${p99:-NA}"
}

PREFLIGHT_ARGS=(-m OPTIONS -H "Origin: http://localhost" -H "Access-Control-Request-Method: POST")

echo "# Fium World-B preflight benchmark"
echo "# binary=$BIN workers=$WORKERS requests=$REQUESTS concurrency=$CONCURRENCY"
echo "# ($(date -u '+%Y-%m-%dT%H:%M:%SZ'))"
echo "#"

run_mode() {
  local mode="$1" native="$2"
  start_server "$native"
  printf '## mode=%s (FIUM_NATIVE_CORS=%s)\n' "$mode" "$native"
  measure "json(control,PHP)"      "/bench/json"
  measure "preflight(-> $mode)"    "/bench/cors" "${PREFLIGHT_ARGS[@]}"
  stop_server
  echo "#"
}

run_mode "PHP"   0
run_mode "Rust"  1

echo "# json is the control (always PHP) — its two numbers should be close."
echo "# preflight PHP vs preflight Rust is the World-B win."
