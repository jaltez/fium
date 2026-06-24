<?php

declare(strict_types=1);

use Fium\Runtime\Request;
use Fium\Runtime\Response;

// This is a standalone example app, separate from the embedded framework. Its own handler
// classes live under App\ (examples/src/); register an autoloader for them. The runtime
// already autoloads Fium\ -> the embedded framework source, so both resolve.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

return [
    // Global middleware applied to all routes
    'middleware' => ['add-powered-by'],

    'GET /' => [
        'middleware' => ['start-session'],
        'handler' => 'App\\Handlers\\HomeHandler',
    ],

    'GET /hello/{name}' => 'App\\Handlers\\HelloHandler',

    'GET /csrf-token' => [
        'middleware' => ['start-session'],
        'handler' => 'App\\Handlers\\CsrfTokenHandler',
    ],

    'POST /login' => [
        'middleware' => ['start-session', 'verify-csrf'],
        'handler' => 'App\\Handlers\\LoginHandler',
    ],

    'POST /register' => [
        'middleware' => ['start-session', 'verify-csrf'],
        'handler' => 'App\\Handlers\\RegisterHandler',
    ],

    'POST /logout' => [
        'middleware' => ['start-session', 'verify-csrf'],
        'handler' => 'App\\Handlers\\LogoutHandler',
    ],

    'GET /me' => [
        'middleware' => ['start-session', 'auth-session'],
        'handler' => 'App\\Handlers\\CurrentUserHandler',
    ],

    'GET /email/verify' => 'App\\Handlers\\VerifyEmailHandler',

    'GET /admin' => [
        'middleware' => ['start-session', 'auth-session', 'role:admin'],
        'handler' => 'App\\Handlers\\AdminDashboardHandler',
    ],

    // Route group: API routes share prefix and middleware
    'api' => [
        'prefix' => '/api',
        'middleware' => ['require-json'],
        'routes' => [
            'POST /forgot-password' => 'App\\Handlers\\PasswordResetLinkHandler',
            'POST /reset-password' => 'App\\Handlers\\ResetPasswordHandler',
            'POST /register' => 'App\\Handlers\\ApiRegisterHandler',
            'POST /login' => 'App\\Handlers\\ApiLoginHandler',
            'POST /refresh' => 'App\\Handlers\\ApiRefreshTokenHandler',
            'POST /logout' => [
                'middleware' => ['auth-bearer'],
                'handler' => 'App\\Handlers\\ApiLogoutHandler',
            ],
            'POST /email/verification-link' => [
                'middleware' => ['auth-bearer'],
                'handler' => 'App\\Handlers\\EmailVerificationLinkHandler',
            ],
            'GET /me' => [
                'middleware' => ['auth-bearer'],
                'handler' => 'App\\Handlers\\ApiMeHandler',
            ],
        ],
    ],

    'GET /session' => [
        'middleware' => ['start-session'],
        'handler' => 'App\\Handlers\\SessionHandler',
    ],

    'GET /boom' => 'App\\Handlers\\ThrowHandler',

    'GET /_runtime/crash-once' => 'App\\Handlers\\CrashOnceHandler',

    'GET /_runtime/slow-once' => 'App\\Handlers\\SlowOnceHandler',

    'GET /_runtime/always-slow' => 'App\\Handlers\\AlwaysSlowHandler',

    // Example: closure-based inline handler
    'GET /ping' => fn(Request $r) => Response::json(['pong' => true]),

    // Cache demo: memoizes a value across requests within the worker process.
    'GET /cache-demo' => function (Request $r): Response {
        $cache = \Fium\Cache\CacheManager::driver();
        $value = $cache->remember('cache-demo-key', function (): array {
            return ['computed_at' => gmdate('c'), 'payload' => bin2hex(random_bytes(4))];
        }, 60);

        return Response::json(['cached' => $value]);
    },

    // Database demo: counts users when a DB is configured, degrades gracefully otherwise.
    'GET /db-demo' => function (Request $r): Response {
        try {
            $count = \Fium\Database\Connection::table('users')->count();

            return Response::json(['configured' => true, 'user_count' => $count]);
        } catch (\Throwable $e) {
            return Response::json([
                'configured' => false,
                'hint' => 'Set FIUM_DB_DSN to enable the database layer.',
            ]);
        }
    },

    // HTML response example
    'GET /welcome' => fn(Request $r) => Response::html('<h1>Welcome to Fium</h1><p>Fast PHP runtime.</p>'),

    // Redirect example
    'GET /old-page' => fn(Request $r) => Response::redirect('/welcome'),

];
