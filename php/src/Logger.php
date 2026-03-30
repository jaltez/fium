<?php

declare(strict_types=1);

namespace Fium;

/**
 * Simple logger that writes to stderr (captured by the Rust runtime).
 */
final class Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::log('WARN', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }

    private static function log(string $level, string $message, array $context): void
    {
        $timestamp = date('Y-m-d\TH:i:s.vP');
        $line = "[{$level}] [{$timestamp}] {$message}";

        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }

        fwrite(STDERR, $line . "\n");
    }
}
