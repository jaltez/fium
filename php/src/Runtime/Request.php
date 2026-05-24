<?php

declare(strict_types=1);

namespace Fium\Runtime;

use Fium\Auth\Authenticator;
use Fium\Auth\User;
use Fium\Contracts\TokenService;
use Fium\Session\Session;
use Fium\Validator;

final class Request
{
    private ?array $parsedQuery = null;
    private ?array $parsedJson = null;
    private ?array $parsedForm = null;
    private ?string $loadedBody = null;
    private bool $bodyLoaded = false;
    private bool $jsonParsed = false;
    private bool $formParsed = false;

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

    public function body(): string
    {
        if ($this->bodyLoaded) {
            return $this->loadedBody ?? '';
        }

        $this->bodyLoaded = true;

        $body = $this->payload['body'] ?? null;

        if (is_string($body)) {
            $this->loadedBody = $body;

            return $body;
        }

        $bodyFile = $this->payload['body_file'] ?? null;

        if (is_string($bodyFile) && $bodyFile !== '' && is_file($bodyFile)) {
            $contents = file_get_contents($bodyFile);
            $this->loadedBody = is_string($contents) ? $contents : '';

            return $this->loadedBody;
        }

        $this->loadedBody = '';

        return '';
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

    public function contentType(): ?string
    {
        $contentType = $this->header('content-type');

        if (!is_string($contentType) || $contentType === '') {
            return null;
        }

        [$mediaType] = explode(';', $contentType, 2);

        return strtolower(trim($mediaType));
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
        $body = $this->body();

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        $this->parsedJson = is_array($decoded) ? $decoded : null;

        return $this->parsedJson;
    }

    /** @return array<string, mixed>|null */
    public function form(): ?array
    {
        if ($this->formParsed) {
            return $this->parsedForm;
        }

        $this->formParsed = true;
        $body = $this->body();

        if ($body === '') {
            return null;
        }

        $contentType = $this->contentType();

        if ($contentType === 'application/x-www-form-urlencoded') {
            parse_str($body, $parsed);
            $this->parsedForm = is_array($parsed) ? $parsed : null;

            return $this->parsedForm;
        }

        if ($contentType === 'multipart/form-data') {
            $this->parsedForm = $this->parseMultipartForm($body);

            return $this->parsedForm;
        }

        return null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();

        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }

        $form = $this->form();

        if (is_array($form) && array_key_exists($key, $form)) {
            return $form[$key];
        }

        return $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
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

    public function csrfToken(): ?string
    {
        $session = $this->session();

        if ($session === null) {
            return null;
        }

        $token = $session->get('csrf_token');

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(20));
            $session->put('csrf_token', $token);
        }

        return $token;
    }

    public function csrfField(): string
    {
        $token = $this->csrfToken();

        if (!is_string($token) || $token === '') {
            return '';
        }

        return '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
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

    public function tokenService(): ?TokenService
    {
        $tokenService = $this->attribute('token_service');

        return $tokenService instanceof TokenService ? $tokenService : null;
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

    /** @return array<string, mixed>|null */
    private function parseMultipartForm(string $body): ?array
    {
        $contentType = $this->header('content-type');

        if (!is_string($contentType) || !preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', $contentType, $matches)) {
            return null;
        }

        $boundary = $matches[1] !== '' ? $matches[1] : trim($matches[2]);

        if ($boundary === '') {
            return null;
        }

        $pairs = [];
        $parts = explode('--' . $boundary, $body);

        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");
            $part = rtrim($part, "\r\n");

            if ($part === '' || $part === '--') {
                continue;
            }

            $segments = preg_split("/\r?\n\r?\n/", $part, 2);

            if (!is_array($segments) || count($segments) !== 2) {
                continue;
            }

            [$rawHeaders, $value] = $segments;
            $disposition = null;

            foreach (preg_split("/\r?\n/", $rawHeaders) ?: [] as $line) {
                if (str_starts_with(strtolower($line), 'content-disposition:')) {
                    $disposition = trim(substr($line, strlen('content-disposition:')));
                    break;
                }
            }

            if (!is_string($disposition) || !preg_match('/name="([^"]+)"/', $disposition, $nameMatch)) {
                continue;
            }

            if (preg_match('/filename="[^"]*"/', $disposition) === 1) {
                continue;
            }

            $pairs[] = rawurlencode($nameMatch[1]) . '=' . rawurlencode($value);
        }

        if ($pairs === []) {
            return null;
        }

        parse_str(implode('&', $pairs), $parsed);

        return is_array($parsed) ? $parsed : null;
    }
}