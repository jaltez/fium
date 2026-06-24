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
        $email = isset($credentials['email']) ? strtolower(trim((string) $credentials['email'])) : '';
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

    public function canRegister(): bool
    {
        return $this->store instanceof MutableUserStore;
    }

    public function canResetPasswords(): bool
    {
        return $this->store instanceof MutableUserStore;
    }

    public function canVerifyEmail(): bool
    {
        return $this->store instanceof MutableUserStore;
    }

    public function canRevokeTokens(): bool
    {
        return $this->store instanceof MutableUserStore;
    }

    /** @param array<string, mixed> $credentials */
    public function attempt(array $credentials, Session $session, bool $remember = false): ?User
    {
        $user = $this->validateCredentials($credentials);

        if ($user === null) {
            return null;
        }

        $session->put('auth_user_id', $user->id());
        if ($remember) {
            $session->remember();
        } else {
            $session->forgetRemember();
        }
        $session->regenerate();

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    public function register(array $attributes, ?Session $session = null, bool $remember = false): ?User
    {
        if (!$this->store instanceof MutableUserStore) {
            return null;
        }

        $email = isset($attributes['email']) ? strtolower(trim((string) $attributes['email'])) : '';
        $password = isset($attributes['password']) ? (string) $attributes['password'] : '';

        if ($email === '' || $password === '') {
            return null;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // password_hash() with PASSWORD_DEFAULT is effectively non-failing on modern PHP;
        // kept as a defensive guard for a security-sensitive value.
        // @phpstan-ignore-next-line
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new \RuntimeException('Failed to hash the user password.');
        }

        $record = $this->store->create($email, $passwordHash);

        if ($record === null) {
            return null;
        }

        $user = $this->hydrateUser($record);

        if ($session !== null) {
            $session->put('auth_user_id', $user->id());
            if ($remember) {
                $session->remember();
            } else {
                $session->forgetRemember();
            }
            $session->regenerate();
        }

        return $user;
    }

    public function userById(int $userId): ?User
    {
        $record = $this->store->findById($userId);

        return $record !== null ? $this->hydrateUser($record) : null;
    }

    public function userByEmail(string $email): ?User
    {
        $record = $this->store->findByEmail(strtolower(trim($email)));

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
        $session->forgetRemember();
        $session->regenerate();
    }

    public function updatePassword(User $user, string $password): ?User
    {
        if (!$this->store instanceof MutableUserStore || $password === '') {
            return null;
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // password_hash() with PASSWORD_DEFAULT is effectively non-failing on modern PHP;
        // kept as a defensive guard for a security-sensitive value.
        // @phpstan-ignore-next-line
        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new \RuntimeException('Failed to hash the user password.');
        }

        $record = $this->store->updatePassword($user->id(), $passwordHash);

        if ($record === null) {
            return null;
        }

        $updatedUser = $this->hydrateUser($record);

        // updatePassword() early-returns unless the store is mutable, so this is guaranteed
        // MutableUserStore here — revoke any outstanding tokens for the changed password.
        $revokedUser = $this->revokeTokens($updatedUser);

        if ($revokedUser !== null) {
            return $revokedUser;
        }

        return $updatedUser;
    }

    public function markEmailVerified(User $user): ?User
    {
        if (!$this->store instanceof MutableUserStore) {
            return null;
        }

        if ($user->hasVerifiedEmail()) {
            return $user;
        }

        $record = $this->store->markEmailVerified($user->id());

        return $record !== null ? $this->hydrateUser($record) : null;
    }

    public function revokeTokens(User $user): ?User
    {
        if (!$this->store instanceof MutableUserStore) {
            return null;
        }

        $record = $this->store->incrementTokenVersion($user->id());

        return $record !== null ? $this->hydrateUser($record) : null;
    }

    /** @param array<string, mixed> $record */
    private function hydrateUser(array $record): User
    {
        return new User(
            (int) ($record['id'] ?? 0),
            (string) ($record['email'] ?? ''),
            (string) ($record['role'] ?? 'user'),
            isset($record['email_verified_at']) && is_string($record['email_verified_at']) && $record['email_verified_at'] !== ''
                ? $record['email_verified_at']
                : null,
            (int) ($record['token_version'] ?? 0),
        );
    }
}