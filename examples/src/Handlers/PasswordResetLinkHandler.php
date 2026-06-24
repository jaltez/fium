<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Auth\PasswordResetTokenService;
use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class PasswordResetLinkHandler implements Handler
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
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $user = $authenticator->userByEmail((string) ($validator->validated()['email'] ?? ''));
        $tokenService = PasswordResetTokenService::boot($authenticator);

        return Response::json([
            'ok' => true,
            'reset_token' => $user?->id() !== null ? $tokenService->issue($user) : null,
            'expires_in' => $tokenService->ttlSeconds(),
        ]);
    }
}
