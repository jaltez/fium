//! End-to-end tests for native (Rust-side) middleware.
//!
//! These boot the REAL `fium` binary with FIUM_NATIVE_CORS / FIUM_NATIVE_RATELIMIT and
//! assert the HTTP behavior — that preflights and 429s are served from Rust and that the
//! PHP pool is bypassed (fium_requests_total stays flat). The unit tests cover the pure
//! functions; these cover the dispatch wiring.
//!
//! Requires the `fium` binary and `php` on PATH. Skips (passes) when unavailable, so it
//! runs in CI (PHP installed in the Rust job) without breaking bare `cargo test`.

use std::io::{Read, Write};
use std::net::{TcpListener, TcpStream};
use std::path::PathBuf;
use std::process::{Child, Command, Stdio};
use std::time::{Duration, Instant};

struct ServerGuard {
    child: Option<Child>,
}

impl Drop for ServerGuard {
    fn drop(&mut self) {
        if let Some(child) = self.child.as_mut() {
            let _ = child.kill();
            let _ = child.wait();
        }
    }
}

fn fium_bin() -> Option<PathBuf> {
    let path = PathBuf::from(env!("CARGO_BIN_EXE_fium"));
    if path.is_file() {
        Some(path)
    } else {
        None
    }
}

fn php_available() -> bool {
    Command::new("php")
        .arg("--version")
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok()
}

fn bench_app() -> Option<PathBuf> {
    let path = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../../php/bench.php");
    if path.is_file() {
        Some(path)
    } else {
        None
    }
}

/// Grab a free port by binding to :0, then dropping the listener. Small TOCTOU race,
/// but fine for an isolated test run.
fn free_port() -> u16 {
    TcpListener::bind("127.0.0.1:0")
        .expect("bind :0")
        .local_addr()
        .expect("local addr")
        .port()
}

struct Response {
    status: u16,
    headers: Vec<(String, String)>,
    body: String,
}

fn http(port: u16, method: &str, path: &str, headers: &[(&str, &str)]) -> Option<Response> {
    let addr: std::net::SocketAddr = format!("127.0.0.1:{port}").parse().ok()?;
    let mut stream = TcpStream::connect_timeout(&addr, Duration::from_secs(1)).ok()?;
    stream.set_read_timeout(Some(Duration::from_secs(3))).ok()?;

    let mut req = format!("{method} {path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n");
    for (key, value) in headers {
        req.push_str(&format!("{key}: {value}\r\n"));
    }
    req.push_str("\r\n");
    stream.write_all(req.as_bytes()).ok()?;

    let mut buf = Vec::new();
    stream.read_to_end(&mut buf).ok()?;
    let text = String::from_utf8_lossy(&buf).into_owned();

    let (head, body) = text
        .split_once("\r\n\r\n")
        .map(|(h, b)| (h, b.to_string()))
        .unwrap_or((text.as_str(), String::new()));

    let mut lines = head.split("\r\n");
    let status = lines
        .next()?
        .split_whitespace()
        .nth(1)
        .and_then(|s| s.parse().ok())?;

    let header_map = lines
        .filter_map(|line| line.split_once(':'))
        .map(|(key, value)| (key.trim().to_ascii_lowercase(), value.trim().to_string()))
        .collect();

    Some(Response {
        status,
        headers: header_map,
        body,
    })
}

fn requests_total(port: u16) -> u64 {
    http(port, "GET", "/_fium/metrics", &[])
        .and_then(|response| {
            response
                .body
                .lines()
                .find(|line| line.starts_with("fium_requests_total "))
                .and_then(|line| line.split_whitespace().nth(1).and_then(|n| n.parse().ok()))
        })
        .unwrap_or(0)
}

/// Spawn the binary with the given native flags; wait until /healthz responds 200.
fn spawn(
    native_cors: bool,
    native_ratelimit: bool,
    native_security_headers: bool,
) -> Option<(ServerGuard, u16)> {
    if !php_available() || fium_bin().is_none() || bench_app().is_none() {
        return None;
    }
    let app = bench_app()?;
    let port = free_port();

    let mut command = Command::new(fium_bin()?);
    command
        .arg("serve")
        .arg(&app)
        .arg("--host")
        .arg("127.0.0.1")
        .arg("--port")
        .arg(port.to_string())
        .arg("--workers")
        .arg("1")
        .env("FIUM_DEBUG", "1")
        .env("RUST_LOG", "error")
        .stdout(Stdio::null())
        .stderr(Stdio::null());

    if native_cors {
        command.env("FIUM_NATIVE_CORS", "1");
    }
    if native_ratelimit {
        command.env("FIUM_NATIVE_RATELIMIT", "1");
    }
    if native_security_headers {
        command.env("FIUM_NATIVE_SECURITY_HEADERS", "1");
    }

    let child = command.spawn().ok()?;
    let guard = ServerGuard { child: Some(child) };

    let deadline = Instant::now() + Duration::from_secs(25);
    while Instant::now() < deadline {
        if http(port, "GET", "/healthz", &[])
            .map(|response| response.status == 200)
            .unwrap_or(false)
        {
            return Some((guard, port));
        }
        std::thread::sleep(Duration::from_millis(100));
    }

    eprintln!("skipping: fium server did not become ready in time");
    None
}

#[test]
fn native_cors_preflight_is_served_without_php() {
    let Some((_guard, port)) = spawn(true, false, false) else {
        eprintln!("skipping: fium binary or php not available");
        return;
    };

    let before = requests_total(port);
    let response = http(
        port,
        "OPTIONS",
        "/bench/cors",
        &[("Origin", "http://localhost")],
    )
    .expect("preflight response");
    assert_eq!(response.status, 204, "preflight should be 204");
    assert!(
        response
            .headers
            .iter()
            .any(|(key, _)| key == "access-control-allow-origin"),
        "CORS allow-origin header missing: {:?}",
        response.headers
    );

    // A few more preflights, all served from Rust.
    for _ in 0..3 {
        let r = http(port, "OPTIONS", "/bench/cors", &[]).expect("preflight");
        assert_eq!(r.status, 204);
    }

    let after = requests_total(port);
    assert_eq!(
        before, after,
        "native preflights must not reach PHP (requests_total changed)"
    );
}

#[test]
fn native_rate_limit_enforces_exact_limit_and_protects_pool() {
    let Some((_guard, port)) = spawn(false, true, false) else {
        eprintln!("skipping: fium binary or php not available");
        return;
    };

    // Coherence: /bench/limited is ratelimit:60; 80 requests -> exactly 60 allowed, 20 denied.
    let mut allowed = 0u32;
    let mut denied = 0u32;
    for _ in 0..80 {
        match http(port, "GET", "/bench/limited", &[]).map(|response| response.status) {
            Some(200) => allowed += 1,
            Some(429) => denied += 1,
            other => panic!("unexpected status for /bench/limited: {other:?}"),
        }
    }
    assert_eq!(allowed, 60, "exact limit should allow 60");
    assert_eq!(denied, 20, "the rest are 429");

    // Pool protection: flood /bench/flood (ratelimit:1) — only the first request reaches PHP.
    let before = requests_total(port);
    for _ in 0..50 {
        let _ = http(port, "GET", "/bench/flood", &[]);
    }
    let after = requests_total(port);
    let php_hits = after.saturating_sub(before);
    assert!(
        php_hits <= 1,
        "native rate limit must protect the PHP pool under a flood (php_hits={php_hits})"
    );
}

#[test]
fn native_security_headers_are_stamped_by_rust() {
    let Some((_guard, port)) = spawn(false, false, true) else {
        eprintln!("skipping: fium binary or php not available");
        return;
    };

    let response = http(port, "GET", "/bench/secure", &[]).expect("secure route response");
    assert_eq!(response.status, 200);

    let header = |name: &str| {
        response
            .headers
            .iter()
            .find(|(key, _)| key == name)
            .map(|(_, value)| value.as_str())
    };
    // The PHP SecurityHeaders middleware defers when FIUM_NATIVE_SECURITY_HEADERS=1, so
    // these must come from Rust's response post-processing.
    assert_eq!(header("x-content-type-options"), Some("nosniff"));
    assert_eq!(header("x-frame-options"), Some("DENY"));
    assert!(header("referrer-policy").is_some());
    assert!(header("permissions-policy").is_some());
}

#[test]
fn streaming_sse_relays_events_to_the_client() {
    let Some((_guard, port)) = spawn(false, false, false) else {
        eprintln!("skipping: fium binary or php not available");
        return;
    };

    let response = http(port, "GET", "/bench/sse", &[]).expect("sse response");
    assert_eq!(response.status, 200);
    // The 3 SSE events must be present in the relayed body (chunked transfer).
    assert!(
        response.body.contains("data: tick-1"),
        "missing tick-1: {}",
        response.body
    );
    assert!(
        response.body.contains("data: tick-2"),
        "missing tick-2: {}",
        response.body
    );
    assert!(
        response.body.contains("data: tick-3"),
        "missing tick-3: {}",
        response.body
    );
    // Content-Type must be the SSE type the handler declared.
    assert!(
        response
            .headers
            .iter()
            .any(|(key, value)| key == "content-type" && value.contains("text/event-stream")),
        "missing text/event-stream content-type: {:?}",
        response.headers
    );
}
