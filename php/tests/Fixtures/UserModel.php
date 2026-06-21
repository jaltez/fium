<?php

declare(strict_types=1);

namespace Fium\Tests\Fixtures;

use Fium\Database\Model;

final class UserModel extends Model
{
    protected string $table = 'users';

    protected array $fillable = ['name', 'email', 'age', 'active'];
}
