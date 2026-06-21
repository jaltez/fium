<?php

declare(strict_types=1);

namespace Fium\Tests\Runtime;

use Fium\Runtime\Request;
use Fium\Session\Session;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_defaults_when_payload_empty(): void
    {
        $r = Request::fromWorkerPayload([]);

        self::assertSame('/', $r->path());
        self::assertSame('GET', $r->method());
        self::assertSame('', $r->body());
        self::assertSame('', $r->queryString());
        self::assertSame('localhost', $r->host());
        self::assertSame('http', $r->scheme());
        self::assertFalse($r->isSecure());
        self::assertNull($r->matchedRoute());
        self::assertNull($r->requestId());
        self::assertNull($r->clientIp());
    }

    public function test_inline_body_and_body_file(): void
    {
        $inline = Request::fromWorkerPayload(['body' => '{"a":1}']);
        self::assertSame('{"a":1}', $inline->body());

        $tmp = tempnam(sys_get_temp_dir(), 'fium_body_');
        file_put_contents($tmp, 'from-file');
        $file = Request::fromWorkerPayload(['body_file' => $tmp]);

        try {
            self::assertSame('from-file', $file->body());
            self::assertSame('from-file', $file->body(), 'body is cached on second read');
        } finally {
            @unlink($tmp);
        }
    }

    public function test_query_string_parsing(): void
    {
        $r = Request::fromWorkerPayload(['query_string' => 'foo=bar&baz=qux']);
        self::assertSame('bar', $r->query('foo'));
        self::assertSame('qux', $r->query('baz'));
        self::assertNull($r->query('missing'));
        self::assertSame('default', $r->query('missing', 'default'));
    }

    public function test_header_normalization_and_lookup(): void
    {
        $r = Request::fromWorkerPayload(['headers' => [
            'content-type' => ['application/json; charset=utf-8'],
            'authorization' => ['Bearer abc.def'],
            'x-multi' => ['one', 'two'],
        ]]);

        self::assertSame('application/json; charset=utf-8', $r->header('Content-Type'));
        self::assertSame('application/json', $r->contentType());
        self::assertSame('abc.def', $r->bearerToken());
        self::assertSame('one', $r->header('x-multi'));
        self::assertNull($r->header('absent'));
    }

    public function test_content_type_returns_null_when_absent(): void
    {
        self::assertNull(Request::fromWorkerPayload([])->contentType());
    }

    public function test_bearer_token_requires_prefix(): void
    {
        self::assertNull(Request::fromWorkerPayload(['headers' => ['authorization' => ['Basic xx']]])->bearerToken());
        self::assertNull(Request::fromWorkerPayload(['headers' => ['authorization' => ['Bearer ']]])->bearerToken());
        self::assertSame('tok', Request::fromWorkerPayload(['headers' => ['authorization' => ['Bearer tok']]])->bearerToken());
    }

    public function test_cookie_lookup(): void
    {
        $r = Request::fromWorkerPayload(['cookies' => ['fium_session' => 'abc', 'tag' => 123]]);
        self::assertSame('abc', $r->cookie('fium_session'));
        // Non-string cookies are ignored.
        self::assertNull($r->cookie('tag'));
        self::assertNull($r->cookie('missing'));
    }

    public function test_route_params(): void
    {
        $r = Request::fromWorkerPayload(['matched_route' => 'show', 'route_params' => ['id' => '42']]);
        self::assertSame('show', $r->matchedRoute());
        self::assertSame('42', $r->routeParam('id'));
        self::assertNull($r->routeParam('missing'));
        self::assertSame('fallback', $r->routeParam('missing', 'fallback'));
    }

    public function test_json_parsing_skips_form_content_types(): void
    {
        $json = Request::fromWorkerPayload([
            'headers' => ['content-type' => ['application/json']],
            'body' => '{"a":1,"b":[1,2]}',
        ]);
        self::assertSame(['a' => 1, 'b' => [1, 2]], $json->json());

        $form = Request::fromWorkerPayload([
            'headers' => ['content-type' => ['application/x-www-form-urlencoded']],
            'body' => '{"a":1}',
        ]);
        self::assertNull($form->json());
    }

    public function test_json_returns_null_for_empty_body(): void
    {
        $r = Request::fromWorkerPayload(['headers' => ['content-type' => ['application/json']], 'body' => '']);
        self::assertNull($r->json());
    }

    public function test_urlencoded_form_parsing(): void
    {
        $r = Request::fromWorkerPayload([
            'headers' => ['content-type' => ['application/x-www-form-urlencoded']],
            'body' => 'name=Ada&role=admin',
        ]);
        self::assertSame(['name' => 'Ada', 'role' => 'admin'], $r->form());
    }

    public function test_multipart_form_parsing(): void
    {
        $boundary = '----FiumBoundary';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"email\"\r\n\r\n"
            . "a@b.com\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"avatar\"; filename=\"x.png\"\r\n"
            . "Content-Type: image/png\r\n\r\n"
            . "BINARY\r\n"
            . "--{$boundary}--\r\n";

        $r = Request::fromWorkerPayload([
            'headers' => ['content-type' => ["multipart/form-data; boundary={$boundary}"]],
            'body' => $body,
        ]);

        $form = $r->form();
        self::assertIsArray($form);
        self::assertSame('a@b.com', $form['email'] ?? null);
        // File fields are skipped.
        self::assertArrayNotHasKey('avatar', $form);
    }

    public function test_input_dispatches_by_content_type(): void
    {
        $json = Request::fromWorkerPayload([
            'headers' => ['content-type' => ['application/json']],
            'body' => '{"email":"a@b.com"}',
        ]);
        self::assertSame('a@b.com', $json->input('email'));
        self::assertNull($json->input('missing'));

        $form = Request::fromWorkerPayload([
            'headers' => ['content-type' => ['application/x-www-form-urlencoded']],
            'body' => 'email=c@d.com',
        ]);
        self::assertSame('c@d.com', $form->input('email'));
    }

    public function test_boolean_helper(): void
    {
        foreach (['1', 'true', 'yes', 'on', true, 1] as $truthy) {
            $r = Request::fromWorkerPayload([
                'headers' => ['content-type' => ['application/json']],
                'body' => json_encode(['flag' => $truthy]),
            ]);
            self::assertTrue($r->boolean('flag'), "truthy: {$truthy}");
        }

        foreach (['0', 'false', 'no', 'off'] as $falsy) {
            $r = Request::fromWorkerPayload([
                'headers' => ['content-type' => ['application/json']],
                'body' => json_encode(['flag' => $falsy]),
            ]);
            self::assertFalse($r->boolean('flag'), "falsy: {$falsy}");
        }
    }

    public function test_attributes_round_trip(): void
    {
        $r = Request::fromWorkerPayload([]);
        $r->setAttribute('custom', ['nested' => true]);
        self::assertSame(['nested' => true], $r->attribute('custom'));
        self::assertSame('fallback', $r->attribute('absent', 'fallback'));
    }

    public function test_session_csrf_token_is_generated_and_stable(): void
    {
        $session = new Session('sid', [], false, true);
        $r = Request::fromWorkerPayload([]);
        $r->setSession($session);

        $token = $r->csrfToken();
        self::assertNotEmpty($token);
        // Second call returns the stored token (no new generation).
        self::assertSame($token, $r->csrfToken());
        self::assertSame($token, $session->get('csrf_token'));

        $field = $r->csrfField();
        self::assertStringContainsString('type="hidden"', $field);
        self::assertStringContainsString('name="_token"', $field);
        self::assertStringContainsString($token, $field);
    }

    public function test_csrf_field_is_empty_without_session(): void
    {
        self::assertSame('', Request::fromWorkerPayload([])->csrfField());
    }

    public function test_all_returns_payload(): void
    {
        $payload = ['path' => '/x', 'custom' => 1];
        self::assertSame($payload, Request::fromWorkerPayload($payload)->all());
    }
}
