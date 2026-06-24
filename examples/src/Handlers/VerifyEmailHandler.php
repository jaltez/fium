<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Auth\EmailVerificationTokenService;
use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class VerifyEmailHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $authenticator = $request->authenticator();

        if ($authenticator === null || !$authenticator->canVerifyEmail()) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $token = $request->query('token');

        if (!is_string($token) || $token === '') {
            return Response::json([
                'ok' => false,
                'error' => 'missing_verification_token',
            ], 400);
        }

        $tokenService = EmailVerificationTokenService::boot($authenticator);
        $user = $tokenService->userFromToken($token);

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'invalid_or_expired_verification_token',
            ], 400);
        }

        $verifiedUser = $authenticator->markEmailVerified($user);

        if ($verifiedUser === null) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        return Response::json([
            'ok' => true,
            'verified' => true,
            'user' => $verifiedUser->toArray(),
        ]);
    }
}
