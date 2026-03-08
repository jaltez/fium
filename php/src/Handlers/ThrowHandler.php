<?php

declare(strict_types=1);

namespace Fium\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class ThrowHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        throw new \RuntimeException('Intentional test exception from ThrowHandler.');
    }
}