<?php

declare(strict_types=1);

namespace Fium\Handlers;

use Fium\Contracts\Handler;
use Fium\Runtime\Request;
use Fium\Runtime\Response;

final class RegisterHandler implements Handler
{
    public function __invoke(Request $request): Response
    {
        $session = $request->session();
        $authenticator = $request->authenticator();

        if ($session === null || $authenticator === null || !$authenticator->canRegister()) {
            return Response::json([
                'ok' => false,
                'error' => 'auth_unavailable',
            ], 500);
        }

        $validator = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $user = $authenticator->register($validator->validated(), $session, $request->boolean('remember'));

        if ($user === null) {
            return Response::json([
                'ok' => false,
                'error' => 'email_already_taken',
            ], 409);
        }

        return Response::json([
            'ok' => true,
            'user' => $user->toArray(),
        ], 201);
    }
}
