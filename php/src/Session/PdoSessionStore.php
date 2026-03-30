<?php

declare(strict_types=1);

namespace Fium\Session;

final class PdoSessionStore implements SessionStore
{
    private \PDO $pdo;

    public function __construct(string $dsn)
    {
        $this->pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $this->ensureTable();
    }

    public function load(?string $sessionId): Session
    {
        $sessionId = $this->normalizeId($sessionId);

        if ($sessionId === null) {
            return new Session($this->generateId(), [], false, true);
        }

        $stmt = $this->pdo->prepare('SELECT data FROM sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return new Session($sessionId, [], false, true);
        }

        $payload = json_decode((string) $row['data'], true);

        if (!is_array($payload)) {
            return new Session($sessionId, [], false, true);
        }

        return new Session($sessionId, $payload, false, false);
    }

    public function save(Session $session): void
    {
        $json = json_encode($session->all(), JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode session data.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, data, updated_at) VALUES (:id, :data, :now) '
            . 'ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'id' => $session->id(),
            'data' => $json,
            'now' => date('Y-m-d H:i:s'),
        ]);
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS sessions ('
            . 'id TEXT PRIMARY KEY, '
            . 'data TEXT NOT NULL DEFAULT \'{}\', '
            . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        );
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(20));
    }

    private function normalizeId(?string $sessionId): ?string
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        if (!preg_match('/^[a-f0-9]{16,64}$/', $sessionId)) {
            return null;
        }

        return $sessionId;
    }
}
