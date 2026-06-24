<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Auth\EmailVerificationTokenService;
use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class EmailVerificationLinkHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $authenticator = $request->authenticator();

        if ($user === null || $authenticator === null || !$authenticator->canVerifyEmail()) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $tokenService = EmailVerificationTokenService::boot($authenticator);
        $token = $tokenService->issue($user);
        $path = '/email/verify?token=' . rawurlencode($token);
        $verificationUrl = $request->scheme() . '://' . $request->host() . $path;

        return Response::json([
            'ok' => true,
            'verification_url' => $verificationUrl,
            'token' => $token,
            'expires_in' => $tokenService->ttlSeconds(),
        ]);
    }
}
