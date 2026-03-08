<?php

declare(strict_types=1);

return [
    [
        'method' => 'GET',
        'path' => '/csrf-token',
        'name' => 'csrf.token',
        'handler' => 'Fium\\Handlers\\CsrfTokenHandler',
        'middleware' => ['start-session', 'add-powered-by'],
    ],
    [
        'method' => 'POST',
        'path' => '/login',
        'name' => 'auth.login',
        'handler' => 'Fium\\Handlers\\LoginHandler',
        'middleware' => ['start-session', 'verify-csrf', 'add-powered-by'],
    ],
    [
        'method' => 'POST',
        'path' => '/logout',
        'name' => 'auth.logout',
        'handler' => 'Fium\\Handlers\\LogoutHandler',
        'middleware' => ['start-session', 'verify-csrf', 'add-powered-by'],
    ],
    [
        'method' => 'GET',
        'path' => '/me',
        'name' => 'auth.me',
        'handler' => 'Fium\\Handlers\\CurrentUserHandler',
        'middleware' => ['start-session', 'auth-session', 'add-powered-by'],
    ],
    [
        'method' => 'GET',
        'path' => '/admin',
        'name' => 'auth.admin',
        'handler' => 'Fium\\Handlers\\AdminDashboardHandler',
        'middleware' => ['start-session', 'auth-session', 'role:admin', 'add-powered-by'],
    ],
    [
        'method' => 'GET',
        'path' => '/',
        'name' => 'home',
        'handler' => 'Fium\\Handlers\\HomeHandler',
        'middleware' => ['start-session', 'add-powered-by'],
    ],
    [
        'method' => 'GET',
        'path' => '/api/me',
        'name' => 'api.me',
        'handler' => 'Fium\\Handlers\\ApiMeHandler',
        'middleware' => ['require-json', 'add-powered-by'],
    ],
    [
        'method' => 'GET',
        'path' => '/boom',
        'name' => 'boom',
        'handler' => 'Fium\\Handlers\\ThrowHandler',
        'middleware' => ['add-powered-by'],
    ],
    [
        'method' => 'GET',
        'path' => '/session',
        'name' => 'session.show',
        'handler' => 'Fium\\Handlers\\SessionHandler',
        'middleware' => ['start-session', 'add-powered-by'],
    ],
];