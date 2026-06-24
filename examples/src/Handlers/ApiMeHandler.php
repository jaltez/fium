<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class ApiMeHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Response::json([
            'ok' => true,
            'user' => $user?->toArray(),
            'route' => $request->matchedRoute(),
            'method' => $request->method(),
        ]);
    }
}