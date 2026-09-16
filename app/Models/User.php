<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

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

    /**
     * This user's entity memberships as entity_id => pivot role, resolved
     * once per instance.
     *
     * Every entity-scoped request asks about membership several times over --
     * AuthorizesEntityAccess checks it to resolve the entity and again to
     * authorise it, the controller asks whether the user is an admin of it,
     * and Slide::scopeVisibleToUser() asks for the whole list to build the
     * query. Each of those used to be its own round trip to user_entities.
     * One lookup answers all of them.
     *
     * Call forgetEntityRoles() after changing this user's memberships within
     * the same request.
     */
    protected ?Collection $entityRoles = null;

    protected function entityRoles(): Collection
    {
        return $this->entityRoles ??= $this->entities()
            ->pluck('user_entities.role', 'entities.id');
    }

    public function forgetEntityRoles(): void
    {
        $this->entityRoles = null;
    }

    public function entityRole(int $entityId): ?string
    {
        return $this->entityRoles()->get($entityId);
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
        return $this->entityRoles()->keys()->all();
    }

    public function settings()
    {
        return $this->hasMany(UserSetting::class);
    }

    /**
     * Read a single per-user setting value, falling back to $default when unset.
     */
    public function setting(string $tag, $default = null)
    {
        return $this->settings()->where('setting_tag', $tag)->value('setting_value') ?? $default;
    }

    /**
     * Create or update a single per-user setting.
     */
    public function putSetting(string $tag, $value): void
    {
        $this->settings()->updateOrCreate(
            ['setting_tag' => $tag],
            ['setting_value' => $value],
        );
    }
}
