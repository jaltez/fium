<?php

declare(strict_types=1);

namespace Fium\Tests\Database;

use Fium\Database\Connection;
use Fium\Tests\Fixtures\UserModel;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER, active INTEGER)'
        );

        Connection::setPdo($pdo);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    public function test_create_persists_and_assigns_id(): void
    {
        $user = UserModel::create(['name' => 'Ada', 'email' => 'ada@x.com', 'age' => 36, 'active' => 1]);

        self::assertTrue($user->exists());
        self::assertSame(1, $user->id);
        self::assertSame('Ada', $user->name);
        self::assertSame('Ada', $user->toArray()['name']);
    }

    public function test_find_returns_hydrated_model_or_null(): void
    {
        UserModel::create(['name' => 'Ada', 'email' => 'a', 'age' => 1, 'active' => 1]);

        $found = UserModel::find(1);
        self::assertInstanceOf(UserModel::class, $found);
        self::assertSame('Ada', $found->name);
        self::assertTrue($found->exists());

        self::assertNull(UserModel::find(999));
    }

    public function test_all_returns_every_row_as_models(): void
    {
        UserModel::create(['name' => 'Ada', 'email' => 'a', 'age' => 1, 'active' => 1]);
        UserModel::create(['name' => 'Alan', 'email' => 'b', 'age' => 2, 'active' => 1]);

        $all = UserModel::all();
        self::assertCount(2, $all);
        self::assertInstanceOf(UserModel::class, $all[0]);
    }

    public function test_save_inserts_then_updates(): void
    {
        $user = new UserModel(['name' => 'Ada', 'email' => 'a', 'age' => 36, 'active' => 1]);
        $user->save();

        self::assertTrue($user->exists());
        $id = $user->id;

        $user->name = 'Augusta';
        $user->save();

        $reloaded = UserModel::find($id);
        self::assertSame('Augusta', $reloaded->name);
    }

    public function test_delete_removes_record(): void
    {
        $user = UserModel::create(['name' => 'Ada', 'email' => 'a', 'age' => 1, 'active' => 1]);

        self::assertTrue($user->delete());
        self::assertFalse($user->exists());
        self::assertNull(UserModel::find(1));
    }

    public function test_delete_on_unsaved_model_is_noop(): void
    {
        $user = new UserModel();
        self::assertFalse($user->delete());
    }

    public function test_fill_respects_fillable_allowlist(): void
    {
        $user = new UserModel(['name' => 'Ada', 'email' => 'a', 'age' => 1, 'active' => 1, 'admin' => true]);

        self::assertSame('Ada', $user->name);
        self::assertNull($user->admin, 'non-fillable keys are ignored');
        self::assertArrayNotHasKey('admin', $user->toArray());
    }

    public function test_query_returns_builder_for_chaining(): void
    {
        UserModel::create(['name' => 'Ada', 'email' => 'a', 'age' => 36, 'active' => 1]);
        UserModel::create(['name' => 'Alan', 'email' => 'b', 'age' => 41, 'active' => 1]);

        $rows = UserModel::query()->where('age', '>', 40)->get();

        self::assertCount(1, $rows);
        self::assertSame('Alan', $rows[0]['name']);
    }

    public function test_model_without_table_throws(): void
    {
        $model = new class extends \Fium\Database\Model {
        };

        $this->expectException(\RuntimeException::class);
        $model->getTable();
    }
}
