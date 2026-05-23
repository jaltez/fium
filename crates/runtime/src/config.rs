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
    pub tuning: TuningConfig,
    pub compression: CompressionConfig,

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

    // Trusted proxies (cached from env at startup)
    pub trusted_proxies: TrustedProxies,
}

#[derive(Debug, Clone)]
pub enum TrustedProxies {
    /// No trusted proxies (matches empty string or unset)
    None,
    /// Trust all proxies (matches "*")
    All,
    /// Trust specific IPs
    Some(Vec<std::net::IpAddr>),
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LogFormat {
    Pretty,
    Json,
}

#[derive(Debug, Clone)]
pub struct TuningConfig {
    pub shutdown_timeout_secs: u64,
    pub keep_alive_timeout_secs: u64,
    pub max_connections: u32,
    pub worker_boot_timeout_ms: u64,
    pub reuse_addr: bool,
}

#[derive(Debug, Clone)]
pub struct CompressionConfig {
    pub algorithms: Vec<CompressionAlgorithm>,
    pub level: i32,
    pub static_cache_max_age_secs: u64,
}

impl CompressionConfig {
    pub fn gzip_enabled(&self) -> bool {
        self.algorithms.contains(&CompressionAlgorithm::Gzip)
    }

    pub fn br_enabled(&self) -> bool {
        self.algorithms.contains(&CompressionAlgorithm::Br)
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum CompressionAlgorithm {
    Gzip,
    Br,
}

#[derive(Debug, Clone, Default)]
pub struct CliOverrides {
    pub host: Option<String>,
    pub port: Option<u16>,
    pub workers: Option<usize>,
    pub tls_cert: Option<PathBuf>,
    pub tls_key: Option<PathBuf>,
    pub tls_self_signed: bool,
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
    #[serde(default)]
    tuning: TomlTuning,
    #[serde(default)]
    compression: TomlCompression,
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
    cert: Option<PathBuf>,
    key: Option<PathBuf>,
}

#[derive(Debug, Deserialize, Default)]
struct TomlLog {
    level: Option<String>,
    format: Option<String>,
}

#[derive(Debug, Deserialize, Default)]
struct TomlStatic {
    dir: Option<PathBuf>,
    enabled: Option<bool>,
}

#[derive(Debug, Deserialize, Default)]
struct TomlTuning {
    shutdown_timeout_secs: Option<u64>,
    keep_alive_timeout_secs: Option<u64>,
    max_connections: Option<u32>,
    worker_boot_timeout_ms: Option<u64>,
    reuse_addr: Option<bool>,
}

#[derive(Debug, Deserialize, Default)]
struct TomlCompression {
    algorithms: Option<Vec<String>>,
    level: Option<i32>,
    static_cache_max_age_secs: Option<u64>,
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

fn parse_compression_algorithms(raw: Option<Vec<String>>) -> Vec<CompressionAlgorithm> {
    let Some(raw) = raw else {
        return vec![CompressionAlgorithm::Gzip, CompressionAlgorithm::Br];
    };

    let mut algorithms = Vec::new();

    for algorithm in raw {
        match algorithm.trim().to_ascii_lowercase().as_str() {
            "gzip" => {
                if !algorithms.contains(&CompressionAlgorithm::Gzip) {
                    algorithms.push(CompressionAlgorithm::Gzip);
                }
            }
            "br" | "brotli" => {
                if !algorithms.contains(&CompressionAlgorithm::Br) {
                    algorithms.push(CompressionAlgorithm::Br);
                }
            }
            "" => {}
            other => eprintln!(
                "[fium] WARNING: unsupported compression algorithm '{other}', ignoring it"
            ),
        }
    }

    algorithms
}

fn parse_compression_level(raw: Option<i32>) -> i32 {
    let level = raw.unwrap_or(4);
    if !(1..=11).contains(&level) {
        eprintln!(
            "[fium] WARNING: compression.level must be between 1 and 11, clamping {}",
            level
        );
    }

    level.clamp(1, 11)
}

fn resolve_toml_paths(app_dir: &Path, toml: &mut TomlConfig) {
    toml.tls.cert = toml
        .tls
        .cert
        .take()
        .map(|path| resolve_path(app_dir, path));
    toml.tls.key = toml
        .tls
        .key
        .take()
        .map(|path| resolve_path(app_dir, path));
    toml.static_files.dir = toml
        .static_files
        .dir
        .take()
        .map(|path| resolve_path(app_dir, path));
}

fn resolve_path(app_dir: &Path, path: PathBuf) -> PathBuf {
    if path.is_absolute() {
        path
    } else {
        app_dir.join(path)
    }
}

impl TrustedProxies {
    pub fn from_env() -> Self {
        let trusted = std::env::var("FIUM_TRUSTED_PROXIES").unwrap_or_default();
        if trusted.is_empty() {
            TrustedProxies::None
        } else if trusted == "*" {
            TrustedProxies::All
        } else {
            let ips: Vec<std::net::IpAddr> = trusted
                .split(',')
                .filter_map(|p| p.trim().parse().ok())
                .collect();
            TrustedProxies::Some(ips)
        }
    }

    pub fn contains(&self, addr: &std::net::SocketAddr) -> bool {
        match self {
            TrustedProxies::None => addr.ip().is_loopback(),
            TrustedProxies::All => true,
            TrustedProxies::Some(ips) => ips.iter().any(|ip| *ip == addr.ip()),
        }
    }
}

impl RuntimeConfig {
    fn defaults() -> Self {
        Self {
            host: "127.0.0.1".to_string(),
            port: 3000,
            workers: default_workers(),
            max_requests: 0,
            worker_timeout_ms: 5_000,
            body_max_size: 1024 * 1024,
            tuning: TuningConfig {
                shutdown_timeout_secs: 30,
                keep_alive_timeout_secs: 60,
                max_connections: 1024,
                worker_boot_timeout_ms: 10_000,
                reuse_addr: true,
            },
            compression: CompressionConfig {
                algorithms: vec![CompressionAlgorithm::Gzip, CompressionAlgorithm::Br],
                level: 4,
                static_cache_max_age_secs: 3600,
            },
            tls_cert: None,
            tls_key: None,
            tls_self_signed: false,
            log_level: "info".to_string(),
            log_format: LogFormat::Pretty,
            static_dir: None,
            static_enabled: false,
            trusted_proxies: TrustedProxies::from_env(),
        }
    }

    fn compose(defaults: Self, toml: TomlConfig, cli: CliOverrides) -> Self {
        let body_max_size = toml
            .server
            .body_max_size
            .as_deref()
            .and_then(parse_size)
            .unwrap_or(defaults.body_max_size);

        let log_format = match toml.log.format.as_deref() {
            Some("json") => LogFormat::Json,
            Some("pretty") | None => defaults.log_format,
            Some(_) => defaults.log_format,
        };

        let tuning = TuningConfig {
            shutdown_timeout_secs: toml
                .tuning
                .shutdown_timeout_secs
                .unwrap_or(defaults.tuning.shutdown_timeout_secs),
            keep_alive_timeout_secs: toml
                .tuning
                .keep_alive_timeout_secs
                .unwrap_or(defaults.tuning.keep_alive_timeout_secs),
            max_connections: toml
                .tuning
                .max_connections
                .unwrap_or(defaults.tuning.max_connections)
                .max(1),
            worker_boot_timeout_ms: toml
                .tuning
                .worker_boot_timeout_ms
                .unwrap_or(defaults.tuning.worker_boot_timeout_ms),
            reuse_addr: toml
                .tuning
                .reuse_addr
                .unwrap_or(defaults.tuning.reuse_addr),
        };
        let compression = CompressionConfig {
            algorithms: toml
                .compression
                .algorithms
                .map_or_else(
                    || defaults.compression.algorithms.clone(),
                    |algorithms| parse_compression_algorithms(Some(algorithms)),
                ),
            level: parse_compression_level(Some(
                toml.compression.level.unwrap_or(defaults.compression.level),
            )),
            static_cache_max_age_secs: toml
                .compression
                .static_cache_max_age_secs
                .unwrap_or(defaults.compression.static_cache_max_age_secs),
        };

        Self {
            host: cli.host.or(toml.server.host).unwrap_or(defaults.host),
            port: cli.port.or(toml.server.port).unwrap_or(defaults.port),
            workers: cli
                .workers
                .or(toml.server.workers)
                .map(|w| if w == 0 { default_workers() } else { w })
                .unwrap_or(defaults.workers),
            max_requests: toml.server.max_requests.unwrap_or(defaults.max_requests),
            worker_timeout_ms: toml
                .server
                .worker_timeout_ms
                .unwrap_or(defaults.worker_timeout_ms),
            body_max_size,
            tuning,
            compression,
            tls_cert: cli.tls_cert.or(toml.tls.cert).or(defaults.tls_cert),
            tls_key: cli.tls_key.or(toml.tls.key).or(defaults.tls_key),
            tls_self_signed: cli.tls_self_signed || defaults.tls_self_signed,
            log_level: toml.log.level.unwrap_or(defaults.log_level),
            log_format,
            static_dir: toml.static_files.dir.or(defaults.static_dir),
            static_enabled: toml
                .static_files
                .enabled
                .unwrap_or(defaults.static_enabled),
            trusted_proxies: defaults.trusted_proxies,
        }
    }

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
        let mut toml = toml;
        resolve_toml_paths(app_dir, &mut toml);

        Self::compose(
            Self::defaults(),
            toml,
            CliOverrides {
                host: cli_host.map(String::from),
                port: cli_port,
                workers: cli_workers,
                tls_cert: cli_tls_cert.map(PathBuf::from),
                tls_key: cli_tls_key.map(PathBuf::from),
                tls_self_signed: cli_tls_self_signed,
            },
        )
    }

    pub fn tls_enabled(&self) -> bool {
        self.tls_self_signed || (self.tls_cert.is_some() && self.tls_key.is_some())
    }
}

#[cfg(test)]
mod tests {
    use super::{
        parse_compression_algorithms, parse_compression_level, parse_size, resolve_toml_paths,
        CliOverrides, CompressionAlgorithm, LogFormat, RuntimeConfig, TomlCompression, TomlConfig,
        TomlLog, TomlServer, TomlStatic, TomlTls, TomlTuning, TrustedProxies,
    };
    use std::path::{Path, PathBuf};

    #[test]
    fn parse_size_supports_common_units_and_invalid_input() {
        assert_eq!(parse_size("1mb"), Some(1_048_576));
        assert_eq!(parse_size("500KB"), Some(512_000));
        assert_eq!(parse_size("2gb"), Some(2usize * 1024 * 1024 * 1024));
        assert_eq!(parse_size("100"), Some(100));
        assert_eq!(parse_size(""), None);
        assert_eq!(parse_size("invalid"), None);
        assert_eq!(parse_size("99999999999999999999"), None);
    }

    #[test]
    fn parse_compression_algorithms_supports_gzip_and_brotli() {
        assert_eq!(
            parse_compression_algorithms(Some(vec!["gzip".into(), "br".into()])),
            vec![CompressionAlgorithm::Gzip, CompressionAlgorithm::Br]
        );
        assert_eq!(
            parse_compression_algorithms(Some(vec!["brotli".into()])),
            vec![CompressionAlgorithm::Br]
        );
        assert_eq!(
            parse_compression_algorithms(None),
            vec![CompressionAlgorithm::Gzip, CompressionAlgorithm::Br]
        );
    }

    #[test]
    fn parse_compression_level_clamps_out_of_range_values() {
        assert_eq!(parse_compression_level(Some(0)), 1);
        assert_eq!(parse_compression_level(Some(4)), 4);
        assert_eq!(parse_compression_level(Some(99)), 11);
    }

    #[test]
    fn compose_applies_defaults_then_toml_then_cli() {
        let defaults = RuntimeConfig {
            host: "127.0.0.1".into(),
            port: 3000,
            workers: 2,
            max_requests: 0,
            worker_timeout_ms: 5_000,
            body_max_size: 1_024,
            tuning: super::TuningConfig {
                shutdown_timeout_secs: 30,
                keep_alive_timeout_secs: 60,
                max_connections: 1024,
                worker_boot_timeout_ms: 10_000,
                reuse_addr: true,
            },
            compression: super::CompressionConfig {
                algorithms: vec![CompressionAlgorithm::Gzip, CompressionAlgorithm::Br],
                level: 4,
                static_cache_max_age_secs: 3600,
            },
            tls_cert: None,
            tls_key: None,
            tls_self_signed: false,
            log_level: "info".into(),
            log_format: LogFormat::Pretty,
            static_dir: None,
            static_enabled: false,
            trusted_proxies: TrustedProxies::None,
        };

        let toml = TomlConfig {
            server: TomlServer {
                host: Some("0.0.0.0".into()),
                port: Some(8080),
                workers: Some(3),
                max_requests: Some(25),
                worker_timeout_ms: Some(9_000),
                body_max_size: Some("2mb".into()),
            },
            tls: TomlTls {
                cert: Some(PathBuf::from("/tmp/cert.pem")),
                key: Some(PathBuf::from("/tmp/key.pem")),
            },
            log: TomlLog {
                level: Some("debug".into()),
                format: Some("json".into()),
            },
            static_files: TomlStatic {
                dir: Some(PathBuf::from("/tmp/public")),
                enabled: Some(true),
            },
            tuning: TomlTuning {
                shutdown_timeout_secs: Some(10),
                keep_alive_timeout_secs: Some(20),
                max_connections: Some(50),
                worker_boot_timeout_ms: Some(4_000),
                reuse_addr: Some(false),
            },
            compression: TomlCompression {
                algorithms: Some(vec!["gzip".into()]),
                level: Some(7),
                static_cache_max_age_secs: Some(42),
            },
        };

        let cli = CliOverrides {
            host: Some("127.0.0.2".into()),
            port: Some(9090),
            workers: Some(4),
            tls_self_signed: true,
            ..Default::default()
        };

        let composed = RuntimeConfig::compose(defaults, toml, cli);
        assert_eq!(composed.host, "127.0.0.2");
        assert_eq!(composed.port, 9090);
        assert_eq!(composed.workers, 4);
        assert_eq!(composed.max_requests, 25);
        assert_eq!(composed.worker_timeout_ms, 9_000);
        assert_eq!(composed.body_max_size, 2 * 1024 * 1024);
        assert_eq!(composed.log_level, "debug");
        assert_eq!(composed.log_format, LogFormat::Json);
        assert_eq!(composed.static_dir, Some(PathBuf::from("/tmp/public")));
        assert!(composed.static_enabled);
        assert_eq!(composed.tuning.shutdown_timeout_secs, 10);
        assert_eq!(composed.tuning.keep_alive_timeout_secs, 20);
        assert_eq!(composed.tuning.max_connections, 50);
        assert_eq!(composed.tuning.worker_boot_timeout_ms, 4_000);
        assert!(!composed.tuning.reuse_addr);
        assert_eq!(composed.compression.algorithms, vec![CompressionAlgorithm::Gzip]);
        assert_eq!(composed.compression.level, 7);
        assert_eq!(composed.compression.static_cache_max_age_secs, 42);
        assert_eq!(composed.tls_cert, Some(PathBuf::from("/tmp/cert.pem")));
        assert_eq!(composed.tls_key, Some(PathBuf::from("/tmp/key.pem")));
        assert!(composed.tls_self_signed);
    }

    #[test]
    fn resolve_toml_paths_makes_relative_entries_absolute() {
        let mut toml = TomlConfig {
            tls: TomlTls {
                cert: Some(PathBuf::from("certs/server.crt")),
                key: Some(PathBuf::from("certs/server.key")),
            },
            static_files: TomlStatic {
                dir: Some(PathBuf::from("public")),
                enabled: Some(true),
            },
            ..Default::default()
        };

        resolve_toml_paths(Path::new("/srv/app"), &mut toml);

        assert_eq!(toml.tls.cert, Some(PathBuf::from("/srv/app/certs/server.crt")));
        assert_eq!(toml.tls.key, Some(PathBuf::from("/srv/app/certs/server.key")));
        assert_eq!(toml.static_files.dir, Some(PathBuf::from("/srv/app/public")));
    }
}
