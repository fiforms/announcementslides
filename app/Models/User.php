<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function slides(): HasMany
    {
        return $this->hasMany(Slide::class, 'uploaded_by');
    }

    public function entities(): BelongsToMany
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

    public function adminEntities(): BelongsToMany
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

    /**
     * The entities whose slide feed this user may see: ones they lead, plus
     * ones they follow that still allow subscriptions. (An entity that has
     * switched subscriptions off keeps its leaders and kiosk links only —
     * existing followers stay on record but lose access.)
     */
    /** Entities this user leads (entity-admin role): the only ones whose archive they may see. */
    public function leaderEntityIds(): array
    {
        return $this->adminEntities()->pluck('entities.id')->toArray();
    }

    public function viewableEntities(): BelongsToMany
    {
        return $this->entities()->where(
            fn ($q) => $q->where('user_entities.role', 'admin')->orWhere('entities.allow_subscription', true)
        );
    }

    public function viewableEntityIds(): array
    {
        return $this->viewableEntities()->pluck('entities.id')->toArray();
    }

    public function settings(): HasMany
    {
        return $this->hasMany(UserSetting::class);
    }

    /**
     * Read a single per-user setting value, falling back to $default when unset.
     */
    public function setting(string $tag, $default = null): mixed
    {
        return $this->settings()->where('setting_tag', $tag)->value('setting_value') ?? $default;
    }

    /**
     * Seconds each slide shows before a slideshow auto-advances.
     */
    public function slideDelaySeconds(): int
    {
        return (int) $this->setting('slide_delay_seconds', config('slides.slide_delay_seconds'));
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
