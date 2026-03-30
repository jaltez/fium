<?php

declare(strict_types=1);

namespace Fium\Auth;

final class ApiTokenService
{
    public function __construct(
        private Authenticator $authenticator,
        private string $secret,
        private int $ttlSeconds = 3600,
    ) {
    }

    public static function boot(Authenticator $authenticator): self
    {
        $secret = \Fium\Config::get('FIUM_API_TOKEN_SECRET');

        if ($secret === null || $secret === '') {
            $secret = 'fium-dev-api-secret';
            fwrite(STDERR, "[fium] WARNING: FIUM_API_TOKEN_SECRET is not set, using insecure default. Set it in .env for production.\n");
        }

        return new self($authenticator, $secret, 3600);
    }

    public function issue(User $user): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];
        $payload = [
            'sub' => $user->id(),
            'email' => $user->email(),
            'role' => $user->role(),
            'iat' => time(),
            'exp' => time() + $this->ttlSeconds,
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header) ?: '{}');
        $encodedPayload = $this->base64UrlEncode(json_encode($payload) ?: '{}');
        $signature = $this->sign("{$encodedHeader}.{$encodedPayload}");

        return "{$encodedHeader}.{$encodedPayload}.{$signature}";
    }

    public function userFromToken(string $token): ?User
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $signature] = $parts;

        if (!hash_equals($this->sign("{$encodedHeader}.{$encodedPayload}"), $signature)) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($encodedPayload);

        if ($payloadJson === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);

        if (!is_array($payload)) {
            return null;
        }

        $exp = $payload['exp'] ?? null;
        $sub = $payload['sub'] ?? null;

        if (!is_int($exp) || $exp < time()) {
            return null;
        }

        if (!is_int($sub) && !is_string($sub)) {
            return null;
        }

        return $this->authenticator->userById((int) $sub);
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    private function sign(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret, true));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}