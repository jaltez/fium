#!/usr/bin/env bash

set -euo pipefail

url="${1:-http://127.0.0.1:3000/hello/World}"
requests="${2:-40}"
concurrency="${3:-8}"

if ! [[ "$requests" =~ ^[0-9]+$ ]] || [[ "$requests" -le 0 ]]; then
    echo "requests must be a positive integer" >&2
    exit 1
fi

if ! [[ "$concurrency" =~ ^[0-9]+$ ]] || [[ "$concurrency" -le 0 ]]; then
    echo "concurrency must be a positive integer" >&2
    exit 1
fi

results_file="$(mktemp)"
trap 'rm -f "$results_file"' EXIT

export FIUM_LOAD_URL="$url"

seq 1 "$requests" | xargs -P "$concurrency" -I{} sh -c '
    curl -sS -o /dev/null -w "%{http_code} %{time_total}\n" "$FIUM_LOAD_URL"
' >> "$results_file"

awk -v requests="$requests" -v concurrency="$concurrency" -v url="$url" '
BEGIN {
    min = -1
    max = 0
    total = 0
    ok = 0
}
{
    status[$1] += 1
    if ($1 == 200) {
        ok += 1
    }
    time = $2 + 0
    total += time
    if (min < 0 || time < min) {
        min = time
    }
    if (time > max) {
        max = time
    }
}
END {
    printf("Concurrent probe for %s\n", url)
    printf("requests=%s concurrency=%s ok=%d avg=%.6fs min=%.6fs max=%.6fs\n", requests, concurrency, ok, total / requests, min, max)
    printf("status_counts=")
    first = 1
    for (code in status) {
        if (!first) {
            printf(",")
        }
        printf("%s:%d", code, status[code])
        first = 0
    }
    printf("\n")
}
' "$results_file"