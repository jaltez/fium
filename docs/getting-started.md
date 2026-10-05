# Getting Started

## Prerequisites

- **Rust** toolchain (1.75+): [rustup.rs](https://rustup.rs)
- **PHP** 8.1+ CLI: `php -v` to verify

## Installation

```bash
git clone <repo-url> fium
cd fium
cargo build --release
```

The binary is at `target/release/fium`.

## Create a new project

```bash
fium init myapp
cd myapp
```

This creates:

```
myapp/
  app.php          # Your routes and handlers
  public/          # Static files (CSS, JS, images)
  storage/
    sessions/      # Session files
    runtime/       # Runtime data
  .env.example     # Environment template
  .gitignore
```

## Write your first app

Edit `app.php`:

```php
<?php

declare(strict_types=1);

use Fium\Runtime\Request;
use Fium\Runtime\Response;

return [
    'GET /' => fn(Request $r) => Response::json([
        'message' => 'Hello from fium!',
    ]),

    'GET /hello/{name}' => fn(Request $r) => Response::json([
        'hello' => $r->routeParam('name'),
    ]),

    'GET /status' => [
        'name' => 'status.show',
        'handler' => fn(Request $r) => Response::json(['ok' => true]),
    ],

    'POST /echo' => fn(Request $r) => Response::json([
        'body' => $r->body(),
        'query' => $r->query('q'),
    ]),
];
```

## Start the server

```bash
fium serve
```

You'll see:

```
  fium  ready

  →  URL:     http://127.0.0.1:3000
  →  Workers: 4
  →  TLS:     off

  GET     / get_index
  GET     /hello/{name} get_hello_name
  POST    /echo post_echo
```

## Development mode

Use `fium dev` for automatic reloading when PHP files change:

```bash
fium dev
```

## Add middleware

```php
return [
    'middleware_groups' => [
        'web' => ['secure', 'cors'],
        'api' => ['web', 'ratelimit:100', 'bearer'],
    ],

    'middleware' => ['web'],

    'GET /' => fn($r) => Response::json(['ok' => true]),

    'api' => [
        'prefix' => '/api',
        'middleware' => ['api'],
        'routes' => [
            'GET /me' => \App\Handlers\MeHandler::class,
        ],
    ],
];
```

## Next steps

- [Configuration Reference](configuration.md): customize with `fium.toml`
- [Middleware Guide](middleware.md): all built-in middleware
- [Deployment Guide](deployment.md): production deployment
