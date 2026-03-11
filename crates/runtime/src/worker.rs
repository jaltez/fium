use fium_transport::{BootMessage, WorkerRequest, WorkerResponse, PROTOCOL_VERSION};
use tokio::{
    io::{AsyncBufReadExt, AsyncWriteExt, BufReader},
    process::{Child, ChildStdin, ChildStdout, Command},
    sync::Mutex,
    time::{timeout, Duration},
};
use tracing::{info, warn};

use std::{env, path::PathBuf, sync::Arc};
use std::sync::atomic::{AtomicUsize, Ordering};

use crate::route::RouteTable;

#[derive(Debug, Clone)]
pub struct WorkerSupervisor {
    php_binary: String,
    worker_entrypoint: PathBuf,
    app_file: PathBuf,
    request_timeout: Duration,
    boot_timeout: Duration,
    process: Arc<Mutex<Option<WorkerProcess>>>,
}

#[derive(Debug)]
struct WorkerProcess {
    child: Child,
    stdin: ChildStdin,
    stdout: BufReader<ChildStdout>,
}

impl WorkerSupervisor {
    pub fn new(worker_entrypoint: impl Into<String>, app_file: impl Into<String>) -> Self {
        Self {
            php_binary: "php".to_string(),
            worker_entrypoint: PathBuf::from(worker_entrypoint.into()),
            app_file: PathBuf::from(app_file.into()),
            request_timeout: resolve_request_timeout(),
            boot_timeout: Duration::from_secs(10),
            process: Arc::new(Mutex::new(None)),
        }
    }

    /// Spawn the PHP worker and read the boot message containing the route manifest.
    /// Returns the RouteTable built from the worker's declared routes.
    pub async fn boot(&self) -> Result<RouteTable, String> {
        let mut process = self.process.lock().await;

        let worker = self.spawn_worker().await?;
        *process = Some(worker);

        let worker = process.as_mut().unwrap();

        let mut line = String::new();
        let bytes_read = timeout(self.boot_timeout, worker.stdout.read_line(&mut line))
            .await
            .map_err(|_| {
                format!(
                    "worker boot timed out after {} ms",
                    self.boot_timeout.as_millis()
                )
            })?
            .map_err(|error| format!("failed to read worker boot message: {error}"))?;

        if bytes_read == 0 {
            return Err("worker closed stdout before sending boot message".to_string());
        }

        let boot: BootMessage = serde_json::from_str(line.trim_end())
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

    pub async fn handle(&self, request: WorkerRequest) -> Result<WorkerResponse, String> {
        let mut process = self.process.lock().await;

        match self.dispatch_once_locked(&mut process, &request).await {
            Ok(response) => Ok(response),
            Err(first_error) => {
                warn!(%first_error, "worker request failed, attempting restart");
                self.restart_locked(&mut process).await?;

                match self.dispatch_once_locked(&mut process, &request).await {
                    Ok(response) => Ok(response),
                    Err(second_error) => {
                        warn!(%second_error, "worker request failed after restart, replacing worker before returning error");

                        match self.restart_locked(&mut process).await {
                            Ok(()) => Err(second_error),
                            Err(restart_error) => Err(format!(
                                "{second_error}; additionally failed to replace worker after retry failure: {restart_error}"
                            )),
                        }
                    }
                }
            }
        }
    }

    async fn dispatch_once_locked(
        &self,
        slot: &mut Option<WorkerProcess>,
        request: &WorkerRequest,
    ) -> Result<WorkerResponse, String> {
        let process = self.ensure_started(slot).await?;

        let encoded = serde_json::to_string(request)
            .map_err(|error| format!("failed to encode worker request: {error}"))?;

        timeout(self.request_timeout, async {
            process
                .stdin
                .write_all(encoded.as_bytes())
                .await
                .map_err(|error| format!("failed to write request to worker stdin: {error}"))?;
            process
                .stdin
                .write_all(b"\n")
                .await
                .map_err(|error| format!("failed to frame worker request: {error}"))?;
            process
                .stdin
                .flush()
                .await
                .map_err(|error| format!("failed to flush worker request: {error}"))
        })
        .await
        .map_err(|_| {
            format!(
                "worker request timed out while writing request {} after {} ms",
                request.request_id,
                self.request_timeout.as_millis()
            )
        })??;

        let mut line = String::new();
        let bytes_read = timeout(self.request_timeout, process.stdout.read_line(&mut line))
            .await
            .map_err(|_| {
                format!(
                    "worker request timed out while reading response {} after {} ms",
                    request.request_id,
                    self.request_timeout.as_millis()
                )
            })?
            .map_err(|error| format!("failed to read worker response: {error}"))?;

        if bytes_read == 0 {
            return Err("worker closed stdout unexpectedly".to_string());
        }

        let response: WorkerResponse = serde_json::from_str(line.trim_end())
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

    async fn restart_locked(&self, slot: &mut Option<WorkerProcess>) -> Result<(), String> {
        if let Some(existing) = slot.as_mut() {
            if let Err(error) = existing.child.start_kill() {
                warn!(%error, "failed to signal worker kill during restart");
            } else {
                match timeout(Duration::from_secs(1), existing.child.wait()).await {
                    Ok(Ok(_status)) => {}
                    Ok(Err(error)) => {
                        warn!(%error, "failed while waiting for worker exit during restart");
                    }
                    Err(error) => {
                        warn!(?error, "timed out waiting for worker exit during restart");
                    }
                }
            }
        }

        *slot = None;
        Ok(())
    }

    async fn ensure_started<'a>(
        &self,
        slot: &'a mut Option<WorkerProcess>,
    ) -> Result<&'a mut WorkerProcess, String> {
        let needs_start = match slot.as_mut() {
            Some(process) => match process.child.try_wait() {
                Ok(Some(status)) => {
                    warn!(?status, "worker exited, starting a new one");
                    true
                }
                Ok(None) => false,
                Err(error) => {
                    warn!(%error, "failed to inspect worker status, restarting");
                    true
                }
            },
            None => true,
        };

        if needs_start {
            let mut worker = self.spawn_worker().await?;
            // Consume the boot message that every new PHP worker emits on startup.
            let mut boot_line = String::new();
            timeout(self.boot_timeout, worker.stdout.read_line(&mut boot_line))
                .await
                .map_err(|_| "timed out waiting for boot message from restarted worker".to_string())?
                .map_err(|error| format!("failed to read boot message from restarted worker: {error}"))?;
            *slot = Some(worker);
        }

        slot.as_mut()
            .ok_or_else(|| "worker process unavailable after startup".to_string())
    }

    async fn spawn_worker(&self) -> Result<WorkerProcess, String> {
        info!(entrypoint = %self.worker_entrypoint.display(), "starting PHP worker process");

        let mut child = Command::new(&self.php_binary)
            .arg(&self.worker_entrypoint)
            .arg(&self.app_file)
            .stdin(std::process::Stdio::piped())
            .stdout(std::process::Stdio::piped())
            .stderr(std::process::Stdio::inherit())
            .spawn()
            .map_err(|error| format!(
                "failed to spawn PHP worker using '{}' and '{}': {error}",
                self.php_binary,
                self.worker_entrypoint.display()
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

fn resolve_request_timeout() -> Duration {
    const DEFAULT_TIMEOUT_MS: u64 = 750;

    match env::var("FIUM_WORKER_TIMEOUT_MS") {
        Ok(value) => match value.parse::<u64>() {
            Ok(milliseconds) if milliseconds > 0 => Duration::from_millis(milliseconds),
            Ok(_) | Err(_) => {
                warn!(value = %value, fallback_ms = DEFAULT_TIMEOUT_MS, "invalid FIUM_WORKER_TIMEOUT_MS, using default");
                Duration::from_millis(DEFAULT_TIMEOUT_MS)
            }
        },
        Err(_) => Duration::from_millis(DEFAULT_TIMEOUT_MS),
    }
}

/// A pool of PHP worker processes that distributes requests round-robin.
#[derive(Clone)]
pub struct WorkerPool {
    workers: Arc<Vec<WorkerSupervisor>>,
    next: Arc<AtomicUsize>,
}

impl WorkerPool {
    pub fn new(
        worker_entrypoint: impl Into<String>,
        app_file: impl Into<String>,
        count: usize,
    ) -> Self {
        let entrypoint = worker_entrypoint.into();
        let app = app_file.into();
        let count = count.max(1);

        let workers: Vec<WorkerSupervisor> = (0..count)
            .map(|_| WorkerSupervisor::new(entrypoint.clone(), app.clone()))
            .collect();

        Self {
            workers: Arc::new(workers),
            next: Arc::new(AtomicUsize::new(0)),
        }
    }

    /// Boot the first worker and get the route table, then boot the remaining workers.
    pub async fn boot(&self) -> Result<RouteTable, String> {
        // Boot the first worker to discover routes
        let routes = self.workers[0].boot().await?;

        // Boot remaining workers in parallel
        let mut handles = Vec::new();
        for worker in self.workers.iter().skip(1) {
            let worker = worker.clone();
            handles.push(tokio::spawn(async move { worker.boot().await }));
        }

        for handle in handles {
            handle
                .await
                .map_err(|error| format!("worker boot task panicked: {error}"))?
                .map_err(|error| format!("worker boot failed: {error}"))?;
        }

        info!(workers = self.workers.len(), "all workers booted");

        Ok(routes)
    }

    /// Dispatch a request to the next available worker (round-robin).
    pub async fn handle(&self, request: WorkerRequest) -> Result<WorkerResponse, String> {
        let index = self.next.fetch_add(1, Ordering::Relaxed) % self.workers.len();
        self.workers[index].handle(request).await
    }
}
