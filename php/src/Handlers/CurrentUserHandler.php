<?php

declare(strict_types=1);

namespace Fium\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class CurrentUserHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'unauthenticated',
            ], 401);
        }

        return Response::json([
            'ok' => true,
            'user' => $user->toArray(),
        ]);
    }
}