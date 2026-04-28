<?php

namespace App\Models;

use App\Support\RoleNames;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    protected string $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'security_pin_hash',
        'security_pin_enabled',
        'security_pin_set_at',
        'college_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'security_pin_hash',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'security_pin_enabled' => 'boolean',
            'security_pin_set_at' => 'datetime',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(RoleNames::all());
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleNames::SUPER_ADMIN);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(RoleNames::ADMIN);
    }

    public function requiresSecurityPinChallenge(): bool
    {
        return $this->security_pin_enabled && filled($this->security_pin_hash);
    }
}
