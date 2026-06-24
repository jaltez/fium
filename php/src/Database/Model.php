<?php

declare(strict_types=1);

namespace Fium\Database;

/**
 * Lightweight active-record base built on {@see QueryBuilder} and {@see Connection}.
 *
 * Subclasses declare a `$table`, a `$primaryKey`, and a `$fillable` allow-list,
 * then get `find`, `all`, `create`, `save`, and `delete` for free. For anything
 * more complex, {@see query()} returns a fresh query builder to chain on.
 *
 * Like the rest of the framework this ships with no external dependencies.
 *
 * @phpstan-consistent-constructor Subclasses promise a constructor compatible with
 *   `__construct(array $attributes = [])`, so `new static()` in the factories is safe.
 */
abstract class Model
{
    /** The database table the model maps to. Subclasses must override this. */
    protected string $table = '';

    /** The primary key column. */
    protected string $primaryKey = 'id';

    /** @var list<string> Mass-assignment allow-list. Only these keys may be set via fill()/create(). */
    protected array $fillable = [];

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** Whether the row exists in the database (drives insert vs update in save()). */
    protected bool $exists = false;

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /** Begin a query builder for this model's table. */
    public static function query(): QueryBuilder
    {
        return Connection::table((new static())->getTable());
    }

    /**
     * Find a single record by primary key.
     *
     * @param int|string $id
     */
    public static function find(int|string $id): ?static
    {
        $instance = new static();
        $row = static::query()
            ->where($instance->primaryKey, $id)
            ->first();

        return $row === null ? null : static::hydrate($row);
    }

    /**
     * Return every record as hydrated models.
     *
     * @return list<static>
     */
    public static function all(): array
    {
        $rows = static::query()->get();

        return array_map(static fn (array $row): static => static::hydrate($row), $rows);
    }

    /**
     * Create and persist a new record in one step.
     *
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * Mass-assign fillable attributes.
     *
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->fillable, true)) {
                $this->attributes[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Persist the model: INSERT if new, UPDATE if it already exists.
     */
    public function save(): bool
    {
        $table = $this->getTable();

        if ($this->exists) {
            $id = $this->attributes[$this->primaryKey] ?? null;
            if ($id === null) {
                throw new \RuntimeException('Cannot update a model without a primary key value.');
            }

            $updates = $this->attributes;
            unset($updates[$this->primaryKey]);

            if ($updates === []) {
                return true;
            }

            Connection::table($table)
                ->where($this->primaryKey, $id)
                ->update($updates);

            return true;
        }

        $id = Connection::table($table)->insert($this->attributes);

        // SQLite/MySQL return the auto-increment id; keep it when the model
        // didn't supply its own primary key.
        if (!isset($this->attributes[$this->primaryKey]) && $id !== '' && ctype_digit($id)) {
            $this->attributes[$this->primaryKey] = (int) $id;
        }

        $this->exists = true;

        return true;
    }

    /**
     * Delete the record from the database.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $id = $this->attributes[$this->primaryKey] ?? null;
        if ($id === null) {
            return false;
        }

        $affected = Connection::table($this->getTable())
            ->where($this->primaryKey, $id)
            ->delete();

        if ($affected > 0) {
            $this->exists = false;

            return true;
        }

        return false;
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function getTable(): string
    {
        if ($this->table === '') {
            throw new \RuntimeException(static::class . ' must define a protected $table property.');
        }

        return $this->table;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Build a model instance from a raw database row and mark it as persisted.
     *
     * @param array<string, mixed> $row
     */
    protected static function hydrate(array $row): static
    {
        $model = new static();
        $model->setRawAttributes($row);
        $model->exists = true;

        return $model;
    }

    /**
     * Set attributes bypassing the fillable allow-list (used for hydration).
     *
     * @param array<string, mixed> $attributes
     */
    protected function setRawAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }
}
