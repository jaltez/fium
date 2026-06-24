<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class ApiLoginHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $authenticator = $request->authenticator();
        $tokenService = $request->tokenService();
        $payload = [
            'email' => $request->input('email'),
            'password' => $request->input('password'),
        ];

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

        return Response::json([
            'ok' => true,
            'token_type' => 'Bearer',
            'access_token' => $tokenService->issue($user),
            'expires_in' => $tokenService->ttlSeconds(),
            'refresh_token' => $tokenService->issueRefreshToken($user),
            'refresh_expires_in' => $tokenService->refreshTtlSeconds(),
            'user' => $user->toArray(),
        ]);
    }
}