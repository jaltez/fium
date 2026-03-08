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
        $session = $request->session();

        if ($session === null) {
            return Response::json([
                'ok' => false,
                'error' => 'session_unavailable',
            ], 500);
        }

        $token = $session->get('csrf_token');

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(20));
            $session->put('csrf_token', $token);
        }

        return Response::json([
            'ok' => true,
            'csrf_token' => $token,
        ])->withHeader('x-csrf-token', $token);
    }
}