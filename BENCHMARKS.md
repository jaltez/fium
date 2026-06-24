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

---

# World-B step 3: native rate limiting

Rate limiting is the strongest case for moving a *stateful* middleware into Rust, because it
stacks three wins at once: a correctness fix, an availability guarantee, and a speed delta —
all from the same change.

The PHP `RateLimit` middleware keeps its counters in process-local memory, so with `N`
workers the configured limit is enforced `N` times (documented at `docs/middleware.md`). Worse,
a rejected request still costs a full PHP round-trip, so **a flood to a rate-limited route
loads the PHP pool** — the opposite of what rate limiting is for. Moving enforcement into the
one Rust process that owns the pool fixes both: counters are coherent across workers, and
`429`s are served without touching PHP.

## Setup

- **Workloads** (`php/bench.php`):
  - `GET /bench/limited` (`ratelimit:60`) — coherence probe.
  - `GET /bench/flood` (`ratelimit:1`) — rejection-throughput + pool-protection probe.
- **Toggle**: `FIUM_NATIVE_RATELIMIT` (`config.rs`). The PHP middleware defers to Rust when
  this is set (`RateLimit.php`), so the two paths don't double-count. Same release binary,
  same `--workers 4`.
- **Harness**: `scripts/bench-native-ratelimit.sh`.
- **Limiter**: `ratelimit.rs` — a `Mutex<HashMap<(route, ip), Bucket>>`, keyed per route+IP
  (an intentional improvement over the PHP middleware's per-IP-only keying, which shared one
  counter across routes with different limits). Unlike the CORS spike, lookups are by route
  name, so **dynamic/parameterized routes are fully supported** — no static-path limitation.

## Results (stable across runs; coherence is deterministic)

**Coherence** — 80 requests to `ratelimit:60` from one IP, 4 workers:

| Mode | 200 | 429 | Effective limit |
|---|---:|---:|---|
| PHP (per-worker) | 80 | 0 | ~240 (= 60 × 4) — **broken** |
| Rust (pool-wide) | **60** | **20** | exactly 60 — as configured |

**Rejection throughput + pool protection** — flood `ratelimit:1`, 8000 reqs, concurrency 16:

| Mode | RPS | p50 (ms) | p99 (ms) | PHP hits |
|---|---:|---:|---:|---:|
| PHP (429 via PHP) | 41,500 | 0.38 | 0.69 | **8000** |
| Rust (429 via Rust) | **328,000** | **0.036** | **0.15** | **1** |

- **~8× higher rejection throughput** (41.5k → 328k req/s).
- **`php_hits`: 8000 → 1.** Under an 8000-request flood to a rate-limited route, PHP handles
  **one** request in Rust mode vs **eight thousand** in PHP mode. The rate limiter only
  actually protects the PHP pool when Rust enforces it — this is the headline result.
- **Exact enforcement**: the configured limit is honored precisely regardless of worker count.

## Interpretation

This is the same IPC tax the CORS benchmark isolated (~0.39ms/PHP round-trip), now applied to
the rejection path — but the more important numbers are the non-speed ones. Coherence makes
`ratelimit:60` mean 60, not 60×N. And `php_hits=1` under an 8000-request flood is the
availability argument: native rate limiting turns a denial-of-service vector (a flood to any
limited endpoint) into a few hundred microseconds of Rust work with the PHP pool idle.

It also de-risks step 4 (the hybrid middleware engine): the `Mutex<HashMap>` shared-state
pattern holds up under concurrency — the `-c 16` flood allows exactly one request through,
proving the lock serializes the check correctly across simultaneous connections.

## Limitations

1. **In-memory only.** Counters live in the Rust process; they don't survive a restart and
   aren't shared across multiple Fium instances behind a load balancer. A Redis/shared backend
   is the production story for multi-instance deployments (same gap the PHP version has).
2. **Fixed window.** Matches the PHP middleware's fixed-window algorithm; no sliding window.
3. **Per-(route, IP) keying** differs from the PHP per-IP-only keying (deliberate — see above).
4. **`ab` caveats** as in the CORS section.

## Production-readiness blockers (before either native toggle defaults on)

Tracked from code review. Both toggles ship **off by default**, so these are latent, not live.

- **[fixed] CORS `.env` divergence** (`main.rs::native_cors_preflight_response`) — Rust now reads
  CORS config from the boot manifest (`BootCors`), which PHP resolves from `.env`/Config. A
  `.env`-configured origin is now honored by the native path instead of being silently turned
  into `*`.
- **[fixed] Purge evicted non-expired buckets** (`ratelimit.rs`) — purge now uses each bucket's
  own window, so long-window routes (e.g. `ratelimit:N,86400`) are no longer under-limited once
  the map exceeds 4096 entries.
- **[fixed] Single global `Mutex`** (`ratelimit.rs`) — state is now sharded across 16
  mutexes keyed by `(route, ip)` hash, so each request locks only its shard.
- **[fixed] O(n) purge under the lock** — the memory-bounding `retain()` is now time-gated
  (at most once per minute per shard, only past the threshold), so a high-churn IP set can't
  turn the limiter into a per-request DoS.
- **[fixed] No automated dispatch integration tests** — `tests/native_middleware.rs` now
  boots the real binary and asserts the native CORS 204 / rate-limit 429 + pool protection.

## Reproduce

```bash
cargo build --release
scripts/bench-native-ratelimit.sh
```

## World-B roadmap status

1. ✅ **Benchmark + one Rust middleware** (CORS preflight — see above)
2. ✅ **Stateful middleware into Rust** (rate-limit; session remains)
3. ❌ **Wire protocol (binary framing)** — **investigated and deprioritized**: serialization is
   only ~11µs/req (~1–3% of end-to-end), so binary framing isn't worth a zero-dep hand-rolled
   protocol. See "Wire-protocol investigation" below.
4. ⏳ **Hybrid middleware engine** — in progress. Three built-in middleware now run in Rust
   (CORS preflight, rate-limit, security-headers), each gated per-feature and each with the PHP
   side deferring when native. See "Hybrid middleware engine" below for the design + what
   remains to make it a general mechanism.

## Hybrid middleware engine (design + status)

Goal: built-in middleware run in Rust (no PHP round-trip for their effect); only
user-written middleware + the handler cross into PHP. Today this exists as **point
solutions** — three specific middleware moved over — not yet a general engine.

**Native middleware today** (each `FIUM_NATIVE_*`, off by default, PHP side defers):

| Middleware | Path exercised | Effect |
|---|---|---|
| CORS preflight | short-circuit | `204` served from Rust; PHP never touched |
| rate-limit | short-circuit + state | `429` from Rust; pool-wide counters; PHP pool protected under flood |
| security-headers | response-mutation | headers stamped on the response in Rust; PHP middleware is a no-op |

**Two middleware shapes, both now demonstrated:**
- *Short-circuit* — Rust answers before PHP (preflight, 429, and the easy next ones: `require-json`
  406, a `health`-style fixed response). These give the big wins (eliminate the crossing).
- *Response-mutation* — Rust post-processes the PHP response (security headers, the
  `X-RateLimit-*` stamps). Smaller per-request win, but applies to every matching route.

**What remains to make it a general engine** (the substantive architectural work):

1. **Contract split.** Today Rust runs native middleware *and* PHP still runs the full chain
   (the PHP middleware just no-op when its native flag is on). A true engine has PHP run **only
   user middleware + handler** — the boot manifest must mark each middleware as built-in vs user,
   and PHP's compiled chain must exclude the built-in ones. That removes the (cheap, but real)
   PHP middleware hops for built-in middleware on every request.
2. **A Rust `NativeMiddleware` trait + registry** so adding the next ones (`require-json`,
   `powered-by`, CORS-on-actual-responses) is uniform instead of per-middleware bespoke code in
   `dispatch`.
3. **A unified toggle** (`FIUM_NATIVE=1` enabling all) instead of one flag per middleware.
4. **Auth short-circuits** (`401`/`403`) are the high-value next target but need shared user/token
   truth — the hardest piece, likely via a Rust-side cache fed by the PHP stores.

The per-feature wins are real and measured (9× preflight, 8× rate-limit, pool-protected); the
engine work above is about *generality and removing residual PHP hops*, not new perf cliffs.
5. 🚫 **Session into Rust** — **decided against after research.** Unlike rate-limit (per-worker
   in-memory counters → incoherent), sessions already use **shared file/DB stores** with no
   per-worker state, so they're already coherent across the pool. Moving them to Rust
   in-memory would *lose* persistence, *add* an IPC round-trip per request, and require
   porting flash/regenerate/remember-me — for no gain. Leave sessions in PHP (file/PDO),
   with Redis a future option if multi-instance is needed.

---

# Wire-protocol investigation: serialization is NOT the bottleneck

Before building a binary wire format, we profiled `/bench/json` to see where the per-request
IPC tax actually goes. The verdict flipped the plan: **serialization is a minor cost, so binary
framing isn't worth it. The lever that pays off is moving whole request classes to Rust.**

## Setup

`FIUM_PROFILE` instruments both sides of the round-trip with tight around-the-call timing
(`Instant` in Rust, `hrtime` in PHP), gated off by default. Harness: `scripts/profile-ipc.sh`,
`workers=4`, concurrency 32, 6000 requests. Stable across runs.

## Results (mean ns/request)

| Stage | Rust | PHP |
|---|---:|---:|
| encode (request)  | 1,700 | 4,700 (decode) |
| decode (response) | 3,100 | 1,200 (encode) |
| write (pipe+flush) | 16,000 | 6,000 |
| read  (fread/pipe) | 94,000 | 251,000 |
| dispatch (handler) | — | 8,900 |

## Interpretation

- **The `read` stages are idle/scheduling wait, not I/O cost.** The worker blocks in `fread`
  until Rust dispatches the next request (Rust pulls; the worker waits). This is true even at
  `-c32`, so the 94µs/251µs reads are the worker *not working*, not a real cost. Ignore them.
- **Tight-measured CPU costs per request**: serialization ≈ **10.8µs** (rust enc 1.7 + rust
  dec 3.1 + php dec 4.7 + php enc 1.3), pipe writes ≈ **22µs** (rust 16 + php 6), handler
  dispatch ≈ **9µs**.
- **Serialization is ~11µs** — ~26% of the measurable CPU work (~42µs), but only **~1–3% of
  the end-to-end latency** (~0.4ms). A binary format (≈2× faster encode/decode) would save
  ~5µs/request: <13% throughput in the CPU-bound regime, ~1% of end-to-end. **Not worth a
  ~400-line zero-dependency hand-rolled protocol with two-language lockstep risk.**
- Notably, **the pipe writes (syscalls + flush) cost more than the serialization** — so even
  the transfer, not the encoding, is the bigger crossing cost.

## Conclusion + redirect

The 9× (CORS) and 8× (rate-limit) wins came from **eliminating the crossing entirely** for
whole request classes, not from making the crossing cheaper. The data confirms that's the right
lever. Next priority is therefore the **hybrid middleware engine** (item 4): serve more request
classes from Rust (security headers, require-json, auth-bearer short-circuits, more) so fewer
requests pay *any* crossing cost.

**Caveat:** payloads here are small. For routes with very large request/response bodies,
serialization cost grows linearly and binary framing could matter more — worth re-measuring if
such workloads become the target. For typical JSON-API requests, it doesn't.

