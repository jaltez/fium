<?php

declare(strict_types=1);

namespace Fium\Contracts;

use Fium\Runtime\Request;
use Fium\Runtime\Response;

interface Handler
{
    public function __invoke(Request $request): Response;
}