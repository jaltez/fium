<?php

declare(strict_types=1);

namespace Fium\Tests\Cache;

use Fium\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;

final class ArrayCacheTest extends TestCase
{
    public function test_miss_returns_default(): void
    {
        $cache = new ArrayCache();

        self::assertNull($cache->get('missing'));
        self::assertSame('fallback', $cache->get('missing', 'fallback'));
        self::assertFalse($cache->has('missing'));
    }

    public function test_set_get_roundtrip(): void
    {
        $cache = new ArrayCache();
        $cache->set('user', ['name' => 'Ada']);

        self::assertSame(['name' => 'Ada'], $cache->get('user'));
        self::assertTrue($cache->has('user'));
    }

    public function test_delete(): void
    {
        $cache = new ArrayCache();
        $cache->set('k', 'v');
        $cache->delete('k');

        self::assertFalse($cache->has('k'));
    }

    public function test_remember_caches_callback_result(): void
    {
        $cache = new ArrayCache();
        $calls = 0;

        $first = $cache->remember('expensive', function () use (&$calls): string {
            $calls++;

            return 'computed';
        });
        $second = $cache->remember('expensive', function () use (&$calls): string {
            $calls++;

            return 'computed-again';
        });

        self::assertSame('computed', $first);
        self::assertSame('computed', $second, 'second call serves the cache, not the callback');
        self::assertSame(1, $calls, 'callback invoked only once');
    }

    public function test_flush_clears_all(): void
    {
        $cache = new ArrayCache();
        $cache->set('a', 1);
        $cache->set('b', 2);
        $cache->flush();

        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
    }

    public function test_ttl_zero_expires_on_next_read(): void
    {
        $cache = new ArrayCache();
        $cache->set('ephemeral', 'v', 0);

        // expires_at is now(); any later read is expired.
        self::assertNull($cache->get('ephemeral'));
        self::assertFalse($cache->has('ephemeral'));
    }
}
