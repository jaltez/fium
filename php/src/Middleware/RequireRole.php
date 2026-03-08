<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class RequireRole implements Middleware
{
    public function __construct(private string $role)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'unauthenticated',
            ], 401);
        }

        if (!$user->hasRole($this->role)) {
            return Response::json([
                'ok' => false,
                'error' => 'forbidden',
                'required_role' => $this->role,
            ], 403);
        }

        return $next($request);
    }
}