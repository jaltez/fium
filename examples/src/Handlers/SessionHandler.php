<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class SessionHandler implements Handler
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

        $visits = (int) $session->get('visits', 0) + 1;
        $session->put('visits', $visits);

        return Response::json([
            'ok' => true,
            'session_id' => $session->id(),
            'visits' => $visits,
        ]);
    }
}