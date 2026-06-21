<?php

declare(strict_types=1);

namespace Fium\Tests;

use Fium\Application;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private string $appDir;

    protected function setUp(): void
    {
        $this->resetApplicationSingleton();
        $this->appDir = sys_get_temp_dir() . '/fium_app_' . bin2hex(random_bytes(4));
        @mkdir($this->appDir, 0777, true);
        file_put_contents($this->appDir . '/users.php', "<?php\n\nreturn [];\n");
    }

    protected function tearDown(): void
    {
        $this->resetApplicationSingleton();

        if (isset($this->appDir) && is_dir($this->appDir)) {
            foreach (glob($this->appDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->appDir);
        }
    }

    public function test_boot_resolves_base_dir(): void
    {
        $app = $this->boot('[]');

        self::assertSame(realpath($this->appDir), realpath($app->baseDir()));
    }

    public function test_routes_file_must_return_array(): void
    {
        // Deliberately not an array.
        file_put_contents($this->appDir . '/routes.php', "<?php\n\nreturn 'not-an-array';\n");

        $this->expectException(\RuntimeException::class);
        Application::boot($this->appDir . '/routes.php');
    }

    public function test_concise_closure_route_dispatches(): void
    {
        $app = $this->boot(<<<'PHP'
[
    "GET /ping" => fn($r) => \Fium\Runtime\Response::json(['pong' => true]),
]
PHP);

        $wire = $app->handleWorkerRequest([
            'protocol_version' => 1,
            'request_id' => 'r1',
            'matched_route' => 'get_ping',
            'method' => 'GET',
            'path' => '/ping',
        ]);

        self::assertSame(200, $wire['status']);
        self::assertSame('r1', $wire['request_id']);
        self::assertStringContainsString('"pong":true', $wire['body']);
    }

    public function test_unknown_matched_route_returns_404(): void
    {
        $app = $this->boot('[]');

        $wire = $app->handleWorkerRequest([
            'protocol_version' => 1,
            'request_id' => 'r2',
            'matched_route' => 'nope',
            'method' => 'GET',
            'path' => '/missing',
        ]);

        self::assertSame(404, $wire['status']);
        $decoded = json_decode($wire['body'], true);
        self::assertSame('route_not_resolved', $decoded['error']);
    }

    public function test_handler_exception_is_caught_and_does_not_leak(): void
    {
        $app = $this->boot(<<<'PHP'
[
    "GET /boom" => fn($r) => throw new \RuntimeException('sensitive internal detail'),
]
PHP);

        $wire = $app->handleWorkerRequest([
            'protocol_version' => 1,
            'request_id' => 'r3',
            'matched_route' => 'get_boom',
            'method' => 'GET',
            'path' => '/boom',
        ]);

        self::assertSame(500, $wire['status']);
        $decoded = json_decode($wire['body'], true);
        self::assertSame('internal_server_error', $decoded['error']);
        self::assertArrayNotHasKey('message', $decoded);
        self::assertStringNotContainsString('sensitive internal detail', $wire['body']);
    }

    public function test_route_group_prefixes_paths_and_applies_middleware(): void
    {
        $app = $this->boot(<<<'PHP'
[
    "middleware" => ["add-powered-by"],
    "api" => [
        "prefix" => "/api",
        "middleware" => ["add-powered-by"],
        "routes" => [
            "GET /things" => fn($r) => \Fium\Runtime\Response::json(['ok' => true]),
        ],
    ],
]
PHP);

        $wire = $app->handleWorkerRequest([
            'protocol_version' => 1,
            'request_id' => 'r4',
            'matched_route' => 'get_api_things',
            'method' => 'GET',
            'path' => '/api/things',
        ]);

        self::assertSame(200, $wire['status']);
        // Global + group middleware both ran.
        self::assertContains('add-powered-by', $wire['headers']['x-fium-middleware']);
    }

    public function test_unknown_middleware_alias_throws_at_boot(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->boot(<<<'PHP'
[
    "GET /x" => ["middleware" => ["does-not-exist"], "handler" => fn($r) => \Fium\Runtime\Response::json([])],
]
PHP);
    }

    public function test_named_route_url_generation(): void
    {
        $app = $this->boot(<<<'PHP'
[
    "GET /users/{id}" => ["name" => "users.show", "handler" => fn($r) => \Fium\Runtime\Response::json([])],
]
PHP);

        self::assertSame('/users/42', Application::url('users.show', ['id' => 42]));
        self::assertSame('/users/' . rawurlencode('a b'), Application::url('users.show', ['id' => 'a b']));
    }

    public function test_url_throws_for_unknown_route_name(): void
    {
        $this->boot('[]');

        $this->expectException(\RuntimeException::class);
        Application::url('missing.route');
    }

    public function test_url_throws_for_missing_parameter(): void
    {
        $this->boot(<<<'PHP'
[
    "GET /posts/{slug}" => ["name" => "posts.show", "handler" => fn($r) => \Fium\Runtime\Response::json([])],
]
PHP);

        $this->expectException(\RuntimeException::class);
        Application::url('posts.show');
    }

    public function test_url_throws_when_not_booted(): void
    {
        $this->resetApplicationSingleton();

        $this->expectException(\RuntimeException::class);
        Application::url('anything');
    }

    public function test_boot_manifest_has_protocol_and_routes(): void
    {
        $app = $this->boot(<<<'PHP'
[
    "middleware" => ["add-powered-by"],
    "GET /" => ["name" => "home", "middleware" => [], "handler" => fn($r) => \Fium\Runtime\Response::json([])],
    "GET /ping" => fn($r) => \Fium\Runtime\Response::json([]),
]
PHP);

        $manifest = json_decode($app->bootManifest(), true);

        self::assertSame(1, $manifest['protocol_version']);
        self::assertSame('boot', $manifest['type']);
        self::assertCount(2, $manifest['routes']);

        $names = array_column($manifest['routes'], 'name');
        self::assertContains('home', $names);
        self::assertContains('get_ping', $names);

        // Global middleware is attached to every route.
        foreach ($manifest['routes'] as $route) {
            self::assertContains('add-powered-by', $route['middleware']);
        }
    }

    // --- helpers ---

    /**
     * Boot an Application whose routes file returns the given array source.
     * The source is wrapped in a real PHP file so closures work natively.
     */
    private function boot(string $routesArraySource): Application
    {
        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $routesArraySource . ";\n";
        file_put_contents($this->appDir . '/routes.php', $php);

        return Application::boot($this->appDir . '/routes.php');
    }

    private function resetApplicationSingleton(): void
    {
        $ref = new \ReflectionClass(Application::class);
        $instance = $ref->getProperty('instance');
        $instance->setValue(null, null);
    }
}
