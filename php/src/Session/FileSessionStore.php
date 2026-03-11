<?php

declare(strict_types=1);

namespace Fium\Session;

final class FileSessionStore implements SessionStore
{
    public function __construct(private string $directory)
    {
    }

    public function load(?string $sessionId): Session
    {
        $sessionId = $this->normalizeId($sessionId);

        if ($sessionId === null) {
            return new Session($this->generateId(), [], false, true);
        }

        $path = $this->pathFor($sessionId);

        if (!is_file($path)) {
            return new Session($sessionId, [], false, true);
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (!is_array($payload)) {
            return new Session($sessionId, [], false, true);
        }

        return new Session($sessionId, $payload, false, false);
    }

    public function save(Session $session): void
    {
        if (!is_dir($this->directory) && !mkdir($concurrentDirectory = $this->directory, 0777, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Session directory "%s" could not be created.', $this->directory));
        }

        $json = json_encode($session->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode session data.');
        }

        file_put_contents($this->pathFor($session->id()), $json . PHP_EOL);
    }

    private function pathFor(string $sessionId): string
    {
        return rtrim($this->directory, '/') . '/' . $sessionId . '.json';
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(20));
    }

    private function normalizeId(?string $sessionId): ?string
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        if (!preg_match('/^[a-f0-9]{16,64}$/', $sessionId)) {
            return null;
        }

        return $sessionId;
    }
}