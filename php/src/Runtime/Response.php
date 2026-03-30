<?php

declare(strict_types=1);

namespace Fium\Runtime;

final class Response
{
    /** @var list<array{name: string, value: string, path: ?string, http_only: bool, secure: bool}> */
    private array $cookies = [];

    /** @param array<string, list<string>> $headers */
    private function __construct(
        private int $status,
        private array $headers,
        private ?string $body,
        private ?array $error = null,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200, ?string $requestId = null): self
    {
        $json = json_encode($payload);

        if ($json === false) {
            return new self(500, ['content-type' => ['application/json']], json_encode([
                'ok' => false,
                'error' => 'json_encoding_failed',
                'request_id' => $requestId,
            ]) ?: null, ['kind' => 'json_encoding_failed', 'message' => 'Failed to encode JSON response']);
        }

        return new self($status, ['content-type' => ['application/json']], $json);
    }

    /** @param array<string, list<string>> $errors */
    public static function validationError(array $errors): self
    {
        return self::json([
            'ok' => false,
            'error' => 'validation_failed',
            'errors' => $errors,
        ], 422);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['content-type' => ['text/html; charset=UTF-8']], $html);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($status, ['content-type' => ['text/plain; charset=UTF-8']], $text);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, ['location' => [$url]], null);
    }

    public static function empty(int $status = 204): self
    {
        return new self($status, [], null);
    }

    public static function fromThrowable(\Throwable $throwable, ?string $requestId = null, bool $debug = false): self
    {
        $payload = [
            'ok' => false,
            'error' => 'internal_server_error',
        ];

        if ($debug) {
            $payload['message'] = $throwable->getMessage();
            $payload['exception'] = get_class($throwable);
            $payload['request_id'] = $requestId;
        }

        return new self(
            500,
            ['content-type' => ['application/json']],
            json_encode($payload) ?: '{"ok":false,"error":"internal_server_error"}',
            [
                'kind' => 'internal_server_error',
                'message' => $debug ? $throwable->getMessage() : 'Unhandled application exception',
            ],
        );
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $normalized = strtolower($name);
        $clone->headers[$normalized] ??= [];
        $clone->headers[$normalized][] = $value;

        return $clone;
    }

    public function withCookie(
        string $name,
        string $value,
        ?string $path = '/',
        bool $httpOnly = true,
        bool $secure = false,
        ?string $sameSite = 'Lax',
        ?int $maxAge = null,
    ): self {
        $clone = clone $this;
        $clone->cookies[] = [
            'name' => $name,
            'value' => $value,
            'path' => $path,
            'http_only' => $httpOnly,
            'secure' => $secure,
            'same_site' => $sameSite,
            'max_age' => $maxAge,
        ];

        return $clone;
    }

    /** @return array<string, mixed> */
    public function toWorkerResponse(?string $requestId = null): array
    {
        return [
            'protocol_version' => 1,
            'request_id' => $requestId,
            'status' => $this->status,
            'headers' => $this->headers,
            'cookies' => $this->cookies,
            'body' => $this->body,
            'error' => $this->error,
        ];
    }
}