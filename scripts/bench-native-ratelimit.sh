#!/usr/bin/env bash
#
# World-B step 3 benchmark: native (Rust) rate limiting vs the PHP middleware.
#
# Two measurements:
#   1. Coherence — `ratelimit:60` with N workers, 80 requests from one IP:
#        BEFORE (FIUM_NATIVE_RATELIMIT=0): per-worker counters -> ~all pass (limit x N).
#        AFTER  (FIUM_NATIVE_RATELIMIT=1): pool-wide counter   -> exactly 60 pass, 20 x 429.
#   2. Rejection throughput + pool protection — flood `ratelimit:1`:
#        RPS of the 429 flood (Rust ~9x faster than PHP), and fium_requests_total delta
#        (AFTER ~1 = pool protected; BEFORE ~all = every 429 still hits PHP).
#
# Usage: scripts/bench-native-ratelimit.sh
# Env: FIUM_BIN, FIUM_FLOOD_REQUESTS, FIUM_CONCURRENCY, FIUM_COHERE, FIUM_WORKERS, FIUM_PORT

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BIN="${FIUM_BIN:-$ROOT/target/release/fium}"
APP="$ROOT/php/bench.php"
HOST=127.0.0.1
PORT="${FIUM_PORT:-3808}"
WORKERS="${FIUM_WORKERS:-4}"
COHERE="${FIUM_COHERE:-80}"
FLOOD_REQUESTS="${FIUM_FLOOD_REQUESTS:-8000}"
CONCURRENCY="${FIUM_CONCURRENCY:-16}"
BASE_URL="http://$HOST:$PORT"

command -v ab >/dev/null   || { echo "missing: ab" >&2; exit 1; }
command -v curl >/dev/null || { echo "missing: curl" >&2; exit 1; }
[[ -x "$BIN" ]]            || { echo "release binary missing: $BIN" >&2; exit 1; }

SERVER_PID=""
cleanup() { [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true; }
trap cleanup EXIT

wait_for_ready() {
  for ((i = 0; i < 60; i++)); do
    curl -sf -o /dev/null "$BASE_URL/healthz" && return 0
    sleep 0.25
  done
  echo "server not ready on $BASE_URL" >&2; exit 1
}

start_server() {
  local native="$1"
  SERVER_PID=$(
    FIUM_NATIVE_RATELIMIT="$native" FIUM_DEBUG=1 RUST_LOG=error \
      "$BIN" serve "$APP" --host "$HOST" --port "$PORT" --workers "$WORKERS" \
      > /tmp/fium_rl_server.log 2>&1 &
    echo $!
  )
  wait_for_ready
  for _ in $(seq 1 80); do curl -sf -o /dev/null "$BASE_URL/bench/json" || true; done
}

stop_server() {
  [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true
  wait "$SERVER_PID" 2>/dev/null || true
  SERVER_PID=""
}

requests_total() { curl -s "$BASE_URL/_fium/metrics" | awk '/^fium_requests_total/ {print $2}'; }

# Coherence: send $COHERE sequential requests to a limit-60 route, count statuses.
coherence() {
  local out
  out=$(for ((i = 0; i < COHERE; i++)); do curl -s -o /dev/null -w "%{http_code}\n" "$BASE_URL/bench/limited"; done \
        | sort | uniq -c | awk '{ printf "%s:%s ", $2, $1 }')
  printf 'coherence(limited, ratelimit:60, %s reqs)\t%s\n' "$COHERE" "$out"
}

# Flood: rejection throughput + pool protection.
flood() {
  local before after rps p50 p99
  before=$(requests_total)
  ab -n "$FLOOD_REQUESTS" -c "$CONCURRENCY" -k -q -e /tmp/fium_rl_pct.csv \
    "$BASE_URL/bench/flood" > /tmp/fium_rl_ab.txt 2>&1 || { cat /tmp/fium_rl_ab.txt >&2; exit 1; }
  after=$(requests_total)
  rps=$(grep  'Requests per second' /tmp/fium_rl_ab.txt | awk '{print $4}')
  p50=$(awk -F, 'NR>1 && $1==50 {print $2}' /tmp/fium_rl_pct.csv)
  p99=$(awk -F, 'NR>1 && $1==99 {print $2}' /tmp/fium_rl_pct.csv)
  printf 'flood(ratelimit:1, %s reqs)\trps=%s\tp50=%s\tp99=%s\tphp_hits=%s\n' \
    "$FLOOD_REQUESTS" "${rps:-NA}" "${p50:-NA}" "${p99:-NA}" "$((after - before))"
}

echo "# Fium World-B native rate-limit benchmark"
echo "# binary=$BIN workers=$WORKERS ($(date -u '+%Y-%m-%dT%H:%M:%SZ'))"
echo "#"

run_mode() {
  local mode="$1" native="$2"
  start_server "$native"
  printf '## mode=%s (FIUM_NATIVE_RATELIMIT=%s)\n' "$mode" "$native"
  coherence
  flood
  stop_server
  echo "#"
}

run_mode "PHP"  0
run_mode "Rust" 1

echo "# coherence: PHP lets ~all through (per-worker x N); Rust allows exactly the configured limit."
echo "# flood:     Rust serves 429s ~9x faster AND php_hits stays ~1 (pool protected);"
echo "#            in PHP mode every rejected request still costs a PHP round-trip."
