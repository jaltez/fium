<?php

declare(strict_types=1);

namespace Fium\Auth;

final class EmailVerificationTokenService
{
    public function __construct(
        private Authenticator $authenticator,
        private string $secret,
        private int $ttlSeconds = 3600,
    ) {
    }

    public static function boot(Authenticator $authenticator): self
    {
        return new self($authenticator, SigningSecret::resolve('Email verification'), 3600);
    }

    public function issue(User $user): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];
        $payload = [
            'sub' => $user->id(),
            'purpose' => 'email_verification',
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

        $purpose = $payload['purpose'] ?? null;
        $exp = $payload['exp'] ?? null;
        $sub = $payload['sub'] ?? null;

        if ($purpose !== 'email_verification' || !is_int($exp) || $exp < time()) {
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
