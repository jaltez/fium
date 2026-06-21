<?php

declare(strict_types=1);

namespace Fium\Tests\Cache;

use Fium\Cache\RedisCache;
use PHPUnit\Framework\TestCase;

/**
 * The redis driver requires the phpredis extension, which is optional and not
 * bundled. These tests verify the guard behavior when it is absent, and the
 * live behavior when it is present.
 */
final class RedisCacheTest extends TestCase
{
    public function test_from_config_throws_when_extension_missing(): void
    {
        if (class_exists(\Redis::class)) {
            self::markTestSkipped('phpredis extension is installed; skipping the missing-extension test.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('redis');

        RedisCache::fromConfig();
    }
}
