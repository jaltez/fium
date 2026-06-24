<?php

declare(strict_types=1);

namespace Fium\Auth;

use Fium\Contracts\TokenService;

final class ApiTokenService implements TokenService
{
    public function __construct(
        private Authenticator $authenticator,
        private ?string $secret = null,
        private int $ttlSeconds = 3600,
        private int $refreshTtlSeconds = 2_592_000,
    ) {
    }

    public static function boot(Authenticator $authenticator): self
    {
        // secret is resolved lazily on first sign() (see secret()), so apps that never use
        // API tokens don't require a signing secret (or FIUM_DEBUG) at boot.
        return new self($authenticator, null, 3600, 2_592_000);
    }

    /**
     * Resolve the signing secret on first use. An explicit secret passed to the
     * constructor is used as-is; otherwise it comes from SigningSecret (config/debug).
     */
    private function secret(): string
    {
        if ($this->secret === null) {
            $this->secret = SigningSecret::resolve('API token');
        }

        return $this->secret;
    }

    public function issue(User $user): string
    {
        return $this->issueToken($user, 'access', $this->ttlSeconds);
    }

    public function issueRefreshToken(User $user): string
    {
        return $this->issueToken($user, 'refresh', $this->refreshTtlSeconds);
    }

    public function userFromToken(string $token): ?User
    {
        return $this->userFromSignedToken($token, 'access');
    }

    public function userFromRefreshToken(string $token): ?User
    {
        return $this->userFromSignedToken($token, 'refresh');
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function refreshTtlSeconds(): int
    {
        return $this->refreshTtlSeconds;
    }

    private function issueToken(User $user, string $purpose, int $ttlSeconds): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];
        $payload = [
            'sub' => $user->id(),
            'purpose' => $purpose,
            'email' => $user->email(),
            'role' => $user->role(),
            'ver' => $user->tokenVersion(),
            'iat' => time(),
            'exp' => time() + $ttlSeconds,
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header) ?: '{}');
        $encodedPayload = $this->base64UrlEncode(json_encode($payload) ?: '{}');
        $signature = $this->sign("{$encodedHeader}.{$encodedPayload}");

        return "{$encodedHeader}.{$encodedPayload}.{$signature}";
    }

    private function userFromSignedToken(string $token, string $expectedPurpose): ?User
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
        $ver = $payload['ver'] ?? null;

        if ($purpose !== $expectedPurpose || !is_int($exp) || $exp < time()) {
            return null;
        }

        if ((!is_int($sub) && !is_string($sub)) || (!is_int($ver) && !is_string($ver))) {
            return null;
        }

        $user = $this->authenticator->userById((int) $sub);

        if ($user === null || $user->tokenVersion() !== (int) $ver) {
            return null;
        }

        return $user;
    }

    private function sign(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret(), true));
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