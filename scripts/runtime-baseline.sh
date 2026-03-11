#!/usr/bin/env bash

set -euo pipefail

url="${1:-http://127.0.0.1:3000/hello/World}"
requests="${2:-20}"
runtime_pid="${3:-}"

if ! [[ "$requests" =~ ^[0-9]+$ ]] || [[ "$requests" -le 0 ]]; then
    echo "requests must be a positive integer" >&2
    exit 1
fi

if [[ -z "$runtime_pid" ]]; then
    runtime_pid="$(pgrep -n -f '/fium' || true)"
fi

read_rss_kb() {
    local pid="$1"

    if [[ -z "$pid" ]]; then
        echo "n/a"
        return
    fi

    ps -o rss= -p "$pid" 2>/dev/null | awk '{print $1}'
}

before_rss="$(read_rss_kb "$runtime_pid")"
results_file="$(mktemp)"
trap 'rm -f "$results_file"' EXIT

for _ in $(seq 1 "$requests"); do
    curl -sS -o /dev/null -w '%{http_code} %{time_total}\n' "$url" >> "$results_file"
done

after_rss="$(read_rss_kb "$runtime_pid")"

awk -v requests="$requests" -v url="$url" -v before_rss="$before_rss" -v after_rss="$after_rss" '
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
    printf("Baseline probe for %s\n", url)
    printf("requests=%s ok=%d avg=%.6fs min=%.6fs max=%.6fs\n", requests, ok, total / requests, min, max)
    printf("rss_kb_before=%s rss_kb_after=%s\n", before_rss, after_rss)
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