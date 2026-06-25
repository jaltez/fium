use async_trait::async_trait;
use fium_transport::{
    BootMessage, HeaderMap, SetCookie, WorkerRequest, WorkerResponse, PROTOCOL_VERSION,
};
use thiserror::Error;
use tokio::{
    io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader},
    process::{Child, ChildStdin, ChildStdout, Command},
    sync::{mpsc, oneshot, Mutex},
    time::{timeout, Duration},
};
use tracing::{info, warn};

use std::sync::atomic::{AtomicU64, AtomicUsize, Ordering};
use std::sync::OnceLock;
use std::time::Instant;
use std::{path::PathBuf, sync::Arc};

use crate::route::{RouteError, RouteTable};

/// Maximum frame size accepted from a PHP worker (16 MB).
const MAX_FRAME_SIZE: u32 = 16 * 1024 * 1024;

// --- Optional IPC profiling (FIUM_PROFILE) -------------------------------------
// When enabled, each per-request stage in `dispatch_transport` is timed and cumulative
// means are logged every 200 requests (target `fium_profile`). Used by
// scripts/profile-ipc.sh to decide whether serialization is the dominant IPC cost.
static PROFILE_ENABLED: OnceLock<bool> = OnceLock::new();

fn profile_enabled() -> bool {
    *PROFILE_ENABLED.get_or_init(|| {
        matches!(
            std::env::var("FIUM_PROFILE")
                .unwrap_or_default()
                .trim()
                .to_ascii_lowercase()
                .as_str(),
            "1" | "true" | "yes" | "on"
        )
    })
}

struct Profile {
    encode_ns: AtomicU64,
    write_ns: AtomicU64,
    read_ns: AtomicU64,
    decode_ns: AtomicU64,
    count: AtomicU64,
}

impl Profile {
    const fn new() -> Self {
        Self {
            encode_ns: AtomicU64::new(0),
            write_ns: AtomicU64::new(0),
            read_ns: AtomicU64::new(0),
            decode_ns: AtomicU64::new(0),
            count: AtomicU64::new(0),
        }
    }

    fn record(&self, encode: Duration, write: Duration, read: Duration, decode: Duration) {
        self.encode_ns
            .fetch_add(encode.as_nanos() as u64, Ordering::Relaxed);
        self.write_ns
            .fetch_add(write.as_nanos() as u64, Ordering::Relaxed);
        self.read_ns
            .fetch_add(read.as_nanos() as u64, Ordering::Relaxed);
        self.decode_ns
            .fetch_add(decode.as_nanos() as u64, Ordering::Relaxed);
        let n = self.count.fetch_add(1, Ordering::Relaxed) + 1;
        if n.is_multiple_of(200) {
            let mean = |total: u64| total / n;
            info!(
                target: "fium_profile",
                "PROFILE_RUST n={n} encode={}ns write={}ns read={}ns decode={}ns",
                mean(self.encode_ns.load(Ordering::Relaxed)),
                mean(self.write_ns.load(Ordering::Relaxed)),
                mean(self.read_ns.load(Ordering::Relaxed)),
                mean(self.decode_ns.load(Ordering::Relaxed)),
            );
        }
    }
}

static PROFILE: Profile = Profile::new();
// -----------------------------------------------------------------------------

/// What a worker dispatch returns to the HTTP layer: a complete buffered response, or a
/// streaming handle whose chunks arrive over time and must be relayed incrementally.
pub enum DispatchOutcome {
    Buffered(WorkerResponse),
    Streaming(StreamHandle),
}

/// A streaming response opened by the worker. The HTTP layer drains `chunks` into the
/// client connection as they arrive; the stream ends when the channel closes.
pub struct StreamHandle {
    pub status: u16,
    pub headers: HeaderMap,
    pub cookies: Vec<SetCookie>,
    pub chunks: tokio::sync::mpsc::Receiver<Result<Vec<u8>, RuntimeWorkerError>>,
}

/// Internal: what dispatch_transport reads from the first frame after a request.
#[derive(Debug)]
enum OpenOutcome {
    Buffered(WorkerResponse),
    StreamOpen {
        status: u16,
        headers: HeaderMap,
        cookies: Vec<SetCookie>,
    },
}

#[derive(Debug, Error)]
pub enum RuntimeWorkerError {
    #[error("worker process disappeared before boot message")]
    BootProcessMissing,
    #[error("worker boot timed out while {context} after {ms} ms")]
    BootTimeout { context: &'static str, ms: u128 },
    #[error("worker request {request_id} timed out while {stage} after {ms} ms")]
    Timeout {
        request_id: String,
        stage: &'static str,
        ms: u128,
    },
    #[error("failed to {context}: {source}")]
    Io {
        context: &'static str,
        #[source]
        source: std::io::Error,
    },
    #[error("failed to {context}: {source}")]
    Json {
        context: &'static str,
        #[source]
        source: serde_json::Error,
    },
    #[error("expected boot message type '{expected}', got '{got}'")]
    UnexpectedMessageType { expected: &'static str, got: String },
    #[error("worker protocol mismatch: expected {expected}, got {got}")]
    ProtocolVersionMismatch { expected: u32, got: u32 },
    #[error("worker request id mismatch: expected {expected}, got {got}")]
    RequestIdMismatch { expected: String, got: String },
    #[error("failed to spawn PHP worker using '{php_binary}' and '{worker_entrypoint}': {source}")]
    Spawn {
        php_binary: String,
        worker_entrypoint: PathBuf,
        #[source]
        source: std::io::Error,
    },
    #[error("worker frame too large: {size} bytes (max {max})")]
    FrameTooLarge { size: u32, max: u32 },
    #[error("worker frame contained invalid UTF-8: {0}")]
    Utf8(#[from] std::string::FromUtf8Error),
    #[error("worker has shut down")]
    WorkerShutdown,
    #[error("worker task terminated")]
    WorkerTaskTerminated,
    #[error("worker process unavailable")]
    ProcessUnavailable,
    #[error("worker {pipe} unavailable")]
    MissingPipe { pipe: &'static str },
    #[error(transparent)]
    Routes(#[from] RouteError),
    #[error("all workers failed to boot: {last_error}")]
    AllWorkersFailedToBoot { last_error: Box<RuntimeWorkerError> },
    #[error("{request_error}; additionally failed to replace worker after retry failure: {restart_error}")]
    RetryReplacement {
        request_error: Box<RuntimeWorkerError>,
        restart_error: Box<RuntimeWorkerError>,
    },
}

#[async_trait]
pub trait WorkerTransport {
    async fn write_frame(&mut self, json: &str) -> Result<(), RuntimeWorkerError>;
    async fn read_frame(&mut self, max_size: u32) -> Result<String, RuntimeWorkerError>;
}

// Messages sent from WorkerSupervisor::handle() to the per-worker background task.
// `request` is boxed to keep the enum's largest variant small (clippy::large_enum_variant).
enum WorkerMessage {
    Request {
        request: Box<WorkerRequest>,
        reply: oneshot::Sender<Result<DispatchOutcome, RuntimeWorkerError>>,
    },
}

#[derive(Debug, Clone)]
pub struct WorkerSupervisor {
    php_binary: String,
    worker_entrypoint: PathBuf,
    app_file: PathBuf,
    request_timeout: Duration,
    boot_timeout: Duration,
    max_requests: u64,
    process: Arc<Mutex<Option<WorkerProcess>>>,
    tx: mpsc::UnboundedSender<WorkerMessage>,
    pending: Arc<AtomicUsize>,
    requests_handled: Arc<AtomicU64>,
    restarts: Arc<AtomicU64>,
    errors: Arc<AtomicU64>,
}

#[derive(Debug)]
struct WorkerProcess {
    child: Child,
    stdin: ChildStdin,
    stdout: BufReader<ChildStdout>,
}

#[async_trait]
impl WorkerTransport for WorkerProcess {
    async fn write_frame(&mut self, json: &str) -> Result<(), RuntimeWorkerError> {
        WorkerSupervisor::write_frame_io(&mut self.stdin, json).await
    }

    async fn read_frame(&mut self, max_size: u32) -> Result<String, RuntimeWorkerError> {
        WorkerSupervisor::read_frame_io(&mut self.stdout, max_size).await
    }
}

impl WorkerSupervisor {
    pub fn new(
        php_binary: impl Into<String>,
        worker_entrypoint: impl Into<String>,
        app_file: impl Into<String>,
        request_timeout_ms: u64,
        boot_timeout_ms: u64,
        max_requests: u64,
    ) -> Self {
        let timeout_ms = if request_timeout_ms > 0 {
            request_timeout_ms
        } else {
            750
        };
        let boot_timeout_ms = if boot_timeout_ms > 0 {
            boot_timeout_ms
        } else {
            10_000
        };
        let process = Arc::new(Mutex::new(None));
        let (tx, rx) = mpsc::unbounded_channel();
        let pending = Arc::new(AtomicUsize::new(0));
        let requests_handled = Arc::new(AtomicU64::new(0));
        let restarts = Arc::new(AtomicU64::new(0));
        let errors = Arc::new(AtomicU64::new(0));

        let supervisor = Self {
            php_binary: php_binary.into(),
            worker_entrypoint: PathBuf::from(worker_entrypoint.into()),
            app_file: PathBuf::from(app_file.into()),
            request_timeout: Duration::from_millis(timeout_ms),
            boot_timeout: Duration::from_millis(boot_timeout_ms),
            max_requests,
            process: process.clone(),
            tx,
            pending: pending.clone(),
            requests_handled: requests_handled.clone(),
            restarts: restarts.clone(),
            errors: errors.clone(),
        };

        // Spawn the per-worker background task that owns request processing.
        let php_binary = supervisor.php_binary.clone();
        let worker_entrypoint = supervisor.worker_entrypoint.clone();
        let app_file = supervisor.app_file.clone();
        let request_timeout = supervisor.request_timeout;
        let boot_timeout = supervisor.boot_timeout;
        let max_requests = supervisor.max_requests;

        tokio::spawn(async move {
            let mut rx = rx;
            while let Some(msg) = rx.recv().await {
                match msg {
                    WorkerMessage::Request { request, reply } => {
                        Self::process_request(
                            &php_binary,
                            &worker_entrypoint,
                            &app_file,
                            request_timeout,
                            boot_timeout,
                            max_requests,
                            &process,
                            &request,
                            &requests_handled,
                            &restarts,
                            &errors,
                            reply,
                        )
                        .await;
                        // process_request sends the reply internally (early for streaming,
                        // then relays chunks inline).
                    }
                }
            }
        });

        supervisor
    }

    pub fn pending_count(&self) -> usize {
        self.pending.load(Ordering::Relaxed)
    }

    pub fn requests_handled(&self) -> u64 {
        self.requests_handled.load(Ordering::Relaxed)
    }

    pub fn restarts(&self) -> u64 {
        self.restarts.load(Ordering::Relaxed)
    }

    pub fn errors(&self) -> u64 {
        self.errors.load(Ordering::Relaxed)
    }

    pub async fn is_alive(&self) -> bool {
        let mut process = self.process.lock().await;
        let Some(worker) = process.as_mut() else {
            return false;
        };

        match worker.child.try_wait() {
            Ok(None) => true,
            Ok(Some(_)) => {
                *process = None;
                false
            }
            Err(error) => {
                warn!(%error, "failed to inspect worker process liveness");
                false
            }
        }
    }

    /// Spawn the PHP worker and read the boot message containing the route manifest.
    /// Returns the RouteTable built from the worker's declared routes.
    pub async fn boot(&self) -> Result<RouteTable, RuntimeWorkerError> {
        let mut process = self.process.lock().await;

        let worker = self.spawn_worker().await?;
        *process = Some(worker);

        let worker = process
            .as_mut()
            .ok_or(RuntimeWorkerError::BootProcessMissing)?;

        let boot = Self::read_boot_message(worker, self.boot_timeout).await?;

        info!(
            route_count = boot.routes.len(),
            "worker booted successfully"
        );

        Ok(RouteTable::from_boot_routes(boot.routes, boot.cors)?)
    }

    /// Queue a request to be processed by this worker. Returns either a complete buffered
    /// response or a streaming handle (whose chunks the caller relays to the HTTP client).
    pub async fn handle(
        &self,
        request: WorkerRequest,
    ) -> Result<DispatchOutcome, RuntimeWorkerError> {
        self.pending.fetch_add(1, Ordering::Relaxed);
        let (reply_tx, reply_rx) = oneshot::channel();
        if self
            .tx
            .send(WorkerMessage::Request {
                request: Box::new(request),
                reply: reply_tx,
            })
            .is_err()
        {
            self.pending.fetch_sub(1, Ordering::Relaxed);
            return Err(RuntimeWorkerError::WorkerShutdown);
        }
        let result = reply_rx
            .await
            .map_err(|_| RuntimeWorkerError::WorkerTaskTerminated)?;
        self.pending.fetch_sub(1, Ordering::Relaxed);
        result
    }

    /// Process a single request (called from the background task). Sends the reply
    /// internally — either a complete response, or a streaming handle whose chunks are
    /// then relayed inline so the worker stays busy for the stream's lifetime.
    #[tracing::instrument(skip_all, fields(request_id = %request.request_id))]
    #[allow(clippy::too_many_arguments)]
    async fn process_request(
        php_binary: &str,
        worker_entrypoint: &PathBuf,
        app_file: &PathBuf,
        request_timeout: Duration,
        boot_timeout: Duration,
        max_requests: u64,
        process: &Arc<Mutex<Option<WorkerProcess>>>,
        request: &WorkerRequest,
        requests_handled: &Arc<AtomicU64>,
        restarts: &Arc<AtomicU64>,
        errors: &Arc<AtomicU64>,
        reply: oneshot::Sender<Result<DispatchOutcome, RuntimeWorkerError>>,
    ) {
        let mut slot = process.lock().await;

        // Check if worker needs recycling due to max_requests
        if max_requests > 0 {
            let handled = requests_handled.load(Ordering::Relaxed);
            if handled > 0 && handled.is_multiple_of(max_requests) {
                info!(handled, max_requests, "recycling worker after max_requests");
                if let Err(error) = Self::kill_and_restart(
                    php_binary,
                    worker_entrypoint,
                    app_file,
                    boot_timeout,
                    &mut slot,
                )
                .await
                {
                    let _ = reply.send(Err(error));
                    return;
                }
                restarts.fetch_add(1, Ordering::Relaxed);
            }
        }

        // Open phase: send request + read the first frame (with restart+retry on failure).
        let outcome = match Self::dispatch_once(
            php_binary,
            worker_entrypoint,
            app_file,
            request_timeout,
            boot_timeout,
            &mut slot,
            request,
        )
        .await
        {
            Ok(outcome) => outcome,
            Err(first_error) => {
                errors.fetch_add(1, Ordering::Relaxed);
                warn!(%first_error, "worker request failed, attempting restart");
                if let Err(restart_error) = Self::kill_and_restart(
                    php_binary,
                    worker_entrypoint,
                    app_file,
                    boot_timeout,
                    &mut slot,
                )
                .await
                {
                    let _ = reply.send(Err(RuntimeWorkerError::RetryReplacement {
                        request_error: Box::new(first_error),
                        restart_error: Box::new(restart_error),
                    }));
                    return;
                }
                restarts.fetch_add(1, Ordering::Relaxed);

                match Self::dispatch_once(
                    php_binary,
                    worker_entrypoint,
                    app_file,
                    request_timeout,
                    boot_timeout,
                    &mut slot,
                    request,
                )
                .await
                {
                    Ok(outcome) => outcome,
                    Err(second_error) => {
                        errors.fetch_add(1, Ordering::Relaxed);
                        warn!(%second_error, "worker request failed after restart, replacing worker");
                        if let Ok(()) = Self::kill_and_restart(
                            php_binary,
                            worker_entrypoint,
                            app_file,
                            boot_timeout,
                            &mut slot,
                        )
                        .await
                        {
                            restarts.fetch_add(1, Ordering::Relaxed);
                        }
                        let _ = reply.send(Err(second_error));
                        return;
                    }
                }
            }
        };

        // Handle the open outcome.
        requests_handled.fetch_add(1, Ordering::Relaxed);
        match outcome {
            OpenOutcome::Buffered(response) => {
                let _ = reply.send(Ok(DispatchOutcome::Buffered(response)));
            }
            OpenOutcome::StreamOpen {
                status,
                headers,
                cookies,
            } => {
                let (chunk_tx, chunk_rx) = mpsc::channel::<Result<Vec<u8>, RuntimeWorkerError>>(64);
                let _ = reply.send(Ok(DispatchOutcome::Streaming(StreamHandle {
                    status,
                    headers,
                    cookies,
                    chunks: chunk_rx,
                })));

                // Relay chunks inline — the worker stays busy for the stream's lifetime.
                if let Err(error) =
                    Self::relay_stream(&mut slot, chunk_tx, &request.request_id, request_timeout)
                        .await
                {
                    errors.fetch_add(1, Ordering::Relaxed);
                    warn!(%error, "stream relay ended with error");
                }
            }
        }
    }

    /// Read `stream_chunk`/`stream_end` frames from the worker and relay decoded bytes to
    /// the channel. On any error, the sender is dropped, closing the receiver on the
    /// dispatch side (the HTTP stream simply ends).
    async fn relay_stream(
        slot: &mut tokio::sync::MutexGuard<'_, Option<WorkerProcess>>,
        chunk_tx: mpsc::Sender<Result<Vec<u8>, RuntimeWorkerError>>,
        request_id: &str,
        request_timeout: Duration,
    ) -> Result<(), RuntimeWorkerError> {
        use base64::Engine as _;
        let process = slot
            .as_mut()
            .ok_or(RuntimeWorkerError::ProcessUnavailable)?;

        loop {
            let frame = timeout(request_timeout, process.read_frame(MAX_FRAME_SIZE))
                .await
                .map_err(|_| RuntimeWorkerError::Timeout {
                    request_id: request_id.to_string(),
                    stage: "reading stream chunk",
                    ms: request_timeout.as_millis(),
                })??;

            let value: serde_json::Value =
                serde_json::from_str(&frame).map_err(|source| RuntimeWorkerError::Json {
                    context: "parse stream frame",
                    source,
                })?;

            match value.get("type").and_then(|t| t.as_str()) {
                Some("stream_chunk") => {
                    let data_b64 = value.get("data").and_then(|d| d.as_str()).unwrap_or("");
                    let data = base64::engine::general_purpose::STANDARD
                        .decode(data_b64)
                        .unwrap_or_default();
                    if chunk_tx.send(Ok(data)).await.is_err() {
                        break; // dispatch dropped the receiver (client disconnected)
                    }
                }
                _ => break, // stream_end or unknown → stream complete
            }
        }

        Ok(())
    }

    /// Write a length-prefixed JSON frame to a writer.
    async fn write_frame_io<T: tokio::io::AsyncWrite + Unpin>(
        writer: &mut T,
        json: &str,
    ) -> Result<(), RuntimeWorkerError> {
        let len = json.len() as u32;
        writer
            .write_all(&len.to_be_bytes())
            .await
            .map_err(|source| RuntimeWorkerError::Io {
                context: "write frame length",
                source,
            })?;
        writer
            .write_all(json.as_bytes())
            .await
            .map_err(|source| RuntimeWorkerError::Io {
                context: "write frame body",
                source,
            })?;
        writer
            .flush()
            .await
            .map_err(|source| RuntimeWorkerError::Io {
                context: "flush frame",
                source,
            })
    }

    /// Read a length-prefixed JSON frame from a buffered reader.
    async fn read_frame_io(
        reader: &mut BufReader<ChildStdout>,
        max_size: u32,
    ) -> Result<String, RuntimeWorkerError> {
        let mut len_buf = [0u8; 4];
        reader
            .read_exact(&mut len_buf)
            .await
            .map_err(|source| RuntimeWorkerError::Io {
                context: "read frame length",
                source,
            })?;
        let len = u32::from_be_bytes(len_buf);

        if len > max_size {
            return Err(RuntimeWorkerError::FrameTooLarge {
                size: len,
                max: max_size,
            });
        }

        let mut body = vec![0u8; len as usize];
        reader
            .read_exact(&mut body)
            .await
            .map_err(|source| RuntimeWorkerError::Io {
                context: "read frame body",
                source,
            })?;

        String::from_utf8(body).map_err(RuntimeWorkerError::from)
    }

    async fn read_boot_message<T: WorkerTransport + ?Sized>(
        transport: &mut T,
        boot_timeout: Duration,
    ) -> Result<BootMessage, RuntimeWorkerError> {
        let frame = match timeout(boot_timeout, transport.read_frame(MAX_FRAME_SIZE)).await {
            Ok(Ok(frame)) => frame,
            // A clean EOF means the worker process exited before booting — almost always a
            // boot error, which is now reported on stderr (see worker.php BOOT FAILED). Map
            // it to a clear cause instead of the opaque "read frame length" / "frame too
            // large" errors that raw bytes on stdout used to produce.
            Ok(Err(RuntimeWorkerError::Io { source, .. }))
                if source.kind() == std::io::ErrorKind::UnexpectedEof =>
            {
                warn!(
                    "worker exited before sending its boot message — a boot error was \
                     likely reported to the php_worker log; try FIUM_DEBUG=1"
                );
                return Err(RuntimeWorkerError::WorkerShutdown);
            }
            Ok(Err(other)) => return Err(other),
            Err(_) => {
                return Err(RuntimeWorkerError::BootTimeout {
                    context: "waiting for boot message",
                    ms: boot_timeout.as_millis(),
                })
            }
        };

        let boot: BootMessage =
            serde_json::from_str(&frame).map_err(|source| RuntimeWorkerError::Json {
                context: "parse worker boot message",
                source,
            })?;

        if boot.message_type != "boot" {
            return Err(RuntimeWorkerError::UnexpectedMessageType {
                expected: "boot",
                got: boot.message_type,
            });
        }

        if boot.protocol_version != PROTOCOL_VERSION {
            return Err(RuntimeWorkerError::ProtocolVersionMismatch {
                expected: PROTOCOL_VERSION,
                got: boot.protocol_version,
            });
        }

        Ok(boot)
    }

    async fn dispatch_transport<T: WorkerTransport + ?Sized>(
        transport: &mut T,
        request: &WorkerRequest,
        request_timeout: Duration,
    ) -> Result<OpenOutcome, RuntimeWorkerError> {
        let profiling = profile_enabled();
        let t0 = Instant::now();

        let encoded =
            serde_json::to_string(request).map_err(|source| RuntimeWorkerError::Json {
                context: "encode worker request",
                source,
            })?;
        let t1 = Instant::now();

        timeout(request_timeout, transport.write_frame(&encoded))
            .await
            .map_err(|_| RuntimeWorkerError::Timeout {
                request_id: request.request_id.clone(),
                stage: "writing request",
                ms: request_timeout.as_millis(),
            })??;
        let t2 = Instant::now();

        let frame = timeout(request_timeout, transport.read_frame(MAX_FRAME_SIZE))
            .await
            .map_err(|_| RuntimeWorkerError::Timeout {
                request_id: request.request_id.clone(),
                stage: "reading response",
                ms: request_timeout.as_millis(),
            })??;
        let t3 = Instant::now();

        let value: serde_json::Value =
            serde_json::from_str(&frame).map_err(|source| RuntimeWorkerError::Json {
                context: "decode first frame",
                source,
            })?;
        let t4 = Instant::now();

        if profiling {
            PROFILE.record(
                t1.duration_since(t0),
                t2.duration_since(t1),
                t3.duration_since(t2),
                t4.duration_since(t3),
            );
        }

        // Streaming: the worker opened a stream — return the open metadata so the caller
        // can create the chunk channel and relay subsequent frames.
        if value.get("type").and_then(|t| t.as_str()) == Some("stream_open") {
            let status = value.get("status").and_then(|v| v.as_u64()).unwrap_or(200) as u16;
            let headers: HeaderMap =
                serde_json::from_value(value.get("headers").cloned().unwrap_or_default())
                    .unwrap_or_default();
            let cookies: Vec<SetCookie> =
                serde_json::from_value(value.get("cookies").cloned().unwrap_or_default())
                    .unwrap_or_default();
            return Ok(OpenOutcome::StreamOpen {
                status,
                headers,
                cookies,
            });
        }

        // Buffered: decode the full WorkerResponse and validate.
        let response: WorkerResponse =
            serde_json::from_value(value).map_err(|source| RuntimeWorkerError::Json {
                context: "decode worker response",
                source,
            })?;

        if response.protocol_version != PROTOCOL_VERSION {
            return Err(RuntimeWorkerError::ProtocolVersionMismatch {
                expected: PROTOCOL_VERSION,
                got: response.protocol_version,
            });
        }

        if response.request_id != request.request_id {
            return Err(RuntimeWorkerError::RequestIdMismatch {
                expected: request.request_id.clone(),
                got: response.request_id,
            });
        }

        Ok(OpenOutcome::Buffered(response))
    }

    #[tracing::instrument(skip_all, fields(request_id = %request.request_id))]
    async fn dispatch_once(
        _php_binary: &str,
        _worker_entrypoint: &PathBuf,
        _app_file: &PathBuf,
        request_timeout: Duration,
        _boot_timeout: Duration,
        slot: &mut Option<WorkerProcess>,
        request: &WorkerRequest,
    ) -> Result<OpenOutcome, RuntimeWorkerError> {
        let process = Self::ensure_started(slot).await?;
        Self::dispatch_transport(process, request, request_timeout).await
    }

    async fn kill_and_restart(
        php_binary: &str,
        worker_entrypoint: &PathBuf,
        app_file: &PathBuf,
        boot_timeout: Duration,
        slot: &mut Option<WorkerProcess>,
    ) -> Result<(), RuntimeWorkerError> {
        Self::kill_process(slot).await;
        *slot = None;

        let mut worker = Self::spawn_worker_cfg(php_binary, worker_entrypoint, app_file).await?;
        // Consume the boot message that every new PHP worker emits on startup.
        let _ = Self::read_boot_message(&mut worker, boot_timeout).await?;
        *slot = Some(worker);
        Ok(())
    }

    async fn kill_process(slot: &mut Option<WorkerProcess>) {
        if let Some(existing) = slot.as_mut() {
            if let Err(error) = existing.child.start_kill() {
                warn!(%error, "failed to signal worker kill during restart");
            } else {
                match timeout(Duration::from_secs(1), existing.child.wait()).await {
                    Ok(Ok(_status)) => {}
                    Ok(Err(error)) => {
                        warn!(%error, "failed while waiting for worker exit during restart");
                    }
                    Err(_) => {
                        warn!("timed out waiting for worker exit during restart");
                    }
                }
            }
        }
    }

    async fn ensure_started(
        slot: &mut Option<WorkerProcess>,
    ) -> Result<&mut WorkerProcess, RuntimeWorkerError> {
        // The retry logic in process_request handles worker failures.
        // If the worker died between writes, we'll get an I/O error and restart.
        // No need for a try_wait() syscall on every request.
        slot.as_mut().ok_or(RuntimeWorkerError::ProcessUnavailable)
    }

    async fn spawn_worker(&self) -> Result<WorkerProcess, RuntimeWorkerError> {
        Self::spawn_worker_cfg(&self.php_binary, &self.worker_entrypoint, &self.app_file).await
    }

    async fn spawn_worker_cfg(
        php_binary: &str,
        worker_entrypoint: &PathBuf,
        app_file: &PathBuf,
    ) -> Result<WorkerProcess, RuntimeWorkerError> {
        info!(entrypoint = %worker_entrypoint.display(), "starting PHP worker process");

        let mut child = Command::new(php_binary)
            .arg(worker_entrypoint)
            .arg(app_file)
            .stdin(std::process::Stdio::piped())
            .stdout(std::process::Stdio::piped())
            .stderr(std::process::Stdio::piped())
            .kill_on_drop(true)
            .spawn()
            .map_err(|source| RuntimeWorkerError::Spawn {
                php_binary: php_binary.to_string(),
                worker_entrypoint: worker_entrypoint.clone(),
                source,
            })?;

        if let Some(stderr) = child.stderr.take() {
            tokio::spawn(async move {
                let mut lines = BufReader::new(stderr).lines();
                loop {
                    match lines.next_line().await {
                        Ok(Some(line)) => warn!(target: "php_worker", "{line}"),
                        Ok(None) => break,
                        Err(error) => {
                            warn!(target: "php_worker", %error, "failed to read worker stderr");
                            break;
                        }
                    }
                }
            });
        }

        let stdin = child
            .stdin
            .take()
            .ok_or(RuntimeWorkerError::MissingPipe { pipe: "stdin" })?;
        let stdout = child
            .stdout
            .take()
            .ok_or(RuntimeWorkerError::MissingPipe { pipe: "stdout" })?;

        Ok(WorkerProcess {
            child,
            stdin,
            stdout: BufReader::new(stdout),
        })
    }
}

/// Choose between two sampled workers instead of scanning the full pool.
fn choose_worker_index<F>(worker_count: usize, cursor: &AtomicUsize, pending_count: F) -> usize
where
    F: Fn(usize) -> usize,
{
    if worker_count <= 1 {
        return 0;
    }

    let turn = cursor.fetch_add(1, Ordering::Relaxed);
    let first = turn % worker_count;
    let second = (first + (worker_count / 2).max(1)) % worker_count;

    if pending_count(second) < pending_count(first) {
        second
    } else {
        first
    }
}

/// A pool of PHP worker processes that distributes requests with queue-aware sampling.
#[derive(Clone)]
pub struct WorkerPool {
    workers: Arc<Vec<WorkerSupervisor>>,
    dispatch_cursor: Arc<AtomicUsize>,
}

impl WorkerPool {
    pub fn new(
        php_binary: impl Into<String>,
        worker_entrypoint: impl Into<String>,
        app_file: impl Into<String>,
        count: usize,
        max_requests: u64,
        worker_timeout_ms: u64,
        worker_boot_timeout_ms: u64,
    ) -> Self {
        let entrypoint = worker_entrypoint.into();
        let app = app_file.into();
        let php_binary = php_binary.into();
        let count = count.max(1);

        let workers: Vec<WorkerSupervisor> = (0..count)
            .map(|_| {
                WorkerSupervisor::new(
                    php_binary.clone(),
                    entrypoint.clone(),
                    app.clone(),
                    worker_timeout_ms,
                    worker_boot_timeout_ms,
                    max_requests,
                )
            })
            .collect();

        Self {
            workers: Arc::new(workers),
            dispatch_cursor: Arc::new(AtomicUsize::new(0)),
        }
    }

    pub fn workers(&self) -> &[WorkerSupervisor] {
        &self.workers
    }

    /// Restart all workers and rebuild the route table from a fresh boot message.
    /// Restart all workers and rebuild the route table. boot() replaces every worker
    /// (spawns new, drops old via kill_on_drop) and reads the fresh manifest — no need
    /// for a separate restart_all pass that would add a redundant lock wait.
    pub async fn reload(&self) -> Result<RouteTable, RuntimeWorkerError> {
        self.boot().await
    }

    /// Boot all workers in parallel. The first successful boot provides
    /// the route table; remaining boots complete asynchronously.
    /// Boot all workers in parallel. The first successful boot provides
    /// the route table; remaining boots complete asynchronously.
    pub async fn boot(&self) -> Result<RouteTable, RuntimeWorkerError> {
        let (tx, mut rx) = tokio::sync::mpsc::unbounded_channel();
        let count = self.workers.len();

        for worker in self.workers.iter() {
            let worker = worker.clone();
            let tx = tx.clone();
            tokio::spawn(async move {
                let _ = tx.send(worker.boot().await);
            });
        }
        // Drop the last sender so recv completes when all workers fail.
        drop(tx);

        let mut last_err: Option<RuntimeWorkerError> = None;
        let mut successes = 0;

        while let Some(result) = rx.recv().await {
            match result {
                Ok(routes) => {
                    successes += 1;
                    if successes == 1 {
                        info!(workers = count, "first worker booted, routes discovered");
                        return Ok(routes);
                    }
                }
                Err(e) => {
                    last_err = Some(e);
                }
            }
        }

        Err(RuntimeWorkerError::AllWorkersFailedToBoot {
            last_error: Box::new(last_err.unwrap_or(RuntimeWorkerError::BootProcessMissing)),
        })
    }

    /// Dispatch a request to one of two sampled workers, preferring the shorter queue.
    pub async fn handle(
        &self,
        request: WorkerRequest,
    ) -> Result<DispatchOutcome, RuntimeWorkerError> {
        let index = choose_worker_index(
            self.workers.len(),
            self.dispatch_cursor.as_ref(),
            |worker_index| self.workers[worker_index].pending_count(),
        );

        self.workers[index].handle(request).await
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::VecDeque;

    struct MockTransport {
        writes: Vec<String>,
        reads: VecDeque<Result<String, RuntimeWorkerError>>,
    }

    #[async_trait]
    impl WorkerTransport for MockTransport {
        async fn write_frame(&mut self, json: &str) -> Result<(), RuntimeWorkerError> {
            self.writes.push(json.to_string());
            Ok(())
        }

        async fn read_frame(&mut self, _max_size: u32) -> Result<String, RuntimeWorkerError> {
            self.reads
                .pop_front()
                .unwrap_or_else(|| Err(RuntimeWorkerError::ProcessUnavailable))
        }
    }

    fn request(request_id: &str) -> WorkerRequest {
        WorkerRequest {
            protocol_version: PROTOCOL_VERSION,
            request_id: request_id.to_string(),
            method: "GET".into(),
            path: "/ping".into(),
            query_string: String::new(),
            headers: Default::default(),
            cookies: Default::default(),
            route_params: Default::default(),
            body: None,
            body_file: None,
            scheme: "http".into(),
            host: "localhost".into(),
            client_ip: Some("127.0.0.1".into()),
            is_secure: false,
            matched_route: Some("get_ping".into()),
        }
    }

    fn response_json(request_id: &str, protocol_version: u32) -> String {
        serde_json::to_string(&WorkerResponse {
            protocol_version,
            request_id: request_id.to_string(),
            status: 200,
            headers: Default::default(),
            cookies: Vec::new(),
            body: Some("{\"ok\":true}".into()),
            error: None,
        })
        .expect("response should encode")
    }

    #[tokio::test]
    async fn dispatch_transport_accepts_valid_response() {
        let req = request("req_1");
        let mut transport = MockTransport {
            writes: Vec::new(),
            reads: VecDeque::from([Ok(response_json("req_1", PROTOCOL_VERSION))]),
        };

        let outcome =
            WorkerSupervisor::dispatch_transport(&mut transport, &req, Duration::from_millis(50))
                .await
                .expect("response should succeed");

        let response = match outcome {
            OpenOutcome::Buffered(r) => r,
            OpenOutcome::StreamOpen { .. } => panic!("expected buffered, got stream_open"),
        };

        assert_eq!(response.status, 200);
        assert_eq!(transport.writes.len(), 1);
    }

    #[tokio::test]
    async fn dispatch_transport_rejects_request_id_mismatch() {
        let req = request("req_expected");
        let mut transport = MockTransport {
            writes: Vec::new(),
            reads: VecDeque::from([Ok(response_json("req_other", PROTOCOL_VERSION))]),
        };

        let error =
            WorkerSupervisor::dispatch_transport(&mut transport, &req, Duration::from_millis(50))
                .await
                .expect_err("request id mismatch should fail");

        assert!(matches!(
            error,
            RuntimeWorkerError::RequestIdMismatch { .. }
        ));
    }

    #[tokio::test]
    async fn read_boot_message_rejects_protocol_mismatch() {
        let boot = serde_json::to_string(&BootMessage {
            protocol_version: PROTOCOL_VERSION + 1,
            message_type: "boot".into(),
            routes: Vec::new(),
            cors: None,
        })
        .expect("boot should encode");
        let mut transport = MockTransport {
            writes: Vec::new(),
            reads: VecDeque::from([Ok(boot)]),
        };

        let error = WorkerSupervisor::read_boot_message(&mut transport, Duration::from_millis(50))
            .await
            .expect_err("protocol mismatch should fail");

        assert!(matches!(
            error,
            RuntimeWorkerError::ProtocolVersionMismatch { .. }
        ));
    }

    #[tokio::test]
    async fn read_boot_message_maps_worker_eof_to_shutdown() {
        // A worker that exits before booting shows up as EOF on the pipe. That must surface
        // as a clear WorkerShutdown, not an opaque "read frame length" / "frame too large".
        let mut transport = MockTransport {
            writes: Vec::new(),
            reads: VecDeque::from([Err(RuntimeWorkerError::Io {
                context: "read frame length",
                source: std::io::Error::new(std::io::ErrorKind::UnexpectedEof, "eof"),
            })]),
        };

        let error = WorkerSupervisor::read_boot_message(&mut transport, Duration::from_millis(50))
            .await
            .expect_err("EOF should map to WorkerShutdown");

        assert!(matches!(error, RuntimeWorkerError::WorkerShutdown));
    }

    #[test]
    fn choose_worker_index_returns_zero_for_single_worker() {
        let cursor = AtomicUsize::new(0);

        assert_eq!(choose_worker_index(1, &cursor, |_| 0), 0);
    }

    #[test]
    fn choose_worker_index_prefers_lower_pending_worker() {
        let cursor = AtomicUsize::new(0);
        let pending = [5, 1, 0, 3];

        assert_eq!(
            choose_worker_index(pending.len(), &cursor, |index| pending[index]),
            2
        );
        assert_eq!(
            choose_worker_index(pending.len(), &cursor, |index| pending[index]),
            1
        );
    }
}
