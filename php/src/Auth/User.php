<?php

declare(strict_types=1);

namespace Fium\Auth;

final class User
{
    public function __construct(
        private int $id,
        private string $email,
        private string $role,
        private ?string $emailVerifiedAt = null,
        private int $tokenVersion = 0,
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

    public function emailVerifiedAt(): ?string
    {
        return $this->emailVerifiedAt;
    }

    public function hasVerifiedEmail(): bool
    {
        return is_string($this->emailVerifiedAt) && $this->emailVerifiedAt !== '';
    }

    public function tokenVersion(): int
    {
        return $this->tokenVersion;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'email_verified' => $this->hasVerifiedEmail(),
            'email_verified_at' => $this->emailVerifiedAt,
        ];
    }
}