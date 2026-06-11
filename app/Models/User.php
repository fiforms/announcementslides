<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'google_id', 'avatar_url',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isContributor(): bool
    {
        return in_array($this->role, ['admin', 'contributor']);
    }

    public function isBanned(): bool
    {
        return $this->role === 'banned';
    }

    public function slides()
    {
        return $this->hasMany(Slide::class, 'uploaded_by');
    }

    public function entities()
    {
        return $this->belongsToMany(Entity::class, 'user_entities')
            ->withPivot('role', 'granted_by')
            ->withTimestamps();
    }

    public function entityRole(int $entityId): ?string
    {
        return $this->entities()->find($entityId)?->pivot->role;
    }

    public function adminEntities()
    {
        return $this->entities()->wherePivot('role', 'admin');
    }

    public function isEntityAdmin(int $entityId): bool
    {
        return $this->entityRole($entityId) === 'admin';
    }

    public function memberEntityIds(): array
    {
        return $this->entities()->pluck('entities.id')->toArray();
    }
}
