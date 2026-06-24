# Protocol V1

This document defines the transport contract between the Rust runtime and managed PHP workers.

## Goals

- keep the transport narrow and explicit
- make request and response framing easy to inspect in development
- version the protocol from the start
- avoid exposing raw PHP SAPI globals as the primary application contract

## Transport Shape

Communication uses newline-delimited JSON messages over stdin/stdout pipes between the Rust runtime and each PHP worker process.

Each frame is one UTF-8 JSON object followed by a newline (`\n`). The Rust runtime writes requests to the worker's stdin; the worker writes responses to its stdout.

This is not intended as the final performance format. It is intended as the easiest format to debug, log, and evolve during the feasibility phase.

## Lifecycle

A PHP worker goes through two phases:

1. **Boot** — the worker starts, loads the application file, and emits a single boot message on stdout containing the route manifest.
2. **Request loop** — the worker reads request frames from stdin and writes response frames to stdout, one per request.

When a worker crashes and the Rust supervisor restarts it, the new worker process repeats both phases. The runtime consumes and discards the boot message from restarted workers (the route table is already built from the initial boot).

## Boot Message

The very first line a PHP worker writes to stdout after starting is a boot message. This replaces the previous `routes.json` file-based route manifest.

### Boot Message Fields

- `protocol_version` — integer, must match the runtime's expected version
- `type` — string, must be `"boot"`
- `routes` — array of route objects

### Boot Route Fields

Each route object in the `routes` array contains:

- `method` — HTTP method (e.g. `"GET"`, `"POST"`)
- `path` — normalized path pattern (e.g. `"/hello/{name}"`)
- `name` — route name for internal dispatch (e.g. `"get_hello_name"`)
- `middleware` — array of middleware alias strings

### Example Boot Message

```json
{
  "protocol_version": 1,
  "type": "boot",
  "routes": [
    {
      "method": "GET",
      "path": "/",
      "name": "get_index",
      "middleware": ["start-session", "add-powered-by"]
    },
    {
      "method": "GET",
      "path": "/hello/{name}",
      "name": "get_hello_name",
      "middleware": ["add-powered-by"]
    },
    {
      "method": "GET",
      "path": "/ping",
      "name": "get_ping",
      "middleware": []
    }
  ]
}
```

## Request Envelope

Every request sent from Rust to PHP contains:

- `protocol_version`
- `request_id`
- `method`
- `path`
- `query_string`
- `headers`
- `cookies`
- `route_params`
- `body`
- `body_file`
- `scheme`
- `host`
- `client_ip`
- `is_secure`
- `matched_route`

## Response Envelope

Every response sent from PHP to Rust contains:

- `protocol_version`
- `request_id`
- `status`
- `headers`
- `cookies`
- `body`
- `error`

## Error Semantics

- Malformed worker responses are treated as worker failures.
- A timed-out worker request should be logged with the request id.
- Worker crashes trigger supervised restart behavior in the runtime.
- The runtime consumes the boot message from restarted workers before resuming request dispatch.
- Development mode may include structured error details in the response envelope.
- Production mode should avoid leaking raw worker exception details.

## Route Discovery

The runtime discovers routes dynamically at startup through the boot protocol. PHP declares routes in a single application file (e.g. `app.php`) using either:

**Concise format** — string keys like `'METHOD /path'`:

```php
return [
    'GET /hello/{name}' => [
        'name' => 'hello.show',
        'middleware' => ['add-powered-by'],
        'handler' => 'App\\Handlers\\HelloHandler',
    ],
    'GET /ping' => fn(Request $r) => Response::json(['pong' => true]),
];
```

Route names are auto-generated from method and path when using the concise format (e.g. `GET /hello/{name}` becomes `get_hello_name`), but array-style concise routes may override this with a `name` key.

The Rust runtime uses the boot route manifest to build its route table and perform path matching. It passes the `matched_route` name to PHP so the worker can dispatch to the correct handler.

## Example Request Frame

```json
{
  "protocol_version": 1,
  "request_id": "req_abc123",
  "method": "GET",
  "path": "/api/me",
  "query_string": "",
  "headers": {
    "accept": ["application/json"]
  },
  "cookies": {},
  "route_params": {},
  "body": null,
  "body_file": null,
  "scheme": "http",
  "host": "localhost:3000",
  "client_ip": "127.0.0.1",
  "is_secure": false,
  "matched_route": "get_api_me"
}
```

## Example Response Frame

```json
{
  "protocol_version": 1,
  "request_id": "req_abc123",
  "status": 200,
  "headers": {
    "content-type": ["application/json"]
  },
  "cookies": [],
  "body": "{\"ok\":true}",
  "error": null
}
```
