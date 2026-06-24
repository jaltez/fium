<?php

declare(strict_types=1);

namespace App\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class HelloHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $name = $request->routeParam('name', 'world');

        return Response::json([
            'ok' => true,
            'route' => $request->matchedRoute(),
            'greeting' => sprintf('Hello, %s!', $name),
            'params' => [
                'name' => $name,
            ],
        ]);
    }
}