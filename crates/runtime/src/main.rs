mod config;
mod embed;
mod ratelimit;
mod route;
mod worker;

use arc_swap::ArcSwap;
use axum::{
    body::Body,
    extract::{ConnectInfo, Request, State},
    http::{HeaderValue, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use clap::Parser;
use config::{CompressionConfig, LogFormat, RuntimeConfig};
use fium_transport::{BootCors, CookieMap, HeaderMap, SetCookie, WorkerRequest, PROTOCOL_VERSION};
use http_body_util::BodyExt;
use ratelimit::{Allow, RateLimiter};
use route::RouteTable;
use socket2::{Domain, Protocol, Socket, Type};
use std::{
    net::SocketAddr,
    path::PathBuf,
    sync::{
        atomic::{AtomicU64, Ordering},
        Arc,
    },
    time::Instant,
};
use tokio::{fs::File, io::AsyncWriteExt, time::Duration};
use tower_http::compression::{predicate::SizeAbove, CompressionLayer, CompressionLevel};
use tower_http::set_header::SetResponseHeaderLayer;
use tracing::{error, info, warn};
use uuid::Uuid;
use worker::{RuntimeWorkerError, WorkerPool};

const REQUEST_DURATION_BUCKETS: [f64; 13] = [
    0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0,
];
const MAX_IN_MEMORY_REQUEST_BODY_BYTES: usize = 64 * 1024;

#[derive(Parser)]
#[command(
    name = "fium",
    about = "A faster PHP miniframework — Laravel-lite, powered by Rust"
)]
enum Cli {
    /// Start the HTTP server
    Serve {
        /// Path to the PHP application file
        #[arg(default_value = "app.php")]
        app: PathBuf,

        /// Host to bind to
        #[arg(long)]
        host: Option<String>,

        /// Port to bind to
        #[arg(short, long)]
        port: Option<u16>,

        /// Number of PHP worker processes
        #[arg(short, long)]
        workers: Option<usize>,

        /// Path to TLS certificate file
        #[arg(long)]
        tls_cert: Option<String>,

        /// Path to TLS private key file
        #[arg(long)]
        tls_key: Option<String>,

        /// Generate and use a self-signed TLS certificate for development
        #[arg(long, default_value_t = false)]
        tls_self_signed: bool,
    },

    /// Start the HTTP server in development mode with file watching
    Dev {
        /// Path to the PHP application file
        #[arg(default_value = "app.php")]
        app: PathBuf,

        /// Host to bind to
        #[arg(long)]
        host: Option<String>,

        /// Port to bind to
        #[arg(short, long)]
        port: Option<u16>,

        /// Number of PHP worker processes
        #[arg(short, long)]
        workers: Option<usize>,
    },

    /// Scaffold a new fium project
    Init {
        /// Directory name for the new project
        #[arg(default_value = ".")]
        directory: PathBuf,
    },

    /// List the routes declared by the app (boots it — same env as `serve`)
    Routes {
        /// Path to the PHP application file
        #[arg(default_value = "app.php")]
        app: PathBuf,
    },

    /// Resolve a request and show the middleware chain that would run
    Explain {
        /// HTTP method, e.g. GET
        method: String,
        /// Request path, e.g. /admin or /users/42
        path: String,
        /// Path to the PHP application file
        #[arg(default_value = "app.php")]
        app: PathBuf,
    },
}

#[derive(Clone)]
struct AppState {
    routes: Arc<ArcSwap<RouteTable>>,
    pool: WorkerPool,
    config: Arc<RuntimeConfig>,
    start_time: Instant,
    request_latency: Arc<RequestDurationHistogram>,
    rate_limiter: Arc<RateLimiter>,
}

#[derive(Debug, Default)]
struct RequestDurationHistogram {
    buckets: Box<[AtomicU64]>,
    sum_micros: AtomicU64,
    count: AtomicU64,
}

impl RequestDurationHistogram {
    fn new() -> Self {
        Self {
            buckets: (0..=REQUEST_DURATION_BUCKETS.len())
                .map(|_| AtomicU64::new(0))
                .collect::<Vec<_>>()
                .into_boxed_slice(),
            sum_micros: AtomicU64::new(0),
            count: AtomicU64::new(0),
        }
    }

    fn record(&self, elapsed: Duration) {
        let elapsed_secs = elapsed.as_secs_f64();
        let bucket_index = REQUEST_DURATION_BUCKETS
            .iter()
            .position(|bound| elapsed_secs <= *bound)
            .unwrap_or(REQUEST_DURATION_BUCKETS.len());
        let elapsed_micros = elapsed.as_micros().min(u64::MAX as u128) as u64;

        self.buckets[bucket_index].fetch_add(1, Ordering::Relaxed);
        self.sum_micros.fetch_add(elapsed_micros, Ordering::Relaxed);
        self.count.fetch_add(1, Ordering::Relaxed);
    }

    fn render_prometheus(&self) -> String {
        let mut body = String::from(
            "# HELP fium_request_duration_seconds Request latency histogram.\n\
             # TYPE fium_request_duration_seconds histogram\n",
        );
        let mut cumulative = 0u64;

        for (index, count) in self.buckets.iter().enumerate() {
            cumulative += count.load(Ordering::Relaxed);
            let bound = REQUEST_DURATION_BUCKETS
                .get(index)
                .map(|bound| bound.to_string())
                .unwrap_or_else(|| "+Inf".to_string());
            body.push_str(&format!(
                "fium_request_duration_seconds_bucket{{le=\"{bound}\"}} {cumulative}\n"
            ));
        }

        let count = self.count.load(Ordering::Relaxed);
        let sum_seconds = self.sum_micros.load(Ordering::Relaxed) as f64 / 1_000_000.0;
        body.push_str(&format!(
            "fium_request_duration_seconds_sum {sum_seconds}\n"
        ));
        body.push_str(&format!("fium_request_duration_seconds_count {count}\n"));

        body
    }
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    let cli = Cli::parse();

    match cli {
        Cli::Serve {
            app,
            host,
            port,
            workers,
            tls_cert,
            tls_key,
            tls_self_signed,
        } => {
            let (app_path, app_dir) = resolve_app_path(&app)?;

            let cfg = RuntimeConfig::load(
                &app_dir,
                host.as_deref(),
                port,
                workers,
                tls_cert.as_deref(),
                tls_key.as_deref(),
                tls_self_signed,
            );

            init_logging(&cfg);
            serve(app_path, cfg).await
        }
        Cli::Dev {
            app,
            host,
            port,
            workers,
        } => {
            let (app_path, app_dir) = resolve_app_path(&app)?;

            let cfg =
                RuntimeConfig::load(&app_dir, host.as_deref(), port, workers, None, None, false);

            init_logging(&cfg);
            dev_serve(app_path, app_dir, cfg).await
        }
        Cli::Init { directory } => init_project(&directory),
        Cli::Routes { app } => routes_command(app).await,
        Cli::Explain { method, path, app } => explain_command(method, path, app).await,
    }
}

/// Boot one PHP worker just long enough to read the route manifest. Used by the
/// `routes` and `explain` introspection commands. Same env requirements as `serve`.
async fn boot_route_table(app: &PathBuf) -> anyhow::Result<RouteTable> {
    let (app_path, app_dir) = resolve_app_path(app)?;
    let mut cfg = RuntimeConfig::load(&app_dir, None, None, None, None, None, false);
    // Quiet: only surface problems (e.g. a boot error), not the normal info logs.
    cfg.log_level = "warn".to_string();
    init_logging(&cfg);

    let lib_dir = embed::extract_php_lib(&app_path)?;
    let worker_entrypoint = lib_dir.join("worker.php");
    let pool = WorkerPool::new(
        cfg.php_binary.clone(),
        worker_entrypoint.to_string_lossy().to_string(),
        app_path.to_string_lossy().to_string(),
        1, // one worker is enough to read the route manifest
        cfg.max_requests,
        cfg.worker_timeout_ms,
        cfg.tuning.worker_boot_timeout_ms,
    );

    pool.boot().await.map_err(anyhow::Error::from)
}

async fn routes_command(app: PathBuf) -> anyhow::Result<()> {
    let table = boot_route_table(&app).await?;
    let routes = table.list_detailed();
    if routes.is_empty() {
        println!("(no routes declared)");
        return Ok(());
    }

    let mw = routes
        .iter()
        .map(|(m, _, _, _)| m.len())
        .max()
        .unwrap_or(0)
        .max(6);
    let pw = routes
        .iter()
        .map(|(_, p, _, _)| p.len())
        .max()
        .unwrap_or(0)
        .max(4);
    let nw = routes
        .iter()
        .map(|(_, _, n, _)| n.len())
        .max()
        .unwrap_or(0)
        .max(4);

    println!(
        "{:mw$}  {:pw$}  {:nw$}  middleware",
        "method",
        "path",
        "name",
        mw = mw,
        pw = pw,
        nw = nw,
    );
    for (method, path, name, middleware) in &routes {
        let chain = if middleware.is_empty() {
            "(none)".to_string()
        } else {
            middleware.join(" -> ")
        };
        println!(
            "{:mw$}  {:pw$}  {:nw$}  {}",
            method,
            path,
            name,
            chain,
            mw = mw,
            pw = pw,
            nw = nw,
        );
    }
    Ok(())
}

async fn explain_command(method: String, path: String, app: PathBuf) -> anyhow::Result<()> {
    let table = boot_route_table(&app).await?;
    let method = method.to_ascii_uppercase();
    let matched = table
        .match_route(&method, &path)
        .ok_or_else(|| anyhow::anyhow!("no route matches {method} {path}"))?;

    let params: Vec<String> = matched
        .params
        .iter()
        .map(|(key, value)| format!("{key}={value}"))
        .collect();
    let middleware = table.middleware_for(&matched.route_name).unwrap_or(&[]);

    println!("{method} {path}");
    println!("  route:      {}", matched.route_name);
    println!(
        "  params:     {}",
        if params.is_empty() {
            "(none)".to_string()
        } else {
            params.join(", ")
        }
    );
    println!(
        "  middleware: {}",
        if middleware.is_empty() {
            "(none)".to_string()
        } else {
            middleware.join(" -> ")
        }
    );
    Ok(())
}

fn resolve_app_path(app: &PathBuf) -> anyhow::Result<(PathBuf, PathBuf)> {
    let app_path = if app.is_absolute() {
        app.clone()
    } else {
        std::env::current_dir()?.join(app)
    };

    if !app_path.is_file() {
        anyhow::bail!("application file not found: {}", app_path.display());
    }

    let app_dir = app_path
        .parent()
        .unwrap_or_else(|| std::path::Path::new("."))
        .to_path_buf();
    Ok((app_path, app_dir))
}

fn init_logging(cfg: &RuntimeConfig) {
    use tracing_subscriber::{fmt, EnvFilter};

    let filter =
        EnvFilter::try_from_default_env().unwrap_or_else(|_| EnvFilter::new(&cfg.log_level));

    match cfg.log_format {
        LogFormat::Json => {
            fmt().json().with_env_filter(filter).init();
        }
        LogFormat::Pretty => {
            fmt().with_env_filter(filter).init();
        }
    }
}

async fn serve(app_path: PathBuf, cfg: RuntimeConfig) -> anyhow::Result<()> {
    // Extract embedded PHP library
    let lib_dir = embed::extract_php_lib(&app_path)?;
    info!(lib_dir = %lib_dir.display(), "extracted PHP library");

    // Build the worker entrypoint path
    let worker_entrypoint = lib_dir.join("worker.php");

    // Boot the worker pool
    info!(workers = cfg.workers, "booting PHP worker pool...");
    let pool = WorkerPool::new(
        cfg.php_binary.clone(),
        worker_entrypoint.to_string_lossy().to_string(),
        app_path.to_string_lossy().to_string(),
        cfg.workers,
        cfg.max_requests,
        cfg.worker_timeout_ms,
        cfg.tuning.worker_boot_timeout_ms,
    );

    let routes = pool.boot().await.map_err(anyhow::Error::from)?;

    print_boot_banner(&cfg, &routes);

    let config = Arc::new(cfg);

    let state = AppState {
        routes: Arc::new(ArcSwap::from_pointee(routes)),
        pool,
        config: config.clone(),
        start_time: Instant::now(),
        request_latency: Arc::new(RequestDurationHistogram::new()),
        rate_limiter: Arc::new(RateLimiter::new()),
    };

    let app = build_router(state, &config);

    let address: SocketAddr = format!("{}:{}", config.host, config.port).parse()?;

    // TLS support
    if config.tls_enabled() {
        let (cert_path, key_path) = if config.tls_self_signed {
            let tls_dir = app_path
                .parent()
                .unwrap_or_else(|| std::path::Path::new("."))
                .join(".fium/tls");
            std::fs::create_dir_all(&tls_dir)?;
            let cert_path = tls_dir.join("cert.pem");
            let key_path = tls_dir.join("key.pem");

            if !cert_path.exists() || !key_path.exists() {
                info!("generating self-signed TLS certificate for development");
                let cert = rcgen::generate_simple_self_signed(vec![
                    "localhost".to_string(),
                    config.host.clone(),
                ])?;
                std::fs::write(&cert_path, cert.cert.pem())?;
                std::fs::write(&key_path, cert.key_pair.serialize_pem())?;
            }

            (cert_path, key_path)
        } else {
            (
                config.tls_cert.clone().expect("TLS cert path required"),
                config.tls_key.clone().expect("TLS key path required"),
            )
        };

        let scheme = "https";
        info!(%address, %scheme, "starting runtime with TLS");

        let rustls_config =
            axum_server::tls_rustls::RustlsConfig::from_pem_file(&cert_path, &key_path).await?;
        let handle = axum_server::Handle::new();
        let handle_for_signal = handle.clone();
        let shutdown_config = config.clone();
        tokio::spawn(async move {
            shutdown_signal().await;
            handle_for_signal.graceful_shutdown(Some(Duration::from_secs(
                shutdown_config.tuning.shutdown_timeout_secs,
            )));
        });

        let tcp = bind_tcp_listener(
            address,
            config.tuning.max_connections,
            config.tuning.reuse_addr,
        )?;
        let mut server = axum_server::from_tcp_rustls(tcp, rustls_config).handle(handle);
        {
            let http = server.http_builder();
            http.http1()
                .keep_alive(config.tuning.keep_alive_timeout_secs > 0);
            if config.tuning.keep_alive_timeout_secs > 0 {
                let keep_alive = Duration::from_secs(config.tuning.keep_alive_timeout_secs);
                http.http2().keep_alive_interval(Some(keep_alive));
                http.http2().keep_alive_timeout(keep_alive);
            } else {
                http.http2().keep_alive_interval(None);
            }
        }
        server.serve(app).await?;
    } else {
        let scheme = "http";
        info!(%address, %scheme, "starting runtime");

        let handle = axum_server::Handle::new();
        let handle_for_signal = handle.clone();
        let shutdown_config = config.clone();
        tokio::spawn(async move {
            shutdown_signal().await;
            handle_for_signal.graceful_shutdown(Some(Duration::from_secs(
                shutdown_config.tuning.shutdown_timeout_secs,
            )));
        });

        let tcp = bind_tcp_listener(
            address,
            config.tuning.max_connections,
            config.tuning.reuse_addr,
        )?;
        let mut server = axum_server::from_tcp(tcp).handle(handle);
        {
            let http = server.http_builder();
            http.http1()
                .keep_alive(config.tuning.keep_alive_timeout_secs > 0);
            if config.tuning.keep_alive_timeout_secs > 0 {
                let keep_alive = Duration::from_secs(config.tuning.keep_alive_timeout_secs);
                http.http2().keep_alive_interval(Some(keep_alive));
                http.http2().keep_alive_timeout(keep_alive);
            } else {
                http.http2().keep_alive_interval(None);
            }
        }
        server.serve(app).await?;
    }

    Ok(())
}

async fn health(State(state): State<AppState>, request: Request<Body>) -> Response {
    let accept = request
        .headers()
        .get(axum::http::header::ACCEPT)
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");

    if accept.contains("application/json") {
        let snapshot = health_snapshot(&state).await;

        let json = serde_json::json!({
            "status": if snapshot.ready { "ok" } else { "degraded" },
            "ready": snapshot.ready,
            "uptime_seconds": snapshot.uptime_seconds,
            "workers": snapshot.workers,
            "live_workers": snapshot.live_workers,
            "route_count": snapshot.route_count,
            "requests_total": snapshot.requests_total,
            "restarts_total": snapshot.restarts_total,
            "errors_total": snapshot.errors_total,
            "pending_requests": snapshot.pending_requests,
            "memory_rss_bytes": snapshot.memory_rss_bytes,
        });

        (
            if snapshot.ready {
                StatusCode::OK
            } else {
                StatusCode::SERVICE_UNAVAILABLE
            },
            [(axum::http::header::CONTENT_TYPE, "application/json")],
            json.to_string(),
        )
            .into_response()
    } else {
        (StatusCode::OK, "ok").into_response()
    }
}

async fn metrics(State(state): State<AppState>) -> Response {
    let workers = state.pool.workers();
    let total_requests: u64 = workers.iter().map(|w| w.requests_handled()).sum();
    let total_restarts: u64 = workers.iter().map(|w| w.restarts()).sum();
    let total_errors: u64 = workers.iter().map(|w| w.errors()).sum();
    let total_pending: usize = workers.iter().map(|w| w.pending_count()).sum();
    let uptime = state.start_time.elapsed().as_secs();
    let memory_rss_bytes = memory_rss_bytes();

    let mut body = format!(
        "# HELP fium_requests_total Total number of requests handled.\n\
         # TYPE fium_requests_total counter\n\
         fium_requests_total {total_requests}\n\
         # HELP fium_worker_restarts_total Total number of worker restarts.\n\
         # TYPE fium_worker_restarts_total counter\n\
         fium_worker_restarts_total {total_restarts}\n\
         # HELP fium_worker_errors_total Total number of worker errors.\n\
         # TYPE fium_worker_errors_total counter\n\
         fium_worker_errors_total {total_errors}\n\
         # HELP fium_workers_total Total number of worker processes.\n\
         # TYPE fium_workers_total gauge\n\
         fium_workers_total {workers}\n\
         # HELP fium_worker_pending_requests Number of requests queued across all workers.\n\
         # TYPE fium_worker_pending_requests gauge\n\
         fium_worker_pending_requests {total_pending}\n\
         # HELP fium_uptime_seconds Server uptime in seconds.\n\
         # TYPE fium_uptime_seconds gauge\n\
         fium_uptime_seconds {uptime}\n",
        workers = workers.len(),
    );
    body.push_str(&state.request_latency.render_prometheus());
    body.push_str(&format!(
        "# HELP fium_memory_rss_bytes Resident set size in bytes.\n\
         # TYPE fium_memory_rss_bytes gauge\n\
         fium_memory_rss_bytes {memory_rss_bytes}\n"
    ));

    (
        StatusCode::OK,
        [(
            axum::http::header::CONTENT_TYPE,
            "text/plain; version=0.0.4; charset=utf-8",
        )],
        body,
    )
        .into_response()
}

async fn healthz() -> Response {
    (StatusCode::OK, "ok").into_response()
}

async fn readyz(State(state): State<AppState>) -> Response {
    let snapshot = health_snapshot(&state).await;
    if snapshot.ready {
        (StatusCode::OK, "ready").into_response()
    } else {
        (StatusCode::SERVICE_UNAVAILABLE, "not ready").into_response()
    }
}

struct HealthSnapshot {
    workers: usize,
    live_workers: usize,
    route_count: usize,
    requests_total: u64,
    restarts_total: u64,
    errors_total: u64,
    pending_requests: usize,
    uptime_seconds: u64,
    memory_rss_bytes: u64,
    ready: bool,
}

async fn health_snapshot(state: &AppState) -> HealthSnapshot {
    let workers = state.pool.workers().to_vec();
    let worker_count = workers.len();
    let requests_total: u64 = workers.iter().map(|w| w.requests_handled()).sum();
    let restarts_total: u64 = workers.iter().map(|w| w.restarts()).sum();
    let errors_total: u64 = workers.iter().map(|w| w.errors()).sum();
    let pending_requests: usize = workers.iter().map(|w| w.pending_count()).sum();
    let route_count = {
        let routes = state.routes.load();
        routes.list().len()
    };

    let mut live_workers = 0usize;
    for worker in &workers {
        if worker.is_alive().await {
            live_workers += 1;
        }
    }

    HealthSnapshot {
        workers: worker_count,
        live_workers,
        route_count,
        requests_total,
        restarts_total,
        errors_total,
        pending_requests,
        uptime_seconds: state.start_time.elapsed().as_secs(),
        memory_rss_bytes: memory_rss_bytes(),
        ready: route_count > 0 && live_workers > 0,
    }
}

fn print_boot_banner(cfg: &RuntimeConfig, routes: &RouteTable) {
    let scheme = if cfg.tls_enabled() { "https" } else { "http" };
    let addr = format!("{}://{}:{}", scheme, cfg.host, cfg.port);

    eprintln!();
    eprintln!("  \x1b[1;36mfium\x1b[0m  ready");
    eprintln!();
    eprintln!("  \x1b[2m→\x1b[0m  URL:     \x1b[1;4m{}\x1b[0m", addr);
    eprintln!("  \x1b[2m→\x1b[0m  Workers: \x1b[1m{}\x1b[0m", cfg.workers);
    eprintln!(
        "  \x1b[2m→\x1b[0m  TLS:     {}",
        if cfg.tls_enabled() {
            "\x1b[32menabled\x1b[0m"
        } else {
            "\x1b[2moff\x1b[0m"
        }
    );
    if cfg.static_enabled {
        if let Some(ref dir) = cfg.static_dir {
            eprintln!("  \x1b[2m→\x1b[0m  Static:  {}", dir.display());
        }
    }
    eprintln!();

    for (method, path, name) in routes.list() {
        eprintln!(
            "  \x1b[33m{:<7}\x1b[0m {} \x1b[2m{}\x1b[0m",
            method, path, name
        );
    }

    eprintln!();
}

fn build_router(
    state: AppState,
    config: &RuntimeConfig,
) -> axum::extract::connect_info::IntoMakeServiceWithConnectInfo<Router, SocketAddr> {
    let mut app = Router::new()
        .route("/health", get(health))
        .route("/healthz", get(healthz))
        .route("/readyz", get(readyz))
        .route("/_fium/metrics", get(metrics))
        .fallback(dispatch)
        .with_state(state);

    // Cache-Control for static assets — 1 hour by default, immutable for hashed assets works too.
    let cache_header = HeaderValue::from_str(&format!(
        "public, max-age={}",
        config.compression.static_cache_max_age_secs
    ))
    .expect("static cache header should always be valid");

    if config.static_enabled {
        if let Some(ref static_dir) = config.static_dir {
            if static_dir.is_dir() {
                info!(dir = %static_dir.display(), "serving static files");
                let serve_dir = build_static_serve_dir(static_dir, &config.compression);
                app = Router::new()
                    .nest_service("/", serve_dir.fallback(app.into_service()))
                    .layer(SetResponseHeaderLayer::overriding(
                        axum::http::header::CACHE_CONTROL,
                        cache_header,
                    ));
            }
        }
    }

    app.layer(
        CompressionLayer::new()
            .quality(CompressionLevel::Precise(config.compression.level))
            .gzip(config.compression.gzip_enabled())
            .br(config.compression.br_enabled())
            .compress_when(SizeAbove::new(256)),
    )
    .into_make_service_with_connect_info::<SocketAddr>()
}

#[tracing::instrument(skip_all, fields(method, path, request_id))]
async fn dispatch(
    State(state): State<AppState>,
    ConnectInfo(addr): ConnectInfo<SocketAddr>,
    request: Request<Body>,
) -> Response {
    let path = request.uri().path().to_string();
    let method = request.method().to_string();
    let span = tracing::Span::current();
    span.record("method", tracing::field::display(&method));
    span.record("path", tracing::field::display(&path));

    // World-B fast path: answer CORS preflights entirely from Rust. The boot manifest
    // already tells us which static paths carry the `cors` middleware, so an OPTIONS
    // request to such a path needs no PHP round-trip. This is gated behind
    // FIUM_NATIVE_CORS so the same binary can run both the Before (PHP) and After
    // (Rust) paths for measurement. With this off, an OPTIONS to a POST route still
    // 404s at the route layer — i.e. CORS preflight is effectively broken today.
    if state.config.native_cors_preflight && method == "OPTIONS" {
        let routes = state.routes.load();
        if routes.cors_preflight_target(&path) {
            // CORS config comes from the boot manifest (resolved by PHP from .env), so the
            // native path serves the same origin/method policy the PHP Cors middleware would.
            if let Some(cors) = routes.cors() {
                return native_cors_preflight_response(cors);
            }
        }
    }

    let route_match = {
        let routes = state.routes.load();
        routes.match_route(&method, &path)
    };

    let Some(route_match) = route_match else {
        return (StatusCode::NOT_FOUND, "route not found").into_response();
    };
    let route_name = &route_match.route_name;

    let request_id = format!("req_{}", Uuid::new_v4().simple());
    let start = Instant::now();
    span.record("request_id", tracing::field::display(&request_id));
    let query_string = request.uri().query().unwrap_or_default().to_string();
    let host = request
        .headers()
        .get(axum::http::header::HOST)
        .and_then(|value| value.to_str().ok())
        .unwrap_or("localhost")
        .to_string();

    // Only trust X-Forwarded-* headers from trusted proxies
    let is_trusted_proxy = state.config.trusted_proxies.contains(&addr);
    let scheme = if state.config.tls_enabled() {
        "https".to_string()
    } else if is_trusted_proxy {
        request
            .headers()
            .get("x-forwarded-proto")
            .and_then(|v| v.to_str().ok())
            .unwrap_or("http")
            .to_string()
    } else {
        request.uri().scheme_str().unwrap_or("http").to_string()
    };

    let client_ip = if is_trusted_proxy {
        request
            .headers()
            .get("x-forwarded-for")
            .and_then(|v| v.to_str().ok())
            .and_then(|v| v.split(',').next())
            .map(|s| s.trim().to_string())
            .unwrap_or_else(|| addr.ip().to_string())
    } else {
        addr.ip().to_string()
    };

    // World-B fast path: enforce rate limits in Rust with pool-wide counters.
    // Coherent across all workers (fixes the per-worker ratelimit x N behavior), and
    // rejections are served as 429 without a PHP round-trip — so a flood to a limited
    // route can no longer load the PHP pool. Gated by FIUM_NATIVE_RATELIMIT. When
    // allowed, we carry the limit/remaining onto the response headers below.
    let mut rate_limit_headers: Option<(u32, u32)> = None;
    if state.config.native_rate_limit {
        if let Some(cfg) = state.routes.load().rate_limit_for(route_name) {
            match state.rate_limiter.check(route_name, &client_ip, cfg) {
                Allow::Denied { retry_after_secs } => {
                    let elapsed = start.elapsed();
                    state.request_latency.record(elapsed);
                    return rate_limit_denied_response(retry_after_secs);
                }
                Allow::Allowed { limit, remaining } => {
                    rate_limit_headers = Some((limit, remaining));
                }
            }
        }
    }

    let is_secure = scheme == "https";
    let headers = normalize_headers(request.headers());
    let cookies = parse_cookies(request.headers());
    let body_max = state.config.body_max_size;

    // Skip body capture for methods that never carry a payload.
    let (body, body_file) = if matches!(method.as_str(), "GET" | "HEAD") {
        (None, None)
    } else {
        match buffer_request_body(
            request.into_body(),
            body_max,
            MAX_IN_MEMORY_REQUEST_BODY_BYTES,
            &request_id,
        )
        .await
        {
            Ok(result) => result,
            Err(response) => return response,
        }
    };

    let worker_request = WorkerRequest {
        protocol_version: PROTOCOL_VERSION,
        request_id: request_id.clone(),
        method,
        path,
        query_string,
        headers,
        cookies,
        route_params: route_match.params,
        body,
        body_file: body_file.clone(),
        scheme,
        host,
        client_ip: Some(client_ip),
        is_secure,
        matched_route: Some(route_name.clone()),
    };

    let log_method = worker_request.method.clone();
    let log_path = worker_request.path.clone();

    let result = state.pool.handle(worker_request).await;

    if let Some(body_file) = body_file {
        if let Err(error) = tokio::fs::remove_file(&body_file).await {
            warn!(%error, request_id, body_file, "failed to remove streamed request body file");
        }
    }

    match result {
        Ok(worker_response) => {
            let elapsed = start.elapsed();
            state.request_latency.record(elapsed);
            info!(
                method = %log_method,
                path = %log_path,
                status = worker_response.status,
                latency_ms = elapsed.as_secs_f64() * 1000.0,
                %request_id,
                "request completed"
            );

            let body = worker_response.body.unwrap_or_default();
            let mut response = Response::new(Body::from(body));

            *response.status_mut() = StatusCode::from_u16(worker_response.status)
                .unwrap_or(StatusCode::INTERNAL_SERVER_ERROR);

            for (name, values) in &worker_response.headers {
                for value in values {
                    if let (Ok(header_name), Ok(header_value)) = (
                        axum::http::header::HeaderName::try_from(name.as_str()),
                        HeaderValue::from_str(value),
                    ) {
                        response.headers_mut().append(header_name, header_value);
                    }
                }
            }

            for cookie in &worker_response.cookies {
                if let Ok(value) = HeaderValue::from_str(&format_set_cookie(cookie)) {
                    response
                        .headers_mut()
                        .append(axum::http::header::SET_COOKIE, value);
                }
            }

            // Native rate limit (allowed path): stamp the limit/remaining headers that
            // the PHP middleware would have added.
            if let Some((limit, remaining)) = rate_limit_headers {
                if let Ok(value) = HeaderValue::from_str(&limit.to_string()) {
                    response.headers_mut().insert(
                        axum::http::header::HeaderName::from_static("x-ratelimit-limit"),
                        value,
                    );
                }
                if let Ok(value) = HeaderValue::from_str(&remaining.to_string()) {
                    response.headers_mut().insert(
                        axum::http::header::HeaderName::from_static("x-ratelimit-remaining"),
                        value,
                    );
                }
            }

            // Native security headers: if the route declares security-headers/secure and
            // native enforcement is on, stamp the headers here so the PHP middleware is a
            // no-op (it defers when FIUM_NATIVE_SECURITY_HEADERS is set).
            if state.config.native_security_headers
                && state
                    .routes
                    .load()
                    .middleware_for(route_name)
                    .is_some_and(|middleware| {
                        middleware
                            .iter()
                            .any(|m| m == "security-headers" || m == "secure")
                    })
            {
                inject_security_headers(&mut response);
            }

            response
        }
        Err(error) => {
            let elapsed = start.elapsed();
            state.request_latency.record(elapsed);
            error!(
                %error,
                method = %log_method,
                path = %log_path,
                route = %route_name,
                latency_ms = elapsed.as_secs_f64() * 1000.0,
                %request_id,
                "worker dispatch failed"
            );
            let status = if matches!(error, RuntimeWorkerError::Timeout { .. }) {
                StatusCode::GATEWAY_TIMEOUT
            } else {
                StatusCode::BAD_GATEWAY
            };

            (status, "worker dispatch failed").into_response()
        }
    }
}

async fn buffer_request_body(
    mut body: Body,
    body_max: usize,
    in_memory_max: usize,
    request_id: &str,
) -> Result<(Option<String>, Option<String>), Response> {
    let mut total = 0usize;
    let mut buffered = Vec::new();
    let mut temp_path: Option<PathBuf> = None;
    let mut temp_file: Option<File> = None;
    let spill_threshold = body_max.min(in_memory_max);

    while let Some(frame_result) = body.frame().await {
        let frame = match frame_result {
            Ok(frame) => frame,
            Err(error) => {
                error!(%error, %request_id, "failed to stream request body");
                if let Some(path) = temp_path {
                    let _ = tokio::fs::remove_file(path).await;
                }
                return Err((StatusCode::BAD_REQUEST, "invalid request body").into_response());
            }
        };

        let Some(chunk) = frame.data_ref().cloned() else {
            continue;
        };

        total += chunk.len();
        if total > body_max {
            if let Some(path) = temp_path {
                let _ = tokio::fs::remove_file(path).await;
            }
            return Err((StatusCode::PAYLOAD_TOO_LARGE, "request body too large").into_response());
        }

        if let Some(file) = &mut temp_file {
            if let Err(error) = file.write_all(chunk.as_ref()).await {
                error!(%error, %request_id, "failed to write streamed request body");
                if let Some(path) = temp_path {
                    let _ = tokio::fs::remove_file(path).await;
                }
                return Err((
                    StatusCode::INTERNAL_SERVER_ERROR,
                    "failed to buffer request body",
                )
                    .into_response());
            }
            continue;
        }

        if buffered.len() + chunk.len() <= spill_threshold {
            buffered.extend_from_slice(chunk.as_ref());
            continue;
        }

        if temp_file.is_none() {
            let path = std::env::temp_dir().join(format!("fium-body-{}.tmp", Uuid::new_v4()));
            let file = match File::create(&path).await {
                Ok(file) => file,
                Err(error) => {
                    error!(%error, %request_id, path = %path.display(), "failed to create request body temp file");
                    return Err((
                        StatusCode::INTERNAL_SERVER_ERROR,
                        "failed to buffer request body",
                    )
                        .into_response());
                }
            };
            temp_path = Some(path);
            temp_file = Some(file);
        }

        if let Some(file) = &mut temp_file {
            if !buffered.is_empty() {
                if let Err(error) = file.write_all(&buffered).await {
                    error!(%error, %request_id, "failed to write streamed request body");
                    if let Some(path) = temp_path {
                        let _ = tokio::fs::remove_file(path).await;
                    }
                    return Err((
                        StatusCode::INTERNAL_SERVER_ERROR,
                        "failed to buffer request body",
                    )
                        .into_response());
                }
                buffered.clear();
            }

            if let Err(error) = file.write_all(chunk.as_ref()).await {
                error!(%error, %request_id, "failed to write streamed request body");
                if let Some(path) = temp_path {
                    let _ = tokio::fs::remove_file(path).await;
                }
                return Err((
                    StatusCode::INTERNAL_SERVER_ERROR,
                    "failed to buffer request body",
                )
                    .into_response());
            }
        }
    }

    if let Some(mut file) = temp_file {
        if let Err(error) = file.flush().await {
            error!(%error, %request_id, "failed to flush streamed request body");
            if let Some(path) = temp_path {
                let _ = tokio::fs::remove_file(path).await;
            }
            return Err((
                StatusCode::INTERNAL_SERVER_ERROR,
                "failed to buffer request body",
            )
                .into_response());
        }

        return Ok((
            None,
            temp_path.map(|path| path.to_string_lossy().to_string()),
        ));
    }

    if buffered.is_empty() {
        return Ok((None, None));
    }

    match String::from_utf8(buffered) {
        Ok(body) => Ok((Some(body), None)),
        Err(error) => {
            let bytes = error.into_bytes();
            let path = std::env::temp_dir().join(format!("fium-body-{}.tmp", Uuid::new_v4()));
            let mut file = match File::create(&path).await {
                Ok(file) => file,
                Err(error) => {
                    error!(%error, %request_id, path = %path.display(), "failed to create request body temp file");
                    return Err((
                        StatusCode::INTERNAL_SERVER_ERROR,
                        "failed to buffer request body",
                    )
                        .into_response());
                }
            };

            if let Err(error) = file.write_all(&bytes).await {
                error!(%error, %request_id, "failed to write streamed request body");
                let _ = tokio::fs::remove_file(&path).await;
                return Err((
                    StatusCode::INTERNAL_SERVER_ERROR,
                    "failed to buffer request body",
                )
                    .into_response());
            }

            if let Err(error) = file.flush().await {
                error!(%error, %request_id, "failed to flush streamed request body");
                let _ = tokio::fs::remove_file(&path).await;
                return Err((
                    StatusCode::INTERNAL_SERVER_ERROR,
                    "failed to buffer request body",
                )
                    .into_response());
            }

            Ok((None, Some(path.to_string_lossy().to_string())))
        }
    }
}

fn init_project(directory: &PathBuf) -> anyhow::Result<()> {
    let dir = if directory.as_os_str() == "." {
        std::env::current_dir()?
    } else {
        let d = std::env::current_dir()?.join(directory);
        std::fs::create_dir_all(&d)?;
        d
    };

    let app_php = dir.join("app.php");
    if app_php.exists() {
        anyhow::bail!("app.php already exists in {}", dir.display());
    }

    std::fs::write(&app_php, APP_PHP_TEMPLATE)?;
    std::fs::write(dir.join("README.md"), SCAFFOLD_README)?;
    std::fs::create_dir_all(dir.join("public"))?;
    std::fs::write(
        dir.join(".gitignore"),
        "/storage/\n/.fium/\n/.env\n/vendor/\n",
    )?;
    // FIUM_DEBUG=true gives verbose errors during local development. It's not required
    // to boot (the token service resolves its secret lazily), but it's the sensible dev
    // default; production sets a real FIUM_API_TOKEN_SECRET instead.
    std::fs::write(dir.join(".env"), "FIUM_DEBUG=true\n")?;
    std::fs::write(
        dir.join(".env.example"),
        "# Copy to .env and adjust. FIUM_DEBUG=true is fine for local dev; for production\n\
         # set a real secret instead.\n\
         FIUM_DEBUG=true\n\
         # FIUM_API_TOKEN_SECRET=change-me-to-a-long-random-string\n\
         # FIUM_SESSION_DRIVER=file\n\
         # FIUM_DB_DSN=sqlite:storage/app.db\n",
    )?;
    std::fs::create_dir_all(dir.join("storage/sessions"))?;
    std::fs::create_dir_all(dir.join("storage/runtime"))?;

    eprintln!();
    eprintln!(
        "  \x1b[1;36mfium\x1b[0m  project created at {}",
        dir.display()
    );
    eprintln!();
    eprintln!("  \x1b[1mGet started:\x1b[0m");
    eprintln!("    cd {}", directory.display());
    eprintln!("    \x1b[2mfium serve\x1b[0m            # http://127.0.0.1:3000");
    eprintln!("    \x1b[2mfium dev\x1b[0m              # serve + reload on PHP changes");
    eprintln!("    \x1b[2mfium routes\x1b[0m            # list routes + middleware");
    eprintln!();

    Ok(())
}

const APP_PHP_TEMPLATE: &str = r#"<?php

declare(strict_types=1);

// A Fium app returns an array of routes from app.php. Each entry is either a concise
// "'METHOD /path' => handler" pair, or a named group with a shared prefix + middleware.
// A handler is a closure (Request $r): Response (or a class-string). Run `fium serve`.

use Fium\Runtime\Request;
use Fium\Runtime\Response;

return [
    // Concise route + closure handler. Path params in {braces} are bound on the Request.
    'GET /' => fn(Request $r) => Response::json([
        'name' => 'my-fium-app',
        'status' => 'ok',
    ]),

    'GET /hello/{name}' => fn(Request $r) => Response::json([
        'message' => 'Hello, ' . $r->routeParam('name') . '!',
    ]),

    // Response::html / Response::redirect / Response::empty are available too.
    'GET /welcome' => fn(Request $r) => Response::html('<h1>It works.</h1>'),

    // A group shares a prefix and middleware across its routes.
    // Built-in middleware: cors, ratelimit:60, start-session, verify-csrf, auth-bearer,
    // auth-session, role:admin, require-json, secure, powered-by. See `fium routes`.
    'api' => [
        'prefix' => '/api',
        'middleware' => ['require-json'],
        'routes' => [
            'GET /time' => fn(Request $r) => Response::json(['now' => gmdate('c')]),
        ],
    ],
];
"#;

const SCAFFOLD_README: &str = r#"# my-fium-app

A [Fium](https://github.com/jaltez/fium) app — a fast PHP runtime powered by Rust.

## Run

```bash
fium serve            # http://127.0.0.1:3000
fium dev              # serve + reload on PHP changes
```

## Explore

```bash
fium routes           # list every route with its middleware chain
fium explain GET /hello/World
```

## Layout

- `app.php` — your routes and handlers (the whole app starts here).
- `public/` — static files served as-is.
- `.env` — config. `FIUM_DEBUG=true` here; set `FIUM_API_TOKEN_SECRET` for production.
- `storage/` — sessions, cache, runtime data (gitignored).

Routes return JSON/HTML/redirect/empty responses via `Fium\Runtime\Response`. Middleware
and groups are declared per-route in `app.php`. See the project docs for auth, sessions,
database, cache, and validation.
"#;

async fn dev_serve(app_path: PathBuf, app_dir: PathBuf, cfg: RuntimeConfig) -> anyhow::Result<()> {
    use notify::{RecursiveMode, Watcher};

    // Extract embedded PHP library
    let lib_dir = embed::extract_php_lib(&app_path)?;
    let worker_entrypoint = lib_dir.join("worker.php");

    info!(
        workers = cfg.workers,
        "booting PHP worker pool (dev mode)..."
    );
    let pool = WorkerPool::new(
        cfg.php_binary.clone(),
        worker_entrypoint.to_string_lossy().to_string(),
        app_path.to_string_lossy().to_string(),
        cfg.workers,
        cfg.max_requests,
        cfg.worker_timeout_ms,
        cfg.tuning.worker_boot_timeout_ms,
    );

    let routes = pool.boot().await.map_err(anyhow::Error::from)?;

    print_boot_banner(&cfg, &routes);
    eprintln!("  \x1b[2m\u{2192}\x1b[0m  Mode:    \x1b[33mdev\x1b[0m (watching for changes)");
    eprintln!();

    let config = Arc::new(cfg);
    let routes = Arc::new(ArcSwap::from_pointee(routes));

    let state = AppState {
        routes: routes.clone(),
        pool: pool.clone(),
        config: config.clone(),
        start_time: Instant::now(),
        request_latency: Arc::new(RequestDurationHistogram::new()),
        rate_limiter: Arc::new(RateLimiter::new()),
    };

    let app = build_router(state, &config);

    // File watcher — restart workers on PHP file changes and refresh the route table.
    let pool_for_watcher = pool.clone();
    let routes_for_watcher = routes.clone();
    let (tx, mut rx) = tokio::sync::mpsc::unbounded_channel::<()>();

    let mut watcher =
        notify::recommended_watcher(move |res: Result<notify::Event, notify::Error>| match res {
            Ok(event) => {
                let has_php_extension = event
                    .paths
                    .iter()
                    .any(|path| path.extension().is_some_and(|ext| ext == "php"));

                if has_php_extension {
                    let _ = tx.send(());
                }
            }
            Err(error) => warn!(%error, "file watcher error"),
        })?;
    watcher.watch(app_dir.as_ref(), RecursiveMode::Recursive)?;

    tokio::spawn(async move {
        while rx.recv().await.is_some() {
            // Debounce: wait a bit then drain extra events
            tokio::time::sleep(Duration::from_millis(100)).await;
            while rx.try_recv().is_ok() {}

            eprintln!("  \x1b[33m[reload]\x1b[0m PHP file changed, restarting workers...");
            match pool_for_watcher.reload().await {
                Ok(new_routes) => {
                    routes_for_watcher.store(Arc::new(new_routes));
                    eprintln!("  \x1b[32m[reload]\x1b[0m workers restarted");
                }
                Err(error) => {
                    warn!(%error, "failed to reload workers after PHP change");
                    eprintln!("  \x1b[31m[reload]\x1b[0m reload failed: {error}");
                }
            }
        }
    });

    let address: SocketAddr = format!("{}:{}", config.host, config.port).parse()?;
    let tcp = bind_tcp_listener(
        address,
        config.tuning.max_connections,
        config.tuning.reuse_addr,
    )?;

    let handle = axum_server::Handle::new();
    let handle_for_signal = handle.clone();
    let shutdown_config = config.clone();
    tokio::spawn(async move {
        shutdown_signal().await;
        handle_for_signal.graceful_shutdown(Some(Duration::from_secs(
            shutdown_config.tuning.shutdown_timeout_secs,
        )));
    });

    let mut server = axum_server::from_tcp(tcp).handle(handle);
    {
        let http = server.http_builder();
        http.http1()
            .keep_alive(config.tuning.keep_alive_timeout_secs > 0);
        if config.tuning.keep_alive_timeout_secs > 0 {
            let keep_alive = Duration::from_secs(config.tuning.keep_alive_timeout_secs);
            http.http2().keep_alive_interval(Some(keep_alive));
            http.http2().keep_alive_timeout(keep_alive);
        } else {
            http.http2().keep_alive_interval(None);
        }
    }
    server.serve(app).await?;

    Ok(())
}

async fn shutdown_signal() {
    let _ = tokio::signal::ctrl_c().await;
    info!("shutdown signal received");
}

fn bind_tcp_listener(
    address: SocketAddr,
    max_connections: u32,
    reuse_addr: bool,
) -> anyhow::Result<std::net::TcpListener> {
    let domain = if address.is_ipv4() {
        Domain::IPV4
    } else {
        Domain::IPV6
    };
    let socket = Socket::new(domain, Type::STREAM, Some(Protocol::TCP))?;
    socket.set_reuse_address(reuse_addr)?;
    socket.bind(&address.into())?;
    socket.listen(max_connections.min(i32::MAX as u32) as i32)?;
    socket.set_nonblocking(true)?;

    Ok(socket.into())
}

fn build_static_serve_dir(
    static_dir: &PathBuf,
    compression: &CompressionConfig,
) -> tower_http::services::ServeDir {
    let mut serve_dir = tower_http::services::ServeDir::new(static_dir);
    if compression.gzip_enabled() {
        serve_dir = serve_dir.precompressed_gzip();
    }
    if compression.br_enabled() {
        serve_dir = serve_dir.precompressed_br();
    }
    serve_dir
}

fn normalize_headers(headers: &axum::http::HeaderMap) -> HeaderMap {
    let mut normalized = HeaderMap::new();

    for (name, value) in headers {
        let key = name.as_str().to_ascii_lowercase();
        let entry = normalized.entry(key).or_default();
        if let Ok(value) = value.to_str() {
            entry.push(value.to_string());
        }
    }

    normalized
}

fn parse_cookies(headers: &axum::http::HeaderMap) -> CookieMap {
    let mut cookies = CookieMap::new();

    for value in headers.get_all(axum::http::header::COOKIE) {
        if let Ok(value) = value.to_str() {
            for part in value.split(';') {
                let mut pieces = part.trim().splitn(2, '=');
                let Some(name) = pieces.next() else {
                    continue;
                };
                let Some(cookie_value) = pieces.next() else {
                    continue;
                };

                cookies.insert(name.trim().to_string(), cookie_value.trim().to_string());
            }
        }
    }

    cookies
}

/// Inject the standard security headers the PHP `SecurityHeaders` middleware adds, so a
/// response doesn't need to cross into PHP to acquire them. Mirrors that middleware's set.
fn inject_security_headers(response: &mut Response) {
    let headers = response.headers_mut();
    for (name, value) in [
        ("x-content-type-options", "nosniff"),
        ("x-frame-options", "DENY"),
        ("referrer-policy", "strict-origin-when-cross-origin"),
        ("x-xss-protection", "0"),
        (
            "permissions-policy",
            "camera=(), microphone=(), geolocation=()",
        ),
    ] {
        if let (Ok(name), Ok(value)) = (
            axum::http::header::HeaderName::from_bytes(name.as_bytes()),
            HeaderValue::from_str(value),
        ) {
            headers.insert(name, value);
        }
    }
}

/// Build a CORS preflight response entirely in Rust, using the CORS config PHP resolved
/// (from `.env`/Config) and sent in the boot manifest — so the native path serves the same
/// origin/method policy the PHP `Cors` middleware would, never a divergent `*`.
fn native_cors_preflight_response(cors: &BootCors) -> Response {
    let mut response = Response::new(Body::empty());
    *response.status_mut() = StatusCode::NO_CONTENT;
    let response_headers = response.headers_mut();
    for (name, value) in [
        (
            axum::http::header::ACCESS_CONTROL_ALLOW_ORIGIN,
            cors.origins.as_str(),
        ),
        (
            axum::http::header::ACCESS_CONTROL_ALLOW_METHODS,
            cors.methods.as_str(),
        ),
        (
            axum::http::header::ACCESS_CONTROL_ALLOW_HEADERS,
            cors.headers.as_str(),
        ),
        (
            axum::http::header::ACCESS_CONTROL_MAX_AGE,
            cors.max_age.as_str(),
        ),
    ] {
        if let Ok(value) = HeaderValue::from_str(value) {
            response_headers.insert(name, value);
        }
    }

    response
}

/// Build a 429 rate-limit-exceeded response in Rust, mirroring the PHP `RateLimit`
/// middleware: JSON body, `Retry-After` header, no X-RateLimit headers (those only
/// appear on allowed responses).
fn rate_limit_denied_response(retry_after_secs: u64) -> Response {
    let mut response = Response::new(Body::from(r#"{"ok":false,"error":"rate_limit_exceeded"}"#));
    *response.status_mut() = StatusCode::TOO_MANY_REQUESTS;
    let headers = response.headers_mut();
    headers.insert(
        axum::http::header::CONTENT_TYPE,
        HeaderValue::from_static("application/json"),
    );
    if let Ok(value) = HeaderValue::from_str(&retry_after_secs.to_string()) {
        headers.insert(axum::http::header::RETRY_AFTER, value);
    }
    response
}

fn format_set_cookie(cookie: &SetCookie) -> String {
    let encoded_value = encode_cookie_value(&cookie.value);
    let mut header = format!("{}={}", cookie.name, encoded_value);

    if let Some(path) = &cookie.path {
        header.push_str(&format!("; Path={path}"));
    }
    if cookie.http_only {
        header.push_str("; HttpOnly");
    }
    if cookie.secure {
        header.push_str("; Secure");
    }
    if let Some(same_site) = &cookie.same_site {
        header.push_str(&format!("; SameSite={same_site}"));
    }
    if let Some(max_age) = cookie.max_age {
        header.push_str(&format!("; Max-Age={max_age}"));
    }

    header
}

/// Percent-encode characters that are not allowed unquoted in a Set-Cookie value per RFC 6265.
fn encode_cookie_value(value: &str) -> String {
    let mut encoded = String::with_capacity(value.len());
    for byte in value.bytes() {
        match byte {
            // RFC 6265 §4.1.1 cookie-octet: allowed unescaped
            0x21 | 0x23..=0x2B | 0x2D..=0x3A | 0x3C..=0x5B | 0x5D..=0x7E => {
                encoded.push(byte as char);
            }
            _ => {
                encoded.push_str(&format!("%{byte:02X}"));
            }
        }
    }
    encoded
}

fn memory_rss_bytes() -> u64 {
    #[cfg(target_os = "linux")]
    {
        linux_memory_rss_bytes().unwrap_or(0)
    }

    #[cfg(not(target_os = "linux"))]
    {
        0
    }
}

#[cfg(target_os = "linux")]
fn linux_memory_rss_bytes() -> Option<u64> {
    let statm = std::fs::read_to_string("/proc/self/statm").ok()?;
    let page_size = unsafe { libc::sysconf(libc::_SC_PAGESIZE) };
    if page_size <= 0 {
        return None;
    }

    parse_statm_rss_bytes(&statm, page_size as u64)
}

fn parse_statm_rss_bytes(statm: &str, page_size: u64) -> Option<u64> {
    let resident_pages = statm.split_whitespace().nth(1)?.parse::<u64>().ok()?;
    resident_pages.checked_mul(page_size)
}

#[cfg(test)]
mod tests {
    use super::{
        bind_tcp_listener, buffer_request_body, encode_cookie_value, format_set_cookie,
        init_project, inject_security_headers, native_cors_preflight_response,
        parse_statm_rss_bytes, rate_limit_denied_response, RequestDurationHistogram,
        MAX_IN_MEMORY_REQUEST_BODY_BYTES,
    };
    use axum::body::Body;
    use axum::response::Response;
    use fium_transport::{BootCors, SetCookie};
    use std::net::{IpAddr, Ipv4Addr, SocketAddr};
    use tokio::time::Duration;

    #[test]
    fn encode_cookie_value_special_chars() {
        assert_eq!(encode_cookie_value("hello world"), "hello%20world");
        assert_eq!(encode_cookie_value("a;b"), "a%3Bb");
    }

    #[test]
    fn format_set_cookie_includes_all_flags() {
        let cookie = SetCookie {
            name: "session".into(),
            value: "abc".into(),
            path: Some("/".into()),
            http_only: true,
            secure: true,
            same_site: Some("Lax".into()),
            max_age: Some(3600),
        };

        let header = format_set_cookie(&cookie);
        assert!(header.contains("HttpOnly"));
        assert!(header.contains("Secure"));
        assert!(header.contains("SameSite=Lax"));
        assert!(header.contains("Max-Age=3600"));
    }

    #[test]
    fn request_duration_histogram_renders_cumulative_buckets() {
        let histogram = RequestDurationHistogram::new();
        histogram.record(Duration::from_millis(3));
        histogram.record(Duration::from_millis(7));

        let rendered = histogram.render_prometheus();
        assert!(rendered.contains("fium_request_duration_seconds_bucket{le=\"0.005\"} 1"));
        assert!(rendered.contains("fium_request_duration_seconds_bucket{le=\"0.01\"} 2"));
        assert!(rendered.contains("fium_request_duration_seconds_count 2"));
    }

    #[test]
    fn parse_statm_rss_bytes_parses_resident_pages() {
        assert_eq!(
            parse_statm_rss_bytes("100 25 0 0 0 0 0", 4096),
            Some(102_400)
        );
        assert_eq!(parse_statm_rss_bytes("invalid", 4096), None);
    }

    #[test]
    fn native_cors_preflight_response_uses_manifest_config() {
        // The native path must serve exactly the config PHP resolved (not env defaults),
        // so a single-origin policy is never silently turned into "*".
        let cors = BootCors {
            origins: "https://example.com".into(),
            methods: "GET, POST".into(),
            headers: "Content-Type".into(),
            max_age: "600".into(),
        };
        let response = native_cors_preflight_response(&cors);
        assert_eq!(response.status(), axum::http::StatusCode::NO_CONTENT);
        let headers = response.headers();
        assert_eq!(
            headers
                .get("access-control-allow-origin")
                .and_then(|v| v.to_str().ok()),
            Some("https://example.com")
        );
        assert_eq!(
            headers
                .get("access-control-allow-methods")
                .and_then(|v| v.to_str().ok()),
            Some("GET, POST")
        );
        assert_eq!(
            headers
                .get("access-control-max-age")
                .and_then(|v| v.to_str().ok()),
            Some("600")
        );
    }

    #[test]
    fn init_project_scaffolds_expected_files() {
        let dir = std::env::temp_dir().join(format!("fium-init-test-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        init_project(&dir).expect("init should scaffold the project");

        for file in ["app.php", "README.md", ".env", ".env.example", ".gitignore"] {
            assert!(dir.join(file).is_file(), "scaffold should include {file}");
        }
        for subdir in ["public", "storage/sessions", "storage/runtime"] {
            assert!(
                dir.join(subdir).is_dir(),
                "scaffold should include {subdir}/"
            );
        }

        // app.php shows the JSON helper; .env enables debug so the scaffold boots as-is.
        let app = std::fs::read_to_string(dir.join("app.php")).unwrap();
        assert!(app.contains("Response::json"));
        let env = std::fs::read_to_string(dir.join(".env")).unwrap();
        assert!(env.contains("FIUM_DEBUG=true"));

        std::fs::remove_dir_all(&dir).ok();
    }

    #[test]
    fn rate_limit_denied_response_shape() {
        let response = rate_limit_denied_response(42);
        assert_eq!(response.status(), axum::http::StatusCode::TOO_MANY_REQUESTS);
        assert_eq!(
            response
                .headers()
                .get("retry-after")
                .and_then(|v| v.to_str().ok()),
            Some("42")
        );
        // X-RateLimit headers are only on allowed responses, not on 429.
        assert!(!response.headers().contains_key("x-ratelimit-remaining"));
    }

    #[test]
    fn inject_security_headers_adds_the_standard_set() {
        let mut response = Response::new(Body::empty());
        inject_security_headers(&mut response);
        let headers = response.headers();
        assert_eq!(
            headers
                .get("x-content-type-options")
                .and_then(|v| v.to_str().ok()),
            Some("nosniff")
        );
        assert_eq!(
            headers.get("x-frame-options").and_then(|v| v.to_str().ok()),
            Some("DENY")
        );
        assert!(headers.contains_key("referrer-policy"));
        assert!(headers.contains_key("permissions-policy"));
    }

    #[test]
    fn bind_tcp_listener_respects_requested_address() {
        let listener = bind_tcp_listener(
            SocketAddr::new(IpAddr::V4(Ipv4Addr::LOCALHOST), 0),
            128,
            true,
        )
        .expect("listener should bind");

        assert!(listener.local_addr().is_ok());
    }

    #[tokio::test]
    async fn buffer_request_body_keeps_small_utf8_payload_in_memory() {
        let result = buffer_request_body(
            Body::from("hello=fium"),
            1024,
            MAX_IN_MEMORY_REQUEST_BODY_BYTES,
            "req_test_small",
        )
        .await;

        let (body, body_file) = match result {
            Ok(value) => value,
            Err(_) => panic!("small request body should buffer in memory"),
        };

        assert_eq!(body.as_deref(), Some("hello=fium"));
        assert!(body_file.is_none());
    }

    #[tokio::test]
    async fn buffer_request_body_spills_large_payload_to_disk() {
        let payload = "a".repeat(MAX_IN_MEMORY_REQUEST_BODY_BYTES + 1);
        let result = buffer_request_body(
            Body::from(payload.clone()),
            payload.len() + 16,
            MAX_IN_MEMORY_REQUEST_BODY_BYTES,
            "req_test_large",
        )
        .await;

        let (body, body_file) = match result {
            Ok(value) => value,
            Err(_) => panic!("large request body should spill to disk"),
        };

        assert!(body.is_none());

        let body_file = match body_file {
            Some(path) => path,
            None => panic!("large request body should return a temp file path"),
        };

        let stored = tokio::fs::read_to_string(&body_file)
            .await
            .expect("temp file should be readable");
        assert_eq!(stored, payload);
        let _ = tokio::fs::remove_file(body_file).await;
    }

    #[tokio::test]
    async fn buffer_request_body_spills_non_utf8_payload_to_disk() {
        let payload = vec![0xf0, 0x28, 0x8c, 0x28];
        let result = buffer_request_body(
            Body::from(payload.clone()),
            1024,
            MAX_IN_MEMORY_REQUEST_BODY_BYTES,
            "req_test_binary",
        )
        .await;

        let (body, body_file) = match result {
            Ok(value) => value,
            Err(_) => panic!("non-UTF-8 request body should spill to disk"),
        };

        assert!(body.is_none());

        let body_file = match body_file {
            Some(path) => path,
            None => panic!("non-UTF-8 request body should return a temp file path"),
        };

        let stored = tokio::fs::read(&body_file)
            .await
            .expect("temp file should be readable");
        assert_eq!(stored, payload);
        let _ = tokio::fs::remove_file(body_file).await;
    }
}
