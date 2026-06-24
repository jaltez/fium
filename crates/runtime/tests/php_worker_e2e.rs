//! End-to-end integration test: spawns the REAL PHP worker (`php/worker.php`)
//! with the REAL example app (`php/app.php`) and speaks the length-prefixed
//! framing protocol over its stdio pipes.
//!
//! This is the strongest contract test in the suite — it exercises, with live
//! processes on both sides:
//!   * PHP `worker.php` boot (route manifest emission + length-prefixed framing)
//!   * Rust -> PHP request framing (the exact JSON `Request::fromWorkerPayload` reads)
//!   * PHP -> Rust response framing (`Response::toWorkerResponse`)
//!   * real route dispatch and the error path
//!
//! Requires the `php` binary on PATH and the `php/` sources relative to the
//! workspace root. The test skips itself (passes) when those are unavailable,
//! so it runs in CI with PHP installed without breaking bare `cargo test`.

use std::io::{Read, Write};
use std::path::Path;
use std::process::{Command, Stdio};

const MAX_FRAME_SIZE: usize = 16 * 1024 * 1024;

/// `Child` handle that always kills the worker on drop, so a failing test
/// never leaks a PHP process.
struct WorkerGuard {
    child: Option<std::process::Child>,
}

impl Drop for WorkerGuard {
    fn drop(&mut self) {
        if let Some(child) = self.child.as_mut() {
            let _ = child.kill();
            let _ = child.wait();
        }
    }
}

fn fixture_paths() -> Option<(&'static Path, &'static Path)> {
    let root = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../php");
    let worker = root.join("worker.php");
    let app = root.join("app.php");
    if !worker.is_file() || !app.is_file() {
        return None;
    }
    // Avoid borrow issues by leaking — this is a test binary.
    let worker = Box::leak(worker.into_boxed_path());
    let app = Box::leak(app.into_boxed_path());
    Some((worker, app))
}

fn php_available() -> bool {
    Command::new("php")
        .arg("--version")
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .is_ok()
}

/// The worker's owned stdio pipes plus the guard that kills the worker on drop.
type WorkerPipes = Option<(WorkerGuard, Box<dyn Write + Send>, Box<dyn Read + Send>)>;

/// Spawn the real PHP worker and return its pipes plus a guard.
fn spawn_worker() -> WorkerPipes {
    if !php_available() {
        return None;
    }
    let (worker, app) = fixture_paths()?;

    let mut child = Command::new("php")
        .arg(worker)
        .arg(app)
        // The example app boots the API token service, which requires a signing
        // secret when debug mode is off. Provide one for the test process.
        .env(
            "FIUM_API_TOKEN_SECRET",
            "fium-e2e-signing-secret-not-for-production-use-32chars",
        )
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .ok()?;

    let stdin: Box<dyn Write + Send> = Box::new(child.stdin.take()?);
    let stdout: Box<dyn Read + Send> = Box::new(child.stdout.take()?);

    Some((WorkerGuard { child: Some(child) }, stdin, stdout))
}

fn write_frame<W: Write + ?Sized>(writer: &mut W, payload: &str) {
    let len = payload.len() as u32;
    writer
        .write_all(&len.to_be_bytes())
        .expect("write frame header");
    writer
        .write_all(payload.as_bytes())
        .expect("write frame body");
    writer.flush().expect("flush frame");
}

fn read_frame<R: Read + ?Sized>(reader: &mut R) -> Option<serde_json::Value> {
    let mut len_buf = [0u8; 4];
    reader.read_exact(&mut len_buf).ok()?;
    let len = u32::from_be_bytes(len_buf) as usize;
    if len == 0 || len > MAX_FRAME_SIZE {
        return None;
    }
    let mut body = vec![0u8; len];
    reader.read_exact(&mut body).ok()?;
    let text = String::from_utf8(body).ok()?;
    serde_json::from_str(&text).ok()
}

#[test]
fn real_php_worker_responds_to_request() {
    let Some((mut guard, mut stdin, mut stdout)) = spawn_worker() else {
        eprintln!("skipping: php binary or php/ sources not available");
        return;
    };

    // 1. Read the boot frame the worker emits on startup.
    let boot = read_frame(&mut stdout).expect("worker must emit a boot frame");
    assert_eq!(boot["type"], "boot", "boot message type");
    assert_eq!(boot["protocol_version"], 1);
    let route_names: Vec<&str> = boot["routes"]
        .as_array()
        .expect("routes is an array")
        .iter()
        .map(|route| route["name"].as_str().expect("route has a name"))
        .collect();
    assert!(
        route_names.contains(&"get_ping"),
        "manifest should advertise get_ping, got {route_names:?}"
    );

    // 2. Send a request for GET /ping (matched by name on the PHP side).
    let request = serde_json::json!({
        "protocol_version": 1,
        "request_id": "e2e-1",
        "method": "GET",
        "path": "/ping",
        "query_string": "",
        "headers": {},
        "cookies": {},
        "route_params": {},
        "body": null,
        "scheme": "http",
        "host": "localhost",
        "client_ip": "127.0.0.1",
        "is_secure": false,
        "matched_route": "get_ping"
    });
    write_frame(&mut stdin, &request.to_string());

    // 3. Read the response frame.
    let response = read_frame(&mut stdout).expect("worker must respond");
    assert_eq!(response["protocol_version"], 1);
    assert_eq!(response["request_id"], "e2e-1", "request id echoed back");
    assert_eq!(response["status"], 200);
    assert!(
        response["body"]
            .as_str()
            .unwrap_or("")
            .contains("\"pong\":true"),
        "ping body should contain pong, got {}",
        response["body"]
    );

    // Global middleware (`add-powered-by`) is applied to every route.
    let powered_by = &response["headers"]["x-fium-middleware"];
    assert!(
        powered_by
            .as_array()
            .map(|values| values.iter().any(|v| v == "add-powered-by"))
            .unwrap_or(false),
        "global middleware header should be present"
    );

    drop(stdin);
    guard.child.as_mut().map(|c| c.kill());
}

#[test]
fn real_php_worker_returns_404_for_unknown_route() {
    let Some((mut guard, mut stdin, mut stdout)) = spawn_worker() else {
        eprintln!("skipping: php binary or php/ sources not available");
        return;
    };

    // Consume the boot frame so the worker is ready to accept requests.
    let _boot = read_frame(&mut stdout).expect("boot frame");

    let request = serde_json::json!({
        "protocol_version": 1,
        "request_id": "e2e-2",
        "method": "GET",
        "path": "/no-such-route",
        "query_string": "",
        "headers": {},
        "cookies": {},
        "route_params": {},
        "body": null,
        "scheme": "http",
        "host": "localhost",
        "client_ip": "127.0.0.1",
        "is_secure": false,
        "matched_route": "definitely_not_a_route"
    });
    write_frame(&mut stdin, &request.to_string());

    let response = read_frame(&mut stdout).expect("worker must respond");
    assert_eq!(response["status"], 404);
    let body = response["body"].as_str().unwrap_or("");
    assert!(
        body.contains("route_not_resolved"),
        "expected route_not_resolved, got {body}"
    );

    drop(stdin);
    guard.child.as_mut().map(|c| c.kill());
}

#[test]
fn real_php_worker_rejects_protocol_mismatch() {
    let Some((mut guard, mut stdin, mut stdout)) = spawn_worker() else {
        eprintln!("skipping: php binary or php/ sources not available");
        return;
    };

    let _boot = read_frame(&mut stdout).expect("boot frame");

    // Wrong protocol_version — the worker must refuse to dispatch.
    let request = serde_json::json!({
        "protocol_version": 99,
        "request_id": "e2e-3",
        "method": "GET",
        "path": "/ping",
        "matched_route": "get_ping"
    });
    write_frame(&mut stdin, &request.to_string());

    let response = read_frame(&mut stdout).expect("worker must respond");
    assert_eq!(response["status"], 500);
    let body = response["body"].as_str().unwrap_or("");
    assert!(
        body.contains("protocol_version_mismatch"),
        "expected protocol_version_mismatch, got {body}"
    );

    drop(stdin);
    guard.child.as_mut().map(|c| c.kill());
}

#[test]
fn real_php_worker_boot_failure_goes_to_stderr_not_stdout() {
    // Regression for the "frame too large: 172384628" bug: a boot-time error written to
    // stdout corrupted framing. It must now go to stderr, leaving stdout clean.
    if !php_available() {
        eprintln!("skipping: php binary not available");
        return;
    }
    let Some((worker, _app)) = fixture_paths() else {
        eprintln!("skipping: php sources not available");
        return;
    };

    // An app that throws the moment it is required → Application::boot fails.
    let broken = std::env::temp_dir().join(format!("fium-broken-boot-{}.php", std::process::id()));
    std::fs::write(
        &broken,
        "<?php\nthrow new RuntimeException('deliberate boot failure for e2e');\n",
    )
    .expect("write broken app fixture");

    let output = Command::new("php")
        .arg(worker)
        .arg(&broken)
        .stdin(Stdio::null())
        .output()
        .expect("spawn php");

    let _ = std::fs::remove_file(&broken);

    // stdout is the protocol channel — must be completely clean (no frame, no error text).
    let stdout = String::from_utf8_lossy(&output.stdout);
    assert!(
        stdout.is_empty(),
        "stdout must stay clean on boot failure, got: {stdout:?}"
    );

    // stderr must explain the failure.
    let stderr = String::from_utf8_lossy(&output.stderr);
    assert!(
        stderr.contains("BOOT FAILED") && stderr.contains("deliberate boot failure"),
        "stderr should explain boot failure, got: {stderr:?}"
    );

    // Worker should exit non-zero.
    assert!(
        !output.status.success(),
        "worker should exit non-zero on boot failure, got {:?}",
        output.status
    );
}
