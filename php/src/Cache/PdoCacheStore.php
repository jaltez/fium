<?php

declare(strict_types=1);

namespace Fium\Cache;

/**
 * PDO-based cache store. Table: `cache` with columns: key (VARCHAR), value (TEXT), expires_at (INT).
 */
final class PdoCacheStore implements CacheStore
{
    private \PDO $pdo;

    public function __construct(string $dsn)
    {
        $this->pdo = new \PDO($dsn);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cache (
                cache_key VARCHAR(255) PRIMARY KEY,
                cache_value TEXT NOT NULL,
                expires_at INTEGER NOT NULL DEFAULT 0
            )'
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT cache_value, expires_at FROM cache WHERE cache_key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return $default;
        }

        if ((int) $row['expires_at'] > 0 && time() > (int) $row['expires_at']) {
            $this->delete($key);
            return $default;
        }

        try {
            return json_decode((string) $row['cache_value'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $default;
        }
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        $expiresAt = $ttl > 0 ? time() + $ttl : 0;

        $stmt = $this->pdo->prepare(
            'INSERT INTO cache (cache_key, cache_value, expires_at) VALUES (:key, :value, :expires_at) '
            . 'ON CONFLICT(cache_key) DO UPDATE SET cache_value = excluded.cache_value, expires_at = excluded.expires_at'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $encoded,
            'expires_at' => $expiresAt,
        ]);
    }

    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cache WHERE cache_key = :key');
        $stmt->execute(['key' => $key]);
    }

    public function has(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT expires_at FROM cache WHERE cache_key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        $expiresAt = (int) $row['expires_at'];
        if ($expiresAt > 0 && time() > $expiresAt) {
            $this->delete($key);
            return false;
        }

        return true;
    }
}
