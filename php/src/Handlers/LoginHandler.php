<?php

declare(strict_types=1);

namespace Fium\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class LoginHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $session = $request->session();
        $authenticator = $request->authenticator();
        $payload = $request->json() ?? [];

        if ($session === null || $authenticator === null) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $user = $authenticator->attempt($payload, $session);

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'invalid_credentials',
            ], 401);
        }

        return Response::json([
            'ok' => true,
            'user' => $user->toArray(),
        ]);
    }
}