<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class AuthenticateSession implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $session = $request->session();
        $authenticator = $request->authenticator();

        if ($session === null || $authenticator === null) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $user = $authenticator->userFromSession($session);

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'unauthenticated',
            ], 401);
        }

        $request->setUser($user);

        return $next($request);
    }
}