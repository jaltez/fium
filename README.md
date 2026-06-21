# Fium

A fast PHP runtime powered by Rust. Write a single `app.php`, run `fium serve`, done.

## What it does

- **Rust binary** owns HTTP serving, route matching, TLS, static files, compression, worker supervision, and metrics
- **PHP** owns business logic — handlers, middleware, sessions, auth, caching
- **Zero-dependency** PHP framework embedded in the binary
- **Zero-config** by default — power users can add an optional `fium.toml`

## Quick start

```bash
# Build
cargo build

# Scaffold a new project
./target/debug/fium init myapp
cd myapp

# Start serving
./target/debug/fium serve
```

Or with the example app:

```bash
./target/debug/fium serve php/app.php
```

## Features

**Runtime (Rust)**
- Supervised PHP worker pool with crash recovery and max-request recycling
- TLS/HTTPS via `--tls-cert`/`--tls-key` or `--tls-self-signed` for development
- Static file serving from a configurable directory
- Gzip and Brotli response compression
- Trusted proxy support (`FIUM_TRUSTED_PROXIES`)
- Configurable request timeouts and body size limits
- Request body size limits and runtime-managed request buffering for PHP workers
- Structured logging with JSON format option
- Health endpoint (`/health`) with JSON worker statistics
- Prometheus-compatible metrics (`/_fium/metrics`)
- File watching with automatic worker restart (`fium dev`)
- Pretty CLI boot banner with route table

**Framework (PHP)**
- Concise route format: `'GET /path' => handler`
- Closure-based inline handlers and class-based handlers
- Route groups with shared prefix and middleware
- Explicit route name overrides in concise route definitions
- Named route URL generation (`Application::url()`)
- Named middleware groups via top-level `middleware_groups`
- Request helpers for JSON and HTML form input, plus CSRF hidden-field rendering
- Built-in session and bearer auth helpers, plus optional registration, login, logout, refresh, reset, and verification handlers
- Middleware pipeline: session, auth, CSRF, CORS, rate limiting, security headers, role guards, bearer tokens
- Session storage: file-backed or PDO-backed, with session ID rotation, flash data, and remember-me support
- User storage: file-backed or PDO-backed
- Config class with `.env` file loading
- Input validation with common rules
- JSON, HTML, text, redirect, and empty response types
- Database layer: lazy PDO connection, fluent query builder, and active-record
  `Model` (zero-dependency, parameter-bound, identifier-validated)
- Cache layer: in-process, file-backed, or optional Redis (phpredis) drivers

## Configuration

Fium works with zero configuration. For customization, create a `fium.toml` next to your `app.php`:

```toml
[server]
host = "127.0.0.1"
port = 3000
workers = 0           # 0 = auto (CPU count)
max_requests = 0      # 0 = no limit (worker recycling)
worker_timeout_ms = 5000
body_max_size = "1mb"

[tuning]
shutdown_timeout_secs = 30
keep_alive_timeout_secs = 60
max_connections = 1024
worker_boot_timeout_ms = 10000
reuse_addr = true

[compression]
algorithms = ["gzip", "br"]
level = 4
static_cache_max_age_secs = 3600

[tls]
cert = "certs/server.crt"
key = "certs/server.key"

[log]
level = "info"        # off, error, warn, info, debug, trace
format = "pretty"     # pretty, json

[static]
enabled = true
dir = "public"
```

CLI flags override config file values.

## Layout

```
crates/runtime/     Rust HTTP runtime (fium binary)
crates/transport/   Shared protocol types (Rust ↔ PHP)
docs/               Protocol spec and guides
php/src/            PHP framework source
php/app.php         Example application
scripts/            Benchmark probes
```

## Middleware

Built-in middleware aliases:

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

## Compared to

| Feature | Fium | RoadRunner | FrankenPHP |
|---------|------|------------|------------|
| Language | Rust + PHP | Go + PHP | Go + PHP |
| Config | `fium.toml` (optional) | `.rr.yaml` (required) | `Caddyfile` |
| TLS | Built-in | Built-in + ACME | Automatic (Caddy) |
| Static files | Built-in | Plugin | Built-in |
| Compression | gzip/br | gzip (plugin) | gzip |
| Metrics | Prometheus | Prometheus | Prometheus |
| Worker model | Process pool | Process pool | Threads (C module) |
| PHP dependency | None | Composer package | PHP extension |
| Binary size | Single binary | Single binary | Single binary |

## Runtime Probes

```bash
# Sequential baseline
./scripts/runtime-baseline.sh http://127.0.0.1:3000/hello/World 50

# Concurrent burst
./scripts/runtime-load.sh http://127.0.0.1:3000/hello/World 120 12
```

## Documentation

- [Getting Started](docs/getting-started.md)
- [Configuration Reference](docs/configuration.md)
- [Middleware Guide](docs/middleware.md)
- [Deployment Guide](docs/deployment.md)
- [Protocol V1 Specification](docs/protocol-v1.md)
- [Architecture](docs/architecture.md)
- [PHP API Reference](docs/api-reference.md)

See [CONTRIBUTING.md](CONTRIBUTING.md) for build, test, and code-style guidance.
