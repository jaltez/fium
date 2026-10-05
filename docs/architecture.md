# Architecture

Fium is a single-binary PHP runtime with a hybrid architecture: a **Rust**
process owns the HTTP server and infrastructure, and one or more **PHP** worker
processes own business logic. The two communicate over a versioned,
length-prefixed JSON protocol on stdio.

```
                   ┌──────────────────────── fium (Rust binary) ────────────────────────┐
 HTTP request  ──► │  Axum server → router → route match → WorkerPool → WorkerSupervisor  │
                   │                                                          │           │
                   │   embed.rs extracts worker.php + src/ to .fium/  ───────┘           │
                   └───────────────────────────────┬───────────────────────────────────┘
                                                   │ stdin (length-prefixed JSON request)
                                                   ▼
                                          ┌─────────────────────┐
                                          │  PHP worker process  │
                                          │  worker.php          │
                                          │   └ autoload Fium\  │
                                          │   └ Application::boot│
                                          │   └ middleware chain  │
                                          │   └ Response          │
                                          └─────────┬─────────────┘
                                  stdout (length-prefixed JSON response) ▲
```

## The two-process model

**Rust runtime** (`crates/runtime/`) is the only thing the operator runs. It:

- Binds the listening socket and terminates TLS.
- Matches incoming requests against the route table.
- Buffers request bodies (in memory up to 64 KB, spilling to disk above that).
- Supervises a pool of PHP worker processes, distributing requests with
  queue-aware sampling.
- Applies gzip/Brotli compression, serves static files, and exposes
  `/health` and `/_fium/metrics` (Prometheus).
- Owns graceful shutdown, worker recycling, and crash recovery.

**PHP workers** (`php/`) are spawned by the runtime and run `worker.php`. Each
worker:

- Boots the `Application` from the user's `app.php` and emits a **boot message**
  describing every route.
- Reads request frames from stdin one at a time, runs the middleware chain +
  handler, and writes a response frame to stdout.

Workers handle a single request concurrently; horizontal throughput comes from
the pool. The pool recycles a worker after `max_requests` and restarts it on
crash, so a bug in one handler can never take the server down.

## Protocol (V1)

The wire format is a sequence of **length-prefixed JSON frames**: a 4-byte
big-endian `u32` length header followed by that many bytes of UTF-8 JSON. This
is deliberately text-based JSON; debuggability was chosen over raw throughput
during the feasibility phase. The framing itself is binary, so partial reads
and large payloads are handled cleanly. See [Protocol V1 Specification](protocol-v1.md).

Two message kinds cross the boundary:

- **Boot**: the worker's first stdout frame: `{ protocol_version, type: "boot",
  routes: [...] }`. The runtime builds its `RouteTable` from it.
- **Request / Response**: the runtime sends a `WorkerRequest`; the worker
  replies with a `WorkerResponse` carrying status, headers, cookies, body, and
  an optional error envelope.

Protocol version mismatches are rejected on both sides, so the contract can
evolve explicitly.

## Embedding and the zero-Composer-dependency rule

`crates/runtime/src/embed.rs` compiles **only `php/worker.php` and `php/src/`**
into the binary with `include_str!` / `include_dir!`. On startup it extracts
them to a `.fium/` directory next to the app file and skips re-extraction when
`.fium/.version` (an FNV hash of the embedded tree) matches.

Consequence: **the embedded framework must be self-contained.** `worker.php`
registers a single autoloader (`Fium\` → `src/`) and never loads
`vendor/autoload.php`. Composer dependencies are not embedded, so an external
package would be invisible to a deployed worker. Every feature added to
`php/src/` is therefore a pure-PHP, dependency-free implementation, including
the query builder, the active-record `Model`, and the cache drivers. The
optional Redis cache driver uses the `redis` PHP *extension* (loaded into the
interpreter) rather than a Composer package.

## Request lifecycle

1. Rust accepts a connection and reads the request.
2. `RouteTable::match_route(method, path)` resolves the handler name and path
   parameters.
3. `WorkerPool` picks a worker (two-candidate sampling, preferring the shorter
   queue) and sends a `WorkerRequest` frame.
4. The worker's `Application::handleWorkerRequest`:
   - Looks up the pre-compiled middleware chain for the route.
   - Builds a `Request`, attaches the authenticator, token service, and base
     directory.
   - Runs the chain: each `Middleware::handle($request, $next)` wraps the next
     until the handler produces a `Response`.
   - Catches any `Throwable` and converts it to a 500 (message hidden unless
     `FIUM_DEBUG=true`).
5. The `Response` is serialized to a `WorkerResponse` frame and written back.
6. Rust applies compression, writes the HTTP response, and records metrics.

## Middleware pipeline

Middleware are referenced by alias (e.g. `csrf`, `auth`, `ratelimit:60`).
Aliases resolve to class names, are instantiated once and cached for the
worker's lifetime, and compiled into a single closure chain at boot, so per-
request dispatch is a straight run down the chain with no alias resolution.
Middleware groups (`middleware_groups` in the routes file) expand recursively
with cycle detection.

## Data layer

The framework ships a small, safe data layer that respects the
zero-Composer-dependency rule:

- `Fium\Database\Connection`: a lazily-configured PDO singleton, driven by
  `FIUM_DB_*` environment variables or explicit injection.
- `Fium\Database\QueryBuilder`: a fluent builder. **Values are always bound
  as parameters**; identifiers are validated against a strict charset and used
  verbatim (SQL has no parameterization for identifiers).
- `Fium\Database\Model`: an active-record base with `find`, `all`, `create`,
  `save`, `delete`, and a fillable mass-assignment allow-list.
- `Fium\Cache\CacheManager` resolves a cache driver from `FIUM_CACHE_DRIVER`:
  `array` (default), `file`, or `redis` (optional, via phpredis).

None of these require an external package; the Redis driver relies on the
`redis` PHP extension being present in the interpreter, which is independent of
the embedded framework.
