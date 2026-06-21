<?php

declare(strict_types=1);

namespace Fium\Database;

use Fium\Config;

/**
 * Thin singleton around a single PDO connection.
 *
 * The connection is configured lazily from FIUM_DB_* environment variables on
 * first use, so applications that never touch a database pay no cost. Tests and
 * advanced setups can inject an explicit PDO instance via {@see setPdo()}.
 *
 * This lives inside the embedded framework (no external dependencies) so it
 * works in the self-contained single-binary runtime.
 */
final class Connection
{
    private static ?\PDO $pdo = null;
    private static ?string $dsn = null;
    private static ?string $user = null;
    private static ?string $password = null;
    /** @var array<int|string, mixed> */
    private static array $options = [];

    /**
     * Configure the connection explicitly. The actual PDO instance is created
     * lazily on first use.
     *
     * @param array<int|string, mixed> $options
     */
    public static function configure(string $dsn, ?string $user = null, ?string $password = null, array $options = []): void
    {
        self::$dsn = $dsn;
        self::$user = $user;
        self::$password = $password;
        self::$options = $options;
        self::$pdo = null;
    }

    /**
     * Inject (or clear) a PDO instance directly. Useful for tests and for
     * sharing an existing connection.
     */
    public static function setPdo(?\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Return the active PDO connection, creating it from configuration on demand.
     */
    public static function pdo(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $dsn = self::$dsn ?? Config::get('FIUM_DB_DSN');

        if ($dsn === null || $dsn === '') {
            throw new \RuntimeException(
                'Database is not configured. Set FIUM_DB_DSN or call Connection::configure() / Connection::setPdo().'
            );
        }

        $user = self::$user ?? Config::get('FIUM_DB_USER');
        $password = self::$password ?? Config::get('FIUM_DB_PASS');

        $options = self::$options + [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        self::$pdo = new \PDO($dsn, $user, $password, $options);

        return self::$pdo;
    }

    /**
     * Begin a query against a table.
     */
    public static function table(string $name): QueryBuilder
    {
        return new QueryBuilder(self::pdo(), $name);
    }

    /**
     * Execute a raw SQL statement with bound parameters.
     *
     * @param array<string, mixed> $params
     */
    public static function statement(string $sql, array $params = []): \PDOStatement
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * Run a callback within a transaction, committing on success and rolling
     * back on any throwable.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $throwable) {
            $pdo->rollBack();

            throw $throwable;
        }
    }

    /**
     * Reset all configured state. Intended for tests.
     */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$dsn = null;
        self::$user = null;
        self::$password = null;
        self::$options = [];
    }
}
