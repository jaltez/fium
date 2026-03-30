<?php

declare(strict_types=1);

namespace Fium\Auth;

use Fium\Session\Session;

final class Authenticator
{
    private function __construct(private UserStore $store)
    {
    }

    public static function fromFile(string $usersPath): self
    {
        return new self(new FileUserStore($usersPath));
    }

    public static function fromStore(UserStore $store): self
    {
        return new self($store);
    }

    public static function empty(): self
    {
        return new self(new NullUserStore());
    }

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(array $credentials): ?User
    {
        $email = isset($credentials['email']) ? (string) $credentials['email'] : '';
        $password = isset($credentials['password']) ? (string) $credentials['password'] : '';

        if ($email === '' || $password === '') {
            return null;
        }

        $record = $this->store->findByEmail($email);

        if ($record === null) {
            return null;
        }

        $passwordHash = (string) ($record['password_hash'] ?? '');

        if (!password_verify($password, $passwordHash)) {
            return null;
        }

        return $this->hydrateUser($record);
    }

    /** @param array<string, mixed> $credentials */
    public function attempt(array $credentials, Session $session): ?User
    {
        $user = $this->validateCredentials($credentials);

        if ($user === null) {
            return null;
        }

        $session->put('auth_user_id', $user->id());

        return $user;
    }

    public function userById(int $userId): ?User
    {
        $record = $this->store->findById($userId);

        return $record !== null ? $this->hydrateUser($record) : null;
    }

    public function userFromSession(Session $session): ?User
    {
        $userId = $session->get('auth_user_id');

        if (!is_int($userId) && !is_string($userId)) {
            return null;
        }

        return $this->userById((int) $userId);
    }

    public function logout(Session $session): void
    {
        $session->forget('auth_user_id');
    }

    /** @param array<string, mixed> $record */
    private function hydrateUser(array $record): User
    {
        return new User(
            (int) ($record['id'] ?? 0),
            (string) ($record['email'] ?? ''),
            (string) ($record['role'] ?? 'user'),
        );
    }
}