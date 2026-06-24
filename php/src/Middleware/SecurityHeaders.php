<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

/**
 * Adds standard security headers to every response.
 */
final class SecurityHeaders implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        // When the Rust runtime stamps these headers natively (FIUM_NATIVE_SECURITY_HEADERS),
        // defer — Rust applies them to the response without this PHP middleware running.
        if (\Fium\Config::bool('FIUM_NATIVE_SECURITY_HEADERS')) {
            return $next($request);
        }

        return $next($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-XSS-Protection', '0')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }
}
