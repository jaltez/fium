<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class ApiRefreshTokenHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $tokenService = $request->tokenService();

        if ($tokenService === null) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $validator = $request->validate([
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $payload = $validator->validated();
        $user = $tokenService->userFromRefreshToken((string) ($payload['refresh_token'] ?? ''));

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'invalid_refresh_token',
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
