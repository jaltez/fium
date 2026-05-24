<?php

declare(strict_types=1);

namespace Fium\Session;

abstract class AbstractSessionStore implements SessionStore
{
    public function regenerate(Session $session): void
    {
        if (!$session->needsRegeneration()) {
            return;
        }

        $session->rotateTo($this->generateId());
    }

    protected function normalizeId(?string $sessionId): ?string
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }

        $len = strlen($sessionId);
        if ($len < 16 || $len > 64 || !ctype_xdigit($sessionId)) {
            return null;
        }

        return $sessionId;
    }

    protected function generateId(): string
    {
        return bin2hex(random_bytes(20));
    }
}
