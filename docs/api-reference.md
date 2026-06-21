# PHP API Reference

Reference for the framework embedded in `php/src/`. All classes live under the
`Fium\` namespace and are autoloaded by `worker.php` (no Composer autoloader).

## Table of contents

- [Application](#application)
- [Request](#request)
- [Response](#response)
- [Validator](#validator)
- [Config](#config)
- [Authentication](#authentication)
- [Sessions](#sessions)
- [Middleware](#middleware)
- [Database](#database)
- [Cache](#cache)

---

## Application

`Fium\Application` boots the routes file, compiles the middleware table, and
dispatches worker requests. It is a singleton.

```php
$app = Application::boot(__DIR__ . '/app.php');
```

Routes are returned as an array from `app.php`. Three forms are supported:

```php
return [
    // Global middleware applied to every route.
    'middleware' => ['add-powered-by'],

    // Concise: METHOD /path => closure
    'GET /ping' => fn(Request $r) => Response::json(['pong' => true]),

    // Concise with options
    'POST /users' => ['middleware' => ['json'], 'name' => 'users.create', 'handler' => fn(Request $r) => /* ... */],

    // Class handler
    'GET /' => HomeHandler::class,

    // Group: shared prefix + middleware
    'api' => ['prefix' => '/api', 'middleware' => ['require-json'], 'routes' => [
        'GET /me' => ['middleware' => ['auth-bearer'], 'handler' => fn(Request $r) => /* ... */],
    ]],
];
```

| Method | Description |
|--------|-------------|
| `Application::boot(string $routesPath): self` | Load routes + `.env`, build the route table. |
| `Application::url(string $name, array $params = []): string` | Generate a URL for a named route. |
| `$app->bootManifest(): string` | The JSON boot message (emitted automatically). |
| `$app->baseDir(): string` | The app directory. |

## Request

`Fium\Runtime\Request` wraps the decoded worker payload.

| Method | Description |
|--------|-------------|
| `$r->method()`, `$r->path()` | HTTP method and path. |
| `$r->body(): string` | Raw body (inline or spilled-to-disk). |
| `$r->query(string $key, ?string $default = null)` | Parsed query-string value. |
| `$r->header(string $name): ?string` | Header (case-insensitive). |
| `$r->bearerToken(): ?string` | Bearer token from `Authorization`. |
| `$r->cookie(string $name): ?string` | Cookie value. |
| `$r->json(): ?array`, `$r->form(): ?array` | Decoded JSON / form body. |
| `$r->input(string $key, mixed $default = null)` | Input from JSON or form by content type. |
| `$r->boolean(string $key, bool $default = false)` | Boolean-coerced input. |
| `$r->routeParam(string $name, ?string $default = null)` | Path parameter. |
| `$r->validate(array $rules): Validator` | Validate input (see below). |
| `$r->session(): ?Session`, `$r->csrfToken()`, `$r->csrfField()` | Session access (after `session` middleware). |
| `$r->user(): ?User` | Authenticated user (after `auth`/`bearer`). |

## Response

`Fium\Runtime\Response` — all factories are static and chainable via
`withHeader()` / `withCookie()`.

| Factory | Description |
|--------|-------------|
| `Response::json(array $payload, int $status = 200)` | JSON response. |
| `Response::html(string $html, int $status = 200)` | HTML response. |
| `Response::text(string $text, int $status = 200)` | Plain text response. |
| `Response::redirect(string $url, int $status = 302)` | Redirect. |
| `Response::empty(int $status = 204)` | No-body response. |
| `Response::validationError(array $errors)` | 422 with `{ ok:false, error:'validation_failed', errors }`. |
| `Response::fromThrowable(\Throwable $e, ?string $id, bool $debug)` | 500 envelope; message hidden unless `$debug`. |

```php
return Response::json(['ok' => true])
    ->withHeader('X-Request-Id', $id)
    ->withCookie('fium_session', $sid, '/', httpOnly: true);
```

## Validator

`Fium\Validator` validates an array or a `Request`.

```php
$validator = $r->validate([
    'email' => 'required|email',
    'name'  => 'required|min:2|max:100',
    'age'   => 'nullable|integer|min:13',
    'role'  => 'in:admin,editor,user',
]);
```

Available rules: `required`, `nullable`, `string`, `integer`/`int`, `numeric`,
`email`, `min:n`, `max:n`, `in:a,b,c`, `boolean`/`bool`, `array`. Unknown rules
are ignored. `nullable` allows `null`; `nullable|required` still requires a value.

| Method | Description |
|--------|-------------|
| `$v->passes()`, `$v->fails()` | Overall result. |
| `$v->errors(): array` | `['field' => ['message', ...]]`. |
| `$v->validated(): array` | Only fields that passed (failed fields omitted). |

## Config

`Fium\Config` reads environment variables (`.env` is loaded on boot; real
environment takes precedence).

| Method | Description |
|--------|-------------|
| `Config::get(string $key, ?string $default = null)` | String value. |
| `Config::int(string $key, int $default = 0)` | Integer value. |
| `Config::bool(string $key, bool $default = false)` | Boolean (`1`/`true`/`yes`/`on`). |
| `Config::has(string $key): bool` | Presence check. |

## Authentication

`Fium\Auth\Authenticator` is created from a user store (file-backed or
PDO-backed, selected by `FIUM_USER_DRIVER`). API tokens are HS256-signed JWTs
via `Fium\Auth\ApiTokenService`.

```php
$user = $auth->attempt(['email' => $email, 'password' => $pw], $session);
$token = $request->tokenService()->issue($user);     // for API clients
```

| Method | Description |
|--------|-------------|
| `attempt(array $creds, Session $s, bool $remember = false): ?User` | Login. |
| `register(array $attrs, ?Session $s = null, bool $remember = false): ?User` | Create + optionally log in. |
| `userById(int)`, `userByEmail(string)` | Lookups. |
| `userFromSession(Session $s): ?User` | Resolve the session's user. |
| `logout(Session $s)` | Clear session + regenerate. |
| `updatePassword(User, string)` | Change password (revokes issued tokens). |
| `markEmailVerified(User)`, `revokeTokens(User)` | Email verification / token revocation. |
| `canRegister()`, `canResetPasswords()`, ... | Capability flags (require a mutable store). |

## Sessions

`Fium\Session\Session` is attached to the request by the `session` middleware.
Storage is file-backed or PDO-backed (`FIUM_SESSION_DRIVER`).

```php
$session = $request->session();
$session->put('key', 'value');
$session->flash('status', 'saved');   // available for the next request
$session->regenerate();               // rotate the session id
```

| Method | Description |
|--------|-------------|
| `get/put/forget(string, mixed)` | Key access; marks dirty. |
| `flash(string, mixed)` | Flash data for the next request. |
| `regenerate()` | Mark for id rotation (applied by the store). |
| `remember()` / `forgetRemember()` / `remembers()` | Long-lived ("remember me") cookie. |

## Middleware

Built-in aliases:

| Alias | Class | Description |
|-------|-------|-------------|
| `session` | `StartSession` | Load/persist the session cookie. |
| `auth` | `AuthenticateSession` | Require an authenticated session. |
| `bearer` | `AuthenticateBearer` | Authenticate via Bearer token. |
| `csrf` | `VerifyCsrf` | Verify CSRF token (header `x-csrf-token` or body `_token`). |
| `cors` | `Cors` | CORS headers + preflight. |
| `ratelimit:60` | `RateLimit` | Per-IP rate limit (`requests[,window-seconds]`). |
| `secure` | `SecurityHeaders` | Security headers. |
| `role:admin` | `RequireRole` | Require a user role. |
| `json` | `RequireJsonAccept` | Require `Accept: application/json`. |
| `powered-by` | `AddPoweredByHeader` | Adds `X-Fium-Middleware`. |

Custom middleware implement `Fium\Contracts\Middleware`:

```php
final class MyMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        // before...
        $response = $next($request);
        // after...
        return $response;
    }
}
```

## Database

`Fium\Database\Connection` is a lazy PDO singleton configured from
`FIUM_DB_DSN`, `FIUM_DB_USER`, `FIUM_DB_PASS`.

```php
use Fium\Database\Connection;

$rows = Connection::table('users')->where('active', 1)->orderBy('name')->limit(10)->get();
$count = Connection::table('users')->where('age', '>', 30)->count();
Connection::table('users')->insert(['name' => 'Ada', 'email' => 'a@x.com']);
Connection::table('users')->where('id', 1)->update(['active' => 0]);
Connection::table('users')->where('id', 1)->delete();
Connection::transaction(fn () => /* atomic work */);
```

`QueryBuilder` methods: `select`, `selectRaw`, `where`, `orWhere`, `whereIn`,
`whereNull`, `whereNotNull`, `join`, `leftJoin`, `orderBy`, `groupBy`, `limit`,
`offset`, `get`, `first`, `count`, `exists`, `insert`, `update`, `delete`.

> **Security:** values are always bound as parameters. Identifiers are validated
> against `[a-zA-Z_][a-zA-Z0-9_]*` and used verbatim — never build identifiers
> from untrusted input.

`Fium\Database\Model` is an active-record base:

```php
use Fium\Database\Model;

final class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email', 'active'];
}

$user = User::create(['name' => 'Ada', 'email' => 'a@x.com', 'active' => 1]);
$found = User::find($user->id);
$user->name = 'Augusta';
$user->save();
$user->delete();
```

## Cache

`Fium\Cache\CacheManager` resolves a driver from `FIUM_CACHE_DRIVER`
(`array`, `file`, or `redis`).

```php
$cache = \Fium\Cache\CacheManager::driver();

$value = $cache->remember('expensive-key', function () {
    return compute_something();
}, 60); // ttl in seconds

$cache->set('k', 'v', 300);
$cache->get('k', $default);
$cache->has('k');
$cache->delete('k');
$cache->flush();
```

Configuration:

| Variable | Default | Description |
|----------|---------|-------------|
| `FIUM_CACHE_DRIVER` | `array` | `array`, `file`, or `redis`. |
| `FIUM_CACHE_DIR` | temp dir | Directory for the `file` driver. |
| `FIUM_CACHE_PREFIX` | _(empty)_ | Key prefix for the `file` driver. |
| `FIUM_REDIS_HOST` | `127.0.0.1` | Redis host (`redis` driver). |
| `FIUM_REDIS_PORT` | `6379` | Redis port. |
| `FIUM_REDIS_PASSWORD` | _(none)_ | Redis password. |
| `FIUM_REDIS_DB` | `0` | Redis database index. |
| `FIUM_REDIS_PREFIX` | `fium:` | Redis key prefix. |

> The `redis` driver requires the `redis` PHP extension (phpredis). It is
> optional and never selected unless `FIUM_CACHE_DRIVER=redis`.
