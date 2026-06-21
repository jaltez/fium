<?php

declare(strict_types=1);

namespace Fium\Tests\Middleware;

use Fium\Auth\ApiTokenService;
use Fium\Auth\Authenticator;
use Fium\Middleware\AddPoweredByHeader;
use Fium\Middleware\AuthenticateBearer;
use Fium\Middleware\AuthenticateSession;
use Fium\Middleware\Cors;
use Fium\Middleware\RateLimit;
use Fium\Middleware\RequireJsonAccept;
use Fium\Middleware\RequireRole;
use Fium\Middleware\SecurityHeaders;
use Fium\Middleware\VerifyCsrf;
use Fium\Runtime\Request;
use Fium\Runtime\Response;
use Fium\Session\Session;
use Fium\Tests\Fixtures\InMemoryUserStore;
use PHPUnit\Framework\TestCase;

final class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        // RateLimit keeps a static bucket map; isolate each test.
        $this->resetRateLimitBuckets();
    }

    protected function tearDown(): void
    {
        $this->resetRateLimitBuckets();
    }

    public function test_security_headers_added(): void
    {
        $response = (new SecurityHeaders())->handle($this->request(), $this->nextOk());

        self::assertSame('nosniff', $this->header($response, 'x-content-type-options'));
        self::assertSame('DENY', $this->header($response, 'x-frame-options'));
        self::assertSame('strict-origin-when-cross-origin', $this->header($response, 'referrer-policy'));
        self::assertSame('0', $this->header($response, 'x-xss-protection'));
    }

    public function test_add_powered_by_header(): void
    {
        $response = (new AddPoweredByHeader())->handle($this->request(), $this->nextOk());
        self::assertSame('add-powered-by', $this->header($response, 'x-fium-middleware'));
    }

    public function test_require_json_accept_rejects_html(): void
    {
        $request = $this->request(['headers' => ['accept' => ['text/html']]]);
        $response = (new RequireJsonAccept())->handle($request, $this->nextOk());

        self::assertSame(406, $this->statusCode($response));
    }

    public function test_require_json_accept_allows_json_wildcard_and_absent(): void
    {
        $mw = new RequireJsonAccept();

        self::assertSame(200, $this->statusCode($mw->handle($this->request(['headers' => ['accept' => ['application/json']]]), $this->nextOk())));
        self::assertSame(200, $this->statusCode($mw->handle($this->request(['headers' => ['accept' => ['*/*']]]), $this->nextOk())));
        self::assertSame(200, $this->statusCode($mw->handle($this->request(), $this->nextOk())));
    }

    public function test_cors_preflight_returns_204_with_headers(): void
    {
        $request = $this->request(['method' => 'OPTIONS']);
        $response = (new Cors())->handle($request, $this->nextOk());

        self::assertSame(204, $this->statusCode($response));
        self::assertSame('*', $this->header($response, 'access-control-allow-origin'));
        self::assertNotEmpty($this->header($response, 'access-control-allow-methods'));
        self::assertNotEmpty($this->header($response, 'access-control-max-age'));
    }

    public function test_cors_adds_headers_to_normal_response(): void
    {
        $response = (new Cors())->handle($this->request(), $this->nextOk());

        self::assertSame(200, $this->statusCode($response));
        self::assertSame('*', $this->header($response, 'access-control-allow-origin'));
    }

    public function test_require_role_denies_without_user(): void
    {
        $response = (new RequireRole('admin'))->handle($this->request(), $this->nextOk());
        self::assertSame(401, $this->statusCode($response));
    }

    public function test_require_role_forbids_wrong_role(): void
    {
        $user = new \Fium\Auth\User(1, 'a@b.com', 'user');
        $request = $this->request();
        $request->setUser($user);

        $response = (new RequireRole('admin'))->handle($request, $this->nextOk());
        self::assertSame(403, $this->statusCode($response));
    }

    public function test_require_role_passes_correct_role(): void
    {
        $user = new \Fium\Auth\User(1, 'a@b.com', 'admin');
        $request = $this->request();
        $request->setUser($user);

        $response = (new RequireRole('admin'))->handle($request, $this->nextOk());
        self::assertSame(200, $this->statusCode($response));
    }

    public function test_authenticate_session_requires_session_and_authenticator(): void
    {
        // No session attribute set -> 500
        $response = (new AuthenticateSession())->handle($this->request(), $this->nextOk());
        self::assertSame(500, $this->statusCode($response));
    }

    public function test_authenticate_session_rejects_guest(): void
    {
        $auth = Authenticator::empty();
        $session = new Session('sid', [], false, false);
        $request = $this->request();
        $request->setSession($session);
        $request->setAttribute('authenticator', $auth);

        $response = (new AuthenticateSession())->handle($request, $this->nextOk());
        self::assertSame(401, $this->statusCode($response));
    }

    public function test_authenticate_session_passes_authenticated_user(): void
    {
        $store = new InMemoryUserStore();
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $store->seed(['id' => 1, 'email' => 'a@b.com', 'password_hash' => $hash, 'role' => 'user', 'email_verified_at' => null, 'token_version' => 0]);
        $auth = Authenticator::fromStore($store);

        $session = new Session('sid', ['auth_user_id' => 1], false, false);
        $request = $this->request();
        $request->setSession($session);
        $request->setAttribute('authenticator', $auth);

        $response = (new AuthenticateSession())->handle($request, $this->nextOk());
        self::assertSame(200, $this->statusCode($response));
        self::assertNotNull($request->user());
    }

    public function test_authenticate_bearer_requires_token_service(): void
    {
        $response = (new AuthenticateBearer())->handle($this->request(), $this->nextOk());
        self::assertSame(500, $this->statusCode($response));
    }

    public function test_authenticate_bearer_rejects_missing_token(): void
    {
        $request = $this->request();
        $request->setAttribute('token_service', $this->tokenService());

        $response = (new AuthenticateBearer())->handle($request, $this->nextOk());
        self::assertSame(401, $this->statusCode($response));
    }

    public function test_authenticate_bearer_rejects_invalid_token(): void
    {
        $request = $this->request(['headers' => ['authorization' => ['Bearer not-a-real-token']]]);
        $request->setAttribute('token_service', $this->tokenService());

        $response = (new AuthenticateBearer())->handle($request, $this->nextOk());
        self::assertSame(401, $this->statusCode($response));
    }

    public function test_authenticate_bearer_accepts_valid_token(): void
    {
        [$service, $user] = $this->tokenServiceWithUser();
        $token = $service->issue($user);

        $request = $this->request(['headers' => ['authorization' => ["Bearer {$token}"]]]);
        $request->setAttribute('token_service', $service);

        $response = (new AuthenticateBearer())->handle($request, $this->nextOk());
        self::assertSame(200, $this->statusCode($response));
        self::assertNotNull($request->user());
    }

    public function test_csrf_requires_session(): void
    {
        $response = (new VerifyCsrf())->handle($this->request(), $this->nextOk());
        self::assertSame(500, $this->statusCode($response));
    }

    public function test_csrf_rejects_mismatch(): void
    {
        $session = new Session('sid', ['csrf_token' => 'server-secret'], false, false);
        $request = $this->request(['headers' => ['x-csrf-token' => ['wrong']]]);
        $request->setSession($session);

        $response = (new VerifyCsrf())->handle($request, $this->nextOk());
        self::assertSame(419, $this->statusCode($response));
    }

    public function test_csrf_accepts_header_token(): void
    {
        $session = new Session('sid', ['csrf_token' => 'server-secret'], false, false);
        $request = $this->request(['headers' => ['x-csrf-token' => ['server-secret']]]);
        $request->setSession($session);

        $response = (new VerifyCsrf())->handle($request, $this->nextOk());
        self::assertSame(200, $this->statusCode($response));
    }

    public function test_csrf_accepts_body_token(): void
    {
        $session = new Session('sid', ['csrf_token' => 'server-secret'], false, false);
        $request = $this->request([
            'headers' => ['content-type' => ['application/x-www-form-urlencoded']],
            'body' => '_token=server-secret&name=Ada',
        ]);
        $request->setSession($session);

        $response = (new VerifyCsrf())->handle($request, $this->nextOk());
        self::assertSame(200, $this->statusCode($response));
    }

    public function test_rate_limit_allows_under_cap_and_adds_headers(): void
    {
        $mw = new RateLimit('3');

        for ($i = 0; $i < 3; $i++) {
            $response = $mw->handle($this->request(['client_ip' => '1.1.1.1']), $this->nextOk());
            self::assertSame(200, $this->statusCode($response), "request {$i} should pass");
        }

        self::assertSame('3', $this->header($response, 'x-ratelimit-limit'));
    }

    public function test_rate_limit_blocks_over_cap(): void
    {
        $mw = new RateLimit('2');

        $mw->handle($this->request(['client_ip' => '2.2.2.2']), $this->nextOk());
        $mw->handle($this->request(['client_ip' => '2.2.2.2']), $this->nextOk());
        $blocked = $mw->handle($this->request(['client_ip' => '2.2.2.2']), $this->nextOk());

        self::assertSame(429, $this->statusCode($blocked));
        self::assertNotNull($this->header($blocked, 'retry-after'));
    }

    public function test_rate_limit_buckets_are_isolated_per_ip(): void
    {
        $mw = new RateLimit('1');

        $mw->handle($this->request(['client_ip' => '3.3.3.3']), $this->nextOk());
        self::assertSame(429, $this->statusCode($mw->handle($this->request(['client_ip' => '3.3.3.3']), $this->nextOk())));

        // A different IP gets its own bucket.
        self::assertSame(200, $this->statusCode($mw->handle($this->request(['client_ip' => '4.4.4.4']), $this->nextOk())));
    }

    // --- helpers ---

    /** @param array<string, mixed> $payload */
    private function request(array $payload = []): Request
    {
        return Request::fromWorkerPayload($payload);
    }

    private function nextOk(): callable
    {
        return static fn (Request $r): Response => Response::json(['ok' => true]);
    }

    private function tokenService(): ApiTokenService
    {
        [$service] = $this->tokenServiceWithUser();
        return $service;
    }

    /** @return array{0: ApiTokenService, 1: \Fium\Auth\User} */
    private function tokenServiceWithUser(): array
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $store = new InMemoryUserStore();
        $store->seed(['id' => 5, 'email' => 'tok@example.com', 'password_hash' => $hash, 'role' => 'user', 'email_verified_at' => null, 'token_version' => 0]);
        $auth = Authenticator::fromStore($store);
        $user = $auth->userById(5);
        assert($user !== null);

        return [new ApiTokenService($auth, 'test-secret-not-for-production'), $user];
    }

    private function resetRateLimitBuckets(): void
    {
        $ref = new \ReflectionClass(RateLimit::class);
        $buckets = $ref->getProperty('buckets');
        $buckets->setValue(null, []);
    }

    private function statusCode(Response $r): int
    {
        return (new \ReflectionClass(Response::class))->getProperty('status')->getValue($r);
    }

    private function header(Response $r, string $name): ?string
    {
        $headers = (new \ReflectionClass(Response::class))->getProperty('headers')->getValue($r);
        $values = $headers[strtolower($name)] ?? null;
        return is_array($values) && $values !== [] ? (string) $values[0] : null;
    }
}
