<?php

declare(strict_types=1);

namespace Fium\Auth;

final class PdoUserStore implements UserStore
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

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS users ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'email TEXT NOT NULL UNIQUE, '
            . 'password_hash TEXT NOT NULL, '
            . 'role TEXT NOT NULL DEFAULT \'user\''
            . ')'
        );
    }
}
