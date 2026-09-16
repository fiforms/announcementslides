<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    protected $fillable = [
        'name',
        'org_mast_id',
        'entity_type',
        'description',
        'address',
        'city',
        'state',
        'zip',
        'country',
        'latitude',
        'longitude',
        'website',
        'is_custom',
        'deactivated',
        'created_by',
    ];

    protected $casts = [
        'is_custom'   => 'boolean',
        'deactivated' => 'boolean',
        'latitude'    => 'decimal:7',
        'longitude'   => 'decimal:7',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_entities')
            ->withPivot('role', 'granted_by')
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function slides(): HasMany
    {
        return $this->hasMany(Slide::class);
    }

    public function slideAnnouncers(): HasMany
    {
        return $this->hasMany(SlideAnnouncer::class);
    }

    public function shows(): HasMany
    {
        return $this->hasMany(Show::class);
    }

    public function mainShow(): Show
    {
        return Show::mainFor($this);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where('name', 'like', '%' . $term . '%');
    }
}
