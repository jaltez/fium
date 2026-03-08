mod route;
mod worker;

use axum::{
    body::{to_bytes, Body},
    extract::{Request, State},
    http::{HeaderValue, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
    Router,
};
use fium_transport::{CookieMap, HeaderMap, SetCookie, WorkerRequest, PROTOCOL_VERSION};
use route::RouteTable;
use std::{collections::BTreeMap, net::SocketAddr, sync::Arc};
use tracing::{error, info};
use uuid::Uuid;
use worker::WorkerSupervisor;

const ROUTE_MANIFEST_PATH: &str = "php/routes.json";

#[derive(Clone)]
struct AppState {
    routes: Arc<RouteTable>,
    workers: WorkerSupervisor,
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();

    let state = AppState {
        routes: Arc::new(RouteTable::load_or_phase_one(ROUTE_MANIFEST_PATH)),
        workers: WorkerSupervisor::new("php/worker.php"),
    };

    let app = Router::new()
        .route("/health", get(health))
        .fallback(dispatch)
        .with_state(state);

    let address: SocketAddr = "127.0.0.1:3000".parse()?;
    info!(%address, "starting runtime");

    let listener = tokio::net::TcpListener::bind(address).await?;
    axum::serve(listener, app)
        .with_graceful_shutdown(shutdown_signal())
        .await?;

    Ok(())
}

async fn health() -> impl IntoResponse {
    (StatusCode::OK, "ok")
}

async fn dispatch(State(state): State<AppState>, request: Request<Body>) -> Response {
    let path = request.uri().path().to_string();
    let method = request.method().to_string();

    let Some(route) = state.routes.match_route(&method, &path) else {
        return (StatusCode::NOT_FOUND, "route not found").into_response();
    };

    let request_id = format!("req_{}", Uuid::new_v4().simple());
    let query_string = request.uri().query().unwrap_or_default().to_string();
    let host = request
        .headers()
        .get(axum::http::header::HOST)
        .and_then(|value| value.to_str().ok())
        .unwrap_or("localhost")
        .to_string();
    let scheme = request.uri().scheme_str().unwrap_or("http").to_string();
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
        route_params: BTreeMap::new(),
        body,
        scheme,
        host,
        client_ip: None,
        is_secure: false,
        matched_route: Some(route.name.clone()),
    };

    match state.workers.handle(worker_request).await {
        Ok(worker_response) => {
            let mut response = worker_response
                .body
                .unwrap_or_else(|| "".to_string())
                .into_response();

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
            (StatusCode::BAD_GATEWAY, "worker dispatch failed").into_response()
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
    let mut header = format!("{}={}", cookie.name, cookie.value);

    if let Some(path) = &cookie.path {
        header.push_str(&format!("; Path={path}"));
    }
    if cookie.http_only {
        header.push_str("; HttpOnly");
    }
    if cookie.secure {
        header.push_str("; Secure");
    }

    header
}
