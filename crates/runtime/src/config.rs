use serde::Deserialize;
use std::path::{Path, PathBuf};
use tracing::info;

/// Runtime configuration loaded from optional `fium.toml` and CLI overrides.
#[derive(Debug, Clone)]
pub struct RuntimeConfig {
    pub host: String,
    pub port: u16,
    pub workers: usize,
    pub max_requests: u64,
    pub worker_timeout_ms: u64,
    pub body_max_size: usize,

    // TLS
    pub tls_cert: Option<PathBuf>,
    pub tls_key: Option<PathBuf>,
    pub tls_self_signed: bool,

    // Logging
    pub log_level: String,
    pub log_format: LogFormat,

    // Static files
    pub static_dir: Option<PathBuf>,
    pub static_enabled: bool,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LogFormat {
    Pretty,
    Json,
}

/// Raw TOML file structure
#[derive(Debug, Deserialize, Default)]
struct TomlConfig {
    #[serde(default)]
    server: TomlServer,
    #[serde(default)]
    tls: TomlTls,
    #[serde(default)]
    log: TomlLog,
    #[serde(rename = "static")]
    #[serde(default)]
    static_files: TomlStatic,
}

#[derive(Debug, Deserialize, Default)]
struct TomlServer {
    host: Option<String>,
    port: Option<u16>,
    workers: Option<usize>,
    max_requests: Option<u64>,
    worker_timeout_ms: Option<u64>,
    body_max_size: Option<String>,
}

#[derive(Debug, Deserialize, Default)]
struct TomlTls {
    cert: Option<String>,
    key: Option<String>,
}

#[derive(Debug, Deserialize, Default)]
struct TomlLog {
    level: Option<String>,
    format: Option<String>,
}

#[derive(Debug, Deserialize, Default)]
struct TomlStatic {
    dir: Option<String>,
    enabled: Option<bool>,
}

fn default_workers() -> usize {
    std::thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(4)
}

fn parse_size(s: &str) -> Option<usize> {
    let s = s.trim().to_lowercase();
    if let Some(num) = s.strip_suffix("mb") {
        num.trim().parse::<usize>().ok().map(|n| n * 1024 * 1024)
    } else if let Some(num) = s.strip_suffix("kb") {
        num.trim().parse::<usize>().ok().map(|n| n * 1024)
    } else if let Some(num) = s.strip_suffix("gb") {
        num.trim().parse::<usize>().ok().map(|n| n * 1024 * 1024 * 1024)
    } else {
        s.parse::<usize>().ok()
    }
}

impl RuntimeConfig {
    /// Build configuration by layering: defaults → fium.toml → CLI overrides.
    pub fn load(
        app_dir: &Path,
        cli_host: Option<&str>,
        cli_port: Option<u16>,
        cli_workers: Option<usize>,
        cli_tls_cert: Option<&str>,
        cli_tls_key: Option<&str>,
        cli_tls_self_signed: bool,
    ) -> Self {
        // Try to load fium.toml from the app directory
        let toml_path = app_dir.join("fium.toml");
        let toml = if toml_path.is_file() {
            info!(path = %toml_path.display(), "loading configuration file");
            match std::fs::read_to_string(&toml_path) {
                Ok(content) => match toml::from_str::<TomlConfig>(&content) {
                    Ok(config) => config,
                    Err(err) => {
                        eprintln!("[fium] WARNING: failed to parse fium.toml: {err}");
                        TomlConfig::default()
                    }
                },
                Err(err) => {
                    eprintln!("[fium] WARNING: failed to read fium.toml: {err}");
                    TomlConfig::default()
                }
            }
        } else {
            TomlConfig::default()
        };

        let body_max_size = toml
            .server
            .body_max_size
            .as_deref()
            .and_then(parse_size)
            .unwrap_or(1024 * 1024); // 1MB default

        let log_format = match toml.log.format.as_deref() {
            Some("json") => LogFormat::Json,
            _ => LogFormat::Pretty,
        };

        let static_dir = toml.static_files.dir.as_ref().map(|d| app_dir.join(d));

        Self {
            host: cli_host
                .map(String::from)
                .or(toml.server.host)
                .unwrap_or_else(|| "127.0.0.1".to_string()),
            port: cli_port
                .or(toml.server.port)
                .unwrap_or(3000),
            workers: cli_workers
                .or(toml.server.workers)
                .map(|w| if w == 0 { default_workers() } else { w })
                .unwrap_or_else(default_workers),
            max_requests: toml.server.max_requests.unwrap_or(0),
            worker_timeout_ms: toml
                .server
                .worker_timeout_ms
                .unwrap_or(750),
            body_max_size,
            tls_cert: cli_tls_cert
                .map(PathBuf::from)
                .or_else(|| toml.tls.cert.map(|c| app_dir.join(c))),
            tls_key: cli_tls_key
                .map(PathBuf::from)
                .or_else(|| toml.tls.key.map(|k| app_dir.join(k))),
            tls_self_signed: cli_tls_self_signed,
            log_level: toml
                .log
                .level
                .unwrap_or_else(|| "info".to_string()),
            log_format,
            static_dir,
            static_enabled: toml.static_files.enabled.unwrap_or(false),
        }
    }

    pub fn tls_enabled(&self) -> bool {
        self.tls_self_signed || (self.tls_cert.is_some() && self.tls_key.is_some())
    }
}
