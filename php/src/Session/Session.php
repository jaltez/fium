<?php

declare(strict_types=1);

namespace Fium\Session;

final class Session
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private string $id,
        private array $data = [],
        private bool $dirty = false,
        private bool $isNew = false,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
        $this->dirty = true;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}