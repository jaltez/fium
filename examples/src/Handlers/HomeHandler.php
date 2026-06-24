<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class HomeHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        return Response::json([
            'ok' => true,
            'message' => 'Welcome to Fium.',
            'route' => $request->attribute('route_name', $request->matchedRoute()),
            'path' => $request->path(),
        ]);
    }
}