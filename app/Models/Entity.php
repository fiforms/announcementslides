<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_entities')
            ->withPivot('role', 'granted_by')
            ->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function slides()
    {
        return $this->hasMany(Slide::class);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'like', '%' . $term . '%');
    }
}
