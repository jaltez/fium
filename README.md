# Fium

> [!WARNING]
> ### Experimental: personal research project, archived (sunset)
> This repository is a **finished personal research experiment**, published as-is for
> educational reference. It is **not maintained**: no support, no releases, no issue
> triage, no security patches. It never reached a stable release and was never used
> in production by anyone, including the author.
>
> Do **not** use it for anything real. If you need a maintained PHP application
> server, use [FrankenPHP](https://frankenphp.dev), [RoadRunner](https://roadrunner.dev),
> [Swoole](https://www.swoole.co.uk), or plain PHP-FPM; see
> [Alternatives](#alternatives-if-you-need-something-maintained) below.
>
> Everything below describes what the project **was** at the moment of archival
> (June 2026), in past-tense spirit where it matters.

A faster PHP miniframework: Laravel-lite, powered by Rust. Write a single `app.php`,
run `fium serve`, done. Single binary; the framework ships inside it.

## What this repository is (and isn't)

Fium was a solo research project exploring one question:

> **How much of a PHP request can be served without crossing into PHP at all?**

The measured answer shaped the whole design: a JSON-over-stdio round-trip into a PHP
worker costs ~0.39 ms regardless of how little the handler does. Whole request classes
answered in Rust (CORS preflight, rate-limit rejections, static files, health ticks)
run ~8-9x faster. More importantly, stateful rate limiting enforced in the Rust
process is *coherent across the worker pool* (60 means 60, not 60×N workers) and
actually protects the PHP pool under flood (1 PHP hit per 8000 flooded requests,
versus 8000 when PHP enforces it).

The full investigation, including two ideas that were **dropped after profiling
showed they weren't worth it** (binary wire framing; moving sessions into Rust), is
documented with numbers in [BENCHMARKS.md](BENCHMARKS.md).

**What it isn't:** a product. The engine that would have generalized the early wins
(the "hybrid middleware engine") was left partially built. See
[Final state](#final-state-at-archival).

## Final state at archival

**Works end-to-end** (verified at archival): `fium init` scaffolding, `serve`/`dev`,
TLS, static files, compression, metrics, streaming/SSE relay, the embedded
zero-Composer PHP framework (sessions, auth, validation, query builder, cache), 56
Rust tests + PHPUnit suite with PHPStan level 8, full CI.

**Left unfinished** (the reason it was sunset rather than released):

- The **hybrid middleware engine** is point solutions, not a general mechanism:
  PHP still runs its (no-op'ing) middleware hops; no `NativeMiddleware` trait, no
  unified `FIUM_NATIVE` toggle, no auth short-circuits.
- **Rate-limit state is in-memory only**: not shared across instances behind a load
  balancer, not durable across restarts.
- Benchmarks are **intra-Fium only** with `ab` on loopback, without opcache; no
  claims versus FrankenPHP/RoadRunner were ever validated.
- No releases, no versioning discipline beyond the protocol version, single-author
  bus factor of 1.

## What it does

- **Rust binary** (axum/tokio) owns HTTP serving, route matching, TLS, static files,
  gzip/Brotli compression, worker supervision, and Prometheus metrics
- **PHP** owns business logic: handlers, middleware, sessions, auth, caching
- **Zero-Composer-dependency** PHP framework embedded in the binary
- **Zero-config** by default; optional `fium.toml` for power users

## Architecture

```
                   ┌──────────────────────── fium (Rust binary) ────────────────────────┐
 HTTP request  ──► │  Axum server → router → route match → WorkerPool → WorkerSupervisor │
                   │                                                          │          │
                   │   embed.rs extracts worker.php + src/ to .fium/  ────────┘          │
                   └───────────────────────────────┬────────────────────────────────────┘
                                                   ▼
                                    length-prefixed JSON frames over stdio
                                                   ▼
                                          ┌─────────────────────┐
                                          │  PHP worker process  │
                                          │  (pool of N)         │
                                          └─────────────────────┘
```

Two-process model: Rust terminates HTTP and supervises a pool of persistent PHP
workers; each request crosses once via a versioned, length-prefixed JSON protocol.
Details: [docs/architecture.md](docs/architecture.md) ·
protocol spec: [docs/protocol-v1.md](docs/protocol-v1.md).

## Quick start

```bash
cargo build                # Rust stable + PHP 8.2+ CLI required
./target/debug/fium init myapp
cd myapp
../target/debug/fium serve # or: fium dev for watch-restart
```

Or with the bundled example app:

```bash
./target/debug/fium serve examples/app.php
```

## CLI

| Command | Purpose | Notable flags |
|---|---|---|
| `fium serve [app.php]` | Start the HTTP server | `--host`, `-p/--port`, `-w/--workers`, `--tls-cert`, `--tls-key`, `--tls-self-signed` |
| `fium dev [app.php]` | Serve with file watching + worker restart | `--host`, `-p/--port`, `-w/--workers` |
| `fium init [dir]` | Scaffold a new project | none |
| `fium routes [app.php]` | List every route with its resolved middleware chain | none |
| `fium explain <METHOD> <path> [app.php]` | Resolve a request: matched route, params, middleware chain | none |

`routes`/`explain` boot the app, so they need the same env as `serve`.

## Configuration

Zero-config by default. Optional `fium.toml` next to your `app.php` (full reference:
[docs/configuration.md](docs/configuration.md)):

```toml
[server]
host = "127.0.0.1"
port = 3000
workers = 0           # 0 = auto (CPU count)
max_requests = 0      # 0 = no limit (worker recycling)
worker_timeout_ms = 5000
body_max_size = "1mb"

[tls]
cert = "certs/server.crt"
key = "certs/server.key"

[log]
level = "info"        # off, error, warn, info, debug, trace
format = "pretty"     # pretty, json
```

Precedence: defaults → `fium.toml` → CLI flags. Selected environment variables:
`FIUM_DEBUG`, `FIUM_PHP_BINARY`, `FIUM_TRUSTED_PROXIES`, `FIUM_SESSION_DRIVER`,
`FIUM_DB_DSN`, `FIUM_CACHE_DRIVER`, `FIUM_CORS_ORIGINS`,
`FIUM_API_TOKEN_SECRET[_FILE]`, and the native-middleware toggles
`FIUM_NATIVE_CORS` / `FIUM_NATIVE_RATELIMIT` / `FIUM_NATIVE_SECURITY_HEADERS`
(all **off by default**).

## Middleware

Built-in aliases (guide: [docs/middleware.md](docs/middleware.md)):

| Alias | Class | Description |
|-------|-------|-------------|
| `session` | `StartSession` | Starts a session |
| `auth` | `AuthenticateSession` | Requires authenticated session |
| `bearer` | `AuthenticateBearer` | Authenticates via Bearer token |
| `csrf` | `VerifyCsrf` | CSRF token verification |
| `cors` | `Cors` | CORS headers and preflight |
| `ratelimit:60` | `RateLimit` | Rate limiting per IP (60/min) |
| `secure` | `SecurityHeaders` | Security headers (nosniff, frame deny, etc.) |
| `role:admin` | `RequireRole` | Role-based access control |
| `json` | `RequireJsonAccept` | Requires JSON Accept header |
| `powered-by` | `AddPoweredByHeader` | Adds X-Fium-Middleware header |

Three of these have native Rust fast paths behind the `FIUM_NATIVE_*` toggles:
CORS preflight (short-circuit, 204 in ~0.05 ms), rate-limit (short-circuit, pool-wide
coherent counters, 429 in ~0.036 ms), security headers (response mutation).

## Benchmarks (intra-Fium, loopback, `ab`; treat as relative only)

| Probe | PHP path | Rust path | Delta |
|---|---:|---:|---|
| CORS preflight | 36,414 RPS | 321,931 RPS | ~8.9× |
| Rate-limit rejection (`ratelimit:1` flood) | 41,500 RPS, 8000 PHP hits | 328,000 RPS, **1 PHP hit** | ~7.9× |
| Rate-limit coherence (`ratelimit:60` × 4 workers) | ~240 effective | exactly 60 | correctness fix |

Methodology, profiling of the IPC tax, and the two ideas dropped after measurement:
[BENCHMARKS.md](BENCHMARKS.md).

## Alternatives (if you need something maintained)

| | Fium (archived) | RoadRunner | FrankenPHP | Swoole + Octane |
|---|---|---|---|---|
| Runtime | Rust + PHP workers | Go + PHP workers | Go, PHP embedded (C) | PHP C extension |
| Model | Process pool + IPC | Process pool + IPC | Threads in-process | Coroutines in-process |
| Framework | Own, embedded, zero-Composer | Yours (Composer/PSR-7) | Yours (Composer) | Yours (Composer) |
| Status | Archived experiment | Production | Production | Production |

Fium's niche, a whole vertical stack (runtime *plus* Laravel-lite framework) in one
binary, is not what these provide; they are servers for existing Composer apps.

## Documentation

- [Getting Started](docs/getting-started.md)
- [Configuration Reference](docs/configuration.md)
- [Middleware Guide](docs/middleware.md)
- [Deployment Guide](docs/deployment.md)
- [Protocol V1 Specification](docs/protocol-v1.md)
- [Architecture](docs/architecture.md)
- [PHP API Reference](docs/api-reference.md)
- [Benchmarks & research log](BENCHMARKS.md)

Contributions are **closed**: [CONTRIBUTING.md](CONTRIBUTING.md) is kept as a
record of the engineering practices used, not as an invitation.

## License

Dual-licensed under [MIT](LICENSE-MIT) or [Apache-2.0](LICENSE-APACHE), at your
option. Provided "as is", without warranty of any kind.
