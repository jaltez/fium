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
- `php/routes.json` - temporary route manifest consumed by the Rust runtime
- `php/bin/export-routes.php` - temporary route manifest exporter
- `php/routes.php` - temporary PHP route source with handler references
- `.agents/PREPARATION.md` - feasibility and product-definition working document

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
