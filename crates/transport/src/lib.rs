use serde::{Deserialize, Serialize};
use std::collections::BTreeMap;

pub const PROTOCOL_VERSION: u32 = 1;

pub type HeaderMap = BTreeMap<String, Vec<String>>;
pub type CookieMap = BTreeMap<String, String>;

/// First message sent by the PHP worker after boot.
/// Contains the route manifest so Rust can build its route table.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct BootMessage {
    pub protocol_version: u32,
    #[serde(rename = "type")]
    pub message_type: String,
    pub routes: Vec<BootRoute>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct BootRoute {
    pub method: String,
    pub path: String,
    pub name: String,
    pub middleware: Vec<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct WorkerRequest {
    pub protocol_version: u32,
    pub request_id: String,
    pub method: String,
    pub path: String,
    pub query_string: String,
    pub headers: HeaderMap,
    pub cookies: CookieMap,
    pub route_params: BTreeMap<String, String>,
    pub body: Option<String>,
    pub scheme: String,
    pub host: String,
    pub client_ip: Option<String>,
    pub is_secure: bool,
    pub matched_route: Option<String>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct SetCookie {
    pub name: String,
    pub value: String,
    pub path: Option<String>,
    pub http_only: bool,
    pub secure: bool,
    #[serde(default)]
    pub same_site: Option<String>,
    #[serde(default)]
    pub max_age: Option<i64>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct WorkerError {
    pub kind: String,
    pub message: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct WorkerResponse {
    pub protocol_version: u32,
    pub request_id: String,
    pub status: u16,
    pub headers: HeaderMap,
    pub cookies: Vec<SetCookie>,
    pub body: Option<String>,
    pub error: Option<WorkerError>,
}

impl WorkerRequest {
    pub fn example() -> Self {
        Self {
            protocol_version: PROTOCOL_VERSION,
            request_id: "req_example".to_string(),
            method: "GET".to_string(),
            path: "/health".to_string(),
            query_string: String::new(),
            headers: BTreeMap::new(),
            cookies: BTreeMap::new(),
            route_params: BTreeMap::new(),
            body: None,
            scheme: "http".to_string(),
            host: "localhost:3000".to_string(),
            client_ip: Some("127.0.0.1".to_string()),
            is_secure: false,
            matched_route: Some("health".to_string()),
        }
    }
}

impl WorkerResponse {
    pub fn ok(request_id: impl Into<String>, body: impl Into<String>) -> Self {
        let mut headers = BTreeMap::new();
        headers.insert("content-type".to_string(), vec!["application/json".to_string()]);

        Self {
            protocol_version: PROTOCOL_VERSION,
            request_id: request_id.into(),
            status: 200,
            headers,
            cookies: Vec::new(),
            body: Some(body.into()),
            error: None,
        }
    }
}
