<?php

declare(strict_types=1);

namespace Fium\Runtime;

use Fium\Auth\Authenticator;
use Fium\Auth\ApiTokenService;
use Fium\Auth\User;
use Fium\Session\Session;
use Fium\Validator;

final class Request
{
    private ?array $parsedQuery = null;
    private ?array $parsedJson = null;
    private bool $jsonParsed = false;

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

    public function queryString(): string
    {
        return (string) ($this->payload['query_string'] ?? '');
    }

    public function query(string $key, ?string $default = null): ?string
    {
        if ($this->parsedQuery === null) {
            parse_str($this->queryString(), $this->parsedQuery);
        }

        if (!array_key_exists($key, $this->parsedQuery)) {
            return $default;
        }

        return is_string($this->parsedQuery[$key]) ? $this->parsedQuery[$key] : $default;
    }

    public function clientIp(): ?string
    {
        return isset($this->payload['client_ip']) ? (string) $this->payload['client_ip'] : null;
    }

    public function scheme(): string
    {
        return (string) ($this->payload['scheme'] ?? 'http');
    }

    public function isSecure(): bool
    {
        return (bool) ($this->payload['is_secure'] ?? false);
    }

    public function host(): string
    {
        return (string) ($this->payload['host'] ?? 'localhost');
    }

    public function matchedRoute(): ?string
    {
        return isset($this->payload['matched_route']) ? (string) $this->payload['matched_route'] : null;
    }

    public function routeParam(string $name, ?string $default = null): ?string
    {
        $routeParams = $this->payload['route_params'] ?? null;

        if (!is_array($routeParams)) {
            return $default;
        }

        $value = $routeParams[$name] ?? $default;

        return is_string($value) ? $value : $default;
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

    public function bearerToken(): ?string
    {
        $authorization = $this->header('authorization');

        if (!is_string($authorization) || !str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = substr($authorization, 7);

        return $token !== '' ? $token : null;
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
        if ($this->jsonParsed) {
            return $this->parsedJson;
        }

        $this->jsonParsed = true;
        $body = $this->payload['body'] ?? null;

        if (!is_string($body) || $body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        $this->parsedJson = is_array($decoded) ? $decoded : null;

        return $this->parsedJson;
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

    public function tokenService(): ?ApiTokenService
    {
        $tokenService = $this->attribute('token_service');

        return $tokenService instanceof ApiTokenService ? $tokenService : null;
    }

    /**
     * Validate request input against rules.
     *
     * @param array<string, string> $rules e.g. ['email' => 'required|email', 'name' => 'required|min:2']
     */
    public function validate(array $rules): Validator
    {
        return Validator::make($this, $rules);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->payload;
    }
}