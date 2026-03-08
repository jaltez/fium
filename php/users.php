<?php

declare(strict_types=1);

return [
    [
        'id' => 1,
        'email' => 'demo@example.com',
        'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'role' => 'admin',
    ],
    [
        'id' => 2,
        'email' => 'user@example.com',
        'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
        'role' => 'user',
    ],
];