<?php

declare(strict_types=1);

namespace Fium\Cache;

use Fium\Config;

/**
 * Resolves the active cache driver from configuration.
 *
 * FIUM_CACHE_DRIVER selects the backend:
 *   - "array" (default): in-process, {@see ArrayCache}
 *   - "file":            persistent, {@see FileCache} (uses FIUM_CACHE_DIR / FIUM_CACHE_PREFIX)
 *   - "redis":           optional, {@see RedisCache} (requires the phpredis extension)
 *
 * The resolved driver is cached for the worker's lifetime.
 */
final class CacheManager
{
    private static ?Cache $driver = null;

    public static function driver(): Cache
    {
        if (self::$driver instanceof Cache) {
            return self::$driver;
        }

        $name = strtolower(Config::get('FIUM_CACHE_DRIVER', 'array') ?? 'array');

        return self::$driver = match ($name) {
            'file' => new FileCache(
                Config::get('FIUM_CACHE_DIR', sys_get_temp_dir() . '/fium-cache') ?? sys_get_temp_dir() . '/fium-cache',
                Config::get('FIUM_CACHE_PREFIX', '') ?? '',
            ),
            'redis' => RedisCache::fromConfig(),
            default => new ArrayCache(),
        };
    }

    /**
     * Inject a specific driver (useful for tests and advanced wiring).
     */
    public static function set(Cache $driver): void
    {
        self::$driver = $driver;
    }

    /**
     * Forget the resolved driver. Intended for tests.
     */
    public static function reset(): void
    {
        self::$driver = null;
    }
}
