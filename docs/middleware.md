# Middleware Guide

Fium includes built-in middleware for common web application needs. Middleware is applied per-route or globally.

## Using middleware

### Per-route

```php
'GET /dashboard' => [
    'middleware' => ['auth', 'role:admin'],
    'handler' => DashboardHandler::class,
],
```

### Global (all routes)

```php
return [
    'middleware' => ['secure', 'cors', 'session'],

    'GET /' => fn($r) => Response::json(['ok' => true]),
];
```

### Route groups

```php
'api' => [
    'prefix' => '/api',
    'middleware' => ['ratelimit:100', 'bearer'],
    'routes' => [
        'GET /me' => ApiMeHandler::class,
    ],
],
```

### Named middleware groups

```php
return [
    'middleware_groups' => [
        'web' => ['secure', 'cors', 'session'],
        'api' => ['web', 'ratelimit:100', 'bearer'],
    ],

    'GET /' => [
        'middleware' => ['web'],
        'handler' => HomeHandler::class,
    ],

    'api' => [
        'prefix' => '/api',
        'middleware' => ['api'],
        'routes' => [
            'GET /me' => ApiMeHandler::class,
        ],
    ],
];
```

Groups are expanded when routes are registered, may reference other groups, and cannot reuse built-in middleware alias names.

## Built-in middleware

### `session` / `start-session`

Starts a session. Required before using `$request->session()`.

```php
'middleware' => ['session']
```

Flash data survives for the next request only:

```php
'POST /profile' => [
    'middleware' => ['session'],
    'handler' => function (Request $r): Response {
        $r->session()?->flash('status', 'Profile saved.');

        return Response::redirect('/profile');
    },
],

'GET /profile' => [
    'middleware' => ['session'],
    'handler' => fn(Request $r): Response => Response::json([
        'status' => $r->session()?->get('status'),
    ]),
],
```

### `auth` / `auth-session`

Requires an authenticated session. Returns 401 if no user is logged in. Must come after `session`.

```php
'middleware' => ['session', 'auth']
```

### `bearer` / `auth-bearer`

Authenticates requests via `Authorization: Bearer <token>` header. Returns 401 for invalid tokens.

```php
'middleware' => ['bearer']
```

### `csrf` / `verify-csrf`

Verifies CSRF tokens on state-changing requests (POST, PUT, PATCH, DELETE). Must come after `session`.

```php
'middleware' => ['session', 'csrf']
```

For server-rendered HTML forms, use `Request::csrfField()` to inject the hidden `_token` field and submit through normal form bodies:

```php
'GET /contact' => fn(Request $r) => Response::html(
    '<form method="POST" action="/contact">'
    . $r->csrfField()
    . '<input type="email" name="email">'
    . '<button type="submit">Send</button>'
    . '</form>'
),

'POST /contact' => [
    'middleware' => ['session', 'csrf'],
    'handler' => fn(Request $r) => Response::json([
        'email' => $r->input('email'),
    ]),
],
```

`Request::input()` now reads JSON bodies, `application/x-www-form-urlencoded`, and text fields from `multipart/form-data` requests.

### `cors`

Handles CORS preflight (`OPTIONS`) and sets `Access-Control-*` headers. Configured via environment variables:

- `FIUM_CORS_ORIGINS` — allowed origins (default: `*`)
- `FIUM_CORS_METHODS` — allowed methods
- `FIUM_CORS_HEADERS` — allowed headers
- `FIUM_CORS_MAX_AGE` — preflight cache duration

```php
'middleware' => ['cors']
```

### `ratelimit`

Simple in-memory rate limiter per client IP. Format: `ratelimit:max_requests` (per 60 seconds) or `ratelimit:max_requests,window_seconds`.

```php
'middleware' => ['ratelimit:60']       // 60 requests per minute
'middleware' => ['ratelimit:100,300']   // 100 requests per 5 minutes
```

Returns `429 Too Many Requests` with `Retry-After` header when exceeded. Adds `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers to responses.

**Note**: Rate limiting is per-worker since workers are independent processes. With 4 workers, the effective limit is approximately 4x the configured value.

### `secure` / `security-headers`

Adds standard security headers to every response:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `X-XSS-Protection: 0`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`

```php
'middleware' => ['secure']
```

### `role`

Role-based access control. Returns 403 if the authenticated user lacks the required role.

```php
'middleware' => ['auth', 'role:admin']
```

### `json` / `require-json`

Requires the request to include an `Accept: application/json` header. Returns 406 if missing.

```php
'middleware' => ['json']
```

### `powered-by` / `add-powered-by`

Adds an `X-Fium-Middleware: add-powered-by` header. Useful for debugging middleware execution.

## Custom middleware

Create a class implementing `Fium\Contracts\Middleware`:

```php
<?php

namespace App\Middleware;

use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class LogRequest implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        fwrite(
            STDERR,
            sprintf("request method=%s path=%s\n", $request->method(), $request->path())
        );

        return $next($request);
    }
}
```

Register it as middleware on routes:

```php
'GET /tracked' => [
    'middleware' => ['session'],
    'handler' => fn($r) => Response::json(['ok' => true]),
],
```

Currently, custom middleware must be referenced directly in handler arrays since the alias registry is internal. Use the full class name with the `handler` key approach for now.
