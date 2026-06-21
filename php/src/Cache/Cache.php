<?php

declare(strict_types=1);

namespace Fium\Cache;

/**
 * Simple cache abstraction backed by the framework's storage drivers.
 *
 * The interface mirrors PSR-16's intent (get/set/delete/has/remember) without
 * pulling in an external package, keeping the runtime dependency-free.
 */
interface Cache
{
    /**
     * Fetch a value, returning $default on a miss.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Store a value. $ttl is seconds until expiry (null = no expiry).
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    public function delete(string $key): bool;

    public function has(string $key): bool;

    /**
     * Fetch a value, computing it via $callback on a miss and caching the
     * result for $ttl seconds.
     *
     * @param callable(): mixed $callback
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed;

    /**
     * Remove all entries from the cache.
     */
    public function flush(): bool;
}
