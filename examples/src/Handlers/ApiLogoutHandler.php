<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class ApiLogoutHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $authenticator = $request->authenticator();
        $user = $request->user();

        if ($authenticator === null || $user === null || !$authenticator->canRevokeTokens()) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $revokedUser = $authenticator->revokeTokens($user);

        if ($revokedUser === null) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        return Response::json([
            'ok' => true,
            'revoked' => true,
        ]);
    }
}
