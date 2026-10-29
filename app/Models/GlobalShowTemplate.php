<?php

namespace App\Models;

use App\Models\Concerns\HasPublicToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identity for a globally-distributed one-off show (e.g. a special
 * sound-on promo video every site gets its own copy of). Each entity's copy
 * is a separate Show row (shows.global_template_id), so leaders can locally
 * hide/reorder/rename theirs independently; this row exists so all those
 * per-entity copies can be found and swept together once the underlying
 * content expires (see Console\Commands\PruneEmptyShows), and so the promo
 * itself can be viewed/linked (?promo=) and managed (Admin\PromoShowController)
 * without picking any one site's copy.
 */
class GlobalShowTemplate extends Model
{
    use HasPublicToken;

    protected $fillable = ['token', 'name', 'created_by'];

    public function shows(): HasMany
    {
        return $this->hasMany(Show::class, 'global_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The promo's own content: the global slides fanned into any of its
     * per-entity copies, in the shared fanout order every copy uses. Read
     * across the copies rather than from one, so a leader's local edits to
     * their copy (an added local slide, a removal) never change the promo.
     */
    public function slidesQuery(): Builder
    {
        return Slide::query()
            ->whereNull('slides.entity_id')
            ->whereHas('shows', fn ($q) => $q->where('global_template_id', $this->id))
            ->orderBy('fanout_sort_order');
    }

    /** Promos that still have something live to show. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereHas('shows.slides', fn ($q) => $q->whereNull('slides.entity_id')->current());
    }
}
