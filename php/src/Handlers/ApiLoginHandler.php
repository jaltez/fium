<?php

declare(strict_types=1);

namespace Fium\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class ApiLoginHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $authenticator = $request->authenticator();
        $tokenService = $request->tokenService();
        $payload = $request->json() ?? [];

        if ($authenticator === null || $tokenService === null) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $user = $authenticator->validateCredentials($payload);

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'invalid_credentials',
            ], 401);
        }

        $token = $tokenService->issue($user);

        return Response::json([
            'ok' => true,
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_in' => $tokenService->ttlSeconds(),
            'user' => $user->toArray(),
        ]);
    }
}