# Fium

Fium is an early-stage experiment toward a standalone Rust runtime for lightweight PHP applications.

Current scope:

- define the Rust-to-PHP transport contract
- scaffold the minimal runtime spike
- keep the PHP-facing framework surface explicit and small

This repository is intentionally at planning and spike stage, not feature-complete framework stage.

## Layout

- `crates/runtime` - phase 1 Rust runtime spike
- `crates/transport` - shared transport models for the Rust side
- `docs/protocol-v1.md` - first transport contract draft
- `php` - PHP-side package and worker stub
- `scripts/runtime-baseline.sh` - lightweight sequential latency and RSS probe
- `scripts/runtime-load.sh` - lightweight concurrent request probe
- `php/routes.json` - temporary route manifest consumed by the Rust runtime
- `php/bin/export-routes.php` - temporary route manifest exporter
- `php/routes.php` - temporary PHP route source with handler references
- `.agents/PREPARATION.md` - feasibility and product-definition working document
- `.agents/IMPLEMENTATION_STATUS.md` - implementation progress tracker against the feasibility plan

## Near-term goal

Prove that a Rust binary can supervise PHP workers, exchange normalized request and response envelopes, and keep the developer-facing PHP model lightweight.

## Temporary workflow

Until the PHP routing package exists, route metadata is sourced from `php/routes.php` and exported to `php/routes.json`:

```bash
php php/bin/export-routes.php
```

The PHP worker now boots a minimal application contract and dispatches named handlers from the route table.

Route metadata can also carry route-level middleware aliases, which are resolved inside the PHP application pipeline.

Unhandled PHP-side exceptions are normalized into structured worker responses instead of crashing the worker loop.

The current PHP spike also includes a minimal file-backed session middleware using the `fium_session` cookie.

On top of that, the current auth spike supports session-backed login, current-user resolution, a protected `/me` route, and logout using a file-loaded demo user source.

Route metadata can also express simple role guards such as `role:admin` for session-authenticated routes.

State-changing session routes are now protected by a minimal CSRF middleware that validates the `x-csrf-token` header or `_token` JSON field against session state.

The API side now includes a minimal bearer-token login flow with token-protected `/api/me` access using a small HMAC-signed token service.

The route manifest and request contract now also support route parameters such as `/hello/{name}` on the PHP side, and that extraction is now verified through the live Rust matcher.

The current Rust runtime spike now builds and has been smoke-tested over real HTTP for `/health`, `/hello/{name}`, `/api/login`, and `/api/me`.

The runtime now also fails fast if `php/routes.json` is missing, and the live `/_runtime/crash-once` probe has verified that a crashed PHP worker is restarted and the request retried successfully.

The worker supervisor now enforces a default request timeout, supports overriding it via `FIUM_WORKER_TIMEOUT_MS`, and has been exercised through `/_runtime/slow-once` and `/_runtime/always-slow` to verify both timeout recovery and `504 Gateway Timeout` behavior.

## Runtime Probes

Both probe scripts assume the Rust runtime is already running.

Sequential baseline sample:

```bash
./scripts/runtime-baseline.sh http://127.0.0.1:3000/hello/World 50
```

Concurrent burst sample:

```bash
./scripts/runtime-load.sh http://127.0.0.1:3000/hello/World 120 12
```

Small timeout-heavy burst sample:

```bash
./scripts/runtime-load.sh http://127.0.0.1:3000/_runtime/always-slow 12 4
```

Current local probe notes:

- a 50-request baseline against `/hello/World` averaged about `6.6ms` with `200:50`, with runtime RSS moving from about `4448 KB` to `5160 KB`
- a 120-request burst at concurrency `12` against `/hello/World` returned `200:120`
- a 12-request burst at concurrency `4` against `/_runtime/always-slow` returned clean `504:12`, and follow-up normal requests still succeeded
