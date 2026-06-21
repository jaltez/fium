<?php

declare(strict_types=1);

namespace Fium\Tests\Runtime;

use Fium\Runtime\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_json_factory_sets_content_type_and_body(): void
    {
        $resp = Response::json(['ok' => true, 'n' => 1], 201);

        self::assertSame(201, $this->statusCode($resp));
        self::assertSame(['application/json'], $this->header($resp, 'content-type'));
        self::assertSame('{"ok":true,"n":1}', $this->body($resp));
        self::assertNull($this->error($resp));
    }

    public function test_json_preserves_unicode_and_slashes(): void
    {
        $resp = Response::json(['name' => 'España', 'url' => '/a/b']);
        // JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        self::assertSame('{"name":"España","url":"/a/b"}', $this->body($resp));
    }

    public function test_validation_error_factory(): void
    {
        $resp = Response::validationError(['email' => ['The email field is required.']]);

        self::assertSame(422, $this->statusCode($resp));
        $decoded = json_decode($this->body($resp), true);
        self::assertFalse($decoded['ok']);
        self::assertSame('validation_failed', $decoded['error']);
        self::assertArrayHasKey('errors', $decoded);
    }

    public function test_html_text_redirect_empty_factories(): void
    {
        $html = Response::html('<h1>Hi</h1>', 200);
        self::assertSame(['text/html; charset=UTF-8'], $this->header($html, 'content-type'));
        self::assertSame('<h1>Hi</h1>', $this->body($html));

        $text = Response::text('plain', 202);
        self::assertSame(['text/plain; charset=UTF-8'], $this->header($text, 'content-type'));
        self::assertSame('plain', $this->body($text));

        $redirect = Response::redirect('/somewhere', 301);
        self::assertSame(301, $this->statusCode($redirect));
        self::assertSame(['/somewhere'], $this->header($redirect, 'location'));
        self::assertNull($this->body($redirect));

        $empty = Response::empty(204);
        self::assertSame(204, $this->statusCode($empty));
        self::assertNull($this->body($empty));
        self::assertSame([], $this->headers($empty));
    }

    public function test_from_throwable_hides_message_in_production(): void
    {
        $resp = Response::fromThrowable(new \RuntimeException('boom'), 'req-1', false);

        self::assertSame(500, $this->statusCode($resp));
        $decoded = json_decode($this->body($resp), true);
        self::assertFalse($decoded['ok']);
        self::assertSame('internal_server_error', $decoded['error']);
        self::assertArrayNotHasKey('message', $decoded);
        self::assertArrayNotHasKey('exception', $decoded);
    }

    public function test_from_throwable_reveals_details_in_debug(): void
    {
        $resp = Response::fromThrowable(new \LogicException('bang'), 'req-2', true);

        self::assertSame(500, $this->statusCode($resp));
        $decoded = json_decode($this->body($resp), true);
        self::assertSame('bang', $decoded['message']);
        self::assertSame('LogicException', $decoded['exception']);
        self::assertSame('req-2', $decoded['request_id']);
    }

    public function test_with_header_appends_case_insensitively(): void
    {
        $resp = Response::text('x')
            ->withHeader('X-Foo', 'one')
            ->withHeader('x-foo', 'two');

        self::assertSame(['one', 'two'], $this->header($resp, 'x-foo'));
    }

    public function test_with_cookie_records_cookie(): void
    {
        $resp = Response::json([])->withCookie(
            'fium_session',
            'sid',
            '/',
            true,
            false,
            'Lax',
            3600,
        );

        $cookies = $this->cookies($resp);
        self::assertCount(1, $cookies);
        self::assertSame('fium_session', $cookies[0]['name']);
        self::assertSame('sid', $cookies[0]['value']);
        self::assertTrue($cookies[0]['http_only']);
        self::assertSame(3600, $cookies[0]['max_age']);
    }

    public function test_to_worker_response_shape(): void
    {
        $resp = Response::json(['ok' => true])->withHeader('x-test', '1');

        $wire = $resp->toWorkerResponse('req-7');

        self::assertSame(1, $wire['protocol_version']);
        self::assertSame('req-7', $wire['request_id']);
        self::assertSame(200, $wire['status']);
        self::assertArrayHasKey('headers', $wire);
        self::assertArrayHasKey('cookies', $wire);
        self::assertArrayHasKey('body', $wire);
        self::assertNull($wire['error']);
    }

    // --- Reflection helpers (Response state is immutable/private) ---

    private function statusCode(Response $r): int
    {
        return (new \ReflectionClass(Response::class))->getProperty('status')->getValue($r);
    }

    /** @return ?list<string> */
    private function header(Response $r, string $name): ?array
    {
        $headers = $this->headers($r);

        return $headers[strtolower($name)] ?? null;
    }

    /** @return array<string, list<string>> */
    private function headers(Response $r): array
    {
        return (new \ReflectionClass(Response::class))->getProperty('headers')->getValue($r);
    }

    private function body(Response $r): ?string
    {
        return (new \ReflectionClass(Response::class))->getProperty('body')->getValue($r);
    }

    /** @return ?array<string, mixed> */
    private function error(Response $r): ?array
    {
        return (new \ReflectionClass(Response::class))->getProperty('error')->getValue($r);
    }

    /** @return list<array<string, mixed>> */
    private function cookies(Response $r): array
    {
        return (new \ReflectionClass(Response::class))->getProperty('cookies')->getValue($r);
    }
}
