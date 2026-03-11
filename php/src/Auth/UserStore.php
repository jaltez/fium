<?php

declare(strict_types=1);

namespace Fium\Auth;

interface UserStore
{
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array;
}
