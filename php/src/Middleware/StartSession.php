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

    private SessionStore $store;

    public function __construct()
    {
        // Resolve the store once at construction time.
        // Since StartSession is cached in Application::$middlewareCache,
        // this runs only once per worker's lifetime.
        $driver = Config::get('FIUM_SESSION_DRIVER', 'file');

        if ($driver === 'pdo') {
            $dsn = Config::get('FIUM_SESSION_DSN', 'sqlite:' . dirname(__DIR__, 2) . '/storage/sessions.db');
            $this->store = new PdoSessionStore($dsn);
        } else {
            $this->store = new FileSessionStore(dirname(__DIR__, 2) . '/storage/sessions');
        }
    }

    public function handle(Request $request, callable $next): Response
    {
        $session = $this->store->load($request->cookie(self::COOKIE_NAME));
        $request->setSession($session);

        $response = $next($request);

        if ($session->isDirty() || $session->isNew()) {
            $this->store->save($session);
        }

        return $response->withCookie(self::COOKIE_NAME, $session->id(), '/');
    }
}