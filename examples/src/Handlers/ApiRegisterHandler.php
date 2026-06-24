<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class ApiRegisterHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $authenticator = $request->authenticator();
        $tokenService = $request->tokenService();

        if ($authenticator === null || $tokenService === null || !$authenticator->canRegister()) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $validator = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $user = $authenticator->register($validator->validated());

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'email_already_taken',
            ], 409);
        }

        return Response::json([
            'ok' => true,
            'token_type' => 'Bearer',
            'access_token' => $tokenService->issue($user),
            'expires_in' => $tokenService->ttlSeconds(),
            'refresh_token' => $tokenService->issueRefreshToken($user),
            'refresh_expires_in' => $tokenService->refreshTtlSeconds(),
            'user' => $user->toArray(),
        ], 201);
    }
}
