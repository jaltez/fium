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
    private const REMEMBER_ME_MAX_AGE = 2_592_000;

    private SessionStore $store;

    public function __construct()
    {
        // Resolve the store once at construction time.
        // Since StartSession is cached in Application::$middlewareCache,
        // this runs only once per worker's lifetime.
        $driver = Config::get('FIUM_SESSION_DRIVER', 'file');

        if ($driver === 'pdo') {
            $dsn = Config::string('FIUM_SESSION_DSN', 'sqlite:' . dirname(__DIR__, 2) . '/storage/sessions.db');
            $this->store = new PdoSessionStore($dsn);
        } else {
            $this->store = new FileSessionStore(dirname(__DIR__, 2) . '/storage/sessions');
        }
    }

    public function handle(Request $request, callable $next): Response
    {
        $incomingSessionId = $request->cookie(self::COOKIE_NAME);
        $session = $this->store->load($incomingSessionId);
        $rememberedBefore = $session->remembers();
        $session->ageFlashData();
        $request->setSession($session);

        $response = $next($request);

        if ($session->needsRegeneration()) {
            $this->store->regenerate($session);
        }

        // Persist and resend the cookie only when request handling changed session state.
        if ($session->isDirty()) {
            $previousId = $session->previousId();
            $this->store->save($session);
            $shouldSetCookie = $incomingSessionId !== $session->id()
                || $rememberedBefore !== $session->remembers();

            if ($previousId !== null) {
                $this->store->delete($previousId);
                $session->clearPreviousId();
                $shouldSetCookie = true;
            }

            if (!$shouldSetCookie) {
                return $response;
            }

            return $response->withCookie(
                self::COOKIE_NAME,
                $session->id(),
                '/',
                true,
                false,
                'Lax',
                $session->remembers() ? self::REMEMBER_ME_MAX_AGE : null,
            );
        }

        return $response;
    }
}