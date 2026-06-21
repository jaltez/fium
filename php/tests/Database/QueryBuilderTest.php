<?php

declare(strict_types=1);

namespace Fium\Tests\Database;

use Fium\Database\Connection;
use Fium\Database\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER, active INTEGER)'
        );
        $this->pdo->exec(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT)'
        );

        Connection::setPdo($this->pdo);
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    public function test_insert_and_get_all(): void
    {
        Connection::table('users')->insert(['name' => 'Ada', 'email' => 'ada@x.com', 'age' => 36, 'active' => 1]);
        Connection::table('users')->insert(['name' => 'Alan', 'email' => 'alan@x.com', 'age' => 41, 'active' => 0]);

        $rows = Connection::table('users')->orderBy('id')->get();

        self::assertCount(2, $rows);
        self::assertSame('Ada', $rows[0]['name']);
    }

    public function test_where_two_arg_equality_and_three_arg_operator(): void
    {
        Connection::table('users')->insert(['name' => 'Ada', 'email' => 'a@x.com', 'age' => 36, 'active' => 1]);
        Connection::table('users')->insert(['name' => 'Alan', 'email' => 'b@x.com', 'age' => 41, 'active' => 1]);

        $eq = Connection::table('users')->where('name', 'Ada')->first();
        self::assertNotNull($eq);
        self::assertSame('Ada', $eq['name']);

        $older = Connection::table('users')->where('age', '>', 36)->first();
        self::assertNotNull($older);
        self::assertSame('Alan', $older['name']);
    }

    public function test_or_where_combines_conditions(): void
    {
        Connection::table('users')->insert(['name' => 'Ada', 'email' => 'a', 'age' => 36, 'active' => 1]);
        Connection::table('users')->insert(['name' => 'Alan', 'email' => 'b', 'age' => 41, 'active' => 0]);

        $rows = Connection::table('users')->where('age', '>', 40)->orWhere('name', 'Ada')->orderBy('id')->get();

        self::assertCount(2, $rows);
    }

    public function test_where_in_and_empty_in(): void
    {
        Connection::table('users')->insert(['name' => 'Ada', 'email' => 'a', 'age' => 36, 'active' => 1]);

        self::assertCount(1, Connection::table('users')->whereIn('id', [1, 2, 3])->get());
        self::assertCount(0, Connection::table('users')->whereIn('id', [])->get(), 'empty IN matches nothing');
    }

    public function test_where_null_and_where_not_null(): void
    {
        Connection::table('users')->insert(['name' => 'NoMail', 'email' => null, 'age' => 1, 'active' => 1]);

        self::assertCount(1, Connection::table('users')->whereNull('email')->get());
        self::assertCount(0, Connection::table('users')->whereNotNull('email')->get());
    }

    public function test_limit_offset_and_count(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Connection::table('users')->insert(['name' => "u{$i}", 'email' => "u{$i}@x", 'age' => $i, 'active' => 1]);
        }

        self::assertSame(5, Connection::table('users')->count());
        self::assertCount(2, Connection::table('users')->limit(2)->get());
        self::assertCount(3, Connection::table('users')->limit(3)->offset(2)->get());
        self::assertTrue(Connection::table('users')->exists());
    }

    public function test_select_specific_columns(): void
    {
        Connection::table('users')->insert(['name' => 'Ada', 'email' => 'a', 'age' => 1, 'active' => 1]);

        $row = Connection::table('users')->select('id', 'name')->first();
        self::assertSame(['id', 'name'], array_keys($row ?? []));
    }

    public function test_join(): void
    {
        Connection::table('users')->insert(['name' => 'Ada', 'email' => 'a', 'age' => 1, 'active' => 1]);
        Connection::table('posts')->insert(['user_id' => 1, 'title' => 'Hello']);
        Connection::table('posts')->insert(['user_id' => 1, 'title' => 'World']);

        $rows = Connection::table('posts')
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->where('users.name', 'Ada')
            ->select('posts.title', 'users.name')
            ->orderBy('posts.id')
            ->get();

        self::assertCount(2, $rows);
        self::assertSame('Hello', $rows[0]['title']);
    }

    public function test_update_returns_affected_rows(): void
    {
        Connection::table('users')->insert(['name' => 'Ada', 'email' => 'a', 'age' => 1, 'active' => 1]);

        $affected = Connection::table('users')->where('name', 'Ada')->update(['active' => 0]);

        self::assertSame(1, $affected);
        $row = Connection::table('users')->where('name', 'Ada')->first();
        // SQLite returns native ints with emulate-prepares off.
        self::assertSame(0, (int) $row['active']);
    }

    public function test_delete(): void
    {
        Connection::table('users')->insert(['name' => 'Ada', 'email' => 'a', 'age' => 1, 'active' => 1]);

        $deleted = Connection::table('users')->where('name', 'Ada')->delete();

        self::assertSame(1, $deleted);
        self::assertSame(0, Connection::table('users')->count());
    }

    public function test_to_sql_and_bindings_are_compiled(): void
    {
        $builder = Connection::table('users')
            ->where('age', '>', 30)
            ->where('active', 1)
            ->orderBy('name')
            ->limit(10);

        $sql = $builder->toSql();

        self::assertStringContainsString('SELECT * FROM users', $sql);
        self::assertStringContainsString('WHERE', $sql);
        self::assertStringContainsString('ORDER BY name ASC', $sql);
        self::assertStringContainsString('LIMIT 10', $sql);

        self::assertCount(2, $builder->getBindings());
    }

    public function test_invalid_identifier_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Connection::table('users')->where('name; DROP TABLE users', 'x');
    }

    public function test_invalid_operator_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 3-arg form with a bogus operator.
        (new QueryBuilder($this->pdo, 'users'))->where('name', '~~', 'x');
    }

    public function test_insert_empty_row_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Connection::table('users')->insert([]);
    }
}
