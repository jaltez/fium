<?php

declare(strict_types=1);

namespace Fium\Session;

interface SessionStore
{
    public function load(?string $sessionId): Session;

    public function save(Session $session): void;

    public function regenerate(Session $session): void;

    public function delete(string $sessionId): void;
}
