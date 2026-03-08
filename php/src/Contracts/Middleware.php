<?php

declare(strict_types=1);

namespace Fium\Contracts;

use Fium\Runtime\Request;
use Fium\Runtime\Response;

interface Middleware
{
    /** @param callable(Request): Response $next */
    public function handle(Request $request, callable $next): Response;
}