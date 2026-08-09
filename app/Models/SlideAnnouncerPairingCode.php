<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlideAnnouncerPairingCode extends Model
{
    protected $fillable = [
        'code',
        'entity_id',
        'created_by',
        'expires_at',
        'used_at',
        'slide_announcer_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function entity()
    {
        return $this->belongsTo(Entity::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function slideAnnouncer()
    {
        return $this->belongsTo(SlideAnnouncer::class);
    }

    public function scopeUnused($query)
    {
        return $query->whereNull('used_at');
    }

    public function scopeUnexpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
