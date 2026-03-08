<?php

declare(strict_types=1);

namespace Fium;

use Fium\Auth\Authenticator;
use Fium\Contracts\Handler;
use Fium\Contracts\Middleware;
use Fium\Middleware\AddPoweredByHeader;
use Fium\Middleware\AuthenticateSession;
use Fium\Middleware\RequireRole;
use Fium\Middleware\RequireJsonAccept;
use Fium\Middleware\StartSession;
use Fium\Middleware\VerifyCsrf;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class Application
{
    /** @var array<string, class-string<Handler>> */
    private array $handlers;

    /** @var array<string, array<string, mixed>> */
    private array $routeMap;

    /** @var array<string, class-string<Middleware>> */
    private array $middlewareAliases;

    private Authenticator $authenticator;
    private bool $debug;

    /** @param array<int, array<string, mixed>> $routes */
    private function __construct(private array $routes)
    {
        $this->handlers = [];
        $this->routeMap = [];
        $this->middlewareAliases = [
            'add-powered-by' => AddPoweredByHeader::class,
            'auth-session' => AuthenticateSession::class,
            'require-json' => RequireJsonAccept::class,
            'role' => RequireRole::class,
            'start-session' => StartSession::class,
            'verify-csrf' => VerifyCsrf::class,
        ];
        $this->authenticator = Authenticator::fromFile(dirname(__DIR__) . '/users.php');
        $this->debug = self::resolveDebugMode();

        foreach ($routes as $route) {
            $name = (string) $route['name'];
            $handler = (string) $route['handler'];
            $this->handlers[$name] = $handler;
            $this->routeMap[$name] = $route;
        }
    }

    public static function boot(string $routesPath): self
    {
        $routes = require $routesPath;

        if (!is_array($routes)) {
            throw new \RuntimeException('Routes bootstrap file must return an array.');
        }

        return new self($routes);
    }

    /** @param array<string, mixed> $workerRequest */
    public function handleWorkerRequest(array $workerRequest): array
    {
        $requestId = isset($workerRequest['request_id']) ? (string) $workerRequest['request_id'] : null;
        $routeName = isset($workerRequest['matched_route']) ? (string) $workerRequest['matched_route'] : '';

        try {
            if ($routeName === '' || !isset($this->handlers[$routeName])) {
                return Response::json([
                    'ok' => false,
                    'error' => 'route_not_resolved',
                ], 404, $requestId)->toWorkerResponse();
            }

            $route = $this->routeMap[$routeName];
            $handlerClass = $this->handlers[$routeName];
            $handler = new $handlerClass();
            $request = Request::fromWorkerPayload($workerRequest);
            $request->setAttribute('route_name', $routeName);
            $request->setAttribute('authenticator', $this->authenticator);

            $response = $this->dispatchThroughMiddleware(
                $request,
                $handler,
                $this->resolveMiddleware($route)
            );

            return $response->toWorkerResponse($requestId);
        } catch (\Throwable $throwable) {
            return Response::fromThrowable($throwable, $requestId, $this->debug)->toWorkerResponse($requestId);
        }
    }

    private static function resolveDebugMode(): bool
    {
        $value = getenv('FIUM_DEBUG');

        if ($value === false) {
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $route
     * @return list<Middleware>
     */
    private function resolveMiddleware(array $route): array
    {
        $resolved = [];

        foreach (($route['middleware'] ?? []) as $alias) {
            $alias = (string) $alias;
            [$middlewareName, $middlewareArgument] = array_pad(explode(':', $alias, 2), 2, null);
            $middlewareClass = $this->middlewareAliases[$middlewareName] ?? null;

            if ($middlewareClass === null) {
                throw new \RuntimeException("Unknown middleware alias '{$alias}'.");
            }

            $resolved[] = $middlewareArgument === null
                ? new $middlewareClass()
                : new $middlewareClass($middlewareArgument);
        }

        return $resolved;
    }

    /**
     * @param list<Middleware> $middlewareStack
     */
    private function dispatchThroughMiddleware(Request $request, Handler $handler, array $middlewareStack): Response
    {
        $next = static fn (Request $request): Response => $handler($request);

        foreach (array_reverse($middlewareStack) as $middleware) {
            $previousNext = $next;
            $next = static fn (Request $request): Response => $middleware->handle($request, $previousNext);
        }

        return $next($request);
    }
}