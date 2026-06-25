<?php

declare(strict_types=1);

namespace Fium\Auth;

final class SigningSecret
{
    private const DEFAULT_DEV_SECRET = 'fium-dev-api-secret';
    private const MIN_SECRET_LENGTH = 32;

    public static function resolve(string $purpose): string
    {
        $debug = \Fium\Config::bool('FIUM_DEBUG');
        $secret = trim((string) (\Fium\Config::get('FIUM_API_TOKEN_SECRET') ?? ''));

        if ($secret === '') {
            $secretFile = trim((string) (\Fium\Config::get('FIUM_API_TOKEN_SECRET_FILE') ?? ''));

            if ($secretFile !== '') {
                if (!is_file($secretFile) || !is_readable($secretFile)) {
                    throw new \RuntimeException(
                        "FIUM_API_TOKEN_SECRET_FILE points to an unreadable file: {$secretFile}"
                    );
                }

                $contents = file_get_contents($secretFile);
                if ($contents === false) {
                    throw new \RuntimeException(
                        "Failed to read FIUM_API_TOKEN_SECRET_FILE: {$secretFile}"
                    );
                }

                $secret = trim($contents);

                if ($secret === '') {
                    throw new \RuntimeException('FIUM_API_TOKEN_SECRET_FILE must contain a non-empty secret.');
                }
            }
        }

        if ($secret === '') {
            if (!$debug) {
                throw new \RuntimeException(
                    "{$purpose} signing secret is not configured. Set FIUM_API_TOKEN_SECRET or FIUM_API_TOKEN_SECRET_FILE for production."
                );
            }

            self::warn(
                'FIUM_API_TOKEN_SECRET is not set, using insecure default. Set FIUM_API_TOKEN_SECRET or FIUM_API_TOKEN_SECRET_FILE for production.'
            );

            $secret = self::DEFAULT_DEV_SECRET;
        }

        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            if (!$debug) {
                throw new \RuntimeException(
                    "{$purpose} signing secret must be at least 32 characters in production."
                );
            }

            if ($secret !== self::DEFAULT_DEV_SECRET) {
                self::warn('FIUM_API_TOKEN_SECRET is shorter than 32 characters; use a longer secret outside local development.');
            }
        }

        return $secret;
    }

    private static function warn(string $message): void
    {
        fwrite(STDERR, "[fium] WARNING: {$message}\n");
    }
}
