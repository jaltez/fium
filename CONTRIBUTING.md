# Contributing to Fium

Thanks for helping improve Fium. This guide covers the build, the test suites,
the architectural constraints that govern changes, and the pull-request process.

## Prerequisites

- **Rust** (stable, edition 2021) and `cargo`
- **PHP** 8.2+ with the CLI SAPI, plus the `pdo`, `pdo_sqlite`, `mbstring`,
  `json`, `openssl`, and `ctype` extensions
- **Composer** (PHP dependency manager)

The end-to-end integration tests additionally require the `php` binary on your
`PATH`; they skip automatically when it is absent.

## Building

```bash
cargo build                 # build the fium runtime binary
./target/debug/fium --help
```

The PHP framework (`php/src/`) is embedded into the binary at compile time, so
a clean build after changing PHP source is required for the change to reach a
deployed runtime.

## Testing

Fium has two test suites. **Please run both before opening a PR.**

### PHP framework suite (PHPUnit)

```bash
cd php
composer install            # first time only
composer test               # runs phpunit
```

Tests live in `php/tests/` under the `Fium\Tests\` namespace. Add focused unit
tests for any framework behavior you change or add. Fixtures (e.g. in-memory
stores) go in `php/tests/Fixtures/`.

### Rust suite

```bash
cargo test                       # all crates: unit + integration tests
cargo test -p fium-transport     # the protocol contract tests
cargo test -p fium-runtime       # runtime unit tests + the live PHP e2e tests
```

The end-to-end tests in `crates/runtime/tests/php_worker_e2e.rs` spawn a real
PHP worker from `php/worker.php` + `php/app.php` and speak the length-prefixed
framing protocol. They are the strongest check that the Rust and PHP sides
agree on the wire format — keep them green.

## Architectural constraints

These constraints exist for good reasons; violating them needs explicit
discussion in a PR.

1. **The PHP framework is zero-dependency.** Only `php/worker.php` and
   `php/src/` are embedded into the binary (`crates/runtime/src/embed.rs`), and
   `worker.php` autoloads only the `Fium\` namespace. Composer packages are
   **not** embedded and would be invisible to a deployed worker. Every framework
   feature must be pure PHP. PHPUnit is a *dev* dependency and never ships in
   the runtime — that is intentional and fine.

2. **Protocol changes are versioned.** The Rust↔PHP wire format is length-
   prefixed JSON frames (see `docs/protocol-v1.md` and the contract tests in
   `crates/transport/tests/`). Any change to a field name or optionality must be
   reflected on **both** sides and covered by the contract tests. Bump the
   protocol version for incompatible changes.

3. **Security defaults are strict.** Values are always bound as parameters in
   the query builder; identifiers are validated. Auth tokens are signed.
   Exceptions do not leak their message unless `FIUM_DEBUG=true`. Preserve these
   defaults.

4. **Optional drivers stay optional.** A driver that needs a PHP extension
   (e.g. `redis` via phpredis) must degrade gracefully and never be selected by
   default. It must not become a hard requirement.

## Code style

- **PHP**: `declare(strict_types=1)`, strict type hints, lowercase keywords,
  `final` classes by default, PSR-4 autoloading. Match the surrounding code.
- **Rust**: follow `rustfmt`/`clippy`. Prefer `Result`/`Option` over `unwrap`
  outside tests. Keep `unsafe` out unless strictly necessary.

## Pull requests

1. Open a branch from `main`.
2. Keep changes focused; one concern per PR is easier to review.
3. Include tests for new behavior and update docs (`docs/`) and the README where
   relevant.
4. Ensure both suites pass and `cargo build` is clean.
5. Describe the *why* of the change in the PR description, not just the *what*.

Commit and push only when asked. By default, prepare the change and let the
maintainer handle the commit/push.

## Repository layout

```
crates/runtime/     Rust HTTP runtime (fium binary)
crates/transport/   Shared protocol types (Rust <-> PHP)
docs/               Protocol spec and guides
php/src/            PHP framework source (embedded into the binary)
php/tests/          PHPUnit test suite
php/app.php         Example application
scripts/            Benchmark probes
```
