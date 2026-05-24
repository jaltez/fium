<?php

declare(strict_types=1);

namespace Fium\Auth;

interface MutableUserStore extends UserStore
{
    /** @return array<string, mixed>|null */
    public function create(string $email, string $passwordHash, string $role = 'user'): ?array;

    /** @return array<string, mixed>|null */
    public function updatePassword(int $userId, string $passwordHash): ?array;

    /** @return array<string, mixed>|null */
    public function markEmailVerified(int $userId): ?array;

    /** @return array<string, mixed>|null */
    public function incrementTokenVersion(int $userId): ?array;
}
