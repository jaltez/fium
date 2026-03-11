<?php

declare(strict_types=1);

namespace Fium\Middleware;

use Fium\Config;
use Fium\Contracts\Middleware;
use Fium\Runtime\Request;
use Fium\Runtime\Response;
use Fium\Session\FileSessionStore;
use Fium\Session\PdoSessionStore;
use Fium\Session\SessionStore;

final class StartSession implements Middleware
{
    private const COOKIE_NAME = 'fium_session';

    public function handle(Request $request, callable $next): Response
    {
        $baseDir = $request->attribute('base_dir') ?? dirname(__DIR__, 2);
        $store = $this->resolveStore($baseDir);
        $session = $store->load($request->cookie(self::COOKIE_NAME));
        $request->setSession($session);

        $response = $next($request);

        if ($session->isDirty() || $session->isNew()) {
            $store->save($session);
        }

        return $response->withCookie(self::COOKIE_NAME, $session->id(), '/');
    }

    private function resolveStore(string $baseDir): SessionStore
    {
        $driver = Config::get('FIUM_SESSION_DRIVER', 'file');

        if ($driver === 'pdo') {
            $dsn = Config::get('FIUM_SESSION_DSN', 'sqlite:' . $baseDir . '/storage/sessions.db');
            return new PdoSessionStore($dsn);
        }

        return new FileSessionStore($baseDir . '/storage/sessions');
    }
}