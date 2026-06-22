#!/usr/bin/env bash
#
# Profile the per-request IPC tax on /bench/json to decide whether serialization is the
# dominant cost (i.e. whether a binary wire format would pay off).
#
# Instruments both sides (FIUM_PROFILE) and prints a stage breakdown:
#   Rust: encode (serde_json req) | write (pipe) | read (wait) | decode (serde_json resp)
#   PHP:  read (fread) | decode (json_decode) | dispatch (handler) | encode (json_encode) | write (fwrite)
# Then: serialization = rust_encode + rust_decode + php_decode + php_encode.
#
# Usage: scripts/profile-ipc.sh   (env: FIUM_BIN, FIUM_PROFILE_REQUESTS, FIUM_PORT)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BIN="${FIUM_BIN:-$ROOT/target/release/fium}"
APP="$ROOT/php/bench.php"
HOST=127.0.0.1
PORT="${FIUM_PORT:-3811}"
REQUESTS="${FIUM_PROFILE_REQUESTS:-6000}"
WORKERS="${FIUM_PROFILE_WORKERS:-4}"
CONCURRENCY="${FIUM_PROFILE_CONCURRENCY:-32}"
LOG=/tmp/fium_profile_ipc.log

command -v ab >/dev/null || { echo "missing: ab" >&2; exit 1; }
[[ -x "$BIN" ]] || { echo "release binary missing: $BIN" >&2; exit 1; }

SERVER_PID=""
cleanup() { [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true; }
trap cleanup EXIT

# Single profiled server: FIUM_PROFILE turns on both-sided timing; RUST_LOG surfaces the
# fium_profile target (Rust) and php_worker target (PHP stderr pump) without the per-request
# info spam.
FIUM_PROFILE=1 FIUM_DEBUG=1 RUST_LOG='error,fium_profile=info,php_worker=warn' \
  "$BIN" serve "$APP" --host "$HOST" --port "$PORT" --workers "$WORKERS" \
  > "$LOG" 2>&1 &
SERVER_PID=$!

for ((i = 0; i < 60; i++)); do
  curl -sf -o /dev/null "http://$HOST:$PORT/healthz" && break
  sleep 0.25
done

# Concurrent flood keeps workers busy (no idle fread blocking), so read/total stages
# reflect real cost, not wait time. Profiling accumulates from boot; the final cumulative
# line is steady-state. Rust PROFILE aggregates across workers; each PHP worker emits its own.
ab -n "$REQUESTS" -c "$CONCURRENCY" -k -q "http://$HOST:$PORT/bench/json" > /dev/null 2>&1 || true
sleep 0.4
kill "$SERVER_PID" 2>/dev/null || true
wait "$SERVER_PID" 2>/dev/null || true

RUST_LINE=$(grep 'PROFILE_RUST' "$LOG" | tail -1 || true)
PHP_LINE=$(grep 'PROFILE_PHP' "$LOG" | tail -1 || true)

val() { printf '%s' "$1" | grep -oP "$2=\K[0-9]+" | head -1 || true; }

if [[ -z "$RUST_LINE" || -z "$PHP_LINE" ]]; then
  echo "no PROFILE lines found — server may not have profiled. Log tail:" >&2
  tail -20 "$LOG" >&2
  exit 1
fi

renc=$(val "$RUST_LINE" encode); rwri=$(val "$RUST_LINE" write); rrea=$(val "$RUST_LINE" read); rdec=$(val "$RUST_LINE" decode)
pread=$(val "$PHP_LINE" read);   pdec=$(val "$PHP_LINE" decode); pdis=$(val "$PHP_LINE" dispatch); penc=$(val "$PHP_LINE" encode); pwri=$(val "$PHP_LINE" write)

us() { awk -v n="$1" 'BEGIN{printf "%.1f", n/1000}'; }

serialization=$((renc + rdec + pdec + penc))
rust_total=$((renc + rwri + rrea + rdec))
php_total=$((pread + pdec + pdis + penc + pwri))
ser_pct=$(awk -v s="$serialization" -v t="$rust_total" 'BEGIN{printf "%.0f", (s/t)*100}')
phpser=$((pdec + penc))
phpser_pct=$(awk -v s="$phpser" -v t="$php_total" 'BEGIN{printf "%.0f", (s/t)*100}')

cat <<EOF
=== IPC breakdown — /bench/json, workers=$WORKERS, concurrency=$CONCURRENCY, N=$REQUESTS (mean ns/req) ===

RUST side:
  encode  (serde_json -> request)    : ${renc} ns  ($(us "$renc") µs)
  write   (pipe write + flush)       : ${rwri} ns  ($(us "$rwri") µs)
  read    (pipe read — waits on PHP) : ${rrea} ns  ($(us "$rrea") µs)
  decode  (serde_json <- response)   : ${rdec} ns  ($(us "$rdec") µs)
  rust total (per-request, Rust)     : ${rust_total} ns  ($(us "$rust_total") µs)

PHP side:
  read    (fread)                    : ${pread} ns  ($(us "$pread") µs)
  decode  (json_decode request)      : ${pdec} ns  ($(us "$pdec") µs)
  dispatch (handler + middleware)    : ${pdis} ns  ($(us "$pdis") µs)
  encode  (json_encode response)     : ${penc} ns  ($(us "$penc") µs)
  write   (fwrite + flush)           : ${pwri} ns  ($(us "$pwri") µs)
  php total (per-request, PHP)       : ${php_total} ns  ($(us "$php_total") µs)

VERDICT
  serialization (rust enc+dec + php enc+dec) = ${serialization} ns ($(us "$serialization") µs)
    -> ${ser_pct}% of the per-request Rust-side cost (${rust_total} ns)
  PHP-side serialization alone (json_decode + json_encode) = ${phpser} ns = ${phpser_pct}% of PHP work
  handler dispatch = ${pdis} ns ($(us "$pdis") µs)

  Cross-check: rust read (${rrea}) should ~= php total (${php_total}) + pipe latency.
EOF
