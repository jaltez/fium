# Configuration Reference

Fium works with zero configuration. All settings have sensible defaults.

For customization, create a `fium.toml` file in the same directory as your `app.php`. CLI flags always override file values.

## Full reference

```toml
[server]
host = "127.0.0.1"        # Bind address
port = 3000                # Bind port
workers = 0                # Worker count (0 = auto, uses CPU count)
max_requests = 0           # Recycle worker after N requests (0 = never)
worker_timeout_ms = 5000   # Per-request timeout in milliseconds
body_max_size = "1mb"      # Maximum request body size (supports kb, mb, gb)

[tuning]
shutdown_timeout_secs = 30     # Graceful shutdown window
keep_alive_timeout_secs = 60   # HTTP keep-alive / HTTP2 ping interval
max_connections = 1024         # Listener backlog size
worker_boot_timeout_ms = 10000 # Worker boot timeout in milliseconds
reuse_addr = true              # Enable SO_REUSEADDR on the listener

[compression]
algorithms = ["gzip", "br"]    # Supported encodings
level = 4                      # Compression quality (1-11, clamped)
static_cache_max_age_secs = 3600

[tls]
cert = ""                  # Path to TLS certificate PEM file
key = ""                   # Path to TLS private key PEM file

[log]
level = "info"             # Log level: off, error, warn, info, debug, trace
format = "pretty"          # Log format: pretty (colored terminal) or json

[static]
enabled = false            # Serve static files?
dir = "public"             # Directory relative to app.php
```

## CLI flags

| Flag | Overrides | Example |
|------|-----------|---------|
| `--host` | `server.host` | `fium serve --host 0.0.0.0` |
| `-p, --port` | `server.port` | `fium serve -p 8080` |
| `-w, --workers` | `server.workers` | `fium serve -w 8` |
| `--tls-cert` | `tls.cert` | `fium serve --tls-cert cert.pem` |
| `--tls-key` | `tls.key` | `fium serve --tls-key key.pem` |
| `--tls-self-signed` | — | `fium serve --tls-self-signed` |

## Environment variables

| Variable | Description | Default |
|----------|-------------|---------|
| `FIUM_DEBUG` | Enable debug mode (verbose errors) | `false` |
| `FIUM_TRUSTED_PROXIES` | Comma-separated trusted proxy IPs (`*` for all) | loopback only |
| `FIUM_API_TOKEN_SECRET` | Secret for API token signing (32+ chars recommended; required in production) | insecure dev default in debug |
| `FIUM_API_TOKEN_SECRET_FILE` | Path to a file containing the API token secret | unset |
| `FIUM_SESSION_DRIVER` | Session driver: `file` or `pdo` | `file` |
| `FIUM_SESSION_DSN` | PDO DSN for session storage | `sqlite:storage/sessions.db` |
| `FIUM_USER_DRIVER` | User store driver: `file` or `pdo` | `file` |
| `FIUM_USER_DSN` | PDO DSN for user storage | `sqlite:storage/users.db` |
| `FIUM_CORS_ORIGINS` | CORS allowed origins | `*` |
| `FIUM_CORS_METHODS` | CORS allowed methods | `GET, POST, PUT, PATCH, DELETE, OPTIONS` |
| `FIUM_CORS_HEADERS` | CORS allowed headers | `Content-Type, Authorization, Accept, X-Requested-With` |
| `FIUM_CORS_MAX_AGE` | CORS preflight cache duration (seconds) | `86400` |

## Layer precedence

```
defaults → fium.toml → CLI flags → environment variables
```

Config file values override defaults. CLI flags override config file values. Some settings (like `FIUM_TRUSTED_PROXIES`) are only available via environment variables.

## Worker recycling

Set `max_requests` to prevent PHP memory leaks from accumulating:

```toml
[server]
max_requests = 500    # Restart worker after 500 requests
```

Workers are restarted gracefully — the current request completes before recycling.

## TLS

### Production (your own certificates)

```toml
[tls]
cert = "certs/server.crt"
key = "certs/server.key"
```

### Development (self-signed)

```bash
fium serve --tls-self-signed
```

This generates a self-signed certificate in `.fium/tls/` and serves HTTPS on the configured port.
