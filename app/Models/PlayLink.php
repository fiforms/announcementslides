<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A revocable, unguessable URL that plays one entity's show full-screen with
 * no login — for browser-capable devices (hallway TVs, a member's computer)
 * that can be given a URL but not a session. The link carries its own
 * settings (show, language, slide delay); see PlayLinkController.
 */
class PlayLink extends Model
{
    public const TOKEN_LENGTH = 64;

    protected $fillable = [
        'entity_id', 'show_id', 'language_id', 'title', 'token',
        'delay_seconds', 'created_by', 'last_used_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $link) {
            $link->token ??= Str::random(self::TOKEN_LENGTH);
        });
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function url(): string
    {
        return route('play.show', ['token' => $this->token]);
    }

    /** The show actually played: the chosen one, else the entity's Main Show. */
    public function resolvedShow(): Show
    {
        return $this->show ?? $this->entity->mainShow();
    }
}
