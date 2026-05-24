<?php

declare(strict_types=1);

namespace Fium\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class CsrfTokenHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $token = $request->csrfToken();

        if (!is_string($token) || $token === '') {
            return Response::json([
                'ok' => false,
                'error' => 'session_unavailable',
            ], 500);
        }

        return Response::json([
            'ok' => true,
            'csrf_token' => $token,
        ])->withHeader('x-csrf-token', $token);
    }
}