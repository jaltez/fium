<?php

declare(strict_types=1);

namespace Fium\Auth;

final class User
{
    public function __construct(
        private int $id,
        private string $email,
        private string $role,
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
        ];
    }
}