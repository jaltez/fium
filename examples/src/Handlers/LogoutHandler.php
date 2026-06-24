<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class LogoutHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $session = $request->session();
        $authenticator = $request->authenticator();

        if ($session === null || $authenticator === null) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $authenticator->logout($session);

        return Response::json([
            'ok' => true,
            'logged_out' => true,
        ]);
    }
}