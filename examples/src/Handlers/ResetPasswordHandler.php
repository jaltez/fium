<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Auth\PasswordResetTokenService;
use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class ResetPasswordHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $authenticator = $request->authenticator();

        if ($authenticator === null || !$authenticator->canResetPasswords()) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $validator = $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $payload = $validator->validated();
        $tokenService = PasswordResetTokenService::boot($authenticator);
        $user = $tokenService->userFromToken((string) ($payload['token'] ?? ''));

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'invalid_or_expired_reset_token',
            ], 400);
        }

        $updatedUser = $authenticator->updatePassword($user, (string) ($payload['password'] ?? ''));

        if ($updatedUser === null) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        return Response::json([
            'ok' => true,
            'password_reset' => true,
            'user' => $updatedUser->toArray(),
        ]);
    }
}
