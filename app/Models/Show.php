<?php

namespace App\Models;

use App\Support\NearbyEntities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * An orderable, named playlist of slides. Every entity has exactly one
 * non-deletable "main" show (auto-created via mainFor()); there is also one
 * singleton, entity-less "Global Board" (auto-created via globalBoard())
 * that holds the master ordering for global slides. Membership/order for
 * every show — main, extra, or the Global Board — lives entirely in
 * show_slides; slides.sort_order no longer exists.
 *
 * A show has two independent auto-fill flags — `auto_fill_global` (global
 * slides) and `auto_fill_nearby` (nearby-shared slides). Only a show with the
 * relevant flag on participates in that kind of automatic distribution — see
 * syncAutoFillForSlide() and syncAutoFillFromCandidates(). A leader can
 * always manually attach any slide to any show regardless of status;
 * `status === 'published'` only gates *automatic* fan-out. Language plays no
 * part in membership: shows hold every language, and the language is a
 * display filter (web pages) or a per-device filter (Slide Announcers). The
 * legacy `language_id` column is unused.
 */
class Show extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'entity_id', 'global_template_id', 'name', 'is_main', 'language_id',
        'auto_fill_global', 'auto_fill_nearby', 'auto_delete_when_empty', 'frame_youtube', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'auto_fill_global' => 'boolean',
            'auto_fill_nearby' => 'boolean',
            'auto_delete_when_empty' => 'boolean',
            'frame_youtube' => 'array',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function globalTemplate(): BelongsTo
    {
        return $this->belongsTo(GlobalShowTemplate::class, 'global_template_id');
    }

    public function slides(): BelongsToMany
    {
        return $this->belongsToMany(Slide::class, 'show_slides')
            ->withPivot('sort_order', 'auto_added')
            ->withTimestamps()
            ->orderBy('show_slides.sort_order');
    }

    /**
     * The show's frame media (see the show_id migration): an optional
     * `show-base` image/video under every slide, and an optional
     * `show-overlay` (SVG + widget placements) over every slide, above the
     * slide's own overlay and widgets.
     */
    public function media(): HasMany
    {
        return $this->hasMany(SlideMedia::class);
    }

    public function baseMedia(): HasOne
    {
        return $this->hasOne(SlideMedia::class)->where('media_type', 'show-base')->latest('id');
    }

    public function overlayMedia(): HasOne
    {
        return $this->hasOne(SlideMedia::class)->where('media_type', 'show-overlay')->latest('id');
    }

    /**
     * With a looping background video (an uploaded one or a YouTube
     * video/playlist), slides that contain a video are left out of playback
     * entirely: two videos at once would be heavy and mix audio.
     */
    public function skipsVideoSlides(): bool
    {
        return !empty($this->frame_youtube) || (bool) $this->baseMedia?->isVideo();
    }

    public static function mainFor(Entity $entity): self
    {
        return static::firstOrCreate(
            ['entity_id' => $entity->id, 'is_main' => true],
            ['name' => 'Main Show', 'auto_fill_global' => true, 'auto_fill_nearby' => true]
        );
    }

    public static function globalBoard(): self
    {
        return static::firstOrCreate(
            ['entity_id' => null, 'is_main' => true],
            ['name' => 'Global Board']
        );
    }

    /**
     * Entities eligible to auto-receive $slide at all: every entity for a
     * global slide, or (for a locally-shared slide) every nearby entity.
     * Whether a given entity's *shows* actually take it is decided per-show
     * by auto_fill_global/auto_fill_nearby in reconcilePair(), not here.
     */
    public static function candidateEntityIdsForSlide(Slide $slide): Collection
    {
        if ($slide->entity_id === null) {
            return Entity::pluck('id');
        }

        if (!$slide->share_nearby) {
            return collect();
        }

        $origin = $slide->entity ?? Entity::find($slide->entity_id);
        if (!$origin) {
            return collect();
        }

        $radius = (float) config('slides.nearby_radius_miles');

        return collect(NearbyEntities::within($origin, $radius));
    }

    /**
     * Add or remove $slide from every eligible show belonging to an
     * eligible entity, based on the relevant auto_fill flag (global vs
     * nearby) and language match. Never touches a link a leader created
     * manually (auto_added = false on the pivot) — automatic reconciliation
     * only retracts what automation itself added.
     */
    public static function syncAutoFillForSlide(Slide $slide): void
    {
        $isGlobal = $slide->entity_id === null;
        $flag = $isGlobal ? 'auto_fill_global' : 'auto_fill_nearby';
        $entityIds = static::candidateEntityIdsForSlide($slide);

        static::where($flag, true)
            ->whereIn('entity_id', $entityIds)
            ->get()
            ->each(fn (self $show) => static::reconcilePair($show, $slide));
    }

    /**
     * Put an immutable global slide back into every entity's Main Show —
     * including ones where a leader removed it before it became immutable.
     * Other (extra) shows are left alone: the lock applies to Main only.
     */
    public static function restoreImmutableSlide(Slide $slide): void
    {
        if (!$slide->isLockedInShows()) {
            return;
        }

        static::where('is_main', true)->whereNotNull('entity_id')->get()->each(function (self $show) use ($slide) {
            if (!$show->slides()->where('slides.id', $slide->id)->exists()) {
                $show->slides()->attach($slide->id, [
                    'sort_order' => $slide->assignFanoutSortOrderIfNeeded(),
                    'auto_added' => true,
                ]);
            }
        });
    }

    /**
     * Remove every auto-added cross-entity link to $slide — used when a
     * slide stops being eligible for fan-out at all (e.g. "share nearby" is
     * turned off), since syncAutoFillForSlide's empty candidate set alone
     * wouldn't clean up links that were added while it was still eligible.
     */
    public static function removeAutoAddedLinksElsewhere(Slide $slide, ?int $exceptEntityId): void
    {
        static::query()
            ->when($exceptEntityId, fn ($q) => $q->where(fn ($w) => $w->whereNull('entity_id')->orWhere('entity_id', '!=', $exceptEntityId)))
            ->get()
            ->each(function (self $show) use ($slide) {
                $pivot = $show->slides()->where('slides.id', $slide->id)->first()?->pivot;
                if ($pivot && $pivot->auto_added) {
                    $show->slides()->detach($slide->id);
                }
            });
    }

    /**
     * The mirror of syncAutoFillForSlide: (re)fill this show from every
     * currently-eligible global and/or nearby slide, per whichever flags are
     * on. Used when a show's auto-fill settings change, so it doesn't start
     * (or stay) empty until the next unrelated slide publish.
     */
    public function syncAutoFillFromCandidates(): void
    {
        if (!$this->entity_id) {
            return;
        }

        $candidates = collect();

        if ($this->auto_fill_global) {
            $candidates = $candidates->concat(Slide::current()->whereNull('entity_id')->get());
        }

        if ($this->auto_fill_nearby) {
            $radius = (float) config('slides.nearby_radius_miles');
            $nearbyIds = NearbyEntities::within($this->entity, $radius);
            if (!empty($nearbyIds)) {
                $candidates = $candidates->concat(
                    Slide::current()->whereIn('entity_id', $nearbyIds)->shareNearby()->get()
                );
            }
        }

        $candidates->each(fn (Slide $slide) => static::reconcilePair($this, $slide));
    }

    /**
     * Eligibility is gated on `status` only — shows hold every language and
     * each device filters by its own language, so a show's `language_id` is
     * no longer consulted. A slide that isn't
     * published has no business auto-appearing anywhere yet/anymore — but
     * deliberately NOT on publish_at/expires_at: once a slide is fanned in,
     * it stays attached through its own publish window the same way it stays
     * attached past expiry (query-time filtering handles both; see
     * Slide::scopeCurrent() and the Show Editor's "expired in this show"
     * pane). That keeps a slide's sort position stable across its own
     * schedule, while still reacting immediately to an admin's
     * approve/reject or draft/publish toggle.
     */
    private static function reconcilePair(self $show, Slide $slide): void
    {
        $eligible = $slide->status === 'published';
        $pivot = $show->slides()->where('slides.id', $slide->id)->first()?->pivot;

        if ($eligible && !$pivot) {
            $sortOrder = $slide->assignFanoutSortOrderIfNeeded();
            $show->slides()->attach($slide->id, ['sort_order' => $sortOrder, 'auto_added' => true]);
        } elseif (!$eligible && $pivot && $pivot->auto_added) {
            $show->slides()->detach($slide->id);
        }
    }
}
