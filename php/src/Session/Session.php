<?php

declare(strict_types=1);

namespace Fium\Session;

final class Session
{
    private const FLASH_DATA_KEY = '__fium_flash';
    private const REMEMBER_ME_KEY = '__fium_remember_me';

    /** @param array<string, mixed> $data */
    public function __construct(
        private string $id,
        private array $data = [],
        private bool $dirty = false,
        private bool $isNew = false,
        private bool $regenerate = false,
        private ?string $previousId = null,
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

    public function needsRegeneration(): bool
    {
        return $this->regenerate;
    }

    public function previousId(): ?string
    {
        return $this->previousId;
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
        $this->removeFlashKey($key);
        $this->dirty = true;
    }

    public function flash(string $key, mixed $value): void
    {
        $this->data[$key] = $value;

        $state = $this->flashState();
        if (!in_array($key, $state['new'], true)) {
            $state['new'][] = $key;
        }
        $state['old'] = array_values(array_filter(
            $state['old'],
            static fn (string $flashKey): bool => $flashKey !== $key
        ));

        $this->writeFlashState($state);
        $this->dirty = true;
    }

    public function ageFlashData(): void
    {
        $state = $this->flashState();
        $changed = false;

        foreach ($state['old'] as $key) {
            if (array_key_exists($key, $this->data)) {
                unset($this->data[$key]);
                $changed = true;
            }
        }

        $nextState = [
            'old' => $state['new'],
            'new' => [],
        ];

        if ($nextState['old'] === [] && $nextState['new'] === []) {
            if (array_key_exists(self::FLASH_DATA_KEY, $this->data)) {
                unset($this->data[self::FLASH_DATA_KEY]);
                $changed = true;
            }
        } else {
            $currentState = $this->data[self::FLASH_DATA_KEY] ?? null;

            if ($currentState !== $nextState) {
                $this->data[self::FLASH_DATA_KEY] = $nextState;
                $changed = true;
            }
        }

        if ($changed) {
            $this->dirty = true;
        }
    }

    public function regenerate(): void
    {
        $this->regenerate = true;
    }

    public function remember(): void
    {
        $this->data[self::REMEMBER_ME_KEY] = true;
        $this->dirty = true;
    }

    public function forgetRemember(): void
    {
        if (!array_key_exists(self::REMEMBER_ME_KEY, $this->data)) {
            return;
        }

        unset($this->data[self::REMEMBER_ME_KEY]);
        $this->dirty = true;
    }

    public function remembers(): bool
    {
        return ($this->data[self::REMEMBER_ME_KEY] ?? false) === true;
    }

    public function rotateTo(string $sessionId): void
    {
        if ($sessionId === $this->id) {
            $this->regenerate = false;

            return;
        }

        $this->previousId ??= $this->id;
        $this->id = $sessionId;
        $this->dirty = true;
        $this->isNew = true;
        $this->regenerate = false;
    }

    public function clearPreviousId(): void
    {
        $this->previousId = null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @return array{old: list<string>, new: list<string>}
     */
    private function flashState(): array
    {
        $state = $this->data[self::FLASH_DATA_KEY] ?? null;

        if (!is_array($state)) {
            return ['old' => [], 'new' => []];
        }

        return [
            'old' => $this->normalizeFlashKeys($state['old'] ?? []),
            'new' => $this->normalizeFlashKeys($state['new'] ?? []),
        ];
    }

    /**
     * @param array{old: list<string>, new: list<string>} $state
     */
    private function writeFlashState(array $state): void
    {
        if ($state['old'] === [] && $state['new'] === []) {
            unset($this->data[self::FLASH_DATA_KEY]);

            return;
        }

        $this->data[self::FLASH_DATA_KEY] = $state;
    }

    private function removeFlashKey(string $key): void
    {
        $state = $this->flashState();
        $nextState = [
            'old' => array_values(array_filter(
                $state['old'],
                static fn (string $flashKey): bool => $flashKey !== $key
            )),
            'new' => array_values(array_filter(
                $state['new'],
                static fn (string $flashKey): bool => $flashKey !== $key
            )),
        ];

        $this->writeFlashState($nextState);
    }

    /**
     * @param mixed $keys
     * @return list<string>
     */
    private function normalizeFlashKeys(mixed $keys): array
    {
        if (!is_array($keys)) {
            return [];
        }

        $normalized = [];

        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $normalized[] = $key;
        }

        return array_values(array_unique($normalized));
    }
}