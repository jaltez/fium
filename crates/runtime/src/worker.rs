use fium_transport::{BootMessage, WorkerRequest, WorkerResponse, PROTOCOL_VERSION};
use tokio::{
    io::{AsyncReadExt, AsyncWriteExt, BufReader},
    process::{Child, ChildStdin, ChildStdout, Command},
    sync::{mpsc, oneshot, Mutex},
    time::{timeout, Duration},
};
use tracing::{info, warn};

use std::{path::PathBuf, sync::Arc};
use std::sync::atomic::{AtomicU64, AtomicUsize, Ordering};

use crate::route::RouteTable;

/// Maximum frame size accepted from a PHP worker (16 MB).
const MAX_FRAME_SIZE: u32 = 16 * 1024 * 1024;

// Messages sent from WorkerSupervisor::handle() to the per-worker background task.
enum WorkerMessage {
    Request {
        request: WorkerRequest,
        reply: oneshot::Sender<Result<WorkerResponse, String>>,
    },
    Restart {
        reply: oneshot::Sender<()>,
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

impl WorkerSupervisor {
    pub fn new(
        worker_entrypoint: impl Into<String>,
        app_file: impl Into<String>,
        request_timeout_ms: u64,
        max_requests: u64,
    ) -> Self {
        let timeout_ms = if request_timeout_ms > 0 { request_timeout_ms } else { 750 };
        let process = Arc::new(Mutex::new(None));
        let (tx, rx) = mpsc::unbounded_channel();
        let pending = Arc::new(AtomicUsize::new(0));
        let requests_handled = Arc::new(AtomicU64::new(0));
        let restarts = Arc::new(AtomicU64::new(0));
        let errors = Arc::new(AtomicU64::new(0));

        let supervisor = Self {
            php_binary: "php".to_string(),
            worker_entrypoint: PathBuf::from(worker_entrypoint.into()),
            app_file: PathBuf::from(app_file.into()),
            request_timeout: Duration::from_millis(timeout_ms),
            boot_timeout: Duration::from_secs(10),
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
                        let result = Self::process_request(
                            &php_binary, &worker_entrypoint, &app_file,
                            request_timeout, boot_timeout, max_requests,
                            &process, &request,
                            &requests_handled, &restarts, &errors,
                        ).await;
                        let _ = reply.send(result);
                    }
                    WorkerMessage::Restart { reply } => {
                        let mut slot = process.lock().await;
                        Self::kill_process(&mut slot).await;
                        let _ = reply.send(());
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

    /// Spawn the PHP worker and read the boot message containing the route manifest.
    /// Returns the RouteTable built from the worker's declared routes.
    pub async fn boot(&self) -> Result<RouteTable, String> {
        let mut process = self.process.lock().await;

        let worker = self.spawn_worker().await?;
        *process = Some(worker);

        let worker = process
            .as_mut()
            .ok_or_else(|| "worker process disappeared before boot message".to_string())?;

        let line = timeout(self.boot_timeout, Self::read_frame(&mut worker.stdout, MAX_FRAME_SIZE))
            .await
            .map_err(|_| {
                format!(
                    "worker boot timed out after {} ms",
                    self.boot_timeout.as_millis()
                )
            })?
            .map_err(|error| format!("failed to read worker boot message: {error}"))?;

        let boot: BootMessage = serde_json::from_str(&line)
            .map_err(|error| format!("failed to parse worker boot message: {error}"))?;

        if boot.message_type != "boot" {
            return Err(format!(
                "expected boot message type 'boot', got '{}'",
                boot.message_type
            ));
        }

        if boot.protocol_version != PROTOCOL_VERSION {
            return Err(format!(
                "worker protocol mismatch: expected {}, got {}",
                PROTOCOL_VERSION, boot.protocol_version
            ));
        }

        info!(route_count = boot.routes.len(), "worker booted successfully");

        RouteTable::from_boot_routes(boot.routes)
    }

    /// Queue a request to be processed by this worker. Returns immediately;
    /// the response arrives via the oneshot when the background task finishes.
    pub async fn handle(&self, request: WorkerRequest) -> Result<WorkerResponse, String> {
        self.pending.fetch_add(1, Ordering::Relaxed);
        let (reply_tx, reply_rx) = oneshot::channel();
        if self.tx.send(WorkerMessage::Request { request, reply: reply_tx }).is_err() {
            self.pending.fetch_sub(1, Ordering::Relaxed);
            return Err("worker has shut down".to_string());
        }
        let result = reply_rx.await.map_err(|_| "worker task terminated".to_string())?;
        self.pending.fetch_sub(1, Ordering::Relaxed);
        result
    }

    /// Process a single request (called from the background task).
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
    ) -> Result<WorkerResponse, String> {
        let mut slot = process.lock().await;

        // Check if worker needs recycling due to max_requests
        if max_requests > 0 {
            let handled = requests_handled.load(Ordering::Relaxed);
            if handled > 0 && handled % max_requests == 0 {
                info!(handled, max_requests, "recycling worker after max_requests");
                Self::kill_and_restart(
                    php_binary, worker_entrypoint, app_file,
                    boot_timeout, &mut slot,
                ).await?;
                restarts.fetch_add(1, Ordering::Relaxed);
            }
        }

        match Self::dispatch_once(
            php_binary, worker_entrypoint, app_file,
            request_timeout, boot_timeout,
            &mut slot, request,
        ).await {
            Ok(response) => {
                requests_handled.fetch_add(1, Ordering::Relaxed);
                Ok(response)
            }
            Err(first_error) => {
                errors.fetch_add(1, Ordering::Relaxed);
                warn!(%first_error, "worker request failed, attempting restart");
                Self::kill_and_restart(
                    php_binary, worker_entrypoint, app_file,
                    boot_timeout, &mut slot,
                ).await?;
                restarts.fetch_add(1, Ordering::Relaxed);

                match Self::dispatch_once(
                    php_binary, worker_entrypoint, app_file,
                    request_timeout, boot_timeout,
                    &mut slot, request,
                ).await {
                    Ok(response) => {
                        requests_handled.fetch_add(1, Ordering::Relaxed);
                        Ok(response)
                    }
                    Err(second_error) => {
                        errors.fetch_add(1, Ordering::Relaxed);
                        warn!(%second_error, "worker request failed after restart, replacing worker before returning error");

                        match Self::kill_and_restart(
                            php_binary, worker_entrypoint, app_file,
                            boot_timeout, &mut slot,
                        ).await {
                            Ok(()) => {
                                restarts.fetch_add(1, Ordering::Relaxed);
                                Err(second_error)
                            }
                            Err(restart_error) => Err(format!(
                                "{second_error}; additionally failed to replace worker after retry failure: {restart_error}"
                            )),
                        }
                    }
                }
            }
        }
    }

    /// Write a length-prefixed JSON frame to a writer.
    async fn write_frame<T: tokio::io::AsyncWrite + Unpin>(
        writer: &mut T,
        json: &str,
    ) -> Result<(), String> {
        let len = json.len() as u32;
        writer.write_all(&len.to_be_bytes()).await
            .map_err(|e| format!("failed to write frame length: {e}"))?;
        writer.write_all(json.as_bytes()).await
            .map_err(|e| format!("failed to write frame body: {e}"))?;
        writer.flush().await
            .map_err(|e| format!("failed to flush frame: {e}"))
    }

    /// Read a length-prefixed JSON frame from a buffered reader.
    async fn read_frame(
        reader: &mut BufReader<ChildStdout>,
        max_size: u32,
    ) -> Result<String, String> {
        let mut len_buf = [0u8; 4];
        reader.read_exact(&mut len_buf).await
            .map_err(|e| format!("failed to read frame length: {e}"))?;
        let len = u32::from_be_bytes(len_buf);

        if len > max_size {
            return Err(format!(
                "worker frame too large: {len} bytes (max {max_size})"
            ));
        }

        let mut body = vec![0u8; len as usize];
        reader.read_exact(&mut body).await
            .map_err(|e| format!("failed to read frame body: {e}"))?;

        String::from_utf8(body)
            .map_err(|e| format!("worker frame contained invalid UTF-8: {e}"))
    }

    async fn dispatch_once(
        _php_binary: &str,
        _worker_entrypoint: &PathBuf,
        _app_file: &PathBuf,
        request_timeout: Duration,
        _boot_timeout: Duration,
        slot: &mut Option<WorkerProcess>,
        request: &WorkerRequest,
    ) -> Result<WorkerResponse, String> {
        let process = Self::ensure_started(slot).await?;

        let encoded = serde_json::to_string(request)
            .map_err(|error| format!("failed to encode worker request: {error}"))?;

        timeout(request_timeout, Self::write_frame(&mut process.stdin, &encoded))
            .await
            .map_err(|_| {
                format!(
                    "worker request timed out while writing request {} after {} ms",
                    request.request_id,
                    request_timeout.as_millis()
                )
            })??;

        let frame = timeout(request_timeout, Self::read_frame(&mut process.stdout, MAX_FRAME_SIZE))
            .await
            .map_err(|_| {
                format!(
                    "worker request timed out while reading response {} after {} ms",
                    request.request_id,
                    request_timeout.as_millis()
                )
            })??;

        let response: WorkerResponse = serde_json::from_str(&frame)
            .map_err(|error| format!("failed to decode worker response: {error}"))?;

        if response.protocol_version != PROTOCOL_VERSION {
            return Err(format!(
                "worker protocol mismatch: expected {}, got {}",
                PROTOCOL_VERSION, response.protocol_version
            ));
        }

        if response.request_id != request.request_id {
            return Err(format!(
                "worker request id mismatch: expected {}, got {}",
                request.request_id, response.request_id
            ));
        }

        Ok(response)
    }

    async fn kill_and_restart(
        php_binary: &str,
        worker_entrypoint: &PathBuf,
        app_file: &PathBuf,
        boot_timeout: Duration,
        slot: &mut Option<WorkerProcess>,
    ) -> Result<(), String> {
        Self::kill_process(slot).await;
        *slot = None;

        let mut worker = Self::spawn_worker_cfg(php_binary, worker_entrypoint, app_file).await?;
        // Consume the boot message that every new PHP worker emits on startup.
        timeout(boot_timeout, Self::read_frame(&mut worker.stdout, MAX_FRAME_SIZE))
            .await
            .map_err(|_| "timed out waiting for boot message from restarted worker".to_string())?
            .map_err(|error| format!("failed to read boot message from restarted worker: {error}"))?;
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

    async fn ensure_started<'a>(
        slot: &'a mut Option<WorkerProcess>,
    ) -> Result<&'a mut WorkerProcess, String> {
        // The retry logic in process_request handles worker failures.
        // If the worker died between writes, we'll get an I/O error and restart.
        // No need for a try_wait() syscall on every request.
        slot.as_mut()
            .ok_or_else(|| "worker process unavailable".to_string())
    }

    async fn spawn_worker(&self) -> Result<WorkerProcess, String> {
        Self::spawn_worker_cfg(
            &self.php_binary,
            &self.worker_entrypoint,
            &self.app_file,
        ).await
    }

    async fn spawn_worker_cfg(
        php_binary: &str,
        worker_entrypoint: &PathBuf,
        app_file: &PathBuf,
    ) -> Result<WorkerProcess, String> {
        info!(entrypoint = %worker_entrypoint.display(), "starting PHP worker process");

        let mut child = Command::new(php_binary)
            .arg(worker_entrypoint)
            .arg(app_file)
            .stdin(std::process::Stdio::piped())
            .stdout(std::process::Stdio::piped())
            .stderr(std::process::Stdio::inherit())
            .spawn()
            .map_err(|error| format!(
                "failed to spawn PHP worker using '{}' and '{}': {error}",
                php_binary,
                worker_entrypoint.display()
            ))?;

        let stdin = child
            .stdin
            .take()
            .ok_or_else(|| "worker stdin unavailable".to_string())?;
        let stdout = child
            .stdout
            .take()
            .ok_or_else(|| "worker stdout unavailable".to_string())?;

        Ok(WorkerProcess {
            child,
            stdin,
            stdout: BufReader::new(stdout),
        })
    }
}

/// A pool of PHP worker processes that distributes requests to the least-loaded worker.
#[derive(Clone)]
pub struct WorkerPool {
    workers: Arc<Vec<WorkerSupervisor>>,
}

impl WorkerPool {
    pub fn new(
        worker_entrypoint: impl Into<String>,
        app_file: impl Into<String>,
        count: usize,
        max_requests: u64,
        worker_timeout_ms: u64,
    ) -> Self {
        let entrypoint = worker_entrypoint.into();
        let app = app_file.into();
        let count = count.max(1);

        let workers: Vec<WorkerSupervisor> = (0..count)
            .map(|_| WorkerSupervisor::new(entrypoint.clone(), app.clone(), worker_timeout_ms, max_requests))
            .collect();

        Self {
            workers: Arc::new(workers),
        }
    }

    pub fn workers(&self) -> &[WorkerSupervisor] {
        &self.workers
    }

    /// Restart all workers and rebuild the route table from a fresh boot message.
    pub async fn reload(&self) -> Result<RouteTable, String> {
        self.restart_all().await;
        self.boot().await
    }

    /// Boot all workers in parallel. The first successful boot provides
    /// the route table; remaining boots complete asynchronously.
    /// Boot all workers in parallel. The first successful boot provides
    /// the route table; remaining boots complete asynchronously.
    pub async fn boot(&self) -> Result<RouteTable, String> {
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

        let mut last_err = String::new();
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
                    last_err = e;
                }
            }
        }

        Err(format!("all workers failed to boot: {last_err}"))
    }

    /// Restart all workers (used by dev mode file watcher).
    pub async fn restart_all(&self) {
        let mut handles = Vec::new();
        for worker in self.workers.iter() {
            let tx = worker.tx.clone();
            handles.push(tokio::spawn(async move {
                let (reply_tx, reply_rx) = oneshot::channel();
                let _ = tx.send(WorkerMessage::Restart { reply: reply_tx });
                let _ = reply_rx.await;
            }));
        }
        for handle in handles {
            let _ = handle.await;
        }
    }

    /// Dispatch a request to the worker with the fewest in-flight requests.
    pub async fn handle(&self, request: WorkerRequest) -> Result<WorkerResponse, String> {
        let index = self.workers.iter()
            .enumerate()
            .min_by_key(|(_, w)| w.pending_count())
            .map(|(i, _)| i)
            .unwrap_or(0);

        self.workers[index].handle(request).await
    }
}
