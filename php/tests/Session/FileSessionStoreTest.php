<?php

declare(strict_types=1);

namespace Fium\Tests\Session;

use Fium\Session\FileSessionStore;
use Fium\Session\Session;
use PHPUnit\Framework\TestCase;

final class FileSessionStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fium_sessions_' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function test_load_null_id_yields_new_session(): void
    {
        $store = new FileSessionStore($this->dir);
        $session = $store->load(null);

        self::assertTrue($session->isNew());
        self::assertNotEmpty($session->id());
    }

    public function test_load_unknown_id_yields_new_session_with_that_id(): void
    {
        $store = new FileSessionStore($this->dir);
        $validId = bin2hex(random_bytes(20));

        $session = $store->load($validId);
        self::assertSame($validId, $session->id());
        self::assertSame([], $session->all());
    }

    public function test_invalid_session_ids_are_rejected_and_replaced(): void
    {
        $store = new FileSessionStore($this->dir);

        // Too short — rejected, a fresh id is generated instead.
        $short = $store->load('short');
        self::assertNotSame('short', $short->id());
        self::assertTrue($short->isNew());

        // Non-hex — rejected, a fresh id is generated instead.
        $nonHex = $store->load(str_repeat('z', 40));
        self::assertNotSame(str_repeat('z', 40), $nonHex->id());
        self::assertTrue($nonHex->isNew());
    }

    public function test_save_and_load_roundtrip(): void
    {
        $store = new FileSessionStore($this->dir);

        $session = $store->load(null);
        $session->put('user_id', 7);
        $session->put('theme', 'dark');

        $store->save($session);

        $reloaded = $store->load($session->id());
        self::assertSame(7, $reloaded->get('user_id'));
        self::assertSame('dark', $reloaded->get('theme'));
        self::assertFalse($reloaded->isNew());
    }

    public function test_delete_removes_session_file(): void
    {
        $store = new FileSessionStore($this->dir);

        $session = $store->load(null);
        $store->save($session);

        $path = $this->dir . '/' . $session->id() . '.json';
        self::assertFileExists($path);

        $store->delete($session->id());
        self::assertFileDoesNotExist($path);
    }

    public function test_regenerate_rotates_id_when_requested(): void
    {
        $store = new FileSessionStore($this->dir);

        $session = $store->load(null);
        $originalId = $session->id();
        $session->regenerate();

        $store->regenerate($session);

        self::assertNotSame($originalId, $session->id());
        self::assertSame($originalId, $session->previousId());
        self::assertTrue($session->isNew());
    }

    public function test_regenerate_noop_when_not_requested(): void
    {
        $store = new FileSessionStore($this->dir);
        $session = $store->load(null);
        $id = $session->id();

        $store->regenerate($session);

        self::assertSame($id, $session->id());
    }

    public function test_corrupt_session_file_yields_empty_session(): void
    {
        $id = bin2hex(random_bytes(20));
        file_put_contents($this->dir . '/' . $id . '.json', 'not-json{');

        $store = new FileSessionStore($this->dir);
        $session = $store->load($id);

        self::assertSame([], $session->all());
    }
}
