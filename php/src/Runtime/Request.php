<?php

declare(strict_types=1);

namespace Fium\Runtime;

use Fium\Auth\Authenticator;
use Fium\Auth\User;
use Fium\Session\Session;

final class Request
{
    /** @param array<string, mixed> $payload */
    private function __construct(private array $payload)
    {
    }

    /** @param array<string, mixed> $payload */
    public static function fromWorkerPayload(array $payload): self
    {
        return new self($payload);
    }

    public function requestId(): ?string
    {
        return isset($this->payload['request_id']) ? (string) $this->payload['request_id'] : null;
    }

    public function path(): string
    {
        return (string) ($this->payload['path'] ?? '/');
    }

    public function method(): string
    {
        return (string) ($this->payload['method'] ?? 'GET');
    }

    public function matchedRoute(): ?string
    {
        return isset($this->payload['matched_route']) ? (string) $this->payload['matched_route'] : null;
    }

    public function header(string $name): ?string
    {
        $headers = $this->payload['headers'] ?? null;

        if (!is_array($headers)) {
            return null;
        }

        $normalized = strtolower($name);
        $values = $headers[$normalized] ?? null;

        if (!is_array($values) || $values === []) {
            return null;
        }

        return (string) $values[0];
    }

    public function cookie(string $name): ?string
    {
        $cookies = $this->payload['cookies'] ?? null;

        if (!is_array($cookies)) {
            return null;
        }

        $value = $cookies[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        $body = $this->payload['body'] ?? null;

        if (!is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();

        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }

        return $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $attributes = $this->payload['attributes'] ?? [];

        if (!is_array($attributes)) {
            $attributes = [];
        }

        $attributes[$key] = $value;
        $this->payload['attributes'] = $attributes;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        $attributes = $this->payload['attributes'] ?? null;

        if (!is_array($attributes)) {
            return $default;
        }

        return $attributes[$key] ?? $default;
    }

    public function setSession(Session $session): void
    {
        $this->setAttribute('session', $session);
    }

    public function session(): ?Session
    {
        $session = $this->attribute('session');

        return $session instanceof Session ? $session : null;
    }

    public function setUser(User $user): void
    {
        $this->setAttribute('user', $user);
    }

    public function user(): ?User
    {
        $user = $this->attribute('user');

        return $user instanceof User ? $user : null;
    }

    public function authenticator(): ?Authenticator
    {
        $authenticator = $this->attribute('authenticator');

        return $authenticator instanceof Authenticator ? $authenticator : null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->payload;
    }
}