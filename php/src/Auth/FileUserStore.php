<?php

declare(strict_types=1);

namespace Fium\Auth;

final class FileUserStore implements UserStore
{
    /** @var array<int, array<string, mixed>> */
    private array $users;

    public function __construct(string $path)
    {
        $users = require $path;

        if (!is_array($users)) {
            throw new \RuntimeException('Users file must return an array.');
        }

        $this->users = $users;
    }

    public function findById(int $id): ?array
    {
        foreach ($this->users as $record) {
            if ((int) ($record['id'] ?? 0) === $id) {
                return $record;
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?array
    {
        foreach ($this->users as $record) {
            if (($record['email'] ?? null) === $email) {
                return $record;
            }
        }

        return null;
    }
}
