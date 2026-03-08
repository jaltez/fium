<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class VerifyCsrf implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $session = $request->session();

        if ($session === null) {
            return Response::json([
                'ok' => false,
                'error' => 'csrf_session_unavailable',
            ], 500);
        }

        $sessionToken = $session->get('csrf_token');
        $providedToken = $request->header('x-csrf-token');

        if (!is_string($providedToken) || $providedToken === '') {
            $bodyToken = $request->input('_token');
            $providedToken = is_string($bodyToken) ? $bodyToken : null;
        }

        if (!is_string($sessionToken) || !is_string($providedToken) || !hash_equals($sessionToken, $providedToken)) {
            return Response::json([
                'ok' => false,
                'error' => 'csrf_token_mismatch',
            ], 419);
        }

        return $next($request);
    }
}