<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Workbench\Database\Factories\UserFactory;

/**
 * The workbench operator.
 *
 * Deliberately the plainest possible user: no roles, no permission package, no
 * tenancy. If a chat screen needs more than this to open, the package is asking
 * the host for something it has not documented.
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Named explicitly because Laravel's guess does not apply to a workbench.
     *
     * The default resolver turns `Workbench\App\Models\User` into
     * `Database\Factories\Models\UserFactory`, which does not exist here — and
     * the failure lands on whichever test happens to call the factory first,
     * nowhere near the model that caused it.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
