<?php

declare(strict_types=1);

namespace Fium\Tests\Auth;

use Fium\Auth\FileUserStore;
use PHPUnit\Framework\TestCase;

final class FileUserStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/fium_users_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);
        $this->path = $dir . '/users.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
        @rmdir(dirname($this->path));
    }

    public function test_missing_file_yields_empty_store(): void
    {
        $store = new FileUserStore($this->path);
        self::assertNull($store->findById(1));
        self::assertNull($store->findByEmail('a@b.com'));
    }

    public function test_create_persists_and_assigns_incrementing_ids(): void
    {
        $store = new FileUserStore($this->path);

        $one = $store->create('a@b.com', 'HASH_ONE', 'user');
        $two = $store->create('c@d.com', 'HASH_TWO', 'admin');

        self::assertSame(1, $one['id']);
        self::assertSame(2, $two['id']);

        // A fresh store instance reads the persisted file.
        $reloaded = new FileUserStore($this->path);
        self::assertSame($one, $reloaded->findById(1));
        self::assertSame($two, $reloaded->findById(2));
    }

    public function test_create_rejects_duplicate_email(): void
    {
        $store = new FileUserStore($this->path);
        self::assertNotNull($store->create('dup@example.com', 'H'));
        self::assertNull($store->create('DUP@example.com', 'H'), 'email lookup is case-insensitive');
    }

    public function test_find_by_email_is_case_insensitive(): void
    {
        $store = new FileUserStore($this->path);
        $store->create('Ada@Example.com', 'H');

        self::assertNotNull($store->findByEmail('ada@example.com'));
        self::assertNotNull($store->findByEmail('ADA@EXAMPLE.COM'));
    }

    public function test_update_password_persists(): void
    {
        $store = new FileUserStore($this->path);
        $created = $store->create('a@b.com', 'OLD');

        $updated = $store->updatePassword($created['id'], 'NEW');

        self::assertNotNull($updated);
        self::assertSame('NEW', $updated['password_hash']);
        self::assertSame('NEW', (new FileUserStore($this->path))->findById(1)['password_hash']);
    }

    public function test_update_password_returns_null_for_missing_user(): void
    {
        $store = new FileUserStore($this->path);
        self::assertNull($store->updatePassword(999, 'NEW'));
    }

    public function test_mark_email_verified_sets_timestamp(): void
    {
        $store = new FileUserStore($this->path);
        $created = $store->create('a@b.com', 'H');

        $verified = $store->markEmailVerified($created['id']);

        self::assertNotNull($verified);
        self::assertNotEmpty($verified['email_verified_at']);
    }

    public function test_increment_token_version_increments(): void
    {
        $store = new FileUserStore($this->path);
        $created = $store->create('a@b.com', 'H');

        self::assertSame(1, $store->incrementTokenVersion($created['id'])['token_version']);
        self::assertSame(2, $store->incrementTokenVersion($created['id'])['token_version']);
    }

    public function test_file_must_return_an_array(): void
    {
        $bad = $this->path . '.bad';
        file_put_contents($bad, "<?php return 'not-an-array';");

        try {
            $this->expectException(\RuntimeException::class);
            new FileUserStore($bad);
        } finally {
            @unlink($bad);
        }
    }
}
