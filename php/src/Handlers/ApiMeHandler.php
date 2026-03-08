<?php

declare(strict_types=1);

namespace Fium\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class ApiMeHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        return Response::json([
            'ok' => true,
            'user' => null,
            'route' => $request->matchedRoute(),
            'method' => $request->method(),
        ]);
    }
}