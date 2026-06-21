<?php

declare(strict_types=1);

namespace Fium\Tests;

use Fium\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** @var list<string> env keys we touched, for cleanup */
    private array $touched = [];

    private string $fixtureA;
    private string $fixtureB;

    protected function setUp(): void
    {
        $this->resetConfig();

        // Unique keys per run avoid cross-test environment pollution.
        $stamp = bin2hex(random_bytes(4));
        $this->fixtureA = $this->writeEnv([
            "# a comment\n",
            "FIUM_TEST_CFG_{$stamp}_NAME=\"Ada Lovelace\"\n",
            "FIUM_TEST_CFG_{$stamp}_ROLE='admin'\n",
            "FIUM_TEST_CFG_{$stamp}_PORT=3000\n",
            "FIUM_TEST_CFG_{$stamp}_DEBUG=true\n",
            "MALFORMED_LINE_NO_EQUALS\n",
        ]);
        $this->fixtureB = $this->writeEnv([
            "FIUM_TEST_CFG_{$stamp}_NAME=FromFile\n",
        ]);

        $this->touched = [
            "FIUM_TEST_CFG_{$stamp}_NAME",
            "FIUM_TEST_CFG_{$stamp}_ROLE",
            "FIUM_TEST_CFG_{$stamp}_PORT",
            "FIUM_TEST_CFG_{$stamp}_DEBUG",
        ];
    }

    protected function tearDown(): void
    {
        $this->resetConfig();

        foreach ($this->touched as $key) {
            putenv($key); // remove from process environment
        }

        if (isset($this->fixtureA) && is_file($this->fixtureA)) {
            @unlink($this->fixtureA);
        }
        if (isset($this->fixtureB) && is_file($this->fixtureB)) {
            @unlink($this->fixtureB);
        }
    }

    public function test_load_env_parses_comments_quotes_and_values(): void
    {
        Config::loadEnv($this->fixtureA);
        self::assertTrue(Config::isLoaded());

        self::assertSame('Ada Lovelace', Config::get($this->touched[0]));
        self::assertSame('admin', Config::get($this->touched[1]));
        self::assertSame('3000', Config::get($this->touched[2]));
    }

    public function test_int_and_bool_accessors(): void
    {
        Config::loadEnv($this->fixtureA);

        self::assertSame(3000, Config::int($this->touched[2]));
        self::assertSame(99, Config::int('FIUM_ABSENT', 99));

        self::assertTrue(Config::bool($this->touched[3]));
        self::assertFalse(Config::bool('FIUM_ABSENT'));
        self::assertTrue(Config::bool('FIUM_ABSENT', true));
    }

    public function test_has_reports_presence(): void
    {
        Config::loadEnv($this->fixtureA);

        self::assertTrue(Config::has($this->touched[0]));
        self::assertFalse(Config::has('FIUM_DEFINITELY_ABSENT'));
    }

    public function test_get_returns_default_when_unset(): void
    {
        self::assertNull(Config::get('FIUM_ABSENT'));
        self::assertSame('fallback', Config::get('FIUM_ABSENT', 'fallback'));
    }

    public function test_load_env_is_a_noop_for_missing_file(): void
    {
        Config::loadEnv('/this/path/does/not/exist/.env');
        // isLoaded stays false because nothing was loaded
        self::assertFalse(Config::isLoaded());
    }

    public function test_real_environment_takes_precedence_over_file(): void
    {
        // Set the env var directly first, then load the file that also defines it.
        $key = $this->touched[0];
        putenv("{$key}=FromProcessEnv");

        Config::loadEnv($this->fixtureB);

        self::assertSame('FromProcessEnv', Config::get($key));

        putenv($key); // cleanup
    }

    // --- helpers ---

    /** @param list<string> $lines */
    private function writeEnv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fium_env_');
        file_put_contents($path, implode('', $lines));

        return $path;
    }

    private function resetConfig(): void
    {
        $ref = new \ReflectionClass(Config::class);

        $values = $ref->getProperty('values');
        $values->setValue(null, []);

        $loaded = $ref->getProperty('loaded');
        $loaded->setValue(null, false);
    }
}
