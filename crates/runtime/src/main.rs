mod embed;
mod route;
mod worker;

use anyhow::anyhow;
use axum::{
    body::{to_bytes, Body},
    extract::{ConnectInfo, Request, State},
    http::{HeaderValue, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use clap::Parser;
use fium_transport::{CookieMap, HeaderMap, SetCookie, WorkerRequest, PROTOCOL_VERSION};
use route::RouteTable;
use std::{net::SocketAddr, path::PathBuf, sync::Arc};
use tracing::{error, info};
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
        #[arg(long, default_value = "127.0.0.1")]
        host: String,

        /// Port to bind to
        #[arg(short, long, default_value_t = 3000)]
        port: u16,

        /// Number of PHP worker processes
        #[arg(short, long, default_value_t = num_workers_default())]
        workers: usize,
    },
}

fn num_workers_default() -> usize {
    std::thread::available_parallelism()
        .map(|n| n.get())
        .unwrap_or(4)
}

#[derive(Clone)]
struct AppState {
    routes: Arc<RouteTable>,
    pool: WorkerPool,
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    let cli = Cli::parse();

    match cli {
        Cli::Serve { app, host, port, workers: worker_count } => {
            serve(app, host, port, worker_count).await
        }
    }
}

async fn serve(app: PathBuf, host: String, port: u16, worker_count: usize) -> anyhow::Result<()> {
    // Resolve app path
    let app_path = if app.is_absolute() {
        app
    } else {
        std::env::current_dir()?.join(app)
    };

    if !app_path.is_file() {
        anyhow::bail!("application file not found: {}", app_path.display());
    }

    // Extract embedded PHP library
    let lib_dir = embed::extract_php_lib(&app_path)?;
    info!(lib_dir = %lib_dir.display(), "extracted PHP library");

    // Build the worker entrypoint path
    let worker_entrypoint = lib_dir.join("worker.php");

    // Boot the worker pool
    info!(workers = worker_count, "booting PHP worker pool...");
    let pool = WorkerPool::new(
        worker_entrypoint.to_string_lossy().to_string(),
        app_path.to_string_lossy().to_string(),
        worker_count,
    );

    let routes = pool.boot().await
        .map_err(|error| anyhow!(error))?;

    let state = AppState {
        routes: Arc::new(routes),
        pool,
    };

    let app = Router::new()
        .route("/health", get(health))
        .fallback(dispatch)
        .with_state(state);

    let address: SocketAddr = format!("{host}:{port}").parse()?;
    info!(%address, "starting runtime");

    let listener = tokio::net::TcpListener::bind(address).await?;
    axum::serve(
        listener,
        app.into_make_service_with_connect_info::<SocketAddr>(),
    )
        .with_graceful_shutdown(shutdown_signal())
        .await?;

    Ok(())
}

async fn health() -> impl IntoResponse {
    (StatusCode::OK, "ok")
}

async fn dispatch(
    State(state): State<AppState>,
    ConnectInfo(addr): ConnectInfo<SocketAddr>,
    request: Request<Body>,
) -> Response {
    let path = request.uri().path().to_string();
    let method = request.method().to_string();

    let Some(route_match) = state.routes.match_route(&method, &path) else {
        return (StatusCode::NOT_FOUND, "route not found").into_response();
    };
    let route = route_match.route;

    let request_id = format!("req_{}", Uuid::new_v4().simple());
    let query_string = request.uri().query().unwrap_or_default().to_string();
    let host = request
        .headers()
        .get(axum::http::header::HOST)
        .and_then(|value| value.to_str().ok())
        .unwrap_or("localhost")
        .to_string();
    let scheme = request
        .headers()
        .get("x-forwarded-proto")
        .and_then(|v| v.to_str().ok())
        .unwrap_or_else(|| request.uri().scheme_str().unwrap_or("http"))
        .to_string();
    let is_secure = scheme == "https";
    let headers = normalize_headers(request.headers());
    let cookies = parse_cookies(request.headers());
    let body_bytes = match to_bytes(request.into_body(), 1024 * 1024).await {
        Ok(bytes) => bytes,
        Err(error) => {
            error!(%error, request_id, "failed to read request body");
            return (StatusCode::BAD_REQUEST, "invalid request body").into_response();
        }
    };
    let body = if body_bytes.is_empty() {
        None
    } else {
        Some(String::from_utf8_lossy(&body_bytes).to_string())
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
        client_ip: Some(addr.ip().to_string()),
        is_secure,
        matched_route: Some(route.name.clone()),
    };

    match state.pool.handle(worker_request).await {
        Ok(worker_response) => {
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
            error!(%error_message, route = %route.name, "worker dispatch failed");
            let status = if error_message.starts_with("worker request timed out") {
                StatusCode::GATEWAY_TIMEOUT
            } else {
                StatusCode::BAD_GATEWAY
            };

            (status, "worker dispatch failed").into_response()
        }
    }
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
