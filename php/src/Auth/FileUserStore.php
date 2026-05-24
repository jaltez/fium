<?php

declare(strict_types=1);

namespace Fium\Auth;

final class FileUserStore implements MutableUserStore
{
    /** @var array<int, array<string, mixed>> */
    private array $users;

    public function __construct(private string $path)
    {
        if (!is_file($this->path)) {
            $this->users = [];

            return;
        }

        $users = require $this->path;

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
            if (strtolower((string) ($record['email'] ?? '')) === strtolower($email)) {
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

        $nextId = 1;
        foreach ($this->users as $record) {
            $nextId = max($nextId, (int) ($record['id'] ?? 0) + 1);
        }

        $record = [
            'id' => $nextId,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'email_verified_at' => null,
            'token_version' => 0,
        ];

        $this->users[] = $record;
        $this->persist();

        return $record;
    }

    public function updatePassword(int $userId, string $passwordHash): ?array
    {
        foreach ($this->users as $index => $record) {
            if ((int) ($record['id'] ?? 0) !== $userId) {
                continue;
            }

            $record['password_hash'] = $passwordHash;
            $this->users[$index] = $record;
            $this->persist();

            return $record;
        }

        return null;
    }

    public function markEmailVerified(int $userId): ?array
    {
        foreach ($this->users as $index => $record) {
            if ((int) ($record['id'] ?? 0) !== $userId) {
                continue;
            }

            $record['email_verified_at'] = gmdate('c');
            $this->users[$index] = $record;
            $this->persist();

            return $record;
        }

        return null;
    }

    public function incrementTokenVersion(int $userId): ?array
    {
        foreach ($this->users as $index => $record) {
            if ((int) ($record['id'] ?? 0) !== $userId) {
                continue;
            }

            $record['token_version'] = (int) ($record['token_version'] ?? 0) + 1;
            $this->users[$index] = $record;
            $this->persist();

            return $record;
        }

        return null;
    }

    private function persist(): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($concurrentDirectory = $directory, 0777, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Users directory "%s" could not be created.', $directory));
        }

        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($this->users, true) . ";\n";

        if (file_put_contents($this->path, $php, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Users file "%s" could not be written.', $this->path));
        }
    }
}
