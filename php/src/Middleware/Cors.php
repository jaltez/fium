<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Config;
use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class Cors implements Middleware
{
    private string $allowedOrigins;
    private string $allowedMethods;
    private string $allowedHeaders;
    private string $maxAge;

    public function __construct()
    {
        $this->allowedOrigins = Config::string('FIUM_CORS_ORIGINS', '*');
        $this->allowedMethods = Config::string('FIUM_CORS_METHODS', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $this->allowedHeaders = Config::string('FIUM_CORS_HEADERS', 'Content-Type, Authorization, Accept, X-Requested-With');
        $this->maxAge = Config::string('FIUM_CORS_MAX_AGE', '86400');
    }

    public function handle(Request $request, callable $next): Response
    {
        // Handle preflight
        if ($request->method() === 'OPTIONS') {
            return Response::empty(204)
                ->withHeader('Access-Control-Allow-Origin', $this->allowedOrigins)
                ->withHeader('Access-Control-Allow-Methods', $this->allowedMethods)
                ->withHeader('Access-Control-Allow-Headers', $this->allowedHeaders)
                ->withHeader('Access-Control-Max-Age', $this->maxAge);
        }

        $response = $next($request);

        return $response
            ->withHeader('Access-Control-Allow-Origin', $this->allowedOrigins)
            ->withHeader('Access-Control-Allow-Methods', $this->allowedMethods)
            ->withHeader('Access-Control-Allow-Headers', $this->allowedHeaders);
    }
}
