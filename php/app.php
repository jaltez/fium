<?php

declare(strict_types=1);

use Fium\Runtime\Request;
use Fium\Runtime\Response;

return [
    // Global middleware applied to all routes
    'middleware' => ['add-powered-by'],

    'GET /' => [
        'middleware' => ['start-session'],
        'handler' => 'Fium\\Handlers\\HomeHandler',
    ],

    'GET /hello/{name}' => 'Fium\\Handlers\\HelloHandler',

    'GET /csrf-token' => [
        'middleware' => ['start-session'],
        'handler' => 'Fium\\Handlers\\CsrfTokenHandler',
    ],

    'POST /login' => [
        'middleware' => ['start-session', 'verify-csrf'],
        'handler' => 'Fium\\Handlers\\LoginHandler',
    ],

    'POST /logout' => [
        'middleware' => ['start-session', 'verify-csrf'],
        'handler' => 'Fium\\Handlers\\LogoutHandler',
    ],

    'GET /me' => [
        'middleware' => ['start-session', 'auth-session'],
        'handler' => 'Fium\\Handlers\\CurrentUserHandler',
    ],

    'GET /admin' => [
        'middleware' => ['start-session', 'auth-session', 'role:admin'],
        'handler' => 'Fium\\Handlers\\AdminDashboardHandler',
    ],

    // Route group: API routes share prefix and middleware
    'api' => [
        'prefix' => '/api',
        'middleware' => ['require-json'],
        'routes' => [
            'POST /login' => 'Fium\\Handlers\\ApiLoginHandler',
            'GET /me' => [
                'middleware' => ['auth-bearer'],
                'handler' => 'Fium\\Handlers\\ApiMeHandler',
            ],
        ],
    ],

    'GET /session' => [
        'middleware' => ['start-session'],
        'handler' => 'Fium\\Handlers\\SessionHandler',
    ],

    'GET /boom' => 'Fium\\Handlers\\ThrowHandler',

    'GET /_runtime/crash-once' => 'Fium\\Handlers\\CrashOnceHandler',

    'GET /_runtime/slow-once' => 'Fium\\Handlers\\SlowOnceHandler',

    'GET /_runtime/always-slow' => 'Fium\\Handlers\\AlwaysSlowHandler',

    // Example: closure-based inline handler
    'GET /ping' => fn(Request $r) => Response::json(['pong' => true]),

    // HTML response example
    'GET /welcome' => fn(Request $r) => Response::html('<h1>Welcome to Fium</h1><p>Fast PHP runtime.</p>'),

    // Redirect example
    'GET /old-page' => fn(Request $r) => Response::redirect('/welcome'),

    // Validation example
    'POST /register' => fn(Request $r) => (function() use ($r) {
        $v = $r->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
            'name' => 'required|string|min:2|max:100',
        ]);
        if ($v->fails()) {
            return Response::validationError($v->errors());
        }
        return Response::json(['ok' => true, 'data' => $v->validated()]);
    })(),
];
