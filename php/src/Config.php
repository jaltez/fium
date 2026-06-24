<?php

declare(strict_types=1);

namespace Fium;

final class Config
{
    /** @var array<string, string> */
    private static array $values = [];
    private static bool $loaded = false;

    /**
     * Load environment variables from a .env file.
     * Variables already set in the actual environment take precedence.
     */
    public static function loadEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $eqPos = strpos($line, '=');

            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // Strip surrounding quotes
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Don't override real environment
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
            }

            self::$values[$key] = $value;
        }

        self::$loaded = true;
    }

    /**
     * Get a configuration value from the environment.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        if ($value !== false && $value !== '') {
            return $value;
        }

        return self::$values[$key] ?? $default;
    }

    /**
     * Get a configuration value as a non-null string. Use this over get() when a default
     * is required and the result is assigned to a non-nullable target.
     */
    public static function string(string $key, string $default): string
    {
        return self::get($key) ?? $default;
    }

    /**
     * Get a configuration value as an integer.
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value !== null ? (int) $value : $default;
    }

    /**
     * Get a configuration value as a boolean.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Check if a key is set in the environment.
     */
    public static function has(string $key): bool
    {
        return getenv($key) !== false || isset(self::$values[$key]);
    }

    /**
     * Whether a .env file has been loaded.
     */
    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
