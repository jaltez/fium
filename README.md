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
- Worker pool with round-robin dispatch, crash recovery, and max-request recycling
- TLS/HTTPS via `--tls-cert`/`--tls-key` or `--tls-self-signed` for development
- Static file serving from a configurable directory
- Gzip, Brotli, and Zstd response compression
- Trusted proxy support (`FIUM_TRUSTED_PROXIES`)
- Configurable request timeouts and body size limits
- Structured logging with JSON format option
- Health endpoint (`/health`) with JSON worker statistics
- Prometheus-compatible metrics (`/_fium/metrics`)
- File watching with automatic worker restart (`fium dev`)
- Pretty CLI boot banner with route table

**Framework (PHP)**
- Concise route format: `'GET /path' => handler`
- Closure-based inline handlers and class-based handlers
- Route groups with shared prefix and middleware
- Named route URL generation (`Application::url()`)
- Middleware pipeline: session, auth, CSRF, CORS, rate limiting, security headers, role guards, bearer tokens
- Session storage: file-backed or PDO-backed
- User storage: file-backed or PDO-backed
- Cache abstraction: file-backed or PDO-backed
- Config class with `.env` file loading
- Input validation with common rules
- Logger (writes to stderr, captured by runtime)
- JSON, HTML, text, redirect, and empty response types

## Configuration

Fium works with zero configuration. For customization, create a `fium.toml` next to your `app.php`:

```toml
[server]
host = "127.0.0.1"
port = 3000
workers = 0           # 0 = auto (CPU count)
max_requests = 0      # 0 = no limit (worker recycling)
worker_timeout_ms = 750
body_max_size = "1mb"

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
| Compression | gzip/br/zstd | gzip (plugin) | gzip |
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
