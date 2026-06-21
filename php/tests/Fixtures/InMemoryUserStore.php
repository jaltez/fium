<?php

declare(strict_types=1);

namespace Fium\Tests\Fixtures;

use Fium\Auth\MutableUserStore;

/**
 * In-memory MutableUserStore for tests. Avoids disk I/O and bcrypt-encoded
 * fixture files while exercising the full Authenticator surface.
 */
final class InMemoryUserStore implements MutableUserStore
{
    /** @var array<int, array<string, mixed>> */
    private array $users = [];

    private int $nextId = 1;

    /**
     * Seed the store from an already-hashed record set.
     *
     * @param list<array<string, mixed>> $users
     */
    public function __construct(array $users = [])
    {
        foreach ($users as $record) {
            $this->seed($record);
        }
    }

    /** @param array<string, mixed> $record */
    public function seed(array $record): void
    {
        if (!isset($record['id'])) {
            $record['id'] = $this->nextId++;
        } else {
            $this->nextId = max($this->nextId, (int) $record['id'] + 1);
        }

        $this->users[(int) $record['id']] = $record;
    }

    public function findById(int $id): ?array
    {
        return $this->users[$id] ?? null;
    }

    public function findByEmail(string $email): ?array
    {
        $email = strtolower($email);

        foreach ($this->users as $record) {
            if (strtolower((string) ($record['email'] ?? '')) === $email) {
                return $record;
            }
        }

        return null;
    }

    public function create(string $email, string $passwordHash, string $role = 'user'): ?array
    {
        if ($this->findByEmail($email) !== null) {
            return null;
        }

        $record = [
            'id' => $this->nextId++,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'email_verified_at' => null,
            'token_version' => 0,
        ];

        $this->users[$record['id']] = $record;

        return $record;
    }

    public function updatePassword(int $userId, string $passwordHash): ?array
    {
        return $this->mutate($userId, ['password_hash' => $passwordHash]);
    }

    public function markEmailVerified(int $userId): ?array
    {
        return $this->mutate($userId, ['email_verified_at' => gmdate('c')]);
    }

    public function incrementTokenVersion(int $userId): ?array
    {
        $record = $this->users[$userId] ?? null;
        if ($record === null) {
            return null;
        }

        $record['token_version'] = (int) ($record['token_version'] ?? 0) + 1;
        $this->users[$userId] = $record;

        return $record;
    }

    /** @param array<string, mixed> $changes */
    private function mutate(int $userId, array $changes): ?array
    {
        $record = $this->users[$userId] ?? null;
        if ($record === null) {
            return null;
        }

        $record = array_merge($record, $changes);
        $this->users[$userId] = $record;

        return $record;
    }
}
