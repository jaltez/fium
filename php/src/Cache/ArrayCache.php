<?php

declare(strict_types=1);

namespace Fium\Cache;

/**
 * In-process cache backed by a PHP array.
 *
 * Useful for tests, request-scoped memoization, and as the default driver when
 * no persistent cache is configured. Entries are lost when the worker process
 * is recycled.
 */
final class ArrayCache implements Cache
{
    /** @var array<string, array{value: mixed, expires_at: ?float}> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->lookup($key) ?? $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->store[$key] = [
            'value' => $value,
            'expires_at' => $this->expiry($ttl),
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        return $this->lookup($key) !== null || array_key_exists($key, $this->store);
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->lookup($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function flush(): bool
    {
        $this->store = [];

        return true;
    }

    private function lookup(string $key): mixed
    {
        if (!array_key_exists($key, $this->store)) {
            return null;
        }

        $entry = $this->store[$key];

        if ($entry['expires_at'] !== null && $entry['expires_at'] <= microtime(true)) {
            unset($this->store[$key]);

            return null;
        }

        return $entry['value'];
    }

    private function expiry(?int $ttl): ?float
    {
        return $ttl === null ? null : microtime(true) + $ttl;
    }
}
