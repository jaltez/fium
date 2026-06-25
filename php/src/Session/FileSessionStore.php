<?php

declare(strict_types=1);

namespace Fium\Session;

final class FileSessionStore extends AbstractSessionStore
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

        $contents = file_get_contents($path);
        if ($contents === false) {
            return new Session($sessionId, [], false, true);
        }

        $payload = json_decode($contents, true);

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

        $json = json_encode($session->all(), JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode session data.');
        }

        if (file_put_contents($this->pathFor($session->id()), $json, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Session file "%s" could not be written.', $this->pathFor($session->id())));
        }
    }

    public function delete(string $sessionId): void
    {
        $path = $this->pathFor($sessionId);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function pathFor(string $sessionId): string
    {
        return rtrim($this->directory, '/') . '/' . $sessionId . '.json';
    }
}