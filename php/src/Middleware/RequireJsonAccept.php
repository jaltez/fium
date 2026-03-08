<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class RequireJsonAccept implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $accept = $request->header('accept');

        if ($accept !== null && !str_contains($accept, 'application/json') && !str_contains($accept, '*/*')) {
            return Response::json([
                'ok' => false,
                'error' => 'not_acceptable',
            ], 406);
        }

        return $next($request);
    }
}