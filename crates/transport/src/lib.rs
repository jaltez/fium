use serde::{Deserialize, Serialize};
use std::collections::{BTreeMap, HashMap};

pub const PROTOCOL_VERSION: u32 = 1;

pub type HeaderMap = HashMap<String, Vec<String>>;
pub type CookieMap = HashMap<String, String>;

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
    #[serde(default)]
    pub body_file: Option<String>,
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
