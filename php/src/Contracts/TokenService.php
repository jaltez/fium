<?php

declare(strict_types=1);

namespace Fium\Contracts;

use Fium\Auth\User;

interface TokenService
{
    public function issue(User $user): string;

    public function userFromToken(string $token): ?User;

    public function ttlSeconds(): int;
}
