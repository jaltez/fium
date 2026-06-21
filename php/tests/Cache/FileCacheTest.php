<?php

declare(strict_types=1);

namespace Fium\Tests\Cache;

use Fium\Cache\FileCache;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private string $dir;
    private FileCache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fium_cache_' . bin2hex(random_bytes(4));
        $this->cache = new FileCache($this->dir, 'test_');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->dir)) {
            @rmdir($this->dir);
        }
    }

    public function test_set_get_roundtrip_for_scalars_and_arrays(): void
    {
        $this->cache->set('name', 'Ada');
        $this->cache->set('profile', ['age' => 36, 'roles' => ['admin', 'user']]);

        self::assertSame('Ada', $this->cache->get('name'));
        self::assertSame(['age' => 36, 'roles' => ['admin', 'user']], $this->cache->get('profile'));
    }

    public function test_miss_returns_default(): void
    {
        self::assertNull($this->cache->get('nope'));
        self::assertSame('fallback', $this->cache->get('nope', 'fallback'));
        self::assertFalse($this->cache->has('nope'));
    }

    public function test_delete(): void
    {
        $this->cache->set('k', 'v');
        $this->cache->delete('k');

        self::assertFalse($this->cache->has('k'));
    }

    public function test_special_characters_in_key_are_safe(): void
    {
        $this->cache->set('user:42:profile {weird}', 'ok');

        self::assertSame('ok', $this->cache->get('user:42:profile {weird}'));
        self::assertTrue($this->cache->has('user:42:profile {weird}'));
    }

    public function test_remember_caches_callback_result(): void
    {
        $calls = 0;

        $first = $this->cache->remember('k', function () use (&$calls): string {
            $calls++;

            return 'computed';
        });
        $second = $this->cache->remember('k', function () use (&$calls): string {
            $calls++;

            return 'again';
        });

        self::assertSame('computed', $first);
        self::assertSame('computed', $second);
        self::assertSame(1, $calls);
    }

    public function test_flush_removes_entries(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->flush();

        self::assertSame([], glob($this->dir . '/*.cache'));
    }

    public function test_expired_entry_is_treated_as_miss(): void
    {
        $this->cache->set('k', 'v', 3600);

        // Forcibly mark the persisted entry as expired by overwriting the file.
        $path = $this->dir . '/' . hash('sha256', 'test_k') . '.cache';
        self::assertFileExists($path);
        file_put_contents($path, serialize(['value' => 'v', 'expires_at' => time() - 60]));

        self::assertNull($this->cache->get('k'));
        self::assertFalse($this->cache->has('k'));
    }

    public function test_corrupt_file_is_treated_as_miss(): void
    {
        $this->cache->set('k', 'v');
        $path = $this->dir . '/' . hash('sha256', 'test_k') . '.cache';
        file_put_contents($path, 'not-serialized{garbage');

        self::assertNull($this->cache->get('k'));
    }
}
