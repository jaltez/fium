<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;
use Fium\Session\FileSessionStore;

final class StartSession implements Middleware
{
    private const COOKIE_NAME = 'fium_session';

    public function handle(Request $request, callable $next): Response
    {
        $store = new FileSessionStore(dirname(__DIR__, 2) . '/storage/sessions');
        $session = $store->load($request->cookie(self::COOKIE_NAME));
        $request->setSession($session);

        $response = $next($request);

        if ($session->isDirty() || $session->isNew()) {
            $store->save($session);
        }

        return $response->withCookie(self::COOKIE_NAME, $session->id(), '/');
    }
}