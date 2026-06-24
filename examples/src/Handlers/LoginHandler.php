<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class LoginHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $session = $request->session();
        $authenticator = $request->authenticator();
        $payload = [
            'email' => $request->input('email'),
            'password' => $request->input('password'),
        ];

        if ($session === null || $authenticator === null) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $user = $authenticator->attempt($payload, $session, $request->boolean('remember'));

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