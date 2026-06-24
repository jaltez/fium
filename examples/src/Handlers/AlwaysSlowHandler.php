<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class AlwaysSlowHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        usleep(1500000);

        return Response::json([
            'ok' => true,
            'route' => (string) $request->matchedRoute(),
        ]);
    }
}