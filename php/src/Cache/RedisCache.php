<?php

declare(strict_types=1);

namespace Fium\Cache;

use Fium\Config;

/**
 * Cache backed by Redis via the phpredis extension.
 *
 * This driver is OPTIONAL: it only works when the `redis` PHP extension is
 * installed and loaded (it is not bundled). It is never selected automatically
 * unless FIUM_CACHE_DRIVER=redis, so the framework remains usable without it.
 *
 * Values are transparently serialized using Redis' PHP serializer, so arrays
 * and serializable objects round-trip without extra work.
 */
final class RedisCache implements Cache
{
    private function __construct(private \Redis $redis)
    {
    }

    /**
     * Build a Redis client from FIUM_REDIS_* configuration.
     */
    public static function fromConfig(): self
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException(
                'The "redis" PHP extension is required for the redis cache driver. '
                . 'Install phpredis or use a different FIUM_CACHE_DRIVER.'
            );
        }

        $redis = new \Redis();
        $redis->pconnect(
            Config::get('FIUM_REDIS_HOST', '127.0.0.1') ?? '127.0.0.1',
            Config::int('FIUM_REDIS_PORT', 6379),
        );

        $password = Config::get('FIUM_REDIS_PASSWORD');
        if ($password !== null && $password !== '') {
            $redis->auth($password);
        }

        $db = Config::int('FIUM_REDIS_DB', 0);
        if ($db !== 0) {
            $redis->select($db);
        }

        $prefix = Config::get('FIUM_REDIS_PREFIX', 'fium:') ?? 'fium:';
        if ($prefix !== '') {
            $redis->setOption(\Redis::OPT_PREFIX, $prefix);
        }

        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

        return new self($redis);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->redis->get($key);

        // phpredis returns false both on a miss and for a stored `false`.
        if ($value === false && !$this->existsInternal($key)) {
            return $default;
        }

        return $value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl === null) {
            return $this->redis->set($key, $value);
        }

        return $this->redis->setex($key, max(1, $ttl), $value);
    }

    public function delete(string $key): bool
    {
        $this->redis->del($key);

        return true;
    }

    public function has(string $key): bool
    {
        return $this->existsInternal($key);
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key, $this);

        if ($value !== $this) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function flush(): bool
    {
        return $this->redis->flushDB();
    }

    private function existsInternal(string $key): bool
    {
        return $this->redis->exists($key) > 0;
    }
}
