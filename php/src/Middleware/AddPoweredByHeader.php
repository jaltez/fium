<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class AddPoweredByHeader implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request)->withHeader('x-fium-middleware', 'add-powered-by');
    }
}