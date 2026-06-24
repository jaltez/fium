<?php

declare(strict_types=1);

namespace Fium\Auth;

final class PdoUserStore implements MutableUserStore
{
    private \PDO $pdo;

    public function __construct(string $dsn)
    {
        $this->pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $this->ensureTable();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function create(string $email, string $passwordHash, string $role = 'user'): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (email, password_hash, role, email_verified_at) VALUES (:email, :password_hash, :role, :email_verified_at)'
            );
            $stmt->execute([
                'email' => $email,
                'password_hash' => $passwordHash,
                'role' => $role,
                'email_verified_at' => null,
            ]);
        } catch (\PDOException) {
            return null;
        }

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function updatePassword(int $userId, string $passwordHash): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $stmt->execute([
            'password_hash' => $passwordHash,
            'id' => $userId,
        ]);

        if ($stmt->rowCount() < 1) {
            return null;
        }

        return $this->findById($userId);
    }

    public function markEmailVerified(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE users SET email_verified_at = :email_verified_at WHERE id = :id');
        $stmt->execute([
            'email_verified_at' => gmdate('c'),
            'id' => $userId,
        ]);

        if ($stmt->rowCount() < 1) {
            return null;
        }

        return $this->findById($userId);
    }

    public function incrementTokenVersion(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE users SET token_version = COALESCE(token_version, 0) + 1 WHERE id = :id');
        $stmt->execute([
            'id' => $userId,
        ]);

        if ($stmt->rowCount() < 1) {
            return null;
        }

        return $this->findById($userId);
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS users ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'email TEXT NOT NULL UNIQUE, '
            . 'password_hash TEXT NOT NULL, '
            . 'role TEXT NOT NULL DEFAULT \'user\', '
            . 'email_verified_at TEXT NULL, '
            . 'token_version INTEGER NOT NULL DEFAULT 0'
            . ')'
        );

        // query() returns PDOStatement|false; the previous `?->` only short-circuited null,
        // so a false return would have thrown a TypeError on ->fetchAll().
        $statement = $this->pdo->query('PRAGMA table_info(users)');
        $columns = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_map(static fn (array $column): string => (string) ($column['name'] ?? ''), $columns);

        if (!in_array('email_verified_at', $columnNames, true)) {
            $this->pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at TEXT NULL');
        }

        if (!in_array('token_version', $columnNames, true)) {
            $this->pdo->exec('ALTER TABLE users ADD COLUMN token_version INTEGER NOT NULL DEFAULT 0');
        }
    }
}
