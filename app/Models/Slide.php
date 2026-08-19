<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use App\Models\Entity;
use App\Models\User;

class Slide extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'notes', 'text_description', 'link', 'video_playback_mode', 'publish_at', 'expires_at',
        'status', 'uploaded_by', 'reviewed_by', 'reviewed_at', 'entity_id', 'language_id',
        'share_nearby', 'fanout_sort_order',
    ];

    protected function casts(): array
    {
        return [
            'publish_at'  => 'datetime',
            'expires_at'  => 'datetime',
            'reviewed_at' => 'datetime',
            'share_nearby' => 'boolean',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    public function language()
    {
        return $this->belongsTo(\App\Models\Language::class);
    }

    public function media()
    {
        return $this->hasMany(SlideMedia::class);
    }

    public function shows()
    {
        return $this->belongsToMany(Show::class, 'show_slides')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * The slide's primary ('slide' type) media row. Every Slide has exactly
     * one, seeded at creation time; this is what the file_url/thumbnail_url/
     * mime_type/etc. proxy accessors below resolve against.
     */
    public function primaryMedia()
    {
        return $this->hasOne(SlideMedia::class)->where('media_type', 'slide')->oldest('sort_order');
    }

    /**
     * The slide's optional 'slide-overlay' media row — a same-aspect-ratio
     * graphic composited on top of primaryMedia when present.
     */
    public function overlayMedia()
    {
        return $this->hasOne(SlideMedia::class)->where('media_type', 'slide-overlay')->oldest('sort_order');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('publish_at')
            ->where('publish_at', '>', now());
    }

    public function scopeUnscoped(Builder $query): Builder
    {
        return $query->whereNull('entity_id');
    }

    public function scopeEntityScoped(Builder $query, int $entityId): Builder
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopeVisibleToUser(Builder $query, ?User $user): Builder
    {
        if ($user) {
            $entityIds = $user->memberEntityIds();
            return $query->where(fn ($q) => $q->whereNull('entity_id')->orWhereIn('entity_id', $entityIds));
        }
        return $query->whereNull('entity_id');
    }

    public function scopeShareNearby(Builder $query): Builder
    {
        return $query->where('share_nearby', true);
    }

    public function scopeLanguage(Builder $query, ?int $languageId): Builder
    {
        if ($languageId === null) {
            return $query;
        }
        return $query->where(fn ($q) => $q->whereNull('language_id')->orWhere('language_id', $languageId));
    }

    /**
     * Order slides by their position within a specific show. This is the
     * single ordering path for every slide listing in the app — the Global
     * Board, every entity's Main show, and any extra shows — replacing the
     * old slides.sort_order column plus the per-feature "entity_id IS NOT
     * NULL DESC" union that used to keep globals separate.
     */
    public function scopeOrderedInShow(Builder $query, int $showId): Builder
    {
        return $query->join('show_slides', 'show_slides.slide_id', '=', 'slides.id')
            ->where('show_slides.show_id', $showId)
            ->select('slides.*', 'show_slides.sort_order as show_sort_order')
            ->orderBy('show_sort_order');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /**
     * Back-compat proxies onto the primary ('slide') media row, so existing
     * views/controllers built around "one file per slide" keep working
     * unchanged now that files live on slide_media. Callers that list many
     * slides should eager-load 'primaryMedia' to avoid N+1s.
     */
    public function getFileUrlAttribute(): ?string
    {
        return $this->primaryMedia?->file_url;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->primaryMedia?->thumbnail_url;
    }

    public function getMimeTypeAttribute(): ?string
    {
        return $this->primaryMedia?->mime_type;
    }

    public function getOverlayUrlAttribute(): ?string
    {
        return $this->overlayMedia?->file_url;
    }

    public function getOverlayMimeTypeAttribute(): ?string
    {
        return $this->overlayMedia?->mime_type;
    }

    public function getOriginalFilenameAttribute(): ?string
    {
        return $this->primaryMedia?->original_filename;
    }

    public function getFileSizeAttribute(): ?int
    {
        return $this->primaryMedia?->file_size;
    }

    public function getValidationIssuesAttribute(): ?array
    {
        return $this->primaryMedia?->validation_issues;
    }

    public function getValidationStatusAttribute(): ?string
    {
        return $this->primaryMedia?->validation_status;
    }

    /**
     * The effective status for display, accounting for publish/expiry dates.
     * A published slide whose expiry has passed reads as "archived"; one whose
     * publish date is still in the future reads as "scheduled".
     */
    public function getDisplayStatusAttribute(): string
    {
        if ($this->status !== 'published') {
            return $this->status;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'archived';
        }

        if ($this->publish_at && $this->publish_at->isFuture()) {
            return 'scheduled';
        }

        return 'published';
    }

    public function isImage(): bool
    {
        return (bool) $this->primaryMedia?->isImage();
    }

    public function isVideo(): bool
    {
        return (bool) $this->primaryMedia?->isVideo();
    }

    /**
     * The slide's stable position within its automatic sort zone (global or
     * nearby) — assigned once, the first time it becomes eligible for that
     * kind of fan-out, by atomically decrementing the matching SortCounter.
     * Reused as-is for every show it's fanned into afterward, which is what
     * makes a slide's relative order identical and predictable across shows.
     * Never reassigned once set, even if the slide temporarily stops being
     * eligible (e.g. share_nearby toggled off and back on).
     */
    public function assignFanoutSortOrderIfNeeded(): int
    {
        if ($this->fanout_sort_order !== null) {
            return $this->fanout_sort_order;
        }

        $key = $this->entity_id === null ? 'global' : 'nearby';

        return DB::transaction(function () use ($key) {
            $counter = SortCounter::where('key', $key)->lockForUpdate()->first();
            $counter->decrement('value');
            $this->fanout_sort_order = $counter->value;
            $this->save();

            return $this->fanout_sort_order;
        });
    }
}
