# World-B Benchmark: CORS preflight served from Rust vs PHP

This benchmark validates the World-B thesis: **framework work done in Rust is dramatically
cheaper than the same work done after crossing into PHP**, because every PHP request pays a
serialize → pipe → deserialize round-trip that a Rust-served request does not.

It is **milestone #1** of the World-B plan: prove the architecture pays off *before*
rebuilding the wire protocol or moving more middleware across.

## The probe

The CORS preflight (`OPTIONS` to a cors-protected path) is the cleanest probe:

- It's a real, common request class (every cross-origin browser request sends one).
- Its response is pure header computation — no business logic — so it's a fair thing to
  serve without PHP.
- Both paths return **byte-identical** responses (`204` + the four CORS headers), so the
  latency delta isolates *who serves it*, not *what is served*.

### Bonus finding: preflight is effectively broken today

In the current architecture, route matching is keyed by HTTP method (`route.rs`), so an
`OPTIONS` request to a `POST /bench/cors` route returns **404 at the Rust layer before PHP
is ever consulted**. The PHP `Cors` middleware's preflight branch is therefore unreachable
for typical setups. The benchmark routes register an explicit `OPTIONS` handler as the
"Before" path — i.e. the workaround a user must write *today* to make preflight work at all.
Serving it from Rust (the "After" path) fixes correctness **and** removes the round-trip.

## Setup

- **Workloads** (`php/bench.php`):
  - `GET /bench/json` — trivial PHP handler. **Control**: always crosses to PHP in both
    modes, so it should be ~unchanged across modes (proves the toggle is scoped).
  - `OPTIONS /bench/cors` — the probe. `FIUM_NATIVE_CORS=0` dispatches to the explicit PHP
    handler; `FIUM_NATIVE_CORS=1` is answered by Rust (`main.rs::native_cors_preflight_response`).
- **Toggle**: `FIUM_NATIVE_CORS` (`config.rs`). Same release binary runs both modes.
- **Load**: `ab -n 8000 -c 16 -k`, after an 80-request warmup. Env: `FIUM_DEBUG=1` (so the
  worker boots with an ephemeral signing secret). `--workers 4`.
- **Machine**: 8 cores, release build (`cargo build --release`), PHP 8.4, localhost loopback.
- **Harness**: `scripts/bench-native-cors.sh`.

`ab` is a single-process load generator (less capable than `wrk`); treat these as
**relative** numbers valid for within-Fium comparison on localhost, not absolute claims
versus other runtimes.

## Results (representative; stable across 3 runs, ±3%)

| Workload | Mode | RPS | mean (ms) | p50 (ms) | p99 (ms) |
|---|---|---:|---:|---:|---:|
| `GET /bench/json` (control) | PHP | 37,761 | 0.424 | 0.403 | 0.844 |
| `GET /bench/json` (control) | Rust-mode | 37,401 | 0.428 | 0.413 | 0.799 |
| `OPTIONS /bench/cors` | **PHP path** | 36,414 | 0.439 | 0.427 | 0.805 |
| `OPTIONS /bench/cors` | **Rust path** | **321,931** | **0.050** | **0.038** | **0.141** |

- **Throughput: ~8.9× higher** (36.4k → 322k req/s).
- **Mean latency: ~8.8× lower** (0.439ms → 0.050ms).
- **p50: ~11× lower**; **p99: ~5.7× lower**.
- **Control unchanged** between modes (37.8k vs 37.4k, ~1%): the toggle affects only
  preflight, confirming the delta is the PHP round-trip, not noise.

## Interpretation

The PHP-served preflight (~0.44ms) sits right next to the PHP-served control route (~0.42ms):
**crossing into PHP costs ~0.39ms per request regardless of how little the handler does** —
that is the JSON-over-stdio IPC tax (`serde_json` encode of the request → pipe → PHP
`json_decode` → execute → `json_encode` the response → pipe → `serde_json` decode). The
Rust-served preflight (~0.05ms) reveals the floor: what the same response costs when computed
inside the runtime with no IPC.

The strategic read: **for every request class whose response can be produced in Rust — CORS
preflight, `401`/`403`/`429` rejections, security-header-only routes, health ticks, static —
World-B serves it ~9× faster and lets PHP sit idle.** That is the differentiator made
measurable, and it's the basis for the next steps.

## Known limitations of this spike

1. **Static cors paths only.** `RouteTable::cors_preflight_target` does exact-path matching;
   parameterized cors routes (e.g. `POST /api/users/{id}` with cors) won't match a preflight
   to `/api/users/42`. Generalizing means matching the route *pattern* — straightforward but
   out of scope for the proof.
2. **Discovered robustness bug.** A PHP fatal during worker *boot* is written to **stdout**
   and corrupts the frame protocol (Rust then reads the first 4 bytes of the error text as a
   frame length, e.g. `\nFat` → 172,384,628 bytes → "frame too large"). PHP fatal output
   should go to stderr (or Rust should detect a dead/garbling worker). Logged for the
   worker-hardening task.
3. **PHP opcache not explicitly enabled** for the workers (`php_binary` is hardcoded with no
   flags). Both PHP-bearing workloads use the same PHP config, so the comparison is fair, but
   absolute PHP numbers would improve with opcache.
4. **`ab` caveats** noted above; re-run with `wrk`/`hey` for publication-grade numbers.

## Reproduce

```bash
cargo build --release
scripts/bench-native-cors.sh

# knobs
FIUM_REQUESTS=20000 FIUM_CONCURRENCY=32 scripts/bench-native-cors.sh
```

## Next World-B steps (in priority order)

1. **Wire protocol** — replace JSON-over-stdio with a binary format over a Unix domain socket
   (or shared memory), retiring the per-request serialize/parse tax for the requests that
   *do* still need PHP.
2. **Stateful middleware into Rust** — move rate-limit and session state into the single Rust
   process; this makes them coherent across the worker pool (fixing the `ratelimit × N`
   behavior) and is the same kind of win this benchmark demonstrates.
3. **Hybrid middleware engine** — built-in middleware run in Rust (fast path); user-written
   middleware still runs in PHP, crossing the boundary only when present.
