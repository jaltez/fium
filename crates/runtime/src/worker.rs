use fium_transport::{WorkerRequest, WorkerResponse, PROTOCOL_VERSION};
use tokio::{
    io::{AsyncBufReadExt, AsyncWriteExt, BufReader},
    process::{Child, ChildStdin, ChildStdout, Command},
    sync::Mutex,
};
use tracing::{info, warn};

use std::{path::PathBuf, sync::Arc};

#[derive(Debug, Clone)]
pub struct WorkerSupervisor {
    php_binary: String,
    worker_entrypoint: PathBuf,
    process: Arc<Mutex<Option<WorkerProcess>>>,
}

#[derive(Debug)]
struct WorkerProcess {
    child: Child,
    stdin: ChildStdin,
    stdout: BufReader<ChildStdout>,
}

impl WorkerSupervisor {
    pub fn new(worker_entrypoint: impl Into<String>) -> Self {
        Self {
            php_binary: "php".to_string(),
            worker_entrypoint: PathBuf::from(worker_entrypoint.into()),
            process: Arc::new(Mutex::new(None)),
        }
    }

    pub async fn handle(&self, request: WorkerRequest) -> Result<WorkerResponse, String> {
        match self.dispatch_with_restart(&request).await {
            Ok(response) => Ok(response),
            Err(first_error) => {
                warn!(%first_error, "worker request failed, attempting restart");
                self.restart().await?;
                self.dispatch_once(&request).await
            }
        }
    }

    async fn dispatch_with_restart(&self, request: &WorkerRequest) -> Result<WorkerResponse, String> {
        self.dispatch_once(request).await
    }

    async fn dispatch_once(&self, request: &WorkerRequest) -> Result<WorkerResponse, String> {
        let mut process = self.process.lock().await;
        let process = self.ensure_started(&mut process).await?;

        let encoded = serde_json::to_string(request)
            .map_err(|error| format!("failed to encode worker request: {error}"))?;

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
            .map_err(|error| format!("failed to flush worker request: {error}"))?;

        let mut line = String::new();
        let bytes_read = process
            .stdout
            .read_line(&mut line)
            .await
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

    async fn restart(&self) -> Result<(), String> {
        let mut process = self.process.lock().await;
        if let Some(existing) = process.as_mut() {
            if let Err(error) = existing.child.start_kill() {
                warn!(%error, "failed to signal worker kill during restart");
            }
        }
        *process = None;
        self.ensure_started(&mut process).await?;
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
            *slot = Some(self.spawn_worker().await?);
        }

        slot.as_mut()
            .ok_or_else(|| "worker process unavailable after startup".to_string())
    }

    async fn spawn_worker(&self) -> Result<WorkerProcess, String> {
        info!(entrypoint = %self.worker_entrypoint.display(), "starting PHP worker process");

        let mut child = Command::new(&self.php_binary)
            .arg(&self.worker_entrypoint)
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
