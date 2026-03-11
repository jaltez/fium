<?php

declare(strict_types=1);

namespace Fium\Auth;

final class NullUserStore implements UserStore
{
    public function findById(int $id): ?array
    {
        return null;
    }

    public function findByEmail(string $email): ?array
    {
        return null;
    }
}
