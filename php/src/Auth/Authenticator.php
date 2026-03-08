<?php

declare(strict_types=1);

namespace Fium\Auth;

use Fium\Session\Session;

final class Authenticator
{
    /** @param array<int, array<string, mixed>> $users */
    private function __construct(private array $users)
    {
    }

    public static function fromFile(string $usersPath): self
    {
        $users = require $usersPath;

        if (!is_array($users)) {
            throw new \RuntimeException('Users file must return an array.');
        }

        return new self($users);
    }

    /** @param array<string, mixed> $credentials */
    public function attempt(array $credentials, Session $session): ?User
    {
        $email = isset($credentials['email']) ? (string) $credentials['email'] : '';
        $password = isset($credentials['password']) ? (string) $credentials['password'] : '';

        foreach ($this->users as $record) {
            if (($record['email'] ?? null) !== $email) {
                continue;
            }

            $passwordHash = (string) ($record['password_hash'] ?? '');

            if ($password === '' || !password_verify($password, $passwordHash)) {
                return null;
            }

            $user = $this->hydrateUser($record);
            $session->put('auth_user_id', $user->id());

            return $user;
        }

        return null;
    }

    public function userFromSession(Session $session): ?User
    {
        $userId = $session->get('auth_user_id');

        if (!is_int($userId) && !is_string($userId)) {
            return null;
        }

        foreach ($this->users as $record) {
            if ((int) ($record['id'] ?? 0) === (int) $userId) {
                return $this->hydrateUser($record);
            }
        }

        return null;
    }

    public function logout(Session $session): void
    {
        $session->put('auth_user_id', null);
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