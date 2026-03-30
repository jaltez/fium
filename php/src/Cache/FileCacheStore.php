<?php

declare(strict_types=1);

namespace Fium\Cache;

/**
 * File-based cache store using JSON files in storage/cache/.
 */
final class FileCacheStore implements CacheStore
{
    private string $directory;

    public function __construct(string $baseDir)
    {
        $this->directory = rtrim($baseDir, '/') . '/storage/cache';
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return $default;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return $default;
        }

        if (isset($data['expires_at']) && $data['expires_at'] > 0 && time() > $data['expires_at']) {
            unlink($path);
            return $default;
        }

        return array_key_exists('value', $data) ? $data['value'] : $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $data = [
            'value' => $value,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
        ];

        file_put_contents($this->path($key), json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function has(string $key): bool
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return false;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return false;
        }

        if (isset($data['expires_at']) && $data['expires_at'] > 0 && time() > $data['expires_at']) {
            unlink($path);
            return false;
        }

        return array_key_exists('value', $data);
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . md5($key) . '.json';
    }
}
