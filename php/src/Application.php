<?php

declare(strict_types=1);

namespace Fium;

use Fium\Auth\Authenticator;
use Fium\Auth\ApiTokenService;
use Fium\Contracts\Handler;
use Fium\Contracts\Middleware;
use Fium\Contracts\TokenService;
use Fium\Middleware\AddPoweredByHeader;
use Fium\Middleware\AuthenticateBearer;
use Fium\Middleware\AuthenticateSession;
use Fium\Middleware\Cors;
use Fium\Middleware\RateLimit;
use Fium\Middleware\RequireRole;
use Fium\Middleware\RequireJsonAccept;
use Fium\Middleware\SecurityHeaders;
use Fium\Middleware\StartSession;
use Fium\Middleware\VerifyCsrf;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class Application
{
    private static ?self $instance = null;

    /** @var array<string, array{method: string, path: string, name: string, middleware: list<string>, _chain: callable(Request): Response}> */
    private array $routeMap;

    /** @var array<string, class-string<Middleware>> */
    private array $middlewareAliases;

    /** @var array<string, list<string>> */
    private array $middlewareGroups;

    /** @var array<string, Middleware> singleton middleware instances keyed by alias */
    private array $middlewareCache = [];

    private string $baseDir;
    private Authenticator $authenticator;
    private TokenService $tokenService;
    private bool $debug;

    /** @param array<mixed> $rawRoutes */
    private function __construct(array $rawRoutes, string $baseDir)
    {
        $this->routeMap = [];
        $this->baseDir = $baseDir;
        $this->middlewareGroups = [];
        $this->middlewareAliases = [
            'add-powered-by' => AddPoweredByHeader::class,
            'auth-bearer' => AuthenticateBearer::class,
            'auth-session' => AuthenticateSession::class,
            'cors' => Cors::class,
            'ratelimit' => RateLimit::class,
            'require-json' => RequireJsonAccept::class,
            'role' => RequireRole::class,
            'security-headers' => SecurityHeaders::class,
            'start-session' => StartSession::class,
            'verify-csrf' => VerifyCsrf::class,
            // Short aliases
            'powered-by' => AddPoweredByHeader::class,
            'bearer' => AuthenticateBearer::class,
            'auth' => AuthenticateSession::class,
            'json' => RequireJsonAccept::class,
            'session' => StartSession::class,
            'csrf' => VerifyCsrf::class,
            'secure' => SecurityHeaders::class,
        ];
        $this->debug = self::resolveDebugMode();

        if (array_key_exists('middleware_groups', $rawRoutes)) {
            $this->middlewareGroups = $this->normalizeMiddlewareGroups($rawRoutes['middleware_groups']);
            unset($rawRoutes['middleware_groups']);
        }

        // Extract global middleware if specified
        $globalMiddleware = [];
        if (isset($rawRoutes['middleware']) && is_array($rawRoutes['middleware'])) {
            $globalMiddleware = $this->expandMiddlewareList($rawRoutes['middleware']);
            unset($rawRoutes['middleware']);
        }

        $this->parseRoutes($rawRoutes, '', $globalMiddleware);

        $usersFile = $this->baseDir . '/users.php';
        $userDriver = Config::get('FIUM_USER_DRIVER', 'file');

        if ($userDriver === 'pdo') {
            $dsn = Config::string('FIUM_USER_DSN', 'sqlite:' . $this->baseDir . '/storage/users.db');
            $this->authenticator = Authenticator::fromStore(new Auth\PdoUserStore($dsn));
        } else {
            $this->authenticator = Authenticator::fromFile($usersFile);
        }
        $this->tokenService = ApiTokenService::boot($this->authenticator);

        self::$instance = $this;
    }

    public static function boot(string $routesPath): self
    {
        $routes = require $routesPath;

        if (!is_array($routes)) {
            throw new \RuntimeException('Routes file must return an array.');
        }

        $baseDir = dirname(realpath($routesPath) ?: $routesPath);

        Config::loadEnv($baseDir . '/.env');

        return new self($routes, $baseDir);
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /**
     * Generate a URL for a named route with parameter substitution.
     *
        * @param array<string, scalar> $params
     */
    public static function url(string $routeName, array $params = []): string
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application not booted.');
        }

        $route = self::$instance->routeMap[$routeName] ?? null;
        if ($route === null) {
            throw new \RuntimeException("Route '{$routeName}' not found.");
        }

        $path = $route['path'];
        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        foreach ($matches[1] as $parameterName) {
            if (!array_key_exists($parameterName, $params)) {
                throw new \RuntimeException("Missing route parameter '{$parameterName}' for route '{$routeName}'.");
            }

            $path = str_replace(
                '{' . $parameterName . '}',
                rawurlencode((string) $params[$parameterName]),
                $path
            );
        }

        return $path;
    }

    /**
     * Return the boot manifest as a JSON string.
     * Sent to Rust on worker startup so it can build the route table.
     */
    public function bootManifest(): string
    {
        $routes = [];
        foreach ($this->routeMap as $name => $route) {
            $routes[] = [
                'method' => $route['method'],
                'path' => $route['path'],
                'name' => $name,
                'middleware' => $route['middleware'],
            ];
        }

        return json_encode([
            'protocol_version' => 1,
            'type' => 'boot',
            'routes' => $routes,
            // Resolve CORS config here (from .env/Config) so the Rust-side native
            // preflight path uses exactly what the PHP Cors middleware would — not the
            // process env (which would diverge from a .env-configured origin).
            'cors' => [
                'origins' => Config::get('FIUM_CORS_ORIGINS', '*'),
                'methods' => Config::get('FIUM_CORS_METHODS', 'GET, POST, PUT, PATCH, DELETE, OPTIONS'),
                'headers' => Config::get('FIUM_CORS_HEADERS', 'Content-Type, Authorization, Accept, X-Requested-With'),
                'max_age' => Config::get('FIUM_CORS_MAX_AGE', '86400'),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $workerRequest
     * @return array<string, mixed>|Response A plain worker-response array, or a streaming
     *     Response the worker must drive itself (see Response::emitTo).
     */
    public function handleWorkerRequest(array $workerRequest): array|Response
    {
        $requestId = isset($workerRequest['request_id']) ? (string) $workerRequest['request_id'] : null;
        $routeName = isset($workerRequest['matched_route']) ? (string) $workerRequest['matched_route'] : '';

        try {
            if ($routeName === '' || !isset($this->routeMap[$routeName])) {
                return Response::json([
                    'ok' => false,
                    'error' => 'route_not_resolved',
                ], 404, $requestId)->toWorkerResponse($requestId);
            }

            $route = $this->routeMap[$routeName];
            $request = Request::fromWorkerPayload($workerRequest);
            $request->setAttribute('route_name', $routeName);
            $request->setAttribute('authenticator', $this->authenticator);
            $request->setAttribute('token_service', $this->tokenService);
            $request->setAttribute('base_dir', $this->baseDir);

            $chain = $this->routeMap[$routeName]['_chain'];
            $response = $chain($request);

            // A streaming response can't be encoded into one frame — hand it back so the
            // worker drives stream_open/chunk/end itself.
            if ($response->isStreaming()) {
                return $response;
            }

            return $response->toWorkerResponse($requestId);
        } catch (\Throwable $throwable) {
            return Response::fromThrowable($throwable, $requestId, $this->debug)->toWorkerResponse($requestId);
        }
    }

    /**
     * Parse routes in concise format or as named groups.
     *
     * Concise: 'GET /path' => fn(Request $r) => Response::json([...])
     * Concise: 'GET /path' => ['middleware' => [...], 'name' => 'route_name', 'handler' => fn(...) => ...]
     * Concise: 'GET /path' => 'App\\Handlers\\MyHandler'
     * Group:   'name' => ['prefix' => '/api', 'middleware' => [...], 'routes' => [...]]
     *
     * @param array<mixed> $rawRoutes
     * @param string $prefix Path prefix inherited from parent group
     * @param list<string> $middleware Middleware inherited from parent group
     */
    private function parseRoutes(array $rawRoutes, string $prefix = '', array $middleware = []): void
    {
        foreach ($rawRoutes as $key => $value) {
            if (is_string($key) && is_array($value) && isset($value['routes'])) {
                // Group: 'name' => ['prefix' => '/api', 'middleware' => [...], 'routes' => [...]]
                $groupPrefix = $prefix . (string) ($value['prefix'] ?? '');
                $groupMiddleware = $this->expandMiddlewareList(array_merge($middleware, (array) ($value['middleware'] ?? [])));
                $this->parseRoutes($value['routes'], $groupPrefix, $groupMiddleware);
            } elseif (is_string($key)) {
                // Concise format: 'METHOD /path' => handler
                $this->registerConciseRoute($key, $value, $prefix, $middleware);
            } else {
                throw new \RuntimeException("Invalid route definition at key '{$key}'.");
            }
        }
    }

    /**
     * @param list<string> $groupMiddleware
     */
    private function registerConciseRoute(string $key, mixed $value, string $prefix = '', array $groupMiddleware = []): void
    {
        $parts = explode(' ', $key, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException("Invalid concise route key '{$key}'. Expected 'METHOD /path'.");
        }

        [$method, $path] = $parts;
        $method = strtoupper($method);
        $path = $prefix . $path;
        // value can be:
        // 1. Closure or callable  → handler with no middleware
        // 2. string (class name)  → handler with no middleware
        // 3. array with 'handler' + optional 'middleware' and 'name'
        if ($value instanceof \Closure || is_string($value)) {
            $handler = $value;
            $middleware = $groupMiddleware;
            $name = $this->generateRouteName($method, $path);
        } elseif (is_array($value) && isset($value['handler'])) {
            $handler = $value['handler'];
            $middleware = $this->expandMiddlewareList(array_merge($groupMiddleware, (array) ($value['middleware'] ?? [])));
            $name = isset($value['name']) ? trim((string) $value['name']) : $this->generateRouteName($method, $path);
        } else {
            throw new \RuntimeException("Invalid handler for route '{$key}'.");
        }

        if ($name === '') {
            throw new \RuntimeException("Invalid route name for route '{$key}'.");
        }

        $this->registerRoute($method, $path, $name, $middleware, $handler);
    }

    /**
     * @param list<string> $middleware
     */
    private function registerRoute(string $method, string $path, string $name, array $middleware, mixed $handlerDefinition): void
    {
        $this->routeMap[$name] = [
            'method' => $method,
            'path' => $path,
            'name' => $name,
            'middleware' => $middleware,
            '_chain' => $this->compileChain($middleware, $this->resolveHandlerDefinition($handlerDefinition)),
        ];
    }

    private function generateRouteName(string $method, string $path): string
    {
        $slug = trim($path, '/');
        $slug = $slug === '' ? 'index' : $slug;
        $slug = str_replace(['{', '}'], '', $slug);
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', $slug) ?? $slug;
        $slug = trim($slug, '_');

        return strtolower($method) . '_' . $slug;
    }

    private function resolveHandlerDefinition(mixed $handler): callable
    {
        if ($handler instanceof \Closure) {
            return $handler;
        }

        if (is_string($handler) && class_exists($handler)) {
            $instance = new $handler();
            // Handler classes implement __invoke, making the instance callable. Validate
            // the contract so a non-invokable class string fails loudly at boot.
            if (!$instance instanceof Handler) {
                throw new \RuntimeException("Handler class '{$handler}' must implement Fium\\Contracts\\Handler.");
            }
            return $instance;
        }

        throw new \RuntimeException('Cannot resolve route handler.');
    }

    /**
     * @param mixed $groups
     * @return array<string, list<string>>
     */
    private function normalizeMiddlewareGroups(mixed $groups): array
    {
        if (!is_array($groups)) {
            throw new \RuntimeException('middleware_groups must be an array of named middleware lists.');
        }

        $normalized = [];

        foreach ($groups as $name => $aliases) {
            $name = is_string($name) ? trim($name) : '';

            if ($name === '') {
                throw new \RuntimeException('Middleware group names must be non-empty strings.');
            }

            if (str_contains($name, ':')) {
                throw new \RuntimeException("Middleware group '{$name}' cannot contain ':'.");
            }

            if (array_key_exists($name, $this->middlewareAliases)) {
                throw new \RuntimeException("Middleware group '{$name}' conflicts with a built-in middleware alias.");
            }

            if (!is_array($aliases)) {
                throw new \RuntimeException("Middleware group '{$name}' must be a list of middleware aliases.");
            }

            $normalized[$name] = [];

            foreach ($aliases as $alias) {
                if (!is_string($alias)) {
                    throw new \RuntimeException("Middleware group '{$name}' may only contain string aliases.");
                }

                $trimmed = trim($alias);

                if ($trimmed === '') {
                    throw new \RuntimeException("Middleware group '{$name}' may not contain empty aliases.");
                }

                $normalized[$name][] = $trimmed;
            }
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $middleware
     * @param array<mixed> $stack
     * @return list<string>
     */
    private function expandMiddlewareList(array $middleware, array $stack = []): array
    {
        $expanded = [];

        foreach ($middleware as $alias) {
            $alias = trim((string) $alias);

            if ($alias === '') {
                throw new \RuntimeException('Middleware aliases must be non-empty strings.');
            }

            if (isset($this->middlewareGroups[$alias])) {
                if (in_array($alias, $stack, true)) {
                    $chain = implode(' -> ', array_merge($stack, [$alias]));
                    throw new \RuntimeException("Circular middleware group reference detected: {$chain}.");
                }

                $expanded = array_merge(
                    $expanded,
                    $this->expandMiddlewareList($this->middlewareGroups[$alias], array_merge($stack, [$alias]))
                );
                continue;
            }

            $expanded[] = $alias;
        }

        return $expanded;
    }

    private static function resolveDebugMode(): bool
    {
        return Config::bool('FIUM_DEBUG');
    }

    /**
     * Compile a middleware chain once, caching singleton middleware instances.
     * The chain closure bakes in the final handler so it can be called with just a Request.
     *
     * @param list<string> $middlewareAliases
     * @return callable(Request): Response
     */
    private function compileChain(array $middlewareAliases, callable $handler): callable
    {
        if (empty($middlewareAliases)) {
            return $handler;
        }

        // Resolve middleware instances (singletons from cache)
        $stack = [];
        foreach ($middlewareAliases as $alias) {
            $alias = (string) $alias;

            if (isset($this->middlewareCache[$alias])) {
                $stack[] = $this->middlewareCache[$alias];
                continue;
            }

            [$middlewareName, $middlewareArgument] = array_pad(explode(':', $alias, 2), 2, null);
            $middlewareClass = $this->middlewareAliases[$middlewareName] ?? null;

            if ($middlewareClass === null) {
                throw new \RuntimeException("Unknown middleware alias '{$alias}'.");
            }

            $instance = $middlewareArgument === null
                ? new $middlewareClass()
                : new $middlewareClass($middlewareArgument);

            $this->middlewareCache[$alias] = $instance;
            $stack[] = $instance;
        }

        // Build closure chain from inside out (rightmost middleware is deepest).
        $next = $handler;
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $previous = $next;
            $middleware = $stack[$i];
            $next = static fn (Request $request): Response => $middleware->handle($request, $previous);
        }

        return $next;
    }
}