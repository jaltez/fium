<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class AuthenticateBearer implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $tokenService = $request->tokenService();
        $token = $request->bearerToken();

        if ($tokenService === null) {
            return Response::json([
                'ok' => false,
                'error' => 'token_service_unavailable',
            ], 500);
        }

        if (!is_string($token) || $token === '') {
            return Response::json([
                'ok' => false,
                'error' => 'missing_bearer_token',
            ], 401);
        }

        $user = $tokenService->userFromToken($token);

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'invalid_bearer_token',
            ], 401);
        }

        $request->setUser($user);

        return $next($request);
    }
}