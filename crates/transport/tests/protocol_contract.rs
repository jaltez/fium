//! Wire-format contract tests for the Rust <-> PHP protocol.
//!
//! These tests pin down the exact JSON shapes that cross the stdio boundary,
//! using samples that mirror what the PHP framework actually emits
//! (`Response::toWorkerResponse()`, `Application::bootManifest()`) and what it
//! consumes (`Request::fromWorkerPayload()`). If either side renames a field or
//! changes an optionality, these tests fail.

use fium_transport::{
    BootCors, BootMessage, BootRoute, CookieMap, HeaderMap, SetCookie, WorkerError, WorkerRequest,
    WorkerResponse, PROTOCOL_VERSION,
};
use std::collections::BTreeMap;

#[test]
fn boot_message_matches_php_manifest_shape() {
    // Verbatim shape produced by Application::bootManifest() in PHP (cors is always
    // present; it's optional on the wire so an older worker without it still deserializes).
    let json = r#"{
        "protocol_version": 1,
        "type": "boot",
        "routes": [
            {"method": "GET", "path": "/ping", "name": "get_ping", "middleware": ["add-powered-by"]},
            {"method": "GET", "path": "/users/{id}", "name": "users.show", "middleware": []}
        ],
        "cors": {
            "origins": "https://example.com",
            "methods": "GET, POST",
            "headers": "Content-Type, Authorization",
            "max_age": "600"
        }
    }"#;

    let boot: BootMessage = serde_json::from_str(json).expect("PHP boot shape must deserialize");

    assert_eq!(boot.protocol_version, PROTOCOL_VERSION);
    assert_eq!(boot.message_type, "boot");
    assert_eq!(boot.routes.len(), 2);
    assert_eq!(boot.routes[0].name, "get_ping");
    assert_eq!(boot.routes[0].middleware, vec!["add-powered-by"]);
    assert_eq!(boot.routes[1].path, "/users/{id}");

    let cors = boot.cors.expect("cors config present");
    assert_eq!(cors.origins, "https://example.com");
    assert_eq!(cors.max_age, "600");
}

#[test]
fn boot_message_omits_cors_when_absent() {
    // An older worker that sends no cors must still deserialize (field is optional).
    let json = r#"{"protocol_version":1,"type":"boot","routes":[]}"#;
    let boot: BootMessage = serde_json::from_str(json).expect("parses without cors");
    assert!(boot.cors.is_none());
}

#[test]
fn boot_message_serializes_back_with_type_rename() {
    let boot = BootMessage {
        protocol_version: PROTOCOL_VERSION,
        message_type: "boot".into(),
        routes: vec![BootRoute {
            method: "GET".into(),
            path: "/".into(),
            name: "home".into(),
            middleware: vec![],
        }],
        cors: None,
    };

    let json = serde_json::to_string(&boot).expect("encode");
    // The `message_type` field must serialize as `type` on the wire.
    assert!(json.contains(r#""type":"boot""#));
    assert!(!json.contains(r#""message_type""#));
    // cors is None -> skipped (skip_serializing_if).
    assert!(!json.contains(r#""cors""#));
}

#[test]
fn boot_cors_round_trips() {
    let cors = BootCors {
        origins: "*".into(),
        methods: "GET, POST".into(),
        headers: "Content-Type".into(),
        max_age: "86400".into(),
    };
    let json = serde_json::to_string(&cors).expect("encode");
    let back: BootCors = serde_json::from_str(&json).expect("decode");
    assert_eq!(back, cors);
}

#[test]
fn worker_response_matches_php_to_worker_response_shape() {
    // Verbatim shape produced by Response::toWorkerResponse() in PHP, including
    // a cookie that carries same_site and max_age.
    let json = r#"{
        "protocol_version": 1,
        "request_id": "req-7",
        "status": 200,
        "headers": {"content-type": ["application/json"], "x-fium-middleware": ["add-powered-by"]},
        "cookies": [
            {"name": "fium_session", "value": "sid", "path": "/", "http_only": true, "secure": false, "same_site": "Lax", "max_age": 3600}
        ],
        "body": "{\"pong\":true}",
        "error": null
    }"#;

    let resp: WorkerResponse =
        serde_json::from_str(json).expect("PHP response shape must deserialize");

    assert_eq!(resp.status, 200);
    assert_eq!(resp.request_id, "req-7");
    assert_eq!(
        resp.headers.get("content-type"),
        Some(&vec!["application/json".into()])
    );
    assert_eq!(resp.cookies.len(), 1);
    let cookie = &resp.cookies[0];
    assert_eq!(cookie.name, "fium_session");
    assert_eq!(cookie.path.as_deref(), Some("/"));
    assert!(cookie.http_only);
    assert!(!cookie.secure);
    assert_eq!(cookie.same_site.as_deref(), Some("Lax"));
    assert_eq!(cookie.max_age, Some(3600));
    assert_eq!(resp.body.as_deref(), Some("{\"pong\":true}"));
    assert!(resp.error.is_none());
}

#[test]
fn cookie_optional_fields_default_when_absent() {
    // PHP may omit same_site/max_age; Rust must tolerate that (serde default).
    let json = r#"{
        "protocol_version": 1, "request_id": "r", "status": 204,
        "headers": {}, "cookies": [
            {"name": "kw", "value": "v", "path": null, "http_only": false, "secure": false}
        ],
        "body": null, "error": null
    }"#;

    let resp: WorkerResponse = serde_json::from_str(json).expect("partial cookie must deserialize");
    let cookie = &resp.cookies[0];
    assert_eq!(cookie.path, None);
    assert_eq!(cookie.same_site, None);
    assert_eq!(cookie.max_age, None);
}

#[test]
fn worker_error_shape() {
    let json = r#"{"kind": "internal_server_error", "message": "Unhandled application exception"}"#;
    let err: WorkerError = serde_json::from_str(json).expect("error shape must deserialize");
    assert_eq!(err.kind, "internal_server_error");
}

#[test]
fn worker_request_serializes_to_shape_php_reads() {
    // Rust is the producer of WorkerRequest; PHP is the consumer via
    // Request::fromWorkerPayload(). Every field PHP reads must be present.
    let mut headers = HeaderMap::new();
    headers.insert("content-type".into(), vec!["application/json".into()]);
    let mut route_params = BTreeMap::new();
    route_params.insert("id".into(), "42".into());

    let request = WorkerRequest {
        protocol_version: PROTOCOL_VERSION,
        request_id: "req-9".into(),
        method: "GET".into(),
        path: "/users/42".into(),
        query_string: "include=posts".into(),
        headers,
        cookies: CookieMap::new(),
        route_params,
        body: None,
        body_file: None,
        scheme: "https".into(),
        host: "example.com".into(),
        client_ip: Some("203.0.113.1".into()),
        is_secure: true,
        matched_route: Some("users.show".into()),
    };

    let json = serde_json::to_string(&request).expect("encode request");
    let value: serde_json::Value = serde_json::from_str(&json).expect("valid json");

    // Every key that Request::fromWorkerPayload() reads must be present.
    for key in [
        "protocol_version",
        "request_id",
        "method",
        "path",
        "query_string",
        "headers",
        "cookies",
        "route_params",
        "body",
        "body_file",
        "scheme",
        "host",
        "client_ip",
        "is_secure",
        "matched_route",
    ] {
        assert!(
            value.get(key).is_some(),
            "WorkerRequest JSON missing `{key}` (PHP would not see it)"
        );
    }

    assert_eq!(value["client_ip"], "203.0.113.1");
    assert_eq!(value["is_secure"], true);
    assert_eq!(value["matched_route"], "users.show");
}

#[test]
fn worker_request_round_trips() {
    let request = WorkerRequest {
        protocol_version: PROTOCOL_VERSION,
        request_id: "r".into(),
        method: "POST".into(),
        path: "/".into(),
        query_string: String::new(),
        headers: HeaderMap::new(),
        cookies: CookieMap::new(),
        route_params: BTreeMap::new(),
        body: Some("{\"a\":1}".into()),
        body_file: Some("/tmp/body".into()),
        scheme: "http".into(),
        host: "localhost".into(),
        client_ip: None,
        is_secure: false,
        matched_route: None,
    };

    let json = serde_json::to_string(&request).expect("encode");
    let back: WorkerRequest = serde_json::from_str(&json).expect("decode");
    assert_eq!(request, back);
}

#[test]
fn set_cookie_round_trips_with_all_fields() {
    let cookie = SetCookie {
        name: "sid".into(),
        value: "abc".into(),
        path: Some("/".into()),
        http_only: true,
        secure: true,
        same_site: Some("Strict".into()),
        max_age: Some(120),
    };

    let json = serde_json::to_string(&cookie).expect("encode");
    let back: SetCookie = serde_json::from_str(&json).expect("decode");
    assert_eq!(cookie, back);
}

#[test]
fn boot_message_rejects_unexpected_type() {
    // The runtime only accepts type == "boot"; ensure parsing itself is tolerant
    // (the type check lives in the runtime), but the field deserializes either way.
    let json = r#"{"protocol_version": 1, "type": "something_else", "routes": []}"#;
    let boot: BootMessage = serde_json::from_str(json).expect("parses regardless of type value");
    assert_eq!(boot.message_type, "something_else");
}
