mod config;
mod embed;
mod route;
mod worker;

use anyhow::anyhow;
use arc_swap::ArcSwap;
use axum::{
    body::{to_bytes, Body},
    extract::{ConnectInfo, Request, State},
    http::{HeaderValue, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use clap::Parser;
use config::{LogFormat, RuntimeConfig};
use fium_transport::{CookieMap, HeaderMap, SetCookie, WorkerRequest, PROTOCOL_VERSION};
use route::RouteTable;
use std::{net::SocketAddr, path::PathBuf, sync::Arc, time::Instant};
use tokio::time::Duration;
use tower_http::compression::{CompressionLayer, predicate::SizeAbove};
use tower_http::set_header::SetResponseHeaderLayer;
use tracing::{error, info, warn};
use uuid::Uuid;
use worker::WorkerPool;

#[derive(Parser)]
#[command(name = "fium", about = "Deno for PHP — a fast runtime for PHP applications")]
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
}

#[derive(Clone)]
struct AppState {
    routes: Arc<ArcSwap<RouteTable>>,
    pool: WorkerPool,
    config: Arc<RuntimeConfig>,
    start_time: Instant,
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

            let cfg = RuntimeConfig::load(
                &app_dir,
                host.as_deref(),
                port,
                workers,
                None,
                None,
                false,
            );

            init_logging(&cfg);
            dev_serve(app_path, app_dir, cfg).await
        }
        Cli::Init { directory } => {
            init_project(&directory)
        }
    }
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

    let app_dir = app_path.parent().unwrap_or_else(|| std::path::Path::new(".")).to_path_buf();
    Ok((app_path, app_dir))
}

fn init_logging(cfg: &RuntimeConfig) {
    use tracing_subscriber::{fmt, EnvFilter};

    let filter = EnvFilter::try_from_default_env()
        .unwrap_or_else(|_| EnvFilter::new(&cfg.log_level));

    match cfg.log_format {
        LogFormat::Json => {
            fmt()
                .json()
                .with_env_filter(filter)
                .init();
        }
        LogFormat::Pretty => {
            fmt()
                .with_env_filter(filter)
                .init();
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
        worker_entrypoint.to_string_lossy().to_string(),
        app_path.to_string_lossy().to_string(),
        cfg.workers,
        cfg.max_requests,
        cfg.worker_timeout_ms,
    );

    let routes = pool.boot().await
        .map_err(|error| anyhow!(error))?;

    print_boot_banner(&cfg, &routes);

    let config = Arc::new(cfg);

    let state = AppState {
        routes: Arc::new(ArcSwap::from_pointee(routes)),
        pool,
        config: config.clone(),
        start_time: Instant::now(),
    };

    let app = build_router(state, &config);

    let address: SocketAddr = format!("{}:{}", config.host, config.port).parse()?;

    // TLS support
    if config.tls_enabled() {
        let (cert_path, key_path) = if config.tls_self_signed {
            let tls_dir = app_path.parent().unwrap_or_else(|| std::path::Path::new(".")).join(".fium/tls");
            std::fs::create_dir_all(&tls_dir)?;
            let cert_path = tls_dir.join("cert.pem");
            let key_path = tls_dir.join("key.pem");

            if !cert_path.exists() || !key_path.exists() {
                info!("generating self-signed TLS certificate for development");
                let cert = rcgen::generate_simple_self_signed(vec!["localhost".to_string(), config.host.clone()])?;
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

        let rustls_config = axum_server::tls_rustls::RustlsConfig::from_pem_file(&cert_path, &key_path).await?;
        let handle = axum_server::Handle::new();
        let handle_for_signal = handle.clone();
        tokio::spawn(async move {
            shutdown_signal().await;
            handle_for_signal.graceful_shutdown(Some(Duration::from_secs(5)));
        });

        let tcp = std::net::TcpListener::bind(address)?;
        let _ = tcp.set_nonblocking(true);

        axum_server::from_tcp_rustls(tcp, rustls_config)
            .handle(handle)
            .serve(app)
            .await?;
    } else {
        let scheme = "http";
        info!(%address, %scheme, "starting runtime");

        let tcp = std::net::TcpListener::bind(address)?;
        let _ = tcp.set_nonblocking(true);

        let handle = axum_server::Handle::new();
        let handle_for_signal = handle.clone();
        tokio::spawn(async move {
            shutdown_signal().await;
            handle_for_signal.graceful_shutdown(Some(Duration::from_secs(5)));
        });

        axum_server::from_tcp(tcp)
            .handle(handle)
            .serve(app)
            .await?;
    }

    Ok(())
}

async fn health(
    State(state): State<AppState>,
    request: Request<Body>,
) -> Response {
    let accept = request
        .headers()
        .get(axum::http::header::ACCEPT)
        .and_then(|v| v.to_str().ok())
        .unwrap_or("");

    if accept.contains("application/json") {
        let workers = state.pool.workers();
        let total_requests: u64 = workers.iter().map(|w| w.requests_handled()).sum();
        let total_restarts: u64 = workers.iter().map(|w| w.restarts()).sum();
        let total_errors: u64 = workers.iter().map(|w| w.errors()).sum();
        let uptime = state.start_time.elapsed().as_secs();

        let json = serde_json::json!({
            "status": "ok",
            "uptime_seconds": uptime,
            "workers": workers.len(),
            "requests_total": total_requests,
            "restarts_total": total_restarts,
            "errors_total": total_errors,
        });

        (
            StatusCode::OK,
            [(axum::http::header::CONTENT_TYPE, "application/json")],
            json.to_string(),
        ).into_response()
    } else {
        (StatusCode::OK, "ok").into_response()
    }
}

async fn metrics(State(state): State<AppState>) -> Response {
    let workers = state.pool.workers();
    let total_requests: u64 = workers.iter().map(|w| w.requests_handled()).sum();
    let total_restarts: u64 = workers.iter().map(|w| w.restarts()).sum();
    let total_errors: u64 = workers.iter().map(|w| w.errors()).sum();
    let uptime = state.start_time.elapsed().as_secs();

    let body = format!(
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
         # HELP fium_uptime_seconds Server uptime in seconds.\n\
         # TYPE fium_uptime_seconds gauge\n\
         fium_uptime_seconds {uptime}\n",
        workers = workers.len(),
    );

    (
        StatusCode::OK,
        [(axum::http::header::CONTENT_TYPE, "text/plain; version=0.0.4; charset=utf-8")],
        body,
    ).into_response()
}

fn print_boot_banner(cfg: &RuntimeConfig, routes: &RouteTable) {
    let scheme = if cfg.tls_enabled() { "https" } else { "http" };
    let addr = format!("{}://{}:{}", scheme, cfg.host, cfg.port);

    eprintln!();
    eprintln!("  \x1b[1;36mfium\x1b[0m  ready");
    eprintln!();
    eprintln!("  \x1b[2m→\x1b[0m  URL:     \x1b[1;4m{}\x1b[0m", addr);
    eprintln!("  \x1b[2m→\x1b[0m  Workers: \x1b[1m{}\x1b[0m", cfg.workers);
    eprintln!("  \x1b[2m→\x1b[0m  TLS:     {}", if cfg.tls_enabled() { "\x1b[32menabled\x1b[0m" } else { "\x1b[2moff\x1b[0m" });
    if cfg.static_enabled {
        if let Some(ref dir) = cfg.static_dir {
            eprintln!("  \x1b[2m→\x1b[0m  Static:  {}", dir.display());
        }
    }
    eprintln!();

    for (method, path, name) in routes.list() {
        eprintln!("  \x1b[33m{:<7}\x1b[0m {} \x1b[2m{}\x1b[0m", method, path, name);
    }

    eprintln!();
}

fn build_router(state: AppState, config: &RuntimeConfig) -> axum::extract::connect_info::IntoMakeServiceWithConnectInfo<Router, SocketAddr> {
    let mut app = Router::new()
        .route("/health", get(health))
        .route("/_fium/metrics", get(metrics))
        .fallback(dispatch)
        .with_state(state);

    // Cache-Control for static assets — 1 hour by default, immutable for hashed assets works too.
    let cache_header = axum::http::HeaderValue::from_static("public, max-age=3600");

    if config.static_enabled {
        if let Some(ref static_dir) = config.static_dir {
            if static_dir.is_dir() {
                info!(dir = %static_dir.display(), "serving static files");
                let serve_dir = tower_http::services::ServeDir::new(static_dir)
                    .precompressed_gzip()
                    .precompressed_br();
                app = Router::new()
                    .nest_service(
                        "/",
                        serve_dir.fallback(app.into_service()),
                    )
                    .layer(SetResponseHeaderLayer::overriding(
                        axum::http::header::CACHE_CONTROL,
                        cache_header,
                    ));
            }
        }
    }

    app.layer(
        CompressionLayer::new()
            .compress_when(SizeAbove::new(256))
    )
        .into_make_service_with_connect_info::<SocketAddr>()
}

async fn dispatch(
    State(state): State<AppState>,
    ConnectInfo(addr): ConnectInfo<SocketAddr>,
    request: Request<Body>,
) -> Response {
    let path = request.uri().path().to_string();
    let method = request.method().to_string();

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
    let query_string = request.uri().query().unwrap_or_default().to_string();
    let host = request
        .headers()
        .get(axum::http::header::HOST)
        .and_then(|value| value.to_str().ok())
        .unwrap_or("localhost")
        .to_string();

    // Only trust X-Forwarded-* headers from trusted proxies
    let is_trusted_proxy = is_trusted_proxy_addr(&addr);
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
    let is_secure = scheme == "https";
    let headers = normalize_headers(request.headers());
    let cookies = parse_cookies(request.headers());
    let body_max = state.config.body_max_size;

    // Skip body buffering for methods that never carry a payload.
    let body = if matches!(method.as_str(), "GET" | "HEAD") {
        None
    } else {
        let body_bytes = match to_bytes(request.into_body(), body_max).await {
            Ok(bytes) => bytes,
            Err(error) => {
                error!(%error, request_id, "failed to read request body");
                return (StatusCode::BAD_REQUEST, "invalid request body").into_response();
            }
        };
        if body_bytes.is_empty() {
            None
        } else {
            Some(String::from_utf8_lossy(&body_bytes).to_string())
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
        scheme,
        host,
        client_ip: Some(client_ip),
        is_secure,
        matched_route: Some(route_name.clone()),
    };

    let log_method = worker_request.method.clone();
    let log_path = worker_request.path.clone();

    match state.pool.handle(worker_request).await {
        Ok(worker_response) => {
            let elapsed = start.elapsed();
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

            response
        }
        Err(error_message) => {
            let elapsed = start.elapsed();
            error!(
                %error_message,
                method = %log_method,
                path = %log_path,
                route = %route_name,
                latency_ms = elapsed.as_secs_f64() * 1000.0,
                %request_id,
                "worker dispatch failed"
            );
            let status = if error_message.starts_with("worker request timed out") {
                StatusCode::GATEWAY_TIMEOUT
            } else {
                StatusCode::BAD_GATEWAY
            };

            (status, "worker dispatch failed").into_response()
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
    std::fs::create_dir_all(dir.join("public"))?;
    std::fs::write(
        dir.join(".gitignore"),
        "/storage/\n/.fium/\n/.env\n/vendor/\n",
    )?;
    std::fs::write(
        dir.join(".env.example"),
        "FIUM_DEBUG=true\n# FIUM_API_TOKEN_SECRET=change-me\n# FIUM_SESSION_DRIVER=file\n",
    )?;
    std::fs::create_dir_all(dir.join("storage/sessions"))?;
    std::fs::create_dir_all(dir.join("storage/runtime"))?;

    eprintln!();
    eprintln!("  \x1b[1;36mfium\x1b[0m  project created at {}", dir.display());
    eprintln!();
    eprintln!("  Get started:");
    eprintln!("    cd {}", directory.display());
    eprintln!("    fium serve");
    eprintln!();

    Ok(())
}

const APP_PHP_TEMPLATE: &str = r#"<?php

declare(strict_types=1);

return [
    'GET /' => fn($request) => \Fium\Runtime\Response::json([
        'message' => 'Hello from fium!',
    ]),

    'GET /hello/{name}' => fn($request) => \Fium\Runtime\Response::json([
        'message' => 'Hello, ' . $request->routeParam('name') . '!',
    ]),
];
"#;

async fn dev_serve(app_path: PathBuf, app_dir: PathBuf, cfg: RuntimeConfig) -> anyhow::Result<()> {
    use notify::{RecursiveMode, Watcher};

    // Extract embedded PHP library
    let lib_dir = embed::extract_php_lib(&app_path)?;
    let worker_entrypoint = lib_dir.join("worker.php");

    info!(workers = cfg.workers, "booting PHP worker pool (dev mode)...");
    let pool = WorkerPool::new(
        worker_entrypoint.to_string_lossy().to_string(),
        app_path.to_string_lossy().to_string(),
        cfg.workers,
        cfg.max_requests,
        cfg.worker_timeout_ms,
    );

    let routes = pool.boot().await
        .map_err(|error| anyhow!(error))?;

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
    };

    let app = build_router(state, &config);

    // File watcher — restart workers on PHP file changes and refresh the route table.
    let pool_for_watcher = pool.clone();
    let routes_for_watcher = routes.clone();
    let (tx, mut rx) = tokio::sync::mpsc::unbounded_channel::<()>();

    let mut watcher = notify::recommended_watcher(move |res: Result<notify::Event, notify::Error>| {
        match res {
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
        }
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
    let tcp = std::net::TcpListener::bind(address)?;
    let _ = tcp.set_nonblocking(true);

    let handle = axum_server::Handle::new();
    let handle_for_signal = handle.clone();
    tokio::spawn(async move {
        shutdown_signal().await;
        handle_for_signal.graceful_shutdown(Some(Duration::from_secs(5)));
    });

    axum_server::from_tcp(tcp)
        .handle(handle)
        .serve(app)
        .await?;

    Ok(())
}

fn is_trusted_proxy_addr(addr: &SocketAddr) -> bool {
    let trusted = std::env::var("FIUM_TRUSTED_PROXIES").unwrap_or_default();
    let ip = addr.ip();

    if trusted.is_empty() {
        // Default: trust loopback only
        return ip.is_loopback();
    }

    if trusted == "*" {
        return true;
    }

    trusted
        .split(',')
        .any(|p| p.trim().parse::<std::net::IpAddr>().map_or(false, |trusted_ip| trusted_ip == ip))
}

async fn shutdown_signal() {
    let _ = tokio::signal::ctrl_c().await;
    info!("shutdown signal received");
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
