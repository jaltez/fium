<?php

declare(strict_types=1);

namespace Fium\Cache;

/**
 * Persistent cache backed by files on disk.
 *
 * Each entry is a serialized `{value, expires_at}` blob written to a path
 * derived from a sha256 of the cache key, so any non-empty key is safe. Entries
 * are lazily expired on read. Suitable as a shared cache across worker
 * recycling within a single host.
 */
final class FileCache implements Cache
{
    private readonly string $directory;
    private readonly string $prefix;

    public function __construct(string $directory, string $prefix = '')
    {
        $this->directory = rtrim($directory, '/');
        $this->prefix = $prefix;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->pathFor($key);
        $entry = $this->read($path);

        if ($entry === null) {
            return $default;
        }

        if ($this->isExpired($entry)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->ensureDirectory();

        $entry = [
            'value' => $value,
            'expires_at' => $ttl === null ? null : time() + $ttl,
        ];

        try {
            $serialized = serialize($entry);
        } catch (\Throwable) {
            return false;
        }

        return file_put_contents($this->pathFor($key), $serialized, LOCK_EX) !== false;
    }

    public function delete(string $key): bool
    {
        $path = $this->pathFor($key);

        if (is_file($path)) {
            @unlink($path);
        }

        return true;
    }

    public function has(string $key): bool
    {
        $path = $this->pathFor($key);
        $entry = $this->read($path);

        return $entry !== null && !$this->isExpired($entry);
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $path = $this->pathFor($key);
        $entry = $this->read($path);

        if ($entry !== null && !$this->isExpired($entry)) {
            return $entry['value'];
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function flush(): bool
    {
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }

        return true;
    }

    private function pathFor(string $key): string
    {
        $hash = hash('sha256', $this->prefix . $key);

        return $this->directory . '/' . $hash . '.cache';
    }

    /** @return ?array{value: mixed, expires_at: ?int} */
    private function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            // Suppress the warning unserialize emits on corrupt payloads; we
            // treat any non-array result as a miss.
            $entry = @unserialize($contents, ['allowed_classes' => true]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($entry) && array_key_exists('value', $entry) ? $entry : null;
    }

    /** @param array{value: mixed, expires_at: ?int} $entry */
    private function isExpired(array $entry): bool
    {
        return $entry['expires_at'] !== null && $entry['expires_at'] <= time();
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Cache directory "%s" could not be created.', $this->directory));
        }
    }
}
